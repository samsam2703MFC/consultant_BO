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
    'Laura Claes': [['07:00', '13:00'], null, ['12:30', '18:30'], null, ['12:30', '18:30'], ['13:00', '18:30'], ['13:00', '18:00']],
    'Marc Lefèvre': [null, ['04:00', '12:00'], ['04:00', '12:00'], null, null, ['04:00', '12:00'], ['04:00', '11:00']],
  };
  const duree = s => { const m = t => +t.slice(0, 2) * 60 + +t.slice(3); return (m(s[1]) - m(s[0])) / 60; };
  EQ.forEach(p => { p.h = (P[p.nom] || []).filter(Boolean).reduce((a, s) => a + duree(s), 0); p.nSv = (P[p.nom] || []).filter(Boolean).length; });

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
    const perJour = JOURS.map((_, j) => EQ.filter(p => (P[p.nom] || [])[j]));
    const hJour = JOURS.map((_, j) => EQ.reduce((a, p) => a + ((P[p.nom] || [])[j] ? duree(P[p.nom][j]) : 0), 0));
    const totH = EQ.reduce((a, p) => a + p.h, 0), totC = EQ.reduce((a, p) => a + p.h * p.t, 0);
    const vides = JOURS.map((_, j) => SECT.filter(s => EQ.some(p => p.secteurs.includes(s.id)) && !perJour[j].some(p => p.secteurs.includes(s.id))));
    const zone = p => p.secteurs.length ? sect(p.secteurs[0]).nom : (p.postes[0] || 'sans poste');
    const lignes = EQ.map((p, i) => `<tr><td><div class="eq-pers"><span class="eq-av">${ini(p.nom)}</span><span><b>${esc(p.nom)}</b><span class="sub">${esc(p.postes.join(', ') || 'aucun poste')}</span></span></div></td>${JOURS.map((_, j) => {
      const s = (P[p.nom] || [])[j];
      if (!s) { return `<td class="${j === auj ? 'auj' : ''}">${i === 6 && j === 3 ? '<span class="eq-add">+ Ajouter</span>' : '<span class="eq-tiret">—</span>'}</td>`; }
      return `<td class="${j === auj ? 'auj' : ''}"><span class="eq-sv">${s[0]}–${s[1]}<small>${esc(zone(p))}</small></span></td>`;
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

  function monter(v) {
    rail(); document.body.classList.add('eq-on');
    const r = document.getElementById('db-rail'); if (r) { r.scrollTop = 0; }
    let el = document.getElementById('eq-maq');
    if (!el) { el = document.createElement('div'); el.id = 'eq-maq'; document.body.appendChild(el); }
    el.innerHTML = v === 'modale' ? modale('Bastien Lambert', 'Traiteur') : v === 'planning' ? planning() : v === 'employes' ? employes() : resp('Bastien Lambert');
    el.scrollTop = 0;
    document.querySelectorAll('body > .eq-voile').forEach(n => n.remove());
    // La fenêtre se pose sur toute la page, rail compris.
    const voile = el.querySelector('.eq-voile'); if (voile) { document.body.appendChild(voile); }
    return v + ' monté';
  }
  window.EQ = { monter, rail };
})();
