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
        Schema::create('profile_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contractor_profile_id')->constrained('contractor_profiles')->cascadeOnDelete();
            $table->foreignId('claimant_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('business_email')->nullable();
            $table->string('business_phone')->nullable();
            $table->string('proof_document_path')->nullable();
            $table->text('message')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('claimant_user_id');
            $table->index('contractor_profile_id');
            $table->unique(['contractor_profile_id', 'claimant_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profile_claims');
    }
};