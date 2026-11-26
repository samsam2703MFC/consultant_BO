/* Module Formation › Médiathèque › Gérer les vidéos (26/11/2026, Sam : « vidéo d'utilisation du dashboard, vidéo de
 * production… faire une section spéciale dans le rail de formation »).
 *
 *   #/mediatheque[/<collection>]   les vidéos filmées d'une collection (Production, Vente et comptoir, Hygiène et
 *                                  sécurité, Ouverture et fermeture) : affiche, titre, catégorie, durée, source, publiée
 *   #/mediatheque/nouvelle[/<col>] une vidéo nouvelle : un FICHIER (MP4, MOV ou WebM filmé au téléphone, envoyé en
 *                                  morceaux de 4 Mo, l'affiche et la durée lues dans le navigateur) ou un LIEN (YouTube,
 *                                  Vimeo, Google Drive, l'adresse d'un fichier vidéo)
 *   #/mediatheque/v/<id>           la modifier, la retirer ou la supprimer
 * Les vidéos d'utilisation du dashboard (tournées sur écran) ne se gèrent pas ici : panel/tools/videos/.
 * Routes : formation/src/mediatheque.php. Styles : mediatheque.css. Branché sur window.FormationSop comme examens.js.
 */
(function (FS) {
  'use strict';
  if (!FS) { return; }
  let C = null;
  const esc = v => (C ? C.esc(v) : String(v == null ? '' : v));
  const L = { d: null, err: null, charge: false, ed: null, edId: undefined, occupe: false, msg: null, prog: null };
  const RACINE = new URL('../', location.href).href;   // le site : le portail vit sous formation/
  const CATALOGUE = 'catalogue/#/videos';
  const nomCol = k => { const c = L.d && L.d.collections.find(x => x.cle === k); return c ? c.nom.fr : k; };
  const mo = n => n == null ? '' : n >= 1073741824 ? (n / 1073741824).toFixed(1).replace('.', ',') + ' Go' : Math.max(1, Math.round(n / 1048576)) + ' Mo';
  const SOURCES = { youtube: 'YouTube', vimeo: 'Vimeo', drive: 'Google Drive', fichier: 'Lien vers un fichier' };

  function charger(force) {
    if ((L.d || L.charge) && !force) { return; }
    L.charge = true;
    C.lire('/formation/mediatheque', 60000).then(d => { L.d = d; L.err = null; }, e => { L.err = e.message; })
      .finally(() => { L.charge = false; C.rendre(true); });
  }
  const msg = () => L.msg ? `<div class="mtg-msg ${L.msg.ok ? 'ok' : 'ko'}">${esc(L.msg.txt)}</div>` : '';

  /* ── la liste ── */
  function liste(col) {
    const D = L.d, V = D.videos.filter(v => v.collection === col);
    const cats = [...new Set(V.map(v => v.categorie || ''))].sort((a, b) => a.localeCompare(b, 'fr'));
    return `${msg()}<div class="mtg-barre"><span class="seg">${D.collections.map(c => {
        const n = D.videos.filter(v => v.collection === c.cle).length;
        return `<button class="${c.cle === col ? 'is-on' : ''}" data-mtg="col" data-v="${c.cle}">${esc(c.nom.fr)}${n ? ' · ' + n : ''}</button>`;
      }).join('')}</span><span style="flex:1"></span>
      <a class="btn" href="${CATALOGUE}/c/${col}" target="_blank" rel="noopener">Voir dans la médiathèque ↗</a>
      <a class="btn btn--primary" href="#/mediatheque/nouvelle/${col}">＋ Ajouter une vidéo</a></div>
      ${!V.length ? `<div class="card mtg-vide"><b>Aucune vidéo dans « ${esc(nomCol(col))} »</b><p>Ajoutez une vidéo filmée au téléphone (MP4 ou MOV) ou un lien YouTube, Vimeo ou Google Drive. Elle entre dans la médiathèque du catalogue de formation, rangée par catégorie, et la recherche la trouve.</p><a class="btn btn--primary" href="#/mediatheque/nouvelle/${col}">＋ Ajouter une vidéo</a></div>` :
      cats.map(cat => `<h3 class="mtg-h">${esc(cat || 'Sans catégorie')} <span class="chip">${V.filter(v => (v.categorie || '') === cat).length}</span></h3>
        <div class="mtg-grille">${V.filter(v => (v.categorie || '') === cat).map(carte).join('')}</div>`).join('')}`;
  }
  function affiche(v) {
    if (v.affiche) { return RACINE + v.affiche; }
    if (v.lien && v.lien.type === 'youtube') { return 'https://i.ytimg.com/vi/' + v.lien.id + '/hqdefault.jpg'; }
    return '';
  }
  function carte(v) {
    const a = affiche(v);
    return `<a class="mtg-c${v.publie ? '' : ' brouillon'}" href="#/mediatheque/v/${v.id}">
      <span class="mtg-aff">${a ? `<img src="${esc(a)}" alt="" loading="lazy">` : ''}${v.duree ? `<span class="mtg-d">${esc(v.duree)}</span>` : ''}${v.publie ? '' : '<span class="mtg-st">Non publiée</span>'}</span>
      <span class="mtg-t">${esc(v.titre)}</span>
      <span class="mtg-s">${v.source === 'fichier' ? 'Fichier · ' + esc(mo(v.taille)) : esc(SOURCES[v.lien && v.lien.type] || 'Lien')} · ${esc(v.par || '')}</span></a>`;
  }

  /* ── l'éditeur ── */
  function nouveau(col) {
    return { collection: col || 'production', categorie: '', titre: '', titreNl: '', resume: '', resumeNl: '', source: 'fichier', url: '', publie: true,
      fichierLocal: null, fichierNom: null, afficheData: null, afficheRetiree: false, dureeS: null };
  }
  function editeur(id, col) {
    if (L.edId !== (id == null ? 'n:' + (col || '') : id)) {
      L.edId = id == null ? 'n:' + (col || '') : id;
      const v = id == null ? null : L.d.videos.find(x => x.id === id);
      if (id != null && !v) { return '<div class="mtg-msg ko">Vidéo inconnue.</div>'; }
      L.ed = v ? Object.assign(nouveau(v.collection), { categorie: v.categorie, titre: v.titre, titreNl: v.titreNl, resume: v.resume, resumeNl: v.resumeNl,
        source: v.source, url: v.url || '', publie: v.publie, dureeS: v.dureeS, existant: v }) : nouveau(col);
      L.msg = null; L.prog = null;
    }
    const E = L.ed, v = E.existant, D = L.d;
    const cats = [...new Set(D.videos.filter(x => x.collection === E.collection && x.categorie).map(x => x.categorie))].sort((a, b) => a.localeCompare(b, 'fr'));
    const lien = E.source === 'lien' && E.url ? reconnaitre(E.url) : null;
    const apercu = E.afficheData || (!E.afficheRetiree && v ? affiche(v) : '') || (lien && lien.type === 'youtube' ? 'https://i.ytimg.com/vi/' + lien.id + '/hqdefault.jpg' : '');
    return `${msg()}<div class="mtg-barre"><a class="btn" href="#/mediatheque/${E.collection}">← ${esc(nomCol(E.collection))}</a><span style="flex:1"></span>
        ${v ? `<a class="btn" href="${CATALOGUE}/v/m${v.id}" target="_blank" rel="noopener">Voir dans la médiathèque ↗</a>` : ''}</div>
      <div class="mtg-ed">
        <section class="card mtg-form">
          <div class="mtg-2"><label>Collection<select class="input" data-mtgp="collection">${D.collections.map(c => `<option value="${c.cle}"${c.cle === E.collection ? ' selected' : ''}>${esc(c.nom.fr)}</option>`).join('')}</select></label>
            <label>Catégorie<input class="input" data-mtgp="categorie" value="${esc(E.categorie)}" list="mtg-cats" placeholder="${E.collection === 'production' ? 'Viennoiseries, Tartes, Cuisson…' : 'Une catégorie, au choix'}" maxlength="80"><datalist id="mtg-cats">${cats.map(c => `<option value="${esc(c)}">`).join('')}</datalist></label></div>
          <label>Titre<input class="input" data-mtgp="titre" value="${esc(E.titre)}" maxlength="160" placeholder="Ce que la vidéo apprend : « Façonner un croissant »"></label>
          <label>Titre en néerlandais <small>facultatif</small><input class="input" data-mtgp="titreNl" value="${esc(E.titreNl)}" maxlength="160"></label>
          <label>Résumé<textarea class="input" rows="3" data-mtgp="resume" maxlength="2000" placeholder="Une ou deux phrases : ce qu’on y voit, à qui elle sert.">${esc(E.resume)}</textarea></label>
          <label>Résumé en néerlandais <small>facultatif</small><textarea class="input" rows="2" data-mtgp="resumeNl" maxlength="2000">${esc(E.resumeNl)}</textarea></label>
          <div class="mtg-src"><span class="seg"><button class="${E.source === 'fichier' ? 'is-on' : ''}" data-mtg="source" data-v="fichier">Un fichier vidéo</button><button class="${E.source === 'lien' ? 'is-on' : ''}" data-mtg="source" data-v="lien">Un lien</button></span></div>
          ${E.source === 'fichier' ? `<div class="mtg-fic">
              <label class="btn">${E.fichierLocal || (v && v.fichier) ? 'Choisir un autre fichier' : 'Choisir la vidéo'}<input type="file" accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.m4v,.webm" data-mtg="fichier" hidden></label>
              <span class="mtg-note">${E.fichierLocal ? esc(E.fichierLocal.name) + ' · ' + mo(E.fichierLocal.size) + (E.dureeS ? ' · ' + Math.round(E.dureeS) + ' s' : '') : v && v.fichier ? 'Fichier envoyé · ' + mo(v.taille) : 'MP4, MOV ou WebM, 2 Go au plus ; filmée au téléphone, à l’horizontale de préférence.'}</span></div>` :
            `<label>Lien<input class="input" data-mtgp="url" value="${esc(E.url)}" placeholder="https://www.youtube.com/watch?v=… · https://vimeo.com/… · https://drive.google.com/file/d/…"></label>
             <div class="mtg-note">${!E.url ? 'YouTube (non répertoriée de préférence), Vimeo, Google Drive (partagé « tous ceux qui ont le lien »), ou l’adresse https d’un fichier .mp4.' : lien ? 'Reconnu : ' + esc(SOURCES[lien.type]) + '.' : '<span class="ko">Lien non reconnu.</span>'}</div>
             <label>Durée <small>facultatif, en secondes</small><input class="input mtg-n" type="number" min="0" data-mtgp="dureeS" value="${E.dureeS != null ? Math.round(E.dureeS) : ''}"></label>`}
          ${L.prog != null ? `<div class="mtg-prog"><span style="width:${L.prog}%"></span></div><div class="mtg-note">Envoi : ${L.prog} %</div>` : ''}
          <label class="mtg-ck"><input type="checkbox" data-mtgp="publie"${E.publie ? ' checked' : ''}> Publiée : visible dans la médiathèque des magasins</label>
          <div class="mtg-act"><button class="btn btn--primary" data-mtg="enregistrer"${L.occupe ? ' disabled' : ''}>${L.occupe ? 'Enregistrement…' : 'Enregistrer'}</button>
            ${v ? '<span style="flex:1"></span><button class="btn mtg-suppr" data-mtg="supprimer"' + (L.occupe ? ' disabled' : '') + '>Supprimer la vidéo</button>' : ''}</div>
        </section>
        <aside class="card mtg-apercu"><div class="mtg-k">Affiche</div>
          <div class="mtg-aff grande">${apercu ? `<img src="${esc(apercu)}" alt="">` : '<span class="mtg-note">Pas d’affiche : la carte montre un fond uni.</span>'}</div>
          <div class="mtg-act"><label class="btn">Choisir une image<input type="file" accept="image/jpeg,image/png,image/webp" data-mtg="affiche" hidden></label>
            ${apercu && (E.afficheData || (v && v.affiche && !E.afficheRetiree)) ? '<button class="btn" data-mtg="sansaffiche">Retirer</button>' : ''}</div>
          <p class="mtg-note">${E.source === 'fichier' ? 'Prise dans la vidéo quand on la choisit (si le navigateur sait la lire) ; une image la remplace.' : 'YouTube donne la sienne ; une image la remplace.'}</p></aside>
      </div>`;
  }

  /** Le même test que le serveur (mtLien), pour le dire tout de suite. */
  function reconnaitre(u) {
    let x; try { x = new URL(u); } catch (e) { return null; }
    if (x.protocol !== 'https:') { return null; }
    const h = x.hostname.toLowerCase(), p = x.pathname; let m;
    if (/^(www\.|m\.)?youtube(-nocookie)?\.com$/.test(h)) {
      if (/^[\w-]{11}$/.test(x.searchParams.get('v') || '')) { return { type: 'youtube', id: x.searchParams.get('v') }; }
      if ((m = /^\/(?:shorts|embed|live)\/([\w-]{11})/.exec(p))) { return { type: 'youtube', id: m[1] }; }
      return null;
    }
    if (h === 'youtu.be' && (m = /^\/([\w-]{11})/.exec(p))) { return { type: 'youtube', id: m[1] }; }
    if (/^(www\.|player\.)?vimeo\.com$/.test(h) && (m = /\/(\d{5,12})/.exec(p))) { return { type: 'vimeo', id: m[1] }; }
    if (h === 'drive.google.com' && ((m = /\/file\/d\/([\w-]{10,80})/.exec(p)) || /^[\w-]{10,80}$/.test(x.searchParams.get('id') || ''))) { return { type: 'drive' }; }
    if (/\.(mp4|m4v|webm|mov)$/i.test(p)) { return { type: 'fichier' }; }
    return null;
  }

  /** La vidéo choisie : sa durée et son affiche (une image à 1,5 s), lues dans le navigateur. */
  function lireFichier(f) {
    const E = L.ed;
    E.fichierLocal = f; E.dureeS = null;
    if (!E.titre) { E.titre = f.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').trim(); }
    C.rendre(true);
    const u = URL.createObjectURL(f), vd = document.createElement('video');
    vd.muted = true; vd.preload = 'metadata'; vd.src = u;
    const fin = () => URL.revokeObjectURL(u);
    vd.addEventListener('error', fin);
    vd.addEventListener('loadedmetadata', () => {
      if (isFinite(vd.duration)) { E.dureeS = vd.duration; }
      vd.currentTime = Math.min(1.5, (vd.duration || 3) / 3);
    });
    vd.addEventListener('seeked', () => {
      try {
        const k = Math.min(1, 1280 / (vd.videoWidth || 1280)), cv = document.createElement('canvas');
        cv.width = Math.round((vd.videoWidth || 1280) * k); cv.height = Math.round((vd.videoHeight || 720) * k);
        cv.getContext('2d').drawImage(vd, 0, 0, cv.width, cv.height);
        if (L.ed === E && !E.afficheChoisie) { E.afficheData = cv.toDataURL('image/jpeg', 0.82); E.afficheRetiree = false; }
      } catch (e) { /* une vidéo que le navigateur ne sait pas dessiner : pas d'affiche */ }
      fin(); C.rendre(true);
    }, { once: true });
  }

  /** L'envoi en morceaux : 4 Mo par requête, un morceau renvoyé après une coupure reprend là où le serveur en est. */
  async function envoyer(f) {
    const d = await C.appel('POST', '/formation/mediatheque/envoi', { nom: f.name, taille: f.size });
    const pas = d.morceau || 4194304;
    let recu = 0, essais = 0;
    while (recu < f.size) {
      try {
        const r = await fetch(C.API + '/formation/mediatheque/envoi/' + d.jeton + '?depuis=' + recu, { method: 'PUT', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/octet-stream', Accept: 'application/json' }, body: f.slice(recu, recu + pas) });
        const j = await r.json().catch(() => null);
        if (!r.ok || !j) { throw new Error((j && j.error) || 'HTTP ' + r.status); }
        recu = j.recu; essais = 0;
        L.prog = Math.round(recu / f.size * 100); C.rendre(true);
      } catch (e) {
        if (++essais > 4) { throw e; }
        await new Promise(ok => setTimeout(ok, 1500 * essais));
      }
    }
    const fin = await C.appel('POST', '/formation/mediatheque/envoi/' + d.jeton + '/fin', {});
    return fin.fichier;
  }

  async function enregistrer() {
    const E = L.ed, v = E.existant;
    if (L.occupe) { return; }
    if (!E.titre.trim()) { L.msg = { ok: false, txt: 'Le titre manque.' }; return C.rendre(true); }
    if (E.source === 'fichier' && !E.fichierLocal && !(v && v.fichier)) { L.msg = { ok: false, txt: 'Choisissez la vidéo à envoyer.' }; return C.rendre(true); }
    if (E.source === 'lien' && !reconnaitre(E.url)) { L.msg = { ok: false, txt: 'Le lien n’est pas reconnu.' }; return C.rendre(true); }
    L.occupe = true; L.msg = null; C.rendre(true);
    try {
      const corps = { collection: E.collection, categorie: E.categorie, titre: E.titre, titreNl: E.titreNl, resume: E.resume, resumeNl: E.resumeNl,
        source: E.source, publie: E.publie, dureeS: E.dureeS };
      if (E.source === 'fichier' && E.fichierLocal) { L.prog = 0; C.rendre(true); corps.fichier = await envoyer(E.fichierLocal); }
      if (E.source === 'lien') { corps.url = E.url.trim(); }
      if (E.afficheData) { corps.affiche = E.afficheData; } else if (E.afficheRetiree) { corps.affiche = null; }
      const r = await C.appel(v ? 'PUT' : 'POST', '/formation/mediatheque/videos' + (v ? '/' + v.id : ''), corps, 120000);
      L.prog = null;
      L.d = null; charger(true);
      L.msg = { ok: true, txt: v ? 'Vidéo enregistrée.' : 'Vidéo ajoutée à la médiathèque' + (E.publie ? '' : ' (non publiée)') + '.' };
      L.edId = undefined;
      location.hash = '#/mediatheque/' + r.video.collection;
    } catch (e) { L.msg = { ok: false, txt: e.message }; L.prog = null; }
    finally { L.occupe = false; C.rendre(true); }
  }

  async function supprimer() {
    const v = L.ed && L.ed.existant;
    if (!v || L.occupe || !window.confirm('Supprimer « ' + v.titre + ' » ? La vidéo quitte la médiathèque' + (v.fichier ? ' et son fichier est effacé du serveur' : '') + '. Pour la cacher sans l’effacer, décochez « Publiée ».')) { return; }
    L.occupe = true; C.rendre(true);
    try { await C.appel('DELETE', '/formation/mediatheque/videos/' + v.id); L.msg = { ok: true, txt: 'Vidéo supprimée.' }; L.edId = undefined; L.d = null; charger(true); location.hash = '#/mediatheque/' + v.collection; }
    catch (e) { L.msg = { ok: false, txt: e.message }; }
    finally { L.occupe = false; C.rendre(true); }
  }

  function ecran() {
    const a = C.S.route.a;
    charger(false);
    let corps;
    if (L.err) { corps = `<div class="mtg-msg ko">La médiathèque ne se lit pas : ${esc(L.err)}</div>`; }
    else if (!L.d) { corps = '<p class="mtg-note">Lecture de la médiathèque…</p>'; }
    else if (a[0] === 'nouvelle') { corps = editeur(null, a[1]); }
    else if (a[0] === 'v' && /^\d+$/.test(a[1] || '')) { corps = editeur(+a[1]); }
    else {
      L.edId = undefined; L.ed = null;
      corps = liste(L.d.collections.some(c => c.cle === a[0]) ? a[0] : L.d.collections[0].cle);
    }
    return { titre: 'Médiathèque', kicker: 'Formation · les vidéos filmées', corps: `<div class="mtg">${corps}</div>` };
  }

  function brancher(root) {
    root.addEventListener('click', ev => {
      const b = ev.target.closest('[data-mtg]');
      if (!b || !root.querySelector('.mtg')) { return; }
      const k = b.dataset.mtg;
      if (k === 'col') { L.msg = null; location.hash = '#/mediatheque/' + b.dataset.v; }
      else if (k === 'source' && L.ed) { L.ed.source = b.dataset.v; C.rendre(true); }
      else if (k === 'enregistrer') { enregistrer(); }
      else if (k === 'supprimer') { supprimer(); }
      else if (k === 'sansaffiche' && L.ed) { L.ed.afficheData = null; L.ed.afficheRetiree = true; L.ed.afficheChoisie = true; C.rendre(true); }
    });
    root.addEventListener('input', ev => {
      const t = ev.target;
      if (!L.ed || !t.dataset.mtgp || t.type === 'checkbox' || t.tagName === 'SELECT') { return; }
      L.ed[t.dataset.mtgp] = t.dataset.mtgp === 'dureeS' ? (t.value === '' ? null : +t.value) : t.value;
      if (t.dataset.mtgp === 'url') { clearTimeout(L.minut); L.minut = setTimeout(() => { const p = t.selectionStart; C.rendre(true); const i = root.querySelector('[data-mtgp="url"]'); if (i) { i.focus(); try { i.setSelectionRange(p, p); } catch (e) { /* */ } } }, 400); }
    });
    root.addEventListener('change', ev => {
      const t = ev.target;
      if (!L.ed) { return; }
      if (t.dataset.mtgp === 'publie') { L.ed.publie = t.checked; return; }
      if (t.dataset.mtgp === 'collection') { L.ed.collection = t.value; return C.rendre(true); }
      if (t.dataset.mtg === 'fichier' && t.files && t.files[0]) { return lireFichier(t.files[0]); }
      if (t.dataset.mtg === 'affiche' && t.files && t.files[0]) {
        const r = new FileReader();
        r.onload = () => { L.ed.afficheData = String(r.result); L.ed.afficheChoisie = true; L.ed.afficheRetiree = false; C.rendre(true); };
        r.readAsDataURL(t.files[0]);
      }
    });
  }

  FS.ecrans.mediatheque = ecran;
  FS.icones.mediatheque = 'M3 5h18v14H3zM10 9l5 3-5 3z';
  FS.icones['catalogue/#/videos'] = 'M4 6h16M4 12h16M4 18h10';
  // Les collections dans le rail : l'icône de lecture.
  ['dashboard', 'production', 'vente', 'hygiene', 'ouverture'].forEach(k => { FS.icones['catalogue/#/videos/c/' + k] = 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM10 8.5l5 3.5-5 3.5z'; });
  const initAvant = FS.init;
  FS.init = function (ctx, root) { initAvant.call(FS, ctx, root); C = ctx; brancher(root); };
})(window.FormationSop);
