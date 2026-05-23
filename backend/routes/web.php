<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

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
    return view('welcome');
});
