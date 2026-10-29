<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_requires_name(): void
    {
        $this->post('/register', $this->registration(['name' => '']))
            ->assertSessionHasErrors(['name' => 'お名前を入力してください']);
    }

    public function test_register_requires_email(): void
    {
        $this->post('/register', $this->registration(['email' => '']))
            ->assertSessionHasErrors(['email' => 'メールアドレスを入力してください']);
    }

    public function test_register_requires_password_of_at_least_eight_characters(): void
    {
        $this->post('/register', $this->registration([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]))->assertSessionHasErrors(['password' => 'パスワードは8文字以上で入力してください']);
    }

    public function test_register_requires_matching_password_confirmation(): void
    {
        $this->post('/register', $this->registration([
            'password' => 'password',
            'password_confirmation' => 'different',
        ]))->assertSessionHasErrors(['password' => 'パスワードと一致しません']);
    }

    public function test_register_requires_password(): void
    {
        $this->post('/register', $this->registration([
            'password' => '',
            'password_confirmation' => '',
        ]))->assertSessionHasErrors(['password' => 'パスワードを入力してください']);
    }

    public function test_register_stores_a_general_user_and_sends_a_verification_mail(): void
    {
        Notification::fake();

        $this->post('/register', $this->registration())->assertRedirect('/verify-email');

        $user = User::query()->where('email', 'new-user@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame(1, (int) $user->role);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_verification_prompt_links_to_mailpit(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get('/verify-email')
            ->assertOk()
            ->assertSee('http://localhost:8025', false)
            ->assertSee('認証メールを再送する');
    }

    public function test_signed_verification_link_redirects_to_attendance(): void
    {
        $user = User::factory()->unverified()->create(['role' => 1]);
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect('/attendance');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_login_requires_email(): void
    {
        $this->post('/login', ['email' => '', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'メールアドレスを入力してください']);
    }

    public function test_login_requires_password(): void
    {
        $this->post('/login', ['email' => 'user@example.com', 'password' => ''])
            ->assertSessionHasErrors(['password' => 'パスワードを入力してください']);
    }

    public function test_login_rejects_unknown_credentials(): void
    {
        User::factory()->create(['email' => 'user@example.com']);

        $this->post('/login', ['email' => 'missing@example.com', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'ログイン情報が登録されていません']);
    }

    public function test_verified_general_user_is_sent_to_attendance(): void
    {
        $user = User::factory()->create(['role' => 1]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/attendance');
    }

    public function test_admin_is_sent_to_the_admin_attendance_list(): void
    {
        $admin = User::factory()->create(['role' => 2]);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('/admin/attendance/list');
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => '新規太郎',
            'email' => 'new-user@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ], $overrides);
    }
}
