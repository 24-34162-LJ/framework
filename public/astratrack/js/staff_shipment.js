/*
 * FILE PURPOSE
 * File   : public/astratrack/js/staff_shipment.js
 * Feature: Legacy (old copy)
 * Purpose: OLD COPY of staff_shipment.js from the original plain-PHP AstraTrack. Not loaded by the
 *          Laravel pages, which use public/assets/js/staff_shipment.js. Kept for reference only.
 */

/* staff_shipment.js — Shipment Management (staff) */

document.addEventListener("DOMContentLoaded", () => {

    /* Mobile sidebar toggle (same behaviour as the other pages) */
    const sidebar = document.getElementById("sidebar");
    const toggle = document.getElementById("menuToggle");
    if (toggle && sidebar) {
        const setMenu = (open) => {
            sidebar.classList.toggle("open", open);
            document.body.classList.toggle("nav-open", open);
        };
        toggle.addEventListener("click", () => setMenu(!sidebar.classList.contains("open")));
        document.addEventListener("click", (e) => {
            if (!sidebar.contains(e.target) && !toggle.contains(e.target)) setMenu(false);
        });
        document.addEventListener("keydown", (e) => { if (e.key === "Escape") setMenu(false); });
    }

    /* Status dropdown: apply the filter as soon as it changes */
    document.querySelectorAll("[data-autosubmit]").forEach((el) => {
        el.addEventListener("change", () => el.form.submit());
    });
});
