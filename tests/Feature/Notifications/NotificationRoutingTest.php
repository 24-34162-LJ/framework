<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/Notifications/NotificationRoutingTest.php
 * Feature: Testing - Notifications
 * Purpose: Tests who gets SMS and email for each event.
 */

namespace Tests\Feature\Notifications;

use App\Jobs\SendNotificationDeliveries;
use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Bus;
use App\Models\Shipment;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesNotificationTables;
use Tests\TestCase;

class NotificationRoutingTest extends TestCase
{
    use CreatesNotificationTables;

    private int $clientId;

    private User $admin;

    private User $staff;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNotificationTables();
        config(['services.semaphore.driver' => 'log']);
        Bus::fake([SendNotificationDeliveries::class]); // these tests check routing; sending is covered by DeliverySendingTest

        $this->clientId = (int) DB::table('clients')->insertGetId(['client_name' => 'Acme', 'email' => 'co@example.com', 'phone' => '09170000000']);
        $this->admin = $this->makeUser('admin', 'admin@example.com');
        $this->staff = $this->makeUser('staff', 'staff@example.com');
        $shipmentId = DB::table('shipments')->insertGetId(['client_id' => $this->clientId, 'assigned_staff_id' => $this->staff->user_id, 'reference_no' => 'REF-1']);
        $this->shipment = Shipment::query()->findOrFail($shipmentId);
    }

    private function makeUser(string $role, string $email, ?string $phone = null, bool $sms = true, string $status = 'active'): User
    {
        $id = DB::table('users')->insertGetId([
            'full_name' => ucfirst($role).' '.$email, 'email' => $email, 'role' => $role,
            'client_id' => $role === 'client' ? $this->clientId : null,
            'status' => $status, 'phone' => $phone, 'sms_enabled' => $sms,
        ]);

        return User::query()->findOrFail($id);
    }

    private function service(): NotificationService
    {
        return app(NotificationService::class);
    }

    private function sms()
    {
        return NotificationDelivery::query()->where('channel', 'sms')->get();
    }

    private function emails(): array
    {
        return NotificationDelivery::query()->where('channel', 'email')->orderBy('recipient')->pluck('recipient')->all();
    }

    public function test_status_event_texts_user_phone_and_emails_everyone_but_no_staff_sms(): void
    {
        $this->makeUser('client', 'c1@example.com', '0917 111 1111');

        $this->service()->forShipment($this->shipment, null, 'status', 'Shipment status updated', 'REF-1 is now Delivered');

        $sms = $this->sms();
        $this->assertCount(1, $sms);
        $this->assertSame('639171111111', $sms[0]->recipient);
        $this->assertSame('pending', $sms[0]->status);
        $this->assertSame('AstraTrack: REF-1 is now Delivered', $sms[0]->message);
        $this->assertNotNull($sms[0]->notification_id);
        $this->assertSame(['admin@example.com', 'c1@example.com', 'staff@example.com'], $this->emails());
    }

    public function test_user_without_phone_falls_back_to_company_mobile_once(): void
    {
        $this->makeUser('client', 'c1@example.com');
        $this->makeUser('client', 'c2@example.com');
        $this->makeUser('client', 'c3@example.com');

        $this->service()->forShipment($this->shipment, null, 'status', 'Shipment status updated', 'REF-1 is now Delivered');

        $sms = $this->sms();
        $this->assertCount(1, $sms);
        $this->assertSame('639170000000', $sms[0]->recipient);
    }

    public function test_landline_company_phone_falls_back_to_user_mobiles_for_company_event(): void
    {
        DB::table('clients')->update(['phone' => '(02) 8123-4567']);
        $a = $this->makeUser('client', 'c1@example.com', '09172222222');
        $b = $this->makeUser('client', 'c2@example.com', '09173333333');

        $this->service()->forUsers([$a->user_id, $b->user_id], $this->staff, 'shipment_request', 'Shipment request declined', 'REQ-1 was declined.', key: 'request_declined', clientId: $this->clientId);

        $this->assertEqualsCanonicalizing(['639172222222', '639173333333'], $this->sms()->pluck('recipient')->all());
    }

    public function test_company_event_with_mobile_company_phone_texts_company_once_and_emails_company(): void
    {
        $a = $this->makeUser('client', 'c1@example.com', '09172222222');
        $b = $this->makeUser('client', 'c2@example.com');

        $this->service()->forUsers([$a->user_id, $b->user_id], $this->staff, 'shipment_request', 'Shipment request declined', 'REQ-1 was declined.', key: 'request_declined', clientId: $this->clientId);

        $sms = $this->sms();
        $this->assertCount(1, $sms);
        $this->assertSame('639170000000', $sms[0]->recipient);
        $this->assertNull($sms[0]->notification_id);
        $this->assertSame(['co@example.com'], $this->emails());
    }

    public function test_company_event_without_company_email_emails_client_users(): void
    {
        DB::table('clients')->update(['email' => null]);
        $this->makeUser('client', 'c1@example.com');

        $this->service()->forShipmentRoles($this->shipment, $this->staff, ['client'], 'created', 'Shipment request accepted', 'REQ-1 is now shipment REF-1.', key: 'request_accepted');

        $this->assertSame(['c1@example.com'], $this->emails());
        $this->assertSame(['639170000000'], $this->sms()->pluck('recipient')->all());
    }

    public function test_landline_and_no_user_phone_records_skipped(): void
    {
        DB::table('clients')->update(['phone' => '(02) 8123-4567']);
        $this->makeUser('client', 'c1@example.com');

        $this->service()->forShipment($this->shipment, null, 'status', 'Shipment status updated', 'REF-1 is now Delivered');

        $sms = $this->sms();
        $this->assertCount(1, $sms);
        $this->assertSame('skipped', $sms[0]->status);
        $this->assertSame('No mobile number', $sms[0]->error);
        $this->assertSame('', $sms[0]->recipient);
    }

    public function test_opted_out_user_gets_no_sms_and_no_fallback(): void
    {
        $this->makeUser('client', 'c1@example.com', '09171111111', sms: false);

        $this->service()->forShipment($this->shipment, null, 'status', 'Shipment status updated', 'REF-1 is now Delivered');

        $this->assertCount(0, $this->sms());
        $this->assertContains('c1@example.com', $this->emails());
    }

    public function test_minor_event_is_email_only(): void
    {
        $this->makeUser('client', 'c1@example.com', '09171111111');

        $this->service()->forShipment($this->shipment, null, 'customs', 'Customs details updated', 'REF-1 customs processing details were saved.');

        $this->assertCount(0, $this->sms());
        $this->assertCount(3, $this->emails());
    }

    public function test_archive_sends_nothing_external(): void
    {
        $this->makeUser('client', 'c1@example.com', '09171111111');

        $this->service()->forShipment($this->shipment, null, 'archive', 'Shipment archived', 'REF-1 was archived.');

        $this->assertSame(0, NotificationDelivery::query()->count());
        $this->assertSame(3, DB::table('notifications')->count());
    }

    public function test_created_from_request_emails_office_only(): void
    {
        $this->makeUser('client', 'c1@example.com', '09171111111');

        $this->service()->forShipment($this->shipment, null, 'created', 'Shipment created', 'REF-1 was created.', key: 'shipment_created_from_request');

        $this->assertCount(0, $this->sms());
        $this->assertSame(['admin@example.com', 'staff@example.com'], $this->emails());
    }

    public function test_in_app_rows_unchanged(): void
    {
        $client = $this->makeUser('client', 'c1@example.com', '09171111111');
        $this->makeUser('client', 'inactive@example.com', '09179999999', status: 'inactive');

        $this->service()->forShipment($this->shipment, $this->admin, 'status', 'Shipment status updated', 'REF-1 is now Delivered');

        $this->assertEqualsCanonicalizing(
            [$this->staff->user_id, $client->user_id],
            DB::table('notifications')->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
        );
        $this->assertNotContains('admin@example.com', $this->emails());
        $this->assertNotContains('inactive@example.com', $this->emails());
        $this->assertSame(['639171111111'], $this->sms()->pluck('recipient')->all());
    }
}
