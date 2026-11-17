<x-app-layout :title="'Edit '.$shipment->reference_no" active="shipments">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/shipments.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/shipment_edit.css') }}?v=9" rel="stylesheet">
    @endpush
    @push('scripts')
        <script src="{{ asset('assets/js/shipment_edit.js') }}" defer></script>
    @endpush

    @php
        $fieldValue = fn (string $field, mixed $default = '') => old($field, $default);
        $dateValue = fn (string $field) => old($field, $shipment->{$field}?->format('Y-m-d') ?? '');
        $disabledAttribute = $isReadOnly ? 'disabled' : '';
        $statusClass = $shipment->stage?->stage_group === 'Delivered' ? 'green' : ($shipment->stage?->stage_group === 'Cancelled' ? 'red' : 'blue');
    @endphp

    <div class="page edit-page" data-closed="{{ $canEdit ? '0' : '1' }}" data-reference="{{ $shipment->reference_no }}">
        <header class="page-head edit-head">
            <div>
                <h1>Edit Shipment</h1>
                <p class="ref-row"><strong>{{ $shipment->reference_no }}</strong><span>{{ $shipment->client?->client_name }}</span><span class="badge badge-{{ $statusClass }}">{{ $shipment->current_status }}</span></p>
            </div>
            <div class="edit-head-meta"><span>Assigned to {{ $shipment->assignedStaff?->full_name ?? 'Unassigned' }}</span><small>Shipment metadata · Status is managed separately</small></div>
        </header>

        @if (session('success'))<div class="edit-success" role="status">{{ session('success') }}</div>@endif
        @if (session('form_error'))<div class="edit-banner" role="alert"><strong>Unable to save shipment.</strong> {{ session('form_error') }}</div>@endif
        @if ($errors->any())<div class="edit-banner" role="alert"><strong>Please correct the highlighted fields.</strong> {{ $errors->first() }}</div>@endif

        @if ($isAssignedToOther)
            <section class="fcard edit-restricted">
                <span class="restricted-icon"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6l8-3z"/><path d="M9 10a3 3 0 116 0v2H9zM8 12h8v5H8z"/></svg></span>
                <span class="eyebrow">Access restricted</span><h2>You can’t edit this shipment</h2>
                <p>This shipment is assigned to {{ $shipment->assignedStaff?->full_name ?? 'another staff member' }}. Staff may edit only their assigned shipments or unassigned shipments.</p>
                <div class="restricted-ref"><strong>{{ $shipment->reference_no }}</strong><span>Assigned to {{ $shipment->assignedStaff?->full_name ?? 'another staff member' }}</span></div>
                <a class="btn btn-primary" href="{{ \App\Support\ShipmentListUrl::for(auth()->user()) }}">Back to Shipments</a><small>If this assignment is incorrect, contact an administrator.</small>
            </section>
        @else
            @if ($isArchived)
                <div class="closed-banner"><span>◈</span><div><strong>This shipment is archived</strong><small>Archived shipment fields are read-only.</small></div>@if (auth()->user()->role === 'admin')<span class="soft-text">Archived records cannot be changed</span>@endif</div>
            @elseif ($isClosed)
                <div class="closed-banner"><span>◈</span><div><strong>This shipment is {{ $shipment->current_status }}</strong><small>{{ auth()->user()->role === 'admin' ? 'Administrator metadata edits are allowed. Shipment status stays unchanged and edits are recorded in the audit trail.' : 'This shipment is closed. Ask an administrator to edit its metadata.' }}</small></div>@if (auth()->user()->role === 'admin')<span class="soft-text">Status remains {{ $shipment->current_status }}</span>@endif</div>
            @endif

            <form id="shipmentEditForm" method="post" action="{{ route('shipments.update', $shipment->shipment_id) }}" novalidate>
                @csrf
                @method('PUT')
                <section class="fcard edit-card">
                    <div class="edit-card-head"><span class="section-number">01</span><h2>Shipment Basics</h2><small>{{ $isReadOnly ? 'Read-only' : '* Required fields' }}</small></div>
                    <div class="edit-grid">
                        <label class="edit-field @error('client_id') has-error @enderror" data-field="client_id" for="edit_client_id"><span>Client *</span><select id="edit_client_id" name="client_id" required {{ $disabledAttribute }}><option value="">Select a client</option>@foreach ($clients as $client)<option value="{{ $client->client_id }}" @selected((string) $fieldValue('client_id', $shipment->client_id) === (string) $client->client_id)>{{ $client->client_name }}{{ $client->status === 'active' ? '' : ' (inactive)' }}</option>@endforeach</select><small class="edit-error">@error('client_id'){{ $message }}@enderror</small></label>
                        <label class="edit-field @error('transaction_type') has-error @enderror" data-field="transaction_type" for="edit_transaction_type"><span>Type * · Import / Export</span><select id="edit_transaction_type" name="transaction_type" required {{ $disabledAttribute }}><option value="">Select…</option><option value="import" @selected($fieldValue('transaction_type', $shipment->transaction_type) === 'import')>Import</option><option value="export" @selected($fieldValue('transaction_type', $shipment->transaction_type) === 'export')>Export</option></select><small class="edit-error">@error('transaction_type'){{ $message }}@enderror</small></label>
                        <label class="edit-field @error('mode') has-error @enderror" data-field="mode" for="edit_mode"><span>Mode * · Sea / Air / Land</span><select id="edit_mode" name="mode" required {{ $disabledAttribute }}><option value="sea" @selected($fieldValue('mode', $shipment->mode) === 'sea')>Sea</option><option value="air" @selected($fieldValue('mode', $shipment->mode) === 'air')>Air</option><option value="land" @selected($fieldValue('mode', $shipment->mode) === 'land')>Land</option></select><small class="edit-error">@error('mode'){{ $message }}@enderror</small></label>
                        <label class="edit-field edit-wide @error('remarks') has-error @enderror" data-field="remarks" for="edit_remarks"><span>Description / Remarks</span><textarea id="edit_remarks" name="remarks" maxlength="5000" {{ $disabledAttribute }}>{{ $fieldValue('remarks', $shipment->remarks) }}</textarea><small class="edit-error">@error('remarks'){{ $message }}@enderror</small></label>
                    </div>
                </section>

                <section class="fcard edit-card">
                    <div class="edit-card-head"><span class="section-number">02</span><h2>Cargo and Route</h2><small data-mode-label>{{ ucfirst((string) $fieldValue('mode', $shipment->mode)) }} freight</small></div>
                    <div class="edit-grid">
                        @foreach ([['bl_awb_no', 'B/L or AWB No.', 'text', 'maxlength="50" data-bl'], ['total_packages', 'Packages', 'number', 'min="0" max="2147483647" step="1"'], ['total_gross_weight', 'Gross Weight (kg)', 'number', 'min="0" step="0.001"'], ['shipper_name', 'Shipper', 'text', 'maxlength="150"'], ['consignee_name', 'Consignee', 'text', 'maxlength="150"'], ['vessel_or_flight', 'Vessel or Flight', 'text', 'maxlength="100"'], ['port_of_loading', 'Port of Loading', 'text', 'maxlength="100"'], ['port_of_discharge', 'Port of Discharge', 'text', 'maxlength="100"'], ['hs_code', 'HS Code', 'text', 'maxlength="20"'], ['measurement_cbm', 'Measurement (CBM)', 'number', 'min="0" step="0.001"'], ['incoterm', 'Incoterm', 'text', 'maxlength="10"']] as [$name, $label, $type, $attributes])
                            <label class="edit-field @error($name) has-error @enderror" data-field="{{ $name }}" for="edit_{{ $name }}"><span>{{ $label }}</span><input id="edit_{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $fieldValue($name, $shipment->{$name}) }}" {!! $attributes !!} {{ $disabledAttribute }}><small class="edit-error">@error($name){{ $message }}@enderror</small></label>
                        @endforeach
                        <p class="field-help">Keep identifiers consistent with the shipment’s supporting documents.</p>
                    </div>
                </section>

                <section class="fcard edit-card">
                    <div class="edit-card-head"><span class="section-number">03</span><h2>Invoice and Values</h2><small>All monetary values in {{ $fieldValue('currency', $shipment->currency) }}</small></div>
                    <div class="edit-grid">
                        <label class="edit-field @error('invoice_no') has-error @enderror" data-field="invoice_no" for="edit_invoice_no"><span>Invoice No.</span><input id="edit_invoice_no" name="invoice_no" maxlength="50" value="{{ $fieldValue('invoice_no', $shipment->invoice_no) }}" {{ $disabledAttribute }}><small class="edit-error">@error('invoice_no'){{ $message }}@enderror</small></label>
                        <label class="edit-field @error('currency') has-error @enderror" data-field="currency" for="edit_currency"><span>Currency</span><select id="edit_currency" name="currency" {{ $disabledAttribute }}>@foreach ($currencyOptions as $currency)<option value="{{ $currency }}" @selected($fieldValue('currency', $shipment->currency) === $currency)>{{ $currency }}</option>@endforeach</select><small class="edit-error">@error('currency'){{ $message }}@enderror</small></label>
                        @foreach ([['invoice_value', 'Invoice Value'], ['freight_amount', 'Freight'], ['fob_value', 'FOB Value']] as [$name, $label])
                            <label class="edit-field @error($name) has-error @enderror" data-field="{{ $name }}" for="edit_{{ $name }}"><span>{{ $label }}</span><input id="edit_{{ $name }}" name="{{ $name }}" type="number" min="0" step="0.01" value="{{ $fieldValue($name, $shipment->{$name}) }}" {{ $disabledAttribute }}><small class="edit-error">@error($name){{ $message }}@enderror</small></label>
                        @endforeach
                        <p class="field-help">Amounts follow the selected currency. No currency conversion is applied.</p>
                    </div>
                </section>

                <section class="fcard edit-card">
                    <div class="edit-card-head"><span class="section-number">04</span><h2>Key Dates</h2><small>Leave dates blank until confirmed.</small></div>
                    <div class="edit-grid">
                        @foreach ([['eta_date', 'Estimated Arrival'], ['first_free_day', 'First Free Day'], ['delivery_due_date', 'Delivery Due Date']] as [$name, $label])
                            <label class="edit-field @error($name) has-error @enderror" data-field="{{ $name }}" for="edit_{{ $name }}"><span>{{ $label }}</span><input id="edit_{{ $name }}" name="{{ $name }}" type="date" value="{{ $dateValue($name) }}" {{ $disabledAttribute }}><small class="edit-error">@error($name){{ $message }}@enderror</small></label>
                        @endforeach
                        <p class="field-help edit-wide">Dates are shown to clients and used to flag upcoming arrivals and overdue deliveries.</p>
                    </div>
                </section>

                <section class="fcard edit-card" id="containersCard">
                    <div class="edit-card-head"><span class="section-number">05</span><h2>Containers</h2>@if (! $isReadOnly)<button class="btn btn-secondary" type="button" data-add-container>+ Add Row</button>@endif</div>
                    <div class="container-grid"><div class="container-head"><span>Container No.</span><span>Seal No.</span><span>Size/Type</span><span>Action</span></div>
                        <div id="containerRows">@foreach (old('containers', $shipment->containers->map(fn ($row) => ['container_no' => $row->container_no, 'seal_no' => $row->seal_no, 'size_type' => $row->size_type])->all()) as $index => $row)<div class="container-row"><label><span class="sr-only">Container No.</span><input name="containers[{{ $index }}][container_no]" value="{{ $row['container_no'] ?? '' }}" maxlength="20" placeholder="Container No." {{ $disabledAttribute }}></label><label><span class="sr-only">Seal No.</span><input name="containers[{{ $index }}][seal_no]" value="{{ $row['seal_no'] ?? '' }}" maxlength="30" placeholder="Seal No." {{ $disabledAttribute }}></label><label><span class="sr-only">Size/Type</span><input name="containers[{{ $index }}][size_type]" value="{{ $row['size_type'] ?? '' }}" maxlength="20" placeholder="Size/Type" {{ $disabledAttribute }}></label>@if (! $isReadOnly)<button class="remove-container" type="button" data-remove-container>Remove</button>@else<span></span>@endif</div>@endforeach</div>
                        <div class="container-error">{{ $errors->first('containers') }}</div><p class="field-help">Sea containers only. Air and Land shipments do not require sea containers. Add a row only when container details are available.</p>
                    </div>
                </section>
            </form>

            <footer class="edit-footer"><span id="editState" aria-live="polite">{{ $isReadOnly ? 'Read-only · '.($isArchived ? 'Archived shipment' : 'Closed shipment') : 'No changes to save' }}</span><div><a class="btn btn-secondary" href="{{ \App\Support\ShipmentListUrl::for(auth()->user()) }}" data-cancel>{{ $isReadOnly ? 'Back to Shipments' : 'Cancel' }}</a><button class="btn btn-primary" type="submit" form="shipmentEditForm" id="saveShipment" {{ $isReadOnly ? 'disabled' : '' }}>Save Changes</button></div></footer>
            <dialog class="leave-dialog" id="leaveDialog"><div class="leave-mark">!</div><h2>Leave page without saving?</h2><p>Your unsaved changes to {{ $shipment->reference_no }} will be lost. Stay on this page to keep editing your draft.</p><div><button class="btn btn-danger-outline" type="button" data-leave-confirm>Leave without saving</button><button class="btn btn-primary" type="button" data-leave-stay>Stay</button></div></dialog>
        @endif
    </div>
</x-app-layout>
