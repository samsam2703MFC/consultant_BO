/*
 * Le cadre des tâches du panel — l'application /task (demande du 07/11/2026).
 *
 * À gauche les checklists du cadre opérationnel ; au centre leurs tâches (jour, heure,
 * fréquence, obligatoire, photo requise) ; on coche des tâches et on les DUPLIQUE avec
 * d'autres réglages : « les photos du comptoir, l'après-midi, samedi et dimanche, 14:30,
 * obligatoires, dans la même checklist ». Chaque écriture passe d'abord par un aperçu qui
 * montre les appels exacts au panel ; la création s'arrête au premier refus.
 *
 * L'ASSISTANT fait le reste en quatre étapes : la checklist (nouvelle, existante ou aucune), ses
 * tâches (nouvelles ou copiées, avec des réglages communs à toutes les lignes), qui les fait et
 * qui les contrôle, puis l'aperçu et la création — la checklist et la première tâche d'abord,
 * pour vérifier, puis le reste.
 *
 * Lectures : ../api/cockpit/task/cadre, …/task/structure, …/task/taches/{id}. Écritures :
 * POST …/task/apercu et …/task/creer (duplication), …/task/assistant/apercu et …/creer
 * (assistant), PATCH et DELETE …/task/taches/{id}, DELETE …/task/checklists/{id}.
 * Contrat : docs/contrat-api.md, « /task ».
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
  // Intégrée au cockpit (?embed=1, écran Pilotage › Cadre des tâches) : sans le logo ni le titre, que
  // le cockpit porte déjà ; la checklist ouverte lui est dite, pour qu'il la garde dans son adresse.
  const EMBED = q.get('embed') === '1';
  if (EMBED) { document.documentElement.classList.add('tk-emb'); }
  /** Remonter en haut de la page — dans le cockpit, c'est lui qui fait défiler le cadre. */
  function voirHaut() {
    if (EMBED && window.parent !== window) { try { window.parent.postMessage({ tkVoir: 0 }, location.origin); } catch (e) { /* cockpit absent */ } return; }
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  // Intégrée, la page prend la hauteur de son contenu : le cadre du cockpit la suit, et il ne reste qu'une
  // barre de défilement, celle du cockpit (07/11/2026 : trois barres imbriquées, « attention à l'UX »).
  if (EMBED && window.parent !== window && window.ResizeObserver) {
    let attente = 0, derniere = 0;
    new ResizeObserver(() => {
      cancelAnimationFrame(attente);
      attente = requestAnimationFrame(() => {
        const h = Math.ceil(document.getElementById('tk').getBoundingClientRect().height) + 8;
        if (Math.abs(h - derniere) < 2) { return; }
        derniere = h;
        try { window.parent.postMessage({ tkHauteur: h }, location.origin); } catch (e) { /* cockpit absent */ }
      });
    }).observe(document.getElementById('tk'));
  }
  function adresse(cl) {
    const a = new URLSearchParams();
    if (EMBED) { a.set('embed', '1'); }
    if (S.vue === 'planning') { a.set('vue', 'planning'); } else if (cl) { a.set('checklist', cl); }
    try { history.replaceState(null, '', '?' + a.toString()); } catch (e) { /* l'adresse reste l'ancienne */ }
    if (EMBED && window.parent !== window) { try { window.parent.postMessage({ tkChecklist: cl }, location.origin); } catch (e) { /* cockpit absent */ } }
  }

  const S = {
    cadre: null, err: null, charge: true,
    cl: q.get('checklist') || null,        // id de checklist, 'tout' ou 'hors'
    filtre: '', sel: new Set(),
    dup: null, plan: null, res: null, envoi: false,
    modale: null, msg: null,
    vue: q.get('vue') === 'planning' ? 'planning' : 'cadre',   // onglet : Cadre des tâches · Planning des tâches
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
        adresse(S.cl);
      }
      // « ?sop=ID » (Formation › la fiche d'une SOP › « Créer les contrôles de ce standard ») : l'assistant
      // s'ouvre avec la proposition du standard, une seule fois.
      const sop = q.get('sop');
      if (sop && !S.sopOuvert && /^[\w-]{1,32}$/.test(sop)) { S.sopOuvert = true; S.charge = false; return wzDepuisSop(sop); }
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
    // Le compte, en une ligne discrète : on n'en change qu'en cas de refus du panel.
    const compte = a
      ? `Compte administrateur du panel, jusqu’à ${new Date(a.expire * 1000).toLocaleTimeString('fr-BE', { hour: '2-digit', minute: '2-digit' })} · <button class="tk-lien" data-a="deconnexion">revenir au compte du cockpit</button>`
      : `Compte du cockpit${S.cadre && S.cadre.api && !S.cadre.api.configure ? ' (non configuré)' : ''} · <button class="tk-lien" data-a="connexion">compte administrateur…</button>`;
    const outils = `<span class="tk-compte">${compte}</span><span class="sp"></span>
      <button class="tk-btn ghost" data-a="recharger" ${S.charge ? 'disabled' : ''} title="Relire les checklists et les tâches du panel">${S.charge ? 'Lecture…' : '↻ Relire'}</button>
      ${EMBED ? `<a class="tk-btn ghost" href="./${/^\d+$/.test(String(S.cl || '')) ? '?checklist=' + S.cl : ''}" target="_blank" rel="noopener" title="La même page, seule, dans un onglet">Ouvrir seul ↗</a>` : ''}
      ${S.wz ? '' : '<button class="tk-btn prim" data-a="wzOuvrir" data-mode="nouvelle">＋ Assistant : checklist et tâches</button>'}`;
    if (EMBED) { return `<div class="tk-hd">${outils}</div>`; }
    return `<div class="tk-hd"><img src="../assets/img/logo.png" alt=""><div><div class="tk-titre">Cadre des tâches</div>
      <div class="tk-sous">Les checklists et les tâches que les magasins font chaque jour, telles que le panel les définit</div></div></div>
      <div class="tk-hd tk-hd2">${outils}</div>`;
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

  /** Quand la tâche revient, en français : « Tous les jours », « Chaque samedi ». */
  function quandAff(t) {
    const f = String(t.frequence || '').toUpperCase(), j = t.jour || '';
    const freq = { DAILY: 'Tous les jours', WEEKLY: 'Chaque semaine', MONTHLY: 'Chaque mois', YEARLY: 'Chaque année' };
    if (j) { const fr = jourFr(j) || j; return f === 'WEEKLY' || f === '' ? 'Chaque ' + fr : fr + ' · ' + (freq[f] || f).toLowerCase(); }
    return freq[f] || (f ? t.frequence : 'Tous les jours');
  }

  function tableau() {
    const L = lignes();
    const c = checklist(S.cl);
    const tous = L.length && L.every(x => S.sel.has(x.t.id));
    const titre = c ? c.nom : (S.cl === 'hors' ? 'Dans aucune checklist' : 'Toutes les tâches');
    const sous = c ? [c.heure && 'à ' + c.heure, c.poste, c.description].filter(Boolean).join(' · ') : '';
    const n = S.sel.size;
    // La barre de sélection n'apparaît qu'avec une sélection : sans elle, un bouton grisé ressemblait à une erreur.
    const selection = n
      ? `<div class="tk-selbar"><b>${n} tâche${n > 1 ? 's' : ''} cochée${n > 1 ? 's' : ''}</b>
          <button class="tk-btn prim" data-a="dupliquer">Dupliquer…</button>
          <button class="tk-btn" data-a="wzOuvrir" data-mode="selection">Ouvrir dans l’assistant</button>
          <button class="tk-lien" data-a="vider">tout décocher</button></div>`
      : '';
    return `<div class="tk-card"><div class="tk-ct"><div class="tk-cl-titre"><div class="tk-t15">${esc(titre)}</div>${sous ? `<div class="tk-sous">${esc(sous)}</div>` : ''}</div>
      <span class="sp"></span>
      <input class="tk-in" data-f="filtre" placeholder="Filtrer…" value="${esc(S.filtre)}" style="width:170px">
      ${c ? `<button class="tk-btn" data-a="wzOuvrir" data-mode="existante">＋ Ajouter des tâches</button>` : ''}</div>
      ${selection}
      <div class="tk-defile"><table class="tk-tab tk-taches"><thead><tr>
        <th class="c" style="width:34px"><input type="checkbox" data-a="tous" ${tous ? 'checked' : ''} aria-label="Tout cocher" title="Tout cocher"></th>
        ${c ? '<th class="n" style="width:34px">#</th>' : ''}<th>Tâche</th><th style="width:130px">Quand</th><th style="width:150px">Exigences</th><th style="width:118px"></th></tr></thead><tbody>
      ${L.length ? L.map(x => {
        const t = x.t;
        const chemin = [t.section, t.categorie, t.sousCategorie].filter(Boolean).join(' › ');
        const sops = (((S.cadre || {}).sopLiens || {})[t.id] || []).map(y => `<a class="tk-pas" href="../formation/#/sop/${encodeURIComponent(y.sopId)}" target="_blank" rel="noopener" title="${esc(y.titre)}">${esc(y.code || 'SOP')}${y.etape ? ' · ' + esc(y.etape) : ''}</a>`).join(' ');
        const exig = [t.obligatoire ? '<span class="tk-pas oui">obligatoire</span>' : '', t.photo ? '<span class="tk-pas photo">photo</span>' : '',
          t.actif === false ? '<span class="tk-pas att">en pause</span>' : ''].filter(Boolean).join(' ') || '<span class="mu">—</span>';
        return `<tr class="${S.sel.has(t.id) ? 'sel' : ''}${t.actif === false ? ' pause' : ''}">
          <td class="c"><input type="checkbox" data-a="coche" data-id="${t.id}" ${S.sel.has(t.id) ? 'checked' : ''} aria-label="Cocher ${esc(t.nom)}"></td>
          ${c ? `<td class="n mu">${x.ordre == null ? '' : x.ordre}</td>` : ''}
          <td class="nom"><span class="tk-nom">${esc(t.nom)}</span>${t.horsListe ? ' <span class="tk-pas att">absente de /tasks</span>' : ''}${sops ? ' ' + sops : ''}
            <small title="${esc(chemin)}">${esc(t.sousCategorie || chemin || '')}${t.sousCategorie || chemin ? ' · ' : ''}#${t.id}</small>${x.note ? `<small>Note : ${esc(x.note)}</small>` : ''}</td>
          <td><span class="tk-quand">${esc(quandAff(t))}</span><small>${esc(t.heure || 'sans heure')}</small></td>
          <td>${exig}</td>
          <td class="act"><button class="tk-btn mini" data-a="modifier" data-id="${t.id}">Modifier</button><button class="tk-btn mini ghost" data-a="brut" data-id="${t.id}" title="La tâche telle que le panel la rend, ses niveaux de poste et ses contrôleurs">Détails</button></td></tr>`;
      }).join('') : `<tr><td colspan="6" class="mu" style="padding:16px">${S.charge ? 'Lecture…' : (S.filtre ? 'Aucune tâche ne correspond au filtre.' : 'Aucune tâche.')}</td></tr>`}
      </tbody></table></div>
      ${L.length && !n ? '<div class="tk-astuce">Cochez des tâches pour les dupliquer avec d’autres jours, une autre heure, obligatoires ou avec photo.</div>' : ''}</div>`;
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
        ${S.plan ? `<span style="font-size:12px">${S.plan.aCreer} tâche${S.plan.aCreer > 1 ? 's' : ''} à créer${S.plan.aAjouter ? ', ' + S.plan.aAjouter + ' existante(s) à ajouter à la checklist' : ''}${S.plan.items.length - S.plan.aCreer - (S.plan.aAjouter || 0) ? ', ' + (S.plan.items.length - S.plan.aCreer - (S.plan.aAjouter || 0)) + ' existe(nt) déjà' : ''}</span>
          <span style="flex:1"></span>
          ${S.plan.aCreer + (S.plan.aAjouter || 0) ? `<button class="tk-btn" data-a="creer" data-essai="1" ${S.envoi ? 'disabled' : ''}>Créer la première seulement (essai)</button>
          <button class="tk-btn prim" data-a="creer" ${S.envoi ? 'disabled' : ''}>${S.plan.aCreer ? 'Créer les ' + S.plan.aCreer + ' tâches' : 'Ajouter à la checklist'}</button>` : ''}` : '<span class="mu" style="font-size:11.5px">L’aperçu montre chaque appel au panel avec son corps exact ; rien n’est envoyé.</span>'}
      </div>
      ${S.plan ? planTable(S.plan, false) : ''}
      ${S.res ? resultat() : ''}</div>`;
  }

  function appels(ops, faits) {
    return ops.map(o => `<div class="tk-appel"><div class="t">${faits ? (o.ok ? '<span class="ok">✓</span>' : (o.code == null ? '<span class="mu">·</span>' : '<span class="ko">✗</span>')) : ''}
      <code>${esc(o.methode)} ${esc(o.chemin)}</code><span class="mu">${esc(o.quoi)}</span>${faits && o.code != null ? `<span style="margin-left:auto" class="${o.ok ? 'ok' : 'ko'}">HTTP ${o.code || '—'}</span>` : ''}</div>
      <pre>${json(o.corps)}</pre>${faits && o.code != null ? `<pre style="border-top:1px solid var(--tk-trait);background:var(--paper)">${o.erreur ? esc(o.erreur) + '\n' : ''}${json(o.reponse)}</pre>` : ''}</div>`).join('');
  }

  function etatItem(it, faits) {
    if (faits) { return it.statut || ''; }
    return ({ creer: 'à créer', ajouter: 'existe (#' + it.existe + ') : ajoutée à la checklist', rien: 'existe déjà (#' + it.existe + ')', doublon: 'en double : ignorée' })[it.mode] || 'à créer';
  }

  function planTable(p, faits) {
    const av = (p.avertissements || []).length ? `<div class="tk-msg wa" style="margin:10px 16px 0">${p.avertissements.map(esc).join('<br>')}</div>` : '';
    return `${av}<div class="tk-defile"><table class="tk-tab tk-plan"><thead><tr><th>Tâche</th><th>Jour</th><th>Heure</th><th>Copie de</th><th>${faits ? 'Résultat' : 'État'}</th><th></th></tr></thead><tbody>
      ${p.items.map(it => {
        const st = etatItem(it, faits);
        const cl = /refus|inconnu/.test(st) ? 'non' : (/^créée$|^à créer$|ajoutée/.test(st) ? 'oui' : 'att');
        return `<tr><td>${esc(it.nom)}${it.id ? ` <small>#${it.id}</small>` : ''}</td><td>${jourAff(it.jour)}</td><td>${esc(it.heure || '—')}</td><td class="mu">${it.modeleNom ? esc(it.modeleNom) : 'nouvelle'}</td>
          <td><span class="tk-pas ${cl}">${esc(st)}</span></td>
          <td class="act">${faits && it.id ? `<button class="tk-lien ko" data-a="supprimer" data-id="${it.id}">Supprimer</button>` : ''}</td></tr>
          ${(it.operations || []).length ? `<tr><td colspan="6" class="json"><details class="tk-appels"${faits && /refus/.test(st) ? ' open' : ''}><summary>${it.operations.length} appel${it.operations.length > 1 ? 's' : ''} au panel</summary>${appels(it.operations, faits)}</details></td></tr>` : ''}`;
      }).join('')}</tbody></table></div>`;
  }

  function resultat() {
    const r = S.res;
    const droits = r.arret && /HTTP 40[13]/.test(r.arret) && !admin() ? ' Le compte du cockpit n’a peut-être pas le droit d’écrire ici : connectez-vous avec un <button class="tk-lien" data-a="connexion">compte administrateur du panel</button> puis recommencez.' : '';
    const tete = r.arret ? `<div class="tk-msg ko" style="margin:10px 16px 0">${esc(r.arret)}${droits}</div>`
      : `<div class="tk-msg ok" style="margin:10px 16px 0">${r.creees} tâche${r.creees > 1 ? 's' : ''} créée${r.creees > 1 ? 's' : ''} dans le panel.${r.items.some(i => /essai/.test(i.statut || '')) ? ' Vérifiez-la dans le panel ou l’app employés, puis créez les autres : celles déjà créées ne seront pas recréées.' : ''}</div>`;
    return `<div class="tk-ct" style="border-top:1px solid var(--tk-trait)"><span class="tk-k">Résultat</span></div>${tete}${planTable(r, true)}`;
  }

  /**
   * Ce qui a été créé depuis cet écran — les seules choses qu'on peut y supprimer. Replié par défaut en une
   * ligne (07/11/2026 : 110 tâches listées sous chaque checklist faisaient une page de 5 000 px) ; ouvert, il
   * ne montre que ce qui touche la checklist affichée, par paquets de 40, « toute la liste » à la demande.
   */
  function creees() {
    const L = (S.cadre && S.cadre.creees) || [];
    const C = (S.cadre && S.cadre.checklistsCreees) || [];
    if (!L.length && !C.length) { return ''; }
    const c = checklist(S.cl);
    const ici = c && !S.creesTout;
    const Lv = ici ? L.filter(x => x.checklistId === c.id) : L;
    const Cv = ici ? C.filter(x => x.id === c.id) : C;
    const max = S.creesMax || 40;
    const pl = (n, m) => n + ' ' + m + (n > 1 ? 's' : '');
    const resume = [L.length ? pl(L.length, 'tâche') : '', C.length ? pl(C.length, 'checklist') : ''].filter(Boolean).join(' · ')
      + (c && (Lv.length || Cv.length) && !S.creesTout ? ` — dont ${pl(Lv.length + Cv.length, 'élément')} pour cette checklist` : '');
    const tete = `<div class="tk-ct"><span class="tk-k">Créé depuis cet écran</span><span class="mu" style="font-size:11.5px">${esc(resume)}</span><span class="sp"></span>
      ${S.creesOuvert && c ? `<button class="tk-lien" data-a="creesTout">${S.creesTout ? 'cette checklist seulement' : 'toute la liste'}</button>` : ''}
      <button class="tk-btn mini" data-a="creesOuvrir">${S.creesOuvert ? 'Masquer' : 'Afficher'}</button></div>`;
    if (!S.creesOuvert) { return `<div class="tk-card">${tete}</div>`; }
    if (!Lv.length && !Cv.length) { return `<div class="tk-card">${tete}<div class="tk-astuce">Rien n’a été créé ici pour cette checklist.</div></div>`; }
    const lignesC = Cv.map(x => `<tr><td><span class="tk-pas">checklist</span></td><td class="nom"><span class="tk-nom">${esc(x.nom)}</span><small>#${x.id} · ${esc(x.le)}${x.compte === 'admin' ? ' · admin' : ''}</small></td><td></td>
        <td class="act"><button class="tk-btn mini danger" data-a="supprimerCl" data-id="${x.id}">Supprimer</button></td></tr>`).join('');
    const lignesT = Lv.slice(0, max).map(x => `<tr><td><span class="tk-pas">tâche</span></td><td class="nom"><span class="tk-nom">${esc(x.nom)}</span>
        <small>#${x.id} · ${esc(x.le)}${ici ? '' : ' · ' + esc((checklist(x.checklistId) || {}).nom || (x.checklistId ? '#' + x.checklistId : 'sans checklist'))}${x.compte === 'admin' ? ' · admin' : ''}</small></td>
        <td><span class="tk-quand">${esc(x.jour ? (jourFr(x.jour) ? 'Chaque ' + jourFr(x.jour) : x.jour) : 'Tous les jours')}</span><small>${esc(x.heure || 'sans heure')}</small></td>
        <td class="act"><button class="tk-btn mini danger" data-a="supprimer" data-id="${x.id}">Supprimer</button></td></tr>`).join('');
    const plus = Lv.length > max ? `<div class="tk-astuce"><button class="tk-lien" data-a="creesPlus">Afficher ${Math.min(40, Lv.length - max)} de plus</button> · ${Lv.length - max} restante${Lv.length - max > 1 ? 's' : ''}</div>` : '';
    return `<div class="tk-card">${tete}<div class="tk-defile"><table class="tk-tab tk-taches"><tbody>${lignesC}${lignesT}</tbody></table></div>${plus}</div>`;
  }

  /* --- l'assistant : checklist et tâches, en quatre étapes ---------------- */
  const ETAPES = ['Checklist', 'Tâches', 'Qui', 'Vérifier et créer'];
  let uid = 0;
  const plusCourant = (L, f) => { const n = {}; L.forEach(x => { const v = f(x); if (v != null && v !== '') { n[v] = (n[v] || 0) + 1; } }); return Object.keys(n).sort((a, b) => n[b] - n[a])[0]; };
  const freqDefaut = () => { const o = ((S.cadre && S.cadre.options) || {}).frequences || []; const v = o.find(x => x.valeur !== ''); return v ? v.valeur : ''; };
  const prioDefaut = () => plusCourant(taches(), t => t.priorite) || '';

  function wzLigneVide() {
    const L = S.wz.lignes; const p = L[L.length - 1];
    return { k: ++uid, modeleId: null, nom: '', description: '', sousCategorieId: p ? p.sousCategorieId : '', jours: p ? p.jours.slice() : [], unParJour: true,
      heure: p ? p.heure : (S.wz.cl.mode === 'nouvelle' ? S.wz.cl.heure : ((checklist(S.wz.cl.id) || {}).heure || '')),
      frequence: p ? p.frequence : freqDefaut(), obligatoire: true, photo: p ? p.photo : false, priorite: p ? p.priorite : prioDefaut(),
      garderNiveaux: true, garderControleurs: true, ouvert: false };
  }
  function wzLigneDe(t) {
    return { k: ++uid, modeleId: t.id, nom: t.nom, description: t.description || '', sousCategorieId: String((t.brut || {}).subcategory_id || ''),
      jours: t.jour ? [t.jour] : [], unParJour: true, heure: t.heure || '', frequence: t.frequence || '', obligatoire: !!t.obligatoire, photo: !!t.photo,
      priorite: t.priorite == null ? '' : String(t.priorite), garderNiveaux: true, garderControleurs: true, ouvert: false };
  }

  async function wzOuvrir(mode) {
    const c = checklist(S.cl);
    S.wz = { etape: 1, cl: { mode: 'nouvelle', id: c ? String(c.id) : '', nom: '', description: '', posteId: '', heure: '', modeleId: '', extra: '' },
      lignes: [], commun: { jours: [], heure: '', frequence: '__', obligatoire: null, photo: null, sousCategorieId: '' }, niveaux: [], controleursDe: '', extra: '',
      choix: null, plan: null, res: null, envoi: false, err: null, struct: S.struct || null };
    if (mode === 'existante' && c) { S.wz.cl.mode = 'existante'; S.wz.etape = 2; S.wz.lignes.push(wzLigneVide()); }
    if (mode === 'selection') {
      S.wz.cl.mode = c ? 'existante' : 'nouvelle';
      Array.from(S.sel).map(tache).filter(Boolean).forEach(t => S.wz.lignes.push(wzLigneDe(t)));
    }
    S.dup = null;
    rendre(); voirHaut();
    if (!S.struct) {
      try { S.struct = await api('GET', '/task/structure'); } catch (e) { S.struct = { sousCategories: [], postes: [], erreurs: { structure: e.message } }; }
      if (S.wz) { S.wz.struct = S.struct; rendre(); }
    }
  }

  /** L'assistant ouvert sur la proposition d'un standard (GET /task/sop/{id}/proposition). */
  async function wzDepuisSop(id) {
    await wzOuvrir('nouvelle');
    const w = S.wz;
    w.envoi = true; rendre();
    let p;
    try { p = await api('GET', '/task/sop/' + encodeURIComponent(id) + '/proposition'); }
    catch (e) { w.envoi = false; w.err = 'Proposition du standard illisible : ' + e.message; return rendre(); }
    w.envoi = false;
    w.sop = p.sop; w.sopAvert = p.avertissements || []; w.reference = p.reference;
    const c = p.checklist || {};
    if (c.mode === 'existante' && checklist(c.id)) { w.cl.mode = 'existante'; w.cl.id = String(c.id); }
    else { w.cl.mode = 'nouvelle'; w.cl.nom = c.nom || ''; w.cl.description = c.description || ''; w.cl.posteId = c.posteId ? String(c.posteId) : ''; w.cl.heure = c.heure || ''; }
    const sc = p.sousCategorieId ? String(p.sousCategorieId) : '';
    w.lignes = (p.lignes || []).map(l => ({ k: ++uid, modeleId: null, nom: l.nom, description: l.description || '', sousCategorieId: sc, jours: [], unParJour: true,
      heure: l.heure || '', frequence: freqDefaut(), obligatoire: true, photo: !!l.photo, priorite: prioDefaut(), garderNiveaux: true, garderControleurs: true,
      sop: { id: p.sop.id, etape: l.etape }, photoRef: l.photoRef || null, section: l.section || '' }));
    w.niveaux = (p.niveaux || []).slice();
    w.controleursDe = p.controleursDe ? String(p.controleursDe) : '';
    w.commun.sousCategorieId = sc;
    rendre();
  }
  /** La photo de référence d'une étape de SOP, servie par le module Formation. */
  const photoSop = ph => String(ph).startsWith('u/') ? API + '/formation/sop/photo/' + encodeURIComponent(String(ph).slice(2)) : '../formation/sop/photos/' + encodeURIComponent(ph);

  function wzValider(etape) {
    const w = S.wz;
    if (etape === 1) {
      if (w.cl.mode === 'nouvelle' && !w.cl.nom.trim()) { return 'Donnez un nom à la nouvelle checklist.'; }
      if (w.cl.mode === 'existante' && !w.cl.id) { return 'Choisissez la checklist.'; }
    }
    if (etape === 2) {
      if (!w.lignes.length) { return 'Ajoutez au moins une tâche.'; }
      const sc = ((w.struct || {}).sousCategories || []).length;
      for (let i = 0; i < w.lignes.length; i++) {
        const l = w.lignes[i];
        if (!l.nom.trim()) { return 'Ligne ' + (i + 1) + ' : le nom manque.'; }
        if (!l.modeleId && sc && !l.sousCategorieId) { return 'Ligne ' + (i + 1) + ' (« ' + l.nom + ' ») : choisissez la sous-catégorie.'; }
        if (l.heure && !/^\d{2}:\d{2}$/.test(l.heure)) { return 'Ligne ' + (i + 1) + ' : heure invalide.'; }
      }
    }
    return null;
  }

  function jsonOuVide(txt, lib) {
    if (!String(txt || '').trim()) { return {}; }
    let v;
    try { v = JSON.parse(txt); } catch (e) { throw new Error(lib + ' : JSON invalide (' + e.message + ')'); }
    if (!v || typeof v !== 'object' || Array.isArray(v)) { throw new Error(lib + ' : un objet JSON est attendu, par exemple {"priority": 2}'); }
    return v;
  }

  function wzSpec() {
    const w = S.wz; const c = w.cl;
    const cl = c.mode === 'nouvelle' ? { mode: 'nouvelle', nom: c.nom.trim(), description: c.description, posteId: +c.posteId || 0, heure: c.heure || '', modeleId: +c.modeleId || 0, extra: jsonOuVide(c.extra, 'Champs en plus de la checklist') }
      : (c.mode === 'existante' ? { mode: 'existante', id: +c.id } : { mode: 'aucune' });
    return { checklist: cl, niveaux: w.niveaux, controleursDe: +w.controleursDe || null, extra: jsonOuVide(w.extra, 'Champs en plus des tâches'),
      sopId: w.sop ? w.sop.id : null,
      lignes: w.lignes.map(l => ({ modeleId: l.modeleId || null, nom: l.nom.trim(), description: l.description, sousCategorieId: +l.sousCategorieId || 0,
        jours: l.jours, unParJour: l.unParJour, heure: l.heure || '', frequence: l.frequence === '' && !l.modeleId ? null : l.frequence,
        obligatoire: l.obligatoire, photo: l.photo, priorite: l.priorite === '' || l.priorite == null ? null : +l.priorite,
        garderNiveaux: l.garderNiveaux, garderControleurs: l.garderControleurs, sop: l.sop || null })) };
  }

  async function wzApercu() {
    const w = S.wz;
    let spec;
    try { spec = wzSpec(); } catch (e) { w.err = e.message; return rendre(); }
    w.envoi = true; w.err = null; w.plan = null; w.res = null; rendre();
    try { w.plan = await api('POST', '/task/assistant/apercu', spec); } catch (e) { w.err = 'Aperçu impossible : ' + e.message; }
    w.envoi = false; rendre();
  }

  function chipsJours(sel, attrs) {
    const ops = ((S.cadre && S.cadre.options) || {}).jours || [];
    const vals = ops.filter(o => o.valeur !== '');
    const perso = sel.filter(v => !vals.some(o => o.valeur === v));
    const chip = (v, nb) => { const fr = jourFr(v); const ex = exemples('day_of_week', v);
      return `<button class="tk-chip${sel.includes(v) ? ' on' : ''}" ${attrs} data-v="${esc(v)}" title="${esc(ex.length ? 'Par exemple : ' + ex.join(' ; ') : '')}">${esc(fr || v)}${fr ? `<small>${esc(v)}</small>` : ''}${nb != null ? `<small>${nb}</small>` : ''}</button>`; };
    return vals.map(o => chip(o.valeur, o.nb)).join('') + perso.map(v => chip(v, null)).join('');
  }
  function optionsFreq(v, avecModele) {
    const ops = ((S.cadre && S.cadre.options) || {}).frequences || [];
    return (avecModele ? `<option value="__"${v === '__' ? ' selected' : ''}>— ne pas changer —</option>` : '')
      + ops.map(o => `<option value="${esc(o.valeur)}"${v === o.valeur ? ' selected' : ''}>${esc(o.valeur === '' ? '(aucune)' : o.valeur)} · ${o.nb}</option>`).join('')
      + (v && v !== '__' && !ops.some(o => o.valeur === v) ? `<option value="${esc(v)}" selected>${esc(v)}</option>` : '');
  }
  function optionsSousCat(v) {
    const L = (S.wz.struct || {}).sousCategories || [];
    const g = {};
    L.forEach(x => { const k = [x.section, x.categorie].filter(Boolean).join(' › ') || 'Sans catégorie'; (g[k] = g[k] || []).push(x); });
    return `<option value="">${L.length ? '— sous-catégorie —' : (S.wz.struct ? 'aucune sous-catégorie lue' : 'lecture…')}</option>`
      + Object.keys(g).map(k => `<optgroup label="${esc(k)}">${g[k].map(x => `<option value="${x.id}"${String(v) === String(x.id) ? ' selected' : ''}>${esc(x.nom)}${x.nb ? ' · ' + x.nb : ''}</option>`).join('')}</optgroup>`).join('');
  }
  const bascule = (on, attrs, lib) => `<label class="tk-bas"><input type="checkbox" ${attrs} ${on ? 'checked' : ''}> ${lib}</label>`;
  const segW = (k, v) => `<span class="tk-seg">${[['null', 'ne pas changer'], ['true', 'oui'], ['false', 'non']].map(([val, lib]) => `<button data-a="wzCommunSeg" data-k="${k}" data-v="${val}" class="${String(v) === val ? 'on' : ''}">${lib}</button>`).join('')}</span>`;

  function wzEtape1() {
    const c = S.wz.cl;
    const carte = (m, t, d) => `<button class="tk-wz-mode${c.mode === m ? ' on' : ''}" data-a="wzMode" data-v="${m}"><b>${t}</b><span>${d}</span></button>`;
    let corps = '';
    if (c.mode === 'nouvelle') {
      const postes = (S.wz.struct || {}).postes || [];
      corps = `<div class="tk-form">
        <label>Nom <span class="ko">*</span></label><div><input class="tk-in large" data-wc="nom" value="${esc(c.nom)}" placeholder="CQ-03 — Contrôle qualité de l’après-midi"><div class="aide">Comme les autres : un code, puis ce qu’elle contrôle.</div></div>
        <label>Description</label><input class="tk-in large" data-wc="description" value="${esc(c.description)}" placeholder="Ce que l’équipe doit faire, en une phrase">
        <label>Poste de travail</label><select class="tk-in" data-wc="posteId"><option value="">${postes.length || !S.wz.struct ? 'aucun' : 'aucun poste lu'}</option>${postes.map(p => `<option value="${p.id}"${String(c.posteId) === String(p.id) ? ' selected' : ''}>${esc(p.nom)}</option>`).join('')}</select>
        <label>Heure</label><div><input type="time" class="tk-in" data-wc="heure" value="${esc(c.heure)}"><div class="aide">L’heure de la checklist ; chaque tâche garde la sienne (proposée par défaut à l’étape suivante).</div></div>
        <label>Avancé</label><div><details${c.modeleId || c.extra ? ' open' : ''}><summary class="tk-lien" style="text-decoration:none">Forme des champs et champs en plus</summary>
          <div class="tk-ligne" style="margin-top:8px;font-size:12px">Reprendre la forme de <select class="tk-in" data-wc="modeleId"><option value="">la première checklist</option>${checklists().map(x => `<option value="${x.id}"${String(c.modeleId) === String(x.id) ? ' selected' : ''}>${esc(x.nom)}</option>`).join('')}</select></div>
          <textarea class="tk-in" data-wc="extra" placeholder='Champs en plus, envoyés tels quels : {"is_mandatory": 1}' style="margin-top:6px">${esc(c.extra)}</textarea></details></div></div>`;
    } else if (c.mode === 'existante') {
      const x = checklist(c.id);
      corps = `<div class="tk-form"><label>Checklist</label><div><select class="tk-in" data-wc="id"><option value="">— choisir —</option>${checklists().map(y => `<option value="${y.id}"${String(c.id) === String(y.id) ? ' selected' : ''}>${esc(y.nom)}</option>`).join('')}</select>
        ${x ? `<div class="aide">${[x.heure && 'à ' + x.heure, x.poste, x.taches.length + ' tâche' + (x.taches.length > 1 ? 's' : '')].filter(Boolean).map(esc).join(' · ')}. Les nouvelles tâches se placent après la dernière.</div>` : ''}</div></div>`;
    } else {
      corps = `<p class="tk-note" style="padding:0 16px">Les tâches seront créées sans checklist : elles n’apparaîtront dans aucune liste du jour tant qu’on ne les y range pas.</p>`;
    }
    return `<div class="tk-wz-modes">${carte('nouvelle', 'Nouvelle checklist', 'Créer la checklist, puis ses tâches')}${carte('existante', 'Checklist existante', 'Y ajouter des tâches')}${carte('aucune', 'Sans checklist', 'Des tâches seules')}</div>${corps}`;
  }

  function wzEtape2() {
    const w = S.wz; const cm = w.commun;
    const lignes = w.lignes.map((l, i) => {
      const m = l.modeleId ? tache(l.modeleId) : null;
      const a = `data-i="${i}"`;
      return `<div class="tk-wl"><div class="tk-wl-l1"><span class="tk-wl-n">${i + 1}</span>
        <input class="tk-in" data-wl="nom" ${a} value="${esc(l.nom)}" placeholder="Nom de la tâche" style="flex:1;min-width:220px">
        <select class="tk-in" data-wl="sousCategorieId" ${a} style="max-width:260px">${optionsSousCat(l.sousCategorieId)}</select>
        <button class="tk-lien" data-a="wzDupLigne" ${a}>dupliquer</button><button class="tk-lien ko" data-a="wzSupLigne" ${a}>retirer</button></div>
        <div class="tk-wl-l2"><span class="tk-ligne">${chipsJours(l.jours, `data-a="wzJour" ${a}`)}</span>
          ${l.jours.length > 1 ? bascule(l.unParJour, `data-wl="unParJour" ${a}`, 'une tâche par jour') : ''}
          ${l.jours.length ? '' : '<span class="mu" style="font-size:11px">aucun jour choisi</span>'}</div>
        <div class="tk-wl-l2"><label class="tk-ligne">Heure <input type="time" class="tk-in" data-wl="heure" ${a} value="${esc(l.heure)}"></label>
          <label class="tk-ligne">Fréquence <select class="tk-in" data-wl="frequence" ${a}>${optionsFreq(l.frequence, false)}</select></label>
          ${bascule(l.obligatoire, `data-wl="obligatoire" ${a}`, 'obligatoire')}
          ${bascule(l.photo, `data-wl="photo" ${a}`, 'photo requise')}
          <label class="tk-ligne">Priorité <input type="number" class="tk-in" data-wl="priorite" ${a} value="${esc(l.priorite)}" style="width:64px"></label></div>
        <div class="tk-wl-l2"><input class="tk-in" data-wl="description" ${a} value="${esc(l.description)}" placeholder="Description (facultatif) : ce qu’on attend sur la photo, par exemple" style="flex:1;min-width:260px"></div>
        ${l.sop ? `<div class="tk-wl-l2 mu" style="font-size:11px">${l.photoRef ? `<img class="tk-wl-ph" src="${esc(photoSop(l.photoRef))}" alt="" title="La photo de référence de l’étape">` : ''}<span class="tk-pas">${esc((w.sop && w.sop.code) || 'SOP')}${l.sop.etape ? ' · étape ' + esc(l.sop.etape) : ''}</span>${l.section ? ' ' + esc(l.section) : ''}</div>` : ''}
        ${m ? `<div class="tk-wl-l2 mu" style="font-size:11px">copie de « ${esc(m.nom)} » (#${m.id}) — ${bascule(l.garderNiveaux, `data-wl="garderNiveaux" ${a}`, 'garder ses niveaux de poste')} ${bascule(l.garderControleurs, `data-wl="garderControleurs" ${a}`, 'garder ses contrôleurs')}</div>` : ''}
      </div>`;
    }).join('');
    const choix = w.choix ? (() => {
      const f = sansAccent(w.choix.filtre);
      const L = taches().filter(t => !f || sansAccent([t.nom, t.section, t.categorie, t.sousCategorie].join(' ')).includes(f)).sort((x, y) => x.nom.localeCompare(y.nom, 'fr'));
      return `<div class="tk-card" style="margin:12px 16px"><div class="tk-ct"><span class="tk-k">Partir de tâches existantes</span>
        <input class="tk-in" data-wf="filtre" value="${esc(w.choix.filtre)}" placeholder="Filtrer…" style="width:200px">
        <span style="flex:1"></span><button class="tk-btn prim" data-a="wzChoixAjouter" ${w.choix.sel.length ? '' : 'disabled'}>Ajouter ${w.choix.sel.length || ''} ligne${w.choix.sel.length > 1 ? 's' : ''}</button><button class="tk-x" data-a="wzChoixFermer">×</button></div>
        <div style="max-height:300px;overflow:auto">${L.map(t => `<label class="tk-choix"><input type="checkbox" data-a="wzChoixCoche" data-id="${t.id}" ${w.choix.sel.includes(t.id) ? 'checked' : ''}> <span>${esc(t.nom)}<small>${esc([t.jour && (jourFr(t.jour) || t.jour), t.heure, t.photo && 'photo', [t.section, t.categorie, t.sousCategorie].filter(Boolean).join(' › ')].filter(Boolean).join(' · '))}</small></span></label>`).join('') || '<div class="tk-note" style="padding:10px 16px">Aucune tâche.</div>'}</div></div>`;
    })() : '';
    return `<div class="tk-wz-commun"><span class="tk-k">Pour toutes les lignes</span>
        <span class="tk-ligne">${chipsJours(cm.jours, 'data-a="wzCommunJour"')}</span>
        <label class="tk-ligne">Heure <input type="time" class="tk-in" data-wcm="heure" value="${esc(cm.heure)}"></label>
        <label class="tk-ligne">Fréquence <select class="tk-in" data-wcm="frequence">${optionsFreq(cm.frequence, true)}</select></label>
        <label class="tk-ligne">Sous-catégorie <select class="tk-in" data-wcm="sousCategorieId" style="max-width:240px">${optionsSousCat(cm.sousCategorieId).replace('— sous-catégorie —', '— ne pas changer —')}</select></label>
        <span class="tk-ligne">Obligatoire ${segW('obligatoire', cm.obligatoire)}</span><span class="tk-ligne">Photo ${segW('photo', cm.photo)}</span>
        <button class="tk-btn" data-a="wzAppliquer">Appliquer à toutes les lignes</button>
        <div class="aide" style="width:100%">Ce qui n’est pas choisi ici ne change pas. Les jours sont ceux que le panel emploie déjà (survolez pour des exemples).</div></div>
      <div style="padding:4px 16px 12px">${lignes || '<p class="tk-note">Aucune tâche pour l’instant.</p>'}
        <div class="tk-ligne" style="margin-top:10px"><button class="tk-btn" data-a="wzAjouterLigne">+ Nouvelle tâche</button><button class="tk-btn" data-a="wzChoixOuvrir">+ Partir de tâches existantes…</button></div></div>${choix}`;
  }

  function wzEtape3() {
    const w = S.wz;
    const nivs = (S.cadre && S.cadre.niveaux) || [];
    const g = {};
    nivs.forEach(n => { (g[n.poste || 'Autres'] = g[n.poste || 'Autres'] || []).push(n); });
    const copies = w.lignes.filter(l => l.modeleId);
    return `<div class="tk-form">
      <label>Qui fait ces tâches ?</label><div>
        ${Object.keys(g).map(p => `<div style="margin-bottom:8px"><div class="tk-k" style="margin-bottom:4px">${esc(p)}</div><div class="tk-ligne">${g[p].map(n => bascule(w.niveaux.includes(n.id), `data-a="wzNiveau" data-id="${n.id}"`, esc(n.nom || ('Niveau #' + n.id)))).join('')}</div></div>`).join('') || '<span class="mu">Aucun niveau de poste lu dans le panel.</span>'}
        <div class="aide">Les niveaux de poste cochés reçoivent toutes les tâches créées : c’est ce qui les fait apparaître dans l’app des employés.${copies.length ? ' Les copies gardent en plus les niveaux de leur modèle si la case « garder ses niveaux de poste » est cochée à l’étape 2.' : ''}</div></div>
      <label>Qui les contrôle ?</label><div><select class="tk-in" data-wg="controleursDe"><option value="">personne de plus</option>
        ${taches().slice().sort((x, y) => x.nom.localeCompare(y.nom, 'fr')).map(t => `<option value="${t.id}"${String(w.controleursDe) === String(t.id) ? ' selected' : ''}>les mêmes que « ${esc(t.nom)} »</option>`).join('')}</select>
        <div class="aide">Les contrôleurs de la tâche choisie sont recopiés sur chaque tâche créée.${copies.length ? ' Les copies gardent aussi ceux de leur modèle si demandé.' : ''}</div></div>
      <label>Avancé</label><div><details${w.extra ? ' open' : ''}><summary class="tk-lien" style="text-decoration:none">Champs en plus pour chaque tâche créée (JSON)</summary>
        <textarea class="tk-in" data-wg="extra" placeholder='{"priority": 1}' style="margin-top:6px">${esc(w.extra)}</textarea></details></div></div>`;
  }

  function wzEtape4() {
    const w = S.wz;
    if (w.res) { return wzResultat(); }
    if (!w.plan) { return `<p class="tk-note" style="padding:12px 16px">${w.envoi ? 'Préparation de l’aperçu…' : 'L’aperçu n’est pas prêt.'}</p>`; }
    const p = w.plan;
    const op = p.checklist && p.checklist.operation;
    const resume = [op ? 'la checklist « ' + p.checklist.nom + ' »' : '', p.aCreer ? p.aCreer + ' tâche' + (p.aCreer > 1 ? 's' : '') + ' à créer' : '', p.aAjouter ? p.aAjouter + ' tâche' + (p.aAjouter > 1 ? 's' : '') + ' existante' + (p.aAjouter > 1 ? 's' : '') + ' à ajouter à la checklist' : ''].filter(Boolean).join(', ');
    return `<div class="tk-cb"><div style="font-size:13px">${resume ? 'À faire : ' + esc(resume) + '.' : 'Rien à faire : tout existe déjà.'}
      ${p.checklist && p.checklist.mode !== 'aucune' && !op ? ' Checklist : « ' + esc(p.checklist.nom) + ' ».' : ''} <span class="mu">Rattachements par ${esc('/' + p.routeNiveaux + '/{niveau}/tasks')}.</span></div>
      ${op ? `<div class="tk-k" style="margin:12px 0 0">La checklist</div>${appels([op], false)}` : ''}</div>
      ${planTable(p, false)}`;
  }

  function wzResultat() {
    const r = S.wz.res;
    const op = r.checklist && r.checklist.operation;
    const droits = r.arret && /HTTP 40[13]/.test(r.arret) && !admin() ? ' Le compte du cockpit n’a peut-être pas le droit d’écrire ici : connectez-vous avec un <button class="tk-lien" data-a="connexion">compte administrateur du panel</button> puis recommencez.' : '';
    const fait = [op && op.ok ? 'checklist « ' + r.checklist.nom + ' » créée (#' + r.checklist.id + ')' : '', r.creees ? r.creees + ' tâche' + (r.creees > 1 ? 's' : '') + ' créée' + (r.creees > 1 ? 's' : '') : '', r.ajoutees ? r.ajoutees + ' ajoutée' + (r.ajoutees > 1 ? 's' : '') + ' à la checklist' : ''].filter(Boolean).join(', ');
    const essai = r.items.some(i => /essai/.test(i.statut || ''));
    return `${r.arret ? `<div class="tk-msg ko" style="margin:12px 16px 0">${esc(r.arret)}${droits}</div>` : ''}
      ${fait ? `<div class="tk-msg ok" style="margin:12px 16px 0">${esc(fait)}.${essai ? ' Vérifiez dans le panel ou l’app employés, puis créez le reste : ce qui existe déjà n’est pas recréé.' : ''}</div>` : ''}
      ${op ? `<div class="tk-cb"><div class="tk-k">La checklist</div>${appels([op], true)}${op.ok && r.checklist.id ? `<div style="margin-top:6px"><button class="tk-lien ko" data-a="supprimerCl" data-id="${r.checklist.id}">Supprimer cette checklist</button></div>` : ''}</div>` : ''}
      ${planTable(r, true)}`;
  }

  function assistant() {
    const w = S.wz;
    const pied = w.etape < 4
      ? `<button class="tk-btn" data-a="wzPrecedent" ${w.etape === 1 ? 'disabled' : ''}>Précédent</button><span style="flex:1"></span><button class="tk-btn prim" data-a="wzSuivant">${w.etape === 3 ? 'Voir l’aperçu' : 'Suivant'}</button>`
      : (w.res ? `<span style="flex:1"></span>${w.res.items.some(i => /essai/.test(i.statut || '')) ? '<button class="tk-btn" data-a="wzReprendre">Créer le reste…</button>' : ''}<button class="tk-btn prim" data-a="wzFin">Terminer</button>`
        : `<button class="tk-btn" data-a="wzPrecedent">Précédent</button><span style="flex:1"></span>
           ${w.plan && (w.plan.aCreer + w.plan.aAjouter) > 0 ? `${(w.plan.aCreer + w.plan.aAjouter) > 1 || (w.plan.checklist && w.plan.checklist.operation) ? `<button class="tk-btn" data-a="wzCreer" data-essai="1" ${w.envoi ? 'disabled' : ''}>${w.plan.checklist && w.plan.checklist.operation ? 'Créer la checklist et la première tâche (essai)' : 'Créer la première seulement (essai)'}</button>` : ''}
           <button class="tk-btn prim" data-a="wzCreer" ${w.envoi ? 'disabled' : ''}>${w.envoi ? 'Envoi…' : 'Tout créer dans le panel'}</button>` : ''}`);
    return `<div class="tk-card tk-dup"><div class="tk-ct"><span class="tk-k">Assistant</span>
        <div class="tk-wz-etapes">${ETAPES.map((e, i) => `<button class="${w.etape === i + 1 ? 'on' : (i + 1 < w.etape ? 'fait' : '')}" data-a="wzEtape" data-e="${i + 1}" ${i + 1 < w.etape && !w.res ? '' : 'disabled'}><b>${i + 1}</b>${e}</button>`).join('')}</div>
        <button class="tk-x" data-a="wzFermer" aria-label="Fermer">×</button></div>
      ${w.sop ? `<div class="tk-msg ok" style="margin:12px 16px 0">D’après le standard <b>${esc(w.sop.code)}</b> — ${esc(w.sop.titre)} : ${w.lignes.filter(l => l.sop).length} étape(s) critique(s) en tâches de contrôle.${w.reference ? ` Sous-catégorie, niveaux de poste et contrôleurs repris de « ${esc(w.reference.nom || '#' + w.reference.id)} »${w.reference.checklist ? ' (' + esc(w.reference.checklist) + ')' : ''}.` : ''}${(w.sopAvert || []).length ? '<br>' + w.sopAvert.map(esc).join('<br>') : ''}</div>` : ''}
      ${w.envoi && !w.lignes.length && w.etape === 1 ? '<p class="tk-note" style="padding:12px 16px 0">Lecture du standard…</p>' : ''}
      ${w.err ? `<div class="tk-err" style="margin:12px 16px 0">${esc(w.err)}</div>` : ''}
      ${S.struct && S.struct.erreurs && Object.keys(S.struct.erreurs).length && w.etape <= 2 ? `<div class="tk-msg wa" style="margin:12px 16px 0">Structure du panel lue en partie : ${Object.entries(S.struct.erreurs).map(([k, v]) => esc(k + ' — ' + v)).join(' ; ')}</div>` : ''}
      ${[wzEtape1, wzEtape2, wzEtape3, wzEtape4][w.etape - 1]()}
      <div class="tk-pied">${pied}</div></div>`;
  }

  /* --- planning : charge par poste et par jour ---------------------------- */
  // Le poste d'une tâche = celui de sa (première) checklist. Une tâche quotidienne compte chaque
  // jour ; une tâche hebdomadaire seulement le jour que le panel lui donne. Rien n'est saisi ici.
  const PL_JOURS = [['MONDAY', 'Lun', 'lundi'], ['TUESDAY', 'Mar', 'mardi'], ['WEDNESDAY', 'Mer', 'mercredi'], ['THURSDAY', 'Jeu', 'jeudi'],
    ['FRIDAY', 'Ven', 'vendredi'], ['SATURDAY', 'Sam', 'samedi'], ['SUNDAY', 'Dim', 'dimanche']];
  const PL_ORDRE = ['vente', 'production boulangerie', 'production patisserie', 'production traiteur', 'nettoyage', 'logistique', 'administartif', 'administratif'];
  const posteAff = p => ({ Administartif: 'Administratif', 'Production Patisserie': 'Production Pâtisserie' })[p] || p;
  const CAM = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>';
  function planDonnees() {
    const cl = new Map();
    checklists().forEach(c => c.taches.forEach(l => { if (!cl.has(l.taskId)) { cl.set(l.taskId, c); } }));
    const L = taches().filter(t => t.actif !== false).map(t => {
      const c = cl.get(t.id); const f = String(t.frequence || '').toUpperCase(); const j = String(t.jour || '').toUpperCase();
      const js = !f || f === 'DAILY' ? PL_JOURS.map(x => x[0]) : PL_JOURS.some(x => x[0] === j) ? [j] : [];
      return { t, js, poste: (c && c.poste) || 'Sans poste', cl: c ? c.nom : 'Dans aucune checklist' };
    });
    const rang = p => { const i = PL_ORDRE.indexOf(sansAccent(p)); return i < 0 ? (p === 'Sans poste' ? 99 : 50) : i; };
    const postes = [...new Set(L.map(x => x.poste))].sort((a, b) => rang(a) - rang(b) || a.localeCompare(b, 'fr'));
    return { L, postes };
  }
  const codeCl = n => String(n).split(/ · | — | - /)[0];
  function planning() {
    const { L, postes } = planDonnees();
    const cel = (p, j) => L.filter(x => x.poste === p && x.js.includes(j));
    const mx = Math.max(1, ...postes.flatMap(p => PL_JOURS.map(([j]) => cel(p, j).length)));
    const sansJour = L.filter(x => !x.js.length).length;
    const lignesHtml = postes.map(p => {
      const cases = PL_JOURS.map(([j, c]) => {
        const T = cel(p, j); const n = T.length; const ph = T.filter(x => x.t.photo).length; const r = n / mx;
        if (!n) { return '<td class="pl-c"><span class="mu">—</span></td>'; }
        return `<td class="pl-c" style="background:rgba(141,29,44,${(0.08 + 0.72 * r).toFixed(2)});color:${r > 0.55 ? '#fff' : 'var(--ink)'}"><button data-a="plCase" data-p="${esc(p)}" data-j="${j}" title="${esc(posteAff(p))} · ${c} : ${n} tâche${n > 1 ? 's' : ''}"><b>${n}</b>${ph ? `<small>${CAM} ${ph}</small>` : ''}</button></td>`;
      }).join('');
      const tot = PL_JOURS.reduce((s, [j]) => s + cel(p, j).length, 0);
      return `<tr><td class="pl-p">${esc(posteAff(p))}</td>${cases}<td class="pl-t">${tot}</td></tr>`;
    }).join('');
    const totJ = PL_JOURS.map(([j]) => `<td class="pl-t">${L.filter(x => x.js.includes(j)).length}</td>`).join('');
    return `<div class="tk-card"><div class="tk-ct"><span class="tk-k">Charge par poste et par jour</span><span class="mu" style="font-size:11px">plus la case est foncée, plus il y a de tâches · ${CAM} photos demandées</span></div>
      <div class="tk-defile" style="padding:8px"><table class="pl-hm"><thead><tr><th></th>${PL_JOURS.map(x => `<th>${x[1]}</th>`).join('')}<th>Semaine</th></tr></thead>
      <tbody>${lignesHtml}<tr class="pl-tot"><td class="pl-p">Tous les postes</td>${totJ}<td class="pl-t"></td></tr></tbody></table></div>
      ${sansJour ? `<div class="tk-pied mu" style="font-size:11.5px">${sansJour} tâche${sansJour > 1 ? 's' : ''} active${sansJour > 1 ? 's' : ''} sans jour reconnu (fréquence ou jour inconnu) : pas dans le planning.</div>` : ''}</div>`;
  }
  // « Fait par » = les niveaux de poste qui portent la tâche ; « Contrôlé par » = ses contrôleurs.
  function quiHtml(id, m) {
    const q = S.qui && S.qui[id];
    if (!q) { return m.quiLecture ? '<span class="pl-qui"><span>Fait par</span><span class="mu">lecture…</span></span>' : ''; }
    const fait = q.fait.length ? q.fait.map(n => esc(n.nom)).join(', ') : '<span class="wa">aucun niveau de poste</span>';
    const ctrl = q.erreur ? '<span class="mu">illisible</span>' : q.controle.length ? q.controle.map(c => esc(c.nom)).join(', ') : '<span class="wa">personne</span>';
    return `<span class="pl-qui"><span>Fait par</span><span>${fait}</span></span><span class="pl-qui"><span>Contrôlé par</span><span>${ctrl}</span></span>`;
  }
  function modalePlanning(m) {
    const { L } = planDonnees();
    const jr = PL_JOURS.find(x => x[0] === m.j);
    const T = L.filter(x => x.poste === m.p && x.js.includes(m.j)).sort((a, b) => String(a.t.heure || '99').localeCompare(String(b.t.heure || '99')) || a.t.nom.localeCompare(b.t.nom, 'fr'));
    const nPh = T.filter(x => x.t.photo).length; const nOb = T.filter(x => x.t.obligatoire).length;
    const f = sansAccent(m.f);
    const V = T.filter(x => (m.k !== 'photo' || x.t.photo) && (m.k !== 'oblig' || x.t.obligatoire) && (!f || sansAccent(x.t.nom + ' ' + x.cl + ' ' + (x.t.heure || '')).includes(f)));
    const cls = [...new Set(T.map(x => codeCl(x.cl)))].join(', ');
    const sg = (k, l, n) => `<button class="${m.k === k ? 'on' : ''}" data-a="plSeg" data-v="${k}">${l} ${n}</button>`;
    return `<div class="tk-ct"><div><h2>${esc(posteAff(m.p))} · ${jr ? jr[2] : ''}</h2><div class="tk-sous">${T.length} tâche${T.length > 1 ? 's' : ''} · ${nPh} photo${nPh > 1 ? 's' : ''} · ${esc(cls)}</div></div><button class="tk-x" data-a="fermer">×</button></div>
      <div class="pl-f"><input class="tk-in" data-pf="1" placeholder="Filtrer les tâches…" value="${esc(m.f)}"><div class="pl-seg">${sg('tout', 'Toutes', T.length)}${sg('photo', 'Photo', nPh)}${sg('oblig', 'Obligatoires', nOb)}</div></div>
      ${m.quiErr ? `<div class="tk-err" style="margin:10px 16px 0">Qui fait / qui contrôle : lecture impossible (${esc(m.quiErr)}).</div>` : ''}
      <ul class="pl-l">${V.map(x => `<li><button data-a="plTache" data-id="${x.t.id}"><span class="h">${esc(x.t.heure || '—')}</span><span class="n">${esc(x.t.nom)}${quiHtml(x.t.id, m)}</span><span class="i">${x.t.photo ? CAM : ''}</span><span class="c">${esc(codeCl(x.cl))}</span></button></li>`).join('') || '<li class="mu" style="padding:14px 0;font-size:12px">Aucune tâche pour ce filtre.</li>'}</ul>
      <div class="tk-pied"><span class="mu" style="font-size:11.5px">Un clic sur une tâche l’ouvre pour la modifier.</span><span style="flex:1"></span><button class="tk-btn" data-a="fermer">Fermer</button></div>`;
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
    } else if (m.type === 'planning') {
      corps = modalePlanning(m);
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
    const y = EMBED ? `style="margin-top:${Math.max(8, (m.y || 0) - 140)}px"` : '';
    return `<div class="tk-voile" data-a="voile"><div class="tk-modale" role="dialog" aria-modal="true" ${y}>${corps}</div></div>`;
  }

  function rendre() {
    const actif = document.activeElement;
    const k = actif && actif.dataset ? ['d', 'f', 'm', 'wc', 'wl', 'wg', 'wcm', 'wf', 'pf'].find(x => actif.dataset[x] != null) : null;
    const garde = k ? { sel: `[data-${k}="${actif.dataset[k]}"]` + (actif.dataset.i != null ? `[data-i="${actif.dataset.i}"]` : ''), s: actif.selectionStart } : null;
    if (!S.cadre && S.charge) { $.innerHTML = enTete() + '<div class="tk-attente">Lecture du cadre des tâches du panel…</div>' + modale(); return; }
    const ong = S.wz ? '' : `<div class="tk-ong"><button class="${S.vue === 'cadre' ? 'on' : ''}" data-a="vue" data-v="cadre">Cadre des tâches</button><button class="${S.vue === 'planning' ? 'on' : ''}" data-a="vue" data-v="planning">Planning des tâches</button></div>`;
    $.innerHTML = enTete() + ong + erreurs() + (S.wz ? assistant() : S.vue === 'planning' ? planning() : `<div class="tk-grille"><div>${cote()}</div><div>${S.dup ? volet() : ''}${tableau()}${creees()}</div></div>`) + modale();
    if (garde) {
      const el = $.querySelector(garde.sel);
      if (el) { el.focus(); try { if (garde.s != null && el.setSelectionRange) { el.setSelectionRange(garde.s, garde.s); } } catch (e) { /* champ sans curseur */ } }
    }
  }

  /* --- actions ------------------------------------------------------------ */
  const bascule2 = (L, v) => { const i = L.indexOf(v); if (i >= 0) { L.splice(i, 1); } else { L.push(v); } };
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
      case 'vue': S.vue = el.dataset.v; adresse(S.cl); rendre(); voirHaut(); return;
      case 'plCase': {
        S.modale = { type: 'planning', p: el.dataset.p, j: el.dataset.j, f: '', k: 'tout' }; rendre();
        // Qui fait, qui contrôle : lu au panel pour les tâches de la case qu'on n'a pas encore lues.
        const m = S.modale; S.qui = S.qui || {};
        const ids = planDonnees().L.filter(x => x.poste === m.p && x.js.includes(m.j)).map(x => x.t.id).filter(i => !S.qui[i]);
        if (!ids.length) { return; }
        m.quiLecture = true; rendre();
        try { const r = await api('POST', '/task/qui', { ids }); Object.assign(S.qui, r.qui || {}); }
        catch (e) { m.quiErr = e.message; }
        m.quiLecture = false; if (S.modale === m) { rendre(); }
        return;
      }
      case 'plSeg': S.modale.k = el.dataset.v; return rendre();
      case 'plTache': { const t = tache(id); if (!t) { return; } S.modale = { type: 'modif', id: +id, f: champsModif(t), plan: null }; return rendre(); }
      // Une checklist choisie en bas de la liste : le tableau est en haut, on y remonte.
      case 'cl': S.cl = id; S.filtre = ''; adresse(id); rendre(); voirHaut(); return;
      case 'coche': if (el.checked) { S.sel.add(+id); } else { S.sel.delete(+id); } return rendre();
      case 'tous': { const L = lignes(); if (el.checked) { L.forEach(x => S.sel.add(x.t.id)); } else { L.forEach(x => S.sel.delete(x.t.id)); } return rendre(); }
      case 'vider': S.sel.clear(); return rendre();
      case 'dupliquer': ouvrirDup(); rendre(); voirHaut(); return;
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
        const n = essai ? 1 : S.plan.aCreer + (S.plan.aAjouter || 0);
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
        S.modale = { type: 'brut', id: +id, data: null, y: el.getBoundingClientRect().top + window.scrollY }; rendre();
        try { S.modale.data = await api('GET', '/task/taches/' + id); } catch (e) { S.modale.data = { erreur: e.message, niveaux: [], controleurs: { brut: null } }; }
        return rendre();
      }
      case 'modifier': { const t = tache(id); if (!t) { return; } S.modale = { type: 'modif', id: +id, f: champsModif(t), plan: null, y: el.getBoundingClientRect().top + window.scrollY }; return rendre(); }
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
      case 'connexion': S.modale = { type: 'connexion', f: {}, y: el.getBoundingClientRect().top + window.scrollY }; return rendre();
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
      case 'wzOuvrir': return wzOuvrir(el.dataset.mode);
      case 'wzFermer':
        if (S.wz.lignes.length && !S.wz.res && !confirm('Fermer l’assistant ? Ce qui a été saisi sera perdu.')) { return; }
        S.wz = null; return rendre();
      case 'wzMode': S.wz.cl.mode = el.dataset.v; S.wz.err = null; return rendre();
      case 'wzEtape': S.wz.etape = +el.dataset.e; S.wz.err = null; S.wz.plan = null; return rendre();
      case 'wzPrecedent': S.wz.etape = Math.max(1, S.wz.etape - 1); S.wz.err = null; S.wz.plan = null; return rendre();
      case 'wzSuivant': {
        const w = S.wz; const e = wzValider(w.etape);
        if (e) { w.err = e; return rendre(); }
        w.err = null;
        if (w.etape === 1 && !w.lignes.length) { w.lignes.push(wzLigneVide()); }
        w.etape++;
        voirHaut();
        if (w.etape === 4) { return wzApercu(); }
        return rendre();
      }
      case 'wzAjouterLigne': S.wz.lignes.push(wzLigneVide()); return rendre();
      case 'wzDupLigne': { const i = +el.dataset.i; const l = S.wz.lignes[i]; S.wz.lignes.splice(i + 1, 0, Object.assign({}, l, { k: ++uid, jours: l.jours.slice() })); return rendre(); }
      case 'wzSupLigne': S.wz.lignes.splice(+el.dataset.i, 1); return rendre();
      case 'wzJour': bascule2(S.wz.lignes[+el.dataset.i].jours, el.dataset.v); return rendre();
      case 'wzCommunJour': bascule2(S.wz.commun.jours, el.dataset.v); return rendre();
      case 'wzCommunSeg': S.wz.commun[el.dataset.k] = el.dataset.v === 'null' ? null : el.dataset.v === 'true'; return rendre();
      case 'wzAppliquer': {
        const c = S.wz.commun;
        S.wz.lignes.forEach(l => {
          if (c.jours.length) { l.jours = c.jours.slice(); }
          if (c.heure) { l.heure = c.heure; }
          if (c.frequence !== '__') { l.frequence = c.frequence; }
          if (c.obligatoire !== null) { l.obligatoire = c.obligatoire; }
          if (c.photo !== null) { l.photo = c.photo; }
          if (c.sousCategorieId) { l.sousCategorieId = c.sousCategorieId; }
        });
        return rendre();
      }
      case 'wzChoixOuvrir': S.wz.choix = { filtre: '', sel: [] }; return rendre();
      case 'wzChoixFermer': S.wz.choix = null; return rendre();
      case 'wzChoixCoche': { const L = S.wz.choix.sel; const i = L.indexOf(+id); if (el.checked && i < 0) { L.push(+id); } if (!el.checked && i >= 0) { L.splice(i, 1); } return rendre(); }
      case 'wzChoixAjouter': S.wz.choix.sel.map(tache).filter(Boolean).forEach(t => S.wz.lignes.push(wzLigneDe(t))); S.wz.choix = null; return rendre();
      case 'wzNiveau': bascule2(S.wz.niveaux, +id); return rendre();
      case 'wzCreer': {
        const w = S.wz; const essai = el.dataset.essai === '1';
        const n = w.plan.aCreer + w.plan.aAjouter;
        const quoi = (w.plan.checklist && w.plan.checklist.operation ? 'la checklist et ' : '') + (essai ? 'la première tâche' : n + ' tâche' + (n > 1 ? 's' : ''));
        if (!confirm('Créer ' + quoi + ' dans le panel de production ?')) { return; }
        let spec;
        try { spec = wzSpec(); } catch (e) { w.err = e.message; return rendre(); }
        w.envoi = true; w.err = null; rendre();
        try { w.res = await api('POST', '/task/assistant/creer', Object.assign(spec, { seulementPremiere: essai })); }
        catch (e) { w.err = 'Création impossible : ' + e.message + (e.status === 403 ? ' — essayez avec un compte administrateur du panel.' : ''); }
        w.envoi = false; w.plan = null;
        return charger(true);
      }
      case 'wzReprendre': {
        // La checklist existe désormais : la suite s'y ajoute, par son nom ou son identifiant.
        const r = S.wz.res;
        if (r && r.checklist && r.checklist.id) { S.wz.cl.mode = 'existante'; S.wz.cl.id = String(r.checklist.id); }
        S.wz.res = null; return wzApercu();
      }
      case 'wzFin': {
        const r = S.wz.res; const cid = r && r.checklist && r.checklist.id;
        S.wz = null;
        if (cid && checklist(cid)) { S.cl = String(cid); adresse(String(cid)); }
        return rendre();
      }
      case 'creesOuvrir': S.creesOuvert = !S.creesOuvert; S.creesMax = 40; return rendre();
      case 'creesTout': S.creesTout = !S.creesTout; S.creesMax = 40; return rendre();
      case 'creesPlus': S.creesMax = (S.creesMax || 40) + 40; return rendre();
      case 'supprimerCl': {
        const c = ((S.cadre && S.cadre.checklistsCreees) || []).find(x => x.id === +id);
        if (!confirm('Supprimer du panel la checklist « ' + (c ? c.nom : '#' + id) + ' » (#' + id + ') ? Ses tâches restent dans le panel.')) { return; }
        try { await api('DELETE', '/task/checklists/' + id); S.msg = { k: 'ok', t: 'Checklist #' + esc(id) + ' supprimée du panel.' }; }
        catch (e) { S.msg = { k: 'ko', t: 'Suppression refusée : ' + esc(e.message) }; }
        return charger(true);
      }
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
    if (el.dataset.pf && S.modale && S.modale.type === 'planning') { S.modale.f = el.value; return rendre(); }
    if (S.wz) {
      const w = S.wz;
      if (el.dataset.wc != null) { w.cl[el.dataset.wc] = el.value; return; }
      if (el.dataset.wl != null) { const l = w.lignes[+el.dataset.i]; if (l) { l[el.dataset.wl] = el.type === 'checkbox' ? el.checked : el.value; } return; }
      if (el.dataset.wg != null) { w[el.dataset.wg] = el.value; return; }
      if (el.dataset.wcm != null) { w.commun[el.dataset.wcm] = el.value; return; }
      if (el.dataset.wf != null && w.choix) { w.choix.filtre = el.value; return rendre(); }
    }
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
    if (S.wz && (el.tagName === 'SELECT' || el.type === 'checkbox') && (el.dataset.wc != null || el.dataset.wl != null || el.dataset.wg != null || el.dataset.wcm != null)) { rendre(); }
  });
  // Sur le document : après un clic dans une fenêtre, le focus retombe sur <body>, hors de #tk.
  document.addEventListener('keydown', ev => {
    if (ev.key === 'Escape' && S.modale) { S.modale = null; rendre(); }
    if (ev.key === 'Enter' && ev.target.dataset && ev.target.dataset.m === 'password') { action('seConnecter', ev.target); }
  });

  charger();
}());
