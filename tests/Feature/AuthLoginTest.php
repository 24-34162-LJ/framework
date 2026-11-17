<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/AuthLoginTest.php
 * Feature: Testing - Security
 * Purpose: Tests sign-in by email or name (including shared names) and the "Remember me" cookie.
 */

namespace Tests\Feature;

use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreatesNotificationTables;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use CreatesNotificationTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNotificationTables();
        (require database_path('migrations/2026_11_17_000002_add_remember_token_to_users.php'))->up();
        $this->mock(ActivityLogger::class)->shouldReceive('record');
    }

    private function staff(string $name, string $email, string $password): int
    {
        return DB::table('users')->insertGetId([
            'full_name' => $name, 'email' => $email, 'password_hash' => Hash::make($password), 'role' => 'staff',
        ]);
    }

    public function test_two_users_with_the_same_name_can_both_sign_in_by_name(): void
    {
        $first = $this->staff('Maria Santos', 'maria1@example.com', 'first-pass');
        $second = $this->staff('Maria Santos', 'maria2@example.com', 'second-pass');

        $this->post('/login', ['login' => 'Maria Santos', 'password' => 'second-pass'])->assertRedirect('/account-management/staff.php');
        $this->assertSame($second, Auth::id());

        Auth::logout();
        $this->post('/login', ['login' => 'Maria Santos', 'password' => 'first-pass'])->assertRedirect('/account-management/staff.php');
        $this->assertSame($first, Auth::id());
    }

    public function test_wrong_password_is_still_refused(): void
    {
        $this->staff('Maria Santos', 'maria1@example.com', 'first-pass');

        $this->post('/login', ['login' => 'Maria Santos', 'password' => 'nope'])->assertRedirect('/login.php');
        $this->assertGuest();
    }

    public function test_remember_me_sets_a_remember_token(): void
    {
        $id = $this->staff('Ana Reyes', 'ana@example.com', 'secret-pass');

        $response = $this->post('/login', ['login' => 'ana@example.com', 'password' => 'secret-pass', 'remember' => '1']);

        $response->assertCookie(Auth::guard()->getRecallerName());
        $this->assertNotNull(DB::table('users')->where('user_id', $id)->value('remember_token'));
    }

    public function test_no_remember_cookie_without_remember_me(): void
    {
        $this->staff('Ana Reyes', 'ana@example.com', 'secret-pass');

        $this->post('/login', ['login' => 'ana@example.com', 'password' => 'secret-pass'])
            ->assertCookieMissing(Auth::guard()->getRecallerName());
    }
}
