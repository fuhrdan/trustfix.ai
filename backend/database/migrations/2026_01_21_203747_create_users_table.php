<?php

//*****************************************************************************
//** Create the Users Table
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
        Schema::create('users', function (Blueprint $table) {
            $table->id(); // PRIMARY KEY
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->enum('role', ['customer', 'handyman', 'admin', 'company'])->default('customer');
            $table->foreignId('company_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->enum('account_status', ['active', 'suspended', 'banned'])->default('active');
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('suspension_reason')->nullable();
            $table->timestamps();

            $table->index('role');
            $table->index('company_id');
            $table->index('account_status');
            $table->index('suspended_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};