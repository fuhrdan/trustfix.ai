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
	Schema::create('cache', function (Blueprint $table)
	{
		$table->string('key_lock', 191)->primary();
		$table->string('id')->nullable();
		$table->text('agent')->nullable();
		$table->longtext('payload')->nullable();
		$table->integer('last_activity');
		$table->integer('expiration')->index();
	});

	Schema::create('cache_locks', function (Blueprint $table)
	{
		$table->string('key_lock', 191)->primary();
		$table->string('owner');
		$table->integer('expiration')->index();
	});
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
