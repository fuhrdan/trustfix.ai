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
        Schema::create('contractor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('business_name');
            $table->text('bio')->nullable();
            $table->string('service_area')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->string('license_number')->nullable();
            $table->unsignedSmallInteger('years_experience')->nullable();
            $table->string('profile_photo_path')->nullable();
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected', 'suspended'])->default('draft');
            $table->boolean('is_public')->default(false);
            $table->timestamps();

            $table->index('status');
            $table->index('is_public');
            $table->index('service_area');
            $table->index(['status', 'is_public']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contractor_profiles');
    }
};