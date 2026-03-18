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
use Illuminate\Support\Facades\Hash;
use JWTAuth;

class AuthController extends Controller
{
	public function register(Request $request)
	{
		$user = User::create([
		    'name' => $request->name,
		    'email' => $request->email,
	  	    'password' => Hash::make($request->password),
		    'role' => $request->role ?? 'customer'
		]);

		return response()->json($user);
	}

	public function login(Request $request)
	{
		$credentials = $request->only('email', 'password');

		if (!$token = auth()->attempt($credentials)) {
		    return response()->json(['error' => 'Unauthorized'], 401);
		}

		return response()->json(['token' => $token]);

	}

	public function me()
	{
		return response()->json(auth()->user());
	}    
}
