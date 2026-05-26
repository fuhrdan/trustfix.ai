<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * THIS is what happens when the plan didn't account for everything
     * at the beginning.  Remember kids, planning ahead leads to less
     * problems down the line.
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
                $table->string('onsite_contact_name')->nullable();
                $table->string('onsite_contact_phone')->nullable();
                $table->json('skills')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            //
        });
    }
};
