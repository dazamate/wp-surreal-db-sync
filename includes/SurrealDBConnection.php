<?php 

namespace Dazamate\SurrealGraphSync;

use Dazamate\SurrealGraphSync\Settings\AdminSettings;
use Surreal\Surreal;

class SurrealDBConnection {
    static private bool $_connetion = false;
    static private Surreal $_db;

    static public function has_db_conn(): bool {
        return self::$_connetion;
    }

    static public function load_hooks() {
        add_filter('get_surreal_db_conn', [__CLASS__, 'get_surreal_db_conn']);
        add_action('admin_init', [__CLASS__, 'check_db_conn']);
        add_action('admin_bar_menu', [__CLASS__, 'add_admin_bar_status'], 100);
        add_action('admin_head', [__CLASS__, 'admin_bar_status_styles']);
        add_action('wp_head', [__CLASS__, 'admin_bar_status_styles']);
    }

    static public function add_admin_bar_status(\WP_Admin_Bar $admin_bar) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $connected = self::$_connetion;
        $status_class = $connected ? 'surreal-db-connected' : 'surreal-db-disconnected';
        $label = $connected ? 'SurrealDB: Connected' : 'SurrealDB: Disconnected';

        $admin_bar->add_node([
            'id'    => 'surreal-db-status',
            'title' => sprintf(
                '<span class="surreal-db-status-dot %s"></span><span class="ab-label">%s</span>',
                esc_attr( $status_class ),
                esc_html( $label )
            ),
            'href'  => esc_url( admin_url( 'admin.php?page=' . AdminSettings::MENU_SLUG ) ),
            'meta'  => [
                'title' => $label,
            ],
        ]);
    }

    static public function admin_bar_status_styles() {
        if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <style>
            #wpadminbar #wp-admin-bar-surreal-db-status .surreal-db-status-dot {
                display: inline-block;
                width: 8px;
                height: 8px;
                border-radius: 50%;
                margin-right: 6px;
                vertical-align: middle;
            }
            #wpadminbar #wp-admin-bar-surreal-db-status .surreal-db-connected {
                background-color: #46b450;
                box-shadow: 0 0 4px #46b450;
            }
            #wpadminbar #wp-admin-bar-surreal-db-status .surreal-db-disconnected {
                background-color: #dc3232;
                box-shadow: 0 0 4px #dc3232;
            }
        </style>
        <?php
    }

    static public function check_db_conn() {
        if ( ! self::$_connetion ) self::display_no_db_error();
    }

    static public function display_no_db_error() {
        add_action('admin_notices', function() {
            echo sprintf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                'Couldn\'t connect to Surreal DB'
            );
        });
    }

    static public function init_db_connection(): bool {
        $settings = AdminSettings::get_settings();
        $db = new Surreal();
    
        try {
            $db->connect($settings['db_protocol'] . '://' . $settings['db_address'] . ':' . $settings['db_port'], [
                "namespace"     => $settings['db_namespace'],
                "database"      => $settings['db_name']
            ]);

            $token = $db->signin([
                "user" => $settings['db_username'],
                "pass" => $settings['db_password']
            ]);

            self::$_db = $db;
            self::$_connetion = true;
        } catch(\Throwable $e) {
            error_log($e->getMessage());
            return false;
        }

        return true;
    }

    static public function get_surreal_db_conn(): ?\Surreal\Surreal {
        return self::$_db;
    }
}