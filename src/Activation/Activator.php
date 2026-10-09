<?php
namespace ConvoCart\Assistant\Activation;

use ConvoCart\Assistant\Database\Schema;
use ConvoCart\Assistant\Security\Capabilities;
use ConvoCart\Assistant\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Activator {
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide ) { throw new \RuntimeException( 'Network activation is not supported. Activate Nexora Shopping Assistant per site.' ); }
		if ( ! function_exists( 'mb_substr' ) || ( ! function_exists( 'sodium_crypto_secretbox' ) && ! function_exists( 'openssl_encrypt' ) ) ) { throw new \RuntimeException( 'Nexora Shopping Assistant requires mbstring and Sodium or OpenSSL.' ); }
		$result = Schema::migrate();
		if ( empty( $result['success'] ) ) {
			throw new \RuntimeException( 'schema_migration_failed' );
		}
		Capabilities::grant();
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
		add_option( 'convocart_initial_sync', 'pending', '', false );
		if ( ! wp_next_scheduled( 'convocart_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'convocart_cleanup' );
		}
		flush_rewrite_rules();
	}
}
