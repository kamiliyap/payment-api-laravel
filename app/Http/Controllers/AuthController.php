<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone_number' => 'required|string',
            'address' => 'required|string',
            'pin' => 'required|digits:6',
        ]);

        $existingUser = User::where('phone_number', $request->phone_number)->first();

        if ($existingUser) {
            return response()->json([
                'message' => 'Phone Number already registered'
            ], 400);
        }

        $user = User::create([
            'user_id' => (string) Str::uuid(),
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'phone_number' => $request->phone_number,
            'address' => $request->address,
            'pin' => Hash::make($request->pin),
            'balance' => 0,
        ]);

        return response()->json([
            'status' => 'SUCCESS',
            'result' => [
                'user_id' => $user->user_id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone_number' => $user->phone_number,
                'address' => $user->address,
                'created_date' => $user->created_at->format('Y-m-d H:i:s'),
            ]
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|string',
            'pin' => 'required|digits:6',
        ]);

        $user = User::where('phone_number', $request->phone_number)->first();

        if (!$user || !Hash::check($request->pin, $user->pin)) {
            return response()->json([
                'message' => 'Phone Number and PIN do not match'
            ], 401);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'status' => 'SUCCESS',
            'result' => [
                'user_id' => $user->user_id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone_number' => $user->phone_number,
                'address' => $user->address,
                'access_token' => $token,
            ]
        ], 200);
    }
}
