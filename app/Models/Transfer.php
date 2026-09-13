<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transfer extends Model
{
    protected $fillable = [
        'transfer_id', 'sender_id', 'receiver_id', 'amount', 'remarks',
        'balance_before', 'balance_after', 'status', 'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }
}
