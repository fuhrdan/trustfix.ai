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
            throw new RuntimeException('Payment agreement tables require MySQL or SQLite.');
        }

        $tables = [
            'payment_agreements',
            'payment_installments',
            'payment_agreement_consents',
        ];
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException("Existing payment agreement table requires review: {$table}");
            }
        }
        foreach (['jobs', 'users', 'payments', 'change_orders'] as $table) {
            if (!Schema::hasTable($table)) {
                throw new RuntimeException("Missing payment agreement prerequisite: {$table}");
            }
        }

        // Immutable proposed terms. Existence does not establish consent,
        // successful payment, completion approval, or transfer eligibility.
        Schema::create('payment_agreements', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('job_id')->constrained('jobs')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('contractor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('previous_agreement_id')->nullable()
                ->constrained('payment_agreements')->restrictOnDelete();
            $table->string('agreement_kind', 32);
            $table->string('policy_version', 64);
            $table->string('payment_model', 64);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('platform_fee_cents');
            $table->unsignedSmallInteger('platform_fee_basis_points');
            $table->json('scope_snapshot');
            $table->char('scope_sha256', 64);
            $table->json('schedule_snapshot');
            $table->json('included_change_orders');
            $table->char('agreement_sha256', 64);

            // Historical actor identity is retained without a cascading FK.
            $table->unsignedBigInteger('created_by_actor_id');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['job_id', 'version']);
        });

        // One payable installment binds one payment record to frozen terms.
        // Payment status and Stripe processing state remain separate.
        Schema::create('payment_installments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('payment_agreement_id')
                ->constrained('payment_agreements')->restrictOnDelete();
            $table->foreignId('payment_id')->unique()
                ->constrained('payments')->restrictOnDelete();
            $table->foreignId('source_change_order_id')->nullable()
                ->constrained('change_orders')->restrictOnDelete();
            $table->string('payable_key', 191)->unique();
            $table->unsignedInteger('sequence');
            $table->string('milestone_key', 64);
            $table->string('collection_trigger', 64);
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('platform_fee_cents');
            $table->char('currency', 3);
            $table->string('transfer_group', 100)->unique();
            $table->char('installment_sha256', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['payment_agreement_id', 'sequence']);
            $table->unique(['payment_agreement_id', 'milestone_key']);
        });

        // Acceptance/revocation facts append rather than overwrite.
        // Services must verify actor, participant role, matching terms and
        // effective consent; a receipt alone is not authorization to charge.
        Schema::create('payment_agreement_consents', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('payment_agreement_id')
                ->constrained('payment_agreements')->restrictOnDelete();
            $table->string('decision_key', 191)->unique();
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_role', 32);
            $table->string('event_type', 64);
            $table->char('agreement_sha256', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['payment_agreement_id', 'id']);
        });

        foreach ($tables as $table) {
            foreach (['update', 'delete'] as $operation) {
                $trigger = "{$table}_no_{$operation}";
                $verb = strtoupper($operation);
                $message = 'TrustFix payment agreement records are append-only';

                if ($driver === 'mysql') {
                    DB::unprepared(
                        "CREATE TRIGGER {$trigger} BEFORE {$verb} ON {$table} "
                        . 'FOR EACH ROW BEGIN '
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
            'Payment agreement history is retained. Use a reviewed forward migration.'
        );
    }
};
