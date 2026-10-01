<?php
/**
 * Plugin Name: SHIED WORLD Schema Markup
 * Plugin URI:  https://shiedworld.com/shied-world-schema-markup-wordpress-plugin/
 * Description: Add custom JSON-LD schema markup to any page, post, or custom post type, plus site-wide schema. Built by SHIED WORLD.
 * Version:     1.0.3
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author:      SHIED WORLD
 * Author URI:  https://shiedworld.com/
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: shied-world-schema-markup
 * Domain Path: /languages
 *
 * @package Shied_World_Schema_Markup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SMSW_VERSION', '1.0.3' );
define( 'SMSW_PLUGIN_FILE', __FILE__ );
define( 'SMSW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMSW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SMSW_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'SMSW_META_KEY', '_smsw_schema_blocks' );

require_once SMSW_PLUGIN_DIR . 'includes/class-smsw-activator.php';
require_once SMSW_PLUGIN_DIR . 'includes/class-smsw-options.php';
require_once SMSW_PLUGIN_DIR . 'includes/class-smsw-crypto.php';
require_once SMSW_PLUGIN_DIR . 'includes/class-smsw-schema-types.php';
require_once SMSW_PLUGIN_DIR . 'includes/class-smsw-placeholders.php';
require_once SMSW_PLUGIN_DIR . 'includes/class-smsw-block-sanitizer.php';
require_once SMSW_PLUGIN_DIR . 'includes/class-smsw-schema-validator.php';
require_once SMSW_PLUGIN_DIR . 'includes/class-smsw-schema-output.php';
require_once SMSW_PLUGIN_DIR . 'includes/class-smsw-suggestion-engine.php';
require_once SMSW_PLUGIN_DIR . 'includes/class-smsw-ai-suggestion.php';
require_once SMSW_PLUGIN_DIR . 'includes/class-smsw-vocabulary-parser.php';

register_activation_hook( __FILE__, array( 'SMSW_Activator', 'activate' ) );

/**
 * Adds a Settings link to the plugin row on the Plugins screen.
 *
 * @param array<int,string> $links Existing action links.
 * @return array<int,string> Action links with the Settings link first.
 */
function smsw_plugin_action_links( $links ) {
	$settings_url = admin_url( 'admin.php?page=shied-world-schema-markup' );
	$label        = esc_html__( 'Settings', 'shied-world-schema-markup' );
	// phpcs:ignore WordPress.Security.EscapeOutput -- URL escaped; label escaped above.
	$links[] = '<a href="' . esc_url( $settings_url ) . '">' . $label . '</a>';
	return $links;
}
add_filter( 'plugin_action_links_' . SMSW_PLUGIN_BASENAME, 'smsw_plugin_action_links' );

/**
 * Adds a Documentation link to the plugin description row.
 *
 * @param array<int,string> $links Existing meta links.
 * @param string            $file  Basename of the plugin being filtered.
 * @return array<int,string> Meta links with the Documentation link appended.
 */
function smsw_plugin_row_meta( $links, $file ) {
	if ( SMSW_PLUGIN_BASENAME !== $file ) {
		return $links;
	}
	$docs_url = admin_url( 'admin.php?page=shied-world-schema-markup' );
	$label    = esc_html__( 'Documentation', 'shied-world-schema-markup' );
	// phpcs:ignore WordPress.Security.EscapeOutput -- URL escaped; label escaped above.
	$links[] = '<a href="' . esc_url( $docs_url ) . '">' . $label . '</a>';
	return $links;
}
add_filter( 'plugin_row_meta', 'smsw_plugin_row_meta', 10, 2 );

/**
 * Cache-busting version for a plugin asset.
 *
 * Appends the asset's own modification time to the plugin version, so a
 * freshly uploaded build always invalidates the browser, CDN and page caches
 * for that file. A hand-bumped plugin version is not enough on its own: when
 * a build ships without a version change, browsers keep serving the previous
 * JavaScript and the site appears to run code that was never deployed.
 *
 * @param string $relative_path Plugin-relative asset path, e.g. 'assets/js/admin-bar.js'.
 * @return string Version string for wp_enqueue_script() / wp_enqueue_style().
 */
function smsw_asset_version( $relative_path ) {
	$path = SMSW_PLUGIN_DIR . ltrim( (string) $relative_path, '/' );

	if ( file_exists( $path ) ) {
		$mtime = filemtime( $path );
		if ( $mtime ) {
			return SMSW_VERSION . '.' . $mtime;
		}
	}

	return SMSW_VERSION;
}

/**
 * Bootstraps the plugin after all plugins have been loaded.
 *
 * @return void
 */
function smsw_init() {
	// Translations are loaded automatically from wp-content/languages/plugins/
	// for plugins hosted on WordPress.org, so no manual translation-loader
	// call is needed here.

	SMSW_Options::ensure_defaults();
	SMSW_Schema_Output::init();
	SMSW_AI_Suggestion::init();

	if ( is_admin() ) {
		require_once SMSW_PLUGIN_DIR . 'admin/class-smsw-admin-menu.php';
		require_once SMSW_PLUGIN_DIR . 'admin/class-smsw-settings.php';
		require_once SMSW_PLUGIN_DIR . 'admin/class-smsw-meta-box.php';
		require_once SMSW_PLUGIN_DIR . 'admin/class-smsw-admin-notices.php';
		require_once SMSW_PLUGIN_DIR . 'admin/class-smsw-admin-bar.php';

		SMSW_Admin_Menu::init();
		SMSW_Settings::init();
		SMSW_Meta_Box::init();
		SMSW_Admin_Notices::init();
		SMSW_Admin_Bar::init();
	}
}
add_action( 'plugins_loaded', 'smsw_init' );
