/* Cockpit CEO — gabarits de Vérification › Audit des flux et Fuites par magasin (lot 2, 31/10/2026).
 * Valeurs : verification-flux-vals.js (`c.vx`, `c.vfu`) ; style : verification.css (vr-, vx-).
 * Maquettes : docs/maquettes/verification (a : le réseau ; a-jour : un jour, produit par produit ; b : un magasin,
 * jour par jour ; g : un tableau par magasin, même structure). */

const barre100 = (b, w) => `<span class="vx-b100" style="width:${w || 110}px"><i class="c-v" style="width:${b[0]}%"></i><i class="c-p" style="width:${b[1]}%"></i><i class="c-i" style="width:${b[2]}%"></i><i class="c-n" style="width:${b[3]}%"></i></span>`;
const pill = (ton, txt) => `<span class="vx-p ${ton}">${txt}</span>`;
const LEG = `<span class="vx-leg"><span><i class="c-v"></i>vendu</span><span><i class="c-p"></i>poubelle déclarée</span><span><i class="c-i"></i>invendu gardé</span><span><i class="c-n"></i>non expliqué</span></span>`;

function tplBarre(v, x, avecShop){
  const { esc } = x, B = v.barre;
  return `<div class="vr-haut">
    <div class="vx-seg">${B.vues.map(o => `<button class="${o.on ? 'on' : ''}" ${x.A(o.choisir)}>${esc(o.nom)}</button>`).join('')}</div>
    <div class="vx-nav"><button ${x.A(B.avant)}>‹</button><span>${esc(B.periode)}</span><button ${x.A(B.apres)}${B.apresOff ? ' disabled' : ''}>›</button></div>
    ${avecShop ? `<select class="vr-sel" ${x.C(e => v.choisirShop(e.target.value))}>${v.magasins.map(m => `<option value="${esc(m.id)}"${m.on ? ' selected' : ''}>${esc(m.nom)}</option>`).join('')}</select>` : ''}
    <span class="vr-sp"></span>${v.manquants ? `<span class="vr-lu">${v.manquants} journée${v.manquants > 1 ? 's' : ''} encore à calculer (le cron les remplit chaque heure)</span>` : ''}
    <button class="vr-btn" ${x.A(v.rafraichir)}${v.relecture ? ' disabled' : ''}>${v.relecture ? 'Calcul…' : 'Relire'}</button></div>`;
}

function etat(v, x, ecran, txt){
  if (v.chargement) { return `<div data-screen="${ecran}" class="vr">${tplBarre(v, x, ecran === 'verification-flux')}<div class="vr-card vr-etat"><span class="vr-roue"></span>${txt}</div></div>`; }
  if (v.erreur) { return `<div data-screen="${ecran}" class="vr">${tplBarre(v, x, ecran === 'verification-flux')}<div class="vr-card vr-etat ko">${x.esc(v.erreur)}</div></div>`; }
  return null;
}

/** L'état de la mesure : sans cuissons validées, le produit est le plan, et l'écart un écart au plan. */
function bandeauMesure(L){
  if (!L || !L.estime) { return ''; }
  return `<div class="vx-mesure"><b>Pas encore une mesure : ${L.mesure} du disponible vient de cuissons validées</b> (cuissons validées ${L.cuissons}, clôtures enregistrées ${L.clotures} sur ${L.jours} journée${L.jours > 1 ? 's' : ''}). Sans cuissons validées, le « produit » est le plan de production — une prévision : l’écart non expliqué est alors un <b>écart au plan</b>, pas une fuite. Pour mesurer, chaque magasin valide ses cuissons à la sortie du four (Production › Validation et suivi) et enregistre sa clôture du soir ; l’écran juge les fuites dès que la moitié du disponible est mesurée. Les ventes et la poubelle déclarée, elles, sont réelles.</div>`;
}

const kpi = (k, val, s, ton) => `<div class="vx-kpi ${ton || ''}"><div class="k">${k}</div><div class="v">${val}</div><div class="s">${s}</div></div>`;
const libNE = L => L && L.estime ? 'Écart au plan' : 'Non expliqué';

/* --- Audit des flux ------------------------------------------------------------------------------------------------ */
export function tplVerifFlux(c, x){
  const { esc } = x, v = c.vx;
  if (!v) { return ''; }
  const e = etat(v, x, 'verification-flux', 'Calcul des journées : la clôture de chaque magasin, jour par jour… La première fois, jusqu’à vingt secondes ; le cron remplit le reste chaque heure.');
  if (e) { return e; }
  const R = v.shop ? v.mag : v.reseau;
  if (!R) { return `<div data-screen="verification-flux" class="vr">${tplBarre(v, x, true)}<div class="vr-card vr-etat">Aucune donnée.</div></div>`; }
  const kpis = `<div class="vx-kpis">
    ${kpi('Disponible', R.dispo, R.dispoPcs + ' pièces · gardé d’hier + produit, au prix de vente')}
    ${kpi('Vendu', R.vendu, R.venduE)}
    ${kpi('Poubelle', R.poubelle, R.poubelleE + ' déclarés en caisse', R.tonP)}
    ${kpi('Invendu gardé', R.invendu, R.invenduE + ' pour le lendemain', R.tonI)}
    ${kpi(libNE(R), R.nonExpl, R.nonExplE + (R.estime ? ' · produit = plan' : ' ni vendus, ni jetés, ni gardés'), R.estime ? 'mu' : R.tonN)}</div>`;
  let corps = '';
  if (!v.shop) {
    corps += `<div class="vr-card"><div class="vx-ct"><h3>Par magasin</h3><span class="vr-lu">en % du disponible · un clic ouvre le magasin</span><span class="vr-sp"></span>${LEG}</div>
      <div class="vr-tw"><table class="vr-t"><thead><tr><th class="g">Magasin</th><th>Disponible<small>gardé + produit</small></th><th>Vendu</th><th>Poubelle</th><th>Invendu<small>gardé</small></th><th>Non expliqué<small>ou écart au plan</small></th><th></th><th>Mesuré<small>cuissons validées</small></th><th class="g">Verdict</th></tr></thead><tbody>
      ${v.lignesMag.map(m => `<tr class="vx-clic" ${x.A(m.ouvrir)}><td class="g nom"><b>${esc(m.nom)}</b><small>${m.jours} journée${m.jours > 1 ? 's' : ''} · cuissons ${esc(m.cuissons)}</small></td><td>${m.dispo}</td><td>${pill('mu', m.vendu)}<small>${m.venduE}</small></td><td>${pill(m.tonP, m.poubelle)}<small>${m.poubelleE}</small></td><td>${pill(m.tonI, m.invendu)}<small>${m.invenduE}</small></td><td>${pill(m.estime ? 'mu' : m.tonN, m.nonExpl)}<small>${m.nonExplE}${m.estime ? ' · plan' : ''}</small></td><td>${barre100(m.barre, 96)}</td><td>${m.estime ? `<span class="vx-est">estimé</span>` : ''} ${m.mesure}</td><td class="g"><span class="vx-v ${m.verdict.ton}"><i></i>${esc(m.verdict.nom)}</span><span class="vx-why">${esc(m.verdict.pourquoi)}</span></td></tr>`).join('')}
      <tr class="tot"><td class="g">Réseau</td><td>${R.dispo}</td><td>${R.vendu}</td><td>${R.poubelle}</td><td>${R.invendu}</td><td>${R.nonExpl}</td><td>${barre100(R.barre, 96)}</td><td>${R.mesure}</td><td></td></tr></tbody></table></div>
      <div class="vr-pied">Disponible = gardé d’hier + produit (cuissons validées, sinon le plan). Non expliqué = disponible − vendu − poubelle déclarée − invendu gardé. À la semaine et au mois, la somme des journées rapportée à la somme des disponibles : le taux d’une journée moyenne. Seuils : non expliqué vert ≤ 3 %, orange ≤ 6 % ; poubelle vert ≤ 6 %, orange ≤ 9 % — une fuite ne se juge que sur des journées mesurées.</div></div>`;
    if (v.vue !== 'jour') {
      corps += `<div class="vr-card"><div class="vx-ct"><h3>${libNE(R)}, jour par jour</h3><span class="vr-lu">en % du disponible · gris : journée estimée (plan) · un clic ouvre le jour</span></div>
        <div class="vr-tw"><table class="vr-t vx-heat"><thead><tr><th class="g">Magasin</th>${v.jours.map(j => `<th>${esc(j.nom)}<small>${esc(j.jj)}</small></th>`).join('')}<th>Période</th></tr></thead><tbody>
        ${v.cases.map(r => `<tr><td class="g nom"><b>${esc(r.nom)}</b></td>${r.cases.map(k => `<td${k.ouvrir ? ` class="vx-clic" ${x.A(k.ouvrir)}` : ''}>${pill(k.ton, k.txt)}</td>`).join('')}<td><b>${r.total ? r.total.nonExpl : '—'}</b></td></tr>`).join('')}
        </tbody></table></div></div>`;
    }
  } else if (v.vue === 'jour') {
    const J = v.journee;
    corps += `<div class="vr-card"><div class="vx-ct"><h3>Le jour, produit par produit</h3><span class="vr-lu">${J ? `cuissons validées ${J.cuissonsValidees} / ${J.cuissons} · clôture ${J.clotureEnregistree ? 'enregistrée' : 'non enregistrée'}` : 'journée pas encore calculée'} · les plus gros écarts d’abord</span><span class="vr-sp"></span>${LEG}</div>
      ${(v.produits || []).length ? `<div class="vr-tw"><table class="vr-t"><thead><tr><th class="g">Produit</th><th>Gardé d’hier</th><th>Produit<small>sorti du four</small></th><th>Disponible</th><th>Vendu</th><th>Poubelle</th><th>Invendu<small>gardé</small></th><th>Non expliqué</th><th></th></tr></thead><tbody>
      ${v.produits.map(p => `<tr><td class="g nom"><b>${esc(p.nom)}</b><small>${esc(p.cat)}${p.prix ? ' · ' + esc(p.prix) : ''}</small></td><td>${p.garde0 === '0' ? '<span class="vr-mu">—</span>' : p.garde0}</td><td>${p.prod}${p.mesureP ? '' : ' <span class="vx-est">plan</span>'}</td><td><b>${p.dispoPcs}</b></td><td>${pill('mu', p.vendu)}<small>${p.venduPcs} pcs</small></td><td>${pill(p.tonP, p.poubelle)}<small>${p.poubPcs} pcs</small></td><td>${pill('mu', p.invendu)}<small>${p.invPcs} pcs</small></td><td>${pill(p.estime ? 'mu' : p.tonN, p.nonExpl)}<small>${p.nonExplPcs} pcs</small></td><td>${barre100(p.barre, 90)}</td></tr>`).join('')}
      </tbody></table></div>` : `<div class="vr-vide">Rien pour ce jour : la journée n’est pas encore calculée, ou le magasin n’avait rien au plan.</div>`}
      <div class="vr-pied">Gardé d’hier : la clôture de la veille. Produit : les cuissons validées (« plan » quand elles ne le sont pas). Vendu : tickets du panel, commandes et webshop compris. Poubelle : déclarée en caisse. Invendu : la clôture du soir (sa proposition quand elle n’est pas enregistrée). Ce qui reste est « non expliqué » : poubelle non déclarée, consommation de l’équipe, offert, vente hors caisse — ou, sur un produit estimé, un plan qui n’a pas été suivi.</div></div>`;
  } else {
    corps += `<div class="vr-card"><div class="vx-ct"><h3>Jour par jour</h3><span class="vr-lu">un clic ouvre le jour, produit par produit</span><span class="vr-sp"></span>${LEG}</div>
      <div class="vr-tw"><table class="vr-t"><thead><tr><th class="g">Jour</th><th>Disponible</th><th>Vendu</th><th>Poubelle</th><th>Invendu</th><th>${libNE(R)}</th><th></th><th>Mesuré</th></tr></thead><tbody>
      ${v.parJour.map(j => j.calcule ? `<tr class="vx-clic${j.dimanche ? ' vx-dim' : ''}" ${x.A(j.ouvrir)}><td class="g nom"><b>${esc(j.nom)}</b></td><td>${j.dispo}</td><td>${pill('mu', j.vendu)}</td><td>${pill(j.tonP, j.poubelle)}</td><td>${pill(j.tonI, j.invendu)}</td><td>${pill(j.estime ? 'mu' : j.tonN, j.nonExpl)}<small>${j.nonExplE}</small></td><td>${barre100(j.barre, 120)}</td><td>${j.estime ? '<span class="vx-est">estimé</span>' : j.mesure}</td></tr>` : `<tr><td class="g nom"><b>${esc(j.nom)}</b></td><td colspan="7" class="g vr-mu">pas encore calculé — le cron remplit chaque heure</td></tr>`).join('')}
      <tr class="tot"><td class="g">Période</td><td>${R.dispo}</td><td>${R.vendu}</td><td>${R.poubelle}</td><td>${R.invendu}</td><td>${R.nonExpl}</td><td>${barre100(R.barre, 120)}</td><td>${R.mesure}</td></tr></tbody></table></div></div>
      <div class="vr-card"><div class="vx-ct"><h3>Où ça fuit</h3><span class="vr-lu">par catégorie, sur la période · en % du disponible</span></div>
      <div class="vr-tw"><table class="vr-t"><thead><tr><th class="g">Catégorie</th><th>${libNE(R)}</th><th>Poubelle</th><th>Invendu</th><th>Vendu</th><th>Disponible</th></tr></thead><tbody>
      ${v.categories.map(k => `<tr><td class="g nom"><b>${esc(k.nom)}</b><small>${esc(k.groupe)}</small></td><td>${pill(k.estime ? 'mu' : k.tonN, k.nonExpl)}<small>${k.nonExplE}</small></td><td>${pill(k.tonP, k.poubelle)}</td><td>${k.invendu}</td><td>${k.vendu}</td><td>${k.dispo}</td></tr>`).join('')}
      </tbody></table></div></div>`;
  }
  return `<div data-screen="verification-flux" class="vr">${tplBarre(v, x, true)}${bandeauMesure(R)}${kpis}${corps}</div>`;
}

/* --- Fuites par magasin ---------------------------------------------------------------------------------------------- */
export function tplVerifFuites(c, x){
  const { esc } = x, v = c.vfu;
  if (!v) { return ''; }
  const e = etat(v, x, 'verification-fuites', 'Calcul des journées de chaque magasin… La première fois, jusqu’à vingt secondes ; le cron remplit le reste chaque heure.');
  if (e) { return e; }
  const R = v.reseau;
  const kpis = `<div class="vx-kpis">${v.kpis.map(k => kpi(esc(k.nom), k.deltaPct, k.deltaE + ' · ' + k.deltaPcs + ' pièces' + (k.estime ? ' · écart au plan' : ''), k.deltaTon)).join('')}${R ? kpi('Réseau', R.deltaPct, R.deltaE + (R.estime ? ' · écart au plan' : ''), R.deltaTon) : ''}</div>`;
  const matrice = `<div class="vr-card"><div class="vx-ct"><h3>Où ça fuit — catégories × magasins</h3><span class="vr-lu">delta en % de ce qui est sorti · gris : estimé (plan)</span></div>
    <div class="vr-tw"><table class="vr-t vx-heat"><thead><tr><th class="g">Catégorie</th>${v.entetes.map(n => `<th>${esc(n)}</th>`).join('')}<th>Réseau</th></tr></thead><tbody>
    ${v.matrice.map(r => `<tr><td class="g nom"><b>${esc(r.cat)}</b></td>${r.cases.map(k => `<td>${k.vide ? '<span class="vr-mu">—</span>' : pill(k.deltaTon, k.deltaPct) + `<small>${k.deltaE}</small>`}</td>`).join('')}<td>${r.reseau && !r.reseau.vide ? pill(r.reseau.deltaTon, r.reseau.deltaPct) + `<small>${r.reseau.deltaE}</small>` : '—'}</td></tr>`).join('')}
    </tbody></table></div></div>`;
  const tables = v.tables.map(m => `<div class="vr-card"><div class="vx-ct"><h3>${esc(m.nom)}</h3><span class="vr-lu">${m.jours} journée${m.jours > 1 ? 's' : ''} · cuissons validées ${esc(m.cuissons)}</span><span class="vr-sp"></span><span class="vx-v ${m.verdict.ton}"><i></i>${esc(m.verdict.nom)} · ${m.deltaPct} · ${m.deltaE}</span></div>
    ${m.pire.length ? `<div class="vx-lect">${m.estime ? 'Écart au plan' : 'Les plus grosses fuites'} : ${esc(m.pire.join(', '))}.</div>` : ''}
    <div class="vr-tw"><table class="vr-t"><thead>
      <tr class="vx-grp"><th></th><th colspan="4"><span>Les stocks — pièces</span></th><th colspan="2"><span>Ce qui est déclaré</span></th><th colspan="3"><span>Delta = sorti − ventes − poubelle</span></th></tr>
      <tr><th class="g">Catégorie / produit</th><th>Gardé d’hier<small>somme des matins</small></th><th>+ Produit<small>cuissons (ou plan)</small></th><th>− Gardé pour demain<small>somme des soirs</small></th><th>= Sorti</th><th>Ventes<small>caisse</small></th><th>Poubelle<small>déclarée</small></th><th>Delta</th><th>% du sorti</th><th>€<small>prix de vente</small></th></tr></thead><tbody>
      ${m.categories.map(k => `<tr class="vx-clic${k.vide ? ' vx-vide' : ''}" ${x.A(k.basculer)}><td class="g nom"><b>${k.ouvert ? '▾' : '▸'} ${esc(k.nom)}</b><small>${k.n} produit${k.n > 1 ? 's' : ''}</small></td><td>${k.garde0}</td><td>${k.prod}</td><td>${k.invPcs}</td><td><b>${k.sortiPcs}</b></td><td>${k.venduPcs}</td><td>${k.poubPcs}</td><td><b>${k.deltaPcs}</b></td><td>${k.vide ? '—' : pill(k.deltaTon, k.deltaPct)}</td><td><b>${k.vide ? '—' : k.deltaE}</b></td></tr>
        ${k.produits.map(p => `<tr class="vx-pr"><td class="g nom">${esc(p.nom)}<small>${esc(p.prix)}</small></td><td>${p.garde0}</td><td>${p.prod}</td><td>${p.invPcs}</td><td><b>${p.sortiPcs}</b></td><td>${p.venduPcs}</td><td>${p.poubPcs}</td><td><b>${p.deltaPcs}</b></td><td>${pill(p.deltaTon, p.deltaPct)}</td><td>${p.deltaE}</td></tr>`).join('')}`).join('')}
      <tr class="tot"><td class="g">${esc(m.nom)} — total</td><td>${m.garde0}</td><td>${m.prod}</td><td>${m.invPcs}</td><td>${m.sortiPcs}</td><td>${m.venduPcs}</td><td>${m.poubPcs}</td><td>${m.deltaPcs}</td><td>${pill(m.deltaTon, m.deltaPct)}</td><td>${m.deltaE}</td></tr></tbody></table></div></div>`).join('');
  const pied = `<div class="vr-card"><div class="vr-pied" style="border-top:none">Chaque magasin a le même tableau, dans le même ordre de catégories : une ligne d’un magasin se compare d’un coup d’œil à la même ligne des autres. Gardé d’hier et gardé pour demain sont les sommes des journées de la période ; sorti = gardé d’hier + produit − gardé pour demain ; delta = sorti − ventes − poubelle déclarée, la somme des non-expliqués des jours. Produit : cuissons validées, sinon le plan — le delta n’est alors qu’un écart au plan (gris). Seuils : vert ≤ 3 %, orange ≤ 6 % du sorti. La même lecture en matières premières (inventaires et commandes validées) viendra avec le lot 4 ; l’écran Inventaires la donne déjà, inventaire par inventaire.</div></div>`;
  return `<div data-screen="verification-fuites" class="vr">${tplBarre(v, x, false)}${bandeauMesure(R)}${kpis}${matrice}${tables}${pied}</div>`;
}
