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

/* --- 1. Tableau de bord ---------------------------------------------------------------- */
export function valsSvTableau(app, common){
  const S = app.state;
  const onglet = S.svOnglet || 'ensemble';
  const cons = S.svCons || ''; const sem = S.svSem || 0;
  const path = '/suivi/tableau?consultant=' + encodeURIComponent(cons) + '&semaine=' + sem;
  charge(app, 'svT', path, false);
  const d = lu(app, 'svT', path);
  const sv = { onglet, msg: S.svMsg || null, chargement: !d, indispo: d && d.error ? 'Le tableau de bord ne répond pas : ' + d.error : null };
  const K = d && !d.error ? d.kpis : null;
  sv.onglets = [['ensemble', 'Vue d’ensemble'], ['preparer', 'Préparer'], ['adapter', 'Adapter et envoyer'], ['points', 'Points et corrections']]
    .map(([v, nom]) => ({ nom, on: onglet === v, badge: K ? ({ preparer: K.aPreparer, adapter: K.aAdapter, points: K.corrections }[v] || 0) : 0, choisir: () => app.setState({ svOnglet: v }) }));
  sv.consultants = [{ v: '', nom: 'Tous les consultants', on: !cons }].concat(((d && d.consultants) || []).map(c => ({ v: c.id, nom: c.nom, on: c.id === cons })));
  sv.setCons = e => app.setState({ svCons: e.target.value });
  sv.semPrec = () => app.setState({ svSem: sem - 1 }); sv.semSuiv = () => app.setState({ svSem: sem + 1 });
  sv.semLib = d && d.semaine ? 'semaine du ' + fmtD(d.semaine.du) + ' au ' + fmtD(d.semaine.au) : 'semaine';
  sv.relire = () => { charge(app, 'svT', path, true); if (S.svPrep && S.svPrep.path) { charge(app, 'svPrep', S.svPrep.path, true); } if (S.svRap && S.svRap.path) { charge(app, 'svRap', S.svRap.path, true); } };
  sv.planifier = () => app.setState({ screen: 'gestionConsultant', gcOnglet: 'planning', gcPlanif: { shop: '', type: 'reguliere', consultant: '', date: plusJours(auj(), 1), heure: '09:00', duree: '', motif: 'reguliere' } });
  sv.lu = d && d.lu ? 'relu à ' + d.lu.slice(11, 16) : '';
  if (!K) { common.sv = sv; return; }
  const D = d.delais;
  sv.kpis = [
    ['Visites cette semaine', nf(K.visitesSemaine), K.aPreparer + ' à préparer · ' + K.surTablette + ' sur la tablette'],
    ['Rapports à adapter', nf(K.aAdapter), K.urgent ? K.urgentTxt : 'à envoyer sous ' + D.rapportHeures + ' h après la visite', K.urgent ? 'ko' : (K.aAdapter ? 'att' : '')],
    ['Rapports envoyés · ' + MOIS[new Date().getMonth()], nf(K.envoyesMois), K.envoyesMois ? K.lusMois + ' lu' + (K.lusMois > 1 ? 's' : '') + ' par le franchisé' : 'aucun ce mois-ci'],
    ['Points ouverts chez les franchisés', nf(K.points), K.p0 + ' P0 · ' + K.retard + ' en retard', K.p0 || K.retard ? 'att' : ''],
    ['Corrections à valider', nf(K.corrections), 'photos envoyées par les franchisés', K.corrections ? 'att' : '']
  ];
  const carte = (c, et) => {
    let ech = '', cls = '';
    if (et === 'preparer') { ech = (c.prepRetard ? 'dossier attendu le ' + fmtDJ(c.prepDu) + ', en retard' : 'dossier attendu le ' + fmtDJ(c.prepDu)); cls = c.prepRetard ? 'ko' : 'att'; }
    if (et === 'tablette') { ech = c.nonFaite ? 'date passée, pas encore faite' : c.statut === 'en_cours' ? 'visite en cours' : (c.prepEnvoyeeLe ? 'dossier envoyé le ' + fmtDJ(c.prepEnvoyeeLe) : 'sans dossier du bureau'); cls = c.nonFaite ? 'ko' : 'ok'; }
    if (et === 'adapter') { const dl = c.delai; ech = dl ? (dl.depasse ? 'délai dépassé de ' + duree(dl.resteH) : 'à envoyer avant ' + fmtDH(dl.limite)) : ''; cls = dl && (dl.depasse || dl.resteH < 12) ? 'ko' : 'att'; }
    if (et === 'envoye') { ech = c.luLe ? 'lu le ' + fmtDJ(c.luLe) : (c.relanceLe ? 'pas encore lu · relancé le ' + fmtDJ(c.relanceLe) : 'pas encore lu'); cls = c.luLe ? 'ok' : 'ko'; }
    const sous = et === 'preparer' ? (c.dossier.points ? pl(c.dossier.points, 'point') + ' du bureau' : 'dossier assemblé tout seul')
      : et === 'adapter' ? 'brouillon du ' + fmtDH(c.termineLe) + ' · ' + (c.proposes ? pl(c.proposes, 'point') + ' proposé' + (c.proposes > 1 ? 's' : '') : 'aucun point proposé')
      : et === 'envoye' ? 'envoyé le ' + fmtDJ(c.envoyeLe) + ' · ' + (c.ouverts ? pl(c.ouverts, 'point') + ' ouvert' + (c.ouverts > 1 ? 's' : '') : 'aucun point ouvert') : (c.odjEnvoyeLe ? 'ordre du jour envoyé le ' + fmtDJ(c.odjEnvoyeLe) : (c.annoncee ? 'ordre du jour pas encore parti' : 'inopinée : personne n’est prévenu'));
    return { id: c.id, court: c.court, feu: c.feu, l1: c.typeNom + ' · ' + fmtDJ(c.le) + ' · ' + c.h, l2: (c.consultantNom || 'consultant à choisir') + ' · ' + sous, ech, cls, inopinee: !c.annoncee,
      on: (et === 'preparer' || et === 'tablette') ? String(S.svPrepId) === String(c.id) : String(S.svRapId) === String(c.id),
      ouvrir: et === 'preparer' ? () => app.setState({ svOnglet: 'preparer', svPrepId: c.id, svPrepF: null })
        : et === 'tablette' ? (c.statut === 'en_cours' || c.nonFaite ? () => app.setState({ screen: 'suiviTablette', svTabCons: c.consultant }) : () => app.setState({ svOnglet: 'preparer', svPrepId: c.id, svPrepF: null }))
        : () => app.setState({ svOnglet: 'adapter', svRapId: c.id, svRapF: null }) };
  };
  sv.colonnes = [['preparer', 'À préparer', 'ordinateur'], ['tablette', 'Sur la tablette', 'en visite'], ['adapter', 'Rapport à adapter', 'ordinateur'], ['envoye', 'Envoyé', '30 derniers jours']]
    .map(([k, nom, ou]) => ({ nom, ou, n: d.cartes[k].length, cartes: d.cartes[k].map(c => carte(c, k)) }));
  sv.franchises = d.franchises.map(f => ({ court: f.court, feu: f.feu, sous: (f.responsable || 'sans consultant responsable') + (f.due ? ' · ' + f.due : ''),
    derniere: f.derniere ? fmtD(f.derniere.le) + ' · ' + f.derniere.typeNom.toLowerCase() : '—', derniereSous: f.derniere ? f.derniere.checklist.ok + ' / ' + f.derniere.checklist.total + ' conformes' : 'jamais visitée',
    rapport: f.rapport ? (f.rapport.statut === 'brouillon' ? 'à adapter' : (f.rapport.luLe ? 'lu' : 'pas lu')) : '—', rapportCls: f.rapport ? (f.rapport.statut === 'brouillon' ? 'att' : (f.rapport.luLe ? 'ok' : 'ko')) : '',
    rapportSous: f.rapport ? (f.rapport.statut === 'envoye' ? 'envoyé le ' + fmtD(f.rapport.envoyeLe) : 'visite du ' + fmtD(f.rapport.le)) : '',
    points: String(f.points.n) + (f.points.p0 ? ' · ' + f.points.p0 + ' P0' : ''), pointsCls: f.points.p0 || f.points.retard ? 'ko' : '',
    pointsSous: [f.points.attente ? pl(f.points.attente, 'photo') + ' à valider' : '', f.points.retard ? f.points.retard + ' en retard' : '', f.points.escalade ? 'escaladé' : ''].filter(Boolean).join(' · '),
    prochaine: f.prochaine ? fmtDJ(f.prochaine.le) + ' · ' + f.prochaine.h : 'rien de planifié', prochaineSous: f.prochaine ? f.prochaine.consultant + (f.prochaine.annoncee === false ? ' · inopinée' : '') : '',
    cadre: f.cadre.attendu ? f.cadre.faites + ' / ' + f.cadre.attendu : '—', cadreSous: f.cadre.planifiees ? f.cadre.planifiees + ' planifiée' + (f.cadre.planifiees > 1 ? 's' : '') : '',
    scoring: f.scoring && f.scoring.total != null ? nf(f.scoring.total, 1) + ' / ' + f.scoring.sur : '—',
    ouvrir: () => app.setState({ screen: 'ficheFranchise', ffShop: f.id, ffShopChoisi: true, ffOnglet: 'sante' }) }));
  if (onglet === 'preparer') {
    const liste = d.cartes.preparer.concat(d.cartes.tablette.filter(c => c.statut !== 'en_cours' && !c.nonFaite));
    const id = S.svPrepId && liste.some(c => String(c.id) === String(S.svPrepId)) ? S.svPrepId : (liste[0] || {}).id;
    sv.prepListe = liste.map(c => Object.assign(carte(c, c.prepEnvoyeeLe ? 'tablette' : 'preparer'), { on: String(c.id) === String(id), ouvrir: () => app.setState({ svPrepId: c.id, svPrepF: null }) }));
    if (id) { prepVals(app, sv, id); }
  }
  if (onglet === 'adapter') {
    const liste = d.cartes.adapter.concat(d.cartes.envoye);
    const id = S.svRapId && liste.some(c => String(c.id) === String(S.svRapId)) ? S.svRapId : (d.cartes.adapter[0] || d.cartes.envoye[0] || {}).id;
    sv.rapListe = liste.map(c => Object.assign(carte(c, c.envoyeLe ? 'envoye' : 'adapter'), { on: String(c.id) === String(id), ouvrir: () => app.setState({ svRapId: c.id, svRapF: null }) }));
    if (id) { rapVals(app, sv, id); }
  }
  if (onglet === 'points') { pointsVals(app, sv, d); }
  sv.apercu = S.svApercu || null; sv.fermerApercu = () => app.setState({ svApercu: null });
  common.sv = sv;
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
function prepSauver(app, id, F, envoyer, odj){
  app.setState({ svBusy: true });
  app.api('PUT', '/suivi/preparation/' + id, { blocs: F.blocs, points: F.points.filter(p => (p.libelle || '').trim()), annoncee: F.annoncee, type: F.type, note: F.note, envoyer, odj, qui: 'Cockpit' }).then(r => {
    const ok = r && r.ok !== false && !r.error && r.visite;
    app.setState({ svBusy: false, svPrepF: ok ? null : app.state.svPrepF, svPrep: ok ? { path: '/suivi/preparation?visite=' + id, chargement: false, d: r } : app.state.svPrep });
    let t = ok ? (envoyer ? 'Dossier envoyé sur la tablette de ' + (r.visite.consultantNom || 'consultant') + '.' : 'Préparation enregistrée.') : 'Refusé : ' + ((r && r.error) || 'sans réponse');
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
    ecarts: d.checklist.ecarts.map(e => e.libelle + (e.commentaire ? ' — ' + e.commentaire : '')),
    nInclus: inclus.length, nInternes: d.plans.length - inclus.length,
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
  const chemin = 'visites/?role=consultant' + (id ? '&id=' + encodeURIComponent(id) : '');
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
      voit: [['rapports', 'ses rapports de visite'], ['points', 'ses points à travailler et leurs photos'], ['prochaine', 'sa prochaine visite annoncée'], ['msp', 'ses rapports client mystère'], ['scoring', 'son scoring du trimestre']].map(([k, l]) => ({ l, on: !!V[k] })),
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
  sv.voit = [['rapports', 'ses rapports de visite'], ['points', 'ses points et leurs photos'], ['prochaine', 'sa prochaine visite annoncée'], ['msp', 'ses rapports client mystère'], ['scoring', 'son scoring du trimestre']]
    .map(([k, l]) => ({ l, on: !!F.voit[k], basculer: () => maj({ voit: Object.assign({}, F.voit, { [k]: !F.voit[k] }) }) }));
  sv.modifie = !!F.modifie; sv.busy = !!S.svBusy;
  sv.enregistrer = () => reglesSauver(app, F);
  sv.annuler = F.modifie ? () => app.setState({ svReglesF: null }) : null;
  sv.cadre = () => app.setState({ screen: 'gestionConsultant', gcOnglet: 'cadre' });
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
