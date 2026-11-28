/* Clients › Particuliers — un passage de la frise ouvre son ticket (maquettes, 28/11/2026).
 * Demande : « avant de mettre en ligne, ajouter la possibilité de cliquer sur une visite et d'avoir son ticket ; fais une
 * maquette ». La fiche A (ff.js), le même client FICTIF ; trois façons d'ouvrir le ticket du samedi 21/11 :
 *   1 · la bulle sous le trait, par-dessus la fiche ;   2 · sous la frise, dans la fiche ;   3 · le ticket de caisse à côté.
 * Ce qu'un ticket du panel donne (/transactions/{id}?include=products, lu par le cockpit) : l'heure, le canal (comptoir,
 * commande, webshop), le montant ; par ligne, le produit, la portion, la quantité, le prix unitaire et le total après
 * remise. Rien d'autre n'est montré. */
(function () {
  'use strict';
  const { ficheSerree, quandA, PASSAGES, esc, nf, MOISL } = window.FF;
  const fE = n => nf(n, 2) + ' €';
  const JS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
  const jourLong = iso => JS[new Date(iso + 'T12:00:00Z').getUTCDay()] + ' ' + (+iso.slice(8, 10)) + ' ' + MOISL[+iso.slice(5, 7) - 1];
  const jourCourt = iso => JS[new Date(iso + 'T12:00:00Z').getUTCDay()].slice(0, 3) + '. ' + iso.slice(8, 10) + '/' + iso.slice(5, 7);
  // Le ticket du samedi 21/11 (fictif) : remise personnelle de 5 % sur chaque ligne.
  const SEL = '2026-11-21';
  const T = { n: '28417', heure: '09:42', canal: 'Comptoir', remise: 5,
    lignes: [['Pain demi-gris tranché', '', 1, 3.20], ['Croissant au beurre', '', 2, 1.30], ['Pain au chocolat', '', 1, 1.40], ['Tarte aux fraises', '1/6', 1, 4.50]] };
  const brut = T.lignes.reduce((a, l) => a + l[2] * l[3], 0), apres = l => Math.round(l[2] * l[3] * (100 - T.remise)) / 100;
  const net = T.lignes.reduce((a, l) => a + apres(l), 0);
  const i = PASSAGES.indexOf(SEL), avant = PASSAGES[i - 1], ensuite = PASSAGES[i + 1];
  const lignes = (pu) => `<div class="pcl-tk-l">${T.lignes.map(l => `<span class="q">${l[2]}×</span><span class="n">${esc(l[0])}${l[1] ? ' <small>portion ' + esc(l[1]) + '</small>' : ''}${pu && l[2] > 1 ? `<small>${l[2]} × ${fE(l[3])}</small>` : ''}</span><span class="t">${fE(apres(l))}</span>`).join('')}</div>`;
  const totaux = `<div class="pcl-tk-s"><span>dont remise ${T.remise} %</span><span>− ${fE(brut - net)}</span></div><div class="pcl-tk-s tot"><span>Total</span><span>${fE(net)}</span></div>`;
  const nav = `<div class="pcl-tk-nav"><button type="button" class="linklike">‹ ${jourCourt(avant)}</button><button type="button" class="linklike">${jourCourt(ensuite)} ›</button></div>`;
  const tete = `<div class="pcl-tk-h"><b>${esc(jourLong(SEL))}</b><button type="button" class="x" aria-label="Fermer">×</button></div>
    <div class="pcl-tk-h2"><span class="pcl-tk-c">${T.canal}</span>${T.heure} · ticket n° ${T.n}</div>`;

  const bulle = `<div class="pcl-tk pcl-tk--bulle">${tete}${lignes(false)}${totaux}${nav}</div>`;
  const dans = `<div class="pcl-tk pcl-tk--dans">${tete}${lignes(false)}${totaux}${nav}</div>`;
  const recu = `<div class="pcl-tk pcl-tk--recu"><div class="rt"><b>Magasin démo</b><span>Ticket n° ${T.n}</span><span>${esc(jourLong(SEL))} 2026 · ${T.heure}</span></div>
    ${lignes(true)}${totaux}<p class="pied">${T.canal} · client reconnu en caisse</p>${nav}</div>`;

  const V = [
    ['1 · La bulle', 'Un clic sur un trait : son ticket dans une bulle, par-dessus la fiche. Rien ne bouge ; ‹ › passe au passage d’avant ou d’après, × ou un clic à côté la ferme.',
      ficheSerree(() => quandA({ sel: SEL, dans: bulle, lab: 'un clic sur un trait : son ticket' }))],
    ['2 · Sous la frise', 'Le ticket s’ouvre dans la fiche, sous la frise ; le radar descend le temps de le lire. ‹ › et × comme dans la bulle.',
      ficheSerree(() => quandA({ sel: SEL, dans: dans, lab: 'un clic sur un trait : son ticket' }))],
    ['3 · Le ticket de caisse à côté', 'Le ticket, mis en page comme celui de la caisse (n°, prix unitaires), dans un panneau à gauche de la fiche, par-dessus la liste. La fiche ne bouge pas.',
      `<div class="mq-duo">${recu}${ficheSerree(() => quandA({ sel: SEL, lab: 'un clic sur un trait : son ticket' }))}</div>`, true],
  ];
  document.getElementById('app').innerHTML = `<div class="mq"><h1>Clients › Particuliers — un passage de la frise ouvre son ticket</h1>
    <p>La fiche A (en attente de mise en ligne), même client fictif. Un clic sur le trait du samedi 21 novembre ouvre son ticket : l’heure, le canal, les articles et leur total après sa remise de 5 %, le total ; ‹ › passe au passage d’avant ou d’après. Au survol d’un trait, une info-bulle dit déjà le jour et le montant.</p>
    <div class="mq-cols" style="grid-template-columns:370px 370px 650px">${V.map((v, k) => `<div class="mq-col" data-v="${k + 1}"><h2>${v[0]}</h2><p class="mq-q">${v[1]}</p><div class="mq-cadre">${v[2]}</div></div>`).join('')}</div></div>`;
  // La bulle sous le trait choisi, sans sortir de la fiche ; sa flèche pointe le trait (comme le ferait clients.js).
  document.querySelectorAll('.pcl-tk--bulle').forEach(b => {
    const q = b.closest('.pcl-fid-quand'), t = q.querySelector('.pcl-frise i.sel'), Q = q.getBoundingClientRect(), R = t.getBoundingClientRect();
    const x = R.left + R.width / 2 - Q.left, g = Math.max(0, Math.min(Q.width - b.offsetWidth, x - b.offsetWidth / 2));
    b.style.left = g + 'px'; b.style.setProperty('--fleche', (x - g) + 'px'); b.style.top = (R.bottom - Q.top + 9) + 'px';
  });
})();
