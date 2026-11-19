"""Construit le modèle de règlement de travail du réseau L'Atelier By (.docx) à partir de modele.txt.

Usage (depuis ce dossier) :
    python3 construire.py modele.txt Reglement-de-travail-modele-reseau-LAtelierBy-v1.0.docx
Demande python-docx. Pour l'aperçu PDF : LibreOffice (soffice --headless --convert-to pdf …).

Balisage de modele.txt : '# [clé] Chapitre', '## [clé] Article', '### Sous-titre', '#### Intertitre', '- puce',
'  - puce 2', '> note' (encadré gris, à retirer avant l'affichage), '!PAGE', '!TOC', '!TABLE … !ENDTABLE' (cellules
séparées par |), '!TITRE', '!SOUSTITRE', '!PETIT', '!ESPACE', '!NOTETITRE', '!SOUS', '!ANNEXE'.
En ligne : **gras**, [[à compléter]] (surligné en jaune), {{art:clé}} / {{ch:clé}} (renvois numérotés automatiquement).
Chapitres et articles sont numérotés dans l'ordre du texte : ajouter un article renumérote la suite et les renvois.
"""
import re, sys
import docx
from docx.enum.text import WD_COLOR_INDEX, WD_ALIGN_PARAGRAPH, WD_BREAK
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Pt, RGBColor, Cm

MODELE, SORTIE = sys.argv[1:3]
ENCRE, GRIS, RUBY, FILET, PAPIER = RGBColor(0x1c, 0x1a, 0x17), RGBColor(0x7a, 0x73, 0x6a), '8D1D2C', 'E6E0D8', 'F3EFE8'
texte = open(MODELE, encoding='utf-8').read()
lignes = texte.split('\n')

# ── 2. numérotation et renvois ──
num_ch, num_art, refs = 0, 0, {}
blocs = []
for l in lignes:
    m = re.match(r'^(#{1,2}) (?:\[(\w+)\] )?(.*)$', l)
    if m and m.group(1) == '#':
        num_ch += 1
        if m.group(2): refs['ch:' + m.group(2)] = 'chapitre %d' % num_ch
        blocs.append(('ch', num_ch, m.group(3)))
    elif m and m.group(1) == '##':
        num_art += 1
        if m.group(2): refs['art:' + m.group(2)] = 'article %d' % num_art
        blocs.append(('art', num_art, m.group(3)))
    else:
        blocs.append(('l', None, l))
def renvois(t):
    return re.sub(r'\{\{((?:art|ch):\w+)\}\}', lambda m: refs[m.group(1)], t)

# ── 3. le document ──
doc = docx.Document()
sec = doc.sections[0]
sec.page_height, sec.page_width = Cm(29.7), Cm(21.0)
sec.left_margin = sec.right_margin = Cm(2.2); sec.top_margin = Cm(2.2); sec.bottom_margin = Cm(2.0)
st = doc.styles
def police(style, taille, gras=False, couleur=ENCRE, avant=0, apres=6, suivant=False):
    s = st[style]; s.font.name = 'Arial'; s.font.size = Pt(taille); s.font.bold = gras; s.font.color.rgb = couleur
    s.element.rPr.rFonts.set(qn('w:eastAsia'), 'Arial')
    pf = s.paragraph_format; pf.space_before = Pt(avant); pf.space_after = Pt(apres); pf.keep_with_next = suivant
police('Normal', 10, apres=6); st['Normal'].paragraph_format.line_spacing = 1.15
police('Heading 1', 14, True, ENCRE, 18, 8, True)
police('Heading 2', 11, True, ENCRE, 12, 4, True)
police('Heading 3', 10, True, GRIS, 8, 3, True)
police('Title', 30, True, ENCRE, 0, 6)
for s in ('List Bullet', 'List Bullet 2'):
    police(s, 10, apres=3); st[s].paragraph_format.line_spacing = 1.15

def filet_bas(p, couleur=RUBY, taille=8):
    pPr = p._p.get_or_add_pPr(); b = OxmlElement('w:pBdr'); e = OxmlElement('w:bottom')
    for k, v in (('w:val', 'single'), ('w:sz', str(taille)), ('w:space', '4'), ('w:color', couleur)): e.set(qn(k), v)
    b.append(e); pPr.append(b)

def ombre(p, couleur=PAPIER, bord=None):
    pPr = p._p.get_or_add_pPr()
    if bord:
        b = OxmlElement('w:pBdr'); e = OxmlElement('w:left')
        for k, v in (('w:val', 'single'), ('w:sz', '18'), ('w:space', '6'), ('w:color', bord)): e.set(qn(k), v)
        b.append(e); pPr.append(b)
    shd = OxmlElement('w:shd'); shd.set(qn('w:val'), 'clear'); shd.set(qn('w:color'), 'auto'); shd.set(qn('w:fill'), couleur); pPr.append(shd)

def en_ligne(p, t, gras=False, taille=None, couleur=None, italique=False):
    t = renvois(t)
    for bout in re.split(r'(\*\*[^*]+\*\*|\[\[[^\]]*\]\])', t):
        if not bout: continue
        r = p.add_run()
        if bout.startswith('**'):
            r.text = bout[2:-2]; r.bold = True
        elif bout.startswith('[['):
            r.text = '[' + bout[2:-2] + ']'; r.font.highlight_color = WD_COLOR_INDEX.YELLOW; r.bold = gras
        else:
            r.text = bout; r.bold = gras or None
        if taille: r.font.size = Pt(taille)
        if couleur: r.font.color.rgb = couleur
        if italique: r.italic = True
    return p

def champ(p, instr, defaut=''):
    r = p.add_run(); f = OxmlElement('w:fldChar'); f.set(qn('w:fldCharType'), 'begin'); r._r.append(f)
    r = p.add_run(); i = OxmlElement('w:instrText'); i.set(qn('xml:space'), 'preserve'); i.text = instr; r._r.append(i)
    r = p.add_run(); f = OxmlElement('w:fldChar'); f.set(qn('w:fldCharType'), 'separate'); r._r.append(f)
    p.add_run(defaut)
    r = p.add_run(); f = OxmlElement('w:fldChar'); f.set(qn('w:fldCharType'), 'end'); r._r.append(f)

def tableau(rangs):
    rangs = [[c.strip() for c in r.split('|')] for r in rangs if r.strip()]
    n = max(len(r) for r in rangs)
    t = doc.add_table(rows=0, cols=n); t.style = 'Table Grid'; t.alignment = WD_TABLE_ALIGNMENT.CENTER
    larg = Cm(16.6)
    largeurs = [Cm(6.2), Cm(10.4)] if n == 2 else ([Cm(3.4)] + [Cm(1.886)] * (n - 1) if n == 8 else [larg / n] * n)
    entete = n > 2
    for k, r in enumerate(rangs):
        cells = t.add_row().cells
        for j in range(n):
            c = cells[j]; c.width = largeurs[j]
            p = c.paragraphs[0]; p.paragraph_format.space_after = Pt(2)
            en_ligne(p, r[j] if j < len(r) else '', gras=(j == 0 and not entete) or (entete and k == 0), taille=9)
            if entete and k == 0:
                tcPr = c._tc.get_or_add_tcPr(); shd = OxmlElement('w:shd')
                shd.set(qn('w:val'), 'clear'); shd.set(qn('w:color'), 'auto'); shd.set(qn('w:fill'), PAPIER); tcPr.append(shd)
    # filets fins et clairs
    tblPr = t._tbl.tblPr; bords = OxmlElement('w:tblBorders')
    for cote in ('top', 'left', 'bottom', 'right', 'insideH', 'insideV'):
        e = OxmlElement('w:' + cote); e.set(qn('w:val'), 'single'); e.set(qn('w:sz'), '4'); e.set(qn('w:color'), FILET); bords.append(e)
    apres = [c for c in tblPr if c.tag in (qn('w:shd'), qn('w:tblLayout'), qn('w:tblCellMar'), qn('w:tblLook'), qn('w:tblCaption'), qn('w:tblDescription'))]
    if apres: apres[0].addprevious(bords)
    else: tblPr.append(bords)
    doc.add_paragraph().paragraph_format.space_after = Pt(2)

# en-tête et pied
h = sec.header.paragraphs[0]; en_ligne(h, 'L’Atelier By · Règlement de travail · [[Société franchisée]]', taille=8, couleur=GRIS); filet_bas(h, RUBY, 6)
f = sec.footer.paragraphs[0]
r = f.add_run('Modèle réseau v1.0 du 19/11/2026'); r.font.size = Pt(8); r.font.color.rgb = GRIS
r = f.add_run('\t\tPage '); r.font.size = Pt(8); r.font.color.rgb = GRIS
champ(f, 'PAGE', '1'); r = f.add_run(' / '); r.font.size = Pt(8); r.font.color.rgb = GRIS; champ(f, 'NUMPAGES', '1')

i = 0
note = []
def vider_note():
    global note
    if not note: return
    for k, t in enumerate(note):
        p = doc.add_paragraph(); ombre(p, PAPIER, '7A736A')
        p.paragraph_format.space_after = Pt(0 if k < len(note) - 1 else 8); p.paragraph_format.left_indent = Cm(0.25)
        en_ligne(p, t, taille=9, couleur=RGBColor(0x5c, 0x58, 0x52))
    note = []
while i < len(blocs):
    genre, n, t = blocs[i]
    if genre == 'l' and t.startswith('>'):
        note.append(t.lstrip('> ').rstrip()) if t.strip('> ') else None; i += 1; continue
    vider_note()
    if genre == 'ch':
        p = doc.add_paragraph(style='Heading 1'); en_ligne(p, 'Chapitre %d · %s' % (n, t)); filet_bas(p, RUBY, 8)
    elif genre == 'art':
        p = doc.add_paragraph(style='Heading 2'); en_ligne(p, 'Article %d · %s' % (n, t))
    else:
        s = t.rstrip()
        if s == '': pass
        elif s == '!PAGE': doc.add_paragraph().add_run().add_break(WD_BREAK.PAGE)
        elif s == '!ESPACE': doc.add_paragraph().paragraph_format.space_after = Pt(24)
        elif s == '!TOC':
            p = doc.add_paragraph(style='Heading 1'); p.add_run('Table des matières'); filet_bas(p)
            sommaire = [('Chapitre %d · %s' % (n2, renvois(re.sub(r'\[\[|\]\]', '', t2)))) for g2, n2, t2 in blocs if g2 == 'ch']
            p = doc.add_paragraph(); en_ligne(p, 'Dans Word : clic droit sur la table, puis « Mettre à jour les champs », pour les articles et les numéros de page.', taille=8, couleur=GRIS)
            # Le champ TOC court sur plusieurs paragraphes : la liste des chapitres en est le résultat provisoire,
            # que Word remplace à la mise à jour (pas de doublon).
            def fc(par, genre):
                r = par.add_run(); e = OxmlElement('w:fldChar'); e.set(qn('w:fldCharType'), genre); r._r.append(e)
            for k, x in enumerate(sommaire):
                p = doc.add_paragraph(); p.paragraph_format.space_after = Pt(1)
                if k == 0:
                    fc(p, 'begin'); r = p.add_run(); it = OxmlElement('w:instrText'); it.set(qn('xml:space'), 'preserve')
                    it.text = 'TOC \\o "1-2" \\h \\z \\u'; r._r.append(it); fc(p, 'separate')
                en_ligne(p, x, taille=9.5)
                if k == len(sommaire) - 1: fc(p, 'end')
        elif s.startswith('!TITRE '):
            for _ in range(5): doc.add_paragraph()
            p = doc.add_paragraph(style='Title'); en_ligne(p, s[7:]); filet_bas(p, RUBY, 12)
        elif s.startswith('!SOUSTITRE '):
            p = doc.add_paragraph(); en_ligne(p, s[11:], taille=14, couleur=ENCRE)
        elif s.startswith('!PETIT '):
            p = doc.add_paragraph(); en_ligne(p, s[7:], taille=8, couleur=GRIS)
        elif s.startswith('!NOTETITRE ') or s.startswith('!ANNEXE '):
            p = doc.add_paragraph(style='Heading 1'); en_ligne(p, s.split(' ', 1)[1]); filet_bas(p, RUBY, 8)
        elif s.startswith('!SOUS '):
            p = doc.add_paragraph(style='Heading 3'); en_ligne(p, s[6:])
        elif s == '!TABLE':
            j = i + 1; rangs = []
            while blocs[j][2].strip() != '!ENDTABLE': rangs.append(blocs[j][2]); j += 1
            tableau(rangs); i = j
        elif s.startswith('### '):
            p = doc.add_paragraph(style='Heading 3'); en_ligne(p, s[4:])
        elif s.startswith('#### '):
            p = doc.add_paragraph(); p.paragraph_format.space_before = Pt(6); p.paragraph_format.keep_with_next = True; en_ligne(p, s[5:], gras=True)
        elif s.startswith('  - '):
            en_ligne(doc.add_paragraph(style='List Bullet 2'), s[4:])
        elif s.startswith('- '):
            en_ligne(doc.add_paragraph(style='List Bullet'), s[2:])
        else:
            en_ligne(doc.add_paragraph(), s)
    i += 1
vider_note()

# Word met les champs (table des matières, pages) à jour à l'ouverture
reg = doc.settings.element; u = OxmlElement('w:updateFields'); u.set(qn('w:val'), 'true')
suite = ('hdrShapeDefaults', 'footnotePr', 'endnotePr', 'compat', 'docVars', 'rsids', 'mathPr', 'attachedSchema', 'themeFontLang',
         'clrSchemeMapping', 'doNotIncludeSubdocsInStats', 'doNotAutoCompressPictures', 'forceUpgrade', 'captions',
         'readModeInkLockDown', 'smartTagType', 'schemaLibrary', 'shapeDefaults', 'doNotEmbedSmartTags', 'decimalSymbol', 'listSeparator')
apres = [c for c in reg if c.tag in [qn('w:' + x) for x in suite]]
if apres: apres[0].addprevious(u)
else: reg.append(u)
for z in reg.findall(qn('w:zoom')):
    if z.get(qn('w:percent')) is None: z.set(qn('w:percent'), '100')
doc.core_properties.title = 'Règlement de travail · modèle du réseau L’Atelier By'
doc.core_properties.author = 'L’Atelier By'
doc.save(SORTIE)
print('chapitres', num_ch, '· articles', num_art, '· renvois', sorted(refs))
restes = [l for _, _, l in blocs if '@@' in l or '{{' in l]
print('balises restantes :', restes[:5])
