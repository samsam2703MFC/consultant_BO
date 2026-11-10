/* Maquettes « Lire une SOP sur la tablette » (07/11/2026) — ce qui est commun aux quatre propositions : les données
   à plat, l'en-tête, le pied, les icônes au trait, la navigation (cliquable : ‹ › , balayage, flèches du clavier).
   ?i=<n> : l'étape affichée (5 = « 5.1 Fours et programmes », celle de la capture du client) ; ?feuille=1 : le
   sommaire ouvert. */
(function () {
  'use strict';
  const D = window.SOP;
  const E = D.sections.flatMap((s, si) => s.etapes.map(e => Object.assign({ si, section: s.titre }, e)));
  const Q = new URLSearchParams(location.search);
  // Typographie française : jamais un « ou un » seul en bout de ligne, ni un « : » en début de ligne (espaces insécables).
  const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]))
    .replace(/« /g, '«\u00a0').replace(/ »/g, '\u00a0»').replace(/ ([:;?!])/g, '\u00a0$1');
  const svg = (d, t) => `<svg width="${t || 24}" height="${t || 24}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${d}</svg>`;
  const ICO = {
    retour: svg('<path d="M15 18l-6-6 6-6"/>'),
    droite: svg('<path d="M9 18l6-6-6-6"/>'),
    gauche: svg('<path d="M15 18l-6-6 6-6"/>'),
    alerte: svg('<path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01"/>', 26),
    info: svg('<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>', 26),
    tel: svg('<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>', 20),
    liste: svg('<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>', 22),
    check: svg('<path d="M20 6L9 17l-5-5"/>', 22),
    fermer: svg('<path d="M18 6L6 18M6 6l12 12"/>'),
  };
  const S = { i: Math.max(0, Math.min(E.length - 1, +(Q.get('i') || 5))), p: 0, feuille: Q.get('feuille') === '1' };

  function entete() {
    return `<header class="hd"><button class="hd-ret" aria-label="Retour à la liste">${ICO.retour}</button>
      <div class="hd-t"><b>${esc(D.titre)}</b><small>${esc(D.code)} · ${esc(D.version)}</small></div>
      <button class="hd-aide" data-a="aide">${ICO.tel}<span>Qui prévenir</span></button>
      <span class="hd-lg"><button class="on">FR</button><button>NL</button></span></header>`;
  }
  /** Le pied : « Précédente » et l'action principale (la dernière étape : « Contrôle final »). */
  function pied(o) {
    o = o || {};
    const fin = S.i === E.length - 1;
    return `<footer class="pd ${o.cls || ''}"><button class="bt" data-a="prec" ${S.i === 0 ? 'disabled' : ''} aria-label="Étape précédente">${ICO.gauche}<span class="bt-l">${o.prec || 'Précédente'}</span></button>
      ${o.milieu || ''}
      <button class="bt p" data-a="suiv">${fin ? 'Contrôle final ' : (o.suiv || 'C’est fait · suivante ')}${ICO.droite}</button></footer>`;
  }
  /** Les lignes d'une étape ; une étape critique = ses lignes dans le cadre rouge « Point critique ». */
  function lignes(e, cls) {
    const ul = `<ul class="${cls || 'li'}">${e.l.map(l => `<li>${esc(l)}</li>`).join('')}</ul>`;
    return e.crit ? `<div class="crit">${ICO.alerte}<div><b>Point critique</b>${ul}</div></div>` : ul;
  }
  /** « Qui prévenir » : un panneau, ouvert d'un toucher depuis n'importe quelle étape. */
  function aide() {
    return `<div class="voile" data-a="fermer"></div><aside class="panneau" role="dialog" aria-label="Qui prévenir">
      <div class="panneau-t"><b>Qui prévenir · en cas de doute</b><button class="hd-ret" data-a="fermer" aria-label="Fermer">${ICO.fermer}</button></div>
      ${D.contacts.map(c => `<div class="contact"><b>${esc(c[0])}</b><span>${esc(c[1])}</span></div>`).join('')}</aside>`;
  }

  let toucheX = null;
  function brancher(rendre) {
    const va = d => { S.i = Math.max(0, Math.min(E.length - 1, S.i + d)); S.p = 0; rendre(); };
    document.addEventListener('click', ev => {
      const b = ev.target.closest('[data-a],[data-i],[data-p]');
      if (!b) { return; }
      if (b.dataset.i !== undefined) { S.i = +b.dataset.i; S.p = 0; S.feuille = false; return rendre(); }
      if (b.dataset.p !== undefined) { S.p = +b.dataset.p; return rendre(); }
      const a = b.dataset.a;
      if (a === 'prec') { va(-1); } else if (a === 'suiv') { va(1); }
      else if (a === 'feuille') { S.feuille = true; rendre(); } else if (a === 'aide') { S.aide = true; rendre(); }
      else if (a === 'fermer') { S.feuille = false; S.aide = false; rendre(); }
    });
    document.addEventListener('keydown', ev => { if (ev.key === 'ArrowRight') { va(1); } else if (ev.key === 'ArrowLeft') { va(-1); } });
    document.addEventListener('touchstart', ev => { toucheX = ev.touches[0].clientX; }, { passive: true });
    document.addEventListener('touchend', ev => {
      if (toucheX === null) { return; }
      const dx = ev.changedTouches[0].clientX - toucheX; toucheX = null;
      if (Math.abs(dx) > 70) { va(dx < 0 ? 1 : -1); }
    }, { passive: true });
    rendre();
  }
  window.M = { D, E, S, esc, ICO, entete, pied, lignes, aide, brancher };
})();
