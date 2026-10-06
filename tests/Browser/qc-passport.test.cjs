const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/qc-passport.js'), 'utf8');

function runtime(count = 3) {
    const element = (properties = {}) => ({
        listeners: {}, dataset: {}, textContent: '', disabled: false,
        addEventListener(type, callback) { this.listeners[type] = callback; },
        fire(type, event = {}) { this.listeners[type]?.(event); },
        removeAttribute(name) { delete this[name]; },
        focus() { this.focused = true; },
        ...properties,
    });
    const entries = Array.from({ length: count }, (_, i) => {
        const image = element({ src: `https://example.test/verify/qc/token/evidence/derivative-${i}` });
        return element({ dataset: { kind: `Kind ${i}`, uploaded: `06 Oct 2026 14:0${i}:00 UTC`, reference: 'TPZ-QC-2026-000001 · v2' }, querySelector: () => image });
    });
    const selectors = Object.fromEntries(['.viewer-image', '#viewer-kind', '.viewer-meta', '.viewer-counter', '[data-viewer-previous]', '[data-viewer-next]', '[data-viewer-close]'].map(key => [key, element()]));
    const viewer = element({ open: false, querySelector: key => selectors[key], showModal() { this.open = true; }, close() { this.open = false; this.fire('close'); } });
    const sections = [element({ open: false }), element({ open: true })];
    const print = element();
    const classes = new Set();
    const document = {
        querySelector: key => key === '.evidence-viewer' ? viewer : print,
        querySelectorAll: key => key === '[data-evidence]' ? entries : key === 'details' ? sections : sections.filter(section => section.dataset.printOpen !== undefined),
        body: { classList: { add: key => classes.add(key), remove: key => classes.delete(key) } },
    };
    const window = element({ prints: 0, print() { this.prints++; } });
    vm.runInNewContext(source, { document, window });
    return { entries, selectors, viewer, sections, print, classes, window };
}

test('viewer opens the selected derivative with corresponding upload metadata and version', () => {
    const r = runtime();
    r.entries[1].fire('click');
    assert.equal(r.viewer.open, true);
    assert.equal(r.selectors['.viewer-image'].src, r.entries[1].querySelector().src);
    assert.equal(r.selectors['#viewer-kind'].textContent, 'Kind 1');
    assert.match(r.selectors['.viewer-meta'].textContent, /14:01:00 UTC.*v2/);
    assert.equal(r.selectors['.viewer-counter'].textContent, '2 / 3');
    assert.equal(r.classes.has('viewer-open'), true);
});

test('previous and next maintain correct images and boundary states', () => {
    const r = runtime();
    r.entries[0].fire('click');
    assert.equal(r.selectors['[data-viewer-previous]'].disabled, true);
    r.selectors['[data-viewer-next]'].fire('click');
    r.selectors['[data-viewer-next]'].fire('click');
    assert.equal(r.selectors['.viewer-counter'].textContent, '3 / 3');
    assert.equal(r.selectors['[data-viewer-next]'].disabled, true);
    r.selectors['[data-viewer-next]'].fire('click');
    assert.equal(r.selectors['.viewer-counter'].textContent, '3 / 3');
    r.selectors['[data-viewer-previous]'].fire('click');
    assert.equal(r.selectors['.viewer-image'].src, r.entries[1].querySelector().src);
});

test('keyboard arrows navigate; Escape is not suppressed so native dialog can close', () => {
    const r = runtime();
    r.entries[0].fire('click');
    r.viewer.fire('keydown', { key: 'ArrowRight' });
    assert.equal(r.selectors['.viewer-counter'].textContent, '2 / 3');
    r.viewer.fire('keydown', { key: 'ArrowLeft' });
    assert.equal(r.selectors['.viewer-counter'].textContent, '1 / 3');
    let blocked = false;
    r.viewer.fire('keydown', { key: 'Escape', preventDefault() { blocked = true; } });
    assert.equal(blocked, false);
});

test('close releases scroll lock, image source and returns focus to the thumbnail', () => {
    const r = runtime();
    r.entries[2].fire('click');
    r.selectors['[data-viewer-close]'].fire('click');
    assert.equal(r.viewer.open, false);
    assert.equal(r.classes.has('viewer-open'), false);
    assert.equal(r.selectors['.viewer-image'].src, undefined);
    assert.equal(r.entries[2].focused, true);
});

test('single-image viewer has no next/previous navigation', () => {
    const r = runtime(1);
    r.entries[0].fire('click');
    assert.equal(r.selectors['[data-viewer-next]'].disabled, true);
    assert.equal(r.selectors['[data-viewer-previous]'].disabled, true);
});

test('print includes all checks then restores the previous expanded state', () => {
    const r = runtime();
    r.print.fire('click');
    assert.equal(r.window.prints, 1);
    assert.deepEqual(r.sections.map(section => section.open), [true, true]);
    r.window.fire('afterprint');
    assert.deepEqual(r.sections.map(section => section.open), [false, true]);
});
