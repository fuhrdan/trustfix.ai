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
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('handyman_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', [
                'posted',
                'requested',
                'accepted',
                'in_progress',
                'change_requested',
                'completed',
                'cancelled',
                'disputed',
            ])->default('posted');
            $table->string('address');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->text('initial_description');
            $table->decimal('agreed_price', 10, 2)->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['customer_id', 'status']);
            $table->index(['handyman_id', 'status']);
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