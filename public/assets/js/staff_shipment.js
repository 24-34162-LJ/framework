/*
 * FILE PURPOSE
 * File   : public/assets/js/staff_shipment.js
 * Feature: Staff portal
 * Purpose: Staff shipment list: submits the filters automatically.
 */

/* staff_shipment.js — Shipment Management (staff) */

document.addEventListener("DOMContentLoaded", () => {

    /* Status dropdown: apply the filter as soon as it changes */
    document.querySelectorAll("[data-autosubmit]").forEach((el) => {
        el.addEventListener("change", () => el.form.submit());
    });
});
