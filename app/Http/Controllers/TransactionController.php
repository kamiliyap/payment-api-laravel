<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\TopUp;
use App\Models\Transfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $transactions = TopUp::where('user_id', $user->id)->get()->toBase()
            ->concat(Payment::where('user_id', $user->id)->get())
            ->concat(Transfer::where('sender_id', $user->id)->get())
            ->sortByDesc('created_at')
            ->values()
            ->map(function ($transaction) use ($user) {
                $isTopUp = $transaction instanceof TopUp;
                $isTransfer = $transaction instanceof Transfer;
                $idField = $isTopUp ? 'top_up_id' : ($isTransfer ? 'transfer_id' : 'payment_id');

                return [
                    $idField => $transaction->{$idField},
                    'status' => $isTransfer ? strtoupper($transaction->status) : 'SUCCESS',
                    'user_id' => $user->user_id,
                    'transaction_type' => $isTopUp ? 'CREDIT' : 'DEBIT',
                    'amount' => (float) ($isTopUp ? $transaction->amount_top_up : $transaction->amount),
                    'remarks' => $isTopUp ? '' : $transaction->remarks,
                    'balance_before' => $transaction->balance_before === null ? null : (float) $transaction->balance_before,
                    'balance_after' => $transaction->balance_after === null ? null : (float) $transaction->balance_after,
                    'created_date' => $transaction->created_at->format('Y-m-d H:i:s'),
                ];
            });

        return response()->json(['status' => 'SUCCESS', 'result' => $transactions]);
    }
}
