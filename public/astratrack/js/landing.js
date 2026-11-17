/*
 * FILE PURPOSE
 * File   : public/astratrack/js/landing.js
 * Feature: Legacy (old copy)
 * Purpose: OLD COPY of landing.js from the original plain-PHP AstraTrack. Not loaded by the Laravel
 *          pages, which use public/assets/js/landing.js. Kept for reference only.
 */

// AstraTrack - Landing page scripts
(function () {
    'use strict';

    var form  = document.getElementById('trackForm');
    var input = document.getElementById('blNumber');
    var note  = document.getElementById('trackNote');
    if (!form) return;

    var defaultNote = note.textContent;

    function resetState() {
        input.classList.remove('is-invalid');
        note.classList.remove('is-error');
        note.textContent = defaultNote;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var bl = input.value.trim();

        if (!bl) {
            input.classList.add('is-invalid');
            note.classList.add('is-error');
            note.textContent = 'Please enter your BL number.';
            input.focus();
            return;
        }

        // Shipment details are for authorized users only -> send them to log in.
        // Change this to a real lookup (fetch to track.php) when the backend is ready.
        window.location.href = form.dataset.loginUrl + '?bl=' + encodeURIComponent(bl);
    });

    input.addEventListener('input', resetState);

    // Close the mobile menu after tapping a link
    var menu = document.getElementById('mainNav');
    if (menu && window.bootstrap) {
        menu.querySelectorAll('a.nav-link').forEach(function (link) {
            link.addEventListener('click', function () {
                if (menu.classList.contains('show')) {
                    window.bootstrap.Collapse.getOrCreateInstance(menu).hide();
                }
            });
        });
    }
})();