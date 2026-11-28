/* Dashboard magasin › Mon coffre-fort — v2 (22/11/2026, maquettes consultant/docs/maquettes/coffre-fort-v2/).
 *
 * Sam : « chaque franchisé a un coffre-fort avec ses documents » (v1), puis, devant le coffre vide de Halle : « refaire
 * l'UI/UX ». La v2 dit d'abord ce qui compte, puis montre les documents :
 *   - l'ÉTAT DU COFFRE : la complétude (les pièces attendues reçues, sur le total), À TRAITER (à renouveler, nouveaux,
 *     pièces manquantes, chacun avec son action) et la PROCHAINE GRANDE DATE ;
 *   - la FRISE des grandes dates des 12 prochains mois ;
 *   - les DOSSIERS à gauche (qui les remplit, leur complétude) ; au centre, un dossier montre ses PIÈCES ATTENDUES
 *     (reçue, à renouveler, manquante, écartée « pas concernée »), puis ses autres documents ; « Tout » liste tout, avec
 *     des filtres ; un coffre vide dit ce qu'il doit contenir et qui le dépose ;
 *   - l'APERÇU s'ouvre en tiroir, seulement quand un document est choisi (Ouvrir, Télécharger, Joindre à un e-mail,
 *     Remplacer, ses grandes dates, qui l'a ouvert) ;
 *   - DÉPOSER ouvre une fenêtre : le fichier, « C'est quelle pièce ? », puis l'assistant qui lit le document et propose
 *     ses grandes dates ; « Ranger » garde les dates cochées, le titre, l'échéance et la pièce.
 * Pas de pastilles rondes (Sam, 22/11/2026) : les nombres et les états sont des rectangles aux coins arrondis.
 *   Routes (consultant/src/coffre.php, coffre_dates.php) : GET …/coffre/magasin, …/coffre/magasin/fichier,
 *   POST …/coffre/magasin/document, …/coffre/magasin/piece, GET|POST|DELETE …/coffre/magasin/dates,
 *   POST …/coffre/magasin/assistant.
 *
 * `window.DashCoffre.racine(shop, {apiBase, aller})` rend l'élément du module, gardé d'un rendu à l'autre ; `aller(vue,
 * intention)` ouvre la Boîte mail avec un document du coffre en pièce jointe. `liste(shop)` sert la Boîte mail.
 */
(function () {
  'use strict';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const dfr = s => s ? String(s).slice(8, 10) + '/' + String(s).slice(5, 7) + '/' + String(s).slice(0, 4) : '—';
  const dh = s => s ? String(s).slice(8, 10) + '/' + String(s).slice(5, 7) + ' · ' + String(s).slice(11, 16) : '';
  const taille = o => o >= 1048576 ? (o / 1048576).toFixed(1).replace('.', ',') + ' Mo' : Math.max(1, Math.round((o || 0) / 1024)) + ' Ko';
  const ext = n => (String(n || '').split('.').pop() || '').slice(0, 4).toLowerCase();
  const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  const COMMENT = { ouvert: 'ouvert', telecharge: 'téléchargé', mail: 'joint à un mail' };
  /** L'assistant lit les PDF, les photos et les fichiers Word (le serveur dit non aux autres). */
  const LISIBLE = /^(application\/pdf|image\/(jpeg|png|webp)|application\/vnd\.openxmlformats-officedocument\.wordprocessingml\.document|application\/vnd\.oasis\.opendocument\.text|text\/plain)$/;
  /** Les dossiers, leur icône et qui les remplit. */
  // Les icônes : des tracés linéaires du design system (§7.12, §8.4), plus d'emoji (27/11/2026).
  const svg = d => `<svg class="cf2-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${d}"/></svg>`;
  const ICO = {
    tout: svg('M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z'),
    contrat: svg('M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5'),
    legal: svg('M4 4h16v16H4zM8 8h8M8 12h8M8 16h5'),
    formation: svg('M2 9l10-5 10 5-10 5zM6 11v5c0 1.5 2.7 3 6 3s6-1.5 6-3v-5M22 9v6'),
    visites: svg('M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11'),
    finances: svg('M4 20V10M10 20V4M16 20v-7M22 20H2'),
    courriers: svg('M3 5h18v14H3zM3 6l9 7 9-7'),
    dates: svg('M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z'),
    cadenas: svg('M7 11V7a5 5 0 0 1 10 0v4M5 11h14v10H5zM12 15v2'),
    plus: svg('M12 5v14M5 12h14'),
    envoyer: svg('M12 19V5M5 12l7-7 7 7'),
  };
  const DOS = [['contrat', ICO.contrat, 'par votre consultant'], ['legal', ICO.legal, 'par vous'], ['formation', ICO.formation, 'automatique'], ['visites', ICO.visites, 'automatique'],
    ['finances', ICO.finances, 'par votre consultant'], ['courriers', ICO.courriers, 'libre']];

  const st = { shop: null, api: '../api/cockpit', el: null, d: null, err: null, dos: 'tout', filtre: 'tous', q: '', sel: null, tout: false,
    depot: null, dates: null, datesErr: null, ajout: null, passees: false, occupe: false, flash: null, aller: null };

  /* --- lectures et écritures ------------------------------------------------------------------------------------ */
  const urlFichier = (id, tel, ap) => st.api + '/coffre/magasin/fichier?shop=' + encodeURIComponent(st.shop) + '&id=' + id + (tel ? '&telecharger=1' : '') + (ap ? '&apercu=1' : '');
  function lireCoffre(shop) {
    return fetch(st.api + '/coffre/magasin?shop=' + encodeURIComponent(shop), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status))))));
  }
  function charger() { return lireCoffre(st.shop).then(d => { st.d = d; st.err = null; }).catch(e => { st.err = e.message; }).finally(rendre); }
  function appel(methode, chemin, corps) {
    const o = { method: methode, headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (corps) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(Object.assign({ shop: st.shop }, corps)); }
    return fetch(st.api + chemin, o).then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status))))));
  }
  function lireDates() {
    return appel('GET', '/coffre/magasin/dates?shop=' + encodeURIComponent(st.shop)).then(d => { st.dates = d; st.datesErr = null; })
      .catch(e => { st.datesErr = e.message; }).finally(rendre);
  }
  function relireTout() { charger(); lireDates(); }
  function flash(ok, texte) { st.flash = { ok, texte }; rendre(); if (ok) { setTimeout(() => { if (st.flash && st.flash.texte === texte) { st.flash = null; rendre(); } }, 6000); } }
  /** Une ouverture se note au serveur : on relit pour « nouveau » et « qui l'a ouvert ». */
  function ouvert(id) { const doc = docs().find(x => x.id === id); if (doc) { doc.nouveau = false; } setTimeout(charger, 1200); }

  /* --- les données ---------------------------------------------------------------------------------------------- */
  const docs = () => (st.d && st.d.documents) || [];
  const visites = () => (st.d && st.d.visites) || [];
  const pieces = () => ((st.d && st.d.pieces) || {}).liste || [];
  const piece = k => pieces().find(p => p.cle === k);
  const nomDos = k => { const o = ((st.d && st.d.onglets) || []).find(x => x.cle === k); return o ? o.nom : (k === 'dates' ? 'Grandes dates' : k); };
  const nDos = k => { const o = ((st.d && st.d.onglets) || []).find(x => x.cle === k); return o ? o.n : 0; };
  const datesVenir = () => ((st.dates && st.dates.dates) || []).filter(d => d.etat.cle !== 'passee');
  /** Une ligne commune aux documents et aux rapports de visite. */
  function lignes() {
    const L = docs().map(x => ({ cle: 'd' + x.id, onglet: x.onglet, titre: x.titre, sous: [x.pieceNom && x.pieceNom !== x.titre ? x.pieceNom : '', x.deposePar, taille(x.octets)].filter(Boolean).join(' · '),
      date: x.date, ty: ext(x.nom), etat: x.etat, nouveau: x.nouveau, moi: x.deposeRole === 'franchise', doc: x }));
    visites().forEach(v => L.push({ cle: 'v' + v.id, onglet: 'visites', titre: 'Rapport de visite — ' + v.type, sous: [v.consultant, 'envoyé le ' + dfr(v.envoyeLe)].filter(Boolean).join(' · '),
      date: v.le, ty: 'web', etat: v.luLe ? { cle: 'lu', texte: 'lu' } : { cle: 'alire', texte: 'à lire' }, nouveau: !v.luLe, moi: false, visite: v }));
    return L.sort((a, b) => String(b.date || '').localeCompare(String(a.date || '')));
  }
  /** Ce qui demande une action, dans l'ordre : à renouveler, nouveaux, pièces que le magasin doit déposer. */
  function aFaire() {
    const A = [];
    pieces().filter(p => p.etat === 'renouveler' || p.etat === 'expiree').forEach(p => A.push({ c: p.etat === 'expiree' ? 'stop' : 'prize', lead: p.nom, mot: p.etat === 'expiree' ? 'échue' : 'à renouveler', t: p.nom + (p.etat === 'expiree' ? ' : échue' : ' : à renouveler'),
      s: 'échéance le ' + dfr(p.doc.echeance) + (p.doc.etat.jours != null ? (p.doc.etat.jours >= 0 ? ' · dans ' + p.doc.etat.jours + ' jours' : ' · depuis ' + (-p.doc.etat.jours) + ' jours') : ''),
      go: p.qui === 'franchise' ? 'Déposer la nouvelle' : 'Voir', faire: p.qui === 'franchise' ? 'depot:' + p.cle : 'doc:' + p.doc.id }));
    const nv = lignes().filter(l => l.nouveau);
    if (nv.length) { A.push({ c: 'prize', lead: nv.length > 1 ? nv.length + ' nouveaux documents' : 'Un nouveau document', mot: 'à lire', t: nv.length > 1 ? nv.length + ' nouveaux documents' : 'Un nouveau document', s: nv.slice(0, 2).map(l => l.titre).join(' · '), go: 'Voir', faire: 'nouveaux' }); }
    const man = pieces().filter(p => p.etat === 'manquante' && p.qui === 'franchise');
    if (man.length) { A.push({ c: 'prize', lead: man.length > 1 ? man.length + ' pièces légales' : 'Une pièce légale', mot: 'à déposer', t: man.length > 1 ? man.length + ' pièces légales à déposer' : 'Une pièce légale à déposer', s: man.slice(0, 3).map(p => p.nom).join(' · ') + (man.length > 3 ? '…' : ''), go: 'Compléter', faire: 'dos:legal' }); }
    const siege = pieces().filter(p => p.etat === 'manquante' && p.qui === 'siege' && p.onglet === 'contrat');
    if (A.length < 3 && siege.length === 4) { A.push({ c: 'neutre', lead: 'Votre contrat et votre DIP', mot: 'votre consultant les dépose', t: 'Votre contrat et votre DIP', s: 'votre consultant les dépose : ils arriveront ici', go: 'Voir', faire: 'dos:contrat' }); }
    return A.slice(0, 3);
  }

  /* --- les écritures -------------------------------------------------------------------------------------------- */
  function exclure(cle, oui) {
    appel('POST', '/coffre/magasin/piece', { piece: cle, exclue: oui }).then(r => { if (st.d) { st.d.pieces = r.pieces; } flash(true, oui ? 'Pièce écartée : elle ne compte plus dans votre coffre.' : 'Pièce réintégrée.'); })
      .catch(e => flash(false, 'Impossible : ' + e.message));
  }
  function ajouterDate() {
    const F = st.ajout; if (!F || st.occupe) { return; }
    if (!F.titre.trim() || !F.le) { flash(false, 'Un titre et une date, au moins.'); return; }
    st.occupe = true;
    appel('POST', '/coffre/magasin/dates', { dates: [{ sorte: F.sorte, titre: F.titre, le: F.le, fin: F.fin || null, note: F.note || null, docId: F.docId ? +F.docId : null }] })
      .then(r => { st.dates = Object.assign({}, st.dates || {}, { dates: r.dates }); st.ajout = null; flash(true, r.ajoutees ? 'Date rangée.' : 'Cette date est déjà rangée.'); charger(); })
      .catch(e => flash(false, 'Ajout impossible : ' + e.message)).finally(() => { st.occupe = false; rendre(); });
  }
  function retirerDate(id) {
    const d = ((st.dates || {}).dates || []).find(x => x.id === id);
    if (!d || !window.confirm('Retirer « ' + d.titre + ' » des grandes dates ?')) { return; }
    appel('DELETE', '/coffre/magasin/dates?shop=' + encodeURIComponent(st.shop) + '&id=' + id)
      .then(r => { st.dates = Object.assign({}, st.dates || {}, { dates: r.dates }); flash(true, 'Date retirée.'); charger(); })
      .catch(e => flash(false, 'Retrait impossible : ' + e.message));
  }

  /* --- le dépôt : la fenêtre, l'envoi, l'assistant, le rangement -------------------------------------------------- */
  function ouvrirDepot(pc) {
    const p = pc ? piece(pc) : null;
    st.depot = { etape: 'choix', fichier: null, piece: p && p.onglet === 'legal' ? p.cle : '', titre: p ? p.nom : '', date: new Date().toISOString().slice(0, 10), echeance: '', note: '', doc: null, as: null };
    st.sel = null; rendre();
  }
  function envoyerDepot() {
    const D = st.depot; if (!D || !D.fichier || st.occupe) { return; }
    const max = (st.d && st.d.depot && st.d.depot.maxOctets) || 25e6;
    if (D.fichier.size > max) { D.err = 'Trop lourd : ' + taille(max) + ' au plus.'; rendre(); return; }
    const fd = new FormData();
    [['shop', st.shop], ['titre', D.titre], ['date', D.date], ['echeance', D.echeance], ['note', D.note], ['piece', D.piece]].forEach(([k, v]) => fd.append(k, v || ''));
    fd.append('fichiers[]', D.fichier, D.fichier.name);
    st.occupe = true; D.err = null; rendre();
    fetch(st.api + '/coffre/magasin/document', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(r => r.json().catch(() => ({ error: 'HTTP ' + r.status })))
      .then(j => {
        const l = (j.ajoutes || [])[0];
        if (!l) { D.err = (j.refuses || [])[0] ? j.refuses[0].nom + ' : ' + j.refuses[0].motif : (j.error || 'Rien n’a été rangé.'); return; }
        Object.assign(D, { etape: 'lu', doc: l, titre: l.titre, echeance: l.echeance || '', pieceDeposee: D.piece });
        charger();
        // L'assistant (Sam : « un assistant lorsque j'importe un document ») lit ce qu'il sait lire.
        if (LISIBLE.test(l.mime || '')) { lancerAssistant(l.id); } else { D.as = { etat: 'illisible' }; }
      })
      .catch(e => { D.err = 'Envoi impossible : ' + e.message; })
      .finally(() => { st.occupe = false; rendre(); });
  }
  /** Fait lire un document du coffre (après un dépôt, ou « Relever les grandes dates » sur un document). */
  function lancerAssistant(id) {
    const D = st.depot; if (!D) { return; }
    D.as = { etat: 'lecture' }; rendre();
    appel('POST', '/coffre/magasin/assistant', { id }).then(r => {
      if (st.depot !== D) { return; }
      D.as = { etat: 'pret', r, coches: new Set(r.propositions.filter(p => p.coche).map(p => p.cle)), le: {}, passees: false };
      // Ce que l'assistant propose remplit le formulaire : un titre lisible, l'échéance, la pièce reconnue.
      if (r.titreDoc) { D.titre = r.titreDoc.propose; }
      if (r.echeanceDoc && !D.echeance) { D.echeance = r.echeanceDoc.proposee; }
      if (r.pieceSuggeree && r.pieceSuggeree.onglet === 'legal' && !D.piece) { D.piece = r.pieceSuggeree.cle; D.pieceAs = true; }
    }).catch(e => { if (st.depot === D) { D.as = { etat: 'erreur', err: e.message }; } }).finally(rendre);
  }
  /** Relire un document déjà rangé avec l'assistant : la même fenêtre, sans l'envoi. */
  function relever(x) {
    st.depot = { etape: 'lu', fichier: null, piece: x.piece || '', pieceDeposee: x.piece || '', titre: x.titre, date: x.date || '', echeance: x.echeance || '', note: '', doc: x, as: null, relecture: true };
    st.sel = null; lancerAssistant(x.id);
  }
  function rangerDepot() {
    const D = st.depot; if (!D || !D.doc || st.occupe) { return; }
    const A = D.as && D.as.etat === 'pret' ? D.as : null;
    const corps = { dates: A ? A.r.propositions.filter(p => A.coches.has(p.cle)).map(p => ({ sorte: p.sorte, titre: p.titre, le: A.le[p.cle] || p.le, fin: p.fin, note: p.note, docId: D.doc.id, source: p.source })) : [] };
    if (D.titre.trim() && D.titre.trim() !== D.doc.titre) { corps.titreDoc = { docId: D.doc.id, titre: D.titre.trim() }; }
    if (/^\d{4}-\d{2}-\d{2}$/.test(D.echeance) && D.echeance !== (D.doc.echeance || '')) { corps.echeanceDoc = { docId: D.doc.id, le: D.echeance }; }
    if ((D.piece || '') !== (D.pieceDeposee || '') && D.piece) { corps.pieceDoc = { docId: D.doc.id, piece: D.piece }; }
    if (!corps.dates.length && !corps.titreDoc && !corps.echeanceDoc && !corps.pieceDoc) { st.depot = null; flash(true, D.relecture ? 'Rien de changé.' : '« ' + D.doc.titre + ' » est rangé dans votre coffre.'); return; }
    st.occupe = true; rendre();
    appel('POST', '/coffre/magasin/dates', corps).then(r => {
      st.dates = Object.assign({}, st.dates || {}, { dates: r.dates }); st.depot = null;
      flash(true, '« ' + (corps.titreDoc ? corps.titreDoc.titre : D.doc.titre) + ' » est rangé' + (r.ajoutees ? (r.ajoutees > 1 ? ', avec ' + r.ajoutees + ' grandes dates' : ', avec une grande date') : '') + '.');
      charger();
    }).catch(e => flash(false, 'Rangement impossible : ' + e.message)).finally(() => { st.occupe = false; rendre(); });
  }

  /* --- le rendu : les pièces communes ----------------------------------------------------------------------------- */
  const ty = t => { const k = ['pdf'].includes(t) ? 'pdf' : ['xls', 'xlsx', 'csv', 'ods'].includes(t) ? 'xls' : ['doc', 'docx', 'odt', 'txt'].includes(t) ? 'doc' : ['jpg', 'jpeg', 'png', 'webp', 'heic'].includes(t) ? 'img' : t === 'web' ? 'web' : 'aut';
    return `<span class="cf2-ty ${k}">${esc(t === 'web' ? 'WEB' : (t || '?').slice(0, 4).toUpperCase())}</span>`; };
  const etatTag = e => { const c = { valide: 'ok', depose: 'gr', renouveler: 'or', expiree: 'ko', lu: 'ok', alire: 'or' }[e.cle] || 'gr';
    return `<span class="cf2-st ${c}">${esc(e.cle === 'valide' && e.jours != null ? 'valide' : e.texte)}</span>`; };
  function ring(a, b) {
    const r = 38, c = 2 * Math.PI * r, p = b ? a / b : 0;
    return `<div class="cf2-ring"><svg viewBox="0 0 92 92"><circle cx="46" cy="46" r="${r}" fill="none" stroke="#EFE9DF" stroke-width="9"/>${a ? `<circle cx="46" cy="46" r="${r}" fill="none" stroke="#3F7A52" stroke-width="9" stroke-linecap="round" stroke-dasharray="${(c * p).toFixed(1)} ${c.toFixed(1)}"/>` : ''}</svg><b>${a}<small>sur ${b}</small></b></div>`;
  }
  const jours = e => e.cle === 'passee' ? 'passée' : e.cle === 'encours' ? (e.jours === 0 ? 'dernier jour' : 'en cours · encore ' + e.jours + ' j')
    : e.jours === 0 ? 'aujourd’hui' : e.jours <= 90 ? 'dans ' + e.jours + ' j' : 'dans ' + Math.round(e.jours / 30.44) + ' mois';

  function barre() {
    // Le nombre des grandes dates à venir est dans le dossier « Grandes dates » (à gauche) : un chiffre, une place.
    return `<div class="cf2-barre"><input class="db-sel cf2-q" data-cf-q="1" value="${esc(st.q)}" placeholder="Chercher un document…">
      <button type="button" class="db-btn cf2-b abr${st.dos === 'dates' ? ' on' : ''}" data-cf-dos="dates">${ICO.dates}Grandes dates</button>
      <button type="button" class="db-btn cf2-b prim" data-cf-depot="">${ICO.plus}Déposer un document</button></div>`;
  }
  /** Le résumé (27/11/2026, DESIGN_SYSTEM.md §7.13) : ligne 1, les pièces reçues sur les attendues (24 px, 700 : le seul gras)
   * et leur état en mots, ce que garde le coffre, la prochaine grande date ; ligne 2 sous un filet, « À corriger » — les points
   * d'aFaire (à renouveler, nouveaux, pièces à déposer), chacun un .btn--sm qui fait le geste d'avant (data-cf-faire). */
  function etat() {
    const P = (st.d.pieces || {}).completude || { a: 0, b: 0 }, man = pieces().filter(p => p.etat === 'manquante');
    const vide = !docs().length && !visites().length;
    const moi = man.filter(p => p.qui === 'franchise').length, lui = man.filter(p => p.qui !== 'franchise').length;
    // Les pièces à déposer par le magasin sont dans « À corriger » : la phrase ne redit que celles du consultant (un chiffre, une place).
    const texte = vide ? 'Votre consultant y dépose le DIP, le contrat et vos rapports de visite. Vous y déposez vos pièces légales : l’assistant relève leurs grandes dates (bail, assurances…).'
      : (!man.length ? 'Toutes les pièces attendues sont là. ' : lui ? lui + ' pièce' + (lui > 1 ? 's' : '') + ' attendue' + (lui > 1 ? 's' : '') + ' de votre consultant. ' : '') + 'Vos documents et ceux de votre consultant ; chaque ouverture est notée.';
    const A = aFaire(), G = (st.d.reperes || {}).dates || {}, p = G.prochaine;
    const et = !man.length ? ['green', 'complet'] : ['prize', 'incomplet'];
    const proche = p && (p.etat.cle === 'encours' || p.etat.cle === 'bientot');
    return `<section class="cf2-rs" role="region" aria-label="Votre coffre en bref"><div class="cf2-rs-l1">
        <div class="cf2-rs-v"><span class="cf2-rs-n">${P.a}<small> sur ${P.b}</small></span><span class="cf2-rs-et"><i class="cf2-pe ${et[0]}"></i>${et[1]}</span></div>
        <div class="cf2-rs-q">${vide ? 'Votre coffre est vide pour l’instant' : 'pièces attendues reçues'}<span>${esc(texte)}</span>
          ${vide && moi ? '<button type="button" class="cf2-lien" data-cf-faire="depot:">Déposer ma première pièce ›</button>' : ''}</div>
        <div class="cf2-rs-pr">${p ? `<span class="k">Prochaine grande date</span><span class="t">${esc(dfr(p.le))} · ${esc(p.titre)}</span>
            <span class="cf2-rs-pl"><span class="cf2-u${proche ? '' : ' gr'}">${esc(jours(p.etat))}</span><button type="button" class="cf2-lien" data-cf-dos="dates">Toutes les grandes dates ›</button></span>`
          : '<span class="k">Grandes dates</span><span class="cf2-pv">Les échéances de vos documents apparaîtront ici : fins de triennat du bail, préavis, renouvellements, assurances. L’assistant les relève quand un document est déposé.</span>'}</div></div>
      <div class="cf2-rs-l2">${A.length ? `<span class="cf2-rs-t">À corriger</span><span class="cf2-rs-l">${A.map(x => `<button type="button" class="btn btn--sm cf2-rs-b" data-cf-faire="${esc(x.faire)}" title="${esc(x.s)}"><i class="cf2-pe ${x.c}" aria-hidden="true"></i><span class="cf2-rs-j">${esc(x.lead)}</span>${esc(x.mot)}</button>`).join('')}</span>`
        : '<p class="cf2-rs-ok">Rien à corriger : votre coffre est à jour.</p>'}</div></section>`;
  }
  /** La frise des 12 prochains mois : les grandes dates à venir, une fenêtre en bande, aujourd'hui marqué. */
  function frise() {
    const auj = new Date(); auj.setHours(12, 0, 0, 0);
    const deb = new Date(auj.getFullYear(), auj.getMonth(), 1, 12), fin = new Date(deb.getFullYear() + 1, deb.getMonth(), 1, 12), tot = (fin - deb) / 864e5;
    const iso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    const pos = s => { const d = new Date(s + 'T12:00:00'); return Math.max(0, Math.min(1, ((d - deb) / 864e5) / tot)); };
    const ev = datesVenir().filter(d => new Date(d.le + 'T12:00:00') < fin || (d.fin && new Date(d.fin + 'T12:00:00') < fin)).slice(0, 8);
    if (!ev.length) { return ''; }
    const mois = Array.from({ length: 12 }, (_, i) => new Date(deb.getFullYear(), deb.getMonth() + i, 1, 12));
    const pct = v => (v * 100).toFixed(2) + '%';
    // Les étiquettes (26/11/2026 : coupées au bord gauche, ou l'une sur l'autre quand deux dates sont proches) : au bord,
    // alignées vers l'intérieur ; chacune sur la rangée (haut ou bas) la plus libre ; raccourcies si les deux sont prises.
    const der = { h: -1, b: -1 }, PROCHE = 0.2;
    return `<section class="cf2-frise"><div class="cf2-k">Les grandes dates des 12 prochains mois<i><span class="cf2-auj">aujourd’hui</span>un clic ouvre les grandes dates</i></div>
      <div class="cf2-fr"><div class="axe"></div>
        ${mois.map((m, i) => `<span class="tick" style="left:${pct(pos(iso(m)))}"></span><span class="m${i % 3 ? ' sec' : ''}" style="left:${pct(pos(iso(m)) + 1 / 24)}">${MOIS[m.getMonth()]}</span>`).join('')}
        ${ev.filter(d => d.fin).map(d => `<div class="fen" style="left:${pct(pos(d.le))};width:${pct(pos(d.fin) - pos(d.le))}"></div>`).join('')}
        <div class="auj" style="left:${pct(pos(iso(auj)))}"></div>
        ${ev.map(d => { const x = d.fin && d.etat.cle === 'encours' ? d.fin : d.le, c = d.etat.cle === 'bientot' || d.etat.cle === 'encours' ? 'or' : 'av', p = pos(x);
          const libreH = p - der.h >= PROCHE, libreB = p - der.b >= PROCHE, h = libreH || (!libreB && der.h <= der.b); if (h) { der.h = p; } else { der.b = p; }
          const lim = libreH || libreB ? 42 : 18, bord = p < 0.15 ? ' g' : (p > 0.85 ? ' d' : '');
          return `<button type="button" class="ev ${c}${h ? '' : ' bas'}${bord}" style="left:${pct(p)}" data-cf-dos="dates" title="${esc(d.titre + ' · ' + dfr(x))}"><i></i><span>${esc(d.titre.length > lim ? d.titre.slice(0, lim - 2) + '…' : d.titre)} · ${esc(dfr(x).slice(0, 5))}</span></button>`; }).join('')}</div></section>`;
  }
  function nav() {
    const PO = (st.d.pieces || {}).parOnglet || {};
    const it = (k, ico, nom, qui, n) => { const c = PO[k]; return `<button type="button" class="cf2-d${st.dos === k ? ' on' : ''}" data-cf-dos="${k}"><span class="ico">${ico}</span>
      <span class="nm">${esc(nom)}${qui ? `<small>${esc(qui)}${c ? ' · ' + c.a + '/' + c.b + ' pièces' : ''}</small>` : ''}${c ? `<span class="bar"><b style="width:${c.b ? Math.round(c.a / c.b * 100) : 0}%"></b></span>` : ''}</span><span class="n">${n}</span></button>`; };
    const nv = datesVenir(), bientot = nv.filter(d => d.etat.cle === 'bientot' || d.etat.cle === 'encours').length;
    return `<nav class="cf2-dos">${it('tout', ICO.tout, 'Tout', '', nDos('tout'))}<div class="cf2-sep"></div>${DOS.map(([k, ico, qui]) => it(k, ico, nomDos(k), qui, nDos(k))).join('')}
      <button type="button" class="cf2-d gd${st.dos === 'dates' ? ' on' : ''}" data-cf-dos="dates"><span class="ico">${ICO.dates}</span><span class="nm">Grandes dates<small>${bientot ? bientot + ' dans les 4 mois' : 'à venir'}</small></span><span class="n">${nv.length}</span></button></nav>`;
  }
  const ligne = l => `<button type="button" class="cf2-l${st.sel === l.cle ? ' sel' : ''}" data-cf-sel="${l.cle}">${ty(l.ty)}
      <span class="tt"><b>${esc(l.titre)}${l.nouveau ? '<em class="cf2-nv">nouveau</em>' : ''}</b><small>${esc(l.sous)}</small></span>
      ${st.dos === 'tout' ? `<span class="dos">${esc(nomDos(l.onglet))}</span>` : ''}<span class="qd">${esc(dfr(l.date))}</span><span class="et">${etatTag(l.etat)}</span></button>`;
  function filtrer(L) {
    const q = st.q.trim().toLowerCase();
    return L.filter(l => (!q || (l.titre + ' ' + l.sous + ' ' + nomDos(l.onglet)).toLowerCase().includes(q))
      && (st.filtre === 'tous' || (st.filtre === 'nouveaux' && l.nouveau) || (st.filtre === 'renouveler' && ['renouveler', 'expiree'].includes(l.etat.cle)) || (st.filtre === 'moi' && l.moi)));
  }
  function vueTout() {
    const T = lignes(), L = filtrer(T), mois = new Date(); mois.setDate(mois.getDate() - 31);
    const recents = L.filter(l => new Date((l.doc ? l.doc.deposeLe.slice(0, 10) : l.visite.envoyeLe.slice(0, 10)) + 'T12:00:00') >= mois), avant = L.filter(l => !recents.includes(l));
    const chips = [['tous', 'Tous', T.length], ['nouveaux', 'Nouveaux', T.filter(l => l.nouveau).length], ['renouveler', 'À renouveler', T.filter(l => ['renouveler', 'expiree'].includes(l.etat.cle)).length], ['moi', 'Déposés par moi', T.filter(l => l.moi).length]];
    const max = st.tout ? 1e9 : 12; let n = 0;
    const part = X => X.filter(() => n++ < max).map(ligne).join('');
    const h1 = part(recents), h2 = part(avant);
    return `<section class="cf2-carte"><div class="cf2-ch"><h2>${st.q ? 'Recherche « ' + esc(st.q) + ' »' : 'Tous les documents'}</h2><small>${st.q || st.filtre !== 'tous' ? L.length + ' · ' : ''}les plus récents d’abord</small><span class="sp"></span>
        ${chips.map(([k, t, c]) => `<button type="button" class="cf2-chip${st.filtre === k ? ' on' : ''}" data-cf-filtre="${k}">${esc(t)}${k !== 'tous' && c ? ' · ' + c : ''}</button>`).join('')}</div>
      ${h1 ? '<div class="cf2-grp">Ce mois-ci</div>' + h1 : ''}${h2 ? '<div class="cf2-grp">Avant</div>' + h2 : ''}
      ${!L.length ? `<div class="cf2-videl">${st.q ? 'Aucun document ne répond à « ' + esc(st.q) + ' ».' : 'Aucun document pour ce filtre.'}</div>` : ''}
      ${L.length > max ? `<div class="cf2-pied">+ ${L.length - max} document${L.length - max > 1 ? 's' : ''} plus ancien${L.length - max > 1 ? 's' : ''} · <button type="button" class="cf2-lien" data-cf-toutvoir="1">tout afficher</button></div>` : ''}</section>`;
  }
  /** Un dossier : ses pièces attendues en cartes, puis ses autres documents. */
  function vueDossier(k) {
    const P = pieces().filter(p => p.onglet === k), c = ((st.d.pieces || {}).parOnglet || {})[k];
    const qui = (DOS.find(d => d[0] === k) || [])[2] || '';
    const montres = new Set(P.filter(p => p.doc).map(p => p.doc.id));
    const autres = filtrer(lignes().filter(l => l.onglet === k && !(l.doc && montres.has(l.doc.id))));
    const carte = p => {
      const d = p.doc, cl = { recue: 'p-ok', renouveler: 'p-or', expiree: 'p-ko', manquante: 'p-man', exclue: 'p-exc' }[p.etat];
      const ck = { recue: '✓', renouveler: '!', expiree: '!', manquante: '', exclue: '–' }[p.etat];
      const sous = d ? (p.etat === 'renouveler' || p.etat === 'expiree' ? 'échéance le ' + dfr(d.echeance) + (d.etat.jours != null ? (d.etat.jours >= 0 ? ' · dans ' + d.etat.jours + ' jours' : ' · échue') : '')
        : (d.date ? 'du ' + dfr(d.date) : 'déposé') + (d.echeance ? ' · jusqu’au ' + dfr(d.echeance) : '') + (d.deposePar ? ' · ' + d.deposePar : ''))
        : p.etat === 'exclue' ? 'pas concernée' + (p.exclue && p.exclue.par ? ' · écartée par ' + p.exclue.par : '') : p.qui === 'franchise' ? 'à déposer par vous' : p.qui === 'auto' ? 'arrive tout seul' : 'attendue de votre consultant';
      const gd = d ? ((st.dates && st.dates.dates) || []).filter(x => x.docId === d.id && x.etat.cle !== 'passee').length : 0;
      const peut = p.qui === 'franchise';
      return `<div class="cf2-p ${cl}"${d ? ` data-cf-sel="d${d.id}" role="button" tabindex="0"` : ''}><span class="ck">${ck}</span><span class="tt"><b>${esc(p.nom)}</b><small>${esc(sous)}</small>
        ${gd ? `<span class="gdm">${ICO.dates}${gd} grande${gd > 1 ? 's' : ''} date${gd > 1 ? 's' : ''}</span>` : ''}${p.n > 1 ? `<span class="gdm gr">${p.n} versions</span>` : ''}
        ${peut && (p.etat === 'manquante' || p.etat === 'renouveler' || p.etat === 'expiree') ? `<span class="bt"><button type="button" class="db-btn cf2-b sm" data-cf-depot="${p.cle}">${p.etat === 'manquante' ? 'Déposer' : 'Déposer la nouvelle'}</button>${p.etat === 'manquante' ? `<button type="button" class="cf2-lien gr" data-cf-exclure="${p.cle}">pas concernée ?</button>` : ''}</span>` : ''}
        ${peut && p.etat === 'exclue' ? `<span class="bt"><button type="button" class="cf2-lien" data-cf-reintegrer="${p.cle}">la réintégrer</button></span>` : ''}</span></div>`;
    };
    return `<section class="cf2-carte"><div class="cf2-ch"><h2>${esc(nomDos(k))}</h2><small>${esc(qui === 'par vous' ? 'vos pièces · vous les déposez, votre consultant aussi' : qui === 'automatique' ? 'rangés d’office' : qui === 'libre' ? 'les comptes rendus et les courriers' : 'déposés par votre consultant')}</small><span class="sp"></span>
        ${k === 'legal' ? `<button type="button" class="db-btn cf2-b prim sm" data-cf-depot="">${ICO.plus}Déposer une pièce légale</button>` : ''}</div>
      ${P.length ? `<div class="cf2-qui">Pièces attendues · ${c ? c.a + ' sur ' + c.b : ''}</div><div class="cf2-att">${P.map(carte).join('')}</div>` : ''}
      ${P.length && autres.length ? `<div class="cf2-grp">Autres documents · ${autres.length}</div>` : ''}${autres.map(ligne).join('')}
      ${!P.length && !autres.length ? `<div class="cf2-videl">${k === 'visites' ? 'Chaque rapport envoyé après une visite de votre consultant arrive ici. Le premier arrivera après sa prochaine visite.' : 'Rien dans ce dossier pour l’instant.'}</div>` : ''}</section>`;
  }
  /** Le coffre vide : ce qu'il doit contenir, et qui le dépose. */
  function vueVide() {
    const P = pieces(), bloc = (k, ico, qui) => { const L = P.filter(p => p.onglet === k);
      return `<div class="cf2-vh"><span class="ico">${ico}</span><span><b>${esc(nomDos(k))}</b><small>${esc(qui)}</small></span><span class="n">${L.filter(p => p.doc).length} / ${L.filter(p => p.etat !== 'exclue').length}</span></div>
        ${L.map(p => `<div class="cf2-vi"><i class="o${p.doc ? ' ok' : ''}">${p.doc ? '✓' : ''}</i><span>${esc(p.nom)}</span>${p.doc ? '<em class="gr">reçu</em>' : p.qui === 'franchise' ? `<button type="button" class="cf2-lien" data-cf-depot="${p.cle}">Déposer</button>` : `<em class="gr">${p.qui === 'auto' ? 'automatique' : 'attendu'}</em>`}</div>`).join('')}`; };
    const auto = (ico, k, sous, t) => `<div class="cf2-vh"><span class="ico">${ico}</span><span><b>${esc(nomDos(k))}</b><small>${esc(sous)}</small></span><span class="n">${nDos(k)}</span></div><div class="cf2-auto">${esc(t)}</div>`;
    const moi = P.filter(p => p.qui === 'franchise').length, lui = P.filter(p => p.qui === 'siege').length;
    return `<section class="cf2-carte"><div class="cf2-ch"><h2>Ce que votre coffre doit contenir</h2><small>${P.filter(p => p.etat !== 'exclue').length} pièces attendues · chacune se dépose une fois, puis se renouvelle à son échéance</small><span class="sp"></span>
        <span class="cf2-chip">À déposer par moi · ${moi}</span><span class="cf2-chip">Attendu de mon consultant · ${lui}</span></div>
      <div class="cf2-vide"><div class="cf2-vc">${bloc('legal', ICO.legal, 'vous les déposez')}</div>
        <div class="cf2-vc">${bloc('contrat', ICO.contrat, 'votre consultant les dépose')}${bloc('finances', ICO.finances, 'votre consultant les dépose')}</div>
        <div class="cf2-vc">${bloc('formation', ICO.formation, 'votre consultant, puis d’office')}${auto(ICO.visites, 'visites', 'arrivent tout seuls', 'Chaque rapport envoyé après une visite de votre consultant.')}${auto(ICO.courriers, 'courriers', 'libre', 'Les comptes rendus de réunion et les courriers échangés avec le réseau.')}</div></div></section>`;
  }
  /** Les grandes dates : par année, la prochaine en tête, les passées repliées ; l'ajout à la main. */
  function vueDates() {
    if (st.datesErr && !st.dates) { return `<section class="cf2-carte"><div class="cf2-videl">Les grandes dates ne se lisent pas : ${esc(st.datesErr)}</div></section>`; }
    if (!st.dates) { return '<section class="cf2-carte"><div class="cf2-videl">Lecture des grandes dates…</div></section>'; }
    const L = st.dates.dates || [], venir = L.filter(d => d.etat.cle !== 'passee'), pas = L.filter(d => d.etat.cle === 'passee');
    const src = d => d.source === 'calcul' ? 'calculée' : d.source === 'assistant' ? 'relevée par l’assistant' : d.source === 'document' ? 'échéance du document' : 'ajoutée par ' + (d.par || 'vous');
    const ld = d => `<div class="cf2-gd ${d.etat.cle}"><span class="d"><b>${+d.le.slice(8, 10)}</b><small>${esc(MOIS[+d.le.slice(5, 7) - 1])}</small></span>
      <span class="c"><b>${esc(d.titre)}</b><small>${d.sorteLib !== d.titre ? esc(d.sorteLib) + ' · ' : ''}${d.fin ? 'du ' + esc(dfr(d.le)) + ' au ' + esc(dfr(d.fin)) + ' · ' : ''}${esc(src(d))}${d.docTitre ? ` · ${d.docCoffre ? `<button type="button" class="cf2-lien" data-cf-sel="d${d.docId}">${esc(d.docTitre)}</button>` : esc(d.docTitre)}` : ''}</small>${d.note ? `<i>${esc(d.note)}</i>` : ''}</span>
      <span class="j">${esc(jours(d.etat))}</span>${d.modifiable && d.id ? `<button type="button" class="cf2-x" data-cf-retire="${d.id}" title="Retirer">×</button>` : '<span class="cf2-x"></span>'}</div>`;
    let h = '', an = '';
    venir.forEach(d => { if (d.le.slice(0, 4) !== an) { an = d.le.slice(0, 4); h += `<div class="cf2-grp">${an}</div>`; } h += ld(d); });
    const F = st.ajout, sortes = (st.dates.sortes || []).filter(o => o.v !== 'echeance');
    const form = F ? `<div class="cf2-ajout"><div class="cf2-fg">
        <label><span>Sorte</span><select class="db-sel" data-cf-a="sorte">${sortes.map(o => `<option value="${o.v}"${o.v === F.sorte ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select></label>
        <label><span>Titre</span><input class="db-sel" data-cf-a="titre" value="${esc(F.titre)}" placeholder="ex. Contrôle AFSCA annuel"></label>
        <label><span>Date</span><input class="db-sel" type="date" data-cf-a="le" value="${esc(F.le)}"></label>
        <label><span>Jusqu’au (facultatif)</span><input class="db-sel" type="date" data-cf-a="fin" value="${esc(F.fin)}"></label>
        <label><span>Document (facultatif)</span><select class="db-sel" data-cf-a="docId"><option value="">—</option>${docs().map(x => `<option value="${x.id}"${String(x.id) === String(F.docId) ? ' selected' : ''}>${esc(x.titre)}</option>`).join('')}</select></label></div>
      <div class="cf2-fb"><input class="db-sel" style="flex:1" data-cf-a="note" value="${esc(F.note)}" placeholder="Note (facultatif)"><button type="button" class="db-btn" data-cf-a-annule="1">Annuler</button><button type="button" class="db-btn cf2-b prim" data-cf-a-ok="1"${st.occupe ? ' disabled' : ''}>Ajouter</button></div></div>` : '';
    return `<section class="cf2-carte"><div class="cf2-ch"><h2>Grandes dates</h2><small>triennats du bail, préavis, renouvellements, échéances</small><span class="sp"></span>${F ? '' : `<button type="button" class="db-btn cf2-b prim sm" data-cf-ajout="1">${ICO.plus}Ajouter une date</button>`}</div>${form}
      ${L.length ? h + (!venir.length ? '<div class="cf2-videl">Aucune grande date à venir.</div>' : '') + (pas.length ? `<button type="button" class="cf2-pas" data-cf-passees="1">${st.passees ? '▾' : '▸'} ${pas.length} date${pas.length > 1 ? 's' : ''} passée${pas.length > 1 ? 's' : ''}</button>${st.passees ? pas.slice().reverse().map(ld).join('') : ''}` : '')
        : '<div class="cf2-videl">Pas encore de grande date. Déposez votre bail ou une police d’assurance : l’assistant relève ses dates. Ou ajoutez-en une à la main.</div>'}</section>`;
  }

  /* --- le tiroir : l'aperçu d'un document ou d'un rapport ---------------------------------------------------------- */
  function tiroir() {
    if (!st.sel) { return ''; }
    const l = lignes().find(x => x.cle === st.sel); if (!l) { return ''; }
    if (l.visite) {
      const v = l.visite, lien = st.d.suivi;
      return `<div class="cf2-ombre" data-cf-ferme="1"></div><aside class="cf2-tir" role="dialog" aria-label="Rapport de visite"><div class="h">${ty('web')}<span><b>${esc(l.titre)}</b><small>Rapports de visite · ${esc(v.consultant || '')}</small></span><button type="button" class="x" data-cf-ferme="1" aria-label="Fermer">×</button></div>
        <div class="c"><div class="cf2-kv"><span>Visite</span><span>${esc(dfr(v.le))}</span><span>Envoyé</span><span>${esc(dh(v.envoyeLe))}${v.envoyePar ? ' · ' + esc(v.envoyePar) : ''}</span><span>Lu</span><span>${v.luLe ? esc(dh(v.luLe)) : 'pas encore'}</span></div>
          ${lien ? `<div class="cf2-acts un"><a class="db-btn cf2-b prim" href="${esc(lien)}" target="_blank" rel="noopener">Lire le rapport ↗</a></div><p class="cf2-n">Le rapport, ses photos et ses points à corriger sont dans « Mon suivi ».</p>` : '<p class="cf2-n">« Mon suivi » n’est pas joignable d’ici.</p>'}</div></aside>`;
    }
    const x = l.doc, im = /^image\//.test(x.mime), pdf = x.mime === 'application/pdf';
    const gd = ((st.dates && st.dates.dates) || []).filter(d => d.docId === x.id);
    const pc = x.piece ? piece(x.piece) : null, remplacer = pc && pc.qui === 'franchise';
    return `<div class="cf2-ombre" data-cf-ferme="1"></div><aside class="cf2-tir" role="dialog" aria-label="${esc(x.titre)}"><div class="h">${ty(ext(x.nom))}<span><b>${esc(x.titre)}</b><small>${esc(nomDos(x.onglet))} · ${esc(x.nom)} · ${esc(taille(x.octets))}</small></span><button type="button" class="x" data-cf-ferme="1" aria-label="Fermer">×</button></div>
      <div class="c">
        ${pdf ? `<iframe class="cf2-page" src="${esc(urlFichier(x.id, false, true))}#toolbar=0&view=FitH" title="Aperçu"></iframe>` : im ? `<img class="cf2-page" src="${esc(urlFichier(x.id, false, true))}" alt="">` : `<div class="cf2-page x">${ty(ext(x.nom))}<small>pas d’aperçu : ouvrez-le</small></div>`}
        <div class="cf2-acts"><a class="db-btn cf2-b prim" href="${esc(urlFichier(x.id))}" target="_blank" rel="noopener" data-cf-ouvre="${x.id}">Ouvrir</a><a class="db-btn cf2-b" href="${esc(urlFichier(x.id, true))}" data-cf-ouvre="${x.id}">Télécharger</a>
          ${st.aller ? `<button type="button" class="db-btn cf2-b" data-cf-mail="${x.id}">${ICO.courriers}Joindre à un e-mail</button>` : ''}
          ${remplacer ? `<button type="button" class="db-btn cf2-b" data-cf-depot="${pc.cle}">Remplacer</button>` : LISIBLE.test(x.mime || '') ? `<button type="button" class="db-btn cf2-b abr" data-cf-releve="${x.id}">✦ Relever les dates</button>` : ''}</div>
        ${x.note ? `<p class="cf2-n">${esc(x.note)}</p>` : ''}
        <div class="cf2-kv"><span>Date</span><span>${esc(dfr(x.date))}</span>${x.echeance ? `<span>Échéance</span><span>${esc(dfr(x.echeance))} ${etatTag(x.etat)}</span>` : ''}${x.pieceNom ? `<span>Pièce</span><span>${esc(x.pieceNom)}</span>` : ''}<span>Déposé par</span><span>${esc(x.deposePar)}, le ${esc(dfr(x.deposeLe))}</span></div>
        ${gd.length ? `<div class="cf2-k">Ses grandes dates<i>${remplacer && LISIBLE.test(x.mime || '') ? `<button type="button" class="cf2-lien" data-cf-releve="${x.id}">✦ relire</button>` : ''}</i></div>${gd.map(d => `<div class="cf2-gdl"><span class="d ${d.etat.cle}"><b>${+d.le.slice(8, 10)}</b>${esc(MOIS[+d.le.slice(5, 7) - 1])} ${esc(d.le.slice(2, 4))}</span><span class="t">${esc(d.titre)}<small>${esc(jours(d.etat))}</small></span></div>`).join('')}` : ''}
        <div class="cf2-k" style="margin-top:14px">Qui l’a ouvert<i>noté à chaque ouverture</i></div>
        ${(x.traces || []).map(t => `<div class="cf2-tr"><span>${esc(dh(t.le))}</span><span>${esc(t.qui)}<small>${esc(COMMENT[t.comment] || t.comment)}${t.role === 'siege' ? ' · siège' : ''}</small></span></div>`).join('')}
        <div class="cf2-tr"><span>${esc(dh(x.deposeLe))}</span><span>Déposé au coffre<small>par ${esc(x.deposePar)}</small></span></div>
      </div></aside>`;
  }

  /* --- la fenêtre du dépôt ----------------------------------------------------------------------------------------- */
  function fenetre() {
    const D = st.depot; if (!D) { return ''; }
    const legal = pieces().filter(p => p.onglet === 'legal'), aFournir = p => p.etat === 'manquante' || p.etat === 'renouveler' || p.etat === 'expiree';
    const ordre = legal.filter(aFournir).concat(legal.filter(p => !aFournir(p)));
    // Relire un document du consultant (une pièce d'un autre dossier) : sa pièce paraît, choisie (26/11/2026 : « Autre
    // document » était surligné, faute de la proposer).
    const propre = D.pieceDeposee && piece(D.pieceDeposee) && piece(D.pieceDeposee).onglet !== 'legal' ? piece(D.pieceDeposee) : null;
    const chips = (propre ? `<button type="button" class="${D.piece === propre.cle ? 'on' : ''}" data-cf-piece="${propre.cle}">${esc(propre.nom)} ✓</button>` : '')
      + ordre.map(p => `<button type="button" class="${D.piece === p.cle ? 'on' : ''}" data-cf-piece="${p.cle}">${esc(p.nom)}${p.etat === 'renouveler' || p.etat === 'expiree' ? ' (nouvelle)' : p.doc ? ' ✓' : ''}</button>`).join('')
      + `<button type="button" class="${!D.piece ? 'on' : ''}" data-cf-piece="">Autre document</button>`;
    const ext2 = ((st.d.depot || {}).extensions || []).map(e => '.' + e).join(',');
    const A = D.as;
    const gauche = `<div class="g">
      ${D.etape === 'choix' ? `<label class="cf2-drop${D.fichier ? ' ok' : ''}" data-cf-drop="1">${D.fichier ? ty(ext(D.fichier.name)) : `<span class="cf2-up">${ICO.envoyer}</span>`}<span>${D.fichier ? `<b>${esc(D.fichier.name)}</b><small>${esc(taille(D.fichier.size))} · prêt à déposer</small>` : '<b>Glissez le fichier ici</b><small>ou cliquez pour le choisir · au téléphone : une photo</small>'}</span><input type="file" data-cf-fichier="1" hidden accept="${esc(ext2)}"></label>`
        : `<div class="cf2-drop ok">${ty(ext(D.doc.nom))}<span><b>${esc(D.doc.nom)}</b><small>${esc(taille(D.doc.octets))} · ${D.relecture ? 'dans votre coffre' : 'rangé ✓'}</small></span></div>`}
      <div class="cf2-lab">C’est quelle pièce ?</div><div class="cf2-chips">${chips}</div>
      ${D.pieceAs ? '<div class="cf2-sug">✦ L’assistant y reconnaît cette pièce : elle est choisie pour vous.</div>' : ''}
      <div class="cf2-lab">Titre</div><input class="db-sel cf2-in" data-cf-dp="titre" value="${esc(D.titre)}" placeholder="ex. Attestation d’assurance RC 2027">
      <div class="cf2-2"><div><div class="cf2-lab">Date du document</div><input class="db-sel cf2-in" type="date" data-cf-dp="date" value="${esc(D.date)}"></div><div><div class="cf2-lab">Échéance</div><input class="db-sel cf2-in" type="date" data-cf-dp="echeance" value="${esc(D.echeance)}"></div></div>
      ${D.etape === 'choix' ? `<div class="cf2-lab">Note</div><input class="db-sel cf2-in" data-cf-dp="note" value="${esc(D.note)}" placeholder="facultatif">` : '<div class="cf2-n">Titre, dates et pièce : remplis par l’assistant quand il sait, vous pouvez les changer.</div>'}
      ${D.err ? `<div class="cf2-flash ko">${esc(D.err)}</div>` : ''}</div>`;
    let droite;
    if (D.etape === 'choix') {
      droite = `<div class="d"><div class="cf2-ash"><span class="o">✦</span><b>L’assistant</b></div><p class="cf2-n">Dès que le document est déposé, l’assistant le lit : ce que c’est, un titre lisible, son échéance et <b>ses grandes dates</b> (fins de triennat du bail, préavis, renouvellements, assurances), avec la phrase qui les justifie.</p><p class="cf2-n">Rien n’est rangé sans votre accord.</p></div>`;
    } else if (!A || A.etat === 'lecture') {
      droite = `<div class="d"><div class="cf2-ash"><span class="o">✦</span><b>L’assistant lit le document</b></div><div class="cf2-lit"><span class="cf2-pt"><i></i><i></i><i></i></span>Je lis « ${esc(D.doc.nom)} »…</div><p class="cf2-n">Quelques secondes, parfois une minute pour un long bail.</p></div>`;
    } else if (A.etat === 'illisible') {
      droite = `<div class="d"><div class="cf2-ash"><span class="o">✦</span><b>L’assistant</b></div><p class="cf2-n">Il lit les PDF, les photos et les fichiers Word : ce fichier est rangé tel quel. Ajoutez une grande date à la main si besoin (« Grandes dates »).</p></div>`;
    } else if (A.etat === 'erreur') {
      droite = `<div class="d"><div class="cf2-ash"><span class="o">✦</span><b>L’assistant</b></div><div class="cf2-flash ko">${esc(A.err)}</div><button type="button" class="db-btn cf2-b" data-cf-as-relance="1">Réessayer</button></div>`;
    } else {
      const R = A.r, P = R.propositions || [], venir = P.filter(p => p.etat.cle !== 'passee'), pas = P.filter(p => p.etat.cle === 'passee');
      const calc = P.find(p => p.source === 'calcul' && p.extrait), nc = P.filter(p => A.coches.has(p.cle)).length;
      const pp = p => `<label class="cf2-pp${p.deja ? ' deja' : ''}"><input type="checkbox" data-cf-as-c="${p.cle}"${A.coches.has(p.cle) ? ' checked' : ''}><span class="t">${esc(p.titre)}${p.etat.cle === 'encours' || p.etat.cle === 'bientot' ? `<em>${esc(jours(p.etat))}</em>` : ''}
          <small>${p.source === 'calcul' ? 'calculée depuis le bail' : 'lue dans le document' + (p.page ? ', p. ' + p.page : '')}${p.fin ? ' · du ' + esc(dfr(p.le)) + ' au ' + esc(dfr(p.fin)) : ''}${p.deja ? ' · déjà rangée' : ''}</small>${p.extrait && p.source !== 'calcul' ? `<i>« ${esc(p.extrait)} »</i>` : ''}</span>
          <input class="db-sel cf2-le" type="date" data-cf-as-le="${p.cle}" value="${esc(A.le[p.cle] || p.le)}"></label>`;
      droite = `<div class="d"><div class="cf2-ash"><span class="o">✦</span><b>L’assistant a lu le document</b><span class="cf2-tag">${esc(R.genreLib)}</span></div>${R.resume ? `<p class="cf2-res">${esc(R.resume)}</p>` : ''}
        ${calc ? `<div class="cf2-ex">« ${esc(calc.extrait)} »</div>` : ''}
        <div class="cf2-lab">Grandes dates proposées · ${nc} cochée${nc > 1 ? 's' : ''}</div>
        ${P.length ? venir.map(pp).join('') + (pas.length ? `<button type="button" class="cf2-pas" data-cf-as-passees="1">${A.passees ? '▾' : '▸'} ${pas.length} date${pas.length > 1 ? 's' : ''} déjà passée${pas.length > 1 ? 's' : ''}, non cochée${pas.length > 1 ? 's' : ''}</button>${A.passees ? pas.map(pp).join('') : ''}` : '') : '<p class="cf2-n">Aucune date à relever dans ce document.</p>'}
        ${R.remarques ? `<div class="cf2-rq">${esc(R.remarques)}</div>` : ''}</div>`;
    }
    const n = A && A.etat === 'pret' ? A.r.propositions.filter(p => A.coches.has(p.cle)).length : 0;
    const bouton = D.etape === 'choix' ? `<button type="button" class="db-btn cf2-b prim" data-cf-envoi="1"${D.fichier && !st.occupe ? '' : ' disabled'}>${st.occupe ? 'Envoi…' : 'Déposer'}</button>`
      : `<button type="button" class="db-btn cf2-b prim" data-cf-range="1"${st.occupe || (A && A.etat === 'lecture') ? ' disabled' : ''}>${st.occupe ? 'Rangement…' : n ? 'Ranger' + (D.relecture ? '' : ' le document') + ' et ' + n + ' grande' + (n > 1 ? 's' : '') + ' date' + (n > 1 ? 's' : '') : D.relecture ? 'Valider' : 'Terminer'}</button>`;
    const pied = A && A.etat === 'pret' && A.r.propositions.some(p => p.source === 'calcul') ? 'Les dates du bail suivent la règle générale des baux commerciaux : à vérifier dans votre bail.'
      : D.etape === 'choix' ? 'Le document rejoint votre coffre ; votre consultant le voit aussi.' : D.relecture ? '' : 'Le document est déjà dans votre coffre : « Ranger » garde aussi le titre, l’échéance, la pièce et les dates cochées.';
    return `<div class="cf2-ombre" data-cf-depot-ferme="1"></div><div class="cf2-mod" role="dialog" aria-label="Déposer un document"><div class="h"><b>${D.relecture ? 'Relever les grandes dates' : 'Déposer un document'}</b><span class="cf2-qui">${D.relecture ? esc(D.doc.titre) : 'dans votre coffre'}</span><button type="button" class="x" data-cf-depot-ferme="1" aria-label="Fermer">×</button></div>
      <div class="c">${gauche}${droite}</div>
      <div class="f"><span class="n">${pied}</span><button type="button" class="db-btn" data-cf-depot-ferme="1">${D.etape === 'choix' ? 'Annuler' : 'Fermer'}</button>${bouton}</div></div>`;
  }

  /* --- le rendu de la page ----------------------------------------------------------------------------------------- */
  function rendre() {
    if (!st.el) { return; }
    const actif = document.activeElement && st.el.contains(document.activeElement) ? document.activeElement : null;
    const garde = actif && (actif.dataset.cfQ || actif.dataset.cfDp || actif.dataset.cfA) ? { k: actif.dataset.cfQ ? '[data-cf-q]' : actif.dataset.cfDp ? `[data-cf-dp="${actif.dataset.cfDp}"]` : `[data-cf-a="${actif.dataset.cfA}"]`, p: actif.selectionStart } : null;
    if (st.err && !st.d) { st.el.innerHTML = `<div class="db-err">Le coffre-fort ne se lit pas : ${esc(st.err)}</div>`; return; }
    if (!st.d) { st.el.innerHTML = '<div class="db-attente">Ouverture du coffre-fort…</div>'; return; }
    const vide = !docs().length && !visites().length;
    const contenu = st.dos === 'dates' ? vueDates() : st.dos === 'tout' ? (vide && !st.q ? vueVide() : vueTout()) : vueDossier(st.dos);
    st.el.innerHTML = `${st.flash ? `<div class="cf2-flash ${st.flash.ok ? 'ok' : 'ko'}">${esc(st.flash.texte)}</div>` : ''}${barre()}${etat()}${st.dos === 'dates' ? '' : frise()}
      <div class="cf2-corps">${nav()}<div class="cf2-cont">${contenu}</div></div>${tiroir()}${fenetre()}`;
    brancher();
    if (garde) { const n = st.el.querySelector(garde.k); if (n) { n.focus(); try { n.setSelectionRange(garde.p, garde.p); } catch (e) { /* sans curseur */ } } }
  }
  function faire(f) {
    if (f.startsWith('depot:')) { ouvrirDepot(f.slice(6)); return; }
    if (f.startsWith('dos:')) { st.dos = f.slice(4); st.filtre = 'tous'; rendre(); return; }
    if (f.startsWith('doc:')) { st.sel = 'd' + f.slice(4); rendre(); return; }
    if (f === 'nouveaux') { st.dos = 'tout'; st.filtre = 'nouveaux'; rendre(); }
  }
  function brancher() {
    const E = st.el, on = (s, ev, fn) => E.querySelectorAll(s).forEach(n => n.addEventListener(ev, fn));
    on('[data-cf-dos]', 'click', e => { const k = e.currentTarget.dataset.cfDos; st.dos = st.dos === 'dates' && k === 'dates' && e.currentTarget.classList.contains('cf2-b') ? 'tout' : k; st.filtre = 'tous'; st.sel = null; st.flash = null; if (k === 'dates' && !st.dates) { lireDates(); } rendre(); });
    on('[data-cf-faire]', 'click', e => faire(e.currentTarget.dataset.cfFaire));
    on('[data-cf-filtre]', 'click', e => { st.filtre = e.currentTarget.dataset.cfFiltre; rendre(); });
    on('[data-cf-toutvoir]', 'click', () => { st.tout = true; rendre(); });
    on('[data-cf-sel]', 'click', e => { e.stopPropagation(); st.sel = e.currentTarget.dataset.cfSel; if (st.dos === 'dates') { st.dos = 'tout'; } rendre(); });
    on('.cf2-p[data-cf-sel]', 'keydown', e => { if (e.key === 'Enter') { st.sel = e.currentTarget.dataset.cfSel; rendre(); } });
    on('[data-cf-ferme]', 'click', () => { st.sel = null; rendre(); });
    on('[data-cf-ouvre]', 'click', e => ouvert(+e.currentTarget.dataset.cfOuvre));
    on('[data-cf-mail]', 'click', e => { const id = +e.currentTarget.dataset.cfMail, x = docs().find(d => d.id === id); if (st.aller && x) { st.sel = null; st.aller('boite', { ecrire: { objet: x.titre, coffre: [id] } }); } });
    on('[data-cf-releve]', 'click', e => { const x = docs().find(d => d.id === +e.currentTarget.dataset.cfReleve); if (x) { relever(x); } });
    on('[data-cf-depot]', 'click', e => { e.stopPropagation(); ouvrirDepot(e.currentTarget.dataset.cfDepot); });
    on('[data-cf-exclure]', 'click', e => { e.stopPropagation(); const p = piece(e.currentTarget.dataset.cfExclure); if (p && window.confirm('« ' + p.nom + ' » ne concerne pas votre boutique ? Elle ne comptera plus dans votre coffre.')) { exclure(p.cle, true); } });
    on('[data-cf-reintegrer]', 'click', e => { e.stopPropagation(); exclure(e.currentTarget.dataset.cfReintegrer, false); });
    const q = E.querySelector('[data-cf-q]'); if (q) { q.addEventListener('input', () => { st.q = q.value; if (st.q && st.dos !== 'tout') { st.dos = 'tout'; } rendre(); }); }
    // Les grandes dates.
    on('[data-cf-retire]', 'click', e => retirerDate(+e.currentTarget.dataset.cfRetire));
    on('[data-cf-passees]', 'click', () => { st.passees = !st.passees; rendre(); });
    on('[data-cf-ajout]', 'click', () => { st.ajout = { sorte: 'autre', titre: '', le: '', fin: '', note: '', docId: '' }; rendre(); });
    on('[data-cf-a]', 'input', e => { st.ajout[e.currentTarget.dataset.cfA] = e.currentTarget.value; });
    on('[data-cf-a]', 'change', e => { st.ajout[e.currentTarget.dataset.cfA] = e.currentTarget.value; });
    on('[data-cf-a-annule]', 'click', () => { st.ajout = null; rendre(); });
    on('[data-cf-a-ok]', 'click', ajouterDate);
    // La fenêtre du dépôt.
    const D = st.depot;
    if (D) {
      on('[data-cf-depot-ferme]', 'click', () => { const enCours = D.etape === 'lu' && D.as && D.as.etat === 'pret' && D.as.coches.size;
        if (enCours && !window.confirm('Fermer sans ranger les grandes dates proposées ? Le document reste dans votre coffre.')) { return; }
        st.depot = null; if (D.etape === 'lu' && !D.relecture) { flash(true, '« ' + D.doc.titre + ' » est rangé dans votre coffre.'); } else { rendre(); } });
      on('[data-cf-piece]', 'click', e => { const k = e.currentTarget.dataset.cfPiece, p = piece(k), avant = piece(D.piece); D.pieceAs = false;
        if (!D.titre || (avant && D.titre === avant.nom) || (D.fichier && D.titre === D.fichier.name.replace(/\.[^.]+$/, ''))) { D.titre = p ? p.nom : D.titre; }
        D.piece = k; rendre(); });
      on('[data-cf-dp]', 'input', e => { D[e.currentTarget.dataset.cfDp] = e.currentTarget.value; });
      on('[data-cf-dp]', 'change', e => { D[e.currentTarget.dataset.cfDp] = e.currentTarget.value; });
      const fi = E.querySelector('[data-cf-fichier]'); if (fi) { fi.addEventListener('change', () => { if (fi.files[0]) { D.fichier = fi.files[0]; if (!D.titre) { D.titre = D.fichier.name.replace(/\.[^.]+$/, ''); } rendre(); } }); }
      const dz = E.querySelector('[data-cf-drop]');
      if (dz) {
        ['dragenter', 'dragover'].forEach(t => dz.addEventListener(t, ev => { ev.preventDefault(); dz.classList.add('sur'); }));
        dz.addEventListener('dragleave', () => dz.classList.remove('sur'));
        dz.addEventListener('drop', ev => { ev.preventDefault(); const f = ev.dataTransfer && ev.dataTransfer.files[0]; if (f) { D.fichier = f; if (!D.titre) { D.titre = f.name.replace(/\.[^.]+$/, ''); } rendre(); } });
      }
      on('[data-cf-envoi]', 'click', envoyerDepot);
      on('[data-cf-range]', 'click', rangerDepot);
      on('[data-cf-as-relance]', 'click', () => lancerAssistant(D.doc.id));
      on('[data-cf-as-passees]', 'click', () => { D.as.passees = !D.as.passees; rendre(); });
      on('[data-cf-as-c]', 'change', e => { const k = e.currentTarget.dataset.cfAsC; if (e.currentTarget.checked) { D.as.coches.add(k); } else { D.as.coches.delete(k); } rendre(); });
      on('[data-cf-as-le]', 'change', e => { D.as.le[e.currentTarget.dataset.cfAsLe] = e.currentTarget.value; });
    }
  }
  // Échap ferme le tiroir, puis la fenêtre (avant l'envoi).
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape' || !st.el || !st.el.isConnected) { return; }
    if (st.sel) { st.sel = null; rendre(); } else if (st.depot && st.depot.etape === 'choix') { st.depot = null; rendre(); }
  });

  window.DashCoffre = {
    racine(shop, o) {
      o = o || {};
      if (o.apiBase) { st.api = o.apiBase; }
      if (o.aller) { st.aller = o.aller; }
      if (!st.el || String(st.shop) !== String(shop)) {
        st.shop = String(shop); st.el = document.createElement('div'); st.el.className = 'cf2';
        Object.assign(st, { d: null, err: null, dos: 'tout', filtre: 'tous', q: '', sel: null, tout: false, depot: null, dates: null, datesErr: null, ajout: null, passees: false, flash: null });
        rendre(); relireTout();
      }
      return st.el;
    },
    relire() { if (st.el) { relireTout(); } },
    /** Les documents du coffre (pour « Joindre du coffre-fort » de la Boîte mail) : [{id, titre, onglet, nom, octets}]. */
    liste(shop, apiBase) {
      if (apiBase) { st.api = apiBase; }
      return lireCoffre(shop).then(d => (d.documents || []).map(x => ({ id: x.id, titre: x.titre, onglet: (d.onglets.find(o => o.cle === x.onglet) || {}).nom || '', nom: x.nom, octets: x.octets })));
    },
  };
  // Revenir au coffre (le rail, un lien) le relit (26/11/2026 : après « Joindre à un e-mail » et l'envoi, « Qui l'a
  // ouvert » n'affichait pas « joint à un mail » avant un rechargement de la page).
  // Seulement en ARRIVANT sur la page (l'évènement part aussi quand l'adresse change sans changer de page).
  let vuePrec = new URLSearchParams(location.search).get('vue');
  window.addEventListener('dashboard:vue', e => { const v = e.detail && e.detail.vue; if (v === 'coffre' && vuePrec !== 'coffre' && st.el) { relireTout(); } vuePrec = v; });
})();
