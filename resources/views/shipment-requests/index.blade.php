{{--
    FILE PURPOSE
    File   : resources/views/shipment-requests/index.blade.php
    Feature: Shipment requests
    Purpose: Staff page to review client shipment requests.
--}}

<x-app-layout title="Shipment Requests" active="shipment_requests" :breadcrumbs="[['Dashboard', route('dashboard')], ['Shipment Requests', null]]">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/shipments.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/client_documents.css') }}?v=13" rel="stylesheet">
    @endpush
    @push('scripts')<script src="{{ asset('assets/js/client_documents.js') }}?v=2" defer></script>@endpush

    <div class="page sr-page">
        <header class="page-head"><div><h1>Shipment Requests</h1><p class="sub">Clients send their shipping documents here. Confirm the auto-read draft to create the shipment.</p></div></header>
        @if (session('success'))<p class="flash" role="status">{{ session('success') }}</p>@endif
        @if ($errors->any())<p class="flash flash-error" role="alert">{{ $errors->first() }}</p>@endif

        <nav class="sr-tabs" aria-label="Filter requests">
            @foreach (['pending' => 'Pending', 'converted' => 'Converted', 'declined' => 'Declined', 'cancelled' => 'Cancelled', 'all' => 'All'] as $key => $label)
                <a class="{{ $status === $key ? 'active' : '' }}" href="{{ route('shipment-requests.index', ['status' => $key]) }}">{{ $label }}{{ $key !== 'all' ? ' · '.($counts[$key] ?? 0) : '' }}</a>
            @endforeach
        </nav>

        <section class="fcard">
            @if ($requests->isEmpty())
                <p class="soft-text">No {{ $status === 'all' ? '' : $status }} requests.</p>
            @else
                <div class="table-wrap"><table class="sr-table"><thead><tr><th>Request</th><th>Client</th><th>Documents</th><th>Auto-read</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                    @foreach ($requests as $item)
                        @php($form = $item->extracted_json['form'] ?? [])
                        @php($checks = $item->extracted_json['checks'] ?? [])
                        <tr>
                            <td data-label="Request"><strong>{{ $item->referenceLabel() }}</strong><small>{{ $item->created_at?->format('M d, Y · g:i A') }}</small>@if ($item->client_notes)<small>“{{ \Illuminate\Support\Str::limit($item->client_notes, 120) }}”</small>@endif</td>
                            <td data-label="Client">{{ $item->client?->client_name ?? '—' }}<small>{{ $item->submittedBy?->full_name }}</small></td>
                            <td data-label="Documents"><a href="{{ route('shipment-requests.file', $item->request_id) }}" target="_blank" rel="noopener">{{ $item->original_name }}</a></td>
                            <td data-label="Auto-read">
                                @if ($item->extraction_status === 'done')
                                    B/L {{ $form['bl_awb_no'] ?? '—' }}<small>{{ ($form['port_of_loading'] ?? '—').' → '.($form['port_of_discharge'] ?? '—') }} · {{ count($form['containers'] ?? []) }} container(s)</small>
                                    @if ($checks)<small>{{ collect($checks)->where('ok', true)->count() }}/{{ count($checks) }} cross-checks passed</small>@endif
                                @elseif ($item->extraction_status === 'failed')
                                    <span class="cd-ai-warn">Failed</span><small>{{ $item->extraction_error }}</small>
                                @else
                                    <small>Not read yet</small>
                                @endif
                                @if ($item->status === 'pending' && $item->extraction_status !== 'done')<button type="button" class="link" data-sr-extract="{{ route('shipment-requests.extract', $item->request_id) }}">Run auto-read</button>@endif
                            </td>
                            <td data-label="Status"><span class="sr-status sr-status-{{ $item->status }}">{{ ucfirst($item->status) }}</span>
                                @if ($item->status === 'converted' && $item->shipment)<small><a href="{{ route('shipments.show', $item->shipment_id) }}">{{ $item->shipment->reference_no }}</a></small>@endif
                                @if ($item->status === 'declined')<small>{{ $item->staff_note }}</small>@endif
                                @if ($item->reviewedBy)<small>by {{ $item->reviewedBy->full_name }}</small>@endif
                            </td>
                            <td data-label="Actions" class="sr-actions">
                                @if ($item->status === 'pending')
                                    <a class="btn btn-primary" href="{{ route('shipments.add', ['request' => $item->request_id]) }}">Create shipment</a>
                                    <details class="cd-reject"><summary class="btn-sm cd-reject-btn">Decline</summary><form method="post" action="{{ route('shipment-requests.decline', $item->request_id) }}">@csrf<label>Reason for the client<input name="staff_note" maxlength="255" required placeholder="e.g. Please send the complete B/L"></label><button type="submit" class="btn btn-primary">Decline and notify</button></form></details>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody></table></div>
                @if ($requests->hasPages())<footer class="bill-table-foot"><span>Page {{ $requests->currentPage() }} of {{ $requests->lastPage() }}</span><nav class="bill-pager">@if ($requests->previousPageUrl())<a href="{{ $requests->previousPageUrl() }}">Previous</a>@endif @if ($requests->nextPageUrl())<a href="{{ $requests->nextPageUrl() }}">Next</a>@endif</nav></footer>@endif
            @endif
        </section>
    </div>
</x-app-layout>
