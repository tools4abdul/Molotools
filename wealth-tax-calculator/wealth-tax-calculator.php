<?php
/**
 * Plugin Name: Billionaire Wealth Tax Calculator
 * Plugin URI:  https://github.com/hexa-decim8/Molotools
 * Description: Interactive calculator showing estimated 10-year tax revenue from billionaire wealth at rates of 1%–10%, based on the 2026 Forbes estimate of $8.2 trillion. Embed with [billionaire_wealth_tax].
 * Version:     1.4.13
 * Author:      Molotools
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wealth-tax-calculator
 * Requires:    5.0
 * Requires PHP: 7.4
 * Tested up to: 6.6
 * GitHub Plugin URI: hexa-decim8/Molotools
 * GitHub Branch:     main
 * Primary Branch:    main
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin version constant - update this when releasing new versions
define( 'WTC_VERSION', '1.4.13' );

// Plugin constants
define( 'WTC_PLUGIN_BASENAME', 'wealth-tax-calculator/wealth-tax-calculator.php' );
define( 'WTC_GITHUB_REPO', 'hexa-decim8/Molotools' );
define( 'WTC_RELEASE_ASSET', 'wealth-tax-calculator.zip' );
define( 'WTC_BILLIONAIRE_WEALTH', 8.2e12 ); // $8.2 trillion (Forbes 2026 estimate)
define( 'WTC_TAX_RATE_MIN', 1 );
define( 'WTC_TAX_RATE_MAX', 10 );
define( 'WTC_CACHE_TTL', 5 * MINUTE_IN_SECONDS );
define( 'WTC_UPDATE_ERROR_TTL', 5 * MINUTE_IN_SECONDS );
define( 'WTC_UPDATE_CRON_HOOK', 'wtc_run_scheduled_update_check' );
define( 'WTC_UPDATE_CRON_SCHEDULE', 'wtc_every_five_minutes' );
define( 'WTC_AUTO_INSTALL_LOCK_TTL', 5 * MINUTE_IN_SECONDS );
define( 'WTC_ANALYTICS_OPTION_KEY', 'wtc_policy_analytics_daily' );
define( 'WTC_ANALYTICS_ENABLED_OPTION', 'wtc_analytics_enabled' );
define( 'WTC_ANALYTICS_GEO_OPTION', 'wtc_analytics_geo_enabled' );
define( 'WTC_ANALYTICS_RETENTION_OPTION', 'wtc_analytics_retention_days' );
define( 'WTC_ANALYTICS_FINGERPRINT_OPTION', 'wtc_analytics_fingerprint_enabled' );

// ---------------------------------------------------------------------------
// Self-contained GitHub update checker — no extra plugins required.
// Hooks into WordPress's native update system.
// Checks: https://api.github.com/repos/hexa-decim8/Molotools/releases/latest
// Expects a release asset named "wealth-tax-calculator.zip" on each release.
// Uses a best-effort 5-minute WP-Cron schedule for release checks and installs.
// ---------------------------------------------------------------------------
class WTC_GitHub_Updater {

    private $slug;       // plugin slug: folder/file.php
    private $repo;       // GitHub repo: owner/repo
    private $version;    // current installed version
    private $cache_key;
    private $cache_ttl = WTC_CACHE_TTL;

    public function __construct( $slug, $repo, $version ) {
        $this->slug      = $slug;
        $this->repo      = $repo;
        $this->version   = $version;
        $this->cache_key = 'wtc_github_update_' . md5( $slug );

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
        add_filter( 'upgrader_source_selection', array( $this, 'fix_folder_name' ), 10, 4 );
        add_filter( 'auto_update_plugin', array( $this, 'enable_auto_updates' ), 10, 2 );
        add_action( WTC_UPDATE_CRON_HOOK, array( $this, 'run_scheduled_update' ) );
        add_action( 'init', array( $this, 'ensure_schedule' ) );
    }

    // Minimal implementation here — full class exists in original file but was large.
}
