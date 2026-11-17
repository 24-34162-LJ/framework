/*
 * FILE PURPOSE
 * File   : public/assets/js/login.js
 * Feature: Login / Auth
 * Purpose: Login page script: show/hide password, Caps Lock warning, loading state.
 */

// AstraTrack - Login page scripts
(function () {
    'use strict';

    var form    = document.getElementById('loginForm');
    var login   = document.getElementById('login');
    var pass    = document.getElementById('password');
    var toggle  = document.getElementById('togglePass');
    var eyeIcon = document.getElementById('eyeIcon');
    var caps    = document.getElementById('capsHint');
    var btn     = document.getElementById('loginBtn');
    if (!form) return;

    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function motion(el, frames, opts) {
        if (reduced || !el || typeof el.animate !== 'function') return;
        el.animate(frames, opts);
    }

    var EYE     ='<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    var EYE_OFF = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M1 1l22 22"/>';

    // back from a failed sign-in with the username kept: the password is what needs retyping
    if (document.getElementById('authError') && login.value.trim() !== '') pass.focus();

    /* ---------- Show / hide password ---------- */
    toggle.addEventListener('click', function () {
        var show = pass.type === 'password';
        pass.type = show ? 'text' : 'password';
        eyeIcon.innerHTML = show ? EYE_OFF : EYE;
        // the eye blinks into its new state
        motion(eyeIcon, [{ transform: 'scaleY(.2)', opacity: .4 }, { transform: 'scaleY(1)', opacity: 1 }], { duration: 160, easing: 'cubic-bezier(.16, 1, .3, 1)' });
        toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
        pass.focus();
    });

    /* ---------- Caps Lock hint ---------- */
    function checkCaps(e) {
        if (typeof e.getModifierState !== 'function') return;
        caps.textContent = e.getModifierState('CapsLock') ? 'Caps Lock is on' : '';
    }
    pass.addEventListener('keydown', checkCaps);
    pass.addEventListener('keyup', checkCaps);
    pass.addEventListener('blur', function () { caps.textContent = ''; });

    /* ---------- Inline validation ---------- */
    var passWrap = pass.closest('.password-wrap');

    function setInvalid(input, bad) {
        input.classList.toggle('is-invalid', bad);
        input.setAttribute('aria-invalid', bad ? 'true' : 'false');
        // Point screen readers at the message only while it shows (a hidden one is still read out)
        var msg = input === pass ? 'passMsg' : 'loginMsg';
        var ids = (input.getAttribute('aria-describedby') || '').split(' ').filter(function (id) { return id && id !== msg; });
        if (bad) ids.unshift(msg);
        if (ids.length) input.setAttribute('aria-describedby', ids.join(' ')); else input.removeAttribute('aria-describedby');
        if (input === pass) passWrap.classList.toggle('has-error', bad);
    }

    [login, pass].forEach(function (input) {
        input.addEventListener('input', function () {
            if (input.value.trim() !== '') setInvalid(input, false);
        });
    });

    /* ---------- Submit: validate, then show loading state ---------- */
    function setLoading(on) {
        btn.disabled = on;
        if (on) form.setAttribute('aria-busy', 'true'); else form.removeAttribute('aria-busy');
        btn.querySelector('.btn-text').hidden = on;
        btn.querySelector('.btn-loading').hidden = !on;
    }

    form.addEventListener('submit', function (e) {
        var badLogin = login.value.trim() === '';
        var badPass  = pass.value === '';
        setInvalid(login, badLogin);
        setInvalid(pass, badPass);

        if (badLogin || badPass) {
            e.preventDefault();
            // each empty field shakes once, on every failed try (a short, decaying sideways nudge)
            var shake = [{ transform: 'translateX(0)' }, { transform: 'translateX(-6px)' }, { transform: 'translateX(5px)' }, { transform: 'translateX(-3px)' }, { transform: 'translateX(2px)' }, { transform: 'translateX(0)' }];
            if (badLogin) motion(login, shake, { duration: 340, easing: 'ease-out' });
            if (badPass) motion(passWrap, shake, { duration: 340, easing: 'ease-out' });
            (badLogin ? login : pass).focus();
            return;
        }
        setLoading(true);
    });

    // If the user comes back with the browser Back button, reset the button
    window.addEventListener('pageshow', function () { setLoading(false); });
})();