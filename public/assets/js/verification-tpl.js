/* Cockpit CEO — gabarit de Vérification › Recettes à corriger (#/verification/recettes, lot 1, 31/10/2026).
 * Les valeurs viennent de verification-vals.js (`c.vr`) ; le style de verification.css (préfixe vr-).
 * Maquette retenue : docs/maquettes/verification/e.png — la couverture vers 100 %, les règles en pastilles,
 * la liste classée par chiffre d'affaires concerné. */

export function tplVerification(c, x){
  const { esc } = x, v = c.vr;
  if (!v) { return ''; }
  const relire = `<button class="vr-btn" ${x.A(v.rafraichir)}${v.relecture || v.chargement ? ' disabled' : ''}>${v.relecture || v.chargement ? 'Lecture…' : 'Relire'}</button>`;
  if (v.chargement) {
    return `<div data-screen="verification-recettes" class="vr"><div class="vr-card vr-etat"><span class="vr-roue"></span>Lecture des ventes des trente derniers jours, des produits et des recettes du panel… La première fois, jusqu’à une minute : le panel ne tient que quatre lectures à la fois.</div></div>`;
  }
  if (v.indispo || v.erreur) {
    return `<div data-screen="verification-recettes" class="vr"><div class="vr-card vr-etat ko">${esc(v.indispo || v.erreur)}<span class="vr-sp"></span>${relire}</div></div>`;
  }
  const cov = v.cov;
  const haut = `<div class="vr-haut"><span class="vr-lu">Ventes ${esc(v.periode)}${v.lu ? ' · ' + esc(v.lu) : ''}</span><span class="vr-sp"></span>${relire}<button class="vr-btn" ${x.A(v.exporter)}${v.lignes.length ? '' : ' disabled'}>Exporter la liste</button></div>`;
  const lecture = v.lecture.complet
    ? `Les ${v.lecture.vendues} recettes des produits vendus sont relues.`
    : `${v.lecture.lues} recettes relues sur ${v.lecture.vendues} : les ${v.lecture.aLire} autres se lisent au prochain passage du cron (chaque heure), ou avec « Relire ». Tant qu’elles restent, les règles qui lisent les lignes de la recette ne les jugent pas encore${cov.aRelire ? ' (' + esc(cov.aRelire) + ' de ventes)' : ''}.`;
  const couverture = `<div class="vr-cov">
      <div class="big ${cov.ton}">${esc(cov.pct)}<small>du CA passe par une recette fiable${cov.horsFormules ? '<br>hors formules et promotions' : ''}</small></div>
      <div><div class="jg"><i class="${cov.ton}" style="width:${cov.largeur}%"></i><u></u></div>
        <div class="tx">Objectif <b>100 %</b> : chaque produit vendu a une recette complète, chiffrée et plausible. Il manque <b>${esc(cov.manque)} du CA, soit ${esc(cov.caManquant)} de ventes sur ${cov.jours} jours</b>${cov.partTop10 ? `, dont ${esc(cov.partTop10)} dans les dix premières lignes` : ''}. Tant qu’elles restent, l’écart de matière, la marge et le CA attendu en portent une part.</div>
        <div class="tx2">${lecture}${v.lecture.orph ? '' : ' Les matières jamais en recette se jugent quand 95 % des recettes sont relues.'}${v.corrigees ? ` <b>${v.corrigees.n} signalement${v.corrigees.n > 1 ? 's' : ''} résolu${v.corrigees.n > 1 ? 's' : ''}</b> depuis le ${esc(v.corrigees.depuis)}.` : ''}${v.joursManquants.length ? ` Ventes incomplètes : ${esc(v.joursManquants.join(', '))}.` : ''}</div></div></div>`;
  const chips = `<div class="vr-chips">${v.chips.map(ch => `<button class="${ch.on ? 'on' : ''}" ${x.A(ch.choisir)}${ch.titre ? ` title="${esc(ch.titre)}"` : ''}>${ch.classe ? `<i class="vr-pt ${ch.classe}"></i>` : ''}${esc(ch.nom)} <b>${ch.n}</b></button>`).join('')}</div>`;
  const F = v.formules;
  const filtres = `<div class="vr-fil">
      <button class="vr-tog${F.on ? ' on' : ''}" ${x.A(F.basculer)} title="${F.on ? 'Masquer' : 'Montrer'} les formules et promotions (lunch, paniers, « 2 achetés »…) ; éteint, la couverture se lit sans elles"><i></i>Formules et promotions <b>${F.n}</b></button>
      <select class="vr-sel" ${x.C(e => v.choisirShop(e.target.value))}>${v.magasins.map(m => `<option value="${esc(m.id)}"${m.on ? ' selected' : ''}>${esc(m.nom)}</option>`).join('')}</select>
      <select class="vr-sel" ${x.C(e => v.choisirTri(e.target.value))}>${v.tris.map(t => `<option value="${esc(t.id)}"${t.on ? ' selected' : ''}>${esc(t.nom)}</option>`).join('')}</select>
      <span class="vr-sp"></span><span class="vr-lu">${v.lignes.length} ligne${v.lignes.length > 1 ? 's' : ''}${v.lignes.length !== v.total ? ' sur ' + v.total : ''}</span></div>`;
  let corps;
  if (v.vide) {
    corps = `<div class="vr-vide ok">Aucune recette à corriger : chaque produit vendu ces trente jours a une recette complète, chiffrée et plausible.</div>`;
  } else if (v.videFiltre) {
    corps = `<div class="vr-vide">Rien pour ce filtre.</div>`;
  } else {
    corps = `<div class="vr-tw"><table class="vr-t"><thead><tr><th class="g" style="width:34px">#</th><th class="g">Produit</th><th class="g">Problème</th><th class="g">Ce qui cloche</th><th>CA concerné<small>30 jours</small></th><th class="g">Magasins</th><th>Repérée<small>depuis</small></th><th></th></tr></thead><tbody>
      ${v.lignes.map(l => `<tr><td class="g"><span class="vr-rang${l.haut ? ' h' : ''}">${l.rang}</span></td>
        <td class="g nom"><b>${esc(l.nom)}</b>${l.cat || l.formule ? `<small>${esc(l.cat)}${l.formule ? `${l.cat ? ' · ' : ''}<span class="vr-tag">formule</span>` : ''}</small>` : ''}</td>
        <td class="g"><span class="vr-pb ${l.classe}">${esc(l.nomRegle)}</span></td>
        <td class="g vr-det">${esc(l.detail)}</td>
        <td>${l.ca ? `<b>${esc(l.ca)}</b>` : (l.valeur ? `<span class="vr-mu">${esc(l.valeur)}</span>` : '<span class="vr-mu">—</span>')}</td>
        <td class="g vr-mu">${esc(l.magasinsTxt)}</td>
        <td title="${esc(l.depuisTitre)}">${esc(l.depuis)}</td>
        <td>${l.lien ? `<a class="vr-lien" href="${esc(l.lien)}" target="_blank" rel="noopener">Ouvrir au panel ↗</a>` : (l.rid ? `<span class="vr-mu" title="Le numéro de la recette au panel">recette ${l.rid}</span>` : '')}</td></tr>`).join('')}
      </tbody></table></div>`;
  }
  const pied = `<div class="vr-pied"><b>Les règles</b>, magasin par magasin, sur les produits vendus ces trente jours : <b>pas de recette</b> (produit vendu sans recette au panel) · <b>formule sans ses produits</b> (une formule ou une promotion vendue comme un seul article : ses produits doivent sortir sur le ticket, chacun à prix réduit — promotion « bundle » du panel) · <b>recette vide</b> (aucune ligne) · <b>matière sans prix</b> (dans un magasin qui vend le produit) · <b>coût invraisemblable</b> (coût de la pièce sous ${esc(v.seuilMin)} ou au-dessus du prix HT) · <b>hors norme du réseau</b> (plus de 40 % d’écart à la médiane des autres magasins) · <b>unité suspecte</b> (une ligne pèse plus de la moitié du coût, et ×1000 ou ÷1000 la ramène dans un coût plausible) · <b>rendement à zéro</b> · <b>portion sans fraction</b> · <b>matière jamais en recette</b> (en stock, employée par aucune recette vendue). Une même pièce ne porte que l’explication la plus précise. « Repérée depuis » : le premier jour où le cockpit l’a vue ; une ligne disparaît d’elle-même quand la recette est corrigée au panel. Lecture seule : rien n’est écrit au panel. <span class="vr-mu">${esc(v.source)}</span></div>`;
  return `<div data-screen="verification-recettes" class="vr">${haut}<div class="vr-card">${couverture}${chips}${filtres}${corps}${pied}</div></div>`;
}
