/* L'audit des fournisseurs — la page du franchisé (12/11/2026). Une lecture : GET ../api/cockpit/audits/public?d=&jeton= ;
 * trois écritures : POST …/audits/public/section (les réponses d'un fournisseur, à chaque « Suivant »),
 * …/audits/public/photo (une photo réduite à 1600 px), …/audits/public/terminer (tout est répondu : envoyé).
 * La page parle la langue du magasin ; les questions restent dans la langue où le siège les a écrites.
 * ?apercu=1[&c=] : la vue du bureau (GET …/audits/apercu), rien n'est enregistré. */
(function () {
  'use strict';
  const API = '../api/cockpit';
  const q = new URLSearchParams(location.search);
  const D = q.get('d') || '', JETON = q.get('jeton') || '', APERCU = q.get('apercu') === '1', CAMP = q.get('c') || '';
  const $ = document.getElementById('au'), pied = document.getElementById('pied'), piedIn = document.getElementById('pied-in');
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const MOTS = {
    fr: { titre: 'Audit fournisseurs', jusqua: 'jusqu’au', fournisseurs: 'fournisseurs', fait: 'terminé', cours: 'en cours', afaire: 'à faire',
      commencer: 'Commencer', reprendre: 'Reprendre', suivant: 'Suivant', liste: 'Liste', envoyer: 'Envoyer mes réponses', fin: 'Dernière étape',
      finTxt: 'Tous les fournisseurs sont notés. Envoyez vos réponses : vous pourrez encore les modifier jusqu’à la clôture.',
      merci: 'Merci, c’est envoyé', modif: 'Vous pouvez encore modifier vos réponses jusqu’au {d} avec le même lien.', revoir: 'Revoir mes réponses',
      ensuite: 'Et ensuite ?', s1: 'Le siège regroupe les réponses de toutes les boutiques.', s2: 'Chaque fournisseur reçoit une synthèse et la note du franchiseur, sans le nom de votre boutique.', s3: 'Vous recevez les résultats du réseau après la clôture.',
      reprise: 'Vos réponses sont enregistrées à chaque écran : vous pouvez fermer et reprendre plus tard avec le même lien.',
      pourquoi: 'Commentaire (obligatoire)', photo: 'Ajouter une photo', retirer: 'Retirer', obl: 'Merci de répondre aux questions en rouge.',
      clos: 'Cet audit est clos : les réponses ne sont plus modifiables.', lien: 'Ce lien n’est pas (ou plus) valable. Demandez un nouveau lien au siège.',
      reseau: 'La connexion a échoué. Réessayez dans un instant.', apercu: 'Aperçu du siège : rien n’est enregistré.', facultatif: 'facultatif', envoi: 'Envoi…' },
    nl: { titre: 'Leveranciersaudit', jusqua: 'tot', fournisseurs: 'leveranciers', fait: 'klaar', cours: 'bezig', afaire: 'te doen',
      commencer: 'Beginnen', reprendre: 'Verdergaan', suivant: 'Volgende', liste: 'Lijst', envoyer: 'Mijn antwoorden versturen', fin: 'Laatste stap',
      finTxt: 'Alle leveranciers zijn beoordeeld. Verstuur uw antwoorden: u kunt ze nog wijzigen tot de afsluiting.',
      merci: 'Bedankt, het is verstuurd', modif: 'U kunt uw antwoorden nog wijzigen tot {d} met dezelfde link.', revoir: 'Mijn antwoorden bekijken',
      ensuite: 'En nu?', s1: 'Het hoofdkantoor bundelt de antwoorden van alle winkels.', s2: 'Elke leverancier ontvangt een samenvatting en de nota van de franchisegever, zonder de naam van uw winkel.', s3: 'U ontvangt de resultaten van het netwerk na de afsluiting.',
      reprise: 'Uw antwoorden worden bij elk scherm bewaard: u kunt sluiten en later verdergaan met dezelfde link.',
      pourquoi: 'Opmerking (verplicht)', photo: 'Foto toevoegen', retirer: 'Verwijderen', obl: 'Beantwoord de vragen in het rood.',
      clos: 'Deze audit is afgesloten: de antwoorden kunnen niet meer gewijzigd worden.', lien: 'Deze link is niet (meer) geldig. Vraag een nieuwe link aan het hoofdkantoor.',
      reseau: 'De verbinding is mislukt. Probeer het zo meteen opnieuw.', apercu: 'Voorbeeld: er wordt niets bewaard.', facultatif: 'optioneel', envoi: 'Versturen…' },
    en: { titre: 'Supplier audit', jusqua: 'until', fournisseurs: 'suppliers', fait: 'done', cours: 'in progress', afaire: 'to do',
      commencer: 'Start', reprendre: 'Continue', suivant: 'Next', liste: 'List', envoyer: 'Send my answers', fin: 'Last step',
      finTxt: 'All suppliers are rated. Send your answers: you can still change them until the closing date.',
      merci: 'Thank you, it is sent', modif: 'You can still change your answers until {d} with the same link.', revoir: 'Review my answers',
      ensuite: 'What next?', s1: 'Head office gathers the answers of all shops.', s2: 'Each supplier receives a summary and the franchisor’s note, without your shop’s name.', s3: 'You receive the network’s results after closing.',
      reprise: 'Your answers are saved at each screen: you can close and continue later with the same link.',
      pourquoi: 'Comment (required)', photo: 'Add a photo', retirer: 'Remove', obl: 'Please answer the questions in red.',
      clos: 'This audit is closed: answers can no longer be changed.', lien: 'This link is not (or no longer) valid. Ask head office for a new link.',
      reseau: 'The connection failed. Please try again in a moment.', apercu: 'Head office preview: nothing is saved.', facultatif: 'optional', envoi: 'Sending…' },
    de: { titre: 'Lieferanten-Audit', jusqua: 'bis', fournisseurs: 'Lieferanten', fait: 'fertig', cours: 'begonnen', afaire: 'offen',
      commencer: 'Beginnen', reprendre: 'Fortfahren', suivant: 'Weiter', liste: 'Liste', envoyer: 'Meine Antworten senden', fin: 'Letzter Schritt',
      finTxt: 'Alle Lieferanten sind bewertet. Senden Sie Ihre Antworten: Sie können sie bis zum Abschluss noch ändern.',
      merci: 'Danke, es ist gesendet', modif: 'Sie können Ihre Antworten bis zum {d} mit demselben Link noch ändern.', revoir: 'Meine Antworten ansehen',
      ensuite: 'Und dann?', s1: 'Die Zentrale fasst die Antworten aller Geschäfte zusammen.', s2: 'Jeder Lieferant erhält eine Zusammenfassung und die Notiz des Franchisegebers, ohne den Namen Ihres Geschäfts.', s3: 'Sie erhalten die Ergebnisse des Netzwerks nach dem Abschluss.',
      reprise: 'Ihre Antworten werden bei jedem Schritt gespeichert: Sie können schließen und später mit demselben Link fortfahren.',
      pourquoi: 'Kommentar (Pflicht)', photo: 'Foto hinzufügen', retirer: 'Entfernen', obl: 'Bitte beantworten Sie die rot markierten Fragen.',
      clos: 'Dieses Audit ist abgeschlossen: die Antworten können nicht mehr geändert werden.', lien: 'Dieser Link ist nicht (mehr) gültig. Bitten Sie die Zentrale um einen neuen Link.',
      reseau: 'Die Verbindung ist fehlgeschlagen. Bitte versuchen Sie es gleich noch einmal.', apercu: 'Vorschau der Zentrale: es wird nichts gespeichert.', facultatif: 'optional', envoi: 'Senden…' }
  };
  let M = MOTS.fr, S = null, vue = 'accueil', idx = 0, manques = {}, erreur = '', occupe = false;
  const rep = {};   // fournisseur → question → { valeur, commentaire }

  const qs = APERCU ? 'apercu' + (CAMP ? '?c=' + encodeURIComponent(CAMP) : '') : 'public?d=' + encodeURIComponent(D) + '&jeton=' + encodeURIComponent(JETON);
  const url = chemin => API + '/audits/' + chemin + (chemin.includes('?') ? '&' : '?') + 'd=' + encodeURIComponent(D) + '&jeton=' + encodeURIComponent(JETON);
  async function api(methode, chemin, corps) {
    const r = await fetch(url(chemin), { method: methode, headers: { 'Content-Type': 'application/json' }, body: corps ? JSON.stringify(corps) : undefined });
    let j = null; try { j = await r.json(); } catch (e) { j = null; }
    return { ok: r.ok, statut: r.status, j: j || {} };
  }
  const dateFr = s => s ? s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4) : '';
  const lecture = () => !APERCU && S && !S.campagne.ouverte;

  /* --- conditions (les mêmes que src/audits.php : auCondition, auVisible) */
  function cond(c, v) {
    if (!c || v == null || v === '') return false;
    if (c.op === 'in') return Array.isArray(v) ? v.some(x => c.valeur.includes(x)) : c.valeur.includes(String(v));
    const n = Number(String(v).replace(',', '.')); if (isNaN(n)) return false;
    return c.op === 'le' ? n <= Number(c.valeur) : n >= Number(c.valeur);
  }
  const val = (f, qid) => (rep[f.id][qid] || {}).valeur;
  const vide = v => v == null || v === '' || (Array.isArray(v) && !v.length);
  function visible(f, x) {
    if (!x.parent) return true;
    const v = val(f, x.parent);
    return x.si ? cond(x.si, v) : !vide(v);
  }
  function manquesDe(f) {
    const m = {};
    f.questions.forEach(x => {
      if (!visible(f, x)) return;
      const r = rep[f.id][x.id] || {};
      if (x.obligatoire && vide(r.valeur)) m[x.id] = 'reponse';
      else if (x.commentaireSi && cond(x.commentaireSi, r.valeur) && !String(r.commentaire || '').trim()) m[x.id] = 'commentaire';
    });
    return m;
  }
  const fini = f => Object.keys(rep[f.id]).length > 0 && !Object.keys(manquesDe(f)).length;
  const commence = f => Object.values(rep[f.id]).some(r => !vide(r.valeur));

  /* --- rendu */
  function tete() {
    return `<header class="au-hd"><img src="../assets/img/logo.png" alt=""><div><h1>${esc(M.titre)}</h1>
      <small>${esc(S.magasin.court)} · ${esc(S.campagne.nom)}${S.campagne.cloture ? ' · ' + esc(M.jusqua) + ' ' + dateFr(S.campagne.cloture) : ''}</small></div></header>
      ${APERCU ? `<div class="au-apercu">${esc(M.apercu)}</div>` : ''}${lecture() ? `<div class="au-apercu">${esc(M.clos)}</div>` : ''}`;
  }
  function prog(actif) {
    return `<div class="au-prog">${S.fournisseurs.map((f, i) => `<i class="${i === actif ? 'on' : fini(f) ? 'ok' : ''}"></i>`).join('')}</div>`;
  }
  function accueil() {
    const lignes = S.fournisseurs.map((f, i) => {
      const etat = fini(f) ? 'ok' : commence(f) ? 'cours' : '';
      return `<button class="au-four" data-i="${i}"><span class="n ${etat}">${etat === 'ok' ? '✓' : i + 1}</span>
        <span><b>${esc(f.nom)}</b><small>${esc(f.cat || '')}${f.cat ? ' · ' : ''}${esc(etat === 'ok' ? M.fait : etat === 'cours' ? M.cours : M.afaire)}</small></span></button>`;
    }).join('');
    const suivant = S.fournisseurs.findIndex(f => !fini(f));
    bas(suivant >= 0 ? `<button class="au-btn prim" data-aller="${suivant}">${esc(Object.values(rep).some(r => Object.keys(r).length) ? M.reprendre : M.commencer)} : ${esc(S.fournisseurs[suivant].nom)}</button>`
      : `<button class="au-btn prim" data-fin>${esc(S.termine ? M.revoir : M.fin)}</button>`);
    return `${tete()}<section class="au-carte"><h2>${S.fournisseurs.length} ${esc(M.fournisseurs)}</h2>${lignes}
      <p class="au-mu" style="margin:12px 0 0">${esc(M.reprise)}</p></section>`;
  }
  function champ(f, x) {
    const r = rep[f.id][x.id] || {}, v = r.valeur, ro = lecture() ? ' disabled' : '';
    const opt = (code, lib, on, attr) => `<button class="au-opt${on ? ' on' : ''}" ${attr}="${esc(code)}" data-q="${x.id}"${ro}>${esc(lib)}</button>`;
    switch (x.type) {
      case 'note': return `<div class="au-note">${[1, 2, 3, 4, 5].map(n => opt(n, n, Number(v) === n, 'data-note')).join('')}</div>
        ${x.min || x.max ? `<div class="au-bornes"><span>${esc(x.min)}</span><span>${esc(x.max)}</span></div>` : ''}`;
      case 'ouinon': case 'choix': return `<div class="au-opts">${x.choix.map(c => opt(c.code, c.libelle, v === c.code, 'data-choix')).join('')}</div>`;
      case 'multi': return `<div class="au-opts">${x.choix.map(c => opt(c.code, c.libelle, Array.isArray(v) && v.includes(c.code), 'data-multi')).join('')}</div>`;
      case 'nombre': return `<div style="display:flex;gap:8px;align-items:center"><input class="au-in" type="number" inputmode="decimal" step="any" data-nombre="${x.id}" value="${esc(v == null ? '' : v)}"${ro} style="max-width:160px">${x.unite ? `<span class="au-mu">${esc(x.unite)}</span>` : ''}</div>`;
      case 'texte': return `<textarea class="au-ta" data-texte="${x.id}"${ro}>${esc(v || '')}</textarea>`;
      case 'date': return `<input class="au-in" type="date" data-date="${x.id}" value="${esc(v || '')}"${ro} style="max-width:200px">`;
      case 'photo': return `<div class="au-ph">${v ? `<img src="../${esc(v)}" alt="">${ro ? '' : `<button class="au-btn" data-photo-retirer="${x.id}">${esc(M.retirer)}</button>`}` : ''}
        ${ro || v ? '' : `<label class="au-btn" style="display:inline-flex;align-items:center">${esc(M.photo)}<input type="file" accept="image/*" capture="environment" data-photo="${x.id}" hidden></label>`}</div>`;
    }
    return '';
  }
  function fournisseur() {
    const f = S.fournisseurs[idx];
    const blocs = f.questions.filter(x => visible(f, x)).map(x => {
      const r = rep[f.id][x.id] || {};
      const com = x.commentaireSi && cond(x.commentaireSi, r.valeur);
      return `<div class="au-q${x.parent ? ' sous' : ''}${manques[x.id] ? ' manque' : ''}"><b>${esc(x.libelle)}${x.obligatoire ? '' : ` <span class="au-mu">(${esc(M.facultatif)})</span>`}</b>
        ${x.aide ? `<span class="aide">${esc(x.aide)}</span>` : ''}${champ(f, x)}
        ${com ? `<label class="au-com"><small>${esc(M.pourquoi)}</small><textarea class="au-ta" data-com="${x.id}"${lecture() ? ' disabled' : ''}>${esc(r.commentaire || '')}</textarea></label>` : ''}</div>`;
    }).join('');
    bas(`<button class="au-btn" data-liste>${esc(M.liste)}</button><button class="au-btn prim" data-suivant${occupe ? ' disabled' : ''}>${esc(occupe ? M.envoi : M.suivant)}</button>`);
    return `${tete()}<section class="au-carte"><div class="au-mu" style="margin-bottom:6px">${idx + 1} / ${S.fournisseurs.length}${f.cat ? ' · ' + esc(f.cat) : ''}</div>
      <h2>${esc(f.nom)}</h2>${prog(idx)}<div style="margin-top:8px">${blocs}</div>${erreur ? `<p class="au-err">${esc(erreur)}</p>` : ''}</section>`;
  }
  function fin() {
    if (S.termine && !APERCU) {
      bas(`<button class="au-btn prim" data-liste>${esc(M.revoir)}</button>`);
      return `${tete()}<section class="au-carte au-ok"><div class="c"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12l5 5 9-10"/></svg></div>
        <h2>${esc(M.merci)}</h2><p class="au-mu">${esc(M.modif.replace('{d}', dateFr(S.campagne.cloture)))}</p></section>
        <section class="au-carte"><h2 style="font-size:17px">${esc(M.ensuite)}</h2><ol class="au-suite"><li>${esc(M.s1)}</li><li>${esc(M.s2)}</li><li>${esc(M.s3)}</li></ol></section>`;
    }
    bas(`<button class="au-btn" data-liste>${esc(M.liste)}</button><button class="au-btn prim" data-terminer${occupe || lecture() ? ' disabled' : ''}>${esc(occupe ? M.envoi : M.envoyer)}</button>`);
    return `${tete()}<section class="au-carte"><h2>${esc(M.fin)}</h2>${prog(-1)}<p class="au-mu" style="margin-top:10px">${esc(M.finTxt)}</p>${erreur ? `<p class="au-err">${esc(erreur)}</p>` : ''}</section>`;
  }
  function bas(html) { piedIn.innerHTML = html; pied.hidden = false; }
  function rendu() {
    if (!S) return;
    $.innerHTML = vue === 'fournisseur' ? fournisseur() : vue === 'fin' ? fin() : accueil();
  }
  function aller(v, i) { vue = v; if (i != null) idx = i; manques = {}; erreur = ''; rendu(); window.scrollTo(0, 0); }

  /* --- saisie */
  function poser(qid, champ, valeur) {
    const f = S.fournisseurs[idx];
    rep[f.id][qid] = Object.assign({}, rep[f.id][qid] || {}, { [champ]: valeur });
    if (manques[qid]) delete manques[qid];
  }
  async function enregistrer(f) {
    if (APERCU || lecture()) return true;
    const reponses = f.questions.map(x => ({ question: x.id, valeur: visible(f, x) ? (rep[f.id][x.id] || {}).valeur ?? null : null, commentaire: (rep[f.id][x.id] || {}).commentaire || '' }));
    try {
      const r = await api('POST', 'public/section', { fournisseur: f.id, reponses });
      if (!r.ok) { erreur = r.statut === 409 ? M.clos : r.statut === 403 ? M.lien : (r.j.message || r.j.error || M.reseau); return false; }
      return true;
    } catch (e) { erreur = M.reseau; return false; }
  }
  async function suivant() {
    const f = S.fournisseurs[idx];
    manques = manquesDe(f); erreur = '';
    if (Object.keys(manques).length) { erreur = M.obl; rendu(); const el = $.querySelector('.manque'); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
    occupe = true; rendu();
    const ok = await enregistrer(f);
    occupe = false;
    if (!ok) { rendu(); return; }
    const reste = S.fournisseurs.findIndex((g, i) => i > idx && !fini(g));
    const autre = S.fournisseurs.findIndex(g => !fini(g));
    if (reste >= 0) aller('fournisseur', reste); else if (autre >= 0 && autre !== idx) aller('fournisseur', autre); else aller('fin');
  }
  async function terminer() {
    if (APERCU) { S.termine = 'apercu'; aller('fin'); return; }
    occupe = true; erreur = ''; rendu();
    try {
      const r = await api('POST', 'public/terminer', {});
      occupe = false;
      if (r.ok) { S.termine = r.j.termine; aller('fin'); return; }
      if (r.statut === 422 && r.j.manques && r.j.manques.length) {
        const i = S.fournisseurs.findIndex(f => f.id === r.j.manques[0].fournisseur);
        aller('fournisseur', i >= 0 ? i : 0); manques = Object.fromEntries(r.j.manques[0].manques.map(m => [m.question, m.motif])); erreur = M.obl; rendu(); return;
      }
      erreur = r.statut === 409 ? M.clos : (r.j.message || M.reseau); rendu();
    } catch (e) { occupe = false; erreur = M.reseau; rendu(); }
  }
  /** La photo, réduite à 1600 px de côté (JPEG), envoyée tout de suite. */
  function photo(qid, fichier) {
    if (!fichier) return;
    const lect = new FileReader();
    lect.onload = () => {
      const img = new Image();
      img.onload = async () => {
        const k = Math.min(1, 1600 / Math.max(img.width, img.height));
        const c = document.createElement('canvas'); c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        const data = c.toDataURL('image/jpeg', 0.82);
        if (APERCU) { erreur = M.apercu; rendu(); return; }
        try {
          const r = await api('POST', 'public/photo', { data });
          if (r.ok) { poser(qid, 'valeur', r.j.chemin); erreur = ''; } else { erreur = r.j.error || M.reseau; }
        } catch (e) { erreur = M.reseau; }
        rendu();
      };
      img.src = lect.result;
    };
    lect.readAsDataURL(fichier);
  }

  document.addEventListener('click', ev => {
    const b = ev.target.closest('button'); if (!b || b.disabled) return;
    if (b.dataset.i != null || b.dataset.aller != null) { aller('fournisseur', Number(b.dataset.i ?? b.dataset.aller)); return; }
    if (b.hasAttribute('data-liste')) { aller('accueil'); return; }
    if (b.hasAttribute('data-fin')) { aller('fin'); return; }
    if (b.hasAttribute('data-suivant')) { suivant(); return; }
    if (b.hasAttribute('data-terminer')) { terminer(); return; }
    const qid = Number(b.dataset.q || b.dataset.photoRetirer || 0); if (!qid) return;
    const f = S.fournisseurs[idx], r = rep[f.id][qid] || {};
    if (b.dataset.note != null) poser(qid, 'valeur', Number(b.dataset.note));
    else if (b.dataset.choix != null) poser(qid, 'valeur', b.dataset.choix);
    else if (b.dataset.multi != null) { const l = Array.isArray(r.valeur) ? r.valeur.slice() : []; const i = l.indexOf(b.dataset.multi); if (i >= 0) l.splice(i, 1); else l.push(b.dataset.multi); poser(qid, 'valeur', l); }
    else if (b.dataset.photoRetirer != null) poser(qid, 'valeur', null);
    rendu();
  });
  document.addEventListener('input', ev => {
    const t = ev.target, ds = t.dataset;
    if (ds.nombre) poser(Number(ds.nombre), 'valeur', t.value === '' ? null : t.value);
    else if (ds.texte) poser(Number(ds.texte), 'valeur', t.value);
    else if (ds.date) poser(Number(ds.date), 'valeur', t.value || null);
    else if (ds.com) poser(Number(ds.com), 'commentaire', t.value);
  });
  document.addEventListener('change', ev => {
    const t = ev.target;
    if (t.dataset.photo) photo(Number(t.dataset.photo), t.files && t.files[0]);
    // Un nombre ou une date peut afficher une sous-question : le rendu suit à la sortie du champ.
    else if (t.dataset.nombre || t.dataset.date) rendu();
  });

  (async function demarrer() {
    if (!APERCU && (!D || !JETON)) { $.innerHTML = `<p class="au-err">${esc(M.lien)}</p>`; return; }
    try {
      const r = await fetch(API + '/audits/' + qs);
      const j = await r.json().catch(() => ({}));
      if (!r.ok) { $.innerHTML = `<p class="au-err">${esc(r.status === 403 ? M.lien : (j.message || j.error || M.reseau))}</p>`; return; }
      S = j; M = MOTS[S.langue] || MOTS.fr; document.documentElement.lang = S.langue || 'fr';
      S.fournisseurs.forEach(f => { rep[f.id] = {}; Object.entries(f.reponses || {}).forEach(([k, v]) => { rep[f.id][k] = { valeur: v.valeur, commentaire: v.commentaire || '' }; }); });
      if (S.termine) aller('fin'); else rendu();
    } catch (e) { $.innerHTML = `<p class="au-err">${esc(M.reseau)}</p>`; }
  })();
})();
