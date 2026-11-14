document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('sidebar');
    const menuToggle = document.getElementById('menuToggle');
    if (sidebar && menuToggle) {
        const closeMenu = () => { sidebar.classList.remove('open'); document.body.classList.remove('nav-open'); };
        menuToggle.addEventListener('click', () => {
            const open = sidebar.classList.toggle('open');
            document.body.classList.toggle('nav-open', open);
        });
        document.addEventListener('click', event => {
            if (!sidebar.contains(event.target) && !menuToggle.contains(event.target)) closeMenu();
        });
        document.addEventListener('keydown', event => { if (event.key === 'Escape') closeMenu(); });
    }

    const client = document.getElementById('billClient');
    client?.addEventListener('change', () => {
        const url = new URL(window.location.href);
        url.searchParams.set('view', 'create');
        if (client.value) url.searchParams.set('client_id', client.value);
        else url.searchParams.delete('client_id');
        window.location.assign(url.toString());
    });

    const rows = document.getElementById('billItems');
    const total = document.getElementById('billTotal');
    const recalc = () => {
        if (!rows || !total) return;
        const cents = [...rows.querySelectorAll('input[name$="[amount]"]')]
            .reduce((sum, input) => sum + Math.round((Number(input.value) || 0) * 100), 0);
        total.textContent = (cents / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        rows.querySelectorAll('[data-remove-bill-item]').forEach(button => { button.disabled = rows.children.length <= 1; });
    };
    const add = document.getElementById('addBillItem');
    add?.addEventListener('click', () => {
        const index = rows.children.length;
        const row = document.createElement('div');
        row.className = 'bill-item-row';
        row.innerHTML = `<select name="items[${index}][charge_type]" required><option value="">Select type</option><option>Freight</option><option>Handling</option><option>Documentation</option><option>Customs-related charge</option><option>Other expense</option></select><input name="items[${index}][description]" maxlength="255" placeholder="Description"><input name="items[${index}][amount]" type="number" min="0.01" step="0.01" required placeholder="0.00"><button type="button" class="bill-remove" data-remove-bill-item aria-label="Remove charge">×</button>`;
        rows.append(row);
        recalc();
        row.querySelector('select').focus();
    });
    rows?.addEventListener('input', recalc);
    rows?.addEventListener('change', recalc);
    rows?.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-bill-item]');
        if (button && rows.children.length > 1) { button.closest('.bill-item-row').remove(); recalc(); }
    });
    document.querySelectorAll('[data-confirm]').forEach(button => button.addEventListener('click', event => {
        if (!window.confirm(button.dataset.confirm)) event.preventDefault();
    }));
    recalc();
});
