<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
        ]);

        $user = $request->user();
        $user->update($validated);

        return response()->json([
            'status' => 'SUCCESS',
            'result' => [
                'user_id' => $user->user_id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'address' => $user->address,
                'updated_date' => $user->updated_at->format('Y-m-d H:i:s'),
            ],
        ]);
    }
}
