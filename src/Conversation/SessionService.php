<?php
namespace ConvoCart\Assistant\Conversation;

defined( 'ABSPATH' ) || exit;

final class SessionService {
	public function issue(): string {
		$payload = array(
			'exp'   => time() + DAY_IN_SECONDS,
			'nonce' => wp_generate_password( 32, false, false ),
		);
		$encoded = $this->encode( wp_json_encode( $payload ) );
		return $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
	}

	public function valid( string $token ): bool {
		return null !== $this->payload( $token );
	}

	public function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'nonce' ) );
	}

	private function payload( string $token ): ?array {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) ), $parts[1] ) ) {
			return null;
		}
		$json = base64_decode( strtr( $parts[0], '-_', '+/' ), true );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		return is_array( $data ) && ! empty( $data['exp'] ) && (int) $data['exp'] >= time() ? $data : null;
	}

	private function encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}
}
