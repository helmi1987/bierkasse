# Installation und Betrieb

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
   Ab 1.20.0 braucht die App das Paket `livewire/livewire`: `composer install` muss bei jedem Deploy laufen (erste Zeile
   oben; ohne sie fehlt Livewire und die Admin-Seiten Buchungen, Benutzer, QR-Codes, Protokoll liefern Fehler 500).
   `package:discover` danach ist nötig, damit Laravel den Livewire-Provider findet.
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
7. Nach dem Setup im Admin-Bereich (`/admin/einstellungen`; bis 1.19 `/backend/...`, alte Adressen leiten weiter):
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
     QR-Code zum Aushängen unter `/admin/registrierung-qr` (Kassier und Admin, siehe "QR-Codes" unten), dort auch als druckfertiger A4-Flyer
     (Reiter "Flyer"). Der Flyer ist ein SVG-Bild in exakt A4 (`/admin/registrierung-qr/flyer-bild`, gleicher
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
   - QR-Codes (Entscheid 2026-10-23, gilt für Kassen-QR, Flyer-Codes, Gäste-QR und Registrierungscodes; zusammengelegt
     Entscheid 2026-10-24, v1.20.0): eine Seite "QR-Codes" in der Kassier-Leiste (`/admin/qr-codes`, Livewire). Kassier und
     Admin sehen die Übersicht (alle Arten mit Anzahl aktiver Codes, Link zur Druckseite: Kassen-QR `/admin/qr-codes/kassen-qr`,
     Gäste-QR `/admin/gaeste/qr`, Registrierung/Flyer `/admin/registrierung-qr`; nur aktive Codes anzeigen, drucken,
     herunterladen). Nur Admins sehen darunter "QR-Codes verwalten": pro Art neuer Code (Name freiwillig), umbenennen,
     deaktivieren, Historie mit Reaktivieren; Registrierungscodes erstellen, deaktivieren, reaktivieren (Formulare auf die
     bisherigen Routen mit `role:admin`, Rechte unverändert). Die Schalter "Kassen-QR aktiv", "Öffentliche Gäste-Seite" und
     das Gäste-Passwort stehen unter Admin > Einstellungen > Funktionen. Die frühere Seite "QR-Codes (Verwaltung)"
     (`/admin/verwaltung/qr-codes`) leitet auf `/admin/qr-codes` um. Mehrere
     Codes pro Art können aktiv sein; nichts wird gelöscht, deaktivierte bleiben als Historie (nur Admin sichtbar).
     Erste Codes (Entscheid 1.15.2): Kassier-Seiten (GET) legen nie Codes an und zeigen "Noch kein Code. Ein Admin muss
     einen anlegen." Fehlende erste Codes (Kasse Bar/TWINT, Gäste, Flyer nur im Modus "Offen mit Registrierungspasswort")
     erzeugt nur ein Admin: beim Öffnen der Seite QR-Codes, beim Speichern der Funktionen bzw. der Einstellungen
     (`created_by` = Admin, Audit `qr_code.create`; Sperre + erneute Prüfung gegen doppeltes Anlegen). Ohne Flyer-Code
     fällt der Einladungs-/Flyer-Link auf den normalen Registrierungslink zurück. Aktiv/Inaktiv-Schalter: `active` ist
     Pflicht (boolean); unveränderter Zustand schreibt keinen Audit-Eintrag. Wird der letzte aktive Code einer Art
     deaktiviert, erscheint eine Warnung mit den Folgen (zusätzlich zur Rückfrage am Knopf).
     Tabelle `qr_codes` (Art `cashbox_cash`/`cashbox_twint`/`flyer`/`guest`, Name freiwillig, Code verschlüsselt +
     SHA-256 für die Suche, `deactivated_at`); die früheren Einzel-Codes aus den Settings werden beim Migrieren
     übernommen (gedruckte Aufkleber/Flyer bleiben gültig). Audit: `qr_code.create/label/deactivate/activate`.
   - Alle QR-Codes (`App\Support\Qr`, SVG serverseitig, Fehlerkorrektur H) zeigen in der Mitte das Bierkrug-Logo (`public/logo.svg`,
     weisse Fläche mit Rand, ca. 21 % der Code-Breite) auf Bildschirm, Download und A4-Druck; wird beim Erzeugen eingebettet,
     bestehende Codes bleiben gültig und lesbar (Inhalt unverändert).
   - Reiter-Reihenfolge Standard (Entscheid 2026-10-24, v1.20.0; ersetzt 2026-10-23): Kassier: Übersicht, Kasse, Lager,
     Benutzer, Buchungen, QR-Codes, Protokoll. Admin: Einstellungen, Feedback, SSO, Navigation, Wartung.
     Zusammengelegt bzw. verschoben: "Statistik" (doppelt zu `/statistik` in der Hauptleiste) entfällt, `/admin/statistik`
     leitet auf `/statistik`; "Runden", "Gäste", "Verschenkt" → "Buchungen" (Reiter Runden | Geschenke | Gäste);
     "Einladungen" → Reiter in "Benutzer"; "Vorschläge" → Reiter "Einzahlungsvorschläge" in "Kasse"; "QR-Codes
     (Verwaltung)" und das Admin-"Protokoll" → je ein Menüpunkt in der Kassier-Leiste. Alte Adressen leiten mit 301 auf den
     neuen Reiter um (`App\Http\Controllers\Backend\MovedPageController`, Query-String bleibt).
   - Mailversand: Server-sendmail (Standard, `MAIL_SENDMAIL_PATH`) oder SMTP (Passwort verschlüsselt gespeichert).
     Kassier-Benachrichtigung (neue Einzahlung, Auszahlungsantrag, neue Selbst-Registrierung mit Link zum Benutzer) an alle Kassiers/Admins mit E-Mail.
     Alle Mails (auch Passwort-Reset) nutzen ein gemeinsames HTML-Layout (`resources/views/mail/layout.blade.php`, Inline-CSS, Logo `public/icon-192.png` als CID eingebettet, fehlt es, entfällt das Logo) plus Textversion; Fakten als Tabelle, Link als Button.
   - SSO (`/admin/sso`): Google, Microsoft (Tenant, Standard `common`), GitHub, Facebook oder generisches OIDC
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
     Admin-Bereich: Benutzer > Reiter "Einladungen" (`/admin/benutzer?tab=einladungen`, Kassier und Admin, Livewire; alte
     Adresse `/admin/einladungen` leitet um): alle Einladungen mit Ersteller, Status (offen / benutzt von X / abgelaufen),
     unbenutzte zurückziehen.
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

Unter Admin > SSO "Neu: <Anbieter>" wählen; das Formular zeigt die Redirect-URI
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

## Wartung (Admin)

`/admin/wartung` (nur Admins, Menüpunkt "Wartung" im Admin-Bereich). Jede Aktion fragt nach und wird mit dem
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
- **Entscheid 2026-10-21:** vorerst Backup der ganzen Datenbank. Mit Mandantenfähigkeit wird das Backup im Admin-Bereich
  pro Mandant erstellt.

