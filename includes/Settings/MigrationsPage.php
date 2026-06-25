<?php

namespace Dazamate\SurrealGraphSync\Settings;

use Dazamate\SurrealGraphSync\Enum\MigrationDirection;

if ( ! defined( 'ABSPATH' ) ) exit;

class MigrationsPage {
    // NB: this page's admin menu item is registered by AdminSettings::surreal_sync_add_menu_item();
    // there's no load_hooks() here.

    static private function get_previous_migrations(array $group_names): array {
        $db = apply_filters('get_surreal_db_conn', null);

        if ( ! ( $db instanceof \Surreal\Surreal ) ) {
            error_log('Unable to get Surreal DB connection ' . __FUNCTION__ . ' ' . __LINE__);
        }

        $q = sprintf("SELECT * FROM migration:state");

        $res = $db->query($q); 
        
        $last_migarations = array_reduce($group_names, function($carry = [], $group_name = '') {
            $carry[$group_name] = 0;
            return $carry;
        });

        foreach ($res[0][0] ?? [] as $k => $v) {
            if ($k === 'id') continue;

            if (($v['last_migration_time'] ?? null) instanceof \DateTime) {
                $last_migarations[$k] = $v['last_migration_time']->getTimeStamp();
            }
        }

        return $last_migarations;
    }

    static public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Ordered array keyed by migration name; each value has up/down keys holding an
        // ordered list of migrations to perform.
        $migrations = [];
        $migrations = apply_filters('get_surreal_graph_migrations', $migrations);

        self::sort_migrations($migrations);

        // Handle form submissions (Up/Down migrations)
        self::handle_migration_post();

        $prev_migrations = self::get_previous_migrations(array_keys($migrations));

        ?>
        <div class="wrap">
            <h1>Surreal Migrations</h1>

            <form method="POST">
                <?php wp_nonce_field( 'surreal_sync_migrations_nonce', 'surreal_sync_migrations_nonce_field' ); ?>

                <?php foreach ($migrations as $migration_group_name => $migration_group_data): ?>
                    <?php $friendly_migration_group_name = str_ireplace('_', ' ', $migration_group_name); ?>
                    
                    <?php $last_migration_stamp = $prev_migrations[$migration_group_name] ?? 0; ?>
                    <h2><?php echo $friendly_migration_group_name; ?></h2>

                    <?php if ($last_migration_stamp === 0): ?>
                        <p>Last migration date id: <i>none</i></p>
                    <?php else: ?>
                        <p>Last migration date id: <?php echo date('Y m d', $last_migration_stamp); ?></p>
                    <?php endif; ?>

                    <table class="widefat fixed" style="margin-bottom: 50px;" cellspacing="0">
                        <thead>
                            <tr>                            
                                <th class="manage-column column-title">Migration Number</th>
                                <th class="manage-column column-title">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( ! empty( $migration_group_data ) ) : ?>
                                <?php foreach ($migration_group_data as $migration_name => $migration_data ) : ?>
                                    <?php $has_migrated = $last_migration_stamp < strtotime($migration_data['datetime'] ?? 0); ?>
                                    <?php $friendly_migration_name = str_ireplace('_', ' ', $migration_name);?>
                                    <tr>
                                        <td><?php echo esc_html( $friendly_migration_name ); ?> - <?php echo date('Y m d', $last_migration_stamp); ?></td>
                                        <td>
                                            <button 
                                                type="submit"
                                                name="migration_up"
                                                value="<?php echo esc_attr( $migration_name ); ?>"
                                                class="button"
                                                <?php if (isset($migration_data['datetime'])): ?>
                                                    <?php echo ($has_migrated) ? '' :  'disabled'; ?>
                                                <?php endif; ?>
                                            >Migrate up</button>
                                            <button 
                                                type="submit"
                                                name="migration_down"
                                                value="<?php echo esc_attr( $migration_name ); ?>"
                                                class="button"
                                                <?php if (isset($migration_data['datetime'])): ?>
                                                    <?php echo ($has_migrated) ? 'disabled' : ''; ?>
                                                <?php endif; ?>
                                            >Migrate down</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr>
                                    <td></td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td>Migrate All</td>
                                    <td>
                                        <button 
                                            type="submit"
                                            name="migration_up_all"
                                            value="<?php echo esc_attr( $migration_group_name ); ?>"
                                            class="button"
                                        >Migrate all <b><?php echo esc_attr( $friendly_migration_group_name ); ?></b> up</button>
                                        
                                        <button 
                                            type="submit" 
                                            name="migration_down_all" 
                                            value="<?php echo esc_attr( $migration_group_name ); ?>"
                                            class="button"
                                        >Migrate all <b><?php echo esc_attr( $friendly_migration_group_name ); ?></b> down</button>
                                    </td>
                                </tr>
                            <?php else : ?>
                                <tr>
                                    <td colspan="2">No migrations found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>    
            </form>
        </div>
        <?php
    }

    private static function sort_migrations(array &$migrations): void {
        foreach ($migrations as &$migration_group) {
            uasort($migration_group, function($a, $b) {                
                $datetime_a = strtotime($a['datetime'] ?? '');
                $datetime_b = strtotime($b['datetime'] ?? '');
                return $datetime_a - $datetime_b;
            });            
        }
    }

    private static function handle_migration_post() {
        if ( ! isset( $_POST['surreal_sync_migrations_nonce_field'] ) ||
             ! wp_verify_nonce( $_POST['surreal_sync_migrations_nonce_field'], 'surreal_sync_migrations_nonce' ) ) {
            return;
        }

        if ( isset($_POST['migration_up_all']) ||  isset($_POST['migration_down_all'])) {
            self::handle_group_migration(
                $_POST['migration_up_all'] ?? $_POST['migration_down_all'],
                (isset($_POST['migration_up_all'])) ? MigrationDirection::UP : MigrationDirection::DOWN
            );
        }

        // If a single migration_up or migration_down was triggered, run it. The
        // POST value is the migration *name*; migrations are consumed as data via
        // the `get_surreal_graph_migrations` filter, so there's no class to load.
        if ( isset( $_POST['migration_up'] ) || isset( $_POST['migration_down'] ) ) {
            $direction = isset( $_POST['migration_up'] )
                ? MigrationDirection::UP
                : MigrationDirection::DOWN;

            $migration_name = sanitize_text_field(
                $_POST['migration_up'] ?? $_POST['migration_down']
            );

            $queries = self::get_migration_queries( $migration_name, $direction );

            if ( empty( $queries ) ) {
                add_settings_error(
                    'migration',
                    'migration_not_found',
                    sprintf( "No '%s' queries found for migration: %s.", $direction->value, $migration_name ),
                    'error'
                );
                return;
            }

            self::run_queries( $queries );
        }
    }

    protected static function handle_group_migration(string $group_name, MigrationDirection $direction): void {
        self::run_queries(self::get_all_migration_queries_of_group($group_name, $direction));
    }

    // Execute an ordered list of SurrealQL strings and render the output. Shared by the
    // per-migration and per-group ("Migrate All") handlers.
    protected static function run_queries(array $queries): void {
        $db = apply_filters('get_surreal_db_conn', null);

        if ( ! ( $db instanceof \Surreal\Surreal ) ) {
            add_settings_error('migration', 'no_db_conn', 'Unable to get a Surreal DB connection to run the migration.', 'error');
            return;
        }

        $q = '';
        ob_start();
        try {
            foreach($queries as $q): if (empty($q)) continue; ?>
                <?php $result = $db->query($q); ?>
                <pre style="white-space: pre-line;"><?php echo htmlentities($q); ?></pre>
                <pre style="white-space: no-wrap; background-color: #393939; color:white; padding: 20px;"><?php echo htmlentities(print_r($result, true)); ?></pre>
            <?php endforeach;
        } catch (\Throwable $e) { ?>
            <pre style="white-space: pre-line;"><?php echo htmlentities($q); ?></pre>
            <pre style="white-space: pre-line; background-color:red; color:white;"><?php echo htmlentities($e->getMessage()); ?></pre>
        <?php }
        // NB: don't $db->close() here — it's the shared singleton connection used
        // for the rest of the request.

        $result_html = ob_get_clean(); ?>
        <h2>Migration Output</h2>
        <div id="migration-restults" style="
            height:500px;
            overflow-y: scroll;
            padding: 30px;
            border: #c4c4c4 solid 1px;
            background: #fffbe1;">
            <?php echo $result_html; ?></div>
        <?php
    }

    // Resolve the ordered query list for a single migration (by name) in the given
    // direction. Searches every group since migration names are unique system-wide.
    protected static function get_migration_queries(string $migration_name, MigrationDirection $direction): array {
        $migrations = apply_filters('get_surreal_graph_migrations', []);
        self::sort_migrations($migrations);

        foreach ($migrations as $migration_group) {
            if ( ! isset($migration_group[$migration_name]) ) continue;

            return $migration_group[$migration_name][$direction->value] ?? [];
        }

        return [];
    }

    protected static function get_all_migration_queries_of_group(string $group_name, MigrationDirection $direction): array {
        $migrations = apply_filters('get_surreal_graph_migrations', []);
        self::sort_migrations($migrations);

        $migration_group = $migrations[$group_name];
        
        if (empty($migration_group)) return [];

        $ordered_queries = [];

        foreach ($migration_group as $migration_name => $migration_data) {
            if (empty($migration_data[$direction->value])) continue;
            foreach ($migration_data[$direction->value] as $q)
            $ordered_queries[] = $q;
        }

        return $ordered_queries;
    }
}
