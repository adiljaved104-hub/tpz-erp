/* QC-only Alpine helpers. No video recording, external decoder, or secret data. */
window.tpzQcUpload = (wire, kind) => ({
    busy: false, percent: 0, message: '', retry: false,
    upload(fileList) {
        if (this.busy) return;
        const files = Array.from(fileList || []);
        this.message = '';
        if (!files.length) return;
        if (files.length > 10 || files.some(file => file.size > 8 * 1024 * 1024 || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type))) {
            this.message = 'Choose up to 10 JPG/PNG/WEBP photos, no larger than 8 MB each.';
            return;
        }
        this.busy = true;
        this.retry = false;
        wire.uploadMultiple('evidenceUploads.' + kind, files,
            () => this.save(),
            () => { this.busy = false; this.message = 'Photos were not uploaded. Choose Photos remains available; please try again.'; },
            event => { this.percent = event.detail.progress; },
            () => { this.busy = false; this.message = 'Upload cancelled. No evidence was saved.'; });
    },
    async save() {
        this.busy = true;
        try {
            const saved = await wire.uploadEvidenceKind(kind);
            this.retry = !saved;
            this.message = saved ? '' : 'Evidence was not saved. Review the highlighted category and retry.';
            if (saved) { this.$refs.camera.value = ''; this.$refs.photos.value = ''; }
        } catch (_) {
            this.retry = true;
            this.message = 'Evidence was not saved. Review the highlighted category and retry.';
        } finally { this.busy = false; }
    }
});

window.tpzQcScanner = wire => ({
    stream: null, timer: null, generation: 0, starting: false, running: false, detected: '',
    message: 'Manual Serial / IMEI entry is always available. Camera scanning requires a supported secure browser.',
    async start() {
        if (this.starting || this.running) return;
        if (!window.isSecureContext || !window.BarcodeDetector || !navigator.mediaDevices?.getUserMedia) {
            this.message = 'Camera scanning is not supported here. Enter the Serial / IMEI manually.';
            return;
        }
        const generation = ++this.generation;
        this.starting = true;
        try {
            const supported = await window.BarcodeDetector.getSupportedFormats();
            if (generation !== this.generation) return;
            const formats = ['code_128', 'code_39', 'qr_code', 'data_matrix'].filter(format => supported.includes(format));
            if (!formats.length) throw new Error('unsupported');
            const detector = new window.BarcodeDetector({ formats });
            const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
            if (generation !== this.generation) { stream.getTracks().forEach(track => track.stop()); return; }
            this.stream = stream;
            this.$refs.video.srcObject = stream;
            await this.$refs.video.play();
            if (generation !== this.generation) { stream.getTracks().forEach(track => track.stop()); return; }
            this.running = true;
            this.message = 'Point the rear camera at the device barcode/QR label.';
            const scan = async () => {
                if (!this.running || generation !== this.generation) return;
                try {
                    const codes = await detector.detect(this.$refs.video);
                    if (!this.running || generation !== this.generation) return;
                    const value = codes[0]?.rawValue?.trim();
                    if (value) {
                        if (!/^[A-Za-z0-9._ -]{3,100}$/.test(value)) {
                            this.message = 'This code is not a valid Serial / IMEI. Try the device label or enter it manually.';
                        } else {
                            this.detected = value;
                            wire.set('data.serial', value, false);
                            this.stop();
                            this.message = 'Serial detected. Review/edit it before Start QC.';
                            return;
                        }
                    }
                    this.timer = setTimeout(scan, 300);
                } catch (_) { this.stop(); this.message = 'Scanning failed. Enter the Serial / IMEI manually.'; }
            };
            await scan();
        } catch (_) {
            this.stop();
            this.message = 'Camera unavailable or permission denied. Enter the Serial / IMEI manually.';
        } finally { if (generation === this.generation) this.starting = false; }
    },
    stop() {
        ++this.generation;
        clearTimeout(this.timer);
        this.stream?.getTracks().forEach(track => track.stop());
        this.stream = null;
        if (this.$refs.video) this.$refs.video.srcObject = null;
        this.running = false;
        this.starting = false;
    },
    destroy() { this.stop(); }
});
