/* Maquettes « Paramètres dans une roue » : posées sur le vrai dashboard par capturer.cjs (window.PR.monter('a'|'b'|'c'|'c2')).
   Les états sont ceux de Sombreffe le 22/11/2026 (boîte pas encore reliée) ; le reste est illustratif. */
(function () {
  'use strict';
  const P = {
    mail: '<path d="M3 5h18v14H3zM3 6l9 7 9-7"/>',
    auto: '<path d="M9 14l-5-5 5-5M4 9h10a6 6 0 0 1 6 6v4"/>',
    modele: '<path d="M6 2h9l5 5v15H6zM14 2v6h6M9 13h7M9 17h7"/>',
    horloge: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    equipe: '<circle cx="9" cy="7" r="4"/><path d="M2 21v-1a6 6 0 0 1 12 0v1M16 3.5a4 4 0 0 1 0 7.5M22 21v-1a6 6 0 0 0-4-5.6"/>',
    cloche: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    bulle: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    roue: '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
    loupe: '<circle cx="11" cy="11" r="7"/><path d="M21 21l-5.2-5.2"/>'
  };
  const svg = k => `<svg viewBox="0 0 24 24" aria-hidden="true">${P[k]}</svg>`;
  const ic = k => `<span class="pr-ic">${svg(k)}</span>`;
  const et = e => e && e[1] ? `<span class="pr-et ${e[0]}">${e[1]}</span>` : '';
  // Tout ce qui se règle dans le dashboard, rangé : ce qui est aujourd'hui dans Boîte mail, dans Mails et automatisations,
  // dans le bouton « M'avertir » et dans le choix du ton de l'assistant.
  const R = [
    ['Boîte mail', [
      { k: 'boite', ic: 'mail', nom: 'La boîte du magasin', desc: 'l’adresse e-mail du magasin, reliée au dashboard', et: ['a', 'à relier'] },
      { k: 'auto', ic: 'auto', nom: 'Messages automatiques', desc: 'accusé de réception, relance d’une offre', et: ['off', 'après la boîte'] },
      { k: 'modeles', ic: 'modele', nom: 'Modèles d’e-mails', desc: 'vos textes prêts à envoyer', et: ['off', 'après la boîte'] }]],
    ['Automatisations', [
      { k: 'rappel', ic: 'horloge', nom: 'Rappel la veille du retrait', desc: 'un e-mail ou un SMS au client, la veille de sa commande', et: ['off', 'après la boîte'] },
      { k: 'equipe', ic: 'equipe', nom: 'L’équipe', desc: 'qui reçoit les e-mails du magasin', et: null }]],
    ['Cet appareil', [
      { k: 'alertes', ic: 'cloche', nom: 'Alertes de stock', desc: 'une notification quand une référence passe sous son minimum', et: ['off', 'désactivées'], sw: false }]],
    ['Assistant IA', [
      { k: 'ton', ic: 'bulle', nom: 'Ton de l’assistant', desc: 'qui vous répond quand vous l’ouvrez', et: ['', 'Mr. Pierre'], ton: 'Mr. Pierre' }]]
  ];

  /** Le rail sans le groupe « Paramètres », et la roue carrée à gauche de Mon coffre-fort. */
  function bas(ouvert) {
    document.querySelectorAll('.sidebar__group').forEach(g => { if (g.querySelector('[data-vue="mails"]')) { g.remove(); } });
    const c = document.querySelector('.db-rail-coffre');
    if (c && !c.querySelector('.pr-roue')) {
      c.classList.add('pr-bas');
      c.insertAdjacentHTML('afterbegin', `<button type="button" class="pr-roue" title="Paramètres" aria-label="Paramètres">${svg('roue')}</button>`);
    }
    const r = c && c.querySelector('.pr-roue'); if (r) { r.classList.toggle('on', !!ouvert); }
    return r;
  }
  function placer(el, roue, largeur) {
    const r = roue.getBoundingClientRect();
    el.style.left = r.left + 'px'; el.style.bottom = (window.innerHeight - r.top + 10) + 'px';
    if (largeur) { el.style.width = largeur + 'px'; }
    document.body.appendChild(el);
  }
  const div = (cls, h) => { const d = document.createElement('div'); d.className = cls; d.innerHTML = h; return d; };

  // A — le menu, à la largeur du rail
  function A(roue) {
    const bar = document.querySelector('.db-rail-coffre').getBoundingClientRect();
    const h = R.map(([g, L], i) => (i ? '<div class="sep"></div>' : '') + `<div class="pr-g">${g}</div>` + L.map(x =>
      `<div class="it${x.k === 'rappel' ? ' sel' : ''}">${ic(x.ic)}<span class="t">${x.nom}<small>${x.et && x.et[1] ? x.et[1] : x.desc}</small></span><span class="pr-chev">›</span></div>`).join('')).join('');
    const el = div('pra', h); placer(el, roue, bar.width - 24);
  }
  // B — la feuille : la recherche, la description, l'état à droite ; la page voilée
  function B(roue) {
    document.body.appendChild(div('prb-voile', ''));
    const h = `<div class="hd"><div><h3>Paramètres</h3><small>Atelier by Harmonie - Sombreffe · ce qui se règle dans le dashboard</small></div><span class="x">×</span></div>
      <div class="q">${svg('loupe')}<span>Chercher un réglage…</span><kbd>/</kbd></div>
      <div class="lst">${R.map(([g, L]) => `<div class="pr-g">${g}</div>` + L.map((x, i) =>
        `<div class="it${x.k === 'boite' ? ' sel' : ''}">${ic(x.ic)}<span class="t"><b>${x.nom}</b><small>${x.desc}</small></span>${et(x.et)}<span class="pr-chev">›</span></div>`).join('')).join('')}</div>
      <div class="pied">Le budget, les objectifs et les prix se règlent au cockpit, avec votre consultant.</div>`;
    placer(div('prb', h), roue);
  }
  // C — deux niveaux : la liste avec ses commandes rapides, puis un réglage ouvert dans la même fenêtre
  function C(roue, detail) {
    let h;
    if (!detail) {
      h = `<div class="hd">Paramètres<span class="x">×</span></div><div class="lst">${R.map(([g, L]) => `<div class="pr-g">${g}</div>` + L.map(x => {
        const droite = x.sw != null ? `<span class="sw${x.sw ? ' on' : ''}"></span>` : (x.ton ? `<span class="sel-ton">${x.ton} ▾</span>` : `${et(x.et)}<span class="pr-chev">›</span>`);
        return `<div class="it${x.k === 'rappel' ? ' sel' : ''}">${ic(x.ic)}<span class="t"><b>${x.nom}</b><small>${x.desc}</small></span>${droite}</div>`;
      }).join('')).join('')}</div>`;
    } else {
      h = `<div class="hd"><span class="ret">‹ Paramètres</span><span class="x">×</span></div><div class="det">
        <h4>Rappel la veille du retrait</h4>
        <p>Chaque commande client à retirer demain : son client reçoit un e-mail (la boîte du magasin) et/ou un SMS, à l’heure choisie.</p>
        <div class="avis">${svg('mail').replace('<svg', '<svg width="18" height="18" style="flex:0 0 18px;fill:none;stroke:currentColor;stroke-width:1.7"')}<span>La boîte du magasin n’est pas encore reliée : le rappel partira dès qu’elle le sera. <b>Relier la boîte ›</b></span></div>
        <div class="ch dis"><span>Rappel actif<small>pour toutes les commandes à retirer</small></span><span class="sw"></span></div>
        <div class="ch dis"><span>Envoyer par</span><span class="seg"><span class="on">E-mail</span><span>SMS</span><span>Les deux</span></span></div>
        <div class="ch dis"><span>À quelle heure<small>la veille du retrait</small></span><span class="heure">18:00</span></div>
        <div class="bts"><span class="bt">Essayer sur mon adresse</span><span class="bt prim">Ouvrir la page complète</span></div></div>`;
    }
    placer(div('prc', h), roue);
  }
  window.PR = {
    monter(v) {
      document.querySelectorAll('.pra,.prb,.prb-voile,.prc').forEach(x => x.remove());
      const roue = bas(v && v !== 'ferme'); if (!roue) { return 'pas de rail'; }
      if (v === 'a') { A(roue); } else if (v === 'b') { B(roue); } else if (v === 'c') { C(roue, false); } else if (v === 'c2') { C(roue, true); }
      return 'ok';
    }
  };
})();
