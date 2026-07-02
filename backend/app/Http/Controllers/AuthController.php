<?php
//*****************************************************************************
//** Guard the gates with careful code,
//** Check each key before it's bestowed.
//** Tokens dance, sessions rise and fall,
//** Authentication answers every call - Dan
//*****************************************************************************

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Validation\Rule;

use App\Mail\WelcomeMail;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['nullable', Rule::in(['customer', 'handyman', 'admin', 'company'])],
            'company_id' => ['nullable', 'integer', 'exists:users,id'],
            'username' => ['nullable', 'string', 'max:255', 'unique:users,username'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'username' => $validated['username'] ?? null,
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'] ?? 'customer',
            'company_id' => $validated['company_id'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
        ]);
        
        Mail::to($user->email)->send(
            new WelcomeMail($user)
            );

        $token = auth('api')->login($user);

        return response()->json([
            'user' => $user,
            'token' => $token,
            'token_type' => 'bearer',
        ], 201);
    }

    public function updateMe(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([

            'name' =>
                'required|string|max:255',

            'username' =>
                'nullable|string|max:255|unique:users,username,' . $user->id,

            'email' =>
                'required|email|max:255|unique:users,email,' . $user->id,

            'phone' =>
                'nullable|string|max:30',

            'address' =>
                'nullable|string|max:500'
        ]);

        $user->name =
            $validated['name'];

        $user->username =
            $validated['username'] ?? null;

        $user->email =
            $validated['email'];

        $user->phone =
            $validated['phone'] ?? null;

        $user->address =
            $validated['address'] ?? null;

        $user->save();

        return response()->json([
            'success' => true,
            'user' => $user
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!$token = auth('api')->attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user = auth('api')->user();

        if ($user) {
            $user->last_login_at = now();
            $user->save();
        }

        return response()->json([
            'token' => $token,
            'token_type' => 'bearer',
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        return response()->json([
            'message' => __($status)
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
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
                    'password' => Hash::make($password)
                ])->setRememberToken(
                    Str::random(60)
                );

                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {

            return response()->json([
                'message' => 'Password reset successful.'
            ]);
        }

        return response()->json([
            'message' => __($status)
        ], 400);
    }

    public function me()
    {
        return response()->json(auth('api')->user());
    }

    public function refresh()
    {
        return response()->json([
            'token' => auth('api')->refresh(),
            'token_type' => 'bearer',
        ]);
    }

    public function logout()
    {
        auth('api')->logout();

        return response()->json([
            'message' => 'Successfully logged out',
        ]);
    }
}