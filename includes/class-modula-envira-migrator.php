<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Modula_Envira_Migrator {

	/**
	 * Holds the class object.
	 *
	 * @var object
	 *
	 * @since 1.0.0
	 */
	public static $instance;

	/**
	 * Primary class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		require_once MODULA_ENVIRA_MIGRATOR_PATH . 'includes/class-modula-plugin-checker.php';

		if ( class_exists( 'Modula_Plugin_Checker' ) ) {

			$modula_checker = Modula_Plugin_Checker::get_instance();

			if ( ! $modula_checker->check_for_modula() ) {

				if ( is_admin() ) {
					add_action( 'admin_notices', array( $modula_checker, 'display_modula_notice' ) );
				}

			} else {

				// Add AJAX
				add_action( 'wp_ajax_modula_importer_envira_gallery_import', array( $this, 'envira_gallery_import' ) );
				add_action( 'wp_ajax_modula_importer_envira_gallery_imported_update', array(
					$this,
					'update_imported'
				) );

				// Add infor used for Modula's migrate functionality
				add_filter( 'modula_migrator_sources', array( $this, 'add_source' ), 15, 1 );
				add_filter( 'modula_source_galleries_envira', array( $this, 'add_source_galleries' ), 15, 1 );
				add_filter( 'modula_g_gallery_envira', array( $this, 'add_gallery_info' ), 15, 3 );
				add_filter( 'modula_migrator_images_envira', array( $this, 'migrator_images' ), 15, 2 );
			}
		}

	}

	/**
	 * Returns the singleton instance of the class.
	 *
	 * @since 1.0.0
	 */
	public static function get_instance() {

		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Modula_Envira_Migrator ) ) {
			self::$instance = new Modula_Envira_Migrator();
		}

		return self::$instance;

	}


	/**
	 * Get all Envira Galleries
	 *
	 * @return mixed
	 *
	 * @since 1.0.0
	 */
	public function get_galleries() {

		global $wpdb;

		$galleries       = $wpdb->get_results( " SELECT * FROM " . $wpdb->prefix . "posts WHERE post_type = 'envira' AND post_status = 'publish'" );
		$empty_galleries = array();

		if ( count( $galleries ) != 0 ) {
			foreach ( $galleries as $key => $gallery ) {
				$count = $this->images_count( $gallery->ID );

				if ( $count == 0 ) {
					unset( $galleries[ $key ] );
					$empty_galleries[ $key ] = $gallery;
				}
			}

			if ( count( $galleries ) != 0 ) {
				$return_galleries['valid_galleries'] = $galleries;
			}
			if ( count( $empty_galleries ) != 0 ) {
				$return_galleries['empty_galleries'] = $empty_galleries;
			}

			if ( count( $return_galleries ) != 0 ) {
				return $return_galleries;
			}
		}

		return false;
	}


	/**
	 * Get gallery image count
	 *
	 * @param $id
	 *
	 * @return int
	 *
	 * @since 1.0.0
	 */
	public function images_count( $id ) {

		$images = get_post_meta( $id, '_eg_gallery_data', true );

		return count( $images['gallery'] );
	}

	/**
	 * Imports a gallery from Envira to Modula
	 *
	 * @param string $gallery_id
	 *
	 * @throws JsonException
	 * @since 1.0.0
	 */
	public function envira_gallery_import( $gallery_id = '' ) {

		global $wpdb;
		$modula_importer = Modula_Importer::get_instance();

		// Set max execution time so we don't timeout
		ini_set( 'max_execution_time', 0 );
		set_time_limit( 0 );

		// If no gallery ID, get from AJAX request
		if ( empty( $gallery_id ) ) {

			// Run a security check first.
			check_ajax_referer( 'modula-importer', 'nonce' );

			if ( ! isset( $_POST['id'] ) ) {
				$this->modula_import_result( false, esc_html__( 'No gallery was selected', 'modula-envira-migrator' ), false );
			}

			$gallery_id = absint( $_POST['id'] );

		}

		$imported_galleries = get_option( 'modula_importer' );

		// If already migrated don't migrate
		if ( isset( $imported_galleries['galleries']['envira'][ $gallery_id ] ) ) {

			$modula_gallery = get_post_type( $imported_galleries['galleries']['envira'][ $gallery_id ] );

			if ( 'modula-gallery' === $modula_gallery ) {
				// Trigger delete function if option is set to delete
				if ( isset( $_POST['clean'] ) && 'delete' == $_POST['clean'] ) {
					$this->clean_entries( $gallery_id );
				}
				$this->modula_import_result( false, esc_html__( 'Gallery already migrated!', 'modula-envira-migrator' ), false );
			}
		}


		// Get all images attached to the gallery
		$modula_images = array();

		// get gallery data so we can get title, description and alt from envira
		$envira_gallery_data = $modula_importer->prepare_images( 'envira', $gallery_id );
		$envira_settings     = get_post_meta( $gallery_id, '_eg_gallery_data', true );

		if ( isset( $envira_gallery_data ) && count( $envira_gallery_data ) > 0 ) {
			foreach ( $envira_gallery_data as $imageID => $image ) {

				$envira_image_title = ( ! isset( $image['title'] ) || '' != $image['title'] ) ? $image['title'] : '';

				$envira_image_caption = ( ! isset( $image['caption'] ) || '' != $image['caption'] ) ? $image['caption'] : wp_get_attachment_caption( $imageID );

				$envira_image_alt = ( ! isset( $image['alt'] ) || '' != $image['alt'] ) ? $image['alt'] : get_post_meta( $imageID, '_wp_attachment_image_alt', true );
				$envira_image_url = ( ! isset( $image['link'] ) || '' != $image['link'] ) ? $image['link'] : '';
				$target           = ( isset( $image['link_new_window'] ) && '1' == $image['link_new_window'] ) ? 1 : 0;
				$image_url        = wp_get_attachment_url( $imageID );

				if ( $envira_image_url == $image_url ) {
					$envira_image_url = '';
				}
				$modula_images[] = apply_filters( 'modula_migrate_image_data', array(
					'id'          => absint( $imageID ),
					'alt'         => sanitize_text_field( $envira_image_alt ),
					'title'       => sanitize_text_field( $envira_image_title ),
					'description' => wp_filter_post_kses( $envira_image_caption ),
					'halign'      => 'center',
					'valign'      => 'middle',
					'link'        => esc_url_raw( $envira_image_url ),
					'target'      => absint( $target ),
					'width'       => 2,
					'height'      => 2,
					'filters'     => ''
				), $imageID, $envira_settings, 'envira' );
			}
		}

		if ( count( $modula_images ) == 0 ) {
			// Trigger delete function if option is set to delete
			if ( isset( $_POST['clean'] ) && 'delete' == $_POST['clean'] ) {
				$this->clean_entries( $gallery_id );
			}
			$this->modula_import_result( false, esc_html__( 'No images found in gallery. Skipping gallery...', 'modula-envira-migrator' ), false );
		}

		$last_row_align = 'justify';
		$grid_type      = 'automatic';

		if ( isset( $envira_settings['config']['justified_last_row'] ) &&  'hide' != $envira_settings['config']['justified_last_row']  ) {
			$last_row_align = $envira_settings['config']['justified_last_row'];
		}

		if ( '0' != $envira_settings['config']['columns'] ) {
			$grid_type = $envira_settings['config']['columns'];
		}

		$socials                 = ( isset( $envira_settings['config']['social'] ) && 1 == $envira_settings['config']['social'] ) ? 1 : 0;
		$facebook                = ( isset( $envira_settings['config']['social_facebook'] ) && 1 == $envira_settings['config']['social_facebook'] ) ? 1 : 0;
		$twitter                 = ( isset( $envira_settings['config']['social_twitter'] ) && 1 == $envira_settings['config']['social_twitter'] ) ? 1 : 0;
		$pinterest               = ( isset( $envira_settings['config']['social_pinterest'] ) && 1 == $envira_settings['config']['social_pinterest'] ) ? 1 : 0;
		$linkedin                = ( isset( $envira_settings['config']['social_linkedin'] ) && 1 == $envira_settings['config']['social_linkedin'] ) ? 1 : 0;
		$email                   = ( isset( $envira_settings['config']['social_email'] ) && 1 == $envira_settings['config']['social_email'] ) ? 1 : 0;
		$email_subject           = esc_html__( 'Check out this awesome image !!', 'modula-envira-migrator' );
		$email_body              = esc_html__( 'Here is the link to the image : %%image_link%% and this is the link to the gallery : %%gallery_link%%', 'modula-envira-migrator' );
		$modula_captions_title   = 0;
		$modula_captions_caption = 0;

		if ( isset( $envira_settings['config']['social_email_subject'] ) && '' != $envira_settings['config']['social_email_subject'] ) {
			$email_subject = str_replace( '{title}', sanitize_text_field( get_the_title( $gallery_id ) ), $envira_settings['config']['social_email_subject'] );
		}

		if ( isset( $envira_settings['config']['social_email_message'] ) && '' != $envira_settings['config']['social_email_message'] ) {
			$email_body = str_replace( '{url}', '%%gallery_link%%', $envira_settings['config']['social_email_message'] );
			$email_body = str_replace( '{photo_url}', '%%image_link%%', $email_body );
		}

		if ( isset( $envira_settings['config']['gallery_column_title_caption'] ) ) {
			if ( '0' == $envira_settings['config']['gallery_column_title_caption'] ) {
				$modula_captions_title   = 1;
				$modula_captions_caption = 1;
			} else if ( 'title' == $envira_settings['config']['gallery_column_title_caption'] ) {
				$modula_captions_title   = 0;
				$modula_captions_caption = 1;
			} else if ( 'caption' == $envira_settings['config']['gallery_column_title_caption'] ) {
				$modula_captions_title   = 1;
				$modula_captions_caption = 0;
			}
		}

		$modula_settings = apply_filters( 'modula_migrate_gallery_data', array(
			'type'                  => 'grid',
			'grid_type'             => sanitize_text_field( $grid_type ),
			'grid_image_size'       => ( 'default' == $envira_settings['config']['image_size'] ) ? 'custom' : sanitize_text_field( $envira_settings['config']['image_size'] ),
			'grid_image_dimensions' => array(
				'width'  => sanitize_text_field( $envira_settings['config']['crop_width'] ),
				'height' => sanitize_text_field( $envira_settings['config']['crop_height'] )
			),
			'gutter'                => absint( $envira_settings['config']['gutter'] ),
			'grid_row_height'       => absint( $envira_settings['config']['justified_row_height'] ),
			'grid_justify_last_row' => sanitize_text_field( $last_row_align ),
			'grid_image_crop'       => ( isset( $envira_settings['config']['crop'] ) && 0 != $envira_settings['config']['crop'] ) ? 1 : 0,
			'lazy_load'             => ( isset( $envira_settings['config']['lazy_loading'] ) && 0 != $envira_settings['config']['lazy_loading'] ) ? 1 : 0,
			'hide_title'            => $modula_captions_title,
			'hide_description'      => $modula_captions_caption,
			'enableSocial'          => $socials,
			'enableTwitter'         => $twitter,
			'enableFacebook'        => $facebook,
			'enablePinterest'       => $pinterest,
			'enableLinkedin'        => $linkedin,
			'enableEmail'           => $email,
			'emailSubject'          => $email_subject,
			'emailMessage'          => $email_body
		), $envira_settings, 'envira' );

		// Get Modula Gallery defaults, used to set modula-settings metadata
		$modula_settings = wp_parse_args( $modula_settings, Modula_CPT_Fields_Helper::get_defaults() );

		// Create Modula CPT
		$modula_gallery_id = wp_insert_post( array(
			'post_type'   => 'modula-gallery',
			'post_status' => 'publish',
			'post_title'  => sanitize_text_field( get_the_title( $gallery_id ) ),
		) );

		// Attach meta modula-settings to Modula CPT
		update_post_meta( $modula_gallery_id, 'modula-settings', $modula_settings );
		// Attach meta modula-images to Modula CPT
		update_post_meta( $modula_gallery_id, 'modula-images', $modula_images );

		$envira_shortcodes     = '[envira-gallery id="' . absint( $gallery_id ) . '"]';
		$envira_slug           = get_post_field( 'post_name', $gallery_id );
		$envira_slug_shortcode = '[envira-gallery slug="' . sanitize_title( $envira_slug ) . '"]';
		$modula_shortcode      = '[modula id="' . absint( $modula_gallery_id ) . '"]';

		// Replace Envira id shortcode with Modula Shortcode in Posts, Pages and CPTs
		$wpdb->query( $wpdb->prepare( "UPDATE " . $wpdb->prefix . "posts SET post_content = REPLACE(post_content, %s, %s)",
		$envira_shortcodes, $modula_shortcode ) );

		// Replace Envira slug shortcode with Modula Shortcode in Posts, Pages and CPTs
		$wpdb->query( $wpdb->prepare( "UPDATE " . $wpdb->prefix . "posts SET post_content = REPLACE(post_content, %s, %s)",
		$envira_slug_shortcode, $modula_shortcode ) );

		// Trigger delete function if option is set to delete
		if ( isset( $_POST['clean'] ) && 'delete' == $_POST['clean'] ) {
			$this->clean_entries( $gallery_id );
		}

		$this->modula_import_result( true, wp_kses_post( '<i class="imported-check dashicons dashicons-yes"></i>' ), $modula_gallery_id );
	}

	/**
	 * Update imported galleries
	 *
	 *
	 * @since 1.0.0
	 */
	public function update_imported() {

		check_ajax_referer( 'modula-importer', 'nonce' );

		if ( ! isset( $_POST['galleries'] ) ) {
			wp_send_json_error();
		}
		$galleries = array_map( 'absint', wp_unslash( $_POST['galleries'] ) );

		$importer_settings = get_option( 'modula_importer' );

		// first check if array
		if ( ! is_array( $importer_settings ) ) {
			$importer_settings = array();
		}

		if ( ! isset( $importer_settings['galleries']['envira'] ) ) {
			$importer_settings['galleries']['envira'] = array();
		}

		if ( is_array( $galleries ) && count( $galleries ) > 0 ) {
			foreach ( $galleries as $key => $value ) {
				$importer_settings['galleries']['envira'][ absint( $key ) ] = absint( $value );
			}
		}

		// Remember that this gallery has been imported
		update_option( 'modula_importer', $importer_settings );

		// Set url for migration complete
		$url = admin_url( 'edit.php?post_type=modula-gallery&page=modula&modula-tab=importer&migration=complete' );

		if ( isset( $_POST['clean'] ) && 'delete' === $_POST['clean'] ) {
			// Set url for migration and cleaning complete
			$url = admin_url( 'edit.php?post_type=modula-gallery&page=modula&modula-tab=importer&migration=complete&delete=complete' );
		}

		echo esc_url( $url );
		wp_die();
	}

	/**
	 * Returns result
	 *
	 * @param $success
	 * @param $message
	 * @param $gallery_id
	 *
	 * @throws JsonException
	 * @since 1.0.0
	 */
	public function modula_import_result( $success, $message, $gallery_id = false ) {

		echo json_encode( array(
			'success'           => (bool) $success,
			'message'           => (string) $message,
			'modula_gallery_id' => $gallery_id
		) );
		die;
	}

	/**
	 * Delete old entries from database
	 *
	 * @param $gallery_id
	 *
	 * @since 1.0.0
	 */
	public function clean_entries( $gallery_id ) {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "DELETE FROM  {$wpdb->posts} WHERE ID = %d", absint( $gallery_id ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM  {$wpdb->postmeta} WHERE post_id = %d", absint( $gallery_id ) ) );
	}

	/**
	 * Add Envira source to Modula gallery sources
	 *
	 * @param $sources
	 *
	 * @return mixed
	 * @since 1.0.0
	 */
	public function add_source( $sources ) {

		global $wpdb;

		$envira = $wpdb->get_results( " SELECT COUNT(ID) FROM " . $wpdb->prefix . "posts WHERE post_type ='envira'" );

		$envira_return = ( null != $envira ) ? get_object_vars( $envira[0] ) : false;

		if ( $envira && null != $envira && ! empty( $envira ) && $envira_return && '0' != $envira_return['COUNT(ID)'] ) {
			$sources['envira'] = 'Envira Gallery';
		}

		return $sources;
	}

	/**
	 * Add our source galleries
	 *
	 * @param $galleries
	 *
	 * @return false|mixed
	 * @since 1.0.0
	 */
	public function add_source_galleries( $galleries ) {

		return $this->get_galleries();
	}

	/**
	 * Return Gallery info
	 *
	 * @param $g_gallery
	 * @param $gallery
	 * @param $import_settings
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function add_gallery_info( $g_gallery, $gallery, $import_settings ) {

		$modula_gallery = get_post_type( $import_settings['galleries']['envira'][ $gallery->ID ] );
		$imported       = false;

		if ( isset( $import_settings['galleries']['envira'] ) && 'modula-gallery' === $modula_gallery ) {
			$imported = true;
		}


		return array(
			'id'       => $gallery->ID,
			'imported' => $imported,
			'title'    => '<a href="' . admin_url( '/post.php?post=' . $gallery->ID . '&action=edit' ) . '" target="_blank">' . esc_html( $gallery->post_title ) . '</a>',
			'count'    => $this->images_count( $gallery->ID )
		);
	}

	/**
	 * Return Envira images
	 *
	 * @param $images
	 * @param $data
	 *
	 * @return mixed
	 * @since 1.0.0
	 */
	public function migrator_images( $images, $data ) {

		$settings = get_post_meta( $data, '_eg_gallery_data', true );

		return $settings['gallery'];

	}

}