/* Visites terrain — l'application du consultant, du franchisé et de l'admin.
 *
 * Un module classique (pas d'import) monté dans un div : la page PWA
 * `visites/` sur le téléphone, et les écrans « Application consultant » du
 * cockpit sur grand écran. Il possède son DOM, ses données et sa file
 * hors-ligne :
 *   - une lecture (`/visites/app`) gardée en IndexedDB, rendue hors réseau ;
 *   - chaque écriture porte un `client_id`, est appliquée tout de suite à
 *     l'écran, mise en file, envoyée dès que le réseau répond — rejouable
 *     sans doublon ;
 *   - les photos sont réduites par l'appareil (bord long 1600 px, JPEG 0,7,
 *     donc sans EXIF) avant d'entrer dans la file.
 *
 *   window.CockpitVisites.mount(host, { role, id, shop, apiBase, mobile, racine, vue, notify })
 */
(function () {
  'use strict';

  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const FEU = { vert: '🟢', orange: '🟡', rouge: '🔴' };
  const ST_PLAN = { ouvert: ['Ouvert', 'st-ouv'], attente: ['En attente de validation', 'st-att'], valide: ['Validé', 'st-val'], reprendre: ['À reprendre', 'st-rep'], ferme: ['Fermé', 'st-fer'], escalade: ['Escaladé', 'st-esc'] };
  const ST_VISITE = { planifiee: 'planifiée', confirmee: 'confirmée', en_cours: 'en cours', terminee: 'terminée', annulee: 'annulée' };
  const ASSIGNES = { franchise: 'Franchisé', equipe: 'Équipe', consultant: 'Consultant', admin: 'Admin' };
  const MOTIFS = { reguliere: 'régulière', asap: 'ASAP', due: 'due', revisite: 'revisite' };
  const JOURS = ['dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam'];
  const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  const GENRES_JOUR = [['jour_facade', 'Façade'], ['jour_interieur', 'Intérieur'], ['jour_arriere', 'Arrière']];
  const RUBRIQUES_MSP = ['accueil', 'produits', 'hygiene', 'ambiance'];

  const CSS = `
.vi{font-family:var(--font-ui);color:var(--color-text);font-size:14px;line-height:1.45;position:relative;min-height:100%}
.vi *{box-sizing:border-box}
.vi.mob{padding-bottom:84px}
.vi .sc{padding:0 14px 20px}
.vi.desk .sc{max-width:960px;padding:0}
.vi .card{background:var(--color-surface);border:.5px solid var(--color-border-tertiary);border-radius:14px;padding:12px 14px;margin-bottom:10px}
.vi .card.alerte{border-color:#e8b4bb}
.vi .cap{font:600 10px var(--font-ui);letter-spacing:.07em;text-transform:uppercase;color:var(--color-text-muted);margin:14px 2px 6px;display:flex;align-items:center;gap:6px}
.vi .row{display:flex;align-items:center;gap:8px}
.vi .sp{flex:1}
.vi .feu{width:12px;height:12px;border-radius:50%;flex:0 0 auto;display:inline-block}
.vi .feu.vert{background:#2d7a3e}.vi .feu.orange{background:#E0A526}.vi .feu.rouge{background:#C0182B}
.vi .pill{display:inline-flex;align-items:center;gap:4px;font:700 9.5px var(--font-ui);letter-spacing:.05em;text-transform:uppercase;padding:3px 8px;border-radius:999px;white-space:nowrap;background:var(--color-background-secondary);color:var(--color-text)}
.vi .api{background:#E6F2E9;color:#2d7a3e}.vi .loc{background:#F7E4E6;color:#C0182B}.vi .mix{background:#FBEFE0;color:#B26A00}
.vi .P0{background:#C0182B;color:#fff}.vi .P1{background:#E0A526;color:#3a2a00}.vi .P2{background:var(--color-background-secondary);color:var(--color-text)}
.vi .st-ouv{background:#FBEFE0;color:#B26A00}.vi .st-att{background:#FFF6D6;color:#7a5a00}.vi .st-val{background:#E6F2E9;color:#2d7a3e}.vi .st-rep{background:#EEE6FA;color:#5b3d9e}.vi .st-fer{background:var(--color-background-secondary);color:var(--color-text-muted)}.vi .st-esc{background:#C0182B;color:#fff}
.vi .k{font-family:var(--font-display);font-size:22px;line-height:1}
.vi .mu{color:var(--color-text-muted)}.vi .sm{font-size:11.5px}.vi .xs{font-size:10.5px}
.vi .up{color:#2d7a3e}.vi .dn{color:#C0182B}
.vi .btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border-radius:10px;padding:10px 14px;font:600 13px var(--font-ui);border:.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);cursor:pointer;text-decoration:none}
.vi .btn.p{background:var(--color-primary);color:#fff;border-color:var(--color-primary)}
.vi .btn.w{width:100%}.vi .btn.s{padding:6px 10px;font-size:11.5px;border-radius:8px}
.vi .btn:disabled{opacity:.5;cursor:default}
.vi .btns{display:flex;gap:8px;margin-top:10px}
.vi .bas{position:fixed;left:0;right:0;bottom:0;display:grid;background:var(--color-surface);border-top:.5px solid var(--color-border-tertiary);padding:8px 0 max(14px,env(safe-area-inset-bottom));z-index:5}
.vi .bas button{text-align:center;font:600 10px var(--font-ui);color:var(--color-text-muted);background:none;border:none;cursor:pointer;padding:0}
.vi .bas button i{display:block;font-style:normal;font-size:17px;opacity:.55;margin-bottom:2px}
.vi .bas button.on{color:var(--color-primary)}.vi .bas button.on i{opacity:1}
.vi .onglets{display:flex;gap:6px;margin:0 0 14px;flex-wrap:wrap}
.vi .onglets button{font:600 12px var(--font-ui);padding:7px 12px;border-radius:999px;border:.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);cursor:pointer}
.vi .onglets button.on{background:var(--color-text);color:#fff;border-color:var(--color-text)}
.vi .jour{display:grid;grid-template-columns:54px 1fr;gap:10px;padding:10px 0;border-bottom:.5px solid var(--color-border-tertiary)}
.vi .jour .j{font-size:11px;color:var(--color-text-muted);line-height:1.3}.vi .jour .j b{display:block;font-size:14px;color:var(--color-text)}
.vi .jour.auj .j b{color:var(--color-primary)}
.vi .vis{background:var(--color-surface);border:.5px solid var(--color-border-tertiary);border-radius:12px;padding:9px 11px;margin-bottom:6px}
.vi .vis b{font-size:14px}
.vi .libre{color:var(--color-text-muted);font-size:12px;padding:6px 0}
.vi .kpi{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.vi.desk .kpi{grid-template-columns:repeat(4,1fr)}
.vi .kpi>div{background:var(--color-surface);border:.5px solid var(--color-border-tertiary);border-radius:12px;padding:10px 12px}
.vi .al{display:flex;gap:8px;align-items:flex-start;padding:7px 0;border-bottom:.5px solid var(--color-border-tertiary);font-size:12.5px}
.vi .al:last-child{border:none}
.vi .ph{height:90px;border-radius:10px;background:#d8cfc3 center/cover no-repeat;display:flex;align-items:center;justify-content:center;color:#6b5f52;font-size:12px;position:relative;cursor:pointer;overflow:hidden}
.vi .ph.vide{background:var(--color-background-secondary);border:1.5px dashed var(--color-border-secondary);color:var(--color-primary);font-weight:600}
.vi .ph small{position:absolute;left:6px;bottom:5px;font-size:10px;background:rgba(0,0,0,.5);color:#fff;padding:2px 6px;border-radius:6px}
.vi .ph .att{position:absolute;right:6px;top:5px;font-size:10px;background:#E0A526;color:#3a2a00;padding:2px 6px;border-radius:6px}
.vi .phs{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
.vi .phs.n2{grid-template-columns:1fr 2fr}
.vi .mod{margin-top:14px}
.vi .mod .th{display:flex;align-items:center;gap:8px;margin-bottom:6px}
.vi .mod .th b{font-size:14px}
.vi .pt{background:var(--color-surface);border:.5px solid var(--color-border-tertiary);border-radius:12px;padding:9px 11px;margin-bottom:6px}
.vi .pt.ko{border-color:#e8b4bb}
.vi .pt .l1{display:flex;align-items:center;gap:8px}
.vi .cb{width:22px;height:22px;border:1.5px solid var(--color-border-secondary);border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;flex:0 0 auto;background:var(--color-surface);cursor:pointer;color:var(--color-text-muted)}
.vi .cb.ok{background:#2d7a3e;border-color:#2d7a3e;color:#fff}.vi .cb.ko{background:#C0182B;border-color:#C0182B;color:#fff}.vi .cb.na{background:var(--color-background-secondary);color:var(--color-text-muted)}
.vi .notes{display:flex;gap:3px}
.vi .notes button{width:24px;height:24px;border-radius:6px;background:var(--color-background-secondary);border:none;display:inline-flex;align-items:center;justify-content:center;font:600 11px var(--font-ui);color:var(--color-text-muted);cursor:pointer;padding:0}
.vi .notes button.on{background:var(--color-text);color:#fff}.vi .notes button.bad{background:#C0182B;color:#fff}
.vi .act{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap;align-items:center}
.vi .chip{font:600 11px var(--font-ui);padding:5px 9px;border-radius:999px;border:.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);cursor:pointer}
.vi .chip.on{background:var(--color-text);color:#fff;border-color:var(--color-text)}
.vi .chip.ko{border-color:#C0182B;color:#C0182B}
.vi .bar{height:6px;border-radius:999px;background:var(--color-background-secondary);overflow:hidden;margin-top:4px}
.vi .bar i{display:block;height:100%;background:var(--color-primary)}
.vi .spark{display:flex;align-items:flex-end;gap:4px;height:44px;margin-top:6px}
.vi .spark i{flex:1;background:#d8cfc3;border-radius:3px 3px 0 0;min-height:2px}
.vi .spark i.o{background:var(--color-primary)}.vi .spark i.f{background:#e9e2d8}
.vi .tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.vi .tbl th{text-align:left;font:600 10px var(--font-ui);letter-spacing:.06em;text-transform:uppercase;color:var(--color-text-muted);padding:8px 8px;border-bottom:.5px solid var(--color-border-tertiary)}
.vi .tbl td{padding:9px 8px;border-bottom:.5px solid var(--color-border-tertiary);vertical-align:top}
.vi .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.vi.mob .grid2{grid-template-columns:1fr}
.vi .plan{border-left:3px solid var(--color-border-secondary);padding-left:10px;margin:8px 0}
.vi .plan.P0b{border-color:#C0182B}.vi .plan.P1b{border-color:#E0A526}.vi .plan.P2b{border-color:#9a8c6a}
.vi .stat{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.vi .stat>div{background:var(--color-surface);border:.5px solid var(--color-border-tertiary);border-radius:12px;padding:10px 12px;text-align:center}
.vi input[type=text],.vi input[type=date],.vi input[type=time],.vi input[type=number],.vi input[type=email],.vi select,.vi textarea{font-family:var(--font-ui);font-size:13px;padding:8px 10px;border-radius:9px;border:.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text);width:100%}
.vi textarea{min-height:64px;resize:vertical}
.vi .champ{margin-top:8px}.vi .champ label{display:block;font-size:11px;color:var(--color-text-muted);margin-bottom:3px}
.vi .l2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.vi .toast{position:fixed;left:14px;right:14px;bottom:86px;background:var(--color-text);color:#fff;border-radius:12px;padding:10px 12px;font-size:12px;z-index:6}
.vi.desk .toast{position:absolute;bottom:auto;top:8px}
.vi .etat{display:flex;align-items:center;gap:8px;font-size:11px;color:var(--color-text-muted);margin:4px 2px 8px;flex-wrap:wrap}
.vi .etat .pt-{width:8px;height:8px;border-radius:50%;background:#2d7a3e;display:inline-block}
.vi .etat .off .pt-{background:#C0182B}
.vi .voir{position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:20;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:14px}
.vi .voir img{max-width:100%;max-height:80vh;border-radius:10px}
.vi .voir .txt{color:#fff;font-size:12px;margin-top:10px;text-align:center}
.vi .hd{display:flex;align-items:center;gap:8px;margin:6px 0 8px}
.vi .hd h2{font-family:var(--font-display);font-size:19px;line-height:1.15;margin:0;font-weight:400}
.vi .hd .d{font-size:11px;color:var(--color-text-muted)}
.vi .retour{border:none;background:none;font-size:20px;cursor:pointer;padding:0 6px 0 0;color:var(--color-text)}
.vi .rub{display:grid;grid-template-columns:repeat(4,1fr);gap:6px}
`;

  /* --- IndexedDB : une réserve de lectures, une file d'écritures --------- */
  const Idb = {
    db: null,
    ouvrir() {
      if (this.db) { return Promise.resolve(this.db); }
      return new Promise((ok, ko) => {
        if (!window.indexedDB) { return ko(new Error('pas d’IndexedDB')); }
        const r = indexedDB.open('cockpit-visites', 1);
        r.onupgradeneeded = () => { const d = r.result; d.createObjectStore('cache'); d.createObjectStore('file', { keyPath: 'id' }); };
        r.onsuccess = () => { this.db = r.result; ok(this.db); };
        r.onerror = () => ko(r.error);
      });
    },
    async op(store, mode, fn) {
      try {
        const db = await this.ouvrir();
        return await new Promise((ok, ko) => {
          const t = db.transaction(store, mode); const s = t.objectStore(store); const q = fn(s);
          q.onsuccess = () => ok(q.result); q.onerror = () => ko(q.error);
        });
      } catch (e) { return null; }
    },
    get(store, cle) { return this.op(store, 'readonly', s => s.get(cle)); },
    put(store, val, cle) { return this.op(store, 'readwrite', s => cle !== undefined ? s.put(val, cle) : s.put(val)); },
    del(store, cle) { return this.op(store, 'readwrite', s => s.delete(cle)); },
    tous(store) { return this.op(store, 'readonly', s => s.getAll()).then(r => r || []); },
    vider(store) { return this.op(store, 'readwrite', s => s.clear()); },
  };

  const uuid = () => (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'x' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
  const auj = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
  const plusJours = (iso, n) => { const d = new Date(iso + 'T12:00:00'); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); };
  const maintenant = () => { const d = new Date(); return auj() + ' ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); };
  const fmtD = iso => { if (!iso) { return '—'; } const d = new Date(String(iso).slice(0, 10) + 'T12:00:00'); return d.getDate() + ' ' + MOIS[d.getMonth()]; };
  const fmtDJ = iso => { if (!iso) { return '—'; } const d = new Date(String(iso).slice(0, 10) + 'T12:00:00'); return JOURS[d.getDay()] + ' ' + d.getDate() + ' ' + MOIS[d.getMonth()]; };
  const fmtH = dt => dt ? String(dt).slice(11, 16) : '';
  const eur = n => n == null ? '—' : Math.round(n).toLocaleString('fr-BE').replace(/ | /g, ' ') + ' €';
  const pct = n => n == null ? '' : (n > 0 ? '+' : n < 0 ? '−' : '') + Math.abs(n) + ' %';
  const note1 = n => n == null ? '—' : String(Number(n).toFixed(1)).replace('.', ',');
  const jours = (a, b) => Math.round((new Date(b + 'T12:00:00') - new Date(a + 'T12:00:00')) / 86400000);
  const lundiDe = (iso, dec) => { const d = new Date(iso + 'T12:00:00'); const j = (d.getDay() + 6) % 7; d.setDate(d.getDate() - j + 7 * (dec || 0)); return d.toISOString().slice(0, 10); };

  /* --- photos : réduire sur l'appareil, ré-encoder (donc sans EXIF) ------- */
  function compresser(fichier) {
    return new Promise((ok, ko) => {
      const url = URL.createObjectURL(fichier);
      const im = new Image();
      im.onload = () => {
        URL.revokeObjectURL(url);
        const max = 1600; let w = im.naturalWidth, h = im.naturalHeight;
        const r = Math.min(1, max / Math.max(w, h)); w = Math.round(w * r); h = Math.round(h * r);
        const c = document.createElement('canvas'); c.width = w; c.height = h;
        c.getContext('2d').drawImage(im, 0, 0, w, h);
        let q = 0.7; let data = c.toDataURL('image/jpeg', q);
        while (data.length > 2 * 1024 * 1024 * 1.37 && q > 0.4) { q -= 0.1; data = c.toDataURL('image/jpeg', q); }
        ok({ data, largeur: w, hauteur: h });
      };
      im.onerror = () => { URL.revokeObjectURL(url); ko(new Error('image illisible')); };
      im.src = url;
    });
  }
  function position() {
    return new Promise(ok => {
      if (!navigator.geolocation) { return ok(null); }
      navigator.geolocation.getCurrentPosition(p => ok({ lat: p.coords.latitude, lng: p.coords.longitude }), () => ok(null), { timeout: 4000, maximumAge: 600000 });
    });
  }

  /* --- l'hôte ---------------------------------------------------------------- */
  class Hote {
    constructor(host, o) {
      this.host = host; this.o = o;
      this.role = o.role || 'consultant';
      this.shop = o.shop ? String(o.shop) : null;
      this.moi = o.id || (this.role === 'consultant' ? (localStorage.getItem('vi.moi') || '') : '');
      this.D = null; this.B = {}; this.C = {}; this.S = null; this.R = null;
      this.v = o.vue || (this.role === 'franchise' ? 'plans' : this.role === 'admin' ? 'admin' : 'agenda');
      this.p = null; this.sem = 0; this.form = {}; this.file = []; this.enLigne = navigator.onLine; this.sync = null; this.busy = false;
      this.toast = null; this.voir = null; this.ouvert = {};
      if (!document.getElementById('vi-css')) { const st = document.createElement('style'); st.id = 'vi-css'; st.textContent = CSS; document.head.appendChild(st); }
      host.innerHTML = '<div class="vi ' + (o.mobile ? 'mob' : 'desk') + '"><div class="sc"><div class="mu sm" style="padding:20px 0">Lecture…</div></div></div>';
      host.addEventListener('click', e => this.clic(e));
      host.addEventListener('input', e => this.saisie(e));
      host.addEventListener('change', e => this.change(e));
      window.addEventListener('online', () => { this.enLigne = true; this.rejouer(); this.rendre(); });
      window.addEventListener('offline', () => { this.enLigne = false; this.rendre(); });
      if (o.mobile) { window.addEventListener('hashchange', () => this.deHash()); this.deHash(true); }
      this.demarrer();
    }

    /* --- réseau et file --------------------------------------------------- */
    api(path) { return this.o.apiBase.replace(/\/$/, '') + path; }
    async lire(path, cle) {
      cle = cle || path;
      try {
        const r = await fetch(this.api(path), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!r.ok) { throw new Error('http ' + r.status); }
        const j = await r.json();
        this.enLigne = true;
        await Idb.put('cache', { quand: maintenant(), val: j }, cle);
        this.sync = maintenant();
        return j;
      } catch (e) {
        const c = await Idb.get('cache', cle);
        if (c) { this.sync = c.quand; return c.val; }
        throw e;
      }
    }
    async demarrer() {
      this.file = await Idb.tous('file');
      try {
        await this.charger();
      } catch (e) { this.host.innerHTML = '<div class="vi"><div class="sc"><div class="card">Le serveur ne répond pas et rien n’est encore en réserve sur cet appareil. Réessayez avec du réseau.</div></div></div>'; return; }
      this.rendre();
      this.suivreConformite(this.v, this.p);
      if (this.file.length) { this.rejouer(); }
    }
    async charger() {
      const q = this.role === 'franchise' ? '?shop=' + encodeURIComponent(this.shop) : '?role=' + this.role + (this.moi ? '&id=' + encodeURIComponent(this.moi) : '');
      this.D = await this.lire('/visites/app' + q, 'app:' + this.role + ':' + (this.shop || ''));
      this.appliquerFile();
    }
    async recharger() { try { await this.charger(); } catch (e) { /* on garde ce qu'on a */ } this.rendre(); }
    /** Une écriture : appliquée à l'écran, mise en file, envoyée. */
    async ecrire(op) {
      op.id = op.id || uuid(); op.quand = maintenant();
      this.file.push(op);
      await Idb.put('file', op);
      this.rendre();
      return this.rejouer();
    }
    async rejouer() {
      if (this.busy || !this.file.length) { return; }
      this.busy = true;
      try {
        while (this.file.length) {
          const op = this.file[0];
          let r;
          try {
            r = await fetch(this.api(op.path), { method: op.method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(op.body || {}) });
          } catch (e) { this.enLigne = false; break; }
          this.enLigne = true;
          let j = null; try { j = await r.json(); } catch (e) { j = {}; }
          if (!r.ok && r.status >= 500) { break; }
          this.file.shift(); await Idb.del('file', op.id);
          if (!r.ok) { this.dire('Refusé par le serveur : ' + ((j && j.error) || r.status)); }
          else if (op.apres) { this.apres(op, j); }
        }
      } finally { this.busy = false; }
      if (!this.file.length) { await this.recharger(); } else { this.rendre(); }
    }
    /** Ce que le serveur rend après une écriture vient remplacer la version locale. */
    apres(op, j) {
      const D = this.D; if (!D) { return; }
      if (op.apres === 'visite' && j.visite) { const i = D.visites.findIndex(v => v.client_id === j.visite.client_id || v.id === j.visite.id); if (i >= 0) { D.visites[i] = j.visite; } else { D.visites.push(j.visite); } if (this.v === 'fiche' && String(this.p) === String(op.body.client_id)) { this.p = j.visite.id; } }
      if (op.apres === 'plans' && j.plans) { j.plans.forEach(p => { const i = D.plans.findIndex(x => x.client_id === p.client_id || x.id === p.id); if (i >= 0) { D.plans[i] = p; } else { D.plans.push(p); } }); }
      if (op.apres === 'plan' && j.plan) { const i = D.plans.findIndex(x => x.id === j.plan.id || (x.client_id && x.client_id === j.plan.client_id)); if (i >= 0) { D.plans[i] = j.plan; } }
      if (op.apres === 'photo' && j.photo) { const i = D.photos.findIndex(x => x.client_id === j.photo.client_id); if (i >= 0) { D.photos[i] = j.photo; } else { D.photos.unshift(j.photo); } }
    }
    /** Les écritures en file, rejouées sur la lecture fraîche : l'écran ne recule pas. */
    appliquerFile() {
      const D = this.D;
      this.file.forEach(op => {
        const b = op.body || {};
        if (op.apres === 'visite' && op.method === 'POST' && !D.visites.some(v => v.client_id === b.client_id)) {
          D.visites.push({ id: b.client_id, client_id: b.client_id, shop: b.shop, consultant: b.consultant, consultantNom: b.consultant_nom, prevu_le: b.prevu_le, debut_h: b.debut_h, duree_min: b.duree_min, motif: b.motif, statut: 'planifiee', attente: true });
        }
        if (op.apres === 'visite' && op.method === 'PUT') { const v = this.visite(op.path.split('/')[2]); if (v) { Object.assign(v, b, { attente: true }); } }
        if (op.apres === 'points') { (b.points || []).forEach(p => { const i = D.points.findIndex(x => String(x.visite_id) === String(op.path.split('/')[2]) && x.ref === p.ref); const l = Object.assign({ visite_id: op.path.split('/')[2] }, p); if (i >= 0) { D.points[i] = Object.assign(D.points[i], l); } else { D.points.push(l); } }); }
        if (op.apres === 'photo' && !D.photos.some(x => x.client_id === b.client_id)) { D.photos.unshift({ client_id: b.client_id, visite_id: b.visite_id || null, shop: b.shop, plan_id: b.plan_id || null, ref: b.ref || null, genre: b.genre, data: b.data, prise_a: b.prise_a, attente: true }); }
        if (op.apres === 'plans') { (b.plans || []).forEach(p => { if (!D.plans.some(x => x.client_id === p.client_id)) { D.plans.push(Object.assign({ id: p.client_id, statut: 'ouvert', cree_le: op.quand, maj_le: op.quand, retard: 0, age: 0, attente: true }, p)); } }); }
        if (op.apres === 'plan') { const p = this.plan(op.path.split('/')[2]); if (p) { Object.assign(p, b, { attente: true }); } }
      });
    }

    /* --- accès aux données ----------------------------------------------- */
    boutique(id) { return (this.D.boutiques || []).find(b => String(b.id) === String(id)) || null; }
    visite(id) { return (this.D.visites || []).find(v => String(v.id) === String(id) || (v.client_id && v.client_id === id)) || null; }
    plan(id) { return (this.D.plans || []).find(p => String(p.id) === String(id) || (p.client_id && p.client_id === id)) || null; }
    plansDe(shop, ouverts) { return (this.D.plans || []).filter(p => String(p.shop) === String(shop) && (!ouverts || /^(ouvert|reprendre|escalade)$/.test(p.statut))); }
    pointsDe(vid) { const m = {}; (this.D.points || []).filter(p => String(p.visite_id) === String(vid)).forEach(p => { m[p.ref] = p; }); return m; }
    photosDe(vid) { return (this.D.photos || []).filter(p => String(p.visite_id) === String(vid)); }
    photosJour(shop) { return (this.D.photos || []).filter(p => String(p.shop) === String(shop) && /^jour_/.test(p.genre)); }
    photoSrc(p) { return p.data ? p.data : (this.o.racine || '') + p.chemin; }
    consultantNom(id) { const c = (this.D.consultants || []).find(x => x.id === id); return c ? c.nom : (id || ''); }
    mesVisites() { return (this.D.visites || []).filter(v => this.role !== 'consultant' || !this.moi || v.consultant === this.moi || !v.consultant); }
    qui() { return this.role === 'consultant' ? (this.consultantNom(this.moi) || 'Consultant') : this.role === 'admin' ? 'Admin' : ('Franchisé ' + ((this.boutique(this.shop) || {}).court || '')); }
    ecartsDe(v) {
      const pts = this.pointsDe(v.id); const out = [];
      const s = this.D.seuils || {};
      (this.D.checklist || []).forEach(m => m.points.forEach(pt => {
        const p = pts[pt.ref]; if (!p) { return; }
        if (pt.pct && p.valeur != null && p.valeur < (s.planoOrange || 80)) { out.push({ ref: pt.ref, module: m.id, titre: pt.libelle, detail: p.valeur + ' % de conformité' + (p.causes && p.causes.length ? ' · ' + p.causes.map(c => (this.D.causes || {})[c] || c).join(', ') : ''), grave: p.valeur < (s.planoRouge || 60) }); }
        else if (p.etat === 'ko' || (p.note != null && p.note <= 2)) { out.push({ ref: pt.ref, module: m.id, titre: pt.libelle, detail: (p.note != null ? p.note + '/5' : 'non conforme') + (p.commentaire ? ' · ' + p.commentaire : ''), grave: m.id === 'hygiene' || (p.note != null && p.note <= 1) }); }
      }));
      return out;
    }

    /* --- navigation --------------------------------------------------------- */
    go(v, p) {
      this.v = v; this.p = p == null ? null : p; this.form = {};
      if (this.o.mobile) { const h = '#' + v + (p != null ? '/' + p : ''); if (location.hash !== h) { history.pushState(null, '', h); } }
      window.scrollTo(0, 0);
      this.rendre();
      if (v === 'tb' || v === 'historique') { this.chargerBoutique(p); }
      this.suivreConformite(v, p);
    }
    /** La conformité suit l'écran : le tableau de bord et la checklist la lisent. */
    suivreConformite(v, p) {
      if (v === 'tb' || v === 'historique') { this.chargerConformite(p); return; }
      if (v === 'checklist' || v === 'review' || v === 'fiche') {
        const vi = this.visite(p);
        if (vi) { this.chargerConformite(vi.shop); }
      }
    }
    deHash(init) {
      const m = /^#([a-z]+)(?:\/([^/]+))?/.exec(location.hash || '');
      if (!m) { if (!init) { this.rendre(); } return; }
      this.v = m[1]; this.p = m[2] != null ? decodeURIComponent(m[2]) : null; this.form = {};
      if (!init) { this.rendre(); if (this.v === 'tb' || this.v === 'historique') { this.chargerBoutique(this.p); } this.suivreConformite(this.v, this.p); }
    }
    async chargerBoutique(shop) {
      if (!shop) { return; }
      if (this.B[shop] && this.B[shop].lu === this.sync) { return; }
      try { this.B[shop] = await this.lire('/visites/boutique/' + encodeURIComponent(shop), 'boutique:' + shop); } catch (e) { return; }
      this.rendre();
    }
    /**
     * Ce que le cockpit sait du comptoir : planogramme dessiné et assortiment
     * obligatoire. Lu une fois par boutique et par session — ni le comptoir ni
     * la liste des obligatoires ne bougent pendant une visite.
     */
    async chargerConformite(shop) {
      if (!shop || this.C[shop]) { return; }
      try { this.C[shop] = await this.lire('/visites/conformite?shop=' + encodeURIComponent(shop), 'conformite:' + shop); } catch (e) { return; }
      this.rendre();
    }
    dire(msg) { this.toast = msg; this.rendre(); clearTimeout(this._tt); this._tt = setTimeout(() => { this.toast = null; this.rendre(); }, 3200); }

    /* --- rendu -------------------------------------------------------------- */
    rendre() {
      if (!this.D) { return; }
      // Le cockpit fusionne son DOM à chaque rendu et peut vider le div hôte : on le repeuple.
      let vi = this.host.querySelector('.vi');
      if (!vi) { this.host.innerHTML = '<div class="vi ' + (this.o.mobile ? 'mob' : 'desk') + '"></div>'; vi = this.host.querySelector('.vi'); }
      const actif = document.activeElement; const focus = actif && this.host.contains(actif) && actif.dataset && actif.dataset.f ? { f: actif.dataset.f, pos: actif.selectionStart } : null;
      const V = this['v_' + this.v] ? this['v_' + this.v]() : this.v_agenda();
      const onglets = this.onglets();
      vi.innerHTML = (this.o.mobile ? '' : `<div class="onglets">${onglets.map(t => `<button data-a="go" data-v="${t[0]}" class="${t[0] === this.v || (t[3] || []).includes(this.v) ? 'on' : ''}">${t[2]}</button>`).join('')}</div>`)
        + `<div class="sc">${this.etat()}${V}</div>`
        + (this.o.mobile ? `<div class="bas" style="grid-template-columns:repeat(${onglets.length},1fr)">${onglets.map(t => `<button data-a="go" data-v="${t[0]}" class="${t[0] === this.v || (t[3] || []).includes(this.v) ? 'on' : ''}"><i>${t[1]}</i>${t[2]}</button>`).join('')}</div>` : '')
        + (this.toast ? `<div class="toast">${esc(this.toast)}</div>` : '')
        + (this.voir ? `<div class="voir" data-a="fermer-voir"><img src="${esc(this.voir.src)}" alt=""><div class="txt">${esc(this.voir.txt)}</div></div>` : '');
      if (focus) { const el = this.host.querySelector(`[data-f="${focus.f.replace(/"/g, '\\"')}"]`); if (el) { el.focus(); try { if (focus.pos != null && el.setSelectionRange) { el.setSelectionRange(focus.pos, focus.pos); } } catch (e) { /* select */ } } }
    }
    onglets() {
      if (this.role === 'franchise') { return [['plans', '✅', 'Plan d’action', ['photo']], ['tb', '📊', 'Ma boutique', ['historique']], ['reglages', '⚙️', 'Réglages']]; }
      if (this.role === 'admin') { return [['admin', '✅', 'À valider'], ['portfolio', '🏪', 'Boutiques', ['tb', 'historique', 'fiche', 'planifier', 'checklist', 'review', 'msp']], ['agenda', '📅', 'Agenda'], ['synthese', '📋', 'Synthèse'], ['reglages', '⚙️', 'Réglages']]; }
      return [['agenda', '📅', 'Agenda', ['fiche', 'planifier', 'checklist', 'review']], ['portfolio', '🏪', 'Boutiques', ['tb', 'historique', 'msp']], ['plans', '✅', 'Plans', ['admin']], ['reglages', '⚙️', 'Réglages']];
    }
    etat() {
      const n = this.file.length;
      return `<div class="etat ${this.enLigne ? '' : 'off'}"><span class="pt-"></span><span>${this.enLigne ? 'en ligne' : 'hors ligne'}${this.sync ? ' · sync ' + esc(this.sync.slice(11)) : ''}</span>${n ? `<button class="chip" data-a="sync">📤 ${n} en attente</button>` : ''}<span class="sp"></span><button class="chip" data-a="recharger">↻</button></div>`;
    }
    hd(titre, sous, retour, droite) {
      return `<div class="hd">${retour ? `<button class="retour" data-a="go" data-v="${retour}">‹</button>` : ''}<div><h2>${esc(titre)}</h2>${sous ? `<div class="d">${sous}</div>` : ''}</div><span class="sp"></span>${droite || ''}</div>`;
    }
    feuDot(b) { return `<span class="feu ${b.feu}"></span>`; }
    /**
     * Le comptoir d'après le cockpit — ce que le consultant n'a pas à compter.
     *
     * Deux constats lus, jamais saisis : le planogramme (emplacements dessinés
     * et tenus, comptoirs photographiés montés ce jour, obligatoires sans place)
     * et l'assortiment obligatoire (celles qui n'ont pas passé un ticket sur la
     * fenêtre). Tant que la lecture n'est pas revenue, la carte le dit ; quand
     * une source manque, elle rend son motif plutôt qu'un chiffre inventé.
     */
    carteConformite(shop) {
      const C = this.C[shop];
      if (!C) { return '<div class="cap">Le comptoir d’après le cockpit ' + this.src('mix') + '</div><div class="card sm mu">Lecture du planogramme et de l’assortiment…</div>'; }
      const pl = C.planogramme || {}; const as = C.assortiment || {};
      const cle = 'cf:' + shop;
      const ouvert = !!this.ouvert[cle];
      const manq = (as.liste || []);
      const sansPlace = (pl.sansPlace || []);
      const feuPl = pl.pct == null ? '' : pl.pct < (this.D.seuils.planoRouge || 60) ? 'rouge' : pl.pct < (this.D.seuils.planoOrange || 80) ? 'orange' : 'vert';
      const feuAs = as.pct == null ? '' : as.pct < 90 ? (as.pct < 75 ? 'rouge' : 'orange') : 'vert';
      return '<div class="cap">Le comptoir d’après le cockpit ' + this.src('mix') + '</div>'
        + `<div class="card sm">
          <div class="row">${feuPl ? `<span class="feu ${feuPl}"></span>` : ''}<b>Planogramme</b><span class="sp"></span><b>${pl.pct != null ? pl.pct + ' %' : '—'}</b></div>
          <div class="mu">${pl.motif ? esc(pl.motif) : `${pl.tenus} emplacement${pl.tenus > 1 ? 's' : ''} tenu${pl.tenus > 1 ? 's' : ''} sur ${pl.emplacements} · ${pl.zones} comptoir${pl.zones > 1 ? 's' : ''} dessiné${pl.zones > 1 ? 's' : ''} · ${pl.comptoirsMontes ? pl.comptoirsMontes + ' photographié' + (pl.comptoirsMontes > 1 ? 's' : '') + ' monté' + (pl.comptoirsMontes > 1 ? 's' : '') + ' aujourd’hui' : 'aucune photo de montage aujourd’hui'}`}</div>
          ${sansPlace.length ? `<div class="xs" style="margin-top:4px">⚠ ${pl.obligatoiresSansPlace} obligatoire${pl.obligatoiresSansPlace > 1 ? 's' : ''} sans place au comptoir : <span class="mu">${sansPlace.slice(0, 6).map(x => esc(x.nom)).join(' · ')}${sansPlace.length > 6 ? ' …' : ''}</span></div>` : ''}
          <div class="row" style="margin-top:10px">${feuAs ? `<span class="feu ${feuAs}"></span>` : ''}<b>Assortiment obligatoire</b><span class="sp"></span><b>${as.pct != null ? as.pct + ' %' : '—'}</b></div>
          <div class="mu">${as.motif ? esc(as.motif) : `${as.presentes} des ${as.lisibles} obligatoires vues en caisse sur les ${as.jours} j jusqu’au ${fmtD(as.au)}${as.sansIdentifiant ? ' · ' + as.sansIdentifiant + ' sans identifiant de caisse' : ''}`}</div>
          ${manq.length ? `<div class="act" style="margin-top:6px"><button class="chip ${ouvert ? 'on' : ''}" data-a="drop" data-v="${esc(cle)}">${as.manquantes} manquante${as.manquantes > 1 ? 's' : ''} ${ouvert ? '▴' : '▾'}</button></div>` : as.motif ? '' : '<div class="xs up" style="margin-top:4px">Toutes les obligatoires sont passées en caisse.</div>'}
          ${ouvert && manq.length ? `<div class="sm" style="margin-top:6px">${manq.map(x => `<div class="row" style="padding:2px 0"><span>${esc(x.nom)}</span><span class="sp"></span><span class="xs mu">${x.auComptoir ? 'a sa place au comptoir' : 'pas de place au comptoir'}</span></div>`).join('')}${as.manquantes > manq.length ? `<div class="xs mu">… et ${as.manquantes - manq.length} autre(s)</div>` : ''}</div>` : ''}
          <div class="xs mu" style="margin-top:6px">Planogramme : comptoir dessiné dans le cockpit. Assortiment : références obligatoires × lignes de ticket du magasin, ${fmtD(as.du)} – ${fmtD(as.au)}.${as.retard > 2 ? ' La caisse s’arrête au ' + fmtD(as.derniereVente) + ' (' + as.retard + ' j de retard) : la fenêtre se cale dessus.' : ''}</div></div>`;
    }
    /**
     * La conclusion de visite en trois temps — ce qui justifie d'être venu.
     *
     * D'abord voir la réalité : sur place, le consultant voit comment ça tourne
     * vraiment — l'énergie de l'équipe, l'exécution contre le process, l'écart
     * entre le protocole et ce qu'on fait. Ensuite diagnostiquer le vrai
     * problème : production mal synchronisée, équipe démotivée, décor, prix —
     * pas la même analyse depuis le bureau. Enfin recommander avec crédit :
     * quand il a vu, mesuré, touché, il peut dire « voilà ce qui ne marche pas
     * et voilà pourquoi », et l'équipe l'écoute.
     *
     * Les trois tiennent dans `notes`, sous leurs titres : rien à changer au
     * serveur, l'historique et la synthèse les lisent tels quels.
     */
    static get TEMPS() {
      return [
        ['vu', 'Vu sur place', 'La réalité, pas le papier : le client sort-il satisfait ? l’équipe applique-t-elle les standards ou prend-elle des raccourcis ? l’énergie, l’exécution face au process…'],
        ['probleme', 'Le vrai problème', 'Si le chiffre ou la qualité baissent, quel est le vrai coupable ? Production mal synchronisée, équipe démotivée, décor qui n’invite pas, prix mal positionnés…'],
        ['reco', 'Ma recommandation', '« Voilà ce qui ne marche pas, et voilà pourquoi. » Vu, mesuré, touché — des données, pas une opinion.']];
    }
    /** Le texte des notes découpé en trois temps ; ce qui ne porte pas de titre reste en « libre ». */
    conclusionDe(notes) {
      const out = { vu: '', probleme: '', reco: '', libre: '' };
      const T = this.constructor.TEMPS;
      let cle = 'libre';
      String(notes || '').split('\n').forEach(l => {
        const t = T.find(x => l.startsWith(x[1] + ' —'));
        if (t) { cle = t[0]; out[cle] = l.slice(t[1].length + 2).trim(); return; }
        out[cle] = (out[cle] ? out[cle] + '\n' : '') + l;
      });
      Object.keys(out).forEach(k => { out[k] = out[k].trim(); });
      return out;
    }
    /** Les trois temps remis en un seul texte, titres compris. */
    notesDe(f) {
      const parts = this.constructor.TEMPS.filter(t => (f[t[0]] || '').trim()).map(t => t[1] + ' — ' + f[t[0]].trim());
      if ((f.notes || '').trim()) { parts.push(f.notes.trim()); }
      return parts.join('\n\n');
    }
    src(s) { return s === 'api' ? '<span class="pill api">API</span>' : s === 'mix' ? '<span class="pill mix">API + local</span>' : '<span class="pill loc">Local</span>'; }
    kpis(b, court) {
      const ca = b.ca, g = b.google, eq = b.equipe, msp = b.msp;
      const ouverts = this.plansDe(b.id, true); const p0 = ouverts.filter(p => p.priorite === 'P0');
      const retard = Math.max(0, ...ouverts.map(p => p.retard || 0));
      return `<div class="kpi">
        <div><div class="row xs mu">CA semaine ${ca ? this.src('api') : ''}</div><div class="k" style="margin-top:4px">${ca ? eur(ca.ca) : '—'}</div><div class="sm ${ca && ca.pct < 0 ? 'dn' : 'up'}">${ca ? (ca.pct != null ? pct(ca.pct) + ' vs ' + eur(ca.objectif) + ' objectif' : 'pas d’objectif') : 'ERP absent'}</div>${ca && ca.objectif ? `<div class="bar"><i style="width:${Math.max(2, Math.min(100, Math.round(ca.ca / ca.objectif * 100)))}%"></i></div>` : ''}</div>
        <div><div class="row xs mu">Clients ${msp ? this.src('mix') : this.src('api')}</div><div class="sm" style="margin-top:4px"><span class="pill api">Google</span> <b>${g ? note1(g.note) : '—'}</b>${g && g.avis != null ? ' · ' + g.avis + ' avis' : ''}</div><div class="sm" style="margin-top:3px"><span class="pill loc">MSP</span> ${msp && msp.total != null ? '<b>' + note1(msp.total) + '/20</b> · ' + esc(msp.mois) : '<span class="mu">pas de rapport</span>'}</div></div>
        <div><div class="row xs mu">Actions ouvertes ${this.src('local')}</div><div class="k" style="margin-top:4px;${p0.length ? 'color:#C0182B' : ''}">${ouverts.length}</div><div class="sm mu">${p0.length ? p0.length + ' P0' : 'aucun P0'}${retard ? ' · retard ' + retard + ' j' : ''}${court ? '' : ''}</div></div>
        <div><div class="row xs mu">Équipe ${this.src('local')}</div><div class="k" style="margin-top:4px">${eq && eq.effectif != null ? eq.effectif + (eq.prevu != null ? ' / ' + eq.prevu : '') : '—'}</div><div class="sm mu">${eq ? (eq.departs ? eq.departs + ' départ(s) · ' : '') + 'relevé ' + fmtD(eq.releve_le) : 'à relever'}</div></div>
      </div>`;
    }
    planLigne(p, actions) {
      const st = ST_PLAN[p.statut] || [p.statut, ''];
      const photo = p.photo_id ? (this.D.photos || []).find(x => x.id === p.photo_id) : (this.D.photos || []).find(x => String(x.plan_id) === String(p.id) || (x.client_id && x.client_id === p.photo_client_id));
      return `<div class="card ${p.priorite === 'P0' && /^(ouvert|reprendre|escalade)$/.test(p.statut) ? 'alerte' : ''}">
        <div class="row"><span class="pill ${st[1]}">${st[0]}</span><span class="pill ${p.priorite}">${p.priorite}</span><span class="sp"></span><span class="xs ${p.retard ? 'dn' : 'mu'}">${p.retard ? 'retard ' + p.retard + ' j' : p.echeance ? 'délai ' + fmtD(p.echeance) : ''}${p.attente ? ' · à envoyer' : ''}</span></div>
        <b style="display:block;margin-top:6px">${esc(p.titre)}</b>
        <div class="sm mu">${esc(ASSIGNES[p.assigne] || p.assigne)} · ${esc(p.cree_par || '')} le ${fmtD(p.cree_le)}${p.detail ? ' · « ' + esc(p.detail) + ' »' : ''}</div>
        ${p.retour ? `<div class="sm" style="margin-top:4px">Retour : « ${esc(p.retour)} »</div>` : ''}
        ${p.escalade_motif ? `<div class="sm dn" style="margin-top:4px">Escalade : ${esc(p.escalade_motif)}</div>` : ''}
        ${photo ? `<div class="act"><button class="chip" data-a="voir" data-v="${esc(photo.client_id || photo.id)}">📷 Photo du ${fmtD(photo.prise_a)} ${fmtH(photo.prise_a)}</button></div>` : ''}
        ${actions || ''}
      </div>`;
    }

    /* --- écrans ---------------------------------------------------------------- */
    v_agenda() {
      const D = this.D; const t = auj();
      const lundi = lundiDe(t, this.sem);
      const vs = this.mesVisites().filter(v => v.statut !== 'annulee');
      const jrs = [];
      for (let i = 0; i < 7; i++) {
        const d = plusJours(lundi, i);
        const du = vs.filter(v => v.prevu_le === d).sort((a, b) => a.debut_h < b.debut_h ? -1 : 1);
        jrs.push(`<div class="jour ${d === t ? 'auj' : ''}"><div class="j"><b>${JOURS[(i + 1) % 7]} ${d.slice(8)}</b>${du.map(v => v.debut_h).join('<br>')}</div><div>${du.length ? du.map(v => this.carteVisite(v)).join('') : '<div class="libre">Libre</div>'}</div></div>`);
      }
      const aPlanifier = D.boutiques.filter(b => !b.prochaineVisite && (b.due || b.feu === 'rouge' || this.plansDe(b.id).some(p => p.statut === 'valide')));
      return this.hd(this.role === 'consultant' ? (this.consultantNom(this.moi) || 'Agenda') : 'Agenda des visites', 'semaine du ' + fmtD(lundi) + ' au ' + fmtD(plusJours(lundi, 6)))
        + `<div class="row" style="margin:0 0 8px"><button class="chip" data-a="sem" data-v="-1">‹ semaine</button><button class="chip ${this.sem === 0 ? 'on' : ''}" data-a="sem" data-v="0">aujourd’hui</button><button class="chip" data-a="sem" data-v="1">semaine ›</button><span class="sp"></span><button class="btn s p" data-a="go" data-v="planifier">+ Planifier</button></div>`
        + (this.role === 'consultant' && !this.moi ? '<div class="card sm">Dites qui vous êtes dans Réglages : l’agenda montrera vos visites.</div>' : '')
        + jrs.join('')
        + (aPlanifier.length ? '<div class="cap">À planifier</div>' + aPlanifier.map(b => `<div class="card"><div class="row">${this.feuDot(b)}<b>${esc(b.court)}</b><span class="sp"></span><span class="pill ${b.feu === 'rouge' ? 'P0' : 'st-att'}">${b.feu === 'rouge' ? 'ASAP' : esc(b.due || 'à revoir')}</span></div><div class="sm mu">${esc((b.motifs || []).slice(0, 2).join(' · ') || 'dernière visite ' + (b.derniereVisite ? fmtD(b.derniereVisite.le) : 'jamais'))}</div><div class="act"><button class="btn s p" data-a="go" data-v="planifier/${esc(b.id)}">📅 Planifier</button><button class="btn s" data-a="go" data-v="tb/${esc(b.id)}">Tableau de bord</button></div></div>`).join('') : '');
    }
    carteVisite(v) {
      const b = this.boutique(v.shop) || { court: v.shop, feu: 'vert', motifs: [] };
      const ouverts = this.plansDe(v.shop, true); const p0 = ouverts.find(p => p.priorite === 'P0');
      return `<div class="vis ${p0 ? 'card alerte' : ''}" style="margin:0 0 6px;padding:9px 11px"><div class="row">${this.feuDot(b)}<b>${esc(b.court)}</b><span class="sp"></span><span class="pill ${p0 ? 'P0' : ''}">${p0 ? 'prioritaire' : esc(ST_VISITE[v.statut] || v.statut)}</span></div>
        ${this.role !== 'consultant' || !this.moi ? `<div class="xs mu">${esc(v.consultantNom || '')}</div>` : ''}
        ${p0 ? `<div class="sm dn" style="margin-top:3px">🚨 P0 ${esc(p0.titre)}${p0.retard ? ' · retard ' + p0.retard + ' j' : ''}</div>` : ''}
        <div class="sm mu" style="margin-top:2px">${b.ca ? 'CA ' + eur(b.ca.ca) + (b.ca.pct != null ? ' · ' + pct(b.ca.pct) : '') : ''}${b.google && b.google.note != null ? ' · Google ' + note1(b.google.note) : ''}${ouverts.length && !p0 ? ' · ' + ouverts.length + ' action(s)' : ''}</div>
        <div class="act"><button class="btn s p" data-a="go" data-v="${v.statut === 'terminee' ? 'historique/' + esc(v.shop) : 'fiche/' + esc(v.id)}">${v.statut === 'terminee' ? 'Historique' : v.statut === 'en_cours' ? 'Reprendre la visite' : 'Ouvrir la fiche'}</button></div></div>`;
    }
    v_portfolio() {
      const D = this.D; const s = D.seuils || {};
      return this.hd(this.role === 'franchise' ? 'Ma boutique' : 'Les boutiques', D.boutiques.length + ' ouvertes · feu par boutique')
        + D.boutiques.map(b => `<div class="card"><div class="row">${this.feuDot(b)}<b style="font-size:16px">${esc(b.court)}</b><span class="sp"></span><span class="xs mu">${b.prochaineVisite ? 'visite ' + fmtDJ(b.prochaineVisite.le) + ' ' + b.prochaineVisite.h : b.derniereVisite ? 'vue le ' + fmtD(b.derniereVisite.le) : 'jamais visitée'}</span></div>
          <div class="sm mu" style="margin-top:4px">${esc((b.motifs || []).slice(0, 3).join(' · ') || ((b.ca ? 'CA ' + pct(b.ca.pct) : '') + (b.google ? ' · Google ' + note1(b.google.note) : '') + ' · aucune alerte'))}</div>
          <div class="row" style="margin-top:8px;gap:6px"><span class="pill api">CA · Google</span><span class="pill loc">Actions · plano</span><span class="sp"></span><button class="btn s" data-a="go" data-v="tb/${esc(b.id)}">Tableau de bord</button></div></div>`).join('')
        + `<div class="cap">Comment le feu se calcule</div><div class="card sm mu">🔴 P0 ouvert plus de ${s.p0Jours} j, Google sous ${note1((s.googleCible || 4.5) - (s.googleRouge || 0.5))}, planogramme sous ${s.planoRouge} %, rubrique MSP sous ${s.mspAlerte}/20<br>🟡 P0 ou P1 ouvert, Google sous ${note1(s.googleCible)}, planogramme sous ${s.planoOrange} %, visite due${s.caSeul ? ', CA sous −' + s.caOrange + ' %' : ''}<br>🟢 sinon. ${s.caSeul ? '' : 'Le CA colore la carte, il ne déclenche pas le feu seul.'}</div>`;
    }
    v_planifier() {
      const D = this.D; const f = this.form; const shop = f.shop || this.p || (D.boutiques[0] || {}).id;
      const cons = this.role === 'consultant' && this.moi ? this.moi : (f.consultant || '');
      return this.hd('Planifier une visite', '', this.role === 'franchise' ? 'plans' : 'agenda')
        + `<div class="card">
          <div class="champ"><label>Boutique</label><select data-c="form|shop">${D.boutiques.map(b => `<option value="${esc(b.id)}" ${String(b.id) === String(shop) ? 'selected' : ''}>${esc(b.court)}</option>`).join('')}</select></div>
          ${this.role === 'consultant' && this.moi ? '' : `<div class="champ"><label>Consultant</label><select data-c="form|consultant"><option value="">—</option>${(D.consultants || []).map(c => `<option value="${esc(c.id)}" ${c.id === cons ? 'selected' : ''}>${esc(c.nom)}</option>`).join('')}</select></div>`}
          <div class="l2"><div class="champ"><label>Date</label><input type="date" data-f="form|prevu_le" value="${esc(f.prevu_le || plusJours(auj(), 1))}"></div><div class="champ"><label>Heure</label><input type="time" data-f="form|debut_h" value="${esc(f.debut_h || '09:00')}"></div></div>
          <div class="l2"><div class="champ"><label>Durée (min)</label><input type="number" data-f="form|duree_min" value="${esc(f.duree_min || 90)}"></div><div class="champ"><label>Motif</label><select data-c="form|motif">${Object.keys(MOTIFS).map(m => `<option value="${m}" ${(f.motif || 'reguliere') === m ? 'selected' : ''}>${MOTIFS[m]}</option>`).join('')}</select></div></div>
          <div class="btns"><button class="btn p w" data-a="planifier">📅 Planifier</button></div></div>`;
    }
    v_fiche() {
      const v = this.visite(this.p); if (!v) { return this.hd('Visite introuvable', '', 'agenda'); }
      const b = this.boutique(v.shop) || { court: v.shop, motifs: [] };
      const ouverts = this.plansDe(v.shop, true).slice(0, 5);
      const g = b.google; const msp = b.msp; const f = this.form;
      const enCours = v.statut === 'en_cours'; const finie = v.statut === 'terminee';
      return this.hd('Visite — ' + b.court, fmtDJ(v.prevu_le) + ' · ' + v.debut_h + ' · ' + v.duree_min + ' min · ' + (ST_VISITE[v.statut] || v.statut), 'agenda', ouverts.some(p => p.priorite === 'P0') ? '<span class="pill P0">prioritaire</span>' : this.feuDot(b))
        + `<div class="card"><div class="row"><span>📍</span><div><b>${esc(b.nom)}</b><div class="sm mu">${esc(b.ville || '')}${b.fr ? ' · ' + esc(b.fr) : ''}</div></div><span class="sp"></span><a class="btn s" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(b.nom + ' ' + (b.ville || ''))}">GPS</a></div>
          <div class="row" style="margin-top:8px"><span>🕐</span><div class="sm">${esc(v.consultantNom || 'consultant à choisir')} · ${v.debut_h} · ${v.duree_min} min</div><span class="sp"></span>${finie ? '' : `<button class="btn s" data-a="form" data-v="reprog|1">Reprogrammer</button>`}</div>
          ${f.reprog ? `<div class="l2" style="margin-top:8px"><div class="champ"><label>Date</label><input type="date" data-f="form|prevu_le" value="${esc(f.prevu_le || v.prevu_le)}"></div><div class="champ"><label>Heure</label><input type="time" data-f="form|debut_h" value="${esc(f.debut_h || v.debut_h)}"></div></div><div class="btns"><button class="btn s p" data-a="reprog" data-v="${esc(v.id)}">Enregistrer</button><button class="btn s" data-a="annuler" data-v="${esc(v.id)}">Annuler la visite</button></div>` : ''}</div>`
        + '<div class="cap">Tableau de bord — aperçu</div>' + this.kpis(b)
        + (ouverts.length ? '<div class="cap">Priorités du jour</div><div class="card">' + ouverts.map(p => `<div class="plan ${p.priorite}b"><div class="row"><span class="pill ${p.priorite}">${p.priorite}</span><b>${esc(p.titre)}</b></div><div class="sm mu">${esc(ASSIGNES[p.assigne] || '')} · ouvert le ${fmtD(p.cree_le)}${p.retard ? ' · délai dépassé de ' + p.retard + ' j' : p.echeance ? ' · délai ' + fmtD(p.echeance) : ''}${p.statut === 'reprendre' ? ' · à reprendre' : ''}${p.photo_id ? '' : ' · pas de photo'}</div></div>`).join('') + '</div>' : '')
        + `<div class="card sm"><b>Avant d’entrer</b><div class="mu">${g && g.faibles ? 'Google : ' + g.faibles + ' avis ≤ 2/5 sur 30 jours. ' : ''}${msp ? 'MSP ' + esc(msp.mois) + ' : ' + note1(msp.total) + '/20' + (Object.keys(msp.rubriques || {}).filter(k => msp.rubriques[k] < (this.D.seuils.mspAlerte || 12)).map(k => ' — ' + k + ' ' + msp.rubriques[k]).join('')) + '. ' : ''}${(b.motifs || []).length ? esc(b.motifs.slice(0, 3).join(' · ')) + '.' : 'Aucune alerte en cours.'}</div></div>`
        + (finie ? `<div class="btns"><button class="btn w" data-a="go" data-v="historique/${esc(v.shop)}">Historique</button><button class="btn w" data-a="go" data-v="review/${esc(v.id)}">Relire la review</button></div>`
          : `<div class="btns"><button class="btn p w" data-a="demarrer" data-v="${esc(v.id)}">${enCours ? '▶ Reprendre la visite' : '▶ Démarrer la visite'}</button></div>${v.statut === 'planifiee' ? `<div class="btns"><button class="btn w" data-a="confirmer" data-v="${esc(v.id)}">✓ Confirmer</button><button class="btn w" data-a="go" data-v="tb/${esc(v.shop)}">Tableau de bord</button></div>` : `<div class="btns"><button class="btn w" data-a="go" data-v="tb/${esc(v.shop)}">Tableau de bord</button></div>`}`);
    }
    v_tb() {
      const shop = this.p || this.shop; const b = this.boutique(shop); if (!b) { return this.hd('Boutique introuvable', '', 'portfolio'); }
      const B = this.B[shop]; const vEnCours = b.visiteEnCours ? this.visite(b.visiteEnCours) : null;
      const pj = this.photosJour(shop); const t = auj();
      const jour = GENRES_JOUR.map(([g, l]) => { const p = pj.find(x => x.genre === g && String(x.prise_a).slice(0, 10) === t); return p ? `<div class="ph" style="background-image:url('${esc(this.photoSrc(p))}')" data-a="voir" data-v="${esc(p.client_id || p.id)}"><small>${fmtH(p.prise_a)}</small>${p.attente ? '<span class="att">à envoyer</span>' : ''}</div>` : `<div class="ph vide" data-a="photo" data-v="${g}|${esc(shop)}${vEnCours ? '|' + vEnCours.id : ''}">📷 ${l}</div>`; }).join('');
      const alertes = [];
      this.plansDe(shop, true).forEach(p => alertes.push([p.priorite === 'P0' ? 'rouge' : 'orange', p.priorite + ' ' + p.titre + (p.retard ? ' — retard ' + p.retard + ' j' : ''), (ASSIGNES[p.assigne] || '') + (p.photo_id ? '' : ' · pas de photo')]));
      if (b.plano && b.plano.pct < (this.D.seuils.planoOrange || 80)) { alertes.push([b.plano.pct < (this.D.seuils.planoRouge || 60) ? 'rouge' : 'orange', 'Planogramme ' + b.plano.pct + ' %', 'relevé du ' + fmtD(b.plano.le) + (b.plano.ruptures ? ' · ' + b.plano.ruptures + ' rupture(s)' : '')]); }
      const cf = this.C[shop];
      if (cf && cf.assortiment && cf.assortiment.manquantes) {
        const as = cf.assortiment;
        alertes.push([as.pct != null && as.pct < 75 ? 'rouge' : 'orange',
          as.manquantes + ' référence(s) obligatoire(s) sans une seule vente sur ' + as.jours + ' j (au ' + fmtD(as.au) + ')',
          (as.liste || []).slice(0, 4).map(x => x.nom + (x.auComptoir ? '' : ' (pas de place au comptoir)')).join(' · ')]);
      }
      if (cf && cf.planogramme && cf.planogramme.obligatoiresSansPlace) {
        alertes.push(['orange', cf.planogramme.obligatoiresSansPlace + ' obligatoire(s) sans place au comptoir',
          (cf.planogramme.sansPlace || []).slice(0, 4).map(x => x.nom).join(' · ')]);
      }
      if (b.google && b.google.faibles >= 1) { alertes.push([b.google.faibles >= 2 ? 'orange' : 'vert', 'Google — ' + b.google.faibles + ' avis ≤ 2/5 sur 30 j', (b.google.derniers || []).filter(d => d.note <= 2).map(d => d.extrait).join(' · ')]); }
      if (B && B.nc && !B.nc.indispo && B.nc.ouvertes) { alertes.push(['orange', B.nc.ouvertes + ' non-conformité(s) du panel non corrigée(s) sur 30 j', B.nc.liste.filter(n => !n.corrigee).slice(0, 3).map(n => n.tache + ' (' + fmtD(n.jour) + ')').join(' · ')]); }
      return this.hd(b.court, (vEnCours ? 'visite en cours depuis ' + fmtH(vEnCours.commence_a) + ' · ' : '') + fmtDJ(t), this.role === 'franchise' ? null : 'portfolio', vEnCours ? '<span class="pill" style="background:#F7E4E6;color:#C0182B">● en visite</span>' : this.feuDot(b))
        + `<div class="cap">Photo du jour</div><div class="phs">${jour}</div><div class="xs mu" style="margin:4px 2px">Horodatée, position si consentie, EXIF retiré à la réduction · ${pj.length ? pj.length + ' photos sur 90 j' : 'première photo'}</div>`
        + '<div class="cap">4 chiffres clés</div>' + this.kpis(b)
        + `<div class="cap">Alertes ${B ? '' : '<span class="xs mu">· panel en lecture…</span>'}</div><div class="card">${alertes.length ? alertes.map(a => `<div class="al"><span class="feu ${a[0]}" style="margin-top:4px"></span><div><b>${esc(a[1])}</b><div class="xs mu">${esc(a[2])}</div></div></div>`).join('') : '<div class="sm mu">Aucune alerte.</div>'}</div>`
        + (this.role === 'franchise' ? `<div class="btns"><button class="btn p w" data-a="go" data-v="plans">Mon plan d’action</button><button class="btn w" data-a="go" data-v="historique/${esc(shop)}">Historique</button></div>`
          : `<div class="btns">${vEnCours ? `<button class="btn p w" data-a="go" data-v="checklist/${esc(vEnCours.id)}">▶ Checklist de visite</button>` : `<button class="btn p w" data-a="go" data-v="planifier/${esc(shop)}">📅 Planifier</button>`}<button class="btn w" data-a="go" data-v="historique/${esc(shop)}">Historique 3 mois</button></div>
             <div class="btns"><button class="btn w" data-a="go" data-v="msp/${esc(shop)}">MSP et équipe</button>${vEnCours ? '' : `<button class="btn w" data-a="demarrer-ici" data-v="${esc(shop)}">▶ Visite non planifiée</button>`}</div>`);
    }
    v_checklist() {
      const v = this.visite(this.p); if (!v) { return this.hd('Visite introuvable', '', 'agenda'); }
      const b = this.boutique(v.shop) || { court: v.shop }; const pts = this.pointsDe(v.id); const photos = this.photosDe(v.id);
      const cl = this.D.checklist || []; const causes = this.D.causes || {};
      let total = 0, faits = 0;
      const mods = cl.map(m => {
        const msp = m.id === 'msp' ? b.msp : null;
        return `<div class="mod"><div class="th"><b>${esc(m.nom)}</b>${this.src(m.id === 'planogramme' || m.id === 'assortiment' ? 'mix' : 'local')}<span class="sp"></span><span class="xs mu">${m.points.length} points</span></div>
          ${msp ? `<div class="card" style="margin-bottom:6px"><div class="row"><b>${note1(msp.total)} / 20</b><span class="sp"></span><span class="xs mu">MSP ${esc(msp.mois)} · ${esc(msp.par)}</span></div><div class="sm" style="margin-top:4px">${Object.keys(msp.rubriques || {}).map(k => (msp.rubriques[k] < (this.D.seuils.mspAlerte || 12) ? '🔴 ' : msp.rubriques[k] < 16 ? '🟡 ' : '🟢 ') + esc(k) + ' ' + msp.rubriques[k]).join(' · ')}</div>${msp.commentaires ? `<div class="xs mu" style="margin-top:4px">« ${esc(msp.commentaires)} »</div>` : ''}${msp.fichier ? `<div class="act"><a class="chip" target="_blank" rel="noopener" href="${esc((this.o.racine || '') + msp.fichier)}">📄 Rapport PDF</a></div>` : ''}</div>` : m.id === 'msp' ? '<div class="sm mu" style="margin:0 2px 6px">Pas de rapport mystery shopper pour cette boutique (à saisir dans MSP et équipe).</div>' : ''}
          ${m.points.map(pt => {
            total++; const p = pts[pt.ref] || {}; if (p.etat || p.note != null || p.valeur != null) { faits++; }
            const phs = photos.filter(x => x.ref === pt.ref);
            const k = `${esc(v.id)}|${esc(pt.ref)}|${m.id}`;
            return `<div class="pt ${p.etat === 'ko' ? 'ko' : ''}"><div class="l1"><button class="cb ${p.etat || ''}" data-a="etat" data-v="${k}">${p.etat === 'ok' ? '✓' : p.etat === 'ko' ? '!' : p.etat === 'na' ? '–' : ''}</button><b class="sm" style="flex:1">${esc(pt.libelle)}</b>${pt.pct ? `<input type="number" data-f="pct|${k}" value="${p.valeur != null ? p.valeur : ''}" placeholder="%" style="width:64px;padding:5px 7px">` : `<span class="notes">${[1, 2, 3, 4, 5].map(n => `<button data-a="note" data-v="${k}|${n}" class="${p.note === n ? (n <= 2 ? 'bad' : 'on') : ''}">${n}</button>`).join('')}</span>`}</div>
              <div class="act">${pt.photo || phs.length ? `<button class="chip" data-a="photo" data-v="point|${esc(v.shop)}|${esc(v.id)}|${esc(pt.ref)}">📷 ${phs.length ? phs.length + ' photo' + (phs.length > 1 ? 's' : '') : 'Photo'}</button>` : ''}${phs.map(x => `<button class="chip" data-a="voir" data-v="${esc(x.client_id || x.id)}">${fmtH(x.prise_a)}${x.attente ? ' ⏳' : ''}</button>`).join('')}
              ${pt.pct ? Object.keys(causes).map(c => `<button class="chip ${(p.causes || []).includes(c) ? 'on' : ''}" data-a="cause" data-v="${k}|${c}">${esc(causes[c])}</button>`).join('') : ''}
              <input type="text" data-f="comm|${k}" value="${esc(p.commentaire || '')}" placeholder="Remarque…" style="flex:1;min-width:120px;padding:5px 8px;font-size:12px"></div></div>`;
          }).join('')}</div>`;
      }).join('');
      return this.hd('Checklist — ' + b.court, fmtDJ(v.prevu_le) + (v.commence_a ? ' · depuis ' + fmtH(v.commence_a) : ''), 'fiche/' + v.id, `<span class="pill">${faits} / ${total}</span>`)
        + this.carteConformite(v.shop)
        + mods
        + `<div class="btns" style="margin-top:16px"><button class="btn p w" data-a="go" data-v="review/${esc(v.id)}">Review et plan d’action ›</button></div><div class="btns"><button class="btn w" data-a="go" data-v="tb/${esc(v.shop)}">Tableau de bord</button></div>`;
    }
    v_review() {
      const v = this.visite(this.p); if (!v) { return this.hd('Visite introuvable', '', 'agenda'); }
      const b = this.boutique(v.shop) || { court: v.shop }; const f = this.form; const finie = v.statut === 'terminee';
      const ecarts = this.ecartsDe(v);
      if (!f.pa) { f.pa = finie ? [] : ecarts.map(e => ({ cid: uuid(), ref: e.ref, titre: e.titre, detail: e.detail, priorite: e.grave ? 'P0' : 'P1', assigne: 'franchise', echeance: plusJours(auj(), e.grave ? 1 : 3), garde: true })); f.positif = v.positif || ''; f.sentiment = v.sentiment || 0; const c = this.conclusionDe(v.notes); f.vu = c.vu; f.probleme = c.probleme; f.reco = c.reco; f.notes = c.libre; }
      const dejaPlans = this.plansDe(v.shop).filter(p => String(p.visite_id) === String(v.id));
      const attente = this.file.filter(o => o.apres === 'photo').length;
      return this.hd('Review — ' + b.court, 'visite du ' + fmtDJ(v.prevu_le) + (v.commence_a ? ' · ' + fmtH(v.commence_a) + (v.termine_a ? ' – ' + fmtH(v.termine_a) : '') : ''), 'checklist/' + v.id)
        + `<div class="cap">Écarts relevés</div><div class="card">${ecarts.length ? ecarts.map(e => `<div class="plan ${e.grave ? 'P0b' : 'P1b'}"><div class="row"><span class="feu ${e.grave ? 'rouge' : 'orange'}"></span><b>${esc(e.titre)}</b></div><div class="sm mu">${esc(e.detail)}</div></div>`).join('') : '<div class="sm mu">Aucun écart dans la checklist.</div>'}</div>`
        + (finie ? (v.notes || v.positif ? (() => { const c = this.conclusionDe(v.notes); return `<div class="cap">Conclusion de visite</div><div class="card sm">${this.constructor.TEMPS.filter(t => c[t[0]]).map(t => `<div style="margin-bottom:6px"><b>${t[1]}</b><div>${esc(c[t[0]])}</div></div>`).join('')}${c.libre ? `<div class="mu">${esc(c.libre)}</div>` : ''}${v.positif ? `<div style="margin-top:6px"><b>Observé positif</b><div>${esc(v.positif)}</div></div>` : ''}</div>`; })() : '') + `<div class="cap">Plan d’action de cette visite</div>${dejaPlans.length ? dejaPlans.map(p => this.planLigne(p)).join('') : '<div class="card sm mu">Aucune action.</div>'}`
          : `<div class="cap">Plan d’action</div><div class="card">${f.pa.map((a, i) => `<div class="plan ${a.priorite}b" style="padding-bottom:6px"><input type="text" data-f="pa|${i}|titre" value="${esc(a.titre)}" placeholder="Action…" style="font-weight:600"><input type="text" data-f="pa|${i}|detail" value="${esc(a.detail || '')}" placeholder="Détail pour le franchisé…" style="margin-top:4px;font-size:12px">
              <div class="act">${['P0', 'P1', 'P2'].map(p => `<button class="chip ${a.priorite === p ? 'on' : ''}" data-a="pa" data-v="${i}|priorite|${p}">${p}</button>`).join('')}<span style="width:6px"></span>${Object.keys(ASSIGNES).map(k => `<button class="chip ${a.assigne === k ? 'on' : ''}" data-a="pa" data-v="${i}|assigne|${k}">${ASSIGNES[k]}</button>`).join('')}</div>
              <div class="act">${[[1, '24 h'], [2, '48 h'], [7, '1 sem.'], [14, '2 sem.']].map(([n, l]) => `<button class="chip ${a.echeance === plusJours(auj(), n) ? 'on' : ''}" data-a="pa" data-v="${i}|echeance|${plusJours(auj(), n)}">${l}</button>`).join('')}<input type="date" data-f="pa|${i}|echeance" value="${esc(a.echeance || '')}" style="width:150px;padding:5px 7px"><span class="sp"></span><button class="chip ko" data-a="pa-del" data-v="${i}">retirer</button></div></div>`).join('')}
            <div class="act"><button class="chip" data-a="pa-add">+ Ajouter une action</button></div></div>
            <div class="cap">Observé positif</div><div class="card"><textarea data-f="form|positif" placeholder="Ce qui va bien — dit au franchisé aussi">${esc(f.positif)}</textarea></div>
            <div class="cap">Équipe</div><div class="card"><div class="row"><span>Sentiment</span><span class="sp"></span><span class="notes">${[1, 2, 3, 4, 5].map(n => `<button data-a="form" data-v="sentiment|${n}" class="${f.sentiment >= n ? 'on' : ''}">★</button>`).join('')}</span></div></div>
            <div class="cap">Conclusion de visite <button class="chip" data-a="drop" data-v="pourquoi" style="margin-left:6px">${this.ouvert.pourquoi ? 'pourquoi ▴' : 'pourquoi ▾'}</button></div>
            ${this.ouvert.pourquoi ? `<div class="card sm mu"><b>Voir la réalité.</b> Sur le papier ou en visio, c’est utile mais incomplet. Sur place, on voit comment ça tourne vraiment : l’énergie de l’équipe, l’exécution face au process, l’écart entre le protocole et ce qu’on fait. Le client sort-il satisfait ? Les standards sont-ils appliqués, ou raccourcis ?<br><br><b>Diagnostiquer le vrai problème.</b> Si une boutique perd du chiffre ou que la qualité se dégrade, de près on nomme le vrai coupable — production mal synchronisée, équipe démotivée, décor qui n’invite pas, prix mal positionnés. Pas la même analyse depuis le bureau.<br><br><b>Donner du crédit à la recommandation.</b> Quand on a vu, mesuré, touché, on peut dire : « voilà ce qui ne marche pas, et voilà pourquoi ». Les équipes écoutent mieux, et vous avez des données, pas une opinion.</div>` : ''}
            <div class="card">${this.constructor.TEMPS.map(t => `<div class="champ"><label>${t[1]}</label><textarea data-f="form|${t[0]}" placeholder="${esc(t[2])}">${esc(f[t[0]] || '')}</textarea></div>`).join('')}<div class="champ"><label>Autres notes</label><textarea data-f="form|notes" placeholder="Ce qui ne rentre pas dans les trois temps…">${esc(f.notes || '')}</textarea></div></div>
            <div class="btns"><button class="btn p w" data-a="terminer" data-v="${esc(v.id)}">💾 Terminer · notifier le franchisé</button></div>`)
        + (attente ? `<div class="btns"><button class="btn w" data-a="sync">📤 Synchroniser (${attente} photo${attente > 1 ? 's' : ''} en attente)</button></div>` : '');
    }
    v_historique() {
      const shop = this.p || this.shop; const b = this.boutique(shop); if (!b) { return this.hd('Boutique introuvable', '', 'portfolio'); }
      const B = this.B[shop]; const R = this.D.reseau || {};
      const pj = this.photosJour(shop); const parJour = {}; pj.forEach(p => { const d = String(p.prise_a).slice(0, 10); (parJour[d] = parJour[d] || []).push(p); });
      const jrs = Object.keys(parJour).sort().reverse().slice(0, 3);
      const serie = b.ca ? b.ca.serie : [];
      const maxCa = Math.max(1, ...serie.map(d => Math.max(d.ca || 0, d.objectif || 0)));
      const msps = B ? B.msp : (this.D.msp && this.D.msp[shop]) || [];
      const visites = B ? B.visites : (this.D.visites || []).filter(v => String(v.shop) === String(shop) && v.statut === 'terminee').sort((a, c) => a.prevu_le < c.prevu_le ? 1 : -1);
      const pm = B ? B.plansParMois : null;
      return this.hd('Historique — ' + b.court, '3 derniers mois · ' + visites.length + ' visite' + (visites.length > 1 ? 's' : ''), this.role === 'franchise' ? 'tb' : 'tb/' + shop)
        + `<div class="cap">Photos du jour</div><div class="card">${jrs.length ? `<div class="phs">${jrs.map(d => { const p = parJour[d].find(x => x.genre === 'jour_facade') || parJour[d][0]; return `<div class="ph" style="background-image:url('${esc(this.photoSrc(p))}')" data-a="voir" data-v="${esc(p.client_id || p.id)}"><small>${fmtD(d)}</small></div>`; }).join('')}</div><div class="act">${GENRES_JOUR.map(([g, l]) => `<button class="chip" data-a="voir-serie" data-v="${esc(shop)}|${g}">${l} (${pj.filter(x => x.genre === g).length})</button>`).join('')}</div>` : '<div class="sm mu">Pas encore de photo du jour.</div>'}</div>`
        + `<div class="cap">CA de la semaine vs objectif ${this.src('api')}</div><div class="card">${serie.length ? `<div class="spark">${serie.map(d => `<i class="${d.vu ? (d.aujourdhui ? 'o' : '') : 'f'}" style="height:${Math.round((d.ca || 0) / maxCa * 100)}%" title="${esc(d.date)}"></i>`).join('')}</div><div class="row xs mu" style="margin-top:4px"><span>${fmtD(serie[0].date)}</span><span class="sp"></span><span>${fmtD(serie[serie.length - 1].date)}</span></div><div class="sm ${b.ca.pct < 0 ? 'dn' : 'up'}" style="margin-top:4px">${eur(b.ca.ca)} sur ${b.ca.jours} j · ${pct(b.ca.pct)} vs objectif${b.ca.caDelta != null ? ' · ' + pct(Math.round(b.ca.caDelta)) + ' vs référence' : ''}</div>` : '<div class="sm mu">ERP absent.</div>'}</div>`
        + `<div class="cap">Clients</div><div class="card"><div class="row sm"><span class="pill api">Google</span><span>${b.google ? note1(b.google.note) + ' · ' + b.google.avis + ' avis' + (b.google.faibles ? ' · ' + b.google.faibles + ' avis ≤ 2/5 sur 30 j' : '') : '—'}</span></div><div class="row sm" style="margin-top:6px"><span class="pill loc">MSP</span><span>${msps.length ? msps.slice().reverse().map(m => esc(MOIS[Number(m.mois.slice(5)) - 1] || m.mois) + ' ' + note1(m.total)).join(' → ') : 'aucun rapport'}</span></div></div>`
        + `<div class="cap">Plans d’action ${this.src('local')}</div><div class="card sm">${pm ? (Object.keys(pm).sort().map(m => { const x = pm[m]; const tot = Object.values(x).reduce((a, c) => a + c, 0); return esc(m) + ' : ' + tot + ' créé' + (tot > 1 ? 's' : '') + ' · ' + (x.ferme || 0) + ' fermé' + ((x.ferme || 0) > 1 ? 's' : ''); }).join('<br>') || 'aucun') : (B ? 'aucun' : 'lecture…')}<br>${this.plansDe(shop, true).length} ouvert(s) aujourd’hui</div>`
        + `<div class="cap">Planogramme ${this.src('mix')}</div><div class="card">${B && B.planoParVisite.length ? `<div class="row sm">${B.planoParVisite.map(p => esc(fmtD(p.le)) + ' ' + p.pct + ' %').join(' <span class="mu">·</span> ')}</div><div class="bar"><i style="width:${B.planoParVisite[B.planoParVisite.length - 1].pct}%"></i></div>` : '<div class="sm mu">Pas encore de relevé.</div>'}${b.plano && b.plano.ruptures ? `<div class="xs mu" style="margin-top:4px">${b.plano.ruptures} rupture(s) au dernier relevé</div>` : ''}</div>`
        + `<div class="cap">Notes du consultant</div><div class="card sm">${visites.length ? visites.slice(0, 6).map(v => `${fmtD(v.prevu_le)} — ${esc(v.consultantNom || '')}${v.sentiment ? ' · équipe ' + '★'.repeat(v.sentiment) : ''}${v.notes ? ' · ' + esc(v.notes) : ''}${v.positif ? ' · 🟢 ' + esc(v.positif) : ''}`).join('<br>') : 'aucune visite terminée'}</div>`
        + (B && B.nc && !B.nc.indispo ? `<div class="cap">Non-conformités du panel (30 j) ${this.src('api')}</div><div class="card sm">${B.nc.total} relevée(s) · <b class="${B.nc.ouvertes ? 'dn' : ''}">${B.nc.ouvertes} non corrigée(s)</b>${B.nc.liste.length ? '<br>' + B.nc.liste.slice(0, 4).map(n => (n.corrigee ? '✓ ' : '! ') + esc(n.tache) + ' (' + fmtD(n.jour) + ', ' + n.note + '/5)').join('<br>') : ''}</div>` : '')
        + `<div class="cap">Face au réseau (${R.boutiques || 0} boutiques)</div><div class="card sm">CA vs objectif : <b class="${b.ca && b.ca.pct < 0 ? 'dn' : ''}">${b.ca ? pct(b.ca.pct) : '—'}</b> · réseau ${pct(R.caPct)}<br>Google : ${b.google ? note1(b.google.note) : '—'} · réseau ${note1(R.google)}<br>Planogramme : ${b.plano ? b.plano.pct + ' %' : '—'} · réseau ${R.plano != null ? R.plano + ' %' : '—'}</div>`;
    }
    v_plans() {
      const D = this.D;
      if (this.role === 'franchise') {
        const b = this.boutique(this.shop) || { court: '' };
        const ps = this.plansDe(this.shop).sort((a, c) => (a.statut === 'ferme') - (c.statut === 'ferme'));
        const enCours = ps.filter(p => p.statut !== 'ferme').length;
        return this.hd('Mon plan d’action', b.court + ' · ' + enCours + ' en cours · ' + (ps.length - enCours) + ' fermé' + (ps.length - enCours > 1 ? 's' : ''))
          + (ps.length ? ps.map(p => this.planLigne(p, p.statut === 'ouvert' || p.statut === 'reprendre' ? `<div class="btns"><button class="btn ${p.statut === 'ouvert' ? 'p' : ''} w" data-a="photo" data-v="correction|${esc(this.shop)}||${esc(p.ref || '')}|${esc(p.id)}">📷 ${p.statut === 'reprendre' ? 'Reprendre la photo' : 'Photo de la correction'}</button></div>` : p.statut === 'attente' ? '<div class="xs mu" style="margin-top:6px">L’admin contrôle la photo.</div>' : '')).join('') : '<div class="card sm mu">Aucune action en cours. 🎉</div>')
          + `<div class="cap">Ma boutique cette semaine</div><div class="card sm">${b.ca ? `<div class="row"><span>CA</span><span class="sp"></span><b>${eur(b.ca.ca)}</b><span class="mu">/ ${eur(b.ca.objectif)}</span></div><div class="bar"><i style="width:${Math.min(100, Math.round(b.ca.ca / Math.max(1, b.ca.objectif) * 100))}%"></i></div>` : ''}${b.google ? `<div class="row" style="margin-top:6px"><span>Google</span><span class="sp"></span><b>${note1(b.google.note)}</b><span class="mu">${b.google.avis} avis</span></div>` : ''}${b.prochaineVisite ? `<div class="row" style="margin-top:6px"><span>Prochaine visite</span><span class="sp"></span><b>${fmtDJ(b.prochaineVisite.le)} ${b.prochaineVisite.h}</b></div>` : ''}</div>`;
      }
      const ouverts = (D.plans || []).filter(p => p.statut !== 'ferme');
      const parShop = {}; ouverts.forEach(p => { (parShop[p.shop] = parShop[p.shop] || []).push(p); });
      return this.hd('Plans d’action', ouverts.length + ' en cours · ' + ouverts.filter(p => p.statut === 'attente').length + ' à valider')
        + (this.role !== 'franchise' ? `<div class="row" style="margin-bottom:8px"><button class="btn s" data-a="go" data-v="admin">Corrections à valider</button></div>` : '')
        + (Object.keys(parShop).length ? Object.keys(parShop).map(s => { const b = this.boutique(s) || { court: s }; return `<div class="cap">${this.feuDot(b)} ${esc(b.court)}</div>` + parShop[s].map(p => this.planLigne(p, this.actionsPlan(p))).join(''); }).join('') : '<div class="card sm mu">Aucun plan d’action ouvert.</div>');
    }
    actionsPlan(p) {
      const f = this.form; const k = String(p.id);
      const btn = (st, l, cls) => `<button class="btn s ${cls || ''}" data-a="statut" data-v="${esc(k)}|${st}">${l}</button>`;
      const out = [];
      if (p.statut === 'attente') { out.push(btn('valide', '✅ Accepter', 'p')); out.push(`<button class="btn s" data-a="form" data-v="rep|${esc(k)}">🔄 À reprendre</button>`); }
      if (p.statut === 'valide') { out.push(btn('ferme', '✓ Confirmé sur place · fermer', 'p')); }
      if (p.statut === 'ouvert' || p.statut === 'reprendre') { out.push(`<button class="btn s" data-a="photo" data-v="correction|${esc(p.shop)}||${esc(p.ref || '')}|${esc(p.id)}">📷 Corrigé devant moi</button>`); out.push(`<button class="btn s" data-a="form" data-v="esc|${esc(k)}">🚨 Escalader</button>`); }
      if (p.statut === 'escalade') { out.push(btn('ouvert', 'Rouvrir')); out.push(btn('ferme', 'Fermer')); }
      let form = '';
      if (f.rep === k) { form = `<div class="champ"><input type="text" data-f="form|retour" placeholder="Ce qui manque…" value="${esc(f.retour || '')}"></div><div class="l2" style="margin-top:6px"><input type="date" data-f="form|echeance" value="${esc(f.echeance || plusJours(auj(), 2))}"><button class="btn s p" data-a="statut" data-v="${esc(k)}|reprendre">Renvoyer au franchisé</button></div>`; }
      if (f.esc === k) { form = `<div class="champ"><input type="text" data-f="form|motif" placeholder="Pourquoi escalader…" value="${esc(f.motif || '')}"></div><div class="btns" style="margin-top:6px"><button class="btn s p" data-a="statut" data-v="${esc(k)}|escalade">Escalader à Sam</button></div>`; }
      return out.length ? `<div class="btns" style="flex-wrap:wrap">${out.join('')}</div>${form}` : form;
    }
    v_admin() {
      const D = this.D;
      const att = (D.plans || []).filter(p => p.statut === 'attente');
      const escs = (D.plans || []).filter(p => p.statut === 'escalade' || (p.priorite === 'P0' && p.retard > 0 && p.statut !== 'ferme'));
      const lundi = lundiDe(auj(), 0);
      const sem = (D.plans || []).filter(p => String(p.maj_le).slice(0, 10) >= lundi);
      return this.hd('Corrections à valider', att.length + ' photo' + (att.length > 1 ? 's' : '') + ' reçue' + (att.length > 1 ? 's' : '') + ' · vue ' + this.role)
        + (att.length ? att.map(p => { const b = this.boutique(p.shop) || { court: p.shop }; const apres = (D.photos || []).find(x => x.id === p.photo_id || String(x.plan_id) === String(p.id)); const avant = p.visite_id ? this.photosDe(p.visite_id).find(x => x.ref === p.ref) : null;
          return `<div class="card"><div class="row"><b>${esc(b.court)}</b><span class="pill ${p.priorite}">${p.priorite}</span><span class="sp"></span><span class="xs mu">${fmtD(p.maj_le)} ${fmtH(p.maj_le)}</span></div><div class="sm" style="margin-top:3px">${esc(p.titre)}${p.detail ? ' · <span class="mu">' + esc(p.detail) + '</span>' : ''}</div>
            <div class="phs n2" style="margin-top:8px">${avant ? `<div class="ph" style="background-image:url('${esc(this.photoSrc(avant))}')" data-a="voir" data-v="${esc(avant.client_id || avant.id)}"><small>avant · visite</small></div>` : '<div class="ph vide" style="color:var(--color-text-muted)">pas de photo avant</div>'}${apres ? `<div class="ph" style="background-image:url('${esc(this.photoSrc(apres))}')" data-a="voir" data-v="${esc(apres.client_id || apres.id)}"><small>franchisé · ${fmtD(apres.prise_a)} ${fmtH(apres.prise_a)}</small></div>` : '<div class="ph vide" style="color:var(--color-text-muted)">photo non reçue</div>'}</div>
            ${this.actionsPlan(p)}</div>`; }).join('') : '<div class="card sm mu">Rien à valider.</div>')
        + (escs.length ? '<div class="cap">Escalades et retards</div>' + escs.map(p => this.planLigne(p, this.actionsPlan(p))).join('') : '')
        + `<div class="cap">Cette semaine</div><div class="card sm">✅ ${sem.filter(p => p.statut === 'valide' || p.statut === 'ferme').length} validées ou fermées · 🔄 ${sem.filter(p => p.statut === 'reprendre').length} à reprendre · 🚨 ${sem.filter(p => p.statut === 'escalade').length} escaladée(s)</div>`;
    }
    v_synthese() {
      const S = this.S;
      if (!S) { this.chargerSynthese(); return this.hd('Synthèse réseau', 'lecture…', null); }
      const c = S.compteurs; const R = S.reseau || {};
      return this.hd('Synthèse réseau', fmtDJ(S.date) + ' · ' + S.boutiques.length + ' boutiques · ' + Object.keys(S.consultants || {}).length + ' consultants actifs', null, `<button class="chip" data-a="synthese">↻</button>`)
        + `<div class="stat"><div><div class="xs mu">Plans d’action en cours</div><div class="k" style="margin-top:4px">${c.enCours}</div><div class="xs mu">${c.p0} P0 · ${c.p1} P1 · ${c.p2} P2</div></div><div><div class="xs mu">En attente de validation</div><div class="k" style="margin-top:4px">${c.attente}</div><div class="xs mu">photos à contrôler</div></div><div><div class="xs mu">Fermés cette semaine</div><div class="k" style="margin-top:4px;color:#2d7a3e">${c.fermes}</div><div class="xs mu">validés et confirmés</div></div></div>
          <div class="cap">Les boutiques</div><div class="card" style="padding:4px 6px;overflow:auto"><table class="tbl"><tr><th>Boutique</th><th>Feu</th><th>Dernière visite</th><th>CA semaine ${this.src('api')}</th><th>Google ${this.src('api')}</th><th>Plano</th><th>Actions</th><th>Signal</th></tr>${S.boutiques.map(b => `<tr><td><b>${esc(b.court)}</b></td><td>${this.feuDot(b)}</td><td>${b.derniereVisite ? fmtD(b.derniereVisite.le) + ' · ' + esc(b.derniereVisite.consultant) : '<span class="mu">jamais</span>'}</td><td>${b.ca ? eur(b.ca.ca) + ' · <span class="' + (b.ca.pct < 0 ? 'dn' : 'up') + '">' + pct(b.ca.pct) + '</span>' : '—'}</td><td>${b.google ? note1(b.google.note) + ' (' + b.google.avis + ')' : '—'}</td><td>${b.plano ? b.plano.pct + ' %' : '—'}</td><td class="${b.p0 ? 'dn' : ''}">${b.plansOuverts}${b.p0 ? ' · ' + b.p0 + ' P0' : ''}</td><td class="${b.feu === 'rouge' ? 'dn' : 'mu'}">${esc((b.motifs || []).slice(0, 2).join(' · ') || '—')}</td></tr>`).join('')}</table></div>
          <div class="grid2"><div><div class="cap">Escalades possibles</div><div class="card sm">${S.escalades.length ? S.escalades.map(e => `<div class="al"><span class="feu ${e.feu}" style="margin-top:4px"></span><div><b>${esc(e.titre)}</b><div class="xs mu">${esc(e.detail)}</div></div></div>`).join('') : '<span class="mu">Aucune.</span>'}</div></div>
          <div><div class="cap">Actions requises aujourd’hui</div><div class="card sm">${S.actions.length ? S.actions.map((a, i) => (i + 1) + '. ' + esc(a)).join('<br>') : '<span class="mu">Rien d’urgent.</span>'}</div><div class="cap">Consultants cette semaine</div><div class="card sm">${Object.keys(S.consultants || {}).length ? Object.keys(S.consultants).map(k => esc(k) + ' ' + S.consultants[k]).join(' · ') : '<span class="mu">aucune visite planifiée</span>'}<div class="xs mu" style="margin-top:4px">${S.visitesSemaine} visites · ${S.checklists} terminées · ${S.photosSemaine} photos</div></div></div></div>
          <div class="card sm mu">Réseau : CA ${pct(R.caPct)} vs objectif · Google ${note1(R.google)} · planogramme ${R.plano != null ? R.plano + ' %' : '—'}. La synthèse part aussi par mail le matin si une adresse est réglée (Réglages).</div>`;
    }
    async chargerSynthese() { try { this.S = await this.lire('/visites/synthese', 'synthese'); } catch (e) { this.S = null; this.dire('Synthèse indisponible hors ligne.'); return; } this.rendre(); }
    v_msp() {
      const shop = this.p || this.shop; const b = this.boutique(shop); if (!b) { return this.hd('Boutique introuvable', '', 'portfolio'); }
      const f = this.form; const msps = (this.D.msp && this.D.msp[shop]) || []; const eq = b.equipe;
      return this.hd('MSP et équipe — ' + b.court, 'rapport mystery shopper, relevé d’effectif', 'tb/' + shop)
        + `<div class="cap">Rapport mystery shopper ${this.src('local')}</div><div class="card">${msps.length ? msps.map(m => `<div class="row sm" style="padding:4px 0"><b>${esc(m.mois)}</b><span>${note1(m.total)}/20</span><span class="mu">${Object.keys(m.rubriques || {}).map(k => k + ' ' + m.rubriques[k]).join(' · ')}</span><span class="sp"></span>${m.fichier ? `<a class="chip" target="_blank" rel="noopener" href="${esc((this.o.racine || '') + m.fichier)}">PDF</a>` : ''}</div>`).join('') : '<div class="sm mu">Aucun rapport.</div>'}
          <div class="champ" style="margin-top:10px"><label>Mois</label><input type="text" data-f="form|mois" value="${esc(f.mois || auj().slice(0, 7))}" placeholder="AAAA-MM"></div>
          <div class="rub">${RUBRIQUES_MSP.map(r => `<div class="champ"><label>${r}</label><input type="number" data-f="form|r_${r}" value="${esc(f['r_' + r] || '')}" placeholder="/20"></div>`).join('')}</div>
          <div class="champ"><label>Commentaires du rapport</label><textarea data-f="form|commentaires">${esc(f.commentaires || '')}</textarea></div>
          <div class="champ"><label>Rapport PDF (facultatif, 8 Mo max)</label><input type="file" accept="application/pdf" data-c="fichier|msp"></div>
          <div class="btns"><button class="btn p w" data-a="msp" data-v="${esc(shop)}">💾 Enregistrer le rapport</button></div></div>
          <div class="cap">Équipe ${this.src('local')}</div><div class="card">${eq ? `<div class="sm mu" style="margin-bottom:6px">Dernier relevé ${fmtD(eq.releve_le)} : ${eq.effectif != null ? eq.effectif : '—'} présents${eq.prevu != null ? ' / ' + eq.prevu + ' prévus' : ''}${eq.departs ? ' · ' + eq.departs + ' départ(s)' : ''}</div>` : ''}
          <div class="rub" style="grid-template-columns:repeat(3,1fr)"><div class="champ"><label>Effectif</label><input type="number" data-f="form|effectif" value="${esc(f.effectif || '')}"></div><div class="champ"><label>Prévu</label><input type="number" data-f="form|prevu" value="${esc(f.prevu || '')}"></div><div class="champ"><label>Départs (mois)</label><input type="number" data-f="form|departs" value="${esc(f.departs || '')}"></div></div>
          <div class="btns"><button class="btn w" data-a="equipe" data-v="${esc(shop)}">💾 Relever l’effectif</button></div></div>`;
    }
    v_reglages() {
      const D = this.D; const f = this.form; const s = D.seuils || {};
      const moi = this.role === 'consultant' ? `<div class="cap">Qui suis-je</div><div class="card"><select data-c="moi">${[['', '— choisir —']].concat((D.consultants || []).map(c => [c.id, c.nom])).map(([v, l]) => `<option value="${esc(v)}" ${v === this.moi ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select><div class="xs mu" style="margin-top:6px">L’agenda montre les visites de ce consultant ; les visites planifiées ici lui sont attribuées.</div></div>` : '';
      const push = `<div class="cap">Notifications</div><div class="card"><div class="sm mu">${this.o.mobile ? 'Sur ce téléphone : rappels J-1 et jour J, plans d’action, corrections reçues, escalades.' : 'À activer sur le téléphone (page Application mobile).'}</div>${this.o.mobile ? `<div class="btns"><button class="btn w" data-a="push">${this.pushEtat === 'abonne' ? '🔕 Désactiver' : '🔔 Activer les notifications'}</button></div>${this.pushMotif ? `<div class="xs dn" style="margin-top:4px">${esc(this.pushMotif)}</div>` : ''}` : ''}</div>`;
      const hors = `<div class="cap">Hors ligne</div><div class="card sm"><div>${this.file.length} écriture(s) en attente · dernière sync ${esc(this.sync || '—')}</div><div class="btns"><button class="btn s" data-a="sync">📤 Synchroniser</button><button class="btn s" data-a="recharger">↻ Recharger</button><button class="btn s" data-a="raz">Vider la réserve</button></div></div>`;
      if (this.role !== 'admin' && !(this.role === 'consultant' && !this.o.mobile)) { return this.hd('Réglages', '') + moi + push + hors; }
      const R = this.R;
      if (!R) { this.chargerReglages(); }
      const cl = f.checklist || (R ? R.checklist : D.checklist) || [];
      const se = f.seuils || Object.assign({}, s);
      const fq = f.frequence || Object.assign({}, D.frequence || {});
      if (!f.checklist) { f.checklist = JSON.parse(JSON.stringify(cl)); f.seuils = se; f.frequence = fq; }
      const num = (k, l, aide) => `<div class="champ"><label>${l}</label><input type="number" step="${/google/i.test(k) ? '0.1' : '1'}" data-f="se|${k}" value="${esc(f.seuils[k])}">${aide ? `<div class="xs mu">${aide}</div>` : ''}</div>`;
      return this.hd('Réglages visites', 'checklist, seuils du feu, fréquences, horloge') + moi + push
        + `<div class="cap">Checklist (${f.checklist.reduce((a, m) => a + m.points.length, 0)} points)</div>${f.checklist.map((m, mi) => `<div class="card"><div class="row"><b>${esc(m.nom)}</b><span class="sp"></span><button class="chip" data-a="cl-add" data-v="${mi}">+ point</button></div>${m.points.map((p, pi) => `<div class="row" style="margin-top:6px"><input type="text" data-f="cl|${mi}|${pi}|libelle" value="${esc(p.libelle)}" style="flex:1"><button class="chip ${p.photo ? 'on' : ''}" data-a="cl-tog" data-v="${mi}|${pi}|photo" title="photo attendue">📷</button>${m.id === 'planogramme' ? `<button class="chip ${p.pct ? 'on' : ''}" data-a="cl-tog" data-v="${mi}|${pi}|pct" title="saisie en %">%</button>` : ''}<button class="chip ko" data-a="cl-del" data-v="${mi}|${pi}">×</button></div>`).join('')}</div>`).join('')}
          <div class="cap">Feu tricolore</div><div class="card"><div class="l2">${num('p0Jours', 'P0 ouvert plus de (jours) → rouge')}${num('escaladeJours', 'Échéance dépassée de (jours) → escalade auto')}${num('googleCible', 'Cible Google')}${num('googleRouge', 'Sous la cible de (points) → rouge')}${num('planoOrange', 'Planogramme sous (%) → orange')}${num('planoRouge', 'Planogramme sous (%) → rouge')}${num('mspAlerte', 'Rubrique MSP sous (/20) → rouge')}${num('caOrange', 'CA sous l’objectif de (%) → orange')}</div><div class="act"><button class="chip ${f.seuils.caSeul ? 'on' : ''}" data-a="se-tog" data-v="caSeul">Le CA seul peut colorer le feu</button></div></div>
          <div class="cap">Fréquence de visite (jours)</div><div class="card"><div class="l2">${D.boutiques.map(b => `<div class="champ"><label>${esc(b.court)}</label><input type="number" data-f="fq|${esc(b.id)}" value="${esc(f.frequence[b.id] || '')}" placeholder="${esc(s.visiteJours || 7)}"></div>`).join('')}</div></div>
          <div class="cap">Horloge et mails</div><div class="card"><div class="l2"><div class="champ"><label>Rappel J-1 à</label><input type="time" data-f="se|rappelJ1" value="${esc(f.seuils.rappelJ1)}"></div><div class="champ"><label>Rappel jour J à</label><input type="time" data-f="se|rappelJour" value="${esc(f.seuils.rappelJour)}"></div><div class="champ"><label>Synthèse à</label><input type="time" data-f="se|syntheseHeure" value="${esc(f.seuils.syntheseHeure)}"></div><div class="champ"><label>Adresse de la synthèse</label><input type="email" data-f="se|mailSynthese" value="${esc(f.seuils.mailSynthese || '')}" placeholder="—"></div></div>
            <div class="act"><button class="chip ${f.seuils.mails ? 'on' : ''}" data-a="se-tog" data-v="mails">Les mails partent (rappels aux consultants, synthèse)${R && !R.smtp ? ' — SMTP non configuré' : ''}</button></div>
            <div class="xs mu" style="margin-top:8px">${R ? 'Dernier passage de l’horloge : ' + esc((R.cron && R.cron.dernier) || 'jamais') + ' · J-1 ' + esc((R.cron && R.cron.j1) || '—') + ' · jour ' + esc((R.cron && R.cron.jour) || '—') + ' · synthèse ' + esc((R.cron && R.cron.synthese) || '—') : ''}</div>
            <div class="btns"><button class="btn s" data-a="tick">⏱ Passer l’horloge maintenant</button></div></div>
          <div class="btns"><button class="btn p w" data-a="save-reglages">💾 Enregistrer les réglages</button></div>` + hors;
    }
    async chargerReglages() { try { this.R = await this.lire('/visites/reglages', 'reglages'); this.rendre(); } catch (e) { /* hors ligne */ } }

    /* --- événements --------------------------------------------------------- */
    clic(e) {
      const el = e.target.closest('[data-a]'); if (!el || !this.host.contains(el)) { return; }
      const a = el.dataset.a; const v = el.dataset.v || ''; const parts = v.split('|');
      if (a === 'go') { const [vue, p] = v.split('/'); this.go(vue, p); return; }
      if (a === 'fermer-voir') { this.voir = null; this.rendre(); return; }
      if (a === 'voir') { const p = (this.D.photos || []).find(x => String(x.id) === v || x.client_id === v); if (p) { this.voir = { src: this.photoSrc(p), txt: (this.boutique(p.shop) || {}).court + ' · ' + fmtDJ(p.prise_a) + ' ' + fmtH(p.prise_a) + ' · ' + p.genre.replace('jour_', '') + (p.attente ? ' · pas encore envoyée' : '') }; this.rendre(); } return; }
      if (a === 'voir-serie') { const ps = this.photosJour(parts[0]).filter(x => x.genre === parts[1]); if (ps.length) { this.voir = { src: this.photoSrc(ps[0]), txt: ps.length + ' photo(s) · la plus récente ' + fmtDJ(ps[0].prise_a) }; this.rendre(); } return; }
      if (a === 'sem') { this.sem = v === '0' ? 0 : this.sem + Number(v); this.rendre(); return; }
      if (a === 'drop') { this.ouvert[v] = !this.ouvert[v]; this.rendre(); return; }
      if (a === 'sync') { this.rejouer(); return; }
      if (a === 'recharger') { this.recharger(); return; }
      if (a === 'synthese') { this.S = null; this.rendre(); return; }
      if (a === 'raz') { Idb.vider('cache').then(() => this.recharger()); return; }
      if (a === 'form') { this.form[parts[0]] = parts.length > 1 ? (isNaN(parts[1]) ? parts[1] : Number(parts[1])) : true; if (parts[0] === 'sentiment' && this.form.sentiment === Number(parts[1])) { /* garde */ } this.rendre(); return; }
      if (a === 'planifier') { this.planifier(); return; }
      if (a === 'reprog') { const f = this.form; this.ecrire({ method: 'PUT', path: '/visites/' + encodeURIComponent(v), body: { prevu_le: f.prevu_le, debut_h: f.debut_h, qui: this.qui() }, apres: 'visite' }); const vis = this.visite(v); if (vis) { if (f.prevu_le) { vis.prevu_le = f.prevu_le; } if (f.debut_h) { vis.debut_h = f.debut_h; } } this.form = {}; this.dire('Visite reprogrammée.'); return; }
      if (a === 'annuler') { if (!confirm('Annuler cette visite ?')) { return; } this.majVisite(v, { statut: 'annulee' }); this.go('agenda'); return; }
      if (a === 'confirmer') { this.majVisite(v, { statut: 'confirmee' }); this.dire('Visite confirmée.'); return; }
      if (a === 'demarrer') { const vis = this.visite(v); if (vis && vis.statut !== 'en_cours') { this.majVisite(v, { statut: 'en_cours' }); const b = this.boutique(vis.shop); if (b) { b.visiteEnCours = vis.id; } } this.go('tb', vis ? vis.shop : null); return; }
      if (a === 'demarrer-ici') { this.form = { shop: v, prevu_le: auj(), debut_h: maintenant().slice(11), duree_min: 60, motif: 'asap' }; this.planifier(true); return; }
      if (a === 'photo') { this.prendrePhoto(parts[0], parts[1], parts[2], parts[3], parts[4]); return; }
      if (a === 'etat') { const cyc = { '': 'ok', ok: 'ko', ko: 'na', na: '' }; const p = this.point(parts[0], parts[1], parts[2]); p.etat = cyc[p.etat || ''] || ''; this.point_save(parts[0], p); return; }
      if (a === 'note') { const p = this.point(parts[0], parts[1], parts[2]); p.note = p.note === Number(parts[3]) ? null : Number(parts[3]); if (p.note != null && !p.etat) { p.etat = p.note <= 2 ? 'ko' : 'ok'; } this.point_save(parts[0], p); return; }
      if (a === 'cause') { const p = this.point(parts[0], parts[1], parts[2]); p.causes = p.causes || []; const i = p.causes.indexOf(parts[3]); if (i >= 0) { p.causes.splice(i, 1); } else { p.causes.push(parts[3]); } this.point_save(parts[0], p); return; }
      if (a === 'pa') { const i = Number(parts[0]); if (this.form.pa && this.form.pa[i]) { this.form.pa[i][parts[1]] = parts[2]; this.rendre(); } return; }
      if (a === 'pa-del') { this.form.pa.splice(Number(v), 1); this.rendre(); return; }
      if (a === 'pa-add') { this.form.pa.push({ cid: uuid(), titre: '', detail: '', priorite: 'P1', assigne: 'franchise', echeance: plusJours(auj(), 3) }); this.rendre(); return; }
      if (a === 'terminer') { this.terminer(v); return; }
      if (a === 'statut') { this.statut(parts[0], parts[1]); return; }
      if (a === 'msp') { this.msp(v); return; }
      if (a === 'equipe') { const f = this.form; this.ecrire({ method: 'PUT', path: '/equipe/' + encodeURIComponent(v), body: { effectif: f.effectif, prevu: f.prevu, departs: f.departs, qui: this.qui() } }); const b = this.boutique(v); if (b) { b.equipe = { effectif: f.effectif != null && f.effectif !== '' ? Number(f.effectif) : null, prevu: f.prevu ? Number(f.prevu) : null, departs: f.departs ? Number(f.departs) : null, releve_le: auj() }; } this.form = {}; this.dire('Effectif relevé.'); return; }
      if (a === 'push') { this.pushBasculer(); return; }
      if (a === 'cl-add') { this.form.checklist[Number(v)].points.push({ ref: 'p' + Date.now().toString(36), libelle: '', photo: false, pct: this.form.checklist[Number(v)].id === 'planogramme' }); this.rendre(); return; }
      if (a === 'cl-del') { this.form.checklist[Number(parts[0])].points.splice(Number(parts[1]), 1); this.rendre(); return; }
      if (a === 'cl-tog') { const p = this.form.checklist[Number(parts[0])].points[Number(parts[1])]; p[parts[2]] = !p[parts[2]]; this.rendre(); return; }
      if (a === 'se-tog') { this.form.seuils[v] = !this.form.seuils[v]; this.rendre(); return; }
      if (a === 'save-reglages') { const f = this.form; this.ecrire({ method: 'PUT', path: '/visites/reglages', body: { checklist: f.checklist, seuils: f.seuils, frequence: f.frequence } }).then(() => { this.R = null; this.form = {}; this.recharger(); }); this.dire('Réglages enregistrés.'); return; }
      if (a === 'tick') { fetch(this.api('/visites/tick'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: '{"force":true}' }).then(r => r.json()).then(j => { const f = j.fait || {}; this.dire('Horloge : ' + (f.escalades || 0) + ' escalade(s), ' + (f.rappelsJ1 || 0) + ' rappel(s) J-1, ' + (f.rappelsJour || 0) + ' du jour' + (f.synthese ? ', synthèse' : '') + (f.mails ? ', ' + f.mails + ' mail(s)' : '') + '.'); this.R = null; this.rendre(); }).catch(() => this.dire('Horloge injoignable.')); return; }
    }
    saisie(e) {
      const el = e.target; const f = el.dataset && el.dataset.f; if (!f) { return; }
      const parts = f.split('|');
      if (parts[0] === 'form') { this.form[parts[1]] = el.value; return; }
      if (parts[0] === 'pa') { if (this.form.pa && this.form.pa[Number(parts[1])]) { this.form.pa[Number(parts[1])][parts[2]] = el.value; } return; }
      if (parts[0] === 'se') { this.form.seuils[parts[1]] = el.type === 'number' ? Number(el.value) : el.value; return; }
      if (parts[0] === 'fq') { this.form.frequence[parts[1]] = el.value; return; }
      if (parts[0] === 'cl') { this.form.checklist[Number(parts[1])].points[Number(parts[2])][parts[3]] = el.value; return; }
      if (parts[0] === 'pct' || parts[0] === 'comm') {
        const p = this.point(parts[1], parts[2], parts[3]);
        if (parts[0] === 'pct') { p.valeur = el.value === '' ? null : Math.max(0, Math.min(100, Number(el.value))); } else { p.commentaire = el.value; }
        clearTimeout(this._pt); this._pt = setTimeout(() => this.point_save(parts[1], p), 700);
      }
    }
    change(e) {
      const el = e.target; const c = el.dataset && el.dataset.c; if (!c) { return; }
      const parts = c.split('|');
      if (parts[0] === 'form') { this.form[parts[1]] = el.value; this.rendre(); return; }
      if (parts[0] === 'moi') { this.moi = el.value; localStorage.setItem('vi.moi', this.moi); this.recharger(); return; }
      if (parts[0] === 'fichier' && el.files && el.files[0]) { const r = new FileReader(); r.onload = () => { this.form.fichier = r.result; this.dire('PDF prêt à envoyer.'); }; r.readAsDataURL(el.files[0]); }
    }

    /* --- actions ------------------------------------------------------------- */
    point(vid, ref, module) {
      let p = (this.D.points || []).find(x => String(x.visite_id) === String(vid) && x.ref === ref);
      if (!p) { const lib = ((this.D.checklist || []).find(m => m.id === module) || { points: [] }).points.find(x => x.ref === ref); p = { visite_id: vid, module, ref, libelle: lib ? lib.libelle : ref, etat: '', note: null, valeur: null, commentaire: '', causes: [] }; this.D.points.push(p); }
      return p;
    }
    point_save(vid, p) {
      const body = { points: [{ ref: p.ref, module: p.module, libelle: p.libelle, etat: p.etat || '', note: p.note, valeur: p.valeur, commentaire: p.commentaire || '', causes: p.causes || [] }] };
      const deja = this.file.find(o => o.apres === 'points' && o.path === '/visites/' + encodeURIComponent(vid) + '/points' && !o.envoi);
      if (deja) { deja.body.points = deja.body.points.filter(x => x.ref !== p.ref).concat(body.points); Idb.put('file', deja); this.rendre(); this.rejouer(); return; }
      this.ecrire({ method: 'PUT', path: '/visites/' + encodeURIComponent(vid) + '/points', body, apres: 'points' });
    }
    majVisite(id, champs) {
      const v = this.visite(id); if (v) { Object.assign(v, champs); if (champs.statut === 'en_cours' && !v.commence_a) { v.commence_a = maintenant(); } if (champs.statut === 'terminee') { v.termine_a = maintenant(); } }
      return this.ecrire({ method: 'PUT', path: '/visites/' + encodeURIComponent(id), body: Object.assign({ qui: this.qui() }, champs), apres: 'visite' });
    }
    planifier(demarrer) {
      const f = this.form; const D = this.D;
      const shop = f.shop || this.p || (D.boutiques[0] || {}).id;
      const cons = this.role === 'consultant' && this.moi ? this.moi : (f.consultant || '');
      const cid = uuid();
      const body = { client_id: cid, shop, consultant: cons, consultant_nom: this.consultantNom(cons), prevu_le: f.prevu_le || plusJours(auj(), 1), debut_h: f.debut_h || '09:00', duree_min: Number(f.duree_min || 90), motif: f.motif || 'reguliere', qui: this.qui() };
      D.visites.push({ id: cid, client_id: cid, shop, consultant: cons, consultantNom: body.consultant_nom, prevu_le: body.prevu_le, debut_h: body.debut_h, duree_min: body.duree_min, motif: body.motif, statut: 'planifiee', attente: true });
      const b = this.boutique(shop); if (b && !b.prochaineVisite) { b.prochaineVisite = { id: cid, le: body.prevu_le, h: body.debut_h, consultant: body.consultant_nom }; b.due = null; }
      const p = this.ecrire({ method: 'POST', path: '/visites', body, apres: 'visite' });
      if (demarrer) { p.then(() => { const v = this.visite(cid) || this.D.visites.find(x => x.client_id === cid); if (v) { this.majVisite(v.id, { statut: 'en_cours' }); const bb = this.boutique(shop); if (bb) { bb.visiteEnCours = v.id; } this.go('tb', shop); } }); this.dire('Visite ouverte.'); return; }
      this.sem = Math.round(jours(lundiDe(auj(), 0), lundiDe(body.prevu_le, 0)) / 7);
      this.go('agenda'); this.dire('Visite planifiée ' + fmtDJ(body.prevu_le) + ' ' + body.debut_h + '.');
    }
    prendrePhoto(genre, shop, visiteId, ref, planId) {
      const inp = document.createElement('input'); inp.type = 'file'; inp.accept = 'image/*'; inp.capture = 'environment';
      inp.onchange = async () => {
        const fichier = inp.files && inp.files[0]; if (!fichier) { return; }
        this.dire('Réduction de la photo…');
        let img; try { img = await compresser(fichier); } catch (e) { this.dire('Photo illisible.'); return; }
        const pos = await position();
        const cid = uuid();
        const body = { client_id: cid, shop, visite_id: visiteId || null, ref: ref || null, plan_id: planId || null, genre, data: img.data, prise_a: maintenant(), lat: pos ? pos.lat : null, lng: pos ? pos.lng : null, par: this.role };
        this.D.photos.unshift({ client_id: cid, visite_id: visiteId || null, shop, plan_id: planId || null, ref: ref || null, genre, data: img.data, prise_a: body.prise_a, attente: true });
        const p = this.ecrire({ method: 'POST', path: '/visites/photos', body, apres: 'photo' });
        if (genre === 'correction' && planId) {
          const plan = this.plan(planId); if (plan) { plan.statut = 'attente'; plan.photo_client_id = cid; plan.attente = true; plan.maj_le = maintenant(); }
          p.then(() => this.ecrire({ method: 'PUT', path: '/plans/' + encodeURIComponent(planId), body: { statut: 'attente', photo_client_id: cid, role: this.role, qui: this.qui(), commentaire: 'photo de la correction' }, apres: 'plan' }));
          this.dire('Photo envoyée : en attente de validation.');
        } else { this.dire('Photo prise' + (this.enLigne ? '' : ' — envoyée au retour du réseau') + '.'); }
      };
      inp.click();
    }
    terminer(vid) {
      const v = this.visite(vid); if (!v) { return; }
      const f = this.form;
      const plans = (f.pa || []).filter(a => a.titre && a.titre.trim()).map(a => ({ client_id: a.cid, shop: v.shop, visite_id: v.id, ref: a.ref || null, titre: a.titre.trim(), detail: a.detail || '', priorite: a.priorite, assigne: a.assigne, echeance: a.echeance || null }));
      if (plans.length) {
        plans.forEach(p => this.D.plans.push(Object.assign({ id: p.client_id, statut: 'ouvert', cree_par: this.qui(), cree_le: maintenant(), maj_le: maintenant(), retard: 0, age: 0, attente: true }, p)));
        this.ecrire({ method: 'POST', path: '/plans', body: { plans, qui: this.qui() }, apres: 'plans' });
      }
      this.majVisite(vid, { statut: 'terminee', sentiment: f.sentiment || null, positif: f.positif || '', notes: this.notesDe(f) });
      const b = this.boutique(v.shop); if (b) { b.visiteEnCours = null; b.derniereVisite = { id: v.id, le: v.prevu_le, consultant: v.consultantNom }; if (b.prochaineVisite && String(b.prochaineVisite.id) === String(v.id)) { b.prochaineVisite = null; } }
      this.form = {};
      this.go('historique', v.shop);
      this.dire(plans.length ? plans.length + ' action(s) envoyée(s) au franchisé.' : 'Visite terminée.');
    }
    statut(id, vers) {
      const p = this.plan(id); if (!p) { return; }
      const f = this.form;
      const body = { statut: vers, role: this.role, qui: this.qui() };
      if (vers === 'reprendre') { body.retour = f.retour || ''; body.echeance = f.echeance || plusJours(auj(), 2); }
      if (vers === 'escalade') { body.escalade_motif = f.motif || ''; }
      Object.assign(p, { statut: vers, attente: true, maj_le: maintenant() }, vers === 'reprendre' ? { retour: body.retour, echeance: body.echeance } : {}, vers === 'escalade' ? { escalade_motif: body.escalade_motif } : {}, vers === 'ferme' ? { ferme_le: maintenant() } : {});
      this.form = {};
      this.ecrire({ method: 'PUT', path: '/plans/' + encodeURIComponent(id), body, apres: 'plan' });
      this.dire({ valide: 'Correction acceptée.', reprendre: 'Renvoyé au franchisé.', ferme: 'Fermé.', escalade: 'Escaladé à Sam.', ouvert: 'Rouvert.', attente: 'En attente.' }[vers] || 'Enregistré.');
    }
    msp(shop) {
      const f = this.form; const rub = {};
      RUBRIQUES_MSP.forEach(r => { if (f['r_' + r] !== undefined && f['r_' + r] !== '') { rub[r] = Number(f['r_' + r]); } });
      if (!Object.keys(rub).length) { this.dire('Saisissez au moins une rubrique.'); return; }
      const mois = f.mois || auj().slice(0, 7);
      const total = Math.round(Object.values(rub).reduce((a, c) => a + c, 0) / Object.keys(rub).length * 10) / 10;
      const b = this.boutique(shop); if (b) { const m = { shop, mois, total, rubriques: rub, commentaires: f.commentaires || '', par: this.qui(), le: maintenant() }; this.D.msp[shop] = [m].concat((this.D.msp[shop] || []).filter(x => x.mois !== mois)); b.msp = m; }
      this.ecrire({ method: 'POST', path: '/msp', body: { shop, mois, rubriques: rub, commentaires: f.commentaires || '', fichier: f.fichier || null, qui: this.qui() } });
      this.form = {}; this.dire('Rapport MSP enregistré.');
    }

    /* --- notifications push (téléphone) --------------------------------------- */
    async pushBasculer() {
      if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) { this.pushMotif = window.isSecureContext === false ? 'les notifications demandent une connexion sécurisée (https)' : 'ce navigateur ne sait pas recevoir de notifications'; this.rendre(); return; }
      try {
        const reg = await navigator.serviceWorker.register('sw.js', { scope: './' }); await navigator.serviceWorker.ready;
        const deja = await reg.pushManager.getSubscription();
        if (deja) { await fetch(this.api('/push/abonnements'), { method: 'DELETE', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: deja.endpoint }) }).catch(() => {}); await deja.unsubscribe().catch(() => {}); this.pushEtat = 'possible'; this.dire('Notifications désactivées.'); this.rendre(); return; }
        const perm = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
        if (perm !== 'granted') { this.pushMotif = 'permission refusée'; this.rendre(); return; }
        const c = await (await fetch(this.api('/push/cle'), { credentials: 'same-origin' })).json();
        if (!c || !c.pret) { this.pushMotif = (c && c.motif) || 'le serveur n’est pas prêt'; this.rendre(); return; }
        const b64 = c.cle; const pad = '='.repeat((4 - b64.length % 4) % 4); const t = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/')); const u = new Uint8Array(t.length); for (let i = 0; i < t.length; i++) { u[i] = t.charCodeAt(i); }
        const ab = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: u }); const j = ab.toJSON();
        const cible = this.role === 'franchise' ? this.shop : this.role === 'admin' ? 'admin' : 'c:' + this.moi;
        const r = await fetch(this.api('/push/abonnements'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ shop: cible, endpoint: ab.endpoint, p256dh: j.keys.p256dh, auth: j.keys.auth }) });
        this.pushEtat = r.ok ? 'abonne' : 'possible'; this.pushMotif = r.ok ? '' : 'le serveur a refusé l’abonnement';
        this.dire(r.ok ? 'Notifications activées.' : 'Abonnement refusé.');
      } catch (e) { this.pushMotif = e.message || String(e); }
      this.rendre();
    }
  }

  const instances = new Map();
  window.CockpitVisites = {
    mount(host, opts) {
      if (!host) { return null; }
      const cle = (opts.role || 'consultant') + '|' + (opts.shop || '') + '|' + (opts.vue || '');
      let h = instances.get(cle);
      if (h && h.host === host) { h.rendre(); return h; }
      if (h && !opts.mobile) { h.host = host; host.innerHTML = '<div class="vi desk"></div>'; host.addEventListener('click', e => h.clic(e)); host.addEventListener('input', e => h.saisie(e)); host.addEventListener('change', e => h.change(e)); h.v = opts.vue || h.v; h.p = null; h.rendre(); if (h.D) { h.recharger(); } return h; }
      h = new Hote(host, opts); instances.set(cle, h); return h;
    },
    compresser, Idb,
  };
})();
