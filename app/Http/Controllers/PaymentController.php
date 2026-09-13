<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function pay(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'remarks' => 'required|string|max:255',
        ]);

        $userId = $request->user()->id;

        $result = DB::transaction(function () use ($userId, $validated) {

            $user = User::where('id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            $amount = round((float) $validated['amount'], 2);
            $balanceBefore = $user->balance;

            if ($balanceBefore < $amount) {
                return null;
            }

            $balanceAfter = round($balanceBefore - $amount, 2);
            $user->balance = $balanceAfter;
            $user->save();

            $payment = Payment::create([
                'payment_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'amount' => $amount,
                'remarks' => $validated['remarks'],
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ]);

            Transaction::create([
                'user_id' => $user->id,
                'type' => 'payment',
                'amount' => $amount,
                'status' => 'success',
                'description' => $validated['remarks'],
            ]);

            return $payment;
        });

        if ($result === null) {
            return response()->json([
                'message' => 'Balance is not enough'
            ], 400);
        }

        return response()->json([
            'status' => 'SUCCESS',
            'result' => [
                'payment_id' => $result->payment_id,
                'amount' => (float) $result->amount,
                'remarks' => $result->remarks,
                'balance_before' => (float) $result->balance_before,
                'balance_after' => (float) $result->balance_after,
                'created_date' => $result->created_at->format('Y-m-d H:i:s'),
            ]
        ], 200);
    }
}
