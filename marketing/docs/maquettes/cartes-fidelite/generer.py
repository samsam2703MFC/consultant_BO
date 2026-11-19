# Trois maquettes « cartes de fidélité » (13/11/2026, Sam : « utilise /loyalty/customers et /loyalty/statistics pour
# savoir quel client a quelle carte, et où on en est au niveau général »). DONNÉES FICTIVES : le compte API du cockpit
# n'a pas encore la permission LOYALTY.MANAGE (403 sur les deux routes, sonde du 13/11/2026). La forme suit le schéma
# OpenAPI des deux routes (participants, programs, recognitions, shop_activity ; items[].programs[].global_progress).
import os
ICI = os.path.dirname(os.path.abspath(__file__))
CSS = open(os.path.join(ICI, 'cartes.css')).read()
CARTES = [  # id, nom, couleur, type, timbres requis, récompense
    (1, 'Carte Café', '#8D1D2C', 'stamps', 10, '1 café offert'),
    (2, 'Carte Viennoiserie', '#B0792A', 'stamps', 8, '1 croissant offert'),
    (3, 'Points Atelier', '#1B6FA8', 'points', None, '5 € dès 100 points'),
]
def page(t, corps):
    return f'<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>{t}</title><style>{CSS}</style></head><body>{corps}</body></html>'
def entete(sous):
    ong = ''.join(f'<span class="{"on" if o == "Fidélité" else ""}">{o}</span>' for o in ['Opérationnel', 'Tâches', 'Semaine', 'Mois', 'Trimestre', 'Année', 'Produits', 'Promotions', 'Primes', 'Concurrence', 'Temps morts', 'Fidélité'])
    s = ''.join(f'<span class="{"on" if x == sous else ""}">{x}</span>' for x in ['Profils des clients', 'Cartes de fidélité'])
    return (f'<div class="hd"><span class="logo">L’ATELIER</span><span class="mag">Atelier by - Halle</span><span class="fictif">maquette · données fictives</span><span class="sp"></span>'
            '<span class="btn">Revue du soir</span><span class="btn">↻ Relire</span><span class="btn ia">Assistant</span></div>'
            f'<div class="ong">{ong}</div><div class="sous">{s}</div>')
def dots(p, r, c):
    return '<span class="dots">' + ''.join(f'<i style="background:{c if i < p else "var(--chip)"}"></i>' for i in range(r)) + '</span>'

# ── A · Dashboard magasin › Fidélité › Cartes : où on en est ────────────────────────────────────────────────────
kpis = [('64', 'clients inscrits à une carte', '+9 ce mois', ''), ('21', 'ont acheté avec leur carte ce mois', '33 % des inscrits', ''),
        ('47', 'cartes reconnues en caisse ce mois', '24 clients différents', ''), ('6', 'récompenses prêtes à offrir', 'à rappeler en caisse', ''),
        ('31 %', 'des clients qui achètent sont inscrits', '21 sur 68 clients reconnus', 'bas')]
K = ''.join(f'<div class="card kpi"><b>{v}</b><span>{l}</span><em class="{c}">{e}</em></div>' for v, l, e, c in kpis)
def carte_timbres(nom, col, req, dist, pretes, clients, actifs, recompense):
    mx = max(dist) or 1
    # Une carte pleine devient une « récompense prête » : les barres vont de 0 à req − 1 timbres.
    bars = ''.join(f'<div><span class="n">{n}</span><i style="height:{max(3, 90 * n / mx):.0f}px;background:{col};opacity:{0.35 + 0.65 * i / (req - 1):.2f}"></i>{i}</div>' for i, n in enumerate(dist))
    return (f'<div class="card"><div class="ct"><span class="carte-ic" style="background:{col}"></span>{nom}<small>{req} timbres · {recompense}</small></div><div class="pad">'
            f'<div style="font-size:12px;color:var(--muted)">Où en sont les clients (timbres sur la carte)</div><div class="prog">{bars}</div>'
            f'<div class="kv"><span>Clients sur la carte</span><b>{clients}</b><span>Ont gagné un timbre ce mois</span><b>{actifs}</b><span>À 1 ou 2 timbres du cadeau</span><b>{dist[req - 1] + dist[req - 2]}</b><span>Récompenses prêtes</span><b style="color:var(--green)">{pretes}</b></div></div></div>')
pts = ('<div class="card"><div class="ct"><span class="carte-ic" style="background:#1B6FA8"></span>Points Atelier<small>1 € = 1 point · paliers</small></div><div class="pad">'
       '<div style="font-size:12px;color:var(--muted)">Clients par palier atteint (solde actuel)</div><div class="paliers">'
       + ''.join(f'<div class="pal"><span>{l}</span><span class="bar"><i style="width:{w}%;background:#1B6FA8;opacity:{o}"></i></span><b style="text-align:right">{n}</b></div>' for l, w, o, n in
                 [('0 à 49 points', 100, .35, 19), ('50 à 99 points', 47, .55, 9), ('100 points · 5 €', 26, .75, 5), ('200 points · 12 €', 10, 1, 2)])
       + '</div><div class="kv"><span>Clients sur la carte</span><b>35</b><span>Points gagnés ce mois dans le magasin</span><b>2 140</b><span>Points dépensés depuis le début</span><b>3 600</b><span>Bons à utiliser (paliers atteints)</span><b style="color:var(--green)">7</b></div></div></div>')
A = (entete('Cartes de fidélité')
     + '<div class="sec">Les cartes de fidélité du magasin<small>inscrits, activité dans le magasin et récompenses · soldes actuels, tous magasins</small><span class="tg"><span class="on">Ce mois</span><span>30 j</span><span>Année</span></span></div>'
     + f'<div class="kpis">{K}</div><div class="g3">'
     + carte_timbres('Carte Café', '#8D1D2C', 10, [6, 9, 7, 5, 4, 3, 2, 2, 1, 1], 4, 40, 15, '1 café offert')
     + carte_timbres('Carte Viennoiserie', '#B0792A', 8, [3, 4, 3, 2, 2, 1, 1, 1], 2, 17, 6, '1 croissant offert')
     + pts + '</div>'
     + '<div class="g2"><div class="card"><div class="ct">Reconnaissances en caisse, jour par jour<small>scans réussis · ce mois</small></div><div class="pad">'
     + '<div class="prog" style="height:110px">' + ''.join(f'<div><i style="height:{h}px;background:#8D1D2C;opacity:.8"></i>{d}</div>' for d, h in zip(range(1, 14), [20, 34, 28, 40, 52, 70, 64, 30, 26, 44, 48, 60, 22])) + '</div>'
     + '<p class="note">47 reconnaissances pour 24 clients, sur 2 appareils (caisse 1 : 39, caisse 2 : 8). Une reconnaissance n’est pas un ticket : un client peut être reconnu sans acheter.</p></div></div>'
     + '<div class="card"><div class="ct">Inscrits ou pas, parmi les clients qui achètent<small>ce mois</small></div><div class="pad">'
     + '<div class="barre" style="height:18px"><i style="width:31%;background:#3f7a52"></i><i style="width:69%;background:#d9d3c9"></i></div>'
     + '<div class="leg"><span><i style="background:#3f7a52"></i>Inscrits à une carte · 21</span><span><i style="background:#d9d3c9"></i>Reconnus sans carte · 47</span></div>'
     + '<p class="note">Le levier : proposer la carte aux 47 clients reconnus en caisse qui n’en ont pas encore. Les plus fidèles d’abord (onglet Profils des clients).</p></div></div></div>')
open(os.path.join(ICI, 'a-magasin-cartes.html'), 'w').write(page('A · Cartes de fidélité du magasin', f'<div class="wrap">{A}</div>'))

# ── B · Dashboard magasin › Fidélité › Cartes : qui a quelle carte ───────────────────────────────────────────────
CLI = [('Julie M.', [(1, 8, 10, 0), (3, None, None, 142)], 9, 'hier', 'aujourd’hui'),
       ('Karim B.', [(2, 7, 8, 0)], 3, 'il y a 3 j', 'il y a 3 j'),
       ('Nadia L.', [(1, 0, 10, 1), (2, 2, 8, 0)], 2, 'il y a 4 j', 'il y a 4 j'),
       ('Tom V.', [(3, None, None, 96)], 2, 'il y a 2 j', 'il y a 2 j'),
       ('Élise D.', [(1, 3, 10, 0)], 1, 'il y a 13 j', 'il y a 13 j'),
       ('Marc P.', [(1, 9, 10, 0), (3, None, None, 210)], 1, 'il y a 11 j', 'il y a 18 j'),
       ('Inès R.', [(2, 0, 8, 1)], 2, 'hier', 'hier'),
       ('Paul G.', [(3, None, None, 38)], 0, '—', 'il y a 41 j')]
C = {c[0]: c for c in CARTES}
def prog(card):
    cid, p, r, x = card
    nom, col = C[cid][1], C[cid][2]
    if p is None:
        nxt = 100 if x < 100 else 200
        return f'<span class="chip"><span class="carte-ic" style="width:22px;height:14px;background:{col}"></span>{nom} <b>{x} pts</b><small style="display:inline;margin-left:4px">{("palier 5 € atteint · " if x >= 100 else "") + f"{nxt - x} pts du palier suivant" if x < 200 else "palier 12 € atteint"}</small></span>'
    return f'<span class="chip"><span class="carte-ic" style="width:22px;height:14px;background:{col}"></span>{nom} {dots(p, r, col)} <b>{p}/{r}</b>{" <span class=pret>" + str(x) + " prête</span>" if x else ""}</span>'
rows = ''.join(f'<tr><td>{n}</td><td>{"".join(prog(k) for k in ks)}</td><td class="n">{t}<small>ce mois</small></td><td>{lt}</td><td>{lr}</td><td class="n" style="color:var(--accent)">Historique ›</td></tr>' for n, ks, t, lt, lr in CLI)
B = (entete('Cartes de fidélité')
     + '<div class="sec">Qui a quelle carte<small>64 clients inscrits · progression actuelle sur toutes les cartes du réseau · activité dans ce magasin</small></div>'
     + '<div class="card"><div class="filtre"><span class="srch">Rechercher un client…</span><span class="tg"><span class="on">Toutes les cartes</span><span>Carte Café</span><span>Carte Viennoiserie</span><span>Points Atelier</span></span>'
     + '<span class="tg" style="margin-left:auto"><span>Récompense prête</span><span class="on">Tous</span><span>Inactifs ce mois</span></span></div>'
     + f'<table><thead><tr><th>Client</th><th>Ses cartes et où il en est</th><th class="n">Achats</th><th>Dernier achat</th><th>Dernière reconnaissance</th><th></th></tr></thead><tbody>{rows}</tbody></table></div>'
     + '<p class="note">Noms fictifs. En vrai, les noms viennent de l’ERP (prénom et initiale) ; aucun contact, aucun code de carte n’est lu. Les soldes sont ceux de tout le réseau : un client de Halle peut avoir gagné ses timbres à Gosselies.</p>')
open(os.path.join(ICI, 'b-magasin-clients.html'), 'w').write(page('B · Qui a quelle carte', f'<div class="wrap">{B}</div>'))

# ── C · Cockpit › ERP franchisé › Fidélité › Cartes : le réseau ──────────────────────────────────────────────────
MAG = [('Gosselies', 112, 40, 96, 41, 9), ('Halle', 64, 21, 47, 31, 6), ('Corbais', 48, 15, 33, 24, 4), ('Sombreffe', 30, 9, 12, 18, 1)]
mr = ''.join(f'<tr><td>{m}</td><td class="n">{i}</td><td class="n">{a}<small>{round(100 * a / i)} %</small></td><td class="n">{r}</td><td><div class="barre"><i style="width:{p}%;background:#3f7a52"></i></div><small>{p} % des clients qui achètent</small></td><td class="n">{rp}</td></tr>' for m, i, a, r, p, rp in MAG)
cr = ''.join(f'<tr><td><span class="chip"><span class="carte-ic" style="width:22px;height:14px;background:{c[2]}"></span>{c[1]}</span></td><td>{"Timbres · " + str(c[4]) if c[3] == "stamps" else "Points · paliers"}</td><td>{c[5]}</td><td class="n">{n}</td><td class="n">{a}</td><td>Réseau · 4 magasins</td><td>Active</td></tr>'
             for c, n, a in zip(CARTES, [161, 58, 121], [52, 19, 37]))
rail = ('<aside class="rail"><div class="lg">L’ATELIER BY</div><div class="kk">Cockpit de pilotage</div>'
        '<div class="gl">Pilotage</div><a>Exploitation</a><a>Résultat</a><div class="gl">ERP franchisé</div><a>Prospection ↗</a><a>MétaDonnée</a><a>Tablette vendeuses</a><a class="on">Fidélité</a><a>Assistant · coachs</a></aside>')
Cc = (f'<div class="layout">{rail}<main class="main"><div class="h1">Fidélité <span class="fictif">maquette · données fictives</span></div>'
      '<div class="sous" style="margin-top:10px"><span>Programme</span><span>Clients</span><span>Profils clients</span><span class="on">Cartes</span></div>'
      '<p class="lead">Où en est le réseau avec les cartes de fidélité : combien de clients sont inscrits, combien s’en servent, ce que chaque magasin en fait. Lu dans l’ERP (/loyalty/statistics) pour chaque magasin.</p>'
      '<div class="kpis"><div class="card kpi"><b>254</b><span>inscrits dans les 4 magasins</span><em>un client peut compter dans deux magasins</em></div><div class="card kpi"><b>85</b><span>actifs ce mois</span><em>33 % des inscrits</em></div>'
      '<div class="card kpi"><b>188</b><span>reconnaissances en caisse ce mois</span><em>+22 % sur octobre</em></div><div class="card kpi"><b>20</b><span>récompenses prêtes</span><em>à rappeler en caisse</em></div><div class="card kpi"><b>3</b><span>cartes actives</span><em>2 à timbres, 1 à points</em></div></div>'
      '<div class="sec">Par magasin<small>ce mois</small><span class="tg"><span class="on">Ce mois</span><span>30 j</span><span>Année</span></span></div>'
      f'<div class="card"><table><thead><tr><th>Magasin</th><th class="n">Inscrits</th><th class="n">Actifs ce mois</th><th class="n">Reconnaissances</th><th>Inscrits parmi les clients qui achètent</th><th class="n">Récompenses prêtes</th></tr></thead><tbody>{mr}</tbody></table></div>'
      '<div class="sec">Les cartes<small>définies dans l’ERP · une carte par ligne</small></div>'
      f'<div class="card"><table><thead><tr><th>Carte</th><th>Mécanique</th><th>Récompense</th><th class="n">Clients</th><th class="n">Actifs ce mois</th><th>Portée</th><th>État</th></tr></thead><tbody>{cr}</tbody></table></div>'
      '<p class="note">Les soldes de timbres et de points sont ceux du réseau, à l’instant : on ne les additionne pas d’un magasin à l’autre. Les reconnaissances sont des scans réussis, pas des tickets.</p></main></div>')
open(os.path.join(ICI, 'c-cockpit-reseau.html'), 'w').write(page('C · Cartes de fidélité · réseau', Cc))
print('ok')
