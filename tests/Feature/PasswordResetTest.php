<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/PasswordResetTest.php
 * Feature: Login / Auth
 * Purpose: Tests the Forgot password / Reset password flow: the link is emailed only to active
 *          accounts, the answer never reveals whether an email is registered, the new password must
 *          be strong, and a link works once.
 */

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('user_id');
            $table->string('full_name', 100);
            $table->string('email', 120);
            $table->string('password_hash', 255)->default('x');
            $table->string('role', 10);
            $table->unsignedInteger('client_id')->nullable();
            $table->string('status', 10)->default('active');
            $table->string('remember_token', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('sms_enabled')->default(false);
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->increments('activity_id');
            $table->string('activity_type', 50);
            $table->string('description', 255);
            $table->unsignedInteger('performed_by')->nullable();
            $table->string('performed_by_name', 100)->nullable();
            $table->string('performed_by_role', 10)->nullable();
            $table->text('details_json')->nullable();
            $table->dateTime('created_at')->useCurrent();
        });
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email', 120)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        $this->client = User::query()->create([
            'full_name' => 'Client User', 'email' => 'client@example.com', 'role' => 'client', 'status' => 'active',
            'password_hash' => Hash::make('Old-Password-1'),
        ]);
    }

    public function test_login_page_links_to_forgot_password(): void
    {
        $this->get('/login')->assertOk()->assertSee(route('password.request'), false)->assertSee('Forgot password?');
        $this->get('/forgot-password')->assertOk()->assertSee('Send reset link');
    }

    public function test_reset_link_is_emailed_to_an_active_account(): void
    {
        Mail::fake();

        $this->post('/forgot-password', ['email' => 'client@example.com'])
            ->assertRedirect(route('password.request'))->assertSessionHas('status');

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo('client@example.com')
            && str_contains((string) $mail->link, '/reset-password/') && $mail->linkLabel === 'Reset password');
    }

    public function test_unknown_and_inactive_emails_get_the_same_answer_and_no_email(): void
    {
        Mail::fake();
        User::query()->create(['full_name' => 'Old Staff', 'email' => 'old@example.com', 'role' => 'staff', 'status' => 'inactive']);

        $known = $this->post('/forgot-password', ['email' => 'client@example.com'])->getSession()->get('status');
        $this->flushSession();
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com'])->getSession()->get('status');
        $this->flushSession();
        $inactive = $this->post('/forgot-password', ['email' => 'old@example.com'])->getSession()->get('status');

        $this->assertSame($known, $unknown);
        $this->assertSame($known, $inactive);
        Mail::assertSent(NotificationMail::class, 1);
    }

    public function test_valid_token_sets_a_strong_password_once(): void
    {
        $token = Password::broker()->createToken($this->client);

        $this->get('/reset-password/'.$token.'?email=client@example.com')->assertOk()->assertSee('Choose a new password');

        $this->from('/reset-password/'.$token)->post('/reset-password', ['token' => $token, 'email' => 'client@example.com', 'password' => '12345678', 'password_confirmation' => '12345678'])
            ->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('Old-Password-1', $this->client->refresh()->password_hash));

        $this->post('/reset-password', ['token' => $token, 'email' => 'client@example.com', 'password' => 'Fresh-Password-7', 'password_confirmation' => 'Fresh-Password-7'])
            ->assertRedirect('/login.php')->assertSessionHas('auth_notice');
        $this->assertTrue(Hash::check('Fresh-Password-7', $this->client->refresh()->password_hash));

        // The same link cannot be used twice.
        $this->from('/reset-password/'.$token)->post('/reset-password', ['token' => $token, 'email' => 'client@example.com', 'password' => 'Another-Pass-8', 'password_confirmation' => 'Another-Pass-8'])
            ->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('Fresh-Password-7', $this->client->refresh()->password_hash));
    }

    public function test_wrong_token_is_refused(): void
    {
        Password::broker()->createToken($this->client);

        $this->from('/forgot-password')->post('/reset-password', ['token' => 'not-the-token', 'email' => 'client@example.com', 'password' => 'Fresh-Password-7', 'password_confirmation' => 'Fresh-Password-7'])
            ->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('Old-Password-1', $this->client->refresh()->password_hash));
    }
}
