<?php

namespace Tests\Feature;

use App\Jobs\ProcessTransfer;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransferTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'transfers.wait_seconds' => 0,
        ]);

        // Skip the legacy migration that duplicates existing transaction columns.
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '0001_01_01_000002_create_jobs_table.php',
            '2026_09_12_044925_create_transactions_table.php',
            '2026_09_12_045733_create_personal_access_tokens_table.php',
            '2026_09_12_045934_add_balance_to_users_table.php',
            '2026_09_12_055137_add_register_fields_to_users_table.php',
            '2026_09_12_130000_create_transfers_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    private function account(int $balance): User
    {
        return User::forceCreate([
            'user_id' => (string) Str::uuid(),
            'name' => 'Transfer Test',
            'email' => Str::uuid().'@example.test',
            'password' => 'test-password',
            'balance' => $balance,
        ]);
    }

    private function enqueue(User $sender, User $receiver, int $amount = 30000): Transfer
    {
        $response = $this->withToken($sender->createToken('access_token')->plainTextToken)
            ->postJson('/transfer', [
                'target_user' => $receiver->user_id,
                'amount' => $amount,
                'remarks' => 'Hadiah Ultah',
            ])->assertStatus(202)->assertJsonPath('status', 'PENDING');

        return Transfer::where('transfer_id', $response->json('result.transfer_id'))->firstOrFail();
    }

    public function test_real_database_queue_moves_balances_and_job_is_idempotent(): void
    {
        $sender = $this->account(400000);
        $receiver = $this->account(10000);
        $transfer = $this->enqueue($sender, $receiver);
        $this->assertTrue(Str::isUuid($transfer->transfer_id));
        $this->assertSame('400000.00', $sender->fresh()->balance);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('transactions', 0);

        Artisan::call('queue:work', ['connection' => 'transfers', '--queue' => 'transfers', '--once' => true]);

        $this->assertSame('370000.00', $sender->fresh()->balance);
        $this->assertSame('40000.00', $receiver->fresh()->balance);
        $this->assertSame('success', $transfer->fresh()->status);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseHas('transactions', ['user_id' => $sender->id, 'type' => 'transfer_out', 'amount' => 30000]);
        $this->assertDatabaseHas('transactions', ['user_id' => $receiver->id, 'type' => 'transfer_in', 'amount' => 30000]);

        $this->getJson('/transfer/'.$transfer->transfer_id)->assertOk()->assertExactJson([
            'status' => 'SUCCESS',
            'result' => [
                'transfer_id' => $transfer->transfer_id,
                'amount' => 30000,
                'remarks' => 'Hadiah Ultah',
                'balance_before' => 400000,
                'balance_after' => 370000,
                'created_date' => $transfer->created_at->format('Y-m-d H:i:s'),
            ],
        ]);

        (new ProcessTransfer($transfer->id))->handle();
        $this->assertSame('370000.00', $sender->fresh()->balance);
        $this->assertSame('40000.00', $receiver->fresh()->balance);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_insufficient_funds_at_request_and_worker_time(): void
    {
        $sender = $this->account(400000);
        $receiver = $this->account(0);
        $this->withToken($sender->createToken('access_token')->plainTextToken)
            ->postJson('/transfer', ['target_user' => $receiver->user_id, 'amount' => 500000, 'remarks' => 'Test'])
            ->assertStatus(400)->assertExactJson(['message' => 'Balance is not enough']);
        $this->assertDatabaseCount('transfers', 0);

        $transfer = $this->enqueue($sender, $receiver);
        $sender->update(['balance' => 100]);
        (new ProcessTransfer($transfer->id))->handle();
        $this->getJson('/transfer/'.$transfer->transfer_id)->assertStatus(400)
            ->assertExactJson(['message' => 'Balance is not enough']);
        $this->assertSame('100.00', $sender->fresh()->balance);
        $this->assertSame('0.00', $receiver->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_auth_validation_and_self_transfer(): void
    {
        foreach (['/transfer', '/api/transfer'] as $path) {
            $this->post($path)->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated']);
        }
        $sender = $this->account(400000);
        $this->withToken($sender->createToken('access_token')->plainTextToken);
        $this->postJson('/transfer', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['target_user', 'amount', 'remarks']);
        foreach ([$sender->user_id, (string) $sender->id, (string) Str::uuid()] as $target) {
            $this->postJson('/transfer', ['target_user' => $target, 'amount' => 1, 'remarks' => 'Test'])
                ->assertUnprocessable()->assertJsonValidationErrors('target_user');
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('transfers', 0);
    }

    public function test_failed_ledger_write_rolls_back_and_can_be_retried(): void
    {
        $sender = $this->account(400000);
        $receiver = $this->account(0);
        $transfer = $this->enqueue($sender, $receiver);
        Schema::drop('transactions');
        try {
            (new ProcessTransfer($transfer->id))->handle();
            $this->fail('Expected failed ledger insert');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame('400000.00', $sender->fresh()->balance);
            $this->assertSame('0.00', $receiver->fresh()->balance);
            $this->assertSame('pending', $transfer->fresh()->status);
        }
        (require database_path('migrations/2026_09_12_044925_create_transactions_table.php'))->up();
        (new ProcessTransfer($transfer->id))->handle();
        $this->assertSame('success', $transfer->fresh()->status);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_enqueue_failure_rolls_back_transfer_record(): void
    {
        $sender = $this->account(400000);
        $receiver = $this->account(0);
        Schema::drop('jobs');
        $this->withToken($sender->createToken('access_token')->plainTextToken)
            ->postJson('/transfer', ['target_user' => $receiver->user_id, 'amount' => 1, 'remarks' => 'Test'])
            ->assertStatus(500);
        $this->assertDatabaseCount('transfers', 0);
        $this->assertSame('400000.00', $sender->fresh()->balance);
    }

    public function test_status_is_only_visible_to_sender_and_final_failure_is_recorded(): void
    {
        $sender = $this->account(400000);
        $receiver = $this->account(0);
        $transfer = $this->enqueue($sender, $receiver);
        (new ProcessTransfer($transfer->id))->failed(new \RuntimeException('Test'));
        $this->assertSame('failed', $transfer->fresh()->status);
        $this->app['auth']->forgetGuards();
        $this->withToken($receiver->createToken('access_token')->plainTextToken)
            ->getJson('/transfer/'.$transfer->transfer_id)->assertNotFound();
    }
}
