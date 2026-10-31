#!/usr/bin/env python3
"""Maquettes « Parcours compétences » — génère les pages HTML à partir de donnees.json.

    python3 -I docs/maquettes/parcours-competences/generer.py
    node docs/maquettes/parcours-competences/captures.js      # les PNG

Règle : les DONNÉES DES API (panel) ONT PRIORITÉ — noms des postes et niveaux,
compétences exigées par niveau, évaluations, compétences rattachées. L'Excel v25
ne fait que compléter ce que le panel n'a pas : nom de la formation FO-xx,
matrice obligatoire / recommandée, ordre des plans, priorités business.
Extraits du panel ci-dessous (lus le 31/10/2026) ; la personne et ses dates
sont des EXEMPLES. Le style est celui du module
Formation (= module Marketing) : ../../../public/formation/formation.css.
"""
import html
import json
import os

ICI = os.path.dirname(os.path.abspath(__file__))
D = json.load(open(os.path.join(ICI, 'donnees.json'), encoding='utf-8'))
F = {f['code']: f for f in D['formations']}
P = {p['code']: p['nom'] for p in D['postes']}
CSS = '../../../public/formation/formation.css'
LOGO = '../../../public/formation/img/logo.png'


def e(s):
    return html.escape(str(s), quote=True)


def obligatoires(poste):
    return [f['code'] for f in D['formations'] if f['attendu'].get(poste) == 'O']


# --- Extraits du PANEL (API, prioritaires), lus le 31/10/2026.
# Le niveau visé, et ce que le panel exige pour lui (/position-levels/{id}/competencies) :
NIVEAU_API = {
    'P4': ('Opérateur Vente', 'Responsable d’équipe (Crew Chief)', ['Faire les horaires', 'Gérer remplacements', 'Former Niveau 1', 'Former Niveau 2']),
    'P7': ('Adjoint de Manager', 'Adjoint Manager', ['Piloter la qualité globale et la conformité', 'Optimiser les stocks, commandes et coûts']),
    'P8': ('Manager', 'Manager', ['Piloter les KPI (CA, marge, coûts, pertes)', 'Manager et faire monter les équipes'] + ['…'] * 6),
    'P10': ('Candidat Franchisé', 'Candidat Franchisé', ['Savoir vendre', 'Démarcher en B2B'] + ['…'] * 9),
}
# Les 5 évaluations du panel pour FO-01 (/operational-framework/trainings « [FO-01] … ») :
EVALS_FO01 = ['Évaluation théorique vente', 'Évaluation pratique caisse', 'Mise en situation upselling', 'Validation niveau dans Franchise Buddy', 'Plan d’action correctif + re-évaluation J+30']
# Les compétences que le panel rattache à ces évaluations (/competencies/{id}?include=trainings) :
COMP_FO01 = ['Cross-selling & up-selling', 'Donner une commande au client', 'Encoder TooGoodToGo', 'Encoder commandes', 'Être formé au contact client',
             'Fidéliser le client', 'Formation produit', 'Gérer la file', 'Gérer réclamations', 'Liste commandes matin', 'Prendre les commandes',
             'Procédure erreurs / changements', 'Régler un souci de commande', 'Vente active', 'Être formé au système de caisse']
# Les niveaux du panel qui exigent ces compétences :
NIVEAUX_FO01 = ['Opérateur Vente · Observation et Introduction', 'Opérateur Vente · Débutant', 'Opérateur Vente · Équipier confirmé',
                'Opérateur Boulangerie – Pâtisserie · 3 niveaux', 'Opérateur Traiteur · Débutant']

# --- L'exemple : Julie, Opérateur Vente · Équipier confirmé, vise Responsable d'équipe (Crew Chief).
FAITES = [c for c in obligatoires('P3') if c not in ('FO-17', 'FO-46')]          # 10 formations validées
CIBLE = 'P4'
# Le chemin : FO-06 d'abord exigée par le PANEL (les 4 compétences du niveau y sont rattachées),
# puis les obligatoires de la matrice v25 qui manquent, dans l'ordre du plan PF-VEND.
A_FAIRE = ['FO-18', 'FO-06', 'FO-15', 'FO-17', 'FO-46', 'FO-26', 'FO-45', 'FO-21']
DATES = ['20 oct.', '17 nov.', '8 déc.', '5 janv.', '26 janv.', '16 févr.', '9 mars', '30 mars']
NOM_CIBLE = {k: v[1] for k, v in NIVEAU_API.items()}
COURT = {
    'FO-01': 'Vendeur — initiale', 'FO-06': 'Animer l’équipe', 'FO-02': 'HACCP & hygiène', 'FO-04': 'Expérience client', 'FO-11': 'Barista',
    'FO-13': 'Vente conseil', 'FO-14': 'Caisse', 'FO-15': 'Service premium', 'FO-16': 'Allergènes', 'FO-17': 'FR / NL / EN',
    'FO-18': 'Client mécontent', 'FO-19': 'Upselling', 'FO-20': 'Vitrine', 'FO-21': 'Chef de section', 'FO-26': 'Réception marchandises',
    'FO-44': 'Réglementation', 'FO-45': 'Animation & saisons', 'FO-46': 'Click & Collect',
}
PRIO = {'TRAFIC': 'Trafic', 'RECURRENCE': 'Récurrence', 'XP': 'Expérience', 'FOOD-COST': 'Food cost', 'LABOUR-COST': 'Labour cost', 'OVERHEAD-COST': 'Frais fixes'}

PAGE = """<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>{titre}</title>
<link rel="stylesheet" href="{css}"><link rel="stylesheet" href="maquette.css"></head><body class="{corps}">{contenu}</body></html>"""


def page(nom, titre, contenu, corps=''):
    with open(os.path.join(ICI, nom), 'w', encoding='utf-8') as fh:
        fh.write(PAGE.format(titre=e(titre), css=CSS, contenu=contenu, corps=corps))


def icone(d):
    return f'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{d}"/></svg>'


# ─────────────────────────────── téléphone ───────────────────────────────

def telephone(etape, kicker, titre, sous, corps, cta, total=3):
    barres = ''.join(f'<span class="{"on" if i < etape else ""}"></span>' for i in range(total)) if etape else ''
    return f"""<div class="phone"><div class="ph-status"><span>9:41</span><span>●●● ▮</span></div>
<div class="ph-head"><img src="{LOGO}" alt=""><span class="ph-close">{'Étape ' + str(etape) + ' / ' + str(total) if etape else 'Mon parcours'}</span></div>
{('<div class="ph-steps">' + barres + '</div>') if barres else ''}
<div class="ph-body"><p class="topbar__kicker">{e(kicker)}</p><h1 class="ph-title">{titre}</h1>{('<p class="muted ph-sous">' + sous + '</p>') if sous else ''}{corps}</div>
{('<div class="ph-cta"><button type="button">' + cta + '</button></div>') if cta else ''}{'' if cta else ''}</div>"""


def ecran1():
    def carte(code, groupe_on=False, note=''):
        todo = A_FAIRE if code == CIBLE else [c for c in obligatoires(code) if c not in FAITES]
        mois = round(len(todo) * 3 / 4.3)
        on = code == CIBLE
        poste, niveau, comps = NIVEAU_API[code]
        # Un poste à un seul niveau : son nom suffit (pas de « Adjoint Manager · Adjoint de Manager »).
        plusieurs = code == 'P4'
        titre = niveau if plusieurs else poste
        return (f'<li><div class="choice-card{" is-on" if on else ""}"><strong>{e(titre)}</strong>'
                f'<span class="muted">{e(poste) + " · " if plusieurs else ""}{len(comps)} compétences · {len(todo)} formations · environ {mois} mois{note}</span></div></li>')
    corps = ('<div class="ph-now"><span class="chip chip--soft">Aujourd’hui</span> Opérateur Vente · Équipier confirmé</div>'
             '<div class="section-label">Dans ton métier</div><ul class="type-grid ph-grid">' + carte('P4') + '</ul>'
             '<div class="section-label">Encadrer le magasin</div><ul class="type-grid ph-grid">' + carte('P7') + carte('P8') + '</ul>'
             '<div class="section-label">Devenir entrepreneur</div><ul class="type-grid ph-grid">' + carte('P10') + '</ul>')
    return telephone(1, 'Mon parcours', 'Où veux-tu aller&nbsp;?', 'Choisis le poste que tu vises. On te prépare le chemin.', corps, 'Continuer')


def ecran2():
    poste, niveau, comps = NIVEAU_API[CIBLE]
    lignes = ''.join(
        f'<li><span class="ph-check{" is-on" if i == 2 else ""}">{"✓" if i == 2 else ""}</span>'
        f'<span><strong>{e(c)}</strong><span class="muted">{"déjà vue ailleurs — ta manager confirmera" if i == 2 else "à apprendre"}</span></span></li>' for i, c in enumerate(comps))
    corps = (f'<div class="ph-ok">✓ <strong>{len(FAITES)} formations déjà validées</strong> <span class="muted">— elles comptent déjà</span></div>'
             f'<div class="section-label">Ce que le niveau demande</div>'
             '<p class="muted ph-note">Tu sais déjà le faire&nbsp;? Coche : ta manager le confirmera.</p>'
             f'<ul class="ph-list">{lignes}</ul>')
    return telephone(2, 'Mon parcours', 'Ce que tu sais déjà', e(niveau) + ' · ' + e(poste), corps, 'Continuer')


def frise_mini(n):
    pts = ''.join(f'<span class="mini__pt{" is-on" if i == 0 else ""}"></span>' for i in range(n))
    return f'<div class="mini">{pts}<span class="mini__flag">⚑</span></div>'


def ecran3():
    rythmes = ''.join(f'<span class="filter{" is-on" if r == "Normal" else ""}">{r}</span>' for r in ('Tranquille', 'Normal', 'Rapide'))
    corps = (f'<div class="card ph-resume"><div class="ph-big">{len(A_FAIRE) - 1}<span> formations</span></div>'
             '<p class="muted">+ 1 déjà commencée · objectif <strong>avril 2027</strong></p>'
             f'{frise_mini(len(A_FAIRE))}</div>'
             f'<div class="section-label">Ton rythme</div><div class="filters__row ph-rythme">{rythmes}</div>'
             '<p class="muted ph-note">Normal = une formation toutes les 3 semaines.</p>'
             '<div class="section-label">Qui t’accompagne</div>'
             '<div class="ph-coach"><span class="lead__initials">SO</span><span><strong>Sophie</strong><span class="muted">ta manager — valide chaque étape</span></span></div>')
    return telephone(3, 'Mon parcours', 'Ton parcours est prêt', 'Vers <strong>' + e(NOM_CIBLE[CIBLE]) + '</strong>.', corps, 'C’est parti')


def ecran_frise():
    jal = []
    for c, quand in (('FO-13', 'sept.'), ('FO-19', 'oct.')):
        jal.append(('fait', c, quand))
    jal.append(('encours', 'FO-18', 'maintenant'))
    for c, quand in zip(A_FAIRE[1:], DATES[1:]):
        jal.append(('avenir', c, quand))
    noeuds = ''.join(
        f'<div class="jalon {k}"><span class="jalon__dot">{"✓" if k == "fait" else ""}</span>'
        f'<span class="jalon__date">{e(q)}</span><span class="jalon__nom">{e(COURT[c])}</span></div>' for k, c, q in jal)
    noeuds += '<div class="jalon cible"><span class="jalon__dot">⚑</span><span class="jalon__date">avril</span><span class="jalon__nom">Responsable d’équipe</span></div>'
    evals = [('Quiz théorique', True), ('Mise en situation', True), ('Jeu de rôle client pressé', False), ('Validation par Sophie', False), ('Re-évaluation J+30', False)]
    ev = ''.join(f'<li class="{"is-done" if ok else ""}"><span class="ph-check{" is-on" if ok else ""}">{"✓" if ok else ""}</span>{e(n)}</li>' for n, ok in evals)
    pct = round(len(FAITES) * 100 / (len(FAITES) + len(A_FAIRE)))
    corps = (f'<div class="ph-goal"><div><span class="muted">Objectif</span><strong>{e(NOM_CIBLE[CIBLE])}</strong></div><span class="ph-pct">{pct}<small>%</small></span></div>'
             f'<div class="progress"><div class="progress__fill" style="width:{pct}%"></div></div>'
             f'<p class="muted ph-note">{len(FAITES)} formations validées sur {len(FAITES) + len(A_FAIRE)} · dans les temps</p>'
             f'<div class="frise"><div class="frise__track">{noeuds}</div></div>'
             '<div class="card ph-now-card"><p class="topbar__kicker">En cours</p><strong class="ph-now-title">Gérer un client mécontent</strong>'
             '<p class="muted">FO-18 · 2 sur 5 évaluations</p><ul class="ph-evals">' + ev + '</ul></div>')
    return telephone(0, 'Suivi', 'Mon parcours', '', corps, '')


# ─────────────────────────────── bureau (module Formation) ───────────────────────────────

def coquille(actif, kicker, titre, corps):
    nav = [('Pilotage', [('tableau', 'Tableau de bord')]),
           ('Référentiel', [('postes', 'Postes & sous-postes'), ('competences', 'Compétences'), ('formations', 'Formations'), ('procedures', 'Procédures opératoires')]),
           ('Équipes', [('parcours', 'Parcours des équipes'), ('equipes', 'Compétences des équipes')]),
           ('Manuel opératoire', [('manuel', 'Manuel en ligne')])]
    g = ''.join('<div class="sidebar__group"><div class="sidebar__group-label">' + e(t) + '</div>' + ''.join(
        f'<a class="sidebar__item{" is-active" if k == actif else ""}">{icone("M4 6h16M4 12h16M4 18h10")}{e(lib)}</a>' for k, lib in it) + '</div>' for t, it in nav)
    return (f'<div class="workspace"><nav class="sidebar"><div class="sidebar__head"><img src="{LOGO}" class="sidebar__logo" alt=""><div class="sidebar__kicker">Module Formation</div></div>'
            f'<div class="sidebar__back"><a>{icone("M19 12H5M12 19l-7-7 7-7")}Retour au cockpit</a></div><div class="sidebar__groups">{g}</div></nav>'
            f'<div class="workspace__main"><header class="topbar"><div><p class="topbar__kicker">{e(kicker)}</p><h1>{e(titre)}</h1></div></header>'
            f'<main class="app__body">{corps}</main></div></div>')


def points(fait, encours, total, retard=False):
    s = ''.join('<span class="pt fait"></span>' for _ in range(fait))
    s += ''.join(f'<span class="pt {"retard" if retard else "encours"}"></span>' for _ in range(encours))
    s += ''.join('<span class="pt"></span>' for _ in range(total - fait - encours))
    return f'<span class="pts">{s}<span class="pt-flag">⚑</span></span>'


def ecran_equipe():
    gens = [
        ('JU', 'Julie M.', 'Opérateur Vente · Équipier confirmé', 'Responsable d’équipe (Crew Chief)', 10, 1, 18, 'Gérer un client mécontent · 2/5', 'Dans les temps', 'ok'),
        ('KE', 'Kevin L.', 'Opérateur Boulangerie – Pâtisserie · Débutant', 'Équipier confirmé', 3, 1, 8, 'Viennoiserie & feuilletage', 'Dans les temps', 'ok'),
        ('IN', 'Inès T.', 'Opérateur Traiteur · Équipier confirmé – Froid', 'Responsable d’équipe', 6, 1, 10, 'Sandwicherie & formules', 'En retard · 2 sem.', 'ko'),
        ('TH', 'Thomas D.', 'Candidat Franchisé', 'Franchisé', 7, 1, 23, 'Étude de marché & emplacement', 'Dans les temps', 'ok'),
        ('SA', 'Sarah V.', 'Adjoint de Manager', 'Manager', 20, 0, 24, 'À planifier : Recrutement & sourcing', 'À relancer', 'warn'),
    ]
    rows = ''.join(
        f'<tr class="row-link"><td><div class="lead"><span class="lead__initials">{i}</span><span><span class="lead__name">{e(n)}</span><p class="muted">{e(a)}</p></span></div></td>'
        f'<td>{e(c)}</td><td>{points(f, ec, t, s == "ko")}</td><td class="num">{round(f * 100 / t)} %</td><td>{e(nx)}</td>'
        f'<td><span class="chip {"chip--ok" if s == "ok" else "chip--ko" if s == "ko" else "chip--warn"}">{e(st)}</span></td></tr>'
        for i, n, a, c, f, ec, t, nx, st, s in gens)
    corps = ('<div class="filters"><div class="filters__row"><span class="filter is-on">Tout le réseau</span><span class="filter">Magasin exemple A</span><span class="filter">Magasin exemple B</span>'
             '<span class="filter">Candidats franchisés</span></div></div>'
             '<div class="grid"><section class="card"><h2>Parcours actifs</h2><p class="metric">5</p><p class="muted">4 employés · 1 candidat franchisé</p></section>'
             '<section class="card"><h2>Étapes validées ce mois</h2><p class="metric">9</p><p class="muted">sur 11 prévues</p></section>'
             '<section class="card"><h2>En retard</h2><p class="metric">1</p><p class="muted">une relance part à la personne et à son manager</p></section></div>'
             '<section class="card table-card"><h2>Les parcours</h2><div class="table-scroll"><table><thead><tr><th>Personne</th><th>Vers</th><th>Frise</th><th class="num">Avancement</th><th>Étape en cours</th><th>Statut</th></tr></thead>'
             f'<tbody>{rows}</tbody></table></div><p class="muted" style="margin-top:12px;font-size:12px">Personnes et dates d’exemple · un clic ouvre la frise complète de la personne.</p></section>')
    return coquille('parcours', 'Vue Réseau', 'Parcours des équipes', corps)


def ecran_fiche():
    f = F['FO-01']
    # Obligatoire pour : les niveaux du PANEL d'abord, puis les postes de la matrice v25 que le panel n'exige pas encore.
    noms_panel = {'P3': None, 'P4': None, 'P7': 'Adjoint de Manager', 'P8': 'Manager', 'P9': 'Locataire-gérant', 'P10': 'Candidat Franchisé',
                  'P11': 'Franchisé', 'P12': 'Franchisé multi-boutique'}
    complement = [noms_panel[k] for k, v in f['attendu'].items() if v == 'O' and noms_panel.get(k)]
    # Un plan par ligne, ses blocs regroupés (le plan Étudiant cite FO-01 deux fois).
    plans = [(p['nom'], ', '.join(b['code'] + ' ' + b['nom'] for b in p['blocs'] if 'FO-01' in b['formations']))
             for p in D['plans'] if any('FO-01' in b['formations'] for b in p['blocs'])]
    prio = ''.join(f'<li class="chip chip--lever">{e(PRIO[x])}</li>' for x in f['priorites'])
    corps = (f'<a class="filter back">← Formations</a><div class="fm-head"><div><h2 class="screen-subtitle">{e(f["nom"])}</h2>'
             f'<p class="muted">FO-01 · {e(f["frequence"])} · contrôlée par {e(f["controleur"])} · {e(f["procedure"].split(" — ")[0])}</p></div>'
             f'<ul class="chips">{prio}</ul></div>'
             '<div class="fm-cols" style="margin-top:16px">'
             '<section class="card"><h2>Obligatoire pour</h2><ul class="chips">'
             + ''.join(f'<li class="chip chip--accent">{e(x)}</li>' for x in NIVEAUX_FO01)
             + ''.join(f'<li class="chip chip--soft">{e(x)}</li>' for x in complement) + '</ul></section>'
             '<section class="card"><h2>Comment on la valide · 5 étapes</h2><ol class="fiche-ol">' + ''.join(f'<li>{e(x)}</li>' for x in EVALS_FO01) + '</ol></section>'
             '</div><div class="fm-cols" style="margin-top:16px">'
             f'<section class="card"><h2>Ce qu’on apprend · {len(COMP_FO01)} compétences</h2><ul class="chips">'
             + ''.join(f'<li class="chip chip--soft">{e(x)}</li>' for x in COMP_FO01)
             + '</ul><p class="muted" style="margin-top:10px;font-size:12.5px">Cochées pour la personne dès que la formation est validée.</p></section>'
             '<section class="card"><h2>Dans les plans</h2><ul class="fiche-ul">' + ''.join(f'<li>{e(a)} · <span class="muted">{e(b)}</span></li>' for a, b in plans) + '</ul></section>'
             '</div><p class="muted fusion">Une seule fiche. Le panel d’abord (évaluations, compétences, niveaux en bordeaux) ; '
             'le référentiel v25 complète ce qu’il n’a pas (nom, fréquence, priorités, postes en beige, plans).</p>')
    return coquille('formations', 'Référentiel', 'Formations', corps)


def ecran_rail():
    groupes = [('ERP franchisé', ['Développement commercial', 'Prospection', 'Tablette vendeuses', 'Fidélité']),
               ('Application employé', ['Page admin', 'Page franchisé (magasin)', 'Application ↗']),
               ('Formation', ['Module Formation ↗', 'Manuel opératoire ↗', '★Parcours compétences ↗']),
               ('Administration', ['Paramètres', 'Journal & diagnostic'])]
    h = ''.join('<div class="rail__g"><div class="rail__t">' + e(t) + '</div>' + ''.join(
        f'<div class="rail__i{" is-new" if x.startswith("★") else ""}">{e(x.lstrip("★"))}{"<span class=rail__new>nouveau</span>" if x.startswith("★") else ""}</div>' for x in it) + '</div>' for t, it in groupes)
    return f'<div class="rail">{h}</div><p class="rail-note">Pour l’instant, une entrée dans le rail du cockpit — elle passera dans l’application employé ensuite.</p>'


page('01-rail.html', 'Rail du cockpit', ecran_rail(), 'rail-body')
page('02-assistant-1-destination.html', 'Assistant 1 — Où veux-tu aller', ecran1(), 'phone-body')
page('03-assistant-2-acquis.html', 'Assistant 2 — Ce que tu as déjà', ecran2(), 'phone-body')
page('04-assistant-3-parcours.html', 'Assistant 3 — Ton parcours', ecran3(), 'phone-body')
page('05-frise-suivi.html', 'Frise de suivi', ecran_frise(), 'phone-body')
page('06-parcours-equipes.html', 'Parcours des équipes', ecran_equipe())
page('07-fiche-formation.html', 'Fiche formation fusionnée', ecran_fiche())
page('planche.html', 'Planche — parcours compétences',
     '<div class="planche">' + ecran1() + ecran2() + ecran3() + ecran_frise() + '</div>', 'planche-body')
print('maquettes écrites dans', ICI)
