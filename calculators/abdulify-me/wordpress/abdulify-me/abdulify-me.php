<?php
/**
 * Plugin Name: Abdulify Me
 * Plugin URI:  https://github.com/hexa-decim8/Molotools
 * Description: Upload a photo, add lightweight Abdul El-Sayed support overlays, and download the result directly in the browser. Embed with [abdulify_me].
 * Version:     0.1.19
 * Author:      Molotools
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: abdulify-me
 * Requires:    5.0
 * Requires PHP: 7.4
 * Tested up to: 6.6
 * GitHub Plugin URI: hexa-decim8/Molotools
 * GitHub Branch:     main
 * Primary Branch:    main
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ABDULIFY_ME_VERSION', '0.1.19' );
define( 'ABDULIFY_ME_PLUGIN_BASENAME', 'abdulify-me/abdulify-me.php' );
define( 'ABDULIFY_ME_GITHUB_REPO', 'hexa-decim8/Molotools' );
define( 'ABDULIFY_ME_RELEASE_ASSET', 'abdulify-me.zip' );
define( 'ABDULIFY_ME_CACHE_TTL', 5 * MINUTE_IN_SECONDS );
define( 'ABDULIFY_ME_UPDATE_ERROR_TTL', 5 * MINUTE_IN_SECONDS );
define( 'ABDULIFY_ME_UPDATE_CRON_HOOK', 'am_run_scheduled_update_check' );
define( 'ABDULIFY_ME_UPDATE_CRON_SCHEDULE', 'am_every_five_minutes' );
define( 'ABDULIFY_ME_AUTO_INSTALL_LOCK_TTL', 5 * MINUTE_IN_SECONDS );

class AM_GitHub_Updater {

    private $slug;
    private $repo;
    private $version;
    private $cache_key;
    private $cache_ttl = ABDULIFY_ME_CACHE_TTL;

    public function __construct( $slug, $repo, $version ) {
        $this->slug      = $slug;
        $this->repo      = $repo;
        $this->version   = $version;
        $this->cache_key = 'am_github_update_' . md5( $slug );

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
        add_filter( 'upgrader_source_selection', array( $this, 'fix_folder_name' ), 10, 4 );
        add_filter( 'auto_update_plugin', array( $this, 'enable_auto_updates' ), 10, 2 );
        add_action( ABDULIFY_ME_UPDATE_CRON_HOOK, array( $this, 'run_scheduled_update' ) );
        add_action( 'init', array( $this, 'ensure_schedule' ) );
    }

    private function log_error( $message ) {
        update_option( 'am_updater_last_error', $message, false );
        error_log( '[Abdulify Me Updater] ' . $message );
    }

    private function clear_last_error() {
        delete_option( 'am_updater_last_error' );
    }

    private function record_successful_check() {
        update_option( 'am_updater_last_check', time(), false );
        $this->clear_last_error();
    }

    public function ensure_schedule() {
        if ( ! wp_next_scheduled( ABDULIFY_ME_UPDATE_CRON_HOOK ) ) {
            if ( ! wp_schedule_event( time() + ABDULIFY_ME_CACHE_TTL, ABDULIFY_ME_UPDATE_CRON_SCHEDULE, ABDULIFY_ME_UPDATE_CRON_HOOK ) ) {
                $this->log_error( 'Unable to schedule the recurring 5-minute update check.' );
            }
        }
    }

    public function clear_cached_release() {
        delete_transient( $this->cache_key );
    }

    private function normalize_version_from_tag( $tag_name ) {
        if ( ! is_string( $tag_name ) ) {
            return null;
        }

        $tag_name = trim( $tag_name );

        if ( preg_match( '/^(?:am-)?v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)$/', $tag_name, $matches ) ) {
            return $matches[1];
        }

        if ( preg_match( '/^(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)$/', $tag_name, $matches ) ) {
            return $matches[1];
        }

        return null;
    }

    private function get_release( $force = false ) {
        $cached = $force ? false : get_transient( $this->cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $url      = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
        $response = wp_remote_get(
            $url,
            array(
                'headers' => array(
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
                ),
                'timeout' => 10,
            )
        );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            $message = is_wp_error( $response )
                ? $response->get_error_message()
                : 'GitHub API returned HTTP ' . wp_remote_retrieve_response_code( $response );

            $this->log_error( 'Release check failed: ' . $message );
            set_transient( $this->cache_key, null, ABDULIFY_ME_UPDATE_ERROR_TTL );
            return null;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ) );
        if ( ! is_object( $release ) || empty( $release->tag_name ) ) {
            $this->log_error( 'GitHub release payload was invalid or missing tag_name.' );
            set_transient( $this->cache_key, null, ABDULIFY_ME_UPDATE_ERROR_TTL );
            return null;
        }

        if ( ! $this->normalize_version_from_tag( $release->tag_name ) ) {
            $this->log_error( 'Latest GitHub release tag is not a recognized version: ' . $release->tag_name );
            set_transient( $this->cache_key, null, ABDULIFY_ME_UPDATE_ERROR_TTL );
            return null;
        }

        set_transient( $this->cache_key, $release, $this->cache_ttl );
        $this->record_successful_check();
        return $release;
    }

    private function get_remote_version( $release ) {
        if ( ! is_object( $release ) || empty( $release->tag_name ) ) {
            return null;
        }

        return $this->normalize_version_from_tag( $release->tag_name );
    }

    private function build_plugin_update_item( $remote_version, $asset_url ) {
        return (object) array(
            'slug' => dirname( $this->slug ),
            'plugin' => $this->slug,
            'new_version' => $remote_version,
            'url' => 'https://github.com/' . $this->repo,
            'package' => $asset_url,
            'tested' => '',
            'requires_php' => '7.4',
            'icons' => array(),
            'banners' => array(),
        );
    }

    private function get_asset_url( $release ) {
        if ( empty( $release->assets ) ) {
            $this->log_error( 'Latest GitHub release is missing assets.' );
            return null;
        }

        foreach ( $release->assets as $asset ) {
            if ( $asset->name === ABDULIFY_ME_RELEASE_ASSET ) {
                return $asset->browser_download_url;
            }
        }

        $this->log_error( 'Latest GitHub release is missing the required ' . ABDULIFY_ME_RELEASE_ASSET . ' asset.' );
        return null;
    }

    public function refresh_update_data( $force = false ) {
        require_once ABSPATH . 'wp-includes/update.php';

        if ( $force ) {
            $this->clear_cached_release();
            delete_site_transient( 'update_plugins' );
            if ( ! function_exists( 'wp_clean_plugins_cache' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            wp_clean_plugins_cache( true );
        }

        wp_update_plugins();

        return get_site_transient( 'update_plugins' );
    }

    private function maybe_install_update( $transient ) {
        if ( ! isset( $transient->response[ $this->slug ] ) ) {
            return;
        }

        $lock_key = 'am_updater_install_lock';
        if ( get_transient( $lock_key ) ) {
            return;
        }

        if ( ! function_exists( 'wp_clean_plugins_cache' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        set_transient( $lock_key, 1, ABDULIFY_ME_AUTO_INSTALL_LOCK_TTL );

        $upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
        $result   = $upgrader->upgrade( $this->slug );

        delete_transient( $lock_key );

        if ( is_wp_error( $result ) ) {
            $this->log_error( 'Automatic update failed: ' . $result->get_error_message() );
            return;
        }

        if ( ! $result ) {
            $this->log_error( 'Automatic update returned an empty result.' );
            return;
        }

        $this->clear_cached_release();
        delete_site_transient( 'update_plugins' );
        wp_clean_plugins_cache( true );
    }

    public function run_scheduled_update() {
        $transient = $this->refresh_update_data( true );
        if ( ! is_object( $transient ) ) {
            $this->log_error( 'Scheduled update check did not produce a valid plugin update transient.' );
            return;
        }

        $this->maybe_install_update( $transient );
    }

    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_release();
        if ( ! $release ) {
            return $transient;
        }

        $remote_version = $this->get_remote_version( $release );
        $asset_url      = $this->get_asset_url( $release );

        if ( ! $remote_version || ! $asset_url ) {
            return $transient;
        }

        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = array();
        }
        if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
            $transient->no_update = array();
        }

        if ( version_compare( $this->version, $remote_version, '<' ) ) {
            $transient->response[ $this->slug ] = $this->build_plugin_update_item( $remote_version, $asset_url );
            unset( $transient->no_update[ $this->slug ] );
        } else {
            $transient->no_update[ $this->slug ] = $this->build_plugin_update_item( $remote_version, $asset_url );
            unset( $transient->response[ $this->slug ] );
        }

        return $transient;
    }

    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }
        if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->slug ) ) {
            return $result;
        }

        $release = $this->get_release();
        if ( ! $release ) {
            return $result;
        }

        $remote_version = $this->get_remote_version( $release );
        $asset_url      = $this->get_asset_url( $release );

        if ( ! $remote_version || ! $asset_url ) {
            return $result;
        }

        return (object) array(
            'name' => 'Abdulify Me',
            'slug' => dirname( $this->slug ),
            'version' => $remote_version,
            'author' => '<a href="https://github.com/hexa-decim8">Molotools</a>',
            'homepage' => 'https://github.com/' . $this->repo,
            'download_link' => $asset_url,
            'sections' => array(
                'description' => isset( $release->body ) ? nl2br( esc_html( $release->body ) ) : 'See GitHub for full changelog.',
            ),
        );
    }

    public function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra ) {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->slug ) {
            return $source;
        }

        $correct = trailingslashit( $remote_source ) . dirname( $this->slug ) . '/';

        if ( $source !== $correct ) {
            global $wp_filesystem;
            if ( $wp_filesystem->move( $source, $correct ) ) {
                return $correct;
            }
        }

        return $source;
    }

    public function enable_auto_updates( $update, $item ) {
        if ( isset( $item->slug ) && $item->slug === dirname( $this->slug ) ) {
            return get_option( 'am_auto_update_enabled', '1' ) === '1';
        }

        return $update;
    }
}

$abdulify_me_updater = new AM_GitHub_Updater(
    ABDULIFY_ME_PLUGIN_BASENAME,
    ABDULIFY_ME_GITHUB_REPO,
    ABDULIFY_ME_VERSION
);

// ---------------------------------------------------------------------------
// Admin Settings Page for Updater
// ---------------------------------------------------------------------------
class AM_Admin_Settings {

    private $updater;

    public function __construct( $updater ) {
        $this->updater = $updater;

        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_am_check_updates', array( $this, 'handle_manual_update_check' ) );
        add_filter( 'auto_update_plugin', array( $this, 'enable_auto_updates' ), 10, 2 );
    }

    /**
     * Add settings page under Settings menu
     */
    public function add_settings_page() {
        add_options_page(
            __( 'Abdulify Me Updates', 'abdulify-me' ),
            __( 'Abdulify Me Updates', 'abdulify-me' ),
            'manage_options',
            'abdulify-me-updater',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting( 'am_updater_settings', 'am_auto_update_enabled' );
    }

    /**
     * Enable auto-updates if setting is enabled
     */
    public function enable_auto_updates( $update, $item ) {
        if ( isset( $item->slug ) && $item->slug === 'abdulify-me' ) {
            return get_option( 'am_auto_update_enabled', '1' ) === '1';
        }
        return $update;
    }

    /**
     * Handle manual update check
     */
    public function handle_manual_update_check() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized access', 'abdulify-me' ) );
        }

        check_admin_referer( 'am_check_updates' );

        $this->updater->refresh_update_data( true );

        // Redirect back with success message
        wp_redirect( add_query_arg(
            array(
                'page'              => 'abdulify-me-updater',
                'update_check_done' => '1',
            ),
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    /**
     * Render the settings page
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Get current version and check for updates
        $current_version = ABDULIFY_ME_VERSION;
        $update_available = false;
        $latest_version = $current_version;

        // Check if there's an update available
        $update_plugins = get_site_transient( 'update_plugins' );
        if ( isset( $update_plugins->response['abdulify-me/abdulify-me.php'] ) ) {
            $update_available = true;
            $latest_version = $update_plugins->response['abdulify-me/abdulify-me.php']->new_version;
        }

        $auto_update_enabled = get_option( 'am_auto_update_enabled', '1' ) === '1';
        $update_check_done = isset( $_GET['update_check_done'] ) && $_GET['update_check_done'] === '1';
        $next_scheduled_check = wp_next_scheduled( ABDULIFY_ME_UPDATE_CRON_HOOK );
        $last_successful_check = (int) get_option( 'am_updater_last_check', 0 );
        $last_error = get_option( 'am_updater_last_error', '' );

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <?php if ( $update_check_done ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Update check completed!', 'abdulify-me' ); ?></p>
                </div>
            <?php endif; ?>

            <div class="card" style="max-width: 800px;">
                <h2><?php esc_html_e( 'Version Information', 'abdulify-me' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Current Version:', 'abdulify-me' ); ?></th>
                        <td><strong><?php echo esc_html( $current_version ); ?></strong></td>
                    </tr>
                    <?php if ( $update_available ) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Latest Version:', 'abdulify-me' ); ?></th>
                            <td>
                                <strong style="color: #d63638;"><?php echo esc_html( $latest_version ); ?></strong>
                                <span style="margin-left: 10px; color: #d63638;">
                                    <?php esc_html_e( '⚠️ Update Available', 'abdulify-me' ); ?>
                                </span>
                            </td>
                        </tr>
                    <?php else : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Status:', 'abdulify-me' ); ?></th>
                            <td>
                                <span style="color: #00a32a;">
                                    <?php esc_html_e( '✓ Up to date', 'abdulify-me' ); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>

            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e( 'Update Settings', 'abdulify-me' ); ?></h2>
                <form method="post" action="options.php">
                    <?php settings_fields( 'am_updater_settings' ); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Enable Automatic Updates', 'abdulify-me' ); ?></th>
                            <td>
                                <label>
                                    <input type="hidden" name="am_auto_update_enabled" value="0" />
                                    <input type="checkbox" name="am_auto_update_enabled" value="1" <?php checked( $auto_update_enabled, true ); ?> />
                                    <?php esc_html_e( 'Automatically update this plugin when a new version is available', 'abdulify-me' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( 'When enabled, Abdulify Me will automatically update to the latest version from GitHub.', 'abdulify-me' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( __( 'Save Settings', 'abdulify-me' ) ); ?>
                </form>
            </div>

            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e( 'Manual Update Check', 'abdulify-me' ); ?></h2>
                <p><?php esc_html_e( 'Click the button below to manually check for updates. This will refresh the update information immediately.', 'abdulify-me' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="am_check_updates" />
                    <?php wp_nonce_field( 'am_check_updates' ); ?>
                    <?php submit_button( __( 'Check for Updates', 'abdulify-me' ), 'secondary' ); ?>
                </form>
            </div>

            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e( 'Update Status', 'abdulify-me' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Last Check:', 'abdulify-me' ); ?></th>
                        <td>
                            <?php
                            if ( $last_successful_check > 0 ) {
                                echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_successful_check ) );
                            } else {
                                esc_html_e( 'Never', 'abdulify-me' );
                            }
                            ?>
                        </td>
                    </tr>
                    <?php if ( ! empty( $last_error ) ) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Last Error:', 'abdulify-me' ); ?></th>
                            <td>
                                <span style="color: #d63638;"><?php echo esc_html( $last_error ); ?></span>
                            </td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <?php
    }
}

// Register the updater settings page
$am_admin_settings = new AM_Admin_Settings( $abdulify_me_updater );

function abdulify_me_register_cron_schedule( $schedules ) {
    $schedules[ ABDULIFY_ME_UPDATE_CRON_SCHEDULE ] = array(
        'interval' => ABDULIFY_ME_CACHE_TTL,
        'display' => __( 'Every 5 Minutes (Abdulify Me)', 'abdulify-me' ),
    );

    return $schedules;
}
add_filter( 'cron_schedules', 'abdulify_me_register_cron_schedule' );

function abdulify_me_activate() {
    global $abdulify_me_updater;

    if ( $abdulify_me_updater instanceof AM_GitHub_Updater ) {
        $abdulify_me_updater->ensure_schedule();
    }
}
register_activation_hook( __FILE__, 'abdulify_me_activate' );

function abdulify_me_deactivate() {
    wp_clear_scheduled_hook( ABDULIFY_ME_UPDATE_CRON_HOOK );
    delete_transient( 'am_updater_install_lock' );
}
register_deactivation_hook( __FILE__, 'abdulify_me_deactivate' );

final class Abdulify_Me_Plugin {
    const SHORTCODE = 'abdulify_me';
    const SETTINGS_GROUP = 'abdulify_me_settings';
    const SESSION_ID_TRANSIENT = 'abdulify_me_session_';
    const OVERLAY_DIR = 'overlays';
    const OVERLAY_PREFIX = 'AFS-Social';

    public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
        add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_am_overlay_upload', array( $this, 'handle_overlay_upload' ) );
        add_action( 'wp_ajax_am_overlay_rename', array( $this, 'handle_overlay_rename' ) );
        add_action( 'wp_ajax_am_overlay_delete', array( $this, 'handle_overlay_delete' ) );
        add_filter( 'upload_mimes', array( $this, 'allow_overlay_mime_types' ) );
    }

    /**
     * WordPress core does not allow SVG uploads by default; register SVG and GIF
     * so wp_check_filetype() recognizes them for overlay uploads.
     */
    public function allow_overlay_mime_types( $mimes ) {
        $mimes['svg']  = 'image/svg+xml';
        $mimes['gif']  = 'image/gif';
        return $mimes;
    }

    public function register_settings_page() {
        add_options_page(
            __( 'Abdulify Me', 'abdulify-me' ),
            __( 'Abdulify Me', 'abdulify-me' ),
            'manage_options',
            'abdulify-me-settings',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        register_setting(
            self::SETTINGS_GROUP,
            'abdulify_me_overlay_names',
            array(
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_overlay_names' ),
                'show_in_rest'      => false,
            )
        );

        register_setting(
            self::SETTINGS_GROUP,
            'abdulify_me_deleted_bundled_overlays',
            array(
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_deleted_bundled_overlays' ),
                'show_in_rest'      => false,
            )
        );
    }

    /**
     * Sanitize deleted bundled overlays option (JSON-encoded array of overlay IDs)
     */
    public function sanitize_deleted_bundled_overlays( $value ) {
        if ( empty( $value ) ) {
            return '[]';
        }

        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( ! is_array( $decoded ) ) {
                return '[]';
            }
        } elseif ( is_array( $value ) ) {
            $decoded = $value;
        } else {
            return '[]';
        }

        $sanitized = array_values( array_unique( array_filter( array_map( 'sanitize_key', $decoded ) ) ) );

        return wp_json_encode( $sanitized );
    }

    /**
     * Sanitize overlay names option (JSON-encoded associative array)
     */
    public function sanitize_overlay_names( $value ) {
        if ( empty( $value ) ) {
            return '{}';
        }

        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( ! is_array( $decoded ) ) {
                return '{}';
            }
        } elseif ( is_array( $value ) ) {
            $decoded = $value;
        } else {
            return '{}';
        }

        // Sanitize each value
        $sanitized = array();
        foreach ( $decoded as $overlay_id => $custom_name ) {
            $overlay_id = sanitize_key( (string) $overlay_id );
            $custom_name = sanitize_text_field( (string) $custom_name );
            if ( ! empty( $overlay_id ) && ! empty( $custom_name ) ) {
                $sanitized[ $overlay_id ] = $custom_name;
            }
        }

        return wp_json_encode( $sanitized );
    }

    /**
     * Get custom name for an overlay by ID, or false if not set
     */
    private function get_overlay_custom_name( $overlay_id ) {
        $overlay_id = sanitize_key( (string) $overlay_id );
        if ( empty( $overlay_id ) ) {
            return false;
        }

        $names = $this->get_all_overlay_custom_names();
        return isset( $names[ $overlay_id ] ) ? $names[ $overlay_id ] : false;
    }

    /**
     * Get all custom overlay names as an associative array
     */
    private function get_all_overlay_custom_names() {
        $option = get_option( 'abdulify_me_overlay_names', '{}' );
        $names = json_decode( $option, true );
        return is_array( $names ) ? $names : array();
    }

    /**
     * Update custom name for an overlay
     */
    private function update_overlay_custom_name( $overlay_id, $name ) {
        $overlay_id = sanitize_key( (string) $overlay_id );
        $name = sanitize_text_field( (string) $name );

        if ( empty( $overlay_id ) || empty( $name ) ) {
            return false;
        }

        $names = $this->get_all_overlay_custom_names();
        $names[ $overlay_id ] = $name;

        return update_option( 'abdulify_me_overlay_names', wp_json_encode( $names ) );
    }

    /**
     * Delete custom name for an overlay
     */
    private function delete_overlay_custom_name( $overlay_id ) {
        $overlay_id = sanitize_key( (string) $overlay_id );

        if ( empty( $overlay_id ) ) {
            return false;
        }

        $names = $this->get_all_overlay_custom_names();
        if ( isset( $names[ $overlay_id ] ) ) {
            unset( $names[ $overlay_id ] );
            return update_option( 'abdulify_me_overlay_names', wp_json_encode( $names ) );
        }

        return false;
    }

    /**
     * Get list of overlay IDs that have been deleted and should not be re-seeded
     */
    private function get_deleted_bundled_overlays() {
        $option = get_option( 'abdulify_me_deleted_bundled_overlays', '[]' );
        $ids = json_decode( $option, true );
        return is_array( $ids ) ? $ids : array();
    }

    /**
     * Record an overlay ID as deleted so it is not re-seeded from the bundled directory
     */
    private function add_deleted_bundled_overlay( $overlay_id ) {
        $overlay_id = sanitize_key( (string) $overlay_id );

        if ( empty( $overlay_id ) ) {
            return false;
        }

        $ids = $this->get_deleted_bundled_overlays();
        if ( ! in_array( $overlay_id, $ids, true ) ) {
            $ids[] = $overlay_id;
        }

        return update_option( 'abdulify_me_deleted_bundled_overlays', wp_json_encode( $ids ) );
    }

    /**
     * Remove an overlay ID from the deleted list, e.g. when it is re-uploaded
     */
    private function remove_deleted_bundled_overlay( $overlay_id ) {
        $overlay_id = sanitize_key( (string) $overlay_id );

        if ( empty( $overlay_id ) ) {
            return false;
        }

        $ids = $this->get_deleted_bundled_overlays();
        $filtered = array_values( array_diff( $ids, array( $overlay_id ) ) );

        if ( count( $filtered ) === count( $ids ) ) {
            return false;
        }

        return update_option( 'abdulify_me_deleted_bundled_overlays', wp_json_encode( $filtered ) );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $overlays = $this->get_available_overlays();
        $custom_names = $this->get_all_overlay_custom_names();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Abdulify Me Settings', 'abdulify-me' ); ?></h1>

            <!-- Overlay Management Section -->
            <div class="card" style="max-width: 1000px; margin-top: 20px;">
                <h2><?php esc_html_e( 'Manage Overlays', 'abdulify-me' ); ?></h2>

                <!-- Upload Form -->
                <div style="margin-bottom: 30px; padding: 20px; background: #f1f1f1; border-radius: 4px;">
                    <h3><?php esc_html_e( 'Add New Overlay', 'abdulify-me' ); ?></h3>
                    <form id="am-overlay-upload-form" enctype="multipart/form-data" style="display: flex; gap: 10px; align-items: flex-end;">
                        <?php wp_nonce_field( 'am_overlay_upload', 'am_overlay_nonce' ); ?>
                        <div style="flex: 1;">
                            <label for="am-overlay-file" style="display: block; margin-bottom: 8px; font-weight: bold;">
                                <?php esc_html_e( 'Select Image File', 'abdulify-me' ); ?>
                            </label>
                            <input 
                                type="file" 
                                id="am-overlay-file" 
                                name="overlay_file" 
                                accept=".png,.jpg,.jpeg,.webp,.svg,.gif"
                                required
                                style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                            >
                            <p style="margin: 5px 0 0 0; font-size: 12px; color: #666;">
                                <?php esc_html_e( 'Supported: PNG, JPG, JPEG, WebP, SVG, GIF (Max 8 MB)', 'abdulify-me' ); ?>
                            </p>
                        </div>
                        <button type="submit" class="button button-primary" style="padding: 8px 15px;">
                            <?php esc_html_e( 'Upload Overlay', 'abdulify-me' ); ?>
                        </button>
                    </form>
                    <div id="am-upload-message" style="margin-top: 10px; display: none;"></div>
                </div>

                <!-- Existing Overlays List -->
                <h3><?php esc_html_e( 'Existing Overlays', 'abdulify-me' ); ?></h3>
                <?php if ( ! empty( $overlays ) ) : ?>
                    <div id="am-overlays-list" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
                        <?php foreach ( $overlays as $overlay ) : ?>
                            <div class="am-overlay-card" data-overlay-id="<?php echo esc_attr( $overlay['id'] ); ?>" style="border: 1px solid #ddd; border-radius: 4px; padding: 15px; background: #fff;">
                                <!-- Preview Image -->
                                <div style="margin-bottom: 12px; text-align: center;">
                                    <img 
                                        src="<?php echo esc_url( $overlay['url'] ); ?>" 
                                        alt="<?php echo esc_attr( $overlay['label'] ); ?>"
                                        style="max-width: 100%; height: auto; max-height: 120px; border: 1px solid #eee; border-radius: 3px;"
                                    >
                                </div>

                                <!-- Filename -->
                                <p style="margin: 8px 0; font-size: 12px; color: #666;">
                                    <strong><?php esc_html_e( 'File:', 'abdulify-me' ); ?></strong><br>
                                    <?php echo esc_html( $overlay['file'] ); ?>
                                </p>

                                <!-- Custom Name Input -->
                                <div style="margin: 12px 0;">
                                    <label for="am-name-<?php echo esc_attr( $overlay['id'] ); ?>" style="display: block; margin-bottom: 5px; font-weight: bold; font-size: 13px;">
                                        <?php esc_html_e( 'Display Name', 'abdulify-me' ); ?>
                                    </label>
                                    <input 
                                        type="text" 
                                        id="am-name-<?php echo esc_attr( $overlay['id'] ); ?>"
                                        class="am-overlay-name-input" 
                                        value="<?php echo esc_attr( isset( $custom_names[ $overlay['id'] ] ) ? $custom_names[ $overlay['id'] ] : $overlay['label'] ); ?>"
                                        maxlength="100"
                                        style="width: 100%; padding: 6px; border: 1px solid #ddd; border-radius: 3px; box-sizing: border-box;"
                                    >
                                    <small style="display: block; margin-top: 3px; color: #666;">
                                        <?php esc_html_e( 'Shows in the border dropdown on the website', 'abdulify-me' ); ?>
                                    </small>
                                </div>

                                <!-- Action Buttons -->
                                <div style="display: flex; gap: 8px; margin-top: 12px;">
                                    <button 
                                        type="button" 
                                        class="am-overlay-rename-btn button" 
                                        data-overlay-id="<?php echo esc_attr( $overlay['id'] ); ?>"
                                        style="flex: 1;"
                                    >
                                        <?php esc_html_e( 'Save Name', 'abdulify-me' ); ?>
                                    </button>
                                    <button
                                        type="button"
                                        class="am-overlay-delete-btn button button-secondary"
                                        data-overlay-id="<?php echo esc_attr( $overlay['id'] ); ?>"
                                        data-overlay-label="<?php echo esc_attr( $overlay['label'] ); ?>"
                                    >
                                        <?php esc_html_e( 'Delete', 'abdulify-me' ); ?>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <p style="color: #666; font-style: italic;">
                        <?php esc_html_e( 'No overlays found. Upload your first overlay above.', 'abdulify-me' ); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function get_overlay_dir_path() {
        return trailingslashit( plugin_dir_path( __FILE__ ) ) . self::OVERLAY_DIR . '/';
    }

    private function get_overlay_dir_url() {
        return trailingslashit( plugin_dir_url( __FILE__ ) ) . self::OVERLAY_DIR . '/';
    }

    private function get_upload_overlay_dir_path() {
        $upload_dir = wp_upload_dir();
        return trailingslashit( $upload_dir['basedir'] ) . 'abdulify-me/overlays/';
    }

    private function get_upload_overlay_dir_url() {
        $upload_dir = wp_upload_dir();
        return trailingslashit( $upload_dir['baseurl'] ) . 'abdulify-me/overlays/';
    }

    private function normalize_overlay_id( $name ) {
        $normalized = strtolower( (string) $name );
        $normalized = preg_replace( '/[^a-z0-9._-]+/', '-', $normalized );
        $normalized = trim( (string) $normalized, '-._' );

        return $normalized ?: strtolower( self::OVERLAY_PREFIX );
    }

    private function format_overlay_label( $value ) {
        $label = str_replace( array( '-', '_' ), ' ', (string) $value );
        $label = preg_replace( '/\s+/', ' ', (string) $label );
        $label = trim( (string) $label );

        if ( '' === $label ) {
            return self::OVERLAY_PREFIX;
        }

        if ( function_exists( 'mb_convert_case' ) ) {
            return mb_convert_case( $label, MB_CASE_TITLE, 'UTF-8' );
        }

        return ucwords( strtolower( $label ) );
    }

    private function maybe_seed_bundled_overlays() {
        $src_dir  = $this->get_overlay_dir_path();
        $dest_dir = $this->get_upload_overlay_dir_path();

        if ( ! is_dir( $src_dir ) ) {
            return;
        }

        if ( ! is_dir( $dest_dir ) ) {
            wp_mkdir_p( $dest_dir );
        }

        $allowed_extensions = array( 'png', 'jpg', 'jpeg', 'webp', 'svg', 'gif' );
        $files = scandir( $src_dir );
        if ( false === $files ) {
            return;
        }

        $deleted_ids = $this->get_deleted_bundled_overlays();

        foreach ( $files as $file_name ) {
            if ( ! is_string( $file_name ) || '.' === $file_name || '..' === $file_name ) {
                continue;
            }
            if ( 0 !== stripos( $file_name, self::OVERLAY_PREFIX ) ) {
                continue;
            }
            $ext = strtolower( (string) pathinfo( $file_name, PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, $allowed_extensions, true ) ) {
                continue;
            }

            $base_name  = (string) pathinfo( $file_name, PATHINFO_FILENAME );
            $overlay_id = $this->normalize_overlay_id( $base_name );
            if ( in_array( $overlay_id, $deleted_ids, true ) ) {
                continue;
            }

            $dest_path = $dest_dir . $file_name;
            if ( ! file_exists( $dest_path ) ) {
                copy( $src_dir . $file_name, $dest_path );
            }
        }
    }

    private function scan_overlay_dir( $dir_path, $url_base, $deletable, $custom_names ) {
        $overlays           = array();
        $allowed_extensions = array( 'png', 'jpg', 'jpeg', 'webp', 'svg', 'gif' );

        if ( ! is_dir( $dir_path ) ) {
            return $overlays;
        }

        $files = scandir( $dir_path );
        if ( false === $files ) {
            return $overlays;
        }

        foreach ( $files as $file_name ) {
            if ( ! is_string( $file_name ) || '.' === $file_name || '..' === $file_name ) {
                continue;
            }

            if ( ! is_file( $dir_path . $file_name ) ) {
                continue;
            }

            if ( 0 !== stripos( $file_name, self::OVERLAY_PREFIX ) ) {
                continue;
            }

            $extension = strtolower( (string) pathinfo( $file_name, PATHINFO_EXTENSION ) );
            if ( ! in_array( $extension, $allowed_extensions, true ) ) {
                continue;
            }

            $base_name  = (string) pathinfo( $file_name, PATHINFO_FILENAME );
            $overlay_id = $this->normalize_overlay_id( $base_name );
            $label      = isset( $custom_names[ $overlay_id ] ) ? $custom_names[ $overlay_id ] : $this->format_overlay_label( $base_name );

            $overlays[ $overlay_id ] = array(
                'id'        => $overlay_id,
                'label'     => $label,
                'url'       => $url_base . rawurlencode( $file_name ),
                'file'      => $file_name,
                'deletable' => $deletable,
            );
        }

        return $overlays;
    }

    private function get_available_overlays() {
        $this->maybe_seed_bundled_overlays();

        $custom_names = $this->get_all_overlay_custom_names();

        $overlays = $this->scan_overlay_dir(
            $this->get_upload_overlay_dir_path(),
            $this->get_upload_overlay_dir_url(),
            true,
            $custom_names
        );

        if ( empty( $overlays ) ) {
            return array();
        }

        uasort(
            $overlays,
            static function ( $a, $b ) {
                return strcasecmp( (string) $a['label'], (string) $b['label'] );
            }
        );

        return array_values( $overlays );
    }

    public function enqueue_admin_assets() {
        // Only load on abdulify-me admin page
        if ( ! isset( $_GET['page'] ) || 'abdulify-me-settings' !== $_GET['page'] ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        wp_enqueue_script(
            'abdulify-me-admin',
            plugin_dir_url( __FILE__ ) . 'js/abdulify-me-admin.js',
            array(),
            ABDULIFY_ME_VERSION,
            true
        );

        wp_localize_script(
            'abdulify-me-admin',
            'abdulifyMeAdmin',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'uploadNonce' => wp_create_nonce( 'am_overlay_upload' ),
                'renameNonce' => wp_create_nonce( 'am_overlay_rename' ),
                'deleteNonce' => wp_create_nonce( 'am_overlay_delete' ),
                'i18n' => array(
                    'uploadSuccess' => __( 'Overlay uploaded successfully!', 'abdulify-me' ),
                    'uploadError' => __( 'Error uploading overlay', 'abdulify-me' ),
                    'renameSuccess' => __( 'Overlay name updated!', 'abdulify-me' ),
                    'renameError' => __( 'Error updating overlay name', 'abdulify-me' ),
                    'deleteConfirm' => __( 'Are you sure you want to delete this overlay?', 'abdulify-me' ),
                    'deleteSuccess' => __( 'Overlay deleted successfully!', 'abdulify-me' ),
                    'deleteError' => __( 'Error deleting overlay', 'abdulify-me' ),
                    'invalidFile' => __( 'Invalid file type', 'abdulify-me' ),
                    'fileTooLarge' => __( 'File is too large (max 8 MB)', 'abdulify-me' ),
                ),
            )
        );
    }

    /**
     * Handle overlay upload via AJAX
     */
    public function handle_overlay_upload() {
        check_ajax_referer( 'am_overlay_upload', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'abdulify-me' ) ) );
        }

        if ( ! isset( $_FILES['file'] ) ) {
            wp_send_json_error( array( 'message' => __( 'No file provided', 'abdulify-me' ) ) );
        }

        $file = $_FILES['file'];
        $allowed_types = array( 'image/png', 'image/jpeg', 'image/webp', 'image/svg+xml', 'image/gif' );
        $max_size = 8 * 1024 * 1024; // 8 MB

        // Validate file
        $ftype = wp_check_filetype( $file['name'] );
        if ( ! in_array( $ftype['type'], $allowed_types, true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid file type. Allowed: PNG, JPG, WebP, SVG, GIF', 'abdulify-me' ) ) );
        }

        if ( $file['size'] > $max_size ) {
            wp_send_json_error( array( 'message' => __( 'File too large. Maximum 8 MB allowed', 'abdulify-me' ) ) );
        }

        // Read file contents
        $file_contents = file_get_contents( $file['tmp_name'] );
        if ( false === $file_contents ) {
            wp_send_json_error( array( 'message' => __( 'Error reading file', 'abdulify-me' ) ) );
        }

        // Sanitize filename and add prefix
        $original_name = sanitize_file_name( basename( $file['name'] ) );
        $extension = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
        $base_name = pathinfo( $original_name, PATHINFO_FILENAME );
        $base_name = preg_replace( '/[^a-z0-9._-]/i', '-', $base_name );
        $base_name = trim( $base_name, '-' );

        if ( empty( $base_name ) ) {
            $base_name = 'overlay';
        }

        // Create filename with prefix
        $new_filename = self::OVERLAY_PREFIX . '_' . $base_name . '.' . $extension;

        // Get upload directory (wp-content/uploads/abdulify-me/overlays/)
        $overlay_dir = $this->get_upload_overlay_dir_path();

        // Create directory if it doesn't exist
        if ( ! is_dir( $overlay_dir ) ) {
            if ( ! wp_mkdir_p( $overlay_dir ) ) {
                wp_send_json_error( array( 'message' => __( 'Error creating overlays directory', 'abdulify-me' ) ) );
            }
        }

        // Check if file already exists
        $target_path = $overlay_dir . $new_filename;
        if ( file_exists( $target_path ) ) {
            // Add counter to make filename unique
            $counter = 1;
            while ( file_exists( $overlay_dir . self::OVERLAY_PREFIX . '_' . $base_name . '-' . $counter . '.' . $extension ) ) {
                $counter++;
            }
            $new_filename = self::OVERLAY_PREFIX . '_' . $base_name . '-' . $counter . '.' . $extension;
            $target_path = $overlay_dir . $new_filename;
        }

        // Write file
        if ( ! file_put_contents( $target_path, $file_contents ) ) {
            wp_send_json_error( array( 'message' => __( 'Error saving file', 'abdulify-me' ) ) );
        }

        // Allow this overlay ID to be re-seeded/used again if it was previously deleted
        $uploaded_base_name = (string) pathinfo( $new_filename, PATHINFO_FILENAME );
        $this->remove_deleted_bundled_overlay( $this->normalize_overlay_id( $uploaded_base_name ) );

        // File uploaded successfully
        wp_send_json_success( array(
            'message' => __( 'Overlay uploaded successfully!', 'abdulify-me' ),
        ) );
    }

    /**
     * Handle overlay rename via AJAX
     */
    public function handle_overlay_rename() {
        check_ajax_referer( 'am_overlay_rename', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'abdulify-me' ) ) );
        }

        if ( ! isset( $_POST['overlay_id'] ) || ! isset( $_POST['name'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing required data', 'abdulify-me' ) ) );
        }

        $overlay_id = sanitize_key( $_POST['overlay_id'] );
        $new_name = sanitize_text_field( $_POST['name'] );

        if ( empty( $overlay_id ) || empty( $new_name ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid overlay ID or name', 'abdulify-me' ) ) );
        }

        // Verify overlay exists
        $overlays = $this->get_available_overlays();
        $overlay_exists = false;
        foreach ( $overlays as $overlay ) {
            if ( $overlay['id'] === $overlay_id ) {
                $overlay_exists = true;
                break;
            }
        }

        if ( ! $overlay_exists ) {
            wp_send_json_error( array( 'message' => __( 'Overlay not found', 'abdulify-me' ) ) );
        }

        // Update custom name
        $this->update_overlay_custom_name( $overlay_id, $new_name );

        wp_send_json_success( array(
            'message' => __( 'Overlay name updated!', 'abdulify-me' ),
        ) );
    }

    /**
     * Handle overlay delete via AJAX
     */
    public function handle_overlay_delete() {
        check_ajax_referer( 'am_overlay_delete', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'abdulify-me' ) ) );
        }

        if ( ! isset( $_POST['overlay_id'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing overlay ID', 'abdulify-me' ) ) );
        }

        $overlay_id = sanitize_key( $_POST['overlay_id'] );

        if ( empty( $overlay_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid overlay ID', 'abdulify-me' ) ) );
        }

        // Find overlay file
        $overlays        = $this->get_available_overlays();
        $overlay_to_delete = null;

        foreach ( $overlays as $overlay ) {
            if ( $overlay['id'] === $overlay_id ) {
                $overlay_to_delete = $overlay;
                break;
            }
        }

        if ( null === $overlay_to_delete ) {
            wp_send_json_error( array( 'message' => __( 'Overlay not found', 'abdulify-me' ) ) );
        }

        if ( ! $overlay_to_delete['deletable'] ) {
            wp_send_json_error( array( 'message' => __( 'Bundled frames cannot be deleted.', 'abdulify-me' ) ) );
        }

        $file_path = $this->get_upload_overlay_dir_path() . $overlay_to_delete['file'];

        // Delete file
        if ( file_exists( $file_path ) ) {
            if ( ! is_writable( $file_path ) ) {
                wp_send_json_error( array( 'message' => __( 'Error deleting file. Check server file permissions.', 'abdulify-me' ) ) );
            }
            if ( ! unlink( $file_path ) ) {
                wp_send_json_error( array( 'message' => __( 'Error deleting file. Check server file permissions.', 'abdulify-me' ) ) );
            }
        }

        // Delete custom name if exists
        $this->delete_overlay_custom_name( $overlay_id );

        // Prevent this overlay from being re-seeded if it's a bundled default
        $this->add_deleted_bundled_overlay( $overlay_id );

        wp_send_json_success( array(
            'message' => __( 'Overlay deleted successfully!', 'abdulify-me' ),
        ) );
    }

    public function enqueue_assets() {
        if ( is_admin() || ! is_singular() ) {
            return;
        }

        global $post;
        if ( ! ( $post instanceof WP_Post ) || ! has_shortcode( $post->post_content, self::SHORTCODE ) ) {
            return;
        }

        $asset_suffix = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? '' : '.min';

        wp_enqueue_style(
            'abdulify-me',
            plugin_dir_url( __FILE__ ) . 'css/abdulify-me' . $asset_suffix . '.css',
            array(),
            ABDULIFY_ME_VERSION
        );

        wp_enqueue_script(
            'abdulify-me',
            plugin_dir_url( __FILE__ ) . 'js/abdulify-me' . $asset_suffix . '.js',
            array(),
            ABDULIFY_ME_VERSION,
            true
        );

        $tint_color = apply_filters( 'abdulify_me_tint_color', '#175f8c' );
        $colors     = array(
            'bg'              => apply_filters( 'abdulify_me_color_bg', '#f6f1e5' ),
            'surface'         => apply_filters( 'abdulify_me_color_surface', '#ffffff' ),
            'primary'         => apply_filters( 'abdulify_me_color_primary', '#0f4f78' ),
            'primaryStrong'   => apply_filters( 'abdulify_me_color_primary_strong', '#0b3957' ),
            'accent'          => apply_filters( 'abdulify_me_color_accent', '#f0a33b' ),
            'ink'             => apply_filters( 'abdulify_me_color_ink', '#1f2530' ),
            'muted'           => apply_filters( 'abdulify_me_color_muted', '#5f6877' ),
            'border'          => apply_filters( 'abdulify_me_color_border', '#dbe3ec' ),
            'statusInfo'      => apply_filters( 'abdulify_me_color_status_info', '#5f6877' ),
            'statusError'     => apply_filters( 'abdulify_me_color_status_error', '#b3212f' ),
            'placeholderBg'   => apply_filters( 'abdulify_me_color_placeholder_bg', '#f2f6fb' ),
            'placeholderText' => apply_filters( 'abdulify_me_color_placeholder_text', '#335f88' ),
            'ribbon'          => apply_filters( 'abdulify_me_color_ribbon', '' ),
            'ribbonText'      => apply_filters( 'abdulify_me_color_ribbon_text', '#ffffff' ),
            'badgeStroke'     => apply_filters( 'abdulify_me_color_badge_stroke', '#0b3957' ),
            'badgeText'       => apply_filters( 'abdulify_me_color_badge_text', '#0b3957' ),
            'tint'            => apply_filters( 'abdulify_me_color_tint', $tint_color ),
        );

        wp_localize_script(
            'abdulify-me',
            'abdulifyMeConfig',
            array(
                'tintColor'   => $tint_color,
                'colors'      => $colors,
                'maxBytes'    => 8 * 1024 * 1024,
                'overlays'    => $this->get_available_overlays(),
                'nonce'       => wp_create_nonce( 'abdulify_me_client' ),
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            )
        );
    }

    public function render_shortcode( $atts = array() ) {
        $atts = shortcode_atts(
            array(
                'title'    => __( 'Abdulify Me', 'abdulify-me' ),
                'subtitle' => __( 'Upload a photo, apply an AFS-Social border, and download your image.', 'abdulify-me' ),
            ),
            $atts,
            self::SHORTCODE
        );

        $title    = esc_html( $atts['title'] );
        $subtitle = esc_html( $atts['subtitle'] );

        ob_start();
        ?>
        <section class="am-widget" data-am-widget>
            <header class="am-header">
                <h2 class="am-title"><?php echo $title; ?></h2>
                <p class="am-subtitle"><?php echo $subtitle; ?></p>
            </header>

            <div class="am-grid">
                <div class="am-panel">
                    <label class="am-upload">
                        <span class="am-upload-title"><?php esc_html_e( 'Upload Photo', 'abdulify-me' ); ?></span>
                        <span class="am-upload-help"><?php esc_html_e( 'PNG, JPG, WebP, SVG, or GIF up to 8 MB', 'abdulify-me' ); ?></span>
                        <input class="am-photo-input" type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml,image/gif">
                    </label>

                    <fieldset class="am-controls" aria-label="<?php esc_attr_e( 'Photo border', 'abdulify-me' ); ?>">
                        <legend><?php esc_html_e( 'Border', 'abdulify-me' ); ?></legend>

                        <label class="am-control-row">
                            <span><?php esc_html_e( 'AFS-Social border', 'abdulify-me' ); ?></span>
                        </label>
                        <select class="am-select" data-am-overlay-select>
                            <option value=""><?php esc_html_e( 'Select a border', 'abdulify-me' ); ?></option>
                        </select>

                        <div class="am-zoom-controls">
                            <label class="am-zoom-label" for="am-zoom-slider"><?php esc_html_e( 'Zoom', 'abdulify-me' ); ?> <span class="am-zoom-value" data-am-zoom-value>100%</span></label>
                            <div class="am-zoom-row">
                                <input class="am-zoom-slider" id="am-zoom-slider" type="range" min="1" max="5" step="0.05" value="1" data-am-zoom-slider disabled>
                                <button class="am-button am-zoom-reset" type="button" data-am-zoom-reset disabled title="<?php esc_attr_e( 'Reset zoom and position', 'abdulify-me' ); ?>">
                                    <?php esc_html_e( 'Reset', 'abdulify-me' ); ?>
                                </button>
                            </div>
                            <p class="am-zoom-hint"><?php esc_html_e( 'Scroll to zoom, drag to reposition', 'abdulify-me' ); ?></p>
                        </div>
                    </fieldset>

                    <div class="am-actions">
                        <button class="am-button am-download" type="button" data-am-download disabled>
                            <?php esc_html_e( 'Download Image', 'abdulify-me' ); ?>
                        </button>
                    </div>

                    <p class="am-status" data-am-status><?php esc_html_e( 'Choose a photo to begin.', 'abdulify-me' ); ?></p>
                </div>

                <div class="am-preview-panel">
                    <canvas class="am-canvas" data-am-canvas width="1200" height="1200" aria-label="<?php esc_attr_e( 'Image preview', 'abdulify-me' ); ?>"></canvas>
                </div>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }
}

new Abdulify_Me_Plugin();
