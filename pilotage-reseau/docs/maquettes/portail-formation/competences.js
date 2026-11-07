/* Maquettes « Compétences sous chaque formation » (06/11/2026) — données de production (donnees-competences.js) :
   53 formations, 56 compétences, critères « Réussi si » du Guide des formations, 25 fiches du manuel (SOP).
   data-page : a (tableau déroulant) · b (déroulant + fiche du manuel à droite) · c (chemin d'apprentissage). */
const D = window.D2;
const PH = D.phases;
const P = D.postes.map(p => p[0]);
const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const FO = Object.fromEntries(D.fo.map(f => [f.code, f]));
const court = s => s.replace(/^Formation /, '');
const coupe = s => s.length >= 60 ? s.slice(0, s.lastIndexOf(' ')) + ' …' : s;
const MOM = { ouverture: 'Ouverture du magasin', journee: 'Pendant la journée', 'apres-midi': 'Après-midi', fermeture: 'Fermeture' };
// les fiches du manuel d'une compétence, puis celles reliées à la formation sans passer par une compétence
const fichesDe = c => (D.comp[c] || { std: [] }).std.map(id => Object.assign({ id }, D.std[id])).filter(s => s.code);
const fichesFo = f => [...new Set(f.comp.flatMap(c => D.comp[c].std))];
const fichesDirectes = f => Object.entries(D.std).filter(([id, s]) => s.fo.includes(f.code) && !fichesFo(f).includes(id)).map(([id, s]) => Object.assign({ id }, s));
const nComp = Object.keys(D.comp).length;
const nCompFiche = Object.values(D.comp).filter(c => c.std.length).length;

function rail() {
  const e = [['Formations', 53, 1, true], ['Postes', 12, 2], ['Compétences', 56, 3], ['Manuel opérationnel', 25, 4]];
  return `<nav class="rail"><div class="logo">L’Atelier By</div><div class="k">Portail de formation</div>
    ${e.map(([l, n, i, on]) => `<a class="${on ? 'on' : ''}"><span class="num">${i}</span>${l}<span class="n">${n}</span></a>`).join('')}
    <div class="sep"></div><a>Suivi des équipes</a>
    <div class="sep"></div><div class="g">Réglages</div>
    <a class="petit">Correspondance panel</a><a class="petit">Tablettes</a><a class="petit">Rédaction des SOP</a><a class="petit">Données du panel</a>
    <div class="bas">← Cockpit</div></nav>`;
}
function entete(lettre, titre, sous) {
  const t = [['Postes', 'qui', 12], ['Formations', 'quand on apprend', 53, 1], ['Compétences', 'ce qu’on sait faire', 56], ['Manuel opérationnel', 'comment on le fait (SOP)', 25]];
  const cnt = k => D.fo.filter(f => f.ph.includes(k)).length;
  const s = { pre: '42 démarches', ouv: 'période de lancement · 6 semaines', con: 'recyclages et progression' };
  return `<div class="mq-lab"><span class="l">${lettre}</span><div><div class="t">${titre}</div><div class="s">${sous}</div></div></div>
    <div class="top"><h1>Formations</h1><span class="sp"></span><span class="q">Rechercher une formation, une compétence, une SOP…</span><span class="seg"><span class="on">FR</span><span>NL</span></span></div>
    <div class="chaine">${t.map(([l, x, n, on]) => `<div class="${on ? 'on' : ''}"><span class="x">${n}</span><b>${l}</b><small>${x}</small></div>`).join('')}</div>
    <div class="phases">${['pre', 'ouv', 'con'].map(k => `<div class="phase ${k === 'pre' ? 'on' : ''}"><span class="c">${cnt(k)}</span>
      <div><div class="t">${PH[k]}</div><div class="s">${s[k]}</div></div>${k !== 'con' ? '<span class="fl">›</span>' : ''}</div>`).join('')}</div>`;
}
function qui(att) {
  return `<span class="qui">${P.map(p => `<span class="${att[p] === 'O' ? 'o' : att[p] === 'R' ? 'r' : ''}"></span>`).join('')}</span>`;
}
function barre(jauge) {
  return `<div class="cmp-bar"><span class="l">Pour le poste</span><span class="puce on">Tous</span>
    ${['Production', 'Vente', 'Traiteur', 'Encadrement', 'Franchisé'].map(x => `<span class="puce">${x}</span>`).join('')}
    ${jauge ? `<span class="cmp-cov"><span class="cmp-jauge"><i style="width:${Math.round(100 * nCompFiche / nComp)}%"></i></span><span><b>${nCompFiche}</b> compétences sur ${nComp} ont une fiche au manuel</span><span class="cmp-tout">Tout déplier</span></span>` : ''}</div>`;
}
const familles = (liste, rangee) => D.fams.map(([k, nom]) => {
  const fs = liste.filter(f => f.fam === k);
  return fs.length ? `<div class="grp">${esc(nom)} <i>${fs.length}</i></div>` + fs.map(rangee).join('') : '';
}).join('');
const panelDe = c => D.comp[c].panel ? `<span class="o">●</span> ${esc(D.comp[c].panel)}` : '<span class="n">○ pas de compétence au panel</span>';
const lienFiche = (s, sel) => `<a class="fiche-l${sel ? ' sel' : ''}" title="Ouvrir la fiche du manuel"><b>${esc(s.code)}</b><span>${esc(s.nom)}</span><i>›</i></a>`;

/* ── A : le tableau déroulant ─────────────────────────────────────────── */
function pageA() {
  const liste = D.fo.filter(f => f.ph.includes('pre'));
  const ouverts = ['FO-31', 'FO-12', 'FO-01'];
  const rangee = f => {
    const o = ouverts.includes(f.code);
    const nf = fichesFo(f).length + fichesDirectes(f).length;
    const avec = f.comp.filter(c => D.comp[c].std.length).length;
    let h = `<div class="ligne${o ? ' ouv' : ''}"><span class="chev">${o ? '▾' : '▸'}</span><span class="code">${f.code}</span><span class="nom">${esc(court(f.nom))}</span>
      ${qui(f.att)}<span class="nbx ${f.comp.length ? '' : 'a'}">${f.comp.length ? f.comp.length + ' compét.' : '0 compét.'}</span>
      <span class="nbx ${nf ? '' : 'z'}">${nf ? nf + ' fiche' + (nf > 1 ? 's' : '') : '—'}</span><span class="freq">${esc(f.freq)}</span></div>`;
    if (!o) { return h; }
    const bande = `<div class="sous-bande"><span>Phases</span>${['pre', 'ouv', 'con'].map(k => `<span class="tg ${f.ph.includes(k) ? k : ''}">${k === 'con' ? 'Continue' : PH[k]}</span>`).join('')}
      <span>· ${esc(f.ctrl)}</span><span class="sp"></span><span>${f.comp.length ? `${avec} sur ${f.comp.length} avec une fiche au manuel` : ''}</span><a class="lien">Fiche de la formation ›</a></div>`;
    if (!f.comp.length) {
      return h + `<div class="sous">${bande}<div class="vide-fo"><div class="t">Aucune compétence n’est rattachée à cette formation.</div>
        Elle s’évalue par le bloc ${f.bloc} du Guide des formations — Réussi si : « ${esc(f.crit)} »
        <div class="act"><span class="btn">＋ Rattacher une compétence</span><span class="btn">＋ Lier une fiche du manuel</span></div></div></div>`;
    }
    const lignes = f.comp.map(c => {
      const x = D.comp[c], fs = fichesDe(c);
      const fi = fs.length ? fs.slice(0, 3).map(s => lienFiche(s, false)).join('') + (fs.length > 3 ? `<span class="fiche-plus">+ ${fs.length - 3} autres fiches</span>` : '')
        : '<span class="fiche-vide">Pas encore de fiche · <a>Lier une fiche</a> · <a>Rédiger</a></span>';
      return `<div class="st-l"><span class="st-cd">${esc(c)}</span><span class="st-nm">${esc(x.nom)}</span>
        <span class="st-cr${x.crit ? '' : ' z'}">${x.crit ? esc(x.crit) : 'critère à écrire'}</span><span class="st-fi">${fi}</span><span class="st-pa">${panelDe(c)}</span></div>`;
    }).join('');
    const dir = fichesDirectes(f);
    return h + `<div class="sous">${bande}<div class="st"><div class="st-tete"><span class="st-cd">Compétence</span><span class="st-nm"></span><span class="st-cr">Réussi si…</span>
      <span class="st-fi">Fiche du manuel</span><span class="st-pa">Panel TF Buddy</span></div>${lignes}</div>
      ${dir.length ? `<div class="aussi">Aussi dans le manuel pour cette formation :${dir.map(s => lienFiche(s)).join('')}</div>` : ''}</div>`;
  };
  return `<div class="app">${rail()}<main class="main">${entete('A', 'Tableau déroulant',
    'Un clic sur ▸ ouvre, sous la formation, le tableau de ses compétences : critère « Réussi si », fiche(s) du manuel (un clic ouvre la fiche), compétence du panel. La fiche de droite disparaît : la formation se lit et se règle dans le déroulant. Ouverts ici : FO-31 (sans compétence), FO-12 (une compétence, sept fiches), FO-01 (sept compétences).')}
    <div class="carte">${barre(true)}
      <div class="tete"><span class="chev"></span><span class="code">Code</span><span class="nom">Formation · Pré-ouverture</span><span class="qui" style="font-size:9px">${P.map(p => `<span style="border:0;width:12px;text-align:center">${p.slice(1)}</span>`).join('')}</span>
        <span class="nbx">Compét.</span><span class="nbx">Manuel</span><span class="freq">Fréquence</span></div>
      ${familles(liste, rangee)}</div></main></div>`;
}

/* ── B : déroulant compact + la fiche du manuel à droite ──────────────── */
function pageB() {
  const liste = D.fo.filter(f => f.ph.includes('pre') && ['vente', 'franchise'].includes(f.fam));
  const ap = D.apercu, s = D.std[ap.id];
  const rangee = f => {
    const o = f.code === 'FO-01';
    const nf = fichesFo(f).length + fichesDirectes(f).length;
    let h = `<div class="ligne${o ? ' ouv' : ''}"><span class="chev">${o ? '▾' : '▸'}</span><span class="code">${f.code}</span><span class="nom">${esc(court(f.nom))}</span>
      <span class="nbx ${f.comp.length ? '' : 'a'}">${f.comp.length} compét.</span><span class="nbx ${nf ? '' : 'z'}">${nf ? nf + ' fiche' + (nf > 1 ? 's' : '') : '—'}</span></div>`;
    if (!o) { return h; }
    return h + `<div class="sous b">${f.comp.map(c => {
      const fs = fichesDe(c);
      return `<div class="cb"><span class="cd">${esc(c)}</span><span class="nm">${esc(D.comp[c].nom)}</span><span class="fx">${fs.length
        ? fs.map(x => `<span class="puce-f${x.id === ap.id ? ' sel' : ''}">${esc(x.code)} ›</span>`).join('') : '<span class="puce-f z">pas de fiche</span>'}</span></div>`;
    }).join('')}</div>`;
  };
  const tot = ap.sections.reduce((n, x) => n + x.n, 0);
  return `<div class="app">${rail()}<main class="main">${entete('B', 'Déroulant + la fiche du manuel à droite',
    'Le déroulant reste court : chaque compétence et ses fiches en puces. Un clic sur une puce affiche la fiche du manuel à droite (objectif, sections, points critiques) avec « Ouvrir dans le manuel », sans quitter la liste. Exemple : FO-01 › Encaissement & caisse › SOP-WKL-002.')}
    <div class="lay-b"><div class="carte">${barre(false)}
      <div class="tete"><span class="chev"></span><span class="code">Code</span><span class="nom">Formation · Pré-ouverture</span><span class="nbx">Compét.</span><span class="nbx">Manuel</span></div>
      ${familles(liste, rangee)}</div>
      <div class="carte ap"><div class="fil">FO-01 › C-VENTE.Caisse › <b>${esc(s.code)}</b></div>
        <div class="k">Fiche du manuel · ${esc(s.code)}</div><div class="titre">${esc(s.nom)}</div>
        <div class="meta"><span class="bd con">Publiée · v${esc(s.version)}</span><span class="bd">${esc(MOM[s.moment] || '')}</span><span class="bd">${esc(ap.pour)}</span><span class="bd r">${tot} étapes</span></div>
        <p class="obj">${esc(ap.objectif)}</p>
        <div class="secs">${ap.sections.map(x => `<div class="sec"><span>${esc(x.t)}<small>${esc(x.et.slice(0, 2).map(coupe).join(' · '))}${x.n > 2 && !x.et.slice(0, 2).some(e => e.length >= 60) ? ' …' : ''}</small></span><i>${x.n} étape${x.n > 1 ? 's' : ''}</i></div>`).join('')}</div>
        <div class="crit"><div class="t">Points critiques</div>${ap.critiques.map(x => `<div>${esc(x)}</div>`).join('')}</div>
        <div class="bloc"><h3>Elle valide <i>sur la tablette : lu · quiz · vu faire</i></h3>
          <div class="it"><span class="cd">C-VENTE.Caisse</span><span class="tx">Encaissement & caisse<small>Panel : Être formé au système de caisse</small></span></div>
          <div class="it"><span class="cd">Formations</span><span class="tx">FO-01 Vendeur · FO-14 Encaissement & gestion caisse</span></div></div>
        <div class="acts"><span class="btn p">Ouvrir dans le manuel ›</span><span class="btn">PDF</span><span class="btn">Voir sur la tablette</span></div></div>
    </div></main></div>`;
}

/* ── C : le chemin d'apprentissage ────────────────────────────────────── */
function pageC() {
  const liste = D.fo.filter(f => f.ph.includes('pre') && ['production', 'vente'].includes(f.fam));
  const ouverts = ['FO-01', 'FO-08'];
  const pr = f => {
    const n = f.comp.length || 1;
    const a = f.comp.filter(c => D.comp[c].std.length).length, b = f.comp.filter(c => D.comp[c].panel).length;
    return `<span class="pr">${f.comp.length ? `<span class="r"><em>Manuel</em><span class="cmp-jauge"><i style="width:${100 * a / n}%"></i></span>${a}/${f.comp.length}</span>
      <span class="r"><em>Panel</em><span class="cmp-jauge g"><i style="width:${100 * b / n}%"></i></span>${b}/${f.comp.length}</span>` : '<span class="r" style="color:var(--prize-ink)">aucune compétence</span>'}</span>`;
  };
  const rangee = f => {
    const o = ouverts.includes(f.code);
    let h = `<div class="ligne${o ? ' ouv' : ''}"><span class="chev">${o ? '▾' : '▸'}</span><span class="code">${f.code}</span><span class="nom">${esc(court(f.nom))}</span>
      ${qui(f.att)}${pr(f)}<span class="freq">${esc(f.freq)}</span></div>`;
    if (!o) { return h; }
    return h + `<div class="sous c"><div class="leg-c"><span class="a">fait le lien</span><span class="b">maillon manquant</span></div>${f.comp.map(c => {
      const x = D.comp[c], fs = fichesDe(c);
      const lire = fs.length ? `<div class="et ok"><span class="n">① Lire la fiche du manuel</span><div class="fl">${fs.slice(0, 3).map(s => `<a><b>${esc(s.code)}</b><span>${esc(s.nom)}</span></a>`).join('')}${fs.length > 3 ? `<span class="x">+ ${fs.length - 3} autres fiches</span>` : ''}</div></div>`
        : '<div class="et ko"><span class="n">① Lire la fiche du manuel</span><span class="x">Pas encore de fiche — <a>Lier</a> · <a>Rédiger</a></span></div>';
      const vu = fs.length ? '<div class="et ok"><span class="n">② Quiz et « vu faire »</span><span class="x">Sur la tablette du magasin : le formateur coche « vu faire » à la fin de la fiche.</span></div>'
        : '<div class="et ko"><span class="n">② Quiz et « vu faire »</span><span class="x f">Rien à cocher tant qu’il n’y a pas de fiche.</span></div>';
      const ev = x.panel ? `<div class="et ok"><span class="n">③ Évaluée au panel</span><span class="x">${esc(x.panel)}</span></div>`
        : '<div class="et ko"><span class="n">③ Évaluée au panel</span><span class="x">Pas de compétence au panel — <a>Correspondance</a></span></div>';
      return `<div class="ch"><div class="ch-c"><div class="cd">${esc(c)}</div><div class="nm">${esc(x.nom)}</div><div class="cr">${x.crit ? '<b>Réussi si :</b> ' + esc(x.crit) : ''}</div></div>${lire}${vu}${ev}</div>`;
    }).join('')}</div>`;
  };
  return `<div class="app">${rail()}<main class="main">${entete('C', 'Autre proposition : le chemin d’apprentissage',
    'Le déroulant montre, pour chaque compétence, le chemin complet : ① lire la fiche du manuel (lien direct) → ② quiz et « vu faire » sur la tablette → ③ évaluation au panel. Les maillons manquants se voient en pointillés, avec le geste pour les combler. Dans la ligne, deux jauges : compétences avec fiche / avec panel.')}
    <div class="carte">${barre(true)}
      <div class="tete"><span class="chev"></span><span class="code">Code</span><span class="nom">Formation · Pré-ouverture</span><span class="qui" style="font-size:9px">${P.map(p => `<span style="border:0;width:12px;text-align:center">${p.slice(1)}</span>`).join('')}</span>
        <span class="pr">Couverture</span><span class="freq">Fréquence</span></div>
      ${familles(liste, rangee)}</div></main></div>`;
}

document.body.innerHTML = ({ a: pageA, b: pageB, c: pageC })[document.body.dataset.page]();
