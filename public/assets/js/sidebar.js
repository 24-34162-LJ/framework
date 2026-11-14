/* sidebar.js — the one place that opens and closes the navigation on small screens.
   Page scripts must not bind their own handler to the menu button: two handlers cancel each other out. */
(() => {
    'use strict';

    const sidebar = document.getElementById('sidebar');
    const toggle = document.querySelector('[data-sidebar-toggle]');

    if (!sidebar || !toggle) {
        return;
    }

    const narrow = window.matchMedia('(max-width: 860px)');

    const setOpen = (isOpen, { returnFocus = false } = {}) => {
        sidebar.classList.toggle('open', isOpen);
        document.body.classList.toggle('nav-open', isOpen);
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        toggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');

        if (isOpen) {
            // Move focus into the menu so keyboard and screen-reader users land where the change happened
            const first = sidebar.querySelector('.menu-item[aria-current="page"]') || sidebar.querySelector('a, button');
            if (first) {
                first.focus({ preventScroll: true });
            }
        } else if (returnFocus) {
            toggle.focus({ preventScroll: true });
        }
    };

    const isOpen = () => sidebar.classList.contains('open');

    toggle.setAttribute('aria-label', 'Open navigation');

    toggle.addEventListener('click', () => {
        setOpen(!isOpen(), { returnFocus: true });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isOpen()) {
            setOpen(false, { returnFocus: true });
        }
    });

    document.addEventListener('click', (event) => {
        if (isOpen() && !sidebar.contains(event.target) && !toggle.contains(event.target)) {
            setOpen(false);
        }
    });

    // Tabbing out of the open menu closes it instead of leaving it covering the page
    sidebar.addEventListener('focusout', (event) => {
        if (isOpen() && narrow.matches && event.relatedTarget && !sidebar.contains(event.relatedTarget) && !toggle.contains(event.relatedTarget)) {
            setOpen(false);
        }
    });

    // Rotating or widening past the breakpoint must not leave the dimmed overlay behind
    narrow.addEventListener('change', (event) => {
        if (!event.matches && isOpen()) {
            setOpen(false);
        }
    });
})();
