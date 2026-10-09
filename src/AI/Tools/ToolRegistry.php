<?php
namespace ConvoCart\Assistant\AI\Tools;

use ConvoCart\Assistant\WooCommerce\WooCommerceService;

defined( 'ABSPATH' ) || exit;

final class ToolRegistry {
	public function __construct( private WooCommerceService $woocommerce ) {}

	public function definitions(): array {
		return array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'search_products',
					'description' => 'Find verified published WooCommerce products.',
					'parameters'  => array(
						'type'                 => 'object',
						'properties'           => array(
							'query' => array(
								'type'      => 'string',
								'maxLength' => 200,
							),
							'limit' => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 10,
							),
						),
						'additionalProperties' => false,
					),
				),
			),
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'get_product_details',
					'description' => 'Return verified details for one WooCommerce product.',
					'parameters'  => array(
						'type'                 => 'object',
						'required'             => array( 'product_id' ),
						'properties'           => array(
							'product_id' => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						),
						'additionalProperties' => false,
					),
				),
			),
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'check_product_stock',
					'description' => 'Check live stock and purchasability.',
					'parameters'  => array(
						'type'                 => 'object',
						'required'             => array( 'product_id' ),
						'properties'           => array(
							'product_id' => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						),
						'additionalProperties' => false,
					),
				),
			),
		);
	}

	public function execute( string $name, array $arguments ): array {
		$name = sanitize_key( $name );
		if ( ! in_array( $name, array( 'search_products', 'get_product_details', 'check_product_stock' ), true ) ) {
			return array(
				'success'    => false,
				'error_code' => 'tool_not_allowed',
			);
		}
		if ( 'search_products' === $name ) {
			$query  = mb_substr( sanitize_text_field( (string) ( $arguments['query'] ?? '' ) ), 0, 200 );
			$limit  = min( 10, max( 1, absint( $arguments['limit'] ?? 6 ) ) );
			return array(
				'success'  => true,
				'products' => $this->woocommerce->search_products(
					array(
						'search' => $query,
						'limit'  => $limit,
					)
				),
			);
		}
		$product_id = absint( $arguments['product_id'] ?? 0 );
		$product    = $this->woocommerce->get_product( $product_id );
		if ( ! $product ) {
			return array(
				'success'    => false,
				'error_code' => 'invalid_product',
			);
		}
		if ( 'check_product_stock' === $name ) {
			return array(
				'success'     => true,
				'product_id'  => $product_id,
				'in_stock'    => $product['in_stock'],
				'purchasable' => $product['purchasable'],
			);
		}
		return array(
			'success' => true,
			'product' => $product,
		);
	}
}
