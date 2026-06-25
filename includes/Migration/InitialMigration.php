<?php

namespace Dazamate\SurrealGraphSync\Migration;

use Dazamate\SurrealGraphSync\Enum\GraphTable;
use  Dazamate\SurrealGraphSync\Query\QueryBuilder;

if ( ! defined( 'ABSPATH' ) ) exit;

class InitialMigration {
    const MIGRATION_GROUP = 'generic_wordpress_types';
    const MIGRATION_NAME = 'initial_migration';
    const MIGRATION_DATE = '2025-01-01';

    public static function load_hooks() {
        add_filter('get_surreal_graph_migrations', [__CLASS__, 'build_migration_data'], 1, 1);
    }

    public static function build_migration_data(array $migrations): array {
        $queries                = [];
        $queries['up']          = self::up();
        $queries['down']        = self::down();
        $queries['datetime']    = self::MIGRATION_DATE;
        $queries['name']        = self::MIGRATION_NAME;

        $migrations[self::MIGRATION_GROUP][self::MIGRATION_NAME] = $queries;
        return $migrations;
    }

    public static function up(): array {
        $up = [];

        // -- Schema definitions are intentionally disabled. -----------------------
        // SurrealDB is schemaless by default. The sync services write records with
        // `UPSERT person:…` / `UPSERT image:…`, and SurrealDB creates the table on
        // the first write if it doesn't exist yet — so you do NOT need to DEFINE a
        // table before data can land in it.
        //
        // We also deliberately avoid SCHEMAFULL here. A SCHEMAFULL table rejects any
        // field it wasn't explicitly told about, so every plugin that wanted to hang
        // its own field off `person` (say a CRM add-on adding `loyalty_points`) would
        // be forced to ship a migration first. Leaving the table schemaless keeps it
        // open for other plugins to extend via the mapping filters.
        //
        // The calls below are kept (commented) as a worked example of how you *would*
        // lock a table down if you ever wanted a strict schema — e.g. a `recipe` table
        // where every record must have a numeric `cook_time_minutes` and nothing stray
        // is allowed in. Uncomment, adapt to your table, and the admin Migrations page
        // will run them in order.
        //
        //   $up[] = self::create_image_table_query();
        //   $up[] = self::create_person_table_query();
        //   $up[] = self::create_image_fields_query();
        //   $up[] = self::create_person_fields_query();
        //   $up[] = self::create_migration_state_update_query();

        return $up;
    }

    public static function down(): array {
        $down = [];

        // The reverse of up(). Since up() defines nothing, there's nothing to roll
        // back. If you enable the schema definitions above, the matching teardown
        // would look like this (`IF EXISTS` keeps the rollback safe to re-run):
        //
        //   $down[] = sprintf("REMOVE TABLE IF EXISTS %s;", GraphTable::IMAGE->to_string());
        //   $down[] = sprintf("REMOVE TABLE IF EXISTS %s;", GraphTable::PERSON->to_string());
        //
        //   // Reset this group's high-water-mark so the admin page re-offers "Migrate up".
        //   $down[] = sprintf(
        //       'UPSERT %s:state SET %s = NONE;',
        //       GraphTable::MIGRATION->to_string(),
        //       self::MIGRATION_GROUP
        //   );

        return $down;
    }

    // ---------------------------------------------------------------------------
    // Example query builders — not invoked while migrations are schemaless-first
    // (see up()), kept as a reference for writing a real schema migration.
    // ---------------------------------------------------------------------------

    protected static function create_image_table_query(): string {
        return sprintf("DEFINE TABLE IF NOT EXISTS %s SCHEMAFULL;", GraphTable::IMAGE->to_string());
    }

    protected static function create_person_table_query(): string {
        return sprintf("DEFINE TABLE IF NOT EXISTS %s SCHEMAFULL;", GraphTable::PERSON->to_string());
    }

    protected static function create_image_fields_query(): string {
        $fields = [
            'width'     => [
                'type'      => 'number',
                'default'   => null,
                'assert'    => null
            ],
            'height'    => [
                'type'      => 'number',
                'default'   => null,
                'assert'    => null
            ],
            'url'       => [
                'type'      => 'string',
                'default'   => null,
                'assert'    => null
            ],
            'caption'   => [
                'type'      => 'string',
                'default'   => null,
                'assert'    => null
            ],
            'alt'       => [
                'type'      => 'string',
                'default'   => null,
                'assert'    => null
            ]
        ];

        return QueryBuilder::build_create_field_query_on_array(GraphTable::IMAGE->to_string(), $fields, true);
    }

    protected static function create_person_fields_query(): string {
        // If you opt `person` into SCHEMAFULL above, these fields must mirror what
        // PersonMapper writes (see the `surreal_graph_map_user_person` filter in the
        // README) — a SCHEMAFULL table would reject any user field not defined here.
        $fields = [
            'username'      => [
                'type'      => 'string',
                'default'   => null,
                'assert'    => null
            ],
            'email'         => [
                'type'      => 'string',
                'default'   => null,
                'assert'    => null
            ],
            'display_name'  => [
                'type'      => 'string',
                'default'   => null,
                'assert'    => null
            ],
            'user_id'       => [
                'type'      => 'number',
                'default'   => null,
                'assert'    => null
            ]
        ];

        return QueryBuilder::build_create_field_query_on_array(GraphTable::PERSON->to_string(), $fields, true);
    }

    protected static function create_migration_state_update_query(): string {
        // UPSERT creates the migration:state record on first run and updates it
        // thereafter, so the migration stays idempotent on re-run.
        return sprintf(
            'UPSERT %s:state SET %s.last_migration_name = "%s", %s.last_migration_time = <datetime>"%sT00:00:00Z";',
            GraphTable::MIGRATION->to_string(),
            self::MIGRATION_GROUP,
            self::MIGRATION_NAME,
            self::MIGRATION_GROUP,
            self::MIGRATION_DATE
        );
    }
}
