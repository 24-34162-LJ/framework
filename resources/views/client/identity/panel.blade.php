{{--
    FILE PURPOSE
    File   : resources/views/client/identity/panel.blade.php
    Feature: ID Recognition
    Purpose: Client ID verification panel on My Profile: upload / live check button, status and history.
--}}

{{-- ID verification panel, shown on the client's My Profile page. Needs: $latest, $history, $companyVerified, $idTypes. --}}
@php
    $resultMessages = [
        'verified' => ['ok', 'pass', 'Your ID is verified', 'Thank you. No further action is needed.'],
        'needs_review' => [
            'info',
            'info',
            'Your ID is with our team',
            'An admin needs to check it by hand. You will get a notification when it is done.',
        ],
        'rejected' => ['bad', 'fail', 'Your ID was not accepted', 'See the reason below, then try again.'],
    ];
    $reading = $latest && $latest->status === 'processing' && !$latest->isStaleProcessing();
    $waiting = $latest && $latest->isStaleProcessing();
    // The "processing" flash is replaced by the live reading notice below.
    $result = $resultMessages[(string) session('identity_result')] ?? null;
    $live = \App\Services\Identity\DocupassService::enabled();
    $openLive = $latest && $latest->isOpenPending();
    $showForm = !$companyVerified && (!$latest || $latest->status === 'rejected' || ($latest->status === 'pending' && !$openLive));
    $reasons =
        $latest && $latest->status === 'rejected' ? ($latest->review_note ? [$latest->review_note] : $latest->checks['reasons'] ?? []) : [];
@endphp
<div id="id-verification" class="idv-group idv-scope">
    <section class="profile-card idv-main" aria-labelledby="id-status-heading">
        <header class="idv-main-head">
            <h2 id="id-status-heading">ID verification</h2>
            @if ($companyVerified)
                <span class="idv-badge idv-verified">Verified</span>
            @elseif (!$latest)
                <span class="idv-badge idv-none">Not verified</span>
            @else
                <span class="idv-badge idv-{{ $latest->status }}">{{ $latest->statusLabel() }}</span>
            @endif
        </header>

        @if (session('identity_error'))
            <div class="idv-notice idv-notice-bad" role="alert">
                @include('partials.identity-icon', ['name' => 'fail'])
                <p><strong>Something went wrong</strong>{{ session('identity_error') }}</p>
            </div>
        @endif
        @if (session('identity_pending'))
            <div class="idv-notice idv-notice-info" role="status">
                @include('partials.identity-icon', ['name' => 'info'])
                <p><strong>No result yet</strong>We could not find a finished check. Continue the live check, or try "Check my result" again
                    in a minute.</p>
            </div>
        @endif
        @if ($result)
            <div class="idv-notice idv-notice-{{ $result[0] }}" role="status">
                @include('partials.identity-icon', ['name' => $result[1]])
                <p><strong>{{ $result[2] }}</strong>{{ $result[3] }}</p>
            </div>
        @endif

        @if ($companyVerified)
            <p class="idv-status">Your company's authorized representative has been verified.</p>
        @elseif (!$latest)
            <p class="idv-status">
                {{ $live ? 'Verify your account with one government ID and a selfie.' : 'Upload one government ID to verify your account.' }}
            </p>
        @else
            <p class="idv-status idv-status-meta">
                {{ $latest->id_type ? \App\Services\Identity\PhIdRules::label($latest->id_type) . ' · uploaded' : 'Uploaded' }}
                {{ $latest->created_at?->format('M d, Y · g:i A') }}</p>
            @if ($reading)
                <div class="idv-notice idv-notice-info" id="idReading" role="status"
                    data-status-url="{{ route('client.identity.status') }}">
                    <span class="idv-spinner" aria-hidden="true"></span>
                    <p><strong>Reading your ID</strong>This usually takes under a minute. This page updates by itself, or you can leave it —
                        we will send you a notification with the result.</p>
                </div>
                <script>
                    (function() {
                        var box = document.getElementById('idReading');
                        var tries = 0;

                        function check() {
                            tries++;
                            fetch(box.dataset.statusUrl, {
                                    headers: {
                                        'Accept': 'application/json'
                                    },
                                    credentials: 'same-origin'
                                })
                                .then(function(response) {
                                    return response.ok ? response.json() : null;
                                })
                                .then(function(data) {
                                    if (data && !data.processing) {
                                        window.location.reload();
                                        return;
                                    }
                                    if (tries < 60) {
                                        setTimeout(check, tries < 10 ? 3000 : 8000);
                                    }
                                })
                                .catch(function() {
                                    if (tries < 60) {
                                        setTimeout(check, 8000);
                                    }
                                });
                        }
                        setTimeout(check, 3000);
                    })();
                </script>
            @elseif ($waiting)
                <div class="idv-notice idv-notice-info" role="status">
                    @include('partials.identity-icon', ['name' => 'info'])
                    <p><strong>Your ID is waiting in line</strong>It is taking longer than usual, so an admin will check it by hand. We will
                        send you a notification with the result.</p>
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
                            <ul>
                                @foreach ($reasons as $reason)
                                    <li>{{ $reason }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endif
        @endif
        @if ($showForm && $live)
            @php
                $retention = (int) config('services.identity.retention_days', 90);
            @endphp
            <div class="idv-live" aria-labelledby="id-live-heading" role="group">
                <div class="idv-live-intro">
                    <h3 id="id-live-heading">Live check with your camera</h3>
                    <p>Scan your ID and take a selfie on our verification partner's secure page. Takes about 2 minutes.</p>
                    <ol class="idv-steps">
                        <li><strong>Scan your ID.</strong> Hold the front of one valid government ID up to the camera.</li>
                        <li><strong>Take a selfie.</strong> We match it with the photo on your ID and check it's really you.</li>
                        <li><strong>Get your result.</strong> Usually right away, here on My Profile.</li>
                    </ol>
                </div>

                <form method="post" action="{{ route('client.identity.live') }}" class="idv-live-form" novalidate>
                    @csrf
                    <label class="profile-check idv-consent">
                        <input type="checkbox" name="live_consent" value="1" @checked(old('live_consent'))
                            @error('live_consent') aria-invalid="true" aria-describedby="liveConsentError" @enderror>
                        <span>I agree that AstraTrack may process my ID and a selfie (read by ID Analyzer, with face match and liveness
                            check) to verify my identity for customs brokerage services.
                            Images are encrypted, seen only by authorized admins, and deleted {{ $retention }} days after the decision
                            (Data Privacy Act, RA 10173).</span>
                    </label>
                    @error('live_consent')
                        <small id="liveConsentError" class="profile-error">{{ $message }}</small>
                    @enderror
                    <button type="submit" class="idv-btn">Start live ID check @include('partials.identity-icon', ['name' => 'arrow'])</button>
                    <p class="idv-hint">No good camera on this computer? The next screen has a QR code to continue on your phone.</p>
                </form>
            </div>

            <p class="idv-accepted"><strong>Accepted IDs</strong> {{ implode(' · ', $idTypes) }}</p>
        @endif
    </section>

    @if ($live && $openLive && !$companyVerified)
        @php $selfieOnly = $latest->document_source === 'digital'; @endphp
        <section class="profile-card idv-verify" aria-labelledby="id-live-open-heading">
            <div class="idv-verify-head">
                <div>
                    @if ($selfieOnly)
                        <h2 id="id-live-open-heading">
                            {{ session('identity_selfie_ready') ? 'Your digital ID is uploaded. Now take a selfie' : 'Take your selfie to finish' }}
                        </h2>
                        <p class="profile-note">Take a live selfie so we can match it with the photo on your digital ID. You can use this
                            computer's camera, or switch to your phone on the next screen.</p>
                    @else
                        <h2 id="id-live-open-heading">Finish your live ID check</h2>
                        <p class="profile-note">You started a live ID check but we have no result yet. Continue where you left off, or if
                            you already finished, check your result.</p>
                    @endif
                </div>
            </div>
            <div class="idv-actions">
                <form method="post" action="{{ route('client.identity.live') }}">
                    @csrf
                    <input type="hidden" name="live_consent" value="1">
                    <button type="submit" class="idv-btn">{{ $selfieOnly ? 'Take my selfie' : 'Continue live check' }}
                        @include('partials.identity-icon', ['name' => 'arrow'])</button>
                </form>
                <form method="post" action="{{ route('client.identity.live.check') }}">
                    @csrf
                    <button type="submit" class="profile-secondary">I'm done, check my result</button>
                </form>
            </div>
        </section>
    @endif

    @if ($showForm && !$live)
        <section class="profile-card" aria-labelledby="id-upload-heading">
            <div>
                <h2 id="id-upload-heading">{{ $latest ? 'Upload a new photo of your ID' : 'Upload your ID' }}</h2>
                <p class="profile-note">Take a clear photo of the <strong>front</strong> of one valid government ID. Lay it flat in good
                    light, with all four corners visible and no glare.</p>
                <p class="profile-note">Accepted: {{ implode(', ', $idTypes) }}.</p>
            </div>
            <form method="post" action="{{ route('client.identity.store') }}" enctype="multipart/form-data" class="profile-form"
                id="idUploadForm" novalidate>
                @csrf
                <div class="profile-form-field idv-file">
                    <label for="idImage">Photo of your ID</label>
                    <input id="idImage" name="id_image" type="file" accept="image/jpeg,image/png,image/webp" capture="environment"
                        required aria-describedby="{{ $errors->has('id_image') ? 'idImageError' : 'idImageHint' }}"
                        @error('id_image') aria-invalid="true" @enderror>
                    @error('id_image')
                        <small id="idImageError" class="profile-error">{{ $message }}</small>
                    @else
                        <small id="idImageHint">JPG, PNG or WEBP, up to 5 MB. On a phone you can take the photo directly.</small>
                    @enderror
                </div>
                <label class="profile-check idv-consent">
                    <input type="checkbox" name="consent" value="1" @checked(old('consent'))
                        @error('consent') aria-invalid="true" aria-describedby="consentError" @enderror>
                    <span>I agree that AstraTrack may process this ID to verify my identity, as required for customs brokerage services.
                        The image is read by an automated service
                        ({{ config('services.identity.reader') === 'idanalyzer' ? 'ID Analyzer' : 'Google Gemini' }}), stored encrypted,
                        viewed only by authorized admins,
                        and deleted {{ (int) config('services.identity.retention_days', 90) }} days after the decision, under the Data
                        Privacy Act of 2012 (RA 10173).</span>
                </label>
                @error('consent')
                    <small id="consentError" class="profile-error">{{ $message }}</small>
                @enderror
                <div><button type="submit" class="profile-save" id="idUploadButton">Upload and verify</button></div>
                <p class="idv-wait" id="idUploadWait" hidden role="status"><span class="idv-spinner" aria-hidden="true"></span>Uploading
                    your ID. Please keep this page open.</p>
            </form>
            <script>
                document.getElementById('idUploadForm').addEventListener('submit', function() {
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
                        <span class="idv-status-meta">{{ \App\Services\Identity\PhIdRules::label($item->id_type) }} ·
                            {{ $item->created_at?->format('M d, Y') }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
@if (session()->has('identity_result') || $errors->hasAny(['id_image', 'consent', 'live_consent']))
    <script>
        document.getElementById('id-verification').scrollIntoView({
            block: 'start'
        });
    </script>
@endif
