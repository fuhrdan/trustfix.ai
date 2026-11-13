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
            throw new RuntimeException('Payment audit tables require MySQL or SQLite.');
        }

        foreach ([
            'stripe_event_receipts',
            'stripe_event_processing',
            'payment_journal_entries',
        ] as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException(
                    "Existing payment audit table requires review: {$table}"
                );
            }
        }

        // Immutable receipt of a verified event. Store encrypted raw payloads.
        Schema::create('stripe_event_receipts', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('stripe_event_id', 191)->unique();
            $table->string('stripe_account_id', 191)->nullable();
            $table->string('event_type', 128);
            $table->boolean('livemode');
            $table->string('api_version', 64)->nullable();
            $table->unsignedBigInteger('stripe_created_at');
            $table->longText('payload_encrypted');
            $table->char('payload_sha256', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['event_type', 'stripe_created_at']);
        });

        // Mutable processing state is separate from the immutable receipt.
        // A received event is not necessarily a successfully processed event.
        Schema::create('stripe_event_processing', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('stripe_event_receipt_id')
                ->unique()
                ->constrained('stripe_event_receipts')
                ->restrictOnDelete();
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('lease_token', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('last_error_code', 128)->nullable();
            $table->timestamps();

            $table->index(['status', 'lease_expires_at']);
        });

        // Corrections append new entries rather than rewriting prior facts.
        Schema::create('payment_journal_entries', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('payment_id')
                ->constrained('payments')
                ->restrictOnDelete();
            $table->string('entry_key', 191)->unique();
            $table->string('entry_type', 64);
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('stripe_object_id', 191)->nullable();
            $table->foreignId('stripe_event_receipt_id')
                ->nullable()
                ->constrained('stripe_event_receipts')
                ->restrictOnDelete();

            // Retain the historical actor ID without a cascading relationship.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['payment_id', 'id']);
            $table->index('stripe_object_id');
        });

        foreach (['stripe_event_receipts', 'payment_journal_entries'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                $trigger = "{$table}_no_{$operation}";
                $verb = strtoupper($operation);
                $message = 'TrustFix payment audit records are append-only';

                if ($driver === 'mysql') {
                    DB::unprepared(
                        "CREATE TRIGGER {$trigger} BEFORE {$verb} ON {$table} "
                        . "FOR EACH ROW BEGIN "
                        . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; "
                        . 'END'
                    );
                } else {
                    DB::unprepared(
                        "CREATE TRIGGER {$trigger} BEFORE {$verb} ON {$table} "
                        . "BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                    );
                }
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Payment audit history is retained. Use a reviewed forward migration.'
        );
    }
};
