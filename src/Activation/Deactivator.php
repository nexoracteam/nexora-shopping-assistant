<?php
namespace ConvoCart\Assistant\Activation;

use ConvoCart\Assistant\Jobs\ScheduleManager;

defined( 'ABSPATH' ) || exit;

final class Deactivator {
	public static function deactivate(): void {
		ScheduleManager::clear_plugin_events();
		flush_rewrite_rules();
	}
}
