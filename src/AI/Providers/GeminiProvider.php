<?php
namespace ConvoCart\Assistant\AI\Providers;

use ConvoCart\Assistant\AI\DTO\AIRequest;
use ConvoCart\Assistant\AI\DTO\AIResponse;
use ConvoCart\Assistant\AI\DTO\ProviderError;

defined( 'ABSPATH' ) || exit;

final class GeminiProvider extends AbstractProvider {
	public function get_id(): string {
		return 'gemini'; }
	public function get_name(): string {
		return 'Google Gemini'; }

	public function supports_tools(): bool {
		return false;
	}

	public function complete( AIRequest $request ): AIResponse {
		if ( ! $this->is_configured() ) {
			throw new ProviderError( 'Provider is not configured.', 'not_configured', false );
		}
		$this->assert_model_available( $request );
		$contents           = array();
		$system_instruction = '';
		foreach ( $request->messages as $message ) {
			$role    = sanitize_key( (string) ( $message['role'] ?? 'user' ) );
			$content = (string) ( $message['content'] ?? '' );
			if ( '' === trim( $content ) ) {
				continue;
			}
			if ( 'system' === $role ) {
				$system_instruction .= ( '' === $system_instruction ? '' : "\n\n" ) . $content;
				continue;
			}
			$contents[] = array(
				'role'  => 'assistant' === $role || 'model' === $role ? 'model' : 'user',
				'parts' => array( array( 'text' => $content ) ),
			);
		}
		if ( empty( $contents ) ) {
			throw new ProviderError( 'Provider request is empty.', 'provider_rejected', false );
		}
		$body = array(
			'contents'         => $contents,
			'generationConfig' => array(
				'temperature'     => $request->temperature,
				'maxOutputTokens' => $request->max_tokens,
			),
		);
		if ( ! empty( $request->context['shopping'] ) ) {
			$body['generationConfig']['responseMimeType'] = 'application/json';
		}
		if ( '' !== trim( $system_instruction ) ) {
			$body['systemInstruction'] = array(
				'parts' => array( array( 'text' => $system_instruction ) ),
			);
		}
		$url     = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $this->model() ) . ':generateContent';
		$data    = $this->request(
			$url,
			array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $this->get_api_key(),
			),
			$body,
			$request->timeout
		);
		$content = '';
		foreach ( (array) ( $data['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			if ( is_array( $part ) && empty( $part['thought'] ) && is_string( $part['text'] ?? null ) ) { $content .= $part['text']; }
		}
		if ( 'STOP' !== ( $data['candidates'][0]['finishReason'] ?? '' ) ) {
			throw new ProviderError( 'Gemini response was incomplete or blocked.', 'invalid_shopping_response', true );
		}
		if ( '' === $content ) {
			throw new ProviderError( 'Provider returned an empty response.', 'malformed_provider_response', false );
		}
		$usage = (array) ( $data['usageMetadata'] ?? array() );
		return new AIResponse( $this->get_id(), $this->model(), $content, array(), array( 'prompt_tokens' => absint( $usage['promptTokenCount'] ?? 0 ), 'completion_tokens' => absint( $usage['candidatesTokenCount'] ?? 0 ) + absint( $usage['thoughtsTokenCount'] ?? 0 ), 'total_tokens' => absint( $usage['totalTokenCount'] ?? 0 ) ), 'STOP' );
	}
}
