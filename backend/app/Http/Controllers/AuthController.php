<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\LifecycleNotificationService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(
        private readonly LifecycleNotificationService $notifications
    ) {
    }

    public function register(Request $request)
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'username' => ['nullable', 'string', 'max:255', 'unique:users,username'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'username' => $validated['username'] ?? null,
            'password' => Hash::make($validated['password']),
            // Public registration can never grant an elevated role.
            'role' => 'customer',
            'company_id' => null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
        ]);

        $welcomeQueued = $this->notifications->welcome($user);
        $verificationQueued = $this->notifications->emailVerification($user);

        return response()->json([
            'user' => $user,
            'requires_email_verification' => true,
            'notification_queued' => $welcomeQueued && $verificationQueued,
            'message' => 'Account created. Check your email to verify your address before signing in.',
        ], 201);
    }

    public function updateMe(Request $request)
    {
        $user = Auth::guard('api')->user();

        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255', 'unique:users,username,' . $user->id],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $emailChanged = !hash_equals(
            Str::lower((string) $user->email),
            $validated['email']
        );

        $user->fill([
            'name' => $validated['name'],
            'username' => $validated['username'] ?? null,
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
        ]);

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $this->notifications->emailVerification($user);
        }

        return response()->json([
            'success' => true,
            'user' => $user,
            'requires_email_verification' => $emailChanged,
            'message' => $emailChanged
                ? 'Profile updated. Verify your new email address before your next sign-in.'
                : 'Profile updated successfully.',
        ]);
    }

    public function login(Request $request)
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!$token = Auth::guard('api')->attempt($credentials)) {
            return response()->json([
                'message' => 'The email or password is incorrect.',
            ], 401);
        }

        $user = Auth::guard('api')->user();

        if (($user->account_status ?? 'active') !== 'active') {
            Auth::guard('api')->logout();

            return response()->json([
                'message' => 'This account is not currently active. Contact TrustFix for assistance.',
            ], 403);
        }

        if (!$user->hasVerifiedEmail()) {
            Auth::guard('api')->logout();
            $this->notifications->emailVerification($user);

            return response()->json([
                'message' => 'Verify your email before signing in. A new verification link has been sent.',
                'requires_email_verification' => true,
            ], 403);
        }

        $user->last_login_at = now();
        $user->save();

        return response()->json([
            'user' => $user,
            'token' => $token,
            'token_type' => 'bearer',
        ]);
    }

    public function resendVerification(Request $request)
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user && !$user->hasVerifiedEmail()) {
            $this->notifications->emailVerification($user);
        }

        return response()->json([
            'message' => 'If that address belongs to an unverified TrustFix account, a new link has been sent.',
        ]);
    }

    public function verifyEmail(Request $request, $id, $hash)
    {
        // Accept existing absolute signed links during the domain transition,
        // plus the new host-independent relative signatures used by the
        // TrustFix frontend verification bridge.
        if (!$request->hasValidSignature() && !$request->hasValidSignature(false)) {
            abort(403, 'Invalid or expired verification link.');
        }

        $user = User::findOrFail($id);

        if (!hash_equals(
            sha1($user->getEmailForVerification()),
            (string) $hash
        )) {
            abort(403, 'Invalid verification link.');
        }

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        $redirectUrl = rtrim((string) config('trustfix.frontend_url'), '/')
            . '/login.php?verified=1';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'redirect_url' => $redirectUrl,
            ]);
        }

        return redirect()->away($redirectUrl);
    }

    public function forgotPassword(Request $request)
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        Password::sendResetLink(['email' => $validated['email']]);

        return response()->json([
            'message' => 'If a TrustFix account uses that address, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $request->only(
                'email',
                'password',
                'password_confirmation',
                'token'
            ),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(
                    Str::random(60)
                );

                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Password reset successful.',
            ]);
        }

        return response()->json([
            'message' => __($status),
        ], 400);
    }

    public function me()
    {
        return response()->json(Auth::guard('api')->user());
    }

    public function refresh()
    {
        return response()->json([
            'token' => Auth::guard('api')->refresh(),
            'token_type' => 'bearer',
        ]);
    }

    public function logout()
    {
        Auth::guard('api')->logout();

        return response()->json([
            'message' => 'Successfully logged out',
        ]);
    }
}
