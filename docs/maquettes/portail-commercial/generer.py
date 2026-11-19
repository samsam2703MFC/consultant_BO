"""Maquettes du portail Commercial (préfixe de tables sm_), 19/11/2026.

Six écrans, une coque commune (le rail du design system, docs/DESIGN_SYSTEM.md §8) :
  1 Tableau de bord des bureaux du webshop   4 Offres, bons et catalogues
  2 Leads Meta par magasin                    5 Échantillons commandés aux magasins
  3 Assistant « Nouveau bureau »              6 B2B : suivi des offres par secteur et par magasin

RÉEL : les quatre magasins et leur zone (annexe de la feuille de mission), les statuts des leads de l'ERP
(nouveau, vu, contacté, bureau créé, bureau connecté, perdu), les types d'offre de Prospection, la boîte
hello@atelierby.be. ILLUSTRÉ : entreprises, contacts, montants, compteurs.

    python3 docs/maquettes/portail-commercial/generer.py
    node docs/maquettes/portail-commercial/capturer.cjs
"""
import html
import os

ICI = os.path.dirname(os.path.abspath(__file__))
e = lambda s: html.escape(str(s), quote=False)

def ico(nom):
    p = {
        'retour': '<path d="M10 4 4 8l6 4M4 8h9"/>',
        'plus': '<path d="M8 3v10M3 8h10"/>',
        'grille': '<rect x="2.5" y="2.5" width="4.5" height="4.5" rx="1"/><rect x="9" y="2.5" width="4.5" height="4.5" rx="1"/><rect x="2.5" y="9" width="4.5" height="4.5" rx="1"/><rect x="9" y="9" width="4.5" height="4.5" rx="1"/>',
        'leads': '<path d="M2.5 4.5h11v8h-11z"/><path d="m2.5 5 5.5 4 5.5-4"/>',
        'bureau': '<path d="M3 13.5V3.5h6v10M9 6.5h4v7M5 6h2M5 8.5h2M5 11h2M11 9h0M11 11h0"/>',
        'offre': '<path d="M3 3h5l5 5-5 5-5-5z"/><circle cx="5.8" cy="5.8" r=".8"/>',
        'boite': '<path d="M2.5 5.5 8 3l5.5 2.5v6L8 14l-5.5-2.5z"/><path d="M2.5 5.5 8 8l5.5-2.5M8 8v6"/>',
        'courbe': '<path d="M2.5 13.5h11M3.5 11l3-3.5 2.5 2 4-5"/>',
        'carte': '<path d="M2.5 4.5 6 3l4 1.5 3.5-1.5v8.5L10 13l-4-1.5-3.5 1.5z"/><path d="M6 3v8.5M10 4.5V13"/>',
        'mail': '<rect x="2.5" y="3.5" width="11" height="9" rx="1.5"/><path d="m3 4.5 5 4 5-4"/>',
        'cle': '<circle cx="5.5" cy="10.5" r="2.5"/><path d="m7.3 8.7 5.2-5.2M11 5l1.5 1.5"/>',
    }[nom]
    return f'<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round">{p}</svg>'

RAIL = [
    ('Bureaux · webshop', [('tdb', 'grille', 'Tableau de bord', ''), ('leads', 'leads', 'Leads Meta', '8'), ('offres', 'offre', 'Offres, bons et catalogues', ''),
                           ('echantillons', 'boite', 'Échantillons', '')]),
    ('B2B', [('b2b', 'courbe', 'Suivi des offres', ''), ('prosp', 'carte', 'Prospection ↗', '')]),
    ('Réglages', [('modeles', 'mail', 'Modèles d’e-mail', ''), ('acces', 'cle', 'Accès et magasins', '')]),
]

def page(fichier, actif, kicker, titre, actions, corps, pied, large=False):
    groupes = ''.join(
        f'<div class="sidebar__group"><div class="sidebar__group-label">{e(g)}</div>'
        + ''.join(f'<div class="sidebar__item{" is-active" if k == actif else ""}">{ico(i)}<span>{e(l)}</span>{f"<span class=count>{c}</span>" if c else ""}</div>' for k, i, l, c in items)
        + '</div>' for g, items in RAIL)
    rail = f'''<aside class="sidebar">
  <div class="sidebar__head"><img class="sidebar__logo" src="../../../shared/public/assets/img/logo.png" alt="L’Atelier"><div class="sidebar__kicker">Module commercial</div></div>
  <div class="sidebar__back"><span>{ico('retour')}Retour à l’ERP</span></div>
  <div class="sidebar__brand">Réseau · 4 magasins<span class="sub">Corbais, Gosselies, Halle, Sombreffe</span></div>
  <div class="sidebar__action{" is-active" if actif == "nouveau" else ""}">{ico('plus')}Nouveau bureau</div>
  <div class="sidebar__groups">{groupes}</div>
  <div class="sidebar__account"><span>Compte commercial</span><span class="muted">hello@atelierby.be</span></div>
</aside>'''
    acts = ''.join(actions)
    doc = f'''<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>{e(titre)} — portail Commercial</title><link rel="stylesheet" href="ds.css"></head>
<body><div class="workspace">{rail}<div class="workspace__main">
<header class="topbar"><div><p class="topbar__kicker">{e(kicker)}</p><h1>{e(titre)}</h1></div><div class="topbar__actions">{acts}</div></header>
<div class="app__body"><div style="max-width:{'none' if large else '1180px'}">{corps}<p class="pied">{pied}</p></div></div>
</div></div></body></html>'''
    open(os.path.join(ICI, fichier), 'w', encoding='utf-8').write(doc)

MAG = ['Corbais', 'Gosselies', 'Halle', 'Sombreffe']
PIED_COMMUN = 'Maquette · portail Commercial (tables <code>sm_</code>) · magasins et zones réels (annexe de la feuille de mission) ; entreprises, contacts et chiffres illustrés.'
sel = lambda txt: f'<span class="select">{e(txt)}</span>'
btn = lambda txt, cls='': f'<span class="btn {cls}">{txt}</span>'
fe = lambda n: f'{n:,.0f}'.replace(',', ' ') + ' €'
fe1 = lambda n: f'{n:,.1f}'.replace(',', ' ').replace('.', ',') + ' €'

# ------------------------------------------------------------------------------------------------------------------
# 1. Tableau de bord des bureaux
# ------------------------------------------------------------------------------------------------------------------
T = [('Corbais', 3, 14, 92, 4210, 12, 5, 9), ('Gosselies', 2, 9, 61, 2870, 9, 3, 6), ('Halle', 1, 6, 38, 1690, 6, 1, 4), ('Sombreffe', 1, 2, 23, 1090, 4, 1, 3)]
tot = [sum(r[i] for r in T) for i in range(1, 8)]
lignes = ''.join(f'<tr><td>{m}</td><td class="num">{n}</td><td class="num">{a}</td><td class="num">{c}</td><td class="num">{fe(ca)}</td><td class="num">{fe1(ca / c)}</td><td class="num">{cartes}</td><td class="num">{bu} / {be}</td></tr>' for m, n, a, c, ca, cartes, bu, be in T)
lignes += f'<tr class="total"><td>Réseau</td><td class="num">{tot[0]}</td><td class="num">{tot[1]}</td><td class="num">{tot[2]}</td><td class="num">{fe(tot[3])}</td><td class="num">{fe1(tot[3] / tot[2])}</td><td class="num">{tot[4]}</td><td class="num">{tot[5]} / {tot[6]}</td></tr>'
# commandes par semaine : nouveaux clients (1re commande il y a moins de 30 jours) et anciens
SEM = [('S40', 9, 31), ('S41', 11, 33), ('S42', 8, 35), ('S43', 12, 34), ('S44', 10, 38), ('S45', 13, 39), ('S46', 15, 41), ('S47', 14, 40)]
W, H, PAD = 520, 190, 28
hmax = 60
bw = (W - PAD) / len(SEM)
barres = ''
for i, (s, n, a) in enumerate(SEM):
    x = PAD + i * bw + 8
    ha, hn = a / hmax * (H - 40), n / hmax * (H - 40)
    ya = H - 20 - ha
    yn = ya - hn
    barres += (f'<rect x="{x:.1f}" y="{ya:.1f}" width="{bw - 16:.1f}" height="{ha:.1f}" fill="#B03048"/>'
               f'<rect x="{x:.1f}" y="{yn:.1f}" width="{bw - 16:.1f}" height="{hn:.1f}" fill="#EBD3D8"/>'
               f'<text x="{x + (bw - 16) / 2:.1f}" y="{H - 6}" font-size="10" fill="#7a736a" text-anchor="middle">{s}</text>'
               f'<text x="{x + (bw - 16) / 2:.1f}" y="{yn - 5:.1f}" font-size="10" fill="#5c5852" text-anchor="middle">{n + a}</text>')
for v in (0, 20, 40, 60):
    y = H - 20 - v / hmax * (H - 40)
    barres = f'<line x1="{PAD}" x2="{W}" y1="{y:.1f}" y2="{y:.1f}" stroke="rgba(34,34,34,.06)"/><text x="{PAD - 6}" y="{y + 3:.1f}" font-size="10" fill="#B8B2A8" text-anchor="end">{v}</text>' + barres
graphe = f'<svg viewBox="0 0 {W} {H}" width="100%" style="display:block">{barres}</svg>'
SUIVRE = [
    ('Imprimerie Vandevelde', 'Halle', 'Bureau créé il y a 2 jours, pas encore de 1re commande', 'Relancer'),
    ('Clinique vétérinaire du Centre', 'Halle', 'Bon de bienvenue envoyé, pas utilisé · expire le 30/11', 'Rappeler'),
    ('Assurances Hainaut Conseil', 'Gosselies', 'Aucune commande depuis 21 jours (avant : 2 par semaine)', 'Appeler'),
    ('Atelier 9 architectes', 'Corbais', 'Carte office à 9 tampons : la récompense à la prochaine commande', 'Prévenir'),
]
suivre = ''.join(f'<tr><td>{n}<span class="sub">{m}</span></td><td class="ink2">{q}</td><td class="num">{btn(a, "btn--sm")}</td></tr>' for n, m, q, a in SUIVRE)
corps = f'''
<div class="toolbar"><span class="seg"><span class="is-on">30 jours</span><span>Trimestre</span><span>12 mois</span></span><span style="flex:1"></span>{sel('Tous les magasins')}</div>
<div class="kpi-row">
  <div class="kpi"><div class="kpi__value">38</div><div class="kpi__label">Bureaux actifs</div><div class="kpi__note">au moins une commande en 30 jours</div></div>
  <div class="kpi"><div class="kpi__value">7</div><div class="kpi__label">Nouveaux bureaux</div><div class="kpi__note">1re commande en 30 jours · 4 venus de Meta</div></div>
  <div class="kpi"><div class="kpi__value">214</div><div class="kpi__label">Commandes livrées</div><div class="kpi__note">+18 % face aux 30 jours d’avant</div></div>
  <div class="kpi"><div class="kpi__value">{fe(tot[3])}</div><div class="kpi__label">CA des bureaux</div><div class="kpi__note">HT · panier moyen {fe1(tot[3] / tot[2])}</div></div>
  <div class="kpi"><div class="kpi__value">31</div><div class="kpi__label">Cartes office</div><div class="kpi__note">actives · 12 récompenses données</div></div>
</div>
<div class="card" style="padding:0;overflow:hidden;margin-bottom:16px">
  <div style="padding:16px 18px 6px"><h2>Par magasin <span class="aside">nouveau client : 1re commande il y a moins de 30 jours</span></h2></div>
  <table><thead><tr><th>Magasin</th><th class="num">Nouveaux clients</th><th class="num">Anciens clients</th><th class="num">Commandes</th><th class="num">CA HT</th><th class="num">Panier moyen</th><th class="num">Cartes office</th><th class="num">Bons utilisés</th></tr></thead><tbody>{lignes}</tbody></table>
</div>
<div class="grid" style="grid-template-columns: 1fr 1.25fr">
  <div class="card"><h2>Commandes par semaine</h2>{graphe}
    <div class="small muted" style="display:flex;gap:16px;margin-top:6px"><span><i class="dot" style="background:#B03048"></i> anciens clients</span><span><i class="dot" style="background:#EBD3D8"></i> nouveaux clients</span></div></div>
  <div class="card" style="padding:0;overflow:hidden"><div style="padding:16px 18px 6px"><h2>Bureaux à suivre <span class="aside">4 cette semaine</span></h2></div>
    <table><tbody>{suivre}</tbody></table></div>
</div>'''
page('1-tableau-de-bord.html', 'tdb', 'Bureaux · webshop', 'Tableau de bord', [btn('Exporter'), btn(ico('plus') + 'Nouveau bureau', 'btn--primary')], corps,
     PIED_COMMUN + ' Les commandes et les clients viennent du webshop et du panel (TF Buddy), les cartes office du programme de fidélité ; à brancher par leurs API.')

# ------------------------------------------------------------------------------------------------------------------
# 2. Leads Meta par magasin
# ------------------------------------------------------------------------------------------------------------------
L = [('Corbais', 31, 2, 17, 4, 3, 5), ('Gosselies', 45, 3, 25, 5, 6, 6), ('Halle', 21, 1, 12, 3, 2, 3), ('Sombreffe', 11, 2, 6, 1, 1, 1)]
cartes = ''.join(f'''<div class="card" style="padding:14px 16px"><div style="display:flex;justify-content:space-between"><span style="font-size:15px">{m}</span><span class="muted small">{n} leads</span></div>
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-top:10px;font-size:12px">
    <div><div style="font-size:18px;font-weight:700;color:{'var(--prize-ink)' if t else 'var(--ink)'}">{t}</div><span class="muted">à traiter</span></div>
    <div><div style="font-size:18px;font-weight:700">{c}</div><span class="muted">contactés</span></div>
    <div><div style="font-size:18px;font-weight:700">{b}</div><span class="muted">créés</span></div>
    <div><div style="font-size:18px;font-weight:700;color:var(--green)">{k}</div><span class="muted">connectés</span></div></div>
  <div style="display:flex;height:6px;border-radius:3px;overflow:hidden;margin-top:12px;background:var(--chip)">
    <i style="width:{t / n * 100:.1f}%;background:#B0792A"></i><i style="width:{c / n * 100:.1f}%;background:#c9b9a8"></i><i style="width:{b / n * 100:.1f}%;background:#B03048"></i><i style="width:{k / n * 100:.1f}%;background:#3f7a52"></i></div>
  <div class="small muted" style="margin-top:6px">{p} perdus · {k / n * 100:.0f} % connectés</div></div>''' for m, n, t, c, b, k, p in L)
st = {'nouveau': '<span class="tag-warn">pas vu · 20 h</span>', 'vu': '<span class="chip">vu · à rappeler</span>', 'contacte': '<span class="chip">contacté</span>',
      'cree': '<span class="chip">bureau créé</span>', 'connecte': '<span class="badge badge--ok">bureau connecté</span>', 'perdu': '<span class="badge badge--n">perdu</span>'}
LE = [
    ('10:24', 'ig', 'Fiduciaire Delvaux &amp; associés', 'Claire Mertens · Office manager · 35 pers. · lunch tous les jours', 'Gosselies', 'formulaire', 'contacte', btn('Créer le bureau', 'btn--sm btn--primary')),
    ('09:51', 'fb', 'Clinique vétérinaire du Centre', 'Marc Dubois · 12 pers. · plateaux réunion', 'Halle', 'CP 1500', 'vu', btn('Appeler', 'btn--sm')),
    ('09:12', 'ig', 'Atelier 9 architectes', 'Sophie Lenoir · 18 pers. · petit-déjeuner', 'Corbais', 'CP 1435', 'cree', btn('Voir le bureau', 'btn--sm')),
    ('hier 16:40', 'fb', 'Assurances Hainaut Conseil', 'Tom Wauters · 60 pers. · lunch tous les jours', 'Gosselies', 'formulaire', 'connecte', '<span class="small muted">1re commande 08:12</span>'),
    ('hier 14:02', 'fb', 'Cabinet dentaire de Ligny', 'Nathalie Gérard · 8 pers. · petit-déjeuner', 'Sombreffe', 'formulaire', 'nouveau', btn('Relancer le magasin', 'btn--sm')),
    ('hier 11:30', 'ig', 'Résidence Les Tilleuls', 'Paul Henrard · 25 pers. · lunch tous les jours', 'Corbais', 'zone : Orbais (1360)', 'contacte', btn('Créer le bureau', 'btn--sm btn--primary')),
    ('lun. 09:30', 'ig', 'Garage Ribeiro', 'Ana Ribeiro · 9 pers. · plateaux réunion', 'Corbais', 'CP 1340', 'perdu', '<span class="small muted">« trop cher pour 9 »</span>'),
]
pf = lambda p: f'<span style="display:inline-flex;width:18px;height:18px;border-radius:5px;align-items:center;justify-content:center;font-size:9px;color:#fff;background:{"var(--mc-ig)" if p == "ig" else "var(--mc-fb)"}">{"IG" if p == "ig" else "f"}</span>'
leads = ''.join(f'<tr><td class="ink2" style="white-space:nowrap">{h}</td><td style="width:22px">{pf(p)}</td><td>{n}<span class="sub">{d}</span></td><td>{m}<span class="sub">{z}</span></td><td>{st[s]}</td><td class="num">{a}</td></tr>' for h, p, n, d, m, z, s, a in LE)
corps = f'''
<div class="grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:16px">{cartes}</div>
<div class="toolbar"><span class="filter is-on">Tous<em>108</em></span><span class="filter">À traiter<em>8</em></span><span class="filter">Contactés<em>60</em></span><span class="filter">Bureaux créés<em>13</em></span><span class="filter">Connectés<em>12</em></span><span class="filter">Perdus<em>15</em></span><span style="flex:1"></span>{sel('Tous les magasins')}{sel('Tous les formulaires')}</div>
<div class="card" style="padding:0;overflow:hidden"><table><thead><tr><th>Reçu</th><th></th><th>Lead</th><th>Magasin</th><th>Statut dans l’ERP</th><th></th></tr></thead><tbody>{leads}</tbody></table>
<div class="small muted" style="padding:12px 14px;border-top:1px solid var(--row-line)">101 leads de plus sur 30 jours · <span class="linklike">Afficher plus</span></div></div>'''
page('2-leads-meta.html', 'leads', 'Bureaux · webshop', 'Leads Meta', [sel('30 derniers jours'), btn('Relire l’ERP')], corps,
     PIED_COMMUN + ' Lu dans MétaDonnée (Marketing) par son API, jamais dans ses tables : leads, magasin attribué (formulaire ou zone par code postal), statut relu dans l’ERP toutes les 10 minutes.')

# ------------------------------------------------------------------------------------------------------------------
# 3. Assistant « Nouveau bureau »
# ------------------------------------------------------------------------------------------------------------------
ETAPES = ['Entreprise', 'Magasin et tournée', 'Compte webshop', 'Bon et carte office', 'E-mail de bienvenue']
stepper = '<div style="display:flex;gap:6px;margin-bottom:16px">' + ''.join(
    f'<div style="flex:1;padding:10px 12px;border-radius:10px;border:{"2px solid var(--accent)" if i == 4 else "1px solid var(--line)"};background:{"rgba(141,29,44,.05)" if i == 4 else "#fff"}">'
    f'<span class="small" style="color:{"var(--accent)" if i == 4 else "var(--green)"}">{"✓ " if i < 4 else ""}Étape {i + 1}</span><div style="color:{"var(--accent)" if i == 4 else "var(--ink)"}">{t}</div></div>'
    for i, t in enumerate(ETAPES)) + '</div>'
recap = [('Entreprise', 'Fiduciaire Delvaux &amp; associés', 'TVA BE 0712.345.678 · 35 personnes · 6041 Gosselies'),
         ('Contact', 'Claire Mertens', 'Office manager · c.mertens@fiduciaire-delvaux.be · 0476 12 34 56'),
         ('Origine', 'Lead Meta L-20488', 'Instagram · « Lunch au bureau — Gosselies » · aujourd’hui 10:24'),
         ('Magasin', 'Gosselies', 'zone : Charleroi (6041)'),
         ('Tournée', 'Gosselies – Aéropôle', 'mardi et jeudi, livraison avant 11:30 · validée avec le franchisé'),
         ('Compte webshop', 'Bureau « Fiduciaire Delvaux »', '<span class="tag-warn">API TF Buddy à définir avec Szymon</span>'),
         ('Bon', 'BIENVENUE-DELVAUX', '−10 % sur la 1re commande · 30 jours · usage unique'),
         ('Carte office', 'Créée', '1 tampon par commande · récompense à 10')]
recap_html = ''.join(f'<tr><td class="muted" style="width:120px;padding:9px 0">{k}</td><td style="padding:9px 0">{v}<span class="sub">{s}</span></td></tr>' for k, v, s in recap)
mail = f'''<div style="border:1px solid var(--line);border-radius:12px;overflow:hidden">
  <div style="background:var(--table-head);padding:12px 16px;font-size:12.5px;line-height:1.8;border-bottom:1px solid var(--line)">
    <span class="muted">De :</span> L’Atelier By — Ventes aux professionnels &lt;hello@atelierby.be&gt;<br>
    <span class="muted">À :</span> c.mertens@fiduciaire-delvaux.be<br>
    <span class="muted">Objet :</span> Votre bureau est prêt : L’Atelier By Gosselies vous livre<br>
    <span class="muted">Pièce jointe :</span> Catalogue-bureaux-Gosselies.pdf (catalogue du magasin)</div>
  <div style="padding:20px 24px;background:#fff">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;border-bottom:2px solid var(--accent);padding-bottom:10px">
      <img src="../../../shared/public/assets/img/logo.png" style="height:24px"><span class="small muted">Bienvenue</span></div>
    <p style="margin:16px 0 10px">Bonjour Claire,</p>
    <p style="margin:0 0 10px">Le bureau de <b style="font-weight:700">Fiduciaire Delvaux &amp; associés</b> est ouvert sur notre webshop. Le magasin de Gosselies vous livre le <b style="font-weight:700">mardi et le jeudi avant 11:30</b> ; commandez jusqu’à la veille 16 h.</p>
    <p style="margin:0 0 14px">Votre bon de bienvenue : <b style="font-weight:700">BIENVENUE-DELVAUX</b>, −10 % sur la première commande, jusqu’au <b style="font-weight:700">19 décembre 2026</b>. Votre carte office compte chaque commande : la dixième vous offre un plateau.</p>
    <span class="btn btn--primary" style="font-weight:700">Commander pour le bureau</span>
    <p class="small muted" style="margin:16px 0 0;border-top:1px solid var(--line);padding-top:10px">L’Atelier By — Ventes aux professionnels · hello@atelierby.be · se désinscrire</p></div></div>'''
corps = f'''{stepper}
<div class="grid" style="grid-template-columns: 1fr 1.15fr">
  <div class="card"><h2>Le bureau <span class="aside">depuis le lead L-20488</span></h2><table><tbody>{recap_html}</tbody></table></div>
  <div class="card"><h2>Étape 5 · e-mail de bienvenue</h2>
    <div class="toolbar"><span class="field-label" style="margin:0">Modèle</span>{sel('Bienvenue bureau')}<span class="seg"><span class="is-on">FR</span><span>NL</span></span><span style="flex:1"></span><span class="small muted">Envoyé depuis hello@atelierby.be</span></div>
    {mail}
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px">{btn('← Étape précédente', 'btn--ghost')}<span style="display:flex;gap:10px">{btn('Envoyer un test')}{btn('Créer le bureau et envoyer', 'btn--primary')}</span></div></div>
</div>'''
page('3-nouveau-bureau.html', 'nouveau', 'Bureaux · webshop', 'Nouveau bureau', [btn('Enregistrer le brouillon'), btn('Fermer', 'btn--ghost')], corps,
     PIED_COMMUN + ' Le compte du bureau et sa tournée s’écrivent au webshop par l’API de TF Buddy : routes à définir avec Szymon (création du bureau, rattachement à la tournée, statut du lead « bureau créé »). Le bon et la carte office se créent dans le programme de fidélité ; l’e-mail part de hello@atelierby.be avec le modèle choisi.', large=True)

# ------------------------------------------------------------------------------------------------------------------
# 4. Offres, bons et catalogues
# ------------------------------------------------------------------------------------------------------------------
OF = [('Plateau bureau', 'Bureaux et entreprises', ['59 € · 10 pers.', '59 € · 10 pers.', '62 € · 10 pers.', '57 € · 10 pers.']),
      ('Fêtes et locations de salle', 'Salles des fêtes', ['dès 4,50 €/pers.', 'dès 4,50 €/pers.', 'brouillon', 'dès 4,20 €/pers.']),
      ('Réceptions et réunions', 'Communes, CPAS', ['7 €/pers.', '7 €/pers.', '7,50 €/pers.', '—']),
      ('Après-match et cantine', 'Clubs sportifs', ['—', 'tarif club −10 %', '—', 'tarif club −10 %']),
      ('Pause soignants et résidents', 'Homes', ['3 €/pers. · 3×/sem.', '—', 'brouillon', '3 €/pers. · 3×/sem.'])]
cell = lambda v: f'<span class="muted">{v}</span>' if v in ('—',) else (f'<span class="tag-warn">{v}</span>' if v == 'brouillon' else v)
of_html = ''.join(f'<tr><td>{o}<span class="sub">{c}</span></td>' + ''.join(f'<td>{cell(v)}</td>' for v in vs) + f'<td class="num">{btn("Envoyer", "btn--sm")}</td></tr>' for o, c, vs in OF)
BONS = [('BIENVENUE-{bureau}', 'Tous', '−10 % sur la 1re commande', '30 jours', 22, 10), ('PLATEAU-OFFERT-GOS', 'Gosselies', '1 plateau viennoiseries offert', '31/12/2026', 8, 3),
        ('FETES-2026', 'Tous', '−5 € dès 50 € HT', '31/12/2026', 40, 6), ('APRES-ECHANTILLON', 'Tous', '−15 % sur la commande qui suit un échantillon', '14 jours', 12, 4)]
bons = ''.join(f'<tr><td><span class="chip">{c}</span></td><td>{m}</td><td>{v}</td><td>{d}</td><td class="num">{n}</td><td class="num">{u}<span class="sub">{u / n * 100:.0f} %</span></td></tr>' for c, m, v, d, n, u in BONS)
CAT = [('Catalogue bureaux — automne 2026', 'PDF · 8 pages', 'Corbais, Gosselies, Halle, Sombreffe'), ('Fêtes de fin d’année', 'PDF · 6 pages · cougnous, bûches', 'tous les magasins'),
       ('Plateaux et réceptions', 'PDF · 4 pages', 'Corbais, Gosselies'), ('Carte des clubs', 'PDF · 2 pages', 'Gosselies, Sombreffe')]
cats = ''.join(f'<tr><td>{n}<span class="sub">{d}</span></td><td class="ink2">{m}</td><td class="num">{btn("Aperçu", "btn--sm btn--ghost")}{btn("Envoyer", "btn--sm")}</td></tr>' for n, d, m in CAT)
corps = f'''
<div class="card" style="padding:0;overflow:hidden;margin-bottom:16px"><div style="padding:16px 18px 6px;display:flex;justify-content:space-between"><h2>Offres par magasin</h2>{btn(ico('plus') + 'Nouvelle offre', 'btn--sm')}</div>
<table><thead><tr><th>Offre</th>{''.join(f'<th>{m}</th>' for m in MAG)}<th></th></tr></thead><tbody>{of_html}</tbody></table></div>
<div class="grid">
  <div class="card" style="padding:0;overflow:hidden"><div style="padding:16px 18px 6px;display:flex;justify-content:space-between"><h2>Bons</h2>{btn(ico('plus') + 'Créer un bon', 'btn--sm')}</div>
    <table><thead><tr><th>Code</th><th>Magasin</th><th>Avantage</th><th>Validité</th><th class="num">Envoyés</th><th class="num">Utilisés</th></tr></thead><tbody>{bons}</tbody></table></div>
  <div class="card" style="padding:0;overflow:hidden"><div style="padding:16px 18px 6px"><h2>Catalogues <span class="aside">ceux des magasins, dans la base</span></h2></div>
    <table><tbody>{cats}</tbody></table></div>
</div>'''
page('4-offres-bons-catalogues.html', 'offres', 'Bureaux · webshop', 'Offres, bons et catalogues', [sel('Tous les magasins')], corps,
     PIED_COMMUN + ' Les offres reprennent les types d’offre de Prospection ; chaque magasin a son prix ou reste vide. Les bons sont ceux de l’ERP (codes uniques, usage suivi) ; les catalogues, ceux des magasins (« Mon catalogue », servi par le Marketing). « Envoyer » ouvre l’e-mail depuis hello@atelierby.be avec son modèle.')

# ------------------------------------------------------------------------------------------------------------------
# 5. Échantillons
# ------------------------------------------------------------------------------------------------------------------
PROD = [('Croissant', 6), ('Pain au chocolat', 6), ('Pistolet garni jambon-fromage', 4), ('Tarte du jour (8 parts)', 1)]
prod = ''.join(f'<tr><td style="padding:8px 0">{p}</td><td class="num" style="padding:8px 0"><span class="input" style="display:inline-block;width:56px;padding:5px 9px;text-align:right">{q}</span></td></tr>' for p, q in PROD)
ECH = [('19/11', 'Résidence Les Tilleuls', 'Corbais', 'plateau découverte 6 pers.', 'commandé', 'livraison mar. 24/11 10:00'),
       ('17/11', 'Clinique vétérinaire du Centre', 'Halle', 'viennoiseries 12 pers.', 'livré', 'suivi à faire jeu. 20/11'),
       ('12/11', 'Atelier 9 architectes', 'Corbais', 'petit-déjeuner 18 pers.', 'converti', 'bureau créé le 18/11'),
       ('10/11', 'Club de football de Ransart', 'Gosselies', 'gaufres et pains buvette', 'converti', '1re commande le 15/11'),
       ('05/11', 'Cabinet Lemaire', 'Sombreffe', 'plateau découverte 6 pers.', 'sans suite', '« pas de budget avant janvier »')]
stc = {'commandé': '<span class="chip">commandé</span>', 'livré': '<span class="chip">livré</span>', 'converti': '<span class="badge badge--ok">converti</span>', 'sans suite': '<span class="badge badge--n">sans suite</span>'}
ech = ''.join(f'<tr><td class="ink2">{d}</td><td>{p}<span class="sub">{c}</span></td><td>{m}</td><td>{stc[s]}</td><td class="ink2 small">{r}</td></tr>' for d, p, m, c, s, r in ECH)
corps = f'''
<div class="kpi-row">
  <div class="kpi"><div class="kpi__value">14</div><div class="kpi__label">Échantillons ce mois</div><div class="kpi__note">tous commandés aux magasins</div></div>
  <div class="kpi"><div class="kpi__value">126 €</div><div class="kpi__label">Coût matière</div><div class="kpi__note">9 € par échantillon en moyenne</div></div>
  <div class="kpi"><div class="kpi__value">5</div><div class="kpi__label">Convertis</div><div class="kpi__note">36 % · une commande dans les 14 jours</div></div>
</div>
<div class="grid" style="grid-template-columns: 1fr 1.45fr">
  <div class="card"><h2>Commander un échantillon</h2>
    <label class="field-label">Prospect</label><span class="input" style="margin-bottom:12px">Résidence Les Tilleuls · Paul Henrard</span>
    <label class="field-label">Magasin (par la zone)</label><span class="input" style="margin-bottom:12px">Corbais · zone : Orbais (1360)</span>
    <label class="field-label">Contenu · catalogue du magasin</label><table style="margin-bottom:12px"><tbody>{prod}</tbody></table>
    <div class="grid" style="grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px"><div><label class="field-label">Le</label><span class="input">mar. 24/11 · 10:00</span></div>
      <div><label class="field-label">Mode</label><span class="seg" style="display:flex"><span class="is-on">Livraison</span><span>Retrait</span></span></div></div>
    <label class="field-label">Après l’échantillon</label><span class="input" style="margin-bottom:14px">Bon APRES-ECHANTILLON · rappel J+3</span>
    <div style="display:flex;justify-content:space-between;align-items:center"><span class="small muted">Coût matière estimé : 8,40 €</span>{btn('Commander au magasin', 'btn--primary')}</div></div>
  <div class="card" style="padding:0;overflow:hidden"><div style="padding:16px 18px 6px"><h2>Les échantillons <span class="aside">30 derniers jours</span></h2></div>
    <table><thead><tr><th>Le</th><th>Prospect</th><th>Magasin</th><th>Statut</th><th>Suite</th></tr></thead><tbody>{ech}</tbody></table></div>
</div>'''
page('5-echantillons.html', 'echantillons', 'Bureaux · webshop', 'Échantillons', [sel('Tous les magasins')], corps,
     PIED_COMMUN + ' La commande part au magasin par le système de commande (une commande à 0 € marquée « échantillon », préparée et livrée comme les autres) ; le franchisé la voit dans ses commandes du jour. Converti : une commande du prospect dans les 14 jours.')

# ------------------------------------------------------------------------------------------------------------------
# 6. B2B : suivi des offres par secteur et par magasin
# ------------------------------------------------------------------------------------------------------------------
SECT = [('Bureaux et entreprises', 186, 74, 31, 9, 14, 2860), ('Salles des fêtes', 38, 21, 12, 3, 5, 940), ('Communes, CPAS', 24, 15, 8, 2, 4, 610),
        ('Clubs sportifs', 41, 19, 9, 4, 6, 720), ('Homes', 22, 13, 6, 3, 3, 1180), ('Écoles et crèches', 57, 18, 6, 1, 2, 240),
        ('Industrie et artisans', 63, 16, 5, 1, 2, 380), ('Centres de formation', 9, 5, 2, 0, 1, 150), ('Funérariums', 7, 4, 3, 1, 2, 420)]
sec = ''.join(f'<tr><td>{s}</td><td class="num">{l}</td><td class="num">{c}</td><td class="num">{o}</td><td class="num">{e_}</td><td class="num">{k}</td>'
              f'<td><div style="display:flex;align-items:center;gap:8px"><div style="flex:1;height:6px;border-radius:3px;background:var(--chip);overflow:hidden"><i style="display:block;height:6px;width:{k / c * 100 if c else 0:.0f}%;background:var(--green)"></i></div><span class="small ink2" style="width:34px;text-align:right">{k / c * 100:.0f} %</span></div></td>'
              f'<td class="num">{fe(ca)}</td></tr>' for s, l, c, o, e_, k, ca in SECT)
S = [sum(r[i] for r in SECT) for i in range(1, 7)]
sec += f'<tr class="total"><td>Tous les secteurs</td><td class="num">{S[0]}</td><td class="num">{S[1]}</td><td class="num">{S[2]}</td><td class="num">{S[3]}</td><td class="num">{S[4]}</td><td><span class="small ink2">{S[4] / S[1] * 100:.0f} % des contactés</span></td><td class="num">{fe(S[5])}</td></tr>'
# clients (offres envoyées) par secteur et par magasin ; la somme des magasins = la colonne du secteur
MX = {'Bureaux et entreprises': [(5, 11), (5, 10), (3, 7), (1, 3)], 'Salles des fêtes': [(2, 4), (1, 3), (1, 3), (1, 2)], 'Communes, CPAS': [(1, 3), (2, 3), (0, 1), (1, 1)],
      'Clubs sportifs': [(1, 2), (3, 4), (0, 1), (2, 2)], 'Homes': [(1, 2), (0, 1), (1, 2), (1, 1)], 'Écoles et crèches': [(1, 3), (1, 2), (0, 1), (0, 0)],
      'Industrie et artisans': [(0, 1), (2, 3), (0, 1), (0, 0)], 'Centres de formation': [(1, 1), (0, 1), (0, 0), (0, 0)], 'Funérariums': [(1, 1), (0, 1), (1, 1), (0, 0)]}
for s, *_r in SECT:
    assert sum(k for k, _ in MX[s]) == _r[4] and sum(o for _, o in MX[s]) == _r[2], s
def case(k, o):
    if o == 0:
        return '<td class="num muted">—</td>'
    if k == 0:
        return f'<td class="num">0<span class="sub">{o} offre{"s" if o > 1 else ""}</span></td>'
    a = min(.08 + k * .07, .5)
    return f'<td class="num" style="background:rgba(63,122,82,{a:.2f})">{k}<span class="sub">{o} offre{"s" if o > 1 else ""}</span></td>'
mx = ''.join(f'<tr><td>{s}</td>' + ''.join(case(k, o) for k, o in MX[s]) + '</tr>' for s, *_ in SECT)
corps = f'''
<div class="toolbar"><span class="seg"><span class="is-on">Par secteur</span><span>Par magasin</span></span><span style="flex:1"></span>{sel('Depuis le 1er septembre')}{sel('Tous les magasins')}</div>
<div class="card" style="padding:0;overflow:hidden;margin-bottom:16px"><div style="padding:16px 18px 6px"><h2>Les offres par secteur <span class="aside">lieux de Prospection, offres et échantillons du portail, clients du panel</span></h2></div>
<table><thead><tr><th>Secteur</th><th class="num">Lieux ciblés</th><th class="num">Contactés</th><th class="num">Offres envoyées</th><th class="num">Échantillons</th><th class="num">Clients</th><th style="width:180px">Clients / contactés</th><th class="num">CA 30 j HT</th></tr></thead><tbody>{sec}</tbody></table></div>
<div class="card" style="padding:0;overflow:hidden"><div style="padding:16px 18px 6px"><h2>Clients par secteur et par magasin <span class="aside">clients · offres envoyées</span></h2></div>
<table><thead><tr><th>Secteur</th>{''.join(f'<th class="num">{m}</th>' for m in MAG)}</tr></thead><tbody>{mx}</tbody></table></div>'''
page('6-suivi-offres-b2b.html', 'b2b', 'B2B', 'Suivi des offres', [btn('Exporter')], corps,
     PIED_COMMUN + ' Lieux ciblés et contactés : la liste de démarchage de Prospection (Marketing, par son API). Offres et échantillons : les tables <code>sm_</code> du portail. Clients et CA : les clients pro à TVA valide du panel.')
print('6 écrans écrits')
