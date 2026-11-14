@props(['active' => ''])

@php
    $user = auth()->user();
    $role = $user->role;
    $fullName = $user->full_name;
    $nameParts = collect(preg_split('/\s+/', trim($fullName)))->filter()->take(2);
    $initials = $nameParts->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');

    $icons = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'box' => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-6 6.5-6s6.5 2.5 6.5 6M16 4.5a3.5 3.5 0 010 7M18 14.5c2 .7 3.5 2.6 3.5 5.5"/>',
        'billing' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path d="M9 8h6M9 12h6"/>',
        'swap' => '<path d="M4 8h16m-4-4l4 4-4 4M20 16H4m4-4l-4 4 4 4"/>',
        'chart' => '<path d="M5 20V10M12 20V4M19 20v-7"/>',
        'activity' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        'bell' => '<path d="M18 8a6 6 0 00-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
        'shield' => '<path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6l8-3z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
        'user' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.6 3-6 7-6s7 2.4 7 6"/>',
        'inbox' => '<path d="M4 13l2.5-8h11L20 13v6H4v-6z"/><path d="M4 13h4.5l1.5 2.5h4l1.5-2.5H20"/><path d="M12 4v6m-2.5-2.5L12 10l2.5-2.5"/>',
        'logout' => '<path d="M9 4H5a1 1 0 00-1 1v14a1 1 0 001 1h4M16 8l4 4-4 4M20 12H9"/>',
    ];

    $makeMenu = fn (array $entries): array => collect($entries)->map(fn (array $entry, string $key): array => [
        'key' => $key,
        'label' => $entry[0],
        'href' => $entry[1],
        'icon' => $icons[$entry[2]],
    ])->values()->all();

    if ($role === 'client') {
        $menu = $makeMenu([
            'dashboard' => ['Dashboard', '/client-management/client_dashbroad.php', 'grid'],
            'shipments' => ['My Shipments', '/client-management/clint_shipment.php', 'box'],
            'shipment_requests' => ['Shipment Requests', '/client/shipment-requests', 'inbox'],
            'billing' => ['My Billing & Payments', '/client-management/client_billing.php', 'billing'],
            'transactions' => ['My Transactions', '/client-management/client_transactions.php', 'swap'],
            'notifications' => ['Notifications', '/operations/notifications.php', 'bell'],
            'profile' => ['My Profile', '/client-management/client_profile.php', 'user'],
        ]);
        $adminMenu = [];
    } else {
        $menu = $makeMenu([
            'dashboard' => ['Dashboard', $role === 'admin' ? '/account-management/admin.php' : '/account-management/staff.php', 'grid'],
            'shipments' => ['Shipment Management', $role === 'admin' ? '/shipment-management/shipments.php' : '/shipment-management/staff_shipment.php', 'box'],
            'shipment_requests' => ['Shipment Requests'.(($pendingRequests = rescue(fn () => \App\Models\ShipmentRequest::query()->where('status', 'pending')->count(), 0, false)) ?' · '.$pendingRequests : ''), '/shipment-requests', 'inbox'],
            'clients' => ['Client Management', '/client-management/clients.php', 'users'],
            'billing' => ['Billing & Payments', '/billing-management/billing.php', 'billing'],
            'transactions' => ['Transaction Management', '/billing-management/transactions.php', 'swap'],
        ]);
        if ($role === 'admin') {
            $menu = array_merge($menu, $makeMenu([
                'reports' => ['Reports', '/operations/reports.php', 'chart'],                'activity' => ['Recent Activity', '/operations/activity.php', 'activity'],
                'shipment_activity' => ['Shipment Updates', '/client-management/client_activity.php', 'activity'],
            ]));
            $adminMenu = $makeMenu([
                'users' => ['User Management', '/account-management/user_management.php', 'shield'],
            ]);
        } else {
            $menu = array_merge($menu, $makeMenu([
                'notifications' => ['Notifications', '/operations/notifications.php', 'bell'],
            ]));
            $adminMenu = [];
        }
    }
@endphp

<aside class="sidebar" id="sidebar" aria-label="AstraTrack navigation">
    <div class="brand">
        <img class="brand-image" src="{{ asset('assets/images/astra-logo-400w.png') }}?v=3" width="42" height="42" alt="Astra Cargo logo">
        <span class="brand-name">Astra Cargo<span>Impex Solutions OPC</span></span>
    </div>

    <p class="menu-title">MENU</p>
    <nav class="menu" aria-label="{{ $role === 'client' ? 'Client navigation' : 'Main navigation' }}">
        @foreach ($menu as $item)
            <a href="{{ $item['href'] }}" class="menu-item {{ $active === $item['key'] ? 'active' : '' }}" @if ($active === $item['key']) aria-current="page" @endif>
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $item['icon'] !!}</svg>
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>

    @if ($adminMenu)
        <p class="menu-title menu-title-gap">ADMINISTRATION</p>
        <nav class="menu" aria-label="Administration">
            @foreach ($adminMenu as $item)
                <a href="{{ $item['href'] }}" class="menu-item {{ $active === $item['key'] ? 'active' : '' }}" @if ($active === $item['key']) aria-current="page" @endif>
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $item['icon'] !!}</svg>
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>
    @endif

    <div class="sidebar-bottom">
        <div class="sidebar-user">
            <span class="avatar">{{ $initials !== '' ? $initials : 'U' }}</span>
            <div><strong>{{ $fullName }}</strong><small>{{ ucfirst($role) }}</small></div>
        </div>
        <a href="/logout.php" class="menu-item">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons['logout'] !!}</svg>
            <span>Logout</span>
        </a>
    </div>
</aside>
