<?php
namespace ConvoCart\Assistant\AI\DTO;

defined( 'ABSPATH' ) || exit;

final class AIResponse {
	public function __construct(
		public readonly string $provider,
		public readonly string $model,
		public readonly string $content,
		public readonly array $tool_calls = array(),
		public readonly array $usage = array(),
		public readonly string $finish_reason = 'stop'
	) {}
}
