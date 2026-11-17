<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/Notifications/ContactSettingsTest.php
 * Feature: Testing - Notifications
 * Purpose: Tests saving mobile number and SMS on/off settings.
 */

namespace Tests\Feature\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesNotificationTables;
use Tests\TestCase;

class ContactSettingsTest extends TestCase
{
    use CreatesNotificationTables;

    private User $client;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNotificationTables();
        $clientId = DB::table('clients')->insertGetId(['client_name' => 'Acme']);
        $this->client = User::query()->findOrFail(DB::table('users')->insertGetId([
            'full_name' => 'Client One', 'email' => 'c1@example.com', 'role' => 'client', 'client_id' => $clientId, 'status' => 'active', 'phone' => '639170000000',
        ]));
        $this->admin = User::query()->findOrFail(DB::table('users')->insertGetId([
            'full_name' => 'Admin', 'email' => 'admin@example.com', 'role' => 'admin', 'status' => 'active',
        ]));
    }

    public function test_client_can_save_mobile_and_turn_sms_off(): void
    {
        $this->actingAs($this->client)
            ->post(route('client.profile.contact'), ['phone' => '0917 123 4567'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->client->refresh();
        $this->assertSame('639171234567', $this->client->phone);
        $this->assertFalse($this->client->sms_enabled);
    }

    public function test_landline_is_rejected(): void
    {
        $this->actingAs($this->client)
            ->post(route('client.profile.contact'), ['phone' => '(02) 8123-4567', 'sms_enabled' => '1'])
            ->assertSessionHasErrors(['phone' => 'Enter a Philippine mobile number like 09171234567.']);

        $this->assertSame('639170000000', $this->client->refresh()->phone);
    }

    public function test_blank_phone_clears_it(): void
    {
        $this->actingAs($this->client)
            ->post(route('client.profile.contact'), ['phone' => '', 'sms_enabled' => '1'])
            ->assertSessionHasNoErrors();

        $this->client->refresh();
        $this->assertNull($this->client->phone);
        $this->assertTrue($this->client->sms_enabled);
    }

    public function test_profile_page_shows_sms_form_with_local_number(): void
    {
        $this->actingAs($this->client)->get(route('client.profile'))
            ->assertOk()
            ->assertSee('SMS updates')
            ->assertSee('value="09170000000"', false)
            ->assertSee('name="sms_enabled" value="1" checked', false)
            ->assertSee(route('client.profile.contact'), false);
    }

    public function test_admin_users_page_has_mobile_field(): void
    {
        $this->actingAs($this->admin)->get(route('admin.users'))
            ->assertOk()
            ->assertSee('id="fPhone"', false)
            ->assertSee('data-phone="09170000000"', false);
    }

    public function test_staff_cannot_use_client_contact_route(): void
    {
        $this->actingAs($this->admin)
            ->post(route('client.profile.contact'), ['phone' => '09171234567'])
            ->assertForbidden();

        $this->assertNull($this->admin->refresh()->phone);
    }

    public function test_admin_can_set_user_mobile(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.save'), [
            'action' => 'edit', 'user_id' => $this->client->user_id, 'full_name' => 'Client One', 'email' => 'c1@example.com',
            'role' => 'client', 'status' => 'active', 'client_id' => $this->client->client_id, 'phone' => '+63 918 555 0000',
        ])->assertSessionHasNoErrors();

        $this->assertSame('639185550000', $this->client->refresh()->phone);
    }

    public function test_admin_user_form_rejects_landline(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.save'), [
            'action' => 'edit', 'user_id' => $this->client->user_id, 'full_name' => 'Client One', 'email' => 'c1@example.com',
            'role' => 'client', 'status' => 'active', 'client_id' => $this->client->client_id, 'phone' => '8123-4567',
        ])->assertSessionHasErrors('phone');

        $this->assertSame('639170000000', $this->client->refresh()->phone);
    }
}
