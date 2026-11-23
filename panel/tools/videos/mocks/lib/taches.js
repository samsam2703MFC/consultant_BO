/* Les tâches du jour et les contrôles en photo du magasin de démo ; les photos sont des illustrations SVG servies
 * par la fausse API (https://demo.local/api/cockpit/__photo/<nom>.svg : l'adresse passe par la route de mock.js). */
const D = require('./demo');
const PH = n => 'https://demo.local/api/cockpit/__photo/' + n + '.svg';
const CL = [['1.1.01 · Ouverture du comptoir', '06:45'], ['2.1.01 · Cuisson du matin', '06:30'], ['3.1.01 · Mise en place snacking', '10:45'], ['4.1.02 · Nettoyage du fournil', '12:30'], ['1.2.01 · Comptoir de l’après-midi', '16:00'], ['4.2.01 · Fermeture', '19:00']];
function taches(date) {
  const j = date === D.AUJ, d = date;
  const T = [
    { taskId: 9001, tache: 'Photo du comptoir - vitrine viennoiseries', checklist: CL[0][0], statut: 'notee', photoRequise: true, faitLe: d + ' 06:52:00', faitePar: 'Julie M.', note: 5, valideeLe: d + ' 09:10:00', valideePar: 'Le consultant' },
    { taskId: 9002, tache: 'Photo du comptoir - vitrine pâtisseries', checklist: CL[0][0], statut: 'notee', photoRequise: true, faitLe: d + ' 06:58:00', faitePar: 'Julie M.', note: 4, valideeLe: d + ' 09:12:00', valideePar: 'Le consultant' },
    { taskId: 9003, tache: 'Contrôle Qualité – Croissants à la sortie du four', checklist: CL[1][0], statut: 'notee', photoRequise: true, faitLe: d + ' 06:41:00', faitePar: 'Thomas D.', note: 3, valideeLe: d + ' 09:15:00', valideePar: 'Le consultant', comment: 'coloration inégale sur la plaque du bas' },
    { taskId: 9004, tache: 'Contrôle Qualité – Sandwichs du midi', checklist: CL[2][0], statut: 'aControler', photoRequise: true, faitLe: d + ' 10:56:00', faitePar: 'Lucas V.' },
    { taskId: 9005, tache: 'Plan de travail du fournil', checklist: CL[3][0], statut: 'notee', photoRequise: true, faitLe: d + ' 12:41:00', faitePar: 'Thomas D.', note: 4, valideeLe: d + ' 13:30:00', valideePar: 'Le consultant' },
    { taskId: 9006, tache: 'Photo du comptoir après-midi - vitrine', checklist: CL[4][0], statut: j ? 'nonRendue' : 'notee', photoRequise: true },
    { taskId: 9007, tache: 'Alarme activée', checklist: CL[5][0], statut: 'nonRendue', photoRequise: false },
    { taskId: 9008, tache: 'Températures des frigos relevées', checklist: CL[0][0], statut: 'fait', photoRequise: false, faitLe: d + ' 07:05:00', faitePar: 'Julie M.' },
  ];
  return { date, seuil: 4, repartition: null, shops: [{ shopId: D.SHOP, magasin: D.NOM, taches: T.map(t => Object.assign({ obligatoire: true, accepte: true }, t)), checklists: CL.map(([nom, heure]) => ({ nom, heure })) }] };
}
function photos(date) {
  return { date, checklists: {}, photos: [
    { taskId: 9001, photo: PH('viennoiseries'), reperes: [] },
    { taskId: 9002, photo: PH('patisseries'), reperes: [] },
    { taskId: 9003, photo: PH('croissants'), reperes: [{ n: 1, x: 0.08, y: 0.58, l: 0.42, h: 0.3, txt: 'coloration inégale', niveau: 3 }] },
    { taskId: 9004, photo: PH('sandwichs'), reperes: [] },
    { taskId: 9005, photo: PH('fournil'), reperes: [] },
  ] };
}
/* Les illustrations : une vitrine, des produits dessinés. */
const fond = (c1, c2) => `<defs><linearGradient id="f" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${c1}"/><stop offset="1" stop-color="${c2}"/></linearGradient>
  <radialGradient id="dore" cx=".45" cy=".35" r=".8"><stop offset="0" stop-color="#f6c873"/><stop offset=".6" stop-color="#d9973b"/><stop offset="1" stop-color="#a8641f"/></radialGradient>
  <radialGradient id="fonce" cx=".45" cy=".35" r=".8"><stop offset="0" stop-color="#d89a4a"/><stop offset=".6" stop-color="#9c5a1c"/><stop offset="1" stop-color="#5e3210"/></radialGradient>
  <radialGradient id="pale" cx=".45" cy=".35" r=".8"><stop offset="0" stop-color="#f8e2a8"/><stop offset="1" stop-color="#d9b06a"/></radialGradient></defs>
  <rect width="640" height="480" fill="url(#f)"/>`;
const etagere = y => `<rect x="0" y="${y}" width="640" height="14" fill="#6d4a2c"/><rect x="0" y="${y + 14}" width="640" height="4" fill="#3d2816" opacity=".5"/>`;
const croissant = (x, y, s, g) => `<g transform="translate(${x} ${y}) scale(${s})"><path d="M-46 8 C-40 -26 40 -26 46 8 C30 -2 18 -6 0 -6 C-18 -6 -30 -2 -46 8Z" fill="url(#${g || 'dore'})"/>
  <path d="M-22 -14 q4 10 0 18 M0 -17 q4 11 0 20 M22 -14 q4 10 0 18" stroke="#8a4f17" stroke-width="2.2" fill="none" opacity=".55"/><ellipse cx="0" cy="12" rx="44" ry="5" fill="#000" opacity=".12"/></g>`;
const painChoco = (x, y) => `<g transform="translate(${x} ${y})"><rect x="-34" y="-16" width="68" height="30" rx="10" fill="url(#dore)"/><rect x="-26" y="-9" width="10" height="16" rx="3" fill="#4a2a12" opacity=".75"/><rect x="16" y="-9" width="10" height="16" rx="3" fill="#4a2a12" opacity=".75"/><path d="M-30 -4 h60" stroke="#8a4f17" stroke-width="2" opacity=".4"/></g>`;
const tartelette = (x, y, c) => `<g transform="translate(${x} ${y})"><ellipse cx="0" cy="8" rx="34" ry="12" fill="#c98a3f"/><ellipse cx="0" cy="2" rx="30" ry="10" fill="${c}"/><circle cx="-10" cy="0" r="6" fill="#fff" opacity=".35"/><circle cx="10" cy="3" r="5" fill="#000" opacity=".12"/></g>`;
const eclair = (x, y) => `<g transform="translate(${x} ${y})"><rect x="-42" y="-10" width="84" height="22" rx="11" fill="#e0b36a"/><rect x="-40" y="-12" width="80" height="12" rx="6" fill="#4b2813"/><path d="M-30 -9 h40" stroke="#fff" stroke-width="2" opacity=".25"/></g>`;
const sandwich = (x, y) => `<g transform="translate(${x} ${y})"><path d="M-60 0 q60 -22 120 0 v14 q-60 10 -120 0z" fill="url(#pale)"/><path d="M-58 12 q58 8 116 0" stroke="#5f9a3a" stroke-width="6" fill="none"/><path d="M-56 16 q56 7 112 0" stroke="#e7a3a0" stroke-width="5" fill="none"/><path d="M-60 18 q60 10 120 0 v6 q-60 10 -120 0z" fill="#d9b06a"/></g>`;
const etiquette = (x, y, t, p) => `<g transform="translate(${x} ${y})"><rect x="-38" y="0" width="76" height="24" rx="3" fill="#fff" stroke="#8D1D2C" stroke-width="1.5"/><text x="0" y="10" font-family="Inter, sans-serif" font-size="8.5" text-anchor="middle" fill="#1d1d1b">${t}</text><text x="0" y="20" font-family="Inter, sans-serif" font-size="9" font-weight="700" text-anchor="middle" fill="#8D1D2C">${p}</text></g>`;
const ILL = {
  viennoiseries: () => fond('#f4ede2', '#e6dccd') + etagere(190) + etagere(400)
    + [70, 190, 310, 430, 550].map((x, i) => croissant(x, 160 + (i % 2) * 6, 1.05)).join('') + [110, 250, 390, 520].map((x, i) => croissant(x, 120 - (i % 2) * 4, 0.95)).join('')
    + [70, 170, 270, 370, 470, 570].map(x => painChoco(x, 370)).join('') + [120, 220, 320, 420, 520].map(x => painChoco(x, 335)).join('')
    + etiquette(140, 212, 'Croissant au beurre', '1,40 €') + etiquette(470, 212, 'Croissant amandes', '2,40 €') + etiquette(320, 420, 'Pain au chocolat', '1,60 €'),
  patisseries: () => fond('#f7f1ea', '#ebe1d4') + etagere(200) + etagere(410)
    + [80, 200, 320].map(x => tartelette(x, 165, '#c8243a')).join('') + [440, 560].map(x => tartelette(x, 165, '#f0b429')).join('')
    + [100, 230, 360, 490].map(x => eclair(x, 370)).join('') + [165, 295, 425, 555].map(x => eclair(x, 340)).join('')
    + etiquette(200, 222, 'Tartelette fruits rouges', '3,90 €') + etiquette(500, 222, 'Tartelette citron', '3,90 €') + etiquette(320, 430, 'Éclair au chocolat', '3,20 €'),
  croissants: () => `<defs><linearGradient id="f" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#3b3b3b"/><stop offset="1" stop-color="#262626"/></linearGradient>
    <radialGradient id="dore" cx=".45" cy=".35" r=".8"><stop offset="0" stop-color="#f6c873"/><stop offset=".6" stop-color="#d9973b"/><stop offset="1" stop-color="#a8641f"/></radialGradient>
    <radialGradient id="fonce" cx=".45" cy=".35" r=".8"><stop offset="0" stop-color="#c98436"/><stop offset=".6" stop-color="#8a4a14"/><stop offset="1" stop-color="#4f280b"/></radialGradient></defs>
    <rect width="640" height="480" fill="url(#f)"/><rect x="30" y="30" width="580" height="420" rx="8" fill="#9a9a9a"/><rect x="40" y="40" width="560" height="400" rx="6" fill="#efe9df"/>`
    + [0, 1, 2, 3].map(r => [0, 1, 2, 3, 4].map(c => croissant(100 + c * 110, 100 + r * 95, 0.92, r >= 2 && c <= 2 ? 'fonce' : 'dore')).join('')).join(''),
  sandwichs: () => fond('#eef3f6', '#dde6ea') + etagere(210) + etagere(420)
    + [110, 300, 490].map(x => sandwich(x, 175)).join('') + [110, 300, 490].map(x => sandwich(x, 385)).join('') + [205, 395].map(x => sandwich(x, 140)).join('')
    + etiquette(200, 232, 'Jambon-beurre', '4,90 €') + etiquette(440, 232, 'Poulet crudités', '5,50 €') + etiquette(320, 440, 'Wrap végétarien', '5,20 €'),
  fournil: () => `<defs><linearGradient id="f" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#d9dde0"/><stop offset=".5" stop-color="#f4f6f7"/><stop offset="1" stop-color="#c3c8cc"/></linearGradient></defs>
    <rect width="640" height="480" fill="#8d8f91"/><rect x="20" y="60" width="600" height="380" rx="6" fill="url(#f)"/><path d="M60 120 L300 400 M200 80 L480 420 M380 70 L600 330" stroke="#fff" stroke-width="18" opacity=".35"/>
    <rect x="460" y="90" width="120" height="70" rx="6" fill="#fff" stroke="#b9bec2"/><rect x="474" y="104" width="92" height="10" rx="3" fill="#cfd5d9"/><rect x="474" y="122" width="70" height="10" rx="3" fill="#cfd5d9"/>
    <circle cx="120" cy="370" r="28" fill="#fff" stroke="#b9bec2" stroke-width="4"/><rect x="70" y="90" width="90" height="12" rx="6" fill="#8D1D2C" opacity=".8"/>`,
};
function illustration(nom) {
  const f = ILL[nom.replace(/\.svg$/, '')];
  if (!f) { return null; }
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 480" width="640" height="480">${f()}</svg>`;
}
module.exports = { taches, photos, illustration };
