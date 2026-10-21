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
     Kassier-Benachrichtigung (neue Einzahlung, Auszahlungsantrag, neue Selbst-Registrierung mit Link zum Benutzer) an alle Kassiers/Admins mit E-Mail.
   - SSO (`/backend/sso`): Google, Microsoft (Tenant, Standard `common`), GitHub, Facebook oder generisches OIDC
     (Discovery-URL). Redirect-URI beim Anbieter: `https://<domain>/login/sso/{kuerzel}/callback`.
     Anmelden per SSO kann nur, wer den Anbieter vorher unter Konto > Einstellungen verknüpft hat.
     Einrichtung pro Anbieter: siehe "SSO-Anbieter einrichten".
   - Anmeldung: "Eigene Anbieter für User erlauben" (Standard an), siehe "Eigener Anmelde-Anbieter".
8. Steht ein Reverse-Proxy vor PHP, dessen IP in `.env` als `TRUSTED_PROXIES` (kommagetrennt, Standard: keine)
   eintragen, sonst werden `X-Forwarded-*`-Header ignoriert. Wichtig: Die Rate-Limits (Login, TOTP, Gäste,
   eigener Anbieter) zählen pro Client-IP; ohne korrekt gesetzte Proxy-IP teilen sich alle Benutzer die IP des
   Proxys. Nie `*` eintragen, sonst kann jeder Client seine IP per `X-Forwarded-For` fälschen.

Break-glass via SSH: `php artisan user:password <username>` (optional `--generate`),
`php artisan user:totp-reset <username>`, `php artisan user:admin <username>`.

## SSO-Anbieter einrichten

Im Backend unter SSO "Neu: <Anbieter>" wählen; das Formular zeigt die Redirect-URI
`https://<domain>/login/sso/{kuerzel}/callback` (Kürzel = Standard der Name, z. B. `google`). Diese URI exakt beim
Anbieter eintragen, Client-ID und Client-Secret ins Formular kopieren, "Aktiv" anhaken. Endpunkte und Scopes sind
vorgegeben. Aktive Anbieter erscheinen als Knöpfe auf der Login-Seite und unter Konto > Einstellungen
("Verknüpfte Anmeldungen").

- Google: [console.cloud.google.com](https://console.cloud.google.com) > APIs & Dienste > OAuth-Zustimmungsbildschirm
  einrichten, dann Anmeldedaten > OAuth-Client-ID, Typ "Webanwendung", Redirect-URI als "Autorisierte
  Weiterleitungs-URI". Scopes: `openid profile email`.
- Microsoft: [entra.microsoft.com](https://entra.microsoft.com) > App-Registrierungen > Neue Registrierung,
  Plattform "Web", Redirect-URI eintragen; unter "Zertifikate & Geheimnisse" ein Client-Secret erstellen (Wert, nicht
  ID). Tenant im Formular: `common` (alle Konten), `organizations`, `consumers` oder Tenant-ID. Scopes:
  `openid profile email`.
- GitHub: Settings > Developer settings > OAuth Apps > New OAuth App, Redirect-URI als "Authorization callback URL",
  dann "Generate a new client secret". Scopes: `read:user user:email` (Subjekt = numerische GitHub-ID).
- Facebook: [developers.facebook.com](https://developers.facebook.com) > Meine Apps > App erstellen (Anwendungsfall
  "Facebook Login"), unter Facebook Login > Einstellungen die Redirect-URI bei "Gültige OAuth Redirect URIs"
  eintragen. App-ID = Client-ID, App-Secret (App-Einstellungen > Allgemein) = Client-Secret; App live schalten.
  Scopes: `email public_profile`; Benutzerdaten über Graph API v19.0 `/me?fields=id,name,email`
  (Subjekt = app-spezifische Facebook-ID).
  **Annahme:** Facebook akzeptiert die PKCE-Parameter (bzw. ignoriert sie); falls die Anmeldung daran scheitert,
  unter Erweitert "PKCE" abschalten. "Require App Secret" (appsecret_proof) wird nicht unterstützt.

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
- Eigener Anmelde-Anbieter (Konto > Einstellungen): jeder Benutzer kann einen eigenen OAuth2-/OIDC-Anbieter
  (z. B. selbst gehostetes Authentik/Keycloak) hinterlegen: Discovery-URL oder Endpunkte von Hand, Client-ID,
  Client-Secret (verschlüsselt). Die angezeigte Redirect-URI (`/login/sso/p-…/callback`) beim Anbieter eintragen,
  speichern, dann "Verbinden". Anmelden: Login-Seite > "Mit eigenem Anbieter anmelden" (Benutzername) oder der
  persönliche Link `/login/eigener/<benutzername>`; beide zeigen zuerst "Weiter zu <host>?" (kein direkter
  Redirect), erst der Knopf startet die Anmeldung. TOTP gilt auch hier.
  Der Authorize-Endpunkt muss zum Issuer gehören (gleicher Host, Subdomain oder gleiche übergeordnete Domain;
  **Annahme:** ohne Public-Suffix-Liste, übergeordnet = Issuer-Host ohne erstes Label bei mind. 3 Labels).
  Der Anbieter gehört nur seinem Besitzer (`sso_providers.owner_user_id`): nicht auf der Login-Seite, nicht für
  andere, nicht in `/backend/sso` (in der Benutzer-Detailansicht nur Name/Host/Status). Eine Identität eines
  eigenen Anbieters meldet nur dessen Besitzer an. Änderung von Issuer/Endpunkten/Client-ID trennt die Verknüpfung.
  Schutz gegen SSRF (`app/Support/OutboundUrl.php`): nur https, Host muss auf öffentliche Adressen auflösen
  (private, Loopback, Link-Local inkl. 169.254.169.254, CGNAT, ULA usw. abgelehnt), Hostnamen werden als ASCII
  (IDN) gespeichert, die aufgelöste IP wird für die Verbindung fixiert, keine Redirects, Timeouts, Antwort max. 1 MB;
  geprüft beim Speichern (auch die Endpunkte aus der Discovery) und bei jedem Abruf.
  Rate-Limit: 10 Abfragen/Minute pro IP; pro Benutzername nur fehlgeschlagene (10 in 10 Minuten), damit Dritte
  niemanden aussperren können.
  Anfragen an eigene Anbieter gehen nie über einen Proxy (auch nicht `HTTPS_PROXY`): der Server braucht dafür
  direkten ausgehenden HTTPS-Zugang.
- Geld immer in Rappen (Integer), Anzeige `CHF 1.50`. Saldo = Summe der bestätigten Buchungen (`transactions`).
  - Doppeltes Abschicken (Doppeltipp) wird verhindert: Einmal-Token pro Formular (Bier, Bier für Gast, Einzahlung melden)
    und Sperre des Knopfs nach dem Absenden.
  - Bier: Preis im Moment des Abstreichens wird pro Buchung gespeichert; Preisänderungen ändern alte Buchungen nie.
    Limit: neuer Saldo darf nicht unter `-Limit` fallen (individuell oder Standard). Eigene Striche 10 Minuten
    rückgängig machbar.
  - "Offen": bei negativem Saldo der offene Betrag; die offenen Biere werden mit ihrem ursprünglichen Preis
    angezeigt (Einzahlungen decken die ältesten Biere zuerst). Die Positionen ergeben immer das Total:
    volle offene Biere, Rest eines teilweise bezahlten Biers, übrige Beträge (negative Korrekturen).
  - Einzahlung: Benutzer meldet (offen), Kassier bestätigt/lehnt ab; Kassier kann direkt buchen.
    Solange offen, kann der Benutzer die eigene Meldung im Dashboard antippen und ändern (Betrag, Bar/TWINT) oder
    zurückziehen ("Nicht bezahlt", Meldung wird gelöscht); der Kassier sieht "geändert". Bereits bestätigte oder
    abgelehnte Meldungen sind gesperrt. **Annahme:** keine neue Kassier-Mail bei Änderung/Rückzug.
  - Haus verlassen (nur mit Saldo ≥ 0): Auszahlung beantragen (Kassier bestätigt die Auszahlung, Betrag = Saldo)
    oder Guthaben spenden. Danach ist das Konto `left` (keine Anmeldung, Verlauf bleibt).
  - Korrektur durch Kassier/Admin mit Pflicht-Bemerkung.
- Kasse (`/backend/kasse`): Kassenstand = bestätigte Einzahlungen − Auszahlungen − Ausgaben + Einnahmen ±
  Korrekturen, getrennt nach Bar und TWINT. Überschuss = Kassenstand − Guthaben aller Benutzer.
- Gäste (`/backend/gaeste`, Kassier und Admin): zwei Schalter, Liste aller Gäste-Verkäufe ("gebucht von <Benutzer>"
  bzw. "öffentlich") mit Storno. Alle Gäste-Verkäufe werden sofort gebucht (Tabelle `guest_sales`, Preis zum
  Zeitpunkt): zählen als Einnahme in der Kasse (Bar/TWINT), reduzieren das Lager (können die Lager-Mail auslösen)
  und erscheinen in der Statistik als "Gäste" (nie als Benutzer).
  - "Gäste durch Benutzer buchen" (Standard an; **Annahme:** an, damit Gäste ohne öffentliche Seite bedient werden
    können): angemeldete Benutzer erfassen im Konto unter "Bier für Gast" 1–10 Biere, Bar oder TWINT (TWINT nur mit
    hinterlegter Nummer, Nummer mit Kopier-Knopf), Name freiwillig. Guthaben und Limit des Benutzers bleiben
    unverändert; gespeichert wird, wer gebucht hat (`guest_sales.booked_by`). Meldung z. B. "3 Biere für Gast
    erfasst – bitte CHF 4.50 bar in die Kasse legen". Eigene Gäste-Buchungen 10 Minuten rückgängig machbar,
    Einmal-Token gegen Doppelbuchung.
  - "Öffentliche Gäste-Seite (QR + Gäste-Passwort)" (Standard aus): eigenes Gäste-Passwort (verschlüsselt, nicht das
    Registrierungspasswort; zum Einschalten Pflicht), druckbarer Gäste-QR-Code (mit/ohne Passwort).
    Öffentliche Seite `/gast` (Passwort per `?code=` vorausgefüllt oder eingeben): Name freiwillig, 1–10 Biere,
    Bar oder TWINT (TWINT-Nummer mit Kopier-Knopf). Passwort wird zuerst geprüft; 10 Versuche/Minute pro IP (auch
    falsche Passwörter), Einmal-Token gegen Doppelbuchung.
- Lager (`/backend/lager`, Kassier und Admin): Bestand = gekaufte Biere (Kassenbuch "Bierkauf", Feld "Anzahl Biere"
  Pflicht; alte Einträge ohne Anzahl zählen 0) + Inventur-Korrekturen (gezählter Bestand, gespeichert als Differenz
  mit Bemerkung, wer, wann) − bestätigte abgestrichene Biere (Rückgängig erhöht den Bestand wieder).
  Bestand im Backend-Dashboard und im Kassenbuch. Mindestbestand (Standard 20, 0 = aus): fällt der Bestand durch
  Abstreichen darunter, geht einmal eine Mail an Kassiers/Admins (Benachrichtigungs-Schalter gilt); erneut erst,
  nachdem der Bestand wieder ≥ Mindestbestand war. Warnung im Backend, solange er darunter ist.
  Abstreichen durch Benutzer wird bei Bestand 0 nicht blockiert; negativer Bestand wird so angezeigt.
  Gäste-Verkäufe dagegen nie über den Bestand hinaus (Meldung "Nur noch X Biere an Lager").
- Statistik `/statistik`: Biere total, pro Monat, pro Wochentag, Rangliste (Diagramme als SVG vom Server).
  **Annahme:** Wer "In Statistik anzeigen" ausschaltet, wird für andere nirgends namentlich gezeigt
  (auch nicht in der Rangliste im Backend); die Biere zählen nur in den anonymen Totals.
  Kassiers sehen in der Benutzerverwaltung trotzdem Saldo und Buchungen aller Konten.
- Einzahlungsvorschläge (`/backend/einzahlungsvorschlaege`, Kassier und Admin): eigene CHF-Beträge
  (z. B. "30, 60, 90", max. 6, aufsteigend, je durch den Bierpreis teilbar) oder automatisch: kleinstes
  Vielfaches des Bierpreises, das durch CHF 10 teilbar ist, dann 2×, 3×, 5× (bei CHF 1.50: 30/60/90/150).
  Passt ein gespeicherter Betrag nach einer Preisänderung nicht mehr, wird er auf ganze Biere aufgerundet.
  Bei Minus: Vorschlag = offener Betrag + Betrag ("inkl. Minus"). Der erste Vorschlag ist vorausgewählt.
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
