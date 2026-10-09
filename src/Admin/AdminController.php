<?php
namespace ConvoCart\Assistant\Admin;

use ConvoCart\Assistant\AI\Encryption\EncryptionService;
use ConvoCart\Assistant\AI\ProviderManager;
use ConvoCart\Assistant\Analytics\AnalyticsService;
use ConvoCart\Assistant\Container;
use ConvoCart\Assistant\Feedback\FeedbackService;
use ConvoCart\Assistant\Knowledge\KnowledgeManager;
use ConvoCart\Assistant\Knowledge\StoreContext;
use ConvoCart\Assistant\Security\Capabilities;
use ConvoCart\Assistant\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class AdminController {
	public function __construct( private Container $container ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_convocart_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_convocart_save_provider_settings', array( $this, 'save_provider_settings' ) );
		add_action( 'admin_post_convocart_save_key', array( $this, 'save_key' ) );
		add_action( 'admin_post_convocart_remove_key', array( $this, 'remove_key' ) );
		add_action( 'admin_post_convocart_sync', array( $this, 'sync' ) );
		add_action( 'admin_post_convocart_analyze', array( $this, 'run_analyze' ) );
		add_action( 'admin_post_convocart_clear_context', array( $this, 'clear_context' ) );
		add_action( 'admin_post_convocart_save_system_prompt', array( $this, 'save_system_prompt' ) );
		add_action( 'admin_post_convocart_save_feedback_settings', array( $this, 'save_feedback_settings' ) );
		add_action( 'admin_post_convocart_clear_feedback', array( $this, 'clear_feedback' ) );
	}

	public function menu(): void {
		// The admin page slugs keep their historical "convocart" values so existing
		// bookmarks and links keep working.
		add_menu_page( __( 'Nexora Shopping Assistant', 'nexora-shopping-assistant' ), __( 'Nexora Assistant', 'nexora-shopping-assistant' ), Capabilities::MANAGE_SETTINGS, 'convocart', array( $this, 'dashboard' ), 'dashicons-format-chat', 58 );
		add_submenu_page( 'convocart', __( 'Settings & Styling', 'nexora-shopping-assistant' ), __( 'Settings & Styling', 'nexora-shopping-assistant' ), Capabilities::MANAGE_SETTINGS, 'convocart', array( $this, 'dashboard' ) );
		add_submenu_page( 'convocart', __( 'Providers', 'nexora-shopping-assistant' ), __( 'Providers', 'nexora-shopping-assistant' ), Capabilities::MANAGE_PROVIDERS, 'convocart-providers', array( $this, 'providers' ) );
		add_submenu_page( 'convocart', __( 'Knowledge Base', 'nexora-shopping-assistant' ), __( 'Knowledge Base', 'nexora-shopping-assistant' ), Capabilities::MANAGE_KNOWLEDGE, 'convocart-knowledge', array( $this, 'knowledge' ) );
		add_submenu_page( 'convocart', __( 'Analyze', 'nexora-shopping-assistant' ), __( 'Analyze', 'nexora-shopping-assistant' ), Capabilities::MANAGE_SETTINGS, 'convocart-analyze', array( $this, 'analyze_page' ) );
		add_submenu_page( 'convocart', __( 'Shortcode Guide', 'nexora-shopping-assistant' ), __( 'Shortcode Guide', 'nexora-shopping-assistant' ), Capabilities::MANAGE_SETTINGS, 'convocart-shortcode', array( $this, 'shortcode_guide' ) );
		add_submenu_page( 'convocart', __( 'System Status', 'nexora-shopping-assistant' ), __( 'System Status', 'nexora-shopping-assistant' ), Capabilities::MANAGE_SETTINGS, 'convocart-status', array( $this, 'status' ) );
		add_submenu_page( 'convocart', __( 'Analytics', 'nexora-shopping-assistant' ), __( 'Analytics', 'nexora-shopping-assistant' ), Capabilities::VIEW_ANALYTICS, 'convocart-analytics', array( $this, 'analytics' ) );
		add_submenu_page( 'convocart', __( 'Feedback', 'nexora-shopping-assistant' ), __( 'Feedback', 'nexora-shopping-assistant' ), Capabilities::VIEW_ANALYTICS, 'convocart-feedback', array( $this, 'feedback_page' ) );
	}

	public function settings(): void {}

	public function assets( string $hook ): void {
		if ( false === strpos( $hook, 'convocart' ) ) {
			return;
		}
		wp_enqueue_style( 'convocart-admin', CONVOCART_URL . 'assets/admin/css/admin.css', array(), CONVOCART_VERSION );
		wp_enqueue_script( 'convocart-admin', CONVOCART_URL . 'assets/admin/js/admin.js', array( 'jquery', 'wp-color-picker' ), CONVOCART_VERSION, true );
		wp_localize_script(
			'convocart-admin',
			'ConvoCartAdminConfig',
			array(
				'restBase' => esc_url_raw( rest_url( 'convocart/v1' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'strings'  => array(
					'syncing'        => __( 'Syncing…', 'nexora-shopping-assistant' ),
					'saveBeforeTest' => ' ' . __( 'Save the model selection before testing.', 'nexora-shopping-assistant' ),
					'testing'        => __( 'Testing…', 'nexora-shopping-assistant' ),
					'verified'       => __( 'Shopping response verified.', 'nexora-shopping-assistant' ),
					'notConnected'   => __( 'Not connected.', 'nexora-shopping-assistant' ),
					'modelLabel'     => ' ' . __( 'Model:', 'nexora-shopping-assistant' ) . ' ',
					'requestFailed'  => __( 'Request failed.', 'nexora-shopping-assistant' ),
					'chooseModel'    => __( 'Choose an available model', 'nexora-shopping-assistant' ),
					'noModels'       => __( 'No verified available models', 'nexora-shopping-assistant' ),
					'modelsLoaded'   => __( 'Models loaded.', 'nexora-shopping-assistant' ),
					'lastSynced'     => ' ' . __( 'Last synchronized:', 'nexora-shopping-assistant' ) . ' ',
					'savedModel'     => ' ' . __( 'Saved model:', 'nexora-shopping-assistant' ) . ' ',
					'typedNotListed' => ' ' . __( 'The typed ID is not in the available list.', 'nexora-shopping-assistant' ),
					'chooseFromList' => __( 'Choose a model from the synchronized available list.', 'nexora-shopping-assistant' ),
					'loadingModels'  => __( 'Loading models…', 'nexora-shopping-assistant' ),
					'couldNotLoad'   => __( 'Could not load models.', 'nexora-shopping-assistant' ),
					'mediaTitle'     => __( 'Select or upload an image', 'nexora-shopping-assistant' ),
					'mediaButton'    => __( 'Use this image', 'nexora-shopping-assistant' ),
				),
			)
		);
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_media();
	}

	public function dashboard(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to access these settings.', 'nexora-shopping-assistant' ) );
		}
		$settings = Settings::all();
		$brand    = trim( (string) ( $settings['assistant_name'] ?? '' ) );
		if ( '' === $brand ) {
			$brand = __( 'Nexora Shopping Assistant', 'nexora-shopping-assistant' );
		}
		?>
		<div class="wrap convocart-admin">
			<h1><?php echo esc_html( $brand ); ?></h1>
			<p class="convocart-admin-subtitle"><?php esc_html_e( 'AI-powered shopping assistant for your store', 'nexora-shopping-assistant' ); ?></p>
			<?php settings_errors(); ?>
			<?php $this->settings_notice(); ?>
			<?php if ( ! $settings['enabled'] ) : ?>
				<p><?php esc_html_e( 'The storefront assistant is off. Configure and test a provider in Providers, review your catalogue and fallback choices, then turn on Enable assistant below.', 'nexora-shopping-assistant' ); ?></p>
			<?php endif; ?>
			<div class="convocart-admin-grid">
				<div class="convocart-admin-card">
					<h2><?php esc_html_e( 'General Settings', 'nexora-shopping-assistant' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="convocart_save_settings" />
						<?php wp_nonce_field( 'convocart_save_settings_general' ); ?>
						<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[_convocart_section]" value="general" />
						<table class="form-table" role="presentation">
							<tr><th scope="row"><label for="convocart-global-enabled"><?php esc_html_e( 'Enable assistant', 'nexora-shopping-assistant' ); ?></label></th><td><input id="convocart-global-enabled" type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[enabled]" value="1" <?php checked( $settings['enabled'], true ); ?> /></td></tr>
							<tr><th scope="row"><label for="convocart-enabled"><?php esc_html_e( 'Enable assistant frontend', 'nexora-shopping-assistant' ); ?></label></th><td><input id="convocart-enabled" type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[frontend_enabled]" value="1" <?php checked( $settings['frontend_enabled'], true ); ?> /></td></tr>
							<tr><th scope="row"><label for="convocart-assistant-name"><?php esc_html_e( 'Assistant name', 'nexora-shopping-assistant' ); ?></label></th><td><input class="regular-text" id="convocart-assistant-name" type="text" name="<?php echo esc_attr( Settings::OPTION ); ?>[assistant_name]" value="<?php echo esc_attr( $settings['assistant_name'] ); ?>" /></td></tr>
							<tr><th scope="row"><label for="convocart-welcome"><?php esc_html_e( 'Welcome message', 'nexora-shopping-assistant' ); ?></label></th><td><textarea class="large-text" id="convocart-welcome" name="<?php echo esc_attr( Settings::OPTION ); ?>[welcome_message]" rows="3"><?php echo esc_textarea( $settings['welcome_message'] ); ?></textarea></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Feature controls', 'nexora-shopping-assistant' ); ?></th><td><?php $this->checkbox( $settings, 'launcher_enabled', __( 'Floating launcher', 'nexora-shopping-assistant' ) ); ?><br /><?php $this->checkbox( $settings, 'hash_trigger_enabled', __( 'Hash trigger', 'nexora-shopping-assistant' ) ); ?><br /><?php $this->checkbox( $settings, 'shortcode_enabled', __( 'Shortcode', 'nexora-shopping-assistant' ) ); ?><br /><?php $this->checkbox( $settings, 'add_to_cart_enabled', __( 'Verified Add to Cart', 'nexora-shopping-assistant' ) ); ?></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Privacy defaults', 'nexora-shopping-assistant' ); ?></th><td><?php $this->checkbox( $settings, 'conversation_logging', __( 'Store conversation transcripts', 'nexora-shopping-assistant' ) ); ?><br /><?php $this->checkbox( $settings, 'logged_in_logging', __( 'Link transcripts to logged-in accounts', 'nexora-shopping-assistant' ) ); ?><br /><?php $this->checkbox( $settings, 'anonymous_analytics', __( 'Store anonymous aggregate analytics', 'nexora-shopping-assistant' ) ); ?><br /><?php $this->checkbox( $settings, 'logged_in_analytics', __( 'Link analytics to logged-in accounts', 'nexora-shopping-assistant' ) ); ?><br /><?php $this->checkbox( $settings, 'preserve_data_on_uninstall', __( 'Preserve data on uninstall', 'nexora-shopping-assistant' ) ); ?></td></tr>
							<?php foreach ( array( 'conversation_retention' => array( __( 'Transcript / feedback retention (days)', 'nexora-shopping-assistant' ), 1, 3650 ), 'analytics_retention' => array( __( 'Analytics retention (days)', 'nexora-shopping-assistant' ), 1, 3650 ), 'max_conversation_messages' => array( __( 'Retained messages per conversation', 'nexora-shopping-assistant' ), 5, 100 ), 'max_message_length' => array( __( 'Maximum message length (UTF-8 bytes)', 'nexora-shopping-assistant' ), 100, 10000 ), 'max_recommendations' => array( __( 'Maximum product cards (AI returns up to 3)', 'nexora-shopping-assistant' ), 1, 20 ), 'provider_timeout' => array( __( 'Total AI response timeout (seconds)', 'nexora-shopping-assistant' ), 5, 60 ), 'provider_retries' => array( __( 'Retries per provider', 'nexora-shopping-assistant' ), 0, 2 ) ) as $key => $field ) : ?>
							<tr><th><label for="<?php echo esc_attr( 'convocart-' . $key ); ?>"><?php echo esc_html( $field[0] ); ?></label></th><td><input type="number" id="<?php echo esc_attr( 'convocart-' . $key ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" min="<?php echo esc_attr( (string) $field[1] ); ?>" max="<?php echo esc_attr( (string) $field[2] ); ?>" value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>" /></td></tr>
							<?php endforeach; ?>
							<tr><th><?php esc_html_e( 'Failure message', 'nexora-shopping-assistant' ); ?></th><td><textarea name="<?php echo esc_attr( Settings::OPTION ); ?>[fallback_message]" class="large-text"><?php echo esc_textarea( $settings['fallback_message'] ); ?></textarea></td></tr>
							<tr><th><?php esc_html_e( 'Customer privacy notice', 'nexora-shopping-assistant' ); ?></th><td><textarea name="<?php echo esc_attr( Settings::OPTION ); ?>[privacy_notice]" class="large-text"><?php echo esc_textarea( $settings['privacy_notice'] ); ?></textarea><p class="description"><?php esc_html_e( 'Temporary memory expires after 24 hours of inactivity even when retained logging is off. Feedback includes the latest exchange.', 'nexora-shopping-assistant' ); ?></p></td></tr>
						</table>
						<?php submit_button( __( 'Save General Settings', 'nexora-shopping-assistant' ) ); ?>
					</form>
				</div>
				<div class="convocart-admin-card">
					<h2><?php esc_html_e( 'Styling Options', 'nexora-shopping-assistant' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="convocart_save_settings" />
						<?php wp_nonce_field( 'convocart_save_settings_styling' ); ?>
						<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[_convocart_section]" value="styling" />
						<table class="form-table" role="presentation">
							<tr><th scope="row"><label for="convocart-primary-color"><?php esc_html_e( 'Primary color', 'nexora-shopping-assistant' ); ?></label></th><td><input id="convocart-primary-color" type="text" class="convocart-color-picker" name="<?php echo esc_attr( Settings::OPTION ); ?>[primary_color]" value="<?php echo esc_attr( $settings['primary_color'] ); ?>" data-default-color="#2563eb" /></td></tr>
							<tr><th scope="row"><label for="convocart-secondary-color"><?php esc_html_e( 'Secondary color', 'nexora-shopping-assistant' ); ?></label></th><td><input id="convocart-secondary-color" type="text" class="convocart-color-picker" name="<?php echo esc_attr( Settings::OPTION ); ?>[secondary_color]" value="<?php echo esc_attr( $settings['secondary_color'] ); ?>" data-default-color="#1e293b" /></td></tr>
							<tr><th scope="row"><label for="convocart-font-family"><?php esc_html_e( 'Body font', 'nexora-shopping-assistant' ); ?></label></th><td><input class="regular-text" id="convocart-font-family" type="text" name="<?php echo esc_attr( Settings::OPTION ); ?>[font_family]" value="<?php echo esc_attr( $settings['font_family'] ); ?>" placeholder="'Inter', sans-serif" /></td></tr>
							<tr><th scope="row"><label for="convocart-heading-font"><?php esc_html_e( 'Heading font', 'nexora-shopping-assistant' ); ?></label></th><td><input class="regular-text" id="convocart-heading-font" type="text" name="<?php echo esc_attr( Settings::OPTION ); ?>[heading_font_family]" value="<?php echo esc_attr( $settings['heading_font_family'] ?? "'Inter', sans-serif" ); ?>" placeholder="'Inter', sans-serif" /></td></tr>
							<?php $this->media_picker( 'brand_logo_id', __( 'Brand logo / icon', 'nexora-shopping-assistant' ), (int) ( $settings['brand_logo_id'] ?? 0 ), __( 'Shown in the assistant header and message avatars. Square images work best.', 'nexora-shopping-assistant' ) ); ?>
							<?php $this->media_picker( 'chef_avatar_id', __( 'Assistant avatar', 'nexora-shopping-assistant' ), (int) ( $settings['chef_avatar_id'] ?? 0 ), __( 'Shown on the feedback prompt and the thank-you screen.', 'nexora-shopping-assistant' ) ); ?>
						</table>
						<p class="description"><?php esc_html_e( 'These colors are applied to the frontend assistant popup. Save and refresh the frontend to see changes.', 'nexora-shopping-assistant' ); ?></p>
						<?php submit_button( __( 'Save Styling', 'nexora-shopping-assistant' ) ); ?>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	public function shortcode_guide(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'nexora-shopping-assistant' ) );
		}
		?>
		<div class="wrap convocart-admin">
			<h1><?php esc_html_e( 'Shortcode & Integration Guide', 'nexora-shopping-assistant' ); ?></h1>
			<div class="convocart-admin-grid">
				<div class="convocart-admin-card">
					<h2><?php esc_html_e( 'Basic Shortcode', 'nexora-shopping-assistant' ); ?></h2>
					<p><?php esc_html_e( 'Place the assistant anywhere on your site with the basic shortcode:', 'nexora-shopping-assistant' ); ?></p>
					<pre class="convocart-code-block">[nexora_shopping_assistant]</pre>
					<p class="description"><?php esc_html_e( 'The earlier [convocart] shortcode still works.', 'nexora-shopping-assistant' ); ?></p>
				</div>
				<div class="convocart-admin-card">
					<h2><?php esc_html_e( 'Multiple Trigger Buttons', 'nexora-shopping-assistant' ); ?></h2>
					<p><?php esc_html_e( 'Multiple buttons open the same assistant popup. These are not independent inline conversations:', 'nexora-shopping-assistant' ); ?></p>
					<pre class="convocart-code-block">[nexora_shopping_assistant id="menu-page"]

[nexora_shopping_assistant id="footer"]</pre>
				</div>
				<div class="convocart-admin-card">
					<h2><?php esc_html_e( 'PHP Template Usage', 'nexora-shopping-assistant' ); ?></h2>
					<p><?php esc_html_e( 'Use the shortcode directly in theme template files:', 'nexora-shopping-assistant' ); ?></p>
					<pre class="convocart-code-block">&lt;?php echo do_shortcode( '[nexora_shopping_assistant]' ); ?&gt;</pre>
				</div>
				<div class="convocart-admin-card">
					<h2><?php esc_html_e( 'Hash Trigger', 'nexora-shopping-assistant' ); ?></h2>
					<p><?php esc_html_e( 'Link directly to the assistant with a hash URL:', 'nexora-shopping-assistant' ); ?></p>
					<pre class="convocart-code-block">&lt;a href="#nexora-shopping-assistant"&gt;Ask the assistant&lt;/a&gt;</pre>
				</div>
				<div class="convocart-admin-card">
					<h2><?php esc_html_e( 'JavaScript API', 'nexora-shopping-assistant' ); ?></h2>
					<p><?php esc_html_e( 'Open/programmatically control from custom JavaScript:', 'nexora-shopping-assistant' ); ?></p>
					<pre class="convocart-code-block">&lt;script&gt;
// Open the assistant
window.NexoraShoppingAssistant.open();

// Close the assistant
window.NexoraShoppingAssistant.close();

// Restart conversation
window.NexoraShoppingAssistant.restart();
&lt;/script&gt;</pre>
				</div>
				<div class="convocart-admin-card">
					<h2><?php esc_html_e( 'JavaScript Event', 'nexora-shopping-assistant' ); ?></h2>
					<p><?php esc_html_e( 'Or use the custom event:', 'nexora-shopping-assistant' ); ?></p>
					<pre class="convocart-code-block">window.dispatchEvent(
	new CustomEvent('convocart:open')
);</pre>
				</div>
				<div class="convocart-admin-card">
					<h2><?php esc_html_e( 'Elementor / Page Builder', 'nexora-shopping-assistant' ); ?></h2>
					<p><?php esc_html_e( 'Use a Shortcode widget for [nexora_shopping_assistant], or a custom HTML widget for JavaScript.', 'nexora-shopping-assistant' ); ?></p>
				</div>
				<div class="convocart-admin-card">
					<h2><?php esc_html_e( 'WooCommerce Integration', 'nexora-shopping-assistant' ); ?></h2>
					<p><?php esc_html_e( 'The assistant automatically indexes published WooCommerce products. Customers can search, view, and add products to cart directly from the popup without leaving the page.', 'nexora-shopping-assistant' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	public function providers(): void {
		if ( ! current_user_can( Capabilities::MANAGE_PROVIDERS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage providers.', 'nexora-shopping-assistant' ) );
		}
		$encryption = new EncryptionService();
		$settings   = Settings::all();
		$providers  = array(
			'groq'   => 'Groq',
			'gemini' => 'Google Gemini',
			'openai' => 'OpenAI',
		);
		$gemini_status = Settings::provider_model_status( 'gemini' );
		?>
		<div class="wrap convocart-admin"><h1><?php esc_html_e( 'AI Providers', 'nexora-shopping-assistant' ); ?></h1>
		<?php $this->provider_notice(); ?>
		<p><?php esc_html_e( 'Model inventories are checked automatically in the background. Only compatible models currently listed by your provider and not past their shutdown date can be selected.', 'nexora-shopping-assistant' ); ?></p>
		<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'WP-Cron is disabled. Configure your server cron runner for automatic model checks; this page can also refresh models on demand.', 'nexora-shopping-assistant' ); ?></p></div>
		<?php endif; ?>
		<div class="convocart-admin-grid">
			<div class="convocart-admin-card">
				<h2><?php esc_html_e( 'Provider Order', 'nexora-shopping-assistant' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'convocart_save_provider_settings_provider_order' ); ?><input type="hidden" name="action" value="convocart_save_provider_settings" /><input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[_convocart_section]" value="provider_order" /><table class="form-table"><tr><th><?php esc_html_e( 'Primary provider', 'nexora-shopping-assistant' ); ?></th><td><select name="<?php echo esc_attr( Settings::OPTION ); ?>[primary_provider]">
				<?php
				foreach ( $providers as $id => $name ) :
					?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['primary_provider'], $id ); ?>><?php echo esc_html( $name ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Fallback providers are tried after the selected primary provider.', 'nexora-shopping-assistant' ); ?></p></td></tr>
				<tr><th><?php esc_html_e( 'Fallback order', 'nexora-shopping-assistant' ); ?></th><td>
				<?php $fallback_order = array_values( array_diff( (array) $settings['provider_order'], array( $settings['primary_provider'] ) ) ); ?>
				<?php for ( $position = 0; $position < 2; $position++ ) : ?>
					<label><?php echo esc_html( sprintf( /* translators: %d: fallback position number (1 or 2). */ __( 'Fallback %d', 'nexora-shopping-assistant' ), $position + 1 ) ); ?> <select name="<?php echo esc_attr( Settings::OPTION ); ?>[fallback_order][]"><option value=""><?php esc_html_e( 'None', 'nexora-shopping-assistant' ); ?></option>
					<?php foreach ( $providers as $id => $name ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $fallback_order[ $position ] ?? '', $id ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
					</select></label><br />
				<?php endfor; ?>
				<p class="description"><?php echo esc_html( implode( ' -> ', array_map( static fn( string $id ): string => $providers[ $id ] ?? $id, (array) $settings['provider_order'] ) ) ); ?></p></td></tr></table><?php submit_button( __( 'Save provider order', 'nexora-shopping-assistant' ) ); ?></form>
			</div>
			<div class="convocart-admin-card convocart-provider-models">
				<h2><?php esc_html_e( 'Provider Models', 'nexora-shopping-assistant' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Available models load automatically after saving a key and refresh before the six-hour inventory expires. Saved selections are never silently replaced. Preview and upcoming shutdown dates are shown explicitly.', 'nexora-shopping-assistant' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'convocart_save_provider_settings_provider_models' ); ?><input type="hidden" name="action" value="convocart_save_provider_settings" /><input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[_convocart_section]" value="provider_models" />
					<?php foreach ( $providers as $id => $name ) : ?>
						<?php $model_value = sanitize_text_field( (string) ( $settings['provider_models'][ $id ] ?? '' ) ); ?>
						<?php $model_status = Settings::provider_model_status( $id ); ?>
						<div class="convocart-model-field" data-provider="<?php echo esc_attr( $id ); ?>">
							<label for="convocart-model-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( sprintf( /* translators: %s: AI provider name, for example OpenAI. */ __( '%s model', 'nexora-shopping-assistant' ), $name ) ); ?></label>
							<select id="convocart-model-<?php echo esc_attr( $id ); ?>" class="convocart-model-select regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[provider_models][<?php echo esc_attr( $id ); ?>]" data-selected="<?php echo esc_attr( $model_value ); ?>" disabled>
								<option value="" selected disabled><?php esc_html_e( 'Loading available models…', 'nexora-shopping-assistant' ); ?></option>
							</select>
							<button type="button" class="button convocart-refresh-models" data-provider="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Refresh models', 'nexora-shopping-assistant' ); ?></button>
							<p class="description convocart-model-status" aria-live="polite"><?php echo esc_html( $model_status['message'] ); ?></p>
							<p class="description convocart-saved-model"><?php echo esc_html( sprintf( /* translators: %s: saved model identifier. */ __( 'Saved model: %s', 'nexora-shopping-assistant' ), $model_value ) ); ?></p>
							<details><summary><?php esc_html_e( 'Find an available model by ID', 'nexora-shopping-assistant' ); ?></summary><input class="regular-text convocart-model-custom" type="text" value="" autocomplete="off" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: AI provider name, for example OpenAI. */ __( 'Find available %s model ID', 'nexora-shopping-assistant' ), $name ) ); ?>" /><p class="description"><?php esc_html_e( 'An ID must be in the synchronized model list. Retired, missing and incompatible IDs cannot be saved.', 'nexora-shopping-assistant' ); ?></p></details>
						</div>
					<?php endforeach; ?>
				<?php submit_button( __( 'Save provider models', 'nexora-shopping-assistant' ) ); ?></form>
			</div>
			<div class="convocart-admin-card">
				<h2><?php esc_html_e( 'API Keys', 'nexora-shopping-assistant' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Use Remove Key to delete credentials. Deactivation preserves settings and keys.', 'nexora-shopping-assistant' ); ?></p>
				<?php
				$enc_class = 'convocart-status-connected';
				$enc_text  = __( 'Dedicated encryption key active', 'nexora-shopping-assistant' );
				if ( $encryption->key_changed() ) {
					$enc_class = 'convocart-status-error';
					$enc_text  = __( 'Encryption key changed — stored keys unreadable, re-enter below', 'nexora-shopping-assistant' );
				} elseif ( $encryption->using_fallback_key() ) {
					$enc_class = 'convocart-status-warning';
					$enc_text  = __( 'Using WordPress salt fallback — define CONVOCART_ENCRYPTION_KEY before production', 'nexora-shopping-assistant' );
				}
				?>
				<p><span class="convocart-sync-badge <?php echo esc_attr( $enc_class ); ?>"><span class="convocart-status-indicator"></span><?php echo esc_html( $enc_text ); ?></span></p>
				<?php
				if ( '' === $encryption->get( 'groq' ) && '' === $encryption->get( 'gemini' ) && '' === $encryption->get( 'openai' ) ) :
					?>
					<div class="convocart-admin-notice"><p><strong><?php esc_html_e( 'No providers configured.', 'nexora-shopping-assistant' ); ?></strong> <?php esc_html_e( 'Enter at least one API key below to start using the assistant.', 'nexora-shopping-assistant' ); ?></p></div>
				<?php endif; ?>
				<?php foreach ( $providers as $id => $name ) : ?>
					<?php $configured = '' !== $encryption->get( $id ); ?>
					<?php 
						$last_test = get_transient( 'convocart_test_' . $id );
						$verified = $configured && Settings::provider_model_is_available( $id ) && is_array( $last_test ) && ! empty( $last_test['connected'] ) && ( $last_test['model'] ?? '' ) === Settings::provider_model( $id );
						$status_class = $verified ? 'convocart-status-connected' : 'convocart-status-warning';
						$status_text  = $verified ? __( 'Test passed recently', 'nexora-shopping-assistant' ) : ( $configured ? __( 'Key stored — test required', 'nexora-shopping-assistant' ) : __( 'Not Configured', 'nexora-shopping-assistant' ) );
					?>
					<div class="convocart-provider-box">
						<div class="convocart-provider-header">
							<h3><?php echo esc_html( $name ); ?></h3>
							<span class="convocart-provider-status <?php echo esc_attr( $status_class ); ?>">
								<span class="convocart-status-indicator"></span><?php echo esc_html( $status_text ); ?>
							</span>
						</div>
						
						<?php if ( $configured ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-right: 15px;">
								<input type="hidden" name="action" value="convocart_remove_key" />
								<input type="hidden" name="provider" value="<?php echo esc_attr( $id ); ?>" />
								<?php wp_nonce_field( 'convocart_remove_key_' . $id ); ?>
								<p style="margin-bottom: 15px; color: var(--convocart-admin-light-text);"><?php esc_html_e( 'Key is configured and securely encrypted.', 'nexora-shopping-assistant' ); ?></p>
								<button type="submit" name="delete" class="button-secondary"><?php echo esc_html( sprintf( /* translators: %s: AI provider name, for example OpenAI. */ __( 'Remove %s Key', 'nexora-shopping-assistant' ), $name ) ); ?></button>
							</form>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-top: <?php echo $configured ? '15px' : '0'; ?>;">
							<input type="hidden" name="action" value="convocart_save_key" />
							<input type="hidden" name="provider" value="<?php echo esc_attr( $id ); ?>" />
							<?php wp_nonce_field( 'convocart_save_key_' . $id ); ?>
							<div style="display: flex; gap: 10px; align-items: center; margin-bottom: 15px;">
								<input type="password" name="api_key" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Enter API key here...', 'nexora-shopping-assistant' ); ?>" style="min-width: 300px;" />
								<button type="submit" class="button-primary"><?php echo esc_html( $configured ? __( 'Update Key', 'nexora-shopping-assistant' ) : __( 'Save Key', 'nexora-shopping-assistant' ) ); ?></button>
							</div>
						</form>
						<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--convocart-admin-border);">
							<button type="button" class="button convocart-test-provider" data-provider="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Test connection', 'nexora-shopping-assistant' ); ?></button>
							<span class="convocart-test-result" aria-live="polite" style="margin-left: 10px; font-weight: 600;"></span>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			</div>
			<?php
			if ( '' === $encryption->get( 'groq' ) && '' === $encryption->get( 'gemini' ) && '' === $encryption->get( 'openai' ) ) :
				?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'No provider API key is configured yet. Configure at least one provider before using the assistant.', 'nexora-shopping-assistant' ); ?></p></div>
				<?php
			endif;
			if ( $encryption->using_fallback_key() ) :
				?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'CONVOCART_ENCRYPTION_KEY is not defined. WordPress salts are being used as the encryption fallback. Define a dedicated key before production.', 'nexora-shopping-assistant' ); ?></p></div><?php endif; ?>
		<?php
		if ( $encryption->key_changed() ) :
			?>
			<div class="notice notice-error"><p><?php esc_html_e( 'The encryption key changed. Existing provider keys are unavailable and must be entered again.', 'nexora-shopping-assistant' ); ?></p></div><?php endif; ?>
		</div>
		<?php
	}

	public function save_settings(): void {
		$payload = $this->posted_settings_payload();
		$section = sanitize_key( (string) ( $payload['_convocart_section'] ?? '' ) );
		if ( ! in_array( $section, array( 'general', 'styling' ), true ) ) {
			wp_die( esc_html__( 'Invalid settings section.', 'nexora-shopping-assistant' ) );
		}
		if ( ! wp_verify_nonce( $this->posted_nonce(), 'convocart_save_settings_' . $section ) ) {
			wp_die( esc_html__( 'The request could not be verified. Please refresh and try again.', 'nexora-shopping-assistant' ) );
		}
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'Permission denied.', 'nexora-shopping-assistant' ) );
		}
		$this->save_settings_section( $section, $payload );
		$this->redirect_settings( 'settings_saved' );
	}

	public function save_provider_settings(): void {
		$payload = $this->posted_settings_payload();
		$section = sanitize_key( (string) ( $payload['_convocart_section'] ?? '' ) );
		if ( ! in_array( $section, array( 'provider_order', 'provider_models' ), true ) ) {
			wp_die( esc_html__( 'Invalid provider settings section.', 'nexora-shopping-assistant' ) );
		}
		if ( ! wp_verify_nonce( $this->posted_nonce(), 'convocart_save_provider_settings_' . $section ) ) {
			wp_die( esc_html__( 'The request could not be verified. Please refresh and try again.', 'nexora-shopping-assistant' ) );
		}
		if ( ! current_user_can( Capabilities::MANAGE_PROVIDERS ) ) {
			wp_die( esc_html__( 'Permission denied.', 'nexora-shopping-assistant' ) );
		}
		try {
			$this->save_settings_section( $section, $payload );
			foreach ( array( 'groq', 'gemini', 'openai' ) as $id ) { delete_transient( 'convocart_test_' . $id ); }
		} catch ( \InvalidArgumentException $error ) {
			wp_die( esc_html( $error->getMessage() ), esc_html__( 'Model not saved', 'nexora-shopping-assistant' ), array( 'back_link' => true, 'response' => 400 ) );
		}
		$this->redirect_provider( 'settings_saved' );
	}

	public function knowledge(): void {
		if ( ! current_user_can( Capabilities::MANAGE_KNOWLEDGE ) ) {
			wp_die( esc_html__( 'You do not have permission to manage knowledge.', 'nexora-shopping-assistant' ) );
		}
		$status   = $this->container->get( KnowledgeManager::class )->status();
		$job      = is_array( $status['last_job'] ?? null ) ? $status['last_job'] : array();
		$state    = sanitize_key( (string) ( $status['completion_status'] ?? '' ) );
		$total    = absint( $status['total_items'] ?? 0 );
		$done     = absint( $status['processed_items'] ?? 0 );
		$failed   = absint( $status['failed_items'] ?? 0 );
		$perm     = absint( $status['permanent_failed_ids'] ?? 0 );
		$retries  = is_array( $status['retry_counts'] ?? null ) ? $status['retry_counts'] : array();
		$percent  = $total > 0 ? min( 100, (int) round( ( $done / $total ) * 100 ) ) : ( 'completed' === $state ? 100 : 0 );
		$active   = in_array( $state, array( 'queued', 'running', 'retrying' ), true );
		$updated  = sanitize_text_field( (string) ( $job['updated_at'] ?? '' ) );
		$finished = sanitize_text_field( (string) ( $job['completed_at'] ?? '' ) );
		$badge    = $this->sync_state_badge( $state );
		?>
		<div class="wrap convocart-admin">
			<h1><?php esc_html_e( 'Knowledge Base', 'nexora-shopping-assistant' ); ?></h1>
			<?php $this->knowledge_notice(); ?>
			<div class="convocart-metrics-grid">
				<div class="convocart-metric-card"><strong><?php echo esc_html( number_format_i18n( absint( $status['indexed_products'] ?? 0 ) ) ); ?></strong><span><?php esc_html_e( 'Products indexed', 'nexora-shopping-assistant' ); ?></span></div>
				<div class="convocart-metric-card"><strong><?php echo esc_html( number_format_i18n( $done ) ); ?></strong><span><?php esc_html_e( 'Processed (last job)', 'nexora-shopping-assistant' ); ?></span></div>
				<div class="convocart-metric-card"><strong><?php echo esc_html( number_format_i18n( $failed ) ); ?></strong><span><?php esc_html_e( 'Failed (retrying)', 'nexora-shopping-assistant' ); ?></span></div>
				<div class="convocart-metric-card"><strong><?php echo esc_html( number_format_i18n( $perm ) ); ?></strong><span><?php esc_html_e( 'Permanently failed', 'nexora-shopping-assistant' ); ?></span></div>
			</div>
			<div class="convocart-admin-grid">
				<div class="convocart-admin-card">
					<h2><?php esc_html_e( 'Sync Controls', 'nexora-shopping-assistant' ); ?></h2>
					<p><?php esc_html_e( 'Synchronize published WooCommerce products into the controlled recommendation index. Live product data is revalidated before every display and cart action.', 'nexora-shopping-assistant' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="convocart-sync-form">
						<input type="hidden" name="action" value="convocart_sync" />
						<?php wp_nonce_field( 'convocart_sync' ); ?>
						<?php submit_button( __( 'Queue product synchronization', 'nexora-shopping-assistant' ), 'primary', 'submit', true, $active ? array( 'disabled' => 'disabled' ) : array() ); ?>
					</form>
					<?php if ( $active ) : ?>
						<p class="description"><?php esc_html_e( 'A synchronization is already running. This page reflects its last recorded progress; reload to refresh.', 'nexora-shopping-assistant' ); ?></p>
					<?php endif; ?>
				</div>
				<div class="convocart-admin-card">
					<h2><?php esc_html_e( 'Sync Status', 'nexora-shopping-assistant' ); ?></h2>
					<p>
						<span class="convocart-sync-badge <?php echo esc_attr( $badge['class'] ); ?>"><span class="convocart-status-indicator"></span><?php echo esc_html( $badge['label'] ); ?></span>
					</p>
					<div class="convocart-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $percent ); ?>" aria-label="<?php esc_attr_e( 'Synchronization progress', 'nexora-shopping-assistant' ); ?>">
						<span class="convocart-progress-bar <?php echo $active ? 'is-active' : ''; ?>" style="width: <?php echo esc_attr( (string) $percent ); ?>%;"></span>
					</div>
					<p class="convocart-progress-label"><?php echo esc_html( sprintf( /* translators: %1$s: processed item count, %2$s: total item count, %3$d: completion percentage. */ __( '%1$s of %2$s items (%3$d%%)', 'nexora-shopping-assistant' ), number_format_i18n( $done ), number_format_i18n( $total ), $percent ) ); ?></p>
					<table class="widefat striped convocart-sync-table">
						<tbody>
							<tr><th><?php esc_html_e( 'Last sync started/updated', 'nexora-shopping-assistant' ); ?></th><td><?php echo esc_html( $updated ? $this->format_admin_time( $updated ) : __( 'Never', 'nexora-shopping-assistant' ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Completed at', 'nexora-shopping-assistant' ); ?></th><td><?php echo esc_html( $finished ? $this->format_admin_time( $finished ) : __( '—', 'nexora-shopping-assistant' ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Product retries', 'nexora-shopping-assistant' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $retries['product'] ?? 0 ) ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Recovery retries', 'nexora-shopping-assistant' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $retries['recovery'] ?? 0 ) ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Batches', 'nexora-shopping-assistant' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $retries['batch'] ?? 0 ) ) ); ?></td></tr>
							<?php if ( ! empty( $status['last_error'] ) ) : ?>
								<tr><th><?php esc_html_e( 'Last error', 'nexora-shopping-assistant' ); ?></th><td><code><?php echo esc_html( (string) $status['last_error'] ); ?></code></td></tr>
							<?php endif; ?>
						</tbody>
					</table>
					<?php if ( $perm > 0 ) : ?>
						<div class="convocart-admin-notice convocart-admin-notice--warning"><p><?php echo esc_html( sprintf( /* translators: %d: number of products that could not be indexed. */ _n( '%d product could not be indexed after all retries and was skipped.', '%d products could not be indexed after all retries and were skipped.', $perm, 'nexora-shopping-assistant' ), $perm ) ); ?></p></div>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Large catalogues are processed through Action Scheduler when available.', 'nexora-shopping-assistant' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @return array{class: string, label: string}
	 */
	private function sync_state_badge( string $state ): array {
		switch ( $state ) {
			case 'completed':
				return array( 'class' => 'convocart-status-connected', 'label' => __( 'Completed', 'nexora-shopping-assistant' ) );
			case 'completed_with_errors':
				return array( 'class' => 'convocart-status-warning', 'label' => __( 'Completed with errors', 'nexora-shopping-assistant' ) );
			case 'running':
				return array( 'class' => 'convocart-status-running', 'label' => __( 'Running', 'nexora-shopping-assistant' ) );
			case 'queued':
				return array( 'class' => 'convocart-status-running', 'label' => __( 'Queued', 'nexora-shopping-assistant' ) );
			case 'retrying':
				return array( 'class' => 'convocart-status-warning', 'label' => __( 'Retrying', 'nexora-shopping-assistant' ) );
			case 'failed':
				return array( 'class' => 'convocart-status-error', 'label' => __( 'Failed', 'nexora-shopping-assistant' ) );
			default:
				return array( 'class' => 'convocart-status-pending', 'label' => __( 'No sync yet', 'nexora-shopping-assistant' ) );
		}
	}

	private function format_admin_time( string $mysql_utc ): string {
		$timestamp = strtotime( $mysql_utc . ' UTC' );
		if ( false === $timestamp ) {
			return $mysql_utc;
		}
		return wp_date( (string) get_option( 'date_format', 'Y-m-d' ) . ' ' . (string) get_option( 'time_format', 'H:i' ), $timestamp );
	}

	public function status(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to view system status.', 'nexora-shopping-assistant' ) );
		}
		$encryption = new EncryptionService();
		?>
		<div class="wrap convocart-admin"><h1><?php esc_html_e( 'System Status', 'nexora-shopping-assistant' ); ?></h1><div class="convocart-admin-card"><table class="widefat striped"><tbody><tr><th><?php esc_html_e( 'Plugin version', 'nexora-shopping-assistant' ); ?></th><td><?php echo esc_html( CONVOCART_VERSION ); ?></td></tr><tr><th><?php esc_html_e( 'WordPress', 'nexora-shopping-assistant' ); ?></th><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr><tr><th><?php esc_html_e( 'PHP', 'nexora-shopping-assistant' ); ?></th><td><?php echo esc_html( PHP_VERSION ); ?></td></tr><tr><th><?php esc_html_e( 'WooCommerce', 'nexora-shopping-assistant' ); ?></th><td><?php echo esc_html( defined( 'WC_VERSION' ) ? WC_VERSION : __( 'Unavailable', 'nexora-shopping-assistant' ) ); ?></td></tr><tr><th><?php esc_html_e( 'HTTPS', 'nexora-shopping-assistant' ); ?></th><td><?php echo esc_html( is_ssl() ? __( 'Enabled', 'nexora-shopping-assistant' ) : __( 'Disabled', 'nexora-shopping-assistant' ) ); ?></td></tr><tr><th><?php esc_html_e( 'Action Scheduler', 'nexora-shopping-assistant' ); ?></th><td><?php echo esc_html( function_exists( 'as_enqueue_async_action' ) ? __( 'Available', 'nexora-shopping-assistant' ) : __( 'Unavailable', 'nexora-shopping-assistant' ) ); ?></td></tr><tr><th><?php esc_html_e( 'Encryption key fingerprint', 'nexora-shopping-assistant' ); ?></th><td><code><?php echo esc_html( $encryption->fingerprint() ); ?></code></td></tr></tbody></table></div></div>
		<?php
	}

	public function analytics(): void {
		if ( ! current_user_can( Capabilities::VIEW_ANALYTICS ) ) {
			wp_die( esc_html__( 'You do not have permission to view analytics.', 'nexora-shopping-assistant' ) );
		}
		$summary = $this->container->get( AnalyticsService::class )->summary();
		?>
		<div class="wrap convocart-admin"><h1><?php esc_html_e( 'Assistant Analytics', 'nexora-shopping-assistant' ); ?></h1><div class="convocart-metrics-grid"><div class="convocart-metric-card"><strong><?php echo esc_html( $summary['total_events'] ); ?></strong><span><?php esc_html_e( 'Total Events', 'nexora-shopping-assistant' ); ?></span></div><div class="convocart-metric-card"><strong><?php echo esc_html( $summary['unique_sessions'] ); ?></strong><span><?php esc_html_e( 'Unique Sessions', 'nexora-shopping-assistant' ); ?></span></div><div class="convocart-metric-card"><strong><?php echo esc_html( $summary['product_clicks'] ); ?></strong><span><?php esc_html_e( 'Product Clicks', 'nexora-shopping-assistant' ); ?></span></div><div class="convocart-metric-card"><strong><?php echo esc_html( $summary['cart_successes'] ); ?></strong><span><?php esc_html_e( 'Cart Additions', 'nexora-shopping-assistant' ); ?></span></div><div class="convocart-metric-card"><strong><?php echo esc_html( $summary['zero_results'] ); ?></strong><span><?php esc_html_e( 'No-Result Searches', 'nexora-shopping-assistant' ); ?></span></div></div><p class="description"><?php esc_html_e( 'Metrics are based on privacy-safe allowlisted events. Raw message content is never used.', 'nexora-shopping-assistant' ); ?></p></div>
		<?php
	}

	public function feedback_page(): void {
		if ( ! current_user_can( Capabilities::VIEW_ANALYTICS ) ) {
			wp_die( esc_html__( 'You do not have permission to view feedback.', 'nexora-shopping-assistant' ) );
		}
		$service  = $this->container->get( FeedbackService::class );
		$summary  = $service->summary();
		$rows     = $service->recent( 100 );
		$settings = Settings::all();
		$this->feedback_notice();
		?>
		<div class="wrap convocart-admin">
			<h1><?php esc_html_e( 'Assistant Feedback', 'nexora-shopping-assistant' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Customer submissions from the feedback panel. Each entry captures the rating and, where available, the question that was asked, the assistant\'s reply, and any comment.', 'nexora-shopping-assistant' ); ?></p>

			<div class="convocart-metrics-grid">
				<div class="convocart-metric-card"><strong><?php echo esc_html( (string) $summary['total'] ); ?></strong><span><?php esc_html_e( 'Total Responses', 'nexora-shopping-assistant' ); ?></span></div>
				<div class="convocart-metric-card"><strong><?php echo esc_html( (string) $summary['helpful'] ); ?></strong><span><?php esc_html_e( '👍 Helpful', 'nexora-shopping-assistant' ); ?></span></div>
				<div class="convocart-metric-card"><strong><?php echo esc_html( (string) $summary['not_helpful'] ); ?></strong><span><?php esc_html_e( '👎 Not Helpful', 'nexora-shopping-assistant' ); ?></span></div>
				<div class="convocart-metric-card"><strong><?php echo esc_html( (string) $summary['with_comment'] ); ?></strong><span><?php esc_html_e( 'With a Comment', 'nexora-shopping-assistant' ); ?></span></div>
			</div>

			<h2><?php esc_html_e( 'Email Notifications', 'nexora-shopping-assistant' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="convocart_save_feedback_settings" />
				<?php wp_nonce_field( 'convocart_save_feedback_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Email new feedback', 'nexora-shopping-assistant' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[feedback_email_enabled]" value="1" <?php checked( ! empty( $settings['feedback_email_enabled'] ), true ); ?> /> <?php esc_html_e( 'Send an email to the shop whenever a customer submits feedback.', 'nexora-shopping-assistant' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="convocart-feedback-email"><?php esc_html_e( 'Notification address', 'nexora-shopping-assistant' ); ?></label></th>
						<td>
							<input type="email" class="regular-text" id="convocart-feedback-email" name="<?php echo esc_attr( Settings::OPTION ); ?>[feedback_email]" value="<?php echo esc_attr( (string) ( $settings['feedback_email'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave blank to use the site admin email.', 'nexora-shopping-assistant' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Notification Settings', 'nexora-shopping-assistant' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Recent Feedback', 'nexora-shopping-assistant' ); ?></h2>
			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'No feedback has been submitted yet.', 'nexora-shopping-assistant' ); ?></p>
			<?php else : ?>
				<table class="widefat striped convocart-feedback-table">
					<thead>
						<tr>
							<th style="width:90px;"><?php esc_html_e( 'Rating', 'nexora-shopping-assistant' ); ?></th>
							<th><?php esc_html_e( 'Question', 'nexora-shopping-assistant' ); ?></th>
							<th><?php esc_html_e( 'AI Response', 'nexora-shopping-assistant' ); ?></th>
							<th><?php esc_html_e( 'Comment', 'nexora-shopping-assistant' ); ?></th>
							<th style="width:150px;"><?php esc_html_e( 'Date', 'nexora-shopping-assistant' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$rating = (string) ( $row['rating'] ?? '' );
							$badge  = 'helpful' === $rating ? '👍' : ( 'not_helpful' === $rating ? '👎' : '—' );
							$when   = (string) ( $row['created_at'] ?? '' );
							$local  = '' !== $when ? get_date_from_gmt( $when, 'Y-m-d H:i' ) : '';
							?>
							<tr>
								<td style="font-size:18px;"><?php echo esc_html( $badge ); ?></td>
								<td><?php echo '' !== (string) $row['question'] ? esc_html( (string) $row['question'] ) : '<span class="description">&mdash;</span>'; ?></td>
								<td><?php echo '' !== (string) $row['answer'] ? esc_html( (string) $row['answer'] ) : '<span class="description">&mdash;</span>'; ?></td>
								<td><?php echo '' !== (string) $row['comment'] ? esc_html( (string) $row['comment'] ) : '<span class="description">&mdash;</span>'; ?></td>
								<td><?php echo esc_html( $local ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete all stored feedback? This cannot be undone.', 'nexora-shopping-assistant' ) ); ?>');">
					<input type="hidden" name="action" value="convocart_clear_feedback" />
					<?php wp_nonce_field( 'convocart_clear_feedback' ); ?>
					<?php submit_button( __( 'Delete All Feedback', 'nexora-shopping-assistant' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function feedback_notice(): void {
		$notice   = $this->notice_param();
		$messages   = array(
			'feedback_saved'   => array( 'success', __( 'Feedback notification settings saved.', 'nexora-shopping-assistant' ) ),
			'feedback_cleared' => array( 'success', __( 'All feedback has been deleted.', 'nexora-shopping-assistant' ) ),
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $notice ][1] ) );
	}

	public function save_feedback_settings(): void {
		check_admin_referer( 'convocart_save_feedback_settings' );
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'Permission denied.', 'nexora-shopping-assistant' ) );
		}
		$payload             = $this->posted_settings_payload();
		$payload['_convocart_section'] = 'feedback';
		$this->save_settings_section( 'feedback', $payload );
		$this->redirect_feedback( 'feedback_saved' );
	}

	public function clear_feedback(): void {
		check_admin_referer( 'convocart_clear_feedback' );
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'Permission denied.', 'nexora-shopping-assistant' ) );
		}
		$this->container->get( FeedbackService::class )->clear();
		$this->redirect_feedback( 'feedback_cleared' );
	}

	private function redirect_feedback( string $notice ): void {
		nocache_headers();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'convocart-feedback',
					'convocart_notice' => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		if ( ! defined( 'CONVOCART_TESTING' ) || ! CONVOCART_TESTING ) {
			exit;
		}
	}

	public function save_key(): void {
		// Selects the nonce action verified below; sanitize_key() limits it to a provider ID.
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		if ( ! EncryptionService::is_supported_provider( $provider ) ) {
			$this->redirect_provider( 'invalid_provider' );
		}
		check_admin_referer( 'convocart_save_key_' . $provider );
		if ( ! current_user_can( Capabilities::MANAGE_PROVIDERS ) ) {
			wp_die( esc_html__( 'Permission denied.', 'nexora-shopping-assistant' ) );
		}
		// A secret must not be altered by text sanitizers. It is trimmed here and then validated
		// against a strict printable-ASCII allowlist in EncryptionService::put() before storage.
		$raw_key = isset( $_POST['api_key'] ) ? wp_unslash( (string) $_POST['api_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by allowlist; see comment above.
		$key     = trim( $raw_key );
		if ( '' === $key ) {
			$this->redirect_provider( 'empty_key' );
		}
		$encryption = new EncryptionService();
		if ( ! $encryption->put( $provider, $key ) ) {
			$notice = $encryption->last_error();
			if ( '' === $notice ) {
				$notice = 'storage_failed';
			}
			$this->redirect_provider( $notice );
		}
		$this->redirect_provider( 'saved' );
	}

	public function remove_key(): void {
		// Selects the nonce action verified below; sanitize_key() limits it to a provider ID.
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		if ( ! EncryptionService::is_supported_provider( $provider ) ) {
			$this->redirect_provider( 'invalid_provider' );
		}
		check_admin_referer( 'convocart_remove_key_' . $provider );
		if ( ! current_user_can( Capabilities::MANAGE_PROVIDERS ) ) {
			wp_die( esc_html__( 'Permission denied.', 'nexora-shopping-assistant' ) );
		}
		( new EncryptionService() )->remove( $provider );
		$this->redirect_provider( 'removed' );
	}

	public function sync(): void {
		check_admin_referer( 'convocart_sync' );
		if ( ! current_user_can( Capabilities::MANAGE_KNOWLEDGE ) ) {
			wp_die( esc_html__( 'Permission denied.', 'nexora-shopping-assistant' ) );
		}
		$notice = 'queued';
		$job_uuid = '';
		try {
			$job_uuid = $this->container->get( KnowledgeManager::class )->queue_full_sync();
		} catch ( \RuntimeException $error ) {
			$notice = false !== strpos( $error->getMessage(), 'lock' ) ? 'sync_running' : 'sync_failed';
			$job_uuid = $this->container->get( KnowledgeManager::class )->active_full_sync_uuid();
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'convocart-knowledge',
					'convocart_notice'  => sanitize_key( $notice ),
					'queued'           => rawurlencode( $job_uuid ),
				),
				admin_url( 'admin.php' )
			)
		);
		if ( ! defined( 'CONVOCART_TESTING' ) || ! CONVOCART_TESTING ) {
			exit;
		}
	}

	private function checkbox( array $settings, string $key, string $label ): void {
		$name = Settings::OPTION . '[' . $key . ']';
		printf( '<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>', esc_attr( $name ), checked( ! empty( $settings[ $key ] ), true, false ), esc_html( $label ) );
	}

	private function media_picker( string $key, string $label, int $attachment_id, string $description = '' ): void {
		$name      = Settings::OPTION . '[' . $key . ']';
		$field_id  = 'convocart-media-' . str_replace( '_', '-', $key );
		$image_url = $attachment_id > 0 ? wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : '';
		$has_image = is_string( $image_url ) && '' !== $image_url;
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<div class="convocart-media-picker" data-convocart-media-picker>
					<div class="convocart-media-preview" data-convocart-media-preview>
						<img src="<?php echo esc_url( $has_image ? $image_url : '' ); ?>" alt="" style="<?php echo $has_image ? '' : 'display:none;'; ?>max-width:96px;max-height:96px;border-radius:8px;object-fit:cover;" />
					</div>
					<input type="hidden" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $attachment_id ); ?>" data-convocart-media-input />
					<p>
						<button type="button" class="button" data-convocart-media-choose><?php esc_html_e( 'Choose image', 'nexora-shopping-assistant' ); ?></button>
						<button type="button" class="button-link-delete" data-convocart-media-remove style="<?php echo $has_image ? '' : 'display:none;'; ?>margin-left:8px;"><?php esc_html_e( 'Remove', 'nexora-shopping-assistant' ); ?></button>
					</p>
					<?php if ( '' !== $description ) : ?>
						<p class="description"><?php echo esc_html( $description ); ?></p>
					<?php endif; ?>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Read the notice code set by this controller's own redirects.
	 *
	 * Display-only: the value is sanitized with sanitize_key() and then matched
	 * against a fixed allowlist of messages, so no state changes depend on it.
	 */
	private function notice_param(): string {
		return isset( $_GET['convocart_notice'] ) ? sanitize_key( wp_unslash( $_GET['convocart_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice code, allowlisted by callers.
	}

	/** The submitted nonce itself; verified by the caller with the section-specific action. */
	private function posted_nonce(): string {
		return isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This reads the nonce that is verified next.
	}

	/**
	 * Raw settings payload. Callers verify the section-specific nonce and the
	 * capability before saving; save_settings_section() keeps only allowlisted
	 * keys and Settings::sanitize() sanitizes each one.
	 */
	private function posted_settings_payload(): array {
		$payload = isset( $_POST[ Settings::OPTION ] ) ? wp_unslash( $_POST[ Settings::OPTION ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See docblock: nonce verified by callers; values sanitized per key.
		return is_array( $payload ) ? $payload : array();
	}

	private function save_settings_section( string $section, array $payload ): void {
		$allowed = array(
			'general'         => array( 'enabled', 'frontend_enabled', 'launcher_enabled', 'hash_trigger_enabled', 'shortcode_enabled', 'add_to_cart_enabled', 'conversation_logging', 'logged_in_logging', 'anonymous_analytics', 'logged_in_analytics', 'preserve_data_on_uninstall', 'conversation_retention', 'analytics_retention', 'max_conversation_messages', 'max_message_length', 'max_recommendations', 'provider_timeout', 'provider_retries', 'max_tool_calls', 'assistant_name', 'welcome_message', 'fallback_message' ),
			'styling'         => array( 'primary_color', 'secondary_color', 'font_family', 'heading_font_family', 'brand_logo_id', 'chef_avatar_id' ),
			'provider_order'  => array( 'primary_provider', 'fallback_order', 'provider_order' ),
			'provider_models' => array( 'provider_models' ),
			'feedback'        => array( 'feedback_email_enabled', 'feedback_email' ),
		);
		$clean = array( '_convocart_section' => $section );
		foreach ( $allowed[ $section ] ?? array() as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				$clean[ $key ] = $payload[ $key ];
			}
		}
		if ( 'general' === $section && isset( $payload['privacy_notice'] ) ) { $clean['privacy_notice'] = $payload['privacy_notice']; }
		$new = Settings::sanitize( $clean );
		update_option( Settings::OPTION, $new, false );
		if ( get_option( Settings::OPTION ) !== $new ) { throw new \RuntimeException( 'Settings storage failed.' ); }
	}

	private function provider_notice(): void {
		$notice   = $this->notice_param();
		$messages   = array(
			'settings_saved'    => array( 'success', __( 'Provider settings saved.', 'nexora-shopping-assistant' ) ),
			'saved'             => array( 'success', __( 'API key encrypted and saved. Available models are loading automatically. Save a model and run its shopping-response test.', 'nexora-shopping-assistant' ) ),
			'removed'           => array( 'success', __( 'API key removed.', 'nexora-shopping-assistant' ) ),
			'empty_key'         => array( 'error', __( 'Enter a non-empty API key before saving.', 'nexora-shopping-assistant' ) ),
			'invalid_provider'  => array( 'error', __( 'The selected provider is invalid.', 'nexora-shopping-assistant' ) ),
			'invalid_secret'    => array( 'error', __( 'The API key is empty or too long.', 'nexora-shopping-assistant' ) ),
			'encryption_failed' => array( 'error', __( 'The API key could not be encrypted. Ensure Sodium or OpenSSL is available on the server.', 'nexora-shopping-assistant' ) ),
			'storage_failed'    => array( 'error', __( 'The API key could not be verified after saving. Check database write permissions and define CONVOCART_ENCRYPTION_KEY in wp-config.php.', 'nexora-shopping-assistant' ) ),
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}
		$class = 'success' === $messages[ $notice ][0] ? 'notice-success' : 'notice-error';
		printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $messages[ $notice ][1] ) );
	}

	private function settings_notice(): void {
		$notice   = $this->notice_param();
		$messages   = array(
			'settings_saved' => array( 'success', __( 'Settings saved.', 'nexora-shopping-assistant' ) ),
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $notice ][1] ) );
	}

	private function knowledge_notice(): void {
		$notice   = $this->notice_param();
		$messages   = array(
			'queued'       => array( 'success', __( 'Product synchronization was queued.', 'nexora-shopping-assistant' ) ),
			'sync_running' => array( 'warning', __( 'A product synchronization is already running. Wait for it to finish or retry shortly.', 'nexora-shopping-assistant' ) ),
			'sync_failed'  => array( 'error', __( 'Product synchronization could not be queued. Please try again shortly.', 'nexora-shopping-assistant' ) ),
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}
		$class = 'success' === $messages[ $notice ][0] ? 'notice-success' : ( 'warning' === $messages[ $notice ][0] ? 'notice-warning' : 'notice-error' );
		printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $messages[ $notice ][1] ) );
	}

	private function redirect_provider( string $notice ): void {
		nocache_headers();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'convocart-providers',
					'convocart_notice' => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		if ( ! defined( 'CONVOCART_TESTING' ) || ! CONVOCART_TESTING ) {
			exit;
		}
	}

	private function redirect_settings( string $notice ): void {
		nocache_headers();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'convocart',
					'convocart_notice' => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		if ( ! defined( 'CONVOCART_TESTING' ) || ! CONVOCART_TESTING ) {
			exit;
		}
	}

	public function analyze_page(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'nexora-shopping-assistant' ) );
		}
		$analysis   = StoreContext::get();
		$last_run   = get_option( 'convocart_analyze_last_run', '' );
		$stats      = get_option( 'convocart_analyze_stats', array() );
		$is_running = get_transient( 'convocart_analyzing' );
		?>
		<div class="wrap convocart-admin">
			<h1><?php esc_html_e( 'Analyze Website Content', 'nexora-shopping-assistant' ); ?></h1>
			<p class="convocart-admin-subtitle"><?php esc_html_e( 'Build a bounded store-context snapshot with policy pages first. This is not an AI-generated analysis or full-site crawl.', 'nexora-shopping-assistant' ); ?></p>
			<?php if ( get_option( 'convocart_context_invalidated_at', '' ) ) : ?>
				<p role="status"><?php esc_html_e( 'Saved store context was cleared or became outdated. Run Analyze to rebuild it from current public content.', 'nexora-shopping-assistant' ); ?></p>
			<?php endif; ?>

			<?php if ( $is_running ) : ?>
				<div class="notice notice-info"><p><?php esc_html_e( 'Analysis is currently running. This may take a few minutes for large stores.', 'nexora-shopping-assistant' ); ?></p></div>
			<?php endif; ?>

			<?php if ( 'prompt_saved' === $this->notice_param() ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'AI configuration saved.', 'nexora-shopping-assistant' ); ?></p></div>
			<?php endif; ?>

			<div class="convocart-admin-grid">
				<div class="convocart-admin-card">
					<h2><?php esc_html_e( 'Site Analysis', 'nexora-shopping-assistant' ); ?></h2>
					<p><?php esc_html_e( 'Build up to 8,000 characters of context from:', 'nexora-shopping-assistant' ); ?></p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'Up to 20 public product names; live prices are supplied separately', 'nexora-shopping-assistant' ); ?></li>
						<li><?php esc_html_e( 'Up to 50 public pages/posts, prioritizing store policies', 'nexora-shopping-assistant' ); ?></li>
						<li><?php esc_html_e( 'Product categories and menu structure', 'nexora-shopping-assistant' ); ?></li>
						<li><?php esc_html_e( 'Site tagline, about content, and brand messaging', 'nexora-shopping-assistant' ); ?></li>
					</ul>
					<p><?php esc_html_e( 'This data helps the assistant give better, more contextual responses to customers.', 'nexora-shopping-assistant' ); ?></p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="convocart_analyze" />
						<?php wp_nonce_field( 'convocart_analyze_site' ); ?>
						<?php submit_button( __( 'Analyze', 'nexora-shopping-assistant' ), 'primary large', 'convocart_analyze_btn', true, $is_running ? array( 'disabled' => 'disabled' ) : array() ); ?>
					</form>

					<?php if ( ! empty( $last_run ) ) : ?>
						<p class="description"><?php printf( /* translators: %s: date and time of the last analysis. */ esc_html__( 'Last analyzed: %s', 'nexora-shopping-assistant' ), esc_html( $last_run ) ); ?></p>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $stats ) && is_array( $stats ) ) : ?>
				<div class="convocart-admin-card">
					<h2><?php esc_html_e( 'Analysis Results', 'nexora-shopping-assistant' ); ?></h2>
					<table class="form-table">
						<tr><th><?php esc_html_e( 'Products scanned', 'nexora-shopping-assistant' ); ?></th><td><?php echo esc_html( $stats['products'] ?? 0 ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Pages scanned', 'nexora-shopping-assistant' ); ?></th><td><?php echo esc_html( $stats['pages'] ?? 0 ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Categories found', 'nexora-shopping-assistant' ); ?></th><td><?php echo esc_html( $stats['categories'] ?? 0 ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Content items', 'nexora-shopping-assistant' ); ?></th><td><?php echo esc_html( $stats['content_items'] ?? 0 ); ?></td></tr>
					</table>
				</div>
				<?php endif; ?>

				<?php if ( ! empty( $analysis ) ) : ?>
				<div class="convocart-admin-card">
					<h2><?php esc_html_e( 'Generated Site Context (used by AI)', 'nexora-shopping-assistant' ); ?></h2>
					<textarea readonly class="large-text" rows="12" style="font-family: monospace; font-size: 12px;"><?php echo esc_textarea( $analysis ); ?></textarea>
					<p class="description"><?php esc_html_e( 'This content is passed to the AI assistant as additional context for better recommendations.', 'nexora-shopping-assistant' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="convocart_clear_context" />
						<?php wp_nonce_field( 'convocart_clear_context' ); ?>
						<?php submit_button( __( 'Clear saved context', 'nexora-shopping-assistant' ), 'secondary' ); ?>
					</form>
				</div>
				<?php endif; ?>

				<div class="convocart-admin-card" style="grid-column: 1 / -1;">
					<h2><?php esc_html_e( 'AI Assistant Configuration', 'nexora-shopping-assistant' ); ?></h2>
					<p><?php esc_html_e( 'Customize the assistant personality and behaviour. Changes here take effect immediately for new conversations.', 'nexora-shopping-assistant' ); ?></p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="convocart_save_system_prompt" />
						<?php wp_nonce_field( 'convocart_save_system_prompt' ); ?>

						<table class="form-table">
							<tr>
								<th scope="row"><label for="convocart_assistant_name"><?php esc_html_e( 'Assistant Name', 'nexora-shopping-assistant' ); ?></label></th>
								<td>
									<input type="text" id="convocart_assistant_name" name="convocart_assistant_name" value="<?php echo esc_attr( (string) Settings::get( 'assistant_name', 'Shopping Assistant' ) ); ?>" class="regular-text" />
									<p class="description"><?php esc_html_e( 'The display name shown to customers in the chat widget.', 'nexora-shopping-assistant' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="convocart_system_prompt"><?php esc_html_e( 'System Prompt', 'nexora-shopping-assistant' ); ?></label></th>
								<td>
									<textarea id="convocart_system_prompt" name="convocart_system_prompt" class="large-text" rows="20" style="font-family: monospace; font-size: 12px;"><?php echo esc_textarea( get_option( 'convocart_system_prompt', self::default_system_prompt() ) ); ?></textarea>
									<p class="description"><?php esc_html_e( 'The system prompt defines the AI personality and behaviour. Placeholders: {SITE_CONTEXT} will be replaced with analyzed site content, {PRODUCTS} with available products JSON, {SCHEMA} with required response format.', 'nexora-shopping-assistant' ); ?></p>
								</td>
							</tr>
						</table>

						<?php submit_button( __( 'Save Configuration', 'nexora-shopping-assistant' ), 'primary', 'convocart_save_prompt_btn' ); ?>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	public function run_analyze(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'nexora-shopping-assistant' ) );
		}
		check_admin_referer( 'convocart_analyze_site' );

		if ( get_transient( 'convocart_analyzing' ) ) {
			wp_safe_redirect( add_query_arg( 'page', 'convocart-analyze', admin_url( 'admin.php' ) ) );
			exit;
		}

		set_transient( 'convocart_analyzing', true, 300 );
		$generation = StoreContext::generation();
		$sources = array();

		$context_parts = array();
		$stats         = array( 'products' => 0, 'pages' => 0, 'categories' => 0, 'content_items' => 0 );

		$context_parts[] = 'SITE: ' . get_bloginfo( 'name' ) . ' — ' . get_bloginfo( 'description' );
		$context_parts[] = 'URL: ' . home_url();
		$policy_ids = array_filter( array( absint( get_option( 'wp_page_for_privacy_policy' ) ), absint( get_option( 'woocommerce_terms_page_id' ) ) ) );
		$policy_pages = array();
		foreach ( $policy_ids as $id ) { $page = get_post( $id ); if ( $page ) { $policy_pages[ $id ] = $page; } }
		global $wpdb;
		$policy_matches = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='page' AND post_password='' AND (post_title LIKE '%shipping%' OR post_title LIKE '%return%' OR post_title LIKE '%refund%' OR post_title LIKE '%delivery%' OR post_title LIKE '%terms%') ORDER BY ID ASC LIMIT 10" );
		foreach ( (array) $policy_matches as $id ) { $page = get_post( $id ); if ( $page ) { $policy_pages[ $id ] = $page; } }
		foreach ( $policy_pages as $page ) {
			if ( ! StoreContext::is_public( $page ) ) { continue; }
			$sources[ $page->ID ] = StoreContext::fingerprint( $page );
			$context_parts[] = 'POLICY ' . $page->post_title . ': ' . mb_substr( wp_strip_all_tags( strip_shortcodes( $page->post_content ) ), 0, 1800 );
			++$stats['pages'];
		}
		$policy_context = mb_substr( implode( "\n", $context_parts ), 0, 6500 );
		$context_parts = array( $policy_context );

		if ( function_exists( 'wc_get_products' ) ) {
			$products = wc_get_products( array( 'limit' => 20, 'status' => 'publish', 'visibility' => 'visible', 'paginate' => false ) );
			$stats['products'] = count( $products );
			$currency = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
			$product_lines = array();
			foreach ( $products as $product ) {
				if ( ! $this->container->get( \ConvoCart\Assistant\WooCommerce\WooCommerceService::class )->is_public_product( $product ) ) { continue; }
				$source = get_post( $product->get_id() );
				if ( ! $source || ! StoreContext::is_public( $source ) ) { continue; }
				$type = $product->get_type();
				if ( in_array( $type, array( 'kit', 'bundle', 'woosb', 'mix-and-match' ), true ) ) {
					continue;
				}
				$cats  = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
				$cats  = is_array( $cats ) ? implode( ', ', $cats ) : '';
				$line  = $product->get_name() . ' | ' . $cats;
				$desc  = wp_strip_all_tags( $product->get_short_description() );
				if ( $desc ) {
					$line .= ' | ' . mb_substr( $desc, 0, 100 );
				}
				$product_lines[] = $line;
				$sources[ $source->ID ] = StoreContext::fingerprint( $source );
			}
			$stats['products'] = count( $product_lines );
			$context_parts[] = "\nPRODUCTS (" . count( $product_lines ) . "):\n" . implode( "\n", $product_lines );
		}

		$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'fields' => 'names', 'number' => 50 ) );
		if ( is_array( $categories ) ) {
			$stats['categories'] = count( $categories );
			$context_parts[]     = "\nCATEGORIES: " . implode( ', ', $categories );
		}

		$wanted = max( 1, 50 - $stats['pages'] );
		$pages  = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => $wanted + count( $policy_pages ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		// Policy pages were already added above; skip them here.
		$pages           = array_slice( array_values( array_filter( $pages, static fn( $page ): bool => ! isset( $policy_pages[ $page->ID ] ) ) ), 0, $wanted );
		$stats['pages'] += count( $pages );
		$page_lines     = array();
		foreach ( $pages as $page ) {
			if ( ! StoreContext::is_public( $page ) ) { continue; }
			$sources[ $page->ID ] = StoreContext::fingerprint( $page );
			$content = wp_strip_all_tags( strip_shortcodes( $page->post_content ) );
			$content = mb_substr( $content, 0, 200 );
			$page_lines[] = $page->post_title . ': ' . $content;
		}
		if ( ! empty( $page_lines ) ) {
			$context_parts[] = "\nPAGES & CONTENT:\n" . implode( "\n", $page_lines );
		}

		$nav_menus = wp_get_nav_menus();
		if ( ! empty( $nav_menus ) ) {
			$menu_items_text = array();
			foreach ( array_slice( $nav_menus, 0, 3 ) as $menu ) {
				$items = wp_get_nav_menu_items( $menu->term_id );
				if ( is_array( $items ) ) {
					$menu_items_text[] = $menu->name . ': ' . implode( ', ', array_map( fn( $i ) => $i->title, array_slice( $items, 0, 15 ) ) );
				}
			}
			if ( ! empty( $menu_items_text ) ) {
				$context_parts[] = "\nNAVIGATION:\n" . implode( "\n", $menu_items_text );
			}
		}

		$stats['content_items'] = $stats['products'] + $stats['pages'] + $stats['categories'];
		$full_context = implode( "\n", $context_parts );
		$full_context = mb_substr( $full_context, 0, 8000 );

		if ( ! StoreContext::save( $full_context, $sources, $generation ) ) {
			StoreContext::invalidate();
			delete_transient( 'convocart_analyzing' );
			wp_die( esc_html__( 'Store content changed during analysis or could not be saved. Run Analyze again.', 'nexora-shopping-assistant' ), '', array( 'back_link' => true, 'response' => 409 ) );
		}
		update_option( 'convocart_analyze_stats', $stats, false );
		update_option( 'convocart_analyze_last_run', current_time( 'Y-m-d H:i:s' ), false );

		delete_transient( 'convocart_analyzing' );

		wp_safe_redirect( add_query_arg( 'page', 'convocart-analyze', admin_url( 'admin.php' ) ) );
		if ( ! defined( 'CONVOCART_TESTING' ) || ! CONVOCART_TESTING ) {
			exit;
		}
	}

	public function clear_context(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) { wp_die( esc_html__( 'Permission denied.', 'nexora-shopping-assistant' ) ); }
		check_admin_referer( 'convocart_clear_context' );
		StoreContext::invalidate();
		wp_safe_redirect( add_query_arg( 'page', 'convocart-analyze', admin_url( 'admin.php' ) ) );
		if ( ! defined( 'CONVOCART_TESTING' ) || ! CONVOCART_TESTING ) { exit; }
	}

	public function save_system_prompt(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'nexora-shopping-assistant' ) );
		}
		check_admin_referer( 'convocart_save_system_prompt' );

		$prompt = isset( $_POST['convocart_system_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['convocart_system_prompt'] ) ) : '';
		$prompt = mb_substr( $prompt, 0, 16000 );
		if ( '' === trim( $prompt ) ) {
			$prompt = self::default_system_prompt();
		}
		update_option( 'convocart_system_prompt', $prompt, false );

		$name = isset( $_POST['convocart_assistant_name'] ) ? sanitize_text_field( wp_unslash( $_POST['convocart_assistant_name'] ) ) : '';
		if ( '' === trim( $name ) ) {
			$name = (string) Settings::get( 'assistant_name', 'Shopping Assistant' );
		}
		$settings = Settings::all();
		$settings['assistant_name'] = $name;
		update_option( Settings::OPTION, $settings, false );

		wp_safe_redirect( add_query_arg( array( 'page' => 'convocart-analyze', 'convocart_notice' => 'prompt_saved' ), admin_url( 'admin.php' ) ) );
		if ( ! defined( 'CONVOCART_TESTING' ) || ! CONVOCART_TESTING ) {
			exit;
		}
	}

	public static function default_system_prompt(): string {
		return Settings::default_system_prompt();
	}
}

