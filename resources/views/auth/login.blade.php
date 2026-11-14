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
(function () {
  var html = document.documentElement;
  if (html.getAttribute('data-motion') === 'off' || !('IntersectionObserver' in window) || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  html.classList.add('reveal-on');
  setTimeout(function () { if (!window.__reveal) html.classList.remove('reveal-on'); }, 3000);
})();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400..700&family=Libre+Franklin:wght@400..600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('assets/taste/system.css') }}">
<link rel="stylesheet" href="{{ asset('assets/taste/motion/motion.css') }}">
<style>
:root {
  --p-bg: #f0f3fa; --p-surface: #d5deef; --p-ink: #1e2f4d; --p-muted: #4f5f78;
  --p-accent: #395886; --p-accent-ink: #f0f3fa; --p-action: #395886; --p-action-ink: #f0f3fa;
  --p-inv-bg: #1e2f4d; --p-inv-surface: #2b4269; --p-inv-ink: #f0f3fa; --p-inv-muted: #b1c9ef;
  --p-inv-accent: #f0f3fa; --p-inv-accent-ink: #1e2f4d; --p-inv-action: #f0f3fa; --p-inv-action-ink: #1e2f4d;
  --font-display: "Source Serif 4", Georgia, serif;
  --font-text: "Libre Franklin", system-ui, sans-serif;
  --display-weight: 400;
  --display-tracking: -0.02em;
  --display-tracking-xl: -0.026em;
  --danger: #a3341f;
  --line: color-mix(in oklab, #1e2f4d 22%, transparent);
}

/* the sign-in screen: one centred card on the landing page's warm stone colour, no photograph */
html, body { min-height: 100%; }
body {
  margin: 0;
  background-color: #f0f3fa;
  background-image:
    radial-gradient(120% 70% at 50% -10%, color-mix(in oklab, #395886 14%, #f0f3fa) 0%, transparent 60%),
    radial-gradient(90% 60% at 50% 115%, #d5deef 0%, transparent 70%);
  background-attachment: fixed;
}
.auth { position: relative; min-height: 100svh; display: grid; grid-template-rows: auto 1fr auto; padding: var(--s-5) var(--gut, 16px); overflow: hidden; isolation: isolate; }

/* the thin concentric arcs behind the card, drawn in CSS so no image is needed */
.auth::before {
  content: ""; position: absolute; left: 50%; top: 58%; z-index: -1;
  width: 1500px; aspect-ratio: 1; transform: translate(-50%, -50%);
  border-radius: 50%; pointer-events: none;
  background: repeating-radial-gradient(circle at center, transparent 0 139px, color-mix(in oklab, #ffffff 85%, transparent) 139px 140px, transparent 140px 150px);
  -webkit-mask-image: radial-gradient(circle at center, transparent 30%, #000 42%, transparent 72%);
          mask-image: radial-gradient(circle at center, transparent 30%, #000 42%, transparent 72%);
  opacity: .9;
}

.auth__bar { display: flex; align-items: center; justify-content: space-between; gap: var(--s-4); min-height: 48px; max-width: 1200px; width: 100%; margin: 0 auto; }
.auth__bar .nav__logo { display: inline-flex; align-items: center; gap: var(--s-2); color: var(--ink); text-decoration: none; font-weight: 500; }
.auth__bar .nav__logo img { width: 28px; height: 28px; object-fit: contain; }
.auth__back { display: inline-flex; align-items: center; gap: 6px; min-height: 44px; padding-inline: var(--s-3); border-radius: 999px; color: var(--ink); font-size: 14px; text-decoration: none; transition: background .2s; }
.auth__back:hover { background: color-mix(in oklab, #ffffff 60%, transparent); }

.auth__center { display: grid; place-items: center; padding-block: var(--s-6); }

/* the card: a frosted panel inside a white rim, like the reference */
.card {
  width: min(100%, 460px);
  padding: 8px;
  border-radius: 36px;
  background: color-mix(in oklab, #ffffff 92%, transparent);
  box-shadow: 0 1px 0 #fff inset, 0 30px 60px -30px rgba(27,42,69, .28), 0 12px 24px -16px rgba(27,42,69, .18);
}
.card__inner {
  border-radius: 28px;
  padding: clamp(28px, 6vw, 44px) clamp(22px, 6vw, 44px) clamp(28px, 6vw, 40px);
  background: linear-gradient(180deg, color-mix(in oklab, #395886 10%, #f5f7fc) 0%, #f5f7fc 38%, #f5f7fc 100%);
}

.card__head { text-align: center; margin-bottom: var(--s-6); }
.card__logo { width: 64px; height: 64px; margin: 0 auto var(--s-4); display: grid; place-items: center; border-radius: 50%; background: #f0f3fa; box-shadow: 0 0 0 4px #fff, 0 6px 16px -6px rgba(27,42,69, .25); }
.card__logo img { width: 36px; height: 36px; object-fit: contain; }
.card__head h1 { font-family: var(--font-text); font-weight: 600; font-size: 26px; letter-spacing: -0.01em; line-height: 1.2; margin: 0 0 6px; color: var(--ink); }
.card__head p { margin: 0; color: var(--muted); font-size: 15px; }

.auth__form { display: grid; gap: var(--s-4); }
.auth__form .field { display: grid; gap: 8px; font-size: 14px; font-weight: 600; color: var(--ink); }
.auth__form .field input {
  width: 100%; box-sizing: border-box; height: 50px; padding: 0 16px;
  font: 400 16px/1 var(--font-text); color: var(--ink);
  background: color-mix(in oklab, #ffffff 70%, transparent);
  border: 1.5px solid var(--line); border-radius: 14px;
  caret-color: var(--action);
  transition: border-color .15s, box-shadow .15s, background .15s;
}
.auth__form .field input:hover { border-color: color-mix(in oklab, #1e2f4d 38%, transparent); }
.auth__form .field input:focus { outline: none; background: #fff; border-color: var(--action); box-shadow: 0 0 0 4px color-mix(in oklab, #395886 18%, transparent); }
.auth__form .field input::placeholder { color: color-mix(in oklab, var(--muted) 70%, #fff); }

.password-wrap { position: relative; }
.password-wrap input { padding-right: 52px !important; }
.toggle-pass { position: absolute; top: 3px; right: 3px; width: 44px; height: 44px; display: grid; place-items: center; border: 0; background: none; color: var(--muted); border-radius: 11px; cursor: pointer; }
.toggle-pass:hover { color: var(--ink); }
.toggle-pass:focus-visible { outline: 2px solid var(--action); outline-offset: -2px; }

.invalid-feedback, .caps-hint { font-size: 13px; font-weight: 400; }
.invalid-feedback { display: none; color: var(--danger); }
.auth__form .field input.is-invalid { border-color: var(--danger); }
.field input.is-invalid ~ .invalid-feedback, .password-wrap.has-error ~ .invalid-feedback { display: block; }
.password-wrap.has-error input { border-color: var(--danger); }
/* a field that needs a value keeps its red while focused: the focus ring takes the danger tone instead of the blue */
.auth__form .field input.is-invalid:focus, .password-wrap.has-error input:focus { border-color: var(--danger); box-shadow: 0 0 0 4px color-mix(in oklab, var(--danger) 16%, transparent); }
html { scrollbar-color: color-mix(in oklab, #1e2f4d 32%, transparent) transparent; }
.caps-hint { color: var(--muted); }
.caps-hint:empty { display: none; }

.auth__row { display: flex; align-items: center; justify-content: space-between; gap: var(--s-3); }
.auth__check { display: inline-flex; align-items: center; gap: 10px; font-size: 15px; min-height: 44px; cursor: pointer; color: var(--ink); }
.auth__check input { width: 20px; height: 20px; margin: 0; accent-color: #1e2f4d; cursor: pointer; }

.auth__submit {
  width: 100%; height: 52px; margin-top: var(--s-2);
  display: inline-flex; align-items: center; justify-content: center;
  border: 0; border-radius: 14px; cursor: pointer;
  background: #1e2f4d; color: #f5f7fc;
  font: 500 16px/1 var(--font-text);
  box-shadow: 0 8px 18px -10px rgba(27,42,69, .6);
  transition: background .15s, transform .15s;
}
.auth__submit:hover { background: #2b4269; }
.auth__submit:active { transform: translateY(1px); }
.auth__submit:focus-visible { outline: 3px solid color-mix(in oklab, #395886 50%, transparent); outline-offset: 2px; }
.auth__submit:disabled { cursor: progress; opacity: .9; }
.btn-loading { display: inline-flex; align-items: center; gap: var(--s-2); }
.btn-loading[hidden], .btn-text[hidden] { display: none; }
.spin { width: 14px; height: 14px; border: 2px solid currentColor; border-right-color: transparent; border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.auth__alert { display: flex; gap: var(--s-2); align-items: flex-start; padding: var(--s-3) var(--s-4); margin: 0 0 var(--s-5); border: 1px solid color-mix(in oklab, var(--danger) 35%, transparent); border-radius: 14px; background: color-mix(in oklab, var(--danger) 7%, #fff); color: var(--danger); font-size: 14px; }
.auth__alert svg { flex: none; margin-top: 2px; }

.auth__foot { text-align: center; font-size: 13px; color: var(--muted); margin: 0; }

@media (max-width: 639px) {
  .auth { padding: var(--s-3) 16px var(--s-4); }
  .auth__center { place-items: start center; padding-block: var(--s-4); }
  .card { border-radius: 30px; padding: 6px; }
  .card__inner { border-radius: 24px; }
  .auth::before { width: 900px; top: 45%; }
}
@media (prefers-reduced-motion: reduce) { .spin { animation-duration: 2s; } }

/* ---------------------------------------------------------------- motion
   one idea, a tracking signal: on arrival the arcs ripple out from behind the card and the logo sends
   one ping; while the sign-in is on its way the logo keeps pinging. Everything else is feedback:
   messages ease in, the loading words cross-fade, a field that needs a value shakes (login.js). */
.card__logo { position: relative; }
.card__logo::after {
  content: ""; position: absolute; inset: 0; border-radius: 50%; pointer-events: none;
  box-shadow: 0 0 0 2px color-mix(in oklab, #395886 55%, transparent);
  opacity: 0;
}
@keyframes auth-ripple { from { opacity: 0; transform: translate(-50%, -50%) scale(.82); } }
@keyframes auth-card { from { opacity: 0; transform: translateY(14px) scale(.985); } }
@keyframes auth-ping { 0% { opacity: .9; transform: scale(1); } 100% { opacity: 0; transform: scale(1.8); } }
@keyframes auth-msg { from { opacity: 0; transform: translateY(-4px); } }
@keyframes auth-fade { from { opacity: 0; } }
@media (prefers-reduced-motion: no-preference) {
  .auth::before { animation: auth-ripple 1400ms cubic-bezier(.16, 1, .3, 1) both; }
  .card { animation: auth-card 640ms cubic-bezier(.16, 1, .3, 1) 60ms both; }
  .card__logo::after { animation: auth-ping 1100ms cubic-bezier(.16, 1, .3, 1) 520ms 1 forwards; }
  .card:has(form[aria-busy="true"]) .card__logo::after { animation: auth-ping 1300ms cubic-bezier(.16, 1, .3, 1) infinite; }
  .auth__alert { animation: auth-msg 280ms cubic-bezier(.16, 1, .3, 1) 420ms both; }
  .field input.is-invalid ~ .invalid-feedback, .password-wrap.has-error ~ .invalid-feedback, .caps-hint:not(:empty) { animation: auth-msg 200ms cubic-bezier(.16, 1, .3, 1) both; }
  .auth__submit .btn-loading:not([hidden]) { animation: auth-fade 160ms ease-out both; }
  .auth__submit { transition: background .15s, transform .15s, box-shadow .2s; }
  .auth__submit:hover { box-shadow: 0 12px 22px -12px rgba(27,42,69, .7); }
}
/* reveal: the card's parts wait hidden until the script pops each in (Web Animations, so no transition
   is left behind to slow the button's hover and press) */
.reveal-on :is(.card__logo, .card__head h1, .auth__form > .field, .auth__form > .auth__row, .auth__form > .auth__submit, .auth__foot):not(.is-in) { opacity: 0; }

/* reduced motion: no travel or ripple; the card and messages still arrive, so a state change reads */
@media (prefers-reduced-motion: reduce) {
  .card { animation: auth-fade 300ms ease-out both; }
  .auth__alert, .field input.is-invalid ~ .invalid-feedback, .password-wrap.has-error ~ .invalid-feedback { animation: auth-fade 200ms ease-out both; }
}
</style>
</head>
<body>
<div class="auth">
  <div class="auth__bar">
    <a class="nav__logo" href="/"><img src="{{ asset('assets/images/astra-logo-64.png') }}" alt="" width="28" height="28">Astra Cargo</a>
    <a class="auth__back" href="/">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
      Back to home
    </a>
  </div>

  <main class="auth__center">
    <div class="card">
      <div class="card__inner">
        <div class="card__head">
          <div class="card__logo"><img src="{{ asset('assets/images/astra-logo-64.png') }}" alt="" width="36" height="36"></div>
          <h1>Sign in</h1>
        </div>

        @if ($error)
          <div class="auth__alert" id="authError" role="alert">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
            <span>{{ $error }}</span>
          </div>
        @endif

        <form class="auth__form" method="post" action="" id="loginForm" novalidate>
          <input type="hidden" name="csrf" value="{{ csrf_token() }}">

          <label class="field" for="login">Email or username
            <input type="text" id="login" name="login" value="{{ $old }}" placeholder="Enter your email or username" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus{!! $error ? ' aria-describedby="authError"' : '' !!}>
            <span class="invalid-feedback" id="loginMsg">Enter your email or username.</span>
          </label>

          <div class="field">
            <label for="password">Password</label>
            <div class="password-wrap">
              <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
              <button type="button" class="toggle-pass" id="togglePass" aria-label="Show password" aria-pressed="false">
                <svg id="eyeIcon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
            <span class="invalid-feedback" id="passMsg">Enter your password.</span>
            <span class="caps-hint" id="capsHint" role="status" aria-live="polite"></span>
          </div>

          <div class="auth__row">
            <label class="auth__check" for="remember"><input type="checkbox" id="remember" name="remember" value="1">Remember me</label>
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
(function () {
  var html = document.documentElement;
  if (!html.classList.contains('reveal-on')) return;
  window.__reveal = true;
  var els = [].slice.call(document.querySelectorAll('.card__logo, .card__head h1, .auth__form > .field, .auth__form > .auth__row, .auth__form > .auth__submit, .auth__foot'));
  var start = performance.now(), queue = 0;
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (!e.isIntersecting) return;
      io.unobserve(e.target);
      var el = e.target;
      // the first wave waits for the card (180ms in), then each part follows 70ms after the last
      var delay = Math.max(0, 180 - (performance.now() - start)) + Math.min(queue++, 7) * 70;
      el.classList.add('is-in');
      if (typeof el.animate === 'function') {
        el.animate([
          { opacity: 0, transform: 'translateY(16px) scale(.97)' },
          { opacity: 1, transform: 'none' }
        ], { duration: 620, delay: delay, easing: 'cubic-bezier(.16, 1, .3, 1)', fill: 'backwards' });
      }
      setTimeout(function () { queue = Math.max(0, queue - 1); }, delay + 100);
    });
  }, { threshold: 0.2 });
  els.forEach(function (el) { io.observe(el); });
})();
</script>
</body>
</html>
