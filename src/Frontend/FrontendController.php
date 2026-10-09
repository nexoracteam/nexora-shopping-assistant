<?php
namespace ConvoCart\Assistant\Frontend;

use ConvoCart\Assistant\Container;
use ConvoCart\Assistant\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class FrontendController {
	private bool $assets_enqueued = false;

	public function __construct( private Container $container ) {}

	public function register(): void {
		add_action( 'wp_ajax_convocart_nonce', array( $this, 'renew_nonce' ) );
		add_action( 'wp_ajax_nopriv_convocart_nonce', array( $this, 'renew_nonce' ) );
		add_shortcode( 'nexora_shopping_assistant', array( $this, 'shortcode' ) );
		// Legacy tag from ConvoCart AI; kept so existing content keeps working.
		add_shortcode( 'convocart', array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'wp_footer', array( $this, 'launcher' ) );
	}

	public function renew_nonce(): void {
		nocache_headers();
		wp_send_json( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	public function maybe_enqueue(): void {
		global $post;
		$content       = is_object( $post ) && isset( $post->post_content ) ? (string) $post->post_content : '';
		$has_shortcode = '' !== $content && ( has_shortcode( $content, 'nexora_shopping_assistant' ) || has_shortcode( $content, 'convocart' ) );
		if ( Settings::get( 'enabled', false ) && ( Settings::get( 'launcher_enabled', true ) || Settings::get( 'hash_trigger_enabled', true ) || $has_shortcode ) ) {
			$this->enqueue();
		}
	}

	public function enqueue(): void {
		if ( $this->assets_enqueued ) { return; }
		if ( ! Settings::get( 'enabled', true ) || ! Settings::get( 'frontend_enabled', true ) ) {
			return;
		}
		$settings       = Settings::all();
		$assistant_display = (string) $settings['assistant_name'];
		if ( '' === trim( $assistant_display ) ) {
			$assistant_display = (string) $settings['assistant_name'];
		}
		$bot_icon       = CONVOCART_URL . 'assets/frontend/img/bot-icon.svg';
		$chef_fallback  = CONVOCART_URL . 'assets/frontend/img/assistant-avatar.svg';
		$brand_logo_url = $this->attachment_url( (int) ( $settings['brand_logo_id'] ?? 0 ), $bot_icon );
		$chef_avatar_url = $this->attachment_url( (int) ( $settings['chef_avatar_id'] ?? 0 ), $chef_fallback );
		wp_register_style( 'convocart', CONVOCART_URL . 'assets/frontend/css/assistant.css', array(), CONVOCART_VERSION );
		wp_register_script( 'convocart', CONVOCART_URL . 'assets/frontend/js/assistant.js', array( 'wp-element' ), CONVOCART_VERSION, true );
			wp_localize_script(
			'convocart',
			'ConvoCartConfig',
			array(
				'restBase'           => esc_url_raw( rest_url( 'convocart/v1' ) ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
				'nonceUrl'           => esc_url_raw( admin_url( 'admin-ajax.php?action=convocart_nonce' ) ),
				'requestTimeout'     => ( (int) $settings['provider_timeout'] + 10 ) * 1000,
				'primaryColor'       => $settings['primary_color'],
				'secondaryColor'     => $settings['secondary_color'],
				'fontFamily'         => $settings['font_family'],
				'headingFontFamily'  => $settings['heading_font_family'] ?? "'Inter', sans-serif",
				'assistantName'      => $assistant_display,
				'botIcon'            => $brand_logo_url,
				'brandLogo'          => $brand_logo_url,
				'chefAvatar'         => $chef_avatar_url,
				'hashTriggerEnabled' => (bool) $settings['hash_trigger_enabled'],
				'analyticsEnabled'   => (bool) $settings['anonymous_analytics'],
				'strings'            => array(
					'brand'                => $assistant_display,
					'tagline'              => __( 'Your shopping assistant', 'nexora-shopping-assistant' ),
					'launcherLabel'        => sprintf( /* translators: %s: assistant name */ __( 'Ask %s', 'nexora-shopping-assistant' ), $assistant_display ),
					'online'               => __( 'AI assistant', 'nexora-shopping-assistant' ),
					'close'                => __( 'Close assistant', 'nexora-shopping-assistant' ),
					'typeMessage'          => __( 'Type your message', 'nexora-shopping-assistant' ),
					'placeholder'          => __( 'Ask about products or get recommendations…', 'nexora-shopping-assistant' ),
					'send'                 => __( 'Send', 'nexora-shopping-assistant' ),
					'thinking'             => __( 'Thinking…', 'nexora-shopping-assistant' ),
					'newConversation'      => __( 'New conversation', 'nexora-shopping-assistant' ),
					'restart'              => __( 'Start over', 'nexora-shopping-assistant' ),
					'welcomeFallback'      => __( 'Hi! I can help you find the right products and answer your questions. What are you looking for?', 'nexora-shopping-assistant' ),
					'view'                 => __( 'View', 'nexora-shopping-assistant' ),
					'viewProduct'          => __( 'View product', 'nexora-shopping-assistant' ),
					'options'              => __( 'Choose options', 'nexora-shopping-assistant' ),
					'addToCart'            => __( 'Add to Cart', 'nexora-shopping-assistant' ),
					'adding'               => __( 'Adding…', 'nexora-shopping-assistant' ),
					'added'                => __( 'Added', 'nexora-shopping-assistant' ),
					'selectOptions'        => __( 'Select Options', 'nexora-shopping-assistant' ),
					'addedToCart'          => __( 'Added to your cart.', 'nexora-shopping-assistant' ),
					'cartFailed'           => __( 'Could not add to cart.', 'nexora-shopping-assistant' ),
					'inStock'              => __( 'In stock', 'nexora-shopping-assistant' ),
					'lowStock'             => __( 'Low stock', 'nexora-shopping-assistant' ),
					'outOfStock'           => __( 'Out of stock', 'nexora-shopping-assistant' ),
					'onBackorder'          => __( 'Available to order', 'nexora-shopping-assistant' ),
					'helpful'              => __( 'Helpful', 'nexora-shopping-assistant' ),
					'notHelpful'           => __( 'Not helpful', 'nexora-shopping-assistant' ),
					'feedbackPrompt'       => __( 'Was this helpful?', 'nexora-shopping-assistant' ),
					'feedbackChef'         => $assistant_display,
					'feedbackSays'         => __( 'says…', 'nexora-shopping-assistant' ),
					'feedbackIntro'        => __( 'Your feedback helps me improve for everyone.', 'nexora-shopping-assistant' ),
					'feedbackCommentPrompt' => __( 'Sorry about that — what could have been better?', 'nexora-shopping-assistant' ),
					'feedbackThanksTitle'  => __( 'Thank you!', 'nexora-shopping-assistant' ),
					'feedbackThanksBody'   => __( 'Your feedback helps us improve.', 'nexora-shopping-assistant' ),
					'feedbackReceived'     => __( 'Your feedback has been received.', 'nexora-shopping-assistant' ),
					'feedbackContinue'     => __( 'Continue chatting', 'nexora-shopping-assistant' ),
					'feedbackStartNew'     => __( 'Start a new chat', 'nexora-shopping-assistant' ),
					'feedbackSkip'         => __( 'Maybe later', 'nexora-shopping-assistant' ),
					'feedbackLink'         => __( 'Help improve the assistant', 'nexora-shopping-assistant' ),
					'feedbackTitle'        => __( 'Help improve the assistant', 'nexora-shopping-assistant' ),
					'feedbackComment'      => __( 'Comments or suggestions', 'nexora-shopping-assistant' ),
					'feedbackPlaceholder'  => __( 'Comments or suggestions (optional)…', 'nexora-shopping-assistant' ),
					'feedbackSubmit'       => __( 'Send feedback', 'nexora-shopping-assistant' ),
					'feedbackSending'      => __( 'Sending…', 'nexora-shopping-assistant' ),
					'feedbackNeedsRating'  => __( 'Please choose 👍 or 👎 first.', 'nexora-shopping-assistant' ),
					'assistantUnavailable' => __( 'The assistant is temporarily unavailable.', 'nexora-shopping-assistant' ),
					'replyFallback'        => __( 'I could not complete that request right now. Please try again shortly.', 'nexora-shopping-assistant' ),
					'startFailed'          => __( 'The assistant could not start. Please try again.', 'nexora-shopping-assistant' ),
					'messageFailed'        => __( 'The assistant could not respond. Please try again.', 'nexora-shopping-assistant' ),
					'offline'              => __( 'You appear to be offline. Check your connection and try again.', 'nexora-shopping-assistant' ),
					'emptyResults'         => __( 'I could not find a match for that. Try rephrasing or ask about another product.', 'nexora-shopping-assistant' ),
					'feedbackSaved'        => __( 'Thanks for the feedback.', 'nexora-shopping-assistant' ),
					'feedbackSavedDown'    => __( 'Thanks, we will use that to improve.', 'nexora-shopping-assistant' ),
					'feedbackFailed'       => __( 'Feedback could not be saved.', 'nexora-shopping-assistant' ),
					'cartStatusFailed'     => __( 'Could not add to cart.', 'nexora-shopping-assistant' ),
					'retry'                => __( 'Try again', 'nexora-shopping-assistant' ),
					'suggestionsTitle'     => __( 'Try asking', 'nexora-shopping-assistant' ),
					'viewCart'             => __( 'View Cart', 'nexora-shopping-assistant' ),
					'checkout'             => __( 'Checkout', 'nexora-shopping-assistant' ),
					'privacyNotice'        => $settings['privacy_notice'],
					'feedbackContext'      => __( 'Submitting feedback shares your latest question, the reply and optional comment with this store.', 'nexora-shopping-assistant' ),
					'sessionExpired'       => __( 'Your session expired. Start a new chat to continue.', 'nexora-shopping-assistant' ),
				),
			)
		);
		wp_add_inline_style(
			'convocart',
			'
			.convocart-portal,
			.convocart-launcher,
			.convocart-trigger {
				--convocart-primary: ' . $settings['primary_color'] . ';
				--convocart-secondary: ' . $settings['secondary_color'] . ';
				--convocart-font: ' . $settings['font_family'] . ';
				--convocart-heading-font: ' . ( $settings['heading_font_family'] ?? "'Inter', sans-serif" ) . ';
			}
		'
		);
		wp_enqueue_style( 'convocart' );
		wp_enqueue_script( 'convocart' );
		$this->assets_enqueued = true;
	}

	private function attachment_url( int $attachment_id, string $fallback ): string {
		if ( $attachment_id > 0 && function_exists( 'wp_attachment_is_image' ) && wp_attachment_is_image( $attachment_id ) ) {
			$url = wp_get_attachment_image_url( $attachment_id, 'medium' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		return $fallback;
	}

	public function shortcode( $atts = array() ): string {
		if ( ! Settings::get( 'enabled', true ) || ! Settings::get( 'frontend_enabled', true ) || ! Settings::get( 'shortcode_enabled', true ) ) {
			return '';
		}
		$this->enqueue();
		$atts    = is_array( $atts ) ? $atts : array();
		$label   = (string) Settings::get( 'assistant_name', 'Shopping Assistant' );
		$uniq_id = ! empty( $atts['id'] ) ? sanitize_key( $atts['id'] ) : 'main';
		return '<button class="convocart-trigger" type="button" data-convocart-trigger="1" data-convocart-id="' . esc_attr( $uniq_id ) . '" aria-label="' . esc_attr( $label ) . '">' . esc_html( $label ) . '</button>';
	}

	public function launcher(): void {
		if ( ! $this->assets_enqueued || ! Settings::get( 'enabled', true ) ) {
			return;
		}
		if ( Settings::get( 'launcher_enabled', true ) ) {
			$launcher_label = sprintf( /* translators: %s: assistant name */ __( 'Ask %s', 'nexora-shopping-assistant' ), (string) Settings::get( 'assistant_name', 'Shopping Assistant' ) );
			echo '<button class="convocart-launcher" type="button" data-convocart-open aria-haspopup="dialog">' . esc_html( $launcher_label ) . '</button>';
		}
		echo '<div id="convocart-portal" class="convocart-portal" data-convocart-modal></div>';
	}
}
