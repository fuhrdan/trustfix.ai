<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractor_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contractor_profile_id')->constrained('contractor_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->enum('role', ['owner', 'manager', 'employee'])->default('employee');
            $table->enum('status', ['invited', 'active', 'inactive'])->default('invited');
            $table->timestamps();

            $table->index('contractor_profile_id');
            $table->index('user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_employees');
    }
};
