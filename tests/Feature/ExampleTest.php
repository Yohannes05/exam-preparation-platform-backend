<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The root redirects to login when not authenticated.
     */
    public function test_the_application_root_redirects_to_login_when_not_authenticated(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    /**
     * The root serves the admin SPA when authenticated.
     */
    public function test_the_application_root_redirects_to_admin_spa_when_authenticated(): void
    {
        $this->actingAs(User::firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Administrator', 'password' => 'password']
        ));

        $response = $this->get('/');

        $response->assertRedirect('/admin');
    }
}
