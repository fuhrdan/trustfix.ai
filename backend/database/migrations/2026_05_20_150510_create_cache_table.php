<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Earlier TrustFix builds accidentally created a session-shaped table
        // named `cache`. Cache records and locks are disposable, so repair that
        // legacy shape before installing Laravel's current database cache schema.
        if (Schema::hasTable('cache') && !Schema::hasColumn('cache', 'key')) {
            Schema::drop('cache');
        }

        if (!Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key', 191)->primary();
                $table->mediumText('value');
                $table->integer('expiration')->index();
            });
        }

        if (Schema::hasTable('cache_locks') && !Schema::hasColumn('cache_locks', 'key')) {
            Schema::drop('cache_locks');
        }

        if (!Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key', 191)->primary();
                $table->string('owner');
                $table->integer('expiration')->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
