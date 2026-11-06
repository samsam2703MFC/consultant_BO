# Model scoringu

## Skale

Każdy wymiar ma 1–5. Dla korzyści 5 oznacza więcej; dla obciążenia 5 oznacza trudniej/drożej.

| Kod | Kryterium | 1 | 3 | 5 |
|---|---|---|---|---|
| BV | wartość biznesowa | lokalna wygoda | mierzalna poprawa domeny | warunek działania/rozliczeń wielu domen |
| RR | redukcja ryzyka | kosmetyczna | istotne ryzyko operacyjne | bezpieczeństwo, utrata/integralność, błędne rozliczenia |
| FU | częstotliwość użycia | kwartalna/rzadsza | tygodniowa | ciągła/dzienna |
| UU | liczba użytkowników | pojedyncza rola/osoba | kilka ról/jednostek | niemal wszyscy/cały system |
| DI | wpływ na dane | brak/odczyt | ważny rejestr | source of truth/historia nieodtwarzalna |
| UN | odblokowane funkcje | 0–1 | 3–5 | fundament przekrojowy >8 |
| UR | pilność | może czekać | planować w średnim terminie | aktywne P0/deadline/blokada |
| IC | koszt implementacji | XS | M | XL |
| MC | koszt utrzymania | niski | średni | wysoki, wiele runtime/integracji |
| MX | złożoność migracji | brak | backfill/cutover jednej domeny | wiele źródeł, nieodwracalne fakty |
| UQ | niepewność | potwierdzone | część danych/decyzji brak | runtime/semantyka/zakres nieznane |
| RG | ryzyko regresji | izolowane/read-only | zapis w domenie | przekrojowe finanse/stock/auth |

## Zastosowanie

Pomocniczy `Opportunity = 2×BV + 2×RR + FU + UU + 2×DI + 2×UN + 2×UR`. `Burden = 2×IC + MC + 2×MX + UQ + RG`. Wartości nie wyznaczają automatycznie priorytetu. Reguły nadrzędne:

1. P0 bezpieczeństwa/integralności wygrywa z wysokim Opportunity funkcji rozwojowej.
2. Element zależny nie może wyprzedzić twardej zależności.
3. Wysoka niepewność może przesunąć implementację, ale zwiększa priorytet discovery.
4. Nieodtwarzalne dane należy zacząć rejestrować wcześniej niż dashboard.
5. Pionowy fragment z E2E ma pierwszeństwo przed szeroką platformą bez konsumenta.

## Ocena znormalizowanego backlogu

| ID | P | BV | RR | FU | UU | DI | UN | UR | IC | MC | MX | UQ | RG |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| ERP-001 | P0 | 5 | 5 | 5 | 5 | 5 | 5 | 5 | 2 | 2 | 2 | 1 | 5 |
| ERP-002 | P0 | 4 | 5 | 2 | 2 | 5 | 5 | 5 | 2 | 1 | 1 | 5 | 1 |
| ERP-003 | P0 | 5 | 5 | 4 | 4 | 5 | 5 | 5 | 4 | 2 | 5 | 4 | 5 |
| ERP-004 | P0 | 5 | 5 | 5 | 4 | 4 | 5 | 5 | 3 | 2 | 2 | 2 | 2 |
| ERP-005 | P0 | 5 | 5 | 5 | 5 | 5 | 5 | 5 | 5 | 4 | 5 | 4 | 5 |
| ERP-006 | P0 | 5 | 5 | 5 | 5 | 5 | 5 | 5 | 4 | 3 | 4 | 3 | 5 |
| ERP-007 | P0 | 5 | 5 | 5 | 4 | 5 | 5 | 5 | 4 | 4 | 4 | 4 | 5 |
| ERP-008 | P1 | 4 | 4 | 5 | 3 | 3 | 4 | 4 | 3 | 3 | 2 | 3 | 3 |
| ERP-009 | P1 | 5 | 4 | 5 | 5 | 5 | 5 | 4 | 5 | 3 | 5 | 4 | 5 |
| ERP-010 | P0 | 5 | 5 | 5 | 4 | 5 | 5 | 5 | 4 | 2 | 5 | 4 | 5 |
| ERP-011 | P0 | 5 | 5 | 5 | 5 | 5 | 5 | 5 | 4 | 3 | 4 | 3 | 5 |
| ERP-012 | P1 | 4 | 4 | 4 | 3 | 4 | 4 | 4 | 4 | 3 | 3 | 3 | 4 |
| ERP-013 | P0 | 5 | 5 | 5 | 4 | 5 | 5 | 5 | 5 | 3 | 5 | 3 | 5 |
| ERP-014 | P1 | 4 | 4 | 4 | 3 | 5 | 3 | 3 | 4 | 3 | 3 | 3 | 4 |
| ERP-015 | P1 | 5 | 5 | 5 | 4 | 5 | 5 | 4 | 5 | 3 | 5 | 4 | 5 |
| ERP-016 | P0 | 5 | 5 | 5 | 5 | 5 | 5 | 5 | 5 | 4 | 5 | 5 | 5 |
| ERP-017 | P1 | 5 | 4 | 5 | 4 | 5 | 4 | 4 | 5 | 3 | 4 | 4 | 5 |
| ERP-018 | P0 | 5 | 5 | 5 | 5 | 5 | 5 | 5 | 5 | 4 | 5 | 5 | 5 |
| ERP-019 | P0 | 5 | 5 | 5 | 5 | 5 | 5 | 5 | 5 | 4 | 5 | 5 | 5 |
| ERP-020 | P1 | 5 | 5 | 5 | 4 | 5 | 4 | 4 | 5 | 3 | 5 | 4 | 5 |
| ERP-021 | P1 | 5 | 5 | 5 | 4 | 5 | 4 | 4 | 5 | 3 | 4 | 3 | 5 |
| ERP-022 | P0 | 5 | 5 | 5 | 4 | 5 | 5 | 5 | 5 | 4 | 5 | 5 | 5 |
| ERP-023 | P0 | 5 | 5 | 5 | 4 | 5 | 5 | 5 | 5 | 4 | 5 | 5 | 5 |
| ERP-024 | P1 | 5 | 5 | 4 | 4 | 5 | 4 | 4 | 5 | 4 | 4 | 4 | 5 |
| ERP-025 | P1 | 4 | 4 | 5 | 4 | 4 | 3 | 3 | 4 | 4 | 4 | 4 | 4 |
| ERP-026 | P1 | 4 | 4 | 5 | 5 | 5 | 3 | 4 | 5 | 4 | 5 | 5 | 5 |
| ERP-027 | P1 | 4 | 4 | 5 | 4 | 5 | 3 | 3 | 4 | 3 | 3 | 4 | 4 |
| ERP-028 | P0 | 5 | 5 | 5 | 4 | 5 | 5 | 5 | 5 | 4 | 5 | 5 | 5 |
| ERP-029 | P0 | 5 | 5 | 3 | 3 | 5 | 5 | 5 | 4 | 3 | 4 | 4 | 5 |
| ERP-030 | P1 | 5 | 4 | 4 | 4 | 5 | 3 | 3 | 5 | 4 | 4 | 5 | 5 |
| ERP-031 | P1 | 5 | 4 | 5 | 5 | 4 | 5 | 4 | 5 | 4 | 4 | 4 | 4 |
| ERP-032 | P1 | 4 | 4 | 4 | 3 | 5 | 3 | 4 | 4 | 3 | 4 | 3 | 4 |
| ERP-033 | P1 | 4 | 5 | 4 | 3 | 5 | 4 | 4 | 5 | 4 | 3 | 4 | 5 |
| ERP-034 | P2 | 4 | 3 | 4 | 3 | 4 | 3 | 2 | 5 | 4 | 3 | 5 | 4 |
| ERP-035 | P2 | 3 | 2 | 3 | 2 | 3 | 1 | 2 | 3 | 2 | 2 | 4 | 2 |
| ERP-036 | P2 | 3 | 3 | 4 | 4 | 4 | 2 | 2 | 4 | 3 | 3 | 4 | 4 |
| ERP-037 | P2 | 4 | 3 | 5 | 5 | 3 | 2 | 3 | 4 | 4 | 3 | 3 | 3 |
| ERP-038 | P2 | 5 | 4 | 4 | 4 | 5 | 2 | 3 | 4 | 4 | 3 | 4 | 4 |
| ERP-039 | P1 | 4 | 5 | 3 | 2 | 4 | 3 | 3 | 4 | 3 | 2 | 3 | 2 |
| ERP-040 | P1 | 4 | 4 | 3 | 2 | 5 | 3 | 3 | 5 | 2 | 5 | 5 | 5 |
| ERP-041 | P3 | 3 | 1 | 3 | 3 | 3 | 1 | 1 | 5 | 5 | 2 | 5 | 3 |
| ERP-042 | P0 | 5 | 5 | 5 | 5 | 5 | 5 | 5 | 5 | 4 | 4 | 4 | 2 |

## Interpretacja

- `ERP-002` ma niski koszt, lecz najwyższą niepewność wejściową; jest discovery P0, nie funkcją użytkową.
- `ERP-001` jest natychmiastowe mimo ograniczonego zakresu implementacji, bo zamyka aktywne ryzyko.
- `ERP-016/019/022/023/028` mają wysoką wartość i burden; realizuje się je dopiero po fundamentach, pionowymi wycinkami.
- `ERP-041` ma atrakcyjną narrację, lecz niski RR/UN i najwyższą niepewność; pozostaje P3.

