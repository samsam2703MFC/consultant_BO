# POS clock-in / clock-out — brief for Szymon

**Task.** Add a "Clock in / out" button to the L'Atelier POS. It opens a full-screen panel with two modes:

- **QR (default):** show the shop's live QR code. Staff scan it with the Atelier employee app.
- **PIN:** for someone who doesn't have her phone. She identifies herself with her PIN on the POS, the POS checks
  the PIN itself, then tells our cockpit "this person clocks in / out".

The cockpit decides whether it is an arrival or a departure, and compares it with the person's schedule. The clock-in
system itself is already live; only the POS side is missing.

## What already exists

| What | Where |
|---|---|
| Cockpit API (base URL) | `https://185.180.206.46/consulant_bo/api/cockpit` |
| Employee app (staff clock in here: Home › "Pointer") | `https://185.180.206.46/consulant_bo/employee` |
| Ready-made QR page (shows the QR, the 6-digit code and a countdown, FR/NL) | `https://185.180.206.46/consulant_bo/borne/?b={KEY}` |

**The key.** Each POS needs a **POS key**. Sam creates it in the cockpit: page **Pointages**, choose the shop, then
**"Clé pour la caisse"**. It is shown once.

- Store it in the POS configuration as a secret, one key per shop.
- Never put it in a URL that staff can see, and never log it.
- If it leaks, remove it in the cockpit ("Retirer") and create a new one.

## 1. The button and the QR mode

1. Show a **"Pointer · Inklokken"** button on the POS main screen. It must work even when no cashier is logged in.
2. Tapping it opens a full-screen panel in **QR mode**:
   - the shop name and the current time;
   - a large QR code (at least about 8 cm on screen);
   - the 6-digit code under the QR, written as `482 913`, for staff whose camera doesn't work (they type it in the app);
   - a countdown bar: "nouveau code dans 24 s · nieuwe code over 24 s";
   - a button **"Pas de téléphone ? PIN · Geen telefoon? PIN"** that switches to PIN mode;
   - a **Close** button. The panel also closes by itself after 60 s without a touch.
3. Get the code with **`GET /pointage/borne?b={KEY}`**:

   ```json
   200 {"shop":4,"magasin":"Halle","nom":"Caisse","qr":"ATP1:4:59224711:0123456789abcdef01234567",
        "code":"482913","reste":24,"periode":30,"heure":"07:58"}
   ```

   - **Encode a link in the QR, not the raw `qr` value:**
     `https://185.180.206.46/consulant_bo/employee/pointage?c=` + `encodeURIComponent(qr)`, error correction level M.
     Because it is a link, a phone's normal camera also opens the app and clocks in.
   - Fetch again after `reste` seconds (plus about 300 ms). The code changes every 30 s and stays valid 90 s. It only
     works for this shop.
   - `404` means the key is unknown or was removed: show "Clé de pointage invalide — voir le cockpit".
   - **Shortcut if it's faster for you:** show the ready-made page `…/borne/?b={KEY}` in a WebView or iframe. It does
     everything in this section. Native rendering is nicer, but either is fine.

## 2. PIN mode (no phone)

1. The person identifies herself **exactly as she already does to sell**: her name and PIN, or her PIN only, whatever
   the POS does today. **The POS checks the PIN itself.** The PIN is never sent to us.
2. Wrong PIN: refuse locally and don't call us. Lock the PIN pad for 5 minutes after 5 wrong PINs in a row.
3. Right PIN: call **`POST /pointage/borne/pointer`** with header `Content-Type: application/json`:

   ```json
   {"b": "{KEY}", "emp": 12}
   ```

   `emp` is the person's id in the panel (`franchisee_employee.id`), the same id as in her app login.

4. Show the result in a large card for 4 seconds, then go back to the QR mode:

   | Response | Show (FR / NL) |
   |---|---|
   | `200 {"sens":"arrivee", "pointage":{"entree":"07:58","arrivee":"a_l_heure", …}, "nom":"Bram Claes"}` | "Bram — arrivée pointée 07:58 · à l'heure" / "ingeklokt 07:58 · op tijd" |
   | `"arrivee":"retard","retard_min":12` | "… · 12 min de retard" / "… · 12 min te laat" |
   | `"arrivee":"hors_planning"` | "… · hors planning" / "… · buiten het rooster" |
   | `200 {"sens":"depart", "pointage":{"sortie":"16:04","duree_min":486}}` | "départ pointé 16:04 · 8 h 06" / "uitgeklokt 16:04 · 8 u 06" |
   | `200 {"sens":"deja"}` (two clockings within 2 minutes) | "Déjà pointé" / "Al geklokt" |
   | `422 {"motif":"trop_tot","a_partir_de":"07:30"}` | "Trop tôt : à partir de 07:30" / "Te vroeg: vanaf 07:30" |
   | `403` (the key is a tablet link, not a POS key) / `404` (unknown key) | "Clé de pointage invalide — voir le cockpit" |
   | network error / `5xx` | "Pas de connexion — réessaie" / "Geen verbinding — probeer opnieuw" |

5. A PIN clock-in **must not** open or switch the sales session: clocking in and selling stay separate.

## Rules the cockpit applies (nothing to code, it is for your messages)

- **Arrival or departure:** if the person has an open clock-in from less than 16 h ago, it is a departure; otherwise
  it is an arrival.
- **Arrival times:** she can clock in from 30 minutes before her shift. More than 5 minutes after the start, the
  arrival is marked late. With no shift that day, it is accepted and marked "hors planning".
- **Double clocking:** two clockings within 2 minutes count as one (`deja`).
- **Where it shows:** every clocking appears in the cockpit (page Pointages) and in the person's app (Profil › Mes
  pointages). A PIN clocking is recorded with the method `pin`.

## Technical notes

- **CORS:** both routes accept requests from any origin (`Access-Control-Allow-Origin: *`), and the `OPTIONS`
  preflight returns `204`. The key travels in the query or the body, never in a cookie.
- **HTTPS by IP address:** the server is reached by IP address over HTTPS. Check that the POS accepts its
  certificate, and tell Sam if it doesn't.
- **No caching:** don't cache `GET /pointage/borne`. The server already sends `Cache-Control: no-store`.

## Acceptance checks

1. The button is on the POS main screen. The QR appears in under 1 s and refreshes every 30 s without flickering.
2. Scanning the POS QR with the Atelier app ("Pointer") clocks in. Scanning it with the phone's normal camera opens the
   app and clocks in.
3. Right PIN gives an arrival. Right PIN again within 2 min gives "Déjà pointé". Right PIN after the shift gives a
   departure with its duration.
4. A wrong PIN is refused by the POS, with no request to the cockpit. 5 wrong PINs lock the pad for 5 min.
5. With no network, the message is clear and the panel stays usable when the network comes back.
6. The clockings appear in the cockpit, page Pointages, for the right shop.

Questions: Sam.
