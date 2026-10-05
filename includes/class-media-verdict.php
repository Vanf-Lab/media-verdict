<?php
/**
 * Orchestrator: singleton wiring every module.
 *
 * @package Media_Verdict
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
class Media_Verdict {

	/**
	 * Singleton instance.
	 *
	 * @var Media_Verdict|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton.
	 *
	 * @return Media_Verdict
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Wires modules.
	 *
	 * @return void
	 */
	private function init() {
		Media_Verdict_Library::hooks();
		Media_Verdict_Admin::hooks();
	}
}
