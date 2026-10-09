<?php
namespace ConvoCart\Assistant;

use ConvoCart\Assistant\Admin\AdminController;
use ConvoCart\Assistant\AI\ProviderManager;
use ConvoCart\Assistant\AI\Tools\ToolRegistry;
use ConvoCart\Assistant\Analytics\AnalyticsService;
use ConvoCart\Assistant\API\RestController;
use ConvoCart\Assistant\Conversation\ConversationEngine;
use ConvoCart\Assistant\Database\Schema;
use ConvoCart\Assistant\Feedback\FeedbackService;
use ConvoCart\Assistant\Frontend\FrontendController;
use ConvoCart\Assistant\Jobs\CleanupJob;
use ConvoCart\Assistant\Knowledge\KnowledgeManager;
use ConvoCart\Assistant\Logging\RedactedLogger;
use ConvoCart\Assistant\Privacy\PrivacyService;
use ConvoCart\Assistant\Security\Capabilities;
use ConvoCart\Assistant\Support\Settings;
use ConvoCart\Assistant\WooCommerce\WooCommerceService;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static ?self $instance  = null;
	private ?Container $container   = null;
	private bool $booted            = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
			add_action( 'woocommerce_loaded', array( $this, 'boot' ), 20 );
			add_action( 'init', array( $this, 'boot' ), 1 );
			return;
		}

		$this->booted = true;

		$this->container = new Container();
		$this->container->set( Settings::class, new Settings() );
		$this->container->set( RedactedLogger::class, new RedactedLogger() );
		$this->container->set( WooCommerceService::class, new WooCommerceService() );
		$knowledge = new KnowledgeManager( $this->container->get( WooCommerceService::class ) );
		$knowledge->register();
		\ConvoCart\Assistant\Knowledge\StoreContext::register();
		$this->container->set( KnowledgeManager::class, $knowledge );
		$this->container->set( ToolRegistry::class, new ToolRegistry( $this->container->get( WooCommerceService::class ) ) );
		$this->container->set( ProviderManager::class, new ProviderManager( $this->container->get( ToolRegistry::class ) ) );
		$this->container->set( AnalyticsService::class, new AnalyticsService() );
		$this->container->set( FeedbackService::class, new FeedbackService() );
		$this->container->set( ConversationEngine::class, new ConversationEngine( $this->container ) );
		$privacy = new PrivacyService();
		$privacy->register();
		$this->container->set( PrivacyService::class, $privacy );

		( new FrontendController( $this->container ) )->register();
		( new RestController( $this->container ) )->register();
		( new AdminController( $this->container ) )->register();
		( new CleanupJob() )->register();
		( new \ConvoCart\Assistant\Jobs\ModelSyncJob() )->register();
		$this->register_upgrades();
		add_action( 'init', array( $this, 'ensure_cleanup_schedule' ), 30 );
		add_action( 'admin_init', array( $this, 'ensure_capabilities' ) );
		add_action( 'admin_notices', array( $this, 'migration_notice' ) );
		add_action( 'admin_notices', array( $this, 'legacy_copy_notice' ) );
	}

	/**
	 * Warn before the earlier ConvoCart AI build is deleted with purging enabled.
	 *
	 * Both builds share the same tables and options. Deleting the old folder runs
	 * its uninstaller, which drops that shared data when "Preserve data on
	 * uninstall" is off.
	 */
	public function legacy_copy_notice(): void {
		if ( ! current_user_can( 'delete_plugins' ) || ! defined( 'WP_PLUGIN_DIR' ) ) {
			return;
		}
		if ( ! file_exists( WP_PLUGIN_DIR . '/convocart/convocart.php' ) || Settings::get( 'preserve_data_on_uninstall', true ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'The earlier ConvoCart AI plugin folder is still installed and "Preserve data on uninstall" is off. Deleting ConvoCart AI now would also delete this plugin\'s conversations, settings and API keys. Turn "Preserve data on uninstall" on before deleting it.', 'nexora-shopping-assistant' ) . '</p></div>';
	}

	public function dependency_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Nexora Shopping Assistant requires WooCommerce to be active. The plugin is currently disabled until WooCommerce is available.', 'nexora-shopping-assistant' )
		);
	}

	public function container(): Container {
		return $this->container ?? new Container();
	}

	/**
	 * Restore plugin capabilities when they are missing.
	 *
	 * Capabilities are normally granted on activation. The earlier ConvoCart AI
	 * uninstaller removed them even when data was preserved, which would hide the
	 * admin screens of this build after a migration. Re-granting is idempotent.
	 */
	public function ensure_capabilities(): void {
		$administrator = get_role( 'administrator' );
		if ( $administrator && ! $administrator->has_cap( Capabilities::MANAGE_SETTINGS ) ) {
			Capabilities::grant();
		}
	}

	public function ensure_cleanup_schedule(): void {
		if ( wp_next_scheduled( 'convocart_cleanup' ) && 'hourly' !== wp_get_schedule( 'convocart_cleanup' ) ) { wp_clear_scheduled_hook( 'convocart_cleanup' ); }
		if ( ! wp_next_scheduled( 'convocart_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'convocart_cleanup' );
		}
	}

	private function register_upgrades(): void {
		add_action(
			'init',
			function (): void {
				$current = (int) get_option( 'convocart_schema_version', 0 );
				if ( $current < Schema::VERSION ) {
					$migration = Schema::migrate();
					if ( empty( $migration['success'] ) ) { return; }
				}
				$old_settings_version = (int) get_option( Settings::VERSION_OPTION, 0 );
				$migration = Settings::migrate();
				if ( empty( $migration['success'] ) ) { return; }
				if ( $old_settings_version < 4 ) {
					\ConvoCart\Assistant\Jobs\ScheduleManager::clear_plugin_events();
					$this->ensure_cleanup_schedule();
					update_option( 'convocart_initial_sync', 'pending', false );
					global $wpdb;
					$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='failed',last_error='superseded_by_v2_scheduler' WHERE status IN ('queued','running','retrying')", Schema::table( 'sync_jobs' ) ) );
				}
				if ( 'pending' === get_option( 'convocart_initial_sync' ) && $this->container ) {
					try {
						$knowledge = $this->container->get( KnowledgeManager::class );
						if ( $knowledge ) {
							$knowledge->queue_full_sync();
							update_option( 'convocart_initial_sync', 'started', false );
						}
					} catch ( \Throwable $e ) {
						// Fallback silently if lock is held or fails
					}
				}
			}
		);
	}

	public function migration_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$migration = get_option( Settings::MIGRATION_OPTION, array() );
		if ( ! empty( $migration['groq_model'] ) || ( ! empty( $migration['gemini_model'] ) && 'unchanged' !== $migration['gemini_model'] ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Nexora Shopping Assistant updated legacy model defaults. Review Providers and run the shopping-response test before enabling the assistant.', 'nexora-shopping-assistant' ) . '</p></div>';
		}
		$status = sanitize_key( (string) get_option( 'convocart_migration_status', '' ) );
		$error  = sanitize_key( (string) get_option( 'convocart_migration_error', '' ) );
		if ( '' === $status || 'success' === $status ) {
			return;
		}
		$message = 'running' === $status ? __( 'Nexora Shopping Assistant database migration is running or waiting for a lock.', 'nexora-shopping-assistant' ) : __( 'Nexora Shopping Assistant database migration needs attention. Reload after the issue is resolved to retry.', 'nexora-shopping-assistant' );
		if ( '' !== $error ) {
			/* translators: %s: migration status code. */
			$message .= ' ' . sprintf( __( 'Status code: %s.', 'nexora-shopping-assistant' ), $error );
		}
		echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
	}
}
