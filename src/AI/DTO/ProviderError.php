<?php
namespace ConvoCart\Assistant\AI\DTO;

defined( 'ABSPATH' ) || exit;

final class ProviderError extends \RuntimeException {
	public function __construct(
		string $message,
		public readonly string $code_name = 'provider_error',
		public readonly bool $retryable = false,
		public readonly int $retry_after = 0
	) {
		parent::__construct( $message );
	}
}
