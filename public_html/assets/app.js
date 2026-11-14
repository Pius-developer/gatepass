/* ==========================================================================
   Gatepass front end. Plain JavaScript, no framework, no build step.
   Each feature looks for its own data-* attribute, so a page only runs what
   it actually contains. Everything still works without JavaScript; this
   layer makes it faster and nicer.
   ========================================================================== */

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
const csrf = () => $('meta[name="csrf-token"]')?.content ?? '';

/* ---- Small shared helpers -------------------------------------------------- */

function toast(message) {
    const box = $('.toasts');
    if (!box) return;
    const el = document.createElement('div');
    el.className = 'toast';
    el.textContent = message;
    box.append(el);
    setTimeout(() => el.remove(), 2600);
}

function debounce(fn, ms) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

/** 250000 kobo → "₦2,500". */
function money(kobo, symbol = '₦') {
    const naira = kobo / 100;
    return symbol + naira.toLocaleString('en-NG', { minimumFractionDigits: kobo % 100 ? 2 : 0, maximumFractionDigits: 2 });
}

async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        // Older browsers / non-HTTPS: fall back to a hidden textarea.
        const ta = Object.assign(document.createElement('textarea'), { value: text });
        ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.append(ta); ta.select(); document.execCommand('copy'); ta.remove();
    }
    toast('Link copied');
}

/* ---- Copy, share, confirm, password, counters -------------------------------- */

document.addEventListener('click', (e) => {
    const copy = e.target.closest('[data-copy]');
    if (copy) { copyText(copy.dataset.copy); return; }

    const share = e.target.closest('[data-share]');
    if (share) {
        const data = { title: share.dataset.title, url: share.dataset.url };
        // The Web Share API opens the phone's own share sheet (WhatsApp, Instagram...).
        if (navigator.share) navigator.share(data).catch(() => {});
        else copyText(data.url);
        return;
    }

    const pw = e.target.closest('[data-pw-toggle]');
    if (pw) {
        const input = pw.previousElementSibling;
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        pw.textContent = show ? 'Hide' : 'Show';
        return;
    }

    const qr = e.target.closest('[data-qr-toggle]');
    if (qr) {
        const panel = $('[data-qr-panel]');
        panel.hidden = !panel.hidden;
        qr.setAttribute('aria-expanded', String(!panel.hidden));
    }
});

document.addEventListener('submit', (e) => {
    const msg = e.target.dataset.confirm;
    if (msg && !confirm(msg)) e.preventDefault();
});

$$('[data-counter]').forEach((field) => {
    const max = Number(field.maxLength);
    const out = Object.assign(document.createElement('p'), { className: 'counter' });
    field.after(out);
    const update = () => {
        const left = max - field.value.length;
        out.textContent = `${left.toLocaleString()} characters left`;
        out.classList.toggle('is-near', left < max * 0.1);
    };
    field.addEventListener('input', update);
    update();
});

$$('[data-slugify]').forEach((input) => {
    input.addEventListener('input', () => {
        const clean = input.value.toLowerCase().replace(/^@/, '').replace(/[^a-z0-9_]/g, '').slice(0, 20);
        if (clean !== input.value) input.value = clean;
    });
});

/* ---- Explore: live search ------------------------------------------------------- */

const explore = $('[data-explore]');
if (explore) {
    const results = $('[data-results]');
    const empty = $('[data-empty]');
    const count = $('[data-count]');
    const tpl = $('#card-tpl');
    let controller;

    const render = (events) => {
        results.replaceChildren(...events.map((ev) => {
            const card = tpl.content.firstElementChild.cloneNode(true);
            card.href = ev.url;
            const cover = $('.card-cover', card);
            if (ev.cover) {
                const img = Object.assign(document.createElement('img'), { src: ev.cover, alt: '', loading: 'lazy' });
                cover.prepend(img);
            } else {
                cover.style.setProperty('--h', ev.hue);
                const mono = Object.assign(document.createElement('span'), { className: 'card-mono', textContent: ev.title.charAt(0) });
                cover.prepend(mono);
            }
            // textContent (never innerHTML) so event names can't inject HTML.
            $('.date-chip b', card).textContent = ev.day;
            $('.date-chip', card).append(ev.month);
            $('h3', card).textContent = ev.title;
            $('[data-f=when]', card).textContent = ev.when;
            $('[data-f=venue]', card).textContent = ev.venue;
            $('[data-f=host]', card).textContent = 'by ' + ev.host;
            const tag = $('[data-f=price]', card);
            if (ev.sold_out) { tag.textContent = 'Sold out'; tag.classList.add('tag-dark'); }
            else if (ev.price) tag.textContent = ev.price;
            else tag.remove();
            return card;
        }));
        empty.hidden = events.length > 0;
        count.textContent = `${events.length} ${events.length === 1 ? 'event' : 'events'}`;
    };

    const search = async () => {
        const params = new URLSearchParams(new FormData(explore));
        for (const [k, v] of [...params]) if (!v) params.delete(k);
        history.replaceState(null, '', '/explore' + (params.size ? '?' + params : ''));

        controller?.abort();   // a newer search replaces an older one still loading
        controller = new AbortController();
        results.classList.add('is-loading');
        try {
            const res = await fetch('/api/events?' + params, { signal: controller.signal });
            render((await res.json()).events);
        } catch (err) {
            if (err.name !== 'AbortError') toast('Couldn\'t load events. Check your connection.');
        } finally {
            results.classList.remove('is-loading');
        }
    };

    explore.addEventListener('input', debounce(search, 250));
    explore.addEventListener('change', search);
    explore.addEventListener('submit', (e) => { e.preventDefault(); search(); });
}

/* ---- Event form ------------------------------------------------------------------- */

const eventForm = $('[data-event-form]');
if (eventForm) {
    // Cover photo: drag and drop, then shrink it in the browser before upload,
    // so a 12 MB phone photo becomes ~300 KB and uploads fast on mobile data.
    const zone = $('[data-dropzone]');
    const input = $('[data-cover-input]');
    const preview = $('[data-cover-preview]');
    const emptyState = $('.dz-empty', zone);
    const miniCover = $('[data-preview-cover]');

    const showCover = (url) => {
        preview.src = url;
        preview.hidden = false;
        emptyState.hidden = true;
        miniCover.style.backgroundImage = `url("${url}")`;
    };
    if (!preview.hidden) miniCover.style.backgroundImage = `url("${preview.src}")`;

    const shrink = (file) => new Promise((resolve) => {
        const img = new Image();
        img.onload = () => {
            const max = 2000;
            const scale = Math.min(1, max / img.width);
            if (scale === 1 && file.size < 1.5e6) return resolve(file);
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(img.width * scale);
            canvas.height = Math.round(img.height * scale);
            canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
            canvas.toBlob((blob) => resolve(blob ? new File([blob], 'cover.jpg', { type: 'image/jpeg' }) : file), 'image/jpeg', 0.86);
        };
        img.onerror = () => resolve(file);
        img.src = URL.createObjectURL(file);
    });

    const useFile = async (file) => {
        if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type)) {
            toast('Use a JPG, PNG or WebP image');
            input.value = '';
            return;
        }
        const small = await shrink(file);
        // Put the smaller file back into the real <input> so the normal form post sends it.
        const dt = new DataTransfer();
        dt.items.add(small);
        input.files = dt.files;
        showCover(URL.createObjectURL(small));
        $('.dz-badge', zone)?.remove();
        zone.insertAdjacentHTML('beforeend', `<span class="dz-badge">${(small.size / 1024).toFixed(0)} KB</span>`);
    };

    input.addEventListener('change', () => useFile(input.files[0]));
    ['dragenter', 'dragover'].forEach((t) => zone.addEventListener(t, (e) => { e.preventDefault(); zone.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach((t) => zone.addEventListener(t, () => zone.classList.remove('is-over')));
    zone.addEventListener('drop', (e) => { e.preventDefault(); useFile(e.dataTransfer.files[0]); });

    // Timezone: new events default to the organizer's own timezone if we list it.
    const tz = $('[data-timezone]');
    if (tz.hasAttribute('data-detect')) {
        const mine = Intl.DateTimeFormat().resolvedOptions().timeZone;
        if ([...tz.options].some((o) => o.value === mine)) tz.value = mine;
    }

    // Dates: when the start changes, keep the end after it (default: 3 hours later).
    const start = $('[data-start]');
    const end = $('[data-end]');
    const duration = $('[data-duration]');
    const pad = (n) => String(n).padStart(2, '0');
    const toInput = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    const describe = () => {
        if (!start.value || !end.value) { duration.textContent = ''; return; }
        const mins = (new Date(end.value) - new Date(start.value)) / 60000;
        if (mins <= 0) { duration.textContent = 'The end is before the start.'; return; }
        const h = Math.floor(mins / 60), m = mins % 60;
        duration.textContent = 'Lasts ' + [h && `${h} h`, m && `${m} min`].filter(Boolean).join(' ') + '.';
    };
    start.addEventListener('change', () => {
        if (start.value && (!end.value || end.value <= start.value)) {
            const d = new Date(start.value);
            d.setHours(d.getHours() + 3);
            end.value = toInput(d);
        }
        describe(); updatePreview();
    });
    end.addEventListener('change', describe);
    describe();

    // Ticket types: add and remove rows.
    const types = $('[data-types]');
    const maxTypes = Number(types.dataset.max);
    const addBtn = $('[data-add-type]');
    const syncTypes = () => {
        const rows = $$('[data-type-row]', types);
        addBtn.disabled = rows.length >= maxTypes;
        rows.forEach((r) => {
            const btn = $('[data-remove-type]', r);
            if (!btn.title) btn.disabled = rows.length === 1;   // keep at least one; titled = has sales
        });
        updatePreview();
    };
    addBtn.addEventListener('click', () => {
        const row = $('[data-type-row]', types).cloneNode(true);
        $$('input', row).forEach((i) => { i.value = i.type === 'hidden' ? '0' : ''; });
        $('[data-price]', row).value = '0';
        $('.type-sold', row)?.remove();
        const btn = $('[data-remove-type]', row);
        btn.removeAttribute('title');
        btn.disabled = false;
        types.append(row);
        $('input[name="type_name[]"]', row).focus();
        syncTypes();
    });
    types.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-remove-type]');
        if (btn && !btn.disabled) { btn.closest('[data-type-row]').remove(); syncTypes(); }
    });
    types.addEventListener('input', updatePreview);
    syncTypes();

    // Live preview of the event card.
    function updatePreview() {
        $('[data-preview-title]').textContent = $('#title').value.trim() || 'Your event name';
        $('[data-preview-when]').textContent = start.value
            ? new Date(start.value).toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })
            : 'Pick a date';
        const prices = $$('[data-price]', types).map((i) => parseFloat(i.value.replace(/[^\d.]/g, '')) || 0);
        const low = Math.min(...prices);
        $('[data-preview-price]').textContent = !prices.length ? '' : low === 0 ? 'Free tickets available' : 'From ' + money(low * 100);
    }
    $('#title').addEventListener('input', updatePreview);
    updatePreview();

    // Stop double submits (and show progress while a photo uploads).
    eventForm.addEventListener('submit', () => {
        const btn = $('[data-submit]');
        setTimeout(() => { btn.disabled = true; btn.textContent = 'Saving…'; }, 0);
    });
}

/* ---- Buying tickets: quantity steppers and live total ------------------------------ */

const buy = $('[data-buy]');
if (buy) {
    const max = Number(buy.dataset.max);
    const symbol = buy.dataset.symbol;
    const steppers = $$('[data-stepper]', buy);
    const totalEl = $('[data-total]');
    const btn = $('[data-buy-btn]');
    const buyer = $('[data-buyer]');

    const update = () => {
        let count = 0, total = 0;
        steppers.forEach((s) => {
            const input = $('input', s);
            const left = Number(s.dataset.left);
            let q = Math.max(0, Math.min(parseInt(input.value, 10) || 0, left));
            input.value = q;
            count += q;
            total += q * Number(s.dataset.price);
            s.closest('.tier').classList.toggle('is-picked', q > 0);
        });
        // Can't go over the per-order limit across all ticket types.
        steppers.forEach((s) => {
            const q = Number($('input', s).value);
            $('[data-step="1"]', s).disabled = count >= max || q >= Number(s.dataset.left);
            $('[data-step="-1"]', s).disabled = q === 0;
        });
        const text = count === 0 ? '–' : total === 0 ? 'Free' : money(total, symbol);
        if (totalEl.textContent !== text) {
            totalEl.textContent = text;
            totalEl.classList.remove('bump'); void totalEl.offsetWidth; totalEl.classList.add('bump');
        }
        btn.disabled = count === 0;
        btn.textContent = count === 0 ? 'Choose tickets'
            : (total === 0 ? `Get ${count} free ${count === 1 ? 'ticket' : 'tickets'}` : `Pay ${money(total, symbol)}`);
        buyer.classList.toggle('is-hidden', count === 0 && !$('.alert', buy));
    };

    buy.addEventListener('click', (e) => {
        const step = e.target.closest('[data-step]');
        if (!step) return;
        const input = $('input', step.closest('[data-stepper]'));
        input.value = (parseInt(input.value, 10) || 0) + Number(step.dataset.step);
        update();
    });
    buy.addEventListener('input', (e) => { if (e.target.closest('[data-stepper]')) update(); });
    buy.addEventListener('submit', () => { setTimeout(() => { btn.disabled = true; btn.textContent = 'One moment…'; }, 0); });
    update();
}

/* ---- Countdowns ---------------------------------------------------------------------- */

$$('[data-countdown]').forEach((el) => {
    const target = new Date(el.dataset.countdown).getTime();
    const parts = Object.fromEntries($$('[data-u]', el).map((b) => [b.dataset.u, b]));
    const tick = () => {
        let s = Math.max(0, Math.floor((target - Date.now()) / 1000));
        const d = Math.floor(s / 86400); s -= d * 86400;
        const h = Math.floor(s / 3600); s -= h * 3600;
        const m = Math.floor(s / 60); s -= m * 60;
        parts.d.textContent = d; parts.h.textContent = h; parts.m.textContent = String(m).padStart(2, '0'); parts.s.textContent = String(s).padStart(2, '0');
        if (target <= Date.now()) { clearInterval(timer); location.reload(); }
    };
    const timer = setInterval(tick, 1000);
    tick();
});

// "Tickets held until 14:32" on the demo checkout, in the buyer's own time.
$$('[data-hold]').forEach((el) => {
    el.textContent = new Date(el.dataset.hold).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
});

/* ---- Dashboard: animated numbers and the sales chart ---------------------------------- */

$$('[data-count-up]').forEach((el) => {
    const target = Number(el.dataset.countUp);
    if (!target || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const isMoney = el.hasAttribute('data-money');
    const t0 = performance.now();
    const frame = (now) => {
        const p = Math.min(1, (now - t0) / 900);
        const v = Math.round(target * (1 - Math.pow(1 - p, 3)));   // ease-out
        el.textContent = isMoney ? money(v, el.dataset.symbol) : v.toLocaleString();
        if (p < 1) requestAnimationFrame(frame);
    };
    requestAnimationFrame(frame);
});

$$('[data-chart]').forEach((box) => {
    const data = JSON.parse(box.dataset.chart);
    const canvas = $('canvas', box);
    const tip = $('.chart-tip', box);
    const ctx = canvas.getContext('2d');
    const symbol = box.dataset.symbol;
    let metric = 'tickets';
    let bars = [];
    let progress = 0;

    const fmt = (v) => metric === 'revenue' ? money(v, symbol) : `${v} ${v === 1 ? 'ticket' : 'tickets'}`;
    const label = (day) => new Date(day + 'T00:00:00Z').toLocaleDateString(undefined, { day: 'numeric', month: 'short', timeZone: 'UTC' });

    function draw() {
        // Draw at the screen's real pixel density so it's sharp on phones.
        const dpr = window.devicePixelRatio || 1;
        const w = box.clientWidth, h = box.clientHeight;
        canvas.width = w * dpr; canvas.height = h * dpr;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, w, h);

        const values = data.map((d) => d[metric]);
        const max = Math.max(...values, metric === 'revenue' ? 100 : 4);
        const pad = { l: 8, r: 8, t: 12, b: 26 };
        const plotH = h - pad.t - pad.b;
        const step = (w - pad.l - pad.r) / data.length;
        const barW = Math.max(3, step * 0.62);

        // Grid lines
        ctx.strokeStyle = '#efeff2';
        ctx.lineWidth = 1;
        for (let i = 0; i <= 3; i++) {
            const y = Math.round(pad.t + plotH * (i / 3)) + 0.5;
            ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(w - pad.r, y); ctx.stroke();
        }

        bars = data.map((d, i) => {
            const bh = Math.max(d[metric] ? 3 : 1.5, (d[metric] / max) * plotH * progress);
            const x = pad.l + i * step + (step - barW) / 2;
            const y = pad.t + plotH - bh;
            ctx.fillStyle = d[metric] ? '#e8483b' : '#e3e3e8';
            ctx.beginPath();
            ctx.roundRect ? ctx.roundRect(x, y, barW, bh, [4, 4, 0, 0]) : ctx.rect(x, y, barW, bh);
            ctx.fill();
            return { x, y, w: barW, d };
        });

        // First, middle and last dates under the bars
        ctx.fillStyle = '#8b8b93';
        ctx.font = '12px Inter, sans-serif';
        [[0, 'left'], [Math.floor(data.length / 2), 'center'], [data.length - 1, 'right']].forEach(([i, align]) => {
            ctx.textAlign = align;
            const x = align === 'left' ? pad.l : align === 'right' ? w - pad.r : bars[i].x + barW / 2;
            ctx.fillText(label(data[i].day), x, h - 6);
        });
    }

    const animate = () => {
        const t0 = performance.now();
        const frame = (now) => {
            progress = Math.min(1, (now - t0) / 600);
            progress = 1 - Math.pow(1 - progress, 3);
            draw();
            if (progress < 1) requestAnimationFrame(frame);
        };
        requestAnimationFrame(frame);
    };

    canvas.addEventListener('mousemove', (e) => {
        const r = canvas.getBoundingClientRect();
        const x = e.clientX - r.left;
        const bar = bars.find((b) => x >= b.x - 2 && x <= b.x + b.w + 2);
        if (!bar) { tip.hidden = true; return; }
        tip.hidden = false;
        tip.textContent = `${label(bar.d.day)}: ${fmt(bar.d[metric])}`;
        tip.style.left = bar.x + bar.w / 2 + 'px';
        tip.style.top = bar.y + 'px';
    });
    canvas.addEventListener('mouseleave', () => { tip.hidden = true; });
    new ResizeObserver(() => draw()).observe(box);

    $$('[data-metric]').forEach((r) => r.addEventListener('change', () => { metric = r.value; animate(); }));
    animate();
});

/* ---- Tables: search, filter and sort (guest list) ---------------------------------- */

$$('table[data-sortable]').forEach((table) => {
    const tbody = $('tbody', table);
    const rows = $$('tr', tbody);
    const search = $(`[data-table-search="${table.id}"]`);
    const filters = $$(`[data-table-filter="${table.id}"]`);
    const counter = $(`[data-table-count="${table.id}"]`);

    const apply = () => {
        const q = (search?.value || '').trim().toLowerCase();
        const f = filters.find((r) => r.checked)?.value || 'all';
        let shown = 0;
        rows.forEach((tr) => {
            const matchText = !q || tr.textContent.toLowerCase().includes(q);
            const matchFilter = f === 'all' || (f === 'in') === (tr.dataset.in === '1');
            tr.hidden = !(matchText && matchFilter);
            if (!tr.hidden) shown++;
        });
        if (counter) counter.textContent = `Showing ${shown} of ${rows.length}`;
    };
    search?.addEventListener('input', apply);
    filters.forEach((r) => r.addEventListener('change', apply));

    $$('th[data-sort]', table).forEach((th, col) => {
        th.tabIndex = 0;
        const sort = () => {
            const asc = th.getAttribute('aria-sort') !== 'ascending';
            $$('th', table).forEach((h) => h.removeAttribute('aria-sort'));
            th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
            const val = (tr) => tr.children[col].dataset.v ?? tr.children[col].textContent.trim().toLowerCase();
            const num = th.dataset.sort === 'num';
            rows.sort((a, b) => {
                const [x, y] = [val(a), val(b)];
                const c = num ? Number(x) - Number(y) : x.localeCompare(y);
                return asc ? c : -c;
            });
            tbody.append(...rows);
        };
        th.addEventListener('click', sort);
        th.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sort(); } });
    });
    apply();
});

/* ---- Ticket page: a little celebration, and waiting for payment -------------------- */

const confetti = $('[data-confetti]');
if (confetti && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const colors = ['#e8483b', '#ffb199', '#ffd166', '#111', '#ff7a5c'];
    for (let i = 0; i < 70; i++) {
        const bit = document.createElement('i');
        bit.style.left = Math.random() * 100 + '%';
        bit.style.background = colors[i % colors.length];
        bit.style.setProperty('--dx', (Math.random() * 160 - 80) + 'px');
        bit.style.setProperty('--r', (Math.random() * 720 - 360) + 'deg');
        bit.style.animationDelay = Math.random() * 0.4 + 's';
        confetti.append(bit);
    }
}

$$('[data-auto-refresh]').forEach((el) => {
    setTimeout(() => location.reload(), Number(el.dataset.autoRefresh) * 1000);
});
