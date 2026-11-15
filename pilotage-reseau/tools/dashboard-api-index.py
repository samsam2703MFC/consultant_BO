#!/usr/bin/env python3
"""L'index des API du cockpit franchisé (15/11/2026, demande : « un index des API en modale, via un bouton API dans le
header, pour vérifier quels sont les appels »).

Lit le code, rien d'autre — pas de liste tenue à la main qui vieillirait :
  - les fichiers JavaScript du dashboard (public/dashboard/*.js) : chaque chemin d'API qu'ils appellent ;
  - le routeur (public/api/index.php) : la fonction PHP qui sert chaque chemin, et la méthode ;
  - le code serveur (src/*.php) : le fichier, la ligne et la première phrase du commentaire de cette fonction ;
  - l'accès (src/acces.php, ACCES_FRANCHISE) : une session de magasin peut-elle l'appeler, en mode « console » ?
Écrit public/dashboard/api-index.json, que la modale « API » du dashboard affiche.

    python3 tools/dashboard-api-index.py        (depuis pilotage-reseau/)
"""
import json
import os
import re
import sys
from datetime import datetime, timezone

RACINE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DASH = os.path.join(RACINE, 'public', 'dashboard')
ROUTEUR = os.path.join(RACINE, 'public', 'api', 'index.php')
SRC = os.path.join(RACINE, 'src')


def lire(p):
    with open(p, encoding='utf-8') as f:
        return f.read()


def fonctions_php():
    """Chaque fonction PHP de src/ : fichier, ligne, première phrase de son commentaire."""
    out = {}
    for nom in sorted(os.listdir(SRC)):
        if not nom.endswith('.php'):
            continue
        texte = lire(os.path.join(SRC, nom))
        for m in re.finditer(r'(/\*\*(?:(?!\*/).)*\*/\s*)?\nfunction\s+(\w+)\s*\(', texte, re.S):
            doc, fn = m.group(1) or '', m.group(2)
            ligne = texte[:m.start(2)].count('\n') + 1
            lignes = [re.sub(r'^\s*\*\s?', '', x).strip() for x in doc.strip().strip('/*').split('\n')]
            phrase = ' '.join(x for x in lignes if x and not x.startswith('@'))
            phrase = re.sub(r'\s+', ' ', phrase).strip()
            # La première phrase, sans la route répétée en tête (« GET /x?shop=4 — … »).
            phrase = re.sub(r'^((GET|POST|PUT|PATCH|DELETE)\b[^\s]*\s+/\S*(\s+\S*[{(][^—–]*?)?(\s+[—–-]+)?\s*)+', '', phrase)
            coupe = re.search(r'(?<=[a-zé»)])\.\s', phrase)
            if coupe:
                phrase = phrase[:coupe.start() + 1]
            out[fn] = {'fichier': 'src/' + nom, 'ligne': ligne, 'resume': phrase[:260]}
    return out


def routes_php():
    """Les routes du routeur : [{methodes, chemin | motif, handler}] et les routeurs par préfixe."""
    t = lire(ROUTEUR)
    routes, prefixes = [], []
    # Les lectures : « $path === '/x' => ep_fn() » et « (bool) preg_match('#^…$#', $path, $m) => fn( ».
    for m in re.finditer(r"\$path === '([^']+)'\s*=>\s*(\w+)\(", t):
        routes.append({'methodes': ['GET'], 'chemin': m.group(1), 'handler': m.group(2)})
    for m in re.finditer(r"preg_match\('#\^([^#]+)\$#', \$path, \$\w+\)\s*=>\s*(\w+)\(", t):
        routes.append({'methodes': ['GET'], 'motif': m.group(1), 'handler': m.group(2)})
    # Les écritures et les sorties à part : « if ($method === 'POST' && $path === '/x') { return wr_fn(); } ».
    sauter = {'accesRefus', 'http_response_code', 'json_encode', 'header', 'tempsServeur', 'exit', 'echo'}
    for m in re.finditer(r"if \(\$method === '(\w+)' && \$path === '([^']+)'\)\s*\{", t):
        corps = t[m.end():m.end() + 600]
        fn = next((x for x in re.findall(r'(\w+)\(', corps) if x not in sauter and not x.startswith('$')), None)
        if fn:
            routes.append({'methodes': [m.group(1)], 'chemin': m.group(2), 'handler': fn})
    for m in re.finditer(r"if \(\$method === '(\w+)' && preg_match\('#\^([^#]+)\$#', \$path, \$\w+\)\)\s*\{[^}]*?(\w+)\(", t):
        routes.append({'methodes': [m.group(1)], 'motif': m.group(2), 'handler': m.group(3)})
    # Les routeurs par préfixe : « str_starts_with($path, '/soir/') … rsRoute » et « ($c = cmRoute(…)) ».
    for m in re.finditer(r"str_starts_with\(\$path, '([^']+)'\)\)\s*\{\s*return (\w+)\(", t):
        prefixes.append({'prefixe': m.group(1), 'handler': m.group(2)})
    for m in re.finditer(r"\(\$c = (\w+)\(\$method, \$path\)\) !== null", t):
        prefixes.append({'prefixe': None, 'handler': m.group(1)})
    return routes, prefixes


def prefixes_routeurs(prefixes):
    """Le préfixe que gère un routeur « xxRoute » sans str_starts_with dans le routeur (il le teste lui-même)."""
    for p in prefixes:
        if p['prefixe'] is not None:
            continue
        for nom in os.listdir(SRC):
            if not nom.endswith('.php'):
                continue
            t = lire(os.path.join(SRC, nom))
            m = re.search(r'function\s+' + p['handler'] + r'\s*\([^)]*\)[^{]*\{\s*if \(\$path !== \'([^\']+)\'', t)
            if m:
                p['prefixe'] = m.group(1)
                break
    return [p for p in prefixes if p['prefixe']]


def acces_franchise():
    t = lire(os.path.join(SRC, 'acces.php'))
    m = re.search(r'const ACCES_FRANCHISE = \[(.*?)\];', t, re.S)
    return re.findall(r"'([^']+)'", m.group(1)) if m else []


def appels_dashboard():
    """Les chemins d'API cités par les fichiers du dashboard : {chemin: {methodes, fichiers}}."""
    out = {}
    for nom in sorted(os.listdir(DASH)):
        if not nom.endswith('.js') or nom in ('sw.js',):
            continue
        for n, ligne in enumerate(lire(os.path.join(DASH, nom)).split('\n'), 1):
            for m in re.finditer(r"['`](/[a-z][a-z0-9_\-]*(?:/[a-z0-9_\-]+)*)(?=[?'`/]|\s*\+|\$\{)", ligne):
                ch = m.group(1).rstrip('/')
                avant = ligne[:m.start()]
                meth = 'GET'
                for mm in ('POST', 'PUT', 'PATCH', 'DELETE'):
                    if re.search(r"method:\s*'" + mm + "'", ligne):
                        meth = mm
                if re.search(r'\becrire\(\s*$', avant) or re.search(r"\becrire\(\s*'", ligne[max(0, m.start() - 8):m.start() + 1]):
                    meth = 'POST'
                e = out.setdefault(ch, {'methodes': set(), 'fichiers': set()})
                e['methodes'].add(meth)
                e['fichiers'].add(nom + ':' + str(n))
    return out


def main():
    fns = fonctions_php()
    routes, prefixes = routes_php()
    prefixes = prefixes_routeurs(prefixes)
    franchise = acces_franchise()
    appels = appels_dashboard()

    def trouve(ch):
        for r in routes:
            if r.get('chemin') == ch:
                yield r
        for r in routes:
            if 'motif' in r:
                try:
                    if re.fullmatch(r['motif'], ch) or re.match(r['motif'].split('(')[0], ch) and r['motif'].split('(')[0] == ch + '/':
                        yield r
                except re.error:
                    pass

    def ouvert(ch):
        return any(ch == p or ch.startswith(p + '/') for p in franchise)

    liste = []
    for ch in sorted(appels):
        a = appels[ch]
        rs = list(trouve(ch))
        # Un chemin du dashboard qui n'est qu'un début (« /tresorerie/ » + reste) : les routes qui commencent par lui.
        if not rs:
            rs = [r for r in routes if r.get('chemin', '').startswith(ch + '/')]
        pre = next((p for p in prefixes if ch == p['prefixe'].rstrip('/') or ch.startswith(p['prefixe'].rstrip('/') + '/') or ch == p['prefixe']), None)
        if not rs and not pre:
            continue   # pas une route d'API (un fichier, une ancre, une adresse d'image)
        handlers = []
        for r in rs:
            f = fns.get(r['handler'], {})
            handlers.append({'methodes': r['methodes'], 'chemin': r.get('chemin') or ('^' + r['motif'] + '$'), 'handler': r['handler'],
                             'source': (f.get('fichier', '?') + ':' + str(f.get('ligne', '?'))) if f else '?', 'resume': f.get('resume', '')})
        if pre and not rs:
            f = fns.get(pre['handler'], {})
            handlers.append({'methodes': sorted(a['methodes']), 'chemin': pre['prefixe'] + '…', 'handler': pre['handler'],
                             'source': (f.get('fichier', '?') + ':' + str(f.get('ligne', '?'))) if f else '?', 'resume': f.get('resume', '')})
        liste.append({'chemin': ch, 'methodes': sorted(a['methodes']), 'fichiers': sorted(a['fichiers'])[:12],
                      'magasin': ouvert(ch), 'routes': handlers})

    sortie = {'genere': datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
              'source': 'tools/dashboard-api-index.py — lu dans public/dashboard/*.js, public/api/index.php, src/*.php, src/acces.php',
              'base': '/api/cockpit', 'accesMagasin': franchise, 'routes': liste}
    with open(os.path.join(DASH, 'api-index.json'), 'w', encoding='utf-8') as f:
        json.dump(sortie, f, ensure_ascii=False, indent=1)
    sans = [x['chemin'] for x in liste if not x['magasin']]
    print(f"{len(liste)} chemins d'API appelés par le dashboard ; {len(sans)} hors de l'accès magasin : {', '.join(sans)}")
    return 0


if __name__ == '__main__':
    sys.exit(main())
