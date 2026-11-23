/* Trois propositions pour la fenêtre d'un service (23/11/2026, « retravailler le formulaire, fais-moi 3 propositions »).
   Posées par capturer.cjs sur le vrai écran Semaine (données ILLUSTRATIVES de ../ecran/panel_illustratif.php) :
   window.EQF.monter(1 | 2 | 3). Le même cas pour les trois : ajouter Chloé Peeters (Vente, lettre A, 15,80 €/h) le
   mercredi 25 novembre, où le comptoir est vide de 7 h à 10 h. Les chiffres sont ceux du calcul du module sur ces
   données : budget du mercredi 25,5 % → 28,6 % (+111 €), de la semaine 20,6 % → 21,0 %, Chloé 31 h → 38 h. */
(function () {
  'use strict';
  const H0 = 4 * 60, H1 = 20 * 60;
  const X = m => ((m - H0) / (H1 - H0) * 100).toFixed(2) + '%', L = (a, b) => ((b - a) / (H1 - H0) * 100).toFixed(2) + '%';
  const mn = t => +t.slice(0, 2) * 60 + +t.slice(3);
  const OUV = ['07:00', '18:30'];
  // Le mercredi 25 : qui est là (le planning illustratif), et le comptoir (les postes de vente : Fatima, Laura).
  const JOUR = [['Bastien Lambert', '04:00', '12:00'], ['Marc Lefèvre', '04:00', '12:00'], ['Elise Maes', '06:00', '14:00'], ['Amélie Dubois', '07:00', '15:00'],
    ['Jonas Willems', '08:00', '16:00'], ['Fatima El Amrani', '10:00', '18:00'], ['Laura Claes', '12:30', '18:30']];
  const m = (v, niv) => `<span class="eqp-m eqp-m-${niv} eqf-num">${v}</span>`;
  const axe = () => `<div class="eqf-axe">${[4, 8, 12, 16, 20].map(h => `<i style="left:${X(h * 60)}">${h} h</i>`).join('')}</div>`;
  const piste = inner => `<div class="eqf-piste"><span class="eqf-ouv" style="left:${X(mn(OUV[0]))};width:${L(mn(OUV[0]), mn(OUV[1]))}"></span>${[8, 12, 16].map(h => `<span class="eqf-g" style="left:${X(h * 60)}"></span>`).join('')}${inner}</div>`;
  const barre = (de, a, cl, txt) => `<span class="eqf-b ${cl || ''}" style="left:${X(mn(de))};width:${L(mn(de), mn(a))}">${txt || ''}</span>`;
  const ligne = (nom, inner, cl) => `<div class="eqf-ligne ${cl || ''}"><span>${nom}</span>${piste(inner)}</div>`;
  const heure = v => `<div class="eqf-t"><button type="button" class="eqf-pm" aria-label="Un quart d’heure plus tôt">−</button><span class="eqf-tv">${v}</span><button type="button" class="eqf-pm" aria-label="Un quart d’heure plus tard">+</button></div>`;
  const tete = (sous) => `<div class="eqf-tete"><span class="eqp-av">CP</span><div><b>Chloé Peeters</b><i class="eqp-lettre" title="Roulement A">A</i><span class="sub">${sous}</span></div><button type="button" class="eqf-x" aria-label="Fermer">×</button></div>`;
  const heures = () => `<div class="eqf-heures">${heure('07:00')}<span class="eqf-fl">→</span>${heure('14:00')}<span class="eqf-duree">7 h<small>de travail</small></span></div>`;
  const crens = () => `<div class="eqf-crens"><button type="button" class="eqf-cren on">Matin<small>07:00–14:00</small></button><button type="button" class="eqf-cren">Après-midi<small>12:30–18:30</small></button><button type="button" class="eqf-cren">Journée<small>07:00–18:30</small></button></div>`;
  const comptoirApres = () => barre('07:00', '10:00', 'comble', 'comblé') + barre('10:00', '18:30', 'cv');
  const journee = () => axe() + JOUR.map(([n, de, a]) => ligne(n, barre(de, a, '', de + '–' + a))).join('')
    + ligne('Chloé · nouveau', barre('07:00', '14:00', 'neuf', '07:00–14:00'), 'elle') + ligne('Comptoir', comptoirApres(), 'cpt');
  const effets = (lignes) => `<div class="eqf-eff">${lignes.join('')}</div>`;
  const effComptoir = '<div class="ok"><span>Comptoir</span><b><span class="eqf-ok">✓</span> le trou de 7 h à 10 h est comblé</b><em></em></div>';
  const effJour = `<div><span>Budget du mercredi</span><b>${m('25,5 %', 'noir')} → ${m('28,6 %', 'noir')}</b><em>+111 € · cible 17 %</em></div>`;
  const effSem = `<div><span>Budget de la semaine</span><b>${m('20,6 %', 'rouge')} → ${m('21,0 %', 'rouge')}</b><em>+7 h au planning</em></div>`;
  const effElle = '<div><span>Chloé cette semaine</span><b class="eqf-num">31 h → 38 h</b><em>6 jours, repos le vendredi</em></div>';
  const pied = (res, ok) => `<div class="eqf-pied">${res ? `<span class="eqf-res">${res}</span>` : ''}<button type="button" class="btn btn--ghost">Annuler</button><button type="button" class="btn btn--primary">${ok || 'Ajouter le service'}</button></div>`;
  const etiquette = (n, t) => `<div class="eqf-etiq">Proposition <b>${n}</b> · ${t}</div>`;

  /* 1 — La fenêtre compacte : les mêmes éléments qu'aujourd'hui, rangés ; les heures en 24 h au quart d'heure (− / +),
   *     la petite journée sous les heures (sa barre, le comptoir), puis « Ce que ça change » en quatre lignes. */
  function une() {
    return `<div class="eqf-voile"><div class="eqf eqf-1" role="dialog" aria-label="Nouveau service">
      ${tete('Vente · mercredi 25 novembre · nouveau service')}
      <div class="eqf-sec"><div class="eqf-h">Horaires</div>${heures()}${crens()}
        <div class="eqf-mini">${axe()}${ligne('Chloé', barre('07:00', '14:00', 'neuf', '07:00–14:00'), 'elle')}${ligne('Comptoir', comptoirApres())}</div></div>
      <div class="eqf-sec"><div class="eqf-h">Ce que ça change</div>${effets([effComptoir, effJour, effSem, effElle])}</div>
      ${pied('', 'Ajouter le service')}
    </div></div>${etiquette(1, 'La fenêtre compacte')}`;
  }

  /* 2 — Les créneaux d'abord : chaque créneau dit ce qu'il donne (le comptoir, le budget, le coût) avant qu'on le
   *     choisisse ; « Sur mesure » se règle en touchant les heures (tablette) ; à droite, qui est là ce jour-là. */
  function deux() {
    const puces = (de, a, on, minOn) => `<div class="eqf-puces">${Array.from({ length: a - de + 1 }, (_, i) => de + i).map(h => `<button type="button" class="eqf-puce${h === on ? ' on' : ''}">${String(h).padStart(2, '0')}</button>`).join('')}</div>
      <div class="eqf-puces">${[':00', ':15', ':30', ':45'].map(x => `<button type="button" class="eqf-puce min${x === minOn ? ' on' : ''}">${x}</button>`).join('')}</div>`;
    return `<div class="eqf-voile"><div class="eqf eqf-2" role="dialog" aria-label="Nouveau service">
      ${tete('Vente · mercredi 25 novembre · nouveau service')}
      <div class="eqf-corps"><div class="eqf-gauche"><div class="eqf-h">Quel créneau ?</div>
        <div class="eqf-carte on"><span class="eqf-radio"></span><span class="t">Matin<span>07:00–14:00 · 7 h</span></span><span class="prix">+111 €</span>
          <span class="d"><span class="bon">✓ comble le comptoir de 7 h à 10 h</span><span>mercredi ${m('28,6 %', 'noir')}</span></span></div>
        <div class="eqf-carte"><span class="eqf-radio"></span><span class="t">Après-midi<span>12:30–18:30 · 6 h</span></span><span class="prix">+95 €</span>
          <span class="d"><span>le comptoir reste vide de 7 h à 10 h ; 3 personnes au comptoir à 14 h</span><span>mercredi ${m('28,2 %', 'noir')}</span></span></div>
        <div class="eqf-carte"><span class="eqf-radio"></span><span class="t">Sur mesure</span><span class="prix"></span>
          <div class="eqf-sm"><span class="eqf-lab">Début</span>${puces(5, 14, 7, ':00')}<span class="eqf-lab">Fin</span>${puces(11, 20, 14, ':00')}</div></div>
      </div>
      <div class="eqf-droite"><div class="eqf-h">Mercredi 25 · qui est là</div><div class="eqf-qui">${journee()}</div>
        <div class="eqf-h" style="margin-top:16px">Ce que ça change</div>${effets([effSem, effElle])}</div></div>
      ${pied('<b>Chloé</b> · mer. 25 · 07:00–14:00 · 7 h · +111 €')}
    </div></div>${etiquette(2, 'Les créneaux d’abord, chacun avec son effet')}`;
  }

  /* 3 — Le panneau à droite : le tableau reste visible (la case est cerclée) ; sa semaine en haut (ce qu'elle fait déjà,
   *     son jour de repos), les heures, « le même horaire d'autres jours » (poser plusieurs jours d'un coup), la journée,
   *     l'effet ; les boutons restent en bas. */
  function trois() {
    const sem = [['L 23', '12:30–18:30'], ['M 24', '12:30–18:30'], ['M 25', '07:00–14:00', 'ce'], ['J 26', '12:30–18:30'], ['V 27', 'repos', 'rep'], ['S 28', '07:00–14:00'], ['D 29', '07:00–13:00']];
    const rep = [['L', 23, 'non'], ['M', 24, 'non'], ['M', 25, 'on'], ['J', 26, 'non'], ['V', 27, ''], ['S', 28, 'non'], ['D', 29, 'non']];
    return `<aside class="eqf-tiroir" aria-label="Nouveau service">${tete('Vente · 15,80 €/h · nouveau service')}<div class="eqf-defile">
      <div class="eqf-sec"><div class="eqf-h">Sa semaine</div><div class="eqf-sem">${sem.map(([j, h, cl]) => `<div class="${cl || ''}"><b>${j}</b>${h.replace('–', '<br>')}</div>`).join('')}</div>
        <div class="eqf-semtot"><span>6 jours travaillés · repos le vendredi</span><span><b class="eqf-num">31 h → 38 h</b></span></div></div>
      <div class="eqf-sec"><div class="eqf-h">Horaires du mercredi 25</div>${heures()}${crens()}</div>
      <div class="eqf-sec"><div class="eqf-h">Le même horaire d’autres jours</div><div class="eqf-rep">${rep.map(([j, d, cl]) => `<label class="${cl}"><span class="eqf-cb"></span><b>${j}</b>${d}</label>`).join('')}</div>
        <p class="eqf-note">Cochez un jour pour y poser le même service en une fois ; grisés : Chloé y travaille déjà.</p></div>
      <div class="eqf-sec"><div class="eqf-h">Ce que ça change</div>${effets([effComptoir, effJour, effSem])}</div>
      <div class="eqf-sec eqf-jour"><div class="eqf-h">Mercredi 25 · qui est là</div>${journee()}</div>
      </div>${pied('mer. 25 · 7 h · <b>+111 €</b>')}</aside>${etiquette(3, 'Le panneau à droite, le tableau reste visible')}`;
  }

  window.EQF = {
    monter(n) {
      document.querySelectorAll('.eqf-voile,.eqf-tiroir,.eqf-etiq').forEach(x => x.remove());
      document.querySelectorAll('.eqf-cell-on').forEach(x => x.classList.remove('eqf-cell-on'));
      const d = document.createElement('div'); d.innerHTML = [null, une, deux, trois][n]();
      [...d.children].forEach(x => document.body.appendChild(x));
      const c = document.querySelector('.eqp-tab td[data-eqp-cell="2026-11-25"][data-emp="23"]'); if (c) { c.classList.add('eqf-cell-on'); }
    },
  };
})();
