<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/BillingController.php
 * Feature: Billing
 * Purpose: Bills for shipments: create a bill, record payments, review payment proof, show the evidence
 *          file.
 */

namespace App\Http\Controllers;

use App\Models\Billing;
use App\Models\BillingItem;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class BillingController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();
        $view = (string) $request->query('view', 'list');
        abort_unless(in_array($view, ['list', 'create', 'detail', 'record', 'submit', 'review'], true), 404);
        if ($view === 'submit' && $actor->role !== 'client') {
            $view = 'record';
        }
        abort_if($actor->role === 'client' && in_array($view, ['create', 'review'], true), 403);
        $bill = null;
        $items = collect();
        $payments = collect();
        $clients = collect();
        $shipments = collect();
        $reviewRows = collect();

        if (in_array($view, ['detail', 'record', 'submit'], true)) {
            $bill = $this->billQuery($actor)->where('b.billing_id', $request->integer('id'))->first();
            abort_unless($bill, 404, 'Bill not found.');
            if (in_array($view, ['record', 'submit'], true) && $bill->archived_at) {
                $view = 'detail';
            }
            $items = BillingItem::query()->where('billing_id', $bill->billing_id)->orderBy('item_id')->get();
            $payments = Payment::query()->with(['recordedBy', 'verifiedBy'])->where('billing_id', $bill->billing_id)->orderByDesc('payment_date')->orderByDesc('payment_id')->get();
        }
        if ($view === 'create') {
            if ($actor->role === 'admin') {
                $clients = Client::query()->where('status', 'active')->orderBy('client_name')->get(['client_id', 'client_name']);
            } else {
                $clients = Client::query()->where('status', 'active')->whereHas('shipments', fn ($query) => $query->where('assigned_staff_id', $actor->user_id)->whereNull('archived_at'))->orderBy('client_name')->get(['client_id', 'client_name']);
            }
            $selectedClient = $request->integer('client_id');
            if ($selectedClient && $clients->contains('client_id', $selectedClient)) {
                $shipments = Shipment::query()->where('client_id', $selectedClient)->whereNull('archived_at')
                    ->when($actor->role === 'staff', fn ($query) => $query->where('assigned_staff_id', $actor->user_id))
                    ->orderByDesc('created_at')->get(['shipment_id', 'reference_no', 'transaction_type', 'mode']);
            }
        }
        if ($view === 'review') {
            $reviewRows = DB::table('payments as p')->join('billings as b', 'b.billing_id', '=', 'p.billing_id')
                ->join('clients as c', 'c.client_id', '=', 'b.client_id')->join('shipments as s', 's.shipment_id', '=', 'b.shipment_id')
                ->where('p.verification_status', 'pending')->when($actor->role === 'staff', fn ($query) => $query->where('s.assigned_staff_id', $actor->user_id))
                ->orderByDesc('p.payment_id')->limit(100)->get(['p.payment_id', 'p.amount', 'p.payment_date', 'p.payment_method', 'p.reference_no', 'b.billing_id', 'b.billing_no', 'c.client_name', 's.reference_no as shipment_reference']);
        }

        $base = $this->billQuery($actor);
        $stats = (clone $base)->selectRaw('COALESCE(SUM(v.total_amount),0) AS billed, COALESCE(SUM(v.amount_paid),0) AS paid, COALESCE(SUM(GREATEST(v.balance,0)),0) AS balance')->first();
        $pending = DB::table('payments as p')->join('billings as b', 'b.billing_id', '=', 'p.billing_id')->join('shipments as s', 's.shipment_id', '=', 'b.shipment_id')
            ->where('p.verification_status', 'pending')
            ->when($actor->role === 'staff', fn ($query) => $query->where('s.assigned_staff_id', $actor->user_id))
            ->when($actor->role === 'client', fn ($query) => $query->where('b.client_id', $actor->client_id));
        $stats->pending_count = (clone $pending)->count();
        $stats->pending_amount = (clone $pending)->sum('p.amount');
        if ($actor->role === 'client') {
            // Client stat card shows unpaid/partially paid bills instead of pending verifications.
            $stats->unpaid_bills = DB::table('billings')->where('client_id', $actor->client_id)->whereIn('payment_status', ['unpaid', 'partial'])->count();
        }

        $bills = null;
        $totalBills = 0;
        $filters = [];
        $clientsForFilter = collect();
        if ($view === 'list') {
            $filters = [
                'q' => trim((string) $request->query('q', '')),
                'client_id' => $request->integer('client_id'),
                'bill_status' => (string) $request->query('bill_status', ''),
                'payment_status' => (string) $request->query('payment_status', ''),
                'start' => (string) $request->query('start', ''), 'end' => (string) $request->query('end', ''),
            ];
            $query = $this->billQuery($actor)->select('b.*', 'c.client_name', 's.reference_no', 's.archived_at', 's.shipment_id', 'v.amount_paid', 'v.balance', 'v.payment_status as computed_status')
                ->selectSub(fn ($sub) => $sub->from('payments')->selectRaw('COUNT(*)')->whereColumn('payments.billing_id', 'b.billing_id')->where('verification_status', 'pending'), 'pending_count');
            if ($filters['q'] !== '') {
                $term = '%'.$filters['q'].'%';
                $query->where(fn ($q) => $q->where('b.billing_no', 'like', $term)->orWhere('c.client_name', 'like', $term)->orWhere('s.reference_no', 'like', $term)->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('payments as p')->whereColumn('p.billing_id', 'b.billing_id')->where('p.reference_no', 'like', $term)));
            }
            if ($actor->role === 'admin' && $filters['client_id']) {
                $query->where('b.client_id', $filters['client_id']);
            }
            if (in_array($filters['bill_status'], ['unpaid', 'partial', 'paid'], true)) {
                $query->where('b.payment_status', $filters['bill_status']);
            }
            if (in_array($filters['payment_status'], ['pending', 'verified', 'rejected'], true)) {
                $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('payments as p')->whereColumn('p.billing_id', 'b.billing_id')->where('p.verification_status', $filters['payment_status']));
            }
            foreach (['start' => '>=', 'end' => '<='] as $key => $operator) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key]) && strtotime($filters[$key]) !== false) {
                    $query->where('b.billing_date', $operator, $filters[$key]);
                }
            }
            $bills = $query->orderByDesc('b.billing_id')->get();
            $totalBills = $bills->count();
            if ($actor->role === 'admin') {
                $clientsForFilter = Client::query()->orderBy('client_name')->get(['client_id', 'client_name']);
            }
        }

        return view('billing.index', compact('view', 'bill', 'items', 'payments', 'clients', 'shipments', 'reviewRows', 'stats', 'bills', 'totalBills', 'filters', 'clientsForFilter'));
    }

    public function storeBill(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['admin', 'staff'], true), 403);
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'client_id')->where(fn ($query) => $query->where('status', 'active'))],
            'shipment_id' => ['required', 'integer', 'exists:shipments,shipment_id'],
            'billing_date' => ['required', 'date_format:Y-m-d'], 'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:billing_date'],
            'items' => ['required', 'array', 'min:1'], 'items.*.charge_type' => ['required', 'string', 'max:40'],
            'items.*.description' => ['nullable', 'string', 'max:255'], 'items.*.amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
        ]);
        $shipment = Shipment::query()->with('client')->where('shipment_id', $data['shipment_id'])->where('client_id', $data['client_id'])->first();
        abort_unless($shipment && $shipment->client?->status === 'active' && ! $shipment->archived_at, 422, 'The selected shipment is unavailable for new billing.');
        if ($request->user()->role === 'staff' && (int) $shipment->assigned_staff_id !== (int) $request->user()->user_id) {
            abort(403, 'Staff can create bills only for their assigned shipments.');
        }
        $totalCents = 0;
        foreach ($data['items'] as $index => $item) {
            if ((float) $item['amount'] <= 0) {
                return back()->withInput()->withErrors(["items.$index.amount" => 'Enter a positive charge amount.']);
            }
            $totalCents += (int) round((float) $item['amount'] * 100);
        }
        if ($totalCents < 1 || $totalCents > 999999999999999) {
            return back()->withInput()->withErrors(['items' => 'The bill total is outside the database amount limit.']);
        }

        try {
            $newBill = DB::transaction(function () use ($request, $data, $shipment, $totalCents): Billing {
                $locked = Shipment::query()->lockForUpdate()->find($shipment->shipment_id);
                if (! $locked || (int) $locked->client_id !== (int) $data['client_id'] || $locked->archived_at || ($request->user()->role === 'staff' && (int) $locked->assigned_staff_id !== (int) $request->user()->user_id)) {
                    throw new RuntimeException('The shipment changed or is no longer available. Reload and try again.');
                }
                $bill = Billing::query()->create([
                    'billing_no' => 'TMP-'.bin2hex(random_bytes(9)), 'client_id' => $data['client_id'], 'shipment_id' => $data['shipment_id'],
                    'billing_date' => $data['billing_date'], 'due_date' => $data['due_date'], 'total_amount' => number_format($totalCents / 100, 2, '.', ''),
                    'payment_status' => 'unpaid', 'created_by' => $request->user()->user_id,
                ]);
                $bill->billing_no = 'BL-'.now()->format('Y').'-'.str_pad((string) $bill->billing_id, 6, '0', STR_PAD_LEFT);
                $bill->save();
                foreach ($data['items'] as $item) {
                    BillingItem::query()->create(['billing_id' => $bill->billing_id, 'charge_type' => trim($item['charge_type']), 'description' => filled($item['description'] ?? null) ? trim($item['description']) : null, 'amount' => number_format((int) round((float) $item['amount'] * 100) / 100, 2, '.', '')]);
                }
                Transaction::query()->where('shipment_id', $shipment->shipment_id)->whereNull('billing_id')->orderByDesc('transaction_id')->first()?->update(['billing_id' => $bill->billing_id]);
                app(\App\Services\ActivityLogger::class)->record($request->user(), 'Billing Change', 'Bill '.$bill->billing_no.' created for '.$shipment->reference_no, ['client_id' => (int) $data['client_id'], 'shipment_id' => (int) $shipment->shipment_id, 'total' => number_format($totalCents / 100, 2, '.', '')]);

                return $bill;
            }, 3);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(['billing' => $exception instanceof RuntimeException ? $exception->getMessage() : 'The bill could not be saved. No changes were applied.']);
        }

        return redirect()->route('billing.index', ['view' => 'detail', 'id' => $newBill->billing_id])->with('success', 'Bill '.$newBill->billing_no.' was created.');
    }

    public function recordPayment(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['admin', 'staff', 'client'], true), 403);
        $data = $request->validate([
            'billing_id' => ['required', 'integer', 'exists:billings,billing_id'],
            'amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'], 'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'payment_method' => ['required', Rule::in(['Bank transfer', 'Cash', 'Check', 'E-wallet', 'Other'])],
            'reference_no' => ['required', 'string', 'max:60'], 'evidence' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);
        if ((float) $data['amount'] <= 0) {
            return back()->withInput()->withErrors(['amount' => 'Enter a positive amount with up to two decimal places.']);
        }
        $billInfo = $this->billQuery($request->user())->where('b.billing_id', $data['billing_id'])->first();
        abort_unless($billInfo, 404, 'Bill not found.');
        if ($billInfo->archived_at) {
            return redirect()->route('billing.index', ['view' => 'detail', 'id' => $billInfo->billing_id])->with('error', 'Archived shipment bills are read-only.');
        }
        $file = $request->file('evidence');
        $mime = $file->getMimeType();
        $extension = match ($mime) {
            'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', default => null
        };
        if (! $extension) {
            return back()->withInput()->withErrors(['evidence' => 'Evidence must be a PDF, JPG, or PNG file.']);
        }
        $path = 'payment-proofs/'.bin2hex(random_bytes(16)).'.'.$extension;
        if (! Storage::disk('local')->putFileAs('payment-proofs', $file, basename($path))) {
            return back()->withInput()->withErrors(['evidence' => 'Evidence storage is unavailable.']);
        }

        try {
            DB::transaction(function () use ($request, $data, $billInfo, $path): void {
                $bill = Billing::query()->lockForUpdate()->findOrFail($billInfo->billing_id);
                $verified = (float) Payment::query()->where('billing_id', $bill->billing_id)->where('verification_status', 'verified')->sum('amount');
                $pending = (float) Payment::query()->where('billing_id', $bill->billing_id)->where('verification_status', 'pending')->sum('amount');
                $amount = (int) round((float) $data['amount'] * 100);
                $available = max(0, (int) round((float) $bill->total_amount * 100) - (int) round($verified * 100) - (int) round($pending * 100));
                if ($amount > $available) {
                    throw new RuntimeException('The amount exceeds the bill balance available for new submissions after other pending payments.');
                }
                $payment = Payment::query()->create([
                    'billing_id' => $bill->billing_id, 'amount' => number_format($amount / 100, 2, '.', ''), 'payment_date' => $data['payment_date'],
                    'payment_method' => $data['payment_method'], 'reference_no' => trim($data['reference_no']), 'proof_file_path' => $path,
                    'verification_status' => 'pending', 'recorded_by' => $request->user()->user_id,
                ]);
                app(\App\Services\ActivityLogger::class)->record($request->user(), 'Billing Change', 'Payment evidence recorded for '.$bill->billing_no, ['payment_id' => $payment->payment_id, 'amount' => number_format($amount / 100, 2, '.', ''), 'verification_status' => 'pending']);
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            report($exception);

            return back()->withInput()->withErrors(['payment' => $exception instanceof RuntimeException ? $exception->getMessage() : 'Payment could not be saved.']);
        }

        return redirect()->route('billing.index', ['view' => 'detail', 'id' => $billInfo->billing_id])->with('success', 'Payment recorded as Pending Verification. The outstanding balance changes only after admin verification.');
    }

    public function reviewPayment(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['payment_id' => ['required', 'integer', 'exists:payments,payment_id'], 'decision' => ['required', Rule::in(['verified', 'rejected'])]]);
        try {
            $billingId = DB::transaction(function () use ($request, $data): int {
                $payment = Payment::query()->lockForUpdate()->find($data['payment_id']);
                if (! $payment || $payment->verification_status !== 'pending') {
                    throw new RuntimeException('That payment is no longer pending review.');
                }
                $bill = Billing::query()->lockForUpdate()->findOrFail($payment->billing_id);
                $paidCents = (int) round((float) Payment::query()->where('billing_id', $bill->billing_id)->where('verification_status', 'verified')->sum('amount') * 100);
                $paymentCents = (int) round((float) $payment->amount * 100);
                $totalCents = (int) round((float) $bill->total_amount * 100);
                if ($data['decision'] === 'verified' && $paidCents + $paymentCents > $totalCents) {
                    throw new RuntimeException('This payment would exceed the bill total. Reject it and ask the submitter to correct the amount.');
                }
                $payment->verification_status = $data['decision'];
                $payment->verified_by = $request->user()->user_id;
                $payment->save();
                $paidCents += $data['decision'] === 'verified' ? $paymentCents : 0;
                $bill->payment_status = $paidCents <= 0 ? 'unpaid' : ($paidCents >= $totalCents ? 'paid' : 'partial');
                $bill->save();
                app(\App\Services\ActivityLogger::class)->record($request->user(), 'Billing Change', 'Payment '.$data['decision'].' for bill '.$bill->billing_no, ['payment_id' => $payment->payment_id, 'amount' => (string) $payment->amount, 'payment_status' => $bill->payment_status]);

                return (int) $bill->billing_id;
            }, 3);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Payment review failed. The bill balance was not changed.');
        }

        $message = $data['decision'] === 'verified' ? 'Payment verified. Bill balance and status updated.' : 'Payment rejected. Bill balance is unchanged.';

        return redirect()->route('billing.index', ['view' => 'detail', 'id' => $billingId])->with('success', $message);
    }

    public function evidence(Request $request, int $paymentId)
    {
        $payment = Payment::query()->with('billing.shipment')->find($paymentId);
        abort_unless($payment && $payment->billing && $this->canSeeShipment($request->user(), $payment->billing->shipment), 404, 'Evidence not found.');
        $relative = (string) $payment->proof_file_path;
        if (str_starts_with($relative, 'payment-proofs/') && ! str_contains($relative, '..') && Storage::disk('local')->exists($relative)) {
            $path = Storage::disk('local')->path($relative);
        } elseif (preg_match('~^uploads/payment_proofs/[a-f0-9]{32}\.(pdf|jpg|png)$~i', $relative)) {
            $root = realpath(storage_path('app/private/legacy-reference/uploads/payment_proofs'));
            $candidate = realpath(storage_path('app/private/legacy-reference/'.$relative));
            abort_unless($root && $candidate && str_starts_with($candidate, $root.DIRECTORY_SEPARATOR) && is_file($candidate), 404, 'Evidence not found.');
            $path = $candidate;
        } else {
            abort(404, 'Evidence not found.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        abort_unless(in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true), 415, 'Unsupported evidence file.');

        return response()->download($path, 'payment-evidence-'.$paymentId.'.'.pathinfo($path, PATHINFO_EXTENSION), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function billQuery(User $actor)
    {
        return DB::table('billings as b')->join('clients as c', 'c.client_id', '=', 'b.client_id')->join('shipments as s', 's.shipment_id', '=', 'b.shipment_id')
            ->join('v_billing_balance as v', 'v.billing_id', '=', 'b.billing_id')
            ->when($actor->role === 'staff', fn ($query) => $query->where('s.assigned_staff_id', $actor->user_id))
            ->when($actor->role === 'client', fn ($query) => $query->where('b.client_id', $actor->client_id));
    }

    private function canSeeShipment(User $actor, ?Shipment $shipment): bool
    {
        return $shipment && ($actor->role === 'admin'
            || ($actor->role === 'staff' && (int) $shipment->assigned_staff_id === (int) $actor->user_id)
            || ($actor->role === 'client' && (int) $shipment->client_id === (int) $actor->client_id));
    }
}
