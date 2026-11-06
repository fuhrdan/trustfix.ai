<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->repairUsers();
        $this->repairDocuments();
        $this->repairHandymanSkills();
    }

    public function down(): void
    {
        // This is a forward-only compatibility repair. Dropping these columns
        // on rollback would discard moderation and document-review history.
    }

    private function repairUsers(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        $missingAccountStatus = !Schema::hasColumn('users', 'account_status');
        $missingSuspendedAt = !Schema::hasColumn('users', 'suspended_at');
        $missingSuspendedBy = !Schema::hasColumn('users', 'suspended_by');
        $missingSuspensionReason = !Schema::hasColumn('users', 'suspension_reason');

        if (
            $missingAccountStatus
            || $missingSuspendedAt
            || $missingSuspendedBy
            || $missingSuspensionReason
        ) {
            Schema::table('users', function (Blueprint $table) use (
                $missingAccountStatus,
                $missingSuspendedAt,
                $missingSuspendedBy,
                $missingSuspensionReason
            ): void {
                if ($missingAccountStatus) {
                    $table->enum('account_status', ['active', 'suspended', 'banned'])
                        ->default('active');
                }

                if ($missingSuspendedAt) {
                    $table->timestamp('suspended_at')->nullable();
                }

                if ($missingSuspendedBy) {
                    $table->unsignedBigInteger('suspended_by')->nullable();
                }

                if ($missingSuspensionReason) {
                    $table->text('suspension_reason')->nullable();
                }
            });
        }

        $this->addIndexIfMissing('users', ['role'], 'users_role_index');
        $this->addIndexIfMissing('users', ['company_id'], 'users_company_id_index');
        $this->addIndexIfMissing('users', ['account_status'], 'users_account_status_index');
        $this->addIndexIfMissing('users', ['suspended_by'], 'users_suspended_by_index');
    }

    private function repairDocuments(): void
    {
        if (!Schema::hasTable('documents')) {
            return;
        }

        $missingStatus = !Schema::hasColumn('documents', 'status');
        $missingReviewedBy = !Schema::hasColumn('documents', 'reviewed_by');
        $missingReviewedAt = !Schema::hasColumn('documents', 'reviewed_at');
        $missingAdminNotes = !Schema::hasColumn('documents', 'admin_notes');

        if ($missingStatus || $missingReviewedBy || $missingReviewedAt || $missingAdminNotes) {
            Schema::table('documents', function (Blueprint $table) use (
                $missingStatus,
                $missingReviewedBy,
                $missingReviewedAt,
                $missingAdminNotes
            ): void {
                if ($missingStatus) {
                    $table->enum('status', ['pending', 'approved', 'rejected'])
                        ->default('pending');
                }

                if ($missingReviewedBy) {
                    $table->unsignedBigInteger('reviewed_by')->nullable();
                }

                if ($missingReviewedAt) {
                    $table->timestamp('reviewed_at')->nullable();
                }

                if ($missingAdminNotes) {
                    $table->text('admin_notes')->nullable();
                }
            });
        }

        if ($missingStatus && Schema::hasColumn('documents', 'verified')) {
            DB::table('documents')
                ->where('verified', true)
                ->update(['status' => 'approved']);
        }

        $this->addIndexIfMissing(
            'documents',
            ['handyman_id', 'type'],
            'documents_handyman_id_type_index'
        );
        $this->addIndexIfMissing('documents', ['status'], 'documents_status_index');
        $this->addIndexIfMissing('documents', ['verified'], 'documents_verified_index');
        $this->addIndexIfMissing('documents', ['reviewed_by'], 'documents_reviewed_by_index');
    }

    private function repairHandymanSkills(): void
    {
        if (!Schema::hasTable('handyman_skills')) {
            return;
        }

        if (!Schema::hasColumn('handyman_skills', 'skill_id')) {
            if (Schema::hasColumn('handyman_skills', 'skills_id')) {
                Schema::table('handyman_skills', function (Blueprint $table): void {
                    $table->renameColumn('skills_id', 'skill_id');
                });
            } elseif (DB::table('handyman_skills')->exists()) {
                throw new \RuntimeException(
                    'handyman_skills contains rows but neither skill_id nor skills_id exists.'
                );
            } else {
                Schema::table('handyman_skills', function (Blueprint $table): void {
                    $table->unsignedBigInteger('skill_id');
                });
            }
        }

        $duplicates = DB::table('handyman_skills')
            ->select(['handyman_id', 'skill_id'])
            ->groupBy('handyman_id', 'skill_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new \RuntimeException(
                'Duplicate handyman/skill pairs must be resolved before schema repair can continue.'
            );
        }

        $this->addIndexIfMissing(
            'handyman_skills',
            ['handyman_id', 'skill_id'],
            'handyman_skills_handyman_id_skill_id_unique',
            true
        );
    }

    /** @param list<string> $columns */
    private function addIndexIfMissing(
        string $tableName,
        array $columns,
        string $indexName,
        bool $unique = false
    ): void {
        if (Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use (
            $columns,
            $indexName,
            $unique
        ): void {
            $unique
                ? $table->unique($columns, $indexName)
                : $table->index($columns, $indexName);
        });
    }
};
