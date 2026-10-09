<?php
namespace ConvoCart\Assistant\AI\Providers;

use ConvoCart\Assistant\AI\DTO\AIRequest;
use ConvoCart\Assistant\AI\DTO\AIResponse;
use ConvoCart\Assistant\AI\DTO\ProviderError;

defined( 'ABSPATH' ) || exit;

final class GroqProvider extends AbstractProvider {
	public function get_id(): string {
		return 'groq'; }
	public function get_name(): string {
		return 'Groq'; }

	public function complete( AIRequest $request ): AIResponse {
		if ( ! $this->is_configured() ) {
			throw new ProviderError( 'Provider is not configured.', 'not_configured', false );
		}
		$body = array(
			'model'       => $this->model(),
			'messages'    => $request->messages,
			'temperature' => $request->temperature,
			'max_tokens'  => $request->max_tokens,
		);
		$this->assert_model_available( $request );
		if ( ! empty( $request->context['shopping'] ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}
		if ( ! empty( $request->tools ) ) {
			$body['tools'] = $request->tools;
		}
		$data    = $this->request(
			'https://api.groq.com/openai/v1/chat/completions',
			array(
				'Authorization' => 'Bearer ' . $this->get_api_key(),
				'Content-Type'  => 'application/json',
			),
			$body,
			$request->timeout
		);
		$content = (string) ( $data['choices'][0]['message']['content'] ?? '' );
		if ( in_array( $data['choices'][0]['finish_reason'] ?? '', array( 'length', 'content_filter' ), true ) ) {
			throw new ProviderError( 'Provider response was incomplete.', 'invalid_shopping_response', true );
		}
		if ( '' === $content && empty( $data['choices'][0]['message']['tool_calls'] ) ) {
			throw new ProviderError( 'Provider returned an empty response.', 'malformed_provider_response', true );
		}
		return new AIResponse( $this->get_id(), $this->model(), $content, (array) ( $data['choices'][0]['message']['tool_calls'] ?? array() ), (array) ( $data['usage'] ?? array() ), (string) ( $data['choices'][0]['finish_reason'] ?? 'stop' ) );
	}
}
