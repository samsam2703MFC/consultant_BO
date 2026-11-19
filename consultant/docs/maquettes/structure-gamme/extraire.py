#!/usr/bin/env python3
"""Données de la maquette « Structure de gamme » — lues en ligne, en GET seulement.

    python3 docs/maquettes/structure-gamme/extraire.py              # lit l'API du cockpit en ligne
    python3 docs/maquettes/structure-gamme/extraire.py --cache DIR  # relit des réponses déjà enregistrées

Quatre lectures, aucune écriture :
  GET /analyse/produits?mois=12  ventes réseau mois par mois, par magasin (400 premières références en pièces)
  GET /production/catalogue      les références actives : catégorie, groupe, prix, coût matière, commission, gammes
  GET /products/scoring          coût unitaire des références hors catalogue, volume du dernier mois
  GET /production/saisons        les gammes saisonnières du panel (pour mémoire)

Ce que le script fait des données, et rien d'autre :
  - le profil de chaque référence : ses pièces mois par mois, tous magasins confondus ;
  - la marge nette sur 12 mois : CA - commission - pièces x coût matière (null sans coût fiable) ;
  - les changements d'identifiant : une référence retirée du catalogue dont une référence active
    du même groupe porte le même nom (au pluriel et aux accents près) lui transmet son historique.
    En octobre 2026, les tartes de la catégorie « Tartes » sont passées sous « Tartes – Ø 28 cm »
    avec de nouveaux identifiants : sans cette fusion, l'ancienne paraît arrêtée et la nouvelle
    paraît lancée en octobre ;
  - les doublons : deux références ACTIVES du même groupe au même nom sont signalées, pas fusionnées.
Les règles de la structure (80 %, 6, 4, 3 %) ne sont PAS ici : elles vivent dans le moteur du cockpit,
public/assets/js/structure-regles.js. Le cockpit assemble les mêmes données côté serveur
(src/structure_gamme.php, GET /produits/structure) ; ce script sert la maquette hors ligne.
"""
import difflib, json, os, re, sys, unicodedata, urllib.request
from datetime import date

BASE = 'http://185.180.206.46/consulant_bo/api/cockpit'
ICI = os.path.dirname(os.path.abspath(__file__))
LECTURES = {'ap12': '/analyse/produits?mois=12', 'catalogue': '/production/catalogue',
            'scoring': '/products/scoring', 'saisons': '/production/saisons'}


def lire(cle, cache):
    if cache:
        with open(os.path.join(cache, cle + '.json'), encoding='utf-8') as f:
            return json.load(f)
    with urllib.request.urlopen(BASE + LECTURES[cle], timeout=280) as r:
        return json.load(r)


def norme(nom):
    """Le nom ramené à ses mots : sans accents, sans pluriel, dans l'ordre alphabétique."""
    s = unicodedata.normalize('NFD', nom.lower())
    s = ''.join(ch for ch in s if unicodedata.category(ch) != 'Mn')
    mots = [m[:-1] if len(m) > 3 and m.endswith('s') else m for m in re.findall(r'[a-z0-9]+', s)]
    return ' '.join(sorted(m for m in mots if m not in ('de', 'du', 'la', 'le', 'les', 'au', 'aux', 'et')))


def main():
    cache = sys.argv[sys.argv.index('--cache') + 1] if '--cache' in sys.argv else None
    ap, cat, sco, sai = (lire(k, cache) for k in ('ap12', 'catalogue', 'scoring', 'saisons'))

    mois = ap['tranches']                      # « Déc 2025 » … « Nov 2026 » (le dernier est en cours)
    n = len(mois)
    ventes = {}                                # pid → [pièces par mois], [ca], nom, catégorie
    total = [0.0] * n                          # toutes références lues, mois par mois : la base du profil
    for p in ap['produits']:
        u = [0.0] * n
        for serie in p['parShop'].values():
            for i, v in enumerate(serie):
                u[i] += v or 0
        for i in range(n):
            total[i] += u[i]
        ventes[int(p['pid'])] = {'u': u, 'ca': float(p['ca']), 'nom': p['nom'], 'cat': p['cat']}
    plancher = min(p['total'] for p in ap['produits'])   # sous ce volume, une référence n'est pas lue

    sc = {int(s['id']): s for s in sco}
    actifs = {int(p['pwaId']): p for p in cat if p.get('pwaId')}
    grp_cat = {}                               # catégorie → groupe, d'après le catalogue
    for p in cat:
        grp_cat.setdefault(p['categorie'], p['groupe'])

    # Les changements d'identifiant : une référence retirée transmet son historique à l'active du même nom.
    par_nom = {}
    for pid, p in actifs.items():
        par_nom.setdefault((p['groupe'], norme(p['nom'])), []).append(pid)
    heritage = {}                              # pid actif → [pid retirés]
    orphelins = []
    for pid, v in ventes.items():
        if pid in actifs:
            continue
        g = grp_cat.get(v['cat'])
        cible = par_nom.get((g, norme(v['nom'])), [])
        if len(cible) == 1:
            heritage.setdefault(cible[0], []).append(pid)
        else:
            orphelins.append(pid)

    # Deuxième passe, à l'orthographe près (« Flan Brézillien » → « Flan Brésilienne ») : seulement vers une
    # référence active du même groupe, sans historique avant juin, qui n'a pas déjà hérité.
    def recente(pid):
        u = ventes.get(pid, {}).get('u')
        return u is None or next((i for i, x in enumerate(u) if x > 0), n) >= 6
    for pid in orphelins:
        v = ventes[pid]
        g = grp_cat.get(v['cat'])
        cands = [(difflib.SequenceMatcher(None, norme(v['nom']), norme(p['nom'])).ratio(), q)
                 for q, p in actifs.items() if p['groupe'] == g and q not in heritage and recente(q)]
        cands.sort(reverse=True)
        if cands and cands[0][0] >= 0.8 and (len(cands) == 1 or cands[1][0] < cands[0][0] - 0.05):
            heritage.setdefault(cands[0][1], []).append(pid)

    def marge(ca, pieces, prix, mat, com):
        if mat is None or ca is None:
            return None
        taux = (com / prix) if (com and prix) else 0.04
        return round(ca * (1 - taux) - pieces * mat, 0)

    produits, fusionnes = [], set()
    for pid, p in actifs.items():
        u, ca = None, None
        anciens = []
        if pid in ventes:
            u, ca = list(ventes[pid]['u']), ventes[pid]['ca']
        for vieux in heritage.get(pid, []):
            v = ventes[vieux]
            u = [a + b for a, b in zip(u or [0.0] * n, v['u'])]
            ca = (ca or 0) + v['ca']
            anciens.append({'id': vieux, 'cat': v['cat']})
            fusionnes.add(vieux)
        mat = p.get('mat') if p.get('matFiable') else None
        produits.append({
            'id': pid, 'nom': p['nom'].strip(), 'cat': p['categorie'].strip(), 'grp': p['groupe'],
            'actif': 1, 'prix': p.get('prix'), 'mat': mat,
            'u': [round(x) for x in u] if u else None, 'ca': round(ca) if ca is not None else None,
            'mg': marge(ca, sum(u) if u else 0, p.get('prix'), mat, p.get('commission')) if u else None,
            'gammes': [re.sub(r'^[^\wÀ-ÿ]+', '', g).strip() for g in (p.get('periods') or [])],
            'must': bool(p.get('must')), 'anciens': anciens,
            'v1': (sc.get(pid) or {}).get('volume') if u is None else None})
    # Les références retirées (non reprises) : elles gardent leur place dans l'historique de leur catégorie,
    # la structure décide de ce qu'elle en fait (saisonnière à réactiver, ou arrêtée).
    for pid, v in ventes.items():
        if pid in actifs or pid in fusionnes:
            continue
        s = sc.get(pid) or {}
        mat = s.get('coutUnit')
        produits.append({
            'id': pid, 'nom': v['nom'].strip(), 'cat': v['cat'].strip(), 'grp': grp_cat.get(v['cat'], '—'),
            'actif': 0, 'prix': s.get('prix'), 'mat': mat, 'u': [round(x) for x in v['u']], 'ca': round(v['ca']),
            'mg': marge(v['ca'], sum(v['u']), s.get('prix'), mat, None), 'gammes': [], 'must': False,
            'anciens': [], 'v1': None})

    # Les doublons : même groupe, même nom, deux références actives.
    vus = {}
    for p in produits:
        if p['actif']:
            vus.setdefault((p['grp'], norme(p['nom'])), []).append(p)
    for l in vus.values():
        if len(l) > 1:
            for p in l:
                p['doublon'] = [{'id': q['id'], 'cat': q['cat']} for q in l if q is not p]

    out = {
        'lu': date.today().isoformat(),
        'source': 'API du cockpit en ligne (GET) : ' + ', '.join(LECTURES.values()),
        'mois': mois, 'bornes': ap['bornes'], 'jours': ap['jours'],
        'magasins': [m['nom'] for m in ap['magasins']],
        'total': [round(x) for x in total], 'plancher': plancher,
        'saisonsPanel': [{'nom': s['nom'], 'debut': s['debut'], 'fin': s['fin'], 'n': len(s['produits'])}
                         for s in sai.get('saisons', [])],
        'produits': sorted(produits, key=lambda p: (p['grp'], p['cat'], -(p['ca'] or 0))),
    }
    with open(os.path.join(ICI, 'donnees.js'), 'w', encoding='utf-8') as f:
        f.write('/* Données réelles lues en ligne le ' + out['lu'] + ' (GET, aucune écriture) — générées par '
                'extraire.py. Ventes réseau 12 mois, catalogue actif, coûts matière. */\n')
        f.write('window.SG = ' + json.dumps(out, ensure_ascii=False, separators=(',', ':')) + ';\n')
    print('%d références (%d actives, %d retirées), %d historiques repris, plancher %d pièces'
          % (len(produits), sum(p['actif'] for p in produits), sum(1 - p['actif'] for p in produits),
             len(fusionnes), plancher))


if __name__ == '__main__':
    main()
