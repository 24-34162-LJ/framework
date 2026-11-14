<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShipmentAuditController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $filter = (string) $request->query('filter', 'all');
        if (! in_array($filter, ['all', 'status', 'documents', 'edits'], true)) {
            return response()->json(['error' => 'Invalid activity filter.'], 400);
        }

        $shipment = Shipment::query()->find($request->integer('shipment_id'));
        if (! $shipment) {
            return response()->json(['error' => 'Shipment not found.'], 404);
        }
        if ($request->user()->role === 'staff' && $shipment->assigned_staff_id && (int) $shipment->assigned_staff_id !== (int) $request->user()->user_id) {
            return response()->json(['error' => 'You cannot view this shipment activity.'], 403);
        }

        $matches = fn (array $event): bool => $filter === 'all' || $event['category'] === $filter;
        $events = [];
        foreach ($shipment->statusUpdates()->with('updatedBy')->orderByDesc('updated_at')->orderByDesc('update_id')->get() as $update) {
            $event = [
                'key' => 's-'.$update->update_id,
                'category' => 'status',
                'kind' => 'Status',
                'at' => $update->updated_at,
                'actor' => $update->updatedBy?->full_name ?: 'System',
                'role' => $update->updatedBy?->role ?: 'system',
                'description' => rtrim((string) $update->stage, '. ').'.',
                'details' => null,
                'note' => trim((string) $update->remarks) !== '' ? $update->remarks : null,
            ];
            if ($matches($event)) {
                $events[] = $event;
            }
        }

        foreach ($shipment->documents()->with('uploadedBy')->orderByDesc('uploaded_at')->orderByDesc('document_id')->get() as $document) {
            $event = [
                'key' => 'd-'.$document->document_id,
                'category' => 'documents',
                'kind' => 'Document',
                'at' => $document->uploaded_at,
                'actor' => $document->uploadedBy?->full_name ?: 'System',
                'role' => $document->uploadedBy?->role ?: 'system',
                'description' => trim((string) $document->doc_type).' uploaded.',
                'details' => null,
            ];
            if ($matches($event)) {
                $events[] = $event;
            }
        }

        $activityRows = ActivityLog::query()->where('description', 'like', '%'.$shipment->reference_no.'%')
            ->orderByDesc('created_at')->orderByDesc('activity_id')->get();
        foreach ($activityRows as $activity) {
            $description = (string) $activity->description;
            if (preg_match('/^Status of .* (?:updated to|corrected from) /i', $description) === 1) {
                continue;
            }
            $category = preg_match('/document|uploaded|verified/i', $description) === 1 ? 'documents' : 'edits';
            $kind = match (true) {
                $category === 'documents' => 'Document',
                preg_match('/customs/i', $description) === 1 => 'Customs',
                preg_match('/delivery|trucking/i', $description) === 1 => 'Delivery',
                preg_match('/reassign|assigned/i', $description) === 1 => 'Assignment',
                default => 'Edit',
            };
            $details = is_array($activity->details_json) ? $activity->details_json : json_decode((string) $activity->details_json, true);
            $event = [
                'key' => 'a-'.$activity->activity_id,
                'category' => $category,
                'kind' => $kind,
                'at' => $activity->created_at,
                'actor' => $activity->performed_by_name ?: 'System',
                'role' => $activity->performed_by_role ?: 'system',
                'description' => $description,
                'details' => is_array($details) ? $details : null,
            ];
            if ($matches($event)) {
                $events[] = $event;
            }
        }

        $events = array_values(array_filter($events, function (array $event) use ($events): bool {
            if (! str_starts_with($event['key'], 'd-')) {
                return true;
            }
            $documentType = preg_replace('/ uploaded\.$/', '', (string) $event['description']);
            $documentTime = strtotime((string) $event['at']);
            if ($documentType === '' || $documentTime === false) {
                return true;
            }
            foreach ($events as $candidate) {
                if (! str_starts_with((string) $candidate['key'], 'a-') || $candidate['category'] !== 'documents') {
                    continue;
                }
                $needle = 'document uploaded: '.$documentType.' (';
                if (stripos((string) $candidate['description'], $needle) === false) {
                    continue;
                }
                $activityTime = strtotime((string) $candidate['at']);
                if ($activityTime !== false && abs($activityTime - $documentTime) <= 120) {
                    return false;
                }
            }

            return true;
        }));

        usort($events, static function (array $left, array $right): int {
            $date = strcmp((string) $right['at'], (string) $left['at']);

            return $date !== 0 ? $date : strcmp((string) $right['key'], (string) $left['key']);
        });

        $offset = max(0, $request->integer('offset'));
        $total = count($events);
        $items = array_slice($events, $offset, 8);
        $nextOffset = $offset + count($items);

        return response()->json([
            'items' => $items,
            'total' => $total,
            'nextOffset' => $nextOffset,
            'hasMore' => $nextOffset < $total,
        ])->header('Cache-Control', 'no-store');
    }
}
