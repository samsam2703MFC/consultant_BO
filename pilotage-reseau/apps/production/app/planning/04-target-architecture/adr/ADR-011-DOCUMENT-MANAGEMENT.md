# ADR-011 — Jeden właściciel dokumentów i immutable issued document

**Status:** proponowany; jurysdykcja zależy od BD-11

## Kontekst

App i Agent mają równoległe modele faktur/dokumentów, a generowanie może mieszać dane i skutki biznesowe.

## Opcje

1. dokumenty per aplikacja;
2. Documents module jako jedyny owner, renderery jako adaptery;
3. zewnętrzny DMS/fakturowanie jako owner wszystkiego.

## Decyzja

Documents module odpowiada za numer, immutable snapshot, lifecycle i korektę. Renderer/storage/send są adapterami po commit. System zewnętrzny może być formalnym kanałem, ale ERP zachowuje source reference/status/hash.

## Uzasadnienie

Usuwa duplikację, chroni numerację i oddziela dokument od transportu/renderingu.

## Konsekwencje

- issued document nie jest edytowany;
- correction jest nowym dokumentem;
- dwa modele AS-IS wymagają mapy/migracji.

## Ponowne rozważenie

Gdy prawo wymaga, aby certyfikowany system zewnętrzny był jedynym źródłem; wtedy ERP utrzymuje mirror metadata i reconciliation.
