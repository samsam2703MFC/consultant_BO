/* Cockpit CEO — Franchisés › Demandes des franchisés (15/11/2026) : le gabarit. Valeurs : helpdesk-vals.js ;
 * style : helpdesk.css (hd-). Maquettes : docs/maquettes/helpdesk-franchises (A, B). */

export function tplHelpdesk(c, x){
  const { esc } = x;
  const V = c.hd;
  if (!V) { return ''; }
  const onglets = `<div class="hd-onglets">${V.onglets.map(o => `<button class="${o.on ? 'on' : ''}" ${x.A(o.choisir)}>${esc(o.nom)}${o.sous ? `<small>${esc(o.sous)}</small>` : ''}</button>`).join('')}</div>`;
  if (V.vue === 'reclamations') { return `<div data-screen="helpdesk" class="vr hd">${onglets}${tplReclamations(V.rc, x)}</div>`; }
  if (V.chargement) { return `<div data-screen="helpdesk" class="vr hd">${onglets}<div class="vr-card vr-etat"><span class="vr-roue"></span>Lecture des demandes du helpdesk au panel…</div></div>`; }
  if (V.erreur) { return `<div data-screen="helpdesk" class="vr hd">${onglets}<div class="vr-card vr-etat ko">${esc(V.erreur)}</div></div>`; }
  const kpis = `<div class="vm-kpis">${V.kpis.map(k => `<div class="vm-kpi ${k[3]}"><div class="k">${esc(k[0])}</div><div class="v">${esc(String(k[1]))}</div><div class="s">${esc(k[2])}</div></div>`).join('')}</div>`;
  const fil = `<div class="vt-fil"><div class="vr-chips hd-chips">${V.chips.map(ch => `<button class="${ch.on ? 'on' : ''}" ${x.A(ch.choisir)}>${esc(ch.nom)} <b>${ch.n}</b></button>`).join('')}</div>
    <select class="vr-sel" ${x.C(V.poserDom)}>${V.domaines.map(o => `<option value="${esc(o.id)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>
    <select class="vr-sel" ${x.C(V.poserMag)}>${V.magasins.map(o => `<option value="${esc(o.id)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>
    <input id="hd-q" class="vm-q" type="search" placeholder="Chercher une demande…" value="${esc(V.q)}" ${x.I(V.poserQ)}><span class="vr-sp"></span>
    <button class="vr-btn" ${x.A(V.relire)}${V.relecture ? ' disabled' : ''}>${V.relecture ? 'Lecture…' : 'Relire'}</button></div>`;
  const ligne = l => `<tr class="${l.on ? 'hd-on' : ''}" ${x.A(l.choisir)}><td class="hd-lis ${l.ton}"></td>
    <td class="g"><span class="hd-ref">${esc(l.ref)}</span><small>${esc(l.le)}</small></td><td class="g">${esc(l.magasin)}<small><span class="hd-dom">${esc(l.domaine)}</span></small></td>
    <td class="g hd-titre"><b>${esc(l.titre)}</b><small>${esc(l.extrait)}</small>${l.visite ? `<small><span class="hd-vis">${esc(l.visite)}</span></small>` : ''}</td><td class="g">${esc(l.consultant)}</td>
    <td><span class="hd-st ${l.ton}">${esc(l.statut)}</span>${l.rep ? `<small class="${l.repKo ? 'ko' : ''}">${esc(l.rep)}</small>` : ''}</td>
    <td class="hd-ech ${l.echTon}">${l.ech ? `<span>${esc(l.ech)}</span>${l.echSous ? `<small>${esc(l.echSous)}</small>` : ''}` : ''}</td>
    <td class="hd-rel ${l.relTon}">${l.rel ? `<span>${esc(l.rel)}</span>${l.relSous ? `<small>${esc(l.relSous)}</small>` : ''}` : ''}</td><td class="${l.vieux ? 'hd-vieux' : ''}">${esc(l.age)}</td></tr>`;
  const table = V.lignes.length ? `<div class="vr-tw"><table class="vr-t hd-t"><thead><tr><th></th><th class="g">Réf.</th><th class="g">Magasin</th><th class="g">Demande</th><th class="g">Consultant</th><th>Statut</th><th>Date limite</th><th>Relance</th><th>Âge</th></tr></thead>
    <tbody>${V.lignes.map(ligne).join('')}</tbody></table></div>` : `<div class="vr-vide">Aucune demande pour ce filtre.</div>`;
  const pied = `<div class="vr-pied"><b>Les demandes des franchisés</b> : le helpdesk du panel (${V.compte === 'admin' ? 'compte administrateur du cockpit' : 'compte consultant : seules les demandes d’un magasin'}). Une demande se lit, s’attribue, reçoit une réponse — le franchisé la lit dans son dashboard — et, quand elle demande un passage, devient une <b>mission de la visite</b> du magasin : deux tâches du consultant (contrôler, puis répondre), que l’application terrain montre sur la fiche de la visite, et ses points à contrôler. À la clôture de la mission, le constat repart en réponse et la demande passe « Résolue » si tout est conforme. <span class="vr-mu">${esc(V.source)}</span></div>`;
  return `<div data-screen="helpdesk" class="vr hd">${onglets}<div class="vr-card">${kpis}${fil}<div class="hd-cols">${table}${fiche(V.fiche, x)}</div>${sms(V.sms, x)}${pied}</div></div>`;
}

/** Le bloc des relances d'une demande, sur sa fiche (20/11/2026). */
function relances(R, x){
  const { esc } = x;
  return `<div class="hd-relances"><h4>Relances par SMS <span>— tant que le bureau n’a pas répondu</span></h4>
    ${R.envois.length ? R.envois.map(e => `<div class="hd-envoi"><b>${esc(e.quand)}</b><span>${e.a.map(a => `${esc(a.nom)} <i class="${a.ok ? 'ok' : 'ko'}" title="${esc(a.motif)}">${a.ok ? '✓' : '✕'}</i>`).join(' · ')}</span></div>`).join('') : '<div class="vr-mu">Aucune relance encore.</div>'}
    ${R.etat ? `<div class="hd-proch">${esc(R.etat)}</div>` : ''}
    <div class="hd-act">${R.suspendre ? `<button class="mko-pied-btn" ${x.A(R.suspendre)}>Suspendre les relances</button>` : ''}${R.reprendre ? `<button class="mko-pied-btn" ${x.A(R.reprendre)}>Reprendre les relances</button>` : ''}${R.maintenant ? `<button class="mko-pied-btn" ${x.A(R.maintenant)}>Relancer maintenant</button>` : ''}</div></div>`;
}

/** Le SMS au bureau (20/11/2026) : à qui part le résumé de chaque nouvelle demande, et les relances (maquette helpdesk-relances). */
function sms(M, x){
  const { esc } = x;
  if (!M) { return ''; }
  if (M.chargement) { return '<div class="hd-sms"><span class="vr-mu">Lecture du réglage des SMS…</span></div>'; }
  if (M.erreur) { return `<div class="hd-sms"><span class="hd-sms-ko">SMS au bureau : ${esc(M.erreur)}</span></div>`; }
  const tete = `<div class="hd-sms-t"><b>SMS au bureau</b><span class="${M.alerte ? 'hd-sms-ko' : ''}">${esc(M.resume)}</span><span class="vr-sp"></span><button class="mko-pied-btn" ${x.A(M.basculer)}>${M.ouvert ? 'Fermer' : 'Régler'}</button></div>`;
  const sansFournisseur = M.fournisseur ? '' : '<div class="hd-sms-ko">Aucun fournisseur SMS réglé : ni jeton SMSAPI dans MétaDonnée › Alertes SMS, ni clé « smsapi_token » dans erp_cles. Rien ne part en attendant.</div>';
  if (!M.ouvert) { return `<div class="hd-sms">${tete}${sansFournisseur}<div class="vr-mu">${esc(M.dernier)}</div></div>`; }
  const segs = l => `<div class="hd-seg">${l.map(o => `<button class="${o.on ? 'on' : ''}" ${x.A(o.choisir)}>${esc(o.nom)}</button>`).join('')}</div>`;
  const regle = (titre, sous, corps, note) => `<div class="hd-r"><div><b>${esc(titre)}</b>${sous ? `<small>${esc(sous)}</small>` : ''}</div><div>${corps}${note ? `<div class="note">${esc(note)}</div>` : ''}</div></div>`;
  const lignes = M.lignes.map(l => `<div class="hd-sms-l"><input class="mko-inp" placeholder="Nom" value="${esc(l.nom)}" ${x.C(l.setNom)}><input class="mko-inp${l.ko ? ' hd-sms-vide' : ''}" type="tel" placeholder="+32 470 12 34 56" value="${esc(l.tel)}" ${x.C(l.setTel)}>
    <select class="mko-inp" title="Le consultant du panel : il reçoit les relances de ses demandes" ${x.C(l.setConsultant)}>${l.consultants.map(o => `<option value="${esc(o.id)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>
    <button class="mko-pied-btn" ${x.A(l.essai)}${l.essai ? '' : ' disabled'} title="Un SMS d’essai à ce numéro (enregistré)">Essai</button><button class="mko-pied-btn" ${x.A(l.retirer)}>Retirer</button></div>`).join('');
  const gauche = `<div class="hd-col"><h3>Les numéros</h3><p>À chaque nouvelle demande, un SMS part à ces numéros : le magasin, le sujet, le titre, le début de la demande, le passage souhaité, les photos et la référence. Le consultant du panel de chaque numéro reçoit les relances de ses demandes. Le franchisé ne voit pas ces numéros.</p>
    <div class="hd-seg"><span>Nouvelles demandes</span><button class="${M.fActif ? 'on' : ''}" ${x.A(M.allumer)}>envoyées</button><button class="${M.fActif ? '' : 'on'}" ${x.A(M.couper)}>coupées</button></div>
    <div class="hd-sms-l hd-sms-th"><span>Nom</span><span>Numéro</span><span>Consultant du panel</span><span></span></div>
    ${lignes || '<div class="vr-mu">Aucun numéro.</div>'}
    ${M.ajouter ? `<div><button class="mko-pied-btn" ${x.A(M.ajouter)}>+ Ajouter un numéro</button></div>` : ''}
    <div class="hd-prochaines"><h3>Les prochaines relances</h3>${M.prochaines.length ? M.prochaines.map(p => `<div><b>${esc(p.quand)}</b> → ${esc(p.a)} : ${esc(p.quoi)} <small>(${esc(p.sous)})</small></div>`).join('') : `<div class="vr-mu">${esc(M.sansProchaine)}</div>`}</div></div>`;
  const droite = `<div class="hd-col"><h3>Les relances</h3><p>Une demande qui attend une réponse du bureau revient par SMS tant qu’elle attend. Sans lien.</p>
    ${regle('Relances', '', segs(M.rActif))}
    ${regle('Tous les', 'après l’envoi de la demande, puis à nouveau', segs(M.rJours) + `<div class="hd-heure">à <input class="mko-inp" value="${esc(M.rHeure)}" ${x.C(M.setHeure)}><button class="hd-ck${M.rOuvres ? ' on' : ''}" ${x.A(M.basculerOuvres)}><i></i>jours ouvrés seulement</button></div>`,
      'Une relance qui tombe un samedi ou un dimanche part le lundi. Rien ne part après 20:00. À la mise en route, ce qui attendait déjà part le premier lundi.')}
    ${regle('Quelles demandes', '', segs(M.rQuelles), 'Pas encore en cours : tant que la demande est « Nouvelle » ; elle s’arrête quand elle passe « En cours » (une date limite fixée l’y fait passer). Sans réponse : tant que le bureau n’a rien répondu au franchisé.')}
    ${regle('À qui', '', segs(M.rAQui), 'Le numéro dont le consultant du panel est celui de la demande. Sans consultant, ou un consultant sans numéro ici : tous les numéros.')}
    ${regle('Le même jour', 'plusieurs demandes à relancer', segs(M.rRegrouper))}
    ${regle('Jusqu’à', '', segs(M.rPlafond), 'S’arrête à la réponse du bureau, au statut Résolue, Fermée ou Annulée, ou à « Suspendre les relances » sur la fiche.')}
    ${M.apercu ? regle('Aperçu', M.apercu.titre, `<pre class="hd-apercu">${esc(M.apercu.texte)}</pre>`) : ''}
    <div class="hd-act" style="justify-content:flex-end">${M.msg ? `<span class="${M.msgOk ? 'hd-okmsg' : 'hd-sms-ko'}">${esc(M.msg)}</span>` : ''}<button class="rc-btn ok" ${x.A(M.enregistrer)}${M.enregistrer ? '' : ' disabled'}>${M.enCours ? 'Envoi…' : 'Enregistrer'}</button></div></div>`;
  return `<div class="hd-sms on">${tete}<div class="hd-sms2">${gauche}${droite}</div>${sansFournisseur}<div class="vr-mu">${esc(M.dernier)}</div></div>`;
}

function fiche(F, x){
  const { esc } = x;
  if (!F) { return '<aside class="vr-card hd-fiche"><div class="vr-mu">Choisissez une demande.</div></aside>'; }
  const E = F.envoi;
  const msg = E && !E.enCours && E.msg ? `<div class="${E.ok ? 'hd-okmsg' : 'rc-ko'}">${E.ok ? '' : '✕ '}${esc(E.msg)}</div>` : (E && E.enCours ? '<div class="vr-mu">Envoi au panel…</div>' : '');
  const mission = m => `<div class="hd-mission${m.cloture ? ' close' : ''}"><div class="hd-mt"><span class="hd-src">Mission de visite</span><b>${esc(m.visite)} · ${esc(m.quand)}</b>${m.cloture ? `<span class="hd-st ok">clôturée le ${esc(m.cloture)}</span>` : ''}</div>
    ${m.points.map(p => `<div class="hd-pt"><div><b>${esc(p.nom)}</b></div><div class="hd-ok3">${p.etats.map(e => `<button class="${e.on ? 'on ' + e.k : ''}" ${x.A(e.choisir)}${e.choisir ? '' : ' disabled'}>${esc(e.nom)}</button>`).join('')}</div>
      <input id="${esc(p.id)}" class="pp-in" placeholder="Constat…" value="${esc(p.constat)}" ${x.I(p.poser)}${m.cloture ? ' disabled' : ''}></div>`).join('')}
    ${m.cloture ? (m.reponse ? `<pre class="hd-rep">${esc(m.reponse)}</pre>` : '') : `<div class="hd-act"><button class="vx-tog${m.repondre ? ' on' : ''}" ${x.A(m.basculerRepondre)}><i></i>à la clôture, envoyer le constat en réponse au franchisé</button><span class="vr-sp"></span>
      <button class="rc-btn gris" ${x.A(m.enregistrer)}>Enregistrer</button><button class="rc-btn ok" ${x.A(m.cloturer)}${m.cloturer ? '' : ' disabled'} title="${m.manque ? m.manque + ' point(s) à voir' : ''}">Clôturer la mission</button></div>`}</div>`;
  return `<aside class="vr-card hd-fiche"><div class="hd-ft"><div><span class="hd-ref">${esc(F.ref)}</span><h3>${esc(F.titre)}</h3><small>${esc(F.sous)}</small></div><span class="hd-st ${F.ton}">${esc(F.statut)}</span></div>
    <div class="hd-desc">${esc(F.description)}</div>
    <div class="hd-delai ${F.delaiTon}"><div class="hd-seg"><span>Date limite</span>${F.delais.map(d => `<button class="${d.on ? 'on' : ''}" ${x.A(d.choisir)}${d.choisir ? '' : ' disabled'}>${esc(d.nom)}</button>`).join('')}${F.retirerDelai ? `<button class="hd-delai-x" ${x.A(F.retirerDelai)} title="Retirer la date limite">✕</button>` : ''}</div><small>${esc(F.delaiNote)}</small></div>
    ${F.reponse ? `<div class="hd-dejarep"><b>Réponse du bureau${F.reponseLe ? ' · ' + esc(F.reponseLe) : ''}</b>${esc(F.reponse)}</div>` : ''}
    ${F.relances ? relances(F.relances, x) : ''}
    ${msg}
    <div class="hd-grille"><label>Statut<select class="pp-in" ${x.C(F.poserStatut)}>${F.statuts.map(o => `<option value="${esc(o.id)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select></label>
      <label>Consultant<select class="pp-in" ${x.C(F.poserConsultant)}>${F.consultants.map(o => `<option value="${esc(o.id)}">${esc(o.nom)}</option>`).join('')}</select><small>${esc(F.consNote)}</small></label></div>
    ${F.missions.map(mission).join('')}
    <div class="hd-bloc"><h4>Reprendre dans une visite <span>— la demande devient une mission de la visite, à contrôler sur place</span></h4>
      ${F.sansVisite ? '<div class="vr-mu">Aucune visite planifiée pour ce magasin : planifiez-la dans Gestion consultant, puis revenez.</div>' : `
      <label>Visite<select class="pp-in" ${x.C(F.poserVisite)}>${F.visites.map(o => `<option value="${o.id}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select></label>
      <div class="hd-seg"><span>Quand</span>${F.quand.map(q => `<button class="${q.on ? 'on' : ''}" ${x.A(q.choisir)}>${esc(q.nom)}</button>`).join('')}</div>
      <label>Ce qu’il faut vérifier sur place <small>un point par ligne</small><textarea id="hd-pts" class="pp-in" rows="3" placeholder="Un point à contrôler par ligne…" ${x.I(F.poserPoints)}>${esc(F.points)}</textarea></label>
      <div class="hd-act"><button class="rc-btn" ${x.A(F.ajouter)}${F.ajouter ? '' : ' disabled'}>Ajouter à la visite</button><span class="vr-mu">la demande passe « En cours » ; deux tâches s’ajoutent à la visite</span></div>`}</div>
    <div class="hd-bloc"><h4>Répondre au franchisé <span>— il la lit dans son dashboard</span></h4>
      <textarea id="hd-rep" class="pp-in" rows="3" placeholder="La réponse du bureau…" ${x.I(F.poserRep)}>${esc(F.rep)}</textarea>
      <div class="hd-act"><select class="vr-sel" ${x.C(F.poserRepStatut)}>${F.repStatuts.map(o => `<option value="${esc(o.id)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select><span class="vr-sp"></span>
        <button class="rc-btn ok" ${x.A(F.repondre)}${F.repondre ? '' : ' disabled'}>Envoyer la réponse</button></div></div></aside>`;
}

/** Les réclamations fournisseur de tous les magasins (20/11/2026) : lecture seule, le fournisseur y répond. */
function tplReclamations(R, x){
  const { esc } = x;
  if (R.chargement) { return '<div class="vr-card vr-etat"><span class="vr-roue"></span>Lecture des réclamations fournisseur de tous les magasins au panel…</div>'; }
  if (R.erreur) { return `<div class="vr-card vr-etat ko">${esc(R.erreur)}</div>`; }
  const kpis = `<div class="vm-kpis">${R.kpis.map(k => `<div class="vm-kpi ${k[3]}"><div class="k">${esc(k[0])}</div><div class="v">${esc(String(k[1]))}</div><div class="s">${esc(k[2])}</div></div>`).join('')}</div>`;
  const sel = (opts, fn) => `<select class="vr-sel" ${x.C(fn)}>${opts.map(o => `<option value="${esc(o.id)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>`;
  const fil = `<div class="vt-fil"><div class="vr-chips hd-chips">${R.chips.map(ch => `<button class="${ch.on ? 'on' : ''}" ${x.A(ch.choisir)}>${esc(ch.nom)} <b>${ch.n}</b></button>`).join('')}</div>
    ${sel(R.magasins, R.poserMag)}${sel(R.fournisseurs, R.poserFour)}${sel(R.periodes, R.poserPeriode)}
    <input id="hd-rc-q" class="vm-q" type="search" placeholder="Chercher une référence, un motif…" value="${esc(R.q)}" ${x.I(R.poserQ)}><span class="vr-sp"></span>
    <button class="vr-btn" ${x.A(R.relire)}${R.relecture ? ' disabled' : ''}>${R.relecture ? 'Lecture…' : 'Relire'}</button></div>`;
  const ligne = l => `<tr class="${l.on ? 'hd-on' : ''}" ${x.A(l.choisir)}><td class="hd-lis ${l.ton}"></td>
    <td class="g">${esc(l.le)}<small class="${l.vieux ? 'ko' : ''}">${esc(l.age)}</small></td><td class="g">${esc(l.magasin)}</td><td class="g">${esc(l.fournisseur)}</td>
    <td class="g hd-titre"><b>${esc(l.reference)}</b><small>${esc(l.qte)}${l.sku ? ' · SKU ' + esc(l.sku) : ''}</small></td>
    <td class="g hd-titre"><span class="hd-dom">${esc(l.motif)}</span>${l.extrait ? `<small>${esc(l.extrait)}</small>` : ''}</td>
    <td><span class="hd-st ${l.ton}">${esc(l.statut)}</span>${l.sousStatut ? `<small>${esc(l.sousStatut)}</small>` : ''}${l.reponse ? `<small>répondue${l.reponseLe ? ' le ' + esc(l.reponseLe) : ''}</small>` : ''}</td><td>${esc(l.montant)}</td></tr>`;
  const table = R.lignes.length ? `<div class="vr-tw"><table class="vr-t hd-t"><thead><tr><th></th><th class="g">Date</th><th class="g">Magasin</th><th class="g">Fournisseur</th><th class="g">Référence</th><th class="g">Motif</th><th>Statut</th><th>Montant</th></tr></thead>
    <tbody>${R.lignes.map(ligne).join('')}</tbody></table></div>` : '<div class="vr-vide">Aucune réclamation pour ce filtre.</div>';
  const F = R.fiche;
  const fiche = F ? `<aside class="vr-card hd-fiche"><div class="hd-ft"><div><span class="hd-ref">${esc(F.ref)}</span><h3>${esc(F.titre)}</h3><small>${esc(F.sous)}</small></div><span class="hd-st ${F.ton}">${esc(F.statut)}</span></div>
    ${F.texte ? `<div class="hd-desc">${esc(F.texte)}</div>` : ''}
    <div class="hd-rc-kv">${F.champs.map(([k, val]) => `<span>${esc(k)}</span><b>${esc(val)}</b>`).join('')}</div>
    ${F.reponse ? `<div class="hd-dejarep"><b>Réponse du fournisseur${F.reponseLe ? ' · ' + esc(F.reponseLe) : ''}</b>${esc(F.reponse)}</div>` : '<div class="vr-mu" style="margin-top:10px">Pas encore de réponse du fournisseur.</div>'}</aside>`
    : '<aside class="vr-card hd-fiche"><div class="vr-mu">Choisissez une réclamation.</div></aside>';
  const pied = `<div class="vr-pied"><b>Les réclamations fournisseur de tous les magasins</b>, déposées depuis le dashboard des franchisés (contrôle, invendus) ou au panel. Une réclamation est <b>ouverte</b> tant que le fournisseur n’a pas répondu (et ne l’a pas refusée). ${esc(R.lecture || '')}${R.ecartees ? ' ' + R.ecartees + ' plus ancienne' + (R.ecartees > 1 ? 's' : '') + ' hors période.' : ''} <span class="vr-mu">${esc(R.source || '')}</span></div>`;
  return `<div class="vr-card">${kpis}${fil}<div class="hd-cols">${table}${fiche}</div>${pied}</div>`;
}

/** La carte « Demandes à traiter » de l'accueil (écran Résultat, 21/11/2026) : les demandes pas encore en cours, chaque jour. */
export function tplATraiter(A, x){
  const { esc } = x;
  if (A.chargement) { return '<div class="hd-at mu">Demandes à traiter : lecture du helpdesk…</div>'; }
  if (A.erreur) { return `<div class="hd-at mu">Demandes à traiter : illisibles (${esc(A.erreur)}). <button class="hd-at-lien" ${x.A(A.relire)}>Relire</button></div>`; }
  if (!A.n) { return `<div class="hd-at ok"><b>Demandes à traiter</b> Aucune : toutes les demandes des franchisés sont prises en charge. <button class="hd-at-lien" ${x.A(A.ouvrir)}>Voir les demandes</button></div>`; }
  return `<div class="hd-at"><div class="hd-at-t"><b>Demandes à traiter</b><span class="hd-at-n">${A.n}</span><span class="vr-mu">pas encore en cours · relancées par SMS tous les 7 jours</span><span class="vr-sp"></span><button class="mko-pied-btn" ${x.A(A.ouvrir)}>Ouvrir les demandes</button></div>
    ${A.lignes.map(l => `<button class="hd-at-l" ${x.A(l.ouvrir)}><span class="hd-at-age${l.vieux ? ' ko' : ''}">${esc(l.age)}</span><span><b>${esc(l.magasin)}</b> · ${esc(l.titre)}<small>${esc(l.sous)}</small></span></button>`).join('')}
    ${A.plus ? `<div class="vr-mu" style="font-size:11px">et ${A.plus} autre${A.plus > 1 ? 's' : ''}, plus récente${A.plus > 1 ? 's' : ''}.</div>` : ''}</div>`;
}
