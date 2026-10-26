<?php

namespace Dazamate\SurrealGraphSync\Cli;

use Dazamate\SurrealGraphSync\Enum\MetaKeys;
use Dazamate\SurrealGraphSync\Manager\ImageSyncManager;
use Dazamate\SurrealGraphSync\Manager\PostSyncManager;
use Dazamate\SurrealGraphSync\SurrealDBConnection;

/**
 * WP-CLI bulk resync of WordPress posts into SurrealDB.
 *
 * Usage:
 *   wp surreal sync --post_type=ingredient
 *   wp surreal sync --post_type=attachment
 *   wp surreal sync --post_type=ingredient --id=123
 */
class SyncPostsCommand {
    public static function register(): void {
        if ( ! class_exists( '\WP_CLI' ) ) {
            return;
        }

        \WP_CLI::add_command( 'surreal sync', [ __CLASS__, 'handle' ] );
    }

    /**
     * Sync posts of a given type to SurrealDB (same path as pressing Save).
     *
     * ## OPTIONS
     *
     * --post_type=<post_type>
     * : Post type slug to sync (e.g. ingredient, cooking-tool, cooking-supply, recipe, attachment).
     *
     * [--id=<id>]
     * : Sync a single post ID only (must match --post_type).
     *
     * [--status=<status>]
     * : Comma-separated post statuses to query. Default: publish (attachment defaults to
     * inherit,private,publish). Posts outside the syncable statuses (publish, unless the
     * `surreal_sync_post_statuses` filter adds more) are skipped: SurrealDB records carry no
     * status, so anything synced is live.
     *
     * [--limit=<n>]
     * : Max posts to process (0 = no limit). Default: 0.
     *
     * [--offset=<n>]
     * : Offset into the query results. Default: 0.
     *
     * [--dry-run]
     * : List posts that would be synced without writing to Surreal.
     *
     * ## EXAMPLES
     *
     *     wp surreal sync --post_type=ingredient
     *     wp surreal sync --post_type=cooking-tool
     *     wp surreal sync --post_type=cooking-supply
     *     wp surreal sync --post_type=attachment
     *     wp surreal sync --post_type=recipe
     *     wp surreal sync --post_type=ingredient --id=42
     *
     * @param array<int, string>    $args
     * @param array<string, mixed>  $assoc_args
     */
    public static function handle( array $args, array $assoc_args ): void {
        if ( ! SurrealDBConnection::has_db_conn() ) {
            \WP_CLI::error( 'No Surreal DB connection. Configure it under Settings → Surreal Sync first.' );
        }

        $post_type = (string) ( $assoc_args['post_type'] ?? '' );

        if ( $post_type === '' ) {
            \WP_CLI::error( 'Required: --post_type=<slug>' );
        }

        if ( ! post_type_exists( $post_type ) ) {
            \WP_CLI::error( sprintf( 'Unknown post type: %s', $post_type ) );
        }

        $dry_run = isset( $assoc_args['dry-run'] );
        $limit   = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;
        $offset  = isset( $assoc_args['offset'] ) ? max( 0, (int) $assoc_args['offset'] ) : 0;
        $single  = isset( $assoc_args['id'] ) ? (int) $assoc_args['id'] : 0;

        $statuses = self::resolve_statuses( $post_type, $assoc_args );
        $post_ids = self::query_post_ids( $post_type, $statuses, $single, $limit, $offset );

        if ( empty( $post_ids ) ) {
            \WP_CLI::warning( 'No posts matched.' );
            return;
        }

        $total   = count( $post_ids );
        $ok      = 0;
        $failed  = 0;
        $skipped = 0;
        $is_image = $post_type === 'attachment';

        \WP_CLI::log( sprintf(
            '%s %d %s post(s) [status=%s]%s',
            $dry_run ? 'Would sync' : 'Syncing',
            $total,
            $post_type,
            implode( ',', $statuses ),
            $dry_run ? ' (dry-run)' : ''
        ) );

        $progress = \WP_CLI\Utils\make_progress_bar( 'Surreal sync', $total );

        foreach ( $post_ids as $post_id ) {
            $post = get_post( $post_id );

            if ( ! ( $post instanceof \WP_Post ) ) {
                ++$skipped;
                $progress->tick();
                continue;
            }

            if ( $dry_run ) {
                \WP_CLI::log( sprintf( '  #%d %s', $post_id, $post->post_title ) );
                ++$ok;
                $progress->tick();
                continue;
            }

            try {
                $fired = $is_image
                    ? ImageSyncManager::sync_attachment( $post_id, $post )
                    : PostSyncManager::sync_post( $post_id, $post );

                if ( ! $fired ) {
                    ++$skipped;
                    \WP_CLI::warning( sprintf( '#%d skipped (not published / not mapped / not an image)', $post_id ) );
                    $progress->tick();
                    continue;
                }

                $errors = get_post_meta( $post_id, MetaKeys::SURREAL_SYNC_ERROR_META_KEY->value, true );

                // Warnings (a skipped link) are stored with errors but the record still synced.
                $is_warning = static fn( $e ): bool => is_string( $e ) && str_starts_with( $e, 'Surreal sync warning:' );
                $hard       = is_array( $errors ) ? array_filter( $errors, static fn( $e ): bool => ! $is_warning( $e ) ) : [];

                if ( ! empty( $hard ) ) {
                    ++$failed;
                    \WP_CLI::warning( sprintf( '#%d failed: %s', $post_id, implode( '; ', $hard ) ) );
                } else {
                    if ( ! empty( $errors ) && is_array( $errors ) ) {
                        \WP_CLI::warning( sprintf( '#%d synced with warnings: %s', $post_id, implode( '; ', $errors ) ) );
                    }

                    ++$ok;
                    $sid = get_post_meta( $post_id, MetaKeys::SURREAL_DB_RECORD_ID_META_KEY->value, true );
                    \WP_CLI::log( sprintf( '  #%d → %s', $post_id, $sid !== '' && $sid !== false ? $sid : '(no id)' ) );
                }
            } catch ( \Throwable $e ) {
                ++$failed;
                \WP_CLI::warning( sprintf( '#%d exception: %s', $post_id, $e->getMessage() ) );
            }

            $progress->tick();
        }

        $progress->finish();

        $msg = sprintf( 'Done. ok=%d failed=%d skipped=%d total=%d', $ok, $failed, $skipped, $total );

        if ( $failed > 0 ) {
            \WP_CLI::warning( $msg );
            return;
        }

        \WP_CLI::success( $msg );
    }

    /**
     * @param array<string, mixed> $assoc_args
     * @return list<string>
     */
    private static function resolve_statuses( string $post_type, array $assoc_args ): array {
        if ( isset( $assoc_args['status'] ) && is_string( $assoc_args['status'] ) && $assoc_args['status'] !== '' ) {
            return array_values( array_filter( array_map( 'trim', explode( ',', $assoc_args['status'] ) ) ) );
        }

        if ( $post_type === 'attachment' ) {
            return [ 'inherit', 'private', 'publish' ];
        }

        return [ 'publish' ];
    }

    /**
     * @param list<string> $statuses
     * @return list<int>
     */
    private static function query_post_ids(
        string $post_type,
        array $statuses,
        int $single_id,
        int $limit,
        int $offset
    ): array {
        if ( $single_id > 0 ) {
            $post = get_post( $single_id );

            if ( ! ( $post instanceof \WP_Post ) || $post->post_type !== $post_type ) {
                return [];
            }

            return [ $single_id ];
        }

        $query = [
            'post_type'              => $post_type,
            'post_status'            => $statuses,
            'posts_per_page'         => $limit > 0 ? $limit : -1,
            'offset'                 => $offset,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        if ( $post_type === 'attachment' ) {
            $query['post_mime_type'] = 'image';
        }

        /** @var list<int> $ids */
        $ids = get_posts( $query );

        return array_map( 'intval', $ids );
    }
}
