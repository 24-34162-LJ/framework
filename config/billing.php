<?php

/*
 * FILE PURPOSE
 * File   : config/billing.php
 * Feature: Billing
 * Purpose: Billing settings. admin_approval_threshold: a payment of this amount (in pesos) or more
 *          that staff verify still needs an administrator's final approval before it reduces the bill.
 *          release_requires_payment: false = moving a shipment to Released/cleared or Trucking with an
 *          unpaid balance only warns; true = it is blocked until the bills are paid.
 */

return [
    'admin_approval_threshold' => (float) env('BILLING_ADMIN_APPROVAL_THRESHOLD', 50000),
    'release_requires_payment' => (bool) env('BILLING_RELEASE_REQUIRES_PAYMENT', false),
];
