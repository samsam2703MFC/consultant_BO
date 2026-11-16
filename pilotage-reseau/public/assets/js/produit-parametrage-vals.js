/* Cockpit CEO — Produits › Paramétrage (14/11/2026, maquette docs/maquettes/parametrage-produits, « tout sur une page »).
 * La liste des produits à gauche ; à droite la fiche d'un produit telle que le panel la rend : tous ses champs, ses gammes
 * (saisons), son prix dans chaque magasin, les autres données lues, et les liaisons de l'API mesurées. Ce qui change se
 * vérifie (l'envoi exact, formats des gammes et des prix réglables), s'écrit au panel avec le compte consultant, se relit
 * et se note au registre des corrections. Serveur : src/produit_parametrage.php. Gabarit : produit-parametrage-tpl.js. */
import { readOne, write } from './api.js';

const nf = (n, d) => n == null || isNaN(n) ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/[  ]/g, ' ');
const eur = (v, d = 2) => v == null ? '—' : nf(v, d) + ' €';
const court = n => String(n || '').replace(/^.* - /, '');
const vide = v => v === null || v === undefined || v === '';
const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const FAMILLES = { definit: 'Ce qui le définit', fabrique: 'Ce qui le fabrique', range: 'Où il se range, s’emballe, s’étiquette', vend: 'Ce qui le vend', mesure: 'Ce qu’on en mesure' };
const REFS = { categorie: 'categories', secteur: 'secteurs', stockage: 'stockages', position: 'positions', emballage: 'emballages' };

function chargerListe(app, frais){
  const L = app.state.ppListe;
  if (!frais && L && (L.d || L.chargement)) { return; }
  app.setState({ ppListe: { chargement: true, d: L ? L.d : null } });
  readOne('/produits/parametrage/liste' + (frais ? '?rafraichir=1' : '')).then(d => app.setState({ ppListe: { chargement: false, d: d || { error: 'injoignable' } } }));
}
function chargerFiche(app, pid, mesurer){
  const F = app.state.ppFiche;
  if (!mesurer && F && F.pid === pid && (F.d || F.chargement)) { return; }
  app.setState({ ppFiche: { pid, chargement: true, d: F && F.pid === pid ? F.d : null }, ...(F && F.pid === pid ? {} : { ppMod: null, ppPrev: null, ppRes: null, ppFormats: null }) });
  readOne('/produits/parametrage?pid=' + pid + (mesurer ? '&mesurer=1' : '')).then(d => { if ((app.state.ppFiche || {}).pid === pid) { app.setState({ ppFiche: { pid, chargement: false, d: d || { error: 'injoignable' } } }); } });
}

/** La valeur affichée d'un champ : la saisie si elle existe, sinon la valeur lue. */
const valeurDe = (c, mod) => mod && Object.prototype.hasOwnProperty.call(mod.champs || {}, c.cle) ? mod.champs[c.cle] : c.valeur;
const egal = (a, b, type) => {
  if (type === 'oui') { return !!Number(a) === !!Number(b); }
  if (vide(a) && vide(b)) { return true; }
  if (['nombre', 'prix', 'tva', 'categorie', 'secteur', 'stockage', 'position', 'emballage', 'recette'].includes(type)) {
    const x = Number(String(a).replace(',', '.')), y = Number(String(b).replace(',', '.'));
    return !vide(a) && !vide(b) && isFinite(x) && isFinite(y) && Math.abs(x - y) < 1e-9;
  }
  return String(a == null ? '' : a) === String(b == null ? '' : b);
};
const jour = md => { const m = Math.floor(md / 100), j = md % 100; return (Date.UTC(2026, m - 1, j) - Date.UTC(2026, 0, 1)) / 864e5; };
const dm = md => String(md % 100).padStart(2, '0') + '/' + String(Math.floor(md / 100)).padStart(2, '0');

export function valsParamProduits(app, c){
  const S = app.state;
  chargerListe(app, false);
  const LL = S.ppListe || {};
  const L = LL.d && !LL.d.error && !LL.d.indispo ? LL.d : null;
  const v = { chargement: !!LL.chargement && !L, erreur: LL.d && LL.d.error ? 'Le serveur du cockpit n’a pas répondu : réessayez dans un instant.' : (LL.d && LL.d.indispo ? LL.d.motif : null) };
  c.pp = v;
  if (!L) { return; }
  const gammes = L.gammes || [];
  const nomG = {}; gammes.forEach(g => { nomG[g.id] = g.nom; });
  // La liste
  const q = String(S.ppQ || '').trim().toLowerCase(), fG = S.ppGamme || '', fC = S.ppCat || '', fx = S.ppFiltre || '';
  const tous = L.produits || [];
  const passe = p => (!q || p.nom.toLowerCase().indexOf(q) >= 0 || String(p.pid) === q) && (!fG || (p.gammes || []).includes(Number(fG))) && (!fC || p.cat === fC)
    && (!fx || (fx === 'webshop' && p.webshop) || (fx === 'inactifs' && !p.actif) || (fx === 'sansGamme' && !(p.gammes || []).length) || (fx === 'sansRecette' && !p.recette) || (fx === 'absents' && !(p.magasins || []).length));
  const P = tous.filter(passe);
  const cats = [...new Set(tous.map(p => p.cat).filter(Boolean))].sort();
  const n = k => tous.filter(p => k(p)).length;
  const pid = S.ppPid && tous.some(p => p.pid === S.ppPid) ? S.ppPid : (P[0] || tous[0] || {}).pid;
  const LIM = S.ppTout ? P.length : Math.min(P.length, 150);
  v.liste = {
    q: S.ppQ || '', poserQ: e => app.setState({ ppQ: e.target.value, ppTout: false }),
    gammes: [{ id: '', nom: 'Toutes les gammes (' + gammes.length + ')' }].concat(gammes.map(g => ({ id: String(g.id), nom: g.nom + (g.n != null ? ' · ' + g.n : '') }))).map(o => Object.assign(o, { on: o.id === String(fG) })),
    poserGamme: e => app.setState({ ppGamme: e.target.value }),
    cats: [{ id: '', nom: 'Toutes les catégories (' + cats.length + ')' }].concat(cats.map(x => ({ id: x, nom: x }))).map(o => Object.assign(o, { on: o.id === fC })),
    poserCat: e => app.setState({ ppCat: e.target.value }),
    chips: [['', 'Tous', tous.length], ['webshop', 'Webshop', n(p => p.webshop)], ['sansGamme', 'Sans gamme', n(p => !(p.gammes || []).length)], ['sansRecette', 'Sans recette', n(p => !p.recette)],
      ['inactifs', 'Inactifs', n(p => !p.actif)], ['absents', 'Dans aucun magasin', n(p => !(p.magasins || []).length)]]
      .filter(x => x[0] === '' || x[2] > 0).map(([k, nom, nb]) => ({ nom, n: nb, on: fx === k, choisir: () => app.setState({ ppFiltre: fx === k ? '' : k }) })),
    n: P.length, total: tous.length,
    items: P.slice(0, LIM).map(p => ({ nom: p.nom, sous: (p.cat || '—') + ' · ' + (p.magasins || []).length + '/' + (L.magasins || []).length + ' magasins' + ((p.gammes || []).length ? ' · ' + (p.gammes || []).length + ' gamme' + ((p.gammes || []).length > 1 ? 's' : '') : ''),
      ton: !p.actif ? 'mu' : (!p.recette ? 'ko' : (!(p.gammes || []).length ? 'att' : 'ok')), on: p.pid === pid,
      choisir: () => app.setState({ ppPid: p.pid }) })),
    plus: P.length > LIM ? { n: P.length - LIM, voir: () => app.setState({ ppTout: true }) } : null,
    source: L.source || '', relire: () => chargerListe(app, true), relecture: !!LL.chargement,
  };
  if (!pid) { return; }
  chargerFiche(app, pid, false);
  const FF = S.ppFiche || {};
  const D = FF.pid === pid && FF.d && FF.d.ok ? FF.d : null;
  v.fiche = { chargement: !!FF.chargement && !D, erreur: FF.d && !FF.d.ok && !FF.chargement ? (FF.d.motif || FF.d.error || 'La fiche n’a pas pu être lue.') : null };
  if (!D) { return; }
  const mod = S.ppMod && S.ppMod.pid === pid ? S.ppMod : { pid, champs: {}, gammes: null, prix: {} };
  const majMod = patch => app.setState({ ppMod: Object.assign({ pid, champs: {}, gammes: null, prix: {} }, app.state.ppMod && app.state.ppMod.pid === pid ? app.state.ppMod : {}, patch), ppPrev: null, ppRes: null });
  const poserChamp = (k, val) => majMod({ champs: Object.assign({}, ((app.state.ppMod || {}).pid === pid ? app.state.ppMod.champs : {}) || {}, { [k]: val }) });
  const refs = D.refs || {};
  // Les champs, par section
  const changes = [];
  const champ = ch => {
    const val = valeurDe(ch, mod);
    const change = ch.modifiable && !egal(val, ch.valeur, ch.type);
    if (change) { changes.push(ch.lib); }
    const o = { cle: ch.cle, lib: ch.lib, type: ch.type, aide: ch.aide, modifiable: ch.modifiable, change, id: 'pp-' + ch.cle };
    if (ch.type === 'oui') { o.on = !!Number(val); o.basculer = ch.modifiable ? () => poserChamp(ch.cle, o.on ? 0 : 1) : null; return o; }
    if (REFS[ch.type] || ch.type === 'tva' || ch.type === 'valeurs') {
      let opts;
      if (ch.type === 'tva') { opts = (refs.tva || []).map(t => ({ id: String(t), nom: t + ' %' })); }
      else if (ch.type === 'valeurs') { opts = (ch.valeurs || []).map(x => ({ id: x, nom: x === '' ? '—' : x })); }
      else { const R = refs[REFS[ch.type]] || {}; opts = Object.keys(R).map(id => ({ id, nom: R[id].nom + (R[id].price_coefficient ? ' · ×' + nf(Number(R[id].price_coefficient), 2) : '') })).sort((a, b) => a.nom.localeCompare(b.nom, 'fr')); }
      if (ch.type !== 'categorie' && ch.type !== 'tva' && !opts.some(x => x.id === '')) { opts.unshift({ id: '', nom: '—' }); }
      if (!vide(val) && !opts.some(x => x.id === String(val))) { opts.push({ id: String(val), nom: 'n° ' + val }); }
      o.select = true; o.options = opts.map(x => Object.assign(x, { on: x.id === String(vide(val) ? '' : val) }));
      o.poser = e => poserChamp(ch.cle, e.target.value);
      if (ch.type === 'categorie') { const R = (refs.categories || {})[String(val)]; o.aide = R ? [R.price_coefficient ? 'coefficient ×' + nf(Number(R.price_coefficient), 2) : '', R.production_area_name || ''].filter(Boolean).join(' · ') : ''; }
      if (ch.type === 'stockage') { const R = (refs.stockages || {})[String(val)]; o.aide = R && R.description ? String(R.description).trim() : ''; }
      return o;
    }
    o.val = vide(val) ? '' : String(val);
    o.poser = e => poserChamp(ch.cle, e.target.value);
    if (ch.cle === 'shelf_life_minutes' && !vide(val)) { const m = Number(String(val).replace(',', '.')); if (m > 0) { o.aide = m >= 1440 ? nf(m / 1440, m % 1440 ? 1 : 0) + ' jour' + (m >= 2880 ? 's' : '') : nf(m / 60, 1) + ' h'; } }
    if (ch.type === 'recette' && D.recette) { o.aide = (D.recette.nom || 'recette ' + D.recette.rid) + (D.recette.lignes ? ' · ' + D.recette.lignes + ' ligne' + (D.recette.lignes > 1 ? 's' : '') : '') + (D.recette.cout != null ? ' · ' + eur(D.recette.cout) + ' la pièce' : ''); }
    return o;
  };
  const champs = D.champs || [];
  v.fiche.sections = Object.keys(D.sections || {}).map(k => ({ cle: k, titre: D.sections[k], route: 'PATCH /products/{id}', champs: champs.filter(ch => ch.section === k).map(champ) }));
  const remplis = champs.filter(ch => ch.modifiable && !vide(ch.valeur)).length;
  // Les gammes
  const gP = mod.gammes || D.gammesProduit || [];
  const gChange = mod.gammes != null && JSON.stringify([...mod.gammes].sort()) !== JSON.stringify([...(D.gammesProduit || [])].sort());
  if (gChange) { changes.push('Gammes'); }
  const auj = (() => { const t = new Date(); return jour((t.getMonth() + 1) * 100 + t.getDate()); })();
  const seg = (a, b) => ({ g: (100 * a / 365).toFixed(2), l: Math.max(0.6, 100 * (b - a) / 365).toFixed(2) });
  v.fiche.gammes = {
    mois: MOIS, auj: (100 * auj / 365).toFixed(2), change: gChange, route: (D.formats.gammes || {}).methode + ' ' + (D.formats.gammes || {}).route,
    lignes: (D.gammes || []).map(g => {
      const a = jour(g.du), b = jour(g.au) + 1;
      const on = gP.includes(g.id);
      const toutLan = g.du && g.au && ((b > a && b - a >= 360) || (b <= a && (365 - a) + b >= 360));
      return { nom: g.nom, on, actif: g.active, barres: b > a ? [seg(a, b)] : [seg(a, 365), seg(0, b)],
        dates: dm(g.du) + ' → ' + dm(g.au) + (g.recurrente ? ' · chaque année' : ''), sous: (g.n != null ? g.n + ' produit' + (g.n > 1 ? 's' : '') : 'produits non lus') + (g.webshop ? ' · webshop' : '') + (g.active ? '' : ' · inactive'),
        alerte: toutLan && !/standard|bureau/i.test(g.nom) ? 'réglée toute l’année' : (g.du === g.au ? 'un seul jour' : ''),
        basculer: () => { const cur = (app.state.ppMod && app.state.ppMod.pid === pid && app.state.ppMod.gammes) || D.gammesProduit || []; majMod({ gammes: on ? cur.filter(x => x !== g.id) : cur.concat([g.id]) }); } };
    }),
    resume: gP.length ? gP.map(id => (D.gammes.find(g => g.id === id) || {}).nom || id).join(', ') : 'aucune gamme : hors de toute période de vente',
  };
  // Les magasins
  v.fiche.magasins = (D.magasins || []).map(m => {
    const saisi = (mod.prix || {})[m.id];
    const val = saisi != null ? saisi : (m.ttc != null ? Number(m.ttc).toFixed(2).replace('.', ',') : '');
    const change = saisi != null && !egal(saisi, m.ttc, 'prix');
    if (change) { changes.push('Prix ' + court(m.nom)); }
    return { nom: court(m.nom), present: m.present, val, change, id: 'pp-prix-' + m.id,
      poser: e => majMod({ prix: Object.assign({}, ((app.state.ppMod || {}).pid === pid ? app.state.ppMod.prix : {}) || {}, { [m.id]: e.target.value }) }),
      ht: eur(m.ht), cout: eur(m.cout), coef: m.coef == null ? '—' : '×' + nf(m.coef, 2), food: m.food == null ? '—' : nf(m.food, 1) + ' %', foodTon: m.food == null ? '' : (m.food > 35 ? 'att' : 'ok'),
      max: eur(m.max), stock: m.stock == null ? '—' : nf(m.stock, m.stock % 1 ? 2 : 0) };
  });
  // Les autres données lues (sans écriture)
  const brut = Object.assign({}, D.brut || {}, D.lecture || {});
  v.fiche.autres = Object.keys(brut).filter(k => k !== 'category' && k !== 'required_competences').map(k => ({ cle: k, val: typeof brut[k] === 'object' && brut[k] !== null ? JSON.stringify(brut[k]) : (vide(brut[k]) ? '—' : String(brut[k])) }));
  v.fiche.competences = (D.competences || []).map(x => typeof x === 'object' ? (x.name || x.nom || JSON.stringify(x)) : String(x));
  v.fiche.groupes = ((D.categorie || {}).groups || []).map(g => g.name || g.base_name).filter(Boolean);
  // Les liaisons
  v.fiche.liaisons = Object.keys(FAMILLES).map(f => ({ titre: FAMILLES[f], cartes: (D.liaisons || []).filter(l => l.famille === f).map(l => ({ nom: l.nom, routes: l.routes, etat: l.etat,
    txt: l.etat === 'fiche' ? 'lue produit par produit' : (l.etat === 'lisible' ? (l.n != null ? l.n + ' élément' + (l.n > 1 ? 's' : '') : 'lisible') : 'refusée' + (l.code ? ' (HTTP ' + l.code + ')' : '') + ' au compte consultant') })) }));
  v.fiche.mesurer = () => chargerFiche(app, pid, true);
  // L'en-tête
  v.fiche.nom = D.nom; v.fiche.pid = pid; v.fiche.lien = D.lienPanel; v.fiche.score = remplis + ' / ' + champs.filter(ch => ch.modifiable).length;
  v.fiche.sous = [(champs.find(ch => ch.cle === 'id_category') || {}).valeur ? ((refs.categories || {})[String(champs.find(ch => ch.cle === 'id_category').valeur)] || {}).nom : '', D.recette ? 'recette ' + D.recette.rid : 'sans recette',
    (D.magasins || []).filter(m => m.present).length + ' magasin(s) sur ' + (D.magasins || []).length].filter(Boolean).join(' · ');
  v.fiche.actif = !!Number(valeurDe(champs.find(ch => ch.cle === 'is_active') || {}, mod));
  v.fiche.groupesTxt = v.fiche.groupes.join(', ');
  v.fiche.source = D.source; v.fiche.lu = D.lu ? D.lu.slice(11, 16) : '';
  // La barre : vérifier, puis écrire.
  const corps = () => {
    const M = app.state.ppMod && app.state.ppMod.pid === pid ? app.state.ppMod : mod;
    const ch = {}; champs.forEach(x => { if (x.modifiable && Object.prototype.hasOwnProperty.call(M.champs || {}, x.cle) && !egal(M.champs[x.cle], x.valeur, x.type)) { ch[x.cle] = M.champs[x.cle]; } });
    const px = {}; (D.magasins || []).forEach(m => { const s = (M.prix || {})[m.id]; if (s != null && !egal(s, m.ttc, 'prix')) { px[m.id] = s; } });
    const o = { pid, champs: ch, prix: px, garder: !!app.state.ppGarder };
    if (M.gammes != null) { o.gammes = M.gammes; }
    const F = app.state.ppFormats; if (F) { o.formats = {}; ['gammes', 'prix'].forEach(k => { if (F[k]) { o.formats[k] = { methode: F[k].methode, route: F[k].route, corps: Object.fromEntries(F[k].champs.filter(x => String(x[0]).trim()).map(x => [String(x[0]).trim(), x[1]])) }; } }); }
    return o;
  };
  const prev = S.ppPrev, res = S.ppRes;
  const fmt = (S.ppFormats || {});
  const formatVue = k => {
    const base = fmt[k] || { methode: D.formats[k].methode, route: D.formats[k].route, champs: Object.entries(D.formats[k].corps).map(([a, b]) => [a, String(b)]) };
    const maj = patch => app.setState({ ppFormats: Object.assign({}, app.state.ppFormats, { [k]: Object.assign({}, base, patch) }), ppPrev: null });
    return { titre: k === 'gammes' ? 'Format des gammes' : 'Format des prix', methode: base.methode, route: base.route, idR: 'pp-fr-' + k,
      methodes: ['PATCH', 'POST', 'PUT'].map(m => ({ m, on: m === base.methode })), poserMethode: e => maj({ methode: e.target.value }), poserRoute: e => maj({ route: e.target.value }),
      champs: base.champs.map(([a, b], i) => ({ k: a, v: b, idK: 'pp-f' + k + '-k' + i, idV: 'pp-f' + k + '-v' + i,
        poserK: e => { const cs = base.champs.map(x => x.slice()); cs[i][0] = e.target.value; maj({ champs: cs }); },
        poserV: e => { const cs = base.champs.map(x => x.slice()); cs[i][1] = e.target.value; maj({ champs: cs }); },
        retirer: () => maj({ champs: base.champs.filter((x, j) => j !== i) }) })),
      ajouter: () => maj({ champs: base.champs.concat([['', '']]) }),
      aide: k === 'gammes' ? '{gammes} : la liste des gammes cochées · {pid} · avec la route /product-availability-periods/{gid}/products : {gid}, {produits}' : '{prix} : le prix TTC saisi · {sid} · {pid}' };
  };
  const nCh = changes.length;
  v.barre = nCh || prev || res ? {
    n: nCh, quoi: changes.slice(0, 6).join(', ') + (changes.length > 6 ? '…' : ''),
    annuler: () => app.setState({ ppMod: null, ppPrev: null, ppRes: null }),
    verifier: nCh && !(prev && prev.enCours) ? () => { app.setState({ ppPrev: { enCours: true }, ppRes: null }); write(app.source || 'api', 'POST', '/produits/parametrage', Object.assign({ apercu: true }, corps())).then(r => app.setState({ ppPrev: { d: r } })); } : null,
    apercu: prev && prev.d && prev.d.apercu ? prev.d.ecritures.map(e => ({ resume: e.resume, txt: e.methode + ' ' + e.route + '\n' + JSON.stringify(e.corps, null, 2), quoi: e.quoi })) : null,
    refus: prev && prev.d && !prev.d.apercu ? (prev.d.error || 'refusé') : null,
    formats: prev && prev.d && prev.d.apercu ? ['gammes', 'prix'].filter(k => prev.d.ecritures.some(e => e.quoi === k)).map(formatVue) : [],
    garder: !!S.ppGarder, basculerGarder: () => app.setState({ ppGarder: !app.state.ppGarder }),
    enCours: !!(prev && prev.enCours) || !!(res && res.enCours),
    ecrire: prev && prev.d && prev.d.apercu ? () => {
      app.setState({ ppRes: { enCours: true } });
      write(app.source || 'api', 'POST', '/produits/parametrage', corps()).then(r => {
        const maj = { ppRes: { d: r }, ppPrev: null, vrReg: null, vrRecentes: ((r && r.ecritures) || []).concat(app.state.vrRecentes || []).slice(0, 20) };
        // `write` rend ok: true pour tout HTTP 200 : la réussite se lit écriture par écriture.
        const tout = r && (r.ecritures || []).length > 0 && r.ecritures.every(e => e.resultat === 'ok');
        if (tout) { maj.ppMod = null; }
        app.setState(maj);
        if (r && (r.ecritures || []).some(e => e.resultat === 'ok')) { chargerFiche(app, pid, true); app.setState({ ppListe: null }); }
      });
    } : null,
    resultat: res && res.d ? ((res.d.ecritures || []).map(e => ({ ok: e.resultat === 'ok', txt: e.resume + ' — ' + e.resultatNom + (e.message ? ' : ' + e.message : ''), http: e.httpPanel || e.http, reponse: e.reponse || '' }))
      .concat(res.d.error ? [{ ok: false, txt: res.d.error, reponse: '' }] : [])) : null,
    fermer: () => app.setState({ ppRes: null }),
  } : null;
}
