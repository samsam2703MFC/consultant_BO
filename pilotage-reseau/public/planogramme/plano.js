/* La vue tablette du comptoir standard.
 *
 * Le même plan pour tous les magasins, lu zone par zone : les cinq zones du
 * comptoir › une zone vue de face, étage par étage › la fiche d'un produit.
 * Le moment de la journée (matin, midi, après-midi) se choisit tout seul à
 * l'heure de la tablette : on voit ce qui doit être en place maintenant.
 * Une fois une zone dressée, « Zone montée » prend la photo ; le cockpit la
 * garde pour la journée, à côté de la tâche « Photo du comptoir » du panel.
 *
 * Tout est relatif : la page vit sous /planogramme/, l'API sous ../api/cockpit.
 */
(function () {
  'use strict';
  const API = '../api/cockpit';
  const $ = document.getElementById('pg');
  const q = new URLSearchParams(location.search);
  const AUJ = (function () { const t = new Date(); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0'); })();
  const S = { shop: q.get('shop') || '4', zone: q.get('zone') || null, fiche: null, zoom: null, moment: q.get('moment') || null,
    ps: null, photos: {}, taches: [], nomShop: '', montage: {}, err: null, enCours: false, envoi: false };
  const NIV = { e3: 'Étage 3', e2: 'Étage 2', e1b: 'Étage 1 · arrière', e1a: 'Étage 1 · avant' };
  const GC = { 'Viennoiserie': '#D4A04A', 'Boulangerie': '#A87B4F', 'Pâtisserie': '#C46A7A', 'Tartes': '#B5654A', 'Tartes · Pâtisserie': '#B5654A', 'Biscuiterie': '#B08850', 'Épicerie': '#7A6FA8', 'Traiteur': '#5E8C61', 'Quiches': '#9A7B3C', 'Fêtes & Occasions': '#8D1D2C' };

  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const hh = x => x ? String(x).slice(11, 16) : '';
  const ini = n => String(n || '?').split(/[\s\-–·]+/).filter(w => w && /^[A-Za-zÀ-ÿ0-9]/.test(w)).slice(0, 2).map(w => w[0].toUpperCase()).join('') || '?';
  const qte = v => v == null ? '' : String(v).replace('.', ',');

  function lire(path) {
    return fetch(API + path, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : r.json().then(j => Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status))), () => Promise.reject(new Error('HTTP ' + r.status))));
  }
  function ecrire(path, body) {
    return fetch(API + path, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body) })
      .then(r => r.ok ? r.json() : r.json().then(j => Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status))), () => Promise.reject(new Error('HTTP ' + r.status))));
  }

  function charger() {
    S.enCours = true; S.err = null; rendre();
    Promise.all([
      lire('/planogramme/standard'),
      lire('/pwa/tasks?date=' + AUJ).catch(() => null),
      lire('/planogramme/standard/montage?shop=' + encodeURIComponent(S.shop) + '&date=' + AUJ).catch(() => ({ zones: {} })),
    ]).then(([ps, tk, mo]) => {
      S.ps = ps;
      const sh = tk && Array.isArray(tk.shops) ? tk.shops.find(x => String(x.shopId || x.id) === String(S.shop)) : null;
      S.taches = sh && Array.isArray(sh.taches) ? sh.taches : [];
      S.nomShop = sh ? String(sh.nom || sh.name || sh.shop || '') : '';
      S.montage = (mo && mo.zones) || {};
      if (!S.moment) { S.moment = momentAuto(); }
      if (S.zone && !zones().some(z => z.id === S.zone)) { S.zone = null; }
      photos(refsDuPlan(), 0);
    }).catch(e => { S.err = e.message; }).finally(() => { S.enCours = false; rendre(); });
  }
  /** Les photos des recettes du panel, 24 par appel ; le serveur les garde une fois téléchargées. */
  function photos(refs, essai) {
    const manque = refs.filter(r => !(r in S.photos)).slice(0, 24);
    if (!manque.length || essai > 8) { return; }
    lire('/planogramme/standard/photos?refs=' + manque.map(encodeURIComponent).join(',')).then(d => {
      Object.assign(S.photos, (d && d.photos) || {});
      manque.forEach(r => { if (!(r in S.photos)) { S.photos[r] = { url: null }; } });
      rendre();
      photos(refs, essai + 1);
    }).catch(() => { /* les vignettes restent en initiales */ });
  }

  /* --- ce que l'on sait --------------------------------------------------- */
  const L = () => (S.ps && S.ps.layout) || {};
  const zones = () => L().zones || [];
  const periodes = () => L().periodes || [];
  const E = () => { const o = {}; ((S.ps && S.ps.emplacements) || []).forEach(e => { o[e.cle] = e; }); return o; };
  const refsDuPlan = () => [...new Set(((S.ps && S.ps.emplacements) || []).flatMap(e => (e.occupants || []).map(o => String(o.ref))))];
  /** Le moment de la tablette : mêmes heures que les ventes (matin jusqu'à 10 h, midi 11–14 h, après-midi dès 15 h). */
  function momentAuto() {
    const h = new Date().getHours();
    const p = periodes().find(x => h >= x.de && h <= x.a);
    return p ? p.k : 'journee';
  }
  const nomMoment = k => { const p = periodes().find(x => x.k === k); return p ? p.nom : 'Toute la journée'; };
  const momentsTxt = per => (!per || per.length >= periodes().length) ? 'toute la journée' : per.map(k => nomMoment(k).toLowerCase()).join(' + ');
  /** Ce qui doit être en place : le produit du moment ; en « journée », celui qui tient le plus de moments. */
  function vu(e) {
    const O = (e && e.occupants) || []; if (!O.length) { return null; }
    if (S.moment && S.moment !== 'journee') { return O.find(o => o.periodes.indexOf(S.moment) >= 0) || null; }
    return O.reduce((a, o) => o.periodes.length > a.periodes.length ? o : a);
  }
  function image(o) {
    if (!o) { return null; }
    if (o.photo) { return '../' + o.photo; }
    const p = S.photos[String(o.ref)];
    return p && p.url ? '../' + p.url : null;
  }
  function imgStyle(c) {
    c = c || { x: 0.5, y: 0.5, s: 1 };
    const px = (100 * c.x).toFixed(1) + '%', py = (100 * c.y).toFixed(1) + '%';
    return `object-position:${px} ${py};transform:scale(${c.s});transform-origin:${px} ${py}`;
  }
  const vignette = o => { const u = image(o); return u ? `<img src="${esc(u)}" alt="" style="${imgStyle(o.crop)}" loading="lazy">` : `<span class="ini" style="background:${GC[o.groupe] || '#9a8f84'}">${esc(ini(o.nom))}</span>`; };
  const sectionsDe = z => { const out = []; for (let s = z.de; s <= z.a; s++) { out.push(s); } return out; };
  const estCaisse = s => (L().caisse || []).indexOf(s) >= 0;
  function compte(z) {
    const e = E(); let n = 0, p = 0;
    sectionsDe(z).forEach(s => ['e3', 'e2', 'e1b', 'e1a'].forEach(k => { const x = e[s + '|' + k]; if (x) { n++; if (vu(x)) { p++; } } }));
    return { n, p };
  }
  /** La tâche « Photo du comptoir - <zone> » du panel, si elle existe pour ce magasin aujourd'hui. */
  const norm = s => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, ' ').trim();
  function tacheDe(z) {
    const nz = norm(z.nom); if (!nz) { return null; }
    return S.taches.find(t => { const nt = norm(t.tache); if (!/^photo du comptoir/.test(nt)) { return false; } const reste = nt.replace(/^photo du comptoir\s*/, ''); const sing = x => x.replace(/s\b/g, ''); if (reste === nz || sing(reste) === sing(nz)) { return true; } return nz.length >= 4 && reste.length >= 4 && (reste.indexOf(nz) === 0 || nz.indexOf(reste) === 0); }) || null;
  }
  const libStatut = t => !t ? null : (t.statut === 'nonRendue' ? ['ko', 'photo à rendre'] : (t.statut === 'aControler' || t.statut === 'aValider' || t.statut === 'notee' ? ['wa', 'photo rendue · à contrôler'] : (t.statut === 'valide' ? ['ok', 'photo validée'] : ['wa', t.statut])));

  /* --- rendu -------------------------------------------------------------- */
  function rendre() {
    let h = S.zone ? rendZone() : rendZones();
    if (S.fiche) { h += rendFiche(S.fiche); }
    if (S.zoom) { h += `<div class="pg-zoom" data-zoomx="1"><img src="${esc(S.zoom.url)}" alt="">${S.zoom.cap ? `<div class="cap">${esc(S.zoom.cap)}</div>` : ''}</div>`; }
    $.innerHTML = h; brancher();
  }

  function top(titre, sous, retour, extra) {
    const P = periodes();
    const auto = S.ps ? momentAuto() : null;
    return `<div class="pg-top">${retour ? `<span class="pg-ico" data-retour="1" title="Revenir aux zones">‹</span>` : `<a class="pg-ico" href="../#/planogramme" title="Revenir au cockpit">✕</a>`}
      <div class="t">${titre}${sous ? `<small>${sous}</small>` : ''}</div><span style="flex:1"></span>
      ${P.length ? `<div class="pg-seg">${P.map(p => `<button data-moment="${esc(p.k)}" class="${S.moment === p.k ? 'on' : ''}" title="${p.de}h – ${p.a}h">${esc(p.nom)}${auto === p.k ? ' <i class="mnt">maintenant</i>' : ''}</button>`).join('')}<button data-moment="journee" class="${S.moment === 'journee' ? 'on' : ''}" title="Le produit principal de chaque emplacement">Journée</button></div>` : ''}
      ${extra || ''}<span class="pg-ico" data-recharger="1" title="Relire le plan">↻</span></div>`;
  }

  // Niveau 0 : les zones du comptoir.
  function rendZones() {
    let h = top('Le comptoir' + (S.nomShop ? ' · ' + esc(S.nomShop) : ''), 'le plan standard, zone par zone — ' + esc(nomMoment(S.moment).toLowerCase()), false);
    if (S.err) { h += `<div class="pg-err">${esc(S.err)}</div>`; }
    if (!S.ps) { return h + (S.enCours ? '<div class="pg-attente">Lecture du plan…</div>' : ''); }
    const e = E();
    h += `<div class="pg-zones">${zones().map((z, i) => {
      const c = compte(z), t = tacheDe(z), st = libStatut(t), mo = S.montage[z.id];
      const S2 = sectionsDe(z).filter(s => !estCaisse(s));
      const mini = ['e3', 'e2', 'e1b', 'e1a'].map(k => `<div class="lv" style="grid-template-columns:repeat(${S2.length},1fr)">${S2.map(s => { const x = e[s + '|' + k]; if (!x) { return '<i class="off"></i>'; } const o = vu(x); const u = image(o); return `<i class="${o ? (u ? 'ph' : '') : 'v'}" style="${u ? 'background-image:url(' + esc(u) + ')' : (o ? 'background:' + (GC[o.groupe] || '#9a8f84') : '')}"></i>`; }).join('')}</div>`).join('');
      return `<div class="pg-zc" data-zone="${esc(z.id)}"><div class="h"><b>${i + 1}. ${esc(z.nom)}</b><span>sections ${z.de} – ${z.a}</span></div>
        <div class="mini">${mini}</div>
        <div class="st"><span>${c.p} / ${c.n} emplacements</span>${mo ? `<span class="pg-chip ok">✓ montée à ${hh(mo.quand)}</span>` : `<span class="pg-chip">à monter</span>`}${st ? `<span class="pg-chip ${st[0]}">panel : ${esc(st[1])}</span>` : ''}</div></div>`; }).join('')}</div>`;
    return h;
  }

  // Niveau 1 : une zone de face, étage par étage.
  function rendZone() {
    const Z = zones(), idx = Z.findIndex(z => z.id === S.zone), z = Z[idx];
    if (!z) { S.zone = null; return rendZones(); }
    const c = compte(z), t = tacheDe(z), st = libStatut(t), mo = S.montage[z.id], e = E();
    let h = top(`<span class="crumb">Zone ${idx + 1} / ${Z.length} ›</span> ${esc(z.nom)}`, `sections ${z.de} – ${z.a} · ${c.p} / ${c.n} emplacements · ${esc(nomMoment(S.moment).toLowerCase())}`, true,
      `<button class="pg-btn ${mo ? 'ok' : 'p'}" data-monter="${esc(z.id)}" ${S.envoi ? 'disabled' : ''}>${S.envoi ? 'envoi…' : (mo ? '✓ Zone montée · reprendre la photo' : '📷 Zone montée · photo')}</button>`);
    if (S.err) { h += `<div class="pg-err">${esc(S.err)}</div>`; }
    if (mo || st) { h += `<div class="pg-montage" style="margin-top:12px">${mo ? `<img src="../${esc(mo.photo)}" alt="" data-zoom="../${esc(mo.photo)}" data-cap="${esc(z.nom)} · montée à ${hh(mo.quand)}${mo.auteur ? ' par ' + esc(mo.auteur) : ''}">` : ''}<div class="t">${mo ? 'Zone montée à ' + hh(mo.quand) + (mo.auteur ? ' par ' + esc(mo.auteur) : '') : 'Zone pas encore photographiée aujourd’hui'}<small>${st ? 'Tâche du panel « ' + esc(t.tache) + ' » : ' + esc(st[1]) + ' — la photo se rend dans l’application du panel.' : 'Pas de tâche « Photo du comptoir » pour cette zone dans le panel.'}</small></div><span class="sp"></span>${mo ? `<button class="pg-btn" data-demonter="${esc(z.id)}">retirer</button>` : ''}</div>`; }
    const secs = sectionsDe(z);
    // Des tuiles carrées, plafonnées : une zone de quatre sections ne devient pas un mur de photos.
    const cols = secs.map(s => estCaisse(s) ? 'minmax(0,110px)' : 'minmax(0,165px)').join(' ');
    const tuile = (s, k) => {
      const x = e[s + '|' + k];
      if (!x) { return `<div class="pg-sl off" title="Pas d’étage ici"></div>`; }
      const o = vu(x), O = x.occupants || [];
      if (!o) { return `<div class="pg-sl v" title="S${s} · ${NIV[k]} — libre ${S.moment === 'journee' ? '' : 'à ce moment'}"><span class="lib">libre</span></div>`; }
      const partage = O.length > 1;
      return `<div class="pg-sl" data-fiche="${esc(x.cle)}" title="${esc(o.nom)}">${vignette(o)}${o.qte > 0 ? `<span class="f">×${esc(qte(o.qte))}</span>` : ''}${partage ? `<span class="mo">${esc(S.moment === 'journee' ? O.length + ' moments' : momentsTxt(o.periodes))}</span>` : ''}<span class="n">${esc(o.nom)}</span></div>`;
    };
    // La caisse : un seul bloc sur ses sections, rien au-dessus.
    const nC = (L().caisse || []).length;
    const caisse = (s, e1) => s !== (L().caisse || [])[0] ? '' : (e1 ? `<div class="pg-caisse" style="grid-column:span ${nC}">caisse</div>` : `<div style="grid-column:span ${nC}"></div>`);
    const ligne = (lab, sub, cellule) => `<div class="pg-lv"><div class="lab"><b>${lab}</b>${sub}</div><div class="pg-row" style="grid-template-columns:${cols}">${secs.map(s => estCaisse(s) ? caisse(s, lab === 'Étage 1') : cellule(s)).join('')}</div></div>`;
    h += `<div class="pg-car"><div class="pg-arr ${idx > 0 ? '' : 'off'}" data-zone="${idx > 0 ? esc(Z[idx - 1].id) : ''}">‹</div><div class="pg-vit">
      ${ligne('Étage 3', 'top picking', s => tuile(s, 'e3'))}
      ${ligne('Étage 2', '20 cm', s => tuile(s, 'e2'))}
      ${ligne('Étage 1', 'arrière / avant', s => `<div class="pg-e1">${tuile(s, 'e1b')}${tuile(s, 'e1a')}</div>`)}
      <div class="pg-lv"><span></span><div class="pg-regle" style="grid-template-columns:${cols}">${secs.map(s => `<span>${estCaisse(s) ? '' : 'S' + s}</span>`).join('')}</div></div>
    </div><div class="pg-arr ${idx < Z.length - 1 ? '' : 'off'}" data-zone="${idx < Z.length - 1 ? esc(Z[idx + 1].id) : ''}">›</div></div>
      <div class="pg-dots">${Z.map(x => `<i class="${x.id === z.id ? 'on' : ''}" data-zone="${esc(x.id)}"></i>`).join('')}</div>`;
    return h;
  }

  // Niveau 2 : la fiche de l'emplacement, produit par moment.
  function rendFiche(cle) {
    const x = E()[cle]; if (!x) { return ''; }
    const z = zones().find(zz => x.section >= zz.de && x.section <= zz.a) || { nom: '' };
    const o0 = vu(x);
    const bloc = o => { const u = image(o);
      return `<div class="b${o === o0 ? ' now' : ''}"><div class="ph ${u ? '' : 'v'}" ${u ? `data-zoom="${esc(u)}" data-cap="${esc(o.nom)}"` : ''}>${u ? `<img src="${esc(u)}" alt="" style="${imgStyle(o.crop)}">` : `<span class="ini" style="background:${GC[o.groupe] || '#9a8f84'}">${esc(ini(o.nom))}</span>`}</div><div class="c">
        ${o === o0 && S.moment !== 'journee' ? '<span class="pg-chip ok" style="align-self:flex-start">en place maintenant</span>' : ''}
        <h2>${esc(o.nom)}</h2><div class="ref">réf. ${esc(o.ref)}${o.groupe ? ' · ' + esc(o.groupe) : ''}</div>
        <div class="kv"><b>Où</b><span>${esc(z.nom)} › section ${x.section} › ${NIV[x.niveau]}</span>
          <b>Quand</b><span>${esc(momentsTxt(o.periodes))}</span>
          <b>Quantité</b><span>${o.qte > 0 ? esc(qte(o.qte)) + ' pièce(s) quand l’emplacement est plein' : 'pas encore fixée au cockpit'}</span></div></div></div>`; };
    return `<div class="pg-fiche" data-fermerx="1"><div class="pile">${(x.occupants || []).slice().sort((a, b) => (b === o0) - (a === o0)).map(bloc).join('')}<button class="pg-btn x" data-fermer="1">Fermer</button></div></div>`;
  }

  /* --- la photo de la zone montée ------------------------------------------ */
  function prendrePhoto(zoneId) {
    const inp = document.createElement('input'); inp.type = 'file'; inp.accept = 'image/*'; inp.setAttribute('capture', 'environment');
    inp.addEventListener('change', () => {
      const f = inp.files && inp.files[0]; if (!f) { return; }
      // On réduit sur la tablette : 1600 px de large suffisent à juger une zone, et l'envoi reste léger.
      const img = new Image(); const url = URL.createObjectURL(f);
      img.onload = () => { const k = Math.min(1, 1600 / img.width); const cv = document.createElement('canvas'); cv.width = Math.round(img.width * k); cv.height = Math.round(img.height * k); cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height); URL.revokeObjectURL(url);
        const data = cv.toDataURL('image/jpeg', 0.82);
        S.envoi = true; S.err = null; rendre();
        ecrire('/planogramme/standard/montage', { shop: S.shop, zone: zoneId, date: AUJ, data, auteur: q.get('qui') || '' })
          .then(r => { S.montage[zoneId] = { photo: r.photo, auteur: r.auteur, quand: r.quand }; })
          .catch(e => { S.err = 'Photo non enregistrée : ' + e.message; })
          .finally(() => { S.envoi = false; rendre(); }); };
      img.onerror = () => { S.err = 'Photo illisible.'; rendre(); };
      img.src = url;
    });
    inp.click();
  }

  /* --- les gestes --------------------------------------------------------- */
  function brancher() {
    $.querySelectorAll('[data-zone]').forEach(el => el.addEventListener('click', () => { const id = el.dataset.zone; if (!id) { return; } S.zone = id; window.scrollTo(0, 0); rendre(); }));
    $.querySelectorAll('[data-fiche]').forEach(el => el.addEventListener('click', e => { e.stopPropagation(); S.fiche = el.dataset.fiche; rendre(); }));
    $.querySelectorAll('[data-fermer]').forEach(el => el.addEventListener('click', () => { S.fiche = null; rendre(); }));
    $.querySelectorAll('[data-fermerx]').forEach(el => el.addEventListener('click', e => { if (e.target === el) { S.fiche = null; rendre(); } }));
    $.querySelectorAll('[data-retour]').forEach(el => el.addEventListener('click', () => { S.zone = null; window.scrollTo(0, 0); rendre(); }));
    $.querySelectorAll('[data-moment]').forEach(el => el.addEventListener('click', () => { S.moment = el.dataset.moment; rendre(); }));
    $.querySelectorAll('[data-recharger]').forEach(el => el.addEventListener('click', () => charger()));
    $.querySelectorAll('[data-monter]').forEach(el => el.addEventListener('click', () => prendrePhoto(el.dataset.monter)));
    $.querySelectorAll('[data-demonter]').forEach(el => el.addEventListener('click', () => { const id = el.dataset.demonter; if (!confirm('Retirer la photo du montage d’aujourd’hui ?')) { return; } ecrire('/planogramme/standard/montage', { shop: S.shop, zone: id, date: AUJ, data: '' }).then(() => { delete S.montage[id]; }).catch(e => { S.err = e.message; }).finally(rendre); }));
    $.querySelectorAll('[data-zoom]').forEach(el => el.addEventListener('click', e => { e.stopPropagation(); S.zoom = { url: el.dataset.zoom, cap: el.dataset.cap || '' }; rendre(); }));
    $.querySelectorAll('[data-zoomx]').forEach(el => el.addEventListener('click', () => { S.zoom = null; rendre(); }));
    // Balayer à gauche ou à droite change de zone.
    let x0 = null; $.ontouchstart = e => { x0 = e.touches[0].clientX; }; $.ontouchend = e => { if (x0 == null || !S.zone || S.fiche || S.zoom) { x0 = null; return; } const dx = e.changedTouches[0].clientX - x0; x0 = null; if (Math.abs(dx) < 70) { return; } const Z = zones(), i = Z.findIndex(z => z.id === S.zone); const j = dx < 0 ? i + 1 : i - 1; if (Z[j]) { S.zone = Z[j].id; window.scrollTo(0, 0); rendre(); } };
  }
  // Le moment suit l'horloge : on relit l'heure toutes les cinq minutes, sans toucher au choix fait à la main.
  let momentVu = null;
  setInterval(() => { if (!S.ps) { return; } const m = momentAuto(); if (momentVu !== null && m !== momentVu && S.moment === momentVu) { S.moment = m; rendre(); } momentVu = m; }, 300000);
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && (S.fiche || S.zoom)) { S.fiche = null; S.zoom = null; rendre(); } });

  charger();
})();
