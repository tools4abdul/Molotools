<?php
/**
 * Plugin Name: Site Translator
 * Plugin URI:  https://github.com/hexa-decim8/Molotools
 * Description: Adds floating translate buttons to translate the entire site into Spanish, Arabic, or Banga using Google Translate. Includes RTL support for Arabic.
 * Version:     0.1.1
 * Author:      Molotools
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: site-translator
 * Requires:    5.0
 * Requires PHP: 8.3
 * Tested up to: 6.6
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SITE_TRANSLATOR_VERSION', '0.1.1' );

class Site_Translator_Plugin {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_footer',          array( $this, 'render_translate_widget' ) );
    }

    /**
     * Enqueue CSS and JS on all front-end pages.
     */
    public function enqueue_assets() {
        $suffix = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? '' : '.min';

        wp_enqueue_style(
            'site-translator',
            plugin_dir_url( __FILE__ ) . 'css/site-translator' . $suffix . '.css',
            array(),
            SITE_TRANSLATOR_VERSION
        );

        wp_enqueue_script(
            'site-translator',
            plugin_dir_url( __FILE__ ) . 'js/site-translator' . $suffix . '.js',
            array(),
            SITE_TRANSLATOR_VERSION,
            true
        );
    }

    /**
     * Output the hidden Google Translate Element container.
     */
    public function render_translate_widget() {
        ?>
        <div id="site-translator-google" style="display:none;"></div>
        <?php
    }
}

new Site_Translator_Plugin();
