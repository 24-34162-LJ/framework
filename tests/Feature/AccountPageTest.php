<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/AccountPageTest.php
 * Feature: Testing - Account
 * Purpose: Tests the admin/staff My Account page: viewing it, saving details, changing the password,
 *          and that clients and guests cannot use it.
 */

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccountPageTest extends TestCase
{
    private User $admin;

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
        // The page header's notification bell reads this.
        Schema::create('notifications', function (Blueprint $table): void {
            $table->unsignedInteger('notification_id')->primary();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('shipment_id')->nullable();
            $table->string('title');
            $table->text('message');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('read_at')->nullable();
        });

        $this->admin = User::query()->create([
            'full_name' => 'System Administrator', 'email' => 'admin@example.com', 'role' => 'admin', 'status' => 'active',
            'password_hash' => Hash::make('old-password-1'),
        ]);
    }

    public function test_admin_sees_their_account_and_recent_activity(): void
    {
        DB::table('activity_log')->insert(['activity_type' => 'Shipment Update', 'description' => 'Status of AST-2026-0001 updated to Delivered', 'performed_by' => $this->admin->user_id]);

        $this->actingAs($this->admin)->get('/account')->assertOk()
            ->assertSee('My Account')->assertSee('admin@example.com')
            ->assertSee('Status of AST-2026-0001 updated to Delivered')
            ->assertSee(route('account'), false);
    }

    public function test_details_are_saved_and_sms_needs_a_number(): void
    {
        $this->actingAs($this->admin)->post('/account/details', ['full_name' => '  Maria   Santos ', 'phone' => '09171234567', 'sms_enabled' => '1'])
            ->assertRedirect(route('account'))->assertSessionHas('account_success');
        $this->admin->refresh();
        $this->assertSame('Maria Santos', $this->admin->full_name);
        $this->assertNotNull($this->admin->phone);
        $this->assertTrue((bool) $this->admin->sms_enabled);

        $this->actingAs($this->admin)->post('/account/details', ['full_name' => 'Maria Santos', 'phone' => '', 'sms_enabled' => '1']);
        $this->assertFalse((bool) $this->admin->refresh()->sms_enabled, 'SMS stays off without a number');
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->actingAs($this->admin)->from('/account')->post('/account/details', ['full_name' => 'Admin', 'phone' => '12345'])
            ->assertRedirect('/account')->assertSessionHasErrorsIn('details', 'phone');
    }

    public function test_password_change_needs_the_current_password(): void
    {
        $this->actingAs($this->admin)->from('/account')->post('/account/password', ['current_password' => 'wrong', 'password' => 'new-password-2', 'password_confirmation' => 'new-password-2'])
            ->assertSessionHasErrorsIn('password', 'current_password');
        $this->assertTrue(Hash::check('old-password-1', $this->admin->refresh()->password_hash));

        $this->actingAs($this->admin)->post('/account/password', ['current_password' => 'old-password-1', 'password' => 'New-Password-2', 'password_confirmation' => 'New-Password-2'])
            ->assertSessionHas('password_success');
        $this->assertTrue(Hash::check('New-Password-2', $this->admin->refresh()->password_hash));
        $this->assertDatabaseHas('activity_log', ['description' => 'Own password changed', 'performed_by' => $this->admin->user_id]);
    }

    public function test_weak_new_passwords_are_rejected(): void
    {
        foreach (['11111111', 'short1A', 'alllowercase123', 'ALLUPPERCASE123', 'NoDigitsHere', 'Password123'] as $weak) {
            $this->actingAs($this->admin)->from('/account')->post('/account/password', ['current_password' => 'old-password-1', 'password' => $weak, 'password_confirmation' => $weak])
                ->assertSessionHasErrorsIn('password', 'password');
        }
        $this->assertTrue(Hash::check('old-password-1', $this->admin->refresh()->password_hash));
    }

    public function test_password_change_signs_out_other_sessions(): void
    {
        // Another device's session remembers the password hash from when it signed in.
        $oldHash = $this->admin->password_hash;
        $this->actingAs($this->admin)->withSession(['password_hash_web' => $oldHash])->get('/account')->assertOk();

        $this->admin->forceFill(['password_hash' => Hash::make('Brand-New-Pass-9')])->save();

        $this->actingAs($this->admin->refresh())->withSession(['password_hash_web' => $oldHash])
            ->get('/account')->assertRedirect();
        $this->assertGuest();
    }

    public function test_pages_send_security_headers(): void
    {
        $this->get('/login')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
    }

    public function test_password_confirmation_must_match(): void
    {
        $this->actingAs($this->admin)->from('/account')->post('/account/password', ['current_password' => 'old-password-1', 'password' => 'new-password-2', 'password_confirmation' => 'other-password'])
            ->assertSessionHasErrorsIn('password', 'password');
        $this->assertTrue(Hash::check('old-password-1', $this->admin->refresh()->password_hash));
    }

    public function test_clients_and_guests_cannot_open_it(): void
    {
        $this->get('/account')->assertRedirect();
        $client = User::query()->create(['full_name' => 'Client', 'email' => 'c@example.com', 'role' => 'client', 'status' => 'active']);
        $this->actingAs($client)->get('/account')->assertForbidden();
    }
}
