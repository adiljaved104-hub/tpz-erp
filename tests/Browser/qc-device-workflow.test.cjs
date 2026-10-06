const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/qc-device-workflow.js'), 'utf8');

function runtime(options = {}) {
    const state = { requests: 0, stops: 0, fields: [], timers: [], formats: [], uploads: [] };
    const stream = { getTracks: () => [{ stop: () => state.stops++ }] };
    class Detector {
        static async getSupportedFormats() { return ['code_128', 'code_39', 'qr_code', 'data_matrix']; }
        constructor({ formats }) { state.formats = formats; }
        async detect() { return options.codes || [{ rawValue: 'SN-12345' }]; }
    }
    const window = { isSecureContext: true, BarcodeDetector: options.unsupported ? undefined : Detector };
    const navigator = { mediaDevices: { async getUserMedia(constraints) {
        state.requests++;
        state.constraints = constraints;
        if (options.denied) throw new Error('Synthetic denial');
        return stream;
    } } };
    const context = vm.createContext({ window, navigator, setTimeout: callback => { state.timers.push(callback); return 1; }, clearTimeout() {} });
    vm.runInContext(source, context);
    const wire = {
        set: (...args) => state.fields.push(args),
        async uploadEvidenceKind(kind) { state.uploads.push(kind); return !options.saveFailure; },
        uploadMultiple(property, files, finish) { state.property = property; state.files = files; finish(); }
    };
    const scanner = window.tpzQcScanner(wire);
    scanner.$refs = { video: { srcObject: null, async play() {} } };
    const upload = window.tpzQcUpload(wire, 'physical');
    upload.$refs = { camera: { value: 'photo.jpg' }, photos: { value: 'photo.jpg' } };
    return { state, stream, scanner, upload, window, navigator };
}

test('scanner requests no camera until explicit start; scans locally and releases it', async () => {
    const r = runtime();
    assert.equal(r.state.requests, 0);
    await r.scanner.start();
    assert.equal(r.state.requests, 1);
    assert.equal(r.state.constraints.video.facingMode.ideal, 'environment');
    assert.equal(r.state.constraints.audio, false);
    assert.deepEqual(Array.from(r.state.formats), ['code_128', 'code_39', 'qr_code', 'data_matrix']);
    assert.deepEqual(r.state.fields, [['data.serial', 'SN-12345', false]]);
    assert.equal(r.scanner.detected, 'SN-12345');
    assert.equal(r.scanner.running, false);
    assert.equal(r.state.stops, 1);
    assert.deepEqual(r.state.uploads, []);
});

test('unsupported scanner keeps manual entry and does not request camera', async () => {
    const r = runtime({ unsupported: true });
    await r.scanner.start();
    assert.equal(r.state.requests, 0);
    assert.match(r.scanner.message, /manually/);
});

test('camera denial has readable manual fallback', async () => {
    const r = runtime({ denied: true });
    await r.scanner.start();
    assert.equal(r.scanner.running, false);
    assert.match(r.scanner.message, /permission denied/);
    assert.equal(r.state.fields.length, 0);
});

test('cancel releases a scanning stream and prevents later detection', async () => {
    const r = runtime({ codes: [] });
    await r.scanner.start();
    assert.equal(r.scanner.running, true);
    r.scanner.stop();
    await r.state.timers[0]();
    assert.equal(r.state.stops, 1);
    assert.equal(r.state.fields.length, 0);
});

test('cancel before supported formats resolve never requests a camera', async () => {
    const r = runtime();
    let resolve;
    r.window.BarcodeDetector.getSupportedFormats = () => new Promise(done => { resolve = done; });
    const start = r.scanner.start();
    r.scanner.stop();
    resolve(['qr_code']);
    await start;
    assert.equal(r.state.requests, 0);
});

test('a late camera stream after cancellation is immediately released', async () => {
    const r = runtime();
    let resolve;
    r.navigator.mediaDevices.getUserMedia = () => new Promise(done => { resolve = done; });
    const start = r.scanner.start();
    await new Promise(setImmediate);
    r.scanner.stop();
    resolve(r.stream);
    await start;
    assert.equal(r.state.stops, 1);
    assert.equal(r.scanner.running, false);
    assert.equal(r.state.fields.length, 0);
});

test('URL QR codes cannot replace serial with arbitrary content', async () => {
    const r = runtime({ codes: [{ rawValue: 'https://example.invalid/private' }] });
    await r.scanner.start();
    assert.equal(r.state.fields.length, 0);
    assert.match(r.scanner.message, /not a valid Serial/);
    r.scanner.destroy();
    assert.equal(r.state.stops, 1);
});

test('photos upload to their own category with duplicate-submit guard', async () => {
    const r = runtime();
    const files = [{ type: 'image/jpeg', size: 100 }, { type: 'image/png', size: 200 }];
    r.upload.upload(files);
    r.upload.upload(files);
    await new Promise(setImmediate);
    assert.equal(r.state.property, 'evidenceUploads.physical');
    assert.equal(r.state.files.length, 2);
    assert.deepEqual(r.state.uploads, ['physical']);
    assert.equal(r.upload.busy, false);
    assert.equal(r.upload.$refs.photos.value, '');
});

test('failed save preserves staged files with safe retry message', async () => {
    const r = runtime({ saveFailure: true });
    await r.upload.save();
    assert.equal(r.upload.retry, true);
    assert.equal(r.upload.$refs.photos.value, 'photo.jpg');
    assert.match(r.upload.message, /not saved/);
});

test('client upload limits reject oversize/count without affecting manual upload choices', () => {
    const r = runtime();
    r.upload.upload(Array(11).fill({ type: 'image/jpeg', size: 100 }));
    assert.equal(r.state.uploads.length, 0);
    assert.match(r.upload.message, /up to 10/);
    r.upload.upload([{ type: 'image/jpeg', size: 9 * 1024 * 1024 }]);
    assert.equal(r.state.uploads.length, 0);
    assert.equal(r.upload.busy, false);
});
