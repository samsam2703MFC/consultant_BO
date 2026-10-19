/*
 * Client des pages du module atelier : jeton d'accès court demandé à la session ERP,
 * appels à l'API /api/v1/production (en-tête X-Access-Token : Authorization peut être pris par
 * un mot de passe navigateur), langue polonaise par défaut avec bascule en français,
 * étiquettes lues dans /resources/lang/ui/{pl,fr}/<fichier>.json.
 */
(function () {
    'use strict';

    const LANG_KEY = 'tfb.lang';
    const TOKEN_KEY = 'tfb.token';
    const NETWORK_ERROR = {
        pl: 'Brak połączenia z serwerem — sprawdź sieć i spróbuj ponownie.',
        fr: 'Pas de connexion au serveur — vérifiez le réseau et réessayez.',
    };
    // Page en cours de fermeture / navigation : les appels interrompus ne sont pas des erreurs à afficher.
    let leaving = false;
    window.addEventListener('pagehide', () => { leaving = true; });
    window.addEventListener('beforeunload', () => { leaving = true; });
    window.addEventListener('pageshow', () => { leaving = false; });
    const wait = (ms) => new Promise(resolve => window.setTimeout(resolve, ms));

    class WorkshopApi {
        constructor(el) {
            this.root = (el.dataset.root || '').replace(/\/$/, '');
            this.base = (el.dataset.apiBase || '').replace(/\/$/, '');
            this.labelsFile = el.dataset.labels || 'workshop';
            this.user = el.dataset.user || '';
            this.token = null;
            this.tokenExpires = 0;
            this.tokenPending = null;
            // Jeton gardé pour l'onglet (sessionStorage) : pas de nouvelle demande à chaque page tant qu'il est
            // valable, et seulement pour la même personne connectée.
            try {
                const saved = JSON.parse(window.sessionStorage.getItem(TOKEN_KEY) || 'null');
                if (saved && saved.user === this.user && this.user !== '' && saved.expires > Date.now() + 30000) {
                    this.token = saved.token;
                    this.tokenExpires = saved.expires;
                }
            } catch (e) { /* stockage indisponible */ }
            this.labels = {};
            let stored = null;
            try { stored = window.localStorage.getItem(LANG_KEY); } catch (e) { /* stockage indisponible */ }
            this.lang = stored === 'fr' ? 'fr' : 'pl';
        }

        async init() {
            await this.loadLabels();
            return this;
        }

        async loadLabels() {
            const res = await fetch(`${this.root}/resources/lang/ui/${this.lang}/${this.labelsFile}.json`, {credentials: 'same-origin'});
            this.labels = res.ok ? await res.json() : {};
            document.documentElement.lang = this.lang;
        }

        async setLang(lang) {
            this.lang = lang === 'fr' ? 'fr' : 'pl';
            try { window.localStorage.setItem(LANG_KEY, this.lang); } catch (e) { /* stockage indisponible */ }
            await this.loadLabels();
        }

        /** Étiquette traduite, avec {paramètres}. */
        t(key, params) {
            let text = this.labels[key] ?? key;
            for (const [k, v] of Object.entries(params || {})) text = text.split('{' + k + '}').join(String(v));
            return text;
        }

        /**
         * fetch avec reprise : une coupure réseau (« Failed to fetch ») sur une lecture est retentée encore
         * une fois après 1,5 s (en plus de la reprise du fetch de la page) ; une écriture n'est jamais
         * rejouée à l'aveugle. L'erreur levée porte un message compréhensible (err.network).
         */
        async send(url, init, safe) {
            const delays = safe ? [1500] : [];
            for (let attempt = 0; ; attempt++) {
                try {
                    return await fetch(url, init);
                } catch (e) {
                    if (e && e.name === 'AbortError') throw e;
                    if (!leaving && attempt < delays.length) { await wait(delays[attempt]); continue; }
                    const err = new Error(NETWORK_ERROR[this.lang] || NETWORK_ERROR.pl);
                    err.network = true;
                    err.leaving = leaving;
                    err.cause = e;
                    throw err;
                }
            }
        }

        async ensureToken(force) {
            if (!force && this.token && Date.now() < this.tokenExpires - 30000) return this.token;
            // Une seule demande à la fois : les appels lancés ensemble attendent le même jeton.
            if (this.tokenPending) return this.tokenPending;
            this.tokenPending = (async () => {
                // Jeton : sans effet de bord, rejouable.
                const res = await this.send(`${this.root}/ajax/workshop/token?lang=${this.lang}`, {
                    method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json'},
                }, true);
                const body = await res.json().catch(() => ({}));
                if (!res.ok || !body.data) {
                    const err = new Error(body.message || this.t('error_session'));
                    err.status = res.status;
                    err.body = body;
                    throw err;
                }
                this.token = body.data.access_token;
                this.tokenExpires = Date.now() + body.data.expires_in * 1000;
                try { window.sessionStorage.setItem(TOKEN_KEY, JSON.stringify({user: this.user, token: this.token, expires: this.tokenExpires})); } catch (e) { /* stockage indisponible */ }
                return this.token;
            })();
            try {
                return await this.tokenPending;
            } finally {
                this.tokenPending = null;
            }
        }

        /** Appel de l'API ; renvoie data (et meta) ou lève une erreur portant le corps de réponse. */
        async request(method, path, body, retried) {
            const token = await this.ensureToken(false);
            const sep = path.includes('?') ? '&' : '?';
            const res = await this.send(`${this.base}${path}${sep}lang=${this.lang}`, {
                method,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Accept-Language': this.lang,
                    'X-Access-Token': token,
                    ...(body !== undefined ? {'Content-Type': 'application/json'} : {}),
                },
                body: body !== undefined ? JSON.stringify(body) : undefined,
            }, method === 'GET');
            const payload = await res.json().catch(() => ({status: 'error', message: this.t('error_generic')}));
            if (res.status === 401 && !retried && ['TOKEN_EXPIRED', 'TOKEN_INVALID'].includes(payload.description)) {
                await this.ensureToken(true);
                return this.request(method, path, body, true);
            }
            if (!res.ok || payload.status !== 'ok') {
                const err = new Error(payload.message || this.t('error_generic'));
                err.status = res.status;
                err.body = payload;
                throw err;
            }
            return payload;
        }

        get(path) { return this.request('GET', path).then(p => p.data); }
        list(path) { return this.request('GET', path); }
        post(path, body) { return this.request('POST', path, body ?? {}).then(p => p.data); }
        patch(path, body) { return this.request('PATCH', path, body).then(p => p.data); }
        put(path, body) { return this.request('PUT', path, body).then(p => p.data); }
        del(path) { return this.request('DELETE', path).then(p => p.data); }
    }

    /** Petits outils d'affichage communs aux pages du module. */
    const ui = {
        esc(v) { return String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c])); },
        notify(ok, message) {
            if (!ok && leaving) return;
            (typeof window.showSnack === 'function') ? window.showSnack(ok, message) : window.alert(message);
        },
        /** Erreurs de champ de l'API posées sous les champs [data-field="chemin"] ; renvoie celles sans champ. */
        fieldErrors(container, err) {
            container.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            container.querySelectorAll('.tfb-field-error').forEach(el => el.remove());
            const orphans = [];
            for (const e of (err.body && err.body.errors) || []) {
                const input = container.querySelector(`[data-field="${CSS.escape(e.field)}"]`);
                if (!input) { orphans.push(e.message); continue; }
                input.classList.add('is-invalid');
                const msg = document.createElement('div');
                msg.className = 'invalid-feedback d-block tfb-field-error';
                msg.textContent = e.message;
                (input.closest('.input-group') || input).insertAdjacentElement('afterend', msg);
            }
            return orphans;
        },
        clearErrors(container) {
            container.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            container.querySelectorAll('.tfb-field-error').forEach(el => el.remove());
        },
        num(v) {
            if (v === '' || v === null || v === undefined) return null;
            const n = Number(String(v).replace(/\s/g, '').replace(',', '.'));
            return Number.isFinite(n) ? n : v;
        },
        fmt(n, digits, lang) {
            if (n === null || n === undefined || n === '') return '—';
            return Number(n).toLocaleString(lang === 'fr' ? 'fr-FR' : 'pl-PL', {maximumFractionDigits: digits ?? 1, minimumFractionDigits: 0});
        },
        /** Durée lisible : 1 h 05 min, 12 min, 45 s. */
        duration(seconds, api) {
            const s = Math.round(Number(seconds) || 0);
            if (s < 60) return `${s} ${api.t('unit.s')}`;
            const h = Math.floor(s / 3600);
            const m = Math.round((s % 3600) / 60);
            return h ? `${h} ${api.t('unit.h')} ${String(m).padStart(2, '0')} ${api.t('unit.min')}` : `${m} ${api.t('unit.min')}`;
        },
    };

    window.TfbWorkshop = {WorkshopApi, ui};
})();
