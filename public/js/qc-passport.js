(() => {
    'use strict';
    const entries = [...document.querySelectorAll('[data-evidence]')];
    const viewer = document.querySelector('.evidence-viewer');
    let current = 0;
    let opener;
    const show = (index) => {
        current = index;
        const entry = entries[current];
        const image = viewer.querySelector('.viewer-image');
        // Only reuse the token-scoped customer derivative already rendered by PHP.
        image.src = entry.querySelector('img').src;
        image.alt = entry.dataset.kind;
        viewer.querySelector('#viewer-kind').textContent = entry.dataset.kind;
        viewer.querySelector('.viewer-meta').textContent = `Uploaded ${entry.dataset.uploaded} · ${entry.dataset.reference}`;
        viewer.querySelector('.viewer-counter').textContent = `${current + 1} / ${entries.length}`;
        viewer.querySelector('[data-viewer-previous]').disabled = current === 0;
        viewer.querySelector('[data-viewer-next]').disabled = current === entries.length - 1;
    };
    entries.forEach((entry, index) => entry.addEventListener('click', () => {
        opener = entry;
        show(index);
        viewer.showModal();
        document.body.classList.add('viewer-open');
    }));
    viewer.querySelector('[data-viewer-close]').addEventListener('click', () => viewer.close());
    viewer.addEventListener('close', () => {
        document.body.classList.remove('viewer-open');
        viewer.querySelector('.viewer-image').removeAttribute('src');
        opener?.focus();
    });
    viewer.querySelector('[data-viewer-previous]').addEventListener('click', () => { if (current > 0) show(current - 1); });
    viewer.querySelector('[data-viewer-next]').addEventListener('click', () => { if (current < entries.length - 1) show(current + 1); });
    viewer.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft' && current > 0) show(current - 1);
        if (event.key === 'ArrowRight' && current < entries.length - 1) show(current + 1);
        // Escape retains the native dialog cancel/close behavior.
    });
    document.querySelector('[data-print-certificate]').addEventListener('click', () => {
        document.querySelectorAll('details').forEach((section) => { section.dataset.printOpen = String(section.open); section.open = true; });
        window.print();
    });
    window.addEventListener('afterprint', () => {
        document.querySelectorAll('details[data-print-open]').forEach((section) => { section.open = section.dataset.printOpen === 'true'; delete section.dataset.printOpen; });
    });
})();
