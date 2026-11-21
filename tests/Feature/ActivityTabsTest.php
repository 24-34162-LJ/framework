<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/ActivityTabsTest.php
 * Feature: Testing - Admin activity
 * Purpose: Tests the merged Activity page: both tabs, search per tab, and that the old
 *          Shipment Updates URLs land on the right tab.
 */

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ActivityTabsTest extends TestCase
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
        Schema::create('shipments', function (Blueprint $table): void {
            $table->increments('shipment_id');
            $table->string('reference_no', 30);
            $table->string('current_status', 60)->default('Trucking');
        });
        Schema::create('shipment_status_updates', function (Blueprint $table): void {
            $table->increments('update_id');
            $table->unsignedInteger('shipment_id');
            $table->string('stage', 60);
            $table->string('status', 30);
            $table->string('remarks', 255)->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->unsignedInteger('notification_id')->primary();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('shipment_id')->nullable();
            $table->string('title');
            $table->text('message');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('read_at')->nullable();
        });

        $this->admin = User::query()->create(['full_name' => 'System Administrator', 'email' => 'a@x.com', 'role' => 'admin', 'status' => 'active']);
        DB::table('activity_log')->insert([
            ['activity_type' => 'User Account', 'description' => 'System Administrator logged in', 'performed_by_name' => 'System Administrator', 'performed_by_role' => 'admin'],
            ['activity_type' => 'Billing', 'description' => 'Bill BL-2026-000001 created', 'performed_by_name' => 'System Administrator', 'performed_by_role' => 'admin'],
        ]);
        $shipmentId = DB::table('shipments')->insertGetId(['reference_no' => 'AST-2026-0001']);
        DB::table('shipment_status_updates')->insert([
            'shipment_id' => $shipmentId, 'stage' => 'Delivered', 'status' => 'Completed', 'remarks' => 'Set from delivery details.',
            'updated_by' => $this->admin->user_id, 'updated_at' => now(),
        ]);
    }

    public function test_all_activity_is_the_default_tab(): void
    {
        $this->actingAs($this->admin)->get('/admin/activity')->assertOk()
            ->assertSee('All activity')->assertSee('Shipment updates')
            ->assertSee('Bill BL-2026-000001 created')
            ->assertDontSee('Set from delivery details.');
    }

    public function test_shipment_updates_tab_shows_the_status_timeline(): void
    {
        $this->actingAs($this->admin)->get('/admin/activity?tab=shipments')->assertOk()
            ->assertSee('AST-2026-0001')->assertSee('Set from delivery details.')
            ->assertDontSee('Bill BL-2026-000001 created');
    }

    public function test_search_stays_within_the_open_tab(): void
    {
        $this->actingAs($this->admin)->get('/admin/activity?q=logged')->assertOk()
            ->assertSee('System Administrator logged in')->assertDontSee('Bill BL-2026-000001 created')
            ->assertSee('name="q" value="logged"', false);
    }

    public function test_old_shipment_updates_urls_open_the_shipment_tab(): void
    {
        $this->actingAs($this->admin)->get('/client-management/client_activity.php')
            ->assertRedirect(route('admin.activity', ['tab' => 'shipments']));
        $this->actingAs($this->admin)->get('/admin/activity/shipments?q=AST')
            ->assertRedirect(route('admin.activity', ['tab' => 'shipments', 'q' => 'AST']));
    }

    public function test_sidebar_has_one_activity_item(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/activity')->getContent();

        $this->assertStringContainsString('<span>Activity</span>', $html);
        $this->assertStringNotContainsString('<span>Recent Activity</span>', $html);
        $this->assertStringNotContainsString('<span>Shipment Updates</span>', $html);
    }
}
