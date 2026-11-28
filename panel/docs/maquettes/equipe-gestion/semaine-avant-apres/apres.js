/* L'après de Mon équipe › Planning › Semaine (maquette du 26/11/2026), posé sur le vrai écran déjà rendu par
 * panel/public/dashboard/equipe.js (rendTableau) : rien n'est recalculé ici, les chiffres sont relus dans l'écran.
 *  1. Les cinq tuiles et la jauge deviennent un résumé en deux lignes : le budget de la semaine, sa jauge, ce qu'il faut
 *     retirer ; puis les heures et « À corriger » (chaque jour hors cible, chaque trou), qui ouvre le jour en grand.
 *  2. La ligne « Par jour » et les linéaires montent dans la tête du tableau, sous le nom des jours : la rangée des
 *     paliers du jour (un ruban, une couleur par palier, toujours avec un mot) et qui tient chaque secteur. La tête
 *     reste collée en haut quand on descend dans l'équipe.
 *  3. Le paragraphe d'aide devient une ligne, avec la légende des linéaires. */
(() => {
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const txt = el => (el ? el.textContent.replace(/\s+/g, ' ').trim() : '');
  const tab = $('section.eqp-tab:not(.eqp-moi)'); if (!tab) { return; }
  const kpis = $('section.eqp-kpis'), bj = $('section.eqp-bj');
  const note = tab.nextElementSibling && tab.nextElementSibling.matches('p.eqp-note') ? tab.nextElementSibling : null;
  const niveauDe = el => { const m = el && /eqp-m-(ok|orange|rouge|noir)/.exec(el.className); return m ? m[1] : null; };
  const premierTexte = el => { const n = el && [...el.childNodes].find(x => x.nodeType === 3 && x.textContent.trim()); return n ? n.textContent.trim() : txt(el); };

  // ── Ce que l'écran dit déjà ──
  const K = kpis ? $$('.kpi', kpis) : [];
  const kv = i => premierTexte(K[i] && $('.kpi__value', K[i])), kn = i => txt(K[i] && $('.kpi__note', K[i]));
  const heures = kv(1), budgetH = (/budget ([\d,]+ h)/.exec(kn(1)) || [])[1], etp = kv(3);
  const mS = bj && $('.eqp-bj-v .eqp-m', bj), pctS = txt(mS), nivS = niveauDe(mS);
  const cible = ((/([\d,]+) %/.exec(txt(bj && $('.eqp-bj-ech .c', bj)))) || [])[1] || '17';
  const coutCa = txt(bj && $('.eqp-bj-n', bj)).replace(txt(bj && $('.eqp-bj-n b', bj)), '').trim();
  const lev = bj && $('.eqp-bj-r', bj), levH = txt(lev && $('b', lev)), levMoy = (/coût moyen de ([\d,  ]+€\/h)/.exec(txt(lev)) || [])[1], levC = txt(lev && $('.ou b', lev));
  const gauge = bj && $('.eqp-bj-g', bj);

  const ths = $$('thead tr:first-child th', tab), jours = ths.slice(1, -2).map(th => {
    const b = $('[data-eqp-jour]', th); return { date: b ? b.dataset.eqpJour : '', court: txt(b), auj: th.classList.contains('auj') };
  });
  const tot = $('tbody tr.tot', tab), totCells = tot ? $$('td', tot) : [];
  const parJour = jours.map((J, i) => { const td = totCells[i + 1], m = td && $('.eqp-m', td);
    return { pct: txt(m), niv: niveauDe(m), pers: premierTexte(td).replace(' · ', ', ') }; });
  const totH = txt(totCells[totCells.length - 2]), totC = txt(totCells[totCells.length - 1]);
  const lsep = $('tbody tr.eqp-lsep', tab), lins = $$('tbody tr.eqp-lin', tab), leg = lsep && $('.eqp-leg', lsep);

  // ── Les mots ──
  const MOT = { ok: 'dans la cible', orange: 'au-dessus de ' + cible + ' %', rouge: 'au-dessus de 20 %', noir: 'au-dessus de 22 %' };
  const jourLong = d => { const s = new Date(d + 'T12:00:00').toLocaleDateString('fr-BE', { weekday: 'long' }); return s.charAt(0).toUpperCase() + s.slice(1); };
  const heureLue = s => s.replace(/(\d{1,2})h(\d{2})?/g, (_, h, m) => h + ' h' + (m && m !== '00' ? ' ' + m : '')).replace('–', ' à ');

  // « À corriger » : un bouton par jour qui a un souci, dans l'ordre de la semaine ; il ouvre le jour en grand.
  const corr = jours.map((J, i) => {
    const P = parJour[i], sous = [];
    if (P.niv && P.niv !== 'ok') { sous.push({ quoi: P.pct + ' de frais de personnel', niv: P.niv }); }
    lins.forEach(tr => { const td = $$('td', tr)[i + 1], t = txt(td && $('.eqp-tr', td)), nom = txt($('td', tr));
      if (t) { sous.push({ quoi: t === 'personne' ? nom.toLowerCase() + ' sans personne' : nom.toLowerCase() + ' vide de ' + heureLue(t), trou: true }); } });
    return sous.length ? { J, sous } : null;
  }).filter(Boolean);
  const icone = s => s.trou ? '<i class="ap-ic-trou" aria-hidden="true"></i>' : `<i class="ap-ic ap-${s.niv}" aria-hidden="true"></i>`;
  const corrHtml = corr.map(c => `<button type="button" class="btn btn--sm ap-corr-b" data-ap-jour="${c.J.date}" title="Ouvrir le ${jourLong(c.J.date).toLowerCase()} en grand">
      <span class="ap-corr-j">${jourLong(c.J.date)}</span>${c.sous.map(s => `<span class="ap-corr-s">${icone(s)}${s.quoi}</span>`).join('')}</button>`).join('');

  // ── 1. Le résumé ──
  const res = document.createElement('section');
  res.className = 'card ap-res'; res.setAttribute('aria-label', 'Budget d’heures de la semaine');
  res.innerHTML = `<div class="ap-l1">
      <div class="ap-v"><span class="ap-pct">${pctS}</span><span class="ap-niv"><i class="ap-ic ap-${nivS}" aria-hidden="true"></i>${MOT[nivS] || ''}</span></div>
      <div class="ap-quoi">de frais de personnel cette semaine<span>${coutCa}</span></div>
      <div class="ap-g"><p class="ap-meta">${heures} planifiées${budgetH ? ' pour un budget de ' + budgetH : ''}, soit ${etp} ETP</p></div>
      <div class="ap-lev">${levH ? `<span class="ap-lev-h">${levH} pour revenir à ${cible} %</span><span>au coût moyen de ${levMoy || '—'}, ou ${levC} de coût moyen pour les mêmes heures</span>` : `<span class="ap-lev-h">Dans la cible</span>`}</div>
    </div>
    <div class="ap-l2">
      ${corr.length ? `<div class="ap-corr"><span class="ap-corr-t">À corriger</span>${corrHtml}</div>` : '<p class="ap-ok">Chaque section est tenue toute la semaine.</p>'}
    </div>`;
  if (gauge) { $('.ap-g', res).prepend(gauge); }
  tab.parentNode.insertBefore(res, kpis || bj || tab);
  if (kpis) { kpis.remove(); } if (bj) { bj.remove(); }

  // ── 2. La tête du tableau ──
  const thead = $("thead", tab);
  const pal = document.createElement('tr'); pal.className = 'ap-pal';
  pal.innerHTML = `<th class="ap-pal-t">Budget du jour<span>personnes et heures</span></th>${jours.map((J, i) => { const P = parJour[i];
    return `<th class="ap-d${J.auj ? ' auj' : ''}"><i class="ap-rib ap-${P.niv || 'vide'}" aria-hidden="true"></i><span class="ap-d-in">${P.pct ? `<span class="ap-d-v">${P.pct}</span><span class="ap-d-w">${MOT[P.niv] || ''}</span>` : '<span class="ap-d-v">—</span>'}<span class="ap-d-s">${P.pers}</span></span></th>`; }).join('')}
    <th class="num ap-d-tot">${totH}</th><th class="num ap-d-tot">${totC}</th>`;
  thead.appendChild(pal);
  const heureCourte = t => t.replace(/(\d{1,2})h(\d{2})?/g, (_, h, m) => h + ' h' + (m && m !== '00' ? ' ' + m : ''));
  lins.forEach(tr => $$('.eqp-tr', tr).forEach(el => { el.textContent = heureCourte(el.textContent); const td = el.closest('td'); if (td) { td.classList.add('ap-trou-c'); } }));
  lins.forEach((tr, k) => { tr.classList.add('ap-lin'); if (k === 0) { tr.classList.add('ap-lin-1'); } $$('td', tr).forEach((td, j) => { if (j > 0 && jours[j - 1] && jours[j - 1].auj) { td.classList.add('auj'); } }); thead.appendChild(tr); });
  if (tot) { tot.remove(); } if (lsep) { lsep.remove(); }
  tab.classList.add('ap-tab');

  // ── 3. La ligne d'aide ──
  if (note) {
    note.className = 'eqp-note ap-note';
    note.innerHTML = `<span>Un clic sur un service l’ouvre, sur une case vide en ajoute un, sur un jour l’ouvre en grand pour tracer à la souris.</span>${leg ? leg.outerHTML : ''}`;
  }

  // « À corriger » ouvre le jour comme le fait son nom en tête de colonne (le geste existant).
  $$('[data-ap-jour]', res).forEach(b => b.addEventListener('click', () => { const t = $(`thead [data-eqp-jour="${b.dataset.apJour}"]`); if (t) { t.click(); } }));
  document.body.dataset.maqApres = 'ok';
})();
