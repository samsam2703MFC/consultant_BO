/* Cockpit CEO — ERP franchisé › Fidélité (#/fidelity, 24/10/2026). Maquettes : docs/maquettes/fidelite,
 * retenu : le Programme de la maquette C, les Clients de la maquette B.
 *
 * Programme : les règles s'écrivent en phrases dont les valeurs se modifient sur place, en quatre blocs
 * (on gagne, ça compte double, on reçoit, garde-fous). Une modification reste un brouillon jusqu'à
 * « Publier » (POST /fidelite/regles, tout ou rien). À droite, ce que l'app du client montre, trois clients
 * types (GET /fidelite → profils) recalculés sur les brouillons, et le budget estimé.
 *
 * Clients : GET /fidelite/clients — récence × fréquence (une case choisit son segment), le segment ouvert,
 * d'un mois à l'autre, ce qu'achète chaque segment. Sans visite carte en base, le serveur calcule sur un jeu
 * d'essai et le dit (`test`) : l'écran l'affiche.
 *
 * Remplit `common.fid` ; le gabarit est tplFidelite (fidelite-tpl.js). */
import { readOne } from './api.js';

const nf = (n, d) => n == null ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).replace(/ /g, ' ');
const fE = (n, d) => n == null ? '—' : nf(n, d) + ' €';
const pc = n => n == null ? '—' : nf(n) + ' %';
const JOURS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
const FAMILLES = [['', 'tous les achats'], ['pain', 'le pain'], ['petitdej', 'le petit-déjeuner'], ['lunch', 'le lunch'], ['gouter', 'le goûter'], ['occasion', 'l’occasion']];
const MULTS = [[2, '×2'], [3, '×3'], [4, '×4'], [5, '×5']];
const EVENEMENTS = [['inscription', 'à l’inscription'], ['visite2', 'à la 2e visite'], ['anniversaire', 'à l’anniversaire'], ['visiteN', 'à la n-ième visite du mois'], ['panier', 'pour un gros panier'], ['parrainage', 'au parrainage'], ['absence', 'après une absence']];
const COULEURS = ['#9a5b16', '#eb6834', '#5c4033', '#c2336a', '#2a78d6', '#1baf7a', '#6250d6', '#8D1D2C'];
const UNIQUES = ['base', 'jauge', 'plafond', 'cumul', 'unbon', 'expiration', 'exclus', 'budget'];
const BLOCS = [
  ['gain', 'On gagne des points', 'la base, sur tous les canaux'],
  ['multi', 'Ça compte double', 'le jour, l’heure, la gamme, l’anniversaire'],
  ['recoit', 'On reçoit', 'la jauge, les cartes à tampons, les déclencheurs'],
  ['garde', 'Garde-fous', 'ce qui empêche le programme de coûter trop'],
];
const BLOC_DE = { base: 'gain', panier: 'gain', creneau: 'multi', gamme: 'multi', anniversaire: 'multi', jauge: 'recoit', tampons: 'recoit', declencheur: 'recoit', plafond: 'garde', cumul: 'garde', unbon: 'garde', expiration: 'garde', exclus: 'garde', budget: 'garde' };
const NOUVELLES = {
  panier: () => ({ min: 30, points: 50 }),
  creneau: () => ({ mult: 2, jours: [1, 2, 3], de: '14:00', a: '17:00', famille: '', pourquoi: '' }),
  gamme: () => { const d = new Date(); const iso = x => x.toISOString().slice(0, 10); const f = new Date(d); f.setDate(f.getDate() + 30); return { mult: 2, gamme: 'Nouvelle gamme', du: iso(d), au: iso(f) }; },
  tampons: n => ({ nom: 'Nouvelle carte', unite: 'articles achetés', n: 10, recompense: '1 article offert', couleur: COULEURS[n % COULEURS.length] }),
  declencheur: ev => ({ evenement: ev, n: { inscription: 8, visite2: 14, visiteN: 10, panier: 30, absence: 30 }[ev] || 0, recompense: ev === 'anniversaire' || ev === 'visiteN' ? { type: 'produit', valeur: 0, libelle: 'un produit offert' } : (ev === 'visite2' || ev === 'parrainage' || ev === 'panier' ? { type: 'points', valeur: 50, libelle: '' } : { type: 'bon', valeur: 3, libelle: '' }), validite: 30 }),
};
const copie = o => JSON.parse(JSON.stringify(o));
/** « 14 », « 14h30 », « 1430 », « 14:30 » → « 14:30 » ; sinon null. */
const heure = v => { const m = String(v || '').trim().match(/^(\d{1,2})(?:\s*[:hH.]\s*|)(\d{2})?$/); if (!m) { return null; } const h = +m[1], mi = +(m[2] || 0); return h < 24 && mi < 60 ? String(h).padStart(2, '0') + ':' + String(mi).padStart(2, '0') : null; };

/* --- Lectures ------------------------------------------------------------------------------------ */
function fidCharge(app, quoi, force){
  const S = app.state, shop = S.fidShop || '';
  const cle = quoi + '|' + shop, k = quoi === 'p' ? 'fidP' : 'fidC', b = S[k];
  if (!force && b && b.cle === cle && (b.d || b.chargement)) { return; }
  if (app['_' + k] === cle && !force) { return; }
  app['_' + k] = cle;
  const garde = b && b.cle === cle ? b.d : null;
  app.setState({ [k]: { cle, chargement: true, d: garde } });
  readOne((quoi === 'p' ? '/fidelite' : '/fidelite/clients') + (shop ? '?shop=' + encodeURIComponent(shop) : '')).then(d => {
    app['_' + k] = null;
    app.setState({ [k]: { cle, chargement: false, d: d || { error: 'injoignable' } } });
  });
}

/* --- Brouillons ------------------------------------------------------------------------------------ */
/** Les règles telles qu'elles seront publiées : la base du serveur, les brouillons par-dessus. */
function reglesVues(app, d){
  const B = app.state.fidBrouillon || {};
  const L = (d.regles || []).map(r => {
    const b = B[r.id];
    return b ? Object.assign({}, r, { params: b.params, shop: b.shop, actif: b.actif, supprimer: !!b.supprimer, modifiee: true }) : Object.assign({}, r, { modifiee: false });
  });
  (app.state.fidNouvelles || []).forEach(n => L.push(Object.assign({}, n, { nouvelle: true, modifiee: true })));
  return L;
}
function brouillon(app, r, patch){
  if (r.nouvelle) {
    app.setState({ fidNouvelles: (app.state.fidNouvelles || []).map(n => n.id === r.id ? Object.assign({}, n, patch) : n) });
    return;
  }
  const B = Object.assign({}, app.state.fidBrouillon || {});
  const cur = B[r.id] || { params: copie(r.params), shop: r.shop, actif: r.actif, supprimer: false };
  B[r.id] = Object.assign({}, cur, patch);
  app.setState({ fidBrouillon: B });
}
function setParam(app, r, chemin, val){
  const p = copie(r.params); const k = chemin.split('.');
  let o = p; for (let i = 0; i < k.length - 1; i++) { o[k[i]] = o[k[i]] || {}; o = o[k[i]]; }
  o[k[k.length - 1]] = val;
  brouillon(app, r, { params: p });
}
function ajouter(app, type, arg){
  const L = app.state.fidNouvelles || [];
  const n = (app.state.fidNouvellesN || 0) + 1;
  const tamp = ((app.state.fidP || {}).d && app.state.fidP.d.regles || []).filter(r => r.type === 'tampons').length + L.filter(r => r.type === 'tampons').length;
  const params = type === 'tampons' ? NOUVELLES.tampons(tamp) : type === 'declencheur' ? NOUVELLES.declencheur(arg) : NOUVELLES[type]();
  app.setState({ fidNouvellesN: n, fidOuvert: 'n' + n, fidNouvelles: L.concat([{ id: 'n' + n, type, bloc: BLOC_DE[type], params, shop: app.state.fidShop || '', actif: true }]) });
}
function annuler(app){ app.setState({ fidBrouillon: {}, fidNouvelles: [], fidMsg: null }); }
function publier(app){
  const S = app.state, d = S.fidP && S.fidP.d;
  if (!d || S.fidEnvoi) { return; }
  const L = [];
  Object.keys(S.fidBrouillon || {}).forEach(id => { const b = S.fidBrouillon[id]; L.push(b.supprimer ? { id: +id, supprimer: true } : { id: +id, params: b.params, shop: b.shop, actif: b.actif }); });
  (S.fidNouvelles || []).forEach(n => L.push({ type: n.type, params: n.params, shop: n.shop, actif: n.actif }));
  if (!L.length) { return; }
  app.setState({ fidEnvoi: true, fidMsg: null });
  app.api('POST', '/fidelite/regles', { regles: L, qui: 'Cockpit' }).then(r => {
    const ok = r && r.ok !== false;
    app.setState({ fidEnvoi: false, fidMsg: ok ? { txt: 'Règles publiées : ' + r.n + ' modification' + (r.n > 1 ? 's' : '') + '.', ko: false } : { txt: 'Publication refusée : ' + ((r && r.error) || 'sans réponse'), ko: true } });
    if (ok) { app.setState({ fidBrouillon: {}, fidNouvelles: [] }); fidCharge(app, 'p', true); fidCharge(app, 'c', true); }
  });
}
/** Une règle modifiée et publiée d'un geste (bouton d'une action). */
function publierUne(app, r, params, msg){
  app.setState({ fidEnvoi: true });
  app.api('POST', '/fidelite/regles', { regles: [{ id: r.id, params, shop: r.shop, actif: r.actif }], qui: 'Cockpit' }).then(x => {
    app.setState({ fidEnvoi: false, fidMsg: x && x.ok !== false ? { txt: msg, ko: false } : { txt: 'Refusé : ' + ((x && x.error) || 'sans réponse'), ko: true } });
    fidCharge(app, 'p', true);
  });
}

/* --- Les phrases ---------------------------------------------------------------------------------- */
const T = v => ({ t: 'txt', v });
const P = (v, set, w, u, ex) => Object.assign({ t: 'num', v, set, w: w || 46, u: u || '' }, ex || {});
const X = (v, set, w, ph) => ({ t: 'txtin', v, set, w: w || 140, ph: ph || '' });
const S_ = (v, opts, set) => ({ t: 'sel', v, opts, set });
function recompense(app, r){
  const rc = r.params.recompense || { type: 'bon', valeur: 2 };
  const out = [S_(rc.type, [['bon', 'un bon de'], ['points', 'des points :'], ['produit', 'un produit :']], e => setParam(app, r, 'recompense', Object.assign({}, rc, { type: e.target.value, valeur: e.target.value === 'points' ? 50 : 3, libelle: e.target.value === 'produit' ? 'un produit offert' : '' })))];
  if (rc.type === 'bon') { out.push(P(rc.valeur, e => setParam(app, r, 'recompense.valeur', +e.target.value), 52, '€', { pas: 0.5, cls: 'bon' })); }
  if (rc.type === 'points') { out.push(T('+'), P(rc.valeur, e => setParam(app, r, 'recompense.valeur', +e.target.value), 56, 'points', { cls: 'pts' })); }
  if (rc.type === 'produit') { out.push(X(rc.libelle, e => setParam(app, r, 'recompense.libelle', e.target.value), 170, 'ce qui est offert')); }
  return out;
}
function valable(app, r){
  return r.params.recompense && r.params.recompense.type === 'points' ? [] : [T(', valable'), P(r.params.validite, e => setParam(app, r, 'validite', +e.target.value), 46, 'jours')];
}
function phrase(app, r){
  const p = r.params, num = (k, w, u, ex) => P(p[k], e => setParam(app, r, k, e.target.value === '' ? '' : +e.target.value), w, u, ex);
  const mult = () => S_(p.mult, MULTS, e => setParam(app, r, 'mult', +e.target.value));
  switch (r.type) {
    case 'base': return [T('Chaque'), num('euros', 46, '€', { pas: 0.5 }), T('dépensé rapporte'), num('points', 42, p.points > 1 ? 'points' : 'point'), T(', en boutique, en click & collect et en livraison.')];
    case 'panier': return [T('Un panier de'), num('min', 52, '€'), T('et plus rapporte'), T('+'), num('points', 52, 'points'), T('.')];
    case 'creneau': return [T('Les points comptent'), mult(), T('le'), { t: 'jours', v: p.jours, basculer: i => setParam(app, r, 'jours', p.jours.includes(i) ? p.jours.filter(x => x !== i) : p.jours.concat([i]).sort()) },
      T('de'), { t: 'heure', v: p.de, set: e => { const h = heure(e.target.value); if (h) { setParam(app, r, 'de', h); } else { e.target.value = p.de; } } }, T('à'), { t: 'heure', v: p.a, set: e => { const h = heure(e.target.value); if (h) { setParam(app, r, 'a', h); } else { e.target.value = p.a; } } },
      T(', sur'), S_(p.famille, FAMILLES, e => setParam(app, r, 'famille', e.target.value)), T('—'), X(p.pourquoi, e => setParam(app, r, 'pourquoi', e.target.value), 200, 'pourquoi ce créneau ?')];
    case 'gamme': return [T('Les points comptent'), mult(), T('sur la gamme'), X(p.gamme, e => setParam(app, r, 'gamme', e.target.value), 130), T('du'), { t: 'date', v: p.du, set: e => setParam(app, r, 'du', e.target.value) }, T('au'), { t: 'date', v: p.au, set: e => setParam(app, r, 'au', e.target.value) }, T('.')];
    case 'anniversaire': return [T('Les points comptent'), mult(), T('le jour de son anniversaire, et'), num('jours', 40, 'jours'), T('avant ou après.')];
    case 'jauge': return [T('Tous les'), num('points', 56, 'points'), T(', le client reçoit un bon de'), num('valeur', 50, '€', { pas: 0.5, cls: 'bon' }), T('valable'), num('validite', 46, 'jours'), T('.')];
    case 'tampons': return [{ t: 'pastille', v: p.couleur }, X(p.nom, e => setParam(app, r, 'nom', e.target.value), 140), T(':'), num('n', 40), X(p.unite, e => setParam(app, r, 'unite', e.target.value), 170), T('='), X(p.recompense, e => setParam(app, r, 'recompense', e.target.value), 170, 'la récompense')];
    case 'declencheur': {
      const n = (w, u) => num('n', w, u);
      const debut = { inscription: [T('Quand un client s’inscrit, il reçoit')], visite2: [T('Quand il revient dans les'), n(40, 'jours'), T('après sa 1re visite, il reçoit')],
        anniversaire: [T('Le jour de son anniversaire, il reçoit')], visiteN: [T('À sa'), n(40, 'e'), T('visite du mois, il reçoit')], panier: [T('Quand son panier atteint'), n(52, '€'), T(', il reçoit')],
        parrainage: [T('Quand il parraine un ami, il reçoit')], absence: [T('Quand un régulier n’est plus venu depuis'), n(46, 'jours'), T(', il reçoit')] }[p.evenement] || [];
      const fin = p.evenement === 'inscription' ? [T('dès'), n(46, '€'), T('d’achat')] : [];
      return debut.concat(recompense(app, r), fin, valable(app, r), [T('.')]);
    }
    case 'plafond': return [T('Pas plus de'), num('points', 56, 'points'), T('bonus par jour et par client.')];
    case 'cumul': return [T('Les multiplicateurs ne se cumulent pas : le plus fort l’emporte.')];
    case 'unbon': return [T('Un bon par ticket ; bon fidélité et promotion jours creux ne se cumulent pas.')];
    case 'expiration': return [T('Les points s’effacent après'), num('mois', 40, 'mois'), T('sans visite ; le client est prévenu'), num('preavis', 40, 'jours'), T('avant.')];
    case 'exclus': return [T('Aucun multiplicateur les'), X(p.dates, e => setParam(app, r, 'dates', e.target.value), 150), T('(jours déjà pleins).')];
    case 'budget': return [T('Le programme ne coûte pas plus de'), num('pct', 46, '%', { pas: 0.5 }), T('du chiffre des clients carte.')];
  }
  return [T(r.type)];
}

/* --- Une règle en une ligne : titre court, résumé en clair, icône ---------------------------------- */
const JOURS_M = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];
const joursTxt = j => {
  const s = (j || []).slice().sort((a, c) => a - c);
  if (s.length === 7) { return 'tous les jours'; }
  if (s.length > 2 && s[s.length - 1] - s[0] === s.length - 1) { return 'du ' + JOURS_M[s[0]] + ' au ' + JOURS_M[s[s.length - 1]]; }
  return s.length === 1 ? 'le ' + ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'][s[0]] : s.map(i => JOURS_M[i]).join(', ');
};
const dateTxt = d => d ? d.slice(8, 10) + '/' + d.slice(5, 7) : '…';
const recTxt = rc => !rc ? '' : rc.type === 'bon' ? 'bon de ' + fE(rc.valeur, rc.valeur % 1 ? 2 : 0) : rc.type === 'points' ? '+' + nf(rc.valeur) + ' points' : rc.libelle;
const IC = { euro: ['€', '#8D1D2C'], mult: ['×', '#E8964B'], bon: ['€', '#1d1d1f'], decl: ['✦', '#5856d6'], garde: ['!', '#8e8e93'] };
function enClair(r){
  const p = r.params, rc = p.recompense, val = rc && rc.type !== 'points' && p.validite ? ' · valable ' + p.validite + ' j' : '';
  switch (r.type) {
    case 'base': return ['Points par euro', fE(p.euros, p.euros % 1 ? 2 : 0) + ' = ' + nf(p.points) + ' point' + (p.points > 1 ? 's' : '') + ' · boutique, click & collect, livraison', IC.euro];
    case 'panier': return ['Bonus panier', 'dès ' + fE(p.min) + ' : +' + nf(p.points) + ' points', IC.euro];
    case 'creneau': return ['×' + p.mult + ' ' + joursTxt(p.jours) + ', ' + p.de + ' – ' + p.a, (p.famille ? 'sur ' + (FAMILLES.find(x => x[0] === p.famille) || ['', ''])[1] : 'tous les achats') + (p.pourquoi ? ' · ' + p.pourquoi : ''), ['×' + p.mult, IC.mult[1]]];
    case 'gamme': return ['×' + p.mult + ' sur la gamme ' + p.gamme, 'du ' + dateTxt(p.du) + ' au ' + dateTxt(p.au), ['×' + p.mult, IC.mult[1]]];
    case 'anniversaire': return ['×' + p.mult + ' pour l’anniversaire', 'le jour même' + (p.jours ? ', et ' + p.jours + ' jours avant ou après' : ''), ['×' + p.mult, IC.mult[1]]];
    case 'jauge': return ['Bon d’achat', 'un bon de ' + fE(p.valeur, p.valeur % 1 ? 2 : 0) + ' tous les ' + nf(p.points) + ' points · valable ' + p.validite + ' j', IC.bon];
    case 'tampons': return [p.nom, p.n + ' ' + p.unite + ' = ' + p.recompense, ['●', p.couleur]];
    case 'declencheur': {
      const t = { inscription: ['Bienvenue', 'à l’inscription : ' + recTxt(rc) + ' dès ' + fE(p.n) + ' d’achat'], visite2: ['Deuxième visite', 'revenu dans les ' + p.n + ' jours : ' + recTxt(rc)],
        anniversaire: ['Anniversaire', recTxt(rc)], visiteN: [p.n + 'e visite du mois', recTxt(rc)], panier: ['Gros panier', 'dès ' + fE(p.n) + ' : ' + recTxt(rc)],
        parrainage: ['Parrainage', recTxt(rc) + ' au parrain'], absence: ['Retour après ' + p.n + ' jours', 'un régulier absent reçoit ' + recTxt(rc)] }[p.evenement] || [p.evenement, ''];
      return [t[0], t[1] + val, IC.decl];
    }
    case 'plafond': return ['Plafond des bonus', nf(p.points) + ' points bonus par jour et par client', IC.garde];
    case 'cumul': return ['Pas de cumul', 'le multiplicateur le plus fort l’emporte', IC.garde];
    case 'unbon': return ['Un bon par ticket', 'pas de cumul avec les promotions jours creux', IC.garde];
    case 'expiration': return ['Expiration des points', 'après ' + p.mois + ' mois sans visite · prévenu ' + p.preavis + ' jours avant', IC.garde];
    case 'exclus': return ['Jours sans multiplicateur', p.dates, IC.garde];
    case 'budget': return ['Budget', 'au plus ' + nf(p.pct, 1) + ' % du chiffre des clients carte', IC.garde];
  }
  return [r.type, '', IC.garde];
}

/* --- Programme ------------------------------------------------------------------------------------ */
function valsProgramme(app, f){
  const S = app.state, b = S.fidP || {}, d = b.d && !b.d.error ? b.d : null;
  f.chargement = !d && !!b.chargement;
  f.indispo = !b.chargement && b.d && !d ? 'Lecture impossible — API injoignable (GET /fidelite).' : '';
  if (!d) { return; }
  f.test = d.test; f.source = d.source;
  const L = reglesVues(app, d);
  const mags = d.magasins || [];
  const nomMag = id => (mags.find(m => m.id === id) || {}).nom || id;
  const stats = (d.stats || {}).regles || {};
  const shop = S.fidShop || '';
  const vues = L.filter(r => !shop || r.shop === '' || r.shop === shop);
  f.nbModifs = Object.keys(S.fidBrouillon || {}).length + (S.fidNouvelles || []).length;
  f.publier = f.nbModifs ? () => publier(app) : null;
  f.annuler = f.nbModifs ? () => annuler(app) : null;
  f.envoi = !!S.fidEnvoi;
  f.blocs = BLOCS.map(([k, titre, sous], i) => {
    const R = vues.filter(r => r.bloc === k);
    const ajouts = k === 'gain' ? [['Ajouter un bonus panier', () => ajouter(app, 'panier')]]
      : k === 'multi' ? [['Ajouter un créneau', () => ajouter(app, 'creneau')], ['Ajouter une gamme', () => ajouter(app, 'gamme')]]
        : k === 'recoit' ? [['Ajouter une carte à tampons', () => ajouter(app, 'tampons')]] : [];
    return { n: i + 1, titre, sous, ajouts,
      declencheurs: k === 'recoit' ? { opts: [['', 'Ajouter un déclencheur…']].concat(EVENEMENTS), choisir: e => { if (e.target.value) { ajouter(app, 'declencheur', e.target.value); } } } : null,
      regles: R.map(r => {
        const st = stats[r.id];
        const unique = UNIQUES.includes(r.type);
        const portee = r.type === 'base' || r.bloc === 'garde' ? null
          : { v: r.shop, opts: [['', 'Réseau']].concat(mags.map(m => [m.id, m.nom])), set: e => brouillon(app, r, { shop: e.target.value }), nom: r.shop ? nomMag(r.shop) : 'Réseau' };
        const [titre, resume, ic] = enClair(r);
        const ouvert = String(S.fidOuvert) === String(r.id);
        return { id: r.id, titre, resume: resume + (r.shop ? ' · ' + nomMag(r.shop) : ''), ic: { txt: ic[0], couleur: ic[1] }, ouvert,
          ouvrir: () => app.setState({ fidOuvert: ouvert ? null : r.id }), fermer: () => app.setState({ fidOuvert: null }),
          morceaux: ouvert ? phrase(app, r) : [], actif: r.actif, supprimer: r.supprimer, nouvelle: !!r.nouvelle, modifiee: r.modifiee, portee,
          stat: r.nouvelle ? 'nouvelle' : r.supprimer ? 'retirée à la publication' : r.modifiee ? 'modifiée' : (st ? st.txt : ''),
          statCls: r.modifiee ? 'mu' : (st && st.cls === 'alerte' ? 'ko' : (st && /^\+|tickets|paniers/.test(st.txt) ? '' : 'mu')),
          basculer: r.type === 'base' ? null : () => brouillon(app, r, { actif: !r.actif }),
          retirer: unique ? null : (r.nouvelle ? () => app.setState({ fidNouvelles: (S.fidNouvelles || []).filter(n => n.id !== r.id) }) : () => brouillon(app, r, { supprimer: !r.supprimer })) };
      }) };
  });
  // L'aperçu de l'app, sur les règles telles qu'elles seront publiées.
  const act = L.filter(r => r.actif && !r.supprimer);
  const g = (act.find(r => r.type === 'jauge') || { params: { points: 150, valeur: 5, validite: 60 } }).params;
  const base = (act.find(r => r.type === 'base') || { params: { euros: 1, points: 1 } }).params;
  const now = d.maintenant || { jour: 0, heure: '12:00' };
  const cren = act.filter(r => r.type === 'creneau' && r.params.jours.includes(now.jour) && (!shop || !r.shop || r.shop === shop)).sort((a, c) => c.params.mult - a.params.mult)[0];
  const solde = Math.round(g.points * 0.85);
  const fin = new Date(); fin.setDate(fin.getDate() + (+g.validite || 60));
  const cadeau = act.find(r => r.type === 'declencheur' && r.params.recompense && r.params.recompense.type === 'produit');
  f.app = { solde, jauge: g.points, pct: Math.round(100 * solde / g.points), reste: g.points - solde, bon: fE(g.valeur, g.valeur % 1 ? 2 : 0),
    bonFin: String(fin.getDate()).padStart(2, '0') + '/' + String(fin.getMonth() + 1).padStart(2, '0'),
    creneau: cren ? 'Aujourd’hui de ' + cren.params.de + ' à ' + cren.params.a + ' : vos points comptent ' + (cren.params.mult === 2 ? 'double' : cren.params.mult === 3 ? 'triple' : '×' + cren.params.mult) + (cren.params.famille ? ' sur ' + (FAMILLES.find(x => x[0] === cren.params.famille) || ['', ''])[1] : '') : '',
    cartes: act.filter(r => r.type === 'tampons').slice(0, 3).map((r, i) => ({ nom: r.params.nom, n: r.params.n, faits: Math.min(r.params.n - 1, [7, 4, 5][i] || 3), couleur: r.params.couleur })),
    cadeau: cadeau ? cadeau.params.recompense.libelle : '' };
  // Le simulateur : trois clients types (les médianes du serveur), recalculés sur les brouillons.
  const ratio = base.points / Math.max(0.5, base.euros || 1);
  const multMax = Math.max(1, ...act.filter(r => r.type === 'creneau').map(r => r.params.mult));
  const bonusPanier = act.filter(r => r.type === 'panier');
  f.profils = (d.profils || []).map(p => {
    const ca = p.visites * p.panier;
    const bonus = bonusPanier.filter(r => p.panier >= r.params.min).reduce((s, r) => s + r.params.points * p.visites, 0);
    const pts = ca * ratio * (1 + p.partCreneau * (multMax - 1)) + bonus;
    const rend = ca > 0 ? 100 * (pts / g.points * g.valeur) / ca : null;
    return { nom: p.nom, mission: p.mission, sous: nf(p.visites) + ' visite' + (p.visites > 1 ? 's' : '') + ' par mois · ' + fE(p.panier, 2) + ' le panier',
      pts: nf(pts), jours: pts > 0 ? nf(30 * g.points / pts) : '—', rend: rend == null ? '—' : nf(rend, 1) + ' %', fort: rend != null && rend > 5 };
  });
  const bud = (d.stats || {}).budget || {};
  const plafond = +((act.find(r => r.type === 'budget') || { params: { pct: bud.plafond || 5 } }).params.pct) || 5;
  f.budget = { cout: fE(bud.cout), ca: fE(bud.ca), pct: bud.pct == null ? '—' : nf(bud.pct, 1) + ' %', plafond: nf(plafond, 1) + ' %',
    largeur: bud.pct == null ? 0 : Math.min(100, 100 * bud.pct / (plafond * 1.25)), repere: 80, depasse: bud.pct != null && bud.pct > plafond,
    hypotheses: bud.hypotheses || '', brouillon: f.nbModifs > 0, part: bud.pct == null ? 0 : Math.min(1, bud.pct / plafond) };
}

/* --- Clients ---------------------------------------------------------------------------------------- */
const SEG_CLS = { H: 'ok', R: 'r', O: 'm', N: 'pts', AR: 'ko', E: '' };
const ARTICLE = ['le pain quotidien', 'le petit-déjeuner', 'le lunch', 'le goûter', 'l’occasion', 'la commande et le bureau'];
const Maj = s => s.charAt(0).toUpperCase() + s.slice(1);
function valsClients(app, f){
  const S = app.state, b = S.fidC || {}, d = b.d && !b.d.error ? b.d : null;
  f.chargementC = !d && !!b.chargement;
  f.indispoC = !b.chargement && b.d && !d ? 'Lecture impossible — API injoignable (GET /fidelite/clients).' : '';
  if (!d) { return; }
  f.test = d.test; f.source = d.source;
  const seg = id => (d.segments || []).find(s => s.id === id) || {};
  const mags = d.magasins || [];
  const K = d.kpis || {};
  const AR = seg('AR');
  f.missions = (d.missions || []).map((m, i) => ({ nom: m.nom, def: m.def, cls: 'm' + (i + 1) }));
  f.tuiles = [
    ['Clients actifs', nf(K.actifs), 'une visite carte sur 30 jours · ' + nf(K.actifsPrec) + ' les 30 jours d’avant'],
    ['Retour à 30 jours', pc(K.retour30), 'des ' + nf(K.nouveauxPrec) + ' nouveaux du mois d’avant'],
    ['Montés', '+ ' + nf(K.montes), 'clients passés à un segment plus fidèle en 30 jours'],
    ['Descendus', '− ' + nf(K.descendus), 'dont ' + nf(((d.transitions || {}).m || [[], []])[1][3]) + ' réguliers devenus à risque'],
    ['À risque', nf(AR.n), fE(AR.caAvantMois) + ' de CA mensuel en jeu', 'fort'],
  ];
  const choisi = S.fidSeg || 'AR';
  const rf = d.rf || { cases: [], segments: [] };
  const mx = Math.max(1, ...rf.cases.flat());
  const nomSeg = { H: 'Habitués', R: 'Réguliers', O: 'Occasionnels', AR: 'À risque', E: 'Endormis' };
  const etiquette = {};
  rf.segments.forEach((ligne, l) => ligne.forEach((s, c) => { if (!etiquette[s] || rf.cases[l][c] > rf.cases[etiquette[s][0]][etiquette[s][1]]) { etiquette[s] = [l, c]; } }));
  f.rf = { lignes: rf.lignes, colonnes: rf.colonnes, nouveaux: nf(seg('N').n),
    cases: rf.cases.map((ligne, l) => ligne.map((n, c) => { const s = rf.segments[l][c], t = Math.sqrt(n / mx);
      return { n: nf(n), t, s, on: s === choisi, ar: s === 'AR', lab: etiquette[s] && etiquette[s][0] === l && etiquette[s][1] === c ? nomSeg[s] : '', titre: nomSeg[s] + ' : ' + nf(n) + ' clients', choisir: () => app.setState({ fidSeg: s }) }; })) };
  f.segPuces = ['H', 'R', 'O', 'N', 'AR', 'E'].map(id => ({ id, nom: seg(id).nom, n: nf(seg(id).n), cls: SEG_CLS[id], on: id === choisi, choisir: () => app.setState({ fidSeg: id }) }));
  // Le segment ouvert.
  const s = seg(choisi), moy = d.mixMoyen || [];
  const ecarts = (s.mix || []).map((v, i) => [i, v - (moy[i] || 0), v]).sort((a, c) => c[1] - a[1]);
  const top = ecarts[0];
  const rs = d.retourSpontane || {};
  const P0 = (S.fidP || {}).d;
  const regles = (P0 && P0.regles) || [];
  const absence = regles.find(r => r.type === 'declencheur' && r.params.evenement === 'absence' && r.actif);
  const lie = { AR: absence, E: absence, H: regles.find(r => r.type === 'declencheur' && r.params.evenement === 'visiteN'), R: regles.find(r => r.type === 'jauge'),
    O: regles.find(r => r.type === 'panier'), N: regles.find(r => r.type === 'declencheur' && r.params.evenement === 'inscription') }[choisi];
  const lieTxt = !lie ? 'aucun — à créer dans le Programme' : lie.type === 'jauge' ? 'jauge ' + nf(lie.params.points) + ' points → bon de ' + fE(lie.params.valeur)
    : lie.type === 'panier' ? 'panier de ' + fE(lie.params.min) + ' → +' + nf(lie.params.points) + ' points'
      : ({ absence: 'bon « on vous attend » à ' + lie.params.n + ' jours', visiteN: lie.params.n + 'e visite du mois → ' + (lie.params.recompense.libelle || 'cadeau'), inscription: 'bienvenue à l’inscription' }[lie.params.evenement] || '');
  let lecture = '';
  if (top && top[1] > 3 && f.missions[top[0]]) { lecture = Maj(ARTICLE[top[0]]) + ' pèse ' + pc(top[2]) + ' de leurs achats contre ' + pc(moy[top[0]]) + ' en moyenne.'; }
  if (choisi === 'AR' && rs['21'] != null && rs['45'] != null) {
    lecture += ' Un régulier absent 21 jours revient seul ' + rs['21'] + ' fois sur 100 ; à 45 jours, ' + rs['45'] + '.' + (absence && absence.params.n > 21 ? ' Le bon part à ' + absence.params.n + ' jours : trop tard pour la moitié d’entre eux.' : '');
  }
  const avancer = choisi === 'AR' && absence && absence.params.n > 21 ? () => publierUne(app, absence, Object.assign(copie(absence.params), { n: 21 }), 'Le bon « on vous attend » part désormais à 21 jours.') : null;
  const topNom = s.topShop ? ((mags.find(m => m.id === s.topShop.id) || {}).nom || s.topShop.id) + ' · ' + nf(s.topShop.n) : '—';
  f.detail = { id: choisi, nom: s.nom, cls: SEG_CLS[choisi], def: s.def, n: nf(s.n), joursSans: s.joursSans == null ? '' : nf(s.joursSans) + ' jours sans visite en moyenne',
    mix: (s.mix || []).map((v, i) => ({ v, cls: 'm' + (i + 1), nom: (f.missions[i] || {}).nom, txt: pc(v) })),
    lignes: [[choisi === 'AR' || choisi === 'E' ? 'CA qu’ils faisaient' : 'CA sur 30 jours', choisi === 'AR' || choisi === 'E' ? fE(s.caAvantMois) + ' / mois' : fE(s.ca30)],
      ['Part du CA carte', pc(s.partCa)], ['Panier · visites', fE(s.panier, 2) + ' · ' + (s.visitesMois == null ? '—' : nf(s.visitesMois, 1)) + ' / mois'],
      [choisi === 'AR' ? 'Magasin le plus touché' : 'Magasin principal', topNom], ['Déclencheur lié', lieTxt]],
    lecture: lecture.trim(), avancer, envoi: !!S.fidEnvoi, versProgramme: () => app.setState({ fidOnglet: 'p' }) };
  // D'un mois à l'autre.
  const tr = d.transitions || { ordre: [], noms: [], m: [] };
  f.trans = { noms: tr.noms, lignes: tr.m.map((r, i) => { const t = r.reduce((a, v) => a + v, 0) || 1;
    return { nom: tr.noms[i], total: nf(r.reduce((a, v) => a + v, 0)), cases: r.map((v, j) => { const p = 100 * v / t; return { txt: p < 0.5 ? '·' : pc(p), sens: i === j ? 'reste' : (j < i ? 'monte' : 'descend'), a: Math.min(0.85, p / 30), titre: tr.noms[i] + ' → ' + tr.noms[j] + ' : ' + nf(v) }; }) }; }) };
  f.flux = tr.m.map((r, i) => { const t = r.reduce((a, v) => a + v, 0) || 1, m = r.filter((v, j) => j < i).reduce((a, v) => a + v, 0), dsc = r.filter((v, j) => j > i).reduce((a, v) => a + v, 0);
    return { nom: tr.noms[i], total: nf(r.reduce((a, v) => a + v, 0)), reste: 100 * r[i] / t, monte: 100 * m / t, descend: 100 * dsc / t, resteTxt: pc(100 * r[i] / t), monteTxt: m ? '↑ ' + pc(100 * m / t) : '', descendTxt: dsc ? '↓ ' + pc(100 * dsc / t) : '' }; });
  const revenus = tr.m.length > 4 ? tr.m[3].slice(0, 3).reduce((a, v) => a + v, 0) + tr.m[4].slice(0, 3).reduce((a, v) => a + v, 0) : 0;
  f.trans.lecture = tr.m.length > 4 ? nf(tr.m[1][3]) + ' réguliers sont passés « à risque » en 30 jours ; ' + nf(revenus) + ' clients à risque ou endormis sont revenus.' : '';
  // Ce qu'achète chaque segment.
  f.mixSeg = ['H', 'R', 'O', 'N', 'AR'].map(id => { const x = seg(id); return { nom: x.nom, panier: fE(x.panier, 2), mix: (x.mix || []).map((v, i) => ({ v, cls: 'm' + (i + 1), titre: ((f.missions[i] || {}).nom || '') + ' ' + pc(v) })) }; });
  const H = seg('H').mix || [], O = seg('O').mix || [];
  const iMax = a => a.indexOf(Math.max(...a));
  f.mixLecture = H.length && O.length ? 'Les habitués viennent d’abord pour ' + ARTICLE[iMax(H)] + ' (' + pc(Math.max(...H)) + ') ; les occasionnels pour ' + ARTICLE[iMax(O)] + ' (' + pc(Math.max(...O)) + ').' : '';
  f.resume = nf(K.tickets30) + ' tickets carte et ' + fE(K.ca30) + ' sur 30 jours · panier ' + fE(K.panier, 2);
}

/* --- Point d'entrée --------------------------------------------------------------------------------- */
export function valsFidelite(app, common){
  const S = app.state;
  const onglet = S.fidOnglet || 'p';
  fidCharge(app, 'p', false);
  if (onglet === 'c') { fidCharge(app, 'c', false); }
  const dP = S.fidP && S.fidP.d && !S.fidP.d.error ? S.fidP.d : null;
  const dC = S.fidC && S.fidC.d && !S.fidC.d.error ? S.fidC.d : null;
  const mags = (dP || dC || {}).magasins || [];
  const shop = S.fidShop || '';
  const f = { onglet, blocs: [], profils: [], msg: S.fidMsg || null, fermerMsg: () => app.setState({ fidMsg: null }),
    ongletP: () => app.setState({ fidOnglet: 'p' }), ongletC: () => app.setState({ fidOnglet: 'c' }),
    nbRegles: dP ? (dP.regles || []).length : null, nbActifs: dC ? nf((dC.kpis || {}).actifs) : null,
    portees: [{ id: '', nom: 'Réseau', on: !shop }].concat(mags.map(m => ({ id: m.id, nom: m.nom, on: m.id === shop }))).map(m => Object.assign(m, { choisir: () => app.setState({ fidShop: m.id }) })),
    rafraichir: () => { fidCharge(app, 'p', true); if (onglet === 'c') { fidCharge(app, 'c', true); } } };
  common.fid = f;
  valsProgramme(app, f);
  if (onglet === 'c') { valsClients(app, f); }
}
