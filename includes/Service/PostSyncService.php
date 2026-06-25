<?php

namespace Dazamate\SurrealGraphSync\Service;

use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Dto\Reference\WordPressId;
use Dazamate\SurrealGraphSync\Utils\ErrorManager;
use Dazamate\SurrealGraphSync\Enum\QueryType;
use Dazamate\SurrealGraphSync\Enum\MetaKeys;

class PostSyncService extends SyncService {
    const SURREAL_SYNC_ERROR_META_KEY = 'surreal_sync_error';

    public static function load_hooks() {
        add_action('surreal_sync_post', [__CLASS__, 'sync_post'], 10, 3);
    }

    public static function sync_post(\WP_Post $post, string $mapped_table_name, MappedData $mapped_entity_data) {
        ErrorManager::clear($post->ID);

        if (empty($mapped_table_name)) {
            ErrorManager::add($post->ID, ['No mapped table name found - you must use the "surreal_map_post_table_name" filter to map a post type to a Surreal DB table name']);
            return;
        }

        // No mapping done for this type
        if ($mapped_entity_data->is_empty()) {
            return;
        }

        $errors = [];

        // Errors for mapped entity data
        self::validate($mapped_entity_data, QueryType::POST, $errors);

        if (!empty($errors)) {
            ErrorManager::add($post->ID, $errors);
            return;
        }

        // Errors for failed db conn
        $db = self::get_surreal_db_conn($errors);

        if (!empty($errors)) {
            ErrorManager::add($post->ID, $errors);
            return;
        }

        // Relations are mapped via a filter. They're upserted in the same
        // transaction as the record below, so a relation may reference this
        // post by its WP id even on a first save (resolved to the in-transaction
        // record via the `$rec` variable).
        $mapped_related_data = apply_filters('surreal_graph_map_related', [], $post);

        // Upsert the record and all of its edges atomically — either everything
        // commits or nothing does.
        $surreal_id = self::persist_entity(
            $db,
            $mapped_table_name,
            $mapped_entity_data,
            $mapped_related_data,
            new WordPressId($post->ID, QueryType::POST),
            $errors
        );

        if ($surreal_id === null) {
            ErrorManager::add($post->ID, $errors);
            return;
        }

        update_post_meta($post->ID, MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value, $surreal_id);
    }
}
