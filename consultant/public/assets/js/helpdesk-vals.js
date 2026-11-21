/* Cockpit CEO — Franchisés › Demandes des franchisés (15/11/2026, maquettes docs/maquettes/helpdesk-franchises A et B).
 * Le helpdesk du panel, lu avec le compte administrateur du cockpit : les demandes, leurs filtres, la fiche d'une demande
 * (statut, consultant, réponse), la reprise dans une visite du magasin (une mission : tâches du consultant et points à
 * contrôler) et la mission elle-même (conforme, à suivre, non conforme, constat ; à la clôture, le constat repart en
 * réponse). Serveur : src/helpdesk.php. Gabarit : helpdesk-tpl.js. */
import { readOne, write } from './api.js';

const AUJ = () => new Date().toISOString().slice(0, 10);
const jours = d => d ? Math.max(0, Math.round((Date.parse(AUJ()) - Date.parse(String(d).slice(0, 10))) / 864e5)) : null;
const dm = d => d ? String(d).slice(8, 10) + '/' + String(d).slice(5, 7) + (String(d).slice(0, 4) !== AUJ().slice(0, 4) ? '/' + String(d).slice(2, 4) : '') : '';
const court = n => String(n || '').replace(/^Atelier by( - |\s+)/i, '').replace(/^.* - /, '');
const TONS = { NEW: 'ko', IN_PROGRESS: 'att', RESOLVED: 'ok', CLOSED: 'mu', CANCELLED: 'mu' };
const ETATS = [['conforme', 'Conforme'], ['a_suivre', 'À suivre'], ['non_conforme', 'Non conforme']];

function charger(app, frais){
  const H = app.state.hdData;
  if (!frais && H && (H.d || H.chargement)) { return; }
  app.setState({ hdData: { chargement: true, d: H ? H.d : null } });
  readOne('/helpdesk/demandes').then(d => app.setState({ hdData: { chargement: false, d: d || { error: 'injoignable' } } }));
}
/** Une écriture : `write` rend ok pour tout HTTP 200, l'échec se lit dans le corps (`ok: false`, `error`). */
function envoyer(app, path, corps, apres){
  app.setState({ hdEnvoi: { path, enCours: true } });
  return write(app.source || 'api', 'POST', path, corps).then(r => {
    const echec = !r || r.ok === false || r.error;
    app.setState({ hdEnvoi: { path, enCours: false, ok: !echec, msg: echec ? ((r && r.error) || 'refusé') : (r.avertissement || '') } });
    if (!echec) { if (apres) { apres(r); } charger(app, true); }
    return r;
  });
}

/* Le SMS au bureau (20/11/2026, src/helpdesk.php › hdkSmsBureau) : à chaque demande d'un franchisé, un SMS avec son
 * résumé part aux numéros réglés ici (Sam, Pierre Boseret). Lecture GET /helpdesk/sms, écriture PUT /helpdesk/sms. */
function chargerSms(app, frais){
  const M = app.state.hdSms;
  if (!frais && M && (M.d || M.chargement)) { return; }
  app.setState({ hdSms: { chargement: true, d: M ? M.d : null } });
  readOne('/helpdesk/sms').then(d => app.setState({ hdSms: { chargement: false, d: d || { error: 'réglage illisible' } } }));
}
const dhm = iso => { const t = iso ? new Date(iso) : null; return t && !isNaN(t) ? String(t.getDate()).padStart(2, '0') + '/' + String(t.getMonth() + 1).padStart(2, '0') + ' à ' + String(t.getHours()).padStart(2, '0') + ':' + String(t.getMinutes()).padStart(2, '0') : ''; };
function valsSms(app, Dd){
  chargerSms(app, false);
  const S = app.state, M = S.hdSms || {}, D = M.d && !M.d.error ? M.d : null;
  const ouvert = !!S.hdSmsOuvert;
  const v = { ouvert, basculer: () => app.setState({ hdSmsOuvert: !ouvert, hdSmsForm: null, hdSmsMsg: null }), chargement: !!M.chargement && !D, erreur: M.d && M.d.error ? M.d.error : null };
  if (!D) { return v; }
  const sans = l => l.filter(x => !String(x.tel || '').trim()).map(x => x.nom || '—');
  const Rl = D.relances || {};
  v.actif = !!D.actif; v.fournisseur = !!D.fournisseur;
  v.resume = (!D.actif ? 'nouvelles demandes : coupés' : (D.destinataires.length ? D.destinataires.map(x => (x.nom || x.lisible || x.tel) + (x.tel ? '' : ' (numéro à compléter)')).join(' · ') : 'aucun destinataire'))
    + ' · ' + (Rl.actif ? 'relances tous les ' + Rl.jours + ' jours' : 'relances coupées');
  v.alerte = (D.actif || Rl.actif) && (!D.fournisseur || sans(D.destinataires).length > 0);
  const Dn = D.dernier, Dr = D.derniereRelance;
  v.dernier = (Dn ? 'Dernière nouvelle demande le ' + dhm(Dn.le) + ' (' + (Dn.magasin || '') + (Dn.titre ? ', « ' + Dn.titre + ' »' : '') + ') : '
    + ((Dn.destinataires || []).map(x => x.nom + (x.ok ? ' ✓' : ' ✕ ' + (x.motif || ''))).join(' · ') || 'aucun destinataire') + '.' : 'Aucun SMS de nouvelle demande pour l’instant.')
    + (Dr ? ' Dernière relance le ' + dhm(Dr.le.replace(' ', 'T')) + ' (' + Dr.demandes + ' demande' + (Dr.demandes > 1 ? 's' : '') + ') : ' + Dr.a.map(x => x.nom + (x.ok ? ' ✓' : ' ✕')).join(' · ') + ' ; ' + Dr.sms + ' SMS de relance' + (D.relancesDepuis ? ' depuis le ' + dm(D.relancesDepuis) : '') + '.' : '');
  if (!ouvert) { return v; }
  const F = S.hdSmsForm || { actif: !!D.actif, destinataires: (D.destinataires || []).map(x => ({ nom: x.nom || '', tel: x.tel || '', consultant: x.consultant || '' })), relances: Object.assign({}, Rl) };
  const maj = p => app.setState({ hdSmsForm: Object.assign({}, F, p), hdSmsMsg: null });
  const majL = (i, p) => { const l = F.destinataires.slice(); l[i] = Object.assign({}, l[i], p); maj({ destinataires: l }); };
  const majR = p => maj({ relances: Object.assign({}, F.relances, p) });
  const E = S.hdSmsMsg || {};
  // Les consultants du panel, tels que les demandes les nomment : le numéro de chacun reçoit les relances des siennes.
  const noms = Array.from(new Set((((Dd || {}).demandes) || []).map(x => x.consultant).filter(Boolean).concat(F.destinataires.map(x => x.consultant).filter(Boolean)))).sort();
  const seg = (cle, opts) => opts.map(([val, nom]) => ({ nom, on: F.relances[cle] === val, choisir: () => majR({ [cle]: val }) }));
  const AV = (((Dd || {}).relances) || {}).aVenir || [];
  const jourCourt = j => ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'][new Date(j + 'T12:00').getDay()] + ' ' + dm(j);
  Object.assign(v, {
    fActif: !!F.actif, allumer: () => maj({ actif: true }), couper: () => maj({ actif: false }),
    lignes: F.destinataires.map((x, i) => ({ nom: x.nom, tel: x.tel, ko: !String(x.tel || '').trim(), setNom: e => majL(i, { nom: e.target.value }), setTel: e => majL(i, { tel: e.target.value }),
      consultants: [{ id: '', nom: 'par le nom' }].concat(noms.map(n => ({ id: n, nom: n }))).map(o => Object.assign(o, { on: o.id === (x.consultant || '') })),
      setConsultant: e => majL(i, { consultant: e.target.value }),
      essai: String(x.tel || '').trim() && !E.enCours ? () => {
        app.setState({ hdSmsMsg: { enCours: true } });
        write(app.source || 'api', 'POST', '/helpdesk/sms/essai', { tel: x.tel }).then(r => app.setState({ hdSmsMsg: !r || r.ok === false || r.error
          ? { ok: false, msg: (r && r.error) || 'refusé' } : { ok: true, msg: 'Essai parti à ' + (x.nom || r.a) + ' (' + r.a + ').' } }));
      } : null,
      retirer: () => maj({ destinataires: F.destinataires.filter((_, j) => j !== i) }) })),
    ajouter: F.destinataires.length < 10 ? () => maj({ destinataires: F.destinataires.concat([{ nom: '', tel: '', consultant: '' }]) }) : null,
    // Les relances (20/11/2026, maquette docs/maquettes/helpdesk-relances, validée).
    rActif: seg('actif', [[true, 'envoyées'], [false, 'coupées']]), rJours: seg('jours', [[3, '3 j'], [7, '7 j'], [14, '14 j']]),
    rHeure: F.relances.heure || '08:30', setHeure: e => majR({ heure: e.target.value }), rOuvres: !!F.relances.ouvres, basculerOuvres: () => majR({ ouvres: !F.relances.ouvres }),
    rQuelles: seg('quelles', [['nouvelles', 'pas encore en cours'], ['sans_reponse', 'sans réponse du bureau'], ['retard', 'date limite dépassée']]),
    rAQui: seg('aQui', [['consultant', 'le consultant de la demande'], ['tous', 'tous les numéros']]),
    rRegrouper: seg('regrouper', [[true, 'un SMS qui les liste'], [false, 'un SMS par demande']]),
    rPlafond: seg('plafond', [[0, 'la réponse'], [6, '6 relances au plus']]),
    apercu: AV.length ? { titre: 'la relance de ' + AV[0].a + ', ' + jourCourt(AV[0].jour), texte: AV[0].texte } : null,
    prochaines: AV.map(x => ({ quand: jourCourt(x.jour) + ' · ' + x.heure, a: x.a, quoi: x.demandes.map(d => d.magasin + ' · ' + d.titre).join(' ; '),
      sous: x.demandes.length === 1 ? (x.demandes[0].rang === 1 ? '1re relance' : x.demandes[0].rang + 'e relance') + (x.demandes[0].consultant ? '' : ', pas de consultant') : x.demandes.length + ' demandes en un SMS' })),
    sansProchaine: !(((Dd || {}).relances) || {}).actif ? 'Relances coupées.' : 'Aucune relance prévue : aucune demande n’attend.',
    enCours: !!E.enCours, msg: E.msg || null, msgOk: !!E.ok,
    enregistrer: E.enCours ? null : () => {
      app.setState({ hdSmsMsg: { enCours: true } });
      write(app.source || 'api', 'PUT', '/helpdesk/sms', { actif: !!F.actif, destinataires: F.destinataires.filter(x => String(x.nom || '').trim() || String(x.tel || '').trim()), relances: F.relances }).then(r => {
        const echec = !r || r.ok === false || r.error;
        if (echec) { app.setState({ hdSmsMsg: { ok: false, msg: (r && r.error) || 'refusé' } }); return; }
        app.setState({ hdSms: { chargement: false, d: r }, hdSmsForm: null, hdSmsMsg: { ok: true, msg: 'Enregistré.' } });
        charger(app, true);   // les prochaines relances suivent le réglage
      });
    },
  });
  return v;
}

/* Les réclamations fournisseur de tous les magasins (20/11/2026, demande de Sam : « dans le cockpit, mettre la vue sur
 * toutes les réclamations de tous les magasins », ici). Lecture seule : GET /fournisseurs/reclamations?mois= (le panel,
 * /consultant/shops/material-complaints, src/mesure.php) ; répondre passe par le fournisseur, pas par le cockpit. */
const RC_STATUTS = { ACCEPTED: 'Acceptée', REJECTED: 'Refusée', PENDING: 'En attente', NEW: 'Nouvelle', OPEN: 'Ouverte', IN_PROGRESS: 'En cours', RESOLVED: 'Réglée', CLOSED: 'Close' };
const eur = n => n == null || isNaN(n) ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
const nb = n => n == null ? '' : Number(n).toLocaleString('fr-BE', { maximumFractionDigits: 2 });
function chargerRc(app, frais, mois){
  const R = app.state.hdRc;
  const m = mois != null ? mois : (R && R.mois != null ? R.mois : 3);
  if (!frais && R && R.mois === m && (R.d || R.chargement)) { return; }
  app.setState({ hdRc: { chargement: true, d: R && R.mois === m ? R.d : null, mois: m } });
  readOne('/fournisseurs/reclamations?mois=' + m).then(d => { if ((app.state.hdRc || {}).mois === m) { app.setState({ hdRc: { chargement: false, d: d || { indispo: true, motif: 'le cockpit n’a pas répondu' }, mois: m } }); } });
}
const rcEtat = l => l.statut === 'REJECTED' ? 'refusee' : (l.ouverte ? 'ouverte' : 'reglee');
function valsReclamations(app){
  chargerRc(app, false);
  const S = app.state, R = S.hdRc || {}, D = R.d && !R.d.indispo && !R.d.error ? R.d : null;
  const mois = R.mois != null ? R.mois : 3;
  const v = { chargement: !!R.chargement && !D, erreur: R.d && (R.d.indispo || R.d.error) ? 'Les réclamations n’ont pas pu être lues : ' + (R.d.motif || R.d.error) + '.' : null,
    relire: () => chargerRc(app, true), relecture: !!R.chargement && !!D,
    periodes: [[3, '3 derniers mois'], [12, '12 derniers mois'], [0, 'tout l’historique']].map(([m, nom]) => ({ id: String(m), nom, on: mois === m })),
    poserPeriode: e => chargerRc(app, false, Number(e.target.value)) };
  if (!D) { return v; }
  const tous = D.lignes || [];
  const f = S.hdRcFiltre || 'toutes', q = String(S.hdRcQ || '').trim().toLowerCase(), fm = S.hdRcMag || '', ff = S.hdRcFour || '';
  const passe = l => (f === 'toutes' || rcEtat(l) === f) && (!fm || l.shopId === fm) && (!ff || (l.fournisseur || 'Fournisseur inconnu') === ff)
    && (!q || [l.reference, l.sku, l.fournisseur, l.magasin, l.motif, l.texte, l.reponse, l.cle].join(' ').toLowerCase().indexOf(q) >= 0);
  const L = tous.filter(passe);
  const ouvertes = tous.filter(l => l.ouverte && l.statut !== 'REJECTED');
  const vieille = ouvertes.filter(l => l.age != null).sort((a, b) => b.age - a.age)[0];
  v.kpis = [['Ouvertes', ouvertes.length, 'sans réponse du fournisseur', ouvertes.length ? 'att' : 'ok'],
    ['Montant ouvert', eur(D.montantOuvert), 'au prix d’achat' + (D.montantComplet === false ? ' (prix incomplets)' : ''), 'ko'],
    ['Plus ancienne', vieille ? vieille.age + ' j' : '—', vieille ? court(vieille.magasin) + ', le ' + dm(vieille.le) : 'aucune ouverte', 'ko'],
    ['Réglées', tous.filter(l => rcEtat(l) === 'reglee').length, 'réponse du fournisseur', 'ok'], ['Refusées', tous.filter(l => rcEtat(l) === 'refusee').length, 'par le fournisseur', 'mu'],
    ['Magasins', new Set(tous.map(l => l.shopId)).size, tous.length + ' réclamation' + (tous.length > 1 ? 's' : '') + ' · ' + (D.fenetre || ''), '']];
  v.chips = [['toutes', 'Toutes', tous.length], ['ouverte', 'Ouvertes', ouvertes.length], ['reglee', 'Réglées', tous.filter(l => rcEtat(l) === 'reglee').length], ['refusee', 'Refusées', tous.filter(l => rcEtat(l) === 'refusee').length]]
    .map(([k, n, c]) => ({ nom: n, n: c, on: f === k, choisir: () => app.setState({ hdRcFiltre: k }) }));
  const mags = {}; tous.forEach(l => { mags[l.shopId] = court(l.magasin); });
  v.magasins = [{ id: '', nom: 'Tous les magasins' }].concat(Object.entries(mags).sort((a, b) => a[1].localeCompare(b[1])).map(([id, nom]) => ({ id, nom: nom + ' (' + tous.filter(l => l.shopId === id).length + ')' }))).map(o => Object.assign(o, { on: o.id === fm }));
  v.poserMag = e => app.setState({ hdRcMag: e.target.value });
  const fours = {}; tous.forEach(l => { const k = l.fournisseur || 'Fournisseur inconnu'; fours[k] = (fours[k] || 0) + 1; });
  v.fournisseurs = [{ id: '', nom: 'Tous les fournisseurs' }].concat(Object.keys(fours).sort().map(k => ({ id: k, nom: k + ' (' + fours[k] + ')' }))).map(o => Object.assign(o, { on: o.id === ff }));
  v.poserFour = e => app.setState({ hdRcFour: e.target.value });
  v.q = S.hdRcQ || ''; v.poserQ = e => app.setState({ hdRcQ: e.target.value });
  const ton = l => ({ ouverte: 'att', reglee: 'ok', refusee: 'mu' })[rcEtat(l)];
  const sel = L.some(l => l.id === S.hdRcSel) ? S.hdRcSel : (L[0] || {}).id;
  v.lignes = L.map(l => ({ le: dm(l.le), age: l.age != null ? l.age + ' j' : '', vieux: l.ouverte && l.age > 30, magasin: court(l.magasin), fournisseur: l.fournisseur || '—', reference: l.reference || '—', sku: l.sku,
    qte: l.qte != null ? nb(l.qte) + (l.unite ? ' ' + l.unite : '') : '—', motif: l.motif || '—', extrait: l.texte.length > 90 ? l.texte.slice(0, 90) + '…' : l.texte,
    statut: rcEtat(l) === 'ouverte' ? 'Ouverte' : (RC_STATUTS[l.statut] || l.statut || '—'), sousStatut: rcEtat(l) === 'ouverte' && RC_STATUTS[l.statut] ? RC_STATUTS[l.statut].toLowerCase() : '',
    ton: ton(l), reponse: !!l.reponse, reponseLe: dm(l.reponseLe), montant: eur(l.montant),
    on: l.id === sel, choisir: () => app.setState({ hdRcSel: l.id }) }));
  v.n = L.length; v.total = tous.length; v.source = D.source; v.lecture = D.lecture; v.ecartees = D.ecartees || 0;
  const X = tous.find(l => l.id === sel);
  v.fiche = X ? { ref: X.cle || ('#' + X.id), titre: (X.reference || 'Réclamation') + (X.fournisseur ? ' · ' + X.fournisseur : ''), sous: court(X.magasin) + ' · le ' + dm(X.le) + (X.age != null ? ' (' + X.age + ' j)' : ''),
    statut: v.lignes.find(l => l.on).statut, ton: ton(X), texte: X.texte, reponse: X.reponse, reponseLe: dm(X.reponseLe),
    champs: [['Référence', X.reference + (X.sku ? ' · SKU ' + X.sku : '')], ['Quantité', X.qte != null ? nb(X.qte) + (X.unite ? ' ' + X.unite : '') : ''], ['Motif', X.motif],
      ['Ce qui est demandé', X.action], ['Commande', X.commande], ['Montant', X.montant != null ? eur(X.montant) + (X.prixUnitaire != null ? ' (' + eur(X.prixUnitaire) + ' / unité)' : '') : ''],
      ['Quantité acceptée', X.qteAcceptee != null ? nb(X.qteAcceptee) : ''], ['Compensation', X.compensation], ['Pièces jointes', X.pj ? String(X.pj) : '']].filter(([, val]) => val) } : null;
  return v;
}

/* La carte « Demandes à traiter » de l'accueil (21/11/2026, demande de Sam : les demandes pas encore en cours « se
 * mettent dans la liste de suivi tous les jours » — choix : l'accueil du cockpit, l'écran Résultat). Relue à chaque
 * ouverture du cockpit et chaque nouveau jour. Serveur : GET /helpdesk/a-traiter. */
function chargerAT(app, frais){
  const A = app.state.hdAT, jour = AUJ();
  if (!frais && A && A.jour === jour && (A.d || A.chargement)) { return; }
  app.setState({ hdAT: { chargement: true, d: A ? A.d : null, jour } });
  readOne('/helpdesk/a-traiter').then(d => app.setState({ hdAT: { chargement: false, d: d || { indispo: true, motif: 'le cockpit n’a pas répondu' }, jour } }));
}
export function valsATraiter(app, c){
  chargerAT(app, false);
  const A = app.state.hdAT || {}, D = A.d && !A.d.indispo && !A.d.error ? A.d : null;
  const ouvrir = () => app.setState({ screen: 'demandesFranchises', hdVue: 'demandes', hdFiltre: 'NEW' });
  c.hdAT = { chargement: !!A.chargement && !D, erreur: A.d && (A.d.indispo || A.d.error) ? (A.d.motif || A.d.error) : null, ouvrir, relire: () => chargerAT(app, true) };
  if (!D) { return; }
  const L = D.demandes || [];
  Object.assign(c.hdAT, { n: D.n, plus: Math.max(0, L.length - 5), lignes: L.slice(0, 5).map(d => ({ age: d.age + ' j', vieux: d.age > 7, magasin: d.magasin, titre: d.titre,
    sous: d.domaine + ' · envoyée le ' + dm(d.cree) + (d.consultant ? ' · ' + d.consultant : ' · sans consultant') + (d.relances ? ' · ' + d.relances + ' relance' + (d.relances > 1 ? 's' : '') + ' SMS' : ''),
    ouvrir: () => app.setState({ screen: 'demandesFranchises', hdVue: 'demandes', hdFiltre: 'NEW', hdSel: d.id }) })) });
}

export function nbDemandesOuvertes(app){
  const d = ((app.state.hdData || {}).d || {}).demandes || [];
  return d.filter(x => x.ouverte && !x.reponse).length;
}

/* La date limite (20/11/2026) : fixée par le consultant à la réception (2, 5, 10, 30 ou 90 jours), à respecter. */
function echeance(x){
  if (!x.echeance) { return { ech: x.ouverte ? 'à fixer' : '', echSous: '', echTon: x.ouverte ? 'att' : '' }; }
  const r = x.joursRestants;
  if (!x.ouverte) { return { ech: dm(x.echeance), echSous: 'date limite', echTon: '' }; }
  return { ech: x.enRetard ? 'en retard de ' + (-r) + ' j' : (r === 0 ? 'aujourd’hui' : 'dans ' + r + ' j'), echSous: 'avant le ' + dm(x.echeance), echTon: x.enRetard ? 'ko' : (r <= 1 ? 'att' : '') };
}

/* Les relances par SMS d'une demande (20/11/2026) : la colonne « Relance » et le bloc de la fiche. */
const JOURS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
const jourCourt = j => j ? JOURS[new Date(String(j).slice(0, 10) + 'T12:00').getDay()] + ' ' + dm(String(j).slice(0, 10)) : '';
function relance(x){
  const R = x.relances;
  if (!R) { return { rel: '', relSous: '', relTon: '' }; }
  const env = R.n ? R.n + ' envoyée' + (R.n > 1 ? 's' : '') : '';
  if (R.suspendue) { return { rel: 'suspendue', relSous: 'par ' + (R.suspendue.par || '—') + ' le ' + dm(String(R.suspendue.le).slice(0, 10)), relTon: 'mu' }; }
  if (R.arret) { return R.n ? { rel: 'arrêtée', relSous: R.arret, relTon: /^réponse/.test(R.arret) ? 'ok' : 'mu' } : { rel: '', relSous: '', relTon: '' }; }
  if (!R.prochaine) { return { rel: '', relSous: '', relTon: '' }; }
  return { rel: jourCourt(R.prochaine), relSous: (env ? env : '1re') + (R.a.length ? ' · ' + R.a.map(n => n.split(' ')[0]).join(', ') : ' · aucun numéro'), relTon: R.n ? 'att' : '' };
}

export function valsHelpdesk(app, c){
  const S = app.state;
  charger(app, false);
  chargerRc(app, false);   // le compte de l'onglet des réclamations
  const H = S.hdData || {};
  const D = H.d && !H.d.error && !H.d.indispo ? H.d : null;
  const vue = S.hdVue === 'reclamations' ? 'reclamations' : 'demandes';
  const v = { vue, chargement: !!H.chargement && !D, erreur: H.d && (H.d.error || H.d.indispo) ? (H.d.motif || H.d.error) : null, relire: () => charger(app, true), relecture: !!H.chargement && !!D };
  c.hd = v;
  // Deux onglets (20/11/2026) : les demandes au bureau, les réclamations fournisseur de tous les magasins.
  const RcD = (S.hdRc || {}).d;
  v.onglets = [['demandes', 'Demandes au bureau', D ? (D.demandes || []).filter(x => x.ouverte).length + ' ouvertes' : ''],
    ['reclamations', 'Réclamations fournisseur', RcD && !RcD.indispo && !RcD.error ? (RcD.ouvertes || 0) + ' ouvertes · tous les magasins' : '']]
    .map(([k, nom, sous]) => ({ nom, sous, on: vue === k, choisir: () => app.setState({ hdVue: k }) }));
  if (vue === 'reclamations') { v.rc = valsReclamations(app); return; }
  if (!D) { return; }
  v.sms = valsSms(app, D);
  const tous = D.demandes || [];
  const f = S.hdFiltre || 'ouvertes', q = String(S.hdQ || '').trim().toLowerCase(), fd = S.hdDom || '', fm = S.hdMag || '';
  const ouvertes = tous.filter(x => x.ouverte);
  const passe = x => (f === 'toutes' || (f === 'ouvertes' && x.ouverte) || (f === 'sansrep' && x.ouverte && !x.reponse) || (f === 'mission' && (x.missions || []).length)
      || (f === 'retard' && x.enRetard) || (f === 'sansdelai' && x.ouverte && !x.echeance) || x.statut === f)
    && (!fd || x.domaine === fd) && (!fm || x.shop === fm) && (!q || (x.titre + ' ' + x.description + ' ' + x.ref + ' ' + x.magasin).toLowerCase().indexOf(q) >= 0);
  const L = tous.filter(passe);
  const plusVieille = ouvertes.slice().sort((a, b) => a.cree.localeCompare(b.cree))[0];
  const retard = ouvertes.filter(x => x.enRetard).length, sansDelai = ouvertes.filter(x => !x.echeance).length;
  v.kpis = [['Ouvertes', ouvertes.length, 'nouvelles ou en cours', 'att'], ['Sans réponse', ouvertes.filter(x => !x.reponse).length, 'aucune réponse du bureau', 'ko'],
    ['En retard', retard, 'date limite dépassée' + (sansDelai ? ' · ' + sansDelai + ' sans date' : ''), retard ? 'ko' : 'ok'],
    ['Plus ancienne', plusVieille ? jours(plusVieille.cree) + ' j' : '—', plusVieille ? 'ouverte depuis le ' + dm(plusVieille.cree) : 'aucune ouverte', 'ko'],
    D.relances && !D.relances.erreur ? ['Relances', D.relances.actif ? D.relances.sms : 'coupées', D.relances.actif ? 'SMS depuis le ' + dm(D.relances.depuis) + (D.relances.arretees ? ' · ' + D.relances.arretees + ' arrêtée' + (D.relances.arretees > 1 ? 's' : '') + ' par une réponse' : '') : 'à rallumer dans « SMS au bureau »', '']
      : ['Résolues', tous.filter(x => x.statut === 'RESOLVED').length, 'sur ' + tous.length + ' demandes', 'ok'], ['Dans une visite', tous.filter(x => (x.missions || []).some(m => !m.cloture)).length, 'missions en cours', '']];
  v.chips = [['ouvertes', 'Ouvertes', ouvertes.length], ['NEW', 'Nouvelles', tous.filter(x => x.statut === 'NEW').length], ['IN_PROGRESS', 'En cours', tous.filter(x => x.statut === 'IN_PROGRESS').length],
    ['sansrep', 'Sans réponse', ouvertes.filter(x => !x.reponse).length], ['retard', 'En retard', retard], ['sansdelai', 'Sans date limite', sansDelai], ['mission', 'En visite', tous.filter(x => (x.missions || []).length).length], ['RESOLVED', 'Résolues', tous.filter(x => x.statut === 'RESOLVED').length], ['toutes', 'Toutes', tous.length]]
    .map(([k, n, nb]) => ({ nom: n, n: nb, on: f === k, choisir: () => app.setState({ hdFiltre: k }) }));
  v.domaines = [{ id: '', nom: 'Tous les domaines' }].concat(Object.entries(D.domaines || {}).map(([id, nom]) => ({ id, nom: nom + ' (' + tous.filter(x => x.domaine === id).length + ')' }))).map(o => Object.assign(o, { on: o.id === fd }));
  v.poserDom = e => app.setState({ hdDom: e.target.value });
  const mags = {}; tous.forEach(x => { if (x.shop) { mags[x.shop] = court(x.magasin); } });
  v.magasins = [{ id: '', nom: 'Tous les magasins' }].concat(Object.entries(mags).map(([id, nom]) => ({ id, nom: nom + ' (' + tous.filter(x => x.shop === id).length + ')' }))).map(o => Object.assign(o, { on: o.id === fm }));
  v.poserMag = e => app.setState({ hdMag: e.target.value });
  v.q = S.hdQ || ''; v.poserQ = e => app.setState({ hdQ: e.target.value });
  const sel = L.some(x => x.id === S.hdSel) ? S.hdSel : (L[0] || {}).id;
  v.lignes = L.map(x => ({ id: x.id, ref: x.ref.replace('CASE-', ''), le: dm(x.cree), magasin: court(x.magasin), domaine: x.domaineNom, titre: x.titre, extrait: x.description.length > 110 ? x.description.slice(0, 110) + '…' : x.description,
    consultant: x.consultant || '—', statut: x.statutNom, ton: TONS[x.statut] || '', rep: x.reponse ? 'répondue' : (x.ouverte ? 'sans réponse' : ''), repKo: !x.reponse && x.ouverte,
    age: jours(x.cree) + ' j', vieux: x.ouverte && jours(x.cree) > 60, ...echeance(x), ...relance(x), visite: (x.missions || []).filter(m => !m.cloture).map(m => 'visite ' + dm(m.visiteLe))[0] || '',
    on: x.id === sel, choisir: () => app.setState({ hdSel: x.id, hdEnvoi: null }) }));
  v.n = L.length; v.total = tous.length; v.compte = D.compte; v.source = D.source;
  const X = tous.find(x => x.id === sel);
  if (!X) { return; }
  // La fiche
  const F = (S.hdForm && S.hdForm.id === X.id) ? S.hdForm : { id: X.id };
  const majF = p => app.setState({ hdForm: Object.assign({ id: X.id }, (app.state.hdForm && app.state.hdForm.id === X.id) ? app.state.hdForm : {}, p) });
  const E = S.hdEnvoi || {};
  const cons = (S.hdCons || {})[X.id];
  if (!cons) {
    app.setState({ hdCons: Object.assign({}, S.hdCons, { [X.id]: { chargement: true } }) });
    readOne('/helpdesk/demandes/' + X.id + '/consultants').then(r => app.setState({ hdCons: Object.assign({}, app.state.hdCons, { [X.id]: r || { consultants: [] } }) }));
  }
  const visites = (D.visites || {})[X.shop] || [];
  const visite = F.visite != null ? F.visite : (visites[0] || {}).id;
  v.fiche = {
    id: X.id, ref: X.ref, titre: X.titre, sous: court(X.magasin) + ' · ' + X.domaineNom + ' · créée le ' + dm(X.cree) + ' (' + jours(X.cree) + ' j)' + (X.demandeur ? ' · ' + X.demandeur : ''),
    statut: X.statutNom, ton: TONS[X.statut] || '', description: X.description, reponse: X.reponse, reponseLe: dm(X.reponseLe),
    statuts: Object.entries(D.statuts || {}).map(([id, nom]) => ({ id, nom, on: id === X.statut })),
    // La date limite : un délai à la réception ; une demande « Nouvelle » passe alors « En cours ».
    delais: (D.delais || [2, 5, 10, 30, 90]).map(j => ({ nom: j + ' j', on: X.delai === j && !!X.echeance,
      choisir: E.enCours ? null : () => envoyer(app, '/helpdesk/demandes/' + X.id + '/delai', { jours: j, statut: X.statut, shop: X.shop, ref: X.ref }) })),
    retirerDelai: X.echeance && !E.enCours ? () => envoyer(app, '/helpdesk/demandes/' + X.id + '/delai', { jours: 0, ref: X.ref }) : null,
    delaiTon: X.enRetard ? 'ko' : (X.echeance ? '' : (X.ouverte ? 'att' : '')),
    // Les relances par SMS : les envois, la prochaine, suspendre, reprendre, relancer maintenant.
    relances: X.relances ? (() => {
      const R = X.relances;
      const act = action => () => envoyer(app, '/helpdesk/demandes/' + X.id + '/relances', { action, shop: X.shop, ref: X.ref });
      return { envois: R.envois.map(e => ({ quand: jourCourt(e.le) + ' · ' + String(e.le).slice(11, 16) + (e.manuel ? ' (à la main)' : ''), a: e.a.map(a => ({ nom: a.nom, ok: a.ok, motif: a.motif || '' })) })),
        etat: R.suspendue ? 'Suspendues par ' + (R.suspendue.par || '—') + ' le ' + dm(String(R.suspendue.le).slice(0, 10)) + '.'
          : (R.arret ? (R.arret === 'relances coupées' ? 'Relances coupées dans « SMS au bureau ».' : 'Arrêtées : ' + R.arret + '.')
            : (R.prochaine ? 'Prochaine : ' + jourCourt(R.prochaine) + ' à ' + R.heure + ', à ' + (R.a.length ? R.a.join(' et ') : 'personne (aucun numéro valable)') + (!X.consultant ? ' — aucun consultant attribué ; attribuer un consultant envoie les suivantes à lui seul.' : '.') : '')),
        suspendre: !R.suspendue && X.ouverte && !E.enCours ? act('suspendre') : null, reprendre: R.suspendue && !E.enCours ? act('reprendre') : null,
        maintenant: X.ouverte && !E.enCours ? act('maintenant') : null };
    })() : null,
    delaiNote: X.echeance ? (X.ouverte ? (X.enRetard ? 'Dépassée de ' + (-X.joursRestants) + ' j' : (X.joursRestants === 0 ? 'Aujourd’hui' : 'Dans ' + X.joursRestants + ' j')) + ' : à respecter avant le ' : 'Était fixée au ') + dm(X.echeance)
        + (X.echeancePar ? ' · fixée par ' + X.echeancePar + (X.echeanceLe ? ' le ' + dm(X.echeanceLe) : '') : '') + '. Le franchisé voit « réponse prévue d’ici le ' + dm(X.echeance) + ' ».'
      : (X.ouverte ? 'À fixer à la réception : le délai que vous tiendrez' + (X.statut === 'NEW' ? ' (la demande passe « En cours »)' : '') + '.' : 'Aucune date limite.'),
    poserStatut: e => { if (e.target.value !== X.statut) { envoyer(app, '/helpdesk/demandes/' + X.id + '/statut', { statut: e.target.value }); } },
    consultants: cons && cons.consultants ? [{ id: '', nom: X.consultant ? X.consultant + ' (actuel)' : '— choisir —' }].concat(cons.consultants.map(k => ({ id: String(k.id), nom: k.nom }))) : [{ id: '', nom: cons && cons.chargement ? 'lecture…' : (X.consultant || '—') }],
    poserConsultant: e => { if (e.target.value) { envoyer(app, '/helpdesk/demandes/' + X.id + '/consultant', { consultant: Number(e.target.value) }); } },
    consNote: cons && cons.consultants && !cons.consultants.length ? 'le panel ne rend aucun consultant éligible' + (cons.erreur ? ' (' + cons.erreur + ')' : '') : 'ceux du domaine « ' + X.domaineNom + ' »',
    // Reprendre dans une visite
    visites: visites.map(w => ({ id: w.id, nom: (w.type === 'reguliere' ? 'Régulière' : w.type) + ' · ' + court(X.magasin) + ' · ' + dm(w.le) + ' · ' + (w.consultant || 'sans consultant') + (w.statut === 'en_cours' ? ' (en cours)' : ''), on: w.id === visite })),
    poserVisite: e => majF({ visite: Number(e.target.value) }),
    quand: ['avant', 'pendant', 'apres'].map(k => ({ k, nom: k === 'apres' ? 'après' : k, on: (F.quand || 'pendant') === k, choisir: () => majF({ quand: k }) })),
    points: F.points != null ? F.points : '', poserPoints: e => majF({ points: e.target.value }),
    ajouter: visite && !(E.enCours) ? () => {
      const pts = String(F.points || '').split(/\n+/).map(s => s.replace(/^[•\-\s]+/, '').trim()).filter(Boolean);
      if (!pts.length) { app.setState({ hdEnvoi: { path: 'visite', ok: false, msg: 'Dites ce qu’il faut vérifier sur place : un point par ligne.' } }); return; }
      envoyer(app, '/helpdesk/demandes/' + X.id + '/visite', { visite, quand: F.quand || 'pendant', points: pts, ref: X.ref, titre: X.titre }, () => majF({ points: '' }));
    } : null,
    sansVisite: !visites.length,
    // Répondre
    rep: F.rep != null ? F.rep : '', poserRep: e => majF({ rep: e.target.value }),
    repStatuts: [['', 'statut inchangé'], ['IN_PROGRESS', 'passer « En cours »'], ['RESOLVED', 'passer « Résolue »']].map(([id, nom]) => ({ id, nom, on: (F.repStatut != null ? F.repStatut : 'IN_PROGRESS') === id })),
    poserRepStatut: e => majF({ repStatut: e.target.value }),
    repondre: String(F.rep || '').trim() && !E.enCours ? () => envoyer(app, '/helpdesk/demandes/' + X.id + '/repondre', { reponse: F.rep, statut: F.repStatut != null ? F.repStatut : 'IN_PROGRESS' }, () => majF({ rep: '' })) : null,
    envoi: E.path ? { enCours: !!E.enCours, ok: E.ok, msg: E.msg } : null,
    // Les missions de la demande
    missions: (X.missions || []).map(m => {
      const P = (S.hdMis || {})[m.id] || {};
      const pts = (m.points || []).map((p, i) => Object.assign({}, p, P[i] || {}));
      const maj = (i, patch) => { const cur = Object.assign({}, (app.state.hdMis || {})[m.id]); cur[i] = Object.assign({}, cur[i], patch); app.setState({ hdMis: Object.assign({}, app.state.hdMis, { [m.id]: cur }) }); };
      const corps = () => ({ points: pts.map(p => ({ etat: p.etat || null, constat: p.constat || '' })) });
      return { id: m.id, visite: 'Visite du ' + dm(m.visiteLe) + (m.consultant ? ' · ' + m.consultant : '') + (m.visiteStatut ? ' · ' + m.visiteStatut.replace('_', ' ') : ''), quand: m.quand, cloture: m.cloture ? dm(m.cloture) : null, reponse: m.reponse,
        points: pts.map((p, i) => ({ nom: p.nom, constat: p.constat || '', id: 'hd-c-' + m.id + '-' + i, poser: e => maj(i, { constat: e.target.value }),
          etats: ETATS.map(([k, n]) => ({ k, nom: n, on: p.etat === k, choisir: m.cloture ? null : () => maj(i, { etat: p.etat === k ? null : k }) })) })),
        repondre: P.repondre !== false, basculerRepondre: () => app.setState({ hdMis: Object.assign({}, app.state.hdMis, { [m.id]: Object.assign({}, (app.state.hdMis || {})[m.id], { repondre: P.repondre === false }) }) }),
        enregistrer: m.cloture ? null : () => envoyer(app, '/helpdesk/missions/' + m.id, corps()),
        cloturer: m.cloture || pts.some(p => !p.etat) ? null : () => envoyer(app, '/helpdesk/missions/' + m.id, Object.assign(corps(), { cloturer: true, repondre: P.repondre !== false }), () => app.setState({ hdMis: Object.assign({}, app.state.hdMis, { [m.id]: null }) })),
        manque: pts.filter(p => !p.etat).length };
    }),
  };
}
