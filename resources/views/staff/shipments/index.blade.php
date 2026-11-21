{{--
    FILE PURPOSE
    File   : resources/views/staff/shipments/index.blade.php
    Feature: Staff portal
    Purpose: Staff shipment list page.
--}}

<x-app-layout title="Shipment Management" active="shipments">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/staff_shipment.css') }}?v=9" rel="stylesheet">
    @endpush
    @push('scripts')
        <script src="{{ asset('assets/js/staff_shipment.js') }}?v=2" defer></script>
    @endpush

    <div class="page">
        <header class="page-head">
            <div>
                <h1>Shipment Management</h1>
            </div><a class="btn btn-primary" href="{{ route('shipments.add') }}">＋ Add Shipment</a>
        </header>

        <section class="fcard">
            <nav class="tabs" aria-label="Filter by status">
                <a class="tab {{ $statusKey === '' ? 'active' : '' }}"
                    href="{{ request()->fullUrlWithQuery(['status' => null, 'page' => null]) }}">All <span
                        class="tab-count">{{ number_format($tabCounts[''] ?? 0) }}</span></a>
                @foreach ($groups as $key => $group)
                    <a class="tab {{ $key === $statusKey ? 'active' : '' }}"
                        href="{{ request()->fullUrlWithQuery(['status' => $key, 'page' => null]) }}">{{ $group['label'] }} <span
                            class="tab-count">{{ number_format($tabCounts[$key] ?? 0) }}</span></a>
                @endforeach
            </nav>

            <form class="toolbar" method="get" action="{{ route('staff.shipments') }}" role="search">
                @if ($statusKey !== '')
                    <input type="hidden" name="status" value="{{ $statusKey }}">
                @endif
                <label class="search-field"><span aria-hidden="true">⌕</span><input type="text" name="q"
                        value="{{ $search }}" placeholder="Search by shipment reference or client"
                        aria-label="Search by shipment reference or client"></label>
                <select class="select" name="date" data-autosubmit aria-label="Filter by date">
                    @foreach ($dateOptions as $value => $label)
                        <option value="{{ $value }}" @selected($value === $dateKey)>{{ $label }}</option>
                    @endforeach
                </select>
                <select class="select" name="arrival" data-autosubmit aria-label="Filter by shipment key dates">
                    @foreach ($arrivalOptions as $value => $label)
                        <option value="{{ $value }}" @selected($value === $arrivalKey)>{{ $label }}</option>
                    @endforeach
                </select>
                <button type="submit" class="sr-only">Search</button>
            </form>

            <div class="table-wrap">
                <table class="ship-table">
                    <thead>
                        <tr>
                            <th>Shipment Reference</th>
                            <th>Client Name</th>
                            <th>Shipment Date</th>
                            <th>ETA</th>
                            <th>Type</th>
                            <th>Mode</th>
                            <th>B/L or AWB No.</th>
                            <th>Current Status</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($shipments as $shipment)
                            @php($status = $shipment->dashboard_status)
                            <tr>
                                <td class="ref" data-label="Shipment Reference"><a
                                        href="{{ route('shipments.show', $shipment->shipment_id) }}">{{ $shipment->reference_no }}</a>
                                </td>
                                <td data-label="Client Name">{{ $shipment->client?->client_name ?? '—' }}</td>
                                <td class="soft" data-label="Shipment Date">{{ $shipment->created_at?->format('M d, Y') ?? '—' }}</td>
                                <td class="soft" data-label="ETA">{{ $shipment->eta_date?->format('M d, Y') ?? '—' }}@if ($shipment->first_free_day)
                                        <small class="row-date-note">Free day · {{ $shipment->first_free_day->format('M d, Y') }}</small>
                                        @endif @if ($shipment->dashboard_overdue)
                                            <small class="row-overdue">Delivery overdue</small>
                                        @endif
                                </td>
                                <td data-label="Type">{{ ucfirst((string) $shipment->transaction_type) }}</td>
                                <td data-label="Mode">{{ ucfirst((string) $shipment->mode) }}</td>
                                <td class="soft" data-label="B/L or AWB No.">{{ $shipment->bl_awb_no ?: '—' }}</td>
                                <td data-label="Current Status"><span
                                        class="badge badge-{{ $status['class'] }}">{{ $status['label'] }}</span></td>
                                <td class="soft" data-label="Last Updated">
                                    {{ $shipment->status_updates_max_updated_at ? \Illuminate\Support\Carbon::parse($shipment->status_updates_max_updated_at)->format('M d, Y') : $shipment->created_at?->format('M d, Y') ?? '—' }}
                                </td>
                                <td data-label="Actions">
                                    <div class="row-actions"><a class="btn-sm btn-sm-view"
                                            href="{{ route('shipments.show', $shipment->shipment_id) }}">View</a>
                                        @if ($shipment->dashboard_edit_allowed)
                                            <a class="btn-sm btn-sm-edit"
                                            href="{{ route('shipments.edit', $shipment->shipment_id) }}">Edit</a>@else<span
                                                class="btn-sm btn-sm-edit is-disabled" aria-disabled="true"
                                                title="Only the assigned staff member or an administrator can edit this shipment">Edit</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @empty
                                <tr>
                                    <td class="empty" colspan="10">
                                        {{ $search !== '' || $statusKey !== '' || $dateKey !== '' || $arrivalKey !== '' ? 'No shipments match your filters.' : 'No shipments yet.' }}
                                        @if ($search !== '' || $statusKey !== '' || $dateKey !== '' || $arrivalKey !== '')
                                            <a class="link" href="{{ route('staff.shipments') }}">Clear filters</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="ship-foot">
                    <p>Showing {{ $shipments->firstItem() ?? 0 }}–{{ $shipments->lastItem() ?? 0 }} of {{ $total }}
                        shipment{{ $total === 1 ? '' : 's' }}</p>
                    {{ $shipments->onEachSide(1)->links('staff.partials.pager', ['anchor' => 'shipment-list']) }}
                </div>
            </section>
        </div>
    </x-app-layout>
