<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {

            $table->string('address_line_2')
                  ->nullable()
                  ->after('street_address');

            $table->string('apartment')
                  ->nullable()
                  ->after('address_line_2');

        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {

            $table->dropColumn([
                'address_line_2',
                'apartment'
            ]);

        });
    }
};