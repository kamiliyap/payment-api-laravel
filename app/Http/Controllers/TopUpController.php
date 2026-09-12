<?php

namespace App\Http\Controllers;

use App\Models\TopUp;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TopUpController extends Controller
{
    public function topUp(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
        ]);

        $userId = $request->user()->id;

        $result = DB::transaction(function () use ($userId, $validated) {

            $user = User::where('id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            $balanceBefore = $user->balance;
            $balanceAfter = $balanceBefore + $validated['amount'];

            $user->balance = $balanceAfter;
            $user->save();

            $topUp = TopUp::create([
                'top_up_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'amount_top_up' => $validated['amount'],
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ]);

            Transaction::create([
                'user_id' => $user->id,
                'type' => 'topup',
                'amount' => $validated['amount'],
                'status' => 'success',
                'description' => 'Balance top up',
            ]);

            return $topUp;
        });

        return response()->json([
            'status' => 'SUCCESS',
            'result' => [
                'top_up_id' => $result->top_up_id,
                'amount_top_up' => (float) $result->amount_top_up,
                'balance_before' => (float) $result->balance_before,
                'balance_after' => (float) $result->balance_after,
                'created_date' => $result->created_at->format('Y-m-d H:i:s'),
            ]
        ], 200);
    }
}