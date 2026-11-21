<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/DeliveryStatusSyncTest.php
 * Feature: Testing - Shipments
 * Purpose: Tests that saving delivery as In Transit / Delivered moves the shipment status forward
 *          to Trucking / Delivered under the same rules as Update Status (customs release, arrival),
 *          never backwards, never out of a closed or archived shipment, and never cancels it.
 *          Also: saving Customs Details moves the shipment to the customs stage those details support.
 */

namespace Tests\Feature;

use App\Models\Shipment;
use App\Models\User;
use App\Services\ShipmentStageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class DeliveryStatusSyncTest extends TestCase
{
    /** @var list<string> stage names applyChange() was asked to move to */
    private array $moves = [];

    private ShipmentStageService $service;

    protected function setUp(): void
    {
        parent::setUp();

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
            $table->string('reference_no', 30);
            $table->string('current_status', 60);
            $table->string('mode', 10)->default('land');
            $table->date('actual_arrival_date')->nullable();
            $table->dateTime('archived_at')->nullable();
        });
        Schema::create('customs_processing', function (Blueprint $table): void {
            $table->increments('customs_id');
            $table->unsignedInteger('shipment_id');
            $table->string('entry_no', 50)->nullable();
            $table->date('lodgement_date')->nullable();
            $table->string('assessment_status', 10)->default('pending');
            $table->date('duties_paid_date')->nullable();
            $table->string('examination_lane', 10)->nullable();
            $table->date('clearance_date')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        Schema::create('deliveries', function (Blueprint $table): void {
            $table->increments('delivery_id');
            $table->unsignedInteger('shipment_id');
            $table->string('trucker', 100)->nullable();
            $table->string('driver_name', 100)->nullable();
            $table->string('delivery_address', 255)->nullable();
            $table->date('scheduled_date')->nullable();
            $table->string('status', 20)->default('scheduled');
        });
        DB::statement('CREATE VIEW v_billing_balance AS SELECT 0 AS billing_id, 0 AS client_id, 0 AS shipment_id, 0 AS balance WHERE 1 = 0');
        Schema::create('shipment_status_updates', function (Blueprint $table): void {
            $table->increments('update_id');
            $table->unsignedInteger('shipment_id');
            $table->string('stage', 60);
            $table->dateTime('updated_at')->nullable();
        });
        DB::table('shipment_stages')->insert([
            ['stage_name' => 'Documents received', 'stage_group' => 'Pending Documents', 'sort_order' => 10, 'is_closed' => false],
            ['stage_name' => 'Declaration filed', 'stage_group' => 'Customs Processing', 'sort_order' => 20, 'is_closed' => false],
            ['stage_name' => 'Assessment', 'stage_group' => 'Customs Processing', 'sort_order' => 30, 'is_closed' => false],
            ['stage_name' => 'Duties paid', 'stage_group' => 'Customs Processing', 'sort_order' => 40, 'is_closed' => false],
            ['stage_name' => 'Examination', 'stage_group' => 'Customs Processing', 'sort_order' => 50, 'is_closed' => false],
            ['stage_name' => 'Released/cleared', 'stage_group' => 'Customs Processing', 'sort_order' => 60, 'is_closed' => false],
            ['stage_name' => 'Trucking', 'stage_group' => 'In Transit', 'sort_order' => 70, 'is_closed' => false],
            ['stage_name' => 'Delivered', 'stage_group' => 'Delivered', 'sort_order' => 80, 'is_closed' => true],
            ['stage_name' => 'On Hold', 'stage_group' => 'On Hold', 'sort_order' => 90, 'is_closed' => false],
            ['stage_name' => 'Cancelled', 'stage_group' => 'Cancelled', 'sort_order' => 100, 'is_closed' => true],
        ]);

        // Record the move instead of writing timeline/transaction/notification rows.
        $service = Mockery::mock(ShipmentStageService::class)->makePartial();
        $service->shouldReceive('applyChange')->andReturnUsing(function (Shipment $shipment, $current, $target, $actor, $at, $timelineRemarks, $remarks, bool $correction = false): void {
            $this->moves[] = $target->stage_name.($correction ? ' (correction)' : '');
        });
        $this->service = $service;
    }

    /** A land shipment that has arrived (no customs needed) with its delivery set up, unless told otherwise. */
    private function shipment(string $status, bool $archived = false, string $mode = 'land', ?string $arrived = '2026-11-18', bool $delivery = true): Shipment
    {
        $id = DB::table('shipments')->insertGetId(['reference_no' => 'AST-1', 'current_status' => $status, 'archived_at' => $archived ? now() : null,
            'mode' => $mode, 'actual_arrival_date' => $arrived]);
        if ($delivery) {
            DB::table('deliveries')->insert(['shipment_id' => $id, 'trucker' => 'ABC Trucking', 'delivery_address' => 'Laguna',
                'scheduled_date' => '2026-11-19', 'status' => 'scheduled']);
        }

        return Shipment::query()->findOrFail($id);
    }

    private function user(string $role): User
    {
        return (new User)->forceFill(['user_id' => 1, 'role' => $role, 'status' => 'active']);
    }

    public function test_in_transit_moves_a_cleared_shipment_to_trucking(): void
    {
        $this->assertSame('Trucking', $this->service->syncFromDelivery($this->shipment('Released/cleared'), 'in_transit', $this->user('staff')));
        $this->assertSame(['Trucking'], $this->moves);
    }

    public function test_delivered_moves_a_trucking_shipment_to_delivered(): void
    {
        $this->assertSame('Delivered', $this->service->syncFromDelivery($this->shipment('Trucking'), 'delivered', $this->user('staff')));
        $this->assertSame(['Delivered'], $this->moves);
    }

    public function test_scheduled_delivery_leaves_the_status_alone(): void
    {
        $this->assertNull($this->service->syncFromDelivery($this->shipment('Trucking'), 'scheduled', $this->user('admin')));
        $this->assertSame([], $this->moves);
    }

    public function test_cancelled_delivery_never_cancels_the_shipment(): void
    {
        $this->assertNull($this->service->syncFromDelivery($this->shipment('Trucking'), 'cancelled', $this->user('admin')));
        $this->assertSame([], $this->moves);
    }

    public function test_delivery_form_never_corrects_a_closed_shipment_backwards(): void
    {
        $this->assertNull($this->service->syncFromDelivery($this->shipment('Delivered'), 'in_transit', $this->user('admin')));
        $this->assertNull($this->service->syncFromDelivery($this->shipment('Cancelled'), 'delivered', $this->user('admin')));
        $this->assertSame([], $this->moves);
    }

    public function test_sea_shipment_needs_customs_release_before_trucking(): void
    {
        $shipment = $this->shipment('Released/cleared', mode: 'sea');
        $this->assertStringContainsString('Trucking needs Entry No.', $this->service->deliveryBlocker($shipment, 'in_transit'));
        $this->assertNull($this->service->syncFromDelivery($shipment, 'in_transit', $this->user('staff')));

        DB::table('customs_processing')->insert(['shipment_id' => $shipment->shipment_id, 'entry_no' => 'C-1', 'lodgement_date' => '2026-11-10',
            'assessment_status' => 'paid', 'duties_paid_date' => '2026-11-12', 'examination_lane' => 'green', 'clearance_date' => '2026-11-14', 'updated_at' => now()]);
        $this->assertNull($this->service->deliveryBlocker($shipment, 'in_transit'));
        $this->assertSame('Trucking', $this->service->syncFromDelivery($shipment, 'in_transit', $this->user('staff')));
    }

    public function test_trucking_needs_the_actual_arrival(): void
    {
        $shipment = $this->shipment('Released/cleared', arrived: null);
        $this->assertStringContainsString('the Actual Arrival date', $this->service->deliveryBlocker($shipment, 'in_transit'));
        $this->assertNull($this->service->syncFromDelivery($shipment, 'in_transit', $this->user('staff')));
        $this->assertSame([], $this->moves);
    }

    public function test_trucking_needs_delivery_information_set_up(): void
    {
        $shipment = $this->shipment('Released/cleared', delivery: false);
        $this->assertStringContainsString('a scheduled delivery', $this->service->deliveryBlocker($shipment, 'in_transit'));
        $this->assertNull($this->service->syncFromDelivery($shipment, 'in_transit', $this->user('staff')));

        // a delivery without an address or trucker is not enough
        DB::table('deliveries')->insert(['shipment_id' => $shipment->shipment_id, 'scheduled_date' => '2026-11-19', 'status' => 'in_transit']);
        $blocker = $this->service->deliveryBlocker($shipment, 'in_transit');
        $this->assertStringContainsString('delivery address', $blocker);
        $this->assertStringContainsString('trucker or driver', $blocker);
        $this->assertStringNotContainsString('pickup schedule date', $blocker);

        DB::table('deliveries')->update(['delivery_address' => 'Laguna', 'driver_name' => 'Juan']);
        $this->assertSame('Trucking', $this->service->syncFromDelivery($shipment, 'in_transit', $this->user('staff')));
    }

    public function test_a_cancelled_delivery_does_not_count(): void
    {
        $shipment = $this->shipment('Released/cleared', delivery: false);
        DB::table('deliveries')->insert(['shipment_id' => $shipment->shipment_id, 'trucker' => 'ABC', 'delivery_address' => 'Laguna',
            'scheduled_date' => '2026-11-19', 'status' => 'cancelled']);
        $this->assertSame(['a scheduled delivery (pickup date, delivery address, trucker or driver)'],
            app(\App\Services\ShipmentConsistencyService::class)->deliveryMissingFor('Trucking', $shipment));
        $this->assertSame([], app(\App\Services\ShipmentConsistencyService::class)->deliveryMissingFor('Released/cleared', $shipment));
    }

    /** Customs Details for a shipment; $fields override the defaults (a declaration only). */
    private function customs(Shipment $shipment, array $fields): void
    {
        DB::table('customs_processing')->insert(array_merge(['shipment_id' => $shipment->shipment_id, 'entry_no' => 'C-1', 'lodgement_date' => '2026-11-10',
            'assessment_status' => 'pending', 'updated_at' => now()], $fields));
    }

    public function test_customs_details_move_the_shipment_to_the_stage_they_support(): void
    {
        $shipment = $this->shipment('Documents received', mode: 'sea');
        $this->customs($shipment, ['assessment_status' => 'assessed']);

        $this->assertSame(['Assessment', null], $this->service->syncFromCustoms($shipment, $this->user('staff')));
        $this->assertSame(['Assessment'], $this->moves);
    }

    public function test_green_lane_skips_examination_and_goes_to_release(): void
    {
        $shipment = $this->shipment('Assessment', mode: 'sea');
        $this->customs($shipment, ['assessment_status' => 'paid', 'duties_paid_date' => '2026-11-12', 'examination_lane' => 'green', 'clearance_date' => '2026-11-14']);
        $this->assertSame(['Released/cleared', null], $this->service->syncFromCustoms($shipment, $this->user('staff')));

        $noClearance = $this->shipment('Assessment', mode: 'sea');
        $this->customs($noClearance, ['assessment_status' => 'paid', 'duties_paid_date' => '2026-11-12', 'examination_lane' => 'green']);
        $this->assertSame(['Duties paid', null], $this->service->syncFromCustoms($noClearance, $this->user('staff')), 'green lane is never "Examination"');
    }

    public function test_examination_and_release_wait_for_the_arrival(): void
    {
        $shipment = $this->shipment('Assessment', mode: 'sea', arrived: null);
        $this->customs($shipment, ['assessment_status' => 'paid', 'duties_paid_date' => '2026-11-12', 'examination_lane' => 'red', 'clearance_date' => '2026-11-14']);

        [$moved, $note] = $this->service->syncFromCustoms($shipment, $this->user('staff'));
        $this->assertSame('Duties paid', $moved);
        $this->assertStringContainsString('Actual Arrival', $note);
    }

    public function test_customs_never_move_a_shipment_backwards_or_out_of_hold(): void
    {
        $ahead = $this->shipment('Trucking', mode: 'sea');
        $this->customs($ahead, ['assessment_status' => 'assessed']);
        $held = $this->shipment('On Hold', mode: 'sea');
        $this->customs($held, ['assessment_status' => 'assessed']);

        $this->assertSame([null, null], $this->service->syncFromCustoms($ahead, $this->user('staff')));
        $this->assertSame([null, null], $this->service->syncFromCustoms($held, $this->user('staff')));
        $this->assertSame([], $this->moves);
    }

    public function test_same_stage_or_archived_shipment_does_nothing(): void
    {
        $this->assertNull($this->service->syncFromDelivery($this->shipment('Trucking'), 'in_transit', $this->user('admin')), 'already Trucking');
        $this->assertNull($this->service->syncFromDelivery($this->shipment('Trucking', archived: true), 'delivered', $this->user('admin')));
        $this->assertSame([], $this->moves);
    }
}
