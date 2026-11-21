<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/Notifications/CallSiteKeysTest.php
 * Feature: Testing - Notifications
 * Purpose: Checks that controllers pass the right notification keys.
 */

namespace Tests\Feature\Notifications;

use Tests\TestCase;

/**
 * The controllers need the full legacy MySQL schema, so this guards that the
 * SMS-worthy call sites keep passing their notification keys to NotificationService.
 */
class CallSiteKeysTest extends TestCase
{
    public function test_sms_worthy_call_sites_pass_keys(): void
    {
        $creation = file_get_contents(app_path('Http/Controllers/ShipmentCreationController.php'));
        $this->assertStringContainsString("key: 'request_accepted'", $creation);
        $this->assertStringContainsString("'shipment_created_from_request'", $creation);

        $requests = file_get_contents(app_path('Http/Controllers/ShipmentRequestController.php'));
        $this->assertStringContainsString("key: 'request_declined'", $requests);
        $this->assertStringContainsString('clientId: (int) $shipmentRequest->client_id', $requests);

        $documents = file_get_contents(app_path('Http/Controllers/DocumentWorkflowController.php'));
        $this->assertStringContainsString("'document_rejected'", $documents);
        $this->assertStringContainsString("key: 'document_requested'", $documents);
    }

    public function test_status_call_sites_use_status_event_type(): void
    {
        // Update Status modal and delivery sync both notify through ShipmentStageService::applyChange().
        $this->assertStringContainsString("'status', 'Shipment status updated'", file_get_contents(app_path('Services/ShipmentStageService.php')));
        $this->assertStringContainsString('->applyChange(', file_get_contents(app_path('Http/Controllers/ShipmentStatusController.php')));
        $lifecycle = file_get_contents(app_path('Http/Controllers/ShipmentLifecycleController.php'));
        $this->assertStringContainsString("'status', 'Shipment reopened'", $lifecycle);
        $this->assertStringContainsString("'status', 'Shipment '.mb_strtolower(\$targetName)", $lifecycle);
    }
}
