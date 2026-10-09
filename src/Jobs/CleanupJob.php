<?php
namespace ConvoCart\Assistant\Jobs;

use ConvoCart\Assistant\Privacy\PrivacyService;

defined( 'ABSPATH' ) || exit;

final class CleanupJob {
	public function register(): void {
		add_action( 'convocart_cleanup', array( $this, 'run' ) );
		add_action( 'convocart_cleanup_continue', array( $this, 'run' ) );
	}

	public function run(): void {
		( new PrivacyService() )->cleanup();
	}
}
