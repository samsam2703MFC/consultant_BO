/* Cockpit CEO — gabarit de Vérification › Évolution (lot 3, 31/10/2026).
 * Valeurs : verification-evol-vals.js (`c.ve`) ; style : verification.css (ve-) ; maquette docs/maquettes/verification/f.png. */

function tplHaut(v, x){
  const { esc } = x;
  return `<div class="vr-haut">
    <div class="vx-seg">${v.periodes.map(o => `<button class="${o.on ? 'on' : ''}" ${x.A(o.choisir)}>${esc(o.nom)}</button>`).join('')}</div>
    ${v.periode ? `<span class="ve-per">${esc(v.periode)}</span>` : ''}
    <select class="vr-sel" ${x.C(e => v.choisirShop(e.target.value))}>${v.magasins.map(m => `<option value="${esc(m.id)}"${m.on ? ' selected' : ''}>${esc(m.nom)}</option>`).join('')}</select>
    <span class="vr-sp"></span>
    <select class="vr-sel" ${x.C(e => v.choisirInd(e.target.value))}>${v.indicateurs.map(o => `<option value="${o.id}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>
    <button class="vr-btn" ${x.A(v.rafraichir)}${v.relecture ? ' disabled' : ''}>${v.relecture ? 'Lecture…' : 'Relire'}</button></div>`;
}

function tplGraphique(v, x){
  const { esc } = x, g = v.g;
  if (g.vide) { return `<div class="vr-vide">Rien encore pour cet indicateur sur la période : la base se remplit jour après jour.</div>`; }
  const f = n => n.toFixed(1);
  return `<svg class="ve-svg" viewBox="0 0 ${g.W} ${g.H}" role="img" aria-label="${esc(v.nomInd)}, semaine par semaine">
    ${g.bandes.map(b => `<rect class="ve-bd ${b.ton}" x="${g.G}" y="${f(b.y1)}" width="${f(g.xFin - g.G)}" height="${f(Math.max(0, b.y2 - b.y1))}"/>`).join('')}
    ${g.grille.map(l => `<line class="ve-gr" x1="${g.G}" x2="${f(g.xFin)}" y1="${f(l.y)}" y2="${f(l.y)}"/><text class="ve-ax" x="${g.G - 6}" y="${f(l.y + 3.5)}" text-anchor="end">${esc(l.txt)}</text>`).join('')}
    ${g.axeX.map(a => `<text class="ve-ax" x="${f(a.x)}" y="${g.H - 8}" text-anchor="middle">${esc(a.txt)}</text>`).join('')}
    ${g.lignes.map(L => `<g class="ve-l${L.pale ? ' pale' : ''}${L.fort ? ' fort' : ''}${L.reseau ? ' res' : ''}" style="--c:${L.couleur}">
      ${L.segs.map(s => `<line x1="${f(s.x1)}" y1="${f(s.y1)}" x2="${f(s.x2)}" y2="${f(s.y2)}"${s.estime ? ' class="est"' : ''}/>`).join('')}
      ${L.pts.map(p => `<circle cx="${f(p.x)}" cy="${f(p.y)}" r="${L.fort ? 3.6 : 2.8}"${p.estime ? ' class="est"' : ''}><title>${esc(p.titre)}</title></circle>`).join('')}</g>`).join('')}
    ${g.etiq.map(e => `<text class="ve-et${e.pale ? ' pale' : ''}" x="${f(g.xFin + 10)}" y="${f(e.y + 3.5)}" fill="${e.couleur}">${esc(e.txt)}</text>`).join('')}
  </svg>`;
}

const sparkline = sp => sp ? `<svg class="ve-sp" viewBox="0 0 ${sp.W} ${sp.H}" width="${sp.W}" height="${sp.H}">${sp.chemins.map(c => `<path d="${c}"/>`).join('')}${sp.points.map(p => `<circle cx="${p[0]}" cy="${p[1]}" r="1.6"/>`).join('')}</svg>` : '<span class="ve-sp0"></span>';

export function tplVerifEvol(c, x){
  const { esc } = x, v = c.ve;
  if (!v) { return ''; }
  if (v.chargement) { return `<div data-screen="verification-evolution" class="vr">${tplHaut(v, x)}<div class="vr-card vr-etat"><span class="vr-roue"></span>Lecture de la base quotidienne…</div></div>`; }
  if (v.erreur) { return `<div data-screen="verification-evolution" class="vr">${tplHaut(v, x)}<div class="vr-card vr-etat ko">${esc(v.erreur)}</div></div>`; }
  if (!v.g) { return `<div data-screen="verification-evolution" class="vr">${tplHaut(v, x)}</div>`; }
  const B = v.base;
  const etat = B.erreur ? `<span class="ve-pt ko"></span><b>La base quotidienne n’a pas pu être lue</b>`
    : (B.vide ? `<span class="ve-pt att"></span><b>La base quotidienne est vide pour l’instant</b> — le cron y enregistre chaque heure les journées calculées`
      : `<span class="ve-pt ok"></span><b>Base à jour — dernier enregistrement le ${esc(B.fluxLe)}</b>`);
  const table = B.vide || B.erreur ? '' : `<span>Table <code>ceo_verif_jour</code> : <b>${nf0(B.lignes)} ligne${B.lignes > 1 ? 's' : ''}</b> (${B.magasins} magasin${B.magasins > 1 ? 's' : ''}, du ${esc(B.premier)} au ${esc(B.dernier)})</span>`;
  const peu = v.semRenseignees < 2 ? `La base a ${v.semRenseignees} semaine renseignée sur la période : courbes et comparaisons se lisent à partir de deux semaines, et deviennent solides vers huit.`
    : (v.semRenseignees < 8 ? `${v.semRenseignees} semaines renseignées : la comparaison oppose ${v.compareN > 1 ? `les ${v.compareN} premières aux ${v.compareN} dernières` : 'la première à la dernière'} ; elle devient solide vers huit semaines.` : '');
  const statut = `<div class="vr-card ve-base">
    <div class="ve-l1">${etat}${table}${B.recettesLe !== '—' ? `<span>Recettes : photo du ${esc(B.recettesLe)}</span>` : ''}</div>
    <div class="ve-l2">Chaque heure, le cron enregistre les journées calculées (l’audit des flux) et, quand toutes les recettes sont lues, la photo du jour de leur couverture ; les sept derniers jours sont recalculés chaque jour — une cuisson validée après coup y entre. Rien ne s’efface.</div>
    ${v.synthese || peu ? `<div class="ve-syn">${v.synthese ? `<b>Ce que les semaines disent :</b> ${esc(v.synthese)}` : ''}${v.synthese && peu ? '<br>' : ''}${peu ? `<span class="vr-mu">${esc(peu)}</span>` : ''}</div>` : ''}</div>`;
  const plan = v.toutEstime ? `<div class="vx-mesure"><b>Aucune semaine n’est encore mesurée :</b> sans cuissons validées, le « produit » est le plan de production — le non expliqué et l’invendu sont alors des <b>écarts au plan</b> (traits pointillés), pas des fuites. La poubelle déclarée et les recettes, elles, sont réelles. Dès que les magasins valident leurs cuissons (Production › Validation et suivi), la courbe « Journées mesurées » monte et les traits deviennent pleins.</div>` : '';
  const seuil = { nonExpl: 'vert ≤ ' + ((v.seuils.nonExpl || [3])[0]) + ' %, orange ≤ ' + ((v.seuils.nonExpl || [3, 6])[1]) + ' %', poubelle: 'vert ≤ ' + ((v.seuils.poubelle || [6])[0]) + ' %, orange ≤ ' + ((v.seuils.poubelle || [6, 9])[1]) + ' %' }[v.ind];
  const graphe = `<div class="vr-card"><div class="vx-ct"><h3>${esc(v.nomInd)}, semaine par semaine</h3><span class="vr-lu">${esc(v.sousInd)}${seuil ? ' · ' + seuil : ''}${v.g.estimeQuelquePart ? ' · pointillé : semaine estimée (plan)' : ''} · un point = une semaine, survolez-le</span></div>
    <div class="ve-gw">${tplGraphique(v, x)}</div></div>`;
  const tableau = `<div class="vr-card"><div class="vx-ct"><h3>Avant, maintenant</h3><span class="vr-lu">${v.compareN > 1 ? `les ${v.compareN} premières semaines renseignées face aux ${v.compareN} dernières` : (v.compareN === 1 ? 'la première semaine renseignée face à la dernière' : 'les premières semaines face aux dernières')} · la flèche dit si la gestion s’améliore · un clic sur un indicateur le met au graphique</span></div>
    <div class="vr-tw"><table class="vr-t ve-t"><thead><tr><th class="g">Magasin</th>${v.colonnes.map(k => `<th colspan="2" class="ve-th${k.on ? ' on' : ''}" ${x.A(k.choisir)}>${esc(k.nom)}<small>avant → maintenant</small></th>`).join('')}</tr></thead><tbody>
    ${v.lignes.map(l => `<tr class="${l.reseau ? 'tot' : ''}${l.fort ? ' ve-fort' : ''}"><td class="g nom vx-clic" ${x.A(l.choisir)}><b>${esc(l.nom)}</b></td>${l.cases.map(k => `<td class="ve-spc${k.on ? ' on' : ''} ${k.ton || ''}">${sparkline(k.sp)}</td><td class="${k.on ? 'on' : ''}">${k.vide ? '<span class="vr-mu">—</span>' : `<span class="ve-av">${k.avant} → <b>${k.apres}</b></span><small class="ve-s ${k.ton}">${k.fleche} ${esc(k.sens)} ${k.ecart}${k.estime ? ' · plan' : ''}</small>`}</td>`).join('')}</tr>`).join('')}
    </tbody></table></div>
    <div class="vr-pied">Chaque semaine est la somme de ses journées, rapportée à la somme des disponibles (gardé d’hier + produit, au prix de vente) : le taux d’une journée moyenne. Avant / maintenant : la moyenne des premières semaines renseignées face à celle des dernières ; moins de ${nf1(0.3)} point d’écart, c’est stable. Moins de poubelle, de non expliqué et d’invendu, c’est mieux ; plus de journées mesurées et de recettes fiables, aussi. Tout se lit dans la base quotidienne, sans relire le panel : une journée enregistrée garde ses chiffres, et une recette corrigée se voit comme une amélioration à partir du jour où elle l’a été.</div></div>`;
  return `<div data-screen="verification-evolution" class="vr">${tplHaut(v, x)}${statut}${plan}${graphe}${tableau}</div>`;
}

const nf0 = n => Number(n || 0).toLocaleString('fr-BE').replace(/[  ]/g, ' ');
const nf1 = n => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
