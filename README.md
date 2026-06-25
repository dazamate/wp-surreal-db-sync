# WP → SurrealDB Graph Sync

Maps WordPress data (posts, users, terms) into a graph format for SurrealDB. The plugin is
extended entirely through WordPress filters: you hook a filter, add typed **field
objects** to the `MappedData` you're given, and return it.

> Field data is defined with typed DTOs (`StringField`, `NumberField`, `ArrayField`, etc…)
> collected in a `MappedData` object. Relations are `RelationData` objects. The old
> `['type' => ..., 'value' => ...]` array shape is no longer supported — each field is a
> strict data parcel that the sync layer validates and renders.

## The field objects

All field classes live under `Dazamate\SurrealGraphSync\Field`:

| Field class      | Constructor                                   | Renders as                       |
|------------------|-----------------------------------------------|----------------------------------|
| `StringField`    | `new StringField('hello')`                    | `<string>'hello'`                |
| `NumberField`    | `new NumberField(42)` / `new NumberField(0.2)`| `<number>42` / `<number>0.2`     |
| `DateTimeField`  | `new DateTimeField('2025-01-02T00:00:00Z')`   | `<datetime>'...'`                |
| `RecordField`    | `new RecordField(new WordPressId(42, QueryType::POST))` | `<record<image>>image:abc…`      |
| `ArrayField`     | `new ArrayField(['a','b'], 'array<string>')`  | `<array<string>>['a', 'b']`      |
| `ObjectField`    | `new ObjectField($mappedData)`                | `<object>{ ... }`                |
| `RawField`       | `new RawField('1w', 'duration')`              | `<duration>1w` (escape hatch)    |

Notes:
- `RecordField` points at a related record via a **reference object** (both live under
  `Dazamate\SurrealGraphSync\Dto\Reference`):
  - `new SurrealId('image:abc')` — a record id that is already in Surreal form.
  - `new WordPressId(42, QueryType::POST)` — a numeric WP id resolved to its Surreal record
    id at render time. The `QueryType` selects the lookup (`QueryType::POST` for posts,
    `QueryType::USER` for users, `QueryType::TERM` for taxonomy terms) — e.g. pointing an
    `order`'s `placed_by` field at the customer who is a WP user:
    `new RecordField(new WordPressId($user->ID, QueryType::USER))`.
- You never name the target table. The cast (`<record<person>>`) is derived automatically
  from the resolved record id, so a `WordPressId`/`SurrealId` that resolves to `person:abc`
  always renders as `<record<person>>person:abc` — no type string to keep in sync.
- `ArrayField` items may be plain scalars or other `Field` objects (e.g. an array of
  `ObjectField`s).
- Empty/`null` values collapse to `NULL` automatically.
- Reach for `RawField` only for a Surreal type with no dedicated field class.

For example, modelling a product for an online store touches most of the field types at once:

```php
use Dazamate\SurrealGraphSync\Field\StringField;
use Dazamate\SurrealGraphSync\Field\NumberField;
use Dazamate\SurrealGraphSync\Field\ArrayField;
use Dazamate\SurrealGraphSync\Field\RecordField;
use Dazamate\SurrealGraphSync\Field\RawField;
use Dazamate\SurrealGraphSync\Dto\Reference\WordPressId;
use Dazamate\SurrealGraphSync\Enum\QueryType;

$data
    ->set('name',      new StringField('Mechanical Keyboard'))
    ->set('price',     new NumberField(149.99))
    ->set('tags',      new ArrayField(['electronics', 'peripherals'], 'array<string>'))
    ->set('hero_image', new RecordField(new WordPressId(512, QueryType::POST)))   // 512 = WP attachment id, resolved to image:…
    ->set('warranty',  new RawField('1y', 'duration'));          // Surreal duration, no dedicated field class
```

## Building a MappedData

`MappedData` is the collection you add fields to. Its fluent helpers — shown here building a
recipe record:

```php
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Field\StringField;
use Dazamate\SurrealGraphSync\Field\RecordField;
use Dazamate\SurrealGraphSync\Dto\Reference\WordPressId;
use Dazamate\SurrealGraphSync\Enum\QueryType;

$data
    ->set('title', new StringField('Spaghetti Carbonara'))         // add / overwrite a field
    ->set_if($has_photo, 'photo', fn() => new RecordField(new WordPressId($photo_id, QueryType::POST))) // add only if condition is true
    ->remove('draft_notes');                                       // drop a field
```

`set_if` only evaluates the closure (and adds the field) when the condition is true — handy
when the value is expensive to build or simply absent, like the recipe photo above that only
exists once an editor has uploaded one.

## Registering a post type (map it to a Surreal table)

A post type only syncs once you map it to a Surreal table. This filter **is** the
registration: return a non-empty table name to opt the post type in, or leave the
default empty string to ignore it.

```php
apply_filters('surreal_map_post_table_name', string $surreal_table_name, string $post_type, int $post_id): string;
```

```php
add_filter('surreal_map_post_table_name', function (string $table, string $post_type, int $post_id): string {
    return $post_type === 'recipe' ? 'recipe' : $table;
}, 10, 3);
```

**Unmapped post types are skipped silently.** When this filter returns an empty string,
the plugin does nothing — no record, no `surreal_graph_map_*` call, and no admin notice.
This is what lets the plugin coexist with other plugins that register their own custom
post types: only the post types you explicitly map are ever touched. The
"No mapped table name found" error therefore only appears for a post type you *did* map
but left misconfigured downstream.

> The same opt-in model applies to the other entity types: taxonomy terms register via
> [`surreal_map_term_table_name`](#mapping-taxonomy-terms), and users register via
> the `surreal_graph_user_role_map` role → table map. In every case, "not registered"
> means "skipped silently", never an error.

## Mapping post types

When a post is created/updated, this filter is called for its post type. You receive a
`MappedData` already pre-seeded with the generic post fields — `title`, `content`,
`post_id`, `created`, `published`, `update`, and (when a featured image is set)
`thumbnail_image` — and return it with your additions:

```php
apply_filters('surreal_graph_map_{post_type}', MappedData $mapped_data, int $post_id): MappedData;
```

```php
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Field\StringField;
use Dazamate\SurrealGraphSync\Field\NumberField;
use Dazamate\SurrealGraphSync\Field\RecordField;
use Dazamate\SurrealGraphSync\Field\ArrayField;
use Dazamate\SurrealGraphSync\Field\ObjectField;
use Dazamate\SurrealGraphSync\Dto\Reference\SurrealId;

add_filter('surreal_graph_map_service', function (MappedData $mapped_data, int $post_id): MappedData {
    $post = get_post($post_id);

    return $mapped_data
        ->set('title', new StringField($post->post_title))
        ->set('post_id', new NumberField($post_id))
        ->set('my_meta_key', new StringField(get_post_meta($post_id, 'my_meta_key', true)))
        ->set('another_record', new RecordField(new SurrealId('person:98fsdfds987sdf90')))
        ->set('my_array', new ArrayField(['a', 'b', 'c'], 'array<string>'))
        ->set('my_object', new ObjectField(
            (new MappedData())
                ->set('object_field_a', new NumberField(42))
                ->set('object_field_b', new StringField('my object field'))
        ));
}, 10, 2);
```

The `MappedData` you return *is* the data that gets synced — other plugins may have added
fields before you, so add to the object you were given rather than starting a new one.

## Mapping images

Image attachments are mapped through their own filter, in the same way as post types — but
unlike a custom post type you **don't register a table for them**: any attachment with an
`image/*` mime type is automatically synced to the `image` table. The `MappedData` you
receive is already pre-seeded with the generic image fields — `title`, `post_id`, `mime`,
`src` (the attachment URL), `alt`, `caption`, and `description` — so this filter is only for
adding or overriding fields:

```php
apply_filters('surreal_graph_map_image', MappedData $mapped_data, int $post_id): MappedData;
```

```php
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Field\StringField;

add_filter('surreal_graph_map_image', function (MappedData $mapped_data, int $post_id): MappedData {
    // The generic fields (title, post_id, mime, src, alt, caption, description) are
    // already set — add your own, e.g. a photographer credit stored in post meta.
    return $mapped_data->set(
        'credit',
        new StringField(get_post_meta($post_id, 'photographer_credit', true) ?: '')
    );
}, 10, 2);
```


## User mapping

First map WordPress roles to Surreal table(s) — this is how a user is registered for
sync. The array key is the Surreal table name, the values are the WordPress roles mapped
to it. A role can be mapped to multiple tables. Users whose roles aren't in the map are
skipped silently (no record, no error).

```php
add_filter('surreal_graph_user_role_map', function (array $user_role_map): array {
    $user_role_map['person'] = [
        'editor',
        'author',
        'contributor',
        'administrator',
    ];

    return $user_role_map;
});
```

Then map the user's fields. This filter includes the Surreal table type in its name and
receives a `MappedData`:

```php
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Field\StringField;
use Dazamate\SurrealGraphSync\Field\NumberField;

add_filter('surreal_graph_map_user_' . $surreal_user_type, function (MappedData $mapped_data, \WP_User $user): MappedData {
    return $mapped_data
        ->set('username', new StringField($user->user_login))
        ->set('email', new StringField($user->user_email))
        ->set('display_name', new StringField($user->display_name))
        ->set('user_id', new NumberField($user->ID));
}, 10, 2);
```

User-related edges use the same `RelationData` objects as posts:

```php
use Dazamate\SurrealGraphSync\Data\RelationData;
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Field\NumberField;

add_filter('surreal_graph_map_user_related', function (array $mapped_relations, string $surreal_user_type, \WP_User $user): array {
    $mapped_relations[] = new RelationData(
        from_record:    $user->ID,
        to_record:      get_another_user_id(),
        relation_table: 'friends_with',
        data:           (new MappedData())->set('years', new NumberField(15)),
        unique:         true,
    );

    return $mapped_relations;
}, 10, 3);
```

> A numeric WP id in `from_record`/`to_record` is resolved to the matching Surreal record
> id using the relation's context (post, user, or term) after the record is saved. Pass a
> Surreal record id directly to skip resolution.


## Creating relations

To add graph relations, hook this filter, which is called during post save. You receive an
array of `RelationData` objects and return it with your edges appended:

```php
apply_filters('surreal_graph_map_related', RelationData[] $mappings, \WP_Post $post): array;
```

```php
use Dazamate\SurrealGraphSync\Data\RelationData;
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Field\NumberField;
use Dazamate\SurrealGraphSync\Field\ArrayField;

add_filter('surreal_graph_map_related', function (array $mappings, \WP_Post $post): array {
    if ($post->post_type !== 'order') {
        return $mappings;
    }

    $products       = get_relevant_products($post);
    $user_record_id = 'customer:978fd9sdf987df';

    foreach ($products as $product) {
        $mappings[] = new RelationData(
            from_record:    $user_record_id,                                  // required: surreal record id or WP id
            to_record:      get_post_meta($product->ID, 'surreal_id', true),  // required
            relation_table: 'ordered',                                        // required: the edge table
            data:           (new MappedData())                                // optional: fields stored on the edge
                ->set('discount', new NumberField(0.2))
                ->set('coupons', new ArrayField(['SAVE10', 'FREESHIP'], 'array<string>')),
            unique:         false,                                            // optional: allow duplicate edges? (default true)
        );
    }

    return $mappings;
}, 10, 2);
```

## Mapping taxonomy terms

Taxonomy terms sync the same way as posts: a **taxonomy maps to a Surreal table** and each
**term becomes a record** in it. Nothing syncs until you map a taxonomy to a non-empty
table name — that's the opt-in.

```php
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Field\StringField;

// 1. Opt a taxonomy in by giving it a Surreal table name.
add_filter('surreal_map_term_table_name', function (string $table, string $taxonomy, int $term_id): string {
    return $taxonomy === 'category' ? 'category' : $table;
}, 10, 3);

// 2. Add/override fields for that taxonomy (generic fields name/slug/term_id/taxonomy/
//    description/count are pre-seeded for you).
add_filter('surreal_graph_map_term_category', function (MappedData $mapped_data, int $term_id): MappedData {
    return $mapped_data->set('color', new StringField(get_term_meta($term_id, 'color', true) ?: ''));
}, 10, 2);
```

**Hierarchy.** A term with a parent automatically gets a `term → child_of → term` edge (the
parent must already be synced — see best-effort note below). Rename the edge via the
`surreal_graph_term_child_relation_name` filter. Add your own term edges with
`surreal_graph_map_term_related` (`RelationData[]`, `\WP_Term`).

## Auto term associations

Opt a taxonomy into auto-wiring and the plugin will append `post → has_{taxonomy} → term`
edges to each post's relations, derived from WordPress' own term assignments — so you don't
hand-roll them in `surreal_graph_map_related`:

```php
add_filter('surreal_graph_auto_term_relations', function (array $taxonomies): array {
    $taxonomies[] = 'category';
    return $taxonomies;
});
```

The edge name defaults to `has_{taxonomy}` (override via `surreal_graph_term_relation_name`,
which receives the taxonomy and post type).

> **Best-effort.** Auto-derived edges (`child_of` and `has_{taxonomy}`) are only created for
> terms that have already been synced to Surreal. A term referenced before it's synced is
> skipped so the record still saves — re-saving once everything exists wires the edge.
> *Manually* mapped relations stay strict: an unresolved endpoint aborts the sync.


## Atomic sync

A record and all of its relations are written in a **single SurrealDB
transaction** (`BEGIN … COMMIT`). Either the record upsert and every edge commit
together, or nothing is written and the sync error is recorded — you'll never end
up with a record that's missing its edges, or edges pointing at a half-written
record.

Because the whole thing is one transaction, a relation may reference the record
being saved by its WP id **even on a first save**, before that record has a
Surreal id — it's resolved to the record created earlier in the same
transaction. An endpoint that can't be resolved (e.g. a numeric WP id for a post
that has never been synced) aborts the sync before anything is written.

For example, saving a brand-new `order` post that relates the customer to three
products writes the `order` record **and** all three `purchased` edges in one shot.
If the third product has never been synced (its endpoint can't be resolved), the
whole order rolls back — you won't be left with an order that's missing a line item,
or edges dangling off an order that didn't save.


## Migrations (optional — you usually don't need one)

**You normally don't have to define any schema.** SurrealDB is schemaless by default:
the first time the plugin runs `UPSERT recipe:…` (or `person:…`, `order:…`, …) SurrealDB
creates the table and accepts whatever fields you mapped. So mapping a post type with
`surreal_map_post_table_name` and adding fields in `surreal_graph_map_{post_type}` is *all*
you need to start syncing — no migration step.

Migrations exist for the cases where you want to go further than schemaless allows:

- **Locking a table down** to a strict schema with `SCHEMAFULL` — e.g. a `recipe` table where
  every record *must* have a numeric `cook_time_minutes` and no stray fields are permitted.
- **Indexes / constraints** — e.g. a `UNIQUE` index on `product.sku` so two products can't
  share a SKU, or an index on `player.gamertag` to make look-ups fast.
- **Defaults / assertions** — e.g. asserting `review.rating` is between 1 and 5.

> ⚠️ Prefer schemaless unless you have a concrete reason. A `SCHEMAFULL` table rejects any
> field it wasn't told about, so locking down `person` means every *other* plugin that wants
> to attach a field (a CRM add-on adding `loyalty_points`, say) has to ship its own migration
> first. The bundled `InitialMigration` is intentionally a no-op example because of this —
> see `includes/Migration/InitialMigration.php` for commented, copy-paste-ready schema code.

### Registering a migration

Migrations are registered on the `get_surreal_graph_migrations` filter as plain data — each
`up`/`down` is just an ordered list of SurrealQL strings the admin **Settings → Migrations**
page runs for you. For example, to lock down a `recipe` table for a cooking site:

```php
add_filter('get_surreal_graph_migrations', function (array $migrations): array {
    $migrations['cooking_site']['recipe_schema'] = [
        'up' => [
            'DEFINE TABLE IF NOT EXISTS recipe SCHEMAFULL;',
            'DEFINE FIELD IF NOT EXISTS title ON TABLE recipe TYPE string;',
            'DEFINE FIELD IF NOT EXISTS cook_time_minutes ON TABLE recipe TYPE number;',
            'DEFINE INDEX IF NOT EXISTS recipe_title ON TABLE recipe COLUMNS title UNIQUE;',
            // stamp the high-water-mark so the admin page marks this migration as run
            'UPSERT migration:state SET cooking_site.last_migration_name = "recipe_schema", cooking_site.last_migration_time = <datetime>"2025-01-02T00:00:00Z";',
        ],
        'down' => [
            'REMOVE TABLE IF EXISTS recipe;',
            'UPSERT migration:state SET cooking_site = NONE;',
        ],
        'datetime' => '2025-01-02', // used to order migrations within a group
        'name'     => 'recipe_schema',
    ];

    return $migrations;
});
```

The data structure: the outer key is a **migration group** (a label you own, e.g. one per
plugin or feature), and within it each migration is keyed by name. An e-commerce plugin
adding a SKU index later would append a *second* migration to its own group rather than
editing the first:

```php
// Array keys are the migration group
[
    'cooking_site' => [
        'recipe_schema' => [
            'up'       => [ 'DEFINE TABLE IF NOT EXISTS recipe SCHEMAFULL;', /* … */ ],
            'down'     => [ 'REMOVE TABLE IF EXISTS recipe;', /* … */ ],
            'datetime' => '2025-01-02', // used to order migrations
            'name'     => 'recipe_schema',
        ],
        // ... later migrations that alter the recipe schema go here, newer datetime
    ],
    'ecommerce' => [
        'product_sku_index' => [
            'up'       => [ 'DEFINE INDEX IF NOT EXISTS product_sku ON TABLE product COLUMNS sku UNIQUE;', /* … */ ],
            'down'     => [ 'REMOVE INDEX IF EXISTS product_sku ON TABLE product;', /* … */ ],
            'datetime' => '2025-01-03', // used to order migrations
            'name'     => 'product_sku_index',
        ],
    ],
];
```

## Development

Building an installable release and running the test/static-analysis tooling are covered in
[docs/development.md](docs/development.md).