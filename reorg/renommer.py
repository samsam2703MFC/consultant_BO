#!/usr/bin/env python3
"""
Étapes 3 à 6 de la réorganisation : renommer les tables d'un domaine.

  python3 -I reorg/renommer.py FOR 002 --essai     ce qui serait fait (références par fichier, écrans à vérifier)
  python3 -I reorg/renommer.py FOR 002             registre + migration + remplacement des références

1. shared/src/reorg_tables.php : le registre ancien → nouveau, par domaine (lu par les migrations, par la remise à
   zéro de bin/deploy.sh et de shared/bin/db_admin.php, et par les tests « aucun ancien nom ne reste ») ;
2. shared/db/migrations/NNN_<domaine>_tables.php : up = migrationRenommer, down = migrationRendre ;
3. chaque nom, mot entier, remplacé dans les fichiers suivis par Git — sauf ce qui doit garder l'ancien nom :
   le registre et les migrations, reorg/, REORG_*.md, docs/PORTAILS.md (réécrit à l'étape 8), les maquettes
   (*/docs/maquettes : archives de conception), la copie de l'ERP et les dépendances (vendor/).
"""
import csv, os, re, subprocess, sys

R = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
NOMS = {'FOR': ('formation', 'Formation'), 'MAR': ('marketing', 'Marketing'), 'PANEL': ('panel', 'Dashboard franchisé'),
        'CON': ('consultant', 'Pilotage consultant')}
REGISTRE = 'shared/src/reorg_tables.php'
EXCLUS = re.compile(r'^(reorg/|REORG_|docs/PORTAILS\.md$|shared/src/reorg_tables\.php$|shared/db/migrations/|[^/]+/docs/maquettes/|'
                    r'pilotage-reseau/apps/production/|.*/vendor/|panel/public/tablette/|marketing/public/assistant/assets/)')
TEXTE = re.compile(r'\.(php|sql|sh|js|mjs|ts|tsx|py|md|json|html|css|yml|yaml|txt|twig|csv)$')


def paires(dom):
    out = []
    for r in csv.DictReader(open(os.path.join(R, 'reorg/tables.tsv'), encoding='utf-8'), delimiter='\t'):
        if r['domaine'] == dom and r['nouveau'] != '=':
            out.append((r['table'], r['nouveau']))
    return out


def fichiers():
    l = subprocess.run(['git', 'ls-files'], cwd=R, capture_output=True, text=True, check=True).stdout.splitlines()
    return [f for f in l if TEXTE.search(f) and not EXCLUS.match(f)]


def registre(dom, ps):
    p = os.path.join(R, REGISTRE)
    if os.path.exists(p):
        s = open(p, encoding='utf-8').read()
    else:
        s = '''<?php
declare(strict_types=1);

/**
 * Le registre des renommages de la réorganisation en quatre domaines (19/11/2026) : ancien nom → nouveau nom, par
 * domaine. Les migrations shared/db/migrations/00N_*_tables.php le lisent ; la remise à zéro (bin/deploy.sh,
 * shared/bin/db_admin.php) y retrouve les tables qui s'appelaient ceo_* ; les tests vérifient qu'aucun ancien nom ne
 * reste dans le code. Les anciens noms ne vivent plus qu'ici et dans les migrations.
 */
const REORG_TABLES = [
];

/** Tous les renommages, tous domaines : [ancien => nouveau]. */
function reorgTables(): array
{
    return array_merge(...array_values(REORG_TABLES));
}
'''
    if f"    '{dom}' => [" in s:
        raise SystemExit(f'{dom} déjà dans le registre')
    bloc = f"    '{dom}' => [   // {NOMS[dom][1]}\n" + ''.join(f"        '{a}' => '{n}',\n" for a, n in ps) + "    ],\n"
    s = s.replace("const REORG_TABLES = [\n", "const REORG_TABLES = [\n" + bloc, 1) if "const REORG_TABLES = [\n];" in s \
        else s.replace("\n];\n\n/** Tous les renommages", "\n" + bloc.rstrip('\n') + "\n];\n\n/** Tous les renommages", 1)
    return s


def migration(dom, num, ps):
    prefixe = {'FOR': 'for_', 'MAR': 'mar_', 'PANEL': 'panel_', 'CON': 'con_'}[dom]
    return f'''<?php
declare(strict_types=1);

// Réorganisation : les {len(ps)} tables du domaine {NOMS[dom][1]} prennent le préfixe {prefixe} (registre :
// shared/src/reorg_tables.php). Un seul RENAME TABLE, données gardées, lignes comptées avant et après.
require_once __DIR__ . '/../../src/reorg_tables.php';

return [
    'quoi' => '{NOMS[dom][1]} : {len(ps)} tables → {prefixe}',
    'up' => static fn (): array => migrationRenommer(REORG_TABLES['{dom}']),
    'down' => static fn (): array => migrationRendre(REORG_TABLES['{dom}']),
];
'''


def main():
    dom, num = sys.argv[1], sys.argv[2]
    essai = '--essai' in sys.argv
    ps = paires(dom)
    rx = re.compile(r'(?<![A-Za-z0-9_])(' + '|'.join(re.escape(a) for a, _ in sorted(ps, key=lambda x: -len(x[0]))) + r')(?![A-Za-z0-9_])')
    carte = dict(ps)
    total = 0
    ecrans = []
    changes = {}
    for f in fichiers():
        p = os.path.join(R, f)
        try:
            t = open(p, encoding='utf-8').read()
        except (UnicodeDecodeError, OSError):
            continue
        n = len(rx.findall(t))
        if not n:
            continue
        total += n
        changes[f] = rx.sub(lambda m: carte[m.group(1)], t)
        if re.search(r'\.(js|mjs|ts|tsx|css|html|json)$', f):
            ecrans.append((f, sorted(set(rx.findall(t)))))
    print(f'{dom} : {len(ps)} tables, {total} occurrence(s) dans {len(changes)} fichier(s)')
    for f in sorted(changes):
        print('  ', f)
    if ecrans:
        print('Fichiers d\'écran / données (à vérifier) :')
        for f, noms in ecrans:
            print('  ', f, noms)
    if essai:
        return
    for f, t in changes.items():
        open(os.path.join(R, f), 'w', encoding='utf-8').write(t)
    contenu = registre(dom, ps)   # lu AVANT d'ouvrir le fichier en écriture
    open(os.path.join(R, REGISTRE), 'w', encoding='utf-8').write(contenu)
    m = os.path.join(R, f'shared/db/migrations/{num}_{NOMS[dom][0]}_tables.php')
    open(m, 'w', encoding='utf-8').write(migration(dom, num, ps))
    print('registre et migration écrits :', REGISTRE, os.path.relpath(m, R))


if __name__ == '__main__':
    main()
