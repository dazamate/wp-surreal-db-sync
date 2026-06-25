<?php

namespace Dazamate\SurrealGraphSync\Service;

use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Dto\Reference\WordPressId;
use Dazamate\SurrealGraphSync\Utils\UserErrorManager;
use Dazamate\SurrealGraphSync\Enum\QueryType;
use Dazamate\SurrealGraphSync\Enum\MetaKeys;

class UserSyncService extends SyncService {
    public static function load_hooks() {
        add_action('surreal_sync_user', [__CLASS__, 'sync_user'], 10, 4);
    }

    public static function sync_user(\WP_User $user, string $mapped_table_name, MappedData $mapped_user_data, array $mapped_related_data) {
        UserErrorManager::clear($user->ID);

        if (empty($mapped_table_name)) {
            UserErrorManager::add($user->ID, ['No mapped user table name found - you must use the "surreal_graph_user_role_map" filter to map a user role to a Surreal DB table name']);
            return;
        }

        // No mapping done for this type
        if ($mapped_user_data->is_empty()) {
            return;
        }

        $errors = [];

        self::validate($mapped_user_data, QueryType::USER, $errors);

        if (!empty($errors)) {
            UserErrorManager::add($user->ID, $errors);
            wp_redirect(admin_url('user-edit.php?user_id=' . $user->ID)); exit;
        }

        $db = self::get_surreal_db_conn($errors);

        if (!empty($errors)) {
            UserErrorManager::add($user->ID, $errors);
            return;
        }

        // Upsert the user record and all of its edges atomically — either
        // everything commits or nothing does. A relation may reference this user
        // by its WP id even on a first save (resolved to the in-transaction
        // record via the `$rec` variable).
        $surreal_id = self::persist_entity(
            $db,
            $mapped_table_name,
            $mapped_user_data,
            $mapped_related_data,
            new WordPressId($user->ID, QueryType::USER),
            $errors
        );

        if ($surreal_id === null) {
            UserErrorManager::add($user->ID, $errors);
            return;
        }

        update_user_meta($user->ID, MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value, $surreal_id);
    }
}
