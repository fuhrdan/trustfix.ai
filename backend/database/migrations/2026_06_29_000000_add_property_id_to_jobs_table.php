<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->foreignId('property_id')
                ->nullable()
                ->after('handyman_id')
                ->constrained('properties')
                ->nullOnDelete();

            $table->index(['property_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropForeign(['property_id']);
            $table->dropIndex(['property_id', 'status']);
            $table->dropColumn('property_id');
        });
    }
};
