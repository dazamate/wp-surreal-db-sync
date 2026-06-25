<?php

namespace Dazamate\SurrealGraphSync\Mapper\User;

use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Field\StringField;
use Dazamate\SurrealGraphSync\Field\NumberField;

if ( ! defined( 'ABSPATH' ) ) exit;

class UserMapper {
    public static function register() {
        add_filter('surreal_graph_map_user_user', [__CLASS__, 'map'], 10, 2);
        add_filter('surreal_graph_user_role_map', [__CLASS__, 'map_user_roles_to_surreal_type'], 10, 1);
    }

    public static function map_user_roles_to_surreal_type(array $user_role_map): array {
        $user_role_map['user'] = [
            'subscriber'
        ];

        return $user_role_map;
    }

    public static function map(MappedData $mapped_data, \WP_User $user): MappedData {
        return $mapped_data
            ->set('username', new StringField($user->user_login))
            ->set('email', new StringField($user->user_email))
            ->set('display_name', new StringField($user->display_name))
            ->set('user_id', new NumberField($user->ID));
    }
}
