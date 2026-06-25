<?php

namespace Dazamate\SurrealGraphSync\Mapper\Term;

use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Data\RelationData;
use Dazamate\SurrealGraphSync\Field\StringField;
use Dazamate\SurrealGraphSync\Field\NumberField;
use Dazamate\SurrealGraphSync\Enum\MetaKeys;

if ( ! defined( 'ABSPATH' ) ) exit;

class TermMapper {
    public static function register() {
        // Append the parent->child hierarchy edge to whatever relations the
        // user mapped for this term.
        add_filter('surreal_graph_map_term_related', [__CLASS__, 'map_hierarchy'], 10, 2);
    }

    public static function map(MappedData $mapped_data, int $term_id): MappedData {
        $term = get_term($term_id);

        if ( ! ( $term instanceof \WP_Term ) ) {
            return $mapped_data;
        }

        return $mapped_data
            ->set('name', new StringField($term->name))
            ->set('slug', new StringField($term->slug))
            ->set('term_id', new NumberField($term_id))
            ->set('taxonomy', new StringField($term->taxonomy))
            ->set('description', new StringField($term->description))
            ->set('count', new NumberField($term->count));
    }

    // Adds a `term -> child_of -> parent_term` edge. Best-effort: only added when the
    // parent has already been synced; a child synced first gets the edge on re-save.
    public static function map_hierarchy(array $relations, \WP_Term $term): array {
        if ($term->parent < 1) {
            return $relations;
        }

        $parent_surreal_id = get_term_meta($term->parent, MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value, true);

        if (empty($parent_surreal_id)) {
            return $relations;
        }

        $relation_name = apply_filters('surreal_graph_term_child_relation_name', 'child_of', $term->taxonomy);

        $relations[] = new RelationData(
            from_record:    $term->term_id,        // self -> resolved to the in-transaction $rec
            to_record:      $parent_surreal_id,    // already a Surreal record id
            relation_table: $relation_name,
            data:           null,
            unique:         true,
        );

        return $relations;
    }
}
