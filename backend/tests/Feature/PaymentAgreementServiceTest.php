<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\ChangeOrderController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\JobEstimateController;
use App\Models\Job;
use App\Models\User;
use App\Services\PaymentAgreementService;
use App\Services\PaymentCheckoutReservation;
use App\Services\StripeEventInbox;
use App\Services\StripePaymentGateway;
use App\Services\StripePaymentIntentExecutor;
use App\Services\StripePaymentIntentReconciler;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as ControllerRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PaymentAgreementServiceTest extends TestCase
{
    private const CONNECTION = 'payment_agreement_service_memory';
    private Connection $memoryDb;
    private string $previousConnection = 'sqlite';
    private int $serial = 0;
    private int $expectedStripeResponses = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame('sqlite', config('database.default'));
        self::assertSame(':memory:', config('database.connections.sqlite.database'));

        config([
            'app.env' => 'testing',
            'payments.enabled' => true,
            'payments.test_mode_only' => true,
            'payments.auto_release_enabled' => false,
            'services.stripe.secret' => 'sk_test_agreement_fixture',
            'services.stripe.key' => 'pk_test_agreement_fixture',
            'services.stripe.webhook_secret' => 'whsec_agreement_fixture',
        ]);

        $this->previousConnection = DB::getDefaultConnection();
        config(['database.connections.' . self::CONNECTION => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection(self::CONNECTION);
        Schema::clearResolvedInstance('db.schema');
        $this->memoryDb = DB::connection(self::CONNECTION);
        self::assertSame(':memory:', $this->memoryDb->getDatabaseName());
        $this->memoryDb->statement('PRAGMA foreign_keys = ON');
        self::assertSame(1, (int) $this->memoryDb->selectOne('PRAGMA foreign_keys')->foreign_keys);

        // Parent fixtures; actual Job/ChangeOrder models and agreement migration.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('handyman_id')->nullable()->constrained('users');
            $table->unsignedBigInteger('property_id')->nullable();
            $table->string('status');
            $table->string('address')->nullable();
            $table->text('initial_description');
            $table->decimal('agreed_price', 10, 2)->nullable();
            $table->json('skills')->nullable();
            $table->timestamps();
        });
        Schema::create('change_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('jobs');
            $table->string('requested_by')->default('handyman');
            $table->text('description');
            $table->decimal('price_delta', 10, 2);
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('jobs');
            $table->string('status');
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('jobs');
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('contractor_id')->constrained('users');
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('platform_fee_cents')->default(0);
            $table->char('currency', 3)->default('usd');
            $table->string('stripe_payment_intent_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status')->default('requires_payment_method');
            $table->timestamps();
        });

        foreach ([1, 2, 3, 4, 5] as $id) {
            DB::table('users')->insert(['id' => $id]);
        }
        foreach ([1 => [1, 2], 2 => [4, 5]] as $id => [$customer, $contractor]) {
            DB::table('jobs')->insert([
                'id' => $id,
                'customer_id' => $customer,
                'handyman_id' => $contractor,
                'property_id' => null,
                'status' => 'accepted',
                'address' => 'Fixture address',
                'initial_description' => 'Fixture agreed scope',
                'agreed_price' => '125.00',
                'skills' => json_encode(['plumbing', 'repair'], JSON_THROW_ON_ERROR),
            ]);
        }

        $migration = require database_path(
            'migrations/2026_11_08_000100_create_payment_agreement_tables.php'
        );
        $migration->up();
    }

    protected function tearDown(): void
    {
        try {
            Http::assertSentCount($this->expectedStripeResponses);
        } finally {
            if (isset($this->memoryDb)) {
                DB::purge(self::CONNECTION);
                DB::setDefaultConnection($this->previousConnection);
                Schema::clearResolvedInstance('db.schema');
            }
            parent::tearDown();
        }
    }

    public function test_snapshot_includes_approved_changes_without_adding_their_price_again(): void
    {
        $this->change(11, 'approved', '25.00');
        $this->change(12, 'approved', '-5.00');
        $this->change(13, 'rejected', '99.00');
        $before = $this->rows('change_orders');
        $agreement = $this->proposal();

        self::assertSame(12500, (int) $agreement->amount_cents);
        self::assertSame(1250, (int) $agreement->platform_fee_cents);
        self::assertSame(1000, (int) $agreement->platform_fee_basis_points);
        self::assertSame('staging_2026_11_v1', $agreement->policy_version);
        self::assertSame('separate_charges_transfers_v1', $agreement->payment_model);
        self::assertSame('usd', $agreement->currency);

        $changes = json_decode($agreement->included_change_orders, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([11, 12], array_column($changes, 'id'));
        self::assertSame([2500, -500], array_column($changes, 'price_delta_cents'));

        $scope = json_decode($agreement->scope_snapshot, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($changes, $scope['change_orders']);
        self::assertSame(hash('sha256', $agreement->scope_snapshot), $agreement->scope_sha256);

        $schedule = json_decode($agreement->schedule_snapshot, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $schedule);
        self::assertSame('upfront', $schedule[0]['milestone_key']);
        self::assertSame(12500, $schedule[0]['amount_cents']);
        self::assertSame($before, $this->rows('change_orders'));

        foreach (['payments', 'payment_installments', 'payment_agreement_consents'] as $table) {
            self::assertSame(0, DB::table($table)->count());
        }
    }

    public function test_either_participant_can_retry_a_proposal_without_rewriting_it_or_signing_it(): void
    {
        $first = $this->proposal(1, 2);
        $second = $this->proposal(1, 1);
        self::assertSame((array) $first, (array) $second);
        self::assertSame(2, (int) $second->created_by_actor_id);
        self::assertSame(1, DB::table('payment_agreements')->count());
        self::assertSame(0, DB::table('payment_agreement_consents')->count());
    }

    public function test_key_order_is_stable_and_changed_terms_create_a_new_immutable_version(): void
    {
        $this->job(['skills' => json_encode(['b' => 'B', 'a' => 'A'], JSON_THROW_ON_ERROR)]);
        $first = $this->proposal();
        $this->job(['skills' => json_encode(['a' => 'A', 'b' => 'B'], JSON_THROW_ON_ERROR)]);
        self::assertSame((int) $first->id, (int) $this->proposal()->id);

        $this->job(['initial_description' => 'Revised scope', 'agreed_price' => '130.05']);
        $second = $this->proposal(1, 2);
        self::assertSame(2, (int) $second->version);
        self::assertSame((int) $first->id, (int) $second->previous_agreement_id);
        self::assertSame(13005, (int) $second->amount_cents);
        self::assertSame(1301, (int) $second->platform_fee_cents);
        self::assertNotSame($first->agreement_sha256, $second->agreement_sha256);
        self::assertSame((array) $first, (array) DB::table('payment_agreements')->find($first->id));
    }

    public function test_old_consents_cannot_authorize_a_new_price_or_scope(): void
    {
        $first = $this->proposal();
        $this->both($first);
        $old = $this->rows('payment_agreement_consents');
        $this->job(['agreed_price' => '150.00', 'initial_description' => 'Changed scope']);
        $second = $this->proposal();

        $this->denied(fn () => $this->decide($first, 1), 409);
        $this->denied(fn () => $this->ready($first), 409);
        $this->denied(fn () => $this->ready($second), 409);
        $this->both($second);
        self::assertSame((int) $second->id, (int) $this->ready($second)->id);
        self::assertSame($old, array_slice($this->rows('payment_agreement_consents'), 0, 2));
    }

    public function test_collection_requires_a_transaction_both_consents_and_the_customer(): void
    {
        $agreement = $this->proposal();
        try {
            app(PaymentAgreementService::class)->lockApprovedInitial(
                (int) $agreement->id, 1, $agreement->agreement_sha256
            );
            self::fail('Collection eligibility must require an outer transaction.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('reservation transaction', $exception->getMessage());
        }

        $this->denied(fn () => $this->ready($agreement), 409);
        $this->decide($agreement, 1);
        $this->denied(fn () => $this->ready($agreement), 409);
        $this->decide($agreement, 2);
        self::assertSame((int) $agreement->id, (int) $this->ready($agreement)->id);
        foreach ([2, 3, 4] as $actor) {
            $this->denied(fn () => $this->ready($agreement, $actor), 403);
        }
        self::assertSame(0, DB::table('payments')->count());
    }

    public function test_outsiders_and_repeated_requester_decisions_cannot_supply_counterparty_consent(): void
    {
        foreach ([0, 3, 4] as $actor) {
            $this->denied(fn () => $this->proposal(1, $actor), 403);
        }
        $agreement = $this->proposal();
        foreach ([0, 3, 4] as $actor) {
            $this->denied(fn () => $this->decide($agreement, $actor), 403);
        }

        $this->decide($agreement, 1);
        $this->decide($agreement, 1);
        self::assertSame(
            ['customer', 'customer'],
            DB::table('payment_agreement_consents')->orderBy('id')->pluck('actor_role')->all()
        );
        $this->denied(fn () => $this->ready($agreement), 409);
    }

    public function test_decision_retries_are_idempotent_and_conflicting_key_reuse_is_rejected(): void
    {
        $agreement = $this->proposal();
        $key = 'fixture_shared_decision_key';
        $first = $this->decide($agreement, 1, 'scope_accepted', $key);
        self::assertSame((array) $first, (array) $this->decide($agreement, 1, 'scope_accepted', $key));
        $this->denied(fn () => $this->decide($agreement, 1, 'consent_revoked', $key), 409);
        $this->denied(fn () => $this->decide($agreement, 2, 'scope_accepted', $key), 409);

        $other = $this->proposal(2, 4);
        $this->denied(fn () => $this->decide($other, 4, 'scope_accepted', $key), 409);
        self::assertSame(1, DB::table('payment_agreement_consents')->count());
        self::assertSame((array) $first, $this->rows('payment_agreement_consents')[0]);
    }

    public function test_hashes_keys_and_event_types_are_validated_before_consent_is_recorded(): void
    {
        $agreement = $this->proposal();
        foreach (['', str_repeat('g', 64), str_repeat('0', 64), strtoupper($agreement->agreement_sha256)] as $hash) {
            $this->denied(fn () => app(PaymentAgreementService::class)->recordDecision(
                (int) $agreement->id, 1, $hash, 'scope_accepted', 'fixture_valid_decision_key'
            ), 409);
        }
        foreach (['', 'short', 'fixture key with spaces', "fixture_decision\nkey", str_repeat('x', 192)] as $key) {
            $this->denied(fn () => $this->decide($agreement, 1, 'scope_accepted', $key), 422);
        }
        foreach (['contractor_accepted', 'completion_approved', 'admin_override'] as $type) {
            $this->denied(fn () => $this->decide($agreement, 1, $type), 422);
        }
        self::assertSame(0, DB::table('payment_agreement_consents')->count());
    }

    public function test_revocation_works_while_paused_and_replay_does_not_restore_consent(): void
    {
        $agreement = $this->proposal();
        $key = 'fixture_original_customer_accept';
        $first = $this->decide($agreement, 1, 'scope_accepted', $key);
        $this->decide($agreement, 2);
        $old = $this->rows('payment_agreement_consents');

        config(['payments.enabled' => false]);
        $this->decide($agreement, 1, 'consent_revoked');
        $this->denied(fn () => $this->decide($agreement, 1), 503);
        config(['payments.enabled' => true]);

        $this->denied(fn () => $this->ready($agreement), 409);
        self::assertSame((array) $first, (array) $this->decide($agreement, 1, 'scope_accepted', $key));
        $this->denied(fn () => $this->ready($agreement), 409);
        self::assertSame($old, array_slice($this->rows('payment_agreement_consents'), 0, 2));

        $this->decide($agreement, 1);
        self::assertSame((int) $agreement->id, (int) $this->ready($agreement)->id);
        self::assertSame(4, DB::table('payment_agreement_consents')->count());
    }

    public function test_inactive_unassigned_pending_change_and_disputed_jobs_are_blocked(): void
    {
        $agreement = $this->proposal();
        $this->both($agreement);

        foreach (['posted', 'requested', 'change_requested', 'disputed', 'cancelled', 'completed'] as $status) {
            $this->job(['status' => $status]);
            $this->denied(fn () => $this->proposal(), 409);
        }
        foreach ([null, 1] as $contractor) {
            $this->job(['status' => 'accepted', 'handyman_id' => $contractor]);
            $this->denied(fn () => $this->proposal(), 409);
        }

        $this->job(['handyman_id' => 2]);
        $this->change(11, 'pending', '25.00');
        $this->denied(fn () => $this->proposal(), 409);
        $this->denied(fn () => $this->ready($agreement), 409);
        DB::table('change_orders')->where('id', 11)->delete();

        foreach (['open', 'under_review'] as $status) {
            $id = DB::table('disputes')->insertGetId(['job_id' => 1, 'status' => $status]);
            $this->denied(fn () => $this->proposal(), 409);
            $this->denied(fn () => $this->ready($agreement), 409);
            DB::table('disputes')->where('id', $id)->delete();
        }
        self::assertSame((int) $agreement->id, (int) $this->ready($agreement)->id);
    }

    public function test_legacy_payment_records_require_reconciliation_regardless_of_status(): void
    {
        $agreement = $this->proposal();
        $this->both($agreement);

        foreach (['requires_payment_method', 'processing', 'succeeded', 'failed', 'cancelled', 'refunded'] as $status) {
            $id = $this->payment(['status' => $status]);
            $before = $this->rows('payments');
            $this->denied(fn () => $this->proposal(), 409);
            $this->denied(fn () => $this->decide($agreement, 1), 409);
            $this->denied(fn () => $this->ready($agreement), 409);
            self::assertSame($before, $this->rows('payments'));
            DB::table('payments')->where('id', $id)->delete();
        }

        self::assertSame(1, DB::table('payment_agreements')->count());
    }

    public function test_reserved_payables_freeze_terms_and_require_cancellation_or_refund_handling(): void
    {
        $agreement = $this->proposal();
        $this->both($agreement);
        $this->reserve($agreement);
        self::assertSame((int) $agreement->id, (int) $this->proposal(1, 2)->id);
        $this->denied(fn () => $this->decide($agreement, 1), 409);
        $this->denied(fn () => $this->decide($agreement, 1, 'consent_revoked'), 409);

        $this->job(['agreed_price' => '200.00']);
        $this->denied(fn () => $this->proposal(), 409);
        $this->denied(fn () => $this->ready($agreement), 409);
        self::assertSame((array) $agreement, (array) DB::table('payment_agreements')->find($agreement->id));
        self::assertSame(1, DB::table('payment_agreements')->count());
        self::assertSame(2, DB::table('payment_agreement_consents')->count());
    }

    public function test_participant_location_and_scope_changes_invalidate_existing_consent(): void
    {
        $agreement = $this->proposal();
        $this->both($agreement);
        $original = (array) DB::table('jobs')->find(1);

        foreach ([
            ['handyman_id' => 5], ['customer_id' => 4], ['address' => 'Changed location'],
            ['property_id' => 123], ['initial_description' => 'Changed work'],
            ['skills' => json_encode(['electrical'], JSON_THROW_ON_ERROR)],
        ] as $changes) {
            $this->job($changes);
            $this->denied(fn () => $this->ready($agreement), 409);
            $this->denied(fn () => $this->decide($agreement, 1), 409);
            $this->job($original);
        }

        self::assertSame(2, DB::table('payment_agreement_consents')->count());
        self::assertSame((int) $agreement->id, (int) $this->ready($agreement)->id);
    }

    public function test_editing_an_included_change_invalidates_the_authorized_scope(): void
    {
        $this->change(11, 'approved', '25.00');
        $first = $this->proposal();
        $this->both($first);
        DB::table('change_orders')->where('id', 11)->update(['description' => 'Different extra work']);

        $this->denied(fn () => $this->ready($first), 409);
        $second = $this->proposal();
        self::assertNotSame($first->scope_sha256, $second->scope_sha256);
        self::assertSame(12500, (int) $second->amount_cents);
        self::assertSame((array) $first, (array) DB::table('payment_agreements')->find($first->id));
    }

    public function test_initial_upfront_collection_cannot_be_prepared_after_work_starts(): void
    {
        $agreement = $this->proposal();
        $this->both($agreement);
        $this->job(['status' => 'in_progress']);
        self::assertSame((int) $agreement->id, (int) $this->proposal()->id);
        $this->denied(fn () => $this->ready($agreement), 409);
        $this->job(['status' => 'accepted']);
        self::assertSame((int) $agreement->id, (int) $this->ready($agreement)->id);
    }

    public function test_invalid_prices_and_unsupported_scope_leave_no_partial_agreements(): void
    {
        foreach ([null, '0.00', '0.49', '-1.00', '1000000.00'] as $price) {
            $this->job(['agreed_price' => $price]);
            $this->denied(fn () => $this->proposal(), 409);
            self::assertSame(0, DB::table('payment_agreements')->count());
        }

        $this->job(['agreed_price' => '125.00', 'skills' => json_encode([1.25], JSON_THROW_ON_ERROR)]);
        $this->denied(fn () => $this->proposal(), 409);
        $this->job(['skills' => '[]', 'initial_description' => "invalid\xFF"]);
        $this->denied(fn () => $this->proposal(), 409);
        self::assertSame(0, DB::table('payment_agreements')->count());
    }

    public function test_disabled_or_unsafe_configuration_blocks_actions_before_queries(): void
    {
        $queries = 0;
        DB::listen(static function () use (&$queries): void { $queries++; });

        foreach ([
            ['payments.enabled' => false],
            ['payments.enabled' => true, 'services.stripe.secret' => 'sk_live_agreement_fixture'],
        ] as $settings) {
            config($settings);
            $this->denied(fn () => $this->proposal(), 503);
            $this->denied(fn () => app(PaymentAgreementService::class)->recordDecision(
                999, 1, str_repeat('a', 64), 'scope_accepted', 'fixture_disabled_decision'
            ), 503);
            $this->denied(fn () => app(PaymentAgreementService::class)
                ->lockApprovedInitial(999, 1, str_repeat('a', 64)), 503);
        }

        self::assertSame(0, $queries);
    }

    public function test_outer_transaction_failure_rolls_back_proposal_and_consent_for_safe_retry(): void
    {
        try {
            DB::transaction(function (): void {
                $agreement = $this->proposal();
                $this->decide($agreement, 1, 'scope_accepted', 'fixture_rollback_decision');
                throw new RuntimeException('fixture_after_consent_failure');
            });
            self::fail('Injected transaction failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('fixture_after_consent_failure', $exception->getMessage());
        }

        self::assertSame(0, DB::table('payment_agreements')->count());
        self::assertSame(0, DB::table('payment_agreement_consents')->count());
        $agreement = $this->proposal();
        $this->decide($agreement, 1, 'scope_accepted', 'fixture_rollback_decision');
        self::assertSame(1, DB::table('payment_agreement_consents')->count());
    }

    public function test_private_scope_arguments_are_redacted_from_failure_traces(): void
    {
        $this->job(['initial_description' => 'private-agreement-scope-marker', 'skills' => '[1.25]']);
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);

        try {
            $error = $this->denied(fn () => $this->proposal(), 409);
            $found = false;
            foreach ($error->getTrace() as $frame) {
                if (($frame['class'] ?? null) === PaymentAgreementService::class
                    && in_array($frame['function'] ?? '', ['terms', 'json', 'normalized'], true)) {
                    self::assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][0] ?? null);
                    $found = true;
                }
            }
            self::assertTrue($found);
            self::assertStringNotContainsString(
                'private-agreement-scope-marker',
                json_encode($error->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR)
            );
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public function test_checkout_reserves_one_payment_and_one_immutable_stripe_request(): void
    {
        $this->change(11, 'approved', '25.00');
        $agreement = $this->proposal();
        $this->both($agreement);
        $checkout = $this->checkout();
        $ids = $checkout->reserveInitial(1, (int) $agreement->id, 1, $agreement->agreement_sha256);

        $payment = DB::table('payments')->find($ids['payment_id']);
        $installment = DB::table('payment_installments')->find($ids['installment_id']);
        $request = DB::table('stripe_operation_requests')->find($ids['operation_id']);
        $state = DB::table('stripe_operation_processing')->where('operation_request_id', $request->id)->first();
        $journal = DB::table('payment_journal_entries')->first();
        $plaintext = Crypt::decryptString($request->payload_encrypted);
        $payload = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(12500, (int) $payment->amount_cents);
        self::assertSame(1250, (int) $payment->platform_fee_cents);
        self::assertSame('requires_payment_method', $payment->status);
        self::assertNull($payment->stripe_payment_intent_id);
        self::assertSame((int) $agreement->id, (int) $installment->payment_agreement_id);
        self::assertSame('job:1:initial', $installment->payable_key);
        self::assertSame(12500, $payload['amount_cents']);
        self::assertSame($installment->transfer_group, $payload['transfer_group']);
        self::assertSame($ids['payment_id'], $payload['metadata']['trustfix_payment_id']);
        self::assertSame($agreement->agreement_sha256, $payload['metadata']['trustfix_agreement_sha256']);
        self::assertSame(hash('sha256', $plaintext), $request->payload_sha256);
        self::assertMatchesRegularExpression('/\Atrustfix_intent_[a-f0-9]{32}\z/', $request->idempotency_key);
        self::assertSame(23 * 3600, strtotime($request->retry_until) - strtotime($request->created_at));
        self::assertSame('pending', $state->status);
        self::assertSame(0, (int) $state->attempts);
        self::assertSame('collection_authorized', $journal->entry_type);
        self::assertSame(1, (int) $journal->actor_id);

        self::assertSame($ids, $checkout->reserveInitial(1, (int) $agreement->id, 1, $agreement->agreement_sha256));
        self::assertSame((array) $request, (array) DB::table('stripe_operation_requests')->find($request->id));
        foreach (['payments', 'payment_installments', 'stripe_operation_requests',
            'stripe_operation_processing', 'payment_journal_entries'] as $table) {
            self::assertSame(1, DB::table($table)->count());
        }
    }

    public function test_checkout_requires_current_terms_and_the_authorizing_customer(): void
    {
        $agreement = $this->proposal();
        $id = (int) $agreement->id;
        $hash = $agreement->agreement_sha256;
        $checkout = $this->checkout();
        $this->decide($agreement, 1);
        $this->denied(fn () => $checkout->reserveInitial(1, $id, 1, $hash), 409);
        $this->decide($agreement, 2);
        $this->denied(fn () => $checkout->reserveInitial(1, $id, 2, $hash), 403);
        $this->denied(fn () => $checkout->reserveInitial(2, $id, 1, $hash), 409);
        $this->denied(fn () => $checkout->reserveInitial(1, $id, 1, str_repeat('0', 64)), 409);
        $this->job(['agreed_price' => '126.00']);
        $this->denied(fn () => $checkout->reserveInitial(1, $id, 1, $hash), 409);
        foreach (['payments', 'payment_installments', 'stripe_operation_requests',
            'stripe_operation_processing', 'payment_journal_entries'] as $table) {
            self::assertSame(0, DB::table($table)->count());
        }
    }

    public function test_checkout_rolls_back_every_reservation_row_when_authorization_cannot_be_recorded(): void
    {
        $agreement = $this->proposal();
        $this->both($agreement);
        $checkout = $this->checkout();
        Schema::create('checkout_fixture_fault', function (Blueprint $table) {
            $table->boolean('enabled');
        });
        DB::table('checkout_fixture_fault')->insert(['enabled' => true]);
        DB::unprepared('CREATE TRIGGER checkout_fixture_abort BEFORE INSERT ON payment_journal_entries '
            . 'WHEN (SELECT enabled FROM checkout_fixture_fault) = 1 '
            . "BEGIN SELECT RAISE(ABORT, 'checkout_fixture_abort'); END");

        try {
            $checkout->reserveInitial(1, (int) $agreement->id, 1, $agreement->agreement_sha256);
            self::fail('The failed journal write must abort the reservation.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('checkout_fixture_abort', $exception->getMessage());
        }
        foreach (['payments', 'payment_installments', 'stripe_operation_requests',
            'stripe_operation_processing', 'payment_journal_entries'] as $table) {
            self::assertSame(0, DB::table($table)->count());
        }
        self::assertSame(2, DB::table('payment_agreement_consents')->count());

        DB::table('checkout_fixture_fault')->update(['enabled' => false]);
        $checkout->reserveInitial(1, (int) $agreement->id, 1, $agreement->agreement_sha256);
        self::assertSame(1, DB::table('stripe_operation_requests')->count());
        self::assertSame(1, DB::table('payment_journal_entries')->count());
    }

    public function test_checkout_preserves_the_request_after_credential_change_or_an_update_attempt(): void
    {
        $agreement = $this->proposal();
        $this->both($agreement);
        $checkout = $this->checkout();
        $ids = $checkout->reserveInitial(1, (int) $agreement->id, 1, $agreement->agreement_sha256);
        $before = $this->rows('stripe_operation_requests');
        try {
            DB::table('stripe_operation_requests')->where('id', $ids['operation_id'])
                ->update(['idempotency_key' => 'fixture_replacement_key']);
            self::fail('A reserved Stripe request must be immutable.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('append-only', $exception->getMessage());
        }

        config(['services.stripe.secret' => 'sk_test_different_agreement_fixture']);
        $this->denied(fn () => $checkout->reserveInitial(
            1, (int) $agreement->id, 1, $agreement->agreement_sha256
        ), 409);
        self::assertSame($before, $this->rows('stripe_operation_requests'));
        self::assertSame(1, DB::table('payments')->count());
    }

    public function test_executor_binds_one_intent_and_replays_without_another_request(): void
    {
        [$executor, $ids, $saved] = $this->executionFixture();
        $this->expectedStripeResponses = 1;
        Http::fake(fn (Request $request) => $this->executorResponse($request));
        $result = $executor->executeInitial($ids['operation_id'], 1);

        self::assertSame([
            'status' => 'completed',
            'payment_id' => $ids['payment_id'],
            'stripe_payment_intent_id' => 'pi_executor_fixture',
        ], $result);
        self::assertSame($result, $executor->executeInitial($ids['operation_id'], 1));
        Http::assertSent(fn (Request $request) =>
            $request->hasHeader('Idempotency-Key', $saved->idempotency_key)
            && $request->data()['metadata']['trustfix_payment_id'] === (string) $ids['payment_id']);
        $payment = DB::table('payments')->find($ids['payment_id']);
        self::assertSame('pi_executor_fixture', $payment->stripe_payment_intent_id);
        self::assertSame('requires_payment_method', $payment->status);
        self::assertSame(1, (int) $this->operationState()->attempts);
        self::assertNull($this->operationState()->lease_token);
        self::assertSame(1, DB::table('payment_journal_entries')->where('entry_type', 'payment_intent_created')->count());
    }

    public function test_executor_retries_a_timeout_with_identical_parameters_and_key(): void
    {
        [$executor, $ids, $saved] = $this->executionFixture();
        $this->expectedStripeResponses = 1;
        $attempts = [];
        Http::fake(function (Request $request) use (&$attempts) {
            $attempts[] = [$request->header('Idempotency-Key'), $request->data()];
            if (count($attempts) === 1) {
                throw new ConnectionException('Fixture network timeout');
            }

            return $this->executorResponse($request);
        });

        self::assertSame('pending', $executor->executeInitial($ids['operation_id'], 1)['status']);
        self::assertSame('transport_error', $this->operationState()->last_error_code);
        self::assertSame('completed', $executor->executeInitial($ids['operation_id'], 1)['status']);
        self::assertCount(2, $attempts);
        self::assertSame([$saved->idempotency_key], $attempts[0][0]);
        self::assertSame($attempts[0], $attempts[1]);
        self::assertSame(2, (int) $this->operationState()->attempts);
    }

    public function test_executor_respects_active_leases_and_fences_stale_responses(): void
    {
        [$executor, $ids] = $this->executionFixture();
        DB::table('stripe_operation_processing')->update([
            'status' => 'processing', 'attempts' => 1,
            'lease_token' => str_repeat('a', 64), 'lease_expires_at' => now()->addMinute(),
        ]);
        Http::fake(function (Request $request) {
            self::assertSame(2, (int) $this->operationState()->attempts);
            DB::table('stripe_operation_processing')->update([
                'attempts' => 3, 'lease_token' => str_repeat('b', 64),
                'lease_expires_at' => now()->addMinute(),
            ]);

            return $this->executorResponse($request);
        });

        self::assertSame('busy', $executor->executeInitial($ids['operation_id'], 1)['status']);
        Http::assertNothingSent();
        DB::table('stripe_operation_processing')->update(['lease_expires_at' => now()->subSecond()]);
        $this->expectedStripeResponses = 1;
        self::assertSame('busy', $executor->executeInitial($ids['operation_id'], 1)['status']);
        self::assertSame(str_repeat('b', 64), $this->operationState()->lease_token);
        self::assertNull(DB::table('payments')->find($ids['payment_id'])->stripe_payment_intent_id);
        self::assertFalse(DB::table('payment_journal_entries')->where('entry_type', 'payment_intent_created')->exists());
    }

    public function test_executor_holds_an_expired_request_without_contacting_stripe(): void
    {
        [$executor, $ids] = $this->executionFixture();
        $this->travel(24)->hours();
        try {
            self::assertSame('reconciliation_required', $executor->executeInitial($ids['operation_id'], 1)['status']);
            self::assertSame('retry_window_closed', $this->operationState()->last_error_code);
            self::assertSame(0, (int) $this->operationState()->attempts);
        } finally {
            $this->travelBack();
        }
    }

    public function test_executor_holds_a_request_after_credential_rotation(): void
    {
        [$executor, $ids] = $this->executionFixture();
        config(['services.stripe.secret' => 'sk_test_rotated_executor_fixture']);

        self::assertSame('reconciliation_required', $executor->executeInitial($ids['operation_id'], 1)['status']);
        self::assertSame('credentials_changed', $this->operationState()->last_error_code);
        self::assertSame(0, (int) $this->operationState()->attempts);
    }

    public function test_executor_rechecks_the_customer_and_current_authorization_before_contacting_stripe(): void
    {
        [$executor, $ids] = $this->executionFixture();
        $this->denied(fn () => $executor->executeInitial($ids['operation_id'], 2), 403);
        try {
            DB::transaction(fn () => $executor->executeInitial($ids['operation_id'], 1));
            self::fail('Execution must follow transaction commit.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('committed reservation', $exception->getMessage());
        }
        $this->job(['status' => 'cancelled']);
        $this->denied(fn () => $executor->executeInitial($ids['operation_id'], 1), 409);
        self::assertSame(0, (int) $this->operationState()->attempts);
    }

    public function test_executor_recovers_the_same_intent_after_a_local_commit_failure(): void
    {
        [$executor, $ids, $saved] = $this->executionFixture();
        $this->expectedStripeResponses = 2;
        Http::fake(function (Request $request) use ($saved) {
            self::assertTrue($request->hasHeader('Idempotency-Key', $saved->idempotency_key));

            return $this->executorResponse($request);
        });
        Schema::create('executor_fixture_fault', function (Blueprint $table): void {
            $table->boolean('enabled');
        });
        DB::table('executor_fixture_fault')->insert(['enabled' => true]);
        DB::unprepared("CREATE TRIGGER executor_fixture_abort BEFORE INSERT ON payment_journal_entries
            WHEN NEW.entry_type = 'payment_intent_created' AND (SELECT enabled FROM executor_fixture_fault) = 1
            BEGIN SELECT RAISE(ABORT, 'executor_fixture_abort'); END");

        try {
            $executor->executeInitial($ids['operation_id'], 1);
            self::fail('The test fault must roll back local finalization.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('executor_fixture_abort', $exception->getMessage());
        }
        self::assertNull(DB::table('payments')->find($ids['payment_id'])->stripe_payment_intent_id);
        self::assertSame('processing', $this->operationState()->status);
        self::assertSame(1, (int) $this->operationState()->attempts);

        DB::table('executor_fixture_fault')->update(['enabled' => false]);
        DB::table('stripe_operation_processing')->update(['lease_expires_at' => now()->subSecond()]);
        self::assertSame('pi_executor_fixture', $executor->executeInitial($ids['operation_id'], 1)['stripe_payment_intent_id']);
        self::assertSame(1, DB::table('payment_journal_entries')->where('entry_type', 'payment_intent_created')->count());
    }

    public function test_executor_leaves_an_indeterminate_server_error_for_reconciliation(): void
    {
        [$executor, $ids] = $this->executionFixture();
        $this->expectedStripeResponses = 1;
        Http::fake(fn () => Http::response(['error' => ['message' => 'fixture provider detail']], 500));

        self::assertSame('reconciliation_required', $executor->executeInitial($ids['operation_id'], 1)['status']);
        self::assertSame('reconciliation_required', $executor->executeInitial($ids['operation_id'], 1)['status']);
        self::assertSame('http_error_500', $this->operationState()->last_error_code);
        $journal = DB::table('payment_journal_entries')->where('entry_type', 'intent_creation_failed')->first();
        self::assertStringNotContainsString('fixture provider detail', $journal->details);
    }

    public function test_executor_limits_transport_retries_to_three_attempts(): void
    {
        [$executor, $ids, $saved] = $this->executionFixture();
        $attempts = 0;
        Http::fake(function (Request $request) use (&$attempts, $saved) {
            self::assertTrue($request->hasHeader('Idempotency-Key', $saved->idempotency_key));
            $attempts++;
            throw new ConnectionException('Fixture network timeout');
        });

        foreach (['pending', 'pending', 'reconciliation_required', 'reconciliation_required'] as $status) {
            self::assertSame($status, $executor->executeInitial($ids['operation_id'], 1)['status']);
        }
        self::assertSame(3, $attempts);
        self::assertSame(3, (int) $this->operationState()->attempts);
    }

    public function test_verified_success_records_one_funding_fact_and_unlocks_progress(): void
    {
        [$executor, $ids] = $this->executionFixture();
        $this->expectedStripeResponses = 1;
        Http::fake(fn (Request $request) => $this->executorResponse($request));
        self::assertSame('completed', $executor->executeInitial($ids['operation_id'], 1)['status']);
        config(['payments.enabled' => false]);

        $event = $this->fundingEvent($ids, 'evt_funding_success');
        $reconciler = app(StripePaymentIntentReconciler::class);
        $receipt = $this->receiveFundingEvent($event);
        self::assertSame('processed', $reconciler->process($receipt));
        self::assertSame($receipt, $this->receiveFundingEvent($event));
        self::assertSame('already_processed', $reconciler->process($receipt));

        $event['id'] = 'evt_funding_success_again';
        $duplicate = $this->receiveFundingEvent($event);
        self::assertSame('ignored', $reconciler->process($duplicate));

        $payment = DB::table('payments')->find($ids['payment_id']);
        self::assertSame('succeeded', $payment->status);
        self::assertNotNull($payment->paid_at);
        $entries = DB::table('payment_journal_entries')->where('entry_type', 'payment_succeeded')->get();
        self::assertCount(1, $entries);
        $entry = $entries->first();
        self::assertSame($ids['payment_id'], (int) $entry->payment_id);
        self::assertSame($receipt, (int) $entry->stripe_event_receipt_id);
        self::assertSame(12500, (int) $entry->amount_cents);
        self::assertSame('usd', $entry->currency);
        self::assertSame('pi_executor_fixture', $entry->stripe_object_id);
        self::assertSame(['stripe_charge_id' => 'ch_funding_fixture'],
            json_decode($entry->details, true, 512, JSON_THROW_ON_ERROR));

        DB::transaction(function (): void {
            Job::query()->lockForUpdate()->findOrFail(1);
            app(PaymentAgreementService::class)->assertJobStatusChangeAllowed(1, 'in_progress');
        });
    }

    public function test_early_success_retries_after_the_reserved_intent_is_bound(): void
    {
        [$executor, $ids] = $this->executionFixture();
        $receipt = $this->receiveFundingEvent($this->fundingEvent($ids, 'evt_funding_early'));
        $this->assertFundingRetry($receipt, 'Stripe intent binding is pending.');
        self::assertSame('requires_payment_method', DB::table('payments')->find($ids['payment_id'])->status);
        self::assertSame(0, DB::table('payment_journal_entries')->where('entry_type', 'payment_succeeded')->count());

        $this->expectedStripeResponses = 1;
        Http::fake(fn (Request $request) => $this->executorResponse($request));
        self::assertSame('completed', $executor->executeInitial($ids['operation_id'], 1)['status']);
        self::assertSame('processed', app(StripePaymentIntentReconciler::class)->process($receipt));
        $state = DB::table('stripe_event_processing')->where('stripe_event_receipt_id', $receipt)->first();
        self::assertSame('processed', $state->status);
        self::assertSame(2, (int) $state->attempts);
    }

    public function test_signed_success_with_wrong_money_scope_or_account_cannot_fund_a_payment(): void
    {
        [$executor, $ids] = $this->executionFixture();
        $this->expectedStripeResponses = 1;
        Http::fake(fn (Request $request) => $this->executorResponse($request));
        self::assertSame('completed', $executor->executeInitial($ids['operation_id'], 1)['status']);

        $event = $this->fundingEvent($ids, 'evt_funding_underpaid');
        $event['data']['object']['amount_received'] = 12499;
        $this->assertFundingRetry($this->receiveFundingEvent($event),
            'Stripe funding snapshot does not match reserved terms.');

        $event = $this->fundingEvent($ids, 'evt_funding_wrong_scope');
        $event['data']['object']['metadata']['trustfix_agreement_sha256'] = str_repeat('0', 64);
        $this->assertFundingRetry($this->receiveFundingEvent($event),
            'Stripe funding metadata does not match reserved terms.');

        $event = $this->fundingEvent($ids, 'evt_funding_wrong_account');
        $event['account'] = 'acct_funding_other';
        $this->assertFundingRetry($this->receiveFundingEvent($event),
            'Stripe funding snapshot does not match reserved terms.');

        $event = $this->fundingEvent($ids, 'evt_funding_wrong_version');
        $event['api_version'] = '2026-01-01.unexpected';
        $this->assertFundingRetry($this->receiveFundingEvent($event),
            'Stripe funding snapshot does not match reserved terms.');

        $payment = DB::table('payments')->find($ids['payment_id']);
        self::assertSame('requires_payment_method', $payment->status);
        self::assertNull($payment->paid_at);
        self::assertSame(0, DB::table('payment_journal_entries')->where('entry_type', 'payment_succeeded')->count());
    }

    public function test_late_success_cannot_overwrite_cancelled_or_refunded_payment_state(): void
    {
        [$executor, $ids] = $this->executionFixture();
        $this->expectedStripeResponses = 1;
        Http::fake(fn (Request $request) => $this->executorResponse($request));
        self::assertSame('completed', $executor->executeInitial($ids['operation_id'], 1)['status']);

        foreach (['cancelled', 'refunded'] as $status) {
            DB::table('payments')->where('id', $ids['payment_id'])->update(['status' => $status]);
            $receipt = $this->receiveFundingEvent($this->fundingEvent($ids, 'evt_funding_late_' . $status));
            $this->assertFundingRetry($receipt, 'Stripe funding conflicts with the local payment state.');
            self::assertSame($status, DB::table('payments')->find($ids['payment_id'])->status);
        }

        self::assertSame(0, DB::table('payment_journal_entries')->where('entry_type', 'payment_succeeded')->count());
    }

    private function fundingEvent(array $ids, string $eventId): array
    {
        $installment = DB::table('payment_installments')->find($ids['installment_id']);
        $agreement = DB::table('payment_agreements')->find($installment->payment_agreement_id);

        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'api_version' => StripePaymentGateway::API_VERSION,
            'created' => time(),
            'livemode' => false,
            'data' => ['object' => [
                'id' => 'pi_executor_fixture',
                'object' => 'payment_intent',
                'livemode' => false,
                'status' => 'succeeded',
                'capture_method' => 'automatic',
                'amount' => (int) $installment->amount_cents,
                'amount_received' => (int) $installment->amount_cents,
                'currency' => $installment->currency,
                'latest_charge' => 'ch_funding_fixture',
                'transfer_group' => $installment->transfer_group,
                'metadata' => [
                    'trustfix_payment_model' => StripePaymentGateway::PAYMENT_MODEL,
                    'trustfix_job_id' => (string) $agreement->job_id,
                    'trustfix_payment_id' => (string) $ids['payment_id'],
                    'trustfix_installment_id' => (string) $installment->id,
                    'trustfix_agreement_id' => (string) $agreement->id,
                    'trustfix_agreement_sha256' => $agreement->agreement_sha256,
                ],
            ]],
        ];
    }

    private function receiveFundingEvent(array $event): int
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', "$timestamp.$payload", config('services.stripe.webhook_secret'));

        return app(StripeEventInbox::class)->receive($payload, "t=$timestamp,v1=$signature");
    }

    private function assertFundingRetry(int $receipt, string $message): void
    {
        try {
            app(StripePaymentIntentReconciler::class)->process($receipt);
            self::fail('Expected funding reconciliation to require retry or review.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        $state = DB::table('stripe_event_processing')->where('stripe_event_receipt_id', $receipt)->first();
        self::assertSame('failed', $state->status);
        self::assertSame(1, (int) $state->attempts);
        self::assertNull($state->processed_at);
    }

    public function test_webhook_requires_a_signature_and_routes_reserved_funding_once(): void
    {
        [$executor, $ids] = $this->executionFixture();
        $this->expectedStripeResponses = 1;
        Http::fake(fn (Request $request) => $this->executorResponse($request));
        self::assertSame('completed', $executor->executeInitial($ids['operation_id'], 1)['status']);

        $success = $this->fundingEvent($ids, 'evt_webhook_funding');
        $this->postStripeSnapshot($success, false)->assertStatus(400);
        self::assertSame(0, DB::table('stripe_event_receipts')->count());

        $processing = $success;
        $processing['id'] = 'evt_webhook_reserved_processing';
        $processing['type'] = 'payment_intent.processing';
        $processing['data']['object']['status'] = 'processing';
        unset($processing['data']['object']['metadata']);
        $this->postStripeSnapshot($processing)->assertOk();
        self::assertSame('requires_payment_method', DB::table('payments')->find($ids['payment_id'])->status);

        $this->postStripeSnapshot($success)->assertOk()->assertExactJson(['received' => true]);
        $this->postStripeSnapshot($success)->assertOk();
        $late = $processing;
        $late['id'] = 'evt_webhook_reserved_late_failure';
        $late['type'] = 'payment_intent.payment_failed';
        $late['data']['object']['status'] = 'requires_payment_method';
        $this->postStripeSnapshot($late)->assertOk();

        self::assertSame('succeeded', DB::table('payments')->find($ids['payment_id'])->status);
        self::assertSame(1, DB::table('payment_journal_entries')->where('entry_type', 'payment_succeeded')->count());
        self::assertSame(3, DB::table('stripe_event_receipts')->count());
        self::assertSame(2, DB::table('stripe_event_processing')->where('status', 'ignored')->count());
    }

    public function test_webhook_returns_retry_until_an_early_success_can_be_reconciled(): void
    {
        [$executor, $ids] = $this->executionFixture();
        $event = $this->fundingEvent($ids, 'evt_webhook_retry');
        $receipt = $this->receiveFundingEvent($event);
        $state = DB::table('stripe_event_processing')->where('stripe_event_receipt_id', $receipt);
        $state->update([
            'status' => 'processing',
            'attempts' => 1,
            'lease_token' => str_repeat('a', 64),
            'lease_expires_at' => now()->addMinute(),
        ]);
        $this->postStripeSnapshot($event)->assertStatus(503);
        self::assertSame(1, (int) (clone $state)->value('attempts'));

        $state->update(['lease_expires_at' => now()->subSecond()]);
        $this->postStripeSnapshot($event)->assertStatus(503);
        self::assertSame('failed', (clone $state)->value('status'));

        $this->expectedStripeResponses = 1;
        Http::fake(fn (Request $request) => $this->executorResponse($request));
        self::assertSame('completed', $executor->executeInitial($ids['operation_id'], 1)['status']);
        $this->postStripeSnapshot($event)->assertOk();
        self::assertSame('processed', (clone $state)->value('status'));
        self::assertSame(3, (int) (clone $state)->value('attempts'));
        self::assertSame(1, DB::table('stripe_event_receipts')->count());
    }

    public function test_webhook_keeps_legacy_updates_without_reopening_a_terminal_payment(): void
    {
        $this->checkout();
        $id = $this->payment(['stripe_payment_intent_id' => 'pi_webhook_legacy']);
        $event = [
            'id' => 'evt_webhook_legacy_processing',
            'object' => 'event',
            'type' => 'payment_intent.processing',
            'created' => time(),
            'livemode' => false,
            'data' => ['object' => [
                'id' => 'pi_webhook_legacy', 'object' => 'payment_intent', 'status' => 'processing',
            ]],
        ];
        $this->postStripeSnapshot($event)->assertOk();
        $payment = DB::table('payments')->where('id', $id);
        self::assertSame('processing', (clone $payment)->value('status'));
        self::assertSame(['stripe_event_id' => 'evt_webhook_legacy_processing'],
            json_decode((clone $payment)->value('metadata'), true, 512, JSON_THROW_ON_ERROR));

        $payment->update(['status' => 'succeeded']);
        $event['id'] = 'evt_webhook_legacy_late_failure';
        $event['type'] = 'payment_intent.payment_failed';
        $event['data']['object']['status'] = 'requires_payment_method';
        $this->postStripeSnapshot($event)->assertOk();
        self::assertSame('succeeded', (clone $payment)->value('status'));
        self::assertSame(0, DB::table('payment_journal_entries')->where('entry_type', 'payment_succeeded')->count());
    }

    public function test_webhook_records_and_deduplicates_connected_account_updates(): void
    {
        $this->checkout();
        Schema::create('contractor_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('stripe_account_id');
            $table->boolean('stripe_details_submitted')->default(false);
            $table->boolean('stripe_charges_enabled')->default(false);
            $table->boolean('stripe_payouts_enabled')->default(false);
            $table->timestamps();
        });
        DB::table('contractor_profiles')->insert(['stripe_account_id' => 'acct_webhook_fixture']);
        $event = [
            'id' => 'evt_webhook_account',
            'object' => 'event',
            'type' => 'account.updated',
            'account' => 'acct_webhook_fixture',
            'created' => time(),
            'livemode' => false,
            'data' => ['object' => [
                'id' => 'acct_webhook_fixture',
                'object' => 'account',
                'details_submitted' => true,
                'charges_enabled' => false,
                'payouts_enabled' => true,
            ]],
        ];

        $this->postStripeSnapshot($event)->assertOk();
        $profile = DB::table('contractor_profiles')->first();
        self::assertSame(1, (int) $profile->stripe_details_submitted);
        self::assertSame(0, (int) $profile->stripe_charges_enabled);
        self::assertSame(1, (int) $profile->stripe_payouts_enabled);
        $this->postStripeSnapshot($event)->assertOk();
        self::assertSame(1, DB::table('stripe_event_receipts')->count());
        self::assertSame(1, (int) DB::table('stripe_event_processing')->value('attempts'));
    }

    public function test_webhook_retries_and_records_reserved_cancellation_once(): void
    {
        [$executor, $ids] = $this->executionFixture();
        $event = $this->cancellationEvent($ids, 'evt_cancellation_early');
        $this->postStripeSnapshot($event)->assertStatus(503);
        $receiptId = DB::table('stripe_event_receipts')->value('id');
        self::assertSame('failed', DB::table('stripe_event_processing')
            ->where('stripe_event_receipt_id', $receiptId)->value('status'));

        $this->expectedStripeResponses = 1;
        Http::fake(fn (Request $request) => $this->executorResponse($request));
        self::assertSame('completed', $executor->executeInitial($ids['operation_id'], 1)['status']);
        config(['payments.enabled' => false]);

        $this->postStripeSnapshot($event)->assertOk();
        $this->postStripeSnapshot($event)->assertOk();
        $event['id'] = 'evt_cancellation_duplicate';
        $this->postStripeSnapshot($event)->assertOk();
        $late = $event;
        $late['id'] = 'evt_processing_after_cancellation';
        $late['type'] = 'payment_intent.processing';
        $late['data']['object']['status'] = 'processing';
        $this->postStripeSnapshot($late)->assertOk();

        $state = DB::table('stripe_event_processing')->where('stripe_event_receipt_id', $receiptId)->first();
        self::assertSame('processed', $state->status);
        self::assertSame(2, (int) $state->attempts);
        $payment = DB::table('payments')->find($ids['payment_id']);
        self::assertSame('cancelled', $payment->status);
        self::assertNull($payment->paid_at);
        self::assertSame('accepted', Job::findOrFail(1)->status);
        $entries = DB::table('payment_journal_entries')->where('entry_type', 'payment_intent_cancelled')->get();
        self::assertCount(1, $entries);
        $entry = $entries->first();
        self::assertSame((int) $receiptId, (int) $entry->stripe_event_receipt_id);
        self::assertSame($ids['payment_id'], (int) $entry->payment_id);
        self::assertSame(0, (int) $entry->amount_cents);
        self::assertSame('usd', $entry->currency);
        self::assertSame('pi_executor_fixture', $entry->stripe_object_id);
        self::assertSame(['stripe_canceled_at' => $event['data']['object']['canceled_at']],
            json_decode($entry->details, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(0, DB::table('payment_journal_entries')->where('entry_type', 'payment_succeeded')->count());
    }

    public function test_webhook_rejects_mismatched_or_funded_reserved_cancellation(): void
    {
        [$executor, $ids] = $this->executionFixture();
        $this->expectedStripeResponses = 1;
        Http::fake(fn (Request $request) => $this->executorResponse($request));
        self::assertSame('completed', $executor->executeInitial($ids['operation_id'], 1)['status']);

        $event = $this->cancellationEvent($ids, 'evt_cancellation_nonzero');
        $event['data']['object']['amount_received'] = 1;
        $this->postStripeSnapshot($event)->assertStatus(503);
        $event = $this->cancellationEvent($ids, 'evt_cancellation_wrong_account');
        $event['account'] = 'acct_cancellation_other';
        $this->postStripeSnapshot($event)->assertStatus(503);
        $event = $this->cancellationEvent($ids, 'evt_cancellation_wrong_scope');
        $event['data']['object']['metadata']['trustfix_agreement_sha256'] = str_repeat('0', 64);
        $this->postStripeSnapshot($event)->assertStatus(503);
        self::assertSame('requires_payment_method', DB::table('payments')->find($ids['payment_id'])->status);

        $this->postStripeSnapshot($this->fundingEvent($ids, 'evt_cancellation_prior_funding'))->assertOk();
        $this->postStripeSnapshot($this->cancellationEvent($ids, 'evt_cancellation_funded'))->assertStatus(503);
        self::assertSame('succeeded', DB::table('payments')->find($ids['payment_id'])->status);
        self::assertSame(1, DB::table('payment_journal_entries')->where('entry_type', 'payment_succeeded')->count());
        self::assertSame(0, DB::table('payment_journal_entries')->where('entry_type', 'payment_intent_cancelled')->count());
        self::assertSame(4, DB::table('stripe_event_processing')->where('status', 'failed')->count());
    }

    public function test_webhook_keeps_legacy_cancellation_without_creating_reserved_history(): void
    {
        $this->checkout();
        $id = $this->payment(['stripe_payment_intent_id' => 'pi_legacy_cancel_fixture']);
        $this->postStripeSnapshot([
            'id' => 'evt_legacy_cancellation',
            'object' => 'event',
            'type' => 'payment_intent.canceled',
            'created' => time(),
            'livemode' => false,
            'data' => ['object' => [
                'id' => 'pi_legacy_cancel_fixture', 'object' => 'payment_intent', 'status' => 'canceled',
            ]],
        ])->assertOk();
        self::assertSame('cancelled', DB::table('payments')->find($id)->status);
        self::assertSame(0, DB::table('payment_journal_entries')->count());
    }

    private function cancellationEvent(array $ids, string $eventId): array
    {
        $event = $this->fundingEvent($ids, $eventId);
        $event['type'] = 'payment_intent.canceled';
        $event['data']['object']['status'] = 'canceled';
        $event['data']['object']['amount_received'] = 0;
        $event['data']['object']['canceled_at'] = $event['created'];
        $event['data']['object']['latest_charge'] = null;

        return $event;
    }

    public function test_checkout_api_requires_participant_consent_for_the_current_agreement(): void
    {
        $this->checkout();
        $this->postJson('/api/jobs/1/payment-intent')->assertStatus(401);
        $this->actAsJobUser(3, 'customer');
        $this->postJson('/api/jobs/1/payment-agreement')->assertForbidden();
        $this->actAsJobUser(1, 'customer');
        $agreement = $this->postJson('/api/jobs/1/payment-agreement')->assertOk()
            ->assertJsonPath('agreement.amount_cents', 12500)->json('agreement');
        $data = ['agreement_id' => $agreement['id'], 'agreement_sha256' => $agreement['agreement_sha256']];
        $this->postJson('/api/jobs/1/payment-intent')->assertStatus(422);
        $this->postJson('/api/jobs/1/payment-intent', $data)->assertStatus(409);
        $decision = [
            'agreement_sha256' => $agreement['agreement_sha256'],
            'event_type' => 'scope_accepted', 'decision_key' => 'fixture_api_customer_consent',
        ];
        $url = '/api/payment-agreements/' . $agreement['id'] . '/decisions';
        $first = $this->postJson($url, $decision)->assertOk()->assertJsonPath('decision.actor_role', 'customer');
        $this->postJson($url, $decision)->assertOk()->assertJsonPath('decision.id', $first->json('decision.id'));
        $this->postJson('/api/jobs/1/payment-intent', $data)->assertStatus(409);

        $this->actAsJobUser(2, 'handyman');
        $decision['decision_key'] = 'fixture_api_contractor_consent';
        $this->postJson($url, $decision)->assertOk()->assertJsonPath('decision.actor_role', 'contractor');
        $this->postJson('/api/jobs/1/payment-agreement')->assertOk()
            ->assertJsonPath('agreement.consents.customer', true)->assertJsonPath('agreement.consents.contractor', true);
        $this->postJson('/api/jobs/1/payment-intent', $data)->assertForbidden();
        $this->getJson('/api/jobs/1/payment-intent')->assertForbidden();
        $this->actAsJobUser(1, 'customer');
        $data['agreement_sha256'] = str_repeat('0', 64);
        $this->postJson('/api/jobs/1/payment-intent', $data)->assertStatus(409);
        self::assertSame(0, DB::table('payments')->count());
    }

    public function test_checkout_api_reuses_the_intent_and_reads_current_status_without_exposing_the_secret(): void
    {
        $data = $this->approvedCheckoutRequest();
        $this->expectedStripeResponses = 6;
        $status = 'requires_payment_method';
        $details = [];
        Http::fake(function (Request $request) use (&$status, &$details) {
            return $request->method() === 'POST' ? $this->executorResponse($request)
                : $this->checkoutIntentResponse($request, $status, $details);
        });
        $url = '/api/jobs/1/payment-intent';
        $first = $this->postJson($url, $data)->assertOk()->assertJsonPath('checkout_status', 'ready')
            ->assertJsonPath('client_secret', 'pi_executor_fixture_secret_fixture');
        self::assertStringContainsString('no-store', (string) $first->baseResponse->headers->get('Cache-Control'));
        $status = 'requires_action';
        $this->postJson($url, $data)->assertOk()->assertJsonPath('stripe_status', 'requires_action')
            ->assertJsonPath('payment.id', $first->json('payment.id'));

        $status = 'requires_payment_method';
        $details = ['last_payment_error' => ['code' => 'card_declined', 'message' => 'Private fixture error']];
        config(['payments.enabled' => false]);
        $read = $this->getJson($url)->assertOk()->assertJsonPath('payment_failed', true)
            ->assertJsonPath('stripe_status', 'requires_payment_method')->assertJsonMissingPath('client_secret');
        self::assertStringNotContainsString('Private fixture error', $read->getContent());

        $status = 'processing';
        $details = ['amount_received' => 12500];
        $this->getJson($url)->assertOk()->assertJsonPath('checkout_status', 'processing')
            ->assertJsonMissingPath('client_secret');
        $status = 'canceled';
        $details = [];
        $this->getJson($url)->assertOk()->assertJsonPath('checkout_status', 'awaiting_cancellation')
            ->assertJsonMissingPath('client_secret');
        $this->postStripeSnapshot($this->cancellationEvent($this->checkoutFixtureIds(), 'evt_checkout_api_cancelled'))
            ->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('checkout_status', 'cancelled');
        self::assertSame(1, DB::table('payments')->count());
        self::assertSame(1, DB::table('stripe_operation_requests')->count());
    }

    public function test_checkout_api_waits_for_the_signed_funding_event(): void
    {
        $data = $this->approvedCheckoutRequest();
        $this->expectedStripeResponses = 2;
        Http::fake(fn (Request $request) => $request->method() === 'POST'
            ? $this->executorResponse($request) : $this->checkoutIntentResponse($request, 'succeeded'));
        $url = '/api/jobs/1/payment-intent';
        $this->postJson($url, $data)->assertOk()->assertJsonPath('checkout_status', 'awaiting_confirmation')
            ->assertJsonPath('payment.status', 'requires_payment_method')->assertJsonMissingPath('client_secret');
        self::assertSame(0, DB::table('payment_journal_entries')->where('entry_type', 'payment_succeeded')->count());

        $event = $this->fundingEvent($this->checkoutFixtureIds(), 'evt_checkout_api_funded');
        $this->postStripeSnapshot($event)->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('checkout_status', 'succeeded')
            ->assertJsonMissingPath('client_secret');
    }

    public function test_checkout_api_rechecks_eligibility_after_the_provider_read(): void
    {
        $data = $this->approvedCheckoutRequest();
        $this->expectedStripeResponses = 2;
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST') {
                return $this->executorResponse($request);
            }

            self::assertSame(0, DB::transactionLevel());
            DB::table('disputes')->insert(['job_id' => 1, 'status' => 'open']);

            return $this->checkoutIntentResponse($request, 'requires_payment_method');
        });
        $this->postJson('/api/jobs/1/payment-intent', $data)->assertStatus(409)->assertJsonMissingPath('client_secret');
        self::assertSame('requires_payment_method', DB::table('payments')->first()->status);
    }

    public function test_checkout_api_rejects_a_provider_response_with_different_terms(): void
    {
        $data = $this->approvedCheckoutRequest();
        $this->expectedStripeResponses = 2;
        Http::fake(fn (Request $request) => $request->method() === 'POST' ? $this->executorResponse($request)
            : $this->checkoutIntentResponse($request, 'requires_payment_method', ['amount' => 12501]));
        $this->postJson('/api/jobs/1/payment-intent', $data)->assertStatus(409)->assertJsonMissingPath('client_secret');
        self::assertSame(1, DB::table('payments')->count());
    }

    private function approvedCheckoutRequest(): array
    {
        $this->checkout();
        $agreement = $this->proposal();
        $this->both($agreement);
        $this->actAsJobUser(1, 'customer');

        return ['agreement_id' => (int) $agreement->id, 'agreement_sha256' => $agreement->agreement_sha256];
    }

    private function checkoutFixtureIds(): array
    {
        $installment = DB::table('payment_installments')->first();

        return ['installment_id' => (int) $installment->id, 'payment_id' => (int) $installment->payment_id];
    }

    private function checkoutIntentResponse(Request $request, string $status, array $overrides = [])
    {
        self::assertSame(0, DB::transactionLevel());
        self::assertSame('GET', $request->method());
        self::assertSame('https://api.stripe.com/v1/payment_intents/pi_executor_fixture', $request->url());
        $intent = $this->fundingEvent($this->checkoutFixtureIds(), 'evt_checkout_read_fixture')['data']['object'];
        $intent['status'] = $status;
        $intent['amount_received'] = $status === 'succeeded' ? $intent['amount'] : 0;
        $intent['client_secret'] = 'pi_executor_fixture_secret_fixture';

        return Http::response(array_replace($intent, $overrides), 200, ['Request-Id' => 'req_checkout_read_fixture']);
    }

    private function postStripeSnapshot(array $event, bool $valid = true): \Illuminate\Testing\TestResponse
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', "$timestamp.$payload", config('services.stripe.webhook_secret'));

        return $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $valid ? "t=$timestamp,v1=$signature" : 'invalid',
        ], $payload);
    }

    private function executionFixture(): array
    {
        $agreement = $this->proposal();
        $this->both($agreement);
        $ids = $this->checkout()->reserveInitial(1, (int) $agreement->id, 1, $agreement->agreement_sha256);

        return [
            app(StripePaymentIntentExecutor::class), $ids,
            DB::table('stripe_operation_requests')->find($ids['operation_id']),
        ];
    }

    private function operationState(): object
    {
        return DB::table('stripe_operation_processing')->first();
    }

    private function executorResponse(Request $request)
    {
        self::assertSame(0, DB::transactionLevel());
        self::assertSame('POST', $request->method());
        self::assertSame('https://api.stripe.com/v1/payment_intents', $request->url());
        $data = $request->data();

        return Http::response([
            'id' => 'pi_executor_fixture', 'object' => 'payment_intent', 'livemode' => false,
            'amount' => (int) $data['amount'], 'currency' => $data['currency'],
            'transfer_group' => $data['transfer_group'], 'metadata' => array_map('strval', $data['metadata']),
            'capture_method' => 'automatic', 'status' => 'requires_payment_method',
            'client_secret' => 'pi_executor_fixture_secret_fixture',
        ], 200, ['Request-Id' => 'req_executor_fixture']);
    }

    public function test_reserved_terms_block_customer_and_admin_edits_while_collection_is_paused(): void
    {
        $this->reserve($this->proposal());
        config(['payments.enabled' => false]);
        $payload = [
            'status' => 'accepted',
            'address' => 'Fixture address',
            'initial_description' => 'Fixture agreed scope',
            'agreed_price' => '130.00',
            'skills' => ['plumbing', 'repair'],
        ];

        $this->actAsJobUser(1, 'customer');
        $this->denied(fn () => app(JobController::class)->update(
            ControllerRequest::create('/api/jobs/1', 'PUT', $payload), 1
        ), 409);
        $this->actAsJobUser(3, 'admin');
        $this->denied(fn () => app(AdminDashboardController::class)->updateJob(
            ControllerRequest::create('/api/admin/jobs/1', 'PUT', $payload), 1
        ), 409);

        self::assertSame('125.00', Job::findOrFail(1)->agreed_price);
        self::assertSame(1, DB::table('payment_installments')->count());
    }

    public function test_a_change_order_requires_counterparty_approval_and_updates_price_once(): void
    {
        $controller = app(ChangeOrderController::class);
        $this->actAsJobUser(2, 'handyman');
        $created = $controller->store(ControllerRequest::create('/api/jobs/1/change-orders', 'POST', [
            'description' => 'Additional fixture work',
            'price_delta' => '25.00',
        ]), 1);
        self::assertSame(201, $created->getStatusCode());
        $id = $created->getData(true)['id'];
        $approve = ControllerRequest::create("/api/change-orders/$id/status", 'POST', ['status' => 'approved']);

        self::assertSame(403, $controller->updateStatus($approve, $id)->getStatusCode());
        self::assertSame('125.00', Job::findOrFail(1)->agreed_price);
        $this->actAsJobUser(1, 'customer');
        self::assertSame(200, $controller->updateStatus($approve, $id)->getStatusCode());
        self::assertSame(409, $controller->updateStatus($approve, $id)->getStatusCode());
        self::assertSame('150.00', Job::findOrFail(1)->agreed_price);
    }

    public function test_reserved_terms_block_quote_acceptance_before_writing_estimate(): void
    {
        $this->reserve($this->proposal());
        Schema::create('job_images', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('job_id');
        });
        Schema::create('job_estimates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('job_id');
            $table->string('status');
            $table->decimal('contractor_quote', 10, 2);
        });
        DB::table('job_estimates')->insert(['job_id' => 1, 'status' => 'quoted', 'contractor_quote' => '135.00']);
        $this->actAsJobUser(1, 'customer');
        $this->denied(fn () => app(JobEstimateController::class)->accept(
            ControllerRequest::create('/api/jobs/1/estimate/accept', 'POST'), 1
        ), 409);

        self::assertSame('quoted', DB::table('job_estimates')->first()->status);
        self::assertSame('125.00', Job::findOrFail(1)->agreed_price);
    }

    public function test_reserved_terms_block_change_order_creation_and_approval(): void
    {
        $this->reserve($this->proposal());
        $this->change(11, 'pending', '25.00');
        $this->actAsJobUser(1, 'customer');
        $controller = app(ChangeOrderController::class);
        $this->denied(fn () => $controller->store(
            ControllerRequest::create('/api/jobs/1/change-orders', 'POST', [
                'description' => 'Additional fixture work', 'price_delta' => '25.00',
            ]), 1
        ), 409);
        $this->denied(fn () => $controller->updateStatus(
            ControllerRequest::create('/api/change-orders/11/status', 'POST', ['status' => 'approved']), 11
        ), 409);

        self::assertSame(1, DB::table('change_orders')->count());
        self::assertSame('pending', DB::table('change_orders')->find(11)->status);
        self::assertSame('125.00', Job::findOrFail(1)->agreed_price);
    }

    public function test_reserved_job_blocks_unfunded_progress_and_generic_cancel_and_delete(): void
    {
        $this->reserve($this->proposal());
        config(['payments.enabled' => false]);
        $controller = app(JobController::class);
        $this->actAsJobUser(2, 'handyman');
        $this->denied(fn () => $controller->startJob(1), 409);
        $this->denied(fn () => $controller->updateStatus(
            ControllerRequest::create('/api/jobs/1/status', 'POST', ['status' => 'in_progress']), 1
        ), 409);
        $this->job(['status' => 'in_progress']);
        $this->denied(fn () => $controller->completeJob(1), 409);

        $this->actAsJobUser(1, 'customer');
        $this->denied(fn () => $controller->cancelJob(1), 409);
        $this->denied(fn () => $controller->updateStatus(
            ControllerRequest::create('/api/jobs/1/status', 'POST', ['status' => 'cancelled']), 1
        ), 409);
        $this->denied(fn () => $controller->destroy(1), 409);
        self::assertSame('in_progress', Job::findOrFail(1)->status);
        self::assertSame(1, DB::table('payment_installments')->count());
    }

    public function test_reserved_progress_requires_matching_success_fact_and_no_open_dispute(): void
    {
        $this->checkout();
        $this->reserve($this->proposal());
        $paymentId = DB::table('payments')->value('id');
        DB::table('payments')->where('id', $paymentId)->update([
            'status' => 'succeeded', 'stripe_payment_intent_id' => 'pi_funding_fixture',
        ]);

        $advance = fn () => DB::transaction(function (): bool {
            Job::query()->lockForUpdate()->findOrFail(1);
            app(PaymentAgreementService::class)->assertJobStatusChangeAllowed(1, 'in_progress');

            return true;
        });
        $this->denied($advance, 409);

        foreach ([12499, 12500] as $amount) {
            $payload = json_encode(['id' => 'evt_funding_' . $amount], JSON_THROW_ON_ERROR);
            $receiptId = DB::table('stripe_event_receipts')->insertGetId([
                'stripe_event_id' => 'evt_funding_' . $amount,
                'event_type' => 'payment_intent.succeeded',
                'api_version' => '2026-10-28.endive',
                'livemode' => false,
                'stripe_created_at' => time(),
                'payload_encrypted' => Crypt::encryptString($payload),
                'payload_sha256' => hash('sha256', $payload),
            ]);
            DB::table('payment_journal_entries')->insert([
                'payment_id' => $paymentId,
                'entry_key' => 'funding_fixture_' . $amount,
                'entry_type' => 'payment_succeeded',
                'amount_cents' => $amount,
                'currency' => 'usd',
                'stripe_object_id' => 'pi_funding_fixture',
                'stripe_event_receipt_id' => $receiptId,
            ]);
            if ($amount === 12499) {
                $this->denied($advance, 409);
            }
        }

        self::assertTrue($advance());
        DB::table('disputes')->insert(['job_id' => 1, 'status' => 'open']);
        $this->denied($advance, 409);
    }

    public function test_reserved_job_dispute_resolution_does_not_advance_work(): void
    {
        Schema::table('disputes', function (Blueprint $table): void {
            $table->unsignedBigInteger('opened_by')->nullable();
            $table->string('reason')->nullable();
            $table->text('details')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
        });
        $this->reserve($this->proposal());
        $this->job(['status' => 'completed']);

        $controller = app(DisputeController::class);
        $this->actAsJobUser(1, 'customer');
        $opened = $controller->store(
            ControllerRequest::create('/api/jobs/1/disputes', 'POST', ['reason' => 'Fixture issue']), 1
        );
        self::assertSame(201, $opened->getStatusCode());
        $id = $opened->getData(true)['id'];
        self::assertSame('open', DB::table('disputes')->find($id)->status);
        self::assertSame('completed', Job::findOrFail(1)->status);

        $this->actAsJobUser(3, 'admin');
        $resolved = $controller->adminUpdateStatus(
            ControllerRequest::create("/api/admin/disputes/$id/status", 'POST', ['status' => 'resolved']), $id
        );
        self::assertSame(200, $resolved->getStatusCode());
        self::assertSame('resolved', DB::table('disputes')->find($id)->status);
        self::assertSame('completed', Job::findOrFail(1)->status);
    }

    private function actAsJobUser(int $id, string $role): void
    {
        $user = (new User())->forceFill(['id' => $id, 'role' => $role, 'name' => 'Fixture User']);
        $this->actingAs($user, 'api');
    }

    private function checkout(): PaymentCheckoutReservation
    {
        foreach ([
            '2026_11_08_000000_create_payment_journal_tables.php',
            '2026_11_08_000200_create_stripe_operation_tables.php',
        ] as $name) {
            $migration = require database_path('migrations/' . $name);
            $migration->up();
        }

        return app(PaymentCheckoutReservation::class);
    }

    private function proposal(int $job = 1, int $actor = 1): object
    {
        return app(PaymentAgreementService::class)->proposeInitial($job, $actor);
    }

    private function decide(
        object $agreement,
        int $actor,
        string $type = 'scope_accepted',
        ?string $key = null
    ): object {
        return app(PaymentAgreementService::class)->recordDecision(
            (int) $agreement->id,
            $actor,
            $agreement->agreement_sha256,
            $type,
            $key ?? 'fixture_consent_' . ++$this->serial
        );
    }

    private function both(object $agreement): void
    {
        $this->decide($agreement, 1);
        $this->decide($agreement, 2);
    }

    private function ready(object $agreement, int $actor = 1): object
    {
        return DB::transaction(fn () => app(PaymentAgreementService::class)->lockApprovedInitial(
            (int) $agreement->id, $actor, $agreement->agreement_sha256
        ));
    }

    private function job(array $changes): void
    {
        DB::table('jobs')->where('id', 1)->update($changes);
    }

    private function change(int $id, string $status, string $delta): void
    {
        DB::table('change_orders')->insert([
            'id' => $id, 'job_id' => 1, 'description' => "Fixture change $id",
            'price_delta' => $delta, 'status' => $status,
        ]);
    }

    private function payment(array $changes = []): int
    {
        return (int) DB::table('payments')->insertGetId(array_replace([
            'job_id' => 1, 'customer_id' => 1, 'contractor_id' => 2, 'amount_cents' => 12500,
        ], $changes));
    }

    private function reserve(object $agreement): void
    {
        DB::table('payment_installments')->insert([
            'payment_agreement_id' => $agreement->id,
            'payment_id' => $this->payment(),
            'payable_key' => 'fixture_initial_payable',
            'sequence' => 1,
            'milestone_key' => 'upfront',
            'collection_trigger' => 'customer_authorized',
            'amount_cents' => 12500,
            'platform_fee_cents' => 1250,
            'currency' => 'usd',
            'transfer_group' => 'fixture_initial_group',
            'installment_sha256' => str_repeat('a', 64),
        ]);
    }

    private function rows(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function denied(callable $action, int $status): HttpException
    {
        try {
            $action();
        } catch (HttpException $exception) {
            self::assertSame($status, $exception->getStatusCode());
            return $exception;
        }
        self::fail("Expected HTTP $status.");
    }
}
