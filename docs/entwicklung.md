# Entwicklung und Funktionen

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
  Admins zusätzlich "Admin" (Einstellungen, Feedback mit Zahl ungelesener, SSO, Navigation, Wartung; bis 1.19 "Backend").
  Die Zahl offener Posten (Einzahlungen, Auszahlungen, Überträge; `Ledger::pendingCount()`) steht zusätzlich als Badge am Reiter
  "Übersicht" (dort werden sie bestätigt); bei 0 ausgeblendet. Mobil scrollt die Seite nie seitlich: Kopfzeile (Logo, Navigation, Benutzer, Abmelden) steht ab Telefonbreite (bis 520 px, Name
  ausgeblendet, enge Abstände) in einer Zeile (Logo links, alle übrigen Einträge rechtsbündig gruppiert, für alle Rollen) und bricht erst unter 360 px um,
  nur die Reiterleiste scrollt/wischt in ihrem eigenen Container, Tabellen in `.table-wrap`.
  So sieht der Admin unter "Kassier" dieselben Seiten wie ein Kassier (plus Rolle/Nur-Verwaltung in der Benutzer-Detailansicht).
  Reihenfolge der Reiter (`App\Support\BackendNav`): Admin legt den Standard unter Admin → Navigation fest
  (Setting `nav_order`), jeder Kassier/Admin kann sie in den Konto-Einstellungen für sich ändern (`users.nav_order`,
  Kassier nur die Gruppe "Kassier"); "Standard wiederherstellen" auf beiden Seiten. Ziehen oder ↑/↓-Knöpfe.
  Neue Reiter erscheinen hinten an, unbekannte werden ignoriert. Gespeicherte Reihenfolgen von vor 1.20.0: "Runden",
  "Gäste" und "Verschenkt" werden zu "Buchungen" (an der Stelle des ersten davon), andere entfernte Reiter fallen weg,
  übrige Wünsche bleiben (`BackendNav::MERGED`, `normalize()`). Der interne Gruppenschlüssel der Admin-Leiste bleibt `backend`.
  Kassier und Admin brauchen eine E-Mail: Pflicht im Setup, im eigenen Profil nicht leerbar, Rolle kassier/admin
  nur vergebbar mit E-Mail; bestehende Konten ohne E-Mail sehen einen Hinweis (Zugriff bleibt).
- Anmeldung `/`: Benutzername oder E-Mail (nur wenn eindeutig) + Passwort, optional TOTP (im Konto einrichten, Wiederherstellungscodes).
  Auch nach einer SSO-Anmeldung wird TOTP verlangt, wenn der Benutzer es eingerichtet hat.
  Passwort vergessen per Mail (nur mit hinterlegter E-Mail; die E-Mail ist pro Konto eindeutig).
  Kassier/Admin können Passwörter setzen ("muss bei nächster Anmeldung geändert werden") und TOTP zurücksetzen.
  Kassiers können Admin-Konten nicht ändern (Passwort, TOTP, Status).
  Deaktivieren geht nur mit Saldo 0 und ohne offene Posten (sonst "Haus verlassen" oder Korrektur);
  der letzte aktive Admin kann nicht deaktiviert werden. Auf ausgetretene Konten wird nichts mehr gebucht.
- Darstellung pro Benutzer (Entscheid 2026-10-25, v1.24.0): `users.theme` (null = automatisch, `light`, `dark`), Konto > Einstellungen,
  Karte "Profil" (Optionen Automatisch/Hell/Dunkel). Fix gewählt: `data-theme` auf `<html>` (Layout `app`) und eine einzelne
  `theme-color`-Meta; automatisch: wie bisher `prefers-color-scheme`. Anmelde-/Registrierungsseiten und Gast-Seiten bleiben
  automatisch (kein Benutzer). Kein Audit-Eintrag (nur Anzeigeeinstellung, wird getrennt vom Profil gespeichert). Mails unverändert.
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
- Interner Hinweis (Entscheid 2026-10-24): Kassier und Admin tragen in der Benutzer-Detailansicht pro Benutzer einen
  einzeiligen Hinweis ein (max. 100 Zeichen, z.B. wer hinter einem nichtssagenden Benutzernamen steckt). Verschlüsselt
  gespeichert (`users.staff_note`), in der Benutzerliste unter dem Namen und in der Kassier-Übersicht bei offenen TWINT-Einzahlungen, Änderungen im Protokoll (`user.note`, alt/neu).
  Nie im Frontend (Konto, Mails, Gästeseiten), im Model `$hidden`. Automatisch (beim Anlegen und bei jeder Namensänderung,
  `User::applyNameToStaffNote()`; bestehende Konten per Migration): leerer Hinweis → "Vorname Name"; beginnt der neue
  Name mit dem Hinweis (z.B. "Hans" → "Hans Muster"), wird ergänzt. Von Hand geänderte Hinweise, ein komplett neuer
  Name oder ein gelöschter Name lassen den Hinweis stehen. In Listen ausgeblendet, wenn gleich wie der angezeigte Name.
  Bleibt nach dem Austritt erhalten (Entscheid 2026-10-24).
- Admin > Einstellungen: jeder Kasten hat einen eigenen Speichern-Knopf (speichert das ganze Formular, Rücksprung zum
  Kasten); Enter in einem Eingabefeld speichert dort nicht (`data-no-enter-submit`).
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
  der Benutzer-Detailansicht im Admin-Bereich (auch für sich selbst). Umschalten (ein und aus) nur mit Saldo 0 und ohne offene Posten, sonst Schalter gesperrt mit Saldo-Hinweis
  ("bitte zuerst den Saldo ausgleichen"); Rolle auf `user` setzt es zurück; Registrierung/Einladung/Setup setzen es
  nie (nicht fillable).
  Wirkung: `/konto` leitet in den Admin-Bereich, Anmeldung und Logo führen in den Admin-Bereich, Link "Konto" ausgeblendet. Alle
  Geld-/Bier-Aktionen sind serverseitig gesperrt (Middleware `beer`: Seiten → Admin-Bereich mit Hinweis, Aktionen 403;
  zusätzlich im `Ledger`): Abstreichen, Rückgängig, Bier für Gast, Runde, Einzahlung melden/ändern/zurückziehen,
  Verlauf, Haus verlassen. Im Admin-Bereich keine Einzahlung/Korrektur/Limit auf das Konto. Bleibt: Einstellungen
  (Passwort, TOTP, SSO, E-Mail), Feedback, Einladen, Statistik-Seite, Admin-Bereich; Kassier-/Feedback-Mails; zählt als
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
  andere, nicht in `/admin/sso` (in der Benutzer-Detailansicht nur Name/Host/Status). Eine Identität eines
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
  Buchen wie bisher). Admin-Bereich (Kassenstand, Guthaben aller Benutzer, Überschuss, Saldo in der Benutzerverwaltung,
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
    Guthaben positiv); zählt für den Benutzer sofort (verfügbarer Saldo, siehe oben), im Admin-Bereich erst nach
    Bestätigung. Kassier/Admin bestätigen (Betrag anpassbar; dann
    wird die Bier-Anzahl verworfen und der gemeldete Wert in der Bemerkung festgehalten) oder lehnen ab in der
    Kassier-Übersicht (`/admin`); zählt bei den offenen Posten (Badge). Erwähnt in der Registrierungs-Mail. Der Benutzer sieht
    ihn in der Liste "Wartet auf Bestätigung" und kann ihn nicht ändern; solange offen, ist "Haus verlassen" gesperrt.
    Übertrag-Biere ändern weder Lager noch Statistik (vor der App getrunken); bestätigte Übertrag-Schulden in
    Bieren erscheinen unter "Offen" als Biere. **Annahme:** der Übertrag ändert den Kassenstand nicht, aber das
    Guthaben aller Benutzer und damit den Überschuss.
    Admin-Schalter unter Einstellungen > Funktionen (Setting `carryover_enabled`, Standard an): aus = Feld in der Registrierung ausgeblendet, Server ignoriert die Eingabe, offene Überträge bleiben bestätigbar/ablehnbar.
- Kasse (`/admin/kasse`): Kassenstand = bestätigte Einzahlungen − Auszahlungen − Ausgaben + Einnahmen ±
  Korrekturen, getrennt nach Bar und TWINT. Überschuss = Kassenstand − Guthaben aller Benutzer.
  Reiter "Kassenbuch" | "Einzahlungsvorschläge" (`?tab=vorschlaege`, Livewire, siehe unten).
- Buchungen (Entscheid 2026-10-24, v1.20.0, `/admin/buchungen`, Kassier und Admin, Livewire): Reiter "Runden" |
  "Geschenke" | "Gäste" (`?tab=runden|geschenke|gaeste`) mit den Listen der Runden, der verschenkten Biere und aller
  Gäste-Verkäufe (Storno der Gäste-Verkäufe hier), je 30 pro Seite, mit dem Zustand der zugehörigen Schalter.
  Alte Adressen `/admin/runden`, `/admin/verschenkt`, `/admin/gaeste` leiten auf den passenden Reiter um.
- Funktionen (Entscheid 2026-10-24, v1.20.0): Admin > Einstellungen, Karte "Funktionen" (eigenes Formular,
  `PUT /admin/einstellungen/funktionen`, **nur Admin**, vorher durften Kassiers Runden, Verschenken und "Gäste durch
  Benutzer buchen" schalten): "Runde spendieren erlauben", "Bier verschenken erlauben", "Gäste durch Benutzer buchen",
  "Öffentliche Gäste-Seite (QR + Gäste-Passwort)" mit Gäste-Passwort und "Kassen-QR aktiv". **Annahme:** "Kassen-QR
  aktiv" zählt zu den Gäste-Schaltern und ist mitgewandert (stand im selben Formular wie Gäste-Seite und Passwort).
  Speichern legt fehlende erste QR-Codes an (Admin-Pfad, wie bisher beim Speichern der QR-Schalter).
- Gäste (Liste unter Buchungen > Gäste, Kassier und Admin; alle Schalter und das Gäste-Passwort nur Admin unter
  Einstellungen > Funktionen): Liste aller Gäste-Verkäufe ("gebucht von <Benutzer>"
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
    Registrierungspasswort; zum Einschalten Pflicht). Druckbarer Gäste-QR-Code (`/admin/gaeste/qr`, erreichbar über QR-Codes): enthält nie das
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
    Code = 404 (10 Fehlversuche/Minute pro IP). Unter QR-Codes > Kassen-QR (`/admin/qr-codes/kassen-qr`) pro aktivem Code Vorschau, PNG (945 x 1299 px = 300 dpi, im Browser
    gerendert) und SVG (80 x 110 mm, `app/Support/CashboxQrSvg.php`, `/admin/gaeste/kassen-qr/<id>`) sowie A4-Bogen
    mit 4 Aufklebern dieses Codes (`…/<id>/a4`); dazu "2 × Bar + 2 × TWINT" mit dem jeweils neuesten Code
    (`/admin/gaeste/kassen-qr-a4/gemischt`). A4: 2 x 2, 10 mm Abstand, gestrichelte Schnittlinien (Rand 20 mm
    seitlich, 33.5 mm oben/unten; **Annahme** 2026-10-22), je Öffnen/Drucken, PNG (A4 300 dpi) und SVG. Drucken mit 100 %.
- Dashboard-Karten (Entscheid 2026-10-24): "Einzahlen" (Einzahlung melden + TWINT-Empfänger/Nummer, TWINT nur wenn aktiv; "Wartet auf
  Bestätigung" darunter) und "Meine Buchungen & Statistik" (Kennzahlen, Diagramm, letzte Buchungen) sind je eine Karte.
- Fusszeile auf allen Seiten (auch Anmeldung, Gäste-Seiten): Zeile 1 "<App-Name> v<Version> · <Copyright>",
  Zeile 2 "Datenschutz · Nutzungsbestimmungen" (Links); jeder Teil bricht nur als Ganzes um (Entscheid 2026-10-24). Version aus `VERSION`;
  Copyright in den Einstellungen (max. 100 Zeichen, Text), Standard "© {startjahr}–{jahr} Stefan Diethelm" (gleiche Jahre = ein Jahr), leer = ohne Copyright.
  Platzhalter: `{jahr}` = aktuelles Jahr, `{startjahr}` = Jahr der Installation (**Annahme**: Jahr des ersten Kontos, ohne Konto
  aktuelles Jahr); "{startjahr}–{jahr}" zeigt bei gleichen Jahren nur ein Jahr. Gespeicherte Texte werden nicht migriert.
  Nicht im Druck.
- Datenschutz und Nutzungsbestimmungen (Entscheid 2026-10-24): zwei getrennte Texte, öffentlich unter `/datenschutz` und
  `/nutzungsbestimmungen` (abgemeldet und angemeldet, auch im Wartungsmodus und ohne Zustimmung), oben "Stand: <Datum>".
  - Bearbeiten: Admin > Einstellungen, Karte "Datenschutz und Nutzungsbestimmungen" (nur Admin, eigenes Formular,
    `PUT /admin/einstellungen/rechtliches`, Audit `legal.update` mit altem/neuem Text). Settings `legal_name`
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
  - Benutzer-Detailansicht im Admin-Bereich (Karte "Zustimmung", `data-testid="consent-info"`, Entscheid 2026-10-25, v1.24.1): "Akzeptiert am <Datum>"
    (+ "(noch offen)" bei neuer Fassung), darunter pro Text eine Zeile "Datenschutzerklärung · Fassung ansehen" mit Link auf die
    akzeptierte Fassung (Datum der Fassung steht dort; Zustimmungen vor 1.19.0 ohne Fassung: "· Stand X") bzw. "noch offen";
    Link "Alle Fassungen" als letzte Zeile. Partial `legal/accepted.blade.php`, auch in Konto > Einstellungen.
  - Fassungen-Archiv (Entscheid 2026-10-24, v1.19.0): Tabelle `legal_revisions` (Art, Stand, Text, sha256, Anlass,
    erstellt von/am). Gespeichert wird der gerenderte Quelltext (Platzhalter eingesetzt, kein HTML); angezeigt mit
    demselben sicheren Renderer, ohne erneutes Einsetzen von Platzhaltern. Unveränderlich: keine Route ändert oder
    löscht, das Modell verweigert Update/Delete.
    - Neue Fassung, sobald Text-Hash oder Stand einer Art von der neuesten Fassung abweicht (gleicher Text mit
      "Erneut zustimmen" = neue Fassung mit neuem Stand). Anlass: `reaccept`/`update`/`silent` (Speichern in den Einstellungen,
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
      Protokoll > Reiter "Fassungen" (`/admin/protokoll?tab=fassungen`, seit 1.20.0; `/admin/rechtliches/fassungen` leitet
      um; Kassier und Admin, nur lesen, 25 pro Seite, Filter nach Text; Link in der
      Karte "Datenschutz und Nutzungsbestimmungen" und in der Benutzer-Detailansicht). Konto > Einstellungen:
      "Akzeptiert am <Datum>", darunter pro Text "· Fassung ansehen" (Link auf die eigene akzeptierte Fassung).
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
    Statistik (auch in der Benutzer-Detailansicht im Admin-Bereich); "Total ausgegeben" enthält die bezahlten Runden.
    **Annahme:** erhaltene Biere erhöhen "Total ausgegeben" des Empfängers nicht (er hat nichts bezahlt).
  - Admin-Bereich: Liste der Runden unter Buchungen > Runden (Kassier und Admin); Schalter "Runde spendieren erlauben"
    unter Einstellungen > Funktionen (nur Admin, seit 1.20.0; Standard an; aus: Karte ausgeblendet, `/konto/runde` 404,
    bestehende Runden bleiben).
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
  - Admin-Bereich: Liste der Geschenke unter Buchungen > Geschenke (Kassier und Admin); Schalter "Bier verschenken
    erlauben" unter Einstellungen > Funktionen (nur Admin, seit 1.20.0; Standard an, `gifts_enabled`; aus: Karte
    ausgeblendet, `/konto/verschenken` 404).
- Lager (`/admin/lager`, Kassier und Admin): Bestand = gekaufte Biere (Kassenbuch "Bierkauf", Feld "Anzahl Biere"
  Pflicht; alte Einträge ohne Anzahl zählen 0) + Inventur-Korrekturen (gezählter Bestand, gespeichert als Differenz
  mit Bemerkung, wer, wann) − bestätigte abgestrichene Biere inkl. spendierter Runden (Rückgängig erhöht den
  Bestand wieder).
  Bestand in der Kassier-Übersicht (`/admin`) und im Kassenbuch. Mindestbestand (Standard 20, 0 = aus): fällt der Bestand durch
  Abstreichen darunter, geht einmal eine Mail an Kassiers/Admins (Benachrichtigungs-Schalter gilt); erneut erst,
  nachdem der Bestand wieder ≥ Mindestbestand war. Warnung im Admin-Bereich, solange er darunter ist.
  Abstreichen durch Benutzer wird bei Bestand 0 nicht blockiert; negativer Bestand wird so angezeigt.
  Gäste-Verkäufe (alle Wege) ebenfalls nie blockiert (Entscheid 2026-10-22).
- Statistik `/statistik`: Biere total, pro Monat, pro Wochentag, Rangliste (Diagramme als SVG vom Server).
  **Annahme:** Wer "In Statistik anzeigen" ausschaltet, wird für andere nirgends namentlich gezeigt
  (auch nicht in der Rangliste im Admin-Bereich); die Biere zählen nur in den anonymen Totals.
  Kassiers sehen in der Benutzerverwaltung trotzdem Saldo und Buchungen aller Konten.
- Einzahlungsvorschläge (Kasse > Reiter "Einzahlungsvorschläge", `/admin/kasse?tab=vorschlaege`, Kassier und Admin,
  Livewire; `/admin/einzahlungsvorschlaege` leitet um): eigene CHF-Beträge
  (z. B. "30, 60, 90", max. 6, aufsteigend, je durch den Bierpreis teilbar) oder automatisch: kleinstes
  Vielfaches des Bierpreises, das durch CHF 10 teilbar ist, dann 2×, 3×, 5× (bei CHF 1.50: 30/60/90/150).
  Passt ein gespeicherter Betrag nach einer Preisänderung nicht mehr, wird er auf ganze Biere aufgerundet.
  Bei Minus: Vorschlag = offener Betrag + Betrag ("inkl. Minus"). Der erste Vorschlag ist vorausgewählt.
- Feedback (Entscheid 2026-10-21): jeder angemeldete aktive Benutzer sendet unter `/konto/feedback` (Link in den
  Einstellungen und unten im Dashboard) Feedback: Art "Feedback" (Standard), "Idee" oder "Bug", Nachricht
  5–5000 Zeichen, optional "Antwort per Mail erwünscht" (nur mit hinterlegter E-Mail; die Mail hat dann
  Reply-To = E-Mail des Benutzers). Einmal-Token, max. 5 Feedbacks pro Stunde und Benutzer. Gespeichert in
  `feedback` (mit Herkunftsseite aus dem Referer, User-Agent und App-Version `app_version` aus `VERSION`; in der
  Liste neben der Seite und in der Mail, nur wenn gesetzt). `/admin/feedback` (nur Admins, Menüpunkt mit
  Zahl ungelesener): Liste (neueste zuerst), gelesen/ungelesen, löschen; dort auch die Empfänger der Mail:
  "Alle Admins" (Standard, aktive Admins mit E-Mail) oder "Feste E-Mail-Adressen" (1–20, eine pro Zeile oder
  mit Komma getrennt). Die Feedback-Mail geht immer raus, unabhängig vom Schalter "Kassier-Benachrichtigung".
  Scheitert der Versand, wird das geloggt; das Feedback ist trotzdem gespeichert.
- TWINT: Satz "Bitte z.B. CHF x per TWINT an A, B oder C senden." (Betrag = Einzahlungs-Vorschlag inkl. Minus),
  Nummern nicht angezeigt, nur Knöpfe "Nummer kopieren"; es gibt keinen öffentlichen TWINT-Deep-Link für
  Zahlungen an Privatpersonen.
  TWINT-Mitteilung (Admin > Einstellungen > TWINT, max. 140 Zeichen): Text mit Knopf "Kopieren" zum Einfügen ins
  Feld "Mitteilung" der TWINT-App, im Konto (Einzahlen) und auf den Gästeseiten (Gästeseite, Danke-Seite, Kassen-QR
  TWINT). Platzhalter `{appname}`, `{username}` (Gäste: `gast`); leer = `{appname}: Einzahlung von Username: {username}`.
  Ohne Zwischenablage-Zugriff wird der Text markiert (Antippen markiert ihn ebenfalls). Der Benutzername `gast`
  ist reserviert (Registrierung und Setup, Gross-/Kleinschreibung egal; `UserRules::RESERVED_USERNAMES`).
- Live-Aktualisierung (Entscheid 2026-10-23): alle Seiten für angemeldete Benutzer (Konto, Kassier, Admin,
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
  Seiten mit Livewire-Komponenten (seit 1.20.0, `<body data-livewire-page>`) lädt `live.js` nicht neu; die Komponenten
  aktualisieren ihre Daten selbst mit `wire:poll.10s.visible` (siehe "Livewire"). Die 30-Minuten-Regel gilt dort auch:
  `live.js` bricht danach die Poll-Aktionen ab (`Livewire.interceptAction`), bis wieder Aktivität kommt.
  Kopfzeilen-Badges werden auf Livewire-Seiten erst beim nächsten Seitenaufruf aktualisiert.
- Livewire (Entscheid 2026-10-24, v1.20.0: für neue bzw. umgebaute Seiten, die übrigen später): `livewire/livewire` ^4.4,
  ohne JS-Build (das Paket liefert sein Skript selbst über eine eigene Route aus, nichts zu veröffentlichen).
  Komponenten in `app/Livewire/Backend` (Ansichten `resources/views/livewire/backend`): Buchungen, Benutzer
  (Reiter Benutzer | Einladungen; Detailansicht bleibt klassisch), Kasse > Einzahlungsvorschläge, QR-Codes, Protokoll
  (Reiter Protokoll | Fassungen). Die Seiten selbst sind normale Routen (gleiche Middleware wie bisher), die Komponente
  steckt im Inhalt; Reiter stehen in der URL (`?tab=…`, `#[Url]`), Links funktionieren auch ohne JS.
  - Strikte CSP bleibt unverändert (`script-src 'self' 'nonce-…'`, kein `unsafe-eval`, kein `unsafe-inline`):
    `config/livewire.php` `csp_safe = true` (CSP-Build mit Alpine-CSP-Auswerter, nur einfache Ausdrücke wie
    `showTab('gaeste')`), `inject_assets = false`; das Layout setzt `@livewireStyles`/`@livewireScripts` nur auf Seiten
    mit `@section('livewire')`, beide mit dem Nonce der Anfrage. Kein Fortschrittsbalken (`navigate.show_progress_bar`).
  - Update-Endpunkt `POST /admin/livewire/update` (Route `backend.livewire.update`, in `routes/web.php` per
    `Livewire::setUpdateRoute`): Middleware `web` (Session, CSRF, Wartungsmodus), `auth`, `AuthenticateSession`, `active`
    (Zustimmungs-/Passwort-Sperre), `role:kassier`; der Standard-Endpunkt von Livewire antwortet dann mit 404.
    Zusätzlich als persistente Middleware `EnsureActiveUser` und `RequireRole` (AppServiceProvider).
  - Livewire-Aktionen sind öffentliche Endpunkte: jede Komponente prüft bei jeder Anfrage die Rolle
    (`App\Livewire\Concerns\AuthorizesAdminArea`, Boot-Hook) und Admin-Aktionen zusätzlich `authorizeAdmin()`. Die
    Admin-Formulare auf der QR-Seite senden an die bisherigen `role:admin`-Routen.
  - Ergebnis einer Aktion (z. B. Storno) erscheint in der Komponente; neue Elemente nach einem Update bekommen die
    UI-Helfer von `app.js` (`Livewire.hook('morph.added')`).
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
- Admin-Bereich `/admin/protokoll` (Kassier und Admin, nur lesen; ein Menüpunkt in der Kassier-Leiste, Livewire, Reiter
  "Protokoll" | "Fassungen"; die frühere Admin-Kopie `/admin/verwaltung/protokoll` leitet um): neueste zuerst, Filter Person, Aktion (Präfix, z. B.
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

## Tests

```
php artisan test
```

Livewire-Komponenten werden mit `Livewire::test()` getestet (`tests/Feature/AdminAreaTest.php`: Rechte, Reiter,
Weiterleitungen, CSP/Nonce, Update-Route).

Die Tests laufen mit SQLite im Speicher (`phpunit.xml`).
