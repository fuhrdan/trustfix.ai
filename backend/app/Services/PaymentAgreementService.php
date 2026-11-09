<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChangeOrder;
use App\Models\Job;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PaymentAgreementService
{
    private const POLICY = 'staging_2026_11_v1';
    private const FEE_BASIS_POINTS = 1000;

    public function __construct(private readonly PaymentSafetyGuard $guard)
    {
    }

    // Controllers must supply the authenticated ID, never a request actor ID.
    // Price-changing endpoints must share this job lock before checkout is wired.
    public function proposeInitial(int $jobId, int $actorId): object
    {
        $this->guard->assertPaymentsEnabled();

        return DB::transaction(function () use ($jobId, $actorId): object {
            $job = Job::query()->lockForUpdate()->findOrFail($jobId);
            $this->participantRole($job, $actorId);
            $this->assertActive($job);
            $this->assertNoLegacyPayments($jobId);

            $latestInitial = DB::table('payment_agreements')->where('job_id', $jobId)
                ->where('agreement_kind', 'initial')->orderByDesc('version')->lockForUpdate()->first();
            if ($latestInitial && $this->matchesCurrentTerms($job, $latestInitial)) {
                return $latestInitial;
            }

            $reserved = DB::table('payment_installments')
                ->join('payment_agreements', 'payment_agreements.id', '=', 'payment_installments.payment_agreement_id')
                ->where('payment_agreements.job_id', $jobId)->lockForUpdate()->first(['payment_installments.id']);
            abort_if($reserved !== null, 409, 'A payable already exists; changes require a separate reviewed agreement.');

            $previous = DB::table('payment_agreements')->where('job_id', $jobId)
                ->orderByDesc('version')->lockForUpdate()->first();
            $version = $previous ? (int) $previous->version : 0;
            abort_if($version >= 4294967295, 409, 'Agreement version limit requires reconciliation.');
            $terms = $this->terms($job, $version + 1, $previous ? (int) $previous->id : null, $actorId);
            $id = DB::table('payment_agreements')->insertGetId($terms);

            return $this->agreement((int) $id);
        }, 3);
    }

    // Idempotent receipt of a decision is not proof that consent is still effective.
    public function recordDecision(
        int $agreementId,
        int $actorId,
        string $expectedHash,
        string $eventType,
        string $decisionKey
    ): object {
        if ($eventType === 'consent_revoked') {
            $this->guard->apiSecret();
        } else {
            $this->guard->assertPaymentsEnabled();
        }
        abort_unless(in_array($eventType, ['scope_accepted', 'consent_revoked'], true), 422, 'Unsupported consent decision.');
        abort_unless(preg_match('/\A[A-Za-z0-9:_-]{16,191}\z/', $decisionKey) === 1, 422, 'Invalid consent decision key.');
        $agreement = $this->agreement($agreementId);
        $role = $this->participantRole($agreement, $actorId);
        $this->assertExpectedHash($agreement, $expectedHash);
        $identity = [
            'payment_agreement_id' => $agreementId,
            'decision_key' => $decisionKey,
            'actor_id' => $actorId,
            'actor_role' => $role,
            'event_type' => $eventType,
            'agreement_sha256' => $expectedHash,
        ];

        try {
            return DB::transaction(function () use ($agreement, $identity, $eventType): object {
                $job = Job::query()->lockForUpdate()->findOrFail($agreement->job_id);
                $existing = DB::table('payment_agreement_consents')
                    ->where('decision_key', $identity['decision_key'])->lockForUpdate()->first();
                if ($existing) {
                    return $this->matchingDecision($existing, $identity);
                }

                $hasPayable = DB::table('payment_installments')
                    ->where('payment_agreement_id', $agreement->id)->lockForUpdate()->first(['id']);
                abort_if($hasPayable !== null, 409, 'A payable already exists; use cancellation or refund handling.');
                if ($eventType === 'scope_accepted') {
                    $this->assertActive($job);
                    $this->assertNoLegacyPayments((int) $job->id);
                    $this->assertCurrent($job, $agreement);
                }

                $id = DB::table('payment_agreement_consents')->insertGetId($identity);
                return DB::table('payment_agreement_consents')->find($id);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // A globally reused key can race across different job locks.
            $existing = DB::table('payment_agreement_consents')->useWritePdo()
                ->where('decision_key', $decisionKey)->lockForUpdate()->first();
            if (!$existing) {
                throw $exception;
            }
            return $this->matchingDecision($existing, $identity);
        }
    }

    // Invoke inside the transaction that reserves the payable and operation.
    // The initial agreement lookup locates immutable terms. Eligibility reads
    // under the job lock must see current rows even in an older read snapshot.
    // This returns approved terms, never a confirmation of payment or transfer.
    public function lockApprovedInitial(int $agreementId, int $actorId, string $expectedHash): object
    {
        $this->guard->assertPaymentsEnabled();
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Agreement eligibility requires the payable reservation transaction.');
        }
        $agreement = $this->agreement($agreementId);
        abort_unless($actorId === (int) $agreement->customer_id, 403, 'Only the customer may authorize collection.');
        $this->assertExpectedHash($agreement, $expectedHash);
        $job = Job::query()->lockForUpdate()->findOrFail($agreement->job_id);
        $this->assertActive($job);
        abort_unless(in_array($job->status, ['accepted', 'scheduled'], true),
            409, 'Initial upfront collection must be authorized before work starts.');
        $this->assertNoLegacyPayments((int) $job->id);
        $this->assertCurrent($job, $agreement);

        foreach ([
            'customer' => (int) $agreement->customer_id,
            'contractor' => (int) $agreement->contractor_id,
        ] as $role => $id) {
            $decision = DB::table('payment_agreement_consents')
                ->where('payment_agreement_id', $agreementId)->where('actor_id', $id)
                ->whereIn('event_type', ['scope_accepted', 'consent_revoked'])
                ->orderByDesc('id')->lockForUpdate()->first();
            abort_unless($decision && $decision->actor_role === $role
                && $decision->event_type === 'scope_accepted'
                && hash_equals($agreement->agreement_sha256, $decision->agreement_sha256),
                409, 'Both participants must accept the same current terms.');
        }

        return $agreement;
    }

    private function agreement(int $id): object
    {
        $agreement = DB::table('payment_agreements')->find($id);
        abort_unless($agreement, 404, 'Payment agreement not found.');
        abort_unless($agreement->agreement_kind === 'initial', 409, 'This service handles initial agreements only.');
        return $agreement;
    }

    private function participantRole(#[\SensitiveParameter] object $subject, int $actorId): string
    {
        if ($actorId > 0 && $actorId === (int) $subject->customer_id) {
            return 'customer';
        }
        $contractorId = $subject instanceof Job ? $subject->handyman_id : $subject->contractor_id;
        abort_unless($actorId > 0 && $actorId === (int) $contractorId,
            403, 'Only agreement participants may decide.');
        return 'contractor';
    }

    private function assertActive(#[\SensitiveParameter] Job $job): void
    {
        abort_unless($job->handyman_id && (int) $job->customer_id !== (int) $job->handyman_id,
            409, 'A distinct assigned contractor is required.');
        abort_unless(in_array($job->status, ['accepted', 'scheduled', 'in_progress'], true),
            409, 'The job is not available for initial payment terms.');
        abort_if($job->disputes()->whereIn('status', ['open', 'under_review'])->lockForUpdate()
                ->first(['id']) !== null,
            409, 'An unresolved dispute blocks payment authorization.');
        abort_if($job->changeOrders()->where('status', 'pending')->lockForUpdate()
                ->first(['id']) !== null,
            409, 'Review pending change orders before authorizing payment.');
    }

    private function assertNoLegacyPayments(int $jobId): void
    {
        $legacy = DB::table('payments')
            ->leftJoin('payment_installments', 'payment_installments.payment_id', '=', 'payments.id')
            ->where('payments.job_id', $jobId)->whereNull('payment_installments.id')
            ->lockForUpdate()->first(['payments.id']);
        abort_if($legacy !== null, 409, 'Existing payment records require reconciliation before collecting again.');
    }

    private function assertCurrent(
        #[\SensitiveParameter] Job $job,
        #[\SensitiveParameter] object $agreement
    ): void {
        $latest = DB::table('payment_agreements')->where('job_id', $job->id)
            ->where('agreement_kind', 'initial')->orderByDesc('version')->lockForUpdate()->first();
        abort_unless($latest && (int) $latest->id === (int) $agreement->id
            && $this->matchesCurrentTerms($job, $agreement),
            409, 'Agreement terms changed; review the current proposal.');
    }

    private function matchesCurrentTerms(
        #[\SensitiveParameter] Job $job,
        #[\SensitiveParameter] object $agreement
    ): bool {
        $terms = $this->terms(
            $job,
            (int) $agreement->version,
            $agreement->previous_agreement_id === null ? null : (int) $agreement->previous_agreement_id,
            (int) $agreement->created_by_actor_id
        );
        return hash_equals($agreement->agreement_sha256, $terms['agreement_sha256']);
    }

    private function assertExpectedHash(#[\SensitiveParameter] object $agreement, string $hash): void
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/', $hash) === 1
            && hash_equals($agreement->agreement_sha256, $hash),
            409, 'The reviewed agreement hash does not match.');
    }

    private function matchingDecision(#[\SensitiveParameter] object $existing, array $identity): object
    {
        foreach ($identity as $field => $value) {
            $actual = in_array($field, ['payment_agreement_id', 'actor_id'], true)
                ? (int) $existing->$field : $existing->$field;
            abort_unless($actual === $value, 409, 'Consent decision key conflicts with an earlier decision.');
        }
        return $existing;
    }

    private function terms(
        #[\SensitiveParameter] Job $job,
        int $version,
        ?int $previous,
        int $actorId
    ): array {
        try {
            $amount = PaymentAmounts::decimalToCents($job->agreed_price);
            abort_unless($amount >= 50, 409, 'The approved amount must be at least USD 0.50.');
            $fee = PaymentAmounts::platformFeeCents($amount, self::FEE_BASIS_POINTS);
            $changes = ChangeOrder::query()->where('job_id', $job->id)->where('status', 'approved')
                ->orderBy('id')->lockForUpdate()->get()->map(static fn (ChangeOrder $change): array => [
                    'id' => (int) $change->id,
                    'description' => (string) $change->description,
                    'price_delta_cents' => PaymentAmounts::decimalToCents($change->price_delta),
                ])->all();
        } catch (InvalidArgumentException) {
            throw new HttpException(409, 'Job pricing requires a reviewed correction.');
        }

        $scope = $this->json([
            'job_id' => (int) $job->id,
            'property_id' => $job->property_id === null ? null : (int) $job->property_id,
            'address' => (string) ($job->address ?? ''),
            'description' => (string) $job->initial_description,
            'skills' => $job->skills ?? [],
            'change_orders' => $changes,
        ]);
        $terms = [
            'job_id' => (int) $job->id,
            'customer_id' => (int) $job->customer_id,
            'contractor_id' => (int) $job->handyman_id,
            'version' => $version,
            'previous_agreement_id' => $previous,
            'agreement_kind' => 'initial',
            'policy_version' => self::POLICY,
            'payment_model' => StripePaymentGateway::PAYMENT_MODEL,
            'currency' => 'usd',
            'amount_cents' => $amount,
            'platform_fee_cents' => $fee,
            'platform_fee_basis_points' => self::FEE_BASIS_POINTS,
            'scope_snapshot' => $scope,
            'scope_sha256' => hash('sha256', $scope),
            'schedule_snapshot' => $this->json([[
                'sequence' => 1,
                'milestone_key' => 'upfront',
                'collection_trigger' => 'customer_authorized',
                'amount_cents' => $amount,
                'platform_fee_cents' => $fee,
                'currency' => 'usd',
            ]]),
            'included_change_orders' => $this->json($changes),
            'created_by_actor_id' => $actorId,
        ];
        $terms['agreement_sha256'] = hash('sha256', $this->json($terms));
        return $terms;
    }

    private function json(#[\SensitiveParameter] mixed $value): string
    {
        try {
            return json_encode(
                $this->normalized($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException) {
            throw new HttpException(409, 'Agreement scope cannot be represented safely.');
        }
    }

    private function normalized(#[\SensitiveParameter] mixed $value): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalized($item);
            }
            return $value;
        }
        abort_unless($value === null || is_string($value) || is_int($value) || is_bool($value),
            409, 'Agreement scope contains an unsupported value.');
        return $value;
    }
}
