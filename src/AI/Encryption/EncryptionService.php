<?php
namespace ConvoCart\Assistant\AI\Encryption;

defined( 'ABSPATH' ) || exit;

final class EncryptionService {
	private string $last_error = '';

	public static function is_supported_provider( string $provider ): bool {
		return in_array( sanitize_key( $provider ), array( 'groq', 'gemini', 'openai' ), true );
	}

	public function get( string $provider ): string {
		$provider = sanitize_key( $provider );
		if ( ! self::is_supported_provider( $provider ) ) {
			return '';
		}
		try {
			if ( $this->key_changed() ) {
				return '';
			}
			$stored = get_option( 'convocart_provider_secrets', array() );
			$value  = is_array( $stored ) && isset( $stored[ $provider ] ) && is_string( $stored[ $provider ] ) ? $stored[ $provider ] : '';
			return '' === $value ? '' : $this->decrypt( $value );
		} catch ( \Throwable $error ) {
			return '';
		}
	}

	public function put( string $provider, string $secret ): bool {
		$this->last_error = '';
		if ( defined( 'CONVOCART_ENCRYPTION_KEY' ) && strlen( $this->configured_key() ) < 32 ) {
			$this->last_error = 'encryption_failed';
			return false;
		}
		$provider         = sanitize_key( $provider );
		if ( ! self::is_supported_provider( $provider ) ) {
			$this->last_error = 'invalid_provider';
			return false;
		}
		// Keys are sent in HTTP headers: allow printable, non-space ASCII only (0x21 '!' to 0x7E '~').
		if ( '' === $secret || strlen( $secret ) > 512 || ! preg_match( '/^[!-~]+$/', $secret ) ) {
			$this->last_error = 'invalid_secret';
			return false;
		}
		try {
			$encrypted   = $this->encrypt( $secret );
			$fingerprint = $this->fingerprint();
		} catch ( \Throwable $error ) {
			$encrypted   = '';
			$fingerprint = '';
		}
		if ( '' === $encrypted ) {
			$this->last_error = 'encryption_failed';
			return false;
		}
		try {
			$rotated             = $this->key_changed();
			$stored              = $rotated ? array() : get_option( 'convocart_provider_secrets', array() );
			if ( $rotated ) { foreach ( array( 'groq', 'gemini', 'openai' ) as $id ) { self::invalidate_provider_cache( $id ); } }
			$stored              = is_array( $stored ) ? $stored : array();
			$stored[ $provider ] = $encrypted;
			update_option( 'convocart_provider_secrets', $stored, false );
			update_option( 'convocart_key_fingerprint', $fingerprint, false );

			$persisted             = get_option( 'convocart_provider_secrets', array() );
			$persisted_key         = is_array( $persisted ) && isset( $persisted[ $provider ] ) && is_string( $persisted[ $provider ] ) ? $persisted[ $provider ] : '';
			$stored_fingerprint    = get_option( 'convocart_key_fingerprint', '' );
			$persisted_fingerprint = is_string( $stored_fingerprint ) ? $stored_fingerprint : '';
			$verified_secret       = $this->get( $provider );
		} catch ( \Throwable $error ) {
			$this->last_error = 'storage_failed';
			return false;
		}
		if ( $encrypted !== $persisted_key || ! hash_equals( $fingerprint, $persisted_fingerprint ) || $secret !== $verified_secret ) {
			$this->last_error = 'storage_failed';
			return false;
		}
		self::invalidate_provider_cache( $provider );
		\ConvoCart\Assistant\AI\ProviderModelService::queue_refresh( $provider );
		return true;
	}

	public function last_error(): string {
		return $this->last_error;
	}

	public function remove( string $provider ): void {
		$provider = sanitize_key( $provider );
		if ( ! self::is_supported_provider( $provider ) ) {
			return;
		}
		$stored = get_option( 'convocart_provider_secrets', array() );
		if ( ! is_array( $stored ) ) {
			return;
		}
		unset( $stored[ $provider ] );
		update_option( 'convocart_provider_secrets', $stored, false );
		self::invalidate_provider_cache( $provider );
		wp_clear_scheduled_hook( 'convocart_check_provider_models', array( $provider ) );
		wp_clear_scheduled_hook( 'convocart_refresh_provider_models', array( $provider ) );
	}

	public static function invalidate_provider_cache( string $provider ): void {
		foreach ( array( 'convocart_models_', 'convocart_models_good_', 'convocart_models_attempt_', 'convocart_model_rejections_', 'convocart_test_' ) as $prefix ) {
			delete_transient( $prefix . sanitize_key( $provider ) );
		}
	}

	public function fingerprint(): string {
		return substr( hash( 'sha256', $this->key() ), 0, 16 );
	}

	public function using_fallback_key(): bool {
		return '' === $this->configured_key();
	}

	public function key_changed(): bool {
		$value  = get_option( 'convocart_key_fingerprint', '' );
		$stored = is_string( $value ) ? $value : '';
		return '' !== $stored && ( 16 !== strlen( $stored ) || ! hash_equals( $stored, $this->fingerprint() ) );
	}

	private function encrypt( string $secret ): string {
		$key = $this->key();
		if ( function_exists( 'sodium_crypto_secretbox' ) && defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' ) ) {
			try {
				$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$cipher = sodium_crypto_secretbox( $secret, $nonce, $key );
				if ( is_string( $cipher ) && '' !== $cipher ) {
					return 'sodium:' . base64_encode( $nonce . $cipher );
				}
			} catch ( \Throwable $error ) {
				// Fall through to OpenSSL when Sodium is unavailable or fails.
			}
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}
		try {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false === $cipher || '' === $cipher || 16 !== strlen( $tag ) ) {
				return '';
			}
			return 'openssl:' . base64_encode( $iv . $tag . $cipher );
		} catch ( \Throwable $error ) {
			return '';
		}
	}

	private function decrypt( string $value ): string {
		try {
			$parts = explode( ':', $value, 2 );
			if ( 2 !== count( $parts ) ) {
				return '';
			}
			$raw = base64_decode( $parts[1], true );
			if ( false === $raw ) {
				return '';
			}
			$key = $this->key();
			if ( 'sodium' === $parts[0] && function_exists( 'sodium_crypto_secretbox_open' ) && defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' ) ) {
				$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$plain = sodium_crypto_secretbox_open( $box, $nonce, $key );
				return false === $plain ? '' : $plain;
			}
			if ( 'openssl' === $parts[0] && function_exists( 'openssl_decrypt' ) ) {
				$iv     = substr( $raw, 0, 12 );
				$tag    = substr( $raw, 12, 16 );
				$cipher = substr( $raw, 28 );
				$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
				return false === $plain ? '' : $plain;
			}
		} catch ( \Throwable $error ) {
			return '';
		}
		return '';
	}

	private function key(): string {
		$source = $this->using_fallback_key() ? wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) : $this->configured_key();
		return hash( 'sha256', $source, true );
	}

	private function configured_key(): string {
		if ( ! defined( 'CONVOCART_ENCRYPTION_KEY' ) ) {
			return '';
		}
		$value = constant( 'CONVOCART_ENCRYPTION_KEY' );
		$value = is_string( $value ) ? trim( $value ) : '';
		return $value;
	}
}

