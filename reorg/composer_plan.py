#!/usr/bin/env python3
"""Compose REORG_PLAN.md à partir de reorg/tables.tsv, reorg/fichiers.tsv et reorg/cles.tsv (lecture seule)."""
import collections, csv, os

R = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
T = list(csv.DictReader(open(os.path.join(R, 'reorg/tables.tsv'), encoding='utf-8'), delimiter='\t'))
F = list(csv.DictReader(open(os.path.join(R, 'reorg/fichiers.tsv'), encoding='utf-8'), delimiter='\t'))
DOMF = {f['ancien'].replace('pilotage-reseau/', ''): f['domaine'] for f in F}
NOM = {'MAR': 'Marketing', 'FOR': 'Formation', 'CON': 'Pilotage consultant', 'PANEL': 'Dashboard franchisé',
       'SHARED': 'Partagé', 'EXT': 'Autre application', 'HORS': 'Hors périmètre', 'RACINE': 'Racine du dépôt'}


def e(x):
    return str(x).replace('|', '\\|')


def tableau_renommages():
    L = []
    for d in ('FOR', 'MAR', 'PANEL', 'CON'):
        rows = [t for t in T if t['domaine'] == d and t['nouveau'] != '=']
        L.append(f'\n#### {NOM[d]} — {len(rows)} renommage(s)\n')
        L.append('| Ancien nom | Nouveau nom | Domaine | Fichiers de code à mettre à jour | Note |')
        L.append('|---|---|---|---:|---|')
        for t in rows:
            n = sum(int(t[k]) for k in ('n_code', 'n_front', 'n_script', 'n_test', 'n_sql'))
            L.append(f"| `{t['table']}` | `{t['nouveau']}` | {NOM[d]} | {n} | {e(t['note'])} |")
    return '\n'.join(L)


def liste_inchangees(d):
    rows = [t['table'] for t in T if t['domaine'] == d and t['nouveau'] == '=']
    return ', '.join(f'`{x}`' for x in rows) or '—'


def tableau_fichiers():
    agg = collections.defaultdict(lambda: [0, set()])
    for f in F:
        a, n = f['ancien'], f['nouveau']
        pa = a.split('/')
        if not a.startswith('pilotage-reseau/'):
            continue
        sub = pa[1]
        if sub == 'src' and len(pa) == 3:
            ka = a
        elif sub == 'src' and pa[2] == 'franchise':
            ka = 'pilotage-reseau/src/franchise/'
        elif sub == 'src':
            ka = '/'.join(pa[:3]) + '/'
        elif sub == 'public' and len(pa) > 3 and pa[2] == 'assets' and pa[3] == 'js':
            ka = a
        elif sub == 'public' and len(pa) > 3 and pa[2] in ('assets', 'api'):
            ka = '/'.join(pa[:4]) + ('/' if len(pa) > 4 else '')
        elif sub in ('public', 'tests', 'tools', 'data', 'sql', 'config', 'scripts', 'maquette-screens') and len(pa) > 3:
            ka = '/'.join(pa[:3]) + '/'
        elif sub == 'bin' or (sub in ('public', 'tools', 'tests', 'sql', 'config', 'scripts', 'data') and len(pa) == 3):
            ka = a
        elif sub == 'docs' and len(pa) > 3 and pa[2] == 'maquettes':
            ka = '/'.join(pa[:4]) + '/'
        elif sub == 'docs' and len(pa) > 3:
            ka = '/'.join(pa[:3]) + '/'
        elif sub == 'apps':
            ka = '/'.join(pa[:3]) + '/'
        else:
            ka = a
        if ka.endswith('/'):
            kn = n[:len(n) - len(a) + len(ka)] if n.endswith(a[len(ka):]) else n
        else:
            kn = n
        agg[(ka, kn, f['domaine'])][0] += 1
    L = ['| Ancien chemin | Nouveau chemin | Fichiers | Domaine |', '|---|---|---:|---|']
    for (ka, kn, d), (n, _) in sorted(agg.items()):
        L.append(f'| `{e(ka)}` | {"`" + e(kn) + "`" if kn != ka else "(reste en place)"} | {n} | {NOM[d]} |')
    return '\n'.join(L)


def a_supprimer():
    L = ['| Table | Lignes | FK venant d’une table conservée | Références restantes (4 domaines + shared) | Pourquoi |',
         '|---|---|---|---|---|']
    for t in T:
        if t['domaine'] != 'HORS':
            continue
        refs = [x for x in t['fichiers'].split() if DOMF.get(x) not in ('HORS', None)]
        L.append(f"| `{t['table']}` | {t['lignes']} | {t['fk_entrantes'] or 'aucune dans le code (à confirmer en base)'} | "
                 f"{', '.join('`' + x + '`' for x in refs) or 'aucune'} | {e(t['note'])} |")
    return '\n'.join(L)


def externes():
    g = collections.defaultdict(list)
    for t in T:
        if t['domaine'] == 'EXT':
            g[t['note']].append(t['table'])
    return '\n'.join(f'- **{k}** ({len(v)}) : ' + ', '.join(f'`{x}`' for x in v) for k, v in g.items())


def lus_par_autre():
    L = []
    for t in T:
        if t['domaine'] not in ('MAR', 'FOR', 'CON', 'PANEL'):
            continue
        autres = [d for d in t['domaines_lecteurs'].split() if d not in (t['domaine'], 'SHARED', 'RACINE', 'HORS')]
        if autres:
            nv = t['nouveau'] if t['nouveau'] != '=' else t['table']
            L.append(f"`{t['table']}` → `{nv}` ({NOM[t['domaine']]}) lue par {', '.join(NOM[a] for a in autres)}")
    return '\n'.join('- ' + x for x in L)


PLAN = open(os.path.join(R, 'reorg/plan_gabarit.md'), encoding='utf-8').read()
c = collections.Counter(t['domaine'] for t in T)
ren = collections.Counter(t['domaine'] for t in T if t['nouveau'] != '=')
PLAN = (PLAN.replace('{{RENOMMAGES}}', tableau_renommages())
        .replace('{{FICHIERS}}', tableau_fichiers())
        .replace('{{A_SUPPRIMER}}', a_supprimer())
        .replace('{{EXTERNES}}', externes())
        .replace('{{LUS_PAR_AUTRE}}', lus_par_autre())
        .replace('{{INCH_MAR}}', liste_inchangees('MAR'))
        .replace('{{INCH_CON}}', liste_inchangees('CON'))
        .replace('{{INCH_SHARED}}', liste_inchangees('SHARED'))
        .replace('{{N_REN}}', str(sum(ren.values())))
        .replace('{{N_REN_FOR}}', str(ren['FOR'])).replace('{{N_REN_MAR}}', str(ren['MAR']))
        .replace('{{N_REN_PANEL}}', str(ren['PANEL'])).replace('{{N_REN_CON}}', str(ren['CON']))
        .replace('{{N_PROPRES}}', str(sum(v for k, v in c.items() if k != 'EXT')))
        .replace('{{N_EXT}}', str(c['EXT'])))
open(os.path.join(R, 'REORG_PLAN.md'), 'w', encoding='utf-8').write(PLAN)
print('REORG_PLAN.md écrit', len(PLAN.splitlines()), 'lignes')
