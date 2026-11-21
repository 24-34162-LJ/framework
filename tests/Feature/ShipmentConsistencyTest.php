<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/ShipmentConsistencyTest.php
 * Feature: Testing - Shipments
 * Purpose: Proves the rules that connect shipment status, Customs Details, bills and payments:
 *          customs stages need their customs facts (also on the server), release with an unpaid
 *          balance warns or blocks, bill charges must match customs, and the checklist / report
 *          flag a shipment whose records disagree.
 */

namespace Tests\Feature;

use App\Models\Shipment;
use App\Models\User;
use App\Services\ShipmentConsistencyService;
use App\Services\ShipmentStageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShipmentConsistencyTest extends TestCase
{
    private User $staff;

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
            $table->date('actual_arrival_date')->nullable();
            $table->date('eta_date')->nullable();
            $table->date('first_free_day')->nullable();
            $table->date('delivery_due_date')->nullable();
            $table->string('mode', 10)->default('sea');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('archived_at')->nullable();
        });
        Schema::create('deliveries', function (Blueprint $table): void {
            $table->increments('delivery_id');
            $table->unsignedInteger('shipment_id');
            $table->string('trucker', 100)->nullable();
            $table->string('driver_name', 100)->nullable();
            $table->string('delivery_address', 255)->nullable();
            $table->date('scheduled_date')->nullable();
            $table->date('delivered_date')->nullable();
            $table->string('status', 20)->nullable();
        });
        Schema::create('shipment_documents', function (Blueprint $table): void {
            $table->increments('document_id');
            $table->unsignedInteger('shipment_id');
            $table->string('doc_type', 60);
            $table->string('review_status', 10)->nullable();
            $table->dateTime('superseded_at')->nullable();
        });
        config(['shipments.free_days' => 5, 'shipments.storage_warning_days' => 2, 'shipments.arrival_eta_tolerance_days' => 3]);
        Schema::create('shipment_status_updates', function (Blueprint $table): void {
            $table->increments('update_id');
            $table->unsignedInteger('shipment_id');
            $table->string('stage', 60);
            $table->string('status', 20)->nullable();
            $table->string('remarks', 255)->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        Schema::create('customs_processing', function (Blueprint $table): void {
            $table->increments('customs_id');
            $table->unsignedInteger('shipment_id');
            $table->string('entry_no', 50)->nullable();
            $table->string('declaration_type', 40)->nullable();
            $table->date('lodgement_date')->nullable();
            $table->decimal('customs_value', 15, 2)->nullable();
            $table->decimal('duties_taxes_amount', 15, 2)->nullable();
            $table->date('duties_paid_date')->nullable();
            $table->string('assessment_status', 10)->default('pending');
            $table->string('examination_lane', 10)->nullable();
            $table->date('clearance_date')->nullable();
            $table->text('customs_remarks')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        Schema::create('billings', function (Blueprint $table): void {
            $table->increments('billing_id');
            $table->string('billing_no', 30);
            $table->unsignedInteger('client_id');
            $table->unsignedInteger('shipment_id');
            $table->decimal('total_amount', 15, 2);
            $table->string('payment_status', 10)->default('unpaid');
        });
        Schema::create('billing_items', function (Blueprint $table): void {
            $table->increments('item_id');
            $table->unsignedInteger('billing_id');
            $table->string('charge_type', 60);
            $table->decimal('amount', 15, 2);
        });
        Schema::create('payments', function (Blueprint $table): void {
            $table->increments('payment_id');
            $table->unsignedInteger('billing_id');
            $table->decimal('amount', 15, 2);
            $table->string('verification_status', 10)->default('pending');
        });
        Schema::create('transactions', function (Blueprint $table): void {
            $table->increments('transaction_id');
            $table->unsignedInteger('client_id');
            $table->unsignedInteger('shipment_id');
            $table->unsignedInteger('billing_id')->nullable();
            $table->string('status', 10)->default('pending');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        DB::statement("CREATE VIEW v_billing_balance AS SELECT b.billing_id, b.billing_no, b.client_id, b.shipment_id, b.total_amount,
            COALESCE(SUM(CASE WHEN p.verification_status = 'verified' THEN p.amount END), 0) AS amount_paid,
            b.total_amount - COALESCE(SUM(CASE WHEN p.verification_status = 'verified' THEN p.amount END), 0) AS balance, b.payment_status
            FROM billings b LEFT JOIN payments p ON p.billing_id = b.billing_id
            GROUP BY b.billing_id, b.billing_no, b.client_id, b.shipment_id, b.total_amount, b.payment_status");

        foreach ([[10, 'Documents received', 'Pending Documents', 0], [20, 'Declaration filed', 'Customs Processing', 0], [30, 'Assessment', 'Customs Processing', 0],
            [40, 'Duties paid', 'Customs Processing', 0], [50, 'Examination', 'Customs Processing', 0], [60, 'Released/cleared', 'Customs Processing', 0],
            [70, 'Trucking', 'In Transit', 0], [80, 'Delivered', 'Delivered', 1], [90, 'On Hold', 'On Hold', 0], [100, 'Cancelled', 'Cancelled', 1]] as [$sort, $name, $group, $closed]) {
            DB::table('shipment_stages')->insert(['stage_name' => $name, 'stage_group' => $group, 'sort_order' => $sort, 'is_closed' => $closed]);
        }
        $this->staff = User::query()->create(['full_name' => 'Staff', 'email' => 's@x.com', 'role' => 'staff', 'status' => 'active']);
        $this->admin = User::query()->create(['full_name' => 'Admin', 'email' => 'a@x.com', 'role' => 'admin', 'status' => 'active']);
    }

    private User $admin;

    private function shipment(string $status = 'Documents received'): Shipment
    {
        $id = DB::table('shipments')->insertGetId(['reference_no' => 'AST-2026-0001', 'current_status' => $status, 'assigned_staff_id' => $this->staff->user_id, 'created_at' => '2026-11-19 14:51:00']);

        return Shipment::query()->findOrFail($id);
    }

    /** Customs Details filled up to and including $upTo. */
    private function customs(Shipment $shipment, string $upTo, array $extra = []): void
    {
        $steps = ['Declaration filed' => ['entry_no' => 'C-2026-0148', 'lodgement_date' => '2026-11-18'], 'Assessment' => ['assessment_status' => 'assessed'],
            'Duties paid' => ['assessment_status' => 'paid', 'duties_paid_date' => '2026-11-19'], 'Examination' => ['examination_lane' => 'green'],
            'Released/cleared' => ['clearance_date' => '2026-11-20']];
        $row = ['shipment_id' => $shipment->shipment_id, 'declaration_type' => 'Consumption', 'customs_value' => '1000.00', 'updated_at' => now()];
        foreach ($steps as $stage => $fields) {
            $row = array_merge($row, $fields);
            if ($stage === $upTo) {
                break;
            }
        }
        DB::table('customs_processing')->insert(array_merge($row, $extra));
    }

    private function bill(Shipment $shipment, array $charges, string $paid = '0'): void
    {
        $total = array_sum($charges);
        $billingId = DB::table('billings')->insertGetId(['billing_no' => 'BL-1', 'client_id' => 1, 'shipment_id' => $shipment->shipment_id, 'total_amount' => $total]);
        foreach ($charges as $type => $amount) {
            DB::table('billing_items')->insert(['billing_id' => $billingId, 'charge_type' => $type, 'amount' => $amount]);
        }
        if ((float) $paid > 0) {
            DB::table('payments')->insert(['billing_id' => $billingId, 'amount' => $paid, 'verification_status' => 'verified']);
        }
    }

    private function moveTo(Shipment $shipment, string $stage)
    {
        return $this->actingAs($this->staff)->postJson("/shipments/{$shipment->shipment_id}/status", ['stage' => $stage, 'occurred_at' => now()->format('Y-m-d\TH:i')]);
    }

    public function test_each_customs_stage_needs_its_facts_and_the_ones_before_it(): void
    {
        $service = app(ShipmentConsistencyService::class);

        $this->assertSame(['Entry No.', 'Lodgement date', 'Assessment status (Assessed or Paid)', 'Assessment status Paid', 'Duties paid date'], $service->missingFor('Duties paid', null));
        $this->assertCount(7, $service->missingFor('Trucking', null, 'sea'), 'sea/air shipments need the full customs release before Trucking');
        $this->assertSame([], $service->missingFor('Trucking', null, 'land'), 'land shipments may skip customs');
        $this->assertSame([], $service->missingFor('Trucking', null), 'non-customs stages follow the delivery details');

        $shipment = $this->shipment();
        $this->customs($shipment, 'Duties paid');
        $customs = $service->customsFor($shipment);
        $this->assertSame([], $service->missingFor('Duties paid', $customs));
        $this->assertSame(['Examination lane', 'Clearance date'], $service->missingFor('Released/cleared', $customs));
        $this->assertSame('Duties paid', $service->furthestSupportedStage($customs));
    }

    public function test_server_refuses_a_customs_stage_without_customs_details(): void
    {
        $shipment = $this->shipment();

        $this->moveTo($shipment, 'Duties paid')->assertStatus(422)
            ->assertJsonPath('errors.stage', 'Before Duties paid, add in Customs Details: Entry No., Lodgement date, Assessment status (Assessed or Paid), Assessment status Paid, Duties paid date.');
        $this->assertSame('Documents received', $shipment->fresh()->current_status);
    }

    public function test_status_modal_marks_stages_that_still_need_details(): void
    {
        $shipment = $this->shipment();
        $this->customs($shipment, 'Declaration filed');

        $stages = collect(app(ShipmentStageService::class)->modalState($shipment, 'staff')['stages'])->keyBy('name');
        $this->assertSame([], $stages['Declaration filed']['needs']);
        $this->assertSame(['Assessment status (Assessed or Paid)'], $stages['Assessment']['needs']);
        $this->assertSame([], $stages['On Hold']['needs'], 'exceptions are always available');
    }

    public function test_release_with_unpaid_bills_is_blocked_when_configured(): void
    {
        config(['billing.release_requires_payment' => true]);
        $shipment = $this->shipment('Examination');
        $this->customs($shipment, 'Released/cleared');
        $this->bill($shipment, ['Brokerage' => '500.00']);

        $this->moveTo($shipment, 'Released/cleared')->assertStatus(422)
            ->assertJsonPath('errors.stage', 'Collect payment first: this shipment has ₱500.00 unpaid on its bills.');
    }

    public function test_release_with_unpaid_bills_only_warns_by_default(): void
    {
        $shipment = $this->shipment('Examination');
        $this->customs($shipment, 'Released/cleared');
        $this->bill($shipment, ['Brokerage' => '500.00']);
        $service = app(ShipmentConsistencyService::class);

        $this->assertFalse($service->releaseBlocked('Released/cleared', 500.0));
        $this->assertSame('This shipment still has ₱500.00 unpaid on its bills.', $service->releaseWarning('Released/cleared', $service->unpaidBalance($shipment)));
        $stages = collect(app(ShipmentStageService::class)->modalState($shipment, 'staff')['stages'])->keyBy('name');
        $this->assertSame([], $stages['Released/cleared']['needs']);
        $this->assertNotNull($stages['Released/cleared']['warning']);
    }

    public function test_bill_warnings_for_lane_and_duties(): void
    {
        $shipment = $this->shipment('Examination');
        $this->customs($shipment, 'Examination', ['duties_taxes_amount' => '1200.00']);
        $this->bill($shipment, ['Handling' => '100.00', 'Red lane' => '100.00']);

        $warnings = app(ShipmentConsistencyService::class)->billWarnings($shipment);
        $this->assertSame([
            'A bill charges "Red lane", but Customs Details say the lane is Green.',
            'Duties and taxes of ₱1,200.00 are in Customs Details, but no bill has a duties or taxes charge.',
        ], $warnings);

        $this->bill($shipment, ['Duties and taxes' => '1200.00']);
        $this->assertCount(1, app(ShipmentConsistencyService::class)->billWarnings($shipment), 'billing the duties clears that warning');
    }

    public function test_customs_form_needs_status_paid_for_a_paid_date_or_clearance(): void
    {
        $shipment = $this->shipment();
        $form = ['declaration_type' => 'Consumption', 'customs_value' => '3.00', 'duties_taxes_amount' => '4.00', 'assessment_status' => 'assessed',
            'entry_no' => 'hhj99', 'lodgement_date' => '2026-11-19'];

        $this->actingAs($this->staff)->post("/shipments/{$shipment->shipment_id}/customs", $form + ['duties_paid_date' => '2026-11-20'])
            ->assertSessionHasErrors(['assessment_status' => 'A duties paid date is set, so set Assessment Status to Paid.']);
        $this->actingAs($this->staff)->post("/shipments/{$shipment->shipment_id}/customs", $form + ['clearance_date' => '2026-11-20', 'examination_lane' => 'green'])
            ->assertSessionHasErrors('clearance_date');
        $this->assertSame(0, DB::table('customs_processing')->count(), 'nothing is saved');
    }

    public function test_customs_dates_cannot_be_in_the_future_and_clearance_needs_a_lane(): void
    {
        $shipment = $this->shipment();
        $form = ['declaration_type' => 'Consumption', 'customs_value' => '100.00', 'assessment_status' => 'pending', 'entry_no' => 'hhj99'];

        $this->actingAs($this->staff)->post("/shipments/{$shipment->shipment_id}/customs", $form + ['lodgement_date' => now()->addDays(8)->toDateString()])
            ->assertSessionHasErrors(['lodgement_date' => 'Lodgement date cannot be in the future.']);
        $this->actingAs($this->staff)->post("/shipments/{$shipment->shipment_id}/customs",
            ['assessment_status' => 'paid', 'duties_paid_date' => '2026-11-10', 'clearance_date' => '2026-11-11'] + $form)
            ->assertSessionHasErrors(['examination_lane' => 'Set the examination lane before entering a clearance date.']);
        $this->assertSame(0, DB::table('customs_processing')->count());
    }

    public function test_unbilled_duties_offer_a_prefilled_bill_until_fully_billed(): void
    {
        $service = app(ShipmentConsistencyService::class);
        $shipment = $this->shipment('Assessment');
        $this->customs($shipment, 'Assessment', ['duties_taxes_amount' => '42500.00']);

        $unbilled = $service->unbilledDuties($shipment);
        $this->assertSame(42500.0, $unbilled['amount']);
        $this->assertStringContainsString('view=create', $unbilled['url']);
        $this->assertStringContainsString('shipment_id='.$shipment->shipment_id, $unbilled['url']);
        $this->assertStringContainsString('duties=1', $unbilled['url']);

        // Billed at the first assessment (₱40,000), then reassessed: the gap is offered and flagged.
        $this->bill($shipment, ['Duties and taxes' => '40000.00']);
        $this->assertSame(2500.0, $service->unbilledDuties($shipment)['amount']);
        $this->assertSame(['Duties and taxes billed (₱40,000.00) differ from Customs Details (₱42,500.00). Bill the difference or check the assessment.'],
            $service->billWarnings($shipment));

        $this->bill($shipment, ['Duties and taxes' => '2500.00']);
        $this->assertNull($service->unbilledDuties($shipment), 'fully billed: no more reminder');
        $this->assertSame([], $service->billWarnings($shipment));
    }

    private function datedShipment(string $status, array $dates): Shipment
    {
        $shipment = $this->shipment($status);
        if ($dates !== []) {
            DB::table('shipments')->where('shipment_id', $shipment->shipment_id)->update($dates);
        }

        return $shipment->fresh();
    }

    private function dateIssueKeys(Shipment $shipment, string $today = '2026-11-20'): array
    {
        return array_column(app(ShipmentConsistencyService::class)->keyDateIssues($shipment, \Carbon\CarbonImmutable::parse($today)), 'key');
    }

    public function test_storage_warning_counts_free_days_from_the_first_free_day(): void
    {
        $service = app(ShipmentConsistencyService::class);
        $today = \Carbon\CarbonImmutable::parse('2026-11-20');

        // Free from Nov 17 for 5 days: charges start Nov 22, two days away -> warn.
        $soon = $this->datedShipment('Assessment', ['first_free_day' => '2026-11-17']);
        $alert = $service->storageAlert($soon, $today);
        $this->assertSame('2026-11-22', $alert['starts']->toDateString());
        $this->assertFalse($alert['started']);
        $this->assertStringContainsString('Free storage ends in 2 days', $alert['text']);

        // Charges already running.
        $late = $this->datedShipment('Examination', ['first_free_day' => '2026-11-10']);
        $this->assertTrue($service->storageAlert($late, $today)['started']);

        // Plenty of free time left, or the cargo already left the port: no warning.
        $this->assertNull($service->storageAlert($this->datedShipment('Assessment', ['first_free_day' => '2026-11-19']), $today));
        $this->assertNull($service->storageAlert($this->datedShipment('Trucking', ['first_free_day' => '2026-11-10']), $today));
    }

    public function test_key_dates_that_disagree_are_flagged(): void
    {
        $this->assertContains('arrival_missing', $this->dateIssueKeys($this->datedShipment('Examination', [])));
        $this->assertNotContains('arrival_missing', $this->dateIssueKeys($this->datedShipment('Assessment', [])), 'before examination the cargo may not have arrived');

        $this->assertContains('arrival_eta', $this->dateIssueKeys($this->datedShipment('Assessment', ['eta_date' => '2026-11-10', 'actual_arrival_date' => '2026-11-15'])));
        $this->assertNotContains('arrival_eta', $this->dateIssueKeys($this->datedShipment('Assessment', ['eta_date' => '2026-11-14', 'actual_arrival_date' => '2026-11-15'])));

        $order = $this->dateIssueKeys($this->datedShipment('Assessment', ['actual_arrival_date' => '2026-11-15', 'first_free_day' => '2026-11-14', 'delivery_due_date' => '2026-11-12']));
        $this->assertContains('free_day_order', $order);
        $this->assertContains('due_order', $order);

        $late = $this->datedShipment('Trucking', ['actual_arrival_date' => '2026-11-10', 'delivery_due_date' => '2026-11-18']);
        DB::table('deliveries')->insert(['shipment_id' => $late->shipment_id, 'scheduled_date' => '2026-11-25', 'status' => 'scheduled']);
        $keys = $this->dateIssueKeys($late);
        $this->assertContains('delivery_schedule', $keys);
        $this->assertContains('delivery_overdue', $keys);
        $this->assertSame([], $this->dateIssueKeys($this->datedShipment('Delivered', ['actual_arrival_date' => '2026-11-10', 'delivery_due_date' => '2026-11-18'])), 'delivered shipments are not overdue');
    }

    public function test_examination_needs_an_actual_arrival_date(): void
    {
        $shipment = $this->shipment('Duties paid');
        $this->customs($shipment, 'Examination');

        $this->moveTo($shipment, 'Examination')->assertStatus(422)
            ->assertJsonPath('errors.actual_arrival_date', 'Enter the Actual Arrival date: the cargo must have arrived to be at Examination.');
    }

    public function test_trucking_needs_delivery_information_set_up(): void
    {
        $shipment = $this->shipment('Released/cleared');
        $this->customs($shipment, 'Released/cleared');
        DB::table('shipments')->where('shipment_id', $shipment->shipment_id)->update(['actual_arrival_date' => '2026-11-19']);

        $this->moveTo($shipment, 'Trucking')->assertStatus(422)->assertJsonPath('errors.stage',
            'Before Trucking, set up Delivery Information: a scheduled delivery (pickup date, delivery address, trucker or driver).');
        $trucking = collect(app(ShipmentStageService::class)->modalState($shipment->fresh(), 'staff')['stages'])->firstWhere('name', 'Trucking');
        $this->assertSame('delivery', $trucking['needsLink']);
        $this->assertNotSame([], $trucking['needs']);
        $this->assertContains('delivery_setup', array_column(app(ShipmentConsistencyService::class)->deliveryIssues($shipment->fresh()->forceFill(['current_status' => 'Trucking'])), 'key'), 'at Trucking without delivery is flagged');

        DB::table('deliveries')->insert(['shipment_id' => $shipment->shipment_id, 'trucker' => 'ABC Trucking', 'delivery_address' => 'Calamba, Laguna',
            'scheduled_date' => '2026-11-20', 'status' => 'scheduled']);
        $this->assertSame([], app(ShipmentConsistencyService::class)->deliveryMissingFor('Trucking', $shipment));
        $trucking = collect(app(ShipmentStageService::class)->modalState($shipment->fresh(), 'staff')['stages'])->firstWhere('name', 'Trucking');
        $this->assertSame([], $trucking['needs'], 'Trucking is selectable once delivery is set up');
        $this->assertNotContains('delivery_setup', array_column(app(ShipmentConsistencyService::class)->deliveryIssues($shipment->fresh()->forceFill(['current_status' => 'Trucking'])), 'key'));
    }

    public function test_only_admins_cancel_and_corrections_need_a_real_reason(): void
    {
        $shipment = $this->shipment();
        $this->moveTo($shipment, 'Cancelled')->assertStatus(422)->assertJsonPath('errors.stage', 'Only an administrator can cancel a shipment.');
        $this->assertNotContains('Cancelled', array_column(app(ShipmentStageService::class)->modalState($shipment, 'staff')['stages'], 'name'));

        $this->actingAs($this->admin)->postJson("/shipments/{$shipment->shipment_id}/status",
            ['stage' => 'Cancelled', 'remarks' => 'j', 'occurred_at' => now()->format('Y-m-d\TH:i')])
            ->assertStatus(422)->assertJsonPath('errors.remarks', 'Explain the reason in at least 10 characters (it is kept in the audit trail).');
    }

    public function test_customs_details_that_contradict_themselves_are_flagged(): void
    {
        $shipment = $this->shipment('Assessment');
        $this->customs($shipment, 'Assessment', ['duties_paid_date' => '2026-11-20', 'clearance_date' => '2026-11-20']);

        $problems = app(ShipmentConsistencyService::class)->customsContradictions(app(ShipmentConsistencyService::class)->customsFor($shipment));
        $this->assertCount(3, $problems, 'paid date without Paid, clearance without Paid, clearance without a lane');
    }

    public function test_delivery_details_that_disagree_with_the_shipment_are_flagged(): void
    {
        // Your AST-2026-0001 case: at Documents received, delivery "in transit", pickup before creation, delivered date in the future
        $shipment = $this->shipment('Documents received');
        DB::table('deliveries')->insert(['shipment_id' => $shipment->shipment_id, 'scheduled_date' => '2026-11-09', 'delivered_date' => '2026-11-23', 'status' => 'in_transit']);

        $issues = array_column(app(ShipmentConsistencyService::class)->deliveryIssues($shipment, \Carbon\CarbonImmutable::parse('2026-11-20')), 'issue');
        $this->assertSame([
            'Delivery is ahead of the shipment stage', 'Delivered date on an undelivered delivery',
            'Delivered date is in the future', 'Pickup is scheduled before the shipment existed',
        ], $issues);
    }

    public function test_required_documents_follow_the_stage(): void
    {
        $shipment = $this->shipment('Declaration filed');
        DB::table('shipment_documents')->insert(['shipment_id' => $shipment->shipment_id, 'doc_type' => 'B/L or AWB', 'review_status' => 'accepted']);
        $service = app(ShipmentConsistencyService::class);

        $this->assertSame(['Commercial Invoice', 'Packing List'], $service->missingDocuments($shipment));
        $this->assertSame([], $service->missingDocuments($this->shipment('Documents received')), 'nothing required before the declaration');
    }

    public function test_delivery_form_rejects_impossible_dates(): void
    {
        $shipment = $this->shipment('Trucking');
        $base = ['delivery_id' => '', 'status' => 'in_transit'];

        $this->actingAs($this->staff)->post("/shipments/{$shipment->shipment_id}/delivery", $base + ['delivered_date' => '2026-11-20'])
            ->assertSessionHasErrors(['delivered_date' => 'A delivered date only goes with status Delivered. Clear it, or set the status to Delivered.']);
        $this->actingAs($this->staff)->post("/shipments/{$shipment->shipment_id}/delivery", ['status' => 'delivered', 'delivered_date' => now()->addDays(3)->toDateString()])
            ->assertSessionHasErrors(['delivered_date' => 'Delivered date cannot be in the future.']);
        $this->actingAs($this->staff)->post("/shipments/{$shipment->shipment_id}/delivery", ['status' => 'scheduled', 'scheduled_date' => '2026-11-09'])
            ->assertSessionHasErrors('scheduled_date');
        $this->assertSame(0, DB::table('deliveries')->count());
    }

    public function test_unassigned_shipment_is_read_only_for_staff_until_claimed(): void
    {
        $shipment = $this->shipment();
        DB::table('shipments')->where('shipment_id', $shipment->shipment_id)->update(['assigned_staff_id' => null]);
        $other = User::query()->create(['full_name' => 'Other', 'email' => 'o@x.com', 'role' => 'staff', 'status' => 'active']);

        // Visible, but no changes before claiming
        $this->moveTo($shipment, 'On Hold')->assertStatus(409)
            ->assertJsonPath('error', 'Assign this shipment to yourself (Assign to me) before making changes.');
        $this->assertNotNull($shipment->fresh()->officeChangeBlockedFor($this->staff));
        $this->assertNull($shipment->fresh()->officeChangeBlockedFor($this->admin), 'admins are not blocked');

        // Claim: first staff wins, the second is told someone else took it
        $this->actingAs($this->staff)->post("/shipments/{$shipment->shipment_id}/claim")->assertSessionHas('success');
        $this->assertSame($this->staff->user_id, (int) $shipment->fresh()->assigned_staff_id);
        $this->actingAs($other)->post("/shipments/{$shipment->shipment_id}/claim")->assertSessionHas('error');
        $this->assertSame($this->staff->user_id, (int) $shipment->fresh()->assigned_staff_id);

        // Only staff claim; admins assign through Reassign
        $this->actingAs($this->admin)->post("/shipments/{$shipment->shipment_id}/claim")->assertForbidden();
    }

    public function test_checklist_and_report_flag_a_shipment_whose_records_disagree(): void
    {
        $shipment = $this->shipment('Duties paid'); // a stage set with no Customs Details at all
        DB::table('transactions')->insert(['client_id' => 1, 'shipment_id' => $shipment->shipment_id, 'status' => 'pending']);

        $checks = collect(app(ShipmentConsistencyService::class)->checks($shipment))->keyBy('key');
        $this->assertFalse($checks['stage_customs']['ok']);
        $this->assertStringContainsString('Duties paid needs: Entry No.', $checks['stage_customs']['detail']);
        $this->assertFalse($checks['transaction']['ok'], 'a customs stage implies an ongoing transaction');

        $this->artisan('shipments:check-consistency')
            ->expectsOutputToContain('AST-2026-0001')
            ->expectsOutputToContain('1 shipment needs review.')
            ->assertSuccessful();
    }
}
