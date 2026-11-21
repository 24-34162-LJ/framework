{{--
    FILE PURPOSE
    File   : resources/views/auth/password.blade.php
    Feature: Login / Auth
    Purpose: "Forgot password" (ask for a reset link) and "Reset password" (choose a new password)
             pages. $mode is 'forgot' or 'reset'.
--}}

@php($isReset = $mode === 'reset')
<!doctype html>
<html lang="en" data-type="service" data-radius="soft" data-buttons="tonal" data-motion="off">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $isReset ? 'Reset password' : 'Forgot password' }}: Astra Cargo client portal</title>
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('assets/images/favicon-32.png') }}?v=2">
    @include('auth.partials.styles')
</head>

<body>
    <div class="auth">
        <div class="auth__bar">
            <a class="nav__logo" href="/"><img src="{{ asset('assets/images/astra-logo-64.png') }}" alt="" width="28"
                    height="28">Astra Cargo</a>
            <a class="auth__back" href="/login.php">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M19 12H5" />
                    <path d="M12 19l-7-7 7-7" />
                </svg>
                Back to sign in
            </a>
        </div>

        <main class="auth__center">
            <div class="card">
                <div class="card__inner">
                    <div class="card__head">
                        <div class="card__logo"><img src="{{ asset('assets/images/astra-logo-64.png') }}" alt="" width="36"
                                height="36"></div>
                        <h1>{{ $isReset ? 'Choose a new password' : 'Forgot password' }}</h1>
                    </div>

                    @if ($status)
                        <div class="auth__alert auth__alert--ok" role="status"><span>{{ $status }}</span></div>
                    @elseif (! $isReset)
                        <p class="auth__sub">Enter the email address of your account and we will send you a link to choose a new password.</p>
                    @endif

                    @if ($isReset)
                        <form class="auth__form" method="post" action="{{ route('password.update') }}" novalidate>
                            @csrf
                            <input type="hidden" name="token" value="{{ $token }}">

                            <label class="field" for="email">Email
                                <input type="email" id="email" name="email" value="{{ old('email', $email) }}" autocomplete="username"
                                    required @error('email') aria-invalid="true" aria-describedby="emailErr" @enderror>
                                @error('email')<span class="auth__error" id="emailErr">{{ $message }}</span>@enderror
                            </label>

                            <label class="field" for="password">New password
                                <input type="password" id="password" name="password" autocomplete="new-password" required
                                    minlength="{{ \App\Rules\StrongPassword::MIN }}" maxlength="{{ \App\Rules\StrongPassword::MAX }}"
                                    @error('password') aria-invalid="true" aria-describedby="passErr" @enderror autofocus>
                                @error('password')
                                    <span class="auth__error" id="passErr">{{ $message }}</span>
                                @else
                                    <span class="auth__hint">{{ \App\Rules\StrongPassword::HINT }}</span>
                                @enderror
                            </label>

                            <label class="field" for="password_confirmation">Confirm new password
                                <input type="password" id="password_confirmation" name="password_confirmation"
                                    autocomplete="new-password" required maxlength="{{ \App\Rules\StrongPassword::MAX }}">
                            </label>

                            <button type="submit" class="auth__submit"><span class="btn-text">Save new password</span></button>
                        </form>
                    @else
                        <form class="auth__form" method="post" action="{{ route('password.email') }}" novalidate>
                            @csrf
                            <label class="field" for="email">Email
                                <input type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="username"
                                    autocapitalize="none" spellcheck="false" required autofocus
                                    @error('email') aria-invalid="true" aria-describedby="emailErr" @enderror>
                                @error('email')<span class="auth__error" id="emailErr">{{ $message }}</span>@enderror
                            </label>

                            <button type="submit" class="auth__submit"><span class="btn-text">Send reset link</span></button>
                        </form>
                    @endif

                    <p class="auth__center-link"><a class="auth__link" href="/login.php">Back to sign in</a></p>
                </div>
            </div>
        </main>

        <p class="auth__foot">&copy; {{ date('Y') }} Astra Cargo Impex Solutions OPC</p>
    </div>
</body>

</html>
