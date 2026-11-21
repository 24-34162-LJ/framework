{{--
    FILE PURPOSE
    File   : resources/views/admin/identity/index.blade.php
    Feature: ID Recognition
    Purpose: Admin list of client ID checks (review queue).
--}}

<x-app-layout title="ID Verification" active="identity" :breadcrumbs="[['Dashboard', '/account-management/admin.php'], ['ID Verification', null]]">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/shipments.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/reports.css') }}?v=9" rel="stylesheet">
        <link href="{{ asset('assets/css/identity.css') }}?v=2" rel="stylesheet">
    @endpush

    @php
        $tabs = ['needs_review' => 'Needs review', 'verified' => 'Verified', 'rejected' => 'Rejected', 'all' => 'All'];
        $badges = ['verified' => 'badge-green', 'needs_review' => 'badge-amber', 'rejected' => 'badge-red', 'processing' => 'badge-blue'];
        $emptyText = [
            'needs_review' => [
                'Nothing to review',
                'Every uploaded ID has been decided. New IDs that need a manual check will appear here.',
            ],
            'verified' => ['No verified IDs yet', 'IDs approved automatically or by an admin will appear here.'],
            'rejected' => ['No rejected IDs', 'IDs that were not accepted will appear here.'],
            'all' => ['No IDs uploaded yet', 'Clients upload their ID from the ID verification section of My Profile.'],
        ][$status];
    @endphp

    <div class="page idv-page idv-scope">
        <header class="page-head">
            <div>
                <h1>ID Verification</h1>
                <p class="sub">Check client IDs that could not be verified automatically.</p>
            </div>
        </header>

        @if (session('success'))
            <div class="flash" role="status">{{ session('success') }}</div>
        @endif

        <section class="fcard report-records" aria-labelledby="idv-queue-heading">
            <header>
                <div>
                    <h2 id="idv-queue-heading">Client IDs</h2>
                    <p>{{ number_format($verifications->total()) }} {{ $verifications->total() === 1 ? 'ID' : 'IDs' }} in this view</p>
                </div>
            </header>

            <nav class="tabs" aria-label="Filter by status">
                @foreach ($tabs as $key => $label)
                    <a class="tab {{ $status === $key ? 'active' : '' }}" href="{{ route('admin.identity.index', ['status' => $key]) }}"
                        @if ($status === $key) aria-current="page" @endif>
                        {{ $label }}@if ($key !== 'all')
                            <span class="tab-count">{{ (int) ($counts[$key] ?? 0) }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>

            @if ($verifications->isEmpty())
                <div class="idv-empty" role="status"><strong>{{ $emptyText[0] }}</strong><span>{{ $emptyText[1] }}</span></div>
            @else
                <div class="report-table-wrap">
                    <table class="report-table">
                        <caption class="idv-sr">Client ID checks, {{ strtolower($tabs[$status]) }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">Client</th>
                                <th scope="col">ID type</th>
                                <th scope="col">Status</th>
                                <th scope="col">Reason</th>
                                <th scope="col">Uploaded</th>
                                <th scope="col"><span class="idv-sr">Action</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($verifications as $item)
                                @php
                                    $reason = match (true) {
                                        $item->status === 'processing' => $item->isStaleProcessing()
                                            ? 'Not read yet: the background reader has not run. Check that the queue worker is running, or review the image yourself.'
                                            : 'Being read now.',
                                        (bool) $item->reader_error => 'Automatic reading failed. Check the image yourself.',
                                        default => implode(' ', $item->checks['reasons'] ?? []),
                                    };
                                @endphp
                                <tr>
                                    <td class="idv-client-cell">
                                        <strong>{{ $item->client?->client_name ?? 'No company' }}</strong><span>{{ $item->user?->full_name ?? 'Unknown user' }}</span>
                                    </td>
                                    <td>{{ \App\Services\Identity\PhIdRules::label($item->id_type) }}</td>
                                    <td><span class="badge {{ $badges[$item->status] ?? 'badge-blue' }}">{{ $item->statusLabel() }}</span>
                                    </td>
                                    <td class="idv-reason">{{ $reason !== '' ? \Illuminate\Support\Str::limit($reason, 120) : '—' }}</td>
                                    <td>{{ $item->created_at?->format('M d, Y · g:i A') }}</td>
                                    <td><a href="{{ route('admin.identity.show', $item->verification_id) }}"
                                            aria-label="Review ID #{{ $item->verification_id }} from {{ $item->client?->client_name ?? $item->user?->full_name }}">{{ $item->status === 'needs_review' ? 'Review' : 'View' }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($verifications->hasPages())
                    <footer class="report-pager">
                        <span>Showing {{ $verifications->firstItem() }}–{{ $verifications->lastItem() }} of
                            {{ number_format($verifications->total()) }}</span>
                        <nav aria-label="ID check pages">
                            @if ($verifications->currentPage() > 1)
                                <a href="{{ $verifications->previousPageUrl() }}">Previous</a>
                            @endif
                            <span>Page {{ $verifications->currentPage() }} of {{ $verifications->lastPage() }}</span>
                            @if ($verifications->hasMorePages())
                                <a href="{{ $verifications->nextPageUrl() }}">Next</a>
                            @endif
                        </nav>
                    </footer>
                @endif
            @endif
        </section>
    </div>
</x-app-layout>
