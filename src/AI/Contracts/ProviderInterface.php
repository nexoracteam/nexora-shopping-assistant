<?php
namespace ConvoCart\Assistant\AI\Contracts;

use ConvoCart\Assistant\AI\DTO\AIRequest;
use ConvoCart\Assistant\AI\DTO\AIResponse;

defined( 'ABSPATH' ) || exit;

interface ProviderInterface {
	public function get_id(): string;
	public function get_name(): string;
	public function is_configured(): bool;
	public function test_connection(): array;
	public function complete( AIRequest $request ): AIResponse;
	public function supports_tools(): bool;
	public function supports_streaming(): bool;
}
