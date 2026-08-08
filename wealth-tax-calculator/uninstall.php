<?php
/**
 * Uninstall script for Billionaire Wealth Tax Calculator
 *
 * This file is automatically executed when the plugin is deleted via the WordPress admin.
 * It cleans up all plugin data from the database.
 *
 * @package WealthTaxCalculator
 */

// Exit if not called from WordPress
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Delete all transients used by the plugin
global $wpdb;

// Delete transients with our prefix pattern (wtc_comparisons_data_v*)
$wpdb->query(
    "DELETE FROM {$wpdb->options} 
    WHERE option_name LIKE '_transient_wtc_comparisons_data_%' 
    OR option_name LIKE '_transient_timeout_wtc_comparisons_data_%'
    OR option_name LIKE '_transient_wtc_geo_bucket_%'
    OR option_name LIKE '_transient_timeout_wtc_geo_bucket_%'
    OR option_name LIKE '_transient_wtc_github_update_%'
    OR option_name LIKE '_transient_timeout_wtc_github_update_%'
    OR option_name = '_transient_wtc_updater_install_lock'
    OR option_name = '_transient_timeout_wtc_updater_install_lock'"
);

// Delete analytics options
delete_option( 'wtc_policy_analytics_daily' );
delete_option( 'wtc_analytics_enabled' );
delete_option( 'wtc_analytics_geo_enabled' );
delete_option( 'wtc_analytics_retention_days' );
delete_option( 'wtc_analytics_fingerprint_enabled' );
delete_option( 'wtc_analytics_schema_version' );
delete_option( 'wtc_analytics_version' );
delete_option( 'wtc_county_backfill_state' );

// Drop the submissions table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wtc_submissions" );

// Delete updater options
delete_option( 'wtc_auto_update_enabled' );
delete_option( 'wtc_updater_last_error' );
delete_option( 'wtc_updater_last_check' );

// Clear any cached data
wp_cache_flush();
