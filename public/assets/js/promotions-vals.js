/* Cockpit CEO — Promotions (26/10/2026, maquette A de docs/maquettes/promotions-cockpit, demande de Sam).
 *
 * La création des promotions sort du dashboard magasin, qui ne fait plus que les lire, et entre au rail, une section à
 * elle :
 *  - Tableau d'analyse (#/promotions) : toutes les promotions de tous les magasins — celles de la marque et celles
 *    réservées à un magasin —, et ce qu'elles ont fait sur 7 ou 30 jours (GET /promotions/reseau, promos_reseau.php).
 *    Deux vues : « Qui utilise quoi » (la matrice promotions × magasins, puis la carte de chaque magasin — maquette A
 *    avec le détail de C, docs/maquettes/promotions-utilisation) et « Toutes les promotions » (la liste).
 *  - Édition (#/promotions/edition?shop=4) : l'assistant « X achetés + Y » du dashboard, tel quel, dans un cadre
 *    (dashboard/?embed=promos) où le magasin se choisit. Le cadre écrit au panel lui-même ; il prévient le cockpit par
 *    messages (enregistrée, magasin, tableau, haut, etat) et sa hauteur suit son contenu (même origine).
 *
 * Remplit `common.prT` (tableau) et `common.prE` (édition) ; les gabarits sont dans promotions-tpl.js, le style dans
 * promotions.css (préfixe ptb-).
 */
import { readOne } from './api.js';

const nf = (n, d) => n == null ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).replace(/ /g, ' ');
const fE = (n, d) => n == null ? '—' : nf(n, d) + ' €';
const fX = v => '×' + nf(v, 2);
const pl = (n, mot, mots) => nf(n) + ' ' + (n > 1 ? (mots || mot + 's') : mot);
const court = v => String(v || '').replace(/^.* - /, '');
const JOURS = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
// Les états, dans les mots de la liste du dashboard.
const ETAT = { en_caisse: ['En caisse', 'ok'], a_venir: ['À venir', 'a'], brouillon: ['Brouillon', 'b'], terminee: ['Terminée', 'b'], inactive: ['Inactive au panel', 'b'] };
const FILTRES = [['actives', 'Actives', e => e === 'en_caisse'], ['a_venir', 'À venir', e => e === 'a_venir'], ['brouillons', 'Brouillons', e => e === 'brouillon'],
  ['terminees', 'Terminées', e => e === 'terminee' || e === 'inactive'], ['toutes', 'Toutes', () => true]];
// La matrice : ce que dit une case, et les seuils de ses cinq teintes (% du CA du magasin ; sinon des cinquièmes du max).
const MESURES = [['pcMag', '% du CA du magasin'], ['pcG', '% du CA général'], ['v', '€'], ['fois', 'Fois']];
const SEUILS = [0.25, 0.5, 1, 2];
const TRIS = { fois: p => p.fois, v: p => p.v, r: p => p.r, coef: p => (p.coef && p.coef.lot != null ? -p.coef.lot : null), du: p => p.du || '' };
const JUSQUA = '2037-12-31';

/** « 27/10 », l'année quand ce n'est pas celle en cours ; null pour une fin « Tout le temps ». */
function jj(v){
  if (!v) { return null; }
  if (v.slice(0, 10) >= JUSQUA) { return null; }
  return v.slice(8, 10) + '/' + v.slice(5, 7) + (v.slice(0, 4) !== String(new Date().getFullYear()) ? '/' + v.slice(2, 4) : '');
}
function creneauxTxt(l){
  if (!l || !l.length) { return ''; }
  const h = (de, a) => de.slice(3) === '00' && a.slice(3) === '00' ? de.slice(0, 2) + '–' + a.slice(0, 2) + ' h' : de + '–' + a;
  return l.map(c => {
    const j = (c.jours || []).slice().sort((a, b) => a - b), suite = j.length >= 3 && j.every((v, i) => !i || v === j[i - 1] + 1);
    return (j.length === 7 ? 'tous les jours' : (suite ? JOURS[j[0] - 1] + '–' + JOURS[j[j.length - 1] - 1] : j.map(v => JOURS[v - 1]).join(', '))) + ' ' + h(c.de || '', c.a || '');
  }).join(' · ');
}

/* --- La lecture : une pour les deux écrans et les badges du rail ------------------------------------------------ */
/** `force` : relue au serveur, règles du panel comprises (sans le cache de 5 minutes). */
export function promosCharge(app, force){
  const n = app.state.prJours === 7 ? 7 : 30;
  const L = app._prR || (app._prR = { n: null, d: undefined, enCours: false });
  if (L.enCours || (!force && L.n === n && L.d !== undefined)) { return; }
  L.enCours = true; L.n = n;
  readOne('/promotions/reseau?jours=' + n + (force ? '&frais=1' : '&_cache=300'))
    .then(d => { if (d && !d.error) { L.d = d; } else if (L.d === undefined) { L.d = null; } })
    .catch(() => { if (L.d === undefined) { L.d = null; } })
    .finally(() => { L.enCours = false; app.setState({}); });
}
/** Les badges du rail, une fois la lecture faite : les brouillons à reprendre, les promotions en caisse sous le repère. */
export function promosBadges(app){
  const d = app._prR && app._prR.d;
  if (!d) { return { brouillons: 0, sousRepere: 0 }; }
  const min = (d.regles || {}).coefMin || 2, P = d.promos || [];
  return { brouillons: P.filter(p => p.src === 'mag' && p.etat === 'brouillon').length,
    sousRepere: P.filter(p => p.etat === 'en_caisse' && p.coef && p.coef.lot != null && p.coef.lot < min).length };
}

/** Le magasin de l'édition quand rien ne le dit : le premier magasin ouvert. */
function magasinParDefaut(app){
  const o = app.open ? app.open() : [];
  const d = app._prR && app._prR.d;
  return String((o[0] && o[0].id) || (d && d.magasins && d.magasins[0] && d.magasins[0].id) || '');
}
/**
 * Ouvrir l'édition sur une promotion : `ouvrir` = nouvelle | bon | p21 | dup:mag:p21 | dup:panel:12 | codes:p21 (voir
 * dashboard.js, EMBED_PM). Le cadre se recharge (`peN`) : ce qui n'est pas enregistré dans l'assistant se perd — on
 * le demande d'abord.
 */
export function promoEditer(app, o){
  const S = app.state;
  if (S.screen === 'promoEdition' && app._peOuvert && !window.confirm('La promotion ouverte dans l’assistant n’est pas enregistrée : l’abandonner ?')) { return; }
  const shop = String(o.shop || S.peShop || magasinParDefaut(app));
  app._peOuvert = false;
  app.setState({ screen: 'promoEdition', peShop: shop, peSrcShop: shop, peOuvrir: o.ouvrir || 'nouvelle', peN: (S.peN || 0) + 1, prMenu: null, railOuvert: false });
}
function promoSupprimer(app, p, nomMag){
  if (!window.confirm('Supprimer le brouillon « ' + (p.nom || 'sans nom') + ' » de ' + nomMag + ' ? Il est supprimé du panel.')) { return; }
  app.api('DELETE', '/exploitation/promos-magasin?shop=' + encodeURIComponent(p.shop) + '&id=p' + p.id, {}).then(r => {
    if (!r || r.ok === false) { return; }
    app.notify('Brouillon supprimé du panel : « ' + (p.nom || 'sans nom') + ' »');
    app.setState({ prMenu: null });
    promosCharge(app, true);
  });
}

/* --- Le Tableau d'analyse ------------------------------------------------------------------------------------------- */
export function valsPromoTableau(app, common){
  promosCharge(app, false);
  const S = app.state, L = app._prR, d = L.d, n = L.n || 30;
  const T = common.prT = { chargement: d === undefined, motif: d === null ? 'Les promotions n’ont pas pu être lues : réessayez dans un instant.' : '', enCours: L.enCours };
  T.relire = () => { promosCharge(app, true); app.setState({}); };
  T.nouvelle = () => promoEditer(app, { shop: S.prShopF, ouvrir: 'nouvelle' });
  T.nouveauBon = () => promoEditer(app, { shop: S.prShopF, ouvrir: 'bon' });
  T.jours = [7, 30].map(v => ({ nom: v + ' jours', on: n === v, choisir: () => app.setState({ prJours: v }) }));
  if (!d) { return; }
  T.lu = d.lu ? 'relu au panel ' + (d.luJour && d.luJour !== new Date().toISOString().slice(0, 10) ? 'le ' + d.luJour.slice(8, 10) + '/' + d.luJour.slice(5, 7) + ' ' : '') + 'à ' + d.lu : '';
  T.erreurPanel = d.erreurPanel || '';
  const min = (d.regles || {}).coefMin || 2, mags = d.magasins || [], P = d.promos || [], tot = d.total || {};
  const mag = id => mags.find(m => +m.id === +id) || null;
  const nomMag = id => (mag(id) || {}).nom || court('magasin ' + id);
  const caDe = p => p.src === 'mag' ? (mag(p.shop) || {}).ca || 0 : (p.shops ? p.shops.reduce((a, id) => a + ((mag(id) || {}).ca || 0), 0) : tot.ca || 0);
  const pc = (a, b) => b > 0 ? 100 * a / b : null;
  const vue = S.prVue === 'liste' ? 'liste' : 'matrice';
  T.vue = vue;
  T.vues = [['matrice', 'Qui utilise quoi'], ['liste', 'Toutes les promotions (liste)']].map(([k, nom]) => ({ nom, on: k === vue, choisir: () => app.setState({ prVue: k, prMenu: null }) }));
  T.notes = notesReseau(d);
  if (vue === 'matrice') { valsMatrice(app, T, d); return; }

  // Les six chiffres.
  const enC = P.filter(p => p.etat === 'en_caisse');
  const sous = enC.filter(p => p.coef && p.coef.lot != null && p.coef.lot < min);
  const sans = mags.filter(m => !enC.some(p => p.src === 'mag' && +p.shop === +m.id));
  const nM = enC.filter(p => p.src === 'marque').length;
  T.kpis = [
    { lab: 'En caisse', v: nf(enC.length), s: nf(nM) + ' de la marque · ' + nf(enC.length - nM) + ' des magasins' },
    { lab: 'À venir · brouillons', v: nf(P.filter(p => p.etat === 'a_venir').length) + ' · ' + nf(P.filter(p => p.etat === 'brouillon').length), s: 'les brouillons sont inactifs au panel' },
    { lab: 'Utilisations · ' + n + ' jours', v: nf(tot.fois), s: tot.fois ? nf(pc(tot.web, tot.fois), 0) + ' % sur le webshop' : 'aucune dans les tickets lus' },
    { lab: 'Ventes en promotion', v: fE(tot.v), s: (tot.ca ? nf(pc(tot.v, tot.ca), 1) + ' % des ventes lues · ' : '') + 'remise ' + fE(tot.r) },
    { lab: 'Sous le repère ' + fX(min).replace(',00', ''), v: nf(sous.length), s: sous.length ? sous.map(p => p.src === 'mag' ? nomMag(p.shop) : p.nom).join(', ') + ' · le lot coûte trop cher' : 'toutes au-dessus, aux prix et coûts d’aujourd’hui', cls: sous.length ? 'ko' : '' },
    { lab: 'Sans promotion à eux', v: nf(sans.length), s: sans.length ? sans.map(m => m.nom).join(', ') + ' · seulement celles de la marque' : 'chaque magasin en a une en caisse', cls: sans.length ? 'wa' : '' }];

  // Les filtres : l'état, le magasin (ses promotions et celles de la marque qui le touchent), l'origine, la sorte.
  const shopF = S.prShopF || '', orig = S.prOrig || '', sorte = S.prSorte || '', q = (S.prQ || '').trim().toLowerCase();
  const base = P.filter(p => (!shopF || (p.src === 'mag' ? String(p.shop) === shopF : !p.shops || p.shops.map(String).includes(shopF)))
    && (!orig || p.src === orig) && (!sorte || p.type === sorte)
    && (!q || (p.nom + ' ' + p.texte + ' ' + (p.src === 'mag' ? nomMag(p.shop) : 'marque')).toLowerCase().includes(q)));
  const f = FILTRES.find(x => x[0] === S.prF) || FILTRES[0];
  T.filtres = FILTRES.map(([k, nom, ok]) => ({ nom, n: base.filter(p => ok(p.etat)).length, on: k === f[0], choisir: () => app.setState({ prF: k, prMenu: null }) }));
  T.magasins = [{ v: '', nom: 'Tous les magasins', on: !shopF }].concat(mags.map(m => ({ v: String(m.id), nom: m.nom, on: String(m.id) === shopF })));
  T.origines = [['', 'Marque et magasins'], ['marque', 'La marque'], ['mag', 'Les magasins']].map(([v, nom]) => ({ v, nom, on: v === orig }));
  T.sortes = [['', 'Toutes les sortes'], ['xy', 'X achetés + Y'], ['bundle', 'Bundles']].map(([v, nom]) => ({ v, nom, on: v === sorte }));
  T.setShop = e => app.setState({ prShopF: e.target.value, prMenu: null });
  T.setOrig = e => app.setState({ prOrig: e.target.value, prMenu: null });
  T.setSorte = e => app.setState({ prSorte: e.target.value, prMenu: null });
  T.q = S.prQ || '';
  T.setQ = e => app.setState({ prQ: e.target.value, prMenu: null });

  // Le tri : une colonne de chiffres, la plus grande d'abord (le coefficient : le plus bas d'abord).
  const tri = TRIS[S.prTri] ? S.prTri : 'fois', cle = TRIS[tri];
  T.tri = Object.fromEntries(Object.keys(TRIS).map(k => [k, { on: k === tri, choisir: () => app.setState({ prTri: k }) }]));
  const vues = base.filter(p => f[2](p.etat)).sort((a, b) => {
    const va = cle(a), vb = cle(b);
    if (va == null && vb == null) { return 0; }
    if (va == null) { return 1; }
    if (vb == null) { return -1; }
    return typeof va === 'string' ? vb.localeCompare(va) : vb - va;
  });
  const sansChiffres = p => !p.fois && (p.etat === 'brouillon' || p.etat === 'a_venir' || p.etat === 'inactive');
  T.lignes = vues.map(p => {
    const k = p.src + ':' + p.id, e = ETAT[p.etat] || [p.etat, 'b'], ca = caDe(p), c = p.coef || null;
    const nom = p.src === 'mag' ? nomMag(p.shop) : '';
    const acts = [];
    if (p.src === 'mag') {
      if (p.etat === 'brouillon') { acts.push({ nom: 'Reprendre', go: () => promoEditer(app, { shop: p.shop, ouvrir: 'p' + p.id }) }); }
      else if (p.etat === 'en_caisse' || p.etat === 'a_venir') { acts.push({ nom: 'Modifier', go: () => promoEditer(app, { shop: p.shop, ouvrir: 'p' + p.id }) }); }
      acts.push({ nom: 'Dupliquer', go: () => promoEditer(app, { shop: p.shop, ouvrir: 'dup:mag:p' + p.id }) });
      if (p.bon) { acts.push({ nom: 'Voir les codes du bon', go: () => promoEditer(app, { shop: p.shop, ouvrir: 'codes:p' + p.id }) }); }
      if (p.etat === 'brouillon') { acts.push({ nom: 'Supprimer le brouillon', ko: true, go: () => promoSupprimer(app, p, nom) }); }
      acts.push({ nom: 'Le dashboard du magasin ↗', go: () => window.open('dashboard/?shop=' + encodeURIComponent(p.shop) + '&vue=promos', '_blank', 'noopener') });
    } else if (p.type === 'xy') {
      const pour = shopF || (p.shops && p.shops.length ? String(p.shops[0]) : '');
      acts.push({ nom: 'Dupliquer en promotion d’un magasin', go: () => promoEditer(app, { shop: pour, ouvrir: 'dup:panel:' + p.id }) });
    }
    return {
      cle, marque: p.src === 'marque',
      mag: p.src === 'mag' ? nom : (p.shops ? pl(p.shops.length, 'magasin') : 'Tout le réseau'),
      magTitre: p.src === 'marque' && p.shops ? p.shops.map(nomMag).join(', ') : '',
      nom: p.nom || 'Sans nom', bon: !!p.bon, texte: p.texte || '', creneaux: creneauxTxt(p.creneaux),
      comptoir: !p.canaux || p.canaux.includes('comptoir'), webshop: !p.canaux || p.canaux.includes('webshop'), seuil: +p.seuil > 0 ? fE(+p.seuil) : '',
      sorte: p.type === 'bundle' ? 'Bundle' : '',
      du: jj(p.du) || '—', au: jj(p.au), etat: e[0], etatCls: e[1],
      fois: sansChiffres(p) ? null : nf(p.fois), ventes: sansChiffres(p) ? null : fE(p.v), poids: sansChiffres(p) || !ca ? null : nf(pc(p.v, ca), 1) + ' %',
      remise: sansChiffres(p) || !p.r ? null : '−' + fE(p.r), web: p.fois ? nf(pc(p.web, p.fois), 0) + ' %' : null,
      coef: c && c.lot != null ? fX(c.lot) : null, coefCls: c && c.lot != null ? (c.lot >= min ? 'ok' : 'ko') : '',
      coefTitre: c && c.x ? 'le cas le plus serré : ' + c.x + ' + ' + c.y + (c.magasin && p.src === 'marque' ? ' (' + nomMag(c.magasin) + ')' : '') + ' · prix payé HT ÷ coût matière, aux prix et coûts d’aujourd’hui' : '',
      actions: acts, menu: S.prMenu === k, basculer: acts.length ? () => app.setState({ prMenu: S.prMenu === k ? null : k }) : null };
  });
  const vT = vues.reduce((a, p) => ({ fois: a.fois + (p.fois || 0), v: a.v + (p.v || 0), r: a.r + (p.r || 0), web: a.web + (p.web || 0) }), { fois: 0, v: 0, r: 0, web: 0 });
  T.total = { n: pl(vues.length, 'promotion'), fois: nf(vT.fois), ventes: fE(vT.v), remise: vT.r ? '−' + fE(vT.r) : '—', web: vT.fois ? nf(pc(vT.web, vT.fois), 0) + ' %' : '—',
    poids: !shopF && tot.ca ? nf(pc(vT.v, tot.ca), 1) + ' %' : (shopF && (mag(shopF) || {}).ca ? nf(pc(vT.v, mag(shopF).ca), 1) + ' %' : '—') };
  T.vide = !vues.length ? (f[0] === 'actives' ? 'Aucune promotion en caisse' + (shopF ? ' à ' + nomMag(shopF) : '') + '.' : 'Rien ici.') : '';
  T.notes.push('Fois : les fois où une promotion s’applique sur un ticket · ventes : ce que le client paie pour les pièces en promotion · poids : part des ventes lues du ou des magasins · coefficient : prix payé HT ÷ coût matière, le cas le plus serré, aux prix et coûts d’aujourd’hui.');
}

/** Ce que le tableau ne compte pas : une remise qu'aucune promotion n'explique, les jours pas encore lus. */
function notesReseau(d){
  const notes = [], mags = d.magasins || [];
  const x = d.nonReconnue || {};
  if (x.fois) { notes.push('Remises qu’aucune promotion du panel n’explique : ' + pl(x.fois, 'fois', 'fois') + ', −' + fE(x.r) + '.'); }
  const manque = mags.filter(m => m.sans > 0);
  if (manque.length) { notes.push('Jours pas encore lus (les tickets se lisent avec les écrans de ventes) : ' + manque.map(m => m.nom + ' ' + m.sans).join(', ') + '.'); }
  if (mags.some(m => m.commandes === false)) { notes.push('Commandes du webshop illisibles pour ' + mags.filter(m => m.commandes === false).map(m => m.nom).join(', ') + ' : tout y compte au comptoir.'); }
  return notes;
}

/**
 * « Qui utilise quoi » : une ligne par promotion (celles qui ont servi sur la période, et celles en caisse même sans
 * vente), une colonne par magasin. La case : ce qu'elle pèse dans le CA du magasin (ou dans le CA général, en €, en
 * fois) ; « 0 » : en caisse dans ce magasin, pas utilisée ; « · » : pas dans ce magasin. Sous la matrice, la carte de
 * chaque magasin (ses promotions et ce que chacune pèse dans son CA) ; un clic sur un magasin y mène.
 * CA général : les ventes lues des magasins ouverts sur la période (total.ca) ; CA d'un magasin : ses ventes lues.
 */
function valsMatrice(app, T, d){
  const S = app.state, mags = d.magasins || [], tot = d.total || {}, CAG = tot.ca || 0;
  const pc = (a, b) => b > 0 ? 100 * a / b : null;
  const fP = v => v == null ? '—' : nf(v, v < 1 ? 2 : 1) + ' %';
  const orig = S.prOrig || '';
  const mes = MESURES.some(m => m[0] === S.prMes) ? S.prMes : 'pcMag';
  T.mesures = MESURES.map(([k, nom]) => ({ nom, on: k === mes, choisir: () => app.setState({ prMes: k }) }));
  T.origines = [['', 'Marque et magasins'], ['marque', 'La marque'], ['mag', 'Les magasins']].map(([v, nom]) => ({ v, nom, on: v === orig }));
  T.setOrig = e => app.setState({ prOrig: e.target.value });
  // Une promotion dans un magasin : null = pas dans ce magasin ; sinon {fois, v, r} (fois 0 : en caisse, pas utilisée).
  const touche = (p, id) => p.src === 'mag' ? +p.shop === +id : !p.shops || p.shops.map(Number).includes(+id);
  const chez = (p, id) => {
    if (!touche(p, id)) { return null; }
    if (p.src === 'mag') { return { fois: p.fois || 0, v: p.v || 0, r: p.r || 0 }; }
    const x = (p.parMagasin || {})[String(id)];
    return x ? { fois: x.fois || 0, v: x.v || 0, r: x.r || 0 } : { fois: 0, v: 0, r: 0 };
  };
  const P = (d.promos || []).filter(p => (p.fois > 0 || p.etat === 'en_caisse') && (!orig || p.src === orig))
    .sort((a, b) => (b.v || 0) - (a.v || 0) || (b.fois || 0) - (a.fois || 0));
  const M = mags.map(m => {
    const L = P.map(p => ({ p, c: chez(p, m.id) })).filter(x => x.c);
    const v = L.reduce((a, x) => a + x.c.v, 0), fois = L.reduce((a, x) => a + x.c.fois, 0);
    return { m, ca: m.ca || 0, v, fois, pc: pc(v, m.ca || 0), L };
  }).sort((a, b) => (b.pc == null ? -1 : b.pc) - (a.pc == null ? -1 : a.pc));
  const TV = P.reduce((a, p) => a + (p.v || 0), 0), TF = P.reduce((a, p) => a + (p.fois || 0), 0);
  const valeur = (c, x) => mes === 'pcMag' ? pc(c.v, x.ca) : mes === 'pcG' ? pc(c.v, CAG) : mes === 'v' ? c.v : c.fois;
  const texte = v => v == null ? '—' : mes === 'v' ? fE(v) : mes === 'fois' ? nf(v) : fP(v);
  let max = 0;
  P.forEach(p => M.forEach(x => { const c = chez(p, x.m.id); const v = c && c.fois ? valeur(c, x) : null; if (v != null && v > max) { max = v; } }));
  const seuils = mes === 'pcMag' ? SEUILS : [0.2, 0.4, 0.6, 0.8].map(f => f * max);
  const teinte = v => v == null ? 'x' : seuils.filter(s => v >= s).length;
  const ouTexte = p => {
    if (p.src === 'mag') { return (mags.find(m => +m.id === +p.shop) || {}).nom || 'magasin ' + p.shop; }
    const ici = M.filter(x => touche(p, x.m.id)), ok = ici.filter(x => chez(p, x.m.id).fois > 0).length;
    return ok === ici.length && ici.length === mags.length ? 'les ' + mags.length + ' magasins' : ok + ' magasin' + (ok > 1 ? 's' : '') + ' sur ' + ici.length;
  };
  const mxG = Math.max(...P.map(p => pc(p.v || 0, CAG) || 0), 0);
  // Les quatre chiffres.
  const servies = P.filter(p => p.fois > 0);
  const zeros = [];
  P.filter(p => p.etat === 'en_caisse').forEach(p => M.forEach(x => { const c = chez(p, x.m.id); if (c && !c.fois) { zeros.push([p, x.m.nom]); } }));
  const parPromo = {};
  zeros.forEach(([p, nom]) => { (parPromo[p.src + p.id] ||= { p, l: [] }).l.push(nom); });
  const zTxt = Object.values(parPromo).map(z => z.p.nom + ' à ' + z.l.join(', '));
  const top = servies[0];
  T.kpisM = [
    { lab: 'Promotions utilisées', v: nf(servies.length), s: 'dans ' + pl(M.filter(x => x.fois > 0).length, 'magasin') + ' · ' + pl(TF, 'fois', 'fois') + ' sur ' + (d.jours || 30) + ' jours' },
    { lab: 'Ventes en promotion', v: CAG ? nf(pc(TV, CAG), 1) + ' %' : '—', s: 'du CA général · ' + fE(TV) + ' sur ' + fE(CAG) },
    { lab: 'Celle qui pèse le plus', v: top ? top.nom || 'Sans nom' : '—', s: top ? fP(pc(top.v, CAG)) + ' du CA général · ' + ouTexte(top) : 'aucune vente en promotion lue', texte: true },
    { lab: 'En caisse, sans vente', v: nf(zeros.length), s: zeros.length ? zTxt.slice(0, 3).join(' ; ') + (zTxt.length > 3 ? ' ; …' : '') : 'chaque promotion en caisse a servi où elle est', cls: zeros.length ? 'wa' : '' }];
  // La matrice.
  const sel = S.prMagSel != null ? String(S.prMagSel) : '';
  const voir = id => () => {
    app.setState({ prMagSel: String(id) });
    setTimeout(() => { const el = document.getElementById('ptb-mag-' + id); if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); } }, 30);
  };
  const etiq = mes === 'pcMag' ? '% du CA du magasin' : mes === 'pcG' ? '% du CA général' : mes === 'v' ? 'ventes en promotion' : 'fois';
  const borne = v => mes === 'v' ? fE(v) : mes === 'fois' ? nf(v) : nf(v, v < 1 ? 2 : 1).replace(/,?0+$/, '');
  T.mx = {
    vide: P.length ? '' : 'Aucune promotion utilisée ni en caisse sur la période' + (orig ? ' pour ce choix' : '') + '.',
    magasins: M.map(x => ({ nom: x.m.nom, sous: x.m.lus > 0 && x.ca ? fE(x.ca / x.m.lus) + ' / j' : 'pas lu', on: sel === String(x.m.id), voir: voir(x.m.id),
      titre: x.m.nom + ' : ' + fE(x.ca) + ' de ventes lues sur ' + pl(x.m.lus || 0, 'jour') + ' — un clic ouvre sa carte' })),
    lignes: P.map(p => ({
      nom: p.nom || 'Sans nom', marque: p.src === 'marque', terminee: p.etat === 'terminee', ou: ouTexte(p),
      cases: M.map(x => {
        const c = chez(p, x.m.id);
        if (!c) { return { non: true, titre: p.nom + ' n’est pas dans ce magasin (' + x.m.nom + ')' }; }
        if (!c.fois) { return { zero: true, titre: p.nom + ' · ' + x.m.nom + ' : en caisse, pas utilisée sur la période' }; }
        const v = valeur(c, x);
        return { val: texte(v), k: teinte(v), on: sel === String(x.m.id),
          titre: p.nom + ' · ' + x.m.nom + ' — ' + pl(c.fois, 'fois', 'fois') + ' · ' + fE(c.v) + ' vendus en promotion · ' + fP(pc(c.v, x.ca)) + ' du CA de ' + x.m.nom
            + ' · ' + fP(pc(c.v, CAG)) + ' du CA général' + (c.r ? ' · remise ' + fE(c.r) : '') };
      }),
      ventes: fE(p.v || 0), pcG: fP(pc(p.v || 0, CAG)), w: mxG > 0 ? 100 * (pc(p.v || 0, CAG) || 0) / mxG : 0 })),
    pied: M.map(x => ({ val: mes === 'pcMag' ? fP(x.pc) : mes === 'pcG' ? fP(pc(x.v, CAG)) : mes === 'v' ? fE(x.v) : nf(x.fois), on: sel === String(x.m.id) })),
    piedLib: mes === 'pcMag' ? 'ce qu’elles pèsent dans le CA du magasin' : mes === 'pcG' ? 'ce qu’elles pèsent dans le CA général' : mes === 'v' ? 'ventes en promotion du magasin' : 'fois dans le magasin',
    totV: fE(TV), totG: CAG ? nf(pc(TV, CAG), 1) + ' %' : '—',
    legende: { lib: etiq + ' :', pas: seuils.map((s, i) => i === 0 ? 'moins de ' + borne(s) : borne(seuils[i - 1]) + ' à ' + borne(s)).concat([borne(seuils[3]) + ' et plus']) } };
  // Les cartes : chaque magasin, ses promotions et ce que chacune pèse dans son CA (la même échelle pour toutes).
  let mxM = 0;
  M.forEach(x => x.L.forEach(l => { const v = pc(l.c.v, x.ca); if (v != null && v > mxM) { mxM = v; } }));
  T.mx.cartes = M.map(x => {
    const n = x.L.filter(l => l.c.fois > 0).length;
    return { id: String(x.m.id), nom: x.m.nom, on: sel === String(x.m.id), n: pl(n, 'promotion utilisée', 'promotions utilisées'),
      pc: fP(x.pc != null ? +x.pc.toFixed(1) : null), sous: fE(x.v) + ' sur ' + fE(x.ca) + ' · ' + fP(pc(x.v, CAG)) + ' du CA général',
      lignes: x.L.slice().sort((a, b) => b.c.v - a.c.v || b.c.fois - a.c.fois).map(l => {
        const v = pc(l.c.v, x.ca);
        return { nom: l.p.nom || 'Sans nom', marque: l.p.src === 'marque', zero: !l.c.fois, val: fP(v), w: mxM > 0 && v != null ? 100 * v / mxM : 0,
          titre: pl(l.c.fois, 'fois', 'fois') + ' · ' + fE(l.c.v) + (l.c.r ? ' · remise ' + fE(l.c.r) : '') };
      }),
      liste: () => {
        app.setState({ prVue: 'liste', prShopF: String(x.m.id), prF: 'toutes', prMenu: null });
        const sc = document.getElementById('main-scroll');
        if (sc) { sc.scrollTop = 0; }
      } };
  });
  T.notes.push('% du CA du magasin : ce que la promotion a vendu dans le magasin ÷ ses ventes lues · % du CA général : ÷ les ventes lues des ' + mags.length + ' magasins (' + fE(CAG) + ') · ventes : ce que le client paie pour les pièces en promotion · les promotions à venir, les brouillons et les autres sont dans la liste.');
}

/* --- L'édition -------------------------------------------------------------------------------------------------------- */
export function valsPromoEdition(app, common){
  promosCharge(app, false);
  const S = app.state, d = (app._prR || {}).d;
  // Le magasin qui a ouvert le cadre (peSrcShop) ne bouge que sur une demande du cockpit : changer de magasin DANS
  // l'assistant (peShop) ne recharge pas le cadre, la promotion en cours le suit.
  if (!S.peSrcShop) { S.peSrcShop = S.peShop || magasinParDefaut(app); }
  if (!S.peShop) { S.peShop = S.peSrcShop; }
  const E = common.prE = {};
  E.src = 'dashboard/?embed=promos&shop=' + encodeURIComponent(S.peSrcShop) + '&ouvrir=' + encodeURIComponent(S.peOuvrir || 'nouvelle') + '&n=' + (S.peN || 0);
  E.haut = app._peHaut || 900;
  const mags = d ? d.magasins || [] : [];
  const nomMag = id => (mags.find(m => +m.id === +id) || {}).nom || 'magasin ' + id;
  E.brouillons = d ? (d.promos || []).filter(p => p.src === 'mag' && p.etat === 'brouillon')
    .map(p => ({ nom: p.nom || 'Sans nom', mag: nomMag(p.shop), go: () => promoEditer(app, { shop: p.shop, ouvrir: 'p' + p.id }) })) : [];
  E.chargement = d === undefined;
  E.tableau = () => app.setState({ screen: 'promoTableau' });
  peEcoute(app);
}
/** Les messages du cadre (une fois pour toutes). */
function peEcoute(app){
  if (app._peEcoute) { return; }
  app._peEcoute = true;
  window.addEventListener('message', e => {
    const m = e.data;
    if (e.origin !== location.origin || !m || m.source !== 'atelier-promos') { return; }
    if (m.type === 'enregistree') { app._peOuvert = false; promosCharge(app, true); app.setState({}); }
    else if (m.type === 'magasin') { app.setState({ peShop: String(m.shop || '') }); }
    else if (m.type === 'tableau') { app._peOuvert = false; app.setState({ screen: 'promoTableau' }); }
    else if (m.type === 'etat') { app._peOuvert = !!m.ouvert; }
    else if (m.type === 'haut') {
      const f = document.getElementById('pe-cadre'), sc = document.getElementById('main-scroll');
      if (!f || !sc) { return; }
      const y = f.getBoundingClientRect().top - sc.getBoundingClientRect().top + sc.scrollTop - 16;
      if (sc.scrollTop > y) { sc.scrollTop = Math.max(0, y); }
    }
  });
}
/** Après le rendu : la hauteur du cadre suit son contenu (même origine : on la lit directement). */
export function peMonter(app){
  const f = document.getElementById('pe-cadre');
  if (!f || f._peSuivi) { return; }
  f._peSuivi = true;
  const suivre = () => {
    let doc = null;
    try { doc = f.contentDocument; } catch (e) { doc = null; }
    const racine = doc && (doc.getElementById('dash') || doc.body);
    if (!racine) { return; }
    const mesure = () => {
      const h = Math.max(480, Math.ceil(racine.getBoundingClientRect().height) + 8);
      if (Math.abs(h - (app._peHaut || 0)) > 2) { app._peHaut = h; f.style.height = h + 'px'; }
    };
    if (f._peRO) { f._peRO.disconnect(); }
    f._peRO = new ResizeObserver(mesure);
    f._peRO.observe(racine);
    mesure();
  };
  f.addEventListener('load', suivre);
  suivre();
}
