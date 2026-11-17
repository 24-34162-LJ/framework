/*
 * FILE PURPOSE
 * File   : public/assets/js/clint_shipment.js
 * Feature: Client portal
 * Purpose: Client My Shipments: submits the filters automatically.
 */

/* clint_shipment.js — My Shipments (client) */

document.addEventListener("DOMContentLoaded", () => {

    /* Status dropdown: apply the filter as soon as it changes */
    document.querySelectorAll("[data-autosubmit]").forEach((el) => {
        el.addEventListener("change", () => el.form.submit());
    });
});
