/* Maquettes « Mon coffre-fort » v2 (22/11/2026) : les pièces communes des écrans (en-tête, état du coffre, frise des
   grandes dates, dossiers, lignes). Chaque page appelle V.monter({...}) ; les données sont celles de Halle, une fois
   le coffre rempli (sauf l'écran 01, Halle aujourd'hui). */
(function () {
  'use strict';
  const V = {};
  /* Les dossiers et leurs pièces attendues (la liste de contrôle : une proposition de ces maquettes). */
  V.dossiers = [
    { k: 'tout', ico: '🗂️', nom: 'Tout', n: 18 },
    { k: 'contrat', ico: '📜', nom: 'Contrat & DIP', qui: 'par votre consultant', n: 4, att: [3, 4] },
    { k: 'legal', ico: '⚖️', nom: 'Légal', qui: 'par vous', n: 5, att: [4, 7] },
    { k: 'formation', ico: '🎓', nom: 'Formation', qui: 'automatique', n: 3, att: [2, 2] },
    { k: 'visites', ico: '📝', nom: 'Rapports de visite', qui: 'automatique', n: 6 },
    { k: 'finances', ico: '📊', nom: 'Finances', qui: 'par votre consultant', n: 3, att: [2, 3] },
    { k: 'courriers', ico: '✉️', nom: 'Courriers & réunions', qui: 'libre', n: 3 },
  ];
  V.tete = (o) => `<div class="v-tete"><div><div class="kk">Boutique de Halle · un coffre par boutique</div><h1>Mon coffre-fort</h1>
      <p>🔒 Vos documents et ceux de Nadia K., votre consultante. Chaque ouverture est notée.</p></div><span class="sp"></span>
      <span class="v-q">🔍 Chercher un document…</span>
      <span class="v-b abr">📅 Grandes dates${o && o.gd ? `<i>${o.gd}</i>` : ''}</span>
      <span class="v-b prim">＋ Déposer un document</span></div>`;
  V.ring = (a, b, taille) => {
    const r = 38, c = 2 * Math.PI * r, p = b ? a / b : 0;
    return `<div class="v-ring"${taille ? ` style="width:${taille}px;height:${taille}px"` : ''}><svg viewBox="0 0 92 92"><circle cx="46" cy="46" r="${r}" fill="none" stroke="#EFE9DF" stroke-width="9"/>
      ${a ? `<circle cx="46" cy="46" r="${r}" fill="none" stroke="#3F7A52" stroke-width="9" stroke-linecap="round" stroke-dasharray="${(c * p).toFixed(1)} ${c.toFixed(1)}"/>` : ''}</svg><b>${a}<small>sur ${b}</small></b></div>`;
  };
  V.etat = (o) => `<section class="v-etat">
      <div><div class="v-k">Votre coffre</div><div class="v-compl">${V.ring(o.a, o.b)}<div><h3>${o.titre}</h3><p>${o.texte}</p>${o.lien ? `<a>${o.lien} ›</a>` : ''}</div></div></div>
      <div><div class="v-k">À traiter <i>${o.afaire.length ? o.afaire.length + ' point' + (o.afaire.length > 1 ? 's' : '') : ''}</i></div>
        ${o.afaire.length ? o.afaire.map(x => `<div class="v-af"><span class="pt ${x.c}">${x.i}</span><span class="tx">${x.t}<small>${x.s}</small></span><span class="go">${x.go} ›</span></div>`).join('') : `<div class="v-af rien">${o.rien}</div>`}</div>
      <div class="v-proch">${o.proch ? `<div class="k2">Prochaine grande date</div><div class="dt"><b>${o.proch.j}</b><span>${o.proch.m}</span></div><p>${o.proch.t}</p><span class="u">${o.proch.u}</span><br><span class="v-b sm abr">📅 Toutes les grandes dates</span>`
        : `<div class="k2">Grandes dates</div><p style="margin-top:10px">Les échéances de vos documents apparaîtront ici : fins de triennat du bail, préavis, renouvellements, assurances.</p><p style="font-size:12px;color:var(--muted)">L’assistant les relève quand un document est déposé.</p>`}</div>
    </section>`;
  /* La frise des 12 mois, de novembre 2026 à octobre 2027 (aujourd'hui : 22/11/2026). */
  V.frise = () => {
    const pct = j => (j / 365 * 100).toFixed(1) + '%';
    const mois = ['nov.', 'déc.', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.'];
    const debuts = [0, 30, 61, 92, 120, 151, 181, 212, 242, 273, 304, 334];
    const ev = [[30, 'or', 'Renouvellement du bail · fin de la fenêtre'], [60, 'or bas', 'Assurance RC'], [103, '', 'Autorisation AFSCA'], [120, 'ab bas', 'Indexation du loyer'], [226, '', 'Contrôle des extincteurs'], [303, 'ab bas', 'Budget 2028 à déposer']];
    return `<section class="v-frise"><div class="v-k">Les grandes dates des 12 prochains mois <i>triennats du bail, préavis, renouvellements, échéances</i></div>
      <div class="v-fr"><div class="axe"></div><div class="fen" style="left:0;width:${pct(30)}"></div>
        ${debuts.map((d, i) => `<span class="tick" style="left:${pct(d)}"></span><span class="m" style="left:${pct(d + 15)}">${mois[i]}</span>`).join('')}
        <div class="auj" style="left:${pct(21)}"></div>
        ${ev.map(([j, c, t]) => `<div class="ev ${c}" style="left:${pct(j)}"><i></i><span>${t}</span></div>`).join('')}</div></section>`;
  };
  V.nav = (on) => `<nav class="v-dos">${V.dossiers.map(d => `<div class="v-d${d.k === on ? ' on' : ''}"><span class="ico">${d.ico}</span><span class="nm">${d.nom}${d.qui ? `<small>${d.qui}${d.att ? ' · ' + d.att[0] + '/' + d.att[1] + ' pièces' : ''}</small>` : ''}${d.att ? `<span class="bar"><b style="width:${Math.round(d.att[0] / d.att[1] * 100)}%"></b></span>` : ''}</span><span class="n">${d.n}</span></div>${d.k === 'tout' ? '<div class="v-sep"></div>' : ''}`).join('')}
      <div class="v-d gd"><span class="ico">📅</span><span class="nm">Grandes dates<small>7 à venir · 3 dans les 4 mois</small></span><span class="n">7</span></div></nav>`;
  V.ligne = (x) => `<div class="v-l${x.sel ? ' sel' : ''}"><span class="v-ty ${x.ty}">${x.ty === 'web' ? 'WEB' : x.ty.toUpperCase()}</span>
      <span class="tt"><b>${x.t}${x.nv ? '<em class="v-nv">nouveau</em>' : ''}</b><small>${x.s}</small></span>
      ${x.dos ? `<span class="dos">${x.dos}</span>` : ''}<span class="qd">${x.q}</span><span class="et">${x.et}</span><span class="ac">${x.ac || 'Ouvrir'}</span></div>`;
  V.monter = (html) => { document.getElementById('page').insertAdjacentHTML('afterbegin', html); };
  window.V = V;
})();
