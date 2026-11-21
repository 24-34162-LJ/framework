{{--
    FILE PURPOSE
    File   : resources/views/auth/login.blade.php
    Feature: Login / Auth
    Purpose: Login page.
--}}

<!doctype html>
<html lang="en" data-type="service" data-radius="soft" data-buttons="tonal" data-motion>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Sign in: Astra Cargo client portal</title>
    <meta name="description" content="Sign in to the Astra Cargo portal to follow your shipments, upload documents and see your bills.">
    <meta name="robots" content="noindex">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('assets/images/favicon-32.png') }}?v=2">
    <script>
        // reveal: set before the first paint so the card's parts start hidden instead of flashing; if the reveal
        // never starts (script blocked or failed), everything shows after 3s
        (function() {
            var html = document.documentElement;
            if (html.getAttribute('data-motion') === 'off' || !('IntersectionObserver' in window) || matchMedia(
                    '(prefers-reduced-motion: reduce)').matches) return;
            html.classList.add('reveal-on');
            setTimeout(function() {
                if (!window.__reveal) html.classList.remove('reveal-on');
            }, 3000);
        })();
    </script>
    @include('auth.partials.styles')
</head>

<body>
    <div class="auth">
        <div class="auth__bar">
            <a class="nav__logo" href="/"><img src="{{ asset('assets/images/astra-logo-64.png') }}" alt="" width="28"
                    height="28">Astra Cargo</a>
            <a class="auth__back" href="/">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M19 12H5" />
                    <path d="M12 19l-7-7 7-7" />
                </svg>
                Back to home
            </a>
        </div>

        <main class="auth__center">
            <div class="card">
                <div class="card__inner">
                    <div class="card__head">
                        <div class="card__logo"><img src="{{ asset('assets/images/astra-logo-64.png') }}" alt="" width="36"
                                height="36"></div>
                        <h1>Sign in</h1>
                    </div>

                    @if ($error)
                        <div class="auth__alert" id="authError" role="alert">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="10" />
                                <path d="M12 8v4" />
                                <path d="M12 16h.01" />
                            </svg>
                            <span>{{ $error }}</span>
                        </div>
                    @endif
                    @if (! $error && ! empty($notice))
                        <div class="auth__alert auth__alert--ok" role="status"><span>{{ $notice }}</span></div>
                    @endif

                    <form class="auth__form" method="post" action="" id="loginForm" novalidate>
                        <input type="hidden" name="csrf" value="{{ csrf_token() }}">

                        <label class="field" for="login">Email or username
                            <input type="text" id="login" name="login" value="{{ $old }}"
                                placeholder="Enter your email or username" autocomplete="username" autocapitalize="none" spellcheck="false"
                                required autofocus{!! $error ? ' aria-describedby="authError"' : '' !!}>
                            <span class="invalid-feedback" id="loginMsg">Enter your email or username.</span>
                        </label>

                        <div class="field">
                            <label for="password">Password</label>
                            <div class="password-wrap">
                                <input type="password" id="password" name="password" placeholder="Enter your password"
                                    autocomplete="current-password" required>
                                <button type="button" class="toggle-pass" id="togglePass" aria-label="Show password" aria-pressed="false">
                                    <svg id="eyeIcon" width="18" height="18" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        aria-hidden="true">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                </button>
                            </div>
                            <span class="invalid-feedback" id="passMsg">Enter your password.</span>
                            <span class="caps-hint" id="capsHint" role="status" aria-live="polite"></span>
                        </div>

                        <div class="auth__row">
                            <label class="auth__check" for="remember"><input type="checkbox" id="remember" name="remember"
                                    value="1">Remember me</label>
                            <a class="auth__link" href="{{ route('password.request') }}">Forgot password?</a>
                        </div>

                        <button type="submit" class="auth__submit" id="loginBtn">
                            <span class="btn-text">Sign in</span>
                            <span class="btn-loading" hidden><span class="spin" aria-hidden="true"></span>Signing in</span>
                        </button>
                    </form>
                </div>
            </div>
        </main>

        <p class="auth__foot">&copy; {{ date('Y') }} Astra Cargo Impex Solutions OPC</p>
    </div>

    <script type="module" src="{{ asset('assets/taste/system.js') }}"></script>
    <script type="module" src="{{ asset('assets/taste/motion/motion.js') }}"></script>
    <script src="{{ asset('assets/js/login.js') }}?v=13"></script>
    <script>
        // reveal: each part of the card pops in the first time it is on screen, in order, 70ms apart, after the
        // card itself has started to rise; a part below the fold (a short screen) pops in when scrolled to
        (function() {
            var html = document.documentElement;
            if (!html.classList.contains('reveal-on')) return;
            window.__reveal = true;
            var els = [].slice.call(document.querySelectorAll(
                '.card__logo, .card__head h1, .auth__form > .field, .auth__form > .auth__row, .auth__form > .auth__submit, .auth__foot'
                ));
            var start = performance.now(),
                queue = 0;
            var io = new IntersectionObserver(function(entries) {
                entries.forEach(function(e) {
                    if (!e.isIntersecting) return;
                    io.unobserve(e.target);
                    var el = e.target;
                    // the first wave waits for the card (180ms in), then each part follows 70ms after the last
                    var delay = Math.max(0, 180 - (performance.now() - start)) + Math.min(queue++, 7) * 70;
                    el.classList.add('is-in');
                    if (typeof el.animate === 'function') {
                        el.animate([{
                                opacity: 0,
                                transform: 'translateY(16px) scale(.97)'
                            },
                            {
                                opacity: 1,
                                transform: 'none'
                            }
                        ], {
                            duration: 620,
                            delay: delay,
                            easing: 'cubic-bezier(.16, 1, .3, 1)',
                            fill: 'backwards'
                        });
                    }
                    setTimeout(function() {
                        queue = Math.max(0, queue - 1);
                    }, delay + 100);
                });
            }, {
                threshold: 0.2
            });
            els.forEach(function(el) {
                io.observe(el);
            });
        })();
    </script>
</body>

</html>
