/* Dashboard magasin › Boîte mail (15/11/2026, « go, commence avec Halle » ; « il me faut tous les supports : templates,
 * messages automatiques, transformer en offre… »).
 *
 * La boîte e-mail du magasin, dans le dashboard : les dossiers, la liste (1/3) et l'e-mail ouvert (2/3), la recherche ;
 * répondre, répondre à tous, transférer, écrire, avec les modèles du réseau et du magasin, des pièces jointes, une offre
 * de TF Buddy en PDF ; archiver, corbeille, suivre, non lu. La sélection (21/11/2026) : une case par e-mail, « tout
 * sélectionner » (la page, puis tout le dossier), archiver, corbeille, effacer pour de bon depuis la corbeille, déplacer
 * vers un dossier — des boutons à micro-icône, pas de liste déroulante — et « Ranger avec l'IA » : l'IA propose un dossier
 * par e-mail, le magasin corrige, puis range. Les archives et la corbeille sont créées si la boîte n'en a pas. Pour vendre : « Créer une offre » transforme l'e-mail en offre
 * (Vendre › Offres, le client retrouvé dans TF Buddy, le jour, le retrait ou la livraison et les produits repérés dans
 * le texte, à vérifier) ; une offre citée s'ouvre ; une fois l'offre créée, la réponse part avec son PDF. Les messages
 * automatiques (accusé de réception, relance d'une offre, rappel la veille) se règlent ici.
 *   Toutes les routes : …/dashboard/boite* (src/franchise/boite_mail.php) — la boîte s'ouvre avec SON mot de passe.
 *   Les offres : …/exploitation/offres-client ; les clients et les produits : …/exploitation/promos-magasin/clients|produits.
 *
 * `window.DashBoite.racine(shop, {apiBase, nomShop, onNonLus, aller, intention})` rend l'élément du module, gardé d'un
 * rendu à l'autre. `intention` : {ecrire: {a, nom, objet, offre}} ou {repondre: {dossier, uid, offre}}.
 * `window.DashBoite.compter(shop, apiBase, cb)` : les non-lus de la réception, pour le rail.
 */
(function () {
  'use strict';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const ico = d => `<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"></path></svg>`;
  const I = {
    ecrire: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z', repondre: 'M9 17l-5-5 5-5M4 12h11a5 5 0 0 1 5 5v2',
    tous: 'M7 17l-5-5 5-5M12 17l-5-5 5-5M7 12h9a5 5 0 0 1 5 5v2', transferer: 'M15 17l5-5-5-5M20 12H9a5 5 0 0 0-5 5v2',
    archiver: 'M3 4h18v4H3zM5 8v12h14V8M10 12h4', corbeille: 'M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14',
    suivi: 'M4 21V4h12l-2 4 2 4H4', pj: 'M21 12l-8.5 8.5a5 5 0 0 1-7-7L14 5a3.5 3.5 0 0 1 5 5l-8.5 8.5a2 2 0 0 1-3-3L15 8',
    offre: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5', cadenas: 'M5 11h14v10H5zM8 11V7a4 4 0 0 1 8 0v4',
    chercher: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM21 21l-5.2-5.2', auto: 'M12 8v4l3 3M3 12a9 9 0 1 0 9-9 9 9 0 0 0-9 9',
    modele: 'M4 4h16v16H4zM4 9h16M9 9v11', nonlu: 'M3 5h18v14H3zM3 6l9 7 9-7',
    lu: 'M3 10l9-6 9 6v10H3zM3 10l9 6 9-6', inbox: 'M3 13h5l2 3h4l2-3h5M5.5 5h13L21 13v6H3v-6z', envoyes: 'M22 2L11 13M22 2l-7 20-4-9-9-4z',
    brouillons: 'M14 3H6v18h12V7zM14 3v4h4M9 13h6M9 17h4', indesirables: 'M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7zM12 8v5M12 16v.5',
    dossier: 'M3 6.5A1.5 1.5 0 0 1 4.5 5H9l2 2h8.5A1.5 1.5 0 0 1 21 8.5v9a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 17.5z',
    nouveau: 'M3 6.5A1.5 1.5 0 0 1 4.5 5H9l2 2h8.5A1.5 1.5 0 0 1 21 8.5v9a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 17.5zM12 10v6M9 13h6',
    ia: 'M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9zM19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z',
    effacer: 'M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 10.5l4 5M14 10.5l-4 5', remettre: 'M9 14L4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3',
    laisser: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2', fermer: 'M6 6l12 12M18 6L6 18',
  };
  const ICO_ROLE = { inbox: 'inbox', drafts: 'brouillons', sent: 'envoyes', archive: 'archiver', junk: 'indesirables', trash: 'corbeille' };
  const nomDossier = d => d ? (d.role ? ROLES[d.role] : String(d.nom || d.brut).replace(/^INBOX[./]/i, '')) : '';
  const icoDossier = d => ico(I[(d && ICO_ROLE[d.role]) || 'dossier']);
  const ROLES = { inbox: 'Réception', drafts: 'Brouillons', sent: 'Envoyés', archive: 'Archives', junk: 'Indésirables', trash: 'Corbeille' };
  const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  const MOIS_L = { janvier: 1, fevrier: 2, mars: 3, avril: 4, mai: 5, juin: 6, juillet: 7, aout: 8, septembre: 9, octobre: 10, novembre: 11, decembre: 12 };
  const norm = s => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const eur = v => v == null || isNaN(v) ? '—' : new Intl.NumberFormat('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v).replace(/[  ]/g, ' ') + ' €';
  const dFr = s => /^\d{4}-\d{2}-\d{2}/.test(s || '') ? s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4) : '';
  function quand(iso) {
    if (!iso) { return ''; }
    const d = new Date(iso), n = new Date();
    if (d.toDateString() === n.toDateString()) { return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); }
    return d.getDate() + ' ' + MOIS[d.getMonth()] + (d.getFullYear() !== n.getFullYear() ? ' ' + d.getFullYear() : '');
  }
  const quandLong = iso => { if (!iso) { return ''; } const d = new Date(iso); return d.toLocaleDateString('fr-BE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) + ' à ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); };
  const nomDe = a => a ? (a.nom || a.adresse) : '';
  const adr = a => a ? (a.nom ? a.nom + ' <' + a.adresse + '>' : a.adresse) : '';

  const st = {
    shop: null, api: '../api/cockpit', el: null, nomShop: '', onNonLus: null, aller: null, intention: null,
    etat: null, err: null, occupe: false, flash: null,
    dossier: 'INBOX', filtre: '', q: '', page: 1, liste: null, listeErr: null,
    sel: null, msg: null, msgErr: null, images: false,
    vue: 'lire', ecr: null, modeles: null, auto: null, autoOuvert: null, modEd: null,
    produits: null, offres: null,
    coches: new Set(), tout: false, derniere: null,   // la sélection : les UID cochés ; tout : tout le dossier (recherche et filtre compris)
    rang: null,                                       // le rangement proposé par l'IA : {lecture, err, props, choix: {uid: cible}, garde: Set, palette}
    nouveauNom: null,                                 // le nom tapé pour « Nouveau dossier » (null : champ fermé)
    porte: { adr: '', mdp: '' },   // ce qui est tapé pour relier ou ouvrir la boîte (un redessin ne le perd pas ; vidé à l'envoi)
  };

  /* --- lectures et écritures ------------------------------------------------------------------------------------ */
  const url = (p, prm) => st.api + p + '?' + new URLSearchParams(Object.assign({ shop: st.shop }, prm || {})).toString();
  function repondre(r) {
    return r.json().catch(() => ({})).then(j => {
      if (r.ok) { return j; }
      if (r.status === 401 && j && (j.etat === 'fermee' || j.etat === 'refusee') && st.etat) { st.etat.ouverte = false; st.etat.erreur = j.error; }
      if (r.status === 409 && j && j.etat === 'non_reliee' && st.etat) { st.etat.reliee = false; st.etat.ouverte = false; }
      return Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status)));
    });
  }
  const lire = (p, prm) => fetch(url(p, prm), { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(repondre);
  const ecrire = (p, corps) => fetch(st.api + p, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify(Object.assign({ shop: st.shop }, corps)) }).then(repondre);
  function flash(ok, texte) { st.flash = { ok, texte }; rendre(); }

  function chargerEtat() {
    return lire('/dashboard/boite').then(d => {
      st.etat = d; st.err = null;
      if (st.onNonLus) { st.onNonLus(d.ouverte ? d.nonLus : null); }
      if (d.ouverte) {
        if (!d.dossiers.some(x => x.brut === st.dossier)) { st.dossier = 'INBOX'; }
        if (!st.liste) { chargerListe(); }
        if (st.intention) { const i = st.intention; st.intention = null; suivreIntention(i); }
      }
    }).catch(e => { st.err = e.message; }).finally(rendre);
  }
  function chargerListe() {
    st.listeErr = null;
    const prm = { dossier: st.dossier, page: st.page };
    if (st.q.trim()) { prm.q = st.q.trim(); }
    if (st.filtre) { prm.filtre = st.filtre; }
    const cle = JSON.stringify(prm); st.listeCle = cle;
    return lire('/dashboard/boite/messages', prm).then(d => {
      if (st.listeCle !== cle) { return; }
      st.liste = d;
      // La sélection garde ce qui est encore là (un e-mail rangé ailleurs n'est plus coché).
      const la = new Set(d.messages.map(m => m.uid)); st.coches.forEach(u => { if (!la.has(u)) { st.coches.delete(u); } });
      if (!st.coches.size) { st.tout = false; }
    })
      .catch(e => { if (st.listeCle === cle) { st.listeErr = e.message; } }).finally(rendre);
  }
  function ouvrirMessage(uid) {
    st.sel = uid; st.msg = null; st.msgErr = null; st.images = false; st.vue = 'lire'; rendre();
    lire('/dashboard/boite/message', { dossier: st.dossier, uid }).then(d => {
      if (st.sel !== uid) { return; }
      st.msg = d;
      const l = st.liste && st.liste.messages.find(m => m.uid === uid);
      if (l && !l.lu) { l.lu = true; const dd = (st.etat.dossiers || []).find(x => x.brut === st.dossier); if (dd && dd.nonLus) { dd.nonLus--; if (dd.role === 'inbox' && st.onNonLus) { st.onNonLus(dd.nonLus); } } }
    }).catch(e => { if (st.sel === uid) { st.msgErr = e.message; } }).finally(rendre);
  }
  /** Le dossier affiché (ses rôle, nom, total). */
  const ici = () => ((st.etat && st.etat.dossiers) || []).find(x => x.brut === st.dossier) || { brut: st.dossier, nom: st.dossier, role: null };
  /** Un geste sur des e-mails : uids, ou tout le dossier (plus.tout) ; plus.nouveau : le dossier à créer en déplaçant. */
  function geste(action, uids, vers, plus) {
    plus = plus || {};
    st.occupe = true; rendre();
    const corps = Object.assign({ dossier: st.dossier, action, vers }, plus.tout ? { tout: { q: st.q.trim(), filtre: st.filtre } } : { uids }, plus.nouveau ? { nouveau: plus.nouveau } : {});
    return ecrire('/dashboard/boite/geste', corps).then(r => {
      const n = r.n || (uids || []).length, pl = n > 1 ? 's' : '', groupe = n > 1 || plus.tout;
      const cree = r.cree ? ` Le dossier « ${action === 'archiver' ? 'Archives' : action === 'corbeille' ? 'Corbeille' : r.cree} » a été créé dans la boîte.` : '';
      const dest = action === 'deplacer' ? (plus.nouveau || nomDossier(((st.etat && st.etat.dossiers) || []).find(x => x.brut === vers))) : '';
      const T = { archiver: groupe ? `${n} e-mail${pl} archivé${pl}.` : 'Archivé.', corbeille: groupe ? `${n} e-mail${pl} mis à la corbeille.` : 'Mis à la corbeille.',
        effacer: `${n} e-mail${pl} effacé${pl} pour de bon.`, deplacer: (groupe ? `${n} e-mail${pl} déplacé${pl}` : 'Déplacé') + (dest ? ` vers « ${dest} ».` : '.'),
        lu: `${n} e-mail${pl} marqué${pl} lu${pl}.`, nonlu: `${n} e-mail${pl} marqué${pl} non lu${pl}.`, suivi: `${n} e-mail${pl} à suivre.`, nonsuivi: `${n} e-mail${pl} plus suivi${pl}.` };
      const part = ['archiver', 'corbeille', 'deplacer', 'effacer'].includes(action);
      if (part) { if (plus.tout || (uids || []).includes(st.sel)) { st.sel = null; st.msg = null; } (uids || []).forEach(u => st.coches.delete(u)); if (plus.tout) { st.coches.clear(); st.tout = false; } st.rang = null; st.nouveauNom = null; }
      if (part || groupe) { flash(true, T[action] + cree); }
      return Promise.all([chargerListe(), chargerEtat()]);
    }).catch(e => flash(false, e.message)).finally(() => { st.occupe = false; rendre(); });
  }
  /** Le geste de la sélection : les cases cochées, ou tout le dossier. */
  function gesteSelection(action, vers, plus) {
    if (action === 'effacer') {
      const n = st.tout ? (st.liste ? st.liste.total : 0) : st.coches.size;
      if (!window.confirm(`Effacer pour de bon ${n} e-mail${n > 1 ? 's' : ''} ? Ils ne pourront plus être récupérés.`)) { return; }
    }
    const uids = [...st.coches];
    return geste(action, uids, vers, Object.assign({ tout: st.tout }, plus || {})).then(() => { if (['lu', 'nonlu', 'suivi', 'nonsuivi'].includes(action)) { st.coches.clear(); st.tout = false; rendre(); } });
  }
  function viderSelection() { st.coches.clear(); st.tout = false; st.rang = null; st.nouveauNom = null; }

  /* --- ranger avec l'IA ----------------------------------------------------------------------------------------- */
  function proposerRangement() {
    const uids = [...st.coches];
    st.rang = { lecture: true, n: uids.length }; rendre();
    ecrire('/dashboard/boite/ranger', { dossier: st.dossier, uids }).then(r => {
      if (!st.rang) { return; }
      const choix = {}; r.propositions.forEach(p => { choix[p.uid] = p.cible; });
      st.rang = { props: r.propositions, choix, garde: new Set(r.propositions.map(p => p.uid)), palette: null, modele: r.modele };
    }).catch(e => { if (st.rang) { st.rang = { err: e.message }; } }).finally(rendre);
  }
  /** Les dossiers proposables : ceux de la boîte (sans rôle), les nouveaux proposés par l'IA, les archives, « laisser ». */
  function ciblesRangement() {
    const R = st.rang, D = ((st.etat && st.etat.dossiers) || []).filter(d => !d.role && d.brut !== st.dossier);
    const nouveaux = [...new Set(Object.values(R.choix).filter(c => c.startsWith('n:')).concat(R.props.map(p => p.cible).filter(c => c.startsWith('n:'))))];
    return [...D.map(d => ({ cible: 'd:' + d.brut, nom: nomDossier(d), ico: I.dossier })), ...nouveaux.map(c => ({ cible: c, nom: c.slice(2), ico: I.nouveau, neuf: true })),
      { cible: 'archive', nom: 'Archives', ico: I.archiver }, { cible: 'laisser', nom: 'Laisser en ' + nomDossier(ici()), ico: I.laisser }];
  }
  function appliquerRangement() {
    const R = st.rang; if (!R || !R.props) { return; }
    const groupes = {};
    R.props.forEach(p => { const c = R.choix[p.uid]; if (R.garde.has(p.uid) && c && c !== 'laisser') { (groupes[c] = groupes[c] || []).push(p.uid); } });
    const L = Object.entries(groupes);
    if (!L.length) { st.rang = null; return flash(true, 'Rien à ranger : tout reste en place.'); }
    st.occupe = true; rendre();
    let n = 0, crees = [];
    const suite = L.reduce((pr, [c, uids]) => pr.then(() => ecrire('/dashboard/boite/geste', c === 'archive' ? { dossier: st.dossier, action: 'archiver', uids }
      : c.startsWith('n:') ? { dossier: st.dossier, action: 'deplacer', nouveau: c.slice(2), uids } : { dossier: st.dossier, action: 'deplacer', vers: c.slice(2), uids })
      .then(r => { n += r.n || uids.length; if (r.cree) { crees.push(c === 'archive' ? 'Archives' : r.cree); } uids.forEach(u => st.coches.delete(u)); })), Promise.resolve());
    const bouges = new Set(L.flatMap(([, u]) => u));
    return suite.then(() => { st.rang = null; if (!st.coches.size) { st.tout = false; } if (bouges.has(st.sel)) { st.sel = null; st.msg = null; }
      flash(true, `${n} e-mail${n > 1 ? 's' : ''} rangé${n > 1 ? 's' : ''} dans ${L.length} dossier${L.length > 1 ? 's' : ''}.` + (crees.length ? ` Créé${crees.length > 1 ? 's' : ''} dans la boîte : ${crees.map(x => '« ' + x + ' »').join(', ')}.` : '')); })
      .catch(e => flash(false, `Rangement interrompu après ${n} e-mail${n > 1 ? 's' : ''} : ${e.message}`))
      .finally(() => { st.occupe = false; Promise.all([chargerListe(), chargerEtat()]); });
  }
  function chargerProduits() { if (st.produits || st.prodLecture) { return st.prodLecture; } st.prodLecture = lire('/exploitation/promos-magasin/produits').then(d => { st.produits = d.produits || []; }).catch(() => { st.produits = []; }); return st.prodLecture; }
  function chargerOffres() { if (st.offres || st.ofLecture) { return st.ofLecture; } st.ofLecture = lire('/exploitation/offres-client').then(d => { st.offres = d.offres || []; }).catch(() => { st.offres = []; }).finally(() => { st.ofLecture = null; rendre(); }); return st.ofLecture; }

  /* --- composer ------------------------------------------------------------------------------------------------- */
  function citation(m) {
    const t = String(m.texte || '').trim();
    return '\n\n' + (m.date ? 'Le ' + quandLong(m.date) + ', ' : '') + nomDe(m.de) + ' a écrit :\n' + t.split('\n').map(l => '> ' + l).join('\n');
  }
  const sansPrefixe = s => String(s || '').replace(/^\s*((re|tr|fw|fwd|réf)\s*:\s*)+/i, '');
  function nouvelEcrit(o) {
    st.ecr = Object.assign({ a: '', cc: '', objet: '', texte: '', reponse: null, transfert: null, offre: null, pieces: [], modele: '', ccOuvert: false }, o || {});
    st.vue = 'ecrire'; rendre();
    if (st.ecr.offre) { chargerOffres(); }
    const f = st.el && st.el.querySelector(st.ecr.a ? '#bx-texte' : '#bx-a'); if (f) { f.focus(); if (f.id === 'bx-texte') { f.setSelectionRange(0, 0); } }
  }
  function ecrireReponse(tous, offre) {
    const m = st.msg; if (!m) { return; }
    const cc = tous ? [...(m.a || []), ...(m.cc || [])].filter(x => x.adresse !== m.moi && x.adresse !== (m.repondreA || {}).adresse) : [];
    nouvelEcrit({ a: adr(m.repondreA || m.de), cc: cc.map(adr).join(', '), ccOuvert: cc.length > 0, objet: 'Re: ' + sansPrefixe(m.objet),
      texte: citation(m), reponse: { dossier: m.dossier, uid: m.uid }, offre: offre || null, contexte: { nom: (m.repondreA || m.de || {}).nom || '', objet: m.objet } });
  }
  function transferer() {
    const m = st.msg; if (!m) { return; }
    nouvelEcrit({ objet: 'Tr: ' + sansPrefixe(m.objet), texte: '\n\n---------- E-mail transféré ----------\nDe : ' + adr(m.de) + '\nObjet : ' + m.objet + '\n\n' + (m.texte || ''), transfert: { dossier: m.dossier, uid: m.uid } });
  }
  /** Un modèle rempli avec ce que l'on sait : le destinataire, l'objet d'origine, le magasin, l'offre jointe. */
  function remplir(t) {
    const e = st.ecr || {}, c = e.contexte || {};
    const nom = c.nom || ((String(e.a).match(/^\s*"?([^"<]+?)"?\s*</) || [])[1] || '');
    const prenom = (() => { let n = nom.trim(); if (n.includes(',')) { n = n.split(',')[1].trim() || n; } const p = n.split(/\s+/)[0] || ''; return p.length > 1 && !/\d|@|^(sa|sprl|srl|bv|nv|asbl|vzw)$/i.test(p) ? p : ''; })();
    const o = e.offre && st.offres ? st.offres.find(x => String(x.id) === String(e.offre)) : null;
    const v = { prenom, nom, objet: sansPrefixe(c.objet || ''), magasin: (st.etat && st.etat.nom) || st.nomShop || '',
      offre: e.offre ? String(e.offre) : '', total: o ? eur(o.total) : '', jour: o && o.jour ? dFr(o.jour) : '' };
    return String(t || '').replace(/\{(prenom|nom|objet|magasin|offre|total|jour)\}/g, (x, k) => v[k] || '').replace(/Bonjour ,/g, 'Bonjour,');
  }
  function appliquerModele(id) {
    const tous = [...((st.modeles || {}).reseau || []), ...((st.modeles || {}).magasin || [])];
    const m = tous.find(x => String(x.id) === String(id)); if (!m || !st.ecr) { return; }
    // La citation de l'e-mail d'origine (« Le … a écrit : » ou « ---------- E-mail transféré ») reste sous le modèle.
    const e = st.ecr, i = e.texte.search(/\n\n(Le .{3,160} a écrit :|-{5,} E-mail transféré)/), cite = i >= 0 ? e.texte.slice(i) : '';
    e.texte = remplir(m.corps) + cite;
    if (m.objet && !e.reponse) { e.objet = remplir(m.objet); }
    e.modele = String(id); rendre();
  }
  function lireFichiers(files) {
    const L = [...files].slice(0, 10);
    return Promise.all(L.map(f => new Promise((ok, ko) => { const r = new FileReader(); r.onload = () => ok({ nom: f.name, type: f.type || 'application/octet-stream', taille: f.size, base64: String(r.result).split(',')[1] || '' }); r.onerror = ko; r.readAsDataURL(f); })));
  }
  function envoyer() {
    const e = st.ecr;
    if (!e.a.trim()) { return flash(false, 'Un destinataire, au moins.'); }
    const poids = e.pieces.reduce((s, p) => s + (p.taille || 0), 0);
    if (poids > 15e6) { return flash(false, 'Les pièces jointes dépassent 15 Mo.'); }
    st.occupe = true; st.flash = null; rendre();
    ecrire('/dashboard/boite/envoyer', { a: e.a, cc: e.cc, objet: e.objet, texte: e.texte, reponse: e.reponse, transfert: e.transfert, offre: e.offre || null,
      pieces: e.pieces.map(p => ({ nom: p.nom, type: p.type, base64: p.base64 })) })
      .then(r => { st.ecr = null; st.vue = 'lire'; flash(!r.avertissement, r.avertissement || 'E-mail envoyé depuis ' + st.etat.adresse + '.'); chargerListe(); chargerEtat(); if (st.sel && st.msg) { st.msg.repondu = true; } })
      .catch(x => flash(false, 'L’e-mail n’est pas parti : ' + x.message))
      .finally(() => { st.occupe = false; rendre(); });
  }
  function suivreIntention(i) {
    if (i.ecrire) {
      const w = i.ecrire;
      const ouvrir = () => { nouvelEcrit({ a: w.a ? (w.nom ? w.nom + ' <' + w.a + '>' : w.a) : '', objet: w.objet || '', offre: w.offre || null, contexte: { nom: w.nom || '', objet: w.objet || '' } }); chargerModeles().then(() => { if (w.offre) { chargerOffres().then(() => appliquerModele('b-offre')); } }); };
      ouvrir();
    } else if (i.repondre) {
      const r = i.repondre;
      st.dossier = r.dossier || 'INBOX'; st.sel = r.uid;
      lire('/dashboard/boite/message', { dossier: st.dossier, uid: r.uid, garder: 1 }).then(d => { st.msg = d; ecrireReponse(false, r.offre); return chargerModeles(); })
        .then(() => chargerOffres()).then(() => { if (r.offre) { appliquerModele('b-offre'); } }).catch(e => flash(false, e.message));
    }
  }

  /* --- transformer un e-mail en offre --------------------------------------------------------------------------- */
  /** Le jour d'un texte : « 21/11 », « 21/11/2026 », « le 3 décembre » ; l'année manquante est la prochaine occurrence. */
  function jourDans(t) {
    const auj = new Date(); auj.setHours(0, 0, 0, 0);
    const iso = (j, m, a) => {
      if (m < 1 || m > 12 || j < 1 || j > 31) { return null; }
      let an = a ? (a < 100 ? 2000 + a : a) : auj.getFullYear();
      let d = new Date(an, m - 1, j);
      if (!a && d < auj) { an++; d = new Date(an, m - 1, j); }
      return an + '-' + String(m).padStart(2, '0') + '-' + String(j).padStart(2, '0');
    };
    const n = norm(t);
    const m1 = n.match(/\b(\d{1,2})[\/.](\d{1,2})(?:[\/.](\d{2,4}))?\b/);
    const m2 = n.match(/\b(\d{1,2})(?:er)?\s+(janvier|fevrier|mars|avril|mai|juin|juillet|aout|septembre|octobre|novembre|decembre)(?:\s+(\d{4}))?/);
    const c = [m1 && { i: m1.index, v: iso(+m1[1], +m1[2], m1[3] ? +m1[3] : 0) }, m2 && { i: m2.index, v: iso(+m2[1], MOIS_L[m2[2]], m2[3] ? +m2[3] : 0) }].filter(x => x && x.v).sort((a, b) => a.i - b.i);
    return c.length ? c[0].v : '';
  }
  /** Les produits demandés : « 30 croissants », « 2 tartes aux pommes » → les produits du catalogue qui y répondent. */
  function lignesDans(t, produits) {
    const n = norm(t), out = [], vus = new Set();
    const NON = /^(personnes?|pers|h|heures?|euros?|eur|ans|min|minutes?|jours?|semaines?|mois|km|janvier|fevrier|mars|avril|mai|juin|juillet|aout|septembre|octobre|novembre|decembre)$/;
    const re = /\b(\d{1,4})\s*(?:x\s+)?([a-z][a-z'-]{2,})((?:\s+(?:aux?|a la|de|du|des|d'|en|au)?\s*[a-z'-]{3,}){0,2})/g;
    let m;
    while ((m = re.exec(n)) && out.length < 8) {
      const q = +m[1], mot = m[2].replace(/['-]/g, ''), suite = m[3] || '';
      if (!q || q > 2000 || NON.test(mot)) { continue; }
      const racine = mot.replace(/(x|s)$/, '');
      if (racine.length < 3) { continue; }
      const mots = (mot + ' ' + suite).split(/[^a-z]+/).filter(w => w.length > 2).map(w => w.replace(/(x|s)$/, ''));
      const cands = produits.map(p => {
        const pm = norm(p.nom).split(/[^a-z]+/).filter(w => w.length > 2).map(w => w.replace(/(x|s)$/, ''));
        if (!pm.some(w => w === racine || (w.length > 4 && racine.length > 4 && (w.startsWith(racine) || racine.startsWith(w))))) { return null; }
        return { p, score: mots.filter(w => pm.includes(w)).length * 10 - pm.length };
      }).filter(Boolean).sort((a, b) => b.score - a.score);
      if (!cands.length || vus.has(cands[0].p.id)) { continue; }
      vus.add(cands[0].p.id);
      out.push({ idProduit: cands[0].p.id, nom: cands[0].p.nom, qte: q, pu: cands[0].p.prixTtc });
    }
    return out;
  }
  function creerOffre() {
    const m = st.msg; if (!m || !st.aller) { return; }
    st.occupe = true; rendre();
    const texte = String(m.texte || '').split(/\n(?:Le .{3,120} a écrit\s*:|-{5,}|De ?: )/)[0].split('\n').filter(l => !/^\s*>/.test(l)).join('\n').trim();
    const qui = m.repondreA || m.de || {};
    const client = () => {
      if (!qui.adresse) { return Promise.resolve(null); }
      const essai = type => lire('/exploitation/promos-magasin/clients', { type, q: qui.adresse }).then(d => (d.clients || []).filter(c => !c.email || String(c.email).toLowerCase() === qui.adresse)).catch(() => []);
      return essai('particulier').then(l => l.length ? l : essai('b2b')).then(l => l.length === 1 ? { id: l[0].id, nom: l[0].nom, b2b: l[0].b2b, ville: l[0].ville } : null);
    };
    Promise.all([client(), chargerProduits()]).then(([c]) => {
      const lignes = lignesDans(m.objet + '\n' + texte, st.produits || []);
      const jour = jourDans(m.objet + '\n' + texte);
      const note = [c ? 'Client retrouvé dans TF Buddy : ' + c.nom + '.' : 'Client : à choisir (' + qui.adresse + ' n’est pas reconnu tel quel dans TF Buddy).',
        jour ? 'Jour repéré : ' + dFr(jour) + '.' : '', lignes.length ? lignes.length + ' produit' + (lignes.length > 1 ? 's' : '') + ' repéré' + (lignes.length > 1 ? 's' : '') + ' dans l’e-mail' : 'Aucun produit repéré : ajoutez-les.', '— à vérifier avant de créer.'].filter(Boolean).join(' ');
      st.aller('offres', { offre: { nom: sansPrefixe(m.objet).slice(0, 120), description: texte.slice(0, 700), jour, retrait: !/\blivr/i.test(texte), client: c, clientQ: c ? '' : qui.adresse,
        lignes, note, source: { dossier: m.dossier, uid: m.uid, de: qui } } });
    }).finally(() => { st.occupe = false; rendre(); });
  }

  /* --- modèles et messages automatiques ------------------------------------------------------------------------- */
  function chargerModeles() { return lire('/dashboard/boite/modeles').then(d => { st.modeles = d; }).catch(e => { st.modeles = { reseau: [], magasin: [], err: e.message }; }).finally(rendre); }
  function chargerAuto() { return lire('/dashboard/boite/automatiques').then(d => { st.auto = d; }).catch(e => { st.auto = { err: e.message }; }).finally(rendre); }

  /* --- ce qui se voit ------------------------------------------------------------------------------------------- */
  function vueRelier() {
    const e = st.etat;
    return `<div class="bx-porte"><div class="bx-porte-c">${ico(I.nonlu)}<h2>Relier la boîte mail du magasin</h2>
      <p>L’adresse e-mail du magasin et le mot de passe de sa boîte (chez OVH : ${esc(e.serveur)}). Le mot de passe est essayé auprès du serveur, puis gardé côté serveur avec les clés de l’ERP ; il ne s’affiche plus jamais.</p>
      <label>Adresse e-mail<input class="bx-in" id="bx-r-adr" type="email" autocomplete="username" placeholder="halle@atelierby.be" value="${esc(st.porte.adr)}"></label>
      <label>Mot de passe de la boîte<input class="bx-in" id="bx-r-mdp" type="password" autocomplete="current-password" value="${esc(st.porte.mdp)}"></label>
      <button type="button" class="db-btn prim" data-bx="relier"${st.occupe ? ' disabled' : ''}>Relier la boîte</button></div></div>`;
  }
  function vueOuvrir() {
    const e = st.etat;
    return `<div class="bx-porte"><div class="bx-porte-c">${ico(I.cadenas)}<h2>${esc(e.adresse)}</h2>
      <p>${e.erreur ? esc(e.erreur) : 'La boîte du magasin s’ouvre avec son mot de passe, une fois par appareil (30 jours).'}</p>
      <label>Mot de passe de la boîte<input class="bx-in" id="bx-o-mdp" type="password" autocomplete="current-password" value="${esc(st.porte.mdp)}"></label>
      <button type="button" class="db-btn prim" data-bx="ouvrir"${st.occupe ? ' disabled' : ''}>Ouvrir la boîte</button></div></div>`;
  }
  /** Un dossier en bouton (rectangle à coins arrondis, micro-icône, compteur) : la barre, « Déplacer vers ». */
  function boutonDossier(d, attr, on, compte) {
    return `<button type="button" class="bx-dos${on ? ' is-on' : ''}${d.role ? ' bx-dos--' + d.role : ''}" ${attr}${st.occupe && !/data-bxd=/.test(attr) ? ' disabled' : ''}>${icoDossier(d)}<span>${esc(nomDossier(d))}</span>${compte ? `<b class="bx-dos-n">${compte}</b>` : ''}</button>`;
  }
  function barre() {
    const e = st.etat, D = e.dossiers || [], hors = st.vue === 'modeles' || st.vue === 'auto';
    const dos = d => boutonDossier(d, `data-bxd="${esc(d.brut)}"`, st.dossier === d.brut && !hors, d.role === 'inbox' ? d.nonLus : d.role === 'drafts' ? d.total : (!d.role && d.nonLus) ? d.nonLus : 0);
    const roles = D.filter(d => d.role), autres = D.filter(d => !d.role);
    return `<div class="bx-barre">
      <label class="bx-q">${ico(I.chercher)}<input id="bx-q" type="search" placeholder="Chercher dans ${esc(nomDossier(ici()))}…" value="${esc(st.q)}"></label>
      <span class="bx-sep"></span>
      <button type="button" class="db-btn prim" data-bx="ecrire">${ico(I.ecrire)}Écrire</button>
      <button type="button" class="db-btn${st.vue === 'modeles' ? ' is-on' : ''}" data-bx="modeles" title="Les modèles de réponse">${ico(I.modele)}Modèles</button>
      <button type="button" class="db-btn${st.vue === 'auto' ? ' is-on' : ''}" data-bx="auto" title="Accusé de réception, relance d’une offre, rappel la veille">${ico(I.auto)}Messages automatiques</button></div>
      <nav class="bx-dossiers" aria-label="Dossiers de la boîte">${roles.map(dos).join('')}${autres.length ? '<span class="bx-dos-sep"></span>' + autres.map(dos).join('') : ''}</nav>`;
  }
  function liste() {
    const L = st.liste;
    const filtres = [['', 'Tous'], ['nonlus', 'Non lus'], ['suivis', 'Suivis'], ['pj', 'Avec pièce jointe']];
    const M = L ? L.messages : [], n = st.coches.size, tousPage = M.length > 0 && M.every(m => st.coches.has(m.uid));
    const corb = ['trash', 'junk'].includes(ici().role), dis = st.occupe ? ' disabled' : '';
    // La tête : « tout sélectionner » et, dès qu'il y a une sélection, ses gestes rapides (le détail est à droite).
    const tete = `<div class="bx-filtres"><label class="bx-coche bx-coche--tout" title="Tout sélectionner dans la page"><input type="checkbox" id="bx-tout"${tousPage || st.tout ? ' checked' : ''}${!M.length ? ' disabled' : ''}><i></i><span>${n || st.tout ? (st.tout ? 'Tout le dossier' : n + ' sélectionné' + (n > 1 ? 's' : '')) : 'Tout sélectionner'}</span></label>
      ${n || st.tout ? `<span class="bx-rapides">
        ${corb ? `<button type="button" class="bx-rapide bx-rapide--stop" data-bxs="effacer" title="Effacer pour de bon"${dis}>${ico(I.effacer)}</button>` : `<button type="button" class="bx-rapide" data-bxs="archiver" title="Archiver"${dis}>${ico(I.archiver)}</button><button type="button" class="bx-rapide" data-bxs="corbeille" title="Mettre à la corbeille"${dis}>${ico(I.corbeille)}</button>`}
        <button type="button" class="bx-rapide" data-bxs="lu" title="Marquer lu"${dis}>${ico(I.lu)}</button>
        <button type="button" class="bx-rapide" data-bx="deselect" title="Tout désélectionner">${ico(I.fermer)}</button></span>`
      : filtres.map(([k, l]) => `<button type="button" class="bx-chip${st.filtre === k ? ' on' : ''}" data-bxf="${k}">${l}</button>`).join('')}</div>`;
    if (st.listeErr && !L) { return `<div class="bx-liste">${tete}<div class="db-err">La liste ne se lit pas : ${esc(st.listeErr)}</div></div>`; }
    if (!L) { return `<div class="bx-liste">${tete}<div class="db-sk" style="height:320px;margin:10px"></div></div>`; }
    const env = ici().role === 'sent';
    const items = M.map(m => `<div class="bx-it${m.uid === st.sel ? ' on' : ''}${m.lu ? '' : ' nonlu'}${st.coches.has(m.uid) || st.tout ? ' coche' : ''}">
        <label class="bx-coche"><input type="checkbox" data-bxc="${m.uid}"${st.coches.has(m.uid) || st.tout ? ' checked' : ''} aria-label="Sélectionner"><i></i></label>
        <button type="button" class="bx-it-b" data-bxu="${m.uid}">
        <span class="bx-it-h"><span class="bx-it-de">${esc(env ? 'À : ' + (m.a || []).map(nomDe).join(', ') : nomDe(m.de) || '(sans expéditeur)')}</span><span class="bx-it-q">${esc(quand(m.date))}</span></span>
        <span class="bx-it-o">${esc(m.objet || '(sans objet)')}</span>
        <span class="bx-it-i">${m.pj ? ico(I.pj) : ''}${m.suivi ? `<span class="bx-suivi">${ico(I.suivi)}</span>` : ''}${m.repondu ? ico(I.repondre) : ''}${(m.offres || []).map(o => `<span class="bx-of">offre ${o}</span>`).join('')}</span></button></div>`).join('');
    const pages = Math.ceil(L.total / L.parPage);
    return `<div class="bx-liste">${tete}<div class="bx-items">${items || `<p class="bx-vide">${st.q || st.filtre ? 'Aucun e-mail ne répond.' : 'Aucun e-mail dans ce dossier.'}</p>`}</div>
      <div class="bx-pied">${L.total} e-mail${L.total > 1 ? 's' : ''}${pages > 1 ? ` · page ${L.page}/${pages} <button type="button" class="bx-lien" data-bxp="-1"${L.page <= 1 ? ' disabled' : ''}>‹</button><button type="button" class="bx-lien" data-bxp="1"${L.page >= pages ? ' disabled' : ''}>›</button>` : ''} · <button type="button" class="bx-lien" data-bx="relire">relire</button></div></div>`;
  }
  /** La sélection, dans le volet de droite : ses gestes, « Déplacer vers » en boutons, et « Ranger avec l'IA ». */
  function vueSelection() {
    const L = st.liste, d = ici(), n = st.tout ? (L ? L.total : 0) : st.coches.size, pl = n > 1 ? 's' : '', dis = st.occupe ? ' disabled' : '';
    const corb = ['trash', 'junk'].includes(d.role), pageEntiere = L && L.messages.length && L.messages.every(m => st.coches.has(m.uid));
    const tuile = (g, ic, lib, cls) => `<button type="button" class="bx-tuile${cls ? ' ' + cls : ''}" data-bxs="${g}"${dis}>${ico(ic)}<span>${lib}</span></button>`;
    const gestes = corb
      ? tuile('effacer', I.effacer, 'Effacer pour de bon', 'bx-tuile--stop') + `<button type="button" class="bx-tuile" data-bxv="INBOX"${dis}>${ico(I.remettre)}<span>Remettre en Réception</span></button>` + tuile('lu', I.lu, 'Marquer lu')
      : tuile('archiver', I.archiver, 'Archiver') + tuile('corbeille', I.corbeille, 'Mettre à la corbeille') + tuile('lu', I.lu, 'Marquer lu') + tuile('nonlu', I.nonlu, 'Marquer non lu') + tuile('suivi', I.suivi, 'Suivre');
    const vers = ((st.etat && st.etat.dossiers) || []).filter(x => x.brut !== st.dossier && x.role !== 'drafts' && x.role !== 'sent' && !(corb && x.role === 'inbox'));
    const nouveau = st.nouveauNom != null
      ? `<span class="bx-dos bx-dos--saisie">${ico(I.nouveau)}<input id="bx-nouveau" value="${esc(st.nouveauNom)}" placeholder="Nom du dossier" maxlength="60"><button type="button" class="bx-lien" data-bx="creerDeplacer"${dis}>Créer et déplacer</button></span>`
      : `<button type="button" class="bx-dos bx-dos--plus" data-bx="nouveauDossier"${dis}>${ico(I.nouveau)}<span>Nouveau dossier</span></button>`;
    return `<div class="bx-lire bx-sel">
      <div class="bx-sel-h"><span class="bx-sel-n">${n}</span><div><h2>${n} e-mail${pl} sélectionné${pl}</h2>
        <span class="bx-mu">dans ${esc(nomDossier(d))}${st.q || st.filtre ? ' (recherche et filtre compris)' : ''}${!st.tout && pageEntiere && L.total > L.messages.length ? ` · <button type="button" class="bx-lien" data-bx="toutDossier">Sélectionner les ${L.total} e-mails du dossier</button>` : ''}${st.tout ? ' · <button type="button" class="bx-lien" data-bx="pageSeule">Revenir à la page</button>' : ''}</span></div>
        <button type="button" class="db-btn" data-bx="deselect">${ico(I.fermer)}Tout désélectionner</button></div>
      <h3 class="bx-t">Gestes</h3><div class="bx-tuiles">${gestes}</div>
      ${corb && d.role === 'trash' ? '<p class="bx-mu bx-sel-note">Ce qui est effacé de la corbeille ne se récupère plus, ni ici, ni dans le webmail, ni sur le téléphone.</p>' : ''}
      <h3 class="bx-t">Déplacer vers</h3><div class="bx-dossiers bx-dossiers--vers">${vers.map(x => boutonDossier(x, `data-bxv="${esc(x.brut)}"`, false, 0)).join('')}${nouveau}</div>
      ${corb ? '' : vueRangement(n)}</div>`;
  }
  /** « Ranger avec l'IA » : l'appel, puis la proposition par dossier d'arrivée, chaque e-mail corrigeable. */
  function vueRangement(n) {
    const R = st.rang, dis = st.occupe ? ' disabled' : '';
    const intro = `<div class="bx-ia-h"><span class="bx-ia-ico">${ico(I.ia)}</span><div><b>Ranger avec l’IA</b><p class="bx-mu">L’IA lit l’expéditeur, l’objet et le début de chaque e-mail, et propose un dossier — un dossier de la boîte, ou un nouveau. Ce qui attend encore une réponse reste en place. Rien ne bouge avant « Ranger ».</p></div></div>`;
    if (!R) {
      const trop = st.tout || n > 50;
      return `<div class="bx-ia">${intro}<div class="bx-actions"><button type="button" class="db-btn prim" data-bx="ranger"${trop || st.occupe ? ' disabled' : ''}>${ico(I.ia)}Proposer un rangement</button></div>${trop ? '<p class="bx-mu">L’IA range 50 e-mails à la fois : choisissez-les dans la page.</p>' : ''}</div>`;
    }
    if (R.lecture) { return `<div class="bx-ia">${intro}<div class="bx-ia-lit"><span class="bx-ia-onde"><i></i><i></i><i></i></span>L’IA lit ${R.n} e-mail${R.n > 1 ? 's' : ''}…</div></div>`; }
    if (R.err) { return `<div class="bx-ia">${intro}<div class="db-err">${esc(R.err)}</div><div class="bx-actions"><button type="button" class="db-btn" data-bx="rangAnnuler">Fermer</button><button type="button" class="db-btn prim" data-bx="ranger">Réessayer</button></div></div>`; }
    const C = ciblesRangement(), parCible = {};
    R.props.forEach(p => { const c = R.choix[p.uid] || 'laisser'; (parCible[c] = parCible[c] || []).push(p); });
    const ordre = C.map(x => x.cible).filter(c => parCible[c]);
    const aRanger = R.props.filter(p => R.garde.has(p.uid) && (R.choix[p.uid] || 'laisser') !== 'laisser');
    const nbDos = new Set(aRanger.map(p => R.choix[p.uid])).size;
    const groupe = c => {
      const x = C.find(k => k.cible === c) || { nom: c, ico: I.dossier }, L = parCible[c];
      return `<div class="bx-rg${c === 'laisser' ? ' bx-rg--laisser' : ''}"><div class="bx-rg-h">${ico(x.ico)}<b>${esc(x.nom)}</b>${x.neuf ? '<span class="bx-badge bx-badge--neuf">nouveau dossier</span>' : ''}<span class="bx-rg-n">${L.length}</span></div>
        ${L.map(p => `<div class="bx-rg-l${R.garde.has(p.uid) || c === 'laisser' ? '' : ' off'}">${c === 'laisser' ? '<span class="bx-rg-place"></span>' : `<label class="bx-coche"><input type="checkbox" data-bxrg="${p.uid}"${R.garde.has(p.uid) ? ' checked' : ''} aria-label="Ranger cet e-mail"><i></i></label>`}
          <div class="bx-rg-t"><span class="bx-rg-de">${esc(nomDe(p.de) || '(sans expéditeur)')}</span><span class="bx-rg-o">${esc(p.objet || '(sans objet)')}</span>${p.raison ? `<span class="bx-rg-r">${ico(I.ia)}${esc(p.raison)}</span>` : ''}</div>
          <button type="button" class="bx-lien" data-bxrp="${p.uid}">${R.palette === p.uid ? 'Fermer' : 'Changer'}</button></div>
          ${R.palette === p.uid ? `<div class="bx-dossiers bx-dossiers--palette">${C.map(k => `<button type="button" class="bx-dos${(R.choix[p.uid] || 'laisser') === k.cible ? ' is-on' : ''}" data-bxrc="${p.uid}" data-cible="${esc(k.cible)}">${ico(k.ico)}<span>${esc(k.nom)}</span></button>`).join('')}</div>` : ''}`).join('')}</div>`;
    };
    return `<div class="bx-ia bx-ia--prop">${intro}
      <div class="bx-rg-tete"><b>Rangement proposé</b><span class="bx-mu">${R.props.length} e-mail${R.props.length > 1 ? 's' : ''} · ${ordre.filter(c => c !== 'laisser').length} dossier${ordre.filter(c => c !== 'laisser').length > 1 ? 's' : ''}${ordre.some(c => c.startsWith('n:')) ? ' dont ' + ordre.filter(c => c.startsWith('n:')).length + ' à créer' : ''}</span></div>
      ${ordre.map(groupe).join('')}
      <div class="bx-actions"><button type="button" class="db-btn" data-bx="rangAnnuler"${dis}>Annuler</button><button type="button" class="db-btn prim" data-bx="rangAppliquer"${!aRanger.length || st.occupe ? ' disabled' : ''}>${st.occupe ? 'Rangement…' : `Ranger ${aRanger.length} e-mail${aRanger.length > 1 ? 's' : ''}${nbDos ? ` dans ${nbDos} dossier${nbDos > 1 ? 's' : ''}` : ''}`}</button></div></div>`;
  }
  function corpsHtml(m) {
    const api = new URL(st.api, location.href).href.replace(/\/$/, '');
    const csp = `default-src 'none'; style-src 'unsafe-inline'; font-src data:; img-src ${st.images ? 'https: http: data:' : "'self' data:"}`;
    const doc = `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="${csp}"><base target="_blank"><style>body{margin:0;padding:4px 2px;font:14px/1.55 Helvetica,Arial,sans-serif;color:#1c1a17;word-wrap:break-word}img{max-width:100%;height:auto}blockquote{margin:8px 0;padding-left:10px;border-left:3px solid #d9d2c7;color:#5c5852}</style></head><body>${String(m.html).split('{API}').join(api)}</body></html>`;
    return `<iframe class="bx-cadre" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" srcdoc="${esc(doc)}" title="Contenu de l’e-mail"></iframe>`;
  }
  function lecture() {
    if (!st.sel) { return `<div class="bx-lire bx-lire--vide">${st.liste && st.liste.messages.length ? 'Choisissez un e-mail dans la liste.' : ''}</div>`; }
    if (st.msgErr) { return `<div class="bx-lire"><div class="db-err">L’e-mail ne s’ouvre pas : ${esc(st.msgErr)}</div></div>`; }
    const m = st.msg;
    if (!m) { return '<div class="bx-lire"><div class="db-sk" style="height:60px;margin-bottom:12px"></div><div class="db-sk" style="height:320px"></div></div>'; }
    const de = m.de || {}, moi = de.adresse === m.moi, dis = st.occupe ? ' disabled' : '';
    const offres = (m.offres || []).map(o => `<button type="button" class="db-btn" data-bxoffre="${o}">${ico(I.offre)}Ouvrir l’offre n° ${o}</button>`).join('');
    const piece = p => `${st.api}/dashboard/boite/piece?${new URLSearchParams({ shop: st.shop, dossier: m.dossier, uid: m.uid, partie: p.id })}`;
    const ko = n => n < 1024 ? n + ' o' : n < 1048576 ? Math.round(n / 1024) + ' Ko' : (n / 1048576).toFixed(1).replace('.', ',') + ' Mo';
    const distantes = m.html && /<img[^>]+src=["']?https?:/i.test(m.html) && !st.images;
    return `<div class="bx-lire">
      <div class="bx-m-h"><h2>${esc(m.objet || '(sans objet)')}</h2>
        <div class="bx-m-qui"><span class="bx-av">${esc((nomDe(de)[0] || '?').toUpperCase())}</span><div><b>${esc(nomDe(de))}</b> <span class="bx-mu">&lt;${esc(de.adresse || '')}&gt;</span>
          <div class="bx-mu">à ${esc((m.a || []).map(nomDe).join(', ') || '—')}${(m.cc || []).length ? ' · cc ' + esc(m.cc.map(nomDe).join(', ')) : ''} · ${esc(quandLong(m.date))}</div></div></div></div>
      <div class="bx-gestes">
        <button type="button" class="db-btn" data-bx="repondre"${dis}>${ico(I.repondre)}Répondre</button>
        ${(m.a || []).length + (m.cc || []).length > 1 ? `<button type="button" class="db-btn" data-bx="tous"${dis}>${ico(I.tous)}Répondre à tous</button>` : ''}
        <button type="button" class="db-btn" data-bx="transferer"${dis}>${ico(I.transferer)}Transférer</button>
        <span class="bx-sep"></span>
        ${ici().role === 'archive' ? '' : `<button type="button" class="db-btn bx-ic" data-bxg="archiver" title="Archiver"${dis}>${ico(I.archiver)}</button>`}
        ${['trash', 'junk'].includes(ici().role) ? `<button type="button" class="db-btn bx-ic bx-stop" data-bxg="effacer" title="Effacer pour de bon"${dis}>${ico(I.effacer)}</button>` : `<button type="button" class="db-btn bx-ic" data-bxg="corbeille" title="Corbeille"${dis}>${ico(I.corbeille)}</button>`}
        <button type="button" class="db-btn bx-ic${m.suivi ? ' is-on' : ''}" data-bxg="${m.suivi ? 'nonsuivi' : 'suivi'}" title="${m.suivi ? 'Ne plus suivre' : 'Suivre'}"${dis}>${ico(I.suivi)}</button>
        <button type="button" class="db-btn bx-ic" data-bxg="nonlu" title="Marquer non lu"${dis}>${ico(I.nonlu)}</button></div>
      ${!moi ? `<div class="bx-vendre"><span>${offres ? 'Cet e-mail parle d’une offre.' : 'Une demande ? Transformez-la en offre : client, jour, produits repérés.'}</span>${offres}<button type="button" class="db-btn prim" data-bx="offre"${dis}>${ico(I.offre)}Créer une offre</button></div>` : ''}
      ${distantes ? '<div class="bx-note">Les images distantes sont bloquées (elles diraient à l’expéditeur que l’e-mail est lu). <button type="button" class="bx-lien" data-bx="images">Les afficher</button></div>' : ''}
      ${m.lourd ? '<div class="bx-note">Cet e-mail dépasse 25 Mo : il ne s’ouvre pas ici en entier.</div>' : ''}
      <div class="bx-corps">${m.html ? corpsHtml(m) : `<pre class="bx-texte">${esc(m.texte || '')}</pre>`}</div>
      ${(m.pieces || []).length ? `<div class="bx-pieces">${m.pieces.map(p => `<a class="bx-piece" href="${esc(piece(p))}" target="_blank" rel="noopener">${ico(I.pj)}<span>${esc(p.nom)}</span><small>${ko(p.taille)}</small></a>`).join('')}</div>` : ''}
    </div>`;
  }
  function vueEcrire() {
    const e = st.ecr, M = st.modeles;
    const opts = M ? [...(M.reseau || []).map(x => [x.id, x.nom, 'Modèles du réseau']), ...(M.magasin || []).map(x => [x.id, x.nom, 'Modèles du magasin'])] : [];
    const grp = g => opts.filter(o => o[2] === g).map(([id, n]) => `<option value="${esc(id)}"${String(e.modele) === String(id) ? ' selected' : ''}>${esc(n)}</option>`).join('');
    const ofs = (st.offres || []).filter(o => o.etat !== 'arch');
    return `<div class="bx-lire bx-ecrire"><div class="bx-m-h"><h2>${e.reponse ? 'Répondre' : e.transfert ? 'Transférer' : 'Nouvel e-mail'}</h2><span class="bx-mu">depuis ${esc(st.etat.adresse)}</span></div>
      <div class="bx-champs">
        <label><span>À</span><input class="bx-in" id="bx-a" value="${esc(e.a)}" placeholder="adresse@exemple.be, …"></label>
        ${e.ccOuvert ? `<label><span>Cc</span><input class="bx-in" id="bx-cc" value="${esc(e.cc)}"></label>` : '<button type="button" class="bx-lien bx-cc" data-bx="cc">+ Cc</button>'}
        <label><span>Objet</span><input class="bx-in" id="bx-objet" value="${esc(e.objet)}"></label>
        <div class="bx-outils"><select class="bx-in" id="bx-modele"><option value="">${M ? 'Insérer un modèle…' : 'lecture des modèles…'}</option>${M ? `<optgroup label="Modèles du réseau">${grp('Modèles du réseau')}</optgroup>${(M.magasin || []).length ? `<optgroup label="Modèles du magasin">${grp('Modèles du magasin')}</optgroup>` : ''}` : ''}</select>
          <select class="bx-in" id="bx-offre"><option value="">${st.offres ? 'Joindre une offre (PDF de TF Buddy)…' : 'lecture des offres…'}</option>${ofs.map(o => `<option value="${o.id}"${String(e.offre) === String(o.id) ? ' selected' : ''}>Offre n° ${o.id} · ${esc(o.nom || '')} · ${esc(o.client ? o.client.nom : 'sans client')} · ${eur(o.total)}</option>`).join('')}</select>
          <label class="db-btn bx-fichier">${ico(I.pj)}Joindre un fichier<input type="file" id="bx-fichiers" multiple hidden></label></div>
        ${e.offre || e.pieces.length ? `<div class="bx-pieces">${e.offre ? `<span class="bx-piece">${ico(I.offre)}<span>Offre n° ${esc(e.offre)}.pdf</span><button type="button" class="bx-x" data-bxx="offre" aria-label="Retirer">×</button></span>` : ''}${e.pieces.map((p, i) => `<span class="bx-piece">${ico(I.pj)}<span>${esc(p.nom)}</span><button type="button" class="bx-x" data-bxx="${i}" aria-label="Retirer">×</button></span>`).join('')}</div>` : ''}
        ${e.transfert ? '<div class="bx-note">L’e-mail d’origine part en pièce jointe (avec ses propres pièces jointes).</div>' : ''}
        <textarea class="bx-in bx-zone" id="bx-texte" rows="16">${esc(e.texte)}</textarea></div>
      <div class="bx-actions"><button type="button" class="db-btn" data-bx="annuler">Annuler</button><button type="button" class="db-btn prim" data-bx="envoyer"${st.occupe ? ' disabled' : ''}>${st.occupe ? 'Envoi…' : 'Envoyer'}</button></div></div>`;
  }
  function vueModeles() {
    const M = st.modeles;
    if (!M) { return '<div class="bx-plein"><div class="db-sk" style="height:300px"></div></div>'; }
    const ed = st.modEd;
    const carte = (x, reseau) => `<div class="bx-mod"><div class="bx-mod-h"><b>${esc(x.nom)}</b><span>${reseau ? '<span class="bx-badge">réseau</span><button type="button" class="bx-lien" data-bxmd="' + esc(x.id) + '">Dupliquer</button>' : `<button type="button" class="bx-lien" data-bxme="${x.id}">Modifier</button><button type="button" class="bx-lien bx-stop" data-bxms="${x.id}">Supprimer</button>`}</span></div>
      ${x.objet ? `<div class="bx-mu">Objet : ${esc(x.objet)}</div>` : ''}<pre class="bx-mod-c">${esc(x.corps)}</pre></div>`;
    return `<div class="bx-plein"><div class="bx-cols"><div>
      <h3 class="bx-t">Les modèles du magasin</h3>${(M.magasin || []).map(x => carte(x, false)).join('') || '<p class="bx-mu">Pas encore de modèle à vous : dupliquez un modèle du réseau, ou écrivez-en un.</p>'}
      <h3 class="bx-t">Les modèles du réseau</h3>${(M.reseau || []).map(x => carte(x, true)).join('')}</div>
      <div><div class="bx-form"><h3 class="bx-t">${ed && ed.id ? 'Modifier le modèle' : 'Nouveau modèle'}</h3>
        <label>Nom<input class="bx-in" id="bx-m-nom" value="${esc(ed ? ed.nom : '')}" placeholder="ex. Réponse anniversaire"></label>
        <label>Objet<input class="bx-in" id="bx-m-objet" value="${esc(ed ? ed.objet : '')}" placeholder="ex. Re: {objet}"></label>
        <label>Texte<textarea class="bx-in" id="bx-m-corps" rows="10">${esc(ed ? ed.corps : '')}</textarea></label>
        <p class="bx-mu">Variables : ${Object.entries(M.variables || {}).map(([k, v]) => `<code>${esc(k)}</code> ${esc(v)}`).join(' · ')}</p>
        <div class="bx-actions">${ed ? '<button type="button" class="db-btn" data-bx="modAnnuler">Annuler</button>' : ''}<button type="button" class="db-btn prim" data-bx="modEnregistrer"${st.occupe ? ' disabled' : ''}>Enregistrer le modèle</button></div></div></div></div></div>`;
  }
  function vueAuto() {
    const A = st.auto;
    if (!A) { return '<div class="bx-plein"><div class="db-sk" style="height:300px"></div></div>'; }
    if (A.err) { return `<div class="bx-plein"><div class="db-err">${esc(A.err)}</div></div>`; }
    const regle = r => {
      const ouv = st.autoOuvert === r.regle;
      return `<div class="bx-regle${r.actif ? ' on' : ''}"><div class="bx-regle-h"><label class="bx-bascule"><input type="checkbox" data-bxa="${r.regle}"${r.actif ? ' checked' : ''}><i></i></label>
        <div><b>${esc(r.nom)}</b><p class="bx-mu">${esc(r.texte)}</p></div><button type="button" class="bx-lien" data-bxao="${r.regle}">${ouv ? 'Fermer' : 'Le message'}</button></div>
        ${ouv ? `<div class="bx-form"><label>${r.regle === 'accuse' ? 'Une fois par expéditeur tous les' : r.regle === 'relance' ? 'Relancer après' : 'Écrire'} <input class="bx-in bx-jours" id="bx-a-jours" type="number" min="1" max="30" value="${r.jours}"${r.regle === 'rappel' ? ' disabled' : ''}> ${r.regle === 'rappel' ? 'jour avant' : 'jours'}</label>
          <label>Objet<input class="bx-in" id="bx-a-objet" value="${esc(r.objet)}"></label><label>Texte<textarea class="bx-in" id="bx-a-corps" rows="8">${esc(r.corps)}</textarea></label>
          <div class="bx-actions"><button type="button" class="db-btn prim" data-bxas="${r.regle}"${st.occupe ? ' disabled' : ''}>Enregistrer</button></div></div>` : ''}</div>`;
    };
    const J = A.journal || [];
    return `<div class="bx-plein"><div class="bx-cols"><div><h3 class="bx-t">Les messages automatiques</h3>
      <p class="bx-mu">Ils partent de l’adresse du magasin, par l’horloge du cockpit (toutes les 10 minutes)${A.horloge ? ' — dernier passage : ' + esc(quandLong(A.horloge)) : ' — pas encore de passage'}. Chacun est éteint tant que vous ne l’allumez pas.</p>
      ${A.regles.map(regle).join('')}</div>
      <div><h3 class="bx-t">Ce qui est parti</h3>${J.length ? `<table class="bx-t-j"><tbody>${J.map(x => `<tr><td>${esc(quand(x.le && x.le.replace(' ', 'T')))}</td><td>${esc(x.nom)}</td><td>${esc(x.destinataire)}<small>${esc(x.objet)}</small></td><td>${x.erreur ? `<span class="bx-stop" title="${esc(x.erreur)}">échec</span>` : 'parti'}</td></tr>`).join('')}</tbody></table>` : '<p class="bx-mu">Rien encore.</p>'}</div></div></div>`;
  }
  function html() {
    if (st.err && !st.etat) { return `<div class="db-err">La boîte ne se lit pas : ${esc(st.err)} <button type="button" class="db-btn" data-bx="relireEtat">Réessayer</button></div>`; }
    if (!st.etat) { return '<div class="db-sk" style="height:48px;margin-bottom:12px"></div><div class="db-sk" style="height:420px"></div>'; }
    const f = st.flash ? `<div class="${st.flash.ok ? 'bx-ok' : 'db-err'}">${esc(st.flash.texte)}</div>` : '';
    if (!st.etat.reliee) { return f + vueRelier(); }
    if (!st.etat.ouverte) { return f + vueOuvrir(); }
    const pied = `<p class="bx-bas">${esc(st.etat.adresse)}${st.etat.erreur ? ' · ' + esc(st.etat.erreur) : ''} · <button type="button" class="bx-lien" data-bx="fermer">fermer sur cet appareil</button></p>`;
    if (st.vue === 'modeles') { return barre() + f + vueModeles() + pied; }
    if (st.vue === 'auto') { return barre() + f + vueAuto() + pied; }
    return barre() + f + `<div class="bx">${liste()}${st.vue === 'ecrire' && st.ecr ? vueEcrire() : st.coches.size || st.tout ? vueSelection() : lecture()}</div>` + pied;
  }

  /* --- les gestes ----------------------------------------------------------------------------------------------- */
  function brancher() {
    const el = st.el, on = (s, ev, fn) => el.querySelectorAll(s).forEach(n => n.addEventListener(ev, fn)), val = id => { const n = el.querySelector('#' + id); return n ? n.value : ''; };
    [['bx-r-adr', 'adr'], ['bx-r-mdp', 'mdp'], ['bx-o-mdp', 'mdp']].forEach(([id, k]) => { const n = el.querySelector('#' + id); if (n) { n.addEventListener('input', () => { st.porte[k] = n.value; }); n.addEventListener('keydown', ev => { if (ev.key === 'Enter') { const b = el.querySelector(id === 'bx-o-mdp' ? '[data-bx="ouvrir"]' : '[data-bx="relier"]'); if (b) { b.click(); } } }); } });
    on('[data-bxd]', 'click', e => { st.dossier = e.currentTarget.dataset.bxd; st.page = 1; st.sel = null; st.msg = null; st.liste = null; viderSelection(); if (st.vue !== 'ecrire') { st.vue = 'lire'; } rendre(); chargerListe(); });
    on('[data-bxf]', 'click', e => { st.filtre = e.currentTarget.dataset.bxf; st.page = 1; viderSelection(); chargerListe(); rendre(); });
    on('[data-bxp]', 'click', e => { st.page += +e.currentTarget.dataset.bxp; viderSelection(); chargerListe(); });
    on('[data-bxu]', 'click', e => ouvrirMessage(+e.currentTarget.dataset.bxu));
    on('[data-bxg]', 'click', e => {
      const g = e.currentTarget.dataset.bxg; if (!st.msg) { return; }
      if (g === 'effacer' && !window.confirm('Effacer cet e-mail pour de bon ? Il ne pourra plus être récupéré.')) { return; }
      geste(g, [st.msg.uid]).then(() => { if (st.msg && (g === 'suivi' || g === 'nonsuivi')) { st.msg.suivi = g === 'suivi'; rendre(); } });
    });
    // La sélection : une case par e-mail (Maj+clic : toute la plage depuis la dernière case), « tout sélectionner » la page.
    on('[data-bxc]', 'click', e => {
      const u = +e.currentTarget.dataset.bxc, M = (st.liste && st.liste.messages) || [], coche = e.currentTarget.checked;
      if (st.tout) { st.tout = false; M.forEach(m => st.coches.add(m.uid)); }
      const i = M.findIndex(m => m.uid === u), j = M.findIndex(m => m.uid === st.derniere);
      const plage = e.shiftKey && j >= 0 ? M.slice(Math.min(i, j), Math.max(i, j) + 1).map(m => m.uid) : [u];
      plage.forEach(x => { if (coche) { st.coches.add(x); } else { st.coches.delete(x); } });
      st.derniere = u; st.rang = null; rendre();
    });
    const tt = el.querySelector('#bx-tout');
    if (tt) {
      const M = (st.liste && st.liste.messages) || [], k = M.filter(m => st.coches.has(m.uid)).length;
      tt.indeterminate = !st.tout && k > 0 && k < M.length;
      tt.addEventListener('change', () => { if (tt.checked && !st.tout && k < M.length) { M.forEach(m => st.coches.add(m.uid)); } else { viderSelection(); } st.rang = null; rendre(); });
    }
    on('[data-bxs]', 'click', e => gesteSelection(e.currentTarget.dataset.bxs));
    on('[data-bxv]', 'click', e => gesteSelection('deplacer', e.currentTarget.dataset.bxv));
    const nv = el.querySelector('#bx-nouveau');
    if (nv) { nv.addEventListener('input', () => { st.nouveauNom = nv.value; }); nv.addEventListener('keydown', ev => { if (ev.key === 'Enter') { const b = el.querySelector('[data-bx="creerDeplacer"]'); if (b) { b.click(); } } if (ev.key === 'Escape') { st.nouveauNom = null; rendre(); } }); }
    on('[data-bxrg]', 'change', e => { const u = +e.currentTarget.dataset.bxrg; if (e.currentTarget.checked) { st.rang.garde.add(u); } else { st.rang.garde.delete(u); } rendre(); });
    on('[data-bxrp]', 'click', e => { const u = +e.currentTarget.dataset.bxrp; st.rang.palette = st.rang.palette === u ? null : u; rendre(); });
    on('[data-bxrc]', 'click', e => { const u = +e.currentTarget.dataset.bxrc; st.rang.choix[u] = e.currentTarget.dataset.cible; st.rang.garde.add(u); st.rang.palette = null; rendre(); });
    on('[data-bxoffre]', 'click', e => { if (st.aller) { st.aller('offres', { voir: +e.currentTarget.dataset.bxoffre }); } });
    let tq = null;
    const q = el.querySelector('#bx-q');
    if (q) { q.addEventListener('input', () => { st.q = q.value; clearTimeout(tq); tq = setTimeout(() => { st.page = 1; st.sel = null; viderSelection(); chargerListe(); }, 350); }); }
    on('[data-bx]', 'click', e => {
      const a = e.currentTarget.dataset.bx;
      if (a === 'relier') {
        st.occupe = true; st.flash = null; rendre();
        const corps = { adresse: st.porte.adr, motDePasse: st.porte.mdp }; st.porte.mdp = '';
        return ecrire('/dashboard/boite/relier', corps).then(() => { st.flash = { ok: true, texte: 'Boîte reliée.' }; st.porte.adr = ''; return chargerEtat(); })
          .catch(x => flash(false, x.message)).finally(() => { st.occupe = false; rendre(); });
      }
      if (a === 'ouvrir') {
        st.occupe = true; st.flash = null; rendre();
        const mdp = st.porte.mdp; st.porte.mdp = '';
        return ecrire('/dashboard/boite/ouvrir', { motDePasse: mdp }).then(() => { st.liste = null; st.flash = null; return chargerEtat(); })
          .catch(x => flash(false, x.message)).finally(() => { st.occupe = false; rendre(); });
      }
      if (a === 'fermer') { return ecrire('/dashboard/boite/fermer', {}).then(() => { Object.assign(st, { liste: null, msg: null, sel: null, vue: 'lire', ecr: null }); return chargerEtat(); }); }
      if (a === 'relireEtat') { return chargerEtat(); }
      if (a === 'deselect') { viderSelection(); return rendre(); }
      if (a === 'toutDossier') { st.tout = true; st.rang = null; return rendre(); }
      if (a === 'pageSeule') { st.tout = false; ((st.liste && st.liste.messages) || []).forEach(m => st.coches.add(m.uid)); return rendre(); }
      if (a === 'nouveauDossier') { st.nouveauNom = ''; rendre(); const n = el.querySelector('#bx-nouveau'); if (n) { n.focus(); } return; }
      if (a === 'creerDeplacer') { const nom = String(st.nouveauNom || '').trim(); if (!nom) { return flash(false, 'Le nom du nouveau dossier, d’abord.'); } return gesteSelection('deplacer', null, { nouveau: nom }); }
      if (a === 'ranger') { return proposerRangement(); }
      if (a === 'rangAnnuler') { st.rang = null; return rendre(); }
      if (a === 'rangAppliquer') { return appliquerRangement(); }
      if (a === 'relire') { chargerListe(); return chargerEtat(); }
      if (a === 'ecrire') { chargerModeles(); chargerOffres(); return nouvelEcrit({}); }
      if (a === 'repondre' || a === 'tous') { chargerModeles(); chargerOffres(); return ecrireReponse(a === 'tous'); }
      if (a === 'transferer') { chargerModeles(); return transferer(); }
      if (a === 'annuler') { st.ecr = null; st.vue = 'lire'; return rendre(); }
      if (a === 'envoyer') { return envoyer(); }
      if (a === 'cc') { st.ecr.ccOuvert = true; return rendre(); }
      if (a === 'images') { st.images = true; return rendre(); }
      if (a === 'offre') { return creerOffre(); }
      if (a === 'modeles') { st.vue = st.vue === 'modeles' ? 'lire' : 'modeles'; st.modEd = null; st.flash = null; rendre(); return chargerModeles(); }
      if (a === 'auto') { st.vue = st.vue === 'auto' ? 'lire' : 'auto'; st.flash = null; rendre(); return chargerAuto(); }
      if (a === 'modAnnuler') { st.modEd = null; return rendre(); }
      if (a === 'modEnregistrer') {
        const ed = st.modEd;
        st.occupe = true; rendre();
        return ecrire('/dashboard/boite/modeles', { action: ed && ed.id ? 'modifier' : 'creer', id: ed && ed.id, nom: val('bx-m-nom'), objet: val('bx-m-objet'), corps: val('bx-m-corps') })
          .then(d => { st.modeles = d; st.modEd = null; flash(true, 'Modèle enregistré.'); }).catch(x => flash(false, x.message)).finally(() => { st.occupe = false; rendre(); });
      }
    });
    on('[data-bxmd]', 'click', e => { const x = (st.modeles.reseau || []).find(m => m.id === e.currentTarget.dataset.bxmd); if (x) { st.modEd = { id: null, nom: x.nom, objet: x.objet, corps: x.corps }; rendre(); } });
    on('[data-bxme]', 'click', e => { const x = (st.modeles.magasin || []).find(m => String(m.id) === e.currentTarget.dataset.bxme); if (x) { st.modEd = Object.assign({}, x); rendre(); } });
    on('[data-bxms]', 'click', e => { ecrire('/dashboard/boite/modeles', { action: 'supprimer', id: +e.currentTarget.dataset.bxms }).then(d => { st.modeles = d; rendre(); }).catch(x => flash(false, x.message)); });
    on('[data-bxa]', 'change', e => { const k = e.currentTarget.dataset.bxa, actif = e.currentTarget.checked; ecrire('/dashboard/boite/automatiques', { regle: k, actif }).then(d => { st.auto = d; flash(true, (actif ? 'Allumé' : 'Éteint') + ' : ' + (d.regles.find(r => r.regle === k) || {}).nom + '.'); }).catch(x => { flash(false, x.message); chargerAuto(); }); });
    on('[data-bxao]', 'click', e => { st.autoOuvert = st.autoOuvert === e.currentTarget.dataset.bxao ? null : e.currentTarget.dataset.bxao; rendre(); });
    on('[data-bxas]', 'click', e => { const k = e.currentTarget.dataset.bxas; st.occupe = true; rendre(); ecrire('/dashboard/boite/automatiques', { regle: k, jours: +val('bx-a-jours') || 1, objet: val('bx-a-objet'), corps: val('bx-a-corps') }).then(d => { st.auto = d; st.autoOuvert = null; flash(true, 'Message enregistré.'); }).catch(x => flash(false, x.message)).finally(() => { st.occupe = false; rendre(); }); });
    if (st.vue === 'ecrire' && st.ecr) {
      const e = st.ecr;
      [['bx-a', 'a'], ['bx-cc', 'cc'], ['bx-objet', 'objet'], ['bx-texte', 'texte']].forEach(([id, k]) => { const n = el.querySelector('#' + id); if (n) { n.addEventListener('input', () => { e[k] = n.value; }); } });
      const mo = el.querySelector('#bx-modele'); if (mo) { mo.addEventListener('change', () => { if (mo.value) { appliquerModele(mo.value); } }); }
      const of = el.querySelector('#bx-offre'); if (of) { of.addEventListener('change', () => { e.offre = of.value || null; rendre(); }); }
      const fi = el.querySelector('#bx-fichiers'); if (fi) { fi.addEventListener('change', () => { lireFichiers(fi.files).then(L => { e.pieces.push(...L); rendre(); }).catch(() => flash(false, 'Le fichier ne se lit pas.')); }); }
      on('[data-bxx]', 'click', x => { const k = x.currentTarget.dataset.bxx; if (k === 'offre') { e.offre = null; } else { e.pieces.splice(+k, 1); } rendre(); });
    }
    // Le corps HTML de l'e-mail prend sa hauteur (même origine, sans script : on le mesure d'ici).
    const f = el.querySelector('.bx-cadre');
    if (f) { const h = () => { try { f.style.height = Math.max(160, f.contentDocument.documentElement.scrollHeight + 8) + 'px'; } catch (x) { /* rien */ } }; f.addEventListener('load', () => { h(); setTimeout(h, 400); }); }
  }

  function rendre() {
    if (!st.el) { return; }
    const a = document.activeElement, dans = a && st.el.contains(a) && a.id ? a.id : null, pos = dans && a.selectionStart != null ? a.selectionStart : null;
    st.el.innerHTML = html();
    brancher();
    if (dans) { const b = st.el.querySelector('#' + dans); if (b) { b.focus(); if (pos != null && b.setSelectionRange) { try { b.setSelectionRange(pos, pos); } catch (e) { /* sans curseur */ } } } }
  }

  window.DashBoite = {
    racine(shop, o) {
      o = o || {};
      if (o.apiBase) { st.api = o.apiBase; }
      if (o.nomShop) { st.nomShop = o.nomShop; }
      if (o.onNonLus) { st.onNonLus = o.onNonLus; }
      if (o.aller) { st.aller = o.aller; }
      if (!st.el || String(st.shop) !== String(shop)) {
        st.shop = String(shop); st.el = document.createElement('div'); st.el.className = 'bx-racine';
        Object.assign(st, { etat: null, err: null, liste: null, sel: null, msg: null, vue: 'lire', ecr: null, modeles: null, auto: null, produits: null, offres: null, flash: null });
        st.intention = o.intention || null;
        chargerEtat();
      } else if (o.intention) {
        if (st.etat && st.etat.ouverte) { suivreIntention(o.intention); } else { st.intention = o.intention; }
      }
      return st.el;
    },
    relire() { if (st.el) { chargerEtat(); if (st.etat && st.etat.ouverte) { chargerListe(); } } },
    /** Les non-lus de la réception (null : boîte non reliée ou fermée sur cet appareil). */
    compter(shop, apiBase, cb) {
      fetch((apiBase || st.api) + '/dashboard/boite?shop=' + encodeURIComponent(shop), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(r => r.ok ? r.json() : null).then(d => cb(d && d.ouverte ? d.nonLus : null)).catch(() => cb(null));
    },
  };
})();
