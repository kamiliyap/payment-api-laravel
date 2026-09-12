<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function pay(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'description' => 'nullable|string|max:255',
        ]);

        $userId = $request->user()->id;

        $result = DB::transaction(function () use ($userId, $validated) {

            $user = User::where('id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($user->balance < $validated['amount']) {
                return [
                    'success' => false,
                    'message' => 'Insufficient balance',
                    'balance' => $user->balance,
                ];
            }

            $user->balance = $user->balance - $validated['amount'];
            $user->save();

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'type' => 'payment',
                'amount' => $validated['amount'],
                'status' => 'success',
                'description' => $validated['description'] ?? 'Payment',
            ]);

            return [
                'success' => true,
                'message' => 'Payment success',
                'balance' => $user->balance,
                'transaction' => $transaction,
            ];
        });

        if (!$result['success']) {
            return response()->json($result, 400);
        }

        return response()->json($result, 200);
    }
}