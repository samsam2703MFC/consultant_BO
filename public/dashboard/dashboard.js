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
  // ?embed=1 : la page servie dans le cockpit (Gestion de production) — sans entête, sans onglets ;
  // seule la vue Production y vit, le sous-onglet vient du rail (?onglet=plan|suivi|params).
  const EMBED = q.get('embed') === '1';
  const S = { shop: q.get('shop') || '4', vue: EMBED ? 'production' : (['ops', 'jour', 'semaine', 'mois', 'trimestre', 'annee', 'reclamation'].includes(q.get('vue')) ? q.get('vue') : (q.get('vue') ? 'jour' : 'ops')),
    date: /^\d{4}-\d{2}-\d{2}$/.test(q.get('date') || '') ? q.get('date') : new Date().toISOString().slice(0, 10),
    heure: null, mode: 'moy', hmMetric: 'pct', tOuvert: false, nOuvert: false, perOuvert: false, perCol: 'ca', cOuv: {}, cVue: (function () { try { return localStorage.getItem('db.cVue') === 'treemap' ? 'treemap' : 'liste'; } catch (e) { return 'liste'; } })(), jourH: null, pOuvert: false, stores: [], res: {}, st: {}, enCours: {}, err: {}, relances: {}, aux: {}, relus: {},
    ncOuvert: false, ncPhotos: {}, ncLigne: null, ncGrav: {}, valoOuvert: false,
    auxLu: {}, cqFiltre: 'tout', cqVoir: null, cqTente: {}, cqRaz: false,
    rc: null, rcFiltre: 'tout', rcFait: null, rcHaut: false, rcMode: null,
    calVal: (function () { try { const v = localStorage.getItem('db.calVal'); return ['ca', 'att', 'cli'].includes(v) ? v : 'ca'; } catch (e) { return 'ca'; } })(),
    stockOuvert: false, stockVues: null, cmdOuvert: false, invOuvert: false, cmdListeOuvert: false,
    ppOnglet: ['plan', 'suivi', 'params'].includes(q.get('onglet')) ? q.get('onglet') : 'plan', ppEdit: null, ppOuvert: {}, ppMsg: null, ppEnvoi: false,
    noteOuvert: false, noteBrouillon: null, noteEtat: null, objOuvert: false, promoOuvert: false, proOuvert: false,
    a4: null, a4Vise: false,
    mo: ['exp', 'ctrl'].includes(q.get('mo')) ? q.get('mo') : 'exp', rgOuvert: false,
    opVitTout: false, opVie: 'S', opOuv: {}, fiche: null,
    pushEtat: 'inconnu', pushMotif: '', pushOccupe: false };
  const AUJ = new Date().toISOString().slice(0, 10);
  const $ = document.getElementById('dash');

  /* --- formats ------------------------------------------------------------ */
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
  const fE = n => n == null ? '—' : nf(Math.round(n) || 0, 0) + ' €';
  const fK = n => n == null ? '—' : (Math.abs(n) >= 10000 ? Number(n / 1000).toLocaleString('fr-BE', { minimumFractionDigits: 0, maximumFractionDigits: 1 }) + ' k€' : fE(n));
  const fU = n => n == null ? '—' : nf(n, 2) + ' €';
  const fP = n => n == null ? '—' : nf(n, 1) + ' %';
  const fP0 = n => n == null ? '—' : nf(Math.round(n), 0) + ' %';
  const fS = n => n == null ? '—' : (n >= 0 ? '+ ' : '− ') + fE(Math.abs(n));
  const fSK = n => n == null ? '—' : (n >= 0 ? '+ ' : '− ') + fK(Math.abs(n));
  const fN = n => n == null ? '—' : nf(Math.round(n), 0);
  // Le signe moins typographique, comme fS : « -87,1 % » et « − 1 754 € »
  // côte à côte sur le même écran, cela se voit.
  const fPS = n => n == null ? '—' : (n >= 0 ? '' : '− ') + nf(Math.abs(n), 1) + ' %';
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
  /** Une écriture : le statut HTTP fait foi, comme pour la lecture. */
  function ecrire(path, corps) {
    return fetch(API + path, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(corps) })
      .then(r => r.ok ? r.json() : r.json().then(j => Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status))), () => Promise.reject(new Error('HTTP ' + r.status))));
  }
  function cleRes() { return S.vue + '|' + S.date; }
  /** Le jour dont on lit les heures : en vue Jour, un jour cliqué dans « le jour dans le mois », sinon la date. */
  function dateH() { return S.vue === 'jour' && S.jourH ? S.jourH : S.date; }
  function cleSt() { return S.shop + '|' + (S.vue === 'ops' ? 'jour' : S.vue) + '|' + dateH(); }
  function annee() { return +S.date.slice(0, 4); }
  function trimestre() { return Math.floor((+S.date.slice(5, 7) - 1) / 3) + 1; }
  function bornes() {
    const t = new Date(S.date + 'T12:00:00');
    if (S.vue === 'semaine') { const j = (t.getDay() + 6) % 7; const du = new Date(t); du.setDate(t.getDate() - j); const au = new Date(du); au.setDate(du.getDate() + 6); return [du.toISOString().slice(0, 10), au.toISOString().slice(0, 10)]; }
    const du = S.date.slice(0, 8) + '01'; const fin = new Date(t.getFullYear(), t.getMonth() + 1, 0); return [du, fin.toISOString().slice(0, 10)];
  }
  /** Le lundi et le dimanche de la date regardée, quelle que soit la vue :
   * au téléphone, le mur porte la semaine même quand on lit le jour. */
  function bornesSemaine() {
    const t = new Date(S.date + 'T12:00:00');
    const j = (t.getDay() + 6) % 7;
    const du = new Date(t); du.setDate(t.getDate() - j);
    const au = new Date(du); au.setDate(du.getDate() + 6);
    return [du.toISOString().slice(0, 10), au.toISOString().slice(0, 10)];
  }
  /** Une réponse servie d'un calcul ancien (le serveur la refait après coup, `cache.relu`) se relit une fois, une demi-minute plus tard. */
  function relirePerime(cle, d, relire) {
    if (!d || !d.cache || !d.cache.relu || S.relus[cle]) { return; }
    S.relus[cle] = true;
    setTimeout(() => { relire(); setTimeout(() => { delete S.relus[cle]; }, 60000); }, 30000);
  }
  /**
   * Les lectures lentes que le dashboard ne relit pas après une écriture : le serveur les garde
   * quelques secondes (`_cache`, 06/10/2026) et refait le calcul en arrière-plan. Pas les photos
   * (leurs liens expirent), ni les notes, objectifs, promotions et pro (lus vite, écrits ici).
   */
  const CACHE_AUX = { 'opSuivi': 90, 'valo': 600, 'notif': 120, 's6': 600, 'stock': 120, 'taches': 120, 'record': 600, 'tend': 300, 'tachesP': 600, 'cmd': 120, 'rentab': 600, 'canaux': 300, 'offres': 300, 'inv': 300, 'invd': 120, 'nc': 300, 'fiche': 900 };
  const avecCache = (cle, path) => { const n = CACHE_AUX[String(cle).split('|')[0]]; return n ? path + (path.includes('?') ? '&' : '?') + '_cache=' + n : path; };
  function lireAux(cle, path, force) {
    if ((force || !S.aux[cle]) && !S.enCours[cle]) {
      S.enCours[cle] = true; delete S.err[cle];
      lire(avecCache(cle, path)).then(d => {
        S.aux[cle] = d; S.auxLu[cle] = Date.now();
        relirePerime(cle, d, () => lireAux(cle, path, true));
        // Le stock vient d'être relu : si une référence est passée sous son
        // minimum depuis la lecture précédente, on le dit.
        if (cle === 'stock|' + S.shop) { stockAvertirSiNouveau(stockEtat()); }
      }).catch(e => { S.err[cle] = e.message; }).finally(() => { S.enCours[cle] = false; rendre(); });
    }
  }
  function charger(force) {
    // Le plan d'action n'est pas une période : le module des visites lit lui-même.
    if (S.vue === 'actions' || S.vue === 'campagne') { rendre(); return; }
    // La réclamation fournisseur (téléphone) : la liste du magasin, et ce
    // qu'il faut pour en écrire une — produits, livraisons, motifs.
    if (S.vue === 'reclamation') { lireAux(cleRC(), cheminRC(), force); lireAux(cleRCR(), cheminRCR(), force); rendre(); return; }
    if (S.vue === 'production') { lireAux(clePP(), cheminPP(), force); rendre(); return; }
    const kr = cleRes(), ks = cleSt();
    if (S.vue === 'annee' || S.vue === 'trimestre') {
      lireAux('valo|' + S.shop, '/ventes/mensuel?shop=' + encodeURIComponent(S.shop) + '&mois=30', force);
      lireAux('perf|' + annee(), '/stores/perf?granularite=mois&annees=' + (annee() - 1) + ',' + annee(), force);
      lireAux('plan|' + S.shop + '|' + annee(), '/plan?shop=' + encodeURIComponent(S.shop) + '&exercice=' + annee(), force);
      rendre(); return;
    }
    // Le Résultat et les ventes d'abord : le navigateur n'ouvre que six connexions vers le serveur
    // et sert les lectures dans l'ordre où elles partent (04/10/2026 : demandé en dernier, le
    // Résultat attendait derrière dix-sept autres lectures, 6 à 8 s pour une réponse d'une seconde).
    if (S.vue !== 'ops' && (force || !S.res[kr]) && !S.enCours[kr]) {
      S.enCours[kr] = true; delete S.err[kr];
      const p = S.vue === 'jour' ? '/exploitation/jour?date=' + S.date : '/exploitation/periode?vue=' + S.vue + '&date=' + S.date;
      lire(p).then(d => { S.res[kr] = d;
        relirePerime(kr, d, () => lire(p).then(d2 => { S.res[kr] = d2; rendre(); }).catch(() => {})); })
        .catch(e => { S.err[kr] = e.message; }).finally(() => { S.enCours[kr] = false; rendre(); });
    }
    // Au téléphone, le mur porte la semaine sous le jour : une lecture de plus,
    // la même que la vue Semaine, donc déjà connue du serveur.
    if (estMobile() && S.vue === 'jour') { lireAux('sem|' + bornesSemaine()[0], '/exploitation/periode?vue=semaine&date=' + S.date, force); }
    // L'onglet Semaine du téléphone : le résultat de chaque jour (porté par la lecture du jour) et,
    // jour par jour, les contrôles et la poubelle.
    if (estMobile() && S.vue === 'semaine') {
      lireAux('jourM|' + S.date, '/exploitation/jour?date=' + S.date, force);
      lireAux(cleSemJ(), '/exploitation/semaine-jours?shop=' + encodeURIComponent(S.shop) + '&date=' + S.date, force);
    }
    if ((force || !S.st[ks]) && !S.enCours[ks]) {
      S.enCours[ks] = true; delete S.err[ks];
      lire('/ventes/stats?shop=' + encodeURIComponent(S.shop) + '&vue=' + (S.vue === 'ops' ? 'jour' : S.vue) + '&date=' + dateH())
        .then(d => { S.st[ks] = d; if (S.heure === null && d.meilleure) { S.heure = d.meilleure.h; }
          // Une lecture partielle (tickets à suivre) se complète toute seule.
          if (d.produits && d.produits.aSuivre && (S.relances[ks] || 0) < 6) { S.relances[ks] = (S.relances[ks] || 0) + 1; setTimeout(() => { if (cleSt() === ks) { charger(true); } }, 4000); } })
        .catch(e => { S.err[ks] = e.message;
          // Un échec (temps dépassé côté serveur) se retente : ce qui a été lu est gravé.
          if ((S.relances[ks] || 0) < 3) { S.relances[ks] = (S.relances[ks] || 0) + 1; setTimeout(() => { if (cleSt() === ks) { charger(true); } }, 5000); } })
        .finally(() => { S.enCours[ks] = false; rendre(); });
    }
    // La valeur du magasin ne dépend pas de la période regardée : elle se lit
    // toujours à partir d'aujourd'hui. Trente mois demandés parce que la
    // fenêtre de 730 jours s'arrête au dernier mois qui a des ventes, pas à
    // aujourd'hui : si ce mois est ancien, la fenêtre recule d'autant.
    lireAux('valo|' + S.shop, '/ventes/mensuel?shop=' + encodeURIComponent(S.shop) + '&mois=30', force);
    if (S.vue === 'mois' && S.date.slice(0, 7) === AUJ.slice(0, 7)) { lireAux('rentab', '/exploitation/rentabilite?periode=mois', force); }
    // Les six dernières semaines face au N-1 : sur la vue Semaine seulement.
    if (S.vue === 'semaine') { lireAux('s6|' + S.shop + '|' + bornes()[0], '/ventes/semaines?shop=' + encodeURIComponent(S.shop) + '&date=' + S.date + '&n=6', force); }
    // La frise des périodes (10/10/2026), au bureau : les jours, les semaines ou les mois autour de la période regardée.
    if (!EMBED && !estMobile()) {
      if (S.vue === 'ops' || S.vue === 'semaine') { [...new Set(friseJours().map(d => d.slice(0, 7)))].filter(ym => ym <= AUJ.slice(0, 7)).forEach(ym => lireAux('frJ|' + ym, '/exploitation/periode?vue=mois&date=' + finMois(ym), force)); }
      if (S.vue === 'mois') { lireAux('perf|' + annee(), '/stores/perf?granularite=mois&annees=' + (annee() - 1) + ',' + annee(), force); }
    }
    // Le stock est vivant : il se relit avec la page, et la page se relit
    // toute seule toutes les dix minutes en vue Jour sur aujourd'hui.
    lireAux('stock|' + S.shop, '/ventes/stock?shop=' + encodeURIComponent(S.shop), force);
    if (S.vue === 'ops') { opCharger(force); }
    else if (S.vue === 'jour') { lireAux('taches|' + S.date, '/pwa/tasks?date=' + S.date, force); lireAux('record|' + S.shop + '|' + S.date, '/ventes/record?shop=' + encodeURIComponent(S.shop) + '&date=' + S.date, force); lireAux('tend|' + S.shop + '|' + S.date, '/ventes/tendance?shop=' + encodeURIComponent(S.shop) + '&date=' + S.date, force); lireAux(cleNote(), cheminNote(), force); lireAux(cleObj(), cheminObj(), force); lireAux(clePromo(), cheminPromo(), force); lireAux(clePro(), cheminPro(), force); lireAux(cleCQ(), cheminCQ(), force); lireAux(cleCanaux(), cheminCanaux(), force); lireAux(cleOffres(), cheminOffres(), force); }
    else { const [du, au] = bornes(); lireAux('tachesP|' + du + '|' + au, '/pwa/tasks/heatmap/mois?du=' + du + '&au=' + (au < AUJ ? au : AUJ) + '&obligatoires=1', force); }
    if (coPer()) { lireAux(cleCanaux(), cheminCanaux(), force); lireAux(cleOffres(), cheminOffres(), force); }
    if (S.vue === 'jour' || coPer()) { lireAux(cleInv(), cheminInv(), force); }
    // Les commandes clients et les livraisons : une seule lecture, elle porte
    // les deux et ne dépend pas de la période regardée.
    if (estMobile()) { lireAux('cmd|' + S.shop, '/ventes/commandes?shop=' + encodeURIComponent(S.shop), force); }
    // Les non-conformités se lisent sous les trois vues : la veille en Jour,
    // la période affichée en Semaine et en Mois.
    lireAux(cleNC(), urlNC(ncFenetre()), force);
    rendre();
  }
  function urlMaj() {
    const u = new URL(location.href);
    u.searchParams.set('shop', S.shop); u.searchParams.set('vue', S.vue); u.searchParams.set('date', S.date);
    if (EMBED) { u.searchParams.set('onglet', S.ppOnglet); }
    if (estMobile() && S.vue === 'jour') { u.searchParams.set('mo', S.mo); } else { u.searchParams.delete('mo'); }
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
    if (S.vue === 'ops') { return (S.date === AUJ ? 'la journée en cours · ' : 'la journée · ') + fDL(S.date); }
    if (S.vue === 'production') { return 'production · ' + fDL(S.date); }
    if (S.vue === 'annee') { return 'année ' + annee(); }
    if (S.vue === 'trimestre') { return 'T' + trimestre() + ' ' + annee(); }
    const d = S.res[cleRes()];
    if (S.vue === 'semaine') { return d && d.du ? 'semaine du ' + fD(d.du) + ' au ' + fD(d.au) : 'semaine'; }
    const t = new Date(S.date + 'T12:00:00');
    return MOIS[t.getMonth()] + ' ' + t.getFullYear();
  }

  /* --- rendu -------------------------------------------------------------- */
  /* --- La valeur du magasin ------------------------------------------------
   * La règle du réseau : le CA des 730 derniers jours, ramené au jour, puis à
   * l'année (× 365), divisé par 6 — soit deux mois de chiffre d'affaires.
   * Deux ans de recul, et un compte en JOURS plutôt qu'en mois : un mois de
   * 28 jours ne pèse pas comme un mois de 31, et une fenêtre en jours ne se
   * déforme pas selon le mois où on la pose.
   *
   * Le diviseur est le nombre de jours d'ACTIVITÉ, plafonné à 730 : un magasin
   * ouvert il y a quinze mois est ramené au jour sur ses quinze mois, pas sur
   * deux ans qu'il n'a pas vécus — sans quoi il serait amputé d'un tiers pour
   * la seule raison qu'il est jeune. Dès qu'il a ses 730 jours, le diviseur
   * est 730 et ne bouge plus.
   *
   * La fenêtre s'arrête au dernier mois qui a des ventes, pas à aujourd'hui :
   * le mois en cours est incomplet, et le compter reviendrait à diviser un
   * demi-mois par un mois entier.
   *
   * Les ventes arrivent par mois ; chaque mois est donc réparti sur ses jours
   * et seuls les jours qui tombent dans la fenêtre comptent — c'est ce qui
   * permet de couper à 730 jours au milieu d'un mois sans le fausser. */
  const VALO_JOURS = 730, VALO_DIV = 6, VALO_AN = 365;
  /** Les mois rendus par /ventes/mensuel — le mois en cours en est déjà exclu. */
  function valoMois() {
    const d = S.aux['valo|' + S.shop];
    return d && Array.isArray(d.mois) ? d.mois : null;
  }
  const libMois = m => MOIS_C[+m.slice(5, 7) - 1] + ' ' + m.slice(0, 4);
  /** Le nombre de jours du mois « YYYY-MM ». */
  const joursDuMois = m => new Date(+m.slice(0, 4), +m.slice(5, 7), 0).getDate();
  const iso = d => d.toISOString().slice(0, 10);
  /** Le jour de la première vente, tel que /ventes/mensuel le rend. */
  function valoOuverture() {
    const d = S.aux['valo|' + S.shop];
    return d && d.ouverture ? d.ouverture : null;
  }
  /* Les jours ACTIFS d'un mois : le mois d'ouverture ne compte qu'à partir du
   * jour où la caisse a sonné. Corbais a ouvert un 21 juillet — répartir son
   * juillet sur 31 jours ferait valoir ce mois-là un tiers de ce qu'il vaut,
   * et tirerait toute l'année d'ouverture vers le bas. */
  function joursActifs(mois, ouv) {
    const n = joursDuMois(mois);
    if (!ouv) { return { deb: 1, n: n }; }
    const mo = ouv.slice(0, 7);
    if (mois < mo) { return { deb: 1, n: 0 }; }
    if (mois > mo) { return { deb: 1, n: n }; }
    const j = +ouv.slice(8, 10);
    return { deb: j, n: n - j + 1 };
  }

  function valeurMagasin() {
    const l = valoMois();
    if (!l) return null;
    const avec = l.filter(c => c.ca != null);
    if (!avec.length) return { n: 0 };
    // Fin de fenêtre : le dernier jour du dernier mois qui a des ventes.
    const dernier = avec[avec.length - 1].mois;
    const fin = new Date(Date.UTC(+dernier.slice(0, 4), +dernier.slice(5, 7) - 1, joursDuMois(dernier)));
    const deb = new Date(fin.getTime() - (VALO_JOURS - 1) * 86400000);
    const ouv = valoOuverture();
    let total = 0, jours = 0, mois = 0, creux = 0, premier = null;
    l.forEach(c => {
      const A = joursActifs(c.mois, ouv);
      if (A.n <= 0) { return; }
      const an = +c.mois.slice(0, 4), mo = +c.mois.slice(5, 7) - 1;
      const m0 = new Date(Date.UTC(an, mo, A.deb));
      const m1 = new Date(Date.UTC(an, mo, A.deb + A.n - 1));
      // Le chevauchement du mois avec la fenêtre, en jours actifs.
      const d0 = m0 > deb ? m0 : deb, d1 = m1 < fin ? m1 : fin;
      const cv = Math.round((d1 - d0) / 86400000) + 1;
      if (cv <= 0) { return; }
      if (c.ca == null) { creux += cv; return; }
      // Le taux journalier du mois se calcule sur ses jours ACTIFS.
      total += c.ca * cv / A.n;
      jours += cv; mois++;
      if (premier === null) { premier = c.mois; }
    });
    if (!jours) return { n: 0 };
    const quotidien = total / jours;
    return {
      // `jours` EST le diviseur : les jours d'activité de la fenêtre, donc au
      // plus 730. `creux` dit ce qui manque, pour qui veut la différence.
      n: mois, jours: jours, creux: creux,
      total: total, quotidien: quotidien,
      annuel: quotidien * VALO_AN, valeur: quotidien * VALO_AN / VALO_DIV,
      du: fD(iso(deb)) + '/' + iso(deb).slice(0, 4), au: fD(iso(fin)) + '/' + iso(fin).slice(0, 4),
      duMois: premier ? libMois(premier) : '', auMois: libMois(dernier)
    };
  }
  /* Année par année, à la même règle : le CA de l'année ramené au jour, puis à
   * l'année, puis divisé par six. Seules les années qui ont des ventes sont
   * rendues — une colonne vide n'apprend rien. L'année en cours y figure : le
   * diviseur étant ses jours vécus, elle dit le rythme du moment, pas un
   * demi-exercice. */
  function valoAnnees() {
    const l = valoMois();
    if (!l) return null;
    const par = {};
    l.filter(c => c.ca != null).forEach(c => {
      const k = c.mois.slice(0, 4);
      (par[k] = par[k] || []).push(c);
    });
    const cles = Object.keys(par).sort();
    if (!cles.length) return null;
    const ouv = valoOuverture();
    return cles.map(k => {
      const m = par[k];
      let ca = 0, jours = 0;
      m.forEach(c => { const A = joursActifs(c.mois, ouv); if (A.n <= 0) { return; } ca += c.ca; jours += A.n; });
      const quot = jours ? ca / jours : null;
      return { lib: k, n: m.length, ca: ca, jours: jours,
        ouverte: !!(ouv && ouv.slice(0, 4) === k),
        quotidien: quot, valeur: quot == null ? null : quot * VALO_AN / VALO_DIV };
    });
  }
  /** La courbe des années : les trous ne sont pas reliés, ils se voient. */
  function courbeValo(T) {
    const vs = T.map(o => o.valeur).filter(v => v != null);
    // La boîte est à la largeur réelle du bloc : étirée, un cercle deviendrait
    // une ellipse. Les abscisses tombent au centre des colonnes de dessous,
    // pour que chaque point soit au-dessus de son année.
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
    const A = valoAnnees();
    if (!A) return '<div class="vide">Pas encore d’année de ventes pour ce magasin.</div>';
    const cours = A[A.length - 1];
    return `<div class="db-vtri">
      <div class="hd">La valeur année par année — ce que le magasin aurait valu au rythme de chaque année.
        ${cours && cours.n < 12 ? '<b>' + esc(cours.lib) + ' n’a que ' + cours.n + ' mois</b> : sa valeur est le rythme de ces mois-là, ramené à l’année.' : ''}</div>
      ${courbeValo(A)}
      <div class="grN" style="--n:${A.length}">${A.map(o => `<div>
        <span class="q">${esc(o.lib)}</span>
        <span class="v">${o.valeur == null ? '—' : fE(o.valeur)}</span>
        <span class="s">${fE(o.ca)} de CA${o.ouverte ? ' · ouvert le ' + esc(fD(valoOuverture())) : (o.n < 12 ? ' · ' + o.n + ' mois sur 12' : '')}</span>
      </div>`).join('')}</div>
    </div>`;
  }

  /* La valeur tient sur la barre du haut : un mot, un chiffre, la courbe des
   * Tout le reste — la formule, la fenêtre, les six trimestres — attend dans
   * le tiroir. */
  function valoPastille() {
    const v = valeurMagasin();
    if (!v) return '<span class="db-valoc att">Valeur du magasin…</span>';
    const dr = S.valoOuvert ? '\u25b4' : '\u25be';
    if (!v.n) return `<button class="db-valoc" data-vdrop="1"><span class="k">Valeur</span><span class="v">—</span><span class="dr">${dr}</span></button>`;
    return `<button class="db-valoc${S.valoOuvert ? ' ouv' : ''}" data-vdrop="1" title="CA des ${VALO_JOURS} derniers jours ÷ ${VALO_JOURS} × ${VALO_AN} ÷ ${VALO_DIV}">
      <span class="k">Valeur</span><span class="v">${fE(v.valeur)}</span><span class="dr">${dr}</span></button>`;
  }
  /* Le tiroir : la formule en toutes lettres, puis les six trimestres. */
  function rendValeur() {
    if (!S.valoOuvert) return '';
    const v = valeurMagasin();
    if (!v || !v.n) {
      return `<div class="db-vdl seul"><div class="vide">${v ? 'Aucun mois complet de chiffre d’affaires relevé pour ce magasin.' : 'Lecture du chiffre d’affaires des ' + VALO_JOURS + ' derniers jours…'}</div></div>`;
    }
    return `<div class="db-vdl seul">
      <div class="hd"><b>${fE(v.valeur)}</b> — ${fE(v.total)} de chiffre d’affaires sur ${fN(v.jours)} jours,
        soit ${fU(v.quotidien)} par jour, ${fE(v.annuel)} sur l’année, ÷ ${VALO_DIV} : deux mois de chiffre d’affaires.
        La fenêtre va du ${esc(v.du)} au ${esc(v.au)} — ${esc(v.duMois)} à ${esc(v.auMois)}.
        ${v.jours < VALO_JOURS ? '<em class="att">' + fN(v.jours) + ' jours d’activité</em> dans la fenêtre — le diviseur est le nombre de jours vécus, pas ' + VALO_JOURS + ' : il le deviendra quand le magasin les aura.' : ''}</div>
      ${valoTiroir()}
    </div>`;
  }

  /** Le bloc du bureau : un bandeau qui se déplie sur les alertes. */
  function rendStock() {
    const E = stockEtat();
    if (E && !E.indispo && !E.n) { return ''; }        // pas d'inventaire : on se tait
    const al = E && !E.indispo ? E.alertes : 0;
    const bouton = pushBouton();
    const vieux = E && !E.indispo && E.vieux;
    const cls = al ? ' ko' : (vieux ? ' wa' : (E && !E.indispo ? ' ok' : ' mu'));
    const titre = al ? al + ' référence' + (al > 1 ? 's' : '') + ' sous le minimum'
      : (vieux ? 'Inventaire non recompté depuis ' + E.jours + ' jours'
        : (E && !E.indispo ? 'Stock au complet' : 'Stock'));
    return `<div class="db-stbar${cls}${S.stockOuvert ? ' ouv' : ''}">
      <span class="ic" data-stdrop="1">${al ? '📦' : (vieux ? '⏳' : (E && !E.indispo ? '✓' : '·'))}</span>
      <span class="t" data-stdrop="1">${titre}<small>${stockSous(E)}</small></span>
      ${al ? `<span class="chips" data-stdrop="1">${E.ruptures ? `<span class="ch ko">${E.ruptures} à zéro</span>` : ''}${E.negatifs ? `<span class="ch ko">${E.negatifs} négatif${E.negatifs > 1 ? 's' : ''}</span>` : ''}</span>` : ''}
      <span class="sp"></span>${bouton}
      ${E && !E.indispo ? `<span class="dr" data-stdrop="1">${S.stockOuvert ? 'replier ▴' : 'la liste ▾'}</span>` : ''}
    </div>${S.stockOuvert && E && !E.indispo ? `<div class="db-stdl">${stockTiroir(E)}</div>` : ''}`;
  }

  /* --- Le stock, vivant ----------------------------------------------------
   * L'inventaire matière du magasin, relu avec la page. Une référence est en
   * alerte quand son stock est NÉGATIF (écart de caisse ou de comptage) ou
   * sous le minimum journalier : les deux appellent un geste. Une référence
   * jamais comptée n'est pas « à zéro », elle est absente — le serveur ne la
   * rend pas, l'écran ne l'invente pas. */
  const STOCK_VIEUX = 7;          // au-delà, l'inventaire n'est plus un état, c'est un souvenir
  function stockEtat() {
    const d = S.aux['stock|' + S.shop];
    if (!d) { return null; }
    if (d.indispo) { return { indispo: true, motif: d.motif || 'panel injoignable' }; }
    const L = Array.isArray(d.lignes) ? d.lignes : [];
    // « Zéro alerte » sur un inventaire non recompté depuis trois semaines
    // n'est pas une bonne nouvelle, c'est une inconnue : on compte les jours
    // et l'écran ne dit « au complet » que si le comptage est frais.
    const der = d.dernierComptage || '';
    const jours = der ? Math.floor((Date.now() - new Date(der.replace(' ', 'T')).getTime()) / 86400000) : null;
    return { n: d.references || L.length, alertes: d.alertes || 0, ruptures: d.ruptures || 0,
      negatifs: d.negatifs || 0, dernier: der, jours: jours, vieux: jours != null && jours > STOCK_VIEUX,
      quand: d.quand || '', lignes: L };
  }

  const fQ = (v, u) => (Math.abs(v) >= 100 ? nf(v, 0) : nf(v, v % 1 ? 2 : 0)) + (u ? ' ' + u : '');

  /** Le tableau des alertes : ce qui manque, et de combien. */
  /** La liste du stock du magasin, dans sa liste déroulante (06/10/2026) : les références en
   * alerte d'abord, puis tout l'inventaire rangé par catégorie, dans un cadre qui défile. */
  function stockTiroir(E) {
    const L = E.lignes.slice();
    const A = L.filter(x => x.alerte).sort((a, b) => (a.stock < 0 ? 0 : 1) - (b.stock < 0 ? 0 : 1) || String(a.categorie || '').localeCompare(String(b.categorie || '')) || String(a.ref || '').localeCompare(String(b.ref || '')));
    const R = L.filter(x => !x.alerte).sort((a, b) => String(a.categorie || '').localeCompare(String(b.categorie || '')) || String(a.ref || '').localeCompare(String(b.ref || '')));
    if (!L.length) { return '<div class="db-stvide">L’inventaire ne rend aucune référence.</div>'; }
    const ligne = x => `<div class="tr${x.stock < 0 ? ' neg' : ''}">
        <span class="r">${esc(x.ref)}</span><span class="c">${esc(x.categorie)}</span>
        <span class="v ${x.alerte ? (x.stock <= 0 ? 'ko' : 'wa') : ''}">${fQ(x.stock, x.unite)}</span>
        <span class="v mu">${x.mini ? fQ(x.mini, x.unite) : '—'}</span>
        <span class="v">${x.manque ? '− ' + fQ(x.manque, x.unite) : '—'}</span>
        <span class="c mu">${esc(x.modif ? fD(x.modif.slice(0, 10)) : '—')}</span></div>`;
    let cat = null, corps = '';
    if (A.length) { corps += `<div class="grp ko">En alerte · ${fN(A.length)}</div>` + A.map(ligne).join(''); }
    R.forEach(x => { if (x.categorie !== cat) { cat = x.categorie; corps += `<div class="grp">${esc(cat || 'Sans catégorie')} · ${fN(R.filter(y => y.categorie === cat).length)}</div>`; } corps += ligne(x); });
    return `<div class="db-stt"><div class="db-stdef"><div class="th"><span>Référence</span><span>Catégorie</span><span>Stock</span><span>Minimum</span><span>Manque</span><span>Compté le</span></div>
      ${corps}</div></div>
      <div class="db-stvide">${fN(L.length)} référence${L.length > 1 ? 's' : ''} · ${A.length ? fN(A.length) + ' en alerte' : 'aucune sous son minimum'}${E.dernier ? ' · dernier comptage le ' + esc(fD(E.dernier.slice(0, 10))) + ' à ' + esc(E.dernier.slice(11, 16)) : ''}</div>`;
  }

  /* --- L'abonnement aux notifications ---------------------------------------
   * Le navigateur s'inscrit auprès de son service de push (Google, Mozilla,
   * Apple), qui lui rend une adresse et deux clés. On les dépose au serveur :
   * c'est lui qui enverra, même application fermée, même téléphone rangé.
   *
   * Trois conditions, et l'écran dit laquelle manque : un contexte sécurisé
   * (HTTPS), un service worker, et la permission de l'utilisateur. */
  function pushDisponible() {
    return ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window);
  }

  async function pushEtatLire() {
    if (!pushDisponible()) {
      S.pushEtat = 'impossible';
      S.pushMotif = window.isSecureContext === false
        ? 'les notifications demandent une connexion sécurisée (https)'
        : 'ce navigateur ne sait pas recevoir de notifications';
      return;
    }
    if (Notification.permission === 'denied') { S.pushEtat = 'refuse'; S.pushMotif = 'notifications bloquées dans les réglages du navigateur'; return; }
    try {
      const reg = await navigator.serviceWorker.getRegistration('./');
      const ab = reg ? await reg.pushManager.getSubscription() : null;
      S.pushEtat = ab ? 'abonne' : 'possible';
    } catch (e) { S.pushEtat = 'possible'; }
  }

  const pushOctets = b64 => {
    const p = '='.repeat((4 - b64.length % 4) % 4);
    const t = atob((b64 + p).replace(/-/g, '+').replace(/_/g, '/'));
    const u = new Uint8Array(t.length);
    for (let i = 0; i < t.length; i++) { u[i] = t.charCodeAt(i); }
    return u;
  };

  async function pushBasculer() {
    if (S.pushOccupe) { return; }
    S.pushOccupe = true; rendre();
    try {
      if (!pushDisponible()) { await pushEtatLire(); return; }
      const reg = await navigator.serviceWorker.register('sw.js', { scope: './' });
      await navigator.serviceWorker.ready;
      const dejaLa = await reg.pushManager.getSubscription();
      if (dejaLa) {
        await fetch(API + '/push/abonnements', { method: 'DELETE', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: dejaLa.endpoint }) }).catch(() => {});
        await dejaLa.unsubscribe().catch(() => {});
        S.pushEtat = 'possible';
        return;
      }
      const perm = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
      if (perm !== 'granted') { S.pushEtat = 'refuse'; S.pushMotif = 'permission refusée'; return; }
      const c = await lire('/push/cle');
      if (!c || !c.pret) { S.pushEtat = 'impossible'; S.pushMotif = (c && c.motif) || 'le serveur n’est pas prêt'; return; }
      const ab = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: pushOctets(c.cle) });
      const j = ab.toJSON();
      const r = await fetch(API + '/push/abonnements', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ shop: S.shop, endpoint: ab.endpoint, p256dh: j.keys.p256dh, auth: j.keys.auth }) });
      if (!r.ok) { await ab.unsubscribe().catch(() => {}); S.pushEtat = 'impossible'; S.pushMotif = 'le serveur a refusé l’abonnement'; return; }
      S.pushEtat = 'abonne'; S.pushMotif = '';
    } catch (e) {
      S.pushEtat = 'impossible'; S.pushMotif = e && e.message ? e.message : 'abonnement impossible';
    } finally { S.pushOccupe = false; rendre(); }
  }

  /** Un message d'essai, pour vérifier que la chaîne va jusqu'au bout. */
  async function pushEssai() {
    S.pushOccupe = true; rendre();
    try {
      const r = await fetch(API + '/push/essai', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ shop: S.shop }) });
      const d = await r.json().catch(() => null);
      S.pushMotif = d && d.envoyes ? 'essai envoyé à ' + d.envoyes + ' appareil' + (d.envoyes > 1 ? 's' : '')
        : (d && d.erreurs && d.erreurs.length ? 'essai refusé : ' + d.erreurs[0] : 'aucun appareil abonné');
    } catch (e) { S.pushMotif = 'essai impossible'; }
    finally { S.pushOccupe = false; rendre(); }
  }

  /** Le libellé du bouton, qui dit toujours où on en est. */
  function pushBouton() {
    if (S.pushEtat === 'impossible' || S.pushEtat === 'refuse') {
      return `<span class="db-stnote" title="${esc(S.pushMotif)}">🔕 ${esc(S.pushMotif || 'notifications indisponibles')}</span>`;
    }
    const occ = S.pushOccupe ? ' disabled' : '';
    if (S.pushEtat === 'abonne') {
      return `<button class="db-btn db-stav on" data-stav="1"${occ}>🔔 averti sur cet appareil</button>`
        + `<button class="db-btn db-stav" data-stessai="1"${occ}>Essai</button>`
        + (S.pushMotif ? `<span class="db-stnote">${esc(S.pushMotif)}</span>` : '');
    }
    return `<button class="db-btn db-stav" data-stav="1"${occ}>${S.pushOccupe ? 'abonnement…' : 'M’avertir'}</button>`;
  }

  /* Le serveur avertit même application fermée, mais son passage a lieu toutes
   * les quinze minutes. Tant que la page est ouverte, elle relit le stock
   * toutes les dix minutes : autant le dire tout de suite. */
  function stockAvertirSiNouveau(E) {
    if (!E || E.indispo) { return; }
    const set = {};
    E.lignes.forEach(x => { if (x.alerte) { set[x.ref] = 1; } });
    const avant = S.stockVues;
    S.stockVues = set;
    if (!avant || S.pushEtat !== 'abonne') { return; }  // première lecture : rien à annoncer
    const neufs = Object.keys(set).filter(r => !avant[r]);
    if (!neufs.length || !('Notification' in window) || Notification.permission !== 'granted') { return; }
    try {
      new Notification(nomShop() + ' — stock', {
        body: neufs.length === 1 ? neufs[0] + ' vient de passer sous son minimum.'
          : neufs.length + ' références viennent de passer sous leur minimum : ' + neufs.slice(0, 3).join(', ')
            + (neufs.length > 3 ? '…' : ''),
        tag: 'stock-' + S.shop, icon: '../assets/img/logo.png'
      });
    } catch (e) { /* le navigateur refuse : tant pis, l'écran le dit déjà */ }
  }

  function stockSous(E) {
    if (!E) { return 'lecture de l’inventaire…'; }
    if (E.indispo) { return esc(E.motif); }
    return E.n + ' référence' + (E.n > 1 ? 's' : '') + ' à l’inventaire'
      + (E.dernier ? ' · dernier comptage le ' + fD(E.dernier.slice(0, 10))
          + (E.jours != null ? ', il y a ' + (E.jours === 0 ? 'moins d’un jour' : E.jours + ' jour' + (E.jours > 1 ? 's' : '')) : '') : '');
  }

  /* --- Le dashboard au téléphone -------------------------------------------
   * La même page et les mêmes lectures : seul le rendu change sous 560 px.
   * Deux vues seulement, le jour et la semaine — le mois, le trimestre et
   * l'année restent au bureau, ils ne se lisent pas au pouce.
   * La forme : le chiffre de la période en grand, ce qui bloque juste en
   * dessous, puis des cartes ouvertes qu'un seul défilement parcourt. */
  const MOB_MAX = 560;
  function estMobile() {
    if (EMBED) { return false; }
    const f = new URLSearchParams(location.search).get('mobile');
    if (f === '1') { return true; }
    if (f === '0') { return false; }
    return window.innerWidth <= MOB_MAX;
  }

  /** Le dashboard magasin ne montre que les tâches OBLIGATOIRES : les
   * contrôles facultatifs (formation cuisine CQ-F…) restent dans Contrôle des
   * tâches du cockpit. Une tâche dont le panel ne dit rien est gardée. */
  const tacheObligatoire = t => t.obligatoire !== false;
  /** Une tâche est faite quand elle est rendue AVEC sa photo (06/10/2026) : cochée sans photo,
   * elle ne prouve rien et ne compte plus comme rendue. Elle n'est pas « pas rendue » non plus :
   * elle se compte à part, « cochée sans photo ». */
  const tacheFaite = t => t.statut !== 'nonRendue' && t.statut !== 'sansPhoto';
  const tacheSansPhoto = t => t.statut === 'sansPhoto';
  /** Les tâches obligatoires du magasin pour la journée regardée. */
  function tachesJour(d) {
    const sh = d ? (d.shops || []).find(x => String(x.shopId) === String(S.shop)) : null;
    return sh ? (sh.taches || []).filter(tacheObligatoire) : [];
  }

  /** Les tâches de la vue, comptées comme dans le bloc du bureau. */
  function mobTaches() {
    if (S.vue !== 'jour') {
      const [du, au] = bornes();
      const p = S.aux['tachesP|' + du + '|' + au];
      if (!p) { return null; }
      // La heatmap rend des LIGNES par magasin : faites / pasFaites, pas un
      // total. Le compte des bloquantes n'y est pas — il ne vaut qu'au jour.
      const sh = (p.lignes || []).find(x => String(x.shopId) === String(S.shop));
      if (!sh) { return { total: 0 }; }
      const f = sh.faites || 0, nf = sh.pasFaites || 0;
      const jours = (sh.jours || []).filter(j => j.releve).length;
      return { total: f + nf, faites: f, nonFaites: nf, bloquantes: null, jours: jours };
    }
    const d = S.aux['taches|' + S.date];
    if (!d) { return null; }
    const T = tachesJour(d);
    if (!T.length) { return { total: 0 }; }
    const faite = tacheFaite;
    const bloq = t => t.statut === 'nonRendue' && (t.obligatoire != null ? !!t.obligatoire : /^CO-/i.test(String(t.checklist || '')));
    const nF = T.filter(faite).length;
    return { total: T.length, faites: nF, nonFaites: T.length - nF, bloquantes: T.filter(bloq).length };
  }

  /* Une cellule du mur : une étiquette, un chiffre, une ligne d'explication.
   * `ouvre` porte le nom de l'attribut qui déplie le tiroir correspondant. */
  /** Les clients de J−7 au même moment : le delta à côté du nombre, et la phrase qui dit d'où il vient. */
  function j7Delta(m) {
    const J = m && m.j7; if (!J || m.tickets == null || J.tickets == null) { return ''; }
    const d = m.tickets - J.tickets, cls = d > 0 ? 'ok' : (d < 0 ? 'ko' : 'mu');
    return ` <small class="db-j7 ${cls}" title="clients à J−7 (${fD(J.date)})${J.moment ? ' à ' + J.moment : ''} : ${fN(J.tickets)}">${d > 0 ? '+' : (d < 0 ? '−' : '=')}${d ? fN(Math.abs(d)) : ''}</small>`;
  }
  function j7Texte(m) {
    const J = m && m.j7; if (!J || J.tickets == null) { return ''; }
    const pct = J.tickets > 0 && m.tickets != null ? Math.round(100 * (m.tickets - J.tickets) / J.tickets) : null;
    return fN(J.tickets) + ' client' + (J.tickets > 1 ? 's' : '') + ' à J−7' + (J.moment ? ' à la même heure' : '') + ' (' + JOURS_C[new Date(J.date + 'T12:00:00').getDay()] + ' ' + fD(J.date) + ')' + (pct != null && pct !== 0 ? ' · ' + (pct > 0 ? '+ ' : '− ') + Math.abs(pct) + ' %' : '');
  }
  function murC(k, v, s, cls, ouvre, apres) {
    return `<div${ouvre ? ` data-${ouvre}="1" data-ouvre="1"` : ''}><div class="k">${k}</div>`
      + `<div class="v ${cls || ''}">${v}</div>`
      + (s ? `<div class="s">${s}</div>` : '') + (apres || '') + '</div>';
  }
  const murR = (cells, un) => { const c = cells.filter(Boolean); return c.length ? `<div class="mb-r${un ? ' un' : ''}">${c.join('')}</div>` : ''; };
  const murJauge = (pct, coul) => `<div class="mb-jauge"><i style="width:${Math.max(0, Math.min(100, pct || 0)).toFixed(1)}%${coul ? ';background:' + coul : ''}"></i></div>`;

  /** Les sept barres de la semaine : la hauteur dit l'atteinte, pas le chiffre —
   * sinon le dimanche écrase le lundi et on ne voit plus qui a manqué. */
  function murSemaine(J) {
    return `<div class="mb-sem">${J.map(j => {
      const att = (j.ca != null && j.objectif) ? j.ca / j.objectif : null;
      const h = att == null ? 3 : Math.max(3, Math.round(Math.min(1.2, att) * 26));
      const c = att == null ? 'var(--color-border-secondary)' : (att >= 1 ? '#2d7a3e' : 'var(--color-primary)');
      return `<div><i style="height:${h}px;background:${c}"></i><span>${esc(j.court || fD(j.date))}</span></div>`;
    }).join('')}</div>`;
  }

  /**
   * Les clients manquants du jour : au comptoir quand le serveur les a comptés
   * ((CA comptoir − objectif) ÷ panier comptoir, le CA des clients pro exclu),
   * sinon sur le CA total. n > 0 = il en manque.
   */
  function clientsJour(m) {
    if (!m || !m.objectifJour) { return null; }
    if (m.clientsBase === 'comptoir' && m.clientsManquants != null) { return { n: m.clientsManquants, ecart: m.ecartComptoir, panier: m.panierComptoir, comptoir: true }; }
    if (!(m.panier > 0)) { return null; }
    return { n: Math.round((m.objectifJour - m.ca) / m.panier), ecart: m.ca - m.objectifJour, panier: m.panier, comptoir: false };
  }
  /** Le grand chiffre de la période regardée, avec sa jauge d'atteinte. */
  function murPeriode(m) {
    if (S.vue === 'jour') {
      const obj = m && m.objectifJour;
      const att = obj ? 100 * m.ca / obj : 0;
      const ecart = obj ? m.ca - obj : null;
      const CJ = clientsJour(m), cl = CJ ? Math.abs(CJ.n) : null;
      // L'animation dure trois secondes ; la nouvelle, elle, doit tenir toute
      // la journée — sinon qui ouvre l'écran à 18 h ne sait pas que c'est fait.
      return murC('Chiffre d’affaires du jour' + (obj && m.ca >= obj ? ' <em class="ok">· objectif atteint</em>' : ''),
        fE(m ? m.ca : null),
        obj ? `objectif ${fE(obj)} · ${fP(att)} · <span class="${ecart >= 0 ? 'ok' : 'ko'}">${fS(ecart)}</span>`
              + (cl ? ` · ${fN(cl)} client${cl > 1 ? 's' : ''}${CJ.comptoir ? ' comptoir' : ''} ${CJ.n <= 0 ? 'd’avance' : 'de moins'}` : '')
            : 'pas d’objectif du jour',
        '', '', obj ? murJauge(att, att >= 100 ? '#2d7a3e' : '') : '');
    }
    const realise = m ? (m.realise != null ? m.realise : m.ca) : null;
    const att = m && m.attendu ? 100 * (realise || 0) / m.attendu : 0;
    const av = m && m.ecart != null && m.ecart >= 0;
    const J = m && Array.isArray(m.jours) ? m.jours : null;
    // L'en-tête de la page porte déjà « semaine du 14/09 au 20/09 » : le
    // répéter ici ne dirait rien de plus.
    return murC('Chiffre d’affaires de la semaine' + (m && m.objectif > 0 && realise >= m.objectif ? ' <em class="ok">· objectif atteint</em>' : ''),
      fE(realise),
      m && m.attendu != null
        ? `attendu à ce jour ${fE(m.attendu)} · <span class="${av ? 'ok' : 'ko'}">${fS(m.ecart)}</span>`
          + (m.clientsManquants ? ` · ${fN(Math.abs(m.clientsManquants))} clients ${m.clientsManquants > 0 ? 'manquants' : 'd’avance'}` : '')
        : (m && m.objectif ? 'objectif ' + fE(m.objectif) : ''),
      '', '', (m && m.attendu ? murJauge(att, av ? '#2d7a3e' : '') : '') + (J ? murSemaine(J) : ''));
  }

  /* Ta place dans le réseau, au téléphone : trois colonnes — chiffre
   * d'affaires, clients, panier moyen — le rang, la jauge du réseau (les
   * autres magasins en gris, la médiane en trait), la valeur et l'écart à la
   * médiane. Les mêmes calculs que le bandeau du bureau (rendBench), anonymes :
   * on ne nomme jamais les autres. Au jour et à la semaine. */
  function murClassement(m, d) {
    if (!d) { return murR([murC('Ta place dans le réseau', '…', 'lecture du réseau…')], true); }
    const L = (d.magasins || []).filter(x => x.ouvert !== false);
    if (!m || L.length < 2) { return ''; }
    const jour = S.vue === 'jour';
    const defs = [['Chiffre d’affaires', jour ? 'ca' : 'realise', fK, v => fSK(v)], ['Clients', 'tickets', fN, v => (v >= 0 ? '+' : '−') + fN(Math.abs(v))], ['Panier moyen', 'panier', fU, v => (v >= 0 ? '+ ' : '− ') + fU(Math.abs(v))]];
    const ord = n => n === 1 ? '1er' : n + 'e';
    let premiers = 0;
    const cols = defs.map(([lib, k, f, fd]) => {
      const vals = L.map(x => x[k]).filter(v => v != null && isFinite(v)).sort((a, b) => b - a);
      const v = m[k];
      if (v == null || !vals.length) { return `<div class="mb-cl"><div class="k">${lib}</div><div class="rg mu">—</div><div class="v">—</div><div class="s">pas de valeur</div></div>`; }
      const n = vals.length, rang = vals.findIndex(x => x <= v) + 1;
      const med = vals[Math.floor((n - 1) / 2)];
      const top = rang === 1, bas = rang === n && n > 1;
      if (top) { premiers++; }
      const mn = vals[n - 1], mx = vals[0], ecart = mx - mn;
      const pos = x => ecart > 0 ? Math.max(0, Math.min(100, 100 * (x - mn) / ecart)) : 50;
      const autres = vals.filter((x, i) => i !== rang - 1).map(x => `<span class="pt" style="left:${pos(x).toFixed(1)}%"></span>`).join('');
      return `<div class="mb-cl"><div class="k">${lib}</div><div class="rg${top ? ' top' : (bas ? ' bas' : '')}">${top ? '🏆 ' : ''}${ord(rang)}<small>/ ${n}</small></div>
        <div class="jg"><i class="l"></i>${autres}<span class="md" style="left:${pos(med).toFixed(1)}%"></span><span class="mo${top ? ' top' : ''}" style="left:${pos(v).toFixed(1)}%"></span></div>
        <div class="v">${f(v)}</div><div class="s"><span class="${v - med >= 0 ? 'ok' : 'ko'}">${fd(v - med)}</span> vs médiane ${f(med)}</div></div>`;
    }).join('');
    return `<div class="mb-r un mb-cls"><div><div class="k">Ta place dans le réseau <em>· ${L.length} magasins · ${jour ? 'la journée' : 'la semaine'} · anonyme${premiers ? ' · ' + premiers + ' × 🏆' : ''}</em></div><div class="mb-clg">${cols}</div></div></div>`;
  }

  /** La semaine vue depuis le jour : le retard, et les sept barres. */
  function murSemaineResume() {
    const d = S.aux['sem|' + bornesSemaine()[0]];
    const m = d && Array.isArray(d.magasins) ? d.magasins.find(x => String(x.shopId) === String(S.shop)) : null;
    if (!m) { return murC('La semaine', '…', 'lecture de la semaine…'); }
    const av = m.ecart != null && m.ecart >= 0;
    const J = Array.isArray(m.jours) ? m.jours : null;
    const arret = J ? (J.filter(j => j.passe).slice(-1)[0] || null) : null;
    return murC(`La semaine${arret ? ' · au ' + esc(fD(arret.date)) : ''}`,
      m.ecart == null ? fE(m.realise) : fS(m.ecart), m.ecart == null ? '' :
        `${fE(m.realise)} contre ${fE(m.attendu)} attendus`
        + (m.clientsManquants ? ` · ${fN(Math.abs(m.clientsManquants))} clients ${m.clientsManquants > 0 ? 'manquants' : 'd’avance'}` : ''),
      m.ecart == null ? '' : (av ? 'ok' : 'ko'), '', J ? murSemaine(J) : '');
  }

  /* Les non-conformités, en une cellule du mur : le nombre ouvert, et ce qui
   * l'explique en une ligne. Le tiroir du bureau s'ouvre dessous. */
  function murNC() {
    const cle = cleNC(), D = S.aux[cle], f = ncFenetre();
    const quand = f.jour ? 'hier' : (S.vue === 'semaine' ? 'cette semaine' : 'ce mois');
    if (S.err[cle]) { return murC('Non-conformités', '—', esc(S.err[cle])); }
    if (!D) { return murC('Non-conformités', '…', 'lecture du panel…'); }
    if (D.indispo) { return murC('Non-conformités', '—', esc(D.motif || 'indisponible')); }
    const L = ncLignes();
    if (!L.length) {
      return murC('Non-conformités', D.notees ? '0' : '—',
        D.notees ? D.notees + ' tâche' + (D.notees > 1 ? 's' : '') + ' notée' + (D.notees > 1 ? 's' : '') + ' ' + quand + ', rien à reprendre'
                 : 'aucun contrôle consigné ' + (f.jour ? 'ce jour-là' : 'sur cette période'),
        D.notees ? 'ok' : '', 'ncdrop');
    }
    const ouverts = L.filter(x => x.etat.c !== 'ok').length;
    return murC('Non-conformités', String(L.length),
      `${esc(quand)} · ${ouverts ? `<span class="ko">${ouverts} à reprendre</span> · ` : ''}${D.notees} notée${D.notees > 1 ? 's' : ''}`,
      ouverts ? 'wa' : 'ok', 'ncdrop');
  }

  /* --- Commandes clients et livraisons --------------------------------------
   * Deux cellules du mur, une seule lecture. Zéro n'est pas une panne : quand
   * il n'y a rien en cours, la cellule dit la date de la dernière — sinon on
   * ne sait pas distinguer « rien à faire » de « l'écran ne lit plus rien ».
   * Le retard de retrait passe devant : une commande payée que personne n'est
   * venu chercher coûte deux fois. */
  function cmdEtat() { return S.aux['cmd|' + S.shop] || null; }
  const cmdHeure = q => q ? q.slice(11, 16).replace(':', ' h ') : '';
  /** La copie de la base s'arrête parfois : le dire vaut mieux que de faire
   * passer un gel pour un calme. Vide quand elle suit. */
  function cmdGel(D) {
    const c = D && D.copie;
    return (c && c.arretee && c.retard > 2) ? 'copie arrêtée le ' + esc(fD(c.arretee.slice(0, 10))) : '';
  }

  function murCommandes() {
    const err = S.err['cmd|' + S.shop];
    if (err) { return murC('Commandes clients', '—', esc(err)); }
    const D = cmdEtat();
    if (!D) { return murC('Commandes clients', '…', 'lecture des commandes…'); }
    const C = D.commandes;
    if (!C || C.indispo) { return murC('Commandes clients', '—', C ? esc(C.motif) : 'indisponible'); }
    const gel = C.source === 'base' ? cmdGel(D) : '';
    if (!C.enCours) {
      return murC('Commandes clients', '0',
        (C.derniere ? 'dernière le ' + esc(fD(C.derniere.slice(0, 10))) : 'aucune commande enregistrée')
          + (gel ? ' · ' + gel
            : (C.dormantes ? ' · ' + C.dormantes + ' fiche' + (C.dormantes > 1 ? 's' : '') + ' jamais clôturée' + (C.dormantes > 1 ? 's' : '') : '')),
        gel ? 'wa' : '', 'cmddrop');
    }
    const bouts = [];
    if (C.auj) { bouts.push(`<span class="ok">${C.auj} à retirer aujourd’hui</span>`); }
    if (C.retard) { bouts.push(`<span class="ko">${C.retard} en retard</span>`); }
    if (C.aVenir) { bouts.push(C.aVenir + ' à venir'); }
    if (C.montant) { bouts.push(fE(C.montant)); }
    return murC('Commandes clients', fN(C.enCours), bouts.join(' · '),
      C.retard ? 'ko' : (C.auj ? 'ok' : ''), 'cmddrop');
  }

  function murLivraisons() {
    const D = cmdEtat();
    if (S.err['cmd|' + S.shop]) { return murC('Livraisons', '—', 'lecture impossible'); }
    if (!D) { return murC('Livraisons', '…', 'lecture des livraisons…'); }
    const L = D.livraisons;
    if (!L || L.indispo) { return murC('Livraisons', '—', L ? esc(L.motif) : 'indisponible'); }
    // Les livraisons n'ont aucune route côté panel : elles viennent toujours
    // de la copie. Quand celle-ci est gelée, sa date passe devant le reste.
    const gel = cmdGel(D);
    if (!L.enRoute) {
      return murC('Livraisons', '0',
        gel || (L.derniere ? 'dernière reçue le ' + esc(fD(L.derniere.slice(0, 10))) : 'aucune commande fournisseur'),
        gel ? 'wa' : '', 'cmddrop');
    }
    const p = L.prochaine ? L.prochaine.slice(0, 10) : null;
    const tard = p && p < AUJ;
    return murC('Livraisons', fN(L.enRoute),
      (p ? (tard ? `<span class="ko">attendue le ${esc(fD(p))}</span>` : 'attendue le ' + esc(fD(p))) : 'sans date annoncée')
        + ' · ' + (gel || (L.derniere ? 'dernière reçue le ' + esc(fD(L.derniere.slice(0, 10))) : '')),
      tard ? 'ko' : 'wa', 'cmddrop');
  }

  /** Le tiroir : les commandes à retirer d'abord, les livraisons ensuite. */
  function cmdTiroir() {
    const D = cmdEtat();
    if (!D) { return '<div class="db-stvide">lecture en cours…</div>'; }
    const C = D.commandes || {}, L = D.livraisons || {};
    // Le panel ne joint pas le détail à la liste : quand aucune ligne ne sait
    // son nombre d'articles, la colonne disparaît plutôt que d'aligner des
    // tirets.
    const art = (C.lignes || []).some(l => l.articles != null);
    let h = `<div class="db-cmdt${art ? '' : ' sansArt'}">`;
    h += `<div class="th"><span>À retirer</span>${art ? '<span>Articles</span>' : ''}<span>Montant</span></div>`;
    if (!(C.lignes || []).length) {
      h += `<div class="db-stvide">Aucune commande en cours${C.derniere ? ' — la dernière était à retirer le ' + esc(fD(C.derniere.slice(0, 10))) : ''}.
        ${C.dormantes ? `<br>${C.dormantes} fiche${C.dormantes > 1 ? 's' : ''} de plus de ${C.fenetre} jours n’${C.dormantes > 1 ? 'ont' : 'a'} jamais été clôturée${C.dormantes > 1 ? 's' : ''} : la remise n’y a pas été enregistrée. Elles ne comptent pas comme des commandes en attente.` : ''}</div>`;
    } else {
      C.lignes.forEach(l => {
        const j = l.quand ? l.quand.slice(0, 10) : null;
        const tard = j && j < AUJ;
        h += `<div class="tr${tard ? ' neg' : ''}"><span class="r">${esc(j ? fD(j) : '—')}${l.quand ? ' · ' + esc(cmdHeure(l.quand)) : ''}</span>
          ${art ? `<span class="n">${l.articles == null ? '—' : fN(l.articles)}</span>` : ''}<span class="n ${tard ? 'ko' : ''}">${fE(l.montant)}</span></div>`;
      });
    }
    h += '</div><div class="db-cmdt" style="margin-top:12px">';
    h += '<div class="th"><span>Livraison en route</span><span>Réfs</span><span>Attendue</span></div>';
    if (!(L.lignes || []).length) {
      h += `<div class="db-stvide">Aucune livraison en route${L.derniere ? ' — la dernière est arrivée le ' + esc(fD(L.derniere.slice(0, 10))) : ''}.</div>`;
    } else {
      L.lignes.forEach(l => {
        const a = l.attendue ? l.attendue.slice(0, 10) : null;
        const tard = a && a < AUJ;
        h += `<div class="tr${tard ? ' neg' : ''}"><span class="r">${esc(l.fournisseur || 'Fournisseur')}</span>
          <span class="n">${fN(l.refs)}</span><span class="n ${tard ? 'ko' : 'mu'}">${a ? esc(fD(a)) : '—'}</span></div>`;
      });
    }
    if (cmdGel(D)) {
      h += `<div class="db-stvide">Le panel n’a pas de route pour les livraisons : elles se lisent dans la copie de la base, et cette copie est arrêtée depuis le ${esc(fD(D.copie.arretee.slice(0, 10)))}, soit ${D.copie.retard} jours. Ce qui est affiché ici est l’état de ce jour-là.</div>`;
    }
    h += '</div>';
    return h;
  }

  /* --- L'objectif atteint ---------------------------------------------------
   * Au jour, c'est le budget du jour ; à la semaine, l'objectif de la semaine
   * entière — et non l'attendu à ce jour, qui ne dit que « dans les temps ».
   * Un objectif de semaine ne tombe qu'un dimanche : c'est bien un événement. */
  function objectifAtteint(m) {
    if (!m) { return false; }
    if (S.vue === 'jour') { return m.objectifJour > 0 && m.ca >= m.objectifJour; }
    const fait = m.realise != null ? m.realise : m.ca;
    return m.objectif > 0 && fait != null && fait >= m.objectif;
  }
  /* Une fois par ouverture de la page, pour un magasin, une vue et une date
   * donnés : la page se relit toute seule toutes les dix minutes, et des
   * confettis toutes les dix minutes ne sont plus une fête, c'est une alarme.
   * Mais rouvrir l'écran refête — c'est le moment où l'on veut la voir, et
   * une mémoire qui traverse la journée ne montrerait la nouvelle qu'à celui
   * qui a ouvert l'application au bon instant.
   *
   * Le nom ne dit pas « confettis » : `confettis(n)` existe déjà plus bas et
   * rend une chaîne de papiers pour la carte du bureau. Deux fonctions de
   * même nom, et JavaScript garde la dernière — celle-ci n'aurait jamais
   * tourné. */
  let _fete = '';
  function feteFaite() {
    const cle = S.shop + '|' + S.vue + '|' + S.date;
    if (_fete === cle) { return true; }
    _fete = cle;
    return false;
  }
  /** `?fete=1` force la fête une fois, pour la voir sans attendre un bon jour. */
  let _feteForcee = false;
  function feteDemandee() {
    if (_feteForcee) { return false; }
    if (new URLSearchParams(location.search).get('fete') !== '1') { return false; }
    _feteForcee = true;
    return true;
  }
  function feteObjectif(force) {
    if (!force && feteFaite()) { return; }
    // Qui a demandé moins d'animations n'en reçoit pas : la nouvelle est déjà
    // dans la jauge, qui passe au vert.
    try {
      if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
    } catch (e) { /* vieux navigateur */ }
    const COUL = ['#C0182B', '#2d7a3e', '#e2b93b', '#8a6508', '#78554B', '#f7f3ec'];
    const n = document.createElement('div');
    n.className = 'mb-confi';
    let h = '';
    for (let i = 0; i < 44; i++) {
      const l = (Math.random() * 100).toFixed(1);
      const w = (5 + Math.random() * 5).toFixed(1);
      const dur = (1500 + Math.random() * 1100).toFixed(0);
      const ret = (Math.random() * 700).toFixed(0);
      const rot = (Math.random() * 900 - 450).toFixed(0);
      h += `<i style="left:${l}%;width:${w}px;height:${(w * 1.8).toFixed(1)}px;background:${COUL[i % COUL.length]};animation-duration:${dur}ms;animation-delay:${ret}ms;--r:${rot}deg"></i>`;
    }
    n.innerHTML = h;
    document.body.appendChild(n);
    setTimeout(() => { n.remove(); }, 3600);
  }

  /* --- Le téléphone en trois onglets (demande du 04/10/2026, maquette A « les feux ») -----------
   * Exploitation (le chiffre, les marges, les clients) et Contrôle (les photos, les contrôles, le
   * stock, les commandes) lisent le jour ; Semaine lit la semaine, jour par jour, en damier.
   * Chaque tuile porte une pastille : vert, orange, rouge, gris tant qu'on ne peut pas juger (une
   * journée en cours, une lecture qui n'est pas revenue). La barre du bas porte la couleur de
   * chaque onglet : le premier coup d'œil lit les couleurs, le second les seules tuiles qui ne
   * sont pas vertes. La réclamation fournisseur devient un bouton de l'onglet Contrôle.
   * Les seuils sont réunis ici. */
  const MA = {
    ca: p => p == null ? 'n' : (p >= 100 ? 'v' : (p >= 90 ? 'o' : 'r')),             // chiffre face à l'objectif, %
    net: p => p == null ? 'n' : (p >= 15 ? 'v' : (p >= 5 ? 'o' : 'r')),               // résultat, % des ventes
    mat: p => p == null ? 'n' : (p <= 35 ? 'v' : (p <= 45 ? 'o' : 'r')),              // matière, % des ventes
    mo: p => p == null ? 'n' : (p <= 20 ? 'v' : (p <= 25 ? 'o' : 'r')),               // main-d'œuvre, % des ventes
    poub: p => p == null ? 'n' : (p <= 1.5 ? 'v' : (p <= 3 ? 'o' : 'r')),             // poubelle, % du chiffre
    ctrl: (r, t) => !t ? 'n' : (r >= t ? 'v' : (r / t >= 0.8 ? 'o' : 'r')),           // contrôles rendus
    cli: (n, j7) => n == null || !j7 ? 'n' : (n >= 0.95 * j7 ? 'v' : (n >= 0.85 * j7 ? 'o' : 'r')),
  };
  /** La poubelle du jour regardé, même depuis l'onglet Semaine (qui lit celle de la semaine). */
  function maInvJour() { const I = S.aux['inv|' + S.shop + '|' + S.date]; return I && !I.error ? I : null; }
  const maPire = L => L.includes('r') ? 'r' : (L.includes('o') ? 'o' : (L.includes('v') ? 'v' : 'n'));
  const maPl = (n, mot) => fN(n) + ' ' + mot + (Math.abs(n) > 1 ? 's' : '');
  const maOrd = n => n === 1 ? '1<sup>er</sup>' : n + '<sup>e</sup>';
  /** Le rang du magasin dans le réseau sur une mesure (le plus haut d'abord) : [rang, nombre] ou null. */
  function maRang(d, m, k) {
    if (!d || !m || m[k] == null) { return null; }
    const v = (d.magasins || []).filter(x => x.ouvert !== false && x[k] != null && isFinite(x[k])).map(x => x[k]).sort((a, b) => b - a);
    return v.length >= 2 ? [v.findIndex(x => x <= m[k]) + 1, v.length] : null;
  }
  const maRangPt = r => !r ? 'n' : (r[0] <= Math.ceil(r[1] / 2) ? 'v' : (r[0] < r[1] ? 'o' : 'r'));
  /** Les petites barres : la hauteur dit la valeur, la plus haute en couleur. */
  function maBarres(L, plafond) {
    const mx = plafond || Math.max(1, ...L.map(x => x.v || 0));
    return `<div class="ma-bars">${L.map(x => `<div><i class="${x.c || ''}" style="height:${x.v == null ? 3 : Math.max(3, Math.round(Math.min(1, x.v / mx) * 30))}px"></i>${esc(x.l)}</div>`).join('')}</div>`;
  }
  /** Une tuile : {k, pt, v, s, w (pleine largeur), ouvre (le tiroir), attr, apres, ouvert, tir()}. */
  function maTuile(o) {
    return `<div class="ma-t${o.w ? ' w' : ''}${o.pt === 'r' ? ' r' : (o.pt === 'o' ? ' o' : '')}"${o.ouvre ? ` data-${o.ouvre}="1" role="button"` : ''}${o.attr || ''}>`
      + `<div class="k"><i class="pt p${o.pt || 'n'}"></i>${o.k}</div><div class="v">${o.v}</div>${o.s ? `<div class="s">${o.s}</div>` : ''}${o.apres || ''}`
      + `${o.ouvre || o.attr ? `<span class="ch">${o.ouvert ? '▴' : '›'}</span>` : ''}</div>`
      + (o.ouvert && o.tir ? `<div class="ma-tir">${o.tir()}</div>` : '');
  }
  const maGrille = T => `<div class="ma-g">${T.map(maTuile).join('')}</div>`;
  /** La ligne du haut : combien de tuiles ne sont pas vertes, lesquelles, et toutes les pastilles. */
  function maVerdict(T, regler) {
    const pb = T.filter(t => t.pt === 'r' || t.pt === 'o'), n = pb.length;
    const cls = pb.some(t => t.pt === 'r') ? 'ko' : (n ? 'wa' : 'ok');
    const titre = n ? maPl(n, 'point').replace(/^\d+ /, '') + (regler ? ' à régler' : ' à surveiller') : 'tout est au vert';
    const vert = T.filter(t => t.pt === 'v').length, gris = T.filter(t => !t.pt || t.pt === 'n').length;
    const em = n ? pb.map(t => t.court || t.k).join(' · ') : (vert + ' au vert' + (gris ? ' · ' + gris + ' pas encore jugé' + (gris > 1 ? 's' : '') : ''));
    return `<div class="ma-v"><span class="n ${cls}">${n || '✓'}</span><span class="t">${titre}<em>${em}</em></span><span class="dots">${T.map(t => `<i class="${t.pt || 'n'}"></i>`).join('')}</span></div>`;
  }

  /** Exploitation : le jour, ce qu'il rapporte. */
  function maTuilesExp(m, d) {
    if (!m) { return null; }
    const auj = S.date === AUJ, obj = m.objectifJour, att = obj ? 100 * m.ca / obj : null;
    const enCours = auj && m.projectionPart != null && m.projectionPart < 100;
    const proj = enCours && m.projectionAtteinte != null ? 100 * m.projectionAtteinte : null;
    const CJ = clientsJour(m), cl = CJ ? Math.abs(CJ.n) : null;
    const I = maInvJour();
    const rc = maRang(d, m, 'ca'), rt = maRang(d, m, 'tickets'), rp = maRang(d, m, 'panier');
    const C = S.aux['canaux|' + S.shop + '|' + S.date], web = C && !C.error && C.jour ? C.jour.webshop : null;
    const H = Array.isArray(m.heures) ? m.heures : [];
    const pic = H.reduce((a, x) => (x.ca || 0) > ((a && a.ca) || 0) ? x : a, null);
    const T = [];
    T.push({ k: 'Chiffre d’affaires' + (obj && m.ca >= obj ? ' · objectif atteint' : ''), court: 'le chiffre', w: true,
      pt: att != null && att >= 100 ? 'v' : (enCours ? MA.ca(proj) : (auj ? 'n' : MA.ca(att))),
      v: fE(m.ca) + (obj ? ` <small class="${m.ca >= obj ? 'ok' : 'ko'}">${fS(m.ca - obj)}</small>` : ''),
      s: obj ? `objectif ${fE(obj)} · ${fP(att)}` + (proj != null && m.ca < obj ? ` · projection ${fK(m.projection)}` : '')
        + (cl ? ` · ${fN(cl)} client${cl > 1 ? 's' : ''}${CJ.comptoir ? ' comptoir' : ''} ${CJ.n <= 0 ? 'd’avance' : 'de moins'}` : '') : 'pas d’objectif du jour',
      apres: obj ? murJauge(att, att >= 100 ? '#2d7a3e' : '') : '' });
    T.push({ k: 'Résultat', court: 'le résultat', pt: auj || m.net == null ? 'n' : MA.net(m.netPct),
      v: m.net != null ? fS(m.net) : '—', s: m.netPct != null ? fPS(m.netPct) + ' des ventes' + (auj ? ' · la journée continue' : '') : 'P&amp;L incomplet' });
    T.push({ k: 'Clients', court: 'les clients', pt: MA.cli(m.tickets, m.j7 && m.j7.tickets),
      v: m.tickets != null ? fN(m.tickets) + j7Delta(m) : '—', s: [m.j7 && m.j7.tickets != null ? fN(m.j7.tickets) + ' à J−7' : '', m.panier ? 'panier ' + fU(m.panier) : ''].filter(Boolean).join(' · ') });
    T.push({ k: 'Matière', court: 'la matière', pt: MA.mat(m.coutMatierePct), v: fP(m.coutMatierePct),
      s: 'seuil 35 %' + (m.margeBrutePct != null ? ' · marge brute ' + fP(m.margeBrutePct) : '') });
    T.push({ k: 'Main-d’œuvre', court: 'la main-d’œuvre', pt: auj ? 'n' : MA.mo(m.labourPct), v: m.labourPct != null ? fP(m.labourPct) : '—',
      s: m.planningHeures ? nf(m.planningHeures, 1) + ' h au planning' : 'des ventes' });
    T.push({ k: 'Invendus', court: 'la poubelle', ouvre: 'invdrop', ouvert: S.invOuvert, tir: () => invCarte(true),
      pt: !I || !I.lu ? 'n' : (!I.declare ? (auj ? 'n' : 'o') : MA.poub(m.ca ? 100 * I.cout / m.ca : null)),
      v: !I ? '…' : (I.declare ? fE(I.cout) : '0'), s: !I ? 'lecture de la poubelle…' : (!I.lu ? 'panel muet' : (I.declare ? maPl(I.pieces, 'pièce') + (m.ca ? ' · ' + fP(100 * I.cout / m.ca) + ' du chiffre' : '') : (auj ? 'se déclare à la fermeture' : 'rien déclaré'))) });
    T.push({ k: 'Réseau', court: 'le rang', ouvre: 'rgdrop', ouvert: S.rgOuvert, tir: () => murClassement(m, d), pt: maRangPt(rc),
      v: rc ? maOrd(rc[0]) + ' / ' + rc[1] : '—', s: rc ? 'chiffre' + (rt ? ' · clients ' + maOrd(rt[0]) : '') + (rp ? ' · panier ' + maOrd(rp[0]) : '') : 'lecture du réseau…' });
    const P = proData();
    T.push({ k: 'Canaux', w: true, pt: 'n', ouvre: P ? 'prodrop' : '', ouvert: S.proOuvert && !!P, tir: () => proCarte(true),
      v: m.caComptoir != null ? fE(m.caComptoir) : fE(m.ca), s: 'comptoir' + (m.caPro != null ? ' · pro ' + fE(m.caPro) + (m.ticketsPro ? ' (' + maPl(m.ticketsPro, 'client') + ')' : '') : '') + (web != null ? ' · web ' + fE(web) : '') });
    if (H.length) {
      T.push({ k: 'Heure par heure' + (pic ? ` · pic à ${pic.h} h, ${fE(pic.ca)}` : ''), w: true, pt: 'n', v: '',
        apres: maBarres(H.map(x => ({ l: String(x.h), v: x.ca, c: x === pic ? 'top' : '' }))) });
    }
    return T;
  }
  function maPageExp(m, d) {
    const T = maTuilesExp(m, d);
    if (!T) { return `<div class="ma-v"><span class="n mu">…</span><span class="t">lecture de la journée<em>le chiffre, les marges, les clients</em></span></div>${squeletteMa(6)}`; }
    let h = maVerdict(T.filter(t => t.pt !== undefined), false) + maGrille(T);
    // Les campagnes et les promotions du jour, quand il y en a : leurs cellules et leurs tiroirs.
    const ext = []; murObjectifs().forEach(t => ext.push(murR([t], true))); murPromos().forEach(t => ext.push(murR([t], true)));
    if (ext.length) { h += `<div class="mb-mur ma-ext">${ext.join('')}</div>`; }
    if (S.objOuvert) { h += `<div class="mb-tir">${objectifsCarte(true)}</div>`; }
    if (S.promoOuvert) { h += `<div class="mb-tir">${promosCarte(true)}</div>`; }
    return h;
  }

  /** Contrôle : les photos, les contrôles, les commandes, le stock, la poubelle. */
  function maTuilesCtrl() {
    const auj = S.date === AUJ, L = cqListe(), T = [];
    const nom = x => cqNom(x).replace(/^(Comptoir|CQ) · /, '');
    if (L) {
      const ko = L.filter(x => x.e.c === 'ko'), ctl = L.filter(x => x.e.c === 'ctl'), nc = L.filter(x => x.e.c === 'nc'), notees = L.filter(x => x.note != null);
      // Cochée sans photo : pas rendue (06/10/2026), mais dite à part.
      const sp = L.filter(x => x.e.c === 'mu'), r = L.length - ko.length - sp.length;
      T.push({ k: 'Contrôles', court: ko.length || sp.length ? [ko.length ? maPl(ko.length, 'contrôle') + ' non rendu' + (ko.length > 1 ? 's' : '') : '', sp.length ? sp.length + ' sans photo' : ''].filter(Boolean).join(' · ') : '', pt: !L.length ? 'n' : ((ko.length || sp.length) && auj ? 'o' : MA.ctrl(r, L.length)),
        v: L.length ? r + ' / ' + L.length : '—', s: !L.length ? 'aucun contrôle ce jour' : ([ko.length ? (auj ? 'pas encore : ' : 'manquent ') + ko.map(nom).join(', ') : '', sp.length ? 'cochés sans photo : ' + sp.map(nom).join(', ') : ''].filter(Boolean).join(' · ') || 'tous rendus avec leur photo') });
      T.push({ k: 'À contrôler', court: ctl.length ? maPl(ctl.length, 'photo') + ' à contrôler' : '', pt: ctl.length ? 'o' : (r ? 'v' : 'n'), v: String(ctl.length),
        s: ctl.length ? ctl.map(nom).join(', ') + ', pas encore notée' + (ctl.length > 1 ? 's' : '') : (r ? 'tout est noté' : 'rien de rendu'), attr: ctl.length ? ` data-cqvoir="${esc(ctl[0].taskId)}"` : '' });
      T.push({ k: 'Non-conformités', court: nc.length ? maPl(nc.length, 'non-conformité') : '', pt: nc.length ? 'r' : (notees.length ? 'v' : 'n'), v: String(nc.length),
        s: nc.length ? nc.map(nom).join(', ') : (notees.length ? maPl(notees.length, 'photo') + ' notée' + (notees.length > 1 ? 's' : '') + ', toutes conformes' : 'pas encore notées'), attr: nc.length ? ` data-cqvoir="${esc(nc[0].taskId)}"` : '' });
    } else {
      ['Contrôles', 'À contrôler', 'Non-conformités'].forEach(k => T.push({ k: k, pt: 'n', v: '…', s: S.err['taches|' + S.date] ? esc(S.err['taches|' + S.date]) : 'lecture du panel…' }));
    }
    const D = cmdEtat(), C = D && D.commandes;
    T.push({ k: 'Commandes', court: C && C.retard ? maPl(C.retard, 'commande') + ' en retard' : '', ouvre: 'cmddrop', ouvert: S.cmdOuvert, tir: () => `<div class="db-stdl">${cmdTiroir()}</div>`,
      pt: !C || C.indispo ? 'n' : (C.retard ? 'r' : 'v'), v: !C ? '…' : (C.indispo ? '—' : (C.auj ? C.auj + ' auj.' : fN(C.enCours || 0))),
      s: !C ? 'lecture des commandes…' : (C.indispo ? esc(C.motif || 'indisponible') : ([C.retard ? C.retard + ' en retard' : '', C.aVenir ? C.aVenir + ' à venir' : '', C.montant ? fE(C.montant) : ''].filter(Boolean).join(' · ') || 'aucune en cours')) });
    const E = stockEtat();
    T.push({ k: 'Stock', court: E && !E.indispo ? (E.ruptures ? maPl(E.ruptures, 'référence') + ' à zéro' : (E.alertes ? maPl(E.alertes, 'référence') + ' sous le minimum' : (E.vieux ? 'stock pas recompté' : ''))) : '',
      ouvre: E && !E.indispo ? 'stdrop' : '', ouvert: S.stockOuvert && E && !E.indispo, tir: () => `<div class="db-stdl">${stockTiroir(E)}<div class="db-stpush">${pushBouton()}</div></div>`,
      pt: !E || E.indispo ? 'n' : (E.ruptures ? 'r' : (E.alertes || E.vieux ? 'o' : 'v')),
      v: !E ? '…' : (E.indispo ? '—' : (E.ruptures ? String(E.ruptures) : (E.alertes ? String(E.alertes) : '✓'))),
      s: !E ? 'lecture de l’inventaire…' : (E.indispo ? esc(E.motif) : (E.ruptures ? 'à zéro · ' + E.alertes + ' sous le minimum' : (E.alertes ? 'sous leur minimum' : maPl(E.n, 'référence') + (E.dernier ? ' · compté le ' + fD(E.dernier.slice(0, 10)) : '') + (E.vieux ? ' · à recompter' : '')))) });
    const I = maInvJour(), top = I && Array.isArray(I.produits) && I.produits[0];
    T.push({ k: 'Poubelle', court: I && I.lu && !I.declare && !auj ? 'poubelle pas déclarée' : '', ouvre: 'invdrop', ouvert: S.invOuvert, tir: () => invCarte(true),
      pt: !I || !I.lu ? 'n' : (I.declare ? 'v' : (auj ? 'n' : 'o')), v: !I ? '…' : (I.declare ? maPl(I.pieces, 'p.').replace(/s$/, '') : '—'),
      s: !I ? 'lecture de la poubelle…' : (!I.lu ? 'panel muet' : (I.declare ? 'déclarée' + (top ? ' · surtout ' + esc(String(top.nom).toLowerCase()) + ', ' + fN(top.pieces) : '') : (auj ? 'se déclare à la fermeture' : 'pas déclarée ce jour'))) });
    return { T: T, L: L };
  }
  function maPageCtrl() {
    const { T } = maTuilesCtrl();
    let h = maVerdict(T, true);
    h += `<div class="ma-car">${rendCQ(true) || `<div class="mb-cq"><div class="k">Les contrôles en photo</div><div class="s">${S.err['taches|' + S.date] ? esc(S.err['taches|' + S.date]) : 'lecture du panel…'}</div></div>`}</div>`;
    h += maGrille(T);
    h += `<button type="button" class="ma-rc" data-vue="reclamation">✎ Réclamer un produit au fournisseur</button>`;
    h += `<div class="mb-mur ma-ext">${murR([murNote()], true)}</div>`;
    if (S.noteOuvert) { h += `<div class="mb-tir">${noteCarte(true)}</div>`; }
    return h;
  }

  /** Semaine : la semaine en une vue — le chiffre jour par jour, et le damier jour × mesure. */
  function cleSemJ() { return 'semJ|' + S.shop + '|' + bornesSemaine()[0]; }
  function maTuilesSem(m, d) {
    if (!m) { return null; }
    const J = Array.isArray(m.jours) ? m.jours : [];
    const realise = m.realise != null ? m.realise : m.ca, att = m.attendu ? 100 * realise / m.attendu : null;
    const JM = magasin(S.aux['jourM|' + S.date]), NET = {}; (JM && Array.isArray(JM.semaine) ? JM.semaine : []).forEach(x => { NET[x.date] = x; });
    const SJ = S.aux[cleSemJ()], X = {}; (SJ && Array.isArray(SJ.jours) ? SJ.jours : []).forEach(x => { X[x.date] = x; });
    const passes = J.filter(j => j.passe && !j.ferme && j.ca != null);
    const avecNet = passes.filter(j => NET[j.date] && NET[j.date].net != null);
    const complet = avecNet.length && passes.every(j => (NET[j.date] && NET[j.date].net != null) || j.aujourdhui);
    const netSem = m.net != null ? m.net : (complet ? avecNet.reduce((a, j) => a + NET[j.date].net, 0) : null);
    const dessous = passes.filter(j => j.objectif && j.ca < j.objectif && !j.aujourdhui), dessus = passes.filter(j => j.objectif && j.ca >= j.objectif);
    const jl = j => (j.court || fD(j.date)).replace(/\s+\d+$/, '');
    const rc = maRang(d, m, 'realise');
    const prochain = J.find(j => !j.passe && !j.ferme && j.objectif);
    const T = [];
    T.push({ k: 'Chiffre de la semaine' + (passes.length ? ' · au ' + esc(fD(passes[passes.length - 1].date)) : ''), court: 'le chiffre', w: true, pt: MA.ca(att),
      v: fE(realise) + (m.attendu ? ` <small class="mu">/ ${fE(m.attendu)}</small>` : ''),
      s: (att != null ? fP(att) + ' de l’attendu' : '') + (m.tickets ? ' · ' + fN(m.tickets) + ' clients' : '') + (prochain ? ' · ' + esc(jl(prochain)).toLowerCase() + ' : objectif ' + fE(prochain.objectif) : ''),
      apres: maBarres(J.map(j => ({ l: jl(j), v: j.ca != null && j.objectif ? 100 * j.ca / j.objectif : null, c: j.ca != null && j.objectif ? 'p' + maJourCA(j) : '' })), 120) });
    T.push({ k: 'Résultat', court: 'le résultat', pt: netSem == null ? 'n' : MA.net(realise ? 100 * netSem / realise : null),
      v: netSem != null ? fS(netSem) : '—', s: netSem != null ? (m.net == null ? 'somme des jours, main-d’œuvre répartie' : 'la semaine') + (realise ? ' · ' + fPS(100 * netSem / realise) + ' des ventes' : '') : 'P&amp;L incomplet' });
    T.push({ k: 'Matière', court: 'la matière', pt: MA.mat(m.coutMatierePct), v: fP(m.coutMatierePct), s: 'seuil 35 % · la semaine' });
    T.push({ k: 'Poubelle', court: 'la poubelle', ouvre: 'invdrop', ouvert: S.invOuvert, tir: () => invCarte(true), pt: m.invendus == null ? 'n' : MA.poub(m.invendusPct),
      v: m.invendus != null ? fE(m.invendus) : '—', s: m.invendusPieces != null ? maPl(m.invendusPieces, 'pièce') + (m.invendusPct != null ? ' · ' + fP(m.invendusPct) + ' du chiffre' : '') : 'pas déclarée' });
    T.push({ k: 'Réseau', court: 'le rang', ouvre: 'rgdrop', ouvert: S.rgOuvert, tir: () => murClassement(m, d), pt: maRangPt(rc),
      v: rc ? maOrd(rc[0]) + ' / ' + rc[1] : '—', s: m.panier ? 'panier ' + fU(m.panier) : '' });
    return { T: T, J: J, NET: NET, X: X, SJ: SJ, att: att, dessous: dessous.map(jl), dessus: dessus.map(jl) };
  }
  function maDamier(R) {
    const { J, NET, X, SJ } = R;
    const cell = (c, v) => `<td class="${c}">${v}</td>`;
    const ligne = (lib, f) => `<tr><td class="l">${lib}</td>${J.map(j => (!j.passe || j.ferme) ? cell('f', j.ferme ? '×' : '·') : f(j)).join('')}</tr>`;
    const auj = j => j.date === AUJ;
    let h = `<div class="ma-dam"><table><tr><th class="l">la semaine</th>${J.map(j => `<th class="${auj(j) ? 'auj' : ''}">${esc(j.court || fD(j.date))}</th>`).join('')}</tr>`;
    h += ligne('Chiffre / obj.', j => j.ca != null && j.objectif ? cell(auj(j) && j.ca < j.objectif ? 'n' : MA.ca(100 * j.ca / j.objectif), nf(Math.round(100 * j.ca / j.objectif), 0)) : cell('n', '—'));
    const jmLu = !!S.aux['jourM|' + S.date];
    h += ligne('Résultat', j => { const x = NET[j.date]; return x && x.net != null ? cell(auj(j) ? 'n' : MA.net(x.ca ? 100 * x.net / x.ca : null), fSigne(x.net)) : cell('n', jmLu ? '—' : '…'); });
    h += ligne('Contrôles', j => { const c = X[j.date] && X[j.date].controles; return c ? cell(c.total ? (auj(j) && c.rendus < c.total ? 'o' : MA.ctrl(c.rendus, c.total)) : 'n', c.total ? c.rendus + '/' + c.total : '—') : cell('n', SJ ? '—' : '…'); });
    h += ligne('Notés', j => { const c = X[j.date] && X[j.date].controles; return c ? (c.nc ? cell('r', c.nc + ' NC') : (c.notes ? cell('v', String(c.notes)) : cell('n', '—'))) : cell('n', SJ ? '—' : '…'); });
    h += ligne('Poubelle', j => { const p = X[j.date] && X[j.date].poubelle; if (!p) { return cell('n', SJ ? '—' : '…'); } if (!p.pieces) { return cell('n', '0'); }
      return cell(auj(j) ? 'n' : MA.poub(j.ca ? 100 * p.cout / j.ca : null), nf(Math.round(p.cout), 0) + '€'); });
    h += `</table><div class="ma-leg"><span><i class="v"></i>bon</span><span><i class="o"></i>à surveiller</span><span><i class="r"></i>à reprendre</span><span><i class="n"></i>rien ou en cours</span></div><div class="ma-note">Résultat : la main-d’œuvre du mois répartie sur les jours, mesurée au planning pour le ${esc(fD(S.date))} seulement.</div></div>`;
    return h;
  }
  /** La couleur d'un jour de la semaine face à son objectif ; aujourd'hui, en dessous, n'est pas encore jugé. */
  const maJourCA = j => j.date === AUJ && j.ca < j.objectif ? 'n' : MA.ca(100 * j.ca / j.objectif);
  const fSigne = v => (v < 0 ? '−' : '') + nf(Math.abs(Math.round(v)), 0);
  function maPageSem(m, d) {
    const R = maTuilesSem(m, d);
    if (!R) { return `<div class="ma-v"><span class="n mu">…</span><span class="t">lecture de la semaine<em>le chiffre jour par jour, les contrôles, la poubelle</em></span></div>${squeletteMa(5)}`; }
    const [ca, ...reste] = R.T;
    const ecart = m.ecart;
    const em = [R.dessous.length ? 'en dessous : ' + R.dessous.join(', ') : '', R.dessus.length ? 'au-dessus : ' + R.dessus.join(', ') : ''].filter(Boolean).join(' · ') || 'la semaine commence';
    let h = `<div class="ma-v"><span class="n ${ecart == null ? 'mu' : (ecart >= 0 ? 'ok' : (R.att >= 90 ? 'wa' : 'ko'))} long">${ecart == null ? '…' : fS(ecart)}</span><span class="t">${ecart == null ? 'la semaine' : (ecart >= 0 ? 'd’avance sur l’attendu' : 'sur l’attendu à ce jour')}<em>${em}</em></span>`
      + `<span class="dots">${R.J.filter(j => j.passe && !j.ferme && j.ca != null && j.objectif).map(j => `<i class="${maJourCA(j)}"></i>`).join('')}</span></div>`;
    h += maGrille([ca]) + maDamier(R) + maGrille(reste);
    const v = valeurMagasin();
    h += `<div class="mb-mur ma-ext">${murR([murC('Valeur', v && v.n ? fK(v.valeur) : '…', v && v.n ? VALO_JOURS + ' jours · ÷ ' + VALO_JOURS + ' × ' + VALO_AN + ' ÷ ' + VALO_DIV : 'lecture des ventes…', 'or', 'vdrop')], true)}</div>`;
    if (S.valoOuvert) { h += `<div class="mb-tir">${rendValeur()}</div>`; }
    return h;
  }
  function squeletteMa(n) { return `<div class="ma-g">${Array.from({ length: n }, () => '<div class="ma-t"><div class="db-sk" style="width:55%"></div><div class="db-sk" style="height:22px;margin:8px 0 6px;width:70%"></div><div class="db-sk" style="width:85%"></div></div>').join('')}</div>`; }

  /** La couleur de chaque onglet, pour la barre du bas : ce qu'on sait déjà, sans relire. */
  function maEtats() {
    const dj = S.res['jour|' + S.date], mj = magasin(dj);
    const te = mj ? maTuilesExp(mj, dj) : null;
    const tc = cqListe() ? maTuilesCtrl().T : null;
    const ds = S.vue === 'semaine' ? S.res[cleRes()] : S.aux['sem|' + bornesSemaine()[0]], ms = magasin(ds);
    const ts = ms ? maTuilesSem(ms, ds) : null;
    return { exp: te ? maPire(te.map(t => t.pt || 'n')) : 'n', ctrl: tc ? maPire(tc.map(t => t.pt || 'n')) : 'n', sem: ts ? maPire(ts.T.map(t => t.pt || 'n')) : 'n' };
  }
  function maDate() {
    if (S.vue === 'semaine') { const [du, au] = bornesSemaine(); return 'du ' + fD(du) + ' au ' + fD(au); }
    const t = new Date(S.date + 'T12:00:00');
    return (S.date === AUJ ? 'aujourd’hui · ' : '') + t.toLocaleDateString('fr-BE', { weekday: 'long', day: 'numeric', month: 'long' });
  }

  function rendMobile(m, d) {
    if (S.vue === 'ops') {
      return `<div class="mb-hd"><img src="../assets/img/logo.png" alt="">
      <div><div class="t">${esc(nomShop())}</div><div class="d ma-d"><button type="button" class="ma-pas" data-pas="-1" aria-label="précédent">‹</button><b>${esc(maDate())}</b><button type="button" class="ma-pas" data-pas="1" aria-label="suivant"${S.date >= AUJ ? ' disabled' : ''}>›</button></div></div>
      <span class="sp"></span><button class="mb-ic" data-recharger="1" aria-label="relire">↻</button></div>
      <div class="mb-sc"><div class="ma op-mob">${rendOpsMobile()}</div></div>${mbOnglets()}`;
    }
    if (S.vue !== 'semaine' && !['exp', 'ctrl'].includes(S.mo)) { S.mo = 'exp'; }
    const on = S.vue === 'semaine' ? 'sem' : S.mo;
    const fin = S.vue === 'semaine' ? bornesSemaine()[1] >= AUJ : S.date >= AUJ;
    let h = `<div class="mb-hd"><img src="../assets/img/logo.png" alt="">
      <div><div class="t">${esc(nomShop())}</div><div class="d ma-d"><button type="button" class="ma-pas" data-pas="-1" aria-label="précédent">‹</button><b>${esc(maDate())}</b><button type="button" class="ma-pas" data-pas="1" aria-label="suivant"${fin ? ' disabled' : ''}>›</button></div></div>
      <span class="sp"></span><button class="mb-ic" data-recharger="1" aria-label="relire">↻</button></div>
      <div class="mb-sc"><div class="ma">`;
    if (S.err[cleRes()]) { h += `<div class="db-err">${esc(S.err[cleRes()])}</div>`; }
    h += on === 'exp' ? maPageExp(m, d) : (on === 'ctrl' ? maPageCtrl() : maPageSem(m, d));
    h += '</div></div>';
    h += mbOnglets();
    return h;
  }
  const MA_ICO = {
    ops: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    exp: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 20V11M11 20V5M17 20v-8M2 20h20"/></svg>',
    ctrl: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8h4l2-3h6l2 3h4v11H3z"/><circle cx="12" cy="13" r="3.6"/></svg>',
    sem: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>',
  };
  /** Le menu du téléphone : Opérationnel (la journée en cours, 06/10/2026), Exploitation, Contrôle,
   * Semaine, chacun avec sa couleur. La réclamation fournisseur s'ouvre depuis Contrôle et y ramène. */
  function mbOnglets() {
    const P = maEtats();
    const on = S.vue === 'ops' ? 'ops' : (S.vue === 'semaine' ? 'sem' : (S.vue === 'reclamation' ? 'ctrl' : (S.vue === 'jour' ? S.mo : '')));
    return `<div class="mb-tabs mb-tabs4 ma-tabs">${[['ops', 'Opérationnel'], ['exp', 'Exploitation'], ['ctrl', 'Contrôle'], ['sem', 'Semaine']]
      .map(([k, l]) => `<button type="button" data-mo="${k}" class="${on === k ? 'on' : ''}"><i>${MA_ICO[k]}${P[k] && P[k] !== 'n' ? `<b class="pt p${P[k]}"></b>` : ''}</i>${l}</button>`).join('')}</div>`;
  }

  /* --- l'objectif produits d'une campagne : « 500 tartes aux pommes en octobre », et où l'on en est --- */
  function cleObj() { return 'obj|' + S.shop + '|' + S.date; }
  function cheminObj() { return '/exploitation/objectifs-produits?shop=' + encodeURIComponent(S.shop) + '&date=' + S.date; }
  const OBJ_ETATS = { atteint: ['objectif atteint', 'ok'], avance: ['en avance', 'ok'], clous: ['dans les clous', 'att'], retard: ['en retard', 'ko'], sans: ['', ''] };
  function objCampagnes() { const O = S.aux[cleObj()]; return O && Array.isArray(O.campagnes) ? O.campagnes : []; }
  function objJauge(c, mini) {
    const pct = c.pct != null ? Math.max(0, Math.min(100, c.pct)) : 0;
    const vert = c.etat === 'avance' || c.etat === 'atteint';
    return `<div class="db-bar${mini ? ' mini' : ''}"><i class="${vert ? 'ok' : ''}" style="width:${pct.toFixed(1)}%"></i>${c.attendu != null ? `<b style="left:${Math.max(0, Math.min(100, c.attendu)).toFixed(1)}%" data-l="attendu ${Math.round(c.attendu)} %"></b>` : ''}</div>`;
  }
  function objectifsCarte(mobile) {
    const L = objCampagnes();
    if (!L.length) { return ''; }
    const auj = S.date === AUJ;
    return L.map(c => {
      const el = OBJ_ETATS[c.etat] || OBJ_ETATS.sans, cls = el[1], j = c.jours || {};
      const or = c.etat === 'atteint';
      const top = (c.parProduit || [])[0];
      const prods = (c.parProduit || []).length ? c.parProduit.map(p => `<div class="db-obj-pp"><span>${esc(p.nom)}</span><span class="n">${fN(p.q)}</span><span class="q">${c.vendu ? fP(100 * p.q / c.vendu).replace(',0', '') : ''}</span><div class="db-bar mini"><i style="width:${top && top.q ? (100 * p.q / top.q).toFixed(1) : 0}%;background:#b8ad9f"></i></div></div>`).join('')
        : `<div class="db-mini">Aucune vente lue encore${c.aSuivre ? ' — tickets en cours de lecture' : ''}.</div>`;
      const gauche = `<div class="db-obj-g">
        <div class="db-obj-gros">${fN(c.vendu)} <small>/ ${fN(c.objectif)} pièces</small> <span class="${cls}">${c.pct != null ? Math.round(c.pct) + ' %' : ''}</span>${or ? '<span class="db-badge-or"><span>🎆</span>Objectif atteint</span>' : ''}</div>
        ${objJauge(c, false)}
        <div class="db-mini" style="margin-top:12px"><b class="${cls}">${el[0]}</b>${c.attendu != null ? ' · attendu à ce jour ' + Math.round(c.attendu) + ' %' : ''} · <b>+${fN(c.ceJour)} ${auj ? 'aujourd’hui' : 'ce jour'}</b> · reste ${fN(c.reste)} sur ${j.restants || 0} jour${(j.restants || 0) > 1 ? 's' : ''} ouvert${(j.restants || 0) > 1 ? 's' : ''}${c.aSuivre ? ' · tickets en cours de lecture' : ''}</div>
        <div class="db-obj-t4">
          <div><div class="k">${auj ? 'Aujourd’hui' : 'Ce jour'}</div><div class="v">+${fN(c.ceJour)}</div><div class="s">pièces vendues</div></div>
          <div><div class="k">Reste à vendre</div><div class="v">${fN(c.reste)}</div><div class="s">d’ici le ${fD(c.fin)}</div></div>
          <div><div class="k">Il faut</div><div class="v ${cls}">${c.ilFaut != null ? fN(Math.round(c.ilFaut)) + ' / j' : '—'}</div><div class="s">rythme actuel ${c.rythme != null ? fN(Math.round(c.rythme)) + ' / j' : '—'}</div></div>
          <div><div class="k">Projection</div><div class="v ${cls}">${c.projection != null ? fN(c.projection) : '—'}</div><div class="s">${c.projection != null && c.objectif ? Math.round(100 * c.projection / c.objectif) + ' % de l’objectif' : 'au rythme actuel'}</div></div>
        </div></div>`;
      const droite = `<div class="db-obj-p"><span class="db-lab">Par produit — vendus depuis le ${fD(c.debut)}</span>${prods}</div>`;
      const sous = `${fD(c.debut)} → ${fD(c.fin)} · ${(c.produits || []).length} produit${(c.produits || []).length > 1 ? 's' : ''} · jour ${j.passes || 0} sur ${j.ouverts || 0} ouverts · le repère noir : où l’on devrait en être ${auj ? 'aujourd’hui' : 'ce jour-là'}`;
      if (mobile) { return `<div class="db-obj mob${or ? ' db-or' : ''}"><div class="db-notej-t" style="padding:4px 4px 0">Objectif — ${esc(c.nom)}<small>${esc(sous)}</small></div>${gauche}${droite}</div>`; }
      return `<div class="db-card db-obj${or ? ' db-or' : ''}"><div class="ct"><span class="db-lab">Objectif produits — ${esc(c.nom)}</span><span class="db-mini">${esc(sous)}</span></div><div class="db-obj-corps">${gauche}${droite}</div></div>`;
    }).join('');
  }
  /** Les tuiles du mur mobile : une par campagne, la jauge en une ligne. */
  function murObjectifs() {
    return objCampagnes().map(c => {
      const el = OBJ_ETATS[c.etat] || OBJ_ETATS.sans, cls = el[1];
      const v = `${fN(c.vendu)} <span style="font-size:14px;color:var(--color-text-muted)">/ ${fN(c.objectif)}</span> <span class="${cls}" style="font-size:16px;font-family:var(--font-ui);font-weight:600">${c.pct != null ? Math.round(c.pct) + ' %' : ''}</span>`;
      const s = objJauge(c, true) + `<b class="${cls}">${el[0]}</b>${c.attendu != null ? ' · attendu ' + Math.round(c.attendu) + ' %' : ''} · +${fN(c.ceJour)} ${S.date === AUJ ? 'aujourd’hui' : 'ce jour'} · il faut ${c.ilFaut != null ? fN(Math.round(c.ilFaut)) + ' / j' : '—'}<span class="dr"> · ${S.objOuvert ? 'replier ▴' : 'détail ▾'}</span>`;
      return murC('Objectif — ' + esc(c.nom), v, s, c.etat === 'atteint' ? 'ok' : '', 'objdrop');
    });
  }

  /* --- la promotion en cours : ce que le cockpit a posé sur un creux du magasin, et ce que ça change --- */
  function clePromo() { return 'promo|' + S.shop + '|' + S.date; }
  function cheminPromo() { return '/exploitation/promos?shop=' + encodeURIComponent(S.shop) + '&date=' + S.date; }
  const PROMO_VERDICT = { tot: ['trop tôt pour juger', ''], garder: ['ça marche — à garder', 'ok'], ajuster: ['effet faible — à ajuster', 'att'], arreter: ['sans effet — à arrêter ou déplacer', 'ko'] };
  const PROMO_LEVIER = { trafic: 'Faire venir', panier: 'Faire acheter plus', experience: 'Faire vivre', ecouler: 'Écouler' };
  function promoListe() { const P = S.aux[clePromo()]; return P && Array.isArray(P.promos) ? P.promos : []; }
  function promoHeures(p) { return p.heureDe + ' h à ' + (p.heureA + 1) + ' h'; }
  function promoDelta(v, suffixe) { return v == null ? '—' : (v >= 0 ? '+ ' : '− ') + nf(Math.abs(v), 1) + ' %' + (suffixe || ''); }
  /** Le créneau jour par jour depuis le lancement, la référence en trait : on voit si l'effet tient ou s'il s'essouffle. */
  function promoBarres(p) {
    const e = p.effet || {}, pj = e.parJour || {}, ref = p.ref && p.ref.caH ? p.ref.caH : 0;
    const jours = Object.keys(pj).sort();
    if (!jours.length) { return ''; }
    const max = Math.max(ref, ...jours.map(j => pj[j].caH || 0)) || 1;
    return `<div class="db-promo-bars">${jours.map(j => { const v = pj[j].caH || 0; return `<i class="${ref && v >= ref ? 'ok' : ''}" style="height:${Math.max(2, Math.round(100 * v / max))}%" title="${fD(j)} · ${fU(v)} / h"></i>`; }).join('')}${ref ? `<b style="bottom:${Math.round(100 * ref / max)}%"></b>` : ''}</div>`;
  }
  function promosCarte(mobile) {
    const L = promoListe();
    if (!L.length) { return ''; }
    const auj = S.date === AUJ;
    return L.map(p => {
      const e = p.effet || {}, v = PROMO_VERDICT[e.verdict] || PROMO_VERDICT.tot, cls = v[1], ref = p.ref || {};
      const gauche = `<div class="db-promo-g">
        <div class="db-promo-nom">${esc(p.nom)}</div>
        <div class="db-promo-regle">${esc(p.regle || '')}</div>
        <div class="db-promo-tags"><span class="t">${esc(PROMO_LEVIER[p.levier] || p.levier)}</span><span class="t">${esc(p.creneau)}</span><span class="t">du ${fD(p.du)} au ${fD(p.au)}</span>${(p.canaux || []).map(c => `<span class="t mu">${esc(c)}</span>`).join('')}</div>
        <div class="db-promo-jour${p.ceJour ? ' on' : ''}">${p.ceJour ? `<b>${auj ? 'Aujourd’hui' : 'Ce jour'}, de ${promoHeures(p)}</b> — ${esc(p.note || 'proposer l’offre à chaque client du créneau')}` : `Pas de créneau ${auj ? 'aujourd’hui' : 'ce jour'} · ${esc(p.creneau)}`}</div>
        ${p.cible ? `<div class="db-mini">Ce qu’on attend : <b>${esc(p.cible)}</b></div>` : ''}</div>`;
      const droite = `<div class="db-promo-d"><span class="db-lab">Le créneau depuis le lancement</span>
        <div class="db-obj-t4 db-promo-t3">
          <div><div class="k">CA / heure</div><div class="v ${cls}">${e.caH != null ? fU(e.caH) : '—'}</div><div class="s">référence ${ref.caH != null ? fU(ref.caH) : '—'}</div></div>
          <div><div class="k">Clients / heure</div><div class="v">${e.tkH != null ? nf(e.tkH, 1) : '—'}</div><div class="s">référence ${ref.tkH != null ? nf(ref.tkH, 1) : '—'}</div></div>
          <div><div class="k">Effet</div><div class="v ${cls}">${promoDelta(e.deltaCaPct)}</div><div class="s">${e.deltaTkPct != null ? promoDelta(e.deltaTkPct, ' de clients') : 'de chiffre d’affaires'}</div></div>
        </div>
        ${promoBarres(p)}
        <div class="db-mini"><b class="${cls}">${v[0]}</b> · ${e.joursLus || 0} jour${(e.joursLus || 0) > 1 ? 's' : ''} lu${(e.joursLus || 0) > 1 ? 's' : ''} · la référence : les 4 semaines d’avant le lancement, mêmes jours, mêmes heures</div></div>`;
      const sous = `posée par le cockpit sur un creux du magasin · ${esc(p.creneau)} · du ${fD(p.du)} au ${fD(p.au)}`;
      if (mobile) { return `<div class="db-promo mob"><div class="db-notej-t" style="padding:4px 4px 0">Promotion en cours<small>${sous}</small></div>${gauche}${droite}</div>`; }
      return `<div class="db-card db-promo${p.ceJour ? ' on' : ''}"><div class="ct"><span class="db-lab">Promotion en cours</span><span class="db-mini">${sous}</span></div><div class="db-promo-corps">${gauche}${droite}</div></div>`;
    }).join('');
  }
  /** Les tuiles du mur mobile : une par promotion, l'heure du jour et l'effet en une ligne. */
  function murPromos() {
    return promoListe().map(p => {
      const e = p.effet || {}, v = PROMO_VERDICT[e.verdict] || PROMO_VERDICT.tot, cls = v[1];
      const val = p.ceJour ? `<span style="font-size:19px">${p.heureDe}–${p.heureA + 1} h</span>` : '<span style="font-size:19px;color:var(--color-text-muted)">pas ce jour</span>';
      const s = esc(p.nom) + ' · ' + (e.deltaCaPct != null ? `<b class="${cls}">${promoDelta(e.deltaCaPct)}</b> sur le créneau` : 'trop tôt pour juger') + `<span class="dr"> · ${S.promoOuvert ? 'replier ▴' : 'détail ▾'}</span>`;
      return murC('Promotion en cours', val, s, p.ceJour ? 'ok' : '', 'promodrop');
    });
  }

  /* --- les canaux : comptoir, click & collect, livraison — et les offres : bundles, promotions --- */
  // En vue Jour : la journée. En Semaine et en Mois : la période du dashboard, bornée par `bornes()`.
  const coPer = () => S.vue === 'semaine' || S.vue === 'mois';
  const perLib = () => S.vue === 'jour' ? 'la journée' : (S.vue === 'semaine' ? 'la semaine' : 'le mois');
  function cleCanaux() { return 'canaux|' + S.shop + '|' + (coPer() ? bornes().join('|') : S.date); }
  function cheminCanaux() { if (!coPer()) { return '/exploitation/canaux?shop=' + encodeURIComponent(S.shop) + '&date=' + S.date; } const [du, au] = bornes(); return '/exploitation/canaux?shop=' + encodeURIComponent(S.shop) + '&du=' + du + '&au=' + au; }
  function canauxData() { const C = S.aux[cleCanaux()]; return C && !C.error && (coPer() ? C.periode : C.jour) ? C : null; }
  function cleOffres() { return 'offres|' + S.shop + '|' + (coPer() ? bornes().join('|') : S.date); }
  function cheminOffres() { if (!coPer()) { return '/exploitation/offres?shop=' + encodeURIComponent(S.shop) + '&date=' + S.date + '&periode=7'; } const [du, au] = bornes(); return '/exploitation/offres?shop=' + encodeURIComponent(S.shop) + '&date=' + S.date + '&du=' + du + '&au=' + au; }
  function offresData() { const O = S.aux[cleOffres()]; return O && !O.error && Array.isArray(O.offres) ? O : null; }
  const CANAL = { compt: ['Comptoir', 'c'], comptoir: ['Comptoir', 'c'], cc: ['Click & collect', 'w'], liv: ['Livraison', 'l'], webshop: ['Webshop', 'w'] };
  const canalTag = k => { const c = CANAL[k] || [k, 'c']; return `<span class="co-mode ${c[1]}">${esc(c[0])}</span>`; };
  const STATUT_CO = { 'remise': 'ok', 'livrée': 'ok', 'en route': 'enc', 'prête': 'enc', 'en préparation': 'att', 'à préparer': 'att' };
  const JOURS_CO = ['di', 'lu', 'ma', 'me', 'je', 've', 'sa'];
  const ceJour = () => S.date === AUJ ? 'aujourd’hui' : 'ce jour';
  /** Le corps de la carte : en vue Jour la liste des commandes du jour ; en Semaine et Mois, les jours empilés par canal et le tableau par jour ou par semaine. */
  function canauxCorps(C) {
    const per = coPer(), mois = S.vue === 'mois', J = per ? C.periode : C.jour;
    const serie = C.serie || [], max = Math.max(1, ...serie.map(s => (s.comptoir || 0) + s.cc + s.liv));
    const Q = per ? J : (C.quatorze || {});
    const piles = `<div class="co-pile">${serie.map(s => { const d = new Date(s.j + 'T12:00:00').getDay(), we = d === 0 || d === 6; const h = v => (100 * (v || 0) / max).toFixed(1) + '%'; const t = (s.comptoir || 0) + s.cc + s.liv; return `<span class="${we ? 'we' : ''}${s.lu ? '' : ' na'}" title="${fD(s.j)} · ${s.lu ? fE(t) + ' dont webshop ' + fE(s.cc + s.liv) : (s.j > AUJ ? 'à venir' : 'caisse non lue') + (s.cc + s.liv ? ' · webshop ' + fE(s.cc + s.liv) : '')}"><i class="c" style="height:${h(s.comptoir)}"></i><i class="w" style="height:${h(s.cc)}"></i><i class="l" style="height:${h(s.liv)}"></i></span>`; }).join('')}</div>
      <div class="co-axe${serie.length > 20 ? ' dense' : ''}">${serie.map(s => { const d = new Date(s.j + 'T12:00:00'); const we = d.getDay() === 0 || d.getDay() === 6; return `<span class="${we ? 'we' : ''}">${mois ? d.getDate() : JOURS_CO[d.getDay()] + ' ' + d.getDate()}</span>`; }).join('')}</div>
      <div class="db-leg" style="padding:8px 0 0"><span><i style="background:#b8ad9f"></i>comptoir</span><span><i style="background:#1f5f8b"></i>click &amp; collect</span><span><i style="background:#0f3b5c"></i>livraison</span><span style="margin-left:auto">${per ? perLib() : '14 jours'} : <b>${fE(Q.webshop || 0)}</b> de webshop sur ${fE(Q.total || 0)}</span></div>`;
    const L = C.liste || [];
    let table, titre;
    if (!per) {
      titre = `Les commandes ${S.date === AUJ ? 'du jour' : 'de ce jour'} — comptoir et webshop`;
      table = L.length ? `<table class="db-pro-tab co-tab" style="margin:6px 0 0;width:100%"><thead><tr><th>Retrait</th><th>Canal</th><th class="n">Articles</th><th class="n">Montant</th><th></th></tr></thead><tbody>${L.map(c => `<tr><td class="mu">${esc(c.heure)}</td><td>${canalTag(c.canal)}</td><td class="n">${c.articles == null ? '—' : nf(c.articles, 0)}</td><td class="n">${fU(c.montant)}</td><td><span class="co-st ${STATUT_CO[c.statut] || ''}">${esc(c.statut)}</span></td></tr>`).join('')}</tbody></table>` : `<div class="db-mini" style="margin-top:8px">Aucune commande ${ceJour()} : ni précommande au comptoir, ni webshop.</div>`;
    } else if (!mois) {
      // La semaine : une ligne par jour, les jours à venir avec ce qui est déjà pris.
      titre = 'Les commandes de la semaine — jour par jour';
      const PJ = C.parJour || [];
      table = `<table class="db-pro-tab co-tab" style="margin:6px 0 0;width:100%"><thead><tr><th>Jour</th><th class="n">Commandes</th><th class="n">Click &amp; collect</th><th class="n">Livraison</th><th class="n">À préparer</th></tr></thead><tbody>${PJ.map(p => { const d = new Date(p.j + 'T12:00:00'); const av = p.j > AUJ; return `<tr${av ? ' style="opacity:.65"' : ''}><td class="${av ? 'mu' : ''}">${JOURS_C[d.getDay()]} ${fD(p.j)}${av ? ' <span class="mu">· à venir</span>' : ''}</td><td class="n">${p.n ? '<b>' + p.n + '</b>' + (p.compt ? ' <span class="mu">dont ' + p.compt + ' comptoir</span>' : '') : '<span class="mu">—</span>'}</td><td class="n">${p.ccN ? p.ccN + ' · ' + fE(p.cc) : '<span class="mu">—</span>'}</td><td class="n">${p.livN ? p.livN + ' · ' + fE(p.liv) : '<span class="mu">—</span>'}</td><td class="n">${p.aPreparer ? '<b class="att">' + p.aPreparer + '</b>' : '<span class="mu">—</span>'}</td></tr>`; }).join('')}</tbody></table>`;
    } else {
      // Le mois : une ligne par semaine.
      titre = 'Les commandes du mois — semaine par semaine';
      const PS = C.parSemaine || [];
      table = PS.length ? `<table class="db-pro-tab co-tab" style="margin:6px 0 0;width:100%"><thead><tr><th>Semaine du</th><th class="n">Comptoir</th><th class="n">Click &amp; collect</th><th class="n">Livraison</th><th class="n">Webshop</th></tr></thead><tbody>${PS.map(w => { const av = w.du > AUJ; return `<tr${av ? ' style="opacity:.65"' : ''}><td>${fD(w.du)}${av ? ' <span class="mu">· à venir</span>' : (w.joursLus < 7 && w.au >= AUJ ? ' <span class="mu">· en cours</span>' : '')}</td><td class="n">${w.joursLus ? fE(w.comptoir) : '<span class="mu">—</span>'}</td><td class="n">${w.ccN ? w.ccN + ' · ' + fE(w.cc) : '<span class="mu">—</span>'}</td><td class="n">${w.livN ? w.livN + ' · ' + fE(w.liv) : '<span class="mu">—</span>'}</td><td class="n">${w.part != null && w.joursLus ? fP(w.part) : '<span class="mu">—</span>'}</td></tr>`; }).join('')}</tbody></table>` : '';
    }
    const nW = J.cc.n + J.liv.n, nTot = per ? (C.nCommandes || 0) : L.length, nC = nTot - (C.nWebshop || 0);
    const AV = per ? C.aVenir : C.demain, avLib = per ? 'à venir : ' : 'demain : ';
    const pied = [nTot ? nTot + ' commande' + (nTot > 1 ? 's' : '') + (per ? ' sur ' + perLib() : '') + (nC ? ' dont ' + nC + ' au comptoir' : '') : (per ? 'aucune commande sur ' + perLib() : ''), nW ? 'webshop ' + fE(J.webshop) : '', C.aPreparer ? `<b>${C.aPreparer} à préparer</b>` : '', AV && AV.n ? avLib + AV.n + ' commande' + (AV.n > 1 ? 's' : '') + ' déjà prise' + (AV.n > 1 ? 's' : '') + ' (' + fE(AV.ca) + ')' : ''].filter(Boolean).join(' · ');
    // En vue Jour, rien que le jour : pas de série sur 14 jours.
    const gauche = per ? `<div><span class="db-lab">${(mois ? 'Le mois' : 'La semaine') + ' — jour par jour, par canal'}</span>${piles}</div>` : '';
    return `<div class="co-corps${per ? '' : ' un'}">${gauche}<div><span class="db-lab">${titre}</span>${per ? '' : `<span class="db-cdr db-cdr-inl" data-cmdliste="1">${S.cmdListeOuvert ? 'replier ▴' : 'voir les commandes ▾'}</span>`}${per || S.cmdListeOuvert ? table : ''}${pied ? `<div class="db-mini" style="margin-top:8px">${pied}</div>` : ''}</div></div>`;
  }
  /**
   * La carte « Commandes et canaux » : le comptoir (tickets caisse, dont clients pro), le click &
   * collect et la livraison (les commandes webshop du panel). Elle remplace « Comptoir et clients
   * pro » en vue Jour dès que les commandes du panel sont lues ; le détail des clients pro reste
   * sous le même déclencheur.
   */
  function canauxCarte(m, C) {
    const per = coPer(), J = per ? C.periode : C.jour, P = per ? null : proData(), PJ = P ? P.jour : null;
    const ouvert = !per && !!S.proOuvert, lue = J.joursLus > 0;
    const comptoir = lue ? J.comptoir : null, cc = J.cc, liv = J.liv, total = (comptoir || 0) + J.webshop;
    const pct = v => total > 0 ? 100 * v / total : 0;
    const caPro = m && m.caPro != null ? m.caPro : (PJ ? PJ.caPro : null), tkPro = m && m.ticketsPro != null ? m.ticketsPro : (PJ ? PJ.ticketsPro : null);
    const pro = !!(PJ && PJ.ticketsPro) || !!(m && m.ticketsPro > 0);
    // La part du pro dans le CA total : celle du Résultat quand il l'a comptée, sinon sur le total des canaux.
    const partPro = m && m.partPro != null ? m.partPro : (caPro != null && total > 0 ? Math.round(1000 * caPro / total) / 10 : null);
    const L = C.liste || [], nSt = (canal, st) => L.filter(c => c.canal === canal && c.statut === st).length;
    const bascule = per ? '' : `<span class="db-cdr" style="padding:0;margin-left:auto;white-space:nowrap">${ouvert ? 'replier ▴' : 'détail des clients pro ▾'}</span>`;
    const deplie = ouvert ? `<div class="db-split-det">${proCarte('detail')}</div>` : '';
    const X = per ? splitDe(m) : null;
    const mini = 'comptoir = tickets caisse (dont clients pro) · click & collect = commande webshop retirée en boutique · livraison = commande webshop livrée'
      + (per && J.joursEcoules && J.joursLus < J.joursEcoules ? ' · caisse lue sur ' + J.joursLus + ' jour' + (J.joursLus > 1 ? 's' : '') + ' sur ' + J.joursEcoules : '');
    const bar = lue && total > 0 ? `<div class="db-split-bar" title="comptoir ${fP(pct(comptoir))} · click & collect ${fP(pct(cc.ca))} · livraison ${fP(pct(liv.ca))}"><i class="c" style="width:${pct(comptoir).toFixed(1)}%"></i><i class="w" style="width:${pct(cc.ca).toFixed(1)}%"></i><i class="l" style="width:${pct(liv.ca).toFixed(1)}%"></i></div>` : '';
    const sC = lue ? [fP(pct(comptoir)) + ' du CA', fN(J.tickets) + ' ticket' + (J.tickets > 1 ? 's' : ''), J.tickets ? 'panier ' + fU(comptoir / J.tickets) : ''].filter(Boolean).join(' · ')
      + (caPro != null && tkPro ? `<br>dont clients pro <b>${fE(caPro)}</b> (${fN(tkPro)} ticket${tkPro > 1 ? 's' : ''}${PJ && PJ.aFacturer ? ', à facturer ' + fE(PJ.aFacturer) : ''})${partPro != null ? ' · ' + proPart(partPro, per) : ''}` : (X && X.manque ? '<br>' + esc(X.manque) : '')) : 'caisse pas encore lue';
    const sW = cc.n ? [fP(pct(cc.ca)) + ' du CA', cc.n + ' commande' + (cc.n > 1 ? 's' : ''), 'panier ' + fU(cc.ca / cc.n)].join(' · ') + (per ? '' : '<br>' + [nSt('cc', 'remise') ? `<b>${nSt('cc', 'remise')} remise${nSt('cc', 'remise') > 1 ? 's' : ''}</b>` : '', nSt('cc', 'prête') ? nSt('cc', 'prête') + ' prête' + (nSt('cc', 'prête') > 1 ? 's' : '') : '', nSt('cc', 'à préparer') + nSt('cc', 'en préparation') ? (nSt('cc', 'à préparer') + nSt('cc', 'en préparation')) + ' à préparer' : ''].filter(Boolean).join(' · ')) : 'aucune commande ' + (per ? 'sur ' + perLib() : ceJour());
    const sL = liv.n ? [fP(pct(liv.ca)) + ' du CA', liv.n + ' commande' + (liv.n > 1 ? 's' : ''), 'panier ' + fU(liv.ca / liv.n)].join(' · ') + (per ? '' : '<br>' + [nSt('liv', 'livrée') ? `<b>${nSt('liv', 'livrée')} livrée${nSt('liv', 'livrée') > 1 ? 's' : ''}</b>` : '', nSt('liv', 'en route') ? nSt('liv', 'en route') + ' en route' : ''].filter(Boolean).join(' · ')) : 'aucune livraison ' + (per ? 'sur ' + perLib() : ceJour());
    return `<div class="db-card db-split co${pro ? ' on' : ''}"><div class="ct"${per ? '' : ' data-prodrop="1" style="cursor:pointer"'}><span class="db-lab">Commandes et canaux — ${perLib()}</span><span class="db-mini">${esc(mini)}</span>${bascule}</div>${bar}
      <div class="db-split-g co3"><div class="c"><div class="k">Comptoir</div><div class="v">${comptoir != null ? fK(comptoir) : '—'}</div><div class="s">${sC}</div></div>
      <div class="w"><div class="k">Click &amp; collect</div><div class="v">${fK(cc.ca)}</div><div class="s">${sW}</div></div>
      <div class="l"><div class="k">Livraison</div><div class="v">${fK(liv.ca)}</div><div class="s">${sL}</div></div></div>${canauxCorps(C)}${deplie}</div>`;
  }
  const CO_SEUIL_MARGE = 50;
  const OFFRE_VERDICT = { tot: ['Trop tôt', 'tot'], garder: ['Garder', 'ok'], ajuster: ['Ajuster', 'att'], arreter: ['Arrêter', 'ko'] };
  /** La carte « Promotions et bundles » : une ligne par offre sur 7 jours, le verdict face à la référence. */
  function offresCarte() {
    const cle = cleOffres(), O = offresData(), err = S.err[cle], per = coPer(), lp = perLib();
    const lib = 'Promotions et bundles — ce qu’elles rapportent' + (per ? ' sur ' + lp : '');
    const mini = 'bundles = produits de la catégorie « Bundle & Promotion » du panel · promotions = celles posées par le cockpit sur les jours creux · référence : les 4 semaines d’avant';
    if (!O) { return `<div class="db-card db-offres"><div class="ct"><span class="db-lab">${lib}</span><span class="db-mini">${err ? esc(err) : (S.enCours[cle] || !S.aux[cle] ? 'lecture des tickets…' : esc((S.aux[cle] || {}).error || 'lecture impossible'))}</span></div></div>`; }
    const L = O.offres, K = O.kpi || {};
    const PJ = O.periodeJours || {}, F = O.fen || {};
    if (!L.length) { return `<div class="db-card db-offres"><div class="ct"><span class="db-lab">${lib}</span><span class="db-mini">${esc(mini)}</span></div><div class="db-mini" style="padding:0 16px 14px">Aucune offre en cours${per ? ' sur ' + lp : ''} : pas de bundle vendu${per && PJ.lus != null ? ' (' + PJ.lus + ' jour' + (PJ.lus > 1 ? 's' : '') + ' de tickets lu' + (PJ.lus > 1 ? 's' : '') + (PJ.jours ? ' sur ' + PJ.jours : '') + ')' : ''}, pas de promotion posée dans le cockpit.</div></div>`; }
    const spark = s => { const mx = Math.max(1, ...s); return `<span class="co-spark${s.length > 7 ? ' l' : ''}">${s.map((v, i) => `<i class="${i === s.length - 1 ? 'auj' : ''}" style="height:${Math.max(2, Math.round(100 * v / mx))}%"></i>`).join('')}</span>`; };
    const delta = o => o.delta == null ? '' : `<span class="co-d ${o.delta >= 0 ? 'ok' : 'ko'}">${o.delta >= 0 ? '+' : '−'}${nf(Math.abs(o.delta), 0)} %</span> `;
    const margeCoul = v => v >= 60 ? '#2d7a3e' : v >= 50 ? '#B26A00' : '#C0182B';
    const q = x => x == null ? '' : nf(x, 0);
    const lignes = L.map(o => { const v = OFFRE_VERDICT[o.verdict] || OFFRE_VERDICT.tot; const auj = o.auj || {}, pe = o.periode || {}; return `<tr><td class="off"><span class="co-type ${o.type === 'bundle' ? 'b' : 'p'}">${o.type === 'bundle' ? 'Bundle' : 'Promo'}</span><b style="display:inline">${esc(o.nom)}</b><small>${esc(o.regle || '')}</small></td><td>${(o.canaux || []).map(canalTag).join(' ')}</td><td class="mu">${o.depuis ? fD(o.depuis) : '—'}</td>${per ? `<td class="n">${pe.pieces != null ? '<b>' + q(pe.pieces) + '</b> · ' : ''}${fE(pe.ca)}</td>` : `<td class="n">${auj.ca || auj.pieces ? (auj.pieces != null ? '<b>' + q(auj.pieces) + '</b> · ' : '') + fE(auj.ca) : '<span class="mu">pas ce jour</span>'}</td>`}<td class="n">${o.marge != null ? `<span style="color:${margeCoul(o.marge)};font-weight:600">${fP0(o.marge)}</span>${o.coef != null ? ` <span class="mu">× ${nf(o.coef, 2)}</span>` : ''}` : '<span class="mu">—</span>'}</td>${per ? `<td>${spark(o.spark || [])}</td>` : ''}<td>${delta(o)}<span class="db-mini">${esc(o.mot || '')}</span></td><td><span class="co-verdict ${v[1]}">${v[0]}</span></td></tr>`; }).join('');
    const piecesJ = L.reduce((a, o) => a + ((o.auj || {}).pieces || 0), 0), nVendues = L.filter(o => (o.auj || {}).ca > 0 || (o.auj || {}).pieces > 0).length;
    // En vue Jour, les chiffres du jour seulement ; la marge et le verdict restent l'avis porté sur l'offre.
    const kpi = `<div class="db-obj-t4 co-kpi">
      ${per ? `<div><div class="k">CA des offres · ${lp}</div><div class="v">${fE(K.ca)}</div><div class="s">${K.part != null ? fP(K.part) + ' du CA ' + (S.vue === 'mois' ? 'du mois' : 'de la semaine') : '—'}${K.pieces ? ' · ' + nf(K.pieces, 0) + ' pièces' : ''}</div></div>
      <div><div class="k">Par jour lu</div><div class="v">${fE(PJ.lus ? K.ca / PJ.lus : 0)}</div><div class="s">${PJ.lus || 0} jour${PJ.lus > 1 ? 's' : ''} de tickets lu${PJ.lus > 1 ? 's' : ''}${PJ.jours ? ' sur ' + PJ.jours : ''}</div></div>` : `<div><div class="k">CA des offres · ${S.date === AUJ ? 'aujourd’hui' : 'ce jour'}</div><div class="v">${fE(K.caJour)}</div><div class="s">${piecesJ ? nf(piecesJ, 0) + ' pièce' + (piecesJ > 1 ? 's' : '') : 'pas de vente ce jour'}</div></div>
      <div><div class="k">Vendues ${S.date === AUJ ? 'aujourd’hui' : 'ce jour'}</div><div class="v">${nVendues} / ${L.length}</div><div class="s">offre${nVendues > 1 ? 's' : ''} avec au moins une vente</div></div>`}
      <div><div class="k">Marge brute des offres</div><div class="v">${K.marge != null ? fP0(K.marge) : '—'}</div><div class="s">${K.marge != null && K.marge < 100 ? 'coef × ' + nf(1 / (1 - K.marge / 100), 2) + ' · sur les bundles' + (per && F.jours ? ', ' + F.jours + ' jours' : '') : 'coût matière inconnu'}</div></div>
      <div><div class="k">Actives</div><div class="v">${K.bundles || 0} + ${K.promos || 0}</div><div class="s">${K.bundles || 0} bundle${K.bundles > 1 ? 's' : ''}, ${K.promos || 0} promotion${K.promos > 1 ? 's' : ''}${K.aAjuster ? ' · <b>' + K.aAjuster + ' à ajuster</b>' : ''}</div></div></div>`;
    return `<div class="db-card db-offres${K.aAjuster ? ' att' : ''}"><div class="ct"><span class="db-lab">${lib}</span><span class="db-mini">${esc(mini)}</span></div>${kpi}
      <div class="co-defil"><table class="db-pro-tab co-tab"><thead><tr><th>Offre</th><th>Canaux</th><th>Depuis</th>${per ? `<th class="n">${S.vue === 'mois' ? 'Le mois' : 'La semaine'}</th>` : `<th class="n">${S.date === AUJ ? 'Aujourd’hui' : 'Ce jour'}</th>`}<th class="n">Marge</th>${per ? '<th>Jour par jour</th>' : ''}<th>Face à la référence</th><th>Verdict</th></tr></thead><tbody>${lignes}</tbody></table></div>
      <div class="db-leg" style="padding-top:10px"><span><span class="co-verdict ok">Garder</span> l’offre rapporte : ≥ +8 % face à la référence, ou marge tenue</span><span><span class="co-verdict att">Ajuster</span> entre −3 % et +8 %, ou marge sous ${CO_SEUIL_MARGE} %</span><span><span class="co-verdict ko">Arrêter</span> sous −3 %</span><span><span class="co-verdict tot">Trop tôt</span> moins de 5 jours lus</span></div></div>`;
  }
  /** Les tuiles du mur mobile : le webshop du jour, et les offres sur 7 jours. */
  /* --- gestion de production (module franchisé) : paramètres, plan du jour, recuissons --- */
  function clePP() { return 'pp|' + S.shop + '|' + S.date; }
  function cheminPP() { return '/production/plan?shop=' + encodeURIComponent(S.shop) + '&date=' + S.date; }
  function ppData() { const P = S.aux[clePP()]; return P && !P.error ? P : null; }
  const PP_JOURS = ['', 'lundis', 'mardis', 'mercredis', 'jeudis', 'vendredis', 'samedis', 'dimanches'];
  const PP_VERDICT = { recuire: ['Recuire', 'ko'], tient: ['Tient', 'ok'], trop: ['Trop produit', 'att'], tard: ['Trop tard', 'mu'] };
  const ppDemain = () => { const t = new Date(AUJ + 'T12:00:00'); t.setDate(t.getDate() + 1); return t.toISOString().slice(0, 10); };
  /** Le brouillon des paramètres : une copie de ce que le serveur rend, modifiée à l'écran jusqu'à « Enregistrer ». */
  function ppBrouillon(P) {
    if (S.ppEdit && S.ppEdit.shop === S.shop) { return S.ppEdit; }
    const cats = {};
    (P.categories || []).forEach(c => { cats[c.cle] = { cuissons: (c.cuissons || []).slice(), plaque: c.plaque, limite: c.limite, nom: c.nom, catId: c.catId }; });
    S.ppEdit = { shop: S.shop, cuissons: P.params.cuissons.map(c => Object.assign({}, c)), regles: Object.assign({}, P.params.regles), categories: cats, modifie: false };
    return S.ppEdit;
  }
  /** La prévision heure par heure en mini-barres : la période en couleur, l'heure en cours en rouge. */
  function ppSpark(h, a, b, now) {
    const hs = []; for (let x = 5; x <= 19; x++) { hs.push(x); }
    const v = hs.map(x => +(h && h[x] || 0)), m = Math.max(0.0001, ...v);
    return `<span class="pp-spark" title="prévision heure par heure, 05 h → 19 h">${hs.map((x, i) => `<i class="${x + 1 > a && x < b ? 'fe' : ''}${now != null && x === Math.floor(now) ? ' now' : ''}" style="height:${Math.max(2, 100 * v[i] / m).toFixed(0)}%"></i>`).join('')}</span>`;
  }
  const ppH = t => { const m = /^(\d{1,2}):(\d{2})/.exec(t || ''); return m ? +m[1] + m[2] / 60 : null; };
  const ppPl = (pl, plaque) => pl == null ? (plaque ? '<span class="mu">—</span>' : '<span class="mu">à l’unité</span>') : (pl ? pl + ' × ' + plaque : '<span class="mu">—</span>');
  function ppOnglets(P) {
    const auj = S.date === AUJ;
    if (EMBED) { return P && P.base ? `<div class="pp-nav emb"><span class="pp-quand">base : ${P.base.lus.length} ${PP_JOURS[P.jourSemaine] || 'jours'} lus sur ${P.base.jours.length}${P.base.manquants.length ? ' · ' + P.base.manquants.length + ' à lire' : ''}</span></div>` : ''; }
    const o = [['plan', 'Plan du jour'], ['suivi', 'Suivi et recuissons'], ['params', 'Paramètres']];
    return `<div class="pp-nav">${o.map(([k, n]) => `<button data-pponglet="${k}" class="${S.ppOnglet === k ? 'on' : ''}">${n}</button>`).join('')}
      <span class="pp-quand">${S.date === AUJ ? 'aujourd’hui' : (S.date === ppDemain() ? 'demain' : fD(S.date))}${P && P.base ? ` · base : ${P.base.lus.length} ${PP_JOURS[P.jourSemaine] || 'jours'} lus sur ${P.base.jours.length}` : ''}</span>
      ${auj ? `<button class="pp-lien" data-ppdemain="1">Préparer demain ›</button>` : `<button class="pp-lien" data-auj="1">‹ Aujourd’hui</button>`}</div>`;
  }
  function ppCorps(mobile) {
    const cle = clePP(), P = ppData(), err = S.err[cle];
    if (!P) { return ppOnglets(null) + `<div class="db-card"><div class="db-mini" style="padding:16px">${err ? '<span class="ko">' + esc(err) + '</span>' : 'Lecture des ventes des dernières semaines, heure par heure, et des commandes du jour…'}</div></div>`; }
    const msg = S.ppMsg ? `<div class="pp-msg ${S.ppMsg.ok ? 'ok' : 'ko'}">${esc(S.ppMsg.texte)}</div>` : '';
    let h = ppOnglets(P) + msg;
    if (!P.base.lus.length) { h += `<div class="db-card"><div class="db-mini" style="padding:16px">Aucun des ${P.base.jours.length} derniers ${PP_JOURS[P.jourSemaine] || 'jours'} n’est encore lu : la prévision attend les tickets du panel${P.base.manquants.length ? ' (' + P.base.manquants.length + ' jour' + (P.base.manquants.length > 1 ? 's' : '') + ' à lire)' : ''}.</div></div>`; }
    if (S.ppOnglet === 'params') { return h + ppParams(P, mobile); }
    if (S.ppOnglet === 'suivi') { return h + ppSuivi(P, mobile); }
    return h + ppPlan(P, mobile);
  }
  function ppTuiles(P) {
    return `<div class="pp-cuis">${P.plan.map(c => { const f = P.faits && P.faits[c.id]; return `<div class="k${Math.min(c.k, 6)}${c.panel ? '' : ' loc'}"><div class="k">Cuisson ${c.k} · ${esc(c.nom)} · four ${c.four}</div><div class="v">${fN(c.total.pieces)} <small>pièces${c.total.plaques ? ' · ' + fN(c.total.plaques) + ' plaques' : ''}</small></div><div class="s"><b>${fE(c.total.ca)}</b> de CA à sortir · vente ${c.de}–${c.a} · ${nf(c.pct, 0)} % de la journée · ${c.total.categories} catégorie${c.total.categories > 1 ? 's' : ''}${c.total.cmd + c.total.ws ? ` · dont <b>${fN(c.total.cmd + c.total.ws)} commandées</b>` : ''}${f ? '<br><span class="pp-tag ok">validée</span>' : ''}${c.panel ? '' : '<br><span class="pp-tag loc">cuisson locale — absente du panel</span>'}</div></div>`; }).join('')}</div>`;
  }
  function ppPlan(P, mobile) {
    const tot = P.plan.reduce((a, c) => ({ p: a.p + c.total.pieces, pl: a.pl + c.total.plaques, ca: a.ca + (c.total.ca || 0), sp: a.sp + (c.total.sansPrix || 0) }), { p: 0, pl: 0, ca: 0, sp: 0 });
    const nCmd = (P.commandes || []).length;
    let h = `<div class="db-card"><div class="ct"><span class="db-lab">Les cuissons ${S.date === AUJ ? 'du jour' : (S.date === ppDemain() ? 'de demain' : 'du ' + fD(S.date))}</span><span class="db-mini">prévision = moyenne des ${P.base.semaines} derniers ${PP_JOURS[P.jourSemaine] || 'jours'}, heure par heure · + ${nf(P.params.regles.securite, 0)} % de sécurité · commandes et webshop inclus</span></div>${ppTuiles(P)}
      <div class="pp-pied"><b>${fN(tot.p)} pièces${tot.pl ? ' en ' + fN(tot.pl) + ' plaques' : ''} · ${fE(tot.ca)} de CA</b> à sortir (prix de vente du magasin${tot.sp ? ', ' + tot.sp + ' référence' + (tot.sp > 1 ? 's' : '') + ' sans prix' : ''}) · ${nCmd ? nCmd + ' commande' + (nCmd > 1 ? 's' : '') + ' à retirer' : (P.commandesLues ? 'aucune commande à retirer' : 'commandes du panel non lues')} · base : ${fN(P.base.piecesParJour)} pièces par ${(PP_JOURS[P.jourSemaine] || 'jour').replace(/s$/, '')} en moyenne${P.base.fermes.length ? ' · ' + P.base.fermes.length + ' jour' + (P.base.fermes.length > 1 ? 's' : '') + ' fermé' + (P.base.fermes.length > 1 ? 's' : '') + ' écarté' + (P.base.fermes.length > 1 ? 's' : '') : ''}${P.params.enregistre ? '' : ' · <b>paramètres proposés</b> : réglez-les dans l’onglet Paramètres'}</div></div>`;
    const suiv = P.suivi && P.suivi.cuisson ? P.suivi.cuisson.id : null;
    P.plan.forEach((c, i) => {
      const ouvert = S.ppOuvert[c.id] != null ? S.ppOuvert[c.id] : (suiv ? c.id === suiv : i === 0);
      const tete = `<div class="ct" data-ppcu="${c.id}" style="cursor:pointer"><span class="db-lab">Cuisson ${c.k} · ${esc(c.nom)} — four à ${c.four}, vente ${c.de}–${c.a}</span><span class="db-mini">${nf(c.pct, 0)} % de la journée · ${fN(c.total.pieces)} pièces${c.total.plaques ? ' · ' + fN(c.total.plaques) + ' plaques' : ''} · ${fE(c.total.ca)}</span><span class="db-cdr" style="padding:0;margin-left:auto;white-space:nowrap">${ouvert ? 'replier ▴' : 'voir le détail ▾'}</span></div>`;
      if (!ouvert) { h += `<div class="db-card">${tete}</div>`; return; }
      if (!c.lignes.length) { h += `<div class="db-card">${tete}<div class="db-mini" style="padding:12px 16px">Aucune catégorie cochée pour cette cuisson.</div></div>`; return; }
      // Les références à moins d'une demi-pièce, sans commande ni rien à cuire, encombrent sans rien dire : comptées en pied.
      const vues = c.lignes.filter(l => l.sortie > 0 || l.prevu >= 0.5 || l.cmd + l.ws > 0 || l.fait != null), cachees = c.lignes.length - vues.length;
      let g = '';
      const corps = mobile
        ? vues.map(l => { const t = l.cat !== g ? `<tr class="grp"><td colspan="3">${esc(l.cat)}</td></tr>` : ''; g = l.cat; return t + `<tr><td class="nom">${esc(l.nom)}<small>prévu ${fN(l.prevu)}${l.cmd + l.ws ? ' · + ' + fN(l.cmd + l.ws) + ' commandés' : ''}${l.stock ? ' · − ' + fN(l.stock) + ' en stock' : ''}</small></td><td class="n"><b>${fN(l.sortie)}</b></td><td class="n">${ppPl(l.plaques, l.plaque)}</td></tr>`; }).join('')
        : ppLignesPlan(vues, l => `<tr><td class="nom">${esc(l.nom)}</td><td>${ppSpark(l.h, ppH(l.zone[0]), ppH(l.zone[1]))} <small class="mu">${nf(l.fenetre, 0)} sur la période</small></td><td class="n">${nf(l.prevJ, l.prevJ < 10 ? 1 : 0)}</td><td class="n mu">${nf(l.part, 0)} %</td><td class="n">${fN(l.prevu)}</td><td class="n">${l.cmd >= 0.5 ? '<b>+ ' + fN(l.cmd) + '</b>' : '<span class="mu">—</span>'}</td><td class="n">${l.ws >= 0.5 ? '<b>+ ' + fN(l.ws) + '</b>' : '<span class="mu">—</span>'}</td><td class="n">${l.stock >= 0.5 ? '− ' + fN(l.stock) : '<span class="mu">—</span>'}</td><td class="n"><b>${fN(l.sortie)}</b>${l.fait != null ? `<small class="ok"> · fait ${fN(l.fait)}</small>` : ''}</td><td class="n">${ppPl(l.plaques, l.plaque)}</td><td class="n">${l.ca != null ? fE(l.ca) : '<span class="mu">sans prix</span>'}</td></tr>`);
      const tete2 = mobile ? '<tr><th>Produit</th><th class="n">À cuire</th><th class="n">Plaques</th></tr>'
        : `<tr><th>Produit</th><th>Prévision heure par heure</th><th class="n">Par jour<br><small>${P.base.semaines} dern. ${esc((PP_JOURS[P.jourSemaine] || 'jours'))}</small></th><th class="n">Part C${c.k}</th><th class="n">Prévu + ${nf(P.params.regles.securite, 0)} %</th><th class="n">Commandes</th><th class="n">Webshop</th><th class="n">Stock estimé</th><th class="n">À cuire</th><th class="n">Plaques</th><th class="n">CA</th></tr>`;
      h += `<div class="db-card">${tete}<div class="pp-defil"><table class="pp-tab"><thead>${tete2}</thead><tbody>${corps}
        ${mobile ? '' : `<tr class="tot"><td>Total cuisson ${c.k}</td><td></td><td></td><td></td><td class="n">${fN(c.total.prevu)}</td><td class="n">${c.total.cmd ? '+ ' + fN(c.total.cmd) : '—'}</td><td class="n">${c.total.ws ? '+ ' + fN(c.total.ws) : '—'}</td><td class="n">${c.total.stock ? '− ' + fN(c.total.stock) : '—'}</td><td class="n">${fN(c.total.pieces)}</td><td class="n">${c.total.plaques ? fN(c.total.plaques) : ''}</td><td class="n">${fE(c.total.ca)}</td></tr>`}</tbody></table></div>
        <div class="pp-pied">${cachees ? cachees + ' référence' + (cachees > 1 ? 's' : '') + ' à moins d’une demi-pièce prévue, rien à cuire, non affichée' + (cachees > 1 ? 's' : '') + '. ' : ''}<b>À cuire = prévision du jour × part de la cuisson × ${nf(1 + P.params.regles.securite / 100, 2).replace(/0$/, '')} + commandes et webshop à retirer d’ici la cuisson suivante − stock estimé</b>, arrondi à la plaque. ${i === 0 ? 'Le stock de départ vaut zéro : le panel ne rend pas les reports de la veille.' : 'Le stock estimé = sorti à la cuisson précédente − ventes prévues jusqu’à l’ouverture de celle-ci ; avant le four, l’onglet « Suivi et recuissons » le remplace par le réel.'}</div></div>`;
    });
    const C = P.commandes || [];
    if (C.length) {
      const nomCu = id => { const c = P.plan.find(x => x.id === id); return c ? 'C' + c.k : ''; };
      h += `<div class="db-card"><div class="ct"><span class="db-lab">Les commandes ${S.date === AUJ ? 'du jour' : 'à retirer'}</span><span class="db-mini">comptoir, clients pro et webshop — chacune entre dans la cuisson qui précède son retrait</span></div><div class="pp-defil"><table class="pp-tab"><thead><tr><th>Retrait</th><th>Canal</th><th>Articles</th><th class="n">Montant</th><th>Cuisson</th></tr></thead><tbody>${C.map(o => `<tr><td class="nom">${esc(o.heure)}</td><td><span class="pp-tag">${o.canal === 'cc' ? 'Click & collect' : (o.canal === 'liv' ? 'Livraison' : 'Comptoir')}</span></td><td>${o.sansDetail ? '<span class="mu">le panel ne joint pas les articles — non compté dans le plan</span>' : o.lignes.map(l => fN(l.q) + ' × ' + esc(l.nom)).join(' · ')}</td><td class="n">${fU(o.montant)}</td><td>${o.cuissons.map(id => `<span class="pp-tag k${(P.plan.find(x => x.id === id) || {}).k || 1}">${nomCu(id)}</span>`).join(' ') || '<span class="mu">hors production</span>'}</td></tr>`).join('')}</tbody></table></div></div>`;
    }
    return h;
  }
  /**
   * Les lignes d'une cuisson, rangées section (groupe du panel) › catégorie › produit : un en-tête de
   * section, un en-tête de catégorie, les produits, le sous-total de la catégorie, le total de la
   * section — en pièces à cuire, plaques et CA (prix de vente du magasin).
   */
  function ppLignesPlan(L, ligne) {
    const somme = arr => arr.reduce((a, l) => ({ p: a.p + l.sortie, pl: a.pl + (l.plaques || 0), ca: a.ca + (l.ca || 0), prevu: a.prevu + l.prevu, n: a.n + 1 }), { p: 0, pl: 0, ca: 0, prevu: 0, n: 0 });
    const st = (cls, lib, t) => `<tr class="${cls}"><td>${lib}</td><td></td><td></td><td></td><td class="n">${fN(t.prevu)}</td><td></td><td></td><td></td><td class="n">${fN(t.p)}</td><td class="n">${t.pl ? fN(t.pl) : ''}</td><td class="n">${fE(t.ca)}</td></tr>`;
    let h = '';
    const sections = [];
    L.forEach(l => { const g = l.groupe || l.cat; let s = sections[sections.length - 1]; if (!s || s.nom !== g) { s = { nom: g, cats: [] }; sections.push(s); } let c = s.cats[s.cats.length - 1]; if (!c || c.nom !== l.cat) { c = { nom: l.cat, lignes: [] }; s.cats.push(c); } c.lignes.push(l); });
    sections.forEach(s => {
      const plusieurs = s.cats.length > 1 || s.cats[0].nom !== s.nom;
      h += `<tr class="sec"><td colspan="11">${esc(s.nom)}</td></tr>`;
      s.cats.forEach(c => {
        if (plusieurs) { h += `<tr class="grp"><td colspan="11">${esc(c.nom)}</td></tr>`; }
        h += c.lignes.map(ligne).join('');
        if (plusieurs && c.lignes.length > 1) { h += st('stc', 'Sous-total ' + esc(c.nom), somme(c.lignes)); }
      });
      h += st('sts', 'Total ' + esc(s.nom), somme(s.cats.flatMap(c => c.lignes)));
    });
    return h;
  }
  function ppSuivi(P, mobile) {
    if (S.date !== AUJ) { return `<div class="db-card"><div class="db-mini" style="padding:16px">Le suivi et les recuissons se calculent le jour même, sur les ventes réelles.${EMBED ? ' Choisissez aujourd’hui dans la barre du haut.' : ' <button class="pp-lien" data-auj="1">Revenir à aujourd’hui ›</button>'}</div></div>`; }
    const V = P.suivi;
    if (!V) { return `<div class="db-card"><div class="db-mini" style="padding:16px">Plus de cuisson aujourd’hui : la dernière période de vente est ouverte. ${EMBED ? 'Le plan de demain : choisissez demain dans la barre du haut.' : 'Le plan de demain est dans « Préparer demain ».'}</div></div>`; }
    const T = V.total, c = P.plan.find(x => x.id === V.cuisson.id) || { k: '?' }, fait = P.faits && P.faits[V.cuisson.id];
    const ec = T.ecart, ecTxt = ec == null ? '—' : (ec >= 0 ? '+ ' : '− ') + nf(Math.abs(ec), 0) + ' %';
    const rec = V.lignes.filter(l => l.verdict === 'recuire'), trop = V.lignes.filter(l => l.verdict === 'trop');
    const tete = `<div class="db-card"><div class="ct"><span class="db-lab">La journée en cours</span><span class="pp-now">${esc(V.maintenant)}</span><span class="db-mini">${V.ventesLues ? 'tickets relus toutes les 10 minutes' : 'tickets du jour pas encore lus'} · ${ecTxt} face à la prévision à la même heure, sur les produits de la cuisson ${c.k}</span></div>
      <div class="pp-cuis">${P.plan.map(x => { const avant = ppH(x.de) <= ppH(V.maintenant), ici = x.id === V.cuisson.id; return `<div class="k${Math.min(x.k, 6)}${x.panel ? '' : ' loc'}"><div class="k">Cuisson ${x.k} · ${esc(x.nom)} · four ${x.four}</div><div class="v">${ici ? fN(T.plaques || T.aEnfourner) + ` <small>${T.plaques ? 'plaques · ' + fN(T.aEnfourner) + ' pièces' : 'pièces'}</small>` : (avant ? (P.faits && P.faits[x.id] ? '✓' : fN(x.total.pieces)) : '—')}</div><div class="s">${ici ? `plan de la nuit : ${fN(T.planPlaques || T.plan)} ${T.planPlaques ? 'plaques' : 'pièces'} · <b>recalculée à ${esc(V.maintenant)}</b>` : (avant ? (P.faits && P.faits[x.id] ? 'validée' : 'sortie prévue (non validée)') : 'se recalculera avant le four')}</div></div>`; }).join('')}</div></div>`;
    const alerte = `<div class="pp-alerte${rec.length ? '' : ' ok'}"><b>Cuisson ${c.k} — ${esc(V.cuisson.nom)}, four à ${esc(V.cuisson.four)} : ${rec.length ? fN(T.aEnfourner) + ' pièces' + (T.plaques ? ' en ' + fN(T.plaques) + ' plaques' : '') + ' à enfourner · ' + fE(T.ca) + ' de CA' : 'rien à recuire'}${T.plan ? ' (plan de la nuit : ' + fN(T.plan) + ')' : ''}</b><span class="mu">${rec.length} produit${rec.length > 1 ? 's' : ''} à recuire${trop.length ? ' · ' + trop.length + ' en trop, à ne pas recuire (' + trop.slice(0, 4).map(l => esc(l.nom)).join(', ') + (trop.length > 4 ? '…' : '') + ')' : ''}</span>
      ${fait ? '<span class="pp-tag ok" style="margin-left:auto">cuisson validée</span>' : `<button class="pp-btn" data-ppvalider="${V.cuisson.id}"${S.ppEnvoi ? ' disabled' : ''}>${S.ppEnvoi ? 'Envoi…' : 'Valider la cuisson ' + c.k}</button>`}</div>`;
    let g = '';
    const lignes = V.lignes.map(l => { const t = l.cat !== g ? `<tr class="grp"><td colspan="${mobile ? 3 : 10}">${esc(l.cat)}</td></tr>` : ''; g = l.cat; const vd = PP_VERDICT[l.verdict] || PP_VERDICT.tient;
      const detail = l.verdict === 'recuire' ? (l.plaques ? `<b>${l.plaques} plaque${l.plaques > 1 ? 's' : ''}</b> (${fN(l.aEnfourner)})` : `<b>${fN(l.aEnfourner)}</b> à préparer`) : (l.verdict === 'trop' ? 'couvre ' + nf(l.couverture, 0) + ' % du besoin : ne pas recuire' : (l.verdict === 'tard' ? 'après ' + esc(l.limite) + ' : plus de recuisson' : 'rien à recuire'));
      if (mobile) { return t + `<tr><td class="nom">${esc(l.nom)}<small>vendu ${fN(l.vendu)} / prévu ${fN(l.prevuMaintenant)} · stock ${fN(l.stock)} · besoin ${fN(l.besoin)} → ${esc(l.jusqua)}</small></td><td><span class="pp-tag ${vd[1]}">${vd[0]}</span></td><td class="n">${l.verdict === 'recuire' ? (l.plaques ? l.plaques + ' × ' + l.plaque : fN(l.aEnfourner)) : '<span class="mu">—</span>'}</td></tr>`; }
      const e = l.ecart, couv = Math.min(100, l.couverture);
      return t + `<tr><td class="nom">${esc(l.nom)}<small>${l.plaque ? 'plaque de ' + l.plaque : 'à l’unité'}${l.limite ? ' · recuisson jusqu’à ' + esc(l.limite) : ''}</small></td><td>${ppSpark(l.h, 5, ppH(V.maintenant), ppH(V.maintenant))}</td><td class="n">${fN(l.produit)}</td><td class="n"><b>${fN(l.vendu)}</b>${l.jete ? `<small class="mu"> · ${fN(l.jete)} jeté${l.jete > 1 ? 's' : ''}</small>` : ''}</td><td class="n mu">${fN(l.prevuMaintenant)}</td><td class="n" style="color:${e == null ? 'inherit' : (e > 8 ? '#2d7a3e' : (e < -8 ? '#C0182B' : 'inherit'))}">${e == null ? '—' : (e >= 0 ? '+ ' : '− ') + nf(Math.abs(e), 0) + ' %'}</td><td class="n">${fN(l.stock)}</td><td class="n"><span class="pp-bar"><i class="${vd[1]}" style="width:${couv.toFixed(0)}%"></i></span>${fN(l.besoin)} <small class="mu">→ ${esc(l.jusqua)}</small></td><td class="n mu">${l.plan ? fN(l.plan) : '—'}</td><td><span class="pp-tag ${vd[1]}">${vd[0]}</span> <small class="mu">${detail}</small></td></tr>`; }).join('');
    const th = mobile ? '<tr><th>Produit</th><th>Verdict</th><th class="n">À enfourner</th></tr>'
      : `<tr><th>Produit</th><th>Prévision · déjà passée</th><th class="n">Sorti avant</th><th class="n">Vendu</th><th class="n">Prévu à ${esc(V.maintenant)}</th><th class="n">Écart</th><th class="n">Stock</th><th class="n">Besoin + ${nf(P.params.regles.securite, 0)} %</th><th class="n">Plan de la nuit</th><th>À enfourner à ${esc(V.cuisson.four)}</th></tr>`;
    return tete + `<div class="db-card"><div class="ct"><span class="db-lab">Recalcul de la cuisson ${c.k} — ${esc(V.cuisson.nom)}, four à ${esc(V.cuisson.four)}</span><span class="db-mini">stock réel face au besoin jusqu’à la cuisson suivante de chaque catégorie</span></div>${alerte}
      ${V.lignes.length ? `<div class="pp-defil"><table class="pp-tab"><thead>${th}</thead><tbody>${lignes}</tbody></table></div>` : '<div class="db-mini" style="padding:12px 16px">Aucune catégorie cochée pour cette cuisson.</div>'}
      <div class="pp-pied"><b>À enfourner = besoin − stock, arrondi à la plaque</b> — stock = sorti aux cuissons d’avant (validé, sinon prévu) − vendu (tickets du panel) − jeté (poubelle du panel) ; besoin = prévision des heures qui restent jusqu’à la cuisson suivante de la catégorie × ${nf(1 + P.params.regles.securite / 100, 2).replace(/0$/, '')} + commandes et webshop à retirer d’ici là. Recuisson sous ${nf(P.params.regles.seuilRecuisson, 0)} % de couverture ; « trop produit » au-delà de ${nf(P.params.regles.seuilTrop, 0)} %. « Valider » enregistre ces quantités comme enfournées : la cuisson suivante part de là.</div></div>`;
  }
  function ppParams(P, mobile) {
    const E = ppBrouillon(P), C = E.cuissons, R = E.regles;
    const tot = C.reduce((a, c) => a + (+c.pct || 0), 0), totOk = Math.abs(tot - 100) <= 0.5;
    const dp = P.dayparts || [];
    const cuis = `<div class="pp-cuis pp-cuis-ed">${C.map((c, i) => `<div class="k${Math.min(i + 1, 6)}${c.daypart ? '' : ' loc'}"><div class="k">Cuisson ${i + 1}${C.length > 1 ? ` <button class="pp-x" data-ppdel="${i}" title="retirer cette cuisson">×</button>` : ''}</div>
      <input class="pp-in l" data-ppin="c:${i}:nom" value="${esc(c.nom)}" maxlength="40">
      <div class="pp-ligne"><input class="pp-in" type="number" min="0" max="100" step="1" data-ppin="c:${i}:pct" value="${esc(c.pct)}"> <span>% de la journée</span></div>
      <div class="pp-ligne">vente <input class="pp-in t" inputmode="numeric" maxlength="5" placeholder="hh:mm" data-ppin="c:${i}:de" value="${esc(c.de)}"> → <input class="pp-in t" inputmode="numeric" maxlength="5" placeholder="hh:mm" data-ppin="c:${i}:a" value="${esc(c.a)}"></div>
      <div class="s">${c.daypart ? '<span class="pp-tag">panel</span> ' + esc((dp.find(d => d.id === c.daypart) || {}).nom || '') : '<span class="pp-tag loc">locale — à créer dans le panel</span>'} · four ${(() => { const h = ppH(c.de); return h == null ? '—' : String(Math.floor(Math.max(0, h - R.avance / 60))).padStart(2, '0') + ':' + String(Math.round((Math.max(0, h - R.avance / 60) % 1) * 60)).padStart(2, '0'); })()}</div></div>`).join('')}
      ${C.length < 6 ? '<button class="pp-ajout" data-ppadd="1">+ Ajouter une cuisson</button>' : ''}</div>
      <div class="pp-pied ${totOk ? '' : 'ko'}"><b>Total : ${nf(tot, 0)} %${totOk ? '' : ' — il faut 100 %'}.</b> Les périodes de vente du panel (${dp.length ? dp.map(d => esc(d.nom) + ' ' + d.de + '–' + d.a).join(', ') : 'aucune lue'}) donnent les cuissons ; une cuisson locale vit ici tant qu’elle n’est pas créée dans le panel. Four = ouverture de la période − <input class="pp-in s" type="number" min="0" max="180" data-ppin="r:avance" value="${esc(R.avance)}"> min.</div>`;
    // La matrice : les catégories vendues ces semaines-là, par groupe.
    const cats = (P.categories || []).slice();
    let g = null;
    const lignes = cats.map(cv => { const e = E.categories[cv.cle] || { cuissons: [], plaque: null, limite: null }; const t = cv.groupe !== g ? `<tr class="grp"><td colspan="${C.length + 4}">${esc(cv.groupe || 'Sans groupe')}</td></tr>` : ''; g = cv.groupe;
      return t + `<tr><td class="nom">${esc(cv.nom)}<small>${nf(cv.parJour, cv.parJour < 10 ? 1 : 0)} pièces par jour${cv.auto && !E.modifie ? ' · proposé' : ''}</small></td>${C.map(c => { const on = e.cuissons.includes(c.id), v = cv.ventesParCuisson ? cv.ventesParCuisson[c.id] : null; return `<td class="c"><button class="pp-chk${on ? ' on' : ''}" data-ppchk="${esc(cv.cle)}|${c.id}" title="${on ? 'retirer' : 'cocher'}"></button>${v != null && !mobile ? `<small>${nf(v, 0)} %</small>` : ''}</td>`; }).join('')}
        <td class="n"><input class="pp-in" type="number" min="1" max="500" placeholder="—" data-ppin="k:${esc(cv.cle)}:plaque" value="${e.plaque == null ? '' : esc(e.plaque)}"></td><td class="n"><input class="pp-in t" inputmode="numeric" maxlength="5" placeholder="hh:mm" data-ppin="k:${esc(cv.cle)}:limite" value="${e.limite == null ? '' : esc(e.limite)}"></td></tr>`; }).join('');
    const matrice = `<div class="pp-defil"><table class="pp-tab pp-mat"><thead><tr><th>Catégorie</th>${C.map((c, i) => `<th class="c">C${i + 1} · ${esc(c.nom)}<br><small>${esc(c.de)}–${esc(c.a)}</small></th>`).join('')}<th class="n">Pièces / plaque</th><th class="n">Dernière recuisson</th></tr></thead><tbody>${lignes}</tbody></table></div>
      <div class="pp-pied">Cocher = la catégorie se cuit pour cette période ; la part de chaque cuisson reprend les % de la journée, renormalisés sur les cases cochées. Sous chaque case : la part de ses ventes dans la période (les ${P.base.semaines} derniers ${PP_JOURS[P.jourSemaine] || 'jours'}). Sans pièces par plaque, le plan compte à l’unité ; sans dernière recuisson, elle reste possible jusqu’à la dernière cuisson cochée.</div>`;
    const inp = (k, min, max, step) => `<input class="pp-in" type="number" min="${min}" max="${max}" step="${step || 1}" data-ppin="r:${k}" value="${esc(R[k])}">`;
    const chk = k => `<button class="pp-chk${R[k] ? ' on' : ''}" data-ppchk="r|${k}"></button>`;
    const regles = `<div class="pp-regles">
      <div class="r"><b>Base de prévision</b>${inp('semaines', 1, 12)} dernières semaines, le même jour, heure par heure<small>un jour fermé (aucun ticket) est écarté de la moyenne</small></div>
      <div class="r"><b>Sécurité</b>+ ${inp('securite', 0, 50, 0.5)} % sur la prévision de chaque cuisson<small>manquer coûte une vente, trop produire coûte la matière</small></div>
      <div class="r"><b>Arrondi</b>à la plaque pleine, au moins ${inp('minPlaques', 0, 10)} plaque<small>13 croissants et 12 par plaque = 2 plaques</small></div>
      <div class="r"><b>Commandes</b>${chk('commandes')} comptoir et clients pro ${chk('webshop')} webshop<small>une commande s’ajoute à la cuisson qui précède son retrait</small></div>
      <div class="r"><b>Recuisson</b>conseillée sous ${inp('seuilRecuisson', 0, 100)} % de couverture<small>couverture = stock ÷ besoin jusqu’à la cuisson suivante</small></div>
      <div class="r"><b>Trop produit</b>au-delà de ${inp('seuilTrop', 100, 400)} % de couverture<small>le suivi dit alors de ne pas recuire</small></div></div>`;
    const actions = `<span style="margin-left:auto;display:flex;gap:8px;align-items:center">${E.modifie ? '<button class="pp-lien" data-ppreset="1">Annuler les modifications</button>' : ''}<button class="pp-btn" data-ppsave="1"${!totOk || S.ppEnvoi ? ' disabled' : ''}>${S.ppEnvoi ? 'Enregistrement…' : 'Enregistrer'}</button></span>`;
    return `<div class="db-card"><div class="ct" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap"><span class="db-lab">1 · Les cuissons de la journée</span><span class="db-mini">${P.params.enregistre ? 'enregistré' + (P.params.maj ? ' le ' + fD(String(P.params.maj).slice(0, 10)) : '') : 'proposé à partir des périodes de vente du panel — à enregistrer'}</span>${actions}</div>${cuis}</div>
      <div class="db-card"><div class="ct"><span class="db-lab">2 · Quelles catégories à quelles cuissons</span><span class="db-mini">${cats.length} catégorie${cats.length > 1 ? 's' : ''} vendue${cats.length > 1 ? 's' : ''} ces ${P.base.semaines} dernières semaines</span></div>${matrice}</div>
      <div class="db-card"><div class="ct" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap"><span class="db-lab">3 · Les règles de prévision et de recuisson</span>${actions}</div>${regles}</div>`;
  }
  /** L'écran Production — sur ordinateur seulement (pas d'écran au téléphone, demande du 03/10/2026). */
  function rendProduction() { return `<div class="pp-vue">${ppCorps(false)}</div>`; }
  /** Le champ actif garde son focus quand l'écran se redessine. */
  function ppGarder() { const a = document.activeElement; return a && a.dataset && a.dataset.ppin && $.contains(a) ? a.dataset.ppin : null; }
  function ppRestaurer(k) { if (!k) { return; } const el = $.querySelector(`[data-ppin="${k.replace(/"/g, '\\"')}"]`); if (el) { el.focus(); } }
  /** « 6h30 », « 6:30 », « 630 » → « 06:30 » ; tel quel sinon (le serveur refusera et le dira). */
  function ppNormH(v) { const m = /^\s*(\d{1,2})\s*[:h.]?\s*(\d{2})?\s*$/i.exec(v || ''); if (!m) { return v; } const h = +m[1], mi = +(m[2] || 0); return h <= 24 && mi < 60 ? String(h).padStart(2, '0') + ':' + String(mi).padStart(2, '0') : v; }
  function ppPoser(chemin, val) {
    const E = S.ppEdit; if (!E) { return; }
    if (/:(de|a|limite)$/.test(chemin) && val !== '') { val = ppNormH(val); }
    const [t, a, b] = chemin.split(':');
    if (t === 'c') { const c = E.cuissons[+a]; if (!c) { return; } c[b] = b === 'pct' ? (val === '' ? 0 : +val) : val; }
    else if (t === 'r') { E.regles[a] = val === '' ? 0 : +val; }
    else if (t === 'k') { const e = E.categories[a] = E.categories[a] || { cuissons: [], plaque: null, limite: null }; e[b] = b === 'plaque' ? (val === '' ? null : Math.max(1, Math.round(+val))) : (val === '' ? null : val); }
    E.modifie = true; S.ppMsg = null;
  }
  function ppBrancher() {
    $.querySelectorAll('[data-pponglet]').forEach(b => b.addEventListener('click', () => { S.ppOnglet = b.dataset.pponglet; S.ppMsg = null; rendre(); }));
    $.querySelectorAll('[data-ppdemain]').forEach(b => b.addEventListener('click', () => { S.date = ppDemain(); urlMaj(); charger(false); }));
    $.querySelectorAll('[data-ppcu]').forEach(b => b.addEventListener('click', () => { const id = b.dataset.ppcu; const P = ppData(); const suiv = P && P.suivi && P.suivi.cuisson ? P.suivi.cuisson.id : null; const ouvert = S.ppOuvert[id] != null ? S.ppOuvert[id] : (suiv ? id === suiv : P && P.plan[0] && P.plan[0].id === id); S.ppOuvert[id] = !ouvert; rendre(); }));
    $.querySelectorAll('[data-ppin]').forEach(i => i.addEventListener('change', () => { ppPoser(i.dataset.ppin, i.value); rendre(); }));
    $.querySelectorAll('[data-ppchk]').forEach(b => b.addEventListener('click', () => {
      const E = S.ppEdit; if (!E) { return; }
      const [cle, id] = b.dataset.ppchk.split('|');
      if (cle === 'r') { E.regles[id] = !E.regles[id]; }
      else { const e = E.categories[cle] = E.categories[cle] || { cuissons: [], plaque: null, limite: null }; e.cuissons = e.cuissons.includes(id) ? e.cuissons.filter(x => x !== id) : E.cuissons.map(c => c.id).filter(x => x === id || e.cuissons.includes(x)); }
      E.modifie = true; S.ppMsg = null; rendre(); }));
    $.querySelectorAll('[data-ppadd]').forEach(b => b.addEventListener('click', () => {
      const E = S.ppEdit; if (!E || E.cuissons.length >= 6) { return; }
      const der = E.cuissons[E.cuissons.length - 1]; let n = 1; while (E.cuissons.some(c => c.id === 'c' + n)) { n++; }
      E.cuissons.push({ id: 'c' + n, nom: 'Cuisson ' + (E.cuissons.length + 1), de: der ? der.a : '17:00', a: '19:00', pct: 0, daypart: null }); E.modifie = true; rendre(); }));
    $.querySelectorAll('[data-ppdel]').forEach(b => b.addEventListener('click', () => {
      const E = S.ppEdit; if (!E || E.cuissons.length <= 1) { return; }
      const c = E.cuissons.splice(+b.dataset.ppdel, 1)[0];
      Object.values(E.categories).forEach(e => { e.cuissons = e.cuissons.filter(x => x !== c.id); }); E.modifie = true; rendre(); }));
    $.querySelectorAll('[data-ppreset]').forEach(b => b.addEventListener('click', () => { S.ppEdit = null; S.ppMsg = null; rendre(); }));
    $.querySelectorAll('[data-ppsave]').forEach(b => b.addEventListener('click', () => {
      const E = S.ppEdit; if (!E || S.ppEnvoi) { return; }
      S.ppEnvoi = true; rendre();
      ecrire('/production/plan/params', { shop: S.shop, params: { cuissons: E.cuissons, categories: E.categories, regles: E.regles } })
        .then(() => { S.ppEnvoi = false; S.ppEdit = null; S.ppMsg = { ok: true, texte: 'Paramètres enregistrés : le plan du jour se recalcule.' }; lireAux(clePP(), cheminPP(), true); rendre(); })
        .catch(e => { S.ppEnvoi = false; S.ppMsg = { ok: false, texte: 'Non enregistré : ' + e.message }; rendre(); });
    }));
    $.querySelectorAll('[data-ppvalider]').forEach(b => b.addEventListener('click', () => {
      const P = ppData(); if (!P || !P.suivi || S.ppEnvoi) { return; }
      const lignes = {}; P.suivi.lignes.forEach(l => { lignes[l.pid] = l.aEnfourner; });
      S.ppEnvoi = true; rendre();
      ecrire('/production/plan/fait', { shop: S.shop, date: S.date, cuisson: b.dataset.ppvalider, lignes })
        .then(() => { S.ppEnvoi = false; S.ppMsg = { ok: true, texte: 'Cuisson validée : ces quantités comptent comme enfournées.' }; lireAux(clePP(), cheminPP(), true); rendre(); })
        .catch(e => { S.ppEnvoi = false; S.ppMsg = { ok: false, texte: 'Non validée : ' + e.message }; rendre(); });
    }));
  }

  /* --- les invendus et la poubelle : ce que le panel sait des pièces jetées (route /shops/{id}/products/waste) --- */
  function cleInv() { return 'inv|' + S.shop + '|' + (coPer() ? bornes().join('|') : S.date); }
  function cheminInv() { const b = '/exploitation/invendus?shop=' + encodeURIComponent(S.shop); if (!coPer()) { return b + '&date=' + S.date; } const [du, au] = bornes(); return b + '&du=' + du + '&au=' + au; }
  function invData() { const I = S.aux[cleInv()]; return I && !I.error ? I : null; }
  const INV_TAG = { expiration: 'fdj', damage: 'casse', tasting: 'degu', quality: 'qual', carryover: 'rep' };
  function invTag(p) { return `<span class="db-inv-tag ${INV_TAG[p.motif] || ''}" title="${esc(p.motifPieces ? p.motifPieces + ' pièce' + (p.motifPieces > 1 ? 's' : '') + ' pour ce motif' : '')}">${esc(p.motifLib || p.motif || 'sans motif')}</span>`; }
  /** La carte « Invendus et poubelle » : les pièces jetées, leur coût de production (retranché du résultat), la valeur de vente perdue, le détail par produit. */
  function invCarte(mobile) {
    const cle = cleInv(), I = invData(), err = S.err[cle], per = coPer();
    const quand = per ? perLib() : (S.date === AUJ ? 'la journée' : 'ce jour'), lib = 'Invendus et poubelle — ' + (per ? perLib() : 'la journée');
    const sous = 'pièces jetées déclarées en caisse · leur coût de production se retranche du résultat';
    const carte = (corps, mini) => mobile ? `<div class="db-inv mob"><div class="db-notej-t" style="padding:4px 4px 0">Invendus et poubelle<small>${esc(mini || sous)}</small></div>${corps}</div>`
      : `<div class="db-card db-inv"><div class="ct"><span class="db-lab">${lib}</span><span class="db-mini${err ? ' ko' : ''}">${esc(mini || sous)}</span></div>${corps}</div>`;
    if (!I) { return carte('', err || 'lecture de la poubelle…'); }
    const note = I.report && I.report.motif ? `<div class="db-mini db-inv-note">${esc(I.report.motif)}</div>` : '';
    if (!I.lu) { return carte(`<div class="db-mini" style="padding:${mobile ? '4px 4px 8px' : '12px 16px'}">Le panel ne répond pas : rien n’est retranché du résultat.</div>${note}`); }
    if (!I.declare) { return carte(`<div class="db-mini" style="padding:${mobile ? '4px 4px 8px' : '12px 16px'}">Rien déclaré au panel ${per ? 'sur ' + perLib() : ceJour()} : aucune pièce jetée encodée en caisse, rien n’est retranché du résultat.</div>${note}`); }
    const M = I.parMotif || [], P = I.produits || [], jour = !per && P.some(p => p.vendus != null);
    const kpi = `<div class="db-obj-t4 db-pro-kpi db-inv-kpi">
      <div><div class="k">Jetées</div><div class="v">${fN(I.pieces)}</div><div class="s">pièce${I.pieces > 1 ? 's' : ''} · ${I.references} référence${I.references > 1 ? 's' : ''} ${per ? 'sur ' + perLib() : ceJour()}</div></div>
      <div><div class="k">Coût de production</div><div class="v ko">${fE(I.cout)}</div><div class="s">${['retranché du résultat', I.coutBrut > 0 ? fE(I.coutBrut) + ' TTC au panel' : '', I.auCatalogue ? I.auCatalogue + ' réf. au coût de recette actuel' : '', I.sansCout ? '<b>' + I.sansCout + ' réf. sans coût connu</b>' : ''].filter(Boolean).join(' · ')}</div></div>
      <div><div class="k">${mobile ? 'Vente perdue' : 'Valeur de vente perdue'}</div><div class="v">${fE(I.caPerdu)}</div><div class="s">${I.perduCatalogue ? (I.perduCatalogue === I.references ? 'au prix de vente du catalogue' : 'au prix de vente du panel, ' + I.perduCatalogue + ' réf. au catalogue') : 'au prix de vente du panel'}</div></div>
      <div><div class="k">Motifs</div><div class="v">${M.length ? esc(M[0].lib) : '—'}</div><div class="s">${M.map(x => esc(x.lib) + ' ' + fN(x.pieces)).join(' · ')}</div></div>
    </div>`;
    const nMax = mobile ? 8 : 12;
    // Sur ordinateur, six colonnes ; au téléphone, quatre : les vendues et la valeur perdue passent sous le nom.
    const vd = p => p.vendus != null ? fN(p.vendus) + ' vendue' + (p.vendus > 1 ? 's' : '') + (p.taux != null ? ' · ' + fP(p.taux) + ' jeté' : '') : '';
    const table = mobile
      ? `<table class="db-pro-tab db-inv-tab"><thead><tr><th>Produit</th><th>Motif</th><th class="n">Jetées</th><th class="n">Coût</th></tr></thead><tbody>${P.slice(0, nMax).map(p => `<tr><td class="nom">${esc(p.nom)}<small>${esc([p.categorie, jour ? vd(p) : '', p.caPerdu ? fE(p.caPerdu) + ' de vente perdue' : ''].filter(Boolean).join(' · '))}</small></td><td>${invTag(p)}</td><td class="n">${fN(p.pieces)}</td><td class="n">${fE(p.cout)}</td></tr>`).join('')}${P.length > nMax ? `<tr><td colspan="4" class="mu">… et ${P.length - nMax} ${P.length - nMax > 1 ? 'autres références' : 'autre référence'}</td></tr>` : ''}</tbody></table>`
      : `<table class="db-pro-tab db-inv-tab"><thead><tr><th>Produit</th><th>Motif</th><th class="n">Jetées</th>${jour ? '<th class="n">Vendues</th>' : ''}<th class="n">Coût</th><th class="n">Valeur perdue</th></tr></thead><tbody>${P.slice(0, nMax).map(p => `<tr><td class="nom">${esc(p.nom)}${p.categorie ? `<small>${esc(p.categorie)}</small>` : ''}</td><td>${invTag(p)}</td><td class="n">${fN(p.pieces)}</td>${jour ? `<td class="n mu">${p.vendus != null ? fN(p.vendus) + (p.taux != null ? ' <small>(' + fP(p.taux) + ' jeté)</small>' : '') : '—'}</td>` : ''}<td class="n">${fE(p.cout)}</td><td class="n mu">${fE(p.caPerdu)}</td></tr>`).join('')}${P.length > nMax ? `<tr><td colspan="${jour ? 6 : 5}" class="mu">… et ${P.length - nMax} ${P.length - nMax > 1 ? 'autres références' : 'autre référence'}</td></tr>` : ''}</tbody></table>`;
    // Le détail des saisies (heure, opérateur, quantité) s'ouvre dans une modale, comme depuis la ligne du P&L.
    const lien = `<div class="db-inv-lien"><button type="button" data-invmodale="1">Les saisies une par une — heure, opérateur, quantité ▸</button></div>`;
    if (mobile) { return carte(`${kpi}${lien}<div class="db-pro-corps un"><div><span class="db-lab">Par produit</span>${table}</div></div>${note}`); }
    // Sur ordinateur, le détail par produit se déplie sous les chiffres de tête.
    const ouvert = !!S.invOuvert;
    return `<div class="db-card db-inv"><div class="ct" data-invdrop="1" style="cursor:pointer"><span class="db-lab">${lib}</span><span class="db-mini">${esc(sous)}</span><span class="db-cdr" style="padding:0;margin-left:auto;white-space:nowrap">${ouvert ? 'replier ▴' : 'voir le détail ▾'}</span></div>${kpi}${lien}${ouvert ? `<div class="db-pro-corps un"><div><span class="db-lab">Par produit — du plus coûteux au moins coûteux</span>${table}</div></div>` : ''}${note}</div>`;
  }
  /* --- la modale des saisies d'invendus : chaque pièce jetée telle qu'encodée en caisse, avec son heure et son
   * opérateur (journal des mouvements de la base partagée), sous le total par produit du panel. Elle s'ouvre
   * depuis la ligne « − Invendus et poubelle » du P&L court et depuis la carte. --- */
  function cleInvD() { return 'invd|' + S.shop + '|' + (coPer() ? bornes().join('|') : S.date); }
  function cheminInvD() { const b = '/exploitation/invendus/detail?shop=' + encodeURIComponent(S.shop); if (!coPer()) { return b + '&date=' + S.date; } const [du, au] = bornes(); return b + '&du=' + du + '&au=' + au; }
  function invModaleOuvrir() { S.invModale = { retour: document.activeElement }; lireAux(cleInvD(), cheminInvD(), false); invModaleRendre(); }
  function invModaleFermer() { const r = S.invModale && S.invModale.retour; S.invModale = null; S.invAct = null; invModaleRendre(); if (r && r.focus) { try { r.focus(); } catch (e) { /* la ligne a été redessinée */ } } }
  function invModaleRendre() {
    let box = document.getElementById('db-invm');
    if (!S.invModale) { if (box) { box.innerHTML = ''; } if (!S.fiche) { document.documentElement.classList.remove('db-fiche-ouverte'); } return; }
    if (!box) { box = document.createElement('div'); box.id = 'db-invm'; document.body.appendChild(box); }
    document.documentElement.classList.add('db-fiche-ouverte');
    const cle = cleInvD(), D = S.aux[cle], err = S.err[cle], per = coPer();
    const quand = per ? perLib() : (S.date === AUJ ? 'aujourd’hui' : fDL(S.date));
    const pl = (n, m) => fN(n) + ' ' + m + (n > 1 ? 's' : '');
    let chips = '', corps = '';
    if (!D) { corps = `<div class="db-mini" style="padding:18px 0">${err ? 'Lecture impossible : ' + esc(err) : 'lecture des saisies…'}</div>`; }
    else {
      const L = D.saisies || [], P = D.produits || [], J = D.journal || {}, ops = D.parOperateur || [];
      chips = [L.length ? `<span class="fi-chip"><b>${pl(L.length, 'saisie')}</b> · ${pl(D.saisiesPieces || 0, 'pièce')}</span>` : '',
        D.pieces != null ? `<span class="fi-chip">au panel <b>${pl(D.pieces, 'pièce')}</b> · ${pl(D.references || 0, 'référence')}</span>` : '',
        D.cout != null ? `<span class="fi-chip">coût de production <b>${fE(D.cout)}</b></span>` : '',
        ops.length ? `<span class="fi-chip"><b>${pl(ops.length, 'opérateur')}</b></span>` : ''].join('');
      // Un problème de qualité (qualité, casse) se traite depuis la ligne : réclamation au fournisseur ou
      // remarque à l'opérateur. Les lignes se retrouvent par leur clé, le formulaire s'ouvre sous la ligne.
      S.invRows = {};
      const QM = D.motifsQualite || ['quality', 'damage'], rem = D.remarques || [];
      const actions = (k, row) => {
        if (!QM.includes(row.motif)) { return ''; }
        S.invRows[k] = row;
        const r = rem.find(x => row.saisieId ? x.saisieId === row.saisieId : (x.pid === row.pid && (!per || x.le === row.le)));
        const fait = S.invFaits && S.invFaits[k];
        const rm = r || (fait && fait.rem ? { employe: fait.rem.nom } : null), rc = fait && fait.recl;
        const badges = [rm ? `<span class="im-badge">remarque faite · ${esc(rm.employe)}</span>` : '', rc ? `<span class="im-badge">réclamation${rc.id ? ' n° ' + rc.id : ''} envoyée</span>` : ''].filter(Boolean).join('');
        const on = S.invAct && S.invAct.k === k ? S.invAct.type : '';
        const court = estMobile();
        return `<div class="im-rowact">${badges}<button type="button" data-imact="recl" data-imk="${esc(k)}" class="${on === 'recl' ? 'on' : ''}" title="Réclamer au fournisseur">${court ? 'Réclamer' : 'Réclamer au fournisseur'}</button><button type="button" data-imact="rem" data-imk="${esc(k)}" class="${on === 'rem' ? 'on' : ''}" title="Remarque à l’opérateur">${court ? 'Remarque' : 'Remarque à l’opérateur'}</button></div>`;
      };
      const actRow = k => S.invAct && S.invAct.k === k ? `<tr class="im-act"><td colspan="5">${invActForm(S.invAct)}</td></tr>` : '';
      if (L.length) {
        let jourCourant = '';
        const lignes = L.map(s => {
          let h = '';
          if (per && s.le !== jourCourant) { jourCourant = s.le; h += `<tr class="im-jour"><td colspan="5">${esc(fDL(s.le))}</td></tr>`; }
          const k = 's' + s.id, row = { k, pid: s.pid, produit: s.produit || ('produit ' + s.pid), pieces: s.pieces, motif: s.motif, motifLib: s.motifLib, le: s.le, heure: s.heure, operateur: s.operateur, operateurId: s.operateurId, saisieId: s.id };
          return h + `<tr><td class="n im-h">${esc(s.heure || '—')}</td><td>${esc(s.operateur || '—')}</td><td class="nom">${esc(s.produit || ('produit ' + s.pid))}${s.categorie ? `<small>${esc(s.categorie)}</small>` : ''}</td><td class="n">${fN(s.pieces)}</td><td>${invTag({ motif: s.motif, motifLib: s.motifLib })}${actions(k, row)}</td></tr>` + actRow(k);
        }).join('');
        corps += `<table class="db-pro-tab db-inv-tab im-tab"><thead><tr><th class="n">Heure</th><th>Opérateur</th><th>Produit</th><th class="n">Quantité</th><th>Motif</th></tr></thead><tbody>${lignes}</tbody></table>`;
        if (ops.length) { corps += `<div class="im-ops"><span class="db-lab">Par opérateur</span>${ops.map(o => `<span><b>${esc(o.operateur || '—')}</b> ${pl(o.pieces, 'pièce')} · ${pl(o.saisies, 'saisie')}</span>`).join('')}</div>`; }
        if (D.pieces != null && Math.round(D.saisiesPieces || 0) !== Math.round(D.pieces)) { corps += `<div class="db-mini db-inv-note">Le journal compte ${pl(D.saisiesPieces || 0, 'pièce')} là où le panel en totalise ${fN(D.pieces)} : les deux sources ne se recoupent pas tout à fait (saisies corrigées, reports).</div>`; }
      }
      if (J.motif) { corps += `<div class="db-mini db-inv-note">${esc(J.motif)}</div>`; }
      if (!L.length && P.length) {
        corps += `<div class="db-lab" style="margin-top:10px">Par produit — ${esc(quand)}</div><table class="db-pro-tab db-inv-tab im-tab"><thead><tr><th>Produit</th><th>Motif</th><th class="n">Jetées</th><th class="n">Coût</th><th class="n">Valeur perdue</th></tr></thead><tbody>${P.map(p => { const k = 'p' + p.pid, row = { k, pid: p.pid, produit: p.nom, pieces: p.pieces, motif: p.motif, motifLib: p.motifLib, le: per ? null : S.date, heure: null, operateur: null, operateurId: null, saisieId: null }; return `<tr><td class="nom">${esc(p.nom)}${p.categorie ? `<small>${esc(p.categorie)}</small>` : ''}</td><td>${invTag(p)}${actions(k, row)}</td><td class="n">${fN(p.pieces)}</td><td class="n">${fE(p.cout)}</td><td class="n">${p.caPerdu ? fE(p.caPerdu) : '—'}</td></tr>` + actRow(k); }).join('')}</tbody></table>`;
      }
      if (!L.length && !P.length) { corps += `<div class="db-mini" style="padding:12px 0">${D.lu === false ? 'Le panel ne répond pas : rien à montrer.' : 'Rien déclaré en caisse ' + esc(quand) + '.'}</div>`; }
      corps += `<div class="db-mini" style="margin-top:14px">${esc(D.source || '')}</div>`;
    }
    const ancienne = box.querySelector('.fi-modale'), defil = ancienne ? ancienne.scrollTop : 0;
    // Le champ du formulaire d'action qui a le focus le retrouve après le redessin (relecture périodique).
    const act = document.activeElement, focIa = act && box.contains(act) && act.dataset && act.dataset.ia ? act.dataset.ia : null, selPos = focIa && act.selectionStart != null ? act.selectionStart : null;
    box.innerHTML = `<div class="fi-voile" data-imfermer="1"></div><div class="fi-modale im-modale" role="dialog" aria-modal="true" aria-label="Invendus et poubelle — le détail des saisies">
      <div class="fi-hd"><div class="t"><h2>Invendus et poubelle</h2><div class="s">${esc(nomShop())} · ${esc(quand)} · chaque pièce jetée telle qu’encodée en caisse</div><div class="fi-chips">${chips}</div></div><button type="button" class="fi-x" data-imfermer="1" aria-label="Fermer">✕</button></div>
      <div class="fi-bd">${corps}</div></div>`;
    if (defil) { const nm = box.querySelector('.fi-modale'); if (nm) { nm.scrollTop = defil; } }
    if (focIa) { const el = box.querySelector('[data-ia="' + focIa + '"]'); if (el) { el.focus(); if (selPos != null && el.setSelectionRange) { try { el.setSelectionRange(selPos, selPos); } catch (e) { /* type sans sélection */ } } } }
    box.querySelectorAll('[data-imfermer]').forEach(b => b.addEventListener('click', invModaleFermer));
    // Les actions d'une ligne : ouvrir ou replier le formulaire, saisir, envoyer.
    box.querySelectorAll('[data-imact]').forEach(b => b.addEventListener('click', () => {
      const k = b.dataset.imk;
      if (S.invAct && S.invAct.k === k && S.invAct.type === b.dataset.imact) { return invActFermer(); }
      if (S.invRows && S.invRows[k]) { invActOuvrir(b.dataset.imact, S.invRows[k]); }
    }));
    box.querySelectorAll('[data-iafermer]').forEach(b => b.addEventListener('click', invActFermer));
    box.querySelectorAll('[data-ia]').forEach(el => {
      const maj = () => { if (S.invAct && S.invAct.f) { S.invAct.f[el.dataset.ia] = el.value; } };
      el.addEventListener('input', maj);
      el.addEventListener('change', () => {
        maj();
        if (el.dataset.ia === 'matiere' && S.invAct) {
          const X = S.aux[cleInvA(S.invAct.pid)], R = (X && X.reclamation) || {}, m = (R.matieres || []).find(x => String(x.id) === String(el.value)), L = m ? rcLivraisons(R, m) : [];
          S.invAct.f.livraison = L[0] ? String(L[0].id) : ''; invModaleRendre();
        }
      });
    });
    box.querySelectorAll('[data-iamotif]').forEach(b => b.addEventListener('click', () => { if (S.invAct && S.invAct.f) { S.invAct.f.motif = b.dataset.iamotif; invModaleRendre(); } }));
    box.querySelectorAll('[data-iaenvoyer]').forEach(b => b.addEventListener('click', invActEnvoyer));
    box.querySelectorAll('[data-iaphoto]').forEach(b => b.addEventListener('click', invActPhoto));
  }
  /* --- agir sur une pièce jetée pour un problème de qualité : la réclamation au fournisseur (la matière du
   * produit dans sa recette, une livraison, un motif ; envoyée au panel par la même route que le téléphone),
   * ou la remarque à l'opérateur qui a produit la référence (gardée dans ses évaluations). --- */
  function cleInvA(pid) { return 'inva|' + S.shop + '|' + S.date + '|' + pid; }
  function invActOuvrir(type, row) {
    S.invFaits = S.invFaits || {};
    S.invAct = Object.assign({ type: type, f: null, envoi: false, err: null, fait: null }, row);
    lireAux(cleInvA(row.pid), '/exploitation/invendus/actions?shop=' + encodeURIComponent(S.shop) + '&date=' + encodeURIComponent(row.le || S.date) + '&pid=' + encodeURIComponent(row.pid), false);
    invModaleRendre();
  }
  function invActFermer() { S.invAct = null; invModaleRendre(); }
  function invActNote(A) { return 'Pièce' + (A.pieces > 1 ? 's' : '') + ' jetée' + (A.pieces > 1 ? 's' : '') + ' en caisse le ' + fD(A.le || S.date) + (A.heure ? ' à ' + A.heure : '') + ' : ' + fN(A.pieces) + ' × ' + A.produit + ', motif « ' + (A.motifLib || A.motif) + ' ».'; }
  function invActTexte(A) { return 'Problème de qualité en production : ' + A.produit + ' jeté le ' + fD(A.le || S.date) + (A.heure ? ' à ' + A.heure : '') + ' (' + fN(A.pieces) + ' pièce' + (A.pieces > 1 ? 's' : '') + ', motif « ' + (A.motifLib || A.motif) + ' »). '; }
  function invActForm(A) {
    const cle = cleInvA(A.pid), X = S.aux[cle], err = S.err[cle];
    const titre = A.type === 'recl' ? 'Réclamation au fournisseur' : 'Remarque à l’opérateur';
    const entete = `<div class="im-acth"><b>${titre}</b><span>${esc(A.produit)} · ${fN(A.pieces)} pièce${A.pieces > 1 ? 's' : ''} · ${esc(A.motifLib || A.motif)}${A.heure ? ' · ' + esc(A.heure) : ''}${A.operateur ? ' · jeté par ' + esc(A.operateur) : ''}</span><button type="button" class="im-x" data-iafermer="1" aria-label="Fermer">✕</button></div>`;
    if (A.fait) { return entete + (A.fait.type === 'recl' ? `<div class="im-fait">✓ Réclamation${A.fait.id ? ' n° ' + A.fait.id : ''} envoyée à ${esc(A.fait.fournisseur)} — ${esc(A.fait.nom)}</div>` : `<div class="im-fait">✓ Remarque enregistrée dans les évaluations de ${esc(A.fait.nom)}</div>`); }
    if (!X) { return entete + `<div class="db-mini">${err ? 'Lecture impossible : ' + esc(err) : 'lecture de la recette, des références réclamables et de l’équipe…'}</div>`; }
    if (A.type === 'recl') {
      const R = X.reclamation || {};
      if (R.indispo) { return entete + `<div class="db-mini">${esc(R.motif || 'Les références réclamables ne sont pas disponibles.')}</div>`; }
      const cands = R.candidates || [], candIds = cands.map(c => String(c.id));
      if (!A.f) { const m0 = cands[0] || null, L0 = m0 ? rcLivraisons(R, m0) : []; A.f = { matiere: m0 ? String(m0.id) : '', livraison: L0[0] ? String(L0[0].id) : '', qte: String(A.pieces || 1), motif: R.motifSuggere || 'product_quality', note: invActNote(A) }; }
      const f = A.f, M = R.matieres || [], m = M.find(x => String(x.id) === String(f.matiere)) || null, L = m ? rcLivraisons(R, m) : [];
      const motifs = R.motifs && R.motifs.length ? R.motifs : Object.keys(RC_MOTIFS).map(k => ({ code: k, nom: RC_MOTIFS[k] }));
      const rec = X.recette || {};
      const recetteTxt = !rec.lue ? 'recette non lue' : (rec.sansRecette ? 'le produit n’a pas de recette au panel' : (cands.length ? (R.acheteFini ? 'produit acheté fini : la réclamation porte sur lui' : cands.length + ' matière' + (cands.length > 1 ? 's' : '') + ' de la recette chez un fournisseur réclamable') : 'aucune matière de la recette chez un fournisseur réclamable : choisissez la référence'));
      return entete + `<div class="im-actf">
        <label class="large">La référence réclamée <small>${esc(recetteTxt)}</small><select data-ia="matiere">${!m ? '<option value="">— choisir —</option>' : ''}${cands.length ? `<optgroup label="Dans la recette du produit">${cands.map(c => `<option value="${esc(c.id)}"${String(c.id) === String(f.matiere) ? ' selected' : ''}>${esc(c.nom)} · ${esc(rcFournNom(R, c.fournisseur))}${c.sku ? ' · SKU ' + esc(c.sku) : ''}</option>`).join('')}</optgroup>` : ''}<optgroup label="Toutes les références">${M.filter(x => !candIds.includes(String(x.id))).map(x => `<option value="${esc(x.id)}"${String(x.id) === String(f.matiere) ? ' selected' : ''}>${esc(x.nom)} · ${esc(rcFournNom(R, x.fournisseur))}</option>`).join('')}</optgroup></select></label>
        <label>La livraison${m && !L.length ? ' <small>aucune livraison de ' + esc(rcFournNom(R, m.fournisseur)) + ' connue pour ce magasin</small>' : ''}<select data-ia="livraison"${L.length ? '' : ' disabled'}>${L.map(l => `<option value="${esc(l.id)}"${String(l.id) === String(f.livraison) ? ' selected' : ''}>${esc(rcLivLib(l))}</option>`).join('')}</select></label>
        <label>Combien${m && m.unite ? ' · en ' + esc(m.unite) : ''}<input data-ia="qte" inputmode="decimal" autocomplete="off" value="${esc(f.qte)}"></label>
        <div class="large"><div class="im-lab">Le problème</div><div class="im-puces">${motifs.map(x => `<button type="button" data-iamotif="${esc(x.code)}" class="${f.motif === x.code ? 'on' : ''}">${esc(RC_MOTIFS[x.code] || x.nom)}</button>`).join('')}</div></div>
        <label class="large">Un mot pour le fournisseur<textarea data-ia="note" rows="3" maxlength="1500">${esc(f.note)}</textarea></label>
      </div>${A.err ? `<div class="rc-err">${esc(A.err)}</div>` : ''}<div class="im-actbtn"><button type="button" class="im-btn" data-iaenvoyer="1"${A.envoi ? ' disabled' : ''}>${A.envoi ? 'Envoi…' : 'Envoyer la réclamation'}</button>${estMobile() ? '<button type="button" class="im-lien" data-iaphoto="1">Avec une photo : ouvrir la réclamation ›</button>' : ''}<span class="db-mini">part au panel comme une réclamation matière, sans photo</span></div>`;
    }
    const prod = X.producteur || null, ops = X.operateurs || [];
    if (!A.f) { A.f = { employe: prod ? String(prod.id) : '', employeNom: '', texte: invActTexte(A) }; }
    const f = A.f;
    return entete + `<div class="im-actf">
      <label>L’opérateur <small>${prod ? 'a produit la référence ce jour (' + esc(prod.source) + ') : ' + esc(prod.nom) : 'le journal ne dit pas qui a produit la référence ce jour : choisissez'}</small><select data-ia="employe"><option value="">— choisir —</option>${ops.map(o => `<option value="${esc(o.id)}"${String(o.id) === String(f.employe) ? ' selected' : ''}>${esc(o.nom)}${prod && String(o.id) === String(prod.id) ? ' · a produit' : ''}${A.operateurId && String(o.id) === String(A.operateurId) ? ' · a jeté' : ''}</option>`).join('')}</select></label>
      <label>Ou un nom, si la personne n’est pas dans la liste<input data-ia="employeNom" autocomplete="off" maxlength="80" value="${esc(f.employeNom)}"></label>
      <label class="large">La remarque<textarea data-ia="texte" rows="3" maxlength="1000">${esc(f.texte)}</textarea></label>
    </div>${A.err ? `<div class="rc-err">${esc(A.err)}</div>` : ''}<div class="im-actbtn"><button type="button" class="im-btn" data-iaenvoyer="1"${A.envoi ? ' disabled' : ''}>${A.envoi ? 'Enregistrement…' : 'Enregistrer la remarque'}</button><span class="db-mini">gardée dans les évaluations de l’opérateur, avec la date, le produit et la quantité</span></div>`;
  }
  function invActEnvoyer() {
    const A = S.invAct; if (!A || A.envoi) { return; }
    const X = S.aux[cleInvA(A.pid)]; if (!X || !A.f) { return; }
    A.err = null;
    if (A.type === 'recl') {
      const R = X.reclamation || {}, f = A.f, m = (R.matieres || []).find(x => String(x.id) === String(f.matiere)) || null;
      const liv = m ? rcLivraisons(R, m).find(l => String(l.id) === String(f.livraison)) : null, q = parseFloat(String(f.qte).replace(',', '.'));
      if (!m) { A.err = 'Choisissez la référence à réclamer.'; return invModaleRendre(); }
      if (!liv) { A.err = 'Choisissez la livraison : sans livraison connue, la réclamation ne peut pas partir d’ici.'; return invModaleRendre(); }
      if (!(q > 0)) { A.err = 'La quantité doit être supérieure à zéro.'; return invModaleRendre(); }
      if (!f.motif) { A.err = 'Dites le problème.'; return invModaleRendre(); }
      if (!m.idUnite) { A.err = 'L’unité de « ' + m.nom + ' » est inconnue du panel : la réclamation ne peut pas partir d’ici.'; return invModaleRendre(); }
      A.envoi = true; invModaleRendre();
      ecrire('/fournisseurs/reclamation', { shopId: S.shop, idMatiere: m.id, sku: m.sku, nomMatiere: m.nom, idFournisseur: m.fournisseur, idUnite: m.idUnite, quantite: Math.round(q * 100) / 100, idLivraison: liv.id, motif: f.motif, action: 'REPLACEMENT', texte: String(f.note || '').trim(), auteur: notePar(), photos: [] })
        .then(r => { A.envoi = false; A.fait = { type: 'recl', id: r && r.id, fournisseur: rcFournNom(R, m.fournisseur), nom: m.nom }; S.invFaits[A.k] = Object.assign({}, S.invFaits[A.k] || {}, { recl: A.fait }); if (S.aux[cleRC()]) { lireAux(cleRC(), cheminRC(), true); } invModaleRendre(); })
        .catch(e => { A.envoi = false; A.err = e.message; invModaleRendre(); });
      return;
    }
    const f = A.f, op = (X.operateurs || []).find(o => String(o.id) === String(f.employe)) || null, nomLibre = String(f.employeNom || '').trim(), texte = String(f.texte || '').trim();
    if (!op && !nomLibre) { A.err = 'À qui ? Choisissez l’opérateur, ou écrivez son nom.'; return invModaleRendre(); }
    if (!texte) { A.err = 'Un mot, au moins.'; return invModaleRendre(); }
    A.envoi = true; invModaleRendre();
    ecrire('/equipe/remarques', { shop: S.shop, employeId: op ? op.id : null, employeNom: op ? op.nom : nomLibre, texte: texte, le: A.le || S.date, heure: A.heure || null, pid: A.pid, produit: A.produit, pieces: A.pieces, motif: A.motif, saisieId: A.saisieId || null, auteur: notePar() })
      .then(r => { A.envoi = false; A.fait = { type: 'rem', id: r && r.id, nom: op ? op.nom : nomLibre }; const D = S.aux[cleInvD()]; if (D && r && r.remarque) { D.remarques = (D.remarques || []).concat([r.remarque]); } S.invFaits[A.k] = Object.assign({}, S.invFaits[A.k] || {}, { rem: A.fait }); invModaleRendre(); })
      .catch(e => { A.envoi = false; A.err = e.message; invModaleRendre(); });
  }
  /** Au téléphone : la même réclamation, mais avec la photo — le formulaire complet, déjà rempli. */
  function invActPhoto() {
    const A = S.invAct, X = A ? S.aux[cleInvA(A.pid)] : null; if (!A || !X || !A.f) { return; }
    const f = A.f;
    S.rc = Object.assign(rcNeuf(), { matiere: f.matiere || null, q: A.produit || '', livraison: f.livraison || null, qte: String(f.qte || A.pieces || 1), motif: f.motif || null, note: f.note || '' });
    S.rcMode = 'saisie'; try { localStorage.setItem('db.rcMode', 'saisie'); } catch (e) { /* stockage indisponible */ }
    S.invAct = null; S.invModale = null; invModaleRendre();
    S.vue = 'reclamation'; urlMaj(); charger(false);
  }
  function murInv() {
    const I = invData(), cle = cleInv(), per = coPer();
    if (!I) { return murC('Invendus', '…', S.err[cle] ? esc(S.err[cle]) : 'lecture de la poubelle…', '', 'invdrop'); }
    if (!I.lu) { return murC('Invendus', '—', 'panel muet', '', 'invdrop'); }
    if (!I.declare) { return murC('Invendus', '0', 'rien déclaré ' + (per ? 'sur ' + perLib() : ceJour()) + '<span class="dr"> · détail ▾</span>', '', 'invdrop'); }
    return murC('Invendus', fK(I.cout), fN(I.pieces) + ' pièce' + (I.pieces > 1 ? 's' : '') + ' jetée' + (I.pieces > 1 ? 's' : '') + ' · ' + fK(I.caPerdu) + ' de valeur de vente' + `<span class="dr"> · ${S.invOuvert ? 'replier ▴' : 'détail ▾'}</span>`, 'ko', 'invdrop');
  }
  function murCanaux() {
    const C = canauxData(), cle = cleCanaux();
    if (!C || C.indispo) { return murC('Webshop', C ? '—' : '…', C ? 'panel muet' : (S.err[cle] ? esc(S.err[cle]) : 'lecture des commandes…')); }
    const per = coPer(), J = per ? C.periode : C.jour, n = J.cc.n + J.liv.n, Q = C.quatorze || {};
    return murC('Webshop', n ? fK(J.webshop) : '0', n ? J.cc.n + ' click & collect · ' + J.liv.n + ' livraison' + (J.part != null ? ' · ' + fP(J.part) + ' du CA' : '') + (C.aPreparer ? ' · <b>' + C.aPreparer + ' à préparer</b>' : '') : 'aucune commande ' + (per ? 'sur ' + perLib() : ceJour()), C.aPreparer ? 'wa' : '');
  }
  function murOffres() {
    const O = offresData(), cle = cleOffres();
    if (!O) { return murC('Offres', '…', S.err[cle] ? esc(S.err[cle]) : 'lecture des tickets…'); }
    const K = O.kpi || {};
    const per = coPer();
    if (!O.offres.length) { return murC('Offres', '0', 'aucune offre en cours' + (per ? ' sur ' + perLib() : '')); }
    if (!per) {
      const nV = O.offres.filter(o => (o.auj || {}).ca > 0 || (o.auj || {}).pieces > 0).length;
      return murC('Offres', fK(K.caJour), nV + ' vendue' + (nV > 1 ? 's' : '') + ' ' + ceJour() + ' sur ' + O.offres.length + ' · ' + (K.bundles || 0) + ' bundle' + (K.bundles > 1 ? 's' : '') + ', ' + (K.promos || 0) + ' promo' + (K.promos > 1 ? 's' : '') + (K.aAjuster ? ' · <b>' + K.aAjuster + ' à ajuster</b>' : ''), K.aAjuster ? 'wa' : 'ok');
    }
    return murC('Offres', fK(K.ca), (K.bundles || 0) + ' bundle' + (K.bundles > 1 ? 's' : '') + ' · ' + (K.promos || 0) + ' promo' + (K.promos > 1 ? 's' : '') + ' · ' + perLib() + (K.part != null ? ' · ' + fP(K.part) + ' du CA' : '') + (K.aAjuster ? ' · <b>' + K.aAjuster + ' à ajuster</b>' : ''), K.aAjuster ? 'wa' : 'ok');
  }

  /* --- les clients pro (B2B) : ce que les tickets du panel disent des ventes aux sociétés --- */
  function clePro() { return 'pro|' + S.shop + '|' + S.date; }
  function cheminPro() { return '/exploitation/pro?shop=' + encodeURIComponent(S.shop) + '&date=' + S.date; }
  function proData() { const P = S.aux[clePro()]; return P && !P.error ? P : null; }
  /** La part pro jour par jour sur 30 jours : une barre par jour, le week-end en clair. */
  function proSerie(serie) {
    const L = (serie || []).filter(s => s.caPro != null);
    if (!L.length) { return ''; }
    const max = Math.max(1, ...L.map(s => s.caPro));
    return `<div class="db-pro-serie">${(serie || []).map(s => { const d = new Date(s.j + 'T12:00:00').getDay(); const we = d === 0 || d === 6; return s.caPro == null ? '<i class="na" title="' + fD(s.j) + ' · non lu"></i>' : `<i class="${we ? 'we' : ''}" style="height:${Math.max(3, Math.round(100 * s.caPro / max))}%" title="${fD(s.j)} · pro ${fE(s.caPro)}${s.ca ? ' sur ' + fE(s.ca) : ''}"></i>`; }).join('')}</div>`;
  }
  function proCarte(mobile) {
    const cle = clePro(), P = proData(), err = S.err[cle];
    const auj = S.date === AUJ;
    if (!P) {
      if (err) { return `<div class="db-card db-pro"><div class="ct"><span class="db-lab">Clients pro — B2B</span><span class="db-mini ko">${esc(err)}</span></div></div>`; }
      return mobile ? '<div class="db-mini">lecture des tickets pro…</div>' : `<div class="db-card db-pro"><div class="ct"><span class="db-lab">Clients pro — B2B</span><span class="db-mini">lecture des tickets…</span></div></div>`;
    }
    const J = P.jour, M = P.mois || {};
    const detail = mobile === 'detail';
    const rienJour = !J || !J.ticketsPro, rienMois = !(M.ticketsPro > 0);
    // La vue Jour ne parle que du jour choisi : ni les 30 jours, ni les comptes, ni la série (demande du 03/10/2026).
    const sous = 'tickets portés par un client dont la fiche est professionnelle';
    if (rienJour) {
      const t = `<div class="db-mini" style="padding:${mobile && !detail ? '4px 4px 8px' : '12px 16px'}">Aucun ticket pro ${auj ? 'aujourd’hui' : 'ce jour'}.${J && !J.tickets ? ' Aucun ticket lu ce jour.' : ''}</div>`;
      if (detail) { return t; }
      return mobile ? `<div class="db-pro mob"><div class="db-notej-t" style="padding:4px 4px 0">Clients pro — B2B<small>${esc(sous)}</small></div>${t}</div>` : `<div class="db-card db-pro"><div class="ct"><span class="db-lab">Clients pro — B2B</span><span class="db-mini">${esc(sous)}</span></div>${t}</div>`;
    }
    // Dans le bloc fusionné, le CA pro et le comptoir sont déjà en tête : le détail ne les répète pas.
    const kpi = detail ? (J ? `<div class="db-obj-t4 db-pro-kpi">
        <div><div class="k">Tickets pro</div><div class="v">${fN(J.ticketsPro)}</div><div class="s">sur ${fN(J.tickets)} ticket${J.tickets > 1 ? 's' : ''} ${auj ? 'aujourd’hui' : 'ce jour'}</div></div>
        <div><div class="k">Panier pro</div><div class="v">${J.panierPro != null ? fU(J.panierPro) : '—'}</div><div class="s">${J.panierComptoir != null ? 'contre ' + fU(J.panierComptoir) + ' au comptoir' : ''}</div></div>
        <div><div class="k">À facturer</div><div class="v">${fE(J.aFacturer)}</div><div class="s">${J.differes} en paiement différé · ${J.ticketsPro - J.differes} réglé${J.ticketsPro - J.differes > 1 ? 's' : ''} au comptoir</div></div>
        <div><div class="k">Sociétés</div><div class="v">${fN(J.societes)}</div><div class="s">servie${J.societes > 1 ? 's' : ''} ${auj ? 'aujourd’hui' : 'ce jour'}</div></div>
      </div>` : `<div class="db-mini" style="padding:10px 16px 0">Les tickets de ce jour ne sont pas lus.</div>`) : J ? `<div class="db-obj-t4 db-pro-kpi">
        <div><div class="k">CA pro ${auj ? 'du jour' : 'ce jour'}</div><div class="v pro">${fE(J.caPro)}</div><div class="s">${J.part != null ? fP(J.part) + ' du jour · ' : ''}comptoir ${fE(J.ca - J.caPro)}</div></div>
        <div><div class="k">Tickets pro</div><div class="v">${fN(J.ticketsPro)}</div><div class="s">sur ${fN(J.tickets)} · ${J.societes} société${J.societes > 1 ? 's' : ''}</div></div>
        <div><div class="k">Panier pro</div><div class="v">${J.panierPro != null ? fU(J.panierPro) : '—'}</div><div class="s">${J.panierComptoir != null ? 'contre ' + fU(J.panierComptoir) + ' au comptoir' : ''}</div></div>
        <div><div class="k">À facturer</div><div class="v">${fE(J.aFacturer)}</div><div class="s">${J.differes} en paiement différé · ${J.ticketsPro - J.differes} réglé${J.ticketsPro - J.differes > 1 ? 's' : ''} au comptoir</div></div>
      </div>` : `<div class="db-mini" style="padding:10px 16px 0">Les tickets de ce jour ne sont pas lus.</div>`;
    // L'heure et le montant seulement : le panel range sous « société » des noms de personnes (demande du 03/10/2026).
    const tickets = J && Array.isArray(J.liste) ? J.liste : [];
    const gauche = `<div><span class="db-lab">Les tickets pro ${auj ? 'du jour' : 'de ce jour'}</span>
      ${tickets.length ? `<table class="db-pro-tab"><thead><tr><th>Heure</th><th class="n">Montant</th><th class="r"></th></tr></thead><tbody>${tickets.slice(0, mobile ? 8 : 14).map(t => `<tr><td class="mu">${esc(t.heure)}</td><td class="n">${fU(t.montant)}</td><td class="r">${t.differe ? '<span class="db-pro-tag d">différé</span>' : (t.facture ? '<span class="db-pro-tag f">facturé</span>' : '<span class="db-pro-tag">comptoir</span>')}</td></tr>`).join('')}${tickets.length > (mobile ? 8 : 14) ? `<tr><td colspan="3" class="mu">… et ${tickets.length - (mobile ? 8 : 14)} ${tickets.length - (mobile ? 8 : 14) > 1 ? 'autres' : 'autre'}</td></tr>` : ''}</tbody></table>` : `<div class="db-mini" style="margin-top:6px">Aucun ticket pro ${auj ? 'aujourd’hui' : 'ce jour'}.</div>`}</div>`;
    // Le détail ne répète pas la définition ni la part sur 30 jours (en tête du bloc) : seulement les jours non lus.
    if (detail) { return `${kpi}<div class="db-pro-corps un">${gauche}</div>`; }
    if (mobile) { return `<div class="db-pro mob"><div class="db-notej-t" style="padding:4px 4px 0">Clients pro — B2B<small>${esc(sous)}</small></div>${kpi}<div class="db-pro-corps un">${gauche}</div></div>`; }
    // Sur ordinateur, la carte se replie : l'en-tête garde le jour en une
    // ligne (CA pro, sa part, clients, à facturer) ; le clic déplie les
    // chiffres et les tickets du jour.
    const ouvert = !!S.proOuvert;
    const resume = J ? (J.ticketsPro
      ? `<b class="pro">${fE(J.caPro)}</b> ${auj ? 'aujourd’hui' : 'ce jour'}${J.part != null ? ' · ' + fP(J.part) + ' du CA' : ''} · ${fN(J.ticketsPro)} client${J.ticketsPro > 1 ? 's' : ''} pro · comptoir ${fE(J.ca - J.caPro)}${J.aFacturer ? ' · à facturer ' + fE(J.aFacturer) : ''}`
      : `aucun ticket pro ${auj ? 'aujourd’hui' : 'ce jour'} · comptoir ${fE(J.ca)}`) : 'tickets du jour non lus';
    const mois = '';
    return `<div class="db-card db-pro${J && J.ticketsPro ? ' on' : ''}${ouvert ? ' ouv' : ''}"><div class="ct" data-prodrop="1" style="cursor:pointer" title="${esc(sous)}"><span class="db-lab">Clients pro — B2B</span><span class="db-mini">${resume}${esc(mois)}</span>
      <span class="db-cdr" style="padding:0;margin-left:auto">${ouvert ? 'replier ▴' : 'voir le détail ▾'}</span></div>
      ${ouvert ? `<div class="db-mini db-pro-def">${esc(sous)}</div>${kpi}<div class="db-pro-corps un">${gauche}</div>` : ''}</div>`;
  }
  /** Le split comptoir / pro de la vue (jour, semaine, mois) : lu dans la réponse de Résultat, pas un appel de plus. */
  function splitDe(m) {
    if (!m || m.caPro === undefined) { return null; }
    const per = S.vue !== 'jour', lu = m.caPro != null, complet = lu && m.caComptoir != null;
    const partC = complet && m.partPro != null ? Math.round((100 - m.partPro) * 10) / 10 : null;
    const cli = n => n == null ? '' : fN(n) + ' client' + (n > 1 ? 's' : '');
    return { per, lu, complet, partC,
      manque: !lu ? (per && m.proJours ? 'aucun des ' + m.proJours + ' jours ouverts n’est encore lu' : 'tickets pas encore lus chez le panel')
        : (!complet ? 'pro lu sur ' + m.proJoursLus + ' jour' + (m.proJoursLus > 1 ? 's' : '') + ' sur ' + m.proJours + ' — le comptoir s’affiche quand tous les jours sont lus' : ''),
      sC: [partC != null ? fP(partC) + ' du CA' : '', cli(m.ticketsComptoir), m.panierComptoir != null ? 'panier ' + fU(m.panierComptoir) : ''].filter(Boolean).join(' · '),
      sP: [m.partPro != null ? proPart(m.partPro, per) : '', cli(m.ticketsPro), m.panierPro != null ? 'panier ' + fU(m.panierPro) : ''].filter(Boolean).join(' · ') };
  }
  /* La part du CA pro dans le CA total : la règle (08/10/2026) la plafonne à 40 % du CA de la semaine ou du mois.
   * Vert en deçà de 35 %, orange de 35 à 40 %, rouge au-delà ; sur une journée la pastille reste neutre. */
  const PRO_MAX = 40, PRO_ALERTE = 35;
  function proPart(part, periode) {
    const niv = !periode ? '' : (part > PRO_MAX ? 'ko' : (part > PRO_ALERTE ? 'att' : 'ok'));
    const regle = 'Règle : le pro ne dépasse pas ' + PRO_MAX + ' % du CA de la semaine ou du mois' + (!periode ? ' — sur une journée, la part est indicative.' : (part > PRO_MAX ? ' — dépassée.' : (part > PRO_ALERTE ? ' — on s’en approche.' : '.')));
    return `<span class="db-pro-part ${niv}" title="${esc(regle)}">${fP(part)} du CA</span>`;
  }
  /**
   * Comptoir et clients pro, en un seul bloc. Les chiffres de tête viennent de Résultat (le même
   * calcul que le cockpit) ; en vue Jour, le bloc porte aussi « à facturer » et la part pro sur
   * 30 jours, et se déplie sur le détail des tickets et des comptes pro — l'ancienne carte
   * « Clients pro — B2B » répétait les mêmes chiffres, lus à un autre moment.
   */
  function splitCarte(m) {
    const X = splitDe(m);
    const jour = S.vue === 'jour';
    const C = (jour || coPer()) ? canauxData() : null;
    if (C && !C.indispo && m) { return canauxCarte(m, C); }
    if (!X) { return jour ? proCarte(false) : ''; }
    const lib = 'Comptoir et clients pro — ' + (jour ? 'la journée' : (S.vue === 'semaine' ? 'la semaine' : 'le mois'));
    const P = jour ? proData() : null, J = P ? P.jour : null, M = P ? (P.mois || {}) : {};
    const ouvert = jour && !!S.proOuvert;
    // Le déclencheur est sur l'en-tête seul : le même attribut sur le lien ferait basculer deux fois.
    const bascule = jour ? `<span class="db-cdr" style="padding:0;margin-left:auto;white-space:nowrap">${ouvert ? 'replier ▴' : 'détail des clients pro ▾'}</span>` : '';
    const ct = jour ? ' data-prodrop="1" style="cursor:pointer"' : '';
    const deplie = ouvert ? `<div class="db-split-det">${proCarte('detail')}</div>` : '';
    const pro = !!(J && J.ticketsPro) || (m.ticketsPro > 0);
    if (!X.lu) { return `<div class="db-card db-split${pro ? ' on' : ''}"><div class="ct"${ct}><span class="db-lab">${lib}</span><span class="db-mini">${esc(X.manque)}</span>${bascule}</div>${deplie}</div>`; }
    const bar = X.complet && m.partPro != null ? `<div class="db-split-bar" title="comptoir ${fP(X.partC)} · pro ${fP(m.partPro)}${X.per ? ' · limite : ' + PRO_MAX + ' % de pro' : ''}"><i class="c" style="width:${X.partC}%"></i><i class="p" style="width:${m.partPro}%"></i>${X.per ? `<b class="lim" style="left:${100 - PRO_MAX}%" title="limite : ${PRO_MAX} % de pro"></b>` : ''}</div>` : '';
    const plusP = jour && P ? [J && J.aFacturer ? 'à facturer ' + fE(J.aFacturer) : ''].filter(Boolean).join(' · ') : '';
    return `<div class="db-card db-split${pro ? ' on' : ''}"><div class="ct"${ct}><span class="db-lab">${lib}</span><span class="db-mini">${esc(X.manque || 'pro = tickets d’un client dont la fiche panel est professionnelle · comptoir = le reste des ventes')}</span>${bascule}</div>${bar}
      <div class="db-split-g"><div class="c"><div class="k">Comptoir</div><div class="v">${m.caComptoir != null ? fK(m.caComptoir) : '—'}</div><div class="s">${X.sC}</div></div>
      <div class="p"><div class="k">Clients pro B2B</div><div class="v">${fK(m.caPro)}</div><div class="s">${X.sP}${plusP ? '<br>' + esc(plusP) : ''}</div></div></div>${deplie}</div>`;
  }
  /** La tuile du mur mobile : le CA pro du jour et sa part, le tiroir en dessous. */
  function murPro() {
    const P = proData(), cle = clePro();
    if (!P) { return murC('Clients pro', '…', S.err[cle] ? esc(S.err[cle]) : 'lecture des tickets…'); }
    const J = P.jour, M = P.mois || {};
    if (!J) { return murC('Clients pro', '—', 'tickets du jour non lus' + `<span class="dr"> · ${S.proOuvert ? 'replier ▴' : 'détail ▾'}</span>`, '', 'prodrop'); }
    return murC('Clients pro', J.ticketsPro ? fK(J.caPro) : '0', (J.ticketsPro ? fN(J.ticketsPro) + ' client' + (J.ticketsPro > 1 ? 's' : '') + ' · ' + fP(J.part) + ' du jour · à facturer ' + fK(J.aFacturer) : 'aucun ticket pro ' + (S.date === AUJ ? 'aujourd’hui' : 'ce jour')) + `<span class="dr"> · ${S.proOuvert ? 'replier ▴' : 'détail ▾'}</span>`, J.ticketsPro ? 'pro' : '', 'prodrop');
  }

  /* --- la note du jour : ce qui explique la journée, relu la même semaine l'an d'après --- */
  const JOURS_C = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
  function cleNote() { return 'notes|' + S.shop + '|' + S.date; }
  function cheminNote() { return '/exploitation/notes?shop=' + encodeURIComponent(S.shop) + '&date=' + S.date; }
  function notePar() { try { return localStorage.getItem('db.notePar') || ''; } catch (e) { return ''; } }
  /** Ce que montre le champ : le brouillon en cours s'il est de ce jour, sinon la note lue. */
  function noteTexteCourant(N) {
    if (S.noteBrouillon && S.noteBrouillon.cle === cleNote()) { return S.noteBrouillon.texte; }
    return N && N.note ? N.note.texte : '';
  }
  function noteLignes(L, exclure, vide) {
    const l = (L || []).filter(n => n.jour !== exclure);
    if (!l.length) { return `<div class="db-nl vide">${vide}</div>`; }
    return l.map(n => { const t = new Date(n.jour + 'T12:00:00'); return `<div class="db-nl"><span class="d">${JOURS_C[t.getDay()]} ${fD(n.jour)}</span><span class="x">${esc(n.texte)}</span>${n.par ? `<span class="p">${esc(n.par)}</span>` : ''}</div>`; }).join('');
  }
  /** La tuile du mur mobile : la note en un coup d'œil, le tiroir en dessous. */
  function murNote() {
    const N = S.aux[cleNote()];
    const nt = N && N.note ? N.note.texte : '';
    const n1 = N && N.n1 && Array.isArray(N.n1.notes) ? N.n1.notes.length : 0;
    const sous = nt ? esc(nt.length > 90 ? nt.slice(0, 90) + '…' : nt)
      : (N ? 'ajouter une note' + (n1 ? ' · ' + n1 + ' note' + (n1 > 1 ? 's' : '') + ' la même semaine l’an dernier' : '') : (S.err[cleNote()] ? esc(S.err[cleNote()]) : 'lecture…'));
    return murC('Note du jour', nt ? '✎' : '+', sous + `<span class="dr"> · ${S.noteOuvert ? 'replier ▴' : (nt ? 'modifier ▾' : 'écrire ▾')}</span>`, '', 'notedrop');
  }
  function noteCarte(mobile) {
    const cle = cleNote(), N = S.aux[cle], err = S.err[cle];
    const texte = noteTexteCourant(N);
    const lu = N && N.note ? N.note.texte : '';
    const modif = S.noteBrouillon && S.noteBrouillon.cle === cle && S.noteBrouillon.texte !== lu;
    const etat = S.noteEtat && S.noteEtat.cle === cle ? S.noteEtat : null;
    const sem = N && N.semaine ? N.semaine : null, n1 = N && N.n1 ? N.n1 : null;
    let pied;
    if (etat && etat.encours) { pied = 'enregistrement…'; }
    else if (etat && etat.erreur) { pied = `<span class="ko">${esc(etat.erreur)}</span>`; }
    else if (modif) { pied = 'modifiée, pas encore enregistrée'; }
    else if (N && N.note) { pied = 'enregistrée' + (N.note.le ? ' le ' + fD(N.note.le.slice(0, 10)) + ' à ' + N.note.le.slice(11, 16) : '') + (N.note.par ? ' par ' + esc(N.note.par) : ''); }
    else if (err) { pied = `<span class="ko">${esc(err)}</span>`; }
    else if (!N) { pied = 'lecture…'; }
    else { pied = 'pas encore de note ce jour'; }
    const occupe = etat && etat.encours ? 'disabled' : '';
    const form = `<div class="db-notej-form">
      <textarea data-note-texte maxlength="2000" rows="${mobile ? 4 : 5}" placeholder="Ce qui explique la journée : météo, événement, animation, panne, équipe…">${esc(texte)}</textarea>
      <div class="db-notej-pied"><input data-note-par class="db-sel" maxlength="120" placeholder="signé (prénom)" value="${esc(notePar())}">
        <button class="db-btn prim" data-note-save ${occupe}>Enregistrer</button>${N && N.note ? `<button class="db-btn" data-note-clear ${occupe}>Effacer</button>` : ''}<span class="db-mini">${pied}</span></div></div>`;
    const listes = `<div class="db-notej-listes">
      <div class="db-notej-bloc"><div class="db-notej-t">Cette semaine<small>${sem ? 'du ' + fD(sem.du) + ' au ' + fD(sem.au) : ''}</small></div>${sem ? noteLignes(sem.notes, S.date, 'Aucune autre note cette semaine.') : '<div class="db-nl vide">lecture…</div>'}</div>
      <div class="db-notej-bloc"><div class="db-notej-t">Même semaine N-1<small>${n1 ? 'du ' + fD(n1.du) + ' au ' + fD(n1.au) : ''}</small></div>${n1 ? noteLignes(n1.notes, null, 'Aucune note cette semaine-là l’an dernier.') : '<div class="db-nl vide">lecture…</div>'}</div></div>`;
    if (mobile) { return `<div class="db-notej mob"><div class="db-notej-t" style="padding:4px 4px 0">La note du ${esc(fDL(S.date))}</div>${form}${listes}</div>`; }
    return `<div class="db-card db-notej"><div class="ct"><span class="db-lab">La note du jour — ${esc(fDL(S.date))}</span><span class="db-mini">ce qui explique la journée, relu l’an prochain la même semaine · une note par jour</span></div><div class="db-notej-corps">${form}${listes}</div></div>`;
  }
  function noteEnregistrer(texte) {
    const cle = cleNote();
    const champ = $.querySelector('[data-note-par]'); const par = champ ? champ.value.trim() : notePar();
    try { localStorage.setItem('db.notePar', par); } catch (e) { /* navigation privée */ }
    // Le clavier se range et le formulaire se rend à neuf : sinon, au téléphone,
    // le champ garde le focus et l'état « enregistrement… » ne s'afficherait pas.
    if (document.activeElement && document.activeElement.blur) { document.activeElement.blur(); }
    S.noteEtat = { cle: cle, encours: true }; rendre();
    ecrire('/exploitation/note', { shop: S.shop, jour: S.date, texte: texte, par: par })
      .then(() => { S.noteEtat = null; S.noteBrouillon = null; lireAux(cle, cheminNote(), true); })
      .catch(e => { S.noteEtat = { cle: cle, erreur: e.message }; rendre(); });
  }
  /** Le formulaire survit au rendu : quand on y tape, le nœud d'origine reprend la place du
   * nouveau, sinon chaque relecture du serveur (toutes les 10 min, ou une tuile qui arrive)
   * volerait le curseur au milieu d'une phrase. */
  function noteGarder() {
    const a = document.activeElement;
    if (!a || !a.matches || !a.matches('[data-note-texte],[data-note-par]')) { return null; }
    const f = a.closest('.db-notej-form'); if (!f) { return null; }
    return { form: f, actif: a, deb: a.selectionStart, fin: a.selectionEnd };
  }
  function noteRestaurer(g) {
    if (!g) { return; }
    const neuf = $.querySelector('.db-notej-form'); if (!neuf) { return; }
    neuf.replaceWith(g.form);
    try { g.actif.focus({ preventScroll: true }); g.actif.setSelectionRange(g.deb, g.fin); } catch (e) { /* champ retiré */ }
  }

  /* --- le plan d'action du franchisé : le module des visites, monté ici ------ */
  function rendActions(mobile) {
    let h = '';
    // Au téléphone, le même en-tête que les autres onglets : le logo à sa
    // taille, le nom qui se raccourcit, la date.
    if (mobile) { h += `<div class="mb-hd"><img src="../assets/img/logo.png" alt=""><div><div class="t">${esc(nomShop())}</div><div class="d">${esc(fDL(AUJ))}</div></div><span class="sp"></span></div>`; }
    // Au téléphone, le module défile sous la barre d'onglets, comme les autres vues.
    h += mobile ? '<div class="mb-sc"><div id="db-actions" style="flex:1 0 auto"></div></div>' + mbOnglets() : '<div id="db-actions" style="min-height:60vh"></div>';
    return h;
  }
  function monterActions() {
    const h = document.getElementById('db-actions');
    if (!h || !window.CockpitVisites) { if (h) { h.innerHTML = '<div class="db-alerte">Le module des visites n’est pas chargé.</div>'; } return; }
    window.CockpitVisites.mount(h, { role: 'franchise', shop: S.shop, apiBase: API, mobile: false, racine: '../', vue: S.vue === 'campagne' ? 'campagne' : 'plans', sansOnglets: true });
  }
  /* --- Opérationnel : la journée en cours, ce qu'il faut faire maintenant -----------
   * Demande du 06/10/2026 : un dashboard centré sur le terrain (maquette
   * docs/maquettes/dashboard-operationnel), sans la liste « à faire maintenant », avec les ventes
   * par catégorie (liste ou treemap) et le P&L court de la journée, coût du personnel compris,
   * repris de la vue Jour. Tout vient de lectures existantes : le suivi de production (la vitrine,
   * les cuissons, la moyenne des 6 derniers mêmes jours), les ventes du jour, le Résultat du jour
   * (planning, P&L), les tâches et leurs photos, les commandes, le stock, la poubelle, les
   * non-conformités de la veille. */
  const cleOpSuivi = () => 'opSuivi|' + S.shop + '|' + S.date;
  function opCharger(force) {
    lireAux(cleOpSuivi(), '/production/flux/suivi?shop=' + encodeURIComponent(S.shop) + '&date=' + S.date, force);
    lireAux('jourM|' + S.date, '/exploitation/jour?date=' + S.date, force);
    lireAux('taches|' + S.date, '/pwa/tasks?date=' + S.date, force);
    lireAux('cmd|' + S.shop, '/ventes/commandes?shop=' + encodeURIComponent(S.shop), force);
    lireAux(cleCanaux(), cheminCanaux(), force);
    lireAux(cleInv(), cheminInv(), force);
    lireAux(cleNC(), urlNC(ncFenetre()), force);
    lireAux(clePromo(), cheminPromo(), force);
    // Les photos ne se relisent pas toutes les deux minutes : leur lien signé vit vingt minutes
    // et cqListe le renouvelle au quart d'heure.
    lireAux(cleCQ(), cheminCQ(), force && !S.aux[cleCQ()]);
  }
  const opMin = h => { const p = String(h || '').split(':'); return (+p[0] || 0) * 60 + (+p[1] || 0); };
  const opHM = m => String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
  /** Maintenant, en minutes : l'heure du poste pour aujourd'hui, la fin de journée pour un jour passé. */
  function opMaintenant() {
    if (S.date !== AUJ) { return null; }
    // L'heure du magasin, pas celle de l'ordinateur qui regarde : le réseau est en Belgique.
    try { return opMin(new Date().toLocaleTimeString('fr-BE', { timeZone: 'Europe/Brussels', hour: '2-digit', minute: '2-digit', hour12: false })); }
    catch (e) { const t = new Date(); return t.getHours() * 60 + t.getMinutes(); }
  }
  const opDans = (m, now) => { const d = m - now; return d < 60 ? 'dans ' + d + ' min' : 'dans ' + Math.floor(d / 60) + ' h ' + String(d % 60).padStart(2, '0'); };
  /** Vide quand : la première heure où la vitrine passe à zéro ou dessous, et l'heure où une cuisson la remplit. */
  function opVide(p) {
    const c = Array.isArray(p.cases) ? p.cases : [];
    const i = c.findIndex((z, k) => z.q < 0 || (z.q <= 0 && c.slice(k).some(y => y.q < 0)));
    if (i < 0) { return null; }
    const j = c.findIndex((z, k) => k > i && z.q > 0);
    return { de: c[i].h, a: j < 0 ? null : c[j].h };
  }
  function opEtat(p) {
    if (p.verdict === 'trop') { return opVieDe(p) === 'M' ? 'se garde demain' : 'jeté ce soir'; }
    if (p.verdict === 'ok') { return 'en ordre'; }
    if (p.verdict === 'rupture' && !p.sorti) { return 'pas produite'; }
    const v = opVide(p);
    if (!v) { return p.verdict === 'manque' ? 'va manquer' : 'vide'; }
    return v.a ? 'vide de ' + v.de + ' h à ' + v.a + ' h' : (p.verdict === 'manque' ? 'vide vers ' : 'vide dès ') + v.de + ' h';
  }
  const opFaire = p => p.verdict === 'rupture' || p.verdict === 'manque' ? (p.conseil && p.conseil.pieces ? 'recuire ' + fN(p.conseil.pieces) : 'à recuire') : (p.verdict === 'trop' ? 'ne pas recuire' : '');
  const OP_RANG = { rupture: 0, manque: 1, trop: 2, ok: 3 };
  function opTuile(cls, k, v, s) { return `<div class="op-tl ${cls || ''}"><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s || ''}</div></div>`; }
  const opSk = () => '<div class="db-sk" style="width:70%;margin:6px 0"></div><div class="db-sk" style="width:50%"></div>';
  /* Les bundles vendus sur la journée (10/10/2026), à côté des ventes par catégorie : quantité, nom, prix moyen
   * encaissé, CA, marge, première et dernière vente, lus dans les mêmes tickets (catégorie « Bundle »). */
  const BUNDLE_RE = /bundle|promotion/i;
  function bundlesDe(st) {
    if (st.bundles && Array.isArray(st.bundles.lignes)) { return st.bundles; }
    // Une réponse d'avant le 10/10/2026 : les bundles se retrouvent dans les catégories.
    const L = [];
    (st.categories || []).filter(c => BUNDLE_RE.test((c.nom || '') + ' ' + (c.groupe || ''))).forEach(c => (c.produits || []).forEach(p => L.push(Object.assign({}, p, { cat: c.nom, prix: p.q ? p.v / p.q : null, heures: null }))));
    L.sort((a, b) => (b.q - a.q) || (b.v - a.v));
    const ca = L.reduce((a, x) => a + (x.v || 0), 0), avec = L.filter(x => x.m != null), mV = avec.reduce((a, x) => a + x.v, 0), tot = st.totaux && st.totaux.ca;
    return { lignes: L, n: L.length, pieces: L.reduce((a, x) => a + (x.q || 0), 0), ca, taux: mV > 0 ? 100 * avec.reduce((a, x) => a + x.m, 0) / mV : null, part: tot ? 100 * ca / tot : null };
  }
  function bundlesCarte() {
    const ks = cleSt(), st = S.st[ks], err = S.err[ks], jour = S.date === AUJ ? 'aujourd’hui' : 'ce jour';
    const tete = mini => `<div class="ct"><span class="db-lab">Bundles vendus</span><span class="db-mini">${mini}</span></div>`;
    if (!st) { return `<div class="db-card op-bun">${tete(err ? esc(err) : 'lecture des tickets en cours…')}<div style="padding:12px 16px">${opSk()}${opSk()}</div></div>`; }
    const B = bundlesDe(st);
    if (!B.lignes.length) { return `<div class="db-card op-bun">${tete('la journée · catégorie Bundle')}<div class="op-bun-vide">Aucun bundle vendu ${jour}.</div></div>`; }
    const quand = x => x.premiere == null ? '' : (x.premiere === x.derniere ? 'à ' + x.premiere + ' h' : 'de ' + x.premiere + ' h à ' + x.derniere + ' h');
    const mc = v => v == null ? 'mu' : (v >= 60 ? 'ok' : (v >= 50 ? 'att' : 'ko'));
    // La composition : la recette du bundle au panel (la même lecture que la fiche produit), lue une fois.
    const cleR = x => 'recette|' + x.id + '|' + S.shop;
    B.lignes.forEach(x => { const k = cleR(x); if (/^\d+$/.test(String(x.id)) && !S.aux[k] && !S.enCours[k] && !S.err[k]) { setTimeout(() => lireAux(k, '/analyse/produits/recette?pid=' + encodeURIComponent(x.id) + '&shop=' + encodeURIComponent(S.shop)), 0); } });
    const compo = x => { const R = S.aux[cleR(x)], L = R && Array.isArray(R.lignes) ? R.lignes.filter(l => l && l.nom) : []; if (!L.length) { return ''; }
      const t = L.slice(0, 4).map(l => (l.qte == null ? '' : (!l.unite || /^pc/i.test(l.unite) ? nf(l.qte, l.qte % 1 ? 1 : 0) + ' × ' : nf(l.qte, l.qte % 1 ? 2 : 0) + ' ' + l.unite + ' ')) + l.nom).join(' + ') + (L.length > 4 ? ' + ' + (L.length - 4) + ' autre' + (L.length > 5 ? 's' : '') : '');
      return `<small class="op-bun-co">${esc(t)}</small>`; };
    const lignes = B.lignes.map(x => `<tr class="clic" data-fprod="${esc(x.id)}" data-fnom="${esc(x.nom)}" data-fq="${x.q != null ? x.q : ''}" data-fv="${x.v != null ? x.v : ''}" data-ft="${x.taux != null ? x.taux : ''}" data-fc="${x.c != null ? x.c : ''}" data-fcat="${esc(x.cat || '')}" role="button" tabindex="0" title="les ventes sur 12 semaines, la recette et la marge">
      <td class="n op-bun-q">${nf(x.q, x.q % 1 ? 1 : 0)}</td><td><b>${esc(x.nom)}</b>${compo(x)}${quand(x) ? `<small>${quand(x)}</small>` : ''}</td><td class="n">${fU(x.prix)}</td><td class="n">${fE(x.v)}</td><td class="n ${mc(x.taux)}">${x.taux != null ? fP0(x.taux) : '—'}</td></tr>`).join('');
    return `<div class="db-card op-bun">${tete('la journée · ' + B.n + ' bundle' + (B.n > 1 ? 's' : '') + ' · clic : la fiche')}
      <div class="op-bun-k"><div><div class="k">Vendus</div><div class="v">${nf(B.pieces, B.pieces % 1 ? 1 : 0)}</div></div><div><div class="k">CA bundles</div><div class="v">${fE(B.ca)}</div></div><div><div class="k">Part du CA</div><div class="v">${B.part != null ? fP(B.part) : '—'}</div></div><div><div class="k">Marge brute</div><div class="v ${mc(B.taux)}">${B.taux != null ? fP0(B.taux) : '—'}</div></div></div>
      <div class="op-large"><table class="db-pro-tab op-bun-tab"><thead><tr><th class="n">Qté</th><th>Bundle</th><th class="n" title="prix moyen encaissé">Prix</th><th class="n">CA</th><th class="n">Marge</th></tr></thead><tbody>${lignes}</tbody></table></div></div>`;
  }

  /* La vitrine selon la durée de vie (demande du 06/10/2026) : les trois onglets du suivi de
   * production — short life (vendu le jour même), medium life (se garde 2 à 3 jours), long life
   * (une semaine et plus) — chacun avec le niveau actuel de marchandises. La durée de vie vient du
   * suivi (`vie`), réglée dans /production. */
  const OP_VIES = ['S', 'M', 'L'];
  const OP_VIE_NOM = { S: 'Short life', M: 'Medium life', L: 'Long life' };
  const OP_VIE_SOUS = { S: 'vendu le jour même', M: 'se garde 2 à 3 jours', L: 'se garde une semaine et plus' };
  const OP_VIE_JOURS = 3;   // le stock d'un long life se lit face à trois jours de vente, comme au suivi
  const opVieDe = p => OP_VIES.includes(p.vie) ? p.vie : 'S';
  /** Un long life est sous le stock quand ce qui reste ne dépasse pas son stock minimum (0 sans réglage). */
  const opSous = p => Math.round(p.stock) <= (p.stockMin || 0);
  const opParJour = p => p.moyJ || p.prevJ || 0;
  /** Ce qui se vendra encore aujourd'hui : la prévision des heures à venir. */
  const opReste = p => (Array.isArray(p.cases) ? p.cases : []).reduce((a, c) => a + (c.reel ? 0 : (+c.prev || 0)), 0);
  /** Le niveau d'une durée de vie : les références, les pièces et leur valeur en vitrine, ce qui se vendra encore, les alertes. */
  function opNiveau(L) {
    const pos = p => Math.max(0, +p.stock || 0);
    return { n: L.length, st: L.reduce((a, p) => a + pos(p), 0), val: L.reduce((a, p) => a + pos(p) * (+p.prix || 0), 0), reste: L.reduce((a, p) => a + opReste(p), 0),
      parJ: L.reduce((a, p) => a + opParJour(p), 0), fin: L.reduce((a, p) => a + Math.max(0, +p.finJour || 0), 0),
      rupt: L.filter(p => p.verdict === 'rupture').length, manq: L.filter(p => p.verdict === 'manque').length, sous: L.filter(opSous).length };
  }
  /** La prochaine cuisson prévue aujourd'hui pour un produit : {de, q}, sinon null. */
  function opProchaine(U, p, now) {
    if (now == null) { return null; }
    for (const c of (U && U.cuissons) || []) { const de = opMin(c.de); if (c.valide || de == null || de <= now) { continue; } const l = (c.lignes || []).find(x => x.pid === p.pid); if (l && l.sortie > 0) { return { de: c.de, q: l.sortie }; } }
    return null;
  }
  /** Long life : les pièces à recuire, de quoi tenir trois jours quand il est sous le stock ; 0 quand une cuisson est déjà prévue aujourd'hui. */
  function opRecuireLong(U, p, now) {
    if (!opSous(p) || opProchaine(U, p, now)) { return 0; }
    return Math.max(1, (p.conseil && p.conseil.pieces) || 0, Math.ceil(opParJour(p) * OP_VIE_JOURS - Math.max(0, +p.stock || 0) - 1e-6));
  }
  /** À faire pour un long life : la cuisson déjà prévue, sinon de quoi tenir trois jours quand il est sous le stock. */
  function opFaireLong(U, p, now) {
    const pr = opProchaine(U, p, now);
    if (pr) { return 'cuisson de ' + pr.de + ' : ' + fN(pr.q); }
    const n = opRecuireLong(U, p, now);
    return n ? 'recuire ' + fN(n) : '';
  }
  /** La vente heure par heure d'un produit (demande du 06/10/2026) : la prévision de chaque heure de
   * la journée (`prevH`, passées comprises), le vendu des heures passées et de l'heure entamée, et le
   * stock projeté des heures à venir. {h: {fc, v, t: r|n|p, st}}. */
  function opVentesH(p, H) {
    const o = {};
    (H || []).forEach(h => { const c = (Array.isArray(p.cases) ? p.cases : []).find(y => y.h === h); if (!c) { o[h] = null; return; }
      const t = c.reel ? 'r' : (c.v != null ? 'n' : 'p');
      const fc = p.prevH && p.prevH[h] != null ? +p.prevH[h] : (t === 'p' ? +c.prev || 0 : null);
      o[h] = { fc, v: t === 'p' ? null : +c.v || 0, t, st: +c.q }; });
    return o;
  }
  /** Une case : la prévision de l'heure, en petit le vendu (vert : bien au-dessus, orange : bien en dessous) ; les heures à venir teintées du stock projeté (bas : orange, vide : rouge). */
  function opCaseH(x, h, nowH) {
    if (!x) { return { cls: 'vd', txt: '', sub: '', tt: '' }; }
    const proj = x.t !== 'r', fc = x.fc == null ? 0 : x.fc;
    const bas = x.st < -0.5 ? ' ko' : (x.st < Math.max(1, fc * 0.25) ? ' att' : '');
    const ecart = x.t === 'r' && x.v != null && fc >= 2 ? (x.v >= fc * 1.25 ? 'plus' : (x.v <= fc * 0.75 ? 'moins' : '')) : '';
    const tt = `${h} h : prévu ${x.fc == null ? '—' : nf(x.fc, 1)}` + (x.v != null ? (x.t === 'n' ? ` · vendu jusqu’ici ${fN(x.v)}` : ` · vendu ${fN(x.v)}`) : '') + (proj && x.st < 1e8 ? ` · vitrine projetée en fin d’heure ${nf(x.st, 0)}` : '');
    return { cls: 'v' + x.t + (proj ? bas : '') + (h === nowH ? ' now' : ''), txt: x.fc == null ? '' : (Math.round(x.fc) ? fN(x.fc) : '·'), sub: x.v == null ? '' : `<small class="${ecart}">${fN(x.v)}</small>`, tt };
  }

  /** Le total d'une heure sur plusieurs produits : la prévision et le vendu additionnés. */
  function opTotH(L, h, nowH) {
    const X = L.map(p => opVentesH(p, [h])[h]).filter(Boolean);
    const t = nowH != null && h > nowH ? 'p' : (h === nowH ? 'n' : 'r');
    return { fc: X.reduce((a, x) => a + (x.fc || 0), 0), v: t === 'p' ? null : X.reduce((a, x) => a + (x.v || 0), 0), t, st: 1e9 };
  }
  /** Au téléphone, la vente heure par heure sous un produit : une bande qui défile, l'heure, le prévu, le vendu. */
  function opBandeDe(V, H, nowH, lib) {
    const j = H.reduce((a, h) => a + (V[h] && V[h].fc ? V[h].fc : 0), 0), jv = H.reduce((a, h) => a + (V[h] && V[h].v ? V[h].v : 0), 0);
    return `<div class="op-hb">${lib ? `<span class="lb">${lib}</span>` : ''}<div class="c">${H.map(h => { const c = opCaseH(V[h], h, nowH); return `<span class="${c.cls}" title="${esc(c.tt)}"><i>${h}</i>${c.txt}${c.sub}</span>`; }).join('')}<span class="j" title="prévu du jour · vendu jusqu’ici"><i>jour</i>${fN(j)}<small>${fN(jv)}</small></span></div></div>`;
  }
  const opBande = (p, U, now) => { const H = Array.isArray(U.heures) ? U.heures : []; return opBandeDe(opVentesH(p, H), H, now == null ? null : Math.floor(now / 60)); };

  /** La vitrine en dépliant (demande du 06/10/2026) : catégorie › sous-catégorie › produit, dans les
   * trois onglets. Short et medium life : chaque niveau avec la prévision de chaque heure et, en petit,
   * le vendu. Long life : chaque niveau avec son stock face à trois jours de vente. Un clic ouvre le
   * niveau suivant ; tout est replié au départ. */
  function opArbre(D, L, mob, k) {
    const { now, U } = D;
    const long = k === 'L';
    const H = Array.isArray(U.heures) ? U.heures : [], nowH = now == null ? null : Math.floor(now / 60);
    const prevJ = p => H.reduce((a, h) => a + (+((p.prevH || {})[h]) || 0), 0);
    const pos = p => Math.max(0, +p.stock || 0);
    const jours = (st, pj) => pj > 0 ? st / pj : null;
    const somme = P => {
      const x = { P, n: P.length, sorti: P.reduce((a, p) => a + (+p.sorti || 0), 0), vendu: P.reduce((a, p) => a + (+p.vendu || 0), 0), st: P.reduce((a, p) => a + pos(p), 0) };
      if (long) { return Object.assign(x, { pj: P.reduce((a, p) => a + opParJour(p), 0), sous: P.filter(opSous).length, recuire: P.reduce((a, p) => a + opRecuireLong(U, p, now), 0) }); }
      const V = {}; H.forEach(h => { V[h] = opTotH(P, h, nowH); });
      return Object.assign(x, { V, jour: P.reduce((a, p) => a + prevJ(p), 0), rupt: P.filter(p => p.verdict === 'rupture').length, manq: P.filter(p => p.verdict === 'manque').length,
        recuire: P.reduce((a, p) => a + ((p.verdict === 'rupture' || p.verdict === 'manque') && p.conseil ? +p.conseil.pieces || 0 : 0), 0) });
    };
    const triP = long ? (a, b) => (opSous(b) - opSous(a)) || ((jours(pos(a), opParJour(a)) ?? 1e9) - (jours(pos(b), opParJour(b)) ?? 1e9)) || a.nom.localeCompare(b.nom)
      : (a, b) => (OP_RANG[a.verdict] ?? 4) - (OP_RANG[b.verdict] ?? 4) || prevJ(b) - prevJ(a);
    const poids = x => long ? x.pj : x.jour;
    const G = {}; L.forEach(p => { const g = p.groupe || 'Sans section', c = p.cat || 'Sans catégorie'; ((G[g] = G[g] || {})[c] = G[g][c] || []).push(p); });
    const arbre = Object.entries(G).map(([g, cs]) => { const sc = Object.entries(cs).map(([c, P]) => Object.assign(somme(P.slice().sort(triP)), { nom: c, cle: k + ':c:' + g + '|' + c })).sort((a, b) => poids(b) - poids(a));
      return Object.assign(somme(sc.flatMap(x => x.P)), { nom: g, cle: k + ':g:' + g, sc }); }).sort((a, b) => poids(b) - poids(a));
    const ouv = c => !!S.opOuv[c];
    const etatN = x => long ? (x.sous ? `<span class="op-vd rupture">${x.sous} sous le stock</span>` : '<span class="op-vd ok">en stock</span>')
      : ((x.rupt ? `<span class="op-vd rupture">${x.rupt} vide${x.rupt > 1 ? 's' : ''}</span> ` : '') + (x.manq ? `<span class="op-vd manque">${x.manq} va manquer</span>` : '') || '<span class="op-vd ok">en ordre</span>');
    const faireN = x => x.recuire ? 'recuire ' + fN(x.recuire) : '';
    const etatL = p => opSous(p) ? '<span class="op-vd rupture">sous le stock</span>' : '<span class="op-vd ok">en stock</span>';
    const jg = (st, pj) => { const pc = pj > 0 ? Math.min(100, 100 * st / (pj * OP_VIE_JOURS)) : (st > 0 ? 100 : 0); return `<span class="op-jg"><i class="${pc < 34 ? 'ko' : (pc < 67 ? 'att' : 'ok')}" style="width:${pc.toFixed(0)}%"></i></span>`; };
    const cases = V => H.map(h => { const c = opCaseH(V[h], h, nowH); return `<td class="h ${c.cls}" title="${esc(c.tt)}">${c.txt}${c.sub}</td>`; }).join('');
    const descend = (ligneN, ligneP) => arbre.map(g => ligneN(g, 1) + (ouv(g.cle) ? g.sc.map(c => ligneN(c, 2) + (ouv(c.cle) ? c.P.map(ligneP).join('') : '')).join('') : '')).join('');
    if (mob) {
      const resume = x => long ? `${fN(x.n)} réf. · ${fN(x.st)} en stock · ${x.pj ? nf(x.pj, 1) + ' par jour · ' + nf(jours(x.st, x.pj), 1) + ' j' : 'pas de vente lue'}` : `${fN(x.n)} réf. · ${fN(x.st)} en vitrine`;
      const ligneN = (x, nv) => `<div class="r nv${nv}${long ? '' : ' hs'}${ouv(x.cle) ? ' on' : ''}" data-opouv="${esc(x.cle)}"><span class="n"><b><span class="fl">▸</span>${esc(x.nom)}</b><small>${resume(x)}</small></span><span class="e">${etatN(x)}<b>${esc(faireN(x))}</b></span>${long ? '' : opBandeDe(x.V, H, nowH)}</div>`;
      const ligneP = long
        ? p => `<div class="r nv3"><span class="n"><b>${esc(p.nom)}</b><small><b class="${opSous(p) ? 'ko' : ''}">${fN(p.stock)}</b> en stock · ${opParJour(p) ? nf(opParJour(p), 1) + ' par jour · ' + nf(jours(pos(p), opParJour(p)), 1) + ' j' : 'pas de vente lue'}</small></span><span class="e">${etatL(p)}<b>${esc(opFaireLong(U, p, now))}</b></span></div>`
        : p => `<div class="r nv3 hs"><span class="n"><b>${esc(p.nom)}</b><small>${fN(p.sorti)} sortie${Math.round(p.sorti) >= 2 ? 's' : ''} · ${fN(p.vendu)} vendue${Math.round(p.vendu) >= 2 ? 's' : ''} · <b class="${p.stock <= 0 ? 'ko' : ''}">${fN(p.stock)}</b> en vitrine</small></span><span class="e"><span class="op-vd ${esc(p.verdict)}">${esc(opEtat(p))}</span><b>${esc(opFaire(p))}</b></span>${opBande(p, U, now)}</div>`;
      return `<div class="op-vl op-arbre">${descend(ligneN, ligneP)}</div>`;
    }
    const sousTitre = (x, nv) => `${fN(x.n)} réf.${nv === 1 ? ' · ' + fN(x.sc.length) + ' sous-catégorie' + (x.sc.length > 1 ? 's' : '') : ''}`;
    const aide = '<span>▸ un clic ouvre la sous-catégorie, puis les produits</span>';
    if (long) {
      const ligneN = (x, nv) => `<tr class="nv${nv}${ouv(x.cle) ? ' on' : ''}" data-opouv="${esc(x.cle)}"><td><span class="fl">▸</span><b>${esc(x.nom)}</b><small>${sousTitre(x, nv)}</small></td><td class="n">${fN(x.sorti)}</td><td class="n">${fN(x.vendu)}</td><td class="n">${fN(x.st)}</td><td class="n">${x.pj ? nf(x.pj, 1) : '—'}</td><td>${jg(x.st, x.pj)}</td><td class="n">${x.pj ? nf(jours(x.st, x.pj), 1) : '—'}</td><td>${etatN(x)}</td><td class="n"><b>${esc(faireN(x))}</b></td></tr>`;
      const ligneP = p => { const pj = opParJour(p);
        return `<tr class="nv3"><td><b>${esc(p.nom)}</b></td><td class="n">${fN(p.sorti)}</td><td class="n">${fN(p.vendu)}</td><td class="n${opSous(p) ? ' ko' : ''}"><b>${fN(p.stock)}</b></td><td class="n">${pj ? nf(pj, 1) : '—'}</td><td>${jg(pos(p), pj)}</td><td class="n">${pj ? nf(jours(pos(p), pj), 1) : '—'}</td><td>${etatL(p)}</td><td class="n"><b>${esc(opFaireLong(U, p, now))}</b></td></tr>`; };
      return `<div class="op-defile"><table class="db-t op-tbla op-arbre"><thead><tr><th>Catégorie › sous-catégorie › produit</th><th class="n">Sorties</th><th class="n">Vendues</th><th class="n">En stock</th><th class="n">Vendu par jour</th><th>Stock face à ${OP_VIE_JOURS} jours de vente</th><th class="n">Jours de stock</th><th>État</th><th class="n">À faire</th></tr></thead><tbody>${descend(ligneN, ligneP)}</tbody></table></div>
        <div class="op-hleg"><span><b>Vendu par jour</b> : la moyenne au comptoir des derniers mêmes jours</span><span><b>Jours de stock</b> : le stock divisé par la vente d’un jour</span><span><b>À faire</b> : la cuisson déjà prévue aujourd’hui, sinon de quoi tenir ${OP_VIE_JOURS} jours</span>${aide}</div>`;
    }
    const tete = H.map(h => `<th class="h${h === nowH ? ' now' : ''}">${h} h</th>`).join('');
    const ligneN = (x, nv) => `<tr class="nv${nv}${ouv(x.cle) ? ' on' : ''}" data-opouv="${esc(x.cle)}"><td><span class="fl">▸</span><b>${esc(x.nom)}</b><small>${sousTitre(x, nv)}</small></td><td class="n">${fN(x.sorti)}</td><td class="n">${fN(x.vendu)}</td><td class="n">${fN(x.st)}</td>${cases(x.V)}<td class="n"><b>${fN(x.jour)}</b></td><td>${etatN(x)}</td><td class="n"><b>${esc(faireN(x))}</b></td></tr>`;
    const ligneP = p => { const V = opVentesH(p, H);
      return `<tr class="nv3"><td><b>${esc(p.nom)}</b></td><td class="n">${fN(p.sorti)}</td><td class="n">${fN(p.vendu)}</td><td class="n${p.stock <= 0 ? ' ko' : ''}"><b>${fN(p.stock)}</b></td>${cases(V)}<td class="n"><b>${fN(prevJ(p))}</b></td><td><span class="op-vd ${esc(p.verdict)}">${esc(opEtat(p))}</span></td><td class="n"><b>${esc(opFaire(p))}</b></td></tr>`; };
    return `<div class="op-defile"><table class="db-t op-tbh op-arbre"><thead><tr><th>Catégorie › sous-catégorie › produit</th><th class="n">Sorties</th><th class="n">Vendues</th><th class="n">En vitrine</th>${tete}<th class="n">Prévu du jour</th><th>État</th><th class="n">À faire</th></tr></thead><tbody>${descend(ligneN, ligneP)}</tbody></table></div>
      <div class="op-hleg"><span><b>12</b> le prévu de l’heure, la prévision des ${U.base && U.base.lus ? U.base.lus : 6} derniers ${esc(U.jourNom || '')}${/s$/.test(U.jourNom || '') ? '' : 's'}</span><span><small>9</small> en petit, le vendu (<b class="plus">vert</b> : bien au-dessus du prévu, <b class="moins">orange</b> : bien en dessous)</span><span><i class="r"></i>heure passée</span><span><i class="now"></i>l’heure en cours</span><span><i class="p"></i>à venir</span><span><i class="att"></i>la vitrine sera basse</span><span><i class="ko"></i>la vitrine sera vide : vente perdue</span>${aide}</div>`;
  }

  /* La vitrine au bureau (10/10/2026, maquette E retravaillée) : ce qui va manquer, une liste par durée de vie,
   * filtrée par trois badges — rouge : vide maintenant, orange : va manquer d'ici la fermeture, vert : en ordre
   * (replié au départ, pour consultation). Short et medium life se recuisent au rythme des 6 derniers mêmes jours,
   * heure par heure : la journée du produit en cases, et la prochaine cuisson où l'ajouter. Long life est un stock
   * en magasin : face à trois jours de vente. Un clic sur un produit ouvre sa fiche de stock. */
  const VIT_REGLE = { S: 'vendu le jour même · recuit plusieurs fois par jour, au rythme des ventes heure par heure', M: 'se garde 2 à 3 jours · recuit au rythme des ventes heure par heure', L: 'stock en magasin · non périssable ou longue DLC' };
  const VIT_F = [['ko', 'Vide maintenant'], ['att', 'Va manquer'], ['ok', 'En ordre']];
  function vitLire(D, p) {
    const { U, now } = D, H = Array.isArray(U.heures) ? U.heures : [], nowH = now == null ? 99 : Math.floor(now / 60);
    const prevH = h => +((p.prevH || {})[h]) || 0, caseH = h => (Array.isArray(p.cases) ? p.cases : []).find(c => c.h === h);
    if (opVieDe(p) === 'L') {
      const pj = opParJour(p), st = Math.max(0, +p.stock || 0), n = Math.max((p.conseil && +p.conseil.pieces) || 0, Math.ceil(pj * OP_VIE_JOURS - st - 1e-6));
      if (pj <= 0.3) { return { c: 'ok', st, pj }; }
      if (Math.round(+p.stock || 0) <= 0) { return { c: 'ko', st, pj, n, txt: 'plus en stock' }; }
      if (st < pj * OP_VIE_JOURS || opSous(p)) { return { c: 'att', st, pj, n, txt: 'stock pour ' + nf(st / pj, 1) + ' jour' + (st / pj >= 2 ? 's' : '') }; }
      return { c: 'ok', st, pj };
    }
    // Une heure vide compte quand des clients le demandent encore ensuite (la prévision de l'heure suivante, et au
    // moins une demi-pièce d'ici la fermeture) : la dernière pièce vendue en fin de journée n'est pas un manque.
    const vide = h => { const c = caseH(h); return !!c && +c.q <= 0.05; };
    const demande = h => prevH(h + 1) >= 0.3 && H.filter(k => k > h).reduce((a, k) => a + prevH(k), 0) >= 0.5;
    if (now == null) {
      // Journée terminée : la première heure vide alors que des clients le demandaient.
      const hv = H.find(h => vide(h) && demande(h));
      if (hv == null) { return { c: 'ok' }; }
      const q = p.manque && +p.manque.q >= 0.5 ? +p.manque.q : Math.max(1, Math.ceil(H.filter(h => h >= hv).reduce((a, h) => a + prevH(h), 0)));
      return { c: 'ko', h: hv, q, n: Math.ceil(q), txt: 'vide à ' + hv + ' h' };
    }
    // En cours de journée, d'après la fiche de stock : vide maintenant (la vitrine à zéro, et encore à vendre),
    // sinon la première heure à venir où elle sera vide. Une rupture du matin réapprovisionnée depuis ne compte plus.
    const K = vitStock(D, p), pv = !K.rec && K.futur.length ? K.futur[0] : null;
    let c = null, hv = null;
    if (K.st <= 0.05 && K.reste >= 0.5) { c = 'ko'; hv = nowH; while (H.includes(hv - 1) && vide(hv - 1)) { hv--; } }
    else { hv = H.find(h => h >= nowH && vide(h) && demande(h)); if (hv != null) { c = 'att'; } }
    if (c == null) { return { c: 'ok' }; }
    const perte = K.reste - K.st - K.prevu, q = perte >= 0.5 ? perte : (p.manque && +p.manque.q >= 0.5 ? +p.manque.q : 1);
    return { c, h: hv, q, n: K.rec, pv, txt: (c === 'ko' ? 'vide depuis ' : 'vide vers ') + hv + ' h' };
  }
  /** La journée d'un produit, une case par heure : vert s'il y en a, orange si la vitrine sera basse, rouge si elle est vide alors que des clients le demandent. */
  function vitBande(D, p) {
    const { U, now } = D, H = Array.isArray(U.heures) ? U.heures : [], nowH = now == null ? 99 : Math.floor(now / 60);
    const prevH = h => +((p.prevH || {})[h]) || 0;
    return `<span class="vt-bd">${H.map(h => { const c = (Array.isArray(p.cases) ? p.cases : []).find(x => x.h === h);
      if (!c) { return '<i class="vd"></i>'; }
      const st = +c.q, fut = h > nowH, d = h < nowH ? (+c.v || 0) : prevH(h) + prevH(h + 1);
      const cls = st <= 0.05 ? (prevH(h + 1) >= 0.3 || (h === H[H.length - 1] && d >= 0.3) ? 'ko' : 'vd') : (fut && st < prevH(h + 1) ? 'att' : 'ok');
      return `<i class="${cls}${fut ? ' fut' : ''}${h === nowH ? ' now' : ''}" title="${h} h · ${fN(Math.max(0, st))} en vitrine${fut ? ' (prévu)' : ''} · ${h < nowH ? 'vendu ' + fN(+c.v || 0) : 'prévu ' + nf(prevH(h), 1)}"></i>`; }).join('')}</span>`;
  }
  function opVitrineE(D) {
    const { now, U, prods } = D;
    const H = Array.isArray(U.heures) ? U.heures : [], fin = now == null;
    const jour = esc(U.jourNom || '') + (/s$/.test(U.jourNom || '') ? '' : 's');
    const suite = fin ? null : (Array.isArray(U.cuissons) ? U.cuissons : []).filter(c => !c.valide && opMin(c.de) > now).sort((a, b) => opMin(a.de) - opMin(b.de))[0] || null;
    // Les filtres, par durée de vie (10/10/2026) : trois petits carrés discrets en bout de ligne.
    const FB = S.vitFb || (S.vitFb = {}), filtre = k => FB[k] || (FB[k] = { ko: true, att: true, ok: false });
    const L = prods.map(p => Object.assign({ p, e: vitLire(D, p) }));
    const nb = c => L.filter(x => x.e.c === c).length;
    const manque = L.filter(x => x.e.c !== 'ok'), eur = manque.filter(x => opVieDe(x.p) !== 'L').reduce((a, x) => a + (x.e.q || 0) * (+x.p.prix || 0), 0);
    const ordre = { ko: 0, att: 1, ok: 2 };
    const geste = (x, k) => {
      if (x.e.c === 'ok') { const fj = Math.max(0, +x.p.finJour || 0); return k === 'L' ? `<span class="g ok">en stock<small>${x.e.pj ? nf(x.e.st / x.e.pj, 1) + ' jours de vente' : ''}</small></span>` : `<span class="g ok">${fj >= 0.5 ? fN(fj) : '—'}<small>${fj >= 0.5 ? 'restera ce soir' : 'juste assez'}</small></span>`; }
      if (k === 'L') { const pr = opProchaine(U, x.p, now); return pr ? `<span class="g">${fN(pr.q)}<small>cuisson de ${esc(pr.de)}</small></span>` : `<span class="g">+${fN(x.e.n)}<small>pour tenir ${OP_VIE_JOURS} jours</small></span>`; }
      if (fin) { return `<span class="g">${fN(x.e.q)}<small>ventes perdues</small></span>`; }
      if (!x.e.n) { return x.e.pv ? `<span class="g">${fN(x.e.pv.q)}<small>déjà prévu à ${esc(x.e.pv.de)}</small></span>` : '<span class="g ok">—<small>juste assez</small></span>'; }
      return `<span class="g">+${fN(x.e.n)}<small>${suite ? 'dans la cuisson de ' + esc(suite.de) : 'à recuire'}</small></span>`;
    };
    const attrs = p => `data-vstk="${esc(p.pid)}" role="button" tabindex="0"`;
    const ligne = (x, k) => {
      const p = x.p, e = x.e;
      const w = k === 'L' ? `stock <b>${fN(e.st || 0)}</b> · ${e.pj ? nf(e.pj, 1) + ' vendus par jour' : 'pas de vente lue'}${e.txt ? ' · <b>' + esc(e.txt) + '</b>' : ''}`
        : (e.c === 'ok' ? `sorti ${fN(p.sorti)} · vendu ${fN(p.vendu)} · en vitrine ${fN(Math.max(0, +p.stock || 0))}` : `<b>${esc(e.txt)}</b> · sorti ${fN(p.sorti)} · vendu ${fN(p.vendu)}`);
      const vis = k === 'L' ? `<span class="vt-jl"><i class="${e.c}" style="width:${e.pj > 0 ? Math.min(100, 100 * (e.st || 0) / (e.pj * OP_VIE_JOURS)).toFixed(0) : 0}%"></i></span>` : vitBande(D, p);
      const mq = e.c === 'ok' ? '' : (k === 'L' ? `<b>${fN(e.n)}</b>pour ${OP_VIE_JOURS} jours de vente` : `<b>${fN(e.q)} pièce${e.q >= 2 ? 's' : ''}</b>${fE((e.q || 0) * (+p.prix || 0))} de ventes`);
      return `<div class="vt-l ${e.c} clic" ${attrs(p)} title="le stock du produit"><span class="n"><i class="pt ${e.c}"></i><b>${esc(p.nom)}</b><small>${esc(p.cat || '')}</small><span class="w">${w}</span></span><span class="v">${vis}</span><span class="mq">${mq}</span>${geste(x, k)}</div>`;
    };
    const blocs = OP_VIES.map(k => {
      const B = L.filter(x => opVieDe(x.p) === k);
      if (!B.length) { return ''; }
      const F = filtre(k);
      const V = B.filter(x => F[x.e.c]).sort((a, b) => (ordre[a.e.c] - ordre[b.e.c]) || ((a.e.h ?? 99) - (b.e.h ?? 99)) || ((b.e.q || 0) - (a.e.q || 0)) || a.p.nom.localeCompare(b.p.nom));
      const tout = !!(S.vitTout || {})[k], MAX = 12, vus = tout ? V : V.slice(0, MAX);
      const cpt = VIT_F.map(([c, nom]) => { const n = B.filter(x => x.e.c === c).length;
        return `<button type="button" class="vt-q ${c}${F[c] ? ' on' : ''}" data-vitq="${k}:${c}" aria-pressed="${F[c] ? 'true' : 'false'}" title="${fin && c === 'ko' ? 'A manqué' : nom} : ${fN(n)} · un clic filtre la liste"><i></i>${fN(n)}</button>`; }).join('');
      // Chaque durée de vie est une liste déroulante (10/10/2026) : l'en-tête résume, le détail s'ouvre au clic.
      const ouvert = !!(S.vitOuv || {})[k];
      const M = B.filter(x => x.e.c !== 'ok'), aCuire = M.reduce((a, x) => a + (x.e.n || 0), 0), eurB = k === 'L' ? 0 : M.reduce((a, x) => a + (x.e.q || 0) * (+x.p.prix || 0), 0);
      const resB = !M.length ? '<span class="rs ok">✓ rien ne va manquer</span>'
        : `<span class="rs">${fN(M.length)} ${fin ? 'ont manqué' : 'vont manquer'}${aCuire && !fin ? ' · <b>+' + fN(aCuire) + '</b> ' + (k === 'L' ? 'à refaire' : 'à cuire') : ''}${eurB >= 1 ? ' · ' + fE(eurB) + (fin ? ' perdus' : ' à sauver') : ''}</span>`;
      const axe = k === 'L' ? '<div class="vt-ax l"><span>stock face à ' + OP_VIE_JOURS + ' jours de vente</span></div>' : `<div class="vt-ax">${H.map(h => `<span>${h} h</span>`).join('')}</div>`;
      const vide = !V.length ? `<div class="vt-vide">${F.ko || F.att ? (B.some(x => x.e.c !== 'ok') ? 'rien dans les filtres choisis' : '✓ rien ne va manquer d’ici la fermeture') : 'aucun produit dans les filtres choisis'}</div>` : '';
      return `<div class="vt-b${ouvert ? ' on' : ''}"><div class="vt-h"><button type="button" class="vt-ht" data-vitb="${k}" aria-expanded="${ouvert ? 'true' : 'false'}"><span class="fl">${ouvert ? '▾' : '▸'}</span><span class="op-vie ${k}">${k}</span><b>${OP_VIE_NOM[k]}</b><span class="r">${fN(B.length)} réf. · ${esc(VIT_REGLE[k])}</span>${resB}</button><span class="cpt">${cpt}</span></div>
        ${ouvert ? `${V.length ? `<div class="vt-t"><span></span>${axe}<span></span><span></span></div>` : ''}${vus.map(x => ligne(x, k)).join('')}${vide}
        ${V.length > MAX ? `<button type="button" class="vt-plus" data-vitall="${k}">${tout ? '▴ replier' : (V.length - MAX === 1 ? '▸ voir le dernier' : '▸ voir les ' + fN(V.length - MAX) + ' autres')}</button>` : ''}` : ''}</div>`;
    }).join('');
    const tete = `<span class="db-mini">${fin ? 'journée terminée' : 'à ' + esc(U.maintenant || opHM(now))} · prévision des ${U.base && U.base.semaines ? U.base.semaines : 6} derniers ${jour}</span><span class="db-mini" style="margin-left:auto">${suite ? `prochaine cuisson : <b>${esc(suite.nom)}</b> à ${esc(suite.de)}${suite.four ? ' (four ' + esc(suite.four) + ')' : ''}` : (fin ? '' : 'plus de cuisson prévue aujourd’hui')}</span>`;
    const resume = manque.length ? `<b>${fN(manque.length)}</b><span>produit${manque.length > 1 ? 's' : ''} ${fin ? 'ont manqué' : 'vont manquer d’ici la fermeture'}${eur >= 1 ? ' · ' + fE(eur) + ' de ventes ' + (fin ? 'perdues' : 'à sauver') : ''}</span>` : `<b class="ok">✓</b><span>rien ne va manquer d’ici la fermeture</span>`;
    return `<div class="db-card op-bloc vt"><div class="ct"><span class="op-h2">La vitrine</span>${tete}</div>
      <div class="vt-top"><span class="vt-res">${resume}</span></div>${blocs}
      <div class="vt-leg"><span><i class="ok"></i>en vitrine</span><span><i class="att"></i>vitrine basse</span><span><i class="ko"></i>vide alors que des clients le demandent</span><span>une case par heure · cadre noir : maintenant · pâle : à venir · un clic ouvre le stock du produit</span></div></div>`;
  }

  /* La fiche de stock d'un produit de La vitrine (10/10/2026, maquette 2 « la réponse d'abord ») : faut-il
   * recuire, et combien, pour finir la journée au plus juste. Le calcul part de la vitrine (jamais sous zéro :
   * un stock négatif au panel veut dire des ventes au-delà des sorties déclarées), retire ce qui se vendra
   * encore d'ici la fermeture (la prévision des derniers mêmes jours, l'heure entamée au prorata) et ce qui
   * est déjà prévu dans les cuissons à venir. Le graphique : la moyenne vendue par heure, le vendu
   * d'aujourd'hui, la vitrine heure par heure, et la vitrine avec la recuisson proposée. */
  function vitStock(D, p) {
    const { U, now } = D, H = Array.isArray(U.heures) ? U.heures : [], t = now == null ? null : now / 60;
    const prevH = h => +((p.prevH || {})[h]) || 0;
    const reste = t == null ? 0 : H.reduce((a, h) => a + prevH(h) * Math.max(0, Math.min(1, h + 1 - t)), 0);
    const st = Math.max(0, +p.stock || 0);
    const futur = t == null ? [] : (Array.isArray(U.cuissons) ? U.cuissons : []).filter(c => !c.valide && opMin(c.de) > now)
      .map(c => ({ nom: c.nom, de: c.de, q: +(((c.lignes || []).find(l => l.pid === p.pid) || {}).sortie) || 0 })).filter(c => c.q > 0);
    const prevu = futur.reduce((a, c) => a + c.q, 0);
    const suite = t == null ? null : (Array.isArray(U.cuissons) ? U.cuissons : []).filter(c => !c.valide && opMin(c.de) > now).sort((a, b) => opMin(a.de) - opMin(b.de))[0] || null;
    const long = opVieDe(p) === 'L';
    const rec = t == null ? 0 : (long ? opRecuireLong(U, p, now) : Math.max(0, Math.ceil(reste - st - prevu - 0.25)));
    return { reste, st, futur, prevu, suite, rec, long, sans: st + prevu - reste, avec: st + prevu + rec - reste, plaque: p.conseil && p.conseil.plaque ? +p.conseil.plaque : null };
  }
  function stkCourbe(D, p, K) {
    const { U, now } = D, H = Array.isArray(U.heures) ? U.heures : [], n = H.length;
    if (!n) { return ''; }
    const W = 580, HH = 240, m = { l: 30, r: 14, t: 30, b: 26 }, bw = (W - m.l - m.r) / n, t = now == null ? null : now / 60;
    const g = (o, h) => +((o || {})[h]) || 0, cas = h => (Array.isArray(p.cases) ? p.cases : []).find(c => c.h === h);
    const moy = H.map(h => g(p.moy, h)), vc = H.map(h => (p.vc || {})[h] == null ? null : +p.vc[h]);
    const c0 = cas(H[0]), dep = c0 ? Math.max(0, +c0.q + (+c0.v || 0)) : 0;
    const passe = [[H[0], dep]].concat(H.filter(h => { const c = cas(h); return c && c.reel && (t == null || h + 1 <= t); }).map(h => [h + 1, Math.max(0, +cas(h).q)]));
    if (t != null) { passe.push([t, K.st]); }
    // La vitrine à venir : les cuissons prévues, la recuisson proposée (à la prochaine cuisson, sinon tout de suite), la prévision au prorata.
    const proj = [];
    if (t != null && t < H[n - 1] + 1) {
      let cur = K.st + (K.suite ? 0 : K.rec); proj.push([t, cur]);
      const tS = K.suite ? opMin(K.suite.de) / 60 : null;
      H.forEach(h => { if (h + 1 <= t) { return; }
        K.futur.forEach(f => { const x = opMin(f.de) / 60; if (x >= h && x < h + 1) { cur += f.q; } });
        if (K.rec && tS != null && tS >= h && tS < h + 1) { cur += K.rec; }
        cur -= g(p.prevH, h) * (h + 1 - Math.max(h, t)); proj.push([h + 1, Math.max(0, cur)]); });
    }
    const mx = Math.max(1, dep, ...moy, ...vc.map(v => v || 0), ...passe.map(x => x[1]), ...proj.map(x => x[1]));
    const pas = mx <= 6 ? 1 : mx <= 12 ? 2 : mx <= 30 ? 5 : mx <= 60 ? 10 : mx <= 150 ? 25 : 50, top = Math.ceil(mx / pas) * pas;
    const X = x => m.l + bw * (x - H[0]), Y = v => m.t + (HH - m.t - m.b) * (1 - v / top), y0 = Y(0);
    let s = '';
    for (let v = 0; v <= top + 1e-9; v += pas) { s += `<line x1="${m.l}" x2="${W - m.r}" y1="${Y(v).toFixed(1)}" y2="${Y(v).toFixed(1)}" class="gl"/><text class="ax" x="${m.l - 6}" y="${(Y(v) + 3.5).toFixed(1)}" text-anchor="end">${v}</text>`; }
    if (t != null) { s += `<rect x="${X(t).toFixed(1)}" y="${m.t}" width="${Math.max(0, X(H[n - 1] + 1) - X(t)).toFixed(1)}" height="${(y0 - m.t).toFixed(1)}" class="fu"/>`; }
    H.forEach((h, i) => { const x0 = X(h) + bw * 0.18, w = bw * 0.3;
      if (moy[i] > 0) { s += `<rect x="${x0.toFixed(1)}" y="${Y(moy[i]).toFixed(1)}" width="${w.toFixed(1)}" height="${(y0 - Y(moy[i])).toFixed(1)}" rx="3" class="my"><title>${h} h · moyenne ${nf(moy[i], 1)}</title></rect>`; }
      if (vc[i] != null && (t == null || h <= t)) { s += `<rect x="${(x0 + w + 2).toFixed(1)}" y="${Y(vc[i]).toFixed(1)}" width="${w.toFixed(1)}" height="${(y0 - Y(vc[i])).toFixed(1)}" rx="3" class="vc"><title>${h} h · vendu aujourd’hui ${fN(vc[i])}</title></rect>`; }
      const c = cas(h);
      if (c && c.reel && +c.q <= 0.05 && (g(p.prevH, h + 1) >= 0.3 || (i === n - 1 && g(p.prevH, h) >= 0.3))) { s += `<rect x="${(X(h) + 2).toFixed(1)}" y="${(y0 + 2).toFixed(1)}" width="${(bw - 4).toFixed(1)}" height="4" rx="2" class="vd"><title>${h} h · vide</title></rect>`; }
      s += `<text class="ax" x="${(X(h) + bw / 2).toFixed(1)}" y="${HH - 9}" text-anchor="middle">${h} h</text>`; });
    const repere = (x, lb, cls) => { const xx = X(x); return `<line x1="${xx.toFixed(1)}" x2="${xx.toFixed(1)}" y1="${m.t - 4}" y2="${y0.toFixed(1)}" class="cu ${cls}"/><text class="lb ${cls}" x="${xx.toFixed(1)}" y="${m.t - 9}" text-anchor="${xx < m.l + 50 ? 'start' : (xx > W - 60 ? 'end' : 'middle')}">${lb}</text>`; };
    (Array.isArray(U.cuissons) ? U.cuissons : []).forEach(c => { const l = (c.lignes || []).find(x => x.pid === p.pid); if (l && +l.sortie > 0 && opMin(c.de) / 60 >= H[0]) { s += repere(opMin(c.de) / 60, '+' + fN(l.sortie) + ' · ' + esc(c.de), t != null && opMin(c.de) > now ? 'av' : ''); } });
    if (K.rec && t != null) { s += repere(K.suite ? opMin(K.suite.de) / 60 : t, '+' + fN(K.rec) + ' proposé', 'pr'); }
    const pts = L => L.map(([x, v]) => X(x).toFixed(1) + ',' + Y(v).toFixed(1)).join(' ');
    s += `<polyline points="${pts(passe)}" class="vi"/>` + passe.slice(1).map(([x, v]) => `<circle cx="${X(x).toFixed(1)}" cy="${Y(v).toFixed(1)}" r="3.2" class="vi"/>`).join('');
    if (proj.length > 1) { const z = proj[proj.length - 1]; s += `<polyline points="${pts(proj)}" class="pj${K.rec ? ' pr' : ''}"/><text class="lb${K.rec ? ' pr' : ' gr'}" x="${(X(z[0]) - 2).toFixed(1)}" y="${(Y(z[1]) - 6).toFixed(1)}" text-anchor="end">ce soir ${fN(z[1])}</text>`; }
    if (t != null) { s += `<line x1="${X(t).toFixed(1)}" x2="${X(t).toFixed(1)}" y1="${m.t - 2}" y2="${y0.toFixed(1)}" class="nw"/>`; }
    return `<svg viewBox="0 0 ${W} ${HH}" class="sk-gr" role="img" aria-label="La journée de ${esc(p.nom)} heure par heure">${s}</svg>`;
  }
  function stkOuvrir(pid) { S.stk = { pid, retour: document.activeElement }; stkRendre(); }
  function stkFermer() { const r = S.stk && S.stk.retour; S.stk = null; stkRendre(); if (r && r.focus) { try { r.focus(); } catch (e) { /* la ligne a été redessinée */ } } }
  function stkRendre() {
    let box = document.getElementById('db-stk');
    if (!S.stk) { if (box) { box.innerHTML = ''; } if (!S.fiche && !S.invModale) { document.documentElement.classList.remove('db-fiche-ouverte'); } return; }
    const D = opDonnees(), U = D.U, p = U && (D.prods || []).find(x => String(x.pid) === String(S.stk.pid));
    if (!p) { S.stk = null; if (box) { box.innerHTML = ''; } return; }
    if (!box) { box = document.createElement('div'); box.id = 'db-stk'; document.body.appendChild(box); }
    document.documentElement.classList.add('db-fiche-ouverte');
    const K = vitStock(D, p), fin = D.now == null, k = opVieDe(p), pl = (n, m) => fN(n) + ' ' + m + (Math.round(n) >= 2 ? 's' : '');
    const jour = esc(U.jourNom || '') + (/s$/.test(U.jourNom || '') ? '' : 's'), sem = U.base && U.base.semaines ? U.base.semaines : 6;
    const q = (v, lb) => `<div><b>${v}</b>${lb}</div>`;
    let rep;
    if (fin) {
      const lu = Math.max(0, +p.stock || 0);
      const e = vitLire(D, p);
      rep = `<div class="sk-rep no"><span class="big">${fN(lu)}</span><span class="t"><b>Journée terminée</b><span>sorti ${fN(p.sorti)} · vendu ${fN(p.vendu)} · ${pl(lu, 'pièce')} en vitrine à la fermeture${e.c !== 'ok' && !K.long ? ' · ' + esc(e.txt) + ', ' + pl(Math.round(e.q), 'vente') + ' perdue' + (Math.round(e.q) >= 2 ? 's' : '') : ''}</span></span></div>`;
    } else if (K.long) {
      const pj = opParJour(p), pr = opProchaine(U, p, D.now);
      rep = K.rec ? `<div class="sk-rep go"><span class="big">+${fN(K.rec)}</span><span class="t"><b>Refaire ${pl(K.rec, 'pièce')}</b><span>de quoi tenir ${OP_VIE_JOURS} jours de vente</span></span><span class="eq">${q(nf(pj * OP_VIE_JOURS, 1), 'vente de ' + OP_VIE_JOURS + ' jours')}<em>−</em>${q(fN(K.st), 'en stock')}<em>=</em>${q(fN(K.rec), 'à refaire')}</span></div>`
        : `<div class="sk-rep no"><span class="big">${pj > 0 ? nf(K.st / pj, 1) : '—'}</span><span class="t"><b>${pr ? 'Cuisson prévue à ' + esc(pr.de) + ' : ' + fN(pr.q) : 'Stock suffisant'}</b><span>${fN(K.st)} en stock · ${pj > 0 ? nf(pj, 1) + ' vendus par jour, soit ' + nf(K.st / pj, 1) + ' jours de vente' : 'pas de vente lue'}</span></span></div>`;
    } else if (K.rec) {
      const ou = K.suite ? ' dans la cuisson de ' + esc(K.suite.de) : ' maintenant';
      rep = `<div class="sk-rep go"><span class="big">+${fN(K.rec)}</span><span class="t"><b>Recuire ${pl(K.rec, 'pièce')}${ou}</b><span>pour finir la journée au plus juste : ${pl(Math.max(0, Math.round(K.avec)), 'pièce')} ce soir${K.plaque ? ' · plaque de ' + fN(K.plaque) : ''}</span></span>
        <span class="eq">${q(nf(K.reste, 1), 'à vendre d’ici la fermeture')}<em>−</em>${q(fN(K.st), 'en vitrine')}${K.prevu ? '<em>−</em>' + q(fN(K.prevu), 'déjà prévu') : ''}<em>=</em>${q(fN(K.rec), 'à recuire')}</span></div>`;
    } else {
      const reste = Math.max(0, Math.round(K.sans));
      rep = `<div class="sk-rep no"><span class="big">0</span><span class="t"><b>Pas de recuisson</b><span>${fN(K.st)} en vitrine${K.prevu ? ' et ' + fN(K.prevu) + ' prévu' + (K.prevu >= 2 ? 's' : '') : ''} pour ${nf(K.reste, 1)} à vendre : ${reste ? pl(reste, 'pièce') + ' en trop ce soir' : 'juste assez'}</span></span>
        <span class="eq">${q(fN(K.st + K.prevu), K.prevu ? 'en vitrine et prévu' : 'en vitrine')}<em>−</em>${q(nf(K.reste, 1), 'à vendre d’ici la fermeture')}<em>=</em>${q(fN(reste), 'en trop ce soir')}</span></div>`;
    }
    const cu = (Array.isArray(U.cuissons) ? U.cuissons : []).map(c => { const l = (c.lignes || []).find(x => x.pid === p.pid); return l && +l.sortie > 0 ? `<div><span>${esc(c.nom)} · ${esc(c.de)}${!fin && opMin(c.de) > D.now ? ' <small>à venir</small>' : ''}</span><b>+${fN(l.sortie)}</b></div>` : ''; }).join('')
      + (K.rec && !fin && !K.long ? `<div class="pr"><span>proposé · ${K.suite ? esc(K.suite.nom) + ' ' + esc(K.suite.de) : 'maintenant'}</span><b>+${fN(K.rec)}</b></div>` : '');
    const sc = K.rec && !fin && !K.long ? `<div class="sk-sc"><div class="a">Sans recuisson<b>${K.sans < -0.5 ? 'vide, ' + pl(Math.round(-K.sans), 'vente') + ' perdue' + (Math.round(-K.sans) >= 2 ? 's' : '') : fN(Math.max(0, K.sans)) + ' ce soir'}</b></div><div class="b">Avec +${fN(K.rec)}<b>${fN(Math.max(0, Math.round(K.avec)))} ce soir</b></div></div>` : '';
    const neg = +p.stock < -0.5 ? `<div class="sk-nt">Le panel compte ${fN(+p.stock)} : ${pl(-Math.round(+p.stock), 'vente')} au-delà des sorties déclarées. Le calcul part d’une vitrine vide.</div>` : '';
    const lg = `<div class="sk-lg"><span><i class="my"></i>moyenne des ${sem} derniers ${jour}</span><span><i class="vc"></i>vendu aujourd’hui</span><span><i class="l vi"></i>en vitrine</span>${!fin ? `<span><i class="l pj${K.rec ? ' pr' : ''}"></i>${K.rec ? 'avec la recuisson' : 'prévu'}</span>` : ''}</div>`;
    const lien = `<button type="button" class="sk-fi" data-fprod="${esc(p.pid)}" data-fnom="${esc(p.nom)}" data-fq="${p.vendu != null ? p.vendu : ''}" data-fv="${p.vendu != null && p.prix ? (+p.vendu * +p.prix).toFixed(2) : ''}" data-ft="" data-fc="" data-fcat="${esc(p.cat || '')}">la fiche du produit ›</button>`;
    box.innerHTML = `<div class="fi-voile" data-skfermer="1"></div><div class="fi-modale sk-modale" role="dialog" aria-modal="true" aria-label="Le stock de ${esc(p.nom)}">
      <div class="fi-hd"><span class="op-vie ${k}">${k}</span><div class="t"><h2>${esc(p.nom)}</h2><div class="s">${esc(p.cat || '')}${p.cat ? ' · ' : ''}${esc(nomShop())} · ${fin ? 'journée terminée' : 'à ' + esc(U.maintenant || opHM(D.now))}${p.prix ? ' · prix ' + fU(+p.prix) : ''} · ${lien}</div></div><button type="button" class="fi-x" data-skfermer="1" aria-label="Fermer">✕</button></div>
      <div class="fi-bd">${rep}<div class="sk-duo"><div>${lg}${stkCourbe(D, p, K)}${neg}</div><div class="sk-cu"><h4>Les cuissons du jour</h4>${cu || '<div><span>aucune cuisson prévue</span><b>—</b></div>'}${sc}</div></div></div></div>`;
    box.querySelectorAll('[data-skfermer]').forEach(b => b.addEventListener('click', stkFermer));
    box.querySelectorAll('[data-fprod]').forEach(b => b.addEventListener('click', () => { S.stk = null; stkRendre(); ficheOuvrir(b); }));
  }

  /** La carte « La vitrine » : trois onglets de durée de vie, le niveau de chacun, puis ses références en dépliant. */
  function opVitrine(D, mob) {
    if (!mob) { return opVitrineE(D); }
    const { now, U, prods, lienProd } = D;
    const k = S.opVie;
    const N = {}; OP_VIES.forEach(x => { N[x] = opNiveau(prods.filter(p => opVieDe(p) === x)); });
    const onglet = x => { const n = N[x];
      const jauge = x === 'L' ? (n.parJ > 0 ? Math.min(100, 100 * n.st / (n.parJ * OP_VIE_JOURS)) : null) : (n.reste > 0.5 ? Math.min(100, 100 * n.st / n.reste) : null);
      const lib = x === 'L' ? (n.parJ > 0 ? `${nf(n.st / n.parJ, 1)} jour${n.st / n.parJ >= 2 ? 's' : ''} de vente` : 'pas de vente lue')
        : (now == null ? (x === 'S' ? `${fN(n.fin)} jeté${n.fin >= 2 ? 's' : ''} ce soir` : `${fN(n.fin)} pour demain`) : (n.reste > 0.5 ? `pour ${fN(n.reste)} à vendre d’ici la fermeture` : 'plus rien à vendre aujourd’hui'));
      const al = x === 'L' ? (n.sous ? `<span class="op-vd rupture">${n.sous} sous le stock</span>` : '') : (n.rupt ? `<span class="op-vd rupture">${n.rupt} vide${n.rupt > 1 ? 's' : ''}</span>` : '') + (n.manq ? `<span class="op-vd manque">${n.manq} va manquer</span>` : '');
      return `<button type="button" class="op-v3 ${x}${x === k ? ' on' : ''}" data-opvie="${x}"><span class="hd"><span class="op-vie ${x}">${x}</span><b>${OP_VIE_NOM[x]}</b><small>${fN(n.n)} réf.${mob ? '' : ' · ' + OP_VIE_SOUS[x]}</small></span>
        <span class="niv"><b>${fN(n.st)}</b> pièce${n.st >= 2 ? 's' : ''} ${x === 'L' ? 'en stock' : 'en vitrine'}${n.val > 0 ? ` · ${fE(n.val)}` : ''}</span>
        ${jauge != null ? `<span class="jg"><i style="width:${jauge.toFixed(0)}%"></i></span>` : ''}<span class="lib">${lib}</span>${al ? `<span class="al">${al}</span>` : ''}</button>`; };
    const L = prods.filter(p => opVieDe(p) === k);
    const corps = L.length ? opArbre(D, L, mob, k) : `<div class="op-vvide">Aucune référence en ${OP_VIE_NOM[k].toLowerCase()} ce jour.</div>`;
    const tete = mob ? `<span class="db-mini" style="margin-left:auto">${fN(U.totaux && U.totaux.stock)} en vitrine · <a class="db-lien" href="${lienProd('suivi')}">suivi ›</a></span>`
      : `<span class="db-mini">ce qui est sorti du four face à ce qui est vendu · prévision des ${U.base && U.base.semaines ? U.base.semaines : 6} derniers ${esc(U.jourNom || '')}${/s$/.test(U.jourNom || '') ? '' : 's'}</span><span class="db-mini" style="margin-left:auto">${fN(U.totaux && U.totaux.sorti)} sorties · ${fN(U.totaux && U.totaux.vendu)} vendues · ${fN(U.totaux && U.totaux.stock)} en vitrine · <a class="db-lien" href="${lienProd('suivi')}">suivi de production ›</a></span>`;
    return `<div class="db-card op-bloc"><div class="ct"><span class="op-h2">La vitrine</span>${tete}</div><div class="op-v3s">${OP_VIES.map(onglet).join('')}</div>${corps}</div>`;
  }

  /** Ce que l'onglet Opérationnel lit et calcule : une fois, pour le bureau et pour le téléphone. */
  function opDonnees() {
    const now = opMaintenant(), fin = now == null;
    const U = S.aux[cleOpSuivi()], ST = S.st[cleSt()], JD = S.aux['jourM|' + S.date], m = magasin(JD);
    const CM = S.aux['cmd|' + S.shop], SK = S.aux['stock|' + S.shop];
    const CA = S.aux[cleCanaux()], INV = S.aux[cleInv()], NC = S.aux[cleNC()], PR = S.aux[clePromo()];
    const prods = U && Array.isArray(U.produits) ? U.produits.slice().sort((a, b) => (OP_RANG[a.verdict] ?? 4) - (OP_RANG[b.verdict] ?? 4) || ((b.conseil && b.conseil.pieces) || 0) - ((a.conseil && a.conseil.pieces) || 0)) : [];
    const vides = prods.filter(p => p.verdict === 'rupture'), manques = prods.filter(p => p.verdict === 'manque'), trop = prods.filter(p => p.verdict === 'trop');
    const cuissons = U && Array.isArray(U.cuissons) ? U.cuissons : [];
    const prochaine = now == null ? null : cuissons.filter(c => opMin(c.four) > now).sort((a, b) => opMin(a.four) - opMin(b.four))[0] || null;
    const planning = m && Array.isArray(m.planning) ? m.planning : [];
    const enPoste = now == null ? [] : planning.filter(p => opMin(p.debut) <= now && now < opMin(p.fin));
    const releve = now == null ? null : planning.filter(p => opMin(p.debut) > now).sort((a, b) => opMin(a.debut) - opMin(b.debut))[0] || null;
    const cmd = CM && CM.commandes ? CM.commandes : null;
    const nowStr = AUJ + ' ' + opHM(now == null ? 23 * 60 + 59 : now);
    const lignesC = cmd && Array.isArray(cmd.lignes) ? cmd.lignes : [];
    const retard = lignesC.filter(l => String(l.quand || '') < nowStr && String(l.quand || '').slice(0, 10) < AUJ + 'z');
    const retardN = cmd ? (cmd.retard != null ? cmd.retard : retard.length) : 0;
    // Les tickets du jour d'abord ; tant qu'ils se lisent, le chiffre du Résultat du jour.
    const ca = ST && ST.totaux ? ST.totaux.ca : (m ? m.ca : null), tk = ST && ST.totaux ? ST.totaux.tickets : (m ? m.tickets : null);
    const lienProd = page => `../production/?shop=${encodeURIComponent(S.shop)}&date=${S.date}&page=${page}`;
    const ncL = NC && Array.isArray(NC.nc) ? NC.nc : [];
    const sk = SK && !SK.indispo ? SK : null;

    const listeC = CA && Array.isArray(CA.liste) ? CA.liste : [];
    return { now, fin, U, ST, JD, m, CM, SK, CA, INV, NC, PR, prods, vides, manques, trop, cuissons, prochaine, planning, enPoste, releve, cmd, lignesC, retard, retardN, ca, tk, lienProd, ncL, sk, listeC };
  }
  /** Les six tuiles « maintenant » : l'heure, les ventes, l'objectif, la vitrine, l'équipe, la cuisson. */
  function opTuiles(D) {
    const { now, fin, U, ST, JD, m, CM, SK, CA, INV, NC, PR, prods, vides, manques, trop, cuissons, prochaine, planning, enPoste, releve, cmd, lignesC, retard, retardN, ca, tk, lienProd, ncL, sk, listeC } = D;
    const sansPlanning = m && !planning.length;
    return `
      ${opTuile('heure', esc(fDL(S.date).replace(/ \d{4}$/, '')), fin ? 'Journée' : opHM(now), fin ? 'journée terminée · le récit, pas le direct' : 'en direct · relu toutes les 2 min')}
      ${U ? opTuile(vides.length ? 'ko' : (manques.length ? 'att' : 'ok'), 'Vitrine', fN(U.totaux && U.totaux.stock) + '<small>pièces</small>', `${vides.length} vide${vides.length > 1 ? 's' : ''} · ${manques.length} ${manques.length > 1 ? 'vont' : 'va'} manquer · ${trop.length} en trop`) : opTuile('', 'Vitrine', '—', S.err[cleOpSuivi()] ? esc(S.err[cleOpSuivi()]) : 'lecture du suivi de production…')}
      ${m ? opTuile(sansPlanning ? 'att' : '', 'Équipe en poste', fin ? fN(planning.length) + '<small>au planning</small>' : fN(enPoste.length) + `<small>personne${enPoste.length > 1 ? 's' : ''}</small>`, sansPlanning ? 'pas de planning lu pour ce jour' : (fin ? '' : (enPoste.length ? enPoste.map(p => 'jusqu’à ' + esc(p.fin)).join(' et ') + ' · ' : '') + (releve ? 'relève à ' + esc(releve.debut) + ' · ' : '')) + nf(m.planningHeures || 0, 1) + ' h au planning') : opTuile('', 'Équipe en poste', '—', opSk())}
      ${U ? (prochaine ? opTuile('', 'Prochaine cuisson', esc(prochaine.nom) + `<small>four à ${esc(prochaine.four)}</small>`, `${fN(prochaine.pieces)} pièces pour ${esc(prochaine.de)} à ${esc(prochaine.a)} · ${opDans(opMin(prochaine.four), now)}`) : opTuile('', 'Cuissons', fN(cuissons.length), fin ? cuissons.map(c => esc(c.nom) + ' ' + esc(c.four)).join(' · ') : 'plus de cuisson prévue aujourd’hui')) : opTuile('', 'Prochaine cuisson', '—', opSk())}
`;
  }
  /* ── Le duel avec J−7 (maquette B du 08/10/2026) ──────────────────────────────
   * Chaque chiffre du jour se compare d'abord à J−7 à la même heure (le serveur arrête la
   * journée d'il y a sept jours à l'heure qu'il est), puis à sa journée entière. */
  const fSP = n => n == null ? '—' : (n >= 0 ? '+ ' : '− ') + fP(Math.abs(n));
  const fPts = n => n == null ? '—' : (n >= 0 ? '+ ' : '− ') + nf(Math.abs(n), 1) + ' pts';
  const sensDe = (d, inv) => d == null ? 'eq' : (Math.abs(d) < 1 ? 'eq' : ((d > 0) !== !!inv ? 'ok' : 'ko'));
  function opDuelBase(D) {
    const { now, fin, m, ca, tk } = D;
    const j7 = m && m.j7 ? m.j7 : null;
    const obj = m && m.objectifJour ? m.objectifJour : null;
    const proj = m && !fin && m.projection != null && m.projectionPart != null && m.projectionPart < 100 ? m.projection : null;
    const dCa = j7 && j7.ca && ca != null ? 100 * (ca - j7.ca) / j7.ca : null;
    const dTk = j7 && j7.tickets && tk != null ? 100 * (tk - j7.tickets) / j7.tickets : null;
    const panA = tk ? ca / tk : null, pan7 = j7 && j7.tickets ? j7.ca / j7.tickets : null;
    const dPan = panA != null && pan7 ? 100 * (panA - pan7) / pan7 : null;
    const dProj = proj != null && j7 && j7.caJour ? 100 * (proj - j7.caJour) / j7.caJour : null;
    const mb7 = j7 && j7.mb != null && j7.ca ? j7.mb : null, mb7Pct = mb7 != null ? 100 * mb7 / j7.ca : null;
    const mbPct = m && m.margeBrutePct != null ? m.margeBrutePct : (m && m.ca && m.margeBrute != null ? 100 * m.margeBrute / m.ca : null);
    const dMb = mbPct != null && mb7Pct != null ? mbPct - mb7Pct : null;
    const jourNom = JOURS_L ? JOURS_L[new Date(S.date + 'T12:00:00').getDay()] : 'J−7';
    const lblB = j7 ? (j7.moment ? jourNom + ' dernier à ' + j7.moment : jourNom + ' dernier') : 'J−7';
    return { j7, obj, proj, dCa, dTk, dPan, dProj, panA, pan7, mb7, mb7Pct, mbPct, dMb, jourNom, lblB, moment: j7 && j7.moment ? j7.moment : null };
  }
  const JOURS_L = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
  /** La carte du duel : deux pistes sur la même échelle (l'objectif), le curseur du temps, le verdict. */
  function opDuel(D) {
    const { now, fin, JD, m, ca, tk } = D;
    const estAuj = !!(JD && JD.estAujourdhui);
    if (!m) { return `<div class="op-duel"><div class="dh"><span class="lab">Objectif du jour</span><span class="dr">${S.err['jourM|' + S.date] ? esc(S.err['jourM|' + S.date]) : 'lecture du Résultat du jour…'}</span></div>${opSk()}</div>`; }
    const B = opDuelBase(D), j7 = B.j7, obj = B.obj;
    const echelle = obj || Math.max(ca || 0, j7 ? j7.caJour || 0 : 0, B.proj || 0, 1);
    const pct = v => Math.max(0, Math.min(100, 100 * (v || 0) / echelle));
    const atteint = obj && ca != null && ca >= obj;
    const part = m.projectionPart != null ? Math.max(0, Math.min(100, m.projectionPart)) : null;
    // Les étiquettes : à droite de leur ancre quand la place le permet, sinon à l'intérieur du segment (à gauche de
    // l'ancre) ; deux étiquettes qui se chevaucheraient fusionnent. Au téléphone, la seconde passe sous la barre (css).
    const tel = innerWidth <= 700 || document.documentElement.classList.contains('mob');
    const barPx = Math.max(240, tel ? innerWidth - 60 : innerWidth - 250);
    const larg = t => (t.replace(/<[^>]+>/g, '').length * (tel ? 5.8 : 6.6) + 18) * 100 / barPx;
    const pill = (e, ded) => `<span class="val${e.cls ? ' ' + e.cls : ''}${ded ? ' dedans' : ''}${tel && e.cls && e.p < larg(e.txt) ? ' g' : ''}" style="left:${e.p.toFixed(1)}%"${e.titre ? ` title="${e.titre}"` : ''}>${e.txt}</span>`;
    const etiquettes = L => {
      if (!L.length) { return ''; }
      const a = L[0], wa = larg(a.txt);
      if (L.length === 1) { return pill(a, a.p + wa > 100 && a.p - wa >= 0); }
      const b = L[1], wb = larg(b.txt);
      if (tel) { return pill(a, a.p + wa > 100 && a.p - wa >= 0) + pill(b, false); }
      const bDed = b.p + wb > 100, limite = bDed ? b.p - wb : b.p;
      const aDed = a.p + wa > limite - 1 && a.p - wa >= 0;
      if (aDed && bDed && b.p - wb < a.p) { return pill({ p: b.p, txt: a.txt + ' → ' + b.txt, cls: b.cls, titre: b.titre }, true); }
      return pill(a, aDed) + pill(b, bDed);
    };
    const piste = (lb, sous, segs, L) => `<div class="op-piste"><div class="pl"><b>${lb}</b><small>${sous}</small></div><div class="pb">${segs}${part != null && !fin ? `<i class="temps" style="left:${part.toFixed(1)}%" title="${fP0(part)} de la journée écoulée"></i>` : ''}${etiquettes(L)}</div></div>`;
    let h = `<div class="op-duel"><div class="dh"><span class="lab">Objectif du ${esc(B.jourNom)}${obj ? `<b>${fE(obj)}</b>` : ''}</span>${obj ? `<span class="chip">${m.objectifSource === 'budget' ? 'budget du mois' : 'CA théorique'} · profil des ${esc(B.jourNom)}s</span>` : '<span class="chip">pas d’objectif du jour : l’échelle, c’est le meilleur des deux</span>'}${atteint ? '<span class="chip" style="background:#f7edc8;color:#7d6310">🏆 objectif atteint</span>' : ''}<span class="dr">${fin ? 'journée terminée' : opHM(now) + (part != null ? ' · ' + fP0(part) + ' de la journée écoulée' : '')}</span></div>`;
    const pA = pct(ca), pP = B.proj != null ? pct(B.proj) : null;
    const LA = [];
    if (ca != null) { LA.push({ p: pA, txt: fE(ca) + (obj ? ' · ' + fP0(100 * ca / obj) : ''), cls: '' }); }
    if (pP != null && pP > pA) { LA.push({ p: pP, txt: 'projection ' + fE(B.proj), cls: 'proj', titre: 'au rythme de la journée, ' + fE(B.proj) + ' ce soir' }); }
    h += piste(estAuj ? 'Aujourd’hui' : 'Cette journée', fin ? (estAuj ? 'journée terminée' : fD(S.date)) : 'à ' + opHM(now),
      `<div class="seg ${atteint ? 'or' : 'fait'}" style="width:${pA.toFixed(1)}%"></div>${pP != null && pP > pA ? `<div class="seg proj" style="left:${pA.toFixed(1)}%;width:${(pP - pA).toFixed(1)}%" title="projection au rythme de la journée : ${fE(B.proj)}"></div>` : ''}`, LA);
    if (j7) {
      const p7 = pct(j7.ca), p7j = j7.moment ? pct(j7.caJour) : p7;
      const L7 = [{ p: p7, txt: fE(j7.ca) + (j7.moment ? ' à ' + esc(j7.moment) : (obj ? ' · ' + fP0(100 * j7.ca / obj) : '')), cls: '' }];
      if (j7.moment) { L7.push({ p: p7j, txt: 'journée ' + fE(j7.caJour) + (obj ? ' · ' + fP0(100 * j7.caJour / obj) : ''), cls: 'fin' }); }
      h += piste(B.jourNom.charAt(0).toUpperCase() + B.jourNom.slice(1) + ' dernier', fD(j7.date),
        `<div class="seg j7" style="width:${p7.toFixed(1)}%"></div>${j7.moment && p7j > p7 ? `<div class="seg j7j" style="left:${p7.toFixed(1)}%;width:${(p7j - p7).toFixed(1)}%"></div>` : ''}`, L7);
    } else {
      h += `<div class="op-piste"><div class="pl"><b>${esc(B.jourNom.charAt(0).toUpperCase() + B.jourNom.slice(1))} dernier</b><small>pas de ventes gravées</small></div><div class="pb"></div></div>`;
    }
    h += `<div class="op-dax"><span>0 €</span><span>${fE(echelle / 2)}</span><span>${obj ? 'objectif ' + fE(obj) : fE(echelle)}</span></div>`;
    // La ligne « face à samedi dernier · clients · panier · au rythme de la journée » est retirée (10/10/2026).
    return h + '</div>';
  }
  /** Le cumul heure par heure d'aujourd'hui face à celui de J−7 : le CA jusqu'à l'objectif, ou les clients
   *  face à la référence des mêmes jours (bascule CA / Clients, demande du 08/10/2026). */
  function opDuelHeures(D) {
    const { now, fin, ST, m } = D;
    const B = opDuelBase(D), j7 = B.j7;
    const hA = ST && Array.isArray(ST.heures) && ST.heures.length ? ST.heures : (m && Array.isArray(m.heures) ? m.heures : []);
    const h7 = j7 && Array.isArray(j7.heures) ? j7.heures : [];
    if (!hA.length || !h7.length) { return ''; }
    // Les clients ne se dessinent que si les deux journées ont leurs tickets heure par heure.
    const aTk = hA.some(x => x.tickets != null) && h7.some(x => x.tickets != null);
    const mode = aTk && S.opCrb === 'tk' ? 'tk' : 'ca', champ = mode === 'tk' ? 'tickets' : 'ca';
    const fmtV = v => mode === 'tk' ? fN(Math.round(v)) + ' clients' : fE(v);
    const cible = mode === 'tk' ? (m && m.refTickets ? m.refTickets : null) : B.obj;
    const cibleLib = cible == null ? '' : (mode === 'tk' ? 'référence ' + fN(Math.round(cible)) + ' clients' : 'objectif ' + fE(cible));
    const hNow = now == null ? 24 : Math.floor(now / 60), frac = now == null ? 0 : (now % 60) / 60;
    const cumul = (hs, limite) => { let c = 0; const out = []; hs.slice().sort((a, b) => a.h - b.h).forEach(x => { if (limite != null && x.h > limite) { return; } c += +x[champ] || 0; out.push({ h: x.h, v: c }); }); return out; };
    const cA = cumul(hA, fin ? null : hNow), c7 = cumul(h7, null);
    if (!cA.length || !c7.length) { return ''; }
    const h0 = Math.min(6, ...cA.map(x => x.h), ...c7.map(x => x.h)), h1 = Math.max(19, ...cA.map(x => x.h + 1), ...c7.map(x => x.h + 1));
    const mx = Math.max(cible || 0, cA[cA.length - 1].v, c7[c7.length - 1].v, 1) * (cible && cible >= Math.max(cA[cA.length - 1].v, c7[c7.length - 1].v) ? 1 : 1.08);
    const axe = v => mode === 'tk' ? fN(Math.round(v)) : fN(Math.round(v / 10) * 10);
    const dessine = (W, Hh, pas, cls) => {
      const tel = cls === 'tel';
      const X0 = 44, X1 = W - 16, Y0 = 14, Y1 = Hh - 26;
      const x = hh => X0 + (X1 - X0) * (hh - h0) / (h1 - h0), y = v => Y1 - (Y1 - Y0) * v / mx;
      const chemin = c => 'M' + x(h0).toFixed(1) + ' ' + y(0).toFixed(1) + ' ' + c.map(p => 'L' + x(p.h + 1).toFixed(1) + ' ' + y(p.v).toFixed(1)).join(' ');
      const lastA = cA[cA.length - 1], last7 = c7[c7.length - 1];
      let g = `<svg class="op-crb ${cls}" viewBox="0 0 ${W} ${Hh}">`;
      [0.25, 0.5, 0.75, 1].forEach(f => { g += `<line x1="${X0}" x2="${X1}" y1="${y(mx * f).toFixed(1)}" y2="${y(mx * f).toFixed(1)}" class="gr"/><text x="${X0 - 6}" y="${(y(mx * f) + 3).toFixed(1)}" text-anchor="end" class="ax">${axe(mx * f)}</text>`; });
      if (cible) { g += `<line x1="${X0}" x2="${X1}" y1="${y(cible).toFixed(1)}" y2="${y(cible).toFixed(1)}" class="cib"/><text x="${X0 + 4}" y="${(y(cible) - 4).toFixed(1)}" class="obj">${cibleLib}</text>`; }
      for (let hh = h0; hh <= h1; hh += pas) { g += `<text x="${x(hh).toFixed(1)}" y="${Hh - 8}" text-anchor="middle" class="ax">${hh} h</text>`; }
      g += `<path d="${chemin(c7)}" class="l7"/><path d="${chemin(cA)}" class="la"/>`;
      // L'heure qu'il est : en haut de son trait, sauf quand l'étiquette de fin de J−7 est à côté (fin de journée),
      // alors en bas ; au téléphone, toujours en bas et les valeurs de fin passent dans la légende.
      if (!fin && now != null) { const v7now = (c7.find(q => q.h === hNow) || last7).v; const bas = tel || hNow + frac > h1 - 2.5 || lastA.v / mx > 0.8 || v7now / mx > 0.8; g += `<line x1="${x(hNow + frac).toFixed(1)}" x2="${x(hNow + frac).toFixed(1)}" y1="${Y0}" y2="${Y1}" class="now"/><text x="${(x(hNow + frac) + 5).toFixed(1)}" y="${bas ? Y1 - 6 : Y0 + 12}" class="nowt">${opHM(now)}</text>`; }
      g += `<circle cx="${x(lastA.h + 1).toFixed(1)}" cy="${y(lastA.v).toFixed(1)}" r="4" class="pa"/>${tel ? '' : `<text x="${(x(lastA.h + 1) - 8).toFixed(1)}" y="${(y(lastA.v) + 18).toFixed(1)}" text-anchor="end" class="ta">${fin ? 'cette journée' : 'aujourd’hui'} ${fmtV(lastA.v)}</text>`}`;
      g += `<circle cx="${x(last7.h + 1).toFixed(1)}" cy="${y(last7.v).toFixed(1)}" r="4" class="p7"/>${tel ? '' : `<text x="${(x(last7.h + 1) - 8).toFixed(1)}" y="${(y(last7.v) - 10).toFixed(1)}" text-anchor="end" class="t7">${esc(B.jourNom)} dernier ${fmtV(last7.v)}</text>`}`;
      return g + '</svg>';
    };
    const valA = fmtV(cA[cA.length - 1].v), val7 = fmtV(c7[c7.length - 1].v);
    const bascule = aTk ? `<span class="op-crbtog" role="tablist"><button type="button" class="${mode === 'ca' ? 'on' : ''}" data-opcrb="ca">CA</button><button type="button" class="${mode === 'tk' ? 'on' : ''}" data-opcrb="tk">Clients</button></span>` : '';
    const sous = mode === 'tk'
      ? `le cumul des clients ${fin ? 'de cette journée' : 'd’aujourd’hui'} face à celui de ${esc(B.jourNom)} dernier${cible ? ', la référence des mêmes jours en pointillé' : ''}`
      : `le cumul ${fin ? 'de cette journée' : 'd’aujourd’hui'} face à celui de ${esc(B.jourNom)} dernier${cible ? ', jusqu’à l’objectif' : ''}`;
    return `<div class="db-card op-bloc"><div class="ct"><span class="op-h2">Heure par heure</span>${bascule}<span class="db-mini">${sous}</span><span class="db-mini" style="margin-left:auto">${mode === 'tk' ? 'tickets encaissés, pro compris' : 'ventes encaissées, pro compris'}</span></div>
      <div class="op-crb-w">${dessine(1100, 220, 1, '')}${dessine(380, 230, 2, 'tel')}</div>
      <div class="op-dleg"><span><i class="la"></i>${fin ? 'cette journée' : 'aujourd’hui'}<b class="tv"> ${valA}</b></span><span><i class="l7"></i>${esc(B.jourNom)} dernier (${fD(j7.date)})<b class="tv"> ${val7}</b></span>${!fin && now != null ? '<span><i class="now"></i>maintenant</span>' : ''}</div></div>`;
  }
  /** Le duel, chiffre par chiffre : aujourd'hui, J−7 à la même heure, l'écart, la journée de J−7, la référence. */
  function opDuelTable(D) {
    const { now, fin, JD, m, ca, tk } = D;
    if (!m) { return ''; }
    const B = opDuelBase(D), j7 = B.j7;
    const lblA = fin ? 'cette journée' : 'aujourd’hui · ' + opHM(now);
    const lblJ = B.jourNom + ' dernier · journée';
    const eq = '<span class="eq">—</span>';
    const ecart = (d, inv, suf, fmt) => d == null ? eq : `<span class="${sensDe(d, inv)}">${(fmt || fSP)(d)}${suf || ''}</span>`;
    const cell = (cls, lab, v, sous) => `<div class="${cls}"><span class="lab">${lab}</span>${v}${sous ? `<small>${sous}</small>` : ''}</div>`;
    const ligne = (k, note, a, b, d, j, r) => `<div class="op-dl"><div class="k">${k}${note ? `<small>${note}</small>` : ''}</div>${cell('a', lblA, a[0], a[1])}${cell('b', esc(B.lblB), b[0], b[1])}<div class="d">${d}</div>${cell('j', lblJ, j[0], j[1])}${cell('r', 'référence', r[0], r[1])}</div>`;
    const ref = JD && JD.reference ? JD.reference : {};
    const sansJ = !j7 || !j7.moment;
    let h = `<div class="db-card op-bloc"><div class="ct"><span class="op-h2">Le duel, chiffre par chiffre</span><span class="db-mini">${sansJ ? 'face à la journée entière de la semaine passée' : 'à la même heure, puis la journée entière de la semaine passée'}</span></div><div class="op-dt${sansJ ? ' sans-j' : ''}">`;
    h += ligne('Chiffre d’affaires', '', [fE(ca), m.objectifJour ? fP0(100 * (ca || 0) / m.objectifJour) + ' de l’objectif' : ''], [j7 ? fE(j7.ca) : eq, ''], ecart(B.dCa), [j7 && j7.moment ? fE(j7.caJour) : eq, j7 && j7.moment && m.objectifJour ? fP0(100 * j7.caJour / m.objectifJour) + ' de l’objectif' : ''], [m.refCa != null ? fE(m.refCa) : eq, esc(ref.libelle || '')]);
    h += ligne('Clients', '', [fN(tk), ''], [j7 ? fN(j7.tickets) : eq, ''], ecart(B.dTk), [j7 && j7.moment ? fN(j7.ticketsJour) : eq, ''], [m.refTickets != null ? fN(m.refTickets) : eq, m.ticketsDelta != null ? fSP(m.ticketsDelta) + ' face à la référence' : '']);
    h += ligne('Panier moyen', '', [fU(B.panA), m.produitsParClient ? nf(m.produitsParClient, 2) + ' produits / client' : ''], [B.pan7 != null ? fU(B.pan7) : eq, ''], ecart(B.dPan), [j7 && j7.moment && j7.ticketsJour ? fU(j7.caJour / j7.ticketsJour) : eq, ''], [JD && JD.reseau && JD.reseau.panier ? fU(JD.reseau.panier) : eq, 'réseau ' + (fin ? 'ce jour-là' : 'aujourd’hui')]);
    // Marge brute, projection et résultat net retirés du duel (10/10/2026) : ils restent dans le P&L court.
    return h + '</div></div>';
  }

  /** Les ventes au comptoir de chaque heure face à la moyenne des 6 derniers mêmes jours. */
  function opHeures(D) {
    const { now, U, prods } = D;
    // Les heures : la moyenne des 6 derniers mêmes jours (pièces × prix, au comptoir) face au vendu au comptoir.
    const moy = {}, vend = {};
    prods.forEach(p => { const px = +p.prix || 0; Object.entries(p.moy || {}).forEach(([hh, v]) => { moy[hh] = (moy[hh] || 0) + (+v || 0) * px; }); Object.entries(p.vc || {}).forEach(([hh, v]) => { if (v != null) { vend[hh] = (vend[hh] || 0) + (+v || 0) * px; } }); });
    const HS = Object.keys(moy).map(Number).sort((a, b) => a - b);
    let heures = '';
    if (HS.length) {
      const W = 520, Ht = 110, bw = W / HS.length, mx = Math.max(1, ...HS.map(hh => Math.max(moy[hh] || 0, vend[hh] || 0))) * 1.15, y = v => Ht - Ht * v / mx;
      heures = `<div class="op-h4">Ventes au comptoir, heure par heure · face aux ${U.base && U.base.semaines ? U.base.semaines : 6} derniers ${esc(U.jourNom || 'mêmes jours')}${/s$/.test(U.jourNom || '') ? '' : 's'}</div><svg width="100%" viewBox="0 0 ${W} ${Ht + 18}" class="op-svg">${HS.map((hh, i) => {
        const mo = moy[hh] || 0, v = vend[hh], X = i * bw, enCours = now != null && hh * 60 <= now && now < hh * 60 + 60;
        const c = enCours ? '#8D1D2C' : (v != null && v >= mo ? '#2D7A3E' : '#D97706');
        return `<rect x="${(X + bw * .12).toFixed(1)}" y="${y(mo).toFixed(1)}" width="${(bw * .36).toFixed(1)}" height="${(Ht - y(mo)).toFixed(1)}" rx="2" fill="#e3dbcf"/><text x="${(X + bw * .3).toFixed(1)}" y="${(y(mo) - 3).toFixed(1)}" font-size="9" text-anchor="middle" fill="#888">${fN(mo)}</text>`
          + (v != null && (now == null || hh * 60 <= now) ? `<rect x="${(X + bw * .52).toFixed(1)}" y="${y(v).toFixed(1)}" width="${(bw * .36).toFixed(1)}" height="${(Ht - y(v)).toFixed(1)}" rx="2" fill="${c}"/><text x="${(X + bw * .7).toFixed(1)}" y="${(y(v) - 3).toFixed(1)}" font-size="9.5" font-weight="700" text-anchor="middle" fill="${c}">${fN(v)}</text>` : '')
          + `<text x="${(X + bw / 2).toFixed(1)}" y="${Ht + 13}" font-size="10" text-anchor="middle" fill="#666">${hh} h</text>`;
      }).join('')}</svg><div class="op-leg"><span><i style="background:#e3dbcf"></i>moyenne</span><span><i style="background:#D97706"></i>vendu, sous la moyenne</span><span><i style="background:#2D7A3E"></i>au-dessus</span>${now != null ? '<span><i style="background:#8D1D2C"></i>heure en cours</span>' : ''}<span class="mu">en euros, au prix de vente, hors commandes</span></div>`;
    } else { heures = U ? '' : `<div style="padding:8px 0">${opSk()}</div>`; }
    return heures;
  }
  /** En un coup d'œil : commandes clients, non-conformités d'hier, poubelle, promotions. */
  function opMinis(D) {
    const { now, fin, U, ST, JD, m, CM, SK, CA, INV, NC, PR, prods, vides, manques, trop, cuissons, prochaine, planning, enPoste, releve, cmd, lignesC, retard, retardN, ca, tk, lienProd, ncL, sk, listeC } = D;
    const mini = (vd, k, v, s) => `<div class="db-card op-mini"><div class="k"><span class="vd ${vd}"></span>${k}</div><div class="v">${v}</div><div class="s">${s}</div></div>`;
    return `
      ${cmd ? mini(retardN ? 'att' : 'ok', 'Commandes clients', retardN ? fN(retardN) + ' en retard' : 'À jour', `${fN(cmd.aVenir || 0)} à venir · aujourd’hui : ${fN(listeC.length)}${CA && CA.demain ? ' · demain : ' + fN(CA.demain.n) + (CA.demain.n ? ', ' + fU(CA.demain.ca) : '') : ''}`) : mini('', 'Commandes clients', '—', opSk())}
      ${NC && !NC.indispo ? mini(ncL.length ? 'ko' : 'ok', 'Hier', ncL.length ? fN(ncL.length) + ' non-conformité' + (ncL.length > 1 ? 's' : '') : 'Aucune non-conformité', `${fN(NC.notees || 0)} photo${(NC.notees || 0) > 1 ? 's' : ''} notée${(NC.notees || 0) > 1 ? 's' : ''} le ${esc(fDL(veille()).replace(/ \d{4}$/, ''))}`) : mini('', 'Hier', '—', NC ? 'panel muet' : opSk())}
      ${INV ? mini(INV.declare ? (INV.cout > 0 ? 'att' : 'ok') : '', 'Poubelle', INV.declare ? fE(INV.cout) : 'Pas encore déclarée', INV.declare ? fN(INV.pieces) + ' pièces · ' + fU(INV.caPerdu) + ' de vente perdue' : 'à encoder à la fermeture') : mini('', 'Poubelle', '—', opSk())}
      ${PR ? mini('', 'Promotions', (PR.promos || []).length ? fN(PR.promos.length) + ' en cours' : 'Aucune', (PR.promos || []).length ? PR.promos.map(p => esc(p.nom || p.titre || '')).filter(Boolean).slice(0, 2).join(' · ') : 'pas de promotion posée pour aujourd’hui') : mini('', 'Promotions', '—', opSk())}
`;
  }
  /** L'onglet Opérationnel au téléphone (06/10/2026) : les mêmes chiffres que le bureau, en une
   * colonne. La vitrine en liste courte, l'équipe et les cuissons en lignes, les photos du jour,
   * le P&L et les catégories, puis commandes, hier, poubelle, promotions et le stock. */
  function rendOpsMobile() {
    const D = opDonnees();
    const { now, U, ST, JD, m, prods, cuissons, planning, lienProd } = D;
    let h = opDuel(D) + `<div class="op-mnt quatre">${opTuiles(D)}</div>` + opDuelTable(D) + opDuelHeures(D);
    // La vitrine : ce qui demande un geste, puis le reste replié.
    if (U) {
      h += opVitrine(D, true);
    } else { h += `<div class="db-card op-bloc"><div class="ct"><span class="op-h2">La vitrine</span><span class="db-mini">${S.err[cleOpSuivi()] ? esc(S.err[cleOpSuivi()]) : 'lecture du suivi de production…'}</span></div><div style="padding:10px 14px">${opSk()}</div></div>`; }
    // Les heures, puis l'équipe et les cuissons en lignes.
    const heures = opHeures(D);
    if (heures) { h += `<div class="db-card op-bloc"><div style="padding:10px 12px">${heures}</div></div>`; }
    const lg = (cls, a, b, c) => `<div class="op-jl${cls ? ' ' + cls : ''}"><span class="a">${a}</span><span class="b">${b}</span><span class="c">${c}</span></div>`;
    h += `<div class="db-card op-bloc"><div class="ct"><span class="op-h2">La journée</span>${m && m.planningHeures ? `<span class="db-mini" style="margin-left:auto">${nf(m.planningHeures, 1)} h au planning</span>` : ''}</div><div class="op-jour">
      ${planning.length ? planning.map(p => { const ici = now != null && opMin(p.debut) <= now && now < opMin(p.fin); return lg(ici ? 'ici' : (now != null && opMin(p.fin) <= now ? 'fini' : ''), esc(p.debut) + '–' + esc(p.fin), esc(p.nom || (p.postes || []).join(', ')), ici ? 'en poste' : (now != null && opMin(p.debut) > now ? 'arrive' : '')); }).join('') : `<div class="op-jl"><span class="b mu">${m ? 'pas de planning lu' : 'lecture…'}</span></div>`}
      ${cuissons.map(c => lg(now != null && opMin(c.four) > now ? 'av' : '', 'four ' + esc(c.four), esc(c.nom) + ' · ' + fN(c.pieces) + ' pièces, ' + esc(c.de) + ' à ' + esc(c.a), c.valide ? 'validée' : (now != null && opMin(c.four) > now ? opDans(opMin(c.four), now) : 'à valider'))).join('')}
    </div></div>`;
    // Les photos du jour : le carrousel du téléphone.
    h += `<div id="op-cq">${rendCQ(true)}</div>`;
    // Le P&L court et les ventes par catégorie, comme au bureau.
    const PJ = m ? jourPieces(m, JD, ST) : null;
    if (PJ) { h += `<div class="op-large">${PJ.pnl}</div><div class="op-large">${PJ.categories}</div>`; }
    h += bundlesCarte();
    h += `<div class="op-mini4">${opMinis(D)}</div>`;
    h += `<div class="op-stock">${S.aux['stock|' + S.shop] ? rendStock() : ''}</div>`;
    h += `<div class="op-renvoi">Le chiffre, la marge et le résultat de la journée : l’onglet <button type="button" class="db-lien" data-mo="exp">Exploitation ›</button></div>`;
    return h;
  }
  function rendOps() {
    const D = opDonnees();
    const { now, fin, U, ST, JD, m, CM, SK, CA, INV, NC, PR, prods, vides, manques, trop, cuissons, prochaine, planning, enPoste, releve, cmd, lignesC, retard, retardN, ca, tk, lienProd, ncL, sk, listeC } = D;
    let h = '';

    /* 1. Le duel avec J−7 (maquette B du 08/10/2026) : la jauge à deux pistes, les quatre tuiles
     * du moment, le cumul heure par heure, le tableau chiffre par chiffre. */
    h += opDuel(D);
    h += `<div class="op-mnt quatre">${opTuiles(D)}</div>`;
    h += opDuelHeures(D);
    h += opDuelTable(D);

    /* 2. Les ventes par catégorie (liste ou treemap) et le P&L court de la journée, coût du
     * personnel compris : les cartes de la vue Jour, telles quelles. */
    const PJ = m ? jourPieces(m, JD, ST) : null;
    const attente = t => `<div class="db-card"><div class="ct"><span class="db-lab">${t}</span><span class="db-mini">${S.err['jourM|' + S.date] ? esc(S.err['jourM|' + S.date]) : 'lecture du Résultat du jour…'}</span></div><div style="padding:12px 16px">${opSk()}${opSk()}</div></div>`;
    h += `<div class="op-deux"><div>${PJ ? PJ.categories : attente('Ventes par catégorie')}</div><div>${bundlesCarte()}${PJ ? PJ.pnl : attente('Le P&amp;L court de la journée')}</div></div>`;

    /* La journée : la frise de 04:00 à 20:00 et les ventes de chaque heure face à la moyenne */
    const H0 = 4 * 60, H1 = 20 * 60, x = mm => (100 * (Math.max(H0, Math.min(H1, mm)) - H0) / (H1 - H0)).toFixed(2) + '%', w = (a, b) => (100 * (Math.min(H1, b) - Math.max(H0, a)) / (H1 - H0)).toFixed(2) + '%';
    const trait = now == null ? '' : `<span class="op-now" style="left:${x(now)}"></span>`;
    const ligneF = (lb, haut, contenu) => `<div class="op-fl" style="height:${haut + 8}px"><span class="lb">${lb}</span><div class="pis" style="height:${haut}px">${contenu}${trait}</div></div>`;
    let frise = '';
    frise += ligneF('Équipe' + (m && m.planningHeures ? `<small>${nf(m.planningHeures, 1)} h</small>` : ''), Math.max(22, 4 + 15 * planning.length), planning.length ? planning.map((p, i) => `<b class="eq${now != null && !(opMin(p.debut) <= now && now < opMin(p.fin)) ? ' hors' : ''}" style="left:${x(opMin(p.debut))};width:${w(opMin(p.debut), opMin(p.fin))};top:${3 + 15 * i}px">${esc(p.debut)}–${esc(p.fin)} · ${esc(p.nom || (p.postes || []).join(', '))}</b>`).join('') : `<span class="vide">${m ? 'pas de planning lu' : 'lecture…'}</span>`);
    frise += ligneF('Four', 22, cuissons.map(c => `<b class="fo${now != null && opMin(c.four) > now ? ' av' : ''}" style="left:${x(opMin(c.four))};width:${w(opMin(c.four), opMin(c.four) + 45)}"></b><span class="lbl" style="left:calc(${x(opMin(c.four) + 50)})">${esc(c.nom)} ${esc(c.four)}${c.valide || (now != null && opMin(c.four) > now) ? '' : ' · à valider'}</span>`).join(''));
    frise += ligneF('Vitrine', 22, cuissons.map(c => `<b class="vi" style="left:${x(opMin(c.de))};width:${w(opMin(c.de), opMin(c.a))}">${fN(c.pieces)} p.</b>`).join(''));
    frise += ligneF('Commandes', 22, listeC.map(l => `<b class="cmd" style="left:${x(opMin(l.heure))}" title="${esc(l.heure)} · ${esc(l.statut || '')}"></b>`).join('') + `<span class="lbl" style="right:8px;color:#777">${listeC.length ? listeC.length + ' commande' + (listeC.length > 1 ? 's' : '') + ' aujourd’hui · ' + listeC.filter(l => /remis|retir/i.test(l.statut || '')).length + ' retirée' + (listeC.filter(l => /remis|retir/i.test(l.statut || '')).length > 1 ? 's' : '') : (CA ? 'pas de commande aujourd’hui' : 'lecture…')}</span>`);
    frise += `<div class="op-ax"><span></span><div class="ax">${[4, 6, 8, 10, 12, 14, 16, 18, 20].map(hh => `<span style="left:${x(hh * 60)}">${hh} h</span>`).join('')}</div></div>`;
    const heures = opHeures(D);
    h += `<div class="db-card op-bloc"><div class="ct"><span class="op-h2">La journée</span><span class="db-mini">de 04:00 à 20:00${now != null ? ' · le trait rouge : maintenant' : ''}</span></div><div class="op-jr"><div class="op-frise">${frise}</div><div class="op-hr">${heures}</div></div></div>`;

    /* 3. La vitrine, selon la durée de vie */
    if (U) {
      h += opVitrine(D, false);
    } else if (S.err[cleOpSuivi()]) { h += `<div class="db-err">Suivi de production : ${esc(S.err[cleOpSuivi()])}</div>`; }
    else { h += `<div class="db-card op-bloc"><div class="ct"><span class="op-h2">La vitrine</span><span class="db-mini">lecture du suivi de production…</span></div><div style="padding:12px 16px">${opSk()}${opSk()}</div></div>`; }

    /* 4. Les contrôles en photo : le carrousel de la vue Jour */
    h += `<div id="op-cq">${rendCQ(false)}</div>`;

    /* 5. En un coup d'œil */
    h += `<div class="op-mini4">${opMinis(D)}</div>`;
    // Le stock du magasin : la barre de la vue Semaine, sa liste en liste déroulante.
    h += `<div class="op-stock">${S.aux['stock|' + S.shop] ? rendStock() : `<div class="db-stbar mu"><span class="t">Stock<small>lecture de l’inventaire…</small></span></div>`}</div>`;
    return h;
  }

  function rendre() {
    const kr = cleRes(), ks = cleSt();
    const d = S.res[kr], st = S.st[ks];
    const m = magasin(d);
    const garde = noteGarder(), cqPos = cqGarder();
    if (estMobile()) {
      // Le mois, le trimestre et l'année n'existent pas au téléphone : on
      // retombe sur le jour plutôt que d'afficher un écran vide.
      // La production se pilote sur ordinateur (demande du 03/10/2026) : pas d'écran au téléphone.
      if (!['ops', 'jour', 'semaine', 'reclamation'].includes(S.vue)) { S.vue = 'jour'; urlMaj(); charger(false); }
      if (S.vue === 'actions' || S.vue === 'campagne') { $.innerHTML = rendActions(true); $.classList.add('mob'); brancher(); monterActions(); cqRestaurer(null); return; }
      if (S.vue === 'reclamation') {
        const champ = rcGarder();
        $.innerHTML = rendReclamation(); $.classList.add('mob'); brancher();
        cqRestaurer(cqPos); rcRestaurer(champ);
        // Après un envoi, on remonte : le bandeau « Envoyée » est en haut.
        if (S.rcHaut) { S.rcHaut = false; const sc = $.querySelector('.mb-sc'); if (sc) { sc.scrollTop = 0; } }
        return;
      }
      $.innerHTML = rendMobile(m, d) + cqLoupe(true);
      $.classList.add('mob');
      brancher();
      noteRestaurer(garde);
      cqRestaurer(cqPos);
      // La fête attend que le jour soit lu : lancée sur un mur encore vide,
      // elle serait finie avant que le premier chiffre s'affiche.
      const forcee = !!m && feteDemandee();
      if (forcee || (objectifAtteint(m) && (S.vue !== 'jour' || S.mo === 'exp'))) { feteObjectif(forcee); }
      return;
    }
    // La réclamation fournisseur n'existe qu'au téléphone.
    // Sur ordinateur, plus d'onglet Jour (10/10/2026) : Opérationnel montre toute la journée, en plus complet.
    if (S.vue === 'reclamation' || S.vue === 'jour') { S.vue = 'ops'; urlMaj(); charger(false); return; }
    if (EMBED) { const g = ppGarder(); $.classList.remove('mob'); document.body.classList.add('pp-emb'); $.innerHTML = rendProduction(); brancher(); ppRestaurer(g); return; }
    $.classList.remove('mob');
    let h = '';
    h += `<div class="db-hd"><img src="../assets/img/logo.png" alt=""><div><div class="db-titre">${esc(nomShop())}</div></div>
      <span style="flex:1"></span><a class="db-lien" href="../#/resultat">Cockpit › Résultat ›</a></div>`;
    h += `<div class="db-nav">
      <div class="db-ong">${[['ops', 'Opérationnel'], ['semaine', 'Semaine'], ['mois', 'Mois'], ['trimestre', 'Trimestre'], ['annee', 'Année']].map(o => `<button data-vue="${o[0]}" class="${S.vue === o[0] ? 'on' : ''}">${o[1]}</button>`).join('')}</div>
      ${['ops', 'semaine', 'mois', 'trimestre'].includes(S.vue) ? '' : `<span class="db-lab">${S.vue === 'jour' || S.vue === 'production' ? 'Date' : 'Année de'}</span>`}${false ? `<div class="db-ong">${[1, 2, 3, 4].map(q => { const deb = annee() + '-' + String((q - 1) * 3 + 1).padStart(2, '0') + '-01'; const auj = q === Math.floor((+AUJ.slice(5, 7) - 1) / 3) + 1 && annee() === +AUJ.slice(0, 4); return `<button data-trim="${q}" class="${trimestre() === q ? 'on' : ''}" ${deb > AUJ ? 'disabled' : ''}>T${q}${auj ? ' · en cours' : ''}</button>`; }).join('')}</div>` : ''}
      ${['ops', 'semaine', 'mois', 'trimestre'].includes(S.vue) ? '' : `<button class="db-btn" data-pas="-1">‹</button><input class="db-sel" type="date" id="db-date" value="${S.date}" max="${AUJ}"><button class="db-btn" data-pas="1">›</button>`}
      ${(S.vue === 'semaine' ? bornes()[1] < AUJ : (S.vue === 'mois' ? S.date.slice(0, 7) !== AUJ.slice(0, 7) : (S.vue === 'trimestre' ? (annee() !== +AUJ.slice(0, 4) || trimestre() !== Math.floor((+AUJ.slice(5, 7) - 1) / 3) + 1) : S.date !== AUJ))) ? `<button class="db-btn" data-auj="1">${S.vue === 'semaine' ? 'Cette semaine' : (S.vue === 'mois' ? 'Ce mois-ci' : (S.vue === 'trimestre' ? 'Ce trimestre' : 'Aujourd’hui'))}</button>` : ''}
      <span style="flex:1"></span>${valoPastille()}<button class="db-btn" data-recharger="1">↻ Relire</button></div>${friseHtml()}`;
    if (S.vue === 'actions' || S.vue === 'campagne') { h += rendActions(false); $.innerHTML = h; brancher(); monterActions(); return; }
    if (S.vue === 'production') { const g = ppGarder(); h += rendProduction(); $.innerHTML = h; brancher(); ppRestaurer(g); return; }
    if (S.vue === 'ops') { h += rendOps() + cqLoupe(false); $.innerHTML = h; brancher(); cqRestaurer(cqPos); return; }
    h += rendValeur();
    if (S.vue === 'annee') { h += rendAnnee(); $.innerHTML = h; brancher(); return; }
    if (S.vue === 'trimestre') { h += rendTrimestre(); $.innerHTML = h; brancher(); return; }
    if (S.vue === 'jour') {
      if (S.err[kr]) { h += `<div class="db-err">Résultat : ${esc(S.err[kr])}</div>`; }
      else if (d && !m) { h += `<div class="db-alerte">Ce magasin n’est pas dans la réponse de Résultat pour cette période.</div>`; }
      h += rendJourA4(m, d, st, ks);
      h += cqLoupe(false);
      $.innerHTML = h;
      brancher();
      noteRestaurer(garde);
      cqRestaurer(cqPos);
      a4Viser();
      return;
    }
    h += rendNC();
    if (S.err[kr]) { h += `<div class="db-err">Résultat : ${esc(S.err[kr])}</div>`; }
    // Le bandeau : la place du magasin dans le réseau, sans nommer les autres.
    if (m) { h += rendBench(m, d); }
    h += rendTaches();
    h += rendCQ(false);
    // La barre du stock ne s'affiche plus en vues Semaine et Mois (10/10/2026) : elle reste dans Opérationnel.
    h += `<div class="db-sec">Résultat — ${S.vue === 'jour' ? 'la journée' : (S.vue === 'semaine' ? 'la semaine' : 'le mois')}<small>${S.vue === 'jour' ? 'budget du jour, référence des mêmes jours, P&amp;L court' : 'objectif réparti par la pondération réseau, attendu à ce jour, P&amp;L'}</small></div>`;
    if (!d && !S.err[kr]) { h += squelette(3); }
    else if (d && !m) { h += `<div class="db-alerte">Ce magasin n’est pas dans la réponse de Résultat pour cette période.</div>`; }
    else if (m) { h += rendPeriode(m, d); }
    const autreJ = S.vue === 'jour' && S.jourH && S.jourH !== S.date;
    h += `<div class="db-sec">Les heures — ${S.vue === 'jour' ? esc(fDL(dateH())) : 'ventes, matière, rémunération, marge nette'}<small>${S.vue === 'jour' ? 'heure par heure · cliquer un jour dans « le jour dans le mois » pour le lire' : 'moyenne par jour ouvert de la période, ou total'}</small>${autreJ ? `<button class="db-btn" data-jh="">↩ revenir au ${esc(fD(S.date))}</button>` : ''}</div>`;
    if (S.err[ks]) { h += `<div class="db-err">Heures : ${esc(S.err[ks])}${(S.relances[ks] || 0) < 3 ? " — nouvelle lecture dans quelques secondes" : ""}</div>`; }
    if (st && st.produits && st.produits.aSuivre) { h += `<div class="db-alerte">Tickets lus sur ${st.produits.jours.length} jour(s) sur ${st.produits.total} — la lecture continue, la page se complète toute seule.</div>`; }
    if (!st && !S.err[ks]) { h += squelette(4); }
    else if (st) { h += rendHeures(st); }
    h += cqLoupe(false);
    $.innerHTML = h;
    brancher();
    noteRestaurer(garde);
    cqRestaurer(cqPos);
  }
  function squelette(n) {
    return `<div class="db-tuiles">${Array.from({ length: 5 }, () => `<div class="db-tui"><div class="db-sk" style="width:60%"></div><div class="db-sk" style="height:24px;margin:8px 0 6px"></div><div class="db-sk" style="width:80%"></div></div>`).join('')}</div>
      <div class="db-card"><div class="ct"><div class="db-sk" style="width:220px"></div></div><div style="padding:14px 16px">${Array.from({ length: n }, () => `<div class="db-sk" style="margin-bottom:10px"></div>`).join('')}</div></div>`;
  }
  const fSE = v => v == null ? '—' : (v < 0 ? '− ' + fE(-v) : fE(v));
  function tuile(k, v, s, cls) { return `<div class="db-tui ${cls || ''}"><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s || ''}</div></div>`; }
  /** La tuile avec sa tendance : l'écart avec le dernier même jour de semaine. */
  function tuileTend(k, v, s, cls, serie, auj, fmt, inverse, cmp) {
    const pts = serie.filter(x => x != null);
    if ((!pts.length && !cmp) || auj == null) { return tuile(k, v, s, cls); }
    const all = pts.concat([auj]), mn = Math.min(...all), mx = Math.max(...all), n = all.length;
    const xy = all.map((x, i) => [(i * 70 / Math.max(1, n - 1)), 24 - 22 * (x - mn) / ((mx - mn) || 1) + 1]);
    // L'écart : face à J−7 à la même heure quand on le connaît (cmp), sinon face au dernier même jour entier.
    const dern = cmp ? cmp.val : pts[pts.length - 1], d = dern ? 100 * (auj - dern) / dern : null;
    const sens = d == null ? 'eq' : (Math.abs(d) < 1 ? 'eq' : ((d > 0) !== !!inverse ? 'up' : 'dn'));
    const TT = S.aux['tend|' + S.shop + '|' + S.date];
    const lib = cmp ? cmp.lib : 'vs ' + esc(fD(TT && TT.jours && TT.jours.length ? TT.jours.slice(-1)[0].date : ''));
    const titre = cmp ? `${cmp.titre} : ${fmt(dern)}` : `dernier même jour : ${fmt(dern)}`;
    return `<div class="db-tui ${cls || ''}"><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s || ''}</div>${d == null ? '' : `<span class="db-dl ${sens}" title="${titre}">${d >= 0 ? '+ ' : '− '}${fP(Math.abs(d))} ${lib}</span>`}</div>`;
  }
  function cascade(m, d) {
    const se = (d && d.seuils) || {};
    const ca = m.ca != null ? m.ca : m.realise;
    const feu = (v, s) => v == null || s == null ? 'var(--color-text-muted)' : (v <= s ? '#2d7a3e' : (v <= s * 1.3 ? '#D97706' : '#C0182B'));
    const feuRes = p => p == null ? 'var(--color-text-muted)' : (p >= 15 ? '#2d7a3e' : (p >= 5 ? '#D97706' : '#C0182B'));
    const mbPct = m.margeBrutePct != null ? m.margeBrutePct : (ca ? 100 * m.margeBrute / ca : null);
    const barre = p => Math.min(Math.abs(p || 0), 100).toFixed(1);
    const ligne = (lib, sous, v, pct, coul, w, fort, note, attr) => `<div class="db-cl${fort ? ' fort' : ''}${attr ? ' clic' : ''}"${attr || ''}><span><b>${lib}</b>${sous ? `<br><span class="mu">${sous}</span>` : ''}</span><span class="b"><i style="width:${w}%;background:${coul}"></i></span><span class="v">${v}</span><span class="p" style="color:${coul}">${pct}</span><span class="n mu">${note || ''}</span></div>`;
    const fr = m.planningHeuresFranchise ? 'hors ' + nf(m.planningHeuresFranchise, 1) + ' h de franchisé (' + esc((m.planningFranchiseNoms || []).join(', ')) + ')' : '';
    return `<div class="db-cascade">
      ${ligne('Chiffre d’affaires', m.tickets != null ? fN(m.tickets) + ' clients · ' + fU(m.panier) : '', fE(ca), '100 %', 'var(--color-text)', 100, true, '')}
      ${ligne('− Coût matière', (se.food != null ? 'seuil ' + fP(se.food) : 'coût des recettes vendues') + (m.coutMatiereSource && m.coutMatiereSource !== 'panel' ? ' · ' + esc(m.coutMatiereSource) : ''), m.coutMatiere == null ? '—' : fE(-m.coutMatiere), fP(m.coutMatierePct), feu(m.coutMatierePct, se.food), barre(m.coutMatierePct), false)}
      ${ligne('= Marge brute', '', fE(m.margeBrute), fP(mbPct), 'var(--color-text)', barre(mbPct), true)}
      ${m.invendus === undefined ? '' : ligne('− Invendus et poubelle', m.invendus == null ? 'panel muet — rien retranché' : (m.invendusDeclare ? 'coût de production des pièces jetées' + (m.invendusPieces ? ' · ' + fN(m.invendusPieces) + ' pièce' + (m.invendusPieces > 1 ? 's' : '') : '') : 'rien déclaré au panel'), m.invendus == null ? '—' : fE(-m.invendus), m.invendus == null ? '' : fP(m.invendusPct), m.invendus ? (m.invendusPct > 5 ? '#C0182B' : '#D97706') : 'var(--color-text-muted)', barre(m.invendusPct), false, 'les saisies ▸', ' data-invmodale="1" role="button" tabindex="0" title="Le détail des saisies : heure, opérateur, produit, quantité"')}
      ${ligne('− Main-d’œuvre', (se.labour != null ? 'seuil ' + fP(se.labour) : '') + (m.labourSource ? ' · ' + esc(m.labourSource) : ''), m.labour == null ? '—' : fE(-m.labour), fP(m.labourPct), feu(m.labourPct, se.labour), barre(m.labourPct), false, fr)}
      ${ligne('− Frais généraux', (se.overhead != null ? 'seuil ' + fP(se.overhead) : '') + (m.overheadSource ? ' · ' + esc(m.overheadSource) : ''), m.overhead == null ? '—' : fE(-m.overhead), fP(m.overheadPct), feu(m.overheadPct, se.overhead), barre(m.overheadPct), false)}
      ${ligne('= Résultat', m.net == null ? esc(m.motifNet || 'non calculable') : '', m.net == null ? '—' : fS(m.net), fP(m.netPct), feuRes(m.netPct), barre(m.netPct), true, m.net != null && m.motifNet ? esc(m.motifNet) : '')}
    </div>`;
  }
  function cascadeVieille(m) {
    const ov = m.overhead == null ? null : m.overhead;
    return `<div class="db-casc">
      <div><div class="k">Chiffre d’affaires</div><div class="v">${fK(m.ca != null ? m.ca : m.realise)}</div><div class="s">${m.tickets != null ? fN(m.tickets) + ' clients · ' + fU(m.panier) : ''}</div></div>
      <div><div class="k">− Coût matière</div><div class="v">${fK(m.coutMatiere)}</div><div class="s">${fP(m.coutMatierePct)} · food cost${m.coutMatiereSource && m.coutMatiereSource !== 'panel' ? ' · ' + esc(m.coutMatiereSource) : ''}</div></div>
      <div><div class="k">= Marge brute</div><div class="v">${fK(m.margeBrute)}</div><div class="s">${fP(m.margeBrutePct != null ? m.margeBrutePct : (m.ca ? 100 * m.margeBrute / m.ca : null))}</div></div>
      <div><div class="k">− Personnel</div><div class="v">${fK(m.labour)}</div><div class="s">${fP(m.labourPct)}${m.labourSource ? ' · ' + esc(m.labourSource) : ''}</div></div>
      <div><div class="k">− Frais généraux</div><div class="v">${fK(ov)}</div><div class="s">${fP(m.overheadPct)}${m.overheadSource ? ' · ' + esc(m.overheadSource) : ''}</div></div>
      <div><div class="k">= Résultat net</div><div class="v ${coul(m.net)}">${m.net == null ? '—' : fSK(m.net)}</div><div class="s">${m.net == null ? esc(m.motifNet || 'non calculable') : fP(m.netPct) + ' des ventes'}${m.net != null && m.motifNet ? ' · ' + esc(m.motifNet) : ''}</div></div>
    </div>`;
  }

  /* Résultat › Jour, déplié pour le magasin : chaque carte de la journée,
   * rendue à part. La vue Jour en une page (rendJourA4) les range chacune
   * dans la liste déroulante de sa ligne. */
  /* « À compléter pour des chiffres justes » (10/10/2026) : sous les ventes par catégorie, ce qui manque dans
   * la caisse et le panel pour que la ventilation tombe juste — les lignes de caisse sans produit (à rattacher
   * à un produit), les remises sur ticket entier, et les produits vendus sans coût de recette (à chiffrer). */
  function aCompleterCarte(st, m) {
    const A = st && st.aCompleter;
    if (!A) { return ''; }
    const somme = (st.categories || []).reduce((t, c) => t + (c.v || 0), 0);
    const ecart = m && m.ca != null ? m.ca - somme : null;
    const lignes = (A.lignes || []).filter(x => x.type === 'ligne'), tickets = (A.lignes || []).filter(x => x.type === 'ticket');
    const reste = ecart != null ? ecart - (A.caLignes || 0) - (A.caTickets || 0) : null;
    const rien = !lignes.length && !tickets.length && !(A.sansCout || []).length && (ecart == null || Math.abs(ecart) < 0.5);
    // Rien à compléter : pas de carte (10/10/2026), elle ne se montre que quand quelque chose ne va pas.
    if (rien) { return ''; }
    const hm = x => (x.mn || '') + (x.j && x.j !== S.date ? ' · ' + fD(x.j) : '');
    // Ouverte ou fermée, la liste le reste quand la page se relit (S.cOuv).
    const ouvre = (k, n) => (S.cOuv['acp:' + k] != null ? S.cOuv['acp:' + k] : n <= 8) ? ' open' : '';
    let v1 = '';
    if (ecart != null && Math.abs(ecart) >= 0.5 || lignes.length || tickets.length) {
      const det = !A.detail ? '<div class="acp-n">Le détail de ce jour se lit à la prochaine ouverture de la page.</div>'
        : `<details data-acpo="l"${ouvre('l', lignes.length + tickets.length)}><summary>${lignes.length} ligne${lignes.length > 1 ? 's' : ''} sans produit${tickets.length ? ' · ' + tickets.length + ' remise' + (tickets.length > 1 ? 's' : '') + ' sur ticket' : ''}</summary>
          <table class="db-pro-tab acp-t"><thead><tr><th>Heure</th><th>Libellé de caisse</th><th class="n">Qté</th><th class="n">Montant</th></tr></thead><tbody>
          ${lignes.concat(tickets).map(x => `<tr class="${x.type === 'ticket' ? 'tk' : ''}"><td>${esc(hm(x))}</td><td>${esc(x.nom)}</td><td class="n">${x.q ? nf(x.q, x.q % 1 ? 1 : 0) : ''}</td><td class="n">${fU(x.v)}</td></tr>`).join('')}
          </tbody></table></details>`;
      v1 = `<div class="acp-v"><div class="k">Ventes sans produit</div><div class="b">${ecart != null ? fE(ecart) : fE((A.caLignes || 0) + (A.caTickets || 0))}</div>
        <div class="s">à rattacher à un produit dans la caisse${A.caTickets ? ' · dont ' + fU(A.caTickets) + ' de remises sur ticket' : ''}${reste != null && A.detail && Math.abs(reste) >= 0.5 ? ' · ' + fU(reste) + ' d’arrondis ou de tickets pas encore lus' : ''}</div>${det}</div>`;
    }
    let v2 = '';
    if ((A.sansCout || []).length) {
      v2 = `<div class="acp-v"><div class="k">Produits sans coût</div><div class="b">${A.nSansCout} <small>produit${A.nSansCout > 1 ? 's' : ''} · ${fU(A.caSansCout)} de ventes</small></div>
        <div class="s">à chiffrer dans le panel : recette ou coût de revient · leur marge, estimée par le P&L, reste dans « Non ventilé » le temps de les chiffrer</div>
        <details data-acpo="p"${ouvre('p', A.sansCout.length)}><summary>les ${A.sansCout.length} produit${A.sansCout.length > 1 ? 's' : ''}</summary><table class="db-pro-tab acp-t"><thead><tr><th>Produit</th><th>Catégorie</th><th class="n">Qté</th><th class="n">CA</th></tr></thead><tbody>
        ${A.sansCout.map(x => `<tr class="clic" data-fprod="${esc(x.id)}" data-fnom="${esc(x.nom)}" data-fq="${x.q}" data-fv="${x.v}" data-ft="" data-fc="" data-fcat="${esc(x.cat || '')}" role="button" tabindex="0"><td>${esc(x.nom)}</td><td class="mu">${esc(x.cat || '')}</td><td class="n">${nf(x.q, x.q % 1 ? 1 : 0)}</td><td class="n">${fU(x.v)}</td></tr>`).join('')}
        </tbody></table>${A.nSansCout > A.sansCout.length ? `<div class="acp-n">et ${A.nSansCout - A.sansCout.length} autres</div>` : ''}</details></div>`;
    }
    return `<div class="db-card acp"><div class="ct"><span class="db-lab">À compléter pour des chiffres justes</span><span class="db-mini">ce qui manque dans la caisse et le panel · la ligne « Non ventilé » ci-dessus</span></div><div class="acp-g">${v1}${v2}</div></div>`;
  }

  function jourPieces(m, d, st) {
    const P = {};
    const TT = S.aux['tend|' + S.shop + '|' + S.date], TJ = TT && Array.isArray(TT.jours) ? TT.jours : [];
    const ref = d.reference || {};
    const att = m.objectifJour ? Math.min(100, 100 * m.ca / m.objectifJour) : 0;
    // Aujourd'hui, chaque tuile se compare à J−7 à la même heure (08/10/2026) ; un jour passé, au dernier même jour entier.
    const J7 = d.estAujourdhui && m.j7 && m.j7.moment ? m.j7 : null;
    const c7 = (val, quoi) => J7 && val ? { val, lib: 'vs J−7 à ' + esc(J7.moment), titre: quoi + ' de J−7 (' + fD(J7.date) + ') à ' + J7.moment } : null;
    let h = `<div class="db-tuiles">
      ${tuileTend('CA du jour', fK(m.ca), m.objectifJour ? 'objectif ' + fK(m.objectifJour) + ' · ' + fP(100 * (m.objectifAtteinte || 0)) + ' atteint' + ((CJ => !CJ ? '' : (CJ.n > 0 ? ' · <b class="ko">−' + fN(CJ.n) + ' clients' + (CJ.comptoir ? ' comptoir' : '') + '</b> (' + fE(Math.abs(CJ.ecart)) + ' ÷ ' + fU(CJ.panier) + ')' : ' · <b class="ok">+' + fN(-CJ.n) + ' clients' + (CJ.comptoir ? ' comptoir' : '') + '</b> d’avance'))(clientsJour(m))) : 'pas d’objectif du jour', '', TJ.map(j => j.ca), m.ca, fK, false, c7(J7 && J7.ca, 'CA'))}
      ${tuileTend('Marge brute', fK(m.margeBrute), fP(m.margeBrutePct != null ? m.margeBrutePct : (m.ca ? 100 * m.margeBrute / m.ca : null)) + ' des ventes · matière ' + fK(m.coutMatiere), '', TJ.map(j => j.mb), m.margeBrute, fK, false, c7(J7 && J7.mb, 'marge brute'))}
      ${tuileTend('Clients', fN(m.tickets) + j7Delta(m), [j7Texte(m), 'référence ' + fN(m.refTickets) + (m.ticketsDelta != null ? ' · ' + (m.ticketsDelta >= 0 ? '+ ' : '− ') + fP(Math.abs(m.ticketsDelta)) : ''), m.produits ? fN(m.produits) + ' produits vendus' : ''].filter(Boolean).join(' · '), '', TJ.map(j => j.tickets), m.tickets, fN, false, c7(J7 && J7.tickets, 'clients'))}
      ${tuileTend('Panier moyen', fU(m.panier), (d.reseau && d.reseau.panier ? 'réseau ' + fU(d.reseau.panier) + ' · ' : '') + (m.produitsParClient ? nf(m.produitsParClient, 2) + ' produits / client' : ''), '', TJ.map(j => j.panier), m.panier, fU, false, c7(J7 && J7.tickets ? J7.ca / J7.tickets : null, 'panier'))}
      ${tuile('Projection fin de journée', m.projection != null ? fK(m.projection) : '—', m.projection != null ? (m.projectionPart != null ? fP(m.projectionPart) + ' de la journée écoulée' : '') + (m.projectionRythme ? ' · au rythme : ' + fK(m.projectionRythme) : '') : esc(m.projectionMotif || ''))}
      ${tuile('Résultat net du jour', m.net == null ? '—' : fSK(m.net), m.net == null ? esc(m.motifNet || '') : fP(m.netPct) + ' des ventes', m.net == null ? '' : (m.net >= 0 ? 'bon' : 'vif'))}
    </div>`;
    P.tuiles = h; h = '';
    P.split = splitCarte(m);
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
    P.objectif = h; h = '';
    h = `<div class="db-card"><div class="ct"><span class="db-lab">Le P&amp;L court de la journée</span><span class="db-mini">matière : coût des recettes vendues · personnel : ${esc(m.planningSource || 'planning')} · frais généraux : ${esc(m.overheadSource || '—')}${m.overheadSource === 'reparti' ? ' — allocation du panel, le mois ÷ ses jours' : ''}</span></div>${cascade(m, d)}</div>`;
    P.pnl = h; h = '';
    const cats = Array.isArray(m.categories) ? m.categories : [];
    const plan = Array.isArray(m.planning) ? m.planning : [];
    // Les catégories lues dans les tickets portent la marge brute (CA − coût matière) :
    // c'est elle qui colore le treemap. Sans tickets lus, repli sur l'écart à la référence du panel.
    const catsM = st && Array.isArray(st.categories) ? st.categories.filter(c => c.v > 0).map(c => ({ categorie: c.nom, groupe: c.groupe, ca: c.v, part: c.part != null ? c.part / 100 : null, mat: c.c, m: c.m, taux: c.taux, refs: c.refs, q: c.q, vC: c.vChiffre, nSans: c.nSans || 0, vSans: c.vSans || 0, produits: Array.isArray(c.produits) ? c.produits : [] })) : [];
    const parMarge = catsM.length > 0;
    const lc = parMarge ? MARGES : ECARTS;
    // Les tickets ne sont pas encore lus : le squelette, plutôt qu'un premier
    // dessin par l'écart à la référence qui basculerait sur la marge vingt
    // secondes plus tard — une carte qui change sous les yeux se relit en entier.
    const catsAttend = !st && !S.err[cleSt()];
    h += catsAttend
      ? `<div class="db-card"><div class="ct"><span class="db-lab">Ventes par catégorie</span><span class="db-mini">lecture des tickets en cours…</span></div><div class="db-acc"><div class="db-sk" style="height:300px"></div></div></div>`
      : `<div class="db-card"><div class="ct"><span class="db-lab">Ventes par catégorie</span><span class="db-ong db-cvue"><button data-cvue="liste" class="${S.cVue !== 'treemap' ? 'on' : ''}">Liste</button><button data-cvue="treemap" class="${S.cVue === 'treemap' ? 'on' : ''}">Treemap</button></span><span class="db-mini">${S.cVue === 'treemap' ? 'surface : poids dans le CA' : 'groupe › catégorie › produit · barre : poids dans le CA'} · couleur : ${parMarge ? 'marge brute, CA − coût matière' + (S.cVue === 'treemap' ? '' : ' · coef : CA ÷ coût matière') : 'écart à la référence'}</span></div>
      ${(parMarge ? catsM : cats).length ? (S.cVue === 'treemap' ? `<div class="db-tm">${treemap(parMarge ? catsM : cats)}</div>` : accordeon(parMarge ? catsM : cats, parMarge, m && m.ca != null && m.margeBrute != null ? { ca: m.ca, m: m.margeBrute } : null)) + `<div class="db-leg">${lc.map(e => `<span><i class="${e.c === 'or' ? 'or' : ''}" style="${e.c === 'or' ? '' : 'background:' + e.c}"></i>${e.l}</span>`).join('')}<span><i style="background:#B9B2A8"></i>${parMarge ? 'coût matière inconnu' : 'sans référence'}</span></div>` : `<div class="db-note" style="padding-top:12px">Pas de ventilation par catégorie pour ce jour.</div>`}</div>`;
    P.categories = h + aCompleterCarte(st, m); h = '';
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
    P.poste = h; h = '';
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
    P.mois = h;
    return P;
  }

  /* --- La vue Jour en une page ---------------------------------------------
   * Maquette A, retenue le 03/10/2026 : seize sections en quatre chapitres,
   * une ligne chacune — la pastille du verdict, le titre, la valeur, la phrase
   * qui la justifie, un mini-graphique — et la carte complète de la section
   * dans une liste déroulante sous sa ligne. Des titres, pas des questions
   * (demande du 03/10/2026). Une seule section ouverte à la fois : repliée,
   * la journée tient dans une page A4. */
  const A4_CHAP = [
    ['ouv', 'Ouverture et contrôles', 'hier, les tâches, les photos, le stock'],
    ['chiffre', 'Le chiffre du jour', 'les ventes, l’objectif, le résultat, le réseau'],
    ['detail', 'Le détail des ventes', 'les canaux, les catégories, les offres, la poubelle'],
    ['journee', 'La journée', 'les heures, l’équipe, le mois, la note'],
  ];
  const A4_VD = { ko: 'à reprendre', att: 'à surveiller', ok: 'en ordre', or: 'en ordre', neutre: 'pour information' };
  /** La couleur d'un taux de marge brute, sur l'échelle des catégories. */
  const a4Marge = t => { let c = MARGES[0].c; for (const p of MARGES) { if (t >= p.s) { c = p.c; } } return c === 'or' ? '#E2B93B' : c; };
  const a4Barres = (vals, coul, haut) => { const H = haut || 22, mx = Math.max(1e-9, ...vals.map(v => Math.abs(v || 0))); return `<span class="a4-mv" style="height:${H}px">${vals.map((v, i) => `<i style="height:${Math.max(2, H * Math.abs(v || 0) / mx).toFixed(1)}px;background:${coul(v, i)}"></i>`).join('')}</span>`; };
  const a4Pile = segs => `<span class="a4-pile">${segs.filter(x => x[0] > 0).map(([p, c, t]) => `<i style="width:${Math.min(100, p).toFixed(1)}%;background:${c}"${t ? ` title="${esc(t)}"` : ''}></i>`).join('')}</span>`;
  const a4Cases = (cls, titres) => `<span class="a4-cases">${cls.map((c, i) => `<i class="${c}"${titres ? ` title="${esc(titres[i])}"` : ''}></i>`).join('')}</span>`;
  const a4Jauge = (pct, repere) => `<span class="a4-jauge"><i style="width:${Math.max(0, Math.min(100, pct)).toFixed(1)}%"></i>${repere != null ? `<b style="left:${Math.max(0, Math.min(100, repere)).toFixed(1)}%"></b>` : ''}</span>`;
  function a4Points(vals, moi) {
    const v = vals.filter(x => x != null && isFinite(x));
    if (v.length < 2 || moi == null) { return ''; }
    const mn = Math.min(...v), e = Math.max(...v) - mn, pos = x => e > 0 ? 4 + 92 * (x - mn) / e : 50;
    let vu = false;
    return `<span class="a4-pts">${v.map(x => { const me = !vu && x === moi; if (me) { vu = true; } return `<i class="${me ? 'moi' : ''}" style="left:${pos(x).toFixed(1)}%"></i>`; }).join('')}</span>`;
  }
  const a4Puce = (cls, t) => `<span class="a4-puce ${cls || ''}">${t}</span>`;
  const a4Lect = (cle, txt) => ({ v: '…', s: S.err[cle] ? esc(S.err[cle]) : txt, vd: 'neutre', mini: '' });
  const pl = (n, mot, motPl) => n + ' ' + (n > 1 ? (motPl || mot + 's') : mot);

  /** Les seize lignes : le résumé de chaque section et sa carte complète. */
  function a4Lignes(m, d, st, P, ks) {
    const L = [];
    const ajoute = (k, ch, t, x, corps) => L.push(Object.assign({ k, ch, t, corps: corps || '' }, x));
    const kr = cleRes();
    const attente = n => S.err[kr] ? `<div class="db-err">Résultat : ${esc(S.err[kr])}</div>` : squelette(n || 2);
    const resume = f => !m ? { v: d ? '—' : '…', s: d ? 'ce magasin n’est pas dans la réponse de Résultat' : (S.err[kr] ? esc(S.err[kr]) : 'lecture du résultat…'), vd: 'neutre', mini: '' } : f();
    const LH = st && Array.isArray(st.heures) ? st.heures.filter(x => x.ca > 0 || x.poste > 0) : [];

    /* 1. Ouverture et contrôles */
    ajoute('nc', 'ouv', 'Non-conformités d’hier', (() => {
      const cle = cleNC(), D = S.aux[cle];
      if (!D) { return a4Lect(cle, 'lecture d’hier…'); }
      if (D.indispo) { return { v: '—', s: 'les avis des contrôles ne sont pas lisibles', vd: 'neutre', mini: '' }; }
      const N = ncLignes(), hier = esc(fDL(veille()));
      if (!N.length) {
        return { v: D.notees ? 'Aucune' : '—', s: hier + (D.notees ? ' · ' + pl(D.notees, 'tâche') + ' ' + (D.notees > 1 ? 'notées' : 'notée') + ', rien à reprendre' : ' · aucune tâche notée'),
          vd: D.notees ? 'ok' : 'neutre', mini: D.notees ? a4Puce('ok', D.notees + ' notée' + (D.notees > 1 ? 's' : '') + ' · 0 à reprendre') : '' };
      }
      const ouv = N.filter(x => x.etat.c !== 'ok').length;
      return { v: String(N.length), s: hier + ' · ' + (ouv ? `<b class="ko">${ouv} à reprendre</b>` : 'toutes reprises') + ' · ' + D.notees + ' notée' + (D.notees > 1 ? 's' : ''),
        vd: ouv ? 'ko' : 'ok', mini: a4Cases(N.map(x => x.etat.c === 'ok' ? 'ok' : 'ko'), N.map(x => x.tache || '')) };
    })(), rendNC());

    ajoute('taches', 'ouv', 'Tâches du jour', (() => {
      const cle = 'taches|' + S.date, D = S.aux[cle];
      if (!D) { return a4Lect(cle, 'lecture du panel…'); }
      const T = tachesJour(D);
      if (!T.length) { return { v: '—', s: D.indispo ? 'panel injoignable' : 'aucune tâche pour ce magasin ce jour', vd: 'neutre', mini: '' }; }
      const faite = tacheFaite;
      const bloq = t => t.statut === 'nonRendue' && (t.obligatoire != null ? !!t.obligatoire : /^CO-/i.test(String(t.checklist || '')));
      const ctl = t => t.statut === 'aControler' || t.statut === 'aValider';
      const nS = T.filter(tacheSansPhoto).length;
      const nF = T.filter(faite).length, nB = T.filter(bloq).length, nC = T.filter(ctl).length, nA = T.length - nF - nB - nS;
      const dern = T.filter(t => t.faitLe).sort((a, b) => String(b.faitLe).localeCompare(String(a.faitLe)))[0];
      const rg = t => faite(t) ? (ctl(t) ? 3 : 4) : (bloq(t) ? 0 : (tacheSansPhoto(t) ? 1 : 2));
      const O = T.slice().sort((a, b) => rg(a) - rg(b));
      return { v: nF + ' / ' + T.length,
        s: [nB ? `<b class="ko">${pl(nB, 'bloquante')} pas ${nB > 1 ? 'rendues' : 'rendue'}</b>` : '', nS ? `<b class="wa">${nS} cochée${nS > 1 ? 's' : ''} sans photo</b>` : '', nA > 0 ? nA + ' autre' + (nA > 1 ? 's' : '') + ' pas ' + (nA > 1 ? 'rendues' : 'rendue') : '', nC ? nC + ' à contrôler' : '',
          dern ? 'dernière rendue à ' + esc(String(dern.faitLe).slice(11, 16)) + (dern.faitePar ? ' par ' + esc(dern.faitePar) : '') : ''].filter(Boolean).join(' · ') || 'toutes rendues',
        vd: nB ? 'ko' : (nA || nS ? 'att' : 'ok'), mini: a4Cases(O.map(t => faite(t) ? (ctl(t) ? 'ctl' : 'ok') : (bloq(t) ? 'ko' : (tacheSansPhoto(t) ? 'mu' : 'att'))), O.map(t => (t.tache || '') + (tacheSansPhoto(t) ? ' · cochée sans photo' : ''))) };
    })(), rendTaches());

    ajoute('photos', 'ouv', 'Contrôles en photo', (() => {
      const Q = cqListe();
      if (Q === null) { return a4Lect('taches|' + S.date, 'lecture des photos…'); }
      if (!Q.length) { return { v: '—', s: 'aucun contrôle en photo ce jour', vd: 'neutre', mini: '' }; }
      const n = c => Q.filter(x => x.e.c === c).length;
      const rendues = Q.length - n('ko') - n('mu'), notees = Q.filter(x => x.note != null);
      const moy = notees.length ? notees.reduce((a, x) => a + x.note, 0) / notees.length : null;
      return { v: n('ok') + ' / ' + rendues,
        s: [(n('ok') > 1 ? 'conformes' : 'conforme') + ' sur ' + pl(rendues, 'rendue'), n('nc') ? `<b class="ko">${pl(n('nc'), 'écart')}</b>` : '', n('ctl') ? n('ctl') + ' à contrôler' : '', n('mu') ? `<b class="wa">${n('mu')} cochée${n('mu') > 1 ? 's' : ''} sans photo</b>` : '', n('ko') ? n('ko') + ' pas ' + (n('ko') > 1 ? 'rendues' : 'rendue') : '', moy != null ? 'moyenne ' + nf(moy, 1) + ' / 5' : ''].filter(Boolean).join(' · '),
        vd: n('nc') ? 'ko' : (n('ctl') || n('ko') || n('mu') ? 'att' : 'ok'),
        mini: a4Cases(Q.map(x => ({ ok: 'ok', nc: 'ko', ctl: 'ctl', ko: 'vide', mu: 'mu' })[x.e.c] || 'mu'), Q.map(x => cqNom(x))) };
    })(), rendCQ(false));

    ajoute('stock', 'ouv', 'Stock', (() => {
      const E = stockEtat();
      if (!E) { return a4Lect('stock|' + S.shop, 'lecture de l’inventaire…'); }
      if (E.indispo) { return { v: '—', s: esc(E.motif), vd: 'neutre', mini: '' }; }
      if (!E.n) { return { v: '—', s: 'pas d’inventaire dans le panel', vd: 'neutre', mini: '' }; }
      return { v: E.alertes ? String(E.alertes) : (E.vieux ? E.jours + ' jours' : 'À jour'),
        s: (E.alertes ? `<b class="ko">${E.alertes > 1 ? 'références' : 'référence'} sous le minimum</b>${E.ruptures ? ' · ' + E.ruptures + ' à zéro' : ''} · ` : (E.vieux ? 'inventaire non recompté · ' : '')) + stockSous(E),
        vd: E.alertes ? 'ko' : (E.vieux ? 'att' : 'ok'),
        mini: a4Puce(E.alertes ? 'ko' : (E.vieux ? 'att' : 'ok'), E.alertes ? E.alertes + ' en alerte' : (E.dernier ? 'compté le ' + fD(E.dernier.slice(0, 10)) : 'au complet')) };
    })(), rendStock());

    /* 2. Le chiffre du jour */
    const TT = S.aux['tend|' + S.shop + '|' + S.date], TJ = TT && Array.isArray(TT.jours) ? TT.jours : [];
    ajoute('ventes', 'chiffre', 'Ventes du jour', resume(() => {
      // Aujourd'hui, l'écart se mesure face à J−7 arrêté à la même heure (08/10/2026) ; un jour passé, face au dernier même jour entier.
      const J7 = d && d.estAujourdhui && m.j7 && m.j7.moment && m.j7.ca ? m.j7 : null;
      const der = TJ.length ? TJ[TJ.length - 1] : null, dp = J7 ? 100 * (m.ca - J7.ca) / J7.ca : (der && der.ca ? 100 * (m.ca - der.ca) / der.ca : null);
      const face = J7 ? `face au ${esc(fD(J7.date))} à ${esc(J7.moment)}` : (der ? `face au ${esc(fD(der.date))}` : '');
      const mx = Math.max(0, ...LH.map(x => x.ca));
      return { v: fK(m.ca),
        s: [fN(m.tickets) + ' clients' + j7Delta(m), 'panier ' + fU(m.panier), dp != null ? `<span class="${dp >= 0 ? 'ok' : 'ko'}">${dp >= 0 ? '+ ' : '− '}${fP(Math.abs(dp))}</span> ${face}` : '',
          m.projection != null && m.projectionPart != null && m.projectionPart < 100 ? 'projection ' + fK(m.projection) : ''].filter(Boolean).join(' · '),
        vd: 'neutre', mini: LH.length ? a4Barres(LH.map(x => x.ca), v => v === mx ? 'var(--color-primary)' : '#cfa3a9') : '' };
    }), m ? P.tuiles : attente(1));

    ajoute('objectif', 'chiffre', 'Objectif du jour', resume(() => {
      if (!m.objectifJour) { return { v: '—', s: 'pas d’objectif du jour', vd: 'neutre', mini: '' }; }
      const att = 100 * m.ca / m.objectifJour, ec = m.ca - m.objectifJour;
      const R = S.aux['record|' + S.shop + '|' + S.date];
      const attendu = m.projectionPart != null ? m.projectionPart : 100;
      return { v: fP(att),
        s: 'objectif ' + fK(m.objectifJour) + ' · ' + (ec >= 0 ? 'dépassé de <b>' + fK(ec) + '</b>' : 'il manque <b>' + fK(-ec) + '</b>' + (attendu < 100 ? ' · ' + fP(attendu) + ' de la journée écoulée' : ''))
          + (R && R.meilleur ? ' · record des ' + esc(R.nom || 'jour') + 's : ' + fK(R.meilleur.ca) + ' le ' + esc(fD(R.meilleur.date)) : ''),
        vd: ec >= 0 ? 'or' : (att >= attendu * 0.95 ? 'ok' : 'att'), mini: a4Jauge(att / 1.2, 100 / 1.2) };
    }), (m ? P.objectif || '' : attente(1)) + objectifsCarte(false));

    const se = (d && d.seuils) || {};
    ajoute('resultat', 'chiffre', 'Résultat net', resume(() => {
      if (m.net == null) { return { v: '—', s: esc(m.motifNet || 'P&L incomplet'), vd: 'neutre', mini: '' }; }
      const trop = (v, s) => v != null && s != null && v > s;
      return { v: fSK(m.net),
        s: [fP(m.netPct) + ' des ventes', 'matière ' + (trop(m.coutMatierePct, se.food) ? `<b class="wa">${fP(m.coutMatierePct)}</b> pour un seuil de ${fP(se.food)}` : fP(m.coutMatierePct)),
          'main-d’œuvre ' + (trop(m.labourPct, se.labour) ? `<b class="wa">${fP(m.labourPct)}</b>` : fP(m.labourPct)), 'frais ' + (trop(m.overheadPct, se.overhead) ? `<b class="wa">${fP(m.overheadPct)}</b>` : fP(m.overheadPct)),
          m.invendus ? 'poubelle ' + fK(m.invendus) : ''].filter(Boolean).join(' · '),
        vd: m.netPct >= 15 ? 'ok' : (m.netPct >= 5 ? 'att' : 'ko'),
        mini: a4Pile([[m.coutMatierePct || 0, '#e5c9a0', 'matière'], [m.invendusPct || 0, '#8a5a2b', 'poubelle'], [m.labourPct || 0, '#D97706', 'main-d’œuvre'], [m.overheadPct || 0, '#C0182B', 'frais généraux'], [Math.max(0, m.netPct || 0), '#2d7a3e', 'résultat']]) };
    }), m ? P.pnl : attente(2));

    ajoute('reseau', 'chiffre', 'Place dans le réseau', resume(() => {
      const R = (d.magasins || []).filter(x => x.ouvert !== false);
      if (R.length < 2) { return { v: '—', s: 'un seul magasin ouvert', vd: 'neutre', mini: '' }; }
      const rang = k => { const v = R.map(x => x[k]).filter(x => x != null && isFinite(x)).sort((a, b) => b - a); return m[k] == null || !v.length ? null : v.findIndex(x => x <= m[k]) + 1; };
      const ord = n => n === 1 ? '1er' : n + 'e';
      const rCa = rang('ca');
      const premiers = [['clients', 'tickets'], ['panier', 'panier'], ['marge brute', 'margeBrutePct'], ['résultat net', 'netPct']].filter(([, k]) => rang(k) === 1).map(([l]) => l);
      const tete = R.map(x => x.ca).filter(x => x != null).sort((a, b) => b - a)[0];
      return { v: rCa ? ord(rCa) + ' / ' + R.length : '—',
        s: 'en chiffre d’affaires sur ' + R.length + ' magasins ouverts' + (premiers.length ? ' · <b>1er en ' + premiers.join(', ') + '</b>' : '') + (rCa > 1 ? ' · le 1er vend ' + fK(tete) : '') + ' · anonyme',
        vd: rCa === 1 ? 'or' : 'neutre', mini: a4Points(R.map(x => x.ca), m.ca) };
    }), m && d ? rendBench(m, d) : attente(1));

    /* 3. Le détail des ventes */
    ajoute('canaux', 'detail', 'Commandes et canaux', (() => {
      const C = canauxData(), cle = cleCanaux();
      if (!C) { return a4Lect(cle, 'lecture des commandes…'); }
      if (C.indispo) { return { v: '—', s: 'panel muet', vd: 'neutre', mini: '' }; }
      const J = C.jour, cc = J.cc || { n: 0, ca: 0 }, lv = J.liv || { n: 0, ca: 0 }, tot = (J.comptoir || 0) + (cc.ca || 0) + (lv.ca || 0);
      const pc = tot ? 100 * (J.comptoir || 0) / tot : null;
      return { v: pc != null ? nf(pc, 0) + ' % comptoir' : '—',
        s: [cc.n ? 'click & collect ' + fK(cc.ca) + ' (' + cc.n + ')' : 'pas de click & collect', lv.n ? 'livraison ' + fK(lv.ca) + ' (' + lv.n + ')' : '', m && m.caPro ? 'clients pro ' + fK(m.caPro) : '',
          C.aPreparer ? `<b class="wa">${C.aPreparer} à préparer</b>` : '', C.demain && C.demain.n ? 'demain : ' + pl(C.demain.n, 'commande') + ' (' + fK(C.demain.ca) + ')' : ''].filter(Boolean).join(' · '),
        vd: C.aPreparer ? 'att' : 'neutre', mini: tot ? a4Pile([[pc, '#b8ad9f', 'comptoir'], [100 * (cc.ca || 0) / tot, '#1f5f8b', 'click & collect'], [100 * (lv.ca || 0) / tot, '#0f3b5c', 'livraison']]) : '' };
    })(), m ? P.split : attente(1));

    ajoute('categories', 'detail', 'Ventes par catégorie', (() => {
      if (!st) { return a4Lect(ks, 'lecture des tickets…'); }
      const G = {};
      (st.categories || []).filter(c => c.v > 0).forEach(c => {
        const g = String(c.groupe || c.nom || 'Autres').split(' · ')[0];
        const x = G[g] || (G[g] = { nom: g, v: 0, vc: 0, m: 0, c: 0, inc: true });
        // La marge sur la part chiffrée de chaque catégorie (10/10/2026) ; « inc » quand aucune ne l'est.
        x.v += c.v; if (c.c != null && c.m != null) { x.inc = false; x.m += c.m; x.c += c.c; x.vc += c.vChiffre != null ? c.vChiffre : c.v; }
      });
      const Gs = Object.values(G).sort((a, b) => b.v - a.v), tot = Gs.reduce((a, x) => a + x.v, 0);
      if (!Gs.length || !tot) { return { v: '—', s: 'pas de ventilation par catégorie pour ce jour', vd: 'neutre', mini: '' }; }
      const C = Gs.filter(x => !x.inc && x.c), mb = C.reduce((a, x) => a + x.m, 0), caC = C.reduce((a, x) => a + x.vc, 0);
      const pc = x => nf(100 * x.v / tot, 0) + ' %';
      return { v: esc(Gs[0].nom),
        s: pc(Gs[0]) + ' du CA' + (Gs[1] ? ' · puis ' + esc(Gs[1].nom.toLowerCase()) + ' ' + pc(Gs[1]) : '') + (Gs[2] ? ' et ' + esc(Gs[2].nom.toLowerCase()) + ' ' + pc(Gs[2]) : '') + (caC ? ' · marge brute ' + nf(100 * mb / caC, 0) + ' %' : ''),
        vd: 'neutre', mini: a4Pile(Gs.map(x => [100 * x.v / tot, x.inc || !x.c || !x.vc ? '#B9B2A8' : a4Marge(100 * x.m / x.vc), x.nom])) };
    })(), m ? P.categories : attente(3));

    ajoute('offres', 'detail', 'Promotions et bundles', (() => {
      const O = offresData(), cle = cleOffres(), PR = promoListe();
      if (!O) { return a4Lect(cle, 'lecture des tickets…'); }
      const K = O.kpi || {};
      const jc = PR.length ? pl(PR.length, 'promotion') + ' de jour creux en cours' : '';
      if (!O.offres.length) {
        return { v: PR.length ? String(PR.length) : 'Aucune', s: [jc, 'pas de bundle vendu' + (PR.length ? '' : ', pas de promotion posée dans le cockpit')].filter(Boolean).join(' · '),
          vd: 'neutre', mini: a4Puce('', PR.length ? 'jours creux' : 'rien en cours') };
      }
      const nV = O.offres.filter(o => (o.auj || {}).ca > 0 || (o.auj || {}).pieces > 0).length;
      return { v: fK(K.caJour),
        s: [pl(nV, 'offre') + ' ' + (nV > 1 ? 'vendues' : 'vendue') + ' ' + ceJour() + ' sur ' + O.offres.length, pl(K.bundles || 0, 'bundle') + ', ' + pl(K.promos || 0, 'promo'), K.aAjuster ? `<b class="wa">${K.aAjuster} à ajuster</b>` : '', jc].filter(Boolean).join(' · '),
        vd: K.aAjuster ? 'att' : 'ok', mini: a4Puce(K.aAjuster ? 'att' : 'ok', O.offres.length + ' en cours') };
    })(), offresCarte() + promosCarte(false));

    ajoute('invendus', 'detail', 'Invendus et poubelle', (() => {
      const I = invData(), cle = cleInv();
      if (!I) { return a4Lect(cle, 'lecture de la poubelle…'); }
      if (!I.lu) { return { v: '—', s: 'le panel ne rend pas la poubelle', vd: 'neutre', mini: '' }; }
      if (!I.declare) {
        return { v: '0 €', s: 'rien déclaré au panel ' + ceJour() + (S.date === AUJ ? ' · à encoder avant la fermeture' : '') + ' · le coût de ce qui est jeté se retranche du résultat', vd: 'att', mini: a4Puce('att', 'pas déclaré') };
      }
      const pc = m && m.ca ? 100 * I.cout / m.ca : null;
      const vd = pc == null ? 'att' : (pc > 5 ? 'ko' : (pc > 2 ? 'att' : 'ok'));
      return { v: fK(I.cout), s: [fN(I.pieces) + ' pièce' + (I.pieces > 1 ? 's jetées' : ' jetée'), fK(I.caPerdu) + ' de valeur de vente', pc != null ? fP(pc) + ' du CA' : ''].filter(Boolean).join(' · '),
        vd, mini: a4Puce(vd === 'ok' ? '' : vd, fN(I.pieces) + ' pièce' + (I.pieces > 1 ? 's' : '') + ' jetée' + (I.pieces > 1 ? 's' : '')) };
    })(), invCarte(false));

    /* 4. La journée */
    const autreJ = !!S.jourH && S.jourH !== S.date;
    const corpsHeures = (autreJ ? `<div class="db-a4-ret">Les heures du ${esc(fDL(dateH()))}<button class="db-btn" data-jh="">↩ revenir au ${esc(fD(S.date))}</button></div>` : '')
      + (S.err[ks] ? `<div class="db-err">Heures : ${esc(S.err[ks])}${(S.relances[ks] || 0) < 3 ? ' — nouvelle lecture dans quelques secondes' : ''}</div>` : '')
      + (st && st.produits && st.produits.aSuivre ? `<div class="db-alerte">Tickets lus sur ${st.produits.jours.length} jour(s) sur ${st.produits.total} — la lecture continue, la page se complète toute seule.</div>` : '')
      + (!st ? (S.err[ks] ? '' : squelette(4)) : rendHeures(st));
    ajoute('heures', 'journee', 'Les heures', (() => {
      if (!st) { return a4Lect(ks, 'lecture des heures…'); }
      const V = (st.heures || []).filter(x => x.ca > 0);
      if (!V.length || !st.meilleure) { return { v: '—', s: 'pas d’heure vendue ' + (autreJ ? 'le ' + esc(fD(dateH())) : 'ce jour'), vd: 'neutre', mini: '' }; }
      const t = st.totaux || {}, b = st.meilleure, p = st.pire;
      return { v: b.h + ' – ' + (b.h + 1) + ' h',
        s: (autreJ ? '<b>lu le ' + esc(fD(dateH())) + '</b> · ' : '') + fSK(b.res) + ' de marge nette' + (p && p.h !== b.h ? ' · la moins bonne : ' + p.h + ' – ' + (p.h + 1) + ' h (' + fSK(p.res) + ')' : '') + ' · ' + fSK(t.res) + ' sur la journée',
        vd: t.resPct == null ? 'neutre' : (t.resPct >= 20 ? 'ok' : (t.resPct >= 0 ? 'att' : 'ko')),
        mini: a4Barres(V.map(x => x.res), v => v < 0 ? '#C0182B' : (v === b.res ? '#1f5e2e' : '#8fbf9a')) };
    })(), corpsHeures);

    ajoute('equipe', 'journee', 'Qui est en poste', resume(() => {
      const seuil = (d.seuils && d.seuils.labour) || 33, plan = Array.isArray(m.planning) ? m.planning : [];
      if (m.labourPct == null && !plan.length) { return { v: '—', s: 'pas de planning dans le panel', vd: 'neutre', mini: '' }; }
      const rouges = LH.filter(x => x.trav > x.ca * seuil / 100).map(x => x.h + ' h');
      return { v: m.labourPct != null ? fP(m.labourPct) : '—',
        s: ['du CA en coût du personnel, seuil ' + seuil + ' %', pl(plan.length, 'personne') + (m.planningHeures != null ? ', ' + nf(m.planningHeures, 1) + ' h' : ''), rouges.length ? 'au-dessus du seuil : ' + rouges.join(', ') : 'aucune heure au-dessus du seuil'].join(' · '),
        vd: m.labourPct == null ? 'neutre' : (m.labourPct <= seuil ? 'ok' : (m.labourPct <= seuil * 1.3 ? 'att' : 'ko')),
        mini: LH.length ? a4Cases(LH.map(x => !x.ca ? 'mu' : (x.trav > x.ca * seuil / 100 ? 'ko' : 'okc')), LH.map(x => x.h + ' h · ' + (x.ca ? fP(100 * x.trav / x.ca) : '—'))) : '' };
    }), m ? P.poste : attente(1));

    ajoute('mois', 'journee', 'Le jour dans le mois', resume(() => {
      const J = Array.isArray(m.serie) ? m.serie.filter(x => x.ouvert && x.netPct != null) : [];
      const moi = J.find(x => x.date === S.date);
      if (!moi) { return { v: '—', s: 'pas de marge nette pour ce jour', vd: 'neutre', mini: '' }; }
      const rang = J.slice().sort((a, b) => b.netPct - a.netPct).findIndex(x => x.date === S.date) + 1;
      const cumN = J.reduce((a, x) => a + (x.net || 0), 0), cumC = J.reduce((a, x) => a + (x.ca || 0), 0);
      return { v: J.length < 2 ? '1er jour' : (rang === 1 ? 'Le meilleur' : rang + 'e sur ' + J.length),
        s: fP(moi.netPct) + ' de marge nette · ' + (J.length < 2 ? 'premier jour ouvert du mois' : (rang === 1 ? 'meilleur' : rang + 'e') + ' des ' + J.length + ' jours ouverts du mois') + ' · ' + fK(cumN) + ' cumulés sur ' + fK(cumC) + ' de ventes',
        vd: moi.netPct >= 40 ? 'or' : (moi.netPct >= 10 ? 'ok' : (moi.netPct >= 0 ? 'att' : 'ko')),
        mini: a4Barres(J.map(x => x.netPct), (v, i) => J[i].date === S.date ? (v >= 40 ? '#E2B93B' : 'var(--color-text)') : (v < 0 ? '#C0182B' : '#9cc5a6')) };
    }), m ? P.mois : attente(1));

    ajoute('note', 'journee', 'Note du jour', (() => {
      const cle = cleNote(), N = S.aux[cle];
      if (!N) { return a4Lect(cle, 'lecture…'); }
      const nt = N.note ? N.note.texte : '', n1 = N.n1 && Array.isArray(N.n1.notes) ? N.n1.notes.length : 0;
      return { v: nt ? 'Écrite' : 'Pas de note',
        s: nt ? esc(nt.length > 110 ? nt.slice(0, 110) + '…' : nt) + (N.note.par ? ' · ' + esc(N.note.par) : '')
          : 'ce qui explique la journée, relu l’an prochain la même semaine' + (n1 ? ' · ' + pl(n1, 'note') + ' la même semaine l’an dernier' : ''),
        vd: nt ? 'ok' : 'att', mini: a4Puce(nt ? 'ok' : 'att', nt ? '✎ écrite' : '✎ à écrire') };
    })(), noteCarte(false));
    return L;
  }

  function rendJourA4(m, d, st, ks) {
    const P = m && d ? jourPieces(m, d, st) : {};
    const L = a4Lignes(m, d, st, P, ks);
    if (S.a4 && !L.some(x => x.k === S.a4)) { S.a4 = null; }
    const n = v => L.filter(x => x.vd === v).length;
    const puce = (cls, k, t) => k ? `<span class="a4-puce ${cls}"><i class="a4-vd ${cls}"></i>${k} ${t}</span>` : '';
    let h = `<div class="db-a4c"><b>${L.length} sections</b>${puce('ko', n('ko'), 'à reprendre')}${puce('att', n('att'), 'à surveiller')}${puce('ok', n('ok') + n('or'), 'en ordre')}${puce('', n('neutre'), 'pour information')}<span class="sp"></span><span>un clic sur une ligne ouvre la section · un second clic la replie</span></div>`;
    A4_CHAP.forEach(([c, t, s], i) => {
      h += `<div class="db-a4h"><span class="n">${i + 1}</span><span class="t">${t}</span><small>${s}</small></div><div class="db-a4">`;
      L.filter(x => x.ch === c).forEach(x => {
        const on = S.a4 === x.k;
        h += `<div class="db-a4l${on ? ' on' : ''}" data-a4="${x.k}" role="button" tabindex="0" aria-expanded="${on}"><i class="a4-vd ${x.vd}" title="${A4_VD[x.vd]}"></i><span class="t">${x.t}</span><span class="v ${x.vd}">${x.v}</span><span class="s">${x.s}</span><span class="mi">${x.mini || ''}</span><span class="fl">▾</span></div>`;
        if (on) { h += `<div class="db-a4d">${x.corps || '<div class="db-note" style="padding:4px 4px 12px">Rien de plus à montrer pour ce jour.</div>'}</div>`; }
      });
      h += '</div>';
    });
    return h;
  }
  /** Après un clic : la ligne ouverte reste sous les yeux, même si celle qui se referme était au-dessus. */
  function a4Viser() {
    if (!S.a4Vise) { return; }
    S.a4Vise = false;
    const r = $.querySelector('.db-a4l.on');
    if (r) { const b = r.getBoundingClientRect(); if (b.top < 0 || b.top > window.innerHeight - 120) { r.scrollIntoView({ block: 'start' }); } }
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

  /** Le treemap des catégories : surface = poids dans le CA, couleur = marge brute (ou écart à la référence sans tickets). */
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
  /* La fiche d'un produit (demande du 06/10/2026, maquette B, « seulement le magasin actif ») : un
   * clic sur un produit de la liste des catégories ouvre une modale à deux onglets — ses ventes
   * sur 12 semaines face à la moyenne du réseau, et son prix encaissé face au prix réseau, en
   * nuage prix × volume. Aucun autre magasin n'y est nommé ni montré : le réseau n'y est qu'un
   * repère (moyenne, médiane, bornes). Lecture : /analyse/produits/magasin. */
  const cleFiche = () => S.fiche ? 'fiche|' + S.shop + '|' + S.fiche.pid + '|' + S.fiche.mois : null;
  function ficheLire() { const k = cleFiche(); if (k) { lireAux(k, '/analyse/produits/magasin?pid=' + encodeURIComponent(S.fiche.pid) + '&shop=' + encodeURIComponent(S.shop) + '&mois=' + S.fiche.mois); } }
  function cleRecette() { return S.fiche ? 'recette|' + S.fiche.pid + '|' + S.shop : null; }
  function cleMat(mid) { return 'matiere|' + mid + '|' + S.shop; }
  function ficheLireMatiere(mid, frais) { lireAux(cleMat(mid), '/analyse/matieres/fiche?mid=' + encodeURIComponent(mid) + '&shop=' + encodeURIComponent(S.shop) + (frais ? '&rafraichir=1' : ''), !!frais); }
  function ficheLireRecette() {
    const k = cleRecette(); if (!k) { return; }
    // Le coût de la pièce gravé avec les tickets guide le serveur dans le choix d'unité des quantités.
    const F = S.fiche, c = F.q > 0 && F.c != null && F.c > 0 ? (F.c / F.q).toFixed(4) : '';
    // Relue passées 10 secondes (10/10/2026) : une recette changée au panel se voit à la réouverture de la fiche.
    lireAux(k, '/analyse/produits/recette?pid=' + encodeURIComponent(F.pid) + '&shop=' + encodeURIComponent(S.shop) + (c ? '&cout=' + c : ''), !!(S.auxLu[k] && Date.now() - S.auxLu[k] > 10000));
  }
  function ficheOuvrir(b) {
    const n = v => v === '' || v == null ? null : +v;
    S.fiche = { pid: b.dataset.fprod, nom: b.dataset.fnom, q: n(b.dataset.fq), v: n(b.dataset.fv), taux: n(b.dataset.ft), c: n(b.dataset.fc), cat: b.dataset.fcat || '', onglet: 1, mois: 1, retour: document.activeElement };
    ficheLire(); ficheLireRecette(); ficheRendre();
  }
  /* Les 12 semaines d'une catégorie ou d'un groupe (10/10/2026) : seulement la courbe, en euros ou en pièces,
   * face à la moyenne par magasin du réseau. Lecture : /analyse/categories/magasin. */
  const cleC12 = () => S.fiche && S.fiche.c12 ? 'c12|' + S.shop + '|' + S.fiche.c12 + '|' + S.fiche.nom : null;
  function c12Ouvrir(b) {
    S.fiche = { c12: b.dataset.c12n, nom: b.dataset.c12, u: 'ca', retour: document.activeElement };
    lireAux(cleC12(), '/analyse/categories/magasin?shop=' + encodeURIComponent(S.shop) + '&niveau=' + encodeURIComponent(S.fiche.c12) + '&nom=' + encodeURIComponent(S.fiche.nom));
    ficheRendre();
  }
  function c12Rendre(box) {
    const F = S.fiche, k = cleC12(), d = S.aux[k], err = S.err[k], court = nomShop().replace(/^.* - /, ''), eu = F.u !== 'q';
    let corps;
    if (err && !d) { corps = `<div class="fi-msg">Lecture impossible : ${esc(err)}</div>`; }
    else if (!d) { corps = '<div class="fi-msg"><div class="db-sk" style="width:60%;margin:6px 0"></div><div class="db-sk" style="width:85%;height:180px;margin:10px 0"></div></div>'; }
    else if (d.indispo) { corps = `<div class="fi-msg">${esc(d.motif || 'indisponible')}</div>`; }
    else {
      const W0 = d.semaines, W = Object.assign({}, W0, { magasin: eu ? W0.magasin : W0.magasinQ, reseau: eu ? W0.reseau : W0.reseauQ });
      const M = W.magasin || [], R = W.reseau || [], clos = M.slice(0, -1), clR = R.slice(0, -1);
      const tot = clos.reduce((a, v) => a + (v || 0), 0), totR = clR.reduce((a, v) => a + (v || 0), 0), moy = tot / Math.max(1, clos.length);
      const av = clos.slice(0, 6).reduce((a, v) => a + (v || 0), 0), ap = clos.slice(6).reduce((a, v) => a + (v || 0), 0), tend = av > 0 ? 100 * (ap - av) / av : null;
      const face = totR > 0 ? 100 * (tot - totR) / totR : null, f = v => v == null ? '—' : (eu ? fE(v) : fN(v));
      corps = `<div class="fi-barre"><span class="fi-seg">${[['ca', 'Chiffre d’affaires'], ['q', 'Pièces']].map(([v, l]) => `<button type="button" data-c12u="${v}" class="${F.u === v ? 'on' : ''}">${l}</button>`).join('')}</span><span class="fi-note">semaines du lundi au dimanche · la dernière est en cours (${W.jours ? W.jours[W.jours.length - 1] : '?'} j) · le réseau : la moyenne par magasin, sans magasin nommé</span></div>
        <div class="fi-deux"><div><div class="fi-leg"><span><i class="ln" style="background:#2a78d6"></i>${esc(court)}</span><span><i class="ln" style="background:#eb6834"></i>le réseau, moyenne par magasin</span></div>${ficheCourbe(W, eu)}</div>
          <div class="fi-cote"><div class="k">${esc(court)}, 12 semaines</div>
            <div class="r"><span>${eu ? 'Chiffre d’affaires' : 'Pièces'}</span><b>${f(tot)}</b></div><div class="r"><span>Par semaine</span><b>${eu ? fE(moy) : nf(moy, 1)}</b></div>
            <div class="r"><span>6 dernières face aux 6 d’avant</span><b class="${tend == null ? '' : (tend >= 0 ? 'up' : 'dn')}">${fSg(tend)}</b></div>
            <div class="sep"></div><div class="r"><span>Moyenne par magasin du réseau</span><b>${f(totR)}</b></div><div class="r"><span>Face au réseau</span><b class="${face == null ? '' : (face >= 0 ? 'up' : 'dn')}">${fSg(face)}</b></div>
            <div class="fi-note">${eu ? 'ventes encaissées, remises comprises' : 'en pièces'} ; un grand magasin vend plus : la taille compte</div></div></div>
        <table class="fi-tab"><thead><tr><th>Semaine</th>${(W.tranches || []).map((l, i) => `<th>${esc(l)}${i === M.length - 1 ? '*' : ''}</th>`).join('')}</tr></thead><tbody>
          <tr class="moi"><td>${esc(court)}</td>${M.map(v => `<td>${v == null ? '—' : fN(v)}</td>`).join('')}</tr><tr><td>Réseau, moyenne</td>${R.map(v => `<td>${v == null ? '—' : fN(v)}</td>`).join('')}</tr></tbody></table>
        <div class="fi-note">* en cours.${eu ? ' Montants en euros.' : ''}</div>`;
    }
    const sous = F.c12 === 'groupe' ? 'groupe de catégories' + (d && d.categories && d.categories.length ? ' · ' + d.categories.map(esc).join(', ') : '') : 'catégorie' + (d && d.groupe ? ' · groupe ' + esc(d.groupe) : '');
    box.innerHTML = `<div class="fi-voile" data-ffermer="1"></div><div class="fi-modale" role="dialog" aria-modal="true" aria-label="${esc(F.nom)}">
      <div class="fi-hd"><div class="t"><h2>${esc(F.nom)}</h2><div class="s">${sous} · ${esc(nomShop())}</div></div><button type="button" class="fi-x" data-ffermer="1" aria-label="fermer">✕</button></div>
      <div class="fi-ong"><button type="button" class="on">Ventes · 12 semaines</button></div>
      <div class="fi-bd">${corps}</div></div>`;
    box.querySelectorAll('[data-ffermer]').forEach(b => b.addEventListener('click', ficheFermer));
    box.querySelectorAll('[data-c12u]').forEach(b => b.addEventListener('click', () => { S.fiche.u = b.dataset.c12u; ficheRendre(); }));
  }
  function ficheFermer() { const r = S.fiche && S.fiche.retour; S.fiche = null; ficheRendre(); if (r && r.focus) { try { r.focus(); } catch (e) { /* la ligne a été redessinée */ } } }
  const fPx = n => n == null ? '—' : nf(n, 2) + ' €';
  const fSg = (n, d) => n == null ? '—' : (n > 0 ? '+' : (n < 0 ? '−' : '')) + nf(Math.abs(n), d || 0) + ' %';
  /** Onglet 1 : la courbe du magasin face à la moyenne par magasin du réseau, semaine par semaine. */
  function ficheCourbe(W, eu) {
    const M = W.magasin || [], R = W.reseau || [], n = M.length, lib = W.tranches || [];
    const iEnC = n - 1, Wd = 700, Hd = 270, m = { l: eu ? 46 : 34, r: eu ? 104 : 92, t: 14, b: 34 };
    const max = Math.max(1, ...M.map(v => v || 0), ...R.map(v => v || 0));
    // En euros (une catégorie, 10/10/2026), un pas rond pour six à dix lignes : 1, 2 ou 5 × 10ⁿ.
    const pasRond = v => { const r = v / 6, e = Math.pow(10, Math.floor(Math.log10(r))), q = r / e; return (q <= 1 ? 1 : q <= 2 ? 2 : q <= 5 ? 5 : 10) * e; };
    const pas = eu ? pasRond(max) : (max > 60 ? 20 : (max > 30 ? 10 : (max > 12 ? 5 : 2))), haut = Math.ceil(max / pas) * pas;
    const vU = v => eu ? fN(v) + ' €' : fN(v);
    const y = v => m.t + (Hd - m.t - m.b) * (1 - v / haut), cx = k => m.l + (Wd - m.l - m.r) * k / Math.max(1, n - 1);
    let g = '';
    for (let v = 0; v <= haut; v += pas) { g += `<line x1="${m.l}" x2="${Wd - m.r}" y1="${y(v).toFixed(1)}" y2="${y(v).toFixed(1)}" stroke="#ece6de"/><text class="ax" x="${m.l - 7}" y="${(y(v) + 3.5).toFixed(1)}" text-anchor="end">${eu ? fN(v) : v}</text>`; }
    const date = k => { const b = (W.bornes || [])[k]; return b ? b[0].slice(8, 10) + '/' + b[0].slice(5, 7) : ''; };
    g += lib.map((l, k) => (k % 2 === 0 || k === n - 1) ? `<text class="ax" x="${cx(k).toFixed(1)}" y="${Hd - 18}" text-anchor="middle">${esc(l)}</text><text class="ax" x="${cx(k).toFixed(1)}" y="${Hd - 5}" text-anchor="middle" style="font-size:9.5px">${k === iEnC ? (W.jours ? W.jours[k] + ' j' : 'en cours') : date(k)}</text>` : '').join('');
    const ligne = (S2, coul, nom, pts) => { const P = S2.map((v, k) => v == null ? null : [cx(k), y(v)]); const ok = P.map((p, k) => [p, k]).filter(x => x[0]);
      const plein = ok.filter(x => x[1] < iEnC).map(x => x[0]), fin = ok.filter(x => x[1] >= iEnC - 1).map(x => x[0]);
      return `<polyline points="${plein.map(p => p.map(z => z.toFixed(1)).join(',')).join(' ')}" fill="none" stroke="${coul}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>`
        + (fin.length === 2 ? `<polyline points="${fin.map(p => p.map(z => z.toFixed(1)).join(',')).join(' ')}" fill="none" stroke="${coul}" stroke-width="2" stroke-dasharray="1 4" stroke-linecap="round"/>` : '')
        + (pts ? P.map((p, k) => p ? `<circle cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="4" fill="${coul}" stroke="#fff" stroke-width="2"><title>${esc(lib[k])} (${k === iEnC ? 'en cours' : date(k)}) · ${nom} ${eu ? vU(S2[k]) : nf(S2[k], 1)}</title></circle>` : '').join('') : ''); };
    // Les étiquettes de la dernière semaine close, à droite, écartées si elles se touchent.
    const k1 = Math.max(0, n - 2); let yM = y(M[k1] || 0) + 4, yR = y(R[k1] || 0) + 4;
    if (Math.abs(yM - yR) < 14) { const mid = (yM + yR) / 2, sgn = yM <= yR ? -1 : 1; yM = mid + 7 * sgn; yR = mid - 7 * sgn; }
    const fin = `<text class="lab" x="${Wd - m.r + 10}" y="${yM.toFixed(1)}">${esc(nomShop().replace(/^.* - /, ''))} ${vU(M[k1])}</text><text class="ax" x="${Wd - m.r + 10}" y="${yR.toFixed(1)}" style="font-weight:600">réseau ${eu ? vU(R[k1] || 0) : nf(R[k1] || 0, 0)}</text><text class="ax" x="${Wd - m.r + 10}" y="${(Math.max(yM, yR) + 13).toFixed(1)}" style="font-size:9.5px">en ${esc(lib[k1] || '')}</text>`;
    return `<svg class="fi-graph" viewBox="0 0 ${Wd} ${Hd}" role="img" aria-label="Ventes par semaine face à la moyenne du réseau">${g}${ligne(R, '#eb6834', 'moyenne par magasin du réseau', true)}${ligne(M, '#2a78d6', 'le magasin', true)}${fin}</svg>`;
  }
  /** Onglet 2 : le nuage prix × volume, le seul point du magasin, le prix réseau et le volume moyen en repères. */
  function ficheNuage(P) {
    const me = P.magasin, re = P.reseau, Wd = 620, Hd = 320, m = { l: 50, r: 22, t: 22, b: 44 };
    const x0 = Math.floor((Math.min(re.min, me.p) - .08) * 10) / 10, x1 = Math.ceil((Math.max(re.max, me.p) + .08) * 10) / 10;
    const y1 = Math.max(5, Math.ceil((Math.max(re.volMax, me.v10k) * 1.15) / 5) * 5), py = y1 > 60 ? 20 : (y1 > 30 ? 10 : 5);
    const X = v => m.l + (Wd - m.l - m.r) * (v - x0) / Math.max(.01, x1 - x0), Y = v => m.t + (Hd - m.t - m.b) * (1 - v / y1);
    let g = '';
    for (let v = 0; v <= y1; v += py) { g += `<line x1="${m.l}" x2="${Wd - m.r}" y1="${Y(v).toFixed(1)}" y2="${Y(v).toFixed(1)}" stroke="#ece6de"/><text class="ax" x="${m.l - 8}" y="${(Y(v) + 3.5).toFixed(1)}" text-anchor="end">${v}</text>`; }
    const px = (x1 - x0) > 1.2 ? .5 : ((x1 - x0) > .6 ? .2 : .1);
    for (let v = Math.ceil(x0 / px) * px; v <= x1 + 1e-9; v += px) { g += `<text class="ax" x="${X(v).toFixed(1)}" y="${Hd - 26}" text-anchor="middle">${nf(v, 2)} €</text>`; }
    g += `<line x1="${X(re.med).toFixed(1)}" x2="${X(re.med).toFixed(1)}" y1="${m.t}" y2="${Hd - m.b}" stroke="#5f5a54"/><text class="ax" x="${(X(re.med) + 5).toFixed(1)}" y="${m.t + 10}">prix réseau ${fPx(re.med)}</text>`;
    g += `<line x1="${m.l}" x2="${Wd - m.r}" y1="${Y(re.volMoyen).toFixed(1)}" y2="${Y(re.volMoyen).toFixed(1)}" stroke="#5f5a54"/><text class="ax" x="${Wd - m.r}" y="${(Y(re.volMoyen) - 6).toFixed(1)}" text-anchor="end">volume moyen du réseau ${nf(re.volMoyen, 1)}</text>`;
    const q = (x, yy, t, a) => `<text x="${x}" y="${yy}" text-anchor="${a}" style="font:600 10px var(--font-ui);letter-spacing:.05em;text-transform:uppercase;fill:#b3aca2">${t}</text>`;
    g += q(m.l + 8, m.t + 26, 'moins cher · vend plus', 'start') + q(Wd - m.r - 8, m.t + 26, 'plus cher · vend plus', 'end') + q(m.l + 8, Hd - m.b - 8, 'moins cher · vend moins', 'start') + q(Wd - m.r - 8, Hd - m.b - 8, 'plus cher · vend moins', 'end');
    // La plage du réseau, sans magasin : du prix le plus bas au plus haut, du volume le plus bas au plus haut.
    g += `<rect x="${X(re.min).toFixed(1)}" y="${Y(re.volMax).toFixed(1)}" width="${Math.max(2, X(re.max) - X(re.min)).toFixed(1)}" height="${Math.max(2, Y(re.volMin) - Y(re.volMax)).toFixed(1)}" fill="#eb6834" opacity=".07" rx="6"><title>la plage du réseau : prix de ${fPx(re.min)} à ${fPx(re.max)}, volume de ${nf(re.volMin, 1)} à ${nf(re.volMax, 1)}</title></rect>`;
    const nomM = esc(nomShop().replace(/^.* - /, '')), t = `${nomM} · ${fPx(me.p)}`, lg = t.length * 6.4, xd = X(me.p) + 15, gauche = xd + lg > Wd - m.r;
    g += `<circle cx="${X(me.p).toFixed(1)}" cy="${Y(me.v10k).toFixed(1)}" r="9" fill="#2a78d6" stroke="#fff" stroke-width="2"><title>${nomM} · ${fPx(me.p)} (${fSg(me.ec, 1)} face au prix réseau) · ${nf(me.v10k, 1)} pièces pour 10 000 € de chiffre (${fSg(me.rel)} face aux autres) · ${fN(me.q)} pièces</title></circle><text class="lab" x="${(gauche ? X(me.p) - 15 : xd).toFixed(1)}" y="${(Y(me.v10k) + 4).toFixed(1)}" text-anchor="${gauche ? 'end' : 'start'}">${t}</text>`;
    g += `<text class="ax" x="${(m.l + Wd - m.r) / 2}" y="${Hd - 6}" text-anchor="middle" style="font-weight:600">prix encaissé →</text><text class="ax" transform="translate(13 ${(m.t + Hd - m.b) / 2}) rotate(-90)" text-anchor="middle" style="font-weight:600">pièces pour 10 000 € de chiffre →</text>`;
    return `<svg class="fi-graph" viewBox="0 0 ${Wd} ${Hd}" role="img" aria-label="Prix encaissé face au volume">${g}</svg>`;
  }
  /* ── Onglet « Recette & marge » (demande du 08/10/2026) ─────────────────────────────────────────
   * La pièce : prix encaissé, coût de recette et marge viennent de la ligne cliquée (ce que les tickets ont
   * gravé) ; la recette ligne par ligne vient de /analyse/produits/recette (copie locale du panel) ; la
   * catégorie et le magasin du jour des stats déjà lues ; le prix réseau de la fiche ; les seuils du P&L. */
  const FI_ZONES = { ko: 100 / 60, att: 2.5 };   // marge < 40 % ⇔ coef < 1,67 ; < 60 % ⇔ < 2,5 (échelle MARGES)
  function ficheRecette(F, d, R, errR) {
    const fX = n => n == null ? '—' : '× ' + nf(n, 2), fPt = n => n == null ? '—' : nf(n, 2) + ' €';
    const q = F.q || 0, prix = q > 0 && F.v != null ? F.v / q : null, matT = q > 0 && F.c != null ? F.c / q : null;
    // Le coût de recette : celui que le panel calcule aujourd'hui pour ce magasin (il bouge à chaque édition de prix) ;
    // à défaut, celui gravé avec les tickets du jour.
    const matA = R && R.api && R.total > 0 ? R.total : null, mat = matA != null ? matA : matT;
    const mb = prix != null && mat != null ? prix - mat : null, taux = mb != null && prix > 0 ? 100 * mb / prix : null, coef = prix != null && mat > 0 ? prix / mat : null;
    const JD = S.aux['jourM|' + S.date], se = Object.assign({ food: 32, labour: 33, overhead: 13.5 }, JD && JD.seuils ? JD.seuils : {});
    const obj = 100 / (se.food || 32);
    const niv = c => c == null ? 'mu' : (c < FI_ZONES.ko ? 'ko' : (c < FI_ZONES.att ? 'att' : 'ok'));
    const NIV = { ko: 'sous le coût acceptable', att: 'à surveiller', ok: 'dans le vert', mu: 'coût matière inconnu' };
    const ST = S.st[cleSt()], cats = ST && Array.isArray(ST.categories) ? ST.categories : [];
    const cat = cats.find(c => c.nom === F.cat) || null;
    const catCoef = cat && cat.c > 0 ? cat.v / cat.c : null, catTaux = cat && cat.taux != null ? cat.taux : null;
    // Le magasin du jour : la somme des catégories chiffrées (le même total que le pied de l'accordéon).
    const catsC = cats.filter(c => c.c != null && c.v > 0), caT = catsC.reduce((a, c) => a + c.v, 0), cT = catsC.reduce((a, c) => a + c.c, 0);
    const T = caT > 0 ? { ca: caT, mat: cT } : null, magCoef = T && T.mat > 0 ? T.ca / T.mat : null, magTaux = T && T.mat != null ? 100 * (T.ca - T.mat) / T.ca : null;
    const P = d && d.prix ? d.prix : null, med = P && P.reseau && P.reseau.med ? P.reseau.med : null;
    const G0 = 1, G1 = 4, pct = v => Math.max(0, Math.min(100, 100 * (v - G0) / (G1 - G0)));
    const reperes = [catCoef != null ? { v: catCoef, lib: 'sa catégorie', sous: fX(catCoef) } : null, magCoef != null ? { v: magCoef, lib: 'le magasin aujourd’hui', sous: fX(magCoef) } : null,
      { v: obj, lib: 'objectif', sous: fX(obj) + ' · matière ' + fP0(se.food), cls: 'obj' }].filter(Boolean).sort((a, b) => a.v - b.v);
    // Deux repères trop proches se lisent sur deux étages (le second monte d'un cran), surtout au téléphone.
    const serre = typeof matchMedia === 'function' && matchMedia('(max-width:700px)').matches ? 27 : 17;
    reperes.forEach((r, i) => { if (i > 0 && pct(r.v) - pct(reperes[i - 1].v) < serre && !/\bh2\b/.test(reperes[i - 1].cls || '')) { r.cls = ((r.cls || '') + ' h2').trim(); } });
    const etage = reperes.some(r => /\bh2\b/.test(r.cls || ''));
    // Le prix pour y arriver : chaque palier de la jauge traduit en prix à pratiquer, arrondi aux 5 centimes supérieurs.
    const a5 = p => Math.ceil(p * 20 - 1e-9) / 20;
    const fD = v => (v >= 0 ? '+ ' : '− ') + nf(Math.abs(v), 2) + ' €', fPs = v => (v >= 0 ? '+' : '−') + nf(Math.abs(v), 0) + ' %';
    const paliers = mat > 0 && prix != null ? [{ lib: 'marge 40 %', coef: FI_ZONES.ko }, catCoef != null ? { lib: 'sa catégorie', coef: catCoef } : null,
      med != null ? { lib: 'le prix réseau', coef: med / mat, fixe: med } : null, { lib: 'marge 60 %', coef: FI_ZONES.att }, magCoef != null ? { lib: 'le magasin aujourd’hui', coef: magCoef } : null,
      { lib: 'objectif · matière ' + fP0(se.food), coef: obj }].filter(Boolean).map(p => Object.assign(p, { prix: p.fixe != null ? p.fixe : a5(mat * p.coef) })).sort((a, b) => a.coef - b.coef) : [];
    // La simulation : un autre prix, choisi dans les paliers ou tapé, et la pièce recalculée à ce prix.
    const sim = F.simPrix != null && F.simPrix > 0 && mat > 0 && prix != null ? F.simPrix : null;
    const sCoef = sim != null ? sim / mat : null, sTaux = sim != null ? 100 * (sim - mat) / sim : null;
    const lignes = R && Array.isArray(R.lignes) ? R.lignes : [];
    // Les parts se lisent sur le coût gravé de la pièce (les lignes peuvent être incomplètes), sinon sur le total des lignes.
    const totalL = R && R.total != null ? R.total : null, baseL = totalL > 0 ? totalL : (mat > 0 ? mat : 0);
    const top = lignes.filter(l => l.cout != null).sort((a, b) => b.cout - a.cout)[0] || null;
    const topPart = top && baseL > 0 ? 100 * top.cout / baseL : null;
    // 1. La jauge : le prix de vente se tape, le coefficient se recalcule et le repère se déplace.
    const simOn = sim != null && Math.abs(sim - prix) >= 0.005, pV = simOn ? sim : prix, cV = simOn ? sCoef : coef, tV = simOn ? sTaux : taux;
    const saisie = F.simSaisie != null ? F.simSaisie : (pV != null ? nf(pV, 2) : '');
    const champ = prix != null && mat > 0 ? `<div class="fi-rsc"><span class="k">Prix de vente</span><span class="fi-rsimc"><button type="button" data-fsimpas="-0.05" aria-label="moins 5 centimes">−</button><input type="text" inputmode="decimal" data-fsiminput="1" value="${esc(saisie)}" aria-label="prix de vente"><b>€</b><button type="button" data-fsimpas="0.05" aria-label="plus 5 centimes">+</button></span>${simOn ? `<small>au lieu de ${fPt(prix)} encaissé · <button type="button" class="fi-rlien" data-fsimx="1">remettre</button></small>` : `<small>le prix encaissé ${S.date === AUJ ? 'aujourd’hui' : 'ce jour'} · tapez un autre prix pour voir le coefficient</small>`}</div>` : '';
    let h = `<section class="fi-rc fi-rjauge"><div class="fi-rct"><span class="fi-rh">Le coefficient</span><span class="fi-note">ce que le prix fait de la matière : prix de vente ÷ coût de recette${mat != null ? ' (' + fPt(mat) + ')' : ''}</span></div>
      <div class="fi-rjg"><div class="fi-rjn ${niv(cV)}">${champ}<b>${fX(cV)}</b><span>${NIV[niv(cV)]}</span><small>${tV != null ? 'marge brute ' + fP0(tV) + ' · matière ' + fP0(100 - tV) + ' du prix' : 'le coût de recette de cette pièce n’est pas gravé'}</small></div>
        <div class="fi-rjb${etage ? ' etage' : ''}${simOn ? ' sim' : ''}"><div class="fi-rband"><i class="ko" style="width:${pct(FI_ZONES.ko).toFixed(1)}%"></i><i class="att" style="width:${(pct(FI_ZONES.att) - pct(FI_ZONES.ko)).toFixed(1)}%"></i><i class="ok" style="width:${(100 - pct(FI_ZONES.att)).toFixed(1)}%"></i>
          ${reperes.map(r => `<span class="fi-rrep ${r.cls || ''}" style="left:${pct(r.v).toFixed(1)}%"><i></i><em>${esc(r.lib)}<small>${esc(r.sous)}</small></em></span>`).join('')}
          ${cV != null ? `<span class="fi-rmoi ${niv(cV)}" style="left:${pct(cV).toFixed(1)}%"><i></i><em>${simOn ? 'à ' + fPt(sim) : 'ce produit'}<small>${fX(cV)}</small></em></span>` : ''}
          ${simOn ? `<span class="fi-rmoi enc" style="left:${pct(coef).toFixed(1)}%"><i></i><em>encaissé ${fPt(prix)}<small>${fX(coef)}</small></em></span>` : ''}</div>
          <div class="fi-raxe"><span>× 1</span><span>× ${nf(FI_ZONES.ko, 2)} · marge 40 %</span><span>× 2,5 · marge 60 %</span><span>× 4</span></div>
          ${paliers.length ? `<div class="fi-rpcs"><span class="k">Le prix pour y arriver</span>${paliers.map(p => `<button type="button" class="fi-rpc${Math.abs(p.prix - pV) < 0.005 ? ' on' : ''}${p.coef <= coef + 1e-9 ? ' atteint' : ''}" data-fsim="${p.prix.toFixed(2)}" title="${esc(p.lib)} : ${fX(p.coef)} · ${fD(p.prix - prix)} face au prix encaissé${p.coef <= coef + 1e-9 ? ' · déjà atteint' : ''}">${esc(p.lib)} <b>${fPt(p.prix)}</b></button>`).join('')}</div>` : ''}</div></div>`;
    if (simOn) {
      const sLab = sim * se.labour / 100, sOh = sim * se.overhead / 100, sNet = sim - mat - sLab - sOh, nv = niv(sCoef);
      h += `<div class="fi-rverdict ${nv}">À <b>${fPt(sim)}</b>, la pièce passe à <b>${fX(sCoef)}</b> : ${NIV[nv]}. Marge brute <b>${fP0(sTaux)}</b>, résultat par pièce <b>${sNet < 0 ? '− ' : ''}${fPt(Math.abs(sNet))}</b>.${med != null ? ` ${fPs(100 * (sim - med) / med)} face au prix réseau (${fPt(med)}).` : ''}${q > 0 ? ` Sur les ${fN(q)} vendu${q >= 2 ? 's' : ''} ${S.date === AUJ ? 'aujourd’hui' : 'ce jour'} : ${fD(q * (sim - prix))} de chiffre, à volume égal.` : ''}</div>`;
    } else if (coef != null) {
      h += `<div class="fi-rverdict ${niv(coef)}"><b>${niv(coef) === 'ok' ? 'Dans le vert.' : 'Sous l’objectif.'}</b> La matière prend ${fP0(100 - taux)} du prix.${niv(coef) !== 'ok' ? ` Pour entrer dans le vert (× 2,5), il faudrait vendre la pièce <b>${fPt(mat * 2.5)}</b> au lieu de ${fPt(prix)}, ou ramener la matière à <b>${fPt(prix / 2.5)}</b> au lieu de ${fPt(mat)}.` : ''}${top && topPart != null ? ` ${esc(top.nom)} pèse <b>${fP0(topPart)}</b> du coût${niv(coef) !== 'ok' ? ' : c’est là que ça se joue' : ''}.` : ''}</div>`;
    }
    h += '</section>';
    // 2. La recette.
    let rec;
    if (errR && !R) { rec = `<div class="fi-msg">Lecture impossible : ${esc(errR)}</div>`; }
    else if (!R) { rec = '<div class="fi-msg"><div class="db-sk" style="width:70%;margin:6px 0"></div><div class="db-sk" style="width:90%;height:120px;margin:10px 0"></div></div>'; }
    else if (R.indispo || R.sansRecette) { rec = `<div class="fi-msg">${esc(R.motif || 'recette indisponible')}${mat != null ? ` · le coût de recette de la pièce, ${fPt(mat)}, reste connu par les tickets.` : ''}</div>`; }
    else if (!lignes.length) { rec = `<div class="fi-msg">La recette « ${esc(R.recette && R.recette.nom ? R.recette.nom : '')} » n’a pas de ligne lisible dans la copie locale.</div>`; }
    else {
      const fQ = l => l.qte == null ? '—' : nf(l.qte, Number.isInteger(+l.qte) ? 0 : (l.qte >= 10 ? 1 : 2)) + (l.unite ? ' ' + esc(l.unite) : '');
      rec = `<table class="fi-rtab"><thead><tr><th>Ingrédient</th><th>Quantité</th><th>Coût</th><th class="p">Part du coût</th></tr></thead><tbody>
        ${lignes.map((l, i) => { const cl = l.type === 'matiere' && l.id != null, on = cl && F.mat === i; return `<tr class="${top && l === top ? 'top' : ''}${cl ? ' clic' : ''}${on ? ' on' : ''}"${cl ? ` data-fmat="${i}" tabindex="0" role="button" aria-expanded="${on ? 'true' : 'false'}" title="le prix de cette matière : ce magasin, le réseau, le fournisseur"` : ''}><td><b>${esc(l.nom)}</b>${l.sous ? `<small>${esc(l.sous)}</small>` : (l.cat && l.type !== 'recette' ? `<small>${esc(l.cat)}</small>` : '')}</td><td>${fQ(l)}</td><td${l.prixUnite != null ? ` title="${nf(l.prixUnite, l.prixUnite < 0.1 ? 4 : 2)} € / ${esc(l.prixParUnite || l.unite || 'unité')}${l.prixSource ? ' · prix ' + (l.prixSource === 'magasin' ? 'du magasin' : 'd’un autre magasin') : ''}"` : ''}>${l.cout != null ? fPt(l.cout) : `<span class="mu" title="${esc(l.motif || 'pas de prix pour cette matière')}">—</span>`}</td><td class="p">${l.cout != null && baseL > 0 ? `<i style="width:${Math.min(100, 100 * l.cout / baseL).toFixed(1)}%"></i><span>${fP0(100 * l.cout / baseL)}</span>` : ''}</td></tr>${on ? ficheMatiere(l, S.aux[cleMat(l.id)], S.err[cleMat(l.id)]) : ''}`; }).join('')}
        </tbody><tfoot><tr><td>${R.api ? 'Coût de recette aujourd’hui' : 'Lignes chiffrées'}${R.sansPrix ? `<small>${R.sansPrix} sans prix</small>` : ''}</td><td></td><td>${totalL != null ? fPt(totalL) : '—'}</td><td class="p">${totalL != null && matT != null && Math.abs(totalL - matT) > 0.05 ? `<small>tickets du jour ${fPt(matT)}</small>` : ''}</td></tr></tfoot></table>
        <div class="fi-note fi-rnote">${esc(R.source || '')}${mat == null && R.cout && R.cout.net != null ? ` · coût de recette ${fPt(R.cout.net)} (${esc(R.cout.source || '')})` : ''}${R.recette && R.recette.rendement && R.recette.rendement !== 1 ? ` · rendement ${nf(R.recette.rendement, 2)}` : ''}${lignes.some(l => l.prixSource && l.prixSource !== 'magasin') ? ' · les prix sans valeur pour ce magasin viennent d’un autre magasin' : ''}</div>`;
    }
    const titreRec = `<div class="fi-rct"><span class="fi-rh">La recette, pièce par pièce</span><span class="fi-note">${matA != null ? 'coût de recette ' + fPt(matA) + ' la pièce, aux prix du panel aujourd’hui' + (matT != null ? ' · ' + fPt(matT) + ' gravé avec les tickets du jour' : '') : (mat != null ? 'coût matière ' + fPt(mat) + ' la pièce, gravé avec les tickets' : 'coût de la pièce inconnu')}</span></div>`;
    // 3. La marge brute en %, seule (10/10/2026) : la cascade pièce par pièce et le « Face à » sont retirés.
    let marge;
    if (prix == null || mat == null) { marge = `<div class="fi-msg">Sans coût de recette gravé, pas de marge brute.</div>`; }
    else {
      // Au prix simulé quand la simulation est ouverte, sinon au prix encaissé.
      const pS = sim != null ? sim : prix, tauxS = 100 * (pS - mat) / pS;
      marge = `<div class="fi-rmb${sim != null ? ' sim' : ''}"><b class="${tauxS >= 100 - se.food ? 'ok' : (tauxS >= 100 - se.food - 8 ? 'att' : 'ko')}">${fP0(tauxS)}</b><small>${sim != null ? 'au prix testé ' + fPt(sim) : 'au prix encaissé ' + fPt(prix)}</small></div>`;
    }
    return `<div class="fi-barre"><span class="fi-note">une pièce vendue ${S.date === AUJ ? 'aujourd’hui' : 'ce jour'} · ${matA != null ? 'coût de recette aux prix du panel aujourd’hui, relu à chaque ouverture' : 'coût de recette net gravé avec les tickets'}</span></div>
      ${h}<div class="fi-rdeux mb"><section class="fi-rc">${titreRec}${rec}</section><section class="fi-rc"><div class="fi-rct"><span class="fi-rh">Marge brute</span></div>${marge}</section></div>`;
  }
  let ficheEnRendu = false;   // vrai pendant le redessin : le blur que Chrome lance en retirant le champ n'est pas une sortie du champ
  /** La fiche d'une matière première, dépliée sous sa ligne de recette : lue sur l'API du panel seulement. */
  function ficheMatiere(l, M, err) {
    const fDj = s => s && s.length >= 10 ? s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4) : '—';
    const fS = v => (v > 0 ? '+' : (v < 0 ? '−' : '')) + nf(Math.abs(v), 1) + ' %', faceA = n => !n ? 'face au repère' : (/^médiane/.test(n) ? 'face à la ' + n : 'face au ' + n);
    let corps;
    if (err && !M) { corps = `<div class="fi-msg">Lecture impossible : ${esc(err)}</div>`; }
    else if (!M) { corps = '<div class="fi-msg"><div class="db-sk" style="width:60%;margin:4px 0"></div><div class="db-sk" style="width:95%;height:64px;margin:8px 0"></div></div>'; }
    else {
      const par = M.par || 'unité', fPu = v => v == null ? '—' : nf(v, v < 1 ? 4 : 2) + ' € / ' + par;
      const mag = M.magasin || {}, st = M.stats || { n: 0 }, F0 = (M.fournisseurs || [])[0] || null;
      const enCours = !!S.enCours[cleMat(l.id)], luLe = M.lu && M.lu.magasin ? new Date(M.lu.magasin) : null;
      const id = `<div class="fi-rdh"><span><b>${esc(M.nom)}</b>${M.cat ? ' · ' + esc(M.cat) : ''} · prix nets par ${esc(par)}${M.tva != null ? ' · TVA ' + nf(M.tva, 0) + ' %' : ''}${M.sourceCentrale ? ' · achat à la centrale' : (M.sourceType === 'OPEN' ? ' · achat libre' : '')}</span>
        <span class="fi-rdlu">${enCours ? 'relecture du panel…' : (luLe && !isNaN(luLe) ? 'panel lu à ' + String(luLe.getHours()).padStart(2, '0') + ':' + String(luLe.getMinutes()).padStart(2, '0') : '')} <button type="button" class="fi-rlien" data-fmatrelire="${esc(l.id)}"${enCours ? ' disabled' : ''} title="relire l’API du panel maintenant (ce magasin est relu toutes les ${M.lu && M.lu.minutesMagasin ? M.lu.minutesMagasin : 10} minutes, le réseau et les fournisseurs toutes les 24 h)">Relire</button></span></div>`;
      // La recette ci-dessus a été chiffrée avec la copie locale du panel : quand l'API dit un autre prix, on le dit.
      const copie = l.prixUnite != null && M.facteur ? l.prixUnite * M.facteur : null;
      const note = copie != null && mag.prix != null && Math.abs(copie - mag.prix) / mag.prix > 0.01
        ? `<div class="fi-rdnote">La recette ci-dessus a été chiffrée à <b>${fPu(copie)}</b>${l.prixSource && l.prixSource !== 'magasin' ? ' (prix d’un autre magasin)' : ''} ; l’API du panel dit maintenant <b>${fPu(mag.prix)}</b> pour ce magasin : rouvrez le produit pour la recalculer.</div>`
        : (copie != null && mag.prix == null && l.prixSource && l.prixSource !== 'magasin' ? `<div class="fi-rdnote">La recette ci-dessus prend ${fPu(copie)}, le prix médian des autres magasins : ce magasin n’a pas de prix pour cette matière au panel.</div>` : '');
      const cles = `<div class="fi-rdg">
        <div><span class="k">${esc(mag.court || 'ce magasin')} · ce magasin</span><b>${mag.prix != null ? fPu(mag.prix) : 'pas de prix'}</b><small>${mag.prix == null ? (mag.absente ? 'matière absente de ce magasin' : 'la recette prend le prix d’un autre magasin') : (mag.ecart != null && M.repereNom ? fS(mag.ecart) + ' ' + esc(faceA(M.repereNom)) : '')}</small></div>
        <div><span class="k">Prix conseillé</span><b>${M.conseille != null ? fPu(M.conseille) : '—'}</b><small>${M.conseille != null ? 'porté par le panel' : 'le panel n’en porte pas pour cette matière'}</small></div>
        <div><span class="k">Réseau</span><b>${st.n ? (st.min === st.max ? fPu(st.med) : nf(st.min, st.min < 1 ? 4 : 2) + ' à ' + nf(st.max, st.max < 1 ? 4 : 2) + ' € / ' + esc(par)) : '—'}</b><small>${st.n} magasin${st.n > 1 ? 's' : ''} avec un prix${st.n > 1 && st.min !== st.max ? ' · médiane ' + nf(st.med, st.med < 1 ? 4 : 2) + ' €' : ''}</small></div>
        <div><span class="k">Fournisseur</span><b>${F0 ? esc(F0.nom) : 'aucun'}</b><small>${F0 ? (F0.centrale ? 'centrale' : esc(F0.typeNom || F0.type || 'fournisseur')) + (F0.sku ? ' · réf. ' + esc(F0.sku) : '') : 'aucun fournisseur du panel ne porte cette matière'}</small></div></div>`;
      const res = `<table class="fi-rdp"><thead><tr><th>Magasin</th><th>Prix / ${esc(par)}</th><th>${esc(faceA(M.repereNom).replace(/^face/, 'Face'))}</th></tr></thead><tbody>${(M.reseau || []).map(r => `<tr class="${r.ceMagasin ? 'moi' : ''}"><td>${esc(r.court)}${r.ceMagasin ? ' <small>ce magasin</small>' : ''}</td><td>${r.prix != null ? fPu(r.prix) : (r.lu ? '<span class="mu">pas de prix</span>' : '<span class="mu">non lu</span>')}</td><td>${r.ecart != null ? fS(r.ecart) : ''}</td></tr>`).join('')}</tbody></table>`;
      const four = (M.fournisseurs || []).length ? `<table class="fi-rdp four"><thead><tr><th>Fournisseur</th><th>Colis</th><th>Prix / ${esc(par)}</th><th>Valable depuis</th><th>Face au magasin</th></tr></thead><tbody>${M.fournisseurs.map(f => `<tr><td><b>${esc(f.nom)}</b><small>${f.centrale ? 'centrale' : esc(f.typeNom || f.type || 'fournisseur')}${f.sku ? ' · réf. ' + esc(f.sku) : ''}${f.nomCatalogue && f.nomCatalogue !== M.nom ? ' · ' + esc(f.nomCatalogue) : ''}</small></td><td data-l="colis">${f.colis ? nf(f.colis.taille || 1, 0) + ' ' + esc(f.colis.unite || '') + (f.colis.parColis > 1 ? ' × ' + nf(f.colis.parColis, 0) + ' ' + esc(M.uniteBase || '') : '') + ' · ' + nf(f.colis.prix, 2) + ' €' : (f.listeLue ? '<span class="mu">pas dans sa liste de prix</span>' : '<span class="mu">liste de prix non lue</span>')}</td><td data-l="prix / ${esc(par)}">${f.parUnite != null ? fPu(f.parUnite) : '—'}</td><td data-l="valable depuis">${f.depuis ? fDj(f.depuis) : '—'}${f.prochain ? `<small>puis ${fPu(f.prochain.parUnite)} dès le ${fDj(f.prochain.depuis)}</small>` : ''}</td><td data-l="face au magasin">${f.ecart != null ? fS(f.ecart) : '—'}</td></tr>`).join('')}</tbody></table>` : '';
      corps = id + note + cles + `<div class="fi-rdd">${res}${four}</div><div class="fi-note">${esc(M.source || '')}</div>`;
    }
    return `<tr class="fi-rdet"><td colspan="4"><div class="fi-rdf">${corps}</div></td></tr>`;
  }
  function ficheRendre() {
    let box = document.getElementById('db-fiche');
    if (!S.fiche) { if (box) { box.innerHTML = ''; } document.documentElement.classList.remove('db-fiche-ouverte'); return; }
    if (!box) { box = document.createElement('div'); box.id = 'db-fiche'; document.body.appendChild(box); }
    document.documentElement.classList.add('db-fiche-ouverte');
    if (S.fiche.c12) { c12Rendre(box); return; }
    const F = S.fiche, k = cleFiche(), d = S.aux[k], err = S.err[k];
    const mag = nomShop(), court = mag.replace(/^.* - /, '');
    const chips = [F.q != null ? `<span class="fi-chip">${S.date === AUJ ? 'aujourd’hui' : 'ce jour'} <b>${fN(F.q)} vendu${F.q >= 2 ? 's' : ''}</b>${F.v != null ? ' · ' + fE(F.v) : ''}</span>` : '',
      d && d.prix ? `<span class="fi-chip">prix encaissé <b>${fPx(d.prix.magasin.p)}</b></span><span class="fi-chip">prix réseau <b>${fPx(d.prix.reseau.med)}</b></span>` : '',
      (() => { const R0 = S.aux[cleRecette()], pq = F.q > 0 && F.v != null ? F.v / F.q : null, m0 = R0 && R0.api && R0.total > 0 ? R0.total : (F.q > 0 && F.c > 0 ? F.c / F.q : null);
        if (pq == null || m0 == null || !(pq > 0)) { return F.taux != null ? `<span class="fi-chip">marge brute <b>${fP0(F.taux)}</b></span>` : ''; }
        const cf = pq / m0; return `<span class="fi-chip">marge brute <b>${fP0(100 * (pq - m0) / pq)}</b></span><span class="fi-chip fi-rchip ${cf < FI_ZONES.ko ? 'ko' : (cf < FI_ZONES.att ? 'att' : 'ok')}">coefficient <b>× ${nf(cf, 2)}</b></span>`; })(),
      F.onglet === 3 && F.simPrix != null && F.simPrix > 0 ? `<span class="fi-chip fi-rchip sim">prix testé <b>${nf(F.simPrix, 2)} €</b></span>` : ''].join('');
    const ancienne = box.querySelector('.fi-modale'), defil = ancienne ? ancienne.scrollTop : 0;
    // L'élément qui a le focus dans la modale (le champ du prix, un onglet, un bouton) le retrouve après le redessin,
    // que le redessin vienne d'un clic ou du rafraîchissement périodique de la page.
    const act = document.activeElement, focSel = (() => { if (!act || !box.contains(act)) { return null; }
      for (const k of ['fsiminput', 'fsimpas', 'fong', 'fsim', 'fmois', 'fmat']) { if (act.dataset && act.dataset[k] != null) { return `[data-${k}="${act.dataset[k]}"]`; } }
      return act.classList.contains('fi-x') ? '.fi-x' : null; })(), focPos = focSel === '[data-fsiminput="1"]' ? act.selectionStart : null;
    ficheEnRendu = true;
    let corps;
    if (F.onglet === 3) { ficheLireRecette(); { const R0 = S.aux[cleRecette()], l0 = F.mat != null && R0 && Array.isArray(R0.lignes) ? R0.lignes[F.mat] : null; if (l0 && l0.id != null) { ficheLireMatiere(l0.id); } } corps = ficheRecette(F, d, S.aux[cleRecette()], S.err[cleRecette()]); }
    else if (err && !d) { corps = `<div class="fi-msg">Lecture impossible : ${esc(err)}</div>`; }
    else if (!d) { corps = '<div class="fi-msg"><div class="db-sk" style="width:60%;margin:6px 0"></div><div class="db-sk" style="width:85%;height:180px;margin:10px 0"></div></div>'; }
    else if (d.indispo) { corps = `<div class="fi-msg">${esc(d.motif || 'indisponible')}</div>`; }
    else if (F.onglet === 1) {
      const W = d.semaines, M = W.magasin || [], R = W.reseau || [], clos = M.slice(0, -1), clR = R.slice(0, -1);
      const tot = clos.reduce((a, v) => a + (v || 0), 0), totR = clR.reduce((a, v) => a + (v || 0), 0), moy = tot / Math.max(1, clos.length);
      const av = clos.slice(0, 6).reduce((a, v) => a + (v || 0), 0), ap = clos.slice(6).reduce((a, v) => a + (v || 0), 0), tend = av > 0 ? 100 * (ap - av) / av : null;
      const face = totR > 0 ? 100 * (tot - totR) / totR : null;
      corps = `<div class="fi-barre"><span class="fi-note">semaines du lundi au dimanche · la dernière est en cours (${W.jours ? W.jours[W.jours.length - 1] : '?'} j) · le réseau : la moyenne par magasin, sans magasin nommé</span></div>
        <div class="fi-deux"><div><div class="fi-leg"><span><i class="ln" style="background:#2a78d6"></i>${esc(court)}</span><span><i class="ln" style="background:#eb6834"></i>le réseau, moyenne par magasin</span></div>${ficheCourbe(W)}</div>
          <div class="fi-cote"><div class="k">${esc(court)}, 12 semaines</div>
            <div class="r"><span>Pièces</span><b>${fN(tot)}</b></div><div class="r"><span>Par semaine</span><b>${nf(moy, 1)}</b></div>
            <div class="r"><span>6 dernières face aux 6 d’avant</span><b class="${tend == null ? '' : (tend >= 0 ? 'up' : 'dn')}">${fSg(tend)}</b></div>
            <div class="sep"></div><div class="r"><span>Moyenne par magasin du réseau</span><b>${fN(totR)}</b></div><div class="r"><span>Face au réseau</span><b class="${face == null ? '' : (face >= 0 ? 'up' : 'dn')}">${fSg(face)}</b></div>
            <div class="fi-note">en pièces ; un grand magasin vend plus : la taille compte (onglet Prix, volume à taille égale)</div></div></div>
        <table class="fi-tab"><thead><tr><th>Semaine</th>${(W.tranches || []).map((l, i) => `<th>${esc(l)}${i === M.length - 1 ? '*' : ''}</th>`).join('')}</tr></thead><tbody>
          <tr class="moi"><td>${esc(court)}</td>${M.map(v => `<td>${v == null ? '—' : fN(v)}</td>`).join('')}</tr><tr><td>Réseau, moyenne</td>${R.map(v => `<td>${v == null ? '—' : nf(v, 0)}</td>`).join('')}</tr></tbody></table>
        <div class="fi-note">* en cours.</div>`;
    } else {
      const P = d.prix, seg = `<span class="fi-seg">${[[1, 'Dernier mois'], [3, '3 derniers mois'], [12, '12 mois']].map(([v, l]) => `<button type="button" data-fmois="${v}" class="${F.mois === v ? 'on' : ''}">${l}</button>`).join('')}</span>`;
      if (!P) { corps = `<div class="fi-barre">${seg}</div><div class="fi-msg">${esc(d.prixMotif || 'pas de prix comparable sur la période')}</div>`; }
      else { const me = P.magasin, re = P.reseau;
        corps = `<div class="fi-barre">${seg}<span class="fi-note">${esc(P.periode)} · prix encaissé = chiffre ÷ pièces, remises comprises · volume à taille égale = pièces pour 10 000 € de chiffre du magasin</span></div>
          <div class="fi-deux"><div><div class="fi-leg"><span><i class="pt"></i>${esc(court)}</span><span><i class="zn"></i>la plage du réseau : du plus bas au plus haut, en prix et en volume</span><span><i class="ln" style="background:#5f5a54;height:1px"></i>prix réseau et volume moyen</span></div>${ficheNuage(P)}</div>
            <div class="fi-cote"><div class="k">${esc(court)}, ${esc(P.periode)}</div>
              <div class="r"><span>Prix encaissé</span><b>${fPx(me.p)}</b></div><div class="r"><span>Prix réseau (médiane)</span><b>${fPx(re.med)}</b></div><div class="r"><span>Écart</span><b>${fSg(me.ec, 1)}</b></div>
              <div class="sep"></div><div class="r"><span>Pièces vendues</span><b>${fN(me.q)}</b></div><div class="r"><span>Pour 10 000 € de chiffre</span><b>${nf(me.v10k, 1)}</b></div><div class="r"><span>Face aux autres magasins</span><b class="${me.rel == null ? '' : (me.rel >= 0 ? 'up' : 'dn')}">${fSg(me.rel)}</b></div>
              <div class="sep"></div><div class="r"><span>Au prix réseau, par mois</span><b class="${me.auMed > 0 ? 'up' : ''}">${me.auMed > 0 ? '+' : (me.auMed < 0 ? '−' : '')}${fE(Math.abs(me.auMed))}</b></div>
              <div class="fi-note">à volume égal ; un prix plus haut peut faire vendre moins${me.peu ? ' · moins de 5 pièces sur la période : à lire avec prudence' : ''} · le réseau : ${re.magasins} magasins, prix de ${fPx(re.min)} à ${fPx(re.max)}</div></div></div>`; }
    }
    box.innerHTML = `<div class="fi-voile" data-ffermer="1"></div><div class="fi-modale" role="dialog" aria-modal="true" aria-label="${esc(F.nom)}">
      <div class="fi-hd"><div class="t"><h2>${esc(F.nom)}</h2><div class="s">${esc(d && d.cat ? d.cat + ' · ' : '')}${esc(mag)}</div><div class="fi-chips">${chips}</div></div><button type="button" class="fi-x" data-ffermer="1" aria-label="fermer">✕</button></div>
      <div class="fi-ong"><button type="button" data-fong="1" class="${F.onglet === 1 ? 'on' : ''}">Ventes · 12 semaines</button><button type="button" data-fong="2" class="${F.onglet === 2 ? 'on' : ''}">Prix face au réseau <small>volume × prix</small></button><button type="button" data-fong="3" class="${F.onglet === 3 ? 'on' : ''}">Recette & marge <small>coût, marge, coefficient</small></button></div>
      <div class="fi-bd">${corps}</div></div>`;
    if (defil) { const nm = box.querySelector('.fi-modale'); if (nm) { nm.scrollTop = defil; } }
    box.querySelectorAll('[data-ffermer]').forEach(b => b.addEventListener('click', ficheFermer));
    box.querySelectorAll('[data-fong]').forEach(b => b.addEventListener('click', () => { S.fiche.onglet = +b.dataset.fong; ficheRendre(); }));
    box.querySelectorAll('[data-fmois]').forEach(b => b.addEventListener('click', () => { S.fiche.mois = +b.dataset.fmois; ficheLire(); ficheRendre(); }));
    // Le prix de vente de l'onglet « Recette & marge » : tapé, par pas de 5 centimes ou choisi dans les paliers ; il recalcule la pièce.
    const prixEnc = F.q > 0 && F.v != null ? F.v / F.q : null;
    const simA = (v, saisie) => { S.fiche.simPrix = v > 0 && (prixEnc == null || Math.abs(v - prixEnc) >= 0.005) ? Math.round(v * 100) / 100 : null; if (saisie != null) { S.fiche.simSaisie = saisie; } else { delete S.fiche.simSaisie; } ficheRendre(); };
    const refocus = (sel, pos) => { const n = document.querySelector('#db-fiche ' + sel); if (n) { n.focus(); if (pos != null) { try { n.setSelectionRange(pos, pos); } catch (e) { /* champ sans sélection */ } } } };
    box.querySelectorAll('[data-fmat]').forEach(tr => {
      const bascule = () => { const i = +tr.dataset.fmat; S.fiche.mat = S.fiche.mat === i ? null : i; ficheRendre(); };
      tr.addEventListener('click', e => { if (e.target.closest('a, button, input')) { return; } bascule(); });
      tr.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); bascule(); } });
    });
    box.querySelectorAll('[data-fmatrelire]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); ficheLireMatiere(b.dataset.fmatrelire, true); ficheRendre(); }));
    box.querySelectorAll('[data-fsim]').forEach(b => b.addEventListener('click', () => simA(+b.dataset.fsim)));
    box.querySelectorAll('[data-fsimx]').forEach(b => b.addEventListener('click', () => simA(null)));
    box.querySelectorAll('[data-fsimpas]').forEach(b => b.addEventListener('click', () => { simA(Math.max(0.05, (S.fiche.simPrix != null ? S.fiche.simPrix : (prixEnc || 0)) + +b.dataset.fsimpas)); refocus(`[data-fsimpas="${b.dataset.fsimpas}"]`); }));
    const inp = box.querySelector('[data-fsiminput]');
    if (inp) {
      const lire = () => parseFloat(String(inp.value).replace(/\s/g, '').replace(',', '.'));
      inp.addEventListener('input', () => { const v = lire(), pos = inp.selectionStart; if (!(v > 0)) { return; } simA(v, inp.value); refocus('[data-fsiminput]', pos); });
      inp.addEventListener('blur', () => { if (ficheEnRendu || !inp.isConnected || !S.fiche) { return; } const v = lire(); simA(v > 0 ? v : (S.fiche.simPrix != null ? S.fiche.simPrix : prixEnc)); });
      inp.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); inp.blur(); }
        else if (e.key === 'ArrowUp' || e.key === 'ArrowDown') { e.preventDefault(); const v = lire(); simA(Math.max(0.05, (v > 0 ? v : (prixEnc || 0)) + (e.key === 'ArrowUp' ? 0.05 : -0.05))); refocus('[data-fsiminput]'); const n = document.querySelector('#db-fiche [data-fsiminput]'); if (n) { n.select(); } }
      });
    }
    ficheEnRendu = false;
    const ref = focSel ? box.querySelector(focSel) : null;
    if (ref) { ref.focus({ preventScroll: true }); if (focPos != null && ref.setSelectionRange) { try { ref.setSelectionRange(focPos, focPos); } catch (e) { /* champ sans sélection */ } } }
    else { const x = box.querySelector('.fi-x'); if (x && !box.contains(document.activeElement)) { x.focus({ preventScroll: true }); } }
  }

  /**
   * Ventes par catégorie en liste à trois niveaux : groupe › catégorie › produit.
   * Chaque ligne porte le CA, sa part, la marge brute (CA − coût matière),
   * son taux et le coefficient (CA ÷ coût matière, « × 2,43 ») ; la barre est
   * le poids dans le niveau du dessus, sa couleur la marge. Les pourcentages
   * sont des entiers : la décimale ne se lit pas à cette taille. Un groupe ou une catégorie s'ouvre d'un clic ; ce qui est ouvert
   * reste ouvert d'une relecture à l'autre. Sans tickets lus, la liste retombe
   * sur les catégories du panel et leur écart à la référence.
   */
  function accordeon(cats, parMarge, ref) {
    const coulE = (v, ech) => { if (v == null) { return '#B9B2A8'; } let r = ech[0]; for (const e of ech) { if (v >= e.s) { r = e; } } return r.c === 'or' ? '#E2B93B' : r.c; };
    const coul = x => parMarge ? coulE(x.taux, MARGES) : coulE(x.delta, ECARTS);
    const clM = t => !parMarge || t == null ? 'mu' : t < 40 ? 'ko' : t < 60 ? 'att' : 'ok';
    // Le total : celui du P&L de la journée quand on l'a (10/10/2026), pour que les deux cartes disent le même
    // chiffre ; l'écart avec la somme des produits (lignes de caisse sans produit, arrondis) se montre à part.
    const somme = cats.reduce((t, c) => t + (c.ca || 0), 0);
    const R = parMarge && ref && ref.ca != null && ref.m != null ? ref : null;
    const tot = R ? R.ca : somme;
    const G = {};
    cats.forEach(c => {
      const g = (c.groupe || (parMarge ? 'Autres' : 'Catégories')).split(' · ')[0];
      const f = G[g] || (G[g] = { nom: g, ca: 0, caC: 0, mat: 0, m: 0, connu: false, cats: [] });
      f.ca += c.ca; f.cats.push(c);
      // B (10/10/2026) : le groupe garde la marge de ses catégories chiffrées ; « ? » seulement si aucune ne l'est.
      if (parMarge && c.mat != null) { f.connu = true; f.mat += c.mat; f.m += c.m; f.caC += c.vC != null ? c.vC : c.ca; }
    });
    const fams = Object.values(G).sort((a, b) => b.ca - a.ca); fams.forEach(f => f.cats.sort((a, b) => b.ca - a.ca));
    const max = Math.max(1, ...fams.map(f => f.ca));
    // Le coefficient : ce que le CA fait du coût matière. Sous 1, on vend sous
    // le coût ; sans coût matière, rien à calculer.
    const coef = (ca, m) => (m == null || ca == null || ca - m <= 0) ? null : ca / (ca - m);
    const coefTxt = v => v == null ? '<span class="mu">—</span>' : '<small>×</small>' + nf(v, 2);
    const ligne = (niv, cle, x, ref, sub, prod, cat) => `<div class="db-al ${niv}${prod ? ' clic' : ''}${x.bundle ? ' bun' : ''}" ${cle ? `data-cacc="${esc(cle)}"` : ''}${prod ? ` data-fprod="${esc(prod.id)}" data-fnom="${esc(x.nom)}" data-fq="${prod.q != null ? prod.q : ''}" data-fv="${prod.v != null ? prod.v : ''}" data-ft="${prod.taux != null ? prod.taux : ''}" data-fc="${prod.c != null ? prod.c : ''}" data-fcat="${esc(cat || '')}" role="button" tabindex="0" title="les ventes sur 12 semaines, le prix face au réseau, la recette et la marge"` : ''}>
        <span>${cle ? `<span class="db-tog ${S.cOuv[cle] ? 'on' : ''}">${S.cOuv[cle] ? '▾' : '▸'}</span>` : ''}</span>
        <span class="nom">${esc(x.nom)}${x.bundle ? '<em class="bdl">bundle</em>' : ''}${(niv === 'g' && x.nom !== 'Catégories') || niv === 'c' ? `<button type="button" class="db-c12" data-c12n="${niv === 'g' ? 'groupe' : 'categorie'}" data-c12="${esc(x.nom)}" title="les ventes des 12 dernières semaines face au réseau" aria-label="les ventes des 12 dernières semaines">i</button>` : ''}${sub ? `<span class="sub">${sub}</span>` : ''}</span>
        <span class="barre"><i style="width:${Math.max(1, Math.min(100, 100 * x.ca / Math.max(ref, 1)))}%;background:${coul(x)}"></i></span>
        <span class="n">${fE(x.ca)}</span>
        <span class="n mu">${x.part != null ? fP0(x.part) : ''}</span>
        <span class="n mg ${clM(x.taux)}">${parMarge ? (x.m == null ? '<span class="mu" title="coût matière inconnu">?</span>' : fE(x.m)) : (x.delta != null ? (x.delta >= 0 ? '+' : '') + fP(x.delta) : '<span class="mu">—</span>')}</span>
        <span class="n ${clM(x.taux)}">${parMarge ? (x.taux != null ? fP0(x.taux) : '') : ''}</span>
        <span class="n coef ${clM(x.taux)}">${parMarge ? (x.m == null ? '<span class="mu" title="coût matière inconnu">?</span>' : coefTxt(coef(x.ca, x.m))) : ''}</span></div>`;
    const entete = `<div class="db-ent"><span></span><span>Groupe › catégorie › produit</span><span>Poids dans le CA</span><span class="n">CA</span><span class="n">Part</span><span class="n">${parMarge ? 'Marge brute' : 'vs réf.'}</span><span class="n">${parMarge ? 'Taux' : ''}</span><span class="n">${parMarge ? 'Coef' : ''}</span></div>`;
    const produits = c => {
      const L = c.produits || [];
      if (!L.length) { return `<div class="db-autres">Le détail par produit se lit sur les tickets : pas encore disponible pour cette catégorie.</div>`; }
      // A (10/10/2026) : un bundle rangé dans la catégorie de son produit porte son étiquette, son prix et sa part.
      const subP = p => p.bundle ? fN(p.bundle.n) + ' bundle' + (p.bundle.n > 1 ? 's' : '') + (p.bundle.prix != null ? ' à ' + fU(p.bundle.prix) : '') + (p.bundle.part < 100 ? ' · ' + p.bundle.part + ' % du bundle dans cette catégorie' : '')
        : (p.q != null ? p.q + ' vendu' + (p.q > 1 ? 's' : '') : '');
      return L.map(p => ligne('p', null, { nom: p.nom, ca: p.v, part: p.part, m: p.m, taux: p.taux, bundle: !!p.bundle }, c.ca, subP(p), /^\d+$/.test(String(p.id || '')) ? p : null, c.categorie)).join('');
    };
    const rows = fams.map(f => {
      const kg = 'g:' + f.nom;
      const fx = { nom: f.nom, ca: f.ca, part: tot > 0 ? 100 * f.ca / tot : null, m: f.connu ? f.m : null, taux: f.connu && f.caC > 0 ? 100 * f.m / f.caC : null, delta: null };
      let h = ligne('g', kg, fx, max, f.cats.length + ' catégorie' + (f.cats.length > 1 ? 's' : ''));
      if (S.cOuv[kg]) {
        h += f.cats.map(c => {
          const kc = 'c:' + c.categorie;
          const cx = { nom: c.categorie, ca: c.ca, part: c.part != null ? 100 * c.part : null, m: c.m, taux: c.taux, delta: c.delta };
          const sans = parMarge && c.nSans ? `<b class="sans">${c.nSans} produit${c.nSans > 1 ? 's' : ''} sans coût · ${fU(c.vSans)}</b>` + (c.mat != null && c.vC != null && c.ca > 0 ? ' · taux sur ' + fP0(100 * c.vC / c.ca) + ' du CA' : '') : '';
          const sub = parMarge ? (sans ? (c.refs != null ? c.refs + ' réf. · ' : '') + sans : (c.refs != null ? c.refs + ' réf.' : '') + (c.q != null ? ' · ' + fN(c.q) + ' pièces' : '')) : (c.ref != null ? 'référence ' + fE(c.ref) : '');
          return ligne('c', parMarge ? kc : null, cx, f.ca, sub) + (parMarge && S.cOuv[kc] ? produits(c) : '');
        }).join('');
      }
      return h;
    }).join('');
    const sommeM = parMarge ? cats.reduce((t, c) => t + (c.m == null ? 0 : c.m), 0) : null;
    const totM = R ? R.m : sommeM;
    const ecCa = R ? R.ca - somme : 0, ecM = R ? R.m - sommeM : 0;
    const ecart = R && (Math.abs(ecCa) >= 0.5 || Math.abs(ecM) >= 0.5) ? `<div class="db-al ec" title="CA et marge de la journée moins la somme des produits : lignes de caisse sans produit, remises sur ticket, arrondis"><span></span><span class="nom">Non ventilé<span class="sub">${(() => { const nS = cats.reduce((t, c) => t + (c.nSans || 0), 0), vS = cats.reduce((t, c) => t + (c.vSans || 0), 0);
      return nS ? 'la marge des ' + nS + ' produit' + (nS > 1 ? 's' : '') + ' sans coût (' + fU(vS) + ' de ventes), estimée par le P&L' + (Math.abs(ecCa) >= 0.5 ? ' · lignes sans produit, remises' : '') : 'lignes de caisse sans produit, remises, arrondis'; })()}</span></span><span></span><span class="n">${fE(ecCa)}</span><span class="n mu">${tot > 0 ? fP0(100 * ecCa / tot) : ''}</span><span class="n mg">${fE(ecM)}</span><span class="n"></span><span class="n coef"></span></div>` : '';
    const pied = ecart + `<div class="db-al tot"><span></span><span class="nom">Total<span class="sub">${fams.length} groupe${fams.length > 1 ? 's' : ''} · ${cats.length} catégorie${cats.length > 1 ? 's' : ''}</span></span><span></span><span class="n">${fE(tot)}</span><span class="n mu">100 %</span><span class="n mg">${parMarge ? fE(totM) : ''}</span><span class="n">${parMarge && tot > 0 ? (R ? fP(100 * totM / tot) : fP0(100 * totM / tot)) : ''}</span><span class="n coef">${parMarge && tot > 0 ? coefTxt(coef(tot, totM)) : ''}</span></div>`;
    return `<div class="db-acc">${entete}${rows}${pied}</div>`;
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
  /**
   * La semaine ou le mois du magasin — comme le drop de Résultat dans le
   * cockpit : huit chiffres (dont la projection de fin de période, le reste à
   * faire par jour et le record), le calendrier coloré par l'atteinte de
   * l'objectif de CHAQUE jour, puis le P&L et — au mois — le profil des jours.
   * Les heures ont leur propre section juste en dessous.
   */
  function rendPeriode(m, d) {
    const sem = S.vue === 'semaine';
    const sansO = m.objectif == null;
    const jours = Array.isArray(m.jours) ? m.jours : [];
    const passes = jours.filter(j => j.passe && j.ca);
    const restants = jours.filter(j => !j.passe && !j.ferme && !j.aujourdhui);
    const nJ = Math.max(1, passes.length);
    const proj = sansO || !m.attendu ? null : m.realise / m.attendu * m.objectif;
    const record = passes.length ? passes.reduce((a, b) => b.ca > a.ca ? b : a) : null;
    const pc = (a, b) => b ? 100 * a / b : 0;
    const srcO = m.objectifSource === 'budget' ? 'budget validé' : (m.objectifSource === 'theorique' ? 'CA théorique de l’étude' : esc(m.objectifSource || ''));
    let h = `<div class="db-tuiles huit">
      ${tuile(sem ? 'CA de la semaine' : 'CA du mois', fK(m.realise), sansO ? 'pas de budget pour cette période' : 'objectif ' + fK(m.objectif) + ' (' + srcO + ') · attendu ' + fK(m.attendu))}
      ${tuile('Atteinte', sansO ? '—' : fP(100 * m.realise / m.attendu), sansO ? '' : 'à ce jour · ' + fSK(m.ecart) + ' · ' + fN(Math.abs(m.clientsManquants)) + ' clients ' + (m.clientsManquants > 0 ? 'manquants' : 'd’avance'), sansO ? '' : (m.ecart < 0 ? 'vif' : 'bon'))}
      ${tuile('Projection', proj == null ? '—' : fK(proj), proj == null ? '' : 'au rythme actuel · ' + fSK(proj - m.objectif) + ' vs objectif', proj == null ? '' : (proj < m.objectif ? 'vif' : 'bon'))}
      ${tuile('Reste à faire', sansO ? '—' : fK(m.reste), sansO ? '' : (restants.length ? restants.length + ' jour' + (restants.length > 1 ? 's' : '') + ' · ' + fK(m.reste / restants.length) + ' par jour' : (sem ? 'semaine close' : 'mois clos')))}
      ${tuile('Par jour ouvert', fE(m.realise / nJ), fN(m.tickets / nJ) + ' clients · panier ' + fU(m.panier).replace(' €', '\u00a0€'))}
      ${tuile('Marge brute', m.margeBrutePct != null ? fP(m.margeBrutePct) : '—', fK(m.margeBrute || 0) + ' · matière ' + fP(m.coutMatierePct) + ' (seuil ' + fP((d.seuils || {}).food || 32) + ')')}
      ${tuile('Résultat net', m.net == null ? '—' : fSK(m.net), m.net == null ? esc(m.motifNet || '') : fP(m.netPct) + ' des ventes', m.net == null ? '' : (m.net >= 0 ? 'bon' : 'vif'))}
      ${tuile('Record', record ? fE(record.ca) : '—', record ? record.court + ' · ' + fN(record.tickets) + ' clients' + (record.objectif ? ' · ' + (pc(record.ca, record.objectif) >= 100 ? '+' : '') + Math.round(pc(record.ca, record.objectif) - 100) + ' % vs objectif' : '') : '', 'or')}
    </div>`;
    h += splitCarte(m);
    h += offresCarte();
    h += invCarte(false);
    // Le calendrier (maquette A du 10/10/2026) : le bouton choisit TOUT — le chiffre en grand, la
    // couleur, la jauge vers l'objectif, le titre et la légende. Le CA se juge face à l'objectif CA
    // du jour, les clients face à l'objectif clients (objectif CA ÷ panier moyen de la période).
    // La journée en cours n'est pas jugée (hachurée) ; un jour futur montre son objectif.
    if (jours.length) {
      const cv = ['ca', 'att', 'cli'].includes(S.calVal) ? S.calVal : 'ca';
      const pan = m.panier || null;
      const objCli = j => j.objectif && pan ? Math.round(j.objectif / pan) : null;
      const fini = j => j.passe && !j.aujourdhui && j.ca != null && !j.ferme;
      const enCours = j => !!j.aujourdhui && j.ca != null;
      const pCa = j => j.objectif ? 100 * (j.ca || 0) / j.objectif : null;
      const pCli = j => objCli(j) ? 100 * (j.tickets || 0) / objCli(j) : null;
      const sg = (v, u) => (v >= 0 ? '+' : '−') + fN(Math.abs(v)) + (u || '');
      const lus = jours.filter(j => fini(j) || enCours(j));
      const totCli = lus.reduce((a, j) => a + (j.tickets || 0), 0), totObjCli = lus.reduce((a, j) => a + (objCli(j) || 0), 0);
      const MODE = {
        ca: { t: sem ? 'Le CA de chaque jour' : 'Le CA de chaque jour du mois', s: 'En grand : le chiffre d’affaires du jour. Couleur et jauge : face à l’objectif CA du jour.', p: pCa,
          v: j => [fN(j.ca), '€'], l: j => j.objectif ? 'objectif ' + fE(j.objectif) + ' · ' + sg(j.ca - j.objectif, ' €') : 'pas d’objectif', f: j => j.objectif ? 'objectif ' + fE(j.objectif) : '', leg: 'le CA face à l’objectif du jour' },
        att: { t: sem ? 'L’atteinte de chaque jour' : 'L’atteinte de chaque jour du mois', s: 'En grand : le CA en % de l’objectif du jour.', p: pCa,
          v: j => j.objectif ? [fN(pCa(j)), '%'] : ['—', ''], l: j => j.objectif ? fE(j.ca) + ' sur ' + fE(j.objectif) : fE(j.ca) + ' · pas d’objectif', f: j => j.objectif ? 'objectif ' + fE(j.objectif) : '', leg: 'l’atteinte de l’objectif du jour' },
        cli: { t: sem ? 'Les clients de chaque jour' : 'Les clients de chaque jour du mois', s: 'En grand : les clients du jour. Couleur et jauge : face à l’objectif clients (objectif CA ÷ panier moyen ' + (pan ? fU(pan) : '—') + ').', p: pCli,
          v: j => [fN(j.tickets), 'clients'], l: j => objCli(j) ? 'objectif ' + fN(objCli(j)) + ' · ' + sg(j.tickets - objCli(j)) : 'pas d’objectif', f: j => objCli(j) ? 'objectif ' + fN(objCli(j)) + ' clients' : '', leg: 'les clients face à l’objectif clients du jour' }
      };
      const X = MODE[cv];
      const PAL = [[110, '#2d7a3e', false], [100, '#6aa84f', false], [90, '#e8c9a0', true], [75, '#F5B26B', true], [-Infinity, '#F08A2C', false]];
      const tein = p => PAL.find(x => p >= x[0]);
      // La jauge : de 0 à 120 % de l'objectif, le trait à 100 %.
      const jauge = p => `<span class="cal-jg"><i style="width:${Math.max(2, Math.min(100, p / 1.2)).toFixed(1)}%"></i><s></s></span>`;
      let cases = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'].map(n => `<div class="h">${n}</div>`).join('');
      const t0 = new Date(jours[0].date + 'T12:00:00');
      for (let i = 0; i < (t0.getDay() + 6) % 7; i++) { cases += '<div class="d vide"></div>'; }
      jours.forEach(j => {
        const rec = record && j.date === record.date ? ' · record' : '';
        if (j.ferme) { cases += `<div class="d fut"><span class="n">${esc(j.court)}</span><b>fermé</b></div>`; return; }
        if (enCours(j)) { const v = X.v(j), p = X.p(j); cases += `<div class="d enc"><span class="n">${esc(j.court)}<em>en cours</em></span><b>${v[0]}<u>${v[1]}</u></b>${p != null ? jauge(p) : ''}<small>${X.l(j)}</small></div>`; return; }
        if (!fini(j)) { cases += `<div class="d fut"><span class="n">${esc(j.court)}</span><b>—</b><small>${X.f(j)}</small></div>`; return; }
        const p = X.p(j), v = X.v(j);
        if (p == null) { cases += `<div class="d nul"><span class="n">${esc(j.court)}${rec}</span><b>${v[0]}<u>${v[1]}</u></b><small>${X.l(j)}</small></div>`; return; }
        const t = tein(p);
        cases += `<div class="d${t[2] ? ' clair' : ''}" style="background:${t[1]}"><span class="n">${esc(j.court)}${rec}</span><b>${v[0]}<u>${v[1]}</u></b>${jauge(p)}<small>${X.l(j)}</small></div>`;
      });
      const totA = m.attendu ? 100 * m.realise / m.attendu : null;
      const SEG = [['ca', 'CA', fE(m.realise), m.attendu ? 'sur ' + fE(m.attendu) + ' attendus' : 'pas d’objectif'], ['att', 'Atteinte', totA != null ? fN(totA) + ' %' : '—', 'de l’objectif à date'], ['cli', 'Clients', fN(m.tickets), totObjCli ? 'sur ' + fN(totObjCli) + ' attendus' : '']];
      const leg = PAL.map((x, i) => `<span><i style="background:${x[1]}"></i>${i === 0 ? '≥ 110 %' : (i === 4 ? '< 75 %' : x[0] + ' – ' + PAL[i - 1][0] + ' %')}</span>`).join('');
      h += `<div class="db-card cal-a"><div class="cal-hd"><div class="cal-t">${X.t}<small>${X.s}</small></div>
        <div class="cal-seg" title="ce que montre le calendrier">${SEG.map(o => `<button data-calval="${o[0]}" class="${cv === o[0] ? 'on' : ''}"><span>${o[1]}</span><b>${o[2]}</b><small>${o[3]}</small></button>`).join('')}</div></div>
        <div class="db-cal cal-a-g">${cases}</div>
        <div class="db-perleg"><span class="cal-q">Couleur et jauge = ${X.leg}</span>${leg}<span><i class="cal-tr"></i>le trait : 100 % de l’objectif</span><span>hachuré : la journée en cours, pas encore jugée</span></div></div>`;
    }
    // Le P&L, et au mois le profil des jours à côté — deux cartes de même hauteur.
    const pl = `<div class="db-card"><div class="ct"><span class="db-lab">Le P&amp;L ${sem ? 'de la semaine' : 'du mois'}</span><span class="db-mini">matière : coût des recettes vendues · personnel : planning × taux · frais généraux : panel</span></div>${cascade(m, d)}</div>`;
    let prof = '';
    if (!sem && passes.length) {
      const NOMJ = ['', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
      const par = {}; passes.forEach(j => { (par[j.jour] = par[j.jour] || []).push(j); });
      const profil = Object.keys(par).map(Number).sort().map(w => { const l = par[w]; return { nom: NOMJ[w], ca: l.reduce((a, j) => a + j.ca, 0) / l.length, o: l.reduce((a, j) => a + (j.objectif || 0), 0) / l.length }; });
      const mx = Math.max(1, ...profil.map(p => Math.max(p.ca, p.o)));
      const pire = profil.filter(p => p.o).sort((a, b) => pc(a.ca, a.o) - pc(b.ca, b.o))[0];
      prof = `<div class="db-card"><div class="ct"><span class="db-lab">Le profil des jours</span><span class="db-mini">moyenne par jour de la semaine · le trait noir = objectif du jour${pire ? ' · le ' + pire.nom.toLowerCase() + ' est le point faible du mois' : ''}</span></div>
        <div style="padding:10px 16px 12px">${profil.map(p => `<div class="db-prof"><span class="l">${p.nom}</span><span class="b"><i style="width:${Math.round(p.ca / mx * 100)}%;background:${p.o && p.ca >= p.o ? '#8D1D2C' : '#c9c2b8'}"></i>${p.o ? `<em style="left:${Math.round(p.o / mx * 100)}%"></em>` : ''}</span><span class="v">${fE(p.ca)}</span><span class="c" style="color:${!p.o ? 'var(--color-text-muted)' : (p.ca >= p.o ? '#2d7a3e' : '#C0182B')}">${p.o ? Math.round(pc(p.ca, p.o)) + ' %' : ''}</span></div>`).join('')}</div></div>`;
    }
    h += prof ? `<div class="db-g2" style="grid-template-columns:1fr 1fr;margin-bottom:12px">${pl}${prof}</div>` : pl;
    if (S.vue === 'mois') { h += rendRentab(); }
    if (S.vue === 'semaine') { h += rendSemaines(); }
    return h;
  }

  /**
   * Les six dernières semaines, face au N-1 — la même section que le drop du
   * cockpit : la courbe des clients sur celle d'un an plus tôt en pointillé,
   * trois tuiles, l'écart semaine par semaine. Sans N-1 (magasin trop jeune),
   * la courbe reste seule et le motif se lit à la place de la tuile.
   */
  function rendSemaines() {
    const cle = 's6|' + S.shop + '|' + bornes()[0];
    const D = S.aux[cle];
    const carte = (corps, sous) => `<div class="db-card"><div class="ct"><span class="db-lab">Les six dernières semaines — clients, face au N-1</span>${sous ? `<span class="db-mini">${esc(sous)}</span>` : ''}</div><div style="padding:12px 16px 14px">${corps}</div></div>`;
    if (!D) { return carte(`<div class="db-note">${S.err[cle] ? esc(S.err[cle]) : 'Lecture des six semaines…'}</div>`); }
    const pc = (a, b) => b ? 100 * a / b : 0;
    const sg = v => (v >= 0 ? '+' : '−') + Math.abs(Math.round(v));
    const col = v => v >= 3 ? '#2d7a3e' : (v <= -3 ? '#C0182B' : '#8a6508');
    const W = (D.semaines || []).filter(w => w.source || (w.n1 && w.n1.source));
    if (!W.length) { return carte(`<div class="db-note">Aucune semaine lue pour ce magasin.</div>`); }
    const avecN1 = W.some(w => w.n1);
    const vals = [];
    W.forEach(w => { if (w.source) { vals.push(w.tickets); } if (w.n1) { vals.push(w.n1.tickets); } });
    const lo = Math.min(...vals) * 0.88, hi = Math.max(...vals) * 1.06 || 1;
    const Wd = 1000, Hh = 190, ml = 30, mr = 64, mt = 26, mb = 30;
    const X = i => (ml + i * (Wd - ml - mr) / Math.max(1, W.length - 1)).toFixed(1);
    const Y = v => (mt + (hi - v) / (hi - lo || 1) * (Hh - mt - mb)).toFixed(1);
    const ft = 'font-family:var(--font-ui)';
    let svg = `<svg viewBox="0 0 ${Wd} ${Hh}" preserveAspectRatio="xMinYMin meet" class="db-s6c">`;
    [1, 2].forEach(k => { const y = Y(lo + (hi - lo) * k / 3); svg += `<line x1="${ml}" x2="${Wd - mr}" y1="${y}" y2="${y}" stroke="rgba(34,34,34,.10)"/>`; });
    const P = W.map((w, i) => w.source ? X(i) + ',' + Y(w.tickets) : null).filter(Boolean);
    const P1 = W.map((w, i) => w.n1 ? X(i) + ',' + Y(w.n1.tickets) : null).filter(Boolean);
    if (P1.length > 1) { svg += `<polyline points="${P1.join(' ')}" fill="none" stroke="#b9b1a6" stroke-width="2" stroke-dasharray="5 4"/>`; }
    if (P.length > 1) { svg += `<polyline points="${P.join(' ')}" fill="none" stroke="#8D1D2C" stroke-width="2.5"/>`; }
    W.forEach((w, i) => {
      const up = !w.n1 || !w.source || w.tickets >= w.n1.tickets;
      if (w.n1) { svg += `<circle cx="${X(i)}" cy="${Y(w.n1.tickets)}" r="3.5" fill="#fff" stroke="#b9b1a6" stroke-width="2"/><text x="${X(i)}" y="${(+Y(w.n1.tickets) + (up ? 16 : -9)).toFixed(1)}" font-size="10" text-anchor="middle" fill="#666" style="${ft}">${fN(w.n1.tickets)}</text>`; }
      if (w.source) { svg += `<circle cx="${X(i)}" cy="${Y(w.tickets)}" r="4" fill="#8D1D2C"/><text x="${X(i)}" y="${(+Y(w.tickets) + (up ? -9 : 16)).toFixed(1)}" font-size="11" font-weight="600" text-anchor="middle" fill="#8D1D2C" style="${ft}">${fN(w.tickets)}</text>`; }
      svg += `<text x="${X(i)}" y="${Hh - 8}" font-size="10.5" font-weight="600" text-anchor="middle" fill="${w.enCours ? '#8D1D2C' : '#222'}" style="${ft}">S${w.iso}${w.enCours ? ' · en cours' : ''}</text>`;
    });
    svg += '</svg>';
    const chips = W.map(w => {
      const e = w.n1 && w.source ? pc(w.tickets - w.n1.tickets, w.n1.tickets) : null;
      const dates = (w.du.slice(5, 7) === w.au.slice(5, 7) ? w.du.slice(8, 10) : fD(w.du)) + '→' + fD(w.au);
      const titre = (w.source ? fN(w.tickets) + ' clients · ' + fK(w.ca) : 'pas de vente lue') + (w.n1 ? ' · N-1 : ' + fN(w.n1.tickets) + ' clients · ' + fK(w.n1.ca) : ' · pas de N-1') + (w.enCours ? ' · ' + w.joursServis + ' jours servis' : '');
      return `<div class="ch" title="${esc(titre)}"><span><b>S${w.iso}</b><span>${dates}</span></span>${avecN1 ? `<em style="background:${e == null ? '#c9c2b8' : col(e)}">${e == null ? '—' : sg(e) + ' %'}</em>` : `<em class="sans">${w.source ? fN(w.tickets) : '—'}</em>`}</div>`;
    }).join('');
    const cur = W[W.length - 1], prev = W.length > 1 ? W[W.length - 2] : null;
    const eT = D.totalN1 && D.totalN1.tickets ? pc(D.total.tickets - D.totalN1.tickets, D.totalN1.tickets) : null;
    const meilleure = W.filter(w => w.source).reduce((a, b) => !a || b.tickets > a.tickets ? b : a, null);
    const eP = prev && prev.source && cur.source ? pc(cur.tickets - prev.tickets, prev.tickets) : null;
    const tuiles = [
      eT != null ? tuile('Six semaines vs N-1', sg(eT) + ' %', fN(D.total.tickets) + ' clients contre ' + fN(D.totalN1.tickets), eT >= 0 ? 'bon' : 'vif')
        : tuile('Six semaines', fN(D.total.tickets), esc(D.n1Motif || 'pas de N-1')),
      tuile(cur.enCours ? 'Semaine en cours' : 'Dernière semaine', cur.source ? fN(cur.tickets) : '—',
        (eP != null ? sg(eP) + ' % vs S' + prev.iso : '') + (cur.enCours ? (eP != null ? ' · ' : '') + cur.joursServis + ' jour' + (cur.joursServis > 1 ? 's' : '') + ' servi' + (cur.joursServis > 1 ? 's' : '') : ''),
        eP == null ? '' : (eP >= 0 ? 'bon' : 'vif')),
      tuile('Meilleure semaine', meilleure ? fN(meilleure.tickets) : '—', meilleure ? 'S' + meilleure.iso + ' · ' + fD(meilleure.du) + ' → ' + fD(meilleure.au) : '')
    ].join('');
    const corps = `<div class="db-s6"><div>${svg}
        <div class="db-axe" style="padding:4px 0 0;justify-content:flex-start;gap:14px"><span><i class="c" style="background:#8D1D2C;border-radius:50%"></i>clients de la semaine</span>${avecN1 ? `<span><i class="c" style="background:#b9b1a6;border-radius:50%"></i>même semaine en N-1 (pointillé)</span>` : `<span>${esc(D.n1Motif || '')}</span>`}</div>
        <div class="db-s6ch">${chips}</div></div>
      <div class="db-s6t">${tuiles}</div></div>`;
    return carte(corps, 'S' + W[0].iso + ' → S' + cur.iso + ' · ' + (D.source || ''));
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
  /* --- La frise des périodes (sélecteur 2 du 10/10/2026) ----------------------
   * Au bureau, sous les onglets : en Opérationnel les 14 jours autour du jour regardé, en Semaine les
   * 8 semaines, en Mois les 12 mois de l'année. Chaque période est un bouton qui montre déjà son chiffre. */
  const JOURS_FR = ['D', 'L', 'Ma', 'Me', 'J', 'V', 'S'];
  function finMois(ym) { const f = new Date(+ym.slice(0, 4), +ym.slice(5, 7), 0, 12); const d = iso(f); return d > AUJ ? AUJ : d; }
  // La semaine active seulement, du lundi au dimanche (10/10/2026) : quatorze cases ne se lisaient pas sur tablette.
  function friseJours() { const t = new Date(S.date + 'T12:00:00'), out = []; t.setDate(t.getDate() - (t.getDay() + 6) % 7); for (let i = 0; i < 7; i++) { const d = new Date(t); d.setDate(t.getDate() + i); out.push(iso(d)); } return out; }
  function friseHtml() {
    if (EMBED || !['ops', 'semaine', 'mois', 'trimestre'].includes(S.vue)) { return ''; }
    const fdC = s => { const d = new Date(s + 'T12:00:00'); return d.getDate() + ' ' + MOIS_C[d.getMonth()]; };
    const col = p => p == null ? '#cfc6ba' : (p >= 100 ? '#2d7a3e' : (p >= 90 ? '#e8c9a0' : (p >= 75 ? '#F5B26B' : '#F08A2C')));
    const fl = n => S.vue === 'ops' || S.vue === 'semaine'
      ? `<button type="button" class="fr-fl" data-frsem="${n}" aria-label="${n < 0 ? 'semaine précédente' : 'semaine suivante'}"${n > 0 && friseJours()[6] >= AUJ ? ' disabled' : ''}>${n < 0 ? '‹' : '›'}</button>`
      : `<button type="button" class="fr-fl" data-pas="${n}" aria-label="${n < 0 ? 'période précédente' : 'période suivante'}">${n < 0 ? '‹' : '›'}</button>`;
    const sk = n => Array.from({ length: n }, () => '<div class="fr-c sk"><span class="db-sk" style="width:60%"></span><span class="db-sk" style="width:80%;height:14px"></span></div>').join('');
    let chips = '', tete = '';
    if (S.vue === 'ops' || S.vue === 'semaine') {
      // En Semaine aussi (10/10/2026) : les sept jours de la semaine choisie ; un clic ouvre le jour en Opérationnel.
      const ops = S.vue === 'ops', J = friseJours(), lus = {};
      let attente = false;
      [...new Set(J.map(d => d.slice(0, 7)))].filter(ym => ym <= AUJ.slice(0, 7)).forEach(ym => {
        const P = S.aux['frJ|' + ym]; if (!P) { attente = true; return; }
        const mg = (P.magasins || []).find(x => String(x.shopId) === String(S.shop));
        ((mg && mg.jours) || []).forEach(j => { lus[j.date] = j; });
      });
      // Le jour regardé prend le chiffre en direct de la page (/exploitation/jour, relu toutes les 2 min) :
      // la période du mois, gardée 5 min par le serveur, retarderait la case d'aujourd'hui.
      // Le même chiffre que la page : les tickets lus (/ventes/stats), sinon /exploitation/jour.
      const vif = ops ? magasin(S.aux['jourM|' + S.date]) : null, STv = ops ? S.st[cleSt()] : null;
      const caVif = STv && STv.totaux && STv.totaux.ca != null ? STv.totaux.ca : (vif ? vif.ca : null);
      if (caVif != null) { lus[S.date] = Object.assign({}, lus[S.date] || {}, { ca: caVif, objectif: vif && vif.objectifJour != null ? vif.objectifJour : (lus[S.date] || {}).objectif }); }
      const totS = J.reduce((t, d) => t + (lus[d] && lus[d].ca != null && d <= AUJ ? +lus[d].ca : 0), 0);
      tete = 'semaine du ' + fdC(J[0]) + ' au ' + fdC(J[6]) + (!ops && totS ? ' · ' + fE(totS) : '');
      chips = attente && !Object.keys(lus).length ? sk(7) : J.map(d => {
        const j = lus[d] || {}, t = new Date(d + 'T12:00:00'), lib = JOURS_FR[t.getDay()] + ' ' + t.getDate(), on = ops && d === S.date;
        if (d > AUJ) { return `<div class="fr-c fut"><span class="n">${lib}</span><b>—</b><small>${j.objectif ? 'obj. ' + nf(j.objectif / 1000, 1) + ' k€' : 'à venir'}</small></div>`; }
        if (j.ferme) { return `<div class="fr-c fut"><span class="n">${lib}</span><b>—</b><small>fermé</small></div>`; }
        const p = j.objectif && j.ca != null ? 100 * j.ca / j.objectif : null, auj = d === AUJ;
        return `<button type="button" class="fr-c${on ? ' on' : ''}" ${ops ? 'data-fdate' : 'data-fops'}="${d}" title="${esc(fDL(d))}${ops ? '' : ' · ouvrir en Opérationnel'}"><span class="n">${lib}<i class="dot" style="background:${auj ? '#1d1d1b' : col(p)}"></i></span><b>${j.ca != null ? fE(j.ca) : '—'}</b><small>${auj ? 'en cours' : (p != null ? fN(p) + ' % obj.' : (j.ca != null ? 'sans objectif' : 'pas de vente'))}</small></button>`;
      }).join('');
    } else if (S.vue === 'trimestre') {
      // Les 4 trimestres de l'année (10/10/2026) : le CA des mois lus, face à l'objectif des trois mois.
      const Y = annee(), P = S.aux['perf|' + Y], L = Array.isArray(P) ? P.filter(x => String(x.storeId) === String(S.shop) && +x.annee === Y) : null;
      const qAuj = +AUJ.slice(0, 4) === Y ? Math.floor((+AUJ.slice(5, 7) - 1) / 3) + 1 : (+AUJ.slice(0, 4) > Y ? 5 : 0);
      tete = `<button type="button" class="fr-an" data-fan="-1">‹</button>${Y}<button type="button" class="fr-an" data-fan="1"${Y >= +AUJ.slice(0, 4) ? ' disabled' : ''}>›</button>`;
      chips = !L ? sk(4) : [1, 2, 3, 4].map(q => {
        const ms = L.filter(r => Math.ceil(+r.mois / 3) === q);
        const lus = ms.filter(r => r.ca != null), ca = lus.reduce((a, r) => a + (+r.ca), 0), obj = ms.reduce((a, r) => a + (r.caBudget != null ? +r.caBudget : 0), 0);
        // La part : le CA des mois qui ont un objectif, face à cet objectif (un mois sans objectif ne compte pas).
        const caO = lus.filter(r => r.caBudget != null).reduce((a, r) => a + (+r.ca), 0);
        const p = lus.length && obj ? 100 * caO / obj : null, on = trimestre() === q, cours = q === qAuj;
        const lib = 'T' + q + ' · ' + MOIS_C[(q - 1) * 3] + ' – ' + MOIS_C[(q - 1) * 3 + 2];
        if (q > qAuj) { return `<div class="fr-c fut"><span class="n">${lib}</span><b>—</b><small>${obj ? 'objectif ' + fK(obj) : 'à venir'}</small></div>`; }
        if (!lus.length) { return `<div class="fr-c fut"><span class="n">${lib}</span><b>—</b><small>pas de vente</small></div>`; }
        return `<button type="button" class="fr-c${on ? ' on' : ''}" data-trim="${q}"><span class="n">${lib}${cours ? '<em>en cours</em>' : ''}</span><b>${fK(ca)}</b><i class="jg"><em style="width:${Math.min(100, p || 0).toFixed(0)}%;background:${on ? 'var(--color-primary)' : col(p)}"></em></i><small>${p != null ? fN(p) + ' % de l’objectif' + (obj ? ' ' + fK(obj) : '') : 'sans objectif'}</small></button>`;
      }).join('');
    } else {
      const Y = annee(), P = S.aux['perf|' + Y], L = Array.isArray(P) ? P.filter(x => String(x.storeId) === String(S.shop) && +x.annee === Y) : null;
      tete = `<button type="button" class="fr-an" data-fan="-1">‹</button>${Y}<button type="button" class="fr-an" data-fan="1"${Y >= +AUJ.slice(0, 4) ? ' disabled' : ''}>›</button>`;
      chips = !L ? sk(12) : Array.from({ length: 12 }, (_, i) => {
        const x = L.find(r => +r.mois === i + 1) || {}, ym = Y + '-' + String(i + 1).padStart(2, '0'), on = S.date.slice(0, 7) === ym, cours = ym === AUJ.slice(0, 7);
        const ca = x.ca != null ? +x.ca : null, bud = x.caBudget != null ? +x.caBudget : null, p = ca != null && bud ? 100 * ca / bud : null;
        if (ym > AUJ.slice(0, 7)) { return `<div class="fr-c fut"><span class="n">${MOIS_C[i]}</span><b>—</b><small>${bud ? 'objectif ' + fK(bud) : 'à venir'}</small></div>`; }
        if (ca == null) { return `<div class="fr-c fut"><span class="n">${MOIS_C[i]}</span><b>—</b><small>pas de vente</small></div>`; }
        return `<button type="button" class="fr-c${on ? ' on' : ''}" data-fdate="${finMois(ym)}"><span class="n">${MOIS_C[i]}${cours ? '<em>en cours</em>' : ''}</span><b>${fK(ca)}</b><i class="jg"><em style="width:${Math.min(100, p || 0).toFixed(0)}%;background:${on ? 'var(--color-primary)' : col(p)}"></em></i><small>${p != null ? fN(p) + ' % de l’objectif' : 'sans objectif'}</small></button>`;
      }).join('');
    }
    const annuel = S.vue === 'mois' || S.vue === 'trimestre';
    return `<div class="db-frise${S.vue === 'trimestre' ? ' quatre' : (S.vue === 'ops' || S.vue === 'semaine' ? ' sept' : '')}"><div class="fr-t">${tete}</div><div class="fr-r">${annuel ? '' : fl(-1)}${chips}${annuel ? '' : fl(1)}</div></div>`;
  }

  function rendBench(m, d) {
    const L = (d.magasins || []).filter(x => x.ouvert !== false);
    const jour = S.vue === 'jour';
    const defs = jour
      ? [['Chiffre d’affaires', 'ca', fK, v => fSK(v)], ['Clients', 'tickets', fN, v => (v >= 0 ? '+' : '−') + fN(Math.abs(v))], ['Panier moyen', 'panier', fU, v => (v >= 0 ? '+ ' : '− ') + fU(Math.abs(v))], ['Marge brute', 'margeBrutePct', v => fP(v), v => (v >= 0 ? '+' : '−') + nf(Math.abs(v), 1) + ' pts'], ['Résultat net', 'netPct', v => fP(v), v => (v >= 0 ? '+' : '−') + nf(Math.abs(v), 1) + ' pts']]
      : [['Chiffre d’affaires', 'realise', fK, v => fSK(v)], ['Clients', 'tickets', fN, v => (v >= 0 ? '+' : '−') + fN(Math.abs(v))], ['Panier moyen', 'panier', fU, v => (v >= 0 ? '+ ' : '− ') + fU(Math.abs(v))], ['Atteinte de l’attendu', 'atteinte', v => fP(100 * v), v => (v >= 0 ? '+' : '−') + nf(Math.abs(100 * v), 1) + ' pts'], ['Résultat net', 'netPct', v => fP(v), v => (v >= 0 ? '+' : '−') + nf(Math.abs(v), 1) + ' pts']];
    // Le B2B (10/10/2026), à la place des messages du panel : le CA des clients pro, sa part du CA dessous.
    defs.splice(4, defs.length - 4, ['B2B · clients pro', 'caPro', fK, v => fSK(v), x => x.partPro != null ? fP(x.partPro) + ' du CA' : '']);
    const ord = n => n === 1 ? '1er' : n + 'e';
    let premiers = 0;
    const tuiles = defs.map(([lib, k, f, fd, extra]) => {
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
        <div class="s">${extra && extra(m) ? extra(m) + ' · ' : ''}${fd(v - med)} vs médiane · ${top ? (second != null ? 'le 2e : ' + f(second) : 'seul en lice') : 'le 1er : ' + f(meilleur)}</div></div>`;
    }).join('');
    return `<div class="db-bench"><div class="db-bt tit"><div class="k">Ta place dans le réseau</div><div class="s">${L.length} magasins ouverts · ${jour ? 'la journée' : (S.vue === 'semaine' ? 'la semaine' : 'le mois')} · anonyme${premiers ? ' · <b>' + premiers + ' × 🏆</b>' : ''}</div><div class="leg"><span><i style="background:var(--color-primary)"></i>toi</span><span><i style="background:#c9c2b8"></i>un autre</span><span><b></b>médiane</span></div></div>${tuiles}</div>`;
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
    if (S.vue === 'jour' || S.vue === 'ops') { return { du: veille(), au: veille(), jour: true }; }
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
      const T = tachesJour(d);
      if (!T.length) { return `<div class="db-taches"><div class="db-bt tit"><div class="k">Les tâches du jour</div><div class="s">${d.indispo ? 'panel injoignable' : 'aucune tâche pour ce magasin ce jour'}</div></div></div>`; }
      const faite = tacheFaite;
      const bloq = t => t.statut === 'nonRendue' && (t.obligatoire != null ? !!t.obligatoire : /^CO-/i.test(String(t.checklist || '')));
      const nF = T.filter(faite).length, nN = T.length - nF, nB = T.filter(bloq).length;
      const nCtrl = T.filter(t => t.statut === 'aControler' || t.statut === 'aValider').length, nSans = T.filter(tacheSansPhoto).length;
      const nQ = T.filter(t => t.statut === 'nonRendue' && !bloq(t)).length;
      // Les checklists, dans l'ordre de la journée.
      const cls = []; const par = {};
      T.forEach(t => { const c = String(t.checklist || 'Sans checklist'); if (!par[c]) { par[c] = []; cls.push(c); } par[c].push(t); });
      // L'ordre de la journée : l'ouverture d'abord, la fermeture en dernier, entre les deux par heure de première tâche rendue.
      const rang = c => /^CO-01/i.test(c) ? '0' : (/^CO-02/i.test(c) ? '9' : '5' + (par[c].filter(t => t.faitLe).map(t => String(t.faitLe)).sort()[0] || '9999') + c);
      cls.sort((a, b) => rang(a).localeCompare(rang(b)));
      const hDe = t => t.faitLe ? String(t.faitLe).slice(11, 16) : '';
      const mini = cls.map((c, i) => (i ? '<span class="sep"></span>' : '') + par[c].map(t => `<i class="${faite(t) ? 'f' : (bloq(t) ? 'b' : 'n')}" title="${esc(t.tache)}${faite(t) ? ' · ' + hDe(t) + (t.faitePar ? ' · ' + esc(t.faitePar) : '') : (tacheSansPhoto(t) ? ' · cochée sans photo' : ' · non rendue')}"></i>`).join('')).join('');
      const dern = T.filter(t => t.faitLe).sort((a, b) => String(b.faitLe).localeCompare(String(a.faitLe)))[0];
      const court = c => c.replace(/^[A-Z]{2}-?[A-Z0-9]+\s*[—–-]\s*/i, '').replace(/\.$/, '');
      corps = `<div class="db-bt tit"><div class="k">Les tâches du jour</div><div class="s">${T.length} obligatoire(s) · ${T.length ? Math.round(100 * nF / T.length) : 0} % faites${dern ? ' · dernière rendue à ' + hDe(dern) + (dern.faitePar ? ' par ' + esc(dern.faitePar) : '') : ''}</div></div>
        ${tuileT('Faites', nF + '<small>/ ' + T.length + '</small>', (nCtrl ? nCtrl + ' à contrôler' : '') || 'avec leur photo', 'ok')}
        ${tuileT('Non faites', nN + '<small>/ ' + T.length + '</small>', [nSans ? nSans + ' cochée(s) sans photo' : '', nQ ? nQ + ' contrôle(s) qualité' : '', nB ? nB + ' d’exploitation' : ''].filter(Boolean).join(' · '), 'wa')}
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

  /* --- Les contrôles en photo ---------------------------------------------
   *
   * Le franchisé qui n'est pas au magasin voit ce que l'équipe a montré et ce
   * que le consultant en a dit : une bande de photos sous les tâches du jour,
   * les écarts d'abord, puis ce qui attend encore le consultant, puis le
   * conforme ; les contrôles jamais rendus y tiennent leur place en case
   * vide. Un clic ouvre la photo en grand, avec les repères, le constat et la
   * tenue de la tâche sur ses derniers contrôles.
   *
   * Deux lectures : la journée (`/pwa/tasks`, déjà lue pour les tâches) et
   * ses photos (`/pwa/tasks/photos`, une seule volée). L'URL signée d'une
   * photo expire après vingt minutes : passé quinze, ou dès qu'une image
   * refuse de se charger, on relit — au plus une fois par minute.
   * On regarde, on n'écrit rien : noter reste dans Contrôle des tâches.
   */
  const CQ_PERIME = 15 * 60 * 1000;
  const CQ_RANG = { nc: 0, ctl: 1, ok: 2, ko: 3, mu: 4 };
  const CQ_FILTRES = [['nc', 'Écarts', '#D97706'], ['ctl', 'À contrôler', '#2F5D8A'], ['ok', 'Conformes', '#2d7a3e'], ['ko', 'Non rendues', '#C0182B'], ['mu', 'Sans photo', '#a59d93']];
  function cleCQ() { return 'cqph|' + S.shop + '|' + S.date; }
  function cheminCQ() { return '/pwa/tasks/photos?shop=' + encodeURIComponent(S.shop) + '&date=' + S.date; }
  /** Relit les photos (URL neuves) ; jamais deux fois dans la même minute. */
  function cqRelire() {
    const k = cleCQ();
    if (S.enCours[k] || Date.now() - (S.cqTente[k] || 0) < 60000) { return; }
    S.cqTente[k] = Date.now();
    lireAux(k, cheminCQ(), true);
  }
  const cqNom = t => String(t.tache || '').replace(/^Photo du comptoir\s*-\s*/i, 'Comptoir · ').replace(/^Contrôle Qualité\s*[–-]\s*/i, 'CQ · ');
  const cqCl = c => String(c || '').replace(/\.$/, '');
  /** Ce que le franchisé doit lire d'une tâche : cinq états, pas davantage. */
  function cqEtat(t, D, seuil) {
    if (t.note != null) {
      if (t.note >= seuil && t.accepte !== false) { return { c: 'ok', bd: t.note + '/5', lib: 'Conforme' }; }
      const n = ncNiveau(D, t.note);
      return { c: 'nc', bd: t.note + '/5 · ' + n.court, lib: 'Non-conformité ' + n.court, coul: n.couleur };
    }
    if (t.statut === 'aControler' || t.statut === 'aValider') { return { c: 'ctl', bd: 'à contrôler', lib: 'Rendue, pas encore notée' }; }
    if (t.statut === 'sansPhoto') { return { c: 'mu', bd: 'sans photo', lib: 'Rendue sans photo' }; }
    return { c: 'ko', bd: 'non rendue', lib: S.date === AUJ ? 'Pas encore rendue' : 'Pas rendue ce jour' };
  }
  /** La journée du magasin, chaque tâche avec sa photo et son état, triée. */
  function cqListe() {
    const d = S.aux['taches|' + S.date];
    if (!d || d.indispo) { return null; }
    const T = tachesJour(d);
    const P = S.aux[cleCQ()];
    // L'URL signée vieillit : au-delà de quinze minutes, on la renouvelle.
    if (P && Date.now() - (S.auxLu[cleCQ()] || 0) > CQ_PERIME) { cqRelire(); }
    const ph = {};
    (P && Array.isArray(P.photos) ? P.photos : []).forEach(p => { if (/^https:\/\//.test(String(p.photo || ''))) { ph[String(p.taskId)] = p; } });
    const cls = P && P.checklists ? P.checklists : {};
    const D = { niveaux: d.repartition }, seuil = d.seuil || 4;
    return T.map(t => {
      const p = ph[String(t.taskId)] || null;
      return Object.assign({}, t, { ph: p, e: cqEtat(t, D, seuil), D: D,
        cl: t.checklist || (p && p.checklist) || cls[String(t.taskId)] || null,
        reperes: p && Array.isArray(p.reperes) ? p.reperes : [] });
    }).sort((a, b) => (CQ_RANG[a.e.c] - CQ_RANG[b.e.c]) || String(a.faitLe || '9').localeCompare(String(b.faitLe || '9')) || cqNom(a).localeCompare(cqNom(b)));
  }
  const cqFiltrees = L => S.cqFiltre === 'tout' ? L : L.filter(x => x.e.c === S.cqFiltre);
  /** « 04:11 » le jour même, « le 26/09 à 05:03 » pour un geste d'un autre jour. */
  const cqQuand = v => !v ? '' : (String(v).slice(0, 10) === S.date ? hhmm(v) : 'le ' + fD(String(v).slice(0, 10)) + ' à ' + hhmm(v));
  function cqMeta(x) {
    if (x.e.c === 'ko') { return S.date === AUJ ? 'pas encore rendue' : 'pas rendue ce jour'; }
    if (x.e.c === 'mu') { return 'clôturée ' + cqQuand(x.faitLe); }
    return x.faitLe ? cqQuand(x.faitLe) + (x.faitePar ? ' · ' + esc(x.faitePar) : '') : 'rendue';
  }
  function cqConstat(x) {
    const quand = x.valideeLe ? ' · noté ' + cqQuand(x.valideeLe) : '';
    if (x.e.c === 'nc') { const r = x.reperes.map(r => r.txt).filter(Boolean); return `<div class="c nc">${esc(r.length ? r.join(', ') : (x.comment || 'écart relevé'))}${quand}</div>`; }
    if (x.e.c === 'ok') { return `<div class="c ok">conforme${quand}</div>`; }
    if (x.e.c === 'ctl') { return '<div class="c ctl">photo déposée, pas encore notée</div>'; }
    if (x.e.c === 'mu') { return `<div class="c mu">${/^auto/i.test(String(x.comment || '')) ? 'clôturée automatiquement' : 'rendue sans photo'}</div>`; }
    return `<div class="c ko">${esc(cqCl(x.cl).replace(/^[A-Z]{2}-?[A-Z0-9]+\s*[—–-]\s*/i, '') || 'non rendue')}</div>`;
  }
  /** La photo, ses repères, l'heure et la pastille ; ou la case vide qui dit pourquoi. */
  function cqPhoto(x, o) {
    o = o || {};
    const P = S.aux[cleCQ()];
    if (!x.ph) {
      const attend = !P && !S.err[cleCQ()] && x.e.c !== 'ko' && x.e.c !== 'mu';
      if (attend) { return `<span class="db-cqph att${o.cls ? ' ' + o.cls : ''}"></span>`; }
      const txt = x.e.c === 'ko' ? (S.date === AUJ ? 'pas encore rendue' : 'pas de photo') : (x.e.c === 'mu' ? 'sans photo' : 'photo indisponible');
      return `<span class="db-cqph vide ${x.e.c}${o.cls ? ' ' + o.cls : ''}"><b>${x.e.c === 'ko' ? '✗' : '—'}</b>${o.mini ? '' : esc(txt)}</span>`;
    }
    const rep = o.mini ? '' : x.reperes.map(r => { const c = ncNiveau(x.D, r.niveau || x.note).couleur;
      return `<i style="left:${(r.x * 100).toFixed(1)}%;top:${(r.y * 100).toFixed(1)}%;width:${(r.l * 100).toFixed(1)}%;height:${(r.h * 100).toFixed(1)}%;border-color:${esc(c)}">${o.txt ? `<u style="background:${esc(c)}">${r.n}. ${esc(r.txt || '')}</u>` : ''}</i>`; }).join('');
    return `<span class="db-cqph${o.cls ? ' ' + o.cls : ''}"><img src="${esc(x.ph.photo)}" alt=""${o.cls === 'max' ? '' : ' loading="lazy"'} data-cqimg="1">${rep}`
      + `${o.heure === false || !x.faitLe ? '' : `<em class="h">${esc(hhmm(x.faitLe))}</em>`}${o.badge === false ? '' : `<em class="cqbd ${x.e.c}">${esc(o.court ? (x.note != null ? x.note + '/5' : x.e.bd) : x.e.bd)}</em>`}</span>`;
  }
  /** Le résumé : qui a rendu quand, qui a noté quand, la moyenne. */
  function cqResume(L) {
    const P = S.aux[cleCQ()];
    const avec = L.filter(x => x.ph), notees = L.filter(x => x.note != null);
    const plage = v => { const s = v.filter(Boolean).map(hhmm).sort(); return !s.length ? '' : (s[0] === s[s.length - 1] ? 'à ' + s[0] : 'de ' + s[0] + ' à ' + s[s.length - 1]); };
    const qui = v => [...new Set(v.filter(Boolean))].join(', ');
    const out = [];
    if (P) {
      const R = avec.filter(x => String(x.faitLe || '').slice(0, 10) === S.date);
      out.push(avec.length + ' photo' + (avec.length > 1 ? 's' : '') + (R.length ? ' rendue' + (R.length > 1 ? 's' : '') + ' ' + plage(R.map(x => x.faitLe)) + (qui(R.map(x => x.faitePar)) ? ' par ' + esc(qui(R.map(x => x.faitePar))) : '') : ''));
    } else { out.push(S.err[cleCQ()] ? 'photos : ' + esc(S.err[cleCQ()]) : 'lecture des photos…'); }
    if (notees.length) {
      const moy = notees.reduce((a, x) => a + x.note, 0) / notees.length;
      out.push(notees.length + ' notée' + (notees.length > 1 ? 's' : '') + ' ' + plage(notees.map(x => x.valideeLe)) + (qui(notees.map(x => x.valideePar || x.consultant)) ? ' par ' + esc(qui(notees.map(x => x.valideePar || x.consultant))) : '') + ' · moyenne ' + nf(moy, 1) + ' / 5');
    } else if (avec.length) { out.push('pas encore notées'); }
    if (P && P.api && P.api.erreur && !avec.length) { out.push('panel : ' + esc(P.api.erreur)); }
    return out.join(' · ');
  }
  function cqCarte(x, mobile) {
    return `<button type="button" class="db-cqc" data-cqvoir="${esc(x.taskId)}">${cqPhoto(x, { court: mobile, heure: !mobile })}`
      + `<span class="n">${esc(mobile ? cqNom(x).replace(/^(Comptoir|CQ) · /, '') : cqNom(x))}</span><span class="m">${cqMeta(x)}</span>${mobile ? '' : cqConstat(x)}</button>`;
  }
  function rendCQ(mobile) {
    if (S.vue !== 'jour' && S.vue !== 'ops') { return ''; }
    const L = cqListe();
    if (L === null) {
      if (mobile || S.err['taches|' + S.date]) { return ''; }
      return `<div class="db-card db-cq"><div class="ct"><span class="db-lab">Les contrôles en photo</span><span class="db-mini">lecture du panel…</span></div><div class="db-cqrail"><div class="db-cqpiste">${Array.from({ length: 6 }, () => '<span class="db-cqc"><span class="db-cqph att"></span></span>').join('')}</div></div></div>`;
    }
    if (!L.length) { return ''; }
    const n = c => L.filter(x => x.e.c === c).length;
    if (S.cqFiltre !== 'tout' && !n(S.cqFiltre)) { S.cqFiltre = 'tout'; }
    const F = cqFiltrees(L);
    const jour = fD(S.date);
    if (mobile) {
      const alerte = [n('nc') ? n('nc') + ' écart' + (n('nc') > 1 ? 's' : '') : '', n('ctl') ? n('ctl') + ' à contrôler' : ''].filter(Boolean).join(' · ');
      return `<div class="mb-cq"><div class="k">Les contrôles en photo${alerte ? ` · <em>${alerte}</em>` : ''}</div><div class="s">${cqResume(L)}</div>
        <div class="db-cqpiste" id="db-cqpiste">${L.map(x => cqCarte(x, true)).join('')}</div></div>`;
    }
    const puces = [`<button type="button" data-cqf="tout" class="${S.cqFiltre === 'tout' ? 'on' : ''}">Tout<b>${L.length}</b></button>`]
      .concat(CQ_FILTRES.filter(f => n(f[0])).map(f => `<button type="button" data-cqf="${f[0]}" class="${S.cqFiltre === f[0] ? 'on' : ''}"><i style="background:${f[2]}"></i>${f[1]}<b>${n(f[0])}</b></button>`)).join('');
    return `<div class="db-card db-cq"><div class="ct"><span class="db-lab">Les contrôles en photo — ${esc(jour)}</span><span class="db-cqf">${puces}</span><span class="db-mini">${cqResume(L)}</span></div>
      <div class="db-cqrail"><button type="button" class="db-cqfl g" data-cqpas="-1" aria-label="précédentes">‹</button>
        <div class="db-cqpiste" id="db-cqpiste">${F.map(x => cqCarte(x, false)).join('')}</div>
        <button type="button" class="db-cqfl d" data-cqpas="1" aria-label="suivantes">›</button></div>
      <div class="db-cqpied" id="db-cqpied">${F.length} contrôle${F.length > 1 ? 's' : ''}${S.cqFiltre === 'tout' ? ' · les écarts d’abord' : ''} · un clic ouvre la photo en grand</div></div>`;
  }
  /** La photo en grand : repères, constat, tenue de la tâche ; ‹ › et Échap. */
  function cqLoupe(mobile) {
    if (!S.cqVoir || (S.vue !== 'jour' && S.vue !== 'ops')) { return ''; }
    const L = cqListe();
    const F = L ? cqFiltrees(L) : [];
    const i = F.findIndex(x => String(x.taskId) === String(S.cqVoir));
    if (i < 0) { S.cqVoir = null; return ''; }
    const x = F[i];
    const note = x.note != null ? `${x.note}/5${x.valideeLe ? ' ' + (String(x.valideeLe).slice(0, 10) === S.date ? 'à ' : '') + cqQuand(x.valideeLe) : ''}${(x.valideePar || x.consultant) ? ' par ' + esc(x.valideePar || x.consultant) : ''}`
      : (x.e.c === 'ctl' ? 'pas encore notée' : '—');
    const M = x.maitrise || {};
    const tenue = M.moyenne != null ? `${nf(M.moyenne, 1)} / 5 sur ${M.nb} contrôle${M.nb > 1 ? 's' : ''}${M.masquee && M.recontrole ? ` · maîtrisée, recontrôlée le ${esc(fD(M.recontrole))}` : ''}`
      : (M.nb === 0 ? 'pas encore d’historique' : '');
    const reps = x.reperes.filter(r => r.txt);
    const constat = x.comment && !/^auto/i.test(x.comment) ? x.comment : '';
    const q = reps.length || constat ? `<q class="${x.e.c}">${reps.map(r => `<b>${r.n}. ${esc(r.txt)}</b>`).join('<br>')}${constat && (!reps.length || !reps.some(r => constat.indexOf(r.txt) >= 0)) ? (reps.length ? '<br>' : '') + esc(constat) : ''}</q>` : '';
    const rendu = x.e.c === 'ko' ? (S.date === AUJ ? 'pas encore rendue' : 'pas rendue ce jour')
      : (x.faitLe ? (String(x.faitLe).slice(0, 10) === S.date ? 'à ' : '') + cqQuand(x.faitLe) + (x.faitePar ? ' par ' + esc(x.faitePar) : '') + (x.e.c === 'mu' ? ', sans photo' : '') : 'rendue');
    const fiche = `<div class="fi"><em class="cqbd ${x.e.c}">${esc(x.e.bd)}</em><h3>${esc(cqNom(x))}</h3>${x.cl ? `<div class="q">${esc(cqCl(x.cl))}</div>` : ''}${q}
      <dl><dt>Rendue</dt><dd>${rendu}</dd><dt>Notée</dt><dd>${note}</dd>${tenue ? `<dt>Tenue</dt><dd>${tenue}</dd>` : ''}</dl>
      ${x.ph ? `<a class="db-lien" href="${esc(x.ph.photo)}" target="_blank" rel="noopener">Ouvrir la photo seule ↗</a>` : ''}</div>`;
    const nav = `${i + 1} / ${F.length}${S.cqFiltre !== 'tout' ? ' · ' + esc((CQ_FILTRES.find(f => f[0] === S.cqFiltre) || [])[1] || '').toLowerCase() : ''}`;
    if (mobile) {
      return `<div class="db-cql mob" role="dialog" aria-label="${esc(cqNom(x))}"><div class="hd"><span>${nav}</span><button type="button" class="x" data-cqfermer="1" aria-label="fermer">✕</button></div>
        <div class="ph" data-cqswipe="1">${cqPhoto(x, { txt: true, heure: false, badge: false, cls: 'max' })}</div>
        <div class="bas">${fiche}<div class="act"><button type="button" data-cqnav="-1"${i ? '' : ' disabled'}>‹ Précédente</button><button type="button" class="p" data-cqnav="1"${i < F.length - 1 ? '' : ' disabled'}>Suivante ›</button></div></div></div>`;
    }
    return `<div class="db-cql" role="dialog" aria-label="${esc(cqNom(x))}"><div class="hd"><span>${nav}</span><b>${esc(cqNom(x))}</b>${x.cl ? `<small>${esc(cqCl(x.cl))}</small>` : ''}<button type="button" class="x" data-cqfermer="1" aria-label="fermer">✕</button></div>
      <div class="mi"><button type="button" class="fl" data-cqnav="-1"${i ? '' : ' disabled'} aria-label="précédente">‹</button>
        <div class="ph">${cqPhoto(x, { txt: true, heure: false, badge: false, cls: 'max' })}</div>${fiche}
        <button type="button" class="fl" data-cqnav="1"${i < F.length - 1 ? '' : ' disabled'} aria-label="suivante">›</button></div>
      <div class="ba" id="db-cqba">${F.map(y => `<button type="button" data-cqvoir="${esc(y.taskId)}" class="${y === x ? 'on' : ''}" title="${esc(cqNom(y))}">${cqPhoto(y, { mini: true, heure: false, badge: false })}</button>`).join('')}</div></div>`;
  }
  function cqAller(pas) {
    const L = cqListe(); if (!L) { return; }
    const F = cqFiltrees(L);
    const i = F.findIndex(x => String(x.taskId) === String(S.cqVoir));
    const j = i + pas;
    if (i >= 0 && j >= 0 && j < F.length) { S.cqVoir = String(F[j].taskId); rendre(); }
  }
  /** La bande garde sa position quand la page se redessine — et, au
   * téléphone, le mur aussi : ouvrir une photo ne doit pas ramener en haut. */
  function cqGarder() {
    const p = document.getElementById('db-cqpiste'), sc = $.querySelector('.mb-sc');
    return { x: p ? p.scrollLeft : 0, y: sc ? sc.scrollTop : 0, ou: S.vue + '|' + S.date + '|' + S.shop };
  }
  function cqRestaurer(g) {
    const meme = g && g.ou === S.vue + '|' + S.date + '|' + S.shop;
    const p = document.getElementById('db-cqpiste'), sc = $.querySelector('.mb-sc');
    if (sc && meme && g.y) { sc.scrollTop = g.y; }
    if (p) { if (S.cqRaz || !meme) { S.cqRaz = false; p.scrollLeft = 0; } else { p.scrollLeft = g.x; } cqFleches(); }
    const ba = document.getElementById('db-cqba'), on = ba && ba.querySelector('.on');
    if (on) { ba.scrollLeft = on.offsetLeft - ba.clientWidth / 2 + on.clientWidth / 2; }
    document.documentElement.classList.toggle('db-cq-ouvert', !!S.cqVoir && (S.vue === 'jour' || S.vue === 'ops'));
  }
  /** Flèches éteintes aux deux bouts, et le compte de ce qu'on voit. */
  function cqFleches() {
    const p = document.getElementById('db-cqpiste'); if (!p) { return; }
    const g = $.querySelector('.db-cqfl.g'), d = $.querySelector('.db-cqfl.d');
    if (g) { g.disabled = p.scrollLeft <= 2; }
    if (d) { d.disabled = p.scrollLeft + p.clientWidth >= p.scrollWidth - 2; }
    const pied = document.getElementById('db-cqpied'), c = p.querySelector('.db-cqc');
    if (pied && c) {
      const w = c.getBoundingClientRect().width + 12, n = p.children.length;
      const a = Math.min(n, Math.round(p.scrollLeft / w) + 1), b = Math.min(n, Math.round((p.scrollLeft + p.clientWidth) / w));
      if (b < n || a > 1) { pied.textContent = a + ' – ' + b + ' sur ' + n + (S.cqFiltre === 'tout' ? ' · les écarts d’abord' : '') + ' · un clic ouvre la photo en grand'; }
    }
  }

  /* --- Réclamations fournisseur, au téléphone -------------------------------
   * Un onglet à part, « Réclamation » : un formulaire sur un seul écran — la
   * photo (l'appareil s'ouvre directement), le produit, combien, le problème,
   * un mot — puis les réclamations du magasin sur douze mois.
   * La livraison suit le produit : la plus récente reçue de son fournisseur,
   * modifiable. La demande est un remplacement, comme 38 réclamations sur 40.
   *
   * La route de création du panel ne prend que du texte : les photos sont
   * gardées par le cockpit et leurs liens partent dans la description. */
  const RC_MAX = 4;
  const RC_MOTIFS = { size_or_weight_issue: 'Taille ou poids', broken_packaging: 'Emballage abîmé', incorrect_information: 'Informations incorrectes',
    lack_of_conformity: 'Non conforme à la commande', incorrect_quantity_of_products_in_package: 'Quantité dans l’emballage',
    product_quality: 'Qualité du produit', other: 'Autre raison' };
  function cleRC() { return 'rcl|' + S.shop; }
  function cheminRC() { return '/fournisseurs/reclamations?shop=' + encodeURIComponent(S.shop) + '&mois=12'; }
  function cleRCR() { return 'rcr|' + S.shop; }
  function cheminRCR() { return '/fournisseurs/reclamation-refs?shop=' + encodeURIComponent(S.shop); }
  const rcPremiere = t => String(t || '').split(/\r?\n/).map(x => x.trim()).find(Boolean) || '';
  const rcSansBonjour = t => String(t || '').replace(/^\s*bonjour[\s,.!]*/i, '');
  const rcJours = d => d ? Math.max(0, Math.round((new Date(AUJ + 'T12:00:00') - new Date(d + 'T12:00:00')) / 86400000)) : null;
  const rcPl = (n, mot) => n + ' ' + mot + (n > 1 ? 's' : '');
  const rcNorm = s => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  /** Une citation courte se coupe entre deux mots, pas au milieu d'un. */
  const rcCoupe = (t, n) => { t = String(t || ''); if (t.length <= n) { return t; } const c = t.slice(0, n), i = c.lastIndexOf(' '); return (i > n * 0.6 ? c.slice(0, i) : c).replace(/[\s,;:.(«-]+$/, '') + '…'; };
  // Le porte-voix de l'onglet Campagne, dans le trait des autres icônes.
  const CP_ICONE = '<svg viewBox="0 0 24 24" width="19" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10v4a1 1 0 0 0 1 1h3l8 4V5L7 9H4a1 1 0 0 0-1 1z"/><path d="M18.5 8.5a5 5 0 0 1 0 7"/><path d="M8 15v4h3"/></svg>';
  const RC_ICONE = '<svg viewBox="0 0 24 24" width="19" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13" r="3.5"/></svg>';

  /** L'état d'une réclamation, vu du magasin : « ouverte » n'est pas un
   * statut du panel, c'est une réclamation que personne n'a suivie d'effet. */
  function rcEtat(l) {
    if (l.statut === 'REJECTED') { return { c: 'ko', lib: 'Refusée' }; }
    if (l.statut === 'ACCEPTED' && l.reponse) { return { c: 'ok', lib: 'Réglée' }; }
    if (l.statut === 'ACCEPTED') { return { c: 'sans', lib: 'Acceptée · sans suite' }; }
    if (l.reponse) { return { c: 'ok', lib: 'Répondue' }; }
    return { c: 'att', lib: 'Envoyée · en attente' };
  }
  function rcLignes() { const D = S.aux[cleRC()]; return D && Array.isArray(D.lignes) ? D.lignes : []; }

  function rcCarte(l) {
    const e = rcEtat(l), j = rcJours(l.le);
    const P = Array.isArray(l.photos) ? l.photos : [];
    const nPh = P.length + (l.pj || 0);
    let rep;
    if (e.c === 'ok' || e.c === 'ko') {
      rep = `<div class="rep ${e.c}"><b>${esc(l.fournisseur)}${l.reponseLe ? ' · ' + fD(l.reponseLe) : ''} :</b> ${esc(rcCoupe(rcPremiere(rcSansBonjour(l.reponse)), 160))}</div>`;
    } else if (e.c === 'sans') {
      rep = `<div class="rep sans">Acceptée, aucune suite${j != null ? ' depuis ' + rcPl(j, 'jour') : ''} — à relancer chez ${esc(l.fournisseur)}</div>`;
    } else {
      rep = `<div class="rep att">Envoyée ${j ? 'il y a ' + rcPl(j, 'jour') : 'aujourd’hui'} — en attente de ${esc(l.fournisseur)}</div>`;
    }
    const bouts = [];
    if (l.qte != null) { bouts.push(nf(l.qte, l.qte % 1 ? 1 : 0) + (l.unite ? ' ' + esc(l.unite) : '')); }
    if (l.motif) { bouts.push(esc(RC_MOTIFS[l.motifCode] || l.motif)); }
    if (l.montant) { bouts.push(fE(l.montant)); }
    bouts.push(nPh ? '📷 ' + rcPl(nPh, 'photo') : 'sans photo');
    const tx = rcPremiere(l.texte);
    return `<div class="rc-carte"><div class="l1"><span class="rc-st ${e.c}">${esc(e.lib)}</span><span class="d">${esc(fD(l.le))}${l.id ? ' · n° ' + l.id : ''}</span></div>
      <b class="ref">${esc(l.reference || 'Réclamation')}</b><div class="l2">${bouts.join(' · ')}</div>
      ${tx ? `<div class="tx">${esc(tx)}</div>` : ''}
      ${P.length ? `<div class="rc-vign">${P.map(c => `<a href="../${esc(c)}" target="_blank" rel="noopener"><img src="../${esc(c)}" alt="" loading="lazy"></a>`).join('')}</div>` : ''}${rep}</div>`;
  }

  /* Le brouillon du formulaire : il survit au changement d'onglet. */
  function rcNeuf() { return { photos: [], traite: 0, matiere: null, q: '', livraison: null, qte: '1', motif: null, note: '', envoi: false, err: null, scan: null }; }
  function rcForm() { if (!S.rc) { S.rc = rcNeuf(); } return S.rc; }
  /** Scanner ou saisir le produit : le dernier choix du téléphone, le scan d'abord. */
  function rcMode() {
    if (!S.rcMode) { try { S.rcMode = localStorage.getItem('db.rcMode') === 'saisie' ? 'saisie' : 'scan'; } catch (e) { S.rcMode = 'scan'; } }
    return S.rcMode;
  }
  function rcRefs() { const F = S.aux[cleRCR()]; return F && !F.indispo ? F : null; }
  function rcFournNom(F, id) { const f = (F && F.fournisseurs || []).find(x => String(x.id) === String(id)); return f ? String(f.nom).trim() : 'le fournisseur'; }
  function rcMatiere(F, R) { return R.matiere ? (F.matieres || []).find(m => String(m.id) === String(R.matiere)) || null : null; }
  function rcQte(R) { const n = parseFloat(String(R.qte).replace(',', '.')); return isFinite(n) && n > 0 ? Math.round(n * 100) / 100 : null; }
  /** Les livraisons du fournisseur du produit : les reçues d'abord (déjà
   * réclamées, ou attendues à ce jour), la plus récente en tête. */
  function rcLivraisons(F, m) {
    const L = m ? (F.livraisons || []).filter(l => String(l.fournisseur) === String(m.fournisseur)) : [];
    const recue = l => l.source !== 'en cours' || (l.attendue && l.attendue <= AUJ);
    const date = l => l.attendue || l.le || '';
    return L.filter(recue).sort((a, b) => date(b).localeCompare(date(a))).concat(L.filter(l => !recue(l)).sort((a, b) => date(a).localeCompare(date(b))));
  }
  function rcLivraison(F, R) {
    const L = rcLivraisons(F, rcMatiere(F, R));
    return L.find(l => String(l.id) === String(R.livraison)) || L[0] || null;
  }
  function rcLivLib(l) {
    const quand = l.source !== 'en cours' ? 'réclamée le ' + fD(l.le)
      : (l.attendue ? (l.attendue <= AUJ ? 'attendue le ' : 'à venir le ') + fD(l.attendue) : 'commandée le ' + fD(l.le));
    return quand + ' · …' + String(l.cle || l.id).slice(-6);
  }
  /** Les produits que CE magasin a déjà réclamés : on les retrouve d'une
   * livraison à l'autre, ils viennent en premier. */
  function rcHabituels(F) {
    const n = {};
    rcLignes().forEach(l => {
      const m = (F.matieres || []).find(x => x.sku && x.sku === l.sku && rcFournNom(F, x.fournisseur) === String(l.fournisseur).trim())
        || (F.matieres || []).find(x => x.sku && x.sku === l.sku);
      if (m) { n[m.id] = (n[m.id] || 0) + 1; }
    });
    return Object.keys(n).sort((a, b) => n[b] - n[a]).slice(0, 4).map(id => (F.matieres || []).find(m => String(m.id) === id)).filter(Boolean);
  }
  function rcSuggestions() {
    const R = rcForm(), F = rcRefs(); if (!F) { return ''; }
    const q = rcNorm(R.q).trim();
    if (q.length < 2) { return ''; }
    const M = (F.matieres || []).filter(m => rcNorm(m.nom).includes(q) || String(m.sku).startsWith(q)).slice(0, 7);
    if (!M.length) { return `<div class="vide">Aucun produit ne contient « ${esc(R.q)} ».</div>`; }
    return M.map(m => `<button data-rcmat="${esc(m.id)}"><span>${esc(m.nom)}</span><small>${esc(rcFournNom(F, m.fournisseur))} · SKU ${esc(m.sku)}${m.unite ? ' · ' + esc(m.unite) : ''}</small></button>`).join('');
  }
  /** Ce qui manque pour envoyer, dans l'ordre de l'écran. */
  function rcManque(F, R) {
    const m = rcMatiere(F, R);
    if (!m) { return 'Choisir le produit'; }
    if (!rcLivraison(F, R)) { return 'Aucune livraison de ' + rcFournNom(F, m.fournisseur); }
    if (!rcQte(R)) { return 'Indiquer combien'; }
    if (!R.motif) { return 'Choisir le problème'; }
    return '';
  }
  function rcValeur(F, R) {
    const m = F ? rcMatiere(F, R) : null, q = rcQte(R);
    if (!m || !q || m.prix == null) { return ''; }
    return `${nf(q, q % 1 ? 2 : 0)} × ${fU(m.prix)} = <b>${fE(q * m.prix)}</b>`;
  }
  function rcBoutonEnvoi(F, R) {
    const manque = F ? rcManque(F, R) : 'Lecture des produits…';
    const lib = R.envoi ? 'Envoi…' : (manque || 'Envoyer à ' + esc(rcFournNom(F, rcMatiere(F, R).fournisseur)));
    return `<button id="rc-envoyer" class="rc-btn" data-rcenvoyer="1" ${manque || R.envoi ? 'disabled' : ''}>${lib}</button>`;
  }

  /** Le formulaire : cinq lignes, dans l'ordre où on les vit au comptoir. */
  function rcFormulaire() {
    const R = rcForm(), cle = cleRCR(), F0 = S.aux[cle], F = rcRefs();
    let h = '';
    const f = S.rcFait;
    if (f) {
      h += `<div class="rc-fait"><span class="rond">✓</span><div><b>Envoyée à ${esc(f.fournisseur)}</b><small>${esc(f.nom)} · ${nf(f.qte, f.qte % 1 ? 2 : 0)}${f.unite ? ' ' + esc(f.unite) : ''} · ${f.photos ? rcPl(f.photos, 'photo') : 'sans photo'}${f.id ? ' · n° ' + f.id : ''}</small></div><button data-rcfaitx="1" aria-label="Fermer">✕</button></div>`;
    }
    // 1. La photo : un seul bouton, l'appareil s'ouvre.
    const P = R.photos, plein = P.length + R.traite >= RC_MAX;
    h += '<div class="rc-champ"><label>La photo</label>';
    if (P.length || R.traite) {
      h += `<div class="rc-photos">${P.map((p, i) => `<div class="ph"><img src="${p.url}" alt=""><button data-rcsuppr="${i}" aria-label="Retirer la photo">✕</button></div>`).join('')}`
        + (R.traite ? '<div class="ph att"><span>…</span></div>' : '')
        + (plein ? '' : '<button class="plus" data-rcphoto="1" aria-label="Une autre photo"><b>＋</b>Une autre</button>') + '</div>';
    } else {
      h += '<button class="rc-prise" data-rcphoto="1"><b>📷</b><span>Prendre la photo</span></button>';
      const ko = rcLignes().filter(l => l.statut === 'REJECTED' && !l.pj && !(l.photos || []).length && !l.texte);
      if (ko.length) { h += `<div class="rc-aide">${esc(ko[0].fournisseur)} a refusé ${rcPl(ko.length, 'réclamation')} envoyée${ko.length > 1 ? 's' : ''} sans photo ni note : le produit en entier et l’étiquette du carton suffisent.</div>`; }
    }
    h += '</div>';
    // 2. Le produit — et la livraison qui le suit.
    h += '<div class="rc-champ"><label>Le produit</label>';
    if (F) { rcResoudre(F, R); }
    const sc = R.scan, mSel = F ? rcMatiere(F, R) : null, mode = rcMode();
    // Deux façons de dire le produit : scanner l'étiquette, ou le saisir. Le
    // téléphone retient la dernière. Un code inconnu ouvre la saisie : le
    // produit est choisi une fois, puis reconnu.
    const saisie = mode === 'saisie' || (sc && sc.etat === 'inconnu');
    if (!mSel) {
      h += `<div class="rc-mode" role="tablist">${[['scan', RC_CODEBARRE + '<span>Scanner</span>'], ['saisie', '<b aria-hidden="true">⌨</b><span>Saisir</span>']]
        .map(o => `<button role="tab" aria-selected="${mode === o[0]}" data-rcmode="${o[0]}" class="${mode === o[0] ? 'on' : ''}">${o[1]}</button>`).join('')}</div>`;
      if (mode === 'scan') {
        h += `<button class="rc-scan" data-rcscan="1"${sc && sc.etat === 'lecture' ? ' disabled' : ''}>${RC_CODEBARRE}<span>${sc && sc.etat === 'lecture' ? 'Lecture du code-barres…' : 'Scanner l’étiquette'}</span></button>`;
        // En direct, la photo reste possible ; en http, on montre où le direct marche.
        const https = rcAdresseHttps();
        if (rcDirectPossible()) { h += '<button class="rc-lien rc-scanalt" data-rcscanphoto="1">ou prendre l’étiquette en photo</button>'; }
        else if (https) { h += `<a class="rc-scanalt" href="${esc(https)}">Scan en direct avec la caméra : ouvrir le cockpit en https ›</a>`; }
      }
    }
    if (sc && sc.etat === 'illisible') { h += '<div class="rc-scanmsg ko">Aucun code-barres lu sur la photo. Reprenez-la de plus près, bien à plat, sans reflet — ou passez sur « Saisir ».</div>'; }
    if (sc && sc.etat === 'erreur') { h += `<div class="rc-scanmsg ko">${esc(sc.err)}</div>`; }
    if (sc && sc.etat === 'lu') { h += `<div class="rc-scanmsg">Code <b>${esc(sc.code)}</b> lu — recherche du produit…</div>`; }
    if (sc && sc.etat === 'inconnu') { h += `<div class="rc-scanmsg">Code <b>${esc(sc.code)}</b> lu, pas encore connu : choisissez le produit une fois, il sera reconnu au prochain scan.</div>`; }
    if (!F0 && S.err[cle]) { h += `<div class="rc-err">${esc(S.err[cle])} <button class="rc-lien" data-recharger="1">relire</button></div>`; }
    else if (!F0) { if (saisie || mSel) { h += '<div class="rc-pt">lecture des produits du magasin…</div>'; } }
    else if (!F) { h += `<div class="rc-err">${esc(F0.motif || 'produits indisponibles')}</div>`; }
    else {
      const m = mSel;
      if (m) {
        const L = rcLivraisons(F, m), liv = rcLivraison(F, R);
        h += `<div class="rc-choix"><div><b>${esc(m.nom)}</b><small>${esc(rcFournNom(F, m.fournisseur))} · SKU ${esc(m.sku)}${m.unite ? ' · ' + esc(m.unite) : ''}</small></div><button data-rcmatx="1" aria-label="Changer de produit">✕</button></div>`;
        const et = rcEtiquette(sc);
        if (sc && sc.code && (sc.etat === 'ok' || sc.etat === 'appris')) {
          h += `<div class="rc-scanmsg ok">${RC_CODEBARRE}<span>${sc.etat === 'appris' ? 'Code retenu : reconnu au prochain scan' : 'Reconnu par le code-barres'}${et ? ' · ' + esc(et) : ''}</span></div>`;
        } else if (et) { h += `<div class="rc-scanmsg ok">${RC_CODEBARRE}<span>${esc(et)}</span></div>`; }
        h += L.length
          ? `<label class="rc-liv">Livraison <select id="rc-liv" data-rcliv="1">${L.map(l => `<option value="${esc(l.id)}"${liv && String(l.id) === String(liv.id) ? ' selected' : ''}>${esc(rcLivLib(l))}</option>`).join('')}</select></label>`
          : `<div class="rc-err">Aucune livraison de ${esc(rcFournNom(F, m.fournisseur))} connue pour ce magasin : la réclamation ne peut pas partir d’ici.</div>`;
      } else if (saisie) {
        const H = rcHabituels(F);
        if (H.length) { h += `<div class="rc-puces">${H.map(x => `<button data-rcmat="${esc(x.id)}">${esc(x.nom)}</button>`).join('')}</div>`; }
        h += `<input id="rc-q" class="rc-in" data-rcq="1" type="search" autocomplete="off" enterkeyhint="search" placeholder="🔍 ${H.length ? 'Un autre produit' : 'Chercher le produit'} — nom ou SKU" value="${esc(R.q)}">`
          + `<div id="rc-sugg" class="rc-sugg">${rcSuggestions()}</div>`;
      }
    }
    h += '</div>';
    // 3. Combien.
    const mu = F ? rcMatiere(F, R) : null;
    h += `<div class="rc-champ"><label for="rc-qte">Combien${mu && mu.unite ? ' · en ' + esc(mu.unite) : ''}</label><div class="rc-ql"><div class="rc-qte"><button data-rcpas="-1" aria-label="Un de moins">−</button><input id="rc-qte" data-rcqte="1" inputmode="decimal" autocomplete="off" value="${esc(R.qte)}"><button data-rcpas="1" aria-label="Un de plus">＋</button></div><span id="rc-valeur" class="rc-val">${rcValeur(F, R)}</span></div></div>`;
    // 4. Le problème.
    const motifs = F && F.motifs && F.motifs.length ? F.motifs : Object.keys(RC_MOTIFS).map(k => ({ code: k, nom: RC_MOTIFS[k] }));
    h += `<div class="rc-champ"><label>Le problème</label><div class="rc-puces">${motifs.map(x => `<button data-rcmotif="${esc(x.code)}" class="${R.motif === x.code ? 'on' : ''}">${esc(RC_MOTIFS[x.code] || x.nom)}</button>`).join('')}</div></div>`;
    // 5. Un mot.
    h += `<div class="rc-champ"><label for="rc-note">Un mot pour le fournisseur <em>facultatif</em></label><textarea id="rc-note" class="rc-in" data-rcnote="1" rows="3" maxlength="1500" placeholder="Ce qui ne va pas, comment vous l’avez vu…">${esc(R.note)}</textarea></div>`;
    if (R.err) { h += `<div class="rc-err">${esc(R.err)}</div>`; }
    h += `<div class="rc-envoi">${rcBoutonEnvoi(F, R)}</div>`;
    return `<div class="rc-form" data-scan="${R.scan ? R.scan.etat : ''}" data-nscan="${R.nScan || 0}">${h}</div>`;
  }

  /** Sous le formulaire : ce qui a déjà été réclamé, et ce qu'il en est. */
  function rcHistorique() {
    const cle = cleRC(), D = S.aux[cle], L = rcLignes();
    let h = '<div class="rc-hist"><div class="rc-lab">Mes réclamations · 12 derniers mois</div>';
    if (!D) { return h + (S.err[cle] ? `<div class="rc-err">${esc(S.err[cle])}</div>` : '<div class="rc-pt">lecture du panel…</div>') + '</div>'; }
    if (D.indispo) { return h + `<div class="rc-err">${esc(D.motif || 'indisponible')}</div></div>`; }
    if (!L.length) { return h + '<div class="rc-pt">Aucune réclamation en 12 mois.</div></div>'; }
    const n = c => L.filter(l => rcEtat(l).c === c).length;
    const F = [['tout', 'Toutes', L.length], ['att', 'En attente', n('att')], ['sans', 'Sans suite', n('sans')], ['ok', 'Réglées', n('ok')], ['ko', 'Refusées', n('ko')]];
    if (S.rcFiltre !== 'tout' && !n(S.rcFiltre)) { S.rcFiltre = 'tout'; }
    const vis = S.rcFiltre === 'tout' ? L : L.filter(l => rcEtat(l).c === S.rcFiltre);
    h += `<div class="rc-resume">${D.ouvertes ? `<b>${D.ouvertes}</b> ouverte${D.ouvertes > 1 ? 's' : ''}` : 'Tout est réglé'}${D.montantOuvert ? ' · ' + fE(D.montantOuvert) + ' au prix d’achat' : ''}</div>`;
    h += `<div class="rc-filtres">${F.filter(f => f[0] === 'tout' || f[2]).map(f => `<button data-rcfiltre="${f[0]}" class="${S.rcFiltre === f[0] ? 'on' : ''}">${f[1]}<b>${f[2]}</b></button>`).join('')}</div>`;
    return h + vis.map(rcCarte).join('') + '</div>';
  }
  function rendReclamation() {
    return `<div class="mb-hd"><img src="../assets/img/logo.png" alt="">
      <div><div class="t">${esc(nomShop())}</div><div class="d">Réclamation fournisseur</div></div>
      <span class="sp"></span><button class="mb-ic" data-recharger="1">↻</button></div>
      <div class="mb-sc"><div class="rc-vue">${rcFormulaire()}${rcHistorique()}</div></div>${mbOnglets()}`;
  }
  /** Le champ actif garde son focus et sa sélection quand la page se redessine. */
  function rcGarder() {
    const a = document.activeElement;
    if (!a || !a.id || !/^rc-/.test(a.id) || !$.contains(a)) { return null; }
    let deb = null, fin = null; try { deb = a.selectionStart; fin = a.selectionEnd; } catch (e) { /* champ sans sélection */ }
    return { id: a.id, deb: deb, fin: fin };
  }
  function rcRestaurer(g) {
    if (!g) { return; }
    const n = document.getElementById(g.id); if (!n) { return; }
    try { n.focus({ preventScroll: true }); if (g.deb != null) { n.setSelectionRange(g.deb, g.fin); } } catch (e) { /* champ retiré */ }
  }
  /** La quantité change : la valeur et le bouton suivent, sans redessiner. */
  function rcMajEnvoi() {
    const R = rcForm(), F = rcRefs();
    const v = document.getElementById('rc-valeur'); if (v) { v.innerHTML = rcValeur(F, R); }
    const b = document.getElementById('rc-envoyer'); if (b) { b.outerHTML = rcBoutonEnvoi(F, R); }
  }

  /* La photo : l'appareil, directement — un sélecteur permanent, hors de la
   * page, qu'aucune relecture ne redessine pendant que l'appareil est ouvert. */
  let rcFichier = null;
  function rcAppareil() {
    if (!rcFichier) {
      rcFichier = document.createElement('input');
      rcFichier.type = 'file'; rcFichier.accept = 'image/*'; rcFichier.hidden = true;
      rcFichier.setAttribute('capture', 'environment');
      rcFichier.addEventListener('change', () => { rcAjouter(rcFichier.files); rcFichier.value = ''; });
      document.body.appendChild(rcFichier);
    }
    rcFichier.click();
  }
  /** Réduite au téléphone (1 600 px, JPEG), comme les photos des plans. */
  function rcReduire(f) {
    return new Promise((ok, ko) => {
      const u = URL.createObjectURL(f), img = new Image();
      img.onload = () => {
        const k = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
        const cv = document.createElement('canvas');
        cv.width = Math.max(1, Math.round(img.naturalWidth * k)); cv.height = Math.max(1, Math.round(img.naturalHeight * k));
        cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
        URL.revokeObjectURL(u);
        cv.toBlob(b => b ? ok(b) : ko(new Error('photo illisible')), 'image/jpeg', 0.82);
      };
      img.onerror = () => { URL.revokeObjectURL(u); ko(new Error('photo illisible')); };
      img.src = u;
    });
  }
  const rcDataUrl = b => new Promise((ok, ko) => { const r = new FileReader(); r.onload = () => ok(r.result); r.onerror = () => ko(r.error); r.readAsDataURL(b); });
  function rcAjouter(files) {
    const R = rcForm();
    const L = Array.from(files || []).slice(0, Math.max(0, RC_MAX - R.photos.length - R.traite));
    if (!L.length) { return; }
    S.rcFait = null; R.err = null; R.traite += L.length; rendre();
    L.forEach(f => rcReduire(f)
      .then(b => { if (S.rc === R) { R.photos.push({ blob: b, url: URL.createObjectURL(b) }); } })
      .catch(() => { if (S.rc === R) { R.err = 'La photo n’a pas pu être lue — reprenez-la.'; } })
      .finally(() => { if (S.rc === R) { R.traite--; rendre(); } }));
  }

  /* --- Le scan de l'étiquette ------------------------------------------------
   * Le site est servi en http : ni caméra en direct (getUserMedia) ni
   * BarcodeDetector, réservés aux pages sécurisées. On fait donc comme pour
   * la photo : l'appareil s'ouvre, la photo revient, et le code-barres est lu
   * dessus, sur le téléphone, par zxing (wasm, chargé au premier scan).
   * La photo de l'étiquette est jointe à la réclamation : c'est ce que les
   * fournisseurs demandent.
   *
   * Le panel ne connaît aucun code-barres : on reconnaît le produit par le
   * SKU du fournisseur quand le code le porte, sinon par ce que le cockpit a
   * appris au premier scan (POST /fournisseurs/matiere-code). */
  const RC_CODEBARRE = '<svg viewBox="0 0 24 24" width="20" height="16" fill="currentColor" aria-hidden="true"><rect x="2" y="4" width="2" height="16"/><rect x="5.5" y="4" width="1" height="16"/><rect x="8" y="4" width="2" height="16"/><rect x="11.5" y="4" width="1" height="16"/><rect x="14" y="4" width="3" height="16"/><rect x="18.5" y="4" width="1" height="16"/><rect x="21" y="4" width="1.5" height="16"/></svg>';
  const RC_ZX = '../assets/vendor/zxing/';
  const RC_FORMATS = ['EAN-13', 'EAN-8', 'UPC-A', 'UPC-E', 'Code128', 'Code39', 'ITF', 'DataBar', 'DataBarExpanded', 'DataMatrix', 'QRCode'];
  let rcZx = null, rcFichierScan = null;
  function rcLecteur() {
    if (!rcZx) {
      rcZx = new Promise((ok, ko) => {
        const s = document.createElement('script');
        s.src = RC_ZX + 'zxing-reader.js';
        s.onload = () => {
          try {
            const Z = window.ZXingWASM;
            Z.prepareZXingModule({ overrides: { locateFile: (p, pre) => p.endsWith('.wasm') ? new URL(RC_ZX + p, location.href).href : pre + p } });
            ok(Z);
          } catch (e) { ko(e); }
        };
        s.onerror = () => ko(new Error('Le lecteur de code-barres ne s’est pas chargé — vérifiez la connexion.'));
        document.head.appendChild(s);
      });
      rcZx.catch(() => { rcZx = null; });
    }
    return rcZx;
  }
  /* --- Le scan en direct --------------------------------------------------------
   * Sur une page sécurisée (https), la caméra s'ouvre en viseur plein écran et
   * le code-barres est lu en continu : dès qu'il est lu, le téléphone vibre,
   * l'image de l'étiquette devient une photo de la réclamation, le viseur se
   * ferme. En http, les navigateurs refusent la caméra en direct : on retombe
   * sur la photo, et l'écran propose l'adresse https.
   * Le viseur vit hors de la page (document.body) : une relecture du
   * dashboard ne le coupe pas. */
  let rcDirect = null;
  function rcDirectPossible() { return !!(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia); }
  /** La même page en https : le serveur l'est déjà (certificat de son IP). */
  function rcAdresseHttps() {
    if (location.protocol !== 'http:' || /^(localhost|127\.)/.test(location.hostname)) { return ''; }
    return 'https://' + location.host + location.pathname + location.search;
  }
  function rcDirectErreur(e) {
    const n = e && e.name;
    if (n === 'NotAllowedError' || n === 'SecurityError') { return 'L’accès à la caméra a été refusé. Autorisez-le pour ce site dans les réglages du navigateur — ou prenez l’étiquette en photo.'; }
    if (n === 'NotFoundError' || n === 'OverconstrainedError') { return 'Aucune caméra disponible sur cet appareil — prenez l’étiquette en photo.'; }
    if (n === 'NotReadableError' || n === 'AbortError') { return 'La caméra est occupée par une autre application. Fermez-la et réessayez — ou prenez l’étiquette en photo.'; }
    return (e && e.message) || 'La caméra n’a pas démarré — prenez l’étiquette en photo.';
  }
  function rcDirectOuvrir() {
    if (rcDirect) { return; }
    const R = rcForm();
    const el = document.createElement('div');
    el.className = 'rc-direct';
    el.setAttribute('role', 'dialog'); el.setAttribute('aria-label', 'Scanner le code-barres');
    el.innerHTML = '<video playsinline muted autoplay></video><div class="vise"><i></i></div>'
      + '<div class="hd"><span>Visez le code-barres de l’étiquette</span><button data-x aria-label="Fermer">✕</button></div>'
      + '<div class="msg">Démarrage de la caméra…</div>'
      + '<div class="pied"><button data-torche hidden>Lampe</button><button data-photo disabled>Prendre en photo</button></div>';
    document.body.appendChild(el);
    document.documentElement.classList.add('rc-direct-ouvert');
    const D = rcDirect = { el: el, video: el.querySelector('video'), flux: null, Z: null, fini: false, occupe: false, minuteur: null, essais: 0, torche: false, R: R };
    el.querySelector('[data-x]').addEventListener('click', () => rcDirectFermer(false));
    el.querySelector('[data-photo]').addEventListener('click', () => rcDirectPhoto(D));
    try { history.pushState({ rcDirect: 1 }, ''); } catch (e) { /* historique indisponible */ }
    Promise.all([rcLecteur(), navigator.mediaDevices.getUserMedia({ audio: false,
      video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } } })])
      .then(([Z, flux]) => {
        if (D.fini) { flux.getTracks().forEach(t => t.stop()); return; }
        D.Z = Z; D.flux = flux; D.video.srcObject = flux;
        return D.video.play().then(() => {
          if (D.fini) { return; }
          el.querySelector('.msg').textContent = 'Tenez l’étiquette dans le cadre, à 15–20 cm';
          el.querySelector('[data-photo]').disabled = false;
          const piste = flux.getVideoTracks()[0];
          const cap = piste && piste.getCapabilities ? piste.getCapabilities() : {};
          if (cap.focusMode && cap.focusMode.indexOf('continuous') >= 0) { piste.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(() => {}); }
          if (cap.torch) {
            const b = el.querySelector('[data-torche]'); b.hidden = false;
            b.addEventListener('click', () => {
              D.torche = !D.torche;
              piste.applyConstraints({ advanced: [{ torch: D.torche }] }).then(() => b.classList.toggle('on', D.torche)).catch(() => {});
            });
          }
          rcDirectBoucle(D);
        });
      })
      .catch(e => {
        if (D.fini) { return; }
        rcDirectFermer(false);
        R.scan = { etat: 'erreur', err: rcDirectErreur(e) }; rendre();
      });
  }
  /** Une image du flux, réduite à 1 280 px, lue ; la suivante 120 ms plus tard.
   * Une lecture sur trois cherche plus fort (codes petits ou flous). */
  function rcDirectBoucle(D) {
    if (D.fini) { return; }
    const v = D.video;
    if (!D.occupe && v.readyState >= 2 && v.videoWidth) {
      D.occupe = true; D.essais++;
      const k = Math.min(1, 1280 / Math.max(v.videoWidth, v.videoHeight));
      const w = Math.round(v.videoWidth * k), h = Math.round(v.videoHeight * k);
      const cv = D.cv || (D.cv = document.createElement('canvas'));
      if (cv.width !== w) { cv.width = w; } if (cv.height !== h) { cv.height = h; }
      const x = cv.getContext('2d', { willReadFrequently: true });
      x.drawImage(v, 0, 0, w, h);
      D.Z.readBarcodes(x.getImageData(0, 0, w, h), { tryHarder: D.essais % 3 === 0, tryRotate: true, tryDownscale: true,
        textMode: 'HRI', maxNumberOfSymbols: 4, formats: RC_FORMATS })
        .then(res => { const lus = (res || []).filter(r => r && r.text && r.isValid !== false); if (lus.length && !D.fini) { rcDirectTrouve(D, lus); } })
        .catch(() => {})
        .then(() => { D.occupe = false; });
    }
    D.minuteur = setTimeout(() => rcDirectBoucle(D), 120);
  }
  /** L'image pleine du flux : la photo de l'étiquette (JPEG), et ses pixels. */
  function rcDirectImage(D) {
    return new Promise(ok => {
      const v = D.video;
      if (!v.videoWidth) { ok(null); return; }
      const cv = document.createElement('canvas'); cv.width = v.videoWidth; cv.height = v.videoHeight;
      const x = cv.getContext('2d'); x.drawImage(v, 0, 0);
      const px = x.getImageData(0, 0, cv.width, cv.height);
      cv.toBlob(b => ok({ blob: b, px: px }), 'image/jpeg', 0.9);
    });
  }
  /** Lu dans le flux. Le flux est lu réduit, pour aller vite : il attrape
   * souvent l'EAN et manque le long code GS1 (lot, dates). L'image pleine est
   * donc relue plus fort, et ce qu'elle trouve s'ajoute. */
  function rcDirectTrouve(D, lus) {
    const R = D.R;
    D.fini = true; clearTimeout(D.minuteur);
    if (navigator.vibrate) { try { navigator.vibrate(70); } catch (e) { /* sans vibreur */ } }
    D.el.classList.add('ok');
    D.el.querySelector('.msg').textContent = 'Code-barres lu';
    rcDirectImage(D).then(im => {
      setTimeout(() => rcDirectFermer(false), 280);
      if (S.rc !== R) { return; }
      const jeton = { etat: 'lecture' };
      R.scan = jeton; R.nScan = (R.nScan || 0) + 1; S.rcFait = null; R.err = null;
      if (im && im.blob && R.photos.length + R.traite < RC_MAX) { rcAjouter([im.blob]); } else { rendre(); }
      const plein = im && im.px && D.Z
        ? D.Z.readBarcodes(im.px, { tryHarder: true, tryRotate: true, tryDownscale: true, textMode: 'HRI', maxNumberOfSymbols: 6, formats: RC_FORMATS }).catch(() => [])
        : Promise.resolve([]);
      plein.then(res => {
        if (S.rc !== R || R.scan !== jeton) { return; }
        const tous = lus.slice();
        (res || []).forEach(r => { if (r && r.text && r.isValid !== false && !tous.some(x => x.text === r.text)) { tous.push(r); } });
        R.scan = Object.assign(rcAnalyse(tous), { etat: 'lu' });
        rendre();
      });
    });
  }
  /** « Prendre en photo » : l'image du moment, lue plus fort, comme une photo. */
  function rcDirectPhoto(D) {
    if (D.fini) { return; }
    D.fini = true; clearTimeout(D.minuteur);
    rcDirectImage(D).then(im => { rcDirectFermer(false); if (im && im.blob) { rcScanner(im.blob); } });
  }
  function rcDirectFermer(parHisto) {
    const D = rcDirect; if (!D) { return; }
    rcDirect = null;
    D.fini = true; clearTimeout(D.minuteur);
    if (D.flux) { D.flux.getTracks().forEach(t => t.stop()); }
    D.el.remove();
    document.documentElement.classList.remove('rc-direct-ouvert');
    if (!parHisto) { try { if (history.state && history.state.rcDirect) { history.back(); } } catch (e) { /* historique indisponible */ } }
  }
  // Le bouton retour du téléphone ferme le viseur ; quitter l'appli coupe la caméra.
  window.addEventListener('popstate', () => { if (rcDirect) { rcDirectFermer(true); } });
  document.addEventListener('visibilitychange', () => { if (document.hidden && rcDirect) { rcDirectFermer(false); } });

  function rcScanAppareil() {
    if (!rcFichierScan) {
      rcFichierScan = document.createElement('input');
      rcFichierScan.type = 'file'; rcFichierScan.accept = 'image/*'; rcFichierScan.hidden = true;
      rcFichierScan.setAttribute('capture', 'environment');
      rcFichierScan.addEventListener('change', () => { const f = rcFichierScan.files && rcFichierScan.files[0]; rcFichierScan.value = ''; if (f) { rcScanner(f); } });
      document.body.appendChild(rcFichierScan);
    }
    // Le lecteur se charge pendant qu'on vise.
    rcLecteur().catch(() => {});
    rcFichierScan.click();
  }
  /** Les pixels de la photo, à 2 400 px au plus : assez pour les barres fines. */
  function rcPixels(f) {
    return new Promise((ok, ko) => {
      const u = URL.createObjectURL(f), img = new Image();
      img.onload = () => {
        const k = Math.min(1, 2400 / Math.max(img.naturalWidth, img.naturalHeight));
        const cv = document.createElement('canvas');
        cv.width = Math.max(1, Math.round(img.naturalWidth * k)); cv.height = Math.max(1, Math.round(img.naturalHeight * k));
        const x = cv.getContext('2d'); x.drawImage(img, 0, 0, cv.width, cv.height);
        URL.revokeObjectURL(u);
        ok(x.getImageData(0, 0, cv.width, cv.height));
      };
      img.onerror = () => { URL.revokeObjectURL(u); ko(new Error('photo illisible')); };
      img.src = u;
    });
  }
  /** Une date GS1 (AAMMJJ, JJ = 00 : fin du mois) en jj/mm/aaaa. */
  function rcDateGS1(v) {
    if (!/^\d{6}$/.test(v || '')) { return v || ''; }
    const a = 2000 + +v.slice(0, 2), m = +v.slice(2, 4);
    let j = +v.slice(4, 6);
    if (!j) { j = new Date(a, m, 0).getDate(); }
    return String(j).padStart(2, '0') + '/' + String(m).padStart(2, '0') + '/' + a;
  }
  /** Ce que disent les codes lus : la clé du produit, et pour une étiquette
   * GS1 le lot et les dates. La clé est le GTIN (01) — le lot et la date
   * changent d'un carton à l'autre, le GTIN non —, sinon l'EAN, sinon le texte. */
  function rcAnalyse(res) {
    const a = { code: '', gtin: '', lot: '', dlc: '', ddm: '', fab: '', ai240: '', textes: [] };
    res.forEach(r => {
      const t = String(r.text || '').trim(); if (!t) { return; }
      a.textes.push(t);
      if (r.contentType === 'GS1' || /^\(\d{2,4}\)/.test(t)) {
        const re = /\((\d{2,4})\)([^(]*)/g; let x;
        while ((x = re.exec(t))) {
          const ai = x[1], v = x[2].trim();
          if (ai === '01' || ai === '02') { a.gtin = a.gtin || v; }
          else if (ai === '10') { a.lot = a.lot || v; }
          else if (ai === '17') { a.dlc = a.dlc || rcDateGS1(v); }
          else if (ai === '15' || ai === '16') { a.ddm = a.ddm || rcDateGS1(v); }
          else if (ai === '11' || ai === '13') { a.fab = a.fab || rcDateGS1(v); }
          else if (ai === '240' || ai === '241') { a.ai240 = a.ai240 || v; }
        }
      }
    });
    const ean = res.find(r => /^(EAN|UPC)/.test(r.format || ''));
    a.code = rcCle(a.gtin || (ean ? String(ean.text).trim() : '') || a.textes[0] || '');
    return a;
  }
  /** Un EAN-8/13, un UPC et un GTIN-14 désignent le même produit : la clé
   * est le GTIN sur 14 chiffres. Les autres codes restent tels quels. */
  const rcCle = k => /^\d{8,14}$/.test(String(k || '')) ? String(k).padStart(14, '0') : String(k || '');
  /** Le lot et les dates, tels qu'ils partent dans la description. */
  function rcEtiquette(sc) {
    if (!sc) { return ''; }
    return [sc.lot ? 'lot ' + sc.lot : '', sc.dlc ? 'DLC ' + sc.dlc : '', sc.ddm ? 'DDM ' + sc.ddm : '', sc.fab ? 'fabriqué le ' + sc.fab : '',
      sc.gtin ? 'GTIN ' + sc.gtin : (sc.code && sc.etat !== 'illisible' ? 'code ' + sc.code : '')].filter(Boolean).join(' · ');
  }
  /** Le produit d'un code : appris d'abord, puis le SKU du fournisseur. */
  function rcTrouve(F, sc) {
    const C = F.codes || {}, M = F.matieres || [];
    const parId = id => M.find(m => String(m.id) === String(id)) || null;
    const cles = [sc.code, sc.gtin, sc.gtin && sc.gtin.replace(/^0+/, ''), sc.ai240].concat(sc.textes || [], (sc.textes || []).map(rcCle)).filter(Boolean);
    for (const k of cles) { if (C[k] && parId(C[k])) { return { m: parId(C[k]), par: 'appris' }; } }
    for (const k of cles) {
      const n = String(k).replace(/^0+/, '');
      const m = M.find(x => x.sku && (x.sku === k || x.sku.replace(/^0+/, '') === n));
      if (m) { return { m: m, par: 'sku' }; }
    }
    return null;
  }
  /** Un code lu attend les références : on le résout dès qu'elles sont là. */
  function rcResoudre(F, R) {
    const sc = R.scan;
    if (!sc || sc.etat !== 'lu') { return; }
    const t = rcTrouve(F, sc);
    if (t) { R.matiere = String(t.m.id); R.livraison = null; R.q = ''; sc.etat = 'ok'; sc.par = t.par; sc.matiere = String(t.m.id); }
    else { sc.etat = 'inconnu'; }
  }
  /** Le produit choisi à la main après un scan : le cockpit retient le lien. */
  function rcApprendre(R) {
    const sc = R.scan, F = rcRefs();
    if (!sc || !sc.code || !F || !R.matiere || sc.matiere === R.matiere) { return; }
    if (sc.etat !== 'inconnu' && sc.etat !== 'ok' && sc.etat !== 'appris') { return; }
    const m = rcMatiere(F, R); if (!m) { return; }
    sc.etat = 'appris'; sc.matiere = String(m.id);
    F.codes = F.codes || {}; F.codes[sc.code] = String(m.id);
    ecrire('/fournisseurs/matiere-code', { code: sc.code, idMatiere: m.id, shopId: S.shop, sku: m.sku, nom: m.nom })
      .catch(e => { if (R.scan === sc) { sc.etat = 'erreur'; sc.err = 'Le code n’a pas pu être retenu : ' + e.message; rendre(); } });
  }
  function rcScanner(f) {
    const R = rcForm(), jeton = { etat: 'lecture' };
    R.scan = jeton; R.nScan = (R.nScan || 0) + 1; S.rcFait = null; R.err = null;
    // La photo de l'étiquette rejoint les photos de la réclamation.
    if (R.photos.length + R.traite < RC_MAX) { rcAjouter([f]); } else { rendre(); }
    Promise.all([rcLecteur(), rcPixels(f)])
      .then(([Z, px]) => Z.readBarcodes(px, { tryHarder: true, tryRotate: true, tryDownscale: true, textMode: 'HRI', maxNumberOfSymbols: 4,
        formats: RC_FORMATS }))
      .then(res => {
        // Un second scan lancé entre-temps l'emporte.
        if (S.rc !== R || R.scan !== jeton) { return; }
        const lus = (res || []).filter(r => r && r.text && r.isValid !== false);
        if (!lus.length) { R.scan = { etat: 'illisible' }; rendre(); return; }
        R.scan = Object.assign(rcAnalyse(lus), { etat: 'lu' });
        rendre();
      })
      .catch(e => { if (S.rc === R && R.scan === jeton) { R.scan = { etat: 'erreur', err: e && e.message ? e.message : 'lecture impossible' }; rendre(); } });
  }

  function rcEnvoyer() {
    const R = rcForm(), F = rcRefs();
    if (R.envoi || !F || rcManque(F, R)) { return; }
    const m = rcMatiere(F, R), liv = rcLivraison(F, R), q = rcQte(R);
    if (!m.idUnite) { R.err = 'L’unité de « ' + m.nom + ' » est inconnue du panel : la réclamation ne peut pas partir d’ici.'; rendre(); return; }
    if (document.activeElement && document.activeElement.blur) { document.activeElement.blur(); }
    R.envoi = true; R.err = null; rendre();
    Promise.all(R.photos.map(p => rcDataUrl(p.blob)))
      .then(photos => ecrire('/fournisseurs/reclamation', {
        shopId: S.shop, idMatiere: m.id, sku: m.sku, nomMatiere: m.nom, idFournisseur: m.fournisseur, idUnite: m.idUnite,
        quantite: q, idLivraison: liv.id, motif: R.motif, action: 'REPLACEMENT',
        texte: [R.note.trim(), rcEtiquette(R.scan) ? 'Étiquette : ' + rcEtiquette(R.scan) : ''].filter(Boolean).join('\n\n'),
        auteur: notePar(), photos: photos }))
      .then(r => {
        S.rcFait = { id: r && r.id, photos: r && Array.isArray(r.photos) ? r.photos.length : 0, nom: m.nom, qte: q, unite: m.unite,
          fournisseur: rcFournNom(F, liv.fournisseur) };
        R.photos.forEach(p => URL.revokeObjectURL(p.url));
        S.rc = rcNeuf(); S.rcHaut = true;
        // La liste, et les livraisons « déjà réclamées », ont changé.
        lireAux(cleRC(), cheminRC(), true); lireAux(cleRCR(), cheminRCR(), true);
        rendre();
      })
      .catch(e => { R.envoi = false; R.err = e.message; rendre(); });
  }

  /* Les gestes du formulaire : une délégation sur la page, posée une fois. */
  $.addEventListener('click', e => {
    if (S.vue !== 'reclamation') { return; }
    const b = e.target.closest('button'); if (!b || b.disabled || !$.contains(b)) { return; }
    const R = rcForm(), d = b.dataset;
    if (d.rcphoto) { rcAppareil(); return; }
    if (d.rcscan) { if (rcDirectPossible()) { rcDirectOuvrir(); } else { rcScanAppareil(); } return; }
    if (d.rcscanphoto) { rcScanAppareil(); return; }
    if (d.rcmode) {
      S.rcMode = d.rcmode === 'saisie' ? 'saisie' : 'scan';
      try { localStorage.setItem('db.rcMode', S.rcMode); } catch (er) { /* navigation privée */ }
      // Un échec de lecture ne suit pas dans l'autre mode.
      if (R.scan && ['illisible', 'erreur'].includes(R.scan.etat)) { R.scan = null; }
      rendre(); return;
    }
    if (d.rcsuppr != null) { const p = R.photos.splice(+d.rcsuppr, 1)[0]; if (p) { URL.revokeObjectURL(p.url); } rendre(); return; }
    if (d.rcmat) { R.matiere = d.rcmat; R.q = ''; R.livraison = null; R.err = null; S.rcFait = null; rcApprendre(R); rendre(); return; }
    if (d.rcmatx) { R.matiere = null; R.livraison = null; rendre(); const i = document.getElementById('rc-q'); if (i) { i.focus(); } return; }
    if (d.rcmotif) { R.motif = d.rcmotif; rendre(); return; }
    if (d.rcpas) {
      const n = Math.max(1, Math.round(((rcQte(R) || 0) + +d.rcpas) * 100) / 100);
      R.qte = String(n).replace('.', ',');
      const i = document.getElementById('rc-qte'); if (i) { i.value = R.qte; }
      rcMajEnvoi(); return;
    }
    if (d.rcenvoyer) { rcEnvoyer(); return; }
    if (d.rcfaitx) { S.rcFait = null; rendre(); return; }
    if (d.rcfiltre) { S.rcFiltre = d.rcfiltre; rendre(); }
  });
  $.addEventListener('input', e => {
    if (S.vue !== 'reclamation' || !e.target.dataset) { return; }
    const R = rcForm(), t = e.target;
    if (t.dataset.rcq) { R.q = t.value; const s = document.getElementById('rc-sugg'); if (s) { s.innerHTML = rcSuggestions(); } return; }
    if (t.dataset.rcnote) { R.note = t.value; return; }
    if (t.dataset.rcqte) { R.qte = t.value; rcMajEnvoi(); }
  });
  $.addEventListener('change', e => {
    if (S.vue !== 'reclamation' || !e.target.dataset || !e.target.dataset.rcliv) { return; }
    rcForm().livraison = e.target.value; rendre();
  });

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
    // La semaine par périodes — matin, midi, après-midi — sous la journée type.
    if ((S.vue === 'semaine' || S.vue === 'mois') && st.periodes && st.periodes.jours) { h += rendPeriodes(st); }
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
  /**
   * Les périodes de la semaine : une grille jour × période (matin 6 – 10 h,
   * midi 11 – 14 h, après-midi 15 – 19 h). Chaque case dit ses trois chiffres
   * — ventes, marge nette et sa part des ventes, clients — et porte son rang
   * dans la journée, au CA. La couleur suit le chiffre choisi (CA, marge
   * nette ou clients), par rapport à la meilleure case de la semaine ; le
   * rang, lui, reste au CA. Repliée par défaut : la journée type suffit à qui
   * ne cherche pas la période.
   */
  function rendPeriodes(st) {
    const P = st.periodes, B = P.bornes || [];
    const mois = S.vue === 'mois';
    const jourDates = Object.keys(P.jours).sort();
    // Au mois, les jours se regroupent par semaine (lundi → dimanche, bornée au
    // mois) : quatre ou cinq colonnes lisibles plutôt que trente. Le rang se
    // lit alors dans la semaine, et la colonne de droite totalise le mois.
    let colonnes;
    if (mois) {
      const sem = {};
      jourDates.forEach(d => { const t = new Date(d + 'T12:00:00'); const lundi = new Date(t); lundi.setDate(t.getDate() - ((t.getDay() + 6) % 7)); const k = lundi.toISOString().slice(0, 10); (sem[k] = sem[k] || []).push(d); });
      colonnes = Object.keys(sem).sort().map(k => ({ cle: k, dates: sem[k] }));
    } else { colonnes = jourDates.map(d => ({ cle: d, dates: [d] })); }
    const PJ = {};
    colonnes.forEach(c => { PJ[c.cle] = {}; B.forEach(b => { const o = { ca: 0, tickets: 0, mb: 0, res: 0 }; c.dates.forEach(d => { const v = P.jours[d][b.cle] || {}; ['ca', 'tickets', 'mb', 'res'].forEach(k => { o[k] += v[k] || 0; }); }); PJ[c.cle][b.cle] = o; }); });
    const dates = colonnes.map(c => c.cle);
    const col = ['ca', 'res', 'tickets'].includes(S.perCol) ? S.perCol : 'ca';
    const NOM = { ca: 'CA', res: 'Marge nette', tickets: 'Clients' };
    const jourNom = d => { const t = new Date(d + 'T12:00:00'); const w = t.toLocaleDateString('fr-BE', { weekday: 'long' }); return w.charAt(0).toUpperCase() + w.slice(1); };
    const enTete = c => {
      if (!mois) { return `<div class="hd">${esc(jourNom(c.cle))}<small>${esc(fD(c.cle))}</small></div>`; }
      const t = new Date(c.cle + 'T12:00:00'); const jeudi = new Date(t); jeudi.setDate(t.getDate() + 3);
      const an1 = new Date(jeudi.getFullYear(), 0, 4); const num = 1 + Math.round(((jeudi - an1) / 86400000 - 3 + ((an1.getDay() + 6) % 7)) / 7);
      const d1 = c.dates[0], d2 = c.dates[c.dates.length - 1];
      return `<div class="hd">Sem. ${num}<small>${d1.slice(8, 10)} – ${fD(d2)}${c.dates.length < 7 ? ' · ' + c.dates.length + ' j' : ''}</small></div>`;
    };
    const W = {}; B.forEach(b => { W[b.cle] = { ca: 0, tickets: 0, mb: 0, res: 0 }; });
    const J = {}; const sem = { ca: 0, tickets: 0, mb: 0, res: 0 };
    dates.forEach(d => { J[d] = { ca: 0, tickets: 0, mb: 0, res: 0 }; B.forEach(b => { const v = PJ[d][b.cle] || {}; ['ca', 'tickets', 'mb', 'res'].forEach(k => { W[b.cle][k] += v[k] || 0; J[d][k] += v[k] || 0; sem[k] += v[k] || 0; }); }); });
    const rangs = d => { const o = B.map(b => b.cle).sort((a, c) => (PJ[d][c] || {}).ca - (PJ[d][a] || {}).ca); const r = {}; o.forEach((k, i) => { r[k] = i + 1; }); return r; };
    const LAB = { 1: '1er', 2: '2e', 3: '3e' };
    // L'échelle : cinq paliers de la meilleure case de la semaine, sur le chiffre choisi.
    const mx = Math.max(1e-9, ...dates.flatMap(d => B.map(b => (PJ[d][b.cle] || {})[col] || 0)));
    const ECH = col === 'res'
      ? [['#1f5a2c', '≥ 80 % de la meilleure marge'], ['#2d7a3e', '55 – 80 %'], ['#6aa84f', '35 – 55 %'], ['#c9e0b8', '18 – 35 %'], ['#efe9e1', '< 18 %']]
      : [['#8D1D2C', '≥ 80 % de la meilleure case'], ['#C0182B', '55 – 80 %'], ['#F08A2C', '35 – 55 %'], ['#e8c9a0', '18 – 35 %'], ['#efe9e1', '< 18 %']];
    const teinte = v => { const p = v / mx; if (col === 'res' && v < 0) { return ['#f5d5d8', true]; } if (p >= .8) { return [ECH[0][0], false]; } if (p >= .55) { return [ECH[1][0], false]; } if (p >= .35) { return [ECH[2][0], false]; } if (p >= .18) { return [ECH[3][0], true]; } return [ECH[4][0], true]; };
    const net = (v, clair) => `<span class="${clair ? (v.res >= 0 ? 'ok' : 'ko') : ''}">${fSK(v.res)}</span>`;
    const kv = (v, clair) => `<div class="kv"><span>marge nette</span><span>${net(v, clair)} <span class="pc">${v.ca > 0 ? fN(100 * v.res / v.ca) + ' %' : '—'}</span></span><span>clients</span><span>${fN(v.tickets)}</span></div>`;
    const cell = (v, rg) => {
      if (!v || (!v.ca && !v.tickets)) { return `<div class="c vide"><b>—</b><div class="kv"><span>aucune vente</span><span></span></div></div>`; }
      const [f, clair] = teinte(v[col] || 0);
      return `<div class="c${clair ? ' clair' : ''}" style="background:${f}${clair ? ';color:var(--color-text)' : ''}"><em>${LAB[rg] || ''}</em><b>${fK(v.ca)}</b>${kv(v, clair)}</div>`;
    };
    let g = `<div></div>${colonnes.map(enTete).join('')}<div class="hd">${mois ? 'Mois' : 'Semaine'}</div>`;
    B.forEach(b => {
      g += `<div class="r">${esc(b.nom)}<small>${b.de} – ${b.a} h</small></div>`;
      dates.forEach(d => { g += cell(PJ[d][b.cle], rangs(d)[b.cle]); });
      const w = W[b.cle];
      g += `<div class="c sem"><b>${fK(w.ca)}</b><div class="kv"><span>${mois ? 'du mois' : 'de la semaine'}</span><span>${sem.ca > 0 ? fN(100 * w.ca / sem.ca) + ' %' : '—'}</span><span>marge nette</span><span>${net(w, true)} <span class="pc">${w.ca > 0 ? fN(100 * w.res / w.ca) + ' %' : '—'}</span></span><span>clients</span><span>${fN(w.tickets)}</span></div></div>`;
    });
    g += `<div class="r">${mois ? 'Semaine' : 'Journée'}</div>${dates.map(d => `<div class="c tot"><b>${fK(J[d].ca)}</b>${kv(J[d], true)}</div>`).join('')}<div class="c tot"><b>${fK(sem.ca)}</b>${kv(sem, true)}</div>`;
    const bornesTxt = B.map(b => b.nom.toLowerCase() + ' ' + b.de + ' – ' + b.a + ' h').join(' · ');
    const meilleur = B.length ? B.reduce((m, b) => W[b.cle].ca > W[m.cle].ca ? b : m) : null;
    return `<div class="db-card"><div class="ct" data-perdrop="1" style="cursor:pointer"><span class="db-lab">Les périodes — matin, midi, après-midi</span>
      <span class="db-mini">${bornesTxt}${meilleur && sem.ca > 0 ? ' · ' + (mois ? 'le mois' : 'la semaine') + ' se fait le ' + esc(meilleur.nom.toLowerCase()) + ' : ' + fN(100 * W[meilleur.cle].ca / sem.ca) + ' % des ventes' : ''}</span>
      <span class="db-cdr" style="padding:0;margin-left:8px">${S.perOuvert ? 'replier ▴' : 'voir les périodes ▾'}</span></div>
      ${S.perOuvert ? `<div class="ct" style="border-top:none;padding-top:10px;padding-bottom:0"><span class="db-lab">Colorer par</span><div class="db-ong" style="margin-left:8px">${['ca', 'res', 'tickets'].map(k => `<button data-percol="${k}" class="${col === k ? 'on' : ''}">${NOM[k]}</button>`).join('')}</div><span class="db-mini">le rang dans la case : la place de la période dans ${mois ? 'sa semaine' : 'sa journée'}, au CA</span></div>
      <div class="db-per" style="grid-template-columns:104px repeat(${dates.length},minmax(0,1fr)) 150px">${g}</div>
      <div class="db-perleg">${ECH.map(e => `<span><i style="background:${e[0]}"></i>${e[1]}</span>`).join('')}${col === 'res' ? '<span><i style="background:#f5d5d8"></i>perte</span>' : ''}<span>· les ventes d’avant 6 h comptent dans le matin, celles d’après 19 h dans l’après-midi</span></div>` : ''}</div>`;
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
    $.querySelectorAll('[data-c12]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); c12Ouvrir(b); }));
    $.querySelectorAll('[data-fprod]').forEach(b => { b.addEventListener('click', () => ficheOuvrir(b)); b.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); ficheOuvrir(b); } }); });
    $.querySelectorAll('[data-vstk]').forEach(b => { b.addEventListener('click', () => stkOuvrir(b.dataset.vstk)); b.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); stkOuvrir(b.dataset.vstk); } }); });
    ficheRendre();
    invModaleRendre();
    stkRendre();
    $.querySelectorAll('[data-vue]').forEach(b => b.addEventListener('click', () => { S.vue = b.dataset.vue; S.heure = null; S.jourH = null; urlMaj(); charger(false); }));
    const dt = document.getElementById('db-date'); if (dt) { dt.addEventListener('change', () => { if (dt.value && dt.value <= AUJ) { S.date = dt.value; S.heure = null; S.jourH = null; urlMaj(); charger(false); } }); }
    $.querySelectorAll('[data-pas]').forEach(b => b.addEventListener('click', () => {
      const t = new Date(S.date + 'T12:00:00'); const n = +b.dataset.pas;
      if (S.vue === 'jour' || S.vue === 'ops' || S.vue === 'production') { t.setDate(t.getDate() + n); } else if (S.vue === 'semaine') { t.setDate(t.getDate() + 7 * n); } else if (S.vue === 'mois') { t.setMonth(t.getMonth() + n, 1); } else if (S.vue === 'trimestre') { t.setMonth(t.getMonth() + 3 * n, 1); } else { t.setFullYear(t.getFullYear() + n, 0, 1); }
      const d = t.toISOString().slice(0, 10); if (d > AUJ) { return; }
      S.date = d; S.heure = null; S.jourH = null; urlMaj(); charger(false); }));
    $.querySelectorAll('[data-trimq]').forEach(r => r.addEventListener('click', () => { const q = +r.dataset.trimq; let d = annee() + '-' + String((q - 1) * 3 + 1).padStart(2, '0') + '-01'; if (d > AUJ) { return; } if (q === Math.floor((+AUJ.slice(5, 7) - 1) / 3) + 1 && annee() === +AUJ.slice(0, 4)) { d = AUJ; } S.vue = 'trimestre'; S.date = d; urlMaj(); charger(false); }));
    $.querySelectorAll('[data-trim]').forEach(b => b.addEventListener('click', () => { const q = +b.dataset.trim; let d = annee() + '-' + String((q - 1) * 3 + 1).padStart(2, '0') + '-01'; if (d > AUJ) { return; } if (d.slice(0, 7) === AUJ.slice(0, 7) || (q === Math.floor((+AUJ.slice(5, 7) - 1) / 3) + 1 && annee() === +AUJ.slice(0, 4))) { d = AUJ; } S.date = d; urlMaj(); charger(false); }));
    $.querySelectorAll('[data-auj]').forEach(b => b.addEventListener('click', () => { S.date = AUJ; S.heure = null; S.jourH = null; urlMaj(); charger(false); }));
    $.querySelectorAll('[data-fops]').forEach(b => b.addEventListener('click', () => { const d = b.dataset.fops; if (!d || d > AUJ) { return; } S.vue = 'ops'; S.date = d; S.heure = null; S.jourH = null; urlMaj(); charger(false); }));
    $.querySelectorAll('[data-frsem]').forEach(b => b.addEventListener('click', () => {
      const t = new Date(S.date + 'T12:00:00'); t.setDate(t.getDate() + 7 * +b.dataset.frsem);
      let d = iso(t); if (d > AUJ) { d = AUJ; } if (d === S.date) { return; }
      S.date = d; S.heure = null; S.jourH = null; urlMaj(); charger(false); }));
    $.querySelectorAll('[data-fdate]').forEach(b => b.addEventListener('click', () => { const d = b.dataset.fdate; if (!d || d > AUJ) { return; } S.date = d; S.heure = null; S.jourH = null; urlMaj(); charger(false); }));
    $.querySelectorAll('[data-fan]').forEach(b => b.addEventListener('click', () => { const y = annee() + (+b.dataset.fan); const d = y + S.date.slice(4, 7) + '-01'; S.date = d > AUJ ? AUJ : finMois(d.slice(0, 7)); S.heure = null; S.jourH = null; urlMaj(); charger(false); }));
    $.querySelectorAll('[data-recharger]').forEach(b => b.addEventListener('click', () => charger(true)));
    $.querySelectorAll('[data-mode]').forEach(b => b.addEventListener('click', () => { S.mode = b.dataset.mode; rendre(); }));
    $.querySelectorAll('[data-hm]').forEach(b => b.addEventListener('click', () => { S.hmMetric = b.dataset.hm; rendre(); }));
    $.querySelectorAll('[data-tdrop]').forEach(b => b.addEventListener('click', () => { S.tOuvert = !S.tOuvert; rendre(); }));
    $.querySelectorAll('[data-opvit]').forEach(b => b.addEventListener('click', () => { S.opVitTout = !S.opVitTout; rendre(); }));
    $.querySelectorAll('[data-opvie]').forEach(b => b.addEventListener('click', () => { S.opVie = b.dataset.opvie; S.opVitTout = false; rendre(); }));
    $.querySelectorAll('[data-vitq]').forEach(b => b.addEventListener('click', () => { const [k, c] = b.dataset.vitq.split(':'), FB = S.vitFb || (S.vitFb = {}), F = FB[k] || (FB[k] = { ko: true, att: true, ok: false });
      F[c] = !F[c]; const O = S.vitOuv || (S.vitOuv = {}); O[k] = true; rendre(); }));
    $.querySelectorAll('[data-vitall]').forEach(b => b.addEventListener('click', () => { const T = S.vitTout || (S.vitTout = {}); T[b.dataset.vitall] = !T[b.dataset.vitall]; rendre(); }));
    $.querySelectorAll('[data-vitb]').forEach(b => b.addEventListener('click', () => { const O = S.vitOuv || (S.vitOuv = {}); O[b.dataset.vitb] = !O[b.dataset.vitb]; rendre(); }));
    $.querySelectorAll('[data-opcrb]').forEach(b => b.addEventListener('click', () => { S.opCrb = b.dataset.opcrb; rendre(); }));
    $.querySelectorAll('[data-opouv]').forEach(b => b.addEventListener('click', () => { const k = b.dataset.opouv; if (S.opOuv[k]) { delete S.opOuv[k]; } else { S.opOuv[k] = true; } rendre(); }));
    $.querySelectorAll('[data-cvue]').forEach(b => b.addEventListener('click', () => { S.cVue = b.dataset.cvue === 'treemap' ? 'treemap' : 'liste'; try { localStorage.setItem('db.cVue', S.cVue); } catch (e) { /* navigation privée */ } rendre(); }));
    $.querySelectorAll('[data-cacc]').forEach(b => b.addEventListener('click', () => { const k = b.dataset.cacc; S.cOuv[k] = !S.cOuv[k]; rendre(); }));
    $.querySelectorAll('details[data-acpo]').forEach(d => d.addEventListener('toggle', () => { S.cOuv['acp:' + d.dataset.acpo] = d.open; }));
    $.querySelectorAll('[data-pdrop]').forEach(b => b.addEventListener('click', () => { S.pOuvert = !S.pOuvert; rendre(); }));
    $.querySelectorAll('[data-perdrop]').forEach(b => b.addEventListener('click', () => { S.perOuvert = !S.perOuvert; rendre(); }));
    $.querySelectorAll('[data-percol]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); S.perCol = b.dataset.percol; rendre(); }));
    $.querySelectorAll('[data-calval]').forEach(b => b.addEventListener('click', () => { S.calVal = ['ca', 'att', 'cli'].includes(b.dataset.calval) ? b.dataset.calval : 'ca'; try { localStorage.setItem('db.calVal', S.calVal); } catch (e) { /* navigation privée */ } rendre(); }));
    $.querySelectorAll('[data-h]').forEach(el => el.addEventListener('click', () => { S.heure = +el.dataset.h; rendre(); }));
    $.querySelectorAll('[data-ncdrop]').forEach(b => b.addEventListener('click', () => { S.ncOuvert = !S.ncOuvert; rendre(); }));
    $.querySelectorAll('[data-vdrop]').forEach(b => b.addEventListener('click', () => { S.valoOuvert = !S.valoOuvert; rendre(); }));
    $.querySelectorAll('[data-stdrop]').forEach(b => b.addEventListener('click', () => { S.stockOuvert = !S.stockOuvert; rendre(); }));
    $.querySelectorAll('[data-notedrop]').forEach(b => b.addEventListener('click', () => { S.noteOuvert = !S.noteOuvert; rendre(); }));
    $.querySelectorAll('[data-objdrop]').forEach(b => b.addEventListener('click', () => { S.objOuvert = !S.objOuvert; rendre(); }));
    $.querySelectorAll('[data-promodrop]').forEach(b => b.addEventListener('click', () => { S.promoOuvert = !S.promoOuvert; rendre(); }));
    $.querySelectorAll('[data-prodrop]').forEach(b => b.addEventListener('click', () => { S.proOuvert = !S.proOuvert; rendre(); }));
    $.querySelectorAll('[data-note-texte]').forEach(t => t.addEventListener('input', () => {
      S.noteBrouillon = { cle: cleNote(), texte: t.value };
      const N = S.aux[cleNote()], lu = N && N.note ? N.note.texte : '';
      const p = t.parentNode.querySelector('.db-notej-pied .db-mini');
      if (p && !(S.noteEtat && S.noteEtat.cle === cleNote() && S.noteEtat.encours)) { p.textContent = t.value !== lu ? 'modifiée, pas encore enregistrée' : (lu ? 'enregistrée' : 'pas encore de note ce jour'); }
    }));
    $.querySelectorAll('[data-note-par]').forEach(i => i.addEventListener('change', () => { try { localStorage.setItem('db.notePar', i.value.trim()); } catch (e) { /* navigation privée */ } }));
    $.querySelectorAll('[data-note-save]').forEach(b => b.addEventListener('click', () => { const t = $.querySelector('[data-note-texte]'); noteEnregistrer(t ? t.value : noteTexteCourant(S.aux[cleNote()])); }));
    $.querySelectorAll('[data-note-clear]').forEach(b => b.addEventListener('click', () => { if (window.confirm('Effacer la note de ce jour ?')) { S.noteBrouillon = null; noteEnregistrer(''); } }));
    $.querySelectorAll('[data-stav]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); pushBasculer(); }));
    $.querySelectorAll('[data-stessai]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); pushEssai(); }));
    $.querySelectorAll('[data-ncrow]').forEach(b => b.addEventListener('click', () => {
      S.ncLigne = S.ncLigne === b.dataset.ncrow ? null : b.dataset.ncrow; rendre(); }));
    $.querySelectorAll('[data-ncgrp]').forEach(b => b.addEventListener('click', () => {
      const n = b.dataset.ncgrp; S.ncGrav[n] = !S.ncGrav[n]; rendre(); }));
    // Un jour cliqué dans « le jour dans le mois » se lit dans « les heures » : la vue en lignes ouvre celles-ci.
    $.querySelectorAll('[data-jh]').forEach(el => el.addEventListener('click', () => { S.jourH = el.dataset.jh || null; S.heure = null; if (S.a4 === 'mois' && S.jourH) { S.a4 = 'heures'; S.a4Vise = true; } charger(false); }));
    $.querySelectorAll('[data-a4]').forEach(b => {
      const basculer = () => { S.a4 = S.a4 === b.dataset.a4 ? null : b.dataset.a4; S.a4Vise = true; rendre(); };
      b.addEventListener('click', basculer);
      b.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); basculer(); } });
    });
    $.querySelectorAll('[data-cmddrop]').forEach(b => b.addEventListener('click', () => { S.cmdOuvert = !S.cmdOuvert; rendre(); }));
    $.querySelectorAll('[data-rgdrop]').forEach(b => b.addEventListener('click', () => { S.rgOuvert = !S.rgOuvert; rendre(); }));
    $.querySelectorAll('[data-mo]').forEach(b => b.addEventListener('click', () => {
      const k = b.dataset.mo, avant = S.vue;
      if (k === 'sem') { S.vue = 'semaine'; } else if (k === 'ops') { S.vue = 'ops'; } else { S.vue = 'jour'; S.mo = k; }
      S.heure = null; S.jourH = null; urlMaj();
      if (S.vue === avant) { rendre(); const sc = $.querySelector('.mb-sc'); if (sc) { sc.scrollTop = 0; } } else { charger(false); } }));
    $.querySelectorAll('[data-invdrop]').forEach(b => b.addEventListener('click', () => { S.invOuvert = !S.invOuvert; rendre(); }));
    $.querySelectorAll('[data-invmodale]').forEach(b => {
      b.addEventListener('click', e => { e.stopPropagation(); invModaleOuvrir(); });
      b.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.stopPropagation(); invModaleOuvrir(); } });
    });
    ppBrancher();
    $.querySelectorAll('[data-cmdliste]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); S.cmdListeOuvert = !S.cmdListeOuvert; rendre(); }));
    // Les contrôles en photo : filtres, flèches, loupe.
    $.querySelectorAll('[data-cqf]').forEach(b => b.addEventListener('click', () => { S.cqFiltre = b.dataset.cqf; S.cqRaz = true; rendre(); }));
    $.querySelectorAll('[data-cqvoir]').forEach(b => b.addEventListener('click', () => { S.cqVoir = b.dataset.cqvoir; rendre(); }));
    $.querySelectorAll('[data-cqfermer]').forEach(b => b.addEventListener('click', () => { S.cqVoir = null; rendre(); }));
    $.querySelectorAll('[data-cqnav]').forEach(b => b.addEventListener('click', () => cqAller(+b.dataset.cqnav)));
    $.querySelectorAll('.db-cql').forEach(l => l.addEventListener('click', e => { if (e.target === l) { S.cqVoir = null; rendre(); } }));
    const piste = document.getElementById('db-cqpiste');
    if (piste) { piste.addEventListener('scroll', cqFleches, { passive: true }); }
    $.querySelectorAll('[data-cqpas]').forEach(b => b.addEventListener('click', () => { const p = document.getElementById('db-cqpiste'); if (p) { p.scrollBy({ left: +b.dataset.cqpas * Math.max(200, p.clientWidth - 80), behavior: 'smooth' }); } }));
    // Au téléphone, la photo se feuillette au doigt.
    $.querySelectorAll('[data-cqswipe]').forEach(z => {
      let x0 = null, y0 = null;
      z.addEventListener('touchstart', e => { const t = e.changedTouches[0]; x0 = t.clientX; y0 = t.clientY; }, { passive: true });
      z.addEventListener('touchend', e => { if (x0 === null) { return; } const t = e.changedTouches[0], dx = t.clientX - x0, dy = t.clientY - y0; x0 = null;
        if (Math.abs(dx) > 50 && Math.abs(dx) > 1.5 * Math.abs(dy)) { cqAller(dx < 0 ? 1 : -1); } }, { passive: true });
    });
  }

  // Tourner le téléphone, ou ouvrir la page sur un écran étroit, change de
  // rendu : on ne recharge rien, on redessine.
  let _mob = estMobile(), _rt = null;
  window.addEventListener('resize', () => {
    clearTimeout(_rt);
    // En passant au téléphone, le mur demande deux lectures que le bureau ne
    // fait pas (la semaine sous le jour, les commandes) : on les déclenche,
    // sinon les cellules resteraient à « lecture en cours » pour toujours.
    _rt = setTimeout(() => { const n = estMobile(); if (n !== _mob) { _mob = n; if (n) { charger(false); } else { rendre(); } } }, 160);
  });

  // La loupe se ferme à Échap et se feuillette aux flèches du clavier.
  document.addEventListener('keydown', e => { if (S.fiche && e.key === 'Escape') { ficheFermer(); } else if (S.invModale && e.key === 'Escape') { invModaleFermer(); } else if (S.stk && e.key === 'Escape') { stkFermer(); } });
  document.addEventListener('keydown', e => {
    if (!S.cqVoir) { return; }
    if (e.key === 'Escape') { S.cqVoir = null; rendre(); }
    else if (e.key === 'ArrowLeft') { cqAller(-1); } else if (e.key === 'ArrowRight') { cqAller(1); }
  });
  // Une photo qui refuse de se charger : son URL signée a expiré. On relit.
  $.addEventListener('error', e => {
    const im = e.target;
    if (im && im.tagName === 'IMG' && im.dataset && im.dataset.cqimg) { im.parentNode.classList.add('perdue'); im.remove(); cqRelire(); }
  }, true);

  /* --- départ ------------------------------------------------------------- */
  // Sur ordinateur, une adresse ?vue=jour ouvre Opérationnel (10/10/2026) : la lecture part tout de suite sur la bonne vue.
  if (S.vue === 'jour' && !estMobile()) { S.vue = 'ops'; }
  pushEtatLire().then(() => rendre()).catch(() => {});
  lire('/stores?statut=tous').then(l => { S.stores = (Array.isArray(l) ? l : []).filter(s => !s.status || /ouvert/i.test(s.status)).map(s => ({ id: s.id, nom: s.nom || s.name })); rendre(); }).catch(() => {});
  urlMaj();
  charger(false);
  // La journée en cours se relit toutes les dix minutes.
  setInterval(() => { if (S.vue === 'jour' && S.date === AUJ) { charger(true); } }, 600000);
  // L'onglet Opérationnel suit la journée de plus près : toutes les deux minutes.
  setInterval(() => { if (S.vue === 'ops' && S.date === AUJ && !document.hidden) { charger(true); } }, 120000);
})();
