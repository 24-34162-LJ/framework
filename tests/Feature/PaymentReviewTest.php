<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/PaymentReviewTest.php
 * Feature: Testing - Billing
 * Purpose: Tests who may verify or reject a pending payment: admins for any bill, staff only for
 *          shipments assigned to them (never their own entries), clients never; that large payments
 *          need an admin's final approval after a staff check; and that admins can reverse a verified
 *          payment. The bill status must follow every decision.
 */

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentReviewTest extends TestCase
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
        Schema::create('shipments', function (Blueprint $table): void {
            $table->increments('shipment_id');
            $table->unsignedInteger('client_id')->default(1);
            $table->string('reference_no', 30);
            $table->unsignedInteger('assigned_staff_id')->nullable();
            $table->dateTime('archived_at')->nullable();
        });
        Schema::create('billings', function (Blueprint $table): void {
            $table->increments('billing_id');
            $table->string('billing_no', 30);
            $table->unsignedInteger('client_id')->default(1);
            $table->unsignedInteger('shipment_id');
            $table->decimal('total_amount', 15, 2);
            $table->string('payment_status', 10)->default('unpaid');
        });
        Schema::create('payments', function (Blueprint $table): void {
            $table->increments('payment_id');
            $table->unsignedInteger('billing_id');
            $table->decimal('amount', 15, 2);
            $table->string('verification_status', 10)->default('pending');
            $table->unsignedInteger('verified_by')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->unsignedInteger('staff_checked_by')->nullable();
            $table->dateTime('staff_checked_at')->nullable();
            $table->unsignedInteger('reversed_by')->nullable();
            $table->dateTime('reversed_at')->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->unsignedInteger('recorded_by')->nullable();
        });
        config(['billing.admin_approval_threshold' => 50000]);
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
        $this->otherStaff = User::query()->create(['full_name' => 'Other', 'email' => 'o@x.com', 'role' => 'staff', 'status' => 'active']);
    }

    /** A bill (₱380.00 unless given) on a shipment assigned to $staffId, with one pending payment; returns the payment id. */
    private function pendingPayment(?int $staffId, string $amount = '380.00', string $billTotal = '380.00'): int
    {
        $shipmentId = DB::table('shipments')->insertGetId(['reference_no' => 'AST-2026-0001', 'assigned_staff_id' => $staffId]);
        $billingId = DB::table('billings')->insertGetId(['billing_no' => 'BL-2026-000003', 'shipment_id' => $shipmentId, 'total_amount' => $billTotal]);

        return DB::table('payments')->insertGetId(['billing_id' => $billingId, 'amount' => $amount]);
    }

    public function test_assigned_staff_can_verify_and_returns_to_review(): void
    {
        $paymentId = $this->pendingPayment($this->staff->user_id);

        $this->actingAs($this->staff)->post('/billing/payments/review', ['payment_id' => $paymentId, 'decision' => 'verified', 'return' => 'review'])
            ->assertRedirect(route('billing.index', ['view' => 'review']))->assertSessionHas('success');

        $this->assertDatabaseHas('payments', ['payment_id' => $paymentId, 'verification_status' => 'verified', 'verified_by' => $this->staff->user_id]);
        $this->assertDatabaseHas('billings', ['billing_no' => 'BL-2026-000003', 'payment_status' => 'paid']);
    }

    public function test_assigned_staff_can_reject(): void
    {
        $paymentId = $this->pendingPayment($this->staff->user_id);

        $this->actingAs($this->staff)->post('/billing/payments/review', ['payment_id' => $paymentId, 'decision' => 'rejected'])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('payments', ['payment_id' => $paymentId, 'verification_status' => 'rejected']);
        $this->assertDatabaseHas('billings', ['billing_no' => 'BL-2026-000003', 'payment_status' => 'unpaid']);
    }

    public function test_staff_cannot_review_a_shipment_assigned_to_someone_else(): void
    {
        $paymentId = $this->pendingPayment($this->otherStaff->user_id);

        $this->actingAs($this->staff)->post('/billing/payments/review', ['payment_id' => $paymentId, 'decision' => 'verified'])
            ->assertSessionHas('error', 'You can review payments only for shipments assigned to you.');

        $this->assertDatabaseHas('payments', ['payment_id' => $paymentId, 'verification_status' => 'pending', 'verified_by' => null]);
        $this->assertDatabaseHas('billings', ['billing_no' => 'BL-2026-000003', 'payment_status' => 'unpaid']);
    }

    public function test_staff_cannot_verify_a_payment_they_recorded(): void
    {
        $paymentId = $this->pendingPayment($this->staff->user_id);
        DB::table('payments')->where('payment_id', $paymentId)->update(['recorded_by' => $this->staff->user_id]);

        foreach (['verified', 'rejected'] as $decision) {
            $this->actingAs($this->staff)->post('/billing/payments/review', ['payment_id' => $paymentId, 'decision' => $decision])
                ->assertSessionHas('error', 'You recorded this payment, so another person must verify it.');
        }
        $this->assertDatabaseHas('payments', ['payment_id' => $paymentId, 'verification_status' => 'pending', 'verified_by' => null]);

        // Another person (an admin) can still verify it.
        $this->actingAs($this->admin)->post('/billing/payments/review', ['payment_id' => $paymentId, 'decision' => 'verified'])
            ->assertSessionHas('success');
        $this->assertDatabaseHas('payments', ['payment_id' => $paymentId, 'verification_status' => 'verified', 'verified_by' => $this->admin->user_id]);
    }

    public function test_admin_can_verify_any_payment(): void
    {
        $paymentId = $this->pendingPayment($this->otherStaff->user_id, '100.00');

        $this->actingAs($this->admin)->post('/billing/payments/review', ['payment_id' => $paymentId, 'decision' => 'verified'])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('billings', ['billing_no' => 'BL-2026-000003', 'payment_status' => 'partial']);
    }

    public function test_large_payment_checked_by_staff_waits_for_admin_approval(): void
    {
        $paymentId = $this->pendingPayment($this->staff->user_id, '60000.00', '60000.00');

        $this->actingAs($this->staff)->post('/billing/payments/review', ['payment_id' => $paymentId, 'decision' => 'verified'])
            ->assertSessionHas('success');
        // Still pending, balance unchanged, but the staff check is recorded.
        $this->assertDatabaseHas('payments', ['payment_id' => $paymentId, 'verification_status' => 'pending', 'verified_by' => null, 'staff_checked_by' => $this->staff->user_id]);
        $this->assertDatabaseHas('billings', ['billing_no' => 'BL-2026-000003', 'payment_status' => 'unpaid']);

        // Staff cannot act on it again; the admin gives the final approval.
        $this->actingAs($this->staff)->post('/billing/payments/review', ['payment_id' => $paymentId, 'decision' => 'verified'])
            ->assertSessionHas('error');
        $this->actingAs($this->admin)->post('/billing/payments/review', ['payment_id' => $paymentId, 'decision' => 'verified'])
            ->assertSessionHas('success');
        $this->assertDatabaseHas('payments', ['payment_id' => $paymentId, 'verification_status' => 'verified', 'verified_by' => $this->admin->user_id, 'staff_checked_by' => $this->staff->user_id]);
        $this->assertDatabaseHas('billings', ['billing_no' => 'BL-2026-000003', 'payment_status' => 'paid']);
    }

    public function test_payment_below_the_threshold_is_verified_by_staff_directly(): void
    {
        $paymentId = $this->pendingPayment($this->staff->user_id, '49999.99', '49999.99');

        $this->actingAs($this->staff)->post('/billing/payments/review', ['payment_id' => $paymentId, 'decision' => 'verified']);

        $this->assertDatabaseHas('payments', ['payment_id' => $paymentId, 'verification_status' => 'verified', 'staff_checked_by' => null]);
        $this->assertNotNull(DB::table('payments')->where('payment_id', $paymentId)->value('verified_at'));
    }

    public function test_admin_reverses_a_verified_payment(): void
    {
        $paymentId = $this->pendingPayment($this->staff->user_id);
        $this->actingAs($this->staff)->post('/billing/payments/review', ['payment_id' => $paymentId, 'decision' => 'verified']);
        $this->assertDatabaseHas('billings', ['billing_no' => 'BL-2026-000003', 'payment_status' => 'paid']);

        // A reason and the confirmation are both required.
        $this->actingAs($this->admin)->post('/billing/payments/reverse', ['payment_id' => $paymentId, 'reason' => 'Check bounced'])
            ->assertSessionHasErrors('confirm');
        $this->actingAs($this->admin)->post('/billing/payments/reverse', ['payment_id' => $paymentId, 'reason' => 'Check bounced', 'confirm' => '1'])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('payments', ['payment_id' => $paymentId, 'verification_status' => 'rejected', 'reversed_by' => $this->admin->user_id, 'reversal_reason' => 'Check bounced']);
        $this->assertDatabaseHas('billings', ['billing_no' => 'BL-2026-000003', 'payment_status' => 'unpaid']);

        // Reversing twice is refused.
        $this->actingAs($this->admin)->post('/billing/payments/reverse', ['payment_id' => $paymentId, 'reason' => 'Again please', 'confirm' => '1'])
            ->assertSessionHas('error', 'Only a verified payment can be reversed.');
    }

    public function test_staff_cannot_reverse_payments(): void
    {
        $paymentId = $this->pendingPayment($this->staff->user_id);
        $this->actingAs($this->staff)->post('/billing/payments/review', ['payment_id' => $paymentId, 'decision' => 'verified']);

        $this->actingAs($this->staff)->post('/billing/payments/reverse', ['payment_id' => $paymentId, 'reason' => 'Mistake here', 'confirm' => '1'])
            ->assertForbidden();
        $this->assertDatabaseHas('payments', ['payment_id' => $paymentId, 'verification_status' => 'verified', 'reversed_at' => null]);
    }

    public function test_client_cannot_review_payments(): void
    {
        $paymentId = $this->pendingPayment($this->staff->user_id);
        $client = User::query()->create(['full_name' => 'Client', 'email' => 'c@x.com', 'role' => 'client', 'client_id' => 1, 'status' => 'active']);

        $this->actingAs($client)->post('/billing/payments/review', ['payment_id' => $paymentId, 'decision' => 'verified'])
            ->assertForbidden();

        $this->assertDatabaseHas('payments', ['payment_id' => $paymentId, 'verification_status' => 'pending']);
    }
}
