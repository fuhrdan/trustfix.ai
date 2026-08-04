<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Some existing installs recorded the original, misnamed session
        // migration as complete, leaving a session-shaped `cache` table. Cache
        // and lock rows are transient, so normalize those tables here as well
        // as in the corrected historical migration.
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

        // Installations that already ran the original migration may not have a
        // real sessions table. Session rows are transient, so an invalid shape
        // can be replaced and affected users can simply sign in again.
        if (Schema::hasTable('sessions') && !Schema::hasColumn('sessions', 'id')) {
            Schema::drop('sessions');
        }

        if (!Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id', 191)->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }
    }

    public function down(): void
    {
        // All three tables can predate this repair migration, so rollback must
        // not remove tables that this migration may not have created.
    }
};
