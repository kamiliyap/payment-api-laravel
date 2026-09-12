<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TopUp extends Model
{
    protected $fillable = [
        'top_up_id',
        'user_id',
        'amount_top_up',
        'balance_before',
        'balance_after',
    ];
}