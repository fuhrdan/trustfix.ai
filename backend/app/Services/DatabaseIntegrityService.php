<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DatabaseIntegrityService
{
    public function supportsCurrentConnection(): bool
    {
        return in_array($this->connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function databaseName(): string
    {
        $row = $this->connection()->selectOne('SELECT DATABASE() AS database_name');
        $name = trim((string) ($row->database_name ?? ''));

        if ($name === '') {
            throw new RuntimeException('The active database name could not be determined.');
        }

        return $name;
    }

    public function hasNonInnoDbTables(): bool
    {
        $row = $this->connection()->selectOne(
            'SELECT COUNT(*) AS aggregate
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_TYPE = ?
                AND UPPER(COALESCE(ENGINE, ?)) <> ?',
            ['BASE TABLE', '', 'INNODB']
        );

        return (int) ($row->aggregate ?? 0) > 0;
    }

    /** @return array<string, mixed> */
    public function audit(): array
    {
        if (!$this->supportsCurrentConnection()) {
            throw new RuntimeException(
                'Database hardening supports only MySQL and MariaDB connections.'
            );
        }

        $connection = $this->connection();
        $server = $connection->selectOne(
            'SELECT VERSION() AS database_version,
                    @@default_storage_engine AS default_storage_engine'
        );
        $tables = $connection->select(
            'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_TYPE = ?
              ORDER BY TABLE_NAME',
            ['BASE TABLE']
        );
        $columns = $connection->select(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLLATION_NAME
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()'
        );
        $foreignKeys = $connection->select(
            'SELECT k.TABLE_NAME AS child_table,
                    k.CONSTRAINT_NAME AS constraint_name,
                    k.COLUMN_NAME AS child_column,
                    k.REFERENCED_TABLE_NAME AS referenced_table,
                    k.REFERENCED_COLUMN_NAME AS referenced_column,
                    r.UPDATE_RULE AS update_rule,
                    r.DELETE_RULE AS delete_rule
               FROM information_schema.KEY_COLUMN_USAGE k
          LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                 ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
              WHERE k.TABLE_SCHEMA = DATABASE()
                AND k.REFERENCED_TABLE_NAME IS NOT NULL'
        );

        $tableMap = [];
        foreach ($tables as $table) {
            $tableMap[(string) $table->TABLE_NAME] = $table;
        }

        $columnMap = [];
        foreach ($columns as $column) {
            $columnMap[$this->columnKey(
                (string) $column->TABLE_NAME,
                (string) $column->COLUMN_NAME
            )] = $column;
        }

        $relationResults = [];
        foreach ($this->relations() as $relation) {
            $relationResults[] = $this->auditRelation(
                $relation,
                $tableMap,
                $columnMap,
                $foreignKeys
            );
        }

        return [
            'database' => $this->databaseName(),
            'server' => [
                'version' => (string) ($server->database_version ?? ''),
                'default_storage_engine' => (string) ($server->default_storage_engine ?? ''),
            ],
            'tables' => array_map(static fn ($table): array => [
                'name' => (string) $table->TABLE_NAME,
                'engine' => (string) $table->ENGINE,
                'collation' => (string) $table->TABLE_COLLATION,
            ], $tables),
            'relations' => $relationResults,
        ];
    }

    /** @return list<string> */
    public function convertAllTablesToInnoDb(): array
    {
        $audit = $this->audit();
        $converted = [];

        $this->connection()->statement('SET SESSION default_storage_engine = InnoDB');

        foreach ($audit['tables'] as $table) {
            if (strcasecmp((string) $table['engine'], 'InnoDB') === 0) {
                continue;
            }

            $name = (string) $table['name'];
            $this->connection()->statement(
                'ALTER TABLE '.$this->quoteIdentifier($name).' ENGINE = InnoDB'
            );
            $converted[] = $name;
        }

        return $converted;
    }

    /** @return list<string> */
    public function addMissingForeignKeys(): array
    {
        $audit = $this->audit();
        $nonInnoDb = array_filter(
            $audit['tables'],
            static fn (array $table): bool => strcasecmp((string) $table['engine'], 'InnoDB') !== 0
        );

        if ($nonInnoDb) {
            throw new RuntimeException('Every base table must use InnoDB before foreign keys are added.');
        }

        foreach ($audit['relations'] as $relation) {
            if ($relation['status'] === 'optional_absent') {
                continue;
            }

            if ($relation['status'] !== 'ready') {
                throw new RuntimeException(
                    'Schema preflight failed for '.$relation['label'].': '.$relation['status'].'.'
                );
            }

            if (!$relation['types_compatible']) {
                throw new RuntimeException(
                    'Column types are incompatible for '.$relation['label'].'.'
                );
            }

            if (!$relation['nullability_compatible']) {
                throw new RuntimeException(
                    'SET NULL requires a nullable child column for '.$relation['label'].'.'
                );
            }

            if ((int) $relation['orphan_count'] > 0) {
                throw new RuntimeException(
                    $relation['label'].' has '.$relation['orphan_count'].' orphaned row(s).'
                );
            }

            if ($relation['constraint_status'] === 'mismatch') {
                throw new RuntimeException(
                    'An incompatible foreign key already exists for '.$relation['label'].'.'
                );
            }
        }

        $added = [];
        foreach ($audit['relations'] as $relation) {
            if (
                $relation['status'] !== 'ready'
                || $relation['constraint_status'] === 'correct'
            ) {
                continue;
            }

            $constraint = $this->constraintName(
                (string) $relation['table'],
                (string) $relation['column']
            );
            $sql = sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE RESTRICT',
                $this->quoteIdentifier((string) $relation['table']),
                $this->quoteIdentifier($constraint),
                $this->quoteIdentifier((string) $relation['column']),
                $this->quoteIdentifier((string) $relation['referenced_table']),
                $this->quoteIdentifier((string) $relation['referenced_column']),
                (string) $relation['on_delete']
            );

            $this->connection()->statement($sql);
            $added[] = $constraint;
        }

        return $added;
    }

    /** @return list<array<string, mixed>> */
    private function relations(): array
    {
        $relations = config('database_integrity.relations', []);

        if (!is_array($relations)) {
            throw new RuntimeException('The database integrity relation manifest is invalid.');
        }

        return array_values($relations);
    }

    /**
     * @param array<string, mixed> $relation
     * @param array<string, object> $tableMap
     * @param array<string, object> $columnMap
     * @param array<int, object> $foreignKeys
     * @return array<string, mixed>
     */
    private function auditRelation(
        array $relation,
        array $tableMap,
        array $columnMap,
        array $foreignKeys
    ): array {
        $table = (string) $relation['table'];
        $column = (string) $relation['column'];
        $referencedTable = (string) $relation['referenced_table'];
        $referencedColumn = (string) $relation['referenced_column'];
        $onDelete = strtoupper((string) $relation['on_delete']);
        $optional = (bool) ($relation['optional'] ?? false);
        $label = $table.'.'.$column.' -> '.$referencedTable.'.'.$referencedColumn;
        $child = $columnMap[$this->columnKey($table, $column)] ?? null;
        $parent = $columnMap[$this->columnKey($referencedTable, $referencedColumn)] ?? null;
        $status = 'ready';

        if (!isset($tableMap[$table]) || !isset($tableMap[$referencedTable])) {
            $status = 'missing_table';
        } elseif ($child === null && $optional) {
            $status = 'optional_absent';
        } elseif ($child === null || $parent === null) {
            $status = 'missing_column';
        }

        $typesCompatible = false;
        $nullabilityCompatible = false;
        $orphanCount = null;
        $constraintStatus = 'not_applicable';

        if ($status === 'ready') {
            $typesCompatible = $this->typesCompatible($child, $parent);
            $nullabilityCompatible = $onDelete !== 'SET NULL'
                || strtoupper((string) $child->IS_NULLABLE) === 'YES';
            $orphanCount = $this->orphanCount(
                $table,
                $column,
                $referencedTable,
                $referencedColumn
            );
            $constraintStatus = $this->constraintStatus(
                $table,
                $column,
                $referencedTable,
                $referencedColumn,
                $onDelete,
                $foreignKeys
            );
        }

        return [
            'label' => $label,
            'table' => $table,
            'column' => $column,
            'referenced_table' => $referencedTable,
            'referenced_column' => $referencedColumn,
            'on_delete' => $onDelete,
            'optional' => $optional,
            'status' => $status,
            'types_compatible' => $typesCompatible,
            'nullability_compatible' => $nullabilityCompatible,
            'orphan_count' => $orphanCount,
            'constraint_status' => $constraintStatus,
            'child_type' => $child?->COLUMN_TYPE,
            'parent_type' => $parent?->COLUMN_TYPE,
        ];
    }

    private function typesCompatible(object $child, object $parent): bool
    {
        if (strtolower((string) $child->COLUMN_TYPE) !== strtolower((string) $parent->COLUMN_TYPE)) {
            return false;
        }

        $childCollation = strtolower((string) ($child->COLLATION_NAME ?? ''));
        $parentCollation = strtolower((string) ($parent->COLLATION_NAME ?? ''));

        return $childCollation === $parentCollation;
    }

    private function orphanCount(
        string $table,
        string $column,
        string $referencedTable,
        string $referencedColumn
    ): int {
        $sql = sprintf(
            'SELECT COUNT(*) AS aggregate FROM %s child_row LEFT JOIN %s parent_row ON child_row.%s = parent_row.%s WHERE child_row.%s IS NOT NULL AND parent_row.%s IS NULL',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($referencedTable),
            $this->quoteIdentifier($column),
            $this->quoteIdentifier($referencedColumn),
            $this->quoteIdentifier($column),
            $this->quoteIdentifier($referencedColumn)
        );
        $row = $this->connection()->selectOne($sql);

        return (int) ($row->aggregate ?? 0);
    }

    /** @param array<int, object> $foreignKeys */
    private function constraintStatus(
        string $table,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        string $onDelete,
        array $foreignKeys
    ): string {
        $foundForColumn = false;

        foreach ($foreignKeys as $foreignKey) {
            if (
                (string) $foreignKey->child_table !== $table
                || (string) $foreignKey->child_column !== $column
            ) {
                continue;
            }

            $foundForColumn = true;
            if (
                (string) $foreignKey->referenced_table === $referencedTable
                && (string) $foreignKey->referenced_column === $referencedColumn
                && strtoupper((string) $foreignKey->delete_rule) === $onDelete
                && strtoupper((string) $foreignKey->update_rule) === 'RESTRICT'
            ) {
                return 'correct';
            }
        }

        return $foundForColumn ? 'mismatch' : 'missing';
    }

    private function constraintName(string $table, string $column): string
    {
        $base = 'tfk_'.$table.'_'.$column;

        if (strlen($base) <= 64) {
            return $base;
        }

        return substr($base, 0, 50).'_'.substr(hash('sha256', $base), 0, 13);
    }

    private function columnKey(string $table, string $column): string
    {
        return $table.'.'.$column;
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new RuntimeException('Unsafe database identifier: '.$identifier);
        }

        return '`'.$identifier.'`';
    }

    private function connection(): Connection
    {
        return DB::connection();
    }
}
