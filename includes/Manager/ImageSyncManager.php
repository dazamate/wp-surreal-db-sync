<?php

namespace Dazamate\SurrealGraphSync\Manager;

use Dazamate\SurrealGraphSync\Mapper\Entity\PostMapper;
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Utils\ErrorManager;
use Dazamate\SurrealGraphSync\PostType\ImagePostType;
use Dazamate\SurrealGraphSync\Enum\MetaKeys;
use Dazamate\SurrealGraphSync\Utils\SurrealUtils;

class ImageSyncManager {
    public static function load_hooks() {
        add_filter('wp_generate_attachment_metadata', [__CLASS__, 'on_metadata_generated'], 20, 2);
        
        // Subsequent edits (alt text, caption, title) still need to re-sync.
        add_action('edit_attachment', [__CLASS__, 'on_attatchemnt_change']);
        add_action('delete_attachment', [__CLASS__, 'on_attatchemnt_delete']);

        add_filter('admin_post_thumbnail_html', [__CLASS__, 'render_surreal_id_info_in_image_ui'], 10, 2);
        add_filter('attachment_fields_to_edit', [__CLASS__, 'render_surreal_id_attatchment_edit'], 10, 2);

        add_filter('surreal_map_post_table_name', [__CLASS__, 'map_surreal_table_name'], 10, 3);
    }

    public static function render_surreal_id_info_in_image_ui($content, $post_id) {
        // Only output this markup when editing an attachment (the image details page).
        if ( function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
            if ( ! isset( $screen->post_type ) || 'attachment' !== $screen->post_type ) {
                return $content;
            }
        }

        $surreal_id = get_post_meta($post_id, MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value, true);

        $html = '<div class="surreal-image-info" style="margin-top: 10px;">';
        $html .= 'Surreal ID: <strong>' . esc_html($surreal_id) . '</strong>';
        $html .= '</div>';
    
        return $content . $html;
    }

    public static function render_surreal_id_attatchment_edit($form_fields, $post) {
        $surreal_id = get_post_meta($post->ID, MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value, true);

        $form_fields['surreal_db_id_field'] = [
            'label' => 'Surreal ID',
            'input' => 'html',
            'html'  => sprintf(
                '<input type="text" readonly style="width:100%%;" value="%s" />',
                esc_attr($surreal_id ?? '')
            )
        ];
    
        return $form_fields;
    }

    public static function on_metadata_generated(array $metadata, int $post_id): array {
        self::on_attatchemnt_change($post_id);

        return $metadata;
    }

    public static function on_attatchemnt_change(int $post_id) {
        $post = get_post($post_id);
        $surreal_table_name = apply_filters('surreal_map_post_table_name', 'image', $post->post_type, $post_id);

        if (strpos($post->post_mime_type, 'image/') === 0) {
            $mapped_data = apply_filters('surreal_graph_map_image', new MappedData(), $post_id);
            do_action('surreal_sync_post', $post, $surreal_table_name, $mapped_data);
        }
    }

    public static function map_surreal_table_name(string $surreal_table_name, string $post_type, int $post_id) {
        if ($post_type === ImagePostType::POST_TYPE)  {
            if (strpos($post_type, 'image/') === 0) {
                return 'image';
            }
        }
        return $surreal_table_name;
    }

    public static function on_attatchemnt_delete(int $post_id) {
        $post = get_post($post_id);

        if (strpos($post->post_mime_type, 'image/') === 0) {
            SurrealUtils::delete_surreal_record_by_post_id($post_id);
        }
    }
}
