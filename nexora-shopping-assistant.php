<?php
/**
 * Plugin Name:       Nexora Shopping Assistant for WooCommerce
 * Plugin URI:        https://nexoracreation.com/nexora-shopping-assistant-for-woocommerce-plugin/
 * Description:       Bring-your-own-key AI shopping assistant for WooCommerce that recommends products from your catalogue and adds simple products to the cart.
 * Version:           1.2.3
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC tested up to:   11.2
 * Author:            Nexora Creation
 * Author URI:        https://nexoracreation.com
 * Text Domain:       nexora-shopping-assistant
 * Domain Path:       /languages
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package nexora-shopping-assistant
 */

defined( 'ABSPATH' ) || exit;

// This plugin was previously distributed as "ConvoCart AI" (folder convocart/).
// Both builds share classes, options and tables, so only one may load. When the
// legacy build loaded first, explain the situation instead of failing silently.
if ( defined( 'CONVOCART_BOOTSTRAPPED' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Nexora Shopping Assistant is inactive because another copy of this plugin (for example the earlier ConvoCart AI build) is already loaded. Deactivate the other copy; your settings and data are shared and will be kept.', 'nexora-shopping-assistant' ) . '</p></div>';
		}
	);
	return;
}
define( 'CONVOCART_BOOTSTRAPPED', true );

// WordPress normally enforces the header requirement, but this guard keeps
// older WordPress versions and unusual loaders from parsing PHP 8.1 classes
// and showing a white-screen fatal error.
if ( PHP_VERSION_ID < 80100 ) {
	register_activation_hook(
		__FILE__,
		static function (): void {
			if ( function_exists( 'deactivate_plugins' ) ) {
				deactivate_plugins( plugin_basename( __FILE__ ), true );
			}
			if ( function_exists( 'wp_die' ) ) {
				wp_die( esc_html__( 'Nexora Shopping Assistant requires PHP 8.1 or newer. Upgrade PHP before activating the plugin.', 'nexora-shopping-assistant' ) );
			}
			throw new \RuntimeException( 'Nexora Shopping Assistant requires PHP 8.1 or newer.' );
		}
	);
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Nexora Shopping Assistant requires PHP 8.1 or newer. Upgrade PHP before activating the plugin.', 'nexora-shopping-assistant' ) . '</p></div>';
		}
	);
	return;
}

// Internal constant names keep the CONVOCART_ prefix for compatibility with
// existing installs (CONVOCART_ENCRYPTION_KEY / CONVOCART_TRUSTED_PROXIES are
// defined by site owners in wp-config.php).
define( 'CONVOCART_VERSION', '1.2.3' );
define( 'CONVOCART_FILE', __FILE__ );
define( 'CONVOCART_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONVOCART_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'ConvoCart\\Assistant\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = CONVOCART_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook(
	__FILE__,
	static function ( bool $network_wide = false ): void {
		try {
			ConvoCart\Assistant\Activation\Activator::activate( $network_wide );
		} catch ( \Throwable $error ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// Debug-only diagnostic; the message never contains credentials.
				error_log( '[Nexora Shopping Assistant] Activation failed: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			if ( function_exists( 'deactivate_plugins' ) ) {
				deactivate_plugins( plugin_basename( __FILE__ ), true );
			}
			wp_die( esc_html__( 'Nexora Shopping Assistant could not be activated. Check the PHP error log for the underlying error.', 'nexora-shopping-assistant' ) );
		}
	}
);
register_deactivation_hook( __FILE__, array( 'ConvoCart\\Assistant\\Activation\\Deactivator', 'deactivate' ) );

// The plugin never reads or writes order storage, and it does not alter the
// cart/checkout blocks; it adds items through WC_Cart only.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		ConvoCart\Assistant\Plugin::instance()->boot();
	}
);
