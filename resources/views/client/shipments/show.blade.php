{{--
    FILE PURPOSE
    File   : resources/views/client/shipments/show.blade.php
    Feature: Client portal
    Purpose: Client shipment details page (read-only).
--}}

@php
    $typeMode = ucfirst((string) $shipment->transaction_type).' · '.ucfirst((string) $shipment->mode);
    $movementLabel = $shipment->mode === 'land' ? 'Truck Plate' : ($shipment->mode === 'air' ? 'Airline / Flight' : 'Vessel / Voyage');
    $route = (filled($shipment->port_of_loading) || filled($shipment->port_of_discharge))
        ? ($shipment->port_of_loading ?: '—').' → '.($shipment->port_of_discharge ?: '—') : '—';
    $containers = $shipment->containers;
    $truckStatus = ['scheduled' => ['Scheduled', 'amber'], 'in_transit' => ['In Transit', 'teal'], 'delivered' => ['Delivered', 'green'], 'cancelled' => ['Cancelled', 'red']];
    $schedule = $delivery?->scheduled_date
        ? $delivery->scheduled_date->format('M d, Y').($delivery->scheduled_time ? ' · '.\Illuminate\Support\Carbon::parse((string) $delivery->scheduled_time)->format('g:i A') : '')
        : '—';
    $driverVehicle = $delivery ? (collect([trim((string) $delivery->driver_name), trim((string) $delivery->plate_no)])->filter()->implode(' · ') ?: 'To be assigned') : 'To be assigned';
    $billingNo = $bills->count() === 1 ? $bills->first()->billing_no : $bills->count().' invoices';
    $assessmentColor = $customs ? (['pending' => 'amber', 'assessed' => 'blue', 'paid' => 'green'][$customs->assessment_status] ?? 'blue') : 'blue';
    $customsSummary = $customs
        ? ucfirst((string) $customs->assessment_status).' assessment · '.($customs->examination_lane ? ucfirst((string) $customs->examination_lane).' lane' : 'Lane not assigned').' · '.($customs->clearance_date ? $customs->clearance_date->format('M d, Y') : 'Awaiting clearance')
        : 'Status not available yet';
    $deliverySummary = $delivery
        ? ($delivery->trucker ?: '—').' · '.($truckStatus[$delivery->status][0] ?? 'Scheduled').' · '.$schedule
        : 'Not yet scheduled';
    $fmt = fn ($date) => $date ? \Illuminate\Support\Carbon::parse($date)->format('M d, Y') : '—';
@endphp
<x-app-layout :title="$shipment->reference_no.' | Shipment Details'" active="shipments" :breadcrumbs="[['Dashboard', route('client.dashboard')], ['My Shipments', route('client.shipments')], ['Shipment Details', null]]">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/clint_shipment.css') }}?v=9" rel="stylesheet">
        <link href="{{ asset('assets/css/shipment_details.css') }}?v=9" rel="stylesheet">
        <link href="{{ asset('assets/css/shipment_status.css') }}?v=9" rel="stylesheet">
        <link href="{{ asset('assets/css/client_documents.css') }}?v=13" rel="stylesheet">
        <link href="{{ asset('assets/css/client_motion.css') }}?v=1" rel="stylesheet">
    @endpush
    @push('scripts')
        <script src="{{ asset('assets/js/clint_shipment.js') }}?v=2" defer></script>
        <script src="{{ asset('assets/js/shipment_details.js') }}?v=2" defer></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
        <script src="{{ asset('assets/js/client_documents.js') }}" defer></script>
        <script src="{{ asset('assets/js/client_motion.js') }}?v=1" defer></script>
    @endpush

    <div class="page shipment-details-page client-shipment-details">
        <div class="page-head">
            <div>
                <h1>Shipment Details</h1>
                <p class="ref-row"><span class="soft-text">Shipment Reference:</span><strong>{{ $shipment->reference_no }}</strong><span class="badge badge-{{ $statusClass }}">{{ $statusLabel }}</span></p>
            </div>
            <div class="head-buttons">
                <a class="btn btn-secondary" href="{{ route('client.shipments') }}">← Back to My Shipments</a>
                @if ($acceptedCount > 0)
                    <a class="btn btn-secondary" href="{{ route('shipments.documents.download-all', $shipment->shipment_id) }}">Download Documents</a>
                @else
                    <span class="btn btn-secondary is-disabled" aria-disabled="true" title="No documents available yet">Download Documents</span>
                @endif
            </div>
        </div>

        @if (session('success'))<p class="flash" role="status">{{ session('success') }}</p>@endif

        <section class="fcard stepper-card" aria-label="Shipment stages">
            <ol class="stepper">
                @foreach (array_filter($progress['items'], fn (array $item): bool => $item['kind'] === 'stage') as $item)
                    <li class="step step-{{ $item['state'] }}" style="--i: {{ $loop->index }}"><span class="step-dot">@if ($item['state'] === 'done')<svg class="cm-check" viewBox="0 0 16 16" aria-hidden="true"><path d="m4 8.4 2.6 2.6L12 5.4" pathLength="1"/></svg>@endif</span><span class="step-text"><span class="step-label">{{ $item['label'] }}</span><span class="step-date">{{ $item['short'] }}</span></span></li>
                @endforeach
            </ol>
        </section>

        <div class="cols">
            <div class="col-left">
                <section class="fcard collapse-card is-expanded">
                    <div class="collapse-head"><div class="collapse-head-text"><h2 class="card-title">Shipment Information</h2><p>{{ $shipment->reference_no }} · {{ auth()->user()->full_name }} · {{ $shipment->bl_awb_no ?: '—' }}</p></div><button class="collapse-toggle" type="button" data-collapse aria-expanded="true" aria-controls="clientShipmentInfoMore">Show less</button></div>
                    <div class="collapse-body" id="clientShipmentInfoMore">
                        <div class="field-row"><div class="dfield"><div class="lbl">Shipment Reference</div><div class="val">{{ $shipment->reference_no }}</div></div><div class="dfield"><div class="lbl">Shipment Date</div><div class="val">{{ $fmt($shipment->created_at) }}</div></div><div class="dfield"><div class="lbl">Current Shipment Status</div><div class="val"><span class="badge badge-{{ $statusClass }}">{{ $statusLabel }}</span></div></div></div>
                        <div class="field-row"><div class="dfield"><div class="lbl">Type · Mode</div><div class="val">{{ $typeMode }}</div></div><div class="dfield"><div class="lbl">B/L Number</div><div class="val">{{ $shipment->bl_awb_no ?: '—' }}</div></div><div class="dfield"><div class="lbl">Actual Arrival</div><div class="val">{{ $fmt($shipment->actual_arrival_date) }}</div></div></div>
                        <div class="dfield"><div class="lbl">Last Updated</div><div class="val">{{ $fmt($lastUpdated) }}</div></div>
                        <div class="dfield"><div class="lbl">Route</div><div class="val">{{ $route }}</div></div>
                    </div>
                </section>

                <section class="fcard collapse-card is-expanded" id="key-dates">
                    <div class="collapse-head"><div class="collapse-head-text"><h2 class="card-title">Key Dates</h2><p>{{ $shipment->eta_date ? 'ETA '.$fmt($shipment->eta_date) : 'No arrival or delivery dates set' }}</p></div><button class="collapse-toggle" type="button" data-collapse aria-expanded="true" aria-controls="clientKeyDatesMore">Show less</button></div>
                    <div class="collapse-body" id="clientKeyDatesMore"><div class="field-row">
                        <div class="dfield"><div class="lbl">Estimated Arrival (ETA)</div><div class="val">{{ $fmt($shipment->eta_date) }}</div></div>
                        <div class="dfield"><div class="lbl">First Free Day</div><div class="val">{{ $fmt($shipment->first_free_day) }}</div></div>
                        <div class="dfield"><div class="lbl">Delivery Due Date</div><div class="val">{{ $fmt($shipment->delivery_due_date) }}</div></div>
                        <div class="dfield"><div class="lbl">Actual Arrival</div><div class="val">{{ $fmt($shipment->actual_arrival_date) }}</div></div>
                    </div></div>
                </section>

                <section class="fcard collapse-card is-expanded">
                    <div class="collapse-head"><div class="collapse-head-text"><h2 class="card-title">Cargo and Route</h2><p>{{ $typeMode }} · {{ $route }}</p></div><button class="collapse-toggle" type="button" data-collapse aria-expanded="true" aria-controls="clientCargoMore">Show less</button></div>
                    <div class="collapse-body" id="clientCargoMore">
                        <div class="field-row"><div class="dfield"><div class="lbl">Shipper</div><div class="val">{{ $shipment->shipper_name ?: '—' }}</div></div><div class="dfield"><div class="lbl">Consignee</div><div class="val">{{ $shipment->consignee_name ?: '—' }}</div></div><div class="dfield"><div class="lbl">{{ $movementLabel }}</div><div class="val">{{ $shipment->vessel_or_flight ?: '—' }}</div></div></div>
                        <div class="field-row"><div class="dfield"><div class="lbl">HS Code</div><div class="val">{{ $shipment->hs_code ?: '—' }}</div></div><div class="dfield"><div class="lbl">Packages</div><div class="val">{{ $shipment->total_packages ?? '—' }}</div></div><div class="dfield"><div class="lbl">Gross Weight · CBM</div><div class="val">{{ $shipment->total_gross_weight ?? '—' }} kg · {{ $shipment->measurement_cbm ?? '—' }} CBM</div></div></div>
                        <div class="field-row"><div class="dfield"><div class="lbl">Incoterm</div><div class="val">{{ $shipment->incoterm ?: '—' }}</div></div><div class="dfield"><div class="lbl">Invoice No.</div><div class="val">{{ $shipment->invoice_no ?: '—' }}</div></div><div class="dfield"><div class="lbl">Currency</div><div class="val">{{ $shipment->currency ?: '—' }}</div></div></div>
                        <div class="field-row field-row-3"><div class="dfield"><div class="lbl">Invoice Value</div><div class="val">{{ $shipment->invoice_value ?? '—' }}</div></div><div class="dfield"><div class="lbl">Freight Amount</div><div class="val">{{ $shipment->freight_amount ?? '—' }}</div></div><div class="dfield"><div class="lbl">FOB Value</div><div class="val">{{ $shipment->fob_value ?? '—' }}</div></div></div>
                    </div>
                </section>

                <section class="fcard collapse-card is-expanded">
                    <div class="collapse-head"><div class="collapse-head-text"><h2 class="card-title">Containers</h2><p>{{ $containers->count() }} {{ $containers->count() === 1 ? 'container' : 'containers' }}@if ($containers->isNotEmpty()) · {{ $containers->first()->size_type ?: '—' }} · {{ $containers->first()->container_no ?: '—' }}@endif</p></div><button class="collapse-toggle" type="button" data-collapse aria-expanded="true" aria-controls="clientContainersMore">Show less</button></div>
                    <div class="collapse-body" id="clientContainersMore">
                        @if ($containers->isNotEmpty())
                            <div class="table-wrap"><table class="ship-table"><thead><tr><th>Container No.</th><th>Seal No.</th><th>Size / Type</th></tr></thead><tbody>
                                @foreach ($containers as $container)<tr><td>{{ $container->container_no }}</td><td>{{ $container->seal_no ?: '—' }}</td><td>{{ $container->size_type ?: '—' }}</td></tr>@endforeach
                            </tbody></table></div>
                        @else
                            <div class="empty-containers"><span aria-hidden="true">□</span><span>No containers for this {{ strtolower((string) $shipment->mode) }} shipment.</span></div>
                        @endif
                    </div>
                </section>

                <section class="fcard collapse-card is-expanded">
                    <div class="collapse-head"><div class="collapse-head-text"><h2 class="card-title">Customs Status</h2><p>{{ $customsSummary }}</p></div><button class="collapse-toggle" type="button" data-collapse aria-expanded="true" aria-controls="clientCustomsMore">Show less</button></div>
                    <div class="collapse-body" id="clientCustomsMore">
                        @if ($customs)
                            <div class="field-row field-row-3">
                                <div class="dfield"><div class="lbl">Assessment Status</div><div class="val"><span class="badge badge-{{ $assessmentColor }}">{{ ucfirst((string) $customs->assessment_status) }}</span></div></div>
                                <div class="dfield"><div class="lbl">Examination Lane</div><div class="val">{{ $customs->examination_lane ? ucfirst((string) $customs->examination_lane) : 'Not assigned' }}</div></div>
                                <div class="dfield"><div class="lbl">Clearance Date</div><div class="val">{{ $fmt($customs->clearance_date) }}</div></div>
                            </div>
                            
                        @else
                            
                        @endif
                    </div>
                </section>

                <section class="fcard collapse-card is-expanded" id="documents">
                    <div class="collapse-head"><div class="collapse-head-text"><h2 class="card-title">Documents</h2><p>{{ $acceptedCount }} accepted · {{ $documentChecklist['requiredReceived'] }}/{{ $documentChecklist['requiredTotal'] }} required received @if ($documentRequests->isNotEmpty())<span class="cd-pill cd-pill-request">{{ $documentRequests->count() }} requested from you</span>@endif</p></div><div class="collapse-actions"><button class="collapse-toggle" type="button" data-collapse aria-expanded="true" aria-controls="clientDocumentsMore">Show less</button>@if ($canUpload)<button type="button" class="link" data-client-upload="">Upload document</button>@endif</div></div>
                    <div class="collapse-body" id="clientDocumentsMore">
                        @if ($documentRequests->isNotEmpty())
                            <div class="cd-requests" role="note">
                                <strong>Action needed</strong>
                                <ul>@foreach ($documentRequests as $documentRequest)<li class="{{ $documentRequest->isOverdue() ? 'is-overdue' : '' }}"><span><b>{{ $documentRequest->doc_type }}</b>{{ $documentRequest->due_date ? ' · due '.$documentRequest->due_date->format('M d, Y') : '' }}{{ $documentRequest->isOverdue() ? ' · overdue' : '' }}@if ($documentRequest->note)<small>“{{ $documentRequest->note }}”</small>@endif @if ($documentRequest->fulfilled_document_id)<small>Uploaded · waiting for review</small>@endif</span>@if ($canUpload && ! $documentRequest->fulfilled_document_id)<button type="button" class="btn-sm cd-accept" data-client-upload="{{ $documentRequest->doc_type }}">Upload</button>@endif</li>@endforeach</ul>
                            </div>
                        @endif
                        <div class="document-checklist" aria-label="Required document checklist">
                            @foreach ($documentChecklist['slots'] as $slot)<div class="document-check-row {{ $slot['received'] ? 'is-received' : 'is-missing' }}"><span class="document-check-icon" aria-hidden="true">{{ $slot['received'] ? '✓' : '•' }}</span><span>{{ $slot['label'] }}@if (! $slot['required']) <small>(optional)</small>@endif @if ($slot['pending'])<small class="cd-pill cd-pill-pending">waiting for review</small>@endif</span><strong>{{ $slot['received'] ? ($slot['count'] > 1 ? $slot['count'].' files' : 'Received') : ($slot['pending'] ? 'Under review' : ($slot['required'] ? 'Missing' : 'Not provided')) }}</strong>@if ($canUpload && ! $slot['received'] && ! $slot['pending'])<button type="button" class="link" data-client-upload="{{ $slot['label'] }}">Upload</button>@endif</div>@endforeach
                        </div>
                        @if ($documents->isNotEmpty())
                            <ul class="shipment-doc-list">
                                @foreach ($documents as $document)
                                    @php($status = $document->review_status ?? 'accepted')
                                    @php($mine = ($document->source ?? 'staff') === 'client')
                                    <li class="{{ $status !== 'accepted' ? 'cd-row-'.$status : '' }}">
                                        <strong>{{ $document->doc_type }}</strong>
                                        <span>{{ $document->original_name ?: basename((string) $document->file_path) }}
                                            @if ($mine)<small class="cd-status cd-status-{{ $status }}">{{ ['pending' => 'Waiting for review', 'accepted' => 'Accepted', 'rejected' => 'Rejected'][$status] ?? ucfirst($status) }}</small>@endif
                                            @if ($status === 'rejected' && $document->review_note)<small class="cd-ai-warn">Reason: {{ $document->review_note }}</small>@endif
                                            @if ($mine && $status !== 'rejected')@include('partials.document-ai-check', ['document' => $document, 'audience' => 'client'])@endif
                                        </span>
                                        <span class="document-actions">
                                            <a class="btn-sm" href="{{ route('shipments.documents.download', $document->document_id) }}">Download</a>
                                            @if ($mine && $canUpload && $status !== 'accepted')<button type="button" class="btn-sm" data-client-upload="{{ $document->doc_type }}" data-replaces="{{ $document->document_id }}">{{ $status === 'rejected' ? 'Upload again' : 'Replace' }}</button>@endif
                                            @if ($mine && $status === 'pending')<form method="post" action="{{ route('client.documents.withdraw', $document->document_id) }}" class="cd-inline-form" onsubmit="return confirm('Withdraw this upload? It has not been reviewed yet.')">@csrf @method('DELETE')<button type="submit" class="link cd-link-muted">Withdraw</button></form>@endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="soft-text">No documents are available yet.</p>
                        @endif
                        <p class="soft-text small">{{ $canUpload ? 'Your uploads are reviewed by our team before they count toward the checklist. PDF, JPG, PNG, XLS or XLSX · up to 10 MB.' : 'This shipment is closed, so documents can no longer be uploaded.' }}</p>
                    </div>
                </section>

                <section class="fcard collapse-card is-expanded">
                    <div class="collapse-head"><div class="collapse-head-text"><h2 class="card-title">Delivery Information</h2><p>{{ $deliverySummary }}</p></div><button class="collapse-toggle" type="button" data-collapse aria-expanded="true" aria-controls="clientDeliveryMore">Show less</button></div>
                    <div class="collapse-body" id="clientDeliveryMore">
                        <div class="field-row">
                            <div class="dfield"><div class="lbl">Trucking Company</div><div class="val">{{ $delivery?->trucker ?: '—' }}</div></div>
                            <div class="dfield"><div class="lbl">Pickup Schedule</div><div class="val">{{ $schedule }}</div></div>
                            <div class="dfield"><div class="lbl">Trucking Status</div><div class="val">@if ($delivery && isset($truckStatus[$delivery->status]))<span class="badge badge-{{ $truckStatus[$delivery->status][1] }}">{{ $truckStatus[$delivery->status][0] }}</span>@else — @endif</div></div>
                        </div>
                        <div class="dfield"><div class="lbl">Delivery Address</div><div class="val">{{ $delivery?->delivery_address ?: '—' }}</div></div>
                        <div class="field-row">
                            <div class="dfield"><div class="lbl">Driver / Vehicle</div><div class="val">{{ $driverVehicle }}</div></div>
                            <div class="dfield"><div class="lbl">Receiving Contact</div><div class="val">{{ $delivery?->receiving_contact ?: '—' }}</div></div>
                            <div class="dfield"><div class="lbl">Delivery Date</div><div class="val">{{ $delivery?->delivered_date ? $fmt($delivery->delivered_date) : 'To be confirmed' }}</div></div>
                        </div>
                    </div>
                </section>
            </div>

            <aside class="col-right">
                <section class="fcard progress">
                    <div class="card-head"><h2 class="card-title">Shipment Progress</h2></div>
                    <ol class="timeline">@foreach ($progress['items'] as $item)<li class="tl-item tl-{{ $item['state'] }}"><div class="tl-indicator"><span class="tl-dot">@if ($item['state'] === 'done')✓@endif</span><span class="tl-line"></span></div><div class="tl-text"><div class="tl-label"><span>{{ $item['label'] }}</span>@if ($item['state'] === 'current' || $item['kind'] === 'exception')<span class="badge badge-{{ $item['kind'] === 'exception' ? $item['colour'] : 'blue' }}">Current</span>@endif</div><div class="tl-date">{{ $item['date'] }}</div>@if ($item['note'] !== '')<p class="tl-note">{{ $item['note'] }}</p>@endif</div></li>@endforeach</ol>
                </section>

                <section class="fcard">
                    <div class="card-head"><h2 class="card-title">Billing Summary</h2>@if ($bills->isNotEmpty())<span class="soft-text small">{{ $billingNo }}</span>@endif</div>
                    @if ($bills->isNotEmpty())
                        <div class="field-row field-row-3">
                            <div class="dfield"><div class="lbl">Amount Due</div><div class="val">₱{{ number_format($totalDue, 2) }}</div></div>
                            <div class="dfield"><div class="lbl">Amount Paid</div><div class="val">₱{{ number_format($totalPaid, 2) }}</div></div>
                            <div class="dfield"><div class="lbl">Payment Status</div><div class="val"><span class="badge badge-{{ $paymentStatus[1] }}">{{ $paymentStatus[0] }}</span></div></div>
                        </div>
                    @else
                        <p class="soft-text">No billing has been issued for this shipment yet.</p>
                    @endif
                </section>
            </aside>
        </div>
    </div>

    @if ($canUpload)
    <dialog class="dlg cd-upload-dialog" id="clientUploadDialog" aria-labelledby="clientUploadTitle" data-endpoint="{{ route('client.shipments.documents.upload', $shipment->shipment_id) }}">
        <form id="clientUploadForm" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="replaces_document_id" id="cuReplaces">
            <header class="assign-detail-head"><div><h2 id="clientUploadTitle">Upload a document</h2><p>{{ $shipment->reference_no }} · reviewed by our team before it counts</p></div><button type="button" data-cu-close aria-label="Close">&times;</button></header>
            <label>Document type<select name="doc_type" id="cuType" required>@foreach ($documentCatalog as $slot)<option value="{{ $slot['label'] }}">{{ $slot['label'] }}</option>@endforeach</select></label>
            <div class="cd-file-pick">
                <label class="cd-drop" id="cuDrop"><input type="file" id="cuFile" accept=".pdf,.jpg,.jpeg,.png,.xls,.xlsx"><strong>Choose a file or drag it here</strong><span>PDF, JPG, PNG, XLS, XLSX · up to 10 MB</span></label>
                <label class="btn btn-secondary cd-camera">Take photos<input type="file" id="cuCamera" accept="image/*" capture="environment" multiple></label>
            </div>
            <div class="cd-photos" id="cuPhotos" hidden><p><strong id="cuPhotoCount">0 photos</strong> · they will be saved as one PDF</p><div class="cd-thumbs" id="cuThumbs"></div><button type="button" class="link" id="cuPhotosClear">Clear photos</button></div>
            <p class="cd-chosen" id="cuChosen" hidden></p>
            <label>Note for our team <span class="optional-mark">(optional)</span><input type="text" name="remarks" maxlength="255" placeholder="e.g. Signed copy"></label>
            <p class="cd-message" id="cuMessage" role="status" hidden></p>
            <div class="cd-progress" id="cuProgress" hidden><span id="cuProgressBar"></span></div>
            <div class="dlg-actions"><button type="button" class="btn btn-secondary" data-cu-close>Cancel</button><button type="submit" class="btn btn-primary" id="cuSubmit" disabled>Upload</button></div>
        </form>
    </dialog>
    @endif
</x-app-layout>
