<?php

namespace Dazamate\SurrealGraphSync\Manager;

use Dazamate\SurrealGraphSync\Mapper\Term\TermMapper;
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Utils\SurrealUtils;

class TermSyncManager {
    public static function load_hooks() {
        // saved_term fires for both creation and edits (WP 5.5+).
        add_action('saved_term', [__CLASS__, 'on_term_save'], 10, 4);
        add_action('delete_term', [__CLASS__, 'on_term_delete'], 10, 1);
    }

    public static function on_term_save(int $term_id, int $tt_id, string $taxonomy, bool $update = false) {
        // Note: deliberately NOT skipping DOING_AJAX — terms are routinely
        // created via AJAX (e.g. the inline "add category" box), and those are
        // real creates that must sync.

        // Map the taxonomy to a surreal table name. Empty means this taxonomy
        // isn't opted in, so there's nothing to sync.
        $surreal_table_name = apply_filters('surreal_map_term_table_name', '', $taxonomy, $term_id);

        if (empty($surreal_table_name)) return;

        $term = get_term($term_id, $taxonomy);

        if ( ! ( $term instanceof \WP_Term ) ) return;

        // Always seed the generic term data; downstream filters can add/remove.
        $mapped_entity_data = TermMapper::map(new MappedData(), $term_id);
        $mapped_entity_data = apply_filters('surreal_graph_map_term_' . $taxonomy, $mapped_entity_data, $term_id);

        $mapped_related_data = apply_filters('surreal_graph_map_term_related', [], $term);

        do_action('surreal_sync_term', $term, $surreal_table_name, $mapped_entity_data, $mapped_related_data);
    }

    public static function on_term_delete(int $term_id) {
        SurrealUtils::delete_surreal_record_by_term_id($term_id);
    }
}
