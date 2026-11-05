<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use App\Http\Controllers\OidcController;

Route::get(
    '/reset-password/{token}',
    function (string $token, Request $request) {

        return redirect(
            'https://trustfix.lakehousesoftware.com/reset_password.php?' .
            http_build_query([
                'token' => $token,
                'email' => $request->email
            ])
        );
    }
)->name('password.reset');

Route::get('/', function () {
    return view('welcome', [
        'user' => auth()->user()
    ]);
});

// Browser-facing OIDC routes deliberately use Laravel's web session for the
// state, nonce, and PKCE verifier. The JWT handoff occurs only after callback
// validation through a short-lived one-time token.
Route::get('/auth/oidc/{provider}', [OidcController::class, 'redirect'])
    ->where('provider', '[A-Za-z0-9_-]+')
    ->name('oidc.redirect');
Route::get('/auth/oidc/{provider}/callback', [OidcController::class, 'callback'])
    ->where('provider', '[A-Za-z0-9_-]+')
    ->name('oidc.callback');
