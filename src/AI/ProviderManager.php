<?php
namespace ConvoCart\Assistant\AI;

use ConvoCart\Assistant\AI\Contracts\ProviderInterface;
use ConvoCart\Assistant\AI\DTO\AIRequest;
use ConvoCart\Assistant\AI\DTO\AIResponse;
use ConvoCart\Assistant\AI\DTO\ProviderError;
use ConvoCart\Assistant\AI\Providers\GeminiProvider;
use ConvoCart\Assistant\AI\Providers\GroqProvider;
use ConvoCart\Assistant\AI\Providers\OpenAIProvider;
use ConvoCart\Assistant\AI\Tools\ToolRegistry;
use ConvoCart\Assistant\Logging\RedactedLogger;
use ConvoCart\Assistant\Support\Settings;
use ConvoCart\Assistant\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class ProviderManager {
	private const MAX_TOOL_ROUNDS       = 3;
	private const MAX_TOOL_RESULT_BYTES = 20000;

	private array $providers = array();
	private ToolRegistry $tools;

	public function __construct( ToolRegistry $tools ) {
		$this->tools     = $tools;
		$this->providers = array(
			'groq'   => new GroqProvider(),
			'gemini' => new GeminiProvider(),
			'openai' => new OpenAIProvider(),
		);
	}

	public function providers(): array {
		return $this->providers;
	}

	public function complete( AIRequest $request, ?int $conversation_id = null ): AIResponse {
		$order    = $this->provider_order();
		$order = array_values( array_filter( $order, fn( string $id ): bool => $this->providers[ $id ]->is_configured() && Settings::provider_model_is_available( $id ) ) );
		$deadline = microtime( true ) + max( 5, min( 60, absint( $request->timeout ) ) );
		$attempts = 1 + min( 2, max( 0, absint( Settings::get( 'provider_retries', 2 ) ) ) );
		$logger   = new RedactedLogger();
		$errors   = array();
		foreach ( $order as $position => $provider_id ) {
			$provider_deadline = microtime( true ) + max( 1, ( $deadline - microtime( true ) ) / ( count( $order ) - $position ) );
			$provider = $this->providers[ sanitize_key( $provider_id ) ] ?? null;
			if ( ! $provider || ! $provider->is_configured() || ! Settings::provider_model_is_available( sanitize_key( $provider_id ) ) ) {
				$errors[] = $provider_id . ':not_configured';
				continue;
			}
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
				$response = null;
				$started   = microtime( true );
				$remaining = max( 0, (int) floor( min( $deadline, $provider_deadline ) - $started ) );
				if ( $remaining <= 0 ) {
					break;
				}
				try {
					$providers_left = count( $order ) - $position;
					$budget = $remaining;
					$provider_request = new AIRequest( $request->messages, $request->tools, $request->temperature, $request->max_tokens, min( $request->timeout, $budget ), $request->context );
					$response         = $provider->complete( $provider_request );
					if ( $provider->supports_tools() && ! empty( $provider_request->tools ) && ! empty( $response->tool_calls ) ) {
						$response = $this->run_tools( $provider, $provider_request, $response, $conversation_id, min( $deadline, $provider_deadline ) );
					}
					// Guard against a tool-capable provider returning raw tool-call JSON as its final answer.
					// Only applies when tools were actually offered; JSON-only bodies are a legitimate
					// final answer for prompts that explicitly require raw JSON output.
					if ( empty( $request->context['shopping'] ) && ! empty( $provider_request->tools ) && $this->json_only_content( $response->content ) ) {
						throw new ProviderError( 'Provider returned tool data instead of a final answer.', 'malformed_provider_response', false );
					}
					if ( ! empty( $request->context['shopping'] ) ) {
						ShoppingResponse::validate( $response->content, (array) ( $request->context['candidate_ids'] ?? array() ), (int) ( $request->context['recommendation_limit'] ?? 3 ) );
					}
					$this->record_usage( $response, (int) round( ( microtime( true ) - $started ) * 1000 ), 'success', $conversation_id );
					return $response;
				} catch ( ProviderError $error ) {
					if ( $response instanceof AIResponse && empty( $response->tool_calls ) ) {
						$this->record_usage( $response, (int) round( ( microtime( true ) - $started ) * 1000 ), 'invalid_output', $conversation_id );
					}
					$errors[] = $provider_id . ':' . sanitize_key( $error->code_name );
					$this->record_failure( $provider_id, (int) round( ( microtime( true ) - $started ) * 1000 ), $error->code_name, $conversation_id );
					$logger->warning(
						'AI provider failed; evaluating fallback.',
						array(
							'provider'   => sanitize_key( $provider_id ),
							'error_code' => sanitize_key( $error->code_name ),
							'attempt'    => $attempt,
						)
					);
					if ( ! $this->retryable_error( $error ) || $attempt >= $attempts ) {
						break;
					}
					$remaining = max( 0, min( $deadline, $provider_deadline ) - microtime( true ) );
					$backoff   = max( $error->retry_after, min( 4, pow( 2, $attempt - 1 ) ) + ( random_int( 0, 500 ) / 1000 ) );
					if ( $remaining <= $backoff + 1 ) { break; }
					if ( $remaining > $backoff ) {
						usleep( (int) round( $backoff * 1000000 ) );
					}
				}
			}
		}
		throw new ProviderError( 'All configured providers failed.', 'provider_unavailable', false );
	}

	private function provider_order(): array {
		$allowed = array( 'groq', 'gemini', 'openai' );
		$order   = array();
		foreach ( array_merge(
			array( Settings::get( 'primary_provider', 'groq' ) ),
			(array) Settings::get( 'fallback_providers', array() )
		) as $provider_id ) {
			$provider_id = sanitize_key( (string) $provider_id );
			if ( in_array( $provider_id, $allowed, true ) && ! in_array( $provider_id, $order, true ) ) {
				$order[] = $provider_id;
			}
		}
		return $order;
	}

	private function record_usage( \ConvoCart\Assistant\AI\DTO\AIResponse $response, int $latency_ms, string $status, ?int $conversation_id = null ): void {
		global $wpdb;
		$usage      = is_array( $response->usage ) ? $response->usage : array();
		$prompt     = absint( $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0 );
		$completion = absint( $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0 );
		$total      = absint( $usage['total_tokens'] ?? ( $prompt + $completion ) );
		$wpdb->insert(
			Schema::table( 'provider_usage' ),
			array(
				'conversation_id'   => $conversation_id,
				'provider'          => $response->provider,
				'model'             => $response->model,
				'request_type'      => 'chat',
				'prompt_tokens'     => $prompt,
				'completion_tokens' => $completion,
				'total_tokens'      => $total,
				'latency_ms'        => max( 0, $latency_ms ),
				'status'            => $status,
				'created_at'        => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
		);
	}

	private function record_failure( string $provider_id, int $latency_ms, string $error_code, ?int $conversation_id = null ): void {
		global $wpdb;
		$models = Settings::get( 'provider_models', array() );
		$model  = is_array( $models ) && isset( $models[ $provider_id ] ) ? sanitize_text_field( (string) $models[ $provider_id ] ) : '';
		$wpdb->insert(
			Schema::table( 'provider_usage' ),
			array(
				'conversation_id'   => $conversation_id,
				'provider'          => sanitize_key( $provider_id ),
				'model'             => $model,
				'request_type'      => 'chat',
				'prompt_tokens'     => 0,
				'completion_tokens' => 0,
				'total_tokens'      => 0,
				'estimated_cost'    => null,
				'latency_ms'        => max( 0, $latency_ms ),
				'status'            => 'failed',
				'error_code'        => sanitize_key( $error_code ),
				'created_at'        => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%f', '%d', '%s', '%s', '%s' )
		);
	}

	private function retryable_error( ProviderError $error ): bool {
		return $error->retryable && in_array( $error->code_name, array( 'network_error', 'temporary_provider_error', 'invalid_shopping_response', 'malformed_provider_response' ), true );
	}

	private function run_tools( ProviderInterface $provider, AIRequest $request, AIResponse $response, ?int $conversation_id, float $deadline ): AIResponse {
		$messages  = $request->messages;
		$calls     = 0;
		$rounds    = 0;
		$max_calls = max( 1, min( 10, (int) Settings::get( 'max_tool_calls', 5 ) ) );

		while ( ! empty( $response->tool_calls ) ) {
			$this->record_usage( $response, 0, 'tool_round', $conversation_id );
			if ( microtime( true ) >= $deadline ) {
				throw new ProviderError( 'Tool execution deadline exceeded.', 'temporary_provider_error', true );
			}
			if ( $rounds >= self::MAX_TOOL_ROUNDS ) {
				throw new ProviderError( 'Tool round limit reached.', 'provider_rejected', false );
			}
			++$rounds;

			$assistant_calls = array();
			$tool_messages   = array();
			foreach ( $response->tool_calls as $tool_call ) {
				if ( $calls >= $max_calls ) {
					throw new ProviderError( 'Tool call limit reached.', 'provider_rejected', false );
				}
				$function = is_array( $tool_call['function'] ?? null ) ? $tool_call['function'] : array();
				$name     = sanitize_key( (string) ( $function['name'] ?? '' ) );
				$args     = $this->decode_tool_args( (string) ( $function['arguments'] ?? '{}' ) );
				if ( '' === $name || null === $args ) {
					throw new ProviderError( 'Provider supplied invalid tool arguments.', 'provider_rejected', false );
				}
				$result  = $this->tools->execute( $name, $args, $request->context );
				$encoded = wp_json_encode( $result );
				if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_TOOL_RESULT_BYTES ) {
					throw new ProviderError( 'Tool result exceeded the allowed size.', 'provider_rejected', false );
				}
				$assistant_calls[] = $this->safe_tool_call_for_history( $tool_call, $name, $function['arguments'] ?? '{}' );
				$tool_messages[]   = array(
					'role'         => 'tool',
					'content'      => $encoded,
					'tool_call_id' => sanitize_text_field( (string) ( $tool_call['id'] ?? '' ) ),
					'name'         => $name,
				);
				++$calls;
			}

			$messages[] = array(
				'role'       => 'assistant',
				'content'    => sanitize_text_field( $response->content ),
				'tool_calls' => $assistant_calls,
			);
			foreach ( $tool_messages as $tool_message ) {
				$messages[] = $tool_message;
			}

			$remaining = max( 1, min( $request->timeout, (int) floor( $deadline - microtime( true ) ) ) );
			$response  = $provider->complete( new AIRequest( $messages, $request->tools, $request->temperature, $request->max_tokens, $remaining, $request->context ) );
		}

		return $response;
	}

	private function decode_tool_args( string $json ): ?array {
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	private function safe_tool_call_for_history( array $tool_call, string $name, $arguments ): array {
		return array(
			'id'       => sanitize_text_field( (string) ( $tool_call['id'] ?? '' ) ),
			'type'     => 'function',
			'function' => array(
				'name'      => $name,
				'arguments' => is_string( $arguments ) ? $arguments : '{}',
			),
		);
	}

	private function json_only_content( string $content ): bool {
		$content = trim( $content );
		if ( preg_match( '/^```(?:json)?\s*(.*?)\s*```$/is', $content, $matches ) ) {
			$content = trim( $matches[1] );
		}
		if ( '' === $content ) {
			return false;
		}
		$first = substr( $content, 0, 1 );
		$last  = substr( $content, -1 );
		if ( ! ( ( '{' === $first && '}' === $last ) || ( '[' === $first && ']' === $last ) ) ) {
			return false;
		}
		return is_array( json_decode( $content, true ) );
	}

	public function health(): array {
		$result = array();
		foreach ( $this->providers as $id => $provider ) {
			$last_test = get_transient( 'convocart_test_' . $id );
			$availability = Settings::provider_model_status( $id );
			$last_test = is_array( $last_test ) && ( $last_test['model'] ?? '' ) === Settings::provider_model( $id ) && $provider->is_configured() && $availability['available'] ? $last_test : array();
			$result[ $id ] = array_merge(
				array(
					'id'   => $id,
					'name' => $provider->get_name(),
					'model_status' => $availability,
				),
				array_merge( array( 'configured' => $provider->is_configured(), 'connected' => false, 'message' => __( 'Not tested recently.', 'nexora-shopping-assistant' ) ), $last_test )
			);
		}
		return $result;
	}
}
