{{--
    FILE PURPOSE
    File   : resources/views/shipments/show.blade.php
    Feature: Shipments
    Purpose: Shipment details page for staff / admin.
--}}

<x-app-layout :title="$shipment->reference_no . ' · Shipment Details'" active="shipments">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/shipments.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/shipment_details.css') }}?v=20" rel="stylesheet">
        <link href="{{ asset('assets/css/shipment_status.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/customs_processing.css') }}?v=9" rel="stylesheet">
        <link href="{{ asset('assets/css/client_documents.css') }}?v=13" rel="stylesheet">
        {{-- staff.css resets every margin to 0, which removes the browser's modal centring; restore it. --}}
        <style>
            .lifecycle-dialog[open],
            .document-dialog[open],
            .dlg[open],
            .customs-dialog[open] {
                margin: auto;
            }
        </style>
    @endpush
    @push('scripts')
        <script src="{{ asset('assets/js/shipments.js') }}?v=2" defer></script>
        <script src="{{ asset('assets/js/shipment_status.js') }}?v=5" defer></script>
        <script src="{{ asset('assets/js/customs_processing.js') }}" defer></script>
        <script src="{{ asset('assets/js/shipment_details.js') }}?v=3" defer></script>
        <script src="{{ asset('assets/js/shipment_audit.js') }}" defer></script>
        <script src="{{ asset('assets/js/shipment_documents_manager.js') }}" defer></script>
        <script src="{{ asset('assets/js/shipment_lifecycle.js') }}" defer></script>
        <script src="{{ asset('assets/js/client_documents.js') }}" defer></script>
    @endpush
    @php
        $latestCustoms = $shipment->customsProcessingRecords->first();
        $role = auth()->user()->role;
        $isOnHold = $shipment->current_status === 'On Hold';
        $ymd = fn($date): string => $date ? \Illuminate\Support\Carbon::parse($date)->format('Y-m-d') : '';
        $customsJson = fn($record): string => json_encode(
            [
                'customs_id' => $record->customs_id,
                'entry_no' => $record->entry_no,
                'declaration_type' => $record->declaration_type,
                'lodgement_date' => $ymd($record->lodgement_date),
                'assessment_status' => $record->assessment_status,
                'customs_value' => $record->customs_value,
                'duties_taxes_amount' => $record->duties_taxes_amount,
                'duties_paid_date' => $ymd($record->duties_paid_date),
                'clearance_date' => $ymd($record->clearance_date),
                'examination_lane' => $record->examination_lane,
                'customs_remarks' => $record->customs_remarks,
            ],
            JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG,
        );
        $deliveryJson = $delivery
            ? json_encode(
                [
                    'delivery_id' => $delivery->delivery_id,
                    'trucker' => $delivery->trucker,
                    'driver_name' => $delivery->driver_name,
                    'plate_no' => $delivery->plate_no,
                    'scheduled_date' => $ymd($delivery->scheduled_date),
                    'scheduled_time' => (string) $delivery->scheduled_time,
                    'delivery_address' => $delivery->delivery_address,
                    'receiving_contact' => $delivery->receiving_contact,
                    'delivered_date' => $ymd($delivery->delivered_date),
                    'status' => $delivery->status,
                ],
                JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG,
            )
            : '';
        $customsFormOpen = $errors->any() && old('_form') === 'customs';
        $deliveryFormOpen = $errors->any() && old('_form') === 'delivery';
        $customsValues = $customsFormOpen ? old() : [];
        $deliveryValues = $deliveryFormOpen ? old() : [];
        $knownDeclarationTypes = ['Import · Consumption', 'Import · Warehousing', 'Export', 'Other'];
        $truckStatus = [
            'scheduled' => ['Scheduled', 'amber'],
            'in_transit' => ['In Transit', 'teal'],
            'delivered' => ['Delivered', 'green'],
            'cancelled' => ['Cancelled', 'red'],
        ];
        $initials = fn(?string $name): string => collect(preg_split('/\s+/', trim((string) $name)))
            ->filter()
            ->take(2)
            ->map(fn($part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    @endphp

    <div class="page shipment-details-page">
        <header class="page-head">
            <div>
                <h1>Shipment Details</h1>
                <p class="ref-row"><span class="soft-text">Shipment Reference:</span><strong>{{ $shipment->reference_no }}</strong><span
                        class="status-badge-slot" data-status-badge><span
                            class="badge badge-{{ $statusClass }}">{{ $statusLabel }}</span></span></p>
            </div>
            <div class="head-buttons">
                <a class="btn btn-secondary" href="{{ \App\Support\ShipmentListUrl::for(auth()->user()) }}">← Back to Shipments</a>
                <a class="btn btn-secondary" href="{{ url('/shipment-management/shipment_summary.php?id=' . $shipment->shipment_id) }}">Print
                    Summary</a>
                @if (!$isArchived && (!$isShipmentClosed || $role === 'admin') && !$needsClaim)
                    <a class="btn btn-secondary" href="{{ route('shipments.edit', $shipment->shipment_id) }}">Edit Shipment</a>
                @endif
                <div class="more head-more">
                    <button type="button" class="btn btn-secondary btn-more" data-more aria-haspopup="true" aria-expanded="false"
                        aria-label="More actions">&#8943;</button>
                    <div class="more-menu" role="menu" hidden>
                        <button role="menuitem" type="button" data-copy="{{ $shipment->reference_no }}">Copy reference</button>
                        @if ($role === 'admin' && ($isArchived || $isShipmentClosed || $isOnHold))
                            <button role="menuitem" type="button" data-lifecycle-action="reopen">Reopen shipment</button>
                        @endif
                        @if (!$isArchived && !$isShipmentClosed && !$isOnHold && !$needsClaim)
                            <button role="menuitem" type="button" data-lifecycle-action="hold">Put on hold</button>
                        @endif
                        @if ($role === 'admin' && !$isArchived && !$isShipmentClosed)
                            <button role="menuitem" type="button" data-lifecycle-action="cancel">Cancel shipment</button>
                        @endif
                        @if ($role === 'admin' && !$isArchived && $isShipmentClosed)
                            <button role="menuitem" type="button" data-lifecycle-action="archive">Archive shipment</button>
                        @endif
                    </div>
                </div>
                @if ($needsClaim)
                    <form method="post" action="{{ route('shipments.claim', $shipment->shipment_id) }}">@csrf
                        <button class="btn btn-primary" type="submit">Assign to me</button></form>
                @elseif (!$isArchived)
                    <button class="btn btn-primary" type="button" data-status-open aria-haspopup="dialog">Update Status</button>
                @endif
            </div>
        </header>

        @if (session('success'))
            <p class="flash" role="status">{{ session('success') }}</p>
        @endif
        @if ($unbilledDuties && !$isArchived)
            <div class="flash flash-action" role="status">
                <span>Duties and taxes of <strong>₱{{ number_format($unbilledDuties['amount'], 2) }}</strong> from Customs Details aren't billed yet.</span>
                <a class="btn btn-primary" href="{{ $unbilledDuties['url'] }}">Create duties bill</a>
            </div>
        @endif
        @if (session('error'))
            <p class="flash flash-error" role="alert">{{ session('error') }}</p>
        @endif
        @if ($errors->any() && !in_array(old('_form'), ['customs', 'delivery'], true))
            <p class="flash flash-error" role="alert">{{ $errors->first() }}</p>
        @endif
        @if ($needsClaim)
            <p class="flash flash-action" role="status"><span><strong>No staff is assigned to this shipment.</strong> You can view it, but
                    changes are locked until someone takes responsibility. Use <strong>Assign to me</strong> to work on it.</span></p>
        @endif
        @if ($isArchived)
            <div class="shipment-archived-banner"><strong>Archived shipment</strong><span>This record is hidden from active shipment lists.
                    Its shipment and billing history is retained.</span>
                @if ($role === 'admin')
                    <button type="button" data-lifecycle-action="reopen">Restore and reopen</button>
                @endif
            </div>
        @endif

        <section class="fcard stepper-card" aria-label="Shipment stages">
            <ol class="stepper" id="stepperList">
                @foreach (array_filter($progress['items'], fn(array $item): bool => $item['kind'] === 'stage') as $item)
                    <li class="step step-{{ $item['state'] }}"><span class="step-dot">
                            @if ($item['state'] === 'done')
                                ✓
                            @endif
                        </span><span class="step-text"><span class="step-label">{{ $item['label'] }}</span><span
                                class="step-date">{{ $item['short'] }}</span></span></li>
                @endforeach
            </ol>
        </section>

        @php
            $issues = collect($consistencyChecks)->where('ok', false)->values();
        @endphp
        {{-- Only records that disagree are listed; the card is hidden when everything matches --}}
        @if ($issues->isNotEmpty())
            <section class="fcard consistency-card has-issues" id="consistency" aria-labelledby="consistencyTitle">
                <header class="consistency-head">
                    <h2 class="consistency-title" id="consistencyTitle">Records to fix</h2>
                    <span class="consistency-sum">{{ $issues->count() }} {{ $issues->count() === 1 ? 'issue' : 'issues' }}</span>
                </header>
                <ul class="consistency-list">
                    @foreach ($issues as $check)
                        <li class="is-issue">
                            <span class="consistency-mark" aria-hidden="true">!</span>
                            <div>
                                <strong>{{ $check['issue'] }}</strong>
                                <p>{{ $check['detail'] }}@if ($check['link'])
                                        <a href="{{ $check['link'] }}">Fix it</a>
                                    @endif</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="cols">
            <div class="col-left">
                <section class="fcard collapse-card is-expanded">
                    <div class="collapse-head">
                        <div class="collapse-head-text">
                            <h2 class="card-title">Shipment Information</h2>
                            <p>{{ $shipment->reference_no }} · {{ $shipment->client?->client_name }}</p>
                        </div><button class="collapse-toggle" type="button" data-collapse aria-expanded="true"
                            aria-controls="shipmentInfoMore">Show less</button>
                    </div>
                    <div class="collapse-body" id="shipmentInfoMore">
                        <div class="field-row">
                            <div class="dfield">
                                <div class="lbl">Shipment Reference</div>
                                <div class="val">{{ $shipment->reference_no }}</div>
                            </div>
                            <div class="dfield">
                                <div class="lbl">Client Name</div>
                                <div class="val">{{ $shipment->client?->client_name ?? '—' }}</div>
                            </div>
                            <div class="dfield">
                                <div class="lbl">Shipment Date</div>
                                <div class="val">{{ $shipment->created_at?->format('M d, Y') ?? '—' }}</div>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="dfield">
                                <div class="lbl">Current Shipment Status</div>
                                <div class="val"><span class="status-badge-slot" data-status-badge><span
                                            class="badge badge-{{ $statusClass }}">{{ $statusLabel }}</span></span></div>
                            </div>
                            <div class="dfield">
                                <div class="lbl">Assigned Staff</div>
                                <div class="val">
                                    @if ($role === 'admin')
                                        <span class="assigned">
                                            @if ($shipment->assignedStaff)
                                                <span class="staff-chip"><span
                                                    class="chip-av">{{ $initials($shipment->assignedStaff->full_name) }}</span>{{ $shipment->assignedStaff->full_name }}</span>@else<span
                                                    class="soft-text">Unassigned</span>
                                                @endif @if ($canReassign)
                                                    <button type="button" class="link"
                                                        data-open-dialog="reassignDialog">Reassign</button>
                                                @endif
                                        </span>
                                        @else{{ $shipment->assignedStaff?->full_name ?? 'Unassigned' }}
                                    @endif
                                </div>
                            </div>
                            <div class="dfield">
                                <div class="lbl">Last Updated</div>
                                <div class="val"><span
                                        data-last-updated>{{ $progress['lastUpdated']?->format('M d, Y · g:i A') ?? '—' }}</span></div>
                            </div>
                        </div>
                        <div class="dfield">
                            <div class="lbl">Actual Arrival</div>
                            <div class="val"><span
                                    data-actual-arrival>{{ $shipment->actual_arrival_date?->format('M d, Y') ?? '—' }}</span></div>
                        </div>
                        <div class="dfield">
                            <div class="lbl">Description / Remarks</div>
                            <p class="remarks">{{ filled($shipment->remarks) ? $shipment->remarks : '—' }}</p>
                        </div>
                    </div>
                </section>

                <section class="fcard collapse-card is-expanded" id="key-dates">
                    <div class="collapse-head">
                        <div class="collapse-head-text">
                            <h2 class="card-title">Key Dates</h2>
                            <p>{{ collect([
                                $shipment->eta_date ? 'ETA ' . $shipment->eta_date->format('M d, Y') : null,
                                $shipment->actual_arrival_date ? 'Arrived ' . $shipment->actual_arrival_date->format('M d, Y') : null,
                                $shipment->delivery_due_date ? 'Due ' . $shipment->delivery_due_date->format('M d, Y') : null,
                            ])->filter()->implode(' · ') ?: ($shipment->first_free_day ? 'First Free Day ' . $shipment->first_free_day->format('M d, Y') : 'No arrival or delivery dates set') }}
                            </p>
                        </div><button class="collapse-toggle" type="button" data-collapse aria-expanded="true"
                            aria-controls="keyDatesMore">Show less</button>
                    </div>
                    <div class="collapse-body" id="keyDatesMore">
                        <div class="field-row">
                            <div class="dfield">
                                <div class="lbl">Estimated Arrival (ETA)</div>
                                <div class="val">{{ $shipment->eta_date?->format('M d, Y') ?? '—' }}</div>
                            </div>
                            <div class="dfield">
                                <div class="lbl">First Free Day</div>
                                <div class="val">{{ $shipment->first_free_day?->format('M d, Y') ?? '—' }}</div>
                            </div>
                            <div class="dfield">
                                <div class="lbl">Delivery Due Date</div>
                                <div class="val">{{ $shipment->delivery_due_date?->format('M d, Y') ?? '—' }}</div>
                            </div>
                            <div class="dfield">
                                <div class="lbl">Actual Arrival</div>
                                <div class="val">{{ $shipment->actual_arrival_date?->format('M d, Y') ?? '—' }}</div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="fcard collapse-card is-expanded">
                    <div class="collapse-head">
                        <div class="collapse-head-text">
                            <h2 class="card-title">Cargo and Route</h2>
                            <p>{{ ucfirst($shipment->transaction_type) }} · {{ ucfirst($shipment->mode) }} ·
                                {{ $shipment->port_of_loading ?: '—' }} → {{ $shipment->port_of_discharge ?: '—' }}</p>
                        </div><button class="collapse-toggle" type="button" data-collapse aria-expanded="true"
                            aria-controls="cargoMore">Show less</button>
                    </div>
                    <div class="collapse-body" id="cargoMore">
                        <div class="field-row">
                            <div class="dfield">
                                <div class="lbl">Transaction Type · Mode</div>
                                <div class="val">{{ ucfirst($shipment->transaction_type) }} · {{ ucfirst($shipment->mode) }}</div>
                            </div>
                            <div class="dfield">
                                <div class="lbl">{{ $modeLabels['document'] }}</div>
                                <div class="val">{{ $shipment->bl_awb_no ?: '—' }}</div>
                            </div>
                            <div class="dfield">
                                <div class="lbl">{{ $modeLabels['movement'] }}</div>
                                <div class="val">{{ $shipment->vessel_or_flight ?: '—' }}</div>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="dfield">
                                <div class="lbl">Shipper</div>
                                <div class="val">{{ $shipment->shipper_name ?: '—' }}</div>
                            </div>
                            <div class="dfield">
                                <div class="lbl">Consignee</div>
                                <div class="val">{{ $shipment->consignee_name ?: '—' }}</div>
                            </div>
                            <div class="dfield">
                                <div class="lbl">HS Code</div>
                                <div class="val">{{ $shipment->hs_code ?: '—' }}</div>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="dfield">
                                <div class="lbl">{{ $modeLabels['origin'] }}</div>
                                <div class="val">{{ $shipment->port_of_loading ?: '—' }}</div>
                            </div>
                            <div class="dfield">
                                <div class="lbl">{{ $modeLabels['destination'] }}</div>
                                <div class="val">{{ $shipment->port_of_discharge ?: '—' }}</div>
                            </div>
                            <div class="dfield">
                                <div class="lbl">Packages · Gross Weight · CBM</div>
                                <div class="val">{{ $shipment->total_packages !== null ? number_format((int) $shipment->total_packages) : '—' }} ·
                                    {{ $shipment->total_gross_weight !== null ? rtrim(rtrim(number_format((float) $shipment->total_gross_weight, 3), '0'), '.') : '—' }}
                                    kg · {{ $shipment->measurement_cbm !== null ? rtrim(rtrim(number_format((float) $shipment->measurement_cbm, 3), '0'), '.') : '—' }} CBM</div>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="dfield">
                                <div class="lbl">Incoterm</div>
                                <div class="val">{{ $shipment->incoterm ?: '—' }}</div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="fcard collapse-card is-expanded">
                    <div class="collapse-head">
                        <div class="collapse-head-text">
                            <h2 class="card-title">Invoice and Values</h2>
                            <p>{{ $shipment->invoice_no ?: '—' }} · {{ $shipment->currency ?: '—' }}
                                {{ $shipment->invoice_value !== null ? number_format((float) $shipment->invoice_value, 2) : '—' }}</p>
                        </div><button class="collapse-toggle" type="button" data-collapse aria-expanded="true"
                            aria-controls="invoiceMore">Show less</button>
                    </div>
                    <div class="collapse-body" id="invoiceMore">
                        <div class="field-row field-row-3">
                            <div class="dfield">
                                <div class="lbl">Invoice No.</div>
                                <div class="val">{{ $shipment->invoice_no ?: '—' }}</div>
                            </div>
                            <div class="dfield">
                                <div class="lbl">Currency</div>
                                <div class="val">{{ $shipment->currency ?: '—' }}</div>
                            </div>
                            <div class="dfield">
                                <div class="lbl">Invoice Value</div>
                                <div class="val">{{ $shipment->invoice_value !== null ? number_format((float) $shipment->invoice_value, 2) : '—' }}</div>
                            </div>
                        </div>
                        <div class="field-row field-row-3">
                            <div class="dfield">
                                <div class="lbl">Freight Amount</div>
                                <div class="val">{{ $shipment->freight_amount !== null ? number_format((float) $shipment->freight_amount, 2) : '—' }}</div>
                            </div>
                            <div class="dfield">
                                <div class="lbl">FOB Value</div>
                                <div class="val">{{ $shipment->fob_value !== null ? number_format((float) $shipment->fob_value, 2) : '—' }}</div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="fcard collapse-card is-expanded">
                    <div class="collapse-head">
                        <div class="collapse-head-text">
                            <h2 class="card-title">Containers</h2>
                            <p>{{ $shipment->containers->count() }}
                                {{ $shipment->containers->count() === 1 ? 'container' : 'containers' }}@if ($shipment->containers->isNotEmpty())
                                    · {{ $shipment->containers->first()->size_type ?: '—' }} ·
                                    {{ $shipment->containers->first()->container_no }}
                                @endif
                            </p>
                        </div><button class="collapse-toggle" type="button" data-collapse aria-expanded="true"
                            aria-controls="containersMore">Show less</button>
                    </div>
                    <div class="collapse-body" id="containersMore">
                        @if ($shipment->containers->isNotEmpty())
                            <div class="table-wrap">
                                <table class="ship-table">
                                    <thead>
                                        <tr>
                                            <th>Container No.</th>
                                            <th>Seal No.</th>
                                            <th>Size / Type</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($shipment->containers as $container)
                                            <tr>
                                                <td>{{ $container->container_no }}</td>
                                                <td>{{ $container->seal_no ?: '—' }}</td>
                                                <td>{{ $container->size_type ?: '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                        </div>@else<div class="empty-containers"><span aria-hidden="true">□</span><span>No containers for this
                                    {{ $shipment->mode }} shipment.</span></div>
                        @endif
                    </div>
                </section>

                <section class="fcard collapse-card is-expanded" id="customs">
                    <div class="collapse-head">
                        <div class="collapse-head-text">
                            <h2 class="card-title">Customs Processing</h2>
                            <p>
                                @if ($shipment->customsProcessingRecords->isNotEmpty())
                                    {{ $shipment->customsProcessingRecords->first()->entry_no ?: 'Entry not recorded' }} ·
                                    Assessment: {{ ucfirst($shipment->customsProcessingRecords->first()->assessment_status) }} ·
                                    {{ $shipment->customsProcessingRecords->first()->examination_lane ? ucfirst($shipment->customsProcessingRecords->first()->examination_lane) . ' lane' : 'Lane not assigned' }}
                                @else
                                    Not yet filed
                                @endif
                            </p>
                        </div>
                        <div class="collapse-actions"><button class="collapse-toggle" type="button" data-collapse aria-expanded="true"
                                aria-controls="customsMore">Show less</button>
                            @if ($canChangeDetails)
                                @if ($latestCustoms)
                                    <button class="link" type="button" data-customs-edit
                                    data-customs="{{ $customsJson($latestCustoms) }}">Edit</button><button class="link" type="button"
                                        data-customs-add>Add entry</button>@else<button class="link"
                                        type="button" data-customs-add>Add details</button>
                                @endif
                            @endif
                        </div>
                    </div>
                    <div class="collapse-body" id="customsMore">
                        @forelse ($shipment->customsProcessingRecords as $customs)
                            <div class="shipment-record">
                                <div class="customs-record-head"><span class="soft-text small">@if ($shipment->customsProcessingRecords->count() > 1)<strong
                                            class="customs-entry-label">{{ $loop->first ? 'Current entry' : 'Earlier entry' }}</strong> · @endif Updated
                                        {{ $customs->updated_at?->format('M d, Y · g:i A') ?? '—' }}{{ $customs->updatedBy?->full_name ? ' · ' . $customs->updatedBy->full_name : '' }}</span>
                                    @if ($canChangeDetails)
                                        <button class="link" type="button" data-customs-edit
                                            data-customs="{{ $customsJson($customs) }}">Edit</button>
                                    @endif
                                </div>
                                <div class="field-row">
                                    <div class="dfield">
                                        <div class="lbl">Entry No.</div>
                                        <div class="val">{{ $customs->entry_no ?: '—' }}</div>
                                    </div>
                                    <div class="dfield">
                                        <div class="lbl">Declaration Type</div>
                                        <div class="val">{{ $customs->declaration_type ?: '—' }}</div>
                                    </div>
                                    <div class="dfield">
                                        <div class="lbl">Lodgement Date</div>
                                        <div class="val">{{ $customs->lodgement_date?->format('M d, Y') ?? '—' }}</div>
                                    </div>
                                </div>
                                <div class="field-row">
                                    <div class="dfield">
                                        <div class="lbl">Assessment Status</div>
                                        <div class="val">{{ ucfirst($customs->assessment_status) }}</div>
                                    </div>
                                    <div class="dfield">
                                        <div class="lbl">Examination Lane</div>
                                        <div class="val">{{ $customs->examination_lane ? ucfirst($customs->examination_lane) : '—' }}
                                        </div>
                                    </div>
                                    <div class="dfield">
                                        <div class="lbl">Clearance Date</div>
                                        <div class="val">{{ $customs->clearance_date?->format('M d, Y') ?? '—' }}</div>
                                    </div>
                                </div>
                                <div class="field-row">
                                    <div class="dfield">
                                        <div class="lbl">Customs Value</div>
                                        <div class="val">
                                            {{ $customs->customs_value !== null ? 'PHP ' . number_format((float) $customs->customs_value, 2) : '—' }}
                                        </div>
                                    </div>
                                    <div class="dfield">
                                        <div class="lbl">Duties and Taxes</div>
                                        <div class="val">
                                            {{ $customs->duties_taxes_amount !== null ? 'PHP ' . number_format((float) $customs->duties_taxes_amount, 2) : '—' }}
                                        </div>
                                    </div>
                                    <div class="dfield">
                                        <div class="lbl">Duties Paid Date</div>
                                        <div class="val">{{ $customs->duties_paid_date?->format('M d, Y') ?? '—' }}</div>
                                    </div>
                                </div>
                                @if (filled($customs->customs_remarks))
                                    <p class="soft-text">{{ $customs->customs_remarks }}</p>
                                @endif
                            </div>
                        @empty<div class="customs-empty"><span class="customs-empty-icon" aria-hidden="true">＋</span>
                                <div><strong>Not yet filed</strong></div>
                            </div>
                        @endforelse
                    </div>
                </section>

                <section class="fcard collapse-card shipment-documents-card is-expanded" id="documents">
                    <div class="collapse-head">
                        <div class="collapse-head-text">
                            <h2 class="card-title">Documents</h2>
                            <p>{{ $documents->count() }} {{ $documents->count() === 1 ? 'document' : 'documents' }} ·
                                {{ $documentChecklist['requiredReceived'] }}/{{ $documentChecklist['requiredTotal'] }} required received
                                @if ($pendingReviewCount)
                                    <span class="cd-pill cd-pill-pending">{{ $pendingReviewCount }} to review</span>
                                @endif
                            </p>
                        </div>
                        <div class="collapse-actions"><button class="collapse-toggle" type="button" data-collapse aria-expanded="true"
                                aria-controls="documentsMore">Show less</button><button type="button" class="link"
                                data-documents-open>Upload document</button></div>
                    </div>
                    <div class="collapse-body" id="documentsMore">
                        @if ($errors->has('doc_type') || $errors->has('review_note') || $errors->has('due_date') || $errors->has('file'))
                            <p class="flash flash-error" role="alert">
                                {{ $errors->first('doc_type') ?: ($errors->first('review_note') ?: ($errors->first('due_date') ?: $errors->first('file'))) }}
                            </p>
                        @endif
                        <div class="document-checklist" aria-label="Required document checklist">
                            @foreach ($documentChecklist['slots'] as $slot)
                                @php
                                    $openRequest = $documentRequests->first(
                                        fn($r) => app(\App\Services\ShipmentDocumentService::class)->slotKey($r->doc_type) === $slot['key'],
                                    );
                                @endphp
                                <div class="document-check-row {{ $slot['received'] ? 'is-received' : 'is-missing' }}">
                                    <span class="document-check-icon" aria-hidden="true">{{ $slot['received'] ? '✓' : '•' }}</span>
                                    <span>{{ $slot['label'] }}@if (!$slot['required'])
                                            <small>(optional)</small>
                                        @endif
                                        @if ($slot['pending'])
                                            <small class="cd-pill cd-pill-pending">{{ $slot['pending'] }} awaiting review</small>
                                        @endif
                                        @if ($openRequest)
                                            <small
                                                class="cd-pill cd-pill-request {{ $openRequest->isOverdue() ? 'is-overdue' : '' }}">Requested{{ $openRequest->due_date ? ' · due ' . $openRequest->due_date->format('M d') : '' }}{{ $openRequest->isOverdue() ? ' · overdue' : '' }}</small>
                                        @endif
                                    </span>
                                    <strong>{{ $slot['received'] ? ($slot['count'] > 1 ? $slot['count'] . ' files' : 'Received') : ($slot['required'] ? 'Missing' : 'Not provided') }}</strong>
                                    @if (!$isArchived)
                                        @if ($openRequest)
                                            <form method="post"
                                                action="{{ route('documents.request.cancel', $openRequest->request_id) }}"
                                                class="cd-inline-form">@csrf<button type="submit" class="link cd-link-muted">Cancel
                                                    request</button></form>
                                        @elseif (!$slot['received'] && !$slot['pending'])
                                            <button type="button" class="link" data-request-doc="{{ $slot['label'] }}">Request from
                                                client</button>
                                        @endif
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        @if ($documents->isNotEmpty())
                            <div class="document-table-wrap">
                                <table class="document-table">
                                    <thead>
                                        <tr>
                                            <th>Document Type</th>
                                            <th>File Name</th>
                                            <th>Size</th>
                                            <th>Uploaded By</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($documents as $document)
                                            @php
                                                $status = $document->review_status ?? 'accepted';
                                            @endphp
                                            <tr class="{{ $status === 'pending' ? 'cd-row-pending' : '' }}">
                                                <td>{{ $document->doc_type }}@if (filled($document->remarks))
                                                        <small class="document-remarks">{{ $document->remarks }}</small>
                                                    @endif
                                                </td>
                                                <td class="document-filename">
                                                    {{ $document->original_name ?: basename((string) $document->file_path) }}@if ($document->replaces_document_id)
                                                        <small class="cd-muted">Replaces an earlier version</small>
                                                        @endif @if (!$document->available)
                                                            <small class="document-unavailable">File unavailable</small>
                                                        @endif
                                                </td>
                                                <td>{{ $document->available ? ($document->file_size >= 1048576 ? number_format($document->file_size / 1048576, 1) . ' MB' : number_format(max(1, $document->file_size / 1024), 0) . ' KB') : '—' }}
                                                </td>
                                                <td>{{ $document->uploadedBy?->full_name ?? '—' }}@if (($document->source ?? 'staff') === 'client')
                                                        <small class="cd-pill cd-pill-client">Client</small>
                                                    @endif
                                                </td>
                                                <td>{{ $document->uploaded_at?->format('M d, Y') ?? '—' }}</td>
                                                <td>
                                                    <span
                                                        class="cd-status cd-status-{{ $status }}">{{ ['pending' => 'Pending review', 'accepted' => 'Accepted', 'rejected' => 'Rejected'][$status] ?? ucfirst($status) }}</span>
                                                    @if ($status === 'rejected' && $document->review_note)
                                                        <small class="cd-muted">“{{ $document->review_note }}”</small>
                                                    @endif
                                                    @if ($document->reviewedBy && $status !== 'pending')
                                                        <small class="cd-muted">by {{ $document->reviewedBy->full_name }}</small>
                                                    @endif
                                                    @include('partials.document-ai-check', [
                                                        'document' => $document,
                                                        'audience' => 'staff',
                                                    ])
                                                </td>
                                                <td>
                                                    <div class="document-actions">
                                                        @if ($document->available)
                                                            <a class="btn-sm btn-sm-view"
                                                                href="{{ route('shipments.documents.download', ['documentId' => $document->document_id, 'view' => 1]) }}"
                                                                target="_blank" rel="noopener">View</a><a class="btn-sm"
                                                            href="{{ route('shipments.documents.download', $document->document_id) }}">Download</a>@else<span
                                                                class="soft-text small">Unavailable</span>
                                                        @endif
                                                        @if ($status === 'pending')
                                                            <form method="post"
                                                                action="{{ route('documents.review', $document->document_id) }}"
                                                                class="cd-inline-form">@csrf<input type="hidden" name="decision"
                                                                    value="accept"><button type="submit"
                                                                    class="btn-sm cd-accept">Accept</button></form>
                                                            <details class="cd-reject">
                                                                <summary class="btn-sm cd-reject-btn">Reject</summary>
                                                                <form method="post"
                                                                    action="{{ route('documents.review', $document->document_id) }}">
                                                                    @csrf<input type="hidden" name="decision"
                                                                        value="reject"><label>Reason for the client<input
                                                                            name="review_note" maxlength="255" required
                                                                            placeholder="e.g. Page 2 is missing"></label><button
                                                                        type="submit" class="btn btn-primary">Reject and notify</button>
                                                                </form>
                                                            </details>
                                                        @endif
                                                        @if ($role === 'admin')
                                                            <button type="button" class="document-delete"
                                                                data-document-delete="{{ $document->document_id }}"
                                                                data-file-name="{{ basename((string) $document->file_path) }}">Delete</button>
                                                        @endif
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                        </div>@else<div class="document-empty"><strong>No documents uploaded yet</strong>
                                <p>Upload the shipment paperwork to track required documents.</p>
                            </div>
                        @endif
                        @if ($documentHistory->isNotEmpty())
                            <details class="cd-history">
                                <summary>Version history · {{ $documentHistory->count() }} replaced
                                    {{ $documentHistory->count() === 1 ? 'file' : 'files' }}</summary>
                                <ul>
                                    @foreach ($documentHistory as $old)
                                        <li><strong>{{ $old->doc_type }}</strong>
                                            <span>{{ $old->original_name ?: basename((string) $old->file_path) }}</span> <small
                                                class="cd-muted">uploaded {{ $old->uploaded_at?->format('M d, Y') }} · replaced
                                                {{ $old->superseded_at?->format('M d, Y') }}</small>
                                            @if ($old->available)
                                                <a class="btn-sm"
                                                    href="{{ route('shipments.documents.download', $old->document_id) }}">Download</a>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                        <div class="document-footer"><span>{{ $documents->where('available', true)->count() }}
                                {{ $documents->where('available', true)->count() === 1 ? 'file' : 'files' }} available ·
                                {{ $documentChecklist['requiredReceived'] }} of {{ $documentChecklist['requiredTotal'] }} required
                                document types received</span>
                            @if ($documents->where('available', true)->isNotEmpty())
                                <a href="{{ route('shipments.documents.download-all', $shipment->shipment_id) }}">Download accepted
                                    documents</a>
                            @endif
                        </div>
                    </div>
                </section>

                <section class="fcard collapse-card is-expanded" id="delivery">
                    <div class="collapse-head">
                        <div class="collapse-head-text">
                            <h2 class="card-title">Delivery Information</h2>
                            <p>{{ $delivery ? ($delivery->trucker ?: 'Trucking details') . ' · ' . ucfirst(str_replace('_', ' ', $delivery->status)) : 'Not yet scheduled' }}
                            </p>
                        </div>
                        <div class="collapse-actions"><button class="collapse-toggle" type="button" data-collapse aria-expanded="true"
                                aria-controls="deliveryMore">Show less</button>
                            @if ($canChangeDetails)
                                @if ($delivery)
                                    <button class="link" type="button" data-delivery-edit
                                    data-delivery="{{ $deliveryJson }}">Edit</button>@else<button class="link" type="button"
                                        data-delivery-add>Schedule delivery</button>
                                @endif
                            @endif
                        </div>
                    </div>
                    <div class="collapse-body" id="deliveryMore">
                        @if ($delivery)
                            <div class="field-row">
                                <div class="dfield">
                                    <div class="lbl">Trucking Company</div>
                                    <div class="val">{{ $delivery->trucker ?: '—' }}</div>
                                </div>
                                <div class="dfield">
                                    <div class="lbl">Pickup Schedule</div>
                                    <div class="val">
                                        {{ $delivery->scheduled_date?->format('M d, Y') ?? '—' }}{{ $delivery->scheduled_time ? ' · ' . substr((string) $delivery->scheduled_time, 0, 5) : '' }}
                                    </div>
                                </div>
                                <div class="dfield">
                                    <div class="lbl">Delivery Status</div>
                                    <div class="val">
                                        @if (isset($truckStatus[$delivery->status]))
                                            <span
                                                class="badge badge-{{ $truckStatus[$delivery->status][1] }}">{{ $truckStatus[$delivery->status][0] }}</span>
                                        @else
                                            —
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="dfield">
                                <div class="lbl">Delivery Address</div>
                                <div class="val">{{ $delivery->delivery_address ?: '—' }}</div>
                            </div>
                            <div class="field-row">
                                <div class="dfield">
                                    <div class="lbl">Driver Name</div>
                                    <div class="val">{{ $delivery->driver_name ?: '—' }}</div>
                                </div>
                                <div class="dfield">
                                    <div class="lbl">Plate No.</div>
                                    <div class="val">{{ $delivery->plate_no ?: '—' }}</div>
                                </div>
                                <div class="dfield">
                                    <div class="lbl">Receiving Contact</div>
                                    <div class="val">{{ $delivery->receiving_contact ?: '—' }}</div>
                                </div>
                            </div>
                            <div class="dfield">
                                <div class="lbl">Delivered Date</div>
                                <div class="val">{{ $delivery->delivered_date?->format('M d, Y') ?? 'To be confirmed' }}</div>
                        </div>@else<div class="customs-empty"><span class="customs-empty-icon" aria-hidden="true">↗</span>
                                <div><strong>Delivery not scheduled</strong></div>
                                @if ($canChangeDetails)
                                    <button class="btn btn-primary" type="button" data-delivery-add>Schedule delivery</button>
                                @endif
                            </div>
                        @endif
                    </div>
                </section>
            </div>

            <aside class="col-right">
                <section class="fcard progress">
                    <div class="card-head">
                        <h2 class="card-title">Shipment Progress</h2>
                    </div>
                    <ol class="timeline" id="timelineList">
                        @foreach ($progress['items'] as $item)
                            <li class="tl-item tl-{{ $item['state'] }}">
                                <div class="tl-indicator"><span class="tl-dot">
                                        @if ($item['state'] === 'done')
                                            ✓
                                        @endif
                                    </span><span class="tl-line"></span></div>
                                <div class="tl-text">
                                    <div class="tl-label"><span>{{ $item['label'] }}</span>
                                        @if ($item['state'] === 'current' || $item['kind'] === 'exception')
                                            <span
                                                class="badge badge-{{ $item['kind'] === 'exception' ? $item['colour'] : 'blue' }}">Current</span>
                                        @endif
                                    </div>
                                    <div class="tl-date">{{ $item['date'] }}</div>
                                    @if ($item['note'] !== '')
                                        <p class="tl-note">{{ $item['note'] }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </section>
                <section class="fcard audit">
                    <div class="card-head">
                        <h2 class="card-title">Audit Trail</h2><button class="link audit-open" type="button" data-audit-open
                            aria-haspopup="dialog">View all</button>
                    </div>
                    <div id="auditBody">
                        @if ($latestAudit->isNotEmpty())
                            <ul class="audit-list">
                                @foreach ($latestAudit as $activity)
                                    <li><time
                                            datetime="{{ $activity->created_at?->toAtomString() }}">{{ $activity->created_at?->format('M d · g:i A') ?? '—' }}</time><span>{{ $activity->performed_by_name ? $activity->performed_by_name . ': ' : '' }}{{ $activity->description }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @else<p class="soft-text">No activity recorded for this shipment yet.</p>
                        @endif
                    </div>
                </section>
            </aside>
        </div>
    </div>

    <dialog class="sdlg" id="statusDialog" aria-labelledby="statusDlgTitle"
        data-endpoint="{{ route('shipments.status.update', $shipment->shipment_id) }}"
        data-state="{{ json_encode($statusModalState, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) }}">
        <div class="sdlg-head">
            <div>
                <h2 id="statusDlgTitle">Update Shipment Status</h2>
                <p class="sdlg-sub">Shipment <strong id="sfRef"></strong> <span id="sfCurrentBadge"></span></p>
            </div><button type="button" class="sdlg-x" data-status-close aria-label="Close">&times;</button>
        </div>
        <form class="sdlg-form" id="statusForm" novalidate autocomplete="off">
            <input type="hidden" name="shipment_id" value="{{ $shipment->shipment_id }}">
            <div class="sdlg-body">
                <div class="sf-banner" id="sfBanner" role="alert" hidden></div>
                @if (auth()->user()->role === 'admin')
                    <label class="sf-correct" id="sfCorrectWrap"><span class="sf-switch"><input type="checkbox" id="sfCorrect"
                                name="correction_toggle" role="switch"><span></span></span><span><strong>Correct a previous
                                stage</strong><small>Admin only. Allows moving backwards and makes remarks mandatory.</small></span></label>
                @endif
                <div class="sf-field" data-field="stage"><label class="sf-label" for="sfStage"><span id="sfStageLabel">New Stage <span
                                class="req">*</span></span></label><select class="select" id="sfStage" name="stage"></select>
                    <div class="sf-help" id="sfCurrent"></div>
                    <div class="sf-note" id="sfNote" hidden></div>
                    <div class="sf-note sf-warn" id="sfWarn" hidden>Correcting a stage updates the timeline and is recorded in the
                        Audit Trail.</div>
                </div>
                <div class="sf-field" data-field="occurred_at"><label class="sf-label" for="sfAt">Date and Time <span
                            class="req">*</span><span class="hint">Defaults to now</span></label><input type="datetime-local"
                        id="sfAt" name="occurred_at" step="60"></div>
                <div class="sf-field" data-field="actual_arrival_date"><label class="sf-label" for="sfArrival">Actual Arrival <span
                            class="hint" id="sfArrivalHint">Optional</span></label><input type="date" id="sfArrival" name="actual_arrival_date">
                    <div class="sf-help" id="sfArrivalHelp">Record the shipment's arrival date. Leave blank if it has not arrived.</div>
                </div>
                <div class="sf-field" data-field="reason" id="sfReasonField" hidden><label class="sf-label" for="sfReason">Reason <span
                            class="req">*</span></label><select class="select" id="sfReason" name="reason">
                        <option value="">Select a reason</option>
                        <option>Missing documents</option>
                        <option>Client request</option>
                        <option>Payment issue</option>
                        <option>Customs issue</option>
                        <option>Other</option>
                    </select></div>
                <div class="sf-field" data-field="remarks"><label class="sf-label" for="sfRemarks">Remarks <span class="req"
                            id="sfRemarksReq" hidden>*</span><span class="hint" id="sfRemarksHint">Optional</span></label>
                    <textarea id="sfRemarks" name="remarks" maxlength="255" rows="3" placeholder="Add a note for the shipment timeline..."></textarea>
                    <div class="sf-meta"><span class="sf-help">Visible in the timeline and Audit Trail.</span><span class="sf-counter"
                            id="sfCounter">0 / 255</span></div>
                </div>
                <label class="sf-notify"><input type="checkbox" id="sfNotify" name="notify" value="1"
                        checked><span><strong>Notify client in AstraTrack</strong><small>Adds an in-app notification and shows the update in
                            the client's shipment timeline.</small></span></label>
            </div>
            <div class="sdlg-foot"><button type="button" class="btn btn-secondary" id="sfCancel"
                    data-status-close>Cancel</button><button type="submit" class="btn btn-primary" id="sfSave">Save Update</button>
            </div>
        </form>
        <div class="sdlg-form" id="statusConfirm" hidden>
            <div class="sdlg-body">
                <div class="sf-confirm"><span class="sf-confirm-ico" aria-hidden="true">✓</span>
                    <h3 id="sfConfirmText">Mark this shipment as Delivered?</h3>
                    <p>This marks the shipment as completed.</p>
                    <div class="sf-flow" id="sfConfirmFlow"></div>
                    <p class="sf-small" id="sfConfirmSmall"></p>
                </div>
            </div>
            <div class="sdlg-foot"><button type="button" class="btn btn-secondary" id="sfConfirmBack">Back</button><button
                    type="button" class="btn btn-primary" id="sfConfirmYes">Yes, mark as Delivered</button></div>
        </div>
    </dialog>

    @if ($role === 'admin')
        <dialog class="dlg" id="reassignDialog" aria-labelledby="reassignDlgTitle">
            <form method="post" action="{{ route('shipments.reassign', $shipment->shipment_id) }}" autocomplete="off"
                id="reassignForm" data-current-staff="{{ (int) $shipment->assigned_staff_id }}">
                @csrf
                <input type="hidden" name="action" value="reassign">
                <input type="hidden" name="staff_id" id="reassignStaffId" value="">
                <section id="reassignPickerPanel">
                    <header class="assign-detail-head">
                        <div>
                            <h2 id="reassignDlgTitle">Reassign staff</h2>
                            <p>Active staff only</p>
                        </div><button type="button" data-close-dialog aria-label="Close">&times;</button>
                    </header>
                    <label class="assign-detail-search"><span class="sr-only">Search staff</span><input type="search"
                            id="reassignSearch" placeholder="Search staff"></label>
                    <div class="assign-detail-list">
                        @foreach ($staffList as $staff)
                            <label class="assign-detail-option" data-reassign-staff data-name="{{ mb_strtolower($staff->full_name) }}">
                                <input type="radio" name="staff_choice" value="{{ $staff->user_id }}"
                                    data-name-label="{{ $staff->full_name }}" data-open-count="{{ (int) $staff->open_shipments }}">
                                <span class="assignment-avatar">{{ $initials($staff->full_name) }}</span>
                                <span class="assignment-staff-info"><strong>{{ $staff->full_name }}</strong>
                                    @if ((int) $staff->user_id === (int) $shipment->assigned_staff_id)
                                    <small>Current assignment</small>@else<small>{{ (int) $staff->open_shipments }} open
                                            shipments</small>
                                    @endif
                                </span>
                                <span class="assignment-selected">Selected</span>
                            </label>
                        @endforeach
                    </div>
                    @if ($staffList->isEmpty())
                        <div class="assignment-no-staff"><strong>No active staff</strong>
                            <p>Activate a staff account in User Management to make an assignment.</p>
                        </div>
                    @endif
                    <footer class="assignment-foot"><button type="button" class="btn btn-secondary"
                            data-close-dialog>Cancel</button><button type="button" class="btn btn-primary" id="reassignContinue"
                            disabled>Continue</button></footer>
                </section>
                <section id="reassignConfirmPanel" hidden>
                    <div class="reassign-confirm-head">
                        <h2>Reassign staff?</h2>
                        <p>{{ $shipment->reference_no }} · {{ $shipment->client?->client_name }}</p>
                    </div>
                    <div class="reassign-compare">
                        <div><small>Current staff</small><strong
                                id="reassignCurrentName">{{ $shipment->assignedStaff?->full_name ?? 'Unassigned' }}</strong></div><span
                            aria-hidden="true">→</span>
                        <div><small>New staff</small><strong id="reassignNewName">—</strong></div>
                    </div>
                    <p class="reassign-confirm-copy">This shipment will be reassigned. Its current shipment status will stay the same; only
                        the staff assignment will change.</p>
                    <div class="dlg-actions"><button type="button" class="btn btn-secondary" id="reassignBack">Back</button><button
                            type="submit" class="btn btn-primary" id="reassignSave">Reassign</button></div>
                </section>
            </form>
        </dialog>
    @endif

    <dialog class="document-dialog" id="documentsDialog" aria-labelledby="documentsDialogTitle"
        data-upload-endpoint="{{ url('/shipment-management/shipment_documents_upload.php') }}"
        data-shipment-id="{{ $shipment->shipment_id }}">
        <form id="documentsUploadForm" enctype="multipart/form-data">
            <header class="document-dialog-head">
                <div>
                    <h2 id="documentsDialogTitle">Upload shipment documents</h2>
                    <p>{{ $shipment->reference_no }} · Add one or more files</p>
                </div><button type="button" data-documents-close aria-label="Close">&times;</button>
            </header>
            <div class="document-dialog-body">
                <label class="document-dropzone" id="documentDropzone"><input type="file" id="documentFiles"
                        accept=".pdf,.jpg,.jpeg,.png,.xls,.xlsx" multiple><strong>Choose files or drag them here</strong><span>PDF, JPG,
                        PNG, XLS, XLSX · Up to 10 MB per file</span></label>
                <div class="document-queue" id="documentQueue" aria-live="polite">
                    <p class="document-queue-empty">No files selected.</p>
                </div>
                <p class="document-upload-message" id="documentUploadMessage" role="status" hidden></p>
            </div>
            <footer class="document-dialog-foot"><span id="documentUploadProgress">Uploads are recorded in the shipment audit
                    trail.</span>
                <div><button type="button" class="btn btn-secondary" data-documents-close>Cancel</button><button type="submit"
                        class="btn btn-primary" id="documentUploadSubmit" disabled>Upload documents</button></div>
            </footer>
        </form>
    </dialog>

    <dialog class="lifecycle-dialog" id="shipmentActionDialog" aria-labelledby="shipmentActionTitle"
        data-endpoint="{{ url('/shipment-management/shipment_lifecycle.php') }}" data-shipment-id="{{ $shipment->shipment_id }}">
        <form id="shipmentActionForm">
            <input type="hidden" name="action" id="shipmentActionValue"><input type="hidden" name="shipment_id"
                value="{{ $shipment->shipment_id }}">
            <header>
                <div>
                    <h2 id="shipmentActionTitle">Shipment action</h2>
                    <p>{{ $shipment->reference_no }} · {{ $shipment->client?->client_name }}</p>
                </div><button type="button" data-lifecycle-close aria-label="Close">&times;</button>
            </header>
            <div class="lifecycle-body">
                <p id="shipmentActionPrompt"></p>
                <label id="lifecycleReasonField" hidden>Reason <span class="required-mark">*</span><select name="reason"
                        id="lifecycleReason">
                        <option value="">Select a reason</option>
                        <option>Missing documents</option>
                        <option>Client request</option>
                        <option>Payment issue</option>
                        <option>Customs issue</option>
                        <option>Operational issue</option>
                        <option>Other</option>
                    </select></label>
                <label id="lifecycleRemarksField">Remarks <span class="optional-mark">(optional)</span>
                    <textarea name="remarks" id="lifecycleRemarks" maxlength="255" rows="3" placeholder="Add a note for the shipment record"></textarea>
                </label>
                <p class="lifecycle-error" id="lifecycleError" role="alert" hidden></p>
            </div>
            <footer><button type="button" class="btn btn-secondary" data-lifecycle-close>Cancel</button><button type="submit"
                    class="btn btn-primary" id="lifecycleSubmit">Continue</button></footer>
        </form>
    </dialog>

    @php
        $cv = fn(string $key, $fallback = '') => $customsValues[$key] ?? $fallback;
        $postedType = (string) $cv('declaration_type');
        $customDeclarationPosted =
            $postedType === '__other__' || ($postedType !== '' && !in_array($postedType, $knownDeclarationTypes, true));
        $customTypeValue =
            (string) ($cv('declaration_type_custom') ?: ($customDeclarationPosted && $postedType !== '__other__' ? $postedType : ''));
    @endphp
    <dialog class="customs-dialog" id="customsDialog" aria-labelledby="customsDialogTitle"
        data-auto-open="{{ $customsFormOpen ? '1' : '0' }}">
        <form method="post" action="{{ route('shipments.customs.save', $shipment->shipment_id) }}#customs" id="customsForm"
            novalidate>
            @csrf
            <input type="hidden" name="_form" value="customs">
            <input type="hidden" name="customs_id" id="customsId" value="{{ $cv('customs_id') }}">
            <header class="customs-dialog-head">
                <div>
                    <h2 id="customsDialogTitle">Add Customs Details</h2>
                    <p>{{ $shipment->reference_no }} · {{ $shipment->client?->client_name }}</p>
                </div>
                <button type="button" class="customs-dialog-close" data-customs-close aria-label="Close">&times;</button>
            </header>
            <div class="customs-dialog-body">
                @if ($customsFormOpen)
                    <div class="customs-error-banner" role="alert">{{ $errors->first() }}</div>
                @endif
                <div class="customs-form-grid">
                    <label>Entry No.<input name="entry_no" type="text" maxlength="50" value="{{ $cv('entry_no') }}"
                            placeholder="e.g. C-2026-0148">
                        @if ($customsFormOpen && $errors->has('entry_no'))
                            <small class="customs-field-error">{{ $errors->first('entry_no') }}</small>
                        @endif
                    </label>
                    <label>Declaration Type<select name="declaration_type" id="customsDeclarationType" required>
                            <option value="">Select type</option>
                            @foreach ($knownDeclarationTypes as $declarationType)
                                <option value="{{ $declarationType }}" @selected($postedType === $declarationType)>{{ $declarationType }}</option>
                            @endforeach
                            <option value="__other__" @selected($customDeclarationPosted)>
                                Other / enter custom value</option>
                        </select><input type="text" name="declaration_type_custom" id="customsDeclarationTypeCustom" maxlength="40"
                            placeholder="Enter declaration type" value="{{ $customTypeValue }}"
                            @if (!$customDeclarationPosted) hidden @endif>
                        @if ($customsFormOpen && $errors->has('declaration_type'))
                            <small class="customs-field-error">{{ $errors->first('declaration_type') }}</small>
                        @endif
                    </label>
                    <label>Lodgement Date<input name="lodgement_date" type="date" value="{{ $cv('lodgement_date') }}">
                        @if ($customsFormOpen && $errors->has('lodgement_date'))
                            <small class="customs-field-error">{{ $errors->first('lodgement_date') }}</small>
                        @endif
                    </label>
                    <label>Assessment Status<select name="assessment_status" id="customsAssessmentStatus">
                            @foreach (['pending' => 'Pending', 'assessed' => 'Assessed', 'paid' => 'Paid'] as $value => $label)
                                <option value="{{ $value }}" @selected($cv('assessment_status', 'pending') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @if ($customsFormOpen && $errors->has('assessment_status'))
                            <small class="customs-field-error">{{ $errors->first('assessment_status') }}</small>
                        @endif
                    </label>
                    <label>Customs Value (PHP)<input name="customs_value" type="number" min="0.01" step="0.01"
                            value="{{ $cv('customs_value') }}" placeholder="0.00" required>
                        @if ($customsFormOpen && $errors->has('customs_value'))
                            <small class="customs-field-error">{{ $errors->first('customs_value') }}</small>
                        @endif
                    </label>
                    <label>Duties and Taxes (PHP)<input name="duties_taxes_amount" type="number" min="0.01" step="0.01"
                            value="{{ $cv('duties_taxes_amount') }}" placeholder="Add when assessed">
                        @if ($customsFormOpen && $errors->has('duties_taxes_amount'))
                            <small class="customs-field-error">{{ $errors->first('duties_taxes_amount') }}</small>
                        @endif
                    </label>
                    <label>Duties Paid Date<input name="duties_paid_date" type="date" value="{{ $cv('duties_paid_date') }}">
                        @if ($customsFormOpen && $errors->has('duties_paid_date'))
                            <small class="customs-field-error">{{ $errors->first('duties_paid_date') }}</small>
                        @endif
                    </label>
                    <label>Clearance Date<input name="clearance_date" type="date" value="{{ $cv('clearance_date') }}">
                        @if ($customsFormOpen && $errors->has('clearance_date'))
                            <small class="customs-field-error">{{ $errors->first('clearance_date') }}</small>
                        @endif
                    </label>
                    <fieldset class="customs-lane">
                        <legend>Examination Lane</legend>
                        @foreach (['green' => 'Green', 'yellow' => 'Yellow', 'orange' => 'Orange', 'red' => 'Red'] as $value => $label)
                            <label><input type="radio" name="examination_lane" value="{{ $value }}"
                                    @checked($cv('examination_lane') === $value)><span>{{ $label }}</span></label>
                        @endforeach
                        <label>
                            <input type="radio" name="examination_lane" value="" @checked(blank($cv('examination_lane')))><span>Not
                                set</span></label>
                        @if ($customsFormOpen && $errors->has('examination_lane'))
                            <small class="customs-field-error">{{ $errors->first('examination_lane') }}</small>
                        @endif
                    </fieldset>
                    <label class="customs-remarks">Customs Remarks
                        <textarea name="customs_remarks" rows="3" placeholder="Add a note for customs processing">{{ $cv('customs_remarks') }}</textarea>
                    </label>
                </div>
                <p class="customs-form-note">Dates must follow the processing order: lodgement, duties paid, then clearance.</p>
            </div>
            <footer class="customs-dialog-foot"><span>Admin and Staff access only</span>
                <div><button type="button" class="btn btn-secondary" data-customs-close>Cancel</button><button type="submit"
                        class="btn btn-primary">Save</button></div>
            </footer>
        </form>
    </dialog>

    @php
        $dv = fn(string $key, $fallback = '') => $deliveryValues[$key] ?? $fallback;
    @endphp
    <dialog class="delivery-dialog" id="deliveryDialog" aria-labelledby="deliveryDialogTitle"
        data-auto-open="{{ $deliveryFormOpen ? '1' : '0' }}">
        <form method="post" action="{{ route('shipments.delivery.save', $shipment->shipment_id) }}#delivery" id="deliveryForm"
            autocomplete="off" novalidate>
            @csrf
            <input type="hidden" name="_form" value="delivery">
            <input type="hidden" name="delivery_id" value="{{ $dv('delivery_id') }}">
            <header class="delivery-dialog-head">
                <div>
                    <h2 id="deliveryDialogTitle">Edit Delivery Details</h2>
                    <p>{{ $shipment->reference_no }} · {{ $shipment->client?->client_name }}</p>
                </div><button type="button" data-delivery-close aria-label="Close">&times;</button>
            </header>
            <div class="delivery-dialog-body">
                @if ($deliveryFormOpen)
                    <div class="delivery-form-error" role="alert">{{ $errors->first() }}</div>
                @endif
                <div class="delivery-form-grid">
                    <label>Trucking Company<input name="trucker" type="text" maxlength="100" value="{{ $dv('trucker') }}"
                            placeholder="Trucking company"></label>
                    <label>Driver Name<input name="driver_name" type="text" maxlength="100" value="{{ $dv('driver_name') }}"
                            placeholder="Driver name"></label>
                    <label>Plate No.<input name="plate_no" type="text" maxlength="20" value="{{ $dv('plate_no') }}"
                            placeholder="ABC 1234"></label>
                    <div class="delivery-schedule"><label>Pickup Schedule · Date<input name="scheduled_date" type="date"
                                value="{{ $dv('scheduled_date') }}"></label><label>Time<input name="scheduled_time" type="time"
                                value="{{ $dv('scheduled_time') }}"></label></div>
                    <label class="delivery-wide delivery-address">Delivery Address
                        <textarea name="delivery_address" rows="2" maxlength="255"
                            placeholder="House no., street, then type barangay or town (e.g. Santol, Mataasnakahoy)"
                            data-suggest-url="{{ route('locations.search') }}">{{ $dv('delivery_address') }}</textarea>
                    </label>
                    <label>Receiving Contact · Name<input name="contact_name" type="text" maxlength="100"
                            value="{{ $dv('contact_name') }}" placeholder="Contact name"></label>
                    <label>Receiving Contact · Phone<input name="contact_phone" type="tel" maxlength="30"
                            value="{{ $dv('contact_phone') }}" placeholder="Phone number"></label>
                    <fieldset class="delivery-status">
                        <legend>Delivery Status</legend>
                        @foreach ($truckStatus as $value => [$label, $color])
                            <label><input type="radio" name="status" value="{{ $value }}"
                                    @checked($dv('status', 'scheduled') === $value)><span>{{ $label }}</span></label>
                        @endforeach
                    </fieldset>
                    <label class="delivery-wide" id="deliveredDateField">Delivered Date<input name="delivered_date" type="date"
                            value="{{ $dv('delivered_date') }}"></label>
                </div>
            </div>
            <footer class="delivery-dialog-foot"><span id="deliverySavingNote">Admin and Staff access only</span>
                <div><button type="button" class="btn btn-secondary" data-delivery-close>Cancel</button><button type="submit"
                        class="btn btn-primary" id="deliverySave">Save</button></div>
            </footer>
        </form>
    </dialog>

    <dialog class="dlg cd-request-dialog" id="requestDocDialog" aria-labelledby="requestDocTitle">
        <form method="post" action="{{ route('documents.request', $shipment->shipment_id) }}">
            @csrf
            <header class="assign-detail-head">
                <div>
                    <h2 id="requestDocTitle">Request a document from the client</h2>
                    <p>{{ $shipment->reference_no }} · {{ $shipment->client?->client_name }}</p>
                </div><button type="button" data-close-request aria-label="Close">&times;</button>
            </header>
            <label>Document<select name="doc_type" id="requestDocType" required>
                    @foreach (app(\App\Services\ShipmentDocumentService::class)->catalog() as $slot)
                        <option value="{{ $slot['label'] }}">{{ $slot['label'] }}</option>
                    @endforeach
                </select></label>
            <label>Due date <span class="optional-mark">(optional)</span><input type="date" name="due_date"
                    min="{{ now()->toDateString() }}"></label>
            <label>Note to the client <span class="optional-mark">(optional)</span>
                <textarea name="note" rows="3" maxlength="255" placeholder="e.g. Please upload the signed copy"></textarea>
            </label>
            <p class="soft-text small">The client is notified and sees this on their dashboard until they upload it and you accept it.</p>
            <div class="dlg-actions"><button type="button" class="btn btn-secondary" data-close-request>Cancel</button><button
                    type="submit" class="btn btn-primary">Send request</button></div>
        </form>
    </dialog>

    <dialog class="audit-drawer" id="auditDialog" aria-labelledby="auditTitle" data-shipment-id="{{ $shipment->shipment_id }}"
        data-endpoint="{{ url('/shipment-management/shipment_audit.php') }}">
        <header class="audit-drawer-head">
            <div>
                <h2 id="auditTitle">Audit Trail, {{ $shipment->reference_no }}</h2>
                <p>{{ $role === 'admin' ? 'Admin activity log' : 'Shipment activity log' }} · Newest first</p>
            </div><button type="button" data-audit-close aria-label="Close audit trail">&times;</button>
        </header>
        <nav class="audit-filters" aria-label="Filter shipment activity" role="tablist">
            <button type="button" class="active" role="tab" aria-selected="true" data-audit-filter="all">All</button>
            <button type="button" role="tab" aria-selected="false" data-audit-filter="status">Status</button>
            <button type="button" role="tab" aria-selected="false" data-audit-filter="documents">Documents</button>
            <button type="button" role="tab" aria-selected="false" data-audit-filter="edits">Edits</button>
        </nav>
        <section class="audit-drawer-content" id="auditList" aria-live="polite">
            <p class="audit-loading">Loading shipment activity…</p>
        </section>
        <div class="audit-empty" id="auditEmpty" hidden><span aria-hidden="true">◷</span><strong>No activity yet.</strong>
            <p>Recorded shipment activity will appear here.</p>
        </div>
        <p class="audit-error" id="auditError" hidden role="alert"></p>
        <footer class="audit-drawer-foot"><button type="button" id="auditLoadMore" hidden>Load more</button></footer>
    </dialog>
</x-app-layout>
