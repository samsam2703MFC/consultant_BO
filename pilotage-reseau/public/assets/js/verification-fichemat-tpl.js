/* Cockpit CEO — la fiche d'une matière, modifiable (14/11/2026) : le gabarit. Valeurs : verification-fichemat-vals.js ;
 * style : verification.css (vx-). Maquette : docs/maquettes/fiche-matiere. */

export function tplFicheMat(F, x){
  if (!F) { return ''; }
  const { esc } = x;
  const voile = `<div class="vr-voile" ${x.A(F.fermer)}></div>`;
  const tete = (t, s) => `<div class="vr-fi-t"><div><b>${t}</b>${s ? `<small>${s}</small>` : ''}</div><button class="vr-x" ${x.A(F.fermer)} aria-label="Fermer">×</button></div>`;
  if (F.chargement) { return `<div class="vr">${voile}<div class="vr-fiche vx-fiche">${tete('Lecture de la matière…', '')}<div class="vr-etat"><span class="vr-roue"></span>La matière au panel, ses fournisseurs, ses prix dans chaque magasin.</div></div></div>`; }
  if (F.erreur) { return `<div class="vr">${voile}<div class="vr-fiche vx-fiche">${tete('Fiche indisponible', '')}<div class="vr-etat ko">${esc(F.erreur)}</div></div></div>`; }
  const champ = p => {
    const note = p.change ? `<small class="av">avant : ${esc(p.avantTxt == null || p.avantTxt === '' ? '—' : p.avantTxt)}</small>` : (p.note ? `<small>${esc(p.note)}</small>` : '');
    if (p.type === 'lecture') { return `<label class="vx-champ">${esc(p.lib)}<input value="${esc(p.txt)}" disabled></label>`; }
    if (p.type === 'choix') { return `<label class="vx-champ">${esc(p.lib)}<select class="${p.change ? 'mod' : ''}" ${x.C(p.poser)}>${p.options.map(o => `<option value="${esc(o.id)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>${note}</label>`; }
    return `<label class="vx-champ${p.large ? ' l2' : ''}">${esc(p.lib)}<input id="${esc(p.id)}" class="${p.change ? 'mod' : ''}" value="${esc(p.val)}" ${x.I(p.poser)}>${note}</label>`;
  };
  const params = `<div class="vx-sec"><h3>Paramètres <span>— pour tout le réseau : chaque magasin, chaque recette qui l’emploie</span></h3>
    <div class="vx-grille">${F.params.map(champ).join('')}</div>
    <div class="vx-togs">${F.oui.map(o => `<button class="vx-tog${o.on ? ' on' : ''}${o.change ? ' mod' : ''}" ${x.A(o.basculer)}${o.basculer ? '' : ' disabled'}><i></i>${esc(o.lib)}</button>`).join('')}</div>
    ${F.allergenes ? `<div class="vx-champ">Allergènes<div class="vx-al">${F.allergenes.map(a => `<button class="${a.on ? 'on' : ''}" ${x.A(a.basculer)}>${esc(a.nom)}</button>`).join('')}</div></div>` : '<div class="vx-champ">Allergènes<small>non rendus par le panel pour cette matière</small></div>'}
    ${F.recettes.length ? `<div class="vx-champ">Employée dans<div class="vx-rec">${F.recettes.map(r => `<span>${esc(r)}</span>`).join('')}</div></div>` : ''}
    ${F.alerteUnite ? `<div class="vx-alerte">${esc(F.alerteUnite)}</div>` : ''}</div>`;
  const prixIn = p => p.poser ? `${p.change ? `<span class="rc-av">${esc(p.avant)}</span> ` : ''}<input id="${esc(p.id)}" class="${p.change ? 'mod' : ''}" inputmode="decimal" value="${esc(p.val)}" ${x.I(p.poser)}>` : `<span class="vr-mu">${esc(p.avant)}</span>`;
  const e = (t, ton) => t ? `<span class="vx-e ${ton}">${esc(t)}</span>` : '';
  const prix = `<div class="vx-sec vx-px"><h3>Prix — la comparaison <span>— par ${esc(F.par)}, hors TVA ; l’écart de chaque prix au prix général</span></h3>
    <table class="vr-t"><thead><tr><th class="g">Source</th><th>Prix<small>€ / ${esc(F.par)}</small></th><th>Écart<small>au prix général</small></th><th class="g">État</th><th></th></tr></thead><tbody>
    <tr class="vx-gen"><td class="g nom"><b>${esc(F.general.nom)}</b><small>${esc(F.general.sous)}</small></td><td>${prixIn(F.general)}</td><td></td><td class="g vr-mu">${F.general.modifiable ? '' : 'non rendu par le panel'}</td><td>${F.auGeneral ? `<button class="vx-lien" ${x.A(F.auGeneral)}>Tous les magasins au prix général</button>` : ''}</td></tr>
    <tr><td class="g nom"><b>${esc(F.conseille.nom)}</b></td><td>${prixIn(F.conseille)}</td><td>${e(F.conseille.e, F.conseille.ton)}</td><td class="g vr-mu">${F.conseille.modifiable ? '' : 'non rendu par le panel'}</td><td></td></tr>
    <tr class="vx-grp"><td colspan="5">Fournisseurs liés</td></tr>
    ${F.fourn.length ? F.fourn.map(f => `<tr><td class="g nom"><b>${esc(f.nom)}</b><small>${esc(f.sous)}</small></td><td>${esc(f.prix)}</td><td>${e(f.e, f.ton)}</td><td class="g vx-etat">${esc(f.etat)}</td><td>${f.prendre ? `<button class="vx-lien" ${x.A(f.prendre)}>Prendre comme prix général</button>` : ''}</td></tr>`).join('') : '<tr><td class="g vr-mu" colspan="5">Aucun fournisseur lié à la matière au panel.</td></tr>'}
    <tr class="vx-grp"><td colspan="5">Magasins</td></tr>
    ${F.mags.map(m => `<tr><td class="g nom"><b>${esc(m.nom)}</b></td><td>${m.prix ? `<span class="${m.change ? 'rc-av' : ''}">${esc(m.prix)}</span> ` : ''}<input id="${esc(m.inp)}" class="${m.change ? 'mod' : ''}" inputmode="decimal" placeholder="${m.sansPrix ? 'sans prix' : 'nouveau'}" value="${esc(m.val)}" ${x.I(m.poser)}></td>
      <td>${e(m.e, m.ton)}${m.e2 ? ` → ${e(m.e2, m.ton2)}` : ''}</td><td class="g vx-etat">${m.change ? 'à écrire' : (m.lu ? '' : 'magasin non lu')}</td><td>${m.prendre ? `<button class="vx-lien" ${x.A(m.prendre)}>Prendre comme prix général</button>` : ''}</td></tr>`).join('')}
    </tbody></table><div class="vx-synthese">${esc(F.synthese)}</div></div>`;
  const effet = F.effet.length ? `<div class="vx-sec"><h3>L’effet sur les recettes <span>— le coût de la pièce, avant et après, dans les magasins dont le prix change</span></h3><table class="vr-t"><thead><tr><th class="g">Produit</th><th class="g">Magasin</th><th>Quantité<small>par pièce</small></th><th>Avant</th><th>Après</th></tr></thead><tbody>
    ${F.effet.map(r => `<tr><td class="g nom"><b>${esc(r.nom)}</b></td><td class="g">${esc(r.magasin)}</td><td>${esc(r.q)}</td><td><span class="rc-av">${esc(r.avant)}</span></td><td><b class="rc-ap">${esc(r.apres)}</b></td></tr>`).join('')}</tbody></table></div>` : '';
  const apercu = F.apercu ? `<div class="rc-apercu" style="margin:10px 20px"><b>Ce qui sera écrit au panel</b>${F.apercu.map(a => `<div>${esc(a.resume)} <span class="rc-code">${esc(a.route)}</span><details><summary class="vr-mu">corps</summary><pre class="rc-pre">${esc(a.corps)}</pre></details>${a.annulable ? '' : '<span class="vr-mu"> · premier prix, sans retour</span>'}</div>`).join('')}</div>` : '';
  const res = F.resultat ? `<div class="rc-res ${F.resultat.ok ? 'ok' : 'ko'}" style="margin:10px 20px">${F.resultat.lignes.map(t => esc(t)).join('<br>')}</div>` : '';
  const barre = `<div class="rc-barre"><div><b>${F.n ? F.n + ' changement' + (F.n > 1 ? 's' : '') : 'Aucun changement'}</b><small>${F.refus ? '✕ ' + esc(F.refus) : (F.invalide ? 'Une valeur n’est pas un nombre valable.' : 'Les paramètres partent en PATCH /materials/' + F.mid + ' (les seuls champs changés), les prix des magasins un par un ; tout est relu et noté au registre, « Annuler » remet l’avant.')}</small></div><span class="vr-sp"></span>
    <button class="rc-btn gris" ${x.A(F.annulerTout)}>Annuler les changements</button>
    ${F.apercu ? `<button class="rc-btn ok" ${x.A(F.enregistrer)}${F.enCours ? ' disabled' : ''}>${F.enCours ? 'Écriture…' : 'Enregistrer au panel'}</button>` : `<button class="rc-btn" ${x.A(F.verifier)}${!F.verifier || F.enCours ? ' disabled' : ''}>${F.enCours ? 'Préparation…' : 'Vérifier ce qui sera écrit'}</button>`}</div>`;
  const liens = F.liens.length ? `<div class="rc-fiche-act"><span class="vr-mu">Au panel :</span>${F.liens.map(l => `<a class="rc-ln" href="${esc(l.url)}" target="_blank" rel="noopener">${esc(l.txt)} ↗</a>`).join('')}</div>` : '';
  return `<div class="vr">${voile}<div class="vr-fiche vx-fiche">${tete(esc(F.nom), esc(F.sous))}${liens}${params}${prix}${effet}${apercu}${res}<div style="height:14px"></div>${barre}</div></div>`;
}
