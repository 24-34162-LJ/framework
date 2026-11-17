/*
 * FILE PURPOSE
 * File   : public/assets/js/client_management.js
 * Feature: Admin - Client management
 * Purpose: Client management script: confirm popups.
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
