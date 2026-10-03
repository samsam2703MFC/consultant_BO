/*
 * La production d'un magasin — l'application /production (demande du 03/10/2026).
 *
 * Quatre pages, dans l'ordre de la journée :
 *   1. Paramètres — par jour de la semaine, le nombre de cuissons et la production minimum de
 *      la 1re cuisson ; les cuissons et les règles ; les catégories (cuissons, plaque, préparée
 *      la veille, se garde au lendemain) ; les produits obligatoires et leurs jours.
 *   2. Plan — catégorie › sous-catégorie › produit : vendu à J−7 (en magasin, webshop,
 *      commandes magasin), à produire pour la 1re période, à préparer pour la 2e cuisson et les
 *      suivantes, à préparer pour la 1re cuisson du lendemain.
 *   3. Validation et suivi — ce qui est réellement sorti, cuisson par cuisson ; le stock heure
 *      par heure et le manque prévu d'après les 6 derniers mêmes jours.
 *   4. Clôture — ce qui reste, ce qui se garde pour demain, ce qui se jette.
 *
 * Lectures : ../api/cockpit/production/flux/{params,plan,suivi,cloture}. Écritures :
 * POST …/params, …/valider, …/cloture — rien ne part au panel.
 * ?embed=1 : sans en-tête, pour l'écran Gestion de production du cockpit (le magasin et la page
 * viennent du cockpit ; le jour se choisit dans la barre des jours de la page, et le cockpit retient
 * celui qu'elle lui renvoie par postMessage).
 */
(function () {
  'use strict';
  const API = '../api/cockpit';
  const q = new URLSearchParams(location.search);
  const EMBED = q.get('embed') === '1';
  const PAGES = [['params', 'Paramètres'], ['plan', 'Plan de production'], ['suivi', 'Validation et suivi'], ['cloture', 'Clôture'], ['fours', 'Fours']];
  const FUTUR = p => p === 'plan' || p === 'fours';   // les pages qui se préparent jusqu'à J+7
  const AUJ = (() => { const t = new Date(); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0'); })();
  const dateOk = d => /^\d{4}-\d{2}-\d{2}$/.test(d || '');
  const decale = (d, n) => { const t = new Date(d + 'T12:00:00'); t.setDate(t.getDate() + n); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0'); };
  const S = {
    shop: q.get('shop') || '4',
    page: PAGES.some(p => p[0] === q.get('page')) ? q.get('page') : 'plan',
    date: dateOk(q.get('date')) ? q.get('date') : AUJ,   // ramené à aujourd'hui hors du plan, au départ
    stores: [], data: {}, err: {}, enCours: {},
    edit: null, editShop: null, editF: null, editFCle: null, ganttF: null, filtre: '', seulsOblig: false, alertes: false, ecartsJ7: false,
    valid: null, clot: null, msg: null, envoi: false,
    par: (() => { try { return localStorage.getItem('pf.par') || ''; } catch (e) { return ''; } })(),
  };
  const $ = document.getElementById('pf');

  /* --- formats ------------------------------------------------------------ */
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 });
  const fN = n => n == null ? '—' : nf(Math.round(n));
  const fQ = n => n == null ? '—' : (Math.abs(n - Math.round(n)) < 0.05 ? nf(Math.round(n)) : nf(n, 1));
  const fE = n => n == null ? '—' : nf(Math.round(n)) + ' €';
  const fP = n => n == null ? '—' : nf(n, n % 1 ? 1 : 0) + ' %';
  const fD = d => d ? d.slice(8, 10) + '/' + d.slice(5, 7) : '';
  const fDL = d => { if (!d) { return ''; } const t = new Date(d + 'T12:00:00'); return t.toLocaleDateString('fr-BE', { weekday: 'long', day: 'numeric', month: 'long' }); };
  const hDe = s => { const m = /^(\d{1,2}):(\d{2})/.exec(s || ''); return m ? +m[1] + +m[2] / 60 : null; };
  const hh = h => String(Math.floor(h)).padStart(2, '0') + ':' + String(Math.round((h % 1) * 60)).padStart(2, '0');
  const pl = (n, mot) => fN(n) + ' ' + mot + (Math.abs(n) >= 2 ? 's' : '');
  const JOURS_C = { 1: 'L', 2: 'M', 3: 'M', 4: 'J', 5: 'V', 6: 'S', 7: 'D' };

  /* --- lecture, écriture ----------------------------------------------------- */
  function lire(path) {
    return fetch(API + path, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : r.json().then(j => Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status))), () => Promise.reject(new Error('HTTP ' + r.status))));
  }
  function ecrire(path, corps) {
    return fetch(API + path, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(corps) })
      .then(r => r.ok ? r.json() : r.json().then(j => Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status))), () => Promise.reject(new Error('HTTP ' + r.status))));
  }
  const cle = () => S.page + '|' + S.shop + '|' + (S.page === 'params' ? '' : S.date);
  const chemin = () => '/production/flux/' + S.page + '?shop=' + encodeURIComponent(S.shop) + (S.page === 'params' ? '' : '&date=' + S.date);
  function charger(force) {
    const k = cle();
    if (S.enCours[k] || (!force && S.data[k])) { rendre(); return; }
    S.enCours[k] = true; delete S.err[k];
    rendre();
    lire(chemin()).then(d => { S.data[k] = d; S.err[k] = null; if (S.page === 'params' && (force || S.editShop !== S.shop)) { S.edit = brouillon(d); S.editShop = S.shop; } })
      .catch(e => { S.err[k] = e.message || 'lecture impossible'; })
      .finally(() => { S.enCours[k] = false; rendre(); });
  }
  function urlMaj() {
    const u = new URLSearchParams(location.search);
    u.set('shop', S.shop); u.set('page', S.page); u.set('date', S.date);
    history.replaceState(null, '', location.pathname + '?' + u.toString());
  }
  function aller(page) { S.page = page; S.valid = null; S.clot = null; S.msg = null; if (!FUTUR(page) && S.date > AUJ) { S.date = AUJ; } urlMaj(); charger(false); }
  function signe(v) { S.par = v; try { localStorage.setItem('pf.par', v); } catch (e) { /* navigation privée */ } }

  /* --- le cadre --------------------------------------------------------------- */
  function entete() {
    if (EMBED) { return ''; }
    return `<div class="pf-hd"><img src="../assets/img/logo.png" alt=""><div><div class="pf-titre">Production</div><div class="pf-sous">${esc(nomShop())} · ${esc(fDL(S.date))}${S.date === AUJ ? ' · aujourd’hui' : (S.date === decale(AUJ, 1) ? ' · demain' : '')}</div></div><span class="sp"></span>
      <label class="pf-lab">Magasin <select id="pf-shop">${(S.stores.length ? S.stores : [{ id: S.shop, nom: 'Magasin ' + S.shop }]).map(s => `<option value="${esc(s.id)}"${String(s.id) === String(S.shop) ? ' selected' : ''}>${esc(s.nom)}</option>`).join('')}</select></label>
      </div>
      <div class="pf-ong">${PAGES.map((p, i) => `<button data-page="${p[0]}" class="${S.page === p[0] ? 'on' : ''}"><b>${i + 1}</b>${p[1]}</button>`).join('')}</div>`;
  }
  /* La barre des jours (demande du 03/10/2026) : le plan se choisit d'hier à J+7, la validation
   * et la clôture sur les sept derniers jours ; une autre date au calendrier. Elle est dans la page,
   * donc aussi dans l'écran intégré au cockpit. */
  const JOURS_L = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
  const maxDate = () => FUTUR(S.page) ? decale(AUJ, 7) : AUJ;
  function barreJours() {
    if (S.page === 'params') { return ''; }
    const plan = FUTUR(S.page);
    const ecarts = plan ? [-1, 0, 1, 2, 3, 4, 5, 6, 7] : [-6, -5, -4, -3, -2, -1, 0];
    const dedans = ecarts.some(n => decale(AUJ, n) === S.date);
    return `<div class="pf-jours"><span class="pf-k">${plan ? 'Jour du plan' : 'Jour'}</span><div class="ch">${ecarts.map(n => { const d = decale(AUJ, n), t = new Date(d + 'T12:00:00');
      const lib = n === 0 ? 'Aujourd’hui' : (n === 1 ? 'Demain' : (n === -1 ? 'Hier' : JOURS_L[t.getDay()]));
      return `<button data-jour="${d}" class="${d === S.date ? 'on' : ''}${n === 0 ? ' auj' : ''}"><b>${lib}</b><small>${n === 0 || n === 1 || n === -1 ? JOURS_L[t.getDay()].slice(0, 3) + '. ' : ''}${fD(d)}</small></button>`; }).join('')}</div>
      <span class="autre"><button class="pf-btn" data-pas="-1" title="jour précédent">‹</button><input type="date" id="pf-date" class="${dedans ? '' : 'on'}" value="${S.date}" max="${maxDate()}"><button class="pf-btn" data-pas="1" title="jour suivant"${S.date >= maxDate() ? ' disabled' : ''}>›</button></span></div>`;
  }
  /** Changer de jour : la page relit, l'adresse suit, et le cockpit qui l'intègre retient le jour. */
  function choisirJour(d) {
    if (!dateOk(d) || d > maxDate()) { return; }
    S.date = d; S.valid = null; S.clot = null; S.msg = null;
    urlMaj();
    if (EMBED) { try { window.parent.postMessage({ pfDate: d }, location.origin); } catch (e) { /* hors cockpit */ } }
    charger(false);
  }
  function nomShop() { const s = S.stores.find(x => String(x.id) === String(S.shop)); return s ? s.nom : 'Magasin ' + S.shop; }
  function attente(n) { return `<div class="pf-card">${Array.from({ length: n || 6 }, () => '<div class="pf-sk"></div>').join('')}</div>`; }
  function message() { return S.msg ? `<div class="pf-msg ${S.msg.ok ? 'ok' : 'ko'}">${esc(S.msg.t)}</div>` : ''; }

  function rendre() {
    const k = cle(), d = S.data[k];
    let h = entete() + message() + barreJours();
    if (S.err[k]) { h += `<div class="pf-err">${esc(S.err[k])} <button class="pf-btn" data-relire="1">Relire</button></div>`; }
    else if (!d) { h += `<div class="pf-note">Lecture des ventes et des réglages… la première lecture d’un jour relit les tickets des six dernières semaines.</div>` + attente(8); }
    else if (S.page === 'params') { h += pageParams(d); }
    else if (S.page === 'plan') { h += pagePlan(d); }
    else if (S.page === 'suivi') { h += pageSuivi(d); }
    else if (S.page === 'fours') { h += pageFours(d); }
    else { h += pageCloture(d); }
    const garde = garderFocus();
    $.innerHTML = h;
    brancher();
    rendreFocus(garde);
  }
  function garderFocus() { const a = document.activeElement; return a && a.dataset && a.dataset.f && $.contains(a) ? { f: a.dataset.f, s: a.selectionStart } : null; }
  function rendreFocus(g) { if (!g) { return; } const el = $.querySelector(`[data-f="${g.f.replace(/"/g, '\\"')}"]`); if (el) { el.focus(); try { if (g.s != null && el.setSelectionRange) { el.setSelectionRange(g.s, g.s); } } catch (e) { /* champ numérique */ } } }

  /* --- 1. Paramètres ------------------------------------------------------------ */
  function brouillon(d) {
    const cats = {}; (d.categories || []).forEach(c => { cats[c.cle] = { cuissons: (c.cuissons || []).slice(), parts: c.parts ? Object.assign({}, c.parts) : null, plaque: c.plaque, limite: c.limite, nom: c.nom, catId: c.catId, groupe: c.groupe, veille: !!c.veille, garde: !!c.garde, auto: !!c.auto, stockMin: c.stockMin || 0 }; });
    const ob = {}; (d.produits || []).forEach(p => { if (p.oblig) { ob[p.pid] = { jours: (p.oblig.jours || []).slice(), min: p.oblig.min, reseau: !!p.oblig.reseau }; } });
    const jours = {}; Object.keys(d.flux.jours).forEach(j => { jours[j] = { cuissons: d.flux.jours[j].cuissons, minPct: d.flux.jours[j].minPct }; });
    return { cuissons: d.cuissons.map(c => Object.assign({}, c)), regles: Object.assign({}, d.regles), categories: cats, jours, oblig: ob, ajusterJ7: d.flux.ajusterJ7 !== false, modePeu: d.flux.modePeu === 'stock' ? 'stock' : 'production' };
  }
  /** Les parts de la journée ramenées aux cuissons d'une catégorie : {id: %}. */
  function partsDefaut(E, c) {
    const ids = E.cuissons.map(x => x.id).filter(id => c.cuissons.includes(id));
    const tot = E.cuissons.filter(x => ids.includes(x.id)).reduce((a, x) => a + (+x.pct || 0), 0);
    const o = {}; ids.forEach(id => { const x = E.cuissons.find(y => y.id === id); o[id] = tot > 0 ? Math.round(100 * (+x.pct || 0) / tot) : Math.round(100 / ids.length); });
    return o;
  }
  const totPart = (t, propre) => propre ? (Math.abs(t - 100) < 0.5 ? `<b>${fN(t)} %</b>` : `<b class="wa" title="les parts se répartissent au prorata">${fN(t)} %</b>`) : `<span class="mu">${t ? fN(t) + ' %' : '—'}</span>`;
  function pageParams(d) {
    const E = S.edit || (S.edit = brouillon(d));
    const C = E.cuissons, nC = C.length, av = +E.regles.avance || 0;
    const totPct = C.reduce((a, c) => a + (+c.pct || 0), 0);
    let h = `<div class="pf-intro"><b>Les réglages de production du magasin.</b> Ils valent pour chaque jour : le plan, la validation et la clôture les relisent. La prévision est la moyenne des ${E.regles.semaines} derniers mêmes jours, heure par heure${d.base ? ` (${d.base.lus} sur ${d.base.jours} lus pour demain)` : ''}.${d.flux.maj ? ` Dernier enregistrement le ${esc(fD(d.flux.maj.slice(0, 10)))} à ${esc(d.flux.maj.slice(11, 16))}${d.flux.par ? ' par ' + esc(d.flux.par) : ''}.` : ' Rien n’est encore enregistré : ce sont les valeurs proposées.'}</div>`;
    // Les jours de la semaine.
    h += `<div class="pf-card"><div class="pf-ct"><span class="pf-k">1 · Les jours de la semaine</span><span class="pf-mini">le nombre de cuissons du jour et la production minimum de la 1re cuisson, en % de la journée</span></div>
      <table class="pf-tab"><thead><tr><th>Jour</th><th class="n">Cuissons</th><th class="n">Production minimum de la 1re cuisson</th><th>Cuissons du jour</th></tr></thead><tbody>
      ${[1, 2, 3, 4, 5, 6, 7].map(j => { const x = E.jours[j]; const n = Math.min(nC, x.cuissons); return `<tr><td class="nom">${esc(d.jours[j])}</td>
        <td class="n"><select data-jour-n="${j}">${C.map((c, i) => `<option value="${i + 1}"${i + 1 === n ? ' selected' : ''}>${i + 1}</option>`).join('')}</select></td>
        <td class="n"><input class="pf-in court" type="number" min="0" max="100" step="5" data-jour-min="${j}" data-f="jm${j}" value="${esc(x.minPct)}"> %</td>
        <td class="mu">${C.slice(0, n).map(c => esc(c.nom) + ' ' + esc(c.de)).join(' · ')}</td></tr>`; }).join('')}</tbody></table></div>`;
    // Les cuissons et les règles.
    h += `<div class="pf-card"><div class="pf-ct"><span class="pf-k">2 · Les cuissons</span><span class="pf-mini">la période de vente que chaque cuisson alimente et sa part de la journée — total ${fP(totPct)}${Math.abs(totPct - 100) > 0.5 ? ' <b class="ko">(doit faire 100 %)</b>' : ''}</span></div>
      <table class="pf-tab"><thead><tr><th>#</th><th>Nom</th><th>Vente de</th><th>à</th><th class="n">Part de la journée</th><th>Au four</th><th></th></tr></thead><tbody>
      ${C.map((c, i) => { const de = hDe(c.de); return `<tr><td class="mu">${i + 1}</td><td><input class="pf-in" data-cu="${i}" data-k="nom" data-f="cn${i}" value="${esc(c.nom)}"></td>
        <td><input class="pf-in court" data-cu="${i}" data-k="de" data-f="cd${i}" value="${esc(c.de)}" inputmode="numeric" placeholder="06:00"></td><td><input class="pf-in court" data-cu="${i}" data-k="a" data-f="ca${i}" value="${esc(c.a)}" inputmode="numeric" placeholder="11:00"></td>
        <td class="n"><input class="pf-in court" type="number" min="0" max="100" step="5" data-cu="${i}" data-k="pct" data-f="cp${i}" value="${esc(c.pct)}"> %</td>
        <td class="mu">${de != null ? hh(Math.max(0, de - av / 60)) : '—'}</td><td>${nC > 1 ? `<button class="pf-x" data-cusup="${i}" title="retirer">×</button>` : ''}</td></tr>`; }).join('')}</tbody></table>
      <div class="pf-pied">${nC < 6 ? '<button class="pf-btn" data-cuajout="1">+ ajouter une cuisson</button>' : ''}
        <label>Base : <input class="pf-in court" type="number" min="1" max="12" data-regle="semaines" data-f="rs" value="${esc(E.regles.semaines)}"> semaines</label>
        <label>Sécurité : <input class="pf-in court" type="number" min="0" max="50" data-regle="securite" data-f="rc" value="${esc(E.regles.securite)}"> %</label>
        <label>Au four : <input class="pf-in court" type="number" min="0" max="180" step="5" data-regle="avance" data-f="ra" value="${esc(E.regles.avance)}"> min avant la vente</label>
        <label>Plaques minimum : <input class="pf-in court" type="number" min="0" max="10" data-regle="minPlaques" data-f="rp" value="${esc(E.regles.minPlaques)}"></label></div></div>`;
    // Les catégories.
    const cats = Object.entries(E.categories).sort((a, b) => [(a[1].groupe || 'zzz'), a[1].nom].join('|').localeCompare([(b[1].groupe || 'zzz'), b[1].nom].join('|')));
    let g = null;
    h += `<div class="pf-card"><div class="pf-ct"><span class="pf-k">3 · Les catégories</span><span class="pf-mini">la part de chaque cuisson, catégorie par catégorie (vide = pas cuite à cette cuisson ; en gris, les parts de la journée ; une catégorie à ses propres parts ne suit pas le minimum de la 1re cuisson) · le step de production (pièces par fournée : la production et la proposition J−7 s’arrondissent à ce step) · le stock minimum en vitrine qui déclenche une recuisson · préparée la veille (1re cuisson du lendemain) · se garde au lendemain (clôture)</span></div>
      <table class="pf-tab"><thead><tr><th>Catégorie</th>${C.map((c, i) => `<th class="c">${i + 1}<small>${esc(c.nom)} %</small></th>`).join('')}<th class="n">Total</th><th class="n">Step de production</th><th class="n">Stock minimum de recuisson</th><th class="c">Préparée la veille</th><th class="c">Se garde</th></tr></thead><tbody>
      ${cats.map(([k, c]) => { const gr = c.groupe || 'Sans section'; const t = gr !== g ? `<tr class="grp"><td colspan="${C.length + 6}">${esc(gr)}</td></tr>` : ''; g = gr;
        const pd = c.parts || partsDefaut(E, c); const tot = Object.values(pd).reduce((a, v) => a + (+v || 0), 0);
        const st = c.plaque == null || c.plaque === '' ? 1 : +c.plaque; const opts = [...new Set((d.steps || [1, 8, 20]).concat([st]))].sort((a, b) => a - b);
        return t + `<tr><td class="nom">${esc(c.nom)}${c.auto ? ' <span class="pf-tag">proposé</span>' : ''}</td>${C.map(cu => { const v = c.parts ? (c.parts[cu.id] || '') : ''; const ph = !c.parts && pd[cu.id] ? pd[cu.id] : ''; return `<td class="c"><input class="pf-in pct${c.parts ? '' : ' def'}" type="number" min="0" max="100" step="5" data-catpart="${esc(k)}" data-cu-id="${esc(cu.id)}" data-f="cp${esc(k)}-${esc(cu.id)}" value="${esc(v)}" placeholder="${esc(ph)}"></td>`; }).join('')}
          <td class="n" data-cattot="${esc(k)}">${totPart(tot, !!c.parts)}${c.parts ? ` <button class="pf-mini-btn" data-catreset="${esc(k)}" title="revenir aux parts de la journée">jour</button>` : ''}</td>
          <td class="n"><select data-catpl="${esc(k)}">${opts.map(v => `<option value="${v}"${v === st ? ' selected' : ''}>${v === 1 ? '1 · à l’unité' : 'par ' + v}</option>`).join('')}</select></td>
          <td class="n"><input class="pf-in court" type="number" min="0" max="500" data-catsm="${esc(k)}" data-f="sm${esc(k)}" value="${c.stockMin ? esc(c.stockMin) : ''}" placeholder="0"></td>
          <td class="c"><input type="checkbox" data-catv="${esc(k)}"${c.veille ? ' checked' : ''}></td><td class="c"><input type="checkbox" data-catg="${esc(k)}"${c.garde ? ' checked' : ''}></td></tr>`; }).join('')}</tbody></table>
      <div class="pf-pied j7"><label><input type="checkbox" data-ajust="1"${E.ajusterJ7 ? ' checked' : ''}> Ajuster la proposition sur J−7</label>
        <label>Quand il y en a eu trop peu : <select data-modepeu="1"${E.ajusterJ7 ? '' : ' disabled'}><option value="production"${E.modePeu === 'production' ? ' selected' : ''}>augmenter la production</option><option value="stock"${E.modePeu === 'stock' ? ' selected' : ''}>augmenter le stock minimum de recuisson</option></select></label>
        <span class="pf-mini">J−7, même jour la semaine passée : la dernière vente avant la fermeture dit « trop peu », la poubelle dit « trop ». Le besoin de J−7 = vendu + manqué. Trop peu : ce qui manque au plan pour l’atteindre, arrondi au step supérieur, s’ajoute à la cuisson de l’heure où il manquait, ou relève le stock minimum de recuisson. Trop : ce qui dépasse ce besoin, au plus la poubelle, arrondi au step inférieur, se retire en partant de la dernière cuisson.</span></div></div>`;
    // Les produits obligatoires.
    const f = S.filtre.trim().toLowerCase();
    const P = (d.produits || []).filter(p => (!f || (p.nom + ' ' + p.cat + ' ' + p.groupe).toLowerCase().includes(f)) && (!S.seulsOblig || (E.oblig[p.pid] && E.oblig[p.pid].jours.length)));
    const nOb = Object.values(E.oblig).filter(o => o.jours.length).length;
    g = null; let gc = null;
    h += `<div class="pf-card"><div class="pf-ct"><span class="pf-k">4 · Les produits obligatoires</span><span class="pf-mini">${nOb} obligatoire${nOb > 1 ? 's' : ''} · les jours où le produit doit être en vitrine, et le minimum à produire même sans vente · « réseau » : l’assortiment obligatoire du cockpit</span></div>
      <div class="pf-pied" style="border-top:none;padding-top:0"><input class="pf-in" style="width:260px" placeholder="chercher un produit, une catégorie…" data-filtre="1" data-f="filtre" value="${esc(S.filtre)}"><label><input type="checkbox" data-seuls="1"${S.seulsOblig ? ' checked' : ''}> seulement les obligatoires</label><span class="pf-mini">${P.length} produit${P.length > 1 ? 's' : ''}</span></div>
      <table class="pf-tab"><thead><tr><th>Produit</th><th class="n">Vendu / jour</th>${[1, 2, 3, 4, 5, 6, 7].map(j => `<th class="c" title="${esc(d.jours[j])}">${JOURS_C[j]}</th>`).join('')}<th class="c">Tous</th><th class="n">Minimum</th></tr></thead><tbody>
      ${P.slice(0, 400).map(p => { const o = E.oblig[p.pid] || { jours: [], min: 2, reseau: false }; const gr = p.groupe || 'Sans section';
        let t = ''; if (gr !== g) { t += `<tr class="grp"><td colspan="11">${esc(gr)}</td></tr>`; g = gr; gc = null; } if (p.cat !== gc) { t += `<tr class="scat"><td colspan="11">${esc(p.cat)}</td></tr>`; gc = p.cat; }
        return t + `<tr class="${o.jours.length ? 'ob' : ''}"><td class="nom">${esc(p.nom)}${o.reseau ? ' <span class="pf-tag or">réseau</span>' : ''}</td><td class="n mu">${p.parJour ? fQ(p.parJour) : '—'}</td>
          ${[1, 2, 3, 4, 5, 6, 7].map(j => `<td class="c"><input type="checkbox" data-obj="${p.pid}" data-jj="${j}"${o.jours.includes(j) ? ' checked' : ''}></td>`).join('')}
          <td class="c"><button class="pf-mini-btn" data-obtous="${p.pid}">${o.jours.length === 7 ? 'aucun' : '7/7'}</button></td>
          <td class="n"><input class="pf-in court" type="number" min="0" max="500" data-obmin="${p.pid}" data-f="om${p.pid}" value="${esc(o.min)}"${o.jours.length ? '' : ' disabled'}></td></tr>`; }).join('')}
      ${P.length > 400 ? `<tr><td colspan="11" class="mu">+ ${P.length - 400} produits : affinez la recherche.</td></tr>` : ''}</tbody></table></div>`;
    h += `<div class="pf-barre"><label>Signé <input class="pf-in" data-par="1" data-f="par" value="${esc(S.par)}" placeholder="prénom"></label><span class="sp"></span><button class="pf-btn" data-annuler="1">Revenir aux réglages enregistrés</button><button class="pf-btn prim" data-enreg="1"${S.envoi ? ' disabled' : ''}>${S.envoi ? 'Enregistrement…' : 'Enregistrer les réglages'}</button></div>`;
    return h;
  }
  function enregistrerParams() {
    const E = S.edit, d = S.data[cle()];
    const cats = {}; Object.entries(E.categories).forEach(([k, c]) => { const pa = c.parts ? Object.fromEntries(Object.entries(c.parts).filter(([id, v]) => E.cuissons.some(x => x.id === id) && +v > 0).map(([id, v]) => [id, +v])) : null;
      cats[k] = { cuissons: pa && Object.keys(pa).length ? E.cuissons.map(x => x.id).filter(id => pa[id]) : c.cuissons.filter(id => E.cuissons.some(x => x.id === id)), parts: pa && Object.keys(pa).length ? pa : null, plaque: c.plaque === '' || c.plaque == null || +c.plaque <= 1 ? null : +c.plaque, limite: c.limite, nom: c.nom, catId: c.catId }; });
    const sm = {}; Object.entries(E.categories).forEach(([k, c]) => { if (+c.stockMin > 0) { sm[k] = Math.round(+c.stockMin); } });
    const ob = {}; Object.entries(E.oblig).forEach(([pid, o]) => { if (o.jours.length || o.reseau) { ob[pid] = { jours: o.jours, min: +o.min || 0 }; } });
    const n = E.cuissons.length;
    const jours = {}; Object.keys(E.jours).forEach(j => { jours[j] = { cuissons: Math.min(n, +E.jours[j].cuissons || n), minPct: +E.jours[j].minPct }; });
    const corps = { shop: +S.shop, par: S.par, gp: { cuissons: E.cuissons.map(c => ({ id: c.id, nom: c.nom, de: c.de, a: c.a, pct: +c.pct, daypart: c.daypart || null })), categories: cats, regles: E.regles },
      flux: { jours, obligatoires: ob, veille: Object.keys(E.categories).filter(k => E.categories[k].veille), garde: Object.keys(E.categories).filter(k => E.categories[k].garde), stockMin: sm, ajusterJ7: !!E.ajusterJ7, modePeu: E.modePeu } };
    S.envoi = true; S.msg = null; rendre();
    ecrire('/production/flux/params', corps).then(r => { S.msg = { ok: true, t: `Réglages enregistrés : ${r.cuissons} cuisson${r.cuissons > 1 ? 's' : ''}, ${r.obligatoires} produit${r.obligatoires > 1 ? 's' : ''} réglé${r.obligatoires > 1 ? 's' : ''} en obligatoire. Le plan les reprend.` }; Object.keys(S.data).forEach(k => { if (!k.startsWith('params|')) { delete S.data[k]; } }); charger(true); })
      .catch(e => { S.msg = { ok: false, t: 'Pas enregistré : ' + e.message }; })
      .finally(() => { S.envoi = false; rendre(); void d; });
  }

  /* --- 2. Plan ------------------------------------------------------------------ */
  function pagePlan(d) {
    const C = d.cuissons || [], L = d.lignes || [];
    const tot = { c: {}, total: 0, veille: 0, ca: 0, report: 0 };
    L.forEach(l => { C.forEach(c => { const x = l.c[c.id]; tot.c[c.id] = (tot.c[c.id] || 0) + (x ? x.sortie : 0); }); tot.total += l.total; tot.veille += l.veille ? l.veille.sortie : 0; tot.ca += l.ca || 0; tot.report += l.report || 0; });
    const caC = id => L.reduce((a, l) => a + ((l.c[id] && l.prix != null) ? l.c[id].sortie * l.prix : 0), 0);
    const nomC = (c, i) => i === 0 ? '1re période' : (i + 1) + 'e cuisson';
    // J−7 : trop ou trop peu, et ce que la proposition en a fait.
    const V7 = { peu: 0, trop: 0, mixte: 0, plus: 0, moins: 0, smin: 0 };
    L.forEach(l => { const v = l.j7.verdict; if (v === 'peu') { V7.peu++; } else if (v === 'trop') { V7.trop++; } else if (v === 'mixte') { V7.mixte++; }
      if (l.ajustJ7 > 0) { V7.plus += l.ajustJ7; } else if (l.ajustJ7 < 0) { V7.moins -= l.ajustJ7; }
      if (l.j7.plus > 0 && d.j7.modePeu === 'stock') { V7.smin++; } });
    let h = `<div class="pf-intro"><b>${esc(fDL(d.date))} — ${d.nCuissons} cuisson${d.nCuissons > 1 ? 's' : ''}</b>, la 1re à ${fP(d.minPct)} de la journée au minimum. Prévision : moyenne des ${d.base.lus} derniers ${esc(d.jourNom)}s lus heure par heure, + sécurité.${d.base.manquants.length ? ` <b class="wa">${d.base.manquants.length} jour(s) encore en lecture : le plan se complète tout seul.</b>` : ''}</div>`;
    h += `<div class="pf-tuiles">${C.map((c, i) => `<div><div class="k">${nomC(c, i)} · ${esc(c.nom)}</div><div class="v">${fN(tot.c[c.id] || 0)} <small>pièces</small></div><div class="s">au four ${esc(c.four)} · vente ${esc(c.de)}–${esc(c.a)} · ${fE(caC(c.id))}</div></div>`).join('')}
      <div class="veille"><div class="k">Préparer pour demain matin</div><div class="v">${fN(tot.veille)} <small>pièces</small></div><div class="s">1re cuisson de ${esc(d.lendemain.jourNom)} · ${d.lendemain.categories} catégorie${d.lendemain.categories > 1 ? 's' : ''} préparée${d.lendemain.categories > 1 ? 's' : ''} la veille${d.lendemain.complet ? '' : ' · <b class="wa">lecture en cours</b>'}</div></div>
      <div><div class="k">Report de la veille</div><div class="v">${fN(d.reportVeille.pieces)} <small>pièces</small></div><div class="s">${d.reportVeille.cloture ? d.reportVeille.produits + ' produit' + (d.reportVeille.produits > 1 ? 's' : '') + ' gardé' + (d.reportVeille.produits > 1 ? 's' : '') + ' à la clôture d’hier, déduit' + (d.reportVeille.produits > 1 ? 's' : '') + ' de la 1re cuisson' : 'pas de clôture hier : rien de déduit'}</div></div>
      <div class="j7t"><div class="k">J−7 · trop ou trop peu</div><div class="v">${d.j7.lu ? `${fN(V7.peu)} <small>trop peu</small> · ${fN(V7.trop)} <small>trop</small>` : '<small>tickets pas encore lus</small>'}</div><div class="s">${d.j7.lu ? `${V7.mixte ? V7.mixte + ' les deux · ' : ''}poubelle ${d.j7.poubelleLue ? fN(d.j7.poubelle) + ' pièces' : 'pas lue'} · ${d.j7.ajuster ? (d.j7.modePeu === 'stock' ? `stock minimum relevé sur ${fN(V7.smin)} produit${V7.smin > 1 ? 's' : ''} · ` : '') + ([V7.plus ? '+' + fN(V7.plus) : '', V7.moins ? '−' + fN(V7.moins) : ''].filter(Boolean).join(' et ') ? [V7.plus ? '+' + fN(V7.plus) : '', V7.moins ? '−' + fN(V7.moins) : ''].filter(Boolean).join(' et ') + ' pièces au plan' : 'plan inchangé') : 'proposition non ajustée (réglage)'}` : ''}</div></div></div>`;
    h += `<div class="pf-note">J−7 (${esc(fDL(d.j7.date))}) : ${d.j7.lu ? 'tickets lus' : '<b class="wa">tickets pas encore lus</b>'} · commandes POS ${d.j7.commandes.n} (${fE(d.j7.commandes.ca)}) · webshop ${d.j7.webshop.n} (${fE(d.j7.webshop.ca)})${d.j7.derniereVente != null ? ` · dernière vente du magasin à ${d.j7.derniereVente} h` : ''}. « Dernière vente » : l’heure du dernier ticket du produit ; avant la fermeture, il en a manqué (trop peu). « Poubelle » : ce qui a été jeté ce jour-là (trop). La proposition vise le besoin de J−7, vendu + manqué : elle relève le plan qui reste en dessous, et retire la poubelle de ce qui le dépasse, en steps de la catégorie. Les commandes ne sont pas des ventes comptoir : la prévision part du comptoir seul (les tickets moins ceux des commandes, souvent payées avant le retrait), puis s’ajoutent les commandes POS et webshop retirées ce jour-là, avec les articles de leur ticket.</div>`;
    const Cm = d.commandes || {};
    if (Cm.lues) { h += `<div class="pf-note">Commandes du ${esc(fDL(d.date))} : ${fN(Cm.pos)} POS et ${fN(Cm.webshop)} webshop, ${fE(Cm.ca)} · ${fN(Cm.pieces)} pièces ajoutées au plan${Cm.sansDetail ? ` · <b class="wa">${fN(Cm.sansDetail)} à payer au retrait : leurs articles ne sont pas encore connus</b>` : ''}${Cm.complet ? '' : ' · <b class="wa">lecture des tickets de commande en cours</b>'}.</div>`; }
    // Le tableau : section › catégorie › produit, sous-totaux en pièces et en CA.
    const cols = C.length;
    const somme = (rows, f) => rows.reduce((a, l) => a + Math.round(f(l) || 0), 0);
    const sg = n => (n > 0 ? '+' : '−') + fN(Math.abs(n));
    // Une case = un nombre entier ; les plaques et l'ajustement J−7 de la cuisson restent au survol.
    const cellQ = x => { const tt = [];
      if (x && x.sortie && x.plaque) { tt.push(`${pl(x.plaques, 'plaque')} de ${fN(x.plaque)}`); }
      if (x && x.ajustJ7) { tt.push(`dont ${sg(x.ajustJ7)} ajusté sur J−7`); }
      const t = tt.length ? ` title="${esc(tt.join(' · '))}"` : '';
      return !x || !x.sortie ? `<span class="mu"${t}>—</span>` : `<b${t}>${fN(x.sortie)}</b>`; };
    const VJ = { peu: ['att', 'Trop peu'], trop: ['bleu', 'Trop'], mixte: ['att', 'Les deux'], juste: ['ok', 'Juste'], aucune: ['', 'Pas vendu'] };
    const fin7 = d.j7.derniereVente;
    const cDer = l => { const j = l.j7; if (j.derniere == null) { return `<span class="mu">${j.verdict === 'aucune' ? 'aucune' : '—'}</span>`; }
      const tot = j.verdict === 'peu' || j.verdict === 'mixte';
      return `<span class="${tot ? 'wa' : ''}" title="dernier ticket entre ${j.derniere} h et ${j.derniere + 1} h${fin7 != null ? ' · le magasin a vendu jusqu’à ' + (fin7 + 1) + ' h' : ''}${j.manque ? ' · vente perdue estimée ' + fN(j.manque) + ' pièces' : ''}">${tot ? '<b>' + j.derniere + ' h</b>' : j.derniere + ' h'}</span>`; };
    const cPoub = l => l.j7.poubelle == null ? '<span class="mu" title="poubelle pas lue">—</span>' : (Math.round(l.j7.poubelle) > 0 ? `<b class="ko">${fN(l.j7.poubelle)}</b>` : '<span class="mu">0</span>');
    const cVerd = l => { const v = VJ[l.j7.verdict]; return v ? `<span class="pf-tag ${v[0]}">${v[1]}</span>` : '<span class="mu">—</span>'; };
    const stepsTxt = (n, st) => st > 1 ? (n % st === 0 ? `${fN(Math.abs(n) / st)} step${Math.abs(n) / st > 1 ? 's' : ''} de ${fN(st)}` : `step de ${fN(st)}`) : 'à l’unité';
    // La proposition : un nombre entier dans la case, le détail au survol.
    const cProp = l => { const j = l.j7, st = l.step || 1, stock = d.j7.modePeu === 'stock';
      if (!j.verdict || j.verdict === 'juste' || j.verdict === 'aucune') { return '<span class="mu">—</span>'; }
      const net = d.j7.ajuster ? l.ajustJ7 : (stock ? 0 : j.plus) - j.moins;
      const smin = stock && j.plus > 0 ? (d.j7.ajuster ? l.stockMin : j.plus) : 0;
      const tt = [];
      if (j.besoin != null) { tt.push(Math.round(j.manque) ? `besoin à J−7 : vendu ${fN(j.vendu)} + manqué ${fN(j.manque)} = ${fN(j.besoin)}` : `besoin à J−7 : vendu ${fN(j.vendu)}`); }
      if (j.plan != null) { tt.push('plan avant ajustement ' + fN(j.plan)); }
      if (j.poubelle) { tt.push('poubelle ' + fN(j.poubelle)); }
      if (net) { tt.push(sg(net) + ' : ' + stepsTxt(net, st)); }
      if (smin) { tt.push('stock minimum de recuisson relevé à ' + fN(smin)); }
      if (!d.j7.ajuster) { tt.push('pas appliquée au plan'); }
      let v = net ? `<b class="${d.j7.ajuster ? (net > 0 ? 'ok' : 'bl') : 'mu'}">${sg(net)}</b>` : '';
      if (smin) { v += (v ? ' ' : '') + `<b class="or">${fN(smin)}</b>`; }
      return `<span title="${esc(tt.join(' · '))}">${v || '<span class="mu">0</span>'}</span>`; };
    // Les commandes du jour (POS + webshop) : un nombre, le détail au survol.
    const cmdJ = l => l.commandesJour ? (l.commandesJour.pos || 0) + (l.commandesJour.webshop || 0) : 0;
    const cCmd = l => { const n = Math.round(cmdJ(l)); return n ? `<b title="POS ${fN(l.commandesJour.pos)} · webshop ${fN(l.commandesJour.webshop)}">${fN(n)}</b>` : '<span class="mu">—</span>'; };
    // Trop ou trop peu d'une catégorie : une seule somme, ce qui a manqué moins ce qui a été jeté.
    const cNet = rows => { const m = somme(rows, l => l.j7.manque), p = somme(rows, l => l.j7.poubelle), n = m - p;
      return `<td class="n j7v" title="manqué ${fN(m)} · jeté ${fN(p)}">${n ? `<span class="${n > 0 ? 'wa' : 'bl'}">${sg(n)}</span>` : '<span class="mu">0</span>'}</td>`; };
    const sousTot = (cls, lib, rows) => { const aj = somme(rows, l => d.j7.ajuster ? l.ajustJ7 : (d.j7.modePeu === 'stock' ? 0 : l.j7.plus) - l.j7.moins);
      return `<tr class="${cls}"><td>${lib}</td><td class="n">${fN(somme(rows, l => l.j7.magasin))}</td><td class="n">${fN(somme(rows, l => l.j7.webshop))}</td><td class="n">${fN(somme(rows, l => l.j7.commandes))}</td><td class="n mu"></td><td class="n">${fN(somme(rows, l => l.j7.poubelle))}</td>${cNet(rows)}<td class="n">${fN(somme(rows, l => l.prevJ))}</td><td class="n">${fN(somme(rows, cmdJ))}</td><td class="n">${fN(somme(rows, l => l.report))}</td><td class="n">${aj ? sg(aj) : '<span class="mu">0</span>'}</td>
      ${C.map(c => `<td class="n">${fN(somme(rows, l => l.c[c.id] ? l.c[c.id].sortie : 0))}</td>`).join('')}<td class="n">${fN(somme(rows, l => l.total))}</td><td class="n">${fN(somme(rows, l => l.veille ? l.veille.sortie : 0))}</td><td class="n">${fE(somme(rows, l => l.ca))}</td></tr>`; };
    let corps = '';
    const LF = S.ecartsJ7 ? L.filter(l => ['peu', 'trop', 'mixte'].includes(l.j7.verdict)) : L;
    const groupes = []; LF.forEach(l => { let g = groupes.find(x => x.nom === l.groupe); if (!g) { g = { nom: l.groupe, cats: [] }; groupes.push(g); } let c = g.cats.find(x => x.cle === l.catCle); if (!c) { c = { cle: l.catCle, nom: l.cat, lignes: [] }; g.cats.push(c); } c.lignes.push(l); });
    groupes.forEach(g => {
      const rowsG = g.cats.flatMap(c => c.lignes);
      corps += sousTot('sec', esc(g.nom), rowsG);
      g.cats.forEach(c => {
        if (g.cats.length > 1 || c.nom !== g.nom) { corps += sousTot('scat', esc(c.nom), c.lignes); }
        corps += c.lignes.map(l => `<tr><td class="nom">${l.oblig ? '<span class="pf-ob" title="obligatoire ce jour">★</span> ' : ''}${esc(l.nom)}</td>
          <td class="n">${fN(l.j7.magasin)}</td><td class="n">${l.j7.webshop == null ? '<span class="mu">—</span>' : (Math.round(l.j7.webshop) ? fN(l.j7.webshop) : '<span class="mu">0</span>')}</td><td class="n">${Math.round(l.j7.commandes || 0) ? fN(l.j7.commandes) : '<span class="mu">0</span>'}</td>
          <td class="n q">${cDer(l)}</td><td class="n">${cPoub(l)}</td><td class="c">${cVerd(l)}</td>
          <td class="n mu">${fN(l.prevJ)}</td><td class="n">${cCmd(l)}</td><td class="n">${Math.round(l.report || 0) ? fN(l.report) : '<span class="mu">—</span>'}</td><td class="n q prop">${cProp(l)}</td>
          ${C.map(c => `<td class="n q">${cellQ(l.c[c.id])}</td>`).join('')}<td class="n"><b>${fN(l.total)}</b></td>
          <td class="n q veille">${l.veille ? cellQ(l.veille) : '<span class="mu">—</span>'}</td><td class="n mu">${l.ca == null ? '—' : fE(l.ca)}</td></tr>`).join('');
      });
    });
    h += `<div class="pf-card"><div class="pf-ct"><span class="pf-k">Le tableau de production</span><span class="pf-mini">${L.length} produits · ${d.obligatoires} obligatoire${d.obligatoires > 1 ? 's' : ''} ce jour (★) · quantités arrondies au step de la catégorie${d.j7.ajuster ? ' · ajustées sur J−7' : ''}</span><label class="pf-mini"><input type="checkbox" data-ecarts="1"${S.ecartsJ7 ? ' checked' : ''}> seulement trop ou trop peu à J−7</label><button class="pf-btn" data-imprimer="1">Imprimer</button></div>
      <div class="pf-defile"><table class="pf-tab plan"><thead><tr><th rowspan="2">Section › catégorie › produit</th><th colspan="6" class="c bd">J−7 · ${esc(fDL(d.j7.date))}</th><th rowspan="2" class="n">Prévision comptoir</th><th rowspan="2" class="n">Commandes du jour</th><th rowspan="2" class="n">Report de la veille</th><th rowspan="2" class="n prop">Proposition J−7${d.j7.ajuster ? '' : '<small>pas appliquée</small>'}</th><th colspan="${cols}" class="c bd">À produire aujourd’hui</th><th rowspan="2" class="n">Total</th><th rowspan="2" class="n veille">À préparer pour demain matin</th><th rowspan="2" class="n">CA</th></tr>
        <tr><th class="n">Vendu au comptoir</th><th class="n">Webshop</th><th class="n">Commandes POS</th><th class="n">Dernière vente</th><th class="n">Poubelle</th><th class="c">Trop ou trop peu</th>${C.map((c, i) => `<th class="n">${nomC(c, i)}<small>${esc(c.nom)} · four ${esc(c.four)}</small></th>`).join('')}</tr></thead>
        <tbody>${corps || `<tr><td colspan="${13 + cols}" class="mu">${S.ecartsJ7 && L.length ? 'Aucun produit en trop ou en trop peu à J−7.' : 'Rien à produire : aucune catégorie n’est cochée pour ce jour, ou les ventes de référence ne sont pas encore lues.'}</td></tr>`}</tbody>
        <tfoot>${L.length ? sousTot('tot', 'Total de la journée', L) : ''}</tfoot></table></div>
      <div class="pf-pied mu">${esc(d.source)}</div></div>`;
    return h;
  }

  /* --- 3. Validation et suivi ------------------------------------------------- */
  function pageSuivi(d) {
    const C = d.cuissons || [];
    const passe = d.date <= AUJ;
    let h = `<div class="pf-intro"><b>${esc(fDL(d.date))}${d.maintenant ? ' — ' + esc(d.maintenant) : ''}.</b> Validez chaque cuisson à la sortie du four : le suivi et la clôture partent de ce qui est réellement sorti. Le stock de chaque produit se suit heure par heure, vendu d’après les tickets et projeté d’après les ${d.base.lus} derniers ${esc(d.jourNom)}s.</div>`;
    h += `<div class="pf-cuis">${C.map(c => { const ouvert = S.valid && S.valid.cuisson === c.id; return `<div class="${c.valide ? 'ok' : 'att'}${ouvert ? ' on' : ''}"><div class="k">${esc(c.nom)} · four ${esc(c.four)} · vente ${esc(c.de)}–${esc(c.a)}</div>
      <div class="v">${c.valide ? fN(c.fait) : fN(c.pieces)} <small>${c.valide ? 'pièces sorties' : 'pièces prévues'}</small></div>
      <div class="s">${c.valide ? `validée${c.le ? ' le ' + esc(fD(c.le.slice(0, 10))) + ' à ' + esc(c.le.slice(11, 16)) : ''}${c.par ? ' par ' + esc(c.par) : ''} · plan ${fN(c.pieces)}` : 'pas encore validée'}</div>
      ${passe ? `<button class="pf-btn${c.valide ? '' : ' prim'}" data-valid="${esc(c.id)}">${ouvert ? 'Fermer' : (c.valide ? 'Corriger' : 'Valider la cuisson')}</button>` : ''}</div>`; }).join('')}</div>`;
    if (S.valid) {
      const c = C.find(x => x.id === S.valid.cuisson);
      if (c) {
        const V = S.valid.lignes;
        let g = null;
        h += `<div class="pf-card"><div class="pf-ct"><span class="pf-k">Valider ${esc(c.nom)} — ce qui est sorti du four</span><span class="pf-mini">prérempli avec le plan, ou la dernière validation · corrigez les écarts</span><button class="pf-btn" data-commeprevu="1">Tout comme prévu</button></div>
          <table class="pf-tab"><thead><tr><th>Produit</th><th class="n">Prévu</th><th class="n">Plaques</th><th class="n">Sorti</th><th class="n">Écart</th></tr></thead><tbody>
          ${c.lignes.map(l => { const t = l.groupe !== g ? `<tr class="grp"><td colspan="5">${esc(l.groupe)}</td></tr>` : ''; g = l.groupe; const v = V[l.pid]; const e = (+v || 0) - l.sortie;
            return t + `<tr><td class="nom">${l.oblig ? '<span class="pf-ob">★</span> ' : ''}${esc(l.nom)}</td><td class="n">${fN(l.sortie)}</td><td class="n mu">${l.plaque ? fN(l.plaques) + ' × ' + fN(l.plaque) : 'unité'}</td>
              <td class="n"><input class="pf-in court" type="number" min="0" max="5000" data-vq="${l.pid}" data-f="vq${l.pid}" value="${esc(v)}"></td><td class="n ${e > 0 ? 'ok' : (e < 0 ? 'ko' : 'mu')}" data-ve="${l.pid}">${e ? (e > 0 ? '+' : '−') + fN(Math.abs(e)) : '='}</td></tr>`; }).join('')}</tbody></table>
          <div class="pf-barre in"><label>Signé <input class="pf-in" data-par="1" data-f="par" value="${esc(S.par)}" placeholder="prénom"></label><span class="sp"></span><button class="pf-btn prim" data-validok="1"${S.envoi ? ' disabled' : ''}>${S.envoi ? 'Enregistrement…' : 'Enregistrer la cuisson'}</button></div></div>`;
      }
    }
    // La surveillance heure par heure.
    const T = d.totaux, H = d.heures || [], now = d.maintenant ? hDe(d.maintenant) : 99;
    const P = (d.produits || []).filter(p => !S.alertes || p.verdict !== 'ok');
    const VERD = { rupture: ['ko', 'Rupture'], manque: ['att', 'Manque prévu'], trop: ['bleu', 'Trop produit'], ok: ['ok', 'Tient'] };
    h += `<div class="pf-card"><div class="pf-ct"><span class="pf-k">Surveillance heure par heure</span>
      <span class="pf-chips"><span class="pf-tag ko">${T.ruptures} en rupture</span><span class="pf-tag att">${T.manques} manque${T.manques > 1 ? 's' : ''} prévu${T.manques > 1 ? 's' : ''}</span><span class="pf-tag bleu">${T.trop} trop produit${T.trop > 1 ? 's' : ''}</span></span>
      <span class="pf-mini">sorti ${fN(T.sorti)} · vendu ${fN(T.vendu)} · en vitrine ${fN(T.stock)} · fin de journée projetée ${fN(T.finJour)}${d.ventesLues ? '' : ' · <b class="wa">tickets du jour pas encore lus</b>'}</span>
      <label class="pf-mini"><input type="checkbox" data-alertes="1"${S.alertes ? ' checked' : ''}> seulement les alertes</label></div>
      <div class="pf-defile"><table class="pf-tab suivi"><thead><tr><th>Produit</th><th class="n">Report</th><th class="n">Sorti</th><th class="n">Vendu</th><th class="n">En vitrine</th>${H.map(x => `<th class="c h${x <= now && now < x + 1 ? ' now' : ''}">${x} h</th>`).join('')}<th>Verdict</th><th>Conseil</th></tr></thead><tbody>
      ${P.map(p => `<tr><td class="nom">${p.oblig ? '<span class="pf-ob">★</span> ' : ''}${esc(p.nom)}<small>${esc(p.cat)}</small></td><td class="n mu">${p.report ? fQ(p.report) : '—'}</td><td class="n">${fQ(p.sorti)}</td><td class="n">${fQ(p.vendu)}</td><td class="n q"><b>${fQ(p.stock)}</b>${p.stockMin ? `<small title="stock minimum de recuisson : sous ce seuil, la recuisson est conseillée">min. ${fN(p.stockMin)}</small>` : ''}</td>
        ${H.map(x => { const c = (p.cases || []).find(y => y.h === x); if (!c) { return '<td class="c h"></td>'; } const cls = c.reel ? (c.q < -0.5 ? 'r neg' : 'r') : (c.q < -0.5 ? 'ko' : (c.q < Math.max(1, (c.prev || 0) * 0.25) ? 'att' : 'ok')); return `<td class="c h ${cls}${x <= now && now < x + 1 ? ' now' : ''}" title="${x} h – ${x + 1} h · ${c.reel ? 'stock réel en fin d’heure · vendu ' + fQ(c.v) : 'projeté · prévision ' + fQ(c.prev)}">${fQ(c.q)}</td>`; }).join('')}
        <td><span class="pf-tag ${VERD[p.verdict][0]}">${VERD[p.verdict][1]}</span>${p.manque ? `<small>à ${p.manque.h} h · −${fQ(p.manque.q)}</small>` : ''}</td>
        <td>${p.conseil ? `recuire <b>${p.conseil.plaques != null ? pl(p.conseil.plaques, 'plaque') + ' (' + fN(p.conseil.plaques * p.conseil.plaque) + ')' : fN(p.conseil.pieces)}</b>` : (p.verdict === 'trop' ? `<span class="mu">${fQ(p.finJour)} en fin de journée</span>` : '')}</td></tr>`).join('') || `<tr><td colspan="${7 + H.length}" class="mu">${S.alertes ? 'Aucune alerte.' : 'Rien à suivre : aucune cuisson planifiée ce jour.'}</td></tr>`}</tbody></table></div>
      <div class="pf-leg"><span><i class="r"></i>stock réel en fin d’heure (rouge : plus vendu que sorti — une cuisson non validée ou un report non compté)</span><span><i class="ok"></i>projeté, tient</span><span><i class="att"></i>projeté, sous le quart de la vente de l’heure</span><span><i class="ko"></i>projeté, manque (sous le stock minimum de recuisson quand il est réglé)</span><span><i class="now"></i>l’heure en cours</span></div>
      <div class="pf-pied mu">${esc(d.source)}</div></div>`;
    return h;
  }
  function ouvrirValid(id) {
    const d = S.data[cle()]; const c = d && d.cuissons.find(x => x.id === id);
    if (!c) { return; }
    if (S.valid && S.valid.cuisson === id) { S.valid = null; rendre(); return; }
    const V = {}; c.lignes.forEach(l => { V[l.pid] = l.fait != null ? l.fait : l.sortie; });
    S.valid = { cuisson: id, lignes: V }; S.msg = null; rendre();
  }
  function enregistrerValid() {
    const V = S.valid; if (!V) { return; }
    const l = {}; Object.entries(V.lignes).forEach(([pid, q]) => { if (q !== '' && q != null && !isNaN(+q)) { l[pid] = +q; } });
    S.envoi = true; rendre();
    ecrire('/production/flux/valider', { shop: +S.shop, date: S.date, cuisson: V.cuisson, lignes: l, par: S.par })
      .then(r => { S.msg = { ok: true, t: `Cuisson validée : ${fN(r.pieces)} pièces sorties sur ${r.lignes} produit${r.lignes > 1 ? 's' : ''}.` }; S.valid = null; delete S.data['cloture|' + S.shop + '|' + S.date]; delete S.data['plan|' + S.shop + '|' + S.date]; charger(true); })
      .catch(e => { S.msg = { ok: false, t: 'Pas enregistrée : ' + e.message }; })
      .finally(() => { S.envoi = false; rendre(); });
  }

  /* --- 4. Clôture -------------------------------------------------------------- */
  function pageCloture(d) {
    const L = d.lignes || [];
    if (!S.clot || S.clot.cle !== cle()) { const x = {}; L.forEach(l => { x[l.pid] = { report: l.report, jete: l.jete }; }); S.clot = { cle: cle(), l: x }; }
    const X = S.clot.l;
    const tot = { report: 0, jete: 0, valJ: 0, valR: 0 };
    L.forEach(l => { const v = X[l.pid] || {}; tot.report += +v.report || 0; tot.jete += +v.jete || 0; if (l.prix != null) { tot.valJ += (+v.jete || 0) * l.prix; tot.valR += (+v.report || 0) * l.prix; } });
    let h = `<div class="pf-intro"><b>Clôture du ${esc(fDL(d.date))}.</b> Pour chaque produit : report d’hier + sorti − vendu − jeté déjà déclaré = ce qui reste. Dites ce qui se garde pour demain (il entre dans le plan de ${esc(fDL(d.lendemain))} comme stock de départ de la 1re cuisson) et ce qui se jette (à encoder en caisse comme poubelle).${d.enregistree ? ` <b>Clôture enregistrée${d.le ? ' le ' + esc(fD(d.le.slice(0, 10))) + ' à ' + esc(d.le.slice(11, 16)) : ''}${d.par ? ' par ' + esc(d.par) : ''}.</b>` : ''}</div>`;
    if (d.cuissonsValidees < d.cuissons) { h += `<div class="pf-note"><b class="wa">${d.cuissons - d.cuissonsValidees} cuisson${d.cuissons - d.cuissonsValidees > 1 ? 's' : ''} sur ${d.cuissons} pas validée${d.cuissons - d.cuissonsValidees > 1 ? 's' : ''}</b> : leur « sorti » est celui du plan. Validez-les dans la page 3 pour une clôture juste.</div>`; }
    h += `<div class="pf-tuiles"><div><div class="k">Reste en vitrine</div><div class="v">${fN(L.reduce((a, l) => a + Math.round(l.reste), 0))} <small>pièces</small></div><div class="s">${L.length} produit${L.length > 1 ? 's' : ''}</div></div>
      <div class="veille"><div class="k">Se garde pour demain</div><div class="v">${fN(tot.report)} <small>pièces</small></div><div class="s">${fE(tot.valR)} au prix de vente</div></div>
      <div class="jete"><div class="k">À jeter</div><div class="v">${fN(tot.jete)} <small>pièces</small></div><div class="s">${fE(tot.valJ)} au prix de vente · à déclarer en caisse</div></div></div>`;
    let g = null;
    h += `<div class="pf-card"><div class="pf-ct"><span class="pf-k">Ce qui reste, produit par produit</span><span class="pf-mini">proposé : les catégories « se garde » sont gardées, le reste est jeté · corrigez au comptage</span><button class="pf-btn" data-tout="garder">Tout garder</button><button class="pf-btn" data-tout="jeter">Tout jeter</button><button class="pf-btn" data-tout="propose">Proposition</button></div>
      <table class="pf-tab"><thead><tr><th>Produit</th><th class="n">Report d’hier</th><th class="n">Sorti</th><th class="n">Vendu</th><th class="n">Jeté déclaré</th><th class="n">Reste</th><th class="n">Garder pour demain</th><th class="n">Jeter</th><th class="n">Écart</th></tr></thead><tbody>
      ${L.map(l => { const v = X[l.pid] || { report: 0, jete: 0 }; const e = (+v.report || 0) + (+v.jete || 0) - Math.round(l.reste); const t = l.groupe !== g ? `<tr class="grp"><td colspan="9">${esc(l.groupe || 'Sans section')}</td></tr>` : ''; g = l.groupe;
        return t + `<tr><td class="nom">${esc(l.nom)}${l.garde ? ' <span class="pf-tag bleu">se garde</span>' : ''}</td><td class="n mu">${l.report0 ? fQ(l.report0) : '—'}</td><td class="n">${fQ(l.sorti)}${l.sortiValide ? '' : '<small>plan</small>'}</td><td class="n">${fQ(l.vendu)}</td><td class="n mu">${l.jeteDeclare ? fQ(l.jeteDeclare) : '—'}</td><td class="n"><b>${fQ(l.reste)}</b></td>
          <td class="n"><input class="pf-in court" type="number" min="0" max="5000" data-cr="${l.pid}" data-f="cr${l.pid}" value="${esc(v.report)}"></td><td class="n"><input class="pf-in court" type="number" min="0" max="5000" data-cj="${l.pid}" data-f="cj${l.pid}" value="${esc(v.jete)}"></td>
          <td class="n ${Math.abs(e) < 0.05 ? 'mu' : 'wa'}" data-ce="${l.pid}">${Math.abs(e) < 0.05 ? '=' : (e > 0 ? '+' : '−') + fQ(Math.abs(e))}</td></tr>`; }).join('') || '<tr><td colspan="9" class="mu">Rien ne reste : aucune production ni report pour ce jour.</td></tr>'}</tbody></table>
      <div class="pf-pied mu">« Écart » : garder + jeter face au reste calculé — un écart dit une vente ou une perte non enregistrée. ${esc(d.source)}</div></div>`;
    h += `<div class="pf-barre"><label>Signé <input class="pf-in" data-par="1" data-f="par" value="${esc(S.par)}" placeholder="prénom"></label><span class="sp"></span><button class="pf-btn prim" data-cloturer="1"${S.envoi || !L.length ? ' disabled' : ''}>${S.envoi ? 'Enregistrement…' : (d.enregistree ? 'Mettre à jour la clôture' : 'Valider la clôture')}</button></div>`;
    return h;
  }
  function enregistrerCloture() {
    const d = S.data[cle()]; if (!d || !S.clot) { return; }
    const l = {}; d.lignes.forEach(x => { const v = S.clot.l[x.pid] || {}; l[x.pid] = { report: +v.report || 0, jete: +v.jete || 0, reste: x.reste }; });
    S.envoi = true; rendre();
    ecrire('/production/flux/cloture', { shop: +S.shop, date: S.date, lignes: l, par: S.par })
      .then(r => { S.msg = { ok: true, t: `Clôture enregistrée : ${fN(r.report)} pièces gardées pour demain, ${fN(r.jete)} à jeter.` }; S.clot = null; delete S.data['plan|' + S.shop + '|' + decale(S.date, 1)]; charger(true); })
      .catch(e => { S.msg = { ok: false, t: 'Pas enregistrée : ' + e.message }; })
      .finally(() => { S.envoi = false; rendre(); });
  }

  /* --- 5. Les fours ----------------------------------------------------------------- */
  // Une couleur par section, stable d'un jour à l'autre.
  const TEINTES = ['#8D1D2C', '#C9A227', '#1f5f8b', '#2d7a3e', '#D97706', '#6b4c9a', '#0f766e', '#b45309', '#475569', '#be185d'];
  const teinte = n => { let x = 0; for (const ch of String(n || '')) { x = (x * 31 + ch.charCodeAt(0)) % 997; } return TEINTES[x % TEINTES.length]; };
  const hm = m => (m >= 60 ? Math.floor(m / 60) + ' h ' : '') + String(m % 60).padStart(m >= 60 ? 2 : 1, '0') + ' min';
  /**
   * Le Gantt recalculé à l'écran avec les fours et réglages du brouillon — le même calcul que le
   * serveur (pfGantt) : plaques = pièces ÷ pièces par plaque, regroupées par four et par réglage, la
   * plus chaude d'abord, au plus tard pour sortir à l'ouverture de la vente, jamais avant l'heure
   * « au four » de la cuisson ni avant que le four soit libre.
   */
  function ganttCalc(entree, E) {
    const hh = x => { const m = Math.round(x * 60); return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0'); };
    const fours = {}; E.fours.forEach(f => { fours[f.id] = { id: f.id, nom: f.nom, plaques: Math.max(1, +f.plaques || 1), fournees: [], occupation: 0, plaquesTot: 0, libre: null }; });
    const hors = {}; let retards = 0, d = null, a = null, pd = null, pa = null;
    entree.forEach(c => {
      const de = hDe(c.de); if (de == null) { return; }
      const au = hDe(c.four);
      pd = Math.min(pd == null ? (au != null ? au : de) : pd, au != null ? au : de); pa = Math.max(pa == null ? de : pa, de);
      const grp = {};
      c.categories.forEach(x => {
        const r = E.categories[x.cle];
        if (!r || !r.four || !fours[r.four]) { hors[x.cle] = { cle: x.cle, nom: x.nom, groupe: x.groupe, pieces: (hors[x.cle] ? hors[x.cle].pieces : 0) + x.pieces }; return; }
        const pl = Math.ceil(x.pieces / Math.max(1, +r.parPlaque || 1));
        const g = r.four + '|' + (+r.temp) + '|' + (+r.duree);
        (grp[r.four] = grp[r.four] || {});
        (grp[r.four][g] = grp[r.four][g] || { temp: +r.temp, duree: +r.duree, cats: [] }).cats.push({ cle: x.cle, nom: x.nom, groupe: x.groupe, plaques: pl, pieces: x.pieces });
      });
      Object.keys(grp).forEach(fid => {
        const cap = fours[fid].plaques;
        const gs = Object.values(grp[fid]).sort((p, q) => (q.temp - p.temp) || (q.duree - p.duree));
        const fn = [];
        gs.forEach(g => {
          let cur = null;
          g.cats.forEach(x => {
            let reste = x.plaques, pieces = x.pieces;
            while (reste > 0) {
              if (!cur || cur.plaques >= cap) { if (cur) { fn.push(cur); } cur = { temp: g.temp, duree: g.duree, plaques: 0, categories: [] }; }
              const mis = Math.min(reste, cap - cur.plaques);
              const pc = Math.round(pieces * mis / Math.max(1, reste)); pieces -= pc;
              cur.plaques += mis; reste -= mis;
              cur.categories.push({ cle: x.cle, nom: x.nom, groupe: x.groupe, plaques: mis, pieces: pc });
            }
          });
          if (cur) { fn.push(cur); }
        });
        let t = de - fn.reduce((s2, x) => s2 + x.duree, 0) / 60;
        if (au != null && t < au) { t = au; }
        if (fours[fid].libre != null && t < fours[fid].libre) { t = fours[fid].libre; }
        fn.forEach(x => {
          const fin = t + x.duree / 60, ret = Math.round(Math.max(0, fin - de) * 60);
          if (ret > 0) { retards++; }
          fours[fid].fournees.push({ cuisson: c.id, cuissonNom: c.nom, debut: hh(t), fin: hh(fin), d: t, f: fin, temp: x.temp, duree: x.duree, plaques: x.plaques, capacite: cap, categories: x.categories, retard: ret });
          fours[fid].occupation += x.duree; fours[fid].plaquesTot += x.plaques;
          t = fin;
        });
        fours[fid].libre = t;
      });
    });
    Object.values(fours).forEach(f => f.fournees.forEach(x => { d = Math.min(d == null ? 99 : d, x.d); a = Math.max(a == null ? 0 : a, x.f); }));
    entree.forEach(c => { const x = hDe(c.de); if (x != null) { d = Math.min(d == null ? x : d, x); a = Math.max(a == null ? x : a, x); } });
    const fen = pd != null && pa != null ? Math.max(1, Math.round((pa - pd) * 60)) : null;
    return { fours: Object.values(fours).map(f => Object.assign(f, { nFournees: f.fournees.length, utilisation: fen != null ? Math.round(100 * f.occupation / fen) : null, remplissage: f.fournees.length ? Math.round(100 * f.plaquesTot / (f.fournees.length * f.plaques)) : null })),
      horsFour: Object.values(hors), retards, axe: { de: Math.floor(d == null ? 5 : d), a: Math.ceil(a == null ? 19 : a) }, fenetre: fen != null ? { de: hh(pd), a: hh(pa), minutes: fen } : null };
  }
  const sigF = E => JSON.stringify([E.fours.map(f => [f.id, f.nom, +f.plaques]), Object.entries(E.categories).map(([k, c]) => [k, c.four, +c.temp, +c.duree, +c.parPlaque])]);
  function brouillonF(d) { return { fours: d.fours.map(f => Object.assign({}, f)), categories: Object.fromEntries(d.categories.map(c => [c.cle, { four: c.four, temp: c.temp, duree: c.duree, parPlaque: c.parPlaque, nom: c.nom, groupe: c.groupe, auto: c.auto }])) }; }
  function pageFours(d) {
    if (!S.editF || S.editFCle !== S.shop) { S.editF = brouillonF(d); S.editFCle = S.shop; S.ganttF = null; }
    const E = S.editF;
    // Le Gantt affiché : celui des réglages au dernier rafraîchissement (au départ, les enregistrés).
    if (!S.ganttF || S.ganttF.date !== d.date || S.ganttF.shop !== S.shop) { S.ganttF = { date: d.date, shop: S.shop, sig: sigF(E), g: ganttCalc(d.gantt.entree || [], E) }; }
    const G = S.ganttF.g, A = G.axe, span = Math.max(1, A.a - A.de);
    const aJour = S.ganttF.sig === sigF(E), brouillon = sigF(E) !== sigF(brouillonF(d));
    const pc = (v, lib) => v == null ? '' : `<span class="${v > 100 ? 'ko' : (v >= 85 ? 'wa' : '')}">${lib} ${fN(v)} %</span>`;
    const pos = h => (100 * (h - A.de) / span).toFixed(2) + '%';
    let h = `<div class="pf-intro"><b>Les fours du ${esc(fDL(d.date))}.</b> Chaque cuisson du plan devient des fournées : les plaques de chaque catégorie (pièces ÷ pièces par plaque), regroupées par four et par réglage, la plus chaude d’abord, enchaînées pour sortir à l’ouverture de la vente. Une fournée qui sort après l’ouverture est en retard : à enfourner plus tôt, ou à répartir sur un autre four.${d.enregistre ? '' : ' Rien n’est encore enregistré : un four et des réglages proposés.'}</div>`;
    // Le Gantt.
    const heures = []; for (let x = A.de; x <= A.a; x++) { heures.push(x); }
    const sections = {};
    h += `<div class="pf-card"><div class="pf-ct"><span class="pf-k">Le Gantt des fours</span><span class="pf-mini">${G.fours.reduce((a, f) => a + f.nFournees, 0)} fournées · ${G.fours.reduce((a, f) => a + f.plaquesTot, 0)} plaques${G.fenetre ? ` · plage de production ${esc(G.fenetre.de)}–${esc(G.fenetre.a)}` : ''}${G.retards ? ` · <b class="ko">${G.retards} fournée${G.retards > 1 ? 's' : ''} en retard</b>` : ' · tout sort à l’heure'}${brouillon && aJour ? ' · <b class="wa">avec les réglages non enregistrés</b>' : ''}</span>
      <button class="pf-btn${aJour ? '' : ' prim'}" data-frefresh="1" title="recalculer le Gantt avec les fours et les réglages de cuisson ci-dessous">↻ Rafraîchir le Gantt${aJour ? '' : ' (réglages modifiés)'}</button></div>
      <div class="pf-gantt"><div class="gx"><div class="gl"></div><div class="gt">${heures.map(x => `<span style="left:${pos(x)}">${x} h</span>`).join('')}</div></div>
      ${G.fours.map(f => `<div class="gr"><div class="gl"><b>${esc(f.nom)}</b><small>${f.plaques} plaques · ${f.nFournees} fournée${f.nFournees > 1 ? 's' : ''} · ${hm(f.occupation)}</small><small class="ut">${pc(f.utilisation, 'utilisé')}${f.remplissage != null ? ' · ' + pc(f.remplissage, 'rempli') : ''}</small></div><div class="gt">
        ${heures.map(x => `<i class="gh" style="left:${pos(x)}"></i>`).join('')}
        ${d.cuissons.map(c => `<i class="gv" style="left:${pos(hDe(c.de))}" title="${esc(c.nom)} : ouverture de la vente à ${esc(c.de)}"></i>`).join('')}
        ${f.fournees.map(x => { const sec = x.categories[0] ? x.categories[0].groupe || x.categories[0].nom : ''; x.categories.forEach(c => { sections[c.groupe || c.nom] = true; });
          const lib = x.categories.map(c => `${c.nom} ${c.plaques} pl.`).join(' + ');
          return `<div class="gb${x.retard ? ' late' : ''}" style="left:${pos(x.d)};width:calc(${(100 * (x.f - x.d) / span).toFixed(2)}% - 2px);background:${teinte(sec)}" title="${esc(x.cuissonNom)} · ${esc(x.debut)}–${esc(x.fin)} · ${x.temp} °C · ${x.duree} min · ${x.plaques}/${x.capacite} plaques : ${esc(x.categories.map(c => c.nom + ' ' + c.plaques + ' pl. (' + c.pieces + ' pièces)').join(', '))}${x.retard ? ' · en retard de ' + x.retard + ' min' : ''}"><span>${esc(lib)}</span></div>`; }).join('')}
      </div></div>`).join('')}
      <div class="gx bas"><div class="gl"></div><div class="gt">${d.cuissons.map(c => `<span class="cu" style="left:${pos(hDe(c.de))}">${esc(c.nom)} ${esc(c.de)}</span>`).join('')}</div></div></div>
      <div class="pf-leg">${Object.keys(sections).map(n => `<span><i style="background:${teinte(n)}"></i>${esc(n)}</span>`).join('')}<span><i class="late"></i>en retard</span><span><i class="gvl"></i>ouverture de la vente</span></div>
      ${G.horsFour.length ? `<div class="pf-pied mu">Hors four : ${G.horsFour.map(x => esc(x.nom) + ' (' + fN(x.pieces) + ')').join(' · ')}</div>` : ''}</div>`;
    // Les fournées en liste, four par four.
    h += `<div class="pf-card"><div class="pf-ct"><span class="pf-k">Les fournées</span><span class="pf-mini">à enfourner dans l’ordre, four par four</span></div>
      <table class="pf-tab"><thead><tr><th>Four</th><th>Cuisson</th><th class="n">Enfourner</th><th class="n">Sortir</th><th class="n">°C</th><th class="n">Plaques</th><th>Catégories</th></tr></thead><tbody>
      ${G.fours.map(f => f.fournees.map((x, i) => `<tr><td class="nom">${i === 0 ? esc(f.nom) : ''}</td><td>${esc(x.cuissonNom)}</td><td class="n"><b>${esc(x.debut)}</b></td><td class="n ${x.retard ? 'ko' : ''}">${esc(x.fin)}${x.retard ? ' <small>+' + x.retard + ' min</small>' : ''}</td><td class="n">${x.temp}</td><td class="n">${x.plaques} / ${x.capacite}</td><td>${x.categories.map(c => `${esc(c.nom)} <span class="mu">${c.plaques} pl. · ${fN(c.pieces)} p.</span>`).join(' · ')}</td></tr>`).join('')).join('') || '<tr><td colspan="7" class="mu">Aucune fournée : rien à cuire ce jour, ou aucune catégorie n’a de four.</td></tr>'}</tbody></table></div>`;
    // Les fours.
    h += `<div class="pf-card"><div class="pf-ct"><span class="pf-k">Les fours du magasin</span><span class="pf-mini">le nombre de plaques qu’une fournée peut contenir</span></div>
      <table class="pf-tab"><thead><tr><th>Four</th><th class="n">Plaques par fournée</th><th class="n">Fournées du jour</th><th class="n">Temps de cuisson</th><th class="n">Utilisation</th><th class="n">Remplissage</th><th></th></tr></thead><tbody>
      ${E.fours.map((f, i) => { const g = G.fours.find(x => x.id === f.id); return `<tr><td><input class="pf-in" data-fn="${i}" data-f="fn${i}" value="${esc(f.nom)}"></td><td class="n"><input class="pf-in court" type="number" min="1" max="100" data-fp="${i}" data-f="fp${i}" value="${esc(f.plaques)}"></td>
        <td class="n">${g ? fN(g.nFournees) : '<span class="mu">—</span>'}</td><td class="n">${g ? hm(g.occupation) : '<span class="mu">—</span>'}</td><td class="n">${g && g.utilisation != null ? pc(g.utilisation, '') : '<span class="mu">—</span>'}</td><td class="n">${g && g.remplissage != null ? pc(g.remplissage, '') : '<span class="mu">—</span>'}</td>
        <td>${E.fours.length > 1 ? `<button class="pf-x" data-fsup="${i}" title="retirer">×</button>` : ''}</td></tr>`; }).join('')}</tbody></table>
      <div class="pf-pied mu" style="border-top:none;padding-top:0">Utilisation : le temps de cuisson du four sur la plage de production${G.fenetre ? ` (${esc(G.fenetre.de)}–${esc(G.fenetre.a)}, de la 1re mise au four à la dernière ouverture de vente)` : ''} ; au-delà de 100 %, le four ne suffit pas. Remplissage : les plaques enfournées sur la capacité des fournées. Après un changement, « Rafraîchir le Gantt » recalcule sans enregistrer.</div>
      <div class="pf-pied">${E.fours.length < 6 ? '<button class="pf-btn" data-fajout="1">+ ajouter un four</button>' : ''}</div></div>`;
    // Les réglages de cuisson par catégorie.
    let g = null;
    const cats = Object.entries(E.categories).sort((a, b) => [(a[1].groupe || 'zzz'), a[1].nom].join('|').localeCompare([(b[1].groupe || 'zzz'), b[1].nom].join('|')));
    h += `<div class="pf-card"><div class="pf-ct"><span class="pf-k">La cuisson de chaque catégorie</span><span class="pf-mini">le four (aucun = ne passe pas au four), la température, la durée d’une fournée, les pièces par plaque</span></div>
      <table class="pf-tab"><thead><tr><th>Catégorie</th><th>Four</th><th class="n">Température °C</th><th class="n">Durée min</th><th class="n">Pièces par plaque</th></tr></thead><tbody>
      ${cats.map(([k, c]) => { const gr = c.groupe || 'Sans section'; const t = gr !== g ? `<tr class="grp"><td colspan="5">${esc(gr)}</td></tr>` : ''; g = gr;
        return t + `<tr><td class="nom">${esc(c.nom)}${c.auto ? ' <span class="pf-tag">proposé</span>' : ''}</td>
          <td><select data-cf="${esc(k)}"><option value="">aucun</option>${E.fours.map(f => `<option value="${esc(f.id)}"${f.id === c.four ? ' selected' : ''}>${esc(f.nom)}</option>`).join('')}</select></td>
          <td class="n"><input class="pf-in court" type="number" min="50" max="300" step="5" data-ct="${esc(k)}" data-f="ct${esc(k)}" value="${esc(c.temp)}"${c.four ? '' : ' disabled'}></td>
          <td class="n"><input class="pf-in court" type="number" min="1" max="240" data-cd="${esc(k)}" data-f="cd${esc(k)}" value="${esc(c.duree)}"${c.four ? '' : ' disabled'}></td>
          <td class="n"><input class="pf-in court" type="number" min="1" max="200" data-cpp="${esc(k)}" data-f="cpp${esc(k)}" value="${esc(c.parPlaque)}"${c.four ? '' : ' disabled'}></td></tr>`; }).join('')}</tbody></table></div>`;
    h += `<div class="pf-barre"><label>Signé <input class="pf-in" data-par="1" data-f="par" value="${esc(S.par)}" placeholder="prénom"></label><span class="sp"></span><button class="pf-btn" data-fannuler="1">Revenir aux réglages enregistrés</button><button class="pf-btn prim" data-fenreg="1"${S.envoi ? ' disabled' : ''}>${S.envoi ? 'Enregistrement…' : 'Enregistrer les fours'}</button></div>`;
    h += `<div class="pf-pied mu" style="border:none">${esc(d.source)}</div>`;
    return h;
  }
  function enregistrerFours() {
    const E = S.editF; if (!E) { return; }
    const corps = { shop: +S.shop, par: S.par, fours: E.fours.map(f => ({ id: f.id, nom: f.nom, plaques: +f.plaques })),
      categories: Object.fromEntries(Object.entries(E.categories).map(([k, c]) => [k, { four: c.four || null, temp: +c.temp, duree: +c.duree, parPlaque: +c.parPlaque, nom: c.nom }])) };
    S.envoi = true; S.msg = null; rendre();
    ecrire('/production/flux/fours', corps).then(r => { S.msg = { ok: true, t: `Fours enregistrés : ${r.fours} four${r.fours > 1 ? 's' : ''}, ${r.categories} catégories réglées. Le Gantt les reprend.` }; S.editF = null; S.ganttF = null; Object.keys(S.data).forEach(k => { if (k.startsWith('fours|')) { delete S.data[k]; } }); charger(true); })
      .catch(e => { S.msg = { ok: false, t: 'Pas enregistrés : ' + e.message }; })
      .finally(() => { S.envoi = false; rendre(); });
  }

  /* --- branchements --------------------------------------------------------------- */
  function brancher() {
    const on = (sel, ev, f) => $.querySelectorAll(sel).forEach(el => el.addEventListener(ev, e => f(el, e)));
    on('[data-page]', 'click', b => aller(b.dataset.page));
    on('#pf-shop', 'change', s => { S.shop = s.value; S.edit = null; S.editF = null; S.valid = null; S.clot = null; S.msg = null; urlMaj(); charger(false); });
    on('#pf-date', 'change', i => choisirJour(i.value));
    on('[data-pas]', 'click', b => choisirJour(decale(S.date, +b.dataset.pas)));
    on('[data-jour]', 'click', b => choisirJour(b.dataset.jour));
    on('[data-relire]', 'click', () => charger(true));
    on('[data-par]', 'input', i => signe(i.value));
    on('[data-imprimer]', 'click', () => window.print());
    on('[data-ecarts]', 'change', c => { S.ecartsJ7 = c.checked; rendre(); });
    // Paramètres
    const E = S.edit;
    on('[data-jour-n]', 'change', s => { E.jours[s.dataset.jourN].cuissons = +s.value; rendre(); });
    on('[data-jour-min]', 'input', i => { E.jours[i.dataset.jourMin].minPct = i.value; });
    on('[data-cu]', 'input', i => { const c = E.cuissons[+i.dataset.cu]; c[i.dataset.k] = i.dataset.k === 'pct' ? i.value : i.value; });
    on('[data-cu]', 'change', () => rendre());
    on('[data-cusup]', 'click', b => { const i = +b.dataset.cusup; const id = E.cuissons[i].id; E.cuissons.splice(i, 1); Object.values(E.categories).forEach(c => { c.cuissons = c.cuissons.filter(x => x !== id); if (c.parts) { delete c.parts[id]; } }); Object.values(E.jours).forEach(j => { j.cuissons = Math.min(j.cuissons, E.cuissons.length); }); rendre(); });
    on('[data-cuajout]', 'click', () => { let n = 1; while (E.cuissons.some(c => c.id === 'c' + n)) { n++; } const der = E.cuissons[E.cuissons.length - 1]; E.cuissons.push({ id: 'c' + n, nom: 'Cuisson ' + (E.cuissons.length + 1), de: der ? der.a : '16:00', a: '19:00', pct: 0, daypart: null }); rendre(); });
    on('[data-regle]', 'input', i => { E.regles[i.dataset.regle] = i.value === '' ? '' : +i.value; });
    on('[data-catpart]', 'input', i => { const x = E.categories[i.dataset.catpart]; if (!x.parts) { x.parts = partsDefaut(E, x); }
      x.parts[i.dataset.cuId] = i.value === '' ? 0 : Math.max(0, Math.min(100, +i.value)); x.cuissons = E.cuissons.map(y => y.id).filter(id => +x.parts[id] > 0); x.auto = false;
      const td = $.querySelector(`[data-cattot="${i.dataset.catpart.replace(/"/g, '\\"')}"]`); if (td) { td.innerHTML = totPart(Object.values(x.parts).reduce((a, v) => a + (+v || 0), 0), true); } });
    on('[data-catpart]', 'change', () => rendre());
    on('[data-catreset]', 'click', b => { const x = E.categories[b.dataset.catreset]; x.parts = null; rendre(); });
    on('[data-catcu]', 'change', c => { const x = E.categories[c.dataset.catcu]; const id = c.dataset.cuId; x.cuissons = c.checked ? E.cuissons.map(y => y.id).filter(y => y === id || x.cuissons.includes(y)) : x.cuissons.filter(y => y !== id); x.auto = false; });
    on('[data-catpl]', 'change', s => { E.categories[s.dataset.catpl].plaque = +s.value > 1 ? +s.value : null; });
    on('[data-catsm]', 'input', i => { E.categories[i.dataset.catsm].stockMin = i.value === '' ? 0 : +i.value; });
    on('[data-ajust]', 'change', c => { E.ajusterJ7 = c.checked; rendre(); });
    on('[data-modepeu]', 'change', s => { E.modePeu = s.value === 'stock' ? 'stock' : 'production'; });
    on('[data-catv]', 'change', c => { E.categories[c.dataset.catv].veille = c.checked; });
    on('[data-catg]', 'change', c => { E.categories[c.dataset.catg].garde = c.checked; });
    const ob = pid => E.oblig[pid] || (E.oblig[pid] = { jours: [], min: 2, reseau: false });
    on('[data-obj]', 'change', c => { const o = ob(c.dataset.obj), j = +c.dataset.jj; o.jours = c.checked ? [...new Set(o.jours.concat([j]))].sort() : o.jours.filter(x => x !== j); rendre(); });
    on('[data-obtous]', 'click', b => { const o = ob(b.dataset.obtous); o.jours = o.jours.length === 7 ? [] : [1, 2, 3, 4, 5, 6, 7]; rendre(); });
    on('[data-obmin]', 'input', i => { ob(i.dataset.obmin).min = i.value; });
    on('[data-filtre]', 'input', i => { S.filtre = i.value; rendre(); });
    on('[data-seuls]', 'change', c => { S.seulsOblig = c.checked; rendre(); });
    on('[data-annuler]', 'click', () => { const d = S.data[cle()]; if (d) { S.edit = brouillon(d); S.msg = null; rendre(); } });
    on('[data-enreg]', 'click', () => enregistrerParams());
    // Validation et suivi
    on('[data-valid]', 'click', b => ouvrirValid(b.dataset.valid));
    on('[data-vq]', 'input', i => { S.valid.lignes[i.dataset.vq] = i.value; const d = S.data[cle()]; const c = d.cuissons.find(x => x.id === S.valid.cuisson); const l = c && c.lignes.find(x => String(x.pid) === i.dataset.vq); const td = $.querySelector(`[data-ve="${i.dataset.vq}"]`); if (l && td) { const e = (+i.value || 0) - l.sortie; td.className = 'n ' + (e > 0 ? 'ok' : (e < 0 ? 'ko' : 'mu')); td.textContent = e ? (e > 0 ? '+' : '−') + fN(Math.abs(e)) : '='; } });
    on('[data-commeprevu]', 'click', () => { const d = S.data[cle()]; const c = d.cuissons.find(x => x.id === S.valid.cuisson); c.lignes.forEach(l => { S.valid.lignes[l.pid] = l.sortie; }); rendre(); });
    on('[data-validok]', 'click', () => enregistrerValid());
    on('[data-alertes]', 'change', c => { S.alertes = c.checked; rendre(); });
    // Clôture
    const ecart = pid => { const d = S.data[cle()]; const l = d.lignes.find(x => String(x.pid) === String(pid)); const v = S.clot.l[pid]; const td = $.querySelector(`[data-ce="${pid}"]`); if (!l || !td) { return; } const e = (+v.report || 0) + (+v.jete || 0) - Math.round(l.reste); td.className = 'n ' + (Math.abs(e) < 0.05 ? 'mu' : 'wa'); td.textContent = Math.abs(e) < 0.05 ? '=' : (e > 0 ? '+' : '−') + fQ(Math.abs(e)); };
    on('[data-cr]', 'input', i => { (S.clot.l[i.dataset.cr] = S.clot.l[i.dataset.cr] || {}).report = i.value; ecart(i.dataset.cr); });
    on('[data-cj]', 'input', i => { (S.clot.l[i.dataset.cj] = S.clot.l[i.dataset.cj] || {}).jete = i.value; ecart(i.dataset.cj); });
    on('[data-cr],[data-cj]', 'change', () => rendre());
    on('[data-tout]', 'click', b => { const d = S.data[cle()]; d.lignes.forEach(l => { const r = Math.round(l.reste); S.clot.l[l.pid] = b.dataset.tout === 'garder' ? { report: r, jete: 0 } : (b.dataset.tout === 'jeter' ? { report: 0, jete: r } : { report: l.garde ? r : 0, jete: l.garde ? 0 : r }); }); rendre(); });
    on('[data-cloturer]', 'click', () => enregistrerCloture());
    // Fours
    const F = S.editF;
    if (F) {
      // Une saisie ne redessine pas la page (le champ suivant garde la main) : seul le bouton
      // « Rafraîchir le Gantt » signale que les réglages ont changé.
      const majF = () => { const b = $.querySelector('[data-frefresh]'); if (!b || !S.ganttF) { return; } const ok = S.ganttF.sig === sigF(F); b.classList.toggle('prim', !ok); b.textContent = '↻ Rafraîchir le Gantt' + (ok ? '' : ' (réglages modifiés)'); };
      on('[data-fn]', 'input', i => { F.fours[+i.dataset.fn].nom = i.value; majF(); });
      on('[data-fp]', 'input', i => { F.fours[+i.dataset.fp].plaques = i.value; majF(); });
      on('[data-fsup]', 'click', b => { const i = +b.dataset.fsup; const id = F.fours[i].id; F.fours.splice(i, 1); Object.values(F.categories).forEach(c => { if (c.four === id) { c.four = F.fours[0] ? F.fours[0].id : null; } }); rendre(); });
      on('[data-fajout]', 'click', () => { let n = 1; while (F.fours.some(f => f.id === 'f' + n)) { n++; } F.fours.push({ id: 'f' + n, nom: 'Four ' + (F.fours.length + 1), plaques: 10 }); rendre(); });
      on('[data-cf]', 'change', s => { const c = F.categories[s.dataset.cf]; c.four = s.value || null; c.auto = false; rendre(); });
      on('[data-ct]', 'input', i => { F.categories[i.dataset.ct].temp = i.value; F.categories[i.dataset.ct].auto = false; majF(); });
      on('[data-cd]', 'input', i => { F.categories[i.dataset.cd].duree = i.value; F.categories[i.dataset.cd].auto = false; majF(); });
      on('[data-cpp]', 'input', i => { F.categories[i.dataset.cpp].parPlaque = i.value; F.categories[i.dataset.cpp].auto = false; majF(); });
      on('[data-fannuler]', 'click', () => { S.editF = null; S.ganttF = null; S.msg = null; rendre(); });
      on('[data-fenreg]', 'click', () => enregistrerFours());
      on('[data-frefresh]', 'click', () => { const d = S.data[cle()]; if (d) { S.ganttF = { date: d.date, shop: S.shop, sig: sigF(F), g: ganttCalc(d.gantt.entree || [], F) }; rendre(); } });
    }
  }

  /* --- départ ------------------------------------------------------------------------- */
  if (EMBED) { document.body.classList.add('pf-emb'); }
  // La validation et la clôture ne vont pas au-delà d'aujourd'hui ; le plan, sept jours.
  if (!FUTUR(S.page) && S.date > AUJ) { S.date = AUJ; }
  if (FUTUR(S.page) && S.date > decale(AUJ, 7)) { S.date = AUJ; }
  lire('/stores?statut=tous').then(l => { S.stores = (Array.isArray(l) ? l : []).filter(s => !s.status || /ouvert/i.test(s.status)).map(s => ({ id: s.id, nom: s.nom || s.name })); rendre(); }).catch(() => {});
  urlMaj();
  charger(false);
  // Le suivi du jour se relit toutes les cinq minutes, sauf pendant une saisie.
  setInterval(() => { if (S.page === 'suivi' && S.date === AUJ && !S.valid && !S.envoi) { charger(true); } }, 300000);
})();
