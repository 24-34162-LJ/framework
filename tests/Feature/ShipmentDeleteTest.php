<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/ShipmentDeleteTest.php
 * Feature: Testing - Shipments
 * Purpose: Tests the admin-only shipment delete: typed-reference confirm, refusal when billed,
 *          and removal of every related row and stored file.
 */

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShipmentDeleteTest extends TestCase
{
    private User $admin;

    private User $staff;

    /** Tables holding rows that belong to a shipment and must go with it. */
    private const CHILD_TABLES = ['notification_deliveries', 'notifications', 'shipment_documents', 'deliveries', 'customs_processing', 'containers', 'shipment_status_updates', 'document_requests', 'transactions'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

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
        Schema::create('shipments', function (Blueprint $table): void {
            $table->increments('shipment_id');
            $table->unsignedInteger('client_id')->default(1);
            $table->string('reference_no', 30);
            $table->string('current_status', 60)->default('Trucking');
            $table->dateTime('archived_at')->nullable();
        });
        foreach (self::CHILD_TABLES as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->increments($name === 'transactions' ? 'transaction_id' : 'id');
                $table->unsignedInteger('shipment_id');
                if ($name === 'shipment_documents') {
                    $table->string('file_path', 255)->nullable();
                }
            });
        }
        Schema::create('transaction_history', function (Blueprint $table): void {
            $table->increments('history_id');
            $table->unsignedInteger('transaction_id');
        });
        Schema::create('billings', function (Blueprint $table): void {
            $table->increments('billing_id');
            $table->unsignedInteger('shipment_id');
        });
        Schema::create('shipment_requests', function (Blueprint $table): void {
            $table->increments('request_id');
            $table->unsignedInteger('shipment_id')->nullable();
        });
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->increments('activity_id');
            $table->string('activity_type', 50);
            $table->string('description', 255);
            $table->unsignedInteger('performed_by')->nullable();
            $table->string('performed_by_name', 100)->nullable();
            $table->string('performed_by_role', 10)->nullable();
            $table->text('details_json')->nullable();
        });

        $this->admin = User::query()->create(['full_name' => 'Admin', 'email' => 'a@x.com', 'role' => 'admin', 'status' => 'active']);
        $this->staff = User::query()->create(['full_name' => 'Staff', 'email' => 's@x.com', 'role' => 'staff', 'status' => 'active']);
    }

    /** A shipment with one row in every related table and one stored document file. */
    private function shipmentWithRecords(string $reference): int
    {
        $id = DB::table('shipments')->insertGetId(['reference_no' => $reference]);
        foreach (self::CHILD_TABLES as $name) {
            DB::table($name)->insert(['shipment_id' => $id] + ($name === 'shipment_documents' ? ['file_path' => "shipment-documents/{$id}/bl.pdf"] : []));
        }
        $transactionId = DB::table('transactions')->where('shipment_id', $id)->value('transaction_id');
        DB::table('transaction_history')->insert(['transaction_id' => $transactionId]);
        DB::table('shipment_requests')->insert(['shipment_id' => $id]);
        Storage::disk('local')->put("shipment-documents/{$id}/bl.pdf", 'pdf');

        return $id;
    }

    public function test_admin_deletes_the_shipment_and_everything_attached(): void
    {
        $id = $this->shipmentWithRecords('AST-2026-0009');
        $keep = $this->shipmentWithRecords('AST-2026-0010');

        $this->actingAs($this->admin)->delete("/shipments/{$id}", ['confirm_reference' => 'AST-2026-0009'])
            ->assertRedirect()->assertSessionHas('success', 'Shipment AST-2026-0009 was deleted.');

        $this->assertDatabaseMissing('shipments', ['shipment_id' => $id]);
        foreach (self::CHILD_TABLES as $name) {
            $this->assertSame(0, DB::table($name)->where('shipment_id', $id)->count(), $name.' rows left behind');
            $this->assertSame(1, DB::table($name)->where('shipment_id', $keep)->count(), $name.' of another shipment touched');
        }
        $this->assertSame(1, DB::table('transaction_history')->count(), 'only the other shipment\'s history remains');
        $this->assertSame(1, DB::table('shipment_requests')->whereNull('shipment_id')->count(), 'request is unlinked, not deleted');
        Storage::disk('local')->assertMissing("shipment-documents/{$id}/bl.pdf");
        Storage::disk('local')->assertExists("shipment-documents/{$keep}/bl.pdf");
        $this->assertDatabaseHas('activity_log', ['description' => 'Shipment AST-2026-0009 permanently deleted']);
    }

    public function test_billed_shipment_cannot_be_deleted(): void
    {
        $id = $this->shipmentWithRecords('AST-2026-0011');
        DB::table('billings')->insert(['shipment_id' => $id]);

        $this->actingAs($this->admin)->delete("/shipments/{$id}", ['confirm_reference' => 'AST-2026-0011'])
            ->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseHas('shipments', ['shipment_id' => $id]);
        Storage::disk('local')->assertExists("shipment-documents/{$id}/bl.pdf");
    }

    public function test_wrong_typed_reference_deletes_nothing(): void
    {
        $id = $this->shipmentWithRecords('AST-2026-0012');

        $this->actingAs($this->admin)->delete("/shipments/{$id}", ['confirm_reference' => 'AST-2026-001'])
            ->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseHas('shipments', ['shipment_id' => $id]);
    }

    public function test_staff_cannot_delete(): void
    {
        $id = $this->shipmentWithRecords('AST-2026-0013');

        $this->actingAs($this->staff)->delete("/shipments/{$id}", ['confirm_reference' => 'AST-2026-0013']);

        $this->assertDatabaseHas('shipments', ['shipment_id' => $id]);
    }
}
