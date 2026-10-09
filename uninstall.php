<?php
/**
 * Uninstall handler.
 *
 * Runs only when the plugin is deleted from the Plugins screen. Retained data,
 * settings and encrypted keys are kept unless the site owner disabled
 * "Preserve data on uninstall". Ephemeral caches are always removed.
 *
 * @package nexora-shopping-assistant
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Everything runs inside a closure so no global variables are created.
( static function (): void {
	// This release supports per-site activation. Clean every site's plugin-owned
	// state on network deletion, honoring each site's preservation preference.
	// Option, table and hook names keep the historical "convocart" prefix so data
	// created by earlier ConvoCart AI builds is handled too.
	$cleanup_site = static function (): void {
		global $wpdb;
		$settings = get_option( 'convocart_settings', array() );
		$preserve = ! array_key_exists( 'preserve_data_on_uninstall', (array) $settings ) || ! empty( $settings['preserve_data_on_uninstall'] );
		foreach ( array( 'convocart_cleanup', 'convocart_cleanup_continue', 'convocart_sync_product', 'convocart_sync_batch', 'convocart_sync_batch_v2', 'convocart_sync_recovery', 'convocart_check_provider_models', 'convocart_refresh_provider_models' ) as $hook ) {
			if ( function_exists( '_get_cron_array' ) ) {
				foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
					foreach ( (array) ( $hooks[ $hook ] ?? array() ) as $event ) {
						wp_unschedule_event( (int) $timestamp, $hook, (array) ( $event['args'] ?? array() ) );
					}
				}
			}
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, null, 'convocart' );
			}
		}

		// Session caches are ephemeral even when retained data is preserved.
		$memory_ids = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_convocart_memory_' ) . '%' ) );
		foreach ( (array) $memory_ids as $name ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		}

		// Cart confirmations are ephemeral even when retained plugin data is kept.
		$cart_options = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'convocart_cartlock_' ) . '%',
				$wpdb->esc_like( '_transient_convocart_cart_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_convocart_cart_' ) . '%'
			)
		);
		foreach ( (array) $cart_options as $name ) {
			delete_option( $name );
		}

		$conversations = $wpdb->prefix . 'convocart_conversations';
		$table_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $conversations ) ) );
		if ( $table_exists ) {
			$cursor = 0;
			do {
				$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE id>%d ORDER BY id ASC LIMIT 500', $conversations, $cursor ) );
				foreach ( (array) $ids as $id ) {
					delete_transient( 'convocart_memory_' . absint( $id ) );
					$cursor = max( $cursor, (int) $id );
				}
			} while ( count( (array) $ids ) === 500 );
		}

		if ( ! $preserve ) {
			foreach ( array( 'conversations', 'messages', 'knowledge', 'sync_jobs', 'events', 'provider_usage', 'feedback' ) as $suffix ) {
				// Fixed allowlist of the plugin's own tables; dropped only when the owner opted out of preservation.
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'convocart_' . $suffix ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intended uninstall purge.
			}
			foreach ( array( 'groq', 'gemini', 'openai' ) as $provider ) {
				foreach ( array( 'convocart_models_', 'convocart_models_good_', 'convocart_models_attempt_', 'convocart_model_rejections_', 'convocart_test_' ) as $prefix ) {
					delete_transient( $prefix . $provider );
				}
			}
			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
					$wpdb->esc_like( 'convocart_' ) . '%',
					$wpdb->esc_like( '_transient_convocart_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_convocart_' ) . '%'
				)
			);
			foreach ( (array) $names as $name ) {
				delete_option( $name );
			}
		}

		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				foreach ( array( 'manage_settings', 'manage_providers', 'manage_knowledge', 'view_conversations', 'manage_privacy', 'view_analytics' ) as $cap ) {
					$role->remove_cap( 'convocart_' . $cap );
				}
			}
		}
	};

	if ( is_multisite() ) {
		$offset = 0;
		do {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 100,
					'offset' => $offset,
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( $site_id );
				$cleanup_site();
				restore_current_blog();
			}
			$offset += 100;
		} while ( count( $sites ) === 100 );
	} else {
		$cleanup_site();
	}
} )();
