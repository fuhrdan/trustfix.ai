<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacySchemaRepairMigrationTest extends TestCase
{
    public function test_legacy_table_shapes_are_repaired_idempotently(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('role')->default('customer');
            $table->unsignedBigInteger('company_id')->nullable();
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('handyman_id');
            $table->string('file_path');
            $table->string('type');
            $table->boolean('verified')->default(false);
            $table->timestamps();
        });

        Schema::create('handyman_skills', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('handyman_id');
            $table->unsignedBigInteger('skills_id');
            $table->unsignedBigInteger('proficiency_level');
            $table->timestamps();
        });

        DB::table('documents')->insert([
            [
                'handyman_id' => 1,
                'file_path' => 'contractor-documents/pending.pdf',
                'type' => 'insurance',
                'verified' => false,
            ],
            [
                'handyman_id' => 2,
                'file_path' => 'contractor-documents/verified.pdf',
                'type' => 'license',
                'verified' => true,
            ],
        ]);

        $migration = require database_path(
            'migrations/2026_11_06_000600_repair_legacy_relational_schema.php'
        );

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumns('users', [
            'account_status',
            'suspended_at',
            'suspended_by',
            'suspension_reason',
        ]));
        $this->assertTrue(Schema::hasColumns('documents', [
            'status',
            'reviewed_by',
            'reviewed_at',
            'admin_notes',
        ]));
        $this->assertTrue(Schema::hasColumn('handyman_skills', 'skill_id'));
        $this->assertFalse(Schema::hasColumn('handyman_skills', 'skills_id'));

        $this->assertSame(
            'pending',
            DB::table('documents')->where('verified', false)->value('status')
        );
        $this->assertSame(
            'approved',
            DB::table('documents')->where('verified', true)->value('status')
        );

        $this->assertTrue(Schema::hasIndex('users', 'users_role_index'));
        $this->assertTrue(Schema::hasIndex('users', 'users_company_id_index'));
        $this->assertTrue(Schema::hasIndex('users', 'users_account_status_index'));
        $this->assertTrue(Schema::hasIndex('users', 'users_suspended_by_index'));
        $this->assertTrue(Schema::hasIndex(
            'documents',
            'documents_handyman_id_type_index'
        ));
        $this->assertTrue(Schema::hasIndex('documents', 'documents_status_index'));
        $this->assertTrue(Schema::hasIndex('documents', 'documents_verified_index'));
        $this->assertTrue(Schema::hasIndex('documents', 'documents_reviewed_by_index'));
        $this->assertTrue(Schema::hasIndex(
            'handyman_skills',
            'handyman_skills_handyman_id_skill_id_unique'
        ));
    }
}
