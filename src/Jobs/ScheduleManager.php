<?php
namespace ConvoCart\Assistant\Jobs;

defined( 'ABSPATH' ) || exit;

final class ScheduleManager {
	private const GROUP = 'convocart';

	public static function clear_plugin_events(): int {
		$removed = 0;
		foreach ( array( 'convocart_cleanup', 'convocart_cleanup_continue', 'convocart_sync_product', 'convocart_sync_batch', 'convocart_sync_batch_v2', 'convocart_sync_recovery', 'convocart_check_provider_models', 'convocart_refresh_provider_models' ) as $hook ) {
			$removed += self::clear_wp_cron_hook( $hook );
			$removed += self::clear_action_scheduler_hook( $hook );
		}
		return $removed;
	}

	public static function schedule_single( int $timestamp, string $hook, array $args ): bool {
		$args = self::normalize_args( $args );
		if ( self::has_scheduled_action( $hook, $args ) ) {
			return true;
		}
		if ( self::action_scheduler_ready() && function_exists( 'as_schedule_single_action' ) ) {
			// Only pending work is deduplicated. A running callback must be able
			// to schedule its next retry/recovery with the same arguments.
			return (bool) as_schedule_single_action( $timestamp, $hook, $args, self::GROUP, false );
		}
		if ( wp_next_scheduled( $hook, $args ) ) {
			return true;
		}
		return (bool) wp_schedule_single_event( $timestamp, $hook, $args );
	}

	public static function has_scheduled_action( string $hook, array $args ): bool {
		$args = self::normalize_args( $args );
		if ( self::action_scheduler_ready() && function_exists( 'as_get_scheduled_actions' ) ) {
			$pending = as_get_scheduled_actions( array( 'hook' => $hook, 'args' => $args, 'group' => self::GROUP, 'status' => 'pending', 'per_page' => 1 ), 'ids' );
			if ( ! empty( $pending ) ) { return true; }
		}
		return function_exists( 'wp_next_scheduled' ) && (bool) wp_next_scheduled( $hook, $args );
	}

	public static function schedule_async( string $hook, array $args ): bool {
		$args = self::normalize_args( $args );
		if ( self::has_scheduled_action( $hook, $args ) ) {
			return true;
		}
		if ( self::action_scheduler_ready() && function_exists( 'as_enqueue_async_action' ) ) {
			return (bool) as_enqueue_async_action( $hook, $args, self::GROUP, false );
		}
		return self::schedule_single( time() + 30, $hook, $args );
	}

	private static function action_scheduler_ready(): bool {
		return function_exists( 'did_action' ) && did_action( 'action_scheduler_init' ) > 0;
	}

	private static function clear_wp_cron_hook( string $hook ): int {
		$removed = 0;
		$crons   = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		foreach ( is_array( $crons ) ? $crons : array() as $timestamp => $hooks ) {
			if ( empty( $hooks[ $hook ] ) || ! is_array( $hooks[ $hook ] ) ) {
				continue;
			}
			foreach ( $hooks[ $hook ] as $event ) {
				$args = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array();
				wp_unschedule_event( (int) $timestamp, $hook, $args );
				++$removed;
			}
		}
		return $removed;
	}

	private static function clear_action_scheduler_hook( string $hook ): int {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, null, self::GROUP );
			return 1;
		}
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'as_unschedule_action' ) ) {
			return 0;
		}
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => self::GROUP,
				'per_page' => -1,
				'status'   => array( 'pending', 'in-progress' ),
			),
			'ids'
		);
		$removed = 0;
		foreach ( is_array( $actions ) ? $actions : array() as $action_id ) {
			$args = function_exists( 'as_get_action_args' ) ? as_get_action_args( $action_id ) : array();
			as_unschedule_action( $hook, is_array( $args ) ? $args : array(), self::GROUP );
			++$removed;
		}
		return $removed;
	}

	private static function normalize_args( array $args ): array {
		return array_values( $args );
	}
}
