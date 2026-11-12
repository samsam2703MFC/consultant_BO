# Rapport de réunion « Objectif 2027 » : rapport.md (tenu à jour avec le doc de la réunion) rendu en PDF A4 au gabarit
# des documents de gestion du design system, version A (docs/design-system/DESIGN_SYSTEM.md, §13.4) : Gotham seule
# (400 = Gotham_Medium, 700 = Gotham_Regular), Ruby #8D1D2C pour le filet d'en-tête et les encadrés, texte sans gras,
# nombres à droite, en-têtes de tableau en petites capitales, pied « Page n / N ». Chromium (Playwright) compose le PDF.
#
#   python3 docs/dossiers/objectif-2027/generer.py
#
# Syntaxe de rapport.md : # titre · ## section · paragraphes · - puces · 1. listes · - [ ] tâches · tableaux | a | b |
# · > [Libellé] encadré (lignes « > - » = puces de l'encadré) · ~ note en petit · $$ formule · {{figure}}.
import base64, datetime, html, json, os, re, subprocess, sys, tempfile

ICI = os.path.dirname(os.path.abspath(__file__))
RACINE = os.path.abspath(os.path.join(ICI, '..', '..', '..'))
FONTS = os.path.join(RACINE, 'public', 'assets', 'ds', 'fonts')
LOGO = 'data:image/png;base64,' + base64.b64encode(open(os.path.join(RACINE, 'public', 'assets', 'img', 'logo.png'), 'rb').read()).decode()
SOURCE = os.path.join(ICI, 'rapport.md')
HTML_OUT = os.path.join(tempfile.gettempdir(), 'objectif-2027-rapport.html')   # page intermédiaire, hors dépôt
PDF_OUT = os.path.join(ICI, 'Objectif-2027-rapport-reunion.pdf')
TYPE = 'Rapport de réunion'
META = json.load(open(os.path.join(ICI, 'dossier.json'), encoding='utf-8'))

INK, MUTED, FAINT, LINE, HEAD, RUBY = '#1c1a17', '#7a736a', '#b8b2a8', '#e6e0d8', '#fbf9f5', '#8D1D2C'
H = lambda s: html.escape(str(s), quote=True)

# --- Figures (mêmes données que les visuels du doc)
def fig_patisserie():
    cats = [('e46', 'Entremets 4/6 pers.', RUBY, '#ffffff'), ('e180', 'Entremets Ø 180', '#78554B', '#ffffff'),
            ('eind', 'Entremets individuels', '#F2C9A0', INK), ('pind', 'Pâtisserie individuelle', FAINT, INK),
            ('autres', 'Autres', LINE, INK)]
    mags = [('Corbais', 1757750, {'e46': 35671, 'e180': 21321, 'eind': 26534, 'pind': 11924, 'autres': 4987}),
            ('Halle', 646201, {'e46': 15128, 'e180': 11609, 'eind': 6285, 'pind': 8339, 'autres': 0}),
            ('Sombreffe', 649050, {'e46': 9800, 'e180': 7551, 'eind': 12355, 'pind': 7797, 'autres': 3586}),
            ('Gosselies', 660000, {'e46': 5149, 'e180': 5940, 'eind': 8207, 'pind': 6557, 'autres': 8982})]
    W, x0, span, y0, bh, step = 680, 90, 430, 46, 26, 40
    mx = max(sum(v.values()) for _, _, v in mags)
    out = [f'<svg viewBox="0 0 {W} {y0 + step * len(mags) + 6}" class="fig" role="img" aria-label="Poids de la pâtisserie par magasin">']
    lx = 0
    for k, nom, c, _ in cats:
        out.append(f'<rect x="{lx}" y="6" width="10" height="10" fill="{c}"/><text x="{lx + 15}" y="15" class="lg">{H(nom)}</text>')
        lx += len(nom) * 5.6 + 34
    for i, (m, caMag, v) in enumerate(mags):
        y = y0 + i * step; cx = x0; t = sum(v.values())
        out.append(f'<text x="0" y="{y + bh / 2 + 4}" class="mg">{H(m)}</text>')
        for k, nom, c, enc in cats:
            w = v[k] / mx * span
            if w <= 0: continue
            out.append(f'<rect x="{cx:.1f}" y="{y}" width="{max(w - 1, 0):.1f}" height="{bh}" fill="{c}"/>')
            if w >= 40:
                out.append(f'<text x="{cx + w / 2:.1f}" y="{y + bh / 2 + 4}" text-anchor="middle" class="vl" fill="{enc}">{round(v[k] / 1000)} k€</text>')
            cx += w
        pct = f'{t / caMag * 100:.1f}'.replace('.', ',')
        out.append(f'<text x="{cx + 8:.1f}" y="{y + bh / 2 + 4}" class="tt">{round(t / 1000)} k€ · {pct} % du CA</text>')
    out.append('</svg>')
    return ('<figure class="figure">' + ''.join(out) +
            '<figcaption>CA HT par an, hors tartes, tartissières et cakes · Autres = gâteaux d’anniversaire, mignardises, desserts · '
            'pièces vendues × prix HT actuel, 3 août → 7 novembre 2026, annualisé.</figcaption></figure>')

def fig_feuille_de_route():
    voies = ['Gamme pâtisserie', 'Réseau et process', 'Outils (développement continu)']
    trim = [('T1 2027', 'janv. – mars', [['Recettes complètes au panel', 'Prix usine du gâteau 10 parts', 'Test à la part : 1 magasin', 'Coupes de gamme arrêtées', 'Kit décor anniversaire unique'],
                                        ['Corbais et Sombreffe : 80 % des tâches sur la tablette', 'Avenant +0,5 % signé', 'Tenue et packaging choisis', 'Anderlues : formation, puis ouverture en mars'],
                                        ['Cockpit : coefficient réel par produit, 4+1 compris']]),
            ('T2 2027', 'avr. – juin', [['Vente à la part : 4 magasins', 'Grille de prix unique', 'Entremets revus à ×3'],
                                       ['Majoration en vigueur', 'Dour : formation en mai, ouverture en juin', 'Tenue et packaging en place'],
                                       ['Tablette : invendus à la part', 'Parcours stagiaire 4 semaines']]),
            ('T3 2027', 'juil. – sept.', [['Reste de la gamme à ×3', 'Bilan des marges à 6 mois'], ['Contrôle des SOP au scoring trimestriel'],
                                         ['Alerte sous 80 % de tâches', 'Brouillon de mise en demeure']]),
            ('T4 2027', 'oct. – déc.', [['Gamme des fêtes à ×3', 'Bilan 2027, gamme 2028'], ['Décision sur la grille de redevances'],
                                       ['Commande à l’usine depuis la tablette']])]
    rows = ['<tr><th class="q"></th>' + ''.join(f'<th>{H(v)}</th>' for v in voies) + '</tr>']
    for n, (t, mois, cases) in enumerate(trim):
        rows.append(f'<tr><td class="q"><span class="qt">{H(t)}</span><span class="qm">{H(mois)}</span></td>' +
                    ''.join('<td><div class="case">' + ''.join(f'<div>{H(x)}</div>' for x in c) + '</div></td>' for c in cases) + '</tr>')
        if n == 0:
            rows.append('<tr class="gate"><td></td><td colspan="3"><span class="losange"></span>Décision : étendre la vente à la part si le ×3 est tenu</td></tr>')
    return ('<figure class="figure"><table class="fdr">' + ''.join(rows) + '</table>'
            '<figcaption>Feuille de route 2027 proposée · 3 chantiers × 4 trimestres, 1 décision.</figcaption></figure>')

def fig_saisonnalite():
    mois = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.']
    fam = [('Viennoiserie', [93, 94, 96, 112, 99, 101, 93, 102, 103, 110, 107, 90]), ('Boulangerie', [104, 98, 102, 105, 104, 95, 89, 97, 90, 98, 107, 111]),
           ('Traiteur', [93, 108, 117, 115, 94, 123, 81, 75, 101, 97, 93, 102]), ('Quiches', [97, 125, 95, 110, 101, 99, 88, 88, 85, 102, 105, 104]),
           ('Tartes', [82, 96, 107, 113, 123, 94, 77, 98, 84, 114, 129, 83]), ('Pâtisserie, entremets', [84, 113, 111, 107, 89, 84, 77, 93, 92, 116, 133, 100]),
           ('Biscuiterie', [109, 117, 115, 126, 99, 95, 74, 80, 87, 100, 96, 101]), ('Boissons', [87, 90, 95, 107, 94, 112, 94, 89, 106, 134, 94, 98]),
           ('Glace by Rawette', [0, 0, 0, 219, 289, 209, 109, 143, 107, 54, 70, 0]), ('Noël et galette', [740, 14, 4, 15, 1, 9, 4, 1, 0, 1, 6, 406]),
           ('Cougnou', [40, 1, 0, 0, 0, 0, 0, 0, 0, 14, 634, 511])]
    def fond(v):
        if v >= 100: a = min(0.75, (v - 100) / 200 + 0.08); return f'rgba(120,85,75,{a:.2f})'          # Pop-Up #78554B
        a = min(0.6, (100 - v) / 130 + 0.05); return f'rgba(184,178,168,{a:.2f})'                       # gris #b8b2a8
    enc = lambda v: '#ffffff' if v >= 260 else INK
    rows = ['<tr><th></th>' + ''.join(f'<th>{m}</th>' for m in mois) + '</tr>']
    for nom, vs in fam:
        rows.append(f'<tr><td class="fam">{H(nom)}</td>' + ''.join(f'<td style="background:{fond(v)};color:{enc(v)}">{v}</td>' for v in vs) + '</tr>')
    return ('<figure class="figure"><div class="fig-titre">Tartes et pâtisserie creusent en juillet et montent à l’automne</div>'
            '<table class="chaleur">' + ''.join(rows) + '</table>'
            '<figcaption>Ventes par jour, 100 = moyenne de l’année · Corbais, Gosselies, Sombreffe · décembre 2025 → novembre 2026 '
            '(novembre : 12 jours) · pièces × prix HT actuel.</figcaption></figure>')

# Packaging : 3 boîtes à l'échelle (simulation d'emballage validée le 12/11/2026), partagé avec le visuel du doc
def emballage_formes():
    import math
    def arc(cx, cy, r, a0, a1, n=24):
        return [(cx + r * math.cos(math.radians(a0 + (a1 - a0) * k / n)), cy + r * math.sin(math.radians(a0 + (a1 - a0) * k / n))) for k in range(n + 1)]
    def quinconce(n, R=145, th=36, e=5):
        h = math.radians(th / 2); W = R * (2 - math.cos(h)); pas = R * math.sin(h) + e / math.cos(h); parts = []
        for i in range(n):
            x = R * math.sin(h) + i * pas
            if i % 2 == 0: parts.append([(x, W)] + arc(x, W, R, -90 - th / 2, -90 + th / 2))
            else: parts.append([(x, 0)] + arc(x, 0, R, 90 - th / 2, 90 + th / 2))
        L = 2 * R * math.sin(h) + (n - 1) * pas
        return parts, L, W
    def centre(polys, w, h, bw, bh, swap=False):
        if swap: polys = [[(y, x) for x, y in p] for p in polys]; w, h = h, w
        ox, oy = (bw - w) / 2, (bh - h) / 2
        return [[(x + ox, y + oy) for x, y in p] for p in polys]
    boites = {'S': (158, 150), 'M': (295, 158), 'L': (295, 295)}
    t = {'S': centre([[(0, 0)] + arc(0, 140, 140, -90, 0) + [(0, 140)]], 140, 140, 158, 150),
         'M': centre([arc(140, 140, 140, 180, 360)], 280, 140, 295, 158),
         'L': centre([arc(140, 140, 140, 0, 360, 72)], 280, 280, 295, 295)}
    p2, L2, W2 = quinconce(2); p5, L5, W5 = quinconce(5)
    cake = [arc(145, 145, 145, 0, 360, 72)] + [[(145, 145), (145 + 145 * math.cos(math.radians(-90 + 36 * k)), 145 + 145 * math.sin(math.radians(-90 + 36 * k)))] for k in range(10)]
    g = {'S': centre(p2, L2, W2, 158, 150, swap=True), 'M': centre(p5, L5, W5, 295, 158),
         'L': centre(cake, 290, 290, 295, 295)}
    return boites, t, g

def chemin(pts, s, ox, oy, ferme=True):
    d = 'M' + 'L'.join(f'{ox + x * s:.1f} {oy + y * s:.1f}' for x, y in pts)
    return d + ('Z' if ferme else '')

def fig_emballage():
    boites, t, g = emballage_formes()
    s, x0, gap, y1 = 0.6, 128, 34, 46
    xs = {'S': x0, 'M': x0 + 158 * s + gap, 'L': x0 + 158 * s + gap + 295 * s + gap}
    y2 = y1 + 295 * s + 40
    o = [f'<svg viewBox="0 0 680 {y2 + 295 * s + 26:.0f}" class="fig" role="img" aria-label="Trois boîtes pour toutes les tartes, quiches et parts de gâteau">']
    for k, nom in (('S', '158 × 150 mm'), ('M', '295 × 158 mm'), ('L', '295 × 295 mm')):
        x = xs[k]; on = k == 'M'
        o.append(f'<circle cx="{x + 5}" cy="14" r="4.5" fill="{RUBY if on else "#fff"}" stroke="{RUBY if on else FAINT}" stroke-width="1.2"/>')
        o.append(f'<text x="{x + 15}" y="18" class="mg">Boîte {k}</text><text x="{x}" y="33" class="lg">{nom}</text>')
    lignes = [(y1, t, '#78554B', ['Tartes, quiches', 'Ø 280 mm'], {'S': '1/4 · 65 %', 'M': '1/2 · 66 %', 'L': 'entière · 71 %'}, False),
              (y2, g, RUBY, ['Gâteau 10 parts', 'Ø 290 mm, en quinconce'], {'S': '1 ou 2 parts · 28 à 56 %', 'M': '4+1 = 5 parts · 71 %', 'L': 'gâteau entier · 76 %'}, True)]
    for y, formes, c, lab, leg, gat in lignes:
        o.append(f'<text x="0" y="{y + 16}" class="mg">{lab[0]}</text><text x="0" y="{y + 30}" class="lg">{lab[1]}</text>')
        for k, (bw, bh) in boites.items():
            x = xs[k]
            o.append(f'<rect x="{x}" y="{y}" width="{bw * s:.1f}" height="{bh * s:.1f}" rx="3" fill="none" stroke="{MUTED}" stroke-width="1.2"/>')
            for p in formes[k]:
                if len(p) == 2: o.append(f'<path d="{chemin(p, s, x, y, False)}" stroke="#fff" stroke-width="1"/>')
                else: o.append(f'<path d="{chemin(p, s, x, y)}" fill="{c}" fill-opacity="0.22" stroke="{c}" stroke-width="1"/>')
            o.append(f'<text x="{x}" y="{y + bh * s + 14:.1f}" class="tt">{leg[k]}</text>')
    o.append('</svg>')
    return ('<figure class="figure"><div class="fig-titre">Trois boîtes pour toutes les tartes, quiches et parts de gâteau</div>' + ''.join(o) +
            '<figcaption>Cotes intérieures, à l’échelle · jeu de 2,5 mm par côté, 5 mm entre les parts · % = surface remplie · '
            'LED pleine = boîte allumée par la caisse via l’Arduino (ici, un 4+1 encodé → boîte M).</figcaption></figure>')

FIGURES = {'patisserie-par-magasin': fig_patisserie, 'feuille-de-route': fig_feuille_de_route, 'saisonnalite': fig_saisonnalite, 'emballage': fig_emballage}

# --- Markdown du rapport → HTML
NUM = re.compile(r'^[+−\-]?(\d|×)')
def typo(s):
    # Espaces insécables (design system §13.1) : milliers en espace fine, avant €, %, k€, M€ et la ponctuation haute.
    for _ in range(3):
        s = re.sub(r'(\d) (\d{3})(?!\d)', '\\1\u202f\\2', s)
    s = re.sub(r'(\d) (€|%|k€|M€|h\b|pièces|parts|références|semaines|jours|mois|magasins)', '\\1\u00a0\\2', s)
    s = re.sub(r' ([:;?!»])', '\u00a0\\1', s)
    s = re.sub(r'« ', '«\u00a0', s)
    s = re.sub(r'\b(n°|T[1-4]|×) (\d)', '\\1\u00a0\\2', s)
    return s

def inline(s):
    s = H(typo(s))
    return re.sub(r'\*\*(.+?)\*\*', r'\1', s)          # pas de gras dans le texte (design system §4.3)

def cellule(s, entete=False):
    s = s.strip(); cls = ' class="num"' if (not entete and NUM.match(s) and len(s) < 34) else ''
    return f'<{"th" if entete else "td"}{cls}>{inline(s)}</{"th" if entete else "td"}>'

def tableau(lignes):
    rows = [[c for c in l.strip().strip('|').split('|')] for l in lignes]
    head, body = rows[0], rows[2:]
    # Une colonne est alignée à droite seulement si toutes ses cellules sont des nombres courts (la 1re colonne, jamais).
    def numerique(k):
        vals = [r[k].strip() for r in body if k < len(r) and r[k].strip()]
        return k > 0 and bool(vals) and all(NUM.match(v) and len(v) <= 22 for v in vals)
    num = [numerique(k) for k in range(len(head))]
    cls = ' '.join(c for c in ('large' if len(head) >= 6 else '', 'court' if len(body) <= 7 else '') if c)
    td = lambda k, c, tag: f'<{tag}{" class=\"num\"" if num[k] else ""}>{inline(c.strip())}</{tag}>'
    out = [f'<table class="{cls}"><thead><tr>' + ''.join(td(k, c, 'th') for k, c in enumerate(head)) + '</tr></thead><tbody>']
    for r in body:
        out.append('<tr>' + ''.join(td(k, c, 'td') for k, c in enumerate(r)) + '</tr>')
    out.append('</tbody></table>')
    return ''.join(out)

def liste(lignes, ordonnee):
    items = []
    for l in lignes:
        t = re.sub(r'^\s*(-|\d+\.)\s+', '', l)
        if t.startswith('[ ] ') or t.startswith('[x] '):
            items.append(f'<li class="tache"><span class="case-a-cocher"></span>{inline(t[4:])}</li>')
        else:
            items.append(f'<li>{inline(t)}</li>')
    tag = 'ol' if ordonnee else 'ul'
    cls = ' class="taches"' if any('tache' in i for i in items) else ''
    return f'<{tag}{cls}>' + ''.join(items) + f'</{tag}>'

def encadre(lignes):
    texte = [re.sub(r'^>\s?', '', l) for l in lignes]
    m = re.match(r'^\[(.+?)\]\s*(.*)$', texte[0])
    label, premier = (m.group(1), m.group(2)) if m else ('', texte[0])
    corps = [premier] + texte[1:]
    paras = [l for l in corps if not l.startswith('- ')]; puces = [l for l in corps if l.startswith('- ')]
    out = '<div class="box">' + (f'<div class="label">{inline(label)}</div>' if label else '')
    out += ''.join(f'<p>{inline(p)}</p>' for p in paras if p.strip())
    if puces: out += liste(puces, False)
    return out + '</div>'

def rendre(md):
    blocs, titre, cur = [], '', []
    lignes = md.split('\n')
    i = 0
    while i < len(lignes):
        l = lignes[i]
        if not l.strip(): i += 1; continue
        if l.startswith('# '): titre = l[2:].strip(); i += 1; continue
        if l.startswith('### '): blocs.append(f'<h3>{inline(l[4:].strip())}</h3>'); i += 1; continue
        if l.startswith('## '):
            t = l[3:].strip(); saut = False   # le corps se lit d'un trait ; seules les annexes ouvrent une nouvelle page
            blocs.append(f'<h2 class="saut">{inline(t)}</h2>' if saut else f'<h2>{inline(t)}</h2>'); i += 1; continue
        if l.startswith('{{') and l.strip().endswith('}}'): blocs.append(FIGURES[l.strip()[2:-2]]()); i += 1; continue
        if l.startswith('$$'): blocs.append(f'<div class="formule">{inline(l[2:].strip())}</div>'); i += 1; continue
        if l.startswith('~ '): blocs.append(f'<p class="note">{inline(l[2:])}</p>'); i += 1; continue
        groupe = []
        if l.startswith('|'):
            while i < len(lignes) and lignes[i].startswith('|'): groupe.append(lignes[i]); i += 1
            blocs.append(tableau(groupe)); continue
        if l.startswith('>> '):
            m = re.match(r'^>> \[(.+?)\]\s*(.*)$', l)
            blocs.append(f'<div class="vignette"><div class="vt">{inline(m.group(1))}</div><div class="vc">{inline(m.group(2))}</div></div>'); i += 1; continue
        if l.startswith('>'):
            while i < len(lignes) and lignes[i].startswith('>'): groupe.append(lignes[i]); i += 1
            blocs.append(encadre(groupe)); continue
        if re.match(r'^(-|\d+\.)\s', l):
            ordonnee = bool(re.match(r'^\d+\.', l))
            while i < len(lignes) and re.match(r'^(-|\d+\.)\s', lignes[i]): groupe.append(lignes[i]); i += 1
            blocs.append(liste(groupe, ordonnee)); continue
        while i < len(lignes) and lignes[i].strip() and not re.match(r'^(#|\||>|-\s|\d+\.\s|~ |\$\$|\{\{)', lignes[i]):
            groupe.append(lignes[i].strip()); i += 1
        blocs.append(f'<p>{inline(" ".join(groupe))}</p>')
    # Un titre ne reste jamais seul en bas de page : il part avec le début de sa section (les titres qui se suivent, le
    # bloc qui suit, et le suivant s'il est court). Chaque partie (sauf la synthèse, l'ouverture et les sources) ouvre
    # une nouvelle page.
    out, i = [], 0
    while i < len(blocs):
        b = blocs[i]
        if b.startswith('<h2') or b.startswith('<h3'):
            g = [b]; j = i + 1
            while j < len(blocs) and blocs[j].startswith('<h3') and len(g) < 2: g.append(blocs[j]); j += 1
            if j < len(blocs): g.append(blocs[j]); j += 1
            if j < len(blocs) and re.match(r'<(table class="[^"]*court|figure|div class="box"|div class="formule")', blocs[j]):
                g.append(blocs[j]); j += 1
            if g[0].startswith('<h2 class="saut"'):
                out.append('<div class="saut"></div>'); g[0] = g[0].replace(' class="saut"', '', 1)
            out.append('<div class="ensemble">' + ''.join(g) + '</div>'); i = j; continue
        # Un paragraphe qui annonce la suite (« … : ») part avec le tableau ou la liste qu'il introduit.
        if b.startswith('<p>') and b.endswith(':</p>') and i + 1 < len(blocs) and re.match(r'<(table class="[^"]*court|ul|ol|figure)', blocs[i + 1]):
            out.append('<div class="ensemble">' + b + blocs[i + 1] + '</div>'); i += 2; continue
        out.append(b); i += 1
    return titre, '\n'.join(out)

def fontface():
    return ''.join(f"@font-face{{font-family:'Gotham';src:url('file://{FONTS}/{f}') format('opentype');font-weight:{w};font-display:block}}"
                   for f, w in (('Gotham_Medium.otf', 400), ('Gotham_Regular.otf', 700)))

CSS = fontface() + f'''
@page{{size:A4;margin:14mm 14mm 18mm}}
*{{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}}
body{{margin:0;font:400 11px/1.5 'Gotham',Helvetica,Arial,sans-serif;color:{INK};background:#fff}}
.head{{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:2px solid {RUBY};padding-bottom:12px;margin-bottom:18px}}
.head img{{height:34px;display:block}}
.type{{text-align:right;font-size:10px;color:{MUTED};line-height:1.5}}
.type b{{display:block;font-size:15px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:{INK}}}
h1{{font-size:21px;font-weight:400;line-height:1.25;margin:0 0 14px}}
.refs{{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;padding:10px 0;border-top:1px solid {LINE};border-bottom:1px solid {LINE};margin-bottom:6px}}
.refs .label,.box .label{{font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:{MUTED};margin-bottom:3px}}
.refs .v{{font-size:11px;font-weight:700}}
h2{{font-size:18px;font-weight:400;line-height:1.3;margin:22px 0 10px;padding-top:2px;break-after:avoid}}
h3{{font-size:14px;font-weight:400;line-height:1.3;margin:18px 0 7px;color:{INK};break-after:avoid}}
h2+h3{{margin-top:6px}}
div.saut{{break-before:page;height:0}}
table.chaleur{{table-layout:fixed;font-size:9px}} table.chaleur td{{padding:4px 2px;text-align:center;border-bottom:2px solid #fff}} table.chaleur td.fam{{text-align:left;width:150px;padding-left:0}} table.chaleur th{{text-align:center;padding:4px 2px}}
p{{margin:0 0 8px}}
p.note{{font-size:9.5px;color:{MUTED}}}
ul,ol{{margin:0 0 10px;padding-left:16px}} li{{margin:0 0 3px}}
ul.taches{{list-style:none;padding-left:0}}
li.tache{{display:flex;gap:8px;align-items:flex-start}}
.case-a-cocher{{flex:0 0 9px;height:9px;margin-top:3px;border:1px solid {FAINT};border-radius:2px}}
table{{width:100%;border-collapse:collapse;font-size:10px;margin:4px 0 12px}}
thead{{display:table-header-group}}
th.num{{text-align:right}}
th{{padding:6px 8px;border-bottom:1px solid {LINE};font-size:8.5px;letter-spacing:.06em;text-transform:uppercase;color:{MUTED};font-weight:400;text-align:left;vertical-align:bottom;line-height:1.3}}
td{{padding:6px 8px;border-bottom:1px solid rgba(34,34,34,.06);vertical-align:top}}
td.num{{text-align:right;white-space:nowrap}}
table.large{{font-size:9px}} table.large th{{font-size:7.5px}} table.large td,table.large th{{padding:5px 6px}}
tr{{break-inside:avoid}}
table.court,.ensemble{{break-inside:avoid}}
.box{{border-left:3px solid {RUBY};padding:2px 0 2px 12px;margin:6px 0 14px;break-inside:avoid}}
.box p{{margin:0 0 6px}} .box ul{{margin:0}}
.vignette{{margin:8px 0 14px;padding:14px 18px;border-left:3px solid {RUBY};background:{HEAD};break-inside:avoid}}
.vignette .vt{{font-size:14px;line-height:1.35;margin-bottom:4px}} .vignette .vc{{font-size:11.5px;color:{INK}}}
.formule{{margin:4px 0 12px;padding:8px 12px;border-top:1px solid {LINE};border-bottom:1px solid {LINE};text-align:center;font-size:11px}}
.figure{{margin:6px 0 12px;break-inside:avoid}}
.fig-titre{{font-size:11px;margin-bottom:6px}}
.figure figcaption{{font-size:8.5px;color:{MUTED};margin-top:6px}}
svg.fig{{width:100%;height:auto;display:block;font-family:'Gotham',Helvetica,Arial,sans-serif}}
svg .lg{{font-size:9px;fill:{INK}}} svg .mg{{font-size:10.5px;fill:{INK}}} svg .vl{{font-size:9px}} svg .tt{{font-size:10px;fill:{INK}}}
table.fdr{{font-size:9.5px;margin:0;table-layout:fixed}}
table.fdr th{{border:0;padding:0 6px 6px}} table.fdr th.q,table.fdr td.q{{width:74px}}
table.fdr td{{border:0;padding:3px;vertical-align:top}}
table.fdr .case{{border:1px solid {LINE};border-radius:6px;padding:7px 9px;min-height:62px;line-height:1.45}}
table.fdr .qt{{display:block;font-size:10.5px}} table.fdr .qm{{display:block;font-size:8.5px;color:{MUTED}}}
table.fdr tr.gate td{{padding:4px 3px 6px;font-size:9px;color:{INK}}}
.losange{{display:inline-block;width:8px;height:8px;border:1.5px solid {RUBY};transform:rotate(45deg);margin:0 10px 0 6px;vertical-align:middle}}
'''

def main():
    titre, corps = rendre(open(SOURCE, encoding='utf-8').read())
    version = datetime.date.today().strftime('%d/%m/%Y')
    refs = [('Réunion', META['reunion']), ('Chiffres lus le', META['chiffres']), ('Version', version), ('Magasins', META['magasins'])]
    entete = (f'<div class="head"><img src="{LOGO}" alt="L’Atelier By"><div class="type"><b>{TYPE}</b>'
              f'Objectif 2027 · {H(version)}</div></div><h1>{inline(titre)}</h1>'
              '<div class="refs">' + ''.join(f'<div><div class="label">{H(k)}</div><div class="v">{H(v)}</div></div>' for k, v in refs) + '</div>')
    doc = f'<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>{H(titre)}</title><style>{CSS}</style></head><body>{entete}{corps}</body></html>'
    open(HTML_OUT, 'w', encoding='utf-8').write(doc)
    pied = ('<div style="width:100%;margin:0 14mm;padding-top:6px;border-top:1px solid #e6e0d8;font:7.5px/1.6 Helvetica,Arial,sans-serif;'
            'color:#7a736a;display:flex;justify-content:space-between"><span>L’Atelier By · ' + H(TYPE) + ' Objectif 2027 · ' + H(version) +
            '</span><span>Page <span class="pageNumber"></span> / <span class="totalPages"></span></span></div>')
    js = ("const {chromium}=require('playwright');(async()=>{const b=await chromium.launch();const p=await b.newPage();"
          f"await p.goto('file://{HTML_OUT}',{{waitUntil:'load'}});await p.evaluate(()=>document.fonts.ready);"
          f"await p.pdf({{path:{json.dumps(PDF_OUT)},format:'A4',printBackground:true,preferCSSPageSize:true,displayHeaderFooter:true,"
          f"headerTemplate:'<div></div>',footerTemplate:{json.dumps(pied)}}});await b.close();}})().catch(e=>{{console.error(e);process.exit(1)}});")
    subprocess.run(['node', '-e', js], check=True)
    print('PDF :', PDF_OUT, os.path.getsize(PDF_OUT), 'octets')

if __name__ == '__main__':
    main()
