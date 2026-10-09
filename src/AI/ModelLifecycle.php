<?php
namespace ConvoCart\Assistant\AI;

defined( 'ABSPATH' ) || exit;

/** Official shutdown metadata, reviewed 2026-09-12. Live inventories supplement this list. */
final class ModelLifecycle {
	public const REVIEWED_AT = '2026-09-12';

	public static function status( string $provider, string $model, ?int $now = null ): array {
		$now = $now ?? time();
		// A fine-tune inherits its base model's shutdown date.
		$base = preg_match( '/^ft:([^:]+):/', $model, $match ) ? $match[1] : $model;
		$record = self::dates()[ $provider ][ $base ] ?? null;
		if ( 'gemini' === $provider && preg_match( '/^gemini-(?:1\.5-|pro(?:$|-))/', $base ) ) {
			return array( 'lifecycle' => 'retired', 'shutdown_date' => null, 'replacement' => 'gemini-2.5-flash-lite' );
		}
		if ( 'gemini' === $provider && 0 === strpos( $base, 'gemini-2.0-' ) && null === $record ) {
			$record = array( '2026-06-01', 'gemini-2.5-flash-lite' );
		}
		if ( null !== $record ) {
			return array(
				'lifecycle' => $now >= strtotime( $record[0] . ' 00:00:00 UTC' ) ? 'retired' : 'scheduled',
				'shutdown_date' => $record[0],
				'replacement' => $record[1],
			);
		}
		$preview = (bool) preg_match( '/preview|experimental|(?:^|-)exp(?:-|$)/i', $model );
		// Groq's preview status is not necessarily present in the ID.
		if ( 'groq' === $provider && in_array( $model, array( 'qwen/qwen3.6-27b', 'qwen/qwen3.8-27b' ), true ) ) { $preview = true; }
		return array( 'lifecycle' => $preview ? 'preview' : 'not_scheduled', 'shutdown_date' => null, 'replacement' => null );
	}

	private static function dates(): array {
		return array(
			// Groq shutdowns below apply to ordinary free/developer plans. This
			// release intentionally does not override retirement for private enterprise contracts.
			'groq' => array(
				'llama-3.1-8b-instant' => array( '2026-08-16', 'openai/gpt-oss-20b' ),
				'llama-3.3-70b-versatile' => array( '2026-08-16', 'openai/gpt-oss-120b' ),
				'qwen/qwen3-32b' => array( '2026-07-17', 'openai/gpt-oss-120b' ),
				'meta-llama/llama-4-scout-17b-16e-instruct' => array( '2026-07-17', 'openai/gpt-oss-120b' ),
				'meta-llama/llama-4-maverick-17b-128e-instruct' => array( '2026-03-09', 'openai/gpt-oss-120b' ),
				'moonshotai/kimi-k2-instruct-0905' => array( '2026-04-15', 'openai/gpt-oss-120b' ),
				'moonshotai/kimi-k2-instruct' => array( '2025-10-10', 'openai/gpt-oss-120b' ),
				'llama-3.3-70b-specdec' => array( '2025-04-14', 'openai/gpt-oss-120b' ),
				'llama-3.2-1b-preview' => array( '2025-04-14', 'openai/gpt-oss-20b' ),
				'llama-3.2-3b-preview' => array( '2025-04-14', 'openai/gpt-oss-20b' ),
				'llama-3.2-11b-vision-preview' => array( '2025-04-14', 'openai/gpt-oss-120b' ),
				'llama-3.2-90b-vision-preview' => array( '2025-04-14', 'openai/gpt-oss-120b' ),
				'llama-3.2-90b-text-preview' => array( '2024-11-25', 'openai/gpt-oss-120b' ),
				'qwen-2.5-32b' => array( '2025-04-14', 'openai/gpt-oss-120b' ),
				'qwen-qwq-32b' => array( '2025-07-14', 'openai/gpt-oss-120b' ),
			),
			'gemini' => array(
				'gemini-2.0-flash' => array( '2026-06-01', 'gemini-2.5-flash-lite' ),
				'gemini-2.0-flash-001' => array( '2026-06-01', 'gemini-2.5-flash-lite' ),
				'gemini-2.0-flash-lite' => array( '2026-06-01', 'gemini-2.5-flash-lite' ),
				'gemini-2.0-flash-lite-001' => array( '2026-06-01', 'gemini-2.5-flash-lite' ),
				'gemini-2.5-flash-lite-preview-09-2025' => array( '2026-03-31', 'gemini-3.5-flash-lite' ),
				'gemini-2.5-flash-preview-05-20' => array( '2025-11-18', 'gemini-2.5-flash-lite' ),
				'gemini-2.5-flash-preview-09-25' => array( '2026-02-17', 'gemini-2.5-flash-lite' ),
				'gemini-2.5-pro-preview-03-25' => array( '2025-12-02', 'gemini-2.5-pro' ),
				'gemini-2.5-pro-preview-05-06' => array( '2025-12-02', 'gemini-2.5-pro' ),
				'gemini-2.5-pro-preview-06-05' => array( '2025-12-02', 'gemini-2.5-pro' ),
				'gemini-3-pro-preview' => array( '2026-03-09', 'gemini-3.1-pro-preview' ),
				'gemini-3.1-flash-lite-preview' => array( '2026-05-25', 'gemini-3.5-flash-lite' ),
				'gemini-3.1-flash-lite' => array( '2027-05-07', 'gemini-3.5-flash-lite' ),
			),
			'openai' => array(
				'gpt-5-chat-latest' => array( '2026-07-23', 'gpt-4.1-mini' ),
				'gpt-5.1-chat-latest' => array( '2026-07-23', 'gpt-4.1-mini' ),
				'gpt-5.2-chat-latest' => array( '2026-08-10', 'gpt-4.1-mini' ),
				'gpt-5.3-chat-latest' => array( '2026-08-10', 'gpt-4.1-mini' ),
				'chatgpt-4o-latest' => array( '2026-02-17', 'gpt-4.1-mini' ),
				'o1-preview' => array( '2025-07-28', 'gpt-4.1-mini' ),
				'o1-preview-2024-09-12' => array( '2025-07-28', 'gpt-4.1-mini' ),
				'o1-mini' => array( '2025-10-27', 'gpt-4.1-mini' ),
				'o1-mini-2024-09-12' => array( '2025-10-27', 'gpt-4.1-mini' ),
				'o1' => array( '2026-10-23', 'gpt-4.1-mini' ),
				'o1-2024-12-17' => array( '2026-10-23', 'gpt-4.1-mini' ),
				'o3-mini' => array( '2026-10-23', 'gpt-4.1-mini' ),
				'o3-mini-2025-01-31' => array( '2026-10-23', 'gpt-4.1-mini' ),
				'o4-mini' => array( '2026-10-23', 'gpt-4.1-mini' ),
				'o4-mini-2025-04-16' => array( '2026-10-23', 'gpt-4.1-mini' ),
				'gpt-4.1-nano' => array( '2026-10-23', 'gpt-4.1-mini' ),
				'gpt-4.1-nano-2025-04-14' => array( '2026-10-23', 'gpt-4.1-mini' ),
				'gpt-4o-2024-05-13' => array( '2026-10-23', 'gpt-4.1-mini' ),
				'gpt-5-2025-08-07' => array( '2026-12-11', 'gpt-4.1-mini' ),
				'gpt-5-mini-2025-08-07' => array( '2026-12-11', 'gpt-4.1-mini' ),
				'gpt-5-nano-2025-08-07' => array( '2026-12-11', 'gpt-4.1-mini' ),
				'o3-2025-04-16' => array( '2026-12-11', 'gpt-4.1-mini' ),
			),
		);
	}
}
