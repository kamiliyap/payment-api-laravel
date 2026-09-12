<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TopUpController extends Controller
{
    public function topUp(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
        ]);

        $user = $request->user();

        DB::transaction(function () use ($user, $validated) {
            $user->increment('balance', $validated['amount']);

            Transaction::create([
                'user_id' => $user->id,
                'type' => 'topup',
                'amount' => $validated['amount'],
                'status' => 'success',
                'description' => 'Balance top up',
            ]);
        });

        $user->refresh();

        return response()->json([
            'message' => 'Top up success',
            'balance' => $user->balance,
        ]);
    }
}