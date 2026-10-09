<?php
namespace ConvoCart\Assistant\AI;

use ConvoCart\Assistant\AI\DTO\ProviderError;

defined( 'ABSPATH' ) || exit;

final class ShoppingResponse {
	public static function schema(): array {
		return array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'message', 'recommendations', 'suggestions' ), 'properties' => array(
			'message' => array( 'type' => 'string' ),
			'recommendations' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'product_id', 'reason' ), 'properties' => array( 'product_id' => array( 'type' => 'integer' ), 'reason' => array( 'type' => 'string' ) ) ) ),
			'suggestions' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
		) );
	}

	public static function validate( string $content, array $allowed_ids, int $limit = 3 ): array {
		$data = json_decode( trim( $content ), true );
		if ( ! is_array( $data ) || array_diff( array_keys( $data ), array( 'message', 'recommendations', 'suggestions' ) ) || ! is_string( $data['message'] ?? null ) || '' === trim( $data['message'] ) || strlen( $data['message'] ) > 8000 || ! is_array( $data['recommendations'] ?? null ) || ! array_is_list( $data['recommendations'] ) || count( $data['recommendations'] ) > $limit || ! is_array( $data['suggestions'] ?? null ) || ! array_is_list( $data['suggestions'] ) || count( $data['suggestions'] ) > 4 ) {
			throw new ProviderError( 'Invalid shopping response schema.', 'invalid_shopping_response', true );
		}
		$seen = array();
		foreach ( $data['recommendations'] as $rec ) {
			if ( ! is_array( $rec ) || array_diff( array_keys( $rec ), array( 'product_id', 'reason' ) ) || ! is_int( $rec['product_id'] ?? null ) || ! in_array( $rec['product_id'], $allowed_ids, true ) || isset( $seen[ $rec['product_id'] ] ) || ! is_string( $rec['reason'] ?? null ) || strlen( $rec['reason'] ) > 2000 ) {
				throw new ProviderError( 'Recommendation is not in the supplied public candidate list.', 'invalid_shopping_response', true );
			}
			$seen[ $rec['product_id'] ] = true;
		}
		foreach ( $data['suggestions'] as $text ) {
			if ( ! is_string( $text ) || '' === trim( $text ) || strlen( $text ) > 200 ) {
				throw new ProviderError( 'Invalid shopping suggestions.', 'invalid_shopping_response', true );
			}
		}
		$data['message'] = sanitize_textarea_field( $data['message'] );
		$data['suggestions'] = array_map( 'sanitize_text_field', $data['suggestions'] );
		return $data;
	}
}
