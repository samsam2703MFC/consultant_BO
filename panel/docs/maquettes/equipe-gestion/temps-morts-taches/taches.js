/* Les tâches dans les temps morts (maquette, 23/11/2026, Sam : « dans l'assistant aussi (pour les sections de vente) on
   a les temps morts, donc on pourrait adapter les tâches des checklists de contrôle ou de tâches à ces moments-là »).
   Posée par capturer.cjs sur le vrai écran : window.EQT.monter(1 | 2 | 3).
   1 — une étape de l'assistant de la semaine : les creux habituels du comptoir, jour par jour, et les tâches sans heure
       fixe qui y sont posées, chacune à quelqu'un du comptoir présent ;
   2 — le jour (planogramme) : la ligne des tâches sous les temps morts habituels ;
   3 — la tablette du comptoir, au creux : « c'est calme : à faire maintenant ».
   Les checklists sont les vraies (shared/src/checklists_codes.php : code, nom, heure) ; les durées, les creux et les
   personnes sont ILLUSTRATIFS. */
(function () {
  'use strict';
  const mn = t => +t.slice(0, 2) * 60 + +t.slice(3), hh = m => String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
  const ZONE = { 1: '#8D1D2C', 4: '#1f5f8b' };
  const T = {
    '1.2.01': ['Facing et FIFO', 15, 1], '4.2.01': ['Hygiène quotidienne', 20, 4], '1.5.01': ['Planogramme et merchandising', 40, 1], '1.5.02': ['Audit visuel', 20, 1],
    '4.5.01': ['Nettoyage approfondi', 45, 4], '1.3.02': ['Photos des comptoirs', 10, 1], '1.2.02': ['Moments calmes', 10, 1],
  };
  // La semaine du 30 novembre : les creux habituels (10 min et plus sans ticket, les quatre mêmes jours d'avant), qui
  // tient le comptoir, et les tâches posées dans les creux : [début, code, prénom].
  const SEM = [
    ['Lun 30', [['09:40', '10:05'], ['14:10', '15:00'], ['15:35', '15:55']], [['07:00', '11:00'], ['12:30', '18:30']], [['09:40', '1.2.01', 'Laura'], ['14:10', '1.5.01', 'Chloé'], ['15:35', '4.2.01', 'Laura']]],
    ['Mar 1', [['09:45', '10:10'], ['14:05', '14:55'], ['16:00', '16:20']], [['10:00', '18:30']], [['14:05', '1.2.01', 'Fatima'], ['14:20', '4.2.01', 'Chloé'], ['16:00', '1.5.02', 'Fatima']]],
    ['Mer 2', [['10:00', '10:30'], ['14:15', '15:05']], [['10:00', '18:30']], [['10:00', '1.2.01', 'Fatima'], ['14:15', '4.5.01', 'Laura']]],
    ['Jeu 3', [['09:40', '10:05'], ['14:10', '15:00'], ['15:35', '15:55']], [['10:00', '18:30']], [['14:10', '1.2.01', 'Fatima'], ['14:25', '4.2.01', 'Chloé'], ['15:35', '1.2.02', 'Fatima']]],
    ['Ven 4', [['14:20', '14:50']], [['10:00', '18:30']], [['14:20', '1.2.01', 'Fatima'], ['14:35', '1.2.02', 'Laura']]],
    ['Sam 5', [], [['07:00', '18:30']], []],
    ['Dim 6', [['13:30', '14:30']], [['07:00', '18:00']], [['13:30', '1.3.02', 'Laura'], ['13:40', '4.2.01', 'Laura'], ['14:00', '1.2.01', 'Laura']]],
  ];
  const H0 = 6 * 60 + 30, H1 = 19 * 60, X = m => ((m - H0) / (H1 - H0) * 100).toFixed(2) + '%', W = (a, b) => ((b - a) / (H1 - H0) * 100).toFixed(2) + '%';
  const puce = (de, code, qui, X2, W2) => { const t = T[code], a = mn(de) + t[1]; return `<span class="eqt-ch" style="left:${X2(mn(de))};width:${W2(mn(de), a)};background:${ZONE[t[2]]}" title="${hh(mn(de))}–${hh(a)} · ${code} ${t[0]} · ${qui}">${code.slice(-4)} <small>${qui}</small></span>`; };
  const etiq = t => `<div class="eqt-etiq">Tâches dans les temps morts · <b>${t}</b></div>`;

  /* 1 — L'étape de l'assistant. */
  function une() {
    const etapes = [['Partir de quoi ?', 'semaine passée reprise', 'fait'], ['Qui adapter ?', 'Jonas absent · Chloé à revoir', 'fait'], ['Le chiffre', '16,8 % · −18 h', 'fait'], ['Les temps morts', 'nouveau : les tâches des creux', 'ici neuf'], ['Vérifier et créer', 'le comptoir, les durées, le panel', 'apres']];
    const taches = [
      [true, '1.2.01', 'chaque jour', 'Aujourd’hui à <b>12:00</b>, en plein midi → au premier creux du jour, <b>6 jours sur 7</b> (samedi : pas de creux, reste à 12:00).'],
      [true, '4.2.01', 'chaque jour', '<b>14:00</b> → au creux de l’après-midi, 4 jours. <span class="ko">Mercredi, vendredi et samedi : pas de place, reste à 14:00.</span>'],
      [true, '1.5.01', 'le lundi', '<b>10:00</b> (le creux de 09:40 est trop court) → <b>14:10</b>, le seul creux du lundi assez long.'],
      [true, '1.5.02', 'le mardi', '<b>10:00</b> → <b>16:00</b>, le creux de la fin d’après-midi.'],
      [true, '4.5.01', 'le mercredi', '<b>15:00</b> → <b>14:15</b>, au début du creux du mercredi.'],
      [true, '1.3.02', 'le dimanche', '<b>14:30</b> → <b>13:30</b>, quand Laura est seule et que c’est calme.'],
      [true, '1.2.02', 'sans heure', 'Posée dans les creux qui restent : jeudi et vendredi.'],
    ];
    const liste = taches.map(([on, code, quand, d]) => `<div class="eqt-t${on ? ' on' : ''}"><span class="eqt-cb"></span><span class="n"><i>${code}</i>${T[code][0]} <span style="font-weight:400;color:var(--muted)">· ${T[code][1]} min · ${quand}</span></span><span class="d"><span class="eqt-z" style="background:${ZONE[T[code][2]]}"></span>${d}</span></div>`).join('')
      + `<div class="eqt-t fixe"><span class="eqt-cb"></span><span class="n" style="font-weight:400">Gardent leur heure</span><span class="d">1.1.01 Ouverture 06:30 · 1.1.02 Photos des comptoirs 07:00 · 1.3.01 Recuisson et réassort 14:00 · 1.4.01 Fermeture 18:00 : liées à l’ouverture, à la cuisson ou à la fermeture.</span></div>`;
    // Un jour : la bande de la journée (le comptoir, les creux), puis une carte par creux : ses heures, ses tâches, qui.
    const jours = SEM.map(([j, creux, cpt, tch]) => {
      const cartes = creux.map(([a, b]) => {
        const dans = tch.filter(([de]) => mn(de) >= mn(a) && mn(de) < mn(b)), pris = dans.reduce((x, [, c]) => x + T[c][1], 0), libre = mn(b) - mn(a) - pris;
        return `<div class="eqt-cr"><span class="h">${a}–${b} <i>${mn(b) - mn(a)} min</i></span>${dans.map(([de, c, qui]) => `<span class="tk" style="border-left-color:${ZONE[T[c][2]]}"><b>${c}</b> ${T[c][0]} <i>${T[c][1]} min · ${qui}</i></span>`).join('') || '<span class="vide">rien à poser</span>'}${libre >= 5 ? `<span class="lb">${libre} min libres</span>` : ''}</div>`;
      }).join('');
      return `<div class="eqt-j"><span>${j}<small>${creux.length ? creux.reduce((x, [a, b]) => x + mn(b) - mn(a), 0) + ' min de creux' : 'pas de creux'}</small></span><div><div class="eqt-bd"><span class="ouv" style="left:${X(7 * 60)};width:${W(7 * 60, 18 * 60 + 30)}"></span>${cpt.map(([a, b]) => `<span class="cpt" style="left:${X(mn(a))};width:${W(mn(a), mn(b))}"></span>`).join('')}${creux.map(([a, b]) => `<span class="tm" style="left:${X(mn(a))};width:${W(mn(a), mn(b))}"></span>`).join('')}</div>
        <div class="eqt-crs">${cartes || '<span class="eqt-rien">Samedi plein toute la journée : les tâches gardent leur heure.</span>'}</div></div></div>`;
    }).join('');
    let axe = ''; for (let h = 7; h <= 19; h += 2) { axe += `<i style="left:${X(h * 60)}">${h} h</i>`; }
    return `<div class="eqt-voile"><div class="eqt" role="dialog" aria-label="Assistant de la semaine">
      <aside class="eqt-rail"><h2>Assistant de la semaine</h2><span class="sub">du 30 novembre au 6 décembre</span>${etapes.map(([t, s, cl], i) => `<div class="eqt-et ${cl}"><i>${cl === 'fait' ? '✓' : i + 1}</i><b>${t}</b><small>${s}</small></div>`).join('')}</aside>
      <div class="eqt-main"><p class="eqt-q">Les temps morts du comptoir</p><p class="eqt-qs">Les creux habituels (10 minutes et plus sans ticket, les quatre mêmes jours d’avant) : on y pose les tâches qui n’ont pas besoin d’une heure fixe, chacune à quelqu’un du comptoir présent. Aux heures de pointe, le comptoir sert.</p>
        <div class="eqt-cols"><div><p class="eqt-h">Les tâches qui peuvent bouger · à cocher</p>${liste}</div>
          <div><p class="eqt-h">La semaine · le comptoir, ses creux, les tâches posées</p><div class="eqt-sem"><div class="eqt-axe">${axe}</div>${jours}
            <div class="eqt-leg"><span><i style="background:var(--muted-2);opacity:.35"></i>quelqu’un au comptoir</span><span><i style="background:repeating-linear-gradient(135deg,#c9bfb2 0 3px,#e6dfd5 3px 5px)"></i>temps mort habituel</span><span><i style="background:${ZONE[1]}"></i>tâche de vente</span><span><i style="background:${ZONE[4]}"></i>nettoyage</span></div></div>
            <div class="eqt-bilan"><b>5 h 05</b> de tâches passent dans les temps morts : autant de moins pendant les heures de pointe. Il reste <b>2 h 30</b> de creux libres dans la semaine. Ni heure ni personne en plus : le budget ne bouge pas (16,8 %).</div></div></div></div>
      <div class="eqt-pied"><span class="res"><b>16</b> tâches posées dans les creux · 4 restent à leur heure (le samedi, l’hygiène du mercredi et du vendredi)</span><button type="button" class="btn btn--ghost">Retour</button><button type="button" class="btn btn--primary">Continuer ›</button></div>
    </div></div>${etiq('1 · une étape de l’assistant')}`;
  }

  /* 2 — Le jour : la ligne des tâches, sous les temps morts habituels (posée dans le vrai planogramme). */
  function deux() {
    const tml = [...document.querySelectorAll('.eqp-pg .eqp-tml')].pop(); if (!tml) { return ''; }
    const t = [...document.querySelectorAll('.eqp-axe .eqp-t')].map(n => +n.textContent * 60), a0 = t[0], a1 = t[t.length - 1];
    const X2 = m => ((m - a0) / (a1 - a0) * 100).toFixed(2) + '%', W2 = (a, b) => ((b - a) / (a1 - a0) * 100).toFixed(2) + '%';
    const fixe = (de, txt, dur) => `<span class="eqt-ch fixe" style="left:${X2(mn(de))};width:${W2(mn(de), mn(de) + dur)}" title="${txt} · heure fixe"></span>`;
    const tache = (de, code, qui, i) => { const t = T[code], a = mn(de) + t[1]; return `<span class="eqt-ch" style="left:${X2(mn(de))};width:${W2(mn(de), a)};background:${ZONE[t[2]]}"></span><span class="eqt-lb${i % 2 ? ' bas' : ''}" style="left:${X2(mn(de))}" title="${code} ${t[0]}"><b>${hh(mn(de))} ${t[0].split(' ')[0]}</b> · ${qui}</span>`; };
    const L = [['09:40', '1.2.01', 'Laura'], ['14:15', '1.5.01', 'Chloé'], ['15:35', '4.2.01', 'Laura'], ['17:50', '1.2.02', 'Chloé']];
    const d = document.createElement('div');
    d.className = 'eqp-l eqp-li eqt-lane';
    d.innerHTML = `<div class="eqp-n">Tâches des creux<span class="sub">4 posées · 1 h 25 · fixes en pointillé</span></div><div class="eqp-tl">${fixe('06:30', '1.1.01 Ouverture', 30)}${fixe('14:00', '1.3.01 Recuisson et réassort', 10)}${fixe('18:00', '1.4.01 Fermeture', 30)}${L.map(([de, code, qui], i) => tache(de, code, qui, i)).join('')}</div>`;
    tml.after(d);
    d.scrollIntoView({ block: 'center' });
    const r = d.getBoundingClientRect(), b = document.createElement('div');
    b.className = 'eqt-bulle eqt-x'; b.style.left = Math.round(r.left + r.width * 0.36) + 'px'; b.style.top = Math.round(r.bottom + 10) + 'px'; b.style.position = 'fixed';
    b.innerHTML = '<b>14:15–14:55 · 1.5.01 Planogramme et merchandising</b><br>Prévue à 10:00 ; posée au creux habituel du lundi (45 min sans client, les 4 lundis d’avant). Chloé tient le comptoir (Laura revient à 14:30) : la tâche lui va.';
    document.body.appendChild(b);
    return etiq('2 · le jour : la ligne des tâches');
  }

  /* 3 — La tablette du comptoir, au creux de 14:10. */
  function trois() {
    return `<div class="eqt-tab"><div class="eqt-tab-ecran"><div class="eqt-tab-haut"><span>Tablette du comptoir · Sombreffe</span><span>lundi 14:16</span></div>
      <div class="eqt-calme"><b>C’est calme</b><span>D’habitude, peu de clients jusqu’à 15:00 (les 4 lundis d’avant).</span></div>
      <div class="eqt-afaire"><div class="eqt-h" style="margin-bottom:8px">À faire maintenant · Chloé</div>
        <div class="eqt-tache"><span class="n"><i>1.5.01</i>Planogramme et merchandising</span><span class="du">40 min</span><span class="m">12 points · photo de la vitrine à la fin</span><span class="bt"><span class="go">Commencer</span><span class="plt">Un client : plus tard</span></span></div>
        <div class="eqt-tache ensuite"><span class="n"><i>4.2.01</i>Hygiène quotidienne</span><span class="du">20 min</span><span class="m">au creux de 15:35 · Laura</span></div></div>
      <div class="eqt-tab-bas">Faite dans le creux, la tâche compte comme d’habitude dans Tâches ; reportée, elle revient au creux suivant, sinon à son heure.</div></div></div>${etiq('3 · la tablette, au moment calme')}`;
  }

  window.EQT = {
    monter(n) {
      document.querySelectorAll('.eqt-voile,.eqt-etiq,.eqt-tab,.eqt-x').forEach(x => x.remove());
      const h = [null, une, deux, trois][n](); if (!h) { return; }
      const d = document.createElement('div'); d.innerHTML = h; [...d.children].forEach(x => document.body.appendChild(x));
    },
  };
})();
