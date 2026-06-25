<?php

namespace Dazamate\SurrealGraphSync\Mapper\Entity;

use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Field\StringField;
use Dazamate\SurrealGraphSync\Field\NumberField;
use Dazamate\SurrealGraphSync\Field\DateTimeField;
use Dazamate\SurrealGraphSync\Field\RecordField;
use Dazamate\SurrealGraphSync\Dto\Reference\WordPressId;
use Dazamate\SurrealGraphSync\Enum\QueryType;

if ( ! defined( 'ABSPATH' ) ) exit;

class PostMapper {
    public static function register() {
        add_filter('surreal_map_post_table_name', [__CLASS__, 'map_table_name'], 10, 3);
    }

    public static function map_table_name(string $node_name, string $post_type, int $post_id): string {
        if ($post_type === 'post') return 'article';
        return $node_name;
    }

    public static function map(MappedData $mapped_data, int $post_id): MappedData {
        $post = get_post($post_id);

        $thumbnail_image_id = get_post_thumbnail_id($post_id);

        return $mapped_data
            ->set('title', new StringField($post->post_title))
            ->set('content', new StringField($post->post_content))
            ->set('post_id', new NumberField($post_id))
            ->set('created', new DateTimeField(date('c', strtotime($post->post_date_gmt))))
            ->set('published', new DateTimeField(date('c', strtotime($post->post_date_gmt))))
            ->set('update', new DateTimeField(date('c', strtotime($post->post_modified_gmt ?: $post->post_date_gmt))))
            ->set_if(
                !empty($thumbnail_image_id),
                'thumbnail_image',
                fn() => new RecordField(new WordPressId($thumbnail_image_id, QueryType::POST))
            );
    }
}
