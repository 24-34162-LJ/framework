<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/ExampleTest.php
 * Feature: Testing
 * Purpose: Default Laravel example test.
 */

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
