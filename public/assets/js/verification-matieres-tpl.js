/* Cockpit CEO — gabarit de Vérification › Matières ↔ recettes (lot 4, 31/10/2026).
 * Valeurs : verification-matieres-vals.js (`c.vm`) ; style : verification.css (vx-, vm-) ; maquettes c et g-matieres. */

const pill = (ton, txt) => `<span class="vx-p ${ton}">${txt}</span>`;
const kpi = (k, val, s, ton) => `<div class="vx-kpi ${ton || ''}"><div class="k">${k}</div><div class="v">${val}</div><div class="s">${s}</div></div>`;

function tplHaut(v, x){
  const { esc } = x;
  return `<div class="vr-haut">
    <select class="vr-sel" ${x.C(e => v.choisirShop(e.target.value))}>${v.magasins.map(m => `<option value="${esc(m.id)}"${m.on ? ' selected' : ''}>${esc(m.nom)}</option>`).join('')}</select>
    ${v.vues ? `<div class="vx-seg">${v.vues.map(o => `<button class="${o.on ? 'on' : ''}" ${x.A(o.choisir)}>${esc(o.nom)}</button>`).join('')}</div>` : ''}
    ${v.semaines && v.semaines.length ? `<select class="vr-sel" ${x.C(e => v.choisirSemaine(e.target.value))}>${v.semaines.map(s => `<option value="${esc(s.cle)}"${s.on ? ' selected' : ''}>${esc(s.nom)}</option>`).join('')}</select>` : ''}
    <span class="vr-sp"></span>${v.aCalculer ? `<span class="vr-lu">${v.aCalculer} semaine${v.aCalculer > 1 ? 's' : ''} encore à calculer (le cron les remplit chaque heure)</span>` : ''}
    <button class="vr-btn" ${x.A(v.rafraichir)}${v.relecture ? ' disabled' : ''}>${v.relecture ? 'Calcul…' : 'Relire'}</button></div>`;
}

function tplAttribution(A, x, titre){
  const { esc } = x;
  const cote = (C, nom) => C.lignes.length ? `<div class="vm-cote"><div class="vm-ch"><b>${nom}</b><span>${C.total}</span></div>
    ${C.lignes.map(l => `<div class="vm-at"><div class="vm-al"><b>${esc(l.nom)}</b><span>${l.eur} · ${l.part} %</span></div><div class="vm-bar"><i style="width:${l.part}%;background:${l.couleur}"></i></div><div class="vm-ex">${esc(l.exemples)}</div></div>`).join('')}</div>` : '';
  return `<div class="vr-card"><div class="vx-ct"><h3>${titre}</h3><span class="vr-lu">règles du cockpit, à confirmer en visite</span></div>
    <div class="vm-attr">${cote(A.manque, 'Ce qui manque — sorti sans explication')}${cote(A.depasse, 'Ce qui dépasse — vendu sans être sorti du stock')}${!A.manque.lignes.length && !A.depasse.lignes.length ? '<div class="vr-vide">Aucun écart à attribuer.</div>' : ''}</div>
    <div class="vr-pied">Entrées non validées : le stock a monté, ou on a vendu plus que sorti, sans commande validée à la réception. Recette : une matière sortie qu’aucune vente n’emploie, un ordre de grandeur d’écart (unité, quantité), ou un produit de la liste « Recettes à corriger ». Poubelle non déclarée : il manque de la matière et rien n’est déclaré jeté sur les produits qui l’emploient. Ni vendu ni jeté : il en manque alors que la poubelle est déclarée — vente hors caisse, offert, consommation de l’équipe. Tolérance : sous le seuil de la matière.</div></div>`;
}

const ENTETE_STOCK = `<tr class="vx-grp"><th></th><th colspan="4"><span>Le réel — inventaires et commandes validées</span></th><th colspan="2"><span>Les recettes</span></th><th colspan="3"><span>L’écart</span></th></tr>`;

/* --- Le réseau ------------------------------------------------------------------------------------------------------ */
function tplReseau(v, x){
  const { esc } = x, R = v.reseau;
  const kpis = `<div class="vx-kpis">${v.lignes.map(l => kpi(esc(l.nom), l.t ? l.t.explication : '—', l.t ? (l.verdict.nom === 'Entrées non validées' ? 'entrées non validées · ' : '') + 'expliquée · ' + l.t.manquant + ' manquants · ' + esc(l.periode) : esc(l.verdict.nom), l.t ? (l.verdict.nom === 'Entrées non validées' ? 'att' : l.t.ton) : 'mu')).join('')}${R ? kpi('Réseau', R.explication, 'de la matière expliquée · ' + R.manquant + ' manquants' + (v.reseauSans.length ? ' · sans ' + esc(v.reseauSans.join(', ')) + ' (entrées non validées)' : ''), R.ton) : ''}</div>`;
  const retard = v.retards.length ? `<div class="vx-mesure"><b>Inventaire hebdomadaire manquant :</b> ${esc(v.retards.join(' ; '))}. Sans deux inventaires consécutifs, la semaine ne se lit pas — l’inventaire matière se fait chaque semaine.</div>` : '';
  const table = `<div class="vr-card"><div class="vx-ct"><h3>Les magasins</h3><span class="vr-lu">la dernière semaine d’inventaire à inventaire · au coût d’achat · un clic ouvre le magasin</span></div>
    <div class="vr-tw"><table class="vr-t"><thead><tr><th class="g">Magasin</th><th>Période</th><th>+ Entré<small>commandes validées</small></th><th>= Consommé<small>début + entré − fin</small></th><th>Expliqué<small>ventes + poubelle</small></th><th>Matière<small>expliquée</small></th><th>Manque<small>au coût</small></th><th class="g">Verdict</th></tr></thead><tbody>
    ${v.lignes.map(l => `<tr class="vx-clic" ${x.A(l.ouvrir)}><td class="g nom"><b>${esc(l.nom)}</b><small>dernier inventaire ${esc(l.dernier)}${l.retard ? ' · <span class="vm-rt">en retard</span>' : ''}</small></td><td>${esc(l.periode)}<small>${l.jours ? l.jours + ' jours' : ''}</small></td>
      ${l.t ? `<td>${l.t.entreesNulles ? pill('att', l.t.entrees) : l.t.entrees}</td><td><b>${l.t.sorties}</b></td><td>${l.t.explique}</td><td>${pill(l.verdict.nom === 'Entrées non validées' ? 'mu' : l.t.ton, l.t.explication)}</td><td>${l.t.manquant}<small>${l.t.excedent} en trop</small></td>` : '<td colspan="5" class="vr-mu">—</td>'}
      <td class="g"><span class="vx-v ${l.verdict.ton}"><i></i>${esc(l.verdict.nom)}</span><span class="vx-why">${esc(l.verdict.pourquoi)}</span></td></tr>`).join('')}
    ${R ? `<tr class="tot"><td class="g">Réseau${v.reseauSans.length ? `<small>sans ${esc(v.reseauSans.join(', '))}</small>` : ''}</td><td></td><td>${R.entrees}</td><td>${R.sorties}</td><td>${R.explique}</td><td>${R.explication}</td><td>${R.manquant}</td><td></td></tr>` : ''}</tbody></table></div></div>`;
  const matrice = v.matrice.length ? `<div class="vr-card"><div class="vx-ct"><h3>Où ça fuit en matières — catégories × magasins</h3><span class="vr-lu">matière expliquée (part du consommé que ventes et poubelle expliquent) · écart au coût</span></div>
    <div class="vr-tw"><table class="vr-t vx-heat"><thead><tr><th class="g">Catégorie de matière</th>${v.entetes.map(n => `<th>${esc(n)}</th>`).join('')}</tr></thead><tbody>
    ${v.matrice.map(r => `<tr><td class="g nom"><b>${esc(r.cat)}</b></td>${r.cases.map(c => `<td>${!c ? '<span class="vr-mu">—</span>' : (c.vide ? `<span class="vr-mu">pas de sortie</span><small>${c.ecart}</small>` : pill(c.ton, c.explication) + `<small>${c.ecart}</small>`)}</td>`).join('')}</tr>`).join('')}
    </tbody></table></div></div>` : '';
  return `${retard}${kpis}${table}${tplAttribution(v.attribution, x, 'D’où vient l’écart — le réseau')}${matrice}`;
}

/* --- Un magasin ----------------------------------------------------------------------------------------------------- */
function tplMagasin(v, x){
  const { esc } = x;
  const retard = v.retard ? `<div class="vx-mesure"><b>Inventaire hebdomadaire manquant :</b> le dernier inventaire de ${esc(v.nom)} date du ${esc(v.retard.dernier || '—')}${v.retard.age != null ? ` (il y a ${v.retard.age} jours)` : ''}. Sans deux inventaires consécutifs, la semaine suivante ne se lit pas.</div>` : '';
  if (v.vide) {
    return `${retard}<div class="vr-card vr-etat">${v.semaines && v.semaines.length ? 'La semaine choisie n’est pas encore calculée : le cron la calcule dans l’heure, ou « Relire » la calcule maintenant (jusqu’à vingt secondes).' : `Aucune semaine à lire pour ${esc(v.nom)} : il faut deux inventaires complets relevés consécutifs (le cockpit relève le stock du panel depuis le 12/10).`}</div>`;
  }
  const t = v.t;
  const entree = v.sansEntree ? `<div class="vx-mesure"><b>Aucune commande validée à la réception entre ces deux inventaires.</b> Le stock du panel n’a monté sur aucune matière : les livraisons ont été rangées sans être validées à la réception dans le panel. Le consommé réel est alors faux par défaut, et ${v.nEntree} matière${v.nEntree > 1 ? 's' : ''} paraissent « vendues sans être sorties ». À corriger en premier : sans entrées, aucune fuite ne se mesure.</div>` : '';
  const notes = v.notes.length ? `<div class="vx-mesure">${v.notes.map(n => esc(n)).join('<br>')}</div>` : '';
  const kpis = `<div class="vx-kpis">
    ${kpi('Stock au ' + esc(v.periode.de), t.debut, 'inventaire, au coût d’achat')}
    ${kpi('+ Entré', t.entrees, 'commandes validées à la réception', t.entreesNulles ? 'att' : '')}
    ${kpi('− Stock au ' + esc(v.periode.a), t.fin, 'inventaire, au coût d’achat')}
    ${kpi('= Consommé réel', t.sorties, t.explique + ' que ventes et poubelle expliquent')}
    ${kpi('Matière expliquée', t.explication, t.manquant + ' manquants · ' + t.excedent + ' en trop', v.sansEntree ? 'mu' : t.ton)}</div>`;
  const deux = `<div class="vm-deux">${tplAttribution(v.attribution, x, 'D’où vient l’écart')}
    <div class="vr-card"><div class="vx-ct"><h3>Vendu sans recette</h3><span class="vr-lu">${v.caSansRecette} de CA sur la période</span></div>
      ${v.sansRecette.length ? `<div class="vr-tw"><table class="vr-t"><thead><tr><th class="g">Produit</th><th>Vendus</th><th>CA</th></tr></thead><tbody>${v.sansRecette.map(p => `<tr><td class="g nom"><b>${esc(p.nom)}</b></td><td>${p.q}</td><td>${p.ca}</td></tr>`).join('')}</tbody></table></div>` : '<div class="vr-vide ok">Tout ce qui est vendu a une recette.</div>'}
      <div class="vr-pied">Ces ventes ne consomment aucune matière dans le calcul : leur matière sort du stock sans explication. À compléter dans « Recettes à corriger ». Ventes sur des matières jamais inventoriées (emballages, boissons) : ${v.horsInventaire}, suivies à part.</div></div></div>`;
  const cats = `<div class="vr-card"><div class="vx-ct"><h3>${esc(v.nom)} — par catégorie de matière</h3><span class="vr-lu">au coût d’achat · même structure pour chaque magasin · un clic déplie les matières (en quantités)</span></div>
    <div class="vr-tw"><table class="vr-t"><thead>${ENTETE_STOCK}
      <tr><th class="g">Catégorie / matière</th><th>Stock début</th><th>+ Entré<small>commandes validées</small></th><th>− Stock fin</th><th>= Consommé</th><th>Ventes<small>× recette</small></th><th>Poubelle<small>déclarée × recette</small></th><th>Écart<small>consommé − expliqué</small></th><th>Expliquée</th><th class="g">Cause</th></tr></thead><tbody>
    ${v.categories.map(k => `<tr class="vx-clic" ${x.A(k.basculer)}><td class="g nom"><b>${k.ouvert ? '▾' : '▸'} ${esc(k.nom)}</b><small>${k.n} matière${k.n > 1 ? 's' : ''}</small></td><td>${k.debut}</td><td>${k.entrees}</td><td>${k.fin}</td><td><b>${k.sorties}</b></td><td>${k.ventes}</td><td>${k.poubelle}</td><td><b>${k.ecart}</b></td><td>${pill(v.sansEntree ? 'mu' : k.ton, k.explication)}</td><td></td></tr>
      ${k.matieres.map(m => `<tr class="vx-pr"><td class="g nom">${esc(m.nom)}<small>${esc(m.prix)}</small></td><td>${m.debut}</td><td>${m.entrees}</td><td>${m.fin}</td><td><b>${m.sorties}</b></td><td>${m.ventes}</td><td>${m.poubelle}</td><td>${m.ecart}<small>${m.ecartEur}</small></td><td>${m.ecartPct}</td><td class="g">${m.cause ? pill(m.tonCause, esc(m.nomCause)) : ''}</td></tr>`).join('')}`).join('')}
    <tr class="tot"><td class="g">${esc(v.nom)} — total</td><td>${t.debut}</td><td>${t.entrees}</td><td>${t.fin}</td><td>${t.sorties}</td><td>${t.ventes}</td><td>${t.poubelle}</td><td>${t.ecart}</td><td>${t.explication}</td><td></td></tr></tbody></table></div></div>`;
  const mats = `<div class="vr-card"><div class="vx-ct"><h3>Matière par matière</h3><span class="vr-lu">les plus gros écarts en euros d’abord · ${v.matieres.length} sur ${v.nAvecEcart} avec un écart (${v.nMatieres} matières comptées)</span><span class="vr-sp"></span>${v.nAvecEcart > 15 ? `<button class="vr-btn" ${x.A(v.basculerTout)}>${v.tout ? 'Les quinze premières' : 'Toutes les matières'}</button>` : ''}</div>
    ${v.phrase ? `<div class="vx-lect">${esc(v.phrase)}</div>` : ''}
    <div class="vr-tw"><table class="vr-t vm-t"><thead>${ENTETE_STOCK.replace('<th colspan="3"><span>L’écart</span></th>', '<th colspan="3"><span>L’écart</span></th><th></th>')}
      <tr><th class="g">Matière</th><th>Stock début</th><th>+ Entré</th><th>− Stock fin</th><th>= Consommé</th><th>Théorique<small>ventes + poubelle</small></th><th>Produits<small>qui l’emploient</small></th><th>Quantité</th><th>%</th><th>€</th><th class="g">Cause probable</th></tr></thead><tbody>
    ${v.matieres.map(m => `<tr class="${m.tonCause === 'ko' ? 'vm-ko' : ''}"><td class="g nom vr-ouvre" ${x.A(m.ouvrir)} title="Voir tous ses mouvements sur la période"><b>${esc(m.nom)}</b><small>${esc(m.prix)} · ${esc(m.cat)}</small></td><td>${m.debut}</td><td>${m.entrees}</td><td>${m.fin}</td><td><b>${m.sorties}</b></td><td>${m.explique}</td><td class="g vm-prod">${esc(m.produits)}</td><td>${m.ecart}</td><td>${pill(m.tonCause, m.ecartPct)}</td><td><b>${m.ecartEur}</b></td><td class="g vm-cause">${m.cause ? `<b>${esc(m.nomCause)}</b> — ` : ''}${esc(m.pourquoi)}</td></tr>`).join('')}
    </tbody></table></div>
    <div class="vr-pied">Consommé réel = stock de l’inventaire de début + entré (les commandes validées à la réception, lues dans le stock du panel au moment où elles passent) − stock de l’inventaire de fin. Théorique = ventes de caisse × recette aplatie (sous-recettes fondues, portions ramenées à la pièce) + poubelle déclarée × recette. Valeurs au prix d’achat moyen HTVA de la matière. Tolérances : ${esc(v.tolerances)} (réglables). Seuils de la matière expliquée : vert ≥ ${100 - v.seuils[0]} %, orange ≥ ${100 - v.seuils[1]} %.</div></div>`;
  return `${retard}${entree}${notes}${kpis}${deux}${cats}${mats}`;
}

export function tplVerifMatieres(c, x){
  const v = c.vm;
  if (!v) { return ''; }
  const haut = tplHaut(v, x);
  if (v.chargement) { return `<div data-screen="verification-matieres" class="vr">${haut}<div class="vr-card vr-etat"><span class="vr-roue"></span>Lecture des inventaires, des commandes validées et des ventes… La première fois, jusqu’à vingt-cinq secondes ; le cron calcule le reste chaque heure.</div></div>`; }
  if (v.erreur) { return `<div data-screen="verification-matieres" class="vr">${haut}<div class="vr-card vr-etat ko">${x.esc(v.erreur)}</div></div>`; }
  if (!v.lignes && !v.t && !v.vide) { return `<div data-screen="verification-matieres" class="vr">${haut}</div>`; }
  return `<div data-screen="verification-matieres" class="vr">${haut}${v.shop ? tplMagasin(v, x) : tplReseau(v, x)}</div>`;
}
