<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/ShipmentEditController.php
 * Feature: Shipments
 * Purpose: Edit Shipment page: update shipment fields, record what changed, notify about date changes.
 */

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Shipment;
use App\Models\TransactionHistory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class ShipmentEditController extends Controller
{
    public function edit(Request $request, int $shipmentId): View
    {
        $shipment = Shipment::query()->with(['client', 'assignedStaff', 'stage', 'containers'])->findOrFail($shipmentId);
        $isAssignedToOther = $request->user()->role === 'staff'
            && $shipment->assigned_staff_id !== null
            && (int) $shipment->assigned_staff_id !== (int) $request->user()->user_id;
        $isArchived = $shipment->archived_at !== null;
        $isClosed = (bool) $shipment->stage?->is_closed;
        $isReadOnly = $isArchived || ($isClosed && $request->user()->role !== 'admin');
        $canEdit = ! $isAssignedToOther && ! $isReadOnly;
        $clients = Client::query()->where(fn ($query) => $query->where('status', 'active')->orWhere('client_id', $shipment->client_id))
            ->orderBy('client_name')->get(['client_id', 'client_name', 'status']);
        $currencyOptions = ['USD', 'PHP', 'EUR', 'JPY', 'CNY', 'SGD', 'HKD', 'GBP', 'AUD', 'CAD'];
        if ($shipment->currency && ! in_array($shipment->currency, $currencyOptions, true)) {
            $currencyOptions[] = $shipment->currency;
        }

        return view('shipments.edit', compact('shipment', 'clients', 'currencyOptions', 'isAssignedToOther', 'isArchived', 'isClosed', 'isReadOnly', 'canEdit'));
    }

    public function update(Request $request, int $shipmentId): RedirectResponse
    {
        $shipment = Shipment::query()->with(['stage', 'client', 'containers'])->findOrFail($shipmentId);
        abort_unless($this->mayEdit($request, $shipment), 403, 'You may not edit this shipment.');
        $validated = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'client_id')->where(fn ($query) => $query->where('status', 'active'))],
            'transaction_type' => ['required', Rule::in(['import', 'export'])],
            'mode' => ['required', Rule::in(['sea', 'air', 'land'])],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'bl_awb_no' => ['nullable', 'string', 'max:50', Rule::unique('shipments', 'bl_awb_no')->ignore($shipmentId, 'shipment_id')],
            'total_packages' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'total_gross_weight' => ['nullable', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'],
            'shipper_name' => ['nullable', 'string', 'max:150'], 'consignee_name' => ['nullable', 'string', 'max:150'],
            'vessel_or_flight' => ['nullable', 'string', 'max:100'], 'port_of_loading' => ['nullable', 'string', 'max:100'],
            'port_of_discharge' => ['nullable', 'string', 'max:100'], 'hs_code' => ['nullable', 'string', 'max:20'],
            'measurement_cbm' => ['nullable', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'], 'incoterm' => ['nullable', 'string', 'max:10'],
            'invoice_no' => ['nullable', 'string', 'max:50'], 'currency' => ['required', 'regex:/^[A-Za-z]{3}$/'],
            'invoice_value' => ['nullable', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'freight_amount' => ['nullable', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'fob_value' => ['nullable', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'eta_date' => ['nullable', 'date_format:Y-m-d'], 'first_free_day' => ['nullable', 'date_format:Y-m-d'],
            'delivery_due_date' => ['nullable', 'date_format:Y-m-d'],
            'containers' => ['nullable', 'array', 'max:200'],
            'containers.*.container_no' => ['nullable', 'string', 'max:20'],
            'containers.*.seal_no' => ['nullable', 'string', 'max:30'],
            'containers.*.size_type' => ['nullable', 'string', 'max:20'],
        ]);
        if (in_array($validated['mode'], ['sea', 'air'], true) && blank($validated['bl_awb_no'] ?? null)) {
            return back()->withInput()->withErrors(['bl_awb_no' => $validated['mode'] === 'air' ? 'AWB number is required for Air.' : 'B/L number is required for Sea.']);
        }

        $containers = collect($validated['containers'] ?? [])->map(fn (array $row): array => [
            'container_no' => trim((string) ($row['container_no'] ?? '')),
            'seal_no' => trim((string) ($row['seal_no'] ?? '')),
            'size_type' => trim((string) ($row['size_type'] ?? '')),
        ])->filter(fn (array $row): bool => $row['container_no'] !== '' || $row['seal_no'] !== '' || $row['size_type'] !== '')->values();
        if ($validated['mode'] !== 'sea' && $containers->isNotEmpty()) {
            return back()->withInput()->withErrors(['containers' => 'Sea containers only. Remove all container rows before changing this shipment to Air or Land.']);
        }
        $seen = [];
        foreach ($containers as $container) {
            if ($container['container_no'] === '') {
                return back()->withInput()->withErrors(['containers' => 'Enter a container number or clear the incomplete row.']);
            }
            $number = mb_strtoupper($container['container_no']);
            if (isset($seen[$number])) {
                return back()->withInput()->withErrors(['containers' => 'Container numbers must be unique within this shipment.']);
            }
            $seen[$number] = true;
        }

        $validated['currency'] = strtoupper($validated['currency']);
        $validated['incoterm'] = filled($validated['incoterm'] ?? null) ? strtoupper($validated['incoterm']) : null;
        $validated['bl_awb_no'] = filled($validated['bl_awb_no'] ?? null) ? trim($validated['bl_awb_no']) : null;
        foreach (['remarks', 'shipper_name', 'consignee_name', 'vessel_or_flight', 'port_of_loading', 'port_of_discharge', 'hs_code', 'invoice_no'] as $field) {
            $validated[$field] = filled($validated[$field] ?? null) ? trim($validated[$field]) : null;
        }
        $actor = $request->user();

        try {
            DB::transaction(function () use ($shipmentId, $validated, $containers, $actor): void {
                $locked = Shipment::query()->with(['stage', 'client', 'containers'])->lockForUpdate()->findOrFail($shipmentId);
                abort_unless($this->mayEdit(request(), $locked), 403, 'This shipment was archived, closed, or reassigned while you were editing.');
                $oldClientName = $locked->client?->client_name ?? 'Unknown client';
                $newClient = Client::query()->where('client_id', $validated['client_id'])->where('status', 'active')->firstOrFail();
                $clientChanged = (int) $locked->client_id !== (int) $validated['client_id'];
                $datesChanged = collect(['eta_date', 'first_free_day', 'delivery_due_date'])->contains(
                    fn (string $field): bool => (string) ($locked->{$field}?->format('Y-m-d') ?? '') !== (string) ($validated[$field] ?? '')
                );
                $changes = $this->auditChanges($locked, $validated, $containers->all());
                $locked->fill(collect($validated)->except('containers')->all());
                $locked->save();

                if ($clientChanged) {
                    $locked->transactions()->update(['client_id' => $newClient->client_id]);
                    $locked->billings()->update(['client_id' => $newClient->client_id]);
                    foreach ($locked->transactions()->get(['transaction_id']) as $transaction) {
                        TransactionHistory::query()->create([
                            'transaction_id' => $transaction->transaction_id, 'event_type' => 'Client changed',
                            'description' => mb_substr('Client on shipment '.$locked->reference_no.' changed from '.$oldClientName.' to '.$newClient->client_name, 0, 255),
                            'performed_by' => $actor->user_id,
                        ]);
                    }
                }

                $locked->containers()->delete();
                if ($validated['mode'] === 'sea') {
                    foreach ($containers as $container) {
                        $locked->containers()->create([
                            'container_no' => $container['container_no'],
                            'seal_no' => $container['seal_no'] !== '' ? $container['seal_no'] : null,
                            'size_type' => $container['size_type'] !== '' ? $container['size_type'] : null,
                        ]);
                    }
                }
                app(\App\Services\ActivityLogger::class)->record($actor, 'Shipment Update', $locked->reference_no.' '.($changes ? 'metadata updated: '.implode(', ', array_slice(array_keys($changes), 0, 8)) : 'metadata saved'), $changes);
                if ($datesChanged) {
                    $this->notifyShipmentDates($locked, $actor);
                }
            }, 3);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000' && str_contains($exception->getMessage(), 'uq_ship_bl')) {
                return back()->withInput()->withErrors(['bl_awb_no' => 'This B/L / AWB number is already assigned to another shipment.']);
            }
            report($exception);

            return back()->withInput()->with('form_error', 'Shipment changes could not be saved. No changes were applied. Please review the entries and try again.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('form_error', 'Shipment changes could not be saved. No changes were applied. Please review the entries and try again.');
        }

        return redirect()->route('shipments.edit', ['shipmentId' => $shipment->shipment_id])->with('success', $shipment->reference_no.' was updated.');
    }

    private function mayEdit(Request $request, Shipment $shipment): bool
    {
        if ($shipment->archived_at !== null) {
            return false;
        }
        if ($request->user()->role === 'admin') {
            return true;
        }
        if ((bool) $shipment->stage?->is_closed) {
            return false;
        }

        return $shipment->assigned_staff_id === null || (int) $shipment->assigned_staff_id === (int) $request->user()->user_id;
    }

    /** @param list<array{container_no: string, seal_no: string, size_type: string}> $containers
     * @return array<string, mixed>
     */
    private function auditChanges(Shipment $shipment, array $values, array $containers): array
    {
        $fields = [
            'client_id' => 'Client', 'transaction_type' => 'Type', 'mode' => 'Mode', 'remarks' => 'Description / Remarks',
            'bl_awb_no' => 'B/L or AWB No.', 'total_packages' => 'Packages', 'total_gross_weight' => 'Gross Weight (kg)',
            'shipper_name' => 'Shipper', 'consignee_name' => 'Consignee', 'vessel_or_flight' => 'Vessel or Flight',
            'port_of_loading' => 'Port of Loading', 'port_of_discharge' => 'Port of Discharge', 'hs_code' => 'HS Code',
            'measurement_cbm' => 'Measurement (CBM)', 'incoterm' => 'Incoterm', 'invoice_no' => 'Invoice No.',
            'currency' => 'Currency', 'invoice_value' => 'Invoice Value', 'freight_amount' => 'Freight', 'fob_value' => 'FOB Value',
            'eta_date' => 'Estimated Arrival', 'first_free_day' => 'First Free Day', 'delivery_due_date' => 'Delivery Due Date',
        ];
        if ($values['mode'] === 'land') {
            $fields['bl_awb_no'] = 'Waybill No.';
            $fields['vessel_or_flight'] = 'Truck Plate';
            $fields['port_of_loading'] = 'Origin';
            $fields['port_of_discharge'] = 'Destination';
        } elseif ($values['mode'] === 'air') {
            $fields['bl_awb_no'] = 'AWB No.';
            $fields['vessel_or_flight'] = 'Airline / Flight';
            $fields['port_of_loading'] = 'Airport of Departure';
            $fields['port_of_discharge'] = 'Airport of Arrival';
        } else {
            $fields['bl_awb_no'] = 'B/L No.';
            $fields['vessel_or_flight'] = 'Vessel / Voyage';
        }
        $changes = [];
        foreach ($fields as $field => $label) {
            $old = $shipment->{$field};
            if ($old instanceof \DateTimeInterface) {
                $old = $old->format('Y-m-d');
            }
            $new = $values[$field] ?? '';
            if ($field === 'client_id') {
                $old = $shipment->client?->client_name ?? $old;
                $new = Client::query()->where('client_id', $new)->value('client_name') ?? $new;
            }
            if (in_array($field, ['transaction_type', 'mode'], true)) {
                $old = ucfirst((string) $old);
                $new = ucfirst((string) $new);
            }
            if ((string) ($old ?? '') !== (string) ($new ?? '')) {
                $changes[$label] = ['old' => filled($old) ? (string) $old : '—', 'new' => filled($new) ? (string) $new : '—'];
            }
        }
        $oldContainers = $shipment->containers->map(fn ($row): array => [$row->container_no, $row->seal_no ?? '', $row->size_type ?? ''])->all();
        $newContainers = collect($containers)->map(fn (array $row): array => [$row['container_no'], $row['seal_no'], $row['size_type']])->all();
        if ($oldContainers !== $newContainers) {
            $changes['Containers'] = ['old' => $oldContainers, 'new' => $newContainers];
        }

        return $changes;
    }

    private function notifyShipmentDates(Shipment $shipment, User $actor): void
    {
        app(\App\Services\NotificationService::class)->forShipment($shipment, $actor, 'date', 'Shipment dates updated', $shipment->reference_no.' key dates were updated.');
    }
}
