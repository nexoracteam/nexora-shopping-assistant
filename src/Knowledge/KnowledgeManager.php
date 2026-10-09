<?php
namespace ConvoCart\Assistant\Knowledge;

use ConvoCart\Assistant\Database\Schema;
use ConvoCart\Assistant\Jobs\ScheduleManager;
use ConvoCart\Assistant\WooCommerce\WooCommerceService;

defined( 'ABSPATH' ) || exit;

final class KnowledgeManager {
	private const MAX_PRODUCT_RETRIES = 3;
	private const MAX_JOB_RECOVERIES = 5;
	private const WORKER_LEASE_SECONDS = 300;
	private ?string $sync_lock_token = null;
	public function __construct( private WooCommerceService $woocommerce ) {}

	public function register(): void {
		add_action( 'save_post_product', array( $this, 'queue_product' ), 20, 2 );
		add_action( 'save_post_product_variation', array( $this, 'queue_variation' ), 20, 2 );
		add_action( 'before_delete_post', array( $this, 'delete_product' ) );
		add_action( 'convocart_sync_product', array( $this, 'sync_product' ) );
		add_action( 'convocart_sync_batch_v2', array( $this, 'sync_batch' ), 10, 2 );
		add_action( 'convocart_sync_recovery', array( $this, 'recover_stuck_jobs' ) );
		add_action( 'woocommerce_update_product', array( $this, 'queue_product_id' ) );
		add_action( 'woocommerce_update_product_variation', array( $this, 'queue_variation_id' ) );
		add_action( 'woocommerce_product_set_stock', array( $this, 'queue_product_object' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'queue_product_object' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'queue_product_object' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'queue_variation_id' ) );
		$this->ensure_recovery_schedule();
	}

	public function queue_product( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || 'product' !== $post->post_type || 'auto-draft' === $post->post_status ) {
			return;
		}
		$this->queue_product_id( $post_id );
	}

	public function queue_variation( int $post_id, \WP_Post $post ): void {
		if ( 'product_variation' !== $post->post_type ) {
			return;
		}
		$parent_id = (int) wp_get_post_parent_id( $post_id );
		if ( $parent_id > 0 ) {
			$this->queue_product_id( $parent_id );
		}
	}

	public function queue_variation_id( int $variation_id ): void {
		$parent_id = (int) wp_get_post_parent_id( $variation_id );
		if ( $parent_id > 0 ) {
			$this->queue_product_id( $parent_id );
		}
	}

	public function queue_product_object( $product ): void {
		if ( is_numeric( $product ) ) {
			$this->queue_product_id( absint( $product ) );
			return;
		}
		if ( is_object( $product ) && is_callable( array( $product, 'get_id' ) ) ) {
			$product_id = (int) $product->get_id();
			$parent_id  = is_callable( array( $product, 'get_parent_id' ) ) ? (int) $product->get_parent_id() : 0;
			$this->queue_product_id( $parent_id > 0 ? $parent_id : $product_id );
		}
	}

	public function queue_product_id( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}
		ScheduleManager::schedule_async( 'convocart_sync_product', array( 'product_id' => $post_id ) );
	}

	public function delete_product( int $post_id ): void {
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deleting rows from the plugin's own indexed table.
		$wpdb->delete(
			Schema::table( 'knowledge' ),
			array(
				'source_type' => 'product',
				'source_id'   => $post_id,
			),
			array( '%s', '%d' )
		);
	}

	public function sync_product( int $product_id ): bool {
		$product = $this->woocommerce->get_product_object( $product_id );
		if ( ! $product || 'publish' !== get_post_status( $product_id ) ) {
			$this->delete_product( $product_id );
			return true;
		}
		$public = $this->woocommerce->get_product( $product_id );
		if ( ! $public ) {
			$this->delete_product( $product_id );
			return true;
		}
		$categories  = $this->term_slugs( $product_id, 'product_cat' );
		$tags        = $this->term_slugs( $product_id, 'product_tag' );
		$payload = array(
			'id'          => $public['id'],
			'type'        => $public['type'],
			'price'       => $public['price'],
			'on_sale'     => $public['on_sale'],
			'in_stock'    => $public['in_stock'],
			'purchasable' => $public['purchasable'],
			'categories'  => $categories,
			'tags'        => $tags,
		);
		$content = wp_strip_all_tags( $product->get_name() . ' ' . $product->get_short_description() . ' ' . $product->get_description() . ' ' . implode( ' ', array_merge( (array) $categories, (array) $tags ) ) );
		$hash    = hash( 'sha256', wp_json_encode( array( $content, $payload ) ) );
		$now     = current_time( 'mysql', true );
		global $wpdb;
		$written = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (source_type,source_id,source_sub_id,title,content,structured_payload,content_hash,status,mapping_version,stale,indexed_at,updated_at) VALUES (%s,%d,0,%s,%s,%s,%s,%s,1,0,%s,%s) ON DUPLICATE KEY UPDATE title=VALUES(title),content=VALUES(content),structured_payload=VALUES(structured_payload),content_hash=VALUES(content_hash),status=VALUES(status),stale=0,indexed_at=VALUES(indexed_at),updated_at=VALUES(updated_at)',
				Schema::table( 'knowledge' ),
				'product',
				$product_id,
				$product->get_name(),
				$content,
				wp_json_encode( $payload ),
				$hash,
				'active',
				$now,
				$now
			)
		);
		return false !== $written;
	}

	public function search( string $query, int $limit = 6, array $safety_context = array() ): array {
		global $wpdb;
		$query  = sanitize_text_field( $query );
		$table  = Schema::table( 'knowledge' );
		$limit  = min( 20, max( 1, $limit ) );
		$sql_limit = $limit;
		$rows   = array();
		$tokens = $this->tokens( $query );

		if ( ! empty( $tokens ) && $this->fulltext_available() ) {
			$ft_query = implode( ' ', array_map( static fn( string $token ): string => $token . '*', $tokens ) );

			if ( '' !== $ft_query ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT structured_payload, MATCH(title, content) AGAINST(%s IN BOOLEAN MODE) AS score FROM %i WHERE status=%s AND stale=0 AND MATCH(title, content) AGAINST(%s IN BOOLEAN MODE) ORDER BY score DESC,indexed_at DESC LIMIT %d',
						$ft_query,
						$table,
						'active',
						$ft_query,
						$sql_limit
					),
					ARRAY_A
				);
			}
		}

		// Fallback to LIKE for short queries or when FULLTEXT returns nothing.
		if ( empty( $rows ) && ! empty( $tokens ) ) {
			// Fixed-shape query: up to six tokens; unused slots repeat the first token,
			// which leaves the OR result unchanged.
			$terms = array_slice( $tokens, 0, 6 );
			$terms = array_pad( $terms, 6, $terms[0] );
			$likes = array();
			foreach ( $terms as $token ) {
				$like    = '%' . $wpdb->esc_like( $token ) . '%';
				$likes[] = $like;
				$likes[] = $like;
				$likes[] = $like;
			}
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT structured_payload FROM %i WHERE status=%s AND stale=0 AND (
						(title LIKE %s OR content LIKE %s OR structured_payload LIKE %s)
						OR (title LIKE %s OR content LIKE %s OR structured_payload LIKE %s)
						OR (title LIKE %s OR content LIKE %s OR structured_payload LIKE %s)
						OR (title LIKE %s OR content LIKE %s OR structured_payload LIKE %s)
						OR (title LIKE %s OR content LIKE %s OR structured_payload LIKE %s)
						OR (title LIKE %s OR content LIKE %s OR structured_payload LIKE %s)
					) ORDER BY indexed_at DESC LIMIT %d',
					$table,
					'active',
					$likes[0],
					$likes[1],
					$likes[2],
					$likes[3],
					$likes[4],
					$likes[5],
					$likes[6],
					$likes[7],
					$likes[8],
					$likes[9],
					$likes[10],
					$likes[11],
					$likes[12],
					$likes[13],
					$likes[14],
					$likes[15],
					$likes[16],
					$likes[17],
					$sql_limit
				),
				ARRAY_A
			);
		}

		$results = array();
		foreach ( $rows as $row ) {
			$data = json_decode( (string) $row['structured_payload'], true );
			if ( is_array( $data ) ) {
				$product = $this->woocommerce->get_product( absint( $data['id'] ?? 0 ) );
				if ( $product ) {
					$results[] = $product;
				}
			}
		}
		return array_slice( $results, 0, $limit );
	}

	private function tokens( string $query ): array {
		$stop = array( 'a', 'an', 'and', 'are', 'as', 'at', 'for', 'from', 'i', 'in', 'is', 'it', 'me', 'my', 'of', 'on', 'or', 'the', 'to', 'with', 'would', 'want', 'need', 'please', 'show', 'give', 'some', 'can', 'you', 'your', 'have', 'what', 'not', 'but', 'just', 'like', 'any', 'all', 'get', 'got', 'that', 'this', 'them', 'those', 'these', 'how', 'let' );
		$raw  = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( remove_accents( $query ) ) );
		$raw  = is_array( $raw ) ? $raw : array();
		$raw  = array_filter(
			$raw,
			static function ( string $token ) use ( $stop ): bool {
				return strlen( $token ) >= 2 && ! in_array( $token, $stop, true );
			}
		);
		return array_slice( array_values( array_unique( $raw ) ), 0, 12 );
	}

	private function term_slugs( int $product_id, string $taxonomy ): array {
		$terms = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_title', $terms ) ) );
	}

	private function fulltext_available(): bool {
		if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) { return false; }
		$stored = get_option( 'convocart_fulltext_available', null );
		if ( null !== $stored ) {
			return (bool) $stored;
		}
		global $wpdb;
		$table = Schema::table( 'knowledge' );
		if ( '' === $table ) {
			return false;
		}
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'knowledge_search' ) );
		$available = ! empty( $indexes ) && ! is_wp_error( $indexes );
		update_option( 'convocart_fulltext_available', $available ? 1 : 0, false );
		return $available;
	}

	public function sync_all( int $limit = 100 ): int {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return 0;
		}
		$products = wc_get_products(
			array(
				'limit'  => min( 500, max( 1, $limit ) ),
				'status' => 'publish',
				'return' => 'objects',
			)
		);
		$count    = 0;
		foreach ( $products as $product ) {
			if ( $this->sync_product( (int) $product->get_id() ) ) {
				++$count;
			}
		}
		return $count;
	}

	public function queue_full_sync(): string {
		global $wpdb;
		$this->recover_stuck_jobs();
		if ( ! $this->acquire_full_sync_lock() ) {
			$existing = $this->active_full_sync_job();
			if ( is_array( $existing ) && ! empty( $existing['job_uuid'] ) ) {
				return sanitize_text_field( (string) $existing['job_uuid'] );
			}
			throw new \RuntimeException( 'Full sync lock is already held.' );
		}
		try {
			$existing = $this->active_full_sync_job();
			if ( is_array( $existing ) && ! empty( $existing['job_uuid'] ) ) {
				if ( ! $this->schedule_batch( sanitize_text_field( (string) $existing['job_uuid'] ), absint( $existing['cursor'] ?? 0 ), 10 ) ) {
					throw new \RuntimeException( 'Could not schedule existing sync job.' );
				}
				return sanitize_text_field( (string) $existing['job_uuid'] );
			}
			$job_uuid = wp_generate_uuid4();
			$total    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s", 'product', 'publish' ) );
			$inserted = $wpdb->insert(
				Schema::table( 'sync_jobs' ),
				array(
					'job_uuid'       => $job_uuid,
					'job_type'       => 'product_full',
					'status'         => 'queued',
					'cursor_payload' => wp_json_encode( array( 'cursor' => 0, 'catalog_complete' => false, 'pending_ids' => array(), 'retry_ids' => array(), 'failed_ids' => array() ) ),
					'retry_payload'  => wp_json_encode( array() ),
					'total_items'    => $total,
					'processed_items' => 0,
					'failed_items'    => 0,
					'retry_count'     => 0,
					'batch_count'     => 0,
					'updated_at'      => current_time( 'mysql', true ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s' )
			);
			if ( false === $inserted ) {
				throw new \RuntimeException( 'Could not create sync job.' );
			}
			if ( ! $this->schedule_batch( $job_uuid, 0, 10 ) ) {
				$wpdb->update( Schema::table( 'sync_jobs' ), array( 'status' => 'failed', 'last_error' => 'schedule_failed', 'updated_at' => current_time( 'mysql', true ) ), array( 'job_uuid' => $job_uuid ), array( '%s', '%s', '%s' ), array( '%s' ) );
				throw new \RuntimeException( 'Could not schedule sync job.' );
			}
			return $job_uuid;
		} finally {
			$this->release_full_sync_lock();
		}
	}

	public function sync_batch( string $job_uuid = '', int $cursor = 0 ): void {
		$limit = 50;
		if ( ! function_exists( 'wc_get_products' ) ) {
			return;
		}
		$job_uuid = sanitize_text_field( $job_uuid );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $job_uuid ) ) {
			return;
		}
		$cursor = max( 0, absint( $cursor ) );
		global $wpdb;
		$this->recover_stuck_jobs();
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE job_uuid=%s AND job_type=%s LIMIT 1', Schema::table( 'sync_jobs' ), $job_uuid, 'product_full' ), ARRAY_A );
		if ( ! is_array( $job ) || ! in_array( sanitize_key( (string) $job['status'] ), array( 'queued', 'running', 'retrying' ), true ) ) {
			return;
		}
		$worker_token = wp_generate_uuid4();
		$state = $this->decode_sync_state( $job['cursor_payload'] ?? '' );
		$cursor_state = max( 0, absint( $state['cursor'] ?? $cursor ) );
		$pending = array_values( array_unique( array_map( 'absint', is_array( $state['pending_ids'] ?? null ) ? $state['pending_ids'] : array() ) ) );
		$retry_ids = array_values( array_unique( array_map( 'absint', is_array( $state['retry_ids'] ?? null ) ? $state['retry_ids'] : array() ) ) );
		$failed_map = is_array( $state['failed_ids'] ?? null ) ? $state['failed_ids'] : array();
		$catalog_complete = ! empty( $state['catalog_complete'] );
		$batch_count = absint( $job['batch_count'] ?? 0 );
		if ( empty( $retry_ids ) ) {
			++$batch_count;
		}
		if ( ! $this->claim_worker_lease( $job, $worker_token, $batch_count ) ) {
			return;
		}
		try {
		$product_retry_count = absint( $job['product_retry_count'] ?? $job['retry_count'] ?? 0 ) + ( empty( $retry_ids ) ? 0 : 1 );
		if ( ! empty( $retry_ids ) ) {
			$wpdb->update(
				Schema::table( 'sync_jobs' ),
				array(
					'product_retry_count' => $product_retry_count,
					'updated_at'  => current_time( 'mysql', true ),
				),
				array( 'job_uuid' => $job_uuid ),
				array( '%d', '%s' ),
				array( '%s' )
			);
		}
		if ( empty( $pending ) && empty( $retry_ids ) ) {
			$pending_result = $this->fetch_next_product_ids( $cursor_state, $limit );
			$catalog_complete = ! empty( $pending_result['complete'] );
			$pending = $pending_result['ids'];
		}
		$queue = ! empty( $retry_ids ) ? $retry_ids : $pending;
		$queue = array_values( array_unique( array_map( 'absint', $queue ) ) );
		$unique_processed = 0;
		$permanent_failed = 0;
		$last_error = '';
		$new_retry = array();
		$new_permanent_failed = array();
		$next_cursor = $cursor_state;
		foreach ( $queue as $product_id ) {
			$next_cursor = max( $next_cursor, (int) $product_id );
			try {
				if ( $this->sync_product( (int) $product_id ) ) {
					++$unique_processed;
					continue;
				}
				$attempt = absint( $failed_map[ $product_id ] ?? 0 ) + 1;
				$failed_map[ $product_id ] = $attempt;
				$last_error = 'product_sync_failed:' . absint( $product_id );
				if ( $attempt < self::MAX_PRODUCT_RETRIES ) {
					$new_retry[] = $product_id;
					continue;
				}
				++$unique_processed;
				++$permanent_failed;
				$new_permanent_failed[] = $product_id;
			} catch ( \Throwable $error ) {
				$attempt = absint( $failed_map[ $product_id ] ?? 0 ) + 1;
				$failed_map[ $product_id ] = $attempt;
				$last_error = 'product_sync_exception:' . absint( $product_id );
				if ( $attempt < self::MAX_PRODUCT_RETRIES ) {
					$new_retry[] = $product_id;
					continue;
				}
				++$unique_processed;
				++$permanent_failed;
				$new_permanent_failed[] = $product_id;
			}
		}
		$permanent_failed_ids = array_values( array_unique( array_merge( array_map( 'absint', $state['permanent_failed'] ?? array() ), $new_permanent_failed ) ) );
		$state = array(
			'cursor'           => $next_cursor,
			'catalog_complete'  => $catalog_complete,
			'pending_ids'       => array(),
			'retry_ids'         => array_values( array_unique( $new_retry ) ),
			'failed_ids'        => $failed_map,
			'permanent_failed'  => $permanent_failed_ids,
		);
		$retry_payload = array(
			'failed_ids'       => $failed_map,
			'permanent_failed' => $permanent_failed_ids,
			'retry_ids'        => $state['retry_ids'],
		);
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET processed_items=processed_items+%d,failed_items=failed_items+%d,cursor_payload=%s,retry_payload=%s,last_error=%s,updated_at=%s WHERE job_uuid=%s', Schema::table( 'sync_jobs' ), $unique_processed, $permanent_failed, wp_json_encode( $state ), wp_json_encode( $retry_payload ), $last_error, current_time( 'mysql', true ), $job_uuid ) );
		if ( ! empty( $state['retry_ids'] ) ) {
			$wpdb->update( Schema::table( 'sync_jobs' ), array( 'status' => 'retrying', 'updated_at' => current_time( 'mysql', true ) ), array( 'job_uuid' => $job_uuid ), array( '%s', '%s' ), array( '%s' ) );
			if ( ! $this->schedule_batch( $job_uuid, $cursor_state, 60 ) ) {
				$wpdb->update( Schema::table( 'sync_jobs' ), array( 'status' => 'failed', 'last_error' => 'schedule_failed', 'updated_at' => current_time( 'mysql', true ) ), array( 'job_uuid' => $job_uuid ), array( '%s', '%s', '%s' ), array( '%s' ) );
			}
			return;
		}
		if ( ! $catalog_complete ) {
			$wpdb->update( Schema::table( 'sync_jobs' ), array( 'status' => 'queued', 'updated_at' => current_time( 'mysql', true ) ), array( 'job_uuid' => $job_uuid ), array( '%s', '%s' ), array( '%s' ) );
			if ( ! $this->schedule_batch( $job_uuid, $next_cursor, 10 ) ) {
				$wpdb->update( Schema::table( 'sync_jobs' ), array( 'status' => 'failed', 'last_error' => 'schedule_failed', 'updated_at' => current_time( 'mysql', true ) ), array( 'job_uuid' => $job_uuid ), array( '%s', '%s', '%s' ), array( '%s' ) );
			}
			return;
		}
		$final_status = ! empty( $permanent_failed_ids ) ? 'completed_with_errors' : 'completed';
		$wpdb->update(
			Schema::table( 'sync_jobs' ),
			array(
				'status'       => $final_status,
				'completed_at' => current_time( 'mysql', true ),
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'job_uuid' => $job_uuid ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);
		} finally {
			$this->release_worker_lease( $job_uuid, $worker_token );
		}
	}

	public function status(): array {
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE source_type=%s AND status=%s', Schema::table( 'knowledge' ), 'product', 'active' ) );
		$job   = $wpdb->get_row( $wpdb->prepare( 'SELECT job_uuid,status,total_items,processed_items,failed_items,product_retry_count,recovery_count,batch_count,last_error,cursor_payload,retry_payload,updated_at,completed_at FROM %i ORDER BY id DESC LIMIT 1', Schema::table( 'sync_jobs' ) ), ARRAY_A );
		if ( is_array( $job ) ) {
			$payload = $this->decode_sync_state( $job['cursor_payload'] ?? '' );
			$job['cursor'] = absint( $payload['cursor'] ?? 0 );
			$job['retry_ids'] = array_values( array_map( 'absint', is_array( $payload['retry_ids'] ?? null ) ? $payload['retry_ids'] : array() ) );
			$job['permanent_failed_count'] = count( is_array( $payload['permanent_failed'] ?? null ) ? $payload['permanent_failed'] : array() );
		}
		return array(
			'indexed_products'     => $count,
			'last_job'             => is_array( $job ) ? $job : null,
			'completion_status'    => is_array( $job ) ? sanitize_key( (string) ( $job['status'] ?? '' ) ) : '',
			'total_items'          => absint( $job['total_items'] ?? 0 ),
			'processed_items'      => absint( $job['processed_items'] ?? 0 ),
			'failed_items'         => absint( $job['failed_items'] ?? 0 ),
			'permanent_failed_ids' => is_array( $job ) ? absint( $job['permanent_failed_count'] ?? 0 ) : 0,
			'last_error'           => sanitize_text_field( (string) ( $job['last_error'] ?? '' ) ),
			'retry_counts'         => array(
				'product'  => absint( $job['product_retry_count'] ?? $job['retry_count'] ?? 0 ),
				'recovery' => absint( $job['recovery_count'] ?? 0 ),
				'batch'    => absint( $job['batch_count'] ?? 0 ),
			),
		);
	}

	public function active_full_sync_uuid(): string {
		$job = $this->active_full_sync_job();
		return is_array( $job ) ? sanitize_text_field( (string) ( $job['job_uuid'] ?? '' ) ) : '';
	}

	private function active_full_sync_job(): ?array {
		global $wpdb;
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT job_uuid,cursor_payload,total_items,processed_items,failed_items,status FROM %i WHERE job_type=%s AND status IN ('queued','running','retrying') ORDER BY id DESC LIMIT 1", Schema::table( 'sync_jobs' ), 'product_full' ), ARRAY_A );
		if ( ! is_array( $job ) ) {
			return null;
		}
		$cursor = $this->decode_sync_state( $job['cursor_payload'] ?? '' );
		$job['cursor'] = absint( $cursor['cursor'] ?? 0 );
		return $job;
	}

	private function schedule_batch( string $job_uuid, int $cursor, int $delay ): bool {
		$scheduled = ScheduleManager::schedule_single(
			time() + max( 1, $delay ),
			'convocart_sync_batch_v2',
			array( sanitize_text_field( $job_uuid ), max( 0, absint( $cursor ) ) )
		);
		if ( ! $scheduled ) {
			global $wpdb;
			$wpdb->update(
				Schema::table( 'sync_jobs' ),
				array(
					'last_error'   => 'schedule_failed',
					'updated_at'   => current_time( 'mysql', true ),
					'worker_token' => '',
					'claimed_at'   => null,
					'lease_until'  => null,
				),
				array( 'job_uuid' => sanitize_text_field( $job_uuid ) ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%s' )
			);
		}
		return $scheduled;
	}

	public function recover_stuck_jobs(): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 30 * MINUTE_IN_SECONDS ) );
		$jobs = $wpdb->get_results( $wpdb->prepare( "SELECT job_uuid,status,cursor_payload,recovery_count FROM %i WHERE job_type=%s AND status IN ('queued','running','retrying') AND updated_at < %s LIMIT 25", Schema::table( 'sync_jobs' ), 'product_full', $cutoff ), ARRAY_A );
		$recovered = 0;
		foreach ( is_array( $jobs ) ? $jobs : array() as $job ) {
			$recovery_count = absint( $job['recovery_count'] ?? 0 );
			$job_uuid = sanitize_text_field( (string) ( $job['job_uuid'] ?? '' ) );
			if ( '' === $job_uuid ) {
				continue;
			}
			if ( $recovery_count >= self::MAX_JOB_RECOVERIES ) {
				$wpdb->update( Schema::table( 'sync_jobs' ), array( 'status' => 'failed', 'last_error' => 'recovery_limit_reached', 'updated_at' => current_time( 'mysql', true ) ), array( 'job_uuid' => $job_uuid ), array( '%s', '%s', '%s' ), array( '%s' ) );
				continue;
			}
			$state = $this->decode_sync_state( $job['cursor_payload'] ?? '' );
			$cursor = absint( $state['cursor'] ?? 0 );
			$args = array( $job_uuid, $cursor );
			if ( ScheduleManager::has_scheduled_action( 'convocart_sync_batch_v2', $args ) ) {
				continue;
			}
			$updated = $wpdb->update(
				Schema::table( 'sync_jobs' ),
				array(
					'status'         => 'retrying',
					'recovery_count' => $recovery_count + 1,
					'worker_token'   => '',
					'claimed_at'     => null,
					'lease_until'    => null,
					'last_error'     => 'stuck_' . sanitize_key( (string) ( $job['status'] ?? 'job' ) ) . '_recovered',
					'updated_at'     => current_time( 'mysql', true ),
				),
				array( 'job_uuid' => $job_uuid ),
				array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' ),
				array( '%s' )
			);
			if ( false !== $updated ) {
				if ( $this->schedule_batch( $job_uuid, $cursor, 10 ) ) {
					++$recovered;
				} else {
					$wpdb->update(
						Schema::table( 'sync_jobs' ),
						array(
							'status'         => 'failed',
							'last_error'     => 'schedule_failed',
							'recovery_count' => $recovery_count + 1,
							'worker_token'   => '',
							'claimed_at'     => null,
							'lease_until'    => null,
							'updated_at'     => current_time( 'mysql', true ),
						),
						array( 'job_uuid' => $job_uuid ),
						array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' ),
						array( '%s' )
					);
				}
			}
		}
		$this->ensure_recovery_schedule();
		return $recovered;
	}

	private function ensure_recovery_schedule(): void {
		if ( ScheduleManager::has_scheduled_action( 'convocart_sync_recovery', array() ) ) {
			return;
		}
		ScheduleManager::schedule_single( time() + ( 15 * MINUTE_IN_SECONDS ), 'convocart_sync_recovery', array() );
	}

	private function claim_worker_lease( array $job, string $worker_token, int $batch_count ): bool {
		global $wpdb;
		$job_uuid = sanitize_text_field( (string) ( $job['job_uuid'] ?? '' ) );
		$now = current_time( 'mysql', true );
		$lease_until = gmdate( 'Y-m-d H:i:s', time() + self::WORKER_LEASE_SECONDS );
		$existing_token = (string) ( $job['worker_token'] ?? '' );
		$existing_lease = (string) ( $job['lease_until'] ?? '' );
		if ( '' !== $existing_token && '' !== $existing_lease && strtotime( $existing_lease ) > time() ) {
			return false;
		}
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status=%s,started_at=COALESCE(started_at,%s),batch_count=%d,worker_token=%s,claimed_at=%s,lease_until=%s,updated_at=%s WHERE job_uuid=%s AND job_type=%s AND status IN ('queued','running','retrying') AND (worker_token='' OR worker_token IS NULL OR lease_until IS NULL OR lease_until < %s)",
				Schema::table( 'sync_jobs' ),
				'running',
				$now,
				$batch_count,
				$worker_token,
				$now,
				$lease_until,
				$now,
				$job_uuid,
				'product_full',
				$now
			)
		);
		return false !== $result && $result > 0;
	}

	private function release_worker_lease( string $job_uuid, string $worker_token ): void {
		global $wpdb;
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT worker_token FROM %i WHERE job_uuid=%s LIMIT 1', Schema::table( 'sync_jobs' ), sanitize_text_field( $job_uuid ) ), ARRAY_A );
		if ( ! is_array( $job ) || ! hash_equals( sanitize_text_field( $worker_token ), (string) ( $job['worker_token'] ?? '' ) ) ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET worker_token='',claimed_at=NULL,lease_until=NULL WHERE job_uuid=%s AND worker_token=%s",
				Schema::table( 'sync_jobs' ),
				sanitize_text_field( $job_uuid ),
				sanitize_text_field( $worker_token )
			)
		);
	}

	private function acquire_full_sync_lock(): bool {
		$key = 'convocart_full_sync_lock';
		$now = time();
		$existing = get_option( $key, array() );
		$existing = is_array( $existing ) ? $existing : array();
		if ( ! empty( $existing['locked_at'] ) && $now - absint( $existing['locked_at'] ) > 300 ) {
			delete_option( $key );
		}
		$this->sync_lock_token = wp_generate_uuid4();
		return add_option( $key, array( 'locked_at' => $now, 'token' => $this->sync_lock_token ), '', false );
	}

	private function release_full_sync_lock(): void {
		$key = 'convocart_full_sync_lock';
		$lock = get_option( $key, array() );
		$lock = is_array( $lock ) ? $lock : array();
		if ( '' === (string) $this->sync_lock_token ) {
			return;
		}
		if ( isset( $lock['token'] ) && hash_equals( (string) $this->sync_lock_token, (string) $lock['token'] ) ) {
			delete_option( $key );
		}
		$this->sync_lock_token = null;
	}

	private function decode_sync_state( $payload ): array {
		$state = json_decode( (string) $payload, true );
		$state = is_array( $state ) ? $state : array();
		return wp_parse_args(
			$state,
			array(
				'cursor'          => 0,
				'catalog_complete' => false,
				'pending_ids'      => array(),
				'retry_ids'        => array(),
				'failed_ids'       => array(),
				'permanent_failed' => array(),
			)
		);
	}

	private function fetch_next_product_ids( int $cursor, int $limit ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s AND ID > %d ORDER BY ID ASC LIMIT %d", 'product', 'publish', $cursor, $limit + 1 ) );
		$ids = array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
		return array(
			'ids'      => array_slice( $ids, 0, $limit ),
			'complete'  => count( $ids ) <= $limit,
		);
	}
}
