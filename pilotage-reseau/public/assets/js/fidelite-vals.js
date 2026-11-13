/* Cockpit CEO — ERP franchisé › Fidélité (#/fidelity). Réécrit le 13/11/2026 : un mix bingo + points, une seule
 * carte, tout par l'API de l'ERP (maquettes docs/maquettes/fidelite-bingo, serveur src/fidelite.php).
 *
 * Programme (GET /fidelite) : la carte à points de l'ERP — son barème se modifie sur place (tranches, ×2, coefficients
 * par magasin, paliers, validités) et reste un brouillon jusqu'à « Publier dans l'ERP » (POST /fidelite/publier, avec
 * la revision lue ; carte figée → version suivante). Le bingo de la saison, ses bons et leurs campagnes. À droite : le
 * simulateur (POST /fidelite/simuler → /admin/loyalty/simulate), la publication, d'où vient l'écran.
 *
 * Clients (GET /fidelite/clients) : la récurrence que le bingo produit, magasin par magasin ; les bons dus
 * (POST /fidelite/bingo/bons, simulation puis émission).
 *
 * Remplit `common.fid` ; le gabarit est tplFidelite (fidelite-tpl.js). */
import { readOne } from './api.js';

const nf = (n, d) => n == null ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).replace(/ /g, ' ');
const eur = v => v == null ? '—' : nf(v, v % 1 ? 2 : 0) + ' €';
const cents = c => eur(c / 100);
const copie = o => JSON.parse(JSON.stringify(o));
const JOURS = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
const JOURS_L = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
const ddmm = iso => iso ? iso.slice(8, 10) + '/' + iso.slice(5, 7) : '';
/** « 14 », « 14h30 », « 1430 », « 14:30 » → « 14:30 » ; sinon null. */
const heure = v => { const m = String(v || '').trim().match(/^(\d{1,2})(?:\s*[:hH.]\s*|)(\d{2})?$/); if (!m) { return null; } const h = +m[1], mi = +(m[2] || 0); return h < 24 && mi < 60 ? String(h).padStart(2, '0') + ':' + String(mi).padStart(2, '0') : null; };
const num = v => { const n = parseFloat(String(v).replace(',', '.')); return isFinite(n) ? n : null; };

/* --- Lectures ------------------------------------------------------------------------------------ */
function charge(app, quoi, force){
  const S = app.state, shop = S.fidShop || '', q = S.fidQ || '';
  const k = quoi === 'p' ? 'fidP' : 'fidC';
  const sa = S.fidSaison || '';
  const cle = quoi === 'p' ? 'p|' + sa : 'c|' + shop + '|' + q;
  const b = S[k];
  if (!force && b && b.cle === cle && (b.d || b.chargement)) { return; }
  if (!force && app['_' + k] === cle) { return; }
  app['_' + k] = cle;
  app.setState({ [k]: { cle, chargement: true, d: b && b.cle === cle ? b.d : (b && b.d) || null } });
  const qs = quoi === 'p' ? (sa ? '?saison=' + encodeURIComponent(sa) : '') : '?' + [shop ? 'shop=' + encodeURIComponent(shop) : '', q ? 'q=' + encodeURIComponent(q) : ''].filter(Boolean).join('&');
  readOne((quoi === 'p' ? '/fidelite' : '/fidelite/clients') + (qs === '?' ? '' : qs)).then(d => {
    app['_' + k] = null;
    app.setState({ [k]: { cle, chargement: false, d: d || { erp: { ok: false, erreur: 'API du cockpit injoignable' } } } });
  });
}

/* --- Le barème en cours d'écriture ----------------------------------------------------------------- */
function bareme(app, d){ return app.state.fidBrouillon || (d.carte && d.carte.points ? copie(d.carte.points) : null); }
function change(app, d, fn){ const p = copie(bareme(app, d)); fn(p); app.setState({ fidBrouillon: p, fidMsg: null }); }
/** Ce qui change entre le barème publié et le brouillon, en phrases. */
function differences(a, b, mags){
  if (!a || !b) { return []; }
  const out = [];
  if (a.slice_cents !== b.slice_cents || a.points_per_slice !== b.points_per_slice) { out.push(eur(b.slice_cents / 100) + ' = ' + b.points_per_slice + ' point' + (b.points_per_slice > 1 ? 's' : '')); }
  if (JSON.stringify(a.bonuses) !== JSON.stringify(b.bonuses)) { out.push('Les ×2 : ' + b.bonuses.length + ' créneau' + (b.bonuses.length > 1 ? 'x' : '')); }
  mags.forEach(m => { const x = +(a.shop_coefficients[m.id] || 1), y = +(b.shop_coefficients[m.id] || 1); if (x !== y) { out.push(m.nom + ' ×' + nf(y, 2)); } });
  if (JSON.stringify(a.tiers) !== JSON.stringify(b.tiers)) { out.push('Paliers : ' + b.tiers.map(t => t.points + ' → ' + cents(t.voucher_cents)).join(', ')); }
  if (a.voucher_validity_days !== b.voucher_validity_days) { out.push('Bon valable ' + b.voucher_validity_days + ' jours'); }
  if (a.points_validity_days !== b.points_validity_days) { out.push('Points valables ' + b.points_validity_days + ' jours'); }
  return out;
}

function publier(app, d){
  const S = app.state, c = d.carte;
  if (!c || S.fidEnvoi) { return; }
  const corps = { qui: 'Cockpit' };
  if (S.fidBrouillon) { corps.carte = { id: c.id, revision: c.revision, points: S.fidBrouillon }; corps.debut = S.fidDebut || ''; }
  if (S.fidArreter !== false && (d.autres || []).length) { corps.arreter = d.autres.map(a => ({ id: a.id, le: S.fidDebut ? prevJour(S.fidDebut) : '' })); }
  if (!corps.carte && !corps.arreter) { return; }
  app.setState({ fidEnvoi: true, fidMsg: null });
  app.api('POST', '/fidelite/publier', corps).then(r => {
    const ok = r && r.ok;
    const etapes = (r && r.etapes) || [];
    app.setState({ fidEnvoi: false, fidMsg: { ko: !ok, txt: ok ? 'Publié dans l’ERP : ' + etapes.map(e => e.quoi).join(' ; ') + '.'
      : 'Publication arrêtée : ' + ((r && r.error) || (etapes.find(e => !e.ok) || {}).erreur || 'sans réponse') + (etapes.some(e => e.ok) ? ' — déjà fait : ' + etapes.filter(e => e.ok).map(e => e.quoi).join(' ; ') : '') } });
    if (ok) { app.setState({ fidBrouillon: null }); }
    charge(app, 'p', true);
  });
}
const prevJour = iso => { const t = new Date(iso + 'T12:00:00Z'); t.setUTCDate(t.getUTCDate() - 1); return t.toISOString().slice(0, 10); };

function simuler(app, d){
  const S = app.state, sim = S.fidSim || {};
  const shop = sim.shop || ((d.magasins || [])[0] || {}).id;
  if (!shop) { return; }
  app.setState({ fidSim: Object.assign({}, sim, { envoi: true }) });
  app.api('POST', '/fidelite/simuler', { shop: +shop, montant: sim.montant || '22,40', quand: sim.quand || (d.aujourdhui + 'T' + d.heure), points: bareme(app, d) }).then(r => {
    app.setState({ fidSim: Object.assign({}, app.state.fidSim || {}, { envoi: false, r: r || { error: 'sans réponse' } }) });
  });
}

/* --- Programme ------------------------------------------------------------------------------------ */
function valsProgramme(app, f){
  const S = app.state, b = S.fidP || {}, d = b.d;
  f.chargement = !d && !!b.chargement;
  if (!d) { return; }
  f.erp = d.erp || { ok: false };
  const mags = d.magasins || [];
  const B = d.bingo || {};
  const sa = B.saison;
  f.saison = sa ? { nom: sa.nom, enCours: sa.enCours, n: sa.semaines.length, du: ddmm(sa.du), au: ddmm(sa.au), aValider: sa.aValider } : null;
  // La carte.
  const c = d.carte;
  f.carte = c ? { nom: c.nom, code: c.code, version: c.version, revision: c.revision, verrou: c.verrou, id: c.id, nMag: (c.magasins || []).length,
    debut: c.debut ? ddmm(c.debut.slice(0, 10)) : '' } : null;
  if (f.carte && c.points) { f.carte.regle = eur(c.points.slice_cents / 100) + ' = ' + c.points.points_per_slice + ' point' + (c.points.points_per_slice > 1 ? 's' : '') + (B.seuil ? ' · une case dès ' + eur(B.seuil) + ' par semaine' : ''); }
  // Les cartes de l'année : la carte à points, et les quatre cartes de bingo (une par saison).
  f.annee = (d.annee || []).map(x => ({ id: x.id, nom: x.nom, ill: x.ill, du: ddmm(x.du), au: ddmm(x.au), etat: x.etat, aValider: x.aValider,
    etatTxt: x.etat === 'cours' ? 'en cours · S' + x.enCours : (x.etat === 'passee' ? 'terminée' : 'à venir'), on: sa && x.id === sa.id,
    choisir: () => app.setState({ fidSaison: x.id }) }));
  f.autres = (d.autres || []).map(a => ({ nom: a.nom, quoi: a.tampons ? a.tampons + ' tampons → ' + a.recompense : (a.points ? 'points' : ''), couleur: a.couleur || '#B8B2A8' }));
  f.arreter = S.fidArreter !== false;
  f.basculerArreter = () => app.setState({ fidArreter: !f.arreter });
  // Le barème, modifiable sur place.
  const P = c ? bareme(app, d) : null;
  if (P) {
    const ch = fn => change(app, d, fn);
    f.base = { euros: nf(P.slice_cents / 100, 2), points: P.points_per_slice,
      setEuros: e => { const v = num(e.target.value); if (v && v > 0) { ch(p => { p.slice_cents = Math.round(v * 100); }); } },
      setPoints: e => { const v = parseInt(e.target.value, 10); if (v > 0) { ch(p => { p.points_per_slice = v; }); } } };
    f.bonus = P.bonuses.map((x, i) => ({ nom: x.name, coef: +x.coefficient, de: x.from, a: x.to,
      jours: JOURS.map((l, k) => ({ l, titre: JOURS_L[k], on: x.days.includes(k), basculer: () => ch(p => { const j = p.bonuses[i].days; p.bonuses[i].days = j.includes(k) ? (j.length > 1 ? j.filter(z => z !== k) : j) : j.concat([k]).sort((u, v) => u - v); }) })),
      setNom: e => ch(p => { p.bonuses[i].name = e.target.value.trim() || 'Bonus'; }),
      setCoef: e => ch(p => { p.bonuses[i].coefficient = +e.target.value; }),
      setDe: e => { const h = heure(e.target.value); if (h && h < x.to) { ch(p => { p.bonuses[i].from = h; }); } else { e.target.value = x.from; } },
      setA: e => { const h = heure(e.target.value); if (h && h > x.from) { ch(p => { p.bonuses[i].to = h; }); } else { e.target.value = x.to; } },
      retirer: () => ch(p => { p.bonuses.splice(i, 1); }) }));
    f.ajouterBonus = () => ch(p => { p.bonuses.push({ name: 'Nouveau créneau', days: [1, 2, 3], from: '14:00', to: '17:00', coefficient: 2 }); });
    f.coefs = mags.map(m => ({ nom: m.nom, v: nf(+(P.shop_coefficients[m.id] ?? 1), 2), modif: c.points && +(c.points.shop_coefficients[m.id] ?? 1) !== +(P.shop_coefficients[m.id] ?? 1),
      set: e => { const v = num(e.target.value); if (v != null && v >= 0 && v <= 100) { ch(p => { if (Array.isArray(p.shop_coefficients)) { p.shop_coefficients = {}; } p.shop_coefficients[m.id] = v; }); } } }));
    f.paliers = P.tiers.map((t, i) => ({ points: t.points, euros: nf(t.voucher_cents / 100, 2), taux: nf(100 * t.voucher_cents / 100 / t.points / (P.slice_cents / 100) * P.points_per_slice, 1),
      setPoints: e => { const v = parseInt(e.target.value, 10); if (v > 0) { ch(p => { p.tiers[i].points = v; p.tiers.sort((u, w) => u.points - w.points); }); } },
      setEuros: e => { const v = num(e.target.value); if (v && v > 0) { ch(p => { p.tiers[i].voucher_cents = Math.round(v * 100); }); } },
      retirer: P.tiers.length > 1 ? () => ch(p => { p.tiers.splice(i, 1); }) : null }));
    f.ajouterPalier = P.tiers.length < 19 ? () => ch(p => { const l = p.tiers[p.tiers.length - 1]; p.tiers.push({ points: l.points * 2, voucher_cents: Math.round(l.voucher_cents * 2.2 / 10) * 10 }); }) : null;
    f.validites = { bon: P.voucher_validity_days, points: P.points_validity_days,
      setBon: e => { const v = parseInt(e.target.value, 10); if (v >= 0) { ch(p => { p.voucher_validity_days = v; }); } },
      setPoints: e => { const v = parseInt(e.target.value, 10); if (v >= 0) { ch(p => { p.points_validity_days = v; }); } } };
    // Ce qui est modifié.
    const diff = S.fidBrouillon ? differences(c.points, S.fidBrouillon, mags) : [];
    f.modifs = diff;
    f.modifie = k => S.fidBrouillon && c.points && JSON.stringify(c.points[k]) !== JSON.stringify(S.fidBrouillon[k]);
    f.marques = { base: f.modifie('slice_cents') || f.modifie('points_per_slice'), bonus: f.modifie('bonuses'), coefs: f.modifie('shop_coefficients'), paliers: f.modifie('tiers'),
      validites: f.modifie('voucher_validity_days') || f.modifie('points_validity_days') };
  }
  // Le bingo.
  f.bingo = sa ? { seuil: B.seuil, seuilTxt: eur(B.seuil), multTxt: nf(B.multiplicateur || 2), palierTxt: B.palier ? B.palier.points + ' points, ' + eur(B.palier.valeur) : 'palier', tousLes: B.tousLes, validite: B.validite, prefixe: B.prefixe,
    bons: (B.bons || []).map(x => ({ k: x.k, v: eur(x.valeur), nom: x.nom, motif: x.motif, camp: x.campagne ? { nom: x.campagne.nom, statut: x.campagne.statut, actif: x.campagne.statut === 'ACTIVE' } : null })),
    sup: B.superBon ? { v: eur(B.superBon.valeur), provisoire: B.superBon.provisoire, camp: B.superBon.campagne ? { statut: B.superBon.campagne.statut, actif: B.superBon.campagne.statut === 'ACTIVE' } : null } : null,
    tirage: B.tirage ? { lot: B.tirage.lot, le: sa.tirage ? ddmm(sa.tirage) : '' } : null,
    semaines: sa.semaines.map(w => ({ n: w.n, titre: w.titre, ill: w.ill, fete: !!w.fete, etat: w.etat, du: ddmm(w.du) })) } : null;
  f.nbCamp = f.bingo ? f.bingo.bons.filter(x => x.camp).length : 0;
  // Le simulateur.
  const sim = S.fidSim || {};
  f.sim = { shops: mags.map(m => ({ id: m.id, nom: m.nom, on: String(m.id) === String(sim.shop || (mags[0] || {}).id) })), montant: sim.montant || '22,40',
    quand: sim.quand || (d.aujourdhui + 'T' + d.heure), envoi: !!sim.envoi, r: sim.r || null,
    setShop: e => app.setState({ fidSim: Object.assign({}, sim, { shop: e.target.value, r: null }) }),
    setMontant: e => app.setState({ fidSim: Object.assign({}, sim, { montant: e.target.value, r: null }) }),
    setQuand: e => app.setState({ fidSim: Object.assign({}, sim, { quand: e.target.value, r: null }) }),
    lancer: () => simuler(app, d) };
  if (f.sim.r && !f.sim.r.error) {
    const r = f.sim.r;
    f.sim.lignes = [['Tranches de ' + eur(P ? P.slice_cents / 100 : 1), nf(r.tranches) + ' × ' + (P ? P.points_per_slice : 1)], ['Magasin', '×' + nf(+r.coefMagasin, 2)], ['Bonus du moment', +r.coefBonus > 1 ? '×' + nf(+r.coefBonus, 2) : 'aucun'],
      ['Bingo : ' + eur(r.montant) + ' ≥ ' + eur(r.seuil) + ' ?', r.coche ? 'la case, à lui seul' : 'pas la case à lui seul']];
  }
  // La publication.
  f.debut = S.fidDebut || '';
  f.setDebut = e => app.setState({ fidDebut: e.target.value });
  f.envoi = !!S.fidEnvoi;
  f.aPublier = (f.modifs || []).length + (f.arreter ? f.autres.length : 0);
  f.publier = f.aPublier ? () => publier(app, d) : null;
  f.annuler = S.fidBrouillon ? () => app.setState({ fidBrouillon: null, fidMsg: null }) : null;
}

/* --- Clients ---------------------------------------------------------------------------------------- */
function valsClients(app, f){
  const S = app.state, b = S.fidC || {}, d = b.d;
  f.chargementC = !d || (!!b.chargement && !d.semaines);
  if (!d) { return; }
  f.erpC = d.erp || { ok: false };
  if (!f.erpC.ok) { return; }
  const K = d.kpis || {};
  f.cl = { appels: d.appels, seuil: d.seuil, seuilTxt: eur(d.seuil), tousLes: d.tousLes, saison: d.saison ? d.saison.nom : '', du: d.saison ? ddmm(d.saison.du) : '', aujourdhui: ddmm(d.aujourdhui) + ' à ' + d.heure,
    mag: d.shop ? ((d.magasins || []).find(m => String(m.id) === String(d.shop)) || {}).nom : 'le réseau', chargement: !!b.chargement };
  f.tuiles = [
    ['Membres de la carte', nf(K.membres), nf(K.venus) + ' venus dans la saison', 'programs[].global_progress.points'],
    ['Jouent au bingo', K.partJoueurs == null ? '—' : K.partJoueurs + ' %', nf(K.joueurs) + ' membres ont au moins une case', 'calcul · semaine par semaine'],
    ['Case cochée cette semaine', K.semaine == null ? '—' : K.semaine + ' %', K.semaineFinie ? 'semaine finie' : 'la semaine n’est pas finie', 'calcul · semaine en cours'],
    ['Points gagnés', nf(K.points), 'depuis le ' + f.cl.du + ' · ' + nf(K.transactions) + ' tickets', 'shop_activity.points_earned'],
    ['Cartes reconnues en caisse', nf(K.reconnues), nf(K.appareils) + ' caisses · ni scans ni achats', 'recognitions.count'],
  ];
  const mx = Math.max(10, ...d.semaines.map(w => Math.max(w.part || 0, w.avant || 0)));
  f.semaines = d.semaines.map(w => ({ n: w.n, titre: w.titre, ill: w.ill, part: w.part, avant: w.avant, cours: w.etat === 'cours',
    hP: Math.round(150 * (w.part || 0) / mx), hA: Math.round(150 * (w.avant || 0) / mx), ecart: w.part != null && w.avant != null && w.etat !== 'cours' ? (w.part - w.avant >= 0 ? '+' : '') + (w.part - w.avant) : '' }));
  const R = d.repartition || [], rm = Math.max(1, ...R);
  f.repartition = R.map((n, k) => ({ k, n: nf(n), l: Math.round(100 * n / rm), bon: k > 0 && k % d.tousLes === 0, plein: k === R.length - 1 && k > 0 }));
  f.enCourse = nf(R[R.length - 1] || 0); f.sansCase = nf(R[0] || 0);
  f.relances = (d.relances || []).map(r => ({ n: nf(r.n), titre: r.titre, detail: r.detail }));
  f.membres = (d.membres || []).map(m => ({ nom: m.nom, solde: nf(m.solde), palier: m.atteint ? m.atteint + ' atteint · à échanger' : (m.prochain ? 'encore ' + m.prochain.reste + ' pour ' + m.prochain.points : ''),
    coches: m.coches, cases: m.cases, n: m.coches.length, semaine: nf(m.semaine, 2) + ' €', pts: nf(m.pointsSemaine) + ' pts', ok: m.semaine >= d.seuil, dernier: m.dernier ? m.dernier.slice(8, 10) + '/' + m.dernier.slice(5, 7) + ' ' + m.dernier.slice(11, 16) : '—', afaire: m.afaire }));
  f.nbMembres = nf(d.nbMembres);
  f.q = S.fidQ || '';
  f.chercher = e => { app.setState({ fidQ: e.target.value.trim() }); };
  // Les bons dus.
  const bo = S.fidBons || {};
  f.bons = { chargement: !!bo.chargement, r: bo.r || null, envoi: !!bo.envoi,
    voir: () => { app.setState({ fidBons: { chargement: true } }); app.api('POST', '/fidelite/bingo/bons', { ecrire: false }).then(r => app.setState({ fidBons: { r } })); },
    emettre: () => { app.setState({ fidBons: Object.assign({}, bo, { envoi: true }) }); app.api('POST', '/fidelite/bingo/bons', { ecrire: true }).then(r => app.setState({ fidBons: { r, emis: true } })); } };
  if (bo.r && bo.r.bons) {
    f.bons.lignes = bo.r.bons.map(x => ({ v: eur(x.valeur), k: x.k, nom: x.nom, ayants: nf(x.ayants), emis: nf(x.emis), a: x.aEmettre, aTxt: nf(x.aEmettre),
      camp: x.campagne ? x.campagne.statut : 'pas de campagne', actif: x.campagne && x.campagne.statut === 'ACTIVE' }));
    f.bons.total = bo.r.bons.filter(x => x.campagne && x.campagne.statut === 'ACTIVE').reduce((s, x) => s + x.aEmettre, 0);
    f.bons.crees = bo.emis ? bo.r.crees : null;
  }
}

/* --- Point d'entrée --------------------------------------------------------------------------------- */
export function valsFidelite(app, common){
  const S = app.state;
  const onglet = S.fidOnglet || 'p';
  charge(app, 'p', false);
  if (onglet === 'c') { charge(app, 'c', false); }
  const dP = S.fidP && S.fidP.d;
  const mags = (dP && dP.magasins) || [];
  const shop = S.fidShop || '';
  const f = { onglet, msg: S.fidMsg || null, fermerMsg: () => app.setState({ fidMsg: null }),
    ongletP: () => app.setState({ fidOnglet: 'p' }), ongletC: () => app.setState({ fidOnglet: 'c' }),
    portees: [{ id: '', nom: 'Réseau', on: !shop }].concat(mags.map(m => ({ id: m.id, nom: m.nom, on: String(m.id) === String(shop) }))).map(m => Object.assign(m, { choisir: () => app.setState({ fidShop: m.id }) })) };
  common.fid = f;
  valsProgramme(app, f);
  if (onglet === 'c') { valsClients(app, f); }
}
