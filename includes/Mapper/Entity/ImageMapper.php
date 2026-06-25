<?php

namespace Dazamate\SurrealGraphSync\Mapper\Entity;

use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Field\StringField;
use Dazamate\SurrealGraphSync\Field\NumberField;

if ( ! defined( 'ABSPATH' ) ) exit;

class ImageMapper {
    public static function register() {
        add_filter('surreal_graph_map_image', [__CLASS__, 'map'], 10, 2);
        add_filter('surreal_map_post_table_name', [__CLASS__, 'map_table_name'], 10, 3);
    }

    public static function map_table_name(string $node_name, string $post_type, int $post_id): string {
        if ($post_type !== 'attachment') return $node_name;

        $post = get_post($post_id);

        if (strpos($post->post_mime_type, 'image/') === 0) return 'image';

        return $node_name;
    }

    public static function map(MappedData $mapped_data, int $post_id): MappedData {
        $post = get_post($post_id);

        return $mapped_data
            ->set('title', new StringField($post->post_title))
            ->set('post_id', new NumberField($post_id))
            ->set('mime', new StringField($post->post_mime_type))
            ->set('src', new StringField(wp_get_attachment_url($post_id) ?: ''))
            ->set('alt', new StringField(get_post_meta($post->ID, '_wp_attachment_image_alt', true) ?: ''))
            ->set('caption', new StringField($post->post_excerpt))
            ->set('description', new StringField($post->post_content));
    }
}