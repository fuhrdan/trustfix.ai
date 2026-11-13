<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class LegacyRoleCompatibilityTest extends TestCase
{
    private const CONNECTION = 'legacy_role_compatibility_memory';

    protected function tearDown(): void
    {
        DB::purge(self::CONNECTION);

        parent::tearDown();
    }

    public function test_primary_role_remains_authoritative_when_assignment_table_is_absent(): void
    {
        $this->createLegacySchema();
        DB::connection(self::CONNECTION)->table('users')->insert([
            'id' => 1,
            'role' => 'admin',
        ]);

        $user = $this->legacyUserQuery()->findOrFail(1);

        self::assertSame(['admin'], $user->roleNames());
        self::assertSame(['admin'], $user->roles);
        self::assertTrue($user->hasRole('admin'));
        self::assertTrue($user->hasAnyRole(['customer', 'admin']));
        self::assertFalse($user->hasRole('customer'));
        self::assertSame(1, $this->legacyUserQuery()->withRole('admin')->count());
        self::assertSame(0, $this->legacyUserQuery()->withRole('customer')->count());
    }

    public function test_user_creation_does_not_require_assignment_table(): void
    {
        $this->createLegacySchema();

        $user = (new User)->setConnection(self::CONNECTION);
        $user->timestamps = false;
        $user->forceFill(['role' => 'customer'])->save();

        self::assertNotNull($user->getKey());
        self::assertSame(['customer'], $user->roleNames());
        self::assertSame(1, DB::connection(self::CONNECTION)->table('users')->count());
    }

    public function test_roleless_legacy_fixture_serializes_empty_roles(): void
    {
        $this->createLegacySchema(false);
        DB::connection(self::CONNECTION)->table('users')->insert(['id' => 1]);

        $user = $this->legacyUserQuery()->findOrFail(1);

        self::assertSame([], $user->roleNames());
        self::assertSame([], $user->roles);
        self::assertSame([], $user->toArray()['roles']);
        self::assertFalse($user->hasRole('admin'));
    }

    public function test_additional_role_is_not_silently_lost_without_assignment_table(): void
    {
        $this->createLegacySchema();
        DB::connection(self::CONNECTION)->table('users')->insert([
            'id' => 1,
            'role' => 'customer',
        ]);

        $user = $this->legacyUserQuery()->findOrFail(1);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('user_roles table');

        $user->grantRole('handyman');
    }

    private function createLegacySchema(bool $includeRole = true): void
    {
        config(['database.connections.'.self::CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        Schema::connection(self::CONNECTION)->create('users', function (Blueprint $table) use ($includeRole): void {
            $table->id();

            if ($includeRole) {
                $table->string('role');
            }
        });

        self::assertFalse(
            Schema::connection(self::CONNECTION)->hasTable('user_roles')
        );
    }

    private function legacyUserQuery(): Builder
    {
        return (new User)
            ->setConnection(self::CONNECTION)
            ->newQuery();
    }
}
