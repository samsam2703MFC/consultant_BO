/* Clients › Particuliers — la fiche plus serrée et la fréquence des passages (maquettes, 28/11/2026).
 * Demande, depuis la fiche en ligne : « compresser légèrement le drawer (datas) et rajouter une indication sur la
 * fréquence (quand le client était là), visuellement ; fais-moi 3-4 maquettes en screenshot et on décide ».
 * Un client FICTIF (Sophie Renard, Val-Fleuri, @exemple.be), profil Hebdo, 13 passages sur 90 jours. La page montre la
 * fiche d'aujourd'hui, puis quatre façons de dire quand il vient ; ?v=a|b|c|d|avant n'en montre qu'une.
 * Les dates : aujourd'hui = samedi 28/11/2026, la mémoire = 90 jours (du lundi 31/08). */
(function () {
  'use strict';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const nf = (n, d) => (Number(n) || 0).toLocaleString('fr-BE', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).replace(/\s/g, ' ');
  const AUJ = '2026-11-28', MEMOIRE = 90, FENETRE = 30;
  const jour = (iso, n) => { const d = new Date(iso + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
  const ecart = (a, b) => Math.round((Date.parse(b + 'T12:00:00Z') - Date.parse(a + 'T12:00:00Z')) / 86400000);
  const DEBUT = jour(AUJ, -(MEMOIRE - 1));   // lundi 31/08
  const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  const MOISL = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  const JOURS = ['L', 'M', 'M', 'J', 'V', 'S', 'D'], JOURSL = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
  const dow = iso => (new Date(iso + 'T12:00:00Z').getUTCDay() + 6) % 7;

  // Les profils (les réglages par défaut du réseau) et le client.
  const PROFILS = [
    { id: 1, nom: 'Fanbase', couleur: '#8D1D2C', seuil: 12, condition: '3 fois par semaine ou plus' },
    { id: 2, nom: 'Fidèle', couleur: '#B03048', seuil: 8, condition: '2 fois par semaine' },
    { id: 3, nom: 'Hebdo', couleur: '#B0792A', seuil: 4, condition: '1 fois par semaine' },
    { id: 4, nom: 'Régulier', couleur: '#3f7a52', seuil: 2, condition: '2 à 3 fois par mois' },
    { id: 5, nom: 'Occasionnel', couleur: '#1B6FA8', seuil: 1, condition: '1 fois par mois' },
    { id: 6, nom: 'Découverte', couleur: '#9a948b', seuil: 0, condition: 'moins d’1 fois par mois' },
  ];
  const profilDe = r => PROFILS.find(p => p.seuil > 0 && r >= p.seuil) || PROFILS[5];
  // Ses passages : surtout le samedi, trois semaines d'absence en octobre, quelques mercredis, jeudi dernier.
  const PASSAGES = ['2026-09-05', '2026-09-09', '2026-09-12', '2026-09-19', '2026-09-26', '2026-10-03', '2026-10-28', '2026-10-31',
    '2026-11-07', '2026-11-14', '2026-11-18', '2026-11-21', '2026-11-26'];
  const VU = new Set(PASSAGES);
  const dansFenetre = PASSAGES.filter(j => j > jour(AUJ, -FENETRE)).length;
  const rythme = dansFenetre * 30 / FENETRE, P = profilDe(rythme);
  const depuis = ecart(PASSAGES[PASSAGES.length - 1], AUJ);
  let creux = { n: 0 };
  PASSAGES.forEach((j, i) => { if (i) { const n = ecart(PASSAGES[i - 1], j); if (n > creux.n) { creux = { n, du: PASSAGES[i - 1], au: j }; } } });
  const C = { tk: 14, eur: 204.0, cmd: 4,
    radar: { frequence: 50, recence: 98, anciennete: 92, panier: 24, diversite: 67, comptoir: 71 },
    familles: { pain: 38, vienno: 30, antigaspi: 4, tartes: 10, gouter: 8, sale: 10 } };
  const M = { radar: { frequence: 14, recence: 54, anciennete: 35, panier: 23, diversite: 47, comptoir: 79 }, familles: { pain: 25, vienno: 23, antigaspi: 7, tartes: 20, gouter: 10, sale: 15 } };
  const AXES = { frequence: 'Fréquence', recence: 'Récence', anciennete: 'Ancienneté', panier: 'Panier', diversite: 'Diversité', comptoir: 'Comptoir' };
  const fD = s => s.slice(8, 10) + '/' + s.slice(5, 7);
  const fJ = s => +s.slice(8, 10) + ' ' + MOISL[+s.slice(5, 7) - 1];
  const fidDepuis = j => j === 0 ? 'passé aujourd’hui' : j === 1 ? 'passé hier' : 'passé il y a ' + j + ' j';

  /* Le radar de dashboard.js (fdRadar, fdLegende), recopié pour la maquette. */
  function radar(series, axes, taille, maxv) {
    const K = Object.keys(axes), n = K.length, cx = taille / 2, R = taille / 2 - 72, mx = maxv || 100;
    const ang = i => -Math.PI / 2 + 2 * Math.PI * i / n;
    const pt = (i, f) => (cx + R * f * Math.cos(ang(i))).toFixed(1) + ',' + (cx + R * f * Math.sin(ang(i))).toFixed(1);
    let h = '';
    [0.25, 0.5, 0.75, 1].forEach(f => { h += `<polygon points="${K.map((k, i) => pt(i, f)).join(' ')}" fill="none" stroke="var(--color-border-tertiary)"/>`; });
    K.forEach((k, i) => {
      const c = Math.cos(ang(i)), sn = Math.sin(ang(i));
      h += `<line x1="${cx}" y1="${cx}" x2="${pt(i, 1).split(',')[0]}" y2="${pt(i, 1).split(',')[1]}" stroke="var(--color-border-tertiary)"/>`;
      h += `<text x="${(cx + (R + 12) * c).toFixed(1)}" y="${(cx + (R + 12) * sn + (sn < -0.5 ? -4 : sn > 0.5 ? 12 : 4)).toFixed(1)}" font-size="11" text-anchor="${Math.abs(c) < 0.3 ? 'middle' : (c > 0 ? 'start' : 'end')}" fill="var(--color-text-muted)">${esc(axes[k])}</text>`;
    });
    series.forEach(x => { h += `<polygon points="${K.map((k, i) => pt(i, Math.min(mx, Math.max(2, x.v[k] || 0)) / mx)).join(' ')}" fill="${x.c}" fill-opacity="${x.fond}" stroke="${x.c}" stroke-width="${x.w}" stroke-linejoin="round"/>`; });
    return `<svg viewBox="0 0 ${taille} ${taille}" class="fd-radar" role="img">${h}</svg>`;
  }
  const legende = () => `<div class="fd-leg"><span><i style="background:${P.couleur}"></i>${esc(P.nom)}</span><span><i style="background:var(--color-text-muted)"></i>Moyenne des 612 clients scannés</span></div>`;
  const seg = on => `<span class="seg"><button type="button" class="${on === 'r' ? 'is-on' : ''}">Récurrence</button><button type="button" class="${on === 'a' ? 'is-on' : ''}">Type d’achat</button></span>`;
  const ligneF = (k, v) => `<div class="pcl-lf"><span>${esc(k)}</span><span>${v}</span></div>`;
  const pastille = `<span class="pcl-fid"><i style="background:${P.couleur}" aria-hidden="true"></i>${esc(P.nom)}</span>`;

  /* ── La fiche d'aujourd'hui (en ligne depuis le 28/11/2026) ── */
  function ficheAvant() {
    return `<aside class="card pcl-fiche"><div class="pcl-ft"><div class="pcl-fnom">Sophie Renard</div><div class="pcl-fmeta">client depuis 12/09/2024</div></div>
      <div class="pcl-kpis"><div><div class="v">5 %</div><div class="k">Remise</div></div><div><div class="v">Non</div><div class="k">Paiement différé</div></div><div><div class="v">Val-Fleuri</div><div class="k">Ville</div></div></div>
      <div class="pcl-bloc"><h4>Coordonnées</h4>${ligneF('Téléphone', '+32 400 55 12 08')}${ligneF('E-mail', 'sophie.renard@exemple.be')}${ligneF('Adresse', 'Avenue des Prés 27, 5000 Val-Fleuri')}
        ${ligneF('Compte en ligne', '<span class="chip chip--ok">En ligne</span> connecté le 05/11/2026')}</div>
      <div class="pcl-bloc pcl-acts"><button type="button" class="btn btn--primary btn--sm">Préparer une offre</button><button type="button" class="btn btn--sm">Modifier</button>
        <button type="button" class="btn btn--sm">Passer en entreprise</button><button type="button" class="btn btn--sm btn--danger">Supprimer</button></div>
      <div class="pcl-bloc pcl-doux"><h4>Passer en entreprise</h4><div class="pcl-aide">Le numéro de TVA se vérifie dans TF Buddy puis dans VIES et remplit la société et l’adresse ; la fiche passe dans Entreprises B2B avec ses conditions de paiement et sa remise.</div></div>
      <div class="pcl-bloc pcl-fid-bloc"><h4>Fidélité<span>reconnu en caisse sur 90 jours</span></h4>
        ${ligneF('Profil', pastille + `<span class="sub">${esc(P.condition)}</span>`)}
        ${ligneF('Rythme', `${nf(rythme, 1)} passages par mois<span class="sub">${dansFenetre} sur les 30 derniers jours</span>`)}
        ${ligneF('Passages', `${PASSAGES.length} en 90 jours<span class="sub">${fidDepuis(depuis)}</span>`)}
        ${ligneF('Panier moyen', nf(C.eur / C.tk, 2) + ' €')}${ligneF('En commande', nf(Math.round(100 * C.cmd / C.tk)) + ' %')}
        <div class="pcl-fid-radar"><div class="pcl-fid-seg">${seg('r')}</div>${radar([{ v: M.radar, c: 'var(--color-text-muted)', fond: 0.06, w: 1.2 }, { v: C.radar, c: P.couleur, fond: 0.16, w: 2.2 }], AXES, 310)}${legende()}
        <p class="pcl-aide">0 au centre, 100 au bord : fréquence, récence, ancienneté, panier, diversité et achats au comptoir.</p></div>
        <button type="button" class="linklike">Son historique : Fidélité ›</button></div></aside>`;
  }

  /* ── Les quatre façons de dire quand il vient ── */
  const JOURS90 = Array.from({ length: MEMOIRE }, (_, i) => jour(DEBUT, i));
  function quandA() {
    const traits = JOURS90.map(j => `<i class="${VU.has(j) ? 'on' : ''}${!VU.has(j) && dow(j) >= 5 ? ' we' : ''}"${VU.has(j) ? ` style="background:${P.couleur}" title="${fD(j)}"` : ''}></i>`).join('');
    const mois = JOURS90.filter(j => j.slice(8) === '01').map(j => `<span style="left:${(100 * JOURS90.indexOf(j) / MEMOIRE).toFixed(1)}%">${MOIS[+j.slice(5, 7) - 1]}</span>`).join('');
    return `<div class="pcl-fid-quand"><div class="lab">Ses passages<span>90 jours, un trait par jour</span></div>
      <div class="pcl-frise">${traits}</div><div class="pcl-frise-m">${mois}<span class="auj">auj.</span></div>
      <p class="dit"><b>${PASSAGES.length} passages</b> · le plus long creux : <b>${creux.n} jours</b>, du ${fJ(creux.du)} au ${fJ(creux.au)}</p></div>`;
  }
  function quandB() {
    const lundis = Array.from({ length: 13 }, (_, s) => jour(DEBUT, 7 * s));
    const tete = '<span></span>' + lundis.map((l, s) => { const m = Array.from({ length: 7 }, (_, i) => jour(l, i)).find(j => j.slice(8) === '01');
      return `<span class="m">${m ? MOIS[+m.slice(5, 7) - 1] : ''}</span>`; }).join('');
    let lignes = '';
    for (let d = 0; d < 7; d++) {
      lignes += `<span class="j">${JOURS[d]}</span>` + lundis.map(l => { const j = jour(l, d);
        return j > AUJ ? '<span class="c vide"></span>' : `<span class="c${j === AUJ ? ' auj' : ''}"${VU.has(j) ? ` style="background:${P.couleur}"` : ''} title="${fD(j)}"></span>`; }).join('');
    }
    const parJ = Array(7).fill(0); PASSAGES.forEach(j => parJ[dow(j)]++);
    const top = parJ.indexOf(Math.max(...parJ));
    return `<div class="pcl-fid-quand"><div class="lab">Ses passages<span>13 semaines, un carré par jour</span></div>
      <div class="pcl-cal13">${tete}${lignes}</div>
      <p class="dit">Vient surtout le <b>${JOURSL[top]}</b> (${parJ[top]} fois sur ${PASSAGES.length}) · absent ${Math.floor(creux.n / 7)} semaines en ${MOISL[+creux.du.slice(5, 7) - 1]}</p></div>`;
  }
  function quandC() {
    const sem = Array.from({ length: 13 }, (_, s) => { const l = jour(DEBUT, 7 * s); return { l, n: Array.from({ length: 7 }, (_, i) => jour(l, i)).filter(j => VU.has(j)).length }; });
    const cible = P.seuil * 7 / 30;   // le seuil du profil, ramené à la semaine (Hebdo : 4 par mois ≈ 0,9 par semaine)
    const mx = Math.max(3, ...sem.map(x => x.n)), H = 46;
    const bar = sem.map(x => `<i class="${x.n ? '' : 'zero'}" style="height:${Math.round(H * x.n / mx)}px;background:${x.n >= cible ? P.couleur : '#d8cfc2'}" title="semaine du ${fD(x.l)} : ${x.n}"></i>`).join('');
    const tenu = sem.filter(x => x.n >= cible).length;
    return `<div class="pcl-fid-quand"><div class="lab">Ses passages<span>par semaine · le trait : ${esc(P.condition)}</span></div>
      <div class="pcl-sem">${bar}<em style="bottom:${(H * cible / mx).toFixed(1)}px"></em></div>
      <div class="pcl-sem-l">${sem.map((x, s) => `<span>${s % 4 === 0 ? fD(x.l) : ''}</span>`).join('')}</div>
      <p class="dit">Au rythme de son profil <b>${tenu} semaines sur 13</b> · 3 semaines sans passage en octobre</p></div>`;
  }
  function quandD() {
    const mois = ['2026-09', '2026-10', '2026-11'], parM = [];
    const corps = mois.map(ym => {
      const n = new Date(Date.UTC(+ym.slice(0, 4), +ym.slice(5, 7), 0)).getUTCDate();
      const js = Array.from({ length: n }, (_, i) => ym + '-' + String(i + 1).padStart(2, '0'));
      const vus = js.filter(j => VU.has(j)).length, ecoules = js.filter(j => j <= AUJ && j >= DEBUT).length;
      const q = profilDe(vus * 30 / Math.max(1, ecoules)); parM.push(q.nom + ' en ' + MOISL[+ym.slice(5, 7) - 1]);
      return `<span class="mo">${MOIS[+ym.slice(5, 7) - 1]}</span><span class="jr">${js.map(j => `<i class="${j > AUJ ? 'fut' : ''}"${VU.has(j) ? ` style="background:${P.couleur}"` : ''}></i>`).join('')}</span>
        <span class="pf"><i style="background:${q.couleur}"></i>${esc(q.nom)} <small>${vus}×</small></span>`; }).join('');
    return `<div class="pcl-fid-quand"><div class="lab">Ses passages<span>mois par mois, son profil du mois</span></div><div class="pcl-mpm">${corps}</div>
      <p class="dit">${parM.map((x, i) => i === 1 ? '<b>' + esc(x) + '</b>' : esc(x)).join(', ')} (trois semaines d’absence)</p></div>`;
  }

  /* ── La fiche serrée, avec l'une des quatre ── */
  function ficheSerree(quand) {
    const tk = C.tk;
    return `<aside class="card pcl-fiche pcl-serre"><div class="pcl-ft"><div class="pcl-fnom">Sophie Renard</div><div class="pcl-fmeta">client depuis 12/09/2024 · <b>remise 5 %</b> · pas de paiement différé</div></div>
      <div class="pcl-bloc"><h4>Coordonnées</h4>${ligneF('Téléphone', '+32 400 55 12 08')}${ligneF('E-mail', 'sophie.renard@exemple.be')}${ligneF('Adresse', 'Avenue des Prés 27, 5000 Val-Fleuri')}
        ${ligneF('Compte en ligne', '<span class="chip chip--ok">En ligne</span> 05/11/2026')}</div>
      <div class="pcl-bloc pcl-acts"><button type="button" class="btn btn--primary btn--sm">Préparer une offre</button><button type="button" class="btn btn--sm">Modifier</button>
        <button type="button" class="btn btn--sm" title="Le numéro de TVA se vérifie dans TF Buddy puis dans VIES ; la fiche passe dans Entreprises B2B">Passer en entreprise</button><button type="button" class="btn btn--sm btn--danger">Supprimer</button></div>
      <div class="pcl-bloc pcl-fid-bloc"><h4>Fidélité<span>reconnu en caisse sur 90 jours</span></h4>
        <div class="pcl-fid-tete"><span>${pastille} <small>${esc(P.condition)}</small></span><small>${fidDepuis(depuis)}</small></div>
        <div class="pcl-fid-k"><div><div class="v">${nf(rythme, 1)}</div><div class="k">par mois</div></div><div><div class="v">${PASSAGES.length}</div><div class="k">en 90 j</div></div>
          <div><div class="v">${nf(C.eur / tk, 2)} €</div><div class="k">panier</div></div><div><div class="v">${nf(Math.round(100 * C.cmd / tk))} %</div><div class="k">commande</div></div></div>
        ${quand()}
        <div class="pcl-fid-radar"><div class="pcl-fid-seg">${seg('r')}</div>${radar([{ v: M.radar, c: 'var(--color-text-muted)', fond: 0.06, w: 1.2 }, { v: C.radar, c: P.couleur, fond: 0.16, w: 2.2 }], AXES, 310)}${legende()}</div>
        <button type="button" class="linklike">Son historique : Fidélité ›</button></div></aside>`;
  }

  const V = {
    avant: ['Aujourd’hui (en ligne)', 'La fiche du 28/11 : cinq lignes de chiffres, l’aide « Passer en entreprise », le radar sous le pli.', ficheAvant],
    a: ['A · La frise des 90 jours', 'Un trait par jour, plein le jour d’un passage : le rythme et les creux d’un coup d’œil.', () => ficheSerree(quandA)],
    b: ['B · Le calendrier des 13 semaines', 'Un carré par jour, une ligne par jour de la semaine : quand, et quel jour il vient.', () => ficheSerree(quandB)],
    c: ['C · Semaine par semaine', 'Une barre par semaine face au rythme de son profil : tient-il son rendez-vous ?', () => ficheSerree(quandC)],
    d: ['D · Mois par mois', 'Les jours de chaque mois et le profil du mois : il glisse, il revient.', () => ficheSerree(quandD)],
  };
  const PLI = 840;   // le bas de l'écran d'un portable 1080p, depuis le haut de la fiche
  const q = new URLSearchParams(location.search).get('v');
  const cles = q && V[q] ? [q] : ['avant', 'a', 'b', 'c', 'd'];
  document.getElementById('app').innerHTML = `<div class="mq"><h1>Clients › Particuliers — la fiche plus serrée et « quand il vient »</h1>
    <p>Client fictif (Hebdo, 13 passages en 90 jours, absent trois semaines en octobre). A à D ont la même fiche serrée : la remise et le paiement différé dans la ligne sous le nom (la ville est dans l’adresse), coordonnées resserrées, l’aide « Passer en entreprise » dans le titre du bouton, quatre chiffres de fidélité sur une ligne, un radar plus petit. Seul le dessin « Ses passages » change. Le trait rouge : le bas d’un écran 1080p.</p>
    <div class="mq-cols" style="--n:${cles.length}">${cles.map(k => `<div class="mq-col" data-v="${k}"><h2>${V[k][0]}</h2><p class="mq-q">${V[k][1]}</p>
      <div class="mq-cadre">${V[k][2]()}<div class="mq-pli" style="top:${PLI}px"><span>bas de l’écran</span></div></div><p class="mq-h"></p></div>`).join('')}</div></div>`;
  // La hauteur de chaque fiche, et où tombe le bas du radar.
  document.querySelectorAll('.mq-col').forEach(c => {
    const f = c.querySelector('.pcl-fiche'), r = c.querySelector('.fd-radar'), h = c.querySelector('.mq-h');
    const bas = r.getBoundingClientRect().bottom - f.getBoundingClientRect().top;
    h.textContent = 'Fiche : ' + Math.round(f.offsetHeight) + ' px · bas du radar à ' + Math.round(bas) + ' px' + (bas <= PLI ? ' (visible)' : ' (sous le pli)');
  });
})();
