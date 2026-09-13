<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessTransfer implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 30;
    public int $backoff = 2;

    public function __construct(public int $transferId)
    {
        $this->onConnection('transfers');
        $this->onQueue('transfers');
    }

    public function handle(): void
    {
        DB::transaction(function () {
            $transfer = Transfer::whereKey($this->transferId)->lockForUpdate()->firstOrFail();

            // A redelivered job must never move the same money twice.
            if ($transfer->status !== 'pending') {
                return;
            }

            // Lock accounts in the same order for transfers in either direction.
            $users = User::whereIn('id', [$transfer->sender_id, $transfer->receiver_id])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $sender = $users->get($transfer->sender_id);
            $receiver = $users->get($transfer->receiver_id);

            if (!$sender || !$receiver || $sender->id === $receiver->id) {
                $transfer->update(['status' => 'failed', 'failure_message' => 'Invalid transfer target']);
                return;
            }

            $before = (int) round((float) $sender->balance * 100);
            $amount = (int) round((float) $transfer->amount * 100);
            $receiverBefore = (int) round((float) $receiver->balance * 100);

            if ($before < $amount) {
                $transfer->update([
                    'status' => 'failed',
                    'failure_message' => 'Balance is not enough',
                    'balance_before' => $sender->balance,
                    'balance_after' => $sender->balance,
                ]);
                return;
            }

            if ($receiverBefore + $amount > 999999999999999) {
                $transfer->update(['status' => 'failed', 'failure_message' => 'Recipient balance limit exceeded']);
                return;
            }

            $sender->update(['balance' => ($before - $amount) / 100]);
            $receiver->update(['balance' => ($receiverBefore + $amount) / 100]);

            foreach ([$sender->id => 'transfer_out', $receiver->id => 'transfer_in'] as $userId => $type) {
                Transaction::create([
                    'user_id' => $userId,
                    'type' => $type,
                    'amount' => $transfer->amount,
                    'status' => 'success',
                    'description' => $transfer->remarks,
                ]);
            }

            $transfer->update([
                'balance_before' => $before / 100,
                'balance_after' => ($before - $amount) / 100,
                'status' => 'success',
            ]);
        }, 3);
    }

    public function failed(?Throwable $exception): void
    {
        Transfer::whereKey($this->transferId)->where('status', 'pending')->update([
            'status' => 'failed',
            'failure_message' => 'Transfer could not be processed',
        ]);
    }
}
