<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add additive capabilities and backfill every existing primary role.
     *
     * The legacy `users.role` column is intentionally not removed: it remains
     * the default/compatibility role during rolling deployment and rollback.
     */
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('role', 32);
            $table->timestamps();

            $table->unique(['user_id', 'role']);
            $table->index('role');
        });

        $now = now();

        // Chunking bounds memory use; insertOrIgnore makes a retried backfill
        // safe against the unique (user_id, role) constraint.
        DB::table('users')
            ->select(['id', 'role'])
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($now): void {
                $rows = [];

                foreach ($users as $user) {
                    $rows[] = [
                        'user_id' => $user->id,
                        'role' => $user->role ?: 'customer',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('user_roles')->insertOrIgnore($rows);
                }
            });
    }

    /**
     * Remove only additive assignments; primary roles remain on `users`.
     *
     * Operators should prefer application rollback without `migrate:rollback`
     * after secondary roles have been assigned, because dropping this table
     * necessarily discards those secondary capabilities.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
