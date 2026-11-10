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
 * La vue DOCUMENT (par défaut) montre le cadre comme un classeur : une section par checklist, une ligne par
 * tâche, ses réglages en vignettes qu'un clic change (voir « la vue Document »). Le Tableau d'avant et le
 * Planning restent dans leurs onglets.
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
  // La hauteur dite au cockpit : le bas du contenu, ou celui d'une fenêtre ouverte s'il est plus bas — une
  // fenêtre (Modifier, le jour du planning) est posée par-dessus, hors du flux : sans elle, le cadre restait
  // à la hauteur du contenu et la coupait (07/11/2026).
  let hauteurDite = 0, hauteurAttente = 0;
  function signalerHauteur() {
    if (!EMBED || window.parent === window) { return; }
    cancelAnimationFrame(hauteurAttente);
    hauteurAttente = requestAnimationFrame(() => {
      let bas = document.getElementById('tk').getBoundingClientRect().bottom;
      document.querySelectorAll('.tk-modale, .dc-dd').forEach(m => { bas = Math.max(bas, m.getBoundingClientRect().bottom + 24); });
      const h = Math.ceil(bas + window.scrollY) + 8;
      if (Math.abs(h - hauteurDite) < 2) { return; }
      hauteurDite = h;
      try { window.parent.postMessage({ tkHauteur: h }, location.origin); } catch (e) { /* cockpit absent */ }
    });
  }
  if (EMBED && window.ResizeObserver) { new ResizeObserver(signalerHauteur).observe(document.getElementById('tk')); }
  // Intégrée, le cockpit dit où il en est de son défilement (tkDefile, en px depuis le haut du cadre) : le sommaire
  // de la vue Document le suit, sans sortir de sa colonne.
  let defileCockpit = 0;
  function placerSommaire() {
    if (!EMBED) { return; }
    const som = document.querySelector('.dc-som'); const dc = document.querySelector('.dc');
    if (!som || !dc) { return; }
    const haut = dc.getBoundingClientRect().top + window.scrollY;
    const y = Math.max(0, Math.min(dc.offsetHeight - som.offsetHeight, defileCockpit + 12 - haut));
    som.style.transform = y > 0 ? `translateY(${Math.round(y)}px)` : '';
  }
  window.addEventListener('message', ev => {
    if (ev.origin !== location.origin || !ev.data || typeof ev.data.tkDefile !== 'number') { return; }
    defileCockpit = ev.data.tkDefile; placerSommaire();
  });
  function adresse(cl) {
    const a = new URLSearchParams();
    if (EMBED) { a.set('embed', '1'); }
    if (S.vue === 'planning' || S.vue === 'checklists' || S.vue === 'soir' || S.vue === 'temps') { a.set('vue', S.vue); } else { if (cl) { a.set('checklist', cl); } }
    try { history.replaceState(null, '', '?' + a.toString()); } catch (e) { /* l'adresse reste l'ancienne */ }
    if (EMBED && window.parent !== window) { try { window.parent.postMessage({ tkChecklist: cl }, location.origin); } catch (e) { /* cockpit absent */ } }
  }

  const S = {
    cadre: null, err: null, charge: true,
    cl: q.get('checklist') || null,        // id de checklist, 'tout' ou 'hors'
    filtre: '', sel: new Set(),
    dup: null, plan: null, res: null, envoi: false,
    modale: null, msg: null,
    // Les vues : le Document (par défaut, 07/11/2026 ; le Tableau d'avant y est fusionné le 09/11/2026, ?vue=tableau l'ouvre), le Planning.
    vue: q.get('vue') === 'planning' ? 'planning' : q.get('vue') === 'checklists' ? 'checklists' : q.get('vue') === 'soir' ? 'soir' : q.get('vue') === 'temps' ? 'temps' : 'doc',
    dd: null,   // la liste ouverte d'une vignette du Document : {k, id, c, portee, …}
    rq: q.get('q') || '', rqMax: 150,   // la recherche partout (tâches et checklists), et combien de tâches on en montre   // onglet : Cadre des tâches · Planning des tâches
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
      S.err = null; S.dcSugg = null; S.dcMots = null;
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
    if (S.cadre && S.vue === 'checklists' && !S.nomen) { ckOuvrir(); }
    if (S.vue === 'soir') { rsOuvrir(); }
    if (S.cadre && S.vue === 'doc') {
      // ?checklist=ID : on descend jusqu'à sa section, une fois (le cockpit a d'abord pris la hauteur du cadre).
      if (!S.dcVu && /^(\d{1,10}|hors)$/.test(q.get('checklist') || '')) { S.dcVu = true; setTimeout(() => dcAller(q.get('checklist')), 450); }
      dcQuiCharger();
    }
  }

  /* --- rendu -------------------------------------------------------------- */
  function enTete() {
    const a = admin();
    // Le compte, en une ligne discrète : on n'en change qu'en cas de refus du panel.
    const compte = a
      ? `Compte administrateur du panel, jusqu’à ${new Date(a.expire * 1000).toLocaleTimeString('fr-BE', { hour: '2-digit', minute: '2-digit' })} · <button class="tk-lien" data-a="deconnexion">revenir au compte du cockpit</button>`
      : `Compte du cockpit${S.cadre && S.cadre.api && !S.cadre.api.configure ? ' (non configuré)' : ''} · <button class="tk-lien" data-a="connexion">compte administrateur…</button>`;
    // La recherche partout (07/11/2026, « je dois absolument avoir une search bar qui cherche partout, tâche et
    // checklist ») : en tête, toujours là, « / » ou Ctrl+K pour y aller.
    const recherche = S.wz ? '' : `<div class="tk-rech"><span class="tk-rech-ic" aria-hidden="true">⌕</span><input type="search" class="tk-in tk-rech-in" data-f="recherche" value="${esc(S.rq)}"
      placeholder="Rechercher une tâche ou une checklist — nom, description, sous-catégorie, n°, jour, heure, SOP…" aria-label="Rechercher une tâche ou une checklist" autocomplete="off">
      ${S.rq ? '<button class="tk-rech-x" data-a="rqEffacer" title="Effacer (Échap)" aria-label="Effacer la recherche">×</button>' : '<kbd class="tk-rech-kbd" title="Raccourci">/</kbd>'}</div>`;
    const outils = `${recherche}<span class="tk-compte">${compte}</span><span class="sp"></span>
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

  /** La barre de sélection n'apparaît qu'avec une sélection : sans elle, un bouton grisé ressemblait à une erreur. */
  function barreSelection(c) {
    const n = S.sel.size;
    // Dans le document, la section d'une checklist : « Changer de checklist… » pour ses tâches cochées.
    const nc = c ? dcTachesDe(c).filter(t => S.sel.has(t.id)).length : 0;
    const ouv = c && S.dd && S.dd.k === 'deplacer' && S.dd.id == null && S.dd.c === c.id;
    const dp = nc ? `<span class="dc-ancre"><button class="tk-btn prim" data-a="ddOuvrir" data-k="deplacer" data-t="" data-c="${c.id}">Changer de checklist${nc < n ? ' (' + nc + ')' : ''}…</button>${ouv ? dcListe() : ''}</span>` : '';
    return n
      ? `<div class="tk-selbar"><b>${n} tâche${n > 1 ? 's' : ''} cochée${n > 1 ? 's' : ''}</b>${dp}
          <button class="tk-btn${dp ? '' : ' prim'}" data-a="dupliquer">Dupliquer…</button>
          <button class="tk-btn" data-a="wzOuvrir" data-mode="selection">Ouvrir dans l’assistant</button>
          <button class="tk-lien" data-a="vider">tout décocher</button></div>`
      : '';
  }

  /* --- la recherche partout ------------------------------------------------ */
  const termesRq = () => sansAccent(S.rq).split(/\s+/).map(m => m.replace(/^#/, '')).filter(Boolean);
  /** Un texte échappé, les mots cherchés surlignés (sans tenir compte des accents ni de la casse). */
  function surligne(texte, termes) {
    const t = String(texte == null ? '' : texte);
    const base = [...t].map(ch => ch.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase());
    if (!termes.length || base.some(b => b.length !== 1)) { return esc(t); }
    const plat = base.join(''), on = new Array(base.length).fill(false);
    termes.forEach(m => { let i = plat.indexOf(m); while (m && i >= 0) { for (let k = i; k < i + m.length; k++) { on[k] = true; } i = plat.indexOf(m, i + 1); } });
    let out = '', dans = false;
    [...t].forEach((ch, i) => { if (on[i] && !dans) { out += '<mark>'; dans = true; } if (!on[i] && dans) { out += '</mark>'; dans = false; } out += esc(ch); });
    return out + (dans ? '</mark>' : '');
  }
  /** Les checklists de chaque tâche. */
  function checklistsParTache() {
    const m = {};
    checklists().forEach(c => c.taches.forEach(l => { (m[l.taskId] = m[l.taskId] || []).push(c); }));
    return m;
  }
  function resultatsRq() {
    const termes = termesRq();
    if (!termes.length) { return null; }
    const ok = txt => termes.every(m => txt.includes(m));
    const liens = (S.cadre && S.cadre.sopLiens) || {};
    const cls = checklists().filter(c => ok(sansAccent([c.nom, c.description, c.poste, c.heure, c.id].join(' '))));
    const parT = checklistsParTache();
    const ts = taches().map(t => {
      const nom = sansAccent(t.nom);
      const tout = sansAccent([t.nom, t.description, t.section, t.categorie, t.sousCategorie, t.id, t.heure, t.jour, jourFr(t.jour) || '', quandAff(t),
        t.photo ? 'photo' : '', t.obligatoire ? 'obligatoire' : '', t.actif === false ? 'en pause' : '',
        ...(liens[t.id] || []).map(y => [y.code, y.titre, y.etape].join(' '))].join(' '));
      return ok(tout) ? { t, rang: termes.every(m => nom.includes(m)) ? 0 : 1 } : null;
    }).filter(Boolean).sort((a, b) => a.rang - b.rang || a.t.nom.localeCompare(b.t.nom, 'fr'));
    return { termes, cls, ts, parT };
  }
  function ecranRecherche() {
    const r = resultatsRq();
    const n = r.ts.length + r.cls.length;
    const tete = `<div class="tk-rq-tete"><span>${n ? `<b>${r.ts.length}</b> tâche${r.ts.length > 1 ? 's' : ''} et <b>${r.cls.length}</b> checklist${r.cls.length > 1 ? 's' : ''}` : 'Rien'} pour « ${esc(S.rq.trim())} »</span>
      <button class="tk-lien" data-a="rqEffacer">effacer la recherche</button></div>`;
    const cls = r.cls.length ? `<div class="tk-card"><div class="tk-ct"><span class="tk-k">Checklists</span><span class="mu" style="font-size:11.5px">${r.cls.length}</span></div>
      <div class="tk-rq-cls">${r.cls.map(c => `<button class="tk-rq-cl" data-a="rqCl" data-id="${c.id}"><span class="tk-nom">${surligne(c.nom, r.termes)}</span>
        <small>${esc([c.heure && 'à ' + c.heure, c.poste, c.taches.length + ' tâche' + (c.taches.length > 1 ? 's' : '')].filter(Boolean).join(' · '))}</small>${c.description ? `<small>${surligne(c.description, r.termes)}</small>` : ''}</button>`).join('')}</div></div>` : '';
    const vus = r.ts.slice(0, S.rqMax);
    const tous = vus.length && vus.every(x => S.sel.has(x.t.id));
    const ts = r.ts.length ? `<div class="tk-card"><div class="tk-ct"><span class="tk-k">Tâches</span><span class="mu" style="font-size:11.5px">${r.ts.length}</span></div>${barreSelection()}
      <div class="tk-defile"><table class="tk-tab tk-taches"><thead><tr>
        <th class="c" style="width:34px"><input type="checkbox" data-a="rqTous" ${tous ? 'checked' : ''} aria-label="Tout cocher" title="Tout cocher"></th>
        <th>Tâche</th><th style="width:240px">Checklist</th><th style="width:130px">Quand</th><th style="width:150px">Exigences</th><th style="width:118px"></th></tr></thead><tbody>
      ${vus.map(({ t }) => {
        const cl = r.parT[t.id] || [];
        const desc = t.description && r.termes.some(m => sansAccent(t.description).includes(m)) && !r.termes.every(m => sansAccent(t.nom).includes(m)) ? `<small class="tk-rq-desc">${surligne(t.description, r.termes)}</small>` : '';
        const exig = [t.obligatoire ? '<span class="tk-pas oui">obligatoire</span>' : '', t.photo ? '<span class="tk-pas photo">photo</span>' : '',
          t.actif === false ? '<span class="tk-pas att">en pause</span>' : ''].filter(Boolean).join(' ') || '<span class="mu">—</span>';
        return `<tr class="${S.sel.has(t.id) ? 'sel' : ''}${t.actif === false ? ' pause' : ''}">
          <td class="c"><input type="checkbox" data-a="coche" data-id="${t.id}" ${S.sel.has(t.id) ? 'checked' : ''} aria-label="Cocher ${esc(t.nom)}"></td>
          <td class="nom"><span class="tk-nom">${surligne(t.nom, r.termes)}</span>
            <small>${surligne(t.sousCategorie || '', r.termes)}${t.sousCategorie ? ' · ' : ''}#${surligne(String(t.id), r.termes)}</small>${desc}</td>
          <td>${cl.length ? cl.map(c => `<button class="tk-rq-chip" data-a="rqCl" data-id="${c.id}" title="Ouvrir la checklist">${esc(c.nom)}</button>`).join(' ') : '<span class="mu">dans aucune checklist</span>'}</td>
          <td><span class="tk-quand">${esc(quandAff(t))}</span><small>${esc(t.heure || 'sans heure')}</small></td>
          <td>${exig}</td>
          <td class="act"><button class="tk-btn mini" data-a="modifier" data-id="${t.id}">Modifier</button><button class="tk-btn mini ghost" data-a="brut" data-id="${t.id}">Détails</button></td></tr>`;
      }).join('')}</tbody></table></div>
      ${r.ts.length > vus.length ? `<div class="tk-astuce"><button class="tk-lien" data-a="rqPlus">Afficher les ${r.ts.length - vus.length} autres</button></div>` : ''}</div>` : '';
    const vide = n ? '' : `<div class="tk-card"><div class="tk-astuce" style="border-top:none">Aucune tâche ni checklist ne contient tous ces mots. Essayez un seul mot, une sous-catégorie, un n° de tâche ou un code (AC-01, SOP-WKL-001).</div></div>`;
    return `<div class="tk-rq">${tete}${cls}${ts}${vide}</div>`;
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
    const selection = barreSelection();
    return `<div class="tk-card"><div class="tk-ct"><div class="tk-cl-titre"><div class="tk-t15">${esc(titre)}</div>${sous ? `<div class="tk-sous">${esc(sous)}</div>` : ''}</div>
      <span class="sp"></span>
      ${c ? `<button class="tk-btn" data-a="clModifier" data-id="${c.id}">✎ Modifier</button><button class="tk-btn" data-a="wzOuvrir" data-mode="existante">＋ Ajouter des tâches</button>` : ''}</div>
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
  /** En bas du Document (09/11/2026, Tableau fusionné) : tout ce qui a été créé depuis cet écran, avec Supprimer. */
  function dcCreees() { const a = S.creesTout; S.creesTout = true; S.dcCreeesRendu = true; const h = creees(); S.creesTout = a; S.dcCreeesRendu = false; return h ? `<div class="dc-creees">${h}</div>` : ''; }
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
      ${S.creesOuvert && c && !S.dcCreeesRendu ? `<button class="tk-lien" data-a="creesTout">${S.creesTout ? 'cette checklist seulement' : 'toute la liste'}</button>` : ''}
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
        <label>Code</label><div>${ckPicker((String(c.nom || '').match(/^\s*([0-9]\.[1-9]\.\d{2})/) || [])[1] || '', 'wz')}</div>
        <label>Nom <span class="ko">*</span></label><div><input class="tk-in large" data-wc="nom" value="${esc(c.nom)}" placeholder="1.3.01 · Contrôle qualité de l’après-midi"><div class="aide">Le code se met tout seul quand vous choisissez qui et quand ; écrivez ensuite ce qu’elle contrôle.</div></div>
        <label>Description</label><input class="tk-in large" data-wc="description" value="${esc(c.description)}" placeholder="Ce que l’équipe doit faire, en une phrase">
        <label>Poste de travail</label><select class="tk-in" data-wc="posteId"><option value="">${postes.length || !S.wz.struct ? 'aucun' : 'aucun poste lu'}</option>${postes.map(p => `<option value="${p.id}"${String(c.posteId) === String(p.id) ? ' selected' : ''}>${esc(posteAff(p.nom))}</option>`).join('')}</select>
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
  const PL_ORDRE = ['vente', 'production boulangerie', 'production patisserie', 'production traiteur', 'nettoyage', 'logistique', 'administartif', 'administratif', 'responsable'];
  // « Administartif » (le nom du poste au panel) s'affiche « Responsable » (10/11/2026, demande du client).
  const posteAff = p => ({ Administartif: 'Responsable', Administratif: 'Responsable', 'Production Patisserie': 'Production Pâtisserie' })[p] || p;
  const CAM = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>';
  function planDonnees() {
    const cl = new Map();
    checklists().forEach(c => c.taches.forEach(l => { if (!cl.has(l.taskId)) { cl.set(l.taskId, c); } }));
    const L = taches().filter(t => t.actif !== false).map(t => {
      const c = cl.get(t.id); const f = String(t.frequence || '').toUpperCase(); const j = String(t.jour || '').toUpperCase();
      const js = !f || f === 'DAILY' ? PL_JOURS.map(x => x[0]) : PL_JOURS.some(x => x[0] === j) ? [j] : [];
      return { t, js, poste: (c && c.poste) || 'Sans poste', cl: c ? c.nom : 'Dans aucune checklist', clId: c ? c.id : null };
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
  // Modifiable en direct : × retire au panel, la liste « ＋ » ajoute (une opération, relue aussitôt).
  const nomNiv = n => n.poste && !n.nom.includes(n.poste) ? `${n.nom} · ${n.poste}` : n.nom;
  function quiHtml(id, m) {
    const q = S.qui && S.qui[id];
    if (!q) { return m.quiLecture ? '<div class="pl-qui"><span>Fait par</span><span class="mu">lecture…</span></div>' : ''; }
    const ch = S.quiChoix || { niveaux: [], postes: [] };
    const occ = m.occupe === id ? ' disabled' : '';
    const puce = (txt, a, attrs) => `<span class="pl-puce">${esc(txt)}<button data-a="${a}" data-t="${id}" ${attrs} title="Retirer"${occ}>×</button></span>`;
    const dejaN = new Set(q.fait.map(n => n.id));
    const dejaP = new Set(q.controle.filter(c => c.type === 'position').map(c => c.positionId));
    const aSelf = q.controle.some(c => c.type === 'self');
    const ajN = `<select class="pl-aj" data-pq="niv" data-t="${id}"${occ}><option value="">＋ niveau</option>${ch.niveaux.filter(n => !dejaN.has(n.id)).map(n => `<option value="${n.id}">${esc(nomNiv(n))}</option>`).join('')}</select>`;
    const ajC = `<select class="pl-aj" data-pq="ctl" data-t="${id}"${occ}><option value="">＋ contrôleur</option>${aSelf ? '' : '<option value="self">La personne qui la fait</option>'}${ch.postes.filter(p => !dejaP.has(p.id)).map(p => `<option value="${p.id}">${esc(p.nom)}</option>`).join('')}</select>`;
    const fait = q.fait.map(n => puce(nomNiv(n), 'plRetNiv', `data-n="${n.id}" data-route="${esc(n.route || '')}"`)).join('') || '<span class="wa">aucun niveau</span>';
    const ctrl = q.erreur ? '<span class="mu">illisible</span>' : q.controle.map(c => puce(c.nom, 'plRetCtl', `data-c="${c.id}"`)).join('') || '<span class="wa">personne</span>';
    return `<div class="pl-qui"><span>Fait par</span><span class="pl-pz">${fait}${ajN}</span></div><div class="pl-qui"><span>Contrôlé par</span><span class="pl-pz">${ctrl}${q.erreur ? '' : ajC}</span></div>`;
  }
  async function quiChanger(m, id, corps) {
    m.occupe = id; m.quiErr = null; rendre();
    try { const r = await api('POST', '/task/qui/modifier', Object.assign({ taskId: id }, corps)); Object.assign(S.qui, r.qui || {}); }
    catch (e) { m.quiErr = 'Tâche #' + id + ' : ' + e.message; }
    m.occupe = null; if (S.modale === m) { rendre(); }
  }
  // Qui fait, qui contrôle : lu au panel pour les tâches de la zone qu'on n'a pas encore lues
  // (80 par appel, le plafond de la route).
  async function quiCharger(m) {
    S.qui = S.qui || {};
    const ids = planDonnees().L.filter(x => (!m.p || x.poste === m.p) && x.js.includes(m.j)).map(x => x.t.id).filter(i => !S.qui[i]);
    if (!ids.length) { return; }
    m.quiLecture = true; m.quiErr = null; rendre();
    try {
      for (let i = 0; i < ids.length; i += 80) { const r = await api('POST', '/task/qui', { ids: ids.slice(i, i + 80) }); Object.assign(S.qui, r.qui || {}); if (r.choix) { S.quiChoix = r.choix; } }
    } catch (e) { m.quiErr = e.message; }
    m.quiLecture = false; if (S.modale === m) { rendre(); }
  }
  function modalePlanning(m) {
    const { L, postes } = planDonnees();
    const jr = PL_JOURS.find(x => x[0] === m.j);
    // La zone de production (poste de la checklist) et la checklist se choisissent dans la fenêtre ;
    // '' = toutes. Les compteurs et les choix suivent la zone.
    const duJour = L.filter(x => x.js.includes(m.j));
    const Z = duJour.filter(x => !m.p || x.poste === m.p);
    const T = Z.filter(x => !m.cl || x.cl === m.cl).sort((a, b) => String(a.t.heure || '99').localeCompare(String(b.t.heure || '99')) || a.t.nom.localeCompare(b.t.nom, 'fr'));
    const nPh = T.filter(x => x.t.photo).length; const nOb = T.filter(x => x.t.obligatoire).length;
    const f = sansAccent(m.f);
    // Fait par / contrôlé par : les choix sont ceux des tâches de la case, lus au panel.
    const Q = id => (S.qui && S.qui[id]) || null;
    const choix = cle => [...new Set(T.flatMap(x => { const q = Q(x.t.id); return q ? q[cle].map(n => n.nom) : []; }))].sort((a, b) => a.localeCompare(b, 'fr'));
    const cFait = choix('fait'); const cCtrl = choix('controle');
    const V = T.filter(x => (m.k !== 'photo' || x.t.photo) && (m.k !== 'oblig' || x.t.obligatoire) && (!f || sansAccent(x.t.nom + ' ' + x.cl + ' ' + (x.t.heure || '')).includes(f))
      && (!m.fait || ((Q(x.t.id) || {}).fait || []).some(n => n.nom === m.fait))
      && (!m.ctrl || ((Q(x.t.id) || {}).controle || []).some(n => n.nom === m.ctrl)));
    const liste = (cle, lib, L) => `<select class="tk-in pl-sel" data-ps="${cle}" ${L.length ? '' : 'disabled'}><option value="">${lib} : ${m.quiLecture ? 'lecture…' : 'tous'}</option>${L.map(v => `<option value="${esc(v)}" ${m[cle] === v ? 'selected' : ''}>${lib} : ${esc(v)}</option>`).join('')}</select>`;
    const cls = [...new Set(T.map(x => codeCl(x.cl)))].join(', ');
    const zones = postes.filter(p => duJour.some(x => x.poste === p));
    const nomsCl = [...new Set(Z.map(x => x.cl))].sort((a, b) => a.localeCompare(b, 'fr'));
    const selZone = `<select class="tk-in pl-sel" data-ps="p"><option value="">Zone de production : toutes</option>${zones.map(p => `<option value="${esc(p)}" ${m.p === p ? 'selected' : ''}>Zone de production : ${esc(posteAff(p))}</option>`).join('')}</select>`;
    const selCl = `<select class="tk-in pl-sel" data-ps="cl"><option value="">Checklist : toutes</option>${nomsCl.map(c => `<option value="${esc(c)}" ${m.cl === c ? 'selected' : ''}>Checklist : ${esc(c)}</option>`).join('')}</select>`;
    const sg = (k, l, n) => `<button class="${m.k === k ? 'on' : ''}" data-a="plSeg" data-v="${k}">${l} ${n}</button>`;
    return `<div class="tk-ct"><div><h2>${m.p ? esc(posteAff(m.p)) : 'Toutes les zones'} · ${jr ? jr[2] : ''}</h2><div class="tk-sous">${T.length} tâche${T.length > 1 ? 's' : ''} · ${nPh} photo${nPh > 1 ? 's' : ''} · ${esc(cls)}</div></div><button class="tk-x" data-a="fermer">×</button></div>
      <div class="pl-f"><input class="tk-in" data-pf="1" placeholder="Filtrer les tâches…" value="${esc(m.f)}"><div class="pl-seg">${sg('tout', 'Toutes', T.length)}${sg('photo', 'Photo', nPh)}${sg('oblig', 'Obligatoires', nOb)}</div>
        <div class="pl-sels">${selZone}${selCl}${liste('fait', 'Fait par', cFait)}${liste('ctrl', 'Contrôlé par', cCtrl)}</div>${m.fait || m.ctrl || m.cl ? '<button class="tk-lien" data-a="plSansQui">tout montrer</button>' : ''}</div>
      ${m.cl && T[0] && T[0].clId ? `<div class="pl-clbar"><span class="mu">${esc(m.cl)}</span><button class="tk-btn" data-a="clModifier" data-id="${T[0].clId}">✎ Modifier la checklist</button></div>` : ''}
      ${m.quiErr ? `<div class="tk-err" style="margin:10px 16px 0">${esc(m.quiErr)}</div>` : ''}
      <ul class="pl-l">${V.map(x => `<li><span class="h">${esc(x.t.heure || '—')}</span><span class="n"><button class="pl-nom" data-a="plTache" data-id="${x.t.id}" title="Modifier la tâche">${esc(x.t.nom)}</button>${quiHtml(x.t.id, m)}</span><span class="i">${x.t.photo ? CAM : ''}</span><span class="c">${esc(codeCl(x.cl))}</span></li>`).join('') || '<li class="mu" style="padding:14px 0;font-size:12px">Aucune tâche pour ce filtre.</li>'}</ul>
      <div class="tk-pied"><span class="mu" style="font-size:11.5px">Un clic sur le nom ouvre la tâche · × et ＋ changent qui fait et qui contrôle, directement dans le panel.</span><span style="flex:1"></span><button class="tk-btn" data-a="fermer">Fermer</button></div>`;
  }

  /* --- la vue Document (07/11/2026 : maquette F choisie, « sur les lignes de tâches, au clic sur chaque
   * vignette, une liste pour choisir un autre paramètre disponible ») ---------------------------------------
   * Le cadre se lit comme un classeur : une section par checklist, dans l'ordre des heures, une ligne par tâche.
   * Chaque ligne porte ses réglages en vignettes : heure, jours, photo, obligatoire, qui la fait, qui la
   * contrôle. Un clic sur une vignette ouvre les choix que le panel accepte. Un choix simple part au panel
   * aussitôt ; une liste à cocher part à « Appliquer ». En bas de chaque liste : pour cette tâche, ou pour
   * toute sa checklist. Écritures : PATCH …/task/taches (lot) et POST …/task/qui/lot.
   */
  const DC_COULEURS = { vente: '#8D1D2C', 'production boulangerie': '#B0792A', 'production patisserie': '#A0522D', 'production traiteur': '#3f7a52',
    nettoyage: '#1f5f8b', administartif: '#6B4FBB', administratif: '#6B4FBB', responsable: '#6B4FBB', logistique: '#5c5852' };
  const dcCouleur = p => DC_COULEURS[sansAccent(p)] || '#8a847c';
  /** « 1.1.01 · Ouverture de la boutique » → code 1.1.01 (la nomenclature du 08/11/2026) ; avant elle, « AC-01 · … » → AC-01. */
  const dcCode = nom => { const m = String(nom || '').match(/^\s*([0-9]\.[1-9]\.\d{2}|[A-Z]{2,5}(?:-[A-Z0-9]{1,6}){1,3})\s+[·—–:-]\s+(.+)$/); return m ? { code: m[1], titre: m[2] } : { code: '', titre: String(nom || '') }; };
  /** Le nom d'une tâche sans le code de sa checklist devant (« AC-01 · Allumer… » sous AC-01 → « Allumer… »). */
  const dcSansCode = (nom, code) => { const c = dcCode(nom); return code && c.code === code ? c.titre : String(nom || ''); };
  const dcMoment = h => !h ? 'Sans heure' : h < '07:00' ? 'Avant l’ouverture' : h < '12:00' ? 'Matin' : h < '17:00' ? 'Après-midi' : 'Soir';
  const DC_MOMENTS = ['Avant l’ouverture', 'Matin', 'Après-midi', 'Soir', 'Sans heure'];
  /** Les jours où la tâche revient, comme le planning les compte (DAILY : les sept). */
  function dcJoursDe(t) {
    const f = String(t.frequence || '').toUpperCase(); const j = String(t.jour || '').toUpperCase();
    if (!f || f === 'DAILY') { return PL_JOURS.map(x => x[0]); }
    return PL_JOURS.some(x => x[0] === j) ? [j] : [];
  }
  const dcPoints = js => `<span class="dc-pts" aria-label="${esc(PL_JOURS.filter(x => js.includes(x[0])).map(x => x[2]).join(', '))}">${PL_JOURS.map(x => `<i class="${js.includes(x[0]) ? 'on' : ''}">${x[1][0]}</i>`).join('')}</span>`;
  const dcOrdre = () => checklists().slice().sort((a, b) => (a.heure || '99').localeCompare(b.heure || '99') || a.nom.localeCompare(b.nom, 'fr'));
  const dcTachesDe = c => [...new Set(c.taches.map(l => l.taskId))].map(id => tache(id)).filter(Boolean);
  const dcHors = () => { const h = new Set((S.cadre && S.cadre.horsChecklist) || []); return taches().filter(t => h.has(t.id)); };
  /** Les tâches que touche la liste ouverte : la tâche, ou toutes celles de sa checklist. */
  const dcCibles = d => d.portee === 'checklist' && d.c ? dcTachesDe(checklist(d.c) || { taches: [] }).map(t => t.id) : [d.id];
  const dcCourt = s => String(s || '').replace(/\s*\(\s*Crew Chief\s*\)/i, '').replace(/^Opérateur\s+/, '').replace(' & Conformité', '');
  const dcNomCtl = c => c.type === 'self' ? 'Auto-contrôle' : c.nom;

  /* Qui fait / qui contrôle : lu au panel pour toutes les tâches du document, 200 par appel (le plafond de la route). */
  async function dcQuiCharger() {
    if (S.dcQui && S.dcQui.enCours) { return; }
    S.qui = S.qui || {};
    const ids = [...new Set(taches().map(t => t.id))].filter(i => !S.qui[i]);
    if (!ids.length) { return; }
    S.dcQui = { enCours: true, lu: 0, total: ids.length, err: null };
    rendre();
    try {
      for (let i = 0; i < ids.length; i += 200) {
        const r = await api('POST', '/task/qui', { ids: ids.slice(i, i + 200) });
        Object.assign(S.qui, r.qui || {}); if (r.choix) { S.quiChoix = r.choix; }
        S.dcQui.lu = Math.min(ids.length, i + 200);
        if ((S.vue === 'doc' && !S.dd) || (S.vue === 'temps' && !tlDrag)) { rendre(); }
      }
    } catch (e) { S.dcQui.err = e.message; }
    S.dcQui.enCours = false;
    rendre();
  }

  /* --- ranger une tâche (08/11/2026, maquette R1 : « Ranger → AC-01 ▾ » sur chaque ligne à ranger) --- */
  const dcMin = h => { const m = /^(\d\d):(\d\d)/.exec(h || ''); return m ? +m[1] * 60 + +m[2] : null; };
  /** Les mots d'un nom (sans code ni accents, trois lettres au moins, sans les petits mots), gardés par tâche. */
  const DC_VIDES = new Set(['les', 'des', 'une', 'pour', 'sur', 'par', 'dans', 'avec', 'aux', 'est', 'que', 'qui', 'son', 'ses', 'leur', 'tout', 'tous', 'chaque']);
  function dcMots(t) {
    S.dcMots = S.dcMots || {};
    if (!S.dcMots[t.id]) { S.dcMots[t.id] = new Set(sansAccent(dcCode(t.nom).titre).replace(/[^a-z0-9 ]/g, ' ').split(/\s+/).filter(w => w.length >= 3 && !DC_VIDES.has(w))); }
    return S.dcMots[t.id];
  }
  // Un seul mot commun ne compte presque pas entre deux noms longs (« Remplir le rayon pains » n'est pas « Photo du
  // comptoir - Pains & Boulangerie ») ; il compte entre deux noms courts (« Cartons & poubelles », « Cartons pliés »).
  const dcSim = (a, b) => { let n = 0; a.forEach(w => { if (b.has(w)) { n++; } }); const m = Math.max(1, Math.min(a.size, b.size)); return n >= 2 || Math.max(a.size, b.size) <= 2 ? n / m : n / (m + 2); };
  /**
   * Les checklists où ranger une tâche : un nom proche d'une de leurs tâches (50 %), des tâches au même moment (35 % :
   * la plus proche à moins de 90 min, et combien à moins de 45 min), la même exigence de photo (15 %). Deux au plus.
   * Un nom très proche (≥ 60 % des mots) d'une tâche déjà rangée : un doublon probable.
   */
  function dcSuggestions(t) {
    S.dcSugg = S.dcSugg || {};
    if (S.dcSugg[t.id]) { return S.dcSugg[t.id]; }
    const mt = dcMots(t); const ht = dcMin(t.heure);
    const L = checklists().map(c => {
      const ts = dcTachesDe(c).filter(x => x.id !== t.id);
      let nom = 0, proche = null;
      ts.forEach(x => { const v = dcSim(mt, dcMots(x)); if (v > nom) { nom = v; proche = x; } });
      let temps = 0;
      if (ht != null) {
        const ds = ts.map(x => dcMin(x.heure)).filter(v => v != null).map(v => Math.abs(v - ht));
        if (ds.length) { temps = 0.5 * Math.max(0, 1 - Math.min(...ds) / 90) + 0.5 * Math.min(1, ds.filter(v => v <= 45).length / 4); }
      }
      const photo = ts.length ? ts.filter(x => !!x.photo === !!t.photo).length / ts.length : 0;
      const why = nom >= 0.5 && proche ? 'comme « ' + dcSansCode(proche.nom, dcCode(c.nom).code) + ' »' : temps >= 0.5 ? 'même moment, ' + t.heure : '';
      return { c, score: 0.5 * nom + 0.35 * temps + 0.15 * photo, nom, proche, why };
    }).filter(x => x.score >= 0.25).sort((a, b) => b.score - a.score);
    const dbl = L.filter(x => x.nom >= 0.6).sort((a, b) => b.nom - a.nom)[0];
    return (S.dcSugg[t.id] = { top: L.slice(0, 2), dbl: dbl ? { c: dbl.c, t: dbl.proche } : null });
  }
  /** Ce que la tâche recevrait de la checklist : ses niveaux et ses contrôleurs qu'elle n'a pas encore. */
  function dcQuiCible(t, c) {
    const ts = dcTachesDe(c).filter(x => x.id !== t.id);
    const Q = ts.map(x => S.qui && S.qui[x.id]).filter(Boolean);
    if (!ts.length || Q.length < ts.length) { return { niveaux: [], controleurs: [], texte: '', vide: !ts.length, lecture: ts.length > 0 }; }
    const moi = (S.qui && S.qui[t.id]) || { fait: [], controle: [] };
    const cle = x => x.type === 'self' ? 'self' : 'p' + x.positionId;
    const nv = new Map(); Q.forEach(q => q.fait.forEach(n => { if (!nv.has(n.id)) { nv.set(n.id, n); } }));
    const ct = new Map(); Q.forEach(q => q.controle.forEach(x => { if (!ct.has(cle(x))) { ct.set(cle(x), x); } }));
    const parPoste = {}; nv.forEach(n => { const p = n.poste || n.nom; parPoste[p] = (parPoste[p] || 0) + 1; });
    const ctl = [...ct.values()].map(dcNomCtl);
    return {
      niveaux: [...nv.values()].filter(n => !moi.fait.some(m => m.id === n.id)).map(n => ({ id: n.id, route: n.route || '' })),
      controleurs: [...ct.values()].filter(x => !moi.controle.some(y => cle(y) === cle(x))).map(x => x.type === 'self' ? { type: 'self' } : { type: 'position', positionId: x.positionId }),
      texte: 'Fait par ' + (Object.entries(parPoste).map(([p, k]) => p + (k > 1 ? ` (${k} niveaux)` : '')).join(' · ') || 'personne') + ' · Contrôlé par ' + (ctl[0] || 'personne') + (ctl.length > 1 ? ' +' + (ctl.length - 1) : ''),
      vide: false, lecture: false };
  }
  /** La vignette « Ranger → AC-01 ▾ » d'une tâche qui n'est dans aucune checklist. */
  function dcRanger(t) {
    const s = dcSuggestions(t); const c0 = s.top[0] && s.top[0].c;
    const ouvert = S.dd && S.dd.k === 'ranger' && S.dd.id === t.id;
    const lib = c0 ? '→ ' + esc(dcCode(c0.nom).code || dcCode(c0.nom).titre) : 'dans…';
    return `<span class="dc-ancre dc-rg"><button class="dc-rg-v${ouvert ? ' open' : ''}" data-a="ddOuvrir" data-k="ranger" data-t="${t.id}" data-c="" aria-haspopup="listbox" aria-expanded="${ouvert}" title="${esc(c0 ? 'Ranger dans une checklist — suggestion : ' + c0.nom : 'Ranger dans une checklist')}"><b>Ranger</b>${lib}${dcFleche}</button>${ouvert ? dcListe() : ''}</span>`;
  }
  async function dcRangerEnvoyer() {
    const d = S.dd; const t = d && tache(d.id); const c = d && checklist(d.cible);
    if (!t || !c) { return; }
    const q = d.qui ? dcQuiCible(t, c) : null;
    const code = dcCode(c.nom).code || dcCode(c.nom).titre;
    d.envoi = true; d.err = null; rendre();
    let r = null;
    try { r = await api('POST', '/task/ranger', { taskId: t.id, checklistId: c.id, reactiver: t.actif === false && !!d.reactiver, niveaux: q ? q.niveaux : [], controleurs: q ? q.controleurs : [] }); }
    catch (e) { r = e.data || null; d.err = e.message + (e.status === 403 && !admin() ? ' Essayez avec un compte administrateur du panel (en haut).' : ''); }
    const ops = (r && r.operations) || [];
    const range = !!(ops[0] && ops[0].ok);
    if (range) {
      c.taches.push({ taskId: t.id, ordre: (r && r.ordre) || c.taches.length + 1, note: '' });
      S.cadre.horsChecklist = (S.cadre.horsChecklist || []).filter(id => id !== t.id);
      if (ops.some(o => /\/active$/.test(o.chemin || '') && o.ok)) { t.actif = true; }
      if (r && r.qui) { Object.assign(S.qui = S.qui || {}, r.qui); }
      S.dcSugg = null;
      const fait = [ops.some(o => /\/active$/.test(o.chemin || '') && o.ok) ? 'réactivée' : '', q && q.niveaux.length ? q.niveaux.length + ' niveau' + (q.niveaux.length > 1 ? 'x' : '') : '', q && q.controleurs.length ? q.controleurs.length + ' contrôleur' + (q.controleurs.length > 1 ? 's' : '') : ''].filter(Boolean).join(', ');
      S.dcRange = { ko: !!d.err, cid: c.id, t: `« ${t.nom} » rangée dans ${c.nom}${fait ? ' (' + fait + ')' : ''}.${d.err ? ' Attention : ' + d.err : ''}` };
      if (S.dd === d) { S.dd = null; }
      dcFlash([t.id], d.err ? '✓ rangée, en partie' : '✓ rangée ici', !!d.err);
    } else if (S.dd !== d) { S.dcRange = { ko: true, cid: null, t: `« ${t.nom} » n’a pas été rangée : ${d.err || 'refus du panel'}` }; }
    d.envoi = false;
    rendre();
  }

  /* --- changer une tâche de checklist (09/11/2026, « je dois pouvoir changer les tâches de checklist facilement ») ---
   * Sur chaque ligne d'une checklist, la vignette « Checklist 2.1.01 ▾ » : on choisit l'autre checklist, la tâche y
   * part (POST /task/scinder, retirée de celle-ci, vérifiée). Cochées à plusieurs dans la même checklist, elles partent
   * ensemble ; la barre de sélection a aussi « Changer de checklist… ». « Copier » la laisse aussi dans celle-ci. */
  /** Les tâches qu'emporte un déplacement : les cochées de la checklist si la ligne l'est (ou depuis la barre), sinon la ligne. */
  function dpIds(id, c) {
    const dans = dcTachesDe(c).map(t => t.id);
    const cochees = dans.filter(x => S.sel.has(x));
    return id == null ? cochees : S.sel.has(id) && cochees.length > 1 ? cochees : [id];
  }
  function dcDeplacer(t, c) {
    const ouvert = S.dd && S.dd.k === 'deplacer' && S.dd.id === t.id && S.dd.c === c.id;
    const code = dcCode(c.nom).code || 'checklist';
    return `<span class="dc-ancre dc-dp"><button class="dc-dp-v${ouvert ? ' open' : ''}" data-a="ddOuvrir" data-k="deplacer" data-t="${t.id}" data-c="${c.id}" aria-haspopup="listbox" aria-expanded="${ouvert}" title="Changer cette tâche de checklist (elle est dans ${esc(c.nom)})" aria-label="Changer de checklist"><span aria-hidden="true">⇄</span><span class="dc-code" style="background:${dcCouleur(c.poste)}">${esc(code)}</span>${dcFleche}</button>${ouvert ? dcListe() : ''}</span>`;
  }
  async function dcDeplacerEnvoyer() {
    const d = S.dd; const src = d && checklist(d.c); const c = d && checklist(d.cible);
    if (!src || !c || !d.ids.length || d.envoi) { return; }
    // Une tâche par appel (09/11/2026, « ça ne fonctionne pas ») : chaque déplacement est court (ajout, retrait, relecture),
    // l'écran montre où il en est, et un refus dit quelle tâche, sans perdre celles déjà parties.
    d.envoi = true; d.err = null; d.fait = 0; rendre();
    const sc = dcCode(src.nom).code || src.nom, vers = dcCode(c.nom).code || c.nom;
    const faites = [];
    for (const id of d.ids) {
      try {
        const r = await api('POST', '/task/scinder', { source: src.id, taches: [id], vers: c.id, retirer: !d.copier });
        if (!r || !r.ok) { throw Object.assign(new Error((r && r.error) || 'refus du panel'), { data: r }); }
        faites.push(id); d.fait = faites.length;
        let ordre = Math.max(0, ...c.taches.map(l => +l.ordre || 0));
        if (!c.taches.some(l => l.taskId === id)) { c.taches.push({ taskId: id, ordre: ordre + 1, note: '' }); }
        if (!d.copier) { src.taches = src.taches.filter(l => l.taskId !== id); }
        S.sel.delete(id);
        if (S.dd === d) { rendre(); }
      } catch (e) {
        const t = tache(id);
        d.err = '« ' + ((t && t.nom) || '#' + id) + ' » : ' + e.message + (e.status === 401 || e.status === 403 ? (admin() ? ' — la session administrateur du panel a peut-être expiré : reconnectez-vous (en haut).' : ' — essayez avec un compte administrateur du panel (en haut).') : '');
        break;
      }
    }
    d.envoi = false;
    const n = faites.length, tout = n === d.ids.length;
    const quoi = n > 1 ? n + ' tâches' : n === 1 ? '« ' + ((tache(faites[0]) || {}).nom || 'la tâche') + ' »' : '';
    const ok = n ? `${esc(quoi)} ${d.copier ? 'copiée' + (n > 1 ? 's' : '') + ' dans' : 'déplacée' + (n > 1 ? 's' : '') + ' de ' + esc(sc) + ' vers'} ${esc(vers)}.` : '';
    S.dcSugg = null;
    if (n) { dcFlash(faites, (d.copier ? '✓ copiée' : '✓ arrivée') + ' ici', !tout); }
    if (tout) {
      S.dd = null;
      S.msg = { k: 'ok', t: `${ok} <button class="tk-lien" data-a="dcAller" data-id="${c.id}">voir ${esc(vers)}</button>` };
    } else {
      d.ids = d.ids.filter(id => !faites.includes(id));
      S.msg = { k: 'ko', t: (ok ? ok + ' ' : '') + 'Arrêt : ' + esc(d.err) };
    }
    rendre();
  }

  /* --- les vignettes --- */
  const dcFleche = '<i class="dc-car" aria-hidden="true">▾</i>';
  function dcVignette(k, t, c) {
    const ouvert = S.dd && S.dd.k === k && S.dd.id === t.id && S.dd.c === (c ? c.id : null);
    const at = `data-a="ddOuvrir" data-k="${k}" data-t="${t.id}" data-c="${c ? c.id : ''}" aria-haspopup="listbox" aria-expanded="${ouvert}"`;
    const cl = 'dc-v' + (ouvert ? ' open' : '');
    if (k === 'heure') { return `<button class="${cl} dc-heure${t.heure ? '' : ' off'}" ${at} title="Changer l’heure">${esc(t.heure || 'sans heure')}${dcFleche}</button>`; }
    if (k === 'jours') { const q = quandAff(t); return `<button class="${cl}" ${at} title="Changer les jours">${esc(q.charAt(0).toLowerCase() + q.slice(1))}${dcFleche}</button>`; }
    if (k === 'photo') { return `<button class="${cl} ${t.photo ? 'ph' : 'off'}" ${at} title="Photo requise ou non">${t.photo ? CAM + ' photo' : 'sans photo'}${dcFleche}</button>`; }
    if (k === 'oblig') { return `<button class="${cl} ${t.obligatoire ? 'ob' : 'off'}" ${at} title="Obligatoire ou facultative">${t.obligatoire ? 'obligatoire' : 'facultative'}${dcFleche}</button>`; }
    const q = S.qui && S.qui[t.id];
    if (k === 'fait') {
      if (!q) { return `<span class="dc-b fait att"><b>Fait</b>…</span>`; }
      const postes = [...new Set(q.fait.map(n => n.poste || n.nom))];
      const tout = q.fait.map(n => nomNiv(n)).join(', ');
      return `<button class="${cl.replace('dc-v', 'dc-b')} fait${q.fait.length ? '' : ' vide'}" ${at} title="${esc(tout ? 'Fait par : ' + tout : 'Aucun niveau : aucun employé ne voit cette tâche')}"><b>Fait</b><span class="dc-bt">${q.fait.length ? esc(dcCourt(postes[0])) : 'personne'}</span>${postes.length > 1 ? `<em>+${postes.length - 1}</em>` : ''}${dcFleche}</button>`;
    }
    if (!q) { return `<span class="dc-b ctrl att"><b>Contrôle</b>…</span>`; }
    if (q.erreur) { return `<span class="dc-b ctrl att" title="${esc(q.erreur)}"><b>Contrôle</b>illisible</span>`; }
    const tout = q.controle.map(dcNomCtl).join(', ');
    return `<button class="${cl.replace('dc-v', 'dc-b')} ctrl${q.controle.length ? '' : ' vide'}" ${at} title="${esc(tout ? 'Contrôlé par : ' + tout : 'Personne ne contrôle cette tâche')}"><b>Contrôle</b><span class="dc-bt">${q.controle.length ? esc(dcCourt(dcNomCtl(q.controle[0]))) : 'personne'}</span>${q.controle.length > 1 ? `<em>+${q.controle.length - 1}</em>` : ''}${dcFleche}</button>`;
  }
  /** Sous le titre d'une checklist : qui fait, qui contrôle l'ensemble de ses tâches — un clic change toute la checklist. */
  function dcQuiChecklist(c) {
    const ts = dcTachesDe(c);
    if (!ts.length) { return ''; }
    const Q = ts.map(t => S.qui && S.qui[t.id]);
    if (Q.some(q => !q)) { return `<div class="dc-qui"><span class="dc-b fait att"><b>Fait par</b>lecture…</span><span class="dc-b ctrl att"><b>Contrôlé par</b>lecture…</span></div>`; }
    const parPoste = {};
    Q.forEach(q => q.fait.forEach(n => { const p = n.poste || n.nom; (parPoste[p] = parPoste[p] || new Set()).add(n.id); }));
    const fait = Object.entries(parPoste).map(([p, s]) => p + (s.size > 1 ? ` (${s.size} niveaux)` : '')).join(' · ');
    const ctl = [...new Set(Q.flatMap(q => q.controle.map(dcNomCtl)))].join(' · ');
    const ouv = k => S.dd && S.dd.k === k && S.dd.id == null && S.dd.c === c.id;
    const at = k => `data-a="ddOuvrir" data-k="${k}" data-t="" data-c="${c.id}" aria-haspopup="listbox"`;
    return `<div class="dc-qui"><span class="dc-ancre"><button class="dc-b fait${ouv('fait') ? ' open' : ''}${fait ? '' : ' vide'}" ${at('fait')} title="Changer qui fait toutes les tâches de la checklist"><b>Fait par</b>${esc(fait || 'personne')}${dcFleche}</button>${ouv('fait') ? dcListe() : ''}</span>
      <span class="dc-ancre"><button class="dc-b ctrl${ouv('ctrl') ? ' open' : ''}${ctl ? '' : ' vide'}" ${at('ctrl')} title="Changer qui contrôle toutes les tâches de la checklist"><b>Contrôlé par</b>${esc(ctl || 'personne')}${dcFleche}</button>${ouv('ctrl') ? dcListe() : ''}</span></div>`;
  }

  /* --- une ligne, une section, le document --- */
  function dcLigne(t, c, code) {
    const cid = c ? c.id : null;
    const liens = (((S.cadre || {}).sopLiens || {})[t.id] || []).map(y => `<a class="tk-pas" href="../formation/#/sop/${encodeURIComponent(y.sopId)}" target="_blank" rel="noopener" title="${esc(y.titre)}">${esc(y.code || 'SOP')}${y.etape ? ' · ' + esc(y.etape) : ''}</a>`).join(' ');
    const ici = S.dd && S.dd.id === t.id && S.dd.c === cid;
    const flash = S.dcFlash && S.dcFlash.ids.includes(t.id) ? `<span class="dc-flash${S.dcFlash.ko ? ' ko' : ''}">${esc(S.dcFlash.t)}</span>` : '';
    const dbl = !c ? dcSuggestions(t).dbl : null;
    const doublon = dbl ? ` <span class="dc-dbl" title="${esc('Ressemble à « ' + dbl.t.nom + ' », déjà dans ' + dbl.c.nom)}">≈ déjà dans ${esc(dcCode(dbl.c.nom).code || dbl.c.nom)}</span>` : '';
    return `<div class="dc-l${S.sel.has(t.id) ? ' sel' : ''}${t.actif === false ? ' pause' : ''}${ici ? ' ici' : ''}">
      <input type="checkbox" class="dc-cb" data-a="coche" data-id="${t.id}" ${S.sel.has(t.id) ? 'checked' : ''} aria-label="Cocher ${esc(t.nom)}">
      <span class="dc-nom"><button data-a="modifier" data-id="${t.id}" title="Modifier le nom, la description… (tâche #${t.id})">${esc(dcSansCode(t.nom, code))}</button>${c ? ' ' + dcDeplacer(t, c) : ''}${t.actif === false ? ' <span class="tk-pas att">en pause</span>' : ''}${doublon}${liens ? ' ' + liens : ''}${flash}</span>
      ${c ? '' : dcRanger(t)}<span class="dc-props">${['heure', 'jours', 'photo', 'oblig', 'fait', 'ctrl'].map(k => `<span class="dc-ancre">${dcVignette(k, t, c)}${ici && S.dd.k === k ? dcListe() : ''}</span>`).join('')}</span></div>`;
  }
  /* --- filtre par poste (10/11/2026, « dans Document, un filtre par poste ») : la zone de la checklist, et la fonction
   * qui fait la tâche (« Fait par »). Les deux se combinent ; une checklist sans tâche visible disparaît. --- */
  const dcFctOk = t => { if (!S.dcFct) { return true; } const q = S.qui && S.qui[t.id]; return !!q && q.fait.some(n => (n.poste || n.nom) === S.dcFct); };
  const dcTachesVues = c => dcTachesDe(c).filter(dcFctOk);
  const dcFiltre = () => !!(S.dcZone || S.dcFct);
  function dcFiltres() {
    const zones = {}; checklists().forEach(c => { const p = c.poste || 'Sans poste'; zones[p] = (zones[p] || 0) + dcTachesVues(c).length; });
    const rang = p => { const i = PL_ORDRE.indexOf(sansAccent(p)); return i < 0 ? (p === 'Sans poste' ? 99 : 50) : i; };
    const Z = Object.keys(zones).sort((a, b) => rang(a) - rang(b) || a.localeCompare(b, 'fr'));
    const fct = {}; taches().forEach(t => { const q = S.qui && S.qui[t.id]; if (q) { [...new Set(q.fait.map(n => n.poste || n.nom))].forEach(f => { fct[f] = (fct[f] || 0) + 1; }); } });
    const F = Object.keys(fct).sort((a, b) => fct[b] - fct[a] || a.localeCompare(b, 'fr'));
    const lecture = S.dcQui && S.dcQui.enCours;
    const chip = (v, lib, n, coul) => `<button class="dc-fz${(S.dcZone || '') === v ? ' on' : ''}" data-a="dcZone" data-v="${esc(v)}">${coul ? `<i style="background:${coul}"></i>` : ''}${esc(lib)}${n != null ? `<em>${n}</em>` : ''}</button>`;
    return `<div class="dc-filtres"><span class="dc-fl">Zone</span>${chip('', 'Toutes')}${Z.map(z => chip(z, posteAff(z), zones[z], dcCouleur(z))).join('')}
      <span class="dc-fl" style="margin-left:10px">Fait par</span><select class="tk-in dc-fsel" data-dcf="fct"${lecture && !F.length ? ' disabled' : ''}><option value="">${lecture && !F.length ? 'lecture…' : 'tous les postes'}</option>${F.map(f => `<option value="${esc(f)}"${S.dcFct === f ? ' selected' : ''}>${esc(f)} (${fct[f]})</option>`).join('')}</select>
      ${dcFiltre() ? '<button class="tk-lien" data-a="dcFiltreRaz">tout montrer</button>' : ''}</div>`;
  }
  function dcSection(c) {
    const { code, titre } = dcCode(c.nom);
    const ts = dcTachesVues(c);
    const js = [...new Set(ts.flatMap(dcJoursDe))];
    const nPh = ts.filter(t => t.photo).length;
    const sel = ts.some(t => S.sel.has(t.id)) ? barreSelection(c) : '';
    return `<section class="dc-s${String(S.cl) === String(c.id) ? ' vise' : ''}" id="dc-${c.id}">
      <h2 class="dc-h2">${code ? `<span class="dc-code" style="background:${dcCouleur(c.poste)}">${esc(code)}</span>` : ''}<span>${esc(titre)}</span>
        <button class="tk-btn ghost mini" data-a="clModifier" data-id="${c.id}" title="Nom, description, zone de production, heure de la checklist">✎ Modifier</button></h2>
      <div class="dc-meta"><span>🕘 ${esc(c.heure || 'sans heure')}</span>${c.poste ? `<span><i class="dc-pd" style="background:${dcCouleur(c.poste)}"></i>${esc(posteAff(c.poste))}</span>` : ''}${ts.length ? dcPoints(js) : ''}
        <span>${ts.length} tâche${ts.length > 1 ? 's' : ''}${nPh ? ` · ${CAM} ${nPh}` : ''}</span></div>
      ${dcQuiChecklist(c)}
      ${c.description && c.description.trim() !== 'Array' ? `<div class="dc-desc">${esc(c.description)}</div>` : ''}${sel}
      <div class="dc-ls">${ts.map(t => dcLigne(t, c, code)).join('') || '<div class="dc-vide">Aucune tâche dans cette checklist.</div>'}</div>
      <button class="dc-ajout" data-a="dcAjouter" data-id="${c.id}">＋ Ajouter des tâches à cette checklist</button></section>`;
  }
  function docVue() {
    const ordre = dcOrdre().filter(c => (!S.dcZone || (c.poste || 'Sans poste') === S.dcZone) && (!S.dcFct || dcTachesVues(c).length));
    const hors = S.dcZone ? [] : dcHors().filter(dcFctOk);
    const groupes = DC_MOMENTS.map(m => [m, ordre.filter(c => dcMoment(c.heure) === m)]).filter(g => g[1].length);
    const sommaire = `<nav class="tk-card dc-som" aria-label="Sommaire"><div class="tk-k dc-som-t">Sommaire · ${ordre.length} checklists</div>
      ${groupes.map(([m, L]) => `<div class="dc-som-g">${m}</div>${L.map(c => { const { code, titre } = dcCode(c.nom); return `<button class="dc-som-i${String(S.cl) === String(c.id) ? ' on' : ''}" data-a="dcAller" data-id="${c.id}">${code ? `<span>${esc(code)}</span>` : ''}${esc(titre)}<em>${dcTachesVues(c).length}</em></button>`; }).join('')}`).join('')}
      ${hors.length ? `<div class="dc-som-g">À ranger</div><button class="dc-som-i wa${S.cl === 'hors' ? ' on' : ''}" data-a="dcAller" data-id="hors">Dans aucune checklist<em>${hors.length}</em></button>` : ''}</nav>`;
    const q = S.dcQui;
    const etat = q && q.enCours ? ` · lecture de qui fait / qui contrôle… ${q.lu} / ${q.total}` : '';
    const sections = groupes.map(([m, L]) => `<div class="dc-moment">${m}</div>${L.map(dcSection).join('')}`).join('');
    const finHors = !hors.length && S.dcRange ? `<div class="dc-moment">À ranger</div><div class="dc-range${S.dcRange.ko ? ' ko' : ''}">${esc(S.dcRange.t)} Plus aucune tâche à ranger.</div>` : '';
    const horsHtml = hors.length ? `<div class="dc-moment">À ranger</div><section class="dc-s${S.cl === 'hors' ? ' vise' : ''}" id="dc-hors"><h2 class="dc-h2"><span>Dans aucune checklist</span></h2>
      <div class="dc-meta"><span class="wa">${hors.length} tâche${hors.length > 1 ? 's' : ''} que les équipes ne voient dans aucune liste du jour${hors.every(t => t.actif === false) ? ', toutes en pause' : ''} · « Ranger » les met dans une checklist</span></div>
      ${S.dcRange ? `<div class="dc-range${S.dcRange.ko ? ' ko' : ''}">${esc(S.dcRange.t)}${S.dcRange.cid ? ` <button class="tk-lien" data-a="dcAller" data-id="${S.dcRange.cid}">voir la checklist</button>` : ''}</div>` : ''}
      ${hors.some(t => S.sel.has(t.id)) ? barreSelection() : ''}<div class="dc-ls">${hors.map(t => dcLigne(t, null, '')).join('')}</div></section>` : '';
    // Les tâches en pause que le panel ne range dans aucune liste (09/11/2026) : leur checklist est inconnue, pas absente.
    const pz = new Set((S.cadre && S.cadre.enPause) || []), pause = S.dcZone ? [] : taches().filter(t => pz.has(t.id) && dcFctOk(t));
    const pauseHtml = pause.length ? `<div class="dc-moment">En pause</div><section class="dc-s" id="dc-pause"><h2 class="dc-h2"><span>En pause · checklist inconnue</span></h2>
      <div class="dc-meta"><span class="mu">${pause.length} tâche${pause.length > 1 ? 's' : ''} en pause : le panel ne les montre dans aucune checklist tant qu’elles sont en pause : leur checklist est inconnue. « Ranger » répond « déjà dans cette checklist » quand elle y est déjà.</span></div>
      <div class="dc-ls">${pause.map(t => dcLigne(t, null, '')).join('')}</div></section>` : '';
    return `<div class="dc">${sommaire}<div class="tk-card dc-doc">
      ${S.dup ? volet() : ''}
      <h1 class="dc-h1">Le cadre du réseau</h1>
      <div class="dc-sous">${ordre.length} checklists · ${dcFiltre() ? ordre.reduce((n, c) => n + dcTachesVues(c).length, 0) + ' tâches affichées sur ' + taches().length : taches().length + ' tâches'} · un clic sur une vignette pour la changer${esc(etat)}</div>
      ${dcFiltres()}
      ${q && q.err ? `<div class="tk-err">Qui fait / qui contrôle : lecture interrompue (${esc(q.err)}). <button class="tk-lien" data-a="dcQuiRelire">Réessayer</button></div>` : ''}
      ${sections}${horsHtml}${finHors}${pauseHtml}</div></div>${dcCreees()}`;
  }

  /* --- les listes --- */
  /** L'état de départ d'une liste à cocher : coché, décoché, ou « mixte » (une partie des tâches visées seulement). */
  function dcEtatQui(d) {
    const ids = dcCibles(d); const Q = ids.map(id => S.qui && S.qui[id]).filter(Boolean);
    const n = {}; const total = Q.length;
    Q.forEach(q => { const cles = d.k === 'fait' ? q.fait.map(x => 'n' + x.id) : q.controle.map(x => x.type === 'self' ? 'self' : 'p' + x.positionId); [...new Set(cles)].forEach(k => { n[k] = (n[k] || 0) + 1; }); });
    const e = {}; Object.keys(n).forEach(k => { e[k] = n[k] === total ? 'on' : 'mixte'; });
    return { e, complet: Q.length === ids.length };
  }
  function dcOuvrir(k, id, c, el) {
    if (S.dd && S.dd.k === k && S.dd.id === id && S.dd.c === c) { S.dd = null; return rendre(); }
    const d = { k, id, c, portee: id == null ? 'checklist' : 'tache', val: '', filtre: '', envoi: false, err: null };
    if (k === 'heure') { const t = tache(id); d.val = (t && t.heure) || ((checklist(c) || {}).heure) || ''; }
    if (k === 'fait' || k === 'ctrl') { const s = dcEtatQui(d); d.init = s.e; d.etat = Object.assign({}, s.e); }
    if (k === 'deplacer') { d.ids = dpIds(id, checklist(c)); d.cible = null; d.copier = false; if (!d.ids.length) { return; } }
    if (k === 'ranger') { const t = tache(id); const sg = t ? dcSuggestions(t).top : []; d.cible = sg[0] ? sg[0].c.id : null; d.reactiver = true; d.qui = true; }
    S.dd = d; rendre();
    if (k === 'heure') { const i = $.querySelector('[data-dd="heure"]'); if (i) { i.focus(); } }
    if (k === 'fait' || k === 'ranger' || k === 'deplacer') { const i = $.querySelector('[data-dd="filtre"]'); if (i) { i.focus(); } }
  }
  function dcPortee(v) {
    const d = S.dd; if (!d || d.portee === v) { return; }
    d.portee = v; d.err = null;
    if (d.k === 'fait' || d.k === 'ctrl') {
      // Les cases déjà changées restent changées ; les autres reprennent l'état des nouvelles tâches visées.
      const avant = d.etat; const touche = Object.keys(Object.assign({}, d.etat, d.init)).filter(k => (d.etat[k] || 'off') !== (d.init[k] || 'off'));
      const s = dcEtatQui(d); d.init = s.e; d.etat = Object.assign({}, s.e); touche.forEach(k => { d.etat[k] = avant[k] || 'off'; });
    }
    rendre();
  }
  function dcPied(d, appliquer) {
    const c = checklist(d.c); const n = c ? dcTachesDe(c).length : 0;
    const portee = d.id != null && c && n > 1 ? `<div class="dc-portee" role="group" aria-label="Appliquer à"><button class="${d.portee === 'tache' ? 'on' : ''}" data-a="ddPortee" data-v="tache">Cette tâche</button><button class="${d.portee === 'checklist' ? 'on' : ''}" data-a="ddPortee" data-v="checklist">Toute la checklist ${esc(dcCode(c.nom).code || '')} (${n})</button></div>` : '';
    const err = d.err ? `<div class="dc-dd-err">${esc(d.err)}</div>` : '';
    return `${err}${portee || appliquer ? `<div class="dc-dd-pied">${portee}${appliquer || ''}</div>` : ''}`;
  }
  const dcOpt = (on, lib, attrs, nb, sous) => `<button class="dc-o${on ? ' on' : ''}" role="option" aria-selected="${!!on}" ${attrs}><span class="dc-rd"></span><span>${lib}${sous ? `<small>${sous}</small>` : ''}</span>${nb != null ? `<em>${nb}</em>` : ''}</button>`;
  function dcListe() {
    const d = S.dd; const t = d.id != null ? tache(d.id) : null; const c = checklist(d.c);
    const occupe = d.envoi ? ' disabled' : '';
    const visees = dcCibles(d).map(tache).filter(Boolean);
    const tous = (f, v) => visees.length && visees.every(x => f(x) === v);
    const titre = d.id == null && c ? `<div class="dc-dd-s">Toutes les tâches de ${esc(dcCode(c.nom).code || c.nom)} (${visees.length})</div>` : '';
    let corps = '';
    if (d.k === 'deplacer') {
      const src = checklist(d.c); const f = sansAccent(d.filtre);
      const opt = c => { const cc = dcCode(c.nom); const on = String(d.cible) === String(c.id); const deja = d.ids.filter(id => c.taches.some(l => l.taskId === id)).length;
        return `<button class="dc-o dc-rg-o${on ? ' on' : ''}" role="option" aria-selected="${on}" data-a="ddCible" data-v="${c.id}"${occupe}><span class="dc-code" style="background:${dcCouleur(c.poste)}">${esc(cc.code || '—')}</span><span>${esc(cc.titre)}<small>${esc([c.heure || 'sans heure', posteAff(c.poste || ''), c.taches.length + ' tâche' + (c.taches.length > 1 ? 's' : '')].filter(Boolean).join(' · '))}</small></span>${deja ? `<span class="dc-why">déjà ${deja > 1 ? deja + ' ' : ''}dedans</span>` : ''}</button>`; };
      const L = dcOrdre().filter(c => c.id !== d.c && (!f || sansAccent(c.nom + ' ' + (c.poste || '') + ' ' + (c.heure || '')).includes(f)));
      // Les checklists du même moment de la journée d'abord : c'est là qu'une tâche change le plus souvent.
      const m0 = dcMoment(src.heure); const memes = f ? [] : L.filter(c => dcMoment(c.heure) === m0); const autres = L.filter(c => !memes.includes(c));
      const cible = checklist(d.cible); const cc = cible ? dcCode(cible.nom) : null; const sc = dcCode(src.nom).code || src.nom;
      const quoi = d.ids.length > 1 ? d.ids.length + ' tâches cochées' : '« ' + esc((tache(d.ids[0]) || {}).nom || '') + ' »';
      corps = `<div class="dc-dd-t">Changer ${quoi} de checklist</div><div class="dc-dd-s">aujourd’hui dans ${esc(sc)} · vers…</div>
        <input class="tk-in dc-dd-f" data-dd="filtre" value="${esc(d.filtre)}" placeholder="Chercher une checklist (code, nom, heure)…" autocomplete="off">
        <div class="dc-dd-l" role="listbox">${memes.length ? `<div class="dc-dd-g">${esc(m0)}</div>${memes.map(opt).join('')}` : ''}
          ${autres.length ? `<div class="dc-dd-g">${f ? 'Checklists trouvées' : memes.length ? 'Les autres checklists' : 'Les checklists'}</div>${autres.map(opt).join('')}` : ''}
          ${L.length ? '' : '<div class="dc-dd-v">Aucune checklist ne correspond.</div>'}</div>
        <div class="dc-dd-pied">
          <button class="dc-o coche ${d.copier ? 'on' : 'off'}" data-a="ddOpt" data-v="copier"${occupe}><span class="dc-cc">${d.copier ? '✓' : ''}</span><span>La garder aussi dans ${esc(sc)}<small>copier au lieu de déplacer : la tâche sera dans les deux checklists</small></span></button>
          <div class="dc-dd-v">L’heure, les jours et qui fait / qui contrôle ne changent pas : leurs vignettes, après.</div>
          ${d.err ? `<div class="dc-dd-err">${esc(d.err)}</div>` : ''}
          <button class="tk-btn prim" data-a="ddDeplacer"${cible && !d.envoi ? '' : ' disabled'}>${d.envoi ? (d.copier ? 'Copie' : 'Déplacement') + (d.ids.length > 1 ? ` ${(d.fait || 0) + 1} / ${d.ids.length}` : '') + '…' : cible ? (d.copier ? 'Copier dans ' : 'Déplacer dans ') + esc(cc.code ? cc.code + ' · ' + cc.titre : cc.titre) : 'Choisissez une checklist'}</button></div>`;
    } else if (d.k === 'ranger') {
      const s = dcSuggestions(t); const f = sansAccent(d.filtre);
      const opt = (c, why) => { const cc = dcCode(c.nom); const on = String(d.cible) === String(c.id);
        return `<button class="dc-o dc-rg-o${on ? ' on' : ''}" role="option" aria-selected="${on}" data-a="ddCible" data-v="${c.id}"${occupe}><span class="dc-code" style="background:${dcCouleur(c.poste)}">${esc(cc.code || '—')}</span><span>${esc(cc.titre)}<small>${esc([c.heure || 'sans heure', posteAff(c.poste || ''), c.taches.length + ' tâche' + (c.taches.length > 1 ? 's' : '')].filter(Boolean).join(' · '))}</small></span>${why ? `<span class="dc-why">${esc(why)}</span>` : ''}</button>`; };
      const sugg = f ? [] : s.top;
      const autres = dcOrdre().filter(c => !sugg.some(x => x.c === c) && (!f || sansAccent(c.nom + ' ' + (c.poste || '')).includes(f)));
      const max = f ? 12 : 3;
      const cible = checklist(d.cible); const cc = cible ? dcCode(cible.nom) : null;
      const q = cible ? dcQuiCible(t, cible) : null;
      const coche = (k, lib, sous) => `<button class="dc-o coche ${d[k] ? 'on' : 'off'}" data-a="ddOpt" data-v="${k}"${occupe}><span class="dc-cc">${d[k] ? '✓' : ''}</span><span>${lib}<small>${esc(sous)}</small></span></button>`;
      corps = `<div class="dc-dd-t">Ranger « ${esc(t.nom)} » dans…</div>
        <input class="tk-in dc-dd-f" data-dd="filtre" value="${esc(d.filtre)}" placeholder="Chercher une checklist…" autocomplete="off">
        ${s.dbl ? `<div class="dc-dd-warn">≈ Ressemble à « ${esc(s.dbl.t.nom)} », déjà dans ${esc(dcCode(s.dbl.c.nom).code || s.dbl.c.nom)} : vérifiez que ce n’est pas un doublon.</div>` : ''}
        <div class="dc-dd-l" role="listbox">${sugg.length ? `<div class="dc-dd-g">Suggérées</div>${sugg.map(x => opt(x.c, x.why)).join('')}` : ''}
          <div class="dc-dd-g">${f ? 'Checklists trouvées' : sugg.length ? 'Les autres checklists' : 'Les checklists'}</div>${autres.slice(0, max).map(c => opt(c)).join('') || '<div class="dc-dd-v">Aucune checklist ne correspond.</div>'}
          ${autres.length > max ? `<div class="dc-dd-v">+ ${autres.length - max} autre${autres.length - max > 1 ? 's' : ''} : cherchez ci-dessus</div>` : ''}</div>
        <div class="dc-dd-pied">
          ${t.actif === false ? coche('reactiver', 'Réactiver la tâche', 'elle est en pause : sans ça, personne ne la voit') : ''}
          ${q && (q.niveaux.length || q.controleurs.length) ? coche('qui', 'Qui fait et qui contrôle : comme ' + esc(cc.code || cc.titre), q.texte) : ''}
          ${q && q.vide ? `<div class="dc-dd-v">${esc(cc.code || cc.titre)} n’a encore aucune tâche : après le rangement, choisissez qui fait la tâche avec sa vignette « Fait ».</div>` : ''}
          ${q && q.lecture ? '<div class="dc-dd-v">Lecture de qui fait / qui contrôle cette checklist…</div>' : ''}
          ${d.err ? `<div class="dc-dd-err">${esc(d.err)}</div>` : ''}
          <button class="tk-btn prim" data-a="ddRanger"${cible && !d.envoi ? '' : ' disabled'}>${d.envoi ? 'Rangement…' : cible ? 'Ranger dans ' + esc(cc.code ? cc.code + ' · ' + cc.titre : cc.titre) : 'Choisissez une checklist'}</button></div>`;
    } else if (d.k === 'heure') {
      const nb = {}; taches().forEach(x => { if (x.heure) { nb[x.heure] = (nb[x.heure] || 0) + 1; } });
      const top = Object.keys(nb).sort((a, b) => nb[b] - nb[a]).slice(0, 8).sort();
      corps = `<div class="dc-dd-t">À quelle heure ?</div>${titre}
        <div class="dc-dd-in"><input type="time" class="tk-in" data-dd="heure" value="${esc(d.val)}"${occupe}><button class="tk-btn prim" data-a="ddHeureOk"${occupe}>${d.envoi ? 'Envoi…' : 'Appliquer'}</button></div>
        <div class="dc-dd-g">Les heures du réseau</div><div class="dc-dd-hr">${top.map(h => `<button class="${tous(x => x.heure, h) ? 'on' : ''}" data-a="ddHeure" data-v="${h}"${occupe}>${h}</button>`).join('')}</div>
        ${c && c.heure ? `<div class="dc-dd-g">La checklist</div>${dcOpt(tous(x => x.heure, c.heure), 'L’heure de la checklist · ' + esc(c.heure), `data-a="ddHeure" data-v="${esc(c.heure)}"${occupe}`)}` : ''}${dcPied(d)}`;
    } else if (d.k === 'jours') {
      const nb = {}; (((S.cadre || {}).options || {}).jours || []).forEach(o => { nb[o.valeur] = o.nb; });
      const quotidien = x => !x.jour && (!x.frequence || String(x.frequence).toUpperCase() === 'DAILY');
      const cle = x => quotidien(x) ? '' : String(x.jour || '').toUpperCase();
      corps = `<div class="dc-dd-t">Quels jours ?</div>${titre}<div role="listbox">
        ${dcOpt(tous(cle, ''), 'Tous les jours', `data-a="ddJour" data-v=""${occupe}`, nb[''])}
        ${PL_JOURS.map(([j, , fr]) => dcOpt(tous(cle, j), 'Chaque ' + fr, `data-a="ddJour" data-v="${j}"${occupe}`, nb[j] || 0)).join('')}</div>
        ${t ? `<div class="dc-dd-sep"></div><button class="dc-o" data-a="ddPlusieurs"${occupe}><span class="dc-ic">⧉</span><span>Plusieurs jours…<small>une copie de la tâche par jour choisi : le panel ne prend qu’un jour par tâche</small></span></button>` : ''}${dcPied(d)}`;
    } else if (d.k === 'photo' || d.k === 'oblig') {
      const ch = d.k === 'photo' ? 'photo' : 'obligatoire';
      corps = `<div class="dc-dd-t">${d.k === 'photo' ? 'Photo ?' : 'Obligatoire ?'}</div>${titre}<div role="listbox">
        ${dcOpt(tous(x => !!x[ch], true), d.k === 'photo' ? CAM + ' Photo requise' : 'Obligatoire', `data-a="ddBool" data-v="1"${occupe}`, null, d.k === 'photo' ? 'l’employé ne valide pas sans photo ; elle arrive au contrôle des tâches' : '')}
        ${dcOpt(tous(x => !!x[ch], false), d.k === 'photo' ? 'Sans photo' : 'Facultative', `data-a="ddBool" data-v="0"${occupe}`)}</div>${dcPied(d)}`;
    } else {
      const ch = S.quiChoix || { niveaux: [], postes: [] };
      const pret = dcEtatQui(d).complet && (S.quiChoix || null);
      const coche = (cle, lib, sous) => { const e = d.etat[cle] || 'off'; return `<button class="dc-o coche ${e}" role="option" aria-selected="${e === 'on'}" data-a="ddCoche" data-v="${esc(cle)}"${occupe}><span class="dc-cc">${e === 'on' ? '✓' : e === 'mixte' ? '–' : ''}</span><span>${esc(lib)}${sous ? `<small>${esc(sous)}</small>` : ''}</span></button>`; };
      const f = sansAccent(d.filtre);
      const ok = txt => !f || sansAccent(txt).includes(f);
      let liste = '';
      if (d.k === 'fait') {
        // Les postes déjà présents d'abord, puis ceux des magasins, puis les autres.
        const g = {}; ch.niveaux.forEach(n => { (g[n.poste || 'Autres'] = g[n.poste || 'Autres'] || []).push(n); });
        const types = {}; ch.postes.forEach(p => { types[p.nom] = p.type; });
        const rang = p => (g[p].some(n => (d.etat['n' + n.id] || 'off') !== 'off') ? 0 : types[p] === 'EMPLOYEE' ? 1 : 2);
        const postes = Object.keys(g).sort((a, b) => rang(a) - rang(b) || a.localeCompare(b, 'fr'));
        liste = postes.map(p => { const L = g[p].filter(n => ok(p + ' ' + n.nom)); return L.length ? `<div class="dc-dd-g">${esc(p)}</div>${L.map(n => coche('n' + n.id, n.nom.replace(/\(\s+/g, '(').replace(/\s+\)/g, ')'))).join('')}` : ''; }).join('');
      } else {
        const grp = [['CONSULTANT', 'Consultants'], ['ADMIN', 'Direction'], ['EMPLOYEE', 'Magasin']];
        const autres = ch.postes.filter(p => !grp.some(x => x[0] === p.type));
        liste = (ok('auto-controle la personne qui la fait') ? coche('self', 'Auto-contrôle', 'la personne qui la fait vérifie elle-même') : '')
          + grp.concat([['', 'Autres']]).map(([ty, lib]) => { const L = (ty ? ch.postes.filter(p => p.type === ty) : autres).filter(p => ok(p.nom)); return L.length ? `<div class="dc-dd-g">${lib}</div>${L.map(p => coche('p' + p.id, p.nom)).join('')}` : ''; }).join('');
      }
      const n = dcQuiOps(d).length;
      corps = `<div class="dc-dd-t">${d.k === 'fait' ? 'Qui fait cette tâche ?' : 'Qui la contrôle ?'}</div>${titre}
        <input class="tk-in dc-dd-f" data-dd="filtre" value="${esc(d.filtre)}" placeholder="${d.k === 'fait' ? 'Chercher un poste ou un niveau…' : 'Chercher un poste…'}" autocomplete="off">
        <div class="dc-dd-l" role="listbox" aria-multiselectable="true">${pret ? (liste || '<div class="dc-dd-v">Rien ne correspond.</div>') : '<div class="dc-dd-v">Lecture de qui fait / qui contrôle…</div>'}</div>
        ${dcPied(d, `<button class="tk-btn prim" data-a="ddQuiOk"${n && !d.envoi ? '' : ' disabled'}>${d.envoi ? 'Envoi…' : n ? `Appliquer (${n} changement${n > 1 ? 's' : ''})` : 'Appliquer'}</button>`)}`;
    }
    return `<div class="dc-dd${d.k === 'fait' || d.k === 'ctrl' ? ' large' : d.k === 'ranger' || d.k === 'deplacer' ? ' rg' : ''}" role="dialog" aria-label="Choisir">${corps}</div>`;
  }

  /* --- les écritures --- */
  /** Les changements à envoyer : seules les cases que l'on a changées, tâche par tâche. */
  function dcQuiOps(d) {
    if (!d.etat) { return []; }
    const cles = Object.keys(Object.assign({}, d.etat, d.init)).filter(k => (d.etat[k] || 'off') !== (d.init[k] || 'off') && (d.etat[k] || 'off') !== 'mixte');
    const ops = [];
    dcCibles(d).forEach(id => {
      const q = S.qui && S.qui[id]; if (!q) { return; }
      cles.forEach(k => {
        const veut = d.etat[k] === 'on';
        if (d.k === 'fait') {
          const lid = +k.slice(1); const la = q.fait.find(n => n.id === lid);
          if (veut && !la) { ops.push({ taskId: id, ajouterNiveau: lid }); }
          if (!veut && la) { ops.push({ taskId: id, retirerNiveau: lid, route: la.route || '' }); }
        } else {
          const la = k === 'self' ? q.controle.find(x => x.type === 'self') : q.controle.find(x => x.type === 'position' && x.positionId === +k.slice(1));
          if (veut && !la) { ops.push({ taskId: id, ajouterControleur: k === 'self' ? { type: 'self' } : { type: 'position', positionId: +k.slice(1) } }); }
          if (!veut && la) { ops.push({ taskId: id, retirerControleur: la.id }); }
        }
      });
    });
    return ops;
  }
  function dcFlash(ids, txt, ko) {
    S.dcFlash = { ids, t: txt, ko: !!ko };
    clearTimeout(S.dcFlashT);
    S.dcFlashT = setTimeout(() => { S.dcFlash = null; if (S.vue === 'doc') { rendre(); } }, ko ? 9000 : 2600);
  }
  /** La fin d'un envoi : la liste se ferme si c'est toujours elle qui est ouverte ; une erreur, si on l'a déjà
   * quittée, s'affiche sur les lignes touchées plutôt que de se perdre. */
  function dcFin(d, ids, okIds) {
    if (!d.err) { if (S.dd === d) { S.dd = null; } dcFlash(okIds, '✓ enregistré'); }
    else if (S.dd !== d) { dcFlash(ids, '✗ ' + d.err, true); }
    rendre();
  }
  /** Heure, jours, photo, obligatoire : un PATCH par tâche visée, côté serveur en un seul appel. */
  async function dcChamps(champs, lib) {
    const d = S.dd; const ids = dcCibles(d);
    const c = checklist(d.c);
    if (ids.length > 1 && !confirm(`${lib} pour les ${ids.length} tâches de « ${c ? c.nom : ''} » ? Le changement part au panel de production.`)) { return; }
    d.envoi = true; d.err = null; rendre();
    let oks = ids;
    try { await api('PATCH', '/task/taches', { ids, champs }); }
    catch (e) {
      oks = ((e.data && e.data.resultats) || []).filter(r => r.ok).map(r => r.id);
      const ko = ((e.data && e.data.resultats) || []).filter(r => !r.ok);
      d.err = e.message + (ko.length ? ' — ' + ko.slice(0, 3).map(r => '#' + r.id + ' : ' + r.erreur).join(' ; ') : '') + (e.status === 403 && !admin() ? ' Essayez avec un compte administrateur du panel (en haut).' : '');
    }
    oks.forEach(id => { const t = tache(id); if (!t) { return; }
      if ('heure' in champs) { t.heure = champs.heure; }
      if ('jour' in champs) { t.jour = champs.jour; }
      if ('frequence' in champs) { t.frequence = champs.frequence; }
      if ('photo' in champs) { t.photo = !!champs.photo; }
      if ('obligatoire' in champs) { t.obligatoire = !!champs.obligatoire; } });
    d.envoi = false;
    dcFin(d, ids, oks);
  }
  async function dcQuiAppliquer() {
    const d = S.dd; const ops = dcQuiOps(d);
    if (!ops.length) { S.dd = null; return rendre(); }
    d.envoi = true; d.err = null; rendre();
    const ids = [...new Set(ops.map(o => o.taskId))];
    try { const r = await api('POST', '/task/qui/lot', { ops }); Object.assign(S.qui, r.qui || {}); }
    catch (e) {
      if (e.data && e.data.qui) { Object.assign(S.qui, e.data.qui); }
      d.err = e.message + (e.status === 403 && !admin() ? ' Essayez avec un compte administrateur du panel (en haut).' : '');
      // Ce qui est passé est relu : l'état de départ suit le panel, les cases encore à envoyer restent cochées.
      const s = dcEtatQui(d); d.init = s.e;
    }
    d.envoi = false;
    dcFin(d, ids, ids);
  }
  /** Faire défiler jusqu'à une section — dans le cockpit, c'est lui qui défile (le cadre a la hauteur du contenu). */
  function dcAller(id) {
    const el = document.getElementById('dc-' + id);
    if (!el) { return; }
    const y = Math.round(el.getBoundingClientRect().top + window.scrollY - 12);
    if (EMBED && window.parent !== window) { try { window.parent.postMessage({ tkVoir: Math.max(1, y) }, location.origin); } catch (e) { /* cockpit absent */ } return; }
    window.scrollTo({ top: y, behavior: 'smooth' });
  }

  /* --- l'onglet Checklists (08/11/2026, « un onglet pour la gestion des noms et des paramètres des checklists ») ---
   * Une ligne par checklist, ses réglages du panel modifiables sur place : code et nom (le nom du panel est
   * « CODE · Nom »), zone de production, heure, description. Les changements s'accumulent, puis partent d'un coup
   * (PATCH …/task/checklists/{id}, un par checklist modifiée). En face de chaque checklist : ce qu'elle contient
   * et les points d'attention (sans code, code en double, sans heure, vide…).
   */
  // Le code de la nomenclature (08/11/2026) : zone.moment.numéro, « 1.1.01 ». Les anciens (AC-01, CO-02…) se lisent encore.
  const CK_CODE = /^[0-9]\.[1-9]\.\d{2}$/;
  const CK_ANCIEN = /^[A-Z]{2,5}(?:-[A-Z0-9]{1,6}){1,3}$/;
  /** Les réglages d'une checklist tels que le panel les a, et le séparateur de son nom (« · », « — », « - »). */
  function ckOrig(c) {
    const m = String(c.nom || '').match(/^\s*([0-9]\.[1-9]\.\d{2}|[A-Z]{2,5}(?:-[A-Z0-9]{1,6}){1,3})(\s+[·—–:-]\s+)(.+)$/);
    return { code: m ? m[1] : '', sep: m ? m[2] : ' · ', titre: m ? m[3] : String(c.nom || ''), posteId: String(((c.brut || {}).workstation_id) || ''),
      heure: c.heure || '', description: c.description || '' };
  }
  const ckVal = c => Object.assign(ckOrig(c), (S.ck && S.ck.edits[c.id]) || {});
  const ckNom = v => v.code ? v.code + v.sep + v.titre.trim() : v.titre.trim();
  /** Ce qui a changé pour une checklist : le corps de PATCH /task/checklists/{id}, ou null. */
  function ckDiff(c) {
    const o = ckOrig(c); const v = ckVal(c); const b = {};
    if (ckNom(v) !== ckNom(o)) { b.nom = ckNom(v); }
    if (v.posteId !== o.posteId) { b.posteId = +v.posteId || 0; }
    if (v.heure !== o.heure) { b.heure = v.heure; }
    if (v.description !== o.description) { b.description = v.description; }
    return Object.keys(b).length ? b : null;
  }
  function ckAttention(c, tous) {
    const v = ckVal(c); const a = [];
    const ts = dcTachesDe(c);
    if (!v.code) { a.push(['wa', 'sans code']); } else if (CK_ANCIEN.test(v.code)) { a.push(['wa', 'ancien code']); } else if (!CK_CODE.test(v.code)) { a.push(['ko', 'code non reconnu (zone.moment.numéro : 1.1.01)']); }
    if (v.code && tous.filter(x => x.code === v.code).length > 1) { a.push(['ko', 'code en double']); }
    if (v.titre.trim() && tous.filter(x => sansAccent(x.titre) === sansAccent(v.titre)).length > 1) { a.push(['wa', 'nom en double']); }
    if (!v.titre.trim()) { a.push(['ko', 'nom vide']); }
    if (!v.heure) { a.push(['wa', 'sans heure']); }
    if (!ts.length) { a.push(['wa', 'aucune tâche']); }
    if (v.description.trim() === 'Array') { a.push(['ko', 'description cassée (« Array »)']); }
    if (ts.length && ts.every(t => t.actif === false)) { a.push(['wa', 'toutes ses tâches en pause']); }
    return a;
  }
  function ckVue() {
    S.ck = S.ck || { edits: {}, res: {}, filtre: '', envoi: false };
    const k = S.ck;
    // Dans l'ordre des codes (1.1.01, 1.1.02… 5.6.01) ; les anciens codes ensuite.
    const cle = c => { const v = ckOrig(c); return (CK_CODE.test(v.code) ? '0' + v.code : '1' + (v.code || '~') + v.titre); };
    const L = dcOrdre().slice().sort((a, b) => cle(a).localeCompare(cle(b), 'fr', { numeric: true }));
    const tous = L.map(ckVal);
    const f = sansAccent(k.filtre);
    const vus = L.filter(c => !f || sansAccent([ckNom(ckVal(c)), c.poste, c.heure, c.description].join(' ')).includes(f));
    const postes = (S.struct && S.struct.postes) || [];
    const modifiees = L.filter(ckDiff);
    const aHarmoniser = L.filter(c => { const v = ckVal(c); return v.code && v.sep !== ' · '; });
    const n = modifiees.length;
    const barre = n || Object.keys(k.res).length ? `<div class="ck-barre${n ? '' : ' fini'}">${n
      ? `<b>${n} checklist${n > 1 ? 's' : ''} modifiée${n > 1 ? 's' : ''}</b><span class="mu">${esc(modifiees.slice(0, 4).map(c => ckVal(c).code || ckVal(c).titre).join(', '))}${n > 4 ? '…' : ''}</span><span class="sp"></span>
        <button class="tk-btn ghost" data-a="ckAnnuler"${k.envoi ? ' disabled' : ''}>Annuler les changements</button><button class="tk-btn prim" data-a="ckEnregistrer"${k.envoi ? ' disabled' : ''}>${k.envoi ? 'Enregistrement…' : `Enregistrer ${n > 1 ? 'les ' + n : ''} dans le panel`}</button>`
      : `<span>${esc(k.bilan || '')}</span>`}</div>` : '';
    const ligne = c => {
      const v = ckVal(c); const o = ckOrig(c); const d = ckDiff(c) || {};
      const ts = dcTachesDe(c); const js = [...new Set(ts.flatMap(dcJoursDe))]; const ph = ts.filter(t => t.photo).length;
      const r = k.res[c.id];
      const ch = x => d && ((x === 'nom' && 'nom' in d) || (x in d)) ? ' mod' : '';
      const cree = ((S.cadre && S.cadre.checklistsCreees) || []).some(x => x.id === c.id);
      const optPostes = postes.length ? postes.map(p => `<option value="${p.id}" ${String(p.id) === String(v.posteId) ? 'selected' : ''}>${esc(posteAff(p.nom))}</option>`).join('')
        : `<option value="${esc(v.posteId)}">${esc(posteAff(c.poste || '—'))}</option>`;
      return `<tr class="${ckDiff(c) ? 'mod' : ''}">
        <td><span class="ck-pd" style="background:${dcCouleur(c.poste)}"></span></td>
        <td><button type="button" class="ck-codeb${v.code !== o.code ? ' mod' : ''}${k.cp === c.id ? ' open' : ''}" data-a="ckCodeOuvrir" data-id="${c.id}" style="--z:${CK_CODE.test(v.code) ? ckZc(v.code[0]) : '#5c5852'}" aria-label="Code de ${esc(c.nom)} : changer">${esc(v.code || 'code')} ▾</button></td>
        <td><input class="tk-in ck-titre${v.titre !== o.titre || v.sep !== o.sep ? ' mod' : ''}" data-ck="titre" data-i="${c.id}" value="${esc(v.titre)}" aria-label="Nom de ${esc(c.nom)}">
          ${ch('nom') ? `<small class="ck-avant">avant : ${esc(c.nom)}</small>` : ''}</td>
        <td><select class="tk-in ck-poste${ch('posteId')}" data-cks="posteId" data-i="${c.id}" aria-label="Zone de production">${optPostes}</select></td>
        <td><input type="time" class="tk-in ck-heure${ch('heure')}" data-ck="heure" data-i="${c.id}" value="${esc(v.heure)}" aria-label="Heure"></td>
        <td><textarea class="tk-in ck-desc${ch('description')}" data-ck="description" data-i="${c.id}" rows="1" aria-label="Description">${esc(v.description)}</textarea></td>
        <td class="ck-cont"><button class="tk-lien" data-a="dcVoir" data-id="${c.id}" title="Voir ses tâches dans le Document">${ts.length} tâche${ts.length > 1 ? 's' : ''}</button>${ph ? ` · ${CAM} ${ph}` : ''}<div>${ts.length ? dcPoints(js) : ''}</div></td>
        <td class="ck-att">${ckAttention(c, tous).map(([cl, t]) => `<span class="ck-a ${cl}">${esc(t)}</span>`).join('')}</td>
        <td class="ck-act">${r ? `<span class="ck-r ${r.ok ? 'ok' : 'ko'}" title="${esc(r.err || '')}">${r.ok ? '✓ enregistrée' : '✗ refusée'}</span>` : ''}
          ${ckDiff(c) ? `<button class="tk-lien" data-a="ckRetablir" data-id="${c.id}">rétablir</button>` : ''}
          <button class="tk-lien ko" data-a="supprimerCl" data-id="${c.id}" title="Supprimer cette checklist du panel (ses tâches restent dans le panel)">supprimer</button></td></tr>${k.cp === c.id ? `<tr class="ckp-tr"><td></td><td colspan="8">${ckPicker(v.code, c.id)}<div class="ckp-man">ou à la main : <input class="tk-in ck-code" data-ck="code" data-i="${c.id}" value="${esc(v.code)}" placeholder="1.1.01" spellcheck="false" aria-label="Code à la main"></div></td></tr>` : ''}`;
    };
    return `${ckNomenclature()}<div class="tk-card ck">
      <div class="tk-ct"><div><div class="tk-t15">Les checklists du réseau</div><div class="tk-sous">${L.length} checklists · nom, zone de production, heure et description, modifiables ici — rien ne part au panel avant « Enregistrer »</div></div>
        <span class="sp"></span><input class="tk-in" data-f="ckFiltre" value="${esc(k.filtre)}" placeholder="Filtrer les checklists…" style="width:220px">
        ${aHarmoniser.length ? `<button class="tk-btn" data-a="ckHarmoniser" title="Préparer « CODE · Nom » partout ; rien ne part avant « Enregistrer »">Harmoniser les noms (${aHarmoniser.length})</button>` : ''}
        <button class="tk-btn prim" data-a="wzOuvrir" data-mode="nouvelle">＋ Nouvelle checklist</button></div>
      ${barre}
      <div class="tk-defile"><table class="tk-tab ck-tab"><thead><tr><th style="width:14px"></th><th style="width:118px">Code</th><th>Nom</th><th style="width:200px">Zone de production</th><th style="width:104px">Heure</th><th style="width:26%">Description</th><th style="width:120px">Contenu</th><th style="width:190px">Points d’attention</th><th style="width:110px"></th></tr></thead>
      <tbody>${vus.map(ligne).join('') || '<tr><td colspan="9" class="mu" style="padding:16px">Aucune checklist ne correspond au filtre.</td></tr>'}</tbody></table></div>
      <div class="tk-astuce">Le nom d’une checklist au panel est « code · nom » (1.1.01 · Ouverture de la boutique). Le code est le même dans Formation et dans les SOP, et ne se traduit pas : zone (1 vente, 2 boulangerie, 3 traiteur, 4 nettoyage, 5 gestion), moment (1 ouverture, 2 journée, 3 après-midi, 4 fermeture, 5 chaque semaine, 6 chaque mois), numéro. La zone de production est celle du panel ; ses tâches et qui les fait ne changent pas ici.</div></div>`;
  }
  /** Garder un champ : le code en majuscules, sans espace. */
  /* --- Le code d'une checklist en boutons (09/11/2026, demande de Sam) ----------------------------------------------
   * « zone.moment.numéro » : la zone (qui) et le moment (quand) se choisissent en boutons, le numéro est le premier libre
   * de ce couple (01, 02…) — gardé si la checklist reste dans le même couple. Sert à l'onglet Checklists et à l'assistant. */
  const CK_ZONES = [[1, 'Vente', '#8D1D2C'], [2, 'Boulangerie', '#B0792A'], [3, 'Traiteur', '#3f7a52'], [4, 'Nettoyage', '#1f5f8b'], [5, 'Gestion', '#6B4FBB'], [0, 'Libre', '#5c5852']];
  const CK_MOMENTS = [[1, 'Ouverture'], [2, 'Journée'], [3, 'Après-midi'], [4, 'Fermeture'], [5, 'Chaque semaine'], [6, 'Chaque mois']];
  const ckZc = z => (CK_ZONES.find(x => x[0] === +z) || [0, '', '#5c5852'])[2];
  /** Les codes déjà pris (modifications en cours comprises), sauf celui de la checklist `sauf`. */
  function ckPris(sauf) {
    const P = new Set(checklists().filter(c => String(c.id) !== String(sauf)).map(c => (S.ck ? ckVal(c) : ckOrig(c)).code).filter(Boolean));
    if (S.wz && S.wz.cl && sauf !== 'wz') { const m = String(S.wz.cl.nom || '').match(/^\s*([0-9]\.[1-9]\.\d{2})/); if (m) { P.add(m[1]); } }
    return P;
  }
  /** Le code pour une zone et un moment : le numéro actuel s'il reste dans le même couple, sinon le premier libre. */
  function ckCodePour(z, m, actuel, sauf) {
    const a = String(actuel || '').match(/^([0-9])\.([1-9])\.(\d{2})$/);
    if (a && +a[1] === +z && +a[2] === +m) { return actuel; }
    const P = ckPris(sauf);
    for (let n = 1; n < 100; n++) { const c = z + '.' + m + '.' + String(n).padStart(2, '0'); if (!P.has(c)) { return c; } }
    return z + '.' + m + '.99';
  }
  /** Les boutons : `cible` = l'id de la checklist, ou 'wz' pour l'assistant. */
  function ckPicker(code, cible) {
    const a = String(code || '').match(/^([0-9])\.([1-9])\.(\d{2})$/), z = a ? +a[1] : null, m = a ? +a[2] : null;
    return `<div class="ckp"><div class="ckp-r"><span class="ckp-l">Qui<small>1er chiffre</small></span>${CK_ZONES.map(([v, l, c]) => `<button type="button" class="ckp-b${v === z ? ' on' : ''}" style="--z:${c}" data-a="ckCode" data-i="${cible}" data-z="${v}"><b>${v}</b>${l}</button>`).join('')}</div>
      <div class="ckp-r"><span class="ckp-l">Quand<small>2e chiffre</small></span>${CK_MOMENTS.map(([v, l]) => `<button type="button" class="ckp-b m${v === m ? ' on' : ''}" data-a="ckCode" data-i="${cible}" data-m="${v}"><b>.${v}</b>${l}</button>`).join('')}</div>
      <div class="ckp-res">Code : ${code ? `<span class="ckp-code" style="background:${ckZc(z)}">${esc(code)}</span>` : '<span class="mu">choisissez qui et quand</span>'}<span class="mu">· le numéro (${a ? esc(a[3]) : '01, 02…'}) est le premier libre pour cette zone et ce moment</span></div></div>`;
  }
  /** Un clic sur une zone ou un moment : le nouveau code de la checklist (ou de l'assistant). */
  function ckCodeClic(el) {
    const cible = el.dataset.i, wz = cible === 'wz';
    const cur = wz ? ((String(S.wz.cl.nom || '').match(/^\s*([0-9]\.[1-9]\.\d{2})/) || [])[1] || '') : ckVal(checklist(cible)).code;
    const a = String(cur).match(/^([0-9])\.([1-9])\.(\d{2})$/);
    let z = a ? +a[1] : null, m = a ? +a[2] : null;
    if (el.dataset.z != null) { z = +el.dataset.z; } if (el.dataset.m != null) { m = +el.dataset.m; }
    if (z == null) { z = 1; } if (m == null) { m = 1; }
    const code = ckCodePour(z, m, a ? cur : '', cible);
    if (wz) { const t = String(S.wz.cl.nom || '').replace(/^\s*([0-9]\.[1-9]\.\d{2}|[A-Z]{2,5}(?:-[A-Z0-9]{1,6}){1,3})\s*[·—–:-]?\s*/, ''); S.wz.cl.nom = code + ' · ' + t; S.wz.err = null; }
    else { ckSaisie(+cible, 'code', code); if (ckVal(checklist(cible)).sep !== ' · ') { ckSaisie(+cible, 'sep', ' · '); } }
    rendre();
  }
  function ckSaisie(id, champ, val) {
    S.ck.edits[id] = S.ck.edits[id] || {};
    S.ck.edits[id][champ] = champ === 'code' ? val.toUpperCase().replace(/\s+/g, '') : val;
    const c = checklist(id); if (c && !ckDiff(c)) { delete S.ck.edits[id]; }
    delete S.ck.res[id];
  }
  async function ckEnregistrer() {
    const k = S.ck; const L = dcOrdre().filter(ckDiff);
    if (!L.length) { return; }
    const vides = L.filter(c => !ckVal(c).titre.trim());
    if (vides.length) { k.bilan = ''; S.msg = { k: 'ko', t: 'Un nom de checklist ne peut pas être vide.' }; return rendre(); }
    if (!confirm(`Modifier ${L.length} checklist${L.length > 1 ? 's' : ''} dans le panel de production ?\n\n` + L.map(c => '· ' + ckNom(ckVal(c))).join('\n'))) { return; }
    k.envoi = true; k.res = {}; rendre();
    let ok = 0;
    for (const c of L) {
      const b = ckDiff(c); const v = ckVal(c);
      try {
        await api('PATCH', '/task/checklists/' + c.id, b);
        // Le cadre suit sans relecture : le nom, la zone, l'heure et la description deviennent ceux envoyés.
        c.nom = ckNom(v); c.heure = v.heure; c.description = v.description;
        if ('posteId' in b) { c.brut = Object.assign({}, c.brut, { workstation_id: +v.posteId || null }); const p = ((S.struct && S.struct.postes) || []).find(x => String(x.id) === String(v.posteId)); if (p) { c.poste = p.nom; } }
        delete k.edits[c.id]; k.res[c.id] = { ok: true }; ok++;
      } catch (e) { k.res[c.id] = { ok: false, err: e.message }; }
      rendre();
    }
    k.envoi = false;
    const ko = L.length - ok;
    k.bilan = `${ok} checklist${ok > 1 ? 's' : ''} enregistrée${ok > 1 ? 's' : ''} dans le panel${ko ? ` · ${ko} refusée${ko > 1 ? 's' : ''} (survolez « refusée » pour la raison)` : ''}.`;
    if (ko && !admin()) { S.msg = { k: 'wa', t: 'Le panel a refusé une partie des changements. Essayez avec un <button class="tk-lien" data-a="connexion">compte administrateur du panel</button>.' }; }
    rendre();
  }


  /** L'onglet s'ouvre : les zones de production du panel (pour les listes) et le plan de la nomenclature, une fois. */
  async function ckOuvrir() {
    if (!S.nomen) { ckNomCharger(); }
    if (!S.struct) { try { S.struct = await api('GET', '/task/structure'); } catch (e) { S.struct = { sousCategories: [], postes: [], erreurs: {} }; } rendre(); }
  }
  /* --- la nouvelle nomenclature : le plan et son application (src/checklists_codes.php) --- */
  async function ckNomCharger() {
    S.nomen = Object.assign(S.nomen || {}, { lecture: true, err: null }); rendre();
    try { S.nomen.plan = await api('GET', '/task/nomenclature'); } catch (e) { S.nomen.err = e.message; }
    S.nomen.lecture = false; rendre();
  }
  function ckNomenclature() {
    const N = S.nomen || {};
    const P = N.plan;
    if (!P) { return `<div class="tk-card ck-nom"><div class="tk-ct"><span class="tk-k">Nouvelle nomenclature des checklists</span><span class="mu" style="font-size:11.5px">${N.err ? esc(N.err) : 'lecture du plan…'}</span></div></div>`; }
    const t = P.totaux;
    const res = N.res;
    const OU = [['panel', 'Au panel : nom, heure, zone, ancien code dans la description'], ['fiche', 'Formation : les fiches des checklists (code et titre FR / NL)'], ['lien', 'Formation : les liens des SOP vers leurs checklists'], ['souscat', 'Référentiel : les checklists des sous-catégories']];
    const pts = res ? res.points : P.points;
    const zone = id => { const z = ((S.struct && S.struct.postes) || []).find(x => String(x.id) === String(id)); return z ? posteAff(z.nom) : '#' + id; };
    const val = (o, k) => { const v = o[k]; return k === 'posteId' ? zone(v) : Array.isArray(v) ? (v.length ? v.join(', ') : '—') : v === '' || v == null ? '—' : String(v); };
    const diff = p => p.changements ? Object.keys(p.changements).map(k => `<span class="ck-d"><b>${esc({ nom: 'nom', heure: 'heure', posteId: 'zone', description: 'description', code: 'code', titre_fr: 'titre FR', titre_nl: 'titre NL', checklists: 'liens' }[k] || k)}</b> ${p.avant && k in p.avant ? `<s>${esc(val(p.avant, k).slice(0, 70))}</s> → ` : ''}${esc(val(p.changements, k).slice(0, 90))}</span>`).join('') : '';
    const etat = p => p.ok === true ? '<span class="ck-e ok">✓ fait</span>' : p.ok === false ? `<span class="ck-e ko" title="${esc(p.erreur || '')}">✗ refusé</span>` : p.etat === 'fait' ? '<span class="ck-e ok">déjà fait</span>' : p.etat === 'bloque' ? `<span class="ck-e ko">bloqué</span>` : '<span class="ck-e wa">à faire</span>';
    const groupes = OU.map(([ou, lib]) => { const L = pts.filter(p => p.ou === ou); if (!L.length) { return ''; }
      const nf = L.filter(p => p.etat === 'afaire' && p.ok !== true).length;
      return `<details class="ck-g"${N.ouvert === ou ? ' open' : ''}><summary data-a="ckNomOuvrir" data-v="${ou}"><b>${esc(lib)}</b><span class="mu">${L.length} point${L.length > 1 ? 's' : ''}${nf ? ` · ${nf} à faire` : ''}</span></summary>
        <table class="tk-tab ck-np"><tbody>${L.map(p => `<tr><td style="width:84px">${etat(p)}</td><td style="width:330px">${esc(p.quoi)}${p.pourquoi ? `<small class="ko">${esc(p.pourquoi)}</small>` : ''}${p.erreur ? `<small class="ko">${esc(p.erreur)}</small>` : ''}</td><td>${diff(p)}</td></tr>`).join('')}</tbody></table></details>`; }).join('');
    const nb = t.afaire;
    const zones = (P.nomenclature.zones || []).map(z => `<span><b>${z.code}</b> ${esc(z.fr)}</span>`).join('');
    const moments = (P.nomenclature.moments || []).map(m => `<span><b>.${m.code}</b> ${esc(m.fr)}</span>`).join('');
    return `<div class="tk-card ck-nom"><div class="tk-ct"><div><div class="tk-t15">Nouvelle nomenclature des checklists</div>
        <div class="tk-sous">Un code « zone.moment.numéro », le même au panel, dans Formation et dans les SOP ; le nom se traduit (FR, NL, EN).</div></div><span class="sp"></span>
        <span class="ck-tot">${t.fait ? `<span class="ck-e ok">${t.fait} fait${t.fait > 1 ? 's' : ''}</span>` : ''}${nb ? `<span class="ck-e wa">${nb} à faire</span>` : ''}${t.bloque ? `<span class="ck-e ko">${t.bloque} bloqué${t.bloque > 1 ? 's' : ''}</span>` : ''}</span>
        <button class="tk-btn ghost" data-a="ckNomRelire"${N.envoi || N.lecture ? ' disabled' : ''}>↻ Relire</button>
        <button class="tk-btn prim" data-a="ckNomAppliquer"${nb && !N.envoi ? '' : ' disabled'}>${N.envoi ? 'Application…' : nb ? `Appliquer les ${nb} changements` : 'Tout est appliqué'}</button></div>
      <div class="ck-leg"><div class="ck-leg-ex"><span class="mu">Le code</span><span class="ck-leg-big"><i style="color:#8D1D2C">1</i>.<i style="color:#1f5f8b">3</i>.<i>02</i></span><span>= <b>1</b> qui (la zone) · <b>3</b> quand (le moment) · <b>02</b> le numéro, donné automatiquement · « 1.3.02 · Photos des comptoirs (après-midi) »</span></div>
        <div><span class="mu">Qui</span>${CK_ZONES.map(([v, l, c]) => `<span><b style="background:${c};color:#fff">${v}</b> ${l}</span>`).join('')}</div><div><span class="mu">Quand</span>${moments}</div>
        <div class="mu" style="width:auto;text-transform:none;letter-spacing:0">Pour changer le code d’une checklist : cliquez son code dans le tableau ci-dessous, puis choisissez qui et quand.</div></div>
      ${res ? `<div class="tk-msg ${res.ok ? 'ok' : 'ko'}" style="margin:10px 16px 0">${res.ok ? `${res.faits} changement${res.faits > 1 ? 's' : ''} appliqué${res.faits > 1 ? 's' : ''}.` : esc(res.error || 'refus')}</div>` : ''}
      ${groupes}
      ${(P.structure || []).length ? `<div class="tk-astuce"><b style="font-weight:400;color:var(--ink)">Ensuite, au panel</b> (il faut pouvoir retirer une tâche d’une checklist) : ${P.structure.map(x => esc(x.quoi)).join(' · ')}.</div>` : ''}</div>`;
  }
  async function ckNomAppliquer() {
    const N = S.nomen; const t = N.plan.totaux;
    if (!confirm(`Appliquer la nouvelle nomenclature ?\n\n${t.afaire} changement(s) : les noms des checklists au panel de production, les codes des fiches et les liens dans Formation. Les anciens codes restent écrits « (ex …) » dans la description des checklists.`)) { return; }
    N.envoi = true; N.res = null; rendre();
    try { N.res = await api('POST', '/task/nomenclature/appliquer', {}); }
    catch (e) { N.res = e.data && e.data.points ? e.data : { ok: false, error: e.message, points: N.plan.points }; }
    N.envoi = false;
    rendre();
    // Les noms du panel ont changé : le cadre et le plan sont relus.
    await charger(true);
    try { N.plan = await api('GET', '/task/nomenclature'); } catch (e) { /* le plan reste l'ancien */ }
    rendre();
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
    } else if (m.type === 'clModif') {
      const f = m.f; const postes = (S.struct && S.struct.postes) || [];
      corps = `<div class="tk-ct"><h2>Modifier la checklist #${m.id}</h2><button class="tk-x" data-a="fermer">×</button></div>
        <div class="tk-form">
          <label>Nom</label><input class="tk-in large" data-m="nom" value="${esc(f.nom)}">
          <label>Description</label><textarea class="tk-in" data-m="description" style="font-family:var(--font-ui);font-size:12px">${esc(f.description)}</textarea>
          <label>Zone de production</label><select class="tk-in" data-m="posteId">${postes.length ? '' : `<option value="${f.posteId}">${esc(posteAff(f.poste || '') || 'lecture…')}</option>`}${postes.map(p => `<option value="${p.id}" ${String(p.id) === String(f.posteId) ? 'selected' : ''}>${esc(posteAff(p.nom))}</option>`).join('')}</select>
          <label>Heure</label><input type="time" class="tk-in" data-m="heure" value="${esc(f.heure)}">
        </div>
        ${m.err ? `<div class="tk-err" style="margin:0 16px 12px">${esc(m.err)}</div>` : ''}
        <div class="tk-pied"><button class="tk-btn ghost danger" data-a="supprimerCl" data-id="${m.id}">Supprimer la checklist…</button><span class="sp"></span><button class="tk-btn prim" data-a="clEnregistrer" ${m.envoi ? 'disabled' : ''}>${m.envoi ? 'Envoi…' : 'Enregistrer dans le panel'}</button><button class="tk-btn" data-a="fermer">Annuler</button>
          <span class="mu" style="font-size:11.5px">Les tâches de la checklist ne changent pas.</span></div>`;
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
        <div class="tk-pied"><button class="tk-btn ghost" data-a="brut" data-id="${m.id}" title="Ce que le panel renvoie pour cette tâche : niveaux, contrôleurs, champs bruts">Détails du panel</button><span class="sp"></span><button class="tk-btn" data-a="modifApercu" ${m.envoi ? 'disabled' : ''}>Voir l’appel</button>
          ${m.plan && !m.plan.fait ? `<button class="tk-btn prim" data-a="modifEnvoyer" ${m.envoi ? 'disabled' : ''}>${m.envoi ? 'Envoi…' : 'Enregistrer dans le panel'}</button>` : ''}
          <button class="tk-btn" data-a="fermer">${m.plan && m.plan.fait ? 'Fermer' : 'Annuler'}</button></div>`;
    }
    const y = EMBED ? `style="margin-top:${Math.max(8, (m.y || 0) - 140)}px"` : '';
    return `<div class="tk-voile" data-a="voile"><div class="tk-modale" role="dialog" aria-modal="true" ${y}>${corps}</div></div>`;
  }

  /* --- L'onglet Revue du soir (08/11/2026) ----------------------------------------------------------------------------
   * Le réglage de l'assistant de gestion du soir du dashboard magasin : ses points (vérifiés automatiquement par une
   * sonde, ou posés en question), le texte et les boutons de réponse de chacun, les raisons rapides pour une checklist
   * pas finie. Pour chaque sonde : la route du cockpit et l'appel au panel qu'elle lit, et « Sonder » pour voir, sur un
   * magasin et un jour, si la donnée est revenue et ce qu'elle dit. Réglage : GET/PUT /soir/config ; essai : /soir/sonder. */
  const rsCopie = v => JSON.parse(JSON.stringify(v));
  async function rsOuvrir() {
    if (S.rs && S.rs.c) { return; }
    S.rs = { charge: true, err: null, test: {}, essai: { shop: '4', date: new Date().toISOString().slice(0, 10), res: null, enCours: false, un: {} }, magasins: [], ajout: {}, raison: '' };
    rendre();
    try {
      const [d, m] = await Promise.all([api('GET', '/soir/config'), api('GET', '/stores?statut=tous').catch(() => [])]);
      Object.assign(S.rs, { charge: false, base: rsCopie(d.config), c: rsCopie(d.config), defaut: d.defaut, sondes: d.sondes,
        magasins: (Array.isArray(m) ? m : []).filter(s => !s.status || /ouvert/i.test(s.status)).map(s => ({ id: String(s.id), nom: s.nom || s.name })) });
    } catch (e) { S.rs.charge = false; S.rs.err = e.message; }
    rendre();
  }
  const rsSonde = cle => ((S.rs && S.rs.sondes) || []).find(s => s.cle === cle) || null;
  const rsModifie = () => { const r = S.rs; if (!r || !r.c) { return false; } const q = x => JSON.stringify({ h: x.heure, c: x.checklists, r: x.raisons, p: x.points }); return q(r.c) !== q(r.base); };
  function rsEtat(e) {
    if (!e) { return ''; }
    if (e.enCours) { return '<span class="rs-et mu">sonde en cours…</span>'; }
    if (e.err) { return `<span class="rs-et ko">erreur : ${esc(e.err)}</span>`; }
    const cls = !e.revenue ? 'ko' : (e.etat === 'ok' ? 'ok' : 'wa');
    const lib = !e.revenue ? '✗ donnée pas revenue' : (e.etat === 'ok' ? '✓ revenue · en ordre' : '! revenue · à expliquer');
    return `<span class="rs-et ${cls}">${lib}</span><span class="rs-tx">${esc(e.texte || '')}${e.ms != null ? ` <small>· ${e.ms} ms</small>` : ''}</span>`;
  }
  function rsPoint(p, i, n) {
    const r = S.rs, s = p.type === 'auto' ? rsSonde(p.sonde) : null, e = r.essai.un[p.id];
    const params = s ? s.params.map(d => `<label class="rs-par">${esc(d.label)} <input class="tk-in" type="number" min="${d.min}" max="${d.max}" data-rs="param" data-k="${esc(d.k)}" data-i="${i}" value="${esc(p.params && p.params[d.k] != null ? p.params[d.k] : d.defaut)}"></label>`).join('') : '';
    // La route du cockpit que lit la sonde et l'appel au panel derrière : modifiables, « Tester » sur ce qui est saisi.
    const T = r.test[i], ligneT = (lib, x) => !x ? '' : `<div class="rs-t ${x.ok ? 'ok' : 'ko'}"><b>${lib}</b> ${x.ok ? '✓' : '✗'} ${x.code != null ? 'HTTP ' + esc(x.code) : ''}${x.ms != null ? ' · ' + x.ms + ' ms' : ''}${x.erreur ? ' · ' + esc(x.erreur) : ''}${x.apercu ? `<small>${esc(x.apercu)}</small>` : ''}${x.chemin ? `<small class="mu">GET ${esc(x.chemin)}</small>` : ''}</div>`;
    const api = s ? `<div class="rs-api">
        <label class="rs-ch"><span class="rs-l">Cockpit</span><span class="rs-get">GET</span><input class="tk-in${p.cockpit !== s.cockpit ? ' mod' : ''}" data-rs="cockpit" data-i="${i}" value="${esc(p.cockpit || '')}" spellcheck="false" aria-label="route du cockpit">${p.cockpit !== s.cockpit ? `<button class="tk-btn mini ghost" data-a="rsChDefaut" data-v="cockpit" data-i="${i}" title="${esc(s.cockpit)}">↺</button>` : ''}</label>
        <label class="rs-ch"><span class="rs-l">Panel</span><span class="rs-get">GET</span><input class="tk-in${(p.panel || '') !== s.panel ? ' mod' : ''}" data-rs="panel" data-i="${i}" value="${esc(p.panel || '')}" spellcheck="false" placeholder="aucun appel au panel" aria-label="appel au panel">${(p.panel || '') !== s.panel ? `<button class="tk-btn mini ghost" data-a="rsChDefaut" data-v="panel" data-i="${i}" title="${esc(s.panel || 'aucun')}">↺</button>` : ''}</label>
        <div class="rs-ligne" style="margin:4px 0 0"><span class="mu">${esc(s.aide)} · lu : ${esc(s.quand)} · variables : {shop} {date}${s.cockpit.includes('{du}') ? ' {du}' : ''}</span><span style="flex:1"></span>
          <button class="tk-btn mini prim" data-a="rsTester" data-i="${i}"${T && T.enCours ? ' disabled' : ''}>${T && T.enCours ? 'Test…' : 'Tester'}</button></div>
        ${T && !T.enCours ? `<div class="rs-tests">${T.err ? `<div class="rs-t ko">✗ ${esc(T.err)}</div>` : ligneT('Cockpit', T.cockpit) + ligneT('Panel', T.panel) + (T.verdict ? `<div class="rs-res"><b class="rs-lv">Sonde</b>${rsEtat(T.verdict)}</div>` : '')}<div class="mu" style="font-size:10.5px">testé sur ${esc((r.magasins.find(m => m.id === r.essai.shop) || { nom: 'magasin ' + r.essai.shop }).nom)} le ${esc(r.essai.date.split('-').reverse().join('/'))} (choisis à droite)</div></div>` : ''}
      </div>` : '';
    return `<div class="tk-card rs-pt${p.actif ? '' : ' off'}">
      <div class="rs-h">
        <span class="rs-ord"><button class="tk-btn mini ghost" data-a="rsMonter" data-i="${i}"${i ? '' : ' disabled'} aria-label="monter">▲</button><button class="tk-btn mini ghost" data-a="rsDescendre" data-i="${i}"${i < n - 1 ? '' : ' disabled'} aria-label="descendre">▼</button></span>
        <label class="rs-on" title="${p.actif ? 'actif : il est passé en revue' : 'désactivé'}"><input type="checkbox" data-rs="actif" data-i="${i}"${p.actif ? ' checked' : ''}> actif</label>
        <input class="tk-in rs-ico" data-rs="icone" data-i="${i}" value="${esc(p.icone || '')}" aria-label="icône" maxlength="4">
        <input class="tk-in rs-titre" data-rs="titre" data-i="${i}" value="${esc(p.titre)}" aria-label="titre du point">
        <span class="rs-type ${p.type}">${p.type === 'auto' ? 'Vérifié automatiquement' : 'Question'}</span>
        <button class="tk-btn mini ghost" data-a="rsSuppr" data-i="${i}">Supprimer</button></div>
      <div class="rs-b">
        ${p.type === 'auto' ? `<div class="rs-ligne"><label class="rs-par">Sonde <select class="tk-in" data-rss="sonde" data-i="${i}">${r.sondes.map(x => `<option value="${x.cle}"${x.cle === p.sonde ? ' selected' : ''}>${esc(x.titre)}</option>`).join('')}</select></label>${params}
</div>${api}${e && !T ? `<div class="rs-res"><b class="rs-lv">Dernière sonde</b>${rsEtat(e)}</div>` : ''}` : ''}
        <label class="rs-q"><span class="rs-l">${p.type === 'auto' ? 'La question, quand ce n’est pas en ordre' : 'La question posée chaque soir'}</span>
          <textarea class="tk-in" rows="2" data-rs="question" data-i="${i}">${esc(p.question || '')}</textarea></label>
        <div class="rs-l">Les boutons de réponse <span class="mu">— cliquez « ✓ » pour qu’une réponse compte comme « en ordre »</span></div>
        <div class="rs-reps">${(p.reponses || []).map((x, j) => `<span class="rs-rep${x.ok ? ' ok' : ''}"><button class="rs-okb" data-a="rsRepOk" data-i="${i}" data-j="${j}" title="${x.ok ? 'compte comme en ordre' : 'compte comme à suivre'}">${x.ok ? '✓' : '○'}</button>${esc(x.t)}<button class="rs-x" data-a="rsRepSuppr" data-i="${i}" data-j="${j}" aria-label="retirer">×</button></span>`).join('')}
          <span class="rs-add"><input class="tk-in" data-rs="ajout" data-i="${i}" value="${esc(r.ajout[i] || '')}" placeholder="nouvelle réponse" maxlength="40"><button class="tk-btn mini" data-a="rsRepAjout" data-i="${i}">Ajouter</button></span></div>
      </div></div>`;
  }
  function rsVue() {
    const r = S.rs;
    if (!r || r.charge) { return '<div class="tk-attente">Lecture du réglage de la revue du soir…</div>'; }
    if (r.err && !r.c) { return `<div class="tk-msg ko">La revue du soir n’a pas pu être lue : ${esc(r.err)}</div>`; }
    const c = r.c, mod = rsModifie(), auto = c.points.filter(p => p.type === 'auto');
    r.avantMod = mod;   // la barre « Enregistrer » se redessine quand cet état change pendant la saisie
    const barre = `<div class="ck-barre${mod ? '' : ' fini'}">${mod ? `<b>Réglage modifié</b><span class="mu">pas encore enregistré</span><span class="sp"></span>
        <button class="tk-btn ghost" data-a="rsAnnuler"${r.envoi ? ' disabled' : ''}>Annuler les changements</button><button class="tk-btn prim" data-a="rsEnregistrer"${r.envoi ? ' disabled' : ''}>${r.envoi ? 'Enregistrement…' : 'Enregistrer'}</button>`
      : `<span>${esc(r.bilan || (c.majLe ? 'Enregistré le ' + c.majLe.slice(8, 10) + '/' + c.majLe.slice(5, 7) + ' à ' + c.majLe.slice(11, 16) + (c.majPar ? ' par ' + c.majPar : '') : 'Réglage proposé (jamais enregistré)'))}</span><span class="sp"></span><button class="tk-btn ghost" data-a="rsDefaut">Revenir au réglage proposé</button>`}</div>`;
    const E = r.essai;
    const essai = `<div class="tk-card rs-essai"><div class="tk-ct"><b>Sonder un magasin</b><span class="mu">la donnée est-elle revenue ?</span></div><div class="rs-b">
      <div class="rs-ligne"><select class="tk-in" data-rse="shop">${(r.magasins.length ? r.magasins : [{ id: '4', nom: 'Magasin 4' }]).map(m => `<option value="${esc(m.id)}"${m.id === E.shop ? ' selected' : ''}>${esc(m.nom)}</option>`).join('')}</select>
        <input class="tk-in" type="date" data-rse="date" value="${esc(E.date)}"><button class="tk-btn prim" data-a="rsSonder"${E.enCours ? ' disabled' : ''}>${E.enCours ? 'Sonde en cours…' : 'Sonder'}</button></div>
      ${mod ? '<div class="mu" style="font-size:11.5px">La sonde lit le réglage enregistré : enregistrez d’abord pour essayer vos changements.</div>' : ''}
      ${E.err ? `<div class="tk-msg ko">${esc(E.err)}</div>` : ''}
      ${E.res ? `<div class="rs-el">${E.res.points.map(x => `<div class="rs-e"><b>${esc(x.titre)}</b>${rsEtat(x)}<code>${esc(x.cockpit || '')}</code></div>`).join('')}<div class="mu" style="font-size:11px">sondé le ${esc(E.res.le.slice(8, 10) + '/' + E.res.le.slice(5, 7) + ' à ' + E.res.le.slice(11, 19))}</div></div>`
        : `<div class="mu" style="font-size:12px">${auto.filter(p => p.actif).length} point${auto.length > 1 ? 's' : ''} vérifié${auto.length > 1 ? 's' : ''} automatiquement : chaque sonde lit sa route et dit si la donnée est revenue, et si elle est en ordre.</div>`}</div></div>`;
    return `<div class="rs">${barre}
      <div class="rs-intro tk-card"><div class="rs-b">
        <p>La <b>revue du soir</b> est l’assistant de gestion du dashboard magasin : le soir, il passe la journée en revue avec le responsable. Il n’apparaît pas dans les checklists de l’équipe. Les points <b>vérifiés automatiquement</b> lisent une donnée (une sonde) et ne posent leur question que si ce n’est pas en ordre ; les <b>questions</b> sont posées chaque soir.</p>
        <div class="rs-ligne"><label class="rs-par">Apparaît dans le dashboard à partir de <input class="tk-in" type="time" data-rs="heure" value="${esc(c.heure)}"></label>
          <label class="rs-on"><input type="checkbox" data-rs="checklists"${c.checklists ? ' checked' : ''}> passer en revue les checklists pas finies du jour</label></div>
        <div class="rs-l">Les raisons rapides pour une checklist ou une tâche pas finie</div>
        <div class="rs-reps">${c.raisons.map((x, j) => `<span class="rs-rep">${esc(x)}<button class="rs-x" data-a="rsRaisonSuppr" data-j="${j}" aria-label="retirer">×</button></span>`).join('')}
          <span class="rs-add"><input class="tk-in" data-rs="raison" value="${esc(r.raison)}" placeholder="nouvelle raison" maxlength="40"><button class="tk-btn mini" data-a="rsRaisonAjout">Ajouter</button></span></div></div></div>
      <div class="rs-g"><div>${c.points.map((p, i) => rsPoint(p, i, c.points.length)).join('')}
        <div class="rs-plus"><button class="tk-btn" data-a="rsAjout" data-v="question">+ Une question</button><button class="tk-btn" data-a="rsAjout" data-v="auto">+ Une vérification automatique</button></div></div>
        <div>${essai}</div></div></div>`;
  }
  function rsSaisie(el) {
    const r = S.rs, c = r && r.c; if (!c) { return; }
    const k = el.dataset.rs, p = el.dataset.i != null ? c.points[+el.dataset.i] : null;
    if (k === 'heure') { c.heure = el.value; } else if (k === 'checklists') { c.checklists = el.checked; } else if (k === 'raison') { r.raison = el.value; return; }
    else if (k === 'ajout') { r.ajout[+el.dataset.i] = el.value; return; }
    else if (p && k === 'actif') { p.actif = el.checked; } else if (p && k === 'param') { p.params = p.params || {}; p.params[el.dataset.k] = el.value === '' ? '' : +el.value; }
    else if (p) { p[k] = el.value; }
    r.bilan = '';
  }
  async function rsEnregistrer() {
    const r = S.rs; r.envoi = true; rendre();
    try { const d = await api('PUT', '/soir/config', r.c); r.base = rsCopie(d.config); r.c = rsCopie(d.config); r.bilan = 'Enregistré : ' + d.config.points.length + ' points, ' + d.config.raisons.length + ' raisons.'; S.msg = null; }
    catch (e) { S.msg = { k: 'ko', t: 'Revue du soir non enregistrée : ' + e.message }; }
    r.envoi = false; rendre();
  }
  async function rsSonder(i) {
    const r = S.rs, E = r.essai, p = i != null ? r.c.points[i] : null;
    if (p) { E.un[p.id] = { enCours: true }; } else { E.enCours = true; E.err = null; }
    rendre();
    try {
      const d = await api('GET', '/soir/sonder?shop=' + encodeURIComponent(E.shop) + '&date=' + encodeURIComponent(E.date) + (p ? '&point=' + encodeURIComponent(p.id) : ''));
      if (p) { E.un[p.id] = d.points[0] || { err: 'point pas encore enregistré' }; } else { E.res = d; d.points.forEach(x => { E.un[x.id] = x; }); }
    } catch (e) { if (p) { E.un[p.id] = { err: e.message }; } else { E.err = e.message; } }
    E.enCours = false; rendre();
  }
  async function rsTester(i) {
    const r = S.rs, p = r.c.points[i];
    r.test[i] = { enCours: true }; rendre();
    try { r.test[i] = await api('POST', '/soir/tester', { point: p, shop: +r.essai.shop, date: r.essai.date }); }
    catch (e) { r.test[i] = { err: e.message }; }
    rendre();
  }
  function rsAction(a, el) {
    const r = S.rs, c = r && r.c; if (!c) { return; }
    const i = el.dataset.i != null ? +el.dataset.i : null, j = el.dataset.j != null ? +el.dataset.j : null, p = i != null ? c.points[i] : null;
    r.bilan = '';
    switch (a) {
      case 'rsEnregistrer': return rsEnregistrer();
      case 'rsAnnuler': r.c = rsCopie(r.base); return rendre();
      case 'rsDefaut': r.c = Object.assign(rsCopie(r.defaut), { majLe: r.base.majLe, majPar: r.base.majPar }); return rendre();
      case 'rsSonder': return rsSonder(null);
      case 'rsTester': return rsTester(i);
      case 'rsChDefaut': { const s = rsSonde(p.sonde); p[el.dataset.v] = s ? s[el.dataset.v] : ''; delete r.test[i]; return rendre(); }
      case 'rsMonter': r.test = {}; if (i > 0) { c.points.splice(i - 1, 0, c.points.splice(i, 1)[0]); } return rendre();
      case 'rsDescendre': r.test = {}; if (i < c.points.length - 1) { c.points.splice(i + 1, 0, c.points.splice(i, 1)[0]); } return rendre();
      case 'rsSuppr': r.test = {}; if (confirm('Retirer « ' + p.titre + ' » de la revue du soir ?')) { c.points.splice(i, 1); } return rendre();
      case 'rsRepOk': p.reponses[j].ok = !p.reponses[j].ok; return rendre();
      case 'rsRepSuppr': p.reponses.splice(j, 1); return rendre();
      case 'rsRepAjout': { const t = String(r.ajout[i] || '').trim(); if (t) { p.reponses.push({ t, ok: false }); r.ajout[i] = ''; } return rendre(); }
      case 'rsRaisonSuppr': c.raisons.splice(j, 1); return rendre();
      case 'rsRaisonAjout': { const t = r.raison.trim(); if (t && !c.raisons.includes(t)) { c.raisons.push(t); } r.raison = ''; return rendre(); }
      case 'rsAjout': {
        const auto = el.dataset.v === 'auto', s = r.sondes[0];
        c.points.push(auto ? { id: '', actif: true, type: 'auto', sonde: s.cle, params: Object.fromEntries(s.params.map(d => [d.k, d.defaut])), cockpit: s.cockpit, panel: s.panel, icone: '🔎', titre: s.titre, question: '', reponses: [{ t: 'Fait maintenant', ok: true }, { t: 'Demain', ok: false }] }
          : { id: '', actif: true, type: 'question', sonde: null, params: {}, icone: '❓', titre: 'Nouvelle question', question: '', reponses: [{ t: 'Oui', ok: true }, { t: 'Pas encore', ok: false }] });
        rendre(); const L = $.querySelectorAll('.rs-titre'); if (L.length) { L[L.length - 1].focus(); L[L.length - 1].select(); }
        return;
      }
    }
  }

  /* --- la ligne du temps (09/11/2026, maquettes T1 + D3 + H2) --------------------------------------------------------
   * Une ligne par zone (ou par fonction : qui fait), une case par demi-heure, le nombre de tâches du jour choisi.
   * Un clic sur une case déplie ses tâches sous la ligne ; chaque tâche a une piste de 6 h où l'on glisse son heure
   * (par 15 min), la charge de la zone en fond. Rien ne part au panel avant « Enregistrer » (PATCH /task/taches/{id}). */
  const TL_PAS = 30, TL_CRAN = 15;
  const tlFmt = m => String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
  const TL_FONCTIONS = ['#8D1D2C', '#B0792A', '#3f7a52', '#1f5f8b', '#6B4FBB', '#A0522D', '#5c5852', '#2f6f6a', '#9a3b6b', '#55708f'];
  function tlEtat() {
    if (!S.tl) { S.tl = { jour: PL_JOURS[(new Date().getDay() + 6) % 7][0], par: 'zone', sel: null, mod: {}, envoi: false, err: null }; }
    return S.tl;
  }
  /** L'heure d'une tâche dans la ligne du temps : la modifiée (pas encore enregistrée), sinon la sienne, sinon celle de sa checklist. */
  function tlHeure(x) { const T = tlEtat(); return T.mod[x.t.id] != null ? T.mod[x.t.id] : dcMin(x.t.heure || x.clHeure || ''); }
  function tlDonnees() {
    const T = tlEtat();
    const { L } = planDonnees();
    const parCl = new Map(); checklists().forEach(c => c.taches.forEach(l => { if (!parCl.has(l.taskId)) { parCl.set(l.taskId, c); } }));
    const E = L.filter(x => x.js.includes(T.jour)).map(x => Object.assign({}, x, { clHeure: (parCl.get(x.t.id) || {}).heure || '', code: dcCode(x.cl).code }));
    const avec = E.filter(x => tlHeure(x) != null), sans = E.length - avec.length;
    // Les lignes : la zone de la checklist, ou les fonctions qui font la tâche (une tâche compte dans chacune).
    let cles, de;
    if (T.par === 'zone') {
      de = x => [x.poste];
      const rang = p => { const i = PL_ORDRE.indexOf(sansAccent(p)); return i < 0 ? (p === 'Sans poste' ? 99 : 50) : i; };
      cles = [...new Set(avec.map(x => x.poste))].sort((a, b) => rang(a) - rang(b) || a.localeCompare(b, 'fr'));
    } else {
      de = x => { const q = S.qui && S.qui[x.t.id]; if (!q) { return ['lecture…']; } const f = [...new Set(q.fait.map(n => n.poste || n.nom))]; return f.length ? f : ['Personne']; };
      const nb = {}; avec.forEach(x => de(x).forEach(f => { nb[f] = (nb[f] || 0) + 1; }));
      cles = Object.keys(nb).sort((a, b) => (a === 'Personne') - (b === 'Personne') || nb[b] - nb[a] || a.localeCompare(b, 'fr'));
    }
    const couleur = k => T.par === 'zone' ? dcCouleur(k) : k === 'Personne' || k === 'lecture…' ? '#8a847c' : TL_FONCTIONS[cles.indexOf(k) % TL_FONCTIONS.length];
    const mins = avec.map(tlHeure);
    const de0 = Math.min(6 * 60, ...mins.map(m => Math.floor(m / TL_PAS) * TL_PAS)), a0 = Math.max(19 * 60, ...mins.map(m => Math.floor(m / TL_PAS) * TL_PAS));
    const SL = []; for (let s = de0; s <= a0; s += TL_PAS) { SL.push(s); }
    const cell = {}; avec.forEach(x => { const s = Math.floor(tlHeure(x) / TL_PAS) * TL_PAS; de(x).forEach(k => { (cell[k + '|' + s] = cell[k + '|' + s] || []).push(x); }); });
    return { E, avec, sans, cles, cell, SL, couleur, de };
  }
  const tlRgba = (hex, a) => { const h = hex.replace('#', ''); return `rgba(${parseInt(h.slice(0, 2), 16)},${parseInt(h.slice(2, 4), 16)},${parseInt(h.slice(4, 6), 16)},${a.toFixed(2)})`; };
  function tempsVue() {
    const T = tlEtat();
    if (T.par === 'fonction' && !(S.dcQui && S.dcQui.enCours) && taches().some(t => !(S.qui && S.qui[t.id]))) { setTimeout(dcQuiCharger, 0); }
    const D = tlDonnees();
    const tot = s => D.avec.filter(x => Math.floor(tlHeure(x) / TL_PAS) * TL_PAS === s).length;
    const mx = Math.max(1, ...Object.values(D.cell).map(v => v.length)), mxt = Math.max(1, ...D.SL.map(tot));
    const pic = D.SL.reduce((a, s) => tot(s) > tot(a) ? s : a, D.SL[0]);
    const jourFrT = (PL_JOURS.find(j => j[0] === T.jour) || [])[2] || '';
    const nMod = Object.keys(T.mod).length;
    const jours = PL_JOURS.map(([j, c]) => `<button class="tl-j${T.jour === j ? ' on' : ''}" data-a="tlJour" data-v="${j}">${c}</button>`).join('');
    const tete = `<div class="tl-bar"><div><div class="tl-t">Ligne du temps · ${esc(jourFrT)}</div><div class="tl-s">${D.avec.length} tâche${D.avec.length > 1 ? 's' : ''} avec une heure, par tranche de 30 minutes${D.sans ? ` · ${D.sans} sans heure` : ''}</div></div><span class="sp"></span>
      <div class="tl-seg"><button class="${T.par === 'zone' ? 'on' : ''}" data-a="tlPar" data-v="zone">Par zone</button><button class="${T.par === 'fonction' ? 'on' : ''}" data-a="tlPar" data-v="fonction">Par fonction (qui fait)</button></div><div class="tl-js">${jours}</div></div>`;
    const lecture = T.par === 'fonction' && S.dcQui && S.dcQui.enCours ? `<div class="tk-astuce">Lecture de qui fait chaque tâche… ${S.dcQui.lu} / ${S.dcQui.total}</div>` : '';
    let h = `<table class="tl-g"><thead><tr><th class="p"></th>${D.SL.map(s => `<th class="${s === pic ? 'pk' : ''}">${s % 60 ? '<i>:30</i>' : tlFmt(s)}</th>`).join('')}<th class="tt">Total</th></tr></thead><tbody>`;
    D.cles.forEach(k => {
      const c = D.couleur(k);
      h += `<tr><td class="p"><i style="background:${c}"></i>${esc(T.par === 'zone' ? posteAff(k) : k)}</td>`;
      D.SL.forEach(s => {
        const L = D.cell[k + '|' + s] || [];
        const on = T.sel && T.sel.k === k && T.sel.s === s;
        if (!L.length) { h += `<td class="c${on ? ' sel' : ''}"></td>`; return; }
        const r = L.length / mx, mod = L.some(x => T.mod[x.t.id] != null);
        const codes = [...new Set(L.map(x => x.code).filter(Boolean))].join(', ');
        h += `<td class="c${on ? ' sel' : ''}"><button data-a="tlCase" data-k="${esc(k)}" data-s="${s}" title="${esc((T.par === 'zone' ? posteAff(k) : k) + ' · ' + tlFmt(s) + '–' + tlFmt(s + TL_PAS) + ' : ' + L.length + ' tâche' + (L.length > 1 ? 's' : '') + (codes ? ' (' + codes + ')' : ''))}" style="background:${tlRgba(c, 0.18 + 0.82 * r)};color:${r > 0.45 ? '#fff' : 'var(--ink)'}">${L.length}${mod ? '<sup>•</sup>' : ''}</button></td>`;
      });
      h += `<td class="tt">${D.SL.reduce((n, s) => n + (D.cell[k + '|' + s] || []).length, 0)}</td></tr>`;
      if (T.sel && T.sel.k === k) { h += `<tr class="tl-dep"><td colspan="${D.SL.length + 2}">${tlDetail(D, k, T.sel.s, c)}</td></tr>`; }
    });
    h += `<tr class="tot"><td class="p">${T.par === 'zone' ? 'Toutes les zones' : 'Toutes les tâches'}</td>${D.SL.map(s => `<td class="c"><span class="bt" style="height:${(4 + 30 * tot(s) / mxt).toFixed(0)}px"></span><em>${tot(s) || ''}</em></td>`).join('')}<td class="tt">${D.avec.length}</td></tr></tbody></table>`;
    const leg = `<div class="tl-leg">Pic : <b>${tlFmt(pic)}–${tlFmt(pic + TL_PAS)}</b> · ${tot(pic)} tâche${tot(pic) > 1 ? 's' : ''} à faire en même temps · un clic sur une case ouvre ses tâches${nMod ? ` · <b>${nMod} heure${nMod > 1 ? 's' : ''} changée${nMod > 1 ? 's' : ''}, pas encore enregistrée${nMod > 1 ? 's' : ''}</b> (•)` : ''}</div>`;
    return `<div class="tl">${tete}${lecture}<div class="tk-card tl-card"><div class="tl-defile">${h}</div>${leg}</div></div>`;
  }
  /** Les tâches d'une case, dépliées sous sa ligne : une piste de 6 h par tâche, le rond se glisse. */
  function tlDetail(D, k, s, c) {
    const T = tlEtat();
    const L = (D.cell[k + '|' + s] || []).slice().sort((a, b) => tlHeure(a) - tlHeure(b) || a.t.nom.localeCompare(b.t.nom, 'fr'));
    // Les tâches parties de cette case pendant les changements restent visibles : on les retrouve par leur heure d'origine.
    const parties = D.avec.filter(x => T.mod[x.t.id] != null && D.de(x).includes(k) && Math.floor(dcMin(x.t.heure || x.clHeure) / TL_PAS) * TL_PAS === s && !L.includes(x));
    const toutes = L.concat(parties);
    const a = Math.max(0, s - 4 * 60), b = Math.min(24 * 60, a + 6 * 60);
    const nMod = Object.keys(T.mod).length;
    const charge = {}; D.avec.forEach(x => { if (D.de(x).includes(k)) { const m = Math.floor(tlHeure(x) / TL_PAS) * TL_PAS; charge[m] = (charge[m] || 0) + 1; } });
    const mxc = Math.max(1, ...Object.values(charge));
    const pos = m => ((m - a) / (b - a) * 100).toFixed(2) + '%';
    const fond = []; for (let m = a; m < b; m += TL_PAS) { if (charge[m]) { fond.push(`<span class="hb" style="left:${pos(m)};width:calc(${(TL_PAS / (b - a) * 100).toFixed(2)}% - 2px);height:${(3 + 28 * charge[m] / mxc).toFixed(0)}px" title="${tlFmt(m)} : ${charge[m]}"></span>`); } }
    const graduations = []; for (let m = Math.ceil(a / 60) * 60; m <= b; m += 60) { graduations.push(`<span class="gr" style="left:${pos(m)}">${tlFmt(m)}</span>`); }
    const lignes = toutes.map(x => {
      const av = dcMin(x.t.heure || x.clHeure), ap = tlHeure(x), mod = T.mod[x.t.id] != null && T.mod[x.t.id] !== av;
      const quand = quandAff(x.t);
      return `<div class="tl-l${mod ? ' mod' : ''}"><div class="tl-n">${x.t.photo ? CAM + ' ' : ''}<button class="tk-lien-n" data-a="modifier" data-id="${x.t.id}" title="Modifier la tâche #${x.t.id}">${esc(x.t.nom)}</button>
          <small>${esc([x.code, quand.charAt(0).toLowerCase() + quand.slice(1)].filter(Boolean).join(' · '))}${x.t.heure ? '' : ' · heure de la checklist'}${mod ? ` · <b>${tlFmt(av)} → ${tlFmt(ap)}</b> <button class="tk-lien" data-a="tlRetour" data-id="${x.t.id}">rétablir</button>` : ''}</small></div>
        <div class="tl-trk" data-trk="${x.t.id}" data-a0="${a}" data-b0="${b}">${fond.join('')}${graduations.join('')}<span class="ln"></span>${mod ? `<span class="kn fant" style="left:${pos(av)}"></span>` : ''}
          <span class="kn${mod ? ' mod' : ''}" style="left:${pos(Math.min(b, Math.max(a, ap)))}" tabindex="0" role="slider" aria-valuetext="${tlFmt(ap)}" aria-label="Heure de ${esc(x.t.nom)}" data-kn="${x.t.id}"><i>${tlFmt(ap)}</i></span></div></div>`;
    }).join('');
    return `<div class="tl-d" style="border-left-color:${c}"><div class="tl-dt"><b>${esc(T.par === 'zone' ? posteAff(k) : k)} · ${tlFmt(s)} – ${tlFmt(s + TL_PAS)}</b><span class="mu">${L.length} tâche${L.length > 1 ? 's' : ''} · glissez le rond sur la journée (par ${TL_CRAN} min, ou ← → au clavier) ; en gris, la charge de la ligne</span><span class="sp"></span>
        <span class="mu">tout déplacer :</span><button class="tk-btn mini" data-a="tlTout" data-v="-30">− 30 min</button><button class="tk-btn mini" data-a="tlTout" data-v="30">+ 30 min</button><button class="tk-x" data-a="tlFermer" title="Fermer">×</button></div>
      <div class="tl-ls">${lignes || '<div class="mu">Plus aucune tâche ici.</div>'}</div>
      ${T.err ? `<div class="tk-err" style="margin:0 14px 10px">${esc(T.err)}</div>` : ''}
      <div class="tl-dp"><span class="sp"></span>${nMod ? `<span class="tl-chg">${nMod} changement${nMod > 1 ? 's' : ''}</span>` : '<span class="mu">aucun changement</span>'}<button class="tk-btn" data-a="tlAnnuler"${nMod && !T.envoi ? '' : ' disabled'}>Annuler</button><button class="tk-btn prim" data-a="tlEnregistrer"${nMod && !T.envoi ? '' : ' disabled'}>${T.envoi ? 'Envoi…' : 'Enregistrer au panel'}</button></div></div>`;
  }
  /** Une nouvelle heure pour une tâche (minutes), sans tout redessiner pendant le glisser. */
  function tlPoser(id, m, redessiner) {
    const T = tlEtat(); const t = tache(id); if (!t) { return; }
    const c = checklists().find(x => x.taches.some(l => l.taskId === id));
    const av = dcMin(t.heure || (c && c.heure) || '');
    m = Math.max(0, Math.min(23 * 60 + 45, m));
    if (m === av) { delete T.mod[id]; } else { T.mod[id] = m; }
    if (redessiner) { rendre(); return; }
    const kn = $.querySelector(`[data-kn="${id}"]`); const trk = kn && kn.parentElement;
    if (trk) { const a = +trk.dataset.a0, b = +trk.dataset.b0; kn.style.left = ((Math.min(b, Math.max(a, m)) - a) / (b - a) * 100).toFixed(2) + '%'; kn.querySelector('i').textContent = tlFmt(m); kn.classList.toggle('mod', m !== av); }
  }
  async function tlEnregistrer() {
    const T = tlEtat(); const ids = Object.keys(T.mod).map(Number);
    if (!ids.length || T.envoi) { return; }
    T.envoi = true; T.err = null; rendre();
    const faits = [];
    for (const id of ids) {
      try { await api('PATCH', '/task/taches/' + id, { heure: tlFmt(T.mod[id]) }); const t = tache(id); if (t) { t.heure = tlFmt(T.mod[id]); } faits.push(id); delete T.mod[id]; }
      catch (e) { const t = tache(id); T.err = '« ' + ((t && t.nom) || '#' + id) + ' » : ' + e.message; break; }
    }
    T.envoi = false;
    S.msg = faits.length ? { k: T.err ? 'wa' : 'ok', t: `${faits.length} heure${faits.length > 1 ? 's' : ''} enregistrée${faits.length > 1 ? 's' : ''} au panel.${T.err ? ' Arrêt : ' + esc(T.err) : ''}` } : { k: 'ko', t: 'Rien n’a été enregistré : ' + esc(T.err || 'refus du panel') };
    rendre();
  }
  // Le glisser : un appui sur la piste pose le rond là, puis il suit le doigt ou la souris (par crans de 15 min).
  let tlDrag = null;
  function tlMinute(trk, x) {
    const r = trk.getBoundingClientRect(); const a = +trk.dataset.a0, b = +trk.dataset.b0;
    const f = Math.max(0, Math.min(1, (x - r.left) / r.width));
    return Math.round((a + f * (b - a)) / TL_CRAN) * TL_CRAN;
  }
  $.addEventListener('pointerdown', ev => {
    const trk = ev.target.closest('[data-trk]'); if (!trk || S.vue !== 'temps') { return; }
    ev.preventDefault();
    tlDrag = { id: +trk.dataset.trk, trk };
    try { trk.setPointerCapture(ev.pointerId); } catch (e) { /* ancien navigateur */ }
    tlPoser(tlDrag.id, tlMinute(trk, ev.clientX), false);
  });
  $.addEventListener('pointermove', ev => { if (tlDrag) { tlPoser(tlDrag.id, tlMinute(tlDrag.trk, ev.clientX), false); } });
  const tlLacher = () => { if (tlDrag) { tlDrag = null; rendre(); } };
  $.addEventListener('pointerup', tlLacher); $.addEventListener('pointercancel', tlLacher);
  $.addEventListener('keydown', ev => {
    const kn = ev.target.closest && ev.target.closest('[data-kn]'); if (!kn || (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight')) { return; }
    ev.preventDefault();
    const id = +kn.dataset.kn; const t = tache(id); const T = tlEtat();
    const c = checklists().find(x => x.taches.some(l => l.taskId === id));
    const cur = T.mod[id] != null ? T.mod[id] : dcMin(t.heure || (c && c.heure) || '');
    tlPoser(id, cur + (ev.key === 'ArrowLeft' ? -TL_CRAN : TL_CRAN), true);
    const k2 = $.querySelector(`[data-kn="${id}"]`); if (k2) { k2.focus(); }
  });

  function rendre() {
    const actif = document.activeElement;
    const k = actif && actif.dataset ? ['d', 'f', 'm', 'wc', 'wl', 'wg', 'wcm', 'wf', 'pf', 'dd', 'ck', 'rs'].find(x => actif.dataset[x] != null) : null;
    const garde = k ? { sel: `[data-${k}="${actif.dataset[k]}"]` + (actif.dataset.i != null ? `[data-i="${actif.dataset.i}"]` : ''), s: actif.selectionStart } : null;
    if (!S.cadre && S.charge) { $.innerHTML = enTete() + '<div class="tk-attente">Lecture du cadre des tâches du panel…</div>' + modale(); return; }
    const ong = S.wz ? '' : `<div class="tk-ong"><button class="${S.vue === 'doc' ? 'on' : ''}" data-a="vue" data-v="doc">Document</button><button class="${S.vue === 'checklists' ? 'on' : ''}" data-a="vue" data-v="checklists">Checklists</button><button class="${S.vue === 'planning' ? 'on' : ''}" data-a="vue" data-v="planning">Planning des tâches</button><button class="${S.vue === 'temps' ? 'on' : ''}" data-a="vue" data-v="temps">Ligne du temps</button><button class="${S.vue === 'soir' ? 'on' : ''}" data-a="vue" data-v="soir">Revue du soir</button></div>`;
    const rq = !S.wz && termesRq().length && S.cadre;
    $.innerHTML = enTete() + (rq ? '' : ong) + erreurs() + (S.wz ? assistant() : rq ? `${S.dup ? volet() : ''}${ecranRecherche()}` : S.vue === 'planning' ? planning() : S.vue === 'temps' ? tempsVue() : S.vue === 'doc' ? docVue() : S.vue === 'checklists' ? ckVue() : S.vue === 'soir' ? rsVue() : `<div class="tk-grille"><div>${cote()}</div><div>${S.dup ? volet() : ''}${tableau()}${creees()}</div></div>`) + modale();
    if (garde) {
      const el = $.querySelector(garde.sel);
      if (el) { el.focus(); try { if (garde.s != null && el.setSelectionRange) { el.setSelectionRange(garde.s, garde.s); } } catch (e) { /* champ sans curseur */ } }
    }
    signalerHauteur();   // une fenêtre ouverte ou fermée ne change pas la taille du contenu : on mesure à chaque rendu
    placerSommaire();
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
      case 'recharger': S.qui = null; S.dd = null; return charger();
      case 'vue': S.vue = el.dataset.v; S.dd = null; adresse(S.cl); rendre(); voirHaut(); if (S.vue === 'doc') { dcQuiCharger(); } if (S.vue === 'checklists') { ckOuvrir(); } if (S.vue === 'soir') { rsOuvrir(); } return;
      // L'onglet Revue du soir
      case 'rsEnregistrer': case 'rsAnnuler': case 'rsDefaut': case 'rsSonder': case 'rsTester': case 'rsChDefaut': case 'rsMonter': case 'rsDescendre': case 'rsSuppr':
      case 'rsRepOk': case 'rsRepSuppr': case 'rsRepAjout': case 'rsRaisonSuppr': case 'rsRaisonAjout': case 'rsAjout': return rsAction(a, el);
      // L'onglet Checklists
      case 'ckAnnuler': if (S.ck) { S.ck.edits = {}; S.ck.res = {}; S.ck.bilan = ''; } return rendre();
      case 'ckRetablir': if (S.ck) { delete S.ck.edits[id]; delete S.ck.res[id]; } return rendre();
      case 'ckEnregistrer': return ckEnregistrer();
      case 'ckHarmoniser': dcOrdre().forEach(c => { const v = ckVal(c); if (v.code && v.sep !== ' · ') { ckSaisie(c.id, 'sep', ' · '); } }); return rendre();
      case 'dcVoir': S.vue = 'doc'; S.cl = id; adresse(id); rendre(); dcQuiCharger(); return dcAller(id);
      case 'ckNomRelire': S.nomen = Object.assign(S.nomen || {}, { res: null }); return ckNomCharger();
      case 'ckNomAppliquer': return ckNomAppliquer();
      case 'ckNomOuvrir': S.nomen.ouvert = S.nomen.ouvert === el.dataset.v ? null : el.dataset.v; return rendre();
      // La vue Document : les vignettes et leurs listes
      case 'ddOuvrir': return dcOuvrir(el.dataset.k, el.dataset.t ? +el.dataset.t : null, el.dataset.c ? +el.dataset.c : null, el);
      case 'ddPortee': return dcPortee(el.dataset.v);
      case 'ddHeure': if (S.dd) { S.dd.val = el.dataset.v; return dcChamps({ heure: el.dataset.v }, 'Heure ' + el.dataset.v); } return;
      case 'ddHeureOk': {
        const d = S.dd; if (!d) { return; }
        if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(d.val || '')) { d.err = 'Une heure au format HH:MM est attendue.'; return rendre(); }
        return dcChamps({ heure: d.val }, 'Heure ' + d.val);
      }
      case 'ddJour': { const v = el.dataset.v; const jr = PL_JOURS.find(x => x[0] === v);
        return dcChamps(v ? { jour: v, frequence: 'WEEKLY' } : { jour: '', frequence: 'DAILY' }, v ? 'Chaque ' + (jr ? jr[2] : v) : 'Tous les jours'); }
      case 'ddBool': { const d = S.dd; if (!d) { return; } const oui = el.dataset.v === '1';
        return dcChamps(d.k === 'photo' ? { photo: oui } : { obligatoire: oui }, d.k === 'photo' ? (oui ? 'Photo requise' : 'Sans photo') : (oui ? 'Obligatoire' : 'Facultative')); }
      case 'ddCoche': { const d = S.dd; if (!d || !d.etat) { return; } const v = el.dataset.v; d.etat[v] = (d.etat[v] || 'off') === 'on' ? 'off' : 'on'; d.err = null; return rendre(); }
      case 'ddQuiOk': return dcQuiAppliquer();
      case 'ddCible': if (S.dd) { S.dd.cible = +el.dataset.v; S.dd.err = null; } return rendre();
      case 'ddOpt': if (S.dd) { S.dd[el.dataset.v] = !S.dd[el.dataset.v]; } return rendre();
      case 'ddRanger': return dcRangerEnvoyer();
      case 'ddDeplacer': return dcDeplacerEnvoyer();
      case 'dcZone': S.dcZone = el.dataset.v || null; S.dd = null; return rendre();
      case 'dcFiltreRaz': S.dcZone = null; S.dcFct = null; S.dd = null; return rendre();
      case 'tlJour': tlEtat().jour = el.dataset.v; tlEtat().sel = null; return rendre();
      case 'tlPar': tlEtat().par = el.dataset.v; tlEtat().sel = null; return rendre();
      case 'tlCase': { const T = tlEtat(); const k = el.dataset.k, s = +el.dataset.s; T.sel = T.sel && T.sel.k === k && T.sel.s === s ? null : { k, s }; T.err = null; return rendre(); }
      case 'tlFermer': tlEtat().sel = null; return rendre();
      case 'tlRetour': delete tlEtat().mod[+id]; return rendre();
      case 'tlAnnuler': tlEtat().mod = {}; tlEtat().err = null; return rendre();
      case 'tlEnregistrer': return tlEnregistrer();
      case 'tlTout': { const T = tlEtat(); if (!T.sel) { return; } const D = tlDonnees(); (D.cell[T.sel.k + '|' + T.sel.s] || []).forEach(x => tlPoser(x.t.id, tlHeure(x) + +el.dataset.v, false)); T.sel = { k: T.sel.k, s: T.sel.s + +el.dataset.v }; return rendre(); }
      case 'ddPlusieurs': { const d = S.dd; if (!d || d.id == null) { return; } S.sel = new Set([d.id]); if (d.c) { S.cl = String(d.c); } S.dd = null; ouvrirDup(); rendre(); voirHaut(); return; }
      case 'dcAller': S.cl = id; S.dd = null; adresse(id); rendre(); return dcAller(id);
      case 'dcAjouter': S.cl = id; S.dd = null; adresse(id); return wzOuvrir('existante');
      case 'dcQuiRelire': S.dcQui = null; return dcQuiCharger();
      case 'plCase': {
        S.modale = { type: 'planning', p: el.dataset.p, j: el.dataset.j, f: '', k: 'tout', fait: '', ctrl: '', cl: '', y: el.getBoundingClientRect().top + window.scrollY }; rendre();
        return quiCharger(S.modale);
      }
      case 'plSeg': S.modale.k = el.dataset.v; return rendre();
      case 'plRetNiv': { const m = S.modale; const q = S.qui[el.dataset.t]; const n = q && q.fait.find(x => String(x.id) === el.dataset.n);
        if (!confirm(`Retirer « ${n ? nomNiv(n) : '#' + el.dataset.n} » de la tâche #${el.dataset.t} dans le panel ? Ce niveau ne la verra plus.`)) { return; }
        return quiChanger(m, +el.dataset.t, { retirerNiveau: +el.dataset.n, route: el.dataset.route }); }
      case 'plRetCtl': { const m = S.modale; const q = S.qui[el.dataset.t]; const c = q && q.controle.find(x => String(x.id) === el.dataset.c);
        if (!confirm(`Retirer le contrôleur « ${c ? c.nom : '#' + el.dataset.c} » de la tâche #${el.dataset.t} dans le panel ?`)) { return; }
        return quiChanger(m, +el.dataset.t, { retirerControleur: +el.dataset.c }); }
      case 'clModifier': {
        const c = checklist(id); if (!c) { return; }
        const p = ((S.struct && S.struct.postes) || []).find(x => x.nom === c.poste);
        S.modale = { type: 'clModif', id: +id, retour: S.modale && S.modale.type === 'planning' ? S.modale : null,
          f: { nom: c.nom, description: c.description || '', heure: c.heure || '', posteId: (c.brut && c.brut.workstation_id) || (p && p.id) || '', poste: c.poste } };
        rendre();
        if (!S.struct) { try { S.struct = await api('GET', '/task/structure'); } catch (e) { S.struct = { sousCategories: [], postes: [], erreurs: {} }; } rendre(); }
        return;
      }
      case 'clEnregistrer': {
        const m = S.modale; m.envoi = true; m.err = null; rendre();
        try {
          await api('PATCH', '/task/checklists/' + m.id, { nom: m.f.nom, description: m.f.description, posteId: +m.f.posteId || 0, heure: m.f.heure });
          S.msg = { k: 'ok', t: 'Checklist « ' + esc(m.f.nom) + ' » modifiée dans le panel.' };
          const r = m.retour; S.modale = r; if (r) { r.cl = m.f.nom; }
          return charger(true);
        } catch (e) { m.err = e.message; m.envoi = false; return rendre(); }
      }
      case 'plSansQui': S.modale.fait = ''; S.modale.ctrl = ''; S.modale.cl = ''; return rendre();
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
      case 'ckCode': return ckCodeClic(el);
      case 'ckCodeOuvrir': S.ck.cp = S.ck.cp === +id ? null : +id; return rendre();
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
      case 'rqEffacer': S.rq = ''; rendre(); { const r = $.querySelector('[data-f="recherche"]'); if (r) { r.focus(); } } return;
      case 'rqPlus': S.rqMax += 300; return rendre();
      case 'rqTous': { const r = resultatsRq(); const L = r ? r.ts.slice(0, S.rqMax) : []; if (el.checked) { L.forEach(x => S.sel.add(x.t.id)); } else { L.forEach(x => S.sel.delete(x.t.id)); } return rendre(); }
      // Une checklist trouvée : on l'ouvre, la recherche s'efface.
      case 'rqCl': S.rq = ''; S.cl = id; S.filtre = ''; adresse(id); rendre(); if (S.vue === 'doc') { return dcAller(id); } if (S.vue === 'planning') { S.vue = 'doc'; rendre(); return dcAller(id); } voirHaut(); return;
      case 'supprimerCl': {
        // Toute checklist se supprime (09/11/2026, « rajouter la possibilité d'effacer une checklist ») : celles créées
        // dans le panel partent avec leur nom exact (?confirmer=), que le serveur compare à celui du panel.
        const cc = ((S.cadre && S.cadre.checklistsCreees) || []).find(x => x.id === +id);
        const cl = checklist(id);
        const nomPanel = cl ? String((cl.brut && (cl.brut.name || cl.brut.base_name)) || cl.nom).trim() : (cc ? cc.nom : '');
        const nb = cl ? dcTachesDe(cl).length : 0;
        if (!confirm('Supprimer du panel la checklist « ' + (nomPanel || '#' + id) + ' » ?\n\n' + (nb ? (nb > 1 ? 'Ses ' + nb + ' tâches restent' : 'Sa tâche reste') + ' dans le panel, mais dans aucune checklist : les équipes ne la' + (nb > 1 ? 's' : '') + ' verront plus (vous pourrez les ranger ailleurs, section « Dans aucune checklist »).' : 'Elle n’a aucune tâche.') + '\n\nOn ne peut pas annuler.')) { return; }
        if (S.modale && S.modale.type === 'clModif') { S.modale = null; }
        try { await api('DELETE', '/task/checklists/' + id + (cc || !nomPanel ? '' : '?confirmer=' + encodeURIComponent(nomPanel))); S.msg = { k: 'ok', t: 'Checklist « ' + esc(nomPanel || '#' + id) + ' » supprimée du panel.' }; }
        catch (e) { S.msg = { k: 'ko', t: 'Suppression refusée : ' + esc(e.message) }; }
        return charger(true);
      }
      default: return;
    }
  }

  $.addEventListener('click', ev => {
    const el = ev.target.closest('[data-a]');
    // Une liste de vignette ouverte se ferme d'un clic à côté (le clic garde son effet).
    if (S.dd && !ev.target.closest('.dc-dd') && !(el && el.dataset.a === 'ddOuvrir')) { S.dd = null; if (!el) { rendre(); return; } }
    if (!el || !$.contains(el)) { return; }
    // Le voile ne ferme que sur un clic À CÔTÉ de la fenêtre, pas dans un champ de celle-ci.
    if (el.dataset.a === 'voile' && ev.target !== el) { return; }
    if (el.tagName !== 'INPUT') { ev.preventDefault(); }
    action(el.dataset.a, el);
  });
  $.addEventListener('input', ev => {
    const el = ev.target;
    if (el.dataset.f === 'filtre') { S.filtre = el.value; return rendre(); }
    if (el.dataset.f === 'ckFiltre' && S.ck) { S.ck.filtre = el.value; return rendre(); }
    if (el.dataset.ck && S.ck) { ckSaisie(+el.dataset.i, el.dataset.ck, el.value); return rendre(); }
    if (el.dataset.rs && S.rs) { rsSaisie(el); if (el.type !== 'checkbox' && el.dataset.rs !== 'ajout' && el.dataset.rs !== 'raison') { const avant = !!S.rs.avantMod, mod = rsModifie(); S.rs.avantMod = mod; if (avant !== mod) { rendre(); } } return; }
    if (el.dataset.dd && S.dd) { if (el.dataset.dd === 'filtre') { S.dd.filtre = el.value; return rendre(); } S.dd.val = el.value; S.dd.err = null; return; }
    if (el.dataset.f === 'recherche') { S.rq = el.value; S.rqMax = 150; return rendre(); }
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
    if (ev.target.dataset && ev.target.dataset.dcf === 'fct') { S.dcFct = ev.target.value || null; S.dd = null; return rendre(); }
    const el = ev.target;
    if (el.dataset.cks && S.ck) { ckSaisie(+el.dataset.i, el.dataset.cks, el.value); return rendre(); }
    if (S.rs && el.dataset.rs && el.type === 'checkbox') { rsSaisie(el); return rendre(); }
    if (S.rs && el.dataset.rss === 'sonde') { const p = S.rs.c.points[+el.dataset.i], s = rsSonde(el.value); p.sonde = el.value; p.params = Object.fromEntries(s.params.map(d => [d.k, d.defaut])); p.cockpit = s.cockpit; p.panel = s.panel; delete S.rs.test[+el.dataset.i]; return rendre(); }
    if (S.rs && el.dataset.rse) { S.rs.essai[el.dataset.rse] = el.value; S.rs.essai.res = null; S.rs.essai.un = {}; return rendre(); }
    if (el.dataset.pq && S.modale && S.modale.type === 'planning' && el.value) {
      const m = S.modale; const t = +el.dataset.t; const v = el.value;
      if (el.dataset.pq === 'niv') { return quiChanger(m, t, { ajouterNiveau: +v }); }
      return quiChanger(m, t, { ajouterControleur: v === 'self' ? { type: 'self' } : { type: 'position', positionId: +v } });
    }
    if (el.dataset.ps && S.modale && S.modale.type === 'planning') {
      const m = S.modale; m[el.dataset.ps] = el.value;
      if (el.dataset.ps === 'p') { m.cl = ''; rendre(); return quiCharger(m); }
      return rendre();
    }
    if (el.dataset.d && S.dup && (el.tagName === 'SELECT' || el.type === 'checkbox')) { S.dup[el.dataset.d] = el.type === 'checkbox' ? el.checked : el.value; S.plan = null; rendre(); }
    if (S.wz && (el.tagName === 'SELECT' || el.type === 'checkbox') && (el.dataset.wc != null || el.dataset.wl != null || el.dataset.wg != null || el.dataset.wcm != null)) { rendre(); }
  });
  // Sur le document : après un clic dans une fenêtre, le focus retombe sur <body>, hors de #tk.
  document.addEventListener('keydown', ev => {
    const champ = ev.target && (ev.target.tagName === 'INPUT' || ev.target.tagName === 'TEXTAREA' || ev.target.tagName === 'SELECT' || ev.target.isContentEditable);
    if (!S.modale && !S.wz && ((ev.key === '/' && !champ) || ((ev.ctrlKey || ev.metaKey) && (ev.key === 'k' || ev.key === 'K')))) {
      const r = $.querySelector('[data-f="recherche"]');
      if (r) { ev.preventDefault(); r.focus(); r.select(); return; }
    }
    if (ev.key === 'Escape' && !S.modale && ev.target.dataset && ev.target.dataset.f === 'recherche' && S.rq) { S.rq = ''; rendre(); const r = $.querySelector('[data-f="recherche"]'); if (r) { r.focus(); } return; }
    if (ev.key === 'Escape' && S.modale) { S.modale = null; rendre(); }
    if (ev.key === 'Escape' && !S.modale && S.dd) { S.dd = null; rendre(); return; }
    if (ev.key === 'Enter' && ev.target.dataset && ev.target.dataset.dd === 'heure' && S.dd) { ev.preventDefault(); action('ddHeureOk', ev.target); }
    if (ev.key === 'Enter' && ev.target.dataset && ev.target.dataset.m === 'password') { action('seConnecter', ev.target); }
  });

  charger();
}());
