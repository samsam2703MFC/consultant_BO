#!/usr/bin/env python3
"""
Étape 8 : les chemins de fichiers cités dans la documentation vivante (.md) suivent leur fichier.

Un chemin entre accents graves (`src/sop.php`, `public/dashboard/`, `bin/deploy.sh`…), écrit comme avant depuis
pilotage-reseau/, devient son chemin depuis la racine du dépôt (`formation/src/sop.php`…), d'après
reorg/fichiers.tsv (et `src/franchise/x.php` → `panel/src/x.php`). Les maquettes, REORG_*.md, reorg/ et
docs/PORTAILS.md (réécrit à la main) ne sont pas touchés.

  python3 -I reorg/docs_chemins.py [--essai]
"""
import csv, os, re, subprocess, sys

R = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PR = 'pilotage-reseau/'
ESSAI = '--essai' in sys.argv
M = {r['ancien']: r['nouveau'] for r in csv.DictReader(open(os.path.join(R, 'reorg/fichiers.tsv'), encoding='utf-8'), delimiter='\t')}
M[PR + 'bin/tests_noms_globaux.php'] = 'shared/bin/tests_noms_globaux.php'   # déplacé à la main à l'étape 2
# Dossiers qui ont gardé leur unité
dossiers = {}
for a, n in M.items():
    pa, pn = a.split('/'), n.split('/')
    for i in range(1, len(pa)):
        d = '/'.join(pa[:i]); suf = pa[i:]
        nd = '/'.join(pn[:len(pn) - len(suf)]) if pn[len(pn) - len(suf):] == suf else None
        dossiers.setdefault(d, set()).add(nd)
DMAP = {d: next(iter(s)) for d, s in dossiers.items() if len(s) == 1 and None not in s}
EXCLUS = re.compile(r'^(reorg/|REORG_|docs/PORTAILS\.md$|[^/]+/docs/maquettes/|pilotage-reseau/apps/production/|.*/vendor/)')
RX = re.compile(r'`((?:src|public|bin|sql|docs|tools|tests|data|config|apps|scripts|maquette-screens)/[^`\s]*)`')


def traduire(chemin):
    c = chemin.rstrip('/')
    barre = chemin.endswith('/') and c != ''
    base = PR + c
    glob = ''
    if '*' in c:
        i = c.find('*'); j = c.rfind('/', 0, i)
        base, glob = PR + c[:j], c[j:]
    nv = M.get(base) or DMAP.get(base)
    if nv is None and base.startswith(PR + 'src/franchise/'):
        nv = M.get(base) or ('panel/src/' + base[len(PR + 'src/franchise/'):])
    if nv is None:
        # Les dossiers qui ont suivi en bloc, même pour un fichier qui n'existe pas (ou plus) dans le dépôt.
        for avant, apres in (('data/', 'formation/data/'), ('apps/employee/', 'panel/apps/employee/'), ('config/config', 'shared/config/config')):
            if c.startswith(avant):
                return apres + c[len(avant):] + glob + ('/' if barre else '')
        return None
    return nv + glob + ('/' if barre else '')


def main():
    l = subprocess.run(['git', 'ls-files', '*.md'], cwd=R, capture_output=True, text=True, check=True).stdout.splitlines()
    total, fichiers, inconnus = 0, 0, set()
    for f in l:
        if EXCLUS.match(f):
            continue
        p = os.path.join(R, f)
        t = open(p, encoding='utf-8').read()
        n = [0]
        def sub(m):
            nv = traduire(m.group(1))
            if nv is None or nv == m.group(1):
                if nv is None:
                    inconnus.add(m.group(1))
                return m.group(0)
            n[0] += 1
            return '`' + nv + '`'
        t2 = RX.sub(sub, t)
        if n[0]:
            total += n[0]; fichiers += 1
            if not ESSAI:
                open(p, 'w', encoding='utf-8').write(t2)
    print(f'{total} chemins mis à jour dans {fichiers} fichiers' + (' (essai)' if ESSAI else ''))
    print(f'{len(inconnus)} chemins non reconnus (laissés tels quels), par ex. :', sorted(inconnus)[:25])


if __name__ == '__main__':
    main()
