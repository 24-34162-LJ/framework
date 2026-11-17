<?php

/*
 * FILE PURPOSE
 * File   : bootstrap/providers.php
 * Feature: Core
 * Purpose: List of service providers Laravel loads on start-up.
 */

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
];
