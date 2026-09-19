/* Prospection — la liste de démarchage du magasin, un CRM minimal, la carte
 * des concentrations et le calculateur de pénétration.
 *
 * Un seul module, deux hôtes : l'écran « Prospection » du cockpit (bureau,
 * deux colonnes) et la page mobile public/prospection/ (une colonne, quatre
 * onglets — la liste et l'annotation en tournée). Les lieux viennent de
 * ../api/cockpit/scouting/demarchage (OpenStreetMap autour du magasin, gardé
 * 45 jours au serveur) ; la position du magasin de /scouting/reseau ; ce que
 * le franchisé fait de chaque lieu (ma liste, statut, visite, note) vit AU
 * SERVEUR dans /prospection/{shop} — le bureau et le téléphone lisent la même
 * réserve.
 *
 *   CockpitProspection.mount(host, { shop, apiBase, mobile, nom })
 *
 * Script classique (pas de module ES) : chargé tel quel par le cockpit et par
 * la page mobile. Expose aussi `CockpitProspection.etat(shop)` / `.set(...)`
 * pour que l'écran Développement commercial partage la réserve.
 */
(function () {
  'use strict';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
  const fN = n => n == null ? '—' : nf(Math.round(n), 0);
  const fE = n => n == null ? '—' : nf(Math.round(n), 0) + ' €';
  const fK = n => n == null ? '—' : (Math.abs(n) >= 10000 ? nf(n / 1000, 1) + ' k€' : fE(n));
  const fP = n => n == null ? '—' : nf(n, 1) + ' %';
  const AUJ = new Date().toISOString().slice(0, 10);

  const FAM = [['bureaux', 'Entreprises', '#8D1D2C'], ['industrie', 'Industrie', '#78554B'], ['ecoles', 'Écoles', '#C17A2A'], ['sante', 'Santé', '#2d7a3e'],
    ['administration', 'Administrations', '#4a5a8a'], ['formation', 'Formation', '#B26A00'], ['funeraire', 'Funéraire', '#555'], ['sport', 'Sport', '#1baf7a'],
    ['evenements', 'Hôtels & événements', '#a34a8c'], ['commerces', 'Commerces (zoning)', '#9a8c6a'], ['artisans', 'Artisans', '#6f6f6f']];
  const NOM = Object.fromEntries(FAM.map(f => [f[0], f[1]]));
  const COUL = Object.fromEntries(FAM.map(f => [f[0], f[2]]));
  // Le statut d'un lieu de ma liste, dans l'ordre où un tap le fait avancer.
  const ST = [['', 'À visiter', 'mu'], ['visite', 'Visité', 'wa'], ['rappeler', 'À rappeler', 'wa'], ['rdv', 'RDV pris', 'ok'], ['client', 'Client', 'ok'], ['refus', 'Refus', 'ko']];
  const RAYONS = [3000, 5000, 8000, 12000];
  // L'action à suivre, décidée sur place, avec sa date : le CRM du téléphone.
  const ACT = [['', '— à suivre —'], ['mail', 'Envoyer un mail'], ['test', 'Envoyer un test (échantillon)'], ['devis', 'Envoyer un devis'], ['rappel', 'Rappeler'], ['passer', 'Repasser'], ['commande', 'Prendre la commande']];
  const ACT_NOM = Object.fromEntries(ACT);
  const fD = d => d ? d.slice(8, 10) + '/' + d.slice(5, 7) : '';

  /* --- la réserve serveur, partagée entre les hôtes -------------------------- */
  const STORE = { base: null, etats: {}, enCours: {}, ecoute: [], offres: null, offresEnCours: false };
  /* Le type d'offre : ce qu'on propose selon ce qu'est le client — un hôpital
   * ne prend pas la même chose qu'une maison communale. Catalogue au serveur
   * (/prospection/offres) : argument, contenu, hypothèses du calcul de CA. */
  function offresCharger(force) {
    if (STORE.offresEnCours || (STORE.offres && !force)) return Promise.resolve(STORE.offres || []);
    STORE.offresEnCours = true;
    return lire('/prospection/offres').then(d => { STORE.offres = (d && d.offres) || []; return STORE.offres; }).catch(() => STORE.offres || [])
      .finally(() => { STORE.offresEnCours = false; STORE.ecoute.forEach(f => { try { f('offres'); } catch (e) { /* un hôte parti */ } }); });
  }
  function offres() { if (!STORE.offres && !STORE.offresEnCours) offresCharger(false); return STORE.offres || []; }
  function offreParId(id) { return offres().find(o => o.id === id) || null; }
  /** L'offre d'un lieu : celle choisie, sinon celle de son genre (mots du libellé), sinon de sa famille, sinon la pause du personnel. */
  function offreDe(l, choisie) {
    const O = offres();
    if (choisie && O.some(o => o.id === choisie)) return choisie;
    const g = String(l.genre || '').toLowerCase();
    for (const o of O) { if (o.genres && new RegExp(o.genres, 'iu').test(g)) return o.id; }
    for (const o of O) { if ((o.familles || []).includes(l.famille)) return o.id; }
    return O.length ? 'personnel' : '';
  }
  function apiBase() {
    if (STORE.base) return STORE.base;
    return (typeof window !== 'undefined' && window.COCKPIT_API_BASE) || (location.pathname.replace(/[^/]*$/, '') + 'api/cockpit');
  }
  function lire(path) {
    return fetch(apiBase() + path, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : r.json().then(j => Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status))), () => Promise.reject(new Error('HTTP ' + r.status))));
  }
  function ecrire(method, path, body) {
    return fetch(apiBase() + path, { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) })
      .then(r => r.json().catch(() => ({})).then(j => r.ok ? j : Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status)))));
  }
  function etatCharger(shop, force) {
    const k = String(shop);
    if (!k || STORE.enCours[k] || (STORE.etats[k] && !force)) return Promise.resolve(STORE.etats[k] || {});
    STORE.enCours[k] = true;
    return lire('/prospection/' + k).then(d => { STORE.etats[k] = (d && d.lieux) || {}; return STORE.etats[k]; })
      .catch(() => { STORE.etats[k] = STORE.etats[k] || {}; return STORE.etats[k]; })
      .finally(() => { STORE.enCours[k] = false; STORE.ecoute.forEach(f => { try { f(k); } catch (e) { /* un hôte parti */ } }); });
  }
  function etat(shop) { const k = String(shop); if (!STORE.etats[k] && !STORE.enCours[k]) etatCharger(k, false); return STORE.etats[k] || {}; }
  /** Fusionne localement puis écrit au serveur ; le serveur rend la ligne, ou la retire. */
  function set(shop, id, patch, nom) {
    const k = String(shop);
    const par = STORE.etats[k] || (STORE.etats[k] = {});
    const cur = par[id] || (par[id] = { coche: false, visite: '', retour: '', note: '' });
    Object.assign(cur, patch);
    if (nom) cur.nom = nom;
    if (patch.note !== undefined || patch.retour !== undefined || patch.visite !== undefined || patch.action !== undefined) cur.le = AUJ;
    if (patch.action === '') cur.actionLe = '';
    if (!cur.coche && !cur.visite && !cur.retour && !cur.note && !cur.action) delete par[id];
    const lieux = {}; lieux[id] = Object.assign({ nom: nom || cur.nom || '' }, patch);
    return ecrire('PUT', '/prospection/' + k, { lieux }).then(r => {
      if (r && r.lieux) { if (r.lieux[id]) par[id] = r.lieux[id]; }
      return r;
    }).catch(e => { console.warn('[prospection] écriture refusée : ' + e.message); return null; });
  }

  /* --- l'hôte : un écran, son état, son rendu -------------------------------- */
  const CALC_DEF = { depense: 6, commandes: 1, semaines: 46, part: 20 };
  class Hote {
    constructor(host, opts) {
      this.host = host;
      this.opts = Object.assign({ mobile: false }, opts || {});
      if (this.opts.apiBase) STORE.base = this.opts.apiBase;
      this.el = document.createElement('div');
      this.el.className = 'pr' + (this.opts.mobile ? ' pr-mobile' : '');
      this.s = { vue: 'liste', filtre: 'tout', fam: null, grand: false, q: '', r: 5000, reseau: null, lieux: null, err: '', enCours: false, calc: Object.assign({}, CALC_DEF) };
      try { Object.assign(this.s.calc, JSON.parse(localStorage.getItem('ceo_prospection_calc') || '{}')); } catch (e) { /* réglages absents */ }
      this.carteEl = null; this.carte = null; this.couches = null;
      STORE.ecoute.push(() => { if (this.el.isConnected) this.render(); });
      this.el.addEventListener('click', e => this.onClick(e));
      this.el.addEventListener('change', e => this.onChange(e));
    }
    shop() { return String(this.opts.shop || ''); }
    setShop(shop) { if (String(shop) !== this.shop()) { this.opts.shop = String(shop); this.s.lieux = null; this.s.err = ''; this.s.vue = 'liste'; this.charger(false); } }
    mount(host) {
      if (host && this.host !== host) this.host = host;
      if (this.host && this.el.parentNode !== this.host) this.host.appendChild(this.el);
      this.charger(false);
      this.render();
    }
    magasin() {
      const R = this.s.reseau; if (!R) return null;
      return (R.magasins || []).find(m => String(m.id) === this.shop() && m.lat != null) || null;
    }
    charger(force) {
      const s = this.s;
      if (!s.reseau && !s.reseauEnCours) {
        s.reseauEnCours = true;
        lire('/scouting/reseau').then(r => { s.reseau = r || { magasins: [] }; s.reseauEnCours = false; this.charger(false); })
          .catch(e => { s.err = e.message; s.reseauEnCours = false; this.render(); });
        return;
      }
      const m = this.magasin();
      if (!m || s.enCours) return;
      etatCharger(this.shop(), false);
      offresCharger(false);
      if (s.lieux && s.lieux.r === s.r && s.lieux.shop === this.shop() && !force) return;
      s.enCours = true; s.err = '';
      const shop = this.shop();
      lire('/scouting/demarchage?lat=' + m.lat.toFixed(5) + '&lng=' + m.lng.toFixed(5) + '&r=' + s.r + (force ? '&force=1' : ''))
        .then(d => { s.lieux = d || {}; s.lieux.r = s.r; s.lieux.shop = shop; }).catch(e => { s.err = e.message; })
        .finally(() => { s.enCours = false; this.render(); });
    }
    /** Les lieux relevés, sans les boulangeries (la mienne comprise), avec ce que j'en ai fait. */
    lieux() {
      const d = this.s.lieux; if (!d || d.shop !== this.shop()) return [];
      const ma = etat(this.shop());
      return (d.lieux || []).filter(l => !/bakery|pastry/.test(l.genre) && !/atelier by/i.test(l.nom)).map(l => {
        const e = ma[l.id] || {};
        const st = e.retour || (e.visite ? 'visite' : '');
        const offre = offreDe(l, e.offre);
        return Object.assign({}, l, { dans: !!e.coche, visite: e.visite || '', retour: e.retour || '', note: e.note || '', st, action: e.action || '', actionLe: e.actionLe || '', offre, offreChoisie: e.offre || '',
          aFaire: !!(e.action && (!e.actionLe || e.actionLe <= AUJ)), enRetard: !!(e.action && e.actionLe && e.actionLe < AUJ),
          pers: l.personnes != null ? l.personnes : (l.grand === true ? 35 : (l.grand === false ? 8 : 15)) });
      });
    }
    maListe() {
      // Ce qui est à faire passe devant (le retard d'abord), puis le statut, puis la distance.
      const ordre = { '': 0, rappeler: 1, rdv: 2, interesse: 2, visite: 3, client: 4, refus: 5 };
      const rang = l => l.enRetard ? -2 : (l.aFaire ? -1 : 0);
      return this.lieux().filter(l => l.dans).sort((a, b) => (rang(a) - rang(b)) || ((ordre[a.st] || 0) - (ordre[b.st] || 0)) || (a.dKm - b.dKm));
    }
    aFaire() { return this.lieux().filter(l => l.dans && l.action); }
    candidats(tous) {
      const s = this.s, q = s.q.trim().toLowerCase();
      return tous.filter(l => !l.dans && (!s.fam || l.famille === s.fam) && (!s.grand || l.grand === true) && (!q || (l.nom + ' ' + l.genre + ' ' + l.adresse + ' ' + (l.zoning || '')).toLowerCase().includes(q)));
    }

    /* --- rendu ----------------------------------------------------------- */
    render() {
      if (!this.el) return;
      const foc = document.activeElement, focK = foc && this.el.contains(foc) ? foc.getAttribute('data-f') : null;
      const sel = focK && foc.selectionStart != null ? [foc.selectionStart, foc.selectionEnd] : null;
      this.el.innerHTML = this.opts.mobile ? this.htmlMobile() : this.htmlBureau();
      if (focK) { const e = this.el.querySelector('[data-f="' + focK + '"]'); if (e) { e.focus(); if (sel && e.setSelectionRange) { try { e.setSelectionRange(sel[0], sel[1]); } catch (x) { /* type sans sélection */ } } } }
      this.apresRendu();
    }
    stat(st) { return ST.find(x => x[0] === st) || (st === 'interesse' ? ['interesse', 'Intéressé', 'ok'] : ST[0]); }
    maps(l) { return 'https://www.google.com/maps/search/?api=1&query=' + l.lat + ',' + l.lng; }
    adr(l) { return l.adresse ? esc(l.adresse) : '<span class="mu">sans adresse</span>'; }
    taille(l) { return l.personnes != null ? l.personnes + ' ' + l.personnesDe : (l.grand === true ? '20 pers. et +' : (l.grand === false ? '< 20 pers.' : 'taille ?')); }
    pastille(l) { const st = this.stat(l.st); return `<button class="pr-st ${st[2]}" data-a="st" data-v="${esc(l.id)}" title="Toucher pour avancer le statut">${st[1]}</button>`; }
    htmlListe(L) {
      const s = this.s, tous = L, af = this.aFaire();
      if (!tous.length) return `<div class="pr-vide">Ma liste est vide. <button class="pr-btn" data-a="vue" data-v="choisir">＋ Créer ma liste</button></div>`;
      const montre = s.filtre === 'afaire' ? tous.filter(l => l.action) : tous;
      const filtres = `<div class="pr-filtres"><button class="pr-chip ${s.filtre !== 'afaire' ? 'on' : ''}" data-a="filtre" data-v="tout">Ma liste <em>${tous.length}</em></button><button class="pr-chip ${s.filtre === 'afaire' ? 'on' : ''}" data-a="filtre" data-v="afaire">À faire <em>${af.length}</em>${af.some(l => l.enRetard) ? '<i class="rt"></i>' : ''}</button></div>`;
      if (!montre.length) return filtres + `<div class="pr-vide">Rien à faire : choisissez une action à suivre sur un lieu de ma liste.</div>`;
      return filtres + `<div class="pr-liste">${montre.map(l => `
        <div class="pr-it ${l.st === 'client' ? 'cli' : ''}">
          <div class="pr-l1"><b>${esc(l.nom)}</b><span class="pr-fam" style="background:${COUL[l.famille] || '#888'}">${esc(NOM[l.famille] || l.famille)}</span><span class="sp"></span>${this.pastille(l)}<button class="pr-x" data-a="del" data-v="${esc(l.id)}" title="Retirer de ma liste">×</button></div>
          <div class="pr-l2">${this.adr(l)} <a href="${esc(this.maps(l))}" target="_blank" rel="noopener">📍</a>${l.tel ? ` · <a href="tel:${esc(l.tel.replace(/\s+/g, ''))}">${esc(l.tel)}</a>` : ''} · ${l.dKm.toFixed(1).replace('.', ',')} km · ${esc(this.taille(l))}${l.zoning ? ' · ' + esc(l.zoning) : ''}</div>
          ${this.htmlOffre(l)}
          <div class="pr-l3"><label>Visite <input type="date" value="${esc(l.visite)}" data-f="date-${esc(l.id)}" data-c="date" data-v="${esc(l.id)}"></label><input type="text" placeholder="Note — part au CRM" value="${esc(l.note)}" data-f="note-${esc(l.id)}" data-c="note" data-v="${esc(l.id)}">${l.note ? '<span class="pr-crm">→ CRM</span>' : ''}</div>
          <div class="pr-l4 ${l.action ? (l.enRetard ? 'retard' : (l.aFaire ? 'auj' : 'plus-tard')) : ''}"><span class="pr-act-ic">${l.action ? (l.enRetard ? '⚑' : '→') : '☐'}</span><select data-c="action" data-v="${esc(l.id)}">${ACT.map(a => `<option value="${a[0]}"${l.action === a[0] ? ' selected' : ''}>${esc(a[1])}</option>`).join('')}</select>${l.action ? `<label>le <input type="date" value="${esc(l.actionLe)}" data-f="actle-${esc(l.id)}" data-c="actionLe" data-v="${esc(l.id)}"></label><span class="pr-act-txt">${l.enRetard ? 'en retard depuis le ' + fD(l.actionLe) : (l.actionLe ? (l.actionLe === AUJ ? 'aujourd’hui' : 'le ' + fD(l.actionLe)) : 'sans date : à faire')}</span><button class="pr-fait" data-a="fait" data-v="${esc(l.id)}" title="C’est fait">✓ fait</button>` : ''}</div>
        </div>`).join('')}</div>`;
    }
    /** L'offre à proposer : un choix, et l'argument à dire au comptoir. */
    htmlOffre(l) {
      const O = offres(); if (!O.length) return '';
      const o = offreParId(l.offre) || O[0];
      return `<div class="pr-offre"><span class="pr-offre-lab">Offre</span><select data-c="offre" data-v="${esc(l.id)}">${O.map(x => `<option value="${x.id}"${x.id === l.offre ? ' selected' : ''}>${esc(x.nom)}</option>`).join('')}</select>${l.offreChoisie ? '' : '<span class="pr-offre-auto" title="proposée d’après le genre du lieu">auto</span>'}<span class="pr-offre-pitch">${esc(o.pitch)}</span><span class="pr-offre-contenu">${esc(o.contenu)} · ${nf(o.depense, 2)} € / pers. · ${nf(o.commandes, o.commandes % 1 ? 1 : 0)} cde / sem.</span></div>`;
    }
    htmlChoisir(tous) {
      const s = this.s, compte = {}; tous.forEach(l => { compte[l.famille] = (compte[l.famille] || 0) + 1; });
      const cand = this.candidats(tous);
      return `
        <div class="pr-chips">${FAM.filter(f => compte[f[0]]).map(f => `<button class="pr-chip ${s.fam === f[0] ? 'on' : ''}" data-a="fam" data-v="${f[0]}"><i style="background:${f[2]}"></i>${esc(f[1])} <em>${compte[f[0]]}</em></button>`).join('')}</div>
        <div class="pr-outils"><label class="pr-tog"><input type="checkbox" ${s.grand ? 'checked' : ''} data-c="grand"> 20 personnes et plus</label>
          <input type="search" placeholder="Chercher…" value="${esc(s.q)}" data-f="q" data-c="q"><span class="sp"></span>
          <button class="pr-btn" data-a="tout" ${cand.length ? '' : 'disabled'}>Tout ajouter · ${cand.length}</button></div>
        <div class="pr-cand">${cand.slice(0, 120).map(l => `
          <button class="pr-c" data-a="add" data-v="${esc(l.id)}">
            <span class="plus">＋</span>
            <span class="tx"><b>${esc(l.nom)}</b><small>${esc(l.genre)} · ${l.dKm.toFixed(1).replace('.', ',')} km · ${esc(this.taille(l))}${l.zoning ? ' · ' + esc(l.zoning) : ''}</small><small>${this.adr(l)}${offreParId(l.offre) ? ' · <i>' + esc(offreParId(l.offre).nom) + '</i>' : ''}</small></span>
            <i style="background:${COUL[l.famille] || '#888'}"></i>
          </button>`).join('')}${cand.length > 120 ? `<div class="pr-vide">… et ${cand.length - 120} autres : affinez avec les types ou la recherche.</div>` : ''}${cand.length ? '' : '<div class="pr-vide">Tout est déjà dans ma liste, ou rien ne passe les filtres.</div>'}</div>`;
    }
    calcul(tous) {
      // Par type d'offre : chaque offre a sa dépense par personne, ses commandes par
      // semaine et sa part atteignable — c'est là que l'hôpital diffère de la commune.
      const c = this.s.calc, O = offres();
      const parAn = (o, pers) => pers * o.depense * o.commandes * c.semaines;
      const rows = O.map(o => {
        const L = tous.filter(l => l.offre === o.id); if (!L.length) return null;
        const pers = L.reduce((t, l) => t + l.pers, 0);
        const clients = L.filter(l => l.st === 'client'), enCours = L.filter(l => l.dans && l.st !== 'client' && l.st !== 'refus');
        const possible = parAn(o, pers), atteignable = possible * o.part / 100, capte = clients.reduce((t, l) => t + parAn(o, l.pers), 0);
        return { o, f: [o.id, o.nom, this.coulOffre(o.id)], n: L.length, pers, clients: clients.length, enCours: enCours.length, pen: clients.length / L.length * 100, possible, atteignable, capte };
      }).filter(Boolean);
      const T = rows.reduce((t, r) => ({ n: t.n + r.n, pers: t.pers + r.pers, clients: t.clients + r.clients, enCours: t.enCours + r.enCours, possible: t.possible + r.possible, atteignable: t.atteignable + r.atteignable, capte: t.capte + r.capte }),
        { n: 0, pers: 0, clients: 0, enCours: 0, possible: 0, atteignable: 0, capte: 0 });
      return { rows, T };
    }
    coulOffre(id) { return { bureau: '#8D1D2C', equipe: '#78554B', ecole: '#C17A2A', soins: '#2d7a3e', commune: '#4a5a8a', formation: '#B26A00', funeraire: '#555', sport: '#1baf7a', hotel: '#a34a8c', personnel: '#9a8c6a' }[id] || '#888'; }
    htmlCalcul(tous) {
      const c = this.s.calc, s = this.s, { rows, T } = this.calcul(tous), O = offres();
      const num = (o, k, min, max, step) => `<input type="number" min="${min}" max="${max}" step="${step}" value="${o[k]}" data-f="offre-${o.id}-${k}" data-c="offreval" data-v="${o.id}|${k}" class="pr-oin">`;
      const partMoy = T.possible ? T.atteignable / T.possible * 100 : 0;
      return `
        <div class="pr-pars pr-pars1"><label class="pr-par"><span>Semaines par an</span><input type="number" min="20" max="52" step="1" value="${c.semaines}" data-f="calc-semaines" data-c="calc" data-v="semaines"><em>sem.</em></label>
          <div class="pr-par-note">Dépense, commandes par semaine et part atteignable se règlent <b>par type d'offre</b> (tableau des offres ci-dessous) : un hôpital ne commande pas comme une maison communale.</div></div>
        <div class="pr-tot"><div><span class="k">CA possible par an</span><b>${fK(T.possible)}</b><small>${fN(T.pers)} personnes dans ${T.n} lieux</small></div>
          <div><span class="k">Atteignable (${fP(partMoy)})</span><b>${fK(T.atteignable)}</b><small>l'objectif de démarchage</small></div>
          <div><span class="k">Capté aujourd'hui</span><b class="${T.capte ? 'ok' : 'mu'}">${fK(T.capte)}</b><small>${T.clients} client${T.clients > 1 ? 's' : ''} · ${T.enCours} en cours</small></div>
          <div><span class="k">Pénétration</span><b>${T.n ? fP(T.clients / T.n * 100) : '—'}</b><small>clients ÷ lieux</small></div></div>
        <div class="pr-tab"><div class="th"><span>Type d'offre</span><span>Lieux</span><span>Pers.</span><span>Clients</span><span>Pénétr.</span><span>CA possible</span><span>Atteignable</span><span>Capté</span></div>
          ${rows.map(r => `<div class="tr"><span><i style="background:${r.f[2]}"></i>${esc(r.f[1])}</span><span>${r.n}</span><span>${fN(r.pers)}</span><span>${r.clients}${r.enCours ? ` <small>+${r.enCours}</small>` : ''}</span><span>${fP(r.pen)}</span><span>${fK(r.possible)}</span><span>${fK(r.atteignable)}</span><span class="${r.capte ? 'ok' : 'mu'}">${fK(r.capte)}</span></div>`).join('')}
        </div>
        <div class="pr-offres"><div class="pr-offres-hd"><b>Les offres</b><button class="pr-btn" data-a="offres-plis">${s.offresOuvert ? 'Replier' : 'Régler les hypothèses'}</button></div>
          ${s.offresOuvert ? `<div class="pr-otab"><div class="th"><span>Offre</span><span>€ / pers. / cde</span><span>cde / sem.</span><span>part %</span></div>
            ${O.map(o => `<div class="tr"><span><i style="background:${this.coulOffre(o.id)}"></i><b>${esc(o.nom)}</b><small>${esc(o.contenu)}</small></span><span>${num(o, 'depense', 0.5, 200, 0.5)}</span><span>${num(o, 'commandes', 0.25, 14, 0.25)}</span><span>${num(o, 'part', 1, 100, 1)}</span></div>`).join('')}
            <div class="pr-note">Ces hypothèses sont celles du réseau : réglées ici, elles valent pour tous les magasins, au bureau comme sur le téléphone.</div></div>` : ''}
        </div>
        <div class="pr-note">Personnes : le chiffre de la carte (employés, élèves, lits) quand il existe, sinon 35 pour un lieu de 20 personnes et plus, 8 pour un petit, 15 quand on ne sait pas. CA possible = personnes × dépense de l'offre × commandes par semaine de l'offre × semaines. Capté = les lieux au statut « Client » de ma liste, au tarif de leur offre.</div>`;
    }
    garde() {
      const s = this.s, m = this.magasin();
      if (s.err && !s.lieux) return `<div class="pr-err">Prospection : ${esc(s.err)}</div>`;
      if (!s.reseau) return `<div class="pr-attente">Lecture du magasin…</div>`;
      if (!this.shop()) return `<div class="pr-alerte">Choisissez un magasin.</div>`;
      if (!m) return `<div class="pr-alerte">Ce magasin n'a pas de position : pointez-le sur la carte dans Scouting (Magasins du réseau).</div>`;
      if (!s.lieux || s.lieux.shop !== this.shop()) return `<div class="pr-attente">Relevé des lieux autour du magasin dans OpenStreetMap — une minute la première fois…</div>`;
      return '';
    }
    htmlBureau() {
      const s = this.s, g = this.garde(); if (g) return g;
      const tous = this.lieux(), L = this.maListe();
      return `
        <div class="pr-hd"><div class="pr-nav">${[['liste', 'Ma liste · ' + L.length], ['choisir', '＋ Créer ma liste'], ['calcul', 'Pénétration & CA']].map(v => `<button class="${s.vue === v[0] ? 'on' : ''}" data-a="vue" data-v="${v[0]}">${v[1]}</button>`).join('')}</div>
          <span class="sp"></span><span class="pr-lab">Rayon</span><select class="pr-sel" data-c="r">${RAYONS.map(r => `<option value="${r}" ${s.r === r ? 'selected' : ''}>${r / 1000} km</option>`).join('')}</select>
          <button class="pr-btn" data-a="relire">↻ Relire</button>
          <span class="pr-mini">${tous.length} lieux relevés · ${L.filter(l => l.st === 'client').length} client(s) · ${L.filter(l => l.note).length} note(s) → CRM</span></div>
        <div class="pr-grid">
          <div class="pr-card pr-col"><div class="ct"><b>${s.vue === 'choisir' ? 'Choisir les lieux à démarcher' : (s.vue === 'calcul' ? 'Pénétration et CA possible par secteur' : 'Ma liste de démarchage')}</b><span class="pr-mini">${s.vue === 'choisir' ? 'un tap ajoute le lieu' : (s.vue === 'calcul' ? 'réglages gardés sur ce poste' : 'un tap sur le statut le fait avancer · la note part au CRM')}</span></div>
            <div class="in">${s.vue === 'choisir' ? this.htmlChoisir(tous) : (s.vue === 'calcul' ? this.htmlCalcul(tous) : this.htmlListe(L))}</div></div>
          <div class="pr-col">
            ${s.vue === 'liste' && this.aFaire().length ? `<div class="pr-card"><div class="ct"><b>À faire</b><span class="pr-mini">les actions décidées sur place, la plus urgente d’abord</span></div><div class="in pr-afaire">${this.aFaire().sort((a, b) => (a.actionLe || '9') < (b.actionLe || '9') ? -1 : 1).slice(0, 8).map(l => `<div class="${l.enRetard ? 'retard' : (l.aFaire ? 'auj' : '')}"><b>${esc(ACT_NOM[l.action] || l.action)}</b> · ${esc(l.nom)}<small>${l.actionLe ? (l.enRetard ? 'en retard · ' : '') + fD(l.actionLe) : 'sans date'}</small></div>`).join('')}</div></div>` : ''}
            <div class="pr-card"><div class="ct"><b>Carte des concentrations</b><span class="pr-mini">un rond par zoning ou grappe, sa taille dit le nombre de lieux ; ma liste en rubis ; un clic ajoute un lieu</span></div><div class="pr-carte" data-carte></div></div>
            ${s.vue === 'calcul' ? '' : `<div class="pr-card"><div class="ct"><b>Pénétration et CA possible par secteur</b></div><div class="in">${this.htmlCalcul(tous)}</div></div>`}
          </div>
        </div>`;
    }
    htmlMobile() {
      const s = this.s, g = this.garde();
      const tous = g ? [] : this.lieux(), L = g ? [] : this.maListe();
      let corps = g;
      if (!g) {
        if (s.vue === 'choisir') corps = this.htmlChoisir(tous);
        else if (s.vue === 'carte') corps = `<div class="pr-carte mob" data-carte></div>`;
        else if (s.vue === 'calcul') corps = this.htmlCalcul(tous);
        else corps = this.htmlListe(L);
      }
      return `<div class="pr-nav mob">${[['liste', 'Ma liste' + (L.length ? ' · ' + L.length : '')], ['choisir', '＋ Créer'], ['carte', 'Carte'], ['calcul', 'CA']].map(v => `<button class="${s.vue === v[0] ? 'on' : ''}" data-a="vue" data-v="${v[0]}">${v[1]}</button>`).join('')}</div>
        <div class="pr-sc">${corps}</div>`;
    }

    /* --- la carte : un nœud Leaflet gardé d'un rendu à l'autre ------------- */
    leaflet() {
      if (window.L) return Promise.resolve();
      if (Hote.leafletP) return Hote.leafletP;
      const dir = this.opts.leafletDir || (apiBase().replace(/api\/cockpit$/, '') + 'assets/vendor/leaflet/');
      Hote.leafletP = new Promise((res, rej) => {
        const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = dir + 'leaflet.css'; document.head.appendChild(css);
        const js = document.createElement('script'); js.src = dir + 'leaflet.js'; js.onload = res; js.onerror = () => rej(new Error('Leaflet introuvable')); document.head.appendChild(js);
      });
      return Hote.leafletP;
    }
    apresRendu() {
      const place = this.el.querySelector('[data-carte]');
      if (!place || !this.s.lieux) return;
      this.leaflet().then(() => {
        if (!this.el.isConnected) return;
        const m = this.magasin(); if (!m) return;
        if (!this.carteEl) { this.carteEl = document.createElement('div'); this.carteEl.style.cssText = 'width:100%;height:100%'; }
        place.appendChild(this.carteEl);
        if (!this.carte) {
          this.carte = L.map(this.carteEl, { zoomControl: true, attributionControl: true }).setView([m.lat, m.lng], 13);
          L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(this.carte);
          this.couches = L.layerGroup().addTo(this.carte);
          this.carteShop = this.shop();
        } else if (this.carteShop !== this.shop()) { this.carte.setView([m.lat, m.lng], 13); this.carteShop = this.shop(); }
        this.carte.invalidateSize();
        this.couches.clearLayers();
        const tous = this.lieux();
        L.circleMarker([m.lat, m.lng], { radius: 9, color: '#fff', weight: 2, fillColor: '#222', fillOpacity: 1 }).bindTooltip('Le magasin').addTo(this.couches);
        // les concentrations : par zoning nommé, sinon par grappe de 400 m
        const grappes = {};
        tous.forEach(l => {
          const k = l.zoning ? 'z|' + l.zoning : 'g|' + Math.round(l.lat / 0.0036) + '|' + Math.round(l.lng / 0.0056);
          const g = grappes[k] || (grappes[k] = { nom: l.zoning || '', n: 0, la: 0, lo: 0, pers: 0, fam: {} });
          g.n++; g.la += l.lat; g.lo += l.lng; g.pers += l.pers; g.fam[l.famille] = (g.fam[l.famille] || 0) + 1;
        });
        Object.values(grappes).filter(g => g.n >= 3).forEach(g => {
          const fams = Object.entries(g.fam).sort((a, b) => b[1] - a[1]), dom = fams[0][0];
          L.circle([g.la / g.n, g.lo / g.n], { radius: 120 + Math.sqrt(g.n) * 70, color: COUL[dom] || '#888', weight: 1, fillColor: COUL[dom] || '#888', fillOpacity: 0.16 })
            .bindTooltip((g.nom || 'Grappe') + ' — ' + g.n + ' lieux, ' + fN(g.pers) + ' personnes · ' + fams.slice(0, 3).map(e => e[1] + ' ' + (NOM[e[0]] || e[0]).toLowerCase()).join(', '))
            .addTo(this.couches);
        });
        tous.forEach(l => {
          L.circleMarker([l.lat, l.lng], { radius: l.dans ? 6 : 3.5, color: l.dans ? '#fff' : COUL[l.famille] || '#888', weight: l.dans ? 1.5 : 0.8, fillColor: l.dans ? '#8D1D2C' : COUL[l.famille] || '#888', fillOpacity: l.dans ? 1 : 0.7 })
            .bindTooltip('<b>' + esc(l.nom) + '</b><br>' + esc(l.genre) + ' · ' + esc(this.taille(l)) + (l.adresse ? '<br>' + esc(l.adresse) : '') + (l.dans ? '<br><i>dans ma liste · ' + this.stat(l.st)[1] + '</i>' : '<br><i>cliquer pour l’ajouter</i>'))
            .on('click', () => { if (!l.dans) { set(this.shop(), l.id, { coche: true }, l.nom).then(() => this.render()); this.render(); } })
            .addTo(this.couches);
        });
      }).catch(e => { place.innerHTML = '<div class="pr-note" style="padding:12px">Carte indisponible : ' + esc(e.message) + '</div>'; });
    }

    /* --- gestes ---------------------------------------------------------- */
    onClick(e) {
      const b = e.target.closest('[data-a]'); if (!b || !this.el.contains(b)) return;
      const a = b.dataset.a, v = b.dataset.v, s = this.s;
      if (a === 'vue') { s.vue = v; this.render(); }
      else if (a === 'fam') { s.fam = s.fam === v ? null : v; this.render(); }
      else if (a === 'filtre') { s.filtre = v; this.render(); }
      else if (a === 'offres-plis') { s.offresOuvert = !s.offresOuvert; this.render(); }
      else if (a === 'fait') {
        // l'action est faite : elle s'efface, la note en garde la trace
        const l = this.lieux().find(x => x.id === v); if (!l) return;
        const trace = (ACT_NOM[l.action] || l.action) + ' fait le ' + fD(AUJ);
        set(this.shop(), v, { action: '', actionLe: '', note: (l.note ? l.note + ' · ' : '') + trace }, l.nom).then(() => this.render()); this.render();
      }
      else if (a === 'relire') { this.charger(true); etatCharger(this.shop(), true); this.render(); }
      else if (a === 'add') { const l = this.lieux().find(x => x.id === v); set(this.shop(), v, { coche: true }, l && l.nom).then(() => this.render()); this.render(); }
      else if (a === 'tout') {
        const cand = this.candidats(this.lieux());
        const lieux = {}; cand.forEach(l => { lieux[l.id] = { coche: true, nom: l.nom }; });
        const par = STORE.etats[this.shop()] || (STORE.etats[this.shop()] = {});
        cand.forEach(l => { const cur = par[l.id] || (par[l.id] = { coche: false, visite: '', retour: '', note: '' }); cur.coche = true; cur.nom = l.nom; });
        ecrire('PUT', '/prospection/' + this.shop(), { lieux }).catch(err => console.warn('[prospection] ' + err.message));
        s.vue = 'liste'; this.render();
      }
      else if (a === 'del') { set(this.shop(), v, { coche: false }).then(() => this.render()); this.render(); }
      else if (a === 'st') {
        const l = this.lieux().find(x => x.id === v); if (!l) return;
        const i = ST.findIndex(x => x[0] === l.st); const nx = ST[(i + 1) % ST.length][0];
        // « visité » est une date, pas un retour : ce pas pose la visite à aujourd'hui
        const patch = nx === 'visite' ? { retour: '', visite: AUJ } : (nx === '' ? { retour: '', visite: '' } : { retour: nx, visite: l.visite || AUJ });
        set(this.shop(), v, patch, l.nom).then(() => this.render()); this.render();
      }
    }
    onChange(e) {
      const b = e.target.closest('[data-c]'); if (!b || !this.el.contains(b)) return;
      const c = b.dataset.c, v = b.dataset.v, s = this.s;
      if (c === 'grand') { s.grand = b.checked; this.render(); }
      else if (c === 'q') { s.q = b.value; this.render(); }
      else if (c === 'r') { s.r = +b.value; this.charger(false); this.render(); }
      else if (c === 'date') { const l = this.lieux().find(x => x.id === v); set(this.shop(), v, { visite: b.value }, l && l.nom).then(() => this.render()); this.render(); }
      else if (c === 'note') { const l = this.lieux().find(x => x.id === v); set(this.shop(), v, { note: b.value }, l && l.nom).then(() => this.render()); this.render(); }
      else if (c === 'action') { const l = this.lieux().find(x => x.id === v); set(this.shop(), v, b.value ? { action: b.value, actionLe: (l && l.actionLe) || AUJ } : { action: '', actionLe: '' }, l && l.nom).then(() => this.render()); this.render(); }
      else if (c === 'actionLe') { const l = this.lieux().find(x => x.id === v); set(this.shop(), v, { actionLe: b.value }, l && l.nom).then(() => this.render()); this.render(); }
      else if (c === 'offre') { const l = this.lieux().find(x => x.id === v); set(this.shop(), v, { offre: b.value === offreDe(l, '') ? '' : b.value }, l && l.nom).then(() => this.render()); this.render(); }
      else if (c === 'offreval') {
        const [id, k] = v.split('|'); const o = offreParId(id); if (!o) return;
        o[k] = +b.value || o[k];
        ecrire('PUT', '/prospection/offres', { offres: offres().map(x => ({ id: x.id, depense: x.depense, commandes: x.commandes, part: x.part })) })
          .then(r => { if (r && r.offres) STORE.offres = r.offres; this.render(); }).catch(err => console.warn('[prospection] offres : ' + err.message));
        this.render();
      }
      else if (c === 'calc') {
        s.calc[v] = +b.value || s.calc[v];
        try { localStorage.setItem('ceo_prospection_calc', JSON.stringify(s.calc)); } catch (x) { /* rien */ }
        this.render();
      }
    }
  }

  /* --- styles : posés une fois, tokens du design system ----------------------- */
  const CSS = `
.pr{font-family:var(--font-ui);color:var(--color-text);font-size:13px}
.pr .mu{color:var(--color-text-muted)}.pr .ok{color:#2d7a3e}
.pr-hd{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 12px}
.pr .sp{flex:1}
.pr-lab{font:600 10px var(--font-ui);letter-spacing:.07em;text-transform:uppercase;color:var(--color-text-muted)}
.pr-mini{font-size:10.5px;color:var(--color-text-muted)}
.pr-sel{font-family:var(--font-ui);font-size:12.5px;padding:6px 9px;border-radius:8px;border:.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text)}
.pr-btn{border:.5px solid var(--color-border-secondary);background:var(--color-surface);border-radius:8px;padding:6px 11px;font:500 12px var(--font-ui);cursor:pointer;color:var(--color-text)}
.pr-btn:hover{background:var(--color-background-secondary)}.pr-btn[disabled]{opacity:.5;cursor:default}
.pr-nav{display:inline-flex;background:var(--color-background-secondary);border-radius:10px;padding:3px;gap:2px}
.pr-nav button{border:none;background:none;border-radius:8px;padding:7px 14px;font:600 12px var(--font-ui);color:var(--color-text-muted);cursor:pointer;white-space:nowrap}
.pr-nav button.on{background:var(--color-primary);color:#fff}
.pr-nav.mob{display:grid;grid-template-columns:repeat(4,1fr);margin:0 14px 8px;flex:0 0 auto}
.pr-nav.mob button{padding:9px 4px;font-size:11.5px}
.pr-grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:14px;align-items:start}
@media (max-width:1000px){.pr-grid{grid-template-columns:1fr}}
.pr-col{min-width:0}
.pr-card{background:var(--color-surface);border:.5px solid var(--color-border-tertiary);border-radius:12px;margin-bottom:12px}
.pr-card>.ct{padding:11px 16px;border-bottom:.5px solid var(--color-border-tertiary);display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.pr-card>.ct .pr-mini{margin-left:auto}
.pr-card>.in{padding:12px 16px 14px}
.pr-carte{height:360px;border-radius:0 0 12px 12px;overflow:hidden;background:#EAE4DC}
.pr-carte.mob{height:calc(100dvh - 150px);border-radius:12px;margin-top:4px}
.pr-sc{padding:4px 14px 16px}
.pr-attente{padding:60px 0;color:var(--color-text-muted);font-size:13px;text-align:center}
.pr-alerte{background:#FBEFE0;border:1px solid #E8C9A0;color:var(--color-on-abricot);border-radius:9px;padding:8px 12px;font-size:12px;margin:12px}
.pr-err{background:#F6E4E7;border:1px solid #e8b4bb;color:var(--color-primary);border-radius:9px;padding:8px 12px;font-size:12px;margin:12px}
.pr-note{font-size:11px;line-height:1.5;color:var(--color-text-muted);margin-top:8px}
.pr-vide{padding:22px 8px;font-size:12.5px;color:var(--color-text-muted);text-align:center}
.pr-vide .pr-btn{margin-left:8px}
.pr-liste{display:flex;flex-direction:column;gap:8px}
.pr-it{border:.5px solid var(--color-border-tertiary);border-radius:12px;padding:9px 11px 8px;background:var(--color-surface);display:flex;flex-direction:column;gap:5px}
.pr-it.cli{border-color:#bfdcc6;background:#f4faf5}
.pr-l1{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.pr-l1 b{font-size:13.5px;line-height:1.2}
.pr-fam{font:600 9px var(--font-ui);letter-spacing:.05em;text-transform:uppercase;color:#fff;border-radius:999px;padding:2px 7px;white-space:nowrap}
.pr-st{border:none;border-radius:999px;padding:5px 12px;font:700 11px var(--font-ui);cursor:pointer;background:var(--color-background-secondary);color:var(--color-text);min-height:30px;white-space:nowrap}
.pr-st.ok{background:#E6F2E9;color:#2d7a3e}.pr-st.wa{background:#FBEFE0;color:#B26A00}.pr-st.ko{background:#F7E4E6;color:#C0182B}.pr-st.mu{background:var(--color-background-secondary);color:var(--color-text-muted)}
.pr-l2{font-size:11.5px;color:var(--color-text-muted);line-height:1.4}
.pr-offre{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:11.5px;color:var(--color-text-muted);padding:2px 0}
.pr-offre-lab{font:600 10px var(--font-ui);letter-spacing:.05em;text-transform:uppercase;color:var(--color-text-muted)}
.pr-offre select{border:.5px solid var(--color-border-secondary);border-radius:8px;padding:5px 8px;font:600 11.5px var(--font-ui);background:var(--color-surface);color:var(--color-primary);min-height:30px;max-width:100%}
.pr-offre-auto{font:600 9px var(--font-ui);letter-spacing:.05em;text-transform:uppercase;padding:2px 6px;border-radius:999px;background:var(--color-background-secondary);color:var(--color-text-muted)}
.pr-offre-pitch{flex:1 1 100%;font-size:11.5px;color:var(--color-text);line-height:1.4;font-style:italic}
.pr-offre-contenu{flex:1 1 100%;font-size:10.5px;color:var(--color-text-muted)}
.pr-pars.pr-pars1{grid-template-columns:minmax(0,170px) minmax(0,1fr);align-items:center}
.pr-par-note{font-size:11px;color:var(--color-text-muted);line-height:1.45}
.pr-offres{margin-top:10px;border-top:.5px solid var(--color-border-tertiary);padding-top:8px}
.pr-offres-hd{display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:12.5px}
.pr-otab{margin-top:8px}
.pr-otab .th,.pr-otab .tr{display:grid;grid-template-columns:minmax(0,1fr) 92px 84px 70px;gap:8px;align-items:center;padding:6px 0;border-bottom:.5px solid var(--color-border-tertiary);font-size:11.5px}
.pr-otab .th{font:600 9.5px var(--font-ui);letter-spacing:.05em;text-transform:uppercase;color:var(--color-text-muted)}
.pr-otab .tr span:first-child{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.pr-otab .tr i{width:8px;height:8px;border-radius:50%;flex:0 0 auto}
.pr-otab .tr small{flex:1 1 100%;color:var(--color-text-muted);font-size:10.5px;padding-left:14px}
.pr-oin{border:.5px solid var(--color-border-secondary);border-radius:8px;padding:5px 7px;font:600 12px var(--font-ui);background:var(--color-surface);color:var(--color-text);width:100%;box-sizing:border-box;min-height:32px}
@media (max-width:640px){.pr-pars.pr-pars1{grid-template-columns:1fr}.pr-otab .th,.pr-otab .tr{grid-template-columns:minmax(0,1fr) 70px 62px 56px;gap:5px}}
.pr-l4{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:11.5px;color:var(--color-text-muted);padding-top:2px}
.pr-l4 select{border:.5px solid var(--color-border-secondary);border-radius:8px;padding:6px 8px;font:600 11.5px var(--font-ui);background:var(--color-surface);color:var(--color-text);min-height:32px;max-width:100%}
.pr-l4 label{display:inline-flex;align-items:center;gap:4px;font:600 10px var(--font-ui);letter-spacing:.05em;text-transform:uppercase;color:var(--color-text-muted)}
.pr-l4 input{border:.5px solid var(--color-border-secondary);border-radius:8px;padding:5px 8px;font:500 12px var(--font-ui);background:var(--color-surface);color:var(--color-text);min-height:32px}
.pr-act-ic{width:18px;text-align:center;font-size:13px}
.pr-l4.auj{color:#B26A00}.pr-l4.auj .pr-act-ic{color:#B26A00}.pr-l4.retard{color:#C0182B}.pr-l4.retard .pr-act-ic{color:#C0182B}.pr-l4.plus-tard{color:var(--color-text-muted)}
.pr-act-txt{font-weight:600}
.pr-fait{border:none;border-radius:999px;padding:5px 10px;font:700 11px var(--font-ui);cursor:pointer;background:#E6F2E9;color:#2d7a3e;min-height:30px}
.pr-filtres{display:flex;gap:6px;margin-bottom:8px;align-items:center}
.pr-filtres .rt{display:inline-block;width:7px;height:7px;border-radius:50%;background:#C0182B;margin-left:4px}
.pr-afaire>div{padding:6px 0;border-bottom:.5px solid var(--color-border-tertiary);font-size:12px;display:flex;justify-content:space-between;gap:8px;align-items:center}
.pr-afaire>div small{color:var(--color-text-muted);white-space:nowrap}
.pr-afaire>div.auj{color:#B26A00}.pr-afaire>div.retard{color:#C0182B}
.pr-l2 a{color:var(--color-primary);text-decoration:none}
.pr-l3{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.pr-l3 label{display:inline-flex;align-items:center;gap:5px;font:600 10px var(--font-ui);letter-spacing:.05em;text-transform:uppercase;color:var(--color-text-muted)}
.pr-l3 input{border:.5px solid var(--color-border-secondary);border-radius:8px;padding:7px 9px;font:500 12px var(--font-ui);background:var(--color-surface);color:var(--color-text);min-height:34px}
.pr-l3 input[type=text]{flex:1;min-width:140px}
.pr-crm{font:700 9.5px var(--font-ui);letter-spacing:.05em;padding:3px 8px;border-radius:999px;background:#E6F2E9;color:#2d7a3e;white-space:nowrap}
.pr-x{border:none;background:none;color:var(--color-text-muted);font-size:18px;cursor:pointer;width:30px;height:30px;border-radius:50%;margin-right:-6px}
.pr-x:hover{background:var(--color-background-secondary)}
.pr-chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px}
.pr-chip{display:inline-flex;align-items:center;gap:6px;border:.5px solid var(--color-border-secondary);background:var(--color-surface);border-radius:999px;padding:6px 11px;font:600 11.5px var(--font-ui);color:var(--color-text);cursor:pointer;min-height:32px}
.pr-chip i{width:9px;height:9px;border-radius:50%;display:inline-block}
.pr-chip em{font-style:normal;color:var(--color-text-muted);font-weight:500}
.pr-chip.on{background:var(--color-text);border-color:var(--color-text);color:#fff}
.pr-chip.on em{color:rgba(255,255,255,.75)}
.pr-outils{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.pr-tog{display:inline-flex;align-items:center;gap:6px;font:600 12px var(--font-ui);cursor:pointer}
.pr-tog input{accent-color:var(--color-primary);width:16px;height:16px}
.pr-outils input[type=search]{border:.5px solid var(--color-border-secondary);border-radius:999px;padding:7px 12px;font:500 12px var(--font-ui);background:var(--color-surface);color:var(--color-text);min-width:150px;flex:1}
.pr-cand{display:flex;flex-direction:column;gap:6px;max-height:560px;overflow-y:auto;-webkit-overflow-scrolling:touch}
.pr-mobile .pr-cand{max-height:none;overflow:visible}
.pr-c{display:flex;align-items:center;gap:10px;width:100%;text-align:left;border:.5px solid var(--color-border-tertiary);background:var(--color-surface);border-radius:12px;padding:8px 10px;cursor:pointer;font-family:var(--font-ui);color:var(--color-text);min-height:52px}
.pr-c:active{background:var(--color-background-secondary)}
.pr-c .plus{width:32px;height:32px;border-radius:50%;background:var(--color-primary);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:18px;flex:0 0 auto}
.pr-c .tx{flex:1;min-width:0}
.pr-c .tx b{display:block;font-size:13px;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pr-c .tx small{display:block;font-size:10.5px;color:var(--color-text-muted);line-height:1.35;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pr-c i{width:9px;height:9px;border-radius:50%;flex:0 0 auto}
.pr-pars{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:10px}
@media (max-width:640px){.pr-pars{grid-template-columns:1fr 1fr}}
.pr-par{display:flex;flex-direction:column;gap:3px;font:600 9.5px var(--font-ui);letter-spacing:.05em;text-transform:uppercase;color:var(--color-text-muted)}
.pr-par input{border:.5px solid var(--color-border-secondary);border-radius:8px;padding:7px 9px;font:600 13px var(--font-ui);background:var(--color-surface);color:var(--color-text);width:100%;min-height:34px;box-sizing:border-box}
.pr-par em{font-style:normal;font-size:9.5px;font-weight:500;text-transform:none;letter-spacing:0}
.pr-tot{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:10px}
@media (max-width:640px){.pr-tot{grid-template-columns:1fr 1fr}}
.pr-tot>div{background:var(--color-background-secondary);border-radius:10px;padding:9px 11px;min-width:0}
.pr-tot .k{display:block;font:600 9.5px var(--font-ui);letter-spacing:.05em;text-transform:uppercase;color:var(--color-text-muted)}
.pr-tot b{display:block;font:400 21px var(--font-display);margin-top:2px;font-variant-numeric:tabular-nums;white-space:nowrap}
.pr-tot small{display:block;font-size:10px;color:var(--color-text-muted);line-height:1.3}
.pr-tab .th,.pr-tab .tr{display:grid;grid-template-columns:minmax(0,2.2fr) 40px 50px 54px 54px 68px 68px 62px;gap:6px;align-items:center;font-size:11.5px;padding:6px 0;border-bottom:.5px solid var(--color-border-tertiary)}
.pr-tab .th{font:600 9.5px var(--font-ui);letter-spacing:.05em;text-transform:uppercase;color:var(--color-text-muted)}
.pr-tab .tr span,.pr-tab .th span{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pr-tab .tr span:first-child,.pr-tab .th span:first-child{text-align:left;display:flex;align-items:center;gap:6px;white-space:normal;line-height:1.2}
.pr-tab .tr i{width:8px;height:8px;border-radius:50%;flex:0 0 auto}
.pr-tab .tr small{color:var(--color-text-muted)}
@media (max-width:640px){.pr-tab .th,.pr-tab .tr{grid-template-columns:minmax(0,1.6fr) 36px 50px 56px 62px}.pr-tab .th span:nth-child(3),.pr-tab .tr span:nth-child(3),.pr-tab .th span:nth-child(7),.pr-tab .tr span:nth-child(7),.pr-tab .th span:nth-child(8),.pr-tab .tr span:nth-child(8){display:none}}
`;
  function styles() {
    if (document.getElementById('pr-css')) return;
    const st = document.createElement('style'); st.id = 'pr-css'; st.textContent = CSS; document.head.appendChild(st);
  }

  const hotes = new Map();
  window.CockpitProspection = {
    /** Un hôte par nœud d'accueil ; le shop peut changer d'un montage à l'autre. */
    mount(host, opts) {
      if (!host) return null;
      styles();
      let h = hotes.get(host);
      if (!h) { h = new Hote(host, opts); hotes.set(host, h); }
      else if (opts && opts.shop != null) h.setShop(opts.shop);
      h.mount(host);
      return h;
    },
    etat, set, charger: etatCharger,
    offres, offreDe, offreParId, chargerOffres: offresCharger,
    /** Être prévenu quand la réserve d'un magasin arrive du serveur. */
    ecouter(fn) { STORE.ecoute.push(fn); },
    FAMILLES: FAM, STATUTS: ST,
  };
})();
