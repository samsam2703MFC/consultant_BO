# Variantes de la maquette A (13/11/2026, Sam : « vers le A, mais refais plusieurs versions »). DONNÉES FICTIVES.
import os
ICI = os.path.dirname(os.path.abspath(__file__))
exec(open(os.path.join(ICI, 'generer.py')).read())
CSS = open(os.path.join(ICI, 'cartes.css')).read()
CAFE = [6, 9, 7, 5, 4, 3, 2, 2, 1, 1]          # clients par nombre de timbres (0 à 9), Carte Café (10 timbres)
VIEN = [3, 4, 3, 2, 2, 1, 1, 1]                # Carte Viennoiserie (8 timbres)

# Chaque magasin vérifie les cartes en général ET client par client (Sam, 13/11/2026) : le bloc « Par client », sous
# chaque variante — la recherche, les cartes de chaque client et où il en est (même lecture que la maquette B).
def par_client():
    r = ''.join(f'<tr><td>{n}</td><td>{"".join(prog(k) for k in ks)}</td><td class="n">{t}<small>achats ce mois</small></td><td>{lr}</td><td class="n" style="color:var(--accent)">Sa fiche ›</td></tr>' for n, ks, t, lt, lr in CLI[:5])
    return ('<div class="sec">Par client<small>64 inscrits · chercher un client pour vérifier ses cartes</small></div>'
            '<div class="card"><div class="filtre"><span class="srch">Rechercher un client…</span><span class="tg"><span class="on">Toutes les cartes</span><span>Carte Café</span><span>Carte Viennoiserie</span><span>Points Atelier</span></span>'
            '<span class="tg" style="margin-left:auto"><span>Récompense prête</span><span class="on">Tous</span><span>Inactifs ce mois</span></span></div>'
            f'<table><thead><tr><th>Client</th><th>Ses cartes et où il en est</th><th class="n">Achats</th><th>Dernière reconnaissance</th><th></th></tr></thead><tbody>{r}</tbody></table>'
            '<div style="padding:10px 16px;border-top:1px solid var(--line);font-size:12.5px;color:var(--accent)">Voir les 64 clients ›</div></div>')
per = '<span class="tg"><span class="on">Ce mois</span><span>30 j</span><span>Année</span></span>'

# ── A1 · Entonnoir ──────────────────────────────────────────────────────────────────────────────────────────────
etapes = [('68', 'clients reconnus en caisse', 100, ''), ('21', 'inscrits à une carte', 31, '47 à inscrire'), ('15', 'ont gagné un timbre ou des points', 22, ''),
          ('4', 'à 1 ou 2 timbres du cadeau', 6, 'à relancer'), ('6', 'récompenses prêtes', 9, 'à offrir en caisse')]
fun = ''.join(f'<div><b>{v}</b><span>{l}</span><div class="bar"><i style="width:{w}%"></i></div>{f"<em>{e}</em>" if e else ""}</div>' for v, l, w, e in etapes)
def mini(dist, col):
    mx = max(dist); return '<div class="mini">' + ''.join(f'<i style="height:{max(3, 38 * n / mx):.0f}px;background:{col};opacity:{0.35 + 0.65 * i / (len(dist) - 1):.2f}"></i>' for i, n in enumerate(dist)) + '</div>'
crow = lambda col, nom, sous, viz, a, b, c: (f'<div class="crow"><div class="t"><span class="carte-ic" style="background:{col}"></span><div>{nom}<small style="display:block;color:var(--muted);font-size:11px">{sous}</small></div></div>'
                                             f'<div>{viz}</div><div class="v"><b>{a[0]}</b><span>{a[1]}</span></div><div class="v"><b>{b[0]}</b><span>{b[1]}</span></div><div class="v"><b style="color:var(--green)">{c[0]}</b><span>{c[1]}</span></div></div>')
pal = '<div class="mini">' + ''.join(f'<i style="height:{h}px;background:#1B6FA8;opacity:{o}"></i>' for h, o in [(38, .35), (18, .55), (10, .75), (4, 1)]) + '</div>'
A1 = (entete('Cartes de fidélité') + f'<div class="sec">Du client reconnu à la récompense<small>ce mois · Halle</small>{per}</div>'
      + f'<div class="card"><div class="fun">{fun}</div></div>'
      + '<div class="sec">Par carte<small>où en sont les clients · soldes actuels, tous magasins</small></div><div class="card">'
      + crow('#8D1D2C', 'Carte Café', '10 timbres · 1 café offert', mini(CAFE, '#8D1D2C'), ('40', 'clients'), ('15', 'actifs ce mois'), ('4', 'prêtes'))
      + crow('#B0792A', 'Carte Viennoiserie', '8 timbres · 1 croissant offert', mini(VIEN, '#B0792A'), ('17', 'clients'), ('6', 'actifs ce mois'), ('2', 'prêtes'))
      + crow('#1B6FA8', 'Points Atelier', '1 € = 1 point · 5 € dès 100 points', pal, ('35', 'clients'), ('2 140', 'points ce mois'), ('7', 'bons à utiliser'))
      + '</div><p class="note">Barres : les clients selon leurs timbres (de 0 à un timbre du cadeau) ou leurs paliers de points. Une reconnaissance est un scan réussi, pas un ticket.</p>')
open(os.path.join(ICI, 'a1-entonnoir.html'), 'w').write(page('A1 · Entonnoir', f'<div class="wrap">{A1}{par_client()}</div>'))

# ── A2 · Les cartes, dessinées ──────────────────────────────────────────────────────────────────────────────────
def phys(nom, rc, col, dist, req, pretes, clients, actifs):
    cases = ''.join(f'<div class="st{" f" if n else ""}"><span>{n}</span><small>{i} timbre{"s" if i > 1 else ""}</small></div>' for i, n in enumerate(dist[1:], 1))
    cases += f'<div class="st gift"><span>{pretes}</span><small>cadeau</small></div>'
    return (f'<div><div class="phys" style="background:{col}"><div class="nm">{nom}</div><div class="rc">{rc}</div><div class="grid">{cases}</div>'
            f'<div class="pied"><span>{dist[0]} client{"s" if dist[0] > 1 else ""} à 0 timbre</span><span>{clients} clients sur la carte</span></div></div>'
            f'<div class="under"><div><b>{clients}</b>inscrits</div><div><b>{actifs}</b>actifs ce mois</div><div><b>{dist[req - 1] + dist[req - 2]}</b>presque au cadeau</div><div><b style="color:var(--green)">{pretes}</b>prêtes</div></div></div>')
pts = ('<div><div class="phys" style="background:#1B6FA8"><div class="nm">Points Atelier</div><div class="rc">1 € = 1 point</div>'
       '<div style="display:grid;gap:12px;margin-top:18px">' + ''.join(f'<div style="display:grid;grid-template-columns:110px 1fr 30px;gap:10px;align-items:center;font-size:12.5px"><span>{l}</span><span style="height:10px;border-radius:5px;background:rgba(255,255,255,.25);overflow:hidden"><i style="display:block;height:100%;width:{w}%;background:#fff"></i></span><b style="text-align:right">{n}</b></div>'
                                                                    for l, w, n in [('0 à 49 pts', 100, 19), ('50 à 99 pts', 47, 9), ('100 pts · 5 €', 26, 5), ('200 pts · 12 €', 10, 2)])
       + '</div><div class="pied" style="margin-top:22px"><span>2 140 points gagnés ce mois</span><span>35 clients sur la carte</span></div></div>'
       '<div class="under"><div><b>35</b>inscrits</div><div><b>14</b>actifs ce mois</div><div><b>9</b>à moins de 50 pts</div><div><b style="color:var(--green)">7</b>bons</div></div></div>')
strip = ''.join(f'<div><b>{v}</b><span>{l}</span></div>' for v, l in [('64', 'inscrits'), ('21', 'actifs ce mois'), ('47', 'reconnaissances'), ('24', 'clients reconnus'), ('6', 'récompenses prêtes'), ('31 %', 'des acheteurs inscrits')])
A2 = (entete('Cartes de fidélité') + f'<div class="sec">Les cartes du magasin<small>chaque case : combien de clients en sont là · soldes actuels, tous magasins</small>{per}</div>'
      + f'<div class="card"><div class="strip">{strip}</div></div>'
      + '<div class="g3">' + phys('Carte Café', '10 timbres · 1 café offert', '#8D1D2C', CAFE, 10, 4, 40, 15) + phys('Carte Viennoiserie', '8 timbres · 1 croissant offert', '#B0792A', VIEN, 8, 2, 17, 6) + pts + '</div>'
      + '<p class="note">Lecture : sur la Carte Café, 9 clients ont 1 timbre, 1 client en a 9 ; 4 cafés sont à offrir. Une carte pleine devient une récompense prête.</p>')
open(os.path.join(ICI, 'a2-cartes.html'), 'w').write(page('A2 · Les cartes', f'<div class="wrap">{A2}{par_client()}</div>'))

# ── A3 · À faire aujourd'hui ────────────────────────────────────────────────────────────────────────────────────
def liste(L): return '<ul>' + ''.join(f'<li>{a}<span>{b}</span></li>' for a, b in L) + '</ul>'
todo = [('Récompenses à offrir', '6', 'à rappeler au client quand il passe en caisse', [('Nadia L.', 'Carte Café · 1 café'), ('Inès R.', 'Carte Viennoiserie · 1 croissant'), ('Julie M.', 'Points · bon de 5 €'), ('Marc P.', 'Points · bon de 12 €')], 'Voir les 6 ›'),
        ('Presque au cadeau', '4', 'à 1 ou 2 timbres : un passage et c’est gagné', [('Karim B.', 'Viennoiserie · 7/8'), ('Marc P.', 'Café · 9/10'), ('Julie M.', 'Café · 8/10'), ('Tom V.', 'Points · 96/100')], 'Voir les 4 ›'),
        ('Clients à inscrire', '47', 'reconnus en caisse ce mois, sans carte', [('Fanbase · Fidèle', '3 clients'), ('Hebdo · Régulier', '9 clients'), ('Occasionnel', '20 clients'), ('Découverte', '15 clients')], 'Commencer par les plus fidèles ›')]
T = ''.join(f'<div class="card pad"><div style="font-size:15px">{t}</div><div class="big">{n}</div><div style="font-size:12px;color:var(--muted)">{s}</div>{liste(L)}<div class="act">{a}</div></div>' for t, n, s, L, a in todo)
A3 = (entete('Cartes de fidélité') + '<div class="sec">À faire aujourd’hui<small>Halle · jeudi 13 novembre</small></div>'
      + f'<div class="todo">{T}</div>'
      + f'<div class="sec">Où en est le magasin<small>ce mois</small>{per}</div><div class="card"><div class="strip">{strip}</div></div>'
      + '<div class="g3">'
      + ''.join(f'<div class="card"><div class="ct"><span class="carte-ic" style="background:{c}"></span>{n}<small>{s}</small></div><div class="pad">{v}</div></div>' for c, n, s, v in
                [('#8D1D2C', 'Carte Café', '40 clients · 15 actifs', mini(CAFE, '#8D1D2C')), ('#B0792A', 'Carte Viennoiserie', '17 clients · 6 actifs', mini(VIEN, '#B0792A')), ('#1B6FA8', 'Points Atelier', '35 clients · 14 actifs', pal)])
      + '</div><p class="note">Noms fictifs. Les « clients à inscrire » viennent des profils de fidélité (clients reconnus en caisse sans compte de carte).</p>')
open(os.path.join(ICI, 'a3-a-faire.html'), 'w').write(page('A3 · À faire aujourd’hui', f'<div class="wrap">{A3}{par_client()}</div>'))
print('ok')
