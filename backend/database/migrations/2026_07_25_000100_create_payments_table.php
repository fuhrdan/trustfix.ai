<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('contractor_id')->constrained('users')->cascadeOnDelete();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('platform_fee_cents')->default(0);
            $table->char('currency', 3)->default('usd');
            $table->enum('status', [
                'requires_payment_method',
                'requires_action',
                'processing',
                'succeeded',
                'failed',
                'cancelled',
                'refunded',
            ])->default('requires_payment_method');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['contractor_id', 'status']);
            $table->index(['customer_id', 'status']);
            $table->index(['job_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
