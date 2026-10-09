<?php
namespace ConvoCart\Assistant\Privacy;


use ConvoCart\Assistant\Database\Schema;
use ConvoCart\Assistant\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class PrivacyService {
	private ?string $cleanup_lock_token = null;

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	public function exporters( array $exporters ): array {
		$exporters['convocart'] = array(
			'exporter_friendly_name' => __( 'Nexora Shopping Assistant data', 'nexora-shopping-assistant' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	public function erasers( array $erasers ): array {
		$erasers['convocart'] = array(
			'eraser_friendly_name' => __( 'Nexora Shopping Assistant data', 'nexora-shopping-assistant' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	public function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$user = get_user_by( 'email', sanitize_email( $email ) );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}
		$limit = 100;
		$state = $this->export_state( $email, (int) $user->ID, $page );
		$data  = array();

		$conversation_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,intent,state,started_at,updated_at FROM %i WHERE user_id=%d AND id>%d ORDER BY id ASC LIMIT %d', Schema::table( 'conversations' ), (int) $user->ID, $state['conversations'], $limit ), ARRAY_A );
		foreach ( $conversation_rows as $row ) {
			$data[] = array(
				'name'  => __( 'Assistant conversation', 'nexora-shopping-assistant' ),
				'value' => sprintf(
					/* translators: %1$s start time, %2$s intent, %3$s state */
					__( 'Conversation started: %1$s. Intent: %2$s. Status: %3$s.', 'nexora-shopping-assistant' ),
					$row['started_at'] ?? '',
					$row['intent'] ?? '',
					$row['state'] ?? ''
				),
			);
			$state['conversations'] = max( $state['conversations'], absint( $row['id'] ?? 0 ) );
		}

		$messages = $wpdb->get_results( $wpdb->prepare( "SELECT m.id,m.role,m.message_type,m.content,m.created_at FROM %i m INNER JOIN %i c ON c.id=m.conversation_id WHERE c.user_id=%d AND m.id>%d AND m.content IS NOT NULL AND m.content<>'' ORDER BY m.id ASC LIMIT %d", Schema::table( 'messages' ), Schema::table( 'conversations' ), (int) $user->ID, $state['messages'], $limit ), ARRAY_A );
		foreach ( $messages as $message ) {
			$data[] = array(
				'name'  => __( 'Assistant message', 'nexora-shopping-assistant' ),
				'value' => sprintf( /* translators: %1$s: message role (user or assistant), %2$s: date and time, %3$s: message text. */ __( '%1$s message on %2$s: %3$s', 'nexora-shopping-assistant' ), sanitize_key( $message['role'] ?? '' ), sanitize_text_field( (string) ( $message['created_at'] ?? '' ) ), sanitize_textarea_field( (string) ( $message['content'] ?? '' ) ) ),
			);
			$state['messages'] = max( $state['messages'], absint( $message['id'] ?? 0 ) );
		}

		$events = $wpdb->get_results( $wpdb->prepare( 'SELECT e.id,e.event_name,e.object_type,e.created_at FROM %i e LEFT JOIN %i c ON c.id=e.conversation_id WHERE (c.user_id=%d OR e.user_id=%d) AND e.conversation_id IS NOT NULL AND e.id>%d ORDER BY e.id ASC LIMIT %d', Schema::table( 'events' ), Schema::table( 'conversations' ), (int) $user->ID, (int) $user->ID, $state['events'], $limit ), ARRAY_A );
		foreach ( $events as $event ) {
			$data[] = array(
				'name'  => __( 'Assistant event', 'nexora-shopping-assistant' ),
				'value' => sprintf( /* translators: %1$s: event name, %2$s: object type, %3$s: date and time. */ __( 'Event: %1$s. Object type: %2$s. Date: %3$s.', 'nexora-shopping-assistant' ), sanitize_key( $event['event_name'] ?? '' ), sanitize_key( $event['object_type'] ?? '' ), sanitize_text_field( (string) ( $event['created_at'] ?? '' ) ) ),
			);
			$state['events'] = max( $state['events'], absint( $event['id'] ?? 0 ) );
		}

		$usage = $wpdb->get_results( $wpdb->prepare( 'SELECT u.id,u.provider,u.model,u.status,u.error_code,u.created_at FROM %i u INNER JOIN %i c ON c.id=u.conversation_id WHERE c.user_id=%d AND u.id>%d ORDER BY u.id ASC LIMIT %d', Schema::table( 'provider_usage' ), Schema::table( 'conversations' ), (int) $user->ID, $state['provider_usage'], $limit ), ARRAY_A );
		foreach ( $usage as $row ) {
			$data[] = array(
				'name'  => __( 'AI provider usage', 'nexora-shopping-assistant' ),
				'value' => sprintf( /* translators: %1$s: AI provider, %2$s: model, %3$s: request status, %4$s: error code, %5$s: date and time. */ __( 'Provider: %1$s. Model: %2$s. Status: %3$s. Error code: %4$s. Date: %5$s.', 'nexora-shopping-assistant' ), sanitize_key( $row['provider'] ?? '' ), sanitize_text_field( (string) ( $row['model'] ?? '' ) ), sanitize_key( $row['status'] ?? '' ), sanitize_key( $row['error_code'] ?? '' ), sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ) ),
			);
			$state['provider_usage'] = max( $state['provider_usage'], absint( $row['id'] ?? 0 ) );
		}

		$direct_events = $wpdb->get_results( $wpdb->prepare( 'SELECT id,event_name,object_type,created_at FROM %i WHERE user_id=%d AND conversation_id IS NULL AND id>%d ORDER BY id ASC LIMIT %d', Schema::table( 'events' ), (int) $user->ID, $state['direct_events'], $limit ), ARRAY_A );
		foreach ( $direct_events as $event ) {
			$data[] = array(
				'name'  => __( 'Assistant account-linked event', 'nexora-shopping-assistant' ),
				'value' => sprintf( /* translators: %1$s: event name, %2$s: object type, %3$s: date and time. */ __( 'Event: %1$s. Object type: %2$s. Date: %3$s.', 'nexora-shopping-assistant' ), sanitize_key( $event['event_name'] ?? '' ), sanitize_key( $event['object_type'] ?? '' ), sanitize_text_field( (string) ( $event['created_at'] ?? '' ) ) ),
			);
			$state['direct_events'] = max( $state['direct_events'], absint( $event['id'] ?? 0 ) );
		}

		$feedback = $wpdb->get_results( $wpdb->prepare( 'SELECT f.id,f.question,f.answer,f.comment,f.rating,f.created_at FROM %i f LEFT JOIN %i c ON c.id=f.conversation_id WHERE (f.user_id=%d OR c.user_id=%d) AND f.id>%d ORDER BY f.id ASC LIMIT %d', Schema::table( 'feedback' ), Schema::table( 'conversations' ), (int) $user->ID, (int) $user->ID, $state['feedback'], $limit ), ARRAY_A );
		foreach ( (array) $feedback as $row ) {
			$data[] = array( 'name' => __( 'Assistant feedback', 'nexora-shopping-assistant' ), 'value' => sanitize_textarea_field( wp_json_encode( $row ) ) );
			$state['feedback'] = max( $state['feedback'], (int) $row['id'] );
		}
		foreach ( $conversation_rows as $row ) {
			$memory = \ConvoCart\Assistant\Conversation\ConversationMemory::get( (int) $row['id'] );
			if ( ! empty( $memory['messages'] ) ) { $data[] = array( 'name' => __( 'Temporary session memory', 'nexora-shopping-assistant' ), 'value' => wp_json_encode( $memory['messages'] ) ); }
		}
		$done = count( $conversation_rows ) < $limit && count( $messages ) < $limit && count( $events ) < $limit && count( $usage ) < $limit && count( $direct_events ) < $limit && count( $feedback ) < $limit;
		$this->save_export_state( $email, (int) $user->ID, $page, $state, $done );
		return array(
			'data' => array(
				array(
					'group_id'    => 'convocart',
					'group_label' => __( 'Nexora Shopping Assistant', 'nexora-shopping-assistant' ),
					'item_id'     => 'convocart-' . md5( sanitize_email( $email ) . '|' . absint( $user->ID ) . '|' . absint( $page ) ),
					'data'        => $data,
				),
			),
			'done' => $done,
		);
	}

	public function erase( string $email, int $page = 1 ): array {
		global $wpdb;
		$user = get_user_by( 'email', sanitize_email( $email ) );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		$limit = 50;
		$ids   = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id=%d ORDER BY id ASC LIMIT %d', Schema::table( 'conversations' ), (int) $user->ID, $limit ) );
		$ids   = array_map( 'absint', is_array( $ids ) ? $ids : array() );
		$retained = false;
		$removed  = 0;
		foreach ( $ids as $id ) {
			$feedback_deleted = $wpdb->delete( Schema::table( 'feedback' ), array( 'conversation_id' => (int) $id ), array( '%d' ) );
			\ConvoCart\Assistant\Conversation\ConversationMemory::delete( (int) $id );
			$usage_deleted = $wpdb->delete( Schema::table( 'provider_usage' ), array( 'conversation_id' => (int) $id ), array( '%d' ) );
			$messages_deleted = $wpdb->delete( Schema::table( 'messages' ), array( 'conversation_id' => (int) $id ), array( '%d' ) );
			$events_deleted = $wpdb->delete( Schema::table( 'events' ), array( 'conversation_id' => (int) $id ), array( '%d' ) );
			if ( false === $feedback_deleted || false === $usage_deleted || false === $messages_deleted || false === $events_deleted ) {
				$retained = true;
				continue;
			}
			$conversation_deleted = $wpdb->delete( Schema::table( 'conversations' ), array( 'id' => (int) $id ), array( '%d' ) );
			if ( false === $conversation_deleted ) {
				$retained = true;
				continue;
			}
			++$removed;
		}
		$direct_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id=%d ORDER BY id ASC LIMIT %d', Schema::table( 'events' ), (int) $user->ID, 100 ) );
		$direct_ids = array_map( 'absint', is_array( $direct_ids ) ? $direct_ids : array() );
		$direct_removed = 0;
		foreach ( $direct_ids as $event_id ) {
			$deleted = $wpdb->delete( Schema::table( 'events' ), array( 'id' => $event_id ), array( '%d' ) );
			if ( false === $deleted ) {
				$retained = true;
				continue;
			}
			$direct_removed += (int) $deleted;
		}
		$removed = $removed + $direct_removed;
		$feedback_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id=%d LIMIT 100', Schema::table( 'feedback' ), (int) $user->ID ) );
		foreach ( (array) $feedback_ids as $id ) {
			$deleted = $wpdb->delete( Schema::table( 'feedback' ), array( 'id' => (int) $id ), array( '%d' ) );
			if ( false === $deleted ) { $retained = true; } else { $removed += (int) $deleted; }
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'Some Nexora Shopping Assistant records could not be erased and will be retried on the next erasure pass.', 'nexora-shopping-assistant' ) ) : array(),
			'done'           => ! $retained && count( $ids ) < $limit && count( $direct_ids ) < 100 && count( (array) $feedback_ids ) < 100,
		);
	}

	public function policy_text(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				__( 'Nexora Shopping Assistant', 'nexora-shopping-assistant' ),
				__( 'Nexora Shopping Assistant sends messages and relevant store context to the store’s explicitly configured AI providers. Temporary conversation memory is stored in the database or object cache for up to 24 hours of inactivity. Longer-lived transcripts are stored only when conversation logging is enabled. Feedback submissions include the latest question, answer and optional comment, and may be emailed to the store. Session-linked interaction events and optionally account-linked records follow configured retention periods. Provider API keys are encrypted and never exported. WordPress export and erasure support account-linked records. Guest records cannot be located by email. Server log rotation and emailed copies are managed separately by the site owner. No external fonts or plugin-author-operated services are loaded.', 'nexora-shopping-assistant' )
			);
		}
	}

	private function export_state( string $email, int $user_id, int $page ): array {
		$key = $this->export_state_key( $email, $user_id );
		if ( 1 === absint( $page ) ) {
			delete_transient( $key );
		}
		$state = get_transient( $key );
		$state = is_array( $state ) ? $state : array();
		return wp_parse_args(
			$state,
			array(
				'conversations'  => 0,
				'messages'       => 0,
				'events'         => 0,
				'provider_usage' => 0,
				'direct_events'  => 0,
				'feedback'       => 0,
			)
		);
	}

	private function save_export_state( string $email, int $user_id, int $page, array $state, bool $done ): void {
		$key = $this->export_state_key( $email, $user_id );
		if ( $done ) {
			delete_transient( $key );
			return;
		}
		set_transient( $key, array_map( 'absint', $state ), HOUR_IN_SECONDS );
	}

	private function export_state_key( string $email, int $user_id ): string {
		return 'convocart_privacy_export_' . hash_hmac( 'sha256', sanitize_email( $email ) . '|' . absint( $user_id ), wp_salt( 'auth' ) );
	}

	public function cleanup(): int {
		global $wpdb;
		if ( ! $this->acquire_cleanup_lock() ) {
			return 0;
		}
		$removed = 0;
		try {
			$days   = max( 1, absint( Settings::get( 'conversation_retention', 30 ) ) );
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );
			$ids    = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE updated_at < %s LIMIT 200', Schema::table( 'conversations' ), $cutoff ) );
			foreach ( $ids as $id ) {
				$feedback_deleted = $wpdb->delete( Schema::table( 'feedback' ), array( 'conversation_id' => (int) $id ), array( '%d' ) );
				\ConvoCart\Assistant\Conversation\ConversationMemory::delete( (int) $id );
				$usage_deleted = $wpdb->delete( Schema::table( 'provider_usage' ), array( 'conversation_id' => (int) $id ), array( '%d' ) );
				$messages_deleted = $wpdb->delete( Schema::table( 'messages' ), array( 'conversation_id' => (int) $id ), array( '%d' ) );
				$events_deleted = $wpdb->delete( Schema::table( 'events' ), array( 'conversation_id' => (int) $id ), array( '%d' ) );
				$conversation_deleted = false;
				if ( false !== $feedback_deleted && false !== $usage_deleted && false !== $messages_deleted && false !== $events_deleted ) {
					$conversation_deleted = $wpdb->delete( Schema::table( 'conversations' ), array( 'id' => (int) $id ), array( '%d' ) );
				}
				if ( false !== $conversation_deleted ) {
					++$removed;
				} else {
					self::debug_log( 'privacy_cleanup_failed=db_delete_failed' );
				}
			}
			$event_days   = max( 1, absint( Settings::get( 'analytics_retention', 90 ) ) );
			$event_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $event_days ) );
			$events_removed = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s LIMIT 500', Schema::table( 'events' ), $event_cutoff ) );
			$usage_removed = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s LIMIT 500', Schema::table( 'provider_usage' ), $event_cutoff ) );
			$feedback_removed = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s LIMIT 500', Schema::table( 'feedback' ), $cutoff ) );
			$expired = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 500", $wpdb->esc_like( 'convocart_rl_' ) . '%_timeout', time() ) );
			foreach ( (array) $expired as $name ) { delete_option( substr( $name, 0, -8 ) ); delete_option( $name ); }
			$transients = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 500", $wpdb->esc_like( '_transient_timeout_convocart_' ) . '%', time() ) );
			foreach ( (array) $transients as $name ) { delete_transient( substr( $name, strlen( '_transient_timeout_' ) ) ); }
			// Cart reservations/results use a timestamp prefix and remain durable
			// for the full session lifetime, including uncertain/crashed operations.
			$locks = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 500", $wpdb->esc_like( 'convocart_cartlock_' ) . '%', time() - DAY_IN_SECONDS ) );
			foreach ( (array) $locks as $name ) { delete_option( $name ); }
			if ( count( $ids ) >= 200 || $events_removed >= 500 || $usage_removed >= 500 || $feedback_removed >= 500 || count( (array) $expired ) >= 500 || count( (array) $transients ) >= 500 || count( (array) $locks ) >= 500 ) {
				if ( ! wp_next_scheduled( 'convocart_cleanup_continue' ) ) { wp_schedule_single_event( time() + 60, 'convocart_cleanup_continue' ); }
			}
			if ( false === $events_removed || false === $usage_removed || false === $feedback_removed ) {
				self::debug_log( 'privacy_cleanup_failed=db_delete_failed' );
			}
			return $removed;
		} finally {
			$this->release_cleanup_lock();
		}
	}

	/**
	 * Write a diagnostic line only when the site enables debug logging.
	 *
	 * @param string $code Short status code; never contains personal data.
	 */
	private static function debug_log( string $code ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Nexora Shopping Assistant] ' . $code ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostics.
		}
	}

	private function acquire_cleanup_lock(): bool {
		$key = 'convocart_cleanup_lock';
		$now = time();
		$lock = get_option( $key, array() );
		$lock = is_array( $lock ) ? $lock : array();
		if ( ! empty( $lock['locked_at'] ) && $now - absint( $lock['locked_at'] ) > 300 ) {
			delete_option( $key );
		}
		$this->cleanup_lock_token = wp_generate_uuid4();
		$acquired = add_option( $key, array( 'locked_at' => $now, 'token' => $this->cleanup_lock_token ), '', false );
		if ( ! $acquired ) {
			$this->cleanup_lock_token = null;
		}
		return $acquired;
	}

	private function release_cleanup_lock(): void {
		$key = 'convocart_cleanup_lock';
		if ( '' === (string) $this->cleanup_lock_token ) {
			return;
		}
		$lock = get_option( $key, array() );
		$lock = is_array( $lock ) ? $lock : array();
		if ( isset( $lock['token'] ) && hash_equals( (string) $this->cleanup_lock_token, (string) $lock['token'] ) ) {
			delete_option( $key );
		}
		$this->cleanup_lock_token = null;
	}
}
