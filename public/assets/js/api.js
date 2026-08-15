/* Cockpit CEO — L'Atelier by · couche d'accès unique aux données.
 *
 * Règle : aucune valeur métier n'est écrite dans le HTML. Chaque écran lit un
 * endpoint REST, la réponse est normalisée ici, et le composant ne connaît que
 * la forme normalisée. Contrat JSON + mapping base de données : contrat-api.md
 *
 * Base d'URL : window.COCKPIT_API_BASE (sinon /api/cockpit).
 * Si l'API est injoignable, un repli VIDE est affiché (aucune donnée de
 * démonstration embarquée) — la source est indiquée dans p.source ('offline').
 */

/* Base d'URL de l'API : surcharge via window.COCKPIT_API_BASE, sinon déduite
 * de l'URL de la page — « api/cockpit » à côté d'index.html, ce qui fonctionne
 * à la racine (/api/cockpit) comme sous un sous-répertoire
 * (/consulant_bo/api/cockpit). */
export const API_BASE = (typeof window !== 'undefined' && window.COCKPIT_API_BASE)
  || (typeof location !== 'undefined' ? location.pathname.replace(/[^/]*$/, '') + 'api/cockpit' : '/api/cockpit');

export const ENDPOINTS = {
  meta:           '/meta',
  leviers:        '/referentiels/leviers',
  kpis:           '/referentiels/kpis',
  emailTemplates: '/referentiels/email-templates',
  projTemplates:  '/referentiels/project-templates',
  stores:         '/stores?statut=tous',
  perf:           '/stores/perf?granularite=mois&annees=2025,2026',
  budgets:        '/stores/budgets?exercice=2026',
  targets:        '/targets',
  consultants:    '/consultants',
  suppliers:      '/fournisseurs',
  projects:       '/projects',
  crm:            '/projects/crm',
  people:         '/people',
  reporting:      '/reporting',
  journal:        '/journal',
  products:       '/products/scoring?periode=2026-07',
  pwaReports:     '/pwa/reports'
};

async function get(path, signal){
  const r = await fetch(API_BASE + path, { headers: { Accept: 'application/json' }, signal, credentials: 'same-origin' });
  if (!r.ok) throw new Error(path + ' → HTTP ' + r.status);
  return r.json();
}

/* --- Authentification intégrée ------------------------------------------- */

/** État d'auth : { setup, authed } — ou null si l'API est injoignable
 *  (mode démo : pas d'authentification). */
export async function authStatus(){
  const ctl = new AbortController();
  const t = setTimeout(() => ctl.abort(), 4000);
  try {
    const r = await fetch(API_BASE + '/auth/status', { headers: { Accept: 'application/json' }, signal: ctl.signal, credentials: 'same-origin' });
    clearTimeout(t);
    if (!r.ok) return null;
    return await r.json();
  } catch (e) { clearTimeout(t); return null; }
}

/** POST /auth/setup ou /auth/login — renvoie { ok } ou { error }. */
export async function authSubmit(mode, password){
  try {
    const r = await fetch(API_BASE + '/auth/' + (mode === 'setup' ? 'setup' : 'login'), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ password })
    });
    return await r.json();
  } catch (e) { return { error: 'API injoignable — réessayez.' }; }
}

export function authLogout(){
  return fetch(API_BASE + '/auth/logout', { method: 'POST', credentials: 'same-origin' }).catch(() => null);
}

export async function load(opts){
  const timeoutMs = (opts && opts.timeoutMs) || 4000;
  const ctl = new AbortController();
  const t = setTimeout(() => ctl.abort(), timeoutMs);
  try {
    const keys = Object.keys(ENDPOINTS);
    const res = await Promise.all(keys.map(k => get(ENDPOINTS[k], ctl.signal)));
    clearTimeout(t);
    const p = {}; keys.forEach((k, i) => { p[k] = res[i]; });
    return shape(p, 'api');
  } catch (e) {
    clearTimeout(t);
    console.info('[cockpit] API indisponible (' + e.message + ') — affichage vide (aucune donnée de démonstration).');
    return shape(emptyPayload(), 'offline');
  }
}

/** Écriture non bloquante : ignorée en mode démo, best-effort en mode API. */
export function write(source, method, path, payload){
  if (source !== 'api') return Promise.resolve(null);
  return fetch(API_BASE + path, {
    method, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: payload === undefined ? undefined : JSON.stringify(payload)
  }).catch(e => console.warn('[cockpit] écriture ' + path + ' échouée :', e.message));
}

/* Normalisation : une seule forme consommée par le composant. */
function shape(p, source){
  const meta = p.meta;
  return {
    source, meta,
    M: { MOIS: meta.moisLabels, TODAY: meta.aujourdhui, LEVIERS: p.leviers, KPI_LIST: p.kpis.map(k => k.nom || k),
      FAMILLES: p.familles || meta.familles, REPORT_TYPES: p.reportTypes || meta.reportTypes,
      // Niveaux, seuil et référentiel du signalement — réglage serveur,
      // jamais une constante d'écran. `ensureValidation()` garantit qu'il
      // existe : le repli ne sert qu'à un serveur injoignable.
      SIGNAL: meta.signalement || { seuil: 4, niveaux: [], familles: [] } },
    D: Object.assign({}, p.raw || {}, {
      stores: joinPerf(p.stores, p.perf, meta && meta.exercice),
      budgets: p.budgets,
      targets: p.targets,
      consultants: p.consultants,
      suppliers: p.suppliers,
      projects: p.projects,
      projTemplates: p.projTemplates,
      emailTemplates: p.emailTemplates,
      crm: p.crm,
      people: p.people,
      reports: (p.reporting || {}).reports,
      alertRules: (p.reporting || {}).alertRules,
      logs: p.journal,
      products: p.products,
      pwaReports: p.pwaReports
    })
  };
}

/* /stores/perf renvoie des lignes plates (storeId, annee, mois, …) — le
   composant lit store.perf[annee][mois][champ]. Les vraies données du panel
   sont CREUSES (tous les magasins/mois ne sont pas encodés) ; on densifie donc
   en tableaux de 12 mois par année affichée, chaque cellule étant un objet aux
   champs à null. Sans ça, `perf[annee]` ou `perf[annee][mois]` est indéfini et
   les écrans Tableau/Heatmap/Marge plantent. */
function joinPerf(stores, perf, exercice){
  perf = perf || [];
  const yEx = exercice || new Date().getFullYear();
  const years = new Set(perf.map(r => r.annee));
  years.add(yEx); years.add(yEx - 1);
  const cell = () => ({ ca: null, caT: null, marge: null, mp: null, tickets: null,
    panier: null, food: null, labour: null, overhead: null, val: null });
  const by = {};
  for (const r of perf){
    const s = (by[r.storeId] = by[r.storeId] || {});
    const y = (s[r.annee] = s[r.annee] || Array.from({ length: 12 }, cell));
    y[r.mois - 1] = { ca: r.ca, caT: r.caBudget, marge: r.margeNette, mp: r.margePct, tickets: r.tickets,
      panier: r.panierMoyen, food: r.foodCostPct, labour: r.labourCostPct, overhead: r.overheadPct, val: r.valorisation };
  }
  return stores.map(s => {
    const per = by[s.id] || {};
    for (const y of years) if (!per[y]) per[y] = Array.from({ length: 12 }, cell);
    return Object.assign({}, s, { perf: per });
  });
}

/* --- Repli hors-ligne : structure vide (aucune donnée fictive) ------------
   Affiché uniquement si l'API est injoignable. Aucune donnée de démonstration
   n'est embarquée : le cockpit ne montre que les vraies données (ou rien). */
function emptyPayload(){
  const MOIS = ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'];
  const meta = {
    reseau: { nom: '', sousTitre: '' },
    utilisateur: { initiales: '', nom: '', role: '' },
    aujourdhui: null, dateLabel: '', periodeLabel: '',
    exercice: new Date().getFullYear(), moisLabels: MOIS,
    seuils: { food: 32, labour: 33, overhead: 13.5, royalties: 3, financieres: 2.2, caEtp: 13000 },
    contribOuverture: 0, notes: {}, familles: [], reportTypes: []
  };
  return {
    raw: {}, meta, leviers: [], kpis: [], familles: [], reportTypes: [],
    emailTemplates: [], projTemplates: [], stores: [], perf: null, budgets: [],
    targets: [], consultants: [], suppliers: [], projects: [], crm: [], people: [],
    reporting: { reports: [], alertRules: [] }, journal: [], products: [],
    pwaReports: { base: '', magasins: [], partages: [] }
  };
}
