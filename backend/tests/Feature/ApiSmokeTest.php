<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_health(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('db', 'ok');
    }

    public function test_register_and_me(): void
    {
        $reg = $this->postJson('/api/auth/register', [
            'email' => 'user@example.com',
            'password' => 'password1',
            'display_name' => 'Player',
        ]);
        $reg->assertCreated()
            ->assertJsonStructure(['access_token', 'user' => ['id', 'email', 'display_name']]);

        $token = $reg->json('access_token');
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('email', 'user@example.com');
    }

    public function test_bearer_login_from_frontend_origin_does_not_require_csrf_cookie(): void
    {
        config()->set('sanctum.stateful', ['127.0.0.1:5173']);

        User::factory()->create([
            'email' => 'owner@example.com',
            'password' => 'password1',
        ]);

        $this->withHeader('Origin', 'http://127.0.0.1:5173')
            ->postJson('/api/auth/login', [
                'email' => 'owner@example.com',
                'password' => 'password1',
            ])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'user'])
            ->assertJsonPath('user.email', 'owner@example.com');
    }

    public function test_favorites_require_auth(): void
    {
        $this->getJson('/api/me/favorites')->assertUnauthorized();
    }

    public function test_empty_prices_query(): void
    {
        $this->getJson('/api/prices?q=')
            ->assertStatus(400);
    }
}
