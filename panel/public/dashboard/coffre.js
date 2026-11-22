/* Dashboard magasin › Mon coffre-fort (22/11/2026, maquettes consultant/docs/maquettes/coffre-fort/).
 *
 * Sam : « chaque franchisé a un coffre-fort avec ses documents : DIP, contrat, légal, formation initiale et continue,
 * rapports de visite ; un tableau à onglets pour n'avoir qu'une page ; dans son dashboard, un lien direct, tout en
 * dessous ». Une page : en tête les repères (documents, nouveaux, à renouveler, échéance du contrat) ; un tableau à
 * onglets (Tout, Contrat & DIP, Légal, Formation, Rapports de visite, Finances, Courriers & réunions) ; à droite,
 * l'aperçu du document choisi et qui l'a ouvert. Dans l'onglet Légal, le magasin dépose ses pièces (décision de Sam).
 *   Routes (consultant/src/coffre.php) : GET …/coffre/magasin, GET …/coffre/magasin/fichier, POST …/coffre/magasin/document.
 * Les rapports de visite s'ouvrent dans « Mon suivi » (leur source). Un document du coffre se joint à un mail de la
 * Boîte mail (boite.js, « Joindre du coffre-fort »), par `window.DashCoffre.liste(shop)`.
 *
 * Les GRANDES DATES (22/11/2026, Sam : « un bouton avec les grandes dates — triennats des contrats de bail et autres ;
 * un assistant lorsque j'importe un document ») : le bouton « Grandes dates » de la barre ouvre leur frise (par année,
 * la prochaine en tête, les passées repliées) ; on en ajoute à la main. Après un dépôt (ou par « Relever les grandes
 * dates » dans l'aperçu d'un document), l'assistant lit le document et PROPOSE ses dates, cochées si elles sont à venir,
 * avec l'extrait qui les justifie ; rien ne se range sans « Ranger ». Les dates d'un bail sont calculées par le serveur.
 *   Routes (consultant/src/coffre_dates.php) : GET|POST|DELETE …/coffre/magasin/dates, POST …/coffre/magasin/assistant.
 *
 * `window.DashCoffre.racine(shop, {apiBase})` rend l'élément du module, gardé d'un rendu à l'autre.
 */
(function () {
  'use strict';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const dfr = s => s ? String(s).slice(8, 10) + '/' + String(s).slice(5, 7) + '/' + String(s).slice(0, 4) : '—';
  const dh = s => s ? String(s).slice(8, 10) + '/' + String(s).slice(5, 7) + ' ' + String(s).slice(11, 16) : '';
  const taille = o => o >= 1048576 ? (o / 1048576).toFixed(1).replace('.', ',') + ' Mo' : Math.max(1, Math.round((o || 0) / 1024)) + ' Ko';
  const ext = n => (String(n || '').split('.').pop() || '').slice(0, 4).toUpperCase();
  const ETAT = { depose: 'brou', valide: 'val', renouveler: 'aval', expiree: 'ret' };
  const COMMENT = { ouvert: 'ouvert', telecharge: 'téléchargé', mail: 'joint à un mail' };

  const st = { shop: null, api: '../api/cockpit', el: null, d: null, err: null, onglet: 'tout', sel: null, q: '', depot: null, occupe: false, flash: null,
    vue: 'docs', dates: null, datesErr: null, ajout: null, passees: false, assist: null };
  const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  /** L'assistant lit les PDF, les photos et les fichiers Word (le serveur dit non aux autres). */
  const LISIBLE = /^(application\/pdf|image\/(jpeg|png|webp)|application\/vnd\.openxmlformats-officedocument\.wordprocessingml\.document|application\/vnd\.oasis\.opendocument\.text|text\/plain)$/;

  /* --- lectures et écritures ------------------------------------------------------------------------------------ */
  const urlFichier = (id, tel, ap) => st.api + '/coffre/magasin/fichier?shop=' + encodeURIComponent(st.shop) + '&id=' + id + (tel ? '&telecharger=1' : '') + (ap ? '&apercu=1' : '');
  function lireCoffre(shop) {
    return fetch(st.api + '/coffre/magasin?shop=' + encodeURIComponent(shop), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status))))));
  }
  function charger() {
    return lireCoffre(st.shop).then(d => { st.d = d; st.err = null; }).catch(e => { st.err = e.message; }).finally(rendre);
  }
  function flash(ok, texte) { st.flash = { ok, texte }; rendre(); }
  function appel(methode, chemin, corps) {
    const o = { method: methode, headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (corps) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(Object.assign({ shop: st.shop }, corps)); }
    return fetch(st.api + chemin, o).then(r => r.json().catch(() => ({})).then(j => (r.ok ? j : Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status))))));
  }
  /* --- les grandes dates ------------------------------------------------------------------------------------------ */
  function lireDates() {
    return appel('GET', '/coffre/magasin/dates?shop=' + encodeURIComponent(st.shop)).then(d => { st.dates = d; st.datesErr = null; })
      .catch(e => { st.datesErr = e.message; }).finally(rendre);
  }
  function vueDates() { st.vue = 'dates'; st.flash = null; if (!st.dates) { lireDates(); } rendre(); }
  /** L'assistant lit un document du coffre et propose ses grandes dates (cochées : celles à venir et pas encore rangées). */
  function lancerAssistant(id, nom) {
    st.assist = { docId: id, nom: nom || '', etat: 'lecture', r: null, err: null };
    vueDates();
    appel('POST', '/coffre/magasin/assistant', { id }).then(r => {
      if (!st.assist || st.assist.docId !== id) { return; }
      Object.assign(st.assist, { etat: 'pret', r, coches: new Set(r.propositions.filter(p => p.coche).map(p => p.cle)), le: {}, echOk: !!r.echeanceDoc, titreOk: !!r.titreDoc });
    }).catch(e => { if (st.assist && st.assist.docId === id) { Object.assign(st.assist, { etat: 'erreur', err: e.message }); } }).finally(rendre);
  }
  function rangerAssistant() {
    const A = st.assist; if (!A || !A.r || st.occupe) { return; }
    const dates = A.r.propositions.filter(p => A.coches.has(p.cle)).map(p => ({ sorte: p.sorte, titre: p.titre, le: A.le[p.cle] || p.le, fin: p.fin, note: p.note, docId: A.docId, source: p.source }));
    const corps = { dates };
    if (A.echOk && A.r.echeanceDoc) { corps.echeanceDoc = { docId: A.docId, le: A.r.echeanceDoc.proposee }; }
    if (A.titreOk && A.r.titreDoc) { corps.titreDoc = { docId: A.docId, titre: A.r.titreDoc.propose }; }
    if (!dates.length && !corps.echeanceDoc && !corps.titreDoc) { st.assist = null; rendre(); return; }
    st.occupe = true; rendre();
    appel('POST', '/coffre/magasin/dates', corps).then(r => {
      st.dates = Object.assign({}, st.dates || {}, { dates: r.dates }); st.assist = null;
      flash(true, (r.ajoutees ? (r.ajoutees > 1 ? r.ajoutees + ' grandes dates rangées' : 'Une grande date rangée') : 'Aucune nouvelle date') + (r.document && r.document.length ? ' ; document mis à jour (' + r.document.join(', ') + ')' : '') + '.');
      charger();
    }).catch(e => flash(false, 'Rangement impossible : ' + e.message)).finally(() => { st.occupe = false; rendre(); });
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
  /** Une ouverture se note au serveur : on relit pour « nouveau » et « qui l'a ouvert », sans perdre la sélection. */
  function ouvert(id) { const doc = docs().find(x => x.id === id); if (doc) { doc.nouveau = false; } setTimeout(charger, 1200); }
  function deposer() {
    const D = st.depot; if (!D || !D.fichiers.length || st.occupe) { return; }
    const max = (st.d && st.d.depot && st.d.depot.maxOctets) || 25e6;
    const lourds = D.fichiers.filter(f => f.size > max);
    if (lourds.length) { flash(false, 'Trop lourd, ' + taille(max) + ' au plus par fichier : ' + lourds.map(f => f.name).join(', ')); return; }
    const fd = new FormData();
    [['shop', st.shop], ['titre', D.titre], ['date', D.date], ['echeance', D.echeance], ['note', D.note]].forEach(([k, v]) => fd.append(k, v || ''));
    D.fichiers.forEach(f => fd.append('fichiers[]', f, f.name));
    st.occupe = true; rendre();
    fetch(st.api + '/coffre/magasin/document', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(r => r.json().catch(() => ({ error: 'HTTP ' + r.status })))
      .then(j => {
        const n = (j.ajoutes || []).length, refus = j.refuses || [];
        if (n) { st.depot = null; st.onglet = 'legal'; st.sel = 'd' + j.ajoutes[0].id; }
        flash(!!n, (n ? (n > 1 ? n + ' pièces rangées' : 'Pièce rangée') + ' dans votre coffre.' : (j.error || 'Rien n’a été rangé.'))
          + (refus.length ? ' Refusé : ' + refus.map(x => x.nom + ' (' + x.motif + ')').join(', ') + '.' : ''));
        if (n) {
          charger();
          // L'assistant (Sam : « un assistant lorsque j'importe un document ») lit le premier document lisible déposé.
          const lu = j.ajoutes.find(x => LISIBLE.test(x.mime || ''));
          if (lu) { lancerAssistant(lu.id, lu.nom); }
        }
      })
      .catch(e => flash(false, 'Envoi impossible : ' + e.message))
      .finally(() => { st.occupe = false; rendre(); });
  }

  /* --- les lignes : documents et rapports, une même forme -------------------------------------------------------- */
  const docs = () => (st.d && st.d.documents) || [];
  function lignes() {
    const D = st.d || {};
    const L = docs().map(x => ({ cle: 'd' + x.id, onglet: x.onglet, ongletNom: nomOnglet(x.onglet), titre: x.titre, sous: x.note || x.sorteLib, date: x.date,
      etat: { c: ETAT[x.etat.cle] || 'brou', t: x.etat.texte }, par: x.deposePar, taille: taille(x.octets), ext: ext(x.nom), nouveau: x.nouveau, doc: x }));
    (D.visites || []).forEach(v => L.push({ cle: 'v' + v.id, onglet: 'visites', ongletNom: nomOnglet('visites'), titre: v.type + (v.consultant ? ' — ' + v.consultant : ''),
      sous: 'rapport envoyé le ' + dh(v.envoyeLe) + (v.luLe ? ' · lu le ' + dh(v.luLe) : ' · pas encore lu'), date: v.le,
      etat: v.luLe ? { c: 'val', t: 'lu' } : { c: 'aval', t: 'à lire' }, par: v.envoyePar || v.consultant, taille: '', ext: 'WEB', nouveau: !v.luLe, visite: v }));
    const q = st.q.trim().toLowerCase();
    return L.filter(l => (st.onglet === 'tout' || l.onglet === st.onglet) && (!q || (l.titre + ' ' + l.sous).toLowerCase().includes(q)))
      .sort((a, b) => st.onglet === 'tout' ? (b.nouveau - a.nouveau) || String(b.date || '').localeCompare(String(a.date || '')) : String(b.date || '').localeCompare(String(a.date || '')));
  }
  const nomOnglet = k => { const o = ((st.d && st.d.onglets) || []).find(x => x.cle === k); return o ? o.nom : k; };

  /* --- le rendu --------------------------------------------------------------------------------------------------- */
  function reperes() {
    const R = st.d.reperes || {}, ren = R.aRenouveler || [], c = R.contrat;
    const tot = (st.d.onglets || []).find(o => o.cle === 'tout');
    const tuile = (cls, k, v, s, att) => `<div class="cf-tui ${cls}"${att || ''}><span class="k">${esc(k)}</span><b>${v}</b><span class="s">${esc(s)}</span></div>`;
    return `<div class="cf-tuiles">
      ${tuile('', 'Documents', tot ? tot.n : 0, 'boutique de ' + String(st.d.nom || '').replace(/^.*-\s*/, ''))}
      ${tuile(R.nouveaux ? 'ac' : '', 'Nouveaux', R.nouveaux || 0, R.nouveaux ? 'pas encore ouverts' : 'tout est lu')}
      ${tuile(ren.length ? 'or' : 'ok', 'À renouveler', ren.length, ren.length ? ren.map(x => x.titre + ' · ' + dfr(x.echeance)).join(' · ') : 'aucune échéance proche')}
      ${tuile('', 'Contrat de franchise', c && c.echeance ? 'jusqu’au ' + dfr(c.echeance) : (c ? 'déposé' : '—'), c ? 'signé le ' + dfr(c.date) : 'pas encore dans le coffre')}
    </div>`;
  }
  function apercu(l) {
    if (!l) { return `<aside class="cf-ap"><div class="cf-vide">Choisissez un document pour le voir ici.</div></aside>`; }
    if (l.visite) {
      const v = l.visite, lien = st.d.suivi;
      return `<aside class="cf-ap"><div class="cf-ap-c"><div class="cf-k">Rapport de visite</div><div class="cf-ap-t">${esc(l.titre)}</div>
        <dl class="cf-kv"><dt>Visite</dt><dd>${esc(dfr(v.le))}</dd><dt>Envoyé</dt><dd>${esc(dh(v.envoyeLe))}${v.envoyePar ? ' · ' + esc(v.envoyePar) : ''}</dd><dt>Lu</dt><dd>${v.luLe ? esc(dh(v.luLe)) : 'pas encore'}</dd></dl>
        ${lien ? `<div class="cf-bts"><a class="db-btn cf-p" href="${esc(lien)}" target="_blank" rel="noopener">Lire le rapport ↗</a></div><p class="cf-n">Le rapport, ses photos et ses points à corriger sont dans « Mon suivi ».</p>` : '<p class="cf-n">« Mon suivi » n’est pas joignable d’ici.</p>'}
      </div></aside>`;
    }
    const x = l.doc, im = /^image\//.test(x.mime), pdf = x.mime === 'application/pdf';
    return `<aside class="cf-ap">
      ${pdf ? `<iframe class="cf-ap-f" src="${esc(urlFichier(x.id, false, true))}#toolbar=0&view=FitH" title="Aperçu"></iframe>` : im ? `<img class="cf-ap-i" src="${esc(urlFichier(x.id, false, true))}" alt="">` : `<div class="cf-ap-x">${esc(ext(x.nom))}</div>`}
      <div class="cf-ap-c"><div class="cf-k">${esc(l.ongletNom)} · ${esc(x.sorteLib)}</div><div class="cf-ap-t">${esc(x.titre)}</div>${x.note ? `<p class="cf-n">${esc(x.note)}</p>` : ''}
        <dl class="cf-kv"><dt>Date</dt><dd>${esc(dfr(x.date))}</dd>${x.echeance ? `<dt>Échéance</dt><dd>${esc(dfr(x.echeance))}</dd>` : ''}<dt>État</dt><dd><span class="cf-st ${l.etat.c}">${esc(l.etat.t)}</span></dd>
          <dt>Déposé par</dt><dd>${esc(x.deposePar)}</dd><dt>Fichier</dt><dd>${esc(x.nom)} · ${esc(taille(x.octets))}</dd></dl>
        <div class="cf-bts"><a class="db-btn cf-p" href="${esc(urlFichier(x.id))}" target="_blank" rel="noopener" data-cf-ouvre="${x.id}">Ouvrir</a><a class="db-btn" href="${esc(urlFichier(x.id, true))}" data-cf-ouvre="${x.id}">Télécharger</a></div>
        ${LISIBLE.test(x.mime || '') ? `<button type="button" class="db-btn cf-as-lance" data-cf-assist="${x.id}">✦ Relever les grandes dates</button>` : ''}
        <div class="cf-k" style="margin-top:14px">Qui l’a ouvert <i>noté à chaque ouverture</i></div>
        ${(x.traces || []).map(t => `<div class="cf-tr"><span>${esc(dh(t.le))}</span><span>${esc(t.qui)}<small>${esc(COMMENT[t.comment] || t.comment)}${t.role === 'siege' ? ' · siège' : ''}</small></span></div>`).join('')}
        <div class="cf-tr"><span>${esc(dh(x.deposeLe))}</span><span>Déposé au coffre<small>par ${esc(x.deposePar)}</small></span></div>
      </div></aside>`;
  }
  function depot() {
    const D = st.depot;
    if (!D) { return `<div class="cf-sous"><span>Vos pièces légales — statuts, BCE, assurance, AFSCA, bail… : vous et votre consultant les déposez ici. Une pièce avec une échéance passe « à renouveler » 60 jours avant.</span><button type="button" class="db-btn cf-p" data-cf-depot="1">＋ Déposer un document</button></div>`; }
    const ext = ((st.d.depot || {}).extensions || []).map(e => '.' + e).join(',');
    return `<div class="cf-depot"><div class="cf-dg">
        <label><span>Titre</span><input class="db-sel" data-cf-d="titre" value="${esc(D.titre)}" placeholder="ex. Attestation d’assurance RC 2027"></label>
        <label><span>Date du document</span><input class="db-sel" type="date" data-cf-d="date" value="${esc(D.date)}"></label>
        <label><span>Échéance (facultatif)</span><input class="db-sel" type="date" data-cf-d="echeance" value="${esc(D.echeance)}"></label>
        <label><span>Note</span><input class="db-sel" data-cf-d="note" value="${esc(D.note)}" placeholder="facultatif"></label></div>
      <div class="cf-db"><label class="db-btn">${D.fichiers.length ? esc(D.fichiers.map(f => f.name).join(' · ')) : 'Choisir le ou les fichiers'}<input type="file" data-cf-fichiers="1" multiple hidden accept="${esc(ext)}"></label>
        <span style="flex:1"></span><button type="button" class="db-btn" data-cf-annule="1">Annuler</button><button type="button" class="db-btn cf-p" data-cf-envoi="1"${D.fichiers.length && !st.occupe ? '' : ' disabled'}>${st.occupe ? 'Envoi…' : 'Déposer'}</button></div></div>`;
  }
  /* --- les grandes dates : la frise, l'ajout, l'assistant ----------------------------------------------------------- */
  const jours = e => e.cle === 'passee' ? 'passée' : e.cle === 'encours' ? (e.jours === 0 ? 'dernier jour' : 'en cours · encore ' + e.jours + ' j')
    : e.jours === 0 ? 'aujourd’hui' : e.jours <= 90 ? 'dans ' + e.jours + ' j' : 'dans ' + Math.round(e.jours / 30.44) + ' mois';
  const ETAT_D = { passee: 'pas', encours: 'cours', bientot: 'bien', avenir: 'av' };
  function ligneDate(d) {
    const j = +d.le.slice(8, 10), m = MOIS[+d.le.slice(5, 7) - 1];
    const src = d.source === 'calcul' ? 'calculée' : d.source === 'assistant' ? 'relevée par l’assistant' : d.source === 'document' ? 'échéance du document' : 'ajoutée par ' + (d.par || 'vous');
    return `<div class="cf-gd-l ${ETAT_D[d.etat.cle] || 'av'}"><span class="cf-gd-d"><b>${j}</b><small>${esc(m)}</small></span>
      <span class="cf-gd-c"><b>${esc(d.titre)}</b><small>${d.sorteLib !== d.titre ? esc(d.sorteLib) + ' · ' : ''}${d.fin ? 'du ' + esc(dfr(d.le)) + ' au ' + esc(dfr(d.fin)) + ' · ' : ''}${esc(src)}${d.docTitre ? ` · <a href="#" data-cf-voir="${d.docId}">${esc(d.docTitre)}</a>` : ''}</small>${d.note ? `<i>${esc(d.note)}</i>` : ''}</span>
      <span class="cf-gd-j">${esc(jours(d.etat))}</span>${d.modifiable && d.id ? `<button type="button" class="cf-gd-x" data-cf-retire="${d.id}" aria-label="Retirer" title="Retirer">×</button>` : '<span class="cf-gd-x"></span>'}</div>`;
  }
  function frise() {
    if (st.datesErr && !st.dates) { return `<div class="cf-vide">Les grandes dates ne se lisent pas : ${esc(st.datesErr)}</div>`; }
    if (!st.dates) { return '<div class="cf-vide">Lecture des grandes dates…</div>'; }
    const L = st.dates.dates || [], venir = L.filter(d => d.etat.cle !== 'passee'), passees = L.filter(d => d.etat.cle === 'passee');
    if (!L.length) { return '<div class="cf-vide">Pas encore de grande date. Déposez votre bail ou une police d’assurance : l’assistant relève ses dates. Ou ajoutez-en une à la main.</div>'; }
    let h = '', an = '';
    const p = venir[0];
    if (p) { h += `<div class="cf-gd-p ${ETAT_D[p.etat.cle]}"><span>Prochaine</span><b>${esc(p.titre)}</b><em>${esc(dfr(p.le))}${p.fin ? ' → ' + esc(dfr(p.fin)) : ''} · ${esc(jours(p.etat))}</em></div>`; }
    venir.forEach(d => { const a = d.le.slice(0, 4); if (a !== an) { an = a; h += `<div class="cf-gd-an">${a}</div>`; } h += ligneDate(d); });
    if (!venir.length) { h += '<div class="cf-vide">Aucune grande date à venir.</div>'; }
    if (passees.length) {
      h += `<button type="button" class="cf-gd-pas" data-cf-passees="1">${st.passees ? '▾' : '▸'} ${passees.length} date${passees.length > 1 ? 's' : ''} passée${passees.length > 1 ? 's' : ''}</button>`;
      if (st.passees) { h += passees.slice().reverse().map(ligneDate).join(''); }
    }
    return h;
  }
  function formAjout() {
    const F = st.ajout; if (!F) { return ''; }
    const sortes = ((st.dates || {}).sortes || []).filter(o => o.v !== 'echeance');
    return `<div class="cf-depot"><div class="cf-dg cf-dg5">
        <label><span>Sorte</span><select class="db-sel" data-cf-a="sorte">${sortes.map(o => `<option value="${o.v}"${o.v === F.sorte ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select></label>
        <label><span>Titre</span><input class="db-sel" data-cf-a="titre" value="${esc(F.titre)}" placeholder="ex. Contrôle AFSCA annuel"></label>
        <label><span>Date</span><input class="db-sel" type="date" data-cf-a="le" value="${esc(F.le)}"></label>
        <label><span>Jusqu’au (facultatif)</span><input class="db-sel" type="date" data-cf-a="fin" value="${esc(F.fin)}"></label>
        <label><span>Document (facultatif)</span><select class="db-sel" data-cf-a="docId"><option value="">—</option>${docs().map(x => `<option value="${x.id}"${String(x.id) === String(F.docId) ? ' selected' : ''}>${esc(x.titre)}</option>`).join('')}</select></label></div>
      <div class="cf-db"><input class="db-sel" style="flex:1" data-cf-a="note" value="${esc(F.note)}" placeholder="Note (facultatif)"><button type="button" class="db-btn" data-cf-a-annule="1">Annuler</button><button type="button" class="db-btn cf-p" data-cf-a-ok="1"${st.occupe ? ' disabled' : ''}>Ajouter</button></div></div>`;
  }
  function assistant() {
    const A = st.assist;
    const tete = '<div class="cf-as-h"><span class="cf-as-ic">✦</span><b>Assistant des grandes dates</b></div>';
    if (!A) {
      const lisibles = docs().filter(x => LISIBLE.test(x.mime || ''));
      return `<aside class="cf-ap cf-as">${tete}<div class="cf-ap-c"><p class="cf-n">Quand vous déposez un document — bail, police d’assurance, autorisation, contrat — l’assistant le lit et vous propose ses grandes dates : fins de triennat, dernier jour pour donner congé, renouvellement, échéances. Rien n’est rangé sans votre accord.</p>
        ${lisibles.length ? `<label class="cf-as-choix"><span>Lire un document du coffre</span><select class="db-sel" data-cf-as-doc="1"><option value="">Choisir…</option>${lisibles.map(x => `<option value="${x.id}">${esc(x.titre)}</option>`).join('')}</select></label>` : ''}</div></aside>`;
    }
    if (A.etat === 'lecture') {
      return `<aside class="cf-ap cf-as">${tete}<div class="cf-ap-c"><div class="cf-as-lit"><span class="cf-as-pt"><i></i><i></i><i></i></span>Je lis « ${esc(A.nom)} »…</div><p class="cf-n">Quelques secondes, parfois une minute pour un long bail.</p></div></aside>`;
    }
    if (A.etat === 'erreur') {
      return `<aside class="cf-ap cf-as">${tete}<div class="cf-ap-c"><div class="cf-flash ko">${esc(A.err)}</div><div class="cf-bts"><button type="button" class="db-btn" data-cf-as-relance="1">Réessayer</button><button type="button" class="db-btn" data-cf-as-ferme="1">Fermer</button></div></div></aside>`;
    }
    const R = A.r, P = R.propositions || [], n = P.filter(p => A.coches.has(p.cle)).length;
    const src = p => p.source === 'calcul' ? 'calculée depuis le bail' : 'lue dans le document' + (p.page ? ', p. ' + p.page : '');
    // Les dates à venir d'abord (l'urgence dite : une fenêtre en cours, une date dans les 4 mois) ; les passées repliées.
    const venir = P.filter(p => p.etat.cle !== 'passee'), passees = P.filter(p => p.etat.cle === 'passee');
    const calc = P.find(p => p.source === 'calcul' && p.extrait);
    const ligne = p => `<label class="cf-as-p${p.deja ? ' deja' : ''}"><input type="checkbox" data-cf-as-c="${p.cle}"${A.coches.has(p.cle) ? ' checked' : ''}>
        <span class="cf-as-t"><b>${esc(p.titre)}</b>${p.etat.cle === 'encours' || p.etat.cle === 'bientot' ? `<em class="cf-as-u">${esc(jours(p.etat))}</em>` : ''}<small>${esc(src(p))}${p.fin ? ' · jusqu’au ' + esc(dfr(p.fin)) : ''}${p.deja ? ' · déjà rangée' : ''}</small>${p.extrait && p.source !== 'calcul' ? `<i>« ${esc(p.extrait)} »</i>` : ''}</span>
        <input class="db-sel cf-as-le" type="date" data-cf-as-le="${p.cle}" value="${esc(A.le[p.cle] || p.le)}"></label>`;
    return `<aside class="cf-ap cf-as">${tete}<div class="cf-ap-c">
      <div class="cf-k">${esc(R.genreLib)} · ${esc(R.nom)}</div>${R.resume ? `<p class="cf-as-r">${esc(R.resume)}</p>` : ''}
      ${R.titreDoc ? `<label class="cf-as-o"><input type="checkbox" data-cf-as-titre="1"${A.titreOk ? ' checked' : ''}><span>Renommer le document<small>« ${esc(R.titreDoc.actuel)} » → « ${esc(R.titreDoc.propose)} »</small></span></label>` : ''}
      ${R.echeanceDoc ? `<label class="cf-as-o"><input type="checkbox" data-cf-as-ech="1"${A.echOk ? ' checked' : ''}><span>Échéance du document : ${esc(dfr(R.echeanceDoc.proposee))}<small>${R.echeanceDoc.actuelle ? 'au lieu du ' + esc(dfr(R.echeanceDoc.actuelle)) : 'il passera « à renouveler » 60 jours avant'}</small></span></label>` : ''}
      <div class="cf-k" style="margin-top:10px">Dates proposées <i>${P.length ? n + ' / ' + P.length + ' cochées' : ''}</i></div>
      ${calc ? `<div class="cf-as-ex"><span>Lu dans le bail</span>« ${esc(calc.extrait)} »</div>` : ''}
      ${P.length ? venir.map(ligne).join('') + (passees.length ? `<button type="button" class="cf-gd-pas cf-as-pas" data-cf-as-passees="1">${A.voirPassees ? '▾' : '▸'} ${passees.length} date${passees.length > 1 ? 's' : ''} déjà passée${passees.length > 1 ? 's' : ''}, non cochée${passees.length > 1 ? 's' : ''}</button>` + (A.voirPassees ? passees.map(ligne).join('') : '') : '')
        : '<p class="cf-n">Aucune date à relever dans ce document.</p>'}
      ${R.remarques ? `<div class="cf-as-rq">${esc(R.remarques)}</div>` : ''}
      <div class="cf-bts"><button type="button" class="db-btn cf-p" data-cf-as-range="1"${st.occupe ? ' disabled' : ''}>${st.occupe ? 'Rangement…' : n ? 'Ranger ' + n + ' date' + (n > 1 ? 's' : '') : 'Valider'}</button><button type="button" class="db-btn" data-cf-as-ferme="1">Ignorer</button></div>
      <p class="cf-n" style="margin-top:10px">Les dates du bail sont calculées selon la règle générale des baux commerciaux (triennats, préavis de 6 mois, renouvellement du 18e au 15e mois avant la fin) : vérifiez-les dans votre bail.</p>
    </div></aside>`;
  }
  function vueDatesHtml() {
    return `<div class="cf-lay"><section class="cf-carte cf-gdv"><div class="cf-gdh"><button type="button" class="db-btn" data-cf-vue="docs">‹ Documents</button><b>Grandes dates</b><span>triennats du bail, préavis, renouvellements, échéances</span>
        ${st.ajout ? '' : '<button type="button" class="db-btn cf-p" data-cf-ajout="1">＋ Ajouter une date</button>'}</div>${formAjout()}<div class="cf-gd-liste">${frise()}</div></section>${assistant()}</div>`;
  }
  function rendre() {
    if (!st.el) { return; }
    const garde = document.activeElement && st.el.contains(document.activeElement) && document.activeElement.dataset.cfQ ? document.activeElement.selectionStart : null;
    if (st.err && !st.d) { st.el.innerHTML = `<div class="db-err">Le coffre-fort ne se lit pas : ${esc(st.err)}</div>`; return; }
    if (!st.d) { st.el.innerHTML = '<div class="db-attente">Ouverture du coffre-fort…</div>'; return; }
    const L = lignes();
    if (!st.sel || !L.some(l => l.cle === st.sel)) { st.sel = L[0] ? L[0].cle : null; }
    const sel = L.find(l => l.cle === st.sel);
    const onglets = (st.d.onglets || []).map(o => `<button type="button" class="${o.cle === st.onglet ? 'on' : ''}" data-cf-onglet="${o.cle}">${esc(o.nom)}<i>${o.n}</i></button>`).join('');
    const tete = `<div class="cf-tete"><span class="cf-ic"></span><span class="cf-c">Document</span>${st.onglet === 'tout' ? '<span class="cf-o">Onglet</span>' : ''}<span class="cf-d">Date</span><span class="cf-e">État</span><span class="cf-p2">Déposé par</span><span class="cf-ta">Taille</span></div>`;
    const corps = L.length ? L.map(l => `<button type="button" class="cf-l${l.cle === st.sel ? ' sel' : ''}" data-cf-sel="${l.cle}">
        <span class="cf-ic"><b>${esc(l.ext)}</b></span><span class="cf-c"><b>${esc(l.titre)}${l.nouveau ? ' <em class="cf-nv">nouveau</em>' : ''}</b><small>${esc(l.sous)}</small></span>
        ${st.onglet === 'tout' ? `<span class="cf-o">${esc(l.ongletNom)}</span>` : ''}<span class="cf-d">${esc(dfr(l.date))}</span><span class="cf-e"><span class="cf-st ${l.etat.c}">${esc(l.etat.t)}</span></span>
        <span class="cf-p2">${esc(l.par)}</span><span class="cf-ta">${esc(l.taille)}</span></button>`).join('')
      : `<div class="cf-vide">${st.q ? 'Aucun document ne répond à « ' + esc(st.q) + ' ».' : st.onglet === 'legal' ? 'Aucune pièce légale pour l’instant : déposez la première ci-dessus.' : 'Rien dans cet onglet pour l’instant. Votre consultant y dépose les documents du réseau.'}</div>`;
    const GD = (st.d.reperes || {}).dates || {};
    const bouton = `<button type="button" class="db-btn cf-gd${st.vue === 'dates' ? ' on' : ''}" data-cf-vue="${st.vue === 'dates' ? 'docs' : 'dates'}" title="Triennats du bail, préavis, échéances">📅 Grandes dates${GD.bientot ? `<i>${GD.bientot}</i>` : ''}${GD.prochaine ? `<small>${esc(GD.prochaine.titre)} · ${esc(dfr(GD.prochaine.le))}</small>` : ''}</button>`;
    st.el.innerHTML = `${st.flash ? `<div class="cf-flash ${st.flash.ok ? 'ok' : 'ko'}">${esc(st.flash.texte)}</div>` : ''}${reperes()}
      <div class="cf-barre"><span>🔒 Votre coffre-fort : vos documents et ceux que votre consultant y dépose. Chaque ouverture est notée.</span>${bouton}${st.vue === 'docs' ? `<input class="db-sel cf-q" data-cf-q="1" value="${esc(st.q)}" placeholder="Chercher un document…">` : ''}</div>
      ${st.vue === 'dates' ? vueDatesHtml() : `<div class="cf-lay"><section class="cf-carte"><div class="cf-onglets">${onglets}</div>
        ${st.onglet === 'legal' ? depot() : ''}${tete}<div class="cf-liste">${corps}</div></section>${apercu(sel)}</div>`}`;
    brancher();
    if (garde !== null) { const q = st.el.querySelector('[data-cf-q]'); if (q) { q.focus(); try { q.setSelectionRange(garde, garde); } catch (e) { /* sans curseur */ } } }
  }
  function brancher() {
    const E = st.el;
    E.querySelectorAll('[data-cf-onglet]').forEach(b => b.addEventListener('click', () => { st.onglet = b.dataset.cfOnglet; st.sel = null; st.flash = null; rendre(); }));
    E.querySelectorAll('[data-cf-sel]').forEach(b => b.addEventListener('click', () => { st.sel = b.dataset.cfSel; rendre(); }));
    E.querySelectorAll('[data-cf-ouvre]').forEach(a => a.addEventListener('click', () => ouvert(+a.dataset.cfOuvre)));
    const q = E.querySelector('[data-cf-q]'); if (q) { q.addEventListener('input', () => { st.q = q.value; rendre(); }); }
    const dp = E.querySelector('[data-cf-depot]'); if (dp) { dp.addEventListener('click', () => { st.depot = { titre: '', date: new Date().toISOString().slice(0, 10), echeance: '', note: '', fichiers: [] }; st.flash = null; rendre(); }); }
    E.querySelectorAll('[data-cf-d]').forEach(i => i.addEventListener('change', () => { st.depot[i.dataset.cfD] = i.value; }));
    const fi = E.querySelector('[data-cf-fichiers]'); if (fi) { fi.addEventListener('change', () => { st.depot.fichiers = Array.from(fi.files || []); rendre(); }); }
    const an = E.querySelector('[data-cf-annule]'); if (an) { an.addEventListener('click', () => { st.depot = null; rendre(); }); }
    const en = E.querySelector('[data-cf-envoi]'); if (en) { en.addEventListener('click', deposer); }
    // Les grandes dates.
    E.querySelectorAll('[data-cf-vue]').forEach(b => b.addEventListener('click', () => { if (b.dataset.cfVue === 'dates') { vueDates(); } else { st.vue = 'docs'; st.flash = null; rendre(); } }));
    E.querySelectorAll('[data-cf-assist]').forEach(b => b.addEventListener('click', () => { const x = docs().find(d => d.id === +b.dataset.cfAssist); lancerAssistant(+b.dataset.cfAssist, x ? x.nom : ''); }));
    E.querySelectorAll('[data-cf-voir]').forEach(a => a.addEventListener('click', ev => { ev.preventDefault(); st.vue = 'docs'; st.onglet = 'tout'; st.q = ''; st.sel = 'd' + a.dataset.cfVoir; rendre(); }));
    E.querySelectorAll('[data-cf-retire]').forEach(b => b.addEventListener('click', () => retirerDate(+b.dataset.cfRetire)));
    const pa = E.querySelector('[data-cf-passees]'); if (pa) { pa.addEventListener('click', () => { st.passees = !st.passees; rendre(); }); }
    const aj = E.querySelector('[data-cf-ajout]'); if (aj) { aj.addEventListener('click', () => { st.ajout = { sorte: 'autre', titre: '', le: '', fin: '', note: '', docId: '' }; rendre(); }); }
    E.querySelectorAll('[data-cf-a]').forEach(i => i.addEventListener('change', () => { st.ajout[i.dataset.cfA] = i.value; }));
    const aa = E.querySelector('[data-cf-a-annule]'); if (aa) { aa.addEventListener('click', () => { st.ajout = null; rendre(); }); }
    const ao = E.querySelector('[data-cf-a-ok]'); if (ao) { ao.addEventListener('click', () => { E.querySelectorAll('[data-cf-a]').forEach(i => { st.ajout[i.dataset.cfA] = i.value; }); ajouterDate(); }); }
    const ad = E.querySelector('[data-cf-as-doc]'); if (ad) { ad.addEventListener('change', () => { if (ad.value) { const x = docs().find(d => d.id === +ad.value); lancerAssistant(+ad.value, x ? x.nom : ''); } }); }
    const A = st.assist;
    E.querySelectorAll('[data-cf-as-c]').forEach(c => c.addEventListener('change', () => { if (c.checked) { A.coches.add(c.dataset.cfAsC); } else { A.coches.delete(c.dataset.cfAsC); } rendre(); }));
    E.querySelectorAll('[data-cf-as-le]').forEach(i => i.addEventListener('change', () => { A.le[i.dataset.cfAsLe] = i.value; }));
    const at = E.querySelector('[data-cf-as-titre]'); if (at) { at.addEventListener('change', () => { A.titreOk = at.checked; }); }
    const ae = E.querySelector('[data-cf-as-ech]'); if (ae) { ae.addEventListener('change', () => { A.echOk = ae.checked; }); }
    const ap = E.querySelector('[data-cf-as-passees]'); if (ap) { ap.addEventListener('click', () => { A.voirPassees = !A.voirPassees; rendre(); }); }
    const ar = E.querySelector('[data-cf-as-range]'); if (ar) { ar.addEventListener('click', rangerAssistant); }
    const rl = E.querySelector('[data-cf-as-relance]'); if (rl) { rl.addEventListener('click', () => lancerAssistant(A.docId, A.nom)); }
    E.querySelectorAll('[data-cf-as-ferme]').forEach(b => b.addEventListener('click', () => { st.assist = null; rendre(); }));
  }

  window.DashCoffre = {
    racine(shop, o) {
      o = o || {};
      if (o.apiBase) { st.api = o.apiBase; }
      if (!st.el || String(st.shop) !== String(shop)) {
        st.shop = String(shop); st.el = document.createElement('div'); st.el.className = 'cf-racine';
        Object.assign(st, { d: null, err: null, onglet: 'tout', sel: null, q: '', depot: null, flash: null, vue: 'docs', dates: null, datesErr: null, ajout: null, passees: false, assist: null });
        rendre(); charger();
      }
      return st.el;
    },
    relire() { if (st.el) { charger(); if (st.dates) { lireDates(); } } },
    /** Les documents du coffre (pour « Joindre du coffre-fort » de la Boîte mail) : [{id, titre, onglet, nom, octets}]. */
    liste(shop, apiBase) {
      if (apiBase) { st.api = apiBase; }
      return lireCoffre(shop).then(d => (d.documents || []).map(x => ({ id: x.id, titre: x.titre, onglet: (d.onglets.find(o => o.cle === x.onglet) || {}).nom || '', nom: x.nom, octets: x.octets })));
    },
  };
})();
