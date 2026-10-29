# Gotham n'existe dans le dépôt qu'en OpenType à contours CFF (public/assets/ds/fonts/*.otf),
# que TCPDF ne sait pas lire : ce script en fait des TrueType (contours quadratiques).
# Les trois TrueType de ce dossier en sortent :
#
#   pip install fonttools
#   python3 otf2ttf.py ../../../../public/assets/ds/fonts/Gotham_Light.otf  Gotham-Light.ttf
#   python3 otf2ttf.py ../../../../public/assets/ds/fonts/Gotham_Medium.otf Gotham-Medium.ttf
#   python3 otf2ttf.py ../../../../public/assets/ds/fonts/Gotham_Black.otf  Gotham-Black.ttf
#
# Seules ces trois graisses (et Thin / Extra Light) portent le signe « € » : Gotham_Regular.otf
# et Gotham_Regular_New.otf sont en fait des Black sans « € ».
import sys
from fontTools.ttLib import TTFont, newTable
from fontTools.pens.cu2quPen import Cu2QuPen
from fontTools.pens.ttGlyphPen import TTGlyphPen


def convert(src, dst, max_err=1.0):
    f = TTFont(src)
    order = f.getGlyphOrder()
    gs = f.getGlyphSet()
    glyf = {}
    for name in order:
        pen = TTGlyphPen(gs)
        gs[name].draw(Cu2QuPen(pen, max_err, reverse_direction=True))
        glyf[name] = pen.glyph()
    t = newTable('glyf')
    t.glyphOrder = order
    t.glyphs = glyf
    f['glyf'] = t
    f['loca'] = newTable('loca')
    maxp = newTable('maxp')
    maxp.tableVersion = 0x00010000
    for a in ('maxZones', 'maxTwilightPoints', 'maxStorage', 'maxFunctionDefs', 'maxInstructionDefs',
              'maxStackElements', 'maxSizeOfInstructions', 'maxComponentElements'):
        setattr(maxp, a, 0)
    maxp.maxZones = 1
    f['maxp'] = maxp
    post = f['post']
    post.formatType = 2.0
    post.extraNames = []
    post.mapping = {}
    post.glyphOrder = order
    f['head'].glyphDataFormat = 0
    del f['CFF ']
    if 'VORG' in f:
        del f['VORG']
    f.sfntVersion = '\x00\x01\x00\x00'
    f.save(dst)


convert(sys.argv[1], sys.argv[2])
