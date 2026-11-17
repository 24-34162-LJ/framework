{{--
    FILE PURPOSE
    File   : resources/views/partials/document-ai-check.blade.php
    Feature: Documents
    Purpose: Shows the Gemini document check result for one document.
--}}

{{-- Gemini document check result for one document. $audience: staff | client --}}
@php
    $check = is_array($document->ai_check) ? $document->ai_check : null;
    $checkable = in_array(strtolower(pathinfo((string) $document->file_path, PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'png'], true);
    $isClientUpload = ($document->source ?? 'staff') === 'client';
@endphp
@if ($checkable && ($isClientUpload || $check))
    <div class="cd-ai" data-ai-check-box>
        @if (! $check)
            @if ($audience === 'staff')
                <button type="button" class="link cd-ai-run" data-ai-check="{{ route('documents.ai-check', $document->document_id) }}">Run auto-check</button>
            @else
                <small class="cd-muted" data-ai-pending="{{ route('documents.ai-check', $document->document_id) }}">Auto-check not run yet</small>
            @endif
        @elseif (($check['status'] ?? '') === 'ok')
            <small class="cd-ai-ok" title="{{ $check['summary'] ?? '' }}">✓ Auto-check: looks like {{ $check['detected_type'] ?? $document->doc_type }}@if (! empty($check['matched_numbers'])) · matches {{ implode(', ', array_slice($check['matched_numbers'], 0, 2)) }}@endif</small>
        @elseif (($check['status'] ?? '') === 'warning')
            <small class="cd-ai-warn">! {{ $audience === 'staff' ? 'Auto-check' : 'Please check this file' }}: {{ implode(' ', array_slice($check['issues'] ?? [], 0, 2)) }}</small>
        @else
            <small class="cd-muted">{{ $check['summary'] ?? 'Auto-check unavailable.' }}</small>
        @endif
        @if ($check && $audience === 'staff')
            <button type="button" class="link cd-ai-run" data-ai-check="{{ route('documents.ai-check', $document->document_id) }}">Re-check</button>
        @endif
    </div>
@endif
