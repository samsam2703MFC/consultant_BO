/* Cockpit CEO — Matières premières › Références et pertes (26/11/2026) : le gabarit. Valeurs : verification-refs-vals.js ;
 * style : verification.css (vr-, vm-, rc-). */

export function tplRefs(P, x){
  const { esc } = x;
  if (!P) { return ''; }
  const fil = `<div class="vr-fil"><input id="vr-ref-q" class="vm-q" type="search" placeholder="Recette : son nom (tagliata) ou son numéro" value="${esc(P.q)}" ${x.I(P.poserQ)}>
    <button class="vr-btn" ${x.A(P.chercher)}>Ouvrir la recette</button><button class="vr-btn" ${x.A(P.tout)}>Toute la carte</button>
    <span class="vr-sp"></span>${P.titre ? `<b>${esc(P.titre)}</b>` : ''}${P.relire ? `<button class="vr-btn" ${x.A(P.relire)}${P.chargement ? ' disabled' : ''}>${P.chargement ? 'Lecture…' : 'Relire'}</button>` : ''}</div>`;
  if (P.chargement) { return fil + `<div class="vr-etat"><span class="vr-roue"></span>Lecture des matières, de leurs prix dans chaque magasin, de leur référence et de leur perte au panel…</div>`; }
  if (P.erreur) { return fil + `<div class="vr-etat ko">${esc(P.erreur)}</div>`; }
  if (P.choix) { return fil + `<div class="vr-etat">${esc(P.choix.motif)}<div class="vr-chips">${P.choix.liste.map(c => `<button ${x.A(c.ouvrir)}>${esc(c.nom)}</button>`).join('')}</div></div>`; }
  if (!P.lignes) {
    return fil + `<div class="vr-pied"><b>Références et pertes</b> : ouvrez une recette (la Tagliata d’abord), ou toute la carte. Pour chaque matière, le cockpit propose <b>le prix du réseau</b> (la moyenne des magasins qui la chiffrent par leur propre prix), sinon un <b>prix de marché belge</b> estimé, et <b>la perte</b>. Il les écrit comme prix de référence et perte de la matière, pour tout le réseau, sans toucher aux prix d’achat des magasins : au panel, le prix d’un magasin passe avant la référence.</div>`;
  }
  const kpis = `<div class="vm-kpis">${P.kpis.map(k => `<div class="vm-kpi ${k[3]}"><div class="k">${esc(k[0])}</div><div class="v">${k[1] == null ? '—' : k[1]}</div><div class="s">${esc(k[2])}</div></div>`).join('')}</div>`;
  const cell = c => `<td class="vm-px"><span class="vm-p ${c.ton}">${esc(c.txt)}${c.sous ? `<small>${esc(c.sous)}</small>` : ''}</span></td>`;
  const ligne = l => `<tr class="${l.coche ? 'vm-on' : ''}"><td><input type="checkbox"${l.coche ? ' checked' : ''} ${x.A(l.cocher)}></td>
    <td class="g nom"><b>${esc(l.nom)}</b><small>${esc(l.cat)}<span class="vm-src${l.centrale ? ' c' : ''}">${l.centrale ? 'centrale' : 'libre'}</span> · € / ${esc(l.par)}</small></td>
    <td class="g vm-emploi">${esc(l.emploi)}</td>${l.magasins.map(cell).join('')}
    <td>${esc(l.reference)}</td><td>${esc(l.perteAvant)}</td>
    <td class="g vm-ref"><input id="${esc(l.idPrix)}" class="rc-in${l.invalide ? ' ko' : ''}" inputmode="decimal" value="${esc(l.prix)}" ${x.I(l.poserPrix)}> € / ${esc(l.par)}<small class="vm-sous"><span class="vm-p ${l.origineTon}">${esc(l.origine)}</span> ${esc(l.sourcePrix)}</small></td>
    <td class="g"><input id="${esc(l.idPerte)}" class="rc-in" inputmode="decimal" value="${esc(l.perte)}" ${x.I(l.poserPerte)}> %${l.raison ? `<small class="vm-sous">${esc(l.raison)}</small>` : ''}</td></tr>`;
  const table = `<div class="vr-tw"><table class="vr-t vm-t"><thead><tr><th></th><th class="g">Matière</th><th class="g">Employée</th>${P.mags.map(m => `<th>${esc(m)}<small>prix du magasin</small></th>`).join('')}
    <th>Référence<small>au panel</small></th><th>Perte<small>au panel</small></th><th class="g">Prix de référence proposé<small>réseau, sinon marché</small></th><th class="g">Perte proposée</th></tr></thead>
    <tbody>${P.lignes.map(ligne).join('')}</tbody></table></div>`;
  const B = P.barre;
  const apercu = B.apercu ? (B.apercu.erreur ? `<div class="rc-ko">✕ ${esc(B.apercu.erreur)}</div>`
    : `<div class="rc-apercu"><b>${esc(B.apercu.resume)}</b>${B.apercu.ecritures.map(e => `<div class="vr-mu">${esc(e.resume)} <span class="rc-code">${esc(e.route)}</span> <span class="rc-code">${esc(e.corps)}</span></div>`).join('')}${B.apercu.erreurs.map(t => `<div class="rc-ko">✕ ${esc(t)}</div>`).join('')}</div>`) : '';
  const barre = `<div class="vm-barre"><div><b>${B.n} matière${B.n > 1 ? 's' : ''} cochée${B.n > 1 ? 's' : ''}</b>
      <small>Rien ne part sans « Vérifier » puis « Appliquer au panel » : PATCH /materials/{id} (prix de référence et perte, pour tout le réseau), relu, noté au registre (« Annuler »), puis le food cost des recettes relu avant et après. <label><input type="checkbox"${P.admin.on ? ' checked' : ''} ${x.A(P.admin.basculer)}> avec le compte administrateur du cockpit</label></small>
      <small><label><input type="checkbox"${P.aligner.on ? ' checked' : ''} ${x.A(P.aligner.basculer)}> <b>Même food cost dans tous les magasins</b> : le prix d’un magasin qui s’écarte de la référence la reprend (POST /materials/{id}/manual-price, au registre)</label></small>${B.progres ? `<small><b>${esc(B.progres)}</b></small>` : ''}${apercu}</div>
    ${B.apercu && !B.apercu.erreur ? `<button class="rc-btn ok" ${x.A(B.appliquer)}${!B.appliquer || B.enCours ? ' disabled' : ''}>${B.enCours ? 'Écriture…' : 'Appliquer au panel'}</button>`
      : `<button class="rc-btn" ${x.A(B.verifier)}${!B.verifier || B.enCours ? ' disabled' : ''}>${B.enCours ? 'Préparation…' : 'Vérifier ce qui sera écrit'}</button>`}</div>`;
  const R = P.resultat;
  const res = R ? `<div class="rc-res ${R.ok ? 'ok' : 'ko'}" style="padding:10px 18px">${R.erreur ? esc(R.erreur) + '<br>' : ''}${R.lignes.map(t => esc(t)).join('<br>')}
    ${R.effet.map(m => `<div class="vm-bloc"><h4>Food cost relu — ${esc(m.magasin)}</h4><div class="vr-mu">${esc(m.resume)}</div>${m.lignes.map(f => `<div>${f.bouge ? '<b>' : ''}${esc(f.nom)}${f.bouge ? '</b>' : ''} : ${esc(f.txt)}</div>`).join('')}</div>`).join('')}
    ${R.pertes ? `<div class="vm-bloc"><h4>La perte</h4>${esc(R.pertes.message)}${R.pertes.poser ? ` <button class="rc-btn" ${x.A(R.pertes.poser)}${R.pertes.enCours ? ' disabled' : ''}>${R.pertes.enCours ? 'Écriture…' : 'Poser les pertes sur les lignes'}</button>` : ''}${R.pertes.res ? `<div>${esc(R.pertes.res)}</div>` : ''}</div>` : ''}
    <button class="rc-btn gris" ${x.A(R.fermer)}>OK</button></div>` : '';
  const L = P.pertesLignes;
  const pertesLignes = L ? `<div class="vm-bloc" style="margin:12px 18px"><h4>Pertes sur les lignes — toute la carte</h4>
    <div class="vr-mu">Le coût d’une recette ne compte que la perte posée sur sa ligne (mesuré au panel sur la Tagliata). Ici, dans toutes les recettes et sous-recettes de la carte, la ligne d’une matière prend la perte que la matière porte au panel. Posez d’abord les pertes des matières ci-dessus (« Toute la carte », puis Appliquer). Chaque recette est écrite entière, relue, notée au registre (« Annuler »).</div>
    <div style="margin-top:8px"><button class="rc-btn" ${x.A(L.verifier)}${L.enCours ? ' disabled' : ''}>Vérifier (15 premières recettes)</button>
    <button class="rc-btn ok" ${x.A(L.appliquer)}${!L.appliquer ? ' disabled' : ''}>${L.enCours ? 'Écriture…' : 'Appliquer à toute la carte'}</button></div>
    ${L.etat ? `<div class="${L.ko ? 'rc-ko' : ''}" style="margin-top:6px">${esc(L.etat)}</div>` : ''}
    ${L.apercu ? `<div class="rc-apercu">${L.apercu.lignes.slice(0, 20).map(t => `<div class="vr-mu">${esc(t)}</div>`).join('')}</div>` : ''}
    ${L.erreurs.length ? `<div class="rc-ko">${L.erreurs.map(t => esc(t)).join('<br>')}</div>` : ''}</div>` : '';
  const pied = `<div class="vr-pied"><b>Le prix proposé</b> : la moyenne des magasins qui chiffrent la matière par leur propre prix (local ou fournisseur), sinon un prix de marché belge estimé (fichier daté du ${esc(P.maj)}), à remplacer par le tarif du fournisseur dès qu’il existe. <b>La perte</b> : celle validée, sinon une perte courante de la famille de matière (persil 30 %, oignons 10 %…). Tout reste modifiable avant l’écriture ; une ligne cochée part. <span class="vr-mu">${esc(P.source)}</span></div>`;
  const T = P.toutFaire;
  const tout = T ? `<div class="vm-barre" style="background:#f6f1e7"><div><b>Tout faire, toute la carte</b>
      <small>En un clic : les ${T.n} matière(s) cochée(s) (prix de référence du réseau ou de marché, pertes${P.aligner.on ? ', même prix dans tous les magasins' : ''}), par lots, relues et notées au registre ; puis la perte de chaque matière posée sur ses lignes, dans toutes les recettes et sous-recettes. Chaque écriture s’annule depuis le registre.</small>
      ${T.etat ? `<small><b>${esc(T.etat)}</b></small>` : ''}</div>
    <button class="rc-btn ok" ${x.A(T.lancer)}${!T.lancer ? ' disabled' : ''}>${T.enCours ? 'En cours…' : (T.fini ? 'Refaire' : 'Tout faire')}</button></div>` : '';
  return fil + kpis + tout + res + table + barre + pertesLignes + pied;
}
