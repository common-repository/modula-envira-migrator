<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Modula_Plugin_Checker' ) ) {

	class Modula_Plugin_Checker {

		/**
		 * Holds the class object.
		 *
		 * @var object
		 *
		 * @since 1.0.0
		 */
		public static $instance;

		/**
		 * Returns the singleton instance of the class.
		 *
		 * @since 1.0.0
		 */
		public static function get_instance() {

			if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Modula_Plugin_Checker ) ) {
				self::$instance = new Modula_Plugin_Checker();
			}

			return self::$instance;

		}

		/**
		 * Construct our class
		 *
		 * @since 1.0.0
		 */
		public function __construct() {

		}

		/**
		 * Get the plugins
		 *
		 * @param string $plugin_folder
		 *
		 * @return array[]
		 * @since 1.0.0
		 */
		public static function get_plugins( $plugin_folder = '' ) {

			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			return get_plugins( $plugin_folder );
		}

		/**
		 * Get plugin basename
		 *
		 * @param $slug
		 *
		 * @return int|mixed|string
		 * @since 1.0.0
		 */
		public static function _get_plugin_basename_from_slug( $slug ) {

			$keys = array_keys( self::get_plugins() );

			foreach ( $keys as $key ) {
				if ( preg_match( '|^' . $slug . '/|', $key ) ) {
					return $key;
				}
			}

			return $slug;
		}

		/**
		 * Check if
		 *
		 * @param $slug
		 *
		 * @return bool
		 */
		public static function check_modula_is_installed( $slug ) {

			$plugin_path = self::_get_plugin_basename_from_slug( $slug );

			if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_path ) ) {
				return true;
			}

			return false;
		}

		/**
		 * @return bool
		 */
		public static function check_modula_is_active( $slug ) {

			$plugin_path = self::_get_plugin_basename_from_slug( $slug );

			if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_path ) ) {
				include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

				return is_plugin_active( $plugin_path );
			}
		}

		/**
		 * Check if Modula Lite is Active
		 *
		 * @return bool
		 * @since 1.0.0
		 */
		public function check_for_modula() {

			$check = array(
				'installed' => $this::check_modula_is_installed( 'modula-best-grid-gallery' ),
				'active'    => $this::check_modula_is_active( 'modula-best-grid-gallery' ),
			);

			if ( $check['active'] ) {
				return true;
			}

			return false;
		}

		/**
		 * Display Install & Activate LITE Notice
		 *
		 * @since 1.0.0
		 */
		public function display_modula_notice() {

			$check = array(
				'installed' => $this::check_modula_is_installed( 'modula-best-grid-gallery' ),
				'active'    => $this::check_modula_is_active( 'modula-best-grid-gallery' ),
			);

			if ( ! $check['installed'] ) {

				$label  = esc_html__( 'Install & Activate: Modula Lite', 'modula-envira-migrator' );
				$action = 'install';
				$url    = '#';
			} else {

				$label  = esc_html__( 'Activate: Modula Lite', 'modula-envira-migrator' );
				$action = 'activate';
				$url    = add_query_arg(
					array(
						'action'        => 'activate',
						'plugin'        => rawurlencode( $this::_get_plugin_basename_from_slug( 'modula-best-grid-gallery' ) ),
						'plugin_status' => 'all',
						'paged'         => '1',
						'_wpnonce'      => wp_create_nonce( 'activate-plugin_' . $this::_get_plugin_basename_from_slug( 'modula-best-grid-gallery' ) ),
					),
					admin_url( 'plugins.php' )
				);
			}

			wp_enqueue_style( 'modula-envira-install-lite', MODULA_ENVIRA_MIGRATOR_URL . 'assets/css/install-lite.css' );

			echo '<div id="modula-install-lite" class="notice">';
			echo '<h1>' . esc_html__( 'Install & Activate Modula Lite', 'modula-envira-migrator' ) . '</h1>';
			echo '<h4>' . esc_html__( 'In order for the migrator to work properly, you\'ll also need to have Modula installed & activated', 'modula-envira-migrator' ) . '</h4>';
			echo '<a href="' . esc_url( $url ) . '" data-action="' . esc_attr( $action ) . '" class="button button-primary button-hero" id="install-modula-lite">' . esc_html( $label ) . '</a>';
			echo '</div>';
		}
	}
}

