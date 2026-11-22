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

  const st = { shop: null, api: '../api/cockpit', el: null, d: null, err: null, onglet: 'tout', sel: null, q: '', depot: null, occupe: false, flash: null };

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
        if (n) { charger(); }
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
    st.el.innerHTML = `${st.flash ? `<div class="cf-flash ${st.flash.ok ? 'ok' : 'ko'}">${esc(st.flash.texte)}</div>` : ''}${reperes()}
      <div class="cf-barre"><span>🔒 Votre coffre-fort : vos documents et ceux que votre consultant y dépose. Chaque ouverture est notée.</span><input class="db-sel cf-q" data-cf-q="1" value="${esc(st.q)}" placeholder="Chercher un document…"></div>
      <div class="cf-lay"><section class="cf-carte"><div class="cf-onglets">${onglets}</div>
        ${st.onglet === 'legal' ? depot() : ''}${tete}<div class="cf-liste">${corps}</div></section>${apercu(sel)}</div>`;
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
  }

  window.DashCoffre = {
    racine(shop, o) {
      o = o || {};
      if (o.apiBase) { st.api = o.apiBase; }
      if (!st.el || String(st.shop) !== String(shop)) {
        st.shop = String(shop); st.el = document.createElement('div'); st.el.className = 'cf-racine';
        Object.assign(st, { d: null, err: null, onglet: 'tout', sel: null, q: '', depot: null, flash: null });
        rendre(); charger();
      }
      return st.el;
    },
    relire() { if (st.el) { charger(); } },
    /** Les documents du coffre (pour « Joindre du coffre-fort » de la Boîte mail) : [{id, titre, onglet, nom, octets}]. */
    liste(shop, apiBase) {
      if (apiBase) { st.api = apiBase; }
      return lireCoffre(shop).then(d => (d.documents || []).map(x => ({ id: x.id, titre: x.titre, onglet: (d.onglets.find(o => o.cle === x.onglet) || {}).nom || '', nom: x.nom, octets: x.octets })));
    },
  };
})();
