<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/ShipmentClosedGuardsTest.php
 * Feature: Testing - Shipments
 * Purpose: Tests that delivered / cancelled / archived shipments cannot be changed.
 */

namespace Tests\Feature;

use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Closed (delivered/cancelled) and archived shipments must not be changed through the side doors:
 * customs/delivery saves, bulk assign and reassign. Mirrors the rule the Edit page already uses:
 * archived = read-only for everyone, closed = read-only for staff (admins may still correct).
 */
class ShipmentClosedGuardsTest extends TestCase
{
    private User $admin;

    private User $staff;

    private User $otherStaff;

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
        Schema::create('shipment_stages', function (Blueprint $table): void {
            $table->increments('stage_id');
            $table->string('stage_name', 60);
            $table->string('stage_group', 40);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_closed')->default(false);
        });
        Schema::create('shipments', function (Blueprint $table): void {
            $table->increments('shipment_id');
            $table->unsignedInteger('client_id')->default(1);
            $table->unsignedInteger('assigned_staff_id')->nullable();
            $table->string('reference_no', 30);
            $table->string('current_status', 60);
            $table->dateTime('archived_at')->nullable();
        });

        DB::table('shipment_stages')->insert([
            ['stage_name' => 'Customs Processing', 'stage_group' => 'Customs Processing', 'sort_order' => 3, 'is_closed' => false],
            ['stage_name' => 'Delivered', 'stage_group' => 'Delivered', 'sort_order' => 9, 'is_closed' => true],
        ]);

        $this->admin = User::query()->create(['full_name' => 'Admin', 'email' => 'a@x.com', 'role' => 'admin', 'status' => 'active']);
        $this->staff = User::query()->create(['full_name' => 'Staff One', 'email' => 's@x.com', 'role' => 'staff', 'status' => 'active']);
        $this->otherStaff = User::query()->create(['full_name' => 'Staff Two', 'email' => 't@x.com', 'role' => 'staff', 'status' => 'active']);
    }

    private function shipment(string $status, bool $archived = false, ?int $staffId = null): Shipment
    {
        $id = DB::table('shipments')->insertGetId([
            'reference_no' => 'AST-'.random_int(1000, 9999), 'current_status' => $status,
            'archived_at' => $archived ? now() : null, 'assigned_staff_id' => $staffId,
        ]);

        return Shipment::query()->findOrFail($id);
    }

    /** Invalid on purpose (customs value 0): an allowed request stops at validation, so nothing is written. */
    private function invalidCustoms(): array
    {
        return ['declaration_type' => 'Consumption', 'customs_value' => '0', 'assessment_status' => 'pending'];
    }

    // ---- Shipment model rule ----

    public function test_office_change_rule_matches_the_edit_page(): void
    {
        $open = $this->shipment('Customs Processing', staffId: $this->staff->user_id);
        $closed = $this->shipment('Delivered');
        $archived = $this->shipment('Delivered', archived: true);

        $this->assertNull($open->officeChangeBlockedFor($this->staff));
        $this->assertNull($closed->officeChangeBlockedFor($this->admin), 'admins may correct closed shipments');
        $this->assertNotNull($closed->officeChangeBlockedFor($this->staff));
        $this->assertNotNull($archived->officeChangeBlockedFor($this->admin), 'archived is read-only for everyone');

        $this->assertTrue($open->isOpen());
        $this->assertFalse($closed->isOpen());
        $this->assertFalse($archived->isOpen());
    }

    // ---- Bug 1: customs / delivery saves ----

    public function test_staff_cannot_save_customs_or_delivery_on_a_closed_shipment(): void
    {
        $closed = $this->shipment('Delivered');

        $this->actingAs($this->staff)->post("/shipments/{$closed->shipment_id}/customs", $this->invalidCustoms())
            ->assertRedirect()->assertSessionHas('error')->assertSessionDoesntHaveErrors('customs_value');
        $this->actingAs($this->staff)->post("/shipments/{$closed->shipment_id}/delivery", ['status' => 'scheduled'])
            ->assertRedirect()->assertSessionHas('error')->assertSessionDoesntHaveErrors('scheduled_date');
    }

    public function test_nobody_can_save_customs_or_delivery_on_an_archived_shipment(): void
    {
        $archived = $this->shipment('Delivered', archived: true);

        $this->actingAs($this->admin)->post("/shipments/{$archived->shipment_id}/customs", $this->invalidCustoms())
            ->assertRedirect()->assertSessionHas('error')->assertSessionDoesntHaveErrors('customs_value');
        $this->actingAs($this->admin)->post("/shipments/{$archived->shipment_id}/delivery", ['status' => 'scheduled'])
            ->assertRedirect()->assertSessionHas('error')->assertSessionDoesntHaveErrors('scheduled_date');
    }

    public function test_open_and_admin_on_closed_still_reach_normal_validation(): void
    {
        $open = $this->shipment('Customs Processing', staffId: $this->staff->user_id);
        $closed = $this->shipment('Delivered');

        $this->actingAs($this->staff)->post("/shipments/{$open->shipment_id}/customs", $this->invalidCustoms())
            ->assertSessionHasErrors('customs_value')->assertSessionMissing('error');
        $this->actingAs($this->admin)->post("/shipments/{$closed->shipment_id}/delivery", ['status' => 'scheduled'])
            ->assertSessionHasErrors('scheduled_date')->assertSessionMissing('error');
    }

    // ---- Bug 2: bulk assign ----

    public function test_bulk_assign_refuses_closed_and_archived_shipments(): void
    {
        $closed = $this->shipment('Delivered');
        $archived = $this->shipment('Delivered', archived: true);

        foreach ([$closed, $archived] as $shipment) {
            $this->actingAs($this->admin)->postJson('/admin/shipments/assign', ['staff_id' => $this->staff->user_id, 'shipment_ids' => [$shipment->shipment_id]])
                ->assertStatus(409)->assertJsonPath('error', fn (string $error) => str_contains($error, $shipment->reference_no));
            $this->assertNull($shipment->fresh()->assigned_staff_id);
        }
    }

    // ---- Bug 3: reassign ----

    public function test_reassign_refuses_closed_and_archived_shipments(): void
    {
        foreach ([$this->shipment('Delivered', staffId: $this->staff->user_id), $this->shipment('Delivered', archived: true, staffId: $this->staff->user_id)] as $shipment) {
            $this->actingAs($this->admin)->post("/shipments/{$shipment->shipment_id}/reassign", ['staff_id' => $this->otherStaff->user_id])
                ->assertRedirect()->assertSessionHas('error');
            $this->assertSame($this->staff->user_id, (int) $shipment->fresh()->assigned_staff_id);
        }
    }

    public function test_reassign_to_the_same_staff_member_changes_nothing(): void
    {
        $shipment = $this->shipment('Customs Processing', staffId: $this->staff->user_id);

        $this->actingAs($this->admin)->post("/shipments/{$shipment->shipment_id}/reassign", ['staff_id' => $this->staff->user_id])
            ->assertRedirect()->assertSessionHas('error', fn (string $message) => str_contains($message, 'already assigned'));
        $this->assertSame($this->staff->user_id, (int) $shipment->fresh()->assigned_staff_id);
    }

    // ---- Bug 4: logout ----

    public function test_get_logout_url_no_longer_signs_the_user_out(): void
    {
        $this->actingAs($this->admin)->get('/logout.php')->assertRedirect();
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_post_logout_signs_the_user_out(): void
    {
        $this->actingAs($this->admin)->post('/logout')->assertRedirect();
        $this->assertGuest();
    }
}
