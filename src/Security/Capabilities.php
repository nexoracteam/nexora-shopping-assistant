<?php
namespace ConvoCart\Assistant\Security;

defined( 'ABSPATH' ) || exit;

final class Capabilities {
	public const MANAGE_SETTINGS    = 'convocart_manage_settings';
	public const MANAGE_PROVIDERS   = 'convocart_manage_providers';
	public const MANAGE_KNOWLEDGE   = 'convocart_manage_knowledge';
	public const VIEW_CONVERSATIONS = 'convocart_view_conversations';
	public const MANAGE_PRIVACY     = 'convocart_manage_privacy';
	public const VIEW_ANALYTICS     = 'convocart_view_analytics';

	public static function all(): array {
		return array(
			self::MANAGE_SETTINGS,
			self::MANAGE_PROVIDERS,
			self::MANAGE_KNOWLEDGE,
			self::VIEW_CONVERSATIONS,
			self::MANAGE_PRIVACY,
			self::VIEW_ANALYTICS,
		);
	}

	public static function grant(): void {
		$administrator = get_role( 'administrator' );
		$shop_manager  = get_role( 'shop_manager' );
		foreach ( array( $administrator, $shop_manager ) as $role ) {
			if ( ! $role ) {
				continue;
			}
			foreach ( self::all() as $capability ) {
				$role->add_cap( $capability );
			}
		}
	}

	public static function revoke(): void {
		foreach ( array( get_role( 'administrator' ), get_role( 'shop_manager' ) ) as $role ) {
			if ( ! $role ) {
				continue;
			}
			foreach ( self::all() as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}
}
