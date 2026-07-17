# Mastermind Web (colegio.cz) — Stav projektu

> Tento soubor udržuj aktuální po každé větší změně. Slouží jako onboarding pro novou session nebo nového vývojáře.
> Poslední aktualizace: 2026-07-18 (pentest v6 retest — vše OK)

---

## Přehled projektu

Vydavatel: **Colegio Solutions s.r.o.**

| Platforma | Repozitář | Stav |
|-----------|-----------|------|
| iOS | github.com/petrvitek70-pKv/Mastermind | ✅ v1.1 live na App Store |
| Android | github.com/petrvitek70-pKv/MastermindAndroid | ⏳ v1.0 čeká na schválení Google Play |
| Web + API (tento repo) | github.com/petrvitek70-pKv/colegio.cz | ✅ live na colegio.cz |

---

## Web — aktuální stav

- ✅ **Live na colegio.cz** — HTTPS (Let's Encrypt), hosting Active24
- ✅ **GitHub Actions deploy** — každý push na `main` se automaticky deployuje přes FTP
- ✅ **App Store tlačítko** — živý odkaz (iOS 1.1 schválena)
- ⏳ **Google Play tlačítko** — `href="#"`, čeká na schválení Android 1.0
  - Po schválení: změnit na skutečný odkaz v `index.html`
- ✅ **Turnajová sekce** — v `index.html` (sekce "🏅 Tournaments"), API na serveru
  - Žádný turnaj ještě nevytvořen — čeká na vydání appek s turnajovým kódem

---

## Struktura

```
index.html              — landing page (i18n, žebříček, turnaje, download tlačítka)
assets/style.css        — dark theme (#06060F pozadí, #F0E442 akcent)
assets/i18n.js          — překlady pro všechny jazyky, detectLang(), applyLang()
privacy.html            — Privacy Policy (EN)
terms.html              — Terms of Use (EN)
admin/index.html        — admin panel (zpětná vazba + správa turnajů), přihlášení klíčem
api/
  db.php                — PDO SQLite helper, corsHeaders(), jsonResponse()
  score.php             — příjem a validace skóre (přepočítává server-side)
  leaderboard.php       — žebříček
  tournament.php        — turnaje (list, create, join, seed, submit, leaderboard, delete, disqualify)
  feedback.php          — zpětná vazba
```

---

## API

- **Base URL:** `https://colegio.cz/api`
- **API secret:** `mm_colegio_2026_xK9pQ` (v appkách i PHP)
- **Admin secret:** uložen pouze v `config.local.php` na serveru (mimo repozitář)
- **DB:** SQLite (`data/scores.db`) — tabulky `scores`, `tournaments`, `tournament_entries`, `feedback`

### Validace skóre (`score.php`)
Server **přepočítá** skóre stejným algoritmem jako appka a odmítne odchylku:
- Max pokusy: easy=12, medium/classic=10, hard=8
- Min čas: guesses × 3 sekund
- scoreMultiplier: easy=1, medium=3, classic=4, hard=6 (×2 pokud allowRepetition)

### Limity max skóre (1 pokus, timed, s opakováním)
easy×2=20k | medium×6=60k | classic×8=80k | hard×12=120k

---

## Admin panel (`admin/index.html`)

Přístupný na `colegio.cz/admin/` — přihlášení admin klíčem (posíláno jako `X-Admin-Key` header).

Záložky:
- **💬 Zpětná vazba** — seznam zpráv od hráčů
- **🏅 Turnaje** — seznam turnajů, žebříček, diskvalifikace hráče, smazání turnaje

Admin klíč je **pouze na serveru** v `config.local.php`, není v repozitáři.

---

## Deploy

Push na `main` → GitHub Actions → FTP na Active24 → live za ~30 sekund.

Secrets v GitHub repozitáři: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_SERVER_DIR`

Vyloučeno z deploye: `.git*`, `data/`, `.DS_Store`

---

## Lokalizace (i18n.js)

- **Vždy lokalizovat do všech jazyků** při přidání nového textu
- Jazyky v `assets/i18n.js` — objekt `TRANSLATIONS`
- RTL automaticky pro arabštinu
- Přidání textu: `data-i18n="klíč"` do HTML + překlad do všech jazyků v i18n.js
- Překlady psát přes Python skript, ne bash (bash selhává na speciálních znacích)

---

## Bezpečnost

- ✅ Prepared statements (SQLi ochrana)
- ✅ XSS — nickname escapován přes `esc()` před vložením do innerHTML
- ✅ Admin klíč jako `X-Admin-Key` header (ne v URL)
- ✅ CORS omezen na colegio.cz
- ✅ Server-side validace skóre
- ✅ `seed` turnaje: POST + API secret, `seed_issued_at` timestamp (min. doba hry)
- ✅ Admin secret mimo repozitář (`config.local.php`)

---

## Synchronizace platforem

**Každá změna jde vždy do všech tří míst:** web + iOS + Android

- Algoritmus skóre v `score.php` musí být identický s `GameLogic.swift` a `GameLogic.kt`
- Nový string na webu → přeložit do všech jazyků v i18n.js
- Při vydání nové verze appky → aktualizovat download tlačítka v `index.html`
