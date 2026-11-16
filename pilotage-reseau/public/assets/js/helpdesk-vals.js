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

export function nbDemandesOuvertes(app){
  const d = ((app.state.hdData || {}).d || {}).demandes || [];
  return d.filter(x => x.ouverte && !x.reponse).length;
}

export function valsHelpdesk(app, c){
  const S = app.state;
  charger(app, false);
  const H = S.hdData || {};
  const D = H.d && !H.d.error && !H.d.indispo ? H.d : null;
  const v = { chargement: !!H.chargement && !D, erreur: H.d && (H.d.error || H.d.indispo) ? (H.d.motif || H.d.error) : null, relire: () => charger(app, true), relecture: !!H.chargement && !!D };
  c.hd = v;
  if (!D) { return; }
  const tous = D.demandes || [];
  const f = S.hdFiltre || 'ouvertes', q = String(S.hdQ || '').trim().toLowerCase(), fd = S.hdDom || '', fm = S.hdMag || '';
  const ouvertes = tous.filter(x => x.ouverte);
  const passe = x => (f === 'toutes' || (f === 'ouvertes' && x.ouverte) || (f === 'sansrep' && x.ouverte && !x.reponse) || (f === 'mission' && (x.missions || []).length) || x.statut === f)
    && (!fd || x.domaine === fd) && (!fm || x.shop === fm) && (!q || (x.titre + ' ' + x.description + ' ' + x.ref + ' ' + x.magasin).toLowerCase().indexOf(q) >= 0);
  const L = tous.filter(passe);
  const plusVieille = ouvertes.slice().sort((a, b) => a.cree.localeCompare(b.cree))[0];
  v.kpis = [['Ouvertes', ouvertes.length, 'nouvelles ou en cours', 'att'], ['Sans réponse', ouvertes.filter(x => !x.reponse).length, 'aucune réponse du bureau', 'ko'],
    ['Plus ancienne', plusVieille ? jours(plusVieille.cree) + ' j' : '—', plusVieille ? 'ouverte depuis le ' + dm(plusVieille.cree) : 'aucune ouverte', 'ko'],
    ['Résolues', tous.filter(x => x.statut === 'RESOLVED').length, 'sur ' + tous.length + ' demandes', 'ok'], ['Dans une visite', tous.filter(x => (x.missions || []).some(m => !m.cloture)).length, 'missions en cours', '']];
  v.chips = [['ouvertes', 'Ouvertes', ouvertes.length], ['NEW', 'Nouvelles', tous.filter(x => x.statut === 'NEW').length], ['IN_PROGRESS', 'En cours', tous.filter(x => x.statut === 'IN_PROGRESS').length],
    ['sansrep', 'Sans réponse', ouvertes.filter(x => !x.reponse).length], ['mission', 'En visite', tous.filter(x => (x.missions || []).length).length], ['RESOLVED', 'Résolues', tous.filter(x => x.statut === 'RESOLVED').length], ['toutes', 'Toutes', tous.length]]
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
    age: jours(x.cree) + ' j', vieux: x.ouverte && jours(x.cree) > 60, visite: (x.missions || []).filter(m => !m.cloture).map(m => 'visite ' + dm(m.visiteLe))[0] || '',
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
