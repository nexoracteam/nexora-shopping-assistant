<?php
namespace ConvoCart\Assistant;

defined( 'ABSPATH' ) || exit;

final class Container {
	private array $services = array();

	public function set( string $id, $service ): void {
		$this->services[ $id ] = $service;
	}

	public function has( string $id ): bool {
		return array_key_exists( $id, $this->services );
	}

	public function get( string $id ) {
		if ( ! $this->has( $id ) ) {
			throw new \RuntimeException( 'Requested service is not registered.' );
		}
		return $this->services[ $id ];
	}
}
