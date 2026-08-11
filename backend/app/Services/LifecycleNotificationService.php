<?php

namespace App\Services;

use App\Mail\LifecycleMail;
use App\Mail\WelcomeMail;
use App\Models\Job;
use App\Models\JobEstimate;
use App\Models\Payment;
use App\Models\User;
use Closure;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

class LifecycleNotificationService
{
    public function welcome(User $user): bool
    {
        return $this->deliver(
            $user,
            fn () => new WelcomeMail($user),
            'welcome'
        );
    }

    public function emailVerification(User $user): bool
    {
        if ($user->hasVerifiedEmail()) {
            return true;
        }

        $minutes = max(
            10,
            (int) config('trustfix.verification_expire_minutes', 60)
        );
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes($minutes),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );

        return $this->deliver(
            $user,
            fn () => new LifecycleMail(
                recipient: $user,
                subjectLine: 'Verify your TrustFix email',
                headline: 'Verify your email address',
                intro: 'Confirm this email address to finish securing your TrustFix account.',
                preheader: 'Confirm your email address to finish setting up TrustFix.',
                actionLabel: 'Verify email',
                actionUrl: $verificationUrl,
                details: [
                    'Account' => $user->email,
                    'Link expires' => $minutes . ' minutes',
                ],
                notice: 'If you did not create a TrustFix account, no action is required.'
            ),
            'email_verification'
        );
    }

    public function passwordReset(User $user, string $token): bool
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);
        $resetUrl = $this->frontendUrl('/reset_password.php')
            . '?'
            . http_build_query([
                'token' => $token,
                'email' => $user->email,
            ]);

        return $this->deliver(
            $user,
            fn () => new LifecycleMail(
                recipient: $user,
                subjectLine: 'Reset your TrustFix password',
                headline: 'Reset your password',
                intro: 'A password reset was requested for your TrustFix account.',
                preheader: 'Use this secure link to reset your TrustFix password.',
                actionLabel: 'Reset password',
                actionUrl: $resetUrl,
                details: [
                    'Account' => $user->email,
                    'Link expires' => $minutes . ' minutes',
                ],
                notice: 'If you did not request a password reset, you can safely ignore this email.'
            ),
            'password_reset'
        );
    }

    public function contractorDocumentReviewed(
        User $user,
        string $documentType,
        string $status,
        ?string $notes = null
    ): bool {
        if (!in_array($status, ['approved', 'rejected'], true)) {
            return true;
        }

        $approved = $status === 'approved';
        $label = $this->label($documentType);

        return $this->deliver(
            $user,
            fn () => new LifecycleMail(
                recipient: $user,
                subjectLine: 'TrustFix document ' . $status,
                headline: $label . ' ' . $status,
                intro: $approved
                    ? 'TrustFix has reviewed and approved this contractor document.'
                    : 'TrustFix reviewed this document, but it needs attention before it can be approved.',
                preheader: 'Your TrustFix contractor document was ' . $status . '.',
                actionLabel: 'Review contractor profile',
                actionUrl: $this->frontendUrl('/edit_profile.php'),
                details: [
                    'Document' => $label,
                    'Status' => ucfirst($status),
                ],
                notice: $notes ?: ($approved
                    ? 'No further action is required for this document.'
                    : 'Open your profile to replace or update the document.')
            ),
            'contractor_document_' . $status
        );
    }

    public function contractorProfileStatus(
        User $user,
        string $status
    ): bool {
        $messages = [
            'approved' => [
                'subject' => 'Your TrustFix contractor profile is approved',
                'headline' => 'You are approved on TrustFix',
                'intro' => 'Your contractor profile has been approved. You can now review available jobs and complete payout setup.',
                'action' => 'Open contractor dashboard',
                'path' => '/contractor_dashboard.php',
            ],
            'rejected' => [
                'subject' => 'Your TrustFix contractor profile needs attention',
                'headline' => 'Contractor profile update required',
                'intro' => 'Your contractor profile was not approved in its current form. Review your profile and documents before submitting again.',
                'action' => 'Review contractor profile',
                'path' => '/edit_profile.php',
            ],
            'suspended' => [
                'subject' => 'Your TrustFix contractor profile was suspended',
                'headline' => 'Contractor access suspended',
                'intro' => 'Your contractor profile is temporarily unavailable to customers. Contact TrustFix if you need help resolving the account status.',
                'action' => 'Review contractor profile',
                'path' => '/edit_profile.php',
            ],
        ];

        if (!isset($messages[$status])) {
            return true;
        }

        $message = $messages[$status];

        return $this->deliver(
            $user,
            fn () => new LifecycleMail(
                recipient: $user,
                subjectLine: $message['subject'],
                headline: $message['headline'],
                intro: $message['intro'],
                preheader: $message['subject'],
                actionLabel: $message['action'],
                actionUrl: $this->frontendUrl($message['path']),
                details: ['Profile status' => ucfirst($status)]
            ),
            'contractor_profile_' . $status
        );
    }

    public function accountStatus(
        User $user,
        string $status,
        ?string $reason = null
    ): bool {
        $messages = [
            'active' => [
                'subject' => 'Your TrustFix account is active',
                'headline' => 'Account access restored',
                'intro' => 'Your TrustFix account is active again and you can sign in normally.',
                'action' => 'Sign in to TrustFix',
                'path' => '/login.php',
            ],
            'suspended' => [
                'subject' => 'Your TrustFix account was suspended',
                'headline' => 'Account access suspended',
                'intro' => 'TrustFix has temporarily suspended access to your account.',
                'action' => null,
                'path' => null,
            ],
            'banned' => [
                'subject' => 'Your TrustFix account was closed',
                'headline' => 'Account access closed',
                'intro' => 'TrustFix has closed access to your account.',
                'action' => null,
                'path' => null,
            ],
        ];

        if (!isset($messages[$status])) {
            return true;
        }

        $message = $messages[$status];

        return $this->deliver(
            $user,
            fn () => new LifecycleMail(
                recipient: $user,
                subjectLine: $message['subject'],
                headline: $message['headline'],
                intro: $message['intro'],
                preheader: $message['subject'],
                actionLabel: $message['action'],
                actionUrl: $message['path']
                    ? $this->frontendUrl($message['path'])
                    : null,
                details: ['Account status' => ucfirst($status)],
                notice: $reason ?: 'Contact TrustFix support if you have questions about this change.'
            ),
            'account_' . $status
        );
    }

    public function quoteSubmitted(Job $job, JobEstimate $estimate): bool
    {
        $job->loadMissing('customer');
        if (!$job->customer) {
            return false;
        }

        return $this->deliver(
            $job->customer,
            fn () => new LifecycleMail(
                recipient: $job->customer,
                subjectLine: 'A TrustFix quote is ready',
                headline: 'Your contractor quote is ready',
                intro: 'The contractor has reviewed the scope and submitted a quote for your approval.',
                preheader: 'A contractor quote is ready for TrustFix job #' . $job->id . '.',
                actionLabel: 'Review quote',
                actionUrl: $this->frontendUrl('/estimate_job.php?id=' . $job->id),
                details: [
                    'Job' => '#' . $job->id,
                    'Quote' => $this->money($estimate->contractor_quote),
                    'Project type' => $this->label($estimate->project_type),
                ]
            ),
            'quote_submitted'
        );
    }

    public function quoteAccepted(Job $job, JobEstimate $estimate): bool
    {
        $job->loadMissing('handyman');
        if (!$job->handyman) {
            return false;
        }

        return $this->deliver(
            $job->handyman,
            fn () => new LifecycleMail(
                recipient: $job->handyman,
                subjectLine: 'Your TrustFix quote was accepted',
                headline: 'The customer accepted your quote',
                intro: 'The accepted price is now recorded in the TrustFix job workspace.',
                preheader: 'Your quote for TrustFix job #' . $job->id . ' was accepted.',
                actionLabel: 'Open job workspace',
                actionUrl: $this->frontendUrl('/job_workspace.php?id=' . $job->id),
                details: [
                    'Job' => '#' . $job->id,
                    'Accepted price' => $this->money($estimate->accepted_price),
                ]
            ),
            'quote_accepted'
        );
    }

    public function jobStatusChanged(
        Job $job,
        string $status,
        ?User $actor = null
    ): void {
        $job->loadMissing(['customer', 'handyman']);
        $statusLabel = $this->label($status);
        $recipients = collect([$job->customer, $job->handyman])
            ->filter()
            ->unique('id')
            ->filter(fn (User $user) => !$actor || $user->id !== $actor->id);

        foreach ($recipients as $recipient) {
            $this->deliver(
                $recipient,
                fn () => new LifecycleMail(
                    recipient: $recipient,
                    subjectLine: 'TrustFix job updated: ' . $statusLabel,
                    headline: 'Job #' . $job->id . ' is now ' . strtolower($statusLabel),
                    intro: 'The job status changed in TrustFix. Open the workspace to review the latest details and activity.',
                    preheader: 'TrustFix job #' . $job->id . ' is now ' . strtolower($statusLabel) . '.',
                    actionLabel: 'Open job workspace',
                    actionUrl: $this->frontendUrl('/job_workspace.php?id=' . $job->id),
                    details: [
                        'Job' => '#' . $job->id,
                        'Status' => $statusLabel,
                    ]
                ),
                'job_status_' . $status
            );
        }
    }

    public function paymentUpdated(Payment $payment): void
    {
        if (!in_array($payment->status, ['succeeded', 'failed', 'cancelled'], true)) {
            return;
        }

        $payment->loadMissing(['job', 'customer', 'contractor']);
        $amount = '$' . number_format($payment->amount_cents / 100, 2);
        $successful = $payment->status === 'succeeded';

        if ($payment->customer) {
            $this->deliver(
                $payment->customer,
                fn () => new LifecycleMail(
                    recipient: $payment->customer,
                    subjectLine: $successful
                        ? 'TrustFix payment confirmed'
                        : 'TrustFix payment needs attention',
                    headline: $successful
                        ? 'Your payment is confirmed'
                        : 'Your payment was not completed',
                    intro: $successful
                        ? 'TrustFix recorded your payment and updated the job workspace.'
                        : 'The payment could not be completed. Open the job workspace to try again or review the payment status.',
                    preheader: 'Payment update for TrustFix job #' . $payment->job_id . '.',
                    actionLabel: 'Open job workspace',
                    actionUrl: $this->frontendUrl('/job_workspace.php?id=' . $payment->job_id),
                    details: [
                        'Job' => '#' . $payment->job_id,
                        'Amount' => $amount,
                        'Status' => $this->label($payment->status),
                    ]
                ),
                'payment_' . $payment->status . '_customer'
            );
        }

        if ($successful && $payment->contractor) {
            $this->deliver(
                $payment->contractor,
                fn () => new LifecycleMail(
                    recipient: $payment->contractor,
                    subjectLine: 'TrustFix payment received',
                    headline: 'A customer payment was confirmed',
                    intro: 'The payment is recorded in TrustFix. Stripe will manage payout timing for your connected account.',
                    preheader: 'Payment was confirmed for TrustFix job #' . $payment->job_id . '.',
                    actionLabel: 'Open contractor dashboard',
                    actionUrl: $this->frontendUrl('/contractor_dashboard.php'),
                    details: [
                        'Job' => '#' . $payment->job_id,
                        'Customer payment' => $amount,
                        'Status' => 'Succeeded',
                    ]
                ),
                'payment_succeeded_contractor'
            );
        }
    }

    private function deliver(
        User $recipient,
        Closure $mailFactory,
        string $event
    ): bool {
        if (!filter_var($recipient->email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('TrustFix notification skipped because the email is invalid.', [
                'user_id' => $recipient->id,
                'event' => $event,
            ]);

            return false;
        }

        try {
            Mail::to($recipient->email)->queue($mailFactory());

            return true;
        } catch (Throwable $queueException) {
            Log::warning('TrustFix notification could not be queued; trying immediate delivery.', [
                'user_id' => $recipient->id,
                'event' => $event,
                'exception' => $queueException->getMessage(),
            ]);
        }

        try {
            /** @var Mailable $mail */
            $mail = $mailFactory();
            Mail::to($recipient->email)->send($mail);

            return true;
        } catch (Throwable $sendException) {
            Log::error('TrustFix notification delivery failed.', [
                'user_id' => $recipient->id,
                'event' => $event,
                'exception' => $sendException->getMessage(),
            ]);

            return false;
        }
    }

    private function frontendUrl(string $path): string
    {
        return rtrim((string) config('trustfix.frontend_url'), '/')
            . '/'
            . ltrim($path, '/');
    }

    private function label(?string $value): string
    {
        return ucwords(str_replace('_', ' ', (string) $value));
    }

    private function money($value): string
    {
        return '$' . number_format((float) $value, 2);
    }
}
