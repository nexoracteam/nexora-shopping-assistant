<?php
namespace ConvoCart\Assistant\WooCommerce;

defined( 'ABSPATH' ) || exit;

/** Durable, session-scoped cart replay records. Never steal an uncertain operation. */
final class CartRequestStore {
	private string $key = '';
	private string $reservation = '';
	private array $record = array();

	/** Null means this request owns the reservation and may mutate the cart. */
	public function claim( string $session_hash, string $idempotency, string $request_hash ): \WP_REST_Response|\WP_Error|null {
		$this->key = 'convocart_cartlock_' . hash( 'sha256', $session_hash . '|' . $idempotency );
		$this->record = array( 'owner' => wp_generate_uuid4(), 'request_hash' => $request_hash );
		$this->reservation = time() . '|' . wp_json_encode( $this->record );
		if ( add_option( $this->key, $this->reservation, '', false ) ) {
			return null;
		}
		// Read the authoritative row, not a request-local/object-cache snapshot.
		// A winner may have completed between our failed INSERT and this read.
		global $wpdb;
		$stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $this->key ) );
		$parts = explode( '|', $stored, 2 );
		$record = isset( $parts[1] ) ? json_decode( $parts[1], true ) : null;
		$this->reservation = '';
		if ( is_array( $record ) && ( $record['request_hash'] ?? '' ) !== $request_hash ) {
			return new \WP_Error( 'convocart_idempotency_conflict', __( 'This request identifier was already used for another cart action.', 'nexora-shopping-assistant' ), array( 'status' => 409 ) );
		}
		if ( is_array( $record['response'] ?? null ) ) {
			return new \WP_REST_Response( array_merge( $record['response'], array( 'replayed' => true ) ), 200 );
		}
		// Includes legacy locks, crashed workers, and uncertain post-mutation
		// failures. A timeout is not proof that the item was never added.
		return new \WP_Error( 'convocart_cart_in_progress', __( 'This cart request is pending or its outcome is unknown. Check your cart before trying a new action.', 'nexora-shopping-assistant' ), array( 'status' => 409, 'retry_after' => 2 ) );
	}

	public function complete( array $response ): bool {
		if ( '' === $this->reservation ) { return false; }
		$record = $this->record;
		// Never retain mini-cart HTML (which may contain extension/customer data)
		// or replay stale fragments over a shopper's current cart display.
		unset( $response['fragments'] );
		$record['response'] = $response;
		$value = explode( '|', $this->reservation, 2 )[0] . '|' . wp_json_encode( $record );
		global $wpdb;
		$saved = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s", $value, $this->key, $this->reservation ) );
		wp_cache_delete( $this->key, 'options' );
		return 1 === $saved;
	}

	/** Release only a definite pre-mutation rejection, and only our own row. */
	public function release(): void {
		if ( '' === $this->reservation ) { return; }
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $this->key, $this->reservation ) );
		wp_cache_delete( $this->key, 'options' );
	}
}
