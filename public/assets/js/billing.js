document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-bill-carousel]').forEach(carousel => {
        const track = carousel.querySelector('[data-bill-track]');
        const slides = [...carousel.querySelectorAll('[data-bill-slide]')];
        const previous = carousel.querySelector('[data-bill-prev]');
        const next = carousel.querySelector('[data-bill-next]');
        const position = carousel.querySelector('.bill-carousel-position');
        if (!track || slides.length < 2) return;

        let activeIndex = 0;
        let frame = 0;
        const updateControls = () => {
            if (previous) previous.disabled = activeIndex === 0;
            if (next) next.disabled = activeIndex === slides.length - 1;
            if (position) position.textContent = `Bill ${activeIndex + 1} of ${slides.length}`;
            slides.forEach((slide, index) => slide.classList.toggle('is-current', index === activeIndex));
        };
        const goTo = index => {
            activeIndex = Math.max(0, Math.min(slides.length - 1, index));
            track.scrollTo({ left: slides[activeIndex].offsetLeft, behavior: 'smooth' });
            updateControls();
        };
        previous?.addEventListener('click', () => goTo(activeIndex - 1));
        next?.addEventListener('click', () => goTo(activeIndex + 1));
        track.addEventListener('scroll', () => {
            if (frame) cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                const left = track.scrollLeft;
                activeIndex = slides.reduce((closest, slide, index) =>
                    Math.abs(slide.offsetLeft - left) < Math.abs(slides[closest].offsetLeft - left) ? index : closest, 0);
                updateControls();
            });
        }, { passive: true });
        track.addEventListener('keydown', event => {
            if (event.key === 'ArrowLeft') { event.preventDefault(); goTo(activeIndex - 1); }
            if (event.key === 'ArrowRight') { event.preventDefault(); goTo(activeIndex + 1); }
        });
        updateControls();
    });

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
        row.innerHTML = `<select name="items[${index}][charge_type]" required><option value="">Select type</option><option>Freight</option><option>Handling</option><option>Documentation</option><option>Customs-related charge</option><option>Other expense</option><option>Orange lane</option><option>Red lane</option><option>DEA examination</option><option>BOC Task Force</option></select><input name="items[${index}][description]" maxlength="255" placeholder="Description"><input name="items[${index}][amount]" type="number" min="0.01" step="0.01" required placeholder="0.00"><button type="button" class="bill-remove" data-remove-bill-item aria-label="Remove charge">×</button>`;
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
