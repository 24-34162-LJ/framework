/*
 * FILE PURPOSE
 * File   : public/assets/js/shipment_details.js
 * Feature: Shipments
 * Purpose: Shipment details script: open/close sections, delivery editor.
 */

(() => {
    document.querySelectorAll('[data-collapse]').forEach(button => {
        const initialBody = document.getElementById(button.getAttribute('aria-controls'));
        button.closest('.collapse-card')?.classList.toggle('is-expanded', !!initialBody && !initialBody.hidden);
        button.addEventListener('click', () => {
            const body = document.getElementById(button.getAttribute('aria-controls'));
            if (!body) return;
            const expanded = button.getAttribute('aria-expanded') !== 'true';
            button.setAttribute('aria-expanded', String(expanded));
            body.hidden = !expanded;
            button.textContent = expanded ? 'Show less' : 'Show more';
            button.closest('.collapse-card')?.classList.toggle('is-expanded', expanded);
        });
    });

    if (window.location.hash) {
        const anchoredCard = document.getElementById(window.location.hash.slice(1));
        const expandButton = anchoredCard?.querySelector('[data-collapse]');
        const body = expandButton ? document.getElementById(expandButton.getAttribute('aria-controls')) : null;
        if (expandButton && body?.hidden) expandButton.click();
    }

    const dialog = document.getElementById('deliveryDialog');
    const form = document.getElementById('deliveryForm');
    if (!dialog || !form) return;
    const field = name => form.elements.namedItem(name);
    const title = document.getElementById('deliveryDialogTitle');
    const deliveredField = document.getElementById('deliveredDateField');
    const save = document.getElementById('deliverySave');
    const note = document.getElementById('deliverySavingNote');
    const statusInputs = Array.from(form.querySelectorAll('input[name="status"]'));

    const selectedStatus = () => statusInputs.find(input => input.checked)?.value || 'scheduled';
    const syncStatusFields = () => {
        const status = selectedStatus();
        const scheduleDate = field('scheduled_date');
        const deliveredDate = field('delivered_date');
        scheduleDate.required = status === 'scheduled';
        deliveredDate.required = status === 'delivered';
        deliveredField.hidden = status !== 'delivered';
        const colours = { scheduled: '#e0a030', in_transit: '#395886', delivered: '#2e9e5b', cancelled: '#c0353a' };
        statusInputs.forEach(input => {
            input.closest('label').style.setProperty('--delivery-status-color', colours[input.value] || '#395886');
        });
    };
    statusInputs.forEach(input => input.addEventListener('change', syncStatusFields));

    const openAdd = () => {
        form.reset();
        field('delivery_id').value = '';
        ['trucker', 'driver_name', 'plate_no', 'scheduled_date', 'scheduled_time', 'delivery_address', 'contact_name', 'contact_phone', 'delivered_date'].forEach(name => { field(name).value = ''; });
        statusInputs.forEach(input => { input.checked = input.value === 'scheduled'; });
        title.textContent = 'Schedule Delivery';
        syncStatusFields();
        dialog.showModal();
    };
    document.querySelectorAll('[data-delivery-add]').forEach(button => button.addEventListener('click', openAdd));

    document.querySelectorAll('[data-delivery-edit]').forEach(button => {
        button.addEventListener('click', () => {
            let delivery;
            try { delivery = JSON.parse(button.dataset.delivery); } catch { return; }
            form.reset();
            field('delivery_id').value = delivery.delivery_id || '';
            field('trucker').value = delivery.trucker || '';
            field('driver_name').value = delivery.driver_name || '';
            field('plate_no').value = delivery.plate_no || '';
            field('scheduled_date').value = delivery.scheduled_date || '';
            field('scheduled_time').value = (delivery.scheduled_time || '').slice(0, 5);
            field('delivery_address').value = delivery.delivery_address || '';
            const contactParts = (delivery.receiving_contact || '').split(' · ', 2);
            field('contact_name').value = contactParts[0] || '';
            field('contact_phone').value = contactParts[1] || '';
            field('delivered_date').value = delivery.delivered_date || '';
            statusInputs.forEach(input => { input.checked = input.value === (delivery.status || 'scheduled'); });
            title.textContent = 'Edit Delivery Details';
            syncStatusFields();
            dialog.showModal();
        });
    });

    dialog.querySelectorAll('[data-delivery-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    form.addEventListener('submit', event => {
        if (!form.reportValidity()) { event.preventDefault(); return; }
        save.disabled = true;
        save.textContent = 'Saving…';
        note.textContent = 'Saving delivery details. Please wait.';
    });

    setupAddressSuggestions(field('delivery_address'));

    if (dialog.dataset.autoOpen === '1') {
        title.textContent = field('delivery_id').value ? 'Edit Delivery Details' : 'Schedule Delivery';
        syncStatusFields();
        dialog.showModal();
    }

    /*
     * Delivery Address suggestions from the app's own PH place list (PSGC); no maps service.
     * Only the part after the last comma is searched, so "Blk 5 Lot 12, santol" suggests barangays
     * named Santol and picking one keeps "Blk 5 Lot 12, " in front.
     */
    function setupAddressSuggestions(input) {
        const url = input?.dataset.suggestUrl;
        if (!input || !url) return;
        const list = document.createElement('ul');
        list.className = 'address-suggest';
        list.id = 'deliveryAddressSuggest';
        list.setAttribute('role', 'listbox');
        list.hidden = true;
        input.insertAdjacentElement('afterend', list);
        input.setAttribute('aria-controls', list.id);
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('autocomplete', 'off');

        let items = [];
        let active = -1;
        let timer = null;
        let request = null;
        const cache = new Map();

        const typedPart = () => {
            const value = input.value;
            const comma = value.lastIndexOf(',');
            return { before: comma >= 0 ? value.slice(0, comma + 1) : '', term: (comma >= 0 ? value.slice(comma + 1) : value).trim() };
        };
        const close = () => { list.hidden = true; items = []; active = -1; input.removeAttribute('aria-activedescendant'); };
        const highlight = index => {
            active = index;
            Array.from(list.children).forEach((li, i) => li.setAttribute('aria-selected', String(i === index)));
            if (index >= 0) { input.setAttribute('aria-activedescendant', list.children[index].id); list.children[index].scrollIntoView({ block: 'nearest' }); }
        };
        const pick = index => {
            const item = items[index];
            if (!item) return;
            const { before } = typedPart();
            input.value = (before ? before.trimEnd() + ' ' : '') + item.label;
            close();
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
        };
        const render = results => {
            items = results;
            list.replaceChildren(...results.map((item, i) => {
                const li = document.createElement('li');
                li.id = 'deliveryAddressOption' + i;
                li.setAttribute('role', 'option');
                const name = document.createElement('span');
                name.textContent = item.label;
                const kind = document.createElement('small');
                kind.textContent = { barangay: 'Barangay', city: 'City', municipality: 'Town', province: 'Province' }[item.type] || '';
                li.append(name, kind);
                li.addEventListener('mousedown', event => { event.preventDefault(); pick(i); });
                return li;
            }));
            list.hidden = results.length === 0;
            highlight(-1);
        };
        const search = () => {
            const { term } = typedPart();
            if (term.replace(/[^a-z0-9]/gi, '').length < 2) { close(); return; }
            const key = term.toLowerCase();
            if (cache.has(key)) { render(cache.get(key)); return; }
            request?.abort();
            request = new AbortController();
            fetch(url + '?q=' + encodeURIComponent(term), { headers: { Accept: 'application/json' }, signal: request.signal })
                .then(response => (response.ok ? response.json() : { items: [] }))
                .then(data => {
                    const results = Array.isArray(data.items) ? data.items : [];
                    cache.set(key, results);
                    if (typedPart().term.toLowerCase() === key) render(results);
                })
                .catch(() => {});
        };

        input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(search, 200); });
        input.addEventListener('keydown', event => {
            if (list.hidden) return;
            if (event.key === 'ArrowDown') { event.preventDefault(); highlight((active + 1) % items.length); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); highlight(active <= 0 ? items.length - 1 : active - 1); }
            else if ((event.key === 'Enter' || event.key === 'Tab') && active >= 0) { event.preventDefault(); pick(active); }
            else if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); }
        });
        input.addEventListener('blur', () => setTimeout(close, 120));
        dialog.addEventListener('close', close);
    }
})();
