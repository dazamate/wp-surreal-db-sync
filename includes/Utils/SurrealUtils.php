<?php

namespace Dazamate\SurrealGraphSync\Utils;

use Dazamate\SurrealGraphSync\Enum\MetaKeys;

class SurrealUtils {
    public static function delete_surreal_record_by_post_id(int $post_id) {
        $surreal_record_id = get_post_meta($post_id, MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value, true);

        if (empty($surreal_record_id)) return;

        do_action('surreal_delete_record', $surreal_record_id);

        // delete the meta key incase it's just going to trash
        delete_post_meta($post_id, MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value);
    }

    public static function delete_surreal_record_by_term_id(int $term_id) {
        $surreal_record_id = get_term_meta($term_id, MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value, true);

        if (empty($surreal_record_id)) return;

        do_action('surreal_delete_record', $surreal_record_id);

        delete_term_meta($term_id, MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value);
    }
}