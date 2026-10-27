<?php 

namespace Dazamate\SurrealGraphSync;

use Dazamate\SurrealGraphSync\Client\SurrealClientInterface;
use Dazamate\SurrealGraphSync\Client\SurrealHttpClient;
use Dazamate\SurrealGraphSync\Settings\AdminSettings;

class SurrealDBConnection {
    static private bool $_connetion = false;
    static private SurrealClientInterface $_db;

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

        // JSON/HTTP client — avoids the SDK's CBOR float16 crash on PHP 8+.
        $protocol = in_array( $settings['db_protocol'], [ 'http', 'https' ], true )
            ? $settings['db_protocol']
            : 'http';

        $base_url = sprintf(
            '%s://%s:%s',
            $protocol,
            $settings['db_address'],
            $settings['db_port']
        );

        try {
            $db = new SurrealHttpClient(
                $base_url,
                (string) $settings['db_namespace'],
                (string) $settings['db_name']
            );

            // System root must sign in without NS scope (NS would look up a
            // namespace-level user). Non-root (prod NS users) keep namespace.
            $signin = [
                'user' => $settings['db_username'],
                'pass' => $settings['db_password'],
            ];
            if ( strtolower( (string) $settings['db_username'] ) !== 'root' ) {
                $signin['namespace'] = $settings['db_namespace'];
            }
            $db->signin( $signin );
            $db->use( (string) $settings['db_namespace'], (string) $settings['db_name'] );

            // Cheap connectivity check.
            $db->query( 'INFO FOR DB;' );

            self::$_db        = $db;
            self::$_connetion = true;
        } catch ( \Throwable $e ) {
            error_log( $e->getMessage() );
            return false;
        }

        return true;
    }

    static public function get_surreal_db_conn(): ?SurrealClientInterface {
        return self::$_connetion ? self::$_db : null;
    }
}