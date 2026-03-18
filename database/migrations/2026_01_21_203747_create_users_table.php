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
	    $table->enum('role', ['customer','handyman','admin','company'])->default('customer');
	    $table->unsignedBigInteger('company_id')->nullable();
	    $table->string('phone')->nullable();
	    $table->string('address')->nullable();
            $table->timestamps();
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
