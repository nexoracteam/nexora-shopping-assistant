<?php
namespace ConvoCart\Assistant\Analytics;

use ConvoCart\Assistant\Database\Schema;
use ConvoCart\Assistant\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class AnalyticsService {
	private const EVENTS = array( 'conversation_started', 'intent_selected', 'product_impression', 'product_click', 'select_options', 'add_to_cart_success', 'add_to_cart_failure', 'zero_result', 'feedback_submitted' );

	public function record( string $event, string $session_hash = '', ?int $conversation_id = null, string $object_type = '', int $object_id = 0, array $metadata = array() ): bool {
		if ( ! Settings::get( 'anonymous_analytics', true ) || ! in_array( $event, self::EVENTS, true ) ) {
			return false;
		}
		global $wpdb;
		$user_id = is_user_logged_in() && Settings::get( 'logged_in_analytics', false ) ? get_current_user_id() : null;
		$object_value = $object_id > 0 ? $object_id : null;
		return false !== $wpdb->insert(
			Schema::table( 'events' ),
			array(
				'conversation_id' => $conversation_id,
				'session_hash'    => sanitize_text_field( $session_hash ),
				'user_id'         => $user_id,
				'event_name'      => $event,
				'object_type'     => sanitize_key( $object_type ),
				'object_id'       => $object_value,
				'metadata'        => wp_json_encode( $this->safe_metadata( $metadata ) ),
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	public function summary(): array {
		global $wpdb;
		$events = Schema::table( 'events' );
		return array(
			'total_events'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $events ) ),
			'unique_sessions' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT session_hash) FROM %i WHERE session_hash<>%s', $events, '' ) ),
			'product_clicks'  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_name=%s', $events, 'product_click' ) ),
			'cart_successes'  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_name=%s', $events, 'add_to_cart_success' ) ),
			'zero_results'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_name=%s', $events, 'zero_result' ) ),
		);
	}

	private function safe_metadata( array $metadata ): array {
		$allowed = array( 'intent', 'provider', 'result_count', 'error_code' );
		$result  = array();
		foreach ( $allowed as $key ) {
			if ( isset( $metadata[ $key ] ) && is_scalar( $metadata[ $key ] ) ) {
				$result[ $key ] = sanitize_text_field( (string) $metadata[ $key ] );
			}
		}
		return $result;
	}
}
