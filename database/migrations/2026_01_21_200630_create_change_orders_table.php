<?php

//*****************************************************************************
//** A table for creating change orders                                      **
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
        Schema::create('change_orders', function (Blueprint $table) {
            $table->id();
	    $table->unsignedBigInteger('job_id');
	    $table->enum('requested_by', ['customer','handyman']);
	    $table->text('description');
	    $table->decimal('price_delta', 10, 2);
	    $table->enum('status', ['pending','approved','rejected'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('change_orders');
    }
};
