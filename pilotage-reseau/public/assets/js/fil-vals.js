/* Cockpit CEO — Franchisés › Suivi des franchisés › Fil du franchisé (19/11/2026). Maquettes docs/maquettes/fil-franchise ;
 * retenu par Sam le 19/11 : « A on toggle B » — le fil vertical (A), et le calendrier (B) en bascule.
 *
 * La carte de chaque franchisé ; un clic ouvre son fil : ce que le cockpit sait déjà (visites, score, points décidés,
 * rapports, client mystère, demandes du helpdesk) et ce que le bureau y dépose — une note, des photos, des captures
 * d'écran (Ctrl+V), un appel — avec ses étiquettes. Un « Problème de gestion » ouvre un fil ; une suite le prolonge,
 * une bonne action le ferme. Serveur : src/fil_franchise.php. Gabarit : fil-tpl.js ; style : fil.css (fil-). */
import { readOne, write, API_BASE } from './api.js';

const JC = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
const JL = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
const MC = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const ML = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
const SENS = ['gestion', 'surveiller', 'bonne'];
const TYPES_NOTE = ['note', 'photo', 'capture', 'appel'];
const STATUTS_VISITE = { planifiee: 'planifiée', confirmee: 'confirmée', en_cours: 'en cours', terminee: 'faite', annulee: 'annulée' };
const STATUTS_POINT = { ouvert: 'ouvert', attente: 'correction à valider', valide: 'validé', reprendre: 'à reprendre', ferme: 'fermé', escalade: 'escaladé', propose: 'proposé', interne: 'gardé au bureau' };
const GROUPES_TYPES = [['visite', 'Visites, rapports et points'], ['note', 'Notes, photos, captures, appels'], ['demande', 'Demandes du franchisé'], ['mystere', 'Client mystère']];
const PIECE_MAX = 6 * 1024 * 1024;

const pad = n => String(n).padStart(2, '0');
const iso = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
const auj = () => iso(new Date());
const dt = d => new Date(String(d).slice(0, 10) + 'T12:00:00');
const plusJ = (d, n) => { const x = dt(d); x.setDate(x.getDate() + n); return iso(x); };
const lundi = d => { const x = dt(d); return plusJ(d, -((x.getDay() + 6) % 7)); };
const jm = d => d ? String(d).slice(8, 10) + '/' + String(d).slice(5, 7) : '';
const jourCourt = d => JC[dt(d).getDay()] + ' ' + jm(d);
const ecartJ = (a, b) => Math.round((dt(b) - dt(a)) / 864e5);
function semaine(d){
  const t = dt(d); const j = (t.getDay() + 6) % 7; t.setDate(t.getDate() - j + 3);
  const p = new Date(t.getFullYear(), 0, 4);
  return 1 + Math.round(((t - p) / 864e5 - 3 + ((p.getDay() + 6) % 7)) / 7);
}
const bornes = l => { const di = plusJ(l, 6); return (+l.slice(8, 10)) + (l.slice(5, 7) !== di.slice(5, 7) ? ' ' + MC[+l.slice(5, 7) - 1] : '') + ' – ' + (+di.slice(8, 10)) + ' ' + MC[+di.slice(5, 7) - 1]; };
const pluriel = (n, s, p) => n + ' ' + (n > 1 ? (p || s + 's') : s);
const maintenantLocal = () => { const d = new Date(); return iso(d) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()); };

/** Une image réduite avant l'envoi : JPEG, le plus grand côté borné (1600 px une photo, 2000 px une capture). */
function reduire(fichier, max, q){
  return new Promise((ok, ko) => {
    const u = URL.createObjectURL(fichier);
    const img = new Image();
    img.onload = () => {
      const k = Math.min(1, max / Math.max(img.naturalWidth || 1, img.naturalHeight || 1));
      const c = document.createElement('canvas'); c.width = Math.max(1, Math.round(img.naturalWidth * k)); c.height = Math.max(1, Math.round(img.naturalHeight * k));
      const x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.imageSmoothingQuality = 'high';
      x.drawImage(img, 0, 0, c.width, c.height);
      URL.revokeObjectURL(u);
      ok(c.toDataURL('image/jpeg', q));
    };
    img.onerror = () => { URL.revokeObjectURL(u); ko(new Error('image illisible')); };
    img.src = u;
  });
}

/** Le nom de la franchise sans la ville qui suit (« Atelier by Berlo - Corbais » → « Atelier by Berlo ») ; « Atelier by » seul garde la ville. */
function nomFranchise(nom, ville){
  const n = String(nom || '').replace(/\s+-\s+[^-]*$/, '').trim();
  return !n || /by$/i.test(n) ? (n ? n + ' ' : '') + ville : n;
}

/* --- lectures ------------------------------------------------------------------ */

function chargerMags(app, frais){
  const M = app.state.flMags;
  if (!frais && M && (M.d || M.chargement)) { return; }
  app.setState({ flMags: { chargement: true, d: M ? M.d : null } });
  readOne('/fil/magasins').then(d => app.setState({ flMags: { chargement: false, d: d || { error: 'injoignable' } } }));
}
function chargerFil(app, shop, annee, frais){
  const F = app.state.flData;
  if (!frais && F && F.shop === shop && F.annee === annee && (F.d || F.chargement)) { return; }
  app.setState({ flData: { shop, annee, chargement: true, d: F && F.shop === shop ? F.d : null } });
  readOne('/fil?shop=' + encodeURIComponent(shop) + '&annee=' + annee).then(d => {
    const G = app.state.flData;
    if (G && (G.shop !== shop || G.annee !== annee)) { return; }
    app.setState({ flData: { shop, annee, chargement: false, d: d || { error: 'injoignable' } } });
  });
}
function chargerDemandes(app, shop){
  const D = app.state.flDem;
  if (D && D.shop === shop) { return; }
  app.setState({ flDem: { shop, chargement: true, d: null } });
  readOne('/helpdesk/magasin?shop=' + encodeURIComponent(shop)).then(d => { if ((app.state.flDem || {}).shop === shop) { app.setState({ flDem: { shop, chargement: false, d } }); } });
}
function relire(app){
  const F = app.state.flData;
  chargerMags(app, true);
  if (F) { chargerFil(app, F.shop, F.annee, true); }
}

export function nbFilsOuverts(app){
  const d = ((app.state.flMags || {}).d || {}).magasins || [];
  return d.reduce((n, m) => n + (m.filsOuverts || 0), 0);
}

/* --- coller ou déposer une image ------------------------------------------------------ */

/**
 * Les images d'un presse-papiers ou d'un glisser-déposer : les fichiers image de ses éléments (une capture d'écran, une
 * image copiée dans une page), sinon ses fichiers (des photos copiées dans l'explorateur, quand le navigateur les passe).
 */
function imagesDe(dt){
  if (!dt) { return []; }
  const parElements = Array.from(dt.items || []).filter(i => i.kind === 'file' && /^image\//.test(i.type)).map(i => i.getAsFile()).filter(Boolean);
  return parElements.length ? parElements : Array.from(dt.files || []).filter(f => /^image\//.test(f.type || 'image/'));
}
/** Ctrl+V d'une image n'importe où sur l'écran du fil : elle rejoint l'entrée en cours (le formulaire ouvert, sinon celui du haut). */
function ecouterColler(app){
  if (app._filColler || typeof document === 'undefined') { return; }
  app._filColler = true;
  document.addEventListener('paste', e => {
    if (app.state.screen !== 'filFranchise') { return; }
    const imgs = imagesDe(e.clipboardData);
    if (!imgs.length) { return; }
    e.preventDefault();
    ajouterPieces(app, imgs, null);
  });
}
function cibleForm(app){ return app.state.flEdit ? 'flEdit' : 'flForm'; }
/**
 * Joint des images à l'entrée en cours. Une capture d'écran collée (PNG sans nom, « image.png ») se range en capture,
 * une photo (JPEG, ou un fichier nommé) en photo. Le geste se confirme (19/11/2026, Sam : « je dois drag and drop ou
 * coller une photo ») : le formulaire du haut pouvait être hors de l'écran, et rien ne disait que l'image était jointe.
 */
function ajouterPieces(app, fichiers, genre){
  const cle = cibleForm(app);
  const imgs = Array.from(fichiers || []).filter(f => /^image\//.test(f.type || 'image/'));
  if (!imgs.length) { app.notify('Seules les images se joignent : JPEG, PNG ou WebP.'); return; }
  let n = 0;
  Promise.all(imgs.map(f => {
    const capture = /^image\/png$/.test(f.type) && (!f.name || f.name === 'image.png' || /capture|screenshot|écran|ecran/i.test(f.name));
    const g = genre || (capture ? 'capture' : 'photo');
    return reduire(f, g === 'capture' ? 2000 : 1600, g === 'capture' ? 0.9 : 0.85).then(data => {
      if (data.length * 0.75 > PIECE_MAX) { app.notify('Image trop lourde, même réduite (6 Mo au plus).'); return; }
      const F0 = app.state[cle] || {};
      const F = cle === 'flForm' && F0.shop !== String(app.state.flMag || '') ? { shop: String(app.state.flMag || '') } : F0;
      const pieces = (F.pieces || []).concat([{ genre: g, data, nom: f.name && f.name !== 'image.png' ? f.name : (g === 'capture' ? 'capture collée' : 'photo') }]);
      if (pieces.length + (F.garder || []).length > 8) { app.notify('8 pièces au plus par entrée.'); return; }
      app.setState({ [cle]: Object.assign({}, F, { pieces }) });
      n++;
    }).catch(() => app.notify('Image illisible : JPEG, PNG ou WebP.'));
  })).then(() => {
    if (!n) { return; }
    app.notify(pluriel(n, 'image jointe', 'images jointes') + ' à ' + (cle === 'flEdit' ? 'l’entrée en cours de modification' : 'la nouvelle entrée, en haut du fil')
      + ' — « ' + (cle === 'flEdit' ? 'Enregistrer' : 'Ajouter au fil') + ' » l’enregistre.');
    // Le formulaire à l'écran : on voit l'image jointe là où elle attend.
    setTimeout(() => {
      const z = typeof document !== 'undefined' ? document.querySelector(cle === 'flEdit' ? '.fil-compo--edit' : '.fil-compo') : null;
      if (z && z.scrollIntoView) { z.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
    }, 60);
  });
}

/* --- le formulaire (ajout en tête du fil, ou modification d'une entrée) ------------- */

function formVue(app, D, cle, F, pour){
  const courant = () => { const x = app.state[cle]; return x && (cle === 'flEdit' || x.shop === D.shop) ? x : F; };
  const maj = p => app.setState({ [cle]: Object.assign({}, courant(), p, cle === 'flForm' ? { shop: D.shop } : {}) });
  const T = D.tags || {};
  const fils = (D.fils || []);
  const tags = F.tags || [];
  const envoi = app.state.flEnvoi && app.state.flEnvoi.cle === cle ? app.state.flEnvoi : null;
  const filChoisi = fils.find(f => String(f.id) === String(F.fil || ''));
  const prochaine = D.prochaine;
  const v = {
    cle, edition: cle === 'flEdit', pour,
    idTexte: cle === 'flEdit' ? 'fl-edit-txt' : 'fl-txt', idLe: cle === 'flEdit' ? 'fl-edit-le' : 'fl-le',
    texte: F.texte || '', poserTexte: e => maj({ texte: e.target.value }),
    appel: F.type === 'appel', types: [['note', 'Note'], ['appel', 'Appel']].map(([k, n]) => ({ nom: n, on: (F.type === 'appel') === (k === 'appel'), choisir: () => maj({ type: k }) })),
    tags: Object.keys(T).map(k => ({ cle: k, nom: T[k].nom, sens: T[k].sens, on: tags.includes(k), basculer: () => maj({ tags: tags.includes(k) ? tags.filter(t => t !== k) : tags.concat([k]) }) })),
    fils: [{ id: '', nom: tags.includes('gestion') ? '— aucun : ce problème ouvre un nouveau fil —' : '— aucun —', on: !F.fil }]
      .concat(fils.filter(f => f.ouvert || String(f.id) === String(F.fil || '')).map(f => ({ id: String(f.id), nom: f.titre + (f.ouvert ? ' · ouvert depuis ' + f.jours + ' j' : ' · résolu'), on: String(f.id) === String(F.fil || '') }))),
    poserFil: e => maj({ fil: e.target.value, ferme: e.target.value ? (F.ferme !== false) : F.ferme }),
    ferme: !!filChoisi && filChoisi.ouvert !== false && tags.includes('bonne') ? { on: F.ferme !== false, basculer: () => maj({ ferme: F.ferme === false }) } : null,
    le: F.le || '', poserLe: e => maj({ le: e.target.value }), leMax: maintenantLocal(),
    epingle: { on: !!F.epingle, nom: prochaine ? 'Épingler pour la visite du ' + jm(prochaine.le) : 'Épingler pour la prochaine visite', basculer: () => maj({ epingle: !F.epingle }) },
    pieces: (F.pieces || []).map((p, i) => ({ src: p.data, genre: p.genre === 'capture' ? 'capture' : 'photo', retirer: () => maj({ pieces: (courant().pieces || []).filter((_, j) => j !== i) }) }))
      .concat((F.garder || []).map(p => ({ src: API_BASE + p.url, genre: p.genre === 'capture' ? 'capture' : 'photo', retirer: () => maj({ garder: (courant().garder || []).filter(x => x.id !== p.id), retirer: (courant().retirer || []).concat([p.id]) }) }))),
    choisirFichiers: e => { ajouterPieces(app, e.target.files || [], null); e.target.value = ''; },
    deposer: e => { e.preventDefault(); e.stopPropagation(); ajouterPieces(app, imagesDe(e.dataTransfer), null); },
    envoi: envoi ? { enCours: !!envoi.enCours, ok: envoi.ok, msg: envoi.msg } : null,
  };
  const corps = () => ({ texte: F.texte || '', tags, le: F.le ? F.le.replace('T', ' ') : null, fil: F.fil ? Number(F.fil) : null, ferme: v.ferme ? v.ferme.on : undefined,
    epingle: !!F.epingle, type: F.type === 'appel' ? 'appel' : 'note', pieces: (F.pieces || []).map(p => ({ genre: p.genre, data: p.data })) });
  const pret = (String(F.texte || '').trim() || (F.pieces || []).length || (F.garder || []).length) && !(envoi && envoi.enCours);
  if (cle === 'flForm') {
    v.envoyer = pret ? () => {
      app.setState({ flEnvoi: { cle, enCours: true } });
      write(app.source || 'api', 'POST', '/fil/entrees', Object.assign({ shop: D.shop }, corps())).then(r => {
        const ko = !r || r.ok === false || r.error;
        app.setState({ flEnvoi: { cle, enCours: false, ok: !ko, msg: ko ? ((r && r.error) || 'refusé') : '' } });
        if (!ko) {
          app.setState({ flForm: { shop: D.shop }, flEnvoi: null, flCompoCal: false });
          app.notify(r.filOuvert ? 'Ajouté au fil — un fil s’ouvre pour ce problème de gestion.' : 'Ajouté au fil.');
          relire(app);
        }
      });
    } : null;
    v.vider = F.texte || (F.pieces || []).length || tags.length ? () => app.setState({ flForm: { shop: D.shop }, flEnvoi: null }) : null;
  } else {
    v.envoyer = pret ? () => {
      app.setState({ flEnvoi: { cle, enCours: true } });
      const c = corps(); c.retirer = F.retirer || []; c.fil = F.fil ? Number(F.fil) : null;
      write(app.source || 'api', 'PUT', '/fil/entrees/' + F.num, c).then(r => {
        const ko = !r || r.ok === false || r.error;
        app.setState({ flEnvoi: { cle, enCours: false, ok: !ko, msg: ko ? ((r && r.error) || 'refusé') : '' } });
        if (!ko) { app.setState({ flEdit: null, flEnvoi: null }); app.notify('Entrée modifiée.'); relire(app); }
      });
    } : null;
    v.annuler = () => app.setState({ flEdit: null, flEnvoi: null });
  }
  return v;
}

/* --- les entrées, telles que le gabarit les dessine --------------------------------- */

function entreeVue(app, D, e, opts){
  const T = D.tags || {};
  const s = (e.tags || []).find(k => SENS.includes(k));
  const sens = s ? T[s].sens : '';
  const v = { id: e.id, type: e.type, ico: e.type, sens, rond: e.type === 'visite' ? 'visite' : sens, jour: jourCourt(e.le), jourSeul: JC[dt(e.le).getDay()], date: jm(e.le),
    heure: String(e.le).slice(11, 16), on: opts && opts.jour === String(e.le).slice(0, 10), tags: (e.tags || []).map(k => ({ nom: (T[k] || {}).nom || k, sens: (T[k] || {}).sens || '' })) };
  if (e.source === 'fil') {
    const fil = e.fil ? (D.fils || []).find(f => f.id === e.fil) : null;
    Object.assign(v, { famille: 'note', typeNom: { note: 'Note', photo: 'Photo', capture: 'Capture', appel: 'Appel' }[e.type] || 'Note', qui: e.auteur, texte: e.texte,
      pieces: (e.pieces || []).map(p => ({ src: API_BASE + p.url, genre: p.genre })),
      fil: fil ? { titre: fil.titre, n: fil.entrees, ouvert: fil.ouvert, etat: fil.ouvert ? 'ouvert depuis ' + fil.jours + ' j' : 'résolu le ' + jm(fil.resolu) + ', en ' + fil.jours + ' j', ferme: fil.resoluPar === e.id, voir: () => app.setState({ flFil: fil.id }) } : null,
      epingle: e.epingle ? (D.prochaine ? 'épinglé pour la visite du ' + jm(D.prochaine.le) : 'épinglé pour la prochaine visite') : '',
      modifier: () => app.setState({ flEdit: { num: e.num, texte: e.texte, tags: (e.tags || []).slice(), le: String(e.le).replace(' ', 'T'), fil: e.fil ? String(e.fil) : '',
        ferme: fil ? fil.resoluPar === e.id : true, epingle: e.epingle, type: e.type === 'appel' ? 'appel' : 'note', pieces: [], garder: (e.pieces || []).slice(), retirer: [] }, flEnvoi: null }),
      effacer: () => {
        if (typeof window !== 'undefined' && !window.confirm('Effacer cette entrée du fil' + ((e.pieces || []).length ? ' et ses ' + pluriel(e.pieces.length, 'pièce') : '') + ' ?')) { return; }
        write(app.source || 'api', 'DELETE', '/fil/entrees/' + e.num).then(r => { if (r && r.ok !== false) { app.notify('Entrée effacée.'); relire(app); } else { app.notify('Effacement refusé : ' + ((r && r.error) || 'pas de réponse')); } });
      },
      enEdition: app.state.flEdit && app.state.flEdit.num === e.num });
  } else if (e.type === 'visite') {
    const fait = e.statut === 'terminee';
    Object.assign(v, { famille: 'visite', typeNom: 'Visite', qui: e.consultant, titre: e.titre, statut: STATUTS_VISITE[e.statut] || e.statut, fait,
      prevue: !fait && e.statut !== 'annulee', annulee: e.statut === 'annulee', score: e.score, delta: e.delta,
      det: [String(e.le).slice(11, 16) + (e.duree ? ' · ' + e.duree + ' min' : ''), e.photos ? pluriel(e.photos, 'photo') : '', e.points && e.points.length ? pluriel(e.points.length, 'point décidé', 'points décidés') : ''].filter(Boolean).join(' · '),
      points: (e.points || []).slice(0, 6).map(p => ({ prio: p.priorite, titre: p.titre, statut: STATUTS_POINT[p.statut] || p.statut, ouvert: ['ouvert', 'reprendre', 'escalade', 'attente'].includes(p.statut) })),
      autres: Math.max(0, (e.points || []).length - 6),
      ouvrir: () => app.setState({ screen: 'suiviTableau', svSel: { kind: 'visite', id: e.visite, garde: true }, svPas: fait ? 'rapport' : 'dossier', svPrepF: null, svRapF: null, svVisF: null, railOuvert: false }) });
  } else if (e.type === 'rapport') {
    Object.assign(v, { famille: 'visite', typeNom: 'Rapport envoyé', qui: e.par, titre: e.titre,
      det: [e.nbPoints ? pluriel(e.nbPoints, 'point à travailler', 'points à travailler') : '', e.lu ? 'lu par le franchisé le ' + jm(e.lu) + ' à ' + String(e.lu).slice(11, 16) : 'pas encore lu'].filter(Boolean).join(' · '),
      ouvrir: () => app.setState({ screen: 'suiviTableau', svSel: { kind: 'visite', id: e.visite, garde: true }, svPas: 'rapport', svPrepF: null, svRapF: null, svVisF: null, railOuvert: false }) });
  } else if (e.type === 'mystere') {
    Object.assign(v, { famille: 'mystere', typeNom: 'Client mystère', qui: '', titre: e.titre + (e.score != null ? ' · ' + e.score + ' / 100' : ''), det: e.commentaire || '' });
  } else if (e.type === 'demande') {
    Object.assign(v, { famille: 'demande', typeNom: 'Demande du franchisé', qui: e.qui, titre: e.titre, det: e.det,
      ouvrir: () => app.setState({ screen: 'demandesFranchises', hdSel: e.num, hdFiltre: 'toutes', railOuvert: false }) });
  }
  return v;
}

/** Les compteurs d'une liste d'entrées : par étiquette de sens, et le résumé des types. */
function resume(l){
  const n = t => l.filter(e => e.type === t).length;
  const notes = l.filter(e => TYPES_NOTE.includes(e.type)).length;
  const photos = l.reduce((k, e) => k + (e.pieces || []).length, 0);
  const x = [];
  if (n('visite')) { x.push(pluriel(n('visite'), 'visite')); }
  if (n('rapport')) { x.push(pluriel(n('rapport'), 'rapport')); }
  if (notes) { x.push(pluriel(notes, 'note')); }
  if (photos) { x.push(pluriel(photos, 'pièce')); }
  if (n('demande')) { x.push(pluriel(n('demande'), 'demande')); }
  if (n('mystere')) { x.push(pluriel(n('mystere'), 'client mystère', 'clients mystère')); }
  return x.join(' · ');
}
const compteTag = (l, k) => l.filter(e => (e.tags || []).includes(k)).length;

/* --- l'écran -------------------------------------------------------------------- */

export function valsFil(app, c){
  const S = app.state;
  ecouterColler(app);
  chargerMags(app, false);
  const M = S.flMags || {};
  const v = { chargement: !!M.chargement && !M.d, erreur: M.d && M.d.error ? M.d.error : null };
  c.fl = v;
  if (!M.d || M.d.error) { return; }
  const mags = M.d.magasins || [];
  const tagsDef = M.d.tags || {};
  // Le magasin : celui de l'adresse, sinon le dernier ouvert, sinon le plus récemment noté.
  let shop = S.flMag && mags.some(m => m.id === String(S.flMag)) ? String(S.flMag) : '';
  if (!shop && mags.length) {
    shop = mags.slice().sort((a, b) => String(b.dernier || '').localeCompare(String(a.dernier || '')))[0].id;
    S.flMag = shop;
    if (app.adresseSansHistorique) { app.adresseSansHistorique(); }
  }
  const annee = S.flAnnee || +auj().slice(0, 4);
  // Les cartes.
  v.cartes = mags.map(m => ({ id: m.id, ville: m.court, nom: nomFranchise(m.nom, m.court), consultant: m.consultant || '', on: m.id === shop,
    gestion: m.mois.gestion, bonne: m.mois.bonne, surveiller: m.mois.surveiller, tags: tagsDef,
    semaines: (m.semaines || []).map(x => ({ g: x.includes('g'), s: x.includes('s'), b: x.includes('b'), n: x.includes('n'), v: x.includes('v') })),
    sDe: m.premiereSemaine, sA: m.derniereSemaine, ouverts: m.filsOuverts,
    dernier: m.dernier ? (m.dernier.slice(0, 10) === auj() ? 'aujourd’hui' : jm(m.dernier)) : '—',
    visite: m.prochaine ? jourCourt(m.prochaine.le) : '—',
    choisir: () => app.setState({ flMag: m.id, flFil: null, flEdit: null, flForm: { shop: m.id }, flEnvoi: null, flJour: null, flCompoCal: false }) }));
  v.mois = ML[+auj().slice(5, 7) - 1].toLowerCase();
  if (!shop) { return; }
  chargerFil(app, shop, annee, false);
  chargerDemandes(app, shop);
  const FD = S.flData || {};
  v.filChargement = !!FD.chargement && !FD.d;
  v.filErreur = FD.d && FD.d.error ? FD.d.error : null;
  v.relecture = !!FD.chargement && !!FD.d;
  if (!FD.d || FD.d.error || FD.shop !== shop) { v.mag = (mags.find(m => m.id === shop) || {}); return; }
  const D = FD.d;
  // Une image lâchée n'importe où sur le fil rejoint l'entrée en cours, comme celle lâchée sur le formulaire.
  v.deposer = e => { e.preventDefault(); ajouterPieces(app, imagesDe(e.dataTransfer), null); };
  const maintenant = D.aujourdhui || (auj() + ' 23:59');
  // Les demandes du helpdesk, lues à part (le panel ne retarde pas le fil).
  const DM = S.flDem && S.flDem.shop === shop ? S.flDem : null;
  const demandes = DM && DM.d && Array.isArray(DM.d.demandes) ? DM.d.demandes.filter(d => String(d.cree || '').slice(0, 4) === String(annee)).map(d => ({
    id: 'd' + d.id, num: d.id, source: 'helpdesk', type: 'demande', le: String(d.cree).slice(0, 16).replace('T', ' '), titre: d.titre, qui: '',
    det: [d.domaineNom, d.statutNom, d.reponse ? 'répondue' : (d.ouverte ? 'sans réponse' : '')].filter(Boolean).join(' · ') })) : [];
  v.demandesNote = DM && DM.chargement ? 'demandes du helpdesk : lecture…' : (DM && DM.d && DM.d.erreur ? 'demandes du helpdesk : ' + DM.d.erreur : (DM && !DM.d ? 'demandes du helpdesk : pas de réponse' : ''));
  const tout = (D.entrees || []).concat(demandes).sort((a, b) => String(b.le).localeCompare(String(a.le)) || String(b.id).localeCompare(String(a.id)));
  // Les filtres : étiquettes, familles, un fil.
  const fTags = S.flTags || [];
  const caches = S.flCaches || {};
  const famille = e => TYPES_NOTE.includes(e.type) ? 'note' : (e.type === 'visite' || e.type === 'rapport' ? 'visite' : e.type);
  const passe = e => !caches[famille(e)] && (!fTags.length || (e.tags || []).some(k => fTags.includes(k))) && (!S.flFil || e.fil === S.flFil);
  const L = tout.filter(passe);
  const passees = L.filter(e => e.le <= maintenant);
  const aVenir = L.filter(e => e.le > maintenant);
  v.mag = { id: shop, nom: nomFranchise(D.magasin.nom, D.magasin.court), court: D.magasin.court, consultant: (mags.find(m => m.id === shop) || {}).consultant || '' };
  v.vue = S.flVue === 'calendrier' ? 'calendrier' : 'fil';
  v.vues = [['fil', 'Fil'], ['calendrier', 'Calendrier']].map(([k, n]) => ({ nom: n, on: v.vue === k, choisir: () => app.setState({ flVue: k, flEdit: null }) }));
  v.relire = () => relire(app);
  v.annee = annee;
  v.annees = (D.annees || [annee]).map(a => ({ id: a, on: a === annee }));
  v.poserAnnee = e => app.setState({ flAnnee: +e.target.value, flMois: null, flJour: null, flOuverts: {} });
  // Le formulaire d'ajout.
  const FF = S.flForm && S.flForm.shop === shop ? S.flForm : { shop };
  v.form = formVue(app, D, 'flForm', FF, null);
  v.edit = S.flEdit ? formVue(app, D, 'flEdit', S.flEdit, null) : null;
  v.filtreFil = S.flFil ? Object.assign({}, (D.fils || []).find(f => f.id === S.flFil) || { titre: 'fil' }, { quitter: () => app.setState({ flFil: null }) }) : null;
  v.filtres = fTags.length || Object.keys(caches).some(k => caches[k]) || S.flFil ? { n: L.length, sur: tout.length, effacer: () => app.setState({ flTags: [], flCaches: {}, flFil: null }) } : null;
  const ev = (e, o) => entreeVue(app, D, e, o);
  // La colonne de droite (fil) : lire, étiquettes, familles, fils, prochaine visite.
  const anneeL = tout.filter(e => e.le <= maintenant);
  v.groupes = [['semaine', 'Semaine'], ['mois', 'Mois'], ['annee', 'Année']].map(([k, n]) => ({ nom: n, on: (S.flGroupe || 'semaine') === k, choisir: () => app.setState({ flGroupe: k, flOuverts: {} }) }));
  v.filtresTags = Object.keys(D.tags || {}).map(k => ({ nom: D.tags[k].nom, sens: D.tags[k].sens, n: compteTag(anneeL, k), on: fTags.includes(k),
    basculer: () => app.setState({ flTags: fTags.includes(k) ? fTags.filter(t => t !== k) : fTags.concat([k]) }) }));
  v.familles = GROUPES_TYPES.map(([k, n]) => ({ nom: n, ico: k === 'visite' ? 'visite' : k, n: anneeL.filter(e => famille(e) === k).length, on: !caches[k],
    basculer: () => app.setState({ flCaches: Object.assign({}, caches, { [k]: !caches[k] }) }) }));
  const fils = D.fils || [];
  const filVue = f => {
    const es = (D.entrees || []).filter(e => e.fil === f.id).sort((a, b) => String(a.le).localeCompare(String(b.le)));
    return { id: f.id, titre: f.titre, ouvert: f.ouvert, dates: es.map(e => jm(e.le)).join(' → '), etat: f.ouvert ? 'ouvert depuis ' + f.jours + ' j' : 'résolu le ' + jm(f.resolu) + ', en ' + f.jours + ' j',
      etapes: es.map(e => { const s = (e.tags || []).find(k => SENS.includes(k)); return s ? (D.tags[s] || {}).sens || '' : 'sys'; }), on: S.flFil === f.id,
      voir: () => app.setState({ flFil: S.flFil === f.id ? null : f.id, flVue: 'fil' }),
      basculer: () => write(app.source || 'api', 'POST', '/fil/fils/' + f.id, { resolu: f.ouvert }).then(r => { if (r && r.ok !== false) { app.notify(f.ouvert ? 'Fil résolu.' : 'Fil rouvert.'); relire(app); } else { app.notify('Refusé : ' + ((r && r.error) || 'pas de réponse')); } }) };
  };
  v.filsOuverts = fils.filter(f => f.ouvert).map(filVue);
  const resolus = fils.filter(f => !f.ouvert);
  v.filsResolus = resolus.map(filVue);
  v.voirResolus = !!S.flVoirResolus; v.basculerResolus = () => app.setState({ flVoirResolus: !S.flVoirResolus });
  v.moyenneResolus = resolus.length ? Math.round(resolus.reduce((n, f) => n + f.jours, 0) / resolus.length) : null;
  const P = D.prochaine;
  v.prochaine = P ? { quand: jourCourt(P.le) + ' · ' + P.h, qui: P.consultant, statut: STATUTS_VISITE[P.statut] || P.statut,
    epingles: (D.entrees || []).filter(e => e.source === 'fil' && e.epingle).length,
    ouvrir: () => app.setState({ screen: 'suiviTableau', svSel: { kind: 'visite', id: P.id, garde: true }, svPas: 'dossier', svPrepF: null, svRapF: null, svVisF: null, railOuvert: false }) } : null;
  v.demandesLien = () => app.setState({ screen: 'demandesFranchises', hdMag: shop, railOuvert: false });

  if (v.vue === 'fil') { valsFilVertical(app, v, passees, aVenir, ev, annee, maintenant); }
  else { valsCalendrier(app, v, tout.filter(passe), ev, D, annee, FF); }
}

/* --- A · le fil vertical ----------------------------------------------------------- */

function valsFilVertical(app, v, passees, aVenir, ev, annee, maintenant){
  const S = app.state;
  const groupe = S.flGroupe || 'semaine';
  const ouverts = S.flOuverts || {};
  const basculer = k => () => app.setState({ flOuverts: Object.assign({}, ouverts, { [k]: !ouverts[k] }) });
  const sections = [];
  if (aVenir.length) { sections.push({ cle: 'avenir', titre: 'À venir', sous: aVenir.length === 1 ? '' : pluriel(aVenir.length, 'rendez-vous'), entrees: aVenir.slice().reverse().map(e => ev(e)), avenir: true }); }
  const parMois = {};
  passees.forEach(e => { const m = String(e.le).slice(0, 7); (parMois[m] = parMois[m] || []).push(e); });
  const semaineSection = (l, lu, extra) => ({ cle: 's' + lu, titre: 'Semaine ' + semaine(lu), sous: bornes(lu) + (extra || '') + (l.length ? ' · ' + resume(l) : ''), compte: sensCompte(l), entrees: l.map(e => ev(e)), vide: !l.length });
  const semainesDe = l => { const g = {}; l.forEach(e => { const k = lundi(String(e.le).slice(0, 10)); (g[k] = g[k] || []).push(e); }); return Object.keys(g).sort().reverse().map(k => semaineSection(g[k], k)); };
  const moisLigne = m => { const l = parMois[m] || []; return { cle: 'm' + m, mois: true, titre: ML[+m.slice(5, 7) - 1], sous: resume(l) || 'rien de noté', compte: sensCompte(l), ouvert: !!ouverts[m], basculer: basculer(m),
    semaines: ouverts[m] ? semainesDe(l) : [] }; };
  const finAnnee = String(annee) === maintenant.slice(0, 4) ? maintenant.slice(0, 10) : annee + '-12-31';
  if (groupe === 'semaine') {
    // Les quatre dernières semaines ouvertes (même vides), puis un mois par ligne.
    const l0 = lundi(finAnnee);
    const debut = plusJ(l0, -21);
    for (let i = 0; i < 4; i++) {
      const lu = plusJ(l0, -7 * i);
      const l = passees.filter(e => String(e.le).slice(0, 10) >= lu && String(e.le).slice(0, 10) <= plusJ(lu, 6));
      sections.push(semaineSection(l, lu, i === 0 && finAnnee === maintenant.slice(0, 10) ? ' · cette semaine' : ''));
    }
    const avant = passees.filter(e => String(e.le).slice(0, 10) < debut);
    const moisAvant = {}; avant.forEach(e => { const m = String(e.le).slice(0, 7); (moisAvant[m] = moisAvant[m] || []).push(e); });
    const ms = [];
    if (debut.slice(0, 4) === String(annee)) {
      const mDeb = +debut.slice(5, 7);
      for (let m = mDeb; m >= 1; m--) { if (!(m === mDeb && debut.slice(8, 10) === '01')) { ms.push(annee + '-' + pad(m)); } }
    }
    if (ms.length) { sections.push({ cle: 'plus-tot', titre: 'Plus tôt en ' + annee, sous: 'un mois par ligne, un clic le déplie', legende: true }); }
    ms.forEach(m => {
      const l = moisAvant[m] || []; const x = moisLigne(m);
      x.sous = resume(l) || 'rien de noté'; x.compte = sensCompte(l); x.semaines = ouverts[m] ? semainesDe(l) : [];
      x.debut = m === debut.slice(0, 7) ? '1 – ' + (+debut.slice(8, 10) - 1) : '';
      sections.push(x);
    });
  } else if (groupe === 'mois') {
    Object.keys(parMois).sort().reverse().forEach(m => { const l = parMois[m]; sections.push({ cle: 'M' + m, titre: ML[+m.slice(5, 7) - 1] + ' ' + annee, sous: resume(l), compte: sensCompte(l), entrees: l.map(e => ev(e)) }); });
    if (!Object.keys(parMois).length) { sections.push({ cle: 'vide', titre: 'Rien en ' + annee, sous: 'aucune entrée pour ces filtres', entrees: [] }); }
  } else {
    sections.push({ cle: 'annee', titre: 'L’année ' + annee, sous: 'douze lignes, un clic sur un mois le déplie', legende: true });
    for (let m = +finAnnee.slice(5, 7); m >= 1; m--) { sections.push(moisLigne(annee + '-' + pad(m))); }
  }
  v.sections = sections;
}
function sensCompte(l){ return { gestion: compteTag(l, 'gestion'), surveiller: compteTag(l, 'surveiller'), bonne: compteTag(l, 'bonne') }; }

/* --- B · le calendrier --------------------------------------------------------------- */

function valsCalendrier(app, v, L, ev, D, annee, FF){
  const S = app.state;
  const today = auj();
  const vueCal = S.flCal === 'annee' ? 'annee' : 'mois';
  v.cal = vueCal;
  v.cals = [['mois', 'Mois'], ['annee', 'Année']].map(([k, n]) => ({ nom: n, on: vueCal === k, choisir: () => app.setState({ flCal: k }) }));
  const moisDef = String(annee) === today.slice(0, 4) ? today.slice(0, 7) : annee + '-12';
  const mois = S.flMois && S.flMois.slice(0, 4) === String(annee) ? S.flMois : moisDef;
  const jour = S.flJour && S.flJour.slice(0, 4) === String(annee) ? S.flJour : (mois === today.slice(0, 7) ? today : mois + '-01');
  const decaler = n => {
    const d = new Date(+mois.slice(0, 4), +mois.slice(5, 7) - 1 + n, 1); const m = d.getFullYear() + '-' + pad(d.getMonth() + 1);
    app.setState({ flMois: m, flJour: null, flAnnee: d.getFullYear() !== annee ? d.getFullYear() : annee });
  };
  v.moisNom = ML[+mois.slice(5, 7) - 1] + ' ' + mois.slice(0, 4);
  v.precedent = () => decaler(-1); v.suivant = () => decaler(1);
  v.aujourdhui = () => app.setState({ flMois: today.slice(0, 7), flJour: today, flAnnee: +today.slice(0, 4), flCal: 'mois' });
  const parJour = {};
  L.forEach(e => { const d = String(e.le).slice(0, 10); (parJour[d] = parJour[d] || []).push(e); });
  Object.keys(parJour).forEach(d => parJour[d].sort((a, b) => String(a.le).localeCompare(String(b.le))));
  const T = D.tags || {};
  const puce = e => {
    const s = (e.tags || []).find(k => SENS.includes(k));
    if (e.type === 'visite') { return { cls: e.statut === 'terminee' ? 'visite' : 'prevue', ico: 'visite', nom: e.statut === 'terminee' ? 'Visite' + (e.score != null ? ' · ' + Math.round(e.score) : '') : 'Visite ' + String(e.le).slice(11, 16) }; }
    if (e.type === 'rapport') { return { cls: 'sys', ico: 'rapport', nom: 'Rapport' }; }
    if (e.type === 'mystere') { return { cls: 'sys', ico: 'mystere', nom: 'Mystère' + (e.score != null ? ' · ' + e.score : '') }; }
    if (e.type === 'demande') { return { cls: 'sys', ico: 'demande', nom: 'Demande' }; }
    const k = s || (e.tags || [])[0];
    return { cls: s ? T[s].sens : '', ico: e.type, nom: k ? ({ gestion: 'Problème', bonne: 'Bonne action', surveiller: 'À surveiller' }[k] || (T[k] || {}).nom || k) : ({ note: 'Note', photo: 'Photo', capture: 'Capture', appel: 'Appel' }[e.type] || 'Note') };
  };
  if (vueCal === 'mois') {
    const fin = iso(new Date(+mois.slice(0, 4), +mois.slice(5, 7), 0));
    const semaines = [];
    for (let lu = lundi(mois + '-01'); lu <= fin; lu = plusJ(lu, 7)) {
      const jours = [];
      for (let j = 0; j < 7; j++) {
        const d = plusJ(lu, j); const l = parJour[d] || [];
        const pieces = l.flatMap(e => (e.pieces || []).map(p => API_BASE + p.url)).slice(0, 3);
        jours.push({ d, n: +d.slice(8, 10), hors: d.slice(0, 7) !== mois, auj: d === today, on: d === jour, futur: d > today,
          puces: l.slice(0, 3).map(puce), plus: Math.max(0, l.length - 3), pieces,
          choisir: () => app.setState({ flJour: d, flCompoCal: false }) });
      }
      const l0 = lu;
      semaines.push({ n: semaine(lu), on: jour >= lu && jour <= plusJ(lu, 6), jours, choisir: () => app.setState({ flJour: l0, flCompoCal: false }) });
    }
    v.semainesCal = semaines;
    // La semaine du jour choisi, en petit fil.
    const lj = lundi(jour);
    const ls = L.filter(e => String(e.le).slice(0, 10) >= lj && String(e.le).slice(0, 10) <= plusJ(lj, 6)).sort((a, b) => String(b.le).localeCompare(String(a.le)));
    v.semaineCal = { titre: 'Semaine ' + semaine(lj) + ' · ' + bornes(lj), sous: (resume(ls) || 'rien de noté') + ' — ' + JL[dt(jour).getDay()] + ' ' + (+jour.slice(8, 10)) + ' ' + MC[+jour.slice(5, 7) - 1] + ' ouvert',
      compte: sensCompte(ls), entrees: ls.map(e => ev(e, { jour })), jourNom: JC[dt(jour).getDay()] + ' ' + (+jour.slice(8, 10)),
      compo: !!S.flCompoCal,
      ajouter: () => app.setState({ flCompoCal: true, flForm: Object.assign({}, FF, { shop: FF.shop, le: jour === today ? '' : jour + 'T12:00' }) }),
      fermer: () => app.setState({ flCompoCal: false }) };
  } else {
    const ms = [];
    for (let m = 1; m <= 12; m++) {
      const k = annee + '-' + pad(m);
      const nbJ = new Date(annee, m, 0).getDate();
      const dec = (new Date(annee, m - 1, 1).getDay() + 6) % 7;
      const jours = [];
      for (let i = 0; i < dec; i++) { jours.push({ vide: true }); }
      let nv = 0;
      for (let j = 1; j <= nbJ; j++) {
        const d = k + '-' + pad(j); const l = parJour[d] || [];
        const vis = l.find(e => e.type === 'visite');
        if (vis && vis.statut === 'terminee') { nv++; }
        const t = l.flatMap(e => e.tags || []);
        const cls = vis ? (vis.statut === 'terminee' ? 'v' : 'p') : t.includes('gestion') ? 'g' : t.includes('surveiller') ? 's' : t.includes('bonne') ? 'b' : l.length ? 'n' : (d > today ? 'f' : '');
        jours.push({ cls, auj: d === today, titre: jm(d) + (l.length ? ' · ' + resume(l) : ''), choisir: () => app.setState({ flCal: 'mois', flMois: k, flJour: d }) });
      }
      const lm = L.filter(e => String(e.le).slice(0, 7) === k);
      ms.push({ nom: ML[m - 1], on: k === today.slice(0, 7), visites: nv, jours, gestion: compteTag(lm, 'gestion'), surveiller: compteTag(lm, 'surveiller'), bonne: compteTag(lm, 'bonne'),
        choisir: () => app.setState({ flCal: 'mois', flMois: k, flJour: null }) });
    }
    v.moisAnnee = ms;
  }
}
