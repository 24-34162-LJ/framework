<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyCompatibilityTest extends TestCase
{
    public function test_login_page_keeps_legacy_form_fields_and_url(): void
    {
        $this->get('/login.php')
            ->assertOk()
            ->assertSee('<form method="post" action="" id="loginForm" novalidate>', false)
            ->assertSee('name="login"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="remember"', false)
            ->assertSee('name="csrf"', false);
    }

    public function test_login_validation_errors_are_preserved_on_the_legacy_url(): void
    {
        $response = $this->from('/login.php')->post('/login.php', []);

        $response->assertRedirect('/login.php');
        $response->assertSessionHas('legacy_login_error', 'Enter your email or username and password.');
    }

    public function test_active_user_can_sign_in_with_the_existing_password_hash(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->unsignedInteger('user_id')->primary();
            $table->string('full_name');
            $table->string('email');
            $table->string('password_hash');
            $table->string('role');
            $table->unsignedInteger('client_id')->nullable();
            $table->string('status');
        });

        $user = new User([
            'full_name' => 'AstraTrack Admin',
            'email' => 'admin@example.test',
            'password_hash' => Hash::make('existing-password'),
            'role' => 'admin',
            'status' => 'active',
        ]);
        $user->user_id = 1;
        $user->save();

        $response = $this->post('/login.php', [
            'login' => 'admin@example.test',
            'password' => 'existing-password',
        ]);

        $response->assertRedirect('/account-management/admin.php');
        $this->assertAuthenticatedAs($user);
    }

    public function test_client_cannot_open_another_clients_shipment_by_id(): void
    {
        Schema::create('shipments', function (Blueprint $table): void {
            $table->unsignedInteger('shipment_id')->primary();
            $table->unsignedInteger('client_id');
        });
        DB::table('shipments')->insert([
            'shipment_id' => 42,
            'client_id' => 2,
        ]);

        $client = new User([
            'full_name' => 'Client One',
            'email' => 'client@example.test',
            'password_hash' => Hash::make('password'),
            'role' => 'client',
            'client_id' => 1,
            'status' => 'active',
        ]);
        $client->user_id = 7;

        $this->actingAs($client)
            ->get('/client-management/clint_shipment.php?id=42')
            ->assertNotFound();
    }

    public function test_client_is_denied_from_the_admin_dashboard(): void
    {
        $client = new User([
            'full_name' => 'Client One',
            'email' => 'client@example.test',
            'password_hash' => Hash::make('password'),
            'role' => 'client',
            'client_id' => 1,
            'status' => 'active',
        ]);
        $client->user_id = 7;

        $this->actingAs($client)
            ->get('/account-management/admin.php')
            ->assertRedirect('/login.php');
    }

    public function test_shared_sidebar_keeps_legacy_brand_and_role_menus(): void
    {
        $menus = [
            'admin' => ['/account-management/admin.php', '/account-management/user_management.php', '/operations/reports.php'],
            'staff' => ['/account-management/staff.php', '/shipment-management/staff_shipment.php', '/operations/notifications.php'],
            'client' => ['/client-management/client_dashbroad.php', '/client-management/clint_shipment.php', '/client-management/client_profile.php'],
        ];

        foreach ($menus as $role => $expectedLinks) {
            $user = new User([
                'full_name' => 'Example '.ucfirst($role),
                'email' => $role.'@example.test',
                'password_hash' => 'unused',
                'role' => $role,
                'status' => 'active',
            ]);
            $user->user_id = 1;
            $this->actingAs($user);

            $html = Blade::render('<x-sidebar active="dashboard" />');

            $this->assertStringContainsString('Astra Cargo', $html);
            $this->assertStringContainsString('Impex Solutions OPC', $html);
            $this->assertStringContainsString('assets/images/astra-logo-400w.png', $html);
            $this->assertStringNotContainsString('data-sidebar-close', $html);
            foreach ($expectedLinks as $href) {
                $this->assertStringContainsString('href="'.$href.'"', $html);
            }
        }
    }

    public function test_legacy_dashboard_header_uses_the_original_topbar_structure(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->unsignedInteger('notification_id')->primary();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('shipment_id')->nullable();
            $table->string('title');
            $table->text('message');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('read_at')->nullable();
        });

        $admin = new User([
            'full_name' => 'System Administrator',
            'email' => 'admin@example.test',
            'password_hash' => 'unused',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $admin->user_id = 1;
        $this->actingAs($admin);

        $html = Blade::render('<x-page-header title="Admin Dashboard" description="Oversee system records." search-action="/operations/search.php" search-placeholder="Search records or users" legacy-dashboard />');

        $this->assertStringContainsString('<header class="topbar">', $html);
        $this->assertStringContainsString('<h1>Admin Dashboard</h1>', $html);
        $this->assertStringContainsString('class="search"', $html);
        $this->assertStringContainsString('class="topbar-right"', $html);
        $this->assertStringNotContainsString('class="crumbs"', $html);

        $profileHtml = Blade::render('<x-page-header title="My Profile" description="Account and company information" profile-header />');

        $this->assertStringContainsString('class="topbar profile-topbar"', $profileHtml);
        $this->assertStringContainsString('class="profile-heading"', $profileHtml);
        $this->assertStringContainsString('class="notification-menu"', $profileHtml);
        $this->assertStringNotContainsString('class="profile"', $profileHtml);

        $modernHtml = Blade::render('<x-page-header title="My Shipments" :breadcrumbs="[[\'Dashboard\', \'/dashboard.php\'], [\'My Shipments\', null]]" />');

        $this->assertStringContainsString('<header class="topnav">', $modernHtml);
        $this->assertStringContainsString('id="menuToggle"', $modernHtml);
        $this->assertStringContainsString('<strong>System Administrator</strong>', $modernHtml);
        $this->assertStringNotContainsString('<small>Admin</small>', $modernHtml);
    }

    public function test_pager_uses_small_legacy_controls_instead_of_unstyled_svg_arrows(): void
    {
        $paginator = new LengthAwarePaginator(range(21, 40), 42, 20, 2, [
            'path' => '/operations/activity.php',
            'query' => ['q' => 'Sample Staff'],
        ]);
        $pages = [];
        foreach ([1, 2, 3] as $page) {
            $pages[$page] = $paginator->url($page);
        }

        $html = view('staff.partials.pager', [
            'paginator' => $paginator,
            'elements' => [$pages],
            'anchor' => null,
        ])->render();

        $this->assertStringContainsString('class="pager"', $html);
        $this->assertStringContainsString('class="page-btn active" aria-current="page">2</span>', $html);
        $this->assertStringContainsString('aria-label="Previous page"', $html);
        $this->assertStringContainsString('aria-label="Next page"', $html);
        $this->assertStringNotContainsString('<svg', $html);
    }
}
