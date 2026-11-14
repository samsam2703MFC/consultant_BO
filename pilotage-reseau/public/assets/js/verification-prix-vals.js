/* Cockpit CEO — Recettes à corriger › Matières premières (14/11/2026, maquette docs/maquettes/matieres-reference).
 * GET /verification/recettes/matieres rend les matières des recettes vendues, leur prix dans chaque magasin et les prix
 * de référence (listes de prix des fournisseurs en vigueur, prix du panel, médiane des magasins). Un prix se choisit dans
 * la liste déroulante de la matière, s'applique à un magasin, aux magasins sans prix ou à tous, se vérifie puis s'écrit
 * au panel par le geste `prix` des corrections (relu, au registre, « Annuler »). Gabarit : verification-prix-tpl.js. */
import { readOne, write } from './api.js';
import { ouvrirFiche } from './verification-fichemat-vals.js';
import { valsTableau } from './verification-tableau-vals.js';

const nf = (n, d) => n == null || isNaN(n) ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/[  ]/g, ' ');
const num = s => { const t = String(s == null ? '' : s).trim().replace(/\s/g, '').replace(',', '.'); if (t === '') { return null; } const n = Number(t); return isFinite(n) ? n : NaN; };
/** Un prix lisible dans l'unité d'affichage : 2,56 € (kg), 9,622 € (pièce), 0,0528 € (petite pièce). */
const pxU = (v, par) => v == null ? '—' : nf(v, par === 'pce' || par === 'unité' ? (v < 1 ? 4 : (v < 10 ? 3 : 2)) : (v < 0.1 ? 4 : 2)) + ' €';
const ROUTE = '/verification/recettes/';

function charger(app, frais){
  const M = app.state.vmData;
  if (!frais && M && (M.d || M.chargement)) { return; }
  app.setState({ vmData: { chargement: true, d: M ? M.d : null } });
  readOne(ROUTE + 'matieres' + (frais ? '?rafraichir=1' : '')).then(d => app.setState({ vmData: { chargement: false, d: d || { error: 'injoignable' } } }));
}

/** Le prix (par unité de base) qu'un choix donne pour un magasin ; null si le choix n'en donne pas. */
function prixChoisi(l, ch, sid){
  if (!ch || !ch.opt) { return null; }
  if (ch.opt === 'saisie') { const v = num(ch.saisie); return v > 0 ? v / l.facteur : null; }
  const o = (l.options || []).find(x => x.id === ch.opt);
  if (!o) { return null; }
  const premier = obj => { const vs = Object.values(obj || {}).filter(v => v != null && v > 0); return vs.length ? vs[0] : null; };
  if (o.conversion) {
    const poids = num(ch.poids);
    const pp = (o.parPieceMagasins || {})[sid] != null ? o.parPieceMagasins[sid] : premier(o.parPieceMagasins);
    // Le poids d'une pièce se saisit en g (ou ml) ; la matière peut se compter en g, ou en kg (ou l) : on ramène à son unité.
    const parBase = ['kg', 'l', 'kilogramme', 'litre'].indexOf(String(l.uniteBase || '').toLowerCase()) >= 0 ? poids / 1000 : poids;
    return poids > 0 && pp > 0 ? pp / parBase : null;
  }
  const p = (o.prixMagasins || {})[sid];
  return p != null && p > 0 ? p : premier(o.prixMagasins);
}

/** Les magasins visés par « Appliquer à ». */
function cibles(l, a){
  const ms = (l.magasins || []).filter(m => m.lu);
  if (!a || a === 'sansPrix') { return ms.filter(m => m.prix == null).map(m => m.id); }
  if (a === 'tous') { return ms.map(m => m.id); }
  return ms.filter(m => m.id === a).map(m => m.id);
}

/** Les écritures qu'un choix demande : une par prix distinct, avec ses magasins. */
function plan(l, ch){
  const par = {};
  cibles(l, ch.a).forEach(sid => {
    const p = prixChoisi(l, ch, sid);
    if (!(p > 0)) { return; }
    const m = (l.magasins || []).find(x => x.id === sid) || {};
    if (m.prix != null && Math.abs(m.prix - p) <= 1e-9 * Math.max(1, p)) { return; }   // déjà ce prix
    const k = p.toPrecision(10);
    (par[k] = par[k] || { geste: 'prix', mid: l.mid, matiere: l.nom, prix: Number(p.toPrecision(10)), shops: [] }).shops.push(Number(sid));
  });
  return Object.values(par);
}

const libOption = (o, l) => {
  if (o.type !== 'fournisseur') { const v = Object.values(o.prixMagasins || {}).find(x => x > 0); return o.nom + (v ? ' · ' + pxU(v * l.facteur, l.par) + ' / ' + l.par : ''); }
  if (o.conversion) { const pp = Object.values(o.parPieceMagasins || {}).find(x => x > 0); return o.nom + ' · ' + (pp ? pxU(pp, 'pce') + ' / pièce' : 'colis en pièces') + ' — poids à poser'; }
  const v = Object.values(o.prixMagasins || {}).find(x => x > 0);
  if (v) { return o.nom + ' · ' + pxU(v * l.facteur, l.par) + ' / ' + l.par; }
  return o.nom + (o.listeLue ? ' · pas de prix en vigueur' : ' · liste de prix non lue');
};
const sousOption = o => {
  if (o.type !== 'fournisseur') { return o.type === 'mediane' ? 'les autres magasins qui la chiffrent' : 'porté par le panel'; }
  const c = o.colis;
  const d = o.depuis ? ' · en vigueur depuis le ' + o.depuis.slice(8, 10) + '/' + o.depuis.slice(5, 7) : '';
  return (c ? 'colis de ' + nf(c.taille || 1, 0) + ' ' + (c.unite || '') + (c.parColis > 1 ? ' × ' + nf(c.parColis, 0) : '') + ' à ' + nf(c.prix, 2) + ' € HT' : '') + d + (o.centrale ? ' · centrale' : '');
};

export function valsMatieres(app){
  const S = app.state;
  charger(app, false);
  const M = S.vmData || {};
  const d = M.d && !M.d.error && !M.d.indispo ? M.d : null;
  const v = { chargement: !!M.chargement && !d, relecture: !!M.chargement && !!d, rafraichir: () => charger(app, true),
    erreur: M.d && M.d.error ? 'Le serveur du cockpit n’a pas répondu : réessayez dans un instant.' : (M.d && M.d.indispo ? M.d.motif : null) };
  if (!d) { return v; }
  // Deux lectures (14/11/2026) : par fournisseur ou par catégorie, avec le formulaire du prix ; ou la liste de toutes les matières.
  const aff = S.vmAff === 'liste' ? 'liste' : 'tableau';
  v.affichages = [['tableau', 'Par fournisseur ou catégorie'], ['liste', 'Toutes les matières, prix de référence au choix']].map(([id, nom]) => ({ nom, on: aff === id, choisir: () => app.setState({ vmAff: id }) }));
  v.lu = d.cache && d.cache.le ? 'calculé à ' + d.cache.le.slice(11, 16) : (d.lu ? 'calculé à ' + String(d.lu).slice(11, 16) : '');
  if (aff === 'tableau') { v.tableau = valsTableau(app, d); v.source = d.source || ''; return v; }
  const ch = S.vmChoix || {};
  const majChoix = (mid, patch) => app.setState({ vmChoix: Object.assign({}, app.state.vmChoix, { [mid]: Object.assign({ a: 'sansPrix' }, (app.state.vmChoix || {})[mid], patch) }), vmPrev: null, vmRes: null });
  const filtre = S.vmFiltre || '', q = String(S.vmQ || '').trim().toLowerCase();
  const tous = d.lignes || [];
  const passe = l => !filtre || (filtre === 'sansPrix' && l.drapeaux.sansPrix > 0) || (filtre === 'ecart' && l.drapeaux.ecart > 0) || (filtre === 'sansFournisseur' && l.drapeaux.sansFournisseur)
    || (filtre === 'unite' && l.drapeaux.unite) || (filtre === 'choisis' && ch[l.mid] && ch[l.mid].opt);
  const L = tous.filter(l => passe(l) && (!q || l.nom.toLowerCase().indexOf(q) >= 0 || (l.cat || '').toLowerCase().indexOf(q) >= 0));
  const mags = d.magasins || [];
  const LIM = S.vmTout ? L.length : Math.min(L.length, 80);
  const plans = [];
  v.lignes = L.slice(0, LIM).map(l => {
    const c = ch[l.mid] || { a: 'sansPrix' };
    const opt = (l.options || []).find(o => o.id === c.opt);
    const p = c.opt ? plan(l, c) : [];
    p.forEach(x => plans.push(Object.assign({ nom: l.nom }, x)));
    const vises = c.opt ? cibles(l, c.a) : [];
    const ouvert = S.vmOuvert === l.mid;
    // L'effet sur les recettes : le coût de la pièce, avant / après, dans le premier magasin visé.
    const sid0 = vises[0];
    const impact = ouvert && c.opt && sid0 ? (l.recettes || []).map(r => {
      const m = (l.magasins || []).find(x => x.id === sid0) || {};
      const nv = prixChoisi(l, c, sid0), avant = (r.cout || {})[sid0];
      const apres = avant != null && nv > 0 ? avant + r.q * (nv - (m.prix || 0)) : null;
      return { nom: r.nom, ca: nf(r.ca, 0) + ' €', q: nf(r.q * (l.facteur === 1000 ? 1 : 1), r.q < 1 ? 3 : 1) + ' ' + (l.uniteBase || ''), avant: avant == null ? '—' : nf(avant, 2) + ' €', apres: apres == null ? '—' : nf(apres, 2) + ' €' };
    }) : null;
    return {
      mid: l.mid, nom: l.nom, cat: l.cat, centrale: l.source === 'CENTRAL', par: l.par,
      emploi: l.nRecettes + ' recette' + (l.nRecettes > 1 ? 's' : '') + ' · ' + nf(l.ca, 0) + ' €' + (l.recettes && l.recettes[0] ? ' — ' + l.recettes.slice(0, 2).map(r => r.nom).join(', ') + (l.nRecettes > 2 ? '…' : '') : ''),
      magasins: (l.magasins || []).map(m => {
        const vise = vises.indexOf(m.id) >= 0 && p.some(x => x.shops.indexOf(Number(m.id)) >= 0);
        const nv = vise ? prixChoisi(l, c, m.id) : null;
        if (vise && nv > 0) { return { txt: pxU(nv * l.facteur, l.par), ton: 'new', sous: 'à écrire' }; }
        if (!m.lu) { return { txt: '?', ton: '', sous: 'non lu' }; }
        if (m.prix == null) { return { txt: 'sans prix', ton: 'ko' }; }
        const e = m.ecart;
        return { txt: pxU(m.prix * l.facteur, l.par), ton: e == null ? '' : (Math.abs(e) > d.seuilEcart ? 'att' : 'ok'), sous: e != null && Math.abs(e) > d.seuilEcart ? (e > 0 ? '+' : '') + nf(e, 0) + ' % / réf.' : (l.drapeaux.emballage ? 'compté en ' + l.uniteBase : '') };
      }),
      options: [{ id: '', nom: (l.options || []).length ? 'Choisir un prix de référence…' : 'Aucune référence : saisir un prix…' }]
        .concat((l.options || []).map(o => ({ id: o.id, nom: libOption(o, l), off: o.type === 'fournisseur' && !o.conversion && !Object.values(o.prixMagasins || {}).some(x => x > 0) })))
        .concat([{ id: 'saisie', nom: 'Saisir un prix…' }]).map(o => Object.assign(o, { on: (c.opt || '') === o.id })),
      sous: opt ? sousOption(opt) : (c.opt === 'saisie' ? 'prix saisi, par ' + l.par : ((l.options || []).length ? '' : 'aucune liste de prix fournisseur liée')),
      choisir: e => majChoix(l.mid, { opt: e.target.value }),
      conversion: opt && opt.conversion ? { val: c.poids || '', unite: opt.conversion, poser: e => majChoix(l.mid, { poids: e.target.value }), id: 'vm-poids-' + l.mid,
        resultat: (() => { const x = prixChoisi(l, c, vises[0] || (l.magasins[0] || {}).id); return x > 0 ? '→ ' + pxU(x * l.facteur, l.par) + ' / ' + l.par : ''; })() } : null,
      saisie: c.opt === 'saisie' ? { val: c.saisie || '', par: l.par, poser: e => majChoix(l.mid, { saisie: e.target.value }), id: 'vm-px-' + l.mid } : null,
      choisi: !!c.opt, nPlan: p.reduce((t, x) => t + x.shops.length, 0),
      appliquerA: [{ id: 'sansPrix', nom: 'Magasins sans prix' }, { id: 'tous', nom: 'Tous les magasins' }].concat(mags.map(m => ({ id: m.id, nom: m.nom.replace(/^.* - /, '') })))
        .map(o => Object.assign(o, { on: (c.a || 'sansPrix') === o.id })),
      poserA: e => majChoix(l.mid, { a: e.target.value }),
      ouvrir: () => app.setState({ vmOuvert: ouvert ? null : l.mid }), fiche: () => ouvrirFiche(app, l.mid), ouvert, impact, impactMagasin: sid0 ? ((mags.find(m => m.id === sid0) || {}).nom || '').replace(/^.* - /, '') : '',
      drapeaux: l.drapeaux,
    };
  });
  v.plus = L.length > LIM ? { n: L.length - LIM, voir: () => app.setState({ vmTout: true }) } : null;
  const C = d.compteurs || {};
  v.kpis = [['Matières dans les recettes', C.matieres, 'celles des recettes vendues ces 30 jours (' + ((d.recettes || {}).lues || 0) + ' recettes lues)', ''],
    ['Sans prix dans un magasin', C.sansPrix, 'au moins un magasin ne la chiffre pas', C.sansPrix ? 'ko' : 'ok'],
    ['Sans liste de prix fournisseur', C.sansFournisseur, 'aucune liste de prix en vigueur liée au panel', C.sansFournisseur ? 'att' : 'ok'],
    ['Écart à la référence', C.ecart, 'plus de ' + nf(d.seuilEcart, 0) + ' % entre un magasin et la référence', C.ecart ? 'att' : 'ok'],
    ['Unité à vérifier', C.unite, 'colis en pièces pour une matière en grammes, ou emballage compté en grammes', C.unite ? 'att' : 'ok']];
  const nCh = Object.keys(ch).filter(k => ch[k] && ch[k].opt).length;
  v.chips = [['', 'Toutes', tous.length], ['sansPrix', 'Sans prix', C.sansPrix, 'pb-prix'], ['ecart', 'Écart à la référence', C.ecart, 'pb-reseau'], ['sansFournisseur', 'Sans fournisseur', C.sansFournisseur, 'pb-orph'],
    ['unite', 'Unité à vérifier', C.unite, 'pb-unite'], ['choisis', 'Prix choisis', nCh, 'pb-port']]
    .map(([k, nom, n, cl]) => ({ nom, n: n || 0, classe: cl || '', on: filtre === k, choisir: () => app.setState({ vmFiltre: filtre === k ? '' : k }) }));
  v.q = S.vmQ || ''; v.poserQ = e => app.setState({ vmQ: e.target.value });
  v.n = L.length; v.total = tous.length;
  v.mags = mags.map(m => m.nom.replace(/^.* - /, ''));
  v.lu = d.cache && d.cache.le ? 'calculé à ' + d.cache.le.slice(11, 16) : (d.lu ? 'calculé à ' + String(d.lu).slice(11, 16) : '');
  v.source = d.source || '';
  v.route = d.formatPrix ? d.formatPrix.methode + ' ' + d.formatPrix.route : '';
  // La barre : les prix choisis, puis vérifier et écrire.
  const nEcr = plans.reduce((t, x) => t + x.shops.length, 0);
  const prev = S.vmPrev, res = S.vmRes;
  v.barre = nCh ? {
    n: nCh, nEcr, resume: plans.slice(0, 4).map(x => x.nom + ' (' + x.shops.length + ')').join(', ') + (plans.length > 4 ? '…' : ''),
    annuler: () => app.setState({ vmChoix: {}, vmPrev: null, vmRes: null, vmOuvert: null }),
    verifier: nEcr && !(prev && prev.enCours) ? () => {
      app.setState({ vmPrev: { enCours: true } });
      Promise.all(plans.map(x => write(app.source || 'api', 'POST', ROUTE + 'corriger', Object.assign({ apercu: true }, x)))).then(rs => app.setState({ vmPrev: { d: rs } }));
    } : null,
    apercu: prev && prev.d ? prev.d.map(r => r.apercu ? { ok: true, resume: r.resume, ecritures: (r.ecritures || []).map(e => ({ route: e.methode + ' ' + e.route, resume: e.resume, corps: JSON.stringify(e.corps), annulable: e.annulable })) } : { ok: false, resume: r.error || 'refusé' }) : null,
    enCours: !!(prev && prev.enCours) || !!(res && res.enCours),
    appliquer: prev && prev.d && prev.d.every(r => r.apercu) ? () => {
      app.setState({ vmRes: { enCours: true } });
      const faites = [];
      plans.reduce((pr, x) => pr.then(() => write(app.source || 'api', 'POST', ROUTE + 'corriger', x).then(r => { faites.push(r); })), Promise.resolve()).then(() => {
        const ecr = [].concat(...faites.map(r => r.ecritures || []));
        const derniere = faites.slice().reverse().find(r => r.liste);
        const maj = { vmRes: { d: { ok: faites.every(r => r.ok), ecritures: ecr, erreurs: faites.filter(r => !r.ok && !(r.ecritures || []).length).map(r => r.error || 'refusé') } }, vmPrev: null, vmChoix: {}, vmOuvert: null,
          vrRecentes: ecr.concat(app.state.vrRecentes || []).slice(0, 20), vrReg: null };
        if (derniere) { maj.vrData = { chargement: false, d: derniere.liste }; }
        app.setState(maj);
        charger(app, false); app.setState({ vmData: null }); charger(app, false);
      });
    } : null,
  } : null;
  v.resultat = res && res.d ? { ok: res.d.ok, lignes: (res.d.ecritures || []).map(e => (e.resultat === 'ok' ? '✓ ' : '✕ ') + e.resume + ' — ' + e.resultatNom + (e.message ? ' : ' + e.message : '')).concat(res.d.erreurs || []),
    fermer: () => app.setState({ vmRes: null }) } : null;
  return v;
}
