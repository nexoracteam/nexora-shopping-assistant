<?php
namespace ConvoCart\Assistant\AI;

use ConvoCart\Assistant\AI\Encryption\EncryptionService;
use ConvoCart\Assistant\Support\Settings;

defined( 'ABSPATH' ) || exit;

/** Credential-scoped inventories. No guessed/curated model is advertised as available. */
final class ProviderModelService {
	public const CACHE_VERSION = 2;
	public const CACHE_TTL = 21600;
	public const REFRESH_AFTER = 18000;
	private const ERROR_TTL = 60;

	public static function queue_refresh( string $provider ): void {
		if ( ! EncryptionService::is_supported_provider( $provider ) ) { return; }
		$args = array( $provider );
		if ( ! wp_next_scheduled( 'convocart_refresh_provider_models', $args ) ) {
			wp_schedule_single_event( time() + 5, 'convocart_refresh_provider_models', $args );
		}
	}

	public function refresh_if_due( string $provider ): void {
		$key = ( new EncryptionService() )->get( $provider );
		if ( '' === $key ) { return; }
		$result = $this->cached( $provider, $key );
		if ( ! $result || 'live' !== $result['source'] || time() - (int) $result['fetched_at'] >= self::REFRESH_AFTER ) {
			$this->list_models( $provider, true );
		}
	}

	public function list_models( string $provider, bool $refresh = false ): array {
		$provider = sanitize_key( $provider );
		if ( ! EncryptionService::is_supported_provider( $provider ) ) {
			return array( 'provider' => $provider, 'models' => array(), 'source' => 'error', 'code' => 'unknown_provider', 'message' => __( 'Unknown provider.', 'nexora-shopping-assistant' ) );
		}
		$key = ( new EncryptionService() )->get( $provider );
		if ( '' === $key ) {
			$result = $this->failure( 'not_configured', __( 'Save an API key to automatically discover available models.', 'nexora-shopping-assistant' ) );
		} else {
			$result = $this->cached( $provider, $key );
			$attempt = $this->read_cache( 'convocart_models_attempt_' . $provider, $key );
			// Prevent manual refresh storms, and allow hourly jobs to retry failures.
			if ( ( $refresh || ! $result ) && ! $attempt ) {
				$result = $this->refresh( $provider, $key );
			} elseif ( ! $result ) {
				$result = $this->failure( 'refresh_pending', __( 'Model refresh is pending. Please try again shortly.', 'nexora-shopping-assistant' ) );
			}
		}
		return $this->present( $provider, $key, $result );
	}

	/** Read-only runtime check: discovery never eats into the chat's HTTP budget. */
	public function availability( string $provider, string $model, bool $allow_retest = false ): array {
		$model = ModelCatalog::normalize( $provider, $model );
		$status = ModelCatalog::status( $provider, $model );
		$status['available'] = false;
		$status['availability'] = $status['status'];
		if ( ! ModelCatalog::usable( $provider, $model ) ) { return $status; }
		$key = ( new EncryptionService() )->get( $provider );
		if ( '' === $key ) {
			$status['availability'] = 'not_configured';
			$status['message'] = __( 'Save an API key to discover available models.', 'nexora-shopping-assistant' );
			return $status;
		}
		$result = $this->cached( $provider, $key );
		if ( ! $this->is_fresh( $result ) ) {
			$status['availability'] = 'not_discovered';
			$status['message'] = __( 'No fresh provider inventory is available. Automatic refresh is queued; check Providers for discovery errors.', 'nexora-shopping-assistant' );
			self::queue_refresh( $provider );
			return $status;
		}
		if ( ! in_array( $model, $result['ids'], true ) ) {
			$status['availability'] = 'not_listed';
			$status['message'] = __( 'The saved model is no longer listed by this provider. Choose an available replacement.', 'nexora-shopping-assistant' );
			return $status;
		}
		if ( ! $allow_retest && $this->rejected( $provider, $key, $model ) ) {
			$status['availability'] = 'provider_rejected';
			$status['message'] = __( 'The provider reported this model unavailable. Choose another model or retest the saved model after resolving access.', 'nexora-shopping-assistant' );
			return $status;
		}
		$status['available'] = true;
		$status['availability'] = 'available';
		$status['discovered_at'] = (int) $result['fetched_at'];
		return $status;
	}

	public function mark_unavailable( string $provider, string $model ): void {
		$key = ( new EncryptionService() )->get( $provider );
		if ( '' === $key ) { return; }
		$cache_key = 'convocart_model_rejections_' . $provider;
		$record = $this->read_cache( $cache_key, $key );
		$record = $record ?: array( 'ids' => array() );
		$record['ids'][ $model ] = time();
		$this->write_cache( $cache_key, $record, $key, self::CACHE_TTL );
		delete_transient( 'convocart_test_' . $provider );
		self::queue_refresh( $provider );
	}

	public function clear_rejection( string $provider, string $model ): void {
		$key = ( new EncryptionService() )->get( $provider );
		$cache_key = 'convocart_model_rejections_' . $provider;
		$record = $this->read_cache( $cache_key, $key );
		if ( $record ) {
			unset( $record['ids'][ $model ] );
			$this->write_cache( $cache_key, $record, $key, self::CACHE_TTL );
		}
	}

	private function present( string $provider, string $key, array $result ): array {
		$fresh = $this->is_fresh( $result );
		$selected = Settings::provider_model( $provider );
		$models = array();
		foreach ( array_values( array_unique( (array) ( $result['ids'] ?? array() ) ) ) as $id ) {
			// Re-evaluate dates on every cache read. Yesterday's usable snapshot
			// cannot resurrect a model whose shutdown boundary has just passed.
			if ( ! is_string( $id ) || ! ModelCatalog::usable( $provider, $id ) ) { continue; }
			$status = ModelCatalog::status( $provider, $id );
			$disabled = ! $fresh || $this->rejected( $provider, $key, $id );
			if ( $disabled ) { continue; }
			$badge = 'scheduled' === $status['status'] ? sprintf( /* translators: %s: shutdown date (YYYY-MM-DD). */ __( 'Shuts down %s', 'nexora-shopping-assistant' ), $status['shutdown_date'] ) : ( 'preview' === $status['status'] ? __( 'Preview', 'nexora-shopping-assistant' ) : __( 'Available', 'nexora-shopping-assistant' ) );
			$models[] = array( 'id' => $id, 'label' => $id, 'badge' => $badge, 'status' => $status['status'], 'lifecycle' => $status['lifecycle'], 'shutdown_date' => $status['shutdown_date'], 'replacement' => $status['replacement'], 'disabled' => false );
		}
		$preferred = array_flip( ModelCatalog::curated( $provider ) );
		usort( $models, static function ( array $a, array $b ) use ( $preferred ): int {
			return ( ( $preferred[ $a['id'] ] ?? 100 ) <=> ( $preferred[ $b['id'] ] ?? 100 ) ) ?: strcmp( $a['id'], $b['id'] );
		} );
		$selection = $this->availability( $provider, $selected );
		return array(
			'provider' => $provider, 'models' => $models,
			'source' => $result['source'], 'code' => $result['code'] ?? '', 'message' => $result['message'],
			'complete' => ! empty( $result['complete'] ), 'fetched_at' => (int) ( $result['fetched_at'] ?? 0 ),
			'last_attempt_at' => (int) ( $result['attempted_at'] ?? 0 ), 'refresh_interval' => self::CACHE_TTL,
			'next_check' => wp_next_scheduled( 'convocart_check_provider_models', array( $provider ) ) ?: null,
			'selected' => $selected, 'selected_missing' => ! $selection['available'], 'selected_status' => $selection,
			'lifecycle_reviewed_at' => ModelLifecycle::REVIEWED_AT,
		);
	}

	private function refresh( string $provider, string $key ): array {
		$lock_key = 'convocart_models_lock_' . $provider;
		$token = wp_generate_uuid4();
		$value = ( time() + 45 ) . '|' . $token;
		$locked = add_option( $lock_key, $value, '', false );
		if ( ! $locked ) {
			$old = (string) get_option( $lock_key, '' );
			if ( (int) $old < time() ) {
				global $wpdb;
				$locked = 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s", $value, $lock_key, $old ) );
				wp_cache_delete( $lock_key, 'options' );
			}
		}
		if ( ! $locked ) { return $this->cached( $provider, $key ) ?: $this->failure( 'refresh_pending', __( 'Another model refresh is running. Please try again shortly.', 'nexora-shopping-assistant' ) ); }
		try {
			$this->write_cache( 'convocart_models_attempt_' . $provider, array( 'at' => time() ), $key, self::ERROR_TTL );
			$result = $this->fetch( $provider, $key );
			// Ignore responses from a key that was changed while the HTTP call ran.
			if ( ! hash_equals( $key, ( new EncryptionService() )->get( $provider ) ) ) {
				return $this->failure( 'credentials_changed', __( 'Credentials changed during discovery. A new automatic refresh is queued.', 'nexora-shopping-assistant' ) );
			}
			$cache_key = 'convocart_models_' . $provider;
			$this->write_cache( $cache_key, $result, $key, 'live' === $result['source'] ? self::CACHE_TTL : self::ERROR_TTL );
			if ( 'live' === $result['source'] ) {
				$this->write_cache( 'convocart_models_good_' . $provider, $result, $key, DAY_IN_SECONDS );
				if ( ! in_array( Settings::provider_model( $provider ), $result['ids'], true ) ) { delete_transient( 'convocart_test_' . $provider ); }
			}
			return $result;
		} finally {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $lock_key, $value ) );
			wp_cache_delete( $lock_key, 'options' );
		}
	}

	private function fetch( string $provider, string $key ): array {
		$urls = array( 'groq' => 'https://api.groq.com/openai/v1/models', 'openai' => 'https://api.openai.com/v1/models', 'gemini' => 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000' );
		$headers = 'gemini' === $provider ? array( 'x-goog-api-key' => $key ) : array( 'Authorization' => 'Bearer ' . $key );
		$ids = array(); $next = ''; $seen_pages = array();
		$deadline = microtime( true ) + 20;
		for ( $page = 0; $page < 5; ++$page ) {
			$remaining = (int) floor( $deadline - microtime( true ) );
			if ( $remaining < 1 ) { return $this->failure( 'incomplete_inventory', __( 'Model discovery timed out before all pages loaded. Automatic refresh will retry.', 'nexora-shopping-assistant' ) ); }
			$url = $urls[ $provider ] . ( '' !== $next ? '&pageToken=' . rawurlencode( $next ) : '' );
			$response = wp_safe_remote_get( $url, array( 'timeout' => min( 8, $remaining ), 'limit_response_size' => 1024 * 1024, 'headers' => $headers ) );
			$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( $status < 200 || $status >= 300 ) {
				$code = in_array( $status, array( 401, 403 ), true ) ? 'auth_error' : ( 429 === $status ? 'rate_limited' : ( 0 === $status ? 'network_error' : 'provider_error' ) );
				return $this->failure( $code, sprintf( /* translators: %s: machine-readable error code, for example auth_error. */ __( 'Model discovery failed (%s). Check credentials, permissions, quota and network access. Unverified choices are not enabled.', 'nexora-shopping-assistant' ), $code ) );
			}
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$models = is_array( $data ) ? ( $data[ 'gemini' === $provider ? 'models' : 'data' ] ?? null ) : null;
			if ( ! is_array( $models ) || ! array_is_list( $models ) ) { return $this->failure( 'invalid_inventory', __( 'The provider returned an invalid model list. Automatic refresh will retry.', 'nexora-shopping-assistant' ) ); }
			foreach ( $models as $model ) {
				if ( ! is_array( $model ) ) { return $this->failure( 'invalid_inventory', __( 'The model list contains invalid entries.', 'nexora-shopping-assistant' ) ); }
				$raw_id = $model['id'] ?? $model['name'] ?? null;
				if ( ! is_string( $raw_id ) ) { return $this->failure( 'invalid_inventory', __( 'The model list contains invalid identifiers.', 'nexora-shopping-assistant' ) ); }
				$id = ModelCatalog::normalize( $provider, $raw_id );
				if ( isset( $model['active'] ) && ! $model['active'] ) { continue; }
				if ( ModelCatalog::usable( $provider, $id ) && ModelCatalog::compatible( $provider, $id, $model ) ) { $ids[] = $id; }
			}
			$next = 'gemini' === $provider ? ( $data['nextPageToken'] ?? '' ) : '';
			if ( ! is_string( $next ) || strlen( $next ) > 2048 || ( '' !== $next && isset( $seen_pages[ $next ] ) ) ) { return $this->failure( 'incomplete_inventory', __( 'Invalid model pagination. Automatic refresh will retry.', 'nexora-shopping-assistant' ) ); }
			if ( '' === $next ) {
				$ids = array_values( array_unique( $ids ) ); sort( $ids );
				return array( 'source' => 'live', 'complete' => true, 'ids' => $ids, 'fetched_at' => time(), 'attempted_at' => time(), 'message' => empty( $ids ) ? __( 'No compatible, non-retired chat models are listed for this API key.', 'nexora-shopping-assistant' ) : __( 'Available models synchronized automatically. Save a model, then test its shopping response.', 'nexora-shopping-assistant' ) );
			}
			$seen_pages[ $next ] = true;
		}
		return $this->failure( 'incomplete_inventory', __( 'The provider model list exceeded the pagination limit. Incomplete inventories are not enabled.', 'nexora-shopping-assistant' ) );
	}

	private function failure( string $code, string $message ): array {
		return array( 'source' => 'error', 'code' => $code, 'message' => $message, 'complete' => false, 'ids' => array(), 'fetched_at' => 0, 'attempted_at' => time() );
	}

	private function cached( string $provider, string $key ): ?array {
		return $this->read_cache( 'convocart_models_' . $provider, $key );
	}

	private function is_fresh( ?array $result ): bool {
		return $result && 'live' === ( $result['source'] ?? '' ) && ! empty( $result['complete'] ) && is_array( $result['ids'] ?? null ) && (int) ( $result['fetched_at'] ?? 0 ) > time() - self::CACHE_TTL;
	}

	private function rejected( string $provider, string $key, string $model ): bool {
		$record = $this->read_cache( 'convocart_model_rejections_' . $provider, $key );
		return (int) ( $record['ids'][ $model ] ?? 0 ) > time() - self::CACHE_TTL;
	}

	private function read_cache( string $name, string $key ): ?array {
		$value = get_transient( $name );
		return '' !== $key && is_array( $value ) && self::CACHE_VERSION === ( $value['schema'] ?? 0 ) && hash_equals( $this->fingerprint( $key ), (string) ( $value['credential'] ?? '' ) ) ? $value : null;
	}

	private function write_cache( string $name, array $value, string $key, int $ttl ): void {
		$value['schema'] = self::CACHE_VERSION;
		$value['credential'] = $this->fingerprint( $key );
		set_transient( $name, $value, $ttl );
	}

	private function fingerprint( string $key ): string {
		return hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
	}
}
