/* Recadrer une photo avant de l'envoyer (06/11/2026, client : « dans l'éditeur, je dois pouvoir cropper et avoir un ratio
 * fixe pour les photos importées »). Une fenêtre : le cadre au format FIXE 4:3 (celui de la tablette), la photo le
 * remplit toujours ; on la glisse (souris, doigt) pour la placer, on zoome avec le curseur, la molette ou deux doigts ;
 * « Utiliser cette photo » rend une image JPEG de 1600 × 1200 au plus (jamais agrandie au-delà de la photo d'origine).
 * Échap = Annuler, Entrée = Utiliser, les flèches déplacent la photo.
 *
 *   window.FormationRecadrer.ouvrir(fichier) → Promise<dataURL | null>   (null : annulé)
 *
 * Utilisé par l'assistant de création des SOP (assistant-sop.js) et l'éditeur complet (formation-sop.js). */
(function () {
  'use strict';
  const RATIO = 4 / 3, SORTIE_L = 1600, QUALITE = 0.85, ZOOM_MAX = 5;

  function charger(fichier) {
    return new Promise((ok, ko) => {
      const url = URL.createObjectURL(fichier), im = new Image();
      im.onload = () => ok({ im, url });
      im.onerror = () => { URL.revokeObjectURL(url); ko(new Error('photo illisible')); };
      im.src = url;
    });
  }

  async function ouvrir(fichier) {
    const { im, url } = await charger(fichier);
    return new Promise(fin => {
      const precedent = document.activeElement;
      const voile = document.createElement('div');
      voile.className = 'confirm rc';
      voile.setAttribute('role', 'dialog');
      voile.setAttribute('aria-modal', 'true');
      voile.setAttribute('aria-label', 'Recadrer la photo');
      voile.innerHTML = `<div class="confirm__box card rc-box">
        <div class="rc-tete"><h3>Recadrer la photo</h3><span class="rc-format">Format 4:3 · celui de la tablette · <b class="rc-taille"></b></span></div>
        <div class="rc-cadre" tabindex="0" aria-label="Photo à placer : glisser, ou flèches du clavier"><img alt="" draggable="false"><i class="rc-grille" aria-hidden="true"></i></div>
        <div class="rc-zoom"><span aria-hidden="true">−</span><input type="range" min="1" max="${ZOOM_MAX}" step="0.01" value="1" aria-label="Zoom"><span aria-hidden="true">＋</span>
          <button type="button" class="btn btn--sm" data-rc="centrer">Recentrer</button></div>
        <div class="rc-actions"><button type="button" class="btn" data-rc="annuler">Annuler</button><button type="button" class="btn btn--primary" data-rc="ok">Utiliser cette photo</button></div></div>`;
      document.body.appendChild(voile);
      const cadre = voile.querySelector('.rc-cadre'), img = cadre.querySelector('img'), curseur = voile.querySelector('input[type=range]');
      const taille = voile.querySelector('.rc-taille');
      img.src = url;
      const nw = im.naturalWidth, nh = im.naturalHeight;
      // W × H : le cadre affiché ; base : l'échelle qui fait tout juste remplir le cadre ; z : le zoom (≥ 1) ;
      // x, y : le coin haut-gauche de la photo dans le cadre (toujours ≤ 0 : la photo couvre le cadre).
      let W = 0, H = 0, base = 1, z = 1, x = 0, y = 0;
      const echelle = () => base * z;
      function borner() { const s = echelle(); x = Math.min(0, Math.max(W - nw * s, x)); y = Math.min(0, Math.max(H - nh * s, y)); }
      function poser() {
        const s = echelle();
        img.style.width = nw * s + 'px'; img.style.height = nh * s + 'px';
        img.style.transform = `translate(${x}px, ${y}px)`;
        curseur.value = String(z);
        // La taille du résultat ; sous 800 px de large, la photo sera floue sur la tablette : on le dit.
        const l = Math.max(1, Math.min(SORTIE_L, Math.round(W / s)));
        taille.textContent = W ? `résultat ${l} × ${Math.round(l / RATIO)} px` + (l < 800 ? ' · photo de faible résolution' : '') : '';
        taille.classList.toggle('is-faible', W > 0 && l < 800);
      }
      /** Mesure le cadre ; garde le même point de la photo au centre (au redimensionnement), sinon centre la photo. */
      function mesurer(garder) {
        const s0 = echelle();
        const cx = garder && W ? (W / 2 - x) / s0 : nw / 2, cy = garder && H ? (H / 2 - y) / s0 : nh / 2;
        const r = cadre.getBoundingClientRect();
        W = r.width; H = r.height; base = Math.max(W / nw, H / nh);
        const s = echelle(); x = W / 2 - cx * s; y = H / 2 - cy * s;
        borner(); poser();
      }
      /** Zoome en gardant fixe le point (px, py) du cadre. */
      function zoomer(nz, px, py) {
        nz = Math.min(ZOOM_MAX, Math.max(1, nz));
        const s0 = echelle(), ix = (px - x) / s0, iy = (py - y) / s0;
        z = nz; const s = echelle(); x = px - ix * s; y = py - iy * s;
        borner(); poser();
      }
      function fermer(resultat) {
        window.removeEventListener('resize', auRedim);
        document.removeEventListener('keydown', auClavier, true);
        voile.remove(); URL.revokeObjectURL(url);
        if (precedent && precedent.focus) { try { precedent.focus(); } catch (e) { /* l'élément a pu disparaître */ } }
        fin(resultat);
      }
      function utiliser() {
        const s = echelle();
        const sx = -x / s, sy = -y / s, sw = W / s, sh = H / s;
        const l = Math.max(1, Math.min(SORTIE_L, Math.round(sw))), h = Math.round(l / RATIO);
        const c = document.createElement('canvas'); c.width = l; c.height = h;
        const g = c.getContext('2d');
        g.fillStyle = '#fff'; g.fillRect(0, 0, l, h);
        g.imageSmoothingQuality = 'high';
        g.drawImage(im, sx, sy, sw, sh, 0, 0, l, h);
        fermer(c.toDataURL('image/jpeg', QUALITE));
      }

      // Glisser (souris ou un doigt) ; deux doigts = zoom autour de leur milieu.
      const points = new Map();
      let depart = null, pincement = null;
      cadre.addEventListener('pointerdown', ev => {
        cadre.setPointerCapture(ev.pointerId);
        points.set(ev.pointerId, { x: ev.clientX, y: ev.clientY });
        if (points.size === 1) { depart = { px: ev.clientX, py: ev.clientY, x, y }; cadre.classList.add('is-glisse'); }
        if (points.size === 2) {
          const [a, b] = [...points.values()], r = cadre.getBoundingClientRect();
          pincement = { d: Math.hypot(a.x - b.x, a.y - b.y) || 1, z, mx: (a.x + b.x) / 2 - r.left, my: (a.y + b.y) / 2 - r.top };
          depart = null;
        }
      });
      cadre.addEventListener('pointermove', ev => {
        if (!points.has(ev.pointerId)) { return; }
        points.set(ev.pointerId, { x: ev.clientX, y: ev.clientY });
        if (pincement && points.size >= 2) {
          const [a, b] = [...points.values()];
          return zoomer(pincement.z * Math.hypot(a.x - b.x, a.y - b.y) / pincement.d, pincement.mx, pincement.my);
        }
        if (depart) { x = depart.x + ev.clientX - depart.px; y = depart.y + ev.clientY - depart.py; borner(); poser(); }
      });
      const lacher = ev => {
        points.delete(ev.pointerId);
        if (points.size < 2) { pincement = null; }
        if (points.size === 1) { const [p] = [...points.values()]; depart = { px: p.x, py: p.y, x, y }; }
        if (!points.size) { depart = null; cadre.classList.remove('is-glisse'); }
      };
      cadre.addEventListener('pointerup', lacher);
      cadre.addEventListener('pointercancel', lacher);
      cadre.addEventListener('wheel', ev => {
        ev.preventDefault();
        const r = cadre.getBoundingClientRect();
        zoomer(z * (ev.deltaY < 0 ? 1.08 : 1 / 1.08), ev.clientX - r.left, ev.clientY - r.top);
      }, { passive: false });
      curseur.addEventListener('input', () => zoomer(+curseur.value, W / 2, H / 2));
      voile.addEventListener('click', ev => {
        const b = ev.target.closest('[data-rc]');
        if (!b) { return; }
        if (b.dataset.rc === 'annuler') { fermer(null); }
        else if (b.dataset.rc === 'ok') { utiliser(); }
        else if (b.dataset.rc === 'centrer') { z = 1; W = 0; mesurer(false); }
      });
      function auClavier(ev) {
        if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); return fermer(null); }
        if (ev.key === 'Enter' && !ev.target.closest('button')) { ev.preventDefault(); return utiliser(); }
        const pas = ev.shiftKey ? 40 : 10;
        const d = { ArrowLeft: [pas, 0], ArrowRight: [-pas, 0], ArrowUp: [0, pas], ArrowDown: [0, -pas] }[ev.key];
        if (d && ev.target === cadre) { ev.preventDefault(); x += d[0]; y += d[1]; borner(); poser(); }
      }
      function auRedim() { mesurer(true); }
      window.addEventListener('resize', auRedim);
      document.addEventListener('keydown', auClavier, true);
      requestAnimationFrame(() => { mesurer(false); cadre.focus(); });
    });
  }

  window.FormationRecadrer = { ouvrir, RATIO };
})();
