/* Cockpit — Franchisés › Formations continues (#/franchises/formations, 22/11/2026).
 * Maquette : formation/docs/maquettes/formation-continue/02-cockpit-consultant.png.
 *
 * Le consultant voit les formations continues de SES franchisés (cadre de visite ; « Tout le réseau » pour le siège),
 * les VÉRIFIE (le contenu tel que la boutique le lit, l'équipe, les photos qualité jour par jour, qu'il relit
 * conforme / à refaire), les ADAPTE pour une boutique (le texte des étapes, qui la suit, l'échéance, une note) et les
 * VALIDE : son nom et l'heure entrent au journal, l'archive au coffre-fort du franchisé (formation/src/formation_continue.php).
 *   GET  /formation/continue/franchises?consultant=   GET /formation/continue/franchise?shop=   GET /formation/continue/dossier?id=
 *   PUT  /formation/continue/dossier   POST …/dossier/avis   POST …/dossier/valider   POST …/dossier/renvoyer
 *
 * Remplit `common.fcc` ; gabarit : formation-continue-tpl.js ; style : formation-continue.css (fcc-). */
import { readOne, write, API_BASE } from './api.js';

const jjmm = s => s ? String(s).slice(8, 10) + '/' + String(s).slice(5, 7) : '';
const jma = s => s ? jjmm(s) + '/' + String(s).slice(0, 4) : '—';
const CLE_CONS = 'fcc.consultant';
const consLu = () => { try { return localStorage.getItem(CLE_CONS); } catch (e) { return null; } };
const t = v => v && typeof v === 'object' ? (v.fr || v.nl || '') : String(v || '');

function chargerF(app, cons, force){
  const b = app.state.fccF;
  if (!force && b && b.cons === cons && (b.d || b.chargement)) { return; }
  app.setState({ fccF: { cons, chargement: true, d: b && b.cons === cons ? b.d : null } });
  readOne('/formation/continue/franchises' + (cons ? '?consultant=' + encodeURIComponent(cons) : '')).then(d => app.setState({ fccF: { cons, chargement: false, d: d || { error: 'injoignable' } } }));
}
function chargerD(app, shop, force){
  const b = app.state.fccD;
  if (!shop || (!force && b && b.shop === shop && (b.d || b.chargement))) { return; }
  app.setState({ fccD: { shop, chargement: true, d: b && b.shop === shop ? b.d : null } });
  readOne('/formation/continue/franchise?shop=' + encodeURIComponent(shop)).then(d => app.setState({ fccD: { shop, chargement: false, d: d || { error: 'injoignable' } } }));
}
function chargerX(app, id, force){
  const b = app.state.fccX;
  if (!id || (!force && b && b.id === id && (b.d || b.chargement))) { return; }
  app.setState({ fccX: { id, chargement: true, d: b && b.id === id ? b.d : null }, fccAd: null });
  readOne('/formation/continue/dossier?id=' + id).then(d => app.setState({ fccX: { id, chargement: false, d: d || { error: 'injoignable' } } }));
}
/** Après une écriture : le dossier, la boutique et la liste des franchisés se relisent. */
function relire(app){ const S = app.state; chargerX(app, S.fccX && S.fccX.id, true); chargerD(app, S.fccD && S.fccD.shop, true); chargerF(app, S.fccF ? S.fccF.cons : '', true); }
function agir(app, methode, chemin, corps, ok){
  if (app.state.fccOcc) { return; }
  app.setState({ fccOcc: true, fccMsg: null });
  write('api', methode, chemin, corps).then(r => {
    app.setState({ fccOcc: false, fccMsg: r.ok ? { ok: true, txt: ok(r) } : { ok: false, txt: r.error || ('HTTP ' + r.status) } });
    if (r.ok) { relire(app); }
  });
}

export function valsFCC(app, common){
  const S = app.state;
  const cons = S.fccCons != null ? S.fccCons : (consLu() || '');
  chargerF(app, cons, false);
  const F = S.fccF && S.fccF.cons === cons && S.fccF.d && !S.fccF.d.error ? S.fccF.d : null;
  const franchises = F ? F.franchises : [];
  const shop = S.fccShop && franchises.some(f => f.shop === S.fccShop) ? S.fccShop : (franchises[0] ? franchises[0].shop : null);
  chargerD(app, shop, false);
  const D = S.fccD && S.fccD.shop === shop && S.fccD.d && !S.fccD.d.error ? S.fccD.d : null;
  const cat = S.fccCat || '';
  const dossiers = D ? D.dossiers.filter(d => !cat || d.categorie === cat) : [];
  const sel = S.fccSel && D && D.dossiers.some(d => d.id === S.fccSel) ? S.fccSel : (dossiers[0] ? dossiers[0].id : null);
  chargerX(app, sel, false);
  const X = S.fccX && S.fccX.id === sel && S.fccX.d && !S.fccX.d.error ? S.fccX.d : null;
  // Le brouillon d'adaptation : le texte de chaque étape (la version actuelle de la boutique), qui la suit, l'échéance, la note.
  const etapesDe = c => (c && c.sections ? c.sections : []).flatMap((s, si) => (s.etapes || []).map((e, ei) => ({ si, ei, numero: e.numero, titre: t(e.titre), texte: t(e.texte), critique: !!e.critique })));
  const ad = X && S.fccAd && S.fccAd.id === X.id ? S.fccAd : (X ? { id: X.id, ouvert: false, etapes: etapesDe(X.contenu).map(e => e.texte), assignes: X.assignes.join(', '), echeance: X.echeance || '', note: (X.adaptation && X.adaptation.note) || '' } : null);
  const majAd = o => app.setState({ fccAd: Object.assign({}, ad, o) });
  const etapes = X ? etapesDe(X.contenu) : [];
  const modele = X ? etapesDe(X.modele) : [];
  common.fcc = {
    chargement: !F && !(S.fccF && S.fccF.d && S.fccF.d.error), erreur: S.fccF && S.fccF.d && S.fccF.d.error ? 'Les formations ne se lisent pas : ' + S.fccF.d.error : '',
    msg: S.fccMsg || null, occ: !!S.fccOcc,
    consultants: [{ v: '', nom: 'Tout le réseau (siège)', on: cons === '' }].concat((F ? F.consultants : []).map(c => ({ v: c.id, nom: c.nom, on: c.id === cons }))),
    setCons: e => { try { localStorage.setItem(CLE_CONS, e.target.value); } catch (er) { /* stockage refusé */ } app.setState({ fccCons: e.target.value, fccShop: null, fccSel: null }); },
    consNom: cons ? ((F && F.consultant && F.consultant.nom) || cons) : '',
    reperes: F ? F.reperes : null, nbFranchises: franchises.length,
    franchises: franchises.map(f => Object.assign({}, f, { on: f.shop === shop, choisir: () => app.setState({ fccShop: f.shop, fccSel: null, fccMsg: null }) })),
    shopNom: D ? D.nom : '', chargementD: !D && !!shop,
    cats: [{ v: '', nom: 'Toutes', n: D ? D.dossiers.length : 0 }].concat(Object.entries(D ? D.categories : {}).map(([v, nom]) => ({ v, nom, n: D.dossiers.filter(d => d.categorie === v).length })))
      .map(o => Object.assign(o, { on: o.v === cat, choisir: () => app.setState({ fccCat: o.v, fccSel: null }) })),
    dossiers: dossiers.map(d => Object.assign({}, d, { on: d.id === sel, choisir: () => app.setState({ fccSel: d.id, fccMsg: null }), echeanceTxt: d.echeance ? jjmm(d.echeance) : '—' })),
    aVenir: D ? D.aVenir : [],
    x: X ? Object.assign({}, X, {
      etapes, modele, adapteTxt: X.adapte ? 'adaptée' + (X.adaptation.par ? ' par ' + X.adaptation.par : '') + (X.adaptation.le ? ' le ' + jma(X.adaptation.le) : '') + (X.adaptation.note ? ' : « ' + X.adaptation.note + ' »' : '') : '',
      ecarts: etapes.map((e, i) => modele[i] && modele[i].texte !== e.texte ? { numero: e.numero, avant: modele[i].texte, apres: e.texte } : null).filter(Boolean),
      grille: X.photos.grille.map(g => Object.assign({}, g, { jm: jjmm(g.jour), src: g.photo ? API_BASE + '/formation/continue/photo?id=' + g.photo : null,
        conforme: g.photo ? () => agir(app, 'POST', '/formation/continue/dossier/avis', { photo: g.photo, avis: 'conforme' }, () => 'Photo du ' + jjmm(g.jour) + ' : conforme.') : null,
        refaire: g.photo ? () => { const c = window.prompt('Ce qu’il faut refaire (le franchisé le lit) :', ''); if (c === null) { return; } agir(app, 'POST', '/formation/continue/dossier/avis', { photo: g.photo, avis: 'a_refaire', commentaire: c }, () => 'Photo du ' + jjmm(g.jour) + ' : à refaire.'); } : null })),
      validationTxt: X.validation ? 'validée par ' + X.validation.par + ' le ' + jma(X.validation.le) + ' à ' + String(X.validation.le).slice(11, 16) : '',
      journal: X.journal.map(j => Object.assign({}, j, { quand: jjmm(j.le) + ' ' + String(j.le).slice(11, 16) })),
    }) : null,
    chargementX: !X && !!sel,
    ad: ad ? Object.assign({}, ad, {
      ouvrir: () => majAd({ ouvert: !ad.ouvert }), setEtape: i => e => { const l = ad.etapes.slice(); l[i] = e.target.value; app.state.fccAd = Object.assign({}, ad, { etapes: l }); },
      setAssignes: e => { app.state.fccAd = Object.assign({}, app.state.fccAd || ad, { assignes: e.target.value }); },
      setEcheance: e => majAd({ echeance: e.target.value }), setNote: e => { app.state.fccAd = Object.assign({}, app.state.fccAd || ad, { note: e.target.value }); },
      enregistrer: () => { app.state.fccAd = app.state.fccAd || ad; enregistrerAvec(app.state.fccAd); },
      modele: () => { if (!window.confirm('Revenir au modèle du réseau pour ' + (D ? D.nom : 'cette boutique') + ' ? Le texte adapté est retiré (il reste au journal).')) { return; } agir(app, 'PUT', '/formation/continue/dossier', { id: X.id, contenu: null }, () => 'Revenue au modèle du réseau.'); },
    }) : null,
    mot: S.fccMot || '', setMot: e => { app.state.fccMot = e.target.value; },
    valider: () => { if (!X) { return; } const forcer = X.statut !== 'a_valider'; if (forcer && !window.confirm('La formation n’est pas terminée (' + X.avancement.pct + ' %). La valider quand même pour ' + (D ? D.nom : 'cette boutique') + ' ?')) { return; }
      agir(app, 'POST', '/formation/continue/dossier/valider', { id: X.id, mot: app.state.fccMot || '', forcer }, r => 'Validée pour ' + (D ? D.nom : 'la boutique') + (r.archive ? ' ; archivée au coffre-fort (onglet Formation).' : '.')); app.state.fccMot = ''; },
    renvoyer: () => { if (!X) { return; } const m = window.prompt('Pourquoi la renvoyer en cours ? (le franchisé le lit au journal)', ''); if (m === null) { return; } agir(app, 'POST', '/formation/continue/dossier/renvoyer', { id: X.id, motif: m }, () => 'Renvoyée en cours.'); },
    relire: () => relire(app),
  };
  // L'enregistrement lit le brouillon tel qu'il est au clic (les champs écrivent dans l'état sans redessiner).
  function enregistrerAvec(a){
    const c = JSON.parse(JSON.stringify(X.contenu));
    etapesDe(X.contenu).forEach((e, i) => { const v = String(a.etapes[i] == null ? e.texte : a.etapes[i]).trim(); c.sections[e.si].etapes[e.ei].texte = { fr: v, nl: (c.sections[e.si].etapes[e.ei].texte || {}).nl || '' }; });
    const corps = { id: X.id, assignes: String(a.assignes || '').split(',').map(s => s.trim()).filter(Boolean), echeance: a.echeance || null, note: String(a.note || '').trim() };
    if (JSON.stringify(c) !== JSON.stringify(X.contenu)) { corps.contenu = c; }
    app.state.fccAd = Object.assign({}, a, { ouvert: false });
    agir(app, 'PUT', '/formation/continue/dossier', corps, () => 'Adaptée pour ' + (D ? D.nom : 'la boutique') + ' : tracée au journal.');
  }
}

/** Le chiffre du rail : les formations à valider (de ce consultant, ou du réseau). */
export function fccBadge(app){ const F = app.state.fccF && app.state.fccF.d; return F && F.reperes ? F.reperes.aValider || 0 : 0; }
