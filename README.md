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
   - Bierpreis (Standard CHF 1.50), Standard-Limit (Standard 0 = kein Minus), TWINT (Schalter, Empfänger: Name aus Kassier/Admin oder frei, Nummer optional).
   - Rückgängig (Entscheid 2026-10-22): Minuten pro Buchungsart, 0–60 (0 = nie): Bier abstreichen 10, Runde spendieren 10,
     Bier für Gast 10, Bier verschenken 5 (Setting `undo_minutes`; der Wert `gift_undo_minutes` aus 1.7.0 gilt weiter,
     bis gespeichert wird).
   - Registrierung (Entscheid 2026-10-21): Registrierungsmodus wählen:
     - "Geschlossen": keine Registrierung, auch Einladungslinks sind ungültig.
     - "Nur per Einladung" (Standard bei Neuinstallation): nur mit gültigem Einladungslink, das
       Registrierungspasswort wird nicht akzeptiert.
     - "Offen mit Registrierungspasswort": wie bisher mit Passwort; gültige Einladungslinks funktionieren auch.
     Der frühere Schalter "Registrierung offen" wird beim Migrieren übernommen: aus → "Geschlossen",
     an (oder nie gespeichert, alter Standard an) → "Offen mit Registrierungspasswort".
     Registrierungspasswort (nur im Modus "Offen mit Registrierungspasswort" nötig, ohne Passwort geht dort nur die
     Einladung): Sonderzeichen erlaubt (min. 4, max. 100 Zeichen); Leerzeichen am Anfang/Ende werden entfernt.
     Änderung gilt sofort, kein Cron.
     Rate-Limit pro IP: 10 Fehlversuche (falsches Passwort, ungültiger Einladungslink oder Flyer-Code oder
     Formularfehler) in 15 Minuten, max. 60 Anfragen in 10 Minuten (ein Büro teilt meist eine IP).
     QR-Code zum Aushängen unter `/backend/registrierung-qr` (Kassier und Admin, siehe "QR-Codes" unten), dort auch als druckfertiger A4-Flyer
     (Reiter "Flyer"). Der Flyer ist ein SVG-Bild in exakt A4 (`/backend/registrierung-qr/flyer-bild`, gleicher
     Parameter `qr`, `?download=1` zum Herunterladen; `app/Support/FlyerSvg.php`), damit jeder Browser gleich
     druckt. Auf der Seite: "Flyer drucken" (nur das Bild, zentriert, max. 92 % Breite bzw. 235 mm Höhe),
     "Als PNG herunterladen" (im Browser gerendert, 2480 x 3508 px = A4 300 dpi) und "Als SVG herunterladen".
     Texte im SVG werden nach Zeichenanzahl umbrochen (**Annahme:** Systemschriften sind nicht breiter als
     angenommen). Entscheid 2026-10-21: Der QR-Code enthält nie das Registrierungspasswort, sondern einen
     zufälligen Flyer-Code (`/registrieren?flyer=<code>`, Tabelle `qr_codes`, Art `flyer`; mehrere aktiv möglich,
     der erste legt ein Admin an, siehe "QR-Codes"). Gültig nur im Modus "Offen mit
     Registrierungspasswort"; dann zeigt die Registrierung kein Passwortfeld. Falscher Code zählt wie ein falsches
     Passwort. Ein deaktivierter Flyer-Code macht die damit gedruckten Flyer ungültig, bis er reaktiviert wird. Gedruckt wird
     als Text nur `/registrieren` plus Hinweis "Registrierungspasswort beim Kassier", nie die URL mit Code.
     Alte QR-Codes mit `?code=<passwort>` füllen das Passwortfeld weiterhin aus.
     Registrierungscodes (Entscheid 2026-10-22, nur Admin, gleiche Seite): mehrere, beschriftet, für beliebig viele
     Personen, ohne Ablauf, gültig in **jedem** Modus (auch "Geschlossen"); Konto sofort aktiv
     (`/registrieren?zugang=<code>`, 32 Zeichen, Tabelle `registration_codes`, Code verschlüsselt + SHA-256 für die
     Suche, `users.registration_code_id`). Deaktivieren/Reaktivieren (Entscheid 2026-10-23: kein Löschen mehr);
     deaktivierter oder unbekannter Code → Startseite mit Hinweis "gesperrt" bzw. "nicht mehr gültig" (unbekannt zählt fürs Rate-Limit).
     Mail an Kassier/Admin wie jede Selbst-Registrierung (folgt dem Schalter), mit Zeile "Registrierungscode".
     Die QR-Seite zeigt nur Mögliches: Registrierungscodes und im Modus "Offen mit Registrierungspasswort"
     zusätzlich die Flyer-Codes; einen QR-Code ohne Code gibt es nicht. Gedruckt wird die vollständige URL mit Code.
   - QR-Codes (Entscheid 2026-10-23, gilt für Kassen-QR, Flyer-Codes, Gäste-QR und Registrierungscodes): Ansicht und
     Verwaltung getrennt. Ansicht (Kassier-Leiste, Reiter "QR-Codes", für Kassier und Admin gleich): nur aktive Codes
     anzeigen, drucken, herunterladen; Admins sehen dort nur einen Link "QR-Codes verwalten". Verwaltung (Backend-Leiste,
     Reiter "QR-Codes", `/backend/verwaltung/qr-codes`, nur Admin): Schalter "Kassen-QR aktiv", "Öffentliche
     Gäste-Seite", Gäste-Passwort; pro Art neuer Code (Name freiwillig), umbenennen, deaktivieren, Historie mit
     Reaktivieren; Registrierungscodes erstellen, deaktivieren, reaktivieren. Mehrere
     Codes pro Art können aktiv sein; nichts wird gelöscht, deaktivierte bleiben als Historie (nur Admin sichtbar).
     Erste Codes (Entscheid 1.15.2): Kassier-Seiten (GET) legen nie Codes an und zeigen "Noch kein Code. Ein Admin muss
     einen anlegen." Fehlende erste Codes (Kasse Bar/TWINT, Gäste, Flyer nur im Modus "Offen mit Registrierungspasswort")
     erzeugt nur ein Admin: beim Öffnen der QR-Verwaltung, beim Speichern der QR-Schalter bzw. der Einstellungen
     (`created_by` = Admin, Audit `qr_code.create`; Sperre + erneute Prüfung gegen doppeltes Anlegen). Ohne Flyer-Code
     fällt der Einladungs-/Flyer-Link auf den normalen Registrierungslink zurück. Aktiv/Inaktiv-Schalter: `active` ist
     Pflicht (boolean); unveränderter Zustand schreibt keinen Audit-Eintrag. Wird der letzte aktive Code einer Art
     deaktiviert, erscheint eine Warnung mit den Folgen (zusätzlich zur Rückfrage am Knopf).
     Tabelle `qr_codes` (Art `cashbox_cash`/`cashbox_twint`/`flyer`/`guest`, Name freiwillig, Code verschlüsselt +
     SHA-256 für die Suche, `deactivated_at`); die früheren Einzel-Codes aus den Settings werden beim Migrieren
     übernommen (gedruckte Aufkleber/Flyer bleiben gültig). Audit: `qr_code.create/label/deactivate/activate`.
     Übersicht in der Kassier-Leiste (`/backend/qr-codes`): alle Arten mit Anzahl aktiver Codes und Link zur Seite.
   - Alle QR-Codes (`App\Support\Qr`, SVG serverseitig, Fehlerkorrektur H) zeigen in der Mitte das Bierkrug-Logo (`public/logo.svg`,
     weisse Fläche mit Rand, ca. 21 % der Code-Breite) auf Bildschirm, Download und A4-Druck; wird beim Erzeugen eingebettet,
     bestehende Codes bleiben gültig und lesbar (Inhalt unverändert).
   - Reiter-Reihenfolge Standard (Entscheid 2026-10-23): Kassier: Übersicht, Kasse, Lager, Runden, Gäste, Einladungen,
     Verschenkt, Statistik, Benutzer, QR-Codes, Vorschläge, Protokoll. Backend: Einstellungen, Feedback, SSO, Navigation,
     QR-Codes (Verwaltung), Protokoll (gleiche Seite wie beim Kassier, `/backend/verwaltung/protokoll`), Wartung.
   - Mailversand: Server-sendmail (Standard, `MAIL_SENDMAIL_PATH`) oder SMTP (Passwort verschlüsselt gespeichert).
     Kassier-Benachrichtigung (neue Einzahlung, Auszahlungsantrag, neue Selbst-Registrierung mit Link zum Benutzer) an alle Kassiers/Admins mit E-Mail.
     Alle Mails (auch Passwort-Reset) nutzen ein gemeinsames HTML-Layout (`resources/views/mail/layout.blade.php`, Inline-CSS, Logo `public/icon-192.png` als CID eingebettet, fehlt es, entfällt das Logo) plus Textversion; Fakten als Tabelle, Link als Button.
   - SSO (`/backend/sso`): Google, Microsoft (Tenant, Standard `common`), GitHub, Facebook oder generisches OIDC
     (Discovery-URL). Redirect-URI beim Anbieter: `https://<domain>/login/sso/{kuerzel}/callback`.
     Anmelden per SSO kann nur, wer den Anbieter vorher unter Konto > Einstellungen verknüpft hat.
     Einrichtung pro Anbieter: siehe "SSO-Anbieter einrichten".
   - Einladen (Entscheid 2026-10-21): Jeder angemeldete aktive Benutzer erstellt unter `/konto/einladen`
     ("Jemand Neues einladen", kleiner Link unten im Dashboard) einmalige Einladungslinks
     `/registrieren?einladung=<token>` (enthält kein Passwort): QR-Code, Link mit Kopier- und Teilen-Knopf.
     Gültig 7 Tage (`Invitation::VALID_DAYS`), nur für 1 Registrierung; max. 10 offene (unbenutzt, nicht abgelaufen)
     Links pro Benutzer (`Invitation::MAX_OPEN_PER_USER`). Gespeichert wird nur der SHA-256-Hash des Tokens
     (Tabelle `invitations`), der Link wird nur direkt nach dem Erstellen angezeigt. Liste der eigenen Einladungen
     (letzte 20) mit Status, unbenutzte lassen sich zurückziehen. Mit gültigem Link zeigt die Registrierung
     "Eingeladen von <Benutzername>" ohne Passwortfeld; ungültig, abgelaufen oder benutzt → Hinweis ohne Formular
     (im Modus "Offen mit Registrierungspasswort" normales Formular mit Passwortfeld). Der Link wird in der
     Registrierungs-Transaktion atomar verbraucht (bedingtes Update), doppelte Nutzung ist ausgeschlossen.
     Wer wen eingeladen hat: `invitations.used_by`; die Registrierungs-Mail nennt "Eingeladen von".
     **Annahme:** Links von nicht mehr aktiven (ausgetretenen) Benutzern sind ungültig.
     Benutzer laden in jedem Modus nur per Einladungslink ein (Entscheid 2026-10-21: kein Registrierungs-QR-Code
     mit Passwort auf dieser Seite).
     Im Modus "Geschlossen" 404 und Link ausgeblendet.
     Backend `/backend/einladungen` (Kassier und Admin): alle Einladungen mit Ersteller, Status
     (offen / benutzt von X / abgelaufen), unbenutzte zurückziehen.
  - Anmeldung: "Eigene Anbieter für User erlauben" (Standard an), siehe "Eigener Anmelde-Anbieter".
  - Datenschutz und Nutzungsbestimmungen (eigenes Formular unten auf der Seite, siehe "Datenschutz und
    Nutzungsbestimmungen" unter Funktionen): verantwortliche Person, Kontakt-E-Mail (Pflicht, öffentlich), Server-Standort, beide Texte.
8. Steht ein Reverse-Proxy vor PHP, dessen IP in `.env` als `TRUSTED_PROXIES` (kommagetrennt, Standard: keine;
   gelesen in `bootstrap/app.php`, einzelne IPs oder CIDR, `*` = alle) eintragen, sonst werden `X-Forwarded-*`-Header
   ignoriert.
   Plesk: Dort läuft nginx vor Apache bzw. PHP-FPM auf demselben Server. Ohne Eintrag sieht Laravel als Client-IP
   immer die Proxy-Adresse, alle Besucher teilen sich dann die Rate-Limits. In `.env`: `TRUSTED_PROXIES=127.0.0.1`
   (**Annahme:** nginx verbindet sich von localhost; sonst die als `REMOTE_ADDR` sichtbare Adresse eintragen, z. B. die
   Server-IP), danach `php artisan config:clear`. Prüfen: Neue Audit-Einträge speichern die IP (Spalte `ip`), dort muss die
   echte Client-IP stehen.
   Wirkung auf das Kassen-QR-Limit (`/kasse/<code>`: 60 Aufrufe bzw. 30 Buchungen pro Minute und IP): ohne Eintrag
   zählen alle Gäste zusammen und die Kasse sperrt sich schon bei wenigen gleichzeitigen Gästen; mit korrekter
   Proxy-IP zählt jede Client-IP einzeln. Wichtig: Die Rate-Limits (Login, TOTP, Gäste,
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
  Kopfzeile: Kassier und Admin sehen "Kassier" (Kassier-Seiten inkl. Protokoll, mit der Zahl offener Posten),
  Admins zusätzlich "Backend" (Einstellungen, SSO, Registrierungs-QR, Feedback mit Zahl ungelesener, Wartung).
  Die Zahl offener Posten (Einzahlungen, Auszahlungen, Überträge; `Ledger::pendingCount()`) steht zusätzlich als Badge am Reiter
  "Übersicht" (dort werden sie bestätigt); bei 0 ausgeblendet. Mobil scrollt die Seite nie seitlich: Kopfzeile (Logo, Navigation, Benutzer, Abmelden) steht ab Telefonbreite (bis 520 px, Name
  ausgeblendet, enge Abstände) in einer Zeile (Logo links, alle übrigen Einträge rechtsbündig gruppiert, für alle Rollen) und bricht erst unter 360 px um,
  nur die Reiterleiste scrollt/wischt in ihrem eigenen Container, Tabellen in `.table-wrap`.
  So sieht der Admin unter "Kassier" dieselben Seiten wie ein Kassier (plus Rolle/Nur-Verwaltung in der Benutzer-Detailansicht).
  Reihenfolge der Reiter (`App\Support\BackendNav`): Admin legt den Standard unter Backend → Navigation fest
  (Setting `nav_order`), jeder Kassier/Admin kann sie in den Konto-Einstellungen für sich ändern (`users.nav_order`,
  Kassier nur die Gruppe "Kassier"); "Standard wiederherstellen" auf beiden Seiten. Ziehen oder ↑/↓-Knöpfe.
  Neue Reiter erscheinen hinten an, unbekannte werden ignoriert.
  Kassier und Admin brauchen eine E-Mail: Pflicht im Setup, im eigenen Profil nicht leerbar, Rolle kassier/admin
  nur vergebbar mit E-Mail; bestehende Konten ohne E-Mail sehen einen Hinweis (Zugriff bleibt).
- Anmeldung `/`: Benutzername oder E-Mail (nur wenn eindeutig) + Passwort, optional TOTP (im Konto einrichten, Wiederherstellungscodes).
  Auch nach einer SSO-Anmeldung wird TOTP verlangt, wenn der Benutzer es eingerichtet hat.
  Passwort vergessen per Mail (nur mit hinterlegter E-Mail; die E-Mail ist pro Konto eindeutig).
  Kassier/Admin können Passwörter setzen ("muss bei nächster Anmeldung geändert werden") und TOTP zurücksetzen.
  Kassiers können Admin-Konten nicht ändern (Passwort, TOTP, Status).
  Deaktivieren geht nur mit Saldo 0 und ohne offene Posten (sonst "Haus verlassen" oder Korrektur);
  der letzte aktive Admin kann nicht deaktiviert werden. Auf ausgetretene Konten wird nichts mehr gebucht.
- Konto sperren (Entscheid 2026-10-24, Status `locked` neben `active`/`left`): Kassier und Admin in der Benutzer-Detailansicht,
  Grund Pflicht (max. 500 Zeichen, `users.locked_reason`, `locked_at`, `locked_by`; angezeigt in der Detailansicht,
  Audit `user.lock`/`user.unlock`). Anders als Deaktivieren auch mit Schulden oder offenen Posten. Kassiers können
  keine Admins sperren, der letzte aktive Admin nicht; **Annahme:** niemand sperrt sich selbst (gegen Selbstaussperrung).
  Gegen gleichzeitige Anfragen (zwei Admins sperren bzw. deaktivieren sich gegenseitig) sperren Sperren und Deaktivieren
  in einer DB-Transaktion zuerst alle aktiven Admins (nach ID), dann das Zielkonto, und zählen danach; Entsperren liest
  das Konto unter Sperre neu (kein Überschreiben eines parallelen Austritts). Wird ein gesperrtes Konto ausgetreten
  (bestätigte Auszahlung), werden die Sperrdaten geleert. Entsperren → `active`
  (Sperrdaten werden geleert, das Protokoll behält sie). Ein gesperrtes Konto wird nicht über "Deaktivieren"
  geändert (zuerst entsperren).
  - Wirkung: keine Anmeldung (Passwort, SSO, eigener Anbieter, TOTP-Schritt), laufende Sitzungen enden bei der nächsten
    Anfrage. Meldung "Konto gesperrt, bitte an <Kontakt-E-Mail> wenden" erst nach richtigem Passwort bzw. erfolgreicher
    SSO-Anmeldung (verrät sonst keine Benutzernamen; Audit `auth.login_locked`). Der eigene Anbieter
    (`/login/eigener/<name>`) zeigt wie für unbekannte Namen "nicht verfügbar" (vor der Anmeldung, nichts verraten). Kein Passwort-Reset, keine
    Mails an gesperrte Kassiers, Einladungslinks gesperrter Benutzer ungültig (alles wie bisher über "nur aktive").
  - Saldo und Verlauf bleiben; Kassier bucht weiter Einzahlungen, Korrekturen, bestätigt offene Posten und storniert
    (Ledger `assertNotLeft`). Nicht in der Kollegen-Liste (Runde, Verschenken) und kein Empfänger.
  - Benutzerliste: gesperrte Konten stehen unter "Aktiv" mit Badge "Gesperrt" (Entscheid: kein eigener Filter); in
    "Im Minus" ebenfalls mit Badge. Rangliste/Statistik unverändert (filtern nicht nach Status).
  - Zählt nicht als aktiver Admin (Regel "letzter Admin").
- Storno durch Kassier/Admin (Entscheid 2026-10-24): in der Benutzer-Detailansicht im Verlauf pro Buchung Typ Bier,
  Runde oder verschenkte Biere (Zeile des Schenkers, bestätigt) "Stornieren" mit Pflicht-Grund, jederzeit (auch nach
  dem Rückgängig-Fenster). Wirkung wie das eigene Rückgängig: die Buchung wird gelöscht (samt `round_items` bzw.
  Gutschriften der Empfänger), damit stimmen Saldo, Statistik und Lager wieder; Lager-Mail wird neu scharf gestellt.
  Gleiche Sperren und Regeln (DB-Transaktion, Zeilensperren: Benutzer nach ID, dann Buchung; zweites Storno scheitert).
  Geschenk: wie beim Rückgängig nicht mehr möglich, sobald ein Empfänger seit dem Geschenk eine Ausgangsbuchung hat
  (Meldung: stattdessen Korrektur buchen). Nicht für ausgetretene Konten und "Nur Verwaltung"; gesperrte gehen.
  Audit `consume.storno`/`round.storno`/`gift.storno` mit dem ganzen Datensatz (alte Werte inkl. Empfänger, Erstellt
  von/am) und dem Grund. Der Benutzer sieht das Storno nicht im Verlauf (Zeile gelöscht wie beim Rückgängig), nur im Protokoll.
  **Annahme:** Kassier/Admin dürfen für sich selbst buchen (Limit, Einzahlung, Korrektur). Jede Buchung
  speichert, wer sie erfasst hat (`created_by`), damit bleibt das im Verlauf nachvollziehbar.
- "Nur Verwaltung" (Entscheid 2026-10-22, `users.admin_only`): Admin- oder Kassier-Konto ohne Bierkonto, z. B. ein
  reines Admin-Konto neben dem eigenen Benutzerkonto. Jeder Admin/Kassier schaltet es selbst unter Konto >
  Einstellungen (Karte "Nur Verwaltung", bleibt immer erreichbar, so geht es auch wieder aus); Admins zusätzlich in
  der Benutzer-Detailansicht im Backend (auch für sich selbst). Umschalten (ein und aus) nur mit Saldo 0 und ohne offene Posten, sonst Schalter gesperrt mit Saldo-Hinweis
  ("bitte zuerst den Saldo ausgleichen"); Rolle auf `user` setzt es zurück; Registrierung/Einladung/Setup setzen es
  nie (nicht fillable).
  Wirkung: `/konto` leitet ins Backend, Anmeldung und Logo führen ins Backend, Link "Konto" ausgeblendet. Alle
  Geld-/Bier-Aktionen sind serverseitig gesperrt (Middleware `beer`: Seiten → Backend mit Hinweis, Aktionen 403;
  zusätzlich im `Ledger`): Abstreichen, Rückgängig, Bier für Gast, Runde, Einzahlung melden/ändern/zurückziehen,
  Verlauf, Haus verlassen. Im Backend keine Einzahlung/Korrektur/Limit auf das Konto. Bleibt: Einstellungen
  (Passwort, TOTP, SSO, E-Mail), Feedback, Einladen, Statistik-Seite, Backend; Kassier-/Feedback-Mails; zählt als
  Admin (Regel "letzter Admin"). Nicht in der Kollegen-Liste "Runde spendieren", nicht in der Rangliste, nicht in
  "Im Minus"; in der Benutzerliste mit Badge "Nur Verwaltung" ohne Saldo/Limit. Karten "Statistik" und
  "Haus verlassen" in den Einstellungen ausgeblendet.
  **Annahme:** Ältere Biere eines solchen Kontos zählen weiter in den Totals der Statistik, nur nicht namentlich.
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
  Verfügbarer Saldo (Entscheid 2026-10-23, ersetzt "offene Einzahlungen zählen nicht"): gemeldete, noch nicht
  bestätigte Einzahlungen (Bar/TWINT) und Überträge zählen für den Benutzer sofort (`Ledger::availableBalance()`,
  Scope `Transaction::available()`): Anzeige im Konto/Verlauf als eine Zahl (Biere und CHF, kein "ausstehend"),
  "Offen" und alle Limit-Prüfungen (Abstreichen, Runde, Verschenken). So kann getrunken werden, wenn der Kassier
  fehlt. Lehnt der Kassier ab oder zieht der Benutzer zurück, geht der Benutzer einfach ins Minus, auch über das
  Limit hinaus (wird persönlich geklärt, keine automatische Sperre). Offene Auszahlungen zählen nicht (sperren das
  Buchen wie bisher). Backend (Kassenstand, Guthaben aller Benutzer, Überschuss, Saldo in der Benutzerverwaltung,
  "Im Minus") zählt nur bestätigtes Geld.
  - Doppeltes Abschicken (Doppeltipp) wird verhindert: Einmal-Token pro Formular (Bier, Bier für Gast, Einzahlung melden)
    und Sperre des Knopfs nach dem Absenden.
  - Bier: Preis im Moment des Abstreichens wird pro Buchung gespeichert; Preisänderungen ändern alte Buchungen nie.
    Limit: neuer verfügbarer Saldo darf nicht unter `-Limit` fallen (individuell oder Standard). Eigene Striche rückgängig machbar
    (Zeit siehe Einstellungen "Rückgängig"). Die Rückgängig-Knöpfe (Bier, Runde, Verschenken, Bier für Gast)
    verschwinden genau bei Ablauf, auch wenn die Seite offen bleibt: jede Zeile trägt `data-expires` (Ablauf in ms,
    vom Server berechnet, `undoExpiresAt()`), `public/js/live.js` rechnet mit der Serverzeit
    (`<meta name="server-now">`), damit eine falsche Uhr am Gerät nicht stört. Der Server prüft weiterhin selbst.
  - "Offen": bei negativem Saldo der offene Betrag; die offenen Biere werden mit ihrem ursprünglichen Preis
    angezeigt (Einzahlungen decken die ältesten Biere zuerst). Die Positionen ergeben immer das Total:
    volle offene Biere, Rest eines teilweise bezahlten Biers, übrige Beträge (negative Korrekturen).
  - Einzahlung: Benutzer meldet (offen), Kassier bestätigt/lehnt ab; Kassier kann direkt buchen.
    Zählt für den Benutzer sofort (verfügbarer Saldo, siehe oben).
    Solange offen, kann der Benutzer die eigene Meldung im Dashboard antippen und ändern (Betrag, Bar/TWINT) oder
    zurückziehen ("Nicht bezahlt", Meldung wird gelöscht); der Kassier sieht "geändert". Bereits bestätigte oder
    abgelehnte Meldungen sind gesperrt. **Annahme:** keine neue Kassier-Mail bei Änderung/Rückzug.
  - Haus verlassen (nur mit Saldo ≥ 0): Auszahlung beantragen (Kassier bestätigt die Auszahlung, Betrag = Saldo)
    oder Guthaben spenden. Danach ist das Konto `left` (keine Anmeldung, Verlauf bleibt).
  - Korrektur durch Kassier/Admin mit Pflicht-Bemerkung.
  - Übertrag von der alten Strichliste (Entscheid 2026-10-21): bei der Registrierung optional "Ich schulde noch" /
    "Ich habe noch Guthaben", in Bieren (1–500, umgerechnet zum aktuellen Bierpreis, Anzahl und Preis werden
    gespeichert) oder CHF (max. 500.00). Wird als offene Buchung Typ `carryover` gespeichert (Schuld negativ,
    Guthaben positiv); zählt für den Benutzer sofort (verfügbarer Saldo, siehe oben), im Backend erst nach
    Bestätigung. Kassier/Admin bestätigen (Betrag anpassbar; dann
    wird die Bier-Anzahl verworfen und der gemeldete Wert in der Bemerkung festgehalten) oder lehnen ab im
    Backend-Dashboard; zählt bei den offenen Posten (Badge). Erwähnt in der Registrierungs-Mail. Der Benutzer sieht
    ihn in der Liste "Wartet auf Bestätigung" und kann ihn nicht ändern; solange offen, ist "Haus verlassen" gesperrt.
    Übertrag-Biere ändern weder Lager noch Statistik (vor der App getrunken); bestätigte Übertrag-Schulden in
    Bieren erscheinen unter "Offen" als Biere. **Annahme:** der Übertrag ändert den Kassenstand nicht, aber das
    Guthaben aller Benutzer und damit den Überschuss.
- Kasse (`/backend/kasse`): Kassenstand = bestätigte Einzahlungen − Auszahlungen − Ausgaben + Einnahmen ±
  Korrekturen, getrennt nach Bar und TWINT. Überschuss = Kassenstand − Guthaben aller Benutzer.
- Gäste (`/backend/gaeste`, Kassier und Admin; QR-Schalter und Gäste-Passwort nur Admin): Schalter, Liste aller Gäste-Verkäufe ("gebucht von <Benutzer>"
  bzw. "öffentlich") mit Storno. Alle Gäste-Verkäufe werden sofort gebucht (Tabelle `guest_sales`, Preis zum
  Zeitpunkt): zählen als Einnahme in der Kasse (Bar/TWINT), reduzieren das Lager (können die Lager-Mail auslösen)
  und erscheinen in der Statistik als "Gäste" (nie als Benutzer).
  - "Gäste durch Benutzer buchen" (Standard an; **Annahme:** an, damit Gäste ohne öffentliche Seite bedient werden
    können): angemeldete Benutzer erfassen im Konto unter "Bier für Gast" 1–10 Biere, Bar oder TWINT (TWINT nur mit
    hinterlegter Nummer, Nummer mit Kopier-Knopf), Name freiwillig. Guthaben und Limit des Benutzers bleiben
    unverändert; gespeichert wird, wer gebucht hat (`guest_sales.booked_by`). Meldung z. B. "3 Biere für Gast
    erfasst – bitte CHF 4.50 bar in die Kasse legen". Eigene Gäste-Buchungen rückgängig machbar (Zeit siehe Einstellungen),
    Einmal-Token gegen Doppelbuchung.
  - "Öffentliche Gäste-Seite (QR + Gäste-Passwort)" (Standard aus): eigenes Gäste-Passwort (verschlüsselt, nicht das
    Registrierungspasswort; zum Einschalten Pflicht). Druckbarer Gäste-QR-Code (`/backend/gaeste/qr`): enthält nie das
    Passwort, sondern einen Gäste-Code (`/gast?zugang=<code>`, Tabelle `qr_codes`, Art `guest`, mehrere aktiv
    möglich) – damit entfällt das Passwortfeld; deaktivierter Code → Passwortfeld mit Hinweis. Variante "Ohne Code":
    nur die Adresse, Passwort von Hand.
    Öffentliche Seite `/gast` (mit Gäste-Code, oder Passwort eingeben bzw. per `?code=` vorausgefüllt): Name freiwillig, 1–10 Biere,
    Bar oder TWINT (TWINT-Nummer mit Kopier-Knopf). Passwort wird zuerst geprüft; 10 Versuche/Minute pro IP (auch
    falsche Passwörter), Einmal-Token gegen Doppelbuchung.
  - "Kassen-QR aktiv" (Entscheid 2026-10-22, Standard aus): feste Aufkleber für die Kasse, für Gäste ohne Konto
    (Vertrauensbasis, keine Ortsprüfung): "Kein Konto? Bar bezahlt" und "Kein Konto? Ich habe getwintet". Jeder hat
    einen eigenen geheimen Code (Tabelle `qr_codes`, Art `cashbox_cash`/`cashbox_twint`, je einer vom Admin
    angelegt, mehrere aktiv möglich), unabhängig vom Gäste-Passwort; der Code bestimmt die Zahlart
    und öffnet nur `/kasse/<code>`. Seite: Anzahl 1–10 wählen und bestätigen ("Abbrechen" führt zu `/`: Login bzw. eigenes Konto), sofort als Gäste-Verkauf gebucht
    (`guest_sales.source = cashbox`, Liste "Kassen-QR"). Die Bar-Seite zeigt nie TWINT-Daten; die TWINT-Seite zeigt die
    Empfänger wie hinterlegt (Kopier-Knopf nur mit Nummer) und funktioniert nur, solange TWINT aktiv ist. Ungültiger
    Code = 404 (10 Fehlversuche/Minute pro IP). Im Backend pro aktivem Code Vorschau, PNG (945 x 1299 px = 300 dpi, im Browser
    gerendert) und SVG (80 x 110 mm, `app/Support/CashboxQrSvg.php`, `/backend/gaeste/kassen-qr/<id>`) sowie A4-Bogen
    mit 4 Aufklebern dieses Codes (`…/<id>/a4`); dazu "2 × Bar + 2 × TWINT" mit dem jeweils neuesten Code
    (`/backend/gaeste/kassen-qr-a4/gemischt`). A4: 2 x 2, 10 mm Abstand, gestrichelte Schnittlinien (Rand 20 mm
    seitlich, 33.5 mm oben/unten; **Annahme** 2026-10-22), je Öffnen/Drucken, PNG (A4 300 dpi) und SVG. Drucken mit 100 %.
- Dashboard-Karten (Entscheid 2026-10-24): "Einzahlen" (Einzahlung melden + TWINT-Empfänger/Nummer, TWINT nur wenn aktiv; "Wartet auf
  Bestätigung" darunter) und "Meine Buchungen & Statistik" (Kennzahlen, Diagramm, letzte Buchungen) sind je eine Karte.
- Fusszeile auf allen Seiten (auch Anmeldung, Gäste-Seiten): "<App-Name> v<Version> · <Copyright> · Datenschutz ·
  Nutzungsbestimmungen" (Links). Version aus `VERSION`;
  Copyright in den Einstellungen (max. 100 Zeichen, Text), Standard "© 2026 Stefan Diethelm", leer = ohne Copyright.
  Nicht im Druck.
- Datenschutz und Nutzungsbestimmungen (Entscheid 2026-10-24): zwei getrennte Texte, öffentlich unter `/datenschutz` und
  `/nutzungsbestimmungen` (abgemeldet und angemeldet, auch im Wartungsmodus und ohne Zustimmung), oben "Stand: <Datum>".
  - Bearbeiten: Backend > Einstellungen, Karte "Datenschutz und Nutzungsbestimmungen" (nur Admin, eigenes Formular,
    `PUT /backend/einstellungen/rechtliches`, Audit `legal.update` mit altem/neuem Text). Settings `legal_name`
    (Standard "Stefan Diethelm"), `legal_email` (Pflicht, Standard "bier@helminet.ch", öffentlich sichtbar, auch in der
    Meldung bei gesperrtem Konto), `legal_location` (Standard "Schweiz"), `privacy_text`/`terms_text` (leer bzw.
    unverändert = Standardtext aus `resources/legal/privacy.txt` bzw. `terms.txt`).
  - Platzhalter beim Anzeigen: `{name}`, `{email}`, `{standort}`, `{bierpreis}` (z. B. "CHF 1.50"), `{limit}`
    (Standard-Limit, z. B. "CHF 0.00"), `{undo_minuten}` (Rückgängig-Minuten "Bier abstreichen").
  - Darstellung (`App\Support\LegalTexts`): reiner Text, alles wird escaped (kein HTML). Leerzeile = neuer Absatz,
    Zeilenumbruch bleibt, Zeilen mit "- " = Liste, Zeilen wie "1. Titel" = Überschrift, eine einzelne erste Zeile = Seitentitel.
  - Version pro Text (`privacy_version`/`terms_version`, UTC, Standard `2026-10-24 00:00:00`) = "Stand". Beim Speichern
    pro Text eine Auswahl "Meldung an die Benutzer" (Entscheid 2026-10-24, v1.18.0): "Automatisch" (Standard: Update, wenn
    der Text geändert wurde, sonst ohne Meldung; nur Name/E-Mail/Standort = ohne Meldung), "Ohne Meldung" (nur
    "Zuletzt geändert"), "Als Update anzeigen" (Hinweis, kein Zwang), "Erneut zustimmen" (neuer Stand, Zustimmungs-Sperre).
    Kein JS: die Vorwahl "Automatisch" wird serverseitig aufgelöst.
  - "Zuletzt geändert" (`privacy_changed_at`/`terms_changed_at`, UTC): gesetzt bei jeder Textänderung, bei Update/Erneut
    zustimmen und bei automatischer Meldung (unten). Öffentliche Seite: "Stand: <Datum>" plus " · Zuletzt geändert: <Datum>",
    wenn das Datum vom Stand abweicht.
  - Update-Hinweis (`privacy_notice_at`/`terms_notice_at`, UTC; nur bei "Als Update anzeigen" und bei der automatischen
    Meldung, nicht bei "Erneut zustimmen"): angemeldete Benutzer sehen oben auf jeder Seite (unter der Kopfzeile, nicht im
    Druck, nicht auf `/zustimmung`, der Hinweis zu einem Text nicht auf dessen eigener Seite) "Die Nutzungsbestimmungen wurden am <Datum> aktualisiert. [Ansehen] [OK]". OK =
    `POST /rechtliches/{privacy|terms}/gelesen` (CSRF, ohne JS, kein Audit) setzt `users.privacy_notice_seen_at`/
    `terms_notice_seen_at`. Sichtbar, solange `seen_at` fehlt oder älter als der Hinweis ist. Registrierung und Zustimmung
    setzen beide `seen_at` auf jetzt (neue Konten sehen alte Hinweise nicht). Der Hinweis ist serverseitig im Layout
    (`main`), Live-Refresh ersetzt ihn mit der Seite.
  - Automatische Meldung: Ändert ein Admin Bierpreis, Standard-Limit oder Rückgängig-Minuten "Bier abstreichen" (nur bei
    echter Änderung), setzen alle Texte, die {bierpreis}/{limit}/{undo_minuten} enthalten (Standard: nur die
    Nutzungsbestimmungen), "Zuletzt geändert" und den Update-Hinweis. Audit `legal.update` (Schlüssel) plus `legal.mode`
    (gewählter Modus pro Text, nur wenn nicht "Ohne Meldung").
  - Registrierung: eine Pflicht-Checkbox "Ich akzeptiere die Nutzungsbestimmungen und die Datenschutzerklärung" (beide
    verlinkt, neuer Tab); ohne: "Bitte Nutzungsbestimmungen und Datenschutzerklärung akzeptieren", kein Konto (zählt
    wie andere Formularfehler fürs Rate-Limit). Gespeichert: `users.privacy_accepted_version`,
    `terms_accepted_version`, `consent_accepted_at`; die Versionen stehen im Audit `registration`.
  - Zustimmungs-Sperre (`EnsureActiveUser`, für alle Rollen inkl. Admin und "Nur Verwaltung"): stimmen die akzeptierten
    Versionen nicht mit den aktuellen überein, leitet jede Seite nach `/zustimmung` (beide Texte aufklappbar, Link zur
    Einzelseite, eine Checkbox, "Weiter" und "Abmelden"). Das Formular sendet die angezeigten Versionen mit; wurde ein
    Text inzwischen mit neuem Stand gespeichert, wird nichts gespeichert und die Seite erscheint erneut mit "Die Texte
    wurden geändert, bitte erneut lesen". Erreichbar bleiben nur diese Seite, Abmelden und die zwei
    öffentlichen Seiten. Reihenfolge: zuerst ein erzwungener Passwortwechsel, dann die Zustimmung. Danach zurück
    zur ursprünglich aufgerufenen Seite (nur GET-Seiten). Audit `consent.accept`. Live-Abfragen (`/live`,
    `X-Live-Refresh`) werden wie beim Passwortwechsel umgeleitet (`live.js` lädt bei Umleitung neu).
    Bestehende Konten haben nach dem Update noch keine Zustimmung und sehen die Seite bei der nächsten Anfrage.
  - **Annahme:** Der Admin aus dem Setup-Wizard gilt als zugestimmt (er betreibt die Installation und veröffentlicht die Texte).
  - Benutzer-Detailansicht im Backend: "Zustimmung: <Datum>: Datenschutzerklärung (Fassung vom …, Stand X),
    Nutzungsbestimmungen (Fassung vom …, Stand Y)" mit Links auf die akzeptierten Fassungen (Zustimmungen vor 1.19.0
    ohne Fassung: nur Stand) bzw. "noch offen"; Link "Alle Fassungen".
  - Fassungen-Archiv (Entscheid 2026-10-24, v1.19.0): Tabelle `legal_revisions` (Art, Stand, Text, sha256, Anlass,
    erstellt von/am). Gespeichert wird der gerenderte Quelltext (Platzhalter eingesetzt, kein HTML); angezeigt mit
    demselben sicheren Renderer, ohne erneutes Einsetzen von Platzhaltern. Unveränderlich: keine Route ändert oder
    löscht, das Modell verweigert Update/Delete.
    - Neue Fassung, sobald Text-Hash oder Stand einer Art von der neuesten Fassung abweicht (gleicher Text mit
      "Erneut zustimmen" = neue Fassung mit neuem Stand). Anlass: `reaccept`/`update`/`silent` (Speichern im Backend,
      gewählter Modus, auch Name/E-Mail/Standort), `settings` (Bierpreis/Limit/Rückgängig-Minuten), `auto` (verzögert
      erkannt beim Anzeigen von `/datenschutz`, `/nutzungsbestimmungen`, `/zustimmung`, `/registrieren`, der
      Fassungen-Liste, bei Zustimmung und Registrierung, z. B. neuer Standardtext per Deploy oder Platzhalter anderswo
      geändert), `initial` (erste Fassung einer Art, beim ersten Zugriff; keine Migration). Eine verzögert erkannte
      Fassung (`auto`) trägt Zeitpunkt und Benutzer (`created_by`, bei Gästen leer) der ersten Anfrage nach der
      Änderung, nicht den Zeitpunkt der Änderung selbst.
    - Fehlerverhalten: Die öffentlichen Seiten und das Registrierungsformular zeigen genau den Text der aktuellen
      Fassung; kann das Archiv nicht geschrieben werden (z. B. Migration ausstehend), zeigen sie den aktuellen Text
      und loggen den Fehler. Registrierung und Zustimmung schlagen dann mit Fehler fehl (fail-closed, keine
      Zustimmung ohne archivierte Fassung).
    - Sicher bei Parallelzugriff: Transaktion, Sperre auf den Audit-Kettenkopf (`audit_chain`), erneute Prüfung.
      Pro Anfrage gecacht: bei unverändertem Text eine Abfrage (SELECT) pro Art und Anfrage, danach nur noch die
      Hash-Berechnung; gecacht wird erst nach dem Commit (kein Rest nach Rollback). Audit `legal.revision`
      (Art, Stand, Hash, Anlass; ohne Text). `legal.update` bleibt.
    - Benutzer: `privacy_revision_id`/`terms_revision_id` bei Registrierung, Zustimmung und Setup-Admin. Zustimmungsseite
      und Registrierungsformular senden die angezeigten Fassungs-IDs als versteckte Felder (Zustimmung: statt der
      Versionen); ist eine davon nicht mehr die neueste, wird nichts gespeichert bzw. kein Konto erstellt ("Die Texte
      wurden geändert, bitte erneut lesen"; Registrierung: zurück mit Eingaben), streng auch bei einer neuen Fassung
      ohne neuen Stand (z. B. Preisänderung). Eine solche Fassung verlangt aber keine erneute Zustimmung (Sperre
      weiterhin nur über den Stand). Tests: `Tests\TestCase::post()` ergänzt beim Registrieren fehlende Fassungs-IDs.
    - Ansehen: `/rechtliches/fassung/{id}` für alle angemeldeten Benutzer (die Texte waren öffentlich), Kopf
      "Fassung vom <Datum> (Stand <Datum>)"; Gäste werden zur Anmeldung geleitet. Liste
      `/backend/rechtliches/fassungen` (Kassier und Admin, nur lesen, 25 pro Seite, Filter nach Text; Link in der
      Karte "Datenschutz und Nutzungsbestimmungen" und in der Benutzer-Detailansicht). Konto > Einstellungen:
      "Akzeptiert am <Datum>: …" mit Links auf die eigenen akzeptierten Fassungen.
    - Test-Factory: Benutzer haben die aktuellen Stände akzeptiert, aber keine Fassungs-IDs (die Sperre hängt nur am Stand).
  - Gäste ohne Konto (`/gast`, `/kasse/<code>`) sehen Links auf beide Texte, ohne Checkbox.
- PWA-Name: `short_name` im Manifest = voller App-Name (Home-Bildschirm-Vorschlag z. B. "Bierkasse LUKS").
- Runde spendieren (Entscheid 2026-10-21): Dashboard-Karte "Runde spendieren" (nach "Bier für Gast"). Angehakte
  aktive Kollegen (alle aktiven Benutzer ausser einem selbst, nach Name sortiert, mit Suchfeld) bekommen je 1 Bier,
  dazu 0–20 "weitere Biere" ohne Namen (z. B. Gäste) und eine freiwillige Nachricht (max. 140 Zeichen). Pro Runde
  1–30 Biere; Total (Biere, Betrag) wird live angezeigt.
  Zwei Knöpfe (Entscheid 2026-10-22): "Runde spendieren (N)" und "Runde inkl. mir (N+1)" (`include_self=1`): bucht
  zusätzlich 1 Bier für den Spender selbst (`round_items` mit `user_id` = Spender; zählt in Betrag, Limit und max. 30).
  Dieses Bier zählt in der Statistik als eigenes Bier des Spenders, nicht bei "Spendiert" (= Runden-Biere minus
  eigenes Bier); keine Hinweis-Karte und keine Mail an sich selbst. "inkl. mir" allein = 1 eigenes Bier.
  - Buchung wie "Bier abstreichen" (eine DB-Transaktion mit Zeilensperre, gleiche Limit-Regel "Bitte zuerst
    einzahlen", gesperrt bei beantragter Auszahlung, Einmal-Token): Spender zahlt Anzahl × aktueller Preis als
    Buchung Typ `round` (Betrag negativ, Anzahl, Preis gespeichert, Nachricht als Bemerkung). Die Biere stehen in
    `round_items` (pro Kollege 1 Zeile mit Anzahl 1, Biere ohne Namen als 1 Zeile ohne Benutzer). Der Spender kann
    die Runde zurücknehmen (Zeit siehe Einstellungen). Unter "Offen" zählen Runden-Biere wie eigene Biere (alter Preis).
    Kassenstand unverändert (wie Abstreichen); Lager sinkt um alle Biere der Runde (Lager-Mail wie beim Abstreichen); wie Abstreichen
    nicht durch den Lagerbestand begrenzt. Kollegen-Liste sortiert nach letztem eigenem Bier.
  - Favoriten (Entscheid 2026-10-24): Jeder Benutzer kann Kollegen mit ☆/★ anheften (Tabelle `user_favorites`, Spalte `list` =
    `round`|`gift`; Entscheid 2026-10-24: jede Liste hat eigene Favoriten, bestehende Favoriten wurden in beide Listen kopiert);
    Favoriten stehen oben, der Rest darunter in bisheriger Reihenfolge. Umschalten per
    `fetch` (`POST /konto/favorit/{round|gift}/{id}`, JSON, andere Liste = 404), nur die betroffene Liste sortiert sich in place um, die Auswahl der Häkchen bleibt; ohne JS normales
    Formular mit Redirect. Inaktive/gelöschte Kollegen erscheinen nicht und lassen sich nicht anheften (Abheften geht immer).
  - Empfänger sehen 1 Tag lang (`NOTICE_DAYS`, Entscheid 2026-10-24) im Dashboard "🍺 <Spender> hat dir ein Bier spendiert – <Nachricht>" (max. 3), mit ×-Knopf: ausgeblendet wird pro Benutzer serverseitig gespeichert (Tabelle `notice_dismissals`, geräteübergreifend, nie wieder sichtbar; `POST /konto/hinweis/{id}`). Keine Mail an Empfänger (Entscheid 2026-10-22: alle sitzen am selben Tisch).
  - Statistik: Biere zählen beim Trinker. Kollegen-Biere zählen beim Kollegen (eigene Statistik, Rangliste, Totals),
    Biere ohne Namen als eigene Zeile "Gäste (eingeladen)" in der Rangliste. Monate/Wochentage nach Zeitpunkt der
    Runde. Total = Abstreichen + Gäste-Verkäufe + Runden-Biere (die Runde selbst zählt nicht zusätzlich).
    "In Statistik anzeigen" aus gilt auch hier (nur im Total). Der Spender sieht "Spendiert: N" in der eigenen
    Statistik (auch in der Benutzer-Detailansicht im Backend); "Total ausgegeben" enthält die bezahlten Runden.
    **Annahme:** erhaltene Biere erhöhen "Total ausgegeben" des Empfängers nicht (er hat nichts bezahlt).
  - Backend `/backend/runden` (Kassier und Admin, wie die Gäste-Schalter): Schalter "Runde spendieren erlauben"
    (Standard an; aus: Karte ausgeblendet, `/konto/runde` 404, bestehende Runden bleiben) und Liste der Runden.
    Der Schalter ist eine eigene Einstellung (`rounds_enabled`) und soll mit der Mandantenfähigkeit in die
    Mandanten-Einstellungen wandern.
    **Annahme:** Wird ein Empfänger gelöscht (nicht deaktiviert), zählt sein Bier danach als "Gäste (eingeladen)".
- Bier verschenken (Entscheid 2026-10-22): Dashboard-Karte "Bier verschenken" (nach "Runde spendieren"). Ein oder
  mehrere aktive Kollegen anhaken (Suchfeld wie bei der Runde), "Biere pro Person" 1–10 (alle bekommen gleich viele),
  freiwillige Nachricht (max. 140 Zeichen); Total live.
  - Gutschrift in CHF zum aktuellen Preis: Schenker Buchung Typ `gift` (Betrag negativ, Anzahl = alle Biere, gleiche
    Limit-Regel wie Abstreichen, gesperrt bei beantragter Auszahlung, Einmal-Token), jeder Empfänger eine Buchung `gift`
    mit positivem Betrag (`transactions.parent_id` = Buchung des Schenkers). Steigt der Preis später, reicht die
    Gutschrift für weniger Biere. Kein Bier getrunken: Lager und Kassenstand unverändert; getrunken wird später normal.
  - Schenker kann zurücknehmen (Zeit siehe Einstellungen, Standard 5 Minuten); dabei werden alle Gutschriften
    gelöscht. Entscheid 2026-10-22 (erweitert 1.15.2): nicht mehr möglich, sobald ein Empfänger seit dem Geschenk
    irgendeine Ausgangsbuchung hat (Bier, Runde, selbst verschenkt, Auszahlung auch beantragt, Spende, Korrektur;
    alles mit negativem Betrag). Eingänge (Einzahlung, weiteres Geschenk) zählen nicht. Geprüft unter Sperre der
    Empfänger. Ein zweites Rückgängig (Doppelklick, parallele Anfrage) lädt die Buchung unter Zeilensperre neu,
    scheitert mit Fehlermeldung und schreibt weder einen zweiten Audit-Eintrag noch ein zweites Lager-Rearm
    (gilt auch für Bier, Runde und Bier für Gast).
  - Empfänger sehen 1 Tag lang "🎁 <Schenker> hat dir N Biere geschenkt – <Nachricht>" (max. 3) mit ×-Knopf (wie bei Runden), keine Mail.
  - Statistik: "Verschenkt: N" (Schenker) und "Geschenkt bekommen: N" (Empfänger), auch in der Benutzer-Detailansicht;
    zählen nicht als getrunkene Biere. **Annahme:** "Total ausgegeben" des Schenkers enthält die verschenkten Beträge.
    Unter "Offen" zählt ein ungedecktes Geschenk wie eigene Biere (alter Preis).
  - Backend `/backend/verschenkt` (Kassier und Admin, Reiter "Verschenkt"): Schalter "Bier verschenken erlauben"
    (Standard an, `gifts_enabled`; aus: Karte ausgeblendet, `/konto/verschenken` 404), und Liste der
    Geschenke.
- Lager (`/backend/lager`, Kassier und Admin): Bestand = gekaufte Biere (Kassenbuch "Bierkauf", Feld "Anzahl Biere"
  Pflicht; alte Einträge ohne Anzahl zählen 0) + Inventur-Korrekturen (gezählter Bestand, gespeichert als Differenz
  mit Bemerkung, wer, wann) − bestätigte abgestrichene Biere inkl. spendierter Runden (Rückgängig erhöht den
  Bestand wieder).
  Bestand im Backend-Dashboard und im Kassenbuch. Mindestbestand (Standard 20, 0 = aus): fällt der Bestand durch
  Abstreichen darunter, geht einmal eine Mail an Kassiers/Admins (Benachrichtigungs-Schalter gilt); erneut erst,
  nachdem der Bestand wieder ≥ Mindestbestand war. Warnung im Backend, solange er darunter ist.
  Abstreichen durch Benutzer wird bei Bestand 0 nicht blockiert; negativer Bestand wird so angezeigt.
  Gäste-Verkäufe (alle Wege) ebenfalls nie blockiert (Entscheid 2026-10-22).
- Statistik `/statistik`: Biere total, pro Monat, pro Wochentag, Rangliste (Diagramme als SVG vom Server).
  **Annahme:** Wer "In Statistik anzeigen" ausschaltet, wird für andere nirgends namentlich gezeigt
  (auch nicht in der Rangliste im Backend); die Biere zählen nur in den anonymen Totals.
  Kassiers sehen in der Benutzerverwaltung trotzdem Saldo und Buchungen aller Konten.
- Einzahlungsvorschläge (`/backend/einzahlungsvorschlaege`, Kassier und Admin): eigene CHF-Beträge
  (z. B. "30, 60, 90", max. 6, aufsteigend, je durch den Bierpreis teilbar) oder automatisch: kleinstes
  Vielfaches des Bierpreises, das durch CHF 10 teilbar ist, dann 2×, 3×, 5× (bei CHF 1.50: 30/60/90/150).
  Passt ein gespeicherter Betrag nach einer Preisänderung nicht mehr, wird er auf ganze Biere aufgerundet.
  Bei Minus: Vorschlag = offener Betrag + Betrag ("inkl. Minus"). Der erste Vorschlag ist vorausgewählt.
- Feedback (Entscheid 2026-10-21): jeder angemeldete aktive Benutzer sendet unter `/konto/feedback` (Link in den
  Einstellungen und unten im Dashboard) Feedback: Art "Feedback" (Standard), "Idee" oder "Bug", Nachricht
  5–5000 Zeichen, optional "Antwort per Mail erwünscht" (nur mit hinterlegter E-Mail; die Mail hat dann
  Reply-To = E-Mail des Benutzers). Einmal-Token, max. 5 Feedbacks pro Stunde und Benutzer. Gespeichert in
  `feedback` (mit Herkunftsseite aus dem Referer, User-Agent und App-Version `app_version` aus `VERSION`; in der
  Liste neben der Seite und in der Mail, nur wenn gesetzt). `/backend/feedback` (nur Admins, Menüpunkt mit
  Zahl ungelesener): Liste (neueste zuerst), gelesen/ungelesen, löschen; dort auch die Empfänger der Mail:
  "Alle Admins" (Standard, aktive Admins mit E-Mail) oder "Feste E-Mail-Adressen" (1–20, eine pro Zeile oder
  mit Komma getrennt). Die Feedback-Mail geht immer raus, unabhängig vom Schalter "Kassier-Benachrichtigung".
  Scheitert der Versand, wird das geloggt; das Feedback ist trotzdem gespeichert.
- TWINT: Satz "Bitte z.B. CHF x per TWINT an A, B oder C senden." (Betrag = Einzahlungs-Vorschlag inkl. Minus),
  Nummern nicht angezeigt, nur Knöpfe "Nummer kopieren"; es gibt keinen öffentlichen TWINT-Deep-Link für
  Zahlungen an Privatpersonen.
- Live-Aktualisierung (Entscheid 2026-10-23): alle Seiten für angemeldete Benutzer (Konto, Kassier, Backend,
  Statistik) bleiben ohne Neuladen aktuell (Saldo, Biere, Badges, offene Posten, Lager usw.). Keine Websockets:
  `public/js/live.js` fragt alle 10 Sekunden `/live` ab (JSON `fp`, `App\Support\LiveFingerprint`: eine Abfrage mit
  MAX(id)/COUNT/MAX(updated_at) der relevanten Tabellen, global für alle). Nur wenn sich `fp` geändert hat (oder alle
  5 Minuten), wird die aktuelle Seite mit Header `X-Live-Refresh` neu geladen und die geänderten direkten Kinder von
  `<main>` sowie die Kopfzeile ausgetauscht (Formulare danach neu verdrahtet via `window.BierkasseUi.init`).
  Pausiert, solange der Tab verborgen ist; beim Zurückkehren sofort. Nie ausgetauscht wird ein Teil mit Fokus in
  einem Feld, mit geänderten Feldern, mit gerade abgeschicktem Formular oder ungespeicherter Sortierung; offene
  `<details>`, Scroll-Position und Meldungen (`data-live-keep`) bleiben. Einmal-Tokens nicht ausgetauschter Formulare
  werden aktualisiert. Aus bei Seiten nach POST, mit Validierungsfehlern/alten Eingaben oder einmalig gezeigten
  Geheimnissen (Wiederherstellungscodes, Wartungsausgabe) sowie per `@section('live_off')`. Abgemeldet: `/live` und
  `X-Live-Refresh`-Anfragen liefern 401 statt Weiterleitung, dann (wie bei 503/Weiterleitung) einmal neu laden.
  Entscheid 2026-10-24: Die Abfrage hielte die Session eines sichtbaren Tabs ewig am Leben. Darum stoppt sie (samt
  Neuladen der Seite) nach 30 Minuten ohne Benutzeraktivität (Konstante `IDLE_LIMIT` in `public/js/live.js`;
  Aktivität = echtes pointerdown, keydown, touchstart, wheel, scroll, input). Danach läuft die Session normal ab
  (`SESSION_LIFETIME`). Bei der nächsten Aktivität wird sofort wieder abgefragt; liefert der Server 401, wird neu
  geladen (Login). Der Ablauf der Rückgängig-Knöpfe läuft unabhängig davon weiter.
- Sicherheit: CSRF, CSP mit Nonce (keine Inline-Styles/-Handler), Rollen-Middleware, verschlüsselte
  Geheimnisse (Registrierungspasswort, SMTP-Passwort, SSO-Client-Secrets, TOTP), Rate-Limits auf Login,
  TOTP, Registrierung und Passwort-Reset.
- App-Icons: `public/logo.svg`, `favicon.svg`, `favicon.ico`, `icon-192/512.png`, `icon-maskable-192/512.png`,
  `apple-touch-icon.png` (statisch, neu erzeugbar mit `php resources/icons/generate.php public`).
  Manifest unter `/manifest.webmanifest`, Service Worker `public/sw.js` (nur Netzwerk, kein Cache).

## Protokoll (Audit-Log)

Entscheid 2026-10-22: lückenloses Protokoll aller Änderungen und Buchungen; Einträge werden **nie** gelöscht
(keine Aufbewahrungsfrist, keine Lösch-/Änderungsroute, keine Aktion im UI, auch nicht für Admins oder in der Wartung).

- Tabelle `audit_logs`: Zeit (UTC, Text), Person (`actor_id` mit Fremdschlüssel, dazu `actor_ref`/`actor_name` als
  Schnappschuss), Aktion (z. B. `consume`, `consume.undo`, `round.create`, `deposit.report/confirm/reject/edit/withdraw`,
  `consume.storno`, `round.storno`, `gift.storno`, `correction.create`, `payout.*`, `carryover.*`, `guest_sale.*`,
  `cash_entry.create/delete`, `stock.correct`, `legal.update`, `consent.accept`, `user.lock/unlock`, `auth.login_locked`,
  `settings.update` (geänderte Schlüssel), `user.role/status/limit/password_reset/totp_reset/admin_only/...`,
  `auth.login`, `auth.login_failed` (nur versuchter Benutzername), `auth.logout`, `registration`,
  `invitation.create/revoke`, `maintenance.*`, `backup.*`, `feedback.delete`, `sso_provider.*`), Objekt (Typ, ID),
  alte/neue Werte (nur geänderte Felder), IP, gekürzter User-Agent.
- Geheimnisse werden nie gespeichert: Passwörter, Secrets, Tokens, TOTP, Client-Secrets, SMTP-Passwort,
  Registrierungs-/Gäste-Passwort, Flyer-Code erscheinen als `***changed***`.
- Geschrieben mit `App\Services\Audit::log()` in derselben DB-Transaktion wie die Änderung (beides oder nichts).
  Ausnahmen ohne Transaktion (Anmeldung, Abmeldung, Wartungsaktionen): Fehler beim Protokollieren werden gemeldet,
  brechen die Aktion aber nicht ab.
- Hash-Kette: `hash = sha256(JSON aus prev_hash und allen Feldern)`. Der Kopf der Kette (letzter Hash, Anzahl)
  steht in `audit_chain` und wird beim Anhängen gesperrt (keine Verzweigung bei gleichzeitigen Schreibern).
  Das Modell `AuditLog` verweigert Ändern und Löschen (auch Massen-Updates über Eloquent). Wer direkten
  Datenbankzugriff hat, kann Zeilen trotzdem ändern oder löschen; die Prüfung zeigt das an.
  **Annahme:** `actor_id` ist nicht Teil des Hashs (der Fremdschlüssel setzt ihn beim Löschen eines Benutzers auf
  null), `actor_ref` schon.
- Backend `/backend/protokoll` (Kassier und Admin, nur lesen): neueste zuerst, Filter Person, Aktion (Präfix, z. B.
  `deposit`), Datum von/bis, Freitext; Detailansicht mit alten/neuen Werten und Hashes; "Integrität prüfen"
  prüft die ganze Kette und nennt den ersten fehlerhaften Eintrag; CSV-Export (mit Filtern).
- Anzeige (nur Darstellung, gespeicherte Daten/Hash/CSV unverändert; `App\Support\AuditPresenter`): Aktion als
  deutscher Text mit Code daneben (`lang/de/audit.php` `actions`, unbekannt = Code), Objekt mit Namen (pro Seite
  gesammelt geladen; gelöscht = "Typ #ID"), Spalte "Details" (Kurzfassung, max. 80 Zeichen). Detail: deutsche
  Feldnamen (`fields`, unbekannt = Schlüssel), Beträge als CHF, ja/nein, "–", Datum lokal, Benutzer-IDs als Namen.
  **Annahme:** Zeiten ohne Zeitzone in den Werten stammen von Laravel in `APP_TIMEZONE`. Neue Aktionen/Felder in
  `lang/de/audit.php` ergänzen.
- Backups (`db:backup`, Download in der Wartung) enthalten `audit_logs` und `audit_chain`; `db:restore` stellt beide
  wieder her und hängt danach `backup.restore` an. Einträge nach dem Backup-Zeitpunkt sind nach einer
  Wiederherstellung weg (nur im neueren Backup).
- Mandantenfähigkeit (später): `audit_logs` bekommt `tenant_id`; Mandanten-Admins sehen nur ihren Mandanten,
  der Superadmin alle. Die Hash-Kette bleibt eine Kette über die ganze Installation.

## Wartung (Admin)

`/backend/wartung` (nur Admins, Menüpunkt "Wartung" im Backend). Jede Aktion fragt nach und wird mit dem
Benutzernamen des Admins protokolliert (`Log::info`, `storage/logs`).

- System: App-Version aus der Datei `VERSION` (Semver, wird mit jeder Änderung erhöht: Minor = Funktion,
  Patch = Korrektur; "installiert" = Dateidatum beim Deploy), zusätzlich falls `.git` lesbar (Commit-Kurzhash, Branch und Datum, gelesen aus `.git/HEAD`, Refs bzw. `packed-refs`
  ohne Shell; Datum aus dem Commit-Objekt, sonst Dateidatum der Ref; ohne lesbares `.git` "unbekannt"),
  PHP, Laravel, Datenbank (Treiber, Verbindung), ausstehende Migrationen, Cache/Sperren/Session/Queue/Mail,
  Schreibrechte `storage/` und `bootstrap/cache/`, `APP_ENV`/`APP_DEBUG` (Warnung bei Debug in Produktion),
  zwischengespeicherte Konfiguration/Routen, freier Speicher.
- "Cache leeren": `cache:clear` plus Sperren-Store; hebt damit auch gesperrte Anmelde-/Registrierungsversuche auf.
- "Nur Sperren aufheben": die Rate-Limits liegen in einem eigenen Cache-Store `limiter`
  (`config/cache.php`, Datei-Cache unter `storage/framework/cache/limiter`), der allein geleert wird.
  `CACHE_LIMITER_DRIVER` (Standard `file`) bzw. `CACHE_LIMITER_STORE` (Standard `limiter`) ändern das; zeigt
  `CACHE_LIMITER_STORE` auf den Anwendungs-Cache, leert die Aktion diesen ganz.
- "Optimierungs-Cache leeren": `optimize:clear` (Konfiguration, Routen, Events, Views, Anwendungs-Cache);
  der laufende Request behält seine geladene Konfiguration, der nächste liest `.env` neu.
- "Datenbank migrieren": zeigt ausstehende Migrationen, führt `migrate --force` aus, Ausgabe wird angezeigt.
- Wartungsmodus (Schalter + optionale Mitteilung, gespeichert in `settings`; nicht `artisan down`): nur Admins
  können die App benutzen und sehen oben den Hinweis "Wartungsmodus aktiv". Alle anderen (Gäste, Benutzer,
  Kassiers) erhalten die Seite "Die Bierkasse wird gerade gewartet" (HTTP 503) mit Mitteilung; angemeldete
  Nicht-Admins werden abgemeldet, auch direkt nach der Anmeldung. Erreichbar bleiben Anmeldung (inkl. TOTP, SSO,
  eigener Anbieter), Passwort-Reset, Manifest, `sw.js`, `/setup`, `/up` und statische Dateien.
  **Annahme:** Passwort-Reset bleibt offen, damit ein Admin sich auch ohne Passwort wieder anmelden kann.
- "Datenbank-Backup": "Backup herunterladen" erstellt einen SQL-Dump und streamt ihn direkt als
  `bierkasse-JJJJ-MM-TT-HHMMSS.sql.gz` (nichts wird auf dem Server gespeichert). Darunter die Liste der mit
  `db:backup` erstellten Backups in `storage/app/backups` (Grösse, Datum) zum Herunterladen und Löschen. Nur
  Dateinamen nach diesem Muster werden ausgeliefert (kein Pfad, keine anderen Dateien); `storage/app` ist nicht
  öffentlich.

### Datenbank-Backup und Wiederherstellung

Reines PHP (kein `mysqldump`/`mysql` nötig, z. B. chroot-Shell auf Plesk). Unterstützt MySQL/MariaDB und SQLite;
andere Treiber brechen mit Fehlermeldung ab.

```
php artisan db:backup                 # storage/app/backups/bierkasse-JJJJ-MM-TT-HHMMSS.sql.gz
php artisan db:backup --keep=10       # danach nur die 10 neusten Backups in diesem Ordner behalten
php artisan db:backup --path=/pfad/   # anderer Ordner (oder Dateiname)
php artisan db:restore datei.sql.gz   # fragt nach; --force ohne Nachfrage (z. B. Skripte)
```

- **Das Backup enthält alle Daten dieser Installation**, auch Passwort-Hashes, verschlüsselte Einstellungen und
  verschlüsselte TOTP-Geheimnisse. Sicher aufbewahren.
- Verschlüsselte Werte lassen sich nach einer Wiederherstellung nur mit demselben `APP_KEY` entschlüsseln:
  `.env` (bzw. `APP_KEY`) separat sichern.
- `db:restore` löscht und ersetzt alle Tabellen, die im Dump enthalten sind (andere Tabellen bleiben). Vorher wird
  geprüft, ob Treiber passt und der Dump vollständig ist (Endmarke `-- end of dump`). Bei MySQL/MariaDB ist die
  Wiederherstellung nicht atomar (DDL), bricht sie ab, ist die Datenbank teilweise wiederhergestellt.
  `--connection=` wählt eine andere Verbindung aus `config/database.php`.
- Mit MySQL-Client geht es auch ohne PHP: `gunzip -c bierkasse-….sql.gz | mysql -u BENUTZER -p DATENBANK`.
- Format: jede Anweisung endet am Zeilenende mit `;`, Zeilenumbrüche in Werten sind maskiert (MySQL `\n`,
  SQLite `CAST(X'…' AS TEXT)`), ungültiges UTF-8 als Hex. TIMESTAMP-Werte in UTC (`TIME_ZONE='+00:00'`).
  Bei MySQL nur Basistabellen (keine Views/Trigger/Routinen).
- Regelmässig per Plesk-Cronjob, z. B. täglich `php artisan db:backup --keep=14`.
- **Entscheid 2026-10-21:** vorerst Backup der ganzen Datenbank. Mit Mandantenfähigkeit wird das Backup im Backend
  pro Mandant erstellt.

## Tests

```
php artisan test
```

Die Tests laufen mit SQLite im Speicher (`phpunit.xml`).
