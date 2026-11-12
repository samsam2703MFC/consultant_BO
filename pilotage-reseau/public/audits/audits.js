/* Pilotage › Audit fournisseurs (12/11/2026) — le formulaire, les campagnes, les résultats, les copies aux fournisseurs.
 * API : ../api/cockpit/audits/* (src/audits.php). Dans le cockpit (?embed=1), la page dit sa hauteur au cadre
 * (postMessage { auHauteur }) : une seule barre de défilement, celle du cockpit. */
(function () {
  'use strict';
  const API = '../api/cockpit/audits/';
  const EMBED = new URLSearchParams(location.search).get('embed') === '1';
  if (EMBED) document.body.classList.add('embed');
  const $ = document.getElementById('af');
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const nf = (n, d = 1) => n == null ? '—' : Number(n).toLocaleString('fr-BE', { minimumFractionDigits: 0, maximumFractionDigits: d });
  const dateFr = s => s ? s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4) : '—';
  const jour = s => s ? s.slice(8, 10) + '/' + s.slice(5, 7) + (s.length > 10 ? ' ' + s.slice(11, 16) : '') : '—';
  // Divergente validée (framboise ← gris → bleu), pour la grille et les répartitions : 1 = mauvais, 5 = très bien.
  const TEINTES = { 1: ['#A93A57', '#fff'], 2: ['#D98A9E', '#1c1a17'], 3: ['#DDD8D2', '#1c1a17'], 4: ['#7FA6CF', '#1c1a17'], 5: ['#2E6DA4', '#fff'] };
  const S = { tab: 'formulaire', etat: null, edit: null, camp: null, campD: null, nouvelle: false, resCamp: null, res: null, resF: null, apercu: null, msg: null, occupe: false };

  async function api(methode, chemin, corps) {
    const r = await fetch(API + chemin, { method: methode, credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: corps ? JSON.stringify(corps) : undefined });
    let j = {}; try { j = await r.json(); } catch (e) { j = {}; }
    if (!r.ok) throw new Error(j.error || j.message || ('erreur ' + r.status));
    return j;
  }
  const msg = (genre, texte) => { S.msg = { genre, texte }; };
  async function action(fn, ok) {
    if (S.occupe) return;
    S.occupe = true; rendu();
    try { await fn(); if (ok) msg('ok', ok); } catch (e) { msg('ko', e.message); }
    S.occupe = false; rendu();
  }
  async function chargeEtat() { S.etat = await api('GET', 'etat'); }

  /* --- résumés d'une question ------------------------------------------------------------------- */
  const T = () => S.etat.types;
  function bareme(q) {
    switch (q.type) {
      case 'note': return Number(q.coef) === 1 ? '1 pt par point de note' : 'note × ' + nf(q.coef, 2);
      case 'ouinon': case 'choix': return q.choix.map(c => c.libelle + ' ' + nf(c.points, 2)).join(' · ');
      case 'nombre': return q.paliers.length ? q.paliers.map(p => (p.jusqua == null ? 'au-delà' : '≤ ' + nf(p.jusqua, 2)) + ' → ' + nf(p.points, 2)).join(' · ') : 'sans palier : non noté';
    }
    return 'non noté';
  }
  function maxDe(q) {
    if (q.type === 'note') return 5 * Number(q.coef || 1);
    if ((q.type === 'ouinon' || q.type === 'choix') && q.choix.length) return Math.max(...q.choix.map(c => Number(c.points)));
    if (q.type === 'nombre' && q.paliers.length) return Math.max(...q.paliers.map(p => Number(p.points)));
    return null;
  }
  function condTxt(c, mere) {
    if (!c) return '';
    if (c.op === 'in') {
      const lib = code => ((mere && mere.choix) || []).find(x => x.code === code);
      return 'si ' + c.valeur.map(v => (lib(v) || {}).libelle || v).join(' / ');
    }
    return 'si ' + (c.op === 'le' ? '≤ ' : '≥ ') + nf(c.valeur, 2);
  }
  function regleTxt(q) {
    const r = q.regle; if (!r) return '';
    const eff = [r.commentaire ? 'commentaire exigé' : '', r.action ? 'action « ' + r.action + ' »' : '', r.alerte ? 'alerte' : ''].filter(Boolean).join(', ');
    return condTxt(r, q) + ' : ' + eff;
  }

  /* --- l'onglet Formulaire ---------------------------------------------------------------------- */
  function editeur(e) {
    const q = e.q, mere = e.mere;
    const sel = (k, opts, v) => `<select data-e="${k}">${opts.map(([c, l]) => `<option value="${esc(c)}"${String(v) === String(c) ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
    let type = '';
    if (q.type === 'note') {
      type = `<div class="af-row"><label class="af-f n">Coefficient<input type="number" step="any" min="0" data-e="coef" value="${esc(q.coef)}"></label>
        <label class="af-f">Libellé du 1<input data-e="min" value="${esc(q.min)}" placeholder="Trop chers"></label><label class="af-f">Libellé du 5<input data-e="max" value="${esc(q.max)}" placeholder="Très corrects"></label>
        <span class="af-mu af-sm">Points = note × coefficient (max ${nf(5 * Number(q.coef || 0), 2)}).</span></div>`;
    } else if (q.type === 'ouinon' || q.type === 'choix' || q.type === 'multi') {
      const fixe = q.type === 'ouinon';
      type = `<div class="af-box"><b>${q.type === 'multi' ? 'Choix (non notés)' : 'Choix et points'}</b>${q.choix.map((c, i) => `<div class="af-row">
        <label class="af-f grand">Choix ${i + 1}<input data-ch="${i}:libelle" value="${esc(c.libelle)}"${fixe ? ' readonly' : ''}></label>
        ${q.type !== 'multi' ? `<label class="af-f n">Points<input type="number" step="any" data-ch="${i}:points" value="${esc(c.points)}"></label>` : ''}
        ${fixe ? '' : `<button class="btn btn--danger btn--sm" data-chx="${i}">Retirer</button>`}</div>`).join('')}
        ${fixe ? '' : '<div><button class="btn btn--sm" data-chplus>+ Ajouter un choix</button></div>'}</div>`;
    } else if (q.type === 'nombre') {
      type = `<div class="af-box"><b>Paliers de points</b><label class="af-f">Unité<input data-e="unite" value="${esc(q.unite)}" placeholder="ruptures, °C…" style="max-width:160px"></label>
        ${q.paliers.map((p, i) => `<div class="af-row"><label class="af-f n">Jusqu’à<input type="number" step="any" data-pal="${i}:jusqua" value="${esc(p.jusqua == null ? '' : p.jusqua)}" placeholder="au-delà"></label>
        <label class="af-f n">Points<input type="number" step="any" data-pal="${i}:points" value="${esc(p.points)}"></label><button class="btn btn--danger btn--sm" data-palx="${i}">Retirer</button></div>`).join('')}
        <div><button class="btn btn--sm" data-palplus>+ Ajouter un palier</button> <span class="af-mu af-sm">« Jusqu’à » vide : au-delà du dernier palier.</span></div></div>`;
    } else {
      type = `<p class="af-mu af-sm" style="margin:0">${q.type === 'texte' ? 'Texte libre : non noté. Il apparaît dans les résultats et peut être joint à la copie du fournisseur.' : 'Non noté.'}</p>`;
    }
    // La condition d'une sous-question : sur les choix de la mère, ou un seuil de sa note / de son nombre.
    const condUi = (pref, c, cible) => {
      if (!cible) return '';
      if (['ouinon', 'choix', 'multi'].includes(cible.type)) {
        const v = c && c.op === 'in' ? c.valeur : [];
        return cible.choix.map(ch => `<label class="af-chk"><input type="checkbox" data-${pref}v="${esc(ch.code)}"${v.includes(ch.code) ? ' checked' : ''}> ${esc(ch.libelle)}</label>`).join(' ');
      }
      if (cible.type === 'note' || cible.type === 'nombre') {
        return `<select data-${pref}="op"><option value="le"${!c || c.op === 'le' ? ' selected' : ''}>≤</option><option value="ge"${c && c.op === 'ge' ? ' selected' : ''}>≥</option></select>
          <input type="number" step="any" data-${pref}="valeur" value="${esc(c && c.op !== 'in' ? c.valeur : '')}" style="width:70px">`;
      }
      return '<span class="af-mu af-sm">Pas de condition possible sur ce type.</span>';
    };
    const si = mere ? `<div class="af-box"><b>Afficher cette sous-question</b><div class="af-row" style="align-items:center">${condUi('si', q.si, mere)}
      <span class="af-mu af-sm">${['texte', 'photo', 'date'].includes(mere.type) ? '' : 'Rien de coché : dès que la question mère est répondue.'}</span></div></div>` : '';
    const rg = q.regle || {};
    const regle = ['texte', 'photo', 'date'].includes(q.type) ? '' : `<div class="af-box"><b>Règle</b><div class="af-row" style="align-items:center">Si la réponse est ${condUi('rg', q.regle, q)}</div>
      <div class="af-row" style="align-items:center"><label class="af-chk"><input type="checkbox" data-rgc="commentaire"${rg.commentaire ? ' checked' : ''}> commentaire exigé</label>
      <label class="af-chk"><input type="checkbox" data-rgc="alerte"${rg.alerte ? ' checked' : ''}> alerte au siège (push)</label>
      <label class="af-f grand">Action à ouvrir (vide : aucune) — {fournisseur} prend le nom<input data-rg="action" value="${esc(rg.action || '')}" placeholder="Revoir les prix avec {fournisseur}"></label></div></div>`;
    return `<div class="af-ed">
      <div class="af-row"><label class="af-f grand">Question — {fournisseur} prend le nom du fournisseur<input data-e="libelle" value="${esc(q.libelle)}" placeholder="Les prix de {fournisseur} sont-ils corrects ?"></label>
        <label class="af-f">Type de réponse${sel('type', Object.entries(T()), q.type)}</label></div>
      <div class="af-row"><label class="af-f grand">Aide (facultatif)<input data-e="aide" value="${esc(q.aide)}"></label>
        <label class="af-chk"><input type="checkbox" data-e="obligatoire"${q.obligatoire ? ' checked' : ''}> obligatoire</label></div>
      ${type}${si}${regle}
      <div class="af-row"><button class="btn btn--primary btn--sm" data-qsave${S.occupe ? ' disabled' : ''}>Enregistrer</button><button class="btn btn--ghost btn--sm" data-qannule>Annuler</button></div></div>`;
  }
  function ligneQ(q, toutes, f) {
    const mere = q.parent ? toutes.find(x => x.id === q.parent) : null;
    const m = maxDe(q);
    const ed = S.edit && S.edit.id === q.id ? editeur(S.edit) : '';
    const lib = f ? q.libelle.replace(/\{fournisseur\}/g, f) : q.libelle;
    return `<div class="af-q${q.parent ? ' sous' : ''}"><div class="ord">${q.parent ? '' : `<button data-monte="${q.id}" title="Monter">▲</button><button data-descend="${q.id}" title="Descendre">▼</button>`}</div>
      <div><div class="lib">${q.parent ? `<span class="sousm">↳ ${esc(condTxt(q.si, mere) || 'si répondu')}</span>` : ''}${esc(lib)}${q.obligatoire ? '' : ' <span class="af-mu af-sm">(facultatif)</span>'}</div>
        <div class="meta"><span class="af-ty">${esc(T()[q.type])}</span><span class="af-bar">${esc(bareme(q))}</span>${m != null ? `<span class="chip chip--neutral">${nf(m, 2)} pts</span>` : ''}
        ${q.regle ? `<span class="tag-warn">${esc(regleTxt(q))}</span>` : ''}</div></div>
      <div class="act">${q.parent ? '' : `<button class="btn btn--ghost btn--sm" data-qsous="${q.id}">+ Sous-question</button>`}<button class="btn btn--sm" data-qedit="${q.id}">Modifier</button><button class="btn btn--danger btn--sm" data-qsuppr="${q.id}">Retirer</button></div></div>
      ${ed ? `<div>${ed}</div>` : ''}`;
  }
  function section(titre, sous, qs, toutes, cle, nomF, commun) {
    const meres = qs.filter(q => !q.parent);
    const tot = qs.reduce((s, q) => s + (maxDe(q) || 0), 0);
    const nouveau = S.edit && S.edit.id == null && S.edit.cle === cle ? editeur(S.edit) : '';
    const corps = meres.map(m => ligneQ(m, toutes, nomF) + qs.filter(q => q.parent === m.id).map(q => ligneQ(q, toutes, nomF)).join('')).join('');
    return `<section class="af-sec${commun ? ' commun' : ''}"><header><b>${esc(titre)}</b><span class="af-mu af-sm">${esc(sous)}</span><span class="pts">${commun ? '' : '+ '}${nf(tot, 2)} pts</span></header>
      ${corps || '<div class="af-vide">Aucune question.</div>'}${nouveau}
      ${nouveau || (S.edit && S.edit.cle === cle && S.edit.parent) ? '' : `<div style="padding:10px 14px;border-top:1px solid var(--line)"><button class="btn btn--sm" data-qnouv="${esc(cle)}">+ Ajouter une question</button></div>`}</section>`;
  }
  function ongletFormulaire() {
    const E = S.etat, qs = E.questions, refs = E.fournisseurs.filter(f => f.ref), autres = E.fournisseurs.filter(f => !f.ref);
    const tronc = qs.filter(q => q.fournisseur == null), totTronc = tronc.reduce((s, q) => s + (maxDe(q) || 0), 0);
    const secs = [section('Tronc commun', 'posé à chaque fournisseur, pour les comparer entre eux', tronc, qs, '', '', true)]
      .concat(refs.map(f => section(f.nom, (f.cat || AU_GENRE(f.genre)) + ' · questions propres', qs.filter(q => q.fournisseur === f.id), qs, f.id, f.nom, false)));
    const ligneF = f => `<div class="af-fo"><div class="af-row" style="align-items:baseline"><b style="font-weight:400;font-size:14px">${esc(f.nom)}</b>
        <span class="af-bar">${nf(totTronc + qs.filter(q => q.fournisseur === f.id).reduce((s, q) => s + (maxDe(q) || 0), 0), 2)} pts</span>
        <button class="btn btn--danger btn--sm" data-unref="${esc(f.id)}" style="margin-left:auto">Retirer</button></div>
      <div class="af-row"><label class="af-f grand">Catégorie<input data-fo="${esc(f.id)}:cat" value="${esc(f.cat)}" placeholder="Glaces, IT & numérique…"></label>
        <label class="af-f">Genre<select data-fo="${esc(f.id)}:genre">${Object.entries(E.genres).map(([c, l]) => `<option value="${c}"${f.genre === c ? ' selected' : ''}>${l}</option>`).join('')}</select></label></div>
      <label class="af-f">E-mail pour la copie des résultats<input type="email" data-fo="${esc(f.id)}:email" value="${esc(f.emailSource === 'fiche' ? f.email : '')}" placeholder="${esc(f.emailSource === 'carnet' ? f.email + ' (carnet de la centrale)' : 'adresse du fournisseur')}"></label></div>`;
    return `<div class="af-grid"><div>${secs.join('')}</div><div>
      <div class="af-card"><h2>Fournisseurs référencés <small>${refs.length}</small></h2>
        <p class="af-mu af-sm" style="margin:0 0 8px">Chacun est noté par tous les franchisés. Un fournisseur ajouté ici reçoit le tronc commun.</p>
        ${refs.length ? refs.map(ligneF).join('') : '<div class="af-vide">Aucun fournisseur référencé.</div>'}
        <div class="af-box" style="margin-top:12px"><b>Ajouter</b>
          <div class="af-row"><label class="af-f grand">Nom<input data-nf="nom" placeholder="Glace Rawette"></label><label class="af-f">Catégorie<input data-nf="cat" placeholder="Glaces"></label></div>
          <div class="af-row"><label class="af-f">Genre<select data-nf="genre">${Object.entries(E.genres).map(([c, l]) => `<option value="${c}">${l}</option>`).join('')}</select></label>
            <label class="af-f grand">E-mail (copie des résultats)<input data-nf="email" type="email"></label></div>
          <div><button class="btn btn--primary btn--sm" data-fajout${S.occupe ? ' disabled' : ''}>Référencer</button></div></div>
        ${autres.length ? `<details style="margin-top:12px"><summary class="af-mu af-sm" style="cursor:pointer">Autres fournisseurs du cockpit (${autres.length})</summary>
          <table class="af-tbl" style="margin-top:6px"><tbody>${autres.map(f => `<tr><td>${esc(f.nom)}<span class="af-mu af-sm" style="display:block">${esc(f.perimetre || '')}</span></td><td style="text-align:right"><button class="btn btn--sm" data-ref="${esc(f.id)}">Référencer</button></td></tr>`).join('')}</tbody></table></details>` : ''}
      </div>
      <div class="af-card"><h2>Seuils du score</h2><p class="af-mu af-sm" style="margin:0 0 8px">En % du maximum de chaque fournisseur.</p>
        <div class="af-row"><label class="af-f n">Bon dès<input type="number" data-seuil="bon" value="${esc(E.seuils.bon)}"></label><label class="af-f n">À surveiller dès<input type="number" data-seuil="surveiller" value="${esc(E.seuils.surveiller)}"></label>
        <button class="btn btn--sm" data-seuils>Enregistrer</button></div><p class="af-mu af-sm" style="margin:8px 0 0">En dessous : « Revoir le contrat ».</p></div>
    </div></div>`;
  }
  const AU_GENRE = g => (S.etat.genres || {})[g] || 'Fournisseur';
  function brouillonQ(q) {
    const b = JSON.parse(JSON.stringify(q || {}));
    return Object.assign({ libelle: '', aide: '', type: 'note', obligatoire: true, coef: 1, min: '', max: '', choix: [], paliers: [], unite: '', si: null, regle: null }, b);
  }
  function typeChange(q, type) {
    q.type = type;
    if (type === 'ouinon') q.choix = [{ code: 'oui', libelle: 'Oui', points: 5 }, { code: 'non', libelle: 'Non', points: 0 }];
    else if ((type === 'choix' || type === 'multi') && (!q.choix.length || q.choix[0].code === 'oui' && q.choix.length === 2)) q.choix = [{ libelle: '', points: 0 }, { libelle: '', points: 0 }];
    if (type === 'nombre' && !q.paliers.length) q.paliers = [{ jusqua: 0, points: 5 }, { jusqua: null, points: 0 }];
    if (['texte', 'photo', 'date'].includes(type)) q.regle = null;
  }
  async function sauverQuestion() {
    const e = S.edit, q = e.q;
    const corps = { libelle: q.libelle, aide: q.aide, type: q.type, obligatoire: q.obligatoire, coef: q.coef, min: q.min, max: q.max, unite: q.unite,
      choix: q.choix, paliers: q.paliers.map(p => ({ jusqua: p.jusqua === '' ? null : p.jusqua, points: p.points })), si: q.si, regle: q.regle };
    if (e.id == null) Object.assign(corps, { fournisseur: e.cle || null, parent: e.parent || null });
    await action(async () => {
      await (e.id == null ? api('POST', 'questions', corps) : api('PUT', 'questions/' + e.id, corps));
      S.edit = null; await chargeEtat();
    }, 'Question enregistrée.');
  }
  async function deplacer(id, sens) {
    const qs = S.etat.questions, q = qs.find(x => x.id === id);
    const groupe = qs.filter(x => !x.parent && (x.fournisseur || '') === (q.fournisseur || ''));
    const i = groupe.findIndex(x => x.id === id), j = i + sens;
    if (j < 0 || j >= groupe.length) return;
    [groupe[i], groupe[j]] = [groupe[j], groupe[i]];
    await action(async () => { await api('POST', 'questions/ordre', { ids: groupe.map(x => x.id) }); await chargeEtat(); });
  }

  /* --- l'onglet Campagnes ----------------------------------------------------------------------- */
  const STATUT_C = { brouillon: ['Brouillon', 'chip chip--draft'], envoyee: ['Envoyée', 'chip chip--ok'], cloturee: ['Close', 'chip chip--neutral'] };
  const STATUT_D = { fini: ['Répondu', 'chip chip--ok'], encours: ['En cours', 'tag-warn'], jamais: ['Pas ouvert', 'tag-stop'], nonenvoye: ['Non envoyé', 'tag-stop'] };
  function formCampagne(c) {
    const dans14 = new Date(Date.now() + 14 * 864e5).toISOString().slice(0, 10);
    const rel = (c ? c.relances : S.etat.relancesDefaut).join(', ');
    const env = c && c.statut === 'envoyee';
    return `<div class="af-box"><b>${c ? 'Réglages de la campagne' : 'Nouvelle campagne'}</b>
      <div class="af-row"><label class="af-f grand">Nom<input data-cf="nom" value="${esc(c ? c.nom : '')}" placeholder="Audit fournisseurs S2 2026"${env ? ' disabled' : ''}></label>
        <label class="af-f">Clôture<input type="date" data-cf="cloture" value="${esc(c ? c.cloture : dans14)}"></label>
        <label class="af-f">Relances (jours ; -1 = la veille)<input data-cf="relances" value="${esc(rel)}" style="width:120px"></label></div>
      <label class="af-f">Message ajouté à l’e-mail (facultatif)<textarea data-cf="message"${env ? ' disabled' : ''}>${esc(c ? c.message : '')}</textarea></label>
      <label class="af-chk"><input type="checkbox" data-cf="push"${!c || c.push ? ' checked' : ''}${env ? ' disabled' : ''}> notification push aux appareils du magasin, en plus de l’e-mail</label>
      <div class="af-row"><button class="btn btn--primary btn--sm" data-csave="${c ? c.id : ''}"${S.occupe ? ' disabled' : ''}>${c ? 'Enregistrer' : 'Créer le brouillon'}</button>${c ? '' : '<button class="btn btn--ghost btn--sm" data-cannule>Annuler</button>'}</div></div>`;
  }
  function ongletCampagnes() {
    const E = S.etat, cs = E.campagnes;
    const liste = cs.length ? `<table class="af-tbl"><thead><tr><th>Campagne</th><th>Statut</th><th>Envoyée</th><th>Clôture</th><th class="num">Réponses</th></tr></thead><tbody>
      ${cs.map(c => `<tr class="clic${S.camp === c.id ? ' on' : ''}" data-camp="${c.id}"><td>${esc(c.nom)}</td><td><span class="${STATUT_C[c.statut][1]}">${STATUT_C[c.statut][0]}</span></td>
        <td>${jour(c.envoyeLe)}</td><td>${dateFr(c.cloture)}</td><td class="num">${c.statut === 'brouillon' ? '—' : c.compte.fini + ' / ' + c.compte.total}</td></tr>`).join('')}</tbody></table>`
      : '<div class="af-vide">Aucune campagne. Créez la première : le formulaire actuel partira tel quel à tous les magasins ouverts.</div>';
    let detail = '';
    const D = S.campD;
    if (D && S.camp === D.campagne.id) {
      const c = D.campagne, k = c.compte;
      const kpi = (v, l) => `<div class="kpi"><div class="kpi__value">${v}</div><div class="kpi__label">${l}</div></div>`;
      const btns = c.statut === 'brouillon'
        ? `<button class="btn btn--primary" data-envoyer="${c.id}"${S.occupe ? ' disabled' : ''}>Envoyer aux franchisés</button><a class="btn" href="../audit/?apercu=1" target="_blank" rel="noopener">Aperçu du formulaire ↗</a><button class="btn btn--danger" data-csuppr="${c.id}">Supprimer</button>`
        : c.statut === 'envoyee'
          ? `<button class="btn btn--primary" data-relancer="${c.id}"${S.occupe ? ' disabled' : ''}>Relancer les ${k.total - k.fini} qui n’ont pas fini</button><a class="btn" href="../audit/?apercu=1&c=${c.id}" target="_blank" rel="noopener">Aperçu ↗</a><button class="btn" data-cloturer="${c.id}">Clôturer maintenant</button><button class="btn btn--ghost" data-voirres="${c.id}">Résultats</button>`
          : `<button class="btn btn--primary" data-voirres="${c.id}">Résultats et copies</button>`;
      const lignes = D.destinataires.map(d => `<tr><td>${esc(d.court)}<span class="af-mu af-sm" style="display:block">${esc(d.email || 'pas d’adresse (Qui fait quoi)')}${d.push ? ' · push' : ''}</span></td>
        <td class="num">${d.avancement} / ${D.nbFournisseurs}</td><td><span class="${STATUT_D[d.statut][1]}">${STATUT_D[d.statut][0]}</span>${d.motif && d.statut !== 'fini' ? `<span class="af-mu af-sm" style="display:block">${esc(d.motif)}</span>` : ''}</td>
        <td>${d.termineLe ? 'fini ' + jour(d.termineLe) : d.ouvertLe ? 'ouvert ' + jour(d.ouvertLe) : d.envoyeLe ? 'envoyé ' + jour(d.envoyeLe) : '—'}</td>
        <td class="num">${d.relances || ''}</td><td>${c.statut === 'envoyee' && d.statut !== 'fini' ? `<button class="btn btn--sm" data-relun="${d.id}">Relancer</button>` : ''}</td></tr>`).join('');
      detail = `<div class="af-card"><h2>${esc(c.nom)} <span class="${STATUT_C[c.statut][1]}">${STATUT_C[c.statut][0]}</span><small>${c.fournisseurs || E.fournisseurs.filter(f => f.ref).length} fournisseurs · clôture ${dateFr(c.cloture)} · relances ${c.relances.map(n => n < 0 ? 'veille' : 'J+' + n).join(', ')}</small></h2>
        ${c.statut === 'brouillon' ? `<p class="af-msg info">À l’envoi, le formulaire est figé et chacun des ${E.magasins} magasins ouverts reçoit son lien personnel${E.smtp ? '' : ' — attention : SMTP non configuré, seuls les push partiront'}.</p>` : ''}
        ${c.statut !== 'brouillon' ? `<div class="kpi-row">${kpi(k.fini + ' / ' + k.total, 'Ont répondu')}${kpi(k.encours, 'En cours')}${kpi(k.jamais, 'Pas ouvert')}${kpi(dateFr(c.cloture), 'Clôture')}</div>` : ''}
        <div class="af-row" style="margin:10px 0">${btns}</div>
        ${c.statut !== 'cloturee' ? formCampagne(c) : ''}
        ${D.destinataires.length ? `<div style="overflow-x:auto;margin-top:12px"><table class="af-tbl"><thead><tr><th>Boutique</th><th class="num">Avancement</th><th>Statut</th><th>Activité</th><th class="num">Relances</th><th></th></tr></thead><tbody>${lignes}</tbody></table></div>` : ''}</div>`;
    }
    return `<div class="af-grid"><div>${detail || '<div class="af-card"><div class="af-vide">Choisissez une campagne, ou créez-en une.</div></div>'}</div><div>
      <div class="af-card"><h2>Campagnes<span class="a"><button class="btn btn--sm" data-cnouv>+ Nouvelle</button></span></h2>${S.nouvelle ? formCampagne(null) : ''}${liste}</div></div></div>`;
  }
  const lireForm = sel => { const o = {}; $.querySelectorAll(sel).forEach(el => { const k = el.dataset.cf; o[k] = el.type === 'checkbox' ? el.checked : el.value; }); return o; };
  async function ouvrirCampagne(id) { S.camp = id; S.campD = await api('GET', 'campagnes/' + id); }

  /* --- l'onglet Résultats ----------------------------------------------------------------------- */
  const STATUT_F = { bon: ['Bon', 'chip chip--ok'], surveiller: ['À surveiller', 'tag-warn'], revoir: ['Revoir le contrat', 'tag-stop'] };
  function rep(qr) {
    if (!qr || !qr.repartition) return '';
    const tot = qr.repartition.reduce((s, r) => s + r.n, 0) || 1, n = qr.repartition.length;
    // Du meilleur (bleu) au moins bon (framboise) dans l'ordre des choix ; la note de 1 (framboise) à 5 (bleu).
    const teinte = i => qr.type === 'note' ? TEINTES[i + 1] : TEINTES[n === 2 ? (i ? 1 : 5) : n === 3 ? [5, 3, 1][i] : Math.max(1, 5 - i)];
    return `<span class="af-rep" title="${esc(qr.repartition.map(r => r.n + ' ' + r.libelle).join(' · '))}">${qr.repartition.map((r, i) => r.n ? `<i style="width:${Math.max(14, r.n / tot * 140)}px;background:${teinte(i)[0]};color:${teinte(i)[1]}">${r.n}</i>` : '').join('')}</span>`;
  }
  const cle = f => f.questions.find(q => q.commun && !q.parent && (q.type === 'choix' || q.type === 'ouinon'));
  function ongletResultats() {
    const camps = S.etat.campagnes.filter(c => c.statut !== 'brouillon');
    if (!camps.length) return '<div class="af-card"><div class="af-vide">Aucune campagne envoyée pour l’instant.</div></div>';
    const R = S.res;
    const choix = `<label class="af-f">Campagne<select data-rescamp>${camps.map(c => `<option value="${c.id}"${S.resCamp === c.id ? ' selected' : ''}>${esc(c.nom)} — ${STATUT_C[c.statut][0].toLowerCase()}</option>`).join('')}</select></label>`;
    if (!R) return `<div class="af-card">${choix}<p class="af-mu">Chargement…</p></div>`;
    const ligne = f => {
      const k = cle(f), st = STATUT_F[f.statut];
      return `<tr class="clic${S.resF === f.id ? ' on' : ''}" data-resf="${esc(f.id)}"><td>${esc(f.nom)}<span class="af-mu af-sm" style="display:block">${esc(f.cat || '')}</span></td>
        <td><span class="af-meter"><i style="width:${f.pct || 0}%"></i></span><span class="af-bar">${f.pct == null ? '—' : nf(f.pct) + ' %'}</span></td>
        <td class="num ${f.ecart > 0 ? 'af-up' : f.ecart < 0 ? 'af-down' : ''}">${f.ecart == null ? '—' : (f.ecart > 0 ? '▲ +' : f.ecart < 0 ? '▼ ' : '= ') + nf(f.ecart)}</td>
        <td>${rep(k)}</td><td>${st ? `<span class="${st[1]}">${st[0]}</span>` : '—'}</td><td class="num">${f.reponses}</td>
        <td class="num">${f.alertes.filter(a => a.statut === 'ouvert').length || ''}</td><td>${f.copie.statut === 'envoyee' ? '<span class="chip chip--ok">copie envoyée</span>' : f.copie.statut === 'brouillon' ? '<span class="chip chip--draft">copie en brouillon</span>' : R.campagne.statut === 'cloturee' ? '<span class="tag-warn">copie à écrire</span>' : ''}</td></tr>`;
    };
    const kq = R.fournisseurs.map(cle).find(Boolean);
    const tete = `<div class="af-card"><div class="af-row">${choix}<span class="af-mu" style="margin-left:auto">${R.reponses.fini} / ${R.reponses.total} boutiques ont répondu${R.campagne.statut === 'envoyee' ? ' · campagne encore ouverte' : ''}</span></div>
      <div style="overflow-x:auto;margin-top:10px"><table class="af-tbl"><thead><tr><th>Fournisseur</th><th>Score</th><th class="num">vs précédente</th><th>${esc(kq ? kq.libelle.replace(/^Souhaitez-vous /, '').replace(/ avec .*$/, ' ?') : 'Répartition')}</th><th>Statut</th><th class="num">Rép.</th><th class="num">Actions</th><th>Copie</th></tr></thead>
      <tbody>${R.fournisseurs.map(ligne).join('')}</tbody></table></div>
      ${kq ? `<div class="af-leg">${(kq.repartition || []).map((r, i, a) => { const t = a.length === 2 ? (i ? 1 : 5) : a.length === 3 ? [5, 3, 1][i] : Math.max(1, 5 - i); return `<span><s style="background:${TEINTES[t][0]}"></s>${esc(r.libelle)}</span>`; }).join('')}</div>` : ''}</div>`;
    const f = R.fournisseurs.find(x => x.id === S.resF);
    return tete + (f ? detailFournisseur(f, R) : '<div class="af-card"><div class="af-vide">Choisissez un fournisseur pour voir le détail et préparer sa copie.</div></div>');
  }
  function detailFournisseur(f, R) {
    const scored = f.questions.filter(q => q.max != null);
    const st = STATUT_F[f.statut];
    const qlignes = f.questions.map(q => {
      let v = '';
      if (q.type === 'note') v = (q.moyenne == null ? '—' : nf(q.moyenne, 2) + ' / 5') + ' ' + rep(q);
      else if (q.type === 'nombre') v = q.moyenne == null ? '—' : 'moyenne ' + nf(q.moyenne, 2);
      else if (q.repartition) v = rep(q);
      else v = `<span class="af-mu">${q.n} réponse(s)</span>`;
      return `<tr><td>${q.parent ? '↳ ' : ''}${esc(q.libelle)}${q.commun ? ' <span class="af-mu af-sm">commun</span>' : ''}</td><td>${v}</td><td class="num">${q.max != null && q.pointsMoyens != null ? nf(q.pointsMoyens, 2) + ' / ' + nf(q.max, 2) : ''}</td></tr>`;
    }).join('');
    const heat = f.lignes.length ? `<div style="overflow-x:auto"><table class="af-heat"><thead><tr><th>Boutique</th>${scored.map(q => `<th title="${esc(q.libelle)}">${esc(q.libelle.length > 34 ? q.libelle.slice(0, 32) + '…' : q.libelle)}</th>`).join('')}<th>Score</th></tr></thead><tbody>
      ${f.lignes.map(l => `<tr><td class="b">${esc(l.court)}</td>${scored.map(q => { const c = l.cellules[q.id] || {}; const t = TEINTES[c.teinte]; return `<td class="c" style="${t ? `background:${t[0]};color:${t[1]}` : ''}">${esc(c.aff ? (c.aff.length > 10 ? c.aff.slice(0, 9) + '…' : c.aff) : '')}</td>`; }).join('')}<td class="c">${l.pct == null ? '' : nf(l.pct, 0) + ' %'}</td></tr>`).join('')}
      </tbody></table></div><div class="af-leg">Note ${[1, 2, 3, 4, 5].map(n => `<span><s style="background:${TEINTES[n][0]}"></s>${n}</span>`).join('')}<span>1 = très mauvais · 5 = très bien (les choix : part des points)</span></div>` : '<div class="af-vide">Aucune boutique n’a encore terminé.</div>';
    const al = f.alertes.length ? f.alertes.map(a => `<div class="af-com"><span style="color:var(--stop)">!</span><div>${esc(a.titre)} <span class="af-mu">— ${esc(a.court)}</span>
      <small>${a.alerte ? 'alerte · ' : ''}${jour(a.le)} · <button class="linklike" data-alerte="${a.id}" data-st="${a.statut === 'ouvert' ? 'traite' : 'ouvert'}">${a.statut === 'ouvert' ? 'marquer traitée' : 'traitée — rouvrir'}</button></small></div></div>`).join('') : '<p class="af-mu">Aucune règle déclenchée.</p>';
    const k = f.copie, coms = f.commentaires;
    const copie = R.campagne.statut === 'cloturee' || k.statut ? `<div class="af-card"><h2>Copie pour ${esc(f.nom)} <small>${k.statut === 'envoyee' ? 'envoyée le ' + jour(k.envoyeLe) : k.statut === 'brouillon' ? 'brouillon' : 'à écrire'}</small></h2>
      ${k.motif ? `<p class="af-msg ko">Dernier envoi refusé : ${esc(k.motif)}</p>` : ''}
      <div class="af-row"><label class="af-f grand">Envoyée à<input type="email" data-cp="email" value="${esc(k.email || '')}" placeholder="${esc(k.emailFiche || 'adresse du fournisseur')}"></label></div>
      <label class="af-f" style="margin-top:8px">Note du franchiseur (en tête de la copie)<textarea data-cp="note" style="min-height:120px">${esc(k.note)}</textarea></label>
      <div class="af-box" style="margin-top:10px"><b>Commentaires joints (${coms.length})</b>${coms.length ? coms.map(c => `<label class="af-chk" style="align-items:flex-start"><input type="checkbox" data-cpcom="${c.id}"${k.commentaires.includes(c.id) ? ' checked' : ''}> <span>« ${esc(c.texte)} » <span class="af-mu">— ${esc(c.court)}, ${esc(c.question)}</span></span></label>`).join('') : '<span class="af-mu">Aucun commentaire.</span>'}</div>
      <div class="af-row" style="margin-top:10px"><label class="af-chk"><input type="checkbox" data-cp="masquer"${k.masquer ? ' checked' : ''}> masquer le nom des boutiques</label><label class="af-chk"><input type="checkbox" data-cp="pdf"${k.pdf ? ' checked' : ''}> joindre le PDF</label></div>
      <div class="af-row" style="margin-top:12px"><button class="btn btn--sm" data-cpsave${S.occupe ? ' disabled' : ''}>Enregistrer</button><button class="btn btn--sm" data-cpapercu${S.occupe ? ' disabled' : ''}>Aperçu</button>
        <button class="btn btn--primary btn--sm" data-cpenvoi${S.occupe ? ' disabled' : ''}>${k.statut === 'envoyee' ? 'Renvoyer' : 'Envoyer'} à ${esc(f.nom)}</button></div>
      ${S.apercu ? '<p class="af-mu af-sm" style="margin:10px 0 0">Aperçu affiché sous les résultats.</p>' : ''}</div>`
      : `<div class="af-card"><h2>Copie pour ${esc(f.nom)}</h2><p class="af-mu">Elle se prépare à la clôture de la campagne (le ${dateFr(R.campagne.cloture)}, ou « Clôturer maintenant »).</p></div>`;
    return `<div class="af-grid"><div>
      <div class="af-card"><h2>${esc(f.nom)} <small>${esc(f.cat || '')}</small>${st ? `<span class="${st[1]}">${st[0]}</span>` : ''}</h2>
        <div class="kpi-row"><div class="kpi"><div class="kpi__value">${f.pct == null ? '—' : nf(f.pct) + ' %'}</div><div class="kpi__label">Score moyen${f.avant != null ? ' · avant ' + nf(f.avant) + ' %' : ''}</div></div>
          <div class="kpi"><div class="kpi__value">${f.points == null ? '—' : nf(f.points) + ' / ' + nf(f.max, 0)}</div><div class="kpi__label">Points moyens</div></div>
          <div class="kpi"><div class="kpi__value">${f.reponses}</div><div class="kpi__label">Boutiques</div></div></div>
        <table class="af-tbl" style="margin-top:10px"><thead><tr><th>Question</th><th>Réponses</th><th class="num">Points</th></tr></thead><tbody>${qlignes}</tbody></table></div>
      <div class="af-card"><h2>Réponses par boutique</h2>${heat}</div>
      <div class="af-card"><h2>Ce que disent les franchisés <small>${coms.length}</small></h2>${coms.length ? coms.map(c => `<div class="af-com"><span class="af-mu">«</span><div><q>${esc(c.texte)}</q><small>${esc(c.court)} · ${esc(c.question)}${c.reponse ? ' · ' + esc(c.reponse) : ''}</small></div></div>`).join('') : '<p class="af-mu">Aucun commentaire.</p>'}</div>
    </div><div><div class="af-card"><h2>Actions et alertes <small>${f.alertes.filter(a => a.statut === 'ouvert').length} ouvertes</small></h2>${al}</div>${copie}</div></div>
      ${S.apercu ? `<div class="af-card"><h2>Aperçu de la copie <small>ce que reçoit ${esc(f.nom)}</small><span class="a"><button class="btn btn--ghost btn--sm" data-apercuferme>Fermer</button></span></h2><iframe class="af-apercu" style="height:900px" title="Aperçu de la copie" srcdoc="${esc(S.apercu)}"></iframe></div>` : ''}`;
  }
  async function chargeRes(id) { S.resCamp = id; S.res = null; S.apercu = null; rendu(); S.res = await api('GET', 'campagnes/' + id + '/resultats'); if (S.res && !S.res.fournisseurs.some(f => f.id === S.resF)) S.resF = null; }
  function lireCopie() {
    const o = { commentaires: [] };
    $.querySelectorAll('[data-cp]').forEach(el => { o[el.dataset.cp] = el.type === 'checkbox' ? el.checked : el.value; });
    $.querySelectorAll('[data-cpcom]').forEach(el => { if (el.checked) o.commentaires.push(Number(el.dataset.cpcom)); });
    return o;
  }

  /* --- rendu ------------------------------------------------------------------------------------ */
  function rendu() {
    if (!S.etat) return;
    const nbC = S.etat.campagnes.length, refs = S.etat.fournisseurs.filter(f => f.ref).length;
    const tabs = [['formulaire', 'Formulaire', refs], ['campagnes', 'Campagnes', nbC], ['resultats', 'Résultats', null]];
    const actif = document.activeElement, cleFocus = actif && actif.closest('#af') ? [...actif.attributes].filter(a => a.name.startsWith('data-')).map(a => a.name + '=' + a.value).join('&') : null;
    $.innerHTML = `<div class="af-hd"><div><h1>Audit fournisseurs</h1><p>Deux fois par an, chaque franchisé note seul les fournisseurs et prestataires référencés, par un lien reçu par e-mail et par notification. Chaque fournisseur reçoit ensuite sa copie, avec la note du franchiseur.</p></div>
      <div class="droite"><a class="btn btn--sm" href="../audit/?apercu=1" target="_blank" rel="noopener">Aperçu du formulaire ↗</a>${EMBED ? '<a class="btn btn--sm" href="./" target="_blank" rel="noopener">Ouvrir seul ↗</a>' : ''}</div></div>
      <nav class="page-tabs">${tabs.map(([k, l, n]) => `<button class="page-tabs__tab${S.tab === k ? ' is-on' : ''}" data-tab="${k}">${l}${n ? ` <span class="page-tabs__count">${n}</span>` : ''}</button>`).join('')}</nav>
      ${S.msg ? `<div class="af-msg ${S.msg.genre}">${esc(S.msg.texte)}</div>` : ''}
      ${S.tab === 'formulaire' ? ongletFormulaire() : S.tab === 'campagnes' ? ongletCampagnes() : ongletResultats()}`;
    if (cleFocus) { const el = $.querySelector(cleFocus.split('&').map(p => { const [k, v] = p.split('='); return `[${k}="${CSS.escape(v)}"]`; }).join('')); if (el && el.focus) el.focus(); }
    hauteur();
  }
  function hauteur() { if (EMBED && window.parent !== window) { try { window.parent.postMessage({ auHauteur: document.documentElement.scrollHeight }, location.origin); } catch (e) { /* hors cockpit */ } } }
  if (window.ResizeObserver) new ResizeObserver(hauteur).observe(document.body);

  /* --- événements ------------------------------------------------------------------------------- */
  $.addEventListener('click', async ev => {
    const b = ev.target.closest('button, tr[data-camp], tr[data-resf]'); if (!b || b.disabled) return;
    const d = b.dataset;
    if (d.tab) { S.tab = d.tab; S.msg = null; S.edit = null; if (d.tab === 'resultats' && !S.res) { const c = S.etat.campagnes.find(x => x.statut !== 'brouillon'); if (c) { await action(() => chargeRes(c.id)); return; } } rendu(); return; }
    S.msg = null;
    // Formulaire
    if (d.qnouv != null) { S.edit = { id: null, cle: d.qnouv, parent: null, q: brouillonQ({ fournisseur: d.qnouv || null }) }; rendu(); return; }
    if (d.qsous) { const m = S.etat.questions.find(x => x.id === Number(d.qsous)); S.edit = { id: null, cle: m.fournisseur || '', parent: m.id, mere: m, q: brouillonQ({ type: 'texte', libelle: 'Pourquoi ?' }) }; rendu(); setTimeout(() => { const el = $.querySelector('[data-e="libelle"]'); if (el) el.scrollIntoView({ block: 'center' }); }, 0); return; }
    if (d.qedit) { const q = S.etat.questions.find(x => x.id === Number(d.qedit)); S.edit = { id: q.id, cle: q.fournisseur || '', parent: q.parent, mere: q.parent ? S.etat.questions.find(x => x.id === q.parent) : null, q: brouillonQ(q) }; rendu(); return; }
    if (d.qannule != null) { S.edit = null; rendu(); return; }
    if (d.qsave != null) { await sauverQuestion(); return; }
    if (d.qsuppr) { const q = S.etat.questions.find(x => x.id === Number(d.qsuppr)); if (confirm('Retirer « ' + q.libelle + ' »' + (q.parent ? '' : ' et ses sous-questions') + ' ? Les campagnes déjà envoyées gardent la leur.')) await action(async () => { await api('DELETE', 'questions/' + q.id); await chargeEtat(); }, 'Question retirée.'); return; }
    if (d.monte) { await deplacer(Number(d.monte), -1); return; }
    if (d.descend) { await deplacer(Number(d.descend), 1); return; }
    if (d.chplus != null) { S.edit.q.choix.push({ libelle: '', points: 0 }); rendu(); return; }
    if (d.chx) { S.edit.q.choix.splice(Number(d.chx), 1); rendu(); return; }
    if (d.palplus != null) { S.edit.q.paliers.push({ jusqua: '', points: 0 }); rendu(); return; }
    if (d.palx) { S.edit.q.paliers.splice(Number(d.palx), 1); rendu(); return; }
    if (d.fajout != null) {
      const o = {}; $.querySelectorAll('[data-nf]').forEach(el => { o[el.dataset.nf] = el.value; });
      await action(async () => { await api('POST', 'fournisseurs', o); await chargeEtat(); }, (o.nom || 'Le fournisseur') + ' est référencé : il reçoit le tronc commun.'); return;
    }
    if (d.ref || d.unref) { const id = d.ref || d.unref; await action(async () => { await api('PUT', 'fournisseurs/' + id, { ref: !!d.ref }); await chargeEtat(); }, d.ref ? 'Fournisseur référencé.' : 'Fournisseur retiré de l’audit (ses questions propres restent, inactives).'); return; }
    if (d.seuils != null) { const o = {}; $.querySelectorAll('[data-seuil]').forEach(el => { o[el.dataset.seuil] = el.value; }); await action(async () => { await api('PUT', 'seuils', o); await chargeEtat(); }, 'Seuils enregistrés.'); return; }
    // Campagnes
    if (d.cnouv != null) { S.nouvelle = !S.nouvelle; rendu(); return; }
    if (d.cannule != null) { S.nouvelle = false; rendu(); return; }
    if (d.csave != null) {
      const o = lireForm('[data-cf]'); o.relances = String(o.relances || '').split(/[,; ]+/).filter(Boolean).map(Number);
      await action(async () => { const r = await api(d.csave ? 'PUT' : 'POST', 'campagnes' + (d.csave ? '/' + d.csave : ''), o); S.nouvelle = false; await chargeEtat(); await ouvrirCampagne(r.id); }, d.csave ? 'Campagne enregistrée.' : 'Brouillon créé : vérifiez l’aperçu, puis envoyez.'); return;
    }
    if (d.camp) { await action(() => ouvrirCampagne(Number(d.camp))); return; }
    if (d.envoyer) {
      const refs = S.etat.fournisseurs.filter(f => f.ref).length;
      if (!confirm('Envoyer maintenant à ' + S.etat.magasins + ' magasins, pour ' + refs + ' fournisseurs ? Le formulaire sera figé pour cette campagne.')) return;
      await action(async () => { const r = await api('POST', 'campagnes/' + d.envoyer + '/envoyer'); await chargeEtat(); await ouvrirCampagne(Number(d.envoyer)); if (r.sansEnvoi) msg('info', r.envoyes + ' e-mail(s) envoyé(s) ; ' + r.sansEnvoi + ' sans e-mail : ' + r.motifs.slice(0, 4).join(' · ')); }, 'Campagne envoyée.'); return;
    }
    if (d.csuppr) { if (confirm('Supprimer ce brouillon ?')) await action(async () => { await api('DELETE', 'campagnes/' + d.csuppr); S.camp = null; S.campD = null; await chargeEtat(); }, 'Brouillon supprimé.'); return; }
    if (d.relancer) { if (confirm('Relancer maintenant tous les magasins qui n’ont pas fini ?')) await action(async () => { const r = await api('POST', 'campagnes/' + d.relancer + '/relancer', {}); await ouvrirCampagne(Number(d.relancer)); msg(r.motifs.length ? 'info' : 'ok', r.relances + ' relance(s) envoyée(s)' + (r.motifs.length ? ' ; sans envoi : ' + r.motifs.slice(0, 4).join(' · ') : '.')); }); return; }
    if (d.relun) { await action(async () => { await api('POST', 'campagnes/' + S.camp + '/relancer', { destinataire: Number(d.relun) }); await ouvrirCampagne(S.camp); }, 'Relance envoyée.'); return; }
    if (d.cloturer) { if (confirm('Clôturer maintenant ? Les franchisés ne pourront plus répondre ; les copies des fournisseurs seront à écrire.')) await action(async () => { await api('POST', 'campagnes/' + d.cloturer + '/cloturer'); await chargeEtat(); await ouvrirCampagne(Number(d.cloturer)); }, 'Campagne close : les copies sont à écrire dans Résultats.'); return; }
    if (d.voirres) { S.tab = 'resultats'; await action(() => chargeRes(Number(d.voirres))); return; }
    // Résultats
    if (d.resf) { S.resF = d.resf; S.apercu = null; rendu(); return; }
    if (d.apercuferme != null) { S.apercu = null; rendu(); return; }
    if (d.alerte) { await action(async () => { await api('PUT', 'alertes/' + d.alerte, { statut: d.st }); await chargeRes(S.resCamp); }); return; }
    if (d.cpsave != null || d.cpapercu != null || d.cpenvoi != null) {
      const f = S.res.fournisseurs.find(x => x.id === S.resF), o = lireCopie();
      if (d.cpenvoi != null && !confirm('Envoyer la copie à ' + (o.email || f.copie.emailFiche || 'l’adresse du fournisseur') + ' ?')) return;
      await action(async () => {
        await api('PUT', 'copies/' + S.resCamp + '/' + f.id, o);
        if (d.cpapercu != null) { const a = await api('GET', 'copies/' + S.resCamp + '/' + f.id + '/apercu'); S.res = await api('GET', 'campagnes/' + S.resCamp + '/resultats'); S.apercu = a.html; return; }
        if (d.cpenvoi != null) { const r = await api('POST', 'copies/' + S.resCamp + '/' + f.id + '/envoyer'); msg('ok', 'Copie envoyée à ' + r.a + (r.pdf ? ' (avec le PDF)' : '') + '.'); }
        S.res = await api('GET', 'campagnes/' + S.resCamp + '/resultats'); S.apercu = null;
      }, d.cpsave != null ? 'Copie enregistrée.' : null);
    }
  });
  $.addEventListener('input', ev => {
    const t = ev.target, d = t.dataset;
    if (!S.edit) return;
    const q = S.edit.q;
    if (d.e && d.e !== 'type' && d.e !== 'obligatoire') q[d.e] = t.value;
    if (d.ch) { const [i, k] = d.ch.split(':'); q.choix[Number(i)][k] = t.value; }
    if (d.pal) { const [i, k] = d.pal.split(':'); q.paliers[Number(i)][k] = t.value; }
    if (d.rg) { q.regle = Object.assign({ op: 'le', valeur: null, commentaire: false, action: '', alerte: false }, q.regle || {}); if (d.rg === 'op' && q.regle.op === 'in') q.regle.valeur = null; q.regle[d.rg] = t.value; }
    if (d.si) { q.si = Object.assign({ op: 'le', valeur: null }, q.si && q.si.op !== 'in' ? q.si : {}); q.si[d.si] = t.value; }
  });
  $.addEventListener('change', async ev => {
    const t = ev.target, d = t.dataset;
    if (d.rescamp != null) { await action(() => chargeRes(Number(t.value))); return; }
    if (d.fo) { const [id, k] = d.fo.split(':'); await action(async () => { await api('PUT', 'fournisseurs/' + id, { [k]: t.value }); await chargeEtat(); }, 'Fiche enregistrée.'); return; }
    if (!S.edit) return;
    const q = S.edit.q;
    if (d.e === 'type') { typeChange(q, t.value); rendu(); return; }
    if (d.e === 'obligatoire') { q.obligatoire = t.checked; return; }
    const coches = attr => [...$.querySelectorAll('[data-' + attr + ']')].filter(x => x.checked).map(x => x.getAttribute('data-' + attr));
    if (d.rgv != null) { const v = coches('rgv'); q.regle = Object.assign({ commentaire: false, action: '', alerte: false }, q.regle && q.regle.op === 'in' ? q.regle : {}, { op: 'in', valeur: v }); return; }
    if (d.rgc) { q.regle = Object.assign({ op: ['ouinon', 'choix', 'multi'].includes(q.type) ? 'in' : 'le', valeur: ['ouinon', 'choix', 'multi'].includes(q.type) ? [] : 2, action: '' }, q.regle || {}); q.regle[d.rgc] = t.checked; return; }
    if (d.siv != null) { const v = coches('siv'); q.si = v.length ? { op: 'in', valeur: v } : null; }
  });

  (async function demarrer() {
    try { await chargeEtat(); rendu(); } catch (e) { $.innerHTML = `<div class="af-msg ko">${esc(e.message)}</div>`; hauteur(); }
  })();
})();
