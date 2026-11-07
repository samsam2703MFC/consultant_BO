/*
 * Le cadre des tâches du panel — l'application /task (demande du 07/11/2026).
 *
 * À gauche les checklists du cadre opérationnel ; au centre leurs tâches (jour, heure,
 * fréquence, obligatoire, photo requise) ; on coche des tâches et on les DUPLIQUE avec
 * d'autres réglages : « les photos du comptoir, l'après-midi, samedi et dimanche, 14:30,
 * obligatoires, dans la même checklist ». Chaque écriture passe d'abord par un aperçu qui
 * montre les appels exacts au panel ; la création s'arrête au premier refus.
 *
 * Lectures : ../api/cockpit/task/cadre, …/task/taches/{id}. Écritures : POST …/task/apercu,
 * …/task/creer, PATCH et DELETE …/task/taches/{id}. Contrat : docs/contrat-api.md, « /task ».
 *
 * Compte : celui du cockpit, ou un compte administrateur du panel connecté ici. Son jeton reste
 * dans l'onglet (sessionStorage) et part dans l'en-tête X-Panel-Jeton ; le mot de passe ne
 * fait que passer.
 */
(function () {
  'use strict';
  const API = '../api/cockpit';
  const CLE = 'tk.admin';
  const q = new URLSearchParams(location.search);
  const $ = document.getElementById('tk');

  const S = {
    cadre: null, err: null, charge: true,
    cl: q.get('checklist') || null,        // id de checklist, 'tout' ou 'hors'
    filtre: '', sel: new Set(),
    dup: null, plan: null, res: null, envoi: false,
    modale: null, msg: null,
  };

  /* --- formats ------------------------------------------------------------ */
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const json = v => esc(JSON.stringify(v, null, 2));
  const sansAccent = s => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
  const JOURS = {
    monday: 'lundi', tuesday: 'mardi', wednesday: 'mercredi', thursday: 'jeudi', friday: 'vendredi', saturday: 'samedi', sunday: 'dimanche',
    mon: 'lundi', tue: 'mardi', wed: 'mercredi', thu: 'jeudi', fri: 'vendredi', sat: 'samedi', sun: 'dimanche',
    lundi: 'lundi', mardi: 'mardi', mercredi: 'mercredi', jeudi: 'jeudi', vendredi: 'vendredi', samedi: 'samedi', dimanche: 'dimanche',
    poniedzialek: 'lundi', wtorek: 'mardi', sroda: 'mercredi', czwartek: 'jeudi', piatek: 'vendredi', sobota: 'samedi', niedziela: 'dimanche',
  };
  // Seuls les noms de jour se traduisent : un chiffre (6 = samedi ? vendredi ?) dépend du panel,
  // l'écran ne le devine pas — il montre des exemples de tâches qui l'emploient.
  const jourFr = v => {
    const p = String(v || '').split(/[,;|]/).map(x => sansAccent(x).replace(/[^a-z]/g, '')).filter(Boolean);
    if (!p.length || p.some(x => !JOURS[x])) { return null; }
    return p.map(x => JOURS[x]).join(' + ');
  };
  const jourAff = v => v === '' || v == null ? '<span class="mu">—</span>' : esc(v) + (jourFr(v) ? ' <small>' + esc(jourFr(v)) + '</small>' : '');

  /* --- API ---------------------------------------------------------------- */
  const admin = () => { try { return JSON.parse(sessionStorage.getItem(CLE) || 'null'); } catch (e) { return null; } };
  async function appel(m, p, b, jeton) {
    const h = { Accept: 'application/json' };
    if (b != null) { h['Content-Type'] = 'application/json'; }
    if (jeton) { h['X-Panel-Jeton'] = jeton; }
    const r = await fetch(API + p, { method: m, headers: h, body: b != null ? JSON.stringify(b) : undefined, credentials: 'same-origin' });
    let j = null;
    try { j = await r.json(); } catch (e) { /* corps vide */ }
    if (!r.ok) { const e = new Error((j && j.error) || ('HTTP ' + r.status)); e.data = j; e.status = r.status; throw e; }
    return j;
  }
  async function api(m, p, b) {
    let a = admin();
    if (a && a.expire * 1000 < Date.now() + 60000) {
      a = null;
      const vieux = admin();
      sessionStorage.removeItem(CLE);
      if (vieux && vieux.renouvellement) {
        try { a = await appel('POST', '/task/connexion/renouveler', { renouvellement: vieux.renouvellement }); sessionStorage.setItem(CLE, JSON.stringify(a)); }
        catch (e) { S.msg = { k: 'wa', t: 'La session administrateur du panel a expiré : le cockpit reprend son propre compte. Reconnectez-vous si le panel refuse.' }; }
      }
    }
    return appel(m, p, b, a && a.jeton);
  }

  /* --- données ------------------------------------------------------------ */
  const taches = () => (S.cadre && S.cadre.taches) || [];
  const tache = id => taches().find(t => t.id === +id) || null;
  const checklists = () => (S.cadre && S.cadre.checklists) || [];
  const checklist = id => checklists().find(c => String(c.id) === String(id)) || null;
  const exemples = (champ, v) => taches().filter(t => String((t.brut || {})[champ] == null ? '' : t.brut[champ]) === v).slice(0, 3).map(t => t.nom);

  function lignes() {
    let out;
    if (S.cl === 'tout') { out = taches().slice().sort((a, b) => a.nom.localeCompare(b.nom, 'fr')).map(t => ({ t, ordre: null })); }
    else if (S.cl === 'hors') { const h = new Set((S.cadre && S.cadre.horsChecklist) || []); out = taches().filter(t => h.has(t.id)).map(t => ({ t, ordre: null })); }
    else { const c = checklist(S.cl); out = c ? c.taches.map(l => ({ t: tache(l.taskId) || { id: l.taskId, nom: 'Tâche #' + l.taskId, brut: {} }, ordre: l.ordre, note: l.note })) : []; }
    const f = sansAccent(S.filtre);
    if (f) { out = out.filter(x => sansAccent([x.t.nom, x.t.section, x.t.categorie, x.t.sousCategorie, x.t.id].join(' ')).includes(f)); }
    return out;
  }

  async function charger(garderMsg) {
    S.charge = true;
    if (!garderMsg) { S.msg = null; }
    rendre();
    try {
      S.cadre = await api('GET', '/task/cadre');
      S.err = null;
      // Un jeton administrateur que le panel ne reconnaît plus : on le lâche et on relit avec le
      // compte du cockpit, plutôt que de laisser l'écran vide.
      if (admin() && Object.values(S.cadre.erreurs || {}).some(v => /session du panel expirée/.test(v))) {
        sessionStorage.removeItem(CLE);
        S.msg = { k: 'wa', t: 'La session administrateur du panel a expiré : relu avec le compte du cockpit. Reconnectez-vous pour créer.' };
        S.cadre = await api('GET', '/task/cadre');
      }
      if (S.cl == null || (S.cl !== 'tout' && S.cl !== 'hors' && !checklist(S.cl))) {
        S.cl = checklists().length ? String(checklists()[0].id) : 'tout';
      }
    } catch (e) {
      S.err = e.message;
      if (e.data && e.data.connexionRequise) { S.modale = { type: 'connexion', f: {} }; }
    }
    S.charge = false;
    rendre();
  }

  /* --- rendu -------------------------------------------------------------- */
  function enTete() {
    const a = admin();
    const compte = a ? '<b>Compte administrateur du panel</b> · jusqu’à ' + new Date(a.expire * 1000).toLocaleTimeString('fr-BE', { hour: '2-digit', minute: '2-digit' })
      : '<b>Compte du cockpit</b>' + (S.cadre && S.cadre.api && !S.cadre.api.configure ? ' (non configuré)' : '');
    return `<div class="tk-hd"><img src="../assets/img/logo.png" alt=""><div><div class="tk-titre">Cadre des tâches</div>
      <div class="tk-sous">Les tâches que les magasins font chaque jour, telles que le panel les définit${S.cadre && S.cadre.api ? ' · ' + esc(S.cadre.api.base) : ''}</div></div>
      <span class="sp"></span><span class="tk-compte">${compte}</span>
      ${a ? '<button class="tk-btn" data-a="deconnexion">Revenir au compte du cockpit</button>' : '<button class="tk-btn" data-a="connexion">Compte administrateur…</button>'}
      <button class="tk-btn" data-a="recharger" ${S.charge ? 'disabled' : ''}>${S.charge ? 'Lecture…' : 'Relire le panel'}</button></div>
      <p class="tk-intro">Cochez une ou plusieurs tâches, puis <b>Dupliquer</b> : les copies reprennent tout du modèle (sous-catégorie, priorité, niveaux de poste, contrôleurs) sauf ce que vous changez — le nom, les jours, l’heure, obligatoire, photo requise. Rien ne part au panel sans un aperçu des appels exacts.</p>`;
  }

  function erreurs() {
    let h = '';
    if (S.msg) { h += `<div class="tk-msg ${S.msg.k}">${S.msg.t}</div>`; }
    if (S.err) { h += `<div class="tk-err"><b>Le panel ne répond pas comme prévu :</b> ${esc(S.err)}</div>`; }
    const e = S.cadre && S.cadre.erreurs ? Object.entries(S.cadre.erreurs) : [];
    if (e.length) { h += `<div class="tk-err">Lectures incomplètes :<ul>${e.map(([k, v]) => `<li>${esc(k)} — ${esc(v)}</li>`).join('')}</ul></div>`; }
    return h;
  }

  function cote() {
    const n = taches().length;
    const h = ((S.cadre && S.cadre.horsChecklist) || []).length;
    const b = (id, nom, sous, nb, grp) => `<button class="tk-cl${grp ? ' grp' : ''}${String(S.cl) === String(id) ? ' on' : ''}" data-a="cl" data-id="${esc(id)}"><span>${esc(nom)}${sous ? `<small>${esc(sous)}</small>` : ''}</span><span class="nb">${nb}</span></button>`;
    return `<div class="tk-card"><div class="tk-ct"><span class="tk-k">Checklists</span><span class="mu" style="margin-left:auto;font-size:11px">${checklists().length}</span></div><div class="tk-cls">
      ${b('tout', 'Toutes les tâches', '', n, true)}${h ? b('hors', 'Dans aucune checklist', '', h, true) : ''}
      ${checklists().map(c => b(c.id, c.nom, [c.heure, c.poste].filter(Boolean).join(' · '), c.taches.length)).join('')}</div></div>`;
  }

  function oui(v) { return v ? '<span class="tk-pas oui">oui</span>' : '<span class="mu">non</span>'; }

  function tableau() {
    const L = lignes();
    const c = checklist(S.cl);
    const tous = L.length && L.every(x => S.sel.has(x.t.id));
    const titre = c ? c.nom : (S.cl === 'hors' ? 'Dans aucune checklist' : 'Toutes les tâches');
    const sous = c ? [c.heure && 'checklist à ' + c.heure, c.poste, c.description].filter(Boolean).join(' · ') : '';
    return `<div class="tk-card"><div class="tk-ct"><div><div style="font-size:15px">${esc(titre)}</div>${sous ? `<div class="tk-sous">${esc(sous)}</div>` : ''}</div>
      <span class="sp" style="flex:1"></span>
      <input class="tk-in" data-f="filtre" placeholder="Filtrer…" value="${esc(S.filtre)}" style="width:180px">
      <button class="tk-btn prim" data-a="dupliquer" ${S.sel.size ? '' : 'disabled'}>Dupliquer la sélection${S.sel.size ? ' (' + S.sel.size + ')' : ''}</button>
      ${S.sel.size ? '<button class="tk-lien" data-a="vider">tout décocher</button>' : ''}</div>
      <div class="tk-defile"><table class="tk-tab"><thead><tr>
        <th class="c"><input type="checkbox" data-a="tous" ${tous ? 'checked' : ''} aria-label="Tout cocher"></th>
        ${c ? '<th class="n">Ordre</th>' : ''}<th>Tâche</th><th>Jour</th><th>Heure</th><th>Fréquence</th><th class="c">Obligatoire</th><th class="c">Photo</th><th class="c">Active</th><th class="n">ID</th><th></th></tr></thead><tbody>
      ${L.length ? L.map(x => {
        const t = x.t;
        const chemin = [t.section, t.categorie, t.sousCategorie].filter(Boolean).join(' › ');
        return `<tr class="${S.sel.has(t.id) ? 'sel' : ''}${t.actif === false ? ' pause' : ''}">
          <td class="c"><input type="checkbox" data-a="coche" data-id="${t.id}" ${S.sel.has(t.id) ? 'checked' : ''}></td>
          ${c ? `<td class="n">${x.ordre == null ? '' : x.ordre}</td>` : ''}
          <td>${esc(t.nom)}${t.horsListe ? ' <span class="tk-pas att">absente de /tasks</span>' : ''}${chemin ? `<small>${esc(chemin)}</small>` : ''}${x.note ? `<small>Note : ${esc(x.note)}</small>` : ''}</td>
          <td>${jourAff(t.jour)}</td><td>${esc(t.heure || '—')}</td><td>${esc(t.frequence || '—')}</td>
          <td class="c">${oui(t.obligatoire)}</td><td class="c">${oui(t.photo)}</td>
          <td class="c">${t.actif == null ? '<span class="mu">?</span>' : (t.actif ? '<span class="tk-pas oui">oui</span>' : '<span class="tk-pas att">en pause</span>')}</td>
          <td class="n mu">${t.id}</td>
          <td class="act"><button class="tk-lien" data-a="modifier" data-id="${t.id}">Modifier</button><button class="tk-lien" data-a="brut" data-id="${t.id}">Donnée brute</button></td></tr>`;
      }).join('') : `<tr><td colspan="11" class="mu" style="padding:16px">${S.charge ? 'Lecture…' : 'Aucune tâche.'}</td></tr>`}
      </tbody></table></div></div>`;
  }

  /* --- duplication -------------------------------------------------------- */
  function ouvrirDup() {
    const c = checklist(S.cl);
    S.dup = { modeles: Array.from(S.sel), remplacer: '', par: '', ajouter: '', jours: [], autre: '', unParJour: true, heure: '',
      frequence: '__m', obligatoire: null, photo: null, checklistId: c ? c.id : 0, niveaux: true, controleurs: true, extra: '' };
    S.plan = null; S.res = null;
  }

  function nomExemple() {
    const d = S.dup;
    const t = tache(d.modeles[0]);
    if (!t) { return ''; }
    let n = t.nom;
    if (d.remplacer && n.includes(d.remplacer)) { n = n.split(d.remplacer).join(d.par); }
    n = (n + d.ajouter).trim();
    return `« ${esc(t.nom)} » → <b>« ${esc(n)} »</b>`;
  }

  function corpsDup() {
    const d = S.dup;
    let extra = {};
    if (d.extra.trim()) {
      try { extra = JSON.parse(d.extra); } catch (e) { throw new Error('Champs en plus : JSON invalide (' + e.message + ')'); }
      if (!extra || typeof extra !== 'object' || Array.isArray(extra)) { throw new Error('Champs en plus : un objet JSON est attendu, par exemple {"priority": 2}'); }
    }
    return { modeles: d.modeles, checklistId: +d.checklistId || 0, nom: { remplacer: d.remplacer, par: d.par, ajouter: d.ajouter },
      jours: d.jours, unParJour: d.unParJour, heure: d.heure || '', frequence: d.frequence === '__m' ? null : d.frequence,
      obligatoire: d.obligatoire, photo: d.photo, niveaux: d.niveaux, controleurs: d.controleurs, extra };
  }

  function seg(k) {
    const v = S.dup[k];
    const b = (val, lib) => `<button data-a="dseg" data-k="${k}" data-v="${val}" class="${String(v) === val ? 'on' : ''}">${lib}</button>`;
    return `<span class="tk-seg">${b('null', 'comme le modèle')}${b('true', 'oui')}${b('false', 'non')}</span>`;
  }

  function volet() {
    const d = S.dup;
    const ops = (S.cadre && S.cadre.options) || { jours: [], frequences: [] };
    const valeursJour = ops.jours.filter(o => o.valeur !== '');
    const perso = d.jours.filter(v => !valeursJour.some(o => o.valeur === v));
    const chip = (v, nb) => {
      const fr = jourFr(v);
      const ex = exemples('day_of_week', v);
      return `<button class="tk-chip${d.jours.includes(v) ? ' on' : ''}" data-a="jour" data-v="${esc(v)}" title="${esc(ex.length ? 'Par exemple : ' + ex.join(' ; ') : '')}">${esc(v)}${fr ? ' · ' + esc(fr) : ''}${nb != null ? `<small>${nb}</small>` : ''}</button>`;
    };
    const nbCopies = d.modeles.length * (d.jours.length > 1 && d.unParJour ? d.jours.length : 1);
    return `<div class="tk-card tk-dup"><div class="tk-ct"><span class="tk-k">Dupliquer ${d.modeles.length} tâche${d.modeles.length > 1 ? 's' : ''}</span>
      <span class="mu" style="font-size:11.5px">${nbCopies} copie${nbCopies > 1 ? 's' : ''} prévue${nbCopies > 1 ? 's' : ''}</span><button class="tk-x" data-a="fermerDup" aria-label="Fermer">×</button></div>
      <div class="tk-form">
        <label>Modèles</label><div class="tk-modeles">${d.modeles.map(id => `<span>${esc((tache(id) || {}).nom || ('#' + id))}</span>`).join('')}</div>

        <label>Nom des copies</label><div>
          <div class="tk-ligne">Remplacer <input class="tk-in" data-d="remplacer" value="${esc(d.remplacer)}" placeholder="Photo du comptoir" style="width:200px"> par <input class="tk-in" data-d="par" value="${esc(d.par)}" placeholder="Photo du comptoir après-midi" style="width:240px"></div>
          <div class="tk-ligne" style="margin-top:6px">Ajouter à la fin <input class="tk-in" data-d="ajouter" value="${esc(d.ajouter)}" placeholder=" (après-midi)" style="width:200px"></div>
          <div class="aide" data-zone="exemple">${nomExemple()}</div></div>

        <label>Jours</label><div>
          <div class="tk-ligne">${valeursJour.map(o => chip(o.valeur, o.nb)).join('')}${perso.map(v => chip(v, null)).join('')}</div>
          <div class="tk-ligne" style="margin-top:6px"><input class="tk-in" data-d="autre" value="${esc(d.autre)}" placeholder="autre valeur" style="width:140px"><button class="tk-btn" data-a="autreJour">Ajouter</button></div>
          ${d.jours.length > 1 ? `<label class="tk-ligne" style="margin-top:6px;font-size:12px"><input type="checkbox" data-d="unParJour" ${d.unParJour ? 'checked' : ''}> Une tâche par jour (le panel range un seul jour par tâche)</label>` : ''}
          <div class="aide">Les valeurs sont celles que le panel emploie déjà, avec le nombre de tâches qui les portent (survolez pour des exemples). Aucun jour choisi : chaque copie garde le jour de son modèle.</div></div>

        <label>Heure</label><div><input type="time" class="tk-in" data-d="heure" value="${esc(d.heure)}"><div class="aide">Vide : l’heure du modèle.</div></div>

        <label>Fréquence</label><div><select class="tk-in" data-d="frequence"><option value="__m"${d.frequence === '__m' ? ' selected' : ''}>comme le modèle</option>
          ${ops.frequences.map(o => `<option value="${esc(o.valeur)}"${d.frequence === o.valeur ? ' selected' : ''}>${esc(o.valeur === '' ? '(vide)' : o.valeur)} — ${o.nb} tâche${o.nb > 1 ? 's' : ''}</option>`).join('')}</select></div>

        <label>Obligatoire</label><div>${seg('obligatoire')}</div>
        <label>Photo requise</label><div>${seg('photo')}<div class="aide">Oui : l’employé ne peut pas valider la tâche sans photo, et la photo arrive au contrôle des tâches du cockpit.</div></div>

        <label>Checklist</label><div><select class="tk-in" data-d="checklistId"><option value="0">aucune</option>
          ${checklists().map(c => `<option value="${c.id}"${String(d.checklistId) === String(c.id) ? ' selected' : ''}>${esc(c.nom)}</option>`).join('')}</select>
          <div class="aide">Les copies se placent après la dernière tâche de la checklist.</div></div>

        <label>Rattachements</label><div>
          <label class="tk-ligne" style="font-size:12px"><input type="checkbox" data-d="niveaux" ${d.niveaux ? 'checked' : ''}> Recopier les niveaux de poste du modèle (sans eux, aucun employé ne voit la tâche)</label>
          <label class="tk-ligne" style="font-size:12px;margin-top:4px"><input type="checkbox" data-d="controleurs" ${d.controleurs ? 'checked' : ''}> Recopier les contrôleurs du modèle</label></div>

        <label>Avancé</label><div><details${d.extra.trim() ? ' open' : ''}><summary class="tk-lien" style="text-decoration:none">Champs en plus, envoyés tels quels à la création (JSON)</summary>
          <textarea class="tk-in" data-d="extra" placeholder='{"priority": 1}' style="margin-top:6px">${esc(d.extra)}</textarea></details></div>
      </div>
      <div class="tk-pied">
        <button class="tk-btn prim" data-a="apercu" ${S.envoi ? 'disabled' : ''}>${S.envoi && !S.plan ? 'Préparation…' : 'Voir l’aperçu'}</button>
        ${S.plan ? `<span style="font-size:12px">${S.plan.aCreer} tâche${S.plan.aCreer > 1 ? 's' : ''} à créer${S.plan.items.length - S.plan.aCreer ? ', ' + (S.plan.items.length - S.plan.aCreer) + ' existe(nt) déjà' : ''}</span>
          <span style="flex:1"></span>
          ${S.plan.aCreer ? `<button class="tk-btn" data-a="creer" data-essai="1" ${S.envoi ? 'disabled' : ''}>Créer la première seulement (essai)</button>
          <button class="tk-btn prim" data-a="creer" ${S.envoi ? 'disabled' : ''}>Créer les ${S.plan.aCreer} tâches</button>` : ''}` : '<span class="mu" style="font-size:11.5px">L’aperçu montre chaque appel au panel avec son corps exact ; rien n’est envoyé.</span>'}
      </div>
      ${S.plan ? planTable(S.plan, false) : ''}
      ${S.res ? resultat() : ''}</div>`;
  }

  function appels(ops, faits) {
    return ops.map(o => `<div class="tk-appel"><div class="t">${faits ? (o.ok ? '<span class="ok">✓</span>' : (o.code == null ? '<span class="mu">·</span>' : '<span class="ko">✗</span>')) : ''}
      <code>${esc(o.methode)} ${esc(o.chemin)}</code><span class="mu">${esc(o.quoi)}</span>${faits && o.code != null ? `<span style="margin-left:auto" class="${o.ok ? 'ok' : 'ko'}">HTTP ${o.code || '—'}</span>` : ''}</div>
      <pre>${json(o.corps)}</pre>${faits && o.code != null ? `<pre style="border-top:1px solid var(--tk-trait);background:var(--paper)">${o.erreur ? esc(o.erreur) + '\n' : ''}${json(o.reponse)}</pre>` : ''}</div>`).join('');
  }

  function planTable(p, faits) {
    const av = (p.avertissements || []).length ? `<div class="tk-msg wa" style="margin:10px 16px 0">${p.avertissements.map(esc).join('<br>')}</div>` : '';
    return `${av}<div class="tk-defile"><table class="tk-tab tk-plan"><thead><tr><th>Nouvelle tâche</th><th>Jour</th><th>Heure</th><th>Copie de</th><th>${faits ? 'Résultat' : 'État'}</th><th></th></tr></thead><tbody>
      ${p.items.map(it => {
        const st = faits ? (it.statut || '') : (it.existe ? 'existe déjà (#' + it.existe + ')' : 'à créer');
        const cl = /refus|inconnu/.test(st) ? 'non' : (/^créée$|^à créer$/.test(st) ? 'oui' : 'att');
        return `<tr><td>${esc(it.nom)}${it.id ? ` <small>#${it.id}</small>` : ''}</td><td>${jourAff(it.jour)}</td><td>${esc(it.heure || '—')}</td><td class="mu">${esc(it.modeleNom)}</td>
          <td><span class="tk-pas ${cl}">${esc(st)}</span></td>
          <td class="act">${faits && it.id ? `<button class="tk-lien ko" data-a="supprimer" data-id="${it.id}">Supprimer</button>` : ''}</td></tr>
          ${it.existe && !faits ? '' : `<tr><td colspan="6" class="json"><details class="tk-appels"${faits && /refus/.test(st) ? ' open' : ''}><summary>${it.operations.length} appel${it.operations.length > 1 ? 's' : ''} au panel</summary>${appels(it.operations, faits)}</details></td></tr>`}`;
      }).join('')}</tbody></table></div>`;
  }

  function resultat() {
    const r = S.res;
    const droits = r.arret && /HTTP 40[13]/.test(r.arret) && !admin() ? ' Le compte du cockpit n’a peut-être pas le droit d’écrire ici : connectez-vous avec un <button class="tk-lien" data-a="connexion">compte administrateur du panel</button> puis recommencez.' : '';
    const tete = r.arret ? `<div class="tk-msg ko" style="margin:10px 16px 0">${esc(r.arret)}${droits}</div>`
      : `<div class="tk-msg ok" style="margin:10px 16px 0">${r.creees} tâche${r.creees > 1 ? 's' : ''} créée${r.creees > 1 ? 's' : ''} dans le panel.${r.items.some(i => /essai/.test(i.statut || '')) ? ' Vérifiez-la dans le panel ou l’app employés, puis créez les autres : celles déjà créées ne seront pas recréées.' : ''}</div>`;
    return `<div class="tk-ct" style="border-top:1px solid var(--tk-trait)"><span class="tk-k">Résultat</span></div>${tete}${planTable(r, true)}`;
  }

  function creees() {
    const L = (S.cadre && S.cadre.creees) || [];
    if (!L.length) { return ''; }
    return `<div class="tk-card"><div class="tk-ct"><span class="tk-k">Créées depuis cet écran</span><span class="mu" style="font-size:11px">les seules qu’on peut supprimer ici</span></div>
      <div class="tk-defile"><table class="tk-tab"><thead><tr><th>Tâche</th><th>Jour</th><th>Heure</th><th>Checklist</th><th>Le</th><th class="n">ID</th><th></th></tr></thead><tbody>
      ${L.map(c => `<tr><td>${esc(c.nom)}</td><td>${jourAff(c.jour)}</td><td>${esc(c.heure || '—')}</td><td class="mu">${esc((checklist(c.checklistId) || {}).nom || (c.checklistId ? '#' + c.checklistId : '—'))}</td>
        <td class="mu">${esc(c.le)}${c.compte === 'admin' ? ' · admin' : ''}</td><td class="n mu">${c.id}</td>
        <td class="act"><button class="tk-lien ko" data-a="supprimer" data-id="${c.id}">Supprimer</button></td></tr>`).join('')}</tbody></table></div></div>`;
  }

  /* --- fenêtres ----------------------------------------------------------- */
  function modale() {
    const m = S.modale;
    if (!m) { return ''; }
    let corps = '';
    if (m.type === 'connexion') {
      corps = `<div class="tk-ct"><h2>Compte administrateur du panel</h2><button class="tk-x" data-a="fermer">×</button></div>
        <div class="tk-cb"><p class="tk-note">Si le panel refuse les créations au compte du cockpit, connectez-vous avec un compte administrateur — le même téléphone et le même mot de passe que sur le panel admin. Le jeton reste dans cet onglet et expire après 30 minutes ; le mot de passe n’est gardé nulle part.</p>
        ${m.err ? `<div class="tk-err">${esc(m.err)}</div>` : ''}
        <div class="tk-form" style="padding:0"><label>Téléphone</label><input class="tk-in" data-m="phone" autocomplete="username" value="${esc(m.f.phone || '')}">
        <label>Mot de passe</label><input class="tk-in" type="password" data-m="password" autocomplete="current-password"></div></div>
        <div class="tk-pied"><button class="tk-btn prim" data-a="seConnecter" ${m.envoi ? 'disabled' : ''}>${m.envoi ? 'Connexion…' : 'Se connecter'}</button><button class="tk-btn" data-a="fermer">Annuler</button></div>`;
    } else if (m.type === 'brut') {
      const d = m.data;
      corps = `<div class="tk-ct"><h2>Donnée brute — tâche #${m.id}</h2><button class="tk-x" data-a="fermer">×</button></div><div class="tk-cb">
        ${!d ? '<div class="tk-note">Lecture…</div>' : `${d.erreur ? `<div class="tk-err">${esc(d.erreur)}</div>` : ''}
        <div class="tk-k" style="margin:4px 0 6px">Telle que GET /tasks la rend</div><pre class="tk-json">${json(d.tache ? d.tache.brut : null)}</pre>
        <div class="tk-k" style="margin:12px 0 6px">Niveaux de poste qui la portent</div><div style="font-size:12px">${d.niveaux.length ? d.niveaux.map(n => `#${n.niveau} <span class="mu">(${esc(n.route)})</span>`).join(' · ') : '<span class="mu">aucun</span>'}</div>
        <div class="tk-k" style="margin:12px 0 6px">Contrôleurs — GET /tasks/${m.id}/controllers · HTTP ${d.controleurs.code || '—'}</div><pre class="tk-json">${json(d.controleurs.brut)}</pre>`}</div>`;
    } else if (m.type === 'modif') {
      const f = m.f;
      const ops = (S.cadre && S.cadre.options) || { jours: [], frequences: [] };
      const dl = (id, L) => `<datalist id="${id}">${L.filter(o => o.valeur !== '').map(o => `<option value="${esc(o.valeur)}">${o.nb} tâche(s)</option>`).join('')}</datalist>`;
      corps = `<div class="tk-ct"><h2>Modifier la tâche #${m.id}</h2><button class="tk-x" data-a="fermer">×</button></div>
        <div class="tk-form">
          <label>Nom</label><input class="tk-in large" data-m="nom" value="${esc(f.nom)}">
          <label>Description</label><textarea class="tk-in" data-m="description" style="font-family:var(--font-ui);font-size:12px">${esc(f.description)}</textarea>
          <label>Jour</label><div><input class="tk-in" data-m="jour" list="tk-dl-j" value="${esc(f.jour)}">${dl('tk-dl-j', ops.jours)}<div class="aide">${f.jour && jourFr(f.jour) ? esc(jourFr(f.jour)) : 'Vide : aucun jour.'}</div></div>
          <label>Heure</label><input type="time" class="tk-in" data-m="heure" value="${esc(f.heure)}">
          <label>Fréquence</label><div><input class="tk-in" data-m="frequence" list="tk-dl-f" value="${esc(f.frequence)}">${dl('tk-dl-f', ops.frequences)}</div>
          <label>Priorité</label><input type="number" class="tk-in" data-m="priorite" value="${f.priorite == null ? '' : f.priorite}" style="width:80px">
          <label>Obligatoire</label><label class="tk-ligne" style="font-size:12px"><input type="checkbox" data-m="obligatoire" ${f.obligatoire ? 'checked' : ''}> oui</label>
          <label>Photo requise</label><label class="tk-ligne" style="font-size:12px"><input type="checkbox" data-m="photo" ${f.photo ? 'checked' : ''}> oui</label>
          ${f.actif == null ? '' : `<label>Active</label><label class="tk-ligne" style="font-size:12px"><input type="checkbox" data-m="actif" ${f.actif ? 'checked' : ''}> oui (décochée : la tâche est mise en pause)</label>`}
        </div>
        ${m.err ? `<div class="tk-err" style="margin:0 16px 12px">${esc(m.err)}</div>` : ''}
        ${m.plan ? `<div class="tk-cb" style="padding-top:0"><div class="tk-k" style="margin-bottom:4px">${m.plan.fait ? 'Résultat' : 'Ce qui partira au panel'}</div>${appels(m.plan.operations, !!m.plan.fait)}</div>` : ''}
        <div class="tk-pied"><button class="tk-btn" data-a="modifApercu" ${m.envoi ? 'disabled' : ''}>Voir l’appel</button>
          ${m.plan && !m.plan.fait ? `<button class="tk-btn prim" data-a="modifEnvoyer" ${m.envoi ? 'disabled' : ''}>${m.envoi ? 'Envoi…' : 'Enregistrer dans le panel'}</button>` : ''}
          <button class="tk-btn" data-a="fermer">${m.plan && m.plan.fait ? 'Fermer' : 'Annuler'}</button></div>`;
    }
    return `<div class="tk-voile" data-a="voile"><div class="tk-modale" role="dialog" aria-modal="true">${corps}</div></div>`;
  }

  function rendre() {
    const actif = document.activeElement;
    const garde = actif && (actif.dataset.d || actif.dataset.f || actif.dataset.m) ? { k: actif.dataset.d ? 'd' : (actif.dataset.f ? 'f' : 'm'), v: actif.dataset.d || actif.dataset.f || actif.dataset.m, s: actif.selectionStart } : null;
    if (!S.cadre && S.charge) { $.innerHTML = enTete() + '<div class="tk-attente">Lecture du cadre des tâches du panel…</div>' + modale(); return; }
    $.innerHTML = enTete() + erreurs() + `<div class="tk-grille"><div>${cote()}</div><div>${S.dup ? volet() : ''}${tableau()}${creees()}</div></div>` + modale();
    if (garde) {
      const el = $.querySelector(`[data-${garde.k}="${garde.v}"]`);
      if (el) { el.focus(); try { if (garde.s != null && el.setSelectionRange) { el.setSelectionRange(garde.s, garde.s); } } catch (e) { /* champ sans curseur */ } }
    }
  }

  /* --- actions ------------------------------------------------------------ */
  function champsModif(t) {
    return { nom: t.nom, description: t.description, jour: t.jour, heure: t.heure, frequence: t.frequence,
      priorite: t.priorite, obligatoire: t.obligatoire, photo: t.photo, actif: t.actif };
  }
  function diffModif() {
    const m = S.modale; const t = tache(m.id); const avant = champsModif(t); const b = {};
    Object.keys(avant).forEach(k => {
      const v = m.f[k];
      if (k === 'priorite') { if (String(v == null ? '' : v) !== String(avant[k] == null ? '' : avant[k]) && v !== '' && v != null) { b[k] = +v; } return; }
      if (k === 'actif' && avant[k] == null) { return; }
      if (v !== avant[k]) { b[k] = v; }
    });
    return b;
  }

  async function action(a, el) {
    const id = el.dataset.id;
    switch (a) {
      case 'recharger': return charger();
      case 'cl': S.cl = id; S.filtre = ''; history.replaceState(null, '', '?checklist=' + encodeURIComponent(id)); return rendre();
      case 'coche': if (el.checked) { S.sel.add(+id); } else { S.sel.delete(+id); } return rendre();
      case 'tous': { const L = lignes(); if (el.checked) { L.forEach(x => S.sel.add(x.t.id)); } else { L.forEach(x => S.sel.delete(x.t.id)); } return rendre(); }
      case 'vider': S.sel.clear(); return rendre();
      case 'dupliquer': ouvrirDup(); rendre(); window.scrollTo({ top: 0, behavior: 'smooth' }); return;
      case 'fermerDup': S.dup = null; S.plan = null; S.res = null; return rendre();
      case 'jour': { const v = el.dataset.v; const j = S.dup.jours; const i = j.indexOf(v); if (i >= 0) { j.splice(i, 1); } else { j.push(v); } S.plan = null; return rendre(); }
      case 'autreJour': { const v = S.dup.autre.trim(); if (v && !S.dup.jours.includes(v)) { S.dup.jours.push(v); } S.dup.autre = ''; S.plan = null; return rendre(); }
      case 'dseg': S.dup[el.dataset.k] = el.dataset.v === 'null' ? null : el.dataset.v === 'true'; S.plan = null; return rendre();
      case 'apercu': {
        let corps;
        try { corps = corpsDup(); } catch (e) { S.msg = { k: 'ko', t: esc(e.message) }; return rendre(); }
        S.envoi = true; S.res = null; S.msg = null; rendre();
        try { S.plan = await api('POST', '/task/apercu', corps); } catch (e) { S.plan = null; S.msg = { k: 'ko', t: 'Aperçu impossible : ' + esc(e.message) }; }
        S.envoi = false; return rendre();
      }
      case 'creer': {
        const essai = el.dataset.essai === '1';
        const n = essai ? 1 : S.plan.aCreer;
        if (!confirm(`Créer ${n} tâche${n > 1 ? 's' : ''} dans le panel de production ?`)) { return; }
        let corps;
        try { corps = corpsDup(); } catch (e) { S.msg = { k: 'ko', t: esc(e.message) }; return rendre(); }
        S.envoi = true; rendre();
        try { S.res = await api('POST', '/task/creer', Object.assign(corps, { seulementPremiere: essai })); }
        catch (e) { S.res = null; S.msg = { k: 'ko', t: 'Création impossible : ' + esc(e.message) + (e.status === 403 ? ' — essayez avec un compte administrateur du panel.' : '') }; }
        S.envoi = false; S.plan = null;
        return charger(true);
      }
      case 'supprimer': {
        const c = ((S.cadre && S.cadre.creees) || []).find(x => x.id === +id);
        if (!confirm(`Supprimer du panel la tâche « ${c ? c.nom : '#' + id} » (#${id}) ?`)) { return; }
        try { await api('DELETE', '/task/taches/' + id); S.msg = { k: 'ok', t: 'Tâche #' + esc(id) + ' supprimée du panel.' }; S.sel.delete(+id); }
        catch (e) { S.msg = { k: 'ko', t: 'Suppression refusée : ' + esc(e.message) }; }
        return charger(true);
      }
      case 'brut': {
        S.modale = { type: 'brut', id: +id, data: null }; rendre();
        try { S.modale.data = await api('GET', '/task/taches/' + id); } catch (e) { S.modale.data = { erreur: e.message, niveaux: [], controleurs: { brut: null } }; }
        return rendre();
      }
      case 'modifier': { const t = tache(id); if (!t) { return; } S.modale = { type: 'modif', id: +id, f: champsModif(t), plan: null }; return rendre(); }
      case 'modifApercu':
      case 'modifEnvoyer': {
        const m = S.modale; const b = diffModif();
        if (!Object.keys(b).length) { m.err = 'Rien n’a changé.'; m.plan = null; return rendre(); }
        m.err = null; m.envoi = true; rendre();
        try {
          if (a === 'modifApercu') { m.plan = await api('PATCH', '/task/taches/' + m.id, Object.assign({ apercu: true }, b)); }
          else { const r = await api('PATCH', '/task/taches/' + m.id, b); m.plan = Object.assign(r, { fait: true }); m.envoi = false; return charger(true); }
        } catch (e) {
          m.err = e.message;
          if (e.data && e.data.operations) { m.plan = Object.assign(e.data, { fait: true }); }
        }
        m.envoi = false; return rendre();
      }
      case 'connexion': S.modale = { type: 'connexion', f: {} }; return rendre();
      case 'deconnexion': sessionStorage.removeItem(CLE); return charger();
      case 'seConnecter': {
        const m = S.modale;
        const pw = ($.querySelector('[data-m="password"]') || {}).value || '';
        m.envoi = true; m.err = null; rendre();
        try {
          const r = await appel('POST', '/task/connexion', { phone: m.f.phone || '', password: pw });
          sessionStorage.setItem(CLE, JSON.stringify({ jeton: r.jeton, renouvellement: r.renouvellement, expire: r.expire }));
          S.modale = null; return charger();
        } catch (e) { m.err = e.message; m.envoi = false; return rendre(); }
      }
      case 'fermer': S.modale = null; return rendre();
      case 'voile': S.modale = null; return rendre();
      default: return;
    }
  }

  $.addEventListener('click', ev => {
    const el = ev.target.closest('[data-a]');
    if (!el || !$.contains(el)) { return; }
    // Le voile ne ferme que sur un clic À CÔTÉ de la fenêtre, pas dans un champ de celle-ci.
    if (el.dataset.a === 'voile' && ev.target !== el) { return; }
    if (el.tagName !== 'INPUT') { ev.preventDefault(); }
    action(el.dataset.a, el);
  });
  $.addEventListener('input', ev => {
    const el = ev.target;
    if (el.dataset.f === 'filtre') { S.filtre = el.value; return rendre(); }
    if (el.dataset.d && S.dup) {
      const k = el.dataset.d;
      S.dup[k] = el.type === 'checkbox' ? el.checked : el.value;
      if (k === 'autre') { return; }
      if (k === 'remplacer' || k === 'par' || k === 'ajouter') { const z = $.querySelector('[data-zone="exemple"]'); if (z) { z.innerHTML = nomExemple(); } }
      if (S.plan) { S.plan = null; rendre(); }
      return;
    }
    if (el.dataset.m && S.modale && S.modale.f) {
      if (el.dataset.m === 'password') { return; }
      S.modale.f[el.dataset.m] = el.type === 'checkbox' ? el.checked : el.value;
      if (S.modale.plan && !S.modale.plan.fait) { S.modale.plan = null; rendre(); }
    }
  });
  $.addEventListener('change', ev => {
    const el = ev.target;
    if (el.dataset.d && S.dup && (el.tagName === 'SELECT' || el.type === 'checkbox')) { S.dup[el.dataset.d] = el.type === 'checkbox' ? el.checked : el.value; S.plan = null; rendre(); }
  });
  // Sur le document : après un clic dans une fenêtre, le focus retombe sur <body>, hors de #tk.
  document.addEventListener('keydown', ev => {
    if (ev.key === 'Escape' && S.modale) { S.modale = null; rendre(); }
    if (ev.key === 'Enter' && ev.target.dataset && ev.target.dataset.m === 'password') { action('seConnecter', ev.target); }
  });

  charger();
}());
