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


def chemin_reseau(ref, v25):
    """Les postes du chemin, par rôle : trois métiers, l'encadrement, l'entrepreneur."""
    P = {p['id']: p for p in ref['postes'] if p['id'] is not None}
    nom = lambda k: next((p for p in P.values() if k in p['nom'].lower()), None)
    return {'vente': nom('vente'), 'boul': nom('boulang'), 'trait': nom('traiteur'), 'adjoint': nom('adjoint'),
            'manager': next((p for p in P.values() if p['nom'].strip().lower() == 'manager'), None), 'candidat': nom('candidat'),
            'franchise': next((p for p in P.values() if p['nom'].strip().lower().startswith('franchisé') and 'multi' not in p['nom'].lower()), None),
            'multi': nom('multi'), 'locataire': nom('locataire'),
            'reseau': [p for p in P.values() if v25.get('familles', {}).get(str(p['id'])) == 'cadre' and not re.search(r'adjoint|manager', p['nom'], re.I)]}


def personnes(eff, nid):
    n = eff.get('parNiveau', {}).get(str(nid), 0)
    return n


def pp(n):
    return '<span class="pp' + (' z' if not n else '') + '">' + (str(n) + ' pers.' if n else 'personne') + '</span>'


def m1a_metro(ref, v25, comp, eff):
    """Variante A : un plan de métro — trois lignes de métier qui rejoignent le tronc commun."""
    R = chemin_reseau(ref, v25)
    W, top = 1150, 0
    lignes = [('vente', 'Vente', '#8D1D2C', 110), ('boul', 'Boulangerie – Pâtisserie', '#b07a2a', 260), ('trait', 'Traiteur', '#3f7a52', 410)]
    svg, st = '', ''

    def station(x, y, coul, n, total, nb, vide=False):
        return ('<div class="st" style="left:' + str(x - 56) + 'px;top:' + str(y - 9) + 'px"><div class="dot" style="border-color:' + coul + '"></div>'
                '<div class="nm">' + e(n['nom']) + '</div><div class="dt' + (' vd' if vide else '') + '">' + ('compétences à définir' if vide else str(total) + ' compétences') + '</div>' + pp(nb) + '</div>')

    for k, lib, coul, y in lignes:
        p = R[k]
        if not p:
            continue
        et = niveaux_cumules(p)
        xs = [70 + i * (370 / max(len(et) - 1, 1)) for i in range(len(et))]
        svg += '<path d="M40,' + str(y) + ' L' + str(xs[-1]) + ',' + str(y) + ' C' + str(xs[-1] + 90) + ',' + str(y) + ' 530,260 600,260" stroke="' + coul + '" stroke-width="7" fill="none" stroke-linecap="round"/>'
        st += '<div class="ligne-nom" style="left:18px;top:' + str(y - 34) + 'px;color:' + coul + '">' + e(lib) + '</div>'
        for x, (n, nv, cu) in zip(xs, et):
            st += station(x, y, coul, n, len(cu), personnes(eff, n['id']))
    tronc = [('adjoint', 650), ('manager', 765), ('candidat', 885), ('franchise', 995), ('multi', 1092)]
    svg += '<path d="M600,260 L1092,260" stroke="#47423c" stroke-width="7" fill="none" stroke-linecap="round"/>'
    if R['locataire']:
        # La location-gérance part du candidat, vers le haut : les étiquettes du tronc sont dessous.
        svg += '<path d="M885,260 C885,170 895,120 945,120 L995,120" stroke="#47423c" stroke-width="5" stroke-dasharray="2 9" fill="none" stroke-linecap="round"/>'
    for k, x in tronc:
        p = R[k]
        if p and p['niveaux']:
            n = p['niveaux'][-1]
            st += station(x, 260, '#47423c', n | {'nom': p['nom'].strip()}, len(n['competences']), personnes(eff, n['id']), not n['competences'])
    if R['locataire'] and R['locataire']['niveaux']:
        n = R['locataire']['niveaux'][-1]
        st += station(995, 120, '#47423c', n | {'nom': R['locataire']['nom']}, len(n['competences']), personnes(eff, n['id']), not n['competences'])
    zones = ('<div class="zone" style="left:0;width:590px"><b>Opérationnel</b></div><div class="zone c" style="left:590px;width:240px"><b>Cadre et direction</b></div>'
             '<div class="zone e" style="left:830px;width:320px"><b>Devenir entrepreneur</b></div>')
    carte = '<div class="metro">' + zones + '<svg width="' + str(W) + '" height="540">' + svg + '</svg>' + st + '</div>'
    reseau = ', '.join(p['nom'].strip() for p in R['reseau'])
    corps = (kpis_carte(ref, v25, comp, eff) + '<h2 class="mq-h">Le chemin des métiers</h2><p class="mq-sub">Trois lignes de métier qui rejoignent le même tronc : encadrer, puis entreprendre. '
             'Chaque station : le niveau, les compétences à maîtriser (cumulées), les personnes qui y sont aujourd’hui.</p>' + carte +
             '<div class="legende2"><span><i style="background:#8D1D2C"></i>Vente</span><span><i style="background:#b07a2a"></i>Boulangerie – Pâtisserie</span><span><i style="background:#3f7a52"></i>Traiteur</span>'
             '<span><i style="background:#47423c"></i>Tronc commun</span><span>· · · Locataire-gérant : une autre voie que la franchise</span></div>'
             '<p class="mq-note" style="margin-top:14px"><b>Hors chemin — rôles du réseau</b> (' + str(len(R['reseau'])) + ') : ' + e(reseau) + '</p>' + manques(ref, comp, eff))
    page('01a-carte-metro', 'carte', 'Vue réseau', 'Carte des métiers', corps, '<a class="btn">Rafraîchir</a>')


def kpis_carte(ref, v25, comp, eff):
    postes = [p for p in ref['postes'] if p['id'] is not None]
    places = eff.get('actifs', 0) - eff.get('sansPoste', 0)
    return ('<div class="kpis">'
            '<div class="kpi"><b>' + str(eff.get('actifs', 0)) + '</b><span>Personnes actives</span><small>' + str(places) + ' placées sur la carte · ' + str(eff.get('magasins', 0)) + ' magasins</small></div>'
            '<div class="kpi"><b>' + str(len(postes)) + '</b><span>Postes</span><small>' + str(sum(len(p['niveaux']) for p in postes)) + ' niveaux</small></div>'
            '<div class="kpi"><b>' + str(len(comp)) + '</b><span>Compétences</span><small>' + str(sum(1 for c in comp.values() if c['fo'])) + ' ont une formation</small></div>'
            '<div class="kpi"><b>' + str(len(v25['formations'])) + '</b><span>Formations</span><small>' + str(len(ref['formations'])) + ' évaluations dans le panel</small></div></div>')


def manques(ref, comp, eff):
    sans_fo = sum(1 for c in comp.values() if not c['fo'])
    sans_proc = sum(1 for c in comp.values() if not c['proc'])
    return ('<h2 class="mq-h">Ce qui manque</h2><div class="alertes" style="margin-top:10px">'
            '<div class="alerte"><b>' + str(eff.get('sansPoste', 0)) + '</b><span>personnes sans poste dans le panel</span><small>Elles n’apparaissent pas sur la carte : à placer.</small></div>'
            '<div class="alerte w"><b>' + str(sans_fo) + '</b><span>compétences sans formation</span><small>À rattacher à une FO.</small></div>'
            '<div class="alerte"><b>' + str(sans_proc) + ' / ' + str(len(comp)) + '</b><span>compétences sans procédure du manuel</span><small>Le manuel n’est relié à aucune compétence.</small></div></div>')


def m1b_grille(ref, v25, comp, eff):
    """Variante B : une grille en escalier — une ligne par voie, une colonne par étape."""
    R = chemin_reseau(ref, v25)

    def cel(titre, total, nb, avec=None, vide=False):
        return ('<div class="cel' + (' vide' if vide else '') + '"><div class="t">' + e(titre) + '</div><div class="d"><span>' + ('à définir' if vide else '<b>' + str(total) + '</b> compétences') + '</span>' + pp(nb) + '</div>'
                + (jauge(avec, total) if avec is not None and total else '') + '</div>')

    def rangee(nom, sous, cellules):
        return '<div class="rg"><div class="pn">' + e(nom) + ('<small>' + e(sous) + '</small>' if sous else '') + '</div>' + ''.join(cellules) + ''.join('<div></div>' for _ in range(4 - len(cellules))) + '</div>'

    h = '<div class="esc2"><div class="fh">Opérationnel<span>en magasin, de l’arrivée à responsable d’équipe</span></div>'
    for k, lib in (('vente', 'Vente'), ('boul', 'Boulangerie – Pâtisserie'), ('trait', 'Traiteur')):
        p = R[k]
        if not p:
            continue
        cs = []
        for n, nv, cu in niveaux_cumules(p):
            cs.append(cel(n['nom'], len(cu), personnes(eff, n['id']), sum(1 for c in cu if comp.get(c, {}).get('fo'))))
        rest = sum(personnes(eff, n['id']) for n in p['niveaux'])
        h += rangee(lib, str(rest) + ' personnes', cs)
    h += '<div class="fh">Cadre et direction<span>encadrer le magasin — on y arrive depuis « Responsable d’équipe »</span></div>'
    cs = []
    for k in ('adjoint', 'manager'):
        p = R[k]
        if p and p['niveaux']:
            n = p['niveaux'][-1]
            cs.append(cel(p['nom'].strip(), len(n['competences']), personnes(eff, n['id']), sum(1 for c in n['competences'] if comp.get(c, {}).get('fo')), not n['competences']))
    h += rangee('Encadrer', None, cs)
    h += '<div class="fh">Devenir entrepreneur<span>candidat, puis franchisé ; ou locataire-gérant</span></div>'
    cs = []
    for k in ('candidat', 'franchise', 'multi'):
        p = R[k]
        if p and p['niveaux']:
            n = p['niveaux'][-1]
            cs.append(cel(p['nom'].strip(), len(n['competences']), personnes(eff, n['id']), sum(1 for c in n['competences'] if comp.get(c, {}).get('fo')), not n['competences']))
    h += rangee('Franchise', None, cs)
    if R['locataire'] and R['locataire']['niveaux']:
        n = R['locataire']['niveaux'][-1]
        h += rangee('Location-gérance', 'l’autre voie', [cel(R['locataire']['nom'], len(n['competences']), personnes(eff, n['id']), None, not n['competences'])])
    h += '</div>'
    reseau = ', '.join(p['nom'].strip() for p in R['reseau'])
    corps = (kpis_carte(ref, v25, comp, eff) + '<h2 class="mq-h">Le chemin des métiers</h2><p class="mq-sub">Une ligne par voie, une colonne par étape : on lit de gauche à droite. '
             'Chaque case : les compétences à maîtriser (cumulées), les personnes qui y sont, la part qui a une formation.</p>' + h +
             '<p class="mq-note" style="margin-top:14px"><b>Hors chemin — rôles du réseau</b> (' + str(len(R['reseau'])) + ') : ' + e(reseau) + '</p>' + manques(ref, comp, eff))
    page('01b-carte-grille', 'carte', 'Vue réseau', 'Carte des métiers', corps, '<a class="btn">Rafraîchir</a>')


def m1c_effectifs(ref, v25, comp, eff):
    """Variante C : les personnes d'abord — où est l'équipe sur le chemin."""
    R = chemin_reseau(ref, v25)
    fam = {'o': ['vente', 'boul', 'trait'], 'c': ['adjoint', 'manager'], 'e': ['candidat', 'franchise', 'multi', 'locataire']}
    nb = {f: sum(personnes(eff, n['id']) for k in ks if R[k] for n in R[k]['niveaux']) for f, ks in fam.items()}
    sans, tot = eff.get('sansPoste', 0), max(eff.get('actifs', 0), 1)
    parts = (('o', nb['o'], 'opérationnel', 'var(--accent)'), ('c', nb['c'], 'cadre et direction', '#a8665f'), ('e', nb['e'], 'entrepreneurs', '#c9a227'), ('s', sans, 'sans poste dans le panel', '#d9d3ca'))
    pile = ''.join('<div class="' + c + '" style="width:' + str(round(v * 100 / tot)) + '%">' + (str(v) if v * 100 / tot >= 4 else '') + '</div>' for c, v, lib, _ in parts if v)
    pile += '</div><div class="legende2" style="margin-top:8px">' + ''.join('<span><i style="background:' + col + ';height:10px;width:10px;border-radius:3px"></i>' + str(v) + ' ' + lib + '</span>' for _, v, lib, col in parts)
    hero = ('<div class="hero"><div><h2>' + str(eff.get('actifs', 0)) + ' personnes actives dans ' + str(eff.get('magasins', 0)) + ' magasins</h2>'
            '<p class="muted" style="margin:0">' + str(tot - sans) + ' sont placées sur la carte ; ' + str(sans) + ' n’ont pas encore de poste dans le panel.</p><div class="pile">' + pile + '</div></div>'
            '<a class="btn on">Placer les ' + str(sans) + ' personnes →</a></div>')

    def carte_poste(p, titre=None):
        if not p:
            return ''
        et = niveaux_cumules(p)
        segs = ''.join('<div><div class="b' + (' p' if personnes(eff, n['id']) else '') + '">' + str(personnes(eff, n['id'])) + '</div><div class="l">' + e(n['nom']) + '</div></div>' for n, _, _ in et)
        total = len(et[-1][2]) if et else 0
        avec = sum(1 for c in (et[-1][2] if et else []) if comp.get(c, {}).get('fo'))
        nbp = sum(personnes(eff, n['id']) for n in p['niveaux'])
        return ('<div class="pc3"><div class="t">' + e(titre or p['nom'].strip()) + '<span>' + str(nbp) + ' pers.</span></div><div class="segs">' + segs + '</div>'
                '<div class="d">' + (str(total) + ' compétences au dernier niveau · ' + str(round(avec * 100 / total)) + ' % avec formation' if total else '<span style="color:var(--danger)">compétences à définir</span>') + '</div></div>')

    cols = ('<div class="cols3"><div><div class="cat" style="margin:0 0 8px">Opérationnel</div>' + carte_poste(R['vente'], 'Vente') + carte_poste(R['boul'], 'Boulangerie – Pâtisserie') + carte_poste(R['trait'], 'Traiteur') + '</div>'
            '<div><div class="cat" style="margin:0 0 8px">Cadre et direction</div>' + carte_poste(R['adjoint']) + carte_poste(R['manager']) +
            '<p class="mq-note">Rôles du réseau (' + str(len(R['reseau'])) + ') : hors chemin.</p></div>'
            '<div><div class="cat" style="margin:0 0 8px">Devenir entrepreneur</div>' + carte_poste(R['candidat']) + carte_poste(R['franchise']) + carte_poste(R['multi']) + carte_poste(R['locataire']) + '</div></div>')
    corps = (hero + '<h2 class="mq-h">Où est l’équipe</h2><p class="mq-sub">Chaque poste, ses niveaux de gauche à droite ; dans chaque case, le nombre de personnes à ce niveau aujourd’hui.</p>' + cols + manques(ref, comp, eff))
    page('01c-carte-effectifs', 'carte', 'Vue réseau', 'Carte des métiers', corps, '<a class="btn">Rafraîchir</a>')


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
    f = os.path.join(d, 'effectifs.json')
    eff = json.load(open(f, encoding='utf-8')) if os.path.exists(f) else {}
    # La carte des métiers : trois propositions (la première version, 01-carte-des-metiers, est écartée).
    m1a_metro(ref, v25, comp, eff)
    m1b_grille(ref, v25, comp, eff)
    m1c_effectifs(ref, v25, comp, eff)
    m2_fiche(ref, v25, comp)
    m3_matrice_comp(ref, comp)
    m4_matrice_fo(v25)
    m5_equipe(ref)
    print('7 maquettes écrites dans', ICI)


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(sys.argv[1])
