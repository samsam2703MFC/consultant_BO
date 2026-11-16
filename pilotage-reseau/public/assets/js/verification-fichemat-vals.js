/* Cockpit CEO — Recettes à corriger › Matières premières › la fiche d'une matière, modifiable (14/11/2026).
 * Maquette : docs/maquettes/fiche-matiere. Serveur : GET / POST /verification/recettes/matiere (src/verification_matiere_edition.php).
 * Tous les paramètres que le panel rend pour la matière (pour tout le réseau), puis la comparaison de ses prix : le prix
 * général et le prix conseillé du panel, chaque fournisseur lié, chaque magasin, l'écart de chacun au prix général. Rien
 * ne part sans « Vérifier » puis « Enregistrer au panel » : écrit, relu, au registre (« Annuler »). Gabarit :
 * verification-fichemat-tpl.js. */
import { readOne, write } from './api.js';

const nf = (n, d) => n == null || isNaN(n) ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/[  ]/g, ' ');
const num = s => { const t = String(s == null ? '' : s).trim().replace(/\s/g, '').replace(',', '.').replace('%', ''); if (t === '') { return null; } const n = Number(t); return isFinite(n) ? n : NaN; };
const dec = (v, par) => par === 'pce' || par === 'unité' ? (v < 1 ? 4 : (v < 10 ? 3 : 2)) : (v < 0.1 ? 4 : 2);
const pxU = (v, par) => v == null || isNaN(v) ? '—' : nf(v, dec(v, par));
const enSaisie = (v, par) => v == null || isNaN(v) ? '' : String(+Number(v).toFixed(dec(v, par) + 2)).replace('.', ',');
const ROUTE = '/verification/recettes/';
/** Les allergènes du panel (codes UE) en français. */
const ALL = { cereals_gluten: 'Gluten', gluten: 'Gluten', crustaceans: 'Crustacés', eggs: 'Œufs', fish: 'Poissons', peanuts: 'Arachides', soybeans: 'Soja', soy: 'Soja', soya: 'Soja',
  milk: 'Lait', nuts: 'Fruits à coque', tree_nuts: 'Fruits à coque', celery: 'Céleri', mustard: 'Moutarde', sesame_seeds: 'Sésame', sesame: 'Sésame', sulphites: 'Sulfites',
  sulfites: 'Sulfites', sulphur_dioxide: 'Sulfites', lupin: 'Lupin', molluscs: 'Mollusques' };

/** Ouvre la fiche d'une matière. */
export function ouvrirFiche(app, mid){
  app.setState({ vxFiche: { mid, chargement: true }, vxEd: { champs: {}, magasins: {} }, vxPrev: null, vxRes: null, vxMode: 'modifier' });
  readOne(ROUTE + 'matiere?mid=' + encodeURIComponent(mid)).then(d => {
    if (((app.state.vxFiche || {}).mid) !== mid) { return; }
    app.setState({ vxFiche: { mid, d: d || { ok: false, motif: 'Le cockpit n’a pas répondu.' } } });
  });
}

export function valsFicheMat(app){
  const S = app.state, F = S.vxFiche;
  if (!F) { return null; }
  const fermer = () => app.setState({ vxFiche: null, vxEd: null, vxPrev: null, vxRes: null });
  if (F.chargement) { return { chargement: true, fermer }; }
  const d = F.d || {};
  if (!d.ok) { return { erreur: d.motif || d.error || 'La matière n’a pas pu être lue.', fermer }; }
  const E = S.vxEd || { champs: {}, magasins: {} };
  const maj = patch => app.setState({ vxEd: Object.assign({}, app.state.vxEd || { champs: {}, magasins: {} }, patch), vxPrev: null, vxRes: null });
  const poser = (cle, val) => maj({ champs: Object.assign({}, (app.state.vxEd || {}).champs, { [cle]: val }) });
  const poserMag = (sid, val) => maj({ magasins: Object.assign({}, (app.state.vxEd || {}).magasins, { [sid]: val }) });
  const par = d.par || 'unité', fac = d.facteur || 1;
  const champ = cle => (d.champs || []).find(c => c.cle === cle) || {};
  const val = cle => Object.prototype.hasOwnProperty.call(E.champs, cle) ? E.champs[cle] : champ(cle).valeur;
  const change = cle => Object.prototype.hasOwnProperty.call(E.champs, cle) && JSON.stringify(E.champs[cle]) !== JSON.stringify(champ(cle).valeur);
  const R = d.refs || {};
  const opts = (liste, v) => liste.map(o => Object.assign(o, { on: String(o.id) === String(v) }));
  // Les paramètres.
  const params = (d.champs || []).filter(c => c.type !== 'prix' && c.type !== 'allergenes' && c.type !== 'oui').map(c => {
    const v = val(c.cle);
    const b = { cle: c.cle, lib: c.lib, modifiable: c.modifiable, change: change(c.cle), avant: c.valeur, id: 'vx-' + c.cle };
    if (!c.modifiable) { return Object.assign(b, { type: 'lecture', txt: 'non rendu par le panel' }); }
    if (c.type === 'categorie') { return Object.assign(b, { type: 'choix', options: opts(Object.keys(R.categories || {}).map(k => ({ id: k, nom: R.categories[k] })), v), poser: e => poser(c.cle, Number(e.target.value)), avantTxt: (R.categories || {})[c.valeur] || c.valeur }); }
    if (c.type === 'unite') { return Object.assign(b, { type: 'choix', options: opts(Object.keys(R.unites || {}).map(k => ({ id: k, nom: R.unites[k] })), v), poser: e => poser(c.cle, Number(e.target.value)), avantTxt: (R.unites || {})[c.valeur] || c.valeur, note: 'les prix se lisent par ' + par }); }
    if (c.type === 'valeurs') { const vs = (c.valeurs || []).slice(); if (v && vs.indexOf(v) < 0) { vs.push(v); } return Object.assign(b, { type: 'choix', options: opts(vs.map(x => ({ id: x, nom: x })), v), poser: e => poser(c.cle, e.target.value), avantTxt: c.valeur }); }
    if (c.type === 'tva') { return Object.assign(b, { type: 'choix', options: opts((R.tva || []).map(x => ({ id: String(x), nom: nf(x, 0) + ' %' })), v == null ? '' : String(Number(v))), poser: e => poser(c.cle, Number(e.target.value)), avantTxt: c.valeur == null ? '—' : nf(Number(c.valeur), 0) + ' %' }); }
    if (c.type === 'nombre') { return Object.assign(b, { type: 'saisie', val: v == null ? '' : String(Number(v)).replace('.', ','), poser: e => poser(c.cle, e.target.value), avantTxt: c.valeur == null ? '—' : nf(Number(c.valeur), 2) }); }
    return Object.assign(b, { type: 'saisie', large: true, val: v == null ? '' : String(v), poser: e => poser(c.cle, e.target.value), avantTxt: c.valeur });
  });
  const oui = (d.champs || []).filter(c => c.type === 'oui').map(c => {
    const v = val(c.cle); const on = v === true || v === 1 || v === '1';
    return { lib: c.lib, on, modifiable: c.modifiable, change: change(c.cle), basculer: c.modifiable ? () => poser(c.cle, !on) : null };
  });
  const cA = champ('allergens');
  const allSel = val('allergens') || [];
  // La liste du panel (/allergens) ; illisible, les 14 allergènes UE sous les codes que le panel emploie (mesurés sur /shops/{id}/materials).
  const UE = ['cereals_gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soybeans', 'milk', 'nuts', 'celery', 'mustard', 'sesame_seeds', 'sulphites', 'lupin', 'molluscs'];
  const refAll = (R.allergenes || []).length ? R.allergenes : UE.concat(allSel.filter(c => UE.indexOf(c) < 0));
  const allergenes = cA.modifiable ? refAll.map(code => ({ code, nom: ALL[code] || code, on: allSel.indexOf(code) >= 0,
    basculer: () => { const cur = (val('allergens') || []).slice(); const i = cur.indexOf(code); if (i >= 0) { cur.splice(i, 1); } else { cur.push(code); } cur.sort(); poser('allergens', cur); } })) : null;
  const L = d.ligne || null;
  const uniteChange = change('id_unit');
  // Les prix (par unité de base au panel ; à l'écran par kg, l ou pièce).
  const pGen = champ('reference_unit_price_net'), pCons = champ('suggested_base_unit_price_net');
  const genBase = (() => { const v = val('reference_unit_price_net'); return v == null || v === '' ? null : Number(v); })();
  const ecart = p => p == null || genBase == null || !(genBase > 0) ? null : 100 * (p - genBase) / genBase;
  const tonE = e => e == null ? 'mu' : (Math.abs(e) <= 3 ? 'ok' : (Math.abs(e) <= 10 ? 'att' : 'ko'));
  const fmtE = e => e == null ? '—' : (e > 0 ? '+' : (e < 0 ? '−' : '')) + nf(Math.abs(e), 0) + ' %';
  const prixLigne = (c, nom, sous) => ({ nom, sous, modifiable: c.modifiable, change: change(c.cle), avant: c.valeur == null ? '—' : pxU(Number(c.valeur) * fac, par),
    val: val(c.cle) == null || val(c.cle) === '' ? '' : enSaisie(Number(val(c.cle)) * fac, par), id: 'vx-' + c.cle,
    poser: c.modifiable ? e => { const n = num(e.target.value); poser(c.cle, n == null ? '' : (isNaN(n) ? e.target.value : n / fac)); } : null });
  const prendre = base => () => poser('reference_unit_price_net', Number(base.toPrecision(10)));
  const fourn = L ? (L.options || []).filter(o => o.type === 'fournisseur').map(o => {
    const v = Object.values(o.prixMagasins || {}).find(x => x > 0);
    const pp = Object.values(o.parPieceMagasins || {}).find(x => x > 0);
    const c = o.colis;
    return { nom: o.nom, sous: (o.centrale ? 'centrale' : 'libre'), prix: v ? pxU(v * fac, par) : (pp ? pxU(pp, 'pce') + ' / pce' : 'pas de prix'), e: v ? fmtE(ecart(v)) : '', ton: v ? tonE(ecart(v)) : 'mu',
      etat: c ? 'colis de ' + nf(c.taille || 1, 0) + ' ' + (c.unite || '') + ' à ' + nf(c.prix, 2) + ' € HT' + (o.depuis ? ' · en vigueur depuis le ' + o.depuis.slice(8, 10) + '/' + o.depuis.slice(5, 7) : '') + (o.conversion ? ' · colis en pièces : l’unité de la matière ne se compare pas' : '') : (o.listeLue ? 'liste de prix lue : aucun prix en vigueur' : 'liste de prix non lue'),
      prendre: v && pGen.modifiable ? prendre(v) : null };
  }) : [];
  const mags = L ? (L.magasins || []).map(m => {
    const saisi = Object.prototype.hasOwnProperty.call(E.magasins, m.id) ? E.magasins[m.id] : null;
    const nv = saisi == null || saisi === '' ? null : num(saisi);
    const apres = nv != null && !isNaN(nv) && nv > 0 ? nv / fac : null;
    return { id: m.id, nom: m.nom, prix: m.prix == null ? null : pxU(m.prix * fac, par), sansPrix: m.prix == null, lu: m.lu,
      e: m.prix == null ? 'sans prix' : fmtE(ecart(m.prix)), ton: m.prix == null ? 'ko' : tonE(ecart(m.prix)), e2: apres != null ? fmtE(ecart(apres)) : null, ton2: apres != null ? tonE(ecart(apres)) : null,
      val: saisi == null ? '' : saisi, change: apres != null && (m.prix == null || Math.abs(apres - m.prix) > 1e-9), inp: 'vx-mag-' + m.id,
      poser: e => poserMag(m.id, e.target.value), prendre: m.prix != null && pGen.modifiable ? prendre(m.prix) : null };
  }) : [];
  const auGeneral = genBase > 0 && mags.length ? () => { const o = {}; mags.forEach(m => { o[m.id] = enSaisie(genBase * fac, par); }); maj({ magasins: Object.assign({}, (app.state.vxEd || {}).magasins, o) }); } : null;
  // La synthèse : ce qui cloche entre le prix général, les fournisseurs et les magasins.
  const chiffres = mags.filter(m => !m.sansPrix);
  const sans = mags.filter(m => m.sansPrix && m.lu).map(m => m.nom);
  const ecartsMax = L ? (L.magasins || []).filter(m => m.prix != null).map(m => ecart(m.prix)).filter(e => e != null) : [];
  const synthese = genBase == null || !(genBase > 0)
    ? 'Pas de prix général au panel' + (fourn.some(f => f.prendre) ? ' : un fournisseur lié a une liste de prix en vigueur, « Prendre comme prix général » la recopie.' : (chiffres.length ? ' : un magasin peut servir de référence (« Prendre comme prix général »).' : ', ni de magasin qui la chiffre : un prix à saisir.'))
    : 'Prix général ' + pxU(genBase * fac, par) + ' € / ' + par + (ecartsMax.length ? ' ; les magasins qui la chiffrent s’en écartent de ' + fmtE(Math.min(...ecartsMax)) + (ecartsMax.length > 1 ? ' à ' + fmtE(Math.max(...ecartsMax)) : '') : '') + (sans.length ? ' ; sans prix : ' + sans.join(', ') + ' (« Tous au prix général » les remplit)' : '') + '.';
  // L'effet sur les recettes : le coût de la pièce dans les magasins dont le prix change.
  const effet = L ? [].concat(...(L.recettes || []).slice(0, 12).map(r => mags.filter(m => m.change).map(m => {
    const avant = (r.cout || {})[m.id]; const ancien = ((L.magasins || []).find(x => x.id === m.id) || {}).prix || 0; const nv = num(m.val) / fac;
    return { nom: r.nom, magasin: m.nom, q: nf(r.q, r.q < 1 ? 3 : 1) + ' ' + (d.unite || ''), avant: avant == null ? '—' : nf(avant, 2) + ' €', apres: avant == null ? '+' + nf(r.q * (nv - ancien), 3) + ' €' : nf(avant + r.q * (nv - ancien), 2) + ' €' };
  }))).slice(0, 12) : [];
  // Ce qui partira.
  const champsEnvoi = {}; Object.keys(E.champs).forEach(k => { if (change(k)) { champsEnvoi[k] = E.champs[k]; } });
  // Les nombres saisis (perte) partent en nombres ; un texte qui n'en est pas un reste NaN et bloque l'envoi.
  Object.keys(champsEnvoi).forEach(k => { if ((champ(k) || {}).type === 'nombre') { const n = num(champsEnvoi[k]); champsEnvoi[k] = n == null ? '' : n; } });
  const magEnvoi = {}; mags.forEach(m => { if (m.change) { magEnvoi[m.id] = num(m.val) / fac; } });
  const invalide = Object.keys(champsEnvoi).some(k => { const t = (champ(k) || {}).type; return (t === 'nombre' || t === 'prix') && champsEnvoi[k] !== '' && champsEnvoi[k] != null && (typeof champsEnvoi[k] !== 'number' || isNaN(champsEnvoi[k]) || champsEnvoi[k] < 0); })
    || mags.some(m => m.val !== '' && !(num(m.val) > 0));
  const n = Object.keys(champsEnvoi).length + Object.keys(magEnvoi).length;
  const corps = { mid: d.mid, champs: champsEnvoi, magasins: magEnvoi };
  const prev = S.vxPrev, res = S.vxRes;
  const pret = prev && prev.d && prev.d.apercu;
  return {
    fermer, nom: d.nom, mid: d.mid, sous: [L ? L.cat : '', L ? (L.source === 'CENTRAL' ? 'centrale' : 'libre') : '', 'matière ' + d.mid, L ? L.nRecettes + ' recette' + (L.nRecettes > 1 ? 's' : '') + ', ' + nf(L.ca, 0) + ' € de CA sur 30 jours' : 'dans aucune recette vendue'].filter(Boolean).join(' · '),
    liens: Object.keys(d.liens || {}).filter(k => k === 'matiere').map(k => ({ txt: 'Matière ' + d.mid, url: d.liens[k] })),
    params, oui, allergenes, recettes: L ? (L.recettes || []).slice(0, 10).map(r => r.nom).concat(L.nRecettes > 10 ? ['+ ' + (L.nRecettes - 10) + ' autres'] : []) : [],
    alerteUnite: uniteChange ? 'L’unité change : les quantités des recettes qui l’emploient (' + (L ? L.nRecettes : 0) + ') sont écrites dans l’ancienne unité. Après l’enregistrement, reposez-les dans « Recettes à corriger » (volet de la recette, mode Corriger).' : null,
    par, general: prixLigne(pGen, 'Prix général', 'prix de référence du panel, pour tout le réseau'), conseille: Object.assign(prixLigne(pCons, 'Prix conseillé du panel', ''), { e: fmtE(ecart(pCons.valeur == null ? null : Number(val('suggested_base_unit_price_net')))), ton: tonE(ecart(val('suggested_base_unit_price_net') == null || val('suggested_base_unit_price_net') === '' ? null : Number(val('suggested_base_unit_price_net')))) }),
    fourn, mags, auGeneral, synthese, effet,
    n, invalide, annulerTout: () => app.setState({ vxEd: { champs: {}, magasins: {} }, vxPrev: null, vxRes: null }),
    verifier: n && !invalide && !(prev && prev.enCours) ? () => {
      app.setState({ vxPrev: { enCours: true } });
      write(app.source || 'api', 'POST', ROUTE + 'matiere', Object.assign({ apercu: true }, corps)).then(r => app.setState({ vxPrev: { d: r } }));
    } : null,
    apercu: pret ? (prev.d.ecritures || []).map(e => ({ route: e.methode + ' ' + e.route, resume: e.resume || '', corps: JSON.stringify(e.corps), annulable: e.annulable })) : null,
    refus: prev && prev.d && !prev.d.apercu ? (prev.d.error || 'Le cockpit n’a pas pu préparer ces changements.') : null,
    enCours: !!(prev && prev.enCours) || !!(res && res.enCours),
    enregistrer: pret ? () => {
      app.setState({ vxRes: { enCours: true } });
      write(app.source || 'api', 'POST', ROUTE + 'matiere', corps).then(r => {
        app.setState({ vxRes: { d: r }, vxPrev: null, vrRecentes: (r.ecritures || []).concat(app.state.vrRecentes || []).slice(0, 20), vrReg: null, vmData: null });
        if (r.ok) { app.setState({ vxEd: { champs: {}, magasins: {} } }); readOne(ROUTE + 'matiere?mid=' + encodeURIComponent(d.mid)).then(d2 => { if (d2 && ((app.state.vxFiche || {}).mid) === d.mid) { app.setState({ vxFiche: { mid: d.mid, d: d2 } }); } }); }
      });
    } : null,
    resultat: res && res.d ? { ok: !!res.d.ok, lignes: (res.d.ecritures || []).map(e => (e.resultat === 'ok' ? '✓ ' : '✕ ') + e.resume + ' — ' + e.resultatNom + (e.message ? ' : ' + e.message : '')).concat(res.d.error ? ['✕ ' + res.d.error] : []) } : null,
  };
}
