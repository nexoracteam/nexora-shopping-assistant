<?php
namespace ConvoCart\Assistant\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {
	public const VERSION = 6;
	private static ?string $migration_lock_token = null;

	public static function table( string $name ): string {
		global $wpdb;
		$allowed = array( 'conversations', 'messages', 'knowledge', 'sync_jobs', 'events', 'provider_usage', 'feedback' );
		return in_array( $name, $allowed, true ) ? $wpdb->prefix . 'convocart_' . $name : '';
	}

	public static function migrate(): array {
		return self::migrate_safe();
	}

	public static function migrate_safe(): array {
		global $wpdb;
		$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
			return self::fail( 'db_bootstrap_unavailable' );
		}
		if ( ! file_exists( $upgrade_file ) ) {
			return self::fail( 'upgrade_api_unavailable' );
		}
		require_once $upgrade_file;
		if ( ! function_exists( 'dbDelta' ) ) {
			return self::fail( 'dbdelta_unavailable' );
		}
		$lock = self::acquire_lock();
		if ( ! $lock['acquired'] ) {
			self::set_status( 'running', 'migration_locked' );
			return self::fail( 'migration_locked' );
		}
		self::set_status( 'running', '' );
		$charset = $wpdb->get_charset_collate();
		$queries = array(
			'CREATE TABLE ' . self::table( 'conversations' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				conversation_uuid char(36) NOT NULL,
				session_hash char(64) NOT NULL,
				user_id bigint(20) unsigned NULL,
				intent varchar(40) NOT NULL DEFAULT '',
				state varchar(32) NOT NULL DEFAULT 'new',
				provider varchar(32) NOT NULL DEFAULT '',
				model varchar(100) NOT NULL DEFAULT '',
				error_code varchar(64) NOT NULL DEFAULT '',
				started_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				completed_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY conversation_uuid (conversation_uuid),
				KEY session_hash (session_hash),
				KEY user_id (user_id),
				KEY intent (intent),
				KEY state (state),
				KEY started_at (started_at)
			) $charset;",
			'CREATE TABLE ' . self::table( 'messages' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				conversation_id bigint(20) unsigned NOT NULL,
				role varchar(20) NOT NULL,
				message_type varchar(32) NOT NULL DEFAULT 'text',
				content longtext NULL,
				structured_payload longtext NULL,
				provider_request_id varchar(128) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY conversation_id (conversation_id),
				KEY role (role),
				KEY created_at (created_at)
			) $charset;",
			'CREATE TABLE ' . self::table( 'knowledge' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_type varchar(32) NOT NULL,
				source_id bigint(20) unsigned NOT NULL,
				source_sub_id bigint(20) unsigned NOT NULL DEFAULT 0,
				title varchar(255) NOT NULL DEFAULT '',
				content longtext NULL,
				structured_payload longtext NULL,
				content_hash char(64) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'active',
				mapping_version int(10) unsigned NOT NULL DEFAULT 1,
				stale tinyint(1) unsigned NOT NULL DEFAULT 0,
				indexed_at datetime NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source (source_type,source_id,source_sub_id),
				KEY status (status),
				KEY content_hash (content_hash),
				KEY updated_at (updated_at)
			) $charset;",
			'CREATE TABLE ' . self::table( 'sync_jobs' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_uuid char(36) NOT NULL,
				job_type varchar(32) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'queued',
				cursor_payload longtext NULL,
				retry_payload longtext NULL,
				total_items bigint(20) unsigned NOT NULL DEFAULT 0,
				processed_items bigint(20) unsigned NOT NULL DEFAULT 0,
				failed_items bigint(20) unsigned NOT NULL DEFAULT 0,
				retry_count smallint(5) unsigned NOT NULL DEFAULT 0,
				product_retry_count smallint(5) unsigned NOT NULL DEFAULT 0,
				recovery_count smallint(5) unsigned NOT NULL DEFAULT 0,
				batch_count smallint(5) unsigned NOT NULL DEFAULT 0,
				worker_token char(36) NOT NULL DEFAULT '',
				claimed_at datetime NULL,
				lease_until datetime NULL,
				last_error text NULL,
				started_at datetime NULL,
				updated_at datetime NOT NULL,
				completed_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY job_uuid (job_uuid),
				KEY job_type (job_type),
				KEY status (status),
				KEY updated_at (updated_at)
			) $charset;",
			'CREATE TABLE ' . self::table( 'events' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				conversation_id bigint(20) unsigned NULL,
				session_hash char(64) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NULL,
				event_name varchar(40) NOT NULL,
				object_type varchar(32) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NULL,
				metadata longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY conversation_id (conversation_id),
				KEY session_hash (session_hash),
				KEY event_name (event_name),
				KEY object (object_type,object_id),
				KEY created_at (created_at)
			) $charset;",
			'CREATE TABLE ' . self::table( 'provider_usage' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				conversation_id bigint(20) unsigned NULL,
				provider varchar(32) NOT NULL,
				model varchar(100) NOT NULL DEFAULT '',
				request_type varchar(32) NOT NULL DEFAULT 'chat',
				prompt_tokens int(10) unsigned NOT NULL DEFAULT 0,
				completion_tokens int(10) unsigned NOT NULL DEFAULT 0,
				total_tokens int(10) unsigned NOT NULL DEFAULT 0,
				estimated_cost decimal(12,6) NULL DEFAULT NULL,
				latency_ms int(10) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'success',
				error_code varchar(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY provider (provider),
				KEY model (model),
				KEY status (status),
				KEY created_at (created_at)
			) $charset;",
			'CREATE TABLE ' . self::table( 'feedback' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				conversation_id bigint(20) unsigned NULL,
				session_hash char(64) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NULL,
				rating varchar(20) NOT NULL DEFAULT '',
				question text NULL,
				answer longtext NULL,
				comment text NULL,
				status varchar(20) NOT NULL DEFAULT 'new',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY conversation_id (conversation_id),
				KEY session_hash (session_hash),
				KEY rating (rating),
				KEY status (status),
				KEY created_at (created_at)
			) $charset;",
		);
		$result = array();
		try {
			foreach ( $queries as $query ) {
				$delta = dbDelta( $query );
				if ( ! is_array( $delta ) ) {
					self::set_status( 'failed', 'dbdelta_failed' );
					return self::fail( 'dbdelta_failed' );
				}
				$result[] = $delta;
				if ( ! empty( $wpdb->last_error ) ) { return self::fail( 'schema_write_failed' ); }
				if ( preg_match( '/CREATE TABLE ([a-z0-9_]+)\s*\(/i', $query, $match ) ) {
					$table = $match[1];
					$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
					preg_match_all( '/^\s*([a-z_]+)\s+(?:bigint|varchar|char|datetime|longtext|text|int|smallint|tinyint|decimal)\b/m', $query, $required );
					if ( ! is_array( $columns ) || array_diff( $required[1], $columns ) ) { return self::fail( 'schema_columns_missing' ); }
				}
			}

			$fulltext = self::maybe_add_fulltext_index();
			if ( ! $fulltext['success'] ) {
				self::set_status( 'failed', $fulltext['code'] );
				return $fulltext;
			}

			update_option( 'convocart_fulltext_available', ! empty( $fulltext['available'] ) ? 1 : 0, false );
			update_option( 'convocart_schema_version', self::VERSION, false );
			if ( (string) get_option( 'convocart_schema_version', '' ) !== (string) self::VERSION ) {
				self::set_status( 'failed', 'schema_version_update_failed' );
				return self::fail( 'schema_version_update_failed' );
			}
			self::set_status( 'success', '' );
			return array(
				'success' => true,
				'code'    => 'migrated',
				'result'  => $result,
			);
		} catch ( \Throwable $error ) {
			self::set_status( 'failed', 'migration_exception' );
			return self::fail( 'migration_exception' );
		} finally {
			self::release_lock();
		}
	}

	private static function acquire_lock(): array {
		$lock_key   = 'convocart_migration_lock';
		$stale_secs = 900;
		$now        = time();
		$existing   = get_option( $lock_key, array() );
		$existing   = is_array( $existing ) ? $existing : array();
		$locked_at  = isset( $existing['locked_at'] ) ? absint( $existing['locked_at'] ) : 0;
		if ( $locked_at > 0 && ( $now - $locked_at ) > $stale_secs ) {
			delete_option( $lock_key );
		}
		$token = wp_generate_uuid4();
		$state = array(
			'token'     => $token,
			'locked_at' => $now,
			'owner'     => is_string( wp_get_current_user()->user_login ?? '' ) ? (string) wp_get_current_user()->user_login : 'system',
		);
		if ( add_option( $lock_key, $state, '', false ) ) {
			self::$migration_lock_token = $token;
			return array( 'acquired' => true, 'token' => $token );
		}
		$existing = get_option( $lock_key, array() );
		$existing = is_array( $existing ) ? $existing : array();
		$locked_at = isset( $existing['locked_at'] ) ? absint( $existing['locked_at'] ) : 0;
		if ( $locked_at > 0 && ( $now - $locked_at ) > $stale_secs ) {
			delete_option( $lock_key );
			if ( add_option( $lock_key, $state, '', false ) ) {
				self::$migration_lock_token = $token;
				return array( 'acquired' => true, 'token' => $token );
			}
		}
		return array( 'acquired' => false, 'token' => '' );
	}

	private static function release_lock(): void {
		if ( '' === (string) self::$migration_lock_token ) {
			return;
		}
		$lock = get_option( 'convocart_migration_lock', array() );
		$lock = is_array( $lock ) ? $lock : array();
		if ( isset( $lock['token'] ) && hash_equals( (string) self::$migration_lock_token, (string) $lock['token'] ) ) {
			delete_option( 'convocart_migration_lock' );
		}
		self::$migration_lock_token = null;
	}

	private static function maybe_add_fulltext_index(): array {
		if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {
			return array( 'success' => true, 'code' => 'fulltext_unavailable', 'available' => false );
		}
		global $wpdb;
		$knowledge_table = self::table( 'knowledge' );
		if ( '' === $knowledge_table ) {
			return self::fail( 'knowledge_table_unavailable' );
		}
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $knowledge_table, 'knowledge_search' ) );
		if ( ! empty( $indexes ) ) {
			return array( 'success' => true, 'code' => 'fulltext_present', 'available' => true );
		}
		$previous_suppression = $wpdb->suppress_errors( true );
		// Schema change on the plugin's own table during a locked migration.
		$created = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD FULLTEXT INDEX knowledge_search (title, content)', $knowledge_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intended migration DDL.
		$error   = is_string( $wpdb->last_error ) ? strtolower( $wpdb->last_error ) : '';
		$wpdb->suppress_errors( $previous_suppression );
		if ( false === $created ) {
			if ( false !== strpos( $error, 'fulltext' ) || false !== strpos( $error, 'not supported' ) || false !== strpos( $error, 'doesn\'t support' ) ) {
				return array( 'success' => true, 'code' => 'fulltext_unavailable', 'available' => false );
			}
			return self::fail( 'fulltext_index_failed' );
		}
		return array( 'success' => true, 'code' => 'fulltext_created', 'available' => true );
	}

	private static function set_status( string $status, string $error_code ): void {
		update_option( 'convocart_migration_status', sanitize_key( $status ), false );
		update_option( 'convocart_migration_error', sanitize_key( $error_code ), false );
		update_option( 'convocart_migration_updated', current_time( 'mysql', true ), false );
	}

	private static function fail( string $code ): array {
		$status = 'migration_locked' === $code ? 'running' : 'failed';
		update_option( 'convocart_migration_status', $status, false );
		update_option( 'convocart_migration_error', sanitize_key( $code ), false );
		update_option( 'convocart_migration_updated', current_time( 'mysql', true ), false );
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Nexora Shopping Assistant] schema_migrate_failed=' . sanitize_key( $code ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostics.
		}
		return array(
			'success' => false,
			'code'    => sanitize_key( $code ),
		);
	}
}
