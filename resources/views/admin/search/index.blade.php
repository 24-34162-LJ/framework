{{--
    FILE PURPOSE
    File   : resources/views/admin/search/index.blade.php
    Feature: Admin - Search
    Purpose: Admin search results page.
--}}

<x-app-layout title="Search" active="search" plain-layout>
    @push('styles')
        <style>
            body.fig{margin:0;background:#f0f3fa;color:#1e2f4d;font:15px "Libre Franklin",Arial,sans-serif}.main{flex:1;min-width:0;margin-left:330px;padding:34px;max-width:1500px}
            .record-search{max-width:1100px;margin:0 auto}.record-search h1{margin:0 0 8px;color:#1e2f4d}.record-search .muted{color:#677b9a}.record-search-form{display:flex;gap:10px;margin:22px 0}.record-search-form input{flex:1;min-width:0;padding:12px 14px;border:1px solid #c1c9d5;border-radius:8px;font:inherit}.record-search-form button{padding:0 22px;border:0;border-radius:8px;background:#1e2f4d;color:#fff;font:inherit;cursor:pointer}.search-results{background:#fff;border:1px solid #e2e6ec;border-radius:14px;padding:20px 22px;margin:18px 0}.search-results h2{margin:0 0 8px;font-size:1.15rem;color:#1e2f4d}.search-result{display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center;padding:13px 0;border-bottom:1px solid #e2e8f7}.search-result:last-child{border:0;padding-bottom:0}.search-result a{font-weight:600;color:#1769d2}.search-result small{color:#677b9a}.search-empty{padding:16px 0;color:#677b9a}
            @media(max-width:900px){.main{margin-left:0;padding:22px}}@media(max-width:640px){.record-search-form{flex-direction:column}.record-search-form button{padding:12px}}
        </style>
    @endpush

    <section class="record-search">
        <h1>Search records</h1>
        <p class="muted">Search shipment references, B/L or AWB numbers, clients, and user accounts.</p>
        <form class="record-search-form" action="/operations/search.php" method="get" role="search">
            <input type="search" name="q" value="{{ $q }}" placeholder="Search records or users" aria-label="Search records or users" autofocus>
            <button type="submit">Search</button>
        </form>

        @if ($q === '')
            <div class="search-results search-empty">Enter a search term to find records.</div>
        @else
            <section class="search-results">
                <h2>Shipments</h2>
                @forelse ($shipments as $shipment)
                    <div class="search-result"><a href="{{ route('shipments.show', $shipment->shipment_id) }}">{{ $shipment->reference_no }}</a><small>{{ $shipment->client?->client_name }}</small><small>{{ $shipment->current_status }}</small></div>
                @empty<p class="search-empty">No matching records.</p>@endforelse
            </section>
            <section class="search-results">
                <h2>Clients</h2>
                @forelse ($clients as $client)
                    <div class="search-result"><a href="{{ route('admin.clients', ['id' => $client->client_id]) }}">{{ $client->client_name }}</a><small>{{ $client->email }}</small><small>{{ ucfirst($client->status) }}</small></div>
                @empty<p class="search-empty">No matching records.</p>@endforelse
            </section>
            <section class="search-results">
                <h2>User accounts</h2>
                @forelse ($users as $user)
                    <div class="search-result"><a href="{{ route('admin.users', ['user_id' => $user->user_id]) }}">{{ $user->full_name }}</a><small>{{ $user->email }}</small><small>{{ ucfirst($user->role) }} · {{ ucfirst($user->status) }}</small></div>
                @empty<p class="search-empty">No matching records.</p>@endforelse
            </section>
        @endif
    </section>
</x-app-layout>
