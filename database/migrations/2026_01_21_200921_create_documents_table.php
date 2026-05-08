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
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->boolean('verified')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();

            $table->index(['handyman_id', 'type']);
            $table->index('status');
            $table->index('verified');
            $table->index('reviewed_by');
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