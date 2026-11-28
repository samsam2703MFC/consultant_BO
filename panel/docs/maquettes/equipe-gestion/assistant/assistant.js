/* L'assistant de la semaine (maquette, 23/11/2026, Sam : « en faire un assistant pour les horaires de la semaine
   (reprendre semaine passée oui ou non), qui adapter ?, le chiffre d'affaires planifié est de … euros, tu as xxx heures
   de trop »). Posé par capturer.cjs sur le vrai écran Semaine : window.EQA.monter(1 | 2 | 3).
   Données ILLUSTRATIVES (../ecran/panel_illustratif.php) ; les chiffres sont ceux du calcul du module : la semaine du
   23 novembre reprise pour celle du 30, 24 663 € de CA planifié (l'objectif, comptoir + B2B), cible 17 %. */
(function () {
  'use strict';
  const m = (v, niv, cl) => `<span class="eqp-m eqp-m-${niv}${cl ? ' ' + cl : ''}">${v}</span>`;
  const JOURS = ['Lun 30', 'Mar 1', 'Mer 2', 'Jeu 3', 'Ven 4', 'Sam 5', 'Dim 6'];
  const etapes = ici => {
    const E = [['Partir de quoi ?', 'la semaine passée, la matrice, rien'], ['Qui adapter ?', 'absents, à revoir'], ['Le chiffre', 'le CA planifié, les heures de trop'], ['Vérifier et créer', 'le comptoir, les durées, le panel']];
    const res = [['Semaine passée reprise', '41 services · 301,5 h'], ['Jonas absent', 'Chloé à revoir'], null, null];
    return `<aside class="eqa-rail"><h2>Assistant de la semaine</h2><span class="sub">du 30 novembre au 6 décembre</span>${E.map(([t, s], i) => {
      const cl = i + 1 < ici ? 'fait' : i + 1 === ici ? 'ici' : 'apres';
      return `<div class="eqa-et ${cl}"><i>${i + 1 < ici ? '✓' : i + 1}</i><b>${t}</b><small>${i + 1 < ici && res[i] ? res[i].join(' · ') : s}</small></div>`;
    }).join('')}</aside>`;
  };
  const pied = (res, retour, suite) => `<div class="eqa-pied"><span class="res">${res}</span>${retour ? '<button type="button" class="btn btn--ghost">Retour</button>' : '<button type="button" class="btn btn--ghost">Fermer</button>'}<button type="button" class="btn btn--primary">${suite}</button></div>`;
  const etiq = t => `<div class="eqa-etiq">Assistant de la semaine · <b>${t}</b></div>`;

  /* 1 — Partir de quoi ? Reprendre la semaine passée (oui), la matrice A B C D (non), ou une semaine vide (non). */
  function une() {
    const jours = [['46 h', '26,3 %', 'noir'], ['46 h', '26,7 %', 'noir'], ['54 h', '26,5 %', 'noir'], ['38 h', '21,5 %', 'rouge'], ['44 h', '19,3 %', 'orange'], ['48,5 h', '17,3 %', 'orange'], ['25 h', '13,6 %', 'ok']];
    return `<div class="eqa-voile"><div class="eqa" role="dialog" aria-label="Assistant de la semaine">${etapes(1)}<div class="eqa-main">
      <p class="eqa-q">Reprendre la semaine passée ?</p><p class="eqa-qs">La semaine du 30 novembre est vide. L’assistant part d’une base, puis vous aide à l’ajuster au chiffre prévu.</p>
      <div class="eqa-choix">
        <div class="eqa-c on"><span class="eqa-r"></span><span class="t">Oui, reprendre la semaine du 23 au 29 novembre</span><span class="k">41 services · 301,5 h · 9 personnes</span>
          <span class="d">Chacun garde ses jours et ses heures, sept jours plus tard. Rien n’est écrit au panel avant la dernière étape.</span>
          <div class="eqa-jours">${jours.map(([h, p, n], i) => `<div>${JOURS[i]}<b>${h}</b>${m(p, n)}</div>`).join('')}</div></div>
        <div class="eqa-c"><span class="eqa-r"></span><span class="t">Non, partir de la matrice A B C D</span><span class="k">20 services · 130 h</span>
          <span class="d">Le shift maker : la semaine 2 du roulement, matin ou après-midi selon la lettre de chacun. Ceux qui n’ont pas de lettre (la production) sont à placer ensuite.</span></div>
        <div class="eqa-c"><span class="eqa-r"></span><span class="t">Non, partir d’une semaine vide</span><span class="k">0 service</span>
          <span class="d">Vous placez chacun vous-même, jour par jour, personne par personne, en tirant sa ligne.</span></div>
      </div></div>
      ${pied('Le CA planifié de la semaine : <b>24 663 €</b> (l’objectif, comptoir + B2B)', false, 'Continuer ›')}</div></div>${etiq('1 · reprendre la semaine passée ?')}`;
  }

  /* 2 — Qui adapter ? Chacun avec sa semaine reprise : comme la semaine passée, absent (congé, maladie), à revoir. */
  function deux() {
    const P = [['AD', 'Amélie Dubois', 'Responsable · D', ['m8', 'm8', 'm8', '', 'm8', 'm8', ''], 40, 'c'], ['BL', 'Bastien Lambert', 'Boulanger', ['m8', 'm8', 'm8', 'm8', 'm6', '', ''], 38, 'c'],
      ['CP', 'Chloé Peeters', 'Vente · A', ['a6', 'a6', '', 'a6', '', 'm7', 'm6'], 31, 'r'], ['DJ', 'David Janssens', 'Boulanger', ['', '', '', 'm8', 'm8', 'm8', 'm7'], 31, 'c'],
      ['EM', 'Elise Maes', 'Pâtissier', ['m8', 'm8', 'm8', 'm8', '', 'm8', ''], 40, 'c'], ['FE', 'Fatima El Amrani', 'Vente · C', ['', 'm8', 'm8', 'm8', 'm8', 'm4', ''], 36, 'c'],
      ['JW', 'Jonas Willems', 'sans poste', ['m8', '', 'm8', '', 'm8', '', ''], 24, 'a'], ['LC', 'Laura Claes', 'Vente · B', ['m8', '', 'a6', '', 'a6', 'a5,5', 'a5'], 30.5, 'c'],
      ['ML', 'Marc Lefèvre', 'Tourier', ['', 'm8', 'm8', '', '', 'm8', 'm7'], 31, 'c']];
    const ligne = ([av, nom, sous, js, h, etat]) => `<div class="eqa-p${etat === 'a' ? ' abs' : ''}"><span class="eqp-av">${av}</span><span class="nm"><b>${nom}</b><small>${sous} · ${String(h).replace('.', ',')} h la semaine passée</small></span>
      <div class="eqa-sem">${js.map(x => `<span class="${x ? x[0] : ''}">${x ? x.slice(1) + ' h' : '—'}</span>`).join('')}</div>
      <span class="eqa-seg"><span class="${etat === 'c' ? 'on' : ''}">Comme la semaine passée</span><span class="${etat === 'a' ? 'on abs' : ''}">Absent·e</span><span class="${etat === 'r' ? 'on rev' : ''}">À revoir</span></span></div>`;
    return `<div class="eqa-voile"><div class="eqa" role="dialog" aria-label="Assistant de la semaine">${etapes(2)}<div class="eqa-main">
      <p class="eqa-q">Qui adapter ?</p><p class="eqa-qs">La semaine reprise, personne par personne. Un absent (congé, maladie) n’est pas recopié ; « à revoir » s’ouvre à la dernière étape, dans la fenêtre du service, pour placer ses jours à la main.</p>
      <div class="eqa-pers"><div class="eqa-tete"><span></span><span>Personne</span><span class="j">${JOURS.map(j => `<span>${j}</span>`).join('')}</span><span>Cette semaine</span></div>${P.map(ligne).join('')}</div>
      <p class="eqp-note" style="margin-top:10px">Beige : le matin ; rose : l’après-midi. Les heures sont celles de la semaine passée, reportées de sept jours.</p></div>
      ${pied('<b>Jonas</b> absent (−24 h) · <b>Chloé</b> à revoir · 277,5 h reprises', true, 'Continuer ›')}</div></div>${etiq('2 · qui adapter ?')}`;
  }

  /* 3 — Le chiffre : le CA planifié, le budget à 17 %, les heures de trop ; des propositions chiffrées, à cocher. */
  function trois() {
    const props = [
      [true, 'Amélie : finir à 13:00 au lieu de 15:00, du lundi au mercredi', 'La pâtisserie reste tenue par Elise jusqu’à 14:00 ; le creux habituel de 14:10 à 15:00 n’a plus deux personnes en production.', '−6 h', '−126 €'],
      [true, 'Elise : 06:00–12:00 au lieu de 06:00–14:00, du lundi au jeudi', 'Amélie couvre la pâtisserie de 07:00 à 13:00. Les services restent entre 4 h et 8 h.', '−8 h', '−151 €'],
      [true, 'Mercredi : retirer le service de Marc (04:00–12:00)', 'Le jour le plus lourd (54 h pour 3 464 €). Bastien tient la boulangerie et la viennoiserie de 04:00 à 12:00.', '−8 h', '−143 €'],
      [false, 'Fatima : commencer à 11:00 au lieu de 10:00, du mardi au vendredi', 'Le pic du comptoir commence vers 11 h ; Laura est là dès 12:30. Le comptoir reste couvert.', '−4 h', '−63 €'],
      [false, 'Bastien : vendredi 04:00–10:00 → retiré', 'David est en boulangerie le vendredi de 04:00 à 12:00.', '−6 h', '−117 €']];
    const jours = [['Lun 30', 34, 27.7], ['Mar 1', 42, 29.7], ['Mer 2', 34, 32.6], ['Jeu 3', 36, 29.7], ['Ven 4', 36, 35.6], ['Sam 5', 48.5, 47.5], ['Dim 6', 25, 29.7]];
    const max = 56, w = v => (v / max * 100).toFixed(1) + '%';
    const lj = ([j, h, b]) => { const ec = h - b, cl = ec > 1 ? 'color:#C0281F' : 'color:var(--green)'; return `<div class="eqa-jl"><span>${j}</span><span class="barre"><i style="width:${w(h)}"></i><em style="left:${w(b)}" title="budget ${String(b).replace('.', ',')} h"></em></span><span class="v" style="${cl}">${ec > 0 ? '+' : '−'}${String(Math.abs(Math.round(ec * 2) / 2)).replace('.', ',')} h<small>${String(h).replace('.', ',')} / ${String(Math.round(b)).replace('.', ',')} h</small></span></div>`; };
    return `<div class="eqa-voile"><div class="eqa" role="dialog" aria-label="Assistant de la semaine">${etapes(3)}<div class="eqa-main">
      <div class="eqa-msg"><span class="big">45 h<small>de trop</small></span><p>Le chiffre d’affaires planifié est de <b>24 663 €</b> (comptoir + B2B). À 17 %, le personnel peut coûter <b>4 193 €</b>, soit <b>232 h</b> au coût moyen de l’équipe (18,04 €/h). La semaine reprise en compte <b>277,5 h</b> pour 5 006 € : ${m('20,3 %', 'rouge')} &nbsp;— il y a <b>45 h de trop</b>.</p></div>
      <div class="eqa-cols"><div><p class="eqa-h">Où les retirer · des propositions, à cocher</p>
        ${props.map(([on, t, d, h, e]) => `<div class="eqa-prop${on ? ' on' : ''}"><span class="eqa-cb"></span><span class="t">${t}</span><span class="g">${h}<small>${e}</small></span><span class="d">${d}</span></div>`).join('')}
        <div class="eqa-bilan">Avec les <b>3</b> propositions cochées : <b>−22 h</b> · <b>−420 €</b> → ${m('18,6 %', 'orange')} · il reste <b>22 h</b> de trop</div></div>
      <div><p class="eqa-h">Jour par jour · heures et budget à 17 %</p><div class="eqa-jt">${jours.map(lj).join('')}</div>
        <p class="eqp-note" style="margin-top:8px">La barre : les heures après les propositions cochées ; le trait vert : le budget du jour à 17 %. Du vendredi au dimanche, la semaine tient son budget ; le trop est du lundi au jeudi.</p></div></div></div>
      ${pied('277,5 h → <b>255,5 h</b> · 20,3 % → <b>18,6 %</b>', true, 'Appliquer et vérifier ›')}</div></div>${etiq('3 · le chiffre, les heures de trop')}`;
  }

  window.EQA = {
    monter(n) {
      document.querySelectorAll('.eqa-voile,.eqa-etiq').forEach(x => x.remove());
      const d = document.createElement('div'); d.innerHTML = [null, une, deux, trois][n]();
      [...d.children].forEach(x => document.body.appendChild(x));
    },
  };
})();
