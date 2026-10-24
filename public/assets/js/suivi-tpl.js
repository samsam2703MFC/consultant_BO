/* Cockpit CEO — gabarits des écrans « Suivi des franchisés » (24/10/2026) : Tableau de bord, Visite sur
 * tablette, Client mystère, Page du franchisé, Qui fait quoi. Mêmes conventions que templates.js :
 * x = { A, C, I, esc } ; les valeurs viennent de suivi-vals.js (`c.sv`). */

const kpi = (esc, k) => `<div class="gc-kpi"><div class="k">${esc(k[0])}</div><div class="v ${k[3] || ''}">${esc(k[1])}</div><div class="s">${esc(k[2] || '')}</div></div>`;
const sel = (x, esc, fn, opts, style) => `<select ${x.C(fn)} class="mko-sel" style="${style || ''}">${opts.map(o => `<option value="${esc(o.v)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>`;
const msgDe = (esc, m) => m ? `<div class="gc-msg ${m.ko ? 'ko' : ''}">${esc(m.txt)}</div>` : '';
const attente = (esc, t) => `<div class="mko-carte" style="padding:20px 22px;font-size:13px;color:var(--color-text-muted)">${esc(t)}</div>`;
const panne = (esc, t) => `<div class="mko-carte" style="padding:20px 22px;font-size:13px;color:#8D1D2C">${esc(t)}</div>`;
const bascule = (x, on, fn, lib) => `<button ${x.A(fn)} class="sv-bas${on ? ' on' : ''}" role="switch" aria-checked="${on ? 'true' : 'false'}"><i></i>${lib || ''}</button>`;
const duo = (x, esc, a, b) => `<span class="sv-duo"><button ${x.A(a.fn)} class="${a.on ? 'on' : ''}">${esc(a.nom)}</button><button ${x.A(b.fn)} class="${b.on ? 'on ko' : ''}">${esc(b.nom)}</button></span>`;
const carte = (titre, mini, corps, plus) => `<div class="mko-carte"${plus || ''}><div class="mko-ct"><span class="mko-lab">${titre}</span>${mini ? `<span class="mko-mini">${mini}</span>` : ''}</div><div class="mko-corps">${corps}</div></div>`;

/* --- 1. Tableau de bord ------------------------------------------------------------- */
export function tplSvTableau(c, x){
  const { esc } = x, s = c.sv;
  const entete = `<div class="mko-hd sv-hd">
      <div class="cx-vues">${s.onglets.map(o => `<button ${x.A(o.choisir)} class="${o.on ? 'on' : ''}">${esc(o.nom)}${o.badge ? ` <span class="gc-badge">${o.badge}</span>` : ''}</button>`).join('')}</div>
      <span class="sv-hd-d">${sel(x, esc, s.setCons, s.consultants)}
      <button ${x.A(s.semPrec)} class="mko-pied-btn">‹</button><b class="sv-sem">${esc(s.semLib)}</b><button ${x.A(s.semSuiv)} class="mko-pied-btn">›</button>
      <button ${x.A(s.planifier)} class="cx-btn prim">Planifier une visite</button><button ${x.A(s.relire)} class="mko-pied-btn" title="${esc(s.lu)}">Relire</button></span>
    </div>`;
  if (s.indispo) { return `<div data-screen="suivi-tableau">${entete}${panne(esc, s.indispo)}</div>`; }
  if (s.chargement) { return `<div data-screen="suivi-tableau">${entete}${attente(esc, 'Lecture des visites, des rapports et des points ouverts…')}</div>`; }
  let corps = '';
  if (s.onglet === 'preparer') { corps = tplPreparer(s, x); }
  else if (s.onglet === 'adapter') { corps = tplAdapter(s, x); }
  else if (s.onglet === 'points') { corps = tplPoints(s, x); }
  else { corps = tplEnsemble(s, x); }
  const apercu = s.apercu ? `<div class="sv-voile" ${x.A(s.fermerApercu)}><div class="sv-modal" ${x.A(() => {})}><div class="mko-ct"><span class="mko-lab">${esc(s.apercu.titre)}</span><button ${x.A(s.fermerApercu)} class="mko-pied-btn" style="margin-left:auto">Fermer</button></div>
      ${s.apercu.chargement ? '<div class="mko-corps mko-mu">Mise en page du rapport…</div>' : `<iframe class="sv-ifr" srcdoc="${esc(s.apercu.html)}" title="Aperçu du rapport"></iframe>`}</div></div>` : '';
  return `<div data-screen="suivi-tableau">${entete}${msgDe(esc, s.msg)}<div class="gc-kpis">${s.kpis.map(k => kpi(esc, k)).join('')}</div>${corps}${apercu}</div>`;
}

const carteVisite = (x, esc, k) => `<button ${x.A(k.ouvrir)} class="sv-carte${k.on ? ' on' : ''}"><span class="t"><i class="gc-feu ${esc(k.feu)}"></i>${esc(k.court)}${k.inopinee ? ' <span class="gc-pill">inopinée</span>' : ''}</span>
    <span class="l">${esc(k.l1)}</span><span class="l mu">${esc(k.l2)}</span>${k.ech ? `<span class="e ${k.cls}">${esc(k.ech)}</span>` : ''}</button>`;

function tplEnsemble(s, x){
  const { esc } = x;
  const pipe = `<div class="sv-pipe">${s.colonnes.map(col => `<div class="col"><div class="ch"><b>${esc(col.nom)}</b><span class="n">${col.n}</span><span class="ou">${esc(col.ou)}</span></div>
      ${col.cartes.length ? col.cartes.map(k => carteVisite(x, esc, k)).join('') : '<div class="mko-mu sv-vide">—</div>'}</div>`).join('')}</div>`;
  const tab = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">Les franchisés</span><span class="mko-mini">un clic ouvre la fiche franchisé</span></div><div style="overflow-x:auto"><table class="mko-tab sv-tab">
      <thead><tr><th>Magasin</th><th>Dernière visite</th><th>Dernier rapport</th><th>Points ouverts</th><th>Prochaine visite</th><th>Cadre du mois</th><th class="n">Scoring</th></tr></thead>
      <tbody>${s.franchises.map(f => `<tr class="cl" ${x.A(f.ouvrir)}><td class="nom"><i class="gc-feu ${esc(f.feu)}"></i>${esc(f.court)}<small>${esc(f.sous)}</small></td>
        <td>${esc(f.derniere)}<small>${esc(f.derniereSous)}</small></td>
        <td>${f.rapportCls ? `<span class="gc-pill ${f.rapportCls}">${esc(f.rapport)}</span>` : esc(f.rapport)}<small>${esc(f.rapportSous)}</small></td>
        <td class="${f.pointsCls}">${esc(f.points)}<small>${esc(f.pointsSous)}</small></td>
        <td>${esc(f.prochaine)}<small>${esc(f.prochaineSous)}</small></td>
        <td>${esc(f.cadre)}<small>${esc(f.cadreSous)}</small></td><td class="n">${esc(f.scoring)}</td></tr>`).join('')}</tbody></table></div></div>`;
  return pipe + tab;
}

function tplPreparer(s, x){
  const { esc } = x;
  const liste = `<div class="sv-liste">${s.prepListe.length ? s.prepListe.map(k => carteVisite(x, esc, k)).join('') : '<div class="mko-carte mko-corps mko-mu">Aucune visite à préparer sur les trois semaines qui viennent.</div>'}</div>`;
  const p = s.prep;
  if (!p) { return `<div class="sv-cote">${liste}<div></div></div>`; }
  if (p.chargement) { return `<div class="sv-cote">${liste}${attente(esc, 'Assemblage du dossier de visite…')}</div>`; }
  if (p.indispo) { return `<div class="sv-cote">${liste}${panne(esc, p.indispo)}</div>`; }
  const dossier = carte('Le dossier de visite — assemblé tout seul', 'cochez ce qui part sur la tablette',
    p.dossier.map(b => `<div class="sv-bloc${b.inclus ? '' : ' off'}"><div class="h">${bascule(x, b.inclus, b.basculer)}<b>${esc(b.titre)}</b>${b.interne ? '<span class="gc-pill">interne</span>' : ''}${b.alerte ? `<span class="gc-pill ko">${esc(b.alerte)}</span>` : ''}</div>
      ${b.lignes.length ? `<ul>${b.lignes.map(l => `<li>${esc(l)}</li>`).join('')}</ul>` : `<div class="mko-mu">${esc(b.motif || 'rien à signaler')}</div>`}</div>`).join(''));
  const tablette = carte('Ce que la tablette emporte', '',
    `<div class="gc-form sv-f2"><div class="f"><label>Type de visite</label>${sel(x, esc, p.setType, p.types)}<small>${esc(p.typeSous)}</small></div>
      <div class="f"><label>Le franchisé</label>${duo(x, esc, { nom: 'Annoncée', on: p.annoncee, fn: p.annoncer }, { nom: 'Inopinée', on: !p.annoncee, fn: p.inopiner })}<small>${p.annoncee ? 'il reçoit l’ordre du jour et voit la visite sur sa page' : 'il n’est pas prévenu, la visite n’apparaît pas sur sa page'}</small></div></div>
    <div class="gc-mod"><b>Demandé par le bureau</b><span>en tête de la checklist · coché = dans l’ordre du jour</span></div>
    ${p.points.map(q => `<div class="sv-pt"><input type="checkbox" ${q.odj ? 'checked' : ''} ${x.C(q.basculerOdj)} title="dans l’ordre du jour"><input class="mko-inp" value="${esc(q.libelle)}" ${x.I(q.setLib)} placeholder="Ce que le consultant doit regarder"><span class="mko-mu">${esc(q.source)}</span><button ${x.A(q.retirer)} class="mko-pied-btn" title="retirer">✕</button></div>`).join('')}
    <button ${x.A(p.ajouter)} class="mko-pied-btn" style="margin-top:6px">+ Ajouter un point</button>
    <div class="f sv-f1"><label>Note pour le consultant (interne)</label><textarea class="mko-inp sv-ta" rows="2" ${x.I(p.setNote)} placeholder="Le contexte, ce qu’il faut savoir avant d’entrer">${esc(p.note)}</textarea></div>`);
  const odj = carte('L’ordre du jour au franchisé', p.annoncee ? esc(p.langue) : 'inopinée',
    `<div class="${p.sansAdresse ? 'ko' : ''}" style="font-size:12.5px">${esc(p.odj)}${p.sansAdresse ? ' Aucune adresse : réglez-la dans Qui fait quoi.' : ''}</div>
    ${p.annoncee ? `<button ${x.A(p.odjBasculer)} class="gc-lien" style="margin-top:6px">${p.odjOuvert ? 'Masquer le courriel' : 'Voir le courriel'}</button>
      ${p.odjOuvert ? `<div class="sv-mail"><b>${esc(p.odjSujet)}</b><pre>${esc(p.odjTexte)}</pre></div>` : ''}` : ''}`);
  return `<div class="sv-cote">${liste}<div class="sv-pile"><div class="sv-titre"><b>${esc(p.titre)}</b><span>${esc(p.roles)}</span>${p.envoyee ? `<span class="ok">${esc(p.envoyee)}</span>` : ''}</div>
    <div class="cx-deux">${dossier}<div class="sv-pile">${tablette}${odj}</div></div>
    <div class="sv-pied"><button ${x.A(p.enregistrer)} class="cx-btn" ${p.busy ? 'disabled' : ''}>Enregistrer</button>
      ${p.odjMaintenant ? `<button ${x.A(p.odjMaintenant)} class="cx-btn" ${p.busy ? 'disabled' : ''}>Envoyer l’ordre du jour maintenant</button>` : ''}
      <button ${x.A(p.envoyer)} class="cx-btn prim" ${p.busy ? 'disabled' : ''}>${p.busy ? 'Envoi…' : 'Envoyer sur la tablette'}</button></div></div></div>`;
}

function tplAdapter(s, x){
  const { esc } = x;
  const liste = `<div class="sv-liste">${s.rapListe.length ? s.rapListe.map(k => carteVisite(x, esc, k)).join('') : '<div class="mko-carte mko-corps mko-mu">Aucun rapport à adapter : les brouillons arrivent ici quand le consultant termine une visite.</div>'}</div>`;
  const r = s.rap;
  if (!r) { return `<div class="sv-cote">${liste}<div></div></div>`; }
  if (r.chargement) { return `<div class="sv-cote">${liste}${attente(esc, 'Lecture du brouillon…')}</div>`; }
  if (r.indispo) { return `<div class="sv-cote">${liste}${panne(esc, r.indispo)}</div>`; }
  const ro = r.envoye ? 'readonly' : '';
  const puces = (l, multi) => `<div class="cx-puces">${l.map(o => `<button ${r.envoye ? '' : x.A(o.choisir)} class="cx-pu${o.on ? ' on' : ''}"${r.envoye ? ' disabled' : ''}>${esc(o.nom != null ? o.nom : o.n)}</button>`).join('')}</div>`;
  const champ = (lab, val, fn, rows, aide) => `<div class="f sv-f1"><label>${lab}</label><textarea class="mko-inp sv-ta" rows="${rows}" ${ro} ${x.I(fn)}>${esc(val)}</textarea>${aide ? `<small>${aide}</small>` : ''}</div>`;
  const texte = carte('Le rapport, partie par partie', r.langueFr ? 'en français' : 'rédigez en ' + esc(r.langue.toLowerCase()) + ' : le franchisé le lit tel quel',
    `${champ('En une phrase', r.phrase, r.setPhrase, 2)}${champ('Ce qui va bien', r.positif, r.setPositif, 2)}
    <div class="gc-form sv-f2"><div class="f"><label>Ressenti</label>${puces(r.sentiment)}</div><div class="f"><label>Exécution</label>${puces(r.execution)}</div><div class="f"><label>Clients</label>${puces(r.clients)}</div></div>
    <div class="f sv-f1"><label>Le vrai problème · causes</label>${puces(r.causes)}</div>
    ${champ('Vu sur place', r.diagnostic, r.setDiagnostic, 3)}${champ('Recommandation', r.reco, r.setReco, 3)}`);
  const plans = carte('Les points', r.nInclus + ' partent au franchisé · ' + r.nInternes + ' restent internes',
    r.plans.length ? r.plans.map(p => `<div class="sv-plan${p.inclus ? '' : ' off'}"><div class="h">${p.modifiable ? bascule(x, p.inclus, p.basculer) : ''}<span class="gc-st ${p.priorite === 'P0' ? 'p0' : ''}">${esc(p.priorite)}</span>
        ${p.modifiable ? `<input class="mko-inp" value="${esc(p.titre)}" ${x.I(p.setTitre)}>` : `<b>${esc(p.titre)}</b>`}<span class="gc-pill ${p.statutCls}">${esc(p.statut)}</span></div>
      ${p.modifiable ? `<div class="sv-plan-d"><input class="mko-inp" value="${esc(p.detail)}" placeholder="Le détail, ce qui est attendu" ${x.I(p.setDetail)}>${sel(x, esc, p.setPrio, p.prios, 'width:70px')}<input type="date" class="mko-inp" style="width:140px" value="${esc(p.echeance)}" ${x.C(p.setEcheance)}></div>` : (p.detail ? `<div class="mko-mu">${esc(p.detail)}</div>` : '')}</div>`).join('')
      : '<div class="mko-mu">Aucun point proposé par la tablette.</div>');
  const photos = carte('Les photos', 'cochées = dans le rapport',
    r.photos.length ? `<div class="sv-photos">${r.photos.map(p => `<button ${x.A(p.basculer)} class="sv-ph${p.inclus ? ' on' : ''}"${p.basculer ? '' : ' disabled'}><img src="${esc(p.src)}" alt="" loading="lazy"><span>${esc(p.genre)}</span></button>`).join('')}</div>` : '<div class="mko-mu">Aucune photo prise pendant la visite.</div>');
  const ck = carte('La checklist', esc(r.checklist), r.ecarts.length ? `<ul class="sv-ul">${r.ecarts.map(e => `<li>${esc(e)}</li>`).join('')}</ul>` : '<div class="mko-mu">Aucun écart relevé.</div>');
  const envoi = carte('L’envoi', esc(r.statut),
    `<div class="sv-kv"><span>À</span><b class="${r.a ? '' : 'ko'}">${esc(r.a || 'aucune adresse — réglez-la dans Qui fait quoi')}</b><span>D’où</span><b>${esc(r.source || '—')}</b><span>Langue</span><b>${esc(r.langue)}</b><span>En copie</span><b>${esc(r.copies)}</b><span>Adapte</span><b>${esc(r.adapte || '—')}</b><span>Envoie</span><b>${esc(r.envoie || '—')}</b></div>
    ${r.delai ? `<div class="${r.delaiKo ? 'ko' : 'att'}" style="font-weight:600;font-size:12.5px;margin-top:8px">${esc(r.delai)}</div>` : ''}
    ${!r.smtp ? '<div class="gc-pill att" style="margin-top:8px">SMTP non configuré : le rapport ira sur la page du franchisé, sans courriel</div>' : ''}
    ${r.dernier ? `<div class="mko-mu" style="margin-top:8px">${esc(r.dernier)}</div>` : ''}
    <div class="sv-mail"><b>${esc(r.sujet)}</b><pre>${esc(r.texte)}</pre></div>`);
  return `<div class="sv-cote">${liste}<div class="sv-pile"><div class="sv-titre"><b>${esc(r.titre)}</b><span>${esc(r.statut)}</span></div>
    <div class="cx-deux"><div class="sv-pile">${texte}${plans}</div><div class="sv-pile">${envoi}${photos}${ck}</div></div>
    <div class="sv-pied"><button ${x.A(r.voir)} class="cx-btn">Voir le rapport</button>
      ${r.envoye ? `<button ${x.A(r.renvoyer)} class="cx-btn" ${r.busy ? 'disabled' : ''}>Renvoyer le courriel</button>`
        : `<button ${x.A(r.enregistrer)} class="cx-btn" ${r.busy ? 'disabled' : ''}>Enregistrer</button><button ${x.A(r.envoyer)} class="cx-btn prim" ${r.busy ? 'disabled' : ''}>${r.busy ? 'Envoi…' : 'Envoyer au franchisé'}</button>`}</div></div></div>`;
}

function tplPoints(s, x){
  const { esc } = x;
  const corr = carte('Les corrections à valider', 'la photo du franchisé, à côté de celle de la visite',
    s.corrections.length ? '<div class="sv-corrs">' + s.corrections.map(p => `<div class="sv-corr"><div class="h"><span class="gc-st ${p.priorite === 'P0' ? 'p0' : ''}">${esc(p.priorite)}</span><b>${esc(p.titre)}</b><span class="${p.horsDelai ? 'ko' : 'mko-mu'}">${esc(p.recu)}</span></div>
      <div class="sv-av"><figure>${p.avant ? `<img src="${esc(p.avant)}" alt="">` : '<div class="sv-noimg">pas de photo</div>'}<figcaption>${esc(p.avantLib)}</figcaption></figure><figure>${p.apres ? `<img src="${esc(p.apres)}" alt="">` : '<div class="sv-noimg">pas de photo</div>'}<figcaption>${esc(p.apresLib)}</figcaption></figure></div>
      ${p.commentaire ? `<div class="sv-com">« ${esc(p.commentaire)} »</div>` : ''}<div class="mko-mu">${esc(p.valide)}</div>
      ${p.rep ? `<div class="sv-rep"><input class="mko-inp" placeholder="Ce qui ne va pas encore" value="${esc(p.rep.retour)}" ${x.I(p.rep.setRetour)}><input type="date" class="mko-inp" style="width:150px" value="${esc(p.rep.echeance)}" ${x.C(p.rep.setEcheance)}><button ${x.A(p.rep.renvoyer)} class="cx-btn prim">Renvoyer au franchisé</button><button ${x.A(p.rep.annuler)} class="mko-pied-btn">Annuler</button></div>`
        : `<div class="sv-pied"><button ${x.A(p.reprendre)} class="cx-btn">À reprendre</button><button ${x.A(p.accepter)} class="cx-btn prim">Accepter</button></div>`}</div>`).join('') + '</div>'
      : '<div class="mko-mu">Aucune photo de correction en attente.</div>');
  const tous = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">Tous les points ouverts</span><span class="mko-mini">les escaladés, puis par urgence</span></div><div style="overflow-x:auto"><table class="mko-tab sv-tab">
      <thead><tr><th>Prio</th><th>Magasin</th><th>Point</th><th>Échéance</th><th>Statut</th></tr></thead>
      <tbody>${s.points.length ? s.points.map(p => `<tr><td><span class="gc-st ${p.priorite === 'P0' ? 'p0' : ''}">${esc(p.priorite)}</span></td><td class="nom">${esc(p.court)}</td><td>${esc(p.titre)}<small>${esc(p.sous)}</small></td>
        <td>${esc(p.echeance)}${p.retard ? `<small class="ko">${esc(p.retard)}</small>` : ''}</td><td><span class="gc-pill ${p.statutCls}">${esc(p.statut)}</span></td></tr>`).join('') : '<tr><td colspan="5" class="mu">Aucun point ouvert.</td></tr>'}</tbody></table></div></div>`;
  return `<div class="sv-pile">${corr}${tous}</div>`;
}

/* --- 2. Visite sur tablette ---------------------------------------------------------- */
export function tplSvTablette(c, x){
  const { esc } = x, s = c.sv;
  const circuit = [['Préparer', 'au bureau, sur l’ordinateur : dossier, points du bureau, type de visite'], ['Ordre du jour', 'part tout seul au franchisé si la visite est annoncée'],
    ['Visiter', 'sur la tablette, même hors ligne : photo du jour, checklist, contrôles, points, météo, signature'], ['Brouillon', 'à la fin de la visite, il part au bureau avec les points proposés'],
    ['Adapter et envoyer', 'au bureau, sous le délai réglé : le franchisé reçoit son rapport et ses points'], ['Corriger', 'le franchisé envoie sa photo, le bureau valide, le consultant ferme sur place']];
  return `<div data-screen="suivi-tablette"><div class="mko-hd sv-hd"><span class="mko-mu">Tablette de</span>${sel(x, esc, s.setCons, s.consultants)}
      <span class="sv-hd-d"><button ${x.A(s.copier)} class="cx-btn">Copier le lien d’installation</button><a class="cx-btn prim" href="${esc(s.src)}" target="_blank" rel="noopener">Ouvrir en plein écran</a></span></div>
    <div class="sv-tab2"><div class="sv-tabl"><iframe src="${esc(s.src)}" title="Application Visites${s.nom ? ' de ' + esc(s.nom) : ''}"></iframe></div>
    <div class="sv-pile">${carte('Installer sur la tablette', '', `<ol class="sv-ol"><li>Ouvrez ce lien sur la tablette du consultant :<input class="mko-inp" readonly value="${esc(s.lien)}" style="font-size:11px;margin-top:4px"></li><li>Dans le navigateur : « Ajouter à l’écran d’accueil ».</li><li>L’application garde les visites de la semaine : sans réseau, les photos et les réponses attendent dans la file et partent au retour du signal.</li></ol>`)}
      ${carte('Le circuit d’une visite', '', `<ol class="sv-circ">${circuit.map(([t, d]) => `<li><b>${t}</b><span>${d}</span></li>`).join('')}</ol>`)}
      ${carte('Les validations', '', `<div style="font-size:12.5px">Les photos de correction se valident au tableau de bord, onglet « Points et corrections ». La vue admin de l’application reste là : <a class="gc-lien" href="${esc(s.admin)}" target="_blank" rel="noopener">visites/?role=admin ›</a></div>`)}</div></div></div>`;
}

/* --- 3. Client mystère -------------------------------------------------------------------- */
export function tplSvMsp(c, x){
  const { esc } = x, s = c.sv;
  const entete = `<div class="mko-hd sv-hd"><span class="sv-hd-d"><button ${x.A(s.relire)} class="mko-pied-btn">Relire</button></span></div>`;
  if (s.indispo) { return `<div data-screen="suivi-client-mystere">${entete}${panne(esc, s.indispo)}</div>`; }
  if (s.chargement) { return `<div data-screen="suivi-client-mystere">${entete}${attente(esc, 'Lecture des passages du client mystère…')}</div>`; }
  const tab = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">Magasin par magasin</span><span class="mko-mini">les rubriques sur 20 · en rouge sous le seuil d’alerte</span></div><div style="overflow-x:auto"><table class="mko-tab sv-tab">
      <thead><tr><th>Magasin</th><th>Dernier passage</th><th class="n">Note</th>${s.rubriques.map(r => `<th class="n">${esc(r)}</th>`).join('')}<th>Précédent</th><th>Ce qui a suivi</th><th></th></tr></thead>
      <tbody>${s.lignes.map(l => `<tr><td class="nom">${esc(l.court)}<small>${esc(l.sous)}</small></td><td>${esc(l.passage)}<small>${esc(l.passageSous)}</small></td><td class="n"><b>${esc(l.note)}</b><small>${esc(l.noteSous)}</small></td>
        ${l.rub.map(r => `<td class="n ${r.cls}">${esc(r.v)}</td>`).join('')}<td class="${l.precCls}">${esc(l.prec)}<small>${esc(l.precSous)}</small></td><td>${esc(l.suite)}<small>${esc(l.suiteSous)}</small></td>
        <td class="sv-act">${l.pdf ? `<a class="gc-lien" href="${esc(l.pdf)}" target="_blank" rel="noopener">PDF</a>` : ''}${l.reencoder ? `<button ${x.A(l.reencoder)} class="mko-pied-btn">Réencoder</button>` : ''}</td></tr>`).join('')}</tbody></table></div></div>`;
  const f = s.form;
  const enc = carte('Encoder un passage', 'réencoder le même trimestre remplace le précédent',
    `<div class="gc-form sv-f3"><div class="f"><label>Magasin</label>${sel(x, esc, f.setShop, f.magasins)}</div><div class="f"><label>Date du passage</label><input type="date" class="mko-inp" value="${esc(f.passage)}" ${x.C(f.setPassage)}></div>
      <div class="f"><label>Note obtenue</label><span class="cx-ligne"><input class="mko-inp" inputmode="decimal" style="width:80px" value="${esc(f.obtenu)}" ${x.I(f.setObtenu)}> / <input class="mko-inp" inputmode="numeric" style="width:70px" value="${esc(f.maximum)}" ${x.I(f.setMaximum)}></span><small>${f.sur5 ? esc(f.sur5) + ' / 5 au scoring' : 'le maximum du rapport reçu'}</small></div></div>
    <div class="gc-form sv-f4">${f.rubriques.map(r => `<div class="f"><label>${esc(r.nom)}</label><input class="mko-inp${r.ko ? ' ko' : ''}" inputmode="decimal" placeholder="/ 20" value="${esc(r.v)}" ${x.I(r.set)}></div>`).join('')}</div>
    ${f.somme ? `<div class="mko-mu" style="margin:-4px 0 8px">rubriques : ${esc(f.somme)}</div>` : ''}
    <div class="f sv-f1"><label>Commentaire du rapport</label><textarea class="mko-inp sv-ta" rows="2" ${x.I(f.setCommentaire)}>${esc(f.commentaire)}</textarea></div>
    <div class="f sv-f1"><label>Le rapport reçu (PDF)</label><input type="file" accept="application/pdf" ${x.C(f.setFichier)}><small>${esc(f.fichierNom || 'facultatif')}</small></div>
    <div class="sv-ligne">${bascule(x, f.montrer, f.basculerMontrer, 'Montré au franchisé sur sa page')}</div>`);
  const suites = carte('Ce qui le suit', esc(f.delais),
    `<div class="gc-mod"><b>Points à travailler</b><span>ouverts chez le franchisé</span></div>
    ${f.points.map(p => `<div class="sv-pt">${sel(x, esc, p.setPrio, p.prios, 'width:70px')}<input class="mko-inp" value="${esc(p.titre)}" ${x.I(p.setTitre)} placeholder="Ce qu’il doit corriger"><button ${x.A(p.retirer)} class="mko-pied-btn">✕</button></div>`).join('')}
    <div class="cx-ligne" style="margin:6px 0 10px"><button ${x.A(f.ajouterPoint)} class="mko-pied-btn">+ Ajouter un point</button><button ${x.A(f.proposer)} class="mko-pied-btn">Proposer depuis les rubriques faibles</button></div>
    <div class="sv-ligne">${bascule(x, f.controle, f.basculerControle, 'Contrôle à la visite suivante')}<small class="mko-mu">${esc(f.prochaine ? 'visite du ' + f.prochaine : 'pas de visite planifiée : le contrôle attendra la prochaine')}</small></div>
    <div class="sv-ligne">${bascule(x, f.tache, f.basculerTache, 'Tâche au consultant responsable')}<small class="mko-mu">${esc(f.responsable || 'pas de responsable au cadre')}</small></div>
    <div class="sv-pied"><button ${x.A(f.vider)} class="cx-btn">Vider</button><button ${x.A(f.enregistrer)} class="cx-btn prim" ${f.busy ? 'disabled' : ''}>${f.busy ? 'Encodage…' : 'Encoder le passage'}</button></div>`);
  const ou = carte('Où il se lit ensuite', '', `<ul class="sv-ul"><li>Le feu du magasin et le dossier de la visite suivante.</li><li>Le scoring du trimestre (note ramenée sur 5).</li><li>Sur la tablette : le module « Client mystère » contrôle les rubriques relevées.</li><li>Sur la page du franchisé, s’il est montré.</li></ul>`);
  return `<div data-screen="suivi-client-mystere">${entete}${msgDe(esc, s.msg)}<div class="gc-kpis">${s.kpis.map(k => kpi(esc, k)).join('')}</div>${tab}<div class="cx-deux" style="margin-top:12px">${enc}<div class="sv-pile">${suites}${ou}</div></div></div>`;
}

/* --- 4. Page du franchisé ----------------------------------------------------------------- */
export function tplSvFranchise(c, x){
  const { esc } = x, s = c.sv;
  const entete = `<div class="mko-hd sv-hd">${sel(x, esc, s.setShop, s.magasins)}${s.url ? `<span class="sv-hd-d"><button ${x.A(s.copier)} class="cx-btn">Copier son lien</button><button ${x.A(s.envoyer)} class="cx-btn prim" ${s.busy ? 'disabled' : ''}>Lui envoyer le lien</button></span>` : ''}</div>`;
  if (s.indispo) { return `<div data-screen="suivi-franchise">${entete}${panne(esc, s.indispo)}</div>`; }
  if (s.chargement) { return `<div data-screen="suivi-franchise">${entete}${attente(esc, 'Lecture de la page du franchisé…')}</div>`; }
  const voit = carte('Ce qu’il voit', '<button ' + x.A(s.regler) + ' class="gc-lien">régler dans Qui fait quoi ›</button>', `<ul class="sv-voit">${s.voit.map(v => `<li class="${v.on ? 'ok' : 'off'}">${v.on ? '✓' : '—'} ${esc(v.l)}</li>`).join('')}</ul>`);
  const pas = carte('Ce qu’il ne voit pas', '', `<ul class="sv-ul"><li>Les brouillons : un rapport n’apparaît qu’une fois envoyé par le bureau.</li><li>Les points gardés en interne et les points proposés pas encore envoyés.</li><li>Les visites inopinées, les notes du bureau, le dossier de préparation.</li><li>La météo du franchisé notée par le consultant.</li></ul>`);
  const retour = carte('Ce qui revient au bureau', '', `<ul class="sv-ul"><li>Sa photo de correction : au tableau de bord, « Points et corrections », pour validation.</li><li>Le rapport lu : la date s’affiche au tableau de bord ; sinon une relance part au bout du délai réglé.</li></ul>`);
  const lien = carte('Son lien', esc(s.langue), `<input class="mko-inp" readonly value="${esc(s.url)}" style="font-size:11px"><div class="mko-mu" style="margin-top:6px">Sans mot de passe : le lien porte une clé propre au magasin. Courriel : ${esc(s.adresse || 'aucune adresse')}${s.source ? ' (' + esc(s.source) + ')' : ''}${s.smtp ? '' : ' · SMTP non configuré'}.</div>
    <button ${x.A(s.basculer)} class="gc-lien" style="margin-top:6px">${s.ouvert ? 'Masquer le courriel' : 'Voir le courriel'}</button>${s.ouvert ? `<div class="sv-mail"><b>${esc(s.sujet)}</b><pre>${esc(s.texte)}</pre></div>` : ''}`);
  return `<div data-screen="suivi-franchise">${entete}${msgDe(esc, s.msg)}<div class="sv-tab2"><div class="sv-tel"><iframe src="${esc(s.src)}" title="Page du franchisé (aperçu)"></iframe></div><div class="sv-pile">${lien}${voit}${pas}${retour}</div></div></div>`;
}

/* --- 5. Qui fait quoi ---------------------------------------------------------------------- */
export function tplSvAdmin(c, x){
  const { esc } = x, s = c.sv;
  if (s.indispo) { return `<div data-screen="suivi-qui-fait-quoi">${panne(esc, s.indispo)}</div>`; }
  if (s.chargement) { return `<div data-screen="suivi-qui-fait-quoi">${attente(esc, 'Lecture des règles du suivi…')}</div>`; }
  const barre = `<div class="sv-barre${s.modifie ? ' on' : ''}"><span>${s.modifie ? 'Modifications non enregistrées.' : 'Les règles valent pour tout le réseau ; un magasin peut avoir les siennes.'}</span>
    ${s.annuler ? `<button ${x.A(s.annuler)} class="cx-btn">Annuler</button>` : ''}<button ${x.A(s.enregistrer)} class="cx-btn prim" ${s.busy || !s.modifie ? 'disabled' : ''}>${s.busy ? 'Enregistrement…' : 'Enregistrer'}</button></div>`;
  const mags = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">Par magasin — qui fait quoi</span><span class="mko-mini">qui visite et combien de fois : <button ${x.A(s.cadre)} class="gc-lien">le cadre de visite ›</button></span></div><div style="overflow-x:auto"><table class="mko-tab sv-tab sv-qfq">
      <thead><tr><th>Magasin</th><th>Prépare</th><th>Adapte le rapport</th><th>Envoie</th><th>Valide les corrections</th><th>En copie</th><th>Courriel du franchisé</th><th>Langue</th></tr></thead>
      <tbody>${s.magasins.map(m => `<tr><td class="nom">${esc(m.court)}<small>${esc(m.responsable)}</small><small>${esc(m.cadre)}</small></td>
        <td>${sel(x, esc, m.setPrepare, m.prepare)}</td><td>${sel(x, esc, m.setAdapte, m.adapte)}</td><td>${sel(x, esc, m.setEnvoie, m.envoie)}</td><td>${sel(x, esc, m.setCorrections, m.corrections)}</td>
        <td><div class="sv-cc">${m.copie.map(p => `<button ${x.A(p.basculer)} class="cx-pu${p.on ? ' on' : ''}">${esc(p.nom)}</button>`).join('')}</div></td>
        <td><input class="mko-inp" type="email" style="min-width:190px" placeholder="adresse du franchisé" value="${esc(m.email)}" ${x.C(m.setEmail)}><small class="${m.emailKo ? 'ko' : ''}">${esc(m.emailAide)}</small></td>
        <td>${sel(x, esc, m.setLangue, m.langues)}</td></tr>`).join('')}</tbody></table></div></div>`;
  const types = carte('Les types de visite — annoncée ou inopinée', 'annoncée : l’ordre du jour part au franchisé',
    `<div class="sv-types">${s.types.map(t => `<div class="sv-type"><b>${esc(t.nom)}</b>${duo(x, esc, { nom: 'Annoncée', on: t.annoncee, fn: t.annoncer }, { nom: 'Inopinée', on: !t.annoncee, fn: t.inopiner })}</div>`).join('')}</div>
    <div class="mko-mu" style="margin-top:8px">Une visite inopinée n’envoie rien et n’apparaît pas sur la page du franchisé. Le réglage se change visite par visite au moment de la préparer.</div>`);
  const pers = `<div class="mko-carte"><div class="mko-ct"><span class="mko-lab">Les personnes et leurs droits</span><span class="mko-mini">les consultants viennent du panel</span></div><div style="overflow-x:auto"><table class="mko-tab sv-tab" style="min-width:640px">
      <thead><tr><th>Personne</th>${s.droits.map(d => `<th class="c">${esc(d)}</th>`).join('')}<th></th></tr></thead>
      <tbody>${s.personnes.map(p => `<tr><td class="nom">${esc(p.nom)}<small>${esc(p.sous)}</small></td>${p.droits.map(d => `<td class="c"><input type="checkbox" ${d.on ? 'checked' : ''} ${x.C(d.basculer)}></td>`).join('')}<td>${p.retirer ? `<button ${x.A(p.retirer)} class="mko-pied-btn">Retirer</button>` : ''}</td></tr>`).join('')}
      ${s.nouveauxAdmins.map(n => `<tr><td class="nom mu" colspan="${s.droits.length + 2}">${esc(n)}</td></tr>`).join('')}</tbody></table></div>
    <div class="mko-corps sv-nouv"><input class="mko-inp" placeholder="Nom (admin, responsable réseau…)" value="${esc(s.nouvelAdmin.nom)}" ${x.I(s.nouvelAdmin.setNom)}><input class="mko-inp" type="email" placeholder="Courriel" value="${esc(s.nouvelAdmin.email)}" ${x.I(s.nouvelAdmin.setEmail)}><input class="mko-inp" placeholder="Rôle" value="${esc(s.nouvelAdmin.role)}" ${x.I(s.nouvelAdmin.setRole)}><button ${x.A(s.nouvelAdmin.ajouter)} class="cx-btn">Ajouter</button></div></div>`;
  const D = s.delais;
  const num = (v, fn, u) => `<span class="cx-ligne"><input class="mko-inp" type="number" min="0" style="width:72px" value="${esc(v)}" ${x.C(fn)}><span class="mko-mu">${u}</span></span>`;
  const regle = (lab, aide, ctrl) => `<div class="sv-regle"><div><b>${lab}</b><small>${aide}</small></div><div>${ctrl}</div></div>`;
  const delais = carte('Les règles et les délais', '',
    regle('Dossier de visite', 'prêt avant la visite', num(D.prepJours, D.setPrepJours, 'jours avant'))
    + regle('Ordre du jour au franchisé', 'visites annoncées seulement', `${duo(x, esc, { nom: 'Tout seul', on: D.odjAuto, fn: D.setOdjAuto(true) }, { nom: 'À la main', on: !D.odjAuto, fn: D.setOdjAuto(false) })} ${D.odjAuto ? num(D.odjJours, D.setOdjJours, 'jours avant') : ''}`)
    + regle('Rapport envoyé', 'après la fin de la visite', num(D.rapportHeures, D.setRapportHeures, 'heures'))
    + regle('Délai dépassé', 'si le rapport n’est pas parti', duo(x, esc, { nom: 'Rappel au bureau', on: D.rapportTard === 'rappel', fn: D.setRapportTard('rappel') }, { nom: 'Envoyé tel quel', on: D.rapportTard === 'tel_quel', fn: D.setRapportTard('tel_quel') }))
    + regle('Délais des points', 'échéance proposée selon la priorité', `<span class="cx-ligne">P0 ${num(D.p0, D.setP0, 'j')} P1 ${num(D.p1, D.setP1, 'j')} P2 ${num(D.p2, D.setP2, 'j')}</span>`)
    + regle('Photo de correction validée', 'par la personne réglée au magasin', num(D.validationHeures, D.setValidationHeures, 'heures'))
    + regle('Escalade', 'point en retard remonté à l’admin', num(D.escaladeJours, D.setEscaladeJours, 'jours de retard'))
    + regle('Relance du rapport pas lu', 'un courriel au franchisé', num(D.relanceLuJours, D.setRelanceLuJours, 'jours après l’envoi')));
  const voit = carte('Ce que voit le franchisé', 'sur sa page', `<div class="sv-pile" style="gap:8px">${s.voit.map(v => `<div class="sv-ligne">${bascule(x, v.on, v.basculer, esc(v.l))}</div>`).join('')}</div>`);
  const M = s.modele;
  const modeles = `<div class="mko-carte" style="margin-top:12px"><div class="mko-ct"><span class="mko-lab">Les courriels aux franchisés</span><span class="mko-mini">un modèle par langue · la langue suit le magasin</span></div>
    <div class="mko-corps sv-mod"><div class="sv-modl">${s.modeles.map(m => `<button ${x.A(m.choisir)} class="${m.on ? 'on' : ''}">${esc(m.nom)}${m.modifie ? ' <i title="modifié">•</i>' : ''}</button>`).join('')}</div>
    <div class="sv-pile"><div class="cx-vues">${s.langues.map(l => `<button ${x.A(l.choisir)} class="${l.on ? 'on' : ''}">${esc(l.nom)}${l.modifie ? ' •' : ''}</button>`).join('')}</div>
      <div class="mko-mu">Part ${esc(M.quand)}. Variables : ${M.vars.map(v => `<code>${esc(v)}</code>`).join(' ')}</div>
      <div class="f sv-f1"><label>Objet</label><input class="mko-inp" value="${esc(M.sujet)}" ${x.I(M.setSujet)}></div>
      <div class="f sv-f1"><label>Texte</label><textarea class="mko-inp sv-ta" rows="12" ${x.I(M.setCorps)}>${esc(M.corps)}</textarea><small>Une ligne vide sépare les paragraphes. {sujets} et {points} seuls sur leur ligne deviennent une liste.</small></div>
      <div class="sv-pied" style="justify-content:flex-start"><button ${x.A(M.voir)} class="cx-btn">Aperçu</button>${M.remettre ? `<button ${x.A(M.remettre)} class="cx-btn">Remettre le texte livré</button>` : ''}<button ${x.A(M.enregistrer)} class="cx-btn prim" ${M.change ? '' : 'disabled'}>Enregistrer le modèle</button>
        <span class="cx-ligne" style="margin-left:auto"><input class="mko-inp" type="email" placeholder="adresse d’essai" style="width:200px" value="${esc(M.essaiA)}" ${x.I(M.setEssaiA)}><button ${x.A(M.essai)} class="cx-btn" ${M.smtp ? '' : 'disabled title="SMTP non configuré"'}>Envoyer un essai</button></span></div>
      ${M.apercu ? `<div class="sv-mail"><b>${esc(M.apercu.sujet)}</b><iframe class="sv-ifr court" srcdoc="${esc(M.apercu.html)}" title="Aperçu du courriel"></iframe></div>` : ''}</div></div></div>`;
  return `<div data-screen="suivi-qui-fait-quoi">${msgDe(esc, s.msg)}<div class="gc-kpis">${s.kpis.map(k => kpi(esc, k)).join('')}</div>${barre}${mags}<div class="cx-deux" style="margin-top:12px"><div class="sv-pile">${types}${pers}</div><div class="sv-pile">${delais}${voit}</div></div>${modeles}</div>`;
}
