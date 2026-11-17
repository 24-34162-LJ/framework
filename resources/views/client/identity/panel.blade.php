{{-- ID verification panel, shown on the client's My Profile page. Needs: $latest, $history, $companyVerified, $idTypes. --}}
@php
    $resultMessages = [
        'verified' => ['ok', 'pass', 'Your ID is verified', 'Thank you. No further action is needed.'],
        'needs_review' => ['info', 'info', 'Your ID is with our team', 'An admin needs to check it by hand. You will get a notification when it is done.'],
        'rejected' => ['bad', 'fail', 'Your ID was not accepted', 'See the reason below, then upload a new photo.'],
    ];
    $reading = $latest && $latest->status === 'processing' && ! $latest->isStaleProcessing();
    $waiting = $latest && $latest->isStaleProcessing();
    // The "processing" flash is replaced by the live reading notice below.
    $result = $resultMessages[session('identity_result')] ?? null;
    $showForm = ! $companyVerified && (! $latest || $latest->status === 'rejected');
    $reasons = $latest && $latest->status === 'rejected'
        ? ($latest->review_note ? [$latest->review_note] : ($latest->checks['reasons'] ?? []))
        : [];
@endphp
<div id="id-verification" class="idv-group idv-scope">
    <section class="profile-card" aria-labelledby="id-status-heading">
        <h2 id="id-status-heading">ID verification</h2>

        @if ($result)
            <div class="idv-notice idv-notice-{{ $result[0] }}" role="status">
                @include('partials.identity-icon', ['name' => $result[1]])
                <p><strong>{{ $result[2] }}</strong>{{ $result[3] }}</p>
            </div>
        @endif

        @if ($companyVerified)
            <p class="idv-status"><span class="idv-badge idv-verified">Verified</span><span>Your company's authorized representative has been verified.</span></p>
        @elseif (! $latest)
            <p class="idv-status"><span class="idv-badge idv-none">Not verified</span><span>Upload one government ID to verify your account.</span></p>
        @else
            <p class="idv-status">
                <span class="idv-badge idv-{{ $latest->status }}">{{ $latest->statusLabel() }}</span>
                <span class="idv-status-meta">{{ $latest->id_type ? \App\Services\Identity\PhIdRules::label($latest->id_type).' · uploaded' : 'Uploaded' }} {{ $latest->created_at?->format('M d, Y · g:i A') }}</span>
            </p>
            @if ($reading)
                <div class="idv-notice idv-notice-info" id="idReading" role="status" data-status-url="{{ route('client.identity.status') }}">
                    <span class="idv-spinner" aria-hidden="true"></span>
                    <p><strong>Reading your ID</strong>This usually takes under a minute. This page updates by itself, or you can leave it — we will send you a notification with the result.</p>
                </div>
                <script>
                    (function () {
                        var box = document.getElementById('idReading');
                        var tries = 0;
                        function check() {
                            tries++;
                            fetch(box.dataset.statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                                .then(function (response) { return response.ok ? response.json() : null; })
                                .then(function (data) {
                                    if (data && !data.processing) { window.location.reload(); return; }
                                    if (tries < 60) { setTimeout(check, tries < 10 ? 3000 : 8000); }
                                })
                                .catch(function () { if (tries < 60) { setTimeout(check, 8000); } });
                        }
                        setTimeout(check, 3000);
                    })();
                </script>
            @elseif ($waiting)
                <div class="idv-notice idv-notice-info" role="status">
                    @include('partials.identity-icon', ['name' => 'info'])
                    <p><strong>Your ID is waiting in line</strong>It is taking longer than usual, so an admin will check it by hand. We will send you a notification with the result.</p>
                </div>
            @endif
            @if ($reasons)
                <div class="idv-notice idv-notice-bad">
                    @include('partials.identity-icon', ['name' => 'fail'])
                    <div>
                        <strong>Why it was not accepted</strong>
                        @if (count($reasons) === 1)
                            <p>{{ $reasons[0] }}</p>
                        @else
                            <ul>@foreach ($reasons as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                        @endif
                    </div>
                </div>
            @endif
        @endif
    </section>

    @if ($showForm)
        <section class="profile-card" aria-labelledby="id-upload-heading">
            <div>
                <h2 id="id-upload-heading">{{ $latest ? 'Upload a new photo of your ID' : 'Upload your ID' }}</h2>
                <p class="profile-note">Take a clear photo of the <strong>front</strong> of one valid government ID. Lay it flat in good light, with all four corners visible and no glare.</p>
                <p class="profile-note">Accepted: {{ implode(', ', $idTypes) }}.</p>
            </div>
            <form method="post" action="{{ route('client.identity.store') }}" enctype="multipart/form-data" class="profile-form" id="idUploadForm" novalidate>
                @csrf
                <div class="profile-form-field idv-file">
                    <label for="idImage">Photo of your ID</label>
                    <input id="idImage" name="id_image" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" required
                        aria-describedby="{{ $errors->has('id_image') ? 'idImageError' : 'idImageHint' }}" @error('id_image') aria-invalid="true" @enderror>
                    @error('id_image')
                        <small id="idImageError" class="profile-error">{{ $message }}</small>
                    @else
                        <small id="idImageHint">JPG, PNG or WEBP, up to 5 MB. On a phone you can take the photo directly.</small>
                    @enderror
                </div>
                <label class="profile-check idv-consent">
                    <input type="checkbox" name="consent" value="1" @checked(old('consent')) @error('consent') aria-invalid="true" aria-describedby="consentError" @enderror>
                    <span>I agree that AstraTrack may process this ID to verify my identity, as required for customs brokerage services.
                        The image is read by an automated service ({{ config('services.identity.reader') === 'idanalyzer' ? 'ID Analyzer' : 'Google Gemini' }}), stored encrypted, viewed only by authorized admins,
                        and deleted {{ (int) config('services.identity.retention_days', 90) }} days after the decision, under the Data Privacy Act of 2012 (RA 10173).</span>
                </label>
                @error('consent')<small id="consentError" class="profile-error">{{ $message }}</small>@enderror
                <div><button type="submit" class="profile-save" id="idUploadButton">Upload and verify</button></div>
                <p class="idv-wait" id="idUploadWait" hidden role="status"><span class="idv-spinner" aria-hidden="true"></span>Uploading your ID. Please keep this page open.</p>
            </form>
            <script>
                document.getElementById('idUploadForm').addEventListener('submit', function () {
                    var button = document.getElementById('idUploadButton');
                    button.disabled = true;
                    button.textContent = 'Uploading…';
                    document.getElementById('idUploadWait').hidden = false;
                });
            </script>
        </section>
    @endif

    @if ($history->count() > 1)
        <section class="profile-card" aria-labelledby="id-history-heading">
            <h2 id="id-history-heading">Previous uploads</h2>
            <ul class="idv-history">
                @foreach ($history->skip(1) as $item)
                    <li><span class="idv-badge idv-{{ $item->status }}">{{ $item->statusLabel() }}</span>
                        <span class="idv-status-meta">{{ \App\Services\Identity\PhIdRules::label($item->id_type) }} · {{ $item->created_at?->format('M d, Y') }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
@if (session()->has('identity_result') || $errors->hasAny(['id_image', 'consent']))
    <script>document.getElementById('id-verification').scrollIntoView({ block: 'start' });</script>
@endif
