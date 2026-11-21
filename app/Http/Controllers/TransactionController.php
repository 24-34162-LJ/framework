<?php

/*
 * FILE PURPOSE
 * File   : app/Http/Controllers/TransactionController.php
 * Feature: Transactions
 * Purpose: Transactions page: link a payment/transaction to a shipment and bill, and correct it (with
 *          history).
 */

namespace App\Http\Controllers;

use App\Models\Billing;
use App\Models\Shipment;
use App\Models\ShipmentStage;
use App\Models\Transaction;
use App\Models\TransactionHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class TransactionController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();
        $view = (string) $request->query('view', 'list');
        abort_unless(in_array($view, ['list', 'create', 'detail', 'correct'], true), 404);
        abort_if($actor->role === 'client' && in_array($view, ['create', 'correct'], true), 403);
        abort_if($view === 'correct' && $actor->role !== 'admin', 403);
        $record = null;
        $history = collect();
        $bills = collect();
        $payment = null;
        $shipmentsToLink = collect();
        $linkBills = collect();
        $suggestedStatus = null;

        if (in_array($view, ['detail', 'correct'], true)) {
            $record = $this->transactionQuery($actor)->select('t.*', 'c.client_name', 's.reference_no', 's.current_status as shipment_status', 's.assigned_staff_id', 's.archived_at', 'b.billing_no')
                ->where('t.transaction_id', $request->integer('id'))->first();
            abort_unless($record, 404, 'Transaction not found.');
            $bills = DB::table('billings as b')->join('v_billing_balance as v', 'v.billing_id', '=', 'b.billing_id')
                ->where('b.shipment_id', $record->shipment_id)->where('b.client_id', $record->client_id)->orderBy('b.billing_id')
                ->get(['b.billing_id', 'b.billing_no', 'b.billing_date', 'b.due_date', 'b.total_amount', 'v.amount_paid', 'v.balance', 'v.payment_status']);
            $payment = Transaction::paymentState($bills->count(), $bills->sum('total_amount'), $bills->sum('amount_paid'));
            $history = TransactionHistory::query()->with('performedBy')->where('transaction_id', $record->transaction_id)->orderByDesc('created_at')->orderByDesc('history_id')->get();
            if ($view === 'correct') {
                $suggestedStatus = app(\App\Services\ShipmentStageService::class)->transactionStatus(
                    ShipmentStage::query()->where('stage_name', $record->shipment_status)->first(), (string) $record->status
                );
            }
        }
        if ($view === 'create') {
            $shipmentsToLink = Shipment::query()->with(['client', 'stage'])->whereNull('archived_at')->whereHas('client', fn ($query) => $query->where('status', 'active'))
                ->whereDoesntHave('transactions')->when($actor->role === 'staff', fn ($query) => $query->where('assigned_staff_id', $actor->user_id))
                ->orderByDesc('created_at')->limit(300)->get();
            $shipmentIds = $shipmentsToLink->pluck('shipment_id');
            if ($shipmentIds->isNotEmpty()) {
                $linkBills = Billing::query()->whereIn('shipment_id', $shipmentIds)->orderByDesc('created_at')->get(['billing_id', 'billing_no', 'shipment_id', 'client_id']);
            }
        }

        $filters = [
            'q' => trim((string) $request->query('q', '')), 'status' => (string) $request->query('status', ''),
            'client_id' => $request->integer('client_id'), 'start' => (string) $request->query('start', ''), 'end' => (string) $request->query('end', ''),
        ];
        $stats = ['pending' => 0, 'ongoing' => 0, 'completed' => 0, 'total' => 0];
        $transactions = null;
        $clients = collect();
        if ($view === 'list') {
            // Client, search and date filters apply to both the summary cards and the table; the
            // status filter only narrows the table, otherwise the other status cards would read 0.
            $filtered = $this->transactionQuery($actor)
                ->when($actor->role === 'admin' && $filters['client_id'], fn ($q) => $q->where('t.client_id', $filters['client_id']));
            if ($filters['q'] !== '') {
                $term = '%'.addcslashes($filters['q'], '\\%_').'%';
                // Accepts the displayed code (TXN-2026-0012) or a bare number (12, 0012).
                $transactionId = preg_match('/^(?:TXN-\d{4}-)?0*(\d{1,18})$/i', $filters['q'], $match) ? (int) $match[1] : null;
                // A transaction covers every bill of its shipment, so a bill number matches any of them.
                $filtered->where(fn ($q) => $q->when($transactionId !== null, fn ($q) => $q->orWhere('t.transaction_id', $transactionId))
                    ->orWhere('c.client_name', 'like', $term)->orWhere('s.reference_no', 'like', $term)
                    ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('billings as sb')->whereColumn('sb.shipment_id', 't.shipment_id')
                        ->whereColumn('sb.client_id', 't.client_id')->where('sb.billing_no', 'like', $term)));
            }
            foreach (['start' => '>=', 'end' => '<='] as $key => $operator) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key]) && strtotime($filters[$key]) !== false) {
                    $filtered->whereDate('t.created_at', $operator, $filters[$key]);
                }
            }
            $counts = (clone $filtered)->selectRaw('t.status, COUNT(*) AS total')->groupBy('t.status')->pluck('total', 'status')->all();
            $stats = ['pending' => (int) ($counts['pending'] ?? 0), 'ongoing' => (int) ($counts['ongoing'] ?? 0), 'completed' => (int) ($counts['completed'] ?? 0)];
            $stats['total'] = array_sum($stats);

            $shipmentBills = fn () => DB::table('billings as sb')->whereColumn('sb.shipment_id', 't.shipment_id')->whereColumn('sb.client_id', 't.client_id');
            $query = $filtered->select('t.*', 'c.client_name', 's.reference_no', 's.current_status as shipment_status', 'b.billing_no')
                ->selectSub($shipmentBills()->selectRaw('COUNT(*)'), 'bill_count')
                ->selectSub($shipmentBills()->selectRaw('COALESCE(SUM(sb.total_amount), 0)'), 'bill_total')
                // Verified payments only, the same rule as the Billing page's "Verified paid"
                ->selectSub($shipmentBills()->join('payments as sp', 'sp.billing_id', '=', 'sb.billing_id')
                    ->where('sp.verification_status', 'verified')->selectRaw('COALESCE(SUM(sp.amount), 0)'), 'bill_paid')
                ->selectSub($shipmentBills()->orderBy('sb.billing_id')->limit(1)->select('sb.billing_id'), 'first_billing_id')
                ->selectSub($shipmentBills()->orderBy('sb.billing_id')->limit(1)->select('sb.billing_no'), 'first_billing_no');
            if (in_array($filters['status'], ['pending', 'ongoing', 'completed'], true)) {
                $query->where('t.status', $filters['status']);
            }
            $transactions = $query->orderByDesc('t.created_at')->orderByDesc('t.transaction_id')->paginate(20)->withQueryString();
            if ($actor->role === 'admin') {
                $clients = DB::table('clients')->orderBy('client_name')->get(['client_id', 'client_name']);
            }
        }

        return view('transactions.index', compact('view', 'record', 'history', 'bills', 'payment', 'shipmentsToLink', 'linkBills', 'suggestedStatus', 'filters', 'stats', 'transactions', 'clients'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['admin', 'staff'], true), 403);
        $data = $request->validate([
            'shipment_id' => ['required', 'integer', 'exists:shipments,shipment_id'],
            'billing_id' => ['nullable', 'integer', 'exists:billings,billing_id'],
        ]);
        try {
            $transaction = DB::transaction(function () use ($request, $data): Transaction {
                $shipment = Shipment::query()->with('client')->lockForUpdate()->find($data['shipment_id']);
                if (! $shipment || $shipment->archived_at || $shipment->client?->status !== 'active') {
                    throw new RuntimeException('Choose an active, unarchived shipment.');
                }
                if ($request->user()->role === 'staff' && (int) $shipment->assigned_staff_id !== (int) $request->user()->user_id) {
                    throw new RuntimeException('Staff can link only their assigned shipments.');
                }
                if (Transaction::query()->where('shipment_id', $shipment->shipment_id)->lockForUpdate()->exists()) {
                    throw new RuntimeException('This shipment already has a transaction. Open its existing record instead.');
                }
                $billingId = $data['billing_id'] ?? app(\App\Services\ShipmentStageService::class)->firstBillId($shipment);
                if ($billingId && ! Billing::query()->where('billing_id', $billingId)->where('shipment_id', $shipment->shipment_id)->where('client_id', $shipment->client_id)->exists()) {
                    throw new RuntimeException('The selected bill must belong to the shipment and its client.');
                }
                $stage = ShipmentStage::query()->where('stage_name', $shipment->current_status)->first();
                if (! $stage) {
                    throw new RuntimeException('Shipment progress is not recognized. Ask an administrator to review this shipment.');
                }
                $status = app(\App\Services\ShipmentStageService::class)->transactionStatus($stage, 'pending');
                $transaction = Transaction::query()->create([
                    'client_id' => $shipment->client_id, 'shipment_id' => $shipment->shipment_id, 'billing_id' => $billingId,
                    'status' => $status, 'completed_at' => $status === 'completed' ? now() : null,
                ]);
                TransactionHistory::query()->create([
                    'transaction_id' => $transaction->transaction_id, 'event_type' => 'Transaction created',
                    'description' => 'Linked to shipment '.$shipment->reference_no.' at '.$shipment->current_status.'.',
                    'performed_by' => $request->user()->user_id,
                ]);
                app(\App\Services\ActivityLogger::class)->record($request->user(), 'Transaction Change', 'Transaction '.$this->code($transaction).' linked to '.$shipment->reference_no, ['shipment_id' => (int) $shipment->shipment_id, 'client_id' => (int) $shipment->client_id]);

                return $transaction;
            }, 3);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'The transaction could not be saved. No changes were applied.');
        }

        return redirect()->route('transactions.index', ['view' => 'detail', 'id' => $transaction->transaction_id])->with('success', 'Transaction linked to the shipment.');
    }

    public function correct(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'transaction_id' => ['required', 'integer', 'exists:transactions,transaction_id'],
            'reason' => ['required', 'string', 'min:5', 'max:255'], 'confirm' => ['required', 'accepted'],
        ]);
        $visible = $this->transactionQuery($request->user())->select('t.*', 'c.client_name', 's.reference_no', 's.current_status as shipment_status', 's.assigned_staff_id', 's.archived_at', 'b.billing_no')
            ->where('t.transaction_id', $data['transaction_id'])->first();
        abort_unless($visible, 404, 'Transaction not found.');
        try {
            DB::transaction(function () use ($request, $data, $visible): void {
                $shipment = Shipment::query()->lockForUpdate()->findOrFail($visible->shipment_id);
                if ($shipment->archived_at) {
                    throw new RuntimeException('This shipment is archived. Reopen it before correcting its transaction.');
                }
                $transaction = Transaction::query()->lockForUpdate()->findOrFail($visible->transaction_id);
                if ((int) $transaction->shipment_id !== (int) $shipment->shipment_id) {
                    throw new RuntimeException('The transaction no longer matches its shipment. Reload and try again.');
                }
                $stage = ShipmentStage::query()->where('stage_name', $shipment->current_status)->first();
                if (! $stage) {
                    throw new RuntimeException('Shipment progress is not recognized; no correction was made.');
                }
                $targetStatus = app(\App\Services\ShipmentStageService::class)->transactionStatus($stage, (string) $transaction->status);
                if ($targetStatus === $transaction->status) {
                    throw new RuntimeException('This transaction already matches its shipment progress.');
                }
                $oldStatus = (string) $transaction->status;
                $transaction->status = $targetStatus;
                $transaction->completed_at = $targetStatus === 'completed' ? now() : null;
                $transaction->save();
                $description = 'Correction '.ucfirst($oldStatus).' → '.ucfirst($targetStatus).'; shipment stage: '.$shipment->current_status.'. Reason: ';
                // Keep the status change in full and shorten the reason instead (column is 255).
                $description = mb_substr($description.trim($data['reason']), 0, 255);
                TransactionHistory::query()->create([
                    'transaction_id' => $transaction->transaction_id, 'event_type' => 'Authorized correction',
                    'description' => $description, 'performed_by' => $request->user()->user_id,
                ]);
                app(\App\Services\ActivityLogger::class)->record($request->user(), 'Transaction Change', 'Transaction '.$this->code($transaction).' corrected to '.ucfirst($targetStatus), ['shipment_id' => (int) $shipment->shipment_id, 'reason' => trim($data['reason'])]);
            }, 3);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'The correction could not be saved. No changes were applied.');
        }

        return redirect()->route('transactions.index', ['view' => 'detail', 'id' => $visible->transaction_id])->with('success', 'Correction saved and recorded in transaction history.');
    }

    private function transactionQuery(User $actor)
    {
        return DB::table('transactions as t')->join('clients as c', 'c.client_id', '=', 't.client_id')
            ->join('shipments as s', 's.shipment_id', '=', 't.shipment_id')->leftJoin('billings as b', 'b.billing_id', '=', 't.billing_id')
            ->when($actor->role === 'staff', fn ($query) => $query->where('s.assigned_staff_id', $actor->user_id))
            ->when($actor->role === 'client', fn ($query) => $query->where('t.client_id', $actor->client_id)->where('s.client_id', $actor->client_id));
    }

    private function code(Transaction $transaction): string
    {
        return 'TXN-'.CarbonImmutable::parse($transaction->created_at)->format('Y').'-'.str_pad((string) $transaction->transaction_id, 4, '0', STR_PAD_LEFT);
    }
}
