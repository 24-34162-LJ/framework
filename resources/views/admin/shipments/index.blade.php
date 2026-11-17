<x-app-layout title="Shipment Management" active="shipments">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/shipments.css') }}?v=10" rel="stylesheet">
    @endpush
    @push('scripts')<script src="{{ asset('assets/js/shipments.js') }}?v=2" defer></script>@endpush

    <div class="page">
        <header class="page-head"><div><h1>Shipment Management</h1></div><div class="head-buttons"><a class="btn btn-secondary" href="{{ request()->fullUrlWithQuery(['export' => 1]) }}">⇩ Export</a><a class="btn btn-primary" href="{{ route('shipments.add') }}">＋ Add Shipment</a></div></header>

        <section class="fcard">
            <nav class="tabs" aria-label="Filter by status">
                <a class="tab {{ $statusKey === '' && $scope !== 'archived' ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['status' => null, 'scope' => null, 'page' => null, 'export' => null]) }}">All <span class="tab-count">{{ number_format($tabCounts[''] ?? 0) }}</span></a>
                @foreach ($groups as $key => $group)
                    <a class="tab {{ $key === $statusKey && $scope !== 'archived' ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['status' => $key, 'scope' => null, 'page' => null, 'export' => null]) }}">{{ $group['label'] }} <span class="tab-count">{{ number_format($tabCounts[$key] ?? 0) }}</span></a>
                @endforeach
                <a class="tab {{ $scope === 'archived' ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['scope' => 'archived', 'status' => null, 'page' => null, 'export' => null]) }}">Archived <span class="tab-count">{{ number_format($archivedCount) }}</span></a>
            </nav>

            <form class="toolbar" method="get" action="{{ route('admin.shipments') }}" role="search">
                @if ($statusKey !== '')<input type="hidden" name="status" value="{{ $statusKey }}">@endif
                <input type="hidden" name="scope" value="{{ $scope }}">
                <label class="search-field"><span aria-hidden="true">⌕</span><input type="text" name="q" value="{{ $search }}" placeholder="Search by reference, client, B/L or container no." aria-label="Search by reference, client, B/L or container no."></label>
                <select class="select" name="staff" data-autosubmit aria-label="Filter by staff"><option value="">All Staff</option><option value="unassigned" @selected($staffKey === 'unassigned')>Unassigned</option>@foreach ($staffList as $staff)<option value="{{ $staff->user_id }}" @selected((string) $staff->user_id === $staffKey)>{{ $staff->full_name }}</option>@endforeach</select>
                <select class="select" name="date" data-autosubmit aria-label="Filter by date">@foreach ($dateOptions as $value => $label)<option value="{{ $value }}" @selected($value === $dateKey)>{{ $label }}</option>@endforeach</select>
                <select class="select" name="arrival" data-autosubmit aria-label="Filter by shipment key dates">@foreach ($arrivalOptions as $value => $label)<option value="{{ $value }}" @selected($value === $arrivalKey)>{{ $label }}</option>@endforeach</select>
                <button type="submit" class="sr-only">Search</button>
            </form>

            <div class="bulk-assign-bar" id="bulkAssignBar" hidden><strong><span id="bulkSelectedCount">0</span> selected</strong><button type="button" class="btn-sm" id="bulkAssignOpen">♙ Assign to…</button><button type="button" class="bulk-clear" id="bulkClear">Clear selection</button><span>Unassigned shipments only</span></div>

            <div class="table-wrap"><table class="ship-table">
                <thead><tr><th class="select-col"><input type="checkbox" id="selectVisible" aria-label="Select all unassigned shipments on this page"></th><th>Shipment Reference</th><th>Client Name</th><th>Shipment Date</th><th>ETA</th><th>Type</th><th>Mode</th><th>B/L or AWB No.</th><th>Current Status</th><th>Assigned Staff</th><th>Last Updated</th><th>Actions</th></tr></thead>
                <tbody>
                    @forelse ($shipments as $shipment)
                        @php($status = $shipment->dashboard_status)
                        @php($lastUpdated = $shipment->status_updates_max_updated_at ? \Illuminate\Support\Carbon::parse($shipment->status_updates_max_updated_at) : $shipment->created_at)
                        <tr data-shipment-row="{{ $shipment->shipment_id }}" data-reference="{{ $shipment->reference_no }}">
                            <td class="select-col" data-label="Select">@if (! $shipment->assigned_staff_id && $shipment->isOpen())<input type="checkbox" class="shipment-select" value="{{ $shipment->shipment_id }}" aria-label="Select {{ $shipment->reference_no }} for assignment">@endif</td>
                            <td class="ref" data-label="Shipment Reference"><a href="{{ route('shipments.show', $shipment->shipment_id) }}">{{ $shipment->reference_no }}</a></td>
                            <td data-label="Client Name">{{ $shipment->client?->client_name ?? '—' }}</td>
                            <td class="soft" data-label="Shipment Date">{{ $shipment->created_at?->format('M d, Y') ?? '—' }}</td>
                            <td class="soft" data-label="ETA">{{ $shipment->eta_date?->format('M d, Y') ?? '—' }}@if ($shipment->first_free_day)<small class="row-date-note">Free day · {{ $shipment->first_free_day->format('M d, Y') }}</small>@endif @if ($shipment->dashboard_overdue)<small class="row-overdue">Delivery overdue</small>@endif @if ($scope === 'archived')<small class="row-archived">Archived {{ $shipment->archived_at?->format('M d, Y') ?? '—' }}</small>@endif</td>
                            <td data-label="Type">{{ ucfirst((string) $shipment->transaction_type) }}</td><td data-label="Mode">{{ ucfirst((string) $shipment->mode) }}</td><td class="soft" data-label="B/L or AWB No.">{{ $shipment->bl_awb_no ?: '—' }}</td>
                            <td data-label="Current Status"><span class="badge badge-{{ $status['class'] }}">{{ $status['label'] }}</span></td>
                            <td data-label="Assigned Staff">@if ($shipment->assignedStaff)<span class="staff-chip"><span class="chip-av">{{ collect(preg_split('/\s+/', trim($shipment->assignedStaff->full_name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') }}</span>{{ $shipment->assignedStaff->full_name }}</span>@elseif (! $shipment->isOpen())<span class="soft-text">Unassigned</span>@else<button type="button" class="assign-row" data-assign-one="{{ $shipment->shipment_id }}" data-reference="{{ $shipment->reference_no }}">● Unassigned <span>Assign</span></button>@endif</td>
                            <td class="soft" data-label="Last Updated">{{ $lastUpdated?->format('M d, Y') ?? '—' }}</td>
                            <td data-label="Actions"><div class="row-actions"><a class="btn-sm btn-sm-view" href="{{ route('shipments.show', $shipment->shipment_id) }}">View</a>@if (! $shipment->archived_at)<a class="btn-sm btn-sm-edit" href="{{ route('shipments.edit', $shipment->shipment_id) }}">Edit</a>@else<span class="btn-sm btn-sm-edit is-disabled" aria-disabled="true" title="Archived shipments are read-only">Edit</span>@endif<div class="more"><button type="button" class="btn-sm btn-sm-more" data-more aria-haspopup="true" aria-expanded="false" aria-label="More actions for {{ $shipment->reference_no }}">⋮</button><div class="more-menu" role="menu" hidden><a role="menuitem" href="{{ route('shipments.show', $shipment->shipment_id) }}">View details</a><button role="menuitem" type="button" data-copy="{{ $shipment->reference_no }}">Copy reference</button></div></div></div></td>
                        </tr>
                    @empty
                        <tr><td class="empty" colspan="12">{{ $filtering ? 'No shipments match your search.' : 'No shipments yet.' }} @if ($filtering)<a class="link" href="{{ route('admin.shipments') }}">Clear filters</a>@endif</td></tr>
                    @endforelse
                </tbody>
            </table></div>
            
            <div class="ship-foot"><p>Showing {{ $shipments->firstItem() ?? 0 }}–{{ $shipments->lastItem() ?? 0 }} of {{ $total }} shipment{{ $total === 1 ? '' : 's' }}</p>{{ $shipments->onEachSide(1)->links('staff.partials.pager', ['anchor' => 'shipments']) }}</div>
        </section>
    </div>

    <dialog class="assignment-dialog" id="assignmentDialog" aria-labelledby="assignmentTitle" data-endpoint="{{ route('admin.shipments.assign') }}" data-csrf="{{ csrf_token() }}">
        <header class="assignment-head"><div><h2 id="assignmentTitle">Assign staff</h2><p id="assignmentSubtitle">Active staff only</p></div><button type="button" data-assignment-close aria-label="Close">×</button></header>
        <label class="assignment-search"><span class="sr-only">Search active staff</span><input id="assignmentSearch" type="search" placeholder="Search staff"></label>
        <div class="assignment-staff-list" id="assignmentStaffList">
            @foreach ($staffList as $staff)
                <label class="assignment-staff" data-staff-card data-staff-name="{{ mb_strtolower($staff->full_name) }}"><input type="radio" name="assignment_staff" value="{{ $staff->user_id }}"><span class="assignment-avatar">{{ collect(preg_split('/\s+/', trim($staff->full_name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') }}</span><span class="assignment-staff-info"><strong>{{ $staff->full_name }}</strong><small>{{ (int) ($staffLoad[$staff->user_id] ?? 0) }} open shipments</small></span><span class="assignment-selected">Selected</span></label>
            @endforeach
        </div>
        <div class="assignment-no-staff" id="assignmentNoStaff" @if ($staffList->isNotEmpty()) hidden @endif><strong>No active staff</strong><p>Activate a staff account in User Management to make an assignment.</p></div>
        <p class="assignment-preview" id="assignmentPreview" hidden></p>
        <footer class="assignment-foot"><button type="button" class="btn btn-secondary" data-assignment-close>Cancel</button><button type="button" class="btn btn-primary" id="assignmentSubmit" disabled>Assign</button></footer>
    </dialog>
    <div class="assignment-toast" id="assignmentToast" role="status" hidden></div>
</x-app-layout>
