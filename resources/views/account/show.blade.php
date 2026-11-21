{{--
    FILE PURPOSE
    File   : resources/views/account/show.blade.php
    Feature: Account
    Purpose: My Account page for admins and staff: profile, editable details, SMS switch,
             password change and the user's own recent activity.
--}}

@php
    $initials = collect(preg_split('/\s+/', trim((string) $user->full_name)))
        ->filter()
        ->take(2)
        ->map(fn($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
    $detailErrors = $errors->getBag('details');
    $passwordErrors = $errors->getBag('password');
@endphp

<x-app-layout title="My Account" active="account">
    @push('styles')
        <link href="{{ asset('assets/css/staff.css') }}?v=10" rel="stylesheet">
        <link href="{{ asset('assets/css/shipments.css') }}?v=11" rel="stylesheet">
        <link href="{{ asset('assets/css/account.css') }}?v=1" rel="stylesheet">
    @endpush

    <div class="page acct-page">
        <header class="page-head">
            <div>
                <h1>My Account</h1>
            </div>
        </header>

        <section class="acct-card acct-hero" aria-label="Account summary">
            <span class="acct-avatar" aria-hidden="true">{{ $initials !== '' ? $initials : 'U' }}</span>
            <div class="acct-hero-text">
                <h2>{{ $user->full_name }}</h2>
                <p>{{ $user->email }}</p>
                <div class="acct-badges">
                    <span class="acct-badge acct-badge-role">{{ ucfirst($user->role) }}</span>
                    <span
                        class="acct-badge acct-badge-{{ $user->status === 'active' ? 'active' : 'inactive' }}">{{ ucfirst($user->status) }}</span>
                </div>
            </div>
            <dl class="acct-hero-facts">
                <div>
                    <dt>Member since</dt>
                    <dd>{{ $user->created_at ? \Illuminate\Support\Carbon::parse($user->created_at)->format('M d, Y') : '—' }}</dd>
                </div>
                @if ($openShipments !== null)
                    <div>
                        <dt>Open shipments</dt>
                        <dd><a href="{{ route('staff.shipments') }}">{{ $openShipments }}</a></dd>
                    </div>
                @endif
            </dl>
        </section>

        <div class="acct-grid">
            <section class="acct-card" id="details" aria-labelledby="acctDetailsTitle">
                <header class="acct-card-head">
                    <h2 id="acctDetailsTitle">Account details</h2>
                    <p>Your name appears on shipment updates and activity logs.</p>
                </header>
                @if (session('account_success'))
                    <p class="acct-alert acct-alert-ok" role="status">{{ session('account_success') }}</p>
                @endif
                <form method="post" action="{{ route('account.details') }}" class="acct-form" novalidate>
                    @csrf
                    <label>Full name
                        <input type="text" name="full_name" value="{{ old('full_name', $user->full_name) }}" maxlength="100" required
                            autocomplete="name" @if ($detailErrors->has('full_name')) aria-invalid="true" @endif>
                        @if ($detailErrors->has('full_name'))
                            <span class="acct-error">{{ $detailErrors->first('full_name') }}</span>
                        @endif
                    </label>
                    <label>Email
                        <input type="email" value="{{ $user->email }}" readonly aria-readonly="true">
                        <span
                            class="acct-hint">{{ $user->role === 'admin' ? 'Change emails in User Management.' : 'Ask an administrator to change your email.' }}</span>
                    </label>
                    <label>Mobile number
                        <input type="tel" name="phone" value="{{ old('phone', $user->phone) }}" maxlength="20"
                            placeholder="09171234567" autocomplete="tel" @if ($detailErrors->has('phone')) aria-invalid="true" @endif>
                        @if ($detailErrors->has('phone'))
                            <span class="acct-error">{{ $detailErrors->first('phone') }}</span>
                        @endif
                    </label>
                    <label class="acct-switch">
                        <input type="checkbox" name="sms_enabled" value="1" @checked(old('sms_enabled', $user->sms_enabled && $user->phone))>
                        <span class="acct-switch-track" aria-hidden="true"></span>
                        <span><strong>SMS notifications</strong><small>Texts to the mobile number above. Needs a number to turn
                                on.</small></span>
                    </label>
                    <div class="acct-actions"><button type="submit" class="btn btn-primary">Save details</button></div>
                </form>
            </section>

            <section class="acct-card" id="password" aria-labelledby="acctPasswordTitle">
                <header class="acct-card-head">
                    <h2 id="acctPasswordTitle">Change password</h2>
                    <p>Changing it signs out "Remember me" on your other devices.</p>
                </header>
                @if (session('password_success'))
                    <p class="acct-alert acct-alert-ok" role="status">{{ session('password_success') }}</p>
                @endif
                <form method="post" action="{{ route('account.password') }}" class="acct-form" novalidate>
                    @csrf
                    <label>Current password
                        <input type="password" name="current_password" required autocomplete="current-password"
                            @if ($passwordErrors->has('current_password')) aria-invalid="true" @endif>
                        @if ($passwordErrors->has('current_password'))
                            <span class="acct-error">{{ $passwordErrors->first('current_password') }}</span>
                        @endif
                    </label>
                    <label>New password
                        <input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password"
                            @if ($passwordErrors->has('password')) aria-invalid="true" @endif>
                        @if ($passwordErrors->has('password'))
                        <span class="acct-error">{{ $passwordErrors->first('password') }}</span>@else<span class="acct-hint">8 to 72
                                characters.</span>
                        @endif
                    </label>
                    <label>Confirm new password
                        <input type="password" name="password_confirmation" required minlength="8" maxlength="72"
                            autocomplete="new-password">
                    </label>
                    <div class="acct-actions"><button type="submit" class="btn btn-primary">Change password</button></div>
                </form>
            </section>
        </div>

        <section class="acct-card" aria-labelledby="acctActivityTitle">
            <header class="acct-card-head acct-card-head-row">
                <div>
                    <h2 id="acctActivityTitle">My recent activity</h2>
                    <p>Your last 10 actions.</p>
                </div>
                @if ($user->role === 'admin')
                    <a class="acct-link" href="{{ route('admin.activity', ['q' => $user->full_name]) }}">View all</a>
                @endif
            </header>
            @if ($activity->isEmpty())
                <p class="acct-empty">No activity recorded yet.</p>
            @else
                <ol class="acct-activity">
                    @foreach ($activity as $entry)
                        <li>
                            <span class="acct-activity-type">{{ $entry->activity_type }}</span>
                            <span class="acct-activity-text">{{ $entry->description }}</span>
                            <time
                                datetime="{{ $entry->created_at?->toIso8601String() }}">{{ $entry->created_at?->format('M d, Y · g:i A') ?? '—' }}</time>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    </div>
</x-app-layout>
