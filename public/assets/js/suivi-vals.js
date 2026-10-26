/* Cockpit CEO — valeurs des écrans « Suivi des franchisés » (24/10/2026).
 * Tableau de bord (/suivi/tableau, /suivi/preparation, /suivi/rapport), Visite sur tablette (visites/),
 * Client mystère (/suivi/msp), Page du franchisé (/suivi/lien, suivi/), Qui fait quoi (/suivi/regles).
 * Chaque fonction reçoit l'application (état, setState, api) et remplit `common.sv`. */
import { readOne, API_BASE } from './api.js';

const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const JC = ['dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam'];
const PLAN_ST = { ouvert: 'ouvert', attente: 'photo reçue', valide: 'validé', reprendre: 'à reprendre', ferme: 'fermé', escalade: 'escaladé', propose: 'proposé', interne: 'interne' };
const PLAN_CLS = { ouvert: 'att', attente: 'info', valide: 'ok', reprendre: 'vio', ferme: '', escalade: 'ko', propose: 'att', interne: '' };
const EXEC = { standards: 'Standards appliqués', raccourcis: 'Quelques raccourcis', ecarts: 'Écarts fréquents' };
const CLIENTS = { satisfaits: 'Clients satisfaits', mitiges: 'Clients mitigés', insatisfaits: 'Clients insatisfaits' };
const DROITS = { preparer: 'Préparer', visiter: 'Visiter', adapter: 'Adapter', envoyer: 'Envoyer', valider: 'Valider', regler: 'Régler' };
// Ce que voit le franchisé sur sa page (réglage `voit`) ; « chaque jour » et le journal viennent de l'ancienne page « Mon suivi ».
const VOIT = [['points', 'ses points à travailler et leurs photos'], ['journalier', 'ses chiffres de chaque jour : tâches, notes, infractions, invendus, réclamations, objectifs, avis, comptoir'],
  ['rapports', 'ses rapports de visite'], ['prochaine', 'sa prochaine visite annoncée'], ['msp', 'ses rapports client mystère'], ['scoring', 'son scoring du trimestre'], ['journal', 'le journal des 60 jours']];
const BLOCS_LIB = { sante: 'Fiche franchisé · santé', points: 'Plans d’action', taches: 'Tâches et contrôles photo', scoring: 'Scoring du trimestre', msp: 'Client mystère · comptoir', meteo: 'Fin de visite · météo' };
const auj = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
const fmtD = d => d ? String(d).slice(8, 10) + '/' + String(d).slice(5, 7) : '';
const fmtDJ = d => { if (!d) { return ''; } const t = new Date(String(d).slice(0, 10) + 'T12:00:00'); return JC[t.getDay()] + ' ' + fmtD(d); };
const fmtDH = d => d ? fmtDJ(d) + ' ' + String(d).slice(11, 16) : '';
const plusJours = (d, n) => { const t = new Date(d + 'T12:00:00'); t.setDate(t.getDate() + n); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0'); };
const nf = (n, dd) => n == null ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: dd || 0, maximumFractionDigits: dd || 0 });
const pl = (n, u, p) => nf(n) + ' ' + u + (n > 1 ? (p || 's') : '');
const duree = h => h == null ? '' : (Math.abs(h) < 1 ? Math.round(Math.abs(h) * 60) + ' min' : nf(Math.abs(h), Math.abs(h) < 10 ? 1 : 0) + ' h');
const racine = () => API_BASE.replace(/api\/cockpit\/?$/, '');
const fin = t => /\.$/.test(String(t)) ? String(t) : t + '.';
const ASSIGNE = { franchise: 'le franchisé', equipe: 'l’équipe', consultant: 'le consultant', admin: 'le bureau' };

/* Le QR code d'une visite (26/10/2026) : scanné avec la tablette, il ouvre le contrôle de CETTE visite
 * (controle/#controle/{id}). Dessiné en SVG par qrcode-generator (assets/vendor/qrcode, MIT), chargé à la
 * première fiche qui en a besoin ; l'écran se redessine quand il arrive. */
let qrCharge = null;
function qrSvg(app, texte){
  if (typeof window !== 'undefined' && typeof window.qrcode === 'function') {
    try { const q = window.qrcode(0, 'M'); q.addData(texte); q.make(); return q.createSvgTag({ cellSize: 4, margin: 2, scalable: true, alt: 'QR code : ' + texte }); } catch (e) { return null; }
  }
  if (!qrCharge && typeof document !== 'undefined') {
    qrCharge = new Promise(res => { const sc = document.createElement('script'); sc.src = racine() + 'assets/vendor/qrcode/qrcode.js'; sc.onload = res; sc.onerror = res; document.head.appendChild(sc); })
      .then(() => app.setState({ svQr: Date.now() }));
  }
  return null;
}
function lienControle(id){
  const base = typeof location !== 'undefined' ? new URL(racine(), location.href).href : racine();
  return base.replace(/\/?$/, '/') + 'controle/#controle/' + encodeURIComponent(String(id));
}

/* --- lectures et messages ------------------------------------------------------------ */
function charge(app, cle, path, force){
  const b = app.state[cle];
  if (!force && b && b.path === path && (b.d || b.chargement)) { return; }
  app._svEnCours = app._svEnCours || {};
  if (!force && app._svEnCours[cle] === path) { return; }
  app._svEnCours[cle] = path;
  app.setState({ [cle]: { path, chargement: true, d: b && b.path === path ? b.d : null } });
  readOne(path).then(d => { if (app._svEnCours[cle] === path) { app._svEnCours[cle] = null; } app.setState({ [cle]: { path, chargement: false, d: d || { error: 'injoignable' } } }); });
}
function lu(app, cle, path){ const b = app.state[cle]; return b && b.path === path ? b.d : null; }
function message(app, txt, ko){ app.setState({ svMsg: { txt, ko: !!ko } }); setTimeout(() => { if (app.state.svMsg && app.state.svMsg.txt === txt) { app.setState({ svMsg: null }); } }, 8000); }
function relireTableau(app){ const t = app.state.svT; if (t && t.path) { charge(app, 'svT', t.path, true); } }

/* --- 1. Visites : l'assistant (26/10/2026, maquette C choisie par Sam) ------------------------------
 * Une seule page, sans tableau de bord : on arrive sur « Créer une visite ». À gauche, les étapes en accordéon —
 * avant la visite (1 le magasin, 2 la visite, 3 le dossier, 4 l'envoi), pendant (la tablette), après (5 le rapport,
 * 6 les corrections) ; celles qui sont faites se replient sur une ligne, l'étape en cours s'ouvre. À droite, la fiche
 * de la visite se remplit à chaque choix : qui reçoit quoi, l'ordre du jour tel qu'il partira, la suite. Chaque visite
 * qui attend quelque chose du bureau garde son onglet (dossier à finir, sur la tablette, rapport à adapter,
 * corrections). Mêmes routes qu'avant : /suivi/tableau, POST /visites, PUT /visites/{id}, /suivi/preparation,
 * /suivi/rapport, PUT /plans/{id}. */
const ETAPES = [['magasin', 'Le magasin', 'avant'], ['visite', 'La visite', 'avant'], ['dossier', 'Le dossier', 'avant'], ['envoi', 'L’envoi', 'avant'],
  ['tablette', 'La visite sur place', 'pendant'], ['rapport', 'Le rapport', 'apres'], ['corrections', 'Les corrections', 'apres']];
const NUMERO = { magasin: 1, visite: 2, dossier: 3, envoi: 4, tablette: null, rapport: 5, corrections: 6 };
const typeSous = t => t ? t.duree + ' min · ' + (t.dynamique === 'plans' ? 'checklist : les plans ouverts' : t.dynamique === 'ko' ? 'checklist : les non-conformes de la dernière visite' : 'checklist de ' + pl(t.points, 'point')) : '';

export function valsSvTableau(app, common){
  const S = app.state;
  const path = '/suivi/tableau?consultant=&semaine=0';
  charge(app, 'svT', path, false);
  const d = lu(app, 'svT', path);
  const sv = { msg: S.svMsg || null, chargement: !d, indispo: d && d.error ? 'Les visites ne se lisent pas : ' + d.error : null };
  sv.apercu = S.svApercu || null; sv.fermerApercu = () => app.setState({ svApercu: null });
  common.sv = sv;
  if (!d || d.error) { return; }
  const shops = d.franchises || []; const types = (d.types || []).filter(t => t.actif !== false);
  const shopDe = id => shops.find(f => String(f.id) === String(id)) || null;
  const consNom = id => ((d.consultants || []).find(c => c.id === id) || {}).nom || '';
  // Les visites qui attendent le bureau : chacune son onglet, la plus pressée d'abord.
  const corrPar = {}; (d.corrections || []).forEach(p => { (corrPar[p.shop] = corrPar[p.shop] || []).push(p); });
  const attente = [];
  d.cartes.adapter.forEach(c => attente.push({ id: c.id, court: c.court, quoi: 'rapport à adapter', echeance: c.delai ? (c.delai.depasse ? 'délai dépassé' : 'avant ' + fmtDH(c.delai.limite)) : '', urgent: !!(c.delai && (c.delai.depasse || c.delai.resteH < 12)), pt: 'att', pas: 'rapport' }));
  Object.keys(corrPar).forEach(shop => { const l = corrPar[shop]; const vid = l.find(p => p.visite_id) ? l.find(p => p.visite_id).visite_id : null;
    if (vid) { attente.push({ id: vid, court: l[0].court, quoi: pl(l.length, 'correction'), echeance: '', urgent: l.some(p => p.horsDelai), pt: 'info', pas: 'corrections' }); } });
  d.cartes.preparer.forEach(c => attente.push({ id: c.id, court: c.court, quoi: c.prepRetard ? 'dossier en retard' : 'dossier à finir', echeance: 'visite ' + fmtDJ(c.le) + ' ' + c.h, urgent: !!c.prepRetard, pt: c.prepRetard ? 'ko' : '', pas: 'dossier' }));
  d.cartes.tablette.filter(c => c.nonFaite || c.statut === 'en_cours').forEach(c => attente.push({ id: c.id, court: c.court, quoi: c.statut === 'en_cours' ? 'en visite' : 'pas encore faite', echeance: fmtDJ(c.le), urgent: !!c.nonFaite, pt: c.nonFaite ? 'ko' : 'ok', pas: 'tablette' }));
  const vus = {}; const tabs = attente.filter(a => { const k = String(a.id); if (vus[k]) { return false; } vus[k] = true; return true; });
  const sel = S.svSel && (S.svSel.kind === 'nouvelle' || S.svSel.kind === 'points' || tabs.some(t => String(t.id) === String(S.svSel.id)) || S.svSel.garde) ? S.svSel : { kind: 'nouvelle' };
  const N = S.svNouv || {};
  // Une visite ouverte reste ouverte, même quand elle change d'étape et quitte la liste de ce qui attend.
  const ouvrir = (s, pas) => app.setState({ svSel: s.kind === 'visite' ? Object.assign({ garde: true }, s) : s, svPas: pas || null, svPrepF: null, svRapF: null, svVisF: null, svRep: null });
  sv.onglets = [];
  if (sel.kind === 'nouvelle' || N.shop) {
    const nf2 = shopDe(N.shop);
    sv.onglets.push({ nom: 'Nouvelle visite', court: nf2 ? nf2.court : '', quoi: 'étape ' + (N.shop ? 2 : 1) + ' / 6', on: sel.kind === 'nouvelle', pt: 'rouge', ouvrir: () => ouvrir({ kind: 'nouvelle' }) });
  }
  const MAX = 5;
  if (sel.kind === 'visite' && !tabs.slice(0, MAX).some(t => String(t.id) === String(sel.id))) {
    const ET = { preparer: 'dossier à finir', tablette: 'sur la tablette', adapter: 'rapport à adapter', envoye: 'rapport envoyé' };
    let c = null, et = '';
    Object.keys(d.cartes).forEach(k => { const x = d.cartes[k].find(y => String(y.id) === String(sel.id)); if (x && !c) { c = x; et = k; } });
    sv.onglets.push({ court: c ? c.court : 'Visite', quoi: c ? ET[et] : '', on: true, pt: '', ouvrir: () => {} });
  }
  tabs.slice(0, MAX).forEach(t => sv.onglets.push({ nom: '', court: t.court, quoi: t.quoi, echeance: t.echeance, urgent: t.urgent, pt: t.pt, on: sel.kind === 'visite' && String(sel.id) === String(t.id), ouvrir: () => ouvrir({ kind: 'visite', id: t.id }, t.pas) }));
  sv.autres = tabs.length > MAX ? [{ v: '', nom: '+ ' + (tabs.length - MAX) + ' autre' + (tabs.length - MAX > 1 ? 's' : ''), on: true }].concat(tabs.slice(MAX).map(t => ({ v: String(t.id), nom: t.court + ' · ' + t.quoi, on: false }))) : null;
  sv.setAutre = e => { const t = tabs.find(x => String(x.id) === e.target.value); if (t) { ouvrir({ kind: 'visite', id: t.id, garde: true }, t.pas); } };
  sv.creer = { on: sel.kind === 'nouvelle' && !N.shop, ouvrir: () => app.setState({ svSel: { kind: 'nouvelle' }, svNouv: null, svPas: null }) };
  // L'onglet des points décidés : tout ce qui a été envoyé au franchisé, et pour quand.
  const pts = d.points || [];
  const nRetard = pts.filter(q => q.retard > 0).length;
  sv.ongletPoints = { n: pts.length, retard: nRetard, on: sel.kind === 'points', ouvrir: () => ouvrir({ kind: 'points' }) };
  sv.relire = () => { charge(app, 'svT', path, true); if (S.svPrep && S.svPrep.path) { charge(app, 'svPrep', S.svPrep.path, true); } if (S.svRap && S.svRap.path) { charge(app, 'svRap', S.svRap.path, true); } };
  sv.lu = d.lu ? 'relu à ' + d.lu.slice(11, 16) : '';
  if (sel.kind === 'points') { pointsDecidesVals(app, sv, d, vid => ouvrir({ kind: 'visite', id: vid }, 'corrections')); }
  else if (sel.kind === 'nouvelle') { nouvelleVals(app, sv, d, { shopDe, types, consNom }); }
  else { visiteVals(app, sv, d, sel.id, { shopDe, types, consNom, corrPar }); }
}

/* Les points décidés (26/10/2026, demande de Sam) : tout point envoyé au franchisé avec le rapport et pas encore
 * fermé — ouvert, à reprendre, escaladé, photo reçue — groupé par échéance : en retard, cette semaine (jusqu'au
 * dimanche), d'ici la fin du mois, plus tard. Les pistes de développement notées quand le CA est sous le budget
 * (référence budget:1…3) se filtrent à part. Une ligne ouvre sa visite, à l'étape des corrections. */
function pointsDecidesVals(app, sv, d, ouvrirVisite){
  const S = app.state; const F = S.svPtF || { shop: '', filtre: 'tout' };
  const maj = patch => app.setState({ svPtF: Object.assign({}, F, patch) });
  const t = auj(); const td = new Date(t + 'T12:00:00');
  const finSem = plusJours(t, (7 - td.getDay()) % 7);
  const finMois = plusJours(t.slice(0, 7) + '-01', 40).slice(0, 7) + '-01'; // le 1er du mois suivant
  const piste = q => /^budget:/.test(q.ref || '');
  const tous = d.points || [];
  const duMag = tous.filter(q => !F.shop || String(q.shop) === String(F.shop));
  const garde = { tout: () => true, retard: q => q.retard > 0, semaine: q => q.retard > 0 || (!!q.echeance && q.echeance <= finSem), pistes: piste };
  const vus = duMag.filter(garde[F.filtre] || garde.tout);
  const groupe = q => q.retard > 0 ? 'retard' : !q.echeance ? 'sans' : q.echeance <= finSem ? 'semaine' : q.echeance < finMois ? 'mois' : 'plus';
  const G = [['retard', 'En retard', 'ko'], ['semaine', 'À faire d’ici dimanche', 'att'], ['mois', 'D’ici la fin du mois', ''], ['plus', 'Plus tard', ''], ['sans', 'Sans échéance', '']];
  const jours = e => Math.round((new Date(e + 'T12:00:00') - td) / 86400000);
  const mags = {}; tous.forEach(q => { mags[q.shop] = q.court || q.shop; });
  sv.titre = 'Les points décidés';
  sv.chapo = 'Tout ce qui a été décidé en visite et envoyé au franchisé, avec son échéance — les plus en retard d’abord. Les pistes de développement notées quand le CA est sous le budget sont marquées.';
  sv.pointsDecides = {
    total: tous.length,
    magasins: [{ v: '', nom: 'Tous les magasins · ' + tous.length, on: !F.shop }].concat(Object.keys(mags).sort((a, b) => String(mags[a]).localeCompare(String(mags[b]))).map(k => ({ v: k, nom: mags[k] + ' · ' + tous.filter(q => String(q.shop) === String(k)).length, on: String(F.shop) === String(k) }))),
    setShop: e => maj({ shop: e.target.value }),
    filtres: [['tout', 'Tous'], ['retard', 'En retard'], ['semaine', 'D’ici dimanche'], ['pistes', 'Pistes CA']].map(([k, nom]) => ({ nom: nom + ' · ' + duMag.filter(garde[k]).length, on: (F.filtre || 'tout') === k, choisir: () => maj({ filtre: k }) })),
    groupes: G.map(([k, nom, cls]) => ({ nom, cls, lignes: vus.filter(q => groupe(q) === k).sort((a, b) => b.retard - a.retard || String(a.echeance || '9').localeCompare(String(b.echeance || '9')) || String(a.priorite).localeCompare(String(b.priorite))).map(q => ({
      prio: q.priorite, titre: q.titre, detail: q.detail || '', court: q.court || q.shop, piste: piste(q),
      origine: q.visiteLe ? 'visite du ' + fmtD(q.visiteLe) + (q.visiteType ? ' · ' + q.visiteType : '') : (/^msp/.test(q.ref || '') ? 'client mystère' : 'hors visite'),
      qui: ASSIGNE[q.assigne] || q.assigne || '', echeance: q.echeance ? fmtDJ(q.echeance) : '—',
      delai: q.retard > 0 ? 'retard ' + pl(q.retard, 'jour') : q.echeance ? (jours(q.echeance) === 0 ? 'aujourd’hui' : 'dans ' + pl(jours(q.echeance), 'jour')) : '', delaiKo: q.retard > 0,
      statut: PLAN_ST[q.statut] || q.statut, statutCls: PLAN_CLS[q.statut] || '', ouvrir: q.visite_id ? () => ouvrirVisite(q.visite_id) : null })) })).filter(g => g.lignes.length)
  };
}

/* La pile d'étapes : l'état de chacune (fait, en cours, à venir), sa ligne repliée, l'étape ouverte. */
function etapesDe(app, faits, lignes, defaut, ouvrables){
  const S = app.state;
  const ouverte = S.svPas && ouvrables.includes(S.svPas) ? S.svPas : defaut;
  return ETAPES.map(([cle, nom, phase], i) => ({ cle, nom, phase, n: NUMERO[cle], der: i === ETAPES.length - 1,
    etat: cle === ouverte ? 'cur' : (faits[cle] ? 'fait' : 'avenir'), titre: (lignes[cle] || [])[0] || nom, sous: (lignes[cle] || [])[1] || '',
    modifier: cle !== ouverte && ouvrables.includes(cle) ? () => app.setState({ svPas: cle }) : null }));
}

/* Une nouvelle visite : le magasin, puis la visite (type, annoncée, date, consultant) — créée au bout de l'étape 2. */
function nouvelleVals(app, sv, d, h){
  const S = app.state; const N = S.svNouv || {};
  const maj = patch => app.setState({ svNouv: Object.assign({}, N, patch) });
  const f = h.shopDe(N.shop);
  const t = h.types.find(x => x.code === N.type) || h.types.find(x => x.code === 'reguliere') || h.types[0] || null;
  const annoncee = N.annoncee != null ? N.annoncee : (t ? t.annoncee !== false : true);
  const cons = N.consultant != null ? N.consultant : (f ? f.responsableId || '' : '');
  const date = N.date || plusJours(auj(), 3); const heure = N.heure || '09:30'; const duree = Number(N.duree || (t ? t.duree : 90));
  const faits = { magasin: !!f };
  const lignes = { magasin: f ? [f.court, (f.responsable ? 'consultant ' + f.responsable : 'sans consultant responsable') + ' · courriels en ' + String(f.langueNom || 'français').toLowerCase()] : null,
    visite: ['La visite', 'le type, annoncée ou inopinée, le jour et qui y va'], dossier: ['Le dossier', 'ce qui part sur la tablette, vos demandes'], envoi: ['L’envoi', 'sur la tablette, et l’ordre du jour au franchisé'],
    tablette: ['La visite sur place', 'sur la tablette du consultant : rien à faire ici'], rapport: ['Le rapport', 'à adapter et envoyer sous ' + d.delais.rapportHeures + ' h'], corrections: ['Les corrections', 'les photos du franchisé, à valider'] };
  sv.etapes = etapesDe(app, faits, lignes, f ? 'visite' : 'magasin', f ? ['magasin', 'visite'] : ['magasin']);
  sv.titre = 'Créer une visite';
  sv.chapo = 'À gauche, une étape à la fois. À droite, la fiche de la visite se remplit au fur et à mesure : qui reçoit quoi, ce que lira le franchisé, ce qui se passe ensuite.';
  const ouverte = sv.etapes.find(e => e.etat === 'cur').cle;
  if (ouverte === 'magasin') {
    sv.pas = { cle: 'magasin', question: 'Quel magasin allez-vous visiter ?', lead: 'Le feu, la dernière visite et les points ouverts aident à choisir. Le consultant responsable et la langue du franchisé suivent.',
      magasins: d.franchises.map(x => ({ court: x.court, feu: x.feu, on: String(x.id) === String(N.shop), l1: (x.responsable || 'sans consultant') + (x.due ? ' · ' + x.due : ''),
        derniere: x.derniere ? 'vu le ' + fmtD(x.derniere.le) + ' · ' + x.derniere.checklist.ok + ' / ' + x.derniere.checklist.total + ' conformes' : 'jamais visité',
        points: x.points.n ? pl(x.points.n, 'point') + ' ouvert' + (x.points.n > 1 ? 's' : '') + (x.points.p0 ? ' · ' + x.points.p0 + ' P0' : '') : 'aucun point ouvert', pointsKo: !!(x.points.p0 || x.points.retard),
        prochaine: x.prochaine ? 'prochaine visite ' + fmtDJ(x.prochaine.le) : '',
        choisir: () => app.setState({ svNouv: { shop: String(x.id) }, svPas: 'visite' }) })) };
  } else {
    sv.pas = { cle: 'visite', nouvelle: true, question: 'Quelle visite, quand, et qui y va ?', lead: 'Le type charge sa checklist, ses tâches et sa durée. Annoncée, le franchisé reçoit l’ordre du jour ; inopinée, il n’est pas prévenu.',
      types: h.types.map(x => ({ nom: x.nom, sous: typeSous(x), defaut: x.annoncee === false ? 'inopinée d’office' : 'annoncée d’office', on: t && x.code === t.code, choisir: () => maj({ type: x.code, annoncee: null, duree: '' }) })),
      annoncee, annoncer: () => maj({ annoncee: true }), inopiner: () => maj({ annoncee: false }),
      annonceeSous: annoncee ? 'il reçoit l’ordre du jour ' + d.delais.prepJours + ' jours avant et voit la visite sur sa page' : 'il n’est pas prévenu : la visite n’apparaît pas sur sa page',
      date, setDate: e => maj({ date: e.target.value }), heure, setHeure: e => maj({ heure: e.target.value }), duree, setDuree: e => maj({ duree: e.target.value }),
      consultants: [{ v: '', nom: 'Choisir le consultant', on: !cons }].concat((d.consultants || []).map(c => ({ v: c.id, nom: c.nom + (f && c.id === f.responsableId ? ' · responsable' : ''), on: c.id === cons }))),
      setCons: e => maj({ consultant: e.target.value }), busy: !!S.svBusy,
      action: 'Créer la visite et préparer le dossier',
      valider: () => {
        if (!cons) { message(app, 'Choisissez le consultant qui fera la visite.', true); return; }
        app.setState({ svBusy: true });
        app.api('POST', '/visites', { client_id: 'sv-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6), shop: N.shop, consultant: cons, consultant_nom: h.consNom(cons),
          type: t ? t.code : 'reguliere', prevu_le: date, debut_h: heure, duree_min: duree, motif: 'reguliere', annoncee, qui: 'Cockpit' }).then(r => {
          const ok = r && r.ok !== false && !r.error && r.visite; const su = (r && r.suite) || {};
          app.setState({ svBusy: false });
          if (!ok) { message(app, 'Refusé : ' + ((r && r.error) || 'sans réponse'), true); return; }
          message(app, 'Visite créée : ' + (f ? f.court : '') + ', ' + fmtDJ(date) + ' à ' + heure + (su.taches ? ' · ' + pl(su.taches, 'tâche') + ' dans la liste du consultant' : '') + (su.invitation && su.invitation.envoye ? ' · invitation envoyée à ' + su.invitation.a : '') + '.');
          app.setState({ svNouv: null, svSel: { kind: 'visite', id: r.visite.id, garde: true }, svPas: 'dossier', svPrepF: null });
          relireTableau(app);
        });
      } };
  }
  // La fiche : ce qu'on sait déjà.
  sv.fiche = { titre: f ? f.court + (t && ouverte === 'visite' ? ' · ' + t.nom : '') : 'Nouvelle visite', sous: f && ouverte === 'visite' ? fmtDJ(date) + ' · ' + heure + ' – ' + finH(heure, duree) + (cons ? ' · ' + h.consNom(cons) : '') : 'choisissez le magasin',
    puces: f && ouverte === 'visite' ? [annoncee ? 'annoncée' : 'inopinée', duree + ' min' + (t && t.points ? ' · ' + t.points + ' points' : ''), { t: 'à créer', neuf: true }] : [],
    dest: f ? [
      { ic: 'tab', qui: (cons ? h.consNom(cons) : 'Le consultant') + ', sur sa tablette', quoi: 'le dossier du magasin et vos demandes, en tête de sa checklist', quand: 'à l’envoi · étape 4' },
      annoncee ? { ic: 'mail', qui: 'Le franchisé de ' + f.court + ', courriel et sa page', quoi: 'l’ordre du jour en ' + String(f.langueNom || 'français').toLowerCase() + ' : le jour, l’heure, le consultant, les sujets', quand: d.delais.prepJours + ' j avant' }
        : { ic: 'mail', qui: 'Le franchisé de ' + f.court, quoi: 'rien : la visite est inopinée, elle n’apparaît pas sur sa page', quand: '', off: true },
      { ic: 'lock', qui: 'Le bureau et le consultant seulement', quoi: 'la météo du franchisé, votre mot au consultant, les demandes internes', quand: '' }] : [],
    odj: null, suite: f && ouverte === 'visite' ? suiteDe(d, { annoncee, date, heure, consultant: h.consNom(cons) }) : [],
    pied: f ? (ouverte === 'visite' ? 'Magasin choisi · reste la visite' : 'Magasin choisi') : 'Rien n’est créé tant que la visite n’est pas validée à l’étape 2.' };
}

/* Le calendrier de la visite, du dossier au rapport. */
function suiteDe(d, v){
  const l = [];
  if (v.annoncee) { l.push({ quand: v.odjLe ? fmtDH(v.odjLe) : fmtDJ(plusJours(v.date, -d.delais.prepJours)) + ' · 08:00', quoi: v.odjLe ? 'l’ordre du jour est parti' : 'l’ordre du jour part tout seul', fait: !!v.odjLe }); }
  l.push({ quand: fmtDJ(v.date) + ' · ' + v.heure, quoi: 'la visite, sur la tablette de ' + (v.consultant || 'du consultant'), cur: true, fait: !!v.faite });
  l.push({ quand: v.rapportLe ? fmtDH(v.rapportLe) : 'sous ' + d.delais.rapportHeures + ' h', quoi: v.rapportLe ? 'rapport envoyé' : 'dernier délai pour le rapport', fait: !!v.rapportLe });
  l.push({ quand: 'ensuite', quoi: 'ses photos de correction, à valider' });
  return l;
}

/* Une visite qui existe : le dossier (/suivi/preparation), puis le rapport (/suivi/rapport) et les corrections. */
function visiteVals(app, sv, d, id, h){
  const S = app.state;
  const pathP = '/suivi/preparation?visite=' + id;
  charge(app, 'svPrep', pathP, false);
  const P = lu(app, 'svPrep', pathP);
  if (!P) { sv.pasChargement = 'Lecture de la visite…'; return; }
  if (P.error) { sv.indispo = 'La visite ne se lit pas : ' + P.error; return; }
  const v = P.visite; const f = h.shopDe(v.shop) || { court: P.magasin.court, langueNom: P.odj.langueNom };
  prepVals(app, sv, id);
  const p = sv.prep; const F = S.svPrepF && String(S.svPrepF.id) === String(id) ? S.svPrepF : null;
  const t = P.types.find(x => x.code === (F ? F.type : v.type)) || P.type;
  const faite = v.statut === 'terminee'; const envoyeeLe = P.tablette.envoyeeLe;
  const rapportEnvoye = v.rapportStatut === 'envoye' || !!v.rapportEnvoyeLe;
  const corr = (h.corrPar[String(v.shop)] || []);
  const inclus = p.dossier.filter(b => b.inclus); const internes = p.dossier.filter(b => b.inclus && b.interne);
  const dem = p.points.filter(q => (q.libelle || '').trim()); const demOdj = dem.filter(q => q.odj);
  const faits = { magasin: true, visite: true, dossier: !!envoyeeLe || faite, envoi: !!envoyeeLe || faite, tablette: faite, rapport: rapportEnvoye, corrections: rapportEnvoye && !corr.length };
  const consN = v.consultantNom || 'le consultant';
  const lignes = {
    magasin: [f.court, (f.responsable ? 'consultant ' + f.responsable + ' · ' : '') + 'courriels en ' + String(f.langueNom || P.odj.langueNom || 'français').toLowerCase()],
    visite: [(t ? t.nom : 'Visite') + ' · ' + (p.annoncee ? 'annoncée' : 'inopinée') + ' · ' + fmtDJ(v.prevu_le) + ', ' + v.debut_h + ' – ' + finH(v.debut_h, v.duree_min) + ' · ' + consN, typeSous(t) + (p.annoncee ? ' · le franchisé est prévenu ' + d.delais.prepJours + ' jours avant' : ' · personne n’est prévenu')],
    dossier: [inclus.length + ' bloc' + (inclus.length > 1 ? 's' : '') + ' sur ' + p.dossier.length + ' partent · ' + pl(dem.length, 'demande'), p.note ? '« ' + p.note.slice(0, 90) + (p.note.length > 90 ? '…' : '') + ' »' : 'aucun mot pour le consultant'],
    envoi: envoyeeLe ? ['Envoyé sur la tablette de ' + consN + ' le ' + fmtDH(envoyeeLe), P.odj.envoyeLe ? 'ordre du jour envoyé le ' + fmtDH(P.odj.envoyeLe) : (p.annoncee ? 'ordre du jour : ' + (P.odj.auto ? 'part le ' + fmtDJ(P.odj.prevuLe) : 'à envoyer à la main') : 'inopinée : rien au franchisé')]
      : ['Sur la tablette de ' + consN + (p.annoncee ? ', et l’ordre du jour au franchisé' : ''), 'un seul bouton ; l’ordre du jour peut aussi partir tout seul'],
    tablette: ['La visite sur la tablette de ' + consN, faite ? 'faite le ' + fmtDH(v.termine_a) : (v.statut === 'en_cours' ? 'en cours depuis ' + fmtDH(v.commence_a) : fmtDJ(v.prevu_le) + ' à ' + v.debut_h)],
    rapport: ['Le rapport', rapportEnvoye ? 'envoyé' + (v.rapportEnvoyeLe ? ' le ' + fmtDH(v.rapportEnvoyeLe) : '') : (faite ? 'brouillon de la tablette, à adapter et envoyer' : 'à adapter et envoyer sous ' + d.delais.rapportHeures + ' h après la visite')],
    corrections: ['Les corrections', corr.length ? pl(corr.length, 'photo') + ' à valider' : (rapportEnvoye ? 'aucune photo en attente' : 'les photos du franchisé, après le rapport')] };
  const defaut = !faits.dossier ? 'dossier' : !faits.tablette ? 'tablette' : !faits.rapport ? 'rapport' : 'corrections';
  const ouvrables = ['visite', 'dossier', 'envoi', 'tablette'].concat(faite ? ['rapport', 'corrections'] : []);
  sv.etapes = etapesDe(app, faits, lignes, defaut, faite ? ['rapport', 'corrections', 'tablette'] : ouvrables);
  sv.titre = f.court + ' · ' + (t ? t.nom : 'visite');
  sv.chapo = 'La visite du ' + fmtDJ(v.prevu_le) + ' à ' + v.debut_h + ' avec ' + fin(consN) + ' Reprenez là où elle en est ; une étape faite se rouvre d’un clic.';
  const ouverte = sv.etapes.find(e => e.etat === 'cur').cle;
  const enregistrerVisite = (G, puis) => {
    app.setState({ svBusy: true });
    app.api('PUT', '/visites/' + id, { prevu_le: G.date, debut_h: G.heure, duree_min: Number(G.duree), consultant: G.consultant, type: G.type, annoncee: G.annoncee, qui: 'Cockpit' }).then(r => {
      const ok = r && r.ok !== false && !r.error;
      app.setState({ svBusy: false, svVisF: ok ? null : app.state.svVisF, svPrepF: ok ? null : app.state.svPrepF, svPas: ok ? puis : app.state.svPas });
      message(app, ok ? 'Visite mise à jour.' : 'Refusé : ' + ((r && r.error) || 'sans réponse'), !ok);
      if (ok) { charge(app, 'svPrep', pathP, true); relireTableau(app); }
    });
  };
  if (ouverte === 'visite') {
    const G = S.svVisF && String(S.svVisF.id) === String(id) ? S.svVisF : { id, type: v.type, annoncee: P.annoncee, date: v.prevu_le, heure: v.debut_h, duree: v.duree_min, consultant: v.consultant };
    const maj = patch => app.setState({ svVisF: Object.assign({}, G, patch) });
    const tG = P.types.find(x => x.code === G.type) || t;
    sv.pas = { cle: 'visite', question: 'Quelle visite, quand, et qui y va ?', lead: 'Changer le jour prévient l’agenda du consultant ; changer le type recharge sa checklist.',
      types: P.types.filter(x => x.actif !== false).map(x => ({ nom: x.nom, sous: typeSous(x), defaut: x.annoncee === false ? 'inopinée d’office' : 'annoncée d’office', on: tG && x.code === tG.code, choisir: () => maj({ type: x.code, annoncee: x.annoncee !== false }) })),
      annoncee: !!G.annoncee, annoncer: () => maj({ annoncee: true }), inopiner: () => maj({ annoncee: false }),
      annonceeSous: G.annoncee ? 'il reçoit l’ordre du jour et voit la visite sur sa page' : 'il n’est pas prévenu : la visite n’apparaît pas sur sa page',
      date: G.date, setDate: e => maj({ date: e.target.value }), heure: G.heure, setHeure: e => maj({ heure: e.target.value }), duree: G.duree, setDuree: e => maj({ duree: e.target.value }),
      consultants: (d.consultants || []).map(c => ({ v: c.id, nom: c.nom, on: c.id === G.consultant })), setCons: e => maj({ consultant: e.target.value }),
      busy: !!S.svBusy, action: 'Enregistrer et revenir au dossier', valider: () => enregistrerVisite(G, 'dossier') };
  } else if (ouverte === 'dossier') {
    sv.pas = { cle: 'dossier', question: 'Que doit emporter ' + (v.consultantNom || 'le consultant') + ' sur la tablette ?', lead: 'Tout s’est assemblé seul depuis la fiche de ' + f.court + '. Décochez ce qui ne doit pas partir, ajoutez vos demandes : la fiche à droite suit chaque choix.',
      prep: p, consultant: v.consultantNom || 'le consultant', busy: !!S.svBusy,
      continuer: () => prepSauver(app, id, prepForm(app, id, P), false, false, 'envoi'), enregistrer: p.enregistrer };
  } else if (ouverte === 'envoi') {
    sv.pas = { cle: 'envoi', question: envoyeeLe ? 'Le dossier est sur la tablette' : 'Tout est prêt : on envoie ?', lead: envoyeeLe ? 'Envoyé le ' + fmtDH(envoyeeLe) + '. Un nouvel envoi remplace le dossier sur la tablette.' : 'Un seul bouton : le dossier part sur la tablette de ' + fin(consN) + (p.annoncee ? ' L’ordre du jour part tout seul au franchisé, ou maintenant si vous préférez.' : ''),
      recap: [{ ic: 'tab', t: 'Sur la tablette de ' + consN, s: inclus.length + ' bloc' + (inclus.length > 1 ? 's' : '') + ' du dossier · ' + pl(dem.length, 'demande') + ' en tête de sa checklist' + (p.note ? ' · votre mot' : '') },
        { ic: 'mail', t: p.annoncee ? 'Au franchisé de ' + f.court : 'Au franchisé : rien', s: p.odj, ko: !!p.sansAdresse }],
      sansAdresse: p.sansAdresse, odjMaintenant: p.odjMaintenant, busy: !!S.svBusy,
      envoyer: p.envoyer, libelle: envoyeeLe ? 'Renvoyer le dossier sur la tablette' : 'Envoyer sur la tablette' };
  } else if (ouverte === 'tablette') {
    const nonFaite = ['planifiee', 'confirmee'].includes(v.statut) && v.prevu_le < auj();
    sv.pas = { cle: 'tablette', question: faite ? 'La visite est faite' : (v.statut === 'en_cours' ? 'La visite est en cours' : 'Rien à faire ici pendant la visite'),
      lead: faite ? 'Le brouillon du rapport est revenu de la tablette le ' + fmtDH(v.termine_a) + '. Il attend à l’étape 5.'
        : v.statut === 'en_cours' ? consN + ' contrôle ' + f.court + ' depuis ' + fmtDH(v.commence_a) + '. Le brouillon du rapport reviendra tout seul.'
        : nonFaite ? 'La date est passée et la visite n’a pas été faite. Reprogrammez-la à l’étape 2, ou ouvrez la tablette du consultant.'
        : consN + ' ouvrira la visite sur sa tablette le ' + fmtDJ(v.prevu_le) + ' à ' + v.debut_h + '. Le brouillon du rapport reviendra tout seul ici.',
      ko: nonFaite, tablette: () => app.setState({ screen: 'suiviTablette', svTabCons: v.consultant }), suite: faite ? () => app.setState({ svPas: 'rapport' }) : null };
  } else if (ouverte === 'rapport') {
    rapVals(app, sv, id);
    sv.pas = { cle: 'rapport', question: rapportEnvoye ? 'Le rapport est parti' : 'Adapter le rapport et l’envoyer', lead: rapportEnvoye ? 'Il est sur la page du franchisé ; vous pouvez le relire ou renvoyer le courriel.' : 'Le brouillon de la tablette, relu au bureau : choisissez ce qui part au franchisé, ce qui reste interne, puis envoyez.', rap: sv.rap };
  } else {
    sv.pas = { cle: 'corrections', question: corr.length ? 'Les photos de correction de ' + f.court : 'Aucune correction en attente', lead: corr.length ? 'La photo du franchisé, à côté de celle de la visite : acceptez, ou renvoyez avec un nouveau délai.' : 'Les photos du franchisé arriveront ici, une par point ouvert.' };
    pointsVals(app, sv, { corrections: corr, points: (d.points || []).filter(q => String(q.shop) === String(v.shop)) });
    sv.pas.corrections = sv.corrections; sv.pas.points = sv.points;
  }
  // La fiche vivante.
  const rapLe = v.rapportEnvoyeLe || null;
  sv.fiche = { titre: f.court + ' · ' + (t ? t.nom : ''), sous: fmtDJ(v.prevu_le) + ' · ' + v.debut_h + ' – ' + finH(v.debut_h, v.duree_min) + ' · ' + consN,
    puces: [p.annoncee ? 'annoncée' : 'inopinée', v.duree_min + ' min' + (t && t.points ? ' · ' + t.points + ' points' : ''),
      { t: rapportEnvoye ? 'rapport envoyé' : faite ? 'rapport à adapter' : envoyeeLe ? 'sur la tablette' : 'dossier en cours', neuf: true }],
    dest: [
      { ic: 'tab', qui: consN + ', sur sa tablette', quoi: 'le dossier (' + inclus.length + ' bloc' + (inclus.length > 1 ? 's' : '') + '), ' + pl(dem.length, 'demande') + ' en tête de sa checklist' + (p.note ? ', votre mot' : ''), quand: envoyeeLe ? 'envoyé ' + fmtD(envoyeeLe) : 'à l’envoi · étape 4' },
      p.annoncee ? { ic: 'mail', qui: 'Le franchisé de ' + f.court + ', courriel et sa page', quoi: 'l’ordre du jour en ' + String(P.odj.langueNom || 'français').toLowerCase() + ' : le jour, l’heure, ' + consN + (demOdj.length ? ' et ' + pl(demOdj.length, 'sujet') : ''), quand: P.odj.envoyeLe ? 'envoyé ' + fmtD(P.odj.envoyeLe) : (P.odj.auto ? fmtDJ(P.odj.prevuLe) : 'à la main'), ko: !!p.sansAdresse }
        : { ic: 'mail', qui: 'Le franchisé de ' + f.court, quoi: 'rien : la visite est inopinée, elle n’apparaît pas sur sa page', quand: '', off: true },
      { ic: 'lock', qui: 'Le bureau et ' + consN + ' seulement', quoi: [internes.map(b => b.titre.toLowerCase()).join(', '), p.note ? 'votre mot' : '', dem.length - demOdj.length ? pl(dem.length - demOdj.length, 'demande') + ' interne' + (dem.length - demOdj.length > 1 ? 's' : '') : ''].filter(Boolean).join(', ') || 'rien de plus', quand: '' }],
    odj: p.annoncee ? { sujet: P.odj.sujet, texte: P.odj.texte, langue: P.odj.langueNom, sujets: demOdj.map(q => q.libelle) } : null,
    suite: suiteDe(d, { annoncee: p.annoncee, date: v.prevu_le, heure: v.debut_h, consultant: consN, odjLe: P.odj.envoyeLe, faite, rapportLe: rapLe }),
    qr: v.statut === 'annulee' ? null : (() => { const lien = lienControle(v.id); return { id: v.id, lien, svg: qrSvg(app, lien), faite,
      copier: () => { const ok = () => message(app, 'Lien du contrôle copié.'); try { navigator.clipboard.writeText(lien).then(ok, () => message(app, lien)); } catch (e) { message(app, lien); } } }; })(),
    pied: rapportEnvoye ? 'Rapport envoyé' + (corr.length ? ' · ' + pl(corr.length, 'correction') + ' à valider' : '') : faite ? 'Visite faite · reste le rapport' : envoyeeLe ? 'Dossier sur la tablette · la visite suit' : 'Magasin, visite' + (inclus.length ? ' et dossier' : '') + ' prêts · reste l’envoi' };
}
/* Le formulaire du dossier tel qu'il est à l'écran (repris de prepVals). */
function prepForm(app, id, d){
  const S = app.state;
  return S.svPrepF && String(S.svPrepF.id) === String(id) ? S.svPrepF
    : { id, blocs: Object.fromEntries(d.dossier.map(b => [b.cle, !!b.inclus])), points: d.prep.points.map(p => ({ libelle: p.libelle, odj: p.odj, source: p.source })), annoncee: d.annoncee, type: d.visite.type, note: d.prep.note || '' };
}

/* Préparer : le dossier, ce que la tablette emportera, l'ordre du jour. */
function prepVals(app, sv, id){
  const S = app.state; const path = '/suivi/preparation?visite=' + id;
  charge(app, 'svPrep', path, false);
  const d = lu(app, 'svPrep', path);
  if (!d) { sv.prep = { chargement: true }; return; }
  if (d.error) { sv.prep = { indispo: 'La préparation ne se lit pas : ' + d.error }; return; }
  const F = S.svPrepF && String(S.svPrepF.id) === String(id) ? S.svPrepF
    : { id, blocs: Object.fromEntries(d.dossier.map(b => [b.cle, !!b.inclus])), points: d.prep.points.map(p => ({ libelle: p.libelle, odj: p.odj, source: p.source })), annoncee: d.annoncee, type: d.visite.type, note: d.prep.note || '' };
  const maj = patch => app.setState({ svPrepF: Object.assign({}, F, patch) });
  const majPt = (i, patch) => { const pts = F.points.slice(); pts[i] = Object.assign({}, pts[i], patch); maj({ points: pts }); };
  const t = d.types.find(x => x.code === F.type) || d.type;
  const v = d.visite;
  sv.prep = {
    titre: d.magasin.court + ' · ' + (t ? t.nom : '') + ' · ' + fmtDJ(v.prevu_le) + ' · ' + v.debut_h + '–' + finH(v.debut_h, v.duree_min) + ' · ' + (v.consultantNom || 'consultant à choisir'),
    roles: 'Prépare : ' + (d.roles.prepareNom || '—') + ' · dossier attendu le ' + fmtDJ(d.prepDu) + ' · adapte le rapport : ' + (d.roles.adapteNom || '—'),
    envoyee: d.tablette.envoyeeLe ? 'Envoyé sur la tablette le ' + fmtDH(d.tablette.envoyeeLe) + '. Un nouvel envoi remplace le dossier.' : null,
    dossier: d.dossier.map(b => ({ titre: b.titre, source: BLOCS_LIB[b.cle] || '', lignes: b.lignes, motif: b.motif, alerte: b.alerte, interne: b.interne, inclus: !!F.blocs[b.cle],
      basculer: () => maj({ blocs: Object.assign({}, F.blocs, { [b.cle]: !F.blocs[b.cle] }) }) })),
    types: d.types.filter(x => x.actif !== false).map(x => ({ v: x.code, nom: x.nom + (x.annoncee ? '' : ' · inopiné'), on: x.code === F.type })),
    setType: e => { const x = d.types.find(y => y.code === e.target.value); maj({ type: e.target.value, annoncee: x ? x.annoncee : F.annoncee }); },
    typeSous: t ? t.duree + ' min · ' + (t.dynamique === 'plans' ? 'checklist : les plans ouverts' : t.dynamique === 'ko' ? 'checklist : les non-conformes de la dernière visite' : pl(t.points, 'point')) : '',
    annoncee: !!F.annoncee, annoncer: () => maj({ annoncee: true }), inopiner: () => maj({ annoncee: false }),
    points: F.points.map((p, i) => ({ libelle: p.libelle, odj: !!p.odj, source: p.source, setLib: e => majPt(i, { libelle: e.target.value }), basculerOdj: () => majPt(i, { odj: !p.odj }), retirer: () => maj({ points: F.points.filter((_, j) => j !== i) }) })),
    ajouter: () => maj({ points: F.points.concat([{ libelle: '', odj: true, source: 'bureau' }]) }),
    note: F.note, setNote: e => maj({ note: e.target.value }),
    odj: d.odj.envoyeLe ? 'Envoyé le ' + fmtDH(d.odj.envoyeLe) + ' à ' + (d.odj.adresse || '—') + '.'
      : (!F.annoncee ? 'Visite inopinée : rien ne part au franchisé, il ne la voit pas sur sa page.'
        : (d.odj.auto ? 'Part tout seul le ' + fmtDJ(d.odj.prevuLe) + ' (' + (d.odj.adresse || 'adresse inconnue') + ', ' + d.odj.langueNom.toLowerCase() + ').' : 'Envoi à la main (réglage de Qui fait quoi).')),
    odjSujet: d.odj.sujet, odjTexte: d.odj.texte, odjOuvert: !!S.svOdjOuvert, odjBasculer: () => app.setState({ svOdjOuvert: !S.svOdjOuvert }),
    langue: d.odj.langueNom, sansAdresse: F.annoncee && !d.odj.adresse,
    busy: !!S.svBusy,
    enregistrer: () => prepSauver(app, id, F, false, false), envoyer: () => prepSauver(app, id, F, true, false),
    odjMaintenant: F.annoncee && !d.odj.envoyeLe ? () => prepSauver(app, id, F, false, true) : null
  };
}
function finH(h, min){ const [a, b] = String(h || '09:00').split(':').map(Number); const t = a * 60 + b + Number(min || 0); return String(Math.floor(t / 60) % 24).padStart(2, '0') + ':' + String(t % 60).padStart(2, '0'); }
function prepSauver(app, id, F, envoyer, odj, puis){
  app.setState({ svBusy: true });
  app.api('PUT', '/suivi/preparation/' + id, { blocs: F.blocs, points: F.points.filter(p => (p.libelle || '').trim()), annoncee: F.annoncee, type: F.type, note: F.note, envoyer, odj, qui: 'Cockpit' }).then(r => {
    const ok = r && r.ok !== false && !r.error && r.visite;
    app.setState({ svBusy: false, svPrepF: ok ? null : app.state.svPrepF, svPrep: ok ? { path: '/suivi/preparation?visite=' + id, chargement: false, d: r } : app.state.svPrep,
      svPas: ok ? (puis || (envoyer ? 'tablette' : app.state.svPas)) : app.state.svPas });
    let t = ok ? (envoyer ? 'Dossier envoyé sur la tablette de ' + fin(r.visite.consultantNom || 'consultant') : 'Préparation enregistrée.') : 'Refusé : ' + ((r && r.error) || 'sans réponse');
    if (ok && r.odjEnvoi) { t += r.odjEnvoi.envoye ? ' Ordre du jour envoyé à ' + r.odjEnvoi.a + '.' : ' Ordre du jour non envoyé : ' + r.odjEnvoi.motif + '.'; }
    message(app, t, !ok || !!(r.odjEnvoi && !r.odjEnvoi.envoye));
    relireTableau(app);
  });
}

/* Adapter et envoyer : le brouillon de la tablette, relu au bureau. */
function rapVals(app, sv, id){
  const S = app.state; const path = '/suivi/rapport?visite=' + id;
  charge(app, 'svRap', path, false);
  const d = lu(app, 'svRap', path);
  if (!d) { sv.rap = { chargement: true }; return; }
  if (d.error) { sv.rap = { indispo: 'Le rapport ne se lit pas : ' + d.error }; return; }
  const c = d.contenu;
  const F = S.svRapF && String(S.svRapF.id) === String(id) ? S.svRapF
    : { id, phrase: c.phrase, positif: c.positif, sentiment: c.sentiment, execution: c.execution, clients: c.clients, causes: c.causes.slice(), diagnostic: c.diagnostic, reco: c.reco,
      plans: Object.fromEntries(d.plans.map(p => [p.id, { inclus: p.inclus, titre: p.titre, detail: p.detail || '', priorite: p.priorite, echeance: p.echeance || '' }])),
      photos: Object.fromEntries(d.photos.map(p => [p.id, p.inclus])) };
  const maj = patch => app.setState({ svRapF: Object.assign({}, F, patch) });
  const majPl = (pid, patch) => maj({ plans: Object.assign({}, F.plans, { [pid]: Object.assign({}, F.plans[pid], patch) }) });
  const envoye = d.statut === 'envoye';
  const v = d.visite; const dl = d.delai;
  const inclus = d.plans.filter(p => F.plans[p.id] && F.plans[p.id].inclus);
  sv.rap = {
    titre: d.magasin.court + ' · ' + d.typeNom + ' · ' + fmtDJ(v.prevu_le) + ' · ' + (v.consultantNom || ''),
    envoye, statut: envoye ? 'envoyé le ' + fmtDH(d.envoi.envoyeLe) + (d.envoi.envoyePar ? ' par ' + d.envoi.envoyePar : '') + (d.envoi.luLe ? ' · lu le ' + fmtDH(d.envoi.luLe) : ' · pas encore lu') : 'brouillon de la tablette · ' + fmtDH(v.termine_a),
    delai: !envoye && dl ? (dl.depasse ? 'délai dépassé de ' + duree(dl.resteH) : 'à envoyer avant ' + fmtDH(dl.limite) + ' (reste ' + duree(dl.resteH) + ')') : null, delaiKo: !envoye && dl && (dl.depasse || dl.resteH < 12),
    langue: d.envoi.langueNom, langueFr: d.envoi.langue === 'fr',
    phrase: F.phrase, setPhrase: e => maj({ phrase: e.target.value }),
    positif: F.positif, setPositif: e => maj({ positif: e.target.value }),
    sentiment: [1, 2, 3, 4, 5].map(n => ({ n, on: F.sentiment === n, choisir: () => maj({ sentiment: F.sentiment === n ? null : n }) })),
    execution: Object.keys(EXEC).map(k => ({ nom: EXEC[k], on: F.execution === k, choisir: () => maj({ execution: F.execution === k ? '' : k }) })),
    clients: Object.keys(CLIENTS).map(k => ({ nom: CLIENTS[k], on: F.clients === k, choisir: () => maj({ clients: F.clients === k ? '' : k }) })),
    causes: Object.keys(d.causesDiag).map(k => ({ nom: d.causesDiag[k], on: F.causes.includes(k), choisir: () => maj({ causes: F.causes.includes(k) ? F.causes.filter(x => x !== k) : F.causes.concat([k]) }) })),
    diagnostic: F.diagnostic, setDiagnostic: e => maj({ diagnostic: e.target.value }),
    reco: F.reco, setReco: e => maj({ reco: e.target.value }),
    plans: d.plans.map(p => { const f = F.plans[p.id] || {}; return { id: p.id, modifiable: p.modifiable && !envoye, statut: PLAN_ST[p.statut] || p.statut, statutCls: PLAN_CLS[p.statut] || '', inclus: !!f.inclus,
      titre: f.titre, detail: f.detail, priorite: f.priorite, echeance: f.echeance,
      basculer: () => majPl(p.id, { inclus: !f.inclus }), setTitre: e => majPl(p.id, { titre: e.target.value }), setDetail: e => majPl(p.id, { detail: e.target.value }),
      setEcheance: e => majPl(p.id, { echeance: e.target.value }), prios: ['P0', 'P1', 'P2'].map(x => ({ v: x, nom: x, on: f.priorite === x })), setPrio: e => majPl(p.id, { priorite: e.target.value }) }; }),
    photos: d.photos.map(p => ({ src: racine() + p.chemin, genre: p.genre, inclus: !!F.photos[p.id], basculer: envoye ? null : () => maj({ photos: Object.assign({}, F.photos, { [p.id]: !F.photos[p.id] }) }) })),
    checklist: d.checklist.ok + ' / ' + d.checklist.total + ' conformes' + (d.checklist.na ? ' · ' + d.checklist.na + ' sans objet' : ''),
    ecarts: d.checklist.ecarts.map(e => e.libelle + (e.reponse ? ' : ' + e.reponse : '') + (e.commentaire ? ' — ' + e.commentaire : '')),
    nInclus: inclus.length, nInternes: d.plans.length - inclus.length,
    budget: budgetDe(v.budgetCa),
    a: d.envoi.a, source: d.envoi.source, copies: d.envoi.copies.map(x => x.nom + (x.email ? '' : ' (sans adresse)')).join(', ') || 'personne',
    envoie: d.roles.envoieNom, adapte: d.roles.adapteNom, smtp: d.envoi.smtp,
    dernier: d.envoi.dernier ? (d.envoi.dernier.envoye ? 'Dernier courriel : envoyé à ' + d.envoi.dernier.a + ' (' + (d.envoi.dernier.langue || '') + ')' + (d.envoi.dernier.pdf ? ', PDF joint' : ', sans PDF (moteur PDF muet)') + '.' : 'Dernier courriel non parti : ' + d.envoi.dernier.motif + '.') : null,
    sujet: d.apercu.sujet, texte: d.apercu.texte,
    busy: !!S.svBusy,
    enregistrer: () => rapSauver(app, id, F, false), envoyer: () => rapSauver(app, id, F, true),
    renvoyer: envoye ? () => rapRenvoyer(app, id) : null,
    voir: () => { app.setState({ svApercu: { chargement: true, titre: 'Le rapport tel que le franchisé le reçoit' } }); readOne('/suivi/rapport/apercu?visite=' + id).then(r => app.setState({ svApercu: { titre: 'Le rapport tel que le franchisé le reçoit · ' + ((r && r.langue) || '').toUpperCase(), html: r && r.html ? r.html : '<p>Aperçu indisponible.</p>' } })); }
  };
}
/** Le CA sous le budget, noté à la tablette avec le franchisé : les écarts, le pourquoi, ses trois pistes et l'échéance. */
function budgetDe(bc){
  if (!bc || (!bc.pourquoi && !(bc.pistes || []).some(p => p && p.trim()))) { return null; }
  const NOM = { semaine: 'Semaine', mois: 'Mois', trimestre: 'Trimestre', annee: 'Année' };
  const pct = e => (e > 0 ? '+' : e < 0 ? '−' : '') + String(Math.abs(e)).replace('.', ',') + ' %';
  const jm = d => d ? d.slice(8, 10) + '/' + d.slice(5, 7) : '';
  return { ecarts: Object.keys(bc.ecarts || {}).map(k => NOM[k] + ' ' + pct(bc.ecarts[k])).join(' · '), arrete: bc.arrete ? 'arrêté au ' + jm(bc.arrete) : '',
    pourquoi: bc.pourquoi || '', pistes: (bc.pistes || []).filter(p => p && p.trim()),
    horizon: bc.horizon === 'trimestre' ? 'pour la fin du trimestre' : bc.horizon === 'mois' ? 'pour le mois prochain' : '', echeance: bc.echeance ? 'd’ici le ' + jm(bc.echeance) : '',
    complet: !!bc.complet, qui: bc.qui || '' };
}
function rapCorps(F){
  return { phrase: F.phrase, positif: F.positif, sentiment: F.sentiment, execution: F.execution, clients: F.clients, causes: F.causes, diagnostic: F.diagnostic, reco: F.reco,
    plans: Object.keys(F.plans).map(id => Object.assign({ id: Number(id) }, F.plans[id])), photos: F.photos, qui: 'Cockpit' };
}
function rapSauver(app, id, F, envoyer){
  app.setState({ svBusy: true });
  app.api('PUT', '/suivi/rapport/' + id, rapCorps(F)).then(r => {
    const ok = r && r.ok !== false && !r.error && r.visite;
    if (!ok) { app.setState({ svBusy: false }); message(app, 'Refusé : ' + ((r && r.error) || 'sans réponse'), true); return; }
    if (!envoyer) { app.setState({ svBusy: false, svRapF: null, svRap: { path: '/suivi/rapport?visite=' + id, chargement: false, d: r } }); message(app, 'Rapport enregistré.'); relireTableau(app); return; }
    app.api('POST', '/suivi/rapport/' + id + '/envoyer', { qui: 'Cockpit' }).then(e => {
      const ok2 = e && e.ok !== false && !e.error && e.visite;
      app.setState({ svBusy: false, svRapF: null, svRap: ok2 ? { path: '/suivi/rapport?visite=' + id, chargement: false, d: e } : app.state.svRap });
      if (!ok2) { message(app, 'Envoi refusé : ' + ((e && e.error) || 'sans réponse'), true); return; }
      const en = e.envoi || {};
      message(app, 'Rapport envoyé : ' + pl(e.ouverts, 'point') + ' ouvert' + (e.ouverts > 1 ? 's' : '') + ' chez le franchisé' + (e.internes ? ', ' + e.internes + ' gardé' + (e.internes > 1 ? 's' : '') + ' en interne' : '') + '. '
        + (en.envoye ? 'Courriel parti à ' + en.a + (en.copies && en.copies.length ? ', copie à ' + en.copies.join(', ') : '') + (en.pdf ? ', PDF joint.' : ', sans PDF.') : 'Courriel non parti : ' + (en.motif || '—') + ' — le rapport est sur sa page.'), !en.envoye);
      relireTableau(app);
    });
  });
}
function rapRenvoyer(app, id){
  app.setState({ svBusy: true });
  app.api('POST', '/suivi/rapport/' + id + '/envoyer', { qui: 'Cockpit', renvoyer: true }).then(e => {
    app.setState({ svBusy: false });
    const en = (e && e.envoi) || {};
    message(app, en.envoye ? 'Courriel renvoyé à ' + en.a + '.' : 'Courriel non parti : ' + (en.motif || (e && e.error) || '—') + '.', !en.envoye);
    if (e && e.visite) { app.setState({ svRap: { path: '/suivi/rapport?visite=' + id, chargement: false, d: e } }); }
  });
}

/* Points et corrections : les photos à valider, tous les points ouverts. */
function pointsVals(app, sv, d){
  const S = app.state;
  sv.corrections = d.corrections.map(p => {
    const rep = S.svRep && S.svRep.id === p.id ? S.svRep : null;
    return { titre: p.court + ' · ' + p.titre, priorite: p.priorite, recu: 'photo du ' + fmtDH(p.recuLe) + (p.horsDelai ? ' · en attente depuis ' + duree(p.attenteH) : ''), horsDelai: p.horsDelai,
      avant: p.avant ? racine() + p.avant.chemin : null, avantLib: p.visiteLe ? 'visite · ' + fmtD(p.visiteLe) : 'avant', apres: p.apres ? racine() + p.apres.chemin : null, apresLib: 'correction · ' + fmtD(p.recuLe),
      commentaire: p.commentaire, valide: 'Valide les corrections : ' + p.valide,
      accepter: () => corrStatut(app, p.id, 'valide', {}), reprendre: () => app.setState({ svRep: { id: p.id, retour: '', echeance: plusJours(auj(), 2) } }),
      rep: rep ? { retour: rep.retour, echeance: rep.echeance, setRetour: e => app.setState({ svRep: Object.assign({}, rep, { retour: e.target.value }) }), setEcheance: e => app.setState({ svRep: Object.assign({}, rep, { echeance: e.target.value }) }),
        renvoyer: () => corrStatut(app, p.id, 'reprendre', { retour: rep.retour, echeance: rep.echeance }), annuler: () => app.setState({ svRep: null }) } : null };
  });
  sv.points = d.points.map(p => ({ priorite: p.priorite, court: p.court, titre: p.titre, sous: p.ref && String(p.ref).startsWith('msp') ? 'client mystère' : (p.visiteLe ? (p.visiteType || 'visite').toLowerCase() + ' du ' + fmtD(p.visiteLe) : ''),
    echeance: p.echeance ? fmtD(p.echeance) : '—', retard: p.retard ? 'retard ' + p.retard + ' j' : '', statut: PLAN_ST[p.statut] || p.statut, statutCls: PLAN_CLS[p.statut] || '' }));
}
function corrStatut(app, id, statut, plus){
  app.api('PUT', '/plans/' + id, Object.assign({ statut, role: 'admin', qui: 'Cockpit' }, plus)).then(r => {
    const ok = r && r.ok !== false && !r.error;
    app.setState({ svRep: null });
    message(app, ok ? (statut === 'valide' ? 'Correction acceptée : le consultant la fermera sur place.' : 'Renvoyé au franchisé, avec le nouveau délai.') : 'Refusé : ' + ((r && r.error) || 'sans réponse'), !ok);
    relireTableau(app);
  });
}

/* --- 2. Visite sur tablette ------------------------------------------------------------- */
export function valsSvTablette(app, common){
  const S = app.state;
  charge(app, 'svConsList', '/consultants', false);
  const L = lu(app, 'svConsList', '/consultants');
  const cons = Array.isArray(L) ? L : [];
  const id = S.svTabCons != null ? S.svTabCons : ((cons[0] || {}).id || '');
  // L'application de contrôle sur tablette (controle/, mode tablette de l'app Visites).
  const chemin = 'controle/' + (id ? '?id=' + encodeURIComponent(id) : '');
  const abs = (() => { try { return new URL(racine() + chemin, window.location.href).href; } catch (e) { return racine() + chemin; } })();
  common.sv = {
    consultants: [{ v: '', nom: 'Choisir le consultant', on: !id }].concat(cons.map(c => ({ v: c.id, nom: c.nom, on: c.id === id }))),
    setCons: e => app.setState({ svTabCons: e.target.value }),
    src: racine() + chemin, lien: abs, nom: (cons.find(c => c.id === id) || {}).nom || '',
    copier: () => app.tbCopier(abs, 'Lien copié : ouvrez-le sur la tablette, puis « Ajouter à l’écran d’accueil ».'),
    admin: racine() + 'visites/?role=admin'
  };
}

/* --- 3. Client mystère ------------------------------------------------------------------- */
export function valsSvMsp(app, common){
  const S = app.state;
  charge(app, 'svMsp', '/suivi/msp', false);
  const d = lu(app, 'svMsp', '/suivi/msp');
  const sv = { msg: S.svMsg || null, chargement: !d, indispo: d && d.error ? 'Le client mystère ne répond pas : ' + d.error : null, relire: () => charge(app, 'svMsp', '/suivi/msp', true) };
  if (!d || d.error) { common.sv = sv; return; }
  const K = d.kpis; const rub = d.rubriques.slice(0, 4);
  sv.kpis = [
    ['Réseau · derniers passages', K.moyenne != null ? nf(K.moyenne, 1) + ' / ' + nf(K.sur) : '—', (K.sur5 != null ? nf(K.sur5, 1) + ' / 5 au scoring' : 'aucun rapport') + (K.moyennePrec != null ? ' · passages précédents : ' + nf(K.moyennePrec, 1) : '')],
    ['Rubriques en alerte', nf(K.alertes.length), K.alertes.length ? K.alertes.map(a => a.court + ' · ' + a.rubrique.toLowerCase() + ' ' + nf(a.note) + ' / 20').join(' · ') : 'aucune sous ' + nf(d.seuil) + ' / 20', K.alertes.length ? 'ko' : 'ok'],
    ['Points issus du client mystère', nf(K.points), K.points ? 'ouverts · ' + K.p0 + ' P0' : 'aucun ouvert', K.p0 ? 'att' : ''],
    ['Relevés corrigés sur place', K.releves ? K.corriges + ' / ' + K.releves : '—', K.releves ? 'contrôlés à la visite suivante' : 'pas encore de visite après un passage', K.releves && K.corriges >= K.releves ? 'ok' : '']
  ];
  sv.rubriques = rub;
  sv.lignes = d.magasins.map(m => {
    const x = m.dernier; const p = m.precedent;
    const delta = x && p ? x.sur20 - p.sur20 : null;
    return { court: m.court, sous: m.responsable || '', a: !!x,
      passage: x ? fmtD(x.passage) + ' · ' + x.trimestre.replace('-T', ' T') : 'aucun passage', passageSous: x ? (x.montrer ? 'montré au franchisé' : 'pas montré au franchisé') : '',
      note: x ? nf(x.obtenu) + ' / ' + nf(x.maximum) : '—', noteSous: x && x.v != null ? nf(x.v, 1) + ' / 5' : '',
      rub: rub.map(k => { const n = x ? x.rub[k] : null; return { v: n == null ? '—' : nf(n, n % 1 ? 1 : 0), cls: n == null ? '' : (n < d.seuil ? 'ko' : (n < 16 ? 'att' : '')) }; }),
      prec: p ? nf(p.obtenu) + ' / ' + nf(p.maximum) : '—', precSous: delta != null ? (delta >= 0 ? '+' : '−') + nf(Math.abs(delta), 1) + ' / 20 · ' + p.trimestre.replace('-T', ' T') : '', precCls: delta == null ? '' : delta < 0 ? 'ko' : 'ok',
      suite: x ? (m.plans.length ? pl(m.plans.length, 'point') + ' (' + m.plans.filter(q => !['ferme', 'valide'].includes(q.statut)).length + ' ouvert' + (m.plans.filter(q => !['ferme', 'valide'].includes(q.statut)).length > 1 ? 's' : '') + ')' : 'aucun point') : '',
      suiteSous: !x ? '' : m.suiteVisite ? 'visite du ' + fmtD(m.suiteVisite.le) + ' : ' + m.suiteVisite.corriges + ' / ' + m.suiteVisite.points + ' corrigés' : (m.prochaine ? 'contrôle à la visite du ' + fmtDJ(m.prochaine.le) : 'pas encore de visite après le passage'),
      pdf: x && x.fichier ? racine() + x.fichier : null,
      reencoder: x ? () => app.setState({ svMspF: mspFormDe(d, m.id, x) }) : null };
  });
  // L'encodage d'un passage, et ce qui le suit.
  const F = S.svMspF || mspFormDe(d, (d.magasins[0] || {}).id || '', null);
  const maj = patch => app.setState({ svMspF: Object.assign({}, F, patch) });
  const mag = d.magasins.find(m => m.id === F.shop) || {};
  const somme = rub.reduce((a, k) => a + (F.rub[k] !== '' && F.rub[k] != null ? Number(String(F.rub[k]).replace(',', '.')) || 0 : 0), 0);
  sv.form = {
    magasins: d.magasins.map(m => ({ v: m.id, nom: m.court, on: m.id === F.shop })), setShop: e => maj({ shop: e.target.value }),
    passage: F.passage, setPassage: e => maj({ passage: e.target.value }),
    obtenu: F.obtenu, setObtenu: e => maj({ obtenu: e.target.value }), maximum: F.maximum, setMaximum: e => maj({ maximum: e.target.value }),
    sur5: F.obtenu !== '' && Number(F.maximum) > 0 ? nf(Number(String(F.obtenu).replace(',', '.')) / Number(F.maximum) * 5, 1) : null,
    somme: somme ? nf(somme, 1) + ' / ' + rub.length * 20 : '',
    rubriques: rub.map(k => ({ nom: k, v: F.rub[k] == null ? '' : F.rub[k], ko: F.rub[k] !== '' && F.rub[k] != null && Number(String(F.rub[k]).replace(',', '.')) < d.seuil, set: e => maj({ rub: Object.assign({}, F.rub, { [k]: e.target.value }) }) })),
    commentaire: F.commentaire, setCommentaire: e => maj({ commentaire: e.target.value }),
    fichierNom: F.fichierNom, setFichier: e => { const f = e.target.files && e.target.files[0]; if (!f) { return; } const r = new FileReader(); r.onload = () => app.setState({ svMspF: Object.assign({}, app.state.svMspF || F, { fichier: r.result, fichierNom: f.name + ' · ' + nf(f.size / 1048576, 1) + ' Mo' }) }); r.readAsDataURL(f); },
    montrer: F.montrer, basculerMontrer: () => maj({ montrer: !F.montrer }),
    points: F.points.map((p, i) => ({ titre: p.titre, prios: ['P0', 'P1', 'P2'].map(x => ({ v: x, nom: x, on: p.priorite === x })), setPrio: e => maj({ points: F.points.map((q, j) => j === i ? Object.assign({}, q, { priorite: e.target.value }) : q) }),
      setTitre: e => maj({ points: F.points.map((q, j) => j === i ? Object.assign({}, q, { titre: e.target.value }) : q) }), retirer: () => maj({ points: F.points.filter((_, j) => j !== i) }) })),
    ajouterPoint: () => maj({ points: F.points.concat([{ titre: '', priorite: 'P2' }]) }),
    proposer: () => maj({ points: F.points.concat(rub.filter(k => F.rub[k] !== '' && F.rub[k] != null && Number(String(F.rub[k]).replace(',', '.')) < 16)
      .map(k => ({ titre: k + ' : ' + F.rub[k] + ' / 20 au client mystère — à corriger', priorite: Number(String(F.rub[k]).replace(',', '.')) < d.seuil ? 'P1' : 'P2' }))) }),
    controle: F.controle, basculerControle: () => maj({ controle: !F.controle }), prochaine: mag.prochaine ? fmtDJ(mag.prochaine.le) + ', ' + mag.prochaine.consultant : null,
    tache: F.tache, basculerTache: () => maj({ tache: !F.tache }), responsable: mag.responsable || '',
    delais: 'P0 ' + d.delais.p0 + ' j · P1 ' + d.delais.p1 + ' j · P2 ' + d.delais.p2 + ' j (Qui fait quoi)',
    busy: !!S.svBusy, enregistrer: () => mspSauver(app, F, rub), vider: () => app.setState({ svMspF: mspFormDe(d, F.shop, null) })
  };
  common.sv = sv;
}
function mspFormDe(d, shop, x){
  const rub = {}; d.rubriques.slice(0, 4).forEach(k => { rub[k] = x && x.rub[k] != null ? String(x.rub[k]) : ''; });
  return { shop, passage: x ? x.passage : auj(), obtenu: x ? String(x.obtenu) : '', maximum: x ? String(x.maximum) : '80', rub, commentaire: x ? (x.commentaire || '') : '', fichier: null, fichierNom: x && x.fichier ? x.fichier.split('/').pop() + ' (déjà déposé)' : '',
    montrer: x ? x.montrer : true, points: [], controle: true, tache: false };
}
function mspSauver(app, F, rub){
  const rubriques = {}; rub.forEach(k => { if (F.rub[k] !== '' && F.rub[k] != null) { rubriques[k] = Number(String(F.rub[k]).replace(',', '.')); } });
  app.setState({ svBusy: true });
  app.api('POST', '/suivi/msp', { shop: F.shop, passage_le: F.passage, obtenu: F.obtenu !== '' ? Number(String(F.obtenu).replace(',', '.')) : null, maximum: F.maximum !== '' ? Number(F.maximum) : null,
    rubriques, commentaire: F.commentaire, fichier: F.fichier || null, montrer: F.montrer, qui: 'Cockpit',
    suites: { points: F.points.filter(p => (p.titre || '').trim()), controle: F.controle, tache: F.tache } }).then(r => {
    const ok = r && r.ok !== false && !r.error && r.msp;
    app.setState({ svBusy: false });
    if (!ok) { message(app, 'Refusé : ' + ((r && r.error) || 'sans réponse'), true); return; }
    const s = r.suites || {};
    message(app, 'Passage encodé : ' + nf(r.msp.obtenu) + ' / ' + nf(r.msp.maximum) + '. ' + (s.points && s.points.length ? pl(s.points.length, 'point') + ' ouvert' + (s.points.length > 1 ? 's' : '') + ' chez le franchisé. ' : '')
      + (s.controle ? (s.controle.visite ? 'Contrôle ajouté à la visite du ' + fmtDJ(s.controle.le) + '. ' : 'Contrôle : ' + s.controle.motif + '. ') : '') + (s.tache ? (s.tache.consultant ? 'Tâche donnée à ' + s.tache.consultant + '.' : 'Tâche : ' + s.tache.motif + '.') : ''));
    app.setState({ svMspF: null }); charge(app, 'svMsp', '/suivi/msp', true);
  });
}

/* --- 4. Page du franchisé ---------------------------------------------------------------- */
export function valsSvFranchise(app, common){
  const S = app.state;
  const mags = app.open().map(s => ({ id: String(s.id), court: String(s.nom).split(' - ').pop() }));
  const shop = String(S.svFrShop || (mags[0] || {}).id || '');
  const path = '/suivi/lien?shop=' + encodeURIComponent(shop);
  if (shop) { charge(app, 'svLien', path, false); }
  const d = shop ? lu(app, 'svLien', path) : null;
  const sv = { msg: S.svMsg || null, magasins: mags.map(m => ({ v: m.id, nom: 'Aperçu comme ' + m.court, on: m.id === shop })), setShop: e => app.setState({ svFrShop: e.target.value }),
    chargement: !d, indispo: d && d.error ? 'Le lien ne se lit pas : ' + d.error : null };
  if (d && !d.error) {
    const V = d.voit || {};
    Object.assign(sv, { url: d.url, src: racine() + d.chemin + '&apercu=1', adresse: d.adresse, source: d.source, langue: d.langueNom, sujet: d.sujet, texte: d.texte, smtp: d.smtp,
      ouvert: !!S.svLienOuvert, basculer: () => app.setState({ svLienOuvert: !S.svLienOuvert }),
      copier: () => app.tbCopier(d.url, 'Lien copié : il ouvre la page du franchisé sans mot de passe.'),
      envoyer: () => { app.setState({ svBusy: true }); app.api('POST', '/suivi/lien/envoyer', { shop, qui: 'Cockpit' }).then(r => { app.setState({ svBusy: false }); const en = (r && r.envoi) || {}; message(app, en.envoye ? 'Lien envoyé à ' + en.a + ' (' + (en.langue || '') + ').' : 'Lien non envoyé : ' + (en.motif || (r && r.error) || '—') + '.', !en.envoye); }); },
      busy: !!S.svBusy,
      voit: VOIT.map(([k, l]) => ({ l, on: !!V[k] })),
      regler: () => app.setState({ screen: 'suiviAdmin' }) });
  }
  common.sv = sv;
}

/* --- 5. Qui fait quoi ------------------------------------------------------------------------ */
export function valsSvAdmin(app, common){
  const S = app.state;
  charge(app, 'svRegles', '/suivi/regles', false);
  const d = lu(app, 'svRegles', '/suivi/regles');
  const sv = { msg: S.svMsg || null, chargement: !d, indispo: d && d.error ? 'Les règles ne se lisent pas : ' + d.error : null };
  if (!d || d.error) { common.sv = sv; return; }
  const R = d.regles;
  const F = S.svReglesF || { magasins: Object.fromEntries(d.magasins.map(m => [m.id, Object.assign({}, m.regle)])), droits: Object.fromEntries(d.personnes.map(p => [p.id, Object.assign({}, p.droits)])),
    admins: R.admins.map(a => Object.assign({}, a)), delais: Object.assign({}, R.delais), voit: Object.assign({}, R.voit), types: Object.fromEntries(d.types.map(t => [t.code, t.annoncee])), modifie: false };
  const maj = patch => app.setState({ svReglesF: Object.assign({}, F, patch, { modifie: true }) });
  const majMag = (id, patch) => maj({ magasins: Object.assign({}, F.magasins, { [id]: Object.assign({}, F.magasins[id], patch) }) });
  const pers = d.personnes.map(p => ({ id: p.id, nom: p.nom })).concat(F.admins.filter(a => !d.personnes.some(p => p.id === a.id)).map(a => ({ id: a.id, nom: a.nom })));
  const peut = (pid, droit) => { const x = F.droits[pid]; return x ? !!x[droit] : true; };
  const opts = (val, base, droit) => base.concat(pers.filter(p => !droit || peut(p.id, droit)).map(p => ({ v: p.id, nom: p.nom }))).map(o => Object.assign(o, { on: o.v === val }));
  sv.kpis = [
    ['Magasins couverts', d.kpis.couverts + ' / ' + d.kpis.magasins, d.kpis.couverts === d.kpis.magasins ? 'chacun a un consultant responsable' : 'un responsable se règle au cadre de visite', d.kpis.couverts < d.kpis.magasins ? 'att' : ''],
    ['Cadre de ' + MOIS[new Date().getMonth()], d.kpis.cadre.attendu ? d.kpis.cadre.faites + ' / ' + d.kpis.cadre.attendu : '—', d.kpis.cadre.planifiees + ' planifiée' + (d.kpis.cadre.planifiees > 1 ? 's' : '') + ' · visites faites'],
    ['Délai moyen d’envoi', d.kpis.envoiMoyenH != null ? duree(d.kpis.envoiMoyenH) : '—', 'règle : ' + R.delais.rapportHeures + ' h · ' + (d.kpis.envois ? d.kpis.horsDelai + ' hors délai sur ' + d.kpis.envois : 'aucun rapport envoyé'), d.kpis.horsDelai ? 'att' : (d.kpis.envois ? 'ok' : '')],
    ['Corrections validées en', d.kpis.validationMoyenneH != null ? duree(d.kpis.validationMoyenneH) : '—', 'en moyenne · règle : ' + R.delais.validationHeures + ' h']
  ];
  sv.magasins = d.magasins.map(m => { const r = F.magasins[m.id] || {}; return { court: m.court, responsable: m.regle.responsableNom ? 'responsable ' + m.regle.responsableNom : 'pas de responsable au cadre', cadre: m.cadre.join(' · ') || '—',
    prepare: opts(r.prepare, [{ v: '', nom: 'le responsable' }], 'preparer'), setPrepare: e => majMag(m.id, { prepare: e.target.value }),
    adapte: opts(r.adapte, [{ v: 'visite', nom: 'le consultant de la visite' }, { v: 'responsable', nom: 'le responsable' }], 'adapter'), setAdapte: e => majMag(m.id, { adapte: e.target.value }),
    envoie: opts(r.envoie, [{ v: 'visite', nom: 'le consultant de la visite' }, { v: 'responsable', nom: 'le responsable' }], 'envoyer'), setEnvoie: e => majMag(m.id, { envoie: e.target.value }),
    corrections: opts(r.corrections, [{ v: 'admin', nom: 'l’admin réseau' }, { v: 'responsable', nom: 'le responsable' }], 'valider'), setCorrections: e => majMag(m.id, { corrections: e.target.value }),
    copie: pers.map(p => ({ nom: p.nom, on: (r.copie || []).includes(p.id), basculer: () => majMag(m.id, { copie: (r.copie || []).includes(p.id) ? r.copie.filter(x => x !== p.id) : (r.copie || []).concat([p.id]) }) })),
    email: r.email || '', setEmail: e => majMag(m.id, { email: e.target.value.trim() }), emailAide: !r.email && m.adresse.email ? 'sinon ' + m.adresse.email + ' (' + m.adresse.source + ')' : (!r.email ? 'aucune adresse : rien ne partira' : ''), emailKo: !r.email && !m.adresse.email,
    langues: [{ v: '', nom: 'auto · ' + d.langues[m.langueDefaut] }].concat(Object.keys(d.langues).map(k => ({ v: k, nom: d.langues[k] }))).map(o => Object.assign(o, { on: o.v === (r.langue || '') })), setLangue: e => majMag(m.id, { langue: e.target.value }) }; });
  sv.types = d.types.filter(t => t.actif !== false).map(t => ({ nom: t.nom, annoncee: !!F.types[t.code], annoncer: () => maj({ types: Object.assign({}, F.types, { [t.code]: true }) }), inopiner: () => maj({ types: Object.assign({}, F.types, { [t.code]: false }) }) }));
  sv.droits = Object.keys(DROITS).map(k => DROITS[k]);
  sv.personnes = d.personnes.map(p => ({ nom: p.nom, sous: p.profilNom + (p.email ? '' : ' · sans adresse'), admin: p.genre === 'admin',
    droits: Object.keys(DROITS).map(k => ({ on: peut(p.id, k), basculer: () => maj({ droits: Object.assign({}, F.droits, { [p.id]: Object.assign({}, F.droits[p.id] || p.droits, { [k]: !peut(p.id, k) }) }) }) })),
    retirer: p.genre === 'admin' ? () => maj({ admins: F.admins.filter(a => a.id !== p.id) }) : null }));
  const nA = S.svNouvelAdmin || { nom: '', email: '', role: '' };
  sv.nouvelAdmin = { nom: nA.nom, email: nA.email, role: nA.role, setNom: e => app.setState({ svNouvelAdmin: Object.assign({}, nA, { nom: e.target.value }) }), setEmail: e => app.setState({ svNouvelAdmin: Object.assign({}, nA, { email: e.target.value }) }), setRole: e => app.setState({ svNouvelAdmin: Object.assign({}, nA, { role: e.target.value }) }),
    ajouter: () => { if (!nA.nom.trim()) { message(app, 'Donnez un nom à la personne.', true); return; } const id = 'a' + Date.now().toString(36).slice(-5); app.setState({ svNouvelAdmin: null, svReglesF: Object.assign({}, F, { admins: F.admins.concat([{ id, nom: nA.nom.trim(), email: nA.email.trim(), role: nA.role.trim() }]), modifie: true }) }); } };
  sv.nouveauxAdmins = F.admins.filter(a => !d.personnes.some(p => p.id === a.id)).map(a => a.nom + ' (à enregistrer)');
  const De = F.delais; const setD = (k, num) => e => maj({ delais: Object.assign({}, De, { [k]: num ? Number(e.target.value) : e.target.value }) });
  sv.delais = { prepJours: De.prepJours, setPrepJours: setD('prepJours', true), odjAuto: !!De.odjAuto, setOdjAuto: v => () => maj({ delais: Object.assign({}, De, { odjAuto: v }) }),
    odjJours: De.odjJours, setOdjJours: setD('odjJours', true), rapportHeures: De.rapportHeures, setRapportHeures: setD('rapportHeures', true),
    rapportTard: De.rapportTard, setRapportTard: v => () => maj({ delais: Object.assign({}, De, { rapportTard: v }) }),
    p0: De.p0, setP0: setD('p0', true), p1: De.p1, setP1: setD('p1', true), p2: De.p2, setP2: setD('p2', true),
    validationHeures: De.validationHeures, setValidationHeures: setD('validationHeures', true), escaladeJours: De.escaladeJours, setEscaladeJours: setD('escaladeJours', true),
    relanceLuJours: De.relanceLuJours, setRelanceLuJours: setD('relanceLuJours', true) };
  sv.voit = VOIT.map(([k, l]) => ({ l, on: !!F.voit[k], basculer: () => maj({ voit: Object.assign({}, F.voit, { [k]: !F.voit[k] }) }) }));
  sv.modifie = !!F.modifie; sv.busy = !!S.svBusy;
  sv.enregistrer = () => reglesSauver(app, F);
  sv.annuler = F.modifie ? () => app.setState({ svReglesF: null }) : null;
  // Les courriels aux franchisés : un modèle, une langue, l'aperçu, l'essai.
  const codes = Object.keys(d.modeles); const code = codes.includes(S.svModCode) ? S.svModCode : codes[0];
  const langs = Object.keys(d.langues); const lg = langs.includes(S.svModLang) ? S.svModLang : 'fr';
  const M = d.modeles[code];
  const MF = S.svModF && S.svModF.code === code && S.svModF.lang === lg ? S.svModF : { code, lang: lg, sujet: M.textes[lg].sujet, corps: M.textes[lg].corps, change: false };
  const majM = patch => app.setState({ svModF: Object.assign({}, MF, patch, { change: true }), svModApercu: null });
  sv.modeles = codes.map(k => ({ nom: d.modeles[k].nom, on: k === code, modifie: Object.values(d.modeles[k].modifie).some(Boolean), choisir: () => app.setState({ svModCode: k, svModF: null, svModApercu: null }) }));
  sv.langues = langs.map(k => ({ nom: d.langues[k], on: k === lg, modifie: !!M.modifie[k], choisir: () => app.setState({ svModLang: k, svModF: null, svModApercu: null }) }));
  sv.modele = { nom: M.nom, quand: M.quand, vars: M.vars.map(v => '{' + v + '}'), sujet: MF.sujet, corps: MF.corps, setSujet: e => majM({ sujet: e.target.value }), setCorps: e => majM({ corps: e.target.value }),
    change: !!MF.change, modifie: !!M.modifie[lg], apercu: S.svModApercu || null, smtp: d.smtp,
    essaiA: S.svEssaiA || '', setEssaiA: e => app.setState({ svEssaiA: e.target.value }),
    voir: () => app.api('POST', '/suivi/modeles/apercu', { code, langue: lg, sujet: MF.sujet, corps: MF.corps }).then(r => app.setState({ svModApercu: r && r.html ? { sujet: r.sujet, html: r.html } : { sujet: '', html: '<p>Aperçu indisponible.</p>' } })),
    enregistrer: () => modeleSauver(app, code, lg, { sujet: MF.sujet, corps: MF.corps }),
    remettre: M.modifie[lg] ? () => modeleSauver(app, code, lg, { reset: true }) : null,
    essai: () => { if (!S.svEssaiA) { message(app, 'Donnez l’adresse d’essai.', true); return; } app.api('POST', '/suivi/modeles/essai', { code, langue: lg, a: S.svEssaiA }).then(r => message(app, r && r.ok && r.a && !r.motif ? 'Essai envoyé à ' + r.a + ' (le texte enregistré, avec des valeurs d’exemple).' : 'Essai non envoyé : ' + ((r && (r.motif || r.error)) || '—') + '.', !(r && r.ok && !r.motif))); } };
  common.sv = sv;
}
function reglesSauver(app, F){
  const delais = Object.assign({}, F.delais);
  app.setState({ svBusy: true });
  app.api('PUT', '/suivi/regles', { magasins: F.magasins, droits: F.droits, admins: F.admins, delais, voit: F.voit, types: F.types, qui: 'Cockpit' }).then(r => {
    const ok = r && r.ok !== false && !r.error && r.regles;
    app.setState({ svBusy: false, svReglesF: ok ? null : app.state.svReglesF, svRegles: ok ? { path: '/suivi/regles', chargement: false, d: r } : app.state.svRegles });
    message(app, ok ? 'Règles enregistrées : elles valent pour les visites planifiées à partir de maintenant.' : 'Refusé : ' + ((r && r.error) || 'sans réponse'), !ok);
  });
}
function modeleSauver(app, code, lg, texte){
  app.api('PUT', '/suivi/regles', { modeles: { [code]: { [lg]: texte } }, qui: 'Cockpit' }).then(r => {
    const ok = r && r.ok !== false && !r.error && r.regles;
    app.setState({ svModF: null, svModApercu: null, svRegles: ok ? { path: '/suivi/regles', chargement: false, d: r } : app.state.svRegles });
    message(app, ok ? (texte.reset ? 'Modèle remis au texte livré.' : 'Modèle enregistré.') : 'Refusé : ' + ((r && r.error) || 'sans réponse'), !ok);
  });
}

/* --- 6. Sources et API (26/10/2026, Sam : « quelles API viennent d'atelier.tfbuddy.com, quelles viennent de table
 * locale, et la liste des API manquantes avec la structure nécessaire et la structure de la table. Tout doit être API
 * en lecture et en écriture ») — GET /suivi/sources (src/suivi_sources.php), lu dans le code et dans la base à chaque
 * ouverture. Cinq onglets : l'ensemble, par écran, le panel, les tables locales, les API manquantes (avec leur
 * spécification à copier ou à télécharger en Markdown pour TFBuddy). ------------------------------------------- */
const SS_ONGLETS = [['ensemble', 'Vue d’ensemble'], ['ecrans', 'Par écran'], ['panel', 'Panel TFBuddy'], ['tables', 'Tables locales'], ['manquantes', 'API manquantes']];
const SS_MODE = { lecture: 'lu', ecriture: 'écrit' };
function ssSpecTable(m, t){
  const l = ['## ' + m.ressource + ' — table `' + m.table + '`', '', m.quoi + '.', '', '| Verbe | Chemin | Rôle |', '|---|---|---|'];
  m.endpoints.forEach(e => l.push('| ' + e.m + ' | `' + e.chemin + '` | ' + e.dit + ' |'));
  const corps = (m.endpoints.find(e => e.corps && (e.m === 'POST' || e.m === 'PUT')) || {}).corps;
  if (corps) { l.push('', 'Corps (JSON) :', '', '```json', JSON.stringify(corps, null, 2), '```'); }
  if (t && t.champs.length) {
    l.push('', '| Champ | Type SQL | Obligatoire | Défaut |', '|---|---|---|---|');
    t.champs.forEach(c => l.push('| `' + c.nom + '`' + (c.cle === 'PRI' ? ' (clé)' : '') + (c.auto ? ' (auto)' : '') + ' | ' + c.sql + ' | ' + (c.requis ? 'oui' : 'non') + ' | ' + (c.defaut == null ? '' : (c.defaut === '' ? 'chaîne vide' : '`' + c.defaut + '`')) + ' |'));
  }
  if (t && t.ddl) { l.push('', 'Structure actuelle (cockpit) :', '', '```sql', t.ddl, '```'); }
  if (m.routes.length) { l.push('', 'Remplacerait, côté cockpit : ' + m.routes.map(r => '`' + r + '`').join(', ') + '.'); }
  return l.join('\n');
}
function ssSpecTout(d){
  const reg = {}; d.registre.forEach(t => { reg[t.nom] = t; });
  const tete = ['# API à ouvrir au panel TFBuddy — consultants et visites', '', 'Généré par le cockpit le ' + d.genere + ' (lu dans le code et dans la base ' + (d.base || '') + ').',
    'Hôte : ' + ((d.hotes.panel || {}).hote || 'atelierby.tfbuddy.com') + '/api/v1. Chaque ressource en lecture ET en écriture ; listes paginées `{ data, meta: { total, page, per_page } }`.', ''];
  const directes = ['', '## Lectures à ouvrir — tables TFBuddy lues aujourd’hui directement dans la base', '', '| Table | Ce qu’elle porte | Au panel aujourd’hui | À ouvrir |', '|---|---|---|---|']
    .concat(d.directes.map(t => '| `' + t.nom + '` | ' + t.quoi + ' | ' + (t.existe ? '`' + t.existe + '`' : '—') + ' | ' + (t.propose ? '`' + t.propose + '`' : (t.existe ? 'basculer sur la route existante' : '—')) + ' |'));
  return tete.concat(d.manquantes.map(m => ssSpecTable(m, reg[m.table]) + '\n')).concat(directes).join('\n');
}
export function valsSvSources(app, common){
  const S = app.state;
  const path = '/suivi/sources';
  charge(app, 'svSrc', path, false);
  const b = S.svSrc && S.svSrc.path === path ? S.svSrc : null;
  const d = lu(app, 'svSrc', path);
  const sv = { msg: S.svMsg, relire: () => charge(app, 'svSrc', path, true), enCours: !!(b && b.chargement) };
  common.sv = sv;
  if (!d) { sv.chargement = true; return; }
  if (d.error) { sv.indispo = 'Le rapport n’a pas pu être lu : ' + d.error + '.'; return; }
  const og = SS_ONGLETS.some(o => o[0] === S.svSrcOg) ? S.svSrcOg : 'ensemble';
  const ouv = S.svSrcOuv || '';
  const basculer = k => () => app.setState({ svSrcOuv: ouv === k ? '' : k });
  const q = String(S.svSrcQ || '').trim().toLowerCase();
  const garde = txt => !q || String(txt).toLowerCase().indexOf(q) >= 0;
  const reg = {}; d.registre.forEach(t => { reg[t.nom] = t; });
  const panel = d.chemins.filter(c => c.source === 'panel'), erp = d.chemins.filter(c => c.source === 'erp');
  const lignes = d.registre.reduce((a, t) => a + (t.lignes || 0), 0);
  const verbes = d.manquantes.reduce((a, m) => a + m.endpoints.length, 0);
  const hote = (d.hotes.panel || {}).hote || 'atelierby.tfbuddy.com';
  sv.genere = d.genere; sv.genereLib = fmtDH(d.genere); sv.base = d.base; sv.limites = d.limites; sv.hote = hote;
  sv.panelConfigure = !!(d.hotes.panel || {}).configure;
  sv.kpis = [
    ['Panel ' + hote, pl(panel.length, 'chemin'), panel.filter(c => c.ecriture).length + ' en écriture · ' + (sv.panelConfigure ? 'compte configuré' : 'compte non configuré ici'), ''],
    ['Tables locales con_', pl(d.registre.length, 'table'), nf(lignes) + ' lignes · lues et écrites par le cockpit', ''],
    ['Base TFBuddy, sans API', pl(d.directes.length, 'table'), 'lues directement dans la base partagée', d.directes.length ? 'att' : 'ok'],
    ['API à ouvrir au panel', pl(d.manquantes.length + d.directes.filter(t => t.propose).length, 'ressource'), verbes + ' verbes pour les ' + d.manquantes.length + ' tables con_ · ' + d.directes.filter(t => t.propose).length + ' lectures · ' + d.directes.filter(t => t.existe && !t.propose).length + ' routes existantes à employer', 'att']
  ];
  sv.onglets = SS_ONGLETS.map(([k, nom]) => ({ nom, on: k === og, n: { ensemble: '', ecrans: d.routes.length, panel: d.chemins.length, tables: d.registre.length, manquantes: d.manquantes.length + d.directes.length }[k],
    ouvrir: () => app.setState({ svSrcOg: k, svSrcOuv: '' }) }));
  sv.og = og;
  sv.q = S.svSrcQ || ''; sv.setQ = e => app.setState({ svSrcQ: e.target.value });
  const md = () => ssSpecTout(d);
  sv.copierTout = () => app.tbCopier(md(), 'La liste des API manquantes est copiée (Markdown) : à coller dans un courriel ou un ticket pour TFBuddy.');
  sv.telecharger = () => { try { const u = URL.createObjectURL(new Blob([md()], { type: 'text/markdown' })); const a = document.createElement('a'); a.href = u; a.download = 'api-manquantes-consultants-' + d.genere.slice(0, 10) + '.md'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(u), 1000); } catch (e) { message(app, 'Téléchargement impossible : ' + e.message, true); } };
  const mig = d.migration;
  sv.migration = mig ? 'Renommées sous con_ le ' + fmtDH(mig.le) + ' : ' + pl(Object.keys(mig.renommees || {}).length, 'table') + (mig.conflits && mig.conflits.length ? ' · à trancher : ' + mig.conflits.join(' ; ') : '') + '.'
    : (d.registre.some(t => t.ancienExiste) ? 'Des tables portent encore leur ancien nom : la migration se fera au prochain appel après déploiement.' : 'Tables créées directement sous con_.');
  // Vue d'ensemble
  const parUsage = panel.slice().sort((a, x) => x.routes.length - a.routes.length);
  sv.ensemble = {
    panel: parUsage.slice(0, 10).map(c => ({ m: c.m, chemin: c.chemin, n: c.routes.length })), panelReste: Math.max(0, parUsage.length - 10),
    erp: erp.map(c => ({ m: c.m, chemin: c.chemin, n: c.routes.length })),
    familles: Object.values(d.registre.reduce((o, t) => { (o[t.famille] = o[t.famille] || { nom: t.famille, tables: [] }).tables.push({ nom: t.nom, lignes: t.lignes, existe: t.existe }); return o; }, {})),
    directes: d.directes.slice(0, 8).map(t => ({ nom: t.nom, quoi: t.quoi, vers: t.existe || t.propose || '', existe: !!t.existe })), directesReste: Math.max(0, d.directes.length - 8),
    allerManquantes: () => app.setState({ svSrcOg: 'manquantes', svSrcOuv: '' }), allerPanel: () => app.setState({ svSrcOg: 'panel', svSrcOuv: '' }), allerTables: () => app.setState({ svSrcOg: 'tables', svSrcOuv: '' }),
    autres: d.autres.length
  };
  // Par écran
  sv.ecrans = Object.keys(d.ecrans).map(e => ({ nom: d.ecrans[e], routes: d.routes.filter(r => r.ecran === e && garde(r.methode + ' ' + r.chemin + ' ' + r.quoi + ' ' + r.fonction)).map(r => {
    const k = 'r:' + r.methode + r.chemin;
    return { m: r.methode, chemin: r.chemin, quoi: r.quoi, fonction: r.fonction, fichier: r.fichier, existe: r.existe,
      nPanel: r.panel.length + r.erp.length, nCon: r.con.length, nTfb: r.tfbuddy.length, nCk: r.cockpit.length, externes: r.externes,
      source: r.panel.length || r.erp.length ? (r.con.length || r.tfbuddy.length || r.cockpit.length ? 'mixte' : 'panel') : 'locale',
      ouvert: ouv === k, basculer: basculer(k),
      detail: ouv === k ? { panel: r.panel.concat(r.erp.map(c => Object.assign({ erp: true }, c))), con: r.con.map(t => ({ nom: t.nom, mode: SS_MODE[t.mode] })), tfbuddy: r.tfbuddy.map(t => ({ nom: t.nom, mode: SS_MODE[t.mode] })), cockpit: r.cockpit.map(t => ({ nom: t.nom, mode: SS_MODE[t.mode] })), installe: r.installe || [] } : null };
  }) })).filter(e => e.routes.length);
  // Panel
  sv.chemins = d.chemins.filter(c => garde(c.m + ' ' + c.chemin + ' ' + c.routes.join(' '))).map(c => { const k = 'p:' + c.source + c.m + c.chemin;
    return { m: c.m, chemin: c.chemin, erp: c.source === 'erp', ecriture: c.ecriture, n: c.routes.length, routes: c.routes, ouvert: ouv === k, basculer: basculer(k) }; });
  // Tables locales
  sv.tables = d.registre.filter(t => garde(t.nom + ' ' + t.ancien + ' ' + t.quoi + ' ' + t.famille)).map(t => { const k = 't:' + t.nom;
    return { nom: t.nom, ancien: t.ancien, famille: t.famille, quoi: t.quoi, lignes: t.lignes, existe: t.existe, ancienExiste: t.ancienExiste, n: t.routes.length, ecrite: t.ecrite,
      etat: t.existe === false ? 'pas encore créée' : (t.ancienExiste ? 'l’ancien nom existe encore' : 'renommée'), etatCls: t.existe === false ? 'mu' : (t.ancienExiste ? 'att' : 'ok'),
      ouvert: ouv === k, basculer: basculer(k), champs: t.champs, ddl: t.ddl, routes: t.routes }; });
  sv.autres = d.autres.filter(t => garde(t.nom)).map(t => ({ nom: t.nom, ecrite: t.ecrite, n: t.routes.length, lignes: t.lignes }));
  // API manquantes
  sv.manquantes = d.manquantes.filter(m => garde(m.table + ' ' + m.ressource + ' ' + m.quoi)).map(m => { const k = 'm:' + m.table; const t = reg[m.table] || { champs: [] };
    const corps = (m.endpoints.find(e => e.corps && (e.m === 'POST' || e.m === 'PUT')) || {}).corps;
    return { table: m.table, ressource: m.ressource, famille: m.famille, quoi: m.quoi, endpoints: m.endpoints, n: m.routes.length, lue: m.structureLue,
      corps: corps ? JSON.stringify(corps, null, 2) : '', champs: t.champs || [], ddl: t.ddl || '', ouvert: ouv === k, basculer: basculer(k),
      copier: () => app.tbCopier(ssSpecTable(m, t), 'Spécification de ' + m.ressource + ' copiée (Markdown).') }; });
  sv.directes = d.directes.filter(t => garde(t.nom + ' ' + t.quoi)).map(t => ({ nom: t.nom, quoi: t.quoi, existe: t.existe, propose: t.propose, n: t.routes.length, routes: t.routes, colonnes: t.colonnes, lignes: t.lignes, ouvert: ouv === 'd:' + t.nom, basculer: basculer('d:' + t.nom) }));
}
