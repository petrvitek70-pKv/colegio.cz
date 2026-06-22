# Colegio.cz web — Claude instrukce

## Projekt
Landing page hry Mastermind + žebříček API. Vydává firma **Colegio Solutions s.r.o.**
- URL: `https://colegio.cz`
- Hosting: Active24, FTP deploy přes GitHub Actions (push na main = automatický deploy)

## Struktura
```
index.html          — landing page, i18n (data-i18n atributy), žebříček
assets/style.css    — dark theme (--bg: #06060F, --accent: #F0E442)
assets/i18n.js      — TRANSLATIONS objekt, 22 jazyků, detectLang(), applyLang()
privacy.html        — Privacy Policy (EN)
terms.html          — Terms of Use (EN)
api/
  db.php            — PDO SQLite helper, corsHeaders(), jsonResponse()
  score.php         — POST: příjem a validace skóre
  leaderboard.php   — GET: žebříček
```

## API — score.php validace
Každý odeslaný výsledek server **přepočítá** stejným algoritmem jako hra a odmítne odchylku:
- Parametry: `nickname, score, difficulty, guesses, seconds, timed (0/1), secret`
- Max pokusy: easy=12, medium/classic=10, hard=8
- Min čas: guesses × 3 sekund
- Skóre musí přesně odpovídat: `computeScore(guesses, maxGuesses, seconds, isTimed, scoreMultiplier)`
- scoreMultiplier: easy=1, medium=3, classic=4, hard=6

## Klíčová pravidla
- Každou změnu **ihned commitovat a pushovat** — deploy proběhne automaticky
- Při změně algoritmu skóre aktualizovat `score.php` i obě appky současně
- Nickname v žebříčku musí být escapován přes `esc()` před vložením do innerHTML (XSS ochrana)
- Prepared statements všude — SQLi ochrana již implementována

## i18n
- 22 jazyků v `assets/i18n.js` — při přidání textu přeložit do všech jazyků
- `data-i18n="klíč"` atributy v HTML
- RTL automaticky pro arabštinu

## GitHub
`https://github.com/petrvitek70-pKv/colegio.cz` (private)
