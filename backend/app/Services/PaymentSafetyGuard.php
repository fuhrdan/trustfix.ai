<?php

namespace App\Services;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class PaymentSafetyGuard
{
    public function apiSecret(): string
    {
        $this->assertTestConfiguration();

        return (string) config('services.stripe.secret');
    }

    public function assertPaymentsEnabled(): void
    {
        $this->ensure(config('payments.enabled', false) === true, 'Payments are disabled.');
        $this->assertTestConfiguration();
        $this->assertWebhookConfigured();
    }

    public function assertTransfersEnabled(): void
    {
        $this->ensure(config('payments.releases_enabled', false) === true, 'Contractor transfers are disabled.');
        $this->assertTestConfiguration();
        $this->assertWebhookConfigured();
    }

    public function assertRefundsEnabled(): void
    {
        $this->ensure(config('payments.refunds_enabled', false) === true, 'Refunds are disabled.');
        $this->assertTestConfiguration();
        $this->assertWebhookConfigured();
    }

    // Call only after verifying the signature against the raw request body.
    // Collection can be paused while authenticated events continue processing.
    public function assertWebhookAllowed(array $event): void
    {
        $this->assertTestConfiguration();
        $this->assertWebhookConfigured();
        $this->ensure(
            ($event['livemode'] ?? null) === false,
            'Only Stripe test events are accepted.',
            400
        );
    }

    public function publishableKey(): ?string
    {
        try {
            $this->assertPaymentsEnabled();
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 503) {
                throw $exception;
            }

            return null;
        }

        return (string) config('services.stripe.key');
    }

    private function assertTestConfiguration(): void
    {
        $environments = ['local', 'testing', 'staging'];
        $this->ensure(
            in_array(config('app.env'), $environments, true)
                && in_array(app()->environment(), $environments, true),
            'Payments require an isolated non-production environment.'
        );
        $this->ensure(
            config('payments.test_mode_only', true) === true,
            'Live payment activation is outside this release.'
        );
        $this->ensure(
            config('payments.auto_release_enabled', false) === false,
            'Automatic contractor transfers are unavailable in this release.'
        );

        $secret = config('services.stripe.secret');
        $publishable = config('services.stripe.key');
        $this->ensure(
            is_string($secret)
                && preg_match('/\A(?:sk|rk)_test_[A-Za-z0-9_-]+\z/', $secret) === 1,
            'A Stripe test API key is required.'
        );
        $this->ensure(
            is_string($publishable)
                && preg_match('/\Apk_test_[A-Za-z0-9_-]+\z/', $publishable) === 1,
            'A Stripe test publishable key is required.'
        );
    }

    private function assertWebhookConfigured(): void
    {
        $secret = config('services.stripe.webhook_secret');
        $this->ensure(
            is_string($secret)
                && preg_match('/\Awhsec_[A-Za-z0-9_-]+\z/', $secret) === 1,
            'A Stripe webhook signing secret is required.'
        );
    }

    private function ensure(bool $allowed, string $message, int $status = 503): void
    {
        if (!$allowed) {
            throw new HttpException($status, $message);
        }
    }
}
