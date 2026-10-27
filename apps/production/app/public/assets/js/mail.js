/*
 * Poczta użytkownika (/mail) i ustawienia jego skrzynki (/mail/settings).
 * Skrzynka : foldery (Odebrane, Wysłane, Wysłane z ERP), lista z wyszukiwaniem pełnotekstowym, odczyt (HTML w ramce
 * bez skryptów i bez obrazów z internetu, dopóki użytkownik ich nie pokaże), załączniki, odpowiedź, przekazanie,
 * nowa wiadomość z załącznikami. Synchronizacja przy otwarciu i co 2 minuty, gdy strona jest widoczna.
 * Tłumacz DeepL : wiadomość przychodząca na język użytkownika (tfb.lang : pl / fr), odpowiedź na język klienta (karta
 * klienta, inaczej język wiadomości) ; podpis, stopka firmy w języku wiadomości i cytat dodaje serwer pod treścią.
 */
(function () {
    'use strict';
    var app = document.getElementById('mailApp');
    if (!app) return;
    var root = (app.dataset.root || '').replace(/\/$/, '');
    var page = app.dataset.page;
    var state = null;
    var view = { folder: 'INBOX', q: '', page: 1, rows: [], current: null, pages: 1 };

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }
    function api(method, path, body) {
        return fetch(root + path, {
            method: method, credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
            body: body === undefined ? undefined : JSON.stringify(body)
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (!r.ok) { var e = new Error(j.message || 'Błąd (' + r.status + ')'); e.fields = j.fields || null; throw e; }
                return j;
            });
        });
    }
    function when(value) {
        if (!value) return '';
        var d = new Date(String(value).replace(' ', 'T'));
        if (isNaN(d)) return value;
        var now = new Date();
        if (d.toDateString() === now.toDateString()) return d.toTimeString().slice(0, 5);
        return d.toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit', year: d.getFullYear() === now.getFullYear() ? undefined : '2-digit' });
    }
    function full(value) {
        var d = new Date(String(value || '').replace(' ', 'T'));
        return isNaN(d) ? (value || '') : d.toLocaleString('pl-PL', { dateStyle: 'medium', timeStyle: 'short' });
    }
    function uiLang() {
        try { return window.localStorage.getItem('tfb.lang') === 'fr' ? 'fr' : 'pl'; } catch (e) { return 'pl'; }
    }
    function canTranslate() { return !!(state && state.translator && state.translator.configured); }
    function toLabel(code) { return (state.translator.to || {})[code] || String(code || '').toUpperCase(); }
    var PO = { pl: 'polsku', fr: 'francusku', nl: 'niderlandzku', en: 'angielsku', de: 'niemiecku' };
    function fromLabel(code) { return (state.translator.from || {})[code] || String(code || '').toUpperCase(); }
    function size(n) { return n > 1048576 ? (n / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(n / 1024)) + ' kB'; }
    function headerButtons(show) {
        document.querySelectorAll('[data-sync], [data-compose]').forEach(function (b) { b.hidden = !show; });
    }

    // ===================================== skrzynka =====================================
    function inbox() {
        api('GET', '/ajax/mail/state').then(function (s) {
            state = s;
            if (!s.configured) {
                app.innerHTML = '<div class="card"><div class="card-body text-center py-5"><i class="bi bi-envelope-plus" style="font-size:40px;color:#8D1D2C"></i>'
                    + '<h4 class="mt-3">Podłącz swoją skrzynkę e-mail</h4><p class="text-muted">Odbieraj i wysyłaj wiadomości ze swojego adresu bez wychodzenia z ERP :'
                    + ' wiadomości od klientów i dostawców są oznaczone, a wyszukiwarka u góry znajduje je po temacie i treści.</p>'
                    + '<a class="btn btn-primary" href="' + esc(root) + '/mail/settings">Podłącz skrzynkę</a></div></div>';
                return;
            }
            headerButtons(true);
            var params = new URLSearchParams(window.location.search);
            if (params.get('folder') && s.folders[params.get('folder')]) view.folder = params.get('folder');
            shell();
            loadList();
            if (/^\d+$/.test(params.get('id') || '')) open(Number(params.get('id')));
            sync(false);
            window.setInterval(function () { if (!document.hidden) sync(true); }, 120000);
        }).catch(function (e) { app.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>'; });
    }

    function shell() {
        var banner = state.delivery.mode !== 'live'
            ? '<div class="ml-banner"><i class="bi bi-shield-check"></i> ' + esc(state.delivery.label) + ' (folder « Wysłane z ERP » pokazuje, co zostałoby wysłane).</div>' : '';
        app.innerHTML = banner + '<div class="ml-status text-muted mb-1" data-status></div><div class="ml-shell">'
            + '<nav class="ml-folders" data-folders></nav>'
            + '<div class="ml-list"><div class="ml-search"><input type="search" class="form-control form-control-sm" data-q placeholder="Szukaj : temat, nadawca, treść…" aria-label="Szukaj w poczcie"></div>'
            + '<div class="ml-rows" data-rows></div></div>'
            + '<div class="ml-reader" data-reader><div class="ml-empty"><i class="bi bi-envelope-open" style="font-size:32px"></i><p class="mt-2">Wybierz wiadomość.</p></div></div></div>';
        folders();
        var timer = null;
        app.querySelector('[data-q]').addEventListener('input', function (e) {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { view.q = e.target.value.trim(); view.page = 1; loadList(); }, 300);
        });
        app.querySelector('[data-rows]').addEventListener('click', function (e) {
            var more = e.target.closest('[data-more]');
            if (more) { view.page++; loadList(true); return; }
            var row = e.target.closest('[data-id]');
            if (row) open(Number(row.dataset.id));
        });
        app.querySelector('[data-folders]').addEventListener('click', function (e) {
            var b = e.target.closest('[data-folder]');
            if (!b) return;
            view.folder = b.dataset.folder; view.page = 1; view.current = null;
            folders(); loadList();
            app.querySelector('[data-reader]').innerHTML = '<div class="ml-empty"><p>Wybierz wiadomość.</p></div>';
        });
    }

    function folders() {
        var icons = { INBOX: 'bi-inbox', SENT: 'bi-send', ERP: 'bi-journal-check' };
        var counts = state.counts || {};
        app.querySelector('[data-folders]').innerHTML = Object.keys(state.folders).map(function (k) {
            var n = k === 'INBOX' ? (counts.INBOX || 0) : 0;
            return '<button type="button" class="ml-folder ' + (view.folder === k ? 'is-on' : '') + '" data-folder="' + k + '"><i class="bi ' + icons[k] + '"></i> '
                + esc(state.folders[k]) + (n ? '<span class="ml-n">' + n + '</span>' : '') + '</button>';
        }).join('') + '<div class="ml-account"><i class="bi bi-person-circle"></i> ' + esc(state.mailbox.email)
            + (state.mailbox.last_sync_at ? '<br>synchronizacja ' + esc(full(state.mailbox.last_sync_at)) : '') + '</div>';
    }

    function status(text, error) {
        var el = app.querySelector('[data-status]');
        if (el) { el.textContent = text || ''; el.className = 'ml-status mb-1 ' + (error ? 'text-danger' : 'text-muted'); }
    }

    function sync(quiet) {
        var button = document.querySelector('[data-sync]');
        if (button) button.disabled = true;
        if (!quiet) status('Synchronizacja ze skrzynką…');
        return api('POST', '/ajax/mail/sync').then(function (r) {
            state.counts = r.counts;
            state.mailbox.last_sync_at = new Date().toISOString().slice(0, 19).replace('T', ' ');
            folders();
            status(r.new ? 'Nowe wiadomości : ' + r.new + '.' : (quiet ? '' : 'Skrzynka aktualna.'));
            if (r.new || !quiet) loadList();
        }).catch(function (e) { status('Synchronizacja nie powiodła się : ' + e.message, true); })
            .then(function () { if (button) button.disabled = false; });
    }

    function loadList(append) {
        var box = app.querySelector('[data-rows]');
        if (!append) box.innerHTML = '<p class="text-muted p-3">Wczytywanie…</p>';
        api('GET', '/ajax/mail/messages?folder=' + encodeURIComponent(view.folder) + '&q=' + encodeURIComponent(view.q) + '&page=' + view.page).then(function (r) {
            view.rows = append ? view.rows.concat(r.rows) : r.rows;
            view.pages = r.pages;
            renderRows(r.total);
        }).catch(function (e) { box.innerHTML = '<p class="text-danger p-3">' + esc(e.message) + '</p>'; });
    }

    function renderRows(total) {
        var box = app.querySelector('[data-rows]');
        if (!view.rows.length) { box.innerHTML = '<p class="text-muted p-3">' + (view.q ? 'Nic nie znaleziono.' : 'Brak wiadomości.') + '</p>'; return; }
        box.innerHTML = view.rows.map(function (m) {
            var who = view.folder === 'INBOX' ? (m.from_name || m.from) : 'Do : ' + (m.to || '');
            var tags = (m.customer ? '<span class="ml-tag">klient' + (m.customer.name ? ' ' + esc(m.customer.name) : '') + '</span>' : '')
                + (m.supplier ? '<span class="ml-tag is-supplier">dostawca' + (m.supplier.name ? ' ' + esc(m.supplier.name) : '') + '</span>' : '')
                + (m.kind === 'erp' && m.mode !== 'live' ? '<span class="ml-tag is-test">' + (m.mode === 'log_only' ? 'tylko zapis' : 'adres testowy') + '</span>' : '')
                + (m.kind === 'erp' && m.status === 'failed' ? '<span class="ml-tag is-fail">błąd</span>' : '');
            return '<button type="button" class="ml-row ' + (m.kind === 'mail' && !m.seen ? 'is-unread ' : '') + (view.current === m.id ? 'is-on' : '') + '" data-id="' + m.id + '">'
                + '<div class="ml-top"><span class="ml-who">' + esc(who) + '</span>' + ((m.attachments_flag || (m.attachments && m.attachments.length)) ? '<i class="bi bi-paperclip"></i>' : '')
                + (m.answered ? '<i class="bi bi-reply" title="Odpowiedziano"></i>' : '') + '<span class="ml-date">' + esc(when(m.date)) + '</span></div>'
                + '<div class="ml-subj">' + esc(m.subject || '(bez tematu)') + '</div>'
                + (tags || m.snippet ? '<div class="ml-snip">' + tags + esc(m.snippet || '') + '</div>' : '') + '</button>';
        }).join('') + (view.page < view.pages ? '<div class="ml-more"><button type="button" class="btn btn-sm btn-outline-secondary" data-more>Wczytaj starsze (' + (total - view.rows.length) + ')</button></div>' : '');
    }

    function open(id) {
        view.current = id;
        app.querySelectorAll('.ml-row').forEach(function (r) { r.classList.toggle('is-on', Number(r.dataset.id) === id); });
        var reader = app.querySelector('[data-reader]');
        var row = view.rows.find(function (r) { return r.id === id; });
        if (row && row.kind === 'erp') return readErp(row);
        reader.innerHTML = '<div class="ml-empty">Wczytywanie wiadomości…</div>';
        api('GET', '/ajax/mail/messages/' + id).then(function (m) {
            if (row && !row.seen) {
                row.seen = true;
                var el = app.querySelector('.ml-row[data-id="' + id + '"]');
                if (el) el.classList.remove('is-unread');
                if (view.folder === 'INBOX' && state.counts.INBOX) { state.counts.INBOX--; folders(); }
            }
            readMail(m);
        }).catch(function (e) { reader.innerHTML = '<div class="ml-empty text-danger">' + esc(e.message) + '</div>'; });
    }

    function contactLinks(m) {
        return (m.customer ? ' <a class="ml-tag" href="' + esc(root) + '/clients/' + m.customer.id + '/edit">klient : ' + esc(m.customer.name || '#' + m.customer.id) + '</a>' : '')
            + (m.supplier ? ' <a class="ml-tag is-supplier" href="' + esc(root) + '/material-suppliers/' + m.supplier.id + '/edit">dostawca : ' + esc(m.supplier.name || '#' + m.supplier.id) + '</a>' : '');
    }

    function readMail(m) {
        var reader = app.querySelector('[data-reader]');
        var remote = /<img[^>]+src=["']?https?:/i.test(m.html || '');
        var target = uiLang();
        var guess = m.lang && m.lang.guess;
        var foreign = !!guess && guess !== target;
        reader.innerHTML = '<div class="ml-head"><h4>' + esc(m.subject || '(bez tematu)') + '</h4><div class="ml-meta">'
            + '<div><strong>Od :</strong> ' + esc(m.from_name ? m.from_name + ' <' + m.from + '>' : m.from) + contactLinks(m) + '</div>'
            + '<div><strong>Do :</strong> ' + esc(m.to || '') + '</div>' + (m.cc ? '<div><strong>DW :</strong> ' + esc(m.cc) + '</div>' : '')
            + '<div><strong>Data :</strong> ' + esc(full(m.date)) + '</div></div>'
            + (m.offline ? '<div class="ml-err mt-1">' + esc(m.offline) + '</div>' : '')
            + '<div class="ml-actions"><button type="button" class="btn btn-sm btn-primary" data-reply><i class="bi bi-reply"></i> Odpowiedz</button>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-reply-all><i class="bi bi-reply-all"></i> Odpowiedz wszystkim</button>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-forward><i class="bi bi-forward"></i> Przekaż</button>'
            + (m.html ? '<button type="button" class="btn btn-sm btn-link" data-plain>Pokaż jako tekst</button>' : '')
            + (remote ? '<button type="button" class="btn btn-sm btn-link" data-remote>Pokaż obrazy z internetu</button>' : '')
            + (canTranslate() ? '<button type="button" class="btn btn-sm ' + (foreign ? 'btn-outline-primary' : 'btn-link') + '" data-translate><i class="bi bi-translate"></i> '
                + esc(foreign ? 'Przetłumacz z ' + fromLabel(guess) : 'Przetłumacz na ' + toLabel(target)) + '</button>' : '') + '</div></div>'
            + '<div class="ml-tr" data-tr hidden></div>'
            + '<div class="ml-body" data-body></div>'
            + (m.attachments.length ? '<div class="ml-files">' + m.attachments.map(function (a) {
                return '<a class="ml-file" href="' + esc(root) + '/mail/messages/' + m.id + '/attachments/' + a.index + '"><i class="bi bi-paperclip"></i> ' + esc(a.filename)
                    + ' <span class="text-muted">' + size(a.size) + '</span></a>';
            }).join('') + '</div>' : '');
        var body = reader.querySelector('[data-body]');
        function showHtml(allowRemote) {
            var frame = document.createElement('iframe');
            frame.setAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox');
            frame.setAttribute('referrerpolicy', 'no-referrer');
            frame.title = 'Treść wiadomości';
            frame.srcdoc = '<!doctype html><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:'
                + (allowRemote ? ' https: http:' : '') + '; style-src \'unsafe-inline\'; font-src data:"><base target="_blank">'
                + '<style>body{font-family:Arial,Helvetica,sans-serif;font-size:14px;margin:16px;color:#222;word-wrap:break-word}img{max-width:100%;height:auto}</style>' + m.html;
            body.innerHTML = '';
            body.appendChild(frame);
        }
        function showText() { body.innerHTML = '<pre class="ml-text">' + esc(m.text || '') + '</pre>'; }
        var trBox = reader.querySelector('[data-tr]');
        function showOriginal() { trBox.hidden = true; if (m.html) showHtml(false); else showText(); }
        function showTranslation(t) {
            var same = t.source && t.source === t.lang;
            trBox.hidden = false;
            trBox.innerHTML = '<i class="bi bi-translate"></i><span>' + esc(same ? 'Wiadomość jest już po ' + (PO[t.lang] || t.lang) + '.'
                : 'Przetłumaczono' + (t.source ? ' z ' + fromLabel(t.source) : '') + ' na ' + toLabel(t.lang) + ' (DeepL).') + '</span>'
                + '<button type="button" class="btn btn-sm btn-link p-0" data-orig>Pokaż oryginał</button>';
            body.innerHTML = (t.subject ? '<div class="ml-trsubj"><strong>Temat :</strong> ' + esc(t.subject) + '</div>' : '') + '<pre class="ml-text">' + esc(t.text || '') + '</pre>';
            trBox.querySelector('[data-orig]').addEventListener('click', showOriginal);
        }
        if (m.translation && m.translation.lang === target) showTranslation(m.translation); else showOriginal();
        var trBtn = reader.querySelector('[data-translate]');
        if (trBtn) trBtn.addEventListener('click', function () {
            trBtn.disabled = true;
            var label = trBtn.innerHTML;
            trBtn.textContent = 'Tłumaczenie…';
            api('POST', '/ajax/mail/messages/' + m.id + '/translate', { target: target }).then(function (t) {
                m.translation = t;
                showTranslation(t);
            }).catch(function (e) { status(e.message, true); }).then(function () { trBtn.disabled = false; trBtn.innerHTML = label; });
        });
        reader.querySelector('[data-reply]').addEventListener('click', function () { compose(replyDraft(m, false)); });
        reader.querySelector('[data-reply-all]').addEventListener('click', function () { compose(replyDraft(m, true)); });
        reader.querySelector('[data-forward]').addEventListener('click', function () {
            compose({ subject: (/^(fwd|pd):/i.test(m.subject || '') ? '' : 'Pd: ') + (m.subject || ''), text: '', quote: '---------- Przekazana wiadomość ----------\nOd : ' + m.from
                + '\nData : ' + full(m.date) + '\nTemat : ' + (m.subject || '') + '\n\n' + (m.text || ''), lang: 'pl',
                note: m.attachments.length ? 'Załączniki oryginału nie są przekazywane automatycznie.' : '' });
        });
        var plain = reader.querySelector('[data-plain]');
        if (plain) plain.addEventListener('click', function () { var t = plain.textContent === 'Pokaż jako tekst'; if (t) showText(); else showHtml(false); plain.textContent = t ? 'Pokaż oryginał' : 'Pokaż jako tekst'; });
        var rem = reader.querySelector('[data-remote]');
        if (rem) rem.addEventListener('click', function () { showHtml(true); rem.remove(); });
    }

    function readErp(m) {
        var reader = app.querySelector('[data-reader]');
        var mode = { live: 'Wysłano', redirect: 'Wysłano na adres testowy', log_only: 'Tylko zapisano (serwer testowy : nic nie wyszło)' }[m.mode] || m.mode;
        reader.innerHTML = '<div class="ml-head"><h4>' + esc(m.subject) + '</h4><div class="ml-meta"><div><strong>Do :</strong> ' + esc(m.to) + contactLinks(m) + '</div>'
            + (m.cc ? '<div><strong>DW :</strong> ' + esc(m.cc) + '</div>' : '') + '<div><strong>Data :</strong> ' + esc(full(m.date)) + '</div>'
            + '<div><strong>Stan :</strong> ' + (m.status === 'failed' ? '<span class="text-danger">błąd : ' + esc(m.error || '') + '</span>' : esc(mode)) + '</div>'
            + (m.lang ? '<div><strong>Język :</strong> ' + esc(toLabel(m.lang)) + (m.original ? ' · przetłumaczone <button type="button" class="btn btn-sm btn-link p-0" data-original>pokaż tekst przed tłumaczeniem</button>' : '') + '</div>' : '')
            + '</div></div>'
            + '<div class="ml-body"><pre class="ml-text" data-erpbody>' + esc(m.body) + '</pre></div>'
            + (m.attachments.length ? '<div class="ml-files">' + m.attachments.map(function (a) { return '<span class="ml-file"><i class="bi bi-paperclip"></i> ' + esc(a.name) + ' <span class="text-muted">' + size(a.size) + '</span></span>'; }).join('') + '</div>' : '');
        var ob = reader.querySelector('[data-original]');
        if (ob) ob.addEventListener('click', function () {
            var pre = reader.querySelector('[data-erpbody]');
            var showing = ob.dataset.on === '1';
            pre.textContent = showing ? m.body : m.original;
            ob.dataset.on = showing ? '' : '1';
            ob.textContent = showing ? 'pokaż tekst przed tłumaczeniem' : 'pokaż wysłany tekst';
        });
    }

    /** Język odpowiedzi : karta klienta → język oryginału (DeepL) → rozpoznany → polski. */
    function replyLang(m) {
        var l = (m.lang && m.lang.customer) || (m.translation && m.translation.source) || (m.lang && m.lang.guess) || 'pl';
        return state.langs[l] ? l : 'pl';
    }

    function replyDraft(m, all) {
        var me = (state.mailbox.email || '').toLowerCase();
        var to = view.folder === 'SENT' ? (m.to || '') : m.from;
        var cc = all ? [m.to, m.cc].filter(Boolean).join(', ').split(/,\s*/).filter(function (a) { return a && a.toLowerCase() !== me && a !== to; }).join(', ') : '';
        var quoted = (m.text || '').split('\n').map(function (l) { return '> ' + l; }).join('\n');
        return { to: to, cc: cc, subject: (/^(re|odp):/i.test(m.subject || '') ? '' : 'Re: ') + (m.subject || ''), reply_to_id: m.id, text: '',
            quote: full(m.date) + ', ' + (m.from_name || m.from) + ' napisał(a) :\n' + quoted, lang: replyLang(m),
            lang_source: m.lang && m.lang.customer ? 'karta klienta' + (m.customer && m.customer.name ? ' : ' + m.customer.name : '') : (m.translation || (m.lang && m.lang.guess) ? 'język wiadomości' : '') };
    }

    function compose(draft) {
        draft = draft || { text: '', lang: 'pl' };
        var original = null;
        var old = document.querySelector('.ml-compose');
        if (old) old.remove();
        var files = [];
        var panel = document.createElement('div');
        panel.className = 'ml-compose';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', 'Nowa wiadomość');
        panel.innerHTML = '<header><i class="bi bi-pencil-square"></i> ' + (draft.reply_to_id ? 'Odpowiedź' : 'Nowa wiadomość') + ' <span class="text-muted fw-normal small">z ' + esc(state.mailbox.email) + '</span>'
            + '<button type="button" class="btn-close ms-auto" data-close aria-label="Zamknij"></button></header><div class="ml-cbody">'
            + '<label class="small">Do<input class="form-control form-control-sm" name="to" value="' + esc(draft.to || '') + '" placeholder="adres@firma.pl, …"></label><div class="ml-err" data-err="to"></div>'
            + '<label class="small">DW<input class="form-control form-control-sm" name="cc" value="' + esc(draft.cc || '') + '"></label><div class="ml-err" data-err="cc"></div>'
            + '<label class="small">Temat<input class="form-control form-control-sm" name="subject" maxlength="300" value="' + esc(draft.subject || '') + '"></label><div class="ml-err" data-err="subject"></div>'
            + '<textarea class="form-control" name="text" aria-label="Treść" placeholder="Twoja wiadomość…">' + esc(draft.text || '') + '</textarea><div class="ml-err" data-err="text"></div>'
            + '<div class="ml-langbar"><i class="bi bi-translate"></i><label class="small mb-0">Język wiadomości <select class="form-select form-select-sm d-inline-block w-auto" data-lang>'
            + Object.keys(state.langs).map(function (k) { return '<option value="' + k + '"' + (k === (draft.lang || 'pl') ? ' selected' : '') + '>' + esc(toLabel(k)) + '</option>'; }).join('')
            + '</select></label><span class="small text-muted" data-langsrc>' + esc(draft.lang_source ? '(' + draft.lang_source + ')' : '') + '</span>'
            + (canTranslate() ? '<button type="button" class="btn btn-sm btn-outline-primary ms-auto" data-trbtn></button>' : '')
            + '</div><div class="small" data-trinfo></div>'
            + '<div class="ml-tail"><span data-taillabel></span> <button type="button" class="btn btn-sm btn-link p-0" data-tailshow>Pokaż</button></div><pre class="ml-tailtext" data-tailtext hidden></pre>'
            + '<div><label class="btn btn-sm btn-outline-secondary mb-0"><i class="bi bi-paperclip"></i> Załącz pliki<input type="file" multiple hidden data-files></label> <span class="small text-muted">najwyżej 5 MB razem</span></div>'
            + '<div data-file-list class="d-flex flex-wrap gap-1"></div><div class="ml-err" data-err="attachments"></div>'
            + (draft.note ? '<div class="small text-muted">' + esc(draft.note) + '</div>' : '') + '</div>'
            + '<footer><button type="button" class="btn btn-primary btn-sm" data-send><i class="bi bi-send"></i> Wyślij</button><span class="ml-status" data-cstatus></span></footer>';
        document.body.appendChild(panel);
        var ta = panel.querySelector('textarea');
        ta.focus();
        ta.setSelectionRange(0, 0);
        panel.querySelector('[data-close]').addEventListener('click', function () { panel.remove(); });
        var langSel = panel.querySelector('[data-lang]');
        var trBtn = panel.querySelector('[data-trbtn]');
        var trInfo = panel.querySelector('[data-trinfo]');
        function tail() {
            var lang = langSel.value;
            var footer = (state.footers || {})[lang];
            var parts = [], names = [];
            if (state.mailbox.signature) { parts.push('-- \n' + state.mailbox.signature); names.push('podpis'); }
            if (footer) { parts.push(footer); names.push('stopka (' + lang.toUpperCase() + ')'); }
            if (draft.quote) { parts.push(draft.quote); names.push(draft.reply_to_id ? 'cytat' : 'przekazana wiadomość'); }
            panel.querySelector('[data-taillabel]').textContent = names.length ? 'Pod treścią : ' + names.join(' · ') : 'Bez podpisu i stopki.';
            panel.querySelector('[data-tailshow]').hidden = !names.length;
            panel.querySelector('[data-tailtext]').textContent = parts.join('\n\n');
            if (trBtn) trBtn.textContent = 'Przetłumacz na ' + toLabel(lang);
        }
        tail();
        langSel.addEventListener('change', function () { panel.querySelector('[data-langsrc]').textContent = ''; tail(); });
        panel.querySelector('[data-tailshow]').addEventListener('click', function (e) {
            var pre = panel.querySelector('[data-tailtext]');
            pre.hidden = !pre.hidden;
            e.currentTarget.textContent = pre.hidden ? 'Pokaż' : 'Ukryj';
        });
        if (trBtn) trBtn.addEventListener('click', function () {
            if (!ta.value.trim()) { trInfo.textContent = 'Najpierw napisz wiadomość.'; trInfo.className = 'small text-danger'; return; }
            trBtn.disabled = true;
            trInfo.textContent = 'Tłumaczenie…'; trInfo.className = 'small text-muted';
            var before = ta.value;
            api('POST', '/ajax/translate', { text: before, target: langSel.value, context: 'mail_out' }).then(function (r) {
                if (original === null) original = before;
                ta.value = r.text;
                trInfo.className = 'small text-success';
                trInfo.innerHTML = esc(r.source && r.source === r.target ? 'Tekst jest już po ' + (PO[r.target] || r.target) + '.' : 'Przetłumaczono' + (r.source_label ? ' z ' + r.source_label : '') + ' na ' + r.target_label + ' — sprawdź przed wysłaniem.')
                    + ' <button type="button" class="btn btn-sm btn-link p-0" data-undo>Cofnij</button>';
                trInfo.querySelector('[data-undo]').addEventListener('click', function () { ta.value = original; original = null; trInfo.textContent = ''; });
            }).catch(function (e) { trInfo.textContent = e.message; trInfo.className = 'small text-danger'; })
                .then(function () { trBtn.disabled = false; });
        });
        // Nowa wiadomość : język z karty klienta po wpisaniu adresu.
        panel.querySelector('[name="to"]').addEventListener('change', function (e) {
            if (draft.reply_to_id || !e.target.value.trim()) return;
            api('GET', '/ajax/mail/contact-lang?email=' + encodeURIComponent(e.target.value)).then(function (r) {
                if (!r.lang || !state.langs[r.lang]) return;
                langSel.value = r.lang;
                panel.querySelector('[data-langsrc]').textContent = '(karta klienta' + (r.customer && r.customer.name ? ' : ' + r.customer.name : '') + ')';
                tail();
            }).catch(function () {});
        });
        function drawFiles() {
            panel.querySelector('[data-file-list]').innerHTML = files.map(function (f, i) {
                return '<span class="ml-file"><i class="bi bi-paperclip"></i> ' + esc(f.name) + ' <span class="text-muted">' + size(f.size) + '</span> <button type="button" class="btn btn-sm btn-link p-0" data-drop="' + i + '" aria-label="Usuń">×</button></span>';
            }).join('');
        }
        panel.querySelector('[data-file-list]').addEventListener('click', function (e) { var b = e.target.closest('[data-drop]'); if (b) { files.splice(Number(b.dataset.drop), 1); drawFiles(); } });
        panel.querySelector('[data-files]').addEventListener('change', function (e) {
            Array.prototype.forEach.call(e.target.files, function (file) {
                var reader = new FileReader();
                reader.onload = function () { files.push({ name: file.name, size: file.size, data: reader.result }); drawFiles(); };
                reader.readAsDataURL(file);
            });
            e.target.value = '';
        });
        panel.querySelector('[data-send]').addEventListener('click', function (e) {
            var btn = e.currentTarget;
            var f = function (n) { return panel.querySelector('[name="' + n + '"]').value; };
            panel.querySelectorAll('[data-err]').forEach(function (el) { el.textContent = ''; });
            if (files.reduce(function (s, x) { return s + x.size; }, 0) > 5 * 1024 * 1024) { panel.querySelector('[data-err="attachments"]').textContent = 'Załączniki razem najwyżej 5 MB.'; return; }
            btn.disabled = true;
            panel.querySelector('[data-cstatus]').textContent = 'Wysyłanie…';
            api('POST', '/ajax/mail/send', { to: f('to'), cc: f('cc'), subject: f('subject'), text: f('text'), reply_to_id: draft.reply_to_id || null,
                lang: langSel.value, quote: draft.quote || '', original_text: original,
                attachments: files.map(function (x) { return { name: x.name, data: x.data }; }) }).then(function (r) {
                panel.remove();
                status(r.message || 'Wysłano.');
                if (view.folder === 'ERP' || view.folder === 'SENT') loadList();
            }).catch(function (err) {
                btn.disabled = false;
                panel.querySelector('[data-cstatus]').textContent = err.message;
                Object.keys(err.fields || {}).forEach(function (k) { var el = panel.querySelector('[data-err="' + k + '"]'); if (el) el.textContent = err.fields[k]; });
            });
        });
    }

    var composeBtn = document.querySelector('[data-compose]');
    if (composeBtn) composeBtn.addEventListener('click', function () { compose(null); });
    var syncBtn = document.querySelector('[data-sync]');
    if (syncBtn) syncBtn.addEventListener('click', function () { sync(false); });

    // ===================================== ustawienia =====================================
    var DOMAINS = { 'gmail.com': 'gmail', 'googlemail.com': 'gmail', 'outlook.com': 'outlook', 'hotmail.com': 'outlook', 'live.com': 'outlook',
        'wp.pl': 'wp', 'onet.pl': 'onet', 'op.pl': 'onet', 'onet.eu': 'onet', 'o2.pl': 'o2', 'tlen.pl': 'o2', 'interia.pl': 'interia', 'interia.eu': 'interia' };

    function settings() {
        api('GET', '/ajax/mail/state').then(function (s) {
            state = s;
            var b = s.mailbox || { provider: 'gmail', imap_port: 993, imap_security: 'ssl', smtp_port: 587, smtp_security: 'starttls', tls_verify: true };
            var opt = function (v, cur, label) { return '<option value="' + v + '"' + (v === cur ? ' selected' : '') + '>' + esc(label) + '</option>'; };
            var sec = function (name, cur) { return '<select class="form-select form-select-sm" name="' + name + '">' + opt('ssl', cur, 'SSL / TLS') + opt('starttls', cur, 'STARTTLS') + '</select>'; };
            var field = function (label, html, name) { return '<label class="form-label small mb-1 d-block">' + label + html + '</label><div class="ml-err mb-2" data-err="' + name + '"></div>'; };
            var pwdHint = s.mailbox ? 'zostaw puste, aby nie zmieniać' : '';
            app.innerHTML = '<div class="ml-settings"><div class="card"><div class="card-body"><form data-form autocomplete="off">'
                + '<div class="row g-2"><div class="col-md-6">' + field('Dostawca poczty', '<select class="form-select" name="provider">' + Object.keys(s.providers).map(function (k) { return opt(k, b.provider, s.providers[k].label); }).join('') + '</select>', 'provider') + '</div>'
                + '<div class="col-md-6">' + field('Adres e-mail', '<input class="form-control" type="email" name="email" value="' + esc(b.email || '') + '">', 'email') + '</div>'
                + '<div class="col-md-6">' + field('Nazwa nadawcy', '<input class="form-control" name="display_name" maxlength="160" value="' + esc(b.display_name || '') + '" placeholder="np. Anna Kowalska — SL Food">', 'display_name') + '</div>'
                + '<div class="col-md-6">' + field('Hasło (lub hasło aplikacji)', '<input class="form-control" type="password" name="password" autocomplete="new-password" placeholder="' + esc(pwdHint) + '">', 'imap_password') + '</div></div>'
                + '<p class="ml-help" data-provider-help></p>'
                + '<details class="mb-3"' + (b.provider === 'other' ? ' open' : '') + '><summary class="small">Serwery (wypełniane według dostawcy)</summary><div class="row g-2 mt-1">'
                + '<div class="col-12 small fw-semibold">Odbiór — IMAP</div>'
                + '<div class="col-md-5">' + field('Serwer', '<input class="form-control form-control-sm" name="imap_host" value="' + esc(b.imap_host || '') + '">', 'imap_host') + '</div>'
                + '<div class="col-md-2">' + field('Port', '<input class="form-control form-control-sm" type="number" name="imap_port" value="' + esc(b.imap_port) + '">', 'imap_port') + '</div>'
                + '<div class="col-md-5">' + field('Szyfrowanie', sec('imap_security', b.imap_security), 'imap_security') + '</div>'
                + '<div class="col-md-6">' + field('Login (domyślnie adres)', '<input class="form-control form-control-sm" name="imap_username" value="' + esc(b.imap_username || '') + '">', 'imap_username') + '</div>'
                + '<div class="col-12 small fw-semibold mt-2">Wysyłka — SMTP</div>'
                + '<div class="col-md-5">' + field('Serwer', '<input class="form-control form-control-sm" name="smtp_host" value="' + esc(b.smtp_host || '') + '">', 'smtp_host') + '</div>'
                + '<div class="col-md-2">' + field('Port', '<input class="form-control form-control-sm" type="number" name="smtp_port" value="' + esc(b.smtp_port) + '">', 'smtp_port') + '</div>'
                + '<div class="col-md-5">' + field('Szyfrowanie', sec('smtp_security', b.smtp_security), 'smtp_security') + '</div>'
                + '<div class="col-md-6">' + field('Login (domyślnie adres)', '<input class="form-control form-control-sm" name="smtp_username" value="' + esc(b.smtp_username || '') + '">', 'smtp_username') + '</div>'
                + '<div class="col-md-6">' + field('Inne hasło SMTP (rzadko)', '<input class="form-control form-control-sm" type="password" name="smtp_password" autocomplete="new-password">', 'smtp_password') + '</div>'
                + '<div class="col-12"><label class="small"><input type="checkbox" class="form-check-input me-1" name="tls_verify"' + (b.tls_verify ? ' checked' : '') + '> Sprawdzaj certyfikat serwera (zalecane)</label></div>'
                + '</div></details>'
                + field('Podpis (dodawany do nowych wiadomości i odpowiedzi)', '<textarea class="form-control" name="signature" rows="3" maxlength="2000">' + esc(b.signature || '') + '</textarea>', 'signature')
                + '<div class="d-flex flex-wrap gap-2 align-items-center"><button class="btn btn-primary" data-save><i class="bi bi-check2"></i> Zapisz i sprawdź połączenie</button>'
                + (s.mailbox ? '<button type="button" class="btn btn-outline-danger" data-delete>Odłącz skrzynkę</button>' : '') + '<span class="ml-status" data-msg></span></div>'
                + '<div class="mt-3" data-checks></div></form></div></div>'
                + '<div class="card"><div class="card-body small">' + side(s) + '</div></div></div>';
            var form = app.querySelector('[data-form]');
            var help = function () { var p = s.providers[form.provider.value]; app.querySelector('[data-provider-help]').textContent = p && p.help ? p.help : ''; };
            help();
            form.provider.addEventListener('change', function () {
                var p = s.providers[form.provider.value];
                if (form.provider.value !== 'other') {
                    form.imap_host.value = p.imap[0]; form.imap_port.value = p.imap[1]; form.imap_security.value = p.imap[2];
                    form.smtp_host.value = p.smtp[0]; form.smtp_port.value = p.smtp[1]; form.smtp_security.value = p.smtp[2];
                } else { form.querySelector('details').open = true; }
                help();
            });
            form.email.addEventListener('change', function () {
                var domain = (form.email.value.split('@')[1] || '').toLowerCase();
                if (DOMAINS[domain] && form.provider.value !== DOMAINS[domain]) { form.provider.value = DOMAINS[domain]; form.provider.dispatchEvent(new Event('change')); }
            });
            if (!s.mailbox) form.provider.dispatchEvent(new Event('change'));
            form.addEventListener('submit', function (e) { e.preventDefault(); save(form); });
            var del = app.querySelector('[data-delete]');
            if (del) del.addEventListener('click', function () {
                if (!window.confirm('Odłączyć skrzynkę ? Wiadomości znikną z ERP (w Twojej skrzynce zostają).')) return;
                api('DELETE', '/ajax/mail/settings').then(function () { settings(); });
            });
        }).catch(function (e) { app.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>'; });
    }

    function side(s) {
        var b = s.mailbox;
        var mode = s.delivery.mode !== 'live' ? '<div class="ml-banner mt-2"><i class="bi bi-shield-check"></i> ' + esc(s.delivery.label) + '</div>' : '';
        if (!b) return '<h6>Jak to działa</h6><ol class="ps-3 mb-2"><li>Wybierz dostawcę i wpisz adres oraz hasło.</li><li>ERP sprawdzi odbiór i wysyłkę (nic nie wysyła).</li>'
            + '<li>Poczta jest w menu « Poczta » ; wiadomości od klientów i dostawców są oznaczone.</li></ol><p class="text-muted mb-0">Gmail : hasło aplikacji (Konto Google › Bezpieczeństwo), nie hasło konta.</p>' + mode;
        return '<h6>Skrzynka</h6><div><strong>' + esc(b.email) + '</strong></div>'
            + '<div>Ostatnia synchronizacja : ' + esc(b.last_sync_at ? full(b.last_sync_at) : '—') + '</div>'
            + '<div>Folder wysłanych : ' + esc(b.sent_folder || 'nie znaleziony') + '</div>'
            + (b.last_error ? '<div class="text-danger mt-1">Ostatni błąd : ' + esc(b.last_error) + '</div>' : '')
            + '<a class="btn btn-sm btn-outline-secondary mt-2" href="' + esc(root) + '/mail"><i class="bi bi-envelope"></i> Otwórz pocztę</a>' + mode;
    }

    function save(form) {
        var msg = app.querySelector('[data-msg]');
        var checks = app.querySelector('[data-checks]');
        app.querySelectorAll('[data-err]').forEach(function (el) { el.textContent = ''; });
        checks.innerHTML = '';
        var body = {};
        ['provider', 'email', 'display_name', 'imap_host', 'imap_port', 'imap_security', 'imap_username', 'smtp_host', 'smtp_port', 'smtp_security', 'smtp_username', 'signature']
            .forEach(function (k) { body[k] = form[k].value; });
        body.tls_verify = form.tls_verify.checked;
        body.imap_password = form.password.value;
        body.smtp_password = form.smtp_password.value || form.password.value;
        msg.textContent = 'Zapisywanie…';
        api('PUT', '/ajax/mail/settings', body).then(function (s) {
            state = s;
            form.password.value = ''; form.smtp_password.value = '';
            msg.textContent = 'Zapisano. Sprawdzanie połączenia…';
            return api('POST', '/ajax/mail/settings/test');
        }).then(function (t) {
            var line = function (label, r) { return '<div class="ml-check"><i class="bi ' + (r.ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill') + '"></i><div><strong>' + label + '</strong> — ' + esc(r.message) + '</div></div>'; };
            checks.innerHTML = line('Odbiór (IMAP)', t.imap) + line('Wysyłka (SMTP)', t.smtp);
            msg.textContent = t.imap.ok && t.smtp.ok ? 'Gotowe : skrzynka działa.' : 'Zapisano, ale połączenie wymaga poprawki.';
            return api('GET', '/ajax/mail/state').then(function (s) { state = s; app.querySelector('.ml-settings .card:last-child .card-body').innerHTML = side(s); });
        }).catch(function (e) {
            msg.textContent = e.message;
            Object.keys(e.fields || {}).forEach(function (k) { var el = app.querySelector('[data-err="' + k + '"]'); if (el) el.textContent = e.fields[k]; });
        });
    }

    if (page === 'settings') settings(); else inbox();
})();
