<?php
namespace ConvoCart\Assistant\Logging;

defined( 'ABSPATH' ) || exit;

final class RedactedLogger {
	public function info( string $message, array $context = array() ): void {
		$this->write( 'info', $message, $context );
	}

	public function warning( string $message, array $context = array() ): void {
		$this->write( 'warning', $message, $context );
	}

	public function error( string $message, array $context = array() ): void {
		$this->write( 'error', $message, $context );
	}

	private function write( string $level, string $message, array $context ): void {
		$context = $this->redact( $context );
		do_action( 'convocart_log', $level, sanitize_text_field( $message ), $context );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			$line = '[Nexora Shopping Assistant][' . $level . '] ' . wp_json_encode(
				array(
					'message' => $message,
					'context' => $context,
				)
			);
			error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only; context is redacted above.
		}
	}

	private function redact( array $context ): array {
		$blocked = array( 'api_key', 'authorization', 'password', 'token', 'secret', 'raw_ip', 'email', 'phone', 'content' );
		foreach ( $context as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), $blocked, true ) ) {
				$context[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$context[ $key ] = $this->redact( $value );
			} elseif ( is_scalar( $value ) ) {
				$context[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		return $context;
	}
}
