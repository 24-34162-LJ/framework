<x-app-layout title="Shipment Requests" active="shipment_requests" :breadcrumbs="[['Dashboard', route('client.dashboard')], ['Shipment Requests', null]]">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/clint_shipment.css') }}?v=9" rel="stylesheet">
        <link href="{{ asset('assets/css/client_documents.css') }}?v=13" rel="stylesheet">
        <link href="{{ asset('assets/css/client_motion.css') }}?v=1" rel="stylesheet">
    @endpush
    @push('scripts')<script src="{{ asset('assets/js/client_documents.js') }}?v=2" defer></script><script src="{{ asset('assets/js/client_motion.js') }}?v=1" defer></script>@endpush

    <div class="page sr-page">
        <header class="page-head"><div><h1>Shipment Requests</h1><p class="sub">Send us your shipping documents and we will create the shipment for you.</p></div><a class="btn btn-secondary" href="{{ route('client.shipments') }}">My Shipments</a></header>
        @if (session('success'))<p class="flash" role="status">{{ session('success') }}</p>@endif

        <section class="fcard">
            <ol class="sr-steps" aria-label="How it works">
                <li><b>1 · Upload</b>Your B/L or AWB packet as one PDF (invoice and packing list included if you have them).</li>
                <li><b>2 · Auto-read</b>We read the documents automatically so our team can start faster.</li>
                <li><b>3 · Confirm</b>Our team checks the details and creates your shipment. You are notified.</li>
            </ol>
            <form class="sr-form" id="srForm" data-endpoint="{{ route('client.shipment-requests.store') }}" enctype="multipart/form-data" novalidate>
                <label class="cd-drop"><input type="file" name="file" id="srFile" accept=".pdf,application/pdf" required><strong>Choose your PDF or drag it here</strong><span id="srChosen">PDF only · up to 25 MB</span></label>
                <label>Notes for our team (optional) <textarea name="client_notes" rows="3" maxlength="1000" placeholder="e.g. Please deliver to our Laguna warehouse"></textarea></label>
                <p class="cd-message" id="srStatus" role="status" hidden></p>
                <div class="cd-progress" id="srProgress" role="progressbar" aria-label="Upload progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" hidden><span id="srProgressBar"></span></div>
                <div><button class="btn btn-primary" type="submit" id="srSubmit" disabled>Send request</button></div>
            </form>
        </section>

        <section class="fcard">
            <div class="card-head"><h2 class="card-title">Your requests</h2><span class="soft-text small">Latest 50</span></div>
            @if ($requests->isEmpty())
                <p class="soft-text">You have not sent any shipment requests yet.</p>
            @else
                <div class="table-wrap"><table class="sr-table"><thead><tr><th>Request</th><th>Sent</th><th>Documents</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                    @foreach ($requests as $item)
                        @php($form = $item->extracted_json['form'] ?? [])
                        <tr>
                            <td data-label="Request"><strong>{{ $item->referenceLabel() }}</strong>@if ($item->client_notes)<small>“{{ \Illuminate\Support\Str::limit($item->client_notes, 80) }}”</small>@endif</td>
                            <td data-label="Sent">{{ $item->created_at?->format('M d, Y · g:i A') }}</td>
                            <td data-label="Documents"><a href="{{ route('shipment-requests.file', $item->request_id) }}" target="_blank" rel="noopener">{{ $item->original_name }}</a>
                                @if ($item->extraction_status === 'done')<small>Read: B/L {{ $form['bl_awb_no'] ?? '—' }} · {{ ($form['port_of_loading'] ?? '—').' → '.($form['port_of_discharge'] ?? '—') }}</small>@elseif ($item->extraction_status === 'failed')<small>Automatic reading failed; our team will read it.</small>@endif
                            </td>
                            <td data-label="Status"><span class="sr-status sr-status-{{ $item->status }}">{{ ['pending' => 'Waiting for our team', 'converted' => 'Shipment created', 'declined' => 'Declined', 'cancelled' => 'Cancelled'][$item->status] ?? ucfirst($item->status) }}</span>
                                @if ($item->status === 'declined' && $item->staff_note)<small>Reason: {{ $item->staff_note }}</small>@endif
                            </td>
                            <td data-label="Actions" class="sr-actions">
                                @if ($item->status === 'converted' && $item->shipment)<a class="btn-sm" href="{{ route('client.shipments.show', $item->shipment_id) }}">Open {{ $item->shipment->reference_no }}</a>@endif
                                @if ($item->status === 'pending')<form method="post" action="{{ route('client.shipment-requests.cancel', $item->request_id) }}" class="cd-inline-form" onsubmit="return confirm('Cancel this request?')">@csrf<button type="submit" class="link cd-link-muted" aria-label="Cancel request {{ $item->referenceLabel() }}">Cancel</button></form>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody></table></div>
            @endif
        </section>
    </div>
</x-app-layout>
