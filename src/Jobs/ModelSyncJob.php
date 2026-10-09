<?php
namespace ConvoCart\Assistant\Jobs;

use ConvoCart\Assistant\AI\Encryption\EncryptionService;
use ConvoCart\Assistant\AI\ProviderModelService;

defined( 'ABSPATH' ) || exit;

/** Independent per-provider checks avoid a slow provider blocking all inventories. */
final class ModelSyncJob {
	public function register(): void {
		add_action( 'init', array( $this, 'ensure_schedules' ), 30 );
		add_action( 'convocart_check_provider_models', array( $this, 'run' ), 10, 1 );
		add_action( 'convocart_refresh_provider_models', array( $this, 'refresh' ), 10, 1 );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	public function ensure_schedules(): void {
		$encryption = new EncryptionService();
		foreach ( array( 'groq', 'gemini', 'openai' ) as $position => $provider ) {
			$args = array( $provider );
			if ( '' === $encryption->get( $provider ) ) {
				wp_clear_scheduled_hook( 'convocart_check_provider_models', $args );
				wp_clear_scheduled_hook( 'convocart_refresh_provider_models', $args );
				continue;
			}
			if ( ! wp_next_scheduled( 'convocart_check_provider_models', $args ) ) {
				wp_schedule_event( time() + 60 + ( 30 * $position ), 'hourly', 'convocart_check_provider_models', $args );
			}
			if ( ! get_transient( 'convocart_models_' . $provider ) ) { ProviderModelService::queue_refresh( $provider ); }
		}
	}

	public function run( string $provider ): void {
		if ( EncryptionService::is_supported_provider( $provider ) ) { ( new ProviderModelService() )->refresh_if_due( $provider ); }
	}

	public function refresh( string $provider ): void {
		if ( EncryptionService::is_supported_provider( $provider ) && '' !== ( new EncryptionService() )->get( $provider ) ) { ( new ProviderModelService() )->list_models( $provider, true ); }
	}

	public function notices(): void {
		if ( ! current_user_can( \ConvoCart\Assistant\Security\Capabilities::MANAGE_PROVIDERS ) ) { return; }
		foreach ( array( 'groq', 'gemini', 'openai' ) as $provider ) {
			if ( '' === ( new EncryptionService() )->get( $provider ) ) { continue; }
			$status = \ConvoCart\Assistant\Support\Settings::provider_model_status( $provider );
			if ( $status['available'] && 'scheduled' !== $status['status'] ) { continue; }
			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html( strtoupper( $provider ) . ' — ' . $status['model'] . ': ' . $status['message'] ),
				esc_url( admin_url( 'admin.php?page=convocart-providers' ) ),
				esc_html__( 'Review available models', 'nexora-shopping-assistant' )
			);
		}
	}
}
