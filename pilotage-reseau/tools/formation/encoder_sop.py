#!/usr/bin/env python3
"""Encode dans le module Formation une SOP ou un article tenu en fichier (data/formation/<dossier>/<id>.json).

Le fichier est la source : le même modèle que data/formation/sops/*.json (titre, objectif, pour, durée, sections →
étapes, points critiques, quiz) plus `classement` (souscat, phases, postes, competences, formations, checklists).
Les photos d'étape sont des fichiers du dossier `photos/` voisin (« dash-pl.jpg ») ; l'outil les envoie par
POST /formation/sop/photo et garde, dans `photos.json`, l'empreinte et le nom rendu par le serveur (« u/… ») :
une photo inchangée n'est jamais renvoyée.

Ce que fait l'outil, document par document, par les routes de l'API du cockpit (aucun accès à la base) :
  - absent du module          → POST /formation/sop/fiche (v1.0, « à valider »), puis le classement ;
  - présent, contenu différent → PUT /formation/sop/fiche/{id} : une nouvelle version (le NL est alors exigé
                                 par le serveur) ; --revalider si le geste change (les personnes validées repassent à revoir) ;
  - classement ou moment différents → PUT /formation/ref/standards/{id}, PATCH /formation/sop/fiche/{id}.
Sans --ecrire, il dit seulement ce qu'il ferait.

Usage (depuis pilotage-reseau/) :
  python3 tools/formation/encoder_sop.py --api <adresse>/formation/api/cockpit data/formation/dashboard/*.json
  python3 tools/formation/encoder_sop.py --api … --ecrire --par "Claude (à la demande de …)" --note "…" data/formation/dashboard/*.json
L'adresse de l'API se donne en argument ou dans FORMATION_API (jamais écrite dans le code).
"""
import argparse
import base64
import hashlib
import json
import os
import sys
import urllib.error
import urllib.request

LISTES = ['phases', 'postes', 'competences', 'formations', 'checklists']


def appel(api, methode, chemin, corps=None):
    """Un appel JSON à l'API ; rend (code HTTP, réponse décodée)."""
    data = json.dumps(corps, ensure_ascii=False).encode('utf-8') if corps is not None else None
    req = urllib.request.Request(api.rstrip('/') + chemin, data=data, method=methode,
                                 headers={'Content-Type': 'application/json', 'Accept': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
            return r.status, json.loads(r.read().decode('utf-8') or 'null')
    except urllib.error.HTTPError as e:
        texte = e.read().decode('utf-8', 'replace')
        try:
            return e.code, json.loads(texte)
        except ValueError:
            return e.code, {'erreur': texte[:300]}


def bi(x):
    x = x if isinstance(x, dict) else {}
    return {'fr': str(x.get('fr') or '').strip(), 'nl': str(x.get('nl') or '').strip()}


def canon(c):
    """Le contenu ramené aux champs que le serveur garde (foContenu), pour comparer sans faux écart."""
    secs = []
    for s in c.get('sections') or []:
        et = []
        for i, e in enumerate(s.get('etapes') or []):
            t = bi(e.get('titre'))
            et.append({'numero': str(e.get('numero') or i + 1), 'titre': None if not t['fr'] and not t['nl'] else t,
                       'texte': bi(e.get('texte')), 'critique': bool(e.get('critique')), 'photo': e.get('photo') or None,
                       'video': e.get('video') or None})
        secs.append({'titre': bi(s.get('titre')), 'etapes': et})
    quiz = []
    for q in c.get('quiz') or []:
        ch = q.get('choix') or {}
        quiz.append({'question': bi(q.get('question')), 'choix': {'fr': list(ch.get('fr') or []), 'nl': list(ch.get('nl') or [])},
                     'bonne': int(q.get('bonne') or 0)})
    return {'titre': bi(c.get('titre')), 'objectif': bi(c.get('objectif')), 'pour': bi(c.get('pour')),
            'duree': (c.get('duree') or '').strip() or None, 'sections': secs,
            'points_critiques': [bi(p) for p in c.get('points_critiques') or [] if bi(p)['fr'] or bi(p)['nl']], 'quiz': quiz}


def photos(doc, dossier, api, ecrire, manifeste):
    """Remplace chaque photo « fichier.jpg » par son nom au serveur (« u/… »), en n'envoyant que les nouvelles."""
    envoyees = 0
    for s in doc['sections']:
        for e in s['etapes']:
            p = e.get('photo')
            if not p or p.startswith('u/'):
                continue
            f = os.path.join(dossier, 'photos', p)
            if not os.path.isfile(f):
                sys.exit(f'photo absente : {f}')
            brut = open(f, 'rb').read()
            sha = hashlib.sha256(brut).hexdigest()
            m = manifeste.get(p)
            if m and m.get('sha') == sha and m.get('u'):
                e['photo'] = m['u']
                continue
            if not ecrire:
                e['photo'] = 'u/(à envoyer)' + p
                envoyees += 1
                continue
            code, r = appel(api, 'POST', '/formation/sop/photo', {'data': 'data:image/jpeg;base64,' + base64.b64encode(brut).decode()})
            if code >= 300 or not (r or {}).get('photo'):
                sys.exit(f'photo {p} refusée ({code}) : {r}')
            manifeste[p] = {'sha': sha, 'u': r['photo']}
            e['photo'] = r['photo']
            envoyees += 1
    return envoyees


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('fichiers', nargs='+')
    ap.add_argument('--api', default=os.environ.get('FORMATION_API'), help='…/formation/api/cockpit (ou FORMATION_API)')
    ap.add_argument('--ecrire', action='store_true', help='écrire pour de bon (sinon : dire ce qui serait fait)')
    ap.add_argument('--par', default='Encodage des SOP', help='l’auteur inscrit au journal et sur la version')
    ap.add_argument('--note', default=None, help='la note de la nouvelle version')
    ap.add_argument('--revalider', action='store_true', help='le geste change : les personnes validées repassent à revoir')
    a = ap.parse_args()
    if not a.api:
        sys.exit('--api ou FORMATION_API attendu')
    mode = 'ÉCRITURE' if a.ecrire else 'SIMULATION (rien n’est écrit ; --ecrire pour encoder)'
    print(mode)
    for f in a.fichiers:
        dossier = os.path.dirname(os.path.abspath(f))
        man_f = os.path.join(dossier, 'photos.json')
        manifeste = json.load(open(man_f, encoding='utf-8')) if os.path.isfile(man_f) else {}
        doc = json.load(open(f, encoding='utf-8'))
        sid, cl = doc['id'], doc.get('classement') or {}
        n = photos(doc, dossier, a.api, a.ecrire, manifeste)
        if a.ecrire:
            json.dump(manifeste, open(man_f, 'w', encoding='utf-8'), ensure_ascii=False, indent=1, sort_keys=True)
        print(f'\n{doc["code"]} — {doc["titre"]["fr"]} : {n} photo(s) {"envoyée(s)" if a.ecrire else "à envoyer"}')
        code, actuel = appel(a.api, 'GET', '/formation/sop/fiche/' + sid)
        contenu = canon(doc)
        if code == 404:
            print('  absent du module → création (v1.0, à valider)')
            if a.ecrire:
                c, r = appel(a.api, 'POST', '/formation/sop/fiche', {
                    'code': doc['code'], 'type': doc['type'], 'dossier': doc['dossier'], 'poste': doc['poste'], 'moment': doc.get('moment'),
                    'titre': contenu['titre'], 'objectif': contenu['objectif'], 'contenu': contenu, 'par': a.par})
                if c >= 300 or (r or {}).get('id') != sid:
                    sys.exit(f'  création refusée ou identifiant inattendu ({c}) : {r}')
                print('  créé :', r['id'])
                actuel = {'classement': {}, 'moment': doc.get('moment')}
        elif code >= 300:
            sys.exit(f'  lecture impossible ({code}) : {actuel}')
        else:
            if canon(actuel.get('contenu') or {}) == contenu:
                print(f'  contenu identique à la version {actuel.get("version")} → rien à publier')
            else:
                print(f'  contenu changé depuis la version {actuel.get("version")} → nouvelle version' + (' (à revoir)' if a.revalider else ' (précision)'))
                if a.ecrire:
                    c, r = appel(a.api, 'PUT', '/formation/sop/fiche/' + sid, {'contenu': contenu, 'note': a.note, 'revalider': a.revalider, 'par': a.par})
                    if c >= 300:
                        sys.exit(f'  publication refusée ({c}) : {r}')
                    print('  version', r.get('version'), 'publiée')
        # Le classement et le moment : seulement ce qui diffère.
        act = (actuel or {}).get('classement') or {}
        diff = {k: cl[k] for k in LISTES if k in cl and list(act.get(k) or []) != list(cl[k])}
        if 'souscat' in cl and act.get('souscat') != cl['souscat']:
            diff['souscat'] = cl['souscat']
        if diff:
            print('  classement →', json.dumps(diff, ensure_ascii=False))
            if a.ecrire:
                c, r = appel(a.api, 'PUT', '/formation/ref/standards/' + sid, dict(diff, par=a.par))
                if c >= 300:
                    sys.exit(f'  classement refusé ({c}) : {r}')
        if code != 404 and (actuel or {}).get('moment') != doc.get('moment'):
            print('  moment →', doc.get('moment'))
            if a.ecrire:
                c, r = appel(a.api, 'PATCH', '/formation/sop/fiche/' + sid, {'moment': doc.get('moment'), 'par': a.par})
                if c >= 300:
                    sys.exit(f'  moment refusé ({c}) : {r}')
    print('\nfait' if a.ecrire else '\nsimulation terminée')


if __name__ == '__main__':
    main()
