<?php

namespace Dazamate\SurrealGraphSync\Manager;

use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Utils\UserErrorManager;
use Dazamate\SurrealGraphSync\Utils\Inputs;
use Dazamate\SurrealGraphSync\Enum\MetaKeys;

if ( ! defined( 'ABSPATH' ) ) exit;

class UserSyncManager {
    public static function load_hooks() {
        // Runs after a user is created (i.e., registration) in WP Admin or front end
        add_action( 'user_register', [ __CLASS__, 'on_user_create' ], 10, 2 );

        // Runs when a user is updated (e.g., profile_update). 
        // Hook signature: do_action( 'profile_update', $user_id, $old_user_data );
        add_action( 'profile_update', [ __CLASS__, 'on_user_save' ], 10, 2 );

        // Runs just before a user is deleted. 
        add_action( 'delete_user', [ __CLASS__, 'on_user_delete' ], 10, 1 );

        add_action( 'show_user_profile', [ __CLASS__, 'render_surreal_id_user_info' ], 10);
        add_action( 'edit_user_profile', [ __CLASS__, 'render_surreal_id_user_info' ], 10);
    }

    public static function on_user_create( int $user_id, array $userdata ) {
        $user = get_userdata($user_id);
        self::on_user_save($user_id, $user);
    }

    public static function render_surreal_id_user_info( \WP_User $user ) {
        $surreal_id = get_user_meta( $user->ID, MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value, true );
        ?>
        <h3>Surreal ID Information</h3>
        <table class="form-table">
            <tr>
                <th><label>Surreal ID</label></th>
                <td>
                    <span><b><?php echo ! empty( $surreal_id ) ? esc_html( $surreal_id ) : 'No Surreal ID found'; ?></b></span>
                </td>
            </tr>
        </table>
        <?php
    }

    public static function on_user_save( int $user_id, ?\WP_User $old_user_data ) {
        // Avoid hooking into auto-saves or AJAX if needed
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }

        $user = get_userdata( $user_id );
        
        if ( ! ( $user instanceof \WP_User ) ) {
            UserErrorManager::add( $user_id, [sprintf( "Surreal DB user mapping error: Unable to fetch user id %d", $user_id )] );
            return;
        }

        $user_role_map = [];
        $user_role_map = apply_filters('surreal_graph_user_role_map', $user_role_map);

        foreach ($user_role_map as $surreal_user_type => $wordpress_user_types) {
            // If the user has the role that is mapped to this surreal type, then process with mapping the user as this type
            if ( count( array_intersect( $user->roles, $wordpress_user_types ) ) > 0 ) {
                $mapped_user_data = apply_filters('surreal_graph_map_user_' . $surreal_user_type, new MappedData(), $user );

                $related_data_mappings = apply_filters('surreal_graph_map_user_related', [], $surreal_user_type, $user);

                // parse the records
                do_action('surreal_sync_user', $user, $surreal_user_type, $mapped_user_data, $related_data_mappings);
            }
        }
    }

    public static function on_user_delete( int $user_id ) {
        $surreal_record_id = get_user_meta($user_id, MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value, true);

        // Make sure we have data
        if ( $surreal_record_id === false ) return;

        do_action('surreal_delete_record', $surreal_record_id);
    }
}
