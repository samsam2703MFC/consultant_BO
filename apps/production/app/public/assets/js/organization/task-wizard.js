// [247] Rama kreatora zadania (makiety 19–23) : te same klocki we wszystkich kreatorach « lżej ».
// tw.frame({...}) = nagłówek (← Mój tydzień, tytuł, kroki) + treść + stopka z jednym przyciskiem głównym.
(() => {
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    // Liczby : zawsze z odstępem tysięcy (« 5 430 », jak « 12 638 ») i przecinkiem — kolumny wyrównane do prawej.
    const nf = (v, d = 0) => {
        const n = Number(v || 0), [i, f] = Math.abs(n).toFixed(d).split('.');
        return (n < 0 && Number(Math.abs(n).toFixed(d)) !== 0 ? '−' : '') + i.replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0') + (f ? ',' + f : '');
    };
    const tw = {
        esc, nf,
        zl: (v) => nf(v) + ' zł',
        pct: (v, d = 0) => v === null || v === undefined ? '—' : nf(v, d) + ' %',
        signed: (v, d = 0, suffix = '') => v === null || v === undefined ? '—' : (v > 0 ? '+' : (v < 0 ? '−' : '')) + nf(Math.abs(v), d) + suffix,
        plural: (n, one, few, many) => n === 1 ? one : ([2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? few : many),
        dm: (d) => d ? `${Number(d.slice(8, 10))}.${d.slice(5, 7)}` : '—',
        /** {title, sub, steps: [label], step, body, prev: label|null, next: {label, attr, final, disabled}, later: bool} */
        frame(o) {
            const steps = o.steps.map((l, i) => {
                const n = i + 1, cls = n < o.step ? 'ok' : (n === o.step ? 'on' : '');
                return `<button type="button" class="${cls}" data-tw-step="${n}" ${n > (o.reached || o.step) ? 'disabled' : ''}><i>${n < o.step ? '✓' : n}</i><span>${esc(l)}</span></button>`;
            }).join('<em></em>');
            const next = o.next ? `<button type="button" class="tw-btn" ${o.next.attr || ''} ${o.next.disabled ? 'disabled' : ''}>${esc(o.next.label)} <i class="bi ${o.next.final ? 'bi-check2-circle' : 'bi-arrow-right'}"></i></button>` : '';
            return `<div class="tw"><div class="tw-top"><a class="tw-back" href="${at.root}/today"><i class="bi bi-arrow-left"></i> Mój tydzień</a>
                <div class="tw-title"><h4>${esc(o.title)}</h4><small>${esc(o.sub || '')}</small></div><span class="tw-sp"></span><div class="tw-steps">${steps}</div></div>
                <div class="tw-body">${o.body}</div>
                <div class="tw-foot">${o.prev ? `<button type="button" class="tw-link" data-tw-prev><i class="bi bi-arrow-left"></i> ${esc(o.prev)}</button>` : ''}<span class="tw-sp"></span>
                    ${o.extra || ''}${o.later ? `<a class="tw-link" href="${at.root}/today">Wróć później</a>` : ''}${next}</div></div>`;
        },
        tiles(items, n) {
            return `<div class="tw-tiles" style="--n:${n || items.length}">${items.map(t => `<div class="tw-tile ${t.cls || ''}"><span>${esc(t.label)}</span><b>${t.html ?? esc(t.value)}</b><small>${t.subHtml ?? esc(t.sub || '')}</small></div>`).join('')}</div>`;
        },
        /** head: [{label, num}] ; rows: [{cells: [html], cls, attr}] */
        table(head, rows, empty, cls = '') {
            if (!rows.length) return `<p class="tw-empty">${esc(empty || 'Brak danych.')}</p>`;
            return `<div class="table-responsive"><table class="tw-tb ${cls}"><thead><tr>${head.map(h => `<th class="${h.num ? 'num' : ''}">${esc(h.label)}</th>`).join('')}</tr></thead>
                <tbody>${rows.map(r => `<tr class="${r.cls || ''}" ${r.attr || ''}>${r.cells.map((c, i) => `<td class="${head[i] && head[i].num ? 'num' : ''}">${c ?? ''}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
        },
        pill: (t, cls = '') => `<span class="tw-pill ${cls}">${esc(t)}</span>`,
        gbar(p, cls = '') { return `<span class="tw-gbar"><i class="${cls}" style="width:${Math.max(0, Math.min(100, p || 0))}%"></i></span>`; },
        check: (name, on, text, small = '') => `<label class="tw-ck"><input type="checkbox" data-tw-ck="${esc(name)}" ${on ? 'checked' : ''}><span>${esc(text)}${small ? `<small>${esc(small)}</small>` : ''}</span></label>`,
        done: (title, sub) => `<div class="tw-done"><span class="ic"><i class="bi bi-check-lg"></i></span><div><b>${esc(title)}</b><small>${esc(sub || '')}</small></div></div>`,
        alert: (html, cls = '', icon = 'bi-exclamation-triangle', actions = '') => `<div class="tw-alert ${cls}"><i class="bi ${icon}"></i><div>${html}</div>${actions}</div>`,
        /** POST/PUT JSON → {ok, message, ...} ; błąd pokazany w dymku. */
        async post(url, body, method = 'POST') {
            const res = await at.send(url, method, body);
            if (!res.ok) at.notify(false, res.message || 'Błąd zapisu.');
            return res;
        },
        /** Wspólne zdarzenia : kroki (kliknięcie wróconego kroku) i « Wstecz ». */
        bind(root, go) {
            root.addEventListener('click', (e) => {
                const s = e.target.closest('[data-tw-step]');
                if (s && !s.disabled) { go(Number(s.dataset.twStep)); return; }
                if (e.target.closest('[data-tw-prev]')) go(-1);
            });
        },
    };
    window.tw = tw;
})();
