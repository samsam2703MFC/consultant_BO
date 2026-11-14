/* Dashboard magasin › Trésorerie — le plan de trésorerie d'un magasin (14/11/2026).
 *
 * Le modèle : « Plan de trésorerie — L'Atelier By » (Sombreffe, tiré de son fichier Excel), repris écran pour écran :
 * le plan semaine par semaine, les fournisseurs, les paiements récurrents, le mode d'emploi — et la routine du
 * dimanche soir (le solde réel lu dans la banque, l'écart, les quatre semaines à venir, la décision).
 *
 * Ce que le modèle faisait retaper, le module le reprend tout seul (src/tresorerie.php) :
 *   - l'année passée : le CA de la même semaine un an plus tôt ;
 *   - le budget validé : le CA budget de chaque semaine (le mois réparti au profil de l'an passé), les taux de
 *     matière et de personnel, les postes de charges (les paiements proposés d'un plan neuf) ;
 *   - ce qui a été fait : le CA réel de chaque semaine close remplace la prévision.
 * Un onglet « N-1, budget, réalisé » les met côte à côte, mois par mois, et règle la base des ventes prévues.
 *
 * Une page autonome, sans compilation : `window.DashTresorerie.mount(el, {shop, apiBase, nomShop})`. Le plan est
 * enregistré au serveur (un par magasin, avec sa version) quelques instants après chaque saisie.
 */
(function () {
  'use strict';

  const DAY = 86400000;
  const CATS = ['Gérant', 'Personnel', 'Frais fixes', 'Crédits & leasing', 'TVA & ONSS', 'Remboursement dettes', 'Investissements'];
  const FREQS = ['Chaque semaine', 'Chaque mois', 'Chaque trimestre'];
  const MO = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  const MOL = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  const FENETRES = ['8 semaines', '13 semaines', 'Tout'];
  const SEUIL_ECART = 500;   // € : au-delà, un écart réel − prévu se cherche avant lundi

  /* --- formats ------------------------------------------------------------------------------------------------- */
  const nf0 = new Intl.NumberFormat('fr-BE', { maximumFractionDigits: 0 });
  const n = v => (v == null || isNaN(v)) ? '' : nf0.format(Math.round(v)).replace('-', '−');
  const eur = v => (v == null || isNaN(v)) ? '—' : n(v) + ' €';
  const pct = v => (v == null || !isFinite(v)) ? '—' : (v * 100).toFixed(1).replace('.', ',').replace('-', '−') + ' %';
  const pctS = v => { if (v == null || !isFinite(v)) { return '—'; } const r = Math.round(v * 1000) / 10; return (r > 0 ? '+ ' : (r < 0 ? '− ' : '')) + Math.abs(r).toFixed(1).replace('.', ',') + ' %'; };
  // Un taux arrondi à l'euro (l'estimation d'un fournisseur) dépasse son plafond de quelques centimes : ce n'est pas un dépassement.
  const audela = (v, plafond) => v > plafond + 0.0005;
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  let compteur = 0;
  const uid = () => 'k' + Date.now().toString(36) + (compteur++).toString(36);
  const msDe = s => Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10));
  const isoDe = t => new Date(t).toISOString().slice(0, 10);
  const dm = t => { const d = new Date(t); return String(d.getUTCDate()).padStart(2, '0') + '/' + String(d.getUTCMonth() + 1).padStart(2, '0'); };
  const dLong = t => { const d = new Date(t); return d.getUTCDate() + ' ' + MOL[d.getUTCMonth()] + ' ' + d.getUTCFullYear(); };
  const dCourt = t => { const d = new Date(t); return d.getUTCDate() + ' ' + MO[d.getUTCMonth()]; };
  const jourUTC = () => { const t = new Date(); return Date.UTC(t.getFullYear(), t.getMonth(), t.getDate()); };
  const isoWeek = t => {
    const j = (new Date(t).getUTCDay() + 6) % 7, th = t + (3 - j) * DAY, y = new Date(th).getUTCFullYear();
    const j4 = Date.UTC(y, 0, 4), j4d = (new Date(j4).getUTCDay() + 6) % 7, premJ = j4 - j4d * DAY + 3 * DAY;
    return 1 + Math.round((th - premJ) / (7 * DAY));
  };
  /** Un nombre tapé : espaces, €, %, virgule et signe moins typographique admis. Vide → null ; illisible → undefined. */
  const lireNombre = t => {
    const s = String(t).trim().replace(/[\s  €%]/g, '').replace('−', '-').replace(',', '.');
    if (s === '') { return null; }
    const v = parseFloat(s); return isNaN(v) ? undefined : v;
  };
  const clone = o => JSON.parse(JSON.stringify(o));
  const objet = v => (v && typeof v === 'object' && !Array.isArray(v)) ? v : {};
  const liste = v => Array.isArray(v) ? v : [];

  /* --- le calcul (le modèle, à l'identique, sur les semaines du serveur) ------------------------------------------- */

  /** Les semaines du plan : celles des repères du serveur, dans l'ordre, chacune avec son lundi et ses repères. */
  function semaines(R) {
    return R.semaines.map((s, i) => {
      const t = msDe(s.du), d = new Date(t);
      return { i, du: s.du, ms: t, dimanche: t + 6 * DAY, date: d.getUTCDate(), mois: d.getUTCMonth(), annee: d.getUTCFullYear(),
        sdm: Math.floor((d.getUTCDate() - 1) / 7) + 1, iso: isoWeek(t), ref: s };
    });
  }

  /** La base d'une semaine à venir : le budget validé ou l'année passée (+ la croissance choisie), l'un à défaut de l'autre. */
  function baseVente(ref, d) {
    const b = ref.budget != null ? { v: +ref.budget, src: 'budget' } : null;
    const a = ref.n1 != null ? { v: +ref.n1 * (1 + (+d.croissance || 0) / 100), src: 'n1' } : null;
    return d.base === 'n1' ? (a || b) : (b || a);
  }

  /** Un paiement récurrent tombe-t-il cette semaine ? (« semaine du mois » 1 à 4 : la 1 est celle du premier lundi.) */
  function paieLaSemaine(p, w) {
    const a = +p.montant || 0; if (!a) { return 0; }
    let oui = p.freq === 'Chaque semaine' || (p.freq === 'Chaque mois' && +p.sem === w.sdm) || (p.freq === 'Chaque trimestre' && +p.sem === w.sdm && (w.mois + 1) % 3 === 1);
    const s = p.debut ? msDe(p.debut) : null, e = p.fin ? msDe(p.fin) : null;
    if (s != null && s > w.ms + 6 * DAY) { oui = false; }
    if (e != null && e < w.ms) { oui = false; }
    return oui ? a : 0;
  }

  function calcul(d, R, auj) {
    const W = semaines(R), N = W.length;
    const anT = W[N - 1].annee, deT = Date.UTC(anT, 0, 1);
    const ventes = [], genre = [], base = [];
    W.forEach((w, i) => {
      const r = w.ref, b = baseVente(r, d), saisi = d.ventes[w.du];
      base[i] = b;
      if (r.complet && r.realise != null) { ventes[i] = +r.realise; genre[i] = 'reel'; }
      else if (saisi != null) { ventes[i] = +saisi; genre[i] = 'saisi'; }
      else if (b) { ventes[i] = Math.round(b.v); genre[i] = b.src; }
      else { ventes[i] = 0; genre[i] = 'vide'; }
    });
    const cats = {}; CATS.forEach(k => { cats[k] = Array(N).fill(0); });
    const payTot = d.paiements.map(p => { let t = 0; if (!cats[p.cat]) { return 0; } W.forEach((w, i) => { const a = paieLaSemaine(p, w); cats[p.cat][i] += a; t += a; }); return t; });
    const supEst = d.fournisseurs.map(s => W.map((w, i) => s.montants[w.du] == null ? (+s.pct || 0) * ventes[i] : null));
    const march = W.map((w, i) => { let fixe = 0, p = 0; d.fournisseurs.forEach(s => { const a = s.montants[w.du]; if (a == null) { p += (+s.pct || 0); } else { fixe += +a; } }); return fixe + Math.round(p * ventes[i]); });
    const supReel = W.map(w => d.fournisseurs.reduce((t, s) => t + (s.montants[w.du] == null ? 0 : +s.montants[w.du]), 0));
    const supTot = d.fournisseurs.map(s => { let fixe = 0, es = 0; W.forEach((w, i) => { if (s.montants[w.du] == null) { es += ventes[i]; } else { fixe += +s.montants[w.du]; } }); return fixe + Math.round((+s.pct || 0) * es); });
    const ponct = W.map(w => +d.ponct[w.du] || 0);
    const reel = W.map(w => d.reel[w.du] == null ? null : +d.reel[w.du]);
    const totdec = W.map((w, i) => march[i] + CATS.reduce((t, k) => t + cats[k][i], 0) + ponct[i]);
    const solde = W.map((w, i) => ventes[i] - totdec[i]);
    const debut = [], fin = [], ecart = [];
    W.forEach((w, i) => {
      debut[i] = i === 0 ? (+d.startCash || 0) : (reel[i - 1] != null ? reel[i - 1] : fin[i - 1]);
      fin[i] = debut[i] + solde[i];
      ecart[i] = reel[i] == null ? null : reel[i] - fin[i];
    });
    const tA = arr => arr.reduce((t, v, i) => W[i].ms >= deT ? t + (+v || 0) : t, 0);
    const tAn = (arr, pred) => { let s = 0, k = 0; W.forEach((w, i) => { if (w.ms >= deT && pred(i)) { s += +arr[i]; k++; } }); return { s, k }; };
    const bas = Math.min(...fin), basIdx = fin.indexOf(bas);
    const coussin = +d.coussin || 0;
    const sous = fin.filter(v => v < coussin).length;
    const cur = Math.floor((auj - W[0].ms) / (7 * DAY));
    const dernierDim = Math.min(N - 1, Math.floor((auj - W[0].dimanche) / (7 * DAY)));
    const oublies = W.filter((w, i) => w.dimanche < auj && reel[i] == null).length;
    const sA = tA(ventes);
    const nbA = W.filter(w => w.ms >= deT).length;
    const budA = tAn(W.map(w => w.ref.budget), i => W[i].ref.budget != null), vBud = tAn(ventes, i => W[i].ref.budget != null);
    const n1A = tAn(W.map(w => w.ref.n1), i => W[i].ref.n1 != null), vN1 = tAn(ventes, i => W[i].ref.n1 != null);
    return { W, N, anT, deT, ventes, genre, base, cats, payTot, supEst, march, supReel, supTot, ponct, reel, totdec, solde, debut, fin, ecart,
      tA, bas, basIdx, sous, cur, dernierDim, oublies, coussin, nbA,
      budA: budA.k ? budA.s : null, budK: budA.k, vBud: vBud.s, n1A: n1A.k ? n1A.s : null, n1K: n1A.k, vN1: vN1.s,
      matPct: sA ? tA(march) / sA : 0, persPct: sA ? (tA(cats['Gérant']) + tA(cats['Personnel'])) / sA : 0,
      sansBase: genre.filter((g, i) => g === 'vide' && !W[i].ref.complet).length };
  }

  /** Les ventes du plan, mois par mois : chaque semaine répartie sur ses sept jours. */
  function ventesParMois(C) {
    const out = {};
    C.W.forEach((w, i) => { for (let k = 0; k < 7; k++) { const t = new Date(w.ms + k * DAY); const ym = t.getUTCFullYear() + '-' + String(t.getUTCMonth() + 1).padStart(2, '0'); out[ym] = (out[ym] || 0) + C.ventes[i] / 7; } });
    return out;
  }

  /* --- un plan neuf, d'après le budget et l'année passée ------------------------------------------------------------ */

  function catDe(c) {
    const t = (c.categorie + ' ' + c.poste).toLowerCase();
    if (/personnel|salaire|r[ée]mun|main.d|[ée]quipe/.test(t)) { return 'Personnel'; }
    if (/leasing|cr[ée]dit|emprunt|financ/.test(t)) { return 'Crédits & leasing'; }
    if (/tva|onss|imp[ôo]t|taxe/.test(t)) { return 'TVA & ONSS'; }
    return 'Frais fixes';
  }
  const SEM_CAT = { 'Personnel': 1, 'Frais fixes': 2, 'Crédits & leasing': 4, 'TVA & ONSS': 3 };

  /**
   * Un plan neuf : la base des ventes (le budget s'il couvre au moins la moitié des semaines que l'année passée
   * couvre, sinon l'année passée), un coussin de 40 % d'une semaine de ventes, la matière du budget en plafond et en
   * estimation des fournisseurs, un paiement mensuel par poste de charges du budget (hors matière et amortissements),
   * et rien d'inventé : le solde de départ, la TVA, l'ONSS et les dettes restent à saisir.
   */
  function defauts(R, debut) {
    const W = semaines(R);
    const nb = W.filter(w => w.ref.budget != null).length, nn = W.filter(w => w.ref.n1 != null).length;
    const d = { v: 2, debut, base: nb > 0 && nb >= nn / 2 ? 'budget' : 'n1', croissance: 0, startCash: null, coussin: 5000, plafond: 0.4,
      ventes: {}, ponct: {}, reel: {}, fournisseurs: [], paiements: [], hors: [] };
    const bases = W.map(w => { const b = baseVente(w.ref, d); return b ? b.v : null; }).filter(v => v != null && v > 0).sort((a, b) => a - b);
    const med = bases.length ? bases[Math.floor(bases.length / 2)] : 0;
    const moy = bases.length ? bases.reduce((a, b) => a + b, 0) / bases.length : 0;
    if (med > 0) { d.coussin = Math.max(500, Math.round(0.4 * med / 500) * 500); }
    const T = R.taux || {};
    const mat = T.matiere > 0 ? T.matiere / 100 : 0.4;
    d.plafond = +mat.toFixed(3);
    d.fournisseurs = [{ id: uid(), nom: 'Tous fournisseurs', pct: +mat.toFixed(3), montants: {} }];
    const moisCA = moy * 52 / 12;
    const src = d.base === 'budget' ? 'budget validé' : 'année passée';
    if (moisCA > 0) {
      const postes = liste(R.charges).filter(c => !c.matiere && !/amortis/i.test(c.poste + ' ' + c.categorie));
      postes.forEach(c => {
        const cat = catDe(c);
        d.paiements.push({ id: uid(), libelle: c.poste || c.categorie, cat, montant: Math.round(c.pct / 100 * moisCA / 10) * 10, freq: 'Chaque mois', sem: SEM_CAT[cat] || 3, debut: '', fin: '',
          note: `Budget : ${String(c.pct).replace('.', ',')} % du CA (${eur(moisCA)} par mois en moyenne, ${src}). Remplace-le par le montant réel.` });
      });
      if (!d.paiements.some(p => p.cat === 'Personnel') && T.personnel > 0) {
        d.paiements.unshift({ id: uid(), libelle: 'Paie du personnel', cat: 'Personnel', montant: Math.round(T.personnel / 100 * moisCA / 100) * 100, freq: 'Chaque mois', sem: 1, debut: '', fin: '',
          note: `Taux personnel du budget : ${String(T.personnel).replace('.', ',')} % du CA (${eur(moisCA)} par mois en moyenne). Remplace-le par ta paie réelle.` });
      }
    }
    d.paiements.unshift({ id: uid(), libelle: 'Rémunération du gérant', cat: 'Gérant', montant: 0, freq: 'Chaque mois', sem: 1, debut: '', fin: '',
      note: 'À fixer : ce que tu te verses. Quand le cash est court, c’est la première ligne qui s’ajuste.' });
    d.hors = [
      { id: uid(), libelle: 'Factures fournisseurs impayées', montant: null, note: 'Ce qui reste dû aujourd’hui, hors échéancier déjà dans le plan.' },
      { id: uid(), libelle: 'ONSS, TVA, précompte : arriérés', montant: null, note: 'À demander au comptable.' }
    ];
    return d;
  }

  /** Un plan relu : les tables par semaine en objets, les listes en listes, chaque ligne avec son identifiant. */
  function normaliser(p) {
    const d = clone(objet(p));
    d.v = 2;
    ['ventes', 'ponct', 'reel'].forEach(k => { d[k] = objet(d[k]); });
    d.fournisseurs = liste(d.fournisseurs).map(s => ({ id: s.id || uid(), nom: s.nom || '', pct: +s.pct || 0, montants: objet(s.montants) }));
    d.paiements = liste(d.paiements).map(p2 => ({ id: p2.id || uid(), libelle: p2.libelle || '', cat: CATS.includes(p2.cat) ? p2.cat : 'Frais fixes', montant: +p2.montant || 0,
      freq: FREQS.includes(p2.freq) ? p2.freq : 'Chaque mois', sem: p2.sem == null ? null : +p2.sem, debut: p2.debut || '', fin: p2.fin || '', note: p2.note || '' }));
    d.hors = liste(d.hors).map(h => ({ id: h.id || uid(), libelle: h.libelle || '', montant: h.montant == null ? null : +h.montant, note: h.note || '' }));
    if (d.base !== 'n1') { d.base = 'budget'; }
    d.croissance = +d.croissance || 0;
    return d;
  }

  /* --- l'état (un par magasin) et le serveur ---------------------------------------------------------------------- */

  const ETATS = {};
  let E = null;            // l'état du magasin monté
  let racine = null;       // l'élément où le module se dessine
  let ctx = { apiBase: '../api/cockpit', nomShop: '' };

  function nouvelEtat(shop) {
    return { shop, data: null, R: null, version: 0, majLe: null, neuf: true, debut: null, err: null, lecture: false,
      onglet: 'plan', fen: '13 semaines', decal: null, brouillon: null, annule: false, fmt: {},
      sale: false, envoi: false, reEnvoi: false, errEnr: null, conflit: null, enrA: null, minuteur: null, rendu: null };
  }

  function appel(path, opts) {
    return fetch(ctx.apiBase + path, Object.assign({ headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, credentials: 'same-origin' }, opts || {}))
      .then(r => r.json().catch(() => ({})).then(j => {
        if (r.ok) { return j; }
        const e = new Error((j && j.error) || ('HTTP ' + r.status)); e.status = r.status; e.corps = j; throw e;
      }));
  }

  function charger(force) {
    const S = E; if (!S || S.lecture) { return; }
    S.lecture = true; S.err = null; rendre();
    const garderSaisies = S.data && (S.sale || S.envoi);
    appel('/tresorerie/plan?shop=' + encodeURIComponent(S.shop)).then(p => {
      if (!garderSaisies) {
        S.version = p.version || 0; S.majLe = p.majLe || null;
        S.data = p.plan ? normaliser(p.plan) : null; S.neuf = !p.plan;
        S.debut = p.plan ? p.plan.debut : p.debutPropose;
      }
      return appel('/tresorerie/references?shop=' + encodeURIComponent(S.shop) + '&debut=' + encodeURIComponent(S.debut) + '&_cache=600' + (force ? '&rafraichir=1' : ''));
    }).then(R => {
      if (R.debut !== S.debut) { throw new Error('repères lus pour un autre premier lundi (' + R.debut + ')'); }
      S.R = R;
      if (!S.data) { S.data = defauts(R, S.debut); S.neuf = true; }
    }).catch(e => { S.err = e.message; }).finally(() => { S.lecture = false; rendre(); });
  }

  /** Une saisie : le plan change, l'écran se redessine, l'enregistrement part un instant plus tard. */
  function maj(fn) {
    const S = E; if (!S || !S.data) { return; }
    const d = clone(S.data); fn(d); S.data = d; S.sale = true; S.importe = null; S.errImport = null;
    clearTimeout(S.minuteur); S.minuteur = setTimeout(enregistrer, 700);
    planifierRendu();
  }

  function enregistrer() {
    const S = E; if (!S || !S.sale || S.conflit) { return; }
    if (S.envoi) { S.reEnvoi = true; return; }
    S.envoi = true; S.sale = false; S.errEnr = null; rendreEtat();
    const shop = S.shop;
    appel('/tresorerie/plan', { method: 'POST', body: JSON.stringify({ shop, version: S.version, donnees: S.data }) }).then(r => {
      S.version = r.version; S.majLe = r.majLe; S.neuf = false; S.enrA = new Date();
    }).catch(e => {
      if (e.status === 409) { S.conflit = e.corps || {}; } else { S.errEnr = e.message; S.sale = true; }
    }).finally(() => {
      S.envoi = false;
      if (S.reEnvoi) { S.reEnvoi = false; enregistrer(); }
      if (E === S) { if (S.conflit) { rendre(); } else { rendreEtat(); } }
    });
  }

  /* --- le dessin ---------------------------------------------------------------------------------------------------- */

  /** Un champ chiffré : ce qui s'affiche hors saisie, ce qu'on retrouve en cliquant, ce qu'on tape en cours. */
  function champ(cle, valeur, o) {
    o = o || {};
    const edit = o.edit || (v => v == null ? '' : String(v).replace('.', ','));
    const aff = o.aff || (v => v == null ? '' : n(v));
    E.fmt[cle] = edit(valeur);
    const enCours = E.brouillon && E.brouillon.cle === cle;
    return `<input class="${o.cls || 'tr-in'}" inputmode="decimal" data-trn="${esc(cle)}" value="${esc(enCours ? E.brouillon.texte : aff(valeur))}"${o.ph ? ` placeholder="${esc(o.ph)}"` : ''}${o.titre ? ` title="${esc(o.titre)}"` : ''}>`;
  }

  function rendre() {
    if (!racine || !E) { return; }
    clearTimeout(E.rendu); E.rendu = null;
    const a = document.activeElement;
    let garde = null;
    if (a && racine.contains(a) && a.dataset) {
      for (const at of ['trtxt', 'trsel', 'trdate']) { if (a.dataset[at] != null) { garde = { at: 'data-' + at, v: a.dataset[at], s: a.selectionStart }; break; } }
    }
    E.fmt = {};
    racine.innerHTML = corps();
    if (E.brouillon) {
      const el = racine.querySelector(`[data-trn="${CSS.escape(E.brouillon.cle)}"]`);
      if (el) { el.focus(); try { el.select(); } catch (x) { /* champ sans sélection */ } } else { E.brouillon = null; }
    } else if (garde) {
      const el = racine.querySelector(`[${garde.at}="${CSS.escape(garde.v)}"]`);
      if (el) { el.focus(); try { if (garde.s != null) { el.setSelectionRange(garde.s, garde.s); } } catch (x) { /* select, date */ } }
    }
  }
  function planifierRendu() { if (E && !E.rendu) { E.rendu = setTimeout(rendre, 0); } }

  function etatTexte() {
    const S = E;
    if (S.conflit) { return '<span class="tr-ko">Pas enregistré : le plan a changé ailleurs</span>'; }
    if (S.envoi) { return 'Enregistrement…'; }
    if (S.errEnr) { return `<span class="tr-ko">Pas enregistré : ${esc(S.errEnr)}</span> <button type="button" class="tr-lien" data-tra="reessayer">Réessayer</button>`; }
    if (S.sale) { return 'Modifié…'; }
    if (S.neuf) { return 'Proposé, pas encore enregistré'; }
    const t = S.enrA || (S.majLe ? new Date(S.majLe.replace(' ', 'T')) : null);
    return t && !isNaN(t) ? 'Enregistré · ' + t.toLocaleDateString('fr-BE', { day: '2-digit', month: '2-digit' }) + ' à ' + t.toLocaleTimeString('fr-BE', { hour: '2-digit', minute: '2-digit' }) : 'Enregistré';
  }
  function rendreEtat() { const el = racine && racine.querySelector('[data-tretat]'); if (el) { el.innerHTML = etatTexte(); } }

  function corps() {
    const S = E;
    if (!S.R || !S.data) {
      if (S.err) { return `<div class="db-err">Trésorerie : ${esc(S.err)} <button type="button" class="tr-lien" data-tra="relire">Relire</button></div>`; }
      return '<div class="db-attente">Lecture de l’année passée, du budget et du réalisé…</div>';
    }
    const auj = jourUTC(), d = S.data, R = S.R, C = calcul(d, R, auj), W = C.W, N = C.N;
    const an0 = W[0].annee, an1 = C.anT;
    const onglets = [['plan', 'Plan ' + (an0 === an1 ? an0 : an0 + '-' + an1)], ['fournisseurs', 'Fournisseurs'], ['paiements', 'Paiements'], ['reperes', 'N-1, budget, réalisé'], ['guide', 'Mode d’emploi']];
    let h = `<div class="tr">`;
    h += `<header class="tr-hd"><div class="tr-hd-g"><div class="tr-sur">Trésorerie · ${esc(ctx.nomShop || '')}</div>
      <h2 class="tr-h1">Plan de trésorerie</h2>
      <div class="tr-sous">Semaine par semaine, du lundi ${esc(dLong(W[0].ms))} au dimanche ${esc(dLong(W[N - 1].dimanche))} · Montants en € TTC</div></div>
      <div class="tr-hd-d"><span class="tr-etat" data-tretat>${etatTexte()}</span>
      <button type="button" class="tr-btn-t" data-tra="reset">Repartir de zéro</button><button type="button" class="tr-btn-t" data-tra="importer" title="Un plan au format du module (JSON), par exemple repris d’un ancien fichier">Importer un plan</button><input type="file" accept=".json,application/json" data-trfichier hidden><button type="button" class="tr-btn" data-tra="export">Exporter CSV</button></div></header>`;
    h += `<nav class="tr-ong">${onglets.map(([k, l]) => `<button type="button" data-tra="onglet:${k}" class="${S.onglet === k ? 'on' : ''}">${esc(l)}</button>`).join('')}</nav>`;
    if (S.conflit) {
      const c = S.conflit, t = c.majLe ? new Date(String(c.majLe).replace(' ', 'T')) : null;
      h += `<div class="db-err">Le plan a été modifié sur un autre écran${t && !isNaN(t) ? ' le ' + t.toLocaleDateString('fr-BE') + ' à ' + t.toLocaleTimeString('fr-BE', { hour: '2-digit', minute: '2-digit' }) : ''} : tes dernières saisies ici ne sont pas enregistrées. <button type="button" class="tr-lien" data-tra="reprendre">Reprendre le plan enregistré</button></div>`;
    }
    if (S.errImport) { h += `<div class="db-err">Import refusé : ${esc(S.errImport)}</div>`; }
    if (S.importe) { h += `<div class="tr-bandeau">Plan importé : ${esc(S.importe)}. Il remplace le plan de ce magasin et s’enregistre comme une saisie.</div>`; }
    if (S.neuf && !S.sale && !S.envoi) {
      h += `<div class="tr-bandeau">Plan proposé d’après ${d.base === 'budget' ? 'le budget validé' : 'l’année passée'} et les postes du budget. Rien n’est enregistré tant que tu n’as rien modifié : commence par ton solde de départ, puis tes paiements réels (onglet Paiements).</div>`;
    }
    if (auj > W[N - 1].dimanche) { h += `<div class="db-alerte">Ce plan s’est terminé le ${esc(dLong(W[N - 1].dimanche))}. « Repartir de zéro » en ouvre un nouveau à partir de cette semaine.</div>`; }
    if (S.err) { h += `<div class="db-err">${esc(S.err)}</div>`; }
    if (S.onglet === 'plan') { h += rendPlan(C, d, R, auj); }
    else if (S.onglet === 'fournisseurs') { h += rendFournisseurs(C, d); }
    else if (S.onglet === 'paiements') { h += rendPaiements(C, d); }
    else if (S.onglet === 'reperes') { h += rendReperes(C, d, R, auj); }
    else { h += rendGuide(C, d, R); }
    return h + '</div>';
  }

  /** La fenêtre de semaines visible et sa navigation. */
  function fenetre(C) {
    const S = E, N = C.N;
    const nW = S.fen === '8 semaines' ? 8 : (S.fen === '13 semaines' ? 13 : N);
    const borne = o => Math.max(0, Math.min(N - Math.min(nW, N), o));
    const decal = borne(S.decal != null ? S.decal : Math.max(0, C.cur - (C.cur > 0 ? 1 : 0)));
    const vis = []; for (let i = decal; i < Math.min(N, decal + nW); i++) { vis.push(i); }
    return { nW, borne, decal, vis };
  }
  function barreFenetre(C, F, titre) {
    const W = C.W, a = W[F.vis[0]], b = W[F.vis[F.vis.length - 1]];
    return `<div class="tr-cbar"><div class="tr-h3">${titre}</div><div class="tr-ctl">
      ${FENETRES.map(l => `<button type="button" class="tr-pil${E.fen === l ? ' on' : ''}" data-tra="fen:${l}">${l}</button>`).join('')}
      <span class="tr-sep"></span>
      <button type="button" class="tr-nav" data-tra="nav:-1" aria-label="Semaines précédentes">‹</button>
      <button type="button" class="tr-nav tr-nav-t" data-tra="nav:0">Cette semaine</button>
      <button type="button" class="tr-nav" data-tra="nav:1" aria-label="Semaines suivantes">›</button>
      <span class="tr-plage">${esc(dCourt(a.ms) + ' ' + a.annee + ' → ' + dCourt(b.dimanche) + ' ' + new Date(b.dimanche).getUTCFullYear())}</span></div></div>`;
  }
  /** Les deux lignes d'entête des colonnes (le mois, puis le lundi). */
  function entete(C, F, larg, libelle, total) {
    const W = C.W;
    let h = `<div class="tr-r"><div class="tr-lab tr-lab-vide" style="width:${larg}px"></div>`;
    F.vis.forEach((i, k) => { const w = W[i], prem = k === 0 || W[i - 1].mois !== w.mois; h += `<div class="tr-c tr-mois${i === C.cur ? ' cur' : ''}">${prem ? esc(MO[w.mois] + ' ' + w.annee) : ''}</div>`; });
    h += `<div class="tr-tot"></div></div><div class="tr-r tr-r-hd"><div class="tr-lab tr-lab-hd" style="width:${larg}px">${libelle}</div>`;
    F.vis.forEach(i => { const w = W[i]; h += `<div class="tr-c tr-chd${i === C.cur ? ' cur' : ''}">${i === C.cur ? '<span class="tr-cette">Cette semaine</span>' : ''}<b>${dm(w.ms)}</b><small>S${w.iso} · sem. ${w.sdm}</small></div>`; });
    return h + `<div class="tr-tot tr-tot-hd">${total}</div></div>`;
  }

  function rendPlan(C, d, R, auj) {
    const S = E, W = C.W, N = C.N, F = fenetre(C);
    const statut = v => v < 0 ? 'neg' : (v < C.coussin ? 'bas' : '');
    let h = '';

    // La routine du dimanche et la décision.
    let dim;
    if (C.dernierDim < 0) {
      const veille = W[0].ms - DAY;
      dim = { titre: 'Cash réel de départ · dimanche ' + dLong(veille), champ: champ('start#dim', d.startCash, { cls: 'tr-in-gros', ph: 'à saisir' }),
        cmpL: 'Premier lundi du plan', cmpV: dCourt(W[0].ms) + ' ' + W[0].annee, cmpC: '',
        note: d.startCashNote || (d.startCash == null ? 'Le solde RÉEL de ton compte ce dimanche-là, lu dans ta banque. Tant qu’il est vide, le plan part de 0 € : tout est faux.' : 'Le vrai chiffre, pas une estimation : tout le plan part de lui.'), noteC: d.startCash == null || d.startCashNote ? 'bas' : '' };
    } else {
      const i = C.dernierDim, e = C.ecart[i], al = e != null && Math.abs(e) > SEUIL_ECART;
      dim = { titre: 'Solde réel · dimanche ' + dLong(W[i].dimanche), champ: champ('r:' + W[i].du + '#dim', d.reel[W[i].du], { cls: 'tr-in-gros', ph: 'à saisir' }),
        cmpL: e == null ? 'Cash prévu ce dimanche' : 'Écart réel − prévu', cmpV: e == null ? eur(C.fin[i]) : (e > 0 ? '+' : '') + eur(e), cmpC: al ? 'bas' : '',
        note: e == null ? 'Le vrai chiffre, pas une estimation. Dès qu’il est saisi, la semaine suivante repart de ce chiffre.' : (al ? `Plus de ${n(SEUIL_ECART)} € : tu trouves la cause avant lundi. Un écart qu’on n’explique pas revient toujours, en plus gros.` : 'Écart sous contrôle. Passe aux 4 semaines à venir.'),
        noteC: al ? 'bas' : '' };
    }
    const s4 = Math.max(0, Math.min(N - 4, C.cur));
    const p4 = [0, 1, 2, 3].map(k => s4 + k).filter(i => i < N);
    const f4 = p4.map(i => C.fin[i]);
    const decision = f4.some(v => v < 0) ? 'Rouge : tu ne peux pas tout payer. Tu décides tout de suite quoi décaler et tu préviens avant l’échéance, pas après.'
      : (f4.some(v => v < C.coussin) ? 'Orange : tu passes sous ton coussin. Tu décides tout de suite quoi décaler et tu préviens avant l’échéance, pas après.' : 'Le cash prévu reste au-dessus de ton coussin sur les 4 semaines.');
    h += `<div class="tr-g2">
      <div class="tr-carte"><div class="tr-carte-hd"><span class="tr-k">Routine du dimanche</span>${C.oublies ? `<span class="tr-pastille">${C.oublies} dimanche${C.oublies > 1 ? 's oubliés' : ' oublié'}</span>` : ''}</div>
        <div class="tr-titre">${esc(dim.titre)}</div>
        <div class="tr-dim"><label><span>Solde du compte, lu dans ta banque</span>${dim.champ}</label>
        <div class="tr-cmp"><span>${esc(dim.cmpL)}</span><b class="${dim.cmpC}">${esc(dim.cmpV)}</b></div></div>
        <div class="tr-note ${dim.noteC}">${esc(dim.note)}</div></div>
      <div class="tr-carte"><div class="tr-carte-hd"><span class="tr-k">La décision · 4 semaines à venir</span></div>
        <div class="tr-q4">${p4.map(i => `<div class="tr-q ${statut(C.fin[i])}"><span>Lun. ${dm(W[i].ms)}</span><b>${eur(C.fin[i])}</b></div>`).join('')}</div>
        <div class="tr-note">${decision}</div></div></div>`;

    // Les repères chiffrés.
    const plaf = +d.plafond || 0, tauxP = R.taux && R.taux.personnel ? R.taux.personnel / 100 : null;
    const vA = C.tA(C.ventes);
    const kpis = [
      { l: 'Point bas du cash sur la période', v: eur(C.bas), s: 'Semaine du lundi ' + dm(W[C.basIdx].ms) + '/' + W[C.basIdx].annee, c: statut(C.bas), t: 'Le moment où ton compte sera le plus bas. Négatif : un problème à régler avant la semaine indiquée.' },
      { l: 'Semaines sous le coussin', v: C.sous + ' / ' + N, s: 'Coussin : ' + eur(C.coussin), c: C.sous ? 'bas' : '' },
      { l: `Ventes prévues ${C.anT}`, v: eur(vA), s: (C.budA ? 'budget ' + pctS(C.vBud / C.budA - 1) + (C.budK < C.nbA ? ` (${C.budK} sem.)` : '') : 'pas de budget') + ' · ' + (C.n1A ? 'N-1 ' + pctS(C.vN1 / C.n1A - 1) + (C.n1K < C.nbA ? ` (${C.n1K} sem.)` : '') : 'pas de N-1'), c: '', t: 'Les ventes du plan sur l’année, face au budget validé et à l’année passée, sur les semaines qui ont l’un ou l’autre.' },
      { l: `Marchandises en % des ventes (${C.anT})`, v: pct(C.matPct), s: 'Plafond : ' + pct(plaf), c: audela(C.matPct, plaf) ? 'neg-t' : '' },
      { l: `Personnel + gérant en % des ventes (${C.anT})`, v: pct(C.persPct), s: tauxP ? 'Budget : ' + pct(tauxP) : 'Paie + rémunération du gérant', c: tauxP && audela(C.persPct, tauxP) ? 'bas-t' : '' }
    ];
    h += `<div class="tr-kpis">${kpis.map(k => `<div class="tr-kpi"${k.t ? ` title="${esc(k.t)}"` : ''}><span>${esc(k.l)}</span><b class="${k.c}">${esc(k.v)}</b><small>${esc(k.s)}</small></div>`).join('')}</div>`;
    if (!liste(R.sources).length) { h += `<div class="db-alerte">Aucune vente lue pour ce magasin, ni au panel ni dans la copie de caisse : ni réalisé, ni année passée.${R.budgets && R.budgets.some(b => b.moisBudget + b.moisTheorique) ? ' Les ventes prévues partent du budget.' : ' Pas de budget non plus : tape tes ventes prévues dans le plan.'}</div>`; }
    if (C.sansBase) { h += `<div class="db-alerte">${C.sansBase} semaine${C.sansBase > 1 ? 's' : ''} à venir sans budget ni année passée : leurs ventes sont à 0 tant que tu ne les tapes pas.</div>`; }

    // Le graphique du cash de fin de semaine.
    const fin = C.fin, maxV = Math.max(...fin, C.coussin, 0) * 1.08, minV = Math.min(...fin, 0) * 1.08, plage = (maxV - minV) || 1;
    const P = v => ((maxV - v) / plage * 100).toFixed(2) + '%';
    h += `<div class="tr-carte"><div class="tr-cbar"><div class="tr-h3">Cash fin de semaine (prévu)</div><div class="tr-leg">
      <span><i style="background:var(--color-text-muted)"></i>Au-dessus du coussin</span><span><i style="background:var(--color-collection-border)"></i>Sous le coussin</span><span><i style="background:var(--color-primary)"></i>Découvert</span></div></div>
      <div class="tr-graph"><div class="tr-ligne-c" style="top:${P(C.coussin)}"></div><div class="tr-ligne-cl" style="top:${P(C.coussin)}">Coussin ${esc(eur(C.coussin))}</div><div class="tr-ligne-z" style="top:${P(0)}"></div>
      ${W.map((w, i) => { const v = fin[i], s = statut(v); return `<button type="button" class="tr-barre" data-tra="barre:${i}" title="${esc('Lundi ' + dm(w.ms) + '/' + w.annee + ' · ' + eur(v))}" style="opacity:${F.vis.includes(i) ? 1 : 0.35}"><i style="top:${v >= 0 ? P(v) : P(0)};height:${(Math.abs(v) / plage * 100).toFixed(2)}%;background:${s === 'neg' ? 'var(--color-primary)' : (s === 'bas' ? 'var(--color-collection-border)' : 'var(--color-text-muted)')}"></i></button>`; }).join('')}</div>
      <div class="tr-graph-m">${W.map((w, i) => `<span>${(i === 0 || W[i - 1].mois !== w.mois) && (i === 0 || w.mois % 2 === 0) ? MO[w.mois] : ''}</span>`).join('')}</div></div>`;

    // Le plan, semaine par semaine.
    const LARG = 264;
    const lignes = [];
    const ligne = (lab, aide, cellule, o) => { o = o || {}; lignes.push({ lab, aide, cellule, o }); };
    const T = (v, o) => { o = o || {}; const zero = o.tiret && !v; return `<div class="tr-v${o.cls ? ' ' + o.cls : ''}${zero ? ' mu' : ''}"${o.titre ? ` title="${esc(o.titre)}"` : ''}>${v == null ? (o.vide || '') : (zero ? '–' : n(v))}</div>`; };
    // Un repère absent n'est pas une case blanche : un tiret, et pourquoi au survol (14/11/2026, Halle : « je ne vois pas
    // de donnée » — le magasin vend depuis février 2026, il n'a pas d'année passée sur l'hiver).
    const pv = R.premiereVente || null, frD = x => x.split('-').reverse().join('/');
    const motifN1 = w => {
      const fin1 = w.ms - 358 * DAY;
      if (fin1 >= auj) { return 'La même semaine un an plus tôt n’est pas encore passée'; }
      if (!pv) { return 'Aucune vente lue pour ce magasin'; }
      if (isoDe(fin1) < pv) { return 'Un an plus tôt, le magasin ne vendait pas encore (première vente lue le ' + frD(pv) + ')'; }
      return 'Aucune vente lue la même semaine un an plus tôt';
    };
    const visN1 = F.vis.filter(i => W[i].ref.n1 != null).length;
    const noteN1 = visN1 ? '' : (!pv ? 'aucune vente lue pour ce magasin' : (F.vis.some(i => isoDe(W[i].ms - 358 * DAY) < pv) ? 'pas d’année passée : première vente le ' + frD(pv) : 'pas encore passée'));
    const nTape = C.genre.filter(g => g === 'saisi').length;
    const srcLib = { budget: 'budget validé', n1: 'année passée' + (d.croissance ? ' ' + (d.croissance > 0 ? '+ ' : '− ') + String(Math.abs(d.croissance)).replace('.', ',') + ' %' : '') };
    ligne('Cash début de semaine', 'Ce que tu as sur le compte le lundi matin. Calculé tout seul : le solde réel du dimanche précédent s’il est saisi, sinon le cash prévu.', i => T(C.debut[i], { cls: 'mu' }), { bord: 'aucun' });
    ligne('Encaissements (ventes)', 'Tes ventes TTC, semaine par semaine. Une semaine close prend le réel de la caisse. Les autres partent du budget validé ou de l’année passée (onglet N-1, budget, réalisé) ; tu tapes ta prévision par-dessus. Case vidée : retour à la base.', i => {
      const w = W[i], g = C.genre[i];
      if (g === 'reel') { return T(C.ventes[i], { cls: 'reel', titre: 'Réalisé : ventes de la semaine en caisse. Il remplace la prévision.' }); }
      const b = C.base[i], saisi = d.ventes[w.du];
      return champ('s:' + w.du, saisi, { cls: 'tr-in' + (saisi == null ? ' est' : ''), aff: v => v == null ? (b ? '≈ ' + n(b.v) : '') : n(v), ph: b ? '' : 'à saisir', titre: b ? 'Base : ' + srcLib[b.src] + ' ' + eur(b.v) : 'Ni budget ni année passée pour cette semaine' });
    }, { total: C.tA(C.ventes), gras: 600, sous: 'Base : ' + (d.base === 'budget' ? 'budget validé' : srcLib.n1) + (nTape ? ` · ${nTape} sem. tapée${nTape > 1 ? 's' : ''}` : ''), versOnglet: 'reperes' });
    ligne('Repère · réalisé en caisse', 'Les ventes réellement encaissées (panel). La semaine en cours : à ce jour.', i => { const r = W[i].ref, passe = W[i].ms <= auj; return T(r.realise, { cls: 'ref', vide: passe ? '–' : '', titre: r.complet ? (r.realise != null ? 'Semaine close' : 'Aucune vente lue cette semaine') : (r.realise != null ? 'À ce jour, semaine en cours' : (passe ? 'Aucune vente lue à ce jour' : 'Semaine à venir')) }); }, { total: C.tA(W.map(w => w.ref.realise)), ref: true, note: W.some(w => w.ms <= auj) ? '' : 'semaines à venir' });
    ligne('Repère · budget validé', 'Le budget du mois réparti sur ses jours au profil de l’an passé. Vide : pas de budget pour ce mois.', i => { const r = W[i].ref; return T(r.budget, { cls: 'ref', vide: '–', titre: r.budget == null ? 'Pas de budget pour ce mois (cockpit › Budget), ni de CA théorique de l’étude' : [r.budgetSource === 'theorique' ? 'CA théorique de l’étude (pas de budget encodé)' : '', r.budget != null && r.budgetJours < 7 ? `Extrapolé de ${r.budgetJours} jours budgétés sur 7` : ''].filter(Boolean).join(' · ') }); }, { total: C.budA, ref: true });
    ligne('Repère · même semaine N-1', 'Les ventes de la même semaine un an plus tôt (décalée de 364 jours : un lundi face à un lundi).', i => T(W[i].ref.n1, { cls: 'ref', vide: '–', titre: W[i].ref.n1 == null ? motifN1(W[i]) : '' }), { total: C.n1A, ref: true, note: noteN1 });
    ligne('Marchandises (fournisseurs)', 'Calcul automatique : total de l’onglet Fournisseurs.', i => T(C.march[i]), { total: C.tA(C.march), sous: 'Onglet Fournisseurs', versOnglet: 'fournisseurs', bord: 'fort' });
    const aideCat = {
      'Gérant': 'Ce que tu te verses : ligne « Gérant » de l’onglet Paiements. Quand le cash est court, c’est la première ligne qui s’ajuste.',
      'TVA & ONSS': 'Rien n’est prérempli. Demande montants et échéances à ton comptable et encode-les dans Paiements, catégorie « TVA & ONSS ». Tant que c’est vide, ton plan est trop optimiste.'
    };
    CATS.forEach(k => ligne(k, aideCat[k] || `Calcul automatique : somme des lignes « ${k} » de l’onglet Paiements.`, i => T(C.cats[k][i], { tiret: true }), { total: C.tA(C.cats[k]), sous: 'Onglet Paiements', versOnglet: 'paiements' }));
    ligne('Ponctuels / imprévus', 'Tout ce qui n’est pas récurrent : réparation, prime de fin d’année, pécule de vacances, régularisation d’énergie, matériel. Dans la semaine où l’argent sort.', i => champ('p:' + W[i].du, d.ponct[W[i].du], { aff: v => v ? n(v) : '' }), { total: C.tA(C.ponct) });
    ligne('Total décaissements', '', i => T(C.totdec[i], { cls: 'g6' }), { total: C.tA(C.totdec), gras: 600, bord: 'fort' });
    ligne('Solde de la semaine', '', i => T(C.solde[i], { cls: C.solde[i] < 0 ? 'neg-t' : '' }), { total: C.tA(C.solde) });
    ligne('Cash fin de semaine (prévu)', 'La ligne qui compte. Orange : sous ton coussin de sécurité. Rouge : découvert, tu ne peux pas tout payer.', i => T(C.fin[i], { cls: 'g6 ' + statut(C.fin[i]) }), { gras: 600, bord: 'fort' });
    ligne('Cash réel en banque (dimanche)', 'Chaque dimanche soir : le solde RÉEL de ton compte, lu dans ta banque. La semaine suivante repart de ce chiffre. Une case orange foncé = un dimanche oublié.', i => {
      const w = W[i], oubli = d.reel[w.du] == null && w.dimanche < auj;
      return champ('r:' + w.du, d.reel[w.du], { cls: 'tr-in reel-in' + (oubli ? ' oubli' : '') });
    });
    ligne('Écart réel − prévu', `Réel moins prévu. Au-delà de ${n(SEUIL_ECART)} € la case passe en orange : cherche la cause (ventes surestimées, facture oubliée, paiement décalé) avant lundi.`, i => { const e = C.ecart[i]; return T(e, { cls: e != null && Math.abs(e) > SEUIL_ECART ? 'bas' : '' }); });

    h += `<div class="tr-carte tr-carte-t">${barreFenetre(C, F, 'Plan semaine par semaine')}<div class="tr-defil"><div class="tr-grille">`;
    h += entete(C, F, LARG, 'Lundi du', 'Total ' + C.anT);
    lignes.forEach(L => {
      const o = L.o;
      h += `<div class="tr-r${o.ref ? ' tr-r-ref' : ''} ${o.bord === 'fort' ? 'b-fort' : (o.bord === 'aucun' ? 'b-aucun' : '')}"><div class="tr-lab" style="width:${LARG}px"><div class="tr-lab-t"><span style="font-weight:${o.gras || 400}">${esc(L.lab)}</span>${o.note ? `<small class="tr-lab-note">${esc(o.note)}</small>` : ''}${o.sous ? `<button type="button" class="tr-sous-l" data-tra="onglet:${o.versOnglet}">${esc(o.sous)}</button>` : ''}</div>${L.aide ? `<span class="tr-aide" title="${esc(L.aide)}">?</span>` : ''}</div>`;
      F.vis.forEach(i => { h += `<div class="tr-c${i === C.cur ? ' cur' : ''}">${L.cellule(i)}</div>`; });
      h += `<div class="tr-tot" style="font-weight:${o.gras || 400}">${o.total == null ? '' : n(o.total)}</div></div>`;
    });
    h += '</div></div></div>';

    // Le pilotage et les dettes hors plan.
    const hors = d.hors.reduce((t, x) => t + (+x.montant || 0), 0);
    h += `<div class="tr-g2">
      <div class="tr-carte"><div class="tr-h3">Pilotage</div>
        <div class="tr-param"><div><span>Cash réel de départ (dimanche ${esc(dLong(W[0].ms - DAY))})</span>${champ('start', d.startCash, { cls: 'tr-in-p', ph: 'à saisir' })}</div><small${d.startCashNote ? ' class="bas-t"' : ''}>${esc(d.startCashNote || 'Le solde RÉEL de ton compte, lu dans ta banque. Sans lui, tout le plan part d’un chiffre faux.')}</small></div>
        <div class="tr-param"><div><span>Coussin de sécurité minimum (€)</span>${champ('coussin', d.coussin, { cls: 'tr-in-p' })}</div><small>Le minimum que tu veux garder en permanence sur le compte. En dessous, la ligne « Cash fin de semaine » passe en orange.</small></div></div>
      <div class="tr-carte"><div class="tr-hors-hd"><div class="tr-h3">Hors plan, à arbitrer</div><b class="neg-t">${esc(eur(hors))}</b></div>
        <small class="tr-mu">Ce que tu dois mais qui n’est PAS dans le plan ci-dessus. Tant que ces montants ne sont pas transformés en échéancier dans l’onglet Paiements, ton cash prévu est trop beau.</small>
        <div class="tr-hors">${d.hors.map(x => `<div class="tr-hors-l"><div><input class="tr-txt" data-trtxt="h:${esc(x.id)}:libelle" value="${esc(x.libelle)}" aria-label="Dette"><input class="tr-txt tr-txt-mu" data-trtxt="h:${esc(x.id)}:note" value="${esc(x.note)}" placeholder="Remarque"></div>${champ('h:' + x.id, x.montant, { ph: '—' })}<button type="button" class="tr-x" data-tra="delH:${esc(x.id)}" title="Supprimer">×</button></div>`).join('')}</div>
        <button type="button" class="tr-btn" data-tra="addH">+ Ajouter une dette</button></div></div>`;
    return h;
  }

  function rendFournisseurs(C, d) {
    const W = C.W, F = fenetre(C), LARG = 304, plaf = +d.plafond || 0;
    let h = `<p class="tr-intro">Montant à PAYER à chaque fournisseur, semaine par semaine (€ TTC). Case vide = le plan compte ventes prévues × % du fournisseur. Dès que tu connais le montant réel, tape-le dans la case (0 si tu ne paies rien). Le total alimente la ligne Marchandises du plan.</p>`;
    h += `<div class="tr-carte tr-carte-t">${barreFenetre(C, F, 'Achats fournisseurs par semaine')}<div class="tr-defil"><div class="tr-grille">`;
    h += entete(C, F, LARG, '<span class="tr-fl"><span title="Un fournisseur par ligne, 15 au plus.">Fournisseur</span><span title="La part de tes ventes TTC que tu paies en moyenne à ce fournisseur. Elle estime les semaines sans montant réel.">% ventes</span></span>', 'Total période');
    d.fournisseurs.forEach(s => {
      const si = d.fournisseurs.indexOf(s);
      h += `<div class="tr-r"><div class="tr-lab tr-lab-f" style="width:${LARG}px"><button type="button" class="tr-x" data-tra="delF:${esc(s.id)}" title="Supprimer ce fournisseur">×</button><input class="tr-txt" data-trtxt="f:${esc(s.id)}:nom" value="${esc(s.nom)}" placeholder="Nom du fournisseur">${champ('fp:' + s.id, s.pct, { cls: 'tr-in tr-in-pct', edit: v => v == null ? '' : String(+(v * 100).toFixed(2)).replace('.', ','), aff: v => pct(+v || 0) })}</div>`;
      F.vis.forEach(i => { const du = W[i].du, a = s.montants[du], est = C.supEst[si][i]; h += `<div class="tr-c${i === C.cur ? ' cur' : ''}">${champ('fa:' + s.id + ':' + du, a, { cls: 'tr-in' + (a == null ? ' est' : ''), aff: v => v == null ? '≈ ' + n(est) : n(v) })}</div>`; });
      h += `<div class="tr-tot">${n(C.supTot[si])}</div></div>`;
    });
    const sV = C.ventes.reduce((a, b) => a + b, 0), sM = C.march.reduce((a, b) => a + b, 0);
    const som = [
      { l: 'Total achats', s: pct(d.fournisseurs.reduce((t, x) => t + (+x.pct || 0), 0)), cls: 'g6', bord: 'b-fort', t: n(sM), c: i => `<div class="tr-v g6">${n(C.march[i])}</div>` },
      { l: 'En % des ventes', s: '', cls: '', bord: '', t: pct(sV ? sM / sV : 0), c: i => { const p = C.ventes[i] ? C.march[i] / C.ventes[i] : 0; return `<div class="tr-v${audela(p, plaf) ? ' bas' : ''}">${pct(p)}</div>`; } },
      { l: 'dont montants réels saisis', s: '', cls: '', bord: '', t: n(C.supReel.reduce((a, b) => a + b, 0)), c: i => `<div class="tr-v mu">${C.supReel[i] ? n(C.supReel[i]) : '–'}</div>` }
    ];
    som.forEach(r => {
      h += `<div class="tr-r ${r.bord}"><div class="tr-lab tr-lab-s" style="width:${LARG}px"><span class="${r.cls}">${r.l}</span><span class="${r.cls}">${r.s}</span></div>`;
      F.vis.forEach(i => { h += `<div class="tr-c${i === C.cur ? ' cur' : ''}">${r.c(i)}</div>`; });
      h += `<div class="tr-tot ${r.cls}">${r.t}</div></div>`;
    });
    h += `</div></div><div class="tr-pied"><button type="button" class="tr-btn" data-tra="addF"${d.fournisseurs.length >= 15 ? ' disabled' : ''}>+ Ajouter un fournisseur</button>
      <label title="Le maximum d’achats que tu t’autorises par rapport à tes ventes. Dépassé plusieurs semaines de suite : tu commandes trop ou tu jettes trop."><span>Plafond à ne pas dépasser (% des ventes)</span>${champ('plafond', d.plafond, { cls: 'tr-in-p tr-in-pct', edit: v => String(+((+v || 0) * 100).toFixed(1)).replace('.', ','), aff: v => pct(+v || 0) })}</label></div></div>`;
    const T = E.R.taux || {};
    h += `<p class="tr-mu tr-p">Plafond et estimation proposés : le taux matière ${T.matiereDuBudget ? 'du budget (Budget › Paramètres)' : 'du réseau'}, ${String(T.matiere || 40).replace('.', ',')} % du CA. Les factures réellement payées ne sont pas encore lues : tape-les dans les cases.</p>`;
    return h;
  }

  function rendPaiements(C, d) {
    let h = `<p class="tr-intro">Une ligne par paiement. « Chaque semaine » = toutes les semaines. « Chaque mois » = une fois par mois, la semaine indiquée (1 à 4 ; la semaine 1 est celle du premier lundi du mois). « Chaque trimestre » = janvier, avril, juillet et octobre, la semaine indiquée. Dates de début et de fin facultatives. Le plan se met à jour tout seul.</p>`;
    h += `<div class="tr-carte tr-carte-t"><div class="tr-defil"><div class="tr-pay">
      <div class="tr-pay-l tr-pay-hd"><span>Libellé</span><span title="La catégorie décide dans quelle ligne du plan le montant atterrit.">Catégorie</span><span class="d" title="Montant TTC réellement débité du compte à chaque échéance.">Montant (€)</span><span>Fréquence</span><span title="Pour un paiement mensuel ou trimestriel : la semaine du mois où il sort du compte, de 1 à 4.">Sem.</span><span title="Facultatif : avant cette date, rien n’est compté.">Premier paiement</span><span title="Facultatif : après cette date, rien n’est compté.">Dernier paiement</span><span class="d">Sur la période</span><span>Source / remarque</span><span></span></div>`;
    d.paiements.forEach((p, pi) => {
      const hebdo = p.freq === 'Chaque semaine';
      h += `<div class="tr-pay-l"><input class="tr-txt" data-trtxt="p:${esc(p.id)}:libelle" value="${esc(p.libelle)}" placeholder="Libellé">
        <select class="tr-sel" data-trsel="p:${esc(p.id)}:cat">${CATS.map(c => `<option${c === p.cat ? ' selected' : ''}>${esc(c)}</option>`).join('')}</select>
        ${champ('pa:' + p.id, p.montant, { cls: 'tr-in tr-in-b' })}
        <select class="tr-sel" data-trsel="p:${esc(p.id)}:freq">${FREQS.map(c => `<option${c === p.freq ? ' selected' : ''}>${esc(c)}</option>`).join('')}</select>
        <select class="tr-sel" data-trsel="p:${esc(p.id)}:sem"${hebdo ? ' disabled' : ''}><option value="">–</option>${[1, 2, 3, 4].map(k => `<option value="${k}"${+p.sem === k ? ' selected' : ''}>${k}</option>`).join('')}</select>
        <input class="tr-sel" type="date" data-trdate="p:${esc(p.id)}:debut" value="${esc(p.debut)}">
        <input class="tr-sel" type="date" data-trdate="p:${esc(p.id)}:fin" value="${esc(p.fin)}">
        <b class="d">${n(C.payTot[pi])}</b>
        <input class="tr-txt tr-txt-mu" data-trtxt="p:${esc(p.id)}:note" value="${esc(p.note)}" title="${esc(p.note)}" placeholder="—">
        <button type="button" class="tr-x" data-tra="delP:${esc(p.id)}" title="Supprimer ce paiement">×</button></div>`;
    });
    h += `<div class="tr-pied"><button type="button" class="tr-btn" data-tra="addP"${d.paiements.length >= 50 ? ' disabled' : ''}>+ Ajouter un paiement</button><span class="tr-mu">${d.paiements.length} paiement${d.paiements.length > 1 ? 's récurrents' : ' récurrent'}</span></div></div></div></div>`;
    h += '<p class="tr-mu tr-p">Les paiements proposés d’un plan neuf viennent des postes du budget (Budget › Paramètres) : un taux du CA, traduit en euros par mois. Remplace-les par tes montants réels. TVA et ONSS ne sont pas préremplies : tant que tu ne les as pas encodées avec ton comptable, ton plan est trop optimiste.</p>';
    return h;
  }

  /** L'année passée, le budget validé et le réalisé, mois par mois, et la base des ventes prévues. */
  function rendReperes(C, d, R, auj) {
    const pm = ventesParMois(C);
    const tend = R.tendance;
    let h = `<div class="tr-g2">
      <div class="tr-carte"><div class="tr-h3">D’où partent les ventes prévues</div>
        <div class="tr-choix">${[['budget', 'Le budget validé', 'Le CA budget de chaque mois, réparti au profil de l’an passé. À défaut de budget, l’année passée.'], ['n1', 'L’année passée', 'Le CA de la même semaine un an plus tôt, avec la croissance ci-dessous. À défaut, le budget.']].map(([k, l, s]) => `<button type="button" class="tr-ch${d.base === k ? ' on' : ''}" data-tra="base:${k}"><b>${l}</b><small>${s}</small></button>`).join('')}</div>
        <div class="tr-param"><div><span>Croissance appliquée à l’année passée (%)</span>${champ('croissance', d.croissance, { cls: 'tr-in-p', aff: v => (v > 0 ? '+' : '') + String(v || 0).replace('.', ',') + ' %' })}</div>
        <small>${tend ? `Repère : sur les ${tend.semaines} dernières semaines closes, ${eur(tend.ca)} contre ${eur(tend.n1)} un an plus tôt, soit <b>${pctS(tend.pct / 100)}</b>.` : 'Pas assez de semaines comparables pour mesurer la tendance.'}</small></div>
        <small class="tr-mu">Les semaines closes prennent toujours le réel de la caisse ; une vente tapée dans le plan passe avant la base.</small></div>
      <div class="tr-carte"><div class="tr-h3">Le budget</div><div class="tr-buds">${liste(R.budgets).map(b => {
        const etat = b.valideLe ? `validé le ${b.valideLe.split('-').reverse().join('/')}` : (b.fiche ? 'encodé, pas encore validé' : (b.moisBudget + b.moisTheorique ? 'pas de fiche budget' : 'aucun budget encodé'));
        const mois = b.moisBudget + b.moisTheorique;
        return `<div class="tr-bud"><b>${b.annee}</b><span>${esc(etat)}</span><small>${mois ? `${b.moisBudget} mois budgétés${b.moisTheorique ? ', ' + b.moisTheorique + ' au CA théorique de l’étude' : ''} · ${eur(b.total)}` : 'les semaines de cette année partent de l’année passée'}</small></div>`; }).join('')}</div>
        <small class="tr-mu">Le budget s’encode et se valide avec le consultant (cockpit › Budget). Un mois sans budget prend le CA théorique de l’étude, à défaut l’année passée.</small></div></div>`;

    // Le tableau des mois, une année après l'autre.
    const annees = [...new Set(R.mois.map(m => +m.ym.slice(0, 4)))];
    const ratio = (a, b) => a != null && b ? a / b - 1 : null;
    annees.forEach(an => {
      const M = R.mois.filter(m => +m.ym.slice(0, 4) === an);
      const som = (k, pred) => { let s = 0, vu = false; M.forEach(m => { if ((!pred || pred(m)) && m[k] != null) { s += +m[k]; vu = true; } }); return vu ? s : null; };
      const clos = m => m.complet;
      // L'atterrissage du mois : le réalisé des jours d'avant le plan, puis les ventes du plan (réel des semaines closes compris).
      const att = m => (m.avantPlan != null || pm[m.ym]) ? (+m.avantPlan || 0) + (pm[m.ym] || 0) : null;
      const tR = som('realise'), tB = som('budget'), tN = som('n1'), tP = M.reduce((t, m) => t + (att(m) || 0), 0), toutAtt = M.every(m => att(m) != null);
      const tRc = som('realise', clos), tBc = som('budget', m => clos(m) && m.realise != null), tNc = som('n1', m => clos(m) && m.realise != null);
      const cel = (v, cls) => `<td class="${cls || ''}">${v == null ? '–' : esc(v)}</td>`;
      const lignes = [
        ['Réalisé (caisse)', m => cel(m.realise == null ? null : n(m.realise) + (m.complet ? '' : ' *'), 'g6'), cel(tR == null ? null : n(tR), 'g6')],
        ['Budget validé', m => cel(m.budget == null ? null : n(m.budget) + (m.budgetSource === 'theorique' ? ' †' : '')), cel(tB == null ? null : n(tB))],
        ['Atteinte du budget', m => { const r = m.complet ? ratio(m.realise, m.budget) : null; return cel(r == null ? null : pctS(r), r == null ? '' : (r < -0.05 ? 'neg-t' : (r >= 0 ? 'ok-t' : 'bas-t'))); }, (() => { const r = ratio(tRc, tBc); return cel(r == null ? null : pctS(r), r == null ? '' : (r < -0.05 ? 'neg-t' : (r >= 0 ? 'ok-t' : 'bas-t'))); })()],
        ['Année passée (N-1)', m => cel(m.n1 == null ? null : n(m.n1), 'mu'), cel(tN == null ? null : n(tN), 'mu')],
        ['Évolution face à N-1', m => { const r = m.complet ? ratio(m.realise, m.n1) : null; return cel(r == null ? null : pctS(r), r == null ? '' : (r < 0 ? 'neg-t' : 'ok-t')); }, (() => { const r = ratio(tRc, tNc); return cel(r == null ? null : pctS(r), r == null ? '' : (r < 0 ? 'neg-t' : 'ok-t')); })()],
        ['Atterrissage : réalisé, puis plan', m => cel(att(m) == null ? null : n(att(m)), 'pl'), cel(tP && toutAtt ? n(tP) : null, 'pl')],
        ['Atterrissage face au budget', m => { const r = ratio(att(m), m.budget); return cel(r == null ? null : pctS(r), 'mu'); }, (() => { const r = toutAtt ? ratio(tP, tB) : null; return cel(r == null ? null : pctS(r), 'mu'); })()]
      ];
      h += `<div class="tr-carte tr-carte-t"><div class="tr-cbar"><div class="tr-h3">${an} · mois par mois</div><span class="tr-mu">€ TTC · * mois en cours, à ce jour · † CA théorique de l’étude (pas de budget encodé)</span></div><div class="tr-defil"><table class="tr-tab">
        <thead><tr><th></th>${M.map(m => `<th class="${m.ym === isoDe(auj).slice(0, 7) ? 'cur' : ''}">${MO[+m.ym.slice(5, 7) - 1]}</th>`).join('')}<th>Total</th></tr></thead>
        <tbody>${lignes.map(([l, f, t]) => `<tr><th>${esc(l)}</th>${M.map(f).join('')}${t}</tr>`).join('')}</tbody></table></div>
        <div class="tr-note tr-p">Atteinte et évolution : les mois clos seulement${tRc != null ? ' (le total compare les mois clos qui ont les deux chiffres)' : ''}. Atterrissage : le réalisé des jours d’avant le plan, puis les ventes du plan (chaque semaine répartie sur ses jours, le réel des semaines closes compris) ; le total, quand toute l’année est couverte.</div></div>`;
    });
    const src = liste(R.sources);
    h += `<p class="tr-mu tr-p">Ventes : ${src.length ? src.map(s => s === 'panel' ? 'le panel (jour par jour)' : 'la copie de caisse').join(' et ') : 'aucune vente lue'}${R.premiereVente ? ', depuis le ' + R.premiereVente.split('-').reverse().join('/') : ''}. Budget : cockpit › Budget (CA du mois, à défaut le CA théorique de l’étude).</p>`;
    return h;
  }

  function rendGuide(C, d, R) {
    const W = C.W, N = C.N;
    const etape = (k, t, s) => `<div class="tr-etape"><span>${k}</span><div><b>${t}</b><p>${s}</p></div></div>`;
    return `<div class="tr-guide">
      <h3>Plan de trésorerie, du ${esc(dLong(W[0].ms))} au ${esc(dLong(W[N - 1].dimanche))} — comment t’en servir</h3>
      <p>Ce tableau répond à une seule question : est-ce que tu peux payer tout le monde dans les semaines qui viennent. Il ne ment pas, à condition que toi non plus.</p>
      <div class="tr-k">La routine du dimanche soir</div>
      ${etape(1, 'Le solde réel', 'Tu ouvres ta banque et tu tapes le solde du compte dans « Routine du dimanche » (ou la ligne « Cash réel en banque (dimanche) » du plan). Le vrai chiffre, pas une estimation.')}
      ${etape(2, 'L’écart', `Juste en dessous, l’écart entre le réel et le prévu. Plus de ${n(SEUIL_ECART)} € : tu trouves la cause avant lundi. Un écart qu’on n’explique pas revient toujours, en plus gros.`)}
      ${etape(3, 'Les 4 semaines à venir', 'Tu corriges tes ventes prévues (onglet Plan), les montants à payer à chaque fournisseur (onglet Fournisseurs) et les paiements exceptionnels.')}
      ${etape(4, 'La décision', 'Tu lis la ligne « Cash fin de semaine (prévu) ». Orange : tu passes sous ton coussin. Rouge : tu ne peux pas tout payer. Tu décides tout de suite quoi décaler et tu préviens avant l’échéance, pas après.')}
      <div class="tr-k">Ce qui est repris tout seul</div>
      <div class="tr-def"><b>Ce qui a été fait</b><span>Une semaine close prend les ventes réelles de la caisse : tu n’as plus à les taper. Le mois en cours et les mois passés sont dans l’onglet « N-1, budget, réalisé ».</span>
      <b>Le budget validé</b><span>Le CA budget de chaque mois, réparti sur ses semaines au profil de l’an passé : la base des ventes prévues. Ses taux donnent le plafond matière, le repère du personnel et, pour un plan neuf, les paiements proposés.</span>
      <b>L’année passée</b><span>Les ventes de la même semaine un an plus tôt : la base des ventes quand il n’y a pas de budget, ou si tu la choisis (avec une croissance).</span></div>
      <div class="tr-k">Les couleurs</div>
      <div class="tr-def tr-def-c"><span class="tr-ex abr">6 200</span><span>Un chiffre réel que toi seul connais : solde bancaire du dimanche, cash de départ, coussin de sécurité.</span>
      <span class="tr-ex">14 000</span><span>Une prévision que tu modifies : ventes, montants fournisseurs, paiements ponctuels.</span>
      <span class="tr-ex est">≈ 14 000</span><span>Une estimation : la base (budget ou N-1) ou le % du fournisseur, tant que tu n’as rien tapé.</span>
      <span class="tr-ex reel">12 840</span><span>Le réel de la caisse : une semaine close.</span>
      <span class="tr-ex nu">8 500</span><span>Un calcul automatique. Si un chiffre calculé te paraît faux, c’est une case saisie qu’il faut corriger.</span>
      <span class="tr-ex bas">4 210</span><span>Orange : tu passes sous ton coussin de sécurité.</span>
      <span class="tr-ex neg">−1 250</span><span>Rouge : le compte est à découvert, tu ne peux pas tout payer.</span></div>
      <div class="tr-savoir"><div class="tr-k">À savoir</div>
      <span>Tous les montants sont en € TTC : ce qui entre et sort réellement du compte.</span>
      <span>TVA et ONSS ne sont pas préremplies. Tant que tu ne les as pas encodées dans Paiements avec ton comptable, ton plan est trop optimiste.</span>
      <span>Un paiement mensuel tombe la semaine 1, 2, 3 ou 4 du mois. La semaine 1 est celle du premier lundi du mois.</span>
      <span>Le plan est enregistré pour le magasin : ton consultant voit le même. Si vous le modifiez en même temps, le second est prévenu au lieu d’écraser le premier.</span></div></div>`;
  }

  /* --- les gestes --------------------------------------------------------------------------------------------------- */

  /** Une valeur chiffrée validée (sortie du champ ou Entrée). */
  function valider(cle, v) {
    // « #dim » : le même chiffre saisi depuis la carte du dimanche (deux champs, une seule valeur).
    const [k, a, b] = cle.split('#')[0].split(':');
    // Un solde tapé remplace l'avertissement d'un chiffre repris d'un ancien fichier.
    if (k === 'start') { return maj(d => { d.startCash = v; delete d.startCashNote; }); }
    if (k === 'coussin') { return maj(d => { d.coussin = v == null ? 0 : v; }); }
    if (k === 'plafond') { return maj(d => { d.plafond = v == null ? 0 : v / 100; }); }
    if (k === 'croissance') { return maj(d => { d.croissance = v == null ? 0 : v; }); }
    if (k === 's') { return maj(d => { if (v == null) { delete d.ventes[a]; } else { d.ventes[a] = v; } }); }
    if (k === 'p') { return maj(d => { if (!v) { delete d.ponct[a]; } else { d.ponct[a] = v; } }); }
    if (k === 'r') { return maj(d => { if (v == null) { delete d.reel[a]; } else { d.reel[a] = v; } }); }
    if (k === 'fp') { return maj(d => { const s = d.fournisseurs.find(x => x.id === a); if (s) { s.pct = v == null ? 0 : v / 100; } }); }
    if (k === 'fa') { return maj(d => { const s = d.fournisseurs.find(x => x.id === a); if (s) { if (v == null) { delete s.montants[b]; } else { s.montants[b] = v; } } }); }
    if (k === 'pa') { return maj(d => { const p = d.paiements.find(x => x.id === a); if (p) { p.montant = v == null ? 0 : v; } }); }
    if (k === 'h') { return maj(d => { const x = d.hors.find(y => y.id === a); if (x) { x.montant = v; } }); }
  }

  function action(cmd) {
    const S = E; if (!S) { return; }
    const [k, a] = [cmd.slice(0, cmd.indexOf(':') < 0 ? cmd.length : cmd.indexOf(':')), cmd.indexOf(':') < 0 ? '' : cmd.slice(cmd.indexOf(':') + 1)];
    if (k === 'onglet') { S.onglet = a; return rendre(); }
    if (k === 'relire') { return charger(true); }
    if (k === 'importer') { const f = racine && racine.querySelector('[data-trfichier]'); if (f) { f.value = ''; f.click(); } return; }
    if (k === 'reessayer') { S.sale = true; return enregistrer(); }
    if (!S.R || !S.data) { return; }
    const C = calcul(S.data, S.R, jourUTC()), F = fenetre(C);
    if (k === 'fen') { S.fen = a; S.decal = null; return rendre(); }
    if (k === 'nav') { S.decal = +a === 0 ? null : F.borne(F.decal + (+a) * F.nW); return rendre(); }
    if (k === 'barre') { S.decal = F.borne(+a - (F.nW >= C.N ? 0 : 1)); return rendre(); }
    if (k === 'base') { return maj(d => { d.base = a === 'n1' ? 'n1' : 'budget'; }); }
    if (k === 'addF') { return maj(d => { if (d.fournisseurs.length < 15) { d.fournisseurs.push({ id: uid(), nom: '', pct: 0, montants: {} }); } }); }
    if (k === 'delF') { return maj(d => { d.fournisseurs = d.fournisseurs.filter(x => x.id !== a); }); }
    if (k === 'addP') { return maj(d => { if (d.paiements.length < 50) { d.paiements.push({ id: uid(), libelle: '', cat: 'Frais fixes', montant: 0, freq: 'Chaque mois', sem: 1, debut: '', fin: '', note: '' }); } }); }
    if (k === 'delP') { return maj(d => { d.paiements = d.paiements.filter(x => x.id !== a); }); }
    if (k === 'addH') { return maj(d => { if (d.hors.length < 50) { d.hors.push({ id: uid(), libelle: 'Nouvelle dette', montant: null, note: '' }); } }); }
    if (k === 'delH') { return maj(d => { d.hors = d.hors.filter(x => x.id !== a); }); }
    if (k === 'export') { return exporter(C, S.data); }
    if (k === 'reprendre') {
      const c = S.conflit || {};
      S.conflit = null; S.sale = false; S.version = c.version || 0; S.majLe = c.majLe || null;
      S.data = c.plan ? normaliser(c.plan) : null; S.neuf = !c.plan;
      if (S.data && S.data.debut !== S.debut) { S.debut = S.data.debut; S.R = null; return charger(false); }
      if (!S.data) { S.data = defauts(S.R, S.debut); }
      return rendre();
    }
    if (k === 'reset') {
      if (!window.confirm('Repartir d’un plan neuf, proposé d’après le budget et l’année passée, à partir de cette semaine ? Tes saisies (soldes, ventes, fournisseurs, paiements, dettes) seront effacées.')) { return; }
      clearTimeout(S.minuteur); S.sale = false;
      const fini = () => { S.data = null; S.R = null; S.version = 0; S.majLe = null; S.neuf = true; S.decal = null; S.enrA = null; charger(false); };
      if (S.version > 0) {
        appel('/tresorerie/plan', { method: 'POST', body: JSON.stringify({ shop: S.shop, version: S.version, donnees: null }) })
          .then(fini).catch(e => { if (e.status === 409) { S.conflit = e.corps || {}; } else { S.errEnr = e.message; } rendre(); });
      } else { fini(); }
    }
  }

  /**
   * Importer un plan (format v2, JSON) : un ancien fichier repris (Sombreffe, 14/11/2026), une sauvegarde. Un plan qui
   * dit pour quel magasin il a été fait (`magasin`) ne s'importe ailleurs qu'après confirmation ; un plan enregistré
   * n'est remplacé qu'après confirmation. L'import s'enregistre comme une saisie (version, conflit).
   */
  function importer(texte, fichier) {
    const S = E; if (!S) { return; }
    let p = null;
    try { p = JSON.parse(texte); } catch (x) { p = null; }
    const motif = !p || typeof p !== 'object' || Array.isArray(p) ? 'ce n’est pas un fichier JSON lisible'
      : (p.v !== 2 ? 'ce n’est pas un plan de trésorerie au format du module (v: 2)'
        : (!/^\d{4}-\d{2}-\d{2}$/.test(String(p.debut || '')) || new Date(msDe(p.debut)).getUTCDay() !== 1 ? 'premier lundi du plan invalide' : null));
    if (motif) { S.errImport = motif + ' (' + fichier + ')'; return rendre(); }
    const sans = x => String(x || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const ici = ctx.nomShop || ('magasin ' + S.shop);
    if (p.magasin && !sans(ici).includes(sans(p.magasin))
      && !window.confirm(`Ce plan a été fait pour « ${p.magasin} », et ce dashboard est celui de « ${ici} ». L’importer quand même ?`)) { return; }
    if ((S.version > 0 || S.sale) && !window.confirm(`Remplacer le plan de « ${ici} » par celui du fichier « ${fichier} » ? Ce qui y est saisi aujourd’hui sera remplacé.`)) { return; }
    clearTimeout(S.minuteur);
    S.data = normaliser(p); S.sale = true; S.neuf = false; S.conflit = null; S.errImport = null; S.errEnr = null; S.decal = null;
    S.importe = p.source || fichier;
    enregistrer();
    if (S.data.debut !== S.debut) { S.debut = S.data.debut; S.R = null; charger(false); } else { rendre(); }
  }

  function exporter(C, d) {
    const W = C.W;
    const num = v => v == null ? '' : String(Math.round(v));
    const L = [['', ...W.map(w => dm(w.ms) + '/' + w.annee)]];
    const aj = (l, arr) => L.push([l, ...arr.map(num)]);
    aj('Cash début de semaine', C.debut); aj('Encaissements (ventes)', C.ventes);
    aj('Repère · réalisé en caisse', W.map(w => w.ref.realise)); aj('Repère · budget validé', W.map(w => w.ref.budget)); aj('Repère · même semaine N-1', W.map(w => w.ref.n1));
    aj('Marchandises (fournisseurs)', C.march);
    CATS.forEach(k => aj(k, C.cats[k])); aj('Ponctuels / imprévus', C.ponct); aj('Total décaissements', C.totdec);
    aj('Solde de la semaine', C.solde); aj('Cash fin de semaine (prévu)', C.fin); aj('Cash réel en banque (dimanche)', C.reel); aj('Écart réel − prévu', C.ecart);
    const csv = '﻿' + L.map(r => r.map(x => /[;"\n]/.test(x) ? '"' + x.replace(/"/g, '""') + '"' : x).join(';')).join('\r\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    a.download = 'plan_tresorerie_' + String(ctx.nomShop || E.shop).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') + '.csv';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  }

  /** Les écouteurs d'un élément racine : posés une fois, tout passe par eux (délégation). */
  function brancher(el) {
    if (el.dataset.trBranche) { return; }
    el.dataset.trBranche = '1';
    el.addEventListener('click', e => { const b = e.target.closest('[data-tra]'); if (b && el.contains(b) && !b.disabled) { action(b.dataset.tra); } });
    el.addEventListener('focusin', e => {
      const t = e.target; if (!E || !t.dataset || t.dataset.trn == null) { return; }
      const k = t.dataset.trn; if (E.brouillon && E.brouillon.cle === k) { return; }
      E.brouillon = { cle: k, texte: E.fmt[k] != null ? E.fmt[k] : '' };
      t.value = E.brouillon.texte;
      // Sélectionner un instant plus tard, et seulement si le champ a encore la main : sous Chrome, select() la reprend
      // (une case quittée aussitôt par Tab la volait à la suivante).
      setTimeout(() => { if (document.activeElement === t) { try { t.select(); } catch (x) { /* champ sans sélection */ } } }, 0);
    });
    el.addEventListener('input', e => { const t = e.target; if (E && E.brouillon && t.dataset && t.dataset.trn === E.brouillon.cle) { E.brouillon.texte = t.value; } });
    el.addEventListener('keydown', e => {
      const t = e.target; if (!t.dataset) { return; }
      if (t.dataset.trn != null) {
        if (e.key === 'Enter') { e.preventDefault(); t.blur(); }
        if (e.key === 'Escape') { E.annule = true; t.blur(); }
      } else if (t.dataset.trtxt != null && e.key === 'Enter') { t.blur(); }
    });
    // La sortie d'un champ se juge un instant plus tard : Tab a déjà donné la main au champ suivant (son brouillon
    // a remplacé celui-ci), et un redessin qui retire le champ en saisie (Chrome le fait sortir) le redessine aussitôt
    // avec la même saisie — celle-là ne se valide pas, elle continue.
    el.addEventListener('focusout', e => {
      const t = e.target; if (!E || !t.dataset || t.dataset.trn == null) { return; }
      const k = t.dataset.trn, b = E.brouillon, S = E;
      if (!b || b.cle !== k) { return; }
      setTimeout(() => {
        if (!t.isConnected && S.brouillon === b && racine && racine.querySelector(`[data-trn="${CSS.escape(k)}"]`)) { return; }
        if (S.brouillon === b) { S.brouillon = null; }
        if (S.annule) { S.annule = false; return planifierRendu(); }
        const v = lireNombre(b.texte);
        if (v === undefined || b.texte === S.fmt[k]) { return planifierRendu(); }
        valider(k, v);
      }, 0);
    });
    el.addEventListener('change', e => {
      const t = e.target; if (!E || !t.dataset) { return; }
      if (t.dataset.trfichier != null) {
        const f = t.files && t.files[0]; if (!f) { return; }
        if (f.size > 600000) { E.errImport = 'fichier trop lourd (' + f.name + ')'; return rendre(); }
        f.text().then(x => importer(x, f.name)).catch(() => { E.errImport = 'fichier illisible (' + f.name + ')'; rendre(); });
        return;
      }
      const ref = t.dataset.trtxt || t.dataset.trsel || t.dataset.trdate; if (!ref) { return; }
      const [g, id, champ2] = ref.split(':'), v = t.value;
      maj(d => {
        const L = g === 'f' ? d.fournisseurs : (g === 'p' ? d.paiements : d.hors);
        const x = L.find(y => y.id === id); if (!x) { return; }
        if (champ2 === 'sem') { x.sem = v ? +v : null; }
        else if (champ2 === 'freq') { x.freq = v; if (v === 'Chaque semaine') { x.sem = null; } else if (!x.sem) { x.sem = 1; } }
        else { x[champ2] = v; }
      });
    });
  }

  // Une saisie en attente part avant que la page se ferme.
  window.addEventListener('pagehide', () => {
    const S = E; if (!S || !S.sale || S.envoi || S.conflit) { return; }
    try {
      fetch(ctx.apiBase + '/tresorerie/plan', { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ shop: S.shop, version: S.version, donnees: S.data }) });
    } catch (x) { /* la page se ferme */ }
  });

  window.DashTresorerie = {
    /** Monte (ou remonte, après un redessin du dashboard) le module dans `el`, pour `o.shop`. */
    mount(el, o) {
      ctx = Object.assign({}, ctx, o || {});
      const shop = String(o.shop);
      if (!ETATS[shop]) { ETATS[shop] = nouvelEtat(shop); }
      E = ETATS[shop]; racine = el;
      brancher(el);
      if (!E.R && !E.lecture) { charger(false); } else { rendre(); }
    },
    /** Relire les repères (le bouton « ↻ Relire » du dashboard) ; les saisies en cours sont gardées. */
    relire() { if (E) { charger(true); } },
    // Pour les tests : le calcul et le plan neuf, sans écran.
    _calcul: calcul, _defauts: defauts, _normaliser: normaliser
  };
})();
