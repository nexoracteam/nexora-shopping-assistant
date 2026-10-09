<?php
namespace ConvoCart\Assistant\AI\Providers;

use ConvoCart\Assistant\AI\Contracts\ProviderInterface;
use ConvoCart\Assistant\AI\DTO\AIRequest;
use ConvoCart\Assistant\AI\DTO\AIResponse;
use ConvoCart\Assistant\AI\DTO\ProviderError;
use ConvoCart\Assistant\AI\Encryption\EncryptionService;
use ConvoCart\Assistant\Support\Settings;

defined( 'ABSPATH' ) || exit;

abstract class AbstractProvider implements ProviderInterface {
	public function is_configured(): bool {
		return '' !== $this->get_api_key();
	}

	public function supports_tools(): bool {
		return true;
	}

	public function supports_streaming(): bool {
		return false;
	}

	public function test_connection(): array {
		if ( ! $this->is_configured() ) {
			return array(
				'configured' => false,
				'connected'  => false,
				'message'    => __( 'API key is not configured.', 'nexora-shopping-assistant' ),
			);
		}
		try {
			$models = new \ConvoCart\Assistant\AI\ProviderModelService();
			// Refresh discovery before a manual test; it is separate from chat latency.
			$models->list_models( $this->get_id() );
			$response = $this->complete(
				new AIRequest(
					array(
						array(
							'role'    => 'user',
							'content' => 'Return only JSON with message "Connection verified", recommendations [], and suggestions ["Browse products"]. No other fields.',
						),
					),
					array(),
					0.5,
					2048,
					15,
					array( 'shopping' => true, 'candidate_ids' => array(), 'recommendation_limit' => 3, 'model_retest' => true )
				)
			);
			\ConvoCart\Assistant\AI\ShoppingResponse::validate( $response->content, array() );
			$models->clear_rejection( $this->get_id(), $this->model() );
			$result = array(
				'configured' => true,
				'connected'  => '' !== $response->content,
				'message'    => __( 'Saved model returned a valid shopping response.', 'nexora-shopping-assistant' ),
				'model'      => $this->model(),
				'tested_at'  => time(),
			);
			set_transient( 'convocart_test_' . $this->get_id(), $result, HOUR_IN_SECONDS );
			return $result;
		} catch ( ProviderError $error ) {
			$message = sprintf( /* translators: %s: machine-readable error code, for example quota_exhausted. */ __( 'Shopping-response test failed (%s). Check model compatibility, API key permissions, billing/quota, and network access.', 'nexora-shopping-assistant' ), $error->code_name );
			$result = array(
				'configured' => true,
				'connected'  => false,
				'message'    => $message,
				'code'       => sanitize_key( $error->code_name ),
				'model'      => $this->model(),
				'tested_at'  => time(),
			);
			set_transient( 'convocart_test_' . $this->get_id(), $result, HOUR_IN_SECONDS );
			return $result;
		}
	}

	protected function request( string $url, array $headers, array $body, int $timeout ): array {
		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'             => max( 1, min( 60, $timeout ) ),
				'limit_response_size' => 1024 * 1024,
				'headers'             => $headers,
				'body'                => wp_json_encode( $body ),
				'data_format'         => 'body',
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new ProviderError( 'Remote provider request failed.', 'network_error', true );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			$retry   = (string) wp_remote_retrieve_header( $response, 'retry-after' );
			$seconds = ctype_digit( $retry ) ? (int) $retry : max( 0, (int) strtotime( $retry ) - time() );
			$error   = $this->provider_error_from_status( $status, $raw, min( 300, $seconds ) );
			if ( 'model_unavailable' === $error->code_name ) {
				( new \ConvoCart\Assistant\AI\ProviderModelService() )->mark_unavailable( $this->get_id(), $this->model() );
			}
			throw $error;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			throw new ProviderError( 'Provider returned malformed data.', 'malformed_provider_response', false );
		}
		return $data;
	}

	protected function get_api_key(): string {
		return ( new EncryptionService() )->get( $this->get_id() );
	}

	protected function model(): string {
		return Settings::provider_model( $this->get_id() );
	}

	protected function assert_model_available( AIRequest $request ): void {
		$status = ( new \ConvoCart\Assistant\AI\ProviderModelService() )->availability( $this->get_id(), $this->model(), ! empty( $request->context['model_retest'] ) );
		if ( ! $status['available'] ) {
			// Internal exception: callers map it to a translated, escaped message by error code.
			throw new ProviderError( $status['message'], 'model_unavailable', false ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output; escaped at every display boundary.
		}
	}

	private function provider_error_from_status( int $status, string $raw, int $retry_after = 0 ): ProviderError {
		$data = json_decode( $raw, true );
		$code = is_array( $data ) ? (string) ( $data['error']['code'] ?? $data['error']['status'] ?? '' ) : '';
		if ( 429 === $status && in_array( $code, array( 'insufficient_quota', 'billing_hard_limit_reached' ), true ) ) {
			return new ProviderError( 'Provider account quota exhausted.', 'quota_exhausted', false );
		}
		$retryable = $status >= 500 || 429 === $status;
		if ( 401 === $status || 403 === $status ) {
			return new ProviderError( 'Provider authorization failed.', 'auth_error', false );
		}
		if ( 404 === $status || 400 === $status ) {
			$code = 404 === $status || in_array( $code, array( 'model_not_found', 'model_decommissioned', 'NOT_FOUND' ), true ) ? 'model_unavailable' : 'provider_rejected';
			return new ProviderError( 'Provider rejected the request.', $code, false );
		}
		return new ProviderError( 'Remote provider returned an error.', $retryable ? 'temporary_provider_error' : 'provider_rejected', $retryable, $retry_after );
	}
}

