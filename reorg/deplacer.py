#!/usr/bin/env python3
"""
Étape 2 de la réorganisation : déplace les fichiers selon reorg/fichiers.tsv et recalcule les chemins PHP
`__DIR__ . '…'` (et `__DIR__ . "…"`) de TOUS les fichiers du dépôt vers leur nouvel emplacement.

  python3 -I reorg/deplacer.py --essai     ce qui serait fait, sans rien toucher
  python3 -I reorg/deplacer.py             déplace (os.renames, puis `git add -A` par l'appelant) et réécrit

Règles :
- un chemin vers un fichier connu suit ce fichier ;
- un chemin vers un dossier suit ce dossier s'il a gardé son unité (tous ses fichiers au même nouvel endroit) ;
- `public/**/uploads` (fichiers déposés, hors dépôt) → `public/**/uploads` à la RACINE : le site assemblé du serveur
  (TARGET_DIR/public) et du poste de développement garde ses dépôts au même endroit ;
- `config/config.php` → `shared/config/config.php` ; `var/` (dossier privé local) → `var/` à la racine ;
- le reste est signalé (« à la main ») et laissé tel quel.
"""
import csv, os, re, sys

R = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PR = 'pilotage-reseau/'
ESSAI = '--essai' in sys.argv

M = {}
for r in csv.DictReader(open(os.path.join(R, 'reorg/fichiers.tsv'), encoding='utf-8'), delimiter='\t'):
    M[r['ancien']] = r['nouveau']
# Ajustements du plan (§2) : le .gitignore du cockpit est fondu dans celui de la racine (à la main).
M[PR + '.gitignore'] = PR + '.gitignore'

# Unité des dossiers : ancien dossier → nouveau dossier, si tous ses fichiers ont suivi le même décalage.
dossiers = {}
for a, n in M.items():
    pa, pn = a.split('/'), n.split('/')
    for i in range(1, len(pa)):
        d = '/'.join(pa[:i])
        suffixe = pa[i:]
        if len(pn) >= len(suffixe) and pn[len(pn) - len(suffixe):] == suffixe:
            nd = '/'.join(pn[:len(pn) - len(suffixe)])
        else:
            nd = None
        dossiers.setdefault(d, set()).add(nd)
DMAP = {d: next(iter(s)) for d, s in dossiers.items() if len(s) == 1 and None not in s}

SPECIAUX = [
    (re.compile(r'^pilotage-reseau/public/(.*/)?uploads(/.*)?$'), lambda m: 'public/' + (m.group(1) or '') + 'uploads' + (m.group(2) or '')),
    (re.compile(r'^pilotage-reseau/config/config\.php$'), lambda m: 'shared/config/config.php'),
    # La racine du site (une adresse convertie en fichier) : le site assemblé, à la racine (shared/bin/assembler_public.sh).
    (re.compile(r'^pilotage-reseau/public$'), lambda m: 'public'),
    (re.compile(r'^pilotage-reseau/var(/.*)?$'), lambda m: 'var' + (m.group(1) or '')),
    (re.compile(r'^pilotage-reseau/node_modules(/.*)?$'), lambda m: 'node_modules' + (m.group(1) or '')),
]


def nouvelle_cible(cible):
    """Nouveau chemin (relatif à la racine) d'une ancienne cible, ou None."""
    for rx, f in SPECIAUX:
        m = rx.match(cible)
        if m:
            return f(m)
    if cible in M:
        return M[cible]
    if cible in DMAP:
        return DMAP[cible]
    # Fichier ou dossier absent du dépôt (créé à l'exécution) : son dossier parent, s'il a gardé son unité.
    parent, nom = os.path.split(cible)
    while parent and parent not in DMAP and parent not in dossiers:
        parent, n2 = os.path.split(parent)
        nom = n2 + '/' + nom
    if parent in DMAP:
        return DMAP[parent] + '/' + nom
    return None


RX = re.compile(r"""__DIR__(\s*\.\s*)(['"])(/[^'"$]*)\2""")
GLOB = set('*?[')

def reecrire(texte, ancien, nouveau, rapport):
    def sub(m):
        lit = m.group(3)
        if not lit.startswith('/') or lit == '/':
            return m.group(0)   # « __DIR__ . '/' » : le dossier lui-même, rien à recalculer
        brut = lit
        motif = ''
        # Un motif de glob en fin de chemin (« /*.php ») : on déplace le dossier, on garde le motif.
        base = brut
        if any(c in base for c in GLOB):
            i = base.rfind('/', 0, min(base.find(c) for c in GLOB if c in base))
            base, motif = base[:i] or '/', base[i:]
        barre = base.endswith('/') and base != '/'
        cible = os.path.normpath(os.path.join(os.path.dirname(ancien), base.lstrip('/')))
        if cible.startswith('..'):
            rapport.append(f'{nouveau}: hors du dépôt : {lit}')
            return m.group(0)
        nc = nouvelle_cible(cible)
        if nc is None:
            rapport.append(f'{nouveau}: à la main : {lit} (cible {cible})')
            return m.group(0)
        rel = os.path.relpath(nc, os.path.dirname(nouveau))
        res = '/' + rel + ('/' if barre else '') + motif
        if os.path.normpath(os.path.join(os.path.dirname(nouveau), rel)) != os.path.normpath(nc):
            rapport.append(f'{nouveau}: incohérent {lit}')
        return '__DIR__' + m.group(1) + m.group(2) + res + m.group(2)
    return RX.sub(sub, texte)


def main():
    rapport = []
    deplaces = [(a, n) for a, n in M.items() if a != n]
    # Les réécritures se calculent avec les anciens chemins, puis les fichiers sont déplacés.
    a_ecrire = {}
    for a, n in M.items():
        if not a.endswith('.php') and not a.endswith('.phtml'):
            continue
        if a.startswith(PR + 'apps/production/') or '/vendor/' in a:
            continue
        p = os.path.join(R, a)
        try:
            t = open(p, encoding='utf-8').read()
        except (OSError, UnicodeDecodeError):
            continue
        t2 = reecrire(t, a, n, rapport)
        if t2 != t:
            a_ecrire[a] = t2
    print(f'{len(deplaces)} fichiers à déplacer ; {len(a_ecrire)} fichiers PHP aux chemins réécrits ; {len(rapport)} cas signalés')
    for l in rapport:
        print('  ', l)
    if ESSAI:
        return
    for a, t in a_ecrire.items():
        open(os.path.join(R, a), 'w', encoding='utf-8').write(t)
    for a, n in deplaces:
        os.renames(os.path.join(R, a), os.path.join(R, n))
    print('fait')


if __name__ == '__main__':
    main()
