/* Maquette « Mon équipe » (23/11/2026) : posée sur le vrai dashboard par capturer.cjs (window.EQ.monter('resp' | 'modale'
   | 'planning' | 'employes')). Le cadre (rail, magasin) est celui de la production ; l'équipe, ses postes, ses produits et
   son planning sont ILLUSTRATIFS (aucune personne réelle). */
(function () {
  'use strict';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const fE = n => Math.round(n).toLocaleString('fr-BE').replace(/ | /g, ' ') + ' €';
  const fH = n => (Number.isInteger(n) ? String(n) : n.toFixed(1).replace('.', ',')) + ' h';
  const ini = n => n.split(' ').map(x => x[0]).slice(0, 2).join('').toUpperCase();
  const ICO_EQ = 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75';

  // Les secteurs de production de l'assortiment du magasin (le secteur vient du produit, au panel).
  const SECT = [
    { id: 1, nom: 'Boulangerie', prods: ['Baguette tradition', 'Pain gris', 'Pain blanc', 'Pain aux céréales', 'Pistolets', 'Pain de seigle', 'Cramique', 'Craquelin', 'Pain d’épeautre', 'Demi-gris', 'Ficelle', 'Pain au levain', 'Pain complet', 'Pain aux noix', 'Pain de mie', 'Baguette blanche', 'Pain multicéréale', 'Pain sans sel', 'Mini-pistolets', 'Pain aux olives', 'Fougasse', 'Pain du jour', 'Pain campagnard', 'Pain au maïs', 'Pain aux graines', 'Boule de seigle'] },
    { id: 2, nom: 'Viennoiserie', prods: ['Croissant', 'Pain au chocolat', 'Couque suisse', 'Couque au beurre', 'Pain aux raisins', 'Chausson aux pommes', 'Croissant aux amandes', 'Couque au chocolat', 'Brioche', 'Brioche tressée', 'Mini-viennoiseries', 'Cougnou', 'Cramique au sucre', 'Couque au sucre', 'Suisse aux raisins', 'Croissant jambon', 'Kouign-amann', 'Torsade'] },
    { id: 3, nom: 'Pâtisserie', n: 38 },
    { id: 4, nom: 'Snacking', n: 24 },
    { id: 5, nom: 'Traiteur', prods: ['Quiche lorraine', 'Vol-au-vent', 'Lasagne', 'Croquettes au fromage', 'Salade de pâtes', 'Boulettes sauce tomate', 'Quiche saumon-poireau', 'Gratin dauphinois', 'Américain préparé', 'Salade de poulet', 'Hachis parmentier', 'Soupe du jour'] },
  ];
  SECT.forEach(s => { s.n = s.prods ? s.prods.length : s.n; });
  const SANS_SECTEUR = ['Bougies d’anniversaire', 'Sac isotherme', 'Carte cadeau', 'Café à emporter'];
  // Les postes du panel (Admin › structure) : un poste lié à un secteur donne ses produits.
  const POSTES = [['Boulanger', 1], ['Tourier', 2], ['Pâtissier', 3], ['Sandwichs', 4], ['Traiteur', 5], ['Vente', 0], ['Responsable', 0], ['Plonge', 0]];
  const sect = id => SECT.find(s => s.id === id);
  // L'équipe : nom, fonction (positions du panel), postes, taux horaire (le coût du planning), téléphone, app employés.
  const EQ = [
    { nom: 'Amélie Dubois', fn: 'Responsable de magasin', postes: ['Responsable', 'Pâtissier'], t: 21, tel: '+32 470 21 08 44', app: 1 },
    { nom: 'Bastien Lambert', fn: 'Boulanger', postes: ['Boulanger', 'Tourier'], t: 19.5, tel: '+32 476 55 12 90', app: 1 },
    { nom: 'Chloé Peeters', fn: 'Vendeuse', postes: ['Vente'], t: 15.8, tel: '+32 485 30 77 12', app: 1 },
    { nom: 'David Janssens', fn: 'Boulanger', postes: ['Boulanger'], t: 18.5, tel: '+32 472 18 64 03', app: 1 },
    { nom: 'Elise Maes', fn: 'Pâtissière', postes: ['Pâtissier'], t: 18.9, tel: '+32 494 62 40 18', app: 1 },
    { nom: 'Fatima El Amrani', fn: 'Vendeuse', postes: ['Vente', 'Sandwichs'], t: 15.8, tel: '+32 489 04 51 26', app: 0 },
    { nom: 'Jonas Willems', fn: 'Apprenti', postes: [], t: 9.6, tel: '0470/12 34 5', app: 0, telKo: 1 },
    { nom: 'Laura Claes', fn: 'Vendeuse', postes: ['Vente'], t: 15.8, tel: '+32 471 90 26 35', app: 1 },
    { nom: 'Marc Lefèvre', fn: 'Tourier', postes: ['Tourier'], t: 17.9, tel: '+32 478 33 59 61', app: 1 },
  ];
  const INACTIFS = [{ nom: 'Sophie Renard', fn: 'Vendeuse', postes: ['Vente'], tel: '+32 477 41 82 09' }, { nom: 'Tom Hermans', fn: 'Boulanger', postes: ['Boulanger'], tel: '+32 491 15 70 66' }];
  EQ.forEach(p => {
    p.secteurs = [...new Set(p.postes.map(x => (POSTES.find(q => q[0] === x) || [])[1]).filter(Boolean))];
    p.nProd = p.secteurs.reduce((a, id) => a + sect(id).n, 0);
  });
  // Le planning de la semaine du 23 au 29/11/2026 (lundi = aujourd'hui) : [début, fin] par jour, null = repos.
  const JOURS = ['Lun 23', 'Mar 24', 'Mer 25', 'Jeu 26', 'Ven 27', 'Sam 28', 'Dim 29'];
  const P = {
    'Amélie Dubois': [['07:00', '15:00'], ['07:00', '15:00'], ['07:00', '15:00'], null, ['07:00', '15:00'], ['07:00', '15:00'], null],
    'Bastien Lambert': [['04:00', '12:00'], ['04:00', '12:00'], ['04:00', '12:00'], ['04:00', '12:00'], ['04:00', '10:00'], null, null],
    'Chloé Peeters': [['12:30', '18:30'], ['12:30', '18:30'], null, ['12:30', '18:30'], null, ['07:00', '14:00'], ['07:00', '13:00']],
    'David Janssens': [null, null, null, ['04:00', '12:00'], ['04:00', '12:00'], ['04:00', '12:00'], ['04:00', '11:00']],
    'Elise Maes': [['06:00', '14:00'], ['06:00', '14:00'], ['06:00', '14:00'], ['06:00', '14:00'], null, ['06:00', '14:00'], null],
    'Fatima El Amrani': [null, ['10:00', '18:00'], ['10:00', '18:00'], ['10:00', '18:00'], ['10:00', '18:00'], ['10:00', '14:00'], null],
    'Jonas Willems': [['08:00', '16:00'], null, ['08:00', '16:00'], null, ['08:00', '16:00'], null, null],
    'Laura Claes': [[['07:00', '11:00'], ['14:30', '18:30']], null, ['12:30', '18:30'], null, ['12:30', '18:30'], ['13:00', '18:30'], ['13:00', '18:00']],
    'Marc Lefèvre': [null, ['04:00', '12:00'], ['04:00', '12:00'], null, null, ['04:00', '12:00'], ['04:00', '11:00']],
  };
  const duree = s => { const m = t => +t.slice(0, 2) * 60 + +t.slice(3); return (m(s[1]) - m(s[0])) / 60; };
  /** Les services d'une personne un jour donné (une coupure : deux services le même jour). */
  const svs = (nom, j) => { const x = (P[nom] || [])[j]; return !x ? [] : Array.isArray(x[0]) ? x : [x]; };
  EQ.forEach(p => { p.h = JOURS.reduce((a, _, j) => a + svs(p.nom, j).reduce((b, s) => b + duree(s), 0), 0); p.nSv = JOURS.reduce((a, _, j) => a + svs(p.nom, j).length, 0); });

  const svg = d => `<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="width:16px;height:16px"><path d="${d}"/></svg>`;

  /** Le rail : « Mon équipe » dans « La journée », sous Temps morts, et actif. */
  function rail() {
    const r = document.getElementById('db-rail'); if (!r) { return; }
    r.querySelectorAll('.sidebar__item.is-active').forEach(b => { b.classList.remove('is-active'); b.removeAttribute('aria-current'); });
    let b = r.querySelector('[data-vue="equipe"]');
    if (!b) {
      const m = r.querySelector('[data-vue="morts"]');
      if (!m) { return; }
      m.insertAdjacentHTML('afterend', `<button type="button" class="sidebar__item" data-vue="equipe">${svg(ICO_EQ).replace('style="width:16px;height:16px"', '')}<span class="sidebar__item-label">Mon équipe</span></button>`);
      b = r.querySelector('[data-vue="equipe"]');
    }
    b.classList.add('is-active'); b.setAttribute('aria-current', 'page');
  }

  function cadre(onglet, corps) {
    const kk = (document.querySelector('#dash .db-vt .kk') || {}).textContent || 'Magasin';
    const tabs = [['employes', 'Employés', EQ.length], ['resp', 'Responsabilités', ''], ['planning', 'Planning', '']]
      .map(([k, l, n]) => `<button type="button" class="page-tabs__tab${k === onglet ? ' is-on' : ''}">${l}${n !== '' ? `<span class="n">${n}</span>` : ''}</button>`).join('');
    return `<div class="db-nav db-nav--rail db-nav--seul"><div class="db-vt"><div class="kk">${esc(kk)}</div><h1>Mon équipe</h1></div></div>
      <nav class="page-tabs" aria-label="Mon équipe">${tabs}</nav>${corps}`;
  }

  /** Responsabilités : qui a quel poste, et les produits qui en dépendent. */
  function resp(sel) {
    const tot = SECT.reduce((a, s) => a + s.n, 0) + SANS_SECTEUR.length;
    const couverts = SECT.filter(s => EQ.some(p => p.secteurs.includes(s.id))).reduce((a, s) => a + s.n, 0);
    const postesOcc = new Set(EQ.flatMap(p => p.postes)).size;
    const kpis = `<section class="card eq-kpis"><div class="kpi-row">
      <div class="kpi"><div class="kpi__value">${EQ.length}</div><div class="kpi__label">Employés actifs</div><div class="kpi__note">dont 1 sans poste</div></div>
      <div class="kpi"><div class="kpi__value">${postesOcc}</div><div class="kpi__label">Postes occupés</div><div class="kpi__note">sur ${POSTES.length} au panel</div></div>
      <div class="kpi"><div class="kpi__value">${couverts}</div><div class="kpi__label">Produits affectés</div><div class="kpi__note">sur ${tot} de l’assortiment</div></div>
      <div class="kpi"><div class="kpi__value">${tot - couverts}<span class="tag-warn">à compléter</span></div><div class="kpi__label">Produits sans responsable</div><div class="kpi__note">1 secteur vide, ${SANS_SECTEUR.length} sans secteur</div></div>
    </div></section>`;
    const th = SECT.map(s => { const vide = !EQ.some(p => p.secteurs.includes(s.id)); return `<th class="${vide ? 'eq-gap' : ''}">${s.nom}<span class="sub">${s.n} produits${vide ? ' · vide' : ''}</span></th>`; }).join('');
    const lignes = EQ.map(p => {
      const cell = s => {
        const ps = p.postes.filter(x => (POSTES.find(q => q[0] === x) || [])[1] === s.id);
        return `<td>${ps.length ? ps.map(x => `<span class="chip chip--neutral">${esc(x)}</span>`).join('') + `<span class="sub">${s.n} produits</span>` : '<span class="eq-tiret" aria-label="Aucune affectation">—</span>'}</td>`;
      };
      const autres = p.postes.filter(x => !(POSTES.find(q => q[0] === x) || [])[1]);
      return `<tr class="${p.nom === sel ? 'is-sel' : ''}"><td><div class="eq-pers"><span class="eq-av">${ini(p.nom)}</span><span><b>${esc(p.nom)}</b><span class="sub">${esc(p.fn)}${p.postes.length ? '' : ' · <span class="eq-vide">aucun poste</span>'}</span></span></div></td>${SECT.map(cell).join('')}<td>${autres.length ? autres.map(x => `<span class="chip chip--draft">${esc(x)}</span>`).join('') : '<span class="eq-tiret">—</span>'}</td></tr>`;
    }).join('');
    const p = EQ.find(x => x.nom === sel);
    const prods = p.secteurs.flatMap(id => sect(id).prods || []);
    const fiche = `<aside class="card eq-fiche" aria-label="Responsabilités de ${esc(p.nom)}">
      <div class="eq-fiche-tete"><span class="eq-av">${ini(p.nom)}</span><div><div class="nm">${esc(p.nom)}</div><span class="sub">${esc(p.fn)}</span></div></div>
      <h3>Son périmètre</h3>
      <div class="eq-flux">${p.postes.map(x => { const s = sect((POSTES.find(q => q[0] === x) || [])[1]); return `<div><span>${esc(x)}</span><span class="fl">→</span><span>${s ? esc(s.nom) : 'sans secteur'}</span><span class="n">${s ? s.n + ' produits' : '—'}</span></div>`; }).join('')}</div>
      <h3>Ses produits · ${p.nProd}</h3>
      <ul class="eq-prods">${prods.slice(0, 18).map(x => `<li>${esc(x)}</li>`).join('')}</ul>
      <div class="eq-plus"><button type="button" class="linklike">Voir les ${p.nProd} produits</button></div>
      <div class="eq-sem" role="button" title="Ouvrir son planning">${svg('M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z')}<span>Cette semaine : <b>${fH(p.h)}</b> · ${p.nSv} services</span><span style="flex:1"></span><span aria-hidden="true">›</span></div>
      <div class="eq-actions"><button type="button" class="btn btn--sm">Modifier ses postes</button><button type="button" class="btn btn--sm btn--ghost">Sa fiche</button></div>
    </aside>`;
    const gap = SECT.filter(s => !EQ.some(q => q.secteurs.includes(s.id)));
    const alerte = `<section class="eq-alerte" aria-labelledby="eq-ac"><h2 id="eq-ac">À compléter</h2><div class="eq-alerte-l">
      ${gap.map(s => `<div><span class="t">${esc(s.nom)} · ${s.n} produits, personne d’affecté</span>Aucun employé actif n’a le poste ${esc(s.nom)}.<span class="ex">${s.prods.slice(0, 5).map(esc).join(', ')}…</span><button type="button" class="linklike">Affecter quelqu’un</button></div>`).join('')}
      <div><span class="t">${SANS_SECTEUR.length} produits sans secteur</span>Le secteur d’un produit se règle au panel : sans lui, personne n’en a la charge.<span class="ex">${SANS_SECTEUR.map(esc).join(', ')}</span><button type="button" class="linklike">Demander au bureau</button></div>
      <div class="calme"><span class="t">Postes sans secteur : Vente, Responsable</span>Normal pour les postes généraux : ils ne donnent pas de produits. Un poste de production doit être lié à son secteur au panel.</div>
    </div></section>`;
    return cadre('resp', `<p class="eq-sous">Qui s’occupe de quoi : les postes de chacun et les produits qui en dépendent, dans l’assortiment actif du magasin.</p>${kpis}
      <div class="eq-grille"><section class="card" aria-labelledby="eq-mt">
        <div class="toolbar"><input type="search" placeholder="Rechercher un employé, un poste ou un produit…" aria-label="Rechercher"><select aria-label="Secteur"><option>Tous les secteurs</option></select><span class="sp"></span><button type="button" class="btn btn--primary">Modifier les affectations</button></div>
        <div class="eq-table-wrap"><table><thead><tr><th id="eq-mt">Employé</th>${th}<th>Autres postes<span class="sub">sans produits</span></th></tr></thead><tbody>${lignes}</tbody></table></div>
        <p class="eq-note">Les produits suivent le secteur du poste. La carte montre les affectations permanentes, ni les qualifications ni la présence : la présence est dans l’onglet Planning.</p>
      </section>${fiche}</div>${alerte}`);
  }

  /** La fenêtre « Modifier les affectations » : les postes, groupés, et ce que le changement donne avant d'enregistrer. */
  function modale(sel, ajout) {
    const p = EQ.find(x => x.nom === sel);
    const coches = new Set([...p.postes, ajout]);
    const opt = ([nom, sid]) => {
      const s = sect(sid), on = coches.has(nom), neuf = nom === ajout;
      return `<label class="eq-opt${on ? ' on' : ''}${neuf ? ' neuf' : ''}"><input type="checkbox"${on ? ' checked' : ''}><span>${esc(nom)}</span><span class="s">${s ? esc(s.nom) + ' · ' + s.n + ' produits' + (neuf && !EQ.some(q => q.secteurs.includes(sid)) ? ' · personne aujourd’hui' : '') : 'sans secteur'}</span></label>`;
    };
    const sAvant = p.secteurs, sApres = [...new Set([...coches].map(x => (POSTES.find(q => q[0] === x) || [])[1]).filter(Boolean))];
    const nApres = sApres.reduce((a, id) => a + sect(id).n, 0);
    const box = `<div class="eq-voile" role="dialog" aria-modal="true" aria-labelledby="eq-mod-t"><div class="eq-mod">
      <h3 id="eq-mod-t">Modifier les affectations</h3><p class="qui">${esc(p.nom)} · ${esc(p.fn)} — cochez ses postes ; ses produits suivent leurs secteurs. Tout décocher retire ses affectations.</p>
      <div class="eq-opt-g">Postes de production</div>${POSTES.filter(x => x[1]).map(opt).join('')}
      <div class="eq-opt-g">Postes généraux</div>${POSTES.filter(x => !x[1]).map(opt).join('')}
      <div class="eq-apres">Avec ces postes : ${sApres.map(id => sect(id).nom).join(', ')} · <b style="font-weight:700">${nApres} produits</b> (+${nApres - p.nProd}).<br>Le ${esc(sect(sApres.find(id => !sAvant.includes(id))).nom)} n’avait personne : il sera couvert.</div>
      <div class="confirm__actions"><button type="button" class="btn btn--ghost">Annuler</button><button type="button" class="btn btn--primary">Enregistrer</button></div>
    </div></div>`;
    return resp(sel) + box;
  }

  /** Planning : la semaine, personne par personne ; les secteurs que personne ne tient ce jour-là. */
  function planning() {
    const auj = 0;
    const perJour = JOURS.map((_, j) => EQ.filter(p => svs(p.nom, j).length));
    const hJour = JOURS.map((_, j) => EQ.reduce((a, p) => a + svs(p.nom, j).reduce((b, s) => b + duree(s), 0), 0));
    const totH = EQ.reduce((a, p) => a + p.h, 0), totC = EQ.reduce((a, p) => a + p.h * p.t, 0);
    const vides = JOURS.map((_, j) => SECT.filter(s => EQ.some(p => p.secteurs.includes(s.id)) && !perJour[j].some(p => p.secteurs.includes(s.id))));
    const zone = p => p.secteurs.length ? sect(p.secteurs[0]).nom : (p.postes[0] || 'sans poste');
    const lignes = EQ.map((p, i) => `<tr><td><div class="eq-pers"><span class="eq-av">${ini(p.nom)}</span><span><b>${esc(p.nom)}</b><span class="sub">${esc(p.postes.join(', ') || 'aucun poste')}</span></span></div></td>${JOURS.map((_, j) => {
      const L = svs(p.nom, j);
      if (!L.length) { return `<td class="${j === auj ? 'auj' : ''}">${i === 6 && j === 3 ? '<span class="eq-add">+ Ajouter</span>' : '<span class="eq-tiret">—</span>'}</td>`; }
      return `<td class="${j === auj ? 'auj' : ''}">${L.map(s => `<span class="eq-sv">${s[0]}–${s[1]}<small>${esc(zone(p))}</small></span>`).join('')}</td>`;
    }).join('')}<td class="num">${fH(p.h)}</td><td class="num">${fE(p.h * p.t)}</td></tr>`).join('');
    const tot = `<tr class="tot"><td>Par jour</td>${JOURS.map((_, j) => `<td class="${j === auj ? 'auj' : ''}">${perJour[j].length} pers. · ${fH(hJour[j])}</td>`).join('')}<td class="num">${fH(totH)}</td><td class="num">${fE(totC)}</td></tr>
      <tr class="tot"><td>Secteurs sans personne<span class="sub">d’après les postes</span></td>${JOURS.map((_, j) => `<td class="${j === auj ? 'auj' : ''}">${vides[j].length ? vides[j].map(s => `<span class="tag-warn" style="margin:1px 0">${esc(s.nom)}</span>`).join(' ') : '<span class="eq-tiret">—</span>'}</td>`).join('')}<td></td><td></td></tr>`;
    return cadre('planning', `<div class="eq-pbar"><span class="eq-semnav"><button type="button" class="btn btn--icon" aria-label="Semaine précédente">‹</button><span>Semaine du 23 au 29 novembre 2026</span><button type="button" class="btn btn--icon" aria-label="Semaine suivante">›</button></span><button type="button" class="btn btn--sm btn--ghost">Cette semaine</button><span class="sp"></span><div class="seg" role="group" aria-label="Affichage"><button type="button" class="is-on">Semaine</button><button type="button">Jour</button></div><button type="button" class="btn btn--primary">Ajouter un service</button></div>
      <section class="card eq-kpis"><div class="kpi-row">
        <div class="kpi"><div class="kpi__value">${EQ.filter(p => p.h).length}</div><div class="kpi__label">Employés au planning</div><div class="kpi__note">${EQ.reduce((a, p) => a + p.nSv, 0)} services</div></div>
        <div class="kpi"><div class="kpi__value">${fH(totH)}</div><div class="kpi__label">Heures planifiées</div><div class="kpi__note">${fH(Math.round(totH / EQ.length * 10) / 10)} par personne en moyenne</div></div>
        <div class="kpi"><div class="kpi__value">${fE(totC)}</div><div class="kpi__label">Coût du personnel</div><div class="kpi__note">estimé au taux horaire du panel</div></div>
        <div class="kpi"><div class="kpi__value">2<span class="tag-warn">à vérifier</span></div><div class="kpi__label">Jours avec un secteur vide</div><div class="kpi__note">lundi, dimanche</div></div>
      </div></section>
      <section class="card" style="padding-top:0;padding-bottom:0"><div class="eq-table-wrap" style="border-top:0"><table class="eq-grid"><thead><tr><th>Employé</th>${JOURS.map((d, j) => `<th class="${j === auj ? 'auj' : ''}">${d}${j === auj ? '<span class="sub" style="color:var(--accent)">aujourd’hui</span>' : ''}</th>`).join('')}<th class="num">Heures</th><th class="num">Coût</th></tr></thead><tbody>${lignes}${tot}</tbody></table></div></section>
      <section class="card eq-pdf" aria-label="Imprimer le planning"><strong>Imprimer le planning</strong>
        <label class="field">À partir du <input type="text" value="23/11/2026" size="10"></label>
        <label class="field">Période <select><option>Une semaine</option><option>Deux semaines</option></select></label>
        <label class="field">Présentation <select><option>Tout le magasin</option><option>Par département</option></select></label>
        <span style="flex:1"></span><span class="eq-note" style="margin:0">A4 paysage · sans pied de page</span><button type="button" class="btn btn--sm">Télécharger le PDF</button></section>
      <p class="eq-note">Un clic sur un service l’ouvre : ses heures, son poste, et son évaluation (de 1 à 5, avec une note). « Secteurs sans personne » croise le planning et les postes de chacun ; une présence planifiée ne garantit pas que toute la production est faite.</p>`);
  }

  /** Employés : la liste, les postes de chacun, l'accès à l'app employés. */
  function employes() {
    const l = (p, actif) => `<tr class="${actif ? '' : 'eq-inactif'}"><td><div class="eq-pers"><span class="eq-av">${ini(p.nom)}</span><span><b>${esc(p.nom)}</b><span class="sub">${esc(p.fn)}</span></span></div></td>
      <td>${p.postes.length ? p.postes.map(x => `<span class="chip chip--neutral">${esc(x)}</span>`).join('') : '<span class="tag-warn">aucun poste</span>'}</td>
      <td>${actif ? (p.nProd ? p.nProd + ' produits' : '<span class="eq-tiret">—</span>') : '<span class="eq-tiret">—</span>'}</td>
      <td style="white-space:nowrap">${esc(p.tel)}${p.telKo ? '<span class="sub"><span class="tag-warn">numéro à vérifier</span></span>' : ''}</td>
      <td class="num">${actif ? fH(p.h) : '—'}</td>
      <td>${actif ? (p.app ? '<span class="chip chip--ok">accès ouvert</span>' : '<span class="chip chip--draft">pas d’accès</span>') : '<span class="eq-tiret">—</span>'}</td>
      <td>${actif ? '<span class="chip chip--ok">Actif</span>' : '<span class="chip chip--draft">Inactif</span>'}</td>
      <td class="num"><button type="button" class="linklike">Modifier</button></td></tr>`;
    return cadre('employes', `<div class="toolbar"><input type="search" placeholder="Rechercher un employé, un poste, un numéro…" aria-label="Rechercher"><div class="seg" role="group" aria-label="Statut"><button type="button">Actifs · ${EQ.length}</button><button type="button">Inactifs · ${INACTIFS.length}</button><button type="button" class="is-on">Tous · ${EQ.length + INACTIFS.length}</button></div><span class="sp" style="flex:1"></span><button type="button" class="btn">Imprimer la liste</button><button type="button" class="btn btn--primary">Ajouter un employé</button></div>
      <section class="card" style="padding-top:0;padding-bottom:0"><div class="eq-table-wrap" style="border-top:0"><table><thead><tr><th>Employé</th><th>Postes de travail</th><th>Responsable de</th><th>Téléphone</th><th class="num">Cette semaine</th><th>App employés</th><th>Statut</th><th></th></tr></thead><tbody>${EQ.map(p => l(p, true)).join('')}${INACTIFS.map(p => l(p, false)).join('')}</tbody></table></div></section>
      <p class="eq-note">« Modifier » ouvre la fiche : identité, téléphone (au format international, pour les SMS), langue, fonction, postes de travail, identifiants de l’app employés, compétences. Un employé ne se supprime pas : il se désactive, et son historique reste.</p>`);
  }

  /* --- Le planogramme des horaires (23/11/2026, « un planogramme avec les linéaires pour les horaires ») ------------
   * L'axe des heures de 04:00 à 20:00, une ligne par personne rangée par secteur, ses services en barres ; dessous, les
   * linéaires : la présence quart d'heure par quart d'heure, et une ligne par secteur, pleine quand quelqu'un de ce
   * secteur est là. Le comptoir (Vente) est vérifié sur les heures d'ouverture. */
  const mn = t => +t.slice(0, 2) * 60 + +t.slice(3);
  const hh = x => String(Math.floor(x / 60)).padStart(2, '0') + ':' + String(x % 60).padStart(2, '0');
  const hc = t => { const [h, m] = t.split(':'); return (+h) + 'h' + (m === '00' ? '' : m); };
  const H0 = 4 * 60, H1 = 20 * 60, OUV = [mn('07:00'), mn('18:30')], NOW = mn('10:20');
  const X = x => ((x - H0) / (H1 - H0) * 100).toFixed(3) + '%';
  const W = (a, b) => ((b - a) / (H1 - H0) * 100).toFixed(3) + '%';
  const groupe = p => p.secteurs.length ? sect(p.secteurs[0]).nom : p.postes.includes('Vente') ? 'Vente' : p.postes.length ? p.postes[0] : 'Sans poste';
  const ORDRE = [...SECT.map(x => x.nom), 'Vente', 'Responsable', 'Sans poste'];
  const GROUPES = () => ORDRE.map(g => [g, EQ.filter(p => groupe(p) === g)]).filter(([, L]) => L.length);
  /** Les intervalles fusionnés d'une liste de services [début, fin] en minutes. */
  const fusion = L => { const r = []; L.slice().sort((a, b) => a[0] - b[0]).forEach(([a, b]) => { const d = r[r.length - 1]; if (d && a <= d[1]) { d[1] = Math.max(d[1], b); } else { r.push([a, b]); } }); return r; };
  const trous = (L, a, b) => { const r = []; let c = a; fusion(L).forEach(([x, y]) => { if (x > c) { r.push([c, Math.min(x, b)]); } c = Math.max(c, y); }); if (c < b) { r.push([c, b]); } return r.filter(([x, y]) => y > x); };
  /** Les linéaires : les secteurs tenus par l'équipe, puis le comptoir. */
  const LINEAIRES = () => [...SECT.filter(x => EQ.some(p => p.secteurs.includes(x.id))).map(x => [x.nom, p => p.secteurs.includes(x.id), false]), ['Vente (comptoir)', p => p.postes.includes('Vente'), true]];
  const couverture = (j, f) => EQ.filter(f).flatMap(p => svs(p.nom, j)).map(([a, b]) => [mn(a), mn(b)]);
  const heuresAxe = (pas) => { let h = ''; for (let t = H0; t <= H1; t += 60) { h += `<span class="pg-t" style="left:${X(t)}">${(t / 60) % pas === 0 ? String(t / 60).padStart(2, '0') : ''}</span>`; } return h; };
  const fondJour = (avecNow) => { let h = `<span class="pg-ouv" style="left:${X(OUV[0])};width:${W(OUV[0], OUV[1])}"></span>`; for (let t = H0 + 60; t < H1; t += 60) { h += `<span class="pg-g${t % 240 === 0 ? ' f' : ''}" style="left:${X(t)}"></span>`; } if (avecNow) { h += `<span class="pg-now" style="left:${X(NOW)}"><b>${hh(NOW)}</b></span>`; } return h; };
  const TICKETS = { 7: 14, 8: 32, 9: 28, 10: 24, 11: 30, 12: 46, 13: 34, 14: 16, 15: 18, 16: 26, 17: 30, 18: 12 };

  /* --- Le budget d'heures (23/11/2026, « le budget heures semaine est de 17 % ; au-dessus un marqueur orange, rouge
   * au-dessus de 20 %, noir au-dessus de 22 % (variables) ») : les frais de personnel du planning face au CA prévu
   * (l'objectif du magasin). La cible est celle du réseau (personnelCible, Catalogue des KPI) ; les paliers se règlent. */
  const CA = [2900, 3300, 3600, 3300, 3900, 5100, 3300];   // le CA prévu de chaque jour (objectif) — illustratif
  const PALIERS = { cible: 17, rouge: 20, noir: 22 };
  const niveau = x => x <= PALIERS.cible ? 'ok' : x <= PALIERS.rouge ? 'orange' : x <= PALIERS.noir ? 'rouge' : 'noir';
  const fP = x => x.toFixed(1).replace('.', ',') + ' %';
  const marq = (x, cl) => `<span class="eqm eqm-${niveau(x)}${cl ? ' ' + cl : ''}">${fP(x)}</span>`;
  const hJ = j => EQ.reduce((a, p) => a + svs(p.nom, j).reduce((b, s) => b + duree(s), 0), 0);
  const cJ = j => EQ.reduce((a, p) => a + svs(p.nom, j).reduce((b, s) => b + duree(s), 0) * p.t, 0);
  const NIV = { ok: 'dans la cible', orange: 'au-dessus de la cible', rouge: 'au-dessus de 20 %', noir: 'au-dessus de 22 %' };
  /** La jauge : la part du personnel, ses paliers, et les deux leviers pour revenir à la cible. */
  function jauge(titre, h, cout, ca) {
    const pct = cout / ca * 100, moy = cout / h, bH = PALIERS.cible / 100 * ca / moy, bC = PALIERS.cible / 100 * ca / h;
    const A = 10, B = 26, x = v => ((Math.min(B, Math.max(A, v)) - A) / (B - A) * 100).toFixed(2) + '%';
    const zone = (a, b, c) => `<span class="bj-z ${c}" style="left:${x(a)};width:calc(${x(b)} - ${x(a)})"></span>`;
    return `<section class="card bj" aria-label="${esc(titre)}"><div class="bj-t"><h2>${esc(titre)}</h2><span class="sub">les frais de personnel du planning face au CA prévu (l’objectif du magasin) · cible du réseau ${PALIERS.cible} %</span></div>
      <div class="bj-l"><div class="bj-v">${marq(pct, 'gros')}<span class="bj-n"><b>${NIV[niveau(pct)]}</b>${fE(cout)} de personnel pour ${fE(ca)} de CA prévu</span></div>
        <div class="bj-g"><div class="bj-piste">${zone(A, PALIERS.cible, 'ok')}${zone(PALIERS.cible, PALIERS.rouge, 'orange')}${zone(PALIERS.rouge, PALIERS.noir, 'rouge')}${zone(PALIERS.noir, B, 'noir')}<span class="bj-m" style="left:${x(pct)}"><i></i></span></div>
          <div class="bj-ech"><span style="left:${x(A)}">${A} %</span><span class="c" style="left:${x(PALIERS.cible)}">${PALIERS.cible} % cible</span><span style="left:${x(PALIERS.rouge)}">${PALIERS.rouge} %</span><span style="left:${x(PALIERS.noir)}">${PALIERS.noir} %</span></div></div>
        <div class="bj-r">${pct > PALIERS.cible ? `<span class="t">Pour revenir à ${PALIERS.cible} %</span><span class="lv"><b>−${fH(Math.round((h - bH) * 2) / 2)}</b> au coût moyen de ${String(moy.toFixed(2)).replace('.', ',')} €/h <span class="sub">budget ${fH(Math.floor(bH * 2) / 2)} au lieu de ${fH(h)}</span></span><span class="lv ou">ou <b>${String(bC.toFixed(2)).replace('.', ',')} €/h</b> de coût moyen pour les mêmes heures</span>` : `<span class="t">Dans la cible</span><span class="lv">marge : ${fH(Math.floor((bH - h) * 2) / 2)} encore possibles</span>`}</div></div></section>`;
  }

  /** Les temps morts d'un lundi : la moyenne des 4 derniers lundis du module Temps morts (trous de 10 min et plus). */
  const TM = [['09:40', '10:05'], ['14:10', '15:00'], ['15:35', '15:55'], ['17:50', '18:10']];
  const dm = m => m >= 60 ? Math.floor(m / 60) + ' h ' + String(m % 60).padStart(2, '0') : m + ' min';

  /** La vue Jour ; `edit` : un service en cours de modification sur son linéaire ({nom, i, de, a}). */
  function planoJour(edit) {
    const j = 0;
    const S = nom => { const L = svs(nom, j); return edit && edit.nom === nom ? L.map((x, i) => i === edit.i ? [edit.de, edit.a] : x) : L; };
    const dureeP = p => S(p.nom).reduce((b, x) => b + duree(x), 0);
    const presents = EQ.filter(p => S(p.nom).length), repos = EQ.filter(p => !S(p.nom).length);
    const hTot = presents.reduce((a, p) => a + dureeP(p), 0), cout = presents.reduce((a, p) => a + dureeP(p) * p.t, 0);
    const cov = f => EQ.filter(f).flatMap(p => S(p.nom)).map(([x, y]) => [mn(x), mn(y)]);
    const q = []; for (let t = H0; t < H1; t += 15) { q.push([t, presents.reduce((a, p) => a + S(p.nom).filter(([x, y]) => mn(x) <= t && mn(y) > t).length, 0)]); }
    const pic = Math.max(...q.map(x => x[1])), picQ = q.filter(x => x[1] === pic), picDe = picQ[0][0], picA = picQ[picQ.length - 1][0] + 15;
    const venteTrous = trous(cov(p => p.postes.includes('Vente')), OUV[0], OUV[1]);
    const vides = LINEAIRES().filter(([, f, comptoir]) => !comptoir && !cov(f).length);
    const tmMin = TM.reduce((a, [x, y]) => a + mn(y) - mn(x), 0);
    // Le personnel présent pendant les temps morts (personnes × minutes).
    const tmPers = TM.reduce((a, [x, y]) => a + q.filter(([t]) => t >= mn(x) && t < mn(y)).reduce((b, [, n]) => b + n * 15, 0), 0);
    const ligne = p => {
      const L = S(p.nom), orig = svs(p.nom, j);
      const barres = L.map(([a, b], i) => {
        const A = mn(a), B = mn(b), en = !edit && A <= NOW && B > NOW, sel = edit && edit.nom === p.nom && edit.i === i;
        const fant = sel ? `<span class="pg-fant" style="left:${X(mn(orig[i][0]))};width:${W(mn(orig[i][0]), mn(orig[i][1]))}"></span>` : '';
        return fant + `<span class="pg-bar${en ? ' en' : ''}${sel ? ' sel' : ''}" style="left:${X(A)};width:${W(A, B)}">${sel ? '<span class="pg-poi g"></span>' : ''}<b>${a}–${b}</b><i>${fH(duree([a, b]))}${en ? ' · en poste' : ''}</i>${sel ? '<span class="pg-poi d"></span>' : ''}</span>`;
      }).join('');
      let pop = '';
      if (edit && edit.nom === p.nom) {
        const o = orig[edit.i], dH = duree([edit.de, edit.a]) - duree(o), avant = svs(p.nom, j);
        const coutAvant = EQ.reduce((a, x) => a + svs(x.nom, j).reduce((b, y) => b + duree(y), 0) * x.t, 0);
        const trAvant = trous(EQ.filter(x => x.postes.includes('Vente')).flatMap(x => svs(x.nom, j)).map(([x, y]) => [mn(x), mn(y)]), OUV[0], OUV[1]);
        pop = `<div class="pg-pop" style="left:${X(mn(edit.de))}" role="dialog" aria-label="Modifier le service">
          <div class="pg-pop-t"><b>${esc(p.nom)}</b><span class="sub">${esc(p.postes.join(', '))} · lundi 23</span></div>
          <div class="pg-pop-h"><label class="field">De <input value="${edit.de}" size="5"></label><span>→</span><label class="field">à <input value="${edit.a}" size="5"></label><span class="d">${fH(duree([edit.de, edit.a]))} <em>(${dH > 0 ? '+' : ''}${fH(dH)})</em></span></div>
          <ul class="pg-pop-e">
            ${trAvant.length && !venteTrous.length ? `<li class="ok">Comptoir couvert toute la journée : le trou de ${trAvant.map(([x, y]) => hh(x) + ' à ' + hh(y)).join(', ')} disparaît</li>` : ''}
            <li>Budget du jour ${marq(coutAvant / CA[j] * 100)} → ${marq(cout / CA[j] * 100)}</li>
            <li class="mu">${fE(cout - coutAvant)} de plus · ${fH(hTot)} au planning ce jour</li>
          </ul>
          <div class="pg-pop-a"><button type="button" class="btn btn--sm btn--danger">Supprimer</button><span style="flex:1"></span><button type="button" class="btn btn--sm btn--ghost">Annuler</button><button type="button" class="btn btn--sm btn--primary">Enregistrer</button></div></div>`;
      }
      const vide = !L.length ? (edit ? '<span class="pg-repos">repos · <u>glisser pour ajouter un service</u></span>' : '<span class="pg-repos">repos</span>') : '';
      return `<div class="pg-l${L.length ? '' : ' repos'}${pop ? ' a-pop' : ''}"><div class="pg-n"><span class="eq-av">${ini(p.nom)}</span><span><b>${esc(p.nom)}</b><span class="sub">${esc(p.postes.join(', ') || 'aucun poste')}</span></span></div><div class="pg-tl">${barres}${vide}${pop}</div></div>`;
    };
    const corps = GROUPES().map(([g, L]) => `<div class="pg-gr"><div class="pg-n">${esc(g)} · ${L.filter(p => S(p.nom).length).length}/${L.length}</div><div class="pg-tl"></div></div>${L.map(ligne).join('')}`).join('');
    const yP = n => (64 - n / 6 * 58).toFixed(1);
    let d = `M0 ${yP(q[0][1])}`; q.forEach(([t, n]) => { const x = ((t - H0) / (H1 - H0) * 1000).toFixed(1); d += ` H${x} V${yP(n)}`; }); d += ' H1000';
    const tk = Object.entries(TICKETS).map(([h, n]) => { const x0 = (h * 60 - H0) / (H1 - H0) * 1000; return `<rect x="${(x0 + 8).toFixed(1)}" y="${(64 - n / 50 * 58).toFixed(1)}" width="${(1000 / 16 - 16).toFixed(1)}" height="${(n / 50 * 58).toFixed(1)}" rx="2"></rect>`; }).join('');
    const piste = ([nom, f, comptoir]) => {
      const cv = fusion(cov(f)), tr = comptoir ? trous(cov(f), OUV[0], OUV[1]) : [];
      const n = EQ.filter(f).filter(p => S(p.nom).length).length;
      return `<div class="pg-l pg-li"><div class="pg-n">${esc(nom)}<span class="sub">${cv.length ? n + ' pers. · ' + cv.map(([a, b]) => hc(hh(a)) + '–' + hc(hh(b))).join(', ') : 'personne aujourd’hui'}</span></div><div class="pg-tl">${cv.length ? cv.map(([a, b]) => `<span class="pg-cv" style="left:${X(a)};width:${W(a, b)}"></span>`).join('') : `<span class="pg-trou plein" style="left:0;width:100%"><b>personne de ce secteur aujourd’hui</b></span>`}${tr.map(([a, b]) => `<span class="pg-trou" style="left:${X(a)};width:${W(a, b)}"><b>${hh(a)}–${hh(b)} · personne</b></span>`).join('')}</div></div>`;
    };
    const tmLigne = `<div class="pg-l pg-li pg-tml"><div class="pg-n">Temps morts<span class="sub">${TM.length} · ${dm(tmMin)} sans client · 4 derniers lundis</span></div><div class="pg-tl">${TM.map(([a, b]) => `<span class="pg-tmb" style="left:${X(mn(a))};width:${W(mn(a), mn(b))}"><b>${mn(b) - mn(a)} min</b></span>`).join('')}</div></div>`;
    const fond = fondJour(!edit) + TM.map(([a, b]) => `<span class="pg-tmz" style="left:${X(mn(a))};width:${W(mn(a), mn(b))}"></span>`).join('');
    const tete = `<div class="pg-l pg-axe"><div class="pg-n">Lundi 23 · ${presents.length} pers. au planning</div><div class="pg-tl">${heuresAxe(1)}${edit ? '' : `<span class="pg-nowax" style="left:${X(NOW)}">${hh(NOW)}</span>`}${TM.map(([a, b]) => `<span class="pg-tmax" style="left:${X(mn(a))};width:${W(mn(a), mn(b))}" title="Temps mort ${a}–${b}"></span>`).join('')}</div></div>`;
    const kpis = `<section class="card eq-kpis"><div class="kpi-row">
        <div class="kpi"><div class="kpi__value">${presents.length}</div><div class="kpi__label">Au planning aujourd’hui</div><div class="kpi__note">au repos : ${repos.map(p => p.nom.split(' ')[0]).join(', ')}</div></div>
        <div class="kpi"><div class="kpi__value">${fH(hTot)}</div><div class="kpi__label">Heures du jour</div><div class="kpi__note">coût estimé ${fE(cout)}</div></div>
        <div class="kpi"><div class="kpi__value">${marq(cout / CA[j] * 100, 'gros')}</div><div class="kpi__label">Personnel / CA prévu</div><div class="kpi__note">${fE(cout)} pour ${fE(CA[j])} prévus</div></div>
        <div class="kpi"><div class="kpi__value">${dm(tmMin)}</div><div class="kpi__label">Temps morts</div><div class="kpi__note">${fH(Math.round(tmPers / 30) / 2)} de personnel pendant ces creux</div></div>
        <div class="kpi"><div class="kpi__value">${venteTrous.length + vides.length}${venteTrous.length + vides.length ? '<span class="tag-warn">à vérifier</span>' : ''}</div><div class="kpi__label">Trous dans les linéaires</div><div class="kpi__note">${[...vides.map(v => v[0] + ' vide'), ...venteTrous.map(([a, b]) => 'comptoir ' + hh(a) + '–' + hh(b))].join(' · ') || 'aucun'}</div></div>
      </div></section>`;
    const barre = `<div class="eq-pbar"><span class="eq-semnav"><button type="button" class="btn btn--icon" aria-label="Jour précédent">‹</button><span>Lundi 23 novembre 2026</span><button type="button" class="btn btn--icon" aria-label="Jour suivant">›</button></span><button type="button" class="btn btn--sm btn--ghost">Aujourd’hui</button><span class="sp"></span><div class="seg" role="group" aria-label="Affichage"><button type="button" class="is-on">Jour</button><button type="button">Semaine</button><button type="button">Tableau</button></div>${edit ? '<span class="pg-modif">1 modification non enregistrée</span>' : '<button type="button" class="btn">Modifier les horaires</button>'}<button type="button" class="btn">Imprimer le planning</button></div>`;
    const ins = edit ? '' : `<div class="eq-alerte pg-ins"><b>Midi, le pic du lundi (${TICKETS[12]} tickets), tombe dans le trou du comptoir</b> : personne au poste Vente de ${venteTrous.map(([a, b]) => hh(a) + ' à ' + hh(b)).join(', ')}. À l’inverse, le temps mort de 14:10 à 15:00 (50 min sans client) a ${q.find(([t]) => t === mn('14:15'))[1]} personnes au planning. Et le Snacking n’a personne aujourd’hui : Fatima est au repos.</div>`;
    return cadre('planning', `${barre}${kpis}${edit ? '' : jauge('Budget d’heures du jour', hTot, cout, CA[j])}
      <section class="card pg${edit ? ' edition' : ''}" aria-label="Planogramme du lundi 23 novembre"><div class="pg-fond">${fond}</div>${tete}${corps}
        <div class="pg-sep"><div class="pg-n">Les linéaires</div><div class="pg-tl"><span class="pg-leg"><i class="l"></i>personnes au planning</span><span class="pg-leg"><i class="b"></i>tickets, moyenne des 4 derniers lundis</span><span class="pg-leg"><i class="m"></i>temps mort (10 min et plus sans ticket)</span><span class="pg-leg"><i class="o"></i>ouvert 07:00–18:30</span></div></div>
        <div class="pg-l pg-pres"><div class="pg-n">Présence<span class="sub">pic ${pic} pers. · midi ${TICKETS[12]} tickets</span></div><div class="pg-tl"><svg viewBox="0 0 1000 66" preserveAspectRatio="none" aria-hidden="true"><g class="tk">${tk}</g><path class="ar" d="${d} V66 H0 Z"></path><path class="ln" d="${d}"></path></svg></div></div>
        ${tmLigne}${LINEAIRES().map(piste).join('')}
      </section>${ins}
      <p class="eq-note">${edit ? 'Modifier sur le linéaire : glisser une barre la déplace, ses bords changent les heures, glisser sur une ligne vide ajoute un service ; la fenêtre montre l’effet sur le comptoir, les secteurs et le budget avant d’enregistrer au panel.' : 'Les barres sont les services du planning du panel ; « Modifier les horaires » les rend déplaçables. Les temps morts viennent de l’onglet Temps morts : le jour même une fois passé, sinon la moyenne des 4 mêmes jours d’avant.'}</p>`);
  }

  function planoSemaine() {
    const tot = EQ.reduce((a, p) => a + p.h, 0), cout = EQ.reduce((a, p) => a + p.h * p.t, 0), caS = CA.reduce((a, b) => a + b, 0);
    const jourCol = (j, cont, cls) => `<div class="pg-j${j === 0 ? ' auj' : ''}${cls ? ' ' + cls : ''}">${cont}</div>`;
    const fondMini = `<span class="pg-ouv" style="left:${X(OUV[0])};width:${W(OUV[0], OUV[1])}"></span><span class="pg-g f" style="left:${X(8 * 60)}"></span><span class="pg-g f" style="left:${X(12 * 60)}"></span><span class="pg-g f" style="left:${X(16 * 60)}"></span>`;
    const tete = `<div class="pgs-l pgs-tete"><div class="pg-n">Employé</div>${JOURS.map((d, j) => { const n = EQ.filter(p => svs(p.nom, j).length).length, h = EQ.reduce((a, p) => a + svs(p.nom, j).reduce((b, x) => b + duree(x), 0), 0); return jourCol(j, `<b>${d}</b><span class="sub">${n} pers. · ${fH(h)}</span>${marq(cJ(j) / CA[j] * 100, 'eqm-j')}<span class="pgs-ax"><i style="left:${X(8 * 60)}">8</i><i style="left:${X(12 * 60)}">12</i><i style="left:${X(16 * 60)}">16</i></span>`); }).join('')}<div class="pg-tot">Semaine</div></div>`;
    const ligne = p => `<div class="pgs-l"><div class="pg-n"><span class="eq-av">${ini(p.nom)}</span><span><b>${esc(p.nom)}</b><span class="sub">${esc(p.postes.join(', ') || 'aucun poste')}</span></span></div>${JOURS.map((_, j) => jourCol(j, `<div class="pgs-tl">${fondMini}${svs(p.nom, j).map(([a, b]) => `<span class="pg-bar" style="left:${X(mn(a))};width:${W(mn(a), mn(b))}"></span><span class="pgs-lab" style="left:${X(mn(a))}">${hc(a)}–${hc(b)}</span>`).join('')}</div>`)).join('')}<div class="pg-tot">${fH(p.h)}<span class="sub">${fE(p.h * p.t)}</span></div></div>`;
    const corps = GROUPES().map(([g, L]) => `<div class="pgs-l pgs-gr"><div class="pg-n">${esc(g)}</div></div>${L.map(ligne).join('')}`).join('');
    const piste = ([nom, f, comptoir]) => `<div class="pgs-l pgs-li"><div class="pg-n">${esc(nom)}</div>${JOURS.map((_, j) => { const cv = fusion(couverture(j, f)), tr = comptoir ? trous(couverture(j, f), OUV[0], OUV[1]) : []; return jourCol(j, `<div class="pgs-tl">${fondMini}${cv.map(([a, b]) => `<span class="pg-cv" style="left:${X(a)};width:${W(a, b)}"></span>`).join('')}${!cv.length ? '<span class="pg-trou plein" style="left:0;width:100%"><b>vide</b></span>' : ''}${tr.map(([a, b]) => `<span class="pg-trou" style="left:${X(a)};width:${W(a, b)}" title="${hh(a)}–${hh(b)}"></span>`).join('')}</div>${tr.length ? `<span class="pgs-tr">${tr.map(([a, b]) => hc(hh(a)) + '–' + hc(hh(b))).join(', ')}</span>` : ''}`); }).join('')}<div class="pg-tot"></div></div>`;
    const nTrous = JOURS.reduce((a, _, j) => a + LINEAIRES().reduce((b, [, f, c]) => b + (c ? trous(couverture(j, f), OUV[0], OUV[1]).length : (couverture(j, f).length ? 0 : 1)), 0), 0);
    return cadre('planning', `<div class="eq-pbar"><span class="eq-semnav"><button type="button" class="btn btn--icon" aria-label="Semaine précédente">‹</button><span>Semaine du 23 au 29 novembre 2026</span><button type="button" class="btn btn--icon" aria-label="Semaine suivante">›</button></span><button type="button" class="btn btn--sm btn--ghost">Cette semaine</button><span class="sp"></span><div class="seg" role="group" aria-label="Affichage"><button type="button">Jour</button><button type="button" class="is-on">Semaine</button><button type="button">Tableau</button></div><button type="button" class="btn">Imprimer le planning</button></div>
      <section class="card eq-kpis"><div class="kpi-row">
        <div class="kpi"><div class="kpi__value">${EQ.filter(p => p.h).length}</div><div class="kpi__label">Employés au planning</div><div class="kpi__note">${EQ.reduce((a, p) => a + p.nSv, 0)} services</div></div>
        <div class="kpi"><div class="kpi__value">${fH(tot)}</div><div class="kpi__label">Heures planifiées</div><div class="kpi__note">budget ${fH(Math.floor(PALIERS.cible / 100 * caS / (cout / tot) * 2) / 2)} à ${PALIERS.cible} %</div></div>
        <div class="kpi"><div class="kpi__value">${marq(cout / caS * 100, 'gros')}</div><div class="kpi__label">Personnel / CA prévu</div><div class="kpi__note">${fE(cout)} pour ${fE(caS)} prévus</div></div>
        <div class="kpi"><div class="kpi__value">${nTrous}<span class="tag-warn">à vérifier</span></div><div class="kpi__label">Trous dans les linéaires</div><div class="kpi__note">secteurs vides, comptoir sans vendeuse</div></div>
      </div></section>
      ${jauge('Budget d’heures de la semaine', tot, cout, caS)}
      <section class="card pgs" aria-label="Planogramme de la semaine">${tete}${corps}<div class="pgs-l pgs-sep"><div class="pg-n">Les linéaires</div><div class="pgs-leg"><span class="pg-leg"><i class="c"></i>quelqu’un du secteur est là</span><span class="pg-leg"><i class="t"></i>trou (comptoir pendant l’ouverture, ou secteur vide)</span><span class="pg-leg"><i class="o"></i>ouverture 07:00–18:30</span></div></div>${LINEAIRES().map(piste).join('')}</section>
      <p class="eq-note">Paliers du budget : jusqu’à ${PALIERS.cible} %, la cible du réseau (Catalogue des KPI) ; orange au-dessus ; rouge au-dessus de ${PALIERS.rouge} % ; noir au-dessus de ${PALIERS.noir} % — les trois se règlent. Le CA prévu est l’objectif du magasin, jour par jour. Chaque jour, l’axe va de 04:00 à 20:00 (repères à 8 h, 12 h et 16 h). Un clic sur un jour l’ouvre en grand (vue Jour) ; « Tableau » garde la grille des heures.</p>`);
  }

  function monter(v) {
    rail(); document.body.classList.add('eq-on');
    const r = document.getElementById('db-rail'); if (r) { r.scrollTop = 0; }
    let el = document.getElementById('eq-maq');
    if (!el) { el = document.createElement('div'); el.id = 'eq-maq'; document.body.appendChild(el); }
    el.innerHTML = v === 'modale' ? modale('Bastien Lambert', 'Traiteur') : v === 'plano-jour' ? planoJour() : v === 'plano-edition' ? planoJour({ nom: 'Laura Claes', i: 0, de: '07:00', a: '12:30' }) : v === 'plano-semaine' ? planoSemaine() : v === 'planning' ? planning() : v === 'employes' ? employes() : resp('Bastien Lambert');
    el.scrollTop = 0;
    document.querySelectorAll('body > .eq-voile').forEach(n => n.remove());
    // La fenêtre se pose sur toute la page, rail compris.
    const voile = el.querySelector('.eq-voile'); if (voile) { document.body.appendChild(voile); }
    return v + ' monté';
  }
  window.EQ = { monter, rail };
})();
