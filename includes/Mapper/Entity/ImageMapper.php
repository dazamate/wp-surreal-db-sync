<?php

namespace Dazamate\SurrealGraphSync\Mapper\Entity;

use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Field\StringField;
use Dazamate\SurrealGraphSync\Field\NumberField;
use Dazamate\SurrealGraphSync\Field\ArrayField;
use Dazamate\SurrealGraphSync\Field\ObjectField;

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

        // `wp_get_attachment_image_src` passes through any CDN/optimised URL 
        // so `src` matches what the front end renders
        // for the full-size image (falling back to the raw attachment url).
        $full = wp_get_attachment_image_src($post_id, 'full');

        $src    = is_array($full) ? (string) $full[0] : (wp_get_attachment_url($post_id) ?: '');
        $width  = is_array($full) ? (int) $full[1] : 0;
        $height = is_array($full) ? (int) $full[2] : 0;

        return $mapped_data
            ->set('title', new StringField($post->post_title))
            ->set('post_id', new NumberField($post_id))
            ->set('mime', new StringField($post->post_mime_type))
            ->set('src', new StringField($src))
            ->set('width', new NumberField($width))
            ->set('height', new NumberField($height))
            ->set('alt', new StringField(get_post_meta($post->ID, '_wp_attachment_image_alt', true) ?: ''))
            ->set('caption', new StringField($post->post_excerpt))
            ->set('description', new StringField($post->post_content))
            ->set('sizes', new ArrayField(self::map_sizes($post_id)));
    }

    private static function map_sizes(int $post_id): array {
        $names = get_intermediate_image_sizes();
        $names[] = 'full';

        $sizes = [];
        $seen = [];

        foreach ($names as $name) {
            $src = wp_get_attachment_image_src($post_id, $name);

            if (!is_array($src) || empty($src[0])) {
                continue;
            }

            $url = (string) $src[0];

            // WordPress returns the full image for any requested size larger than
            // the original, so dedupe by URL to avoid repeating the same rendition.
            if (isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;

            $sizes[] = new ObjectField(
                (new MappedData())
                    ->set('name', new StringField((string) $name))
                    ->set('url', new StringField($url))
                    ->set('width', new NumberField((int) $src[1]))
                    ->set('height', new NumberField((int) $src[2]))
                    ->set('mime', new StringField(self::mime_from_url($url)))
            );
        }

        return $sizes;
    }

    private static function mime_from_url(string $url): string {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'webp'        => 'image/webp',
            'avif'        => 'image/avif',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            default       => '',
        };
    }
}