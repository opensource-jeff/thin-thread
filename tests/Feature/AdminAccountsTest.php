<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_users_cannot_access_admin_interface(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_admin_users_can_access_admin_interface(): void
    {
        $admin = User::factory()->admin()->create();

        $this->withoutMiddleware() ->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Account directory');
    }

    public function test_admin_users_can_create_update_and_delete_accounts(): void
    {
        $admin = User::factory()->admin()->create();

        $this->withoutMiddleware() ->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Thread Analyst',
                'email' => 'analyst@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'is_admin' => '1',
            ])
            ->assertRedirect();

        $createdUser = User::query()->where('email', 'analyst@example.com')->firstOrFail();

        $this->assertTrue($createdUser->is_admin);
        $this->assertTrue(Hash::check('password123', $createdUser->password));

        $this->withoutMiddleware() ->actingAs($admin)
            ->put(route('admin.users.update', $createdUser), [
                'name' => 'Thread Analyst Updated',
                'email' => 'analyst.updated@example.com',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ])
            ->assertRedirect();

        $createdUser->refresh();

        $this->assertSame('Thread Analyst Updated', $createdUser->name);
        $this->assertSame('analyst.updated@example.com', $createdUser->email);
        $this->assertFalse($createdUser->is_admin);
        $this->assertTrue(Hash::check('new-password123', $createdUser->password));

        $this->withoutMiddleware() ->actingAs($admin)
            ->delete(route('admin.users.destroy', $createdUser))
            ->assertRedirect();

        $this->assertDatabaseMissing('users', [
            'email' => 'analyst.updated@example.com',
        ]);
    }

    public function test_admin_users_cannot_delete_their_current_account(): void
    {
        $admin = User::factory()->admin()->create();

        $this->withoutMiddleware() ->actingAs($admin)
            ->delete(route('admin.users.destroy', $admin))
            ->assertSessionHasErrors('account');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
        ]);
    }
}
