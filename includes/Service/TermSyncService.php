<?php

namespace Dazamate\SurrealGraphSync\Service;

use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Dto\Reference\WordPressId;
use Dazamate\SurrealGraphSync\Utils\TermErrorManager;
use Dazamate\SurrealGraphSync\Enum\QueryType;
use Dazamate\SurrealGraphSync\Enum\MetaKeys;

class TermSyncService extends SyncService {
    public static function load_hooks() {
        add_action('surreal_sync_term', [__CLASS__, 'sync_term'], 10, 4);
    }

    public static function sync_term(\WP_Term $term, string $mapped_table_name, MappedData $mapped_entity_data, array $mapped_related_data) {
        TermErrorManager::clear($term->term_id);

        if (empty($mapped_table_name)) {
            // No table mapped means the taxonomy isn't opted in — nothing to do.
            return;
        }

        // No mapping done for this taxonomy
        if ($mapped_entity_data->is_empty()) {
            return;
        }

        $errors = [];

        self::validate($mapped_entity_data, QueryType::TERM, $errors);

        if (!empty($errors)) {
            TermErrorManager::add($term->term_id, $errors);
            return;
        }

        $db = self::get_surreal_db_conn($errors);

        if (!empty($errors)) {
            TermErrorManager::add($term->term_id, $errors);
            return;
        }

        // Upsert the term record and all of its edges atomically — either
        // everything commits or nothing does. A relation may reference this term
        // by its WP id even on a first save (resolved to the in-transaction
        // record via the `$rec` variable).
        $surreal_id = self::persist_entity(
            $db,
            $mapped_table_name,
            $mapped_entity_data,
            $mapped_related_data,
            new WordPressId($term->term_id, QueryType::TERM),
            $errors
        );

        if ($surreal_id === null) {
            TermErrorManager::add($term->term_id, $errors);
            return;
        }

        update_term_meta($term->term_id, MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value, $surreal_id);
    }
}
