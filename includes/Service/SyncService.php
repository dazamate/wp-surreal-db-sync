<?php

namespace Dazamate\SurrealGraphSync\Service;

use Dazamate\SurrealGraphSync\Query\QueryBuilder;
use Dazamate\SurrealGraphSync\Data\MappedData;
use Dazamate\SurrealGraphSync\Data\RelationData;
use Dazamate\SurrealGraphSync\Dto\Reference\WordPressId;
use Dazamate\SurrealGraphSync\Utils\Inputs;
use Dazamate\SurrealGraphSync\Utils\FieldValidator;
use Dazamate\SurrealGraphSync\Utils\RelationDataHelper;
use Dazamate\SurrealGraphSync\Enum\QueryType;

class SyncService {
    const SURREAL_SYNC_ERROR_META_KEY = 'surreal_sync_error';

    public static function load_hooks() {
        add_action('surreal_delete_record', [__CLASS__, 'delete_record']);
    }

    protected static function get_surreal_db_conn(array &$errors): ?\Surreal\Surreal {
        $db = apply_filters('get_surreal_db_conn', null);

        if ( ! ( $db instanceof \Surreal\Surreal ) ) {
            $errors[] = 'Surreal sync error: Unable to establish database connection';
            error_log('Unable to get Surreal DB connection ' . __FUNCTION__ . ' ' . __LINE__);
            return NULL;
        }

        return $db;
    }

    protected static function try_get_record_id_from_response(array $res): ?string {
        if ($res[0][0]['id'] instanceof \Surreal\Cbor\Types\Record\RecordId) {
            return $res[0][0]['id']->toString();
        }

        return $res[0][0]['id'] ?? null;
    }

    // Upsert the entity record and all of its relation edges as one atomic SurrealDB
    // transaction — either everything commits or nothing is written. $subject is the WP
    // record being saved; its id and type drive the WHERE match and the resolution of any
    // relation endpoint that points back at this record (rendered as the `$rec` variable).
    protected static function persist_entity(
        \Surreal\Surreal $db,
        string $table,
        MappedData $entity_data,
        array $raw_related_mappings,
        WordPressId $subject,
        array &$errors
    ): ?string {
        $query_type = $subject->type;
        $self_wp_id = $subject->id;
        $where_clause = sprintf('%s_id = %d', $query_type->value, $self_wp_id);

        $resolved_relations = self::validate_relation_mapping($raw_related_mappings, $query_type, $errors, $self_wp_id);

        if ($resolved_relations === false) {
            return null;
        }

        $relation_queries = array_map(
            static fn(RelationData $relation): string => QueryBuilder::build_relate_query(
                (string) $relation->from_record,
                (string) $relation->to_record,
                $relation->relation_table,
                $relation->data ?? new MappedData(),
                $relation->unique
            ),
            $resolved_relations
        );

        $q = QueryBuilder::build_sync_transaction($table, $where_clause, $entity_data, $relation_queries);

        try {
            $res = $db->queryRaw($q);
        } catch (\Throwable $e) {
            $errors[] = sprintf('Surreal query error: %s', $e->getMessage());
            return null;
        }

        $statement_errors = self::collect_statement_errors($res);

        if (!empty($statement_errors)) {
            foreach ($statement_errors as $statement_error) {
                $errors[] = sprintf('Surreal transaction error: %s', $statement_error);
            }

            return null;
        }

        $surreal_id = self::record_id_from_transaction($res);

        if (empty($surreal_id)) {
            $errors[] = 'Surreal sync error: Did not get a record ID from surreal';
            return null;
        }

        return $surreal_id;
    }

    // SurrealDB doesn't throw on a statement-level error inside a transaction; it marks
    // every statement 'ERR' and rolls back. We surface the specific failing message(s)
    // and fall back to the generic "failed transaction" message only if that's all there is.
    protected static function collect_statement_errors(?array $res): array {
        if (!is_array($res)) {
            return ['Empty response from Surreal'];
        }

        $generic = 'The query was not executed due to a failed transaction';
        $specific = [];
        $had_error = false;

        foreach ($res as $statement) {
            if (($statement['status'] ?? 'OK') === 'OK') {
                continue;
            }

            $had_error = true;
            $message = is_string($statement['result'] ?? null) ? $statement['result'] : 'Unknown error';

            if ($message !== $generic) {
                $specific[] = $message;
            }
        }

        if (!empty($specific)) {
            return $specific;
        }

        return $had_error ? [$generic] : [];
    }

    // The transaction ends with `RETURN $rec`, so the response collapses to a single
    // result holding the record id.
    protected static function record_id_from_transaction(?array $res): ?string {
        $result = $res[0]['result'] ?? null;

        if ($result instanceof \Surreal\Cbor\Types\Record\RecordId) {
            return $result->toString();
        }

        if (is_string($result) && $result !== '') {
            return $result;
        }

        return null;
    }

    public static function validate(MappedData $mapped_entity_data, QueryType $query_type, array &$errors): bool {
        $field_errors = [];

        if (!FieldValidator::validate_mapped($mapped_entity_data, $field_errors)) {
            foreach ($field_errors as $e) {
                $errors[] = sprintf("Surreal DB mapping error: %s", $e);
            }

            return false;
        }

        return true;
    }

    // Validate every relation (input comes from a filter, so types are checked at runtime),
    // then resolve its endpoints to Surreal record ids. When $self_wp_id is given, an endpoint
    // matching it resolves to the in-transaction `$rec` so a record can relate to itself before
    // its id has been persisted. Returns resolved RelationData, or false on the first failure.
    public static function validate_relation_mapping(array $related_data_mappings, QueryType $query_type, array &$errors, ?int $self_wp_id = null): array|false {
        $resolved = [];

        foreach ($related_data_mappings as $relation) {
            if (!($relation instanceof RelationData)) {
                $errors[] = 'Related data mapping must be a RelationData instance.';
                return false;
            }

            $relation_errors = [];

            if (!RelationDataHelper::validate($relation, $relation_errors)) {
                foreach ($relation_errors as $e) {
                    $errors[] = sprintf("Surreal DB '%s' related data mapping error: %s", $relation->relation_table, $e);
                }

                return false;
            }

            // Convert any WP ids in the 'from'/'to' endpoints to the related
            // record's Surreal id. A Surreal record id is passed through as-is,
            // and a reference to the record being saved becomes the `$rec`
            // transaction variable.
            $from_record = self::resolve_endpoint((string) $relation->from_record, $query_type, $self_wp_id);

            if (empty($from_record)) {
                $errors[] = self::endpoint_error('from_record', $relation);
                return false;
            }

            $to_record = self::resolve_endpoint((string) $relation->to_record, $query_type, $self_wp_id);

            if (empty($to_record)) {
                $errors[] = self::endpoint_error('to_record', $relation);
                return false;
            }

            $resolved[] = new RelationData(
                $from_record,
                $to_record,
                $relation->relation_table,
                $relation->data,
                $relation->unique
            );
        }

        return $resolved;
    }

    private static function endpoint_error(string $endpoint, RelationData $relation): string {
        $value = $endpoint === 'from_record' ? $relation->from_record : $relation->to_record;
        $label = $endpoint === 'from_record' ? 'from' : 'to';

        if (ctype_digit((string) $value)) {
            return sprintf(
                "Couldn't get '%s' surreal record id: %s for '%s' relation. It may need re-saving",
                $label,
                $value,
                $relation->relation_table
            );
        }

        return sprintf(
            "Couldn't parse surreal record '%s' when trying to map '%s' for '%s' relation.",
            $value,
            $endpoint,
            $relation->relation_table
        );
    }

    private static function resolve_endpoint(string $value, QueryType $query_type, ?int $self_wp_id): ?string {
        // The record being upserted in this same transaction. Its id isn't known
        // until the upsert runs, so reference the transaction's $rec variable.
        if ($self_wp_id !== null && ctype_digit($value) && (int) $value === $self_wp_id) {
            return '$rec';
        }

        return Inputs::parse_record_id($value, $query_type);
    }

    public static function delete_record(string $surreal_record_id): bool {
        // Extra checks so we dont accidently run a DELETE all command when there is a missing record argument
        if (
            strlen($surreal_record_id) < 1 ||               // Make sure string is more than 1 char
            strpos($surreal_record_id, ':') === false       // Make sure it has the record delimiter
        ) return false;

        $q = "DELETE $surreal_record_id";

        $db = apply_filters('get_surreal_db_conn', null);

        if ( ! ( $db instanceof \Surreal\Surreal ) ) {
            error_log('Unable to get Surreal DB connection ' . __FUNCTION__ . ' ' . __LINE__);
            return false;
        }

        $res = $db->query($q);

        return true;
    }
}
