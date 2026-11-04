#!/usr/bin/env python3
"""Catalogue des routes « formation » du panel, tiré de son swagger.

Usage (le swagger se télécharge à part, il n'est pas dans le dépôt) :

    curl -sS -o /tmp/openapi.json https://atelierby.tfbuddy.com/api/swagger/openapi.json
    python3 -I tools/formation/catalogue.py /tmp/openapi.json \
        public/formation/catalogue.json docs/formation-routes.md

Écrit le JSON lu par l'écran « API » du module /formation : pour chaque route,
sa famille, son verbe, ce que le swagger en dit (contrat, sans contrat, cassée),
ses paramètres, son corps et les champs de sa réponse. Le troisième argument,
facultatif, écrit la même chose en Markdown (docs/formation-routes.md).
"""
import json
import re
import sys
from datetime import date

# Familles, dans l'ordre de l'écran. Un chemin va à la PREMIÈRE famille qui le reconnaît.
FAMILLES = [
    ('postes', 'Postes & sous-postes (niveaux)',
     r'^/api/v1/(positions|position-levels|levels/)',
     'Les postes du réseau et leurs niveaux (les sous-postes) : compétences exigées, exigences, tâches, fiche PDF.'),
    ('carriere', 'Parcours de carrière',
     r'^/api/v1/career-',
     'Parcours et étapes de carrière. Le swagger les déclare TOUTES en 500 (« missing controller ») : '
     'les postes et sous-postes passent par /positions et /position-levels.'),
    ('liens', 'Liens d’une compétence',
     r'^/api/v1/competencies/\{competencyId\}/(operational-procedures|knowledge-resources|trainings)',
     'Ce qui s’apprend pour une compétence : procédures opératoires, ressources de la base de connaissances, formations.'),
    ('competences', 'Compétences RH',
     r'^/api/v1/(competencies|competency-subcategories)',
     'Le catalogue des compétences, rangé par section › catégorie › sous-catégorie fonctionnelle.'),
    ('formations', 'Formations',
     r'^/api/v1/operational-framework/trainings',
     'Les formations du cadre opérationnel et leurs formateurs (administrateurs ou employés).'),
    ('structure', 'Structure fonctionnelle',
     r'^/api/v1/operational-framework/(structure|categories|sections|subcategories)',
     'Sections, catégories et sous-catégories qui rangent compétences, formations et procédures.'),
    ('postesTravail', 'Postes de travail',
     r'^/api/v1/(operational-framework/workstations|shops/workstations|workstations/)',
     'Les postes de travail (zones) du magasin, distincts des postes RH.'),
    ('procedures', 'Procédures opératoires',
     r'^/api/v1/operational-procedure',
     'Les procédures du manuel opératoire : sections, pièces jointes, PDF, alias de traduction.'),
    ('connaissances', 'Base de connaissances',
     r'^/api/v1/(knowledge-base|shops/\{param1\}/knowledge-base)',
     'Les ressources (documents, liens, vidéos) rangées par catégorie.'),
    ('employes', 'Employés : postes et compétences',
     r'^/api/v1/(employees/\{employeeId\}(/(competencies|positions|workstations).*)?$|franchisee-employee/\{id\}/(competences|courses))',
     'Ce qu’une personne tient (poste, niveau) et ce qu’elle a validé (compétences).'),
    ('cours', 'Cours & sessions',
     r'^/api/v1/(course|courses|franchisee-employee-role)',
     'Le modèle « cours » de l’ancien panel : sessions (commissions), groupes, ensembles, participants.'),
    ('produit', 'Compétences produit',
     r'^/api/v1/(product-competences|products/\{param1\}/competences|subrecipes/\{param1\}/competences)',
     'Les compétences attachées aux produits et sous-recettes (savoir-faire de fabrication).'),
    ('packages', 'Packages',
     r'^/api/v1/(packages|product-packages|shops/\{param1\}/packages)',
     'Les packages (conditionnements) de produits et leur coût — rangés côté produit par le panel.'),
]

# Reconnues par une famille, mais hors du sujet : la fiche employé ne se modifie
# ni ne se supprime depuis la formation, et les zones des consultants ne sont
# pas des niveaux de poste.
EXCLURE = {
    ('PATCH', '/api/v1/employees/{employeeId}'),
    ('DELETE', '/api/v1/employees/{employeeId}'),
    ('GET', '/api/v1/positions/{positionId}/consultant-areas'),
    ('PUT', '/api/v1/positions/{positionId}/consultant-areas'),
}

# Les routes citées dans la demande du 31/10/2026.
DEMANDE = {
    ('GET', '/api/v1/career-stages/{careerStageId}/competencies'),
    ('POST', '/api/v1/career-stages/{careerStageId}/competencies'),
    ('DELETE', '/api/v1/career-stages/{careerStageId}/competencies/{competencyId}'),
    ('GET', '/api/v1/franchisee-employee/{id}/competences'),
    ('GET', '/api/v1/employees/{employeeId}/competencies'),
    ('PUT', '/api/v1/employees/{employeeId}/competencies'),
    ('DELETE', '/api/v1/employees/{employeeId}/competencies/{competencyId}'),
    ('GET', '/api/v1/competencies'),
    ('POST', '/api/v1/competencies'),
    ('DELETE', '/api/v1/competencies/{competencyId}'),
    ('GET', '/api/v1/competencies/{competencyId}'),
    ('PATCH', '/api/v1/competencies/{competencyId}'),
    ('GET', '/api/v1/competency-subcategories/{subcategoryId}/competencies'),
    ('POST', '/api/v1/competencies/{competencyId}/operational-procedures'),
    ('DELETE', '/api/v1/competencies/{competencyId}/operational-procedures/{procedureId}'),
    ('POST', '/api/v1/competencies/{competencyId}/knowledge-resources'),
    ('DELETE', '/api/v1/competencies/{competencyId}/knowledge-resources/{resourceId}'),
    ('POST', '/api/v1/competencies/{competencyId}/trainings'),
    ('DELETE', '/api/v1/competencies/{competencyId}/trainings/{trainingId}'),
    ('GET', '/api/v1/product-competences/name-aliases'),
    ('PATCH', '/api/v1/product-competences/{param1}/name-aliases'),
    ('GET', '/api/v1/product-competences/description-aliases'),
    ('PATCH', '/api/v1/product-competences/{param1}/description-aliases'),
    ('POST', '/api/v1/product-competences'),
    ('PATCH', '/api/v1/product-competences/{param1}'),
    ('GET', '/api/v1/product-competences/{param1}'),
    ('DELETE', '/api/v1/product-competences/{param1}'),
    ('POST', '/api/v1/packages'),
}

# Les routes que le module lit (src/formation.php).
MODULE = {
    '/api/v1/positions', '/api/v1/positions/{param1}/levels', '/api/v1/position-levels',
    '/api/v1/position-levels/{param1}', '/api/v1/position-levels/{param1}/competencies',
    '/api/v1/position-levels/{param1}/requirements', '/api/v1/position-levels/{param1}/tasks',
    '/api/v1/position-levels/{param1}/pdf', '/api/v1/positions/{param1}/pdf',
    '/api/v1/competencies', '/api/v1/competencies/{competencyId}',
    '/api/v1/operational-framework/trainings', '/api/v1/operational-framework/trainings/{param1}',
    '/api/v1/operational-framework/trainings/{param1}/trainers',
    '/api/v1/operational-framework/structure/sections', '/api/v1/operational-framework/structure/categories',
    '/api/v1/operational-framework/workstations',
    '/api/v1/operational-procedures', '/api/v1/operational-procedures/{param1}',
    '/api/v1/operational-procedures/{param1}/attachments', '/api/v1/operational-procedures/{param1}/pdf',
    '/api/v1/knowledge-base/resources', '/api/v1/knowledge-base/resources/{param1}',
    '/api/v1/product-competences', '/api/v1/employees/{employeeId}',
}

# Ce que fait la route, en français, quand le gabarit générique ne suffit pas.
NOTES = {
    ('GET', '/api/v1/positions'): 'Liste des postes RH du réseau.',
    ('GET', '/api/v1/positions/{param1}/levels'): 'Les niveaux (sous-postes) d’un poste, dans leur ordre.',
    ('GET', '/api/v1/position-levels'): 'Tous les niveaux de tous les postes.',
    ('GET', '/api/v1/position-levels/{param1}/competencies'): 'Les compétences exigées à ce niveau : le cœur du référentiel poste → compétences.',
    ('POST', '/api/v1/position-levels/{param1}/competencies'): 'Ajoute une compétence exigée au niveau.',
    ('DELETE', '/api/v1/position-levels/{param1}/competencies/{param2}'): 'Retire une compétence du niveau.',
    ('GET', '/api/v1/position-levels/{param1}/requirements'): 'Les exigences du niveau (hors compétences : ancienneté, conditions…).',
    ('GET', '/api/v1/position-levels/{param1}/tasks'): 'Les tâches attachées au niveau.',
    ('GET', '/api/v1/positions/{param1}/pdf'): 'Fiche de poste en PDF, générée par le panel.',
    ('GET', '/api/v1/position-levels/{param1}/pdf'): 'Fiche du niveau en PDF.',
    ('PATCH', '/api/v1/position-levels/{param1}/move-up'): 'Remonte le niveau d’un cran dans son poste.',
    ('PATCH', '/api/v1/position-levels/{param1}/move-down'): 'Descend le niveau d’un cran.',
    ('GET', '/api/v1/competencies'): 'Le catalogue complet des compétences (filtre facultatif section_id). Pas de pagination.',
    ('GET', '/api/v1/competencies/{competencyId}'): 'Une compétence ; include=operational_procedures,trainings,knowledge_resources ajoute ses liens. Rend [] si elle n’existe pas.',
    ('POST', '/api/v1/competencies'): 'Crée une compétence dans une sous-catégorie.',
    ('PATCH', '/api/v1/competencies/{competencyId}'): 'Modifie une compétence. Un corps vide fait échouer la requête SQL.',
    ('DELETE', '/api/v1/competencies/{competencyId}'): 'Supprime une compétence (succès même si elle n’existait pas).',
    ('GET', '/api/v1/competency-subcategories/{subcategoryId}/competencies'): 'Compétences d’une sous-catégorie. Cassée : TypeError côté panel (sendUpdated reçoit un tableau).',
    ('POST', '/api/v1/competencies/{competencyId}/trainings'): 'Attache une formation à la compétence.',
    ('DELETE', '/api/v1/competencies/{competencyId}/trainings/{trainingId}'): 'Détache une formation.',
    ('POST', '/api/v1/competencies/{competencyId}/operational-procedures'): 'Attache une procédure opératoire à la compétence.',
    ('DELETE', '/api/v1/competencies/{competencyId}/operational-procedures/{procedureId}'): 'Détache une procédure.',
    ('POST', '/api/v1/competencies/{competencyId}/knowledge-resources'): 'Attache une ressource de la base de connaissances.',
    ('DELETE', '/api/v1/competencies/{competencyId}/knowledge-resources/{resourceId}'): 'Détache une ressource.',
    ('GET', '/api/v1/employees/{employeeId}'): 'Fiche employé ; include=competencies,positions ajoute compétences validées et postes tenus.',
    ('GET', '/api/v1/employees/{employeeId}/competencies'): 'Compétences validées par l’employé.',
    ('PUT', '/api/v1/employees/{employeeId}/competencies'): 'Valide une compétence pour l’employé (upsert).',
    ('DELETE', '/api/v1/employees/{employeeId}/competencies/{competencyId}'): 'Retire une compétence validée.',
    ('GET', '/api/v1/employees/{employeeId}/positions'): 'Postes et niveaux tenus par l’employé.',
    ('PUT', '/api/v1/employees/{employeeId}/positions'): 'Affecte un poste et un niveau à l’employé.',
    ('GET', '/api/v1/franchisee-employee/{id}/competences'): 'Compétences d’un employé franchisé (modèle « cours » de l’ancien panel).',
    ('GET', '/api/v1/franchisee-employee/{id}/courses'): 'Cours suivis par un employé franchisé.',
    ('GET', '/api/v1/operational-framework/trainings'): 'Liste des formations.',
    ('GET', '/api/v1/operational-framework/trainings/{param1}/trainers'): 'Formateurs d’une formation.',
    ('GET', '/api/v1/operational-procedures/{param1}'): 'Une procédure avec ses sections (le contenu du manuel).',
    ('GET', '/api/v1/operational-procedures/{param1}/pdf'): 'Procédure en PDF.',
    ('GET', '/api/v1/product-competences'): 'Liste des compétences produit.',
    ('GET', '/api/v1/product-competences/name-aliases'): 'Traductions des noms de compétences produit.',
    ('GET', '/api/v1/product-competences/description-aliases'): 'Traductions des descriptions de compétences produit.',
    ('PATCH', '/api/v1/product-competences/{param1}/name-aliases'): 'Écrit les traductions du nom.',
    ('PATCH', '/api/v1/product-competences/{param1}/description-aliases'): 'Écrit les traductions de la description.',
    ('POST', '/api/v1/packages'): 'Crée un package (conditionnement produit). Route sans contrat dans le swagger.',
    ('GET', '/api/v1/packages'): 'Liste des packages.',
}

VERBES = {'GET': 'Lit', 'POST': 'Crée / ajoute', 'PUT': 'Remplace / associe', 'PATCH': 'Modifie', 'DELETE': 'Supprime / retire'}

# Les segments de chemin, en français, pour les routes sans note propre.
SEGMENTS = {
    'positions': 'postes', 'position-levels': 'niveaux', 'levels': 'niveaux', 'tasks': 'tâches',
    'requirements': 'exigences', 'competencies': 'compétences', 'competences': 'compétences',
    'competency-subcategories': 'sous-catégories de compétences', 'trainings': 'formations',
    'trainers': 'formateurs', 'admin-trainers': 'formateurs (administrateurs)', 'employee-trainers': 'formateurs (employés)',
    'operational-procedures': 'procédures', 'attachments': 'pièces jointes', 'sections': 'sections',
    'operational-procedure-sections': 'sections de procédure', 'operational-procedure-section-titles': 'titres de section',
    'operational-procedure-categories': 'catégories de procédures', 'operational-procedure-attachments': 'pièces jointes de procédure',
    'operational-procedure-todo-links': 'liens procédure ↔ tâche', 'categories': 'catégories', 'subcategories': 'sous-catégories',
    'structure': 'structure', 'knowledge-base': 'base de connaissances', 'resources': 'ressources',
    'workstations': 'postes de travail', 'product-competences': 'compétences produit', 'products': 'produits',
    'subrecipes': 'sous-recettes', 'packages': 'packages', 'product-packages': 'packages produit', 'cost': 'coût',
    'pdf': 'PDF', 'move-up': 'monter d’un cran', 'move-down': 'descendre d’un cran', 'name-aliases': 'traductions du nom',
    'description-aliases': 'traductions de la description', 'title-aliases': 'traductions du titre',
    'label-aliases': 'traductions du libellé', 'note-aliases': 'traductions de la note', 'aliases': 'traductions',
    'course': 'cours', 'courses': 'cours', 'course-commission': 'session de cours', 'course-commissions': 'sessions de cours',
    'course-group': 'groupe de cours', 'course-groups': 'groupes de cours', 'course-set': 'ensemble de cours',
    'course-sets': 'ensembles de cours', 'course-subgroup': 'sous-groupe de cours', 'course-subgroups': 'sous-groupes de cours',
    'participant': 'participant', 'participants': 'participants', 'document': 'document',
    'franchisee-employee-role': 'rôle d’employé', 'franchisee-employee-roles': 'rôles d’employé',
    'franchisee-employee': 'employé franchisé', 'employees': 'employés', 'shops': 'magasins', 'stages': 'étapes',
    'career-paths': 'parcours de carrière', 'career-stages': 'étapes de carrière',
}


def note_generique(methode, chemin):
    segs = [x for x in chemin.replace('/api/v1/', '').split('/') if x]
    noms = [SEGMENTS.get(x, x) for x in segs if not x.startswith('{') and x != 'operational-framework']
    chaine = ' › '.join(noms)
    if methode == 'GET':
        return ('Lit un élément : ' if segs[-1].startswith('{') else 'Liste : ') + chaine + '.'
    return VERBES[methode] + ' : ' + chaine + '.'


STATUTS_MD = {
    'contrat': 'contrat documenté',
    'decrit': 'décrite, sans schéma',
    'runtime': 'sans contrat',
    'casse': '**cassée (500)**',
}


def markdown(out):
    """Le catalogue en Markdown : une section par famille, une fiche par route."""
    def cell(t):
        return str(t).replace('|', '\\|').replace('\n', ' ')
    L = ['# Routes API du référentiel formation (panel TFBuddy)', '',
         '> Fichier GÉNÉRÉ par `tools/formation/catalogue.py` — ne pas éditer à la main.',
         '> ' + out['source'] + ' · généré le ' + out['genere'] + '. Le module /formation lit les routes marquées « lue ».',
         '> Contexte, modèle de données et constats : `docs/formation.md`.', '']
    n = {}
    for r in out['routes']:
        n[r['statut']] = n.get(r['statut'], 0) + 1
    L.append('**' + str(len(out['routes'])) + ' routes** — ' + ', '.join(str(n.get(k, 0)) + ' ' + v.strip('*') for k, v in STATUTS_MD.items()) + '.')
    L.append('')
    L.append('| Famille | Routes |')
    L.append('|---|---:|')
    for f in out['familles']:
        L.append('| [' + f['nom'] + '](#' + f['cle'] + ') | ' + str(sum(1 for r in out['routes'] if r['famille'] == f['cle'])) + ' |')
    for f in out['familles']:
        rs = [r for r in out['routes'] if r['famille'] == f['cle']]
        if not rs:
            continue
        L += ['', '<a id="' + f['cle'] + '"></a>', '## ' + f['nom'], '', f['note'], '',
              '| Verbe | Route | État | Ce qu’elle fait | Module |', '|---|---|---|---|---|']
        for r in rs:
            L.append('| `' + r['methode'] + '` | `' + r['chemin'] + '` | ' + STATUTS_MD[r['statut']] + ' | ' + cell(r['note'])
                     + (' *(citée dans la demande)*' if r['demande'] else '') + ' | ' + ('lue' if r['module'] else '') + ' |')
        details = [r for r in rs if r['corps'] or r['reponse'] or any(p['ou'] == 'query' for p in r['params'])]
        for r in details:
            L += ['', '#### `' + r['methode'] + ' ' + r['chemin'] + '`', '']
            q = [p for p in r['params'] if p['ou'] == 'query']
            if q:
                L.append('- Paramètres : ' + '; '.join('`' + p['nom'] + '` (' + (p['type'] or '?') + ')' + (' — ' + cell(p['note']) if p['note'] else '') for p in q))
            if r['corps']:
                L.append('- Corps : ' + ', '.join('`' + c['champ'] + '` ' + c['type'] for c in r['corps']))
            if r['reponse']:
                L.append('- Réponse : ' + ', '.join('`' + c['champ'] + '` ' + c['type'] for c in r['reponse']))
            ko = [c for c in r['codes'] if not c['code'].startswith('2')]
            if ko:
                L.append('- Erreurs : ' + '; '.join(c['code'] + ' ' + cell(c['note']) for c in ko))
    return '\n'.join(L) + '\n'


def main(src, dst, md=None):
    d = json.load(open(src, encoding='utf-8'))

    def res(ref):
        o = d
        for p in ref.lstrip('#/').split('/'):
            o = o[p]
        return o

    def deref(s, seen=()):
        while isinstance(s, dict) and '$ref' in s:
            if s['$ref'] in seen:
                return {}
            seen = seen + (s['$ref'],)
            s = res(s['$ref'])
        return s

    def champs(s, prefix='', prof=0):
        s = deref(s)
        out = []
        if not isinstance(s, dict) or prof > 4:
            return out
        for x in s.get('allOf', []):
            out += champs(x, prefix, prof)
        for k in ('oneOf', 'anyOf'):
            if s.get(k):
                out += champs(s[k][0], prefix, prof)
        if s.get('type') == 'array' and 'items' in s:
            out += champs(s['items'], prefix + '[]', prof + 1)
        for k, v in (s.get('properties') or {}).items():
            v = deref(v)
            t = v.get('type', '?') if isinstance(v, dict) else '?'
            if isinstance(v, dict) and v.get('nullable'):
                t += '?'
            if isinstance(v, dict) and v.get('enum'):
                t += ' (' + ' | '.join(map(str, v['enum'])) + ')'
            nom = (prefix + '.' + k).lstrip('.')
            out.append({'champ': nom, 'type': t})
            if isinstance(v, dict) and (v.get('type') in ('object', 'array') or 'properties' in v):
                out += champs(v, prefix + '.' + k, prof + 1)
        return out

    routes = []
    for p, ops in d['paths'].items():
        fam = next((f for f in FAMILLES if re.search(f[2], p)), None)
        if fam is None:
            continue
        for m, op in ops.items():
            if m not in ('get', 'post', 'put', 'patch', 'delete'):
                continue
            M = m.upper()
            if (M, p) in EXCLURE:
                continue
            reps = {c: deref(r) for c, r in op.get('responses', {}).items()}
            succes = [c for c in reps if c.startswith('2')]
            descs = ' '.join((r.get('description') or '') for r in reps.values())
            if 'x-runtime-route-source' in op:
                statut = 'runtime'
            elif not succes and re.search(r'missing|TypeError|Registered handler', descs):
                statut = 'casse'
            elif any(next(iter(reps[c].get('content', {}).values()), {}).get('schema') for c in succes):
                statut = 'contrat'
            else:
                statut = 'decrit'
            params = []
            for prm in op.get('parameters', []):
                prm = deref(prm)
                if prm.get('in') == 'query' and prm.get('name') == 'id' and not prm.get('schema'):
                    continue  # doublon du paramètre de chemin dans les vieilles routes
                params.append({'nom': prm.get('name'), 'ou': prm.get('in'),
                               'type': deref(prm.get('schema', {})).get('type', ''),
                               'requis': bool(prm.get('required')),
                               'note': (prm.get('description') or '')[:300]})
            corps = []
            rb = deref(op.get('requestBody') or {})
            if rb.get('content'):
                ct = next(iter(rb['content']))
                corps = champs(rb['content'][ct].get('schema', {}))
            reponse = []
            for c in succes:
                cont = reps[c].get('content', {})
                if cont:
                    reponse = champs(next(iter(cont.values())).get('schema', {}))
                    break
            codes = [{'code': c, 'note': (r.get('description') or '')[:300]} for c, r in reps.items()]
            note = NOTES.get((M, p))
            if note is None:
                note = note_generique(M, p)
            routes.append({
                'famille': fam[0], 'methode': M, 'chemin': p,
                'resume': op.get('summary') or '', 'note': note, 'statut': statut,
                'tag': ', '.join(op.get('tags') or []),
                'source': (op.get('x-runtime-route-source') or {}).get('file'),
                'params': params, 'corps': corps, 'reponse': reponse, 'codes': codes,
                'demande': (M, p) in DEMANDE, 'module': M == 'GET' and p in MODULE,
            })
    ordre = {f[0]: i for i, f in enumerate(FAMILLES)}
    vrb = {'GET': 0, 'POST': 1, 'PUT': 2, 'PATCH': 3, 'DELETE': 4}
    routes.sort(key=lambda r: (ordre[r['famille']], r['chemin'], vrb[r['methode']]))
    out = {
        'genere': date.today().isoformat(),
        'source': 'Swagger du panel TFBuddy (/api/swagger/openapi.json), ' + str(len(d['paths'])) + ' chemins',
        'familles': [{'cle': f[0], 'nom': f[1], 'note': f[3]} for f in FAMILLES],
        'routes': routes,
    }
    with open(dst, 'w', encoding='utf-8') as fh:
        json.dump(out, fh, ensure_ascii=False, indent=1)
        fh.write('\n')
    print(len(routes), 'routes ->', dst)
    if md:
        with open(md, 'w', encoding='utf-8') as fh:
            fh.write(markdown(out))
        print('markdown ->', md)


if __name__ == '__main__':
    if len(sys.argv) not in (3, 4):
        sys.exit(__doc__)
    main(*sys.argv[1:])
