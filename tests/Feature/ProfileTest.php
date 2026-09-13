<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);

        // Isolate tests from the legacy migration with duplicate transaction columns.
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_09_12_045733_create_personal_access_tokens_table.php',
            '2026_09_12_045934_add_balance_to_users_table.php',
            '2026_09_12_055137_add_register_fields_to_users_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    private function account(): User
    {
        return User::forceCreate([
            'user_id' => (string) Str::uuid(),
            'name' => 'Profile Test',
            'email' => Str::uuid().'@example.test',
            'password' => 'test-password',
            'first_name' => 'Original',
            'last_name' => 'User',
            'address' => 'Original address',
            'phone_number' => (string) Str::uuid(),
            'pin' => 'original-pin-hash',
            'balance' => 500000,
        ])->refresh();
    }

    private function profile(): array
    {
        return ['first_name' => 'Tom', 'last_name' => 'Araya', 'address' => 'Jl. Diponegoro No. 215'];
    }

    public function test_bearer_token_updates_profile_with_exact_response_on_both_routes(): void
    {
        $this->travelTo(now()->setDate(2021, 4, 1)->setTime(23, 0, 20));
        foreach (['/profile', '/api/profile'] as $path) {
            $user = $this->account();
            $this->withToken($user->createToken('profile')->plainTextToken)
                ->putJson($path, $this->profile())->assertOk()->assertExactJson([
                    'status' => 'SUCCESS',
                    'result' => array_merge(['user_id' => $user->user_id], $this->profile(), [
                        'updated_date' => '2021-04-01 23:00:20',
                    ]),
                ]);
            $this->assertDatabaseHas('users', array_merge(['id' => $user->id], $this->profile()));
            $this->app['auth']->forgetGuards();
        }
        $this->travelBack();
    }

    public function test_extra_fields_cannot_change_identity_credentials_balance_or_other_users(): void
    {
        $user = $this->account();
        $other = $this->account();
        $before = $user->getAttributes();
        $otherBefore = $other->getAttributes();

        $this->withToken($user->createToken('profile')->plainTextToken)
            ->putJson('/profile', array_merge($this->profile(), [
                'id' => $other->id, 'user_id' => $other->user_id,
                'phone_number' => $other->phone_number, 'balance' => 999999,
                'pin' => '123456', 'password' => 'replacement',
                'email' => 'replacement@example.test', 'name' => 'Replacement',
                'created_at' => '2000-01-01 00:00:00',
                'updated_at' => '2000-01-01 00:00:00',
            ]))->assertOk()->assertJsonPath('result.user_id', $user->user_id);

        $user->refresh();
        foreach (['id', 'user_id', 'phone_number', 'balance', 'pin', 'password', 'email', 'name', 'created_at'] as $field) {
            $this->assertSame($before[$field], $user->getAttributes()[$field], $field);
        }
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
    }

    public function test_fields_are_required_strings_with_database_length_limits(): void
    {
        $user = $this->account();
        $before = $user->getAttributes();
        $this->withToken($user->createToken('profile')->plainTextToken);

        foreach (['first_name', 'last_name', 'address'] as $field) {
            foreach ([null, '', 123, true, ['invalid'], str_repeat('a', 256)] as $invalid) {
                $body = $this->profile();
                $body[$field] = $invalid;
                $this->putJson('/profile', $body)->assertUnprocessable()->assertJsonValidationErrors($field);
            }
            $body = $this->profile();
            unset($body[$field]);
            $this->putJson('/profile', $body)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_missing_or_invalid_token_returns_exact_json_without_accept_header(): void
    {
        foreach (['/profile', '/api/profile'] as $path) {
            $this->put($path, $this->profile())->assertUnauthorized()
                ->assertExactJson(['message' => 'Unauthenticated']);
            $this->withToken('invalid')->put($path, $this->profile())->assertUnauthorized()
                ->assertExactJson(['message' => 'Unauthenticated']);
            $this->flushHeaders();
        }
    }
}
