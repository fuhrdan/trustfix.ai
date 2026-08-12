<?php

namespace Tests\Feature;

use App\Mail\LifecycleMail;
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Services\LifecycleNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class TrustFixEmailLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://api.lakehousesoftware.com',
            'trustfix.frontend_url' => 'https://trustfix.lakehousesoftware.com',
        ]);
        URL::forceRootUrl('https://api.lakehousesoftware.com');
    }

    public function test_welcome_email_uses_the_current_frontend_login_url(): void
    {
        $user = $this->unverifiedUser();
        $html = (new WelcomeMail($user))->render();

        $this->assertStringContainsString(
            'https://trustfix.lakehousesoftware.com/login.php',
            $html
        );
        $this->assertStringNotContainsString('www.trustfixai.com', $html);
    }

    public function test_verification_email_uses_the_frontend_bridge(): void
    {
        Mail::fake();
        $user = $this->unverifiedUser();

        $queued = app(LifecycleNotificationService::class)
            ->emailVerification($user);

        $this->assertTrue($queued);
        Mail::assertQueued(LifecycleMail::class, function (LifecycleMail $mail): bool {
            return str_starts_with(
                (string)$mail->actionUrl,
                'https://trustfix.lakehousesoftware.com/verify_email.php?path='
            ) && !str_contains((string)$mail->actionUrl, 'www.trustfixai.com');
        });
    }

    public function test_relative_signed_verification_route_marks_the_user_verified(): void
    {
        $user = $this->unverifiedUser();
        $signedPath = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification()),
            ],
            absolute: false
        );

        $response = $this->get($signedPath);

        $response->assertRedirect(
            'https://trustfix.lakehousesoftware.com/login.php?verified=1'
        );
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_existing_absolute_signed_verification_links_remain_valid(): void
    {
        $user = $this->unverifiedUser();
        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );

        $response = $this->get($signedUrl);

        $response->assertRedirect(
            'https://trustfix.lakehousesoftware.com/login.php?verified=1'
        );
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    private function unverifiedUser(): User
    {
        return User::create([
            'name' => 'Email Test User',
            'email' => 'email-test@example.com',
            'password' => Hash::make('test-password'),
            'role' => 'customer',
        ]);
    }
}
