<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFrontendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Administrator', 'password' => 'password']
        );
    }

    public function test_login_page_is_public()
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('Exam Prep Admin');
        $response->assertSee('Email');
        $response->assertSee('Password');
    }

    public function test_admin_pages_require_authentication()
    {
        $this->get('/admin/grades')->assertRedirect('/login');
        $this->get('/admin/dashboard')->assertRedirect('/login');
    }

    public function test_every_sidebar_page_renders_for_an_authenticated_admin(): void
    {
        $this->actingAs(User::where('email', 'admin@example.com')->first());

        foreach ([
            '/admin/dashboard', '/admin/students', '/admin/results', '/admin/grades',
            '/admin/subjects', '/admin/chapters', '/admin/topics', '/admin/notes',
            '/admin/questions', '/admin/exams', '/admin/announcements',
        ] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_login_returns_token_and_user()
    {
        $response = $this->post('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
            'device_name' => 'test',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'user']);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_session_login_lands_on_the_session_authenticated_dashboard()
    {
        $token = 'test-session-csrf-token';
        $this->withSession(['_token' => $token])->post('/login', [
            '_token' => $token,
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->assertRedirect('/admin/dashboard');

        $this->get('/admin')->assertRedirect('/admin/dashboard');
        $this->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('Questions answered')
            ->assertDontSee('Sign in to manage content and students');
        $this->getJson('/api/dashboard')->assertOk();
    }

    public function test_authenticated_admin_visiting_login_is_sent_to_dashboard()
    {
        $this->actingAs(User::where('email', 'admin@example.com')->first());

        $this->get('/login')->assertRedirect('/admin/dashboard');
    }

    public function test_students_list_is_available_to_the_session_authenticated_admin(): void
    {
        $student = Student::create([
            'telegram_id' => 88990011,
            'first_name' => 'Telegram Test Student',
        ]);

        $this->actingAs(User::where('email', 'admin@example.com')->first())
            ->getJson('/api/students')
            ->assertOk()
            ->assertJsonPath('data.0.id', $student->id)
            ->assertJsonPath('data.0.first_name', 'Telegram Test Student');
    }
}
