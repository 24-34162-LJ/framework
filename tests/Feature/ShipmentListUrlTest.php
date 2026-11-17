<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/ShipmentListUrlTest.php
 * Feature: Testing - Shipments
 * Purpose: Tests that "Back to Shipments" returns to the same list.
 */

namespace Tests\Feature;

use App\Models\User;
use App\Support\ShipmentListUrl;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * "Back to Shipments" / "Cancel" must return to the list exactly as the user left it
 * (tab, filters, search, page), not to a search for the shipment that was just opened.
 */
class ShipmentListUrlTest extends TestCase
{
    private function user(string $role): User
    {
        return (new User)->forceFill(['user_id' => 1, 'role' => $role]);
    }

    private function visitList(User $user, string $uri): void
    {
        $request = Request::create($uri);
        $request->setLaravelSession($this->app['session.store']);
        $request->setUserResolver(fn () => $user);
        ShipmentListUrl::remember($request);
    }

    public function test_defaults_to_the_full_list_with_no_search(): void
    {
        $this->assertSame(route('staff.shipments'), ShipmentListUrl::for($this->user('staff')));
        $this->assertSame(route('admin.shipments'), ShipmentListUrl::for($this->user('admin')));
        $this->assertStringNotContainsString('q=', ShipmentListUrl::for($this->user('staff')));
    }

    public function test_returns_to_the_list_as_it_was_left(): void
    {
        $staff = $this->user('staff');
        $this->visitList($staff, '/staff/shipments?status=transit&date=30d&page=2');

        $this->assertSame(url('/staff/shipments?status=transit&date=30d&page=2'), ShipmentListUrl::for($staff));
    }

    public function test_unfiltered_list_comes_back_unfiltered(): void
    {
        $staff = $this->user('staff');
        $this->visitList($staff, '/shipment-management/staff_shipment.php');

        $this->assertSame(url('/shipment-management/staff_shipment.php'), ShipmentListUrl::for($staff));
    }

    public function test_each_role_keeps_its_own_list(): void
    {
        $this->visitList($this->user('admin'), '/admin/shipments?staff=unassigned');

        $this->assertSame(route('staff.shipments'), ShipmentListUrl::for($this->user('staff')));
        $this->assertSame(url('/admin/shipments?staff=unassigned'), ShipmentListUrl::for($this->user('admin')));
    }

    public function test_never_returns_an_off_site_address(): void
    {
        $staff = $this->user('staff');
        $this->app['session.store']->put('shipments.list_url.staff', '//evil.example/phish');
        $this->assertSame(route('staff.shipments'), ShipmentListUrl::for($staff));

        $this->app['session.store']->put('shipments.list_url.staff', 'https://evil.example/');
        $this->assertSame(route('staff.shipments'), ShipmentListUrl::for($staff));
    }

    public function test_export_downloads_are_not_remembered(): void
    {
        $admin = $this->user('admin');
        $this->visitList($admin, '/admin/shipments?status=delivered');
        $this->visitList($admin, '/admin/shipments?status=delivered&export=1');

        $this->assertSame(url('/admin/shipments?status=delivered'), ShipmentListUrl::for($admin));
    }
}
