#!/usr/bin/env python3
"""Maquettes « fiche d'une formation dans le manuel, sur tablette » (1er nov. 2026).

Quatre propositions pour #/manuel/formation/FO-29/partie/preouverture, avec les VRAIES données
de FO-29 (src/data/referentiel_v25.json : la formation, sa compétence, la rubrique du manuel
D6 › Recrutement, le bloc B5 du Plan Gérant). Les personnes sont nommées par leur rôle.
    python3 docs/maquettes/manuel-formation/generer.py && node docs/maquettes/manuel-formation/captures.js
"""
import html, json, os

ICI = os.path.dirname(os.path.abspath(__file__))
RACINE = os.path.normpath(os.path.join(ICI, '..', '..', '..'))
CSS = os.path.relpath(os.path.join(RACINE, 'public', 'formation', 'formation.css'), ICI)
V = json.load(open(os.path.join(RACINE, 'src', 'data', 'referentiel_v25.json'), encoding='utf-8'))
e = html.escape

FO = next(f for f in V['formations'] if f['code'] == 'FO-29')
COMP = next(c for c in V['competences'] if c.get('formation') == 'FO-29')
RUB = next(sc for d in V['manuel'] for sc in d.get('sousCategories', []) if 'FO-29' in (sc.get('formations') or []))
DOM = next(d for d in V['manuel'] if RUB in d.get('sousCategories', []))
PLAN, BLOC = next((p, b) for p in V['plans'] for b in p['blocs'] if 'FO-29' in b.get('formations', []))
POSTES = {p['code']: p['nom'] for p in V['postes']}
NOMS = {'P9': 'Locataire-gérant', 'P12': 'Multi-boutique'}
OBLIG = [NOMS.get(k, POSTES[k]) for k, v in FO['attendu'].items() if v == 'O']
RECO = [NOMS.get(k, POSTES[k]) for k, v in FO['attendu'].items() if v == 'R']
AUTRES = [next(f for f in V['formations'] if f['code'] == c) for c in BLOC['formations'] if c != 'FO-29']
NOM = FO['nom'].replace('Formation ', '')
RESUME = RUB['description']
CONTROLE = FO['controleur'].split(' (')[0]          # le rôle, sans le nom de la personne
MO = FO['procedure'].split(' — ')[0]
PRIO = {'XP': 'Expérience client', 'LABOUR-COST': 'Coût du personnel', 'TRAFIC': 'Trafic', 'RECURRENCE': 'Récurrence', 'FOOD-COST': 'Coût matière', 'OVERHEAD-COST': 'Frais généraux'}
ETAPES = [('Théorie', 'Les bases du recrutement : sourcing, annonce, entretien.', 'Quiz'),
          ('Pratique', 'Rédiger une annonce et préparer une grille d’entretien.', 'Exercice'),
          ('Mise en situation', 'Mener un entretien réel, accompagné.', 'Observation'),
          ('Validation', 'Le ' + CONTROLE + ' valide la compétence.', 'Signature'),
          ('Re-évaluation J+30', 'Un mois après : l’intégration a-t-elle tenu ?', 'Suivi')]

def chrome(titre, corps):
    parts = [('1', 'Pré-ouverture', True), ('2', 'Ouverture', False), ('3', 'Opérationnel', False), ('4', 'Formation continue', False)]
    return ('<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>' + e(titre) + '</title>'
            '<link rel="stylesheet" href="' + CSS + '"><link rel="stylesheet" href="maquette.css"></head><body>'
            '<header class="tb-top"><div><p class="k">Formation · Pré-ouverture</p><h1>Manuel opératoire</h1></div><span class="q">Quitter le mode tablette</span></header>'
            '<nav class="tb-parts">' + ''.join('<a class="' + ('on' if on else '') + '"><i>' + n + '</i>' + e(t) + '</a>' for n, t, on in parts) + '</nav>'
            '<main class="tb-body"><p class="tb-fil">Manuel › Pré-ouverture › <b>' + FO['code'] + ' · ' + e(NOM) + '</b></p>' + corps + '</main></body></html>')

def ul_check(items):
    return '<ul class="lst">' + ''.join('<li><span class="ck"></span><span>' + e(x) + '</span></li>' for x in items) + '</ul>'
def ul_pts(items):
    return '<ul class="lst">' + ''.join('<li><span class="pt"></span><span>' + e(x) + '</span></li>' for x in items) + '</ul>'
def ul_mesures(items):
    return '<ul class="lst">' + ''.join('<li><span class="mesure">%</span><span>' + e(x) + '</span></li>' for x in items) + '</ul>'
def tags(l, cls='a'):
    return ' '.join('<span class="tag ' + cls + '">' + e(x) + '</span>' for x in l)

# A — une fiche à lire, de haut en bas
A = ('<div class="a-col"><div class="a-tete"><span class="code">' + FO['code'] + '</span> <span class="tag">' + e(DOM['nom']) + ' › ' + e(RUB['nom']) + '</span>'
     '<h2>' + e(NOM) + '</h2><p class="lead">' + e(RESUME) + '</p></div>'
     '<div class="a-faits"><div><b>Annuelle</b><span>fréquence</span></div><div><b>5 étapes</b><span>pour la valider</span></div>'
     '<div><b>Le franchisé</b><span>la suit</span></div><div><b>' + e(CONTROLE) + '</b><span>la valide</span></div></div>'
     '<div class="a-actions"><span class="btn p">Ouvrir la procédure ' + e(MO) + '</span><span class="btn">Imprimer</span></div>'
     '<section class="a-sec"><h3><i>1</i>Ce que vous saurez faire</h3><p><b>' + e(COMP['nom']) + '</b> — ' + e(COMP['description']) + '. <span class="muted">Niveau attendu : ' + e(COMP['niveau']) + '.</span></p></section>'
     '<section class="a-sec"><h3><i>2</i>Au programme</h3>' + ul_pts(BLOC['contenu']) + '</section>'
     '<section class="a-sec"><h3><i>3</i>Comment on la valide</h3><ol class="a-etapes">' +
     ''.join('<li data-n="' + str(i + 1) + '"><b>' + e(t) + '</b><span>' + e(d) + '</span></li>' for i, (t, d, _) in enumerate(ETAPES)) + '</ol></section>'
     '<section class="a-sec"><h3><i>4</i>En boutique, ensuite</h3>' + ul_check(BLOC['checklist']) + '</section>'
     '<section class="a-sec"><h3><i>5</i>Pour qui</h3><p>' + tags(OBLIG) + ' ' + tags(RECO, 'r') + '</p><p class="muted" style="font-size:14px">Pointillés : recommandée.</p></section></div>')

# B — les cinq étapes en tête
B = ('<div class="b-tete"><div><span class="code">' + FO['code'] + '</span><h2>' + e(NOM) + '</h2><p>' + e(RESUME) + '</p></div>'
     '<div style="text-align:right"><span class="tag">Annuelle</span> <span class="tag">Suivie par le franchisé</span> <span class="tag">Validée par le ' + e(CONTROLE) + '</span></div></div>'
     '<div class="b-steps">' + ''.join('<div class="b-step' + (' on' if i == 0 else '') + '"><div class="n">' + str(i + 1) + '</div><b>' + e(t) + '</b><span>' + e(d) + '</span><em>' + ('Commencer ›' if i == 0 else e(k)) + '</em></div>'
                                       for i, (t, d, k) in enumerate(ETAPES)) + '</div>'
     '<div class="b-cols"><section class="box"><h2>Ce que vous saurez faire</h2><p style="font-size:16px;line-height:1.55;margin:0 0 14px"><b>' + e(COMP['nom']) + '</b> — ' + e(COMP['description']) + '.</p>'
     '<h2 style="margin-top:6px">Au programme</h2>' + ul_pts(BLOC['contenu']) + '</section>'
     '<section class="box"><h2>En boutique, ensuite</h2>' + ul_check(BLOC['checklist']) + '</section></div>'
     '<div class="b-bas"><section class="box"><h2>Pour qui</h2><p style="margin:0">' + tags(OBLIG) + ' ' + tags(RECO, 'r') + '</p></section>'
     '<section class="box"><h2>À suivre avec</h2>' + ''.join('<p style="margin:0 0 8px;font-size:15.5px"><span class="code">' + f['code'] + '</span> ' + e(f['nom'].replace('Formation ', '')) + '</p>' for f in AUTRES) + '</section></div>')

# C — tableau de bord
C = ('<div class="c-hero"><div><span class="code blanc">' + FO['code'] + '</span> <span class="tag" style="background:rgba(255,255,255,.18);color:#fff">1 · Pré-ouverture</span>'
     '<h2>' + e(NOM) + '</h2><p>' + e(RESUME) + '</p></div><span class="btn">Procédure ' + e(MO) + ' ›</span></div>'
     '<div class="c-kpis"><div class="c-kpi"><b>Annuelle</b><span>Fréquence</span><small>à refaire chaque année</small></div>'
     '<div class="c-kpi"><b>5</b><span>Étapes de validation</span><small>validée par le ' + e(CONTROLE) + '</small></div>'
     '<div class="c-kpi"><b>' + str(len(OBLIG) + len(RECO)) + '</b><span>Postes concernés</span><small>' + str(len(OBLIG)) + ' obligatoires · ' + str(len(RECO)) + ' recommandé</small></div>'
     '<div class="c-kpi"><b>' + str(len(BLOC['kpis'])) + '</b><span>Indicateurs suivis</span><small>' + e(', '.join(PRIO[p] for p in FO['priorites'])) + '</small></div></div>'
     '<div class="c-grid"><section class="box"><h2>Au programme</h2>' + ul_pts([COMP['nom'] + ' (' + COMP['niveau'].lower() + ')'] + BLOC['contenu']) + '</section>'
     '<section class="box"><h2>En boutique, ensuite</h2>' + ul_check(BLOC['checklist']) + '</section>'
     '<section class="box"><h2>Ce qu’on mesure</h2>' + ul_mesures(BLOC['kpis']) + '</section></div>'
     '<div class="c-grid" style="grid-template-columns:1fr 1fr;margin-top:14px"><section class="box"><h2>Pour qui</h2><div class="c-pour">' +
     ''.join('<span>' + e(x) + '</span><span class="tag a">Obligatoire</span>' for x in OBLIG) + ''.join('<span>' + e(x) + '</span><span class="tag r">Recommandée</span>' for x in RECO) + '</div></section>'
     '<section class="box"><h2>Dans le ' + e(PLAN['nom']) + '</h2><p class="muted" style="margin:-4px 0 8px;font-size:14px">Bloc ' + BLOC['code'] + ' · ' + e(BLOC['nom']) + ', avec :</p>' +
     ''.join('<div class="c-lien"><span class="code">' + f['code'] + '</span><span>' + e(f['nom'].replace('Formation ', '')) + '</span></div>' for f in AUTRES) + '</section></div>')

# D — quatre onglets
D = ('<div class="d-tete"><div><span class="code">' + FO['code'] + '</span> <span class="tag">' + e(DOM['nom']) + ' › ' + e(RUB['nom']) + '</span><h2>' + e(NOM) + '</h2><p class="lead" style="margin:0;font-size:17px">' + e(RESUME) + '</p></div>'
     '<div class="d-faits"><span>Fréquence</span><b>Annuelle</b><span>Suivie par</span><b>Le franchisé</b><span>Validée par</span><b>' + e(CONTROLE) + '</b><span>Procédure</span><b>' + e(MO) + '</b></div></div>'
     '<nav class="d-onglets"><a class="on">Comprendre<small>pourquoi, pour qui</small></a><a>Se former<small>au programme</small></a><a>Valider<small>5 étapes</small></a><a>Appliquer<small>checklist, mesures</small></a></nav>'
     '<div class="d-cols"><section class="box"><h2>Pourquoi cette formation</h2><p style="font-size:16.5px;line-height:1.6;margin:0 0 12px">' + e(RESUME) + ' Elle compte pour : <b>' + e(', '.join(PRIO[p].lower() for p in FO['priorites'])) + '</b>.</p>'
     '<h2>Ce que vous saurez faire</h2><p style="font-size:16.5px;line-height:1.6;margin:0"><b>' + e(COMP['nom']) + '</b> — ' + e(COMP['description']) + '. <span class="muted">Niveau ' + e(COMP['niveau'].lower()) + '.</span></p></section>'
     '<section class="box"><h2>Pour qui</h2><p style="margin:0 0 10px">' + tags(OBLIG) + '</p><p style="margin:0">' + tags(RECO, 'r') + ' <span class="muted" style="font-size:14px">recommandée</span></p></section></div>'
     '<div class="d-suite"><span class="muted">Suite : <b style="color:var(--ink)">Se former</b> — ' + e(', '.join(BLOC['contenu'])) + '</span><span class="btn p">Se former ›</span></div>')

PROPS = [('A-fiche-a-lire', 'A · Une fiche à lire', A), ('B-cinq-etapes', 'B · Les cinq étapes', B), ('C-tableau-de-bord', 'C · Tableau de bord', C), ('D-onglets', 'D · Quatre onglets', D)]
for f, t, corps in PROPS:
    open(os.path.join(ICI, f + '.html'), 'w', encoding='utf-8').write(chrome(t, corps))
print('ok :', ', '.join(f for f, _, _ in PROPS))
