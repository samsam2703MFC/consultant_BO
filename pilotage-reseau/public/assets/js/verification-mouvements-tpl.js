/* Cockpit CEO — gabarit de Vérification › Mouvements (31/10/2026). Valeurs : verification-mouvements-vals.js
 * (`c.vmo`) ; style : verification.css (vx-, vmo-). */

const pill = (ton, txt) => `<span class="vx-p ${ton}">${txt}</span>`;
const kpi = (k, val, s, ton) => `<div class="vx-kpi ${ton || ''}"><div class="k">${k}</div><div class="v">${val}</div><div class="s">${s}</div></div>`;

function tplHaut(v, x){
  const { esc } = x;
  return `<div class="vr-haut">
    <select class="vr-sel" ${x.C(e => v.choisirShop(e.target.value))}>${v.magasins.map(m => `<option value="${esc(m.id)}"${m.on ? ' selected' : ''}>${esc(m.nom)}</option>`).join('')}</select>
    <input id="vmo-q" class="vr-sel vmo-q" type="search" placeholder="Un produit ou une matière…" value="${esc(v.q)}" ${x.I(v.chercher)} autocomplete="off">
    <span class="vmo-per">du <input class="vr-sel" type="date" value="${esc(v.du)}" ${x.C(v.choisirDu)}> au <input class="vr-sel" type="date" value="${esc(v.au)}" ${x.C(v.choisirAu)}></span>
    <div class="vx-seg">${v.presets.map(o => `<button class="${o.on ? 'on' : ''}" ${x.A(o.choisir)}>${esc(o.nom)}</button>`).join('')}</div>
    <span class="vr-sp"></span>${v.rafraichir ? `<button class="vr-btn" ${x.A(v.rafraichir)}${v.relecture ? ' disabled' : ''}>${v.relecture ? 'Lecture…' : 'Relire'}</button>` : ''}</div>`;
}

function tplRecherche(v, x){
  const { esc } = x;
  if (v.chargementListe) { return `<div class="vr-card vr-etat"><span class="vr-roue"></span>Lecture des produits et des matières du magasin…</div>`; }
  if (!v.resultats) { return ''; }
  const titre = v.q ? `${v.nResultats} résultat${v.nResultats > 1 ? 's' : ''} pour « ${esc(v.q)} »` : `Les produits les plus vendus — ou tapez un nom (${v.nProduits} produits, ${v.nMatieres} matières)`;
  return `<div class="vr-card"><div class="vx-ct"><h3>${v.sel && !v.q ? 'Changer de produit' : 'Choisir un produit ou une matière'}</h3><span class="vr-lu">${titre}</span></div>
    ${v.resultats.length ? `<div class="vmo-res">${v.resultats.map(r => `<button class="vmo-r${r.on ? ' on' : ''}" ${x.A(r.choisir)}><b>${esc(r.nom)}</b><span class="vr-tag">${esc(r.type)}</span><small>${esc(r.detail)}</small></button>`).join('')}</div>` : '<div class="vr-vide">Aucun produit ni matière de ce nom dans ce magasin.</div>'}</div>`;
}

function tplJournal(v, x){
  const { esc } = x;
  if (!v.journal.length) { return '<div class="vr-vide">Aucun mouvement sur la période.</div>'; }
  return `<div class="vmo-jn" data-scroll="vmo-journal">${v.journal.map(e => e.jour ? `<div class="vmo-jj">${esc(e.jour)}</div>` : `<div class="vmo-e"><span class="h">${esc(e.h)}</span>${pill(e.ton, esc(e.type))}<span class="t">${esc(e.texte)}</span></div>`).join('')}</div>`;
}

/** Les en-têtes de la vue inversée : les groupes (vitrine, stock de chaque matière), puis une colonne par mouvement. */
function entetes(I, x, premiere){
  const { esc } = x;
  return `<tr class="vx-grp"><th></th>${I.colonnes.map(g => `<th colspan="${g.cols.length}"><span>${esc(g.titre)}</span></th>`).join('')}</tr>
    <tr><th class="g">${esc(premiere)}</th>${I.colonnes.map(g => g.cols.map(c => `<th class="vmo-col${c.sens === '=' || c.sens === 'soir' ? ' vmo-colfin' : ''}">${esc(c.lib)}</th>`).join('')).join('')}</tr>`;
}
const cellule = (c, x) => `<td class="${c.cls}"${c.title ? ` title="${x.esc(c.title)}"` : ''}>${c.txt}${c.small ? `<small>${x.esc(c.small)}</small>` : ''}</td>`;

/** Semaine par semaine (une ligne par semaine, l'écart à la semaine d'avant), puis jour par jour avec un total par semaine. */
function tplInverse(v, x){
  const { esc } = x, I = v.inverse;
  if (!I || !I.blocs.length) { return ''; }
  const comp = `<div class="vr-card"><div class="vx-ct"><h3>Semaine par semaine</h3><span class="vr-lu">les totaux de chaque semaine, l’un sous l’autre · sous chaque chiffre, l’écart à la semaine d’avant · stocks : le premier matin et le dernier soir</span></div>
    <div class="vr-tw"><table class="vr-t vmo-inv"><thead>${entetes(I, x, 'Semaine')}</thead><tbody>
    ${I.blocs.map(b => `<tr><td class="g nom"><b>${esc(b.titre)}</b><small>${esc(b.periode)}</small></td>${b.total.map((c, k) => `<td class="${c.cls}">${c.txt}${b.ecarts[k] ? `<small class="vmo-dlt">${esc(b.ecarts[k])}</small>` : ''}</td>`).join('')}</tr>`).join('')}
    </tbody></table></div></div>`;
  const jours = `<div class="vr-card"><div class="vx-ct"><h3>Jour par jour</h3><span class="vr-lu">+ ce qui entre, − ce qui sort · un total par semaine · survolez une vente pour l’heure par heure</span></div>
    <div class="vr-tw"><table class="vr-t vmo-inv"><thead>${entetes(I, x, 'Jour')}</thead><tbody>
    ${I.blocs.map(b => `<tr class="vmo-sec"><td class="g" colspan="${I.nCols + 1}">${esc(b.titre)} · du ${esc(b.du)} au ${esc(b.au)}</td></tr>
      ${b.jours.map(j => `<tr class="${j.dim ? 'vx-dim' : ''}"><td class="g nom"><b>${esc(j.date)}</b></td>${j.cells.map(c => cellule(c, x)).join('')}</tr>`).join('')}
      <tr class="vmo-totl"><td class="g">Total ${esc(b.titre.toLowerCase())}</td>${b.total.map(c => `<td class="${c.cls}">${c.txt}</td>`).join('')}</tr>`).join('')}
    </tbody></table></div></div>`;
  return comp + jours;
}

function tplProduit(v, x){
  const { esc } = x;
  const mats = v.matieres.length ? `<div class="vr-card"><div class="vx-ct"><h3>Sa recette</h3><span class="vr-lu">les matières du stock du panel · un clic ouvre la matière</span></div>
    ${v.matieres.map(m => `<div class="vmo-m"><div class="vmo-mt vx-clic" ${x.A(m.ouvrir)}><b>${esc(m.nom)}</b><small>${esc(m.parPiece)} · consommé par ce produit sur la période : ${esc(m.theorique)} · ${m.n} mouvement${m.n > 1 ? 's' : ''} de stock</small></div></div>`).join('')}</div>` : '<div></div>';
  return `${tplInverse(v, x)}<div class="vca-deux"><div class="vr-card"><div class="vx-ct"><h3>Le journal</h3><span class="vr-lu">tous les mouvements, dans l’ordre de la journée</span></div>${tplJournal(v, x)}</div>${mats}</div>`;
}

function tplMatiere(v, x){
  const { esc } = x;
  const prods = `<div class="vr-card"><div class="vx-ct"><h3>Les produits qui l’emploient</h3><span class="vr-lu">${v.nProduitsRecette} produit${v.nProduitsRecette > 1 ? 's' : ''} en recette · vendus ou jetés sur la période · un clic ouvre le produit</span></div>
    ${v.produits.length ? `<div class="vr-tw"><table class="vr-t"><thead><tr><th class="g">Produit</th><th>Par pièce</th><th>Vendu</th><th>Poubelle</th><th>Consommé</th></tr></thead><tbody>
      ${v.produits.map(p => `<tr class="vx-clic" ${x.A(p.ouvrir)}><td class="g nom"><b>${esc(p.nom)}</b></td><td>${p.parPiece}</td><td>${p.vendu}</td><td>${p.poubelle}</td><td><b>${p.consomme}</b></td></tr>`).join('')}</tbody></table></div>`
      : `<div class="vr-vide">${v.nProduitsRecette ? 'Aucun produit qui l’emploie n’a été vendu ni jeté sur la période.' : 'Aucune recette n’emploie cette matière : tout ce qui sort du stock est sans explication.'}</div>`}</div>`;
  return `${tplInverse(v, x)}<div class="vca-deux"><div class="vr-card"><div class="vx-ct"><h3>Le journal</h3><span class="vr-lu">chaque mouvement de stock relevé, avant → après</span></div>${tplJournal(v, x)}</div>${prods}</div>`;
}

export function tplVerifMouvements(c, x){
  const { esc } = x, v = c.vmo;
  if (!v) { return ''; }
  const haut = tplHaut(v, x);
  const recherche = !v.sel || v.q ? tplRecherche(v, x) : '';
  if (!v.sel) { return `<div data-screen="verification-mouvements" class="vr">${haut}${recherche}</div>`; }
  let corps = '';
  if (v.chargement) { corps = `<div class="vr-card vr-etat"><span class="vr-roue"></span>Lecture des journées, des tickets et du stock de la période…</div>`; }
  else if (v.erreur) { corps = `<div class="vr-card vr-etat ko">${esc(v.erreur)}</div>`; }
  else if (v.kpis) {
    const tete = `<div class="vmo-tete"><div><b>${esc(v.titre)}</b><small>${esc(v.sous)} · ${esc(v.periode)}</small></div>${v.aCalculer ? `<span class="vr-lu">${v.aCalculer} journée${v.aCalculer > 1 ? 's' : ''} pas encore calculée${v.aCalculer > 1 ? 's' : ''} — « Relire » les calcule</span>` : ''}</div>`;
    const kpis = `<div class="vx-kpis vmo-kpis">${v.kpis.map(k => kpi(esc(k[0]), esc(k[1]), esc(k[2]), k[3] || '')).join('')}</div>`;
    corps = tete + kpis + (v.type === 'produit' ? tplProduit(v, x) : tplMatiere(v, x));
  }
  return `<div data-screen="verification-mouvements" class="vr">${haut}${recherche}${corps}</div>`;
}
