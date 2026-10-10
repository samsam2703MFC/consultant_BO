/* Cockpit CEO — valeurs des écrans « Franchisés · évaluation et suivi » (09/10/2026).
 * Gestion consultant (/consultants/gestion, /visites/cadre, /visites, /consultants/taches),
 * Fiche franchisé (/franchises, /franchises/fiche), Remarques aux opérateurs (/equipe/remarques).
 * Chaque fonction reçoit l'application (état, setState, api) et remplit `common`. */
import { readOne, API_BASE } from './api.js';

const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const MOIS_C = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const JOURS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
const JC = ['dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam'];
const ST = { planifiee: ['planifiée', ''], confirmee: ['confirmée', 'conf'], en_cours: ['en cours', 'conf'], terminee: ['faite', 'fait'], annulee: ['annulée', 'm'] };
const SRC = { type: ['Visite', 'type'], visite: ['Visite', 'v'], plan: ['Plan d’action', 'plan'], panel: ['Panel', 'panel'], recurrente: ['Récurrente', 'r'], helpdesk: ['Helpdesk', 'helpdesk'], perso: ['Perso', 'perso'] };
const QUAND = { avant: 'Avant la visite', pendant: 'Pendant la visite', apres: 'Après la visite' };
const ASSIGNES = { franchise: 'franchisé', equipe: 'équipe', consultant: 'consultant', admin: 'admin' };
const PLAN_ST = { ouvert: 'ouvert', attente: 'correction à valider', valide: 'validé', reprendre: 'à reprendre', ferme: 'fermé', escalade: 'escaladé' };
const auj = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
const fmtD = d => d ? d.slice(8, 10) + '/' + d.slice(5, 7) : '';
const fmtDJ = d => { if (!d) { return ''; } const t = new Date(d + 'T12:00:00'); return JC[t.getDay()] + ' ' + fmtD(d); };
const plusJours = (d, n) => { const t = new Date(d + 'T12:00:00'); t.setDate(t.getDate() + n); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0'); };
const finMois = m => { const t = new Date(+m.slice(0, 4), +m.slice(5, 7), 0); return m + '-' + String(t.getDate()).padStart(2, '0'); };
const decaleMois = (m, n) => { const t = new Date(+m.slice(0, 4), +m.slice(5, 7) - 1 + n, 1); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0'); };
const nf = (n, dd) => n == null ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: dd || 0, maximumFractionDigits: dd || 0 });
const pl = (n, u, p) => nf(n) + ' ' + u + (n > 1 ? (p || 's') : '');
const racine = () => API_BASE.replace(/api\/cockpit\/?$/, '');

/* --- Gestion consultant ------------------------------------------------------------- */
export function gcCharge(app, force){
  const S = app.state, cons = S.gcCons || '', mois = S.gcMois || auj().slice(0, 7);
  const cle = cons + '|' + mois, b = S.gc;
  if (!force && b && b.cle === cle && (b.d || b.chargement)) { return; }
  if (app._gcEnCours === cle && !force) { return; }
  app._gcEnCours = cle;
  app.setState({ gc: { cle, chargement: true, d: b && b.cle === cle ? b.d : null } });
  readOne('/consultants/gestion?consultant=' + encodeURIComponent(cons) + '&mois=' + mois).then(d => { app._gcEnCours = null; app.setState({ gc: { cle, chargement: false, d: d || { error: 'injoignable' } } }); });
}
export function gcCadreCharge(app, force){
  const b = app.state.gcCadre;
  if (!force && b && (b.d || b.chargement)) { return; }
  if (app._gcCadreEnCours && !force) { return; }
  app._gcCadreEnCours = true;
  app.setState({ gcCadre: { chargement: true, d: b ? b.d : null } });
  readOne('/visites/cadre').then(d => { app._gcCadreEnCours = false; app.setState({ gcCadre: { chargement: false, d: d || { error: 'injoignable' } } }); });
}
function gcMessage(app, txt, ko){ app.setState({ gcMsg: { txt, ko: !!ko } }); setTimeout(() => { if (app.state.gcMsg && app.state.gcMsg.txt === txt) { app.setState({ gcMsg: null }); } }, 6000); }
function gcPlanifier(app, body, consNom){
  app.setState({ gcBusy: true });
  app.api('POST', '/visites', Object.assign({ client_id: 'ck-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6), consultant_nom: consNom, qui: 'Cockpit' }, body)).then(r => {
    const ok = r && r.ok !== false && !r.error;
    const s = (r && r.suite) || {};
    app.setState({ gcBusy: false, gcPlanif: ok ? null : app.state.gcPlanif, gcCadre: null, gcCadreForm: app.state.gcCadreForm });
    gcMessage(app, ok ? 'Visite planifiée le ' + fmtDJ(body.prevu_le) + ' à ' + body.debut_h + (s.taches ? ' · ' + pl(s.taches, 'tâche') + ' du type dans la liste du consultant' : '') + (s.invitation && s.invitation.envoye ? ' · invitation envoyée à ' + s.invitation.a : (s.invitation && s.invitation.motif ? ' · pas d’invitation : ' + s.invitation.motif : '')) : 'Planification refusée : ' + ((r && r.error) || 'sans réponse'), !ok);
    gcCharge(app, true); gcCadreCharge(app, true);
  });
}
function gcTacheStatut(app, id, statut){
  app.api('PUT', '/consultants/taches/' + id, { statut }).then(r => { if (r && r.ok !== false) { gcCharge(app, true); } });
}
function gcTacheCreer(app, f, cons){
  if (!f.titre || !f.titre.trim()) { gcMessage(app, 'Donnez un titre à la tâche.', true); return; }
  app.api('POST', '/consultants/taches', { client_id: 'ck-' + Date.now().toString(36), titre: f.titre.trim(), shop: f.shop || null, echeance: f.echeance || null, consultant: cons || '', source: 'perso', qui: 'Cockpit' })
    .then(r => { if (r && r.ok !== false) { app.setState({ gcNouvelle: null }); gcMessage(app, 'Tâche ajoutée.'); gcCharge(app, true); } });
}
function cadreFormDe(K){
  return { lignes: (K.cadre || []).map(l => Object.assign({}, l)),
    types: (K.types || []).map(t => Object.assign({}, t, { tachesTxt: (t.taches || []).map(x => x.quand + ' | ' + x.delai + ' | ' + x.libelle).join('\n') })),
    consultantProfil: Object.fromEntries((K.consultants || []).map(c => [c.id, c.profil || ''])) };
}
function tachesDeTexte(txt){
  return String(txt || '').split('\n').map(l => l.trim()).filter(Boolean).map(l => { const p = l.split('|').map(s => s.trim()); const quand = ['avant', 'pendant', 'apres'].includes(p[0]) ? p[0] : 'pendant'; return p.length >= 3 ? { quand, delai: Math.max(0, parseInt(p[1], 10) || 0), libelle: p.slice(2).join(' | ') } : { quand: 'pendant', delai: 0, libelle: l }; });
}
function gcCadreSauver(app, F, quoi){
  const corps = quoi === 'cadre' ? { cadre: F.lignes.map(l => ({ id: l.id, shop: l.shop, type: l.type, nb: l.nb, par: l.par, profil: l.profil || '', consultant: l.consultant || '' })) }
    : { types: F.types.map(t => Object.assign({ code: t.code, nom: t.nom, duree: t.duree, profil: t.profil || '', actif: t.actif !== false, dynamique: t.dynamique || null, taches: tachesDeTexte(t.tachesTxt) }, t.code === 'reguliere' ? {} : { checklist: t.checklist || [] })), consultantProfil: F.consultantProfil };
  app.setState({ gcBusy: true });
  app.api('PUT', '/visites/cadre', corps).then(r => {
    const ok = r && r.ok !== false && !r.error;
    app.setState({ gcBusy: false, gcCadre: ok ? { chargement: false, d: r } : app.state.gcCadre, gcCadreForm: ok ? null : F });
    gcMessage(app, ok ? (quoi === 'cadre' ? 'Cadre enregistré : ' + pl((r.cadre || []).length, 'ligne') + '.' : 'Types et profils enregistrés.') : 'Enregistrement refusé : ' + ((r && r.error) || 'sans réponse'), !ok);
    if (ok) { gcCharge(app, true); }
  });
}
function planifVals(app, f, d, K){
  const types = ((K && K.types) || d.types || []).filter(x => x.actif !== false);
  const t = types.find(x => x.code === (f.type || 'reguliere')) || null;
  const maj = patch => app.setState({ gcPlanif: Object.assign({}, app.state.gcPlanif, patch) });
  const consPro = (d.consultants || []).slice().sort((a, b) => (t && t.profil ? ((b.profil === t.profil) - (a.profil === t.profil)) : 0));
  const dup = t && t.profil ? consPro.find(c => c.profil === t.profil) : null;
  const consSel = f.consultant || (dup ? dup.id : (d.consultant ? d.consultant.id : (consPro[0] || {}).id || ''));
  const consNom = ((d.consultants || []).find(c => c.id === consSel) || {}).nom || '';
  const shopSel = f.shop || (d.magasins[0] || {}).id;
  const cl = t ? (t.dynamique === 'plans' ? 'checklist : les plans d’action ouverts' : t.dynamique === 'ko' ? 'checklist : les points non conformes de la dernière visite' : 'checklist ' + pl(t.points, 'point')) : '';
  return { magasins: d.magasins.map(m => ({ v: m.id, nom: m.court, on: String(m.id) === String(shopSel) })), setShop: e => maj({ shop: e.target.value }),
    types: types.map(x => ({ v: x.code, nom: x.nom, on: x.code === (f.type || 'reguliere') })), setType: e => maj({ type: e.target.value, duree: '', consultant: '' }),
    typeInfo: t ? t.duree + ' min · ' + cl + ' · ' + pl(t.nbTaches, 'tâche') + ' à faire' + (t.profilNom ? ' · profil ' + t.profilNom : '') : '',
    consultants: consPro.map(c => ({ v: c.id, nom: c.nom + (c.profilNom ? ' · ' + c.profilNom : ''), on: c.id === consSel })), setCons: e => maj({ consultant: e.target.value }),
    consInfo: t && t.profil ? 'profil demandé : ' + t.profilNom + (dup ? '' : ' — aucun consultant de ce profil : réglez les profils dans Cadre de visite') : 'profil : celui du cadre',
    motifs: [['reguliere', 'régulière'], ['asap', 'ASAP'], ['due', 'due'], ['revisite', 'revisite']].map(([v, nom]) => ({ v, nom, on: (f.motif || 'reguliere') === v })), setMotif: e => maj({ motif: e.target.value }),
    date: f.date, setDate: e => maj({ date: e.target.value }), heure: f.heure, setHeure: e => maj({ heure: e.target.value }),
    duree: f.duree || (t ? t.duree : 90), setDuree: e => maj({ duree: e.target.value }),
    apercu: 'Ce que la liste déroulante charge : ' + (t ? 'la checklist « ' + t.nom + ' » et ' + pl(t.nbTaches, 'tâche') + ' dans la liste de ' + (consNom || 'le consultant') + ', avec leur échéance (avant, pendant, après)' : '—') + (d.agenda && d.agenda.invitations ? (d.agenda.smtp ? ' · l’invitation .ics part au consultant' : ' · invitation : SMTP non configuré') : '') + '.',
    busy: !!app.state.gcBusy, fermer: () => app.setState({ gcPlanif: null }),
    envoyer: () => gcPlanifier(app, { shop: shopSel, consultant: consSel, type: f.type || 'reguliere', prevu_le: f.date, debut_h: f.heure || '09:00', duree_min: Number(f.duree || (t ? t.duree : 90)), motif: f.motif || 'reguliere' }, consNom) };
}
export function valsGC(app, common){
  const S = app.state; const auj0 = auj();
  const onglet = S.gcOnglet || 'planning';
  const mois = S.gcMois || auj0.slice(0, 7);
  gcCharge(app, false);
  if (onglet === 'taches' || onglet === 'cadre' || S.gcPlanif) { gcCadreCharge(app, false); }
  const b = S.gc || {}, d = b.d && !b.d.error ? b.d : null;
  const KB = S.gcCadre || {}, K = KB.d && !KB.d.error ? KB.d : null;
  const g = { onglet, moisLib: MOIS[+mois.slice(5, 7) - 1] + ' ' + mois.slice(0, 4),
    onglets: [['planning', 'Mon planning'], ['taches', 'Tâches et contrôles'], ['reseau', 'Réseau'], ['cadre', 'Cadre de visite']].map(([v, nom]) => ({ v, nom, on: onglet === v,
      badge: v === 'taches' && d ? d.taches.filter(t => t.statut === 'a_faire' || t.statut === 'en_cours').length : 0, choisir: () => app.setState({ gcOnglet: v }) })),
    consultants: [{ v: 'tous', nom: 'Tous les consultants', on: !d || !d.consultant }].concat((d ? d.consultants : []).map(c => ({ v: c.id, nom: c.nom, on: !!(d && d.consultant && d.consultant.id === c.id) }))),
    setCons: e => app.setState({ gcCons: e.target.value, gcSel: null }),
    moisPrec: () => app.setState({ gcMois: decaleMois(mois, -1) }), moisSuiv: () => app.setState({ gcMois: decaleMois(mois, 1) }),
    ouvrirPlanif: () => app.setState({ gcPlanif: S.gcPlanif ? null : { shop: '', type: 'reguliere', consultant: '', date: plusJours(auj0, 1), heure: '09:00', duree: '', motif: 'reguliere' } }),
    rafraichir: () => { gcCharge(app, true); gcCadreCharge(app, true); },
    chargement: !!b.chargement && !d, indispo: !b.chargement && b.d && !d ? 'Lecture impossible — API injoignable.' : '', msg: S.gcMsg || null, planif: null, kpis: [] };
  common.gc = g;
  if (!d) { return; }
  const cons = d.consultant ? d.consultant.id : '';
  const mags = d.magasins || []; const magNom = {}; mags.forEach(m => { magNom[String(m.id)] = m.court; });
  const mesVisites = d.visites.filter(v => v.mienne && v.statut !== 'annulee');
  const duMois = v => v.prevu_le >= mois + '-01' && v.prevu_le <= finMois(mois);
  const tachesOuvertes = d.taches.filter(t => t.statut === 'a_faire' || t.statut === 'en_cours');
  const retards = tachesOuvertes.filter(t => t.retard > 0).length;
  const plansOuverts = d.plans.filter(p => /^(ouvert|reprendre|escalade|attente)$/.test(p.statut));
  const p0 = plansOuverts.filter(p => p.priorite === 'P0').length;
  const cadreMoi = d.cadre.filter(l => !cons || l.consultant === cons || !l.consultant);
  const aPlanifier = cadreMoi.filter(l => l.aPlanifier > 0);
  const faites = mesVisites.filter(v => duMois(v) && v.statut === 'terminee').length, planifiees = mesVisites.filter(v => duMois(v) && v.statut !== 'terminee').length;
  g.kpis = [['Visites du mois', faites + ' / ' + (faites + planifiees), 'faites / planifiées' + (aPlanifier.length ? ' · ' + pl(aPlanifier.reduce((a, l) => a + l.aPlanifier, 0), 'visite') + ' à planifier d’après le cadre' : ' · cadre tenu')],
    ['Tâches à faire', String(tachesOuvertes.length), (retards ? retards + ' en retard · ' : '') + tachesOuvertes.filter(t => t.echeance && t.echeance >= auj0 && t.echeance <= plusJours(auj0, 6)).length + ' cette semaine', retards ? 'ko' : (tachesOuvertes.length ? 'att' : 'ok')],
    ['Plans d’action ouverts', String(plansOuverts.length), (p0 ? p0 + ' P0 · ' : '') + plansOuverts.filter(p => p.statut === 'attente').length + ' correction(s) à valider', p0 ? 'ko' : ''],
    ['Agenda Google', d.agenda.invitations ? (d.agenda.smtp ? 'invitations' : 'ICS seul') : 'ICS seul', d.agenda.ics ? 'flux par consultant prêt' + (d.agenda.invitations && d.agenda.smtp ? ' · invitation .ics à chaque visite' : '') : 'choisissez un consultant']];
  if (S.gcPlanif) { g.planif = planifVals(app, S.gcPlanif, d, K); }
  if (onglet === 'planning') {
    const premier = mois + '-01'; const dow = (new Date(premier + 'T12:00:00').getDay() + 6) % 7;
    const debut = plusJours(premier, -dow); const nbJours = +finMois(mois).slice(8); const nCases = Math.ceil((dow + nbJours) / 7) * 7;
    const parJour = {}; const push = (dte, chip) => { (parJour[dte] = parJour[dte] || []).push(chip); };
    d.visites.forEach(v => { if (v.statut === 'annulee') { return; } const st = ST[v.statut] || ['', '']; push(v.prevu_le, { cls: v.mienne ? st[1] : 'm', txt: (v.mienne ? v.debut_h + ' ' : '') + v.magasin + ' · ' + v.typeNom + (v.mienne ? '' : ' · ' + v.consultantNom), titre: v.magasin + ' — ' + v.typeNom + ' · ' + v.consultantNom + ' · ' + st[0] }); });
    tachesOuvertes.forEach(t => { if (t.echeance) { push(t.echeance, { cls: 't', txt: t.titre, titre: (SRC[t.source] || SRC.perso)[0] + (t.magasin ? ' · ' + t.magasin : '') }); } });
    plansOuverts.forEach(p => { if (p.echeance && (p.priorite === 'P0' || p.priorite === 'P1')) { push(p.echeance, { cls: 'ko', txt: p.priorite + ' ' + p.magasin + ' · ' + p.titre, titre: 'plan d’action ' + PLAN_ST[p.statut] }); } });
    g.jours = JOURS; g.cases = [];
    for (let i = 0; i < nCases; i++) { const dte = plusJours(debut, i); const hors = dte.slice(0, 7) !== mois; const n = +dte.slice(8, 10); g.cases.push({ num: hors ? n + ' ' + MOIS_C[+dte.slice(5, 7) - 1] : (dte === auj0 ? 'aujourd’hui ' + n : String(n)), hors, auj: dte === auj0, chips: (parJour[dte] || []).slice(0, 6) }); }
    g.grilleSous = (d.consultant ? d.consultant.nom : 'tous les consultants') + ' · tous magasins · ' + pl(faites + planifiees, 'visite') + ', ' + pl(tachesOuvertes.length, 'tâche') + ' ouverte' + (tachesOuvertes.length > 1 ? 's' : '');
    g.grilleMini = 'les visites des autres consultants en gris · relu ' + (d.lu || '');
    const sDu = mois === auj0.slice(0, 7) ? auj0 : premier; const sAu = plusJours(sDu, 6);
    const items = [];
    mesVisites.filter(v => v.prevu_le >= sDu && v.prevu_le <= sAu).forEach(v => { const nPl = plansOuverts.filter(p => p.shop === v.shop).length; items.push({ k: v.prevu_le + v.debut_h, h: fmtDJ(v.prevu_le), sous: v.debut_h + ' · ' + v.duree_min + ' min', titre: v.magasin + ' — ' + v.typeNom, mu: 'checklist ' + (v.points && v.points.total ? v.points.faits + ' / ' + v.points.total + (v.points.ko ? ' · ' + pl(v.points.ko, 'non conforme') : '') : 'à faire') + (nPl ? ' · ' + pl(nPl, 'plan') + ' ouvert' + (nPl > 1 ? 's' : '') : '') + (!cons ? ' · ' + v.consultantNom : ''), statut: (ST[v.statut] || ['', ''])[0], statutCls: (ST[v.statut] || ['', ''])[1], google: v.google, prog: v.points && v.points.total ? Math.round(100 * v.points.faits / v.points.total) : null, progCls: v.points && v.points.ko ? 'att' : '' }); });
    tachesOuvertes.filter(t => t.echeance && t.echeance >= sDu && t.echeance <= sAu).forEach(t => items.push({ k: t.echeance + '00:00', h: fmtDJ(t.echeance), sous: 'tâche', titre: t.titre, mu: (SRC[t.source] || SRC.perso)[0] + (t.magasin ? ' · ' + t.magasin : '') + (t.quand ? ' · ' + QUAND[t.quand].toLowerCase() : ''), statut: t.retard ? 'en retard' : (t.statut === 'en_cours' ? 'en cours' : 'à faire'), statutCls: t.retard ? 'p0' : 'att' }));
    plansOuverts.filter(p => p.echeance && p.echeance >= sDu && p.echeance <= sAu).forEach(p => items.push({ k: p.echeance + '00:01', h: fmtDJ(p.echeance), sous: 'échéance', titre: p.priorite + ' — ' + p.titre + ', ' + p.magasin, mu: 'plan d’action ' + (PLAN_ST[p.statut] || p.statut) + ' · assigné ' + (ASSIGNES[p.assigne] || ''), statut: p.priorite, statutCls: p.priorite === 'P0' ? 'p0' : 'att' }));
    items.sort((a, c) => a.k < c.k ? -1 : 1); g.semaine = items;
    g.semaineTitre = (sDu === auj0 ? 'Aujourd’hui et les six jours suivants' : 'Du ' + fmtD(sDu) + ' au ' + fmtD(sAu)); g.semaineMini = pl(items.filter(i => i.google).length, 'visite') + ' · ' + pl(items.filter(i => !i.google).length, 'échéance');
    g.agenda = d.agenda;
    g.aPlanifier = cadreMoi.map(l => ({ magasin: l.magasin, sous: l.nb + ' / ' + (l.par === 'mois' ? 'mois' : 'trim.'), titre: l.typeNom + (l.aPlanifier > 0 ? ' — ' + pl(l.aPlanifier, 'visite') + ' à planifier' : ' — à jour'),
      mu: 'faites ' + l.faites + ' · planifiées ' + l.planifiees + (l.prochaine ? ' · prochaine ' + fmtD(l.prochaine) : '') + (l.consultantNom ? ' · ' + l.consultantNom : '') + (l.profilNom ? ' · profil ' + l.profilNom : ''),
      cls: l.aPlanifier > 0 ? 'att' : 'fait', statut: l.aPlanifier > 0 ? 'à planifier' : 'à jour',
      planifier: l.aPlanifier > 0 ? () => app.setState({ gcPlanif: { shop: l.shop, type: l.type, consultant: l.consultant || '', date: plusJours(auj0, 1), heure: '09:00', duree: '', motif: 'reguliere' } }) : null }))
      .sort((a, c) => (c.planifier ? 1 : 0) - (a.planifier ? 1 : 0));
    return;
  }
  if (onglet === 'taches') {
    const filtre = S.gcFiltre || 'tous';
    const liste = d.taches.map(t => Object.assign({ kind: 'tache' }, t));
    plansOuverts.filter(p => p.assigne === 'consultant' || p.statut === 'attente').forEach(p => liste.push({ kind: 'plan', id: 'p' + p.id, titre: p.priorite + ' — ' + p.titre + (p.statut === 'attente' ? ' : correction à valider' : ''), source: 'plan', magasin: p.magasin, shop: p.shop, echeance: p.echeance, statut: p.statut === 'attente' ? 'en_cours' : 'a_faire', retard: p.retard || 0 }));
    if (d.helpdesk && d.helpdesk.lu) { d.helpdesk.cas.forEach(c => liste.push({ kind: 'hd', id: 'h' + c.id, titre: c.titre, source: 'helpdesk', magasin: magNom[c.shop] || '', echeance: c.echeance, statut: 'a_faire', retard: c.echeance && c.echeance < auj0 ? 1 : 0 })); }
    const srcDe = t => t.source === 'visite' ? 'type' : t.source;
    const filtrees = liste.filter(t => filtre === 'tous' || srcDe(t) === filtre);
    const tri = (a, c) => (a.echeance || '9999') < (c.echeance || '9999') ? -1 : 1;
    const tacheVals = t => ({ titre: t.titre, src: (SRC[t.source] || SRC.perso)[0], srcCls: (SRC[t.source] || SRC.perso)[1], magasin: t.magasin || '', late: t.retard > 0,
      ech: t.echeance ? fmtDJ(t.echeance) + (t.retard ? ' · en retard de ' + t.retard + ' j' : '') : '', on: !!(t.visite_id && String(t.visite_id) === String(S.gcSel)),
      extra: t.quand ? QUAND[t.quand] : (t.fait_le ? 'fait le ' + fmtD(t.fait_le.slice(0, 10)) : ''),
      fait: t.kind === 'tache' && t.statut !== 'fait' ? () => gcTacheStatut(app, t.id, 'fait') : null,
      encours: t.kind === 'tache' && t.statut === 'a_faire' ? () => gcTacheStatut(app, t.id, 'en_cours') : null,
      reprendre: t.kind === 'tache' && t.statut === 'fait' ? () => gcTacheStatut(app, t.id, 'a_faire') : null,
      voir: t.visite_id ? () => app.setState({ gcSel: t.visite_id }) : null });
    const colonnes = [['a_faire', 'À faire'], ['en_cours', 'En cours'], ['fait', 'Fait ce mois']].map(([st, nom]) => { const ts = filtrees.filter(t => t.statut === st && (st !== 'fait' || (t.fait_le || '') >= mois + '-01')).sort(tri); return { nom, sous: ts.length + (st === 'a_faire' && ts.some(t => t.retard > 0) ? ' · ' + ts.filter(t => t.retard > 0).length + ' en retard' : ''), taches: ts.map(tacheVals) }; });
    const nv = S.gcNouvelle || { titre: '', shop: '', echeance: '' };
    const majNv = patch => { app.state.gcNouvelle = Object.assign({}, nv, patch); };
    const vSel = d.visites.find(v => String(v.id) === String(S.gcSel)) || mesVisites.filter(v => v.prevu_le >= auj0 && v.statut !== 'terminee').sort((a, c) => a.prevu_le < c.prevu_le ? -1 : 1)[0] || mesVisites.slice().sort((a, c) => a.prevu_le < c.prevu_le ? 1 : -1)[0] || null;
    let checklist = null;
    if (vSel) {
      const T = K ? (K.types || []).find(t => t.code === vSel.type) : null;
      const tv = d.taches.filter(t => String(t.visite_id) === String(vSel.id)).sort((a, c) => (a.echeance || '') < (c.echeance || '') ? -1 : 1);
      const modules = T && T.checklist && T.checklist.length ? T.checklist : [];
      checklist = { titre: vSel.magasin + ' · ' + fmtDJ(vSel.prevu_le) + ' · ' + vSel.debut_h, sous: vSel.consultantNom + ' · ' + (ST[vSel.statut] || ['', ''])[0], type: vSel.typeNom,
        typeInfo: T ? T.duree + ' min' + (T.profilNom ? ' · profil ' + T.profilNom : '') + ' · ' + (T.dynamique === 'plans' ? 'les plans d’action ouverts' : T.dynamique === 'ko' ? 'les points non conformes de la dernière visite' : pl(T.points, 'point') + ' · ' + pl((T.checklist || []).length, 'module')) : (KB.chargement ? 'lecture du type…' : ''),
        taches: tv.map(t => ({ etat: t.statut === 'fait' ? 'on' : (t.retard ? 'ko' : ''), txt: t.titre, sous: QUAND[t.quand] + (t.echeance ? ' · ' + fmtDJ(t.echeance) : '') + (t.statut === 'fait' ? ' · fait' : (t.retard ? ' · en retard de ' + t.retard + ' j' : '')) })), tachesFaites: tv.filter(t => t.statut === 'fait').length,
        pointsSous: vSel.points && vSel.points.total ? vSel.points.faits + ' / ' + vSel.points.total + ' points faits' + (vSel.points.ko ? ' · ' + vSel.points.ko + ' non conforme(s)' : '') : (T ? pl(T.points, 'point') + ' à contrôler' : ''),
        modules: modules.map(m => ({ nom: m.nom, sous: pl(m.points.length, 'point') + (m.points.some(p => p.photo) ? ' · ' + m.points.filter(p => p.photo).length + ' photo(s)' : ''), points: m.points.map(p => ({ etat: '', txt: p.libelle, sous: (p.photo ? 'photo' : '') + (p.pct ? (p.photo ? ' · ' : '') + '% de conformité' : '') })) })),
        app: racine() + 'visites/?role=consultant&id=' + encodeURIComponent(vSel.consultant || '') + '#fiche/' + vSel.id, google: vSel.google };
    }
    g.taches = { filtres: [['tous', 'Toutes les sources'], ['type', 'Visites'], ['plan', 'Plans d’action'], ['panel', 'Panel'], ['helpdesk', 'Helpdesk'], ['perso', 'Perso']].map(([v, nom]) => ({ nom, on: filtre === v, choisir: () => app.setState({ gcFiltre: v }) })),
      colonnes, checklist,
      nouvelle: { titre: nv.titre, setTitre: e => majNv({ titre: e.target.value }), magasins: [{ v: '', nom: 'Réseau', on: !nv.shop }].concat(mags.map(m => ({ v: m.id, nom: m.court, on: String(m.id) === String(nv.shop) }))), setShop: e => { majNv({ shop: e.target.value }); },
        echeance: nv.echeance, setEcheance: e => majNv({ echeance: e.target.value }), creer: () => gcTacheCreer(app, app.state.gcNouvelle || nv, cons) } };
    return;
  }
  if (onglet === 'reseau') {
    const cadreParShop = {}; d.cadre.forEach(l => { cadreParShop[l.shop] = (cadreParShop[l.shop] || 0) + (l.par === 'mois' ? l.nb : 0); });
    const totM = d.visites.filter(v => duMois(v) && v.statut !== 'annulee');
    g.reseau = { cols: mags.map(m => ({ nom: m.court, sous: cadreParShop[m.id] ? 'cadre ' + cadreParShop[m.id] + ' / mois' : 'cadre à régler' })),
      rows: d.reseau.map(r => ({ nom: r.nom, sous: r.profilNom || 'profil à régler', cells: mags.map(m => { const c = r.magasins[String(m.id)]; return c && (c.derniere || c.prochaine || c.responsable) ? { derniere: c.derniere ? 'dernière ' + fmtD(c.derniere) : 'jamais', prochaine: c.prochaine ? 'prochaine ' + fmtD(c.prochaine) : 'rien de planifié', resp: c.responsable } : null; }),
        visites: pl(r.faites, 'faite') + ' · ' + pl(r.planifiees, 'planifiée'), taches: pl(r.tachesOuvertes, 'tâche') + ' ouverte' + (r.tachesOuvertes > 1 ? 's' : '') + (r.tachesRetard ? ' · ' + r.tachesRetard + ' en retard' : ''), agenda: r.email ? 'invitations par courriel' : 'sans adresse courriel' })),
      pied: mags.map(m => { const bq = d.boutiques.find(x => String(x.id) === String(m.id)) || {}; return { feu: bq.feu, b: bq.feu === 'rouge' ? 'alerte' : bq.feu === 'orange' ? 'à surveiller' : 'à jour', s: (bq.motifs || []).slice(0, 2).join(' · ') || (bq.prochaineVisite ? 'prochaine ' + fmtD(bq.prochaineVisite.le) : '') }; }),
      total: { b: pl(totM.filter(v => v.statut === 'terminee').length, 'faite') + ' · ' + pl(totM.filter(v => v.statut !== 'terminee').length, 'planifiée'), s: pl(d.reseau.reduce((a, r) => a + r.tachesOuvertes, 0), 'tâche') + ' ouverte(s) · ' + d.reseau.reduce((a, r) => a + r.tachesRetard, 0) + ' en retard' },
      couverture: d.cadre.map(l => ({ nom: l.magasin + ' · ' + l.typeNom, pct: Math.min(100, Math.round(100 * (l.faites + l.planifiees) / Math.max(1, l.attendu))), coul: l.aPlanifier > 0 ? '#D97706' : '#2d7a3e', txt: l.faites + ' faite(s) + ' + l.planifiees + ' planifiée(s) / ' + l.attendu + ' ' + (l.par === 'mois' ? 'ce mois' : 'ce trimestre') })),
      regles: [['Affectation', 'Le cadre nomme le consultant de chaque ligne : ' + (d.cadre.filter(l => l.consultantNom).map(l => l.consultantNom + ' → ' + l.magasin + ' (' + l.typeNom + ')').join(' · ') || 'aucune ligne nommée, réglez le cadre') + '.'],
        ['Cadre de visite', 'Par magasin, le nombre de visites par mois ou par trimestre et leur type : ' + (mags.map(m => m.court + ' ' + (cadreParShop[m.id] || 0) + ' / mois').join(' · ')) + '. Le suivi et la revisite sont hors cadre.'],
        ['Profil', 'Chaque type demande un profil (' + (d.profils || []).map(p => p.nom).join(', ') + ') ; le planning propose d’abord les consultants du profil.'],
        ['Liste de contrôle', 'Chaque type porte sa checklist et ses tâches à faire (avant, pendant, après) : ' + (d.types || []).filter(t => t.points).map(t => t.nom + ' ' + t.points).join(' · ') + '.'],
        ['Agenda Google', 'Un flux ICS par consultant, l’invitation .ics à chaque visite planifiée (' + (d.agenda.invitations ? (d.agenda.smtp ? 'active' : 'SMTP à configurer') : 'désactivée') + '), le lien « Ajouter à Google Agenda » sur chaque visite.']] };
    return;
  }
  // cadre
  if (!K) { g.cadre = { chargement: true }; return; }
  const F = S.gcCadreForm || cadreFormDe(K);
  const garde = () => { app.state.gcCadreForm = F; };
  const majF = () => { app.setState({ gcCadreForm: F }); };
  const typesK = K.types || []; const profils = K.profils || [];
  const profilNom = code => (profils.find(p => p.code === code) || {}).nom || (code || '');
  const attParId = {}; (K.attendu || []).forEach(a => { attParId[a.id] = a; });
  const typeSelCode = S.gcTypeSel || (F.types[0] || {}).code;
  const t = F.types.find(x => x.code === typeSelCode) || null;
  const nbMois = F.lignes.reduce((a, l) => a + (l.par === 'mois' ? +l.nb || 0 : 0), 0);
  const nbTri = F.lignes.reduce((a, l) => a + (l.par === 'trimestre' ? +l.nb || 0 : 0), 0);
  const attMois = (K.attendu || []).filter(a => a.par === 'mois');
  g.cadre = { chargement: false, busy: !!S.gcBusy,
    kpis: [['Types de visite', String(typesK.length), 'dans la liste déroulante · ' + pl(typesK.reduce((a, x) => a + (x.nbTaches || 0), 0), 'tâche') + ' à faire'],
      ['Visites au cadre', nbMois + ' / mois', nbTri ? '+ ' + nbTri + ' par trimestre' : 'par magasin, par type'],
      ['Profils', String(profils.length), profils.map(p => p.nom).join(' · ')],
      ['Réalisé ce mois', attMois.reduce((a, l) => a + l.faites, 0) + ' / ' + attMois.reduce((a, l) => a + l.attendu, 0), 'faites / attendues au cadre, lignes mensuelles']],
    lignes: F.lignes.map((l, i) => { const tt = typesK.find(x => x.code === l.type) || {}; const a = attParId[l.id]; return {
      magasins: mags.map(m => ({ v: m.id, nom: m.court, on: String(m.id) === String(l.shop) })), setShop: e => { l.shop = e.target.value; majF(); },
      types: typesK.map(x => ({ v: x.code, nom: x.nom, on: x.code === l.type })), setType: e => { l.type = e.target.value; majF(); },
      nb: l.nb, setNb: e => { l.nb = Math.max(1, parseInt(e.target.value, 10) || 1); garde(); },
      pars: [['mois', 'par mois'], ['trimestre', 'par trimestre']].map(([v, nom]) => ({ v, nom, on: (l.par || 'mois') === v })), setPar: e => { l.par = e.target.value; majF(); },
      profil: profilNom(l.profil || tt.profil) || 'celui du cadre', checklist: tt.dynamique === 'plans' ? 'les plans ouverts' : tt.dynamique === 'ko' ? 'les non conformes' : (tt.points ? tt.points + ' pts' : '—'),
      consultants: [{ v: '', nom: '— à nommer —', on: !l.consultant }].concat((K.consultants || []).map(c => ({ v: c.id, nom: c.nom + (c.profilNom ? ' · ' + c.profilNom : ''), on: c.id === l.consultant }))), setCons: e => { l.consultant = e.target.value; majF(); },
      realise: a ? a.faites + ' / ' + a.attendu + (a.planifiees ? ' · ' + a.planifiees + ' planifiée(s)' : '') : '', realiseCls: a ? (a.aPlanifier > 0 ? 'att' : 'ok') : '',
      retirer: () => { F.lignes.splice(i, 1); majF(); } }; }),
    ajouter: () => { F.lignes.push({ id: '', shop: (mags[0] || {}).id, type: 'reguliere', nb: 1, par: 'mois', profil: '', consultant: '' }); majF(); },
    sauver: () => gcCadreSauver(app, F, 'cadre'), sauverTypes: () => gcCadreSauver(app, F, 'types'),
    plansMini: pl(F.lignes.length, 'ligne') + ' · le suivi de plan d’action et la revisite naissent d’un P0 ou d’un point non conforme',
    types: F.types.map(x => ({ nom: x.nom, sous: x.duree + ' min · profil : ' + (profilNom(x.profil) || 'celui du cadre') + ' · ' + (x.dynamique === 'plans' ? 'les plans d’action ouverts' : x.dynamique === 'ko' ? 'les points non conformes' : pl(x.points, 'point')), n: pl(tachesDeTexte(x.tachesTxt).length, 'tâche'), on: x.code === typeSelCode, choisir: () => app.setState({ gcTypeSel: x.code }) })),
    typeSel: t ? { code: t.code, nom: t.nom, setNom: e => { t.nom = e.target.value; garde(); }, duree: t.duree, setDuree: e => { t.duree = parseInt(e.target.value, 10) || t.duree; garde(); },
      profils: [{ v: '', nom: 'celui du cadre', on: !t.profil }].concat(profils.map(p => ({ v: p.code, nom: p.nom, on: p.code === t.profil }))), setProfil: e => { t.profil = e.target.value; majF(); },
      tachesTxt: t.tachesTxt, setTaches: e => { t.tachesTxt = e.target.value; garde(); },
      checklistSous: t.code === 'reguliere' ? 'la liste standard, réglée dans l’app Visites › Réglages' : (t.dynamique ? 'dynamique' : pl((t.checklist || []).length, 'module') + ' · ' + pl(t.points, 'point')),
      modules: t.checklist || [], dynamique: t.dynamique === 'plans' ? 'Checklist dynamique : les plans d’action ouverts de la boutique, un point par plan.' : t.dynamique === 'ko' ? 'Checklist dynamique : les points non conformes de la dernière visite terminée.' : '',
      note: 'Le nom, la durée, le profil et les tâches se modifient ici ; la liste des points est celle livrée (la régulière suit l’app Visites).' } : null,
    consultants: (K.consultants || []).map(c => ({ nom: c.nom, profils: [{ v: '', nom: '—', on: !F.consultantProfil[c.id] }].concat(profils.map(p => ({ v: p.code, nom: p.nom, on: p.code === F.consultantProfil[c.id] }))), setProfil: e => { F.consultantProfil[c.id] = e.target.value; majF(); } })) };
}

/* --- Fiche franchisé --------------------------------------------------------------- */
export function ffTableCharge(app, force){
  const b = app.state.ffTable;
  if (!force && b && (b.d || b.chargement)) { return; }
  if (app._ffTableEnCours && !force) { return; }
  app._ffTableEnCours = true;
  app.setState({ ffTable: { chargement: true, d: b ? b.d : null } });
  readOne('/franchises?_cache=600' + (force ? '&rafraichir=1' : '')).then(d => { app._ffTableEnCours = false; app.setState({ ffTable: { chargement: false, d: d || { error: 'injoignable' } } }); });
}
export function ffCharge(app, shop, force){
  const b = app.state.ff;
  if (!shop) { return; }
  if (!force && b && b.shop === shop && (b.d || b.chargement)) { return; }
  if (app._ffEnCours === shop && !force) { return; }
  app._ffEnCours = shop;
  app.setState({ ff: { shop, chargement: true, d: b && b.shop === shop ? b.d : null } });
  readOne('/franchises/fiche?shop=' + encodeURIComponent(shop) + '&_cache=300' + (force ? '&rafraichir=1' : '')).then(d => { app._ffEnCours = null; app.setState({ ff: { shop, chargement: false, d: d || { error: 'injoignable' } } }); });
}
export function ffAgCharge(app, force){
  const m0 = auj().slice(0, 7), m1 = decaleMois(m0, 1), cle = m0, b = app.state.ffAg;
  if (!force && b && b.cle === cle && (b.d || b.chargement)) { return; }
  if (app._ffAgEnCours === cle && !force) { return; }
  app._ffAgEnCours = cle;
  app.setState({ ffAg: { cle, chargement: true, d: b && b.cle === cle ? b.d : null } });
  const lire = m => readOne('/consultants/gestion?consultant=tous&mois=' + m + (force ? '&rafraichir=1' : '')).then(d => d && !d.error ? d : null);
  Promise.all([lire(m0), +auj().slice(8) >= 15 ? lire(m1) : Promise.resolve(null)]).then(([a, c]) => { app._ffAgEnCours = null; app.setState({ ffAg: { cle, chargement: false, d: a ? { visites: a.visites.concat(c ? c.visites : []), lu: a.lu } : { error: 'injoignable' } } }); });
}
export function ffCkCharge(app, cons, shop, force){
  const cle = (cons || '') + '|' + (shop || ''), b = app.state.ffCk;
  if (!force && b && b.cle === cle && (b.d || b.chargement)) { return; }
  if (app._ffCkEnCours === cle && !force) { return; }
  app._ffCkEnCours = cle;
  app.setState({ ffCk: { cle, chargement: true, d: b && b.cle === cle ? b.d : null } });
  readOne('/consultants/checklists?consultant=' + encodeURIComponent(cons || '') + '&shop=' + encodeURIComponent(shop || '') + '&_t=' + Date.now()).then(d => { app._ffCkEnCours = null; app.setState({ ffCk: { cle, chargement: false, d: d || { error: 'injoignable' } } }); });
}
function ffCkSauver(app, shop, ck, sel, F, panel){
  const taches = sel.taches.filter(t => F.taches[t.id] && F.taches[t.id].etat).map(t => ({ task_id: t.id, nom: t.nom, etat: F.taches[t.id].etat, commentaire: F.taches[t.id].commentaire || '' }));
  if (!taches.length) { app.setState({ ffCkMsg: { txt: 'Rien à enregistrer : choisissez Fait, Pas fait ou N/A pour au moins une tâche.', ko: true } }); return; }
  const cons = (ck.consultants || []).find(c => c.id === ck.choisi) || {};
  app.setState({ ffCkBusy: true, ffCkMsg: null });
  app.api('POST', '/consultants/checklists', { shop, consultant: ck.choisi, consultant_nom: cons.nom || '', moi: !!ck.moi, checklist_id: sel.id, checklist_nom: sel.nom, panel: !!panel, taches, qui: 'Cockpit' }).then(r => {
    const ok = !!(r && r.ok !== false && !r.error);
    app.setState({ ffCkBusy: false, ffCkMsg: { ko: !ok, txt: ok ? 'Checklist enregistrée · ' + pl(r.enregistrees, 'tâche') + (r.panelEnvoyees ? ' · ' + r.panelEnvoyees + ' envoyée' + (r.panelEnvoyees > 1 ? 's' : '') + ' au panel' : '') + (r.panelRefusees ? ' · ' + r.panelRefusees + ' refusée' + (r.panelRefusees > 1 ? 's' : '') + ' par le panel' : '') : 'Enregistrement refusé : ' + ((r && r.error) || 'sans réponse') } });
    if (ok) { ffCkCharge(app, ck.choisi, shop, true); }
  });
}
export function ffMeteoCharge(app, shop, force){
  const b = app.state.ffMeteo;
  if (!shop) { return; }
  if (!force && b && b.shop === shop && (b.d || b.chargement)) { return; }
  if (app._ffMeteoEnCours === shop && !force) { return; }
  app._ffMeteoEnCours = shop;
  app.setState({ ffMeteo: { shop, chargement: true, d: b && b.shop === shop ? b.d : null } });
  readOne('/franchises/meteo?shop=' + encodeURIComponent(shop) + '&_t=' + Date.now()).then(d => { app._ffMeteoEnCours = null; app.setState({ ffMeteo: { shop, chargement: false, d: d || { error: 'injoignable' } } }); });
}
const METEO_I = { 1: '⛈️', 2: '🌧️', 3: '☁️', 4: '🌤️', 5: '☀️' };
const METEO_L = { 1: 'orage', 2: 'pluie', 3: 'nuageux', 4: 'éclaircies', 5: 'soleil' };
const METEO_K = [['moral', 'Moral'], ['envie', 'Envie, motivation'], ['equipe', 'Climat de l’équipe'], ['relation', 'Relation avec le réseau']];
const METEO_T = [['envies', 'Ses envies, ses projets', 'ouvrir le dimanche, lancer les plateaux traiteur…'], ['demandes', 'Ses demandes', 'une par ligne : chacune devient une tâche'], ['inquietudes', 'Ses inquiétudes, ce qui bloque', 'personnel, travaux, trésorerie…'], ['note', 'Ce que j’en retiens', 'pour le consultant seulement']];
const mCls = v => v == null ? 'mu' : (v >= 4 ? 'ok' : v === 3 ? 'att' : 'ko');
function ffMeteoSauver(app, shop){
  const F = app.state.ffMeteoForm && app.state.ffMeteoForm.shop === shop ? app.state.ffMeteoForm : { shop };
  const dem = String(F.demandes || '').split('\n').map(x => x.trim()).filter(Boolean);
  const rien = !METEO_K.some(([k]) => F[k]) && !dem.length && !['envies', 'inquietudes', 'note'].some(k => String(F[k] || '').trim());
  if (rien) { app.setState({ ffMeteoMsg: { txt: 'Rien à enregistrer : choisissez une météo ou écrivez une ligne.', ko: true } }); return; }
  app.setState({ ffMeteoBusy: true, ffMeteoMsg: null });
  const body = { client_id: 'mt-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6), shop, demandes: dem, taches: F.taches !== false, qui: 'Cockpit' };
  METEO_K.forEach(([k]) => { body[k] = F[k] || null; });
  ['envies', 'inquietudes', 'note'].forEach(k => { body[k] = String(F[k] || ''); });
  app.api('POST', '/franchises/meteo', body).then(r => {
    const ok = !!(r && r.ok !== false && !r.error);
    app.setState({ ffMeteoBusy: false, ffMeteoForm: ok ? { shop, taches: F.taches !== false } : F,
      ffMeteoMsg: { txt: ok ? 'Météo enregistrée' + (r.taches ? ' · ' + pl(r.taches, 'tâche') + ' dans votre liste' : '') : 'Enregistrement refusé : ' + ((r && r.error) || 'sans réponse'), ko: !ok } });
    if (ok) { ffMeteoCharge(app, shop, true); }
  });
}
export function valsFF(app, common){
  const S = app.state; const auj0 = auj();
  ffTableCharge(app, false);
  const TB = S.ffTable || {}, T = TB.d && !TB.d.error ? TB.d : null;
  const mags = T ? T.magasins : app.open().map(s => ({ id: String(s.id), court: s.nom.split(' - ').pop(), nom: s.nom }));
  const shop = String(S.ffShop || (mags[0] || {}).id || '');
  const onglet = ({ fiche: 'sante', journal: 'sante', tableau: 'reseau', checklists: 'checklist' })[S.ffOnglet] || S.ffOnglet || 'agenda';
  const choisi = !!S.ffShopChoisi;
  if (onglet === 'agenda') { ffAgCharge(app, false); }
  if (shop) { ffCharge(app, shop, false); if (onglet === 'checklist') { ffCkCharge(app, S.ffCkCons || '', choisi ? shop : '', false); } if (onglet === 'sante' || onglet === 'meteo') { ffMeteoCharge(app, shop, false); } }
  const B = S.ff || {}, d = B.shop === shop && B.d && !B.d.error ? B.d : null;
  const go = (screen, extra) => () => app.setState(Object.assign({ screen }, extra || {}));
  const ETAPES = [['agenda', 'Agenda'], ['checklist', 'Checklist'], ['sante', 'Santé du magasin'], ['reseau', 'Benchmark réseau'], ['meteo', 'Fin de visite · météo']].map(([v, nom], i) => ({ v, nom: (i + 1) + ' · ' + nom, on: onglet === v, choisir: () => app.setState({ ffOnglet: v }) }));
  const f = { onglet, onglets: ETAPES,
    magasins: mags.map(m => ({ v: m.id, nom: m.court, on: String(m.id) === shop })), setShop: e => app.setState({ ffShop: e.target.value, ffShopChoisi: true, ffCkSel: null }),
    choisi, aChoisir: !choisi && ['checklist', 'sante', 'meteo'].includes(onglet), etapes: null,
    menu: () => app.setState({ railOuvert: !S.railOuvert }),
    rafraichir: () => { ffTableCharge(app, true); ffCharge(app, shop, true); if (onglet === 'agenda') { ffAgCharge(app, true); } if (onglet === 'checklist') { ffCkCharge(app, S.ffCkCons || '', choisi ? shop : '', true); } ffMeteoCharge(app, shop, true); },
    pageFranchise: shop ? racine() + 'dashboard/suivi.html?shop=' + encodeURIComponent(shop) : '',
    shopNom: (mags.find(m => String(m.id) === shop) || {}).court || shop, tableau: null, tableauChargement: !!TB.chargement && !T,
    chargement: !!B.chargement && !d, indispo: !B.chargement && B.d && !d ? 'Lecture impossible — API injoignable.' : '', kpis: [], journalier: [], terrain: [], journal: [], sous: '' };
  common.ff = f;
  if (T) {
    f.tableau = { mini: (T.trimestre ? 'scoring ' + T.trimestre.lib + ' · ' : '') + 'CA et B2B du mois · tâches, notes et réclamations sur 30 jours · invendus en % du CA sur 7 jours · relu ' + (T.lu || ''),
      rows: T.magasins.map(m => { const j = m.journalier, t = m.terrain; return { on: String(m.id) === shop, choisir: () => app.setState({ ffShop: m.id, ffOnglet: 'fiche' }), feu: m.feu, court: m.court, sous: (m.responsable ? m.responsable + ' · ' : '') + ((m.motifs || [])[0] || m.due || 'aucune alerte'),
        scoring: j.scoring ? nf(j.scoring.total, 1) + ' / 20' : '—', scoringSous: j.scoring ? (j.scoring.etoiles != null ? nf(j.scoring.etoiles, 1) + ' ★ · ' : '') + j.scoring.rang + 'ᵉ' + (j.scoring.n < 4 ? ' · ' + (4 - j.scoring.n) + ' poste(s) sans donnée' : '') : 'pas de scoring',
        taches: j.taches.pct != null ? j.taches.pct + ' %' : '—', tachesSous: j.taches.pct != null ? (j.taches.joursObligManques ? pl(j.taches.joursObligManques, 'jour') + ' obligatoire manquée' : 'obligatoires tenues') : (j.taches.motif || ''), tachesCls: j.taches.pct == null ? 'mu' : (j.taches.pct < 70 ? 'ko' : j.taches.pct < 85 ? 'att' : 'ok'),
        invendus: j.invendus.lu && j.invendus.part != null ? nf(j.invendus.part, 1) + ' %' : '—', invendusCls: j.invendus.lu && j.invendus.part != null ? (j.invendus.part > (j.invendus.cible || 4) ? 'ko' : 'ok') : 'mu',
        revues: j.revues && j.revues.lu && j.revues.moyenne != null ? nf(j.revues.moyenne, 1) + ' / 5' : '—', revuesSous: j.revues && j.revues.lu ? (j.revues.nc ? pl(j.revues.nc, 'infraction') + (j.revues.mineures ? ' · ' + j.revues.mineures + ' mineure' + (j.revues.mineures > 1 ? 's' : '') : '') : (j.revues.notees ? 'aucune infraction' : 'rien de noté')) : ((j.revues && j.revues.motif) || ''), revuesCls: j.revues && j.revues.lu ? ((j.revues.majeures || j.revues.critiques) ? 'ko' : (j.revues.mineures ? 'att' : (j.revues.notees ? 'ok' : 'mu'))) : 'mu',
        reclamations: j.reclamations && j.reclamations.lu ? String(j.reclamations.n) : '—', reclamationsSous: j.reclamations && j.reclamations.lu ? (j.reclamations.ouvertes ? j.reclamations.ouvertes + ' sans réponse' : (j.reclamations.n ? 'toutes traitées' : '')) : '', reclamationsCls: j.reclamations && j.reclamations.ouvertes ? 'att' : '', google: j.google && j.google.note != null ? nf(j.google.note, 1) + ' (' + j.google.avis + ')' + (j.google.faibles ? ' · ' + j.google.faibles + ' ≤ 2' : '') : '—',
        ca: j.ca && j.ca.pct != null ? (j.ca.pct >= 0 ? '+ ' : '− ') + Math.abs(j.ca.pct) + ' %' : (j.ca ? nf(j.ca.ca) + ' €' : '—'), caCls: j.ca && j.ca.pct != null ? (j.ca.pct < -10 ? 'ko' : j.ca.pct < 0 ? 'att' : 'ok') : 'mu',
        mois: j.mois && j.mois.atteinte != null ? nf(j.mois.atteinte) + ' %' : (j.mois ? nf(j.mois.ca) + ' €' : '—'), moisSous: j.mois ? nf(j.mois.ca) + ' €' + (j.mois.budget != null ? ' / ' + nf(j.mois.budget) + ' €' : '') : '', moisCls: j.mois && j.mois.atteinte != null ? (j.mois.atteinte >= 100 ? 'ok' : j.mois.atteinte >= 90 ? 'att' : 'ko') : 'mu',
        b2b: j.mois && j.mois.pro != null ? nf(j.mois.pro, 1) + ' %' : '—', b2bSous: j.mois && j.mois.pro == null && j.mois.proJours ? 'lu ' + (j.mois.proLus || 0) + ' / ' + j.mois.proJours + ' j' : '', b2bCls: j.mois && j.mois.pro != null ? (j.mois.pro > 40 ? 'ko' : j.mois.pro > 35 ? 'att' : 'ok') : 'mu',
        derniere: t.derniereVisite ? fmtD(t.derniereVisite.le) : 'jamais', derniereSous: t.derniereVisite ? (t.derniereVisite.consultant || '') + (t.prochaineVisite ? ' · prochaine ' + fmtD(t.prochaineVisite.le) : '') : (t.prochaineVisite ? 'prochaine ' + fmtD(t.prochaineVisite.le) : ''),
        plans: t.plansOuverts + (t.p0 ? ' · ' + t.p0 + ' P0' : ''), plansCls: t.p0 ? 'ko' : (t.plansOuverts ? 'att' : ''), plano: t.plano ? t.plano.pct + ' %' : '—', msp: t.msp ? nf(t.msp.obtenu) + ' / ' + nf(t.msp.maximum) + ' · ' + t.msp.trimestre.replace(/^\d{4}-/, '') : '—' }; }) };
  }
  const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  // Trois niveaux (demande du 09/10/2026) : la tuile dit le chiffre, simple et précis ; dépliée,
  // elle montre son détail ; « Tout voir » ouvre la modale sur la liste complète.
  const ouverts = S.ffOuverts || {};
  const TU = (k, lab, v, sous, cls, html, extra) => Object.assign({ k, lab, v, sous, cls: cls || '', on: !!ouverts[k], toggle: () => app.setState({ ffOuverts: Object.assign({}, ouverts, { [k]: !ouverts[k] }) }), html }, extra || {});
  const M = (titre, html) => () => app.setState({ ffModal: { titre, html } });
  f.modal = S.ffModal || null;
  f.fermerModal = e => { if (!e || !e.target || !e.target.closest('.ff-modal-c') || e.target.closest('.ff-x')) { app.setState({ ffModal: null }); } };
  const tab = (cols, rows) => '<table class="ff-tab"><thead><tr>' + cols.map(c => '<th>' + esc(c) + '</th>').join('') + '</tr></thead><tbody>' + rows.map(r => '<tr>' + r.map(c => '<td>' + c + '</td>').join('') + '</tr>').join('') + '</tbody></table>';
  const mu = s => '<span class="mu">' + esc(s) + '</span>';
  const nZ = (n, u) => n ? n + ' ' + u + (n > 1 ? 's' : '') : '';
  const FERME = { mois: 'mois', trimestre: 'trimestre' };
  const MARK = e => e === 'ok' ? '<b class="ok">✓</b>' : e === 'ko' ? '<b class="ko">✕</b>' : e === 'na' ? '<span class="mu">n/a</span>' : '<span class="mu">·</span>';
  // Étape suivante, étape précédente : la visite se lit dans l'ordre.
  const iE = ETAPES.findIndex(e => e.on);
  // L'habillage de l'application tablette (10/10/2026), comme la PWA Visites : en-tête, étape, barre du bas.
  const NAV = { agenda: ['📅', 'Agenda', 'Vos visites des 14 jours · choisissez le magasin'], checklist: ['✅', 'Checklist', 'La checklist du consultant, tâche par tâche'], sante: ['📊', 'Santé', 'CA, B2B, scoring, infractions, météo'], reseau: ['🏆', 'Réseau', 'Le magasin face au réseau'], meteo: ['☀️', 'Météo', 'Fin de visite · comment se sent le franchisé'] };
  const JL = new Date(auj0 + 'T12:00:00').toLocaleDateString('fr-BE', { weekday: 'long', day: 'numeric', month: 'long' });
  f.app = { titre: choisi ? 'Visite · ' + f.shopNom : 'Visites terrain', sous: JL + (common.userNom ? ' · ' + common.userNom : ''), init: common.userInit || (common.userNom || '·').split(/\s+/).map(x => x[0]).join('').slice(0, 2).toUpperCase(),
    etape: { num: iE + 1, total: ETAPES.length, nom: ETAPES[iE] ? ETAPES[iE].nom.replace(/^\d+ · /, '') : '', aide: (NAV[onglet] || [])[2] || '' },
    nav: ETAPES.map(e => ({ icone: (NAV[e.v] || ['•'])[0], nom: (NAV[e.v] || ['', e.nom])[1], on: e.on, choisir: e.choisir })) };
  f.etapes = { prec: iE > 0 ? { nom: ETAPES[iE - 1].nom, aller: ETAPES[iE - 1].choisir } : null, suiv: iE < ETAPES.length - 1 ? { nom: ETAPES[iE + 1].nom, aller: ETAPES[iE + 1].choisir } : null };
  // 1. Agenda : les visites des 14 prochains jours, et le choix du magasin.
  const choisirMag = (id, suite) => () => app.setState({ ffShop: String(id), ffShopChoisi: true, ffOnglet: suite || 'checklist', ffCkSel: null, ffCkMsg: null });
  const AG = S.ffAg || {}, ag = AG.d && !AG.d.error ? AG.d : null;
  const fin14 = plusJours(auj0, 13);
  const vis = ag ? ag.visites.filter(v => v.statut !== 'annulee' && v.prevu_le >= auj0 && v.prevu_le <= fin14).sort((x, y) => (x.prevu_le + x.debut_h) < (y.prevu_le + y.debut_h) ? -1 : 1) : [];
  const parJ = {}; vis.forEach(v => { (parJ[v.prevu_le] = parJ[v.prevu_le] || []).push(v); });
  f.agenda = { chargement: !!AG.chargement && !ag, indispo: AG.d && AG.d.error ? 'L’agenda ne se lit pas.' : '',
    jours: Object.keys(parJ).sort().map(dte => ({ lib: dte === auj0 ? 'Aujourd’hui · ' + fmtDJ(dte) : fmtDJ(dte), auj: dte === auj0,
      visites: parJ[dte].map(v => ({ heure: v.debut_h, magasin: v.magasin || ('Magasin ' + v.shop), type: v.typeNom || '', consultant: v.consultantNom || '', statut: (ST[v.statut] || ['', ''])[0], cls: (ST[v.statut] || ['', ''])[1], mienne: !!v.mienne, choisir: choisirMag(v.shop) })) })),
    magasins: mags.map(m => { const r = T ? T.magasins.find(x => String(x.id) === String(m.id)) : null; const t = r ? r.terrain : null;
      return { id: m.id, court: m.court, feu: r ? r.feu : '', on: choisi && String(m.id) === shop, sous: t ? (t.derniereVisite ? 'dernière ' + fmtD(t.derniereVisite.le) : 'jamais visité') + (t.prochaineVisite ? ' · prochaine ' + fmtD(t.prochaineVisite.le) : '') : '', choisir: choisirMag(m.id) }; }),
    planifier: go('gestionConsultant', { gcOnglet: 'planning' }) };
  // 2. Checklist : les checklists du consultant (API du panel), puis la liste des tâches et le formulaire.
  const CK = S.ffCk || {}, ck = CK.d && !CK.d.error && CK.cle === (S.ffCkCons || '') + '|' + (choisi ? shop : '') ? CK.d : null;
  const SA = ck && ck.saisies ? ck.saisies : {};
  f.ck = { chargement: !!CK.chargement && !ck, indispo: CK.d && CK.d.error ? 'La checklist ne se lit pas.' : (ck && !ck.lu ? 'Panel : ' + (ck.motif || 'sans réponse') : ''), consultants: [], liste: [], sel: null, busy: !!S.ffCkBusy, msg: S.ffCkMsg || null, source: ck && ck.lu ? 'API du panel · ' + fmtDJ(ck.date) : '' };
  if (ck && ck.lu) {
    f.ck.consultants = ck.consultants.map(c => ({ nom: c.nom + (c.moi ? ' · moi' : ''), sous: [c.poste, c.niveau].filter(Boolean).join(' · '), on: c.id === ck.choisi, choisir: () => app.setState({ ffCkCons: String(c.id), ffCkSel: null, ffCkMsg: null }) }));
    const remplies = cl => cl.taches.filter(t => SA[t.id]).length;
    f.ck.liste = ck.checklists.map(cl => ({ id: cl.id, nom: cl.nom, sous: [cl.poste, cl.heure].filter(Boolean).join(' · '), n: cl.taches.length, remplies: remplies(cl), oblig: cl.taches.filter(t => t.obligatoire).length, choisir: () => app.setState({ ffCkSel: String(cl.id), ffCkMsg: null }) }));
    const sel = S.ffCkSel != null ? ck.checklists.find(cl => String(cl.id) === String(S.ffCkSel)) : null;
    if (sel) {
      const cleF = ck.choisi + '|' + shop + '|' + sel.id;
      if (!app.state.ffCkForm || app.state.ffCkForm.cle !== cleF) { const tt = {}; sel.taches.forEach(t => { if (SA[t.id]) { tt[t.id] = { etat: SA[t.id].etat, commentaire: SA[t.id].commentaire || '' }; } }); app.state.ffCkForm = { cle: cleF, taches: tt, panel: true }; }
      const F = app.state.ffCkForm;
      const tF = id => (F.taches[id] = F.taches[id] || { etat: '', commentaire: '' });
      f.ck.sel = { nom: sel.nom, description: sel.description, moi: !!ck.moi, panel: F.panel !== false, setPanel: e => { F.panel = !!e.target.checked; app.setState({}); },
        retour: () => app.setState({ ffCkSel: null, ffCkMsg: null }), enregistrer: () => ffCkSauver(app, shop, ck, sel, F, F.panel !== false && ck.moi),
        faites: sel.taches.filter(t => (F.taches[t.id] || {}).etat).length,
        taches: sel.taches.map(t => { const e = (F.taches[t.id] || {}).etat || ''; const sa = SA[t.id];
          return { id: t.id, nom: t.nom, description: t.description || t.note || '', groupe: [t.section, t.categorie, t.sousCategorie].filter(Boolean).join(' › '),
            badges: [t.obligatoire ? 'obligatoire' : '', t.photoRequise ? 'photo requise' : '', t.priorite != null ? 'priorité ' + t.priorite : '', t.heure ? t.heure : '', t.faitPanel ? 'fait dans le panel' : ''].filter(Boolean),
            choix: [['fait', 'Fait'], ['pas_fait', 'Pas fait'], ['na', 'N/A']].map(([v, nom]) => ({ v, nom, on: e === v, choisir: () => { tF(t.id).etat = e === v ? '' : v; app.setState({ ffCkMsg: null }); } })),
            commentaire: (F.taches[t.id] || {}).commentaire || '', setCom: ev => { tF(t.id).commentaire = ev.target.value; },
            deja: sa ? 'enregistrée à ' + sa.le + (sa.panel ? ' · panel ' + sa.panel : '') : '' }; }) };
    }
  }
  // Onglet Météo : le formulaire, puis les météos passées.
  const MT = S.ffMeteo || {}, mt = MT.shop === shop && MT.d && !MT.d.error ? MT.d : null;
  const FM = S.ffMeteoForm && S.ffMeteoForm.shop === shop ? S.ffMeteoForm : { shop, taches: true };
  const majF = patch => { app.state.ffMeteoForm = Object.assign({}, FM, app.state.ffMeteoForm && app.state.ffMeteoForm.shop === shop ? app.state.ffMeteoForm : {}, patch); };
  f.meteo = { chargement: !!MT.chargement && !mt, busy: !!S.ffMeteoBusy, msg: S.ffMeteoMsg || null, taches: FM.taches !== false,
    setTaches: e => { majF({ taches: !!e.target.checked }); app.setState({}); },
    echelles: METEO_K.map(([k, nom]) => ({ nom, v: FM[k] || null, choix: [1, 2, 3, 4, 5].map(n => ({ n, icone: METEO_I[n], titre: METEO_L[n], on: FM[k] === n, choisir: () => { majF({ [k]: FM[k] === n ? null : n }); app.setState({ ffMeteoMsg: null }); } })) })),
    textes: METEO_T.map(([k, nom, aide]) => ({ nom, aide, v: FM[k] || '', set: e => majF({ [k]: e.target.value }) })),
    enregistrer: () => ffMeteoSauver(app, shop), derniere: null, tendance: [], historique: [], ouvertes: 0 };
  const echTxt = m => METEO_K.map(([k, nom]) => m[k] != null ? nom.split(',')[0] + ' ' + METEO_I[m[k]] + ' ' + m[k] : '').filter(Boolean).join(' · ');
  const meteoHtml = m => METEO_K.map(([k, nom]) => '<div class="ff-clp"><span style="width:22px">' + (m[k] != null ? METEO_I[m[k]] : '·') + '</span><span>' + esc(nom) + ' : <b>' + (m[k] != null ? m[k] + ' / 5 · ' + METEO_L[m[k]] : '—') + '</b></span></div>').join('')
    + (m.envies ? '<div class="ff-clm"><b>Ses envies</b>' + esc(m.envies).replace(/\n/g, '<br>') + '</div>' : '')
    + (m.demandes.length ? '<div class="ff-clm"><b>Ses demandes</b>' + m.demandes.map(x => '<div class="ff-clp">' + (x.statut === 'fait' ? '<b class="ok">✓</b>' : x.statut === 'annule' ? '<span class="mu">✕</span>' : '<b class="att">○</b>') + '<span>' + esc(x.texte) + (x.tacheId ? ' <span class="mu">· tâche ' + (x.statut === 'fait' ? 'faite' + (x.faitLe ? ' le ' + fmtD(String(x.faitLe).slice(0, 10)) : '') : x.statut === 'annule' ? 'annulée' : 'ouverte') + '</span>' : '') + '</span></div>').join('') + '</div>' : '')
    + (m.inquietudes ? '<div class="ff-clm"><b>Ses inquiétudes</b>' + esc(m.inquietudes).replace(/\n/g, '<br>') + '</div>' : '')
    + (m.note ? '<div class="ff-clm"><b>Ce que j’en retiens</b>' + esc(m.note).replace(/\n/g, '<br>') + '</div>' : '');
  if (mt) {
    f.meteo.ouvertes = mt.demandesOuvertes || 0;
    f.meteo.derniere = mt.derniere ? { le: fmtDJ(mt.derniere.le), qui: mt.derniere.consultant || mt.derniere.creePar, cases: METEO_K.map(([k, nom]) => ({ nom, icone: mt.derniere[k] != null ? METEO_I[mt.derniere[k]] : '·', v: mt.derniere[k] != null ? mt.derniere[k] + ' / 5' : '—', cls: mCls(mt.derniere[k]) })) } : null;
    f.meteo.tendance = METEO_K.map(([k, nom]) => ({ nom, points: (mt.tendance || []).map(x => ({ icone: x[k] != null ? METEO_I[x[k]] : '·', titre: fmtD(x.le) + (x[k] != null ? ' : ' + x[k] + ' / 5' : '') })) }));
    f.meteo.historique = mt.meteos.map(m => TU('mt:' + m.id, fmtDJ(m.le) + (m.consultant ? ' · ' + m.consultant : ''), m.moral != null ? METEO_I[m.moral] + ' ' + m.moral + ' / 5' : '—', echTxt(m) + (m.demandes.length ? ' · ' + pl(m.demandes.length, 'demande') : ''), mCls(m.moral), meteoHtml(m)));
  }
  if (!d) { return; }
  const J = d.journalier, R = d.terrain, V = R.visites;
  const fe = d.feu || {};
  f.sous = 'fiche franchisé · ' + (d.magasin.ville || '') + (d.consultants.length ? ' · ' + d.consultants.map(c => c.nom + ' (' + c.types.join(', ') + ')').join(' · ') : '') + (fe.feu ? ' · feu ' + fe.feu + (fe.motifs && fe.motifs.length ? ' : ' + fe.motifs[0] : '') : '');
  const sc = R.scoring; const RV = J.revues || {}, RC = J.reclamations || {}, TA = J.taches || {}, IV = J.invendus || {}, tj = J.tachesJour || {};
  const pctCls = p => p == null ? 'mu' : (p < 70 ? 'ko' : p < 85 ? 'att' : 'ok');
  const heat = () => { const parJ = {}; (TA.jours || []).forEach(x => { parJ[x.jour] = x; }); let h = ''; for (let i = 29; i >= 0; i--) { const dte = plusJours(auj0, -i); const x = parJ[dte]; h += '<i class="' + (!x ? 'x' : (x.oblig ? 'm' : (x.f < x.t ? 'p' : ''))) + '" title="' + fmtD(dte) + (x ? ' : ' + x.f + ' / ' + x.t : ' : non relevé') + '"></i>'; } return '<div class="ff-heat">' + h + '</div>'; };
  const NIV = i => '<span class="gc-st ' + (i.niveau === 'critique' ? 'p0' : i.niveau === 'majeure' ? 'att' : 'm') + '">' + esc(i.niveau) + '</span>';
  const SUITE = i => i.suite ? fmtD(i.suite.jour) + ' : ' + (i.suite.conforme ? '<span class="ok">conforme</span>' : '<span class="ko">toujours non conforme</span>') : '<span class="mu">pas encore renotée</span>';
  const ST_RC = r => r.ouverte ? '<b class="att">sans réponse</b>' : esc(r.statut === 'REJECTED' ? 'refusée' : r.statut === 'ACCEPTED' ? 'acceptée' : String(r.statut || '').toLowerCase());
  const OB = J.objectifs || { produits: [], campagnes: [] }; const o1 = OB.produits[0];
  f.kpis = [];
  f.journalier = [
    TU('taches', 'Tâches faites · 30 j', TA.pct != null ? TA.pct + ' %' : '—', TA.pct != null ? TA.rendues + ' / ' + TA.attendues + (TA.joursObligManques ? ' · obligatoire manquée ' + nZ(TA.joursObligManques, 'jour') : ' · obligatoires tenues') : (TA.motif || ''), pctCls(TA.pct),
      (tj.lu ? 'Le ' + fmtD(tj.date) + ' : <b>' + tj.notees + ' / ' + tj.taches + '</b> photos notées' + (tj.aNoter ? ' · <b>' + tj.aNoter + ' à noter</b>' : '') : mu('Les revues du jour ne se lisent pas.')) + heat() + '<div class="gc-leg" style="margin-top:4px"><span><i style="background:#C9E3CD"></i>tout rendu</span><span><i style="background:#F5D58F"></i>partiel</span><span><i style="background:#E8A0A0"></i>obligatoire manquée</span><span><i style="background:#EDE7E0"></i>non relevé</span></div>',
      { modal: (TA.jours || []).length ? M('Tâches jour par jour · 30 jours', tab(['Jour', 'Rendues', 'Attendues', 'Obligatoire'], TA.jours.slice().reverse().map(x => [fmtDJ(x.jour), String(x.f), String(x.t), x.oblig ? '<b class="ko">manquée</b>' : 'tenue']))) : null, lien: go('suivi'), lienTxt: 'Tâches et contrôles photo' }),
    TU('notes', 'Points moyens', RV.lu && RV.moyenne != null ? nf(RV.moyenne, 1) + ' / 5' : '—', RV.lu ? (RV.notees ? RV.notees + ' notées · ' + (RV.parNote || []).map(p => p.note + '★ ' + p.n).join(' · ') : 'rien de noté sur 30 jours') : (RV.motif || ''), RV.lu && RV.moyenne != null ? (RV.moyenne >= 4 ? 'ok' : RV.moyenne >= 3 ? 'att' : 'ko') : 'mu',
      RV.lu && RV.notees ? '<div class="ff-rep">' + (RV.parNote || []).map(p => '<div><span>' + p.note + ' ★ ' + esc(p.nom) + '</span><i><b style="width:' + Math.round(100 * p.n / RV.notees) + '%"></b></i><span>' + p.n + '</span></div>').join('') + '</div>' : mu(RV.lu ? 'Aucune tâche notée sur 30 jours.' : (RV.motif || 'les revues ne se lisent pas')),
      { lien: go('suivi'), lienTxt: 'Contrôle des tâches' }),
    TU('infractions', 'Infractions · 30 j', RV.lu ? String(RV.nc || 0) : '—', RV.lu ? ([nZ(RV.mineures, 'mineure'), nZ(RV.majeures, 'majeure'), nZ(RV.critiques, 'critique')].filter(Boolean).join(' · ') || 'aucune') : (RV.motif || ''), RV.lu ? (RV.nc ? ((RV.majeures || RV.critiques) ? 'ko' : 'att') : 'ok') : 'mu',
      RV.lu && RV.nc ? '<div class="ff-nc">' + (RV.infractions || []).map(i => '<div>' + NIV(i) + '<span>' + fmtD(i.jour) + ' · <b>' + esc(i.tache) + '</b>' + (i.note != null ? ' · ' + i.note + ' / 5' : '') + (i.comment ? ' — ' + esc(i.comment) : '') + (i.recidive ? ' · <b class="ko">' + i.recidive + ' j sur 7</b>' : '') + '</span></div>').join('') + '</div>' : mu(RV.lu ? 'Aucune infraction sur 30 jours.' : (RV.motif || 'les revues ne se lisent pas')),
      { modal: RV.lu && RV.nc ? M('Les infractions · 30 jours', tab(['Jour', 'Tâche', 'Note', 'Gravité', 'Commentaire', 'Récidive', 'Suite', 'Par'], (RV.infractions || []).map(i => [fmtDJ(i.jour), '<b>' + esc(i.tache) + '</b>', i.note != null ? i.note + ' / 5' : '—', NIV(i) + ' ' + esc(i.niveauNom || ''), esc(i.comment || ''), i.recidive ? '<b class="ko">' + i.recidive + ' j sur 7</b>' : '—', SUITE(i), esc(i.consultant || '')]))) : null, lien: go('suivi'), lienTxt: 'Contrôle des tâches' }),
    TU('invendus', 'Invendus · 7 j', IV.lu && IV.part != null ? nf(IV.part, 1) + ' %' : '—', IV.lu && IV.part != null ? 'du CA · cible sous ' + nf(IV.cible || 4) + ' %' : (IV.motif || ''), IV.lu && IV.part != null ? (IV.part > (IV.cible || 4) ? 'ko' : 'ok') : 'mu',
      IV.lu ? 'Du ' + fmtD(IV.du) + ' au ' + fmtD(IV.au) + ' : <b>' + nf(IV.cout, 2) + ' €</b> d’invendus' + (IV.ca != null ? ' pour ' + nf(IV.ca) + ' € de CA' : '') + '. ' + mu('Pièces, motifs et produits : écran Invendus et poubelle.') : mu(IV.motif || 'la poubelle du panel ne se lit pas'),
      { lien: go('invendus'), lienTxt: 'Invendus et poubelle' }),
    TU('reclamations', 'Réclamations · 30 j', RC.lu ? String(RC.n) : '—', RC.lu ? ((RC.ouvertes ? RC.ouvertes + ' sans réponse' : (RC.n ? 'toutes traitées' : 'aucune')) + (RC.montant ? ' · ' + nf(RC.montant, 2) + ' €' : '')) : (RC.motif || ''), RC.lu ? (RC.ouvertes ? 'att' : '') : 'mu',
      RC.lu && RC.n ? ((RC.parFournisseur || []).length ? 'Par fournisseur : ' + RC.parFournisseur.map(f2 => esc(f2.nom) + ' ' + f2.n).join(' · ') + '<br>' : '') + '<span class="mu">' + (RC.dernieres || []).map(r => fmtD(r.le) + ' ' + esc(r.fournisseur) + ' — ' + esc(r.reference) + (r.qte != null ? ' ' + nf(r.qte) + ' ' + esc(r.unite) : '') + (r.motif ? ' · ' + esc(r.motif) : '') + ' · ' + ST_RC(r)).join('<br>') + '</span>' : mu(RC.lu ? 'Aucune réclamation sur 30 jours.' : (RC.motif || 'le panel n’a pas rendu les réclamations')),
      { modal: RC.lu && RC.n ? M('Les réclamations fournisseur · 30 jours', tab(['Date', 'Fournisseur', 'Référence', 'Qté', 'Motif', 'Statut', 'Réponse', 'Montant'], (RC.lignes || RC.dernieres || []).map(r => [fmtDJ(r.le), esc(r.fournisseur), '<b>' + esc(r.reference) + '</b>', r.qte != null ? nf(r.qte) + ' ' + esc(r.unite) : '—', esc(r.motif), ST_RC(r), esc(r.reponse || '') + (r.reponseLe ? ' <span class="mu">' + fmtD(r.reponseLe) + '</span>' : ''), r.montant != null ? nf(r.montant, 2) + ' €' : '—']))) : null, lien: go('caAchats'), lienTxt: 'Réclamations fournisseur' }),
    TU('objectifs', 'Objectifs', o1 ? (o1.pct != null ? o1.pct + ' %' : '—') : (OB.campagnes[0] ? nf(OB.campagnes[0].reel) : '—'), o1 ? o1.nom + (OB.produits.length > 1 ? ' · + ' + (OB.produits.length - 1) : '') : (OB.campagnes[0] ? 'clients · ' + OB.campagnes[0].nom : 'aucun objectif en cours'), o1 && o1.pct != null ? (o1.pct >= 100 ? 'ok' : o1.pct >= 70 ? 'att' : 'ko') : 'mu',
      (OB.produits.length ? OB.produits.map(c => '« ' + esc(c.nom) + ' » : <b>' + (c.pct != null ? c.pct + ' %' : '—') + '</b> · ' + nf(c.vendu) + ' / ' + nf(c.objectif) + ' pièces · jusqu’au ' + fmtD(c.fin)).join('<br>') : mu('Pas d’objectif produits en cours.')) + (OB.campagnes.length ? '<br>' + OB.campagnes.map(c => 'Campagne « ' + esc(c.nom) + ' » : <b>' + nf(c.reel) + '</b> clients' + (c.clientsPrevus != null ? ' / ' + nf(c.clientsPrevus) : '') + (c.n1Ecoule != null ? ' · N−1 ' + nf(c.n1Ecoule) : '') + (c.jourCourant ? ' · jour ' + c.jourCourant + ' / ' + c.nbJours : '')).join('<br>') : ''),
      { lien: go('mktObjectifs'), lienTxt: 'Objectifs' }),
    TU('remarques', 'Remarques · 30 j', String(J.remarques.n || 0), J.remarques.n ? J.remarques.parOperateur.map(o => o.employe + ' ' + o.n).join(' · ') : 'aucune', J.remarques.n ? 'att' : 'ok',
      J.remarques.n ? '<span class="mu">' + J.remarques.dernieres.map(r => fmtD(r.le) + ' ' + esc(r.employe) + ' — ' + esc(r.produit) + (r.motifLib ? ' · ' + esc(r.motifLib) : '') + (r.texte ? ' : ' + esc(r.texte) : '')).join('<br>') + '</span>' : mu('Aucune remarque sur 30 jours.'),
      { lien: go('remarquesOperateurs', { roShop: shop }), lienTxt: 'Remarques aux opérateurs' }),
    TU('google', 'Note Google', J.google && J.google.note != null ? nf(J.google.note, 1) : '—', J.google && J.google.note != null ? nf(J.google.avis) + ' avis · ' + (J.google.faibles ? J.google.faibles + ' ≤ 2 sur 30 j' : 'aucun avis faible') + (J.google.cible ? ' · cible ' + nf(J.google.cible, 1) : '') : 'pas de fiche reliée', J.google && J.google.note != null ? (J.google.faibles || (J.google.cible && J.google.note < J.google.cible) ? 'att' : 'ok') : 'mu',
      J.google && (J.google.derniers || []).length ? '<span class="mu">' + J.google.derniers.slice(0, 5).map(a => fmtD(a.le) + ' ' + a.note + '/5' + (a.extrait ? ' « ' + esc(a.extrait) + ' »' : '')).join('<br>') + '</span>' : mu('Pas d’avis récent.'),
      { lien: go('reputation'), lienTxt: 'Note Google et avis' }),
    TU('ca', 'CA semaine', J.ca ? nf(J.ca.ca) + ' €' : '—', J.ca ? ((J.ca.objectif ? 'objectif ' + nf(J.ca.objectif) + ' €' : '') + (J.ca.pct != null ? ' · ' + (J.ca.pct >= 0 ? '+ ' : '− ') + Math.abs(J.ca.pct) + ' %' : '')) : 'ne se lit pas', J.ca && J.ca.pct != null ? (J.ca.pct < -10 ? 'ko' : J.ca.pct < 0 ? 'att' : 'ok') : 'mu',
      J.ca ? pl(J.ca.jours, 'jour') + ' vu' + (J.ca.jours > 1 ? 's' : '') + ' cette semaine. ' + mu('Le poste Budget du scoring lit le CA du trimestre face au budget.') : mu('Le CA de la semaine ne se lit pas.'),
      { lien: go('resultatJour'), lienTxt: 'Résultat' })];
  const der = V.derniere; const pls = R.plans.filter(p => /^(ouvert|reprendre|escalade|attente)$/.test(p.statut)); const fermes = R.plans.filter(p => p.statut === 'ferme' || p.statut === 'valide');
  const msp = R.msp; const conf = R.conformite || {}; const plano = conf.planogramme || {}; const asso = conf.assortiment || {};
  const nP = p => pls.filter(x => x.priorite === p).length; const pr0 = V.prochaines[0];
  const PRI = p => '<span class="gc-st ' + (p.priorite === 'P0' ? 'p0' : p.priorite === 'P1' ? 'att' : 'm') + '">' + p.priorite + '</span>';
  f.terrain = [
    TU('visite', 'Dernière visite', der ? (der.points.total ? der.points.ok + ' / ' + der.points.total : fmtD(der.prevu_le)) : '—', der ? fmtD(der.prevu_le) + ' · ' + der.typeNom + ' · ' + der.consultantNom : 'aucune visite terminée', der ? (der.points.ko ? 'ko' : 'ok') : 'mu',
      der ? (der.points.ko ? '<b class="ko">' + der.points.ko + ' non conforme' + (der.points.ko > 1 ? 's' : '') + '</b> : ' + der.ecarts.map(e => esc(e.libelle) + (e.valeur != null ? ' ' + e.valeur + ' %' : e.note != null ? ' ' + e.note + '/5' : '') + (e.commentaire ? ' (' + esc(e.commentaire) + ')' : '')).join(', ') : '<span class="ok">Rien de non conforme.</span>') + (der.reco ? '<br>' + mu('Recommandation : ' + der.reco) : '') + (der.diagnostic ? '<br>' + mu('Diagnostic : ' + der.diagnostic) : '') : mu('Aucune visite terminée.'),
      { href: racine() + 'visites/?role=admin#historique/' + shop, lienTxt: 'Visites' }),
    TU('plans', 'Plans d’action', String(pls.length), pls.length ? [nZ(nP('P0'), 'P0'), nZ(nP('P1'), 'P1'), nZ(nP('P2'), 'P2')].filter(Boolean).join(' · ') : 'aucun ouvert', nP('P0') ? 'ko' : (pls.length ? 'att' : 'ok'),
      (pls.length ? pls.map(p => PRI(p) + ' ' + esc(p.titre) + ' — ' + (PLAN_ST[p.statut] || p.statut) + (p.echeance ? ', échéance ' + fmtD(p.echeance) : '') + (p.retard ? ' · <b class="ko">retard ' + p.retard + ' j</b>' : '') + ' · ' + (ASSIGNES[p.assigne] || '')).join('<br>') : mu('Aucun plan ouvert.')) + (fermes.length ? '<br>' + mu(fermes.length + ' fermé(s) ou validé(s) sur 90 jours') : ''),
      { modal: R.plans.length ? M('Les plans d’action · 90 jours', tab(['Priorité', 'Plan', 'Statut', 'Échéance', 'Retard', 'Assigné'], R.plans.map(p => [PRI(p), '<b>' + esc(p.titre) + '</b>' + (p.detail ? '<br><span class="mu">' + esc(p.detail) + '</span>' : ''), esc(PLAN_ST[p.statut] || p.statut), p.echeance ? fmtDJ(p.echeance) : '—', p.retard ? '<b class="ko">' + p.retard + ' j</b>' : '—', esc(ASSIGNES[p.assigne] || '')]))) : null, href: racine() + 'visites/?role=admin', lienTxt: 'Plans d’action' }),
    TU('prochaine', 'Prochaine visite', pr0 ? fmtD(pr0.prevu_le) : '—', pr0 ? pr0.typeNom + ' · ' + pr0.consultantNom + ' · ' + pr0.debut_h : (R.cadre.length ? 'à planifier d’après le cadre' : 'aucun cadre réglé'), pr0 ? '' : (R.cadre.length ? 'att' : 'mu'),
      (V.prochaines.length ? V.prochaines.map(v => fmtDJ(v.prevu_le) + ' ' + v.debut_h + ' · <b>' + esc(v.typeNom) + '</b> · ' + esc(v.consultantNom) + ' · ' + (ST[v.statut] || ['', ''])[0]).join('<br>') : mu('Rien de planifié.')) + '<br>' + (R.cadre.length ? mu('Cadre du mois : ' + R.cadre.map(l => l.typeNom + ' ' + l.faites + ' faite(s) + ' + l.planifiees + ' planifiée(s) / ' + l.attendu + (l.aPlanifier ? ' · ' + l.aPlanifier + ' à planifier' : '') + (l.consultantNom ? ' (' + l.consultantNom + ')' : '')).join(' · ')) : mu('Aucune ligne de cadre pour ce magasin.')) + '<br>' + mu('Ce mois : ' + V.mois.faites + ' faite(s), ' + V.mois.planifiees + ' planifiée(s).'),
      { lien: go('gestionConsultant', { gcOnglet: 'planning' }), lienTxt: 'Gestion consultant' }),
    TU('msp', 'Client mystère', msp ? nf(msp.obtenu) + ' / ' + nf(msp.maximum) : '—', msp ? msp.trimestre.replace(/^\d{4}-/, '') + (msp.v != null ? ' · ' + nf(msp.v, 1) + ' / 5' : '') + (msp.le ? ' · le ' + fmtD(msp.le) : '') : 'pas de rapport encodé', msp && msp.v != null ? (msp.v >= 4 ? 'ok' : msp.v >= 3 ? 'att' : 'ko') : 'mu',
      msp ? (((Object.keys(msp.rubriques || {}).length ? Object.keys(msp.rubriques).map(k => esc(k) + ' <b>' + esc(msp.rubriques[k]) + '</b>').join(' · ') : '') + (msp.commentaire ? '<br>' + mu('« ' + msp.commentaire + ' »') : '')) || mu('Sans rubrique ni commentaire.')) : mu('Pas de rapport encodé au scoring.'),
      { lien: go('scoringTri', { sqVue: 'msp' }), lienTxt: 'Client mystère' }),
    TU('comptoir', 'Comptoir', plano.pct != null ? plano.pct + ' %' : '—', (plano.pct != null ? 'planogramme tenu' : (plano.motif || conf.motif || 'planogramme non lu')) + (asso.obligatoires != null && !asso.motif ? ' · obligatoires ' + asso.presentes + ' / ' + asso.obligatoires : ''), plano.pct != null ? ((asso.manquantes || plano.pct < 80) ? 'att' : 'ok') : 'mu',
      (plano.pct != null ? 'Planogramme : <b>' + plano.pct + ' %</b>' + (plano.tenus != null ? ' (' + plano.tenus + ' / ' + plano.emplacements + ' emplacements)' : '') + (plano.comptoirsMontes != null ? ' · ' + plano.comptoirsMontes + ' comptoir(s) photographié(s) aujourd’hui' : '') : mu('Planogramme : ' + (plano.motif || conf.motif || 'non lu'))) + '<br>' + (asso.obligatoires != null && !asso.motif ? 'Obligatoires vues en caisse sur ' + (asso.jours || 30) + ' j : <b>' + asso.presentes + ' / ' + asso.obligatoires + '</b>' + (asso.manquantes ? ' · <b class="ko">' + pl(asso.manquantes, 'manquante') + '</b>' + ((asso.liste || []).length ? ' : ' + asso.liste.filter(l => l.manquante !== false).slice(0, 6).map(l => esc(l.nom)).join(', ') : '') : ' · toutes passées en caisse') : mu('Assortiment : ' + (asso.motif || 'non lu'))) + (R.plano ? '<br>' + mu('À la dernière visite : planogramme ' + R.plano.pct + ' % le ' + fmtD(R.plano.le)) : ''),
      { lien: go('assortiment'), lienTxt: 'Conformité du comptoir' }),
    TU('scoring', 'Scoring ' + (sc && sc.trimestre ? (sc.trimestre.court || sc.trimestre.lib) : 'du trimestre'), sc ? nf(sc.total, 1) + ' / 20' : '—', sc ? (sc.etoiles != null ? nf(sc.etoiles, 1) + ' ★ · ' : '') + sc.rang + 'ᵉ sur ' + sc.magasins + ' · précédent ' + nf(sc.prec.total, 1) : 'pas de scoring calculé', sc ? (sc.total >= 16 ? 'ok' : sc.total >= 12 ? 'att' : 'ko') : 'mu',
      sc ? '<div class="ff-postes">' + Object.keys(sc.postes).map(k => '<div><small>' + esc((sc.noms && sc.noms[k]) || k) + '</small><b>' + (sc.postes[k].v != null ? nf(sc.postes[k].v, 1) : '—') + '</b></div>').join('') + '</div>' + mu('Chaque poste vaut 5' + (sc.delta != null ? ' · ' + (sc.delta >= 0 ? '+ ' : '− ') + nf(Math.abs(sc.delta), 2) + ' ★ sur le trimestre précédent' : '') + '.') : mu('Pas de scoring calculé pour ce trimestre.'),
      { lien: go('scoringTri'), lienTxt: 'Scoring du trimestre' })];
  // Santé du magasin : la vue générale en tête (09/10/2026) — le mois face au budget, la part du pro, le scoring,
  // ses postes Google et Tâches, les infractions mineures, la météo du franchisé.
  const prend = (arr, k) => { const i = arr.findIndex(t => t.k === k); return i >= 0 ? arr.splice(i, 1)[0] : null; };
  const tInf = prend(f.journalier, 'infractions'), tGoo = prend(f.journalier, 'google'), tSco = prend(f.terrain, 'scoring');
  const MO = J.mois || {}, PRO = MO.pro || {};
  const pv = k => sc && sc.postes && sc.postes[k] && sc.postes[k].v != null ? sc.postes[k].v : null;
  const c5 = v => v == null ? 'mu' : (v >= 4 ? 'ok' : v >= 3 ? 'att' : 'ko');
  const joursM = (MO.jours || []).filter(x => x.passe && !x.ferme);
  const maxJ = Math.max(1, ...joursM.map(x => Math.max(+x.ca || 0, +x.objectif || 0)));
  const barres = joursM.length ? '<div class="ff-bars">' + joursM.map(x => '<i title="' + esc(x.court) + ' : ' + nf(x.ca) + ' €' + (x.objectif != null ? ' / ' + nf(x.objectif) + ' €' : '') + '"><b class="' + (x.objectif != null && x.ca != null && x.ca < x.objectif ? 'ko' : 'ok') + '" style="height:' + Math.round(100 * (+x.ca || 0) / maxJ) + '%"></b>' + (x.objectif != null ? '<u style="bottom:' + Math.round(100 * x.objectif / maxJ) + '%"></u>' : '') + '</i>').join('') + '</div><div class="gc-leg" style="margin-top:2px"><span><i style="background:#8FBF98"></i>CA du jour</span><span><i style="background:#D98C8C"></i>sous le budget</span><span><i style="background:#333"></i>budget du jour</span></div>' : '';
  const MT2 = mt && mt.derniere ? mt.derniere : null;
  f.sante = [
    TU('mois', 'CA du mois / budget', MO.lu ? (MO.atteinte != null ? nf(MO.atteinte) + ' %' : nf(MO.ca) + ' €') : '—', MO.lu ? nf(MO.ca) + ' €' + (MO.attendu != null ? ' sur ' + nf(MO.attendu) + ' € attendus à date' : ' · pas de budget') : (MO.motif || ''), MO.lu && MO.atteinte != null ? (MO.atteinte >= 100 ? 'ok' : MO.atteinte >= 90 ? 'att' : 'ko') : 'mu',
      MO.lu ? (MO.budget != null ? 'Budget du mois : <b>' + nf(MO.budget) + ' €</b>' + (MO.ecart != null ? ' · écart à date <b class="' + (MO.ecart < 0 ? 'ko' : 'ok') + '">' + (MO.ecart >= 0 ? '+ ' : '− ') + nf(Math.abs(MO.ecart)) + ' €</b>' : '') : mu('Aucun budget encodé pour ce mois.')) + (MO.tickets ? '<br>' + nf(MO.tickets) + ' tickets · panier ' + nf(MO.panier, 2) + ' €' : '') + barres : mu(MO.motif || 'le résultat du mois ne se lit pas'),
      { modal: joursM.length ? M('CA du mois jour par jour', tab(['Jour', 'CA', 'Budget', 'Écart', 'Atteinte'], joursM.map(x => [esc(x.court), nf(x.ca) + ' €', x.objectif != null ? nf(x.objectif) + ' €' : '—', x.objectif != null && x.ca != null ? '<b class="' + (x.ca < x.objectif ? 'ko' : 'ok') + '">' + (x.ca >= x.objectif ? '+ ' : '− ') + nf(Math.abs(x.ca - x.objectif)) + ' €</b>' : '—', x.objectif ? nf(100 * x.ca / x.objectif) + ' %' : '—']))) : null, lien: go('resultatJour'), lienTxt: 'Résultat' }),
    TU('b2b', 'Part B2B · mois', PRO.part != null ? nf(PRO.part, 1) + ' %' : '—', PRO.part != null ? 'du CA · règle ≤ ' + nf(PRO.max || 40) + ' %' : (PRO.jours ? 'tickets lus ' + (PRO.joursLus || 0) + ' / ' + PRO.jours + ' jours' : (MO.motif || 'tickets non lus')), PRO.part != null ? (PRO.part > (PRO.max || 40) ? 'ko' : PRO.part > (PRO.alerte || 35) ? 'att' : 'ok') : 'mu',
      MO.lu ? 'CA pro : <b>' + nf(PRO.ca) + ' €</b>' + (PRO.tickets != null ? ' · ' + nf(PRO.tickets) + ' tickets pro' : '') + (PRO.panier != null ? ' · panier pro ' + nf(PRO.panier, 2) + ' €' : '') + (PRO.complet === false && PRO.jours ? '<br>' + mu('Part calculée quand tous les jours sont lus : ' + (PRO.joursLus || 0) + ' / ' + PRO.jours + '.') : '') + '<br>' + mu('Règle : le pro ne dépasse pas ' + nf(PRO.max || 40) + ' % du CA du mois.') : mu(MO.motif || 'le résultat du mois ne se lit pas'),
      { lien: go('resultatJour'), lienTxt: 'Résultat' }),
    tSco,
    TU('scGoogle', 'Scoring Google', pv('google') != null ? nf(pv('google'), 1) + ' / 5' : '—', J.google && J.google.note != null ? 'note ' + nf(J.google.note, 1) + ' · ' + nf(J.google.avis) + ' avis' + (J.google.faibles ? ' · ' + J.google.faibles + ' ≤ 2' : '') : 'pas de fiche reliée', c5(pv('google')), tGoo ? tGoo.html : '', { lien: go('reputation'), lienTxt: 'Note Google et avis' }),
    TU('scTaches', 'Scoring tâches', pv('taches') != null ? nf(pv('taches'), 1) + ' / 5' : '—', (TA.pct != null ? TA.pct + ' % faites' : (TA.motif || 'pas de relevé')) + (RV.lu && RV.moyenne != null ? ' · points moyens ' + nf(RV.moyenne, 1) : ''), c5(pv('taches')),
      (TA.pct != null ? 'Faites sur 30 jours : <b>' + TA.rendues + ' / ' + TA.attendues + '</b>' + (TA.joursObligManques ? ' · <b class="ko">obligatoire manquée ' + nZ(TA.joursObligManques, 'jour') + '</b>' : ' · obligatoires tenues') : '') + (RV.lu && RV.notees ? '<div class="ff-rep" style="margin-top:6px">' + (RV.parNote || []).map(p => '<div><span>' + p.note + ' ★ ' + esc(p.nom) + '</span><i><b style="width:' + Math.round(100 * p.n / RV.notees) + '%"></b></i><span>' + p.n + '</span></div>').join('') + '</div>' : ''),
      { lien: go('suivi'), lienTxt: 'Contrôle des tâches' }),
    tInf && Object.assign(tInf, { lab: 'Infractions mineures · 30 j', v: RV.lu ? String(RV.mineures || 0) : '—', sous: RV.lu ? ([nZ(RV.majeures, 'majeure'), nZ(RV.critiques, 'critique')].filter(Boolean).join(' · ') || (RV.nc ? 'aucune majeure' : 'aucune infraction')) : (RV.motif || ''), cls: RV.lu ? ((RV.majeures || RV.critiques) ? 'ko' : (RV.mineures ? 'att' : 'ok')) : 'mu' }),
    TU('meteo', 'Météo du franchisé', MT2 && MT2.moral != null ? METEO_I[MT2.moral] + ' ' + MT2.moral + ' / 5' : '—', MT2 ? 'le ' + fmtD(MT2.le) + (mt.demandesOuvertes ? ' · ' + pl(mt.demandesOuvertes, 'demande') + ' ouverte' + (mt.demandesOuvertes > 1 ? 's' : '') : '') : (mt ? 'pas encore relevée' : 'lecture…'), MT2 ? mCls(MT2.moral) : 'mu',
      MT2 ? meteoHtml(MT2) : mu('Aucune météo relevée pour ce franchisé.'), { lien: () => app.setState({ ffOnglet: 'meteo' }), lienTxt: MT2 ? 'Nouvelle météo' : 'Relever la météo' })].filter(Boolean);
  f.journal = (d.journal || []).map(e => ({ le: fmtD(e.le), volet: e.volet, texte: e.texte }));
}

/* --- Remarques aux opérateurs ---------------------------------------------------------- */
export function roCharge(app, shop, periode, force){
  const b = app.state.ro; const cle = shop + '|' + periode;
  if (!shop) { return; }
  if (!force && b && b.cle === cle && (b.d || b.chargement)) { return; }
  if (app._roEnCours === cle && !force) { return; }
  app._roEnCours = cle;
  app.setState({ ro: { cle, chargement: true, d: b && b.cle === cle ? b.d : null } });
  const du = plusJours(auj(), -(+periode - 1));
  readOne('/equipe/remarques?shop=' + encodeURIComponent(shop) + '&du=' + du + '&au=' + auj()).then(d => { app._roEnCours = null; app.setState({ ro: { cle, chargement: false, d: d || { error: 'injoignable' } } }); });
}
export function valsRO(app, common){
  const S = app.state;
  const mags = app.open().map(s => ({ id: String(s.id), court: s.nom.split(' - ').pop() }));
  const shop = String(S.roShop || (mags[0] || {}).id || '');
  const periode = String(S.roPeriode || '90');
  roCharge(app, shop, periode, false);
  const b = S.ro || {}, d = b.d && !b.d.error ? b.d : null;
  const op = S.roOp || '';
  const r = { magasins: mags.map(m => ({ v: m.id, nom: m.court, on: m.id === shop })), setShop: e => app.setState({ roShop: e.target.value, roOp: '' }),
    periodes: [['30', '30 jours'], ['90', '90 jours'], ['365', '12 mois']].map(([v, nom]) => ({ v, nom, on: periode === v })), setPeriode: e => app.setState({ roPeriode: e.target.value }),
    operateurs: [], rafraichir: () => roCharge(app, shop, periode, true), chargement: !!b.chargement && !d, indispo: !b.chargement && b.d && !d ? 'Lecture impossible — API injoignable.' : '', kpis: [], lignes: [], parOperateur: [], titre: '', mini: '' };
  common.ro = r;
  if (!d) { return; }
  const tout = d.remarques || [];
  r.operateurs = [{ nom: 'Tous', on: op === '', n: tout.length, choisir: () => app.setState({ roOp: '' }) }].concat((d.parOperateur || []).map(o => ({ nom: o.employe, n: o.n, on: op === o.employe, choisir: () => app.setState({ roOp: o.employe }) })));
  const liste = tout.filter(x => !op || x.employe === op);
  const parMotif = {}; liste.forEach(x => { const k = x.motifLib || x.motif || '—'; parMotif[k] = (parMotif[k] || 0) + 1; });
  const motifs = Object.keys(parMotif).sort((a, c) => parMotif[c] - parMotif[a]);
  r.kpis = [['Remarques', String(liste.length), 'sur ' + periode + ' jours' + (op ? ' · ' + op : '')], ['Opérateurs concernés', String(new Set(liste.map(x => x.employe)).size), (d.parOperateur || []).slice(0, 3).map(o => o.employe + ' ' + o.n).join(' · ')],
    ['Pièces en cause', nf(liste.reduce((a, x) => a + (x.pieces || 0), 0)), 'jetées pour qualité ou casse'], ['Motif principal', motifs[0] || '—', motifs.slice(0, 3).map(m => m + ' ' + parMotif[m]).join(' · ')]];
  r.titre = 'Les remarques' + (op ? ' — ' + op : '') + ' · ' + ((mags.find(m => m.id === shop) || {}).court || shop);
  r.mini = 'du ' + fmtD(d.du || '') + ' au ' + fmtD(d.au || '') + ' · pour l’entretien d’évaluation';
  r.lignes = liste.map(x => ({ le: fmtD(x.le), heure: x.heure || '', employe: x.employe, produit: x.produit, pieces: x.pieces != null ? nf(x.pieces, x.pieces % 1 ? 1 : 0) : '—', motif: x.motifLib || x.motif || '', texte: x.texte || '', auteur: x.auteur || '' }));
  const max = Math.max(1, ...(d.parOperateur || []).map(o => o.n));
  r.parOperateur = (d.parOperateur || []).map(o => ({ nom: o.employe, pct: Math.round(100 * o.n / max), txt: pl(o.n, 'remarque') + ' · dernière ' + fmtD(o.derniere) }));
}
