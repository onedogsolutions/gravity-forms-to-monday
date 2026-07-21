<?php
/**
 * Plugin Name:       Gravity Forms to Monday
 * Plugin URI:        https://github.com/onedogsolutions/gravity-forms-to-monday
 * Description:       Automatically push new Gravity Forms entries to Monday.com as items, with per-form field mapping.
 * Version:           1.1.2
 * Author:            One Dog Solutions
 * Author URI:        https://onedog.solutions
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gravity-forms-to-monday
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * @package GravityFormsToMonday
 */

defined( 'ABSPATH' ) || die();

define( 'GF_MONDAY_VERSION', '1.1.2' );
define( 'GF_MONDAY_MIN_GF_VERSION', '2.7' );
define( 'GF_MONDAY_PLUGIN_FILE', __FILE__ );
define( 'GF_MONDAY_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

// Register the add-on with Gravity Forms once the framework is loaded.
add_action( 'gform_loaded', array( 'GF_Monday_Bootstrap', 'load' ), 5 );

/**
 * Bootstraps the Monday add-on, guarding against a missing or outdated Gravity Forms install.
 */
class GF_Monday_Bootstrap {

	/**
	 * Load the add-on if the feed framework is available.
	 *
	 * @return void
	 */
	public static function load() {
		if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'requirements_notice' ) );
			return;
		}

		GFForms::include_feed_addon_framework();

		require_once GF_MONDAY_PLUGIN_PATH . 'includes/class-gf-monday-api.php';
		require_once GF_MONDAY_PLUGIN_PATH . 'includes/class-gf-monday-column-mapper.php';
		require_once GF_MONDAY_PLUGIN_PATH . 'class-gf-monday.php';

		GFAddOn::register( 'GF_Monday' );
	}

	/**
	 * Notify the admin when Gravity Forms is missing or too old.
	 *
	 * @return void
	 */
	public static function requirements_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: minimum required Gravity Forms version. */
			esc_html__( 'Gravity Forms to Monday requires Gravity Forms %s or greater. Please install and activate Gravity Forms.', 'gravity-forms-to-monday' ),
			esc_html( GF_MONDAY_MIN_GF_VERSION )
		);

		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}
}

/**
 * Convenience accessor for the add-on instance.
 *
 * @return GF_Monday|null
 */
function gf_monday() {
	return class_exists( 'GF_Monday' ) ? GF_Monday::get_instance() : null;
}
