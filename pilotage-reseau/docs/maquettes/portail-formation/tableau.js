/* Maquette « Formations : un tableau aligné + rédacteur de fiche à droite » (06/11/2026).
   Données de production (donnees-competences.js). Les compétences s'ouvrent SOUS leur formation, DANS LES MÊMES
   COLONNES (un seul <table>). Une compétence sans fiche du manuel : « ＋ Rédiger » ouvre le rédacteur à droite.
   data-etat : tableau (le tableau seul, toutes les colonnes) · redacteur (rédacteur ouvert, colonnes réduites).
   Le texte des étapes du rédacteur est un EXEMPLE de saisie. */
const D = window.D2;
const PH = D.phases;
const P = D.postes.map(p => p[0]);
const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const court = s => s.replace(/^Formation /, '');
const fichesDe = c => (D.comp[c] || { std: [] }).std.map(id => Object.assign({ id }, D.std[id])).filter(s => s.code);
const fichesFo = f => [...new Set(f.comp.flatMap(c => D.comp[c].std))];
const nComp = Object.keys(D.comp).length;
const nCompFiche = Object.values(D.comp).filter(c => c.std.length).length;
const ETAT = document.body.dataset.etat;
const RED = ETAT === 'redacteur';
const SEL = 'C-VENTE.Fidélité';

function rail() {
  const e = [['Formations', 53, 1, true], ['Postes', 12, 2], ['Compétences', 56, 3], ['Manuel opérationnel', 25, 4]];
  return `<nav class="rail"><div class="logo">L’Atelier By</div><div class="k">Portail de formation</div>
    ${e.map(([l, n, i, on]) => `<a class="${on ? 'on' : ''}"><span class="num">${i}</span>${l}<span class="n">${n}</span></a>`).join('')}
    <div class="sep"></div><div class="g">Réglages</div>
    <a class="petit">Correspondance panel</a><a class="petit">Tablettes</a><a class="petit">Rédaction des SOP</a><a class="petit">Données du panel</a>
    <div class="bas">← Cockpit</div></nav>`;
}
function entete(titre, sous) {
  const t = [['Postes', 'qui', 12], ['Formations', 'quand on apprend', 53, 1], ['Compétences', 'ce qu’on sait faire', 56], ['Manuel opérationnel', 'comment on le fait (SOP)', 25]];
  const cnt = k => D.fo.filter(f => f.ph.includes(k)).length;
  const s = { pre: '42 démarches', ouv: 'période de lancement · 6 semaines', con: 'recyclages et progression' };
  return `<div class="mq-lab"><div><div class="t">${titre}</div><div class="s">${sous}</div></div></div>
    <div class="top"><h1>Formations</h1><span class="sp"></span><span class="q">Rechercher une formation, une compétence, une SOP…</span><span class="seg"><span class="on">FR</span><span>NL</span></span></div>
    <div class="chaine">${t.map(([l, x, n, on]) => `<div class="${on ? 'on' : ''}"><span class="x">${n}</span><b>${l}</b><small>${x}</small></div>`).join('')}</div>
    <div class="phases">${['pre', 'ouv', 'con'].map(k => `<div class="phase ${k === 'pre' ? 'on' : ''}"><span class="c">${cnt(k)}</span>
      <div><div class="t">${PH[k]}</div><div class="s">${s[k]}</div></div>${k !== 'con' ? '<span class="fls">›</span>' : ''}</div>`).join('')}</div>`;
}
const qui = att => `<span class="qui2">${P.map(p => `<span class="${att[p] === 'O' ? 'o' : att[p] === 'R' ? 'r' : ''}"></span>`).join('')}</span>`;
const lien = s => `<a title="Ouvrir la fiche du manuel"><b>${esc(s.code)}</b><span>${esc(s.nom)}</span><i>›</i></a>`;
const rediger = on => `<span class="red${on ? ' on' : ''}">＋ Rédiger la fiche</span>${on ? '' : '<span class="lier">ou lier</span>'}`;

// colonnes : [clé, titre, largeur] — le rédacteur ouvert garde code · nom · réussi si · fiche
const COLS = RED
  ? [['chv', '', 26], ['cd', 'Code', 142], ['nm', 'Formation · compétence', 210], ['cr', 'Réussi si…', null], ['fi', 'Fiche du manuel', 230]]
  : [['chv', '', 26], ['cd', 'Code', 142], ['nm', 'Formation · compétence', 250], ['qui', 'Postes 1 → 12', 172], ['cr', 'Réussi si…', null], ['fi', 'Fiche du manuel', 280], ['pa', 'Panel TF Buddy', 190], ['fr', 'Fréquence', 92]];
const has = k => COLS.some(c => c[0] === k);

function ligneFo(f, ouvert) {
  const nf = fichesFo(f).length;
  const avec = f.comp.filter(c => D.comp[c].std.length).length;
  const pan = f.comp.filter(c => D.comp[c].panel).length;
  const cel = {
    chv: ouvert ? '▾' : '▸', cd: f.code,
    nm: `<span class="nmx">${esc(court(f.nom))}<small>${f.comp.length ? f.comp.length + ' compétence' + (f.comp.length > 1 ? 's' : '') : '<span style="color:var(--prize-ink)">aucune compétence</span>'}</small></span>`,
    qui: qui(f.att),
    cr: f.crit ? `<span class="crx">Bloc ${f.bloc} du Guide : ${esc(f.crit)}</span>` : '<span class="crx z">—</span>',
    fi: f.comp.length ? `<span class="sum"><span class="jg"><i style="width:${100 * avec / f.comp.length}%"></i></span>${avec}/${f.comp.length} avec fiche${nf ? ' · ' + nf + ' fiche' + (nf > 1 ? 's' : '') : ''}</span>` : '<span class="nb2 z">—</span>',
    pa: f.comp.length ? `<span class="sum"><span class="jg g"><i style="width:${100 * pan / f.comp.length}%"></i></span>${pan}/${f.comp.length} au panel</span>` : '<span class="nb2 z">—</span>',
    fr: `<span class="fr2">${esc(f.freq)}</span>`,
  };
  return `<tr class="lfo${ouvert ? ' ouv' : ''}">${COLS.map(([k]) => `<td class="c-${k}">${cel[k]}</td>`).join('')}</tr>`;
}
function ligneComp(c, der) {
  const x = D.comp[c], fs = fichesDe(c), sel = RED && c === SEL;
  const fi = sel ? '<span class="brou">✎ Brouillon en cours · rédacteur ouvert</span>'
    : fs.length ? `<div class="fls">${fs.slice(0, RED ? 2 : 3).map(lien).join('')}${fs.length > (RED ? 2 : 3) ? `<span class="plus">+ ${fs.length - (RED ? 2 : 3)} autres fiches</span>` : ''}</div>` : rediger(false);
  const cel = {
    chv: '', cd: esc(c), nm: `<span class="nmx">${esc(x.nom)}</span>`, qui: '',
    cr: x.crit ? `<span class="crx">${esc(x.crit)}</span>` : '<span class="crx z">critère à écrire</span>',
    fi, pa: `<span class="pa">${x.panel ? `<span class="o">●</span> ${esc(x.panel)}` : '<span class="n">○ pas au panel</span>'}</span>`, fr: '',
  };
  return `<tr class="cp${der ? ' der' : ''}${sel ? ' sel' : ''}">${COLS.map(([k]) => `<td class="c-${k}">${cel[k]}</td>`).join('')}</tr>`;
}
function ligneVide(f) {
  const cel = {
    chv: '', cd: '<span style="color:var(--faint)">—</span>', nm: '<span class="nmx" style="color:var(--prize-ink)">Aucune compétence rattachée</span>', qui: '',
    cr: `<span class="crx">${f.crit ? 'La formation s’évalue seule : ' + esc(f.crit) : '—'}</span>`,
    fi: rediger(false), pa: '<span class="pa"><span class="red">＋ Rattacher une compétence</span></span>', fr: '',
  };
  if (!has('pa')) { cel.fi = '<span class="red">＋ Rattacher une compétence</span>'; }
  return `<tr class="cp der">${COLS.map(([k]) => `<td class="c-${k}">${cel[k]}</td>`).join('')}</tr>`;
}
function tableau(liste, ouverts) {
  const tete = `<colgroup>${COLS.map(c => `<col${c[2] ? ` style="width:${c[2]}px"` : ''}>`).join('')}</colgroup>
    <thead><tr>${COLS.map(c => `<th class="c-${c[0]}">${c[1]}</th>`).join('')}</tr></thead>`;
  const corps = D.fams.map(([k, nom]) => {
    const fs = liste.filter(f => f.fam === k);
    if (!fs.length) { return ''; }
    return `<tr class="fam"><td colspan="${COLS.length}">${esc(nom)}<i>${fs.length}</i></td></tr>` + fs.map(f => {
      const o = ouverts.includes(f.code);
      if (!o) { return ligneFo(f, false); }
      return ligneFo(f, true) + (f.comp.length ? f.comp.map((c, i) => ligneComp(c, i === f.comp.length - 1)).join('') : ligneVide(f));
    }).join('');
  }).join('');
  return `<table class="tf">${tete}<tbody>${corps}</tbody></table>`;
}
function barre(poste) {
  return `<div class="bar"><span class="l">Pour le poste</span>${['Tous', 'Production', 'Vente', 'Traiteur', 'Encadrement', 'Franchisé'].map(x => `<span class="puce${x === poste ? ' on' : ''}">${x}</span>`).join('')}
    <span class="cov"><span class="jg"><i style="width:${Math.round(100 * nCompFiche / nComp)}%"></i></span><span><b>${nCompFiche}</b> compétences sur ${nComp} ont une fiche</span><span class="puce">Sans fiche · ${nComp - nCompFiche}</span><span class="puce">Tout déplier</span></span></div>`;
}

/* ── Le rédacteur (panneau de droite) ─────────────────────────────────── */
function redacteur() {
  const x = D.comp[SEL];
  const ph = n => `/public/formation/sop/photos/ouv-mag-${n}.jpg`;
  const et = (n, titre, texte, media, crit, outils) => `<div class="et${crit ? ' crit' : ''}"><div class="et-h"><span class="n">${n}</span><span class="ti">${titre}</span><span class="ac">⋮⋮ ↑ ↓ ✕</span></div>
    ${outils ? '<div class="tb"><span class="on">G</span><span><i>I</i></span><span>• Liste</span><span>Lien</span><span class="sp"></span><span>FR · <i style="color:var(--prize-ink)">NL à traduire</i></span></div>' : ''}
    <div class="et-t${texte ? '' : ' vide'}">${texte || 'Écrire le texte de l’étape…'}</div>
    ${media ? `<div class="med">${media}</div>` : ''}
    <div class="et-f"><span class="ob">＋ Photo</span><span class="ob">＋ Vidéo</span><span class="ob${crit ? ' on' : ''}">! Point critique</span><span class="sp"></span></div></div>`;
  const photo = (n, lg) => `<div class="ph"><img src="${ph(n)}" alt=""><span class="xx">✕</span><div class="lg">${lg}</div></div>`;
  const video = `<div class="vid"><div class="im"><img src="${ph(5)}" alt=""><div class="pl"><span>▶</span></div><span class="du">1:20</span></div><div class="u">Vidéo · youtu.be/… · lue sur la tablette</div></div>`;
  return `<aside class="rd">
    <div class="rd-h"><div class="top2"><span class="k">Nouvelle fiche du manuel · brouillon</span><span class="seg2"><span class="on">FR</span><span>NL <i>à traduire</i></span></span><span class="x">✕</span></div>
      <div class="rd-titre">Inscrire un client à la fidélité et l’identifier à la caisse</div>
      <div class="rd-lien">Valide <span class="ch"><b>${esc(SEL)}</b>${esc(x.nom)}</span><span class="ch"><b>FO-01</b>Vendeur</span><span class="ch plus">＋ formation</span></div>
      <div class="rd-meta"><span class="sel2"><small>Type</small>SOP ▾</span><span class="sel2"><small>Postes</small>P3 · P4 ▾</span><span class="sel2"><small>Moment</small>Pendant la journée ▾</span><span class="sel2"><small>Code proposé</small>SOP-WKL-005</span></div></div>
    <div class="rd-b">
      <h4>Objectif <i>repris du « Réussi si », modifiable</i></h4>
      <div class="zt">${esc(x.crit)}</div>
      <div class="sec-t"><span class="zt">Marche à suivre</span><span class="ac" style="color:var(--faint);font-size:12px">⋮⋮ ✕</span></div>
      ${et(1, 'Proposer la fidélité', 'À l’encaissement, demander au client s’il a l’application L’Atelier By ; sinon, proposer de l’inscrire (moins d’une minute).', '', false, true)}
      ${et(2, 'Ouvrir la fiche client à la caisse', 'Toucher « Client » sur l’écran de vente, puis « Nouveau client » ou rechercher son nom.', photo(3, 'Écran de vente · « Client »') + photo(4, 'Recherche du client'), false, false)}
      ${et(3, 'Identifier le client (QR ou NFC)', 'Scanner le QR code de l’application ou approcher la carte du lecteur. Le nom du client s’affiche en haut de l’écran.', video, false, false)}
      ${et(4, 'Vérifier le nom avant de valider', 'Ne jamais valider sans avoir relu le nom affiché : les points iraient à un autre client.', '', true, false)}
      ${et(5, 'Titre de l’étape…', '', '<div class="dz">Glisser ici une photo (JPG, PNG) ou une vidéo (MP4)<br>ou coller un lien YouTube / Vimeo</div>', false, false)}
      <div class="addb"><span class="ob">＋ Étape</span><span class="ob">＋ Section</span><span class="ob">＋ Document PDF</span></div>
      <h4 style="margin-top:14px">Points critiques <i>en rouge sur la tablette</i></h4>
      <div class="pcr"><div>Vérifier le nom affiché avant de valider (étape 4).</div></div>
      <h4 style="margin-top:14px">Quiz de la tablette <i>2 questions · « lu · quiz · vu faire »</i></h4>
      <div class="qz"><div class="q">Que vérifier avant de valider l’identification ?</div>
        <div class="c"><span class="rb on"></span>Le nom du client affiché à l’écran</div><div class="c"><span class="rb"></span>Le montant du ticket</div><div class="c"><span class="rb"></span>L’heure</div>
        <div class="c" style="margin-top:6px;color:var(--muted)">＋ Question</div></div>
      <h4 style="margin-top:14px">Vu faire</h4>
      <div class="vfa"><span class="ck">✓</span>À la fin de la fiche, le formateur coche « vu faire » sur la tablette : la compétence ${esc(SEL)} est validée pour la personne (et au panel : ${esc(x.panel || '—')}).</div>
    </div>
    <div class="rd-f"><span class="st">Brouillon enregistré à 14:32</span><span class="btn">Aperçu tablette</span><span class="btn p">Envoyer à valider</span></div></aside>`;
}

function page() {
  if (!RED) {
    const liste = D.fo.filter(f => f.ph.includes('pre'));
    return `<div class="app">${rail()}<main class="main">${entete('Un seul tableau, les compétences dans les colonnes des formations',
      'Un clic sur ▸ déplie la formation : chaque compétence prend une ligne, dans les mêmes colonnes (code · nom · réussi si · fiche du manuel · panel). Sur la ligne de la formation : combien de ses compétences ont une fiche et une compétence au panel. Sans fiche : « ＋ Rédiger la fiche ».')}
      <div class="carte">${barre('Tous')}${tableau(liste, ['FO-31', 'FO-12', 'FO-01'])}</div></main></div>`;
  }
  const liste = D.fo.filter(f => f.ph.includes('pre') && f.fam === 'vente');
  return `<div class="app">${rail()}<main class="main">${entete('« ＋ Rédiger la fiche » : le rédacteur s’ouvre à droite',
    'Exemple : FO-01 › Fidélisation & PWA loyalty, qui n’a pas encore de fiche. Le rédacteur part de la compétence (objectif = son « Réussi si »), puis les étapes : texte, photos glissées, vidéos (fichier ou lien YouTube / Vimeo), point critique, quiz de la tablette. Le texte des étapes est un exemple de saisie.')}
    <div class="lay-r"><div class="carte">${barre('Vente')}${tableau(liste, ['FO-01'])}</div>${redacteur()}</div></main></div>`;
}
document.body.innerHTML = page();
