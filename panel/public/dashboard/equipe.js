/* Dashboard magasin › Mon équipe (23/11/2026, étape 1 ; maquettes panel/docs/maquettes/equipe-gestion/).
 *
 * La gestion de l'équipe du panel TF Buddy, reprise pour le cockpit (le paquet panel-team-files : la carte des
 * responsabilités et le planning). Deux onglets :
 *   - RESPONSABILITÉS : qui a quel poste, et les produits qui en dépendent (le secteur du poste) ; la fiche de la personne
 *     choisie ; « Modifier les affectations » (ce que le changement donne avant d'enregistrer) ;
 *   - PLANNING : le planogramme des horaires — vue Jour (une ligne par personne, ses services en barres ; dessous, les
 *     linéaires : la présence quart d'heure par quart d'heure face aux tickets habituels, les temps morts, une ligne par
 *     secteur et le comptoir), vue Semaine (la grille des heures, une petite barre par service, les linéaires
 *     de chaque jour dessous), vue Mois (les heures de chacun jour par jour, chaque jour et chaque semaine face au budget) ;
 *     le BUDGET D'HEURES : les frais de personnel du planning face au CA du jour (la caisse, comptoir + B2B, pour un jour
 *     passé ; la projection aujourd'hui ; l'objectif du magasin pour un jour à venir), cible du réseau
 *     17 %, orange au-dessus, rouge au-dessus de 20 %, noir au-dessus de 22 % (personnelPaliers, réglables), et ce qu'il
 *     faut retirer pour revenir à la cible ; au jour, la souris suffit : tirer une ligne sur celle d'une personne lui
 *     crée un service, glisser ou étirer une barre le change ; la fenêtre montre l'effet sur le comptoir, les secteurs et
 *     le budget avant d'enregistrer.
 * Routes (panel/src/equipe.php) : GET /dashboard/equipe/carte, POST …/postes, GET …/planning, GET …/planning/pdf,
 * POST|PATCH|DELETE …/service.
 *
 * `window.DashEquipe.racine(shop, {apiBase, aller, bureau})` rend l'élément du module, gardé d'un rendu à l'autre ;
 * `relire()` relit tout ; `bureau()` ouvre la demande au bureau du dashboard.
 */
(function () {
  'use strict';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const fE = n => (Math.round(n) || 0).toLocaleString('fr-BE').replace(/\s/g, ' ') + ' €';
  const fH = n => { const r = Math.round(n * 2) / 2; return (Number.isInteger(r) ? String(r) : r.toFixed(1).replace('.', ',')) + ' h'; };
  const fP = x => x.toFixed(1).replace('.', ',') + ' %';
  const fEh = x => x.toFixed(2).replace('.', ',') + ' €/h';
  const mn = t => { const m = /^(\d{1,2}):(\d{2})/.exec(String(t || '')); return m ? 60 * +m[1] + +m[2] : null; };
  const hh = x => String(Math.floor(x / 60)).padStart(2, '0') + ':' + String(x % 60).padStart(2, '0');
  const hc = x => Math.floor(x / 60) + 'h' + (x % 60 ? String(x % 60).padStart(2, '0') : '');
  const dm = m => m >= 60 ? Math.floor(m / 60) + ' h ' + String(m % 60).padStart(2, '0') : m + ' min';
  const ini = n => String(n || '?').split(/\s+/).filter(Boolean).map(x => x[0]).slice(0, 2).join('').toUpperCase();
  const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const dt = s => new Date(s + 'T12:00:00');
  const plus = (s, n) => { const d = dt(s); d.setDate(d.getDate() + n); return ymd(d); };
  const lundiDe = s => { const d = dt(s); d.setDate(d.getDate() - (d.getDay() + 6) % 7); return ymd(d); };
  const JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
  const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  const long = s => { const d = dt(s); const j = JOURS[d.getDay()]; return j[0].toUpperCase() + j.slice(1) + ' ' + d.getDate() + ' ' + MOIS[d.getMonth()] + ' ' + d.getFullYear(); };
  const court = s => { const d = dt(s); return JOURS[d.getDay()].slice(0, 3).replace(/^./, c => c.toUpperCase()) + ' ' + d.getDate(); };
  const wd = s => { const g = dt(s).getDay(); return String(g === 0 ? 7 : g); };
  const AUJ = ymd(new Date());
  const moisDe = s => s.slice(0, 8) + '01';
  const moisPlus = (s, n) => { const d = dt(moisDe(s)); d.setMonth(d.getMonth() + n); return ymd(d).slice(0, 8) + '01'; };
  const nbJours = s => { const d = dt(moisDe(s)); return new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate(); };
  const LETTRE = ['D', 'L', 'M', 'M', 'J', 'V', 'S'];
  // Un poste de comptoir : un poste général dont le nom dit la vente (fr, nl, pl, en).
  const COMPTOIR = /vente|vendeu|comptoir|caisse|verkoop|kassa|sprzeda|kasa|sales|counter/i;
  const SVG = d => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${d}"/></svg>`;

  const st = { shop: null, api: '../api/cockpit', el: null, aller: null, bureau: null, onglet: 'resp',
    R: null, Rerr: null, sel: null, q: '', secteur: '', tousProduits: false, mod: null,
    PJ: null, PS: null, PM: null, Perr: null, vue: 'tableau', ouvrir: null, jour: AUJ, lundi: lundiDe(AUJ), mois: moisDe(AUJ), edit: null, edSection: null, glisse: null,
    pdf: null, rec: null, M: null, mat: null, flash: null, occupe: false };

  /* ── L'API ──────────────────────────────────────────────────────────────────────────────────────────────────── */
  const json = r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(Object.assign(new Error((j && j.error) || ('HTTP ' + r.status)), { j, code: r.status }))));
  const lire = (p, prm) => fetch(st.api + p + '?' + new URLSearchParams(Object.assign({ shop: st.shop }, prm || {})).toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(json);
  const envoyer = (m, p, corps) => fetch(st.api + p, { method: m, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify(Object.assign({ shop: +st.shop }, corps)) }).then(json);
  if (window.DashApi && window.DashApi.noter) { /* l'index de l'API compte ses appels lui-même */ }

  function lireCarte() {
    st.Rerr = null;
    return lire('/dashboard/equipe/carte').then(r => { st.R = r; if (!st.sel || !r.employes.some(e => e.id === st.sel)) { st.sel = r.employes[0] ? r.employes[0].id : null; } })
      .catch(e => { st.Rerr = e.message; st.R = null; }).finally(rendre);
  }
  function lirePlanning(quoi) {
    st.Perr = null;
    const prm = quoi === 'jour' ? { du: st.jour, jours: 1 } : quoi === 'mois' ? { du: st.mois, jours: nbJours(st.mois) } : { du: st.lundi, jours: 7 };
    return lire('/dashboard/equipe/planning', prm)
      .then(r => { if (quoi === 'jour') { st.PJ = r; } else if (quoi === 'mois') { st.PM = r; } else { st.PS = r; } rouvrirSi(); })
      .catch(e => { st.Perr = e.message; }).finally(rendre);
  }
  function relireTout() { lireCarte(); lireMatrice(); lirePlanning('semaine'); if (st.onglet === 'planning' && (st.vue === 'jour' || st.vue === 'mois')) { lirePlanning(st.vue); } }
  /** Le planning affiché : le jour, le mois, sinon la semaine. */
  const planVue = () => st.vue === 'jour' ? st.PJ : st.vue === 'mois' ? st.PM : st.PS;

  /* ── Le calcul d'un jour : services (avec la modification en cours), coût, présence, linéaires ──────────────── */
  function equipeDe(P) { const m = new Map(); (P.employes || []).forEach(e => m.set(e.id, e)); return m; }
  const comptoir = e => (e.postes || []).some(p => !p.secteur && COMPTOIR.test(p.nom || ''));
  const secteurNom = (P, id) => ((P.secteurs || []).find(s => s.id === id) || {}).nom || ('Secteur ' + id);
  const fusion = L => { const r = []; L.slice().sort((a, b) => a[0] - b[0]).forEach(([a, b]) => { const d = r[r.length - 1]; if (d && a <= d[1]) { d[1] = Math.max(d[1], b); } else { r.push([a, b]); } }); return r; };
  const trous = (L, a, b) => { const r = []; let c = a; fusion(L).forEach(([x, y]) => { if (x > c) { r.push([c, Math.min(x, b)]); } c = Math.max(c, y); }); if (c < b) { r.push([c, b]); } return r.filter(([x, y]) => y > x); };

  /** Les services d'un jour, la modification `edit` appliquée ({id, employe, de, a, suppr}). */
  function servicesDe(J, edit) {
    let L = (J.services || []).map(s => ({ id: s.id, employe: s.employe, de: mn(s.de), a: mn(s.a) }));
    if (edit && edit.date === J.date) {
      if (edit.id) { L = L.map(s => s.id === edit.id ? Object.assign({}, s, { de: edit.de, a: edit.a, edite: true }) : s).filter(s => !(edit.suppr && s.id === edit.id)); }
      else if (!edit.suppr && edit.employe) { L.push({ id: 0, employe: edit.employe, de: edit.de, a: edit.a, edite: true }); }
    }
    return L;
  }
  /** Les linéaires d'une équipe : les secteurs tenus par quelqu'un, puis le comptoir s'il existe. */
  function lineaires(P) {
    const E = P.employes || [];
    const L = (P.secteurs || []).filter(s => E.some(e => (e.secteurs || []).includes(s.id))).map(s => ({ nom: s.nom, f: e => (e.secteurs || []).includes(s.id), comptoir: false }));
    if (E.some(comptoir)) { L.push({ nom: 'Comptoir', f: comptoir, comptoir: true }); }
    return L;
  }
  function calculJour(P, J, edit) {
    const eq = equipeDe(P), sv = servicesDe(J, edit);
    let h = 0, cout = 0, sansTaux = 0;
    sv.forEach(s => { const e = eq.get(s.employe) || {}; const d = (s.a - s.de) / 60; h += d; if (e.taux == null && !e.franchise) { sansTaux += d; } cout += d * (e.taux || 0); });
    const ouv = (P.ouvertures || {})[wd(J.date)] || null;
    const lin = lineaires(P).map(l => {
      const cv = sv.filter(s => l.f(eq.get(s.employe) || {})).map(s => [s.de, s.a]);
      return Object.assign({}, l, { cv: fusion(cv), n: new Set(sv.filter(s => l.f(eq.get(s.employe) || {})).map(s => s.employe)).size,
        trous: l.comptoir && ouv && cv.length ? trous(cv, ouv[0], ouv[1]) : [], vide: !cv.length });
    });
    // Le CA des % (23/11/2026, Sam : « le chiffre du jour complet, comptoir + B2B ») : la caisse d'un jour passé, la
    // projection aujourd'hui (J.ca, J.caSource), sinon l'objectif. `objectif` reste le nom du CA de base dans les calculs.
    const base = J.ca > 0 ? J.ca : J.objectif;
    return { sv, eq, h, cout, sansTaux, ouv, lin, objectif: base, prevu: J.objectif, caSource: J.ca > 0 ? J.caSource : (J.objectif ? 'prevu' : null), pct: base ? cout / base * 100 : null,
      presents: new Set(sv.map(s => s.employe)).size };
  }
  const niveau = (P, x) => { const b = P.budget || { cible: 17, rouge: 20, noir: 22 }; return x <= b.cible ? 'ok' : x <= b.rouge ? 'orange' : x <= b.noir ? 'rouge' : 'noir'; };
  const marq = (P, x, cl) => x == null ? '<span class="eqp-tiret">—</span>' : `<span class="eqp-m eqp-m-${niveau(P, x)}${cl ? ' ' + cl : ''}" title="${esc(NIV[niveau(P, x)](P))}">${fP(x)}</span>`;
  /** D'où vient le CA d'un jour, en clair. */
  const SRC_CA = { reel: 'de CA (caisse : comptoir + B2B)', projection: 'de CA projeté (comptoir + B2B)', encours: 'de CA à cette heure (comptoir + B2B)', prevu: 'de CA prévu (l’objectif)' };
  /** Le CA de plusieurs jours : la caisse pour les jours passés, l'objectif pour les autres. */
  const srcCaJours = CJ => { const r = CJ.filter(c => c.objectif && c.caSource !== 'prevu').length, p = CJ.filter(c => c.objectif && c.caSource === 'prevu').length; return r && p ? 'de CA (caisse comptoir + B2B les jours passés, objectif ensuite)' : r ? 'de CA (caisse : comptoir + B2B)' : 'de CA prévu'; };
  const NIV = { ok: P => 'dans la cible de ' + P.budget.cible + ' %', orange: P => 'au-dessus de la cible (' + P.budget.cible + ' %)', rouge: P => 'au-dessus de ' + P.budget.rouge + ' %', noir: P => 'au-dessus de ' + P.budget.noir + ' %' };

  /* ── Le cadre ────────────────────────────────────────────────────────────────────────────────────────────────── */
  function rendre() {
    if (!st.el) { return; }
    const garde = st.el.querySelector('.eqp-pg-defil'), x = garde ? garde.scrollLeft : 0;
    const tabs = [['resp', 'Responsabilités'], ['planning', 'Planning']].map(([k, l]) => `<button type="button" class="page-tabs__tab${st.onglet === k ? ' is-on' : ''}" data-eqp-onglet="${k}">${l}</button>`).join('');
    st.el.innerHTML = `<nav class="page-tabs" aria-label="Mon équipe">${tabs}</nav>${st.flash ? `<div class="eqp-flash ${st.flash[0]}" role="status">${esc(st.flash[1])}</div>` : ''}`
      + (st.onglet === 'resp' ? rendResp() : rendPlanning()) + rendModale() + rendRecopie() + rendMatrice() + rendEditeurModal();
    const g2 = st.el.querySelector('.eqp-pg-defil'); if (g2) { g2.scrollLeft = x; }
    brancher();
  }
  function flash(type, msg) { st.flash = [type, msg]; rendre(); setTimeout(() => { if (st.flash && st.flash[1] === msg) { st.flash = null; rendre(); } }, 5000); }

  /* ── Responsabilités ─────────────────────────────────────────────────────────────────────────────────────────── */
  function rendResp() {
    if (st.Rerr) { return `<div class="eqp-err">Impossible de lire la carte de l’équipe : ${esc(st.Rerr)}. <button type="button" class="linklike" data-eqp-relire="1">Réessayer</button></div>`; }
    const R = st.R;
    if (!R) { return '<div class="eqp-attente">Lecture de l’équipe…</div>'; }
    const r = R.resume, sec = new Map(R.secteurs.map(s => [s.id, s]));
    const kpis = `<section class="card eqp-kpis"><div class="kpi-row">
      <div class="kpi"><div class="kpi__value">${r.employes}</div><div class="kpi__label">Employés actifs</div><div class="kpi__note">${r.sansPoste ? 'dont ' + r.sansPoste + ' sans poste' : 'chacun a un poste'}</div></div>
      <div class="kpi"><div class="kpi__value">${r.postesOccupes}</div><div class="kpi__label">Postes occupés</div><div class="kpi__note">sur ${r.postes} au panel</div></div>
      <div class="kpi"><div class="kpi__value">${r.couverts}</div><div class="kpi__label">Produits affectés</div><div class="kpi__note">sur ${r.produits} de l’assortiment</div></div>
      <div class="kpi"><div class="kpi__value">${r.nonCouverts}${r.nonCouverts ? '<span class="tag-warn">à compléter</span>' : ''}</div><div class="kpi__label">Produits sans responsable</div><div class="kpi__note">${r.secteursVides} secteur${r.secteursVides > 1 ? 's' : ''} vide${r.secteursVides > 1 ? 's' : ''}, ${R.produitsSansSecteur.length} sans secteur</div></div>
    </div></section>`;
    const q = st.q.trim().toLocaleLowerCase();
    const visible = e => !q || [e.nom, ...(e.fonctions || []), ...e.postes.map(p => p.nom), ...e.secteurs.flatMap(id => (sec.get(id) || { produits: [] }).produits.map(p => p.nom))].join(' ').toLocaleLowerCase().includes(q);
    const cols = R.secteurs.filter(s => !st.secteur || String(s.id) === st.secteur);
    const lignes = R.employes.filter(visible).map(e => {
      const cell = s => { const ps = e.postes.filter(p => p.secteur === s.id); return `<td>${ps.length ? ps.map(p => `<span class="chip chip--neutral">${esc(p.nom)}</span>`).join('') + `<span class="sub">${s.produits.length} produits</span>` : '<span class="eqp-tiret" aria-label="Aucune affectation">—</span>'}</td>`; };
      return `<tr class="eqp-ligne${e.id === st.sel ? ' is-sel' : ''}" data-eqp-sel="${e.id}"><td><div class="eqp-pers"><span class="eqp-av">${esc(ini(e.nom))}</span><span><b>${esc(e.nom)}</b>${lettreChip(e)}<span class="sub">${esc((e.fonctions || []).join(', ') || '—')}${e.postes.length ? '' : ' · <span class="eqp-vide">aucun poste</span>'}</span></span></div></td>${cols.map(cell).join('')}<td>${e.autres.length ? e.autres.map(n => `<span class="chip chip--draft">${esc(n)}</span>`).join('') : '<span class="eqp-tiret">—</span>'}</td></tr>`;
    }).join('') || `<tr><td colspan="${cols.length + 2}" class="eqp-vide-l">${R.employes.length ? 'Aucun résultat pour ces filtres.' : 'Aucun employé actif dans ce magasin.'}</td></tr>`;
    const th = cols.map(s => `<th class="${s.employes.length ? '' : 'eqp-gap'}">${esc(s.nom)}<span class="sub">${s.produits.length} produits${s.employes.length ? '' : ' · vide'}</span></th>`).join('');
    // Le bloc « À compléter » (secteurs sans personne, produits et postes sans secteur) est retiré le 23/11/2026, à la
    // demande de Sam : il ne servait pas à faire les horaires. Reste l'assortiment vide, sans quoi la carte ne dit rien.
    const alerte = r.produits ? '' : '<div class="eqp-err">Aucun produit dans l’assortiment actif du magasin : la carte ne peut rien dire.</div>';
    return `<p class="eqp-sous">Qui s’occupe de quoi : les postes de chacun et les produits qui en dépendent, dans l’assortiment actif du magasin.</p>${kpis}
      <div class="eqp-grille"><section class="card" aria-label="Responsabilités de l’équipe">
        <div class="toolbar"><input type="search" placeholder="Rechercher un employé, un poste ou un produit…" aria-label="Rechercher" value="${esc(st.q)}" data-eqp-q="1"><select aria-label="Secteur" data-eqp-secteur="1"><option value="">Tous les secteurs</option>${R.secteurs.map(s => `<option value="${s.id}"${String(s.id) === st.secteur ? ' selected' : ''}>${esc(s.nom)}</option>`).join('')}</select><span class="eqp-sp"></span><button type="button" class="btn btn--primary" data-eqp-postes="${st.sel || ''}"${st.sel ? '' : ' disabled'}>Modifier les affectations</button></div>
        <div class="eqp-table"><table><thead><tr><th>Employé</th>${th}<th>Autres postes<span class="sub">sans produits</span></th></tr></thead><tbody>${lignes}</tbody></table></div>
        <p class="eqp-note">Les produits suivent le secteur du poste. La carte montre les affectations permanentes, ni les qualifications ni la présence : la présence est dans l’onglet Planning.</p>
      </section>${rendFiche(R, sec)}</div>${alerte}`;
  }
  function rendFiche(R, sec) {
    const e = R.employes.find(x => x.id === st.sel);
    if (!e) { return '<aside class="card eqp-fiche"><p class="eqp-note">Choisissez une personne dans le tableau.</p></aside>'; }
    const prods = e.secteurs.flatMap(id => (sec.get(id) || { produits: [] }).produits);
    const semaine = st.PS && st.PS.du === lundiDe(AUJ) ? st.PS.jours.reduce((a, j) => { j.services.filter(s => s.employe === e.id).forEach(s => { a.h += (mn(s.a) - mn(s.de)) / 60; a.n++; }); return a; }, { h: 0, n: 0 }) : null;
    return `<aside class="card eqp-fiche" aria-label="Responsabilités de ${esc(e.nom)}">
      <div class="eqp-fiche-tete"><span class="eqp-av">${esc(ini(e.nom))}</span><div><div class="nm">${esc(e.nom)}${lettreChip(e)}</div><span class="sub">${esc((e.fonctions || []).join(', ') || '—')}</span></div></div>
      <h3>Son périmètre</h3>
      ${e.postes.length ? `<div class="eqp-flux">${e.postes.map(p => { const s = p.secteur ? sec.get(p.secteur) : null; return `<div><span>${esc(p.nom)}</span><span class="fl">→</span><span>${s ? esc(s.nom) : (p.secteur ? 'aucun produit de ce secteur ici' : 'sans secteur')}</span><span class="n">${s ? s.produits.length + ' produits' : '—'}</span></div>`; }).join('')}</div>` : '<p class="eqp-note">Aucun poste n’est affecté à cette personne.</p>'}
      <h3>Ses produits · ${prods.length}</h3>
      ${prods.length ? `<ul class="eqp-prods${st.tousProduits ? ' tous' : ''}">${(st.tousProduits ? prods : prods.slice(0, 18)).map(p => `<li>${esc(p.nom)}</li>`).join('')}</ul>${prods.length > 18 ? `<button type="button" class="linklike" data-eqp-tous="1">${st.tousProduits ? 'Replier' : 'Voir les ' + prods.length + ' produits'}</button>` : ''}` : '<p class="eqp-note">Aucun produit lié dans l’assortiment du magasin.</p>'}
      ${semaine ? `<button type="button" class="eqp-sem" data-eqp-aller-planning="semaine">${SVG('M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z')}<span>Semaine : <b>${fH(semaine.h)}</b> · ${semaine.n} service${semaine.n > 1 ? 's' : ''}</span><span class="eqp-sp"></span><span aria-hidden="true">›</span></button>` : ''}
      ${rendRoulement(e)}
      <div class="eqp-actions"><button type="button" class="btn btn--sm" data-eqp-postes="${e.id}">Modifier ses postes</button></div>
    </aside>`;
  }

  /* ── La fenêtre des postes ───────────────────────────────────────────────────────────────────────────────────── */
  function ouvrirPostes(id) {
    const R = st.R, e = R && R.employes.find(x => x.id === id);
    if (!e) { return; }
    st.mod = { id, coches: new Set(e.postes.map(p => p.id)), attendus: e.postes.map(p => p.id).sort((a, b) => a - b), err: null, occupe: false };
    rendre();
    const b = st.el.querySelector('.eqp-mod input'); if (b) { b.focus(); }
  }
  function rendModale() {
    if (!st.mod || !st.R) { return ''; }
    const R = st.R, e = R.employes.find(x => x.id === st.mod.id), sec = new Map(R.secteurs.map(s => [s.id, s]));
    if (!e) { return ''; }
    const connus = new Map(R.postes.map(p => [p.id, p]));
    e.postes.forEach(p => { if (!connus.has(p.id)) { connus.set(p.id, p); } });
    const opt = p => { const s = p.secteur ? sec.get(p.secteur) : null, on = st.mod.coches.has(p.id), neuf = on && !e.postes.some(x => x.id === p.id);
      return `<label class="eqp-opt${on ? ' on' : ''}${neuf ? ' neuf' : ''}"><input type="checkbox" data-eqp-coche="${p.id}"${on ? ' checked' : ''}${st.mod.occupe ? ' disabled' : ''}><span>${esc(p.nom)}</span><span class="s">${s ? esc(s.nom) + ' · ' + s.produits.length + ' produits' + (!s.employes.length ? ' · personne aujourd’hui' : '') : (p.secteur ? 'aucun produit de ce secteur ici' : 'sans secteur')}</span></label>`; };
    const tous = [...connus.values()].sort((a, b) => String(a.nom).localeCompare(String(b.nom), 'fr'));
    const apres = [...new Set([...st.mod.coches].map(id => (connus.get(id) || {}).secteur).filter(id => id && sec.has(id)))];
    const nApres = apres.reduce((a, id) => a + sec.get(id).produits.length, 0), delta = nApres - e.produits;
    const nouveaux = apres.filter(id => !sec.get(id).employes.length).map(id => sec.get(id).nom);
    const change = [...st.mod.coches].sort((a, b) => a - b).join() !== st.mod.attendus.join();
    return `<div class="eqp-voile" data-eqp-fermer="1"><div class="eqp-mod card" role="dialog" aria-modal="true" aria-labelledby="eqp-mod-t" data-eqp-garde="1">
      <h3 id="eqp-mod-t">Modifier les affectations</h3><p class="qui">${esc(e.nom)}${(e.fonctions || []).length ? ' · ' + esc(e.fonctions.join(', ')) : ''} — cochez ses postes ; ses produits suivent leurs secteurs. Tout décocher retire ses affectations.</p>
      ${tous.some(p => p.secteur) ? `<div class="eqp-opt-g">Postes de production</div>${tous.filter(p => p.secteur).map(opt).join('')}` : ''}
      ${tous.some(p => !p.secteur) ? `<div class="eqp-opt-g">Postes généraux</div>${tous.filter(p => !p.secteur).map(opt).join('')}` : ''}
      ${!tous.length ? '<p class="eqp-note">Aucun poste au panel : ils se créent dans Admin.</p>' : ''}
      ${change ? `<div class="eqp-apres">Avec ces postes : ${apres.length ? apres.map(id => esc(sec.get(id).nom)).join(', ') : 'aucun secteur'} · <b>${nApres} produits</b> (${delta >= 0 ? '+' : ''}${delta}).${nouveaux.length ? '<br>' + nouveaux.map(esc).join(', ') + ' n’avai' + (nouveaux.length > 1 ? 'en' : '') + 't personne : ' + (nouveaux.length > 1 ? 'ils seront couverts.' : 'il sera couvert.') : ''}</div>` : ''}
      ${st.mod.err ? `<p class="error" role="alert">${esc(st.mod.err)}</p>` : ''}
      <div class="confirm__actions"><button type="button" class="btn btn--ghost" data-eqp-fermer="1"${st.mod.occupe ? ' disabled' : ''}>Annuler</button><button type="button" class="btn btn--primary${st.mod.occupe ? ' is-busy' : ''}" data-eqp-enregistrer-postes="1"${change && !st.mod.occupe ? '' : ' disabled'}>${st.mod.occupe ? 'Enregistrement…' : 'Enregistrer'}</button></div>
    </div></div>`;
  }
  function enregistrerPostes() {
    const m = st.mod; if (!m || m.occupe) { return; }
    m.occupe = true; m.err = null; rendre();
    envoyer('POST', '/dashboard/equipe/postes', { employe: m.id, postes: [...m.coches].sort((a, b) => a - b), attendus: m.attendus })
      .then(r => { st.R = r; st.mod = null; flash('ok', 'Postes enregistrés au panel.'); })
      .catch(e => { m.occupe = false; m.err = e.j && e.j.motif === 'conflit' ? 'Les postes de cette personne ont changé entre-temps. Fermez la fenêtre : la carte se relit.' : 'Rien n’est enregistré : ' + e.message + (e.j && e.j.detail ? ' (' + e.j.detail + ')' : '') + '.';
        if (e.j && e.j.motif === 'conflit') { lireCarte(); } rendre(); });
  }

  /* ── Le planning ─────────────────────────────────────────────────────────────────────────────────────────────── */
  function rendPlanning() {
    const P = planVue();
    const nav = st.vue === 'mois'
      ? `<button type="button" class="btn btn--icon" data-eqp-pas="-1" aria-label="Mois précédent">‹</button><span>${MOIS[dt(st.mois).getMonth()].replace(/^./, c => c.toUpperCase())} ${dt(st.mois).getFullYear()}</span><button type="button" class="btn btn--icon" data-eqp-pas="1" aria-label="Mois suivant">›</button>${st.mois !== moisDe(AUJ) ? '<button type="button" class="btn btn--sm btn--ghost" data-eqp-auj="1">Ce mois</button>' : ''}`
      : st.vue === 'jour'
      ? `<button type="button" class="btn btn--icon" data-eqp-pas="-1" aria-label="Jour précédent">‹</button><span>${esc(long(st.jour))}</span><button type="button" class="btn btn--icon" data-eqp-pas="1" aria-label="Jour suivant">›</button>${st.jour !== AUJ ? '<button type="button" class="btn btn--sm btn--ghost" data-eqp-auj="1">Aujourd’hui</button>' : ''}`
      : `<button type="button" class="btn btn--icon" data-eqp-pas="-7" aria-label="Semaine précédente">‹</button><span>Semaine du ${dt(st.lundi).getDate()} au ${esc(long(plus(st.lundi, 6)).replace(/^\S+ /, ''))}</span><button type="button" class="btn btn--icon" data-eqp-pas="7" aria-label="Semaine suivante">›</button>${st.lundi !== lundiDe(AUJ) ? '<button type="button" class="btn btn--sm btn--ghost" data-eqp-auj="1">Cette semaine</button>' : ''}`;
    const barre = `<div class="eqp-pbar"><span class="eqp-nav">${nav}</span><span class="eqp-sp"></span>
      <div class="seg" role="group" aria-label="Affichage">${[['jour', 'Jour'], ['tableau', 'Semaine'], ['mois', 'Mois']].map(([k, l]) => `<button type="button" class="${st.vue === k ? 'is-on' : ''}" data-eqp-vue="${k}">${l}</button>`).join('')}</div>
      ${st.vue === 'tableau' ? `<details class="eqp-menu"><summary class="btn">Préparer la semaine ▾</summary><div class="eqp-menu-l"><button type="button" class="btn btn--sm" data-eqp-recopier="1">Recopier la semaine précédente</button><button type="button" class="btn btn--sm" data-eqp-generer="1">Shift maker <span class="tag-warn">test</span></button><button type="button" class="btn btn--sm" data-eqp-matrice="1"${st.M ? '' : ' disabled'}>Matrice A B C D</button></div></details>` : ''}
      <button type="button" class="btn btn--primary" data-eqp-ajouter="1">Ajouter un service</button>
      <button type="button" class="btn" data-eqp-pdf="1" aria-expanded="${st.pdf ? 'true' : 'false'}">Imprimer le planning</button></div>${rendPdf()}`;
    if (st.Perr && !P) { return barre + `<div class="eqp-err">Impossible de lire le planning : ${esc(st.Perr)}. <button type="button" class="linklike" data-eqp-relire="1">Réessayer</button></div>`; }
    if (!P || P.du !== (st.vue === 'jour' ? st.jour : st.vue === 'mois' ? st.mois : st.lundi)) { return barre + '<div class="eqp-attente">Lecture du planning…</div>'; }
    const non = (P.nonLus || []).length ? `<div class="eqp-err">Le panel n’a pas rendu le planning de ${P.nonLus.map(court).join(', ')} : ces jours sont vides ici, pas au panel.</div>` : '';
    return barre + non + (st.vue === 'jour' ? rendJour(P) : st.vue === 'mois' ? rendMois(P) : rendTableau(P));
  }
  function rendPdf() {
    if (!st.pdf) { return ''; }
    const p = st.pdf;
    return `<section class="card eqp-pdf" aria-label="Imprimer le planning"><strong>Imprimer le planning</strong>
      <label class="eqp-f">À partir du <input type="date" value="${esc(p.du)}" data-eqp-pdfdu="1"></label>
      <label class="eqp-f">Période <select data-eqp-pdfjours="1"><option value="7"${p.jours === 7 ? ' selected' : ''}>Une semaine</option><option value="14"${p.jours === 14 ? ' selected' : ''}>Deux semaines</option></select></label>
      <label class="eqp-f">Présentation <select data-eqp-pdfpres="1"><option value="magasin"${p.pres === 'magasin' ? ' selected' : ''}>Tout le magasin</option><option value="departements"${p.pres === 'departements' ? ' selected' : ''}>Par département</option></select></label>
      <span class="eqp-sp"></span><span class="eqp-note" style="margin:0">A4 paysage · le PDF du panel</span>
      <button type="button" class="btn btn--sm${p.occupe ? ' is-busy' : ''}" data-eqp-pdfok="1"${p.occupe ? ' disabled' : ''}>${p.occupe ? 'Préparation…' : 'Télécharger le PDF'}</button>${p.err ? `<p class="error" style="flex-basis:100%">${esc(p.err)}</p>` : ''}</section>`;
  }
  function telechargerPdf() {
    const p = st.pdf; if (!p || p.occupe) { return; }
    p.occupe = true; p.err = null; rendre();
    fetch(st.api + '/dashboard/equipe/planning/pdf?' + new URLSearchParams({ shop: st.shop, du: p.du, jours: p.jours, presentation: p.pres }), { credentials: 'same-origin' })
      .then(r => (r.headers.get('content-type') || '').includes('pdf') ? r.blob() : r.json().catch(() => ({})).then(j => Promise.reject(new Error(j.error || ('HTTP ' + r.status)))))
      .then(b => { const u = URL.createObjectURL(b), a = document.createElement('a'); a.href = u; a.download = 'Planning_' + p.du + '.pdf'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(u), 4000); p.occupe = false; rendre(); })
      .catch(e => { p.occupe = false; p.err = 'Le PDF n’est pas venu : ' + e.message + '.'; rendre(); });
  }

  /** La jauge du budget : la part du personnel, ses paliers, et les deux leviers pour revenir à la cible. */
  function jauge(P, titre, h, cout, ca) {
    if (!ca) { return `<section class="card eqp-bj"><div class="eqp-bj-t"><h2>${esc(titre)}</h2><span class="sub">pas de CA pour ${st.vue === 'jour' ? 'ce jour' : 'ces jours'}${P.objectif && P.objectif.motif ? ' : ' + esc(P.objectif.motif) : ''} — le budget ne se calcule pas</span></div></section>`; }
    const b = P.budget, pct = cout / ca * 100, moy = h > 0 ? cout / h : 0, bH = moy > 0 ? b.cible / 100 * ca / moy : 0, bC = h > 0 ? b.cible / 100 * ca / h : 0;
    const A = 10, B = Math.max(26, b.noir + 4), x = v => ((Math.min(B, Math.max(A, v)) - A) / (B - A) * 100).toFixed(2) + '%';
    const zone = (a, c, cl) => `<span class="eqp-bj-z ${cl}" style="left:${x(a)};width:calc(${x(c)} - ${x(a)})"></span>`;
    return `<section class="card eqp-bj" aria-label="${esc(titre)}"><div class="eqp-bj-t"><h2>${esc(titre)}</h2><span class="sub">les frais de personnel du planning face au CA : la caisse (comptoir + B2B) pour les jours passés, la projection aujourd’hui, l’objectif ensuite${P.objectif && P.objectif.source === 'theorique' ? ', au CA théorique faute de budget' : ''} · cible du réseau ${String(b.cible).replace('.', ',')} %</span></div>
      <div class="eqp-bj-l"><div class="eqp-bj-v">${marq(P, pct, 'gros')}<span class="eqp-bj-n"><b>${esc(NIV[niveau(P, pct)](P))}</b>${fE(cout)} de personnel pour ${fE(ca)} de CA</span></div>
        <div class="eqp-bj-g"><div class="eqp-bj-piste">${zone(A, b.cible, 'ok')}${zone(b.cible, b.rouge, 'orange')}${zone(b.rouge, b.noir, 'rouge')}${zone(b.noir, B, 'noir')}<span class="eqp-bj-k" style="left:${x(pct)}"><i></i></span></div>
          <div class="eqp-bj-ech"><span style="left:${x(A)}">${A} %</span><span class="c" style="left:${x(b.cible)}">${String(b.cible).replace('.', ',')} % cible</span><span style="left:${x(b.rouge)}">${String(b.rouge).replace('.', ',')} %</span><span style="left:${x(b.noir)}">${String(b.noir).replace('.', ',')} %</span></div></div>
        <div class="eqp-bj-r">${pct > b.cible && moy > 0 ? `<span class="t">Pour revenir à ${String(b.cible).replace('.', ',')} %</span><span><b>−${fH(h - bH)}</b> au coût moyen de ${fEh(moy)}<span class="sub">budget ${fH(bH)} au lieu de ${fH(h)}</span></span><span class="ou">ou <b>${fEh(bC)}</b> de coût moyen pour les mêmes heures</span>` : `<span class="t">Dans la cible</span><span>${moy > 0 ? 'encore ' + fH(bH - h) + ' possibles au coût moyen de ' + fEh(moy) : 'aucune heure au planning'}</span>`}</div></div></section>`;
  }

  /* — Vue Jour : le planogramme — */
  function bornes(P, sv) {
    let a = 4 * 60, b = 20 * 60;
    sv.forEach(s => { a = Math.min(a, Math.floor(s.de / 60) * 60); b = Math.max(b, Math.ceil(s.a / 60) * 60); });
    const o = (P.ouvertures || {})[wd(st.jour)]; if (o) { a = Math.min(a, Math.floor(o[0] / 60) * 60); b = Math.max(b, Math.ceil(o[1] / 60) * 60); }
    return [a, Math.min(24 * 60, b)];
  }
  function groupes(P, eq) {
    const g = new Map(), sect = new Map((P.secteurs || []).map(s => [s.id, s.nom]));
    const ordre = [...(P.secteurs || []).map(s => s.nom), 'Comptoir'];
    (P.employes || []).forEach(e => {
      const nom = (e.secteurs || []).length ? (sect.get(e.secteurs[0]) || 'Secteur ' + e.secteurs[0]) : comptoir(e) ? 'Comptoir' : (e.postes || []).length ? e.postes[0].nom : 'Sans poste';
      if (!g.has(nom)) { g.set(nom, []); }
      g.get(nom).push(e);
    });
    return [...g.entries()].sort((a, b) => { const i = ordre.indexOf(a[0]), j = ordre.indexOf(b[0]); return (a[0] === 'Sans poste') - (b[0] === 'Sans poste') || (i < 0 ? 99 : i) - (j < 0 ? 99 : j) || a[0].localeCompare(b[0], 'fr'); });
  }
  function rendJour(P) {
    const J = P.jours[0], edJ = st.edit && st.edit.date === J.date ? st.edit : null, C = calculJour(P, J, edJ), C0 = edJ ? calculJour(P, J, null) : C;
    const [H0, H1] = bornes(P, C.sv), X = m => ((m - H0) / (H1 - H0) * 100).toFixed(3) + '%', W = (a, b) => ((b - a) / (H1 - H0) * 100).toFixed(3) + '%';
    const TM = P.tempsMorts || {}, creux = (TM.habitude && TM.habitude.creux) || [], tk = (TM.habitude && TM.habitude.ticketsHeure) || {};
    const maintenant = J.date === P.aujourdhui ? mn(P.maintenant) : null;
    const q = []; for (let t = H0; t < H1; t += 15) { q.push([t, C.sv.filter(s => s.de <= t && s.a > t).length]); }
    const pic = Math.max(0, ...q.map(x => x[1])), picQ = q.filter(x => x[1] === pic && pic > 0);
    const tmMin = creux.reduce((a, [x, y]) => a + y - x, 0), tmPers = creux.reduce((a, [x, y]) => a + q.filter(([t]) => t >= x && t < y).reduce((b, [, n]) => b + n * 15, 0), 0);
    const comptoirL = C.lin.find(l => l.comptoir), vides = C.lin.filter(l => !l.comptoir && l.vide);
    const nTrous = vides.length + (comptoirL ? comptoirL.trous.length : 0);
    const kpis = `<section class="card eqp-kpis"><div class="kpi-row">
      <div class="kpi"><div class="kpi__value">${C.presents}</div><div class="kpi__label">Au planning ce jour</div></div>
      <div class="kpi"><div class="kpi__value">${fH(C.h)}</div><div class="kpi__label">Heures du jour</div><div class="kpi__note">coût estimé ${fE(C.cout)}${C.sansTaux ? ' · ' + fH(C.sansTaux) + ' sans taux' : ''}</div></div>
      <div class="kpi"><div class="kpi__value">${marq(P, C.pct, 'gros')}</div><div class="kpi__label">Personnel / CA du jour</div><div class="kpi__note">${C.objectif ? fE(C.cout) + ' pour ' + fE(C.objectif) + ' ' + SRC_CA[C.caSource || 'prevu'] + (C.caSource !== 'prevu' && C.prevu ? ' · objectif ' + fE(C.prevu) : '') : 'pas de CA prévu'}</div></div>
      <div class="kpi"><div class="kpi__value">${TM.habitude && TM.habitude.jours >= 2 ? dm(tmMin) : '—'}</div><div class="kpi__label">Temps morts habituels</div><div class="kpi__note">${TM.habitude && TM.habitude.jours >= 2 ? (tmMin ? fH(tmPers / 60) + ' de personnel pendant ces creux' : 'aucun creux d’un quart d’heure') : 'pas assez de jours mesurés'}</div></div>
      <div class="kpi"><div class="kpi__value">${nTrous}${nTrous ? '<span class="tag-warn">à vérifier</span>' : ''}</div><div class="kpi__label">Trous dans les linéaires</div><div class="kpi__note">${[...vides.map(l => esc(l.nom) + ' vide'), ...(comptoirL ? comptoirL.trous.map(([a, b]) => 'comptoir ' + hh(a) + '–' + hh(b)) : [])].join(' · ') || 'aucun'}</div></div>
    </div></section>`;
    // Le fond : l'ouverture, les heures, les creux habituels, maintenant.
    let fond = C.ouv ? `<span class="eqp-ouv" style="left:${X(C.ouv[0])};width:${W(C.ouv[0], C.ouv[1])}"></span>` : '';
    for (let t = H0 + 60; t < H1; t += 60) { fond += `<span class="eqp-g${t % 240 === 0 ? ' f' : ''}" style="left:${X(t)}"></span>`; }
    fond += creux.map(([a, b]) => `<span class="eqp-tmz" style="left:${X(a)};width:${W(a, b)}"></span>`).join('');
    if (maintenant !== null && maintenant > H0 && maintenant < H1) { fond += `<span class="eqp-now" style="left:${X(maintenant)}"></span>`; }
    let axe = ''; for (let t = H0; t <= H1; t += 60) { axe += `<span class="eqp-t" style="left:${X(t)}">${String(t / 60).padStart(2, '0')}</span>`; }
    if (maintenant !== null && maintenant > H0 && maintenant < H1) { axe += `<span class="eqp-nowax" style="left:${X(maintenant)}">${hh(maintenant)}</span>`; }
    axe += creux.map(([a, b]) => `<span class="eqp-tmax" style="left:${X(a)};width:${W(a, b)}" title="Temps mort habituel ${hh(a)}–${hh(b)}"></span>`).join('');
    const ed = edJ;
    const ligne = e => {
      const L = C.sv.filter(s => s.employe === e.id), orig = ed && ed.id ? C0.sv.find(s => s.id === ed.id && s.employe === e.id) : null;
      const barres = L.map(s => {
        const en = !ed && maintenant !== null && s.de <= maintenant && s.a > maintenant, sel = ed && s.edite;
        return `<span class="eqp-bar${en ? ' en' : ''}${sel ? ' sel' : ''} mobile" style="left:${X(s.de)};width:${W(s.de, s.a)}" data-eqp-bar="${s.id}" data-emp="${e.id}" title="${hh(s.de)}–${hh(s.a)}"><span class="eqp-poi g" data-bord="g"></span><b>${hh(s.de)}–${hh(s.a)}</b><i>${fH((s.a - s.de) / 60)}${en ? ' · en poste' : ''}</i><span class="eqp-poi d" data-bord="d"></span></span>`;
      }).join('');
      const fant = orig && ed && ed.id && orig ? `<span class="eqp-fant" style="left:${X(orig.de)};width:${W(orig.de, orig.a)}"></span>` : '';
      const vide = !L.length ? `<span class="eqp-repos">${e.actif === false ? 'plus dans l’équipe' : 'repos'}${e.actif !== false ? ' · <u>tirez une ligne sur les heures voulues pour ajouter un service</u>' : ''}</span>` : '';
      return `<div class="eqp-l${L.length ? '' : ' repos'}"><div class="eqp-n"><span class="eqp-av">${esc(ini(e.nom))}</span><span><b>${esc(e.nom)}</b>${lettreChip(e)}<span class="sub">${esc((e.postes || []).map(p => p.nom).join(', ') || 'aucun poste')}${e.taux == null && !e.franchise ? ' · <span class="eqp-vide">taux inconnu</span>' : ''}</span></span></div><div class="eqp-tl" data-eqp-tl="${e.id}">${fant}${barres}${vide}</div></div>`;
    };
    const corps = groupes(P, C.eq).map(([g, L]) => `<div class="eqp-gr"><div class="eqp-n">${esc(g)} · ${L.filter(e => C.sv.some(s => s.employe === e.id)).length}/${L.length}</div><div></div></div>${L.map(ligne).join('')}`).join('');
    // Le linéaire de présence : la marche des personnes, et les tickets d'un jour habituel.
    const maxP = Math.max(4, pic), yP = n => (64 - n / maxP * 56).toFixed(1);
    let d = `M0 ${yP(q[0] ? q[0][1] : 0)}`; q.forEach(([t, n]) => { d += ` H${((t - H0) / (H1 - H0) * 1000).toFixed(1)} V${yP(n)}`; }); d += ' H1000';
    const tkE = Object.entries(tk).map(([k, n]) => [+k.slice(1), n]).filter(([h, n]) => n != null && h * 60 >= H0 && h * 60 < H1);
    const maxT = Math.max(1, ...tkE.map(x => x[1])), pT = tkE.reduce((a, b) => b[1] > a[1] ? b : a, [0, 0]);
    const tkB = tkE.map(([h, n]) => { const x0 = (h * 60 - H0) / (H1 - H0) * 1000, l = 60 / (H1 - H0) * 1000; return `<rect x="${(x0 + l * 0.14).toFixed(1)}" y="${(64 - n / maxT * 56).toFixed(1)}" width="${(l * 0.72).toFixed(1)}" height="${(n / maxT * 56).toFixed(1)}" rx="2"></rect>`; }).join('');
    const piste = l => `<div class="eqp-l eqp-li"><div class="eqp-n">${esc(l.nom)}<span class="sub">${l.cv.length ? l.n + ' pers. · ' + l.cv.map(([a, b]) => hc(a) + '–' + hc(b)).join(', ') : 'personne ce jour'}</span></div><div class="eqp-tl">${l.cv.length ? l.cv.map(([a, b]) => `<span class="eqp-cv" style="left:${X(a)};width:${W(a, b)}"></span>`).join('') : '<span class="eqp-trou plein" style="left:0;width:100%"><b>personne de ce secteur ce jour</b></span>'}${l.trous.map(([a, b]) => `<span class="eqp-trou" style="left:${X(a)};width:${W(a, b)}"><b>${hh(a)}–${hh(b)} · personne</b></span>`).join('')}</div></div>`;
    const jourTM = TM.jour ? `<div class="eqp-l eqp-li eqp-tml"><div class="eqp-n">${TM.jour.etat === 'encours' ? 'Aujourd’hui' : 'Ce jour-là'}<span class="sub">${TM.jour.trous.length} temps mort${TM.jour.trous.length > 1 ? 's' : ''} · ${dm(TM.jour.mort)}${TM.jour.etat === 'encours' ? ' jusqu’ici' : ''}</span></div><div class="eqp-tl">${TM.jour.trous.filter(([a, b]) => b > H0 && a < H1).map(([a, b]) => `<span class="eqp-tmb jour" style="left:${X(Math.max(a, H0))};width:${W(Math.max(a, H0), Math.min(b, H1))}" title="${hh(a)}–${hh(b)} · ${b - a} min sans client">${b - a >= 20 ? `<b>${b - a} min</b>` : ''}</span>`).join('')}</div></div>` : '';
    const tmL = TM.habitude ? `<div class="eqp-l eqp-li eqp-tml"><div class="eqp-n">Temps morts habituels<span class="sub">${TM.habitude.jours >= 2 ? creux.length + ' · ' + dm(tmMin) + ' sans client · ' + TM.habitude.jours + ' ' + JOURS[dt(J.date).getDay()] + 's d’avant' : 'pas assez de jours mesurés'}</span></div><div class="eqp-tl">${creux.map(([a, b]) => `<span class="eqp-tmb" style="left:${X(a)};width:${W(a, b)}" title="${hh(a)}–${hh(b)} · ${b - a} min sans client">${b - a >= 20 ? `<b>${b - a} min</b>` : ''}</span>`).join('')}</div></div>` : '';
    const ins = ed ? '' : constats(P, J, C, creux, tk, q);
    return kpis + jauge(P, 'Budget d’heures du jour', C.h, C.cout, C.objectif)
      + `<div class="eqp-pg-defil"><section class="card eqp-pg edition" aria-label="Planogramme du ${esc(long(J.date))}"><div class="eqp-fond">${fond}</div>
        <div class="eqp-l eqp-axe"><div class="eqp-n">${esc(court(J.date))} · ${C.presents} pers. au planning</div><div class="eqp-tl">${axe}</div></div>${corps}
        <div class="eqp-sep"><div class="eqp-n">Les linéaires</div><div class="eqp-leg"><span><i class="l"></i>personnes au planning</span>${tkE.length ? `<span><i class="b"></i>tickets, moyenne des ${TM.habitude.jours} ${JOURS[dt(J.date).getDay()]}s d’avant</span>` : ''}${creux.length ? '<span><i class="m"></i>temps mort (10 min et plus sans ticket)</span>' : ''}${C.ouv ? `<span><i class="o"></i>ouvert ${hh(C.ouv[0])}–${hh(C.ouv[1])} (d’après les tickets)</span>` : ''}</div></div>
        <div class="eqp-l eqp-pres"><div class="eqp-n">Présence<span class="sub">${pic ? 'pic ' + pic + ' pers.' + (picQ.length ? ' de ' + hh(picQ[0][0]) : '') : 'personne'}${pT[1] ? ' · ' + pT[0] + ' h : ' + String(pT[1]).replace('.', ',') + ' tickets' : ''}</span></div><div class="eqp-tl"><svg viewBox="0 0 1000 66" preserveAspectRatio="none" aria-hidden="true"><g class="tk">${tkB}</g><path class="ar" d="${d} V66 H0 Z"></path><path class="ln" d="${d}"></path></svg></div></div>
        ${tmL}${jourTM}${C.lin.map(piste).join('')}
      </section></div>${ins}
      <p class="eqp-note">À la souris : tirez une ligne sur la ligne d’une personne, de l’heure de début à l’heure de fin, pour lui créer un service ; glissez une barre pour la déplacer, ses bords pour changer ses heures (au quart d’heure) ; un clic ouvre le service. Rien n’est écrit au panel avant « Enregistrer » ou « Ajouter le service ». ${'Les barres sont les services du planning du panel. Les temps morts viennent de l’onglet Temps morts (10 minutes et plus sans ticket) ; les heures d’ouverture, des premiers et derniers tickets des 4 dernières semaines. Les linéaires disent qui est là d’après les postes de chacun : une présence planifiée ne garantit pas que la production est faite.'}</p>`;
  }
  /** Les constats du jour : le comptoir vide aux heures chargées, le personnel pendant les creux, les secteurs vides. */
  function constats(P, J, C, creux, tk, q) {
    const L = [];
    const cpt = C.lin.find(l => l.comptoir);
    if (cpt) {
      cpt.trous.forEach(([a, b]) => {
        const n = Object.entries(tk).filter(([k]) => { const h = +k.slice(1) * 60; return h < b && h + 60 > a; }).reduce((s, [, v]) => Math.max(s, v || 0), 0);
        const la = C.sv.filter(s => s.de < b && s.a > a).map(s => (C.eq.get(s.employe) || {}).nom).filter(Boolean).map(n2 => n2.split(' ')[0]);
        L.push(`<b>Personne au comptoir de ${hh(a)} à ${hh(b)}</b>${n ? ' (jusqu’à ' + String(n).replace('.', ',') + ' tickets à l’heure à ce moment, d’habitude)' : ''}${la.length ? ' : ' + esc([...new Set(la)].join(', ')) + (la.length > 1 ? ' sont là.' : ' est là.') : '.'}`);
      });
    }
    const grand = creux.slice().sort((x, y) => (y[1] - y[0]) - (x[1] - x[0]))[0];
    if (grand) { const n = Math.max(0, ...q.filter(([t]) => t >= grand[0] && t < grand[1]).map(x => x[1])); if (n >= 2) { L.push(`Le creux habituel de ${hh(grand[0])} à ${hh(grand[1])} (${grand[1] - grand[0]} min sans client) a <b>${n} personnes</b> au planning.`); } }
    C.lin.filter(l => !l.comptoir && l.vide).forEach(l => L.push(`<b>Personne au ${esc(l.nom)}</b> ce jour.`));
    if (C.pct != null && niveau(P, C.pct) !== 'ok') { L.push(`Le personnel fait <b>${fP(C.pct)}</b> du CA ${C.caSource === 'prevu' ? 'prévu' : 'du jour (comptoir + B2B)'} (cible ${String(P.budget.cible).replace('.', ',')} %).`); }
    return L.length ? `<div class="eqp-alerte eqp-ins">${L.map(x => `<p>${x}</p>`).join('')}</div>` : '';
  }
  function enregistrerService(suppr, suivant) {
    const ed = st.edit; if (!ed || ed.occupe) { return; }
    if (suppr) { ecrireShift('DELETE', { id: ed.id, date: ed.date }, 'Service retiré du planning.', false); return; }
    const suite = suivant ? { mode: 'modal', employe: suivant, date: ed.date, suivre: true } : null;
    if (suivant && ed.id) { const [, J] = ctxEdition(ed), o = J && (J.services || []).find(x => x.id === ed.id); if (o && mn(o.de) === ed.de && mn(o.a) === ed.a) { ouvrirPersonne(suivant, ed.date); return; } }
    if (!ed.employe) { ed.err = 'Choisissez la personne.'; rendre(); return; }
    if (ed.a <= ed.de) { ed.err = 'La fin doit venir après le début.'; rendre(); return; }
    const corps = { date: ed.date, de: hh(ed.de), a: hh(ed.a) };
    ecrireShift(ed.id ? 'PATCH' : 'POST', ed.id ? Object.assign({ id: ed.id }, corps) : Object.assign({ employe: ed.employe }, corps), 'Planning enregistré au panel.', false, suite);
  }

  /* — Vue Semaine — */
  /* — Vue Semaine (la maquette 03, 23/11/2026 : « fais celui-là » ; le planogramme de la semaine y est fondu le même
   * jour : « fusionner tableau et semaine ») : une ligne par personne, une case par jour ; chaque service porte sa
   * petite barre sur la journée (de 4 h à 20 h) ; un clic sur un service l'ouvre (adapter, ajouter une personne sur le
   * shift, la retirer), un clic sur une case vide en ajoute un. Dessous : par jour, les personnes, les heures et le
   * budget ; puis les linéaires (le comptoir, chaque secteur : qui le tient, où sont les trous). — */
  function rendTableau(P) {
    const eq = equipeDe(P), CJ = P.jours.map(J => calculJour(P, J, null));
    const h = CJ.reduce((a, c) => a + c.h, 0), cout = CJ.reduce((a, c) => a + c.cout, 0), ca = CJ.reduce((a, c) => a + (c.objectif || 0), 0);
    const coutCa = CJ.reduce((a, c) => a + (c.objectif ? c.cout : 0), 0), hCa = CJ.reduce((a, c) => a + (c.objectif ? c.h : 0), 0);
    const auPlanning = new Set(CJ.flatMap(c => c.sv.map(s => s.employe))), nSv = CJ.reduce((a, c) => a + c.sv.length, 0);
    const trousJ = CJ.map(C => [...C.lin.filter(l => !l.comptoir && l.vide).map(l => esc(l.nom)), ...(C.lin.find(l => l.comptoir) || { trous: [] }).trous.map(([a, b]) => 'comptoir ' + hc(a) + '–' + hc(b))]);
    const joursTrou = trousJ.filter(t => t.length).length;
    const pct = ca ? coutCa / ca * 100 : null;
    // La petite barre d'un service sur sa journée, et le fond des linéaires : de 4 h à 20 h, élargi aux services de la semaine.
    const H0 = Math.min(4 * 60, ...CJ.flatMap(c => c.sv.map(x => Math.floor(x.de / 60) * 60))), H1 = Math.max(20 * 60, ...CJ.flatMap(c => c.sv.map(x => Math.ceil(x.a / 60) * 60)));
    const X = m => ((Math.min(H1, Math.max(H0, m)) - H0) / (H1 - H0) * 100).toFixed(2) + '%', W = (a, b) => ((Math.min(H1, b) - Math.max(H0, a)) / (H1 - H0) * 100).toFixed(2) + '%';
    const ouv = i => { const o = CJ[i].ouv; return o ? `<span class="eqp-ouv" style="left:${X(o[0])};width:${W(o[0], o[1])}"></span>` : ''; };
    const kpis = `<section class="card eqp-kpis"><div class="kpi-row">
      <div class="kpi"><div class="kpi__value">${auPlanning.size}</div><div class="kpi__label">Employés au planning</div><div class="kpi__note">${nSv} service${nSv > 1 ? 's' : ''}</div></div>
      <div class="kpi"><div class="kpi__value">${fH(h)}</div><div class="kpi__label">Heures planifiées</div><div class="kpi__note">${ca && coutCa && hCa ? 'budget ' + fH(P.budget.cible / 100 * ca / (coutCa / hCa)) + ' à ' + String(P.budget.cible).replace('.', ',') + ' %' : (auPlanning.size ? fH(h / auPlanning.size) + ' par personne en moyenne' : '—')}</div></div>
      <div class="kpi"><div class="kpi__value">${nSv ? marq(P, pct, 'gros') : fE(0)}</div><div class="kpi__label">Personnel / CA</div><div class="kpi__note">${fE(cout)} estimés${ca ? ' pour ' + fE(ca) + ' ' + srcCaJours(CJ) : ''}</div></div>
      <div class="kpi"><div class="kpi__value">${joursTrou}${joursTrou ? '<span class="tag-warn">à vérifier</span>' : ''}</div><div class="kpi__label">Jours avec un trou</div><div class="kpi__note">${joursTrou ? P.jours.filter((_, i) => trousJ[i].length).map(J => JOURS[dt(J.date).getDay()]).join(', ') : 'secteurs et comptoir tenus'}</div></div>
    </div></section>`;
    const zone = e => (e.secteurs || []).length ? secteurNom(P, e.secteurs[0]) : ((e.postes || [])[0] || {}).nom || 'sans poste';
    const lignes = (P.employes || []).filter(e => e.actif !== false || CJ.some(c => c.sv.some(s => s.employe === e.id))).map(e => {
      let hp = 0, cp = 0;
      const cells = CJ.map((C, i) => {
        const L = C.sv.filter(s => s.employe === e.id), d = P.jours[i].date;
        L.forEach(s => { hp += (s.a - s.de) / 60; cp += (s.a - s.de) / 60 * (e.taux || 0); });
        const ouvert = st.edit && st.edit.mode === 'modal' && st.edit.date === d && (st.edit.employe === e.id);
        return `<td class="eqp-c${d === P.aujourdhui ? ' auj' : ''}${ouvert ? ' on' : ''}${e.actif === false ? '' : ' cliquable'}" data-eqp-cell="${d}" data-emp="${e.id}">${L.length ? L.map(s => `<button type="button" class="eqp-sv${st.edit && st.edit.id === s.id ? ' on' : ''}" data-eqp-svc="${s.id}" title="Ouvrir le service">${hh(s.de)}–${hh(s.a)}<small>${esc(zone(e))}</small><span class="eqp-mini" aria-hidden="true">${ouv(i)}<i style="left:${X(s.de)};width:${W(s.de, s.a)}"></i></span></button>`).join('') : `<span class="eqp-tiret">—</span>${e.actif === false ? '' : '<span class="eqp-plus">+ Ajouter</span>'}`}</td>`;
      }).join('');
      return `<tr><td><div class="eqp-pers"><span class="eqp-av">${esc(ini(e.nom))}</span><span><b>${esc(e.nom)}</b>${lettreChip(e)}<span class="sub">${esc((e.postes || []).map(p => p.nom).join(', ') || 'aucun poste')}</span></span></div></td>${cells}<td class="num">${hp ? fH(hp) : '—'}</td><td class="num">${hp ? fE(cp) : '—'}</td></tr>`;
    }).join('') || `<tr><td colspan="10" class="eqp-vide-l">Aucun employé actif.</td></tr>`;
    const tot = `<tr class="tot"><td>Par jour</td>${CJ.map((C, i) => `<td class="${P.jours[i].date === P.aujourdhui ? 'auj' : ''}">${C.presents} pers. · ${fH(C.h)}${C.pct != null && C.h > 0 ? '<br>' + marq(P, C.pct, 'eqp-m-j') : ''}</td>`).join('')}<td class="num">${fH(h)}</td><td class="num">${fE(cout)}</td></tr>`;
    // Les linéaires (du planogramme de la semaine) : une ligne par secteur et le comptoir, une piste par jour.
    const lin = nSv && CJ[0] ? CJ[0].lin : [];
    const pistes = lin.length ? `<tr class="eqp-lsep"><td colspan="${P.jours.length + 3}">Les linéaires<span class="eqp-leg"><span><i class="c"></i>quelqu’un du secteur est là</span><span><i class="t"></i>trou : comptoir vide pendant l’ouverture, ou secteur vide</span><span><i class="o"></i>ouverture (d’après les tickets)</span></span></td></tr>`
      + lin.map((l, k) => `<tr class="eqp-lin"><td>${esc(l.nom)}</td>${P.jours.map((J, i) => { const x = CJ[i].lin[k] || { cv: [], trous: [], vide: false };
        return `<td class="${J.date === P.aujourdhui ? 'auj' : ''}"><span class="eqp-mini l">${ouv(i)}${x.cv.map(([a, b]) => `<span class="eqp-cv" style="left:${X(a)};width:${W(a, b)}"></span>`).join('')}${x.vide ? '<span class="eqp-trou" style="left:0;width:100%"></span>' : ''}${x.trous.map(([a, b]) => `<span class="eqp-trou" style="left:${X(a)};width:${W(a, b)}" title="${hh(a)}–${hh(b)}"></span>`).join('')}</span>${x.vide ? '<span class="eqp-tr">personne</span>' : x.trous.length ? `<span class="eqp-tr">${x.trous.map(([a, b]) => hc(a) + '–' + hc(b)).join(', ')}</span>` : ''}</td>`; }).join('')}<td></td><td></td></tr>`).join('') : '';
    const vide = nSv ? '' : (() => {
      const taux = (P.employes || []).filter(e => e.actif && e.taux > 0).map(e => e.taux), moy = taux.length ? taux.reduce((a, b) => a + b, 0) / taux.length : 0, bud = ca ? P.budget.cible / 100 * ca : 0;
      return `<div class="eqp-vide7"><b>Aucun service au planning cette semaine.</b> Cliquez une case pour ajouter un service, ou partez de la semaine précédente (« Préparer la semaine »).${bud ? `<div class="eqp-vide7-b">Budget de la semaine à ${String(P.budget.cible).replace('.', ',')} % : <b>${fE(bud)}</b> de personnel pour ${fE(ca)} de CA prévu${moy ? `, soit environ <b>${fH(bud / moy)}</b> au taux moyen de l’équipe (${fEh(moy)})` : ''}.</div>` : ''}</div>`;
    })();
    return vide + kpis + (nSv ? jauge(P, 'Budget d’heures de la semaine', hCa || h, coutCa, ca) : '') + `<section class="card eqp-tab"><div class="eqp-table"><table><thead><tr><th>Employé</th>${P.jours.map(J => `<th class="${J.date === P.aujourdhui ? 'auj' : ''}"><button type="button" class="eqp-jb" data-eqp-jour="${J.date}" title="Ouvrir le planogramme du jour">${esc(court(J.date))}</button>${J.date === P.aujourdhui ? '<span class="sub">aujourd’hui</span>' : ''}</th>`).join('')}<th class="num">Heures</th><th class="num">Coût</th></tr></thead><tbody>${lignes}${tot}${pistes}</tbody></table></div></section>
      <p class="eqp-note">Un clic sur un service l’ouvre : adapter ses heures, ajouter une personne sur le même shift, retirer une personne du shift. Un clic sur une case vide ajoute un service à cette personne ce jour-là ; un clic sur un jour l’ouvre en grand, où l’on trace les services à la souris. Paliers du budget : jusqu’à ${String(P.budget.cible).replace('.', ',')} %, la cible du réseau ; orange au-dessus ; rouge au-dessus de ${String(P.budget.rouge).replace('.', ',')} % ; noir au-dessus de ${String(P.budget.noir).replace('.', ',')} %. Le coût suit le taux horaire du panel.</p>`;
  }

  /* — Vue Mois (23/11/2026, « rajouter mois ») : une ligne par personne, une colonne par jour du mois (ses heures, le
   * matin ou l'après-midi), son total du mois ; dessous, chaque jour et chaque semaine face au budget. Un clic sur une
   * case ouvre le service ou en ajoute un ; sur un jour, l'ouvre en grand ; sur une semaine, la montre. — */
  function rendMois(P) {
    const CJ = P.jours.map(J => calculJour(P, J, null)), b = P.budget;
    const somme = f => CJ.reduce((a, c) => a + f(c), 0);
    const h = somme(c => c.h), cout = somme(c => c.cout), ca = somme(c => c.objectif || 0), coutCa = somme(c => c.objectif ? c.cout : 0), hCa = somme(c => c.objectif ? c.h : 0);
    const auPlanning = new Set(CJ.flatMap(c => c.sv.map(x => x.employe))), nSv = somme(c => c.sv.length), pct = ca ? coutCa / ca * 100 : null;
    const hors = CJ.filter(C => C.pct != null && C.h > 0 && niveau(P, C.pct) !== 'ok'), graves = hors.filter(C => niveau(P, C.pct) !== 'orange');
    const nb = x => { const r = Math.round(x * 2) / 2; return Number.isInteger(r) ? String(r) : r.toFixed(1).replace('.', ','); };
    const cl = (J, base) => { const g = dt(J.date).getDay(); return [base, g === 0 || g === 6 ? 'we' : '', J.date === P.aujourdhui ? 'auj' : ''].filter(Boolean).join(' '); };
    const semaines = []; P.jours.forEach((J, i) => { const l = lundiDe(J.date), w = semaines[semaines.length - 1]; if (w && w.lundi === l) { w.i.push(i); } else { semaines.push({ lundi: l, i: [i] }); } });
    const kpis = `<section class="card eqp-kpis"><div class="kpi-row">
      <div class="kpi"><div class="kpi__value">${auPlanning.size}</div><div class="kpi__label">Employés au planning</div><div class="kpi__note">${nSv} service${nSv > 1 ? 's' : ''}</div></div>
      <div class="kpi"><div class="kpi__value">${fH(h)}</div><div class="kpi__label">Heures du mois</div><div class="kpi__note">${ca && coutCa && hCa ? 'budget ' + fH(b.cible / 100 * ca / (coutCa / hCa)) + ' à ' + String(b.cible).replace('.', ',') + ' %' : (auPlanning.size ? fH(h / auPlanning.size) + ' par personne en moyenne' : '—')}</div></div>
      <div class="kpi"><div class="kpi__value">${nSv ? marq(P, pct, 'gros') : fE(0)}</div><div class="kpi__label">Personnel / CA</div><div class="kpi__note">${fE(cout)} estimés${ca ? ' pour ' + fE(ca) + ' ' + srcCaJours(CJ) : ''}</div></div>
      <div class="kpi"><div class="kpi__value">${hors.length}${hors.length ? '<span class="tag-warn">à revoir</span>' : ''}</div><div class="kpi__label">Jours au-dessus de la cible</div><div class="kpi__note">${hors.length ? (graves.length ? graves.length + ' au-dessus de ' + String(b.rouge).replace('.', ',') + ' %' : 'tous sous ' + String(b.rouge).replace('.', ',') + ' %') : (nSv ? 'tous dans la cible' : 'aucun service ce mois')}</div></div>
    </div></section>`;
    const tete = `<tr><th>Employé</th>${P.jours.map(J => { const d = dt(J.date); return `<th class="${cl(J, 'eqp-mj')}"><button type="button" class="eqp-jb" data-eqp-jour="${J.date}" title="Ouvrir le ${esc(long(J.date))}"><span>${LETTRE[d.getDay()]}</span><b>${d.getDate()}</b></button></th>`; }).join('')}<th class="num">Heures</th><th class="num">Coût</th></tr>`;
    const lignes = (P.employes || []).filter(e => e.actif !== false || CJ.some(c => c.sv.some(x => x.employe === e.id))).map(e => {
      let hp = 0, cp = 0;
      const cells = CJ.map((C, i) => {
        const J = P.jours[i], L = C.sv.filter(x => x.employe === e.id);
        L.forEach(x => { hp += (x.a - x.de) / 60; cp += (x.a - x.de) / 60 * (e.taux || 0); });
        const ouvert = st.edit && st.edit.mode === 'modal' && st.edit.date === J.date && st.edit.employe === e.id;
        return `<td class="${cl(J, 'eqp-mc')}${e.actif === false ? '' : ' cliquable'}${ouvert ? ' on' : ''}" data-eqp-cell="${J.date}" data-emp="${e.id}">${L.map(x => `<button type="button" class="eqp-ms ${x.de < 12 * 60 ? 'm' : 'a'}" data-eqp-svc="${x.id}" title="${esc(court(J.date))} · ${hh(x.de)}–${hh(x.a)}">${nb((x.a - x.de) / 60)}</button>`).join('')}</td>`;
      }).join('');
      return `<tr><td><div class="eqp-pers"><span class="eqp-av">${esc(ini(e.nom))}</span><span><b>${esc(e.nom)}</b>${lettreChip(e)}<span class="sub">${esc((e.postes || []).map(x => x.nom).join(', ') || 'aucun poste')}</span></span></div></td>${cells}<td class="num">${hp ? fH(hp) : '—'}</td><td class="num">${hp ? fE(cp) : '—'}</td></tr>`;
    }).join('') || `<tr><td colspan="${P.jours.length + 3}" class="eqp-vide-l">Aucun employé actif.</td></tr>`;
    const parJour = `<tr class="tot"><td>Par jour<span class="sub">heures · budget</span></td>${CJ.map((C, i) => `<td class="${cl(P.jours[i], 'eqp-mc')}">${C.h ? nb(C.h) : '<span class="eqp-tiret">—</span>'}${C.pct != null && C.h > 0 ? `<i class="eqp-pt eqp-m-${niveau(P, C.pct)}" title="${fP(C.pct)} du CA prévu"></i>` : ''}</td>`).join('')}<td class="num">${fH(h)}</td><td class="num">${fE(cout)}</td></tr>`;
    const parSem = `<tr class="tot eqp-msem"><td>Par semaine</td>${semaines.map(w => {
      const C = w.i.map(i => CJ[i]), hw = C.reduce((a, c) => a + c.h, 0), caw = C.reduce((a, c) => a + (c.objectif || 0), 0), cw = C.reduce((a, c) => a + (c.objectif ? c.cout : 0), 0);
      return `<td colspan="${w.i.length}"><button type="button" class="linklike" data-eqp-semaine="${w.lundi}" title="Voir la semaine du ${esc(long(w.lundi))}">${w.i.length < 7 ? '' : 'Sem. du '}${dt(w.lundi).getDate()}/${dt(w.lundi).getMonth() + 1}</button>${hw ? ' · ' + fH(hw) + (caw && cw ? ' ' + marq(P, cw / caw * 100, 'eqp-m-j') : '') : ''}</td>`;
    }).join('')}<td></td><td></td></tr>`;
    const vide = nSv ? '' : `<div class="eqp-vide7"><b>Aucun service au planning ce mois.</b> Préparez-le semaine par semaine : un clic sur une semaine l’ouvre, puis « Préparer la semaine » (recopier la précédente, ou le shift maker).</div>`;
    return vide + kpis + (nSv ? jauge(P, 'Budget d’heures du mois', hCa || h, coutCa, ca) : '') + `<section class="card eqp-tab eqp-moi"><div class="eqp-table"><table><thead>${tete}</thead><tbody>${lignes}${parJour}${parSem}</tbody></table></div></section>
      <p class="eqp-note">Dans chaque case, les heures du service : <span class="eqp-ms m">8</span> le matin (avant midi), <span class="eqp-ms a">6</span> l’après-midi. Un clic sur une case l’ouvre (adapter, retirer, ajouter quelqu’un sur le shift) ou ajoute un service ; un clic sur un jour l’ouvre en grand ; sur une semaine, la montre. Les points sous chaque jour suivent les paliers du budget (cible ${String(b.cible).replace('.', ',')} %, orange au-dessus, rouge au-dessus de ${String(b.rouge).replace('.', ',')} %, noir au-dessus de ${String(b.noir).replace('.', ',')} %).</p>`;
  }

  /* ── La fenêtre d'un service (23/11/2026, « on clic je dois pouvoir adapter, rajouter et effacer une personne du
   * shift ») : la même à la semaine et au mois (une fenêtre) et au jour (sous la barre). ───────── */
  /** Le planning qui porte la date d'une modification : le jour ouvert en grand, sinon la semaine. */
  function ctxEdition(ed) {
    for (const P of st.vue === 'jour' ? [st.PJ, st.PS, st.PM] : st.vue === 'mois' ? [st.PM, st.PS, st.PJ] : [st.PS, st.PJ, st.PM]) { const J = P && P.jours.find(x => x.date === ed.date); if (J) { return [P, J]; } }
    return [null, null];
  }
  /** Les heures proposées pour un nouveau service : le créneau de sa lettre ce jour-là (matrice A B C D), sinon le matin. */
  function defautCreneau(emp, date) {
    const M = st.M && st.M.matrice;
    if (!M) { return [8 * 60, 12 * 60]; }
    const l = emp ? (lettreDe(emp) || null) : null, i = (dt(date).getDay() + 6) % 7;
    const code = l ? M.lettres[l][cycleDe(M, lundiDe(date))][i] : 'M';
    const c = M.creneaux[code === 'R' ? 'M' : code];
    return [mn(c.de), mn(c.a)];
  }
  function ouvrirEdition(o) {
    const [P, J] = ctxEdition(o); if (!P) { return; }
    if (o.id) {
      const s = (J.services || []).find(x => x.id === o.id); if (!s) { return; }
      st.edit = { mode: o.mode, id: s.id, employe: s.employe, date: J.date, de: mn(s.de), a: mn(s.a), err: null, occupe: false, ajout: null };
    } else {
      const [de, a] = o.de != null ? [o.de, o.a] : defautCreneau(o.employe, o.date);
      st.edit = { mode: o.mode, id: 0, employe: o.employe || null, choisir: !o.employe, date: J.date, de, a, err: null, occupe: false, ajout: null };
    }
    rendre();
  }
  /** Rouvre la fenêtre du service gardé dans st.ouvrir, une fois le planning relu (ou la personne suivante). */
  function rouvrirSi() {
    const o = st.ouvrir; if (!o) { return; }
    const [P] = ctxEdition(o); if (!P) { return; }
    st.ouvrir = null;
    if (o.suivre) { ouvrirPersonne(o.employe, o.date); } else { ouvrirEdition(o); }
  }
  /** Une personne, ce jour : son premier service s'il y en a un, sinon un nouveau au créneau de sa lettre. */
  function ouvrirPersonne(emp, date) {
    const [P, J] = ctxEdition({ date }); if (!P) { return; }
    const s = (J.services || []).filter(x => x.employe === emp).sort((x, y) => mn(x.de) - mn(y.de))[0];
    ouvrirEdition({ mode: 'modal', id: s ? s.id : 0, employe: emp, date });
  }
  /** Passer à une autre personne dans la fenêtre ; une modification non enregistrée est d'abord confirmée. */
  function allerPersonne(emp) {
    const ed = st.edit; if (!ed || ed.occupe || !emp || emp === ed.employe) { return; }
    const [P, J] = ctxEdition(ed); if (!P) { return; }
    const orig = ed.id ? (J.services || []).find(x => x.id === ed.id) : null;
    const modifie = orig ? (mn(orig.de) !== ed.de || mn(orig.a) !== ed.a) : !!ed.toucheHeures;
    if (modifie && !window.confirm('Les heures en cours ne sont pas enregistrées. Passer quand même ?')) { return; }
    ouvrirPersonne(emp, ed.date);
  }
  /* ── La fenêtre d'un service (23/11/2026, la proposition 2 bis choisie par Sam : « au lieu de faire un input, tirer
   * une ligne horizontale sur un axe qui représente les heures de la journée, en rouge si plus court que 4 et si plus
   * que 8 ») : les créneaux de la matrice en haut, chacun avec son effet ; la ligne de la personne, à tirer sur l'axe des
   * heures, au-dessus de qui est là ce jour (même échelle) ; l'effet (comptoir, budget du jour, de la semaine, ses
   * heures) ; ceux du même shift. Une seule fenêtre, au jour, à la semaine et au mois. ──────────────────────────────── */
  const DUREES = P => P.durees || { min: 4, max: 8 };
  /** 'court' sous la durée minimale, 'long' au-dessus de la maximale (P.durees, réglage equipeDureesService), sinon ''. */
  const horsDuree = (P, de, a) => { const d = (a - de) / 60, D = DUREES(P); return d < D.min ? 'court' : d > D.max ? 'long' : ''; };
  const motifDuree = (P, h) => h === 'court' ? 'moins de ' + fH(DUREES(P).min) : h === 'long' ? 'plus de ' + fH(DUREES(P).max) : '';
  const signeE = x => (x >= 0 ? '+' : '−') + fE(Math.abs(x));
  /** L'échelle de la fenêtre : de 4 h à 20 h, élargie à l'ouverture et aux services du jour. */
  function echelleEd(C, ed) {
    let a = 4 * 60, b = 20 * 60;
    [...C.sv, ed].forEach(s => { a = Math.min(a, Math.floor(s.de / 60) * 60); b = Math.max(b, Math.ceil(s.a / 60) * 60); });
    if (C.ouv) { a = Math.min(a, Math.floor(C.ouv[0] / 60) * 60); b = Math.max(b, Math.ceil(C.ouv[1] / 60) * 60); }
    return [Math.max(0, a), Math.min(24 * 60, b)];
  }
  /** Ce qu'un horaire fait au comptoir : { ok, txt } ou null (rien ne change, ou pas de comptoir). */
  function effetComptoir(C0, C) {
    const a0 = C0.lin.find(l => l.comptoir), a1 = C.lin.find(l => l.comptoir); if (!a0 || !a1) { return null; }
    const tot = L => L.reduce((x, [a, b]) => x + b - a, 0), liste = L => L.map(([a, b]) => hc(a) + ' à ' + hc(b)).join(', ');
    if (a0.vide && !a1.vide) { return { ok: true, txt: 'quelqu’un au comptoir ce jour' + (a1.trous.length ? ' ; vide de ' + liste(a1.trous) : '') }; }
    if (tot(a1.trous) < tot(a0.trous)) { return { ok: true, txt: a1.trous.length ? 'réduit le trou du comptoir ; reste ' + liste(a1.trous) : 'comble le comptoir de ' + liste(a0.trous) }; }
    if (tot(a1.trous) > tot(a0.trous) || (!a0.vide && a1.vide)) { return { ok: false, txt: a1.vide ? 'plus personne au comptoir ce jour' : 'ouvre un trou au comptoir : ' + liste(a1.trous) }; }
    if (a1.trous.length) { return { ok: false, txt: 'le comptoir reste vide de ' + liste(a1.trous) }; }
    return null;
  }
  /** La semaine de la date, si un planning lu la porte en entier : le budget et les heures de la personne, avant et après. */
  function semaineEd(ed) {
    const l = lundiDe(ed.date), src = [st.PS, st.PM, st.PJ].find(P => P && P.jours.filter(J => lundiDe(J.date) === l).length === 7);
    if (!src) { return null; }
    const Js = src.jours.filter(J => lundiDe(J.date) === l), r = { c0: 0, c1: 0, ca: 0, h0: 0, h1: 0, jours: new Set() };
    Js.forEach(J => {
      const A = calculJour(src, J, null), B = calculJour(src, J, ed);
      if (A.objectif) { r.ca += A.objectif; r.c0 += A.cout; r.c1 += B.cout; }
      A.sv.filter(s => s.employe === ed.employe).forEach(s => { r.h0 += (s.a - s.de) / 60; });
      B.sv.filter(s => s.employe === ed.employe).forEach(s => { r.h1 += (s.a - s.de) / 60; r.jours.add(J.date); });
    });
    return Object.assign(r, { P: src, pct0: r.ca ? r.c0 / r.ca * 100 : null, pct1: r.ca ? r.c1 / r.ca * 100 : null,
      repos: Js.filter(J => !r.jours.has(J.date)).map(J => JOURS[dt(J.date).getDay()]) });
  }
  /** Le corps de la fenêtre. */
  function editeur(P, J, ed) {
    const eq = equipeDe(P), e = ed.employe ? (eq.get(ed.employe) || { nom: 'Employé ' + ed.employe, postes: [] }) : null;
    const C0 = calculJour(P, J, null), C = calculJour(P, J, ed), jourNom = JOURS[dt(ed.date).getDay()];
    const orig = ed.id ? C0.sv.find(s => s.id === ed.id) : null, change = !orig || orig.de !== ed.de || orig.a !== ed.a;
    const prenom = e ? esc(e.nom.split(' ')[0]) : '', hors = horsDuree(P, ed.de, ed.a);
    const chev = ed.employe ? C0.sv.find(x => x.employe === ed.employe && x.id !== ed.id && x.de < ed.a && x.a > ed.de) : null;
    // Les créneaux : ceux de la matrice A B C D, puis « Sur mesure » (la ligne tirée), chacun avec son effet.
    const M = st.M && st.M.matrice;
    const opts = M ? ['M', 'P'].map(k => ({ k, nom: CRENEAU[k], de: mn(M.creneaux[k].de), a: mn(M.creneaux[k].a) })) : [];
    const choisi = opts.find(o => o.de === ed.de && o.a === ed.a);
    const carte = (o, sur) => {
      const Ck = sur ? C : calculJour(P, J, Object.assign({}, ed, { de: o.de, a: o.a })), on = sur ? !choisi : choisi === o, hk = horsDuree(P, o.de, o.a);
      const cp = e ? effetComptoir(C0, Ck) : null, dC = Ck.cout - C0.cout;
      const lignes = [hk ? `<span class="ko">${esc(motifDuree(P, hk))}</span>` : '', cp ? `<span class="${cp.ok ? 'bon' : ''}">${cp.ok ? '✓ ' : ''}${esc(cp.txt)}</span>` : '',
        e && Ck.objectif ? `<span>${jourNom} ${marq(P, Ck.pct)}${sur ? ' · la ligne ci-dessous' : ''}</span>` : (sur ? '<span>la ligne ci-dessous</span>' : '')].filter(Boolean).join('');
      const t = `<span class="eqp-fo-radio"></span><span class="t">${sur ? 'Sur mesure' : esc(o.nom)}<span>${hh(o.de)}–${hh(o.a)} · <i${hk ? ' class="r"' : ''}>${fH((o.a - o.de) / 60)}</i></span></span><span class="prix">${e ? signeE(dC) : ''}</span><span class="d">${lignes}</span>`;
      return sur ? `<div class="eqp-fo-carte${on ? ' on' : ''}${on && hk ? ' rouge' : ''}">${t}</div>`
        : `<button type="button" class="eqp-fo-carte${on ? ' on' : ''}${on && hk ? ' rouge' : ''}" data-eqp-cren="${o.k}">${t}</button>`;
    };
    const cartes = `<div class="eqp-fo-cartes${opts.length ? '' : ' seule'}">${opts.map(o => carte(o, false)).join('')}${carte({ de: ed.de, a: ed.a }, true)}</div>`;
    // La ligne à tirer, et qui est là ce jour, à la même échelle.
    const [H0, H1] = echelleEd(C0, ed), X = m => ((Math.min(H1, Math.max(H0, m)) - H0) / (H1 - H0) * 100).toFixed(2) + '%', W = (a, b) => ((Math.min(H1, b) - Math.max(H0, a)) / (H1 - H0) * 100).toFixed(2) + '%';
    const ouv = C.ouv ? `<span class="eqp-fo-ouv" style="left:${X(C.ouv[0])};width:${W(C.ouv[0], C.ouv[1])}"></span>` : '';
    let grille = ''; for (let t = H0 + 60; t < H1; t += 60) { grille += `<span class="h" style="left:${X(t)}"></span>`; } for (let t = H0 + 30; t < H1; t += 60) { grille += `<span class="hh" style="left:${X(t)}"></span>`; }
    let axe = ''; for (let t = H0; t <= H1; t += 60) { axe += `<i style="left:${X(t)}">${t / 60} h</i>`; }
    const r = hors ? ' rouge' : '', dur = fH((ed.a - ed.de) / 60) + (hors ? ' · ' + motifDuree(P, hors) : '');
    const autres = C0.sv.filter(s => s.employe === ed.employe && s.id !== ed.id).map(s => `<span class="eqp-fo-b autre" style="left:${X(s.de)};width:${W(s.de, s.a)}" title="Son autre service">${hh(s.de)}–${hh(s.a)}</span>`).join('');
    const fant = orig && change ? `<span class="eqp-fo-fant" style="left:${X(orig.de)};width:${W(orig.de, orig.a)}" title="Avant : ${hh(orig.de)}–${hh(orig.a)}"></span>` : '';
    const trace = `<div class="eqp-fo-trace" data-eqp-trace="1" data-h0="${H0}" data-h1="${H1}">${ouv}${grille}${fant}${autres}
      <span class="eqp-fo-trait${r}" style="left:${X(ed.de)};width:${W(ed.de, ed.a)}"></span>
      <span class="eqp-fo-poi${r}" style="left:${X(ed.de)}" role="slider" tabindex="0" aria-label="Début" aria-valuetext="${hh(ed.de)}" data-eqp-poignee="de"></span>
      <span class="eqp-fo-poi${r}" style="left:${X(ed.a)}" role="slider" tabindex="0" aria-label="Fin" aria-valuetext="${hh(ed.a)}" data-eqp-poignee="a"></span>
      <span class="eqp-fo-bul de${r}" style="left:${X(ed.de)}">${hh(ed.de)}</span><span class="eqp-fo-bul a${r}" style="left:${X(ed.a)}">${hh(ed.a)}</span>
      <span class="eqp-fo-dur${r}" style="left:${X((ed.de + ed.a) / 2)}">${esc(dur)}</span></div>`;
    const D = DUREES(P);
    const autresL = C0.sv.filter(s => s.employe !== ed.employe).sort((x, y) => x.de - y.de || x.a - y.a);
    const dansSec = id => { if (!st.edSection) { return false; } const g = groupes(P, eq).find(([n]) => n === st.edSection); return !!g && g[1].some(x => x.id === id); };
    const lignesQui = autresL.map(s => { const p2 = eq.get(s.employe) || { nom: 'Employé ' + s.employe }; return `<div class="eqp-fo-l${dansSec(s.employe) ? ' sec' : ''}"><span>${esc(p2.nom)}</span><div class="eqp-fo-p">${ouv}<span class="eqp-fo-b" style="left:${X(s.de)};width:${W(s.de, s.a)}">${hh(s.de)}–${hh(s.a)}</span></div></div>`; }).join('');
    const c0 = C0.lin.find(l => l.comptoir), c1 = C.lin.find(l => l.comptoir);
    const comble = c0 && c1 ? c0.trous.flatMap(([x, y]) => trous(c1.trous, x, y)) : [];
    const ligneCpt = c1 ? `<div class="eqp-fo-l cpt"><span>Comptoir</span><div class="eqp-fo-p">${ouv}${c1.cv.map(([a, b]) => `<span class="eqp-fo-b cv" style="left:${X(a)};width:${W(a, b)}"></span>`).join('')}${comble.map(([a, b]) => `<span class="eqp-fo-b comble" style="left:${X(a)};width:${W(a, b)}">comblé</span>`).join('')}${c1.trous.map(([a, b]) => `<span class="eqp-fo-b trou" style="left:${X(a)};width:${W(a, b)}" title="Personne au comptoir ${hh(a)}–${hh(b)}"></span>`).join('')}</div></div>` : '';
    const lS = st.edSection ? C.lin.find(l => !l.comptoir && l.nom === st.edSection) : null;
    const ligneSec = lS ? `<div class="eqp-fo-l cpt"><span>${esc(lS.nom)}</span><div class="eqp-fo-p">${ouv}${lS.vide ? `<span class="eqp-fo-b trou" style="left:0;width:100%" title="Personne de ce secteur ce jour"></span>` : lS.cv.map(([a, b]) => `<span class="eqp-fo-b cv" style="left:${X(a)};width:${W(a, b)}"></span>`).join('')}</div></div>` : '';
    const plan = `<div class="eqp-fo-plan"><div class="eqp-fo-axe">${axe}</div>
      <div class="eqp-fo-tl"><span class="nom">${e ? prenom : 'La personne'}<small>tirez sa ligne</small></span>${trace}</div>
      <p class="eqp-fo-regle" title="Au clavier : les flèches sur un bord (Maj : à l’heure)">Tirez du début à la fin, au quart d’heure ; tirez un bord pour ajuster. <b>Verte de ${fH(D.min)} à ${fH(D.max)}</b> · <b class="r">rouge sous ${fH(D.min)} ou au-dessus de ${fH(D.max)}</b></p>
      ${lignesQui || '<p class="eqp-note" style="margin:4px 0 0">Personne d’autre au planning ce jour.</p>'}${ligneCpt}${ligneSec}</div>`;
    // L'effet : le comptoir et les secteurs, le budget du jour, celui de la semaine, ses heures.
    const cp = e && change ? effetComptoir(C0, C) : null;
    const secteurs = change ? C.lin.filter(l => !l.comptoir).map(l => { const l0 = C0.lin.find(x => x.nom === l.nom); return l0 && l0.vide && !l.vide ? `<em class="bon">${esc(l.nom)} : quelqu’un ce jour</em>` : l0 && !l0.vide && l.vide ? `<em class="ko">${esc(l.nom)} : plus personne ce jour</em>` : ''; }).join('') : '';
    const S = e ? semaineEd(ed) : null, dC = C.cout - C0.cout;
    const effs = e ? `<div class="eqp-fo-effs">
      <div class="${cp ? (cp.ok ? 'ok' : 'ko') : ''}"><span>Comptoir</span><b>${cp ? (cp.ok ? '<i class="eqp-fo-ok">✓</i>' : '') + esc(cp.txt) : (c1 && c1.trous.length ? 'vide de ' + c1.trous.map(([a, b]) => hc(a) + ' à ' + hc(b)).join(', ') : 'rien ne change')}</b>${secteurs}</div>
      <div><span>Budget du ${jourNom}</span>${C.objectif ? `<b>${marq(P, C0.pct)} → ${marq(P, C.pct)}</b><em>${signeE(dC)} · cible ${String(P.budget.cible).replace('.', ',')} %</em>` : '<b>—</b><em>pas de CA ce jour</em>'}</div>
      <div><span>Budget de la semaine</span>${S && S.ca ? `<b>${marq(P, S.pct0)} → ${marq(P, S.pct1)}</b><em>${(ed.a - ed.de - (orig ? orig.a - orig.de : 0)) >= 0 ? '+' : '−'}${fH(Math.abs(ed.a - ed.de - (orig ? orig.a - orig.de : 0)) / 60)} au planning</em>` : '<b>—</b><em>la semaine n’est pas lue ici</em>'}</div>
      <div><span>${prenom} cette semaine</span>${S ? `<b class="eqp-num">${fH(S.h0)} → ${fH(S.h1)}</b><em>${S.jours.size} jour${S.jours.size > 1 ? 's' : ''}${S.repos.length ? ', repos ' + esc(S.repos.join(', ')) : ', aucun repos'}</em>` : '<b>—</b>'}</div></div>` : '';
    // Ceux du même shift (un service ouvert) : les ouvrir, les retirer, ajouter quelqu'un sur ces heures.
    const meme = C0.sv.filter(s => s.id !== ed.id && s.de < ed.a && s.a > ed.de).sort((x, y) => x.de - y.de);
    const libres = (P.employes || []).filter(x => x.actif !== false && x.id !== ed.employe && !C0.sv.some(s => s.employe === x.id && s.de < ed.a && s.a > ed.de));
    let ajoutEffet = '';
    if (ed.ajout) { const Ca = calculJour(P, J, { id: 0, employe: ed.ajout, date: J.date, de: ed.de, a: ed.a }); ajoutEffet = `<span class="mu">+${fH((ed.a - ed.de) / 60)} · +${fE(Ca.cout - C0.cout)}${Ca.objectif ? ' · budget du jour ' + marq(P, Ca.pct) : ''}</span>`; }
    const shift = ed.id ? `<div class="eqp-fo-sec eqp-shift"><div class="eqp-opt-g">Sur ce shift · ${hh(ed.de)}–${hh(ed.a)}</div>
        ${meme.length ? meme.map(x => { const p2 = eq.get(x.employe) || { nom: 'Employé ' + x.employe }; return `<div class="eqp-shift-l"><span><b>${esc(p2.nom)}</b>${lettreChip(p2)} <span class="mu">${hh(x.de)}–${hh(x.a)}</span></span><span class="eqp-sp"></span><button type="button" class="linklike" data-eqp-ouvrir-svc="${x.id}">Ouvrir</button><button type="button" class="btn btn--sm btn--danger" data-eqp-retirer="${x.id}" data-nom="${esc(p2.nom)}"${ed.occupe ? ' disabled' : ''}>Retirer</button></div>`; }).join('') : '<p class="eqp-note" style="margin:4px 0 0">Personne d’autre sur ces heures.</p>'}
        <div class="eqp-shift-aj"><select data-eqp-ajout-sel="1"${libres.length ? '' : ' disabled'}><option value="">${libres.length ? '+ Ajouter une personne sur ce shift' : 'Toute l’équipe est déjà là'}</option>${libres.map(x => `<option value="${x.id}"${x.id === ed.ajout ? ' selected' : ''}>${esc(x.nom)}${(x.postes || []).length ? ' · ' + esc(x.postes[0].nom) : ''}</option>`).join('')}</select>${ed.ajout ? `<button type="button" class="btn btn--sm" data-eqp-ajout-ok="1"${ed.occupe ? ' disabled' : ''}>Ajouter ${esc(((eq.get(ed.ajout) || {}).nom || '').split(' ')[0])} ${hh(ed.de)}–${hh(ed.a)}</button>` : ''}</div>${ajoutEffet ? `<div class="eqp-shift-ef">${ajoutEffet}</div>` : ''}</div>` : '';
    const choisir = ed.choisir ? `<div class="eqp-fo-sec eqp-ed-ch"><label class="eqp-f">Qui <select data-eqp-ed-emp="1"><option value="">— choisir —</option>${(P.employes || []).filter(x => x.actif !== false).map(x => `<option value="${x.id}"${x.id === ed.employe ? ' selected' : ''}>${esc(x.nom)}</option>`).join('')}</select></label><label class="eqp-f">Jour <select data-eqp-ed-date="1">${P.jours.map(x => `<option value="${x.date}"${x.date === ed.date ? ' selected' : ''}>${esc(long(x.date).replace(/ \d{4}$/, ''))}</option>`).join('')}</select></label></div>` : '';
    // Parcourir le jour (23/11/2026, « je dois pouvoir switcher personne par personne et section par section pour être
    // sûr que tout soit bon ») : les sections (secteurs, comptoir, postes), et dans la section chacun avec ses heures.
    const G = groupes(P, eq).map(([g, L]) => [g, L.filter(x => x.actif !== false || C0.sv.some(s2 => s2.employe === x.id))]).filter(([, L]) => L.length);
    const sec = st.edSection && G.some(([g]) => g === st.edSection) ? st.edSection : null;
    const file = (sec ? G.filter(([g]) => g === sec) : G).flatMap(([, L]) => L), iF = file.findIndex(x => x.id === ed.employe);
    const suivant = iF >= 0 && iF < file.length - 1 ? file[iF + 1] : null, avant = iF > 0 ? file[iF - 1] : null;
    const auPl = id => C0.sv.filter(s2 => s2.employe === id).sort((x, y) => x.de - y.de);
    const nav = e ? `<div class="eqp-fo-nav">
      <div class="eqp-fo-secs" role="group" aria-label="Sections"><button type="button" class="eqp-fo-sc${sec ? '' : ' on'}" data-eqp-fo-section="">Toute l’équipe <span>${new Set(C0.sv.map(x => x.employe)).size}/${G.reduce((a, [, L]) => a + L.length, 0)}</span></button>${G.map(([g, L]) => `<button type="button" class="eqp-fo-sc${sec === g ? ' on' : ''}" data-eqp-fo-section="${esc(g)}">${esc(g)} <span>${L.filter(x => auPl(x.id).length).length}/${L.length}</span></button>`).join('')}</div>
      <div class="eqp-fo-pers"><button type="button" class="btn btn--icon" data-eqp-fo-aller="${avant ? avant.id : ''}"${avant ? '' : ' disabled'} aria-label="Personne précédente">‹</button>
        <div class="eqp-fo-pl">${file.map(x => { const L = auPl(x.id); return `<button type="button" class="eqp-fo-pc${x.id === ed.employe ? ' on' : ''}${L.length ? ' a' : ''}" data-eqp-fo-aller="${x.id}"><b>${esc(x.nom.split(' ')[0])}</b><small>${L.length ? L.map(y => hc(y.de) + '–' + hc(y.a)).join(', ') : 'repos'}</small></button>`; }).join('')}</div>
        <button type="button" class="btn btn--icon" data-eqp-fo-aller="${suivant ? suivant.id : ''}"${suivant ? '' : ' disabled'} aria-label="Personne suivante">›</button><span class="eqp-fo-pos">${iF + 1}/${file.length}</span></div></div>` : '';
    const res = `${e ? '<b>' + prenom + '</b> · ' : ''}${esc(court(ed.date))} · ${hh(ed.de)}–${hh(ed.a)} · ${fH((ed.a - ed.de) / 60)}${e ? ' · ' + signeE(dC) : ''}${hors ? `<span class="tag-r">${esc(motifDuree(P, hors))}</span>` : ''}`;
    return `<div class="eqp-fo-tete">${e ? `<span class="eqp-av">${esc(ini(e.nom))}</span>` : ''}<div><b>${e ? esc(e.nom) + lettreChip(e) : 'Ajouter un service'}</b><span class="sub">${e ? esc((e.postes || []).map(x => x.nom).join(', ') || 'aucun poste') + ' · ' : ''}${esc(long(ed.date).replace(/ \d{4}$/, ''))}${ed.id ? (orig ? ' · ' + hh(orig.de) + '–' + hh(orig.a) + ' au planning' : '') : ' · nouveau service'}</span></div><button type="button" class="eqp-fo-x" data-eqp-annuler="1" aria-label="Fermer">×</button></div>
      ${nav}${choisir}${cartes}${plan}${effs}
      ${chev ? `<p class="eqp-fo-err" role="alert">Chevauche son service de ${hh(chev.de)} à ${hh(chev.a)} : adaptez les heures.</p>` : ''}${ed.err ? `<p class="eqp-fo-err" role="alert">${esc(ed.err)}</p>` : ''}
      <div class="eqp-fo-pied"><span class="eqp-fo-res">${res}</span>${ed.id ? `<button type="button" class="btn btn--danger" data-eqp-suppr="1"${ed.occupe ? ' disabled' : ''}>Retirer ${prenom} du shift</button>` : ''}<button type="button" class="btn btn--ghost" data-eqp-annuler="1"${ed.occupe ? ' disabled' : ''}>${ed.id && !change ? 'Fermer' : 'Annuler'}</button><button type="button" class="btn btn--primary${ed.occupe ? ' is-busy' : ''}" data-eqp-ok="1"${ed.occupe || !change || !ed.employe || chev ? ' disabled' : ''}>${ed.occupe ? 'Enregistrement…' : ed.id ? 'Enregistrer' : 'Ajouter le service'}</button>${suivant ? `<button type="button" class="btn btn--primary" data-eqp-ok-suivant="${suivant.id}"${ed.occupe || !ed.employe || chev ? ' disabled' : ''}>${change ? (ed.id ? 'Enregistrer' : 'Ajouter') + ' et suivant' : 'Suivant'} : ${esc(suivant.nom.split(' ')[0])} ›</button>` : ''}</div>
      ${shift}`;
  }
  /** La fenêtre, au jour, à la semaine et au mois. */
  function rendEditeurModal() {
    const ed = st.edit; if (!ed || ed.mode !== 'modal') { return ''; }
    const [P, J] = ctxEdition(ed); if (!P) { return ''; }
    return `<div class="eqp-voile" data-eqp-fermer="1"><div class="eqp-fo card" role="dialog" aria-modal="true" aria-label="Le service" data-eqp-garde="1">${editeur(P, J, ed)}</div></div>`;
  }
  /** Tirer la ligne dans la fenêtre : une nouvelle ligne sur la piste, un bord pour l'ajuster, la ligne pour la déplacer ;
   *  au quart d'heure. Pendant le geste, seule la piste bouge ; au lâcher, la fenêtre se recalcule (l'effet). */
  function traceDebut(ev) {
    const ed = st.edit; if (ev.button !== 0 || !ed) { return; }
    const [P] = ctxEdition(ed); if (!P) { return; }
    const tr = ev.currentTarget, H0 = +tr.dataset.h0, H1 = +tr.dataset.h1, r = tr.getBoundingClientRect();
    const aMin = x => H0 + (x - r.left) / r.width * (H1 - H0), q15 = m => Math.max(H0, Math.min(H1, Math.round(m / 15) * 15));
    const poi = ev.target.closest('[data-eqp-poignee]'), surTrait = ev.target.closest('.eqp-fo-trait');
    const g = poi ? { mode: poi.dataset.eqpPoignee } : surTrait ? { mode: 'deplacer', de0: ed.de, a0: ed.a, x0: aMin(ev.clientX) } : { mode: 'nouveau', x0: q15(aMin(ev.clientX)) };
    ev.preventDefault();
    if (tr.setPointerCapture) { try { tr.setPointerCapture(ev.pointerId); } catch (x) { /* rien */ } }
    let de = ed.de, a = ed.a, bouge0 = false;
    const X = m => ((m - H0) / (H1 - H0) * 100).toFixed(2) + '%';
    const maj = () => {
      const h = horsDuree(P, de, a), cl = (n, base) => { if (n) { n.className = base + (h ? ' rouge' : ''); } };
      const t = tr.querySelector('.eqp-fo-trait'), pd = tr.querySelector('[data-eqp-poignee="de"]'), pa = tr.querySelector('[data-eqp-poignee="a"]');
      const bd = tr.querySelector('.eqp-fo-bul.de'), ba = tr.querySelector('.eqp-fo-bul.a'), du = tr.querySelector('.eqp-fo-dur');
      if (t) { t.style.left = X(de); t.style.width = ((a - de) / (H1 - H0) * 100).toFixed(2) + '%'; cl(t, 'eqp-fo-trait'); }
      if (pd) { pd.style.left = X(de); cl(pd, 'eqp-fo-poi'); } if (pa) { pa.style.left = X(a); cl(pa, 'eqp-fo-poi'); }
      if (bd) { bd.style.left = X(de); bd.textContent = hh(de); cl(bd, 'eqp-fo-bul de'); } if (ba) { ba.style.left = X(a); ba.textContent = hh(a); cl(ba, 'eqp-fo-bul a'); }
      if (du) { du.style.left = X((de + a) / 2); du.textContent = fH((a - de) / 60) + (h ? ' · ' + motifDuree(P, h) : ''); cl(du, 'eqp-fo-dur'); }
    };
    const bouge = e2 => {
      const m = aMin(e2.clientX); bouge0 = true;
      if (g.mode === 'de') { de = Math.min(a - 15, q15(m)); }
      else if (g.mode === 'a') { a = Math.max(de + 15, q15(m)); }
      else if (g.mode === 'deplacer') { const d = Math.round((m - g.x0) / 15) * 15; de = g.de0 + d; a = g.a0 + d; if (de < H0) { a += H0 - de; de = H0; } if (a > H1) { de -= a - H1; a = H1; } }
      else { const x = q15(m); de = Math.min(g.x0, x); a = Math.max(g.x0, x); if (a === de) { a = Math.min(H1, de + 15); } }
      maj();
    };
    const fin = () => {
      document.removeEventListener('pointermove', bouge); document.removeEventListener('pointerup', fin);
      if (!bouge0 || a - de < 15) { rendre(); return; }   // un simple clic ne change rien
      ed.de = de; ed.a = a; ed.toucheHeures = true; rendre();
    };
    document.addEventListener('pointermove', bouge); document.addEventListener('pointerup', fin);
  }
  /** Après une écriture : relire, et rouvrir la fenêtre du service si on reste sur le shift. */
  function apresEcriture(msg, rouvrir) {
    st.edit = null; st.ouvrir = rouvrir || null;
    if (st.vue === 'jour') { st.PJ = null; lirePlanning('jour'); }
    if (st.vue === 'mois') { st.PM = null; lirePlanning('mois'); } else { st.PM = null; }
    st.PS = null; lirePlanning('semaine');
    flash('ok', msg);
  }
  function ecrireShift(methode, corps, msg, rester, suite) {
    const ed = st.edit; if (!ed || ed.occupe) { return; }
    ed.occupe = true; ed.err = null; rendre();
    envoyer(methode, '/dashboard/equipe/service', corps)
      .then(() => apresEcriture(msg, suite || (rester && ed.id ? { mode: ed.mode, id: ed.id, date: ed.date } : null)))
      .catch(e => { ed.occupe = false; ed.err = 'Rien n’est enregistré : ' + e.message + (e.j && e.j.detail ? ' (' + e.j.detail + ')' : '') + '.'; rendre(); });
  }

  /* ── Préparer la semaine : recopier la semaine précédente, ou le shift maker (test, la matrice A B C D) ──────────
   * (23/11/2026 : « le plus important est que ça aide le franchisé à faire ses horaires »). Les deux passent par le
   * même aperçu : ce qui sera créé, jour par jour, à cocher ; ce qui est écarté et pourquoi ; le budget de la semaine
   * avant et après ; puis la création au panel des seuls services cochés, recalculés par le serveur. ──────────────── */
  const PREP = {
    recopie: { route: '/dashboard/equipe/semaine/recopie', titre: 'Recopier la semaine précédente', cle: p => p.source, corps: c => ({ sources: c }), verbe: ['Recopier', 'recopié', 'Recopie au panel…'] },
    generer: { route: '/dashboard/equipe/semaine/generer', titre: 'Shift maker', cle: p => p.cle, corps: c => ({ cles: c }), verbe: ['Créer', 'créé', 'Création au panel…'] },
  };
  const CRENEAU = { M: 'Matin', P: 'Après-midi', R: 'Repos' };
  /** La lettre du roulement d'une personne, à côté de son nom. */
  const lettreChip = e => { const l = lettreDe(e.id) || e.lettre; return l ? ` <i class="eqp-lettre" title="Roulement ${esc(l)} (matrice A B C D)">${esc(l)}</i>` : ''; };
  function ouvrirPrep(type) {
    const du = st.lundi, T = PREP[type];
    st.rec = { type, du, etat: 'lecture', data: null, coches: new Set(), err: null, res: null };
    rendre();
    lire(T.route, { du }).then(r => { if (!st.rec || st.rec.du !== du || st.rec.type !== type) { return; } st.rec.data = r; st.rec.coches = new Set(r.propositions.map(T.cle)); st.rec.etat = 'pret'; })
      .catch(e => { if (st.rec) { st.rec.etat = 'erreur'; st.rec.err = e.message; } }).finally(rendre);
  }
  function rendRecopie() {
    const R = st.rec; if (!R) { return ''; }
    const T = PREP[R.type], fin = plus(R.du, 6), gen = R.type === 'generer';
    const titre = `<h3 id="eqp-rec-t">${T.titre}${gen ? ' <span class="tag-warn">test</span>' : ''}</h3>`;
    const cadre = (corps, actions) => `<div class="eqp-voile" data-eqp-fermer="1"><div class="eqp-mod eqp-rec card" role="dialog" aria-modal="true" aria-labelledby="eqp-rec-t" data-eqp-garde="1">${titre}${corps}<div class="confirm__actions">${actions}</div></div></div>`;
    const fermer = l => `<button type="button" class="btn btn--ghost" data-eqp-fermer="1"${R.etat === 'envoi' ? ' disabled' : ''}>${l}</button>`;
    if (R.etat === 'lecture') { return cadre(`<p class="qui">${gen ? 'La matrice, les lettres de l’équipe et la semaine au panel…' : 'Lecture des deux semaines au panel…'}</p>`, fermer('Annuler')); }
    if (R.etat === 'erreur') { return cadre(`<p class="error" role="alert">L’aperçu n’est pas venu : ${esc(R.err)}.</p>`, fermer('Fermer')); }
    const D = R.data, cle = T.cle;
    if (R.etat === 'fini') {
      const x = R.res;
      return cadre(`<p class="qui">${x.crees} service${x.crees > 1 ? 's' : ''} ${T.verbe[1]}${x.crees > 1 ? 's' : ''} sur ${x.demandes}.${x.arret ? ' Arrêt : ' + esc(x.arret) + '.' : ''}</p>${x.echecs.length ? `<div class="eqp-opt-g">Pas ${T.verbe[1]}s</div><ul class="eqp-rec-ec">${x.echecs.map(f => `<li><b>${esc(f.nom)}</b> · ${esc(court(f.date))} ${esc(f.de)}–${esc(f.a)} <span>${esc(f.motif)}</span></li>`).join('')}</ul>` : ''}`, fermer('Fermer'));
    }
    const P = st.PS && st.PS.du === R.du ? st.PS : null, eq = P ? equipeDe(P) : new Map();
    const choisis = D.propositions.filter(p => R.coches.has(cle(p)));
    const heures = choisis.reduce((a, p) => a + (mn(p.a) - mn(p.de)) / 60, 0);
    const cout = choisis.reduce((a, p) => a + (mn(p.a) - mn(p.de)) / 60 * ((eq.get(p.employe) || {}).taux || 0), 0);
    let budget = '';
    if (P) {
      const CJ = P.jours.map(J => calculJour(P, J, null)), ca = CJ.reduce((a, c) => a + (c.objectif || 0), 0);
      const avant = CJ.reduce((a, c) => a + (c.objectif ? c.cout : 0), 0);
      const ajout = choisis.filter(p => (P.jours.find(J => J.date === p.date) || {}).objectif).reduce((a, p) => a + (mn(p.a) - mn(p.de)) / 60 * ((eq.get(p.employe) || {}).taux || 0), 0);
      if (ca) {
        const apres = (avant + ajout) / ca * 100, moy = heures > 0 ? cout / heures : 0, trop = moy > 0 ? (avant + ajout - P.budget.cible / 100 * ca) / moy : 0;
        budget = `<div class="eqp-rec-b">Budget de la semaine ${marq(P, avant / ca * 100)} → ${marq(P, apres)} <span>cible ${String(P.budget.cible).replace('.', ',')} % · ${fE(ca)} de CA prévu</span>${trop > 0.5 ? `<em>Pour revenir à ${String(P.budget.cible).replace('.', ',')} % : environ ${fH(trop)} de moins (au coût moyen de ${fEh(moy)}) — décochez des services ici, ou raccourcissez-les ensuite jour par jour.</em>` : ''}</div>`;
      }
    }
    const parJour = new Map(); D.propositions.forEach(p => { if (!parJour.has(p.date)) { parJour.set(p.date, []); } parJour.get(p.date).push(p); });
    const ligne = p => `<label class="eqp-opt eqp-rec-l${R.coches.has(cle(p)) ? ' on' : ''}"><input type="checkbox" data-eqp-rec-coche="${esc(cle(p))}"${R.coches.has(cle(p)) ? ' checked' : ''}${R.etat === 'envoi' ? ' disabled' : ''}><span>${esc(p.nom)}${p.lettre ? ` <i class="eqp-lettre">${esc(p.lettre)}</i>` : ''}</span><span class="s">${p.creneau ? esc(CRENEAU[p.creneau]) + ' · ' : ''}${esc(p.de)}–${esc(p.a)} · ${fH((mn(p.a) - mn(p.de)) / 60)}</span></label>`;
    const liste = [...parJour.entries()].map(([d, L]) => `<div class="eqp-opt-g">${esc(long(d).replace(/ \d{4}$/, ''))}${gen ? '' : ` <span>← ${esc(court(plus(d, -7)))}</span>`}</div>${L.map(ligne).join('')}`).join('');
    const ecartes = D.ecartes.length ? `<details class="eqp-rec-d"><summary>${D.ecartes.length} service${D.ecartes.length > 1 ? 's' : ''} écarté${D.ecartes.length > 1 ? 's' : ''}</summary><ul class="eqp-rec-ec">${D.ecartes.map(x => `<li><b>${esc(x.nom)}</b> · ${esc(court(x.date))} ${esc(x.de)}–${esc(x.a)} <span>${esc(x.motif)}</span></li>`).join('')}</ul></details>` : '';
    const deja = D.dejaPrevus ? D.dejaPrevus + ' service' + (D.dejaPrevus > 1 ? 's sont' : ' est') + ' déjà prévu' + (D.dejaPrevus > 1 ? 's' : '') + ' cette semaine : ils restent, et rien ne les chevauche.' : 'La semaine est vide.';
    const intro = gen
      ? `<p class="qui">La semaine du ${dt(R.du).getDate()} au ${esc(long(fin).replace(/^\S+ /, ''))} est la <b>semaine ${D.cycle}</b> du roulement. Matin ${esc(D.matrice.creneaux.M.de)}–${esc(D.matrice.creneaux.M.a)}, après-midi ${esc(D.matrice.creneaux.P.de)}–${esc(D.matrice.creneaux.P.a)}. ${deja}</p>
        ${D.sansLettre.length ? `<p class="eqp-rec-sans">Sans lettre, pas générés : ${D.sansLettre.map(x => esc(x.nom)).join(', ')} — leur lettre se donne dans leur fiche (Responsabilités), ou ils se planifient à la main.</p>` : ''}`
      : `<p class="qui">Les services du ${dt(D.source.du).getDate()} au ${esc(long(D.source.au).replace(/^\S+ /, ''))}, reportés sur la semaine du ${dt(R.du).getDate()} au ${esc(long(fin).replace(/^\S+ /, ''))}. ${deja}</p>`;
    const rien = gen ? (D.sansLettre.length && !D.ecartes.length ? 'Personne n’a encore de lettre : donnez la sienne à chacun dans sa fiche.' : 'Rien à créer : tout est déjà au planning, ou les jours de cette semaine sont en repos.') : 'Rien à recopier : la semaine précédente est vide, ou tout est déjà au planning.';
    const corps = `${intro}
      ${D.propositions.length ? `<div class="eqp-rec-r"><b>${choisis.length} service${choisis.length > 1 ? 's' : ''}</b> · ${fH(heures)} · ${fE(cout)}<span class="eqp-sp"></span><button type="button" class="linklike" data-eqp-rec-tout="1">${R.coches.size === D.propositions.length ? 'Tout décocher' : 'Tout cocher'}</button></div>${budget}<div class="eqp-rec-liste">${liste}</div>` : `<p class="eqp-note">${rien}</p>`}
      ${ecartes}${R.err ? `<p class="error" role="alert">${esc(R.err)}</p>` : ''}`;
    return cadre(corps, fermer('Annuler') + (D.propositions.length ? `<button type="button" class="btn btn--primary${R.etat === 'envoi' ? ' is-busy' : ''}" data-eqp-rec-ok="1"${choisis.length && R.etat !== 'envoi' ? '' : ' disabled'}>${R.etat === 'envoi' ? T.verbe[2] : T.verbe[0] + ' ' + choisis.length + ' service' + (choisis.length > 1 ? 's' : '')}</button>` : ''));
  }
  function appliquerRecopie() {
    const R = st.rec; if (!R || R.etat !== 'pret' || !R.coches.size) { return; }
    const T = PREP[R.type];
    R.etat = 'envoi'; R.err = null; rendre();
    envoyer('POST', T.route, Object.assign({ du: R.du }, T.corps([...R.coches])))
      .then(x => { st.PS = null; lirePlanning('semaine'); st.PJ = null; st.PM = null;
        if (x.echecs && x.echecs.length) { R.etat = 'fini'; R.res = x; rendre(); } else { st.rec = null; flash('ok', x.crees + ' service' + (x.crees > 1 ? 's' : '') + ' ' + T.verbe[1] + (x.crees > 1 ? 's' : '') + ' au panel. Ajustez maintenant jour par jour.'); } })
      .catch(e => { R.etat = 'pret'; R.err = 'Rien n’est enregistré : ' + e.message + '.'; rendre(); });
  }

  /* ── La matrice A B C D (23/11/2026) : quatre roulements sur deux semaines, matin ou après-midi, un week-end sur
   * deux ; chaque case se change d'un clic (matin → après-midi → repos). La couverture dit, jour par jour, combien de
   * personnes de l'équipe (selon leurs lettres) sont du matin et de l'après-midi. ──────────────────────────────── */
  const LETTRES = ['A', 'B', 'C', 'D'], JC = ['Lu', 'Ma', 'Me', 'Je', 'Ve', 'Sa', 'Di'];
  function lireMatrice() { return lire('/dashboard/equipe/matrice').then(r => { st.M = r; }).catch(() => { st.M = null; }).finally(rendre); }
  /** La lettre de chacun : la carte la porte ; la matrice relue aussi (après un changement). */
  function lettreDe(id) { const r = st.M && st.M.roulements; return r && Object.prototype.hasOwnProperty.call(r, id) ? r[id] : null; }
  const cycleDe = (M, lundi) => { const n = Math.round((dt(lundi) - dt(M.reference)) / 86400000 / 7); return ((n % 2) + 2) % 2; };
  /** Ce que donne une lettre une semaine donnée : « matin du mercredi au dimanche · repos lundi, mardi ». */
  function resumeLettre(M, l, cyc) {
    const sem = M.lettres[l][cyc], noms = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    const parts = ['M', 'P'].map(c => { const j = sem.map((x, i) => x === c ? i : -1).filter(i => i >= 0); if (!j.length) { return ''; } const suite = j.every((x, k) => !k || x === j[k - 1] + 1);
      return CRENEAU[c].toLowerCase() + ' ' + (suite && j.length > 2 ? 'du ' + noms[j[0]] + ' au ' + noms[j[j.length - 1]] : j.map(i => noms[i]).join(', ')); }).filter(Boolean);
    const r = sem.map((x, i) => x === 'R' ? noms[i] : '').filter(Boolean);
    return parts.join(' · ') + (r.length ? ' · repos ' + r.join(', ') : '');
  }
  function ouvrirMatrice() { if (!st.M) { return; } st.mat = { m: JSON.parse(JSON.stringify(st.M.matrice)), err: null, occupe: false }; rendre(); }
  function rendMatrice() {
    if (!st.mat || !st.M) { return ''; }
    const m = st.mat.m, equipe = (st.R ? st.R.employes : []), parL = {}; LETTRES.forEach(l => { parL[l] = equipe.filter(e => (lettreDe(e.id) || e.lettre) === l); });
    const cel = (l, w, d) => { const c = m.lettres[l][w][d]; return `<td><button type="button" class="eqp-mx ${c}" data-eqp-mx="${l}${w}${d}" title="${CRENEAU[c]} — un clic change">${c === 'M' ? 'M' : c === 'P' ? 'AP' : '·'}</button></td>`; };
    const couv = w => JC.map((_, d) => { const nM = LETTRES.reduce((a, l) => a + (m.lettres[l][w][d] === 'M' ? parL[l].length : 0), 0), nP = LETTRES.reduce((a, l) => a + (m.lettres[l][w][d] === 'P' ? parL[l].length : 0), 0);
      return `<td class="eqp-mx-c${(!nM || !nP) && equipe.length ? ' ko' : ''}">${nM}<i>·</i>${nP}</td>`; }).join('');
    const cyc = cycleDe(m, lundiDe(AUJ));
    return `<div class="eqp-voile" data-eqp-fermer="1"><div class="eqp-mod eqp-mat card" role="dialog" aria-modal="true" aria-labelledby="eqp-mat-t" data-eqp-garde="1">
      <h3 id="eqp-mat-t">La matrice A B C D</h3><p class="qui">Quatre roulements sur deux semaines : matin ou après-midi, un week-end sur deux. Chacun porte sa lettre (sa fiche, onglet Responsabilités) ; le shift maker en tire la semaine. Un clic sur une case : matin → après-midi → repos.</p>
      <div class="eqp-mat-cr"><span>Matin</span><label class="eqp-f">de <input type="time" step="300" value="${esc(m.creneaux.M.de)}" data-eqp-cr="M.de"></label><label class="eqp-f">à <input type="time" step="300" value="${esc(m.creneaux.M.a)}" data-eqp-cr="M.a"></label>
        <span>Après-midi</span><label class="eqp-f">de <input type="time" step="300" value="${esc(m.creneaux.P.de)}" data-eqp-cr="P.de"></label><label class="eqp-f">à <input type="time" step="300" value="${esc(m.creneaux.P.a)}" data-eqp-cr="P.a"></label></div>
      <div class="eqp-mat-cr"><span>La semaine 1 est la semaine du</span><label class="eqp-f"><input type="date" value="${esc(m.reference)}" data-eqp-ref="1"></label><span class="eqp-note" style="margin:0">cette semaine-ci : semaine ${cyc + 1}</span></div>
      <div class="eqp-mat-t"><table><thead><tr><th></th><th colspan="7" class="${cyc === 0 ? 'on' : ''}">Semaine 1</th><th colspan="7" class="${cyc === 1 ? 'on' : ''}">Semaine 2</th><th>Équipe</th></tr>
        <tr><th></th>${[0, 1].map(() => JC.map(j => `<th>${j}</th>`).join('')).join('')}<th></th></tr></thead>
        <tbody>${LETTRES.map(l => `<tr><th><i class="eqp-lettre">${l}</i></th>${[0, 1].map(w => JC.map((_, d) => cel(l, w, d)).join('')).join('')}<td class="eqp-mx-e">${parL[l].length ? parL[l].map(e => esc(e.nom.split(' ')[0])).join(', ') : '<span class="eqp-tiret">personne</span>'}</td></tr>`).join('')}
        <tr class="tot"><th>Matin · AP</th>${couv(0)}${couv(1)}<td class="eqp-mx-e">personnes de l’équipe</td></tr></tbody></table></div>
      ${st.mat.err ? `<p class="error" role="alert">${esc(st.mat.err)}</p>` : ''}
      <div class="confirm__actions"><button type="button" class="linklike" data-eqp-mat-depart="1" style="margin-right:auto">Revenir à la matrice de départ</button><button type="button" class="btn btn--ghost" data-eqp-fermer="1">Annuler</button><button type="button" class="btn btn--primary${st.mat.occupe ? ' is-busy' : ''}" data-eqp-mat-ok="1"${st.mat.occupe ? ' disabled' : ''}>Enregistrer la matrice</button></div>
    </div></div>`;
  }
  function enregistrerMatrice(depart) {
    const x = st.mat; if (!x || x.occupe) { return; }
    x.occupe = true; x.err = null; rendre();
    envoyer('POST', '/dashboard/equipe/matrice', depart ? { depart: true } : { matrice: x.m })
      .then(r => { st.M = r; if (depart) { x.m = JSON.parse(JSON.stringify(r.matrice)); x.occupe = false; rendre(); } else { st.mat = null; flash('ok', 'Matrice enregistrée.'); } })
      .catch(e => { x.occupe = false; x.err = 'La matrice n’est pas enregistrée : ' + e.message + '.'; rendre(); });
  }
  function choisirLettre(emp, l) {
    if (st.occupe) { return; }
    st.occupe = true;
    envoyer('POST', '/dashboard/equipe/roulement', { employe: emp, lettre: l })
      .then(r => { if (st.M) { st.M.roulements = r.roulements; } const e = st.R && st.R.employes.find(x => x.id === emp); if (e) { e.lettre = r.lettre; } [st.PS, st.PJ].forEach(P => { const x = P && (P.employes || []).find(y => y.id === emp); if (x) { x.lettre = r.lettre; } });
        st.occupe = false; flash('ok', l ? 'Roulement ' + l + ' enregistré.' : 'Roulement retiré.'); })
      .catch(e => { st.occupe = false; flash('ko', 'Le roulement n’est pas enregistré : ' + e.message + '.'); });
  }
  /** Le bloc « Roulement » de la fiche : la lettre, et ce qu'elle donne cette semaine et la suivante. */
  function rendRoulement(e) {
    const l = lettreDe(e.id) || e.lettre || null, M = st.M && st.M.matrice;
    const seg = `<div class="seg eqp-seg-l" role="group" aria-label="Roulement">${LETTRES.map(x => `<button type="button" class="${l === x ? 'is-on' : ''}" data-eqp-lettre="${x}" data-emp="${e.id}">${x}</button>`).join('')}<button type="button" class="${l ? '' : 'is-on'}" data-eqp-lettre="" data-emp="${e.id}">aucun</button></div>`;
    const c = M ? cycleDe(M, lundiDe(AUJ)) : 0;
    return `<h3>Roulement · matrice A B C D</h3>${seg}${l && M ? `<ul class="eqp-roul"><li><b>Cette semaine</b> (semaine ${c + 1}) : ${esc(resumeLettre(M, l, c))}</li><li><b>La suivante</b> (semaine ${2 - c}) : ${esc(resumeLettre(M, l, 1 - c))}</li></ul>` : `<p class="eqp-note" style="margin-top:6px">${l ? '' : 'Sans lettre, le shift maker ne la planifie pas.'}</p>`}`;
  }

  /* ── Les gestes ──────────────────────────────────────────────────────────────────────────────────────────────── */
  function brancher() {
    const el = st.el, on = (sel, ev, f) => el.querySelectorAll(sel).forEach(n => n.addEventListener(ev, f));
    on('[data-eqp-onglet]', 'click', e => { st.onglet = e.currentTarget.dataset.eqpOnglet; st.edit = null; if (st.onglet === 'planning') { chargerVue(); } rendre(); });
    on('[data-eqp-relire]', 'click', () => relireTout());
    on('[data-eqp-sel]', 'click', e => { st.sel = +e.currentTarget.dataset.eqpSel; st.tousProduits = false; rendre(); });
    on('[data-eqp-q]', 'input', e => { st.q = e.target.value; const p = e.target.selectionStart; rendre(); const n = el.querySelector('[data-eqp-q]'); if (n) { n.focus(); n.setSelectionRange(p, p); } });
    on('[data-eqp-secteur]', 'change', e => { st.secteur = e.target.value; rendre(); });
    on('[data-eqp-tous]', 'click', () => { st.tousProduits = !st.tousProduits; rendre(); });
    on('[data-eqp-postes]', 'click', e => { const id = +e.currentTarget.dataset.eqpPostes; if (id) { ouvrirPostes(id); } });
    on('[data-eqp-aller-planning]', 'click', () => { st.onglet = 'planning'; st.vue = 'tableau'; st.lundi = lundiDe(AUJ); chargerVue(); rendre(); });
    on('[data-eqp-coche]', 'change', e => { const id = +e.target.dataset.eqpCoche; if (e.target.checked) { st.mod.coches.add(id); } else { st.mod.coches.delete(id); } rendre(); });
    on('[data-eqp-fermer]', 'click', e => { if (e.target !== e.currentTarget || (st.mod && st.mod.occupe) || (st.rec && st.rec.etat === 'envoi') || (st.mat && st.mat.occupe)) { return; } st.mod = null; st.rec = null; st.mat = null; rendre(); });
    on('[data-eqp-recopier]', 'click', () => ouvrirPrep('recopie'));
    on('[data-eqp-generer]', 'click', () => ouvrirPrep('generer'));
    on('[data-eqp-rec-coche]', 'change', e => { const v = e.target.dataset.eqpRecCoche, id = st.rec.type === 'recopie' ? +v : v; if (e.target.checked) { st.rec.coches.add(id); } else { st.rec.coches.delete(id); } rendre(); });
    on('[data-eqp-rec-tout]', 'click', () => { const R = st.rec; const tous = R.coches.size === R.data.propositions.length; R.coches = new Set(tous ? [] : R.data.propositions.map(PREP[R.type].cle)); rendre(); });
    on('[data-eqp-matrice]', 'click', ouvrirMatrice);
    on('[data-eqp-mx]', 'click', e => { const k = e.currentTarget.dataset.eqpMx, L = st.mat.m.lettres[k[0]][+k[1]], d = +k[2]; L[d] = { M: 'P', P: 'R', R: 'M' }[L[d]]; rendre(); });
    on('[data-eqp-cr]', 'change', e => { const [c, b] = e.target.dataset.eqpCr.split('.'); st.mat.m.creneaux[c][b] = e.target.value; });
    on('[data-eqp-ref]', 'change', e => { if (e.target.value) { st.mat.m.reference = e.target.value; rendre(); } });
    on('[data-eqp-mat-ok]', 'click', () => enregistrerMatrice(false));
    on('[data-eqp-mat-depart]', 'click', () => enregistrerMatrice(true));
    on('[data-eqp-lettre]', 'click', e => { const b = e.currentTarget; choisirLettre(+b.dataset.emp, b.dataset.eqpLettre || null); });
    on('[data-eqp-rec-ok]', 'click', appliquerRecopie);
    on('[data-eqp-enregistrer-postes]', 'click', enregistrerPostes);
    on('[data-eqp-vue]', 'click', e => {
      // La date qu'on regarde suit d'une vue à l'autre : le jour ouvert, la semaine montrée, ou le mois (aujourd'hui s'il en est).
      const ref = st.vue === 'jour' ? st.jour : st.vue === 'mois' ? (moisDe(AUJ) === st.mois ? AUJ : st.mois) : (st.lundi === lundiDe(AUJ) ? AUJ : st.lundi);
      st.vue = e.currentTarget.dataset.eqpVue; st.edit = null;
      if (st.vue === 'tableau') { st.lundi = lundiDe(ref); } else if (st.vue === 'mois') { st.mois = moisDe(ref); } else { st.jour = ref; }
      chargerVue(); rendre();
    });
    on('[data-eqp-pas]', 'click', e => { const n = +e.currentTarget.dataset.eqpPas; st.edit = null; if (st.vue === 'jour') { st.jour = plus(st.jour, n); } else if (st.vue === 'mois') { st.mois = moisPlus(st.mois, n); } else { st.lundi = plus(st.lundi, n); } chargerVue(); rendre(); });
    on('[data-eqp-auj]', 'click', () => { st.edit = null; st.jour = AUJ; st.lundi = lundiDe(AUJ); st.mois = moisDe(AUJ); chargerVue(); rendre(); });
    on('[data-eqp-semaine]', 'click', e => { st.lundi = e.currentTarget.dataset.eqpSemaine; st.vue = 'tableau'; st.edit = null; chargerVue(); rendre(); });
    on('[data-eqp-jour]', 'click', e => { st.jour = e.currentTarget.dataset.eqpJour; st.vue = 'jour'; st.edit = null; chargerVue(); rendre(); });
    on('[data-eqp-pdf]', 'click', () => { st.pdf = st.pdf ? null : { du: st.vue === 'jour' ? lundiDe(st.jour) : st.vue === 'mois' ? lundiDe(st.mois) : st.lundi, jours: 7, pres: 'magasin' }; rendre(); });
    on('[data-eqp-pdfdu]', 'change', e => { st.pdf.du = e.target.value; });
    on('[data-eqp-pdfjours]', 'change', e => { st.pdf.jours = +e.target.value; });
    on('[data-eqp-pdfpres]', 'change', e => { st.pdf.pres = e.target.value; });
    on('[data-eqp-pdfok]', 'click', telechargerPdf);
    on('[data-eqp-annuler]', 'click', () => { st.edit = null; rendre(); });
    on('[data-eqp-cell]', 'click', e => {
      if (e.target.closest('[data-eqp-garde]')) { return; }
      const c = e.currentTarget, sv = e.target.closest('[data-eqp-svc]'), emp = +c.dataset.emp, P = planVue();
      const x = P && (P.employes || []).find(y => y.id === emp); if (!x || (!sv && x.actif === false)) { return; }
      ouvrirEdition({ mode: 'modal', id: sv ? +sv.dataset.eqpSvc : 0, employe: emp, date: c.dataset.eqpCell });
    });
    on('[data-eqp-ajouter]', 'click', () => {
      const P = planVue(); if (!P) { return; }
      const d = P.jours.some(J => J.date === AUJ) ? AUJ : P.jours[0].date;
      ouvrirEdition({ mode: st.vue === 'jour' ? 'modal' : 'modal', id: 0, employe: null, date: d });
    });
    on('[data-eqp-ed-emp]', 'change', e => { const ed = st.edit; ed.employe = +e.target.value || null; if (ed.employe && !ed.toucheHeures) { [ed.de, ed.a] = defautCreneau(ed.employe, ed.date); } rendre(); });
    on('[data-eqp-ed-date]', 'change', e => { st.edit.date = e.target.value; rendre(); });
    on('[data-eqp-cren]', 'click', e => { const M = st.M.matrice, c = M.creneaux[e.currentTarget.dataset.eqpCren]; st.edit.de = mn(c.de); st.edit.a = mn(c.a); st.edit.toucheHeures = true; rendre(); });
    on('[data-eqp-ouvrir-svc]', 'click', e => { const ed = st.edit; ouvrirEdition({ mode: ed.mode, id: +e.currentTarget.dataset.eqpOuvrirSvc, date: ed.date }); });
    on('[data-eqp-retirer]', 'click', e => { const b = e.currentTarget; if (window.confirm('Retirer ' + b.dataset.nom + ' de ce shift ? Son service de ce jour est supprimé du planning.')) { ecrireShift('DELETE', { id: +b.dataset.eqpRetirer, date: st.edit.date }, b.dataset.nom.split(' ')[0] + ' est retiré(e) du shift.', true); } });
    on('[data-eqp-ajout-sel]', 'change', e => { st.edit.ajout = +e.target.value || null; rendre(); });
    on('[data-eqp-ajout-ok]', 'click', () => { const ed = st.edit; if (!ed.ajout) { return; } const n = ((equipeDe(ctxEdition(ed)[0]).get(ed.ajout) || {}).nom || '').split(' ')[0];
      ecrireShift('POST', { employe: ed.ajout, date: ed.date, de: hh(ed.de), a: hh(ed.a) }, n + ' est ajouté(e) au shift ' + hh(ed.de) + '–' + hh(ed.a) + '.', true); });
    on('[data-eqp-ok]', 'click', () => enregistrerService(false));
    on('[data-eqp-ok-suivant]', 'click', e => enregistrerService(false, +e.currentTarget.dataset.eqpOkSuivant));
    on('[data-eqp-fo-aller]', 'click', e => allerPersonne(+e.currentTarget.dataset.eqpFoAller));
    on('[data-eqp-fo-section]', 'click', e => {
      st.edSection = e.currentTarget.dataset.eqpFoSection || null; const ed = st.edit; if (!ed) { return; }
      const [P] = ctxEdition(ed), g = P && st.edSection ? groupes(P, equipeDe(P)).find(([n]) => n === st.edSection) : null;
      const L = g ? g[1].filter(x => x.actif !== false) : [];
      if (g && L.length && !L.some(x => x.id === ed.employe)) { allerPersonne(L[0].id); } else { rendre(); }
    });
    on('[data-eqp-suppr]', 'click', () => { if (window.confirm('Retirer cette personne du shift ? Son service de ce jour est supprimé du planning.')) { enregistrerService(true); } });
    on('[data-eqp-trace]', 'pointerdown', traceDebut);
    on('[data-eqp-poignee]', 'keydown', e => {
      const k = e.key, ed = st.edit, b = e.currentTarget.dataset.eqpPoignee, pas = e.shiftKey ? 60 : 15;
      if (!ed || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(k)) { return; }
      e.preventDefault(); const s2 = k === 'ArrowLeft' || k === 'ArrowDown' ? -pas : pas;
      if (b === 'de') { ed.de = Math.max(0, Math.min(ed.a - 15, ed.de + s2)); } else { ed.a = Math.min(24 * 60, Math.max(ed.de + 15, ed.a + s2)); }
      ed.toucheHeures = true; rendre(); const n = st.el.querySelector(`[data-eqp-poignee="${b}"]`); if (n) { n.focus(); }
    });
    on('[data-eqp-garde]', 'pointerdown', e => e.stopPropagation());
    on('[data-eqp-tl]', 'pointerdown', glisserDebut);
  }
  function chargerVue() {
    if (st.vue === 'jour') { if (!st.PJ || st.PJ.du !== st.jour) { st.PJ = null; lirePlanning('jour'); } }
    else if (st.vue === 'mois') { if (!st.PM || st.PM.du !== st.mois) { st.PM = null; lirePlanning('mois'); } }
    else if (!st.PS || st.PS.du !== st.lundi) { st.PS = null; lirePlanning('semaine'); }
  }

  /** Glisser sur le linéaire : déplacer une barre, l'étirer par un bord, ou dessiner un nouveau service sur une ligne. */
  function glisserDebut(ev) {
    if (ev.button !== 0 || !st.PJ || st.PJ.du !== st.jour) { return; }
    if (ev.target.closest('button, input, select, textarea, a, label')) { return; }
    const tl = ev.currentTarget, emp = +tl.dataset.eqpTl, P = st.PJ, J = P.jours[0];
    const e = (P.employes || []).find(x => x.id === emp); if (!e || e.actif === false) { return; }
    const [H0, H1] = bornes(P, calculJour(P, J, st.edit).sv), r = tl.getBoundingClientRect();
    const aMin = x => H0 + (x - r.left) / r.width * (H1 - H0), q15 = m => Math.round(m / 15) * 15;
    const bar = ev.target.closest('[data-eqp-bar]'), bord = ev.target.dataset.bord || null;
    let g;
    if (bar) {
      const id = +bar.dataset.eqpBar, s = calculJour(P, J, st.edit).sv.find(x => x.id === id && x.employe === emp);
      if (!s) { return; }
      g = { mode: bord || 'deplacer', id, employe: emp, de0: s.de, a0: s.a, x0: aMin(ev.clientX) };
    } else {
      const m = q15(aMin(ev.clientX));
      g = { mode: 'nouveau', id: 0, employe: emp, de0: m, a0: m, x0: m };
    }
    ev.preventDefault();
    st.glisse = g;
    const bouge = e2 => {
      const m = aMin(e2.clientX), d = q15(m - g.x0);
      let de = g.de0, a = g.a0;
      if (g.mode === 'deplacer') { de = g.de0 + d; a = g.a0 + d; if (de < H0) { a += H0 - de; de = H0; } if (a > H1) { de -= a - H1; a = H1; } }
      else if (g.mode === 'g') { de = Math.min(g.a0 - 15, Math.max(H0, g.de0 + d)); }
      else if (g.mode === 'd') { a = Math.max(g.de0 + 15, Math.min(H1, g.a0 + d)); }
      else { const x = q15(m); de = Math.max(H0, Math.min(g.x0, x)); a = Math.min(H1, Math.max(g.x0, x)); }
      g.de = de; g.a = a;
      const n = g.mode === 'nouveau' ? null : tl.querySelector(`[data-eqp-bar="${g.id}"]`);
      const X = v => ((v - H0) / (H1 - H0) * 100).toFixed(3) + '%';
      if (n) { n.style.left = X(de); n.style.width = ((a - de) / (H1 - H0) * 100).toFixed(3) + '%'; const b = n.querySelector('b'); if (b) { b.textContent = hh(de) + '–' + hh(a); } }
      else if (g.mode === 'nouveau') {
        let f = tl.querySelector('.eqp-bar.trace'); if (!f) { f = document.createElement('span'); f.className = 'eqp-bar sel trace'; tl.appendChild(f); }
        f.style.left = X(de); f.style.width = ((a - de) / (H1 - H0) * 100).toFixed(3) + '%'; f.textContent = a > de ? hh(de) + '–' + hh(a) + ' · ' + fH((a - de) / 60) : hh(de);
      }
    };
    const fin = () => {
      document.removeEventListener('pointermove', bouge); document.removeEventListener('pointerup', fin);
      st.glisse = null;
      let de = g.de != null ? g.de : g.de0, a = g.a != null ? g.a : g.a0;
      // Un simple clic sur une ligne vide propose le créneau de sa lettre s'il contient l'heure cliquée, sinon quatre
      // heures ; un clic sur une barre l'ouvre telle quelle.
      if (g.mode === 'nouveau' && a - de < 30) { const c = defautCreneau(g.employe, J.date); if (c[0] <= g.x0 && g.x0 < c[1]) { [de, a] = c; } else { de = q15(g.x0); a = Math.min(H1, de + 240); } }
      st.edit = { mode: 'modal', id: g.id, employe: g.employe, date: J.date, de, a, err: null, occupe: false, ajout: null };
      rendre();
    };
    document.addEventListener('pointermove', bouge); document.addEventListener('pointerup', fin);
  }

  window.DashEquipe = {
    racine(shop, o) {
      o = o || {};
      if (!st.el || st.shop !== String(shop)) {
        Object.assign(st, { shop: String(shop), R: null, PJ: null, PS: null, edit: null, mod: null, sel: null });
        st.el = document.createElement('div'); st.el.className = 'eqp';
        st.api = o.apiBase || st.api; st.aller = o.aller || null; st.bureau = o.bureau || null;
        rendre(); relireTout();
      } else { st.bureau = o.bureau || st.bureau; rendre(); }
      return st.el;
    },
    relire() { if (st.el) { st.PJ = null; st.PS = null; relireTout(); if (st.onglet === 'planning') { chargerVue(); } } },
  };
})();
