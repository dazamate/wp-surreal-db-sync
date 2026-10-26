<?php

namespace Dazamate\SurrealGraphSync\Manager;

use Dazamate\SurrealGraphSync\Mapper\Entity\PostMapper;
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Utils\ErrorManager;
use Dazamate\SurrealGraphSync\PostType\ImagePostType;
use Dazamate\SurrealGraphSync\Enum\MetaKeys;
use Dazamate\SurrealGraphSync\Utils\SurrealUtils;

class PostSyncManager {
    public static function load_hooks() {
        add_action('save_post', [__CLASS__, 'on_post_save'], 100, 3);
        add_action('before_delete_post', [__CLASS__, 'on_post_delete'], 10, 1);
        add_action('trashed_post', [__CLASS__, 'on_post_trash'], 10, 1);
        // No untrash hook: untrash_post fires while the post is still `trash`. Restoring then saves
        // the post with its new status, and on_post_save handles that.

        add_action('post_submitbox_misc_actions', [__CLASS__, 'render_surreal_id_info']);
    }

    public static function render_surreal_id_info() {
        $surreal_id = (string) get_post_meta( get_the_ID(), MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value, true );

        echo '<div class="misc-pub-section misc-pub-db-id-info">';
        echo 'Surreal ID:<br>';

        if ( $surreal_id !== '' ) {
            printf(
                '<a href="#" class="surreal-id-link" data-surreal-preview-open title="View Surreal payload">%s</a>',
                esc_html( $surreal_id )
            );
        } else {
            echo '<span><b>— not synced —</b></span>';
        }

        echo '</div>';
    }
   
    // Runs on save_post (after CMB2 has saved its meta). SurrealDB records carry no status, so
    // anything synced is live: a post that isn't in a syncable status has its record removed.
    // Quick Edit saves over AJAX, so AJAX saves are handled too; autosaves and revisions aren't.
    public static function on_post_save(int $post_id, \WP_Post $post, bool $update) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        if ( ! self::is_syncable_status( $post ) ) {
            SurrealUtils::delete_surreal_record_by_post_id( $post_id );
            return;
        }

        self::sync_post( $post_id, $post );
    }

    /**
     * Statuses whose posts belong in SurrealDB. Default: publish only. Scheduled posts sync when
     * WordPress publishes them (wp_publish_post fires save_post).
     */
    public static function is_syncable_status( \WP_Post $post ): bool {
        $statuses = apply_filters( 'surreal_sync_post_statuses', [ 'publish' ], $post );

        return is_array( $statuses ) && in_array( $post->post_status, $statuses, true );
    }

    /**
     * Map and fire surreal_sync_post for a single post (same path as save_post).
     * Returns false when the post isn't in a syncable status, the post type is not opted in,
     * or mapping produced nothing useful.
     */
    public static function sync_post( int $post_id, ?\WP_Post $post = null ): bool {
        $post ??= get_post( $post_id );

        if ( ! ( $post instanceof \WP_Post ) ) {
            return false;
        }

        if ( ! self::is_syncable_status( $post ) ) {
            return false;
        }

        // Map the post type to a surreal table name (entity). An empty result
        // means this post type isn't opted in (no `surreal_map_post_table_name`
        // mapping registered for it), so there's nothing to sync.
        $surreal_table_name = apply_filters( 'surreal_map_post_table_name', '', $post->post_type, $post_id );

        if ( empty( $surreal_table_name ) ) {
            return false;
        }

        // Always map the generic post data; downstream filters can add/remove fields.
        $mapped_entity_data = PostMapper::map( new MappedData(), $post_id );
        $mapped_entity_data = apply_filters( 'surreal_graph_map_' . $post->post_type, $mapped_entity_data, $post_id );

        do_action( 'surreal_sync_post', $post, $surreal_table_name, $mapped_entity_data );

        return true;
    }

    public static function on_post_delete(int $post_id) {
        SurrealUtils::delete_surreal_record_by_post_id($post_id);   
    }

    public static function on_post_trash(int $post_id) {
        SurrealUtils::delete_surreal_record_by_post_id($post_id);
    }
}
