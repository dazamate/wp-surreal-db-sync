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
    public static function build_sync_transaction(
        string $table,
        string $where_clause,
        MappedData $entity_data,
        array $relation_queries
    ): string {
        $content = self::build_object_str($entity_data);

        $q  = 'BEGIN TRANSACTION;';
        $q .= sprintf('LET $rec_res = (UPSERT %s CONTENT %s WHERE %s RETURN id);', $table, $content, $where_clause);
        $q .= 'LET $rec = $rec_res[0].id;';

        foreach ($relation_queries as $relation_query) {
            $q .= $relation_query;
        }

        $q .= 'RETURN $rec;';
        $q .= 'COMMIT TRANSACTION;';

        return $q;
    }

    static public function build_relate_query(
        string $from_record_id,
        string $to_record_id,
        string $relation_table_name,
        MappedData $data,
        bool $unique = true // only one link allowed
    ) {
        // Generates a relation query like this:
        //
        //   LET $rel_id = (SELECT id FROM created_by where in = ($person.id) and out = ($recipe.id)).id[0];
        //   IF $rel_id is NONE THEN {
        //     LET $new_rel_id = (RELATE ($person.id)->created_by->($recipe.id));
        //     UPDATE $new_rel_id CONTENT { units: 'created' };
        //   } else {
        //     UPDATE $rel_id CONTENT { units: 'updated' };
        //   } END;
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
            'LET $rel_id = (SELECT id FROM %s where in = %s and out = %s).id[0];',
            $relation_table_name,
            $from_record_id,
            $to_record_id
        );

        $q .= 'IF $rel_id is NONE THEN {';
        $q .= sprintf(
            '$rel_id = (RELATE (%s)->%s->(%s));',
            $from_record_id,
            $relation_table_name,
            $to_record_id
        );

        $q .= sprintf(
            'UPDATE $rel_id CONTENT %s;',
            $data_obj_str
        );

        $q .= '} else {';

        $q .= sprintf(
            'UPDATE $rel_id CONTENT %s;',
            $data_obj_str
        );

        $q .= '} END;';

        return $q;
    }
}
