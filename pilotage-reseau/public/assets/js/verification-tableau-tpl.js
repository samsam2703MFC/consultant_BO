/* Cockpit CEO — Matières premières par fournisseur ou par catégorie, et le formulaire du prix (14/11/2026). Valeurs :
 * verification-tableau-vals.js ; style : verification.css (vt-). Maquette : docs/maquettes/matieres-par-fournisseur. */

export function tplTableau(T, x){
  const { esc } = x;
  if (!T) { return ''; }
  const filtre = `<div class="vt-fil"><div class="rc-seg"><button class="${T.mode === 'fournisseur' ? 'on' : ''}" ${x.A(T.choisirMode('fournisseur'))}>Par fournisseur</button><button class="${T.mode === 'categorie' ? 'on' : ''}" ${x.A(T.choisirMode('categorie'))}>Par catégorie</button></div>
    <select class="vr-sel vt-choix" ${x.C(T.choisir)}>${T.options.map(o => `<option value="${esc(o.nom)}"${o.on ? ' selected' : ''}>${esc(o.nom)} (${o.n})</option>`).join('')}</select>
    <input id="vt-q" class="vm-q" type="search" placeholder="Chercher une matière…" value="${esc(T.q)}" ${x.I(T.poserQ)}></div>`;
  const kpis = `<div class="vm-kpis">${T.kpis.map(k => `<div class="vm-kpi ${k[3]}"><div class="k">${esc(k[0])}</div><div class="v">${k[1]}</div><div class="s">${esc(k[2])}</div></div>`).join('')}</div>`;
  const nCol = 5 + T.mags.length + (T.montrerF ? 1 : 0);
  const cell = (c, l) => `<td class="vt-c" ${x.A(l.prix(c.id))} title="Ouvrir le formulaire du prix pour ce magasin"><span class="vm-p ${c.ton}">${esc(c.txt)}</span>${c.e ? `<small class="vt-e ${c.ton}">${esc(c.e)}</small>` : ''}</td>`;
  const ligne = l => `<tr><td class="vt-lis ${l.etat}"></td><td class="g nom"><b>${esc(l.nom)}</b><small>€ / ${esc(l.par)}<span class="vm-src${l.source === 'centrale' ? ' c' : ''}">${esc(l.source)}</span></small></td>
    ${T.montrerF ? `<td class="g vt-f">${l.fournisseur ? esc(l.fournisseur.nom) : '<span class="vr-mu">aucun</span>'}</td>` : ''}
    <td class="g vt-ref">${l.ref.txt ? `<b>${esc(l.ref.txt)}</b>` : '<span class="vr-mu">—</span>'}<small>${esc(l.ref.quoi)}${l.fournisseur ? ' · ' + esc(l.fournisseur.txt) : ''}</small></td>
    ${l.cellules.map(c => cell(c, l)).join('')}<td class="vr-mu">${esc(l.recettes)}</td>
    <td class="g vt-act"><button class="rc-btn" ${x.A(l.prix(null))}>Prix…</button> <button class="rc-btn gris" ${x.A(l.fiche)}>Fiche</button></td></tr>`;
  const table = T.groupes.length ? `<div class="vr-tw"><table class="vr-t vt-t"><thead><tr><th></th><th class="g">Matière</th>${T.montrerF ? '<th class="g">Fournisseur</th>' : ''}<th class="g">Prix fournisseur<small>liste en vigueur, sinon prix général</small></th>
    ${T.mags.map(m => `<th>${esc(m)}<small>prix · écart</small></th>`).join('')}<th>Recettes<small>n · CA 30 j</small></th><th></th></tr></thead><tbody>
    ${T.groupes.map(g => `<tr class="vt-grp"><td colspan="${nCol}">${esc(g.nom)} <span>${g.n} matière${g.n > 1 ? 's' : ''} · ${esc(g.ca)}</span></td></tr>${g.lignes.map(ligne).join('')}`).join('')}
    </tbody></table></div>` : `<div class="vr-vide">Aucune matière pour ce choix.</div>`;
  return `${filtre}${kpis}${table}${tplForm(T.form, x)}`;
}

function tplForm(F, x){
  const { esc } = x;
  if (!F) { return ''; }
  const mags = `<div class="vt-mags">${F.magasins.map(m => `<button class="vx-tog${m.on ? ' on' : ''}" ${x.A(m.basculer)}><i></i>${esc(m.nom)} <small>${esc(m.prix)}</small></button>`).join('')}</div>`;
  const champs = F.champs.map(c => `<div class="vt-ch"><input id="${c.idK}" class="rc-in vt-k" placeholder="champ" value="${esc(c.k)}" ${x.I(c.poserK)}><span>=</span><input id="${c.idV}" class="rc-in vt-v" placeholder="valeur ou {prix}" value="${esc(c.v)}" ${x.I(c.poserV)}><button class="vt-x" ${x.A(c.retirer)} title="Retirer ce champ">✕</button></div>`).join('');
  const apercu = F.apercu ? `<div class="vt-bloc"><h4>Ce que le cockpit enverra au panel — ${F.apercu.length} envoi${F.apercu.length > 1 ? 's' : ''}</h4>${F.apercu.map(a => `<div class="vt-env${a.permise ? '' : ' ko'}"><div class="vr-mu">${esc(a.resume)}${a.permise ? '' : ' — route refusée : seules les routes de prix du panel partent d’ici'}</div><pre>${esc(a.txt)}</pre></div>`).join('')}</div>` : '';
  const refus = F.refus ? `<div class="vt-bloc"><div class="rc-ko">✕ ${esc(F.refus)}</div></div>` : '';
  const resultat = F.resultat ? `<div class="vt-bloc"><h4>La réponse du panel</h4>${F.resultat.map(r => `<div class="vt-env ${r.ok ? 'ok' : 'ko'}"><div>${r.ok ? '✓' : '✕'} ${esc(r.txt)}${r.http ? ` <span class="rc-code">HTTP ${esc(String(r.http))}</span>` : ''}</div>${r.reponse ? `<pre>${esc(r.reponse)}</pre>` : ''}</div>`).join('')}</div>` : '';
  return `<div class="vr-voile" ${x.A(F.fermer)}></div><div class="vt-modale" role="dialog" aria-label="Le prix d’une matière">
    <div class="vt-tete"><div><b>${esc(F.nom)}</b><small>${F.fournisseur ? 'Fournisseur : ' + esc(F.fournisseur) + (F.fid ? ' (n° ' + F.fid + ')' : '') + ' · ' : ''}prix de référence ${esc(F.refTxt)} / ${esc(F.par)}</small></div><button class="vt-x" ${x.A(F.fermer)} title="Fermer">✕</button></div>
    <div class="vt-corps">
      <div class="vt-sec"><h4>Le prix</h4><label class="vt-prix"><input id="vt-prix" class="rc-in" inputmode="decimal" value="${esc(F.prix)}" ${x.I(F.poserPrix)}> € HT / ${esc(F.par)}</label><small class="vr-mu">${esc(F.prixBase)} — c’est la valeur de {prix}</small></div>
      <div class="vt-sec"><h4>Les magasins</h4>${mags}<small class="vr-mu">Un envoi par magasin coché ; {sid} prend le numéro du magasin.</small></div>
      <div class="vt-sec"><h4>La route du panel</h4><div class="vt-presets">${F.presets.map(p => `<button class="${p.on ? 'on' : ''}" ${x.A(p.choisir)}>${esc(p.nom)}</button>`).join('')}</div>
        <div class="vt-route"><select class="vr-sel" ${x.C(F.poserMethode)}>${F.methodes.map(m => `<option${m.on ? ' selected' : ''}>${m.m}</option>`).join('')}</select><input id="vt-route" class="rc-in" value="${esc(F.route)}" ${x.I(F.poserRoute)}></div></div>
      <div class="vt-sec"><h4>Les données envoyées</h4>${champs}<button class="vx-lien" ${x.A(F.ajouter)}>+ Ajouter un champ</button>
        <small class="vr-mu">Remplacés à l’envoi : {prix} {sid} {mid} {fid} {date}. Un nombre écrit tel quel part en nombre.</small></div>
      ${F.apercuLocal ? `<div class="vt-sec"><h4>Le corps, pour le premier magasin</h4><pre>${esc(F.apercuLocal)}</pre></div>` : ''}
      ${refus}${apercu}${resultat}
    </div>
    <div class="vt-pied"><button class="vx-tog${F.garder ? ' on' : ''}" ${x.A(F.basculerGarder)} title="Si le panel accepte, ce format devient celui de tous les prix écrits par le cockpit"><i></i>Garder ce format s’il est accepté</button><span class="vr-sp"></span>
      <button class="rc-btn gris" ${x.A(F.fermer)}>Fermer</button>
      ${F.apercu ? `<button class="rc-btn ok" ${x.A(F.envoyer)}${F.enCours ? ' disabled' : ''}>${F.enCours ? 'Envoi…' : 'Envoyer au panel'}</button>`
        : `<button class="rc-btn" ${x.A(F.verifier)}${!F.verifier || F.enCours ? ' disabled' : ''}>${F.enCours ? 'Préparation…' : 'Vérifier ce qui sera envoyé'}</button>`}</div></div>`;
}
