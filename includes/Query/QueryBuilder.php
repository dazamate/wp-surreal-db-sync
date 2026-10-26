<?php

namespace Dazamate\SurrealGraphSync\Query;

use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Utils\FieldRenderer;

class QueryBuilder {
    static public function build_create_field_query(string $table, string $key, array $rules, bool $if_not_exists = false): string {
        $q = sprintf('DEFINE FIELD %s%s ON TABLE %s TYPE %s',
            $if_not_exists ? 'IF NOT EXISTS ' : '',
            $key,
            $table,
            $rules['type']
        );

        if (is_string($rules['default'])) {
            $q .= sprintf(" DEFAULT '%s'", $rules['default']);
        } else if (is_numeric($rules['default']) && $rules['default'] === 0) {
            if ($rules['type'] === 'datetime' ) {
                $q .= ' DEFAULT d"1900-01-01"'; // Default to a 0 date
            } else {
                $q .= ' DEFAULT 0';
            }
        } else if (is_array($rules['default'])) {
            $arr = $rules['default'];

            if (count($arr) < 1) {
                $q .= ' DEFAULT []';
            } else {
                $q .= sprintf(" DEFAULT [%s]", implode(',', $arr));
            }
        } else if (!empty($rules['default'])) {
            $q .= ' DEFAULT ' . $rules['default'];
        }

        if (!empty($rules['assert'])) {
            $q .= ' ASSERT ' . $rules['assert'];
        }

        $q .= ';';

        return $q;
    }

    static public function build_create_field_query_on_array(string $table, array $params, bool $if_not_exists = false): string {
        $output = [];

        foreach ($params as $key => $rules) {
            $output[] = self::build_create_field_query($table, $key, $rules, $if_not_exists);
        }

        return implode(' ', $output);
    }

    public static function build_object_str(MappedData $object_data): string {
        return FieldRenderer::to_object_string($object_data);
    }

    // Builds a single atomic transaction that upserts the entity record and creates all
    // of its relation edges. The upserted record's id is captured in `$rec` so relation
    // statements can reference the record being saved before its id is known; ending with
    // `RETURN $rec;` collapses the response to the record id on success.
    //
    // $owned_relations names relation tables whose edges out of this record are rebuilt on
    // every save: they're deleted before the relation queries run, so an edge the mapping no
    // longer produces (a removed recipe line, an unticked term) doesn't linger. Names must
    // already be validated with is_valid_table_name().
    public static function build_sync_transaction(
        string $table,
        string $where_clause,
        MappedData $entity_data,
        array $relation_queries,
        array $owned_relations = []
    ): string {
        $content = self::build_object_str($entity_data);

        $q  = 'BEGIN TRANSACTION;';
        $q .= sprintf('LET $rec_res = (UPSERT %s CONTENT %s WHERE %s RETURN id);', $table, $content, $where_clause);
        $q .= 'LET $rec = $rec_res[0].id;';

        foreach (array_unique($owned_relations) as $relation_table) {
            // v3 errors on a missing table, so define it before deleting from it.
            $q .= sprintf('DEFINE TABLE IF NOT EXISTS %s TYPE ANY SCHEMALESS;', $relation_table);
            $q .= sprintf('DELETE %s WHERE in = $rec;', $relation_table);
        }

        foreach ($relation_queries as $relation_query) {
            $q .= $relation_query;
        }

        $q .= 'RETURN $rec;';
        $q .= 'COMMIT TRANSACTION;';

        return $q;
    }

    // Table names are interpolated into queries, so only plain identifiers are accepted.
    public static function is_valid_table_name(string $name): bool {
        return preg_match('/^[a-z_][a-z0-9_]*$/i', $name) === 1;
    }

    static public function build_relate_query(
        string $from_record_id,
        string $to_record_id,
        string $relation_table_name,
        MappedData $data,
        bool $unique = true // only one link allowed
    ) {
        // Generates a relation query like this (SurrealDB v3):
        //
        //   DEFINE TABLE IF NOT EXISTS created_by TYPE ANY SCHEMALESS;
        //   LET $rel_id = (SELECT VALUE id FROM created_by WHERE in = $person AND out = $recipe)[0];
        //   IF $rel_id is NONE THEN {
        //     LET $rel_id = (RELATE ($person)->created_by->($recipe))[0].id;
        //     UPDATE $rel_id CONTENT { units: 'created' };
        //   } ELSE {
        //     UPDATE $rel_id CONTENT { units: 'updated' };
        //   } END;
        //
        // v3 notes: bare `$x = ...` is rejected (needs LET); `(SELECT id ...).id[0]`
        // no longer yields the record id (use SELECT VALUE id + [0]); RELATE
        // returns an array so take [0].id; missing tables error on SELECT, so
        // DEFINE TABLE first.
        $data_obj_str = self::build_object_str($data);

        if (!$unique) {
            return sprintf(
                'RELATE (%s)->%s->(%s) CONTENT %s RETURN id;',
                $from_record_id,
                $relation_table_name,
                $to_record_id,
                $data_obj_str
            );
        }

        $q = sprintf(
            'DEFINE TABLE IF NOT EXISTS %s TYPE ANY SCHEMALESS;',
            $relation_table_name
        );

        $q .= sprintf(
            'LET $rel_id = (SELECT VALUE id FROM %s WHERE in = %s AND out = %s)[0];',
            $relation_table_name,
            $from_record_id,
            $to_record_id
        );

        $q .= 'IF $rel_id is NONE THEN {';
        $q .= sprintf(
            'LET $rel_id = (RELATE (%s)->%s->(%s))[0].id;',
            $from_record_id,
            $relation_table_name,
            $to_record_id
        );

        $q .= sprintf(
            'UPDATE $rel_id CONTENT %s;',
            $data_obj_str
        );

        $q .= '} ELSE {';

        $q .= sprintf(
            'UPDATE $rel_id CONTENT %s;',
            $data_obj_str
        );

        $q .= '} END;';

        return $q;
    }
}
