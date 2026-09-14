/* La vue tablette du planogramme.
 *
 * Du plus large au plus proche : les comptoirs (zones) › un comptoir et ses
 * meubles › un meuble en grand, niveau par niveau › la fiche d'un produit.
 * À chaque niveau, le « pourquoi » : la note posée dans le cockpit sur la
 * zone, le meuble, le niveau ou le produit. Une fois le comptoir dressé, on
 * pousse sur « comptoir monté » : la tablette prend la photo, le cockpit la
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
  const S = { shop: q.get('shop') || '4', vue: 'zones', zone: null, meuble: null, fiche: null, zoom: null,
    periode: q.get('periode') || periodeAuto(), pl: null, photos: {}, taches: [], montage: {}, err: null, enCours: false, envoi: false };

  function periodeAuto() { const h = new Date().getHours(); return h < 11 ? 'matin' : (h < 14 ? 'midi' : 'apresmidi'); }
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fD = d => d ? d.slice(8, 10) + '/' + d.slice(5, 7) : '';
  const hh = q => q ? q.slice(11, 16) : '';

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
      lire('/planogramme?shop=' + encodeURIComponent(S.shop)),
      lire('/planogramme/photos').catch(() => ({ photos: {} })),
      lire('/pwa/tasks?date=' + AUJ).catch(() => null),
      lire('/planogramme/montage?shop=' + encodeURIComponent(S.shop) + '&date=' + AUJ).catch(() => ({ zones: {} })),
    ]).then(([pl, ph, tk, mo]) => {
      S.pl = pl; S.photos = (ph && ph.photos) || {};
      const sh = tk && Array.isArray(tk.shops) ? tk.shops.find(x => String(x.shopId || x.id) === String(S.shop)) : null;
      S.taches = sh && Array.isArray(sh.taches) ? sh.taches : [];
      S.montage = (mo && mo.zones) || {};
      // La première zone s'ouvre d'elle-même quand l'URL en nomme une.
      const z = q.get('zone'); if (z && (pl.zones || []).some(x => String(x.id) === z)) { S.zone = +z; S.vue = 'zone'; }
    }).catch(e => { S.err = e.message; }).finally(() => { S.enCours = false; rendre(); });
  }

  /* --- ce que l'on sait --------------------------------------------------- */
  const zones = () => (S.pl && S.pl.zones) || [];
  const notes = () => (S.pl && S.pl.notes) || {};
  const note = (cible, id) => { const n = notes()[cible + ':' + id]; return n && (n.texte || n.photo) ? n : null; };
  const periodes = () => ((S.pl && S.pl.referentiels && S.pl.referentiels.periodes) || []);
  const nomPeriode = slug => { const p = periodes().find(x => x.slug === slug); return p ? (p.nom || slug) : slug; };
  /** Un meuble est là à ce moment s'il n'en déclare aucun, ou s'il déclare celui-ci. */
  const meubleVisible = m => !S.periode || !(m.periodes || []).length || m.periodes.indexOf(S.periode) >= 0;
  const occVisible = o => !S.periode || !(o.periodes || []).length || o.periodes.indexOf(S.periode) >= 0;
  const occupants = s => (s.occupants || []).filter(occVisible);
  const photoRef = ref => ((notes()['ref:' + ref] || {}).photo ? '../' + notes()['ref:' + ref].photo : null) || ((S.photos[String(ref)] || {}).url || null);
  const meublesDe = z => (z.meubles || []).filter(meubleVisible);
  const compte = z => { let s = 0, p = 0; meublesDe(z).forEach(m => (m.niveaux || []).forEach(n => (n.slots || []).forEach(sl => { s++; if (occupants(sl).length) { p++; } }))); return { slots: s, places: p }; };
  /** La tâche « Photo du comptoir - <zone> » du panel, si elle existe pour ce magasin aujourd'hui. */
  const norm = s => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, ' ').trim();
  function tacheDe(z) {
    const nz = norm(z.nom); if (!nz) { return null; }
    return S.taches.find(t => { const nt = norm(t.tache); if (!/^photo du comptoir/.test(nt)) { return false; } const reste = nt.replace(/^photo du comptoir\s*/, ''); const sing = x => x.replace(/s\b/g, ''); if (reste === nz || sing(reste) === sing(nz)) { return true; } return nz.length >= 4 && (reste.indexOf(nz) === 0 || nz.indexOf(reste) === 0); }) || null;
  }
  const libStatut = t => !t ? null : (t.statut === 'nonRendue' ? ['ko', 'photo à rendre'] : (t.statut === 'aControler' || t.statut === 'aValider' || t.statut === 'notee' ? ['wa', 'photo rendue · à contrôler'] : (t.statut === 'valide' ? ['ok', 'photo validée'] : ['wa', t.statut])));

  /* --- rendu -------------------------------------------------------------- */
  function rendre() {
    let h = '';
    if (S.vue === 'zones') { h = rendZones(); }
    else if (S.vue === 'zone') { h = rendZone(); }
    else if (S.vue === 'meuble') { h = rendMeuble(); }
    if (S.fiche) { h += rendFiche(S.fiche); }
    if (S.zoom) { h += `<div class="pg-zoom" data-zoomx="1"><img src="${esc(S.zoom.url)}" alt="">${S.zoom.cap ? `<div class="cap">${esc(S.zoom.cap)}</div>` : ''}</div>`; }
    $.innerHTML = h; brancher();
  }

  function top(titre, sous, retour, extra) {
    return `<div class="pg-top">${retour ? `<span class="pg-ico" data-retour="1" title="Revenir">‹</span>` : `<a class="pg-ico" href="../#/planogramme" title="Revenir au cockpit">✕</a>`}
      <div class="t">${titre}${sous ? `<small>${sous}</small>` : ''}</div><span style="flex:1"></span>
      <div class="pg-seg">${periodes().map(p => `<button data-periode="${esc(p.slug)}" class="${S.periode === p.slug ? 'on' : ''}" title="${esc(p.aide || '')}">${esc(p.nom || p.slug)}</button>`).join('')}<button data-periode="" class="${!S.periode ? 'on' : ''}" title="Tous les meubles, quel que soit le moment">Tout</button></div>
      ${extra || ''}<span class="pg-ico" data-recharger="1" title="Relire">↻</span></div>`;
  }

  function miniMeuble(m) {
    return `<div class="m">${(m.niveaux || []).map(n => `<div class="lv" style="grid-template-columns:repeat(${Math.max(1, (n.slots || []).length)},1fr)">${(n.slots || []).map(sl => { const o = occupants(sl)[0]; const u = o ? photoRef(o.ref) : null; return `<i class="${o ? (u ? 'ph' : '') : 'v'}" style="${u ? 'background-image:url(' + esc(u) + ')' : ''}"></i>`; }).join('')}</div>`).join('')}</div>`;
  }

  // Niveau 0 : les comptoirs.
  function rendZones() {
    let h = top('Le comptoir', 'du plus large au plus proche : comptoir › meuble › produit', false);
    if (S.err) { h += `<div class="pg-err">${esc(S.err)}</div>`; }
    if (!S.pl) { return h + (S.enCours ? '<div class="pg-attente">Lecture du planogramme…</div>' : ''); }
    const Z = zones();
    if (!Z.length) { return h + '<div class="pg-note mu">Aucun comptoir n’est déclaré dans le cockpit. Il se dessine dans Cockpit › Catalogue › Planogramme › Organiser le comptoir.</div>'; }
    h += `<div class="pg-zones">${Z.map((z, i) => { const c = compte(z), nz = note('zone', z.id), t = tacheDe(z), st = libStatut(t), mo = S.montage[String(z.id)];
      return `<div class="pg-zc" data-zone="${z.id}"><div class="h"><b>${i + 1}. ${esc(z.nom)}</b><span>${meublesDe(z).length} meuble${meublesDe(z).length > 1 ? 's' : ''} · ${c.places} / ${c.slots} placés</span></div>
        <div class="mini" style="grid-template-columns:repeat(${Math.max(1, Math.min(3, meublesDe(z).length))},1fr)">${meublesDe(z).slice(0, 3).map(miniMeuble).join('') || '<div class="pg-note mu" style="margin:0">aucun meuble à ce moment</div>'}</div>
        ${nz && nz.texte ? `<div class="why">${esc(nz.texte)}</div>` : ''}
        <div class="st">${mo ? `<span class="pg-chip ok">✓ monté à ${hh(mo.quand)}</span>` : `<span class="pg-chip">à monter</span>`}${st ? `<span class="pg-chip ${st[0]}">panel : ${esc(st[1])}</span>` : ''}</div></div>`; }).join('')}</div>`;
    return h;
  }

  // Niveau 1 : un comptoir, ses meubles en cartes, la photo du montage.
  function rendZone() {
    const Z = zones(), idx = Z.findIndex(z => z.id === S.zone), z = Z[idx];
    if (!z) { S.vue = 'zones'; return rendZones(); }
    const c = compte(z), nz = note('zone', z.id), t = tacheDe(z), st = libStatut(t), mo = S.montage[String(z.id)];
    let h = top(`<span class="crumb">Comptoir ${idx + 1} / ${Z.length} ›</span> ${esc(z.nom)}`, `${meublesDe(z).length} meuble(s) · ${c.places} / ${c.slots} placés`, true,
      `<button class="pg-btn ${mo ? 'ok' : 'p'}" data-monter="${z.id}" ${S.envoi ? 'disabled' : ''}>${S.envoi ? 'envoi…' : (mo ? '✓ Comptoir monté · reprendre la photo' : '📷 Comptoir monté · photo')}</button>`);
    if (S.err) { h += `<div class="pg-err">${esc(S.err)}</div>`; }
    if (nz && nz.texte) { h += `<div class="pg-note"><b>Pourquoi ce comptoir.</b> ${esc(nz.texte)}</div>`; }
    if (mo || st) { h += `<div class="pg-montage" style="margin-top:12px">${mo ? `<img src="../${esc(mo.photo)}" alt="" data-zoom="../${esc(mo.photo)}" data-cap="${esc(z.nom)} · monté à ${hh(mo.quand)}${mo.auteur ? ' par ' + esc(mo.auteur) : ''}">` : ''}<div class="t">${mo ? 'Comptoir monté à ' + hh(mo.quand) + (mo.auteur ? ' par ' + esc(mo.auteur) : '') : 'Comptoir pas encore photographié aujourd’hui'}<small>${st ? 'Tâche du panel « ' + esc(t.tache) + ' » : ' + esc(st[1]) + ' — la photo se rend dans l’application du panel.' : 'Pas de tâche « Photo du comptoir » pour ce comptoir dans le panel.'}</small></div><span class="sp"></span>${mo ? `<button class="pg-btn" data-demonter="${z.id}">retirer</button>` : ''}</div>`; }
    const M = meublesDe(z);
    h += `<div class="pg-car"><div class="pg-arr ${idx > 0 ? '' : 'off'}" data-zone="${idx > 0 ? Z[idx - 1].id : ''}">‹</div><div class="pg-meubles">${M.length ? M.map(m => { const nm = note('meuble', m.id); const meta = [m.type, m.temperature, m.presentation, (m.periodes || []).length ? m.periodes.map(nomPeriode).join(' · ') : 'toute la journée'].filter(Boolean).join(' · '); let np = 0, ns = 0; (m.niveaux || []).forEach(n => (n.slots || []).forEach(sl => { ns++; if (occupants(sl).length) { np++; } }));
      return `<div class="pg-mc" data-meuble="${m.id}"><div class="h"><b>${esc(m.nom)}</b><span>${esc(meta)}<br>${(m.niveaux || []).length} niv. · ${np} / ${ns} placés</span></div>
        <div class="pg-shelf">${(m.niveaux || []).map(n => `<div class="pg-lvl" style="grid-template-columns:${(n.slots || []).map(sl => Math.max(1, (occupants(sl)[0] || {}).fronts || 1) + 'fr').join(' ') || '1fr'}">${(n.slots || []).map(sl => tuileSlot(sl, false)).join('')}</div>`).join('')}</div>
        ${nm && nm.texte ? `<div class="why">${esc(nm.texte)}</div>` : ''}</div>`; }).join('') : '<div class="pg-note mu" style="margin:0">Aucun meuble monté à ce moment de la journée sur ce comptoir.</div>'}</div><div class="pg-arr ${idx < Z.length - 1 ? '' : 'off'}" data-zone="${idx < Z.length - 1 ? Z[idx + 1].id : ''}">›</div></div>
      <div class="pg-dots">${Z.map(x => `<i class="${x.id === z.id ? 'on' : ''}" data-zone="${x.id}"></i>`).join('')}</div>`;
    return h;
  }

  function tuileSlot(sl, grand) {
    const O = occupants(sl), o = O[0];
    if (!o) { return `<div class="pg-sl v" title="Emplacement ${sl.position} · libre"></div>`; }
    const u = photoRef(o.ref), nr = note('ref', o.ref);
    return `<div class="pg-sl ${u ? '' : 'pl'}" data-fiche="${sl.id}" style="${u ? 'background-image:url(' + esc(u) + ')' : ''}" title="${esc(o.nom)}${O.length > 1 ? ' + ' + (O.length - 1) : ''}"><span class="n">${esc(o.nom)}${O.length > 1 ? ' + ' + (O.length - 1) : ''}</span>${o.fronts > 1 ? `<span class="f">×${o.fronts}</span>` : ''}${nr && nr.texte ? '<span class="why" title="il y a une consigne">i</span>' : ''}</div>`;
  }

  // Niveau 2 : un meuble en grand.
  function rendMeuble() {
    const Z = zones(), z = Z.find(x => x.id === S.zone), m = z && (z.meubles || []).find(x => x.id === S.meuble);
    if (!m) { S.vue = 'zone'; return rendZone(); }
    const M = meublesDe(z), mi = M.findIndex(x => x.id === m.id), nm = note('meuble', m.id);
    const meta = [m.type, m.temperature, m.presentation, (m.periodes || []).length ? m.periodes.map(nomPeriode).join(' · ') : 'toute la journée'].filter(Boolean).join(' · ');
    let h = top(`<span class="crumb">${esc(z.nom)} ›</span> ${esc(m.nom)}`, esc(meta), true,
      `<span class="pg-ico ${mi > 0 ? '' : 'off'}" data-meuble="${mi > 0 ? M[mi - 1].id : ''}" title="meuble précédent">‹</span><span class="pg-ico ${mi < M.length - 1 ? '' : 'off'}" data-meuble="${mi < M.length - 1 ? M[mi + 1].id : ''}" title="meuble suivant">›</span>`);
    if (nm && nm.texte) { h += `<div class="pg-note"><b>Pourquoi ce meuble.</b> ${esc(nm.texte)}</div>`; }
    if (nm && nm.photo) { h += `<div class="pg-montage" style="margin-top:12px"><img src="../${esc(nm.photo)}" alt="" data-zoom="../${esc(nm.photo)}" data-cap="${esc(m.nom)} · photo de référence"><div class="t">La photo de référence du meuble<small>c’est à ça que le meuble doit ressembler une fois dressé</small></div></div>`; }
    h += `<div class="pg-vit">${(m.niveaux || []).map(n => { const nn = note('niveau', n.id); let np = 0; (n.slots || []).forEach(sl => { if (occupants(sl).length) { np++; } });
      return `<div class="pg-lv"><div class="lab"><b>${esc(n.nom)}</b>${np} / ${(n.slots || []).length} placés${nn && nn.texte ? `<span class="nn">${esc(nn.texte)}</span>` : ''}</div><div class="pg-row" style="grid-template-columns:${(n.slots || []).map(sl => Math.max(1, (occupants(sl)[0] || {}).fronts || 1) + 'fr').join(' ') || '1fr'}">${(n.slots || []).map(sl => tuileSlot(sl, true)).join('')}</div></div>`; }).join('')}</div>`;
    h += `<div class="pg-meta" style="margin-bottom:14px"><span><b>Lire la vitrine :</b> une tuile = un emplacement, sa largeur = le nombre de fronts</span><span class="mu">×2 = deux fronts · <span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:#C9A227;color:#fff;font:700 10px/14px var(--font-ui);text-align:center">i</span> = une consigne · hachuré = libre</span><span class="mu">toucher un produit : sa fiche et son pourquoi</span></div>`;
    return h;
  }

  // Niveau 3 : la fiche du produit, et pourquoi il est là.
  function rendFiche(slotId) {
    let sl = null, zn = null, mb = null, nv = null;
    zones().forEach(z => (z.meubles || []).forEach(m => (m.niveaux || []).forEach(n => (n.slots || []).forEach(s => { if (s.id === slotId) { sl = s; zn = z; mb = m; nv = n; } }))));
    if (!sl) { return ''; }
    const O = occupants(sl);
    const bloc = o => { const u = photoRef(o.ref), nr = note('ref', o.ref), nn = note('niveau', nv.id), nm = note('meuble', mb.id);
      const pos = `${esc(zn.nom)} › ${esc(mb.nom)} › ${esc(nv.nom)} · emplacement ${sl.position}`;
      return `<div class="b"><div class="ph ${u ? '' : 'v'}" style="${u ? 'background-image:url(' + esc(u) + ')' : ''}" ${u ? `data-zoom="${esc(u)}" data-cap="${esc(o.nom)}"` : ''}></div><div class="c"><h2>${esc(o.nom)}</h2><div class="ref">réf. ${esc(o.ref)} · ${pos}</div>
        <div class="kv"><b>Fronts</b><span>${o.fronts || 1}${o.cols && o.rangs ? ' · ' + o.cols + ' colonne(s) × ' + o.rangs + ' rang(s)' : ''}${o.parSlot ? ' · ' + o.parSlot + ' pièce(s) par emplacement' : ''}</span>
          <b>Moment</b><span>${(o.periodes || []).length ? o.periodes.map(nomPeriode).join(', ') : 'toute la journée'}</span>
          ${sl.format || sl.contenant ? `<b>Emplacement</b><span>${esc([sl.format, sl.contenant].filter(Boolean).join(' · '))}${sl.capacite ? ' · capacité ' + sl.capacite : ''}</span>` : ''}
          <b>Meuble</b><span>${esc([mb.type, mb.temperature, mb.presentation].filter(Boolean).join(' · ') || '—')}</span></div>
        ${nr && nr.texte ? `<div class="why"><b>Pourquoi ce produit ici</b>${esc(nr.texte)}</div>` : `<div class="why mu"><b>Pourquoi ce produit ici</b>aucune consigne posée sur ce produit — elle se pose dans le cockpit, sur la fiche du produit au planogramme.</div>`}
        ${nn && nn.texte ? `<div class="why"><b>Le niveau</b>${esc(nn.texte)}</div>` : ''}${nm && nm.texte ? `<div class="why"><b>Le meuble</b>${esc(nm.texte)}</div>` : ''}
        <button class="pg-btn x" data-fermer="1">Fermer</button></div></div>`; };
    return `<div class="pg-fiche" data-fermerx="1">${O.map(bloc).join('')}</div>`;
  }

  /* --- la photo du comptoir monté ------------------------------------------ */
  function prendrePhoto(zoneId) {
    const inp = document.createElement('input'); inp.type = 'file'; inp.accept = 'image/*'; inp.setAttribute('capture', 'environment');
    inp.addEventListener('change', () => {
      const f = inp.files && inp.files[0]; if (!f) { return; }
      // On réduit sur la tablette : 1600 px de large suffisent à juger un comptoir, et l'envoi reste léger.
      const img = new Image(); const url = URL.createObjectURL(f);
      img.onload = () => { const k = Math.min(1, 1600 / img.width); const cv = document.createElement('canvas'); cv.width = Math.round(img.width * k); cv.height = Math.round(img.height * k); cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height); URL.revokeObjectURL(url);
        const data = cv.toDataURL('image/jpeg', 0.82);
        S.envoi = true; S.err = null; rendre();
        ecrire('/planogramme/montage', { shop: S.shop, zoneId, date: AUJ, data, auteur: q.get('qui') || '' })
          .then(r => { S.montage[String(zoneId)] = { photo: r.photo, auteur: r.auteur, quand: r.quand }; })
          .catch(e => { S.err = 'Photo non enregistrée : ' + e.message; })
          .finally(() => { S.envoi = false; rendre(); }); };
      img.onerror = () => { S.err = 'Photo illisible.'; rendre(); };
      img.src = url;
    });
    inp.click();
  }

  /* --- les gestes --------------------------------------------------------- */
  function brancher() {
    $.querySelectorAll('[data-zone]').forEach(el => el.addEventListener('click', () => { const id = +el.dataset.zone; if (!id) { return; } S.zone = id; S.meuble = null; S.vue = 'zone'; window.scrollTo(0, 0); rendre(); }));
    $.querySelectorAll('[data-meuble]').forEach(el => el.addEventListener('click', e => { e.stopPropagation(); const id = +el.dataset.meuble; if (!id) { return; } S.meuble = id; S.vue = 'meuble'; window.scrollTo(0, 0); rendre(); }));
    $.querySelectorAll('[data-fiche]').forEach(el => el.addEventListener('click', e => { e.stopPropagation(); S.fiche = +el.dataset.fiche; rendre(); }));
    $.querySelectorAll('[data-fermer]').forEach(el => el.addEventListener('click', () => { S.fiche = null; rendre(); }));
    $.querySelectorAll('[data-fermerx]').forEach(el => el.addEventListener('click', e => { if (e.target === el) { S.fiche = null; rendre(); } }));
    $.querySelectorAll('[data-retour]').forEach(el => el.addEventListener('click', () => { if (S.vue === 'meuble') { S.vue = 'zone'; S.meuble = null; } else { S.vue = 'zones'; S.zone = null; } window.scrollTo(0, 0); rendre(); }));
    $.querySelectorAll('[data-periode]').forEach(el => el.addEventListener('click', () => { S.periode = el.dataset.periode || ''; rendre(); }));
    $.querySelectorAll('[data-recharger]').forEach(el => el.addEventListener('click', () => charger()));
    $.querySelectorAll('[data-monter]').forEach(el => el.addEventListener('click', () => prendrePhoto(+el.dataset.monter)));
    $.querySelectorAll('[data-demonter]').forEach(el => el.addEventListener('click', () => { const id = +el.dataset.demonter; if (!confirm('Retirer la photo du montage d’aujourd’hui ?')) { return; } ecrire('/planogramme/montage', { shop: S.shop, zoneId: id, date: AUJ, data: '' }).then(() => { delete S.montage[String(id)]; }).catch(e => { S.err = e.message; }).finally(rendre); }));
    $.querySelectorAll('[data-zoom]').forEach(el => el.addEventListener('click', e => { e.stopPropagation(); S.zoom = { url: el.dataset.zoom, cap: el.dataset.cap || '' }; rendre(); }));
    $.querySelectorAll('[data-zoomx]').forEach(el => el.addEventListener('click', () => { S.zoom = null; rendre(); }));
    // Balayer à gauche ou à droite change de comptoir.
    let x0 = null; $.ontouchstart = e => { x0 = e.touches[0].clientX; }; $.ontouchend = e => { if (x0 == null || S.vue !== 'zone' || S.fiche || S.zoom) { x0 = null; return; } const dx = e.changedTouches[0].clientX - x0; x0 = null; if (Math.abs(dx) < 70) { return; } const Z = zones(), i = Z.findIndex(z => z.id === S.zone); const j = dx < 0 ? i + 1 : i - 1; if (Z[j]) { S.zone = Z[j].id; S.meuble = null; window.scrollTo(0, 0); rendre(); } };
  }

  charger();
})();
