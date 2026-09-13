<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolate payment tests from the legacy migration that repeats transaction columns.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_09_12_044925_create_transactions_table.php',
            '2026_09_12_045733_create_personal_access_tokens_table.php',
            '2026_09_12_045934_add_balance_to_users_table.php',
            '2026_09_12_120000_create_payments_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    private function account(): User
    {
        return User::forceCreate([
            'name' => 'Payment Test',
            'email' => 'payment@example.test',
            'password' => 'test-password',
            'balance' => 500000,
        ]);
    }

    public function test_payment_uses_bearer_token_and_records_balances(): void
    {
        $user = $this->account();
        $token = $user->createToken('access_token')->plainTextToken;

        $response = $this->withToken($token)->postJson('/pay', [
            'amount' => 100000,
            'remarks' => 'Pulsa Telkomsel 100k',
        ])->assertOk();

        $payment = Payment::sole();
        $this->assertTrue(Str::isUuid($payment->payment_id));
        $response->assertExactJson([
            'status' => 'SUCCESS',
            'result' => [
                'payment_id' => $payment->payment_id,
                'amount' => 100000,
                'remarks' => 'Pulsa Telkomsel 100k',
                'balance_before' => 500000,
                'balance_after' => 400000,
                'created_date' => $payment->created_at->format('Y-m-d H:i:s'),
            ],
        ]);
        $this->assertSame('400000.00', $user->fresh()->balance);
        $this->assertDatabaseHas('payments', ['user_id' => $user->id, 'balance_after' => 400000]);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id, 'type' => 'payment', 'amount' => 100000,
            'description' => 'Pulsa Telkomsel 100k', 'status' => 'success',
        ]);
    }

    public function test_insufficient_balance_does_not_write_payment_or_deduct_balance(): void
    {
        $user = $this->account();
        $this->withToken($user->createToken('access_token')->plainTextToken)
            ->postJson('/pay', ['amount' => 500001, 'remarks' => 'Too much'])
            ->assertStatus(400)->assertExactJson(['message' => 'Balance is not enough']);

        $this->assertSame('500000.00', $user->fresh()->balance);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_all_payment_routes_return_json_for_guests_without_accept_header(): void
    {
        foreach (['/pay', '/api/pay', '/api/payment'] as $path) {
            $this->post($path, ['amount' => 1, 'remarks' => 'Test'])
                ->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated']);
        }
    }

    public function test_invalid_requests_are_rejected(): void
    {
        $this->withToken($this->account()->createToken('access_token')->plainTextToken);
        foreach ([[], ['amount' => 0, 'remarks' => 123], ['amount' => 'invalid', 'remarks' => '']] as $body) {
            $this->postJson('/pay', $body)->assertUnprocessable()
                ->assertJsonValidationErrors(['amount', 'remarks']);
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_transaction_failure_rolls_back_balance_and_payment(): void
    {
        $user = $this->account();
        $token = $user->createToken('access_token')->plainTextToken;
        Schema::drop('transactions');

        $this->withToken($token)->postJson('/pay', ['amount' => 100000, 'remarks' => 'Test'])
            ->assertStatus(500);

        $this->assertSame('500000.00', $user->fresh()->balance);
        $this->assertDatabaseCount('payments', 0);
    }
}
