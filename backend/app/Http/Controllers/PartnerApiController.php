<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\JobActivity;
use App\Models\JobMessage;
use App\Models\PartnerApiClient;
use App\Models\PartnerMemberAccount;
use App\Models\PartnerServiceRequest;
use App\Services\PartnerWebhookService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartnerApiController extends Controller
{
    public function __construct(private readonly PartnerWebhookService $webhooks) {}

    public function createServiceRequest(Request $request)
    {
        $validated = $request->validate([
            'external_request_id' => ['required', 'string', 'max:191'],
            'member_id' => ['required', 'string', 'max:191'],
            'description' => ['required', 'string', 'max:5000'],
            'address' => ['required', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'onsite_contact_name' => ['nullable', 'string', 'max:255'],
            'onsite_contact_phone' => ['nullable', 'string', 'max:50'],
            'skills' => ['nullable', 'array', 'max:30'],
            'skills.*' => ['string', 'max:100'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'scheduled_for' => ['nullable', 'date'],
            'scheduling_reference' => ['nullable', 'string', 'max:191'],
        ]);
        $client = $this->client($request);

        try {
            $result = DB::transaction(function () use ($validated, $client) {
                $existing = PartnerServiceRequest::query()
                    ->where('partner_api_client_id', $client->id)
                    ->where('external_request_id', $validated['external_request_id'])
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return [
                        'request' => $existing->load('job'),
                        'created' => false,
                    ];
                }

                $member = PartnerMemberAccount::query()
                    ->with('user')
                    ->where('partner_api_client_id', $client->id)
                    ->where('external_member_id', $validated['member_id'])
                    ->lockForUpdate()
                    ->first();
                if (! $member || $member->membership_status !== 'active' || ! $member->user) {
                    return [
                        'error' => 'No active TrustFix account is mapped to that partner member.',
                        'status' => 422,
                    ];
                }
                if (($member->user->account_status ?? 'active') !== 'active') {
                    return [
                        'error' => 'The mapped TrustFix account is not active.',
                        'status' => 403,
                    ];
                }

                $job = Job::create([
                    'customer_id' => $member->user_id,
                    'status' => 'posted',
                    'address' => trim($validated['address']),
                    'lat' => $validated['latitude'] ?? 0,
                    'lng' => $validated['longitude'] ?? 0,
                    'initial_description' => $validated['description'],
                    // In a partner request, this is an optional customer-approved
                    // ceiling, not a contractor's final quote.
                    'agreed_price' => $validated['budget'] ?? null,
                    'onsite_contact_name' => $validated['onsite_contact_name'] ?? null,
                    'onsite_contact_phone' => $validated['onsite_contact_phone'] ?? null,
                    'skills' => $validated['skills'] ?? [],
                ]);

                $partnerRequest = PartnerServiceRequest::create([
                    'partner_api_client_id' => $client->id,
                    'partner_member_account_id' => $member->id,
                    'job_id' => $job->id,
                    'external_request_id' => $validated['external_request_id'],
                    'scheduled_for' => $validated['scheduled_for'] ?? null,
                    'scheduling_reference' => $validated['scheduling_reference'] ?? null,
                    'last_status_at' => now(),
                ]);

                $description = 'Service request submitted by partner '.$client->name.'.';
                JobActivity::create([
                    'job_id' => $job->id,
                    'user_id' => null,
                    'activity_type' => 'partner_service_request_created',
                    'description' => $description,
                    'metadata' => [
                        'partner_client_id' => $client->id,
                        'external_request_id' => $partnerRequest->external_request_id,
                    ],
                ]);
                JobMessage::create([
                    'job_id' => $job->id,
                    'sender_user_id' => null,
                    'message' => $description,
                    'message_type' => 'system',
                ]);

                if ($partnerRequest->scheduled_for) {
                    JobActivity::create([
                        'job_id' => $job->id,
                        'user_id' => null,
                        'activity_type' => 'partner_schedule_confirmed',
                        'description' => 'Partner scheduling confirmation was recorded.',
                        'metadata' => [
                            'scheduled_for' => $partnerRequest->scheduled_for->toIso8601String(),
                            'scheduling_reference' => $partnerRequest->scheduling_reference,
                        ],
                    ]);
                }

                return [
                    'request' => $partnerRequest->load('job'),
                    'created' => true,
                ];
            });
        } catch (QueryException $exception) {
            // The unique partner/external-request index is the final guard
            // against concurrent idempotent submissions. If another request
            // won that narrow race, return its already-created result.
            if (! in_array((string) $exception->getCode(), ['19', '23000'], true)) {
                throw $exception;
            }

            $existing = PartnerServiceRequest::query()
                ->with('job')
                ->where('partner_api_client_id', $client->id)
                ->where('external_request_id', $validated['external_request_id'])
                ->first();
            if (! $existing) {
                throw $exception;
            }

            $result = [
                'request' => $existing,
                'created' => false,
            ];
        }

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['status']);
        }

        /** @var PartnerServiceRequest $partnerRequest */
        $partnerRequest = $result['request'];
        if ($result['created']) {
            $this->webhooks->dispatchStatus($partnerRequest, 'service_request.created');
        }

        return response()->json([
            'data' => $this->present($partnerRequest),
            'idempotent_replay' => ! $result['created'],
        ], $result['created'] ? 201 : 200);
    }

    public function showServiceRequest(Request $request, string $externalRequestId)
    {
        $partnerRequest = $this->findServiceRequest($this->client($request), $externalRequestId);

        return response()->json(['data' => $this->present($partnerRequest)]);
    }

    public function activities(Request $request, string $externalRequestId)
    {
        $partnerRequest = $this->findServiceRequest($this->client($request), $externalRequestId);
        $activities = JobActivity::query()
            ->where('job_id', $partnerRequest->job_id)
            ->latest('created_at')
            ->get(['activity_type', 'created_at'])
            ->map(fn (JobActivity $activity) => [
                'type' => $activity->activity_type,
                'occurred_at' => $activity->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'data' => [
                'external_request_id' => $partnerRequest->external_request_id,
                'activities' => $activities,
            ],
        ]);
    }

    public function recordSchedule(Request $request, string $externalRequestId)
    {
        $validated = $request->validate([
            'scheduled_for' => ['required', 'date'],
            'scheduling_reference' => ['nullable', 'string', 'max:191'],
        ]);
        $client = $this->client($request);

        $partnerRequest = DB::transaction(function () use ($client, $externalRequestId, $validated) {
            $partnerRequest = $this->findServiceRequest($client, $externalRequestId, true);
            $partnerRequest->forceFill([
                'scheduled_for' => $validated['scheduled_for'],
                'scheduling_reference' => $validated['scheduling_reference'] ?? null,
            ])->save();

            JobActivity::create([
                'job_id' => $partnerRequest->job_id,
                'user_id' => null,
                'activity_type' => 'partner_schedule_confirmed',
                'description' => 'Partner scheduling confirmation was updated.',
                'metadata' => [
                    'scheduled_for' => $partnerRequest->scheduled_for?->toIso8601String(),
                    'scheduling_reference' => $partnerRequest->scheduling_reference,
                ],
            ]);

            return $partnerRequest->load('job');
        });

        $this->webhooks->dispatchStatus($partnerRequest, 'service_request.scheduled');

        return response()->json(['data' => $this->present($partnerRequest)]);
    }

    private function client(Request $request): PartnerApiClient
    {
        $client = $request->attributes->get('partner_client');
        abort_unless($client instanceof PartnerApiClient, 401, 'Invalid partner API credentials.');

        return $client;
    }

    private function findServiceRequest(
        PartnerApiClient $client,
        string $externalRequestId,
        bool $lock = false
    ): PartnerServiceRequest {
        $query = PartnerServiceRequest::query()
            ->with('job')
            ->where('partner_api_client_id', $client->id)
            ->where('external_request_id', $externalRequestId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function present(PartnerServiceRequest $partnerRequest): array
    {
        $job = $partnerRequest->job;

        return [
            'external_request_id' => $partnerRequest->external_request_id,
            'trustfix_job_id' => $partnerRequest->job_id,
            'status' => $job?->status,
            'scheduled_for' => $partnerRequest->scheduled_for?->toIso8601String(),
            'scheduling_reference' => $partnerRequest->scheduling_reference,
            'status_updated_at' => $partnerRequest->last_status_at?->toIso8601String(),
            'created_at' => $partnerRequest->created_at?->toIso8601String(),
            'updated_at' => $partnerRequest->updated_at?->toIso8601String(),
        ];
    }
}
