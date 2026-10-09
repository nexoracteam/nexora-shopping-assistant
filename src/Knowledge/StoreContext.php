<?php
namespace ConvoCart\Assistant\Knowledge;

use ConvoCart\Assistant\WooCommerce\WooCommerceService;

defined( 'ABSPATH' ) || exit;

/** A disposable, provenance-checked snapshot, never an independent source of truth. */
final class StoreContext {
	public static function register(): void {
		add_action( 'save_post', array( self::class, 'post_changed' ), 20, 2 );
		add_action( 'before_delete_post', array( self::class, 'post_changed' ) );
		add_action( 'added_post_meta', array( self::class, 'meta_changed' ), 20, 2 );
		add_action( 'updated_post_meta', array( self::class, 'meta_changed' ), 20, 2 );
		add_action( 'deleted_post_meta', array( self::class, 'meta_changed' ), 20, 2 );
		foreach ( array( 'set_object_terms', 'created_term', 'edited_term', 'delete_term', 'wp_update_nav_menu', 'wp_delete_nav_menu', 'woocommerce_update_product', 'woocommerce_update_product_variation' ) as $hook ) {
			add_action( $hook, array( self::class, 'invalidate' ), 20, 0 );
		}
		add_action( 'updated_option', array( self::class, 'option_changed' ), 20, 1 );
	}

	public static function post_changed( int $id, ?\WP_Post $post = null ): void {
		$post = $post ?? get_post( $id );
		if ( $post && in_array( $post->post_type, array( 'page', 'post', 'product', 'product_variation', 'nav_menu_item' ), true ) ) {
			self::invalidate();
		}
	}

	public static function meta_changed( $meta_id, int $post_id ): void {
		self::post_changed( $post_id );
	}

	public static function option_changed( string $option ): void {
		if ( in_array( $option, array( 'blogname', 'blogdescription', 'home', 'wp_page_for_privacy_policy', 'woocommerce_terms_page_id' ), true ) || 0 === strpos( $option, 'theme_mods_' ) ) {
			self::invalidate();
		}
	}

	public static function generation(): string {
		// Cross-request invalidation must not use a request-local option cache.
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", 'convocart_context_generation' ) );
	}

	public static function invalidate(): void {
		if ( ! get_option( 'convocart_site_analysis', '' ) && ! get_transient( 'convocart_analyzing' ) ) { return; }
		update_option( 'convocart_context_generation', wp_generate_uuid4(), false );
		delete_option( 'convocart_site_analysis' );
		delete_option( 'convocart_context_sources' );
		delete_option( 'convocart_analyze_stats' );
		delete_option( 'convocart_analyze_last_run' );
		update_option( 'convocart_context_invalidated_at', current_time( 'mysql', true ), false );
	}

	/** Extensions can exclude membership-protected content in both admin and chat. */
	public static function is_public( \WP_Post $post ): bool {
		if ( 'publish' !== $post->post_status || '' !== $post->post_password || ! is_post_publicly_viewable( $post ) ) { return false; }
		if ( 'product' === $post->post_type ) {
			$service = new WooCommerceService();
			if ( ! $service->is_public_product( $service->get_product_object( (int) $post->ID ) ) ) { return false; }
		}
		return (bool) apply_filters( 'convocart_store_context_source_allowed', true, $post );
	}

	public static function fingerprint( \WP_Post $post ): string {
		return hash( 'sha256', wp_json_encode( array( $post->post_title, $post->post_content, $post->post_excerpt, $post->post_status, $post->post_password, $post->post_modified_gmt ) ) );
	}

	private static function valid_sources( array $sources ): bool {
		foreach ( $sources as $id => $fingerprint ) {
			$post = get_post( (int) $id );
			if ( ! $post || ! self::is_public( $post ) || ! is_string( $fingerprint ) || ! hash_equals( $fingerprint, self::fingerprint( $post ) ) ) { return false; }
		}
		return true;
	}

	public static function save( string $context, array $sources, string $generation ): bool {
		if ( $generation !== self::generation() || ! self::valid_sources( $sources ) ) { return false; }
		update_option( 'convocart_context_sources', array( 'version' => 1, 'generation' => $generation, 'sources' => $sources, 'context_hash' => hash( 'sha256', $context ) ), false );
		update_option( 'convocart_site_analysis', $context, false );
		if ( self::get() !== $context ) { return false; }
		delete_option( 'convocart_context_invalidated_at' );
		return true;
	}

	public static function get(): string {
		$context = get_option( 'convocart_site_analysis', '' );
		if ( ! is_string( $context ) || '' === $context ) { return ''; }
		$meta = get_option( 'convocart_context_sources', array() );
		$generation = self::generation();
		// Legacy snapshots have no provenance and must be rebuilt once.
		if ( ! is_array( $meta ) || 1 !== ( $meta['version'] ?? 0 ) || ( $meta['generation'] ?? null ) !== $generation || ( $meta['context_hash'] ?? '' ) !== hash( 'sha256', $context ) || ! is_array( $meta['sources'] ?? null ) || ! self::valid_sources( $meta['sources'] ) || $generation !== self::generation() ) {
			self::invalidate();
			return '';
		}
		return $context;
	}
}
