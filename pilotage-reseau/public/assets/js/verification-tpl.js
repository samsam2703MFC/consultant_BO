/* Cockpit CEO — gabarit de Vérification › Recettes à corriger (#/verification/recettes, lot 1, 31/10/2026).
 * Les valeurs viennent de verification-vals.js (`c.vr`) ; le style de verification.css (préfixe vr-).
 * Maquette retenue : docs/maquettes/verification/e.png — la couverture vers 100 %, les règles en pastilles,
 * la liste classée par chiffre d'affaires concerné. Corriger d'ici (13/11/2026) : docs/maquettes/recettes-correction — le
 * geste et les liens directs de chaque ligne, la correction en place, le volet en correction, le registre. */

import { tplPrix } from './verification-prix-tpl.js';
import { tplFicheMat } from './verification-fichemat-tpl.js';
import { tplCellule, tplProp, tplOuverte, tplRecentes, tplRegistre, tplEdition } from './verification-correction-tpl.js';

export function tplVerification(c, x){
  const { esc } = x, v = c.vr;
  if (!v) { return ''; }
  const bascule = (v.bascule || []).length ? `<div class="vm-bascule">${v.bascule.map(b => `<button class="${b.on ? 'on' : ''}" ${x.A(b.choisir)}>${esc(b.nom)}${b.n != null ? ` <b>${b.n}</b>` : ''}</button>`).join('')}</div>` : '';
  if (v.vue === 'matieres') {
    return `<div data-screen="verification-recettes" class="vr"><div class="vr-haut">${bascule}<span class="vr-sp"></span></div>${tplPrix(v.prix, x)}${tplRegistre(v.registre, x)}${tplFicheMat(v.ficheMat, x)}</div>`;
  }
  const relire = `<button class="vr-btn" ${x.A(v.rafraichir)}${v.relecture || v.chargement ? ' disabled' : ''}>${v.relecture || v.chargement ? 'Lecture…' : 'Relire'}</button>`;
  if (v.chargement) {
    return `<div data-screen="verification-recettes" class="vr"><div class="vr-card vr-etat"><span class="vr-roue"></span>Lecture des ventes des trente derniers jours, des produits et des recettes du panel… La première fois, jusqu’à une minute : le panel ne tient que quatre lectures à la fois.</div></div>`;
  }
  if (v.indispo || v.erreur) {
    return `<div data-screen="verification-recettes" class="vr"><div class="vr-card vr-etat ko">${esc(v.indispo || v.erreur)}<span class="vr-sp"></span>${relire}</div></div>`;
  }
  const cov = v.cov;
  const haut = `<div class="vr-haut">${bascule}<span class="vr-lu">Ventes ${esc(v.periode)}${v.lu ? ' · ' + esc(v.lu) : ''}</span><span class="vr-sp"></span>${relire}<button class="vr-btn" ${x.A(v.exporter)}${v.lignes.length ? '' : ' disabled'}>Exporter la liste</button></div>`;
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
    corps = `<div class="vr-tw"><table class="vr-t"><thead><tr><th class="g" style="width:34px">#</th><th class="g">Produit</th><th class="g">Problème</th><th class="g">Ce qui cloche</th><th>CA concerné<small>30 jours</small></th><th class="g">Magasins</th><th>Repérée<small>depuis</small></th><th>Corriger<small>ici, ou au bon écran du panel</small></th></tr></thead><tbody>
      ${v.lignes.map(l => `<tr><td class="g"><span class="vr-rang${l.haut ? ' h' : ''}">${l.rang}</span></td>
        <td class="g nom${l.ouvrir ? ' vr-ouvre' : ''}"${l.ouvrir ? ` ${x.A(l.ouvrir)} title="Ouvrir la recette"` : ''}><b>${esc(l.nom)}</b>${l.cat || l.formule ? `<small>${esc(l.cat)}${l.formule ? `${l.cat ? ' · ' : ''}<span class="vr-tag">formule</span>` : ''}</small>` : ''}</td>
        <td class="g"><span class="vr-pb ${l.classe}">${esc(l.nomRegle)}</span></td>
        <td class="g vr-det">${esc(l.detail)}${tplProp(l, x)}</td>
        <td>${l.ca ? `<b>${esc(l.ca)}</b>` : (l.valeur ? `<span class="vr-mu">${esc(l.valeur)}</span>` : '<span class="vr-mu">—</span>')}</td>
        <td class="g vr-mu">${esc(l.magasinsTxt)}</td>
        <td title="${esc(l.depuisTitre)}">${esc(l.depuis)}</td>
        <td>${l.geste || (l.liens || []).length ? tplCellule(l, x) : (l.rid ? `<span class="vr-mu" title="Le numéro de la recette au panel">recette ${l.rid}</span>` : '')}</td></tr>${l.ouverte ? tplOuverte(l.ouverte, 8, x) : ''}`).join('')}
      </tbody></table></div>`;
  }
  const pied = `<div class="vr-pied"><b>Les règles</b>, magasin par magasin, sur les produits vendus ces trente jours : <b>pas de recette</b> (produit vendu sans recette au panel) · <b>formule sans ses produits</b> (une formule ou une promotion vendue comme un seul article : ses produits doivent sortir sur le ticket, chacun à prix réduit — promotion « bundle » du panel) · <b>recette vide</b> (aucune ligne) · <b>matière sans prix</b> (dans un magasin qui vend le produit) · <b>prix en retard sur le fournisseur</b> (le prix d’une matière dans ce magasin s’écarte de plus de 10 % de la liste de prix du fournisseur en vigueur ; la comparaison au réseau se fait alors sur le coût au prix du fournisseur) · <b>coût invraisemblable</b> (coût de la pièce sous ${esc(v.seuilMin)} ou au-dessus du prix HT) · <b>hors norme du réseau</b> (plus de 40 % d’écart à la médiane des autres magasins) · <b>unité suspecte</b> (une ligne pèse plus de la moitié du coût, et ×1000 ou ÷1000 la ramène dans un coût plausible) · <b>rendement à zéro</b> · <b>portion sans fraction</b> · <b>matière jamais en recette</b> (en stock, employée par aucune recette vendue). Une même pièce ne porte que l’explication la plus précise. « Repérée depuis » : le premier jour où le cockpit l’a vue ; une ligne disparaît d’elle-même quand la recette est corrigée. <b>Corriger d’ici</b> : chaque geste montre d’abord ce qui sera écrit, puis l’écrit au panel avec le compte consultant, le relit et le note au registre (« Annuler » remet l’avant). Les liens ↗ ouvrent l’écran du panel ; leurs adresses se règlent dans le registre. <span class="vr-mu">${esc(v.source)}</span></div>`;
  return `<div data-screen="verification-recettes" class="vr">${haut}<div class="vr-card">${couverture}${chips}${filtres}${tplRecentes(v.recentes, x)}${corps}${pied}</div>${tplRegistre(v.registre, x)}${tplFiche(v.fiche, x)}</div>`;
}

/** Le volet de la recette d'un produit, ouvert au clic sur son nom. */
function tplFiche(F, x){
  if (!F) { return ''; }
  const { esc } = x;
  const tete = (titre, sous) => `<div class="vr-fi-t"><div><b>${titre}</b>${sous ? `<small>${sous}</small>` : ''}</div><button class="vr-x" ${x.A(F.fermer)} aria-label="Fermer">×</button></div>`;
  let corps;
  if (F.chargement) { corps = tete('Lecture de la recette…', '') + `<div class="vr-etat"><span class="vr-roue"></span>La recette, les prix de chaque magasin, le produit dans chaque magasin.</div>`; }
  else if (F.erreur) { corps = tete('Fiche indisponible', '') + `<div class="vr-etat ko">${esc(F.erreur)}</div>`; }
  else {
    const R = F.recette;
    const bascule = F.corriger ? `<div class="rc-seg"><button class="${F.mode === 'lire' ? 'on' : ''}" ${x.A(F.lire)}>Lire</button><button class="${F.mode === 'corriger' ? 'on' : ''}" ${x.A(F.corriger)}>Corriger</button></div>` : '';
    const liens = (F.liens || []).map(l => `<a class="rc-ln" href="${esc(l.url)}" target="_blank" rel="noopener">${esc(l.txt)} ↗</a>`).join('');
    const sig = F.signalements.length ? `<div class="vr-fi-s">${F.signalements.map(s => `<div><span class="vr-pb ${s.classe}">${esc(s.nom)}</span> ${esc(s.detail)} <span class="vr-mu">${esc(s.magasins)}</span></div>`).join('')}</div>` : '';
    if (F.mode === 'corriger') {
      corps = tete(esc(F.nom), esc(F.cat) + ' · recette ' + F.rid) + `<div class="vr-fi-act">${bascule}</div>` + sig + tplEdition(F.edition, x);
      return `<div class="vr-voile" ${x.A(F.fermer)}></div><div class="vr-fiche">${corps}</div>`;
    }
    corps = tete(esc(F.nom), esc(F.cat) + (F.rid ? ' · recette ' + F.rid : ' · aucune recette au panel'))
      + `<div class="vr-fi-act">${bascule}${liens ? `<span class="vr-mu">Au panel :</span>${liens}` : (F.lien ? `<a class="vr-lien" href="${esc(F.lien)}" target="_blank" rel="noopener">Ouvrir au panel ↗</a>` : '')}${F.corriger ? '' : '<span class="vr-mu">Sans recette : « Rattacher une recette » dans la liste, ou la créer au panel.</span>'}</div>`
      + (F.signalements.length ? `<div class="vr-fi-s">${F.signalements.map(s => `<div><span class="vr-pb ${s.classe}">${esc(s.nom)}</span> ${esc(s.detail)} <span class="vr-mu">${esc(s.magasins)}</span></div>`).join('')}</div>` : '')
      + `<div class="vr-fi-h">Dans chaque magasin</div><table class="vr-t"><thead><tr><th class="g">Magasin</th><th>Coût<small>de la pièce</small></th><th>Prix HT<small>de la pièce</small></th><th>Coefficient</th><th>Matière<small>% du prix</small></th><th class="g"></th></tr></thead><tbody>
        ${F.magasins.map(m => `<tr><td class="g nom"><b>${esc(m.nom)}</b></td>${m.present ? `<td>${m.cout}</td><td>${m.prix}</td><td>${m.coef}</td><td><span class="vx-p ${m.ton}">${m.food}</span></td><td class="g vr-mu">${esc(m.portion)}</td>` : '<td colspan="5" class="g vr-mu">pas proposé dans ce magasin</td>'}</tr>`).join('')}</tbody></table>`
      + (R ? `<div class="vr-fi-h">La recette « ${esc(R.nom)} » — ${R.lignes.length} ligne${R.lignes.length > 1 ? 's' : ''}, ${esc(R.total)} la pièce${R.luDans ? ' (prix de ' + esc(R.luDans) + ')' : ''}, rendement ${esc(R.rendement)}</div>
        ${R.lignes.length ? `<div class="vr-tw"><table class="vr-t"><thead><tr><th class="g">Matière</th><th>Quantité<small>par pièce</small></th><th>Coût</th><th>Part</th>${F.entetes.map(n => `<th>${esc(n)}<small>prix / unité</small></th>`).join('')}</tr></thead><tbody>
        ${R.lignes.map(l => `<tr${l.lourde ? ' class="vr-lourde"' : ''}><td class="g nom"><b>${esc(l.nom)}</b>${l.sous ? `<small>sous-recette ${esc(l.sous)}</small>` : ''}</td><td>${esc(l.qte)}</td><td>${l.cout}</td><td>${l.part}</td>${l.prix.map(p => `<td>${p.ton ? `<span class="vx-p ${p.ton}">${esc(p.txt)}</span>` : esc(p.txt)}</td>`).join('')}</tr>`).join('')}
        </tbody></table></div>` : `<div class="vr-vide">La recette n’a aucune ligne.</div>`}` : (F.rid ? `<div class="vr-vide">La recette n’a pas pu être lue.</div>` : `<div class="vr-vide">Ce produit n’a pas de recette au panel : à créer et à rattacher au produit.</div>`));
  }
  return `<div class="vr-voile" ${x.A(F.fermer)}></div><div class="vr-fiche">${corps}</div>`;
}
