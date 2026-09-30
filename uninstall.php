<?php
/**
 * Uninstall cleanup for SHIED WORLD Schema Markup.
 *
 * Only removes data when the user enabled
 * "Delete all plugin data on uninstall" in the General tab.
 *
 * @package Shied_World_Schema_Markup
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$smsw_general = get_option( 'smsw_general_settings', array() );
if ( ! is_array( $smsw_general ) || empty( $smsw_general['delete_on_uninstall'] ) ) {
	return;
}

delete_option( 'smsw_site_schema_blocks' );
delete_option( 'smsw_enabled_post_types' );
delete_option( 'smsw_ai_settings' );
delete_option( 'smsw_suggestion_settings' );
delete_option( 'smsw_general_settings' );
delete_option( 'smsw_version' );
delete_option( 'smsw_vocabulary_version' );

if ( function_exists( 'delete_post_meta_by_key' ) ) {
	delete_post_meta_by_key( '_smsw_schema_blocks' );
}

global $wpdb;

// Transients with a fixed name.
$smsw_transient_names = array(
	'smsw_vocabulary_parsed',
);

// Transients whose name carries a suffix that is not known ahead of time:
// smsw_save_error_{user_id} and smsw_save_warning_{user_id} from the meta box,
// smsw_ai_suggest_{post_id}_{provider} from the suggestion cache,
// smsw_ai_models_{provider}, smsw_ai_models_{provider}_retry and
// smsw_ai_models_state_{provider} from the model lists, and
// smsw_all_property_definitions_{schema_revision}_{version} from the schema
// types cache. Their rows are read from the options table and then removed
// by name.
$smsw_like_patterns = array(
	'_transient_smsw_save_%',
	'_transient_smsw_ai_suggest_%',
	'_transient_smsw_ai_models_%',
	'_transient_smsw_all_property_definitions_%',
);

foreach ( $smsw_like_patterns as $smsw_like_pattern ) {
	// delete_transient() needs an exact name, and these transients carry a
	// suffix only the database knows, so the rows are read by name pattern.
	// Nothing here is cached: this runs once, on uninstall, and every row
	// found is deleted before the file returns.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$smsw_rows = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$smsw_like_pattern
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery

	if ( ! is_array( $smsw_rows ) ) {
		continue;
	}

	foreach ( $smsw_rows as $smsw_option_name ) {
		$smsw_name = str_replace( '_transient_', '', $smsw_option_name );

		if ( 0 === strpos( $smsw_name, 'smsw_save_error_' )
			|| 0 === strpos( $smsw_name, 'smsw_save_warning_' )
			|| 0 === strpos( $smsw_name, 'smsw_ai_suggest_' )
			|| 0 === strpos( $smsw_name, 'smsw_all_property_definitions_' ) ) {
			$smsw_transient_names[] = $smsw_name;
		}
	}
}

foreach ( $smsw_transient_names as $smsw_transient_name ) {
	delete_transient( $smsw_transient_name );
}
