/* MétaDonnée — les formulaires de prospects Facebook et Instagram (Meta Lead
 * Ads) de la Page, jusqu'à l'ERP du franchisé (maquette A retenue le
 * 28/10/2026 : un sous-menu en quatre étapes, sous « ERP franchisé ») :
 *
 *   1 · Formulaires     — la connexion Meta du CRM, recopiée, avec ses clés et son
 *                         accès (« Connecter et tester ») ; les formulaires de la Page, ceux qu'on suit
 *                         et leur magasin ;
 *   2 · Mapping ERP     — chaque question dans un champ de l'ERP, ce qui partira,
 *                         la traduction des réponses en codes, le magasin par code postal ;
 *   3 · Suivi des leads — ce que l'ERP dit du traitement : vu, contacté, bureau
 *                         connecté ; les retards, les leads à attribuer ou à corriger ;
 *   4 · Alertes SMS     — le SMS au gérant à chaque lead, la relance, l'e-mail au consultant.
 *
 *   CockpitMetaDonnee.mount(host, { vue, apiBase, notify, go, onResume })
 *
 * Un seul module pour les quatre écrans : l'état (formulaire choisi, brouillon
 * du mapping) survit au passage d'un écran à l'autre. Données : GET /metadonnee,
 * /metadonnee/leads, /metadonnee/leads/{id} ; écritures : src/metadonnee.php.
 * Les badges et pastilles vivent dans des colonnes de largeur fixe.
 */
(function () {
  'use strict';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const VUES = ['mdFormulaires', 'mdMapping', 'mdLeads', 'mdSms'];
  const REGLE = { coupe: 'coupé en deux', email: 'adresse réparée', tel: 'format +32', cp: 'code postal', nombre: 'nombre · tranche', code: 'table ↓',
    date: 'date si lisible', note: 'dans la note', accord: 'coché exigé', texte: 'tel quel' };
  const REGLE_AIDE = { coupe: 'Le premier mot devient le prénom, le reste le nom', email: 'Espaces, majuscules et « mailto: » retirés ; une adresse illisible part dans la note',
    tel: 'Mis au format international (+32 pour un numéro belge) ; illisible, il manque et l’ERP ne reçoit rien', cp: 'Les chiffres seuls ; il décide du magasin d’un formulaire « par code postal »',
    nombre: 'Un nombre ; une tranche (« 26 à 50 ») devient son milieu', code: 'Chaque réponse traduite en code : « Correspondance des réponses »',
    date: 'Une date lisible (« 4 novembre », 12/11/2026) ; sinon la réponse part dans la note', note: 'La réponse part dans la note du lead',
    accord: 'Case non cochée : rien ne part (RGPD)', texte: 'La réponse telle quelle' };
  const ETAT = { attente: ['en attente', 'gr'], a_attribuer: ['à attribuer', 'att'], a_envoyer: ['à envoyer', 'bl'], rejete: ['à corriger', 'ko'], erreur: ['erreur ERP', 'ko'] };
  const STATUT = { nouveau: ['pas vu', 'bl'], vu: ['vu · à rappeler', 'abr'], contacte: ['contacté', 'ok'], bureau_cree: ['bureau créé', 'ok'], bureau_connecte: ['bureau connecté', 'vert'], perdu: ['perdu', 'gr'] };
  const FILTRES = [['tous', 'Tous'], ['pas_vus', 'Pas vus'], ['retard', 'En retard'], ['a_attribuer', 'À attribuer'], ['a_corriger', 'À corriger'], ['attente', 'En attente'],
    ['contactes', 'Contactés'], ['connectes', 'Bureaux connectés'], ['perdus', 'Perdus']];
  const JOURNAL_PILL = { webhook: 'bl', lead: 'bl', rattrapage: 'gr', relecture: 'gr', envoi: 'ok', sms: 'bl', relance: 'att', escalade: 'att', erreur: 'ko', reglage: 'gr', meta: 'gr' };
  // GSM-7 : hors de ces caractères, le SMS passe en UCS-2 (70 caractères au lieu de 160).
  const GSM = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
  const GSM_EXT = '^{}\\[~]|€';
  function coutSms(t) {
    let n = 0, uni = false;
    for (const ch of String(t || '')) { if (GSM.includes(ch)) n++; else if (GSM_EXT.includes(ch)) n += 2; else uni = true; }
    if (uni) { const l = [...String(t)].length; return { car: l, max: 70, sms: l <= 70 ? 1 : Math.ceil(l / 67), uni: true }; }
    return { car: n, max: 160, sms: n <= 160 ? 1 : Math.ceil(n / 153), uni: false };
  }

  /* --- dates --------------------------------------------------------------- */
  const ts = s => { if (!s) return null; const d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? null : d.getTime(); };
  const hm = s => { const t = ts(s); if (t == null) return ''; const d = new Date(t); return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); };
  const jm = s => { const t = ts(s); if (t == null) return ''; const d = new Date(t); return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0'); };
  function duree(ms) {
    const m = Math.max(0, Math.round(ms / 60000));
    if (m < 60) return m + ' min';
    if (m < 48 * 60) { const h = Math.floor(m / 60), r = m % 60; return h + ' h' + (r >= 10 ? ' ' + String(r).padStart(2, '0') : ''); }
    return Math.floor(m / 1440) + ' j';
  }

  const CSS = `
.md{display:flex;flex-direction:column;gap:16px;font-family:var(--font-ui);color:var(--color-text)}
.md .md-steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.md .md-step{display:flex;gap:11px;align-items:center;background:var(--color-surface);border-radius:14px;padding:11px 14px;border:1.5px solid transparent;cursor:pointer;text-align:left;font-family:var(--font-ui)}
.md .md-step:hover{border-color:var(--color-border-secondary)}
.md .md-step .n{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;background:#EDE7E0;color:#666;flex:0 0 auto}
.md .md-step.fait .n{background:#2d7a3e;color:#fff}
.md .md-step.on{border-color:var(--color-primary)}
.md .md-step.on .n{background:var(--color-primary);color:#fff}
.md .md-step b{display:block;font-size:13.5px;color:var(--color-text)}
.md .md-step small{display:block;font-size:11px;color:var(--color-text-muted);margin-top:2px}
.md .md-etat{display:flex;flex-wrap:wrap;gap:6px 16px;align-items:center;font-size:12px;color:var(--color-text-muted);background:var(--color-surface);border-radius:12px;padding:9px 14px}
.md .md-etat span{display:inline-flex;gap:6px;align-items:center}
.md .md-etat b{color:var(--color-text);font-weight:600}
.md .feu{display:inline-block;width:8px;height:8px;border-radius:50%;background:#2d7a3e;flex:0 0 auto}
.md .feu.o{background:#D97706}.md .feu.r{background:#C0182B}.md .feu.g{background:#bbb}
.md .carte{background:var(--color-surface);border-radius:14px;padding:16px 18px;min-width:0}
.md .carte h3{margin:0 0 12px;font:400 19px/1.15 var(--font-display);display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}
.md .carte h3 small{font:500 12px var(--font-ui);color:var(--color-text-muted)}
.md .carte h3 .d{margin-left:auto;font:600 12px var(--font-ui);display:inline-flex;gap:8px;align-items:center}
.md .grille{display:grid;gap:16px;align-items:start}
.md .lab{font-weight:700;font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:var(--color-text-muted);margin:0 0 8px}
.md .mini{font-size:11.5px;color:var(--color-text-muted);line-height:1.5}
.md .mini b{color:var(--color-text)}
.md .alerte{font-size:12px;line-height:1.5;padding:8px 11px;border-radius:9px;background:#FFF4E0;color:#7a4b00;margin:8px 0}
.md .alerte.ko{background:#FBEEEF;color:#8a1b1b}
.md .alerte.ok{background:#E7F2EA;color:#1A6629}
.md .btn{display:inline-flex;align-items:center;gap:6px;border:none;background:var(--color-primary);color:#fff;border-radius:999px;padding:8px 14px;font:600 12.5px var(--font-ui);cursor:pointer;white-space:nowrap}
.md .btn.s{background:#fff;color:var(--color-text);border:.5px solid var(--color-border-secondary)}
.md .btn.xs{padding:5px 10px;font-size:11.5px}
.md .btn[disabled]{opacity:.45;cursor:default}
.md .lien{border:none;background:none;padding:0;color:var(--color-primary);font:600 12px var(--font-ui);cursor:pointer;white-space:nowrap}
.md .pill{display:inline-block;box-sizing:border-box;text-align:center;border-radius:999px;padding:3px 6px;font:700 10.5px var(--font-ui);background:#EDE7E0;color:#444;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:0 0 auto;vertical-align:middle}
.md .pill.ok{background:#E7F2EA;color:#1A6629}.md .pill.ko{background:#FBEEEF;color:#9B2C2C}.md .pill.att{background:#FFF4E0;color:#9A5B00}
.md .pill.bl{background:#EEF3FA;color:#2E5E8C}.md .pill.ru{background:#EFE3DE;color:#5a1320}.md .pill.gr{background:#F1ECE4;color:#8A847C}
.md .pill.plein{background:#C0182B;color:#fff}.md .pill.vert{background:#2d7a3e;color:#fff}.md .pill.abr{background:#F2C9A0;color:#6b4420}
.md .vide{display:inline-block;flex:0 0 auto;text-align:center;color:#CFC8BC}
.md .pf{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;width:20px;height:20px;border-radius:5px;font:700 10px var(--font-ui);color:#fff;vertical-align:middle}
.md .pf.fb{background:#1877F2}.md .pf.ig{background:linear-gradient(45deg,#F58529,#DD2A7B 55%,#8134AF)}.md .pf.no{background:#E3DCD3;color:#8A847C}
.md table.t{width:100%;border-collapse:collapse}
.md .t th{font-weight:700;font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:var(--color-text-muted);text-align:left;padding:6px 8px;border-bottom:.5px solid var(--color-border-secondary);white-space:nowrap}
.md .t td{padding:8px;border-bottom:.5px solid var(--color-border-tertiary);vertical-align:middle;font-size:12.5px}
.md .t td small{display:block;color:var(--color-text-muted);font-size:11px;margin-top:2px}
.md .t tr.on>td{background:#FBF4F0}
.md .t tr.on>td:first-child{box-shadow:inset 3px 0 0 var(--color-primary)}
.md .t tr.neuf>td{background:#FFF8EC}
.md .t tr.gp>td{background:#F5F1EB;font-weight:700;font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--color-text-muted);padding:7px 8px}
.md .t tr.clic{cursor:pointer}
.md .t tr.clic:hover>td{background:#FBF8F4}
.md .t tr.det>td{background:#FBF8F4;padding:12px 14px}
.md code{font-size:11px;background:#EDE7E0;border-radius:5px;padding:1px 5px;font-family:ui-monospace,Menlo,monospace}
.md .fl{color:#B9AFA3;font-size:15px;text-align:center;width:18px}
.md select,.md input[type=text],.md input[type=number],.md input[type=tel],.md input[type=email],.md input[type=password],.md textarea{box-sizing:border-box;border:.5px solid var(--color-border-secondary);background:#fff;border-radius:8px;padding:6px 9px;font:500 12.5px var(--font-ui);color:var(--color-text)}
.md select.manque{border-color:#D97706;background:#FFF8EC;color:#9A5B00}
.md select.change{border-color:var(--color-primary);box-shadow:0 0 0 1px var(--color-primary)}
.md textarea{width:100%;min-height:76px;resize:vertical;line-height:1.45}
.md .code{background:#2A2523;color:#EDE6DE;border-radius:10px;padding:12px 14px;font:11px/1.55 ui-monospace,Menlo,monospace;white-space:pre-wrap;word-break:break-word;max-height:420px;overflow:auto}
.md .form{display:flex;gap:10px;align-items:center;padding:9px 10px;border-radius:10px;border:.5px solid var(--color-border-tertiary);margin-bottom:6px;background:#fff;cursor:pointer;width:100%;text-align:left;font-family:var(--font-ui)}
.md .form.on{border-color:var(--color-primary);box-shadow:0 0 0 1.5px var(--color-primary)}
.md .form.off{background:#FAF8F5;opacity:.75}
.md .form .c{flex:1;min-width:0}
.md .form .c b{display:block;font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--color-text)}
.md .form .c small{display:flex;gap:8px;align-items:center;font-size:11px;color:var(--color-text-muted);margin-top:5px}
.md .form .nb{width:36px;flex:0 0 auto;text-align:right;font:400 18px/1 var(--font-display);color:var(--color-text)}
.md .tog{position:relative;display:inline-block;width:34px;height:19px;border-radius:999px;background:#CFC8BC;border:none;cursor:pointer;flex:0 0 auto;vertical-align:middle;padding:0}
.md .tog::after{content:'';position:absolute;top:2px;left:2px;width:15px;height:15px;border-radius:50%;background:#fff;transition:left .15s}
.md .tog.on{background:#2d7a3e}.md .tog.on::after{left:17px}
.md .kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px}
.md .kpi{background:var(--color-surface);border-radius:14px;padding:13px 15px}
.md .kpi .k{font-weight:700;font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:var(--color-text-muted)}
.md .kpi .v{font:400 30px/1.1 var(--font-display);margin:6px 0 4px}
.md .kpi .v.ko{color:#C0182B}.md .kpi .v.ok{color:#2d7a3e}
.md .kpi .s{font-size:11.5px;color:var(--color-text-muted)}
.md .chips{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.md .chip{border:.5px solid var(--color-border-secondary);background:#fff;border-radius:999px;padding:6px 12px;font:500 12px var(--font-ui);display:inline-flex;gap:6px;align-items:center;cursor:pointer;color:var(--color-text)}
.md .chip.on{background:var(--color-primary);color:#fff;border-color:var(--color-primary);font-weight:600}
.md .chip em{font-style:normal;font-weight:700;font-size:11px;opacity:.75}
.md .suite{display:inline-flex;align-items:center;flex:0 0 auto}
.md .suite i{display:block;width:10px;height:10px;border-radius:50%;background:#E3DCD3;flex:0 0 auto}
.md .suite s{display:block;width:9px;height:2px;background:#E3DCD3;flex:0 0 auto}
.md .suite i.f,.md .suite s.f{background:#2d7a3e}
.md .suite i.c{background:#fff;box-shadow:inset 0 0 0 2.5px var(--color-primary)}
.md .suite i.r{background:#C0182B}.md .suite i.x{background:#9B8F84}
.md .journal{display:flex;gap:10px;padding:7px 0;border-bottom:.5px dashed var(--color-border-tertiary);font-size:12px;align-items:center}
.md .journal:last-child{border-bottom:none}
.md .journal .d{width:44px;flex:0 0 auto;font-weight:600;color:var(--color-text-muted)}
.md .journal .t{flex:1;min-width:0;line-height:1.4}
.md .regle{display:grid;grid-template-columns:150px 1fr;gap:6px 12px;font-size:12.5px;padding:8px 0;border-bottom:.5px dashed var(--color-border-tertiary);align-items:center}
.md .regle:last-child{border-bottom:none}
.md .regle>b{font-size:12px}
.md .regle .v{display:flex;flex-wrap:wrap;gap:6px 8px;align-items:center}
.md .tel{background:#F3EEE7;border-radius:16px;padding:12px;border:.5px solid var(--color-border-tertiary)}
.md .tel .de{font-size:10.5px;color:var(--color-text-muted);text-align:center;margin-bottom:8px}
.md .bulle{background:#fff;border-radius:14px 14px 14px 4px;padding:9px 11px;font-size:12px;line-height:1.45;white-space:pre-wrap;word-break:break-word}
.md .cpt{font-size:11px;color:var(--color-text-muted);margin-top:6px;display:flex;justify-content:space-between;gap:8px}
.md .cpt b.ko{color:#C0182B}
.md .kv{display:grid;grid-template-columns:minmax(0,1.1fr) 16px minmax(0,1fr);gap:5px 8px;font-size:12px;align-items:baseline}
.md .kv .m{color:var(--color-text-muted);overflow:hidden;text-overflow:ellipsis}
.md .kv .a{color:#B9AFA3;text-align:center}
.md .kv .e{font-weight:600;overflow:hidden;text-overflow:ellipsis}
.md .vides{padding:26px 10px;text-align:center;font-size:12.5px;color:var(--color-text-muted)}
@media (max-width:1250px){.md .kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.md .md-steps{grid-template-columns:repeat(2,minmax(0,1fr))}}
`;
  function styles() { if (document.getElementById('md-css')) return; const st = document.createElement('style'); st.id = 'md-css'; st.textContent = CSS; document.head.appendChild(st); }

  /* --- accès API ------------------------------------------------------------ */
  function lire(base, path) {
    return fetch(base + path, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.json().catch(() => ({})).then(j => r.ok ? j : Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status)))));
  }
  function ecrire(base, method, path, body) {
    return fetch(base + path, { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) })
      .then(r => r.json().catch(() => ({})).then(j => r.ok ? j : Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status)))));
  }

  const pill = (cls, txt, w, tip) => `<span class="pill ${cls}" style="width:${w}px"${tip ? ` title="${esc(tip)}"` : ''}>${esc(txt)}</span>`;
  const vide = w => `<span class="vide" style="width:${w}px">—</span>`;
  const pf = p => p === 'fb' ? '<span class="pf fb" title="Facebook">f</span>' : p === 'ig' ? '<span class="pf ig" title="Instagram">IG</span>' : '<span class="pf no" title="Plateforme inconnue">·</span>';
  const copie = o => JSON.parse(JSON.stringify(o == null ? null : o));

  class MD {
    constructor(host, opts) {
      this.host = host;
      this.opts = Object.assign({}, opts || {});
      this.base = this.opts.apiBase || ((window.COCKPIT_API_BASE) || (location.pathname.replace(/[^/]*$/, '') + 'api/cockpit'));
      this.el = document.createElement('div'); this.el.className = 'md';
      this.s = { vue: this.opts.vue || 'mdFormulaires', data: null, err: '', chargement: false, lu: 0, busy: '',
        formSel: null, br: {}, apercu: {}, cpBr: null, erpBr: null, smsBr: null, metaBr: null, jeton: '', telTest: '', magBr: {},
        leads: null, leadsErr: '', filtre: 'tous', shopF: '', formF: '', q: '', ouvert: null, detail: {}, attrib: {} };
      this.el.addEventListener('click', e => this.onClick(e));
      this.el.addEventListener('change', e => this.onChange(e));
      this.el.addEventListener('input', e => this.onInput(e));
    }
    setVue(v) {
      if (!VUES.includes(v) || v === this.s.vue) return;
      this.s.vue = v;
      if (v === 'mdLeads') this.chargerLeads();
      if (v === 'mdMapping') this.apercuPlus();
    }
    mount(host) {
      if (host && this.host !== host) this.host = host;
      if (this.host && this.el.parentNode !== this.host) this.host.appendChild(this.el);
      if (!this.s.chargement && (!this.s.data || Date.now() - this.s.lu > 60000)) this.charger();
      this.render();
    }
    notify(m) { if (this.opts.notify) this.opts.notify(m); }
    charger() {
      const s = this.s; s.chargement = true;
      return lire(this.base, '/metadonnee').then(d => {
        s.data = d; s.err = ''; s.lu = Date.now();
        const suivis = (d.formulaires || []).filter(f => f.suivi);
        if (!s.formSel || !(d.formulaires || []).some(f => f.id === s.formSel)) s.formSel = (suivis[0] || (d.formulaires || [])[0] || {}).id || null;
        if (this.opts.onResume) this.opts.onResume({ retard: (d.resume || {}).retard || 0, mapping: suivis.filter(f => !f.mappingLe || f.champsNeufs).length });
        if (s.vue === 'mdLeads' && !s.leads) this.chargerLeads();
        if (s.vue === 'mdMapping') this.apercuPlus();
      }).catch(e => { s.err = e.message; }).finally(() => { s.chargement = false; this.render(); });
    }
    chargerLeads() {
      const s = this.s;
      const q = new URLSearchParams({ filtre: s.filtre, shop: s.shopF, form: s.formF, q: s.q });
      return lire(this.base, '/metadonnee/leads?' + q).then(d => { s.leads = d; s.leadsErr = ''; })
        .catch(e => { s.leadsErr = e.message; }).finally(() => this.render());
    }
    chargerDetail(id) {
      return lire(this.base, '/metadonnee/leads/' + id).then(d => { this.s.detail[id] = d; }).catch(e => { this.s.detail[id] = { err: e.message }; }).finally(() => this.render());
    }
    act(promise, okMsg, apres) {
      this.s.busy = 'x'; this.render();
      return promise.then(r => { if (okMsg) this.notify(typeof okMsg === 'function' ? okMsg(r) : okMsg); return apres ? apres(r) : this.charger(); })
        .catch(e => this.notify('⚠ ' + e.message)).finally(() => { this.s.busy = ''; this.render(); });
    }

    /* --- lectures dérivées ------------------------------------------------------ */
    d() { return this.s.data || { formulaires: [], magasins: [], champs: [], besoins: {}, reglages: { erp: {}, sms: {}, meta: {}, cp: [] }, resume: {}, journal: [], statut: { meta: {}, erp: {}, sms: {}, cron: {} } }; }
    maintenant() { return ts(this.d().maintenant) || Date.now(); }
    ilYa(s) { const t = ts(s); if (t == null) return '—'; const ms = this.maintenant() - t; if (ms < 60000) return 'à l’instant'; if (ms < 3600000) return 'il y a ' + duree(ms); const auj = new Date(this.maintenant()).toDateString(); const d = new Date(t);
      if (d.toDateString() === auj) return hm(s); const hier = new Date(this.maintenant() - 86400000).toDateString(); return (d.toDateString() === hier ? 'hier ' : jm(s) + ' ') + hm(s); }
    form(id) { return this.d().formulaires.find(f => f.id === id) || null; }
    mag(id) { return this.d().magasins.find(m => m.id === String(id)) || null; }
    nomMag(id) { const m = this.mag(id); return m ? m.nom : (id ? '#' + id : '—'); }
    champ(cle) { return this.d().champs.find(c => c.cle === cle) || null; }
    brouillon(f) {
      let b = this.s.br[f.id];
      if (!b) b = this.s.br[f.id] = { mapping: copie(f.mapping || f.mappingSuggere) || {}, valeurs: copie(f.valeurs) || {} };
      return b;
    }
    sale(f) { const b = this.s.br[f.id]; if (!f.mappingLe) return true; if (!b) return false;
      return JSON.stringify(b.mapping) !== JSON.stringify(f.mapping || {}) || JSON.stringify(b.valeurs) !== JSON.stringify(f.valeurs || {}); }
    etatForm(f) {
      if (f.statutMeta && f.statutMeta !== 'ACTIVE' && !f.suivi) return ['gr', 'archivé chez Meta'];
      if (!f.suivi) return ['gr', 'non suivi'];
      if (!f.mappingLe) return ['att', 'mapping à faire'];
      if (f.champsNeufs) return ['att', f.champsNeufs + ' champ' + (f.champsNeufs > 1 ? 's' : '') + ' neuf' + (f.champsNeufs > 1 ? 's' : '')];
      if (f.affectation === 'magasin' && !f.shopId) return ['att', 'sans magasin'];
      return ['ok', '✓ suivi'];
    }

    /* --- rendu ------------------------------------------------------------------- */
    render() {
      if (!this.el) return;
      const a = document.activeElement;
      const garde = a && this.el.contains(a) && a.dataset && a.dataset.f ? { f: a.dataset.f, d: a.selectionStart, e: a.selectionEnd } : null;
      this.el.innerHTML = this.html();
      if (garde) {
        const n = this.el.querySelector('[data-f="' + garde.f + '"]');
        if (n) { n.focus(); try { if (garde.d != null) n.setSelectionRange(garde.d, garde.e); } catch (e) { /* champ sans sélection */ } }
      }
    }
    html() {
      const s = this.s;
      if (!s.data) {
        return s.err ? `<div class="carte"><div class="alerte ko">MétaDonnée ne répond pas : ${esc(s.err)}</div><button class="btn s xs" data-a="recharger">↻ Réessayer</button></div>`
          : '<div class="carte vides">Lecture des formulaires et des leads…</div>';
      }
      const corps = { mdFormulaires: () => this.vueFormulaires(), mdMapping: () => this.vueMapping(), mdLeads: () => this.vueLeads(), mdSms: () => this.vueSms() }[s.vue] || (() => '');
      return this.etapes() + this.etat() + corps();
    }
    etapes() {
      const d = this.d(), f = d.formulaires, suivis = f.filter(x => x.suivi), r = d.resume || {}, reg = d.reglages;
      const mappes = suivis.filter(x => x.mappingLe && !x.champsNeufs).length;
      const smsMags = d.magasins.filter(m => m.sms.on).length;
      const st = [
        ['mdFormulaires', '1 · Formulaires', suivis.length + ' suivi' + (suivis.length > 1 ? 's' : '') + ' sur ' + f.length, suivis.length > 0],
        ['mdMapping', '2 · Mapping ERP', suivis.length ? mappes + ' sur ' + suivis.length + ' prêt' + (mappes > 1 ? 's' : '') + (reg.erp.envoiActif ? ' · envoi auto' : ' · envoi éteint') : 'aucun formulaire suivi', suivis.length > 0 && mappes === suivis.length],
        ['mdLeads', '3 · Suivi des leads', (r.leads || 0) + ' en 30 j · ' + (r.retard || 0) + ' en retard', false],
        ['mdSms', '4 · Alertes SMS', reg.sms.actif ? smsMags + ' magasin' + (smsMags > 1 ? 's' : '') + ' · relance à ' + reg.sms.relanceH + ' h' : 'éteintes', !!reg.sms.actif && smsMags > 0],
      ];
      return `<div class="md-steps">${st.map((x, i) => `<button class="md-step ${x[0] === this.s.vue ? 'on' : (x[3] ? 'fait' : '')}" data-a="vue" data-v="${x[0]}">
        <span class="n">${x[0] !== this.s.vue && x[3] ? '✓' : i + 1}</span><span><b>${esc(x[1])}</b><small>${esc(x[2])}</small></span></button>`).join('')}</div>`;
    }
    etat() {
      const st = this.d().statut, reg = this.d().reglages;
      const cron = st.cron || {};
      const vieux = cron.dernier ? (this.maintenant() - ts(cron.dernier)) > 5 * 60000 : true;
      return `<div class="md-etat">
        <span><i class="feu ${st.meta.configure ? (st.meta.connexion && st.meta.connexion.ok ? '' : 'o') : 'r'}"></i><b>Meta</b> ${st.meta.configure
          ? esc(st.meta.pageNom ? '« ' + st.meta.pageNom + ' »' : 'Page ' + st.meta.pageId) + (st.meta.mode === 'webhook' ? ' · webhook' : ' · relève ' + esc(reg.meta.releveMin || 2) + ' min')
          : '⚠ connexion à régler (étape 1)'}</span>
        <span><i class="feu ${st.erp.configure ? (reg.erp.envoiActif ? '' : 'o') : 'r'}"></i><b>ERP</b> ${st.erp.configure ? esc((st.erp.base || '').replace(/^https?:\/\//, '')) + (reg.erp.envoiActif ? ' · envoi automatique' : ' · envoi éteint') : '⚠ compte API du panel non configuré'}</span>
        <span><i class="feu ${st.sms.configure ? (reg.sms.actif ? '' : 'o') : 'r'}"></i><b>SMS</b> ${st.sms.configure ? (reg.sms.actif ? 'SMSAPI · allumés' : 'SMSAPI · éteints') : '⚠ jeton SMSAPI absent'}</span>
        <span><i class="feu ${vieux ? 'o' : ''}"></i><b>Horloge</b> ${cron.dernier ? 'passage ' + esc(this.ilYa(cron.dernier)) : 'jamais passée'}</span>
        <span style="margin-left:auto;display:inline-flex;gap:14px"><button class="lien" data-a="sonde" ${this.s.busy ? 'disabled' : ''} title="Chaque maillon vérifié en lecture seule : Meta, l’ERP, SMSAPI (aucun SMS ne part), le SMTP, l’horloge">🔍 Tester les connexions</button>
          <button class="lien" data-a="tick" ${this.s.busy ? 'disabled' : ''}>↻ Lancer le cycle maintenant</button></span>
      </div>${this.s.sonde ? this.carteSonde() : ''}`;
    }
    carteSonde() {
      const r = this.s.sonde;
      if (r.err) return `<div class="carte"><div class="alerte ko">Sonde impossible : ${esc(r.err)}</div></div>`;
      const sym = { ok: ['ok', '✓'], ko: ['plein', '✕'], att: ['att', '!'] };
      return `<div class="carte"><h3>Les connexions<small>testées ${esc(this.ilYa(r.at))} · lecture seule</small><span class="d"><button class="lien" data-a="sondeFermer">Fermer ✕</button></span></h3>
        <div class="grille" style="grid-template-columns:repeat(4,minmax(0,1fr))">${r.blocs.map(b => `<div><div class="lab">${esc(b.nom)}</div>
          ${b.etapes.map(e => `<div class="journal">${pill((sym[e[0]] || sym.att)[0], (sym[e[0]] || sym.att)[1], 24)}<span class="t"><b style="font-size:12px">${esc(e[1])}</b><br><span class="mini">${esc(e[2])}</span></span></div>`).join('')}</div>`).join('')}</div></div>`;
    }

    /* --- 1 · formulaires -------------------------------------------------------------- */
    vueFormulaires() {
      const d = this.d();
      const mags = d.magasins;
      const optMag = (sel, cp) => `<option value="">— aucun magasin —</option>${mags.map(m => `<option value="${esc(m.id)}"${String(sel) === m.id && !cp ? ' selected' : ''}>${esc(m.nom)}</option>`).join('')}<option value="@cp"${cp ? ' selected' : ''}>Par code postal</option>`;
      const lignes = d.formulaires.map(f => {
        const [cls, txt] = this.etatForm(f);
        return `<tr${f.suivi ? '' : ' style="opacity:.8"'}>
          <td style="width:46px"><button class="tog ${f.suivi ? 'on' : ''}" data-a="suivre" data-v="${esc(f.id)}" title="${f.suivi ? 'Ne plus suivre : ses nouveaux leads ne seront plus repris' : 'Suivre : ses leads iront dans l’ERP du magasin'}"></button></td>
          <td><b>${esc(f.nom)}</b><small>${esc(f.page || 'page inconnue')}${f.locale ? ' · ' + esc(f.locale.slice(0, 2).toUpperCase()) : ''}</small></td>
          <td style="width:58px"><span style="display:inline-flex;gap:3px">${f.fb ? pf('fb') : ''}${f.ig ? pf('ig') : ''}${!f.fb && !f.ig ? vide(40) : ''}</span></td>
          <td style="width:74px;text-align:right"><b style="font:400 18px var(--font-display)">${f.leads30}</b>${f.ignores && !f.suivi ? `<small title="Leads arrivés pendant que le formulaire n’était pas suivi : comptés, pas repris">${f.ignores} non repris</small>` : ''}</td>
          <td style="width:74px;text-align:right" title="Leads chez Meta (tout l’historique)">${f.leadsMeta != null ? esc(f.leadsMeta) : '—'}</td>
          <td style="width:96px">${f.dernierLead ? esc(this.ilYa(f.dernierLead)) : '—'}</td>
          <td style="width:180px"><select data-c="formMag" data-v="${esc(f.id)}" style="width:170px">${optMag(f.shopId, f.affectation === 'cp')}</select></td>
          <td style="width:122px">${pill(cls, txt, 116)}</td>
          <td style="width:150px;text-align:right">${f.suivi ? `<button class="lien" data-a="versMapping" data-v="${esc(f.id)}">Mapping →</button>` : ''}
            ${f.suivi && !f.leads30 ? `<br><button class="lien" data-a="historique" data-v="${esc(f.id)}" title="Les leads des 30 derniers jours chez Meta (Meta en garde 90)">↻ Récupérer 30 jours</button>` : ''}</td>
        </tr>`;
      }).join('');
      const suivis = d.formulaires.filter(f => f.suivi).length;
      return `<div class="grille" style="grid-template-columns:minmax(0,1fr) 380px">
        <div class="carte"><h3>Formulaires<small>${d.formulaires.length} chez Meta · ${suivis} suivi${suivis > 1 ? 's' : ''} · leads sur 30 jours</small>
          <span class="d"><button class="btn s xs" data-a="relire" ${this.s.busy ? 'disabled' : ''}>↻ Relire chez Meta</button></span></h3>
          ${d.formulaires.length ? `<table class="t"><thead><tr><th>Suivre</th><th>Formulaire</th><th>Où</th><th style="text-align:right">Leads</th><th style="text-align:right">Chez Meta</th><th>Dernier</th><th>Magasin</th><th>État</th><th></th></tr></thead><tbody>${lignes}</tbody></table>`
            : `<div class="vides">Aucun formulaire lu. ${d.statut.meta.configure ? '« Relire chez Meta » lit ceux de la Page.' : 'Réglez d’abord la connexion Meta (à droite), comme dans le CRM, puis « Connecter et tester ».'}</div>`}
        </div>
        <div class="grille">
          ${this.carteMeta()}
          <div class="carte"><h3>Sans magasin<small>formulaire « par code postal »</small></h3>
            <div class="mini">Un formulaire de la marque couvre plusieurs magasins : le code postal du bureau choisit, d’après les plages réglées à l’étape 2. Sans plage, le lead attend « à attribuer ».</div></div>
        </div>
      </div>`;
    }

    /**
     * La connexion Meta : par défaut les clés et l'accès du CRM, lus dans sa base (rien à saisir,
     * rien n'y est écrit) ; « Clés propres » : les réglages saisis ici. Le « Connecter et tester » du CRM, recopié.
     */
    carteMeta() {
      const s = this.s, m = this.d().statut.meta || {};
      const crm = m.source !== 'cockpit';
      const b = s.metaBr || (s.metaBr = { appId: crm ? '' : m.appId || '', pageId: crm ? '' : m.pageId || '', secret: '', jeton: '', rappel: m.rappelSaisi ? m.rappel : '' });
      const sale = b.appId !== (m.appId || '') || b.pageId !== (m.pageId || '') || !!b.secret || !!b.jeton || b.rappel !== (m.rappelSaisi ? m.rappel : '');
      const cx = m.connexion;
      const champ = (k, lib, type, ph) => `<div class="regle"><b>${lib}</b><span class="v"><input type="${type}" data-f="meta-${k}" data-i="meta" data-k="${k}" value="${esc(b[k])}" placeholder="${esc(ph)}" autocomplete="off" style="width:100%"></span></div>`;
      const lu = (lib, v, la) => `<div class="regle"><b>${lib}</b><span class="v">${la ? esc(v) : pill('ko', 'absent du CRM', 104)}</span></div>`;
      const mode = !m.configure ? '' : m.mode === 'webhook'
        ? pill('ok', 'webhook au Cockpit', 150, 'L’application envoie chaque lead ici, à la seconde ; la relève horaire reste en filet')
        : pill('att', 'relève toutes les ' + (this.d().reglages.meta.releveMin || 2) + ' min', 150, crm ? 'L’application du CRM garde son webhook : le Cockpit relit ses formulaires' : m.rappelApp ? 'Le webhook de l’application va à ' + m.rappelApp + ' (le CRM) : le Cockpit ne le détourne pas, il relit ses formulaires' : 'Pas de webhook vers le Cockpit : il relit ses formulaires');
      const etapes = cx && cx.etapes && cx.etapes.length ? `<div style="margin-top:12px">${cx.etapes.map(e => `<div class="journal">${pill(e.ok ? 'ok' : 'plein', e.ok ? '✓' : '✕', 24)}<span class="t"><b style="font-size:12px">${esc(e.titre)}</b><br><span class="mini">${esc(e.detail)}</span></span></div>`).join('')}
        <div class="mini" style="margin-top:4px">Testée ${esc(this.ilYa(cx.le))}</div></div>` : '';
      const source = `<div style="display:flex;gap:6px;margin-bottom:8px">${[['crm', 'Clés du CRM', 'Les clés et l’accès du CRM (Paramètres → Intégrations → Formulaires Facebook / Instagram), lus dans sa base : un jeton régénéré dans le CRM vaut aussitôt ici'],
        ['cockpit', 'Clés propres', 'Une autre application Meta, saisie ici (pour un webhook à la seconde)']]
        .map(([k, l, t]) => (k === 'crm') === crm
          ? `<button class="btn xs" title="${esc(t)}" disabled style="opacity:1">${l}</button>`
          : `<button class="btn s xs" data-a="metaSource" data-v="${k}" title="${esc(t)}" ${s.busy ? 'disabled' : ''}>${l}</button>`).join('')}</div>`;
      const boutons = (enregistrer) => `<div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
          ${enregistrer ? `<button class="btn s xs" data-a="metaEnregistrer" ${sale && !s.busy ? '' : 'disabled'}>Enregistrer</button>` : ''}
          <button class="btn xs" data-a="metaConnecter" ${s.busy ? 'disabled' : ''} title="Le jeton (lu par Meta, échangé contre celui de la Page), la Page, ses formulaires, l’abonnement aux leads, l’adresse de rappel — sans jamais détourner celle du CRM">🔌 Connecter et tester</button>
        </div>`;
      if (crm) {
        return `<div class="carte"><h3>Connexion Meta<small>${m.pageNom ? '« ' + esc(m.pageNom) + ' »' : 'les clés du CRM'}</small><span class="d">${mode}</span></h3>
        ${source}
        ${!m.crm ? `<div class="mini">${pill('ko', 'pas de CRM', 80)} Aucun réglage du CRM dans cette base : passez en « Clés propres ».</div>`
          : `${lu('ID de l’application', m.appId, !!m.appId)}
        ${lu('Clé secrète', '•••••• celle du CRM', m.secret)}
        ${lu('ID de la Page', m.pageId, !!m.pageId)}
        ${lu('Jeton', '•••••• celui du CRM', m.jeton)}
        ${m.crm.le ? `<div class="mini" style="margin-top:4px">Publiées par le CRM ${esc(this.ilYa(m.crm.le))}</div>` : ''}`}
        ${boutons(false)}${etapes}</div>`;
      }
      return `<div class="carte"><h3>Connexion Meta<small>${m.pageNom ? '« ' + esc(m.pageNom) + ' »' : 'clés propres'}</small><span class="d">${mode}</span></h3>
        ${source}
        ${champ('appId', 'ID de l’application', 'text', '1234567890')}
        ${champ('secret', 'Clé secrète', 'password', m.secret ? '•••••• enregistrée' : 'clé secrète de l’application')}
        ${champ('pageId', 'ID de la Page', 'text', '1234567890')}
        ${champ('jeton', 'Jeton', 'password', m.jeton ? '•••••• enregistré (jeton de la Page)' : 'jeton de l’utilisateur système')}
        ${champ('rappel', 'Adresse de rappel', 'text', m.rappelAuto || 'https://…/api/cockpit/metadonnee/meta/leads')}
        <div class="regle"><b>Jeton de vérification</b><span class="v" style="display:flex;gap:6px;min-width:0"><input type="text" value="${esc(m.verify || '')}" readonly style="flex:1;min-width:0;color:var(--color-text-muted)"><button class="btn s xs" data-a="copier" data-v="${esc(m.verify || '')}">Copier</button></span></div>
        ${boutons(true)}${etapes}</div>`;
    }

    /* --- 2 · mapping ------------------------------------------------------------------- */
    vueMapping() {
      const d = this.d();
      const f = this.form(this.s.formSel);
      const liste = d.formulaires.slice().sort((a, b) => (b.suivi - a.suivi)).map(x => {
        const [cls, txt] = this.etatForm(x);
        return `<button class="form ${x.id === this.s.formSel ? 'on' : ''} ${x.suivi ? '' : 'off'}" data-a="choisir" data-v="${esc(x.id)}">
          <span class="c"><b>${esc(x.nom)}</b><small><span style="width:44px;flex:0 0 auto">${x.fb ? pf('fb') : ''}${x.ig ? ' ' + pf('ig') : ''}</span>${pill(cls, txt, 104)}<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(x.page)}</span></small></span>
          <span class="nb" title="Leads sur 30 jours">${x.leads30}</span></button>`;
      }).join('');
      const gauche = `<div class="grille"><div class="carte"><h3>Formulaires<small>suivis d’abord</small></h3>${liste || '<div class="mini">Aucun formulaire.</div>'}</div>${f ? this.carteApercu(f) : ''}</div>`;
      if (!f) return `<div class="grille" style="grid-template-columns:360px minmax(0,1fr)">${gauche}<div class="carte vides">Choisissez un formulaire.</div></div>`;
      return `<div class="grille" style="grid-template-columns:360px minmax(0,1fr)">${gauche}${this.carteMapping(f)}</div>
        <div class="grille" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(0,.9fr)">${this.carteValeurs(f)}${this.carteAffectation(f)}${this.carteErp()}</div>
        ${this.s.essai ? this.carteEssai() : ''}`;
    }
    carteEssai() {
      const r = this.s.essai;
      if (r.err) return `<div class="carte"><div class="alerte ko">Lead d’essai : ${esc(r.err)}</div></div>`;
      const bloc = (titre, x) => `<div><div class="lab">${esc(titre)}</div><div class="code">${esc(JSON.stringify(x, null, 2))}</div></div>`;
      return `<div class="carte"><h3>Lead d’essai<small>${esc(r.magasin || '')} · les réponses brutes de l’API</small><span class="d"><button class="lien" data-a="essaiFermer">Fermer ✕</button></span></h3>
        <div class="alerte ${r.ok ? 'ok' : 'ko'}">${r.ok ? '✓ Envoyé (' + esc(r.envoi.code) + '), identifiant ' + esc(r.lu.id) + ', statut « ' + esc(r.lu.statut) + ' » lu « ' + esc(r.lu.statutCockpit || 'inconnu') + ' », et retrouvé par la route des statuts. À supprimer dans l’ERP.'
          : '⚠ ' + esc(r.envoi.route) + ' → ' + esc(r.envoi.code || 'injoignable') + (r.lu.id ? ' · identifiant ' + esc(r.lu.id) : ' · aucun identifiant rendu (id, lead_id ou reference attendu)') + (r.relecture ? ' · relu par ' + esc(r.relecture.route) + ' : ' + (r.relecture.trouve ? 'oui' : 'NON') : '')}</div>
        <div class="grille" style="grid-template-columns:repeat(${r.relecture ? 3 : 2},minmax(0,1fr))">${bloc('Envoyé · ' + r.envoi.route, r.payload)}${bloc('Réponse · ' + (r.envoi.code || '—'), r.envoi.corps)}${r.relecture ? bloc('Relu · ' + r.relecture.route + ' · ' + (r.relecture.code || '—'), r.relecture.corps) : ''}</div></div>`;
    }
    carteMapping(f) {
      const b = this.brouillon(f);
      const champs = this.d().champs;
      const neufs = f.mapping ? new Set(f.questions.filter(q => !(q.key in f.mapping) && q.key !== 'consentement').map(q => q.key)) : new Set();
      const places = new Set(Object.values(b.mapping));
      const manquent = champs.filter(c => c.requis && !places.has(c.cle) && !(c.cle === 'contact.first_name' && places.has('contact.full_name')));
      const sale = this.sale(f);
      const lignes = f.questions.map(q => {
        const c = b.mapping[q.key] || '';
        const ch = this.champ(c);
        const change = f.mapping ? (f.mapping[q.key] || '') !== c : false;
        const regle = c === 'ignorer' ? ['gr', 'ne part pas', 'Cette réponse ne part pas'] : ch ? [ch.requis ? 'plein' : (ch.regle === 'code' ? 'ru' : 'bl'), ch.requis ? 'requis · ' + REGLE[ch.regle] : REGLE[ch.regle], REGLE_AIDE[ch.regle]] : ['gr', 'dans la note', 'Sans champ, la réponse part dans la note du lead (« question : réponse »)'];
        return `<tr class="${neufs.has(q.key) ? 'neuf' : ''}">
          <td><b style="font-size:12.5px">${esc(q.label)}</b>${neufs.has(q.key) ? ' ' + pill('att', 'neuve', 50, 'Question ajoutée chez Meta après l’enregistrement du mapping') : ''}<small><code>${esc(q.key)}</code></small></td>
          <td style="max-width:230px;overflow:hidden;text-overflow:ellipsis">${q.exemple != null ? esc(q.exemple) : '<span class="mini">—</span>'}</td>
          <td class="fl">→</td>
          <td style="width:290px"><select data-c="champ" data-v="${esc(q.key)}" class="${change ? 'change' : ''}" style="width:280px">
            <option value=""${c === '' ? ' selected' : ''}>— dans la note —</option>
            ${champs.map(x => `<option value="${esc(x.cle)}"${c === x.cle ? ' selected' : ''}>${esc(x.libelle)}${x.requis ? ' *' : ''} · ${esc(x.cle)}</option>`).join('')}
            <option value="ignorer"${c === 'ignorer' ? ' selected' : ''}>— ignorer —</option></select>
            ${q.suggestion && q.suggestion !== c ? `<small><button class="lien" data-a="suggestion" data-v="${esc(q.key)}" style="font-size:11px">suggestion : ${esc((this.champ(q.suggestion) || {}).libelle || q.suggestion)}</button></small>` : ''}</td>
          <td style="width:130px">${pill(regle[0], regle[1], 124, regle[2])}</td>
        </tr>`;
      }).join('');
      return `<div class="carte"><h3>${esc(f.nom)}<small>${f.questions.length} questions · ${esc(f.page)}</small>
        <span class="d">${f.mappingLe ? `<span class="mini">enregistré ${esc(this.ilYa(f.mappingLe))}${f.mappingPar ? ' par ' + esc(f.mappingPar) : ''}</span>` : pill('att', 'suggestion à enregistrer', 160)}
          ${sale && f.mappingLe ? `<button class="btn s xs" data-a="annulerMapping">Annuler</button>` : ''}
          <button class="btn xs" data-a="enregistrerMapping" ${sale && !this.s.busy ? '' : 'disabled'}>Enregistrer le mapping</button></span></h3>
        ${!f.suivi ? '<div class="alerte">Ce formulaire n’est pas suivi : le mapping se prépare, aucun lead ne partira tant qu’il n’est pas suivi (étape 1).</div>' : ''}
        ${!f.mappingLe && f.attente ? `<div class="alerte">${f.attente} lead${f.attente > 1 ? 's' : ''} attend${f.attente > 1 ? 'ent' : ''} ce mapping pour partir.</div>` : ''}
        ${manquent.length ? `<div class="alerte ko">⚠ ${esc(manquent.map(c => c.libelle).join(', '))} : aucune question ne ${manquent.length > 1 ? 'les' : 'le'} remplit — l’ERP refusera ces leads.</div>` : ''}
        <table class="t"><thead><tr><th>Question du formulaire</th><th>Dernière réponse</th><th></th><th>Champ de l’ERP</th><th>Règle</th></tr></thead><tbody>
        ${lignes}
        <tr class="gp"><td colspan="5">Ajoutés par le cockpit</td></tr>
        <tr><td><b>Magasin</b><small>formulaire ou code postal</small></td><td>${esc(f.affectation === 'cp' ? 'par code postal' : this.nomMag(f.shopId))}</td><td class="fl">→</td><td><code>shop_id</code></td><td>${pill('ru', f.affectation === 'cp' ? 'code postal' : 'formulaire', 124)}</td></tr>
        <tr><td><b>Plateforme, formulaire, campagne</b><small><code>platform · form · campaign</code></small></td><td>Facebook ou Instagram</td><td class="fl">→</td><td><code>source</code></td><td>${vide(124)}</td></tr>
        <tr><td><b>N° du lead chez Meta</b><small><code>id</code></small></td><td>—</td><td class="fl">→</td><td><code>meta_lead_id</code></td><td>${pill('gr', 'jamais 2 fois', 124, 'Clé d’unicité : un lead relu par le rattrapage ne crée pas de doublon dans l’ERP')}</td></tr>
        </tbody></table></div>`;
    }
    carteApercu(f) {
      const a = this.s.apercu[f.id];
      let corps;
      if (!a) corps = '<div class="mini">Lecture…</div>';
      else if (a.err) corps = `<div class="alerte ko">${esc(a.err)}</div>`;
      else if (a.vide) corps = '<div class="mini">Aucun lead encore reçu pour ce formulaire : l’aperçu viendra avec le premier.</div>';
      else corps = `<div class="mini" style="margin-bottom:6px">Le dernier lead (${esc(a.lead.entreprise || '#' + a.lead.id)}, ${esc(this.ilYa(a.lead.recu))}), avec ce mapping${a.magasin ? ' · ' + esc(a.magasin) : ''} :</div>
        <div class="code">${esc(a.route)}\n${esc(JSON.stringify(a.payload, null, 2))}</div>
        ${a.consentement === false ? '<div class="alerte ko">Case de consentement non cochée : ce lead ne partirait pas.</div>' : ''}
        ${a.manques && a.manques.length ? `<div class="alerte ko">Manque : ${esc(a.manques.join(', '))} — l’ERP ne le recevrait pas.</div>` : '<div class="alerte ok">✓ Rien ne manque : ce lead partirait.</div>'}
        ${(a.avertissements || []).map(w => `<div class="alerte">${esc(w)}</div>`).join('')}`;
      return `<div class="carte"><h3>Ce qui partira<small>aperçu, rien n’est envoyé</small></h3>${corps}</div>`;
    }
    carteValeurs(f) {
      const b = this.brouillon(f);
      const besoins = this.d().besoins;
      const qs = f.questions.filter(q => b.mapping[q.key] === 'lead.need');
      if (!qs.length) return `<div class="carte"><h3>Correspondance des réponses</h3><div class="mini">Aucune question n’est placée dans <code>lead.need</code> : rien à traduire.</div></div>`;
      return `<div class="carte"><h3>Correspondance des réponses<small>→ <code>lead.need</code></small></h3>${qs.map(q => {
        const reps = Array.from(new Set((q.options || []).concat(q.vues || []))).filter(Boolean);
        const val = (b.valeurs || {})[q.key] || {};
        return `<div class="lab" style="margin-top:4px">${esc(q.label)}</div><table class="t"><thead><tr><th>Réponse chez Meta</th><th></th><th>Code de l’ERP</th><th style="text-align:right">Leads</th></tr></thead><tbody>
          ${reps.map(r => `<tr><td>${esc(r)}</td><td class="fl">→</td><td style="width:220px"><select data-c="valeur" data-v="${esc(q.key)}" data-r="${esc(r)}" style="width:210px" class="${val[r] ? '' : 'manque'}">
            <option value="">— other + note —</option>${Object.keys(besoins).map(k => `<option value="${esc(k)}"${val[r] === k ? ' selected' : ''}>${esc(k)} · ${esc(besoins[k])}</option>`).join('')}</select></td>
            <td style="width:50px;text-align:right">${esc((q.compte || {})[r] || 0)}</td></tr>`).join('') || '<tr><td colspan="4" class="mini">Aucune réponse vue.</td></tr>'}
        </tbody></table>`;
      }).join('')}<div class="mini" style="margin-top:8px">Une réponse sans code part en <code>other</code>, la réponse en note. Le code s’enregistre avec le mapping.</div></div>`;
    }
    carteAffectation(f) {
      const d = this.d();
      const cp = this.s.cpBr || (this.s.cpBr = copie(d.reglages.cp) || []);
      const sale = JSON.stringify(cp) !== JSON.stringify(d.reglages.cp);
      return `<div class="carte"><h3>Affectation au magasin</h3>
        <div class="regle"><b>Ce formulaire</b><span class="v"><select data-c="formMag" data-v="${esc(f.id)}" style="width:200px"><option value="">— aucun magasin —</option>
          ${d.magasins.map(m => `<option value="${esc(m.id)}"${f.affectation !== 'cp' && String(f.shopId) === m.id ? ' selected' : ''}>${esc(m.nom)}</option>`).join('')}
          <option value="@cp"${f.affectation === 'cp' ? ' selected' : ''}>Par code postal ↓</option></select></span></div>
        <div class="lab" style="margin-top:12px">Codes postaux → magasin</div>
        <table class="t"><thead><tr><th>De</th><th>À</th><th>Magasin</th><th>Libellé</th><th></th></tr></thead><tbody>
          ${cp.map((p, i) => `<tr><td style="width:70px"><input type="text" inputmode="numeric" maxlength="5" data-f="cpde${i}" data-c="cp" data-i="${i}" data-k="de" value="${esc(p.de)}" style="width:62px"></td>
            <td style="width:70px"><input type="text" inputmode="numeric" maxlength="5" data-f="cpa${i}" data-c="cp" data-i="${i}" data-k="a" value="${esc(p.a)}" style="width:62px"></td>
            <td style="width:150px"><select data-c="cp" data-i="${i}" data-k="shopId" style="width:140px" class="${p.shopId ? '' : 'manque'}"><option value="">à attribuer</option>${d.magasins.map(m => `<option value="${esc(m.id)}"${p.shopId === m.id ? ' selected' : ''}>${esc(m.nom)}</option>`).join('')}</select></td>
            <td><input type="text" data-f="cplib${i}" data-c="cp" data-i="${i}" data-k="libelle" value="${esc(p.libelle)}" style="width:100%"></td>
            <td style="width:20px"><button class="lien" data-a="cpRetirer" data-v="${i}" title="Retirer la plage">✕</button></td></tr>`).join('') || '<tr><td colspan="5" class="mini">Aucune plage : un formulaire « par code postal » laisse tous ses leads « à attribuer ».</td></tr>'}
        </tbody></table>
        <div style="display:flex;gap:8px;margin-top:10px"><button class="btn s xs" data-a="cpAjouter">＋ Plage</button>
          <button class="btn xs" data-a="cpEnregistrer" ${sale && !this.s.busy ? '' : 'disabled'}>Enregistrer les plages</button></div></div>`;
    }
    carteErp() {
      const d = this.d();
      const e = this.s.erpBr || (this.s.erpBr = copie(d.reglages.erp));
      const sale = JSON.stringify(e) !== JSON.stringify(d.reglages.erp);
      return `<div class="carte"><h3>Connexion ERP<small>${esc((d.statut.erp.base || '').replace(/^https?:\/\//, ''))}</small></h3>
        ${d.statut.erp.configure ? '' : '<div class="alerte ko">Le compte API du panel n’est pas configuré (Paramètres → compte consultant) : rien ne peut partir.</div>'}
        <div class="regle"><b>Envoi automatique</b><span class="v"><button class="tog ${e.envoiActif ? 'on' : ''}" data-a="erpActif"></button><span class="mini">${e.envoiActif ? 'chaque lead prêt part tout de suite' : 'éteint : les leads attendent « à envoyer »'}</span></span></div>
        <div class="regle"><b>Envoi</b><span class="v"><code>POST</code><input type="text" data-f="erpEnv" data-c="erp" data-k="routeEnvoi" value="${esc(e.routeEnvoi)}" style="flex:1"></span></div>
        <div class="regle"><b>Statuts</b><span class="v"><code>GET</code><input type="text" data-f="erpSt" data-c="erp" data-k="routeStatuts" value="${esc(e.routeStatuts)}" style="flex:1"><span class="mini">?ids=…, toutes les ${esc(e.relectureMin)} min</span></span></div>
        <div class="regle"><b>Lien d’un lead</b><span class="v"><input type="text" data-f="erpLien" data-c="erp" data-k="lienLead" value="${esc(e.lienLead)}" style="width:100%"></span></div>
        <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap"><button class="btn s xs" data-a="erpTest">Tester la route des statuts</button>
          <button class="btn s xs" data-a="erpEssai" ${this.s.busy ? 'disabled' : ''} title="Un lead « TEST » envoyé pour de vrai, puis relu : les réponses brutes de l’API">Envoyer un lead d’essai</button>
          <button class="btn xs" data-a="erpEnregistrer" ${sale && !this.s.busy ? '' : 'disabled'}>Enregistrer</button></div>
        <div class="mini" style="margin-top:8px">Les deux routes sont à créer côté panel (docs/contrat-api.md, « MétaDonnée ») : l’envoi est idempotent sur <code>meta_lead_id</code>.</div></div>`;
    }

    /* --- 3 · suivi des leads ----------------------------------------------------------- */
    vueLeads() {
      const d = this.d(), r = d.resume || {}, s = this.s;
      const L = s.leads;
      const kpis = `<div class="kpis">
        <div class="kpi"><div class="k">Leads · 30 j</div><div class="v">${r.leads || 0}</div><div class="s">${r.fb || 0} Facebook · ${r.ig || 0} Instagram</div></div>
        <div class="kpi"><div class="k">Dans l’ERP</div><div class="v ok">${r.dansErp || 0}</div><div class="s">${r.aAttribuer || 0} à attribuer · ${r.rejetes || 0} à corriger${r.attente ? ' · ' + r.attente + ' en attente' : ''}</div></div>
        <div class="kpi"><div class="k">Vus en moins de ${esc(d.reglages.sms.relanceH)} h</div><div class="v">${r.vus2h == null ? '—' : r.vus2h + ' %'}</div><div class="s">par le franchisé, dans l’ERP</div></div>
        <div class="kpi"><div class="k">1er contact</div><div class="v">${r.contactMedianMin == null ? '—' : esc(duree(r.contactMedianMin * 60000))}</div><div class="s">médiane, de l’arrivée à l’appel</div></div>
        <div class="kpi"><div class="k">Bureaux connectés</div><div class="v ok">${r.connectes || 0}</div><div class="s">${r.leads ? Math.round(100 * (r.connectes || 0) / r.leads) : 0} % · compte au webshop</div></div>
        <div class="kpi"><div class="k">En retard</div><div class="v ${r.retard ? 'ko' : ''}">${r.retard || 0}</div><div class="s">pas vu en ${esc(d.reglages.sms.relanceH)} h, pas rappelé en ${esc(d.reglages.sms.escaladeH)} h</div></div></div>`;
      const comptes = (L && L.comptes) || {};
      const chips = `<div class="chips">${FILTRES.map(f => `<button class="chip ${s.filtre === f[0] ? 'on' : ''}" data-a="filtre" data-v="${f[0]}">${esc(f[1])} <em>${comptes[f[0]] != null ? comptes[f[0]] : ''}</em></button>`).join('')}
        <span style="margin-left:auto;display:inline-flex;gap:8px"><select data-c="shopF" style="width:150px"><option value="">Tous les magasins</option>${d.magasins.map(m => `<option value="${esc(m.id)}"${s.shopF === m.id ? ' selected' : ''}>${esc(m.nom)}</option>`).join('')}</select>
        <select data-c="formF" style="width:190px"><option value="">Tous les formulaires</option>${d.formulaires.filter(f => f.suivi || f.leads30).map(f => `<option value="${esc(f.id)}"${s.formF === f.id ? ' selected' : ''}>${esc(f.nom)}</option>`).join('')}</select>
        <input type="text" data-f="q" data-i="q" value="${esc(s.q)}" placeholder="Entreprise, nom, téléphone…" style="width:200px"></span></div>`;
      let table;
      if (s.leadsErr) table = `<div class="alerte ko">${esc(s.leadsErr)}</div>`;
      else if (!L) table = '<div class="vides">Lecture des leads…</div>';
      else if (!L.leads.length) table = '<div class="vides">Aucun lead dans cette vue.</div>';
      else table = `<table class="t"><thead><tr><th>Reçu</th><th>Lead</th><th>Magasin</th><th>ERP</th><th>SMS</th><th>Franchisé</th><th>Suite</th></tr></thead><tbody>${this.lignesLeads(L.leads)}</tbody></table>
        ${L.total > L.leads.length ? `<div class="mini" style="text-align:center;margin-top:8px">${L.total - L.leads.length} de plus — affinez le filtre.</div>` : ''}`;
      const journal = (d.journal || []).slice(0, 14).map(j => `<div class="journal"><span class="d">${esc(jm(j.at) === jm(d.maintenant) ? hm(j.at) : jm(j.at))}</span>${pill(JOURNAL_PILL[j.type] || 'gr', j.type, 76)}<span class="t">${esc(j.message)}</span></div>`).join('');
      return kpis + chips + `<div class="grille" style="grid-template-columns:minmax(0,1fr) 380px">
        <div class="carte">${table}</div>
        <div class="carte"><h3>Le cycle<small>${d.statut.cron.dernier ? 'dernier passage ' + esc(this.ilYa(d.statut.cron.dernier)) : 'jamais passé'}</small></h3>${journal || '<div class="mini">Rien encore.</div>'}</div></div>`;
    }
    suite(l) {
      const dots = ['f'];
      const env = l.etat === 'envoye';
      dots.push(env ? 'f' : (l.etat === 'rejete' || l.etat === 'erreur') ? 'r' : 'c');
      if (!env) return dots.concat(['', '', '', '']);
      const st = l.statut || 'nouveau';
      dots.push(l.sms ? (l.smsErreur ? 'r' : 'f') : '');
      dots.push(l.vu ? 'f' : (l.retard === 'pas vu' ? 'r' : 'c'));
      dots.push(l.contacte ? 'f' : st === 'perdu' ? 'x' : (l.retard === 'pas rappelé' ? 'r' : (l.vu ? 'c' : '')));
      dots.push(l.connecte ? 'f' : st === 'perdu' ? 'x' : (st === 'bureau_cree' ? 'c' : ''));
      return dots;
    }
    lignesLeads(leads) {
      const s = this.s;
      let jour = '';
      const auj = new Date(this.maintenant()).toDateString(), hier = new Date(this.maintenant() - 86400000).toDateString();
      return leads.map(l => {
        const dj = new Date(ts(l.recu)).toDateString();
        let sep = '';
        if (dj !== jour) { jour = dj; const lib = dj === auj ? 'Aujourd’hui' : dj === hier ? 'Hier' : new Date(ts(l.recu)).toLocaleDateString('fr-BE', { weekday: 'long', day: '2-digit', month: '2-digit' }); sep = `<tr class="gp"><td colspan="7">${esc(lib)}</td></tr>`; }
        const erp = l.etat === 'envoye' ? pill('ok', l.erpId || '✓', 92, 'Dans l’ERP ' + (l.envoye ? 'depuis ' + this.ilYa(l.envoye) : '')) : pill(ETAT[l.etat][1], ETAT[l.etat][0], 92, l.motif || '');
        const sms = l.sms ? (l.smsErreur ? pill('ko', 'échec', 84, l.smsErreur) : l.smsRelances ? pill('att', 'relancé ' + l.smsRelances + '×', 84, 'Premier SMS ' + this.ilYa(l.sms) + ', dernière relance ' + this.ilYa(l.smsDerniere)) : pill('ok', '✓ ' + hm(l.sms), 84)) : vide(84);
        const st = l.statut || 'nouveau';
        let fr = vide(108), frSous = '';
        if (l.etat === 'envoye') {
          const age = this.maintenant() - ts(l.envoye);
          if (l.retard) { fr = pill('plein', l.retard + ' · ' + duree(age), 108, l.escalade ? 'Consultant prévenu ' + this.ilYa(l.escalade) : ''); frSous = l.escalade ? 'consultant prévenu' : (STATUT[st] || [st])[0]; }
          else { fr = pill((STATUT[st] || ['', 'gr'])[1], (STATUT[st] || [l.statutBrut || st])[0], 108, l.statutBrut ? 'ERP : ' + l.statutBrut : '');
            frSous = st === 'nouveau' ? 'depuis ' + duree(age) : st === 'vu' ? 'vu ' + this.ilYa(l.vu) : (st === 'contacte' || st === 'bureau_cree') && l.contacte ? 'rappelé en ' + duree(ts(l.contacte) - ts(l.recu)) : st === 'bureau_connecte' ? 'connecté ' + this.ilYa(l.connecte) : ''; }
        } else if (l.motif) frSous = l.motif;
        const ouvert = s.ouvert === l.id;
        const ligne = `<tr class="clic ${ouvert ? 'on' : ''}" data-a="ouvrir" data-v="${l.id}">
          <td style="width:46px;font-weight:600;color:var(--color-text-muted)">${esc(hm(l.recu))}</td>
          <td><div style="display:flex;gap:8px;align-items:center">${pf(l.plateforme)}<div style="min-width:0"><b>${esc(l.entreprise || l.contact || 'Sans nom')}</b><small>${esc([l.contact, l.cp, l.form].filter(Boolean).join(' · '))}</small></div></div></td>
          <td style="width:100px"><b style="font-size:12px">${esc(l.magasin || '—')}</b><small>${esc(l.shopPar === 'cp' ? 'CP ' + (l.cp || '') : l.shopPar === 'main' ? 'à la main' : l.shopPar === 'formulaire' ? 'formulaire' : '')}</small></td>
          <td style="width:96px">${erp}</td><td style="width:88px">${sms}</td>
          <td style="width:116px">${fr}<small style="text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:116px">${esc(frSous)}</small></td>
          <td style="width:112px"><span class="suite" title="Meta · ERP · SMS · vu · contacté · bureau connecté">${this.suite(l).map((x, i) => (i ? `<s class="${x && x !== 'x' ? 'f' : ''}"></s>` : '') + `<i class="${x}"></i>`).join('')}</span></td></tr>`;
        return sep + ligne + (ouvert ? `<tr class="det"><td colspan="7">${this.detailLead(l)}</td></tr>` : '');
      }).join('');
    }
    detailLead(l) {
      const det = this.s.detail[l.id];
      if (!det) return '<div class="mini">Lecture…</div>';
      if (det.err) return `<div class="alerte ko">${esc(det.err)}</div>`;
      const d = this.d();
      const reps = det.reponses.map(r => {
        const ch = r.champ === 'ignorer' ? 'ignoré' : r.champ || 'note';
        return `<span class="m" title="${esc(r.label)}">${esc(r.label)} · ${esc(r.valeur)}</span><span class="a">→</span><span class="e"><code>${esc(ch)}</code></span>`;
      }).join('');
      const fil = (det.journal || []).map(j => `<div class="journal"><span class="d">${esc(hm(j.at))}</span>${pill(JOURNAL_PILL[j.type] || 'gr', j.type, 70)}<span class="t">${esc(j.message)}</span></div>`).join('');
      const attrib = l.etat === 'a_attribuer' || (l.etat !== 'envoye' && !l.shopId) ? `<div class="regle"><b>Magasin</b><span class="v"><select data-c="attrib" data-v="${l.id}" style="width:160px"><option value="">— choisir —</option>${d.magasins.map(m => `<option value="${esc(m.id)}"${this.s.attrib[l.id] === m.id ? ' selected' : ''}>${esc(m.nom)}</option>`).join('')}</select>
        <button class="btn xs" data-a="attribuer" data-v="${l.id}" ${this.s.attrib[l.id] ? '' : 'disabled'}>Attribuer et envoyer</button></span></div>` : '';
      return `<div class="grille" style="grid-template-columns:minmax(0,1.2fr) minmax(0,1fr) 230px">
        <div><div class="lab">Meta → ERP</div><div class="kv">${reps}</div></div>
        <div><div class="lab">Son fil</div>${fil || '<div class="mini">—</div>'}
          ${det.payload ? `<details style="margin-top:10px"><summary class="lien">Ce qui est parti à l’ERP${l.statutBrut ? ' · statut brut « ' + esc(l.statutBrut) + ' »' : ''}</summary><div class="code" style="margin-top:6px">${esc(JSON.stringify(det.payload, null, 2))}</div></details>` : ''}</div>
        <div><div class="lab">Gestes</div>
          ${l.motif ? `<div class="alerte ${l.etat === 'rejete' || l.etat === 'erreur' ? 'ko' : ''}">${esc(l.motif)}</div>` : ''}
          ${attrib}
          <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-start;margin-top:6px">
            ${l.etat !== 'attente' ? `<button class="btn s xs" data-a="renvoyer" data-v="${l.id}" ${this.s.busy ? 'disabled' : ''}>↻ ${l.etat === 'envoye' ? 'Renvoyer' : 'Envoyer'} à l’ERP</button>` : ''}
            ${l.etat === 'envoye' ? `<button class="btn s xs" data-a="relancer" data-v="${l.id}" ${this.s.busy ? 'disabled' : ''}>💬 Relancer le franchisé par SMS</button>` : ''}
            ${l.lien ? `<a class="btn xs" href="${esc(l.lien)}" target="_blank" rel="noopener">Ouvrir dans l’ERP ↗</a>` : ''}
            ${l.telephone ? `<span class="mini">Le bureau : <b>${esc(l.telephone)}</b>${l.email ? ' · ' + esc(l.email) : ''}</span>` : ''}
          </div></div></div>`;
    }

    /* --- 4 · alertes SMS ----------------------------------------------------------------- */
    vueSms() {
      const d = this.d(), s = this.s;
      const r = s.smsBr || (s.smsBr = copie(d.reglages.sms));
      const sale = JSON.stringify(r) !== JSON.stringify(d.reglages.sms);
      const exemple = t => String(t || '').replace(/\{entreprise\}/g, 'Fiduciaire Delvaux').replace(/\{personnes\}/g, '35').replace(/\{cp\}/g, '6041')
        .replace(/\{contact\}/g, 'Claire Mertens').replace(/\{telephone\}/g, '0476 12 34 56').replace(/\{besoin\}/g, 'Lunch tous les jours').replace(/\{magasin\}/g, 'Gosselies')
        .replace(/\{lien_erp\}/g, String(d.reglages.erp.lienLead || '').replace('{erp_id}', 'L-20488')).replace(/\{attente\}/g, '2 h 05');
      const compteur = t => { const c = coutSms(exemple(t)); return `<div class="cpt"><span>${c.uni ? 'un caractère hors GSM : 70 par SMS' : 'GSM : 160 par SMS'}</span><b class="${c.sms > 1 ? 'ko' : ''}">${c.car} car. · ${c.sms} SMS</b></div>`; };
      const modele = (cle, titre) => `<div class="lab" style="margin-top:6px">${esc(titre)}</div>
        <textarea data-f="${cle}" data-i="sms" data-k="${cle}">${esc(r[cle])}</textarea>${compteur(r[cle])}
        <div class="tel" style="margin-top:8px"><div class="de">SMS · ${esc(r.from || 'expéditeur')}</div><div class="bulle">${esc(exemple(r[cle]))}</div></div>`;
      const nombre = (k, min, max, suffixe) => `<input type="number" data-f="sms${k}" data-c="sms" data-k="${k}" value="${esc(r[k])}" min="${min}" max="${max}" style="width:62px"> ${suffixe}`;
      const st = d.statut.sms;
      const mags = d.magasins.map(m => {
        const b = s.magBr[m.id] || (s.magBr[m.id] = copie(m.sms));
        const salem = JSON.stringify(b) !== JSON.stringify(m.sms);
        const x = m.stats || {};
        return `<tr><td><b>${esc(m.nom)}</b><small>${esc(m.statut || '')}</small></td>
          <td style="width:46px"><button class="tog ${b.on ? 'on' : ''}" data-a="magSms" data-v="${esc(m.id)}"></button></td>
          <td style="width:140px"><input type="tel" data-f="mtel${m.id}" data-c="mag" data-v="${esc(m.id)}" data-k="tel" value="${esc(b.tel)}" placeholder="0476 …" style="width:130px"></td>
          <td style="width:130px"><input type="text" data-f="mnom${m.id}" data-c="mag" data-v="${esc(m.id)}" data-k="nom" value="${esc(b.nom)}" placeholder="gérant" style="width:120px"></td>
          <td style="width:190px"><input type="email" data-f="mmail${m.id}" data-c="mag" data-v="${esc(m.id)}" data-k="escaladeEmail" value="${esc(b.escaladeEmail)}" placeholder="consultant@…" style="width:180px"></td>
          <td style="width:52px;text-align:right">${esc(x.leads || 0)}</td>
          <td style="width:62px;text-align:right">${x.vus2h == null ? '—' : esc(x.vus2h) + ' %'}</td>
          <td style="width:70px;text-align:right">${x.contactMedianMin == null ? '—' : esc(duree(x.contactMedianMin * 60000))}</td>
          <td style="width:64px">${x.retard ? pill('plein', x.retard + ' retard', 60) : pill('ok', '✓', 60)}</td>
          <td style="width:96px;text-align:right"><button class="btn xs" data-a="magEnregistrer" data-v="${esc(m.id)}" ${salem && !s.busy ? '' : 'disabled'}>Enregistrer</button></td></tr>`;
      }).join('');
      return `<div class="grille" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(0,.9fr)">
        <div class="carte"><h3>Règles<small>SMS au gérant du magasin</small><span class="d"><button class="btn xs" data-a="smsEnregistrer" ${sale && !s.busy ? '' : 'disabled'}>Enregistrer</button></span></h3>
          <div class="regle"><b>SMS automatiques</b><span class="v"><button class="tog ${r.actif ? 'on' : ''}" data-a="smsActif"></button><span class="mini">${r.actif ? 'un SMS à chaque lead entré dans l’ERP' : 'éteints : aucun SMS ne part tout seul'}</span></span></div>
          <div class="regle"><b>Heures</b><span class="v">de ${nombre('heureDebut', 0, 23, 'h')} à ${nombre('heureFin', 1, 24, 'h')} <span class="mini">— hors de la fenêtre, il part à l’ouverture</span></span></div>
          <div class="regle"><b>Pas vu</b><span class="v">relance après ${nombre('relanceH', 1, 48, 'h')}, au plus ${nombre('relancesMax', 0, 5, 'fois')}</span></div>
          <div class="regle"><b>Pas rappelé</b><span class="v">e-mail au consultant après ${nombre('escaladeH', 1, 168, 'h')}</span></div>
          <div class="regle"><b>Expéditeur</b><span class="v"><input type="text" data-f="smsFrom" data-c="sms" data-k="from" value="${esc(r.from)}" maxlength="11" style="width:130px"> <span class="mini">nom validé chez SMSAPI, 11 caractères</span></span></div>
          ${d.statut.smtp ? '' : '<div class="alerte">SMTP non configuré : l’e-mail au consultant ne partira pas (le journal le dira).</div>'}
        </div>
        <div class="carte"><h3>Les textes<small>{entreprise} {personnes} {cp} {contact} {telephone} {besoin} {magasin} {lien_erp} {attente}</small></h3>
          ${modele('modele', 'Nouveau lead')}${modele('modeleRelance', 'Relance')}</div>
        <div class="carte"><h3>SMSAPI<small>${esc(st.base)}</small></h3>
          <div class="alerte ${st.configure ? 'ok' : 'ko'}">${st.configure ? '✓ Jeton ' + (st.jetonSaisi ? 'saisi ici' : 'du serveur (config ou environnement)') : 'Aucun jeton : aucun SMS ne peut partir.'}</div>
          <div class="regle"><b>Jeton OAuth</b><span class="v"><input type="password" data-f="jeton" data-i="jeton" value="${esc(s.jeton)}" placeholder="${st.jetonSaisi ? '•••••• enregistré' : 'jeton avec la permission sms'}" style="width:100%"></span></div>
          <div class="regle"><b>Plateforme</b><span class="v"><input type="text" data-f="smsBase" data-c="sms" data-k="base" value="${esc(r.base)}" placeholder="https://api.smsapi.com" style="width:100%"></span></div>
          <div style="display:flex;gap:8px;margin-top:8px"><button class="btn s xs" data-a="jetonEnregistrer" ${s.jeton ? '' : 'disabled'}>Enregistrer le jeton</button>${st.jetonSaisi ? '<button class="btn s xs" data-a="jetonEffacer">Effacer</button>' : ''}</div>
          <div class="lab" style="margin-top:14px">SMS d’essai</div>
          <div style="display:flex;gap:8px"><input type="tel" data-f="telTest" data-i="telTest" value="${esc(s.telTest)}" placeholder="votre numéro" style="flex:1"><button class="btn s xs" data-a="smsTest" ${s.telTest && !s.busy ? '' : 'disabled'}>Envoyer</button></div>
          <div class="mini" style="margin-top:8px">Code 101 : jeton refusé (OAuth, permission « sms », plateforme .com ou .pl) · 112 : la Belgique n’est pas autorisée sur le compte.</div></div>
      </div>
      <div class="carte"><h3>Les magasins<small>numéro du gérant, e-mail du consultant prévenu en cas de retard · chiffres sur 30 jours</small></h3>
        <table class="t"><thead><tr><th>Magasin</th><th>SMS</th><th>Numéro</th><th>Nom</th><th>Consultant (e-mail)</th><th style="text-align:right">Leads</th><th style="text-align:right">Vus ${esc(d.reglages.sms.relanceH)} h</th><th style="text-align:right">1er contact</th><th>Retard</th><th></th></tr></thead><tbody>${mags}</tbody></table></div>`;
    }

    /* --- gestes ------------------------------------------------------------------------- */
    apercuPlus() {
      const f = this.form(this.s.formSel);
      if (!f) return;
      clearTimeout(this._ap);
      this._ap = setTimeout(() => {
        const b = this.brouillon(f);
        ecrire(this.base, 'POST', '/metadonnee/apercu', { formId: f.id, mapping: b.mapping, valeurs: b.valeurs })
          .then(r => { this.s.apercu[f.id] = r; }).catch(e => { this.s.apercu[f.id] = { err: e.message }; }).finally(() => this.render());
      }, 250);
    }
    onClick(e) {
      const t = e.target.closest('[data-a]');
      if (!t || !this.el.contains(t) || t.disabled) return;
      if (t.tagName === 'A') return;
      const a = t.dataset.a, v = t.dataset.v, s = this.s, d = this.d();
      if (t.tagName === 'TR' && e.target.closest('select,input,button,a')) return;
      const W = (m, p, b) => ecrire(this.base, m, p, b);
      switch (a) {
        case 'recharger': this.charger(); break;
        case 'vue': if (this.opts.go) this.opts.go(v); else { this.setVue(v); this.render(); } break;
        case 'tick': this.act(W('POST', '/metadonnee/tick'), r => 'Cycle passé — ' + this.bilan(r.bilan), () => { this.charger(); if (s.vue === 'mdLeads') this.chargerLeads(); }); break;
        case 'relire': this.act(W('POST', '/metadonnee/formulaires/relire'), r => r.formulaires + ' formulaire(s) relu(s)' + (r.nouveaux ? ', ' + r.nouveaux + ' nouveau(x), non suivi(s)' : '')); break;
        case 'copier': if (navigator.clipboard) navigator.clipboard.writeText(v).then(() => this.notify('Copié'), () => this.notify('⚠ Copie impossible')); break;
        case 'metaEnregistrer': this.act(W('PUT', '/metadonnee/meta', this.metaCorps()), 'Connexion Meta enregistrée', () => { s.metaBr = null; return this.charger(); }); break;
        case 'metaSource': this.act(W('PUT', '/metadonnee/meta', { source: v }), v === 'crm' ? 'Les clés et l’accès du CRM' : 'Clés propres', () => { s.metaBr = null; return this.charger(); }); break;
        case 'metaConnecter': {
          // Avec les clés du CRM, rien à enregistrer d'abord : les champs propres, cachés, ne sont pas envoyés.
          const avant = (d.statut.meta || {}).source === 'cockpit' ? W('PUT', '/metadonnee/meta', this.metaCorps()) : Promise.resolve();
          this.act(avant.then(() => W('POST', '/metadonnee/meta/connecter')),
            r => r.ok ? '✓ Connexion Meta vérifiée' : '⚠ Connexion incomplète : voir les étapes', () => { s.metaBr = null; return this.charger(); });
          break;
        }
        case 'suivre': { const f = this.form(v); this.act(W('PUT', '/metadonnee/formulaires/' + v, { suivi: !f.suivi }), (f.suivi ? 'Plus suivi : ' : 'Suivi : ') + f.nom); break; }
        case 'historique': this.act(W('POST', '/metadonnee/formulaires/' + v + '/rattrapage', { jours: 30 }), r => (r.nouveaux || 0) + ' lead(s) récupéré(s) sur 30 jours'); break;
        case 'versMapping': s.formSel = v; if (this.opts.go) this.opts.go('mdMapping'); else { this.setVue('mdMapping'); this.render(); } this.apercuPlus(); break;
        case 'choisir': s.formSel = v; this.render(); this.apercuPlus(); break;
        case 'suggestion': { const f = this.form(s.formSel); const q = f.questions.find(x => x.key === v); this.brouillon(f).mapping[v] = q.suggestion; this.render(); this.apercuPlus(); break; }
        case 'annulerMapping': delete s.br[s.formSel]; this.render(); this.apercuPlus(); break;
        case 'enregistrerMapping': {
          const f = this.form(s.formSel), b = this.brouillon(f);
          this.act(W('PUT', '/metadonnee/formulaires/' + f.id, { mapping: b.mapping, valeurs: b.valeurs, par: this.opts.user || '' }),
            r => 'Mapping enregistré' + (r.liberes ? ' — ' + r.liberes + ' lead(s) libéré(s)' : ''), () => { delete s.br[f.id]; return this.charger(); });
          break;
        }
        case 'cpAjouter': (s.cpBr = s.cpBr || []).push({ de: '', a: '', shopId: '', libelle: '' }); this.render(); break;
        case 'cpRetirer': s.cpBr.splice(Number(v), 1); this.render(); break;
        case 'cpEnregistrer': this.act(W('PUT', '/metadonnee/reglages', { cp: s.cpBr }), r => 'Plages enregistrées' + (r.liberes ? ' — ' + r.liberes + ' lead(s) réaffecté(s)' : ''), () => { s.cpBr = null; return this.charger(); }); break;
        case 'erpActif': s.erpBr.envoiActif = !s.erpBr.envoiActif; this.render(); break;
        case 'erpEnregistrer': this.act(W('PUT', '/metadonnee/reglages', { erp: s.erpBr }), 'Connexion ERP enregistrée', () => { s.erpBr = null; return this.charger(); }); break;
        case 'erpTest': this.act(W('POST', '/metadonnee/erp/test'), r => (r.ok ? '✓ ' : '⚠ ') + r.message, () => null); break;
        case 'erpEssai': {
          const f = this.form(s.formSel);
          if (!confirm('Envoyer un lead d’ESSAI à l’ERP' + (f && f.shopId ? ' de ' + this.nomMag(f.shopId) : '') + ' ? Il est marqué « TEST » et restera dans l’ERP : à y supprimer.')) break;
          this.act(W('POST', '/metadonnee/erp/essai', { shopId: f ? f.shopId : '' }), r => r.ok ? 'Lead d’essai envoyé et relu' : '⚠ Lead d’essai : voir le détail', r => { s.essai = r; return this.charger(); });
          break;
        }
        case 'essaiFermer': s.essai = null; this.render(); break;
        case 'sonde': this.act(W('POST', '/metadonnee/sonde'), null, r => { s.sonde = r; }).catch(() => null); break;
        case 'sondeFermer': s.sonde = null; this.render(); break;
        case 'filtre': s.filtre = v; s.ouvert = null; this.chargerLeads(); this.render(); break;
        case 'ouvrir': s.ouvert = s.ouvert === Number(v) ? null : Number(v); if (s.ouvert && !s.detail[s.ouvert]) this.chargerDetail(s.ouvert); this.render(); break;
        case 'attribuer': this.act(W('PUT', '/metadonnee/leads/' + v, { shopId: s.attrib[v], envoyer: true }), r => r.envoi && r.envoi.ok ? 'Attribué et envoyé dans l’ERP' : 'Attribué' + (r.envoi && r.envoi.motif ? ' — ' + r.envoi.motif : ''), () => this.rafraichirLead(v)); break;
        case 'renvoyer': this.act(W('POST', '/metadonnee/leads/' + v + '/envoyer'), r => r.ok ? 'Dans l’ERP' + (r.erpId ? ' · ' + r.erpId : '') : '⚠ ' + (r.motif || 'non envoyé'), () => this.rafraichirLead(v)); break;
        case 'relancer': this.act(W('POST', '/metadonnee/leads/' + v + '/sms'), r => r.envoye ? 'SMS envoyé au franchisé' : '⚠ ' + (r.motif || 'SMS non parti'), () => this.rafraichirLead(v)); break;
        case 'smsActif': s.smsBr.actif = !s.smsBr.actif; this.render(); break;
        case 'smsEnregistrer': this.act(W('PUT', '/metadonnee/reglages', { sms: s.smsBr }), 'Règles des SMS enregistrées', () => { s.smsBr = null; return this.charger(); }); break;
        case 'jetonEnregistrer': this.act(W('PUT', '/metadonnee/reglages', { smsJeton: s.jeton }), 'Jeton SMSAPI enregistré', () => { s.jeton = ''; return this.charger(); }); break;
        case 'jetonEffacer': if (confirm('Effacer le jeton SMSAPI saisi ici ? (celui du serveur, s’il existe, reprend la main)')) this.act(W('PUT', '/metadonnee/reglages', { smsJeton: '' }), 'Jeton effacé'); break;
        case 'smsTest': this.act(W('POST', '/metadonnee/sms/test', { tel: s.telTest }), r => 'SMS d’essai parti : « ' + r.texte + ' »', () => this.charger()); break;
        case 'magSms': s.magBr[v].on = !s.magBr[v].on; this.render(); break;
        case 'magEnregistrer': { const b = s.magBr[v]; this.act(W('PUT', '/metadonnee/magasins/' + v, { smsOn: b.on, smsTel: b.tel, smsNom: b.nom, escaladeEmail: b.escaladeEmail }), 'Magasin enregistré', () => { delete s.magBr[v]; return this.charger(); }); break; }
      }
    }
    /** Ce qui part au serveur : un secret laissé vide n'est pas envoyé (il garde l'enregistré). */
    metaCorps() {
      const b = this.s.metaBr || {};
      const c = { appId: (b.appId || '').trim(), pageId: (b.pageId || '').trim(), rappel: (b.rappel || '').trim() };
      if (b.secret) c.secret = b.secret.trim();
      if (b.jeton) c.jeton = b.jeton.trim();
      return c;
    }
    rafraichirLead(id) { delete this.s.detail[id]; return Promise.all([this.charger(), this.chargerLeads()]).then(() => { if (this.s.ouvert === Number(id)) this.chargerDetail(id); }); }
    bilan(b) {
      if (!b) return 'rien';
      const p = [];
      if (b.meta && b.meta.nouveaux != null) p.push(b.meta.nouveaux + ' nouveau(x) chez Meta');
      if (b.envoi) p.push(b.envoi.envoyes + ' envoyé(s) à l’ERP' + (b.envoi.echecs ? ', ' + b.envoi.echecs + ' échec(s)' : ''));
      if (b.relecture && b.relecture.relus != null) p.push(b.relecture.changes + ' statut(s) changé(s)');
      if (b.sms) p.push(b.sms.sms + ' SMS' + (b.sms.escalades ? ', ' + b.sms.escalades + ' escalade(s)' : ''));
      return p.join(' · ') || 'rien à faire';
    }
    onChange(e) {
      const t = e.target, s = this.s, c = t.dataset.c, v = t.dataset.v;
      if (!c) return;
      const W = (m, p, b) => ecrire(this.base, m, p, b);
      switch (c) {
        case 'formMag': this.act(W('PUT', '/metadonnee/formulaires/' + v, t.value === '@cp' ? { affectation: 'cp' } : { affectation: 'magasin', shopId: t.value }),
          t.value === '@cp' ? 'Magasin choisi par code postal' : t.value ? 'Magasin : ' + this.nomMag(t.value) : 'Formulaire sans magasin', () => this.charger().then(() => this.apercuPlus())); break;
        case 'champ': { const f = this.form(s.formSel); this.brouillon(f).mapping[v] = t.value; if (!t.value) delete this.brouillon(f).mapping[v]; this.render(); this.apercuPlus(); break; }
        case 'valeur': { const f = this.form(s.formSel), b = this.brouillon(f); b.valeurs[v] = b.valeurs[v] || {}; if (t.value) b.valeurs[v][t.dataset.r] = t.value; else delete b.valeurs[v][t.dataset.r]; this.render(); this.apercuPlus(); break; }
        case 'cp': { const p = s.cpBr[Number(t.dataset.i)]; p[t.dataset.k] = t.dataset.k === 'de' || t.dataset.k === 'a' ? (t.value === '' ? '' : Number(t.value)) : t.value; this.render(); break; }
        case 'erp': s.erpBr[t.dataset.k] = t.value; this.render(); break;
        case 'sms': s.smsBr[t.dataset.k] = t.type === 'number' ? Number(t.value) : t.value; this.render(); break;
        case 'mag': s.magBr[v][t.dataset.k] = t.value; this.render(); break;
        case 'shopF': s.shopF = t.value; this.chargerLeads(); break;
        case 'formF': s.formF = t.value; this.chargerLeads(); break;
        case 'attrib': s.attrib[v] = t.value; this.render(); break;
      }
    }
    onInput(e) {
      const t = e.target, s = this.s, i = t.dataset.i;
      if (!i) return;
      if (i === 'q') { s.q = t.value; clearTimeout(this._q); this._q = setTimeout(() => this.chargerLeads(), 300); return; }
      if (i === 'sms') { s.smsBr[t.dataset.k] = t.value; this.render(); return; }
      if (i === 'jeton') { s.jeton = t.value; this.render(); return; }
      if (i === 'meta') { s.metaBr[t.dataset.k] = t.value; this.render(); return; }
      if (i === 'telTest') { s.telTest = t.value; this.render(); return; }
    }
  }

  let inst = null;
  window.CockpitMetaDonnee = {
    /** Une instance pour les quatre écrans : le brouillon du mapping survit au changement d'étape. */
    mount(host, opts) {
      if (!host) return null;
      styles();
      if (!inst) inst = new MD(host, opts);
      else if (opts) Object.assign(inst.opts, opts, { vue: inst.opts.vue });
      if (opts && opts.vue) inst.setVue(opts.vue);
      inst.mount(host);
      return inst;
    },
  };
})();
