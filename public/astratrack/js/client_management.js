/*
 * FILE PURPOSE
 * File   : public/astratrack/js/client_management.js
 * Feature: Legacy (old copy)
 * Purpose: OLD COPY of client_management.js from the original plain-PHP AstraTrack. Not loaded by the
 *          Laravel pages, which use public/assets/js/client_management.js. Kept for reference only.
 */

document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.getElementById('clientDialog');
    if (dialog && typeof dialog.showModal === 'function') {
        dialog.removeAttribute('open');
        dialog.showModal();
        dialog.addEventListener('click', event => {
            if (event.target === dialog) window.location.href = dialog.querySelector('header a').href;
        });
    }
    document.querySelectorAll('[data-confirm]').forEach(button => {
        button.addEventListener('click', event => {
            if (!window.confirm(button.dataset.confirm)) event.preventDefault();
        });
    });
});
