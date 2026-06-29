<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('contractor_profiles')) {
            Schema::create('contractor_profiles', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->boolean('is_contractor')->default(false);

                $table->string('business_name')->nullable();
                $table->string('business_phone')->nullable();
                $table->string('website')->nullable();

                $table->string('business_address')->nullable();
                $table->string('business_city')->nullable();
                $table->string('business_state')->nullable();
                $table->string('business_zip')->nullable();

                $table->integer('year_established')->nullable();
                $table->string('business_type')->nullable();
                $table->text('service_area')->nullable();
                $table->boolean('emergency_service')->default(false);

                $table->boolean('verified')->default(false);
                $table->timestamp('verified_at')->nullable();

                $table->foreignId('verified_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_profiles');
    }
};