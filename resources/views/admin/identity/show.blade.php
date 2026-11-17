<x-app-layout title="ID Check #{{ $verification->verification_id }}" active="identity"
    :breadcrumbs="[['Dashboard', '/account-management/admin.php'], ['ID Verification', route('admin.identity.index')], ['#'.$verification->verification_id, null]]">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/shipments.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/identity.css') }}?v=2" rel="stylesheet">
    @endpush

    @php
        $fields = $verification->extracted ?? [];
        $checks = $verification->checks['items'] ?? [];
        $resultText = ['pass' => 'Passed', 'warn' => 'Needs attention', 'fail' => 'Failed'];
        $badges = ['verified' => 'badge-green', 'needs_review' => 'badge-amber', 'rejected' => 'badge-red', 'processing' => 'badge-blue'];
        $date = fn (?string $value) => $value && strtotime($value) ? \Illuminate\Support\Carbon::parse($value)->format('M d, Y') : null;
        $imageUrl = route('admin.identity.image', $verification->verification_id);
        $clientName = $verification->client?->client_name ?? 'No company';
    @endphp

    <div class="page idv-page idv-scope">
        <header class="page-head">
            <div>
                <h1>ID check #{{ $verification->verification_id }}</h1>
                <p class="sub">{{ $clientName }} · uploaded by {{ $verification->user?->full_name ?? 'unknown user' }} on {{ $verification->created_at?->format('M d, Y · g:i A') }}</p>
            </div>
            <div class="idv-head-meta">
                <span class="badge {{ $badges[$verification->status] ?? 'badge-blue' }}">{{ $verification->statusLabel() }}</span>
                <a class="btn btn-secondary" href="{{ route('admin.identity.index') }}">@include('partials.identity-icon', ['name' => 'back'])Back to list</a>
            </div>
        </header>

        <div class="idv-review">
            <section class="fcard" aria-labelledby="idv-image-heading">
                <div class="idv-card-head">
                    <h2 id="idv-image-heading">Uploaded ID</h2>
                    @if ($verification->file_path)
                        <a href="{{ $imageUrl }}" target="_blank" rel="noopener">Open full size<span class="idv-sr"> (opens in a new tab)</span></a>
                    @endif
                </div>
                <div class="idv-frame">
                    @if ($verification->file_path)
                        <img src="{{ $imageUrl }}" alt="Front of the ID uploaded by {{ $verification->user?->full_name ?? 'the client' }}" decoding="async">
                    @else
                        <p>The image was deleted on {{ $verification->file_purged_at?->format('M d, Y') }} after the retention period. The decision record is kept.</p>
                    @endif
                </div>
                @if ($verification->selfie_path)
                    <div class="idv-card-head">
                        <h3>Selfie (live check)</h3>
                        <a href="{{ route('admin.identity.image', ['verificationId' => $verification->verification_id, 'kind' => 'selfie']) }}" target="_blank" rel="noopener">Open full size<span class="idv-sr"> (opens in a new tab)</span></a>
                    </div>
                    <div class="idv-frame">
                        <img src="{{ route('admin.identity.image', ['verificationId' => $verification->verification_id, 'kind' => 'selfie']) }}" alt="Selfie taken by {{ $verification->user?->full_name ?? 'the client' }} during the live check" decoding="async">
                    </div>
                    @if (isset($fields['face_score']))
                        <p class="idv-footnote">Face match with the ID photo: {{ number_format((float) $fields['face_score'] * 100) }}%.</p>
                    @endif
                @endif
                <p class="idv-footnote">Every view of these images is recorded in Recent Activity.</p>
            </section>

            <div>
                @if ($verification->status === 'processing')
                    <div class="idv-notice idv-notice-info" role="note">
                        @include('partials.identity-icon', ['name' => 'info'])
                        <p><strong>Not read yet</strong>{{ $verification->isStaleProcessing() ? 'The background reader has not run for this ID. Check that the queue worker is running (php artisan queue:work), or decide from the image yourself.' : 'The ID is being read right now. Refresh in a moment to see the automatic checks.' }}</p>
                    </div>
                @endif
                @if ($verification->reader_error)
                    <div class="idv-notice idv-notice-bad" role="note">
                        @include('partials.identity-icon', ['name' => 'fail'])
                        <p><strong>The ID could not be read automatically</strong>{{ $verification->reader_error }}. Compare the image with the client's details yourself.</p>
                    </div>
                @endif

                <section class="fcard" aria-labelledby="idv-names-heading">
                    <h2 id="idv-names-heading">Compare details</h2>
                    <dl class="detail-grid">
                        <div><dt>Name on the ID</dt><dd>{{ $fields['full_name'] ?? 'Not read' }}</dd></div>
                        <div><dt>Account name</dt><dd>{{ $verification->user?->full_name ?? '—' }}</dd></div>
                        <div><dt>Company contact</dt><dd>{{ $verification->client?->contact_person ?: '—' }}</dd></div>
                        <div><dt>ID type</dt><dd>{{ \App\Services\Identity\PhIdRules::label($verification->id_type) }}</dd></div>
                        <div><dt>ID number</dt><dd>{{ $verification->id_number_last4 ? 'Ends in '.$verification->id_number_last4 : 'Not read' }}</dd></div>
                        <div><dt>Birth date</dt><dd>{{ $date($fields['birth_date'] ?? null) ?? 'Not read' }}</dd></div>
                        <div><dt>Expiry</dt><dd>{{ $date($fields['expiry_date'] ?? null) ?? 'None shown' }}</dd></div>
                        <div><dt>Automatic result</dt><dd>{{ $verification->auto_decision ? ucfirst(str_replace('_', ' ', $verification->auto_decision)) : 'Not read' }}</dd></div>
                    </dl>
                </section>

                @if ($checks)
                    <section class="fcard" aria-labelledby="idv-checks-heading">
                        <h2 id="idv-checks-heading">Automatic checks</h2>
                        <ul class="idv-checks">
                            @foreach ($checks as $check)
                                <li class="idv-check-{{ $check['result'] }}">
                                    @include('partials.identity-icon', ['name' => $check['result']])
                                    <span class="idv-check-label">{{ $check['label'] }}<span class="idv-sr">: {{ $resultText[$check['result']] ?? $check['result'] }}.</span></span>
                                    <span class="idv-check-detail">{{ $check['detail'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <section class="fcard" aria-labelledby="idv-decision-heading">
                    <h2 id="idv-decision-heading">Decision</h2>
                    @if ($verification->reviewed_at)
                        <p class="idv-decided">{{ $verification->statusLabel() }} by {{ $verification->reviewer?->full_name ?? 'an admin' }} on {{ $verification->reviewed_at->format('M d, Y · g:i A') }}{{ $verification->review_note ? ' — “'.$verification->review_note.'”' : '' }}. You can change the decision below.</p>
                    @endif
                    <form method="post" action="{{ route('admin.identity.decide', $verification->verification_id) }}" class="idv-decision" novalidate>
                        @csrf
                        <div class="idv-field">
                            <label for="reviewNote">Note to the client</label>
                            <textarea id="reviewNote" name="review_note" maxlength="255" aria-describedby="{{ $errors->has('review_note') ? 'reviewNoteError' : 'reviewNoteHint' }}" @error('review_note') aria-invalid="true" @enderror>{{ old('review_note') }}</textarea>
                            @error('review_note')
                                <small id="reviewNoteError" class="idv-error">{{ $message }}</small>
                            @else
                                <small id="reviewNoteHint">Required when rejecting. The client sees this note, e.g. “The photo is blurry — please retake it in good light.”</small>
                            @enderror
                        </div>
                        <div class="idv-actions">
                            <button type="submit" name="decision" value="verified" class="btn btn-primary">Approve ID</button>
                            <button type="submit" name="decision" value="rejected" class="btn idv-btn-danger">Reject ID</button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
