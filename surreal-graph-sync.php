<?php
/*
 * Plugin Name:       Surreal Graph Sync
 * Description:       Convert wordpress data to a Surreal DB graph data
 * Version:           1.0
 * Requires PHP:      8.5
 * Author:            Dale Woods
 * Author URI:        https://dalewoods.me
 * Requires Plugins:  
 */

require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

use Dazamate\SurrealGraphSync\SurrealDBConnection;
use Dazamate\SurrealGraphSync\Settings\AdminSettings;
use Dazamate\SurrealGraphSync\Manager\PostSyncManager;
use Dazamate\SurrealGraphSync\Manager\UserSyncManager;
use Dazamate\SurrealGraphSync\Manager\ImageSyncManager;
use Dazamate\SurrealGraphSync\Manager\TermSyncManager;
use Dazamate\SurrealGraphSync\Manager\TermAssociationManager;
use Dazamate\SurrealGraphSync\Migration\InitialMigration;

use Dazamate\SurrealGraphSync\Service\SyncService;
use Dazamate\SurrealGraphSync\Service\PostSyncService;
use Dazamate\SurrealGraphSync\Service\UserSyncService;
use Dazamate\SurrealGraphSync\Service\TermSyncService;
use Dazamate\SurrealGraphSync\Utils\ErrorManager;
use Dazamate\SurrealGraphSync\Utils\UserErrorManager;
use Dazamate\SurrealGraphSync\Utils\TermErrorManager;

use Dazamate\SurrealGraphSync\Mapper\Entity\ImageMapper;
use Dazamate\SurrealGraphSync\Mapper\Entity\PostMapper;

use Dazamate\SurrealGraphSync\Mapper\User\UserMapper;
use Dazamate\SurrealGraphSync\Mapper\User\PersonMapper;

use Dazamate\SurrealGraphSync\Mapper\Term\TermMapper;

add_action( 'plugins_loaded', function() {
    SurrealDBConnection::init_db_connection();
    SurrealDBConnection::load_hooks();

    // The settings menu must always be available — it's where the DB connection
    // details are entered. Gating it behind has_db_conn() would make it impossible
    // to configure the connection when there isn't one yet (chicken-and-egg).
    AdminSettings::load_hooks();

    if ( ! SurrealDBConnection::has_db_conn() ) return;

    SyncService::load_hooks();
    PostSyncService::load_hooks();
    UserSyncService::load_hooks();
    TermSyncService::load_hooks();

    // Register schema migrations on the `get_surreal_graph_migrations` filter so
    // they appear in the Migrations admin page. Extensions can register their own
    // migration groups on the same filter.
    InitialMigration::load_hooks();
});

add_action('init', function () {
    if ( ! SurrealDBConnection::has_db_conn() ) return;

    ErrorManager::load_hooks();
    UserErrorManager::load_hooks();
    TermErrorManager::load_hooks();

    PostSyncManager::load_hooks();
    UserSyncManager::load_hooks();
    ImageSyncManager::load_hooks();
    TermSyncManager::load_hooks();
    TermAssociationManager::register();

    ImageMapper::register();
    PostMapper::register();

    UserMapper::register();
    PersonMapper::register();

    TermMapper::register();
});