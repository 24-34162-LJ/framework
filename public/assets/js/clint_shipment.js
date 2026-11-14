/* clint_shipment.js — My Shipments (client) */

document.addEventListener("DOMContentLoaded", () => {

    /* Status dropdown: apply the filter as soon as it changes */
    document.querySelectorAll("[data-autosubmit]").forEach((el) => {
        el.addEventListener("change", () => el.form.submit());
    });
});
