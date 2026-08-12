# Mastermind Web (colegio.cz) — Stav projektu

> Tento soubor udržuj aktuální po každé větší změně. Slouží jako onboarding pro novou session nebo nového vývojáře.
> Poslední aktualizace: 2026-08-12 (turnaje — opraveny globální reakce, hecování, zarovnání, sdílecí karta, Play button; $ALLOWED_REACTIONS fix)

---

## Přehled projektu

Vydavatel: **Colegio Solutions s.r.o.**

| Platforma | Repozitář | Stav |
|-----------|-----------|------|
| iOS | github.com/petrvitek70-pKv/Mastermind | ✅ v1.2.2 Ready for Distribution od 2026-08-11 |
| Android | github.com/petrvitek70-pKv/MastermindAndroid | 🔄 v1.2 release build hotov, čeká na upload |
| Web + API (tento repo) | github.com/petrvitek70-pKv/colegio.cz | ✅ live na colegio.cz |

---

## Web — aktuální stav

- ✅ **Live na colegio.cz** — HTTPS (Let's Encrypt), hosting Active24
- ✅ **GitHub Actions deploy** — každý push na `main` se automaticky deployuje přes FTP
- ✅ **App Store tlačítko** — živý odkaz (iOS schválena)
- ✅ **Google Play tlačítko** — živý odkaz (Android schválen)
- ✅ **Turnajová sekce** — aktivní; turnaje v databázi (přejmenováno 2026-08-04)
- ✅ **Globální reakce na webu** — zobrazují se pod žebříčkem turnaje (fire/nice/gg/love/wow s počty, 2026-08-12)
- ✅ **Hecování hráčů na webu** — zobrazuje se u každého hráče v turnajovém žebříčku
- ✅ **Tournament Bot** — GitHub Actions, každých 6 h doplňuje turnaje na minimum 3; secret `TOURNAMENT_BOT_KEY` v GitHub secrets; 4× easy, 4× medium, 3× classic, 1× hard
- ✅ **Knuth fun fact** — přesunuta za Pro sekci, lokalizováno do 26 jazyků
- ✅ **Canonical tagy** — přidány do index.html, privacy.html, terms.html (oprava Google Search Console)

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
  tournament_bot.php    — bot pro automatické doplňování turnajů (volán přes GitHub Actions)
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
- Min čas: guesses × 5 sekund (od v1.2.3 — dříve 3s)
- scoreMultiplier: easy=1, medium=3, classic=4, hard=6 (×2 pokud allowRepetition)
- Nové (v1.2.3): přijímá `ms` (nové appky) nebo `seconds` (staré appky): `if isset($body['ms']) && ms>0 → use ms, else seconds*1000`
- timePenalty = `floor(ms * 0.005)` (ekvivalentní `seconds * 5`)

### Žebříček (leaderboard.php) — nové funkce v1.2.3
- **ms timing**: záznamy mají `ms` (milisekundy), zobrazení jako `M:SS.cc` (centisekundy)
- **my_entry**: pokud hráč není v top 100, vrátí jeho nejlepší výsledek s reálným pořadím (`?nickname=X`)
- **timed indikátor**: pole `timed: bool` v odpovědi
- **Analytické sloupce**: `platform`, `app_version`, `app_lang`, `country` — plní appky od v1.2.3

### Migrace DB (db.php)
- `ALTER TABLE scores ADD COLUMN ms INTEGER NOT NULL DEFAULT 0`
- `UPDATE scores SET ms = seconds * 1000 WHERE ms = 0` — staré záznamy zobrazí `M:SS.00`

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

Secrets v GitHub repozitáři: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_SERVER_DIR`, `TOURNAMENT_BOT_KEY`

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

Poslední pentest: **v9 2026-08-12** — opraveny 2 nálezy.

- ✅ Prepared statements (SQLi ochrana)
- ✅ XSS — nickname escapován přes `esc()` před vložením do innerHTML
- ✅ Admin klíč jako `X-Admin-Key` header (ne v URL) — `adminFetch()` helper v admin panelu
- ✅ `isAdminRequest()` v `tournament.php` — kontroluje header, nikdy GET parametr
- ✅ CORS omezen na colegio.cz (pro `feedback-list.php`; ostatní API mají wildcard — akceptováno, bez cookie auth)
- ✅ Server-side validace skóre (`score.php` i `tournament.php?action=submit`)
- ✅ `seed` turnaje: POST + API secret, `seed_issued_at` timestamp (min. doba hry)
- ✅ Admin secret mimo repozitář (`config.local.php`)
- ✅ `action=update` — editace turnajů chráněna pouze admin klíčem
- ✅ Creator delete — opravena regrese `$body` → `$reqBody` (2026-07-28)
- ✅ `action=disqualify` — opravena regrese `$body` → `$reqBody` (2026-08-12)
- ✅ `react` + `player_react` — přidána délková validace nickname (max 20 znaků, 2026-08-12)
- ✅ `$ALLOWED_REACTIONS` — opraveny klíče na `['fire','nice','gg','love','wow']` (bylo špatné staré hodnoty, 2026-08-12)

**Akceptovaná rizika (nízká):**
- Hardcoded API secret `mm_colegio_2026_xK9pQ` v mobilních appkách — dopad snížen server-side validací
- isPro v plaintext storage — dopad jen na rootnutá zařízení
- Chybí certificate pinning

---

## Synchronizace platforem

**Každá změna jde vždy do všech tří míst:** web + iOS + Android

- Algoritmus skóre v `score.php` musí být identický s `GameLogic.swift` a `GameLogic.kt`
- Nový string na webu → přeložit do všech jazyků v i18n.js
- Při vydání nové verze appky → aktualizovat download tlačítka v `index.html`
