<?php
namespace ConvoCart\Assistant\Conversation;

defined( 'ABSPATH' ) || exit;

/** Short-lived session context, distinct from opt-in retained transcripts. */
final class ConversationMemory {
	public static function get( int $id ): array {
		$value = get_transient( 'convocart_memory_' . $id );
		return is_array( $value ) ? $value : array( 'messages' => array(), 'product_ids' => array() );
	}

	public static function append( int $id, string $role, string $content ): void {
		$data = self::get( $id );
		$data['messages'][] = array( 'role' => $role, 'content' => $content );
		$data['messages'] = array_slice( $data['messages'], -10 );
		self::put( $id, $data );
	}

	public static function products( int $id, array $ids ): void {
		$data = self::get( $id );
		$data['product_ids'] = array_slice( array_values( array_unique( array_map( 'absint', $ids ) ) ), 0, 8 );
		self::put( $id, $data );
	}

	private static function put( int $id, array $data ): void {
		set_transient( 'convocart_memory_' . $id, $data, DAY_IN_SECONDS );
	}

	public static function delete( int $id ): void {
		delete_transient( 'convocart_memory_' . $id );
	}
}
