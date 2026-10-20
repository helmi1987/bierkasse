# Bierkasse

Digitale Bierkasse fürs Büro: Bier abstreichen, Einzahlungen melden (Bar/TWINT), Kassenbuch und Statistik.
Laravel 13, PHP 8.4, kein JS-Build (CSS/JS liegen als einfache Dateien in `public/`). Mobile first,
Light/Dark-Mode, als App installierbar (PWA). Die Domain ist frei wählbar; nichts ist fest auf eine
Domain eingestellt (Beispiel unten: `bier.helminet.ch`).

## Voraussetzungen

- PHP 8.4 (FPM) mit `pdo_mysql`, `mbstring`, `openssl`, `intl`
- Datenbank: MariaDB/MySQL (Plesk), PostgreSQL oder SQLite
- composer

## Installation auf Plesk

1. Domain bzw. Subdomain (z. B. `bier.helminet.ch`) in Plesk anlegen. Unter "PHP-Einstellungen" PHP 8.4 mit FPM wählen.
   In Plesk eine MariaDB-Datenbank mit Benutzer anlegen.
2. Unter "Git" ein Repository hinzufügen: `<owner>/<repo>`, Branch `main`.
   Automatisches Deployment aktivieren und die angezeigte Webhook-URL in GitHub unter
   Settings > Webhooks eintragen (Content type und Events gemäss Plesk-Anzeige, normalerweise "push").
3. Dokumentenstamm auf `<domain>/public` setzen (Plesk: "Hosting-Einstellungen" > Dokumentenstamm).
4. Deploy-Aktionen (Git > Repository-Einstellungen > "Zusätzliche Deploy-Aktionen"):
   ```
   php -d open_basedir= /opt/psa/var/modules/composer/composer.phar install --no-dev --optimize-autoloader --no-interaction --no-scripts
   php -d open_basedir= artisan package:discover --ansi
   php -d open_basedir= artisan optimize:clear
   ```
   `php artisan migrate --force` kommt erst nach dem Setup dazu (Schritt 6).
   `composer` ist auf Plesk nicht im PATH; `--no-scripts` ist nötig, weil Composer `artisan` sonst ohne
   `-d open_basedir=` startet. `storage:link` wird nicht gebraucht.
5. Geplante Aufgabe (optional): Mails werden synchron beim Request verschickt (keine Queue), daher braucht
   die App keinen Cron. Wer trotzdem den Laravel-Scheduler laufen lassen will (z. B. für spätere Erweiterungen):
   Plesk "Geplante Aufgaben", jede Minute `php -d open_basedir= artisan schedule:run` im Projektordner.
6. Setup:
   - `bootstrap/ensure-env.php` wird nur von `public/index.php` geladen, also erst beim ersten
     Web-Aufruf. Dabei entstehen `.env` (aus `.env.example`, mit generiertem `APP_KEY`) und das
     einmalige Setup-Token in `storage/app/setup-token.txt` (nur solange `APP_INSTALLED` nicht `true` ist).
     `php artisan` erzeugt weder `.env` noch Token.
   - Vor dem Setup `migrate --force` nicht als Deploy-Aktion eintragen: es gibt noch keine
     Datenbankzugangsdaten (`DB_DATABASE` ist leer), der Befehl schlägt fehl. Solange `APP_INSTALLED=false`
     leitet die Seite alle Anfragen auf `/setup` um.
   - **Vorher HTTPS einrichten** (Plesk: "SSL/TLS-Zertifikate" > Let's Encrypt, inkl. Weiterleitung
     http → https). Die `.env` setzt `SESSION_SECURE_COOKIE=true`: Das Session-Cookie wird nur über HTTPS
     gesendet, über http schlägt jedes Formular (auch der Wizard) mit **419 Page Expired** fehl.
   - `https://bier.helminet.ch/` (bzw. die eigene Domain) aufrufen (Weiterleitung auf `/setup`). Das Token
     steht im Dateimanager bzw. per SSH in `<Projekt>/storage/app/setup-token.txt` (neben `public/`,
     nicht im Dokumentenstamm).
   - Im Wizard Token, Datenbank (MySQL/MariaDB, Host `localhost`) und ersten Admin eintragen. Die Webseiten-URL
     ist mit der aktuellen Adresse vorausgefüllt und wird als `APP_URL` gespeichert. Danach erzeugt die App
     alle Links (z. B. im Passwort-Reset-Mail) aus `APP_URL` und akzeptiert nur noch diesen Host
     (Schutz gegen Host-Header-Injection). Domain später wechseln: `APP_URL` in `.env` anpassen. Der Wizard schreibt die
     DB-Daten in `.env`, führt die Migrationen aus, legt den Admin an, setzt `APP_INSTALLED=true` und löscht
     das Token. `/setup` liefert danach 404.
   - Danach in `.env` `DB_HOST=127.0.0.1` setzen (nicht `localhost`): PHP auf der Kommandozeile erreicht den
     localhost-Socket nicht, sonst scheitert `migrate` in den Deploy-Aktionen mit `SQLSTATE[HY000] [2002]`.
   - Dann `php -d open_basedir= artisan migrate --force` als letzte Deploy-Aktion ergänzen (Updates mit neuen
     Migrationen).
7. Nach dem Setup im Backend (`/backend/einstellungen`):
   - Bierpreis (Standard CHF 1.50), Standard-Limit (Standard 0 = kein Minus), TWINT-Nummer und Name.
   - Registrierung: Registrierungspasswort setzen (ohne Passwort ist keine Registrierung möglich).
     QR-Code zum Aushängen unter `/backend/registrierung-qr` (füllt das Registrierungspasswort automatisch aus; Variante ohne Passwort wählbar).
   - Mailversand: Server-sendmail (Standard, `MAIL_SENDMAIL_PATH`) oder SMTP (Passwort verschlüsselt gespeichert).
     Kassier-Benachrichtigung (neue Einzahlung, Auszahlungsantrag) an alle Kassiers/Admins mit E-Mail.
   - SSO (`/backend/sso`): Google, Microsoft (Tenant, Standard `common`), GitHub oder generisches OIDC
     (Discovery-URL). Redirect-URI beim Anbieter: `https://<domain>/login/sso/{kuerzel}/callback`.
     Anmelden per SSO kann nur, wer den Anbieter vorher unter Konto > Einstellungen verknüpft hat.
8. Steht ein Reverse-Proxy vor PHP, dessen IP in `.env` als `TRUSTED_PROXIES` (kommagetrennt, Standard: keine)
   eintragen, sonst werden `X-Forwarded-*`-Header ignoriert.

Break-glass via SSH: `php artisan user:password <username>` (optional `--generate`),
`php artisan user:totp-reset <username>`, `php artisan user:admin <username>`.

## Branch-Modell

- Gearbeitet wird auf `claude/**`-Branches. Die CI (`.github/workflows/tests.yml`) führt dort die Tests aus.
- Nach grüner CI merged `.github/workflows/auto-merge.yml` den Branch automatisch in `main`; `main` wird von Plesk deployt.
  Repository-Einstellung nötig: Settings > Actions > General > Workflow permissions "Read and write" und
  "Allow GitHub Actions to create and approve pull requests".
- Manuell: Actions > "Promote to main" > Run workflow (unter "Use workflow from" den `claude/...`-Branch wählen). Legt `main` an, falls es fehlt, sonst Merge per PR.
  Danach einmalig Settings > General > Default branch = `main`.

## Funktionen

- Rollen: `admin`, `kassier`, `user`. Admin hat alle Kassier-Rechte; mehrere Kassiers möglich.
  Admin/Kassier sehen im Kopf den Link "Backend" mit der Zahl offener Posten (Einzahlungen + Auszahlungen).
- Anmeldung `/`: Benutzername + Passwort, optional TOTP (im Konto einrichten, Wiederherstellungscodes).
  Auch nach einer SSO-Anmeldung wird TOTP verlangt, wenn der Benutzer es eingerichtet hat.
  Passwort vergessen per Mail (nur mit hinterlegter E-Mail; die E-Mail ist pro Konto eindeutig).
  Kassier/Admin können Passwörter setzen ("muss bei nächster Anmeldung geändert werden") und TOTP zurücksetzen.
  Kassiers können Admin-Konten nicht ändern (Passwort, TOTP, Status).
  Deaktivieren geht nur mit Saldo 0 und ohne offene Posten (sonst "Haus verlassen" oder Korrektur);
  der letzte aktive Admin kann nicht deaktiviert werden. Auf ausgetretene Konten wird nichts mehr gebucht.
  **Annahme:** Kassier/Admin dürfen für sich selbst buchen (Limit, Einzahlung, Korrektur). Jede Buchung
  speichert, wer sie erfasst hat (`created_by`), damit bleibt das im Verlauf nachvollziehbar.
- Geld immer in Rappen (Integer), Anzeige `CHF 1.50`. Saldo = Summe der bestätigten Buchungen (`transactions`).
  - Doppeltes Abschicken (Doppeltipp) wird verhindert: Einmal-Token pro Formular (Bier, Einzahlung melden)
    und Sperre des Knopfs nach dem Absenden.
  - Bier: Preis im Moment des Abstreichens wird pro Buchung gespeichert; Preisänderungen ändern alte Buchungen nie.
    Limit: neuer Saldo darf nicht unter `-Limit` fallen (individuell oder Standard). Eigene Striche 10 Minuten
    rückgängig machbar.
  - "Offen": bei negativem Saldo der offene Betrag; die offenen Biere werden mit ihrem ursprünglichen Preis
    angezeigt (Einzahlungen decken die ältesten Biere zuerst). Die Positionen ergeben immer das Total:
    volle offene Biere, Rest eines teilweise bezahlten Biers, übrige Beträge (negative Korrekturen).
  - Einzahlung: Benutzer meldet (offen), Kassier bestätigt/lehnt ab; Kassier kann direkt buchen.
  - Haus verlassen (nur mit Saldo ≥ 0): Auszahlung beantragen (Kassier bestätigt die Auszahlung, Betrag = Saldo)
    oder Guthaben spenden. Danach ist das Konto `left` (keine Anmeldung, Verlauf bleibt).
  - Korrektur durch Kassier/Admin mit Pflicht-Bemerkung.
- Kasse (`/backend/kasse`): Kassenstand = bestätigte Einzahlungen − Auszahlungen − Ausgaben + Einnahmen ±
  Korrekturen, getrennt nach Bar und TWINT. Überschuss = Kassenstand − Guthaben aller Benutzer.
- Statistik `/statistik`: Biere total, pro Monat, pro Wochentag, Rangliste (Diagramme als SVG vom Server).
  **Annahme:** Wer "In Statistik anzeigen" ausschaltet, wird für andere nirgends namentlich gezeigt
  (auch nicht in der Rangliste im Backend); die Biere zählen nur in den anonymen Totals.
  Kassiers sehen in der Benutzerverwaltung trotzdem Saldo und Buchungen aller Konten.
- TWINT: Nummer mit Kopier-Knopf und Betragsvorschlag; es gibt keinen öffentlichen TWINT-Deep-Link für
  Zahlungen an Privatpersonen.
- Sicherheit: CSRF, CSP mit Nonce (keine Inline-Styles/-Handler), Rollen-Middleware, verschlüsselte
  Geheimnisse (Registrierungspasswort, SMTP-Passwort, SSO-Client-Secrets, TOTP), Rate-Limits auf Login,
  TOTP, Registrierung und Passwort-Reset.
- App-Icons: `public/logo.svg`, `favicon.svg`, `favicon.ico`, `icon-192/512.png`, `icon-maskable-192/512.png`,
  `apple-touch-icon.png` (statisch, neu erzeugbar mit `php resources/icons/generate.php public`).
  Manifest unter `/manifest.webmanifest`, Service Worker `public/sw.js` (nur Netzwerk, kein Cache).

## Tests

```
php artisan test
```

Die Tests laufen mit SQLite im Speicher (`phpunit.xml`).
