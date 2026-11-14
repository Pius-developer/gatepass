/* ==========================================================================
   Door scanner. Opens the phone's back camera, finds QR codes and checks
   each ticket with the server.

   - Chrome on Android has a built-in QR reader (BarcodeDetector).
   - Safari on iPhone doesn't, so we load the small jsQR library only then.
   - The server decides if a ticket is valid; this page just shows the answer
     big and in colour, with a sound and a vibration, so staff barely need to look.
   ========================================================================== */

(() => {
    const root = document.querySelector('[data-scanner]');
    if (!root) return;

    const q = (s) => root.querySelector(s);
    const video = q('[data-video]');
    const startBox = q('[data-start]');
    const startBtn = q('[data-start-btn]');
    const camMsg = q('[data-cam-msg]');
    const result = q('[data-result]');
    const log = q('[data-log]');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    let detector = null;     // BarcodeDetector, when the browser has one
    let jsQR = null;         // fallback decoder
    let busy = false;        // waiting for the server
    let lastCode = '';
    let lastAt = 0;
    let audio;

    // ---- Camera ------------------------------------------------------------------

    startBtn.addEventListener('click', start);

    async function start() {
        if (!navigator.mediaDevices?.getUserMedia) {
            camMsg.textContent = 'This browser can\'t use the camera. Type codes in the box below instead.';
            return;
        }
        startBtn.disabled = true;
        camMsg.textContent = 'Starting camera…';
        try {
            await loadDecoder();
            const stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 1280 } },
                audio: false,
            });
            video.srcObject = stream;
            await video.play();
            startBox.hidden = true;
            keepScreenOn();
            requestAnimationFrame(scanLoop);
        } catch (err) {
            startBtn.disabled = false;
            camMsg.textContent = err.name === 'NotAllowedError'
                ? 'Camera permission was blocked. Allow it in your browser settings, or type codes below.'
                : 'Couldn\'t start the camera. You can still type codes below.';
        }
    }

    async function loadDecoder() {
        if ('BarcodeDetector' in window) {
            try {
                const formats = await BarcodeDetector.getSupportedFormats();
                if (formats.includes('qr_code')) {
                    detector = new BarcodeDetector({ formats: ['qr_code'] });
                    return;
                }
            } catch { /* fall through to jsQR */ }
        }
        await new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js';
            s.onload = () => { jsQR = window.jsQR; resolve(); };
            s.onerror = () => reject(new Error('Could not load the QR reader'));
            document.head.append(s);
        });
    }

    // Screens dim after a minute; keep this one awake while scanning.
    async function keepScreenOn() {
        try {
            let lock = await navigator.wakeLock?.request('screen');
            document.addEventListener('visibilitychange', async () => {
                if (document.visibilityState === 'visible' && lock?.released) lock = await navigator.wakeLock.request('screen');
            });
        } catch { /* not supported; fine */ }
    }

    // ---- Scanning --------------------------------------------------------------------

    let lastFrame = 0;
    async function scanLoop(now) {
        requestAnimationFrame(scanLoop);
        if (busy || now - lastFrame < 120 || video.readyState < 2) return;   // ~8 scans a second is plenty
        lastFrame = now;

        let text = null;
        if (detector) {
            const codes = await detector.detect(video).catch(() => []);
            text = codes[0]?.rawValue ?? null;
        } else if (jsQR) {
            // Only look at the square in the middle, scaled down: much faster on older phones.
            const side = Math.min(video.videoWidth, video.videoHeight);
            const size = Math.min(640, side);
            canvas.width = canvas.height = size;
            ctx.drawImage(video, (video.videoWidth - side) / 2, (video.videoHeight - side) / 2, side, side, 0, 0, size, size);
            const img = ctx.getImageData(0, 0, size, size);
            text = jsQR(img.data, size, size, { inversionAttempts: 'dontInvert' })?.data ?? null;
        }
        if (!text) return;

        // The same ticket stays in front of the camera for a moment; don't check it 10 times.
        if (text === lastCode && Date.now() - lastAt < 4000) return;
        lastCode = text;
        lastAt = Date.now();
        check(text);
    }

    q('[data-manual]').addEventListener('submit', (e) => {
        e.preventDefault();
        const input = e.target.code;
        if (input.value.trim()) check(input.value.trim());
        input.value = '';
    });

    // ---- Asking the server -------------------------------------------------------------

    async function check(code) {
        busy = true;
        try {
            const res = await fetch(root.dataset.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
                body: new URLSearchParams({ code }),
            });
            if (res.status === 419) throw new Error('session');
            const data = await res.json();
            show(data, code);
            if (data.stats) {
                q('[data-in]').textContent = data.stats.checked_in;
                q('[data-total]').textContent = data.stats.tickets;
            }
        } catch (err) {
            show({ result: err.message === 'session' ? 'session' : 'offline' }, code);
        } finally {
            // Keep the answer on screen briefly, then scan again.
            setTimeout(() => { result.hidden = true; busy = false; }, 1600);
        }
    }

    const time = (sql) => new Date(sql.replace(' ', 'T') + 'Z').toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

    function show(data, code) {
        const t = data.ticket || {};
        const views = {
            ok:          ['ok',   '✓', 'Welcome in',        `${t.name} · ${t.type}`],
            already:     ['warn', '!', 'Already used',      `${t.name} · scanned at ${t.checked_in_at ? time(t.checked_in_at) : 'earlier'}`],
            wrong_event: ['bad',  '✕', 'Wrong event',       'This ticket is for a different event'],
            not_found:   ['bad',  '✕', 'Not a valid ticket', code.slice(0, 40)],
            slow_down:   ['warn', '…', 'Too fast',          'Wait a second and scan again'],
            session:     ['bad',  '✕', 'Logged out',        'Refresh the page and log in again'],
            offline:     ['bad',  '✕', 'No connection',     'Check the internet and try again'],
        };
        const [cls, icon, head, sub] = views[data.result] || views.not_found;
        result.className = 'sc-result ' + cls;
        q('[data-r-icon]').textContent = icon;
        q('[data-r-head]').textContent = head;
        q('[data-r-sub]').textContent = sub;
        result.hidden = false;

        beep(cls === 'ok' ? 880 : 220, cls === 'ok' ? 0.12 : 0.35);
        navigator.vibrate?.(cls === 'ok' ? 60 : [80, 60, 80]);

        const li = document.createElement('li');
        li.className = cls;
        const label = document.createElement('span');
        label.textContent = `${head}${t.name ? ' · ' + t.name : ''}`;
        const when = document.createElement('time');
        when.textContent = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        li.append(label, when);
        log.prepend(li);
        while (log.children.length > 8) log.lastChild.remove();
    }

    // A short tone with the Web Audio API: high for "in", low for a problem.
    function beep(freq, seconds) {
        try {
            audio ??= new AudioContext();
            const osc = audio.createOscillator();
            const gain = audio.createGain();
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.18, audio.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + seconds);
            osc.connect(gain).connect(audio.destination);
            osc.start();
            osc.stop(audio.currentTime + seconds);
        } catch { /* sound is a nice extra */ }
    }
})();
