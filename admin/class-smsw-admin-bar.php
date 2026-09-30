<?php
/**
 * Admin toolbar icon and quick actions for Schema Markup.
 *
 * @package Shied_World_Schema_Markup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Schema Markup admin bar node.
 */
class SMSW_Admin_Bar {

	/**
	 * Root node ID.
	 *
	 * @var string
	 */
	const NODE_ID = 'smsw-toolbar';

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'register_node' ), 100 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Whether the admin bar item should show on the current screen.
	 *
	 * Only the post edit screen for enabled post types and the plugin
	 * settings page are relevant.
	 *
	 * @return array{show:bool,is_post:bool}
	 */
	private static function get_context() {
		$none = array(
			'show'    => false,
			'is_post' => false,
		);

		if ( ! function_exists( 'get_current_screen' ) ) {
			return $none;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return $none;
		}

		$menu_base = 'toplevel_page_' . SMSW_Admin_Menu::MENU_SLUG;

		if ( $menu_base === $screen->base ) {
			return array(
				'show'    => current_user_can( 'manage_options' ),
				'is_post' => false,
			);
		}

		if ( ! in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
			return $none;
		}

		$type = (string) $screen->post_type;
		if ( ! $type || ! in_array( $type, SMSW_Options::get_enabled_post_types(), true ) ) {
			return $none;
		}

		$post_id = 0;
		if ( 'post' === $screen->base ) {
			$post = get_post();
			if ( $post && isset( $post->ID ) ) {
				$post_id = (int) $post->ID;
			}
		}

		$can = $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_posts' );
		if ( ! $can && ! current_user_can( 'manage_options' ) ) {
			return $none;
		}

		return array(
			'show'    => true,
			'is_post' => true,
		);
	}

	/**
	 * Register the toolbar node and its dropdown children.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @return void
	 */
	public static function register_node( $wp_admin_bar ) {
		$context = self::get_context();
		if ( empty( $context['show'] ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=' . SMSW_Admin_Menu::MENU_SLUG );
		$logo         = '<div class="smsw-admin-bar-icon" style="width:18px;height:18px;overflow:hidden;background-image:url(\'' . esc_url( SMSW_Admin_Menu::get_logo_url() ) . '\');background-size:contain;background-position:center;background-repeat:no-repeat;display:inline-block;vertical-align:middle;"></div>';

		$wp_admin_bar->add_node(
			array(
				'id'    => self::NODE_ID,
				'title' => $logo . '<span class="smsw-admin-bar-label">' . esc_html__( 'Schema', 'shied-world-schema-markup' ) . '</span>',
				'href'  => $settings_url,
				'meta'  => array(
					'title' => __( 'SHIED WORLD Schema Markup', 'shied-world-schema-markup' ),
				),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'id'     => self::NODE_ID . '-settings',
				'parent' => self::NODE_ID,
				'title'  => __( 'Schema Settings', 'shied-world-schema-markup' ),
				'href'   => $settings_url,
			)
		);

		if ( ! empty( $context['is_post'] ) ) {
			$wp_admin_bar->add_node(
				array(
					'id'     => self::NODE_ID . '-preview',
					'parent' => self::NODE_ID,
					'title'  => __( 'View Live Preview', 'shied-world-schema-markup' ),
					'href'   => '#smsw-blocks',
					'meta'   => array(
						'class' => 'smsw-admin-bar-preview-item',
					),
				)
			);
		}
	}

	/**
	 * Enqueue the scroll-to-preview script on relevant screens.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WP hook callback signature.
	public static function enqueue_assets( $hook ) {
		$context = self::get_context();
		if ( empty( $context['show'] ) ) {
			return;
		}

		wp_enqueue_script(
			'smsw-admin-bar',
			SMSW_PLUGIN_URL . 'assets/js/admin-bar.js',
			array(),
			smsw_asset_version( 'assets/js/admin-bar.js' ),
			true
		);
	}
}
