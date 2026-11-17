<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignShipmentsRequest;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ShipmentManagementController extends Controller
{
    public function index(Request $request): View|SymfonyResponse
    {
        $stageService = app(\App\Services\ShipmentStageService::class);
        $groups = $stageService->groups();
        $statusKey = (string) $request->query('status', '');
        if (! array_key_exists($statusKey, $groups)) {
            $statusKey = '';
        }

        $dateOptions = ['' => 'Any date', 'today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'month' => 'This month', 'year' => 'This year'];
        $dateKey = array_key_exists((string) $request->query('date', ''), $dateOptions) ? (string) $request->query('date', '') : '';
        $arrivalOptions = ['' => 'Any arrival date', 'arriving7d' => 'Arriving in 7 days', 'overdue' => 'Overdue delivery'];
        $arrivalKey = array_key_exists((string) $request->query('arrival', ''), $arrivalOptions) ? (string) $request->query('arrival', '') : '';
        $scope = $request->query('scope') === 'archived' ? 'archived' : 'active';
        $staffList = User::query()->where('role', 'staff')->where('status', 'active')->orderBy('full_name')->get(['user_id', 'full_name']);
        $staffKey = (string) $request->query('staff', '');
        $validStaff = $staffList->pluck('user_id')->map(fn ($id): string => (string) $id)->all();
        if ($staffKey !== '' && $staffKey !== 'unassigned' && ! in_array($staffKey, $validStaff, true)) {
            $staffKey = '';
        }
        $search = trim((string) $request->query('q', ''));

        $staffLoad = Shipment::query()->select('assigned_staff_id')
            ->selectRaw('COUNT(*) AS open_shipments')
            ->whereNotNull('assigned_staff_id')
            ->whereRaw("LOWER(TRIM(current_status)) NOT IN ('delivered', 'cancelled')")
            ->groupBy('assigned_staff_id')->pluck('open_shipments', 'assigned_staff_id');

        $baseQuery = $this->filteredQuery($search, $staffKey, $dateKey, $arrivalKey, $scope);
        $statusRows = (clone $baseQuery)->select('current_status')->selectRaw('COUNT(*) AS total')->groupBy('current_status')->get();
        $statusCounts = $statusRows->mapWithKeys(fn ($row): array => [mb_strtolower(trim((string) $row->current_status)) => (int) $row->total]);
        $tabCounts = ['' => (int) $statusCounts->sum()];
        foreach ($groups as $key => $group) {
            $tabCounts[$key] = collect($group['stages'])->sum(fn (string $stage): int => (int) ($statusCounts[$stage] ?? 0));
        }

        $archivedCount = Shipment::query()->whereNotNull('archived_at')->count();
        $listQuery = clone $baseQuery;
        if ($statusKey !== '') {
            $placeholders = implode(', ', array_fill(0, count($groups[$statusKey]['stages']), '?'));
            $listQuery->whereRaw('LOWER(TRIM(current_status)) IN ('.$placeholders.')', $groups[$statusKey]['stages']);
        }

        if ($request->boolean('export')) {
            return $this->export($listQuery);
        }
        \App\Support\ShipmentListUrl::remember($request);

        $total = (clone $listQuery)->count();
        $perPage = 8;
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $request->integer('page', 1)), $lastPage);
        $shipments = $listQuery->orderByDesc('created_at')->orderByDesc('shipment_id')
            ->paginate($perPage, ['*'], 'page', $page)->withQueryString();
        $today = Carbon::today();
        $shipments->getCollection()->transform(function (Shipment $shipment) use ($today, $stageService): Shipment {
            $shipment->dashboard_status = $stageService->statusInfo($shipment->current_status, $shipment->stage?->stage_group);
            $shipment->dashboard_overdue = $shipment->delivery_due_date?->lt($today)
                && ! in_array(mb_strtolower(trim($shipment->current_status)), ['delivered', 'cancelled'], true);

            return $shipment;
        });

        $filtering = $search !== '' || $statusKey !== '' || $staffKey !== '' || $dateKey !== '' || $arrivalKey !== '' || $scope === 'archived';

        return view('admin.shipments.index', compact(
            'groups', 'statusKey', 'dateOptions', 'dateKey', 'arrivalOptions', 'arrivalKey', 'scope',
            'staffList', 'staffLoad', 'staffKey', 'search', 'tabCounts', 'archivedCount', 'shipments',
            'total', 'perPage', 'filtering'
        ));
    }

    public function assign(AssignShipmentsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $actor = $request->user();
        $result = DB::transaction(function () use ($data, $actor): array {
            $staff = User::query()->where('role', 'staff')->where('status', 'active')
                ->whereKey($data['staff_id'])->lockForUpdate()->first();
            if (! $staff) {
                return ['status' => 422, 'error' => 'Choose an active staff member.'];
            }

            $shipments = Shipment::query()->with('stage')->whereIn('shipment_id', $data['shipment_ids'])
                ->orderBy('shipment_id')->lockForUpdate()->get();
            if ($shipments->count() !== count($data['shipment_ids'])) {
                return ['status' => 404, 'error' => 'One or more shipments could not be found. Refresh the list and try again.'];
            }
            $finished = $shipments->first(fn (Shipment $shipment): bool => ! $shipment->isOpen());
            if ($finished) {
                return ['status' => 409, 'error' => $finished->reference_no.' is closed or archived and can no longer be assigned. Refresh the list and try again.'];
            }
            $alreadyAssigned = $shipments->first(fn (Shipment $shipment): bool => $shipment->assigned_staff_id !== null);
            if ($alreadyAssigned) {
                return ['status' => 409, 'error' => $alreadyAssigned->reference_no.' has already been assigned. Refresh the list and try again.'];
            }

            $items = [];
            foreach ($shipments as $shipment) {
                $shipment->assigned_staff_id = $staff->user_id;
                $shipment->save();

                app(\App\Services\ActivityLogger::class)->record($actor, 'Shipment Update', $shipment->reference_no.' assigned to '.$staff->full_name);
                app(\App\Services\NotificationService::class)->forShipment($shipment, $actor, 'assignment', 'Shipment assigned', $shipment->reference_no.' was assigned to '.$staff->full_name.'.');

                $items[] = ['id' => (int) $shipment->shipment_id, 'reference' => $shipment->reference_no];
            }

            return ['status' => 200, 'success' => true, 'staff' => $staff->full_name, 'count' => count($items), 'items' => $items];
        }, 3);

        $status = (int) ($result['status'] ?? 500);
        unset($result['status']);

        return response()->json($result, $status);
    }

    private function filteredQuery(string $search, string $staffKey, string $dateKey, string $arrivalKey, string $scope): Builder
    {
        $query = Shipment::query()->with(['client', 'assignedStaff', 'stage'])->withMax('statusUpdates', 'updated_at')
            ->when($scope === 'archived', fn (Builder $builder) => $builder->whereNotNull('archived_at'), fn (Builder $builder) => $builder->whereNull('archived_at'));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder->where('reference_no', 'like', $like)
                    ->orWhere('bl_awb_no', 'like', $like)
                    ->orWhere('invoice_no', 'like', $like)
                    ->orWhereHas('client', fn (Builder $client) => $client->where('client_name', 'like', $like))
                    ->orWhereHas('containers', fn (Builder $container) => $container->where('container_no', 'like', $like));
            });
        }
        if ($staffKey === 'unassigned') {
            $query->whereNull('assigned_staff_id');
        } elseif ($staffKey !== '') {
            $query->where('assigned_staff_id', (int) $staffKey);
        }

        $now = Carbon::now();
        match ($dateKey) {
            'today' => $query->whereDate('created_at', Carbon::today()->toDateString()),
            '7d' => $query->where('created_at', '>=', $now->copy()->subDays(7)),
            '30d' => $query->where('created_at', '>=', $now->copy()->subDays(30)),
            'month' => $query->where('created_at', '>=', Carbon::today()->startOfMonth()),
            'year' => $query->where('created_at', '>=', Carbon::today()->startOfYear()),
            default => null,
        };

        $notFinished = "LOWER(TRIM(current_status)) NOT IN ('delivered', 'cancelled')";
        if ($arrivalKey === 'arriving7d') {
            $query->whereBetween('eta_date', [Carbon::today()->toDateString(), Carbon::today()->addDays(7)->toDateString()])->whereRaw($notFinished);
        } elseif ($arrivalKey === 'overdue') {
            $query->where('delivery_due_date', '<', Carbon::today()->toDateString())->whereRaw($notFinished);
        }

        return $query;
    }

    /** @param Builder<Shipment> $query */
    private function export(Builder $query): SymfonyResponse
    {
        $shipments = $query->orderByDesc('created_at')->orderByDesc('shipment_id')->get();

        return response()->streamDownload(function () use ($shipments): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Shipment Reference', 'Client Name', 'Shipment Date', 'Current Status', 'Assigned Staff', 'Last Updated']);
            foreach ($shipments as $shipment) {
                $safe = fn (?string $value): string => $this->safeCsvCell($value ?? '');
                $status = app(\App\Services\ShipmentStageService::class)->statusInfo($shipment->current_status, $shipment->stage?->stage_group);
                $updated = $shipment->status_updates_max_updated_at
                    ? Carbon::parse($shipment->status_updates_max_updated_at)
                    : $shipment->created_at;
                fputcsv($output, [
                    $safe($shipment->reference_no),
                    $safe($shipment->client?->client_name),
                    $shipment->created_at?->format('M d, Y') ?? '—',
                    $status['label'],
                    $shipment->assignedStaff ? $safe($shipment->assignedStaff->full_name) : 'Unassigned',
                    $updated?->format('M d, Y') ?? '—',
                ]);
            }
            fclose($output);
        }, 'shipments_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function safeCsvCell(string $value): string
    {
        return $value !== '' && str_contains('=+-@', $value[0]) ? "'".$value : $value;
    }

}

