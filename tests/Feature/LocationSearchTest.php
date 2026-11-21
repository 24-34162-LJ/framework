<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/LocationSearchTest.php
 * Feature: Testing - Delivery address lookup
 * Purpose: Tests the PSGC import command and the /locations/search suggestions endpoint.
 */

namespace Tests\Feature;

use App\Models\PsgcLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationSearchTest extends TestCase
{
    use RefreshDatabase;

    private function importSample(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'psgc');
        file_put_contents($csv, implode("\n", [
            'code,type,name,city,province,region',
            '041000000,province,Batangas,,,CALABARZON',
            '041018000,municipality,Mataasnakahoy,,Batangas,CALABARZON',
            '041018020,barangay,Santol,Mataasnakahoy,Batangas,CALABARZON',
            '031420018,barangay,"Mataas na Kahoy",Alicia,Isabela,"Cagayan Valley"',
            '072217000,city,"City of Cebu",,Cebu,"Central Visayas"',
            '137404000,city,"Quezon City",,"Metro Manila","Metro Manila"',
        ]));
        $this->artisan('psgc:import', ['--path' => $csv])->assertSuccessful();
        unlink($csv);
    }

    private function staff(): User
    {
        return (new User)->forceFill(['user_id' => 1, 'role' => 'staff', 'status' => 'active']);
    }

    public function test_import_builds_readable_labels(): void
    {
        $this->importSample();

        $this->assertSame(6, PsgcLocation::query()->count());
        $this->assertSame('Brgy. Santol, Mataasnakahoy, Batangas', PsgcLocation::query()->find('041018020')->label);
        $this->assertSame('Mataasnakahoy, Batangas', PsgcLocation::query()->find('041018000')->label);
    }

    public function test_spaced_spelling_finds_the_town_first(): void
    {
        $this->importSample();

        $labels = PsgcLocation::search('mataas na kahoy')->pluck('label')->all();

        $this->assertSame('Mataasnakahoy, Batangas', $labels[0]);
        $this->assertContains('Brgy. Mataas na Kahoy, Alicia, Isabela', $labels);
    }

    public function test_words_can_come_from_different_parts_of_the_address(): void
    {
        $this->importSample();

        $this->assertSame(['Brgy. Santol, Mataasnakahoy, Batangas'], PsgcLocation::search('santol batangas')->pluck('label')->all());
        $this->assertSame('City of Cebu, Cebu', PsgcLocation::search('cebu')->first()->label);
    }

    public function test_endpoint_returns_suggestions_for_staff(): void
    {
        $this->importSample();

        $this->actingAs($this->staff())
            ->getJson(route('locations.search', ['q' => 'quezon']))
            ->assertOk()
            ->assertJsonPath('items.0.label', 'Quezon City, Metro Manila')
            ->assertJsonPath('items.0.type', 'city');
    }

    public function test_too_short_query_returns_nothing(): void
    {
        $this->importSample();

        $this->actingAs($this->staff())->getJson(route('locations.search', ['q' => 'a']))->assertOk()->assertExactJson(['items' => []]);
    }

    public function test_clients_and_guests_cannot_search(): void
    {
        $this->getJson(route('locations.search', ['q' => 'cebu']))->assertUnauthorized();

        $client = (new User)->forceFill(['user_id' => 2, 'role' => 'client', 'status' => 'active']);
        $this->actingAs($client)->getJson(route('locations.search', ['q' => 'cebu']))->assertForbidden();
    }
}
