<?php
namespace ConvoCart\Assistant\AI\DTO;

defined( 'ABSPATH' ) || exit;

final class AIRequest {
	public function __construct(
		public readonly array $messages,
		public readonly array $tools = array(),
		public readonly float $temperature = 0.2,
		public readonly int $max_tokens = 600,
		public readonly int $timeout = 20,
		public readonly array $context = array()
	) {}
}
