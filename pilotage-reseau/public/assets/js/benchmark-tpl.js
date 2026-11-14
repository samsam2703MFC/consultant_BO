/* Cockpit CEO — Benchmark (rail Pilotage, 14/11/2026) : le gabarit. Valeurs : benchmark-vals.js ; style : benchmark.css (bk-).
 * Maquette : docs/maquettes/benchmark-import-pdf. */

export function tplBenchmark(c, x){
  const { esc } = x;
  const V = c.bk;
  if (!V) { return ''; }
  const onglets = `<div class="bk-onglets">${V.onglets.map(o => `<button class="${o.on ? 'on' : ''}" ${x.A(o.choisir)}>${esc(o.nom)}</button>`).join('')}</div>`;
  const msg = V.msg ? `<div class="bk-msg">${esc(V.msg)}<button class="vx-lien" ${x.A(V.fermerMsg)}>OK</button></div>` : '';
  const etat = (t, ko) => `<div class="vr-card vr-etat${ko ? ' ko' : ''}">${ko ? '' : '<span class="vr-roue"></span>'}${esc(t)}</div>`;
  let corps = '';
  if (V.chargement) { corps = etat('Lecture des relevés, des magasins et de leurs zones…'); }
  else if (V.erreur) { corps = etat(V.erreur, true); }
  else if (V.onglet === 'classement') { corps = classement(V.classement, x); }
  else if (V.onglet === 'zones') { corps = zones(V.zones, x); }
  else { corps = importer(V, x); }
  return `<div data-screen="benchmark" class="vr bk">${onglets}${msg}${corps}</div>`;
}

/* ── Importer ────────────────────────────────────────────────────────────────── */
function importer(V, x){
  const { esc } = x;
  const Li = V.liste;
  const liste = `<aside class="bk-liste"><button class="rc-btn bk-nouveau${Li.nouveauOn ? ' on' : ''}" ${x.A(Li.nouveau)}>+ Nouveau relevé</button>
    ${Li.items.length ? '' : '<div class="bk-vide">Aucun relevé importé pour l’instant.</div>'}
    <div class="bk-items">${Li.items.map(i => `<div class="bk-it${i.on ? ' on' : ''}" ${x.A(i.choisir)}><span class="bk-src ${i.source === 'PDF' ? 'pdf' : 'web'}">${i.source}</span><div><b>${esc(i.titre)}</b><small>${esc(i.sous)}</small></div><span class="bk-it-d">${esc(i.date)}${i.encode ? '<i class="bk-ok">encodé</i>' : '<i class="bk-att">à encoder</i>'}</span></div>`).join('')}</div></aside>`;
  let droite = '';
  if (V.nouveau) { droite = nouveau(V.nouveau, x); }
  else if (V.fiche && V.fiche.chargement) { droite = `<div class="vr-card vr-etat"><span class="vr-roue"></span>Lecture du relevé et du catalogue du magasin…</div>`; }
  else if (V.fiche && V.fiche.erreur) { droite = `<div class="vr-card vr-etat ko">${esc(V.fiche.erreur)}</div>`; }
  else if (V.fiche) { droite = fiche(V.fiche, x); }
  return `<div class="bk-cols">${liste}<div class="bk-droite">${droite}</div></div>`;
}

function nouveau(N, x){
  const { esc } = x;
  const modes = `<div class="bk-modes">${N.modes.map(m => `<button class="${m.on ? 'on' : ''}" ${x.A(m.choisir)}><b>${esc(m.nom)}</b><small>${esc(m.sous)}</small></button>`).join('')}</div>`;
  const ia = N.iaOk ? '' : `<div class="bk-alerte">La clé Anthropic n’est pas posée : Paramètres › IA. Sans elle, ni le PDF ni internet ne peuvent être lus.</div>`;
  const champ = (lib, id, val, poser, ph, aide) => `<label class="bk-ch">${esc(lib)}<input id="${id}" class="bk-in" value="${esc(val)}" placeholder="${esc(ph || '')}" ${x.I(poser)}>${aide ? `<small>${esc(aide)}</small>` : ''}</label>`;
  let f = '';
  if (N.mode === 'pdf') {
    f = `<label class="bk-drop"><svg viewBox="0 0 24 24"><path d="M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg><div><b>${N.fichierTxt ? esc(N.fichierTxt) : 'Choisir le PDF du concurrent'}</b><small>tarif, folder, carte des fêtes, scan · ${esc(N.max)} au plus · gardé dans le dossier privé du serveur</small></div><input type="file" accept="application/pdf,.pdf" ${x.C(N.setFichier)} style="display:none"></label>
      <div class="bk-grille">${champ('Concurrent (si l’en-tête ne le dit pas)', 'bk-n-cnom', N.cnom, N.poserCnom, 'Dumont et Partners')}${champ('Son code postal', 'bk-n-ccp', N.ccp, N.poserCcp, '5140', 'classe le concurrent sur ses magasins')}</div>
      <div class="bk-pied"><span class="bk-note">Le PDF est lu par l’IA (${esc(N.modele)}) : 20 secondes à 2 minutes. Rien n’entre dans le benchmark avant « Encoder ».</span><span class="vr-sp"></span><button class="rc-btn" ${x.A(N.lire)}${N.lire ? '' : ' disabled'}>${N.enCours ? 'Lecture du PDF…' : 'Lire le PDF'}</button></div>`;
  } else {
    f = `<div class="bk-grille">${champ('Concurrent', 'bk-n-cnom', N.cnom, N.poserCnom, 'Dumont et Partners')}${champ('Commune ou code postal', 'bk-n-lieu', N.lieu, N.poserLieu, 'Tongrinne 5140')}
      ${champ('Site ou webshop (si connu)', 'bk-n-site', N.site, N.poserSite, 'https://…')}${champ('À chercher en priorité', 'bk-n-consignes', N.consignes, N.poserConsignes, 'cougnou, galette, viennoiseries')}</div>
      <div class="bk-pied"><span class="bk-note">L’IA (${esc(N.modele)}) cherche les prix que le concurrent publie lui-même et relève l’adresse de chaque page : 30 secondes à 2 minutes. Seuls les prix vus sur une page sont gardés.</span><span class="vr-sp"></span><button class="rc-btn" ${x.A(N.chercher)}${N.chercher ? '' : ' disabled'}>${N.enCours ? 'Recherche en cours…' : 'Chercher sur internet'}</button></div>`;
  }
  return `<section class="vr-card bk-carte"><h2 class="bk-h">Nouveau relevé d’un concurrent</h2>${modes}${ia}${f}
    ${N.enCours ? `<div class="bk-attente"><span class="vr-roue"></span>${N.mode === 'pdf' ? 'Lecture du PDF par l’IA' : 'Recherche sur internet par l’IA'}… ne fermez pas la page.</div>` : ''}
    ${N.erreur ? `<div class="rc-ko">✕ ${esc(N.erreur)}</div>` : ''}</section>`;
}

function fiche(F, x){
  const { esc } = x;
  const C = F.c;
  const inp = (lib, id, val, poser, cls) => `<label class="bk-ch${cls ? ' ' + cls : ''}">${esc(lib)}<input id="${id}" class="bk-in" value="${esc(val)}" ${x.I(poser)}></label>`;
  const tete = `<div class="bk-tete"><div><h2 class="bk-h">${esc(F.titre || 'Concurrent sans nom')} <span class="bk-src ${F.source}">${F.source === 'pdf' ? 'PDF' : 'Internet'}</span></h2>
      <div class="bk-sous">${F.source === 'pdf' ? esc(F.nomOrigine) + (F.octets ? ' · ' + esc(F.octets) : '') : esc(F.demande)} · lu le ${esc(F.creeLe.slice(8, 10) + '/' + F.creeLe.slice(5, 7) + ' à ' + F.creeLe.slice(11, 16))}${F.modele ? ' · ' + esc(F.modele) : ''}${F.jetons ? ' · ' + esc(F.jetons) : ''}</div></div>
    <span class="vr-sp"></span><button class="vx-lien" ${x.A(F.supprimer)}>Supprimer le relevé</button></div>`;
  const conc = `<div class="bk-sec">Le concurrent <span>— créé chez TFBuddy à l’encodage, avec son code postal</span></div>
    <div class="bk-grille g4">${inp('Nom', 'bk-c-nom', C.nom, C.poserNom, 'l2')}<label class="bk-ch">Type<select class="bk-in" ${x.C(C.poserType)}>${C.types.map(t => `<option value="${t.k}"${t.on ? ' selected' : ''}>${esc(t.nom)}</option>`).join('')}</select></label>${inp('Code postal', 'bk-c-cp', C.cp, C.poserCp)}
      ${inp('Adresse', 'bk-c-adresse', C.adresse, C.poserAdresse, 'l2')}${inp('Commune', 'bk-c-ville', C.ville, C.poserVille)}${inp('Site', 'bk-c-site', C.site, C.poserSite)}</div>
    ${C.change ? `<div class="bk-pied petit"><span class="bk-note">Un code postal changé reclasse le concurrent sur les magasins de sa zone.</span><span class="vr-sp"></span><button class="rc-btn gris" ${x.A(C.annuler)}>Annuler</button><button class="rc-btn" ${x.A(C.enregistrer)}>Enregistrer le concurrent</button></div>` : ''}
    <div class="bk-classe ${F.classement.ton}">${F.classement.cp ? `<span class="bk-cp">CP ${esc(F.classement.cp)}</span>` : ''}${esc(F.classement.txt)}</div>
    <div class="bk-mags">${F.magasins.map(m => `<button class="${m.on ? 'on' : ''}" ${x.A(m.basculer)}><b>${esc(m.nom)}</b><small>${m.franchise ? 'franchisé ' + esc(m.franchise) : ''}${m.parCp ? ' · zone du CP' : ''}</small></button>`).join('')}
      <label class="bk-ch bk-date">Date du relevé<input type="date" class="bk-in" value="${esc(F.date)}" ${x.C(F.poserDate)}></label></div>`;
  const src = F.sources.length || F.remarques ? `<div class="bk-sources">${F.remarques ? `<div><b>Remarque de l’IA :</b> ${esc(F.remarques)}</div>` : ''}${F.sources.length ? `<div><b>Pages lues :</b> ${F.sources.map(s => `<a href="${esc(s.url)}" target="_blank" rel="noopener">${esc(s.txt)}</a>`).join(' · ')}</div>` : ''}</div>` : '';
  const tuiles = `<div class="bk-tuiles">${F.tuiles.map(t => `<div class="bk-tuile ${t[3] || ''}"><small>${esc(t[0])}</small><b>${esc(t[1])}</b><span>${esc(t[2])}</span></div>`).join('')}</div>`;
  const lignes = F.groupes.map(g => `<tr class="grp"><td colspan="8">${esc(g.nom)}<span>${g.lignes.length} ligne${g.lignes.length > 1 ? 's' : ''}</span></td></tr>` + g.lignes.map(l => ligne(l, x)).join('')).join('');
  const table = `<div class="bk-sec">Les lignes face à nos produits <span>— catalogue et prix de ${esc(F.magasinRef || 'notre magasin')} ; un choix confirmé est retenu pour ce concurrent et pour tout le réseau</span>
      ${F.toutConfirmer ? `<button class="rc-btn sec" ${x.A(F.toutConfirmer)}${F.maj ? ' disabled' : ''}>Confirmer les ${F.nAttente} propositions</button>` : ''}</div>
    ${F.catalogueLu ? '' : '<div class="bk-alerte">Le catalogue du magasin n’a pas pu être lu au panel : les lignes ne sont pas rapprochées.</div>'}
    <div class="bk-tw"><table class="bk-t"><thead><tr><th class="g">Ligne lue</th><th>Prix</th><th class="g">Notre produit</th><th>Notre prix</th><th class="g">Comparé</th><th>Écart<small>nous face à eux</small></th><th>Confiance</th><th>État</th></tr></thead><tbody>${lignes || '<tr><td colspan="8" class="bk-vide">Aucune ligne de prix lue.</td></tr>'}</tbody></table></div>`;
  const P = F.plan;
  const enc = `<div class="bk-sec">Encoder dans le benchmark de TFBuddy <span>— vérifié avant, chaque écriture relue après ; rejouable sans doublon</span></div>
    ${P && P.refus ? `<div class="rc-ko">✕ ${esc(P.refus)}</div>` : ''}
    ${P && !P.refus ? `<div class="bk-plan"><div><b>${P.lignes} ligne(s)</b> par magasin · concurrent « ${esc(P.fiche.name || '')} » (${esc(P.fiche.type || '')}${P.fiche.city ? ', ' + esc(P.fiche.city) : ''}) · observation du ${esc(P.observation.observed_at || '')}</div>${P.magasins.map(m => `<div class="bk-plan-m"><b>${esc(m.nom)}</b><small>${m.franchise ? 'franchisé ' + esc(m.franchise) + ' · ' : ''}${esc(m.txt)}</small></div>`).join('')}<code>${esc(P.fiche.notes || '')}</code></div>` : ''}
    ${F.resultat ? `<div class="bk-res">${F.encodeLe ? `<div class="bk-note">Encodé le ${esc(F.encodeLe)}</div>` : ''}${F.resultat.map(r => `<div class="${r.ok ? 'ok' : 'ko'}">${r.ok ? '✓' : '✕'} ${esc(r.txt)}</div>`).join('')}</div>` : ''}
    <div class="bk-pied">${F.nAttente ? `<span class="bk-note att">${F.nAttente} ligne(s) à confirmer avant d’encoder.</span>` : '<span class="bk-note">Les lignes rattachées entrent avec notre produit ; « sans équivalent » entre sans produit ; « ignorée » et « illisible » n’entrent pas.</span>'}<span class="vr-sp"></span>
      <button class="rc-btn sec" ${x.A(F.verifier)}${F.verifier ? '' : ' disabled'}>${F.planEnCours ? 'Vérification…' : 'Vérifier ce qui sera écrit'}</button>
      <button class="rc-btn" ${x.A(F.encoder)}${F.encoder ? '' : ' disabled'}>${F.resEnCours ? 'Encodage…' : 'Encoder dans le benchmark'}</button></div>`;
  return `<section class="vr-card bk-carte">${tete}${conc}${src}${tuiles}${table}${enc}</section>`;
}

function ligne(l, x){
  const { esc } = x;
  const tr = `<tr class="${l.ton}${l.ouvert ? ' ouvert' : ''}"><td class="g nom"><b>${esc(l.libelle)}</b>${l.sous ? `<small>${l.url ? `<a href="${esc(l.url)}" target="_blank" rel="noopener">${esc(l.sous)}</a>` : esc(l.sous)}</small>` : ''}</td>
    <td>${esc(l.prix)}${l.fmt ? `<small>${esc(l.fmt)}</small>` : ''}${l.promo ? `<small class="bk-promo">${esc(l.promo)}</small>` : ''}</td>
    <td class="g">${l.illi ? '<span class="bk-mu">illisible : à saisir à la main si elle compte</span>' : `<div class="bk-choix"><select class="bk-sel ${l.ton}" ${x.C(l.choisir)}>${l.options.map(o => `<option value="${esc(o.v)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>${l.confirmer ? `<button class="rc-btn ok" ${x.A(l.confirmer)} title="Confirmer la proposition">✓</button>` : ''}</div>`}</td>
    <td>${l.manque ? `<span class="bk-ko">${esc(l.nous)}</span>` : esc(l.nous)}${l.nousSous ? `<small>${esc(l.nousSous)}</small>` : ''}</td>
    <td class="g bk-base">${esc(l.base)}${l.euxSous ? `<small>${esc(l.euxSous)}</small>` : ''}</td>
    <td>${l.ecart ? `<span class="bk-ec ${l.ecartTon}">${esc(l.ecart)}</span>` : ''}</td>
    <td>${l.confiance != null ? `<div class="bk-conf ${l.confTon}"><span><i style="width:${l.confiance}%"></i></span>${l.confiance} %</div>` : ''}</td>
    <td><span class="bk-etat ${l.ton}">${esc(l.etat)}</span>${l.appris ? '<span class="bk-appris">appris</span>' : ''}</td></tr>`;
  if (!l.ouvert || !l.cherche) { return tr; }
  const Q = l.cherche;
  return tr + `<tr class="ouvert"><td colspan="8"><div class="bk-cherche"><input id="${esc(Q.id)}" class="bk-in" placeholder="Chercher dans le catalogue du magasin…" value="${esc(Q.q)}" ${x.I(Q.poser)}><button class="vx-lien" ${x.A(Q.fermer)}>Fermer</button>
    <div class="bk-trouves">${Q.resultats.map(r => `<button ${x.A(r.prendre)}><b>${esc(r.nom)}</b><small>${esc(r.sous)}</small></button>`).join('') || (Q.q.length >= 2 ? '<span class="bk-mu">Aucun produit ne correspond.</span>' : '<span class="bk-mu">Deux lettres au moins.</span>')}</div></div></td></tr>`;
}

/* ── Classement ──────────────────────────────────────────────────────────────── */
function classement(V, x){
  const { esc } = x;
  if (V.chargement) { return `<div class="vr-card vr-etat"><span class="vr-roue"></span>Lecture des concurrents de chaque magasin dans TFBuddy…</div>`; }
  if (V.erreur) { return `<div class="vr-card vr-etat ko">${esc(V.erreur)}</div>`; }
  const table = (L, chez) => `<div class="bk-tw"><table class="bk-t"><thead><tr><th class="g">Concurrent</th>${chez ? '<th class="g">Relevé pour</th>' : ''}<th class="g">Code postal</th><th class="g">Type</th><th class="g">Classement</th><th class="g">Source</th><th>Relevés</th><th>Dernier</th></tr></thead><tbody>
    ${L.map(l => `<tr${l.ouvrir ? ' class="clic"' : ''} ${l.ouvrir ? x.A(l.ouvrir) : ''}><td class="g nom"><b>${esc(l.nom)}</b></td>${chez ? `<td class="g">${esc(l.chez)}</td>` : ''}<td class="g">${l.cp ? `<span class="bk-cp">CP ${esc(l.cp)}</span> ` : ''}<span class="bk-mu">${esc(l.commune)}</span></td><td class="g">${esc(l.type)}</td><td class="g"><span class="bk-etat ${l.ton}">${esc(l.classe)}</span></td><td class="g bk-mu">${esc(l.source)}</td><td>${esc(l.releves)}</td><td>${esc(l.dernier)}</td></tr>`).join('')}</tbody></table></div>`;
  return `<div class="bk-haut"><span class="bk-note">Chaque concurrent est rangé sur le magasin dont la zone couvre son code postal${V.tfbuddy ? '' : ' — <b>TFBuddy n’est pas lu</b> (compte consultant du panel non configuré) : seuls les relevés du cockpit apparaissent'}.</span><span class="vr-sp"></span><button class="vx-lien" ${x.A(V.zones)}>Régler les zones</button><button class="vx-lien" ${x.A(V.relire)}${V.relecture ? ' disabled' : ''}>${V.relecture ? 'Lecture…' : 'Relire'}</button></div>
    ${V.magasins.map(m => `<section class="vr-card bk-carte"><div class="bk-tete"><div><h2 class="bk-h">${esc(m.nom)} <span class="bk-cp">CP ${esc(m.cp)}</span></h2><div class="bk-sous">franchisé ${esc(m.franchise)} · zone de ${esc(m.zone)} · ${esc(m.compte || 'aucun concurrent relevé')}</div></div></div>
      ${m.lignes.length ? table(m.lignes, false) : '<div class="bk-vide">Aucun concurrent relevé pour ce magasin.</div>'}<div class="bk-codes">${esc(m.codes)}</div></section>`).join('')}
    ${V.horsZone.length ? `<section class="vr-card bk-carte"><div class="bk-tete"><div><h2 class="bk-h">À reclasser</h2><div class="bk-sous">relevés pour un magasin alors que leur code postal est hors de sa zone : à déplacer, ou la zone à élargir</div></div></div>${table(V.horsZone, true)}</section>` : ''}
    <div class="bk-note">Lu le ${esc(V.lu)}</div>`;
}

/* ── Zones ───────────────────────────────────────────────────────────────────── */
function zones(Z, x){
  const { esc } = x;
  return `<div class="bk-haut"><span class="bk-note">Une ligne par code postal ou par plage (« 5140 Sombreffe », « 5000-5099 Namur »), comme les plages de MétaDonnée. Un code peut servir deux magasins.${Z.parDefaut ? ' <b>Zones par défaut</b> : les codes postaux à moins de 10 km de chaque boutique.' : ''}</span></div>
    <div class="bk-test"><label class="bk-ch">Tester un code postal<input id="bk-test" class="bk-in" value="${esc(Z.test)}" placeholder="5140" ${x.I(Z.poserTest)}></label>${Z.testTxt ? `<span class="bk-cp">${esc(Z.testTxt)}</span>` : ''}</div>
    <div class="bk-zones">${Z.magasins.map(m => `<section class="vr-card bk-carte"><h2 class="bk-h">${esc(m.nom)} <span class="bk-cp">CP ${esc(m.cp)}</span></h2><div class="bk-sous">franchisé ${esc(m.franchise)} · ${m.n} code(s) ou plage(s)</div>
      <textarea id="${esc(m.id)}" class="bk-in bk-zone" rows="14" ${x.I(m.poser)}>${esc(m.txt)}</textarea>${m.ko.length ? `<div class="rc-ko">Lignes illisibles : ${esc(m.ko.join(' · '))}</div>` : ''}</section>`).join('')}</div>
    <div class="bk-pied">${Z.resultat ? `<span class="bk-note ${Z.resultatOk ? 'ok' : 'att'}">${esc(Z.resultat)}</span>` : ''}<span class="vr-sp"></span>
      ${Z.defaut ? `<button class="vx-lien" ${x.A(Z.defaut)}>Revenir aux zones par défaut</button>` : ''}
      ${Z.annuler ? `<button class="rc-btn gris" ${x.A(Z.annuler)}>Annuler</button>` : ''}<button class="rc-btn" ${x.A(Z.enregistrer)}${Z.enregistrer && !Z.enCours ? '' : ' disabled'}>${Z.enCours ? 'Enregistrement…' : 'Enregistrer les zones'}</button></div>`;
}
