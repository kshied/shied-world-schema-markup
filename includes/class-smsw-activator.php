<?php
/**
 * Plugin activation.
 *
 * @package Shied_World_Schema_Markup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on plugin activation.
 */
class SMSW_Activator {

	/**
	 * Activate the plugin.
	 *
	 * Stores the running version and seeds the default options.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( ! get_option( 'smsw_version' ) ) {
			add_option( 'smsw_version', SMSW_VERSION );
		} else {
			update_option( 'smsw_version', SMSW_VERSION );
		}

		require_once SMSW_PLUGIN_DIR . 'includes/class-smsw-options.php';
		SMSW_Options::ensure_defaults();
	}
}
