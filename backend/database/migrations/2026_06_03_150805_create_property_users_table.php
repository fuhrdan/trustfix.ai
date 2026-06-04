<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Coffee in the morning is a great way to start the day.
// Coffee at night is a good way to end up coding all night.

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('property_users', function (Blueprint $table)
        {
            $table->id();
    
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('user_id');

            $table->timestamps();

            $table->unique([
                'property_id',
                'user_id'
            ]);

            $table->foreign('property_id')
                ->references('id')
                ->on('properties')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_users');
    }
};
