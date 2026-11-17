<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/Notifications/DeliverySendingTest.php
 * Feature: Testing - Notifications
 * Purpose: Tests sending SMS / WhatsApp / email and recording success or failure.
 */

namespace Tests\Feature\Notifications;

use App\Mail\NotificationMail;
use App\Models\NotificationDelivery;
use App\Models\Shipment;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Support\CreatesNotificationTables;
use Tests\TestCase;

class DeliverySendingTest extends TestCase
{
    use CreatesNotificationTables;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNotificationTables();
        config(['services.semaphore' => [
            'driver' => 'semaphore', 'api_key' => 'k', 'sender_name' => null,
            'url' => 'https://sms.test/messages', 'timeout' => 10,
        ]]);

        $clientId = DB::table('clients')->insertGetId(['client_name' => 'Acme', 'email' => 'co@example.com', 'phone' => '(02) 8123-4567']);
        $staffId = DB::table('users')->insertGetId(['full_name' => 'Staff', 'email' => 'staff@example.com', 'role' => 'staff', 'status' => 'active']);
        DB::table('users')->insert(['full_name' => 'Client', 'email' => 'c1@example.com', 'role' => 'client', 'client_id' => $clientId, 'status' => 'active', 'phone' => '639171111111']);
        $shipmentId = DB::table('shipments')->insertGetId(['client_id' => $clientId, 'assigned_staff_id' => $staffId, 'reference_no' => 'REF-1']);
        $this->shipment = Shipment::query()->findOrFail($shipmentId);
    }

    private function notifyStatus(): void
    {
        app(NotificationService::class)->forShipment($this->shipment, null, 'status', 'Shipment status updated', 'REF-1 is now Delivered');
    }

    private function statuses(string $channel): array
    {
        return NotificationDelivery::query()->where('channel', $channel)->pluck('status')->all();
    }

    public function test_pending_deliveries_are_sent_and_marked(): void
    {
        Http::fake(['sms.test/*' => Http::response([['message_id' => 555]])]);
        Mail::fake();

        $this->notifyStatus();

        $sms = NotificationDelivery::query()->where('channel', 'sms')->sole();
        $this->assertSame('sent', $sms->status);
        $this->assertSame('555', $sms->provider_message_id);
        $this->assertNotNull($sms->sent_at);
        $this->assertSame(['sent', 'sent'], $this->statuses('email'));
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo('c1@example.com')
            && $mail->link === route('client.shipments.show', ['shipmentId' => $this->shipment->shipment_id]));
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo('staff@example.com')
            && $mail->link === route('shipments.show', ['shipmentId' => $this->shipment->shipment_id]));
    }

    public function test_semaphore_failure_marks_failed_and_does_not_throw(): void
    {
        Http::fake(['sms.test/*' => Http::response('oops', 500)]);
        Mail::fake();

        $this->notifyStatus();

        $sms = NotificationDelivery::query()->where('channel', 'sms')->sole();
        $this->assertSame('failed', $sms->status);
        $this->assertSame('Semaphore HTTP 500', $sms->error);
        $this->assertSame(['sent', 'sent'], $this->statuses('email'));
    }

    private function useWhatsApp(): void
    {
        config(['services.whatsapp' => [
            'driver' => 'meta', 'token' => 'test-token-123','phone_number_id' => '111', 'api_version' => 'v25.0',
            'base_url' => 'https://wa.test', 'template' => 'hello_world', 'template_language' => 'en_US',
            'template_has_message' => false, 'timeout' => 10,
        ]]);
    }

    public function test_whatsapp_is_tried_first_and_sms_is_not_sent(): void
    {
        $this->useWhatsApp();
        Http::fake([
            'wa.test/*' => Http::response(['messages' => [['id' => 'wamid.OK']]]),
            'sms.test/*' => Http::response([['message_id' => 1]]),
        ]);
        Mail::fake();

        $this->notifyStatus();

        $row = NotificationDelivery::query()->where('channel', 'whatsapp')->sole();
        $this->assertSame('sent', $row->status);
        $this->assertSame('wamid.OK', $row->provider_message_id);
        $this->assertSame('639171111111', $row->recipient);
        $this->assertSame([], $this->statuses('sms'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sms.test'));
    }

    public function test_sms_is_the_backup_when_whatsapp_fails(): void
    {
        $this->useWhatsApp();
        Http::fake([
            'wa.test/*' => Http::response(['error' => ['message' => 'not allowed']], 400),
            'sms.test/*' => Http::response([['message_id' => 777]]),
        ]);
        Mail::fake();

        $this->notifyStatus();

        $row = NotificationDelivery::query()->where('channel', 'sms')->sole();
        $this->assertSame('sent', $row->status);
        $this->assertSame('777', $row->provider_message_id);
        $this->assertNull($row->error);
    }

    public function test_both_whatsapp_and_sms_failing_records_both_errors(): void
    {
        $this->useWhatsApp();
        Http::fake([
            'wa.test/*' => Http::response(['error' => ['message' => 'not allowed']], 400),
            'sms.test/*' => Http::response('oops', 500),
        ]);
        Mail::fake();

        $this->notifyStatus();

        $row = NotificationDelivery::query()->where('channel', 'sms')->sole();
        $this->assertSame('failed', $row->status);
        $this->assertSame('WhatsApp HTTP 400: not allowed | SMS backup: Semaphore HTTP 500', $row->error);
        $this->assertSame(['sent', 'sent'], $this->statuses('email'));
    }

    public function test_smtp_failure_marks_email_failed(): void
    {
        Http::fake(['sms.test/*' => Http::response([['message_id' => 1]])]);
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

        $this->notifyStatus();

        $this->assertSame(['failed', 'failed'], $this->statuses('email'));
        $this->assertSame(['sent'], $this->statuses('sms'));
    }

    public function test_rolled_back_transaction_sends_nothing(): void
    {
        Http::fake();
        Mail::fake();

        try {
            DB::transaction(function (): void {
                $this->notifyStatus();
                throw new RuntimeException('staff action failed');
            });
        } catch (RuntimeException) {
        }

        Http::assertNothingSent();
        Mail::assertNothingSent();
        $this->assertSame(0, NotificationDelivery::query()->count());
    }

    public function test_committed_transaction_sends_after_commit(): void
    {
        Http::fake(['sms.test/*' => Http::response([['message_id' => 7]])]);
        Mail::fake();

        DB::transaction(function (): void {
            $this->notifyStatus();
            Http::assertNothingSent();
        });

        Http::assertSentCount(1);
        $this->assertSame(['sent'], $this->statuses('sms'));
    }

    public function test_skipped_rows_are_not_sent(): void
    {
        DB::table('users')->where('role', 'client')->update(['phone' => null]);
        Http::fake();
        Mail::fake();

        $this->notifyStatus();

        $this->assertSame(['skipped'], $this->statuses('sms'));
        Http::assertNothingSent();
    }
}
