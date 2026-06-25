<?php

namespace Dazamate\SurrealGraphSync\Manager;

use Dazamate\SurrealGraphSync\Data\RelationData;
use Dazamate\SurrealGraphSync\Enum\MetaKeys;

// Opt-in convenience: auto-appends `post -> has_{taxonomy} -> term` edges to a post's
// relations, derived from WordPress' own term relationships. Enabled per taxonomy via
// the `surreal_graph_auto_term_relations` filter.
class TermAssociationManager {
    public static function register() {
        add_filter('surreal_graph_map_related', [__CLASS__, 'append_term_relations'], 10, 2);
    }

    public static function append_term_relations(array $relations, \WP_Post $post): array {
        $auto_taxonomies = apply_filters('surreal_graph_auto_term_relations', []);

        if (empty($auto_taxonomies) || !is_array($auto_taxonomies)) {
            return $relations;
        }

        $post_taxonomies = get_object_taxonomies($post->post_type);

        foreach ($auto_taxonomies as $taxonomy) {
            if (!in_array($taxonomy, $post_taxonomies, true)) continue;

            $terms = wp_get_object_terms($post->ID, $taxonomy);

            if (is_wp_error($terms) || empty($terms)) continue;

            $relation_name = apply_filters('surreal_graph_term_relation_name', 'has_' . $taxonomy, $taxonomy, $post->post_type);

            foreach ($terms as $term) {
                $term_surreal_id = get_term_meta($term->term_id, MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value, true);

                // Best-effort: only wire edges to terms that already exist in Surreal.
                if (empty($term_surreal_id)) continue;

                $relations[] = new RelationData(
                    from_record:    $post->ID,          // self -> resolved to the in-transaction $rec
                    to_record:      $term_surreal_id,   // already a Surreal record id
                    relation_table: $relation_name,
                    data:           null,
                    unique:         true,
                );
            }
        }

        return $relations;
    }
}
