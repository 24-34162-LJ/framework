<?php

/*
 * FILE PURPOSE
 * File   : config/notifications.php
 * Feature: Config - Notifications
 * Purpose: Which events send SMS and/or email, and to whom.
 */

/*
|--------------------------------------------------------------------------
| SMS and email rules per notification
|--------------------------------------------------------------------------
|
| In-app notifications are always created. These rules add email and SMS on top.
| A rule is looked up by the notification key passed by the caller, then by the
| event_type, then "default".
|
|  email        true/false — send email to the notified users
|  email_roles  optional list — only email users with these roles
|  sms          null (no SMS), 'user' (each client user: own mobile, else company mobile)
|               or 'company' (company mobile once, else client users' own mobiles)
|
| Admin and staff never receive SMS.
*/

return [
    'default' => ['email' => true, 'sms' => null],

    'rules' => [
        'status' => ['email' => true, 'sms' => 'user'],
        'request_accepted' => ['email' => true, 'sms' => 'company'],
        'request_declined' => ['email' => true, 'sms' => 'company'],
        'document_requested' => ['email' => true, 'sms' => 'user'],
        'document_rejected' => ['email' => true, 'sms' => 'user'],
        'shipment_created_from_request' => ['email' => true, 'email_roles' => ['admin', 'staff'], 'sms' => null],
        'archive' => ['email' => false, 'sms' => null],
        // Billing and payments (client only). Money messages go to the company mobile once;
        // progress notes on a payment are email only.
        'bill_created' => ['email' => true, 'sms' => 'company'],
        'payment_verified' => ['email' => true, 'sms' => 'company'],
        'payment_rejected' => ['email' => true, 'sms' => 'company'],
        'payment_reversed' => ['email' => true, 'sms' => 'company'],
        'payment_recorded' => ['email' => true, 'sms' => null],
        'payment_escalated' => ['email' => true, 'sms' => null],
    ],
];
