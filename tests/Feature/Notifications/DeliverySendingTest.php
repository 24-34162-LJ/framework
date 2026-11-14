<?php

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
