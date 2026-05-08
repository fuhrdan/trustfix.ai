<?php

//*****************************************************************************
//** Table for holding documents                                              **
//*****************************************************************************
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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('handyman_id')->constrained('users')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('type');
            $table->boolean('verified')->default(false);
            $table->timestamps();

            $table->index(['handyman_id', 'type']);
            $table->index('verified');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};