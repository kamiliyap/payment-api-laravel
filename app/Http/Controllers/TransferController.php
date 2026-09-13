<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTransfer;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransferController extends Controller
{
    public function transfer(Request $request)
    {
        $validated = $request->validate([
            'target_user' => 'required|uuid|exists:users,user_id',
            'amount' => 'required|numeric|min:1|max:9999999999999.99|decimal:0,2',
            'remarks' => 'required|string|max:255',
        ]);

        $sender = $request->user();
        $receiver = User::where('user_id', $validated['target_user'])->firstOrFail();

        if ($sender->id === $receiver->id) {
            throw ValidationException::withMessages([
                'target_user' => 'Cannot transfer to your own account',
            ]);
        }

        if ((float) $sender->balance < (float) $validated['amount']) {
            return response()->json(['message' => 'Balance is not enough'], 400);
        }

        $transfer = DB::transaction(function () use ($sender, $receiver, $validated) {
            $transfer = Transfer::create([
                'transfer_id' => (string) Str::uuid(),
                'sender_id' => $sender->id,
                'receiver_id' => $receiver->id,
                'amount' => $validated['amount'],
                'remarks' => $validated['remarks'],
                'status' => 'pending',
            ]);

            // Use the same database connection so the record and job commit together.
            Queue::connection('transfers')->push(new ProcessTransfer($transfer->id), '', 'transfers');

            return $transfer;
        });

        $deadline = microtime(true) + (float) config('transfers.wait_seconds', 5);
        while ($transfer->status === 'pending' && microtime(true) < $deadline) {
            usleep(100000);
            $transfer->refresh();
        }

        return $this->result($transfer);
    }

    public function show(Request $request, string $transferId)
    {
        $transfer = Transfer::where('transfer_id', $transferId)
            ->where('sender_id', $request->user()->id)->firstOrFail();

        return $this->result($transfer);
    }

    private function result(Transfer $transfer)
    {
        if ($transfer->status === 'failed') {
            return response()->json(['message' => $transfer->failure_message], 400);
        }

        if ($transfer->status === 'pending') {
            return response()->json([
                'status' => 'PENDING',
                'result' => ['transfer_id' => $transfer->transfer_id],
            ], 202)->header('Location', '/transfer/'.$transfer->transfer_id);
        }

        return response()->json([
            'status' => 'SUCCESS',
            'result' => [
                'transfer_id' => $transfer->transfer_id,
                'amount' => (float) $transfer->amount,
                'remarks' => $transfer->remarks,
                'balance_before' => (float) $transfer->balance_before,
                'balance_after' => (float) $transfer->balance_after,
                'created_date' => $transfer->created_at->format('Y-m-d H:i:s'),
            ],
        ], 200);
    }
}
