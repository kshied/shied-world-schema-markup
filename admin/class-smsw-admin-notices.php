<?php
/**
 * Admin notices for save validation errors.
 *
 * @package Shied_World_Schema_Markup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Displays schema save errors after redirect.
 */
class SMSW_Admin_Notices {

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
	}

	/**
	 * Show transient error and warning notices if present.
	 *
	 * Scoped to the post edit screens. The meta box that raises these notices
	 * only runs on post.php and post-new.php, and WordPress redirects back to
	 * one of those after a save, so that is exactly where the notice is
	 * useful. Rendering on the dashboard or any other admin screen would only
	 * put plugin messaging on pages the plugin has no business appearing on.
	 *
	 * @return void
	 */
	public static function render() {
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
			return;
		}

		$user_id = get_current_user_id();
		$key     = SMSW_Meta_Box::NOTICE_KEY . $user_id;
		$message = get_transient( $key );

		if ( $message ) {
			delete_transient( $key );
			?>
			<div class="notice notice-error is-dismissible smsw-admin-notice">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
			<?php
		}

		$warning_key = SMSW_Meta_Box::WARNING_KEY . $user_id;
		$warnings    = get_transient( $warning_key );

		if ( ! empty( $warnings ) && is_array( $warnings ) ) {
			delete_transient( $warning_key );
			?>
			<div class="notice notice-warning is-dismissible smsw-admin-notice">
				<p><strong><?php esc_html_e( 'Schema Markup recommended properties:', 'shied-world-schema-markup' ); ?></strong></p>
				<ul>
					<?php foreach ( array_values( $warnings ) as $warning ) : ?>
						<li><?php echo esc_html( (string) $warning ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}
	}
}
