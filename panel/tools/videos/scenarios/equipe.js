/* Les gestes de « Mon équipe » filmés, communs à la vidéo courte (02) et au module de formation (03).
 * Données fictives : mocks/20_equipe.js (semaine du 16 au 22 novembre 2026, jeudi 19 = aujourd'hui).
 * Le récit : le comptoir est vide mardi de 13 h 45 à 14 h 30 → on avance le service qui suit ; vendredi est rouge
 * → on retire une personne du shift de l'après-midi. */
const C = {
  mardi: '2026-11-17', vendredi: '2026-11-20', jeudi: '2026-11-19',
  // Le service à avancer mardi (il commence à 14 h 30, au bout du trou) et celui à retirer vendredi.
  trou: { emp: null, de: '13:45', a: '14:30' },
  retrait: { emp: null },
  traceur: { emp: '201', de: 0.625, a: 0.875 },   // Camille, au repos jeudi : 14 h → 18 h
};

/** La position, sur le tracé 4 h → 20 h de la fenêtre d'un service, d'une heure « HH:MM ». */
function surTrace(r, hhmm) { const [h, m] = hhmm.split(':').map(Number); return r.x + r.w * ((h + m / 60 - 4) / 16); }

/** Trouve, dans le tableau de la semaine, le service d'un jour qui commence à une heure donnée (ou le dernier du jour). */
async function serviceDuJour(f, date, { commence, finit, secteur } = {}) {
  return f.p.evaluate(({ date, commence, finit, secteur }) => {
    const L = [...document.querySelectorAll(`[data-eqp-cell="${date}"] [data-eqp-svc]`)];
    const t = el => (el.textContent || '').replace(/\s+/g, ' ');
    const x = L.find(el => (!commence || t(el).includes(commence)) && (!finit || t(el).includes(finit)) && (!secteur || t(el).toLowerCase().includes(secteur)));
    return x ? x.closest('[data-eqp-cell]').getAttribute('data-emp') : null;
  }, { date, commence, finit, secteur });
}

async function ouvrirMonEquipe(f) {
  await f.cliquer('#db-rail .sidebar__item[data-vue=equipe]', { apres: 1400 });
  await f.dire('<b>Mon équipe</b> s’ouvre sur les Responsabilités : qui s’occupe de quoi, poste par poste.', 3600);
  await f.cliquer('[data-eqp-onglet=planning]', { apres: 1500 });
}

async function lireLaSemaine(f, { long } = {}) {
  await f.montrer('.eqp-pbar .seg', 'Jour · Semaine · Mois');
  await f.dire('Le planning s’ouvre sur la <b>semaine</b>. Jour, Semaine, Mois : trois façons de le lire.', 4200);
  await f.cacher();
  await f.montrer('.eqp-kpis', 'La semaine en chiffres');
  await f.dire('En tête : les personnes au planning, les heures, et la part du <b>personnel dans le chiffre d’affaires</b>.', 4600);
  await f.cacher();
  await f.montrer('.eqp-table tbody tr:nth-child(2)', 'Une ligne par personne');
  await f.dire('Une ligne par personne, une case par jour : chaque case est un <b>service</b>, avec son secteur.', 4200);
  await f.cacher();
  await f.montrer('.eqp-table tr.tot', 'Par jour');
  await f.dire('Sous le tableau, <b>chaque jour</b> : le nombre de personnes, les heures, et le pourcentage — vert, orange ou rouge.', 5000);
  await f.cacher();
  await f.montrerTout('.eqp-table tr.eqp-lin', 'Les linéaires');
  await f.dire('Les <b>linéaires</b> montrent qui tient chaque secteur et le comptoir. Un trou y est signalé en jaune.', 4800);
  if (long) { await f.dire('Ici, <b>mardi</b> : personne au comptoir de 13 h 45 à 14 h 30.', 3400); }
  await f.cacher();
}

async function leBudget(f, { long } = {}) {
  await f.montrer('.eqp-bj', 'Le budget d’heures');
  await f.dire('Le <b>budget d’heures</b> : le coût du personnel face au chiffre d’affaires de la semaine.', 4400);
  await f.dire('<b>17 %</b>, c’est la cible du réseau. Orange au-dessus, <b>rouge</b> au-dessus de 20 %, noir au-dessus de 22 %.', 5200);
  if (long) { await f.dire('À droite, ce qu’il faut faire pour revenir à 17 % : les <b>heures à retirer</b>, ou le coût moyen à viser.', 5000); }
  await f.cacher();
}

async function corrigerLeTrou(f) {
  await f.defilerVers(f.p.locator('.eqp-table thead'), 60);
  if (!C.trou.emp) { C.trou.emp = await serviceDuJour(f, C.mardi, { commence: C.trou.a }); }
  const cible = `[data-eqp-cell="${C.mardi}"][data-emp="${C.trou.emp}"] [data-eqp-svc]`;
  await f.montrer(cible, 'Mardi');
  await f.dire('Mardi, ce service commence à <b>14 h 30</b> : juste à la fin du trou. Un clic l’ouvre.', 3800);
  await f.cacher(150);
  await f.cliquer(cible, { apres: 1300 });
  await f.montrer('[data-eqp-trace]', 'La ligne du service');
  await f.dire('La fenêtre du service. Plus de champ d’heure : on <b>tire la ligne</b>, au quart d’heure.', 4200);
  await f.cacher(150);
  const tr = await f.rect('[data-eqp-trace]');
  const de = await f.rect('[data-eqp-poignee=de]');
  await f.tirer(de.x + de.w / 2, de.y + de.h / 2, surTrace(tr, C.trou.de), de.y + de.h / 2, 1500);
  await f.dire('On avance le début à <b>13 h 45</b> : le comptoir n’est plus vide. L’effet sur le budget s’affiche en dessous.', 4800);
  await f.cliquer('[data-eqp-ok]', { apres: 1600 });
  await f.montrerTout('.eqp-table tr.eqp-lin', 'Le trou a disparu');
  await f.dire('Enregistré au planning : le <b>trou a disparu</b> des linéaires.', 3600);
  await f.cacher();
}

async function retirerDuShift(f) {
  await f.defilerVers(f.p.locator('.eqp-table tr.tot'), 260);
  await f.montrer(`.eqp-table tr.tot td:nth-child(${await colonne(f, C.vendredi)})`, 'Vendredi');
  await f.dire('Vendredi est <b>rouge</b> : trop d’heures pour le chiffre prévu.', 3400);
  await f.cacher(150);
  if (!C.retrait.emp) { C.retrait.emp = await serviceDuJour(f, C.vendredi, { finit: '19:15', secteur: 'comptoir' }); }
  const cible = `[data-eqp-cell="${C.vendredi}"][data-emp="${C.retrait.emp}"] [data-eqp-svc]`;
  await f.cliquer(cible, { apres: 1300 });
  await f.montrer('[data-eqp-suppr]', 'Retirer du shift');
  await f.dire('Dans la fenêtre du service, <b>Retirer du shift</b> enlève cette personne de ce service.', 3800);
  await f.cacher(150);
  await f.cliquerConfirme('[data-eqp-suppr]', 'Retirer cette personne du shift ?', 'Son service de ce jour est supprimé du planning.', { apres: 1600 });
  await f.defilerVers(f.p.locator('.eqp-table tr.tot'), 260);
  await f.montrer(`.eqp-table tr.tot td:nth-child(${await colonne(f, C.vendredi)})`, 'Vendredi');
  await f.dire('Vendredi repasse sous la barre : le pourcentage et sa couleur se recalculent aussitôt.', 4000);
  await f.cacher();
}

/** Le numéro de colonne (1…) d'un jour dans le tableau de la semaine. */
async function colonne(f, date) {
  return f.p.evaluate(date => {
    const td = document.querySelector(`[data-eqp-cell="${date}"]`);
    return td ? [...td.parentElement.children].indexOf(td) + 1 : 2;
  }, date);
}

async function vueJour(f) {
  await f.defiler(-3000);
  await f.cliquer('[data-eqp-vue=jour]', { apres: 1500 });
  await f.dire('La vue <b>Jour</b> : le planogramme. Qui est là, heure par heure, les temps morts et le budget du jour.', 5000);
}

async function tracerUnService(f) {
  // Au jour, une personne au repos : on lui tire un service sur sa ligne.
  const sel = `[data-eqp-tl="${C.traceur.emp}"]`;
  await f.montrer(sel, 'Camille est au repos');
  await f.dire('Pour créer un service, pas de formulaire : on <b>tire une ligne</b> à la souris sur celle de la personne.', 4400);
  await f.cacher(150);
  const r = await f.rect(sel);
  await f.tirer(r.x + r.w * C.traceur.de, r.y + r.h / 2, r.x + r.w * C.traceur.a, r.y + r.h / 2, 1800);
  await f.dire('La fenêtre s’ouvre sur ce service, de <b>14 h à 18 h</b> : on lit son effet sur le budget, puis on l’ajoute.', 4600);
  const ok = f.p.getByRole('button', { name: 'Ajouter le service' }).first();
  if (await ok.count()) { await f.cliquer(ok, { apres: 1700 }); }
  await f.dire('Le service est au planning : la ligne de Camille et le budget du jour sont à jour.', 3800);
}

async function vueMois(f) {
  await f.defiler(-3000);
  await f.cliquer('[data-eqp-vue=mois]', { apres: 1500 });
  await f.dire('La vue <b>Mois</b> : les heures de chacun, jour par jour, et le budget par jour et par semaine.', 4600);
}

async function imprimer(f) {
  await f.defiler(-3000);
  await f.cliquer('[data-eqp-pdf]', { apres: 1300 });
  await f.dire('<b>Imprimer le planning</b> : une page A4 par semaine, tout le magasin ou une page par section, à afficher pour l’équipe.', 5400);
  await f.montrer('.eqp-pdf', 'Imprimer le planning');
  await f.dire('On choisit la semaine, une ou deux semaines, la présentation, puis <b>Télécharger le PDF</b>.', 4600);
  await f.cacher();
  await f.cliquer('[data-eqp-pdf]', { apres: 700 });
}

module.exports = { C, ouvrirMonEquipe, lireLaSemaine, leBudget, corrigerLeTrou, retirerDuShift, vueJour, tracerUnService, vueMois, imprimer };
