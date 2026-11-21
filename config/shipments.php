<?php

/*
 * FILE PURPOSE
 * File   : config/shipments.php
 * Feature: Shipments - key dates
 * Purpose: Settings for the Key Dates rules (ShipmentConsistencyService):
 *          free_days            days of free port storage counted from the First Free Day; storage
 *                               charges start the day after (First Free Day + free_days)
 *          storage_warning_days warn this many days before free storage ends
 *          arrival_eta_tolerance_days  flag an Actual Arrival this many days or more away from the ETA
 */

return [
    'free_days' => (int) env('SHIPMENT_FREE_DAYS', 5),
    'storage_warning_days' => (int) env('SHIPMENT_STORAGE_WARNING_DAYS', 2),
    'arrival_eta_tolerance_days' => (int) env('SHIPMENT_ARRIVAL_ETA_TOLERANCE_DAYS', 3),
];
