<?php

/*
 * FILE PURPOSE
 * File   : tests/Feature/SidebarBadgeTest.php
 * Feature: Testing - Core
 * Purpose: Tests the sidebar count badge on ID Verification (IDs waiting for an admin).
 */

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SidebarBadgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('identity_verifications', function (Blueprint $table): void {
            $table->increments('verification_id');
            $table->unsignedInteger('user_id');
            $table->string('status', 20);
            $table->timestamps();
        });
        $this->actingAs((new User)->forceFill(['user_id' => 1, 'full_name' => 'System Administrator', 'role' => 'admin', 'status' => 'active']));
    }

    private function badgeFor(string $html, string $label): ?string
    {
        return preg_match('#<span>'.preg_quote($label, '#').'</span>\s*(?:<span class="menu-badge"[^>]*>(\d+\+?)</span>)?#', $html, $m) ? ($m[1] ?? null) : 'missing';
    }

    public function test_badge_counts_ids_waiting_for_review(): void
    {
        DB::table('identity_verifications')->insert([
            ['user_id' => 5, 'status' => 'needs_review', 'created_at' => now()],
            ['user_id' => 6, 'status' => 'needs_review', 'created_at' => now()],
            ['user_id' => 7, 'status' => 'verified', 'created_at' => now()],
            ['user_id' => 8, 'status' => 'processing', 'created_at' => now()->subHour()], // stuck: needs an admin too
        ]);

        $this->assertSame('3', $this->badgeFor(Blade::render('<x-sidebar active="dashboard" />'), 'ID Verification'));
    }

    public function test_no_badge_when_nothing_is_waiting(): void
    {
        DB::table('identity_verifications')->insert(['user_id' => 7, 'status' => 'verified', 'created_at' => now()]);

        $this->assertNull($this->badgeFor(Blade::render('<x-sidebar active="dashboard" />'), 'ID Verification'));
    }
}
