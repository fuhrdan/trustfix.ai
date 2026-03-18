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
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
	    $table->unsignedBigInteger('customer_id');
	    $table->unsignedBigInteger('handyman_id')->nullable();
	    $table->enum('status', [
		'posted',
		'requested',
		'accepted',
		'in_progress',
		'change_requested',
		'completed',
		'cancelled'
	    ])->default('posted');
	    $table->string('address');
	    $table->decimal('lat', 10, 7);
	    $table->decimal('lng', 10, 7);
	    $table->text('initial_description');
	    $table->decimal('agreed_price', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
