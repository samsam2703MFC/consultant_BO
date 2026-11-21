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
  const F = S.hdSmsForm || { actif: !!D.actif, franchise: D.franchise !== false, destinataires: (D.destinataires || []).map(x => ({ nom: x.nom || '', tel: x.tel || '', consultant: x.consultant || '' })), relances: Object.assign({}, Rl) };
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
    fFranchise: F.franchise !== false, franchiseOui: () => maj({ franchise: true }), franchiseNon: () => maj({ franchise: false }),
    lignes: F.destinataires.map((x, i) => ({ nom: x.nom, tel: x.tel, ko: !String(x.tel || '').trim(), setNom: e => majL(i, { nom: e.target.value }), setTel: e => majL(i, { tel: e.target.value }),
      consultants: [{ id: '', nom: 'par le nom' }].concat(noms.map(n => ({ id: n, nom: n }))).map(o => Object.assign(o, { on: o.id === (x.consultant || '') })),
      setConsultant: e => majL(i, { consultant: e.target.value }),
      // L'essai dit d'où le SMS est parti, puis sa remise chez SMSAPI (21/11/2026 : « SMS parti » au cockpit, rien reçu).
      essai: String(x.tel || '').trim() && !E.enCours ? () => {
        app.setState({ hdSmsMsg: { enCours: true } });
        write(app.source || 'api', 'POST', '/helpdesk/sms/essai', { tel: x.tel }).then(r => {
          if (!r || r.ok === false || r.error) { app.setState({ hdSmsMsg: { ok: false, msg: (r && r.error) || 'refusé' } }); return; }
          const D0 = r.details;
          const tete = 'Accepté par SMSAPI pour ' + (x.nom || r.a) + ' (' + r.a + ')' + (D0 ? ' — jeton : ' + D0.origine + ' · ' + D0.plateforme + ' · expéditeur : ' + (D0.expediteur || 'aucun (celui du compte)') + (D0.id ? ' · id ' + D0.id : '') : '')
            + (r.compte ? ' · compte SMSAPI : ' + r.compte : '') + '.';
          app.setState({ hdSmsMsg: { ok: true, msg: tete + ' Remise : vérification dans 15 s…' } });
          if (!r.id) { return; }
          const verifier = n => readOne('/helpdesk/sms/remise?id=' + encodeURIComponent(r.id)).then(st => {
            const fini = !!(st && st.final);
            const t = st && st.ok ? 'Remise : ' + st.libelle + ' (' + st.statut + ')' + (st.erreur ? ', erreur ' + st.erreur : '') : 'Remise : illisible (' + ((st && st.erreur) || 'pas de réponse') + ')';
            app.setState({ hdSmsMsg: { ok: !!(st && st.statut === 'DELIVERED') || (!fini && !!(st && st.ok)), msg: tete + ' ' + t + (fini || n >= 4 ? '' : ' — nouvelle vérification dans 30 s…') } });
            if (!fini && n < 4) { setTimeout(() => verifier(n + 1), 30000); }
          });
          setTimeout(() => verifier(1), 15000);
        });
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
      write(app.source || 'api', 'PUT', '/helpdesk/sms', { actif: !!F.actif, franchise: F.franchise !== false, destinataires: F.destinataires.filter(x => String(x.nom || '').trim() || String(x.tel || '').trim()), relances: F.relances }).then(r => {
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
  const passe = l => (f === 'toutes' || rcEtat(l) === f || (f === 'sansphoto' && !l.nbPhotos)) && (!fm || l.shopId === fm) && (!ff || (l.fournisseur || 'Fournisseur inconnu') === ff)
    && (!q || [l.reference, l.sku, l.fournisseur, l.magasin, l.motif, l.texte, l.reponse, l.cle].join(' ').toLowerCase().indexOf(q) >= 0);
  const L = tous.filter(passe);
  const ouvertes = tous.filter(l => l.ouverte && l.statut !== 'REJECTED');
  const vieille = ouvertes.filter(l => l.age != null).sort((a, b) => b.age - a.age)[0];
  const sansPhoto = tous.filter(l => !l.nbPhotos).length;
  v.kpis = [['Ouvertes', ouvertes.length, 'sans réponse du fournisseur', ouvertes.length ? 'att' : 'ok'],
    ['Montant ouvert', eur(D.montantOuvert), 'au prix d’achat' + (D.montantComplet === false ? ' (prix incomplets)' : ''), 'ko'],
    ['Plus ancienne', vieille ? vieille.age + ' j' : '—', vieille ? court(vieille.magasin) + ', le ' + dm(vieille.le) : 'aucune ouverte', 'ko'],
    ['Réglées', tous.filter(l => rcEtat(l) === 'reglee').length, 'réponse du fournisseur', 'ok'], ['Refusées', tous.filter(l => rcEtat(l) === 'refusee').length, 'par le fournisseur', 'mu'],
    // Les photos (21/11/2026) : sans photo, le fournisseur refuse — la part des réclamations qui en ont une.
    ['Avec photo', tous.filter(l => l.nbPhotos).length + ' / ' + tous.length, sansPhoto + ' sans photo · ' + new Set(tous.map(l => l.shopId)).size + ' magasin' + (new Set(tous.map(l => l.shopId)).size > 1 ? 's' : ''), sansPhoto ? 'att' : 'ok']];
  v.chips = [['toutes', 'Toutes', tous.length], ['ouverte', 'Ouvertes', ouvertes.length], ['reglee', 'Réglées', tous.filter(l => rcEtat(l) === 'reglee').length], ['refusee', 'Refusées', tous.filter(l => rcEtat(l) === 'refusee').length], ['sansphoto', 'Sans photo', sansPhoto]]
    .map(([k, n, c]) => ({ nom: n, n: c, on: f === k, ko: k === 'sansphoto', choisir: () => app.setState({ hdRcFiltre: k }) }));
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
    ton: ton(l), reponse: !!l.reponse, reponseLe: dm(l.reponseLe), montant: eur(l.montant), photos: l.nbPhotos || 0,
    on: l.id === sel, choisir: () => app.setState({ hdRcSel: l.id }) }));
  v.n = L.length; v.total = tous.length; v.source = D.source; v.lecture = D.lecture; v.ecartees = D.ecartees || 0;
  const X = tous.find(l => l.id === sel);
  v.fiche = X ? { ref: X.cle || ('#' + X.id), titre: (X.reference || 'Réclamation') + (X.fournisseur ? ' · ' + X.fournisseur : ''), sous: court(X.magasin) + ' · le ' + dm(X.le) + (X.age != null ? ' (' + X.age + ' j)' : ''),
    statut: v.lignes.find(l => l.on).statut, ton: ton(X), texte: X.texte, reponse: X.reponse, reponseLe: dm(X.reponseLe),
    champs: [['Référence', X.reference + (X.sku ? ' · SKU ' + X.sku : '')], ['Quantité', X.qte != null ? nb(X.qte) + (X.unite ? ' ' + X.unite : '') : ''], ['Motif', X.motif],
      ['Ce qui est demandé', X.action], ['Commande', X.commande], ['Montant', X.montant != null ? eur(X.montant) + (X.prixUnitaire != null ? ' (' + eur(X.prixUnitaire) + ' / unité)' : '') : ''],
      ['Quantité acceptée', X.qteAcceptee != null ? nb(X.qteAcceptee) : ''], ['Compensation', X.compensation]].filter(([, val]) => val) } : null;
  if (X) { Object.assign(v.fiche, ficheRc(app, X)); }
  return v;
}

/* La fiche d'une réclamation (21/11/2026, maquette A) : ses photos — liens signés du panel, redemandés à chaque
 * ouverture — et la part du produit dans ce qui a été livré au magasin (semaine, mois, année ; réseau sur l'année).
 * Serveur : GET /fournisseurs/reclamation-fiche (src/reclamations.php). */
function chargerFiche(app, X){
  const cle = X.shopId + '|' + X.id, F = app.state.hdRcFiche;
  if (F && F.cle === cle) { return; }
  app.setState({ hdRcFiche: { cle, chargement: true, d: null }, hdRcPhoto: 0 });
  readOne('/fournisseurs/reclamation-fiche?shop=' + encodeURIComponent(X.shopId) + '&id=' + encodeURIComponent(X.id))
    .then(d => { if ((app.state.hdRcFiche || {}).cle === cle) { app.setState({ hdRcFiche: { cle, chargement: false, d: d || { error: 'le cockpit n’a pas répondu' } } }); } });
}
const pct = n => n == null ? '—' : Number(n).toLocaleString('fr-BE', { maximumFractionDigits: n < 1 ? 2 : 1 }) + ' %';
function ficheRc(app, X){
  chargerFiche(app, X);
  const F = app.state.hdRcFiche || {}, d = F.d && !F.d.error ? F.d : null;
  const liste = d ? (d.photos || []).filter(p => p.url) : [];
  const i = Math.min(Math.max(0, app.state.hdRcPhoto || 0), Math.max(0, liste.length - 1));
  const photos = { chargement: !!F.chargement, erreur: F.d && F.d.error ? F.d.error : null, n: X.nbPhotos || 0,
    perdues: d ? (d.photos || []).filter(p => !p.url).length : 0,
    grande: liste[i] ? liste[i].url : null, rang: liste.length ? (i + 1) + ' / ' + liste.length : '',
    vignettes: liste.map((p, k) => ({ url: p.url, on: k === i, choisir: () => app.setState({ hdRcPhoto: k }) })),
    source: liste.length ? (liste.some(p => p.source === 'dashboard') && liste.some(p => p.source === 'panel') ? 'panel et dashboard' : (liste[0].source === 'dashboard' ? 'prises depuis le dashboard' : 'jointes au panel')) : '',
    toutOuvrir: liste.length > 1 ? () => liste.forEach(p => window.open(p.url, '_blank', 'noopener')) : null };
  const P = d && d.part ? d.part : null;
  const unite = X.unite ? ' ' + X.unite : '';
  const part = !P ? null : !P.lu ? { indispo: 'Les livraisons de ce magasin ne sont pas lisibles : la part du livré ne peut pas être calculée.' } : {
    titre: 'Part de ' + (X.reference || 'ce produit') + ' dans ce qui a été livré à ' + court(X.magasin),
    cases: ['semaine', 'mois', 'annee'].map(k => { const p = P.periodes[k] || {}; return { nom: p.nom, part: pct(p.part), barre: p.part == null ? 0 : Math.min(100, p.part * 4),
      sous: nb(p.reclame) + unite + ' réclamés / ' + (p.livre == null ? '—' : nb(p.livre) + unite + ' livrés'), haut: p.part != null && p.part >= 3 }; }),
    reseau: 'Réseau, ' + (P.reseau || {}).nom + ' : ' + pct((P.reseau || {}).part) + ' (' + nb((P.reseau || {}).reclame) + ' / ' + nb((P.reseau || {}).livre) + unite + ')'
      + ' · ' + court(X.magasin) + ' : ' + pct(((P.periodes || {}).annee || {}).part) };
  return { photos, part };
}

/* Le tableau annuel (21/11/2026, maquette B) : magasins × mois, le montant réclamé et sa part dans ce qui a été livré
 * au magasin le même mois, l'accepté, et la ligne Réseau. Serveur : GET /reclamations/annuel (src/reclamations.php). */
const MOIS_C = ['Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];
function chargerAn(app, frais, annee, four, shop, type){
  const A = app.state.hdAn || {};
  const an = annee != null ? annee : (A.annee || new Date().getFullYear());
  const f = four != null ? four : (A.fournisseur != null ? A.fournisseur : '');
  const sh = shop != null ? shop : (A.shop != null ? A.shop : '');
  const ty = type != null ? type : (A.type != null ? A.type : '');
  const cle = [an, f, sh, ty].join('|');
  if (!frais && A.cle === cle && (A.d || A.chargement)) { return; }
  app.setState({ hdAn: { chargement: true, d: A.cle === cle ? A.d : null, annee: an, fournisseur: f, shop: sh, type: ty, cle } });
  readOne('/reclamations/annuel?annee=' + an + '&fournisseur=' + encodeURIComponent(f) + (sh ? '&shop=' + encodeURIComponent(sh) : '') + (ty ? '&type=' + ty : '')).then(d => {
    if ((app.state.hdAn || {}).cle === cle) { app.setState({ hdAn: { chargement: false, d: d || { indispo: true, motif: 'le cockpit n’a pas répondu' }, annee: an, fournisseur: f, shop: sh, type: ty, cle } }); }
  });
}
const chaleur = p => p == null ? '' : (p >= 3 ? 'h3' : p >= 1.5 ? 'h2' : p >= 0.75 ? 'h1' : 'h0');
const eur0 = n => n == null ? '—' : Math.round(n).toLocaleString('fr-BE') + ' €';
const pcs = (n, u) => n == null ? '—' : Number(n).toLocaleString('fr-BE', { maximumFractionDigits: n % 1 ? 1 : 0 }) + ' ' + (u || 'pcs');
/* Le tableau annuel des PRODUITS RÉCLAMÉS (21/11/2026, maquettes B, D et E) : « Livré » ne compte que les produits
 * réclamés dans l'année (d'après les factures du fournisseur), et le % se lit sur l'ensemble, la catégorie et le
 * produit. Trois vues : par magasin, par catégorie, par produit (rangé par catégorie). Serveur : GET /reclamations/annuel. */
function valsAnnuel(app){
  chargerAn(app, false);
  const S = app.state, A = S.hdAn || {}, D = A.d && !A.d.indispo && !A.d.error ? A.d : null;
  const anCourant = new Date().getFullYear();
  const vue = ['mag', 'cat', 'prod', 'motif'].includes(S.hdAnVue) ? S.hdAnVue : 'cat';
  const enPieces = S.hdAnUnite === 'pcs' && vue === 'prod';
  const v = { chargement: !!A.chargement && !D, relecture: !!A.chargement && !!D, relire: () => chargerAn(app, true),
    erreur: A.d && (A.d.indispo || A.d.error) ? 'Le tableau annuel n’a pas pu être lu : ' + (A.d.motif || A.d.error) + '.' : null,
    vues: [['mag', 'Par magasin'], ['cat', 'Catégories'], ['prod', 'Produits'], ['motif', 'Motifs']].map(([k, nom]) => ({ nom, on: vue === k, choisir: () => app.setState({ hdAnVue: k }) })),
    // Produit ou livraison (21/11/2026) : « rajouter s'il y a des problèmes et réclamations de livraison ».
    types: [['', 'Toutes'], ['produit', 'Produit'], ['livraison', 'Livraison']].map(([k, nom]) => ({ nom, on: (A.type || '') === k, ko: k === 'livraison', choisir: () => chargerAn(app, false, null, null, null, k) })),
    unites: vue === 'prod' ? [['eur', '€ et %'], ['pcs', 'Pièces et %']].map(([k, nom]) => ({ nom, on: (k === 'pcs') === enPieces, choisir: () => app.setState({ hdAnUnite: k }) })) : null,
    annees: [anCourant, anCourant - 1, anCourant - 2].map(a => ({ id: String(a), nom: String(a), on: a === A.annee })),
    poserAnnee: e => chargerAn(app, false, Number(e.target.value)),
    mois: MOIS_C, vue };
  if (!D) { return v; }
  v.fournisseurs = (D.fournisseurs || []).map(f => ({ id: f.nom, nom: f.nom + ' (' + f.n + ')', on: f.nom === D.fournisseur }))
    .concat([{ id: '*', nom: 'Tous les fournisseurs', on: D.fournisseur === '*' }]);
  v.poserFour = e => chargerAn(app, false, null, e.target.value);
  v.magasins = [{ id: '', nom: 'Tous les magasins (réseau)', on: !D.shop }].concat((D.magasinsListe || []).map(m => ({ id: m.id, nom: court(m.nom), on: m.id === D.shop })));
  v.poserMag = e => chargerAn(app, false, null, null, e.target.value);
  // Une case : réclamé (€ ou pièces) et le % réclamé ÷ livré (€ pour un regroupement, pièces pour un produit seul).
  const cellule = (c, pieces, unite) => {
    if (c == null) { return { futur: true }; }
    const part = pieces ? c.partQ : c.part;
    return { montant: c.n ? (pieces ? pcs(c.rq, unite) : eur0(c.reclame)) : '', part: c.n ? (part != null ? pct(part) : 'livré inconnu') : '',
      cls: c.n ? chaleur(part) : '', titre: c.n + ' réclamation' + (c.n > 1 ? 's' : '') + (c.nonChiffrees ? ' (' + c.nonChiffrees + ' sans prix)' : '') + ' · livré ' + (pieces ? pcs(c.lq, unite) : eur0(c.livre)) };
  };
  const ligne = (nom, sous, L, opts) => {
    const o = opts || {}, t = L.total || {}, pieces = !!o.pieces, part = pieces ? t.partQ : t.part;
    return { nom, sous, fort: !!o.fort, cat: !!o.cat, ind: !!o.ind, cases: (L.mois || []).map(c => cellule(c, pieces, o.unite)), total: cellule(t, pieces, o.unite),
      livre: pieces ? pcs(t.lq, o.unite) : eur0(t.livre), livreSous: pieces ? pcs(t.rq, o.unite) + ' réclamés' : eur0(t.reclame) + ' réclamés',
      pc: t.n && part != null ? pct(part) : (t.n ? '—' : ''), pcCls: chaleur(part) };
  };
  const E = D.ensemble || { mois: [], total: {} }, T = E.total || {};
  const npr = n => n + ' produit' + (n > 1 ? 's' : '') + ' réclamé' + (n > 1 ? 's' : '');
  if (vue === 'mag') {
    v.premier = 'Magasin';
    v.lignes = (D.magasins || []).map(m => ligne(court(m.nom), m.total.n + ' réclamation' + (m.total.n > 1 ? 's' : ''), m));
  } else if (vue === 'cat') {
    v.premier = 'Catégorie';
    v.lignes = (D.categories || []).map(c => ligne(c.nom, npr((c.produits || []).length), c));
  } else if (vue === 'motif') {
    v.premier = 'Motif';
    v.lignes = [];
    ['livraison', 'produit'].forEach(ty => {
      const M = (D.motifs || []).filter(m => m.type === ty);
      if (!M.length) { return; }
      v.lignes.push({ titre: ty === 'livraison' ? 'Problèmes de livraison' : 'Problèmes de produit', ko: ty === 'livraison' });
      M.forEach(m => v.lignes.push(ligne(m.nom, m.total.n + ' réclamation' + (m.total.n > 1 ? 's' : '') + ' · % sur le livré des produits réclamés', m, { ind: true })));
    });
  } else {
    v.premier = 'Produit';
    v.lignes = [];
    (D.categories || []).forEach(c => {
      v.lignes.push(ligne('▾ ' + c.nom, 'catégorie · ' + npr((c.produits || []).length), c, { cat: true }));
      (c.produits || []).forEach(p => v.lignes.push(ligne(p.nom, p.sku ? 'SKU ' + p.sku : '', p, { ind: true, pieces: enPieces, unite: p.unite })));
    });
  }
  if (v.lignes.length) { v.lignes.push(ligne('Ensemble', npr(D.produits || 0), E, { fort: true })); }
  // En tête : l'ensemble, puis les catégories (les cinq plus réclamées).
  const cats = (D.categories || []).filter(c => c.total.n).slice(0, 4);
  const TY = D.types || {}, Li = TY.livraison || {}, Pr = TY.produit || {};
  v.kpis = [['Livraison', String(Li.n || 0), (Li.n ? 'réclamations de livraison · ' + eur0(Li.reclame) + ' · ' + (Li.ouvertes || 0) + ' sans réponse' : 'aucune réclamation de livraison') + ' (produit : ' + (Pr.n || 0) + ')', Li.n ? 'ko' : 'ok'],
    ['Ensemble · produits réclamés', pct(T.part), eur0(T.reclame) + ' réclamés ÷ ' + eur0(T.livre) + ' livrés' + (T.nonChiffrees ? ' · ' + T.nonChiffrees + ' sans prix' : ''), T.part >= 1.5 ? 'att' : '']]
    .concat(cats.map(c => [c.nom, pct(c.total.part), eur0(c.total.reclame) + ' / ' + eur0(c.total.livre) + ' · ' + (c.produits || []).length + ' produit' + ((c.produits || []).length > 1 ? 's' : ''), c.total.part >= 3 ? 'ko' : '']));
  v.source = D.source;
  v.sourceKo = D.livreSource !== 'factures' ? (D.livreSource ? 'Livré lu sur le grand livre du panel : les factures du fournisseur ne sont pas lisibles.' : 'Aucune source du livré lisible.') : '';
  v.exporter = () => {
    const sep = ';', num = n => n == null ? '' : String(Math.round(n * 100) / 100).replace('.', ',');
    const l = [[v.premier, 'Ligne'].concat(MOIS_C.map(m => m + ' réclamé (€)'), MOIS_C.map(m => m + ' % du livré'), ['Année réclamé (€)', 'Année réclamé (pcs)', 'Livré (€)', 'Livré (pcs)', '% (€)', '% (pcs)', 'Accepté (€)', 'Réclamations']).join(sep)];
    const ajoute = (nom, type, L) => { const t = L.total || {}; l.push([nom, type].concat((L.mois || []).map(c => c ? num(c.reclame) : ''), (L.mois || []).map(c => c ? num(c.part) : ''), [num(t.reclame), num(t.rq), num(t.livre), num(t.lq), num(t.part), num(t.partQ), num(t.accepte), String(t.n || 0)]).join(sep)); };
    if (vue === 'mag') { (D.magasins || []).forEach(m => ajoute(court(m.nom), 'magasin', m)); }
    else if (vue === 'motif') { (D.motifs || []).forEach(m => ajoute(m.nom, m.type, m)); }
    else { (D.categories || []).forEach(c => { ajoute(c.nom, 'catégorie', c); if (vue === 'prod') { (c.produits || []).forEach(p => ajoute(p.nom, 'produit', p)); } }); }
    ajoute('Ensemble', 'ensemble', E);
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob(['﻿' + l.join('\n')], { type: 'text/csv;charset=utf-8' }));
    a.download = 'reclamations-' + D.annee + '-' + vue + '-' + (D.fournisseur === '*' ? 'tous' : D.fournisseur).replace(/[^\w-]+/g, '_') + '.csv';
    document.body.appendChild(a); a.click(); a.remove();
  };
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
  Object.assign(c.hdAT, { n: D.n, urgentes: D.urgentes || 0, plus: Math.max(0, L.length - 5), lignes: L.slice(0, 5).map(d => ({ age: d.age + ' j', vieux: d.age > 7, urgent: !!d.urgent, magasin: d.magasin, titre: d.titre,
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
  const vue = ['reclamations', 'annuel'].includes(S.hdVue) ? S.hdVue : 'demandes';
  const v = { vue, chargement: !!H.chargement && !D, erreur: H.d && (H.d.error || H.d.indispo) ? (H.d.motif || H.d.error) : null, relire: () => charger(app, true), relecture: !!H.chargement && !!D };
  c.hd = v;
  // Deux onglets (20/11/2026) : les demandes au bureau, les réclamations fournisseur de tous les magasins.
  const RcD = (S.hdRc || {}).d;
  v.onglets = [['demandes', 'Demandes au bureau', D ? (D.demandes || []).filter(x => x.ouverte).length + ' ouvertes' : ''],
    ['reclamations', 'Réclamations fournisseur', RcD && !RcD.indispo && !RcD.error ? (RcD.ouvertes || 0) + ' ouvertes · tous les magasins' : ''],
    ['annuel', 'Tableau annuel', 'produits réclamés / livrés · catégories et produits']]
    .map(([k, nom, sous]) => ({ nom, sous, on: vue === k, choisir: () => app.setState({ hdVue: k }) }));
  if (vue === 'reclamations') { v.rc = valsReclamations(app); return; }
  if (vue === 'annuel') { v.an = valsAnnuel(app); return; }
  if (!D) { return; }
  v.sms = valsSms(app, D);
  const tous = D.demandes || [];
  const f = S.hdFiltre || 'ouvertes', q = String(S.hdQ || '').trim().toLowerCase(), fd = S.hdDom || '', fm = S.hdMag || '';
  const ouvertes = tous.filter(x => x.ouverte);
  const passe = x => (f === 'toutes' || (f === 'ouvertes' && x.ouverte) || (f === 'sansrep' && x.ouverte && !x.reponse) || (f === 'mission' && (x.missions || []).length)
      || (f === 'retard' && x.enRetard) || (f === 'sansdelai' && x.ouverte && !x.echeance) || (f === 'urgent' && x.urgent && x.ouverte) || x.statut === f)
    && (!fd || x.domaine === fd) && (!fm || x.shop === fm) && (!q || (x.titre + ' ' + x.description + ' ' + x.ref + ' ' + x.magasin).toLowerCase().indexOf(q) >= 0);
  const L = tous.filter(passe);
  const plusVieille = ouvertes.slice().sort((a, b) => a.cree.localeCompare(b.cree))[0];
  const retard = ouvertes.filter(x => x.enRetard).length, sansDelai = ouvertes.filter(x => !x.echeance).length;
  v.kpis = [['Ouvertes', ouvertes.length, 'nouvelles ou en cours', 'att'], ['Sans réponse', ouvertes.filter(x => !x.reponse).length, 'aucune réponse du bureau', 'ko'],
    ['En retard', retard, 'date limite dépassée' + (sansDelai ? ' · ' + sansDelai + ' sans date' : ''), retard ? 'ko' : 'ok'],
    ['Plus ancienne', plusVieille ? jours(plusVieille.cree) + ' j' : '—', plusVieille ? 'ouverte depuis le ' + dm(plusVieille.cree) : 'aucune ouverte', 'ko'],
    D.relances && !D.relances.erreur ? ['Relances', D.relances.actif ? D.relances.sms : 'coupées', D.relances.actif ? 'SMS depuis le ' + dm(D.relances.depuis) + (D.relances.arretees ? ' · ' + D.relances.arretees + ' arrêtée' + (D.relances.arretees > 1 ? 's' : '') + ' par une réponse' : '') : 'à rallumer dans « SMS au bureau »', '']
      : ['Résolues', tous.filter(x => x.statut === 'RESOLVED').length, 'sur ' + tous.length + ' demandes', 'ok'], ['Dans une visite', tous.filter(x => (x.missions || []).some(m => !m.cloture)).length, 'missions en cours', '']];
  v.chips = [['ouvertes', 'Ouvertes', ouvertes.length], ['urgent', 'Urgentes', ouvertes.filter(x => x.urgent).length], ['NEW', 'Nouvelles', tous.filter(x => x.statut === 'NEW').length], ['IN_PROGRESS', 'En cours', tous.filter(x => x.statut === 'IN_PROGRESS').length],
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
    urgent: !!x.urgent && x.ouverte, on: x.id === sel, choisir: () => app.setState({ hdSel: x.id, hdEnvoi: null }) }));
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
    id: X.id, ref: X.ref, titre: X.titre, urgent: !!X.urgent, sous: court(X.magasin) + ' · ' + X.domaineNom + ' · créée le ' + dm(X.cree) + ' (' + jours(X.cree) + ' j)' + (X.demandeur ? ' · ' + X.demandeur : ''),
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
    repondre: String(F.rep || '').trim() && !E.enCours ? () => envoyer(app, '/helpdesk/demandes/' + X.id + '/repondre', { reponse: F.rep, statut: F.repStatut != null ? F.repStatut : 'IN_PROGRESS', shop: X.shop, titre: X.titre, ref: X.ref }, () => majF({ rep: '' })) : null,
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
