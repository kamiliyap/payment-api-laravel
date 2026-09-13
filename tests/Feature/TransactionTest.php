<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\TopUp;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);

        // Follow existing tests: skip the legacy migration with duplicate transaction columns.
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_09_12_045733_create_personal_access_tokens_table.php',
            '2026_09_12_045934_add_balance_to_users_table.php',
            '2026_09_12_055137_add_register_fields_to_users_table.php',
            '2026_09_12_062818_create_top_ups_table.php',
            '2026_09_12_120000_create_payments_table.php',
            '2026_09_12_130000_create_transfers_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    private function account(): User
    {
        return User::forceCreate([
            'user_id' => (string) Str::uuid(),
            'name' => 'Report Test',
            'email' => Str::uuid().'@example.test',
            'password' => 'test-password',
        ]);
    }

    public function test_report_combines_owned_transactions_in_newest_first_order(): void
    {
        $user = $this->account();
        $other = $this->account();
        $expected = [];

        foreach ([$user, $other] as $owner) {
            foreach ([
                [TopUp::class, 'top_up_id', 'amount_top_up', 500000, '', 0, 500000, '2021-04-01 22:21:21'],
                [Payment::class, 'payment_id', 'amount', 100000, 'Pulsa Telkomsel 100k', 500000, 400000, '2021-04-01 22:22:00'],
                [Transfer::class, 'transfer_id', 'amount', 30000, 'Hadiah Ultah', 400000, 370000, '2021-04-01 22:23:20'],
            ] as [$model, $idField, $amountField, $amount, $remarks, $before, $after, $date]) {
                $id = (string) Str::uuid();
                $attributes = [
                    $idField => $id, $amountField => $amount,
                    'balance_before' => $before, 'balance_after' => $after,
                    'created_at' => $date, 'updated_at' => $date,
                ];
                if ($model === Transfer::class) {
                    $attributes += ['sender_id' => $owner->id, 'receiver_id' => $owner->is($user) ? $other->id : $user->id, 'status' => 'success'];
                } else {
                    $attributes['user_id'] = $owner->id;
                }
                if ($model !== TopUp::class) {
                    $attributes['remarks'] = $remarks;
                }
                $model::forceCreate($attributes);
                if ($owner->is($user)) {
                    $expected[] = [
                        $idField => $id, 'status' => 'SUCCESS', 'user_id' => $user->user_id,
                        'transaction_type' => $model === TopUp::class ? 'CREDIT' : 'DEBIT',
                        'amount' => $amount, 'remarks' => $remarks,
                        'balance_before' => $before, 'balance_after' => $after, 'created_date' => $date,
                    ];
                }
            }
        }

        $this->withToken($user->createToken('report')->plainTextToken);
        foreach (['/transactions', '/api/transactions'] as $path) {
            $this->getJson($path)->assertOk()->assertExactJson([
                'status' => 'SUCCESS', 'result' => array_reverse($expected),
            ]);
        }
    }

    public function test_empty_report_is_an_array(): void
    {
        $this->withToken($this->account()->createToken('report')->plainTextToken)
            ->getJson('/transactions')->assertOk()
            ->assertExactJson(['status' => 'SUCCESS', 'result' => []]);
    }

    public function test_missing_or_invalid_token_returns_json_without_accept_header(): void
    {
        foreach (['/transactions', '/api/transactions'] as $path) {
            $this->get($path)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated']);
            $this->withToken('invalid')->get($path)->assertUnauthorized()
                ->assertExactJson(['message' => 'Unauthenticated']);
            $this->flushHeaders();
        }
    }

    public function test_unsettled_transfers_preserve_status_and_null_balances(): void
    {
        $user = $this->account();
        $receiver = $this->account();
        foreach (['pending', 'failed'] as $status) {
            Transfer::create([
                'transfer_id' => (string) Str::uuid(), 'sender_id' => $user->id,
                'receiver_id' => $receiver->id, 'amount' => 1.25,
                'remarks' => 'Queued transfer', 'status' => $status,
            ]);
        }

        $result = $this->withToken($user->createToken('report')->plainTextToken)
            ->getJson('/transactions')->assertOk()->assertJsonCount(2, 'result')->json('result');
        $this->assertEqualsCanonicalizing(['PENDING', 'FAILED'], array_column($result, 'status'));
        foreach ($result as $transaction) {
            $this->assertNull($transaction['balance_before']);
            $this->assertNull($transaction['balance_after']);
            $this->assertSame(1.25, $transaction['amount']);
        }
    }
}
