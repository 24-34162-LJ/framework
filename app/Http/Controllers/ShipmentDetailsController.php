<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/ShipmentDetailsController.php
 * Feature: Shipments
 * Purpose: Shipment details page for staff/admin: reassign staff, save customs processing and delivery
 *          details.
 */

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CustomsProcessing;
use App\Models\Delivery;
use App\Models\Shipment;
use App\Models\User;
use App\Services\ShipmentDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ShipmentDetailsController extends Controller
{
    public function show(Request $request, int $shipmentId, ShipmentDocumentService $documentService): View
    {
        $shipment = Shipment::query()->with([
            'client', 'assignedStaff', 'stage', 'containers',
            'statusUpdates.stageDefinition', 'statusUpdates.updatedBy',
            'customsProcessingRecords.updatedBy', 'documents.uploadedBy', 'documents.reviewedBy',
        ])->findOrFail($shipmentId);
        if ($request->user()->role === 'staff' && $shipment->assigned_staff_id !== null
            && (int) $shipment->assigned_staff_id !== (int) $request->user()->user_id) {
            abort(404);
        }

        $shipment->setRelation('customsProcessingRecords', $shipment->customsProcessingRecords
            ->sortByDesc(fn ($record): string => ($record->updated_at?->format('Y-m-d H:i:s') ?? '').'-'.$record->customs_id)->values());
        $delivery = Delivery::query()->where('shipment_id', $shipment->shipment_id)->orderByDesc('delivery_id')->first();
        $documents = $shipment->documents->map(function ($document) use ($documentService): object {
            $path = $documentService->readablePath($document);
            $document->available = $path !== null;
            $document->file_size = $path ? filesize($path) : null;

            return $document;
        });
        $documentChecklist = app(\App\Services\ShipmentDocumentService::class)->checklist($documents->filter(fn ($document): bool => $document->available));
        // Older versions (replaced files) are shown separately as history.
        $documentHistory = $documents->filter(fn ($document): bool => $document->superseded_at !== null)->values();
        $documents = $documents->filter(fn ($document): bool => $document->superseded_at === null)->values();
        $documentRequests = \App\Models\DocumentRequest::query()->with('requestedBy')->where('shipment_id', $shipment->shipment_id)
            ->where('status', 'open')->orderBy('due_date')->get();
        $pendingReviewCount = $documents->where('review_status', \App\Models\ShipmentDocument::REVIEW_PENDING)->count();
        $stageService = app(\App\Services\ShipmentStageService::class);
        $progress = $stageService->progress($shipment);
        $statusLabel = $shipment->current_status === 'Trucking' ? 'Trucking' : ($shipment->stage?->stage_group ?? $shipment->current_status);
        $statusClass = $stageService->groupClass((string) ($shipment->stage?->stage_group ?? ''));
        $modeLabels = [
            'movement' => $shipment->mode === 'land' ? 'Truck Plate' : ($shipment->mode === 'air' ? 'Airline / Flight' : 'Vessel / Voyage'),
            'document' => $shipment->mode === 'land' ? 'Waybill No.' : ($shipment->mode === 'air' ? 'AWB No.' : 'B/L No.'),
            'origin' => $shipment->mode === 'air' ? 'Airport of Departure' : ($shipment->mode === 'land' ? 'Origin' : 'Port of Loading'),
            'destination' => $shipment->mode === 'air' ? 'Airport of Arrival' : ($shipment->mode === 'land' ? 'Destination' : 'Port of Discharge'),
        ];
        $latestAudit = ActivityLog::query()->where('description', 'like', '%'.$shipment->reference_no.'%')
            ->orderByDesc('created_at')->orderByDesc('activity_id')->limit(4)->get();
        $staffList = $request->user()->role === 'admin'
            ? User::query()->where('role', 'staff')->where('status', 'active')->orderBy('full_name')
                ->withCount(['assignedShipments as open_shipments' => fn ($query) => $query
                    ->whereRaw("LOWER(TRIM(current_status)) NOT IN ('delivered', 'cancelled')")])
                ->get(['user_id', 'full_name'])
            : collect();
        $isArchived = $shipment->archived_at !== null;
        $isShipmentClosed = (bool) $shipment->stage?->is_closed;
        $statusModalState = $stageService->modalState($shipment, $request->user()->role);
        $consistencyChecks = app(\App\Services\ShipmentConsistencyService::class)->checks($shipment);
        $unbilledDuties = $request->user()->role === 'admin' || (int) $shipment->assigned_staff_id === (int) $request->user()->user_id
            ? app(\App\Services\ShipmentConsistencyService::class)->unbilledDuties($shipment) : null;
        $canChangeDetails = $shipment->officeChangeBlockedFor($request->user()) === null;
        $needsClaim = $shipment->needsClaimBy($request->user()) && $shipment->isOpen();
        $canReassign = $request->user()->role === 'admin' && $shipment->isOpen();

        return view('shipments.show', compact(
            'shipment', 'delivery', 'documents', 'documentChecklist', 'progress', 'statusLabel', 'statusClass',
            'modeLabels', 'latestAudit', 'isArchived', 'isShipmentClosed', 'staffList', 'statusModalState',
            'documentHistory', 'documentRequests', 'pendingReviewCount', 'canChangeDetails', 'canReassign', 'consistencyChecks', 'unbilledDuties', 'needsClaim'
        ));
    }

    public function reassign(Request $request, int $shipmentId): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['staff_id' => ['required', 'integer', Rule::exists('users', 'user_id')->where(fn ($query) => $query->where('role', 'staff')->where('status', 'active'))]]);
        $shipment = Shipment::query()->with('assignedStaff')->findOrFail($shipmentId);
        $staff = User::query()->where('user_id', $data['staff_id'])->where('role', 'staff')->where('status', 'active')->firstOrFail();
        $oldStaffName = $shipment->assignedStaff?->full_name ?? 'Unassigned';

        $error = DB::transaction(function () use ($shipment, $staff, $oldStaffName, $request): ?string {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->shipment_id);
            if (! $locked->isOpen()) {
                return $locked->reference_no.' is closed or archived, so its staff assignment can no longer change.';
            }
            if ((int) $locked->assigned_staff_id === (int) $staff->user_id) {
                return $locked->reference_no.' is already assigned to '.$staff->full_name.'.';
            }
            $locked->assigned_staff_id = $staff->user_id;
            $locked->save();
            $description = $locked->reference_no.' reassigned from '.$oldStaffName.' to '.$staff->full_name;
            app(\App\Services\ActivityLogger::class)->record($request->user(), 'Shipment Update', $description);
            app(\App\Services\NotificationService::class)->forShipment($locked, $request->user(), 'assignment', 'Shipment reassigned', $locked->reference_no.' was assigned to '.$staff->full_name.'.');

            return null;
        }, 3);

        if ($error !== null) {
            return redirect()->route('shipments.show', $shipmentId)->with('error', $error);
        }

        return redirect()->route('shipments.show', $shipmentId)->with('success', 'Shipment reassigned to '.$staff->full_name.'.');
    }

    public function saveCustoms(Request $request, int $shipmentId): RedirectResponse
    {
        $shipment = $this->accessibleShipment($request, $shipmentId);
        if ($blocked = $shipment->officeChangeBlockedFor($request->user())) {
            return redirect()->route('shipments.show', $shipmentId)->withFragment('customs')->with('error', $blocked);
        }
        if ($request->input('declaration_type') === '__other__') {
            $request->merge(['declaration_type' => trim((string) $request->input('declaration_type_custom', ''))]);
        }
        $data = $request->validate([
            'customs_id' => ['nullable', 'integer'], 'entry_no' => ['nullable', 'string', 'max:50'],
            'declaration_type' => ['required', 'string', 'max:40'], 'lodgement_date' => ['nullable', 'date_format:Y-m-d'],
            'customs_value' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'duties_taxes_amount' => ['nullable', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'duties_paid_date' => ['nullable', 'date_format:Y-m-d'],
            'assessment_status' => ['required', Rule::in(['pending', 'assessed', 'paid'])],
            'examination_lane' => ['nullable', Rule::in(['green', 'yellow', 'orange', 'red'])],
            'clearance_date' => ['nullable', 'date_format:Y-m-d'], 'customs_remarks' => ['nullable', 'string', 'max:5000'],
        ]);
        if ((float) $data['customs_value'] <= 0 || (isset($data['duties_taxes_amount']) && $data['duties_taxes_amount'] !== '' && (float) $data['duties_taxes_amount'] <= 0)) {
            return back()->withInput()->withErrors(['customs_value' => 'Enter customs and duties amounts greater than zero.'])->withFragment('customs');
        }
        if ($data['assessment_status'] === 'paid' && empty($data['duties_paid_date'])) {
            return back()->withInput()->withErrors(['duties_paid_date' => 'Enter the date the duties were paid.'])->withFragment('customs');
        }
        foreach (['lodgement_date' => 'Lodgement date', 'duties_paid_date' => 'Duties paid date', 'clearance_date' => 'Clearance date'] as $field => $label) {
            if (! empty($data[$field]) && $data[$field] > now()->toDateString()) {
                return back()->withInput()->withErrors([$field => $label.' cannot be in the future.'])->withFragment('customs');
            }
        }
        if (! empty($data['clearance_date']) && empty($data['examination_lane'])) {
            return back()->withInput()->withErrors(['examination_lane' => 'Set the examination lane before entering a clearance date.'])->withFragment('customs');
        }
        if (! empty($data['duties_paid_date']) && $data['assessment_status'] !== 'paid') {
            return back()->withInput()->withErrors(['assessment_status' => 'A duties paid date is set, so set Assessment Status to Paid.'])->withFragment('customs');
        }
        if (! empty($data['clearance_date']) && $data['assessment_status'] !== 'paid') {
            return back()->withInput()->withErrors(['clearance_date' => 'Clearance comes after the duties are paid: set Assessment Status to Paid with a Duties Paid Date.'])->withFragment('customs');
        }
        if (! empty($data['lodgement_date']) && ! empty($data['duties_paid_date']) && $data['duties_paid_date'] < $data['lodgement_date']) {
            return back()->withInput()->withErrors(['duties_paid_date' => 'Duties paid date cannot precede the lodgement date.'])->withFragment('customs');
        }
        if (! empty($data['lodgement_date']) && ! empty($data['clearance_date']) && $data['clearance_date'] < $data['lodgement_date']) {
            return back()->withInput()->withErrors(['clearance_date' => 'Clearance date cannot precede the lodgement date.'])->withFragment('customs');
        }
        if (! empty($data['duties_paid_date']) && ! empty($data['clearance_date']) && $data['clearance_date'] < $data['duties_paid_date']) {
            return back()->withInput()->withErrors(['clearance_date' => 'Clearance date cannot precede the duties paid date.'])->withFragment('customs');
        }
        // Save the entry and move the shipment forward to the stage these details support, together.
        [$record, $movedTo, $stopNote] = DB::transaction(function () use ($request, $shipmentId, $data): array {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipmentId);
            $record = isset($data['customs_id']) && $data['customs_id'] !== ''
                ? CustomsProcessing::query()->where('shipment_id', $shipmentId)->findOrFail($data['customs_id'])
                : new CustomsProcessing(['shipment_id' => $shipmentId]);
            $record->fill(collect($data)->except('customs_id')->all());
            $record->updated_by = $request->user()->user_id;
            $record->save();
            $verb = $record->wasRecentlyCreated ? 'customs entry added' : 'customs details updated';
            app(\App\Services\ActivityLogger::class)->record($request->user(), 'Shipment Update', $locked->reference_no.' '.$verb);
            app(\App\Services\NotificationService::class)->forShipment($locked, $request->user(), 'customs', 'Customs details updated', $locked->reference_no.' customs processing details were saved.');
            [$movedTo, $stopNote] = app(\App\Services\ShipmentStageService::class)->syncFromCustoms($locked, $request->user());

            return [$record, $movedTo, $stopNote];
        }, 3);
        $message = ($record->wasRecentlyCreated ? 'Customs entry added.' : 'Customs details saved.')
            .($movedTo ? ' Shipment status is now '.$movedTo.'.' : '').($stopNote ? ' '.$stopNote : '');

        // If assessed duties are not billed yet, the shipment page shows a "Create duties bill" reminder.
        return redirect()->route('shipments.show', $shipmentId)->withFragment('customs')->with('success', $message);
    }

    public function saveDelivery(Request $request, int $shipmentId): RedirectResponse
    {
        $shipment = $this->accessibleShipment($request, $shipmentId);
        if ($blocked = $shipment->officeChangeBlockedFor($request->user())) {
            return redirect()->route('shipments.show', $shipmentId)->withFragment('delivery')->with('error', $blocked);
        }
        $data = $request->validate([
            'delivery_id' => ['nullable', 'integer'], 'trucker' => ['nullable', 'string', 'max:100'],
            'driver_name' => ['nullable', 'string', 'max:100'], 'plate_no' => ['nullable', 'regex:/^[A-Za-z]{3} ?\d{4}$/', 'max:20'],
            'delivery_address' => ['nullable', 'string', 'max:255'], 'contact_name' => ['nullable', 'string', 'max:100'],
            'contact_phone' => ['nullable', 'string', 'max:30'], 'scheduled_date' => ['nullable', 'date_format:Y-m-d'],
            'scheduled_time' => ['nullable', 'date_format:H:i'], 'delivered_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['scheduled', 'in_transit', 'delivered', 'cancelled'])],
        ]);
        if ($data['status'] === 'scheduled' && empty($data['scheduled_date'])) {
            return back()->withInput()->withErrors(['scheduled_date' => 'Pickup schedule date is required when delivery is scheduled.'])->withFragment('delivery');
        }
        if ($data['status'] === 'delivered' && empty($data['delivered_date'])) {
            return back()->withInput()->withErrors(['delivered_date' => 'Delivered date is required when status is Delivered.'])->withFragment('delivery');
        }
        // Delivery dates must be possible: not in the future, not before the shipment, in order.
        $today = now()->toDateString();
        $created = $shipment->created_at?->format('Y-m-d');
        $arrived = $shipment->actual_arrival_date?->format('Y-m-d');
        $dateError = match (true) {
            ! empty($data['delivered_date']) && $data['status'] !== 'delivered' => ['delivered_date', 'A delivered date only goes with status Delivered. Clear it, or set the status to Delivered.'],
            ! empty($data['delivered_date']) && $data['delivered_date'] > $today => ['delivered_date', 'Delivered date cannot be in the future.'],
            ! empty($data['delivered_date']) && $arrived && $data['delivered_date'] < $arrived => ['delivered_date', 'Delivered date cannot be before the Actual Arrival ('.\Carbon\Carbon::parse($arrived)->format('M d, Y').').'],
            ! empty($data['scheduled_date']) && $created && $data['scheduled_date'] < $created => ['scheduled_date', 'Pickup cannot be scheduled before the shipment was created ('.\Carbon\Carbon::parse($created)->format('M d, Y').').'],
            ! empty($data['scheduled_date']) && ! empty($data['delivered_date']) && $data['delivered_date'] < $data['scheduled_date'] => ['delivered_date', 'Delivered date cannot be before the pickup schedule.'],
            default => null,
        };
        if ($dateError) {
            return back()->withInput()->withErrors([$dateError[0] => $dateError[1]])->withFragment('delivery');
        }
        $contactName = trim((string) ($data['contact_name'] ?? ''));
        $contactPhone = trim((string) ($data['contact_phone'] ?? ''));
        $receivingContact = implode(' · ', array_filter([$contactName, $contactPhone], fn (string $part): bool => $part !== ''));
        if (mb_strlen($receivingContact) > 150) {
            return back()->withInput()->withErrors(['contact_name' => 'Combined receiving contact details must be 150 characters or fewer.'])->withFragment('delivery');
        }
        [$movedTo, $blocked] = DB::transaction(function () use ($request, $shipmentId, $data, $receivingContact): array {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipmentId);
            $delivery = isset($data['delivery_id']) && $data['delivery_id'] !== ''
                ? Delivery::query()->where('shipment_id', $shipmentId)->findOrFail($data['delivery_id'])
                : new Delivery(['shipment_id' => $shipmentId]);
            $delivery->fill([
                'trucker' => $data['trucker'] ?: null, 'driver_name' => $data['driver_name'] ?: null,
                'plate_no' => isset($data['plate_no']) ? mb_strtoupper(preg_replace('/\s+/', ' ', trim($data['plate_no']))) : null,
                'delivery_address' => $data['delivery_address'] ?: null, 'receiving_contact' => $receivingContact ?: null,
                'scheduled_date' => $data['scheduled_date'] ?: null, 'scheduled_time' => $data['scheduled_time'] ?: null,
                'delivered_date' => $data['delivered_date'] ?: null, 'status' => $data['status'],
            ]);
            $delivery->save();
            $verb = $delivery->wasRecentlyCreated ? 'delivery details scheduled' : 'delivery details updated';
            app(\App\Services\ActivityLogger::class)->record($request->user(), 'Shipment Update', $shipment->reference_no.' '.$verb);
            app(\App\Services\NotificationService::class)->forShipment($shipment, $request->user(), 'delivery', 'Delivery details updated', $shipment->reference_no.' delivery details were saved.');

            // Delivery In Transit / Delivered also moves the shipment status forward, under the same
            // rules as Update Status (customs release, arrival, payment). Never backwards, never cancels.
            $stages = app(\App\Services\ShipmentStageService::class);
            $blocked = $stages->deliveryBlocker($shipment, $data['status']);

            return [$blocked ? null : $stages->syncFromDelivery($shipment, $data['status'], $request->user()), $blocked];
        }, 3);

        $message = 'Delivery details saved.'.($movedTo ? ' Shipment status is now '.$movedTo.'.' : '');
        $redirect = redirect()->route('shipments.show', $shipmentId)->withFragment('delivery')->with('success', $message);

        return $blocked ? $redirect->with('error', $blocked) : $redirect;
    }

    /** Staff take an unassigned shipment ("Assign to me"). Only one person can win the race. */
    public function claim(Request $request, int $shipmentId): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'staff', 403);
        $shipment = Shipment::query()->findOrFail($shipmentId);
        if ($shipment->archived_at !== null || ! $shipment->isOpen()) {
            return redirect()->route('shipments.show', $shipmentId)->with('error', $shipment->reference_no.' is closed or archived and cannot be claimed.');
        }
        $claimed = Shipment::query()->whereKey($shipmentId)->whereNull('assigned_staff_id')->update(['assigned_staff_id' => $user->user_id]);
        if ($claimed === 0) {
            return redirect()->route('shipments.show', $shipmentId)->with('error', 'Someone else was just assigned to '.$shipment->reference_no.'.');
        }
        $shipment->refresh();
        app(\App\Services\ActivityLogger::class)->record($user, 'Shipment Update', $shipment->reference_no.' assigned to '.$user->full_name.' (Assign to me)');
        try {
            app(\App\Services\NotificationService::class)->forShipmentRoles($shipment, $user, ['admin'], 'assignment', 'Shipment claimed', $shipment->reference_no.' was taken by '.$user->full_name.'.');
        } catch (\Throwable $exception) {
            report($exception); // the claim itself is saved; a failed notice must not undo it
        }

        return redirect()->route('shipments.show', $shipmentId)->with('success', 'You are now assigned to '.$shipment->reference_no.'.');
    }

    private function accessibleShipment(Request $request, int $shipmentId): Shipment
    {
        $shipment = Shipment::query()->findOrFail($shipmentId);
        if ($request->user()->role === 'staff' && $shipment->assigned_staff_id !== null
            && (int) $shipment->assigned_staff_id !== (int) $request->user()->user_id) {
            abort(404);
        }

        return $shipment;
    }

}
