# POS loyalty card — brief for Szymon

**Task.** Add a **"Carte fidélité · Klantenkaart"** button to the L'Atelier POS. The cashier uses it to give our
loyalty QR card to a customer who doesn't have one yet. The POS asks one question:

> **"C'est la première fois que vous venez ?"** · *"Is het de eerste keer dat u bij ons komt?"*

- **Yes** → the cashier gives the **QR card**.
- **No, already came before** → the cashier gives the **QR card and a voucher**: a code that the POS prints on a slip,
  or a pre-printed paper voucher, depending on the shop.

The cockpit records every card, decides the voucher, and composes the messages (FR / NL) and the slip lines. **The
POS shows and prints them as they are**: when Sam changes the voucher, nothing changes on your side.

## What already exists

| What | Where |
|---|---|
| Cockpit API (base URL) | `https://185.180.206.46/consulant_bo/api/cockpit` |
| The POS key | **the same key as the clock-in** ("Clé pour la caisse", one per shop, already in your POS configuration) |

Nothing new to configure. The shop is always the shop of the key. Same rules for the key as before: a secret, never in a
URL that staff can see, never logged.

## 1. The button

1. Read the state with **`GET /fidelite/caisse/etat?b={KEY}`** when the POS starts, then every 10 minutes (and when
   the main screen opens after a long pause):

   ```json
   200 {"shop":4,"magasin":"Halle","lance":true,"lanceLe":"2026-11-14",
        "bouton":{"fr":"Carte fidélité","nl":"Klantenkaart"},
        "question":{"fr":"C’est la première fois que vous venez ?","nl":"Is het de eerste keer dat u bij ons komt?"},
        "oui":{"fr":"Oui — la carte QR","nl":"Ja — de QR-kaart"},
        "non":{"fr":"Non, déjà venu — la carte QR + bon de 2 €","nl":"Nee, al eerder geweest — de QR-kaart + bon van 2 €"},
        "bon":{"mode":"code","libelle":"Bon de 2 €","valeur":2,"jours":30},
        "aujourdhui":{"cartes":12,"bons":4}}
   ```

2. Show the button on the POS main screen **only when `lance` is `true`**. Sam switches shops on one by one from the
   cockpit; before that day the button stays hidden.
3. If the current ticket **already has a loyalty customer** (the customer's app was scanned), the customer has a card:
   grey the button out.
4. The button works with or without a sale in progress, and **never changes the sale**.

## 2. The question

Tapping the button opens a panel:

- the question (`question.fr` large, `question.nl` under it);
- two big buttons, labelled with `oui` and `non` (FR, with NL under it). The `non` label already says which voucher
  the customer gets;
- a **Cancel** button (nothing is sent).

When the panel opens, generate a **`ref`** (a UUID, 6 to 64 characters `A-Z a-z 0-9 _ . : -`). It identifies this one
customer. Send it with the answer, and **send the same `ref` again if you retry** after a network error: the cockpit
then returns the same answer instead of counting a second card.

## 3. Send the answer

**`POST /fidelite/caisse/carte`**, header `Content-Type: application/json`:

```json
{"b": "{KEY}", "premiere": true, "ref": "6f1c0e8a-3d2b-4b8e-9a51-2f0c9d7e4a10", "emp": 12}
```

| Field | |
|---|---|
| `premiere` | `true` = "Oui" (first visit) · `false` = "Non" (already came). Required. |
| `ref` | the panel's UUID (see above). Strongly recommended. |
| `emp` | the cashier logged in on the POS (`franchisee_employee.id`), if any. Lets Sam see who offers the card. |
| `carte` | optional: if the POS scanner can read the card's QR, its content (64 characters max). |
| `essai` | `true` for a test (see §5). |

Then show `ecran.fr` (large) and `ecran.nl` (under it) in a big card, and **if `ticket` is not `null`, print it**: one
line per entry, the `Code : …` line large or bold. If the POS reads voucher codes with its scanner, also print
`bon.code` as a QR code or barcode. The card stays until "OK" or 10 seconds.

| Response | What it means |
|---|---|
| `200 {"id":815,"premiere":true,"remise":"carte","bon":null,"ecran":{"fr":"Donnez la carte QR au client.","nl":"Geef de klant de QR-kaart."},"ticket":null}` | Yes: the card. |
| `200 {"id":816,"premiere":false,"remise":"carte_bon","bon":{"mode":"code","code":"FIDK7M2QXPA","libelle":"Bon de 2 €","valeur":2,"des":"2026-11-15","jusqu_au":"2026-12-14"},"ecran":{"fr":"Donnez la carte QR et le bon imprimé (Bon de 2 €).","nl":"…"},"ticket":["L’Atelier by — Bon fidélité · Klantenbon","Bon de 2 € sur votre prochain achat","Bon van 2 € op uw volgende aankoop","Code : FIDK7M2QXPA","Valable du 15/11/2026 au 14/12/2026","Geldig van 15/11/2026 tot 14/12/2026","Un bon par ticket · Eén bon per kasticket"]}` | No, shop with **printed codes**: print the slip, the cashier gives the card and the slip. |
| `200 {"id":817,"premiere":false,"remise":"carte_bon","bon":{"mode":"papier","code":null,…},"ecran":{"fr":"Donnez la carte QR et un bon papier : Bon de 2 €, valable jusqu’au 14/12/2026.",…},"ticket":null}` | No, shop with **paper vouchers**: nothing to print, the cashier gives a paper voucher. |
| `200 {…,"remise":"carte","bon":null,"motif":"plafond du jour",…}` | No, but no voucher today (no voucher rule, or the daily limit is reached): the card only. Just show `ecran`. |
| `200 {…,"repris":true}` | Same `ref` sent again: the answer of the first time. Show it, **don't print the slip twice**. |
| `409 {"lance":false}` | The shop isn't launched: hide the button, show "La carte n’est pas encore lancée dans ce magasin" / "De kaart is nog niet gestart in deze winkel". |
| `403` / `404` | "Clé de caisse invalide — voir le cockpit" / "Ongeldige kassasleutel — zie de cockpit". |
| `400` | A bug in the request (`premiere` missing or not a boolean). |
| network error / `5xx` | "Pas de connexion — réessaie" / "Geen verbinding — probeer opnieuw". Retry **with the same `ref`**. |

**The voucher at the next visit:** the printed code is a normal TFBuddy voucher code (a code of the shop's voucher
campaign, one use). The customer brings it back; the cashier enters or scans it in the usual voucher flow
(`/vouchers/validate`, `/vouchers/redeem`). Nothing new to code for that. It is **valid from the next day** until
`jusqu_au`.

## 4. Undo a mistake

The result card has a small **"Annuler · Ongedaan maken"** link for 15 minutes (wrong button, customer changed their
mind). It calls **`POST /fidelite/caisse/carte/annuler`** with `{"b": "{KEY}", "id": 816}`:

- `200 {"ok":true}` → "Annulé · Geannuleerd". If a slip was printed, the cashier takes it back: its code is disabled.
- `422` (more than 15 minutes) → "Trop tard : voir le cockpit · Te laat: zie de cockpit".
- `404` → the card isn't from this shop.

## 5. Test mode

Before a shop is launched, you can test end to end with `"essai": true` in the body: it always passes, the code starts
with `ESSAI` and is **never valid**, the slip starts with `*** ESSAI — NON VALABLE ***`, and `ecran` starts with
"ESSAI —" / "TEST —". Tests are shown in the cockpit but never counted. Please don't send `essai` from the cashiers'
normal screen.

## Rules the cockpit applies (nothing to code, it is for your messages)

- **The voucher:** the rule "Carte donnée en caisse" of the cockpit's loyalty programme — the shop's own rule first,
  else the network rule. Default: a 2 € voucher, valid 30 days from the next day.
- **Printed code or paper:** chosen per shop in the cockpit (a TFBuddy voucher campaign with unique codes, or paper).
- **The code** is created in TFBuddy right after the answer (`POST /admin/voucher-campaigns/{id}/codes`, one use) and
  retried every hour if TFBuddy refuses: it only has to exist the next day. You don't wait for TFBuddy.
- **Limits:** 100 vouchers per shop per day, then the card only. Same `ref` → same answer. Without a `ref`, the same
  answer from the same POS within 4 seconds counts once (double tap).

## Technical notes

- **CORS:** the three routes accept requests from any origin (`Access-Control-Allow-Origin: *`), the `OPTIONS`
  preflight returns `204`. The key travels in the query or the body, never in a cookie.
- **No caching:** the server sends `Cache-Control: no-store`.
- **HTTPS by IP address:** same server and certificate as the clock-in.

## Acceptance checks

1. The button appears only when `lance` is `true`, and is greyed out when an app customer is on the ticket.
2. "Oui": the card message shows in under 1 s; the card appears in the cockpit (Fidélité › En caisse) for the right
   shop and the right cashier.
3. "Non" in a paper shop: the paper-voucher message, nothing printed.
4. "Non" in a printed-code shop: the slip is printed with the code and the dates. The next day the code is accepted
   once by the normal voucher flow; a second use is refused.
5. Network cut while sending, then retry: one card only in the cockpit, the slip printed once.
6. "Annuler" within 15 minutes: the card leaves the cockpit counts; the printed code is refused at the POS.
7. A test (`essai`) shows "ESSAI" everywhere and is not counted.

Questions: Sam.
