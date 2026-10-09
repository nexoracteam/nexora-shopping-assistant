<?php
namespace ConvoCart\Assistant\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class WooCommerceService {
	private const EXCLUDED_TYPES = array( 'bundle', 'grouped', 'woosb', 'mix-and-match' );

	public function search_products( array $filters = array() ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$args = array(
			'limit'    => min( 20, max( 1, absint( $filters['limit'] ?? 6 ) ) ),
			'paginate' => false,
			'status'   => 'publish',
		);
		if ( ! empty( $filters['search'] ) ) {
			$args['s'] = sanitize_text_field( $filters['search'] );
		}
		if ( ! empty( $filters['category'] ) ) {
			$args['category'] = array( sanitize_title( $filters['category'] ) );
		}
		if ( ! empty( $filters['featured'] ) ) {
			$args['featured'] = true;
		}
		$args['visibility'] = 'visible';
		$products = wc_get_products( $args );
		$results  = array_values( array_filter( array_map( array( $this, 'to_public_product' ), $products ) ) );
		return array_values( array_filter( $results, array( $this, 'is_allowed_product' ) ) );
	}

	private function is_allowed_product( array $product ): bool {
		if ( in_array( $product['type'] ?? '', self::EXCLUDED_TYPES, true ) ) {
			return false;
		}
		return true;
	}

	public function get_product( int $product_id ): ?array {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
		if ( ! $product ) {
			return null;
		}
		$data = $this->to_public_product( $product );
		if ( ! $data || ! $this->is_allowed_product( $data ) ) {
			return null;
		}
		return $data;
	}

	public function get_product_object( int $product_id ) {
		return function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
	}

	public function add_to_cart( int $product_id, int $quantity = 1, int $variation_id = 0, array $variation = array() ): array {
		$woocommerce = $this->ensure_cart();
		if ( ! $woocommerce || ! is_object( $woocommerce->cart ?? null ) ) {
			return array(
				'success' => false,
				'code'    => 'cart_unavailable',
				'message' => __( 'The cart is unavailable. Please refresh and try again.', 'nexora-shopping-assistant' ),
			);
		}
		$product = $this->get_product_object( $product_id );
		if ( ! $product || ! $this->is_public_product( $product ) || ! $product->is_purchasable() ) {
			return array(
				'success' => false,
				'code'    => 'product_unavailable',
				'message' => __( 'That product is not available for purchase.', 'nexora-shopping-assistant' ),
			);
		}
		$quantity = max( 1, min( 99, $quantity ) );
		// In-chat purchases support simple products only. Other types must use
		// their product-page flow so required extension/variation data is collected.
		if ( ! $product->is_type( 'simple' ) || $variation_id > 0 || ! empty( $variation ) ) {
			return array( 'success' => false, 'code' => 'product_options_required', 'message' => __( 'Please use the product page to choose options and add this item.', 'nexora-shopping-assistant' ) );
		}
		if ( is_callable( array( $product, 'is_sold_individually' ) ) && $product->is_sold_individually() ) {
			$quantity = 1;
		}
		if ( ! $product->is_in_stock() && ! $product->backorders_allowed() ) {
			return array(
				'success' => false,
				'code'    => 'out_of_stock',
				'message' => __( 'That product is out of stock.', 'nexora-shopping-assistant' ),
			);
		}
		// WC_Cart does not run this entry-point filter itself. Respect the same
		// validation used by WooCommerce's simple-product form/AJAX handlers.
		if ( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter, applied intentionally.
			return array( 'success' => false, 'code' => 'validation_failed', 'message' => __( 'This product could not be added. Check its product page for purchase requirements.', 'nexora-shopping-assistant' ) );
		}
		$cart_key = $woocommerce->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );
		if ( ! $cart_key ) {
			return array(
				'success' => false,
				'code'    => 'cart_rejected',
				'message' => __( 'WooCommerce could not add that product to the cart.', 'nexora-shopping-assistant' ),
			);
		}
		if ( is_object( $woocommerce->session ?? null ) && is_callable( array( $woocommerce->session, 'set_customer_session_cookie' ) ) ) {
			$woocommerce->session->set_customer_session_cookie( true );
		}
		return array(
			'success'    => true,
			'cart_key'   => sanitize_key( $cart_key ),
			'cart_count' => is_callable( array( $woocommerce->cart, 'get_cart_contents_count' ) ) ? absint( $woocommerce->cart->get_cart_contents_count() ) : null,
			'cart_hash'  => is_callable( array( $woocommerce->cart, 'get_cart_hash' ) ) ? sanitize_text_field( (string) $woocommerce->cart->get_cart_hash() ) : '',
			'message'    => __( 'Added to cart.', 'nexora-shopping-assistant' ),
			'fragments'  => $this->cart_fragments(),
		);
	}

	private function ensure_cart() {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		if ( ! $woocommerce ) {
			return null;
		}
		if ( ! is_object( $woocommerce->cart ?? null ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
			$woocommerce = WC();
		}
		if ( is_object( $woocommerce->session ?? null ) && is_callable( array( $woocommerce->session, 'init' ) ) && ! is_callable( array( $woocommerce->session, 'get_customer_id' ) ) ) {
			$woocommerce->session->init();
		}
		if ( ! is_object( $woocommerce->customer ?? null ) && class_exists( 'WC_Customer' ) ) {
			$woocommerce->customer = new \WC_Customer( get_current_user_id(), true );
		}
		return $woocommerce;
	}

	private function cart_fragments(): array {
		if ( ! function_exists( 'woocommerce_mini_cart' ) || ! function_exists( 'apply_filters' ) ) {
			return array();
		}
		ob_start();
		woocommerce_mini_cart();
		$mini_cart = ob_get_clean();
		return apply_filters(
			'woocommerce_add_to_cart_fragments', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter, applied intentionally.
			array(
				'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
			)
		);
	}

	private function to_public_product( $product ): ?array {
		if ( ! is_object( $product ) || ! is_a( $product, 'WC_Product' ) ) {
			return null;
		}
		if ( ! $this->is_public_product( $product ) ) { return null; }
		$price_html = $product->get_price_html();
		$price_text = str_replace( "\xC2\xA0", ' ', html_entity_decode( wp_strip_all_tags( $price_html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$price_text = trim( preg_replace( '/\s+/', ' ', $price_text ) );

		$product_id   = (int) $product->get_id();
		$stock_status = is_callable( array( $product, 'get_stock_status' ) ) ? (string) $product->get_stock_status() : ( $product->is_in_stock() ? 'instock' : 'outofstock' );
		$stock_qty    = is_callable( array( $product, 'get_stock_quantity' ) ) ? $product->get_stock_quantity() : null;

		$categories = array();
		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[] = $term->name;
			}
		}

		return array(
			'id'                => $product_id,
			'name'              => $product->get_name(),
			'type'              => $product->get_type(),
			'price'             => (string) $product->get_price(),
			'price_text'        => $price_text,
			'price_html'        => wp_kses_post( $price_html ),
			'on_sale'           => (bool) $product->is_on_sale(),
			'in_stock'          => (bool) $product->is_in_stock(),
			'stock_status'      => sanitize_key( $stock_status ),
			'stock_qty'         => is_numeric( $stock_qty ) ? (int) $stock_qty : null,
			'purchasable'       => (bool) $product->is_purchasable(),
			'permalink'         => esc_url_raw( get_permalink( $product_id ) ),
			'image'             => esc_url_raw( wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) ?: wc_placeholder_img_src() ),
			'sku'               => sanitize_text_field( (string) $product->get_sku() ),
			'short_description' => sanitize_text_field( wp_strip_all_tags( (string) $product->get_short_description() ) ),
			'description'       => mb_substr( sanitize_text_field( wp_strip_all_tags( (string) $product->get_description() ) ), 0, 2000 ),
			'attributes'        => $this->public_attributes( $product ),
			'categories'        => $categories,
			'variation_ids'     => 'variable' === $product->get_type() ? array_map( 'absint', $product->get_children() ) : array(),
		);
	}

	public function is_public_product( $product ): bool {
		return is_a( $product, 'WC_Product' ) && 'publish' === $product->get_status() && '' === (string) get_post_field( 'post_password', $product->get_id() ) && 'visible' === $product->get_catalog_visibility() && ! in_array( $product->get_type(), self::EXCLUDED_TYPES, true ) && $product->is_visible();
	}

	private function public_attributes( $product ): array {
		$result = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) || ! $attribute->get_visible() ) { continue; }
			$result[ wc_attribute_label( $attribute->get_name() ) ] = mb_substr( wp_strip_all_tags( $product->get_attribute( $attribute->get_name() ) ), 0, 300 );
			if ( count( $result ) >= 10 ) { break; }
		}
		return $result;
	}
}
