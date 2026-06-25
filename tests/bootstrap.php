<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Several plugin files guard with `if (!defined('ABSPATH')) exit;` so they
// can't be loaded outside WordPress. Define a dummy constant so those files are
// safe to autoload in the test process.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Minimal stubs for the WordPress classes the plugin type-hints against, so
// end-to-end tests can construct them. WordPress itself isn't loaded in tests;
// its functions are mocked per-test with Brain\Monkey.
if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID = 0;
        public string $post_type = '';
        public string $post_title = '';
    }
}

if (!class_exists('WP_User')) {
    class WP_User {
        public int $ID = 0;
        public string $user_login = '';
        public string $user_email = '';
        public string $display_name = '';
    }
}

if (!class_exists('WP_Term')) {
    class WP_Term {
        public int $term_id = 0;
        public string $taxonomy = '';
        public string $name = '';
        public string $slug = '';
        public int $parent = 0;
        public string $description = '';
        public int $count = 0;
    }
}
