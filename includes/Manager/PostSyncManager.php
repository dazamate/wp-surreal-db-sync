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
        add_action('untrash_post', [__CLASS__, 'on_post_untrash'], 10, 1);

        add_action('post_submitbox_misc_actions', [__CLASS__, 'render_surreal_id_info']);
    }

    public static function render_surreal_id_info() {
        echo '<div class="misc-pub-section misc-pub-db-id-info">';
            echo 'Surreal ID: <br>';
            printf('<span><b>%s</b></span>', get_post_meta(get_the_id(), MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value, true));
        echo '</div>';
    }
   
    public static function on_post_save(int $post_id, \WP_Post $post, bool $update) {
        if ( 'draft' === $post->post_status || wp_is_post_revision( $post_id ) || defined( 'DOING_AJAX' ) ) return;
        
        // Ignore trying to sync drafts
        $ignore_post_states = [
            'draft',
            'auto-draft'
        ];

        if (in_array($post->post_status, $ignore_post_states)) return;

        // Map the post type to a surreal table name (entity). An empty result
        // means this post type isn't opted in (no `surreal_map_post_table_name`
        // mapping registered for it), so there's nothing to sync. Bail before
        // seeding/firing so unmapped post types from other plugins stay silent
        // rather than raising a "no mapped table name" admin notice.
        $surreal_table_name = apply_filters('surreal_map_post_table_name', '', $post->post_type, $post_id);

        if (empty($surreal_table_name)) return;

        // Allways map the generic post data, downstream filters can add/remove generic data
        $mapped_entity_data = PostMapper::map(new MappedData(), $post_id);
        $mapped_entity_data = apply_filters('surreal_graph_map_' . $post->post_type, $mapped_entity_data, $post_id);

        do_action('surreal_sync_post', $post, $surreal_table_name, $mapped_entity_data);
    }

    public static function on_post_delete(int $post_id) {
        SurrealUtils::delete_surreal_record_by_post_id($post_id);   
    }

    public static function on_post_trash(int $post_id) {
        SurrealUtils::delete_surreal_record_by_post_id($post_id);        
    }

    public static function on_post_untrash(int $post_id) {
        $post = get_post($post_id);

        if ( ! ( $post instanceof \WP_Post ) ) return;
        
        self::on_post_save($post_id, $post, true);
    }
}
