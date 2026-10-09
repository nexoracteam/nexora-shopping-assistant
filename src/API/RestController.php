<?php
namespace ConvoCart\Assistant\API;

use ConvoCart\Assistant\Analytics\AnalyticsService;
use ConvoCart\Assistant\Conversation\ConversationEngine;
use ConvoCart\Assistant\Conversation\SessionService;
use ConvoCart\Assistant\Feedback\FeedbackService;
use ConvoCart\Assistant\Knowledge\KnowledgeManager;
use ConvoCart\Assistant\Security\Capabilities;
use ConvoCart\Assistant\Support\Settings;
use ConvoCart\Assistant\WooCommerce\WooCommerceService;
use ConvoCart\Assistant\WooCommerce\CartRequestStore;
use ConvoCart\Assistant\AI\ProviderManager;
use ConvoCart\Assistant\AI\ProviderModelService;
use ConvoCart\Assistant\Container;

defined( 'ABSPATH' ) || exit;

final class RestController {
	private const NS = 'convocart/v1';
	private SessionService $sessions;

	public function __construct( private Container $container ) {
		$this->sessions = new SessionService();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route( self::NS, '/nonce', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'guest_nonce' ), 'permission_callback' => '__return_true' ) );
		register_rest_route(
			self::NS,
			'/bootstrap',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'bootstrap' ),
				'permission_callback' => array( $this, 'public_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/conversation/start',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start' ),
				'permission_callback' => array( $this, 'public_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/conversation/(?P<uuid>[a-f0-9-]{36})/message',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'message' ),
				'permission_callback' => array( $this, 'session_permission' ),
				'args'                => array(
					'message' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => static fn( $value ): bool => is_string( $value ) && strlen( $value ) <= (int) Settings::get( 'max_message_length', 2000 ),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/conversation/(?P<uuid>[a-f0-9-]{36})/feedback',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'feedback' ),
				'permission_callback' => array( $this, 'frontend_mutation_permission' ),
				'args'                => array(
					'feedback' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'helpful', 'not_helpful' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'comment'  => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => static fn( $value ): bool => is_string( $value ) && strlen( $value ) <= 2000,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/cart/add',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cart_add' ),
				'permission_callback' => array( $this, 'frontend_mutation_permission' ),
				'args'                => array(
					'product_id'   => array(
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'variation_id' => array(
						'type'              => 'integer',
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					),
					'quantity'     => array(
						'type'              => 'integer',
						'minimum'           => 1,
						'maximum'           => 99,
						'sanitize_callback' => 'absint',
					),
					'variation'    => array(
						'type'              => 'object',
						'sanitize_callback' => array( $this, 'sanitize_variation_attributes' ),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/events',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'event' ),
				'permission_callback' => array( $this, 'frontend_mutation_permission' ),
				'args'                => array(
					'event'       => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'product_impression', 'product_click', 'select_options', 'add_to_cart_success', 'add_to_cart_failure', 'zero_result', 'feedback_submitted' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'object_type' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'object_id'   => array(
						'type'              => 'integer',
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					),
					'metadata'    => array(
						'type'              => 'object',
						'sanitize_callback' => array( $this, 'sanitize_event_metadata' ),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/admin/providers/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'provider_status' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/admin/providers/(?P<provider>[a-z0-9_-]+)/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'provider_test' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/admin/providers/(?P<provider>[a-z0-9_-]+)/models',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'provider_models' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/admin/providers/(?P<provider>[a-z0-9_-]+)/model/validate',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'provider_model_validate' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/admin/knowledge/sync',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'knowledge_sync' ),
				'permission_callback' => array( $this, 'knowledge_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/admin/knowledge/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'knowledge_status' ),
				'permission_callback' => array( $this, 'knowledge_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/admin/system/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'system_status' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
	}

	public function public_permission(): bool {
		return true; }

	public function guest_nonce(): \WP_REST_Response {
		$response = new \WP_REST_Response( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ), 200 );
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
		return $response;
	}

	public function session_permission( \WP_REST_Request $request ) {
		$token = $this->token( $request );
		if ( ! $this->sessions->valid( $token ) ) {
			return new \WP_Error( 'convocart_invalid_session', __( 'Your assistant session has expired. Please start a new session.', 'nexora-shopping-assistant' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function frontend_mutation_permission( \WP_REST_Request $request ) {
		$session = $this->session_permission( $request );
		if ( true !== $session ) {
			return $session;
		}
		if ( ! $this->valid_rest_nonce( $request ) ) {
			return new \WP_Error( 'convocart_invalid_nonce', __( 'The request could not be verified. Please refresh and try again.', 'nexora-shopping-assistant' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function admin_permission( \WP_REST_Request $request ): bool {
		return $this->valid_rest_nonce( $request ) && current_user_can( Capabilities::MANAGE_PROVIDERS );
	}

	public function knowledge_permission( \WP_REST_Request $request ): bool {
		return $this->valid_rest_nonce( $request ) && current_user_can( Capabilities::MANAGE_KNOWLEDGE );
	}

	public function bootstrap(): \WP_REST_Response {
		nocache_headers();
		if ( ! $this->within_rate_limit( null, 'bootstrap', 30 ) ) {
			$response = new \WP_REST_Response(
				array(
					'code'    => 'convocart_rate_limited',
					'message' => __( 'Please wait before starting another assistant session.', 'nexora-shopping-assistant' ),
				),
				429
			);
			$response->header( 'Retry-After', '900' );
			return $response;
		}
		$settings = Settings::all();
		$response = new \WP_REST_Response(
			array(
				'session'   => $this->sessions->issue(),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'max_message_length' => (int) Settings::get( 'max_message_length', 2000 ),
				'privacy_notice' => (string) Settings::get( 'privacy_notice', '' ),
				'assistant' => array(
					'name'    => $settings['assistant_name'],
					'welcome' => $settings['welcome_message'],
					'enabled' => (bool) $settings['enabled'] && (bool) $settings['frontend_enabled'],
				),
				'features'  => array(
					'add_to_cart' => (bool) $settings['add_to_cart_enabled'],
					'analytics'   => (bool) $settings['anonymous_analytics'],
				),
				'rest'      => array( 'namespace' => self::NS ),
				'urls'      => array(
					'cart'     => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
					'checkout' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
				),
				'styles'    => array(
					'primary_color'       => $settings['primary_color'],
					'secondary_color'     => $settings['secondary_color'],
					'font_family'         => $settings['font_family'],
					'heading_font_family' => $settings['heading_font_family'] ?? "'Inter', sans-serif",
				),
				'suggestions' => $this->welcome_suggestions(),
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
		return $response;
	}

	/**
	 * Quick-entry prompts for the assistant welcome screen. Seeded from the
	 * store's featured/top products where available, with neutral fallbacks so
	 * the widget is useful on any catalogue.
	 *
	 * @return array<int, string>
	 */
	private function welcome_suggestions(): array {
		$chips = array();
		try {
			$woocommerce = $this->container->get( WooCommerceService::class );
			$products    = $woocommerce->search_products( array( 'featured' => true, 'limit' => 4 ) );
			if ( count( $products ) < 3 ) {
				$products = $woocommerce->search_products( array( 'limit' => 4 ) );
			}
			foreach ( (array) $products as $p ) {
				$name = trim( (string) ( $p['name'] ?? '' ) );
				if ( '' !== $name && ! in_array( $name, $chips, true ) ) {
					$chips[] = $name;
				}
			}
		} catch ( \Throwable $e ) {
			$chips = array();
		}

		if ( count( $chips ) >= 3 ) {
			return array_slice( $chips, 0, 4 );
		}

		return array(
			__( 'Browse bestsellers', 'nexora-shopping-assistant' ),
			__( "What's on sale?", 'nexora-shopping-assistant' ),
			__( 'Help me choose', 'nexora-shopping-assistant' ),
			__( 'New arrivals', 'nexora-shopping-assistant' ),
		);
	}

	public function start( \WP_REST_Request $request ) {
		if ( ! Settings::get( 'enabled', true ) ) {
			return new \WP_Error( 'convocart_disabled', __( 'The assistant is currently disabled.', 'nexora-shopping-assistant' ), array( 'status' => 403 ) );
		}
		if ( ! $this->within_rate_limit( $request, 'start', 5 ) ) {
			return new \WP_Error(
				'convocart_rate_limited',
				__( 'Please wait before starting another assistant session.', 'nexora-shopping-assistant' ),
				array(
					'status'      => 429,
					'retry_after' => 900,
				)
			);
		}
		$token = $this->token( $request );
		if ( ! $this->sessions->valid( $token ) ) {
			$token = $this->sessions->issue();
		}
		try {
			$data     = $this->engine()->start( $token );
			$response = new \WP_REST_Response( $data, 201 );
			$response->header( 'X-ConvoCart-Session', $token );
			return $response;
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'convocart_start_failed', __( 'The assistant could not start. Please try again.', 'nexora-shopping-assistant' ), array( 'status' => 500 ) );
		}
	}

	public function message( \WP_REST_Request $request ) {
		if ( ! Settings::get( 'enabled', true ) ) {
			return new \WP_Error( 'convocart_disabled', __( 'The assistant is currently disabled.', 'nexora-shopping-assistant' ), array( 'status' => 403 ) );
		}
		if ( ! $this->within_rate_limit( $request, 'message', 20 ) ) {
			return new \WP_Error(
				'convocart_rate_limited',
				__( 'You have reached the assistant limit. Please try again shortly.', 'nexora-shopping-assistant' ),
				array(
					'status'      => 429,
					'retry_after' => 900,
				)
			);
		}
		try {
			return new \WP_REST_Response( $this->engine()->respond( sanitize_text_field( $request['uuid'] ), $this->token( $request ), (string) $request->get_param( 'message' ) ), 200 );
		} catch ( \InvalidArgumentException $error ) {
			return new \WP_Error( 'convocart_invalid_message', __( 'Please enter a shorter message and try again.', 'nexora-shopping-assistant' ), array( 'status' => 400 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'convocart_message_failed', __( 'The assistant could not complete that request.', 'nexora-shopping-assistant' ), array( 'status' => 500 ) );
		}
	}

	public function feedback( \WP_REST_Request $request ) {
		if ( ! Settings::get( 'enabled', true ) ) {
			return new \WP_Error( 'convocart_disabled', __( 'The assistant is currently disabled.', 'nexora-shopping-assistant' ), array( 'status' => 403 ) );
		}
		if ( ! $this->within_rate_limit( $request, 'feedback', 10 ) ) {
			return new \WP_Error( 'convocart_rate_limited', __( 'Please wait before sending more feedback.', 'nexora-shopping-assistant' ), array( 'status' => 429, 'retry_after' => 900 ) );
		}
		$feedback = sanitize_key( $request->get_param( 'feedback' ) );
		if ( ! in_array( $feedback, array( 'helpful', 'not_helpful' ), true ) ) {
			return new \WP_Error( 'convocart_invalid_feedback', __( 'Invalid feedback.', 'nexora-shopping-assistant' ), array( 'status' => 400 ) );
		}
		$conversation_id = $this->engine()->owned_conversation_id( sanitize_text_field( (string) $request['uuid'] ), $this->token( $request ) );
		if ( $conversation_id <= 0 ) {
			return new \WP_Error( 'convocart_invalid_conversation', __( 'The conversation could not be verified.', 'nexora-shopping-assistant' ), array( 'status' => 403 ) );
		}
		$comment  = trim( (string) $request->get_param( 'comment' ) );
		$exchange = $this->engine()->last_exchange( $conversation_id );
		$saved = $this->feedback_service()->record(
			array(
				'conversation_id' => $conversation_id,
				'session_hash'    => $this->sessions->hash( $this->token( $request ) ),
				'rating'          => $feedback,
				'comment'         => $comment,
				'question'        => $exchange['question'] ?? '',
				'answer'          => $exchange['answer'] ?? '',
			)
		);
		if ( ! $saved ) { return new \WP_Error( 'convocart_feedback_failed', __( 'Feedback could not be saved. Please retry.', 'nexora-shopping-assistant' ), array( 'status' => 500 ) ); }
		$this->analytics()->record( 'feedback_submitted', $this->sessions->hash( $this->token( $request ) ), $conversation_id, 'conversation', 0, array( 'intent' => $feedback ) );
		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	public function cart_add( \WP_REST_Request $request ) {
		if ( ! Settings::get( 'enabled', true ) ) {
			return new \WP_Error( 'convocart_disabled', __( 'The assistant is currently disabled.', 'nexora-shopping-assistant' ), array( 'status' => 403 ) );
		}
		if ( ! $this->within_rate_limit( $request, 'cart', 10 ) ) {
			return new \WP_Error(
				'convocart_rate_limited',
				__( 'Please wait before adding more items from the assistant.', 'nexora-shopping-assistant' ),
				array(
					'status'      => 429,
					'retry_after' => 900,
				)
			);
		}
		if ( ! Settings::get( 'add_to_cart_enabled', true ) ) {
			return new \WP_Error( 'convocart_cart_disabled', __( 'Add to Cart is currently disabled.', 'nexora-shopping-assistant' ), array( 'status' => 403 ) );
		}
		$product_id   = absint( $request->get_param( 'product_id' ) );
		$variation_id = absint( $request->get_param( 'variation_id' ) );
		$quantity     = absint( $request->get_param( 'quantity' ) );
		if ( $quantity < 1 ) {
			$quantity = 1;
		}
		$variation    = $request->get_param( 'variation' );
		$variation    = is_array( $variation ) ? array_combine( array_map( 'sanitize_key', array_keys( $variation ) ), array_map( 'sanitize_text_field', array_values( $variation ) ) ) : array();
		if ( $product_id <= 0 || $quantity < 1 || $quantity > 99 ) {
			return new \WP_Error( 'convocart_invalid_cart_request', __( 'The product or quantity is invalid.', 'nexora-shopping-assistant' ), array( 'status' => 400 ) );
		}
		$idempotency = sanitize_text_field( (string) $request->get_header( 'X-ConvoCart-Idempotency-Key' ) );
		if ( strlen( $idempotency ) > 128 ) { return new \WP_Error( 'convocart_invalid_idempotency', __( 'Invalid cart request identifier.', 'nexora-shopping-assistant' ), array( 'status' => 400 ) ); }
		$request_hash = hash( 'sha256', wp_json_encode( array( $product_id, $quantity, $variation_id, $variation ) ) );
		$cart_request = null;
		if ( '' !== $idempotency ) {
			$digest    = hash( 'sha256', $this->sessions->hash( $this->token( $request ) ) . '|' . $idempotency );
			// Honor results written by 1.2.0 during an in-place update.
			$cached    = get_transient( 'convocart_cart_' . $digest );
			if ( is_array( $cached ) ) {
				if ( ( $cached['request_hash'] ?? '' ) !== $request_hash ) { return new \WP_Error( 'convocart_idempotency_conflict', __( 'This request identifier was already used for another cart action.', 'nexora-shopping-assistant' ), array( 'status' => 409 ) ); }
				return new \WP_REST_Response( $cached['response'], 200 );
			}
			$cart_request = new CartRequestStore();
			$replay = $cart_request->claim( $this->sessions->hash( $this->token( $request ) ), $idempotency, $request_hash );
			if ( null !== $replay ) { return $replay; }
		}
		try {
			$result = $this->container->get( WooCommerceService::class )->add_to_cart( $product_id, $quantity, $variation_id, $variation );
		} catch ( \Throwable $error ) {
			// Keep the reservation: an extension may have thrown after mutation.
			return new \WP_Error( 'convocart_cart_failed', __( 'Cart action failed. Check the cart before retrying.', 'nexora-shopping-assistant' ), array( 'status' => 500 ) );
		}
		if ( empty( $result['success'] ) ) {
			if ( $cart_request ) { $cart_request->release(); }
			return new \WP_Error(
				'convocart_cart_rejected',
				sanitize_text_field( (string) ( $result['message'] ?? __( 'WooCommerce could not add that product to the cart.', 'nexora-shopping-assistant' ) ) ),
				array(
					'status' => 409,
					'reason' => sanitize_key( $result['code'] ?? 'rejected' ),
				)
			);
		}
		$response = array(
			'success'   => true,
			'cart_key'  => $result['cart_key'],
			'cart_hash' => sanitize_text_field( (string) ( $result['cart_hash'] ?? '' ) ),
			'message'   => sanitize_text_field( (string) ( $result['message'] ?? __( 'Added to cart.', 'nexora-shopping-assistant' ) ) ),
			'fragments' => is_array( $result['fragments'] ?? null ) ? $result['fragments'] : array(),
		);
		if ( isset( $result['cart_count'] ) ) {
			$response['cart_count'] = absint( $result['cart_count'] );
		}
		if ( $cart_request && ! $cart_request->complete( $response ) ) {
			return new \WP_Error( 'convocart_cart_result_unknown', __( 'The cart changed but the confirmation could not be saved. Check your cart before trying a new action.', 'nexora-shopping-assistant' ), array( 'status' => 500 ) );
		}
		try {
			$this->analytics()->record( 'add_to_cart_success', $this->sessions->hash( $this->token( $request ) ), null, 'product', $product_id );
		} catch ( \Throwable $error ) {
			// Analytics must not turn a completed cart action into a retry.
		}
		return new \WP_REST_Response( $response, 200 );
	}

	public function event( \WP_REST_Request $request ) {
		if ( ! Settings::get( 'enabled', true ) ) {
			return new \WP_Error( 'convocart_disabled', __( 'The assistant is currently disabled.', 'nexora-shopping-assistant' ), array( 'status' => 403 ) );
		}
		if ( ! $this->within_rate_limit( $request, 'event', 30 ) ) {
			return new \WP_Error(
				'convocart_rate_limited',
				__( 'Please wait before sending more events.', 'nexora-shopping-assistant' ),
				array(
					'status'      => 429,
					'retry_after' => 900,
				)
			);
		}
		$event = sanitize_key( $request->get_param( 'event' ) );
		if ( ! in_array( $event, array( 'product_impression', 'product_click', 'select_options', 'add_to_cart_failure' ), true ) ) {
			return new \WP_Error( 'convocart_server_event', __( 'This event is recorded by the server.', 'nexora-shopping-assistant' ), array( 'status' => 400 ) );
		}
		if ( '' === $event ) {
			return new \WP_Error( 'convocart_invalid_event', __( 'Invalid analytics event.', 'nexora-shopping-assistant' ), array( 'status' => 400 ) );
		}
		$this->analytics()->record( $event, $this->sessions->hash( $this->token( $request ) ), null, sanitize_key( $request->get_param( 'object_type' ) ), absint( $request->get_param( 'object_id' ) ), (array) $request->get_param( 'metadata' ) );
		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	public function provider_status(): \WP_REST_Response {
		return new \WP_REST_Response( $this->container->get( ProviderManager::class )->health(), 200 ); }

	public function provider_test( \WP_REST_Request $request ) {
		if ( ! $this->within_rate_limit( $request, 'provider_test', 10 ) ) { return new \WP_Error( 'convocart_rate_limited', __( 'Please wait before testing again.', 'nexora-shopping-assistant' ), array( 'status' => 429 ) ); }
		$provider_id = sanitize_key( $request['provider'] );
		$provider    = $this->container->get( ProviderManager::class )->providers()[ $provider_id ] ?? null;
		if ( ! $provider ) {
			return new \WP_Error( 'convocart_unknown_provider', __( 'Unknown provider.', 'nexora-shopping-assistant' ), array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( $provider->test_connection(), 200 );
	}

	public function provider_model_validate( \WP_REST_Request $request ): \WP_REST_Response {
		$provider_id = sanitize_key( $request['provider'] );
		$status = Settings::provider_model_status( $provider_id );
		$status['last_test'] = get_transient( 'convocart_test_' . $provider_id ) ?: null;
		return new \WP_REST_Response( $status, 200 );
	}

	public function provider_models( \WP_REST_Request $request ): \WP_REST_Response {
		$provider_id = sanitize_key( $request['provider'] );
		$refresh = (bool) $request->get_param( 'refresh' );
		return new \WP_REST_Response( ( new ProviderModelService() )->list_models( $provider_id, $refresh ), 200 );
	}

	public function knowledge_sync() {
		$knowledge = $this->container->get( KnowledgeManager::class );
		try {
			return new \WP_REST_Response(
				array(
					'queued'   => true,
					'job_uuid' => $knowledge->queue_full_sync(),
				),
				202
			);
		} catch ( \RuntimeException $error ) {
			$existing = $knowledge->active_full_sync_uuid();
			$status   = false !== strpos( $error->getMessage(), 'lock' ) ? 409 : 500;
			$code     = 409 === $status ? 'convocart_sync_already_running' : 'convocart_sync_queue_failed';
			$message  = 409 === $status ? __( 'A product sync is already running. Wait for it to finish or retry shortly.', 'nexora-shopping-assistant' ) : __( 'The product sync could not be queued. Please try again shortly.', 'nexora-shopping-assistant' );
			return new \WP_Error(
				$code,
				$message,
				array(
					'status'            => $status,
					'existing_job_uuid' => $existing,
				)
			);
		}
	}

	public function knowledge_status(): \WP_REST_Response {
		return new \WP_REST_Response( $this->container->get( KnowledgeManager::class )->status(), 200 ); }

	public function system_status(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'plugin_version'             => CONVOCART_VERSION,
				'wordpress_version'          => get_bloginfo( 'version' ),
				'php_version'                => PHP_VERSION,
				'woocommerce_version'        => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'https'                      => is_ssl(),
				'rest_url'                   => esc_url_raw( rest_url( self::NS ) ),
				'action_scheduler'           => function_exists( 'as_enqueue_async_action' ),
				'gemini_model'               => Settings::provider_model_status( 'gemini' ),
				'settings_migration'         => get_option( Settings::MIGRATION_OPTION, array() ),
				'wp_cron'                    => ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON,
				'encryption_key_fingerprint' => ( new \ConvoCart\Assistant\AI\Encryption\EncryptionService() )->fingerprint(),
			),
			200
		);
	}

	private function engine(): ConversationEngine {
		return $this->container->get( ConversationEngine::class ); }
	private function analytics(): AnalyticsService {
		return $this->container->get( AnalyticsService::class ); }
	private function feedback_service(): FeedbackService {
		return $this->container->get( FeedbackService::class ); }
	private function token( \WP_REST_Request $request ): string {
		return sanitize_text_field( (string) $request->get_header( 'X-ConvoCart-Session' ) ); }
	private function valid_rest_nonce( \WP_REST_Request $request ): bool {
		return (bool) wp_verify_nonce( sanitize_text_field( (string) $request->get_header( 'X-WP-Nonce' ) ), 'wp_rest' ); }

	private function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$remote = filter_var( $remote, FILTER_VALIDATE_IP ) ?: 'unknown';
		if ( 'unknown' === $remote || ! $this->trusted_proxy( (string) $remote ) ) {
			return (string) $remote;
		}
		$raw = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';
		if ( '' === $raw && isset( $_SERVER['HTTP_X_REAL_IP'] ) ) { $raw = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) ); }
		// Walk right-to-left through known proxies; the first untrusted hop is
		// the client. Never trust a spoofable leftmost value supplied upstream.
		foreach ( array_reverse( array_map( 'trim', explode( ',', $raw ) ) ) as $candidate ) {
			$ip = filter_var( $candidate, FILTER_VALIDATE_IP );
			if ( false === $ip ) { return (string) $remote; }
			if ( ! $this->trusted_proxy( (string) $ip ) ) { return (string) $ip; }
		}
		return (string) $remote;
	}

	private function within_rate_limit( ?\WP_REST_Request $request, string $scope = 'message', int $limit = 20 ): bool {
		$window  = (int) floor( time() / 900 ) * 900;
		$session = $request && $this->sessions->valid( $this->token( $request ) ) ? $this->sessions->hash( $this->token( $request ) ) : '';
		$user    = is_user_logged_in() ? (string) get_current_user_id() : '';
		$checks  = array(
			'ip' => $this->client_ip(),
		);
		if ( '' !== $session ) {
			$checks['session'] = $session;
		}
		if ( '' !== $user ) {
			$checks['user'] = $user;
		}
		foreach ( $checks as $dimension => $value ) {
			$key   = 'convocart_rl_' . hash_hmac( 'sha256', sanitize_key( $scope ) . '|' . sanitize_key( $dimension ) . '|' . $value . '|' . gmdate( 'YmdHi', $window ), wp_salt( 'auth' ) );
			$count = $this->increment_rate_counter( $key, 15 * MINUTE_IN_SECONDS );
			if ( $count > $limit ) {
				return false;
			}
		}
		return true;
	}

	private function increment_rate_counter( string $key, int $ttl ): int {
		$group = 'convocart';
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, $group, $ttl );
			$count = wp_cache_incr( $key, 1, $group );
			if ( false !== $count ) {
				return (int) $count;
			}
		}
		global $wpdb;
		$count_key   = 'convocart_rl_' . substr( md5( $key ), 0, 32 );
		$timeout_key = $count_key . '_timeout';
		$timeout     = (int) get_option( $timeout_key, 0 );
		if ( $timeout > 0 && $timeout < time() ) {
			delete_option( $count_key );
			delete_option( $timeout_key );
		}
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
				$count_key
			)
		);
		if ( ! add_option( $timeout_key, time() + $ttl, '', false ) && (int) get_option( $timeout_key, 0 ) <= 0 ) {
			update_option( $timeout_key, time() + $ttl, false );
		}
		wp_cache_delete( $count_key, 'options' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $count_key ) );
		if ( $count <= 0 ) { return PHP_INT_MAX; }
		return max( 1, $count );
	}

	private function trusted_proxy( string $ip ): bool {
		$ranges = defined( 'CONVOCART_TRUSTED_PROXIES' ) ? constant( 'CONVOCART_TRUSTED_PROXIES' ) : array();
		$ranges = is_array( $ranges ) ? $ranges : array_filter( array_map( 'trim', explode( ',', (string) $ranges ) ) );
		foreach ( $ranges as $range ) {
			if ( $this->ip_in_range( $ip, (string) $range ) ) {
				return true;
			}
		}
		return false;
	}

	private function ip_in_range( string $ip, string $range ): bool {
		if ( '' === $range ) {
			return false;
		}
		if ( false === strpos( $range, '/' ) ) {
			return hash_equals( $range, $ip );
		}
		list( $subnet, $bits ) = explode( '/', $range, 2 );
		$ip_bin     = inet_pton( $ip );
		$subnet_bin = inet_pton( $subnet );
		$bits       = absint( $bits );
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) || $bits > strlen( $ip_bin ) * 8 ) {
			return false;
		}
		$bytes = intdiv( $bits, 8 );
		$rest  = $bits % 8;
		if ( $bytes && substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ~ ( ( 1 << ( 8 - $rest ) ) - 1 ) & 0xff;
		return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $subnet_bin[ $bytes ] ) & $mask );
	}

	public function sanitize_variation_attributes( $value ): array {
		$value = is_array( $value ) ? $value : array();
		$result = array();
		foreach ( $value as $key => $attribute ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || 0 !== strpos( $key, 'attribute_' ) ) {
				continue;
			}
			$result[ $key ] = sanitize_text_field( (string) $attribute );
		}
		return $result;
	}

	public function sanitize_event_metadata( $value ): array {
		$value = is_array( $value ) ? $value : array();
		$result = array();
		foreach ( array( 'intent', 'provider', 'result_count', 'error_code' ) as $key ) {
			if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) {
				$result[ $key ] = sanitize_text_field( (string) $value[ $key ] );
			}
		}
		return $result;
	}
}
