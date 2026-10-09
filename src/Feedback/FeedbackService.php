<?php
namespace ConvoCart\Assistant\Feedback;

use ConvoCart\Assistant\Database\Schema;
use ConvoCart\Assistant\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and reads customer "Help improve the assistant" feedback.
 *
 * Each row captures a 👍/👎 rating, an optional free-text comment, and — when
 * available — the customer's last question and the assistant's last answer, so
 * the shop can see feedback in the context that produced it.
 */
final class FeedbackService {
	private const RATINGS = array( 'helpful', 'not_helpful' );

	/**
	 * Persist a single feedback submission and optionally e-mail the shop.
	 *
	 * @param array<string,mixed> $data Keyed: conversation_id, session_hash, rating, question, answer, comment.
	 */
	public function record( array $data ): bool {
		$rating = sanitize_key( (string) ( $data['rating'] ?? '' ) );
		if ( ! in_array( $rating, self::RATINGS, true ) ) {
			return false;
		}
		global $wpdb;
		$user_id = is_user_logged_in() && Settings::get( 'logged_in_analytics', false ) ? get_current_user_id() : null;
		$row     = array(
			'conversation_id' => isset( $data['conversation_id'] ) && (int) $data['conversation_id'] > 0 ? (int) $data['conversation_id'] : null,
			'session_hash'    => sanitize_text_field( (string) ( $data['session_hash'] ?? '' ) ),
			'user_id'         => $user_id,
			'rating'          => $rating,
			'question'        => $this->clip( (string) ( $data['question'] ?? '' ), 1000 ),
			'answer'          => $this->clip( (string) ( $data['answer'] ?? '' ), 4000 ),
			'comment'         => $this->clip( (string) ( $data['comment'] ?? '' ), 2000 ),
			'status'          => 'new',
			'created_at'      => current_time( 'mysql', true ),
		);
		$inserted = false !== $wpdb->insert(
			Schema::table( 'feedback' ),
			$row,
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( $inserted ) {
			$this->maybe_notify( $row );
		}
		return $inserted;
	}

	/**
	 * Recent feedback rows, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );
		$rows   = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', Schema::table( 'feedback' ), $limit, $offset ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Aggregate counts for the admin dashboard.
	 *
	 * @return array<string,int>
	 */
	public function summary(): array {
		global $wpdb;
		$table        = Schema::table( 'feedback' );
		$total        = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		$helpful      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE rating=%s', $table, 'helpful' ) );
		$not_helpful  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE rating=%s', $table, 'not_helpful' ) );
		$with_comment = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE comment<>%s', $table, '' ) );
		return array(
			'total'        => $total,
			'helpful'      => $helpful,
			'not_helpful'  => $not_helpful,
			'with_comment' => $with_comment,
		);
	}

	public function clear(): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::table( 'feedback' ) ) );
	}

	private function maybe_notify( array $row ): void {
		if ( ! Settings::get( 'feedback_email_enabled', false ) ) {
			return;
		}
		$to = sanitize_email( (string) Settings::get( 'feedback_email', get_option( 'admin_email' ) ) );
		if ( '' === $to ) { $to = sanitize_email( (string) get_option( 'admin_email' ) ); }
		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}
		$rating  = 'helpful' === $row['rating'] ? __( 'Helpful (thumbs up)', 'nexora-shopping-assistant' ) : __( 'Not helpful (thumbs down)', 'nexora-shopping-assistant' );
		$subject = sprintf(
			/* translators: %s: rating label. */
			__( '[Nexora Shopping Assistant] New customer feedback — %s', 'nexora-shopping-assistant' ),
			$rating
		);
		$lines = array(
			__( 'A customer left feedback on the assistant.', 'nexora-shopping-assistant' ),
			'',
			__( 'Rating:', 'nexora-shopping-assistant' ) . ' ' . $rating,
			__( 'Question:', 'nexora-shopping-assistant' ) . ' ' . ( '' !== $row['question'] ? $row['question'] : __( '(not captured)', 'nexora-shopping-assistant' ) ),
			__( 'AI response:', 'nexora-shopping-assistant' ) . ' ' . ( '' !== $row['answer'] ? $row['answer'] : __( '(not captured)', 'nexora-shopping-assistant' ) ),
			__( 'Comment:', 'nexora-shopping-assistant' ) . ' ' . ( '' !== $row['comment'] ? $row['comment'] : __( '(none)', 'nexora-shopping-assistant' ) ),
			'',
			__( 'View all feedback:', 'nexora-shopping-assistant' ) . ' ' . admin_url( 'admin.php?page=convocart-feedback' ),
		);
		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	private function clip( string $value, int $max ): string {
		$value = trim( sanitize_textarea_field( $value ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max );
		}
		return substr( $value, 0, $max );
	}
}
