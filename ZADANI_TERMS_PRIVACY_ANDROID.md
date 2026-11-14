# Zadání: Terms of Use a Privacy Policy Mastermindu pokrýt i Android / Google Play

**Pro:** vývojář Mastermindu (web colegio.cz, repo `web colegio`, deploy GitHub Actions). **Od:** Petr Vítek, 15. 11. 2026. **Proč:** obě stránky jsou psané jen pro iOS („any Apple device“, „processed by Apple“, „our iOS application“), ale Mastermind je i na Google Play a odkaz na ně je v androidové appce (About → Privacy / Terms) i v deklaraci statusu obchodníka v Play Console (pole Smluvní podmínky = `https://colegio.cz/terms.html`). Google vyžaduje, aby se podmínky vztahovaly na danou appku.

## 1. `terms.html` – změny textu (EN, stránka je jen anglicky)
| Oddíl | Dnes | Nově |
|---|---|---|
| License | …to use Mastermind on any Apple device you own or control, subject to the App Store Terms of Service. | …to use Mastermind on any Apple or Android device you own or control, subject to the App Store Terms of Service or the Google Play Terms of Service, whichever store you installed the app from. |
| In-App Purchases | …All purchases are processed by Apple and subject to Apple's refund policy. | …All purchases are processed by the store you bought from – Apple (App Store) or Google (Google Play) – and are subject to that store's refund policy. We never see your payment details. |
| Last updated | June 2026 | November 2026 |
Nic dalšího v Terms neměnit (Restrictions, Disclaimer, Liability, Governing Law, Contact zůstávají).

## 2. `privacy.html` – změny textu
| Místo | Dnes | Nově |
|---|---|---|
| Úvod | …explains how our iOS application handles information. | …explains how our iOS and Android applications handle information. |
| Data stored on your device | Your in-app purchase status — verified and managed by Apple's StoreKit | Your in-app purchase status — verified and managed by Apple's StoreKit (iOS) or Google Play Billing (Android) |
| In-App Purchases | All purchase transactions are handled entirely by Apple. We do not have access to your payment information, Apple ID, or any personal data related to your purchase. | All purchase transactions are handled entirely by Apple (App Store) or Google (Google Play). We do not have access to your payment information, your Apple ID or Google account, or any personal data related to your purchase. |
| Last updated | June 2026 | November 2026 |

## 3. `privacy.html` – doplnit, co server skutečně ukládá (kontrola proti kódu, ne jen formulace)
Od verze 1.2.3 posílá appka k záznamu žebříčku kromě přezdívky a skóre i **platformu (iOS/Android), verzi appky, jazyk appky a zemi** (sloupce `platform`, `app_version`, `app_lang`, `country` v DB, viz `score.php` a `LeaderboardManager`). Privacy to dnes neuvádí („only the data you explicitly provide (your nickname and game scores)“). Doplnit do oddílu „data sent to our server“ odrážku:
- *Technical details used only for leaderboard statistics: app platform (iOS/Android), app version, app language and the country of your store account. These are not shown publicly.*
Ověřit v kódu, co se reálně posílá (včetně `feedback.php` – zpětná vazba: text zprávy, případně e-mail, verze appky), a popsat přesně; nic nevymýšlet.

## 4. Mimo rozsah
- Lokalizace stránek (zůstávají EN).
- Změna URL (odkazy v obou appkách i v Play Console míří na `https://colegio.cz/terms.html` a `/privacy.html` – neměnit).
- Design stránek.

## 5. Hotovo = 
- Obě stránky nasazené na colegio.cz (GitHub Actions z `main`), ověřené v prohlížeči (curl z Macu blokuje WAF, hláška 466 – ověřovat prohlížečem).
- STATUS.md repozitáře doplněn.
- Petr pak zkontroluje v Play Console (Lachez i Mastermind mají v deklaraci obchodníka odkaz na podmínky) a v App Store Connect (odkaz na privacy v listingu zůstává stejný, jen obsah).
