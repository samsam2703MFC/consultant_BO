/* Dashboard magasin — une page à part.
 *
 * Pour UN magasin (?shop=4) : tout ce que Résultat dit de lui (Jour · Semaine ·
 * Mois, déplié), puis l'analyse des heures — la cascade de chaque heure
 * (ventes − matière = marge brute, marge brute − travail = résultat) et le top
 * 5 des produits de l'heure cliquée, avec leur marge.
 *
 * Trois lectures de l'API du cockpit, jamais devinées :
 *   ../api/cockpit/exploitation/jour?date=         (Résultat › Jour)
 *   ../api/cockpit/exploitation/periode?vue=&date= (Résultat › Semaine, Mois)
 *   ../api/cockpit/ventes/stats?shop=&vue=&date=   (les heures, les produits)
 */
(function () {
  'use strict';
  const API = '../api/cockpit';
  const q = new URLSearchParams(location.search);
  const S = { shop: q.get('shop') || '4', vue: ['jour', 'semaine', 'mois', 'trimestre', 'annee'].includes(q.get('vue')) ? q.get('vue') : 'jour',
    date: /^\d{4}-\d{2}-\d{2}$/.test(q.get('date') || '') ? q.get('date') : new Date().toISOString().slice(0, 10),
    heure: null, mode: 'moy', hmMetric: 'pct', tOuvert: false, nOuvert: false, cOuvert: false, cTri: 'famille', jourH: null, pOuvert: false, stores: [], res: {}, st: {}, enCours: {}, err: {}, relances: {}, aux: {},
    ncOuvert: false, ncPhotos: {}, ncLigne: null, ncGrav: {}, valoOuvert: false };
  const AUJ = new Date().toISOString().slice(0, 10);
  const $ = document.getElementById('dash');

  /* --- formats ------------------------------------------------------------ */
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
  const fE = n => n == null ? '—' : nf(Math.round(n) || 0, 0) + ' €';
  const fK = n => n == null ? '—' : (Math.abs(n) >= 10000 ? Number(n / 1000).toLocaleString('fr-BE', { minimumFractionDigits: 0, maximumFractionDigits: 1 }) + ' k€' : fE(n));
  const fU = n => n == null ? '—' : nf(n, 2) + ' €';
  const fP = n => n == null ? '—' : nf(n, 1) + ' %';
  const fS = n => n == null ? '—' : (n >= 0 ? '+ ' : '− ') + fE(Math.abs(n));
  const fSK = n => n == null ? '—' : (n >= 0 ? '+ ' : '− ') + fK(Math.abs(n));
  const fN = n => n == null ? '—' : nf(Math.round(n), 0);
  const coul = n => n == null ? 'mu' : (n >= 0 ? 'ok' : 'ko');
  const fD = d => d ? d.slice(8, 10) + '/' + d.slice(5, 7) : '';
  const fDL = d => { if (!d) { return ''; } const t = new Date(d + 'T12:00:00'); return t.toLocaleDateString('fr-BE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }); };
  const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  // Les abréviations d'usage : « mai » et « août » ne se coupent pas, donc
  // pas de point — « août. » n'existe pas.
  const MOIS_C = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

  /* --- lecture ------------------------------------------------------------ */
  function lire(path) {
    return fetch(API + path, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : r.json().then(j => Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status))), () => Promise.reject(new Error('HTTP ' + r.status))));
  }
  function cleRes() { return S.vue + '|' + S.date; }
  /** Le jour dont on lit les heures : en vue Jour, un jour cliqué dans « le jour dans le mois », sinon la date. */
  function dateH() { return S.vue === 'jour' && S.jourH ? S.jourH : S.date; }
  function cleSt() { return S.shop + '|' + S.vue + '|' + dateH(); }
  function annee() { return +S.date.slice(0, 4); }
  function trimestre() { return Math.floor((+S.date.slice(5, 7) - 1) / 3) + 1; }
  function bornes() {
    const t = new Date(S.date + 'T12:00:00');
    if (S.vue === 'semaine') { const j = (t.getDay() + 6) % 7; const du = new Date(t); du.setDate(t.getDate() - j); const au = new Date(du); au.setDate(du.getDate() + 6); return [du.toISOString().slice(0, 10), au.toISOString().slice(0, 10)]; }
    const du = S.date.slice(0, 8) + '01'; const fin = new Date(t.getFullYear(), t.getMonth() + 1, 0); return [du, fin.toISOString().slice(0, 10)];
  }
  function lireAux(cle, path, force) {
    if ((force || !S.aux[cle]) && !S.enCours[cle]) {
      S.enCours[cle] = true; delete S.err[cle];
      lire(path).then(d => { S.aux[cle] = d; }).catch(e => { S.err[cle] = e.message; }).finally(() => { S.enCours[cle] = false; rendre(); });
    }
  }
  function charger(force) {
    const kr = cleRes(), ks = cleSt();
    // La valeur du magasin ne dépend pas de la période regardée : elle se lit
    // toujours à partir d'aujourd'hui. Trois exercices couvrent les 18 mois
    // même quand on est en début d'année.
    const yA = +AUJ.slice(0, 4);
    lireAux('valo', '/stores/perf?granularite=mois&annees=' + (yA - 2) + ',' + (yA - 1) + ',' + yA, force);
    if (S.vue === 'annee' || S.vue === 'trimestre') {
      lireAux('perf|' + annee(), '/stores/perf?granularite=mois&annees=' + (annee() - 1) + ',' + annee(), force);
      lireAux('plan|' + S.shop + '|' + annee(), '/plan?shop=' + encodeURIComponent(S.shop) + '&exercice=' + annee(), force);
      rendre(); return;
    }
    if (S.vue === 'mois' && S.date.slice(0, 7) === AUJ.slice(0, 7)) { lireAux('rentab', '/exploitation/rentabilite?periode=mois', force); }
    lireAux('notif|' + S.shop, '/ventes/notifications?shop=' + encodeURIComponent(S.shop), force);
    if (S.vue === 'jour') { lireAux('taches|' + S.date, '/pwa/tasks?date=' + S.date, force); lireAux('record|' + S.shop + '|' + S.date, '/ventes/record?shop=' + encodeURIComponent(S.shop) + '&date=' + S.date, force); lireAux('tend|' + S.shop + '|' + S.date, '/ventes/tendance?shop=' + encodeURIComponent(S.shop) + '&date=' + S.date, force); }
    else { const [du, au] = bornes(); lireAux('tachesP|' + du + '|' + au, '/pwa/tasks/heatmap/mois?du=' + du + '&au=' + (au < AUJ ? au : AUJ), force); }
    // Les non-conformités se lisent sous les trois vues : la veille en Jour,
    // la période affichée en Semaine et en Mois.
    lireAux(cleNC(), urlNC(ncFenetre()), force);
    if ((force || !S.res[kr]) && !S.enCours[kr]) {
      S.enCours[kr] = true; delete S.err[kr];
      const p = S.vue === 'jour' ? '/exploitation/jour?date=' + S.date : '/exploitation/periode?vue=' + S.vue + '&date=' + S.date;
      lire(p).then(d => { S.res[kr] = d; }).catch(e => { S.err[kr] = e.message; }).finally(() => { S.enCours[kr] = false; rendre(); });
    }
    if ((force || !S.st[ks]) && !S.enCours[ks]) {
      S.enCours[ks] = true; delete S.err[ks];
      lire('/ventes/stats?shop=' + encodeURIComponent(S.shop) + '&vue=' + S.vue + '&date=' + dateH())
        .then(d => { S.st[ks] = d; if (S.heure === null && d.meilleure) { S.heure = d.meilleure.h; }
          // Une lecture partielle (tickets à suivre) se complète toute seule.
          if (d.produits && d.produits.aSuivre && (S.relances[ks] || 0) < 6) { S.relances[ks] = (S.relances[ks] || 0) + 1; setTimeout(() => { if (cleSt() === ks) { charger(true); } }, 4000); } })
        .catch(e => { S.err[ks] = e.message;
          // Un échec (temps dépassé côté serveur) se retente : ce qui a été lu est gravé.
          if ((S.relances[ks] || 0) < 3) { S.relances[ks] = (S.relances[ks] || 0) + 1; setTimeout(() => { if (cleSt() === ks) { charger(true); } }, 5000); } })
        .finally(() => { S.enCours[ks] = false; rendre(); });
    }
    rendre();
  }
  function urlMaj() {
    const u = new URL(location.href);
    u.searchParams.set('shop', S.shop); u.searchParams.set('vue', S.vue); u.searchParams.set('date', S.date);
    history.replaceState(null, '', u.toString());
  }

  /* --- le magasin dans la réponse Résultat --------------------------------- */
  function magasin(d) {
    if (!d || !Array.isArray(d.magasins)) { return null; }
    return d.magasins.find(m => String(m.shopId) === String(S.shop)) || null;
  }
  function nomShop() {
    const s = S.stores.find(x => String(x.id) === String(S.shop));
    return s ? s.nom : ('Magasin ' + S.shop);
  }
  function libPeriode() {
    if (S.vue === 'jour') { return fDL(S.date); }
    if (S.vue === 'annee') { return 'année ' + annee(); }
    if (S.vue === 'trimestre') { return 'T' + trimestre() + ' ' + annee(); }
    const d = S.res[cleRes()];
    if (S.vue === 'semaine') { return d && d.du ? 'semaine du ' + fD(d.du) + ' au ' + fD(d.au) : 'semaine'; }
    const t = new Date(S.date + 'T12:00:00');
    return MOIS[t.getMonth()] + ' ' + t.getFullYear();
  }

  /* --- rendu -------------------------------------------------------------- */
  /* --- La valeur du magasin ------------------------------------------------
   * La règle du réseau : le CA mensuel moyen des 18 derniers mois, ramené à
   * l'année (× 12), divisé par 6 — soit deux mois de chiffre d'affaires. Le
   * mois en cours est écarté : il est incomplet et tirerait la moyenne vers
   * le bas jusqu'à son dernier jour. Quand l'historique est plus court, on
   * calcule sur ce qu'on a et on le dit : mieux vaut un chiffre daté qu'un
   * tiret. */
  const VALO_MOIS = 18, VALO_DIV = 6;
  function valeurMagasin() {
    const d = S.aux['valo'];
    if (!Array.isArray(d)) return null;
    const enCours = AUJ.slice(0, 7);
    const cle = c => c.annee + '-' + String(c.mois).padStart(2, '0');
    const serie = d
      .filter(c => String(c.storeId) === String(S.shop) && c.ca != null && cle(c) < enCours)
      .sort((a, b) => cle(a) < cle(b) ? -1 : 1)
      .slice(-VALO_MOIS);
    if (!serie.length) return { n: 0 };
    const moy = serie.reduce((a, c) => a + c.ca, 0) / serie.length;
    const lib = c => MOIS_C[c.mois - 1] + ' ' + c.annee;
    return {
      n: serie.length, moyenne: moy, annuel: moy * 12, valeur: moy * 12 / VALO_DIV,
      du: lib(serie[0]), au: lib(serie[serie.length - 1]),
      serie: serie.map(c => c.ca)
    };
  }
  /* Les six derniers trimestres CLOS — le trimestre en cours est écarté comme
   * le mois en cours : à mi-parcours il vaudrait la moitié de lui-même. Chaque
   * trimestre porte la valeur que le magasin aurait eue à ce rythme-là. */
  function valoTrimestres() {
    const d = S.aux['valo'];
    if (!Array.isArray(d)) return null;
    let y = +AUJ.slice(0, 4), q = Math.floor((+AUJ.slice(5, 7) - 1) / 3) + 1;
    const cles = [];
    for (let i = 0; i < 6; i++) { q--; if (q < 1) { q = 4; y--; } cles.unshift(y + 'T' + q); }
    const par = {};
    d.filter(c => String(c.storeId) === String(S.shop) && c.ca != null).forEach(c => {
      const k = c.annee + 'T' + (Math.floor((c.mois - 1) / 3) + 1);
      (par[k] = par[k] || []).push(c.ca);
    });
    const out = cles.map(k => {
      const l = par[k] || [], ca = l.reduce((a, b) => a + b, 0);
      const moy = l.length ? ca / l.length : null;
      return { lib: 'T' + k.slice(5) + ' ' + k.slice(0, 4), n: l.length, ca: l.length ? ca : null,
        moyenne: moy, valeur: moy == null ? null : moy * 12 / VALO_DIV };
    });
    return out.some(o => o.n) ? out : null;
  }
  /** La courbe des six trimestres : les trous ne sont pas reliés, ils se voient. */
  function courbeTrim(T) {
    const vs = T.map(o => o.valeur).filter(v => v != null);
    // La boîte est à la largeur réelle du bloc : étirée, un cercle deviendrait
    // une ellipse. Les abscisses tombent au centre des six colonnes de dessous,
    // pour que chaque point soit au-dessus de son trimestre.
    const mn = Math.min(...vs), mx = Math.max(...vs), W = 1360, H = 150;
    const x = i => ((i + 0.5) * W / T.length).toFixed(1);
    const y = v => (H - 14 - (H - 32) * (v - mn) / ((mx - mn) || 1)).toFixed(1);
    // Un trou coupe le trait : on dessine des segments, pas une seule ligne.
    const traits = []; let run = [];
    T.forEach((o, i) => {
      if (o.valeur == null) { if (run.length > 1) traits.push(run); run = []; return; }
      run.push(x(i) + ',' + y(o.valeur));
    });
    if (run.length > 1) traits.push(run);
    return `<svg class="db-vtr" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" aria-hidden="true">
      ${T.map((o, i) => `<line class="gr" x1="${x(i)}" y1="6" x2="${x(i)}" y2="${H - 4}"></line>`).join('')}
      ${traits.map(r => `<polyline points="${r.join(' ')}"></polyline>`).join('')}
      ${T.map((o, i) => o.valeur == null ? '' : `<circle cx="${x(i)}" cy="${y(o.valeur)}" r="3.4"></circle>`).join('')}
    </svg>`;
  }
  function valoTiroir() {
    const T = valoTrimestres();
    if (!T) return '<div class="vide">Pas encore de trimestre clos pour ce magasin.</div>';
    const connus = T.filter(o => o.n).length;
    return `<div class="db-vtri">
      <div class="hd">La valeur trimestre par trimestre — ce que le magasin aurait valu au rythme de chaque trimestre.
        ${connus < 6 ? '<b>' + connus + ' trimestre' + (connus > 1 ? 's' : '') + ' clos sur six</b> dans le relevé ; les autres sont vides.' : 'Six trimestres clos, le trimestre en cours écarté.'}</div>
      ${courbeTrim(T)}
      <div class="gr6">${T.map(o => `<div class="${o.n ? '' : 'vide'}">
        <span class="q">${esc(o.lib)}</span>
        <span class="v">${o.valeur == null ? '—' : fE(o.valeur)}</span>
        <span class="s">${o.ca == null ? 'pas de CA relevé' : fE(o.ca) + ' de CA' + (o.n < 3 ? ' · ' + o.n + ' mois sur 3' : '')}</span>
      </div>`).join('')}</div>
    </div>`;
  }

  /* La valeur tient sur la barre du haut : un mot, un chiffre, la courbe des
   * 18 mois en miniature. Tout le reste — la formule, la fenêtre, les six
   * trimestres — attend dans le tiroir. */
  function valoPastille() {
    const v = valeurMagasin();
    if (!v) return '<span class="db-valoc att">Valeur du magasin…</span>';
    const dr = S.valoOuvert ? '\u25b4' : '\u25be';
    if (!v.n) return `<button class="db-valoc" data-vdrop="1"><span class="k">Valeur</span><span class="v">—</span><span class="dr">${dr}</span></button>`;
    return `<button class="db-valoc${S.valoOuvert ? ' ouv' : ''}" data-vdrop="1" title="CA mensuel moyen des 18 derniers mois clos × 12 ÷ ${VALO_DIV}">
      <span class="k">Valeur</span><span class="v">${fE(v.valeur)}</span><span class="dr">${dr}</span></button>`;
  }
  /* Le tiroir : la formule en toutes lettres, puis les six trimestres. */
  function rendValeur() {
    if (!S.valoOuvert) return '';
    const v = valeurMagasin();
    if (!v || !v.n) {
      return `<div class="db-vdl seul"><div class="vide">${v ? 'Aucun mois complet de chiffre d’affaires relevé pour ce magasin.' : 'Lecture du chiffre d’affaires des 18 derniers mois…'}</div></div>`;
    }
    const complet = v.n >= VALO_MOIS;
    return `<div class="db-vdl seul">
      <div class="hd"><b>${fE(v.valeur)}</b> — ${fE(v.moyenne)} de CA mensuel moyen × 12 ÷ ${VALO_DIV}, soit deux mois de chiffre d’affaires.
        ${complet ? 'Sur les 18 derniers mois clos' : 'Sur les ' + v.n + ' mois clos disponibles'}, de ${esc(v.du)} à ${esc(v.au)} —
        ${fE(v.annuel)} de CA annualisé.${complet ? '' : ' La règle en demande 18 : le chiffre se stabilisera avec l’historique.'}</div>
      ${valoTiroir()}
    </div>`;
  }

  function rendre() {
    const kr = cleRes(), ks = cleSt();
    const d = S.res[kr], st = S.st[ks];
    const m = magasin(d);
    let h = '';
    h += `<div class="db-hd"><img src="../assets/img/logo.png" alt=""><div><div class="db-titre">${esc(nomShop())}</div><div class="db-sous">Dashboard magasin · ${esc(libPeriode())}${S.vue === 'jour' && S.date === AUJ ? ' · en direct, relu toutes les 10 min' : ''}</div></div>
      <span style="flex:1"></span><a class="db-lien" href="../#/resultat">Cockpit › Résultat ›</a></div>`;
    h += `<div class="db-nav">
      <div class="db-ong">${[['jour', 'Jour'], ['semaine', 'Semaine'], ['mois', 'Mois'], ['trimestre', 'Trimestre'], ['annee', 'Année']].map(o => `<button data-vue="${o[0]}" class="${S.vue === o[0] ? 'on' : ''}">${o[1]}</button>`).join('')}</div>
      <span class="db-lab">${S.vue === 'jour' ? 'Date' : (S.vue === 'semaine' ? 'Semaine du' : (S.vue === 'mois' ? 'Mois de' : (S.vue === 'trimestre' ? 'Trimestre de' : 'Année de')))}</span>${S.vue === 'trimestre' ? `<div class="db-ong">${[1, 2, 3, 4].map(q => { const deb = annee() + '-' + String((q - 1) * 3 + 1).padStart(2, '0') + '-01'; const auj = q === Math.floor((+AUJ.slice(5, 7) - 1) / 3) + 1 && annee() === +AUJ.slice(0, 4); return `<button data-trim="${q}" class="${trimestre() === q ? 'on' : ''}" ${deb > AUJ ? 'disabled' : ''}>T${q}${auj ? ' · en cours' : ''}</button>`; }).join('')}</div>` : ''}
      <button class="db-btn" data-pas="-1">‹</button><input class="db-sel" type="date" id="db-date" value="${S.date}" max="${AUJ}"><button class="db-btn" data-pas="1">›</button>
      ${S.date !== AUJ ? `<button class="db-btn" data-auj="1">Aujourd’hui</button>` : ''}
      <span style="flex:1"></span>${valoPastille()}<button class="db-btn" data-recharger="1">↻ Relire</button></div>`;
    h += rendValeur();
    if (S.vue === 'annee') { h += rendAnnee(); $.innerHTML = h; brancher(); return; }
    if (S.vue === 'trimestre') { h += rendTrimestre(); $.innerHTML = h; brancher(); return; }
    h += rendNC();
    if (S.err[kr]) { h += `<div class="db-err">Résultat : ${esc(S.err[kr])}</div>`; }
    // Le bandeau : la place du magasin dans le réseau, sans nommer les autres.
    if (m) { h += rendBench(m, d); }
    h += rendTaches();
    h += `<div class="db-sec">Résultat — ${S.vue === 'jour' ? 'la journée' : (S.vue === 'semaine' ? 'la semaine' : 'le mois')}<small>${S.vue === 'jour' ? 'budget du jour, référence des mêmes jours, P&amp;L court' : 'objectif réparti par la pondération réseau, attendu à ce jour, P&amp;L'}</small></div>`;
    if (!d && !S.err[kr]) { h += squelette(3); }
    else if (d && !m) { h += `<div class="db-alerte">Ce magasin n’est pas dans la réponse de Résultat pour cette période.</div>`; }
    else if (m) { h += S.vue === 'jour' ? rendJour(m, d, st) : rendPeriode(m, d); }
    const autreJ = S.vue === 'jour' && S.jourH && S.jourH !== S.date;
    h += `<div class="db-sec">Les heures — ${S.vue === 'jour' ? esc(fDL(dateH())) : 'ventes, matière, rémunération, marge nette'}<small>${S.vue === 'jour' ? 'heure par heure · cliquer un jour dans « le jour dans le mois » pour le lire' : 'moyenne par jour ouvert de la période, ou total'}</small>${autreJ ? `<button class="db-btn" data-jh="">↩ revenir au ${esc(fD(S.date))}</button>` : ''}</div>`;
    if (S.err[ks]) { h += `<div class="db-err">Heures : ${esc(S.err[ks])}${(S.relances[ks] || 0) < 3 ? " — nouvelle lecture dans quelques secondes" : ""}</div>`; }
    if (st && st.produits && st.produits.aSuivre) { h += `<div class="db-alerte">Tickets lus sur ${st.produits.jours.length} jour(s) sur ${st.produits.total} — la lecture continue, la page se complète toute seule.</div>`; }
    if (!st && !S.err[ks]) { h += squelette(4); }
    else if (st) { h += rendHeures(st); }
    $.innerHTML = h;
    brancher();
  }
  function squelette(n) {
    return `<div class="db-tuiles">${Array.from({ length: 5 }, () => `<div class="db-tui"><div class="db-sk" style="width:60%"></div><div class="db-sk" style="height:24px;margin:8px 0 6px"></div><div class="db-sk" style="width:80%"></div></div>`).join('')}</div>
      <div class="db-card"><div class="ct"><div class="db-sk" style="width:220px"></div></div><div style="padding:14px 16px">${Array.from({ length: n }, () => `<div class="db-sk" style="margin-bottom:10px"></div>`).join('')}</div></div>`;
  }
  const fSE = v => v == null ? '—' : (v < 0 ? '− ' + fE(-v) : fE(v));
  function tuile(k, v, s, cls) { return `<div class="db-tui ${cls || ''}"><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s || ''}</div></div>`; }
  /** La tuile avec sa tendance : l'écart avec le dernier même jour de semaine. */
  function tuileTend(k, v, s, cls, serie, auj, fmt, inverse) {
    const pts = serie.filter(x => x != null);
    if (!pts.length || auj == null) { return tuile(k, v, s, cls); }
    const all = pts.concat([auj]), mn = Math.min(...all), mx = Math.max(...all), n = all.length;
    const xy = all.map((x, i) => [(i * 70 / Math.max(1, n - 1)), 24 - 22 * (x - mn) / ((mx - mn) || 1) + 1]);
    const dern = pts[pts.length - 1], d = dern ? 100 * (auj - dern) / dern : null;
    const sens = d == null ? 'eq' : (Math.abs(d) < 1 ? 'eq' : ((d > 0) !== !!inverse ? 'up' : 'dn'));
    const last = xy[n - 1];
    return `<div class="db-tui ${cls || ''}"><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s || ''}</div>${d == null ? '' : `<span class="db-dl ${sens}" title="dernier même jour : ${fmt(dern)}">${d >= 0 ? '+ ' : '− '}${fP(Math.abs(d))} vs ${esc(fD(S.aux['tend|' + S.shop + '|' + S.date].jours.slice(-1)[0].date))}</span>`}</div>`;
  }
  function cascade(m, d) {
    const se = (d && d.seuils) || {};
    const ca = m.ca != null ? m.ca : m.realise;
    const feu = (v, s) => v == null || s == null ? 'var(--color-text-muted)' : (v <= s ? '#2d7a3e' : (v <= s * 1.3 ? '#D97706' : '#C0182B'));
    const feuRes = p => p == null ? 'var(--color-text-muted)' : (p >= 15 ? '#2d7a3e' : (p >= 5 ? '#D97706' : '#C0182B'));
    const mbPct = m.margeBrutePct != null ? m.margeBrutePct : (ca ? 100 * m.margeBrute / ca : null);
    const barre = p => Math.min(Math.abs(p || 0), 100).toFixed(1);
    const ligne = (lib, sous, v, pct, coul, w, fort, note) => `<div class="db-cl${fort ? ' fort' : ''}"><span><b>${lib}</b>${sous ? `<br><span class="mu">${sous}</span>` : ''}</span><span class="b"><i style="width:${w}%;background:${coul}"></i></span><span class="v">${v}</span><span class="p" style="color:${coul}">${pct}</span><span class="n mu">${note || ''}</span></div>`;
    const fr = m.planningHeuresFranchise ? 'hors ' + nf(m.planningHeuresFranchise, 1) + ' h de franchisé (' + esc((m.planningFranchiseNoms || []).join(', ')) + ')' : '';
    return `<div class="db-cascade">
      ${ligne('Chiffre d’affaires', m.tickets != null ? fN(m.tickets) + ' clients · ' + fU(m.panier) : '', fE(ca), '100 %', 'var(--color-text)', 100, true, '')}
      ${ligne('− Coût matière', se.food != null ? 'seuil ' + fP(se.food) : 'coût des recettes vendues', fE(-m.coutMatiere), fP(m.coutMatierePct), feu(m.coutMatierePct, se.food), barre(m.coutMatierePct), false)}
      ${ligne('= Marge brute', '', fE(m.margeBrute), fP(mbPct), 'var(--color-text)', barre(mbPct), true)}
      ${ligne('− Main-d’œuvre', (se.labour != null ? 'seuil ' + fP(se.labour) : '') + (m.labourSource ? ' · ' + esc(m.labourSource) : ''), m.labour == null ? '—' : fE(-m.labour), fP(m.labourPct), feu(m.labourPct, se.labour), barre(m.labourPct), false, fr)}
      ${ligne('− Frais généraux', (se.overhead != null ? 'seuil ' + fP(se.overhead) : '') + (m.overheadSource ? ' · ' + esc(m.overheadSource) : ''), m.overhead == null ? '—' : fE(-m.overhead), fP(m.overheadPct), feu(m.overheadPct, se.overhead), barre(m.overheadPct), false)}
      ${ligne('= Résultat', m.net == null ? esc(m.motifNet || 'non calculable') : '', m.net == null ? '—' : fS(m.net), fP(m.netPct), feuRes(m.netPct), barre(m.netPct), true, m.net != null && m.motifNet ? esc(m.motifNet) : '')}
    </div>`;
  }
  function cascadeVieille(m) {
    const ov = m.overhead == null ? null : m.overhead;
    return `<div class="db-casc">
      <div><div class="k">Chiffre d’affaires</div><div class="v">${fK(m.ca != null ? m.ca : m.realise)}</div><div class="s">${m.tickets != null ? fN(m.tickets) + ' clients · ' + fU(m.panier) : ''}</div></div>
      <div><div class="k">− Coût matière</div><div class="v">${fK(m.coutMatiere)}</div><div class="s">${fP(m.coutMatierePct)} · food cost</div></div>
      <div><div class="k">= Marge brute</div><div class="v">${fK(m.margeBrute)}</div><div class="s">${fP(m.margeBrutePct != null ? m.margeBrutePct : (m.ca ? 100 * m.margeBrute / m.ca : null))}</div></div>
      <div><div class="k">− Personnel</div><div class="v">${fK(m.labour)}</div><div class="s">${fP(m.labourPct)}${m.labourSource ? ' · ' + esc(m.labourSource) : ''}</div></div>
      <div><div class="k">− Frais généraux</div><div class="v">${fK(ov)}</div><div class="s">${fP(m.overheadPct)}${m.overheadSource ? ' · ' + esc(m.overheadSource) : ''}</div></div>
      <div><div class="k">= Résultat net</div><div class="v ${coul(m.net)}">${m.net == null ? '—' : fSK(m.net)}</div><div class="s">${m.net == null ? esc(m.motifNet || 'non calculable') : fP(m.netPct) + ' des ventes'}${m.net != null && m.motifNet ? ' · ' + esc(m.motifNet) : ''}</div></div>
    </div>`;
  }

  /* Résultat › Jour, déplié pour le magasin. */
  function rendJour(m, d, st) {
    const TT = S.aux['tend|' + S.shop + '|' + S.date], TJ = TT && Array.isArray(TT.jours) ? TT.jours : [];
    const ref = d.reference || {};
    const att = m.objectifJour ? Math.min(100, 100 * m.ca / m.objectifJour) : 0;
    let h = `<div class="db-tuiles">
      ${tuileTend('CA du jour', fK(m.ca), m.objectifJour ? 'objectif ' + fK(m.objectifJour) + ' · ' + fP(100 * (m.objectifAtteinte || 0)) + ' atteint' + (m.panier > 0 && m.objectifJour - m.ca > 0 ? ' · <b class="ko">−' + fN((m.objectifJour - m.ca) / m.panier) + ' clients</b> (' + fE(m.objectifJour - m.ca) + ' ÷ ' + fU(m.panier) + ')' : (m.objectifJour && m.panier > 0 ? ' · <b class="ok">+' + fN((m.ca - m.objectifJour) / m.panier) + ' clients</b> d’avance' : '')) : 'pas d’objectif du jour', '', TJ.map(j => j.ca), m.ca, fK)}
      ${tuileTend('Marge brute', fK(m.margeBrute), fP(m.margeBrutePct != null ? m.margeBrutePct : (m.ca ? 100 * m.margeBrute / m.ca : null)) + ' des ventes · matière ' + fK(m.coutMatiere), '', TJ.map(j => j.mb), m.margeBrute, fK)}
      ${tuileTend('Clients', fN(m.tickets), 'référence ' + fN(m.refTickets) + (m.ticketsDelta != null ? ' · ' + (m.ticketsDelta >= 0 ? '+ ' : '− ') + fP(Math.abs(m.ticketsDelta)) : '') + (m.produits ? ' · ' + fN(m.produits) + ' produits vendus' : ''), '', TJ.map(j => j.tickets), m.tickets, fN)}
      ${tuileTend('Panier moyen', fU(m.panier), (d.reseau && d.reseau.panier ? 'réseau ' + fU(d.reseau.panier) + ' · ' : '') + (m.produitsParClient ? nf(m.produitsParClient, 2) + ' produits / client' : ''), '', TJ.map(j => j.panier), m.panier, fU)}
      ${tuile('Projection fin de journée', m.projection != null ? fK(m.projection) : '—', m.projection != null ? (m.projectionPart != null ? fP(m.projectionPart) + ' de la journée écoulée' : '') + (m.projectionRythme ? ' · au rythme : ' + fK(m.projectionRythme) : '') : esc(m.projectionMotif || ''))}
      ${tuile('Résultat net du jour', m.net == null ? '—' : fSK(m.net), m.net == null ? esc(m.motifNet || '') : fP(m.netPct) + ' des ventes', m.net == null ? '' : (m.net >= 0 ? 'bon' : 'vif'))}
    </div>`;
    if (m.objectifJour) {
      // Objectif atteint : la ligne passe en or, badge trophée et pluie de
      // confettis (V1). Record de jour du magasin : bandeau plein or, feux
      // d'artifice et le record en gros à droite (V3).
      const or = m.ca >= m.objectifJour, attReel = 100 * m.ca / m.objectifJour;
      const R = S.aux['record|' + S.shop + '|' + S.date];
      const rec = R && R.jours > 0 && R.meilleur && m.ca > R.meilleur.ca;
      const nomJ = R && R.nom ? R.nom : (m.objectifJourNom || 'jour');
      const lab = `Objectif du jour — ${fK(m.objectifJour)}${m.objectifJourNom ? ' · profil des ' + esc(m.objectifJourNom) + 's' : ''}`;
      const bar = `<div class="db-bar"><i style="width:${att.toFixed(1)}%"></i>${m.projectionPart != null ? `<b style="left:${Math.min(100, m.projectionPart).toFixed(1)}%"></b>` : ''}</div>`;
      const repere = m.projectionPart != null ? ' · le repère noir est la part de journée normalement écoulée (' + fP(m.projectionPart) + ')' : '';
      if (rec) {
        h += `<div class="db-card db-or db-rec"><div class="db-conf">${confettis(80)}</div><canvas class="db-feux"></canvas>
          <div class="big">🏆 <span>${fK(m.ca)}<br><small>record des ${esc(nomJ)}s<br>précédent ${fK(R.meilleur.ca)} le ${fD(R.meilleur.date)}</small></span></div>
          <div class="in"><div class="db-lab">${lab}${or ? `<span class="db-badge-or"><span>🎆</span>Objectif atteint</span>` : ''}</div>${bar}
          <div class="db-mini" style="margin-top:5px"><b>${fP(attReel)} réalisé</b>${or ? ' · dépassé de ' + fK(m.ca - m.objectifJour) : ''} · meilleur ${esc(nomJ)} du magasin${R.depuis ? ' depuis le ' + fD(R.depuis) : ''} (${fN(R.jours)} jours de ventes relus)${repere}</div></div></div>`;
      } else {
        h += `<div class="db-card${or ? ' db-or' : ''}">${or ? '<div class="db-conf">' + confettis(60) + '</div>' : ''}<div class="in"><div class="db-lab">${lab}${or ? `<span class="db-badge-or"><span>🏆</span>Objectif atteint · ${fK(m.ca)}</span>` : ''}</div>${bar}
          <div class="db-mini" style="margin-top:5px">${or ? '<b>' + fP(attReel) + ' réalisé</b> · dépassé de ' + fK(m.ca - m.objectifJour) : fP(att) + ' réalisé'}${R && R.meilleur ? ' · record des ' + esc(nomJ) + 's : ' + fK(R.meilleur.ca) + ' le ' + fD(R.meilleur.date) : ''}${repere}</div></div></div>`;
      }
    }
    h += `<div class="db-card"><div class="ct"><span class="db-lab">Le P&amp;L court de la journée</span><span class="db-mini">matière : coût des recettes vendues · personnel : ${esc(m.planningSource || 'planning')} · frais généraux : ${esc(m.overheadSource || '—')}${m.overheadSource === 'reparti' ? ' — allocation du panel, le mois ÷ ses jours' : ''}</span></div>${cascade(m, d)}</div>`;
    // Catégories et planning côte à côte.
    const cats = Array.isArray(m.categories) ? m.categories : [];
    const plan = Array.isArray(m.planning) ? m.planning : [];
    // Les catégories lues dans les tickets portent la marge brute (CA − coût matière) :
    // c'est elle qui colore le treemap. Sans tickets lus, repli sur l'écart à la référence du panel.
    const catsM = st && Array.isArray(st.categories) ? st.categories.filter(c => c.v > 0).map(c => ({ categorie: c.nom, groupe: c.groupe, ca: c.v, part: c.part != null ? c.part / 100 : null, mat: c.c, m: c.m, taux: c.taux, refs: c.refs })) : [];
    const parMarge = catsM.length > 0;
    const lc = parMarge ? MARGES : ECARTS;
    // Les tickets ne sont pas encore lus : le squelette, plutôt qu'un premier
    // dessin par l'écart à la référence qui basculerait sur la marge vingt
    // secondes plus tard — une carte qui change sous les yeux se relit en entier.
    const catsAttend = !st && !S.err[cleSt()];
    h += catsAttend
      ? `<div class="db-card"><div class="ct"><span class="db-lab">Ventes par catégorie</span><span class="db-mini">lecture des tickets en cours…</span></div><div class="db-tm"><div class="db-sk" style="height:100%"></div></div></div>`
      : `<div class="db-card"><div class="ct"><span class="db-lab">Ventes par catégorie</span><span class="db-mini">surface : poids dans le CA · couleur : ${parMarge ? 'marge brute, CA − coût matière' : 'écart à la référence'}</span></div>
      ${(parMarge ? catsM : cats).length ? `<div class="db-tm">${treemap(parMarge ? catsM : cats)}</div><div class="db-leg">${lc.map(e => `<span><i class="${e.c === 'or' ? 'or' : ''}" style="${e.c === 'or' ? '' : 'background:' + e.c}"></i>${e.l}</span>`).join('')}<span><i style="background:#B9B2A8"></i>${parMarge ? 'coût matière inconnu' : 'sans référence'}</span></div>${parMarge ? `<div class="db-cdr" data-cdrop="1">${S.cOuvert ? 'replier le détail ▴' : 'le détail par famille et catégorie ▾'}</div>${S.cOuvert ? tableauFamilles(catsM) : ''}` : ''}` : `<div class="db-note" style="padding-top:12px">Pas de ventilation par catégorie pour ce jour.</div>`}</div>`;
    const hMin = plan.length ? Math.floor(Math.min(...plan.map(p => hDe(p.debut)))) : 6, hMax = plan.length ? Math.ceil(Math.max(...plan.map(p => hDe(p.fin)))) : 19;
    // Qui est en poste : replié, la frise effectif / budget de l'heure ; déplié, le planning par personne.
    const seuilLab = (d.seuils && d.seuils.labour) || 33;
    const LH2 = st && Array.isArray(st.heures) ? st.heures.filter(x => x.ca > 0 || x.poste > 0) : [];
    const budgetJour = m.ca ? m.ca * seuilLab / 100 : null;
    const hRouges = LH2.filter(x => x.trav > x.ca * seuilLab / 100).map(x => x.h + ' h');
    const caParH = m.planningHeures ? m.ca / m.planningHeures : null;
    const frise = (!st && !S.err[cleSt()]) ? `<div style="padding:12px 16px 14px">${Array.from({ length: 3 }, () => '<div class="db-sk" style="margin-bottom:8px"></div>').join('')}</div>` : LH2.length ? `<div class="db-fz"><div class="row"><div class="n">Heure</div><div class="hs" style="grid-template-columns:repeat(${LH2.length},1fr)">${LH2.map(x => `<span class="lb">${x.h} h</span>`).join('')}</div></div>
      <div class="row"><div class="n">En poste</div><div class="hs" style="grid-template-columns:repeat(${LH2.length},1fr)">${LH2.map(x => `<i class="p${Math.min(4, Math.max(0, Math.round(x.poste)))}" title="${x.h} – ${x.h + 1} h · ${nf(x.poste, 1)} en poste · ${fE(x.trav)} de rémunération">${nf(x.poste, x.poste % 1 ? 1 : 0)}</i>`).join('')}</div></div>
      <div class="row"><div class="n">Personnel / CA</div><div class="hs" style="grid-template-columns:repeat(${LH2.length},1fr)">${LH2.map(x => { const pct = x.ca ? 100 * x.trav / x.ca : null, ok = pct != null && pct <= seuilLab, vide = pct == null; return `<i class="c ${vide ? 'vide' : (ok ? 'ok' : 'ko')}" title="${x.h} – ${x.h + 1} h · rémunération ${fE(x.trav)} pour ${fE(x.ca)} de ventes · seuil ${seuilLab} %">${vide ? (x.trav ? fE(x.trav) + ' / 0' : '—') : fP(pct)}</i>`; }).join('')}</div></div>
      <div class="sum"><span><b>${plan.length}</b> personne${plan.length > 1 ? 's' : ''} · <b>${m.planningHeures != null ? nf(m.planningHeures, 1) + ' h' : '—'}</b>${m.planningHeuresFranchise ? ' dont ' + nf(m.planningHeuresFranchise, 1) + ' h franchisé' : ''}</span><span>coût <b>${fE(m.planningCout)}</b>${m.labourPct != null ? ' · <b>' + fP(m.labourPct) + '</b> du CA (seuil ' + seuilLab + ' %)' : ''}</span>${budgetJour != null ? `<span>budget du jour <b>${fE(budgetJour)}</b> · reste ${fSE(budgetJour - (m.planningCout || 0))}</span>` : ''}${caParH != null ? `<span><b>${fE(caParH)}</b> de CA / h travaillée</span>` : ''}<span>${hRouges.length ? 'heures au-dessus du seuil : <b>' + hRouges.join(', ') + '</b>' : 'aucune heure au-dessus du seuil'}</span></div></div>` : `<div class="db-note" style="padding:10px 16px 4px">${st ? 'Pas d’heure vendue pour ce jour.' : 'Lecture des heures en cours…'}</div>`;
    h += `<div class="db-card"><div class="ct"><span class="db-lab">Qui est en poste</span><span class="db-mini">planning du panel · effectif en poste et coût du personnel en % des ventes de l’heure (seuil ${seuilLab} %)${m.planningHeuresZero ? ' · ' + nf(m.planningHeuresZero, 1) + ' h à 0 €/h (' + esc((m.planningZeroNoms || []).join(', ')) + ')' : ''}</span></div>
      ${frise}
      <div class="db-cdr" data-pdrop="1">${S.pOuvert ? 'replier le planning ▴' : 'voir le planning ▾'}</div>
      ${S.pOuvert ? planningSecteurs(plan, hMin, hMax) : ''}</div>`;
    const serie = Array.isArray(m.serie) ? m.serie.filter(x => x.ouvert) : [];
    if (serie.length) {
      // Le jour dans le mois : la marge nette en % des ventes, une barre par
      // jour sur des bandes de paliers (noir < 0, rouge, orange, ambre, vert,
      // vert clair, or ≥ 35 %). Pas de chiffre sur les barres : le détail est
      // dans l'info-bulle, l'échelle en légende.
      const cumNet = serie.reduce((a, x) => a + (x.net || 0), 0), cumCa = serie.reduce((a, x) => a + (x.ca || 0), 0);
      const lo = -10, hi = Math.max(50, Math.ceil(Math.max(...serie.map(x => x.netPct || 0)) / 5) * 5 + 5);
      const y = v => 100 * (Math.min(hi, Math.max(lo, v)) - lo) / (hi - lo);
      const bornes = [lo, 0, 10, 20, 40, hi];
      const bandes = bornes.slice(0, -1).map((bo, i) => { const p = palier(bo); const o = p.c === 'or'; return `<div style="flex:0 0 ${(100 * (bornes[i + 1] - bo) / (hi - lo)).toFixed(2)}%;background:${o ? '#E2B93B' : p.c}22"></div>`; }).join('');
      const y0 = y(0);
      h += `<div class="db-card"><div class="ct"><span class="db-lab">Le jour dans le mois</span><span class="db-mini">marge nette en % des ventes, un jour = une barre, du noir (perte) à l’or (≥ 40 %) — <b>${fE(cumNet)}</b> cumulés sur ${fE(cumCa)} de ventes · main-d’œuvre et frais généraux répartis</span></div>
        <div class="db-jm"><div class="bandes">${bandes}</div><div class="g" style="grid-template-columns:repeat(${serie.length},1fr)">${serie.map(x => {
          const pct = x.netPct == null ? null : x.netPct, p = palier(pct == null ? 0 : pct), o = p.c === 'or', neg = pct != null && pct < 0, yy = y(pct == null ? 0 : pct);
          return `<div class="jh${x.date === dateH() ? ' sel' : ''}" data-jh="${esc(x.date)}" title="${esc(x.date)} · CA ${fE(x.ca)} · net ${fE(x.net)}${pct == null ? '' : ' (' + fP(pct) + ')'} · cliquer pour lire ses heures"><div class="bb">${pct == null ? '' : `<i class="${o ? 'o' : ''}${neg ? ' neg' : ''}" style="${neg ? `top:${(100 - y0).toFixed(2)}%;height:${(y0 - yy).toFixed(2)}%` : `bottom:${y0.toFixed(2)}%;height:${(yy - y0).toFixed(2)}%`};${o ? '' : 'background:' + p.c};${x.date === dateH() ? 'outline:2px solid #222;outline-offset:1px' : ''}"></i>`}</div><span>${fD(x.date)}</span></div>`; }).join('')}</div></div>
        <div class="db-leg">${PALIERS.map(p => `<span><i class="${p.c === 'or' ? 'or' : ''}" style="${p.c === 'or' ? '' : 'background:' + p.c}"></i>${p.l}</span>`).join('')}<span style="margin-left:auto"><i style="background:#fff;outline:2px solid #222;outline-offset:-1px"></i>jour lu dans « les heures »</span></div></div>`;
    }
    return h;
  }

  /* Treemap « squarified » des catégories : surface = CA, couleur = écart à la référence. */
  /** L'échelle commune, 5 sections du noir à l'or, de 0 à +40 % : marge nette en % des ventes (le jour dans le mois) et écart à la référence (catégories). */
  const PALIERS = [{ s: -Infinity, c: '#222', l: '< 0 %' }, { s: 0, c: '#C0182B', l: '0 – 10 %' }, { s: 10, c: '#F08A2C', l: '10 – 20 %' }, { s: 20, c: '#2d7a3e', l: '20 – 40 %' }, { s: 40, c: 'or', l: '≥ 40 % or' }];
  const ECARTS = PALIERS;
  /** La marge brute d'une catégorie (CA − coût matière, en % du CA) : 5 sections, l'or au-delà de 75 %. Une bonne marge commence à 60 %. */
  const MARGES = [{ s: -Infinity, c: '#222', l: '< 40 %' }, { s: 40, c: '#C0182B', l: '40 – 50 %' }, { s: 50, c: '#F08A2C', l: '50 – 60 %' }, { s: 60, c: '#2d7a3e', l: '60 – 70 %' }, { s: 70, c: 'or', l: '≥ 70 % or' }];
  function palier(pct) { let r = PALIERS[0]; for (const p of PALIERS) { if (pct >= p.s) { r = p; } } return r; }

  /** Confettis en CSS : n rectangles colorés qui tombent en boucle, positions stables d'un rendu à l'autre. */
  function confettis(n) {
    const cols = ['#e2b93b', '#8D1D2C', '#2d7a3e', '#1f4e8c', '#f7e7a1', '#D97706', '#fff'];
    let out = '', g = 7;
    for (let i = 0; i < n; i++) {
      g = (g * 16807) % 2147483647; const a = g / 2147483647;
      g = (g * 16807) % 2147483647; const b = g / 2147483647;
      g = (g * 16807) % 2147483647; const c = g / 2147483647;
      out += `<s style="left:${(a * 100).toFixed(1)}%;background:${cols[i % cols.length]};animation-duration:${(3 + b * 3).toFixed(2)}s;animation-delay:${(-c * 6).toFixed(2)}s;width:${(5 + a * 4).toFixed(0)}px;height:${(8 + b * 6).toFixed(0)}px"></s>`;
    }
    return out;
  }

  function treemap(cats) {
    const vals = cats.filter(c => (c.ca || 0) > 0).sort((a, b) => b.ca - a.ca);
    if (!vals.length) { return ''; }
    const W = 1000, H = 440;
    const tot = vals.reduce((t, c) => t + c.ca, 0), ech = (W * H) / tot;
    const items = vals.map(c => ({ c, a: c.ca * ech }));
    const out = []; let x = 0, y = 0, w = W, h = H, row = [];
    const pire = (rw, l) => { const sm = rw.reduce((t, r) => t + r.a, 0); if (sm <= 0 || l <= 0) { return Infinity; } const mx = Math.max(...rw.map(r => r.a)), mn = Math.min(...rw.map(r => r.a)); return Math.max((l * l * mx) / (sm * sm), (sm * sm) / (l * l * mn)); };
    const poser = (rw, horiz) => { const sm = rw.reduce((t, r) => t + r.a, 0); if (sm <= 0) { return; }
      if (horiz) { const rh = sm / w; let cx = x; rw.forEach(r => { const rl = r.a / rh; out.push({ c: r.c, x: cx, y, w: rl, h: rh }); cx += rl; }); y += rh; h -= rh; }
      else { const rl = sm / h; let cy = y; rw.forEach(r => { const rh = r.a / rl; out.push({ c: r.c, x, y: cy, w: rl, h: rh }); cy += rh; }); x += rl; w -= rl; } };
    let garde = 0;
    while (items.length && garde++ < 400) { const horiz = w <= h, l = horiz ? w : h, it = items[0]; if (!row.length || pire(row, l) >= pire(row.concat([it]), l)) { row.push(items.shift()); } else { poser(row, horiz); row = []; } }
    if (row.length) { poser(row, w <= h); }
    const coulE = (v, ech) => { if (v == null) { return '#B9B2A8'; } let r = ech[0]; for (const e of ech) { if (v >= e.s) { r = e; } } return r.c === 'or' ? '#E2B93B' : r.c; };
    const coulD = c => 'taux' in c ? coulE(c.taux, MARGES) : coulE(c.delta, ECARTS);
    const CLAIRS = ['#F2D34B', '#7CC26A', '#E2B93B', '#F08A2C'];
    const detail = (c, court) => 'taux' in c
      ? (c.taux == null ? (court ? 'matière ?' : 'coût matière inconnu') : (court ? 'marge ' + Math.round(c.taux) + ' %' : 'marge ' + fP(c.taux)))
      : (c.delta != null ? (c.delta >= 0 ? '+' : '') + (court ? Math.round(c.delta) + ' %' : fP(c.delta) + ' vs réf.') : (court ? '' : 'sans référence'));
    return out.map(t => { const c = t.c; const gros = t.w > 150 && t.h > 90, moyen = t.w > 90 && t.h > 40;
      return `<div title="${esc(c.categorie)} · ${fE(c.ca)} · ${c.part != null ? fP(100 * c.part) + ' du CA' : ''}${'taux' in c ? (c.mat != null ? ' · matière ' + fE(c.mat) + ' · marge ' + fE(c.m) + ' (' + fP(c.taux) + ')' : ' · coût matière inconnu') + (c.refs ? ' · ' + c.refs + ' réf.' : '') : (c.delta != null ? ' · ' + (c.delta >= 0 ? '+' : '') + fP(c.delta) + ' vs réf. ' + fE(c.ref) : ' · sans référence')}" style="position:absolute;left:${(t.x / W * 100).toFixed(3)}%;top:${(t.y / H * 100).toFixed(3)}%;width:${Math.max(t.w / W * 100 - 0.35, 0).toFixed(3)}%;height:${Math.max(t.h / H * 100 - 0.8, 0).toFixed(3)}%;background:${coulD(c)};color:${CLAIRS.includes(coulD(c)) ? '#222' : '#fff'};border-radius:5px;padding:${gros ? '8px 10px' : '4px 6px'};overflow:hidden;font-size:${gros ? 12 : 10.5}px;line-height:1.3">${moyen ? `<b>${esc(c.categorie)}</b>${gros ? `<br><span style="font-family:var(--font-display);font-size:16px">${fE(c.ca)}</span><br><span style="opacity:.95;font-size:10.5px;font-weight:300">${c.part != null ? fP(100 * c.part) + ' du CA' : ''}${detail(c, false) ? ' · ' + detail(c, false) : ''}</span>` : `<br><span style="font-size:10px;opacity:.95;font-weight:300">${c.part != null ? Math.round(100 * c.part) + ' %' : ''}${detail(c, true) ? ' · ' + detail(c, true) : ''}</span>`}` : ''}</div>`; }).join('');
  }
  /** Le tiroir du treemap : famille › catégorie, carré de la couleur de la marge, CA, part, matière, marge, taux ; total en pied. */
  function tableauFamilles(cats) {
    const coulM = t => { if (t == null) { return '#B9B2A8'; } let r = MARGES[0]; for (const e of MARGES) { if (t >= e.s) { r = e; } } return r.c === 'or' ? '#E2B93B' : r.c; };
    const F = {};
    cats.forEach(c => { const g = (c.groupe || 'Autres').split(' · ')[0]; const f = F[g] || (F[g] = { nom: g, ca: 0, mat: 0, m: 0, caConnu: 0, inconnu: false, cats: [] }); f.ca += c.ca; f.cats.push(c); if (c.mat == null) { f.inconnu = true; } else { f.mat += c.mat; f.m += c.m; f.caConnu += c.ca; } });
    const fams = Object.values(F).sort((a, b) => b.ca - a.ca);
    const tot = { ca: 0, mat: 0, m: 0 }; cats.forEach(c => { tot.ca += c.ca; if (c.mat != null) { tot.mat += c.mat; tot.m += c.m; } });
    let etoile = false;
    const tri = S.cTri, plat = tri !== 'famille';
    const tog = `<div class="db-ong db-ctri"><button data-ctri="famille" class="${tri === 'famille' ? 'on' : ''}">Par famille et catégorie</button><button data-ctri="marge" class="${tri === 'marge' ? 'on' : ''}">Par marge brute (%)</button><button data-ctri="euros" class="${tri === 'euros' ? 'on' : ''}">Par marge (€)</button></div>`;
    const entete = `<th>CA</th><th>% CA</th><th title="CA − coût matière">Marge (€)</th><th title="(CA − coût matière) ÷ CA">Marge brute (%)</th>`;
    const pied = `<td>${fE(tot.ca)}</td><td>100 %</td><td>${fE(tot.m)}</td><td>${tot.ca > 0 ? fP(100 * tot.m / tot.ca) : '—'}</td>`;
    if (plat) {
      // La liste à plat, de la meilleure à la pire ; les coûts inconnus en queue.
      const cle = tri === 'euros' ? (c => c.m == null ? -Infinity : c.m) : (c => c.taux == null ? -Infinity : c.taux);
      const L = cats.slice().sort((a, b) => cle(b) - cle(a) || b.ca - a.ca);
      const rows2 = L.map((c, i) => `<tr class="sub plat"><td class="l"><span class="rg">${i + 1}</span><i class="sq s" style="background:${coulM(c.taux)}"></i>${esc(c.categorie)}<span class="mu"> · ${esc((c.groupe || 'Autres').split(' · ')[0])}</span></td><td>${fE(c.ca)}</td><td>${c.part != null ? fP(100 * c.part) : '—'}</td><td style="${tri === 'euros' ? 'font-weight:600' : ''}">${c.m == null ? '—' : fSE(c.m)}</td><td style="color:${c.taux == null ? '#999' : coulM(c.taux)};font-weight:600">${c.taux == null ? '—' : fP(c.taux)}</td></tr>`).join('');
      return `<div class="db-cdt">${tog}<table class="db-tf"><tr><th class="l">Catégorie · famille</th>${entete}</tr>${rows2}
        <tr class="tot"><td class="l">Total · ${cats.length} catégories</td>${pied}</tr></table>
        <div class="db-note" style="padding:6px 0 0">Marge brute (%) = (CA − coût matière) ÷ CA ; le coût matière vient de la fiche recette du catalogue. Une marge très négative signale une fiche recette au coût d’une fournée, pas d’une part.</div></div>`;
    }
    const rows = fams.map(f => {
      f.cats.sort((a, b) => b.ca - a.ca);
      const taux = f.caConnu > 0 ? 100 * f.m / f.caConnu : null; if (f.inconnu && taux != null) { etoile = true; }
      return `<tr class="fam"><td class="l"><i class="sq" style="background:${coulM(taux)}"></i>${esc(f.nom)}<span class="mu"> · ${f.cats.length} catégorie${f.cats.length > 1 ? 's' : ''}</span></td><td>${fE(f.ca)}</td><td>${fP(100 * f.ca / tot.ca)}</td><td>${f.caConnu > 0 ? fSE(f.m) : '—'}</td><td style="color:${taux == null ? '#999' : coulM(taux)}">${taux == null ? '—' : fP(taux)}${f.inconnu && taux != null ? ' *' : ''}</td></tr>`
        + f.cats.map(c => `<tr class="sub"><td class="l"><i class="sq s" style="background:${coulM(c.taux)}"></i>${esc(c.categorie)}</td><td>${fE(c.ca)}</td><td>${c.part != null ? fP(100 * c.part) : '—'}</td><td>${c.m == null ? '—' : fSE(c.m)}</td><td style="color:${c.taux == null ? '#999' : coulM(c.taux)};font-weight:600">${c.taux == null ? '—' : fP(c.taux)}</td></tr>`).join('');
    }).join('');
    return `<div class="db-cdt">${tog}<table class="db-tf"><tr><th class="l">Famille › catégorie</th>${entete}</tr>${rows}
      <tr class="tot"><td class="l">Total · ${fams.length} famille${fams.length > 1 ? 's' : ''} · ${cats.length} catégories</td>${pied}</tr></table>
      <div class="db-note" style="padding:6px 0 0">Marge brute (%) = (CA − coût matière) ÷ CA ; le coût matière vient de la fiche recette du catalogue. ${etoile ? '* taux calculé sur les catégories dont le coût matière est connu. ' : ''}Une marge très négative signale une fiche recette au coût d’une fournée, pas d’une part.</div></div>`;
  }
  /** Le planning déplié, groupé par secteur : le premier poste de travail de la personne dans le panel ; « sans secteur » sinon. */
  function planningSecteurs(plan, hMin, hMax) {
    if (!plan.length) { return '<div class="db-note" style="padding:8px 16px 12px">Pas de planning lu pour ce jour.</div>'; }
    const COUL = { 'vente': '#1f4e8c', 'production patisserie': '#8D1D2C', 'production pâtisserie': '#8D1D2C', 'production boulangerie': '#b8860b', 'production traiteur': '#2d7a3e', 'nettoyage': '#7a7a7a' };
    const coulS = n => COUL[String(n || '').toLowerCase()] || (n ? '#5f5a55' : '#B9B2A8');
    const G = {};
    plan.forEach(p => { const sec = (p.postes && p.postes[0]) || ''; (G[sec] = G[sec] || { nom: sec, h: 0, cout: 0, pers: [] }); G[sec].h += p.h || 0; G[sec].cout += p.cout || 0; G[sec].pers.push(p); });
    const secteurs = Object.values(G).sort((a, b) => (a.nom === '') - (b.nom === '') || b.h - a.h);
    const axe = `<div class="db-axe2"><div></div><div>${(function () { const o = []; for (let h = Math.ceil(hMin); h <= hMax; h += 2) { o.push(`<span style="left:${(100 * (h - hMin) / (hMax - hMin)).toFixed(1)}%">${h} h</span>`); } return o.join(''); })()}</div><div></div></div>`;
    return `<div style="padding:4px 0 12px">${axe}${secteurs.map(g => `<div class="db-sect"><div><i class="sq" style="background:${coulS(g.nom)}"></i>${g.nom ? esc(g.nom) : 'Sans secteur'}<span class="mu"> · ${g.pers.length} personne${g.pers.length > 1 ? 's' : ''}${g.pers.some(p => (p.postes || []).length > 1) ? ' · polyvalent' + (g.pers.filter(p => (p.postes || []).length > 1).length > 1 ? 'es' : 'e') : ''}${g.nom ? '' : ' · poste à renseigner dans le panel'}</span></div><div></div><div class="r">${nf(g.h, 1)} h · ${fE(g.cout)}</div></div>`
      + `<div class="db-plans">` + g.pers.map(p => `<div class="db-plan sec" title="${(p.postes || []).length ? 'postes : ' + esc(p.postes.join(', ')) : 'aucun poste dans le panel'}"><span><b>${esc(p.nom)}</b><br><span class="mu">${esc(p.debut)} – ${esc(p.fin)} · ${nf(p.h, 1)} h${p.franchise ? ' · franchisé' : ''}${(p.postes || []).length > 1 ? ' · polyvalent' : ''}</span></span><span class="g"><i style="left:${(100 * (hDe(p.debut) - hMin) / (hMax - hMin)).toFixed(1)}%;width:${(100 * (hDe(p.fin) - hDe(p.debut)) / (hMax - hMin)).toFixed(1)}%;background:${p.franchise ? '#D97706' : coulS(g.nom)}"></i></span><span style="text-align:right"><b>${fE(p.cout)}</b><br><span class="mu">${p.caH != null ? fE(p.caH) + '/h vendu' : ''}</span></span></div>`).join('') + `</div>`).join('')}</div>`;
  }
  function hDe(t) { const p = String(t || '0:0').split(':'); return (+p[0] || 0) + (+p[1] || 0) / 60; }

  /* Résultat › Semaine, Mois — le magasin déplié. */
  function rendPeriode(m, d) {
    const sansO = m.objectif == null;
    const srcO = m.objectifSource === 'budget' ? 'budget validé' : (m.objectifSource === 'theorique' ? 'CA théorique de l’étude' : esc(m.objectifSource || ''));
    const sansB = Array.isArray(m.sansBudget) && m.sansBudget.length ? ' · sans budget ' + esc(m.sansBudget.join(', ')) : '';
    const att = m.objectif ? Math.min(100, 100 * m.realise / m.objectif) : 0;
    const attendu = m.objectif && m.attendu != null ? Math.min(100, 100 * m.attendu / m.objectif) : null;
    const cl = m.clientsManquants;
    let h = `<div class="db-tuiles">
      ${tuile('Objectif', sansO ? '—' : fK(m.objectif), sansO ? 'pas de budget pour cette période' : srcO + ' · réparti sur la pondération réseau' + sansB)}
      ${tuile('Attendu à ce jour', sansO ? '—' : fK(m.attendu), sansO ? '' : 'jours passés au poids de chacun')}
      ${tuile('Réalisé', fK(m.realise), fN(m.tickets) + ' clients · panier ' + fU(m.panier) + (m.atteinte != null && !sansO ? ' · ' + fP(100 * m.atteinte) + ' de l’attendu' : ''))}
      ${tuile('Écart à l’attendu', sansO ? '—' : fSK(m.ecart), sansO ? '' : (cl != null ? (cl > 0 ? fN(cl) + ' clients manquants' : fN(-cl) + ' clients d’avance') + ' · ' + fE(Math.abs(m.ecart)) + ' ÷ ' + fU(m.panier) : ''), sansO ? '' : (m.ecart >= 0 ? 'bon' : 'vif'))}
      ${tuile('Reste à faire', sansO ? '—' : fK(m.reste), sansO ? '' : 'prévu sur les jours restants : ' + fK(m.prevu))}
      ${tuile('Résultat net', m.net == null ? '—' : fSK(m.net), m.net == null ? esc(m.motifNet || '') : fP(m.netPct) + ' des ventes', m.net == null ? '' : (m.net >= 0 ? 'bon' : 'vif'))}
    </div>`;
    if (!sansO) {
      h += `<div class="db-card"><div style="padding:12px 16px"><div class="db-lab">Objectif ${S.vue === 'semaine' ? 'de la semaine' : 'du mois'} — ${fK(m.objectif)}</div>
        <div class="db-bar"><i style="width:${att.toFixed(1)}%"></i>${attendu != null ? `<b style="left:${attendu.toFixed(1)}%"></b>` : ''}</div>
        <div class="db-mini" style="margin-top:5px">${fP(att)} réalisé${attendu != null ? ' · le repère noir est l’attendu à ce jour (' + fP(attendu) + ')' : ''}</div></div></div>`;
    }
    const jours = Array.isArray(m.jours) ? m.jours : [];
    if (jours.length) {
      const mx = Math.max(...jours.map(x => Math.max(x.ca || 0, x.objectif || 0)), 1);
      h += `<div class="db-card"><div class="ct"><span class="db-lab">Jour par jour</span><span class="db-mini">barre : CA · trait noir : objectif du jour · rose : sous l’objectif · orange : aujourd’hui</span></div>
        <div class="db-jours" style="grid-template-columns:repeat(${jours.length},minmax(0,1fr))">${jours.map(x => {
          const ca = x.ca || 0; const cls = x.ferme ? 'ferme' : (x.aujourdhui ? 'auj' : (x.objectif && ca < x.objectif && x.passe ? 'sous' : ''));
          return `<div title="${esc(x.date)}${x.objectif ? ' · objectif ' + fE(x.objectif) : ''}"><em>${x.ferme ? '' : (x.passe || x.aujourdhui ? (S.vue === 'mois' ? nf(ca / 1000, 1) : fK(ca)) : '')}</em><div class="bb"><i class="${cls}" style="height:${x.ferme ? 4 : (100 * ca / mx).toFixed(1)}%"></i>${x.objectif ? `<b style="bottom:${(100 * x.objectif / mx).toFixed(1)}%"></b>` : ''}</div><span>${esc(x.court)}</span></div>`; }).join('')}</div>
        ${S.vue === 'mois' ? '<div class="db-note">Montants en k€.</div>' : ''}</div>`;
    }
    h += rendTenir(m, d);
    h += `<div class="db-card"><div class="ct"><span class="db-lab">Le P&amp;L ${S.vue === 'semaine' ? 'de la semaine' : 'du mois'}</span><span class="db-mini">matière : coût des recettes vendues · personnel : planning × taux · frais généraux : panel</span></div>${cascade(m, d)}</div>`;
    if (S.vue === 'mois') { h += rendRentab(); }
    return h;
  }

  /* Ce qu'il manque, et ce qu'il faut pour tenir l'objectif — comme le drop de Résultat. */
  function rendTenir(m, d) {
    if (m.objectif == null) { return ''; }
    const jours = Array.isArray(m.jours) ? m.jours : [];
    const restants = jours.filter(j => !j.passe && !j.ferme && !j.aujourdhui);
    const eff = m.reste - m.prevu;   // ce qu'il faut faire EN PLUS de ce que la pondération prévoyait
    const effCl = m.panier > 0 ? Math.round(eff / m.panier) : null;
    const parJour = restants.length ? m.reste / restants.length : null;
    const cl = m.clientsManquants;
    return `<div class="db-g2" style="grid-template-columns:1fr 1fr;margin-bottom:12px">
      <div class="db-card"><div class="ct"><span class="db-lab">Ce qu’il manque</span><span class="db-mini">écart à l’attendu ${d.enCours ? 'à ce jour' : 'de la période'}</span></div>
        <div style="padding:12px 16px"><div style="font-family:var(--font-display);font-size:20px;color:${cl > 0 ? '#C0182B' : '#2d7a3e'}">${cl > 0 ? 'Il manque ' + fN(cl) + ' clients' : (cl < 0 ? fN(-cl) + ' clients d’avance' : 'Dans la cible')}</div>
        <div class="db-mini" style="margin-top:4px">${fS(m.ecart)} d’écart · ${fE(Math.abs(m.ecart))} ÷ panier ${fU(m.panier)} = ${fN(Math.abs(cl))} clients</div></div></div>
      <div class="db-card"><div class="ct"><span class="db-lab">Tenir l’objectif</span><span class="db-mini">${restants.length ? restants.length + ' jour(s) restant(s)' : 'période close'}</span></div>
        <div class="db-casc" style="grid-template-columns:repeat(3,1fr);padding:10px 16px">
          <div><div class="k">Reste à faire</div><div class="v">${fK(m.reste)}</div><div class="s">${parJour != null ? fE(parJour) + ' par jour ouvert restant' : ''}</div></div>
          <div><div class="k">Prévu par la pondération</div><div class="v">${fK(m.prevu)}</div><div class="s">${m.objectif ? Math.round(100 * m.prevu / m.objectif) + ' % de l’objectif' : ''}</div></div>
          <div><div class="k">Effort en plus</div><div class="v ${eff > 0 ? 'ko' : 'ok'}">${fSK(eff)}</div><div class="s">${effCl != null ? (effCl > 0 ? '+' : '') + fN(effCl) + ' clients sur la période' : ''}</div></div>
        </div></div></div>`;
  }

  /* Le résultat net jour par jour du mois en cours — les cases de l'analyse rentabilité. */
  function rendRentab() {
    if (S.date.slice(0, 7) !== AUJ.slice(0, 7)) { return `<div class="db-note" style="padding:0 0 12px">La heatmap de rentabilité ne se lit que sur le mois en cours (P&amp;L quotidien du panel).</div>`; }
    const r = S.aux['rentab'];
    const teinte = p => p == null ? 'background:var(--color-background-secondary);color:var(--color-text-muted)'
      : p < 0 ? 'background:#8D1D2C;color:#fff' : p < 5 ? 'background:#C17A2A;color:#fff'
      : p < 10 ? 'background:#A8B545;color:#fff' : p < 15 ? 'background:#7CB342;color:#fff'
      : p < 25 ? 'background:#3D8B44;color:#fff' : 'background:#C9A227;color:#fff';
    let h = `<div class="db-card"><div class="ct"><span class="db-lab">Rentabilité — le résultat net, jour par jour</span><span class="db-mini">${r && r.du ? 'mois en cours · ' + fD(r.du) + ' → ' + fD(r.au) : ''}</span></div>`;
    if (S.err['rentab']) { h += `<div class="db-note" style="padding-top:10px">Lecture impossible : ${esc(S.err['rentab'])}</div></div>`; return h; }
    if (!r) { h += `<div style="padding:14px 16px"><div class="db-sk"></div></div></div>`; return h; }
    const mg = (r.magasins || []).find(x => String(x.id) === String(S.shop));
    if (!mg || mg.indispo) { h += `<div class="db-note" style="padding-top:10px">${esc((mg && mg.motif) || r.motif || 'pas de résultat net jour par jour pour ce magasin')}</div></div>`; return h; }
    h += `<div style="display:flex;gap:4px;flex-wrap:wrap;padding:12px 16px 6px">${(mg.jours || []).map(j => `<div title="${esc(j.date)}${j.ouvert && j.net != null ? ' — net ' + fE(j.net) + ' · ' + fP(j.netPct) : ''}" style="${teinte(j.ouvert ? j.netPct : null)};border-radius:6px;min-width:44px;padding:5px 4px;text-align:center;font-size:10.5px;line-height:1.25"><b>${+j.date.slice(8, 10)}</b><br>${j.ouvert ? (j.netPct == null ? '' : Math.round(j.netPct) + ' %') : '·'}</div>`).join('')}</div>
      <div class="db-note">${mg.total && mg.total.netPct != null ? '<b>' + fP(mg.total.netPct) + ' · ' + fE(mg.total.net) + '</b> sur le mois · ' : ''}palette : rouge &lt; 0 % · orange 0–5 · verts 5–25 · doré &gt; 25 % · ${esc(mg.sourceJour || '')}${mg.labourMois != null ? ' · personnel du mois ' + fE(mg.labourMois) + ', frais généraux ' + fE(mg.overheadMois) + ', répartis par jour ouvert' : ''}</div></div>`;
    return h;
  }

  /* La place du magasin dans le réseau — le badge de rang sur la jauge : du
   * dernier (à gauche) au premier (à droite), toi en rouge, les autres en
   * points gris, la médiane un trait. Un trophée au premier. Jamais un nom. */
  function rendBench(m, d) {
    const L = (d.magasins || []).filter(x => x.ouvert !== false);
    const jour = S.vue === 'jour';
    const defs = jour
      ? [['Chiffre d’affaires', 'ca', fK, v => fSK(v)], ['Clients', 'tickets', fN, v => (v >= 0 ? '+' : '−') + fN(Math.abs(v))], ['Panier moyen', 'panier', fU, v => (v >= 0 ? '+ ' : '− ') + fU(Math.abs(v))], ['Marge brute', 'margeBrutePct', v => fP(v), v => (v >= 0 ? '+' : '−') + nf(Math.abs(v), 1) + ' pts'], ['Résultat net', 'netPct', v => fP(v), v => (v >= 0 ? '+' : '−') + nf(Math.abs(v), 1) + ' pts']]
      : [['Chiffre d’affaires', 'realise', fK, v => fSK(v)], ['Clients', 'tickets', fN, v => (v >= 0 ? '+' : '−') + fN(Math.abs(v))], ['Panier moyen', 'panier', fU, v => (v >= 0 ? '+ ' : '− ') + fU(Math.abs(v))], ['Atteinte de l’attendu', 'atteinte', v => fP(100 * v), v => (v >= 0 ? '+' : '−') + nf(Math.abs(100 * v), 1) + ' pts'], ['Résultat net', 'netPct', v => fP(v), v => (v >= 0 ? '+' : '−') + nf(Math.abs(v), 1) + ' pts']];
    const ord = n => n === 1 ? '1er' : n + 'e';
    let premiers = 0;
    const N = S.aux['notif|' + S.shop];
    const msgs = N && Array.isArray(N.messages) ? N.messages : [];
    if (N) { defs.pop(); }
    const tuiles = defs.map(([lib, k, f, fd]) => {
      const vals = L.map(x => x[k]).filter(v => v != null && isFinite(v)).sort((a, b) => b - a);
      const v = m[k];
      if (v == null || !vals.length) { return `<div class="db-bt"><div class="k">${lib}</div><span class="rg mu">—</span><div class="v mu">—</div><div class="s">pas de valeur</div></div>`; }
      const n = vals.length, rang = vals.findIndex(x => x <= v) + 1;
      const med = vals[Math.floor((n - 1) / 2)], meilleur = vals[0], second = vals[1];
      const top = rang === 1, bas = rang === n && n > 1;
      if (top) { premiers++; }
      // La jauge : du minimum au maximum du réseau, en pourcentage de la largeur.
      const mn = vals[n - 1], mx = vals[0], ecart = mx - mn;
      const pos = x => ecart > 0 ? Math.max(0, Math.min(100, 100 * (x - mn) / ecart)) : 50;
      const autres = vals.filter((x, i) => i !== rang - 1).map(x => `<span class="pt" style="left:${pos(x).toFixed(1)}%"></span>`).join('');
      return `<div class="db-bt"><div class="k">${lib}</div><span class="rg${top ? ' top' : (bas ? ' bas' : '')}">${top ? '🏆 ' : ''}${ord(rang)} <small>/ ${n}</small></span>
        <div class="jg"><i class="l"></i>${autres}<span class="md" style="left:${pos(med).toFixed(1)}%"></span><span class="mo${top ? ' top' : ''}" style="left:${pos(v).toFixed(1)}%"></span></div>
        <div class="v">${f(v)}<small>médiane ${f(med)}</small></div>
        <div class="s">${fd(v - med)} vs médiane · ${top ? (second != null ? 'le 2e : ' + f(second) : 'seul en lice') : 'le 1er : ' + f(meilleur)}</div></div>`;
    }).join('');
    let msgT = '', msgD = '';
    if (N && !msgs.length) {
      msgT = `<div class="db-bt msg vide"><div class="k">Messages du panel</div><div class="n"><span class="bell">🔔</span>0</div><div class="last">Pas de message pour l’instant</div></div>`;
    } else if (msgs.length) {
      const nb = p => msgs.filter(x => x.priorite === p).length;
      const nU = nb('urgent'), nA = nb('attention'), nI = nb('info');
      const hh = q => q ? q.slice(11, 16) : '';
      const dj = q => q ? fD(q.slice(0, 10)) + ' à ' + hh(q) : '';
      const dern = msgs[0];
      msgT = `<div class="db-bt msg${nU ? ' urg' : ''}" data-ndrop="1"><span class="dr">${S.nOuvert ? 'replier ▴' : 'détail ▾'}</span><div class="k">Messages du panel</div><div class="n"><span class="bell">🔔</span>${msgs.length}</div><div class="pri">${nU ? `<i class="urg">${nU} urgent</i>` : ''}${nA ? `<i class="warn">${nA} attention</i>` : ''}${nI ? `<i class="info">${nI} info</i>` : ''}</div><div class="last">${esc(dern.titre)}${dern.quand ? ' · ' + hh(dern.quand) : ''}</div></div>`;
      if (S.nOuvert) {
        const lib = { urgent: 'urgent', attention: 'attention', info: 'info' };
        msgD = `<div class="db-ndrop">${msgs.map(x => `<div class="m"><i class="${x.priorite === 'urgent' ? 'urg' : (x.priorite === 'attention' ? 'warn' : 'info')}"></i><div><b>${esc(x.titre || '(sans titre)')}</b>${x.message ? `<p>${esc(x.message)}</p>` : ''}<div class="meta">${lib[x.priorite]} · ${x.type === 'once' ? 'une fois' : 'récurrent'}${x.quand ? ' · publié le ' + dj(x.quand) : ''}${x.au ? ' · visible jusqu’au ' + fD(x.au) : ''}${x.global ? ' · tout le réseau' : ''}</div></div><div class="act"><span>${x.quand ? fD(x.quand.slice(0, 10)) + ' · ' + hh(x.quand) : ''}</span>${x.actionLib ? (N.panel ? `<a href="${esc(N.panel)}" target="_blank" rel="noopener">${esc(x.actionLib)} ›</a>` : `<em>${esc(x.actionLib)}</em>`) : ''}</div></div>`).join('')}
          <div class="db-note" style="padding:4px 0 0">Les messages viennent du panel${N.panel ? ' ; l’action ouvre le panel dans un nouvel onglet' : ''}. Relus avec la page.</div></div>`;
      }
    }
    return `<div class="db-bench"><div class="db-bt tit"><div class="k">Ta place dans le réseau</div><div class="s">${L.length} magasins ouverts · ${jour ? 'la journée' : (S.vue === 'semaine' ? 'la semaine' : 'le mois')} · anonyme${premiers ? ' · <b>' + premiers + ' × 🏆</b>' : ''}</div><div class="leg"><span><i style="background:var(--color-primary)"></i>toi</span><span><i style="background:#c9c2b8"></i>un autre</span><span><b></b>médiane</span></div></div>${tuiles}${msgT}</div>${msgD}`;
  }

  /* --- Les non-conformités de la veille -----------------------------------
   *
   * La vue Jour ne dit que la journée en cours : une tâche notée sous le seuil
   * de conformité hier disparaissait de l'écran le lendemain matin, au moment
   * précis où il fallait la reprendre. Le bandeau la garde en tête de page, le
   * tiroir la détaille, et le bouton contresigne LA REPRISE — jamais la
   * non-conformité elle-même, qui, elle, est un fait acquis.
   *
   * Deux lectures, pas une de plus : `/pwa/tasks/nc` pour la veille (du SQL
   * local, sans le panel), et la lecture du jour DÉJÀ faite pour savoir ce que
   * la même tâche est devenue depuis. La photo, elle, ne se lit qu'au
   * dépliage — elle coûte un aller-retour au panel par ligne.
   */
  function veille() { const t = new Date(S.date + 'T12:00:00'); t.setDate(t.getDate() - 1); return t.toISOString().slice(0, 10); }
  /** La fenêtre lue : la veille en vue Jour, la période affichée sinon — jamais le futur. */
  function ncFenetre() {
    if (S.vue === 'jour') { return { du: veille(), au: veille(), jour: true }; }
    const [d, a] = bornes();
    return { du: d, au: a > AUJ ? AUJ : a, jour: false };
  }
  function cleNC() { const f = ncFenetre(); return 'nc|' + S.shop + '|' + f.du + '|' + f.au; }
  function urlNC(f) {
    return '/pwa/tasks/nc?shop=' + encodeURIComponent(S.shop)
      + (f.jour ? '&date=' + f.du : '&du=' + f.du + '&au=' + f.au);
  }
  const hhmm = v => v ? String(v).slice(11, 16) : '';
  /** Le nom du niveau vient du barème partagé ; « majeur » s'accorde à « non-conformité ». */
  function ncNiveau(D, n) {
    const def = { 1: ['critique', '#8D1D2C'], 2: ['majeure', '#C0182B'], 3: ['mineure', '#D97706'] }[n] || ['non conforme', '#8D1D2C'];
    const lv = (D && Array.isArray(D.niveaux) ? D.niveaux : []).find(x => +x.n === +n);
    if (!lv || !lv.nom) { return { court: def[0], couleur: def[1] }; }
    const court = String(lv.nom).split(/[—–-]/).pop().trim().toLowerCase().replace(/eur$/, 'eure');
    return { court: court || def[0], couleur: lv.couleur || def[1] };
  }
  const ncCls = n => n === 1 ? 'g1' : (n === 2 ? 'g2' : 'g3');
  const ncCourt = v => { let n = String(v || '').replace(/^Photo du comptoir\s*-\s*/i, 'Comptoir · ').replace(/^Contrôle Qualité\s*[–-]\s*/i, 'CQ · '); return n.length > 32 ? n.slice(0, 31) + '…' : n; };

  /** Ce que la MÊME tâche est devenue aujourd'hui. Le cœur du bloc. */
  function ncEtat(taskId, seuil) {
    const d = S.aux['taches|' + S.date];
    // La journée en cours se lit sur le panel : c'est long. Tant qu'elle n'est
    // pas là, on le dit — et la pastille du bandeau se tait plutôt que
    // d'afficher trois points qui passeraient pour un état de la tâche.
    if (!d) { return { c: 'mu', lib: 'lecture de la journée en cours…', sous: 'le devenir de la tâche s’affichera ici', court: '' }; }
    const sh = (d.shops || []).find(x => String(x.shopId) === String(S.shop));
    const t = sh && (sh.taches || []).find(x => String(x.taskId) === String(taskId));
    if (!t) { return { c: 'mu', lib: 'Pas attendue aujourd’hui', sous: 'la tâche n’est pas au programme du jour', court: 'pas au programme' }; }
    const qui = s2 => s2 ? ' par ' + esc(s2) : '';
    if (t.note != null && t.note >= seuil) {
      return { c: 'ok', t: t, deja: !!t.ctrlDir,
        lib: '✓ Refaite' + (t.faitLe ? ' à ' + hhmm(t.faitLe) : '') + ' · notée ' + t.note + '/5',
        sous: 'reprise' + qui(t.faitePar) + (t.valideePar ? ', contrôlée par ' + esc(t.valideePar) : ''),
        court: 'reprise' + (t.faitLe ? ' ' + hhmm(t.faitLe) : '') };
    }
    if (t.note != null) {
      return { c: 'rec', t: t, lib: '↻ Notée ' + t.note + '/5 — encore non conforme',
        sous: 'la reprise n’a pas tenu' + qui(t.faitePar), court: 'encore non conforme' };
    }
    if (t.statut === 'nonRendue') {
      return { c: 'ko', t: t, lib: '✗ Pas encore rendue', sous: 'attendue aujourd’hui, toujours pas rendue', court: 'non rendue' };
    }
    if (t.statut === 'sansPhoto') {
      return { c: 'ctl', t: t, lib: 'Rendue sans photo', sous: 'rien à noter, donc rien à valider', court: 'sans photo' };
    }
    return { c: 'ctl', t: t, lib: '◻ Rendue' + (t.faitLe ? ' à ' + hhmm(t.faitLe) : '') + ' · à contrôler',
      sous: 'photo déposée, sans note de consultant', court: 'à contrôler' };
  }

  /** Sur une période, le devenir ne se lit pas dans la journée en cours mais
   * dans la SUITE de la tâche : la première note posée après l'écart. */
  function ncDepuis(x) {
    const s2 = x.suite;
    if (!s2) { return { c: 'ko', lib: '\u2717 Pas de reprise notée', sous: 'la tâche n\u2019a pas été renotée depuis', court: 'sans reprise' }; }
    if (s2.conforme) { return { c: 'ok', lib: '\u2713 Reprise notée ' + s2.note + '/5', sous: 'le ' + fDL(s2.jour), court: 'reprise le ' + fD(s2.jour) }; }
    return { c: 'rec', lib: '\u21bb Renotée ' + s2.note + '/5', sous: 'le ' + fDL(s2.jour) + ' \u2014 encore non conforme', court: 'encore non conforme' };
  }

  /** Les lignes de la fenêtre, chacune avec son devenir. */
  function ncLignes() {
    const D = S.aux[cleNC()];
    if (!D || !Array.isArray(D.nc)) { return []; }
    const seuil = D.seuil || 4;
    const jour = ncFenetre().jour;
    return D.nc.map(x => Object.assign({}, x,
      { niv: ncNiveau(D, x.note), etat: jour ? ncEtat(x.taskId, seuil) : ncDepuis(x) }));
  }
  /** La photo de la tâche, lue à la demande — un aller-retour au panel par ligne. */
  function ncPhoto(id, jour) {
    const k = 'ph|' + S.shop + '|' + id + '|' + jour;
    if (S.ncPhotos[k] === undefined && !S.enCours[k]) {
      S.enCours[k] = true;
      lire('/pwa/tasks/detail?shop=' + encodeURIComponent(S.shop) + '&task=' + encodeURIComponent(id) + '&date=' + jour)
        .then(d => { S.ncPhotos[k] = { url: d.photo || null,
          reperes: (d.reperes && Array.isArray(d.reperes.liste)) ? d.reperes.liste : [] }; })
        .catch(() => { S.ncPhotos[k] = { url: null, reperes: [] }; })
        .finally(() => { S.enCours[k] = false; rendre(); });
    }
    return S.ncPhotos[k];
  }
  /** La vignette : petite dans le tableau du jour, grande dans un détail ouvert. */
  function ncVignette(x, taille) {
    const p = ncPhoto(x.taskId, x.jour || veille());
    const cls = 'db-ncph ' + taille;
    if (p === undefined) { return `<span class="${cls} att"></span>`; }
    if (!p.url) { return `<span class="${cls} vide" title="photo indisponible">\u2014</span>`; }
    const rep = p.reperes.map((r, i) => `<i style="left:${(r.x * 100).toFixed(1)}%;top:${(r.y * 100).toFixed(1)}%;width:${(r.l * 100).toFixed(1)}%;height:${(r.h * 100).toFixed(1)}%;border-color:${esc(x.niv.couleur)}">${taille === 'max' ? `<u style="background:${esc(x.niv.couleur)}">${i + 1}</u>` : ''}</i>`).join('');
    return `<a class="${cls}" href="${esc(p.url)}" target="_blank" rel="noopener" title="${esc(x.tache)} \u2014 ${p.reperes.length} repère(s) posé(s) au contrôle"><img src="${esc(p.url)}" alt="" onerror="var b=this.parentNode;this.remove();b.classList.add('perdue');b.removeAttribute('href')">${rep}</a>`;
  }

  function rendNC() {
    if (S.vue === 'annee' || S.vue === 'trimestre') { return ''; }
    const cle = cleNC(), D = S.aux[cle], f = ncFenetre();
    // « hier », « cette semaine », « ce mois » : le bandeau nomme la fenêtre
    // qu'il résume, sinon on ne sait pas de quoi il parle.
    const quand = f.jour ? 'hier' : (S.vue === 'semaine' ? 'cette semaine' : 'ce mois');
    const jourV = f.jour ? fDL(f.du) : (S.vue === 'semaine' ? 'du ' + fD(f.du) + ' au ' + fD(f.au) : libPeriode());
    if (S.err[cle]) { return `<div class="db-ncbar mu"><span class="ic">·</span><span class="t">Les non-conformités ${esc(f.jour ? 'd’hier' : quand)}<small>${esc(S.err[cle])}</small></span></div>`; }
    if (!D) { return `<div class="db-ncbar mu"><span class="ic">·</span><span class="t">Les non-conformités ${esc(f.jour ? 'd’hier' : quand)}<small>lecture ${f.jour ? 'du ' : ''}${esc(jourV)}…</small></span></div>`; }
    // Table des avis absente : le cockpit n'a rien à dire, il se tait.
    if (D.indispo) { return ''; }
    const L = ncLignes();
    if (!L.length) {
      const rien = !D.notees;
      return `<div class="db-ncbar ${rien ? 'mu' : 'ok'}"><span class="ic">${rien ? '·' : '✓'}</span>
        <span class="t">${rien ? 'Aucune tâche notée ' + quand : 'Aucune non-conformité ' + quand}<small>${esc(jourV)}${D.notees ? ' · ' + D.notees + ' tâche' + (D.notees > 1 ? 's' : '') + ' notée' + (D.notees > 1 ? 's' : '') + ', rien à reprendre' : ' · aucun contrôle consigné ' + (f.jour ? 'ce jour-là' : 'sur cette période')}</small></span></div>`;
    }
    const ouverts = L.filter(x => x.etat.c !== 'ok'), repris = L.filter(x => x.etat.c === 'ok');
    const valides = repris.filter(x => x.etat.deja).length;
    const chip = x => `<span class="ch${x.etat.c === 'ko' || x.etat.c === 'rec' ? ' ko' : ''}"><i class="${ncCls(x.note)}"></i>${esc(x.niv.court)} · ${esc(ncCourt(x.tache))} ${x.etat.court || x.recidive ? `<em class="${x.etat.c === 'ok' ? 'v' : (x.etat.c === 'ctl' ? 'ctl' : 'ko')}">${esc(x.etat.court)}${x.recidive ? (x.etat.court ? ' ' : '') + '↻ ' + x.recidive + 'e fois' : ''}</em>` : ''}</span>`;
    const reste = ouverts.length > 3 ? `<span class="ch">+ ${ouverts.length - 3} autre${ouverts.length - 3 > 1 ? 's' : ''}</span>` : '';
    // « à valider » serait un appel à l'action : ici on constate, on ne demande
    // rien. Sans contresignature, la pastille dit le nombre et se tait.
    const etatR = valides === repris.length ? 'validée' + (repris.length > 1 ? 's' : '')
      : (valides ? valides + ' validée' + (valides > 1 ? 's' : '') : '');
    const chipR = repris.length ? `<span class="ch"><i class="g3"></i>${repris.length} reprise${repris.length > 1 ? 's' : ''}${etatR ? ' <em class="v">' + etatR + '</em>' : ''}</span>` : '';
    const tout = repris.length && valides === repris.length && !ouverts.length;
    const bandeau = `<div class="db-ncbar${S.ncOuvert ? ' ouv' : ''}${tout ? ' fini' : ''}" data-ncdrop="1"><span class="ic">${tout ? '✅' : '⚠️'}</span>
      <span class="t">${L.length} non-conformité${L.length > 1 ? 's' : ''} ${quand}<small>${esc(jourV)} · ${D.notees} tâche${D.notees > 1 ? 's' : ''} notée${D.notees > 1 ? 's' : ''}${valides ? ' · ' + valides + ' reprise' + (valides > 1 ? 's' : '') + ' validée' + (valides > 1 ? 's' : '') : ''}</small></span>
      <span class="chips">${ouverts.slice(0, 3).map(chip).join('')}${reste}${chipR}</span>
      <span class="act"><span class="dr">${S.ncOuvert ? 'replier ▴' : 'détail ▾'}</span></span></div>`;
    return bandeau + (S.ncOuvert ? ncTiroir(D, L) : '');
  }

  /* Le tiroir prend la forme de la fenêtre qu'il montre :
   *   Jour     — le tableau nu : quelques écarts, tout se lit d'un coup ;
   *   Semaine  — une ligne dense par écart, le détail au clic ;
   *   Mois     — replié par gravité, puis par écart : un mois chargé tient en
   *              trois lignes tant qu'on ne demande rien.
   * Aucune des trois ne perd de donnée : seul change le nombre de clics. */
  function ncTiroir(D, L) {
    const jour = ncFenetre().jour;
    const relev = [...new Set(L.map(x => x.consultant).filter(Boolean))].join(', ');
    const totSem = (Array.isArray(D.semaine) ? D.semaine : []).reduce((a, x) => a + (x.nc || 0), 0);
    const corps = jour ? ncTableau(L) : (S.vue === 'semaine' ? ncListe(L) : ncGroupes(D, L));
    return `<div class="db-ncdl">
      <div class="ct"><span class="db-lab">${L.length} non-conformité${L.length > 1 ? 's' : ''} sur ${D.notees} tâche${D.notees > 1 ? 's' : ''} notée${D.notees > 1 ? 's' : ''}</span><span class="db-mini">${jour && totSem > L.length ? totSem + ' sur les 7 derniers jours \u00b7 ' : ''}${relev ? 'relevée' + (L.length > 1 ? 's' : '') + ' par ' + esc(relev) + ' \u00b7 ' : ''}seuil de conformité ${D.seuil || 4}/5${jour ? ' \u00b7 survoler une vignette pour l\u2019agrandir' : ''}</span><a class="db-lien" href="../#/taches">Contrôle des tâches \u203a</a></div>
      ${corps}</div>`;
  }

  const ncBadge = x => `<span class="db-ncg ${ncCls(x.note)}">${esc(x.niv.court)} <small>${x.note}/5</small></span>`;
  const ncCle = x => x.taskId + '|' + (x.jour || '');

  /* JOUR — le tableau nu : rien à déplier, tout est en colonnes. */
  function ncTableau(L) {
    return `<div class="db-nclist"><table class="db-nct">
      <tr><th style="width:82px">Gravité</th><th style="width:44px">Relevée</th><th>La tâche</th><th>Le constat</th><th style="width:40px">Photo</th><th style="width:176px">Aujourd\u2019hui</th><th style="width:118px">Par</th></tr>
      ${L.map(x => { const t = x.etat.t; return `<tr>
        <td>${ncBadge(x)}</td>
        <td class="d">${hhmm(x.releveeLe)}${x.recidive ? ' <b class="ko" title="' + x.recidive + 'e fois en 7 jours">\u21bb</b>' : ''}</td>
        <td class="n">${esc(x.tache)}${t && t.checklist ? `<br><span class="q">${esc(t.checklist)}</span>` : ''}</td>
        <td class="c">${x.comment ? esc(x.comment) : '<span class="q">sans motif consigné</span>'}</td>
        <td>${ncVignette(x, 'mini')}</td>
        <td><span class="db-ncst ${x.etat.c}">${x.etat.lib}</span>${x.etat.sous ? `<div class="q">${x.etat.sous}</div>` : ''}</td>
        <td class="q">${esc(x.consultant || '')}${x.recidive ? '<br><b class="ko">\u21bb ' + x.recidive + 'e fois en 7 jours</b>' : ''}</td></tr>`; }).join('')}
    </table></div>`;
  }

  /* SEMAINE — une ligne par écart ; le détail s'ouvre sous celle qu'on clique. */
  function ncListe(L, sous) {
    return `<div class="${sous ? 'db-ncsous' : 'db-nclist'}">${L.map(x => {
      const on = S.ncLigne === ncCle(x);
      return ncLigneDense(x, on) + (on ? ncDetail(x) : '');
    }).join('')}</div>`;
  }
  function ncLigneDense(x, on) {
    return `<div class="db-ncl${on ? ' on' : ''}" data-ncrow="${esc(ncCle(x))}">
      <span>${ncBadge(x)}</span>
      <span class="d">${esc(fD(x.jour))}${x.recidive ? '<br><span class="rc">\u21bb ' + x.recidive + 'e</span>' : ''}</span>
      <span class="n">${esc(x.tache)}</span>
      <span class="c">${x.comment ? esc(x.comment) : 'sans motif consigné'}</span>
      <span class="v"><span class="db-ncst ${x.etat.c}">${x.etat.lib}</span></span>
      <span class="ch">${on ? '\u25b4' : '\u25be'}</span></div>`;
  }
  function ncDetail(x) {
    const t = x.etat.t;
    return `<div class="db-ncd">${ncVignette(x, 'max')}
      <div>${x.comment ? `<q>${esc(x.comment)}</q>` : '<q class="mu">sans motif consigné</q>'}
        <dl>${t && t.checklist ? `<dt>Checklist</dt><dd>${esc(t.checklist)}</dd>` : ''}
          <dt>Relevée</dt><dd>le ${esc(fDL(x.jour))}${x.releveeLe ? ' à ' + hhmm(x.releveeLe) : ''}${x.consultant ? ' par ' + esc(x.consultant) : ''}${x.recidive ? ' \u00b7 <b class="ko">\u21bb récidive, ' + x.recidive + 'e fois en 7 jours</b>' : ''}</dd>
          <dt>${ncFenetre().jour ? 'Aujourd\u2019hui' : 'Depuis'}</dt><dd>${x.etat.lib.replace(/^[\u2713\u2717\u21bb\u25fb]\s*/, '')}${x.etat.sous ? ' \u2014 ' + x.etat.sous : ''}${x.valideeLe ? ' \u00b7 contresignée' + (x.valideePar ? ' par ' + esc(x.valideePar) : '') : ''}</dd></dl>
        <div class="a"><a href="../#/taches">Ouvrir la tâche dans Contrôle des tâches \u203a</a></div></div></div>`;
  }

  /* MOIS — replié par gravité. Une ligne par niveau, le compte et les états. */
  function ncGroupes(D, L) {
    const niveaux = [...new Set(L.map(x => x.note))].sort((a, b) => a - b);
    return `<div class="db-nclist">${niveaux.map(n => {
      const G = L.filter(x => x.note === n), on = !!S.ncGrav[n];
      const nOk = G.filter(x => x.etat.c === 'ok').length, nKo = G.length - nOk;
      const niv = ncNiveau(D, n);
      return `<div class="db-ncgr" data-ncgrp="${n}">
        <span class="db-ncg ${ncCls(n)}">${esc(niv.court)} <small>${n}/5</small></span>
        <span class="k">${G.length} écart${G.length > 1 ? 's' : ''}</span>
        <span class="pt">${G.map(x => `<i class="${x.etat.c}" title="${esc(x.tache)} \u00b7 ${esc(fD(x.jour))}"></i>`).join('')}</span>
        <span class="mu">${nOk} repris${nOk > 1 ? '' : ''} \u00b7 <b class="${nKo ? 'ko' : ''}">${nKo} encore ouvert${nKo > 1 ? 's' : ''}</b></span>
        <span class="sp"></span><span class="ch">${on ? 'replier \u25b4' : 'voir \u25be'}</span></div>`
        + (on ? ncListe(G, true) : '');
    }).join('')}</div>`;
  }

  /* Les tâches du jour (ou de la période) : faites, non faites, bloquantes,
   * le fil en miniature, et le détail qui se déplie. Bloquante = tâche
   * d'exploitation (ouverture, fermeture, contrôle opérationnel : checklists
   * CO-…) non rendue ; un contrôle qualité non rendu est « non fait ». */
  function rendTaches() {
    const jour = S.vue === 'jour';
    const cle = jour ? 'taches|' + S.date : (function () { const [du, au] = bornes(); return 'tachesP|' + du + '|' + au; })();
    const d = S.aux[cle];
    const tuileT = (k, v, s, cls) => `<div class="db-bt ${cls || ''}"><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s || ''}</div></div>`;
    let corps = '', drop = '', titre = '', sous = '';
    if (S.err[cle]) { return `<div class="db-taches"><div class="db-bt tit"><div class="k">Les tâches ${jour ? 'du jour' : 'de la période'}</div><div class="s">${esc(S.err[cle])}</div></div></div>`; }
    if (!d) { return `<div class="db-taches"><div class="db-bt tit"><div class="k">Les tâches ${jour ? 'du jour' : 'de la période'}</div><div class="s">lecture du panel…</div></div>${tuileT('Faites', '<span class="db-sk" style="display:block;width:40px;height:22px"></span>')}${tuileT('Non faites', '<span class="db-sk" style="display:block;width:40px;height:22px"></span>')}${tuileT('Bloquantes', '<span class="db-sk" style="display:block;width:40px;height:22px"></span>')}<div class="db-bt fil"><div class="k">Le fil</div><div class="db-sk"></div></div></div>`; }
    if (jour) {
      const sh = (d.shops || []).find(x => String(x.shopId) === String(S.shop));
      const T = sh ? (sh.taches || []) : [];
      if (!T.length) { return `<div class="db-taches"><div class="db-bt tit"><div class="k">Les tâches du jour</div><div class="s">${d.indispo ? 'panel injoignable' : 'aucune tâche pour ce magasin ce jour'}</div></div></div>`; }
      const faite = t => t.statut !== 'nonRendue';
      const bloq = t => !faite(t) && /^CO-/i.test(String(t.checklist || ''));
      const nF = T.filter(faite).length, nN = T.length - nF, nB = T.filter(bloq).length;
      const nCtrl = T.filter(t => t.statut === 'aControler' || t.statut === 'aValider').length, nSans = T.filter(t => t.statut === 'sansPhoto').length;
      const nQ = T.filter(t => !faite(t) && !bloq(t)).length;
      // Les checklists, dans l'ordre de la journée.
      const cls = []; const par = {};
      T.forEach(t => { const c = String(t.checklist || 'Sans checklist'); if (!par[c]) { par[c] = []; cls.push(c); } par[c].push(t); });
      // L'ordre de la journée : l'ouverture d'abord, la fermeture en dernier, entre les deux par heure de première tâche rendue.
      const rang = c => /^CO-01/i.test(c) ? '0' : (/^CO-02/i.test(c) ? '9' : '5' + (par[c].filter(t => t.faitLe).map(t => String(t.faitLe)).sort()[0] || '9999') + c);
      cls.sort((a, b) => rang(a).localeCompare(rang(b)));
      const hDe = t => t.faitLe ? String(t.faitLe).slice(11, 16) : '';
      const mini = cls.map((c, i) => (i ? '<span class="sep"></span>' : '') + par[c].map(t => `<i class="${faite(t) ? 'f' : (bloq(t) ? 'b' : 'n')}" title="${esc(t.tache)}${faite(t) ? ' · ' + hDe(t) + (t.faitePar ? ' · ' + esc(t.faitePar) : '') : ' · non rendue'}"></i>`).join('')).join('');
      const dern = T.filter(t => t.faitLe).sort((a, b) => String(b.faitLe).localeCompare(String(a.faitLe)))[0];
      const court = c => c.replace(/^[A-Z]{2}-?[A-Z0-9]+\s*[—–-]\s*/i, '').replace(/\.$/, '');
      corps = `<div class="db-bt tit"><div class="k">Les tâches du jour</div><div class="s">${T.length} obligatoire(s) · ${T.length ? Math.round(100 * nF / T.length) : 0} % faites${dern ? ' · dernière rendue à ' + hDe(dern) + (dern.faitePar ? ' par ' + esc(dern.faitePar) : '') : ''}</div></div>
        ${tuileT('Faites', nF + '<small>/ ' + T.length + '</small>', (nCtrl ? nCtrl + ' à contrôler' : '') + (nSans ? (nCtrl ? ' · ' : '') + nSans + ' sans photo' : ''), 'ok')}
        ${tuileT('Non faites', nN + '<small>/ ' + T.length + '</small>', (nQ ? nQ + ' contrôle(s) qualité' : '') + (nB ? (nQ ? ' · ' : '') + nB + ' d’exploitation' : ''), 'wa')}
        ${tuileT('Bloquantes', String(nB), nB ? 'exploitation non rendue' : 'rien ne bloque', nB ? 'ko' : '')}
        <div class="db-bt fil" data-tdrop="1"><div class="k">Le fil de la journée <span class="dr">${S.tOuvert ? 'replier ▴' : 'détail ▾'}</span></div><div class="mini">${mini}</div><div class="s">${cls.map(court).map(esc).join(' · ')}</div></div>`;
      if (S.tOuvert) {
        // Le détail : une ligne par checklist — le compte, les heures, puis
        // chaque tâche en pastille (état, nom court, heure et personne au survol).
        const nomCourt = t => { let n = String(t.tache || ''); n = n.replace(/^Photo du comptoir\s*-\s*/i, 'Comptoir · ').replace(/^Contrôle Qualité\s*[–-]\s*/i, 'CQ · '); return n.length > 34 ? n.slice(0, 33) + '…' : n; };
        const etat = t => faite(t) ? 'f' : (bloq(t) ? 'b' : 'n');
        const ico = t => faite(t) ? '✓' : (bloq(t) ? '!' : '·');
        drop = `<div class="db-tdrop">${cls.map(c => { const L = par[c]; const f = L.filter(faite).length, b = L.filter(bloq).length; const hs = L.filter(t => t.faitLe).map(hDe).sort(); const qui = [...new Set(L.filter(t => t.faitePar).map(t => t.faitePar))];
          const meta = hs.length ? hs[0] + (hs.length > 1 ? ' → ' + hs[hs.length - 1] : '') + (qui.length ? ' · ' + esc(qui.join(', ')) : '') : (b ? b + ' bloquante(s)' : 'rien de rendu');
          return `<div class="r"><span class="n">${esc(c)}<small>${meta}</small></span><span class="pills">${L.map(t => `<span class="pl ${etat(t)}" title="${esc(t.tache)}${faite(t) ? ' · ' + hDe(t) + (t.faitePar ? ' · ' + esc(t.faitePar) : '') + (t.statut === 'aControler' || t.statut === 'aValider' ? ' · à contrôler' : '') : (bloq(t) ? ' · non rendue · bloquante' : ' · non rendue')}"><i>${ico(t)}</i>${esc(nomCourt(t))}${faite(t) ? `<em>${hDe(t)}</em>` : ''}</span>`).join('')}</span><span class="c ${f === L.length ? 'ok' : (b ? 'ko' : 'wa')}">${f} / ${L.length}</span></div>`; }).join('')}
          <div class="db-note" style="padding:6px 0 0">✓ rendue · ! bloquante (exploitation non rendue) · · non rendue. Survolez une pastille pour l’heure, la personne et l’état du contrôle.</div></div>`;
      }
    } else {
      const li = (d.lignes || []).find(x => String(x.shopId) === String(S.shop));
      const J = li ? (li.jours || []).filter(j => j.releve && j.j <= AUJ) : [];
      if (!J.length) { return `<div class="db-taches"><div class="db-bt tit"><div class="k">Les tâches de la période</div><div class="s">pas de relevé pour ce magasin sur la période</div></div></div>`; }
      const nF = J.reduce((a, j) => a + (j.faites || 0), 0), nN = J.reduce((a, j) => a + (j.pasFaites || 0), 0), tot = nF + nN;
      const teinte = p => p == null ? '' : (p >= 80 ? 'f' : (p >= 40 ? 'm' : (p > 0 ? 'n' : 'b')));
      const mauvais = J.filter(j => (j.part || 0) < 40).length;
      corps = `<div class="db-bt tit"><div class="k">Les tâches ${S.vue === 'semaine' ? 'de la semaine' : 'du mois'}</div><div class="s">${J.length} jour(s) relevé(s) · ${tot ? Math.round(100 * nF / tot) : 0} % faites · bloquantes : voir le jour</div></div>
        ${tuileT('Faites', nF + '<small>/ ' + tot + '</small>', Math.round(nF / Math.max(1, J.length)) + ' par jour en moyenne', 'ok')}
        ${tuileT('Non faites', nN + '<small>/ ' + tot + '</small>', Math.round(nN / Math.max(1, J.length)) + ' par jour en moyenne', 'wa')}
        ${tuileT('Jours sous 40 %', String(mauvais), mauvais ? 'jour(s) où moins de 40 % des tâches sont faites' : 'aucun jour en dessous', mauvais ? 'ko' : '')}
        <div class="db-bt fil" data-tdrop="1"><div class="k">Le fil des jours <span class="dr">${S.tOuvert ? 'replier ▴' : 'détail ▾'}</span></div><div class="mini">${J.map(j => `<i class="${teinte(j.part)}" title="${esc(j.j)} · ${j.faites} faites / ${j.faites + j.pasFaites} · ${j.part} %"></i>`).join('')}</div><div class="s">une case par jour · vert ≥ 80 % · vert clair ≥ 40 % · rose &lt; 40 % · rouge : rien de fait</div></div>`;
      if (S.tOuvert) {
        drop = `<div class="db-tdrop">${J.map(j => `<div class="r"><span class="n">${fDL(j.j)}</span><span class="db-bar" style="margin:0"><i style="width:${Math.min(100, j.part || 0)}%;background:${(j.part || 0) >= 80 ? '#2d7a3e' : ((j.part || 0) >= 40 ? '#A8B545' : '#C0182B')}"></i></span><span class="c ${(j.part || 0) >= 80 ? 'ok' : ((j.part || 0) >= 40 ? 'wa' : 'ko')}">${j.faites} / ${j.faites + j.pasFaites}</span></div>`).join('')}</div>`;
      }
    }
    return `<div class="db-taches">${corps}</div>${drop}`;
  }

  /* L'année : la heatmap des 12 mois (deux années) et l'objectif — 1 an, 3 ans, 5 ans. */
  /* --- Trimestre : l'objectif du plan, les 3 mois, les annotations ---------- */
  const VOIX = [['franchise', 'Franchisé', 'fr'], ['consultant', 'Consultant', 'co'], ['marque', 'Marque', 'ma']];
  const nomVoix = (plan, v) => (plan && plan.voix && plan.voix[v[0]]) || v[1];
  const actionsDe = (plan, q) => (plan && Array.isArray(plan.actions) ? plan.actions : []).filter(a => +a.trimestre === q);
  const stAct = a => a.statut === 'fait' || a.statut === 'faite' || a.statut === 'termine' ? 'ok' : (a.statut === 'en_cours' || a.statut === 'encours' || a.statut === 'lancee' ? 'enc' : '');
  const libAct = a => stAct(a) === 'ok' ? 'fait' : (stAct(a) === 'enc' ? 'en cours' : (a.statut ? String(a.statut).replace(/_/g, ' ') : 'à faire'));
  function blocNotes(plan, t) {
    const q = t.t, acts = actionsDe(plan, q), notes = t.notes || {};
    return VOIX.map(v => { const n = notes[v[0]]; return `<div class="db-nv ${v[2]}${n ? '' : ' vide'}"><div class="q"><span>${esc(nomVoix(plan, v))}</span><span>${n && n.le ? 'maj ' + fD(String(n.le).slice(0, 10)) + (n.par ? ' · ' + esc(n.par) : '') : ''}</span></div><p>${n ? esc(n.texte) : 'pas encore d’annotation'}</p></div>`; }).join('')
      + (acts.length ? `<div class="db-lab" style="margin:8px 0 4px">Actions du trimestre</div>` + acts.map(a => `<div class="db-act"><span>${esc(a.libelle)}${a.responsable ? ' <span class="mu">· ' + esc(a.responsable) + '</span>' : ''}${a.effetAn ? ' <span class="mu">· effet ' + fK(a.effetAn) + ' / an</span>' : ''}</span><span class="st ${stAct(a)}">${esc(libAct(a))}</span></div>`).join('') : `<div class="db-mini" style="margin-top:8px">aucune action posée sur ce trimestre</div>`)
      + `<div class="db-rit">Rituel du trimestre : ${[1, 2, 3, 4, 5].map(i => `<i class="${i <= ((t.revue && t.revue.etape) || 1) ? 'on' : ''}"></i>`).join('')} étape ${(t.revue && t.revue.etape) || 1} / 5${t.revue && t.revue.valideLe ? ' · validé le ' + fD(String(t.revue.valideLe).slice(0, 10)) + (t.revue.validePar ? ' par ' + esc(t.revue.validePar) : '') : ' · non validé'}</div>`;
  }
  function rendTrimestre() {
    const Y = annee(), q = trimestre();
    const perf = S.aux['perf|' + Y], plan = S.aux['plan|' + S.shop + '|' + Y];
    let h = '';
    if (S.err['perf|' + Y]) { h += `<div class="db-err">Mois : ${esc(S.err['perf|' + Y])}</div>`; }
    if (S.err['plan|' + S.shop + '|' + Y]) { h += `<div class="db-err">Plan : ${esc(S.err['plan|' + S.shop + '|' + Y])}</div>`; }
    h += `<div class="db-sec">Résultat — le trimestre T${q} ${Y}<small>objectif du plan de développement, attendu au rythme des budgets mensuels</small></div>`;
    if (!plan && !S.err['plan|' + S.shop + '|' + Y]) { return h + squelette(3); }
    const t = plan && Array.isArray(plan.trimestres) ? plan.trimestres.find(x => +x.t === q) : null;
    if (!t) { return h + `<div class="db-alerte">${esc((plan && plan.error) || 'Pas de plan pour ce trimestre.')}</div>`; }
    const MO = ['jan', 'fév', 'mar', 'avr', 'mai', 'juin', 'juil', 'août', 'sep', 'oct', 'nov', 'déc'];
    const att = t.objectif ? 100 * (t.realise || 0) / t.objectif : null;
    h += `<div class="db-tuiles">
      ${tuile('Objectif ' + t.label, fK(t.objectif), 'plan de développement · ' + t.mois.map(m => MO[m - 1]).join(' · '))}
      ${tuile('Réalisé', fK(t.realise), (att != null ? fP(att) + ' de l’objectif · ' : '') + (t.moisRealises || 0) + ' mois lu(s)')}
      ${tuile('Attendu à ce jour', fK(t.attendu), 'au rythme des budgets mensuels')}
      ${tuile('Écart', t.ecart == null ? '—' : fSK(t.ecart), t.ecart == null ? (t.futur ? 'trimestre à venir' : '') : fP(t.ecartPct) + ' · ' + (t.ecart >= 0 ? 'en avance' : 'en retard') + ' sur l’attendu', t.ecart == null ? '' : (t.ecart >= 0 ? 'bon' : 'vif'))}
      ${tuile('Clients manquants', t.clients == null ? '—' : fN(t.clients), t.panier ? 'au panier moyen ' + fU(t.panier) : '')}
      ${tuile('Marge nette', fP(t.netPct), (t.food != null ? 'food ' + fP(t.food) : '') + (t.labour != null ? ' · labour ' + fP(t.labour) : ''), t.netPct == null ? '' : (t.netPct >= 10 ? 'bon' : 'vif'))}
    </div>`;
    // les 3 mois : budget et réalisé
    const cells = (Array.isArray(perf) ? perf : []).filter(c => String(c.storeId) === String(S.shop) && +c.annee === Y);
    const M = t.mois.map(m => cells.find(c => +c.mois === m) || { mois: m });
    const mx = Math.max(...M.map(x => Math.max((x.caBudget || x.caTheorique || 0), x.ca || 0)), 1);
    const moisAuj = +AUJ.slice(5, 7), anAuj = +AUJ.slice(0, 4);
    h += `<div class="db-g2" style="grid-template-columns:1.4fr 1fr;margin-bottom:12px">
      <div class="db-card"><div class="ct"><span class="db-lab">Les 3 mois du trimestre</span><span class="db-mini">gris : budget du mois · couleur : réalisé (vert ≥ 95 %, rouge sinon, orange = mois en cours)</span></div>
        ${!perf && !S.err['perf|' + Y] ? squelette(1) : `<div class="db-mo">${M.map(x => { const bud = x.caBudget || x.caTheorique || 0, ca = x.ca || 0, pct = bud ? 100 * ca / bud : null; const enc = Y === anAuj && +x.mois === moisAuj, futur = Y > anAuj || (Y === anAuj && +x.mois > moisAuj);
          return `<div><div class="bars"><i class="b" style="height:${(140 * bud / mx).toFixed(0)}px" title="budget ${fE(bud)}"></i><i class="r ${enc ? 'enc' : (pct != null && pct >= 95 ? 'ok' : '')}" style="height:${(140 * ca / mx).toFixed(0)}px" title="réalisé ${fE(ca)}"></i></div><div class="n">${MO[x.mois - 1]} · ${futur ? '—' : fK(ca)}</div><div class="d">budget ${fK(bud)}${pct != null && !futur ? ' · ' + fP(pct) : ''}${enc ? ' · en cours' : ''}${x.tickets ? ' · ' + fN(x.tickets) + ' clients' : ''}</div></div>`; }).join('')}</div>`}
      </div>
      <div class="db-card"><div class="ct"><span class="db-lab">Les annotations du trimestre</span><span class="db-mini">plan de développement · <a class="db-lien" href="../#/plan-developpement">Cockpit › Budget › Plan ›</a></span></div><div style="padding:10px 16px 12px">${blocNotes(plan, t)}</div></div>
    </div>`;
    return h;
  }

  /** Le tableau des trimestres de l'année, avec les annotations. */
  function tableauTrimestres(plan, Y) {
    const T = Array.isArray(plan.trimestres) ? plan.trimestres : [];
    if (!T.length) { return ''; }
    const MO = ['jan', 'fév', 'mar', 'avr', 'mai', 'juin', 'juil', 'août', 'sep', 'oct', 'nov', 'déc'];
    const col = p => p == null ? '#B9B2A8' : p < 80 ? '#8D1D2C' : p < 95 ? '#C17A2A' : p < 105 ? '#7CB342' : '#C9A227';
    const tag = t => t.enCours ? '<span class="db-tg enc">en cours</span>' : (t.clos ? '<span class="db-tg clos">clos</span>' : '<span class="db-tg fut">à venir</span>');
    const tot = { o: 0, r: 0, a: 0 };
    const rows = T.map(t => { const att = t.attendu ? 100 * (t.realise || 0) / t.attendu : null; tot.o += t.objectif || 0; tot.r += t.realise || 0; tot.a += t.attendu || 0;
      const notes = t.notes || {}, acts = actionsDe(plan, t.t), et = (t.revue && t.revue.etape) || 1;
      const an = VOIX.filter(v => notes[v[0]] && notes[v[0]].texte).map(v => `<div><i class="v ${v[2]}"></i><b>${esc(nomVoix(plan, v))}</b> — ${esc(notes[v[0]].texte.length > 140 ? notes[v[0]].texte.slice(0, 140) + '…' : notes[v[0]].texte)}</div>`).join('');
      const pied = `${acts.length ? acts.length + ' action(s) · ' + acts.filter(a => stAct(a) === 'ok').length + ' faite(s) · ' : ''}rituel ${et}/5${t.revue && t.revue.valideLe ? ' validé' : ''}`;
      return `<tr data-trimq="${t.t}"><td class="l"><b>${esc(t.label)}</b>${tag(t)}<br><span class="mu">${t.mois.map(m => MO[m - 1]).join(' · ')}</span></td><td>${fK(t.objectif)}</td><td>${fK(t.realise)}</td><td>${fK(t.attendu)}</td><td style="color:${t.ecart == null ? 'inherit' : (t.ecart >= 0 ? 'var(--color-success,#2d7a3e)' : 'var(--color-primary)')}">${t.ecart == null ? '—' : fSK(t.ecart)}</td><td>${att == null ? '—' : `<span class="db-pill" style="background:${col(att)}">${fP(att)}</span>`}</td><td>${t.clients == null ? '—' : '− ' + fN(t.clients)}</td><td>${t.panier == null ? '—' : fU(t.panier)}</td><td>${fP(t.food)}</td><td>${fP(t.labour)}</td><td>${fP(t.netPct)}</td><td class="an">${an || '<span class="mu" style="font-style:italic">pas encore d’annotation</span>'}<div class="mu" style="margin-top:2px">${pied}</div></td></tr>`; }).join('');
    const A = plan.annee || {};
    return `<div class="db-card"><div class="ct"><span class="db-lab">${Y}, trimestre par trimestre</span><span class="db-mini">objectif du plan · réalisé · les annotations des trois voix, et les actions · cliquer un trimestre pour l’ouvrir</span></div>
      <div style="padding:4px 16px 12px;overflow-x:auto"><table class="db-t db-tq"><tr><th>Trimestre</th><th>Objectif</th><th>Réalisé</th><th>Attendu</th><th>Écart</th><th>Atteinte</th><th>Clients</th><th>Panier</th><th>Food</th><th>Labour</th><th>Marge nette</th><th class="l">Annotations</th></tr>${rows}
      <tr class="tot"><td class="l">${Y}</td><td>${fK(tot.o)}</td><td>${fK(tot.r)}</td><td>${fK(tot.a)}</td><td style="color:${tot.r - tot.a >= 0 ? 'var(--color-success,#2d7a3e)' : 'var(--color-primary)'}">${fSK(tot.r - tot.a)}</td><td>${tot.a ? `<span class="db-pill" style="background:${col(100 * tot.r / tot.a)}">${fP(100 * tot.r / tot.a)}</span>` : '—'}</td><td colspan="5"></td><td class="an mu">${A.objectif ? 'objectif de l’année ' + fK(A.objectif) : ''}${A.projection ? ' · projection ' + fK(A.projection) : ''}</td></tr></table></div></div>`;
  }

  function rendAnnee() {
    const Y = annee();
    const perf = S.aux['perf|' + Y], plan = S.aux['plan|' + S.shop + '|' + Y];
    let h = '';
    if (S.err['perf|' + Y]) { h += `<div class="db-err">Mois : ${esc(S.err['perf|' + Y])}</div>`; }
    if (S.err['plan|' + S.shop + '|' + Y]) { h += `<div class="db-err">Objectif : ${esc(S.err['plan|' + S.shop + '|' + Y])}</div>`; }
    // --- l'objectif de l'année, et la rampe
    h += `<div class="db-sec">L’objectif — ${Y}, 3 ans, 5 ans<small>engagement du franchisé, sinon la rampe de l’étude de marché</small></div>`;
    if (!plan && !S.err['plan|' + S.shop + '|' + Y]) { h += squelette(2); }
    else if (plan && plan.annee) {
      const A = plan.annee, src = A.objectifSource === 'rampe' ? 'rampe de l’étude, à engager' : 'engagement du franchisé';
      const att = A.objectif ? Math.min(100, 100 * A.realise / A.objectif) : 0, wAtt = A.objectif && A.attendu != null ? Math.min(100, 100 * A.attendu / A.objectif) : null;
      h += `<div class="db-tuiles">
        ${tuile('Objectif ' + Y, fK(A.objectif), src)}
        ${tuile('Réalisé', fK(A.realise), A.partEcoulee != null ? fP(A.partEcoulee) + ' de l’année écoulée' : '')}
        ${tuile('Attendu à ce jour', fK(A.attendu), 'au rythme des budgets mensuels')}
        ${tuile('Écart', fSK(A.ecart), A.ecart == null ? '' : (A.ecart >= 0 ? 'en avance' : 'en retard') + ' sur l’attendu', A.ecart == null ? '' : (A.ecart >= 0 ? 'bon' : 'vif'))}
        ${tuile('Projection fin d’année', fK(A.projection), A.projection != null && A.objectif ? fSK(A.projection - A.objectif) + ' sur l’objectif' : 'au rythme actuel', A.projection != null && A.objectif ? (A.projection >= A.objectif ? 'bon' : 'vif') : '')}
        ${tuile('Rampe à 5 ans', plan.rampe && plan.rampe.potentiel ? fK(plan.rampe.potentiel) : '—', plan.rampe && plan.rampe.potentiel ? 'potentiel à maturité · ' + (function () { const n = (plan.rampe.annees || []).find(a => a.an === Y + 1); return n ? 'l’an prochain ' + fK(n.ca) : ''; })() : 'sans étude de marché')}
      </div>
      ${A.objectif ? `<div class="db-card"><div style="padding:12px 16px"><div class="db-lab">Objectif ${Y} — ${fK(A.objectif)}</div><div class="db-bar"><i style="width:${att.toFixed(1)}%"></i>${wAtt != null ? `<b style="left:${wAtt.toFixed(1)}%"></b>` : ''}</div><div class="db-mini" style="margin-top:5px">${fP(att)} réalisé${wAtt != null ? ' · le repère noir est l’attendu à ce jour (' + fP(wAtt) + ')' : ''}</div></div></div>` : ''}`;
      const R = (plan.rampe && plan.rampe.annees) || [];
      if (R.length) {
        const mx = Math.max(...R.map(a => a.ca || 0), 1);
        const engs = plan.engagements || {};
        h += `<div class="db-card"><div class="ct"><span class="db-lab">La rampe à 5 ans</span><span class="db-mini">${plan.rampe.potentiel ? 'potentiel à maturité ' + fK(plan.rampe.potentiel) + ' · année ' + plan.rampe.anneeExploitation + ' d’exploitation en ' + Y : 'sans étude de marché'}</span></div>
          <div class="db-jours" style="grid-template-columns:repeat(${R.length},1fr);height:190px">${R.map(a => { const en = engs[String(a.an)]; const cur = a.an === Y;
            return `<div><em>${fK(a.ca)}${en && en.objectif ? '<br><span class="mu" style="font-weight:500">engagé ' + fK(en.objectif) + '</span>' : ''}</em><div class="bb"><i class="${cur ? '' : 'ferme'}" style="height:${(100 * (a.ca || 0) / mx).toFixed(1)}%;${cur ? '' : 'background:#e5d9cc'}"></i>${cur && A.realise ? `<b style="bottom:${(100 * A.realise / mx).toFixed(1)}%;background:#D97706"></b>` : ''}</div><span>${a.an} · ${a.maturite ? 'maturité' : 'année ' + a.anneeExploitation + ' · ' + a.coef + ' %'}</span></div>`; }).join('')}</div>
          <div class="db-note">Barres : la rampe de l’étude (potentiel × montée en régime) ; trait orange sur ${Y} : le réalisé à ce jour. L’engagement se dépose dans Cockpit › Plan de développement.</div></div>`;
      }
      h += tableauTrimestres(plan, Y);
    } else if (plan) { h += `<div class="db-alerte">${esc(plan.error || 'Pas de plan pour ce magasin.')}</div>`; }
    // --- la heatmap des mois
    h += `<div class="db-sec">Les mois — ${Y - 1} et ${Y}<small>CA du mois et atteinte du budget, magasin seul</small></div>`;
    if (!perf && !S.err['perf|' + Y]) { h += squelette(2); }
    else if (perf) {
      const cells = (Array.isArray(perf) ? perf : []).filter(c => String(c.storeId) === String(S.shop));
      const MO = ['jan', 'fév', 'mar', 'avr', 'mai', 'juin', 'juil', 'août', 'sep', 'oct', 'nov', 'déc'];
      const tPct = p => p == null ? 'background:var(--color-background-secondary);color:var(--color-text-muted)'
        : p < 80 ? 'background:#8D1D2C;color:#fff' : p < 95 ? 'background:#C17A2A;color:#fff' : p < 105 ? 'background:#7CB342;color:#fff' : 'background:#C9A227;color:#fff';
      const mxCa = Math.max(...cells.map(c => c.ca || 0), 1);
      const tCa = ca => ca == null || !ca ? 'background:var(--color-background-secondary);color:var(--color-text-muted)' : `background:rgba(141,29,44,${(0.15 + 0.75 * ca / mxCa).toFixed(2)});color:${ca / mxCa > 0.45 ? '#fff' : '#222'}`;
      const auj = new Date();
      h += `<div class="db-card"><div class="ct"><span class="db-lab">Heatmap mensuelle</span>
        <div class="db-ong" style="margin-left:8px"><button data-hm="pct" class="${S.hmMetric === 'pct' ? 'on' : ''}">% d’atteinte du budget</button><button data-hm="ca" class="${S.hmMetric === 'ca' ? 'on' : ''}">CA du mois</button></div>
        <span class="db-mini">budget validé, sinon CA théorique de l’étude · le mois en cours est en cours</span></div>
        <div style="padding:12px 16px;overflow-x:auto"><div style="display:grid;grid-template-columns:70px repeat(12,minmax(64px,1fr));gap:4px;min-width:900px">
          <div></div>${MO.map(x => `<div class="db-lab" style="text-align:center">${x}</div>`).join('')}
          ${[Y - 1, Y].map(an => `<div style="font-weight:600;font-size:12px;display:flex;align-items:center">${an}</div>` + MO.map((x, i) => {
            const c = cells.find(z => +z.annee === an && +z.mois === i + 1); const bud = c ? (c.caBudget || c.caTheorique) : null;
            const pct = c && bud ? 100 * c.ca / bud : null; const futur = an > auj.getFullYear() || (an === auj.getFullYear() && i > auj.getMonth());
            const enCours = an === auj.getFullYear() && i === auj.getMonth();
            if (!c || futur || !c.ca) { return `<div style="${tPct(null)};border-radius:6px;min-height:46px;display:flex;align-items:center;justify-content:center;font-size:10.5px">${futur ? '' : '—'}</div>`; }
            return `<div title="${an}-${String(i + 1).padStart(2, '0')} · CA ${fE(c.ca)}${bud ? ' · budget ' + fE(bud) + ' · ' + fP(pct) : ''} · ${fN(c.tickets)} tickets · panier ${fU(c.panierMoyen)}" style="${S.hmMetric === 'pct' ? tPct(pct) : tCa(c.ca)};border-radius:6px;min-height:46px;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:11px;line-height:1.2;${enCours ? 'outline:2px dashed #222;outline-offset:-2px' : ''}"><b>${S.hmMetric === 'pct' ? (pct == null ? '—' : Math.round(pct) + ' %') : fK(c.ca)}</b><span style="font-size:9.5px;opacity:.85">${S.hmMetric === 'pct' ? fK(c.ca) : (pct == null ? '' : Math.round(pct) + ' %')}</span></div>`; }).join('')).join('')}
        </div></div>
        <div class="db-note">Atteinte : rouge &lt; 80 % · orange 80–95 · vert 95–105 · doré &gt; 105 %. Survolez une case : CA, budget, tickets, panier.</div></div>`;
      // Le tableau des mois de l'année : tickets, panier, marge, ratios.
      const an = cells.filter(c => +c.annee === Y && c.ca).sort((a, b) => a.mois - b.mois);
      if (an.length) {
        h += `<div class="db-card"><div class="ct"><span class="db-lab">${Y}, mois par mois</span><span class="db-mini">marge, labour et overhead connus depuis juillet 2026, food cost depuis janvier</span></div>
          <table class="db-t"><tr><th>Mois</th><th>CA</th><th>Budget</th><th>Atteinte</th><th>Clients</th><th>Panier</th><th>Food cost</th><th>Labour</th><th>Overhead</th><th>Marge nette</th></tr>
          ${an.map(c => { const bud = c.caBudget || c.caTheorique; const pct = bud ? 100 * c.ca / bud : null;
            return `<tr><td>${MO[c.mois - 1]} ${Y}</td><td>${fK(c.ca)}</td><td class="mu">${fK(bud)}</td><td class="${pct == null ? 'mu' : (pct >= 100 ? 'ok' : (pct >= 90 ? 'wa' : 'ko'))}">${fP(pct)}</td><td>${fN(c.tickets)}</td><td>${fU(c.panierMoyen)}</td><td>${fP(c.foodCostPct)}</td><td>${fP(c.labourCostPct)}</td><td>${fP(c.overheadPct)}</td><td class="${c.margePct == null ? 'mu' : (c.margePct >= 0.15 ? 'ok' : (c.margePct >= 0.05 ? 'wa' : 'ko'))}">${c.margePct == null ? '—' : fP(100 * c.margePct)}${c.margeNette != null ? ' <span class="mu">· ' + fK(c.margeNette) + '</span>' : ''}</td></tr>`; }).join('')}</table></div>`;
      }
    }
    return h;
  }

  /* Les heures : courbe, tableau en cascade, panneau de l'heure. */
  function rendHeures(st) {
    const L = st.heures || [];
    if (!L.length) { return `<div class="db-alerte">Aucune heure vendue sur cette période${st.joursServis && st.joursServis.length === 0 ? ' — le panel n’a pas répondu' : ''}.</div>`; }
    const moy = S.mode === 'moy' && S.vue !== 'jour';
    const val = (l, k) => moy ? l.moy[k] : l[k];
    const nJ = Math.max(1, st.nJoursOuverts || 1);
    const tot = st.totaux || {};
    if (S.heure === null || !L.some(l => l.h === S.heure)) { S.heure = st.meilleure ? st.meilleure.h : L[0].h; }
    const sel = L.find(l => l.h === S.heure) || L[0];
    const LH = Array.isArray(st.heures) ? st.heures.filter(x => x.ca > 0) : [];
    const hCli = LH.length ? LH.reduce((m, x) => x.tickets > m.tickets ? x : m) : null, hCa = LH.length ? LH.reduce((m, x) => x.ca > m.ca ? x : m) : null;
    let h = `<div class="db-tuiles">
      ${hCli ? tuile('Heure avec le plus de clients', hCli.h + ' – ' + (hCli.h + 1) + ' h', fN(moy ? hCli.moy.tickets : hCli.tickets) + ' clients' + (moy ? ' par jour ouvert' : '') + ' · panier ' + fU(hCli.panier) + ' · ' + fP(tot.tickets ? 100 * hCli.tickets / tot.tickets : null) + ' des clients') : tuile('Heure avec le plus de clients', '—', '')}
      ${hCa ? tuile('Heure avec le plus de CA', hCa.h + ' – ' + (hCa.h + 1) + ' h', fK(moy ? hCa.moy.ca : hCa.ca) + (moy ? ' par jour ouvert' : '') + ' · ' + fP(tot.ca ? 100 * hCa.ca / tot.ca : null) + ' des ventes · matière ' + fP(hCa.mbPct == null ? null : 100 - hCa.mbPct)) : tuile('Heure avec le plus de CA', '—', '')}
      ${tuile('Marge brute', fK(tot.mb), fP(tot.mbPct) + ' des ventes')}
      ${tuile('Marge nette des heures', fSK(tot.res), fP(tot.resPct) + ' des ventes · rémunération ' + fK(tot.trav), tot.res >= 0 ? 'bon' : 'vif')}
      ${st.meilleure ? tuile('Heure la plus rentable', st.meilleure.h + ' – ' + (st.meilleure.h + 1) + ' h', fSK(moy ? st.meilleure.moy : st.meilleure.res) + (moy ? ' par jour ouvert' : ''), 'bon') : ''}
      ${st.pire ? tuile('Heure la moins rentable', st.pire.h + ' – ' + (st.pire.h + 1) + ' h', fSK(moy ? st.pire.moy : st.pire.res) + (moy ? ' par jour ouvert' : ''), (moy ? st.pire.moy : st.pire.res) < 0 ? 'vif' : '') : ''}
    </div>`;
    // Le graphique : une grille commune, une colonne par heure — la barre
    // empilée (matière, rémunération, marge nette ; une perte hachurée) et,
    // exactement dessous, la case de la marge nette en euros.
    const max = Math.max(...L.map(l => val(l, 'ca')), 1) * 1.04;
    const teinteMn = pct => pct == null ? 'var(--color-background-secondary)' : (pct < 0 ? '#C0182B' : (pct < 20 ? '#D97706' : (pct < 40 ? '#A8B545' : '#2d7a3e')));
    const HB = 210;
    let barres = '', heures = '', cases = '';
    L.forEach(l => {
      const ca = val(l, 'ca'), mat = val(l, 'mat'), trav = val(l, 'trav'), mn = val(l, 'res');
      const pm = ca > 0 ? 100 * mat / ca : 0, pt = ca > 0 ? 100 * Math.max(0, Math.min(trav, ca - mat)) / ca : 0, pr = ca > 0 ? 100 * Math.max(mn, 0) / ca : 0;
      const pct = ca > 0 ? 100 * mn / ca : null;
      const on = l.h === sel.h;
      barres += `<div class="bh${on ? ' sel' : ''}" data-h="${l.h}"><em>${fE(ca)}</em><div class="st" style="height:${Math.max(2, (HB - 20) * ca / max).toFixed(0)}px"><i style="height:${pm.toFixed(1)}%;background:#e5c9a0"></i><i style="height:${pt.toFixed(1)}%;background:#D97706"></i><i style="height:${pr.toFixed(1)}%;background:#2d7a3e"></i>${mn < 0 && ca > 0 ? `<i style="height:${Math.min(100, 100 * Math.abs(mn) / ca).toFixed(1)}%;background:repeating-linear-gradient(45deg,#C0182B,#C0182B 3px,#f2c9cf 3px,#f2c9cf 6px)"></i>` : ''}</div></div>`;
      heures += `<div class="hh${on ? ' sel' : ''}" data-h="${l.h}">${l.h} h</div>`;
      cases += `<div class="cell${on ? ' sel' : ''}" data-h="${l.h}" style="background:${teinteMn(pct)}" title="${l.h} – ${l.h + 1} h · marge nette ${fS(mn)}${pct != null ? ' · ' + fP(pct) + ' des ventes' : ''}">${fS(mn)}</div>`;
    });
    h += `<div class="db-card"><div class="ct"><span class="db-lab">${S.vue === 'jour' ? 'Ce que chaque heure rapporte' : (moy ? 'La journée type — moyenne par jour ouvert' : 'La période — total des heures')}</span>
      ${S.vue !== 'jour' ? `<div class="db-ong" style="margin-left:8px"><button data-mode="moy" class="${moy ? 'on' : ''}">Moyenne / jour ouvert</button><button data-mode="tot" class="${moy ? '' : 'on'}">Total</button></div>` : ''}
      <span class="db-mini">le chiffre au-dessus : les ventes de l’heure · la case : la marge nette · cliquez une heure</span></div>
      <div class="db-gr" style="grid-template-columns:104px repeat(${L.length},minmax(0,1fr))">
        <div class="lab">Ventes de l’heure</div>${barres}
        <div class="axe"></div><div></div>${heures}
        <div class="lab">Marge nette<br><span>marge brute − rémunération</span></div>${cases}
      </div>
      <div class="db-axe" style="padding-top:8px"><span><i class="c" style="background:#e5c9a0"></i>coût matière &nbsp; <i class="c" style="background:#D97706"></i>rémunération &nbsp; <i class="c" style="background:#2d7a3e"></i>marge nette de l’heure &nbsp; <i class="c" style="background:#C0182B"></i>perte</span><span>hauteur de la barre = ventes · case : rouge &lt; 0 · orange &lt; 20 % des ventes · vert clair &lt; 40 % · vert ≥ 40 %</span></div></div>`;
    // Tableau + panneau.
    h += `<div class="db-g2">`;
    h += `<div class="db-card"><div class="ct"><span class="db-lab">Heure par heure — ventes − matière = marge brute · marge brute − rémunération = marge nette</span></div>
      <table class="db-t"><tr><th>Heure</th><th>Clients</th><th>Panier</th><th>Ventes</th><th>− Matière</th><th>= Marge brute</th><th>En poste</th><th>− Rémunération</th><th>= Marge nette</th></tr>
      ${L.map(l => `<tr class="hv${l.h === sel.h ? ' sel' : ''}${val(l, 'res') < 0 ? ' perte' : ''}" data-h="${l.h}"><td>${l.h === sel.h ? '▾' : '▸'} ${l.h} – ${l.h + 1} h</td><td>${moy ? nf(l.moy.tickets, 0) : fN(l.tickets)}</td><td>${fU(l.panier)}</td><td>${fE(val(l, 'ca'))}</td><td class="mu">${fE(val(l, 'mat'))}</td><td><b>${fE(val(l, 'mb'))}</b> <span class="mu" style="font-weight:400">${fP(l.mbPct)}</span></td><td>${l.poste}</td><td class="mu">${fE(val(l, 'trav'))}</td><td class="${coul(val(l, 'res'))}"><b>${fS(val(l, 'res'))}</b></td></tr>`).join('')}
      <tr class="tot"><td>${S.vue === 'jour' ? 'Journée' : (moy ? 'Jour type' : 'Période')}</td><td>${fN(tot.tickets / (moy ? nJ : 1))}</td><td>${fU(tot.panier)}</td><td>${fE(tot.ca / (moy ? nJ : 1))}</td><td>${fE(tot.mat / (moy ? nJ : 1))}</td><td>${fE(tot.mb / (moy ? nJ : 1))} <span class="mu" style="font-weight:400">${fP(tot.mbPct)}</span></td><td>—</td><td>${fE(tot.trav / (moy ? nJ : 1))}</td><td class="${coul(tot.res)}">${fS(tot.res / (moy ? nJ : 1))}</td></tr></table>
      <div class="db-note" style="padding-top:10px">Ventes, matière, rémunération et marge de l’heure viennent du panel (répartition horaire). Le personnel en poste est la moyenne des jours ouverts.</div></div>`;
    h += rendPanneau(st, sel, moy, nJ);
    h += `</div>`;
    return h;
  }
  function rendPanneau(st, l, moy, nJ) {
    const V = moy ? l.moy.ca : l.ca, M = moy ? l.moy.mat : l.mat, MB = V - M, T = moy ? l.moy.trav : l.trav, R = MB - T;
    const base = Math.max(V, 1);
    const rows = [['Ventes', V, 0, V, '#8D1D2C'], ['− Coût matière', M, V - M, V, '#e5c9a0'], ['= Marge brute', MB, 0, MB, '#8D1D2C'], ['− Rémunération', T, MB - T, MB, '#D97706'], ['= Marge nette', R, 0, Math.max(R, 0), R >= 0 ? '#2d7a3e' : '#C0182B']];
    const top = l.top || [];
    const mx = Math.max(...top.map(x => x.v), 1);
    const pr = st.produits || {};
    const couv = pr.total ? `${pr.jours.length} jour(s) sur ${pr.total}` : '';
    return `<div class="db-card"><div class="ct"><span class="db-lab">${l.h} – ${l.h + 1} h</span><span class="db-mini">${moy ? nf(l.moy.tickets, 0) : fN(l.tickets)} clients · panier ${fU(l.panier)}${moy ? ' · par jour ouvert' : ''}</span></div>
      <div class="db-wf">${rows.map((r, k) => `<div class="r${k === 4 ? ' tot' : ''}"><span class="n">${r[0]}</span><span class="b"><i style="left:${Math.max(0, 100 * r[2] / base).toFixed(1)}%;width:${Math.max(0, 100 * (r[3] - r[2]) / base).toFixed(1)}%;background:${r[4]}"></i></span><span class="v ${k === 4 ? coul(R) : ''}">${k === 4 ? fS(R) : fE(r[1])}</span></div>`).join('')}</div>
      <div class="ct" style="border-top:.5px solid var(--color-border-tertiary);border-bottom:none;padding:10px 16px 4px"><span class="db-lab">Top 5 de l’heure — par marge brute</span><span class="db-mini">■ marge &nbsp;<span style="color:#c9a36a">■</span> coût matière</span></div>
      ${top.length ? `<div class="db-pl">${top.map((x, k) => `<div class="r"><b class="n">${k + 1}</b><span title="${esc(x.nom)}">${esc(x.nom)}</span><span class="v mu">${nf(x.q, 0)} pcs</span><span class="b"><i style="width:${(100 * x.v / mx).toFixed(1)}%;background:#e5c9a0"></i>${x.m != null ? `<i style="width:${(100 * Math.max(0, x.m) / mx).toFixed(1)}%"></i>` : ''}</span><span class="v"><b>${x.m != null ? fU(x.m) : fU(x.v)}</b></span><span class="v ${x.taux == null ? 'mu' : (x.taux < 50 ? 'wa' : 'ok')}">${x.taux != null ? nf(x.taux, 0) + ' %' : 'sans coût'}</span></div>`).join('')}</div>
        ${(l.cats || []).length ? `<div class="ct" style="border-top:.5px solid var(--color-border-tertiary);border-bottom:none;padding:10px 16px 4px"><span class="db-lab">Top 3 des catégories — par marge brute</span><span class="db-mini">${l.categories || ''} catégorie(s) vendue(s)</span></div>
        <div class="db-pl">${l.cats.map((x, k) => `<div class="r"><b class="n">${k + 1}</b><span title="${esc(x.nom)}">${esc(x.nom)}<br><span class="mu" style="font-size:10px">${x.refs} référence(s) · ${x.part != null ? nf(x.part, 0) + ' % des ventes de l’heure' : ''}</span></span><span class="v mu">${nf(x.q, 0)} pcs</span><span class="b"><i style="width:${(100 * x.v / Math.max(...l.cats.map(y => y.v), 1)).toFixed(1)}%;background:#e5c9a0"></i>${x.m != null ? `<i style="width:${(100 * Math.max(0, x.m) / Math.max(...l.cats.map(y => y.v), 1)).toFixed(1)}%"></i>` : ''}</span><span class="v"><b>${x.m != null ? fU(x.m) : fU(x.v)}</b></span><span class="v ${x.taux == null ? 'mu' : (x.taux < 50 ? 'wa' : 'ok')}">${x.taux != null ? nf(x.taux, 0) + ' %' : 'sans coût'}</span></div>`).join('')}</div>` : ''}
        <div class="db-note">${l.references} référence(s) vendue(s) à cette heure${l.topSur ? ' · tickets lus sur ' + l.topSur + ' jour(s)' : ''}${pr.total && !pr.complet ? ' · <b>tickets lus : ' + couv + '</b> — la moisson complète la période au fil des heures' : ''}. Marge = ventes − quantité × coût de la recette ; « sans coût » : recette non chiffrée.</div>`
        : `<div class="db-note" style="padding-top:8px">${pr.total ? (pr.jours.length ? 'Aucune ligne produit à cette heure sur les jours lus (' + couv + ').' : 'Tickets pas encore lus pour cette période — la moisson les lit par lots ; relisez dans quelques minutes.') : 'Les tickets ne sont moissonnés que depuis août 2026.'}</div>`}
    </div>`;
  }

  /* --- gestes -------------------------------------------------------------- */
  /** Feux d'artifice sur le canvas du record : des fusées éclatent pendant ~20 s puis le ciel se calme. Relancés à chaque rendu. */
  function feux(cv) {
    if (!cv || cv.dataset.on) { return; }
    cv.dataset.on = '1';
    const r = cv.getBoundingClientRect(); if (!r.width) { return; }
    cv.width = r.width * 2; cv.height = r.height * 2;
    const x = cv.getContext('2d'); x.scale(2, 2);
    const W = r.width, H = r.height, cols = ['#e2b93b', '#8D1D2C', '#2d7a3e', '#1f4e8c', '#fff', '#D97706', '#f7e7a1'];
    let P = [], t = 0;
    const tir = (cx, cy) => { const c = cols[Math.floor(Math.random() * cols.length)]; for (let i = 0; i < 44; i++) { const a = Math.PI * 2 * i / 44, v = 1.2 + Math.random() * 2.2; P.push({ x: cx, y: cy, vx: Math.cos(a) * v, vy: Math.sin(a) * v, l: 1, c, r: 1.3 + Math.random() * 1.4 }); } };
    const step = () => {
      if (!cv.isConnected) { return; }
      x.clearRect(0, 0, W, H);
      P.forEach(p => { p.x += p.vx; p.y += p.vy; p.vy += 0.045; p.vx *= .985; p.l -= .011; x.globalAlpha = Math.max(0, p.l); x.fillStyle = p.c; x.beginPath(); x.arc(p.x, p.y, p.r, 0, 7); x.fill(); });
      x.globalAlpha = 1; P = P.filter(p => p.l > 0); t++;
      if (t % 45 === 1 && t < 1200) { tir(W * (0.1 + Math.random() * 0.65), H * (0.15 + Math.random() * 0.5)); }
      if (t < 1320 || P.length) { requestAnimationFrame(step); }
    };
    for (let i = 0; i < 3; i++) { tir(W * (0.15 + i * 0.25 + Math.random() * .08), H * (0.2 + Math.random() * .4)); }
    step();
  }

  function brancher() {
    $.querySelectorAll('canvas.db-feux').forEach(feux);
    $.querySelectorAll('[data-vue]').forEach(b => b.addEventListener('click', () => { S.vue = b.dataset.vue; S.heure = null; S.jourH = null; urlMaj(); charger(false); }));
    const dt = document.getElementById('db-date'); if (dt) { dt.addEventListener('change', () => { if (dt.value && dt.value <= AUJ) { S.date = dt.value; S.heure = null; S.jourH = null; urlMaj(); charger(false); } }); }
    $.querySelectorAll('[data-pas]').forEach(b => b.addEventListener('click', () => {
      const t = new Date(S.date + 'T12:00:00'); const n = +b.dataset.pas;
      if (S.vue === 'jour') { t.setDate(t.getDate() + n); } else if (S.vue === 'semaine') { t.setDate(t.getDate() + 7 * n); } else if (S.vue === 'mois') { t.setMonth(t.getMonth() + n, 1); } else if (S.vue === 'trimestre') { t.setMonth(t.getMonth() + 3 * n, 1); } else { t.setFullYear(t.getFullYear() + n, 0, 1); }
      const d = t.toISOString().slice(0, 10); if (d > AUJ) { return; }
      S.date = d; S.heure = null; S.jourH = null; urlMaj(); charger(false); }));
    $.querySelectorAll('[data-trimq]').forEach(r => r.addEventListener('click', () => { const q = +r.dataset.trimq; let d = annee() + '-' + String((q - 1) * 3 + 1).padStart(2, '0') + '-01'; if (d > AUJ) { return; } if (q === Math.floor((+AUJ.slice(5, 7) - 1) / 3) + 1 && annee() === +AUJ.slice(0, 4)) { d = AUJ; } S.vue = 'trimestre'; S.date = d; urlMaj(); charger(false); }));
    $.querySelectorAll('[data-trim]').forEach(b => b.addEventListener('click', () => { const q = +b.dataset.trim; let d = annee() + '-' + String((q - 1) * 3 + 1).padStart(2, '0') + '-01'; if (d > AUJ) { return; } if (d.slice(0, 7) === AUJ.slice(0, 7) || (q === Math.floor((+AUJ.slice(5, 7) - 1) / 3) + 1 && annee() === +AUJ.slice(0, 4))) { d = AUJ; } S.date = d; urlMaj(); charger(false); }));
    $.querySelectorAll('[data-auj]').forEach(b => b.addEventListener('click', () => { S.date = AUJ; S.heure = null; S.jourH = null; urlMaj(); charger(false); }));
    $.querySelectorAll('[data-recharger]').forEach(b => b.addEventListener('click', () => charger(true)));
    $.querySelectorAll('[data-mode]').forEach(b => b.addEventListener('click', () => { S.mode = b.dataset.mode; rendre(); }));
    $.querySelectorAll('[data-hm]').forEach(b => b.addEventListener('click', () => { S.hmMetric = b.dataset.hm; rendre(); }));
    $.querySelectorAll('[data-tdrop]').forEach(b => b.addEventListener('click', () => { S.tOuvert = !S.tOuvert; rendre(); }));
    $.querySelectorAll('[data-ndrop]').forEach(b => b.addEventListener('click', () => { S.nOuvert = !S.nOuvert; rendre(); }));
    $.querySelectorAll('[data-cdrop]').forEach(b => b.addEventListener('click', () => { S.cOuvert = !S.cOuvert; rendre(); }));
    $.querySelectorAll('[data-pdrop]').forEach(b => b.addEventListener('click', () => { S.pOuvert = !S.pOuvert; rendre(); }));
    $.querySelectorAll('[data-ctri]').forEach(b => b.addEventListener('click', () => { S.cTri = b.dataset.ctri; rendre(); }));
    $.querySelectorAll('[data-h]').forEach(el => el.addEventListener('click', () => { S.heure = +el.dataset.h; rendre(); }));
    $.querySelectorAll('[data-ncdrop]').forEach(b => b.addEventListener('click', () => { S.ncOuvert = !S.ncOuvert; rendre(); }));
    $.querySelectorAll('[data-vdrop]').forEach(b => b.addEventListener('click', () => { S.valoOuvert = !S.valoOuvert; rendre(); }));
    $.querySelectorAll('[data-ncrow]').forEach(b => b.addEventListener('click', () => {
      S.ncLigne = S.ncLigne === b.dataset.ncrow ? null : b.dataset.ncrow; rendre(); }));
    $.querySelectorAll('[data-ncgrp]').forEach(b => b.addEventListener('click', () => {
      const n = b.dataset.ncgrp; S.ncGrav[n] = !S.ncGrav[n]; rendre(); }));
    $.querySelectorAll('[data-jh]').forEach(el => el.addEventListener('click', () => { S.jourH = el.dataset.jh || null; S.heure = null; charger(false); }));
  }

  /* --- départ ------------------------------------------------------------- */
  lire('/stores?statut=tous').then(l => { S.stores = (Array.isArray(l) ? l : []).filter(s => !s.status || /ouvert/i.test(s.status)).map(s => ({ id: s.id, nom: s.nom || s.name })); rendre(); }).catch(() => {});
  urlMaj();
  charger(false);
  // La journée en cours se relit toutes les dix minutes.
  setInterval(() => { if (S.vue === 'jour' && S.date === AUJ) { charger(true); } }, 600000);
})();
