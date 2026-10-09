<?php
namespace ConvoCart\Assistant\AI;

defined( 'ABSPATH' ) || exit;

/** Adapter capabilities, not a promise of account access or provider uptime. */
final class ModelCatalog {
	public static function defaults(): array {
		return array( 'groq' => 'openai/gpt-oss-20b', 'gemini' => 'gemini-2.5-flash-lite', 'openai' => 'gpt-4.1-mini' );
	}

	public static function curated( string $provider ): array {
		return array(
			'groq' => array( 'openai/gpt-oss-20b', 'openai/gpt-oss-120b' ),
			'gemini' => array( 'gemini-2.5-flash-lite' ),
			'openai' => array( 'gpt-4.1-mini', 'gpt-4.1', 'gpt-4o-mini', 'gpt-4o' ),
		)[ $provider ] ?? array();
	}

	public static function normalize( string $provider, string $model ): string {
		$model = trim( sanitize_text_field( $model ) );
		return 'gemini' === $provider ? (string) preg_replace( '#^models/#i', '', $model ) : $model;
	}

	public static function status( string $provider, string $model ): array {
		$model = self::normalize( $provider, $model );
		$status = 'unverified';
		$message = __( 'Model must be present in the provider inventory. Test the saved model to verify shopping responses.', 'nexora-shopping-assistant' );
		$lifecycle = ModelLifecycle::status( $provider, $model );
		if ( ! isset( self::defaults()[ $provider ] ) || ! preg_match( '/^[A-Za-z0-9._:-]+(?:\/[A-Za-z0-9._:-]+)*$/', $model ) || strlen( $model ) > 100 ) {
			$status = 'invalid';
			$message = __( 'Enter a valid model ID of at most 100 characters.', 'nexora-shopping-assistant' );
		} elseif ( 'retired' === $lifecycle['lifecycle'] ) {
			$status = 'retired';
			$message = $lifecycle['shutdown_date']
				/* translators: %s: retirement date (YYYY-MM-DD). */
				? sprintf( __( 'This model was retired on %s and cannot be used. Select an available replacement.', 'nexora-shopping-assistant' ), $lifecycle['shutdown_date'] )
				: __( 'This model is retired and cannot be used. Select an available replacement.', 'nexora-shopping-assistant' );
		} elseif ( ! self::compatible( $provider, $model ) ) {
			$status = 'unsupported';
			$message = __( 'This model is not supported by the text-chat adapter.', 'nexora-shopping-assistant' );
		} elseif ( 'scheduled' === $lifecycle['lifecycle'] ) {
			$status = 'scheduled';
			$message = sprintf( /* translators: %s: shutdown date (YYYY-MM-DD). */ __( 'Shutdown scheduled for %s (UTC). Choose a replacement before this date.', 'nexora-shopping-assistant' ), $lifecycle['shutdown_date'] );
		} elseif ( 'preview' === $lifecycle['lifecycle'] ) {
			$status = 'preview';
			$message = __( 'Preview model; availability can change at short notice. Test before use.', 'nexora-shopping-assistant' );
		} elseif ( in_array( $model, self::curated( $provider ), true ) ) {
			$status = 'valid';
			$message = __( 'Adapter-supported model. Run the shopping-response test to verify current provider availability.', 'nexora-shopping-assistant' );
		}
		return array_merge( $lifecycle, array( 'status' => $status, 'model' => $model, 'message' => $message, 'verified' => false ) );
	}

	public static function usable( string $provider, string $model ): bool {
		return ! in_array( self::status( $provider, $model )['status'], array( 'invalid', 'retired', 'unsupported' ), true );
	}

	public static function compatible( string $provider, string $id, array $raw = array() ): bool {
		if ( preg_match( '/embedding|moderation|whisper|tts|speech|audio|image|dall|sora|rerank|guard|realtime|transcrib|search|codex|deep-research|robotics|cyber|(?:^|-)live(?:-|$)/i', $id ) ) {
			return false;
		}
		if ( 'gemini' === $provider ) {
			return (bool) preg_match( '/^gemini-\d+(?:\.\d+)?-(?:flash|pro)(?:-|$)/', $id ) && ( empty( $raw ) || in_array( 'generateContent', (array) ( $raw['supportedGenerationMethods'] ?? array() ), true ) );
		}
		if ( 'openai' === $provider ) {
			return (bool) preg_match( '/^(?:gpt-(?:4o|4\.1)(?:-|$)|gpt-[5-9](?:[.-]|$)|o[134](?:-|$)|ft:gpt-(?:4o|4\.1))/', $id ) && ! preg_match( '/-pro(?:-|$)|^o1-(?:mini|preview)/', $id );
		}
		return 'groq' === $provider && (bool) preg_match( '#^(?:openai/gpt-oss-|qwen/|llama-|meta-llama/|moonshotai/)#', $id );
	}

	public static function reasoning( string $provider, string $model ): bool {
		return 'openai' === $provider && (bool) preg_match( '/^(?:o[134](?:-|$)|gpt-[5-9])/', $model );
	}
}
