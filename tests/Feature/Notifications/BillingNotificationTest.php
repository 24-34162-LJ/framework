<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/Notifications/BillingNotificationTest.php
 * Feature: Testing - Notifications
 * Purpose: Tests that billing buttons message the client: payment verified / rejected (with the
 *          reason) / reversed / sent for final approval, and that bill and payment rules route to
 *          email plus the company mobile (WhatsApp first, SMS backup) as configured.
 */

namespace Tests\Feature\Notifications;

use App\Jobs\SendNotificationDeliveries;
use App\Models\NotificationDelivery;
use App\Models\Shipment;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesNotificationTables;
use Tests\TestCase;

class BillingNotificationTest extends TestCase
{
    use CreatesNotificationTables;

    private int $clientId;

    private User $admin;

    private User $staff;

    private int $shipmentId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNotificationTables();
        Schema::table('shipments', fn (Blueprint $table) => $table->dateTime('archived_at')->nullable());
        Schema::create('billings', function (Blueprint $table): void {
            $table->increments('billing_id');
            $table->string('billing_no', 30);
            $table->unsignedInteger('client_id');
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
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->increments('activity_id');
            $table->string('activity_type', 50);
            $table->string('description', 255);
            $table->unsignedInteger('performed_by')->nullable();
            $table->string('performed_by_name', 100)->nullable();
            $table->string('performed_by_role', 10)->nullable();
            $table->text('details_json')->nullable();
        });
        config(['billing.admin_approval_threshold' => 50000, 'services.semaphore.driver' => 'log']);
        Bus::fake([SendNotificationDeliveries::class]); // routing only; sending is covered by DeliverySendingTest

        $this->clientId = (int) DB::table('clients')->insertGetId(['client_name' => 'Kubota', 'email' => 'billing@kubota.test', 'phone' => '09170000000']);
        $this->admin = $this->makeUser('admin', 'admin@astra.test');
        $this->staff = $this->makeUser('staff', 'staff@astra.test');
        $this->makeUser('client', 'jan@kubota.test');
        $this->shipmentId = (int) DB::table('shipments')->insertGetId(['client_id' => $this->clientId, 'assigned_staff_id' => $this->staff->user_id, 'reference_no' => 'AST-2026-0001']);
    }

    private function makeUser(string $role, string $email): User
    {
        $id = DB::table('users')->insertGetId([
            'full_name' => ucfirst($role), 'email' => $email, 'role' => $role,
            'client_id' => $role === 'client' ? $this->clientId : null, 'status' => 'active',
        ]);

        return User::query()->findOrFail($id);
    }

    private function pendingPayment(string $amount = '380.00', string $total = '380.00'): int
    {
        $billingId = DB::table('billings')->insertGetId(['billing_no' => 'BL-2026-000003', 'client_id' => $this->clientId, 'shipment_id' => $this->shipmentId, 'total_amount' => $total]);

        return DB::table('payments')->insertGetId(['billing_id' => $billingId, 'amount' => $amount]);
    }

    /** The client's in-app message for a billing event, plus where it was sent. */
    private function clientMessage(): array
    {
        $notification = DB::table('notifications')->where('event_type', 'billing')->orderByDesc('notification_id')->first();
        $deliveries = NotificationDelivery::query()->orderBy('delivery_id')->get(['channel', 'recipient'])
            ->map(fn ($d) => $d->channel.':'.$d->recipient)->all();

        return [$notification?->message, $deliveries];
    }

    public function test_verified_payment_messages_the_client_by_email_and_company_mobile(): void
    {
        $paymentId = $this->pendingPayment();

        $this->actingAs($this->staff)->post('/billing/payments/review', ['payment_id' => $paymentId, 'decision' => 'verified'])->assertSessionHas('success');

        [$message, $deliveries] = $this->clientMessage();
        $this->assertSame('Your ₱380.00 payment for BL-2026-000003 is confirmed. Balance: ₱0.00.', $message);
        $this->assertContains('sms:639170000000', $deliveries, 'company mobile (WhatsApp first, SMS backup)');
        $this->assertContains('email:billing@kubota.test', $deliveries);
        $this->assertSame(1, DB::table('notifications')->where('event_type', 'billing')->count(), 'only the client is told, not office users');
    }

    public function test_rejected_payment_includes_the_reason(): void
    {
        $paymentId = $this->pendingPayment();

        $this->actingAs($this->staff)->post('/billing/payments/review', ['payment_id' => $paymentId, 'decision' => 'rejected', 'reason' => 'Reference number not found']);

        [$message, $deliveries] = $this->clientMessage();
        $this->assertSame('Your ₱380.00 payment for BL-2026-000003 was not accepted. Reason: Reference number not found. Please check it and submit again.', $message);
        $this->assertContains('sms:639170000000', $deliveries);
    }

    public function test_reversed_payment_tells_the_client_the_reason_and_new_balance(): void
    {
        $paymentId = $this->pendingPayment();
        $this->actingAs($this->staff)->post('/billing/payments/review', ['payment_id' => $paymentId, 'decision' => 'verified']);

        $this->actingAs($this->admin)->post('/billing/payments/reverse', ['payment_id' => $paymentId, 'reason' => 'Check bounced', 'confirm' => '1']);

        [$message] = $this->clientMessage();
        $this->assertSame('The ₱380.00 payment for BL-2026-000003 was reversed: Check bounced. Balance due: ₱380.00.', $message);
    }

    public function test_large_payment_check_sends_an_email_only_progress_note(): void
    {
        $paymentId = $this->pendingPayment('60000.00', '60000.00');

        $this->actingAs($this->staff)->post('/billing/payments/review', ['payment_id' => $paymentId, 'decision' => 'verified']);

        [$message, $deliveries] = $this->clientMessage();
        $this->assertStringContainsString('is with an administrator for final approval', $message);
        $this->assertSame([], array_values(array_filter($deliveries, fn ($d) => str_starts_with($d, 'sms:'))), 'no WhatsApp/SMS for progress notes');
        $this->assertContains('email:jan@kubota.test', $deliveries);
    }

    public function test_bill_and_recorded_payment_rules(): void
    {
        $service = app(NotificationService::class);
        $shipment = Shipment::query()->findOrFail($this->shipmentId);

        $service->forShipmentRoles($shipment, $this->staff, ['client'], 'billing', 'New bill', 'New bill BL-1', 'bill_created');
        $this->assertSame(1, NotificationDelivery::query()->where('channel', 'sms')->count(), 'new bill goes to the company mobile');

        NotificationDelivery::query()->delete();
        $service->forShipmentRoles($shipment, $this->staff, ['client'], 'billing', 'Payment recorded', 'We recorded', 'payment_recorded');
        $this->assertSame(0, NotificationDelivery::query()->where('channel', 'sms')->count(), 'recorded payment is email only');
        $this->assertSame(1, NotificationDelivery::query()->where('channel', 'email')->count());
    }
}
