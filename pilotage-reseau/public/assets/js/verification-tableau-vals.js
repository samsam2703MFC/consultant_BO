/* Cockpit CEO — Matières premières : par fournisseur ou par catégorie, le prix du fournisseur face à chaque magasin, et le
 * formulaire du prix (14/11/2026, maquette docs/maquettes/matieres-par-fournisseur ; « je dois pouvoir avoir le formulaire
 * modal avec le prix et toutes les données à poster »). Données : GET /verification/recettes/matieres (déjà lu par la vue
 * Matières premières). Le formulaire montre et laisse modifier la route et chaque champ envoyé au panel, vérifie (aperçu),
 * envoie (geste `prix`, format saisi) et rend la réponse brute du panel. Gabarit : verification-tableau-tpl.js. */
import { write } from './api.js';
import { ouvrirFiche } from './verification-fichemat-vals.js';

const nf = (n, d) => n == null || isNaN(n) ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/[  ]/g, ' ');
const num = s => { const t = String(s == null ? '' : s).trim().replace(/\s/g, '').replace(',', '.'); if (t === '') { return null; } const n = Number(t); return isFinite(n) ? n : NaN; };
const dec = (v, par) => par === 'pce' || par === 'unité' ? (v < 1 ? 4 : (v < 10 ? 3 : 2)) : (v < 0.1 ? 4 : 2);
const px = (v, par) => v == null || isNaN(v) ? '—' : nf(v, dec(v, par)) + ' €';
const court = n => String(n || '').replace(/^.* - /, '');
const jm = s => s ? s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(2, 4) : '';
const SANS = 'Aucun fournisseur lié';
/** Les trois routes de prix du panel (swagger), et leurs champs de départ. {sid} {mid} {prix} {fid} {date} se remplacent à l'envoi. */
const PRESETS = [
  { id: 'manual', nom: 'Prix du magasin (manual-price)', methode: 'POST', route: '/materials/{mid}/manual-price', champs: [['id_shop', '{sid}'], ['price', '{prix}']] },
  { id: 'avg', nom: 'Prix moyen du magasin (avg-price)', methode: 'PATCH', route: '/shops/{sid}/materials/avg-price', champs: [['id_material', '{mid}'], ['avg_price', '{prix}']] },
  { id: 'fourn', nom: 'Prix chez le fournisseur', methode: 'POST', route: '/shops/{sid}/suppliers/{fid}/materials/price', champs: [['id_material', '{mid}'], ['price', '{prix}'], ['valid_from', '{date}']] },
];

/** Le prix de référence d'une matière : la liste en vigueur (du fournisseur choisi), sinon le prix général du panel. */
function reference(l, fNom){
  const fs = (l.options || []).filter(o => o.type === 'fournisseur' && (!fNom || o.nom.split(' · réf')[0] === fNom));
  const val = o => Object.values(o.prixMagasins || {}).find(v => v > 0);
  const f = fs.find(o => o.enVigueur && val(o)) || fs.find(o => o.enVigueur) || fs[0] || null;
  if (f && val(f)) { return { prix: val(f), f, quoi: 'liste' }; }
  const g = (l.options || []).find(o => (o.type === 'reference' || o.type === 'conseille') && val(o));
  if (g) { return { prix: val(g), f, quoi: 'general' }; }
  return { prix: null, f, quoi: f ? (f.conversion ? 'conversion' : (f.enVigueur ? 'sansprix' : 'nonlue')) : 'aucun' };
}

export function valsTableau(app, d){
  const S = app.state;
  if (!d) { return null; }
  const mode = S.vtMode === 'categorie' ? 'categorie' : 'fournisseur';
  const L = d.lignes || [];
  const fournisseurs = (() => { const c = {}; L.forEach(l => { const fs = [...new Set((l.options || []).filter(o => o.type === 'fournisseur').map(o => o.nom.split(' · réf')[0]))]; if (!fs.length) { c[SANS] = (c[SANS] || 0) + 1; } fs.forEach(f => { c[f] = (c[f] || 0) + 1; }); }); return Object.entries(c).sort((a, b) => b[1] - a[1]); })();
  const categories = (() => { const c = {}; L.forEach(l => { const k = l.cat || '—'; c[k] = (c[k] || 0) + 1; }); return Object.entries(c).sort((a, b) => b[1] - a[1]); })();
  const liste = mode === 'fournisseur' ? fournisseurs : categories;
  const choix = S.vtChoix && liste.some(x => x[0] === S.vtChoix) ? S.vtChoix : (liste[0] || [''])[0];
  const q = String(S.vtQ || '').trim().toLowerCase();
  const fNom = mode === 'fournisseur' && choix !== SANS ? choix : null;
  const lignes = L.filter(l => (mode === 'fournisseur'
    ? (choix === SANS ? !(l.options || []).some(o => o.type === 'fournisseur') : (l.options || []).some(o => o.type === 'fournisseur' && o.nom.split(' · réf')[0] === choix))
    : (l.cat || '—') === choix) && (!q || l.nom.toLowerCase().indexOf(q) >= 0));
  const mags = d.magasins || [];
  const vue = l => {
    const ref = reference(l, fNom);
    const f = ref.f;
    const cell = m => {
      if (m.prix == null) { return m.lu ? { txt: 'sans prix', ton: 'ko', id: m.id } : { txt: '?', ton: '', e: 'non lu', id: m.id }; }
      const v = ref.prix ? 100 * (m.prix - ref.prix) / ref.prix : null;
      return { txt: px(m.prix * l.facteur, l.par), ton: v == null ? '' : (Math.abs(v) <= 10 ? 'ok' : (Math.abs(v) > 100 ? 'ko' : 'att')), e: v == null ? '' : (Math.abs(v) <= 10 ? '✓ ' : '') + (v > 0 ? '+' : (v < 0 ? '−' : '')) + nf(Math.abs(v), 0) + ' %', id: m.id };
    };
    const ecarts = (l.magasins || []).filter(m => m.prix != null && ref.prix).map(m => Math.abs(100 * (m.prix - ref.prix) / ref.prix));
    const sans = (l.magasins || []).filter(m => m.prix == null && m.lu).length;
    const etat = !ref.prix ? (sans ? 'ko' : 'mu') : (sans || ecarts.some(e => e > 10) ? (ecarts.some(e => e > 100) ? 'ko' : 'att') : 'ok');
    const c = f && f.colis;
    return { mid: l.mid, nom: l.nom, par: l.par, source: l.source === 'CENTRAL' ? 'centrale' : 'libre', etat,
      groupe: mode === 'fournisseur' ? (l.cat || '—') : ((f && f.nom.split(' · réf')[0]) || SANS),
      fournisseur: f ? { nom: f.nom, txt: (c ? 'colis ' + nf(c.taille || 1, 0) + ' ' + (c.unite || '') + (c.parColis > 1 ? ' × ' + nf(c.parColis, 0) : '') + ' · ' + nf(c.prix, 2) + ' € HT' : (f.listeLue ? 'pas de prix en vigueur' : 'liste non lue')) + (f.depuis ? ' · depuis le ' + jm(f.depuis) : '') } : null,
      ref: ref.prix ? { txt: px(ref.prix * l.facteur, l.par), quoi: ref.quoi === 'general' ? 'prix général du panel' : 'liste du fournisseur' } : { txt: '', quoi: { conversion: 'colis en pièces : poids à poser', sansprix: 'pas de prix en vigueur', nonlue: 'liste non lue', aucun: 'ni fournisseur ni prix général' }[ref.quoi] || '' },
      cellules: mags.map(m => cell((l.magasins || []).find(x => x.id === m.id) || { id: m.id })),
      recettes: l.nRecettes + ' · ' + nf(l.ca, 0) + ' €', ca: l.ca,
      fiche: () => ouvrirFiche(app, l.mid),
      prix: sid => () => ouvrirForm(app, l, ref, d, sid) };
  };
  const vs = lignes.map(vue).sort((a, b) => b.ca - a.ca);
  const groupes = {}; vs.forEach(v => { (groupes[v.groupe] = groupes[v.groupe] || []).push(v); });
  const ordre = Object.keys(groupes).sort((a, b) => groupes[b].reduce((t, x) => t + x.ca, 0) - groupes[a].reduce((t, x) => t + x.ca, 0));
  const compte = t => vs.filter(v => v.etat === t).length;
  return {
    mode, choisirMode: m => () => app.setState({ vtMode: m, vtChoix: null }),
    choix, options: liste.map(([nom, n]) => ({ nom, n, on: nom === choix })), choisir: e => app.setState({ vtChoix: e.target.value }),
    q: S.vtQ || '', poserQ: e => app.setState({ vtQ: e.target.value }),
    kpis: [['Matières', vs.length, mode === 'fournisseur' ? 'chez ' + choix : 'dans « ' + choix + ' »', ''], ['Alignées', compte('ok'), 'les magasins à 10 % près du prix de référence', 'ok'],
      ['Écart > 10 %', compte('att'), 'au moins un magasin s’écarte', 'att'], ['À voir', compte('ko'), 'sans prix en magasin, ou écart de plus de ×2', 'ko'], ['Sans prix de référence', compte('mu'), 'ni liste en vigueur ni prix général', 'mu']],
    montrerF: mode === 'categorie', mags: mags.map(m => court(m.nom)),
    groupes: ordre.map(g => ({ nom: g, n: groupes[g].length, ca: nf(groupes[g].reduce((t, x) => t + x.ca, 0), 0) + ' €', lignes: groupes[g] })),
    form: valsForm(app),
  };
}

/* ── Le formulaire du prix ───────────────────────────────────────────────── */

function ouvrirForm(app, l, ref, d, sid){
  const fp = d.formatPrix || PRESETS[0];
  const fid = ref.f && /^f(\d+)/.test(ref.f.id) ? Number(ref.f.id.match(/^f(\d+)/)[1]) : 0;
  const choisis = {};
  (l.magasins || []).forEach(m => { const e = m.prix != null && ref.prix ? Math.abs(100 * (m.prix - ref.prix) / ref.prix) : null; choisis[m.id] = sid ? m.id === sid : (m.lu && (m.prix == null || (e != null && e > 10))); });
  app.setState({ vtForm: { mid: l.mid, nom: l.nom, par: l.par, facteur: l.facteur, fid, fournisseur: ref.f ? ref.f.nom : '', refTxt: ref.prix ? px(ref.prix * l.facteur, l.par) : '—',
    magasins: (l.magasins || []).map(m => ({ id: m.id, nom: court(m.nom), prix: m.prix })), choisis,
    prix: ref.prix ? String(+Number(ref.prix * l.facteur).toFixed(6)).replace('.', ',') : '',
    methode: fp.methode || 'POST', route: fp.route || PRESETS[0].route,
    champs: Object.entries(fp.corps || {}).map(([k, v]) => [k, String(v)]).concat(Object.keys(fp.corps || {}).length ? [] : PRESETS[0].champs.map(x => x.slice())),
    garder: false, prev: null, res: null } });
}

function valsForm(app){
  const F = app.state.vtForm;
  if (!F) { return null; }
  const maj = patch => app.setState({ vtForm: Object.assign({}, app.state.vtForm, patch, { prev: null }) });
  const p = num(F.prix);
  const prixBase = p > 0 ? p / F.facteur : null;
  const shops = F.magasins.filter(m => F.choisis[m.id]).map(m => Number(m.id));
  const corps = {}; F.champs.forEach(([k, v]) => { if (String(k).trim() !== '') { corps[String(k).trim()] = v; } });
  const format = { methode: F.methode, route: F.route, corps };
  // L'aperçu local : le premier magasin coché, placeholders remplacés (le serveur refait le même calcul).
  const s0 = shops[0];
  const sub = v => v === '{prix}' ? prixBase : (v === '{sid}' ? s0 : (v === '{mid}' ? F.mid : (v === '{fid}' ? F.fid : (v === '{date}' ? new Date().toISOString().slice(0, 10) : (v !== '' && !isNaN(Number(v)) && !/^0\d/.test(v) ? Number(v) : v)))));
  const local = {}; Object.keys(corps).forEach(k => { local[k] = sub(corps[k]); });
  const routeLocale = F.route.replace('{sid}', s0 == null ? '{sid}' : s0).replace('{mid}', F.mid).replace('{fid}', F.fid || '{fid}');
  const corpsEnvoi = { geste: 'prix', mid: F.mid, prix: prixBase, shops, fid: F.fid, matiere: F.nom, format, garder: !!F.garder };
  const valide = prixBase > 0 && shops.length > 0 && F.route.startsWith('/');
  const prev = F.prev, res = F.res;
  return {
    nom: F.nom, par: F.par, fournisseur: F.fournisseur, refTxt: F.refTxt, fid: F.fid,
    fermer: () => app.setState({ vtForm: null }),
    magasins: F.magasins.map(m => ({ nom: m.nom, prix: m.prix == null ? 'sans prix' : px(m.prix * F.facteur, F.par), on: !!F.choisis[m.id],
      basculer: () => maj({ choisis: Object.assign({}, app.state.vtForm.choisis, { [m.id]: !app.state.vtForm.choisis[m.id] }) }) })),
    prix: F.prix, poserPrix: e => maj({ prix: e.target.value }), prixBase: prixBase == null ? '' : nf(prixBase, prixBase < 0.1 ? 6 : 4) + ' € l’unité de base',
    presets: PRESETS.map(x => ({ nom: x.nom, on: x.route === F.route && x.methode === F.methode, choisir: () => maj({ methode: x.methode, route: x.route, champs: x.champs.map(c => c.slice()) }) })),
    methode: F.methode, methodes: ['POST', 'PATCH', 'PUT'].map(m => ({ m, on: m === F.methode })), poserMethode: e => maj({ methode: e.target.value }),
    route: F.route, poserRoute: e => maj({ route: e.target.value }),
    champs: F.champs.map(([k, v], i) => ({ k, v, idK: 'vt-k-' + i, idV: 'vt-v-' + i,
      poserK: e => { const c = app.state.vtForm.champs.map(x => x.slice()); c[i][0] = e.target.value; maj({ champs: c }); },
      poserV: e => { const c = app.state.vtForm.champs.map(x => x.slice()); c[i][1] = e.target.value; maj({ champs: c }); },
      retirer: () => maj({ champs: app.state.vtForm.champs.filter((x, j) => j !== i) }) })),
    ajouter: () => maj({ champs: app.state.vtForm.champs.concat([['', '']]) }),
    garder: !!F.garder, basculerGarder: () => maj({ garder: !app.state.vtForm.garder }),
    apercuLocal: s0 == null ? '' : F.methode + ' ' + routeLocale + '\n' + JSON.stringify(local, null, 2),
    invalide: !valide,
    verifier: valide && !(prev && prev.enCours) ? () => {
      app.setState({ vtForm: Object.assign({}, app.state.vtForm, { prev: { enCours: true }, res: null }) });
      write(app.source || 'api', 'POST', '/verification/recettes/corriger', Object.assign({ apercu: true }, corpsEnvoi)).then(r => app.setState({ vtForm: Object.assign({}, app.state.vtForm, { prev: { d: r } }) }));
    } : null,
    apercu: prev && prev.d && prev.d.apercu ? (prev.d.ecritures || []).map(e => ({ txt: e.methode + ' ' + e.route + '\n' + JSON.stringify(e.corps, null, 2), resume: e.resume, permise: e.permise !== false })) : null,
    refus: prev && prev.d && !prev.d.apercu ? (prev.d.error || 'Le cockpit n’a pas pu préparer l’envoi.') : null,
    enCours: !!(prev && prev.enCours) || !!(res && res.enCours),
    envoyer: prev && prev.d && prev.d.apercu ? () => {
      app.setState({ vtForm: Object.assign({}, app.state.vtForm, { res: { enCours: true } }) });
      write(app.source || 'api', 'POST', '/verification/recettes/corriger', corpsEnvoi).then(r => {
        app.setState({ vtForm: Object.assign({}, app.state.vtForm, { res: { d: r }, prev: null }), vrRecentes: (r.ecritures || []).concat(app.state.vrRecentes || []).slice(0, 20), vrReg: null });
        if (r.ok) { app.setState({ vmData: null }); }
      });
    } : null,
    resultat: res && res.d ? (res.d.ecritures || []).map(e => ({ ok: e.resultat === 'ok', txt: e.resume + ' — ' + e.resultatNom + (e.message ? ' : ' + e.message : ''), http: e.httpPanel, reponse: e.reponse || '' }))
      .concat(res.d.error && !(res.d.ecritures || []).length ? [{ ok: false, txt: res.d.error, reponse: '' }] : []) : null,
  };
}
