/* Cockpit CEO — gabarits de la section Promotions (26/10/2026) : le Tableau d'analyse et l'Édition. Les valeurs viennent
 * de promotions-vals.js (`c.prT`, `c.prE`) ; le style de promotions.css (préfixe ptb-). Maquette A de
 * docs/maquettes/promotions-cockpit. */

const ICO_DOTS = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>';

export function tplPromoTableau(c, x){
  const { esc } = x;
  const T = c.prT || {};
  const mu = t => `<span class="ptb-mu">${t}</span>`;
  const barre = `<div class="ptb-barre">${T.lu ? `<span class="ptb-mu">${esc(T.lu)}</span>` : ''}<button ${x.A(T.relire)} class="ptb-lien"${T.enCours ? ' disabled' : ''}>${T.enCours ? 'lecture…' : '↻ relire le panel'}</button>
    <span style="flex:1"></span><button ${x.A(T.nouveauBon)} class="ptb-btn" title="un bon et la promotion qu’il déclenche, dans le même assistant">+ Nouveau bon</button><button ${x.A(T.nouvelle)} class="ptb-btn prim">+ Nouvelle promotion</button></div>`;
  if (T.chargement) { return `<div data-screen="promoTableau" class="ptb">${barre}<div class="ptb-carte ptb-attente">Lecture des promotions de tous les magasins…</div></div>`; }
  if (T.motif) { return `<div data-screen="promoTableau" class="ptb">${barre}<div class="ptb-carte ptb-attente ko">${esc(T.motif)}</div></div>`; }
  const kpis = `<div class="ptb-kpis">${(T.kpis || []).map(k => `<div class="ptb-kpi${k.cls ? ' ' + k.cls : ''}"><div class="ptb-lab">${esc(k.lab)}</div><div class="v">${esc(k.v)}</div><div class="s">${esc(k.s)}</div></div>`).join('')}</div>`;
  const sel = (l, fn) => `<select class="ptb-in" ${x.C(fn)}>${l.map(o => `<option value="${esc(o.v)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>`;
  const filtres = `<div class="ptb-filtres">
      <div class="ptb-ong">${(T.filtres || []).map(o => `<button ${x.A(o.choisir)} class="${o.on ? 'on' : ''}">${esc(o.nom)} <small>${o.n}</small></button>`).join('')}</div>
      ${sel(T.magasins || [], T.setShop)}${sel(T.origines || [], T.setOrig)}${sel(T.sortes || [], T.setSorte)}
      <span class="ptb-seg">${(T.jours || []).map(o => `<button ${x.A(o.choisir)} class="${o.on ? 'on' : ''}">${esc(o.nom)}</button>`).join('')}</span>
      <input class="ptb-in ptb-cherche" type="search" placeholder="Chercher une promotion…" value="${esc(T.q || '')}" ${x.I(T.setQ)}></div>`;
  const th = (k, lib, cls, titre) => { const t = (T.tri || {})[k]; return `<th class="${cls || ''}${t ? ' tri' + (t.on ? ' on' : '') : ''}"${titre ? ` title="${esc(titre)}"` : ''}${t ? ' ' + x.A(t.choisir) : ''}>${lib}${t && t.on ? ' ▾' : ''}</th>`; };
  const NC = 13;
  const ligne = l => `<tr class="${l.menu ? 'ouvert' : ''}">
      <td>${l.marque ? `<span class="ptb-or r">Marque</span><div class="ptb-mag"${l.magTitre ? ` title="${esc(l.magTitre)}"` : ''}>${esc(l.mag)}</div>` : `<b>${esc(l.mag)}</b>`}</td>
      <td class="nm"><b>${esc(l.nom)}</b>${l.bon ? ' <span class="ptb-chip a">bon</span>' : ''}${l.sorte ? ` <span class="ptb-chip b">${esc(l.sorte)}</span>` : ''}<div class="ptb-regle">${esc(l.texte)}${l.creneaux ? ' · ' + esc(l.creneaux) : ''}</div></td>
      <td class="ou">${l.comptoir ? '<span class="ptb-bdg c">Comptoir</span>' : ''}${l.webshop ? '<span class="ptb-bdg w">Webshop</span>' : ''}${l.seuil ? `<span class="ptb-bdg s">dès ${esc(l.seuil)}</span>` : ''}</td>
      <td>${esc(l.du)} → ${l.au ? esc(l.au) : mu('sans fin')}</td>
      <td><span class="ptb-chip ${l.etatCls}">${esc(l.etat)}</span></td>
      <td class="r"><b>${l.fois != null ? esc(l.fois) : mu('—')}</b></td>
      <td class="r">${l.ventes != null ? esc(l.ventes) : mu('—')}</td>
      <td class="r">${l.poids != null ? esc(l.poids) : mu('—')}</td>
      <td class="r">${l.remise != null ? esc(l.remise) : mu('—')}</td>
      <td class="r">${l.web != null ? esc(l.web) : mu('—')}</td>
      <td class="r"${l.coefTitre ? ` title="${esc(l.coefTitre)}"` : ''}>${l.coef ? `<b class="${l.coefCls}">${esc(l.coef)}${l.coefCls === 'ko' ? ' ⚠' : ''}</b>` : mu('—')}</td>
      <td class="act">${l.basculer ? `<button ${x.A(l.basculer)} class="ptb-dots${l.menu ? ' on' : ''}" aria-label="actions" aria-expanded="${l.menu}">${ICO_DOTS}</button>` : ''}</td></tr>
    ${l.menu ? `<tr class="ptb-actions"><td colspan="${NC}">${l.actions.map(a => `<button ${x.A(a.go)} class="ptb-btn${a.ko ? ' ko' : ''}">${esc(a.nom)}</button>`).join('')}</td></tr>` : ''}`;
  const tableau = T.vide ? `<div class="ptb-vide">${esc(T.vide)}</div>` : `<div class="ptb-large"><table class="ptb-tab">
      <thead><tr><th>Magasin</th><th>Promotion · ce qu’elle donne</th><th>Où</th>${th('du', 'Période')}<th>Statut</th>${th('fois', 'Fois', 'r', 'les fois où la promotion s’applique sur un ticket')}${th('v', 'Ventes', 'r', 'ce que le client paie pour les pièces en promotion')}<th class="r" title="ventes de la promotion ÷ ventes lues du ou des magasins">Poids</th>${th('r', 'Remise', 'r')}<th class="r" title="part des fois sur un ticket du webshop">Web</th>${th('coef', 'Coef.', 'r', 'prix payé HT ÷ coût matière, le cas le plus serré')}<th></th></tr></thead>
      <tbody>${(T.lignes || []).map(ligne).join('')}</tbody>
      <tfoot><tr><td colspan="5"><b>${esc(T.total.n)}</b></td><td class="r"><b>${esc(T.total.fois)}</b></td><td class="r"><b>${esc(T.total.ventes)}</b></td><td class="r"><b>${esc(T.total.poids)}</b></td><td class="r"><b>${esc(T.total.remise)}</b></td><td class="r"><b>${esc(T.total.web)}</b></td><td></td><td></td></tr></tfoot></table></div>`;
  return `<div data-screen="promoTableau" class="ptb">${barre}${T.erreurPanel ? `<div class="ptb-avert">Panel : ${esc(T.erreurPanel)} — la liste peut dater.</div>` : ''}${kpis}
    <div class="ptb-carte">${filtres}${tableau}<div class="ptb-pied">${(T.notes || []).map(n => `<div>${esc(n)}</div>`).join('')}</div></div></div>`;
}

/* L'édition : les brouillons à reprendre, puis le cadre de l'assistant. Le cadre vient en dernier, seul dans son
 * conteneur : la fusion du rendu compare les nœuds par position — ce qui change au-dessus ne doit pas le recréer
 * (il se rechargerait et perdrait la promotion en cours). */
export function tplPromoEdition(c, x){
  const { esc } = x;
  const E = c.prE || {};
  const br = E.brouillons || [];
  return `<div data-screen="promoEdition" class="ptb">
    <div class="ptb-reprendre">${br.length ? `<span class="ptb-lab">Brouillons à reprendre</span>${br.map(b => `<button ${x.A(b.go)} class="ptb-pill"><b>${esc(b.nom)}</b> · ${esc(b.mag)}</button>`).join('')}` : `<span class="ptb-mu">${E.chargement ? 'Lecture des brouillons…' : 'Aucun brouillon à reprendre.'}</span>`}<span style="flex:1"></span><button ${x.A(E.tableau)} class="ptb-lien">Une promotion en caisse se modifie depuis le Tableau d’analyse (⋯)</button></div>
    <div class="ptb-cadre"><iframe id="pe-cadre" src="${esc(E.src)}" title="Assistant des promotions" style="width:100%;height:${E.haut}px;border:0;display:block;background:transparent"></iframe></div>
  </div>`;
}
