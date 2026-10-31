#!/usr/bin/env python3
"""Maquettes « montrer les données » du module Formation (31/10/2026).

Usage :
    python3 -I docs/maquettes/formation-donnees/generer.py <dossier>

<dossier> contient ref.json, liens.json et v25.json, lus tels que le cockpit les
rend (GET /formation/referentiel, /formation/liens, /formation/v25) : les
maquettes montrent les vraies données du panel. Les personnes de la maquette
« équipe » sont fictives.

Le constat qui les motive, mesuré sur le panel le 31/10/2026 :
  - les niveaux ne sont PAS cumulatifs : chaque niveau ne liste que ses
    nouvelles compétences (« Responsable d'équipe » : 4, alors qu'il en faut
    47 avec les niveaux d'avant) ;
  - aucune des 107 compétences n'a de procédure ni de ressource reliée :
    ces colonnes sont toujours vides.
"""
import html
import json
import os
import re
import sys

ICI = os.path.dirname(os.path.abspath(__file__))
CSS = '../../../public/formation/formation.css'
LOGO = '../../../public/formation/img/logo.png'
FAM = [('operationnel', 'Opérationnel', 'En magasin : vente, boulangerie-pâtisserie, traiteur'),
       ('cadre', 'Cadre et direction', 'Encadrer le magasin'),
       ('entrepreneur', 'Devenir entrepreneur', 'Candidat, franchisé, multi-boutique')]


def e(x):
    return html.escape(str(x if x is not None else ''))


def fo_de(nom):
    m = re.match(r'\s*\[(FO-\d+)\]', nom or '')
    return m.group(1) if m else None


def charger(d):
    ref = json.load(open(os.path.join(d, 'ref.json'), encoding='utf-8'))
    liens = json.load(open(os.path.join(d, 'liens.json'), encoding='utf-8'))['liens']
    v25 = json.load(open(os.path.join(d, 'v25.json'), encoding='utf-8'))
    comp = {c['id']: c for c in ref['competences']}
    for c in comp.values():
        cat = (c.get('categorie') or '').strip()
        c['cat'] = 'À classer' if (not cat or re.fullmatch(r'\d+', cat)) else cat
        l = liens.get(str(c['id']), {})
        c['fo'] = sorted({fo_de(f['nom']) for f in l.get('formations', []) if fo_de(f['nom'])}, key=lambda k: int(k[3:]))
        c['proc'] = len(l.get('procedures', []))
    return ref, liens, v25, comp


def page(nom, actif, kick, titre, corps, actions=''):
    nav = [('Pilotage', [('carte', 'Carte des métiers')]),
           ('Référentiel', [('postes', 'Postes & niveaux'), ('competences', 'Compétences'), ('formations', 'Formations'), ('manuel', 'Manuel opératoire')]),
           ('Équipes', [('equipes', 'Équipes'), ('parcours', 'Parcours')])]
    side = ''.join('<div class="g">' + e(g) + '</div>' + ''.join('<a class="' + ('on' if k == actif else '') + '">' + e(t) + '</a>' for k, t in it) for g, it in nav)
    doc = ('<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>' + e(titre) + '</title>'
           '<link rel="stylesheet" href="' + CSS + '"><link rel="stylesheet" href="maquette.css"></head><body>'
           '<div class="mq"><nav class="mq-side"><img src="' + LOGO + '" alt=""><div class="k">MODULE FORMATION</div>' + side + '</nav>'
           '<div class="mq-main"><header class="mq-top"><div><p class="kick">' + e(kick) + '</p><h1>' + e(titre) + '</h1></div><div class="act">' + actions + '</div></header>'
           '<main class="mq-body">' + corps + '</main></div></div></body></html>')
    open(os.path.join(ICI, nom + '.html'), 'w', encoding='utf-8').write(doc)


def niveaux_cumules(p):
    """Pour chaque niveau : nouvelles compétences, total cumulé, et part avec formation."""
    out, vu = [], []
    for n in p['niveaux']:
        nouv = [c for c in n['competences']]
        vu = vu + [c for c in nouv if c not in vu]
        out.append((n, nouv, list(vu)))
    return out


def jauge(avec, total):
    if not total:
        return ''
    x = round(avec * 100 / total)
    return '<div class="jauge' + (' z' if x == 0 else ' f' if x < 50 else '') + '"><i style="width:' + str(max(x, 3)) + '%"></i></div>'


def m1_carte(ref, v25, comp):
    fam = v25.get('familles', {})
    postes = [p for p in ref['postes'] if p['id'] is not None]
    par = {k: [] for k, _, _ in FAM}
    for p in postes:
        par[fam.get(str(p['id']), 'cadre')].append(p)
    reseau = [p for p in par['cadre'] if not re.search(r'adjoint|manager', p['nom'], re.I)]
    par['cadre'] = [p for p in par['cadre'] if p not in reseau]
    ordre_e = ['candidat', 'locataire', 'multi', 'franchis']
    par['entrepreneur'].sort(key=lambda p: next((i for i, k in enumerate(ordre_e) if k in p['nom'].lower()), 9))
    par['cadre'].sort(key=lambda p: 0 if 'adjoint' in p['nom'].lower() else 1)

    def echelle(p, fleches=True):
        h = '<div class="echelle"><h4>' + e(p['nom'].strip()) + '</h4>'
        for i, (n, nouv, cumul) in enumerate(niveaux_cumules(p)):
            avec = sum(1 for c in cumul if comp.get(c, {}).get('fo'))
            if i:
                h += '<div class="vers">↓</div>' if fleches else ''
            h += ('<div class="marche' + (' vide' if not cumul else '') + '"><div class="n">' + e(n['nom']) + '</div>'
                  '<div class="c"><span>' + ('+' + str(len(nouv)) if nouv else 'aucune') + '</span><b>' + str(len(cumul)) + ' au total</b></div>' + jauge(avec, len(cumul)) + '</div>')
        if not p['niveaux']:
            h += '<div class="marche vide"><div class="n muted">Aucun niveau</div></div>'
        return h + '</div>'

    n_niv = sum(len(p['niveaux']) for p in postes)
    sans_fo = sum(1 for c in comp.values() if not c['fo'])
    sans_proc = sum(1 for c in comp.values() if not c['proc'])
    vides = [p['nom'].strip() + (' · ' + n['nom'] if len(p['niveaux']) > 1 else '') for p in postes if fam.get(str(p['id'])) != 'cadre' or p in par['cadre'] for n in p['niveaux'] if not n['competences']]
    corps = ('<div class="kpis">'
             '<div class="kpi"><b>' + str(len(postes)) + '</b><span>Postes</span><small>' + str(n_niv) + ' niveaux</small></div>'
             '<div class="kpi"><b>' + str(len(comp)) + '</b><span>Compétences</span><small>' + str(len(comp) - sans_fo) + ' ont une formation</small></div>'
             '<div class="kpi"><b>' + str(len(v25['formations'])) + '</b><span>Formations</span><small>' + str(len(ref['formations'])) + ' évaluations dans le panel</small></div>'
             '<div class="kpi"><b>0</b><span>Parcours en cours</span><small>aucun en retard</small></div></div>'
             '<h2 class="mq-h">Le chemin des métiers</h2><p class="mq-sub">De gauche à droite, du premier jour à la franchise. Chaque marche : <b>+N</b> compétences nouvelles, et le <b>total</b> à maîtriser à ce niveau. La jauge : la part qui a une formation.</p>'
             '<div class="carte">')
    corps += '<section class="fam"><h3>Opérationnel</h3><p>En magasin, de débutant à responsable d’équipe</p><div class="postes h">' + ''.join(echelle(p) for p in par['operationnel']) + '</div></section>'
    corps += '<div class="fleche">→</div>'
    corps += ('<section class="fam"><h3>Cadre et direction</h3><p>Encadrer le magasin</p><div class="postes">' + ''.join(echelle(p) for p in par['cadre']) + '</div>'
              '<div class="reseau"><b>Rôles du réseau</b> (' + str(len(reseau)) + ') : ' + e(', '.join(p['nom'].strip() for p in reseau)) + '</div></section>')
    corps += '<div class="fleche">→</div>'
    corps += '<section class="fam"><h3>Devenir entrepreneur</h3><p>Candidat, franchisé, multi-boutique</p><div class="postes">' + ''.join(echelle(p) for p in par['entrepreneur']) + '</div></section>'
    corps += '</div>'
    corps += ('<h2 class="mq-h">Ce qui manque</h2><p class="mq-sub">Trois chiffres, chacun ouvre la liste à compléter.</p><div class="alertes">'
              '<div class="alerte w"><b>' + str(sans_fo) + '</b><span>compétences sans formation</span><small>Personne ne sait comment les apprendre : à rattacher à une FO.</small></div>'
              '<div class="alerte"><b>' + str(sans_proc) + ' / ' + str(len(comp)) + '</b><span>compétences sans procédure du manuel</span><small>Le manuel opératoire n’est relié à aucune compétence.</small></div>'
              '<div class="alerte"><b>' + str(len(vides)) + '</b><span>niveaux sans compétence</span><small>' + e(', '.join(vides[:4])) + (' …' if len(vides) > 4 else '') + '</small></div></div>')
    page('01-carte-des-metiers', 'carte', 'Vue réseau', 'Carte des métiers', corps, '<a class="btn">Rafraîchir</a>')


def m2_fiche(ref, v25, comp, poste_id=4, niveau_id=17):
    p = next(x for x in ref['postes'] if x['id'] == poste_id)
    etapes = niveaux_cumules(p)
    i = next(k for k, (n, _, _) in enumerate(etapes) if n['id'] == niveau_id)
    n, nouv, cumul = etapes[i]
    avant = [c for c in cumul if c not in nouv]
    fo_v25 = {f['code']: f for f in v25['formations']}
    esc_ = '<div class="escalier">'
    for k, (nn, nv, cu) in enumerate(etapes):
        if k:
            esc_ += '<div class="x">›</div>'
        esc_ += ('<div class="s' + (' on' if k == i else ' fait' if k < i else '') + '"><div class="t">' + e(nn['nom']) + '</div><div class="d">+' + str(len(nv)) + ' · ' + str(len(cu)) + ' au total</div></div>')
    esc_ += '</div>'
    par_cat = {}
    for c in nouv:
        if c in comp:
            par_cat.setdefault(comp[c]['cat'], []).append(comp[c])
    gauche = ('<section class="box"><h2 style="margin-bottom:8px">Ce qu’il faut savoir faire</h2><div class="seg"><span class="on">Nouveau à ce niveau · ' + str(len(nouv)) + '</span><span>Acquis avant · ' + str(len(avant)) + '</span><span>Tout · ' + str(len(cumul)) + '</span></div>')
    for cat in sorted(par_cat, key=lambda k: (-len(par_cat[k]), k)):
        gauche += '<div class="cat">' + e(cat) + ' · ' + str(len(par_cat[cat])) + '</div>'
        for c in sorted(par_cat[cat], key=lambda c: c['nom']):
            gauche += ('<div class="comp"><span class="ck"></span><span>' + e(c['nom']) + '</span><span>' +
                       (' '.join('<span class="tag">' + f + '</span>' for f in c['fo'][:2]) if c['fo'] else '<span class="tag ko">sans formation</span>') + '</span></div>')
    gauche += '</section>'
    # Les formations qui couvrent les nouvelles compétences, dans l'ordre du code.
    couvre = {}
    for c in nouv:
        for f in comp.get(c, {}).get('fo', []):
            couvre.setdefault(f, 0)
            couvre[f] += 1
    evals = {}
    for t in ref['formations']:
        f = fo_de(t['nom'])
        if f:
            evals[f] = evals.get(f, 0) + 1
    droite = '<section class="box"><h2 style="margin-bottom:4px">Pour les apprendre</h2><p class="mq-sub" style="margin-bottom:6px">Dans l’ordre du parcours.</p>'
    for k, f in enumerate(sorted(couvre, key=lambda x: int(x[3:]))):
        nom = fo_v25.get(f, {}).get('nom', 'Formation ' + f)
        droite += ('<div class="fo"><span class="code">' + f + '</span><div><div class="t">' + e(re.sub(r'^Formation\s+', '', nom)) + '</div><div class="d">'
                   + str(evals.get(f, 5)) + ' évaluations · couvre ' + str(couvre[f]) + ' compétence' + ('s' if couvre[f] > 1 else '') + ' de ce niveau</div></div></div>')
    sans = sum(1 for c in nouv if c in comp and not comp[c]['fo'])
    if sans:
        droite += '<div class="encart"><b>' + str(sans) + ' compétence' + ('s' if sans > 1 else '') + '</b> de ce niveau sans formation : à rattacher.</div>'
    suivant = etapes[i + 1] if i + 1 < len(etapes) else None
    droite += '</section>'
    droite += ('<section class="box" style="margin-top:14px"><h2 style="margin-bottom:6px">Pour y accéder</h2><p style="margin:0;font-size:14px">Avoir validé <b>' + e(etapes[i - 1][0]['nom'] if i else '—') + '</b> (' + str(len(avant)) + ' compétences).</p>'
               + ('<h2 style="margin:14px 0 6px">Ensuite</h2><p style="margin:0;font-size:14px"><b>' + e(suivant[0]['nom']) + '</b> : +' + str(len(suivant[1])) + ' compétences, ' + str(len(suivant[2])) + ' au total.</p>' if suivant else '')
               + '</section>')
    corps = ('<div class="fil">Opérationnel › ' + e(p['nom']) + '</div>' + esc_ +
             '<div class="deux"><div>' + gauche + '</div><div>' + droite + '</div></div>')
    page('02-fiche-niveau', 'postes', 'Postes & niveaux', p['nom'] + ' › ' + n['nom'], corps, '<a class="btn">Manuel</a><a class="btn">Fiche PDF ↗</a><a class="btn on">+ Exiger une compétence</a>')


def m3_matrice_comp(ref, comp, poste_id=4):
    p = next(x for x in ref['postes'] if x['id'] == poste_id)
    etapes = niveaux_cumules(p)
    intro = {}
    for k, (n, nouv, _) in enumerate(etapes):
        for c in nouv:
            intro.setdefault(c, k)
    par_cat = {}
    for c, k in intro.items():
        if c in comp:
            par_cat.setdefault(comp[c]['cat'], []).append((k, comp[c]))
    choix = ''.join('<a class="btn' + (' on' if x['id'] == poste_id else '') + '">' + e(x['nom'].strip()) + '</a>' for x in ref['postes'][:6])
    h = ('<div class="choix">' + choix + '</div><div class="legende"><span><i class="pt new"></i> exigée à partir de ce niveau</span><span><i class="pt acq"></i> déjà acquise</span>'
         '<span><span class="tag">FO-01</span> la formation qui l’enseigne</span><span><span class="tag ko">aucune</span> sans formation</span></div>')
    h += '<table class="mat"><thead><tr><th>Compétence</th>' + ''.join('<th>' + e(n['nom']) + '<br><span class="muted" style="font-weight:400">+' + str(len(nv)) + ' · ' + str(len(cu)) + ' au total</span></th>' for n, nv, cu in etapes) + '<th>Formation</th></tr></thead><tbody>'
    for cat in sorted(par_cat, key=lambda k: (min(x[0] for x in par_cat[k]), k)):
        h += '<tr class="g"><td colspan="' + str(len(etapes) + 2) + '">' + e(cat) + ' · ' + str(len(par_cat[cat])) + '</td></tr>'
        for k, c in sorted(par_cat[cat], key=lambda x: (x[0], x[1]['nom'])):
            h += ('<tr><td class="l">' + e(c['nom']) + '</td>' + ''.join('<td class="c">' + ('<i class="pt new"></i>' if j == k else '<i class="pt acq"></i>' if j > k else '') + '</td>' for j in range(len(etapes)))
                  + '<td>' + (' '.join('<span class="tag">' + f + '</span>' for f in c['fo'][:2]) if c['fo'] else '<span class="tag ko">aucune</span>') + '</td></tr>')
    h += '</tbody></table>'
    page('03-matrice-competences', 'competences', 'Compétences', 'Compétences par niveau — ' + p['nom'], h, '<a class="btn">Liste</a><a class="btn on">Matrice</a>')


def m4_matrice_fo(v25):
    noms = v25.get('nomsPanel', {})
    cols = [('Opérationnel', ['P3', 'P4', 'P1', 'P5', 'P2', 'P6']), ('Cadre et direction', ['P7', 'P8']), ('Devenir entrepreneur', ['P10', 'P9', 'P11', 'P12'])]
    court = {'P1': 'Boul.-Pât.', 'P5': 'Chef Boul.-Pât.', 'P2': 'Traiteur', 'P6': 'Chef traiteur', 'P3': 'Vente', 'P4': 'Chef vente', 'P7': 'Adjoint', 'P8': 'Manager',
             'P10': 'Candidat', 'P9': 'Loc.-gérant', 'P11': 'Franchisé', 'P12': 'Multi-boutique'}
    h = ('<div class="legende"><span><i class="pt O">O</i> obligatoire</span><span><i class="pt R">R</i> recommandée</span><span>Colonnes : les postes, rangés par famille · '
         'Lignes : les formations, rangées par thème</span></div>')
    h += '<table class="mat"><thead><tr class="fam"><th></th>' + ''.join('<th colspan="' + str(len(c)) + '">' + e(f) + '</th>' for f, c in cols) + '</tr><tr><th>Formation</th>' + ''.join(
        '<th title="' + e(noms.get(k, k)) + '">' + e(court.get(k, k)) + '</th>' for _, c in cols for k in c) + '</tr></thead><tbody>'
    par_theme = {}
    for f in v25['formations']:
        par_theme.setdefault(f.get('sousCategorie') or 'Autres', []).append(f)
    for theme in par_theme:
        h += '<tr class="g"><td colspan="13">' + e(theme) + '</td></tr>'
        for f in par_theme[theme]:
            h += '<tr><td class="l"><span class="tag" style="margin-right:6px">' + e(f['code']) + '</span>' + e(re.sub(r'^Formation\s+', '', f['nom'])) + '</td>' + ''.join(
                '<td class="c">' + ('<i class="pt ' + f['attendu'][k] + '">' + f['attendu'][k] + '</i>' if f['attendu'].get(k) in ('O', 'R') else '') + '</td>' for _, c in cols for k in c) + '</tr>'
    h += '</tbody></table>'
    page('04-matrice-formations', 'formations', 'Formations', 'Qui doit suivre quoi', h, '<a class="btn">Liste</a><a class="btn on">Matrice</a>')


def m5_equipe(ref):
    """Personnes fictives ; niveaux et nombres de compétences du panel."""
    vente = next(x for x in ref['postes'] if x['id'] == 4)
    boul = next(x for x in ref['postes'] if x['id'] == 1)
    cv, cb = niveaux_cumules(vente), niveaux_cumules(boul)
    gens = [('Camille D.', vente, cv, 2, 0.82), ('Lucas M.', vente, cv, 2, 1.0), ('Inès B.', vente, cv, 0, 0.5), ('Hugo P.', boul, cb, 1, 0.64),
            ('Sarah K.', boul, cb, 1, 1.0), ('Nora T.', vente, cv, 3, 0.75), ('Théo L.', None, None, None, 0)]
    lignes = ''
    for nom, p, et, k, part in gens:
        ini = ''.join(w[0] for w in nom.split()[:2])
        if p is None:
            lignes += ('<div class="pers"><span class="ini">' + ini + '</span><div><div class="nom">' + nom + '</div><div class="sous">sans poste dans le panel</div></div>'
                       '<div class="prog">—</div><div><span class="tag ko">à affecter</span></div><div><a class="btn">Choisir un poste</a></div></div>')
            continue
        n, nv, cu = et[k]
        tot = len(cu)
        fait = round(tot * part)
        x = round(fait * 100 / tot) if tot else 0
        suivant = et[k + 1] if k + 1 < len(et) else None
        lignes += ('<div class="pers"><span class="ini">' + ini + '</span><div><div class="nom">' + nom + '</div><div class="sous">' + e(p['nom']) + ' · ' + e(n['nom']) + '</div></div>'
                   '<div><div class="prog"><b>' + str(fait) + ' / ' + str(tot) + '</b> compétences du niveau</div>' + jauge(fait, tot) + '</div>'
                   '<div class="prog">' + ('Niveau complet' + (' → prêt·e pour <b>' + e(suivant[0]['nom']) + '</b> (+' + str(len(suivant[1])) + ')' if suivant else '') if part >= 1 else 'Il manque <b>' + str(tot - fait) + '</b> compétence' + ('s' if tot - fait > 1 else '')) + '</div>'
                   '<div>' + ('<span class="tag ok">parcours en cours</span>' if 0.6 < part < 1 else '<a class="btn">' + ('Ouvrir un parcours' if part >= 1 else 'Voir ce qui manque') + '</a>') + '</div></div>')
    corps = ('<div class="choix"><a class="btn on">L’Atelier by · Magasin A</a><a class="btn">Magasin B</a><a class="btn">Magasin C</a></div>'
             '<div class="kpis" style="margin-bottom:16px"><div class="kpi"><b>7</b><span>Personnes</span><small>1 sans poste dans le panel</small></div>'
             '<div class="kpi"><b>2</b><span>Niveau complet</span><small>prêtes pour l’étape suivante</small></div><div class="kpi"><b>4</b><span>En progression</span><small>2 avec un parcours</small></div>'
             '<div class="kpi"><b>73 %</b><span>Compétences tenues</span><small>du niveau de chacun</small></div></div>'
             '<section class="box" style="padding:4px 0">' + lignes + '</section><p class="mq-note" style="margin-top:10px">Personnes fictives ; niveaux et nombres de compétences tirés du panel.</p>')
    page('05-equipe', 'equipes', 'Équipes', 'Compétences de l’équipe', corps)


def main(d):
    ref, liens, v25, comp = charger(d)
    m1_carte(ref, v25, comp)
    m2_fiche(ref, v25, comp)
    m3_matrice_comp(ref, comp)
    m4_matrice_fo(v25)
    m5_equipe(ref)
    print('5 maquettes écrites dans', ICI)


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(sys.argv[1])
