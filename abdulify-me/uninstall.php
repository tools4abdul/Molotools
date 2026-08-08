<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * This script cleans up all Abdulify Me plugin data from the database,
 * including user settings, internal tracking options, transient caches,
 * and the avatar events tracking table.
 *
 * @package AbdulifyMe
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$wpdb->query(
    "DELETE FROM {$wpdb->options}
    WHERE option_name LIKE '_transient_am_github_update_%'
    OR option_name LIKE '_transient_timeout_am_github_update_%'
    OR option_name = '_transient_am_updater_install_lock'
    OR option_name = '_transient_timeout_am_updater_install_lock'"
);

// Delete user-facing settings
delete_option( 'am_auto_update_enabled' );

// Delete internal tracking options
delete_option( 'am_updater_last_error' );
delete_option( 'am_updater_last_check' );

// Drop avatar events tracking table
$table_name = $wpdb->prefix . 'abdulify_me_avatar_events';
$wpdb->query( "DROP TABLE IF EXISTS $table_name" );
