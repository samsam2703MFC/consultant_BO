/* Formation — le module du cockpit (/formation/).
 *
 *  #/standards          les SOP par famille, FR et NL, compétence, progression du réseau
 *  #/sop/<id>           une SOP : FR et NL côte à côte, versions, PDF, équipe du magasin, quiz
 *  #/competences        les compétences du panel : réseau × compétence, magasin × personne, réglages de l'équipe
 *  #/manuel             le Manuel opératoire : sommaire, PDF FR et NL (complet ou par chapitre)
 *  #/redaction          l'état de chaque SOP : langues à relire, compétence, statut, nouvelle SOP
 *  #/editer/<id>        modifier une SOP dans les deux langues et publier une nouvelle version
 *
 * Nos bases sont la source (décision du 05/11/2026). API : ../api/cockpit/formation/* (src/formation.php).
 */
(function () {
  'use strict';
  const API = '../api/cockpit';
  const POSTES = ['vente', 'cuisson', 'production', 'nettoyage'];
  const POSTE_NOM = { vente: 'Vente', cuisson: 'Cuisson', production: 'Production', nettoyage: 'Nettoyage' };
  const TRAD_NOM = { origine: 'origine', relue: 'relue', a_relire: 'à relire' };
  const ST_NOM = { non: 'pas commencée', lu: 'lue · quiz à faire', quiz: 'quiz réussi · à voir faire', vu: 'vue faire', a_revoir: 'à revoir (nouvelle version)' };
  const ST_PILL = { non: '', lu: '', quiz: 'att', vu: 'ok', a_revoir: 'ko' };
  const CP_NOM = { acquise: '✓', en_cours: '', a_acquerir: '', a_revoir: '! à revoir', na: '' };

  const S = {
    route: [], sops: null, totaux: null, dossiers: [], magasins: [], comp: null, fiche: null, archive: null,
    shop: localStorage.getItem('foShopBo') || '', langue: localStorage.getItem('foLangueBo') === 'nl' ? 'nl' : 'fr',
    vueFiche: 'deux', q: '', f: { poste: '', relire: false, valider: false, sansComp: false },
    ed: null, edMeta: null, edErr: null, occupe: false, erreur: null, fil: null, nouv: null
  };
  const $ = s => document.querySelector(s);
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const L = (v, l) => { if (!v) { return ''; } if (typeof v === 'string') { return v; } l = l || S.langue; return v[l] || v[l === 'nl' ? 'fr' : 'nl'] || ''; };
  const dt = iso => iso ? new Date(iso).toLocaleDateString('fr-BE', { day: '2-digit', month: '2-digit' }) : '';
  const dth = iso => iso ? new Date(iso).toLocaleString('fr-BE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';

  async function api(chemin, corps, methode) {
    const r = await fetch(API + chemin, { method: methode || (corps ? 'POST' : 'GET'), credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: corps ? JSON.stringify(corps) : undefined });
    let d = null;
    try { d = await r.json(); } catch (e) { d = null; }
    if (!r.ok) { const e = new Error((d && (d.error || d.erreur)) || ('HTTP ' + r.status)); e.code = r.status; throw e; }
    return d;
  }
  function toast(m) { const el = document.createElement('div'); el.className = 'toast'; el.textContent = m; document.body.appendChild(el); setTimeout(() => el.remove(), 2800); }

  /* ── Petites pièces ── */
  const fam = d => `<span class="fam f${esc(d)}">${esc(d)} · ${esc(L((S.dossiers.find(x => x.id === d) || {}).nom))}</span>`;
  const trad = s => ['fr', 'nl'].map(l => { const t = s.trad[l]; return `<span class="lg ${t === 'a_relire' ? 'ar' : 'on'}" title="${l.toUpperCase()} · ${TRAD_NOM[t]}">${l.toUpperCase()}${t === 'a_relire' ? ' ·' : ''}</span>`; }).join(' ');
  const comp = s => s.competence ? `<span class="comp">${esc(s.competence.nom)}</span>` : '<span class="comp sans">sans compétence du panel</span>';
  const heat = v => v == null ? '<span class="mu">—</span>' : `<span class="heat" style="background:${v < 20 ? '#F3EEE7' : v < 40 ? '#E3EFE2' : v < 60 ? '#C6E0CA' : v < 80 ? '#86BF94' : '#2d7a3e'};color:${v < 20 ? '#999' : v < 60 ? '#24502f' : '#fff'}">${v} %</span>`;
  const pdf = (chemin, l) => `${API}${chemin}${chemin.includes('?') ? '&' : '?'}langue=${l}`;

  function entete() {
    const tabs = [['standards', 'Standards', S.totaux ? S.totaux.sops : ''], ['competences', 'Compétences', ''], ['manuel', 'Manuel opératoire', 'FR · NL'],
      ['redaction', 'Rédaction et versions', S.totaux && S.totaux.tradARelire ? S.totaux.tradARelire : '']];
    const cur = S.route[0] === 'sop' ? 'standards' : S.route[0] === 'editer' ? 'redaction' : (S.route[0] || 'standards');
    return `<div class="fo-hd no-print"><img src="../assets/img/logo.png" alt="L'Atelier"><div class="mod">Formation<small>les standards du réseau · SOP · compétences</small></div>
      <span class="sp"></span><input class="fo-q" type="search" placeholder="Rechercher une SOP, une étape…" value="${esc(S.q)}" data-q>
      <span class="bascule"><button data-langue="fr" class="${S.langue === 'fr' ? 'on' : ''}">FR</button><button data-langue="nl" class="${S.langue === 'nl' ? 'on' : ''}">NL</button></span>
      <select class="fo-sel" data-shop><option value="">Réseau</option>${S.magasins.map(m => `<option value="${esc(m.id)}" ${m.id === S.shop ? 'selected' : ''}>${esc(m.nom)}</option>`).join('')}</select>
      <a class="b" href="tablette.html${S.shop ? '?shop=' + encodeURIComponent(S.shop) : ''}" target="_blank" rel="noopener">Tablette du poste ↗</a></div>
      <div class="tabs no-print">${tabs.map(t => `<a href="#/${t[0]}" class="${t[0] === cur ? 'on' : ''}">${t[1]}${t[2] !== '' ? '<em>' + esc(t[2]) + '</em>' : ''}</a>`).join('')}</div>`;
  }

  /* ═══ Standards ═══ */
  function filtrer(l) {
    const q = S.q.trim().toLowerCase();
    return l.filter(s => (!S.f.poste || s.poste === S.f.poste) && (!S.f.relire || s.trad.fr === 'a_relire' || s.trad.nl === 'a_relire')
      && (!S.f.valider || s.statut === 'a_valider') && (!S.f.sansComp || !s.competence)
      && (!q || [s.titre.fr, s.titre.nl, s.code, s.objectif.fr, s.objectif.nl, s.competence && s.competence.nom].join(' ').toLowerCase().includes(q)));
  }
  function vueStandards() {
    const T = S.totaux, l = filtrer(S.sops);
    const vus = S.sops.reduce((a, s) => a + s.progression.vu, 0), quiz = S.sops.reduce((a, s) => a + s.progression.quiz, 0);
    const kpis = `<div class="kpis">
      <div class="kpi"><div class="k">Standards du réseau</div><div class="v">${T.sops - T.aValider}<small> + ${T.aValider} à valider</small></div><div class="s">${T.etapes} étapes · 4 familles · FR et NL</div></div>
      <div class="kpi"><div class="k">Vus faire · réseau</div><div class="v ok">${vus}</div><div class="s">${quiz} quiz réussis en attente de la responsable</div></div>
      <div class="kpi"><div class="k">Traductions à relire</div><div class="v ${T.tradARelire ? 'att' : 'ok'}">${T.tradARelire}</div><div class="s">la langue d’origine fait foi ; l’autre est traduite à la reprise</div></div>
      <div class="kpi"><div class="k">Compétences du panel</div><div class="v ${T.sansCompetence ? 'att' : 'ok'}">${T.sops - T.sansCompetence} / ${T.sops}</div><div class="s">SOP rattachées à une compétence (Rédaction)</div></div></div>`;
    const btn = (k, v, lib) => `<button data-filtre="${k}" data-v="${v}" class="${String(S.f[k]) === String(v) && v !== '' ? 'on' : (k === 'poste' && v === '' && !S.f.poste ? 'on' : '')}">${lib}</button>`;
    const filtres = `<div class="filtres"><em>Poste</em>${btn('poste', '', 'Tous')}${POSTES.map(p => btn('poste', p, POSTE_NOM[p])).join('')}<span class="sep">|</span>
      ${btn('relire', !S.f.relire, (S.f.relire ? '✓ ' : '') + 'Traduction à relire')}${btn('valider', !S.f.valider, (S.f.valider ? '✓ ' : '') + 'À valider')}${btn('sansComp', !S.f.sansComp, (S.f.sansComp ? '✓ ' : '') + 'Sans compétence')}</div>`;
    const familles = S.dossiers.map(d => {
      const f = l.filter(s => s.dossier === d.id);
      if (!f.length) { return ''; }
      return `<div class="famille"><div class="fh"><span class="fam f${d.id}" style="font-size:11px;padding:3px 8px">${d.id}</span><h2>${esc(L(d.nom))}</h2><span>${f.length} SOP · ${f.reduce((a, s) => a + s.nbEtapes, 0)} étapes</span></div>
        <div class="cartes">${f.map(carte).join('')}</div></div>`;
    }).join('') || '<div class="vide">Aucune SOP ne correspond.</div>';
    return `<div class="titre"><h1>Les standards du réseau</h1><span class="ss">rangés dans nos bases · chaque SOP en FR et en NL · valables pour tous les magasins</span></div>${kpis}${filtres}
      <div class="mini" style="margin:-4px 2px 14px"><span class="lg on">FR</span> <span class="lg on">NL</span> langue d’origine ou relue · <span class="lg ar">NL ·</span> traduite à la reprise, à relire</div>${familles}`;
  }
  function carte(s) {
    const p = s.progression;
    return `<a class="sop ${s.statut === 'a_valider' ? 'vl' : ''}" href="#/sop/${esc(s.id)}">
      <div class="ty"><span class="pill">${esc(s.type)}</span>${trad(s)}<span class="lg">v${esc(s.version)}</span>${s.statut === 'a_valider' ? '<span class="pill att">À valider</span>' : ''}</div>
      <div class="tt">${esc(L(s.titre))}</div><div class="ob">${esc(L(s.objectif))}</div><div>${comp(s)}</div>
      <div class="mini">${p.vu} vu(s) faire · ${p.quiz} quiz · ${p.lu} lu(s)${p.aRevoir ? ` · <span class="ko">${p.aRevoir} à revoir</span>` : ''}</div>
      <div class="pd"><span><b>${s.nbEtapes}</b> étapes</span>${s.nbPhotos ? `<span>📷 ${s.nbPhotos}</span>` : ''}<span>${esc(POSTE_NOM[s.poste] || s.poste)}</span><span class="r">${esc(s.code || '')}</span></div></a>`;
  }

  /* ═══ Fiche SOP ═══ */
  function vueFiche() {
    const s = S.fiche;
    if (!s) { return '<div class="vide">…</div>'; }
    const c = S.archive ? S.archive.contenu : s.contenu;
    const deux = S.vueFiche === 'deux', l1 = S.vueFiche === 'nl' ? 'nl' : 'fr';
    const etapes = (c.sections || []).map(sec => `<div class="sec-t">${esc(L(sec.titre, l1))}${deux && sec.titre.nl ? `<small>${esc(sec.titre.nl)}</small>` : ''}</div>
      ${sec.etapes.map(e => `<div class="bil ${deux ? '' : 'une'} ${e.critique ? 'crit' : ''}"><span class="n">${esc(e.numero)}</span>
        <div class="tx">${e.titre && L(e.titre, l1) ? `<b>${esc(L(e.titre, l1))}</b>` : ''}${esc(L(e.texte, l1))}</div>
        ${deux ? `<div class="tx nl">${e.titre && e.titre.nl ? `<b>${esc(e.titre.nl)}</b>` : ''}${esc(e.texte.nl || '')}</div>` : ''}
        ${e.photo ? `<img src="photos/${esc(e.photo)}" data-zoom="photos/${esc(e.photo)}" alt="">` : '<span></span>'}</div>`).join('')}`).join('');
    const tete = deux ? `<div class="bil" style="border-bottom:.5px solid var(--color-border-secondary)"><span></span><span class="lab" style="margin:0">Français · ${TRAD_NOM[s.trad.fr]}</span><span class="lab" style="margin:0">Nederlands · ${TRAD_NOM[s.trad.nl]}</span><span></span></div>` : '';
    const infos = `<div class="carte"><h3>La SOP</h3><dl class="kv">
      <dt>Code</dt><dd>${esc(s.code || '—')} · ${fam(s.dossier)}</dd><dt>Poste</dt><dd>${esc(POSTE_NOM[s.poste] || s.poste)}${s.moment ? ' · ' + esc(s.moment) : ''}</dd>
      <dt>Langues</dt><dd>${trad(s)} origine : ${esc(s.langueOrigine)}</dd><dt>Version</dt><dd>v${esc(s.version)} · ${esc(dt(s.majLe))}${s.majPar ? ' · ' + esc(s.majPar) : ''}</dd>
      <dt>Étapes</dt><dd>${s.nbEtapes}${s.nbPhotos ? ' · 📷 ' + s.nbPhotos : ''}${c.duree ? ' · ⏱ ' + esc(c.duree) : ''}</dd><dt>Compétence</dt><dd>${comp(s)}</dd>
      ${s.statut === 'a_valider' ? '<dt>Statut</dt><dd><span class="pill att">À valider</span> lisible, sans quiz ni validation</dd>' : ''}</dl>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px"><a class="b s" href="${pdf('/formation/sops/' + s.id + '.pdf', 'fr')}" target="_blank">⬇ PDF FR</a><a class="b s" href="${pdf('/formation/sops/' + s.id + '.pdf', 'nl')}" target="_blank">⬇ PDF NL</a>
        ${S.shop ? `<a class="b s" href="tablette.html?shop=${encodeURIComponent(S.shop)}&poste=${s.poste}" target="_blank">Tablette ↗</a>` : ''}</div></div>`;
    const versions = `<div class="carte"><h3>Versions<small>la précédente reste lisible</small></h3>${s.versions.map(v => `<div class="fil"><div class="d"><span class="lg ${v.version === s.version ? 'on' : ''}">v${esc(v.version)}</span></div>
      <div class="x">${esc(v.note || '—')}<small>${esc(dth(v.publieLe))}${v.publiePar ? ' · ' + esc(v.publiePar) : ''}${v.revalider ? ' · à revalider' : ''}</small></div>
      ${v.version !== s.version ? `<button class="b s" data-archive="${esc(v.version)}">Voir</button>` : ''}</div>`).join('')}</div>`;
    const crit = (c.points_critiques || []).length ? `<div class="carte"><h3>Points critiques</h3><div class="crit-box"><ul>${c.points_critiques.map(x => `<li>${esc(L(x, l1))}${deux && x.nl ? `<br><span class="mu">${esc(x.nl)}</span>` : ''}</li>`).join('')}</ul></div></div>` : '';
    const eq = s.equipe ? `<div class="carte"><h3>${esc(s.magasin.nom)} face à cette SOP<small>${s.equipe.filter(p => p.statut === 'vu').length} / ${s.equipe.length} vus faire</small></h3>
      ${s.equipe.map(p => `<div class="fil"><div class="x"><b>${esc(p.nom)}</b><small>${p.valideur ? 'responsable · ' : ''}lit en ${esc(p.langue.toUpperCase())}${p.vuPar ? ' · vu faire par ' + esc(p.vuPar) + ' le ' + esc(dt(p.vuLe)) : ''}</small></div><span class="pill ${ST_PILL[p.statut]}">${esc(ST_NOM[p.statut])}</span></div>`).join('') || '<div class="mini">Personne de ce poste dans ce magasin.</div>'}</div>`
      : '<div class="carte"><h3>L’équipe</h3><div class="mini">Choisir un magasin en haut pour voir qui a lu, réussi le quiz, été vu faire.</div></div>';
    const quiz = (c.quiz || []).length ? `<div class="carte"><h3>Le quiz<small>${c.quiz.length} questions · la bonne réponse en rouge</small></h3>${c.quiz.map((q, i) => `<div class="question"><b>${i + 1}. ${esc(L(q.question, l1))}</b>${deux && q.question.nl ? `<div class="mini">${esc(q.question.nl)}</div>` : ''}
      <div class="opt">${(q.choix[l1] && q.choix[l1].some(x => x) ? q.choix[l1] : q.choix.fr).map((x, j) => `<span class="${j === q.bonne ? 'rec' : ''}">${esc(x)}</span>`).join('')}</div></div>`).join('')}</div>` : '';
    const rel = ((c.corrections || []).length || (c.remarques || []).length) ? `<div class="carte"><h3>Relevé à la reprise</h3>
      ${(c.corrections || []).map(x => `<div class="fil"><span class="pill ok">corrigé</span><div class="x" style="font-size:12px">${esc(x)}</div></div>`).join('')}
      ${(c.remarques || []).map(x => `<div class="fil"><span class="pill att">à confirmer</span><div class="x" style="font-size:12px">${esc(x)}</div></div>`).join('')}</div>` : '';
    const vb = v => `<button data-vuefiche="${v}" class="${S.vueFiche === v ? 'on' : ''}">${v === 'deux' ? 'FR et NL côte à côte' : v.toUpperCase()}</button>`;
    return `<div class="titre"><a href="#/standards" class="no-print">← Standards</a><h1>${esc(L(s.titre))}</h1>${trad(s)}<span class="lg on">v${esc(S.archive ? S.archive.version : s.version)}</span><span class="ss">${esc(L(s.objectif))}</span></div>
      ${S.archive ? `<div class="note">Version archivée v${esc(S.archive.version)} (${esc(dth(S.archive.publieLe))}) — <button class="b s" data-archive="">Revenir à la version en vigueur</button></div>` : ''}
      <div class="filtres no-print"><em>Afficher</em>${vb('fr')}${vb('nl')}${vb('deux')}<span style="flex:1"></span><a class="b n" href="#/editer/${esc(s.id)}">✎ Modifier</a></div>
      <div class="grille g2"><div class="carte">${tete}${etapes || '<div class="vide">Aucune étape.</div>'}</div><div>${infos}${eq}${crit}${quiz}${versions}${rel}</div></div>`;
  }

  /* ═══ Compétences ═══ */
  function vueCompetences() {
    const C = S.comp;
    if (!C) { return '<div class="vide">…</div>'; }
    const comps = C.competences;
    const panel = C.panel.indispo ? `<div class="note">Compétences du panel : ${esc(C.panel.motif || 'indisponibles')}. ${C.panel.liste.length ? C.panel.liste.length + ' gardées de la dernière lecture.' : ''} Les SOP se rattachent dans Rédaction et versions.</div>`
      : `<div class="mini" style="margin-bottom:10px">${C.panel.liste.length} compétences lues au panel (${esc(C.panel.source)}) · <button class="b s" data-a="relirePanel">Relire le panel</button></div>`;
    const tete = `<tr><th class="l"></th>${comps.map(c => `<th>${esc(c.nom)}<br><span class="mu" style="font-weight:500">${c.sops.length} SOP</span></th>`).join('')}<th>Acquises</th></tr>`;
    const reseau = `<div class="carte"><h3>Le réseau<small>part des personnes concernées qui ont acquis chaque compétence</small></h3>
      ${comps.length ? `<div style="overflow-x:auto"><table class="cm">${tete}${C.magasins.map(m => `<tr><td class="p"><b>${esc(m.nom)}</b><small>${m.equipe ? m.equipe + ' personnes · ' + m.aVoirFaire + ' à voir faire' : 'équipe pas encore lue'}</small></td>
        ${comps.map(c => `<td>${heat(m.parCompetence[c.cle])}</td>`).join('')}<td><b>${m.taux == null ? '—' : m.taux + ' %'}</b></td></tr>`).join('')}</table></div>`
      : '<div class="mini">Aucune SOP n’est encore rattachée à une compétence du panel : Rédaction et versions › colonne Compétence.</div>'}</div>`;
    let mag = '<div class="carte"><h3>Un magasin</h3><div class="mini">Choisir un magasin en haut : chaque personne × chaque compétence, ce que la responsable doit voir faire, les réglages de l’équipe.</div></div>';
    if (C.magasin) {
      const M = C.magasin;
      const cell = x => !x || x.statut === 'na' ? '' : `<span class="cp ${x.statut}">${x.statut === 'acquise' ? '✓' : x.statut === 'a_revoir' ? '! à revoir' : x.vues + ' / ' + x.total}</span>`;
      const matrice = `<div class="carte"><h3>${esc(M.nom)} · qui a quoi<small>une compétence est acquise quand la responsable a vu faire chacune de ses SOP · ${M.taux == null ? '' : M.taux + ' % acquises'}</small></h3>
        ${comps.length ? `<div style="overflow-x:auto"><table class="cm">${tete}${M.equipe.map(p => { const e = Object.values(p.competences).filter(x => x.statut !== 'na');
          return `<tr><td class="p"><b>${esc(p.nom)}</b><small>${p.valideur ? 'responsable · ' : ''}${esc(p.postes.map(x => POSTE_NOM[x] || x).join(', '))} · ${esc(p.langue.toUpperCase())}</small></td>${comps.map(c => `<td>${cell(p.competences[c.cle])}</td>`).join('')}<td><b>${e.length ? e.filter(x => x.statut === 'acquise').length + ' / ' + e.length : '—'}</b></td></tr>`; }).join('')}</table></div>
          <div class="mini" style="margin-top:8px"><span class="cp acquise" style="min-width:24px;height:18px">✓</span> acquise · <span class="cp en_cours" style="height:18px">1 / 3</span> SOP vues faire · <span class="cp a_revoir" style="height:18px">!</span> une SOP a changé depuis · vide : pas son poste</div>` : ''}</div>`;
      const av = `<div class="carte"><h3>À voir faire<small>quiz réussi · la responsable valide sur la tablette, avec son PIN · ${M.aVoirFaire.length}</small></h3>
        ${M.aVoirFaire.map(a => `<div class="fil"><div class="d">${esc(dt(a.quizLe))}</div><div class="x"><b>${esc(a.nom)}</b> · ${esc(a.titre)}</div><span class="pill att">à voir faire</span></div>`).join('') || '<div class="mini">Rien en attente.</div>'}</div>`;
      const reg = `<div class="carte"><h3>L’équipe<small>noms et rôles du panel · postes, langue et responsable réglables ici</small></h3>
        ${M.equipe.map(p => `<div class="fil" style="flex-wrap:wrap"><div class="x" style="min-width:150px"><b>${esc(p.nom)}</b><small>${esc(p.role || '—')}</small></div>
          <span class="puce">${POSTES.map(x => `<label class="${p.postes.includes(x) ? 'on' : ''}"><input type="checkbox" data-poste-pers="${p.id}" value="${x}" ${p.postes.includes(x) ? 'checked' : ''}>${POSTE_NOM[x]}</label>`).join('')}</span>
          <span class="puce"><label class="${p.valideur ? 'on' : ''}"><input type="checkbox" data-valideur="${p.id}" ${p.valideur ? 'checked' : ''}>valide « vu faire »</label></span>
          <select class="fo-sel" data-langue-pers="${p.id}" style="padding:3px 8px"><option value="fr" ${p.langue === 'fr' ? 'selected' : ''}>FR</option><option value="nl" ${p.langue === 'nl' ? 'selected' : ''}>NL</option></select></div>`).join('') || '<div class="mini">Équipe introuvable au panel pour ce magasin.</div>'}</div>`;
      const fil = `<div class="carte"><h3>Le fil<small>14 derniers jours</small></h3>${(S.fil || []).map(f => `<div class="fil"><div class="d">${esc(dt(f.le))}</div><div class="x"><b>${esc(f.nom)}</b> · ${esc(f.titre)}<small>${f.type === 'vu' ? 'vu faire par ' + esc(f.par || '') : f.type === 'quiz' ? 'quiz réussi' : 'lue en entier'}</small></div></div>`).join('') || '<div class="mini">Rien ces 14 derniers jours.</div>'}</div>`;
      mag = matrice + `<div class="grille g2"><div>${av}${reg}</div><div>${fil}</div></div>`;
    }
    const sans = C.sansCompetence.length ? `<div class="note">${C.sansCompetence.length} SOP sans compétence du panel : ${C.sansCompetence.slice(0, 6).map(x => esc(x.titre)).join(', ')}${C.sansCompetence.length > 6 ? '…' : ''} — <a href="#/redaction">les rattacher</a>.</div>` : '';
    return `<div class="titre"><h1>Compétences</h1><span class="ss">les compétences du panel, acquises SOP par SOP · « vu faire » validé par la responsable du magasin</span></div>${panel}${sans}${reseau}${mag}`;
  }

  /* ═══ Manuel opératoire ═══ */
  function vueManuel() {
    const tdm = S.dossiers.map(d => `<div style="margin:12px 0 4px;display:flex;align-items:center;gap:10px">${fam(d.id)}<a class="b s" href="${pdf('/formation/manuel.pdf?dossier=' + d.id, 'fr')}" target="_blank">PDF FR</a><a class="b s" href="${pdf('/formation/manuel.pdf?dossier=' + d.id, 'nl')}" target="_blank">PDF NL</a></div>
      ${S.sops.filter(s => s.dossier === d.id).map(s => `<div class="fil"><span class="mini" style="width:120px;white-space:nowrap">${esc(s.code || '—')}</span><div class="x"><a href="#/sop/${esc(s.id)}"><b>${esc(s.titre.fr)}</b></a><small>${esc(s.titre.nl)}</small></div>${trad(s)}<span class="lg">v${esc(s.version)}</span>${s.statut === 'a_valider' ? '<span class="pill att">à valider</span>' : ''}</div>`).join('')}`).join('');
    return `<div class="titre"><h1>Manuel opératoire</h1><span class="ss">les standards assemblés en un manuel, imprimable en français et en néerlandais · la même source que la tablette</span></div>
      <div class="grille g2"><div class="carte"><h3>Sommaire<small>${S.sops.length} SOP · ${S.totaux.etapes} étapes</small></h3>${tdm}</div>
      <div><div class="carte"><h3>Imprimer</h3><div style="display:flex;gap:8px;flex-wrap:wrap"><a class="b p" href="${pdf('/formation/manuel.pdf', 'fr')}" target="_blank">⬇ Manuel complet FR</a><a class="b p" href="${pdf('/formation/manuel.pdf', 'nl')}" target="_blank">⬇ Volledig handboek NL</a></div>
        <div class="mini" style="margin-top:10px">Généré depuis nos bases au moment du clic : page de garde datée, sommaire, une SOP par page. Une SOP dont la traduction est à relire sort avec la mention « traduction à relire ». Sans moteur PDF sur le serveur, la page s’ouvre prête à imprimer.</div></div>
        <div class="carte"><h3>Dans le Manuel Opératoire du panel</h3><div class="mini" style="font-size:12.5px">Le Manuel Opératoire lit les mêmes SOP, sans copie : <code>GET ${esc(API)}/formation/manuel?langue=fr|nl</code> rend le manuel complet (familles, SOP, sections, étapes, points critiques, quiz) ; chaque SOP a aussi son PDF (<code>/formation/sops/&lt;id&gt;.pdf?langue=</code>).</div></div></div></div>`;
  }

  /* ═══ Rédaction et versions ═══ */
  function vueRedaction() {
    const liste = (S.comp && S.comp.panel.liste) || [];
    const opts = s => `<option value="">— aucune —</option>${liste.map(c => `<option value="${esc(c.id || '')}|${esc(c.nom)}" ${s.competence && s.competence.nom === c.nom ? 'selected' : ''}>${esc(c.nom)}</option>`).join('')}
      ${s.competence && !liste.some(c => c.nom === s.competence.nom) ? `<option value="${esc(s.competence.id || '')}|${esc(s.competence.nom)}" selected>${esc(s.competence.nom)} (hors liste)</option>` : ''}<option value="*">Autre…</option>`;
    const tr = (s, l) => { const t = s.trad[l]; return t === 'origine' ? '<span class="pill">origine</span>' : `<button class="b s ${t === 'relue' ? 'g' : ''}" data-trad="${esc(s.id)}" data-l="${l}" data-v="${t === 'relue' ? 'a_relire' : 'relue'}" title="${t === 'relue' ? 'repasser à relire' : 'marquer relue'}">${t === 'relue' ? '✓ relue' : 'à relire → relue'}</button>`; };
    const rows = filtrer(S.sops).map(s => `<tr class="${s.statut === 'a_valider' ? 'alerte' : ''}"><td>${fam(s.dossier)}</td><td><a href="#/sop/${esc(s.id)}"><b>${esc(s.titre.fr)}</b></a><small>${esc(s.code || '')} · ${esc(s.titre.nl)}</small></td>
      <td>${tr(s, 'fr')}</td><td>${tr(s, 'nl')}</td><td><span class="lg on">v${esc(s.version)}</span><small>${esc(dt(s.majLe))}</small></td><td>${s.nbEtapes}</td>
      <td><select class="fo-sel" style="max-width:220px;padding:4px 8px" data-comp="${esc(s.id)}">${opts(s)}</select></td>
      <td><select class="fo-sel" style="padding:4px 8px" data-statut="${esc(s.id)}"><option value="publiee" ${s.statut === 'publiee' ? 'selected' : ''}>publiée</option><option value="a_valider" ${s.statut === 'a_valider' ? 'selected' : ''}>à valider</option></select></td>
      <td><a class="b s" href="#/editer/${esc(s.id)}">✎ Modifier</a></td></tr>`).join('');
    const panel = !S.comp ? '' : S.comp.panel.indispo ? `<div class="note">Compétences du panel : ${esc(S.comp.panel.motif || '')}. « Autre… » permet d’écrire le nom exact d’une compétence du panel en attendant.</div>`
      : `<div class="mini" style="margin-bottom:8px">${liste.length} compétences lues au panel (${esc(S.comp.panel.source)}).</div>`;
    const N = S.nouv;
    const nouv = N ? `<div class="carte"><h3>Nouvelle SOP</h3><div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px">
      <input class="in" placeholder="Code (ex. SOP-CUI-XYZ-01)" data-nouv="code" value="${esc(N.code)}">
      <select class="in" data-nouv="dossier">${S.dossiers.map(d => `<option value="${d.id}" ${N.dossier === d.id ? 'selected' : ''}>${d.id} · ${esc(d.nom.fr)}</option>`).join('')}</select>
      <select class="in" data-nouv="type">${['SOP', 'Checklist', 'Mémo', 'Fiche'].map(x => `<option ${N.type === x ? 'selected' : ''}>${x}</option>`).join('')}</select>
      <select class="in" data-nouv="poste">${POSTES.map(x => `<option value="${x}" ${N.poste === x ? 'selected' : ''}>${POSTE_NOM[x]}</option>`).join('')}</select>
      <input class="in" style="grid-column:span 2" placeholder="Titre FR" data-nouv="fr" value="${esc(N.fr)}"><input class="in" style="grid-column:span 2" placeholder="Titel NL" data-nouv="nl" value="${esc(N.nl)}"></div>
      <div style="display:flex;gap:8px;margin-top:10px"><button class="b p" data-a="creer">Créer (à valider)</button><button class="b" data-a="annulerNouv">Annuler</button></div></div>` : '';
    return `<div class="titre"><h1>Rédaction et versions</h1><span class="ss">nos bases sont la source · une modification se fait dans les deux langues et se publie en nouvelle version</span><span style="flex:1"></span><button class="b p" data-a="nouv">Nouvelle SOP</button></div>
      ${nouv}${panel}<div class="carte"><table class="src"><thead><tr><th>Famille</th><th>SOP</th><th>FR</th><th>NL</th><th>Version</th><th>Étapes</th><th>Compétence du panel</th><th>Statut</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>
      <div class="mini">Reprise du Drive le 05/11/2026 (44 fichiers → 25 SOP, PDF et DOCX réunis) : le détail des corrections et des points à confirmer est dans <code>data/formation/REPRISE.md</code> et sur chaque fiche (« Relevé à la reprise »).</div>`;
  }

  /* ═══ Éditeur ═══ */
  const get = (o, p) => p.split('.').reduce((a, k) => a == null ? a : a[k], o);
  function set(o, p, v) { const k = p.split('.'); const last = k.pop(); const t = k.reduce((a, x) => a[x], o); t[last] = v; }
  const champ = (p, ph, area) => { const v = get(S.ed, p) || ''; return area ? `<textarea class="in ${v ? '' : 'vide'}" data-ed="${p}" placeholder="${esc(ph)}">${esc(v)}</textarea>` : `<input class="in ${v ? '' : 'vide'}" data-ed="${p}" placeholder="${esc(ph)}" value="${esc(v)}">`; };
  function vueEditer() {
    const s = S.edMeta, c = S.ed;
    if (!c) { return '<div class="vide">…</div>'; }
    const bi = (p, lib, area) => `<div style="display:grid;grid-template-columns:120px 1fr 1fr;gap:8px;align-items:start;margin-bottom:8px"><span class="lab" style="margin:6px 0 0">${lib}</span>${champ(p + '.fr', 'FR', area)}${champ(p + '.nl', 'NL', area)}</div>`;
    const secs = c.sections.map((sec, si) => `<div class="ed-sec"><div class="h">${champ('sections.' + si + '.titre.fr', 'Titre de la section (FR)')}${champ('sections.' + si + '.titre.nl', 'Titel van de sectie (NL)')}
      <span style="display:flex;gap:4px"><button class="b s" data-sec-move="${si}" data-d="-1">↑</button><button class="b s" data-sec-move="${si}" data-d="1">↓</button><button class="b s" data-sec-suppr="${si}">✕</button></span></div>
      ${sec.etapes.map((e, ei) => { const p = 'sections.' + si + '.etapes.' + ei; if (!e.titre) { e.titre = { fr: '', nl: '' }; }
        return `<div class="ed-et"><div class="col"><input class="in" data-ed="${p}.numero" value="${esc(e.numero)}" style="text-align:center"><label class="mini"><input type="checkbox" data-edc="${p}.critique" ${e.critique ? 'checked' : ''}> critique</label></div>
          <div class="col">${champ(p + '.titre.fr', 'Titre (FR, facultatif)')}${champ(p + '.texte.fr', 'Texte (FR)', true)}</div>
          <div class="col">${champ(p + '.titre.nl', 'Titel (NL, facultatief)')}${champ(p + '.texte.nl', 'Tekst (NL)', true)}</div>
          <div class="act"><input class="in" data-ed="${p}.photo" placeholder="photo (fichier)" value="${esc(e.photo || '')}">${e.photo ? `<img src="photos/${esc(e.photo)}" style="width:100%;border-radius:6px" alt="">` : ''}
            <span style="display:flex;gap:4px"><button class="b s" data-et-move="${si}.${ei}" data-d="-1">↑</button><button class="b s" data-et-move="${si}.${ei}" data-d="1">↓</button><button class="b s" data-et-suppr="${si}.${ei}">✕</button></span></div></div>`; }).join('')}
      <button class="b s" data-et-ajout="${si}">+ étape</button></div>`).join('');
    const crit = c.points_critiques.map((x, i) => `<div style="display:grid;grid-template-columns:1fr 1fr auto;gap:8px;margin-bottom:6px">${champ('points_critiques.' + i + '.fr', 'FR')}${champ('points_critiques.' + i + '.nl', 'NL')}<button class="b s" data-crit-suppr="${i}">✕</button></div>`).join('');
    const quiz = c.quiz.map((q, i) => `<div class="question"><div style="display:grid;grid-template-columns:1fr 1fr auto;gap:8px">${champ('quiz.' + i + '.question.fr', 'Question (FR)')}${champ('quiz.' + i + '.question.nl', 'Vraag (NL)')}<button class="b s" data-quiz-suppr="${i}">✕</button></div>
      ${q.choix.fr.map((x, j) => `<div style="display:grid;grid-template-columns:auto 1fr 1fr;gap:8px;margin-top:6px;align-items:center"><label class="mini"><input type="radio" name="bonne${i}" data-bonne="${i}" value="${j}" ${q.bonne === j ? 'checked' : ''}> bonne</label>${champ('quiz.' + i + '.choix.fr.' + j, 'Choix ' + (j + 1) + ' (FR)')}${champ('quiz.' + i + '.choix.nl.' + j, 'Keuze ' + (j + 1) + ' (NL)')}</div>`).join('')}</div>`).join('');
    const prochaine = (() => { const p = s.version.split('.'); if (p.length === 1) { return p[0] + '.1'; } p[p.length - 1] = String(+p[p.length - 1] + 1); return p.join('.'); })();
    const trSel = l => `<select class="fo-sel" data-edtrad="${l}">${['origine', 'relue', 'a_relire'].map(t => `<option value="${t}" ${S.edMeta.tradApres[l] === t ? 'selected' : ''}>${l.toUpperCase()} ${TRAD_NOM[t]}</option>`).join('')}</select>`;
    return `<div class="titre"><a href="#/sop/${esc(s.id)}">← la fiche</a><h1>Modifier · ${esc(s.titre.fr)}</h1><span class="ss">${esc(s.code || '')} · v${esc(s.version)} en vigueur · chaque texte en FR et en NL (en orange : vide)</span></div>
      <div class="carte"><h3>En-tête</h3>${bi('titre', 'Titre')}${bi('objectif', 'Objectif', true)}${bi('pour', 'Pour')}<div style="display:grid;grid-template-columns:120px 1fr;gap:8px"><span class="lab" style="margin:6px 0 0">Durée</span><input class="in" data-ed="duree" value="${esc(c.duree || '')}"></div></div>
      <div class="carte"><h3>Les étapes<small>${c.sections.reduce((a, x) => a + x.etapes.length, 0)} étapes</small></h3>${secs}<button class="b" data-a="secAjout">+ section</button></div>
      <div class="carte"><h3>Points critiques</h3>${crit}<button class="b s" data-a="critAjout">+ point critique</button></div>
      <div class="carte"><h3>Quiz<small>3 questions conseillées · une bonne réponse par question</small></h3>${quiz}<button class="b s" data-a="quizAjout">+ question</button></div>
      ${S.edErr ? `<div class="erreur">${esc(S.edErr)}</div>` : ''}
      <div class="ed-barre"><input class="in" style="max-width:360px" placeholder="Ce qui change (note de version)" data-ednote value="${esc(S.edMeta.note || '')}">
        <label class="mini"><input type="radio" name="reval" data-reval="0" ${!S.edMeta.revalider ? 'checked' : ''}> Précision : ceux qui l’ont validée restent validés</label>
        <label class="mini"><input type="radio" name="reval" data-reval="1" ${S.edMeta.revalider ? 'checked' : ''}> Le geste change : ils repassent « à revoir »</label>
        ${trSel('fr')}${trSel('nl')}<span style="flex:1"></span><a class="b" href="#/sop/${esc(s.id)}">Annuler</a><button class="b p" data-a="publier" ${S.occupe ? 'disabled' : ''}>Publier la v${esc(prochaine)}</button></div>`;
  }

  /* ═══ Navigation, chargement, rendu ═══ */
  async function aller() {
    S.route = (location.hash.replace(/^#\/?/, '') || 'standards').split('/');
    S.erreur = null;
    try {
      if (!S.sops || S.route[0] === 'standards' || S.route[0] === 'redaction' || S.route[0] === 'manuel') {
        const d = await api('/formation/sops'); S.sops = d.sops; S.totaux = d.totaux; S.dossiers = d.dossiers;
      }
      if (!S.magasins.length) { try { S.magasins = (await api('/stores')).filter(m => m.status !== 'Fermé').map(m => ({ id: String(m.id), nom: m.nom })); } catch (e) { S.magasins = []; } }
      if (S.route[0] === 'sop') { S.archive = null; S.fiche = await api('/formation/sops/' + encodeURIComponent(S.route[1]) + (S.shop ? '?shop=' + encodeURIComponent(S.shop) : '')); }
      if (S.route[0] === 'competences' || S.route[0] === 'redaction') {
        S.comp = await api('/formation/competences' + (S.shop && S.route[0] === 'competences' ? '?shop=' + encodeURIComponent(S.shop) : ''));
        S.fil = S.shop && S.route[0] === 'competences' ? (await api('/formation/fil?shop=' + encodeURIComponent(S.shop))).fil : null;
      }
      if (S.route[0] === 'editer') {
        const f = await api('/formation/sops/' + encodeURIComponent(S.route[1]));
        S.ed = JSON.parse(JSON.stringify(f.contenu)); S.edErr = null;
        S.ed.sections.forEach(sec => sec.etapes.forEach(e => { if (!e.titre) { e.titre = { fr: '', nl: '' }; } }));
        S.edMeta = Object.assign({}, f, { note: '', revalider: false, tradApres: { fr: f.trad.fr, nl: f.trad.nl } });
      }
    } catch (e) { S.erreur = e.message; }
    rendre(); window.scrollTo(0, 0);
  }
  function rendre() {
    const vues = { standards: vueStandards, sop: vueFiche, competences: vueCompetences, manuel: vueManuel, redaction: vueRedaction, editer: vueEditer };
    let corps;
    try { corps = S.erreur ? `<div class="erreur">${esc(S.erreur)}</div>` : (S.sops ? (vues[S.route[0]] || vueStandards)() : '<div class="vide">…</div>'); }
    catch (e) { corps = `<div class="erreur">${esc(e.message)}</div>`; console.error(e); }
    $('#fo').innerHTML = entete() + corps + '<div class="pied no-print">Formation · standards du réseau rangés dans nos bases (reprise du Drive du 05/11/2026) · chaque SOP en FR et en NL · lecture sur la tablette du poste, « vu faire » validé par la responsable du magasin · compétences du panel.</div>';
  }
  async function patch(id, corps, msg) {
    try { await api('/formation/sops/' + encodeURIComponent(id), corps, 'PATCH'); toast(msg || 'Enregistré'); await aller(); } catch (e) { toast(e.message); }
  }
  async function personne(id, corps) {
    try { await api('/formation/equipe/' + encodeURIComponent(S.shop) + '/' + id, corps, 'PUT'); toast('Enregistré'); await aller(); } catch (e) { toast(e.message); }
  }
  function vider(o) { const r = JSON.parse(JSON.stringify(o)); (function n(x) { if (x && typeof x === 'object') { Object.keys(x).forEach(k => { if (typeof x[k] === 'string') { x[k] = ''; } else { n(x[k]); } }); } })(r); return r; }

  window.addEventListener('hashchange', aller);
  document.addEventListener('click', async ev => {
    const z = ev.target.closest('[data-zoom]');
    if (z) { const d = document.createElement('div'); d.className = 'zoom'; d.innerHTML = `<img src="${esc(z.dataset.zoom)}">`; d.onclick = () => d.remove(); document.body.appendChild(d); return; }
    const b = ev.target.closest('button');
    if (!b) { return; }
    const d = b.dataset;
    if (d.langue) { S.langue = d.langue; localStorage.setItem('foLangueBo', S.langue); return rendre(); }
    if (d.filtre) { S.f[d.filtre] = d.filtre === 'poste' ? d.v : d.v === 'true'; return rendre(); }
    if (d.vuefiche) { S.vueFiche = d.vuefiche; return rendre(); }
    if (d.archive !== undefined) {
      if (!d.archive) { S.archive = null; return rendre(); }
      try { S.archive = await api('/formation/sops/' + encodeURIComponent(S.fiche.id) + '/versions/' + encodeURIComponent(d.archive)); } catch (e) { toast(e.message); }
      rendre(); return window.scrollTo(0, 0);
    }
    if (d.trad) { return patch(d.trad, { trad: { [d.l]: d.v } }, d.l.toUpperCase() + (d.v === 'relue' ? ' relue' : ' à relire')); }
    const E = S.ed;
    if (d.secMove !== undefined) { const i = +d.secMove, j = i + (+d.d); if (j >= 0 && j < E.sections.length) { [E.sections[i], E.sections[j]] = [E.sections[j], E.sections[i]]; } return rendre(); }
    if (d.secSuppr !== undefined) { if (confirm('Supprimer cette section et ses étapes ?')) { E.sections.splice(+d.secSuppr, 1); } return rendre(); }
    if (d.etAjout !== undefined) { const sec = E.sections[+d.etAjout]; sec.etapes.push({ numero: String(sec.etapes.length + 1), titre: { fr: '', nl: '' }, texte: { fr: '', nl: '' }, critique: false, photo: null }); return rendre(); }
    if (d.etMove !== undefined) { const [si, ei] = d.etMove.split('.').map(Number); const l = E.sections[si].etapes, j = ei + (+d.d); if (j >= 0 && j < l.length) { [l[ei], l[j]] = [l[j], l[ei]]; } return rendre(); }
    if (d.etSuppr !== undefined) { const [si, ei] = d.etSuppr.split('.').map(Number); E.sections[si].etapes.splice(ei, 1); return rendre(); }
    if (d.critSuppr !== undefined) { E.points_critiques.splice(+d.critSuppr, 1); return rendre(); }
    if (d.quizSuppr !== undefined) { E.quiz.splice(+d.quizSuppr, 1); return rendre(); }
    switch (d.a) {
      case 'secAjout': E.sections.push({ titre: { fr: '', nl: '' }, etapes: [] }); return rendre();
      case 'critAjout': E.points_critiques.push({ fr: '', nl: '' }); return rendre();
      case 'quizAjout': E.quiz.push(E.quiz[0] ? Object.assign(vider(E.quiz[0]), { bonne: 0 }) : { question: { fr: '', nl: '' }, choix: { fr: ['', '', ''], nl: ['', '', ''] }, bonne: 0 }); return rendre();
      case 'publier': {
        S.occupe = true; S.edErr = null; rendre();
        try {
          const r = await api('/formation/sops/' + encodeURIComponent(S.edMeta.id), { contenu: E, note: S.edMeta.note, revalider: S.edMeta.revalider, trad: S.edMeta.tradApres }, 'PUT');
          toast('Version ' + r.version + ' publiée' + (r.aRevoir ? ' · ' + r.aRevoir + ' personne(s) à revoir' : ''));
          S.occupe = false; location.hash = '#/sop/' + S.edMeta.id; return;
        } catch (e) { S.edErr = e.message; }
        S.occupe = false; return rendre();
      }
      case 'relirePanel': try { S.comp = await api('/formation/competences?rafraichir=1' + (S.shop ? '&shop=' + encodeURIComponent(S.shop) : '')); } catch (e) { toast(e.message); } return rendre();
      case 'nouv': S.nouv = { code: '', dossier: '01', type: 'SOP', poste: 'vente', fr: '', nl: '' }; return rendre();
      case 'annulerNouv': S.nouv = null; return rendre();
      case 'creer': {
        const N = S.nouv;
        try { const r = await api('/formation/sops', { code: N.code, dossier: N.dossier, type: N.type, poste: N.poste, titre: { fr: N.fr, nl: N.nl } }); S.nouv = null; location.hash = '#/editer/' + r.id; }
        catch (e) { toast(e.message); }
        return;
      }
    }
  });
  document.addEventListener('input', ev => {
    const el = ev.target, d = el.dataset;
    if (d.q !== undefined) { S.q = el.value; if (S.route[0] === 'standards' || S.route[0] === 'redaction') { const pos = el.selectionStart; rendre(); const n = $('[data-q]'); n.focus(); n.setSelectionRange(pos, pos); } return; }
    if (d.ed) { set(S.ed, d.ed, d.ed.endsWith('.photo') && el.value.trim() === '' ? null : el.value); el.classList.toggle('vide', !el.value); return; }
    if (d.ednote !== undefined) { S.edMeta.note = el.value; return; }
    if (d.nouv) { S.nouv[d.nouv] = el.value; }
  });
  document.addEventListener('change', ev => {
    const el = ev.target, d = el.dataset;
    if (d.shop !== undefined) { S.shop = el.value; localStorage.setItem('foShopBo', S.shop); return aller(); }
    if (d.edc) { set(S.ed, d.edc, el.checked); return; }
    if (d.bonne !== undefined) { S.ed.quiz[+d.bonne].bonne = +el.value; return; }
    if (d.reval !== undefined) { S.edMeta.revalider = d.reval === '1'; return; }
    if (d.edtrad) { S.edMeta.tradApres[d.edtrad] = el.value; return; }
    if (d.nouv) { S.nouv[d.nouv] = el.value; return; }
    if (d.statut) { return patch(d.statut, { statut: el.value }, el.value === 'publiee' ? 'Publiée' : 'À valider'); }
    if (d.comp) {
      let v = el.value;
      if (v === '*') { const n = prompt('Nom exact de la compétence du panel :'); if (!n) { return rendre(); } v = '|' + n.trim(); }
      if (!v) { return patch(d.comp, { competence: null }, 'Compétence retirée'); }
      const i = v.indexOf('|');
      return patch(d.comp, { competence: { id: v.slice(0, i) || null, nom: v.slice(i + 1) } }, 'Compétence rattachée');
    }
    if (d.postePers) { const p = S.comp.magasin.equipe.find(x => String(x.id) === d.postePers); const set2 = new Set(p.postes); el.checked ? set2.add(el.value) : set2.delete(el.value); return personne(d.postePers, { postes: [...set2] }); }
    if (d.valideur) { return personne(d.valideur, { valideur: el.checked }); }
    if (d.languePers) { return personne(d.languePers, { langue: el.value }); }
  });
  aller();
})();
