<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/ShipmentSummaryController.php
 * Feature: Shipments
 * Purpose: Printable one-page summary sheet of a shipment.
 */

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\View\View;

class ShipmentSummaryController extends Controller
{
    public function show(Request $request): View
    {
        $id = $request->integer('id');
        $shipment = DB::table('shipments as s')
            ->join('clients as c', 'c.client_id', '=', 's.client_id')
            ->leftJoin('users as u', 'u.user_id', '=', 's.assigned_staff_id')
            ->where('s.shipment_id', $id)
            ->select('s.*', 'c.client_name', 'c.contact_person', 'c.email as client_email', 'c.phone as client_phone', 'u.full_name as staff_name')
            ->first();
        abort_unless($shipment, 404, 'Shipment not found.');
        abort_if($request->user()->role === 'staff' && $shipment->assigned_staff_id && (int) $shipment->assigned_staff_id !== (int) $request->user()->user_id, 404, 'Shipment not found.');

        $customs = DB::table('customs_processing')->where('shipment_id', $id)->orderByDesc('customs_id')->first();
        $delivery = DB::table('deliveries')->where('shipment_id', $id)->orderByDesc('delivery_id')->first();
        $containers = DB::table('containers')->where('shipment_id', $id)->orderBy('container_id')->get()->map(fn ($row): array => (array) $row)->all();
        $documents = DB::table('shipment_documents')->where('shipment_id', $id)->orderByDesc('uploaded_at')->orderByDesc('document_id')->get()->map(fn ($row): array => (array) $row)->all();
        $timeline = DB::table('shipment_status_updates')->where('shipment_id', $id)->orderByDesc('updated_at')->orderByDesc('update_id')->get()->map(fn ($row): array => (array) $row)->all();
        $billings = DB::table('billings')->where('shipment_id', $id)->orderByDesc('billing_id')->get()->map(fn ($row): array => (array) $row)->all();
        $formatDate = static fn ($value): string => $value ? Carbon::parse($value)->format('M d, Y') : '—';
        $formatDateTime = static fn ($value): string => $value ? Carbon::parse($value)->format('M d, Y · g:i A') : '—';
        $summaryRows = static function (array $fields): HtmlString {
            $html = '';
            foreach ($fields as $label => $value) {
                if ($value === null || $value === '') {
                    $value = '—';
                }
                $html .= '<div class="field"><dt>'.e($label).'</dt><dd>'.e($value).'</dd></div>';
            }

            return new HtmlString($html);
        };
        $ship = (array) $shipment;
        $customs = $customs ? (array) $customs : [];
        $delivery = $delivery ? (array) $delivery : [];

        $fullName = (string) ($request->user()?->full_name ?? '');

        return view('shipments.summary', compact(
            'fullName',
            'id', 'ship', 'customs', 'delivery', 'containers', 'documents', 'timeline', 'billings',
            'formatDate', 'formatDateTime', 'summaryRows',
        ));
    }
}
