<?php

declare(strict_types=1);

/**
 * php-scoper configuration for building a conflict-proof release.
 *
 * WordPress runs every active plugin in one shared PHP process. If two plugins
 * each bundle their own vendor/ with different versions of a common library
 * (psr/log, ramsey/uuid, brick/math, composer/semver ...), whichever autoloader
 * registers the class name first wins and the other plugin silently runs against
 * the wrong version. To avoid that, every third-party namespace bundled with this
 * plugin is rewritten under a prefix unique to us, so our copies can never collide
 * with another plugin's.
 *
 * The plugin's own source (includes/ + the bootstrap file) is passed through the
 * scoper too — not to prefix it (our namespace is excluded below) but so that its
 * references to the now-prefixed vendor classes get rewritten to match.
 *
 * Run via bin/build.sh; not used at runtime or by the test/stan harness.
 */

use Isolated\Symfony\Component\Finder\Finder;

return [
    // Unique prefix for all bundled third-party code.
    'prefix' => 'Dazamate\\SurrealGraphSync\\Vendor',

    'finders' => [
        // 1. The dependencies we're isolating.
        Finder::create()
            ->files()
            ->ignoreVCS(true)
            ->notName('/\.(?:dist|md|lock|neon|xml|yml|yaml)$/')
            ->exclude([
                'doc',
                'docs',
                'test',
                'tests',
                'Test',
                'Tests',
                'example',
                'examples',
            ])
            ->in('vendor'),

        // 2. Our own source — so `use Surreal\Surreal;` etc. become
        //    `use Dazamate\SurrealGraphSync\Vendor\Surreal\Surreal;`.
        Finder::create()
            ->files()
            ->name('*.php')
            ->in('includes'),

        // 3. The plugin bootstrap (holds `require vendor/autoload.php`).
        Finder::create()->append(['surreal-graph-sync.php']),
    ],

    // Our own namespace is already globally unique — never prefix it, only
    // rewrite the vendor references found inside it.
    'exclude-namespaces' => [
        'Dazamate\\SurrealGraphSync',
    ],

    // WordPress core symbols are provided by the host at runtime and must stay
    // in the global namespace, never prefixed.
    'exclude-classes' => [
        '/^WP_/',
        'WP',
        'WP_CLI',
        'wpdb',
    ],
    'exclude-constants' => [
        '/^WP_/',
        'ABSPATH',
        'WPINC',
        'DOING_AJAX',
        'DOING_CRON',
        'OBJECT',
        'ARRAY_A',
        'ARRAY_N',
    ],
];
