<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Stripe operation tables require MySQL or SQLite.');
        }
        if (!Schema::hasTable('payment_installments')
            || Schema::hasTable('stripe_operation_requests')
            || Schema::hasTable('stripe_operation_processing')) {
            throw new RuntimeException('Stripe operation schema requires review before migration.');
        }

        Schema::create('stripe_operation_requests', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('payment_installment_id')
                ->constrained('payment_installments')->restrictOnDelete();
            $table->string('operation_type', 64);
            $table->string('operation_key', 191)->unique();
            $table->string('idempotency_key', 191)->unique();
            $table->string('stripe_api_version', 64);
            $table->char('credential_sha256', 64);
            $table->longText('payload_encrypted');
            $table->char('payload_sha256', 64);
            $table->unsignedBigInteger('actor_id');
            $table->timestamp('retry_until');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('stripe_operation_processing', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('operation_request_id')->unique()
                ->constrained('stripe_operation_requests')->restrictOnDelete();
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('lease_token', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('stripe_object_id', 191)->nullable();
            $table->string('last_error_code', 128)->nullable();
            $table->timestamps();
            $table->index(['status', 'lease_expires_at']);
        });

        foreach (['UPDATE', 'DELETE'] as $verb) {
            $trigger = 'stripe_operation_requests_no_' . strtolower($verb);
            $statement = "CREATE TRIGGER {$trigger} BEFORE {$verb} ON stripe_operation_requests ";
            $message = 'TrustFix Stripe operation requests are append-only';
            if ($driver === 'mysql') {
                DB::unprepared($statement . 'FOR EACH ROW BEGIN '
                    . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END");
            } else {
                DB::unprepared($statement . "BEGIN SELECT RAISE(ABORT, '{$message}'); END");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Stripe operation history is retained. Use a reviewed forward migration.');
    }
};
