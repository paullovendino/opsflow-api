<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const STATEFUL_ORIGIN = 'http://localhost:5173';

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@opsflow.test',
        ]);

        $response = $this->withHeader('Origin', self::STATEFUL_ORIGIN)
            ->postJson('/api/v1/auth/login', [
                'email' => 'admin@opsflow.test',
                'password' => 'password',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('errors', null)
            ->assertJsonPath('meta', null)
            ->assertJsonMissingPath('data.user.password');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'admin@opsflow.test',
        ]);

        $response = $this->withHeader('Origin', self::STATEFUL_ORIGIN)
            ->postJson('/api/v1/auth/login', [
                'email' => 'admin@opsflow.test',
                'password' => 'wrong-password',
            ]);

        $response->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid credentials.')
            ->assertJsonPath('data', null)
            ->assertJsonPath('errors', null);

        $this->assertGuest();
    }

    public function test_login_validation_fails_when_fields_are_missing(): void
    {
        $response = $this->withHeader('Origin', self::STATEFUL_ORIGIN)
            ->postJson('/api/v1/auth/login', []);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonStructure([
                'errors' => [
                    'email',
                    'password',
                ],
            ]);
    }

    public function test_authenticated_user_can_retrieve_me(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonMissingPath('data.password');
    }

    public function test_unauthenticated_user_cannot_retrieve_me(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_authenticated_user_cannot_login_again(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Origin', self::STATEFUL_ORIGIN)
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Origin', self::STATEFUL_ORIGIN)
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $response->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Already authenticated.');
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Origin', self::STATEFUL_ORIGIN)
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertOk();

        $this->assertAuthenticatedAs($user);

        $response = $this->withHeader('Origin', self::STATEFUL_ORIGIN)
            ->postJson('/api/v1/auth/logout');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Logout successful.');

        // Clear in-memory guard state so the next request reloads auth from the session.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Origin', self::STATEFUL_ORIGIN)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}
