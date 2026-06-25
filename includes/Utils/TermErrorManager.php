<?php

namespace Dazamate\SurrealGraphSync\Utils;

use Dazamate\SurrealGraphSync\Enum\MetaKeys;

class TermErrorManager {
    public static function load_hooks() {
        add_action('admin_notices', [__CLASS__, 'display_errors']);
    }

    public static function add(int $term_id, array $errors): void {
        $existing = get_term_meta($term_id, MetaKeys::SURREAL_SYNC_ERROR_META_KEY->value, true);

        if (!is_array($existing)) $existing = [];

        $merged = array_merge($existing, $errors);

        update_term_meta($term_id, MetaKeys::SURREAL_SYNC_ERROR_META_KEY->value, $merged);
    }

    public static function display_errors(): void {
        $screen = get_current_screen();

        // Only on the term edit screen (edit-tags.php / term.php set screen->base to 'edit-tags' / 'term').
        if ( empty( $screen ) || ! in_array( $screen->base, ['edit-tags', 'term'], true ) ) {
            return;
        }

        $term_id = isset($_GET['tag_ID']) ? absint($_GET['tag_ID']) : 0;

        if ( $term_id < 1 ) return;

        $errors = get_term_meta($term_id, MetaKeys::SURREAL_SYNC_ERROR_META_KEY->value, true);

        if (!empty($errors)) {
            foreach ($errors as $message) {
                $safe_message = esc_html($message);

                echo sprintf(
                    '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                    $safe_message
                );
            }
        }
    }

    public static function clear(int $term_id): void {
        delete_term_meta($term_id, MetaKeys::SURREAL_SYNC_ERROR_META_KEY->value);
    }
}
