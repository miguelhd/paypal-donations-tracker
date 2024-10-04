<?php
/**
 * Plugin Name: PayPal Donations Tracker
 * Plugin URI: https://miguelhd.com
 * Description: A plugin to accept and track donations via the PayPal Standard SDK for non-profit organizations.
 * Version: 1.0
 * Author: Miguel Hernández Domenech
 * Author URI: https://miguelhd.com
 * License: GPLv2 or later
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// Include the main class file
include_once plugin_dir_path( __FILE__ ) . 'includes/class-paypal-donations-tracker.php';

// Register activation and deactivation hooks
register_activation_hook( __FILE__, array( 'PayPal_Donations_Tracker', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PayPal_Donations_Tracker', 'deactivate' ) );

// Add a Settings link to the Plugins page
add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), 'paypal_donations_tracker_add_settings_link' );

/**
 * Add a settings link to the plugins page.
 *
 * @param array $links Existing links.
 * @return array Links including the settings link.
 */
function paypal_donations_tracker_add_settings_link( $links ) {
    $settings_link = '<a href="' . admin_url('admin.php?page=paypal-donations-tracker-settings') . '">Settings</a>';
    array_unshift( $links, $settings_link );
    return $links;
}