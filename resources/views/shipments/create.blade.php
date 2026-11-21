{{--
    FILE PURPOSE
    File   : resources/views/shipments/create.blade.php
    Feature: Shipments
    Purpose: Add Shipment page.
--}}

<x-app-layout title="Add Shipment" active="shipments" legacy-dashboard :show-notifications="false">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/shipment_add.css') }}?v=9" rel="stylesheet">
        <link href="{{ asset('assets/css/client_documents.css') }}?v=13" rel="stylesheet">
    @endpush
    @push('scripts')
        @php(
    $shipmentAddRoutes = [
        'check' => route('shipments.add.check'),
        'extract' => route('shipments.add.extract'),
        'store' => route('shipments.add.store'),
        'list' => $role === 'admin' ? route('admin.shipments') : route('staff.shipments'),
        'show' => route('shipments.show', ['shipmentId' => '__shipment__'])
    ]
)
        <script>
            window.SHIPMENT_ADD_ROUTES = {{ Illuminate\Support\Js::from($shipmentAddRoutes) }};
            window.ASTRA_SHIPMENT_REQUEST =
                {{ Illuminate\Support\Js::from($prefill ? $prefill + ['back_url' => route('shipment-requests.index')] : null) }};
        </script>
        <script src="{{ asset('assets/js/shipment_add.js') }}?v=2" defer></script>
    @endpush
    <div class="stack add-shell">
        <div class="add-page-head">
            <div>
                <h2>Add Shipment</h2>
                <p id="pageSubtitle"></p>
            </div>
            <div class="add-page-meta"><span id="pageState" class="add-state">New shipment</span></div>
        </div>

        @if ($prefill)
            <div class="request-banner" role="note">
                <strong>Client request {{ $prefill['reference'] }}</strong>
                <span>{{ $prefill['client_name'] }} sent {{ $prefill['file_name'] }}. The client stays fixed; confirm the extracted values
                    before saving.</span>
                @if (filled($prefill['notes']))
                    <span class="request-banner-notes">Client notes: “{{ $prefill['notes'] }}”</span>
                @endif
                <a href="{{ route('shipment-requests.file', $prefill['id']) }}" target="_blank" rel="noopener">Open the client's PDF</a>
            </div>
        @endif

        <div class="add-steps" aria-label="Shipment creation progress">
            <span class="add-step-caption">NEW SHIPMENT</span>
            <span class="add-step active" id="stepOne"><b>1</b><strong>Choose how to start</strong></span>
            <span class="add-step-line"></span>
            <span class="add-step" id="stepTwo"><b>2</b><strong>Review and save</strong></span>
        </div>

        <section class="start-grid" id="startCard" aria-label="Choose how to create a shipment">
            <article class="start-card upload-start" id="uploadCard">
                <div class="start-card-head"><span class="start-icon">⇧</span>
                    <h3>Upload shipping documents <span>(auto-read)</span></h3>
                    <p>Start with a PDF packet. We’ll prepare a draft of cargo and invoice details for your review.</p>
                </div>
                <div class="drop-zone" id="dropZone" tabindex="0" role="button" aria-label="Choose a shipping PDF">
                    <span class="upload-symbol">⇧</span><strong>Drag and drop your shipping PDF here</strong><span>PDF only · Maximum 25 MB
                        per file</span>
                    <button type="button" class="btn-primary" id="browseBtn">Browse files</button>
                </div>
                <input type="file" id="pdfFile" accept="application/pdf,.pdf" hidden aria-label="Choose a PDF file">
                <div class="upload-state" id="uploadState" hidden>
                    <div class="file-summary"><span class="pdf-icon">PDF</span>
                        <div><strong id="fileName">Document.pdf</strong><small id="fileMeta">PDF · Ready to upload</small></div>
                    </div>
                    <p class="upload-feedback" id="uploadFeedback" role="status"></p>
                    <div class="progress-track" id="progressTrack" hidden><span id="uploadProgress"></span></div>
                    <p class="upload-state-note" id="uploadNote"></p>
                    <div class="upload-state-actions" id="uploadActions" hidden><button type="button" class="btn-primary"
                            id="manualAfterFailure">Continue manually</button><button type="button" class="btn-outline"
                            id="retryRead">Retry reading</button></div>
                    <button type="button" class="btn-outline cancel-upload" id="cancelUpload" hidden>Cancel upload</button>
                </div>
            </article>

            <article class="start-card manual-start">
                <div class="start-card-head"><span class="start-icon">▤</span>
                    <h3>Enter details manually</h3>
                    <p>Have the details ready? Fill in the shipment form yourself, with fields tailored to Sea, Air or Land.</p>
                </div>
                <ul class="start-points">
                    <li>Choose an existing client and transport mode</li>
                    <li>Add cargo, route and invoice details</li>
                    <li>Check your draft, then save once</li>
                </ul>
                <button type="button" class="btn-primary manual-start-button" id="manualBtn">Enter details manually</button>
            </article>
        </section>

        <div class="add-info-strip" id="startInfo"><strong>You stay in control.</strong> Auto-read produces a draft, not a saved shipment.
            Confirm all extracted values before saving.</div>

        <form id="shipForm" class="review-layout" hidden autocomplete="off" novalidate>
            <div class="review-main">
                <section class="review-card">
                    <div class="review-card-head">
                        <h3><span>01</span> Shipment Basics</h3><small>* Required fields</small>
                    </div>
                    <div class="review-grid three-col">
                        <label class="field">Client *<select id="f_client_id" name="client_id" required>
                                <option value="">Select an existing client</option>
                                @foreach ($clients as $client)
                                    <option value="{{ $client->client_id }}">{{ $client->client_name }}</option>
                                @endforeach
                            </select></label>
                        @if ($role === 'admin')
                            <label class="field">Assigned staff *<select id="f_assigned_staff" name="assigned_staff_id" required>
                                    <option value="">Select the responsible staff</option>
                                    @foreach ($staffList as $staffMember)
                                        <option value="{{ $staffMember->user_id }}">{{ $staffMember->full_name }}</option>
                                    @endforeach
                                </select></label>
                        @endif
                        <label class="field">Type *<select id="f_transaction_type" name="transaction_type" required>
                                <option value="">Select type</option>
                                <option value="import">Import</option>
                                <option value="export">Export</option>
                            </select></label>
                        <div class="field mode-choice"><span>Mode *</span>
                            <div class="mode-segments" role="group" aria-label="Transport mode"><button type="button"
                                    data-mode="sea" class="selected">✓ Sea</button><button type="button"
                                    data-mode="air">Air</button><button type="button" data-mode="land">Land</button></div><input
                                type="hidden" id="f_mode" name="mode" value="sea">
                        </div>
                        <label class="field field-span-three">Description / Remarks
                            <textarea id="f_remarks" name="remarks" rows="2" maxlength="2000" placeholder="Add a cargo description or shipment note"></textarea><small class="land-hint" id="landHint" hidden>For Land, the border or port of entry
                                is saved with these remarks.</small>
                        </label>
                    </div>
                    <p class="client-hint" id="clientHint" hidden></p>
                </section>

                <section class="review-card">
                    <div class="review-card-head">
                        <h3><span>02</span> Cargo and Route</h3><small id="routeCaption">Sea freight</small>
                    </div>
                    <input type="hidden" name="vessel_or_flight" id="f_vessel_or_flight">
                    <div class="review-grid three-col" data-mode-section="sea">
                        <label class="field">B/L No. *<input id="f_bl_awb_no" name="bl_awb_no" maxlength="50" autocomplete="off"
                                required><small class="inline-error" id="duplicateMessage" hidden></small></label>
                        <label class="field">Vessel<input id="seaVessel" maxlength="60" data-combined="sea-vessel"></label>
                        <label class="field">Voyage<input id="seaVoyage" maxlength="35" data-combined="sea-voyage"></label>
                        <label class="field field-span-three">Shipper<input id="f_shipper_name" name="shipper_name"
                                maxlength="150"></label>
                        <label class="field field-span-three">Consignee<input id="f_consignee_name" name="consignee_name"
                                maxlength="150"></label>
                        <label class="field field-span-three">Port of Loading<input id="f_port_of_loading" name="port_of_loading"
                                maxlength="100"></label>
                        <label class="field field-span-three">Port of Discharge<input id="f_port_of_discharge" name="port_of_discharge"
                                maxlength="100"></label>
                        <label class="field">HS Code<input id="f_hs_code" name="hs_code" maxlength="20"></label>
                        <label class="field">Packages<input id="f_total_packages" name="total_packages" inputmode="decimal"></label>
                        <label class="field">Gross Weight (kg)<input id="f_total_gross_weight" name="total_gross_weight"
                                inputmode="decimal"></label>
                        <label class="field">Measurement (CBM)<input id="f_measurement_cbm" name="measurement_cbm"
                                inputmode="decimal"></label>
                        <label class="field">Incoterm<input id="f_incoterm" name="incoterm" maxlength="10" placeholder="FOB"></label>
                    </div>
                    <div class="review-grid three-col" data-mode-section="air" hidden>
                        <label class="field">AWB No. *<input id="airBl" maxlength="50" data-mode-target="bl"></label>
                        <label class="field">Airline<input id="airline" maxlength="60" data-combined="airline"></label>
                        <label class="field">Flight No.<input id="flightNo" maxlength="35" data-combined="flight"></label>
                        <label class="field field-span-three">Shipper<input data-mode-copy="shipper_name" maxlength="150"></label>
                        <label class="field field-span-three">Consignee<input data-mode-copy="consignee_name" maxlength="150"></label>
                        <label class="field field-span-three">Airport of Departure<input data-mode-copy="port_of_loading"
                                maxlength="100"></label>
                        <label class="field field-span-three">Airport of Arrival<input data-mode-copy="port_of_discharge"
                                maxlength="100"></label>
                        <label class="field">HS Code<input data-mode-copy="hs_code" maxlength="20"></label>
                        <label class="field">Packages<input data-mode-copy="total_packages" inputmode="decimal"></label>
                        <label class="field">Gross Weight (kg)<input data-mode-copy="total_gross_weight" inputmode="decimal"></label>
                        <label class="field">Measurement (CBM)<input data-mode-copy="measurement_cbm" inputmode="decimal"></label>
                        <label class="field">Incoterm<input data-mode-copy="incoterm" maxlength="10" placeholder="FOB"></label>
                    </div>
                    <div class="review-grid three-col" data-mode-section="land" hidden>
                        <label class="field">Truck Plate *<input id="truckPlate" maxlength="100" data-combined="truck"></label>
                        <label class="field">Waybill No. · Optional<input id="landBl" maxlength="50" data-mode-target="bl"></label>
                        <label class="field">Border or Port of Entry<input id="borderEntry" maxlength="100"></label>
                        <label class="field field-span-three">Origin<input data-mode-copy="port_of_loading" maxlength="100"></label>
                        <label class="field field-span-three">Destination<input data-mode-copy="port_of_discharge"
                                maxlength="100"></label>
                        <label class="field field-span-three">Shipper<input data-mode-copy="shipper_name" maxlength="150"></label>
                        <label class="field field-span-three">Consignee<input data-mode-copy="consignee_name" maxlength="150"></label>
                        <label class="field">HS Code<input data-mode-copy="hs_code" maxlength="20"></label>
                        <label class="field">Packages<input data-mode-copy="total_packages" inputmode="decimal"></label>
                        <label class="field">Gross Weight (kg)<input data-mode-copy="total_gross_weight" inputmode="decimal"></label>
                        <label class="field">Measurement (CBM)<input data-mode-copy="measurement_cbm" inputmode="decimal"></label>
                    </div>
                    <section class="container-section" data-mode-section="sea">
                        <div class="subsection-head">
                            <div>
                                <h4>Containers</h4><small>Sea containers only · Confirm numbers against the source document.</small>
                            </div><button type="button" class="btn-outline" id="addContainer">+ Add row</button>
                        </div>
                        <div class="table-wrap">
                            <table id="containerTable">
                                <thead>
                                    <tr>
                                        <th>Container No.</th>
                                        <th>Seal No.</th>
                                        <th>Size / Type</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="containerBody"></tbody>
                            </table>
                        </div>
                    </section>
                </section>

                <section class="review-card">
                    <div class="review-card-head">
                        <h3><span>03</span> Invoice and Values</h3><small>Amounts remain in the selected currency</small>
                    </div>
                    <div class="review-grid three-col">
                        <label class="field">Invoice No.<input id="f_invoice_no" name="invoice_no" maxlength="50"></label>
                        <label class="field">Currency<input id="f_currency" name="currency" maxlength="3" placeholder="USD"></label>
                        <label class="field">Invoice Value<input id="f_invoice_value" name="invoice_value" inputmode="decimal"></label>
                        <label class="field">Freight<input id="f_freight_amount" name="freight_amount" inputmode="decimal"></label>
                        <label class="field">FOB Value<input id="f_fob_value" name="fob_value" inputmode="decimal"></label>
                    </div>
                </section>
                <section class="review-card extraction-card" id="extractionCard" hidden>
                    <div class="review-card-head">
                        <h3><span>04</span> Extraction notes</h3>
                    </div>
                    <ul class="checks" id="checkList"></ul>
                    <ul class="checks" id="warningList"></ul>
                </section>
            </div>

            <aside class="review-aside">
                <section class="checks-card">
                    <div class="checks-head">
                        <h3>Checks</h3><span id="checksBadge" class="checks-badge">Action needed</span>
                    </div>
                    <p class="checks-intro" id="checksIntro">Manual draft · Completeness and amount checks.</p>
                    <div id="checksDynamic" class="checks-dynamic" aria-live="polite"></div>
                    <label class="hs-confirm" id="hsConfirmWrap" hidden><input type="checkbox" id="hsConfirm"><span><strong>Confirm HS
                                classification</strong><small>Check this suggested code against the source document.</small></span></label>
                    <div class="source-document" id="sourceDocument" hidden><small>Source document</small><strong
                            id="sourceFileName"></strong><span id="sourceFileMeta"></span></div>
                </section>
            </aside>

            <footer class="review-footer">
                <p class="review-footer-status" id="saveStatus" role="status">Unsaved draft · Complete required details before saving
                </p>
                <div><button type="button" class="btn-outline" id="cancelBtn">Cancel</button><button type="submit"
                        class="btn-primary" id="saveBtn" disabled>Save Shipment</button></div>
            </footer>
        </form>

        <section class="success-card" id="doneCard" hidden>
            <div class="success-check">✓</div>
            <h2>Shipment added successfully.</h2>
            <p>Your reviewed draft is now shipment <strong id="doneRef"></strong>.</p>
            <div class="success-summary">
                <div><small>Client</small><strong id="doneClient"></strong></div>
                <div><small>Type / Mode</small><strong id="doneMode"></strong></div>
                <div><small>B/L or AWB</small><strong id="doneBl"></strong></div>
            </div>
            <div class="success-stage">
                <div><small>Initial stage</small><strong>Documents Received</strong></div>
                <div><small>Overview status</small><strong class="success-status">Pending Documents</strong></div>
            </div>
            <p class="success-note">The document packet is attached when provided. Customs filing, assessment and clearance are separate
                steps; saving does not complete them.</p>
            <div class="success-actions"><a class="btn-outline"
                    href="{{ $role === 'admin' ? route('admin.shipments') : route('staff.shipments') }}">Back to Shipments</a><a
                    class="btn-primary" href="#" id="doneOpen">View Shipment</a></div>
        </section>

        <div class="start-footer" id="startFooter"><span id="pageMessage">No shipment saved · Choose a method to begin</span><a
                class="btn-outline" href="{{ $role === 'admin' ? route('admin.shipments') : route('staff.shipments') }}">Back to
                Shipments</a></div>
    </div>
</x-app-layout>
