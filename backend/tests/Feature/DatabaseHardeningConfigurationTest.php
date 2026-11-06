<?php

namespace Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DatabaseHardeningConfigurationTest extends TestCase
{
    public function test_mysql_connections_default_new_tables_to_innodb(): void
    {
        $this->assertSame('InnoDB', config('database.connections.mysql.engine'));
        $this->assertSame('InnoDB', config('database.connections.mariadb.engine'));
    }

    public function test_integrity_manifest_is_safe_unique_and_contains_critical_relationships(): void
    {
        $relations = config('database_integrity.relations');

        $this->assertIsArray($relations);
        $this->assertGreaterThan(60, count($relations));

        $keys = [];
        foreach ($relations as $relation) {
            foreach (['table', 'column', 'referenced_table', 'referenced_column'] as $key) {
                $this->assertMatchesRegularExpression(
                    '/^[A-Za-z0-9_]+$/',
                    (string) $relation[$key]
                );
            }

            $this->assertContains($relation['on_delete'], ['CASCADE', 'SET NULL', 'RESTRICT']);
            $keys[] = $relation['table'].'.'.$relation['column'];
        }

        $this->assertSame($keys, array_values(array_unique($keys)));
        $this->assertTrue(collect($relations)->contains(
            fn (array $relation): bool => $relation['table'] === 'job_images'
                && $relation['column'] === 'job_id'
                && $relation['referenced_table'] === 'jobs'
                && $relation['referenced_column'] === 'id'
                && $relation['on_delete'] === 'CASCADE'
        ));
    }

    public function test_hardening_command_refuses_the_sqlite_test_database(): void
    {
        $exitCode = Artisan::call('trustfix:harden-database');

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString(
            'supports only MySQL and MariaDB',
            Artisan::output()
        );
    }
}
