# Bierkasse

Digitale Bierkasse fürs Büro: Bier abstreichen, Einzahlungen melden (Bar/TWINT), Kassenbuch und Statistik.
Laravel 13, PHP 8.4, kein JS-Build. Mobile first, Light/Dark-Mode, als App installierbar (PWA).
Die Domain ist frei wählbar; nichts ist fest auf eine Domain eingestellt.

## Funktionen

- Bier mit einem Klick abstreichen, mit Rückgängig-Fenster
- Einzahlung melden (Bar oder TWINT), mehrere TWINT-Empfänger, Zahlungstext zum Kopieren
- Einzahlungsvorschläge mit eigenen CHF-Beträgen
- Rollen: Admin, Kassier und Benutzer
- Kasse mit Kassenbuch (Einzahlungen, Auszahlungen, Ausgaben, Einnahmen)
- Lager mit Bestand und Warnung per Mail
- Offline-Notfallplan: Strichliste zum Ausdrucken für den Ausfall der App, mit Erinnerung per Mail
- Statistik: Biere pro Monat und Wochentag, Rangliste
- Entsorgungsbonus: Leergut melden, Bonus in Bier nach Sperrfrist und Mindestmenge, Rangliste Entsorgungen
- Backend-Statistik für Kassier und Admin mit Filtern und CSV-Export
- Gäste ohne Konto per QR-Kleber (Bar oder TWINT)
- Bier für einen Gast buchen
- Runde spendieren
- Bier verschenken
- Übertrag von der alten Strichliste bei der Registrierung (ein-/ausschaltbar)
- Storno von Buchungen durch Kassier und Admin
- Konto sperren, mit Pflichtgrund
- Interner Hinweis pro Benutzer, nur für Kassier und Admin sichtbar
- Registrierungscodes, QR-Codes und Flyer zum Einladen
- Anmeldung mit Passwort, optional mit TOTP (Zwei-Faktor) und Wiederherstellungscodes
- Anmeldung per SSO (OAuth2/OIDC), auch mit eigenem Anbieter
- Passwort vergessen per Mail
- Datenschutz und Nutzungsbestimmungen mit Zustimmung und archivierten Fassungen
- Feedback an die Admins
- Protokoll (Audit-Log) aller Änderungen und Buchungen
- Datenbank-Backup und Wiederherstellung
- Live-Aktualisierung der Seiten
- Anpassbare Reihenfolge der Reiter
- Installierbar als App (PWA), Light/Dark-Mode (pro Benutzer: Automatisch/Hell/Dunkel)

## Voraussetzungen

- PHP 8.4 (FPM) mit `pdo_mysql`, `mbstring`, `openssl`, `intl`
- Datenbank: MariaDB/MySQL (Plesk), PostgreSQL oder SQLite
- composer

## Dokumentation

- [Installation und Betrieb](docs/installation.md): Plesk, SSO-Anbieter, Wartung, Backup
- [Entwicklung und Funktionen](docs/entwicklung.md): Funktionsdetails, Protokoll, Branch-Modell, Tests

## Releases

| Version | Art | Änderung |
|---|---|---|
| 1.40.1 | Verbesserung | Notfallplan: Sichtfenster-Masse auch mit Komma eingeben (z. B. 4,5) |
| 1.40.0 | Neue Funktion | Admin > Notfallplan: Offline-Notfallplan zum Drucken (A4, doppelseitig, Farbe; Seite 1 mit Sichtfenster für C5-Umschlag, danach Strichliste aller aktiven Benutzer mit leeren Feldern für neue), Testdruck, gespeicherte Ausdrucke mit Differenz neu/ausgetreten, einstellbares Sichtfenster und Texte, wöchentliche Mail-Erinnerung ab einstellbarer Schwelle |
| 1.39.0 | Änderung | Kassier > Entsorgung ist neu ein Bereich unten auf der Seite Lager (Anleitung, Häufige Fragen, Einstellungen, Stand, Erfassen, Liste, Rangliste); Reiter «Entsorgung» entfällt, alte Adresse leitet auf Lager weiter |
| 1.38.2 | Verbesserung | Kassier > Entsorgung: Anleitung immer eingeklappt; neuer klappbarer Bereich «Häufige Fragen» darunter |
| 1.38.1 | Änderung | Ranglisten: Die Nummern von «Anonym n» wechseln bei jedem Aufruf zufällig, damit niemand über die Nummer oder die Position wiedererkannt wird |
| 1.38.0 | Neue Funktion | Bier verschenken und Bier für Gast: Total und Absende-Knopf oben und beim Scrollen sichtbar wie bei der Runde; pro Bereich schaltbar («Knöpfe oben fixieren», Standard ein) |
| 1.37.0 | Änderung | Alte Live-Aktualisierung (live.js-Seitenaustausch, /live) entfernt; alle Live-Seiten sind Livewire-Komponenten |
| 1.36.0 | Neue Funktion | Admin-Seiten (Übersicht, Kasse, Lager, Benutzer-Detail, Entsorgung, Feedback, Statistik) als Livewire-Komponenten mit Live-Badges in der Unternavigation; Einstellungen, Navigation, SSO, Wartung, QR-Seiten und Protokoll-Detail ohne Live-Aktualisierung |
| 1.35.0 | Neue Funktion | Statistik, Verlauf und Konto verlassen als Livewire-Komponenten (Live ohne Springen); Einstellungen, Feedback, Einladen und Rechtliches ohne Live-Aktualisierung |
| 1.34.0 | Neue Funktion | Konto-Dashboard als Livewire-Komponente: Live-Aktualisierung ohne Springen (nur geänderte Teile, Formulare und Eingaben bleiben), Kopfzeilen-Badges live, Leiste «Neue Version» statt Neuladen mitten in der Bedienung |
| 1.33.2 | Änderung | Hilfe-Symbol ⓘ entfernt, Hinweistexte wieder immer sichtbar (Runde-Knöpfe nebeneinander und Neuladen bei neuer CSS/JS bleiben) |
| 1.33.1 | Fehlerbehebung | Hilfe-Symbol ⓘ als blauer Kreis am Ende von Titel/Feld-Label, Runde-Knöpfe nach einem Deploy zuverlässig nebeneinander (Seite lädt bei neuer CSS/JS neu) |
| 1.33.0 | Neue Funktion | Hilfetexte in der Benutzer-Ansicht standardmässig eingeklappt (ⓘ), aufgeklappte Hinweise werden im Konto gemerkt |
| 1.32.2 | Fehlerbehebung | Entsorgungsbonus ausgeschaltet: Schalter «Name in Statistik Entsorgung zeigen» und Kennzahl «Entsorgungen» beim Benutzer ausgeblendet |
| 1.32.1 | Verbesserung | Runde spendieren: Total und beide Knöpfe oben, nebeneinander und beim Scrollen fixiert unter der Kopfzeile |
| 1.32.0 | Neue Funktion | Entsorgungsbonus: Leergut-Meldung mit Sperrfrist, Mindestmenge, verzögerter Buchung (fix oder Zufall), Erfassung durch Kassier/Admin, Rangliste Entsorgungen |
| 1.31.0 | Neue Funktion | Backend-Statistik für Kassier und Admin: Zeitraum (inkl. Quartal), Benutzer, Typ, Gruppierung nach Zeit oder Benutzer, Vergleich Vorperiode, CSV-Export |
| 1.30.0 | Neue Funktion | Statistik mit Zeitraum (Woche, Monat, Jahr, frei) und Vor/Zurück, Wochenansicht, Vergleich Monat/Vormonat/Jahr/Vorjahr, Rangliste pro Zeitraum |
| 1.29.0 | Neue Funktion | Statistik für Benutzer in den Funktionen ein-/ausschaltbar (ganz oder einzelne Teile); Opt-out-Benutzer in der Rangliste als «Anonym n» |
| 1.28.1 | Bugfix | Rangliste mit Sport-Platzierung: gleich viele Biere = gleicher Rang, danach Lücke (1, 1, 3) |
| 1.28.0 | Neue Funktion | Ablaufdatum des Client-Secrets pro SSO-Anbieter mit Banner und Mail-Erinnerungen an Admins (90 bis 0 Tage) |
| 1.27.0 | Verbesserung | SSO-Anbieter: Redirect-URI in der Liste und live beim Anlegen, Reihenfolge der Login-Knöpfe per Pfeile im Backend |
| 1.26.0 | Neue Funktion | Anmeldung mit Apple (Sign in with Apple) als SSO-Anbieter |
| 1.25.2 | Bugfix | Übertrag in Bieren über CHF 500 bei der Registrierung: Feldfehler statt Serverfehler (Issue #86) |
| 1.25.1 | Bugfix | Benutzername bei Registrierung/Einrichtung auch mit Grossbuchstaben eingebbar (wird klein gespeichert) |
| 1.25.0 | Neue Funktion | Übertrag von der alten Strichliste in den Funktionen ein-/ausschaltbar |
| 1.24.1 | Verbesserung | Zustimmung kompakter: "Fassung ansehen" statt Datum, "Alle Fassungen" unten |
| 1.24.0 | Neue Funktion | Darstellung pro Benutzer (Automatisch/Hell/Dunkel); Zustimmung als Karte in der Benutzeransicht |
| 1.23.3 | Doku | README gekürzt, Details nach docs/ verschoben, Releases-Liste |
| 1.23.2 | Verbesserung | Interner Hinweis wird automatisch aus Vor- und Nachname gefüllt |
| 1.23.1 | Verbesserung | Interner Hinweis bei offenen TWINT-Einzahlungen in der Kassier-Übersicht |
| 1.23.0 | Neue Funktion | Interner Hinweis pro Benutzer für Kassier und Admin |
| 1.22.2 | Verbesserung | Jede Einstellungskarte hat einen eigenen Speichern-Knopf |
| 1.22.1 | Verbesserung | Abstand unter der TWINT-Nachricht |
| 1.22.0 | Neue Funktion | Kopierbare TWINT-Nachricht; Benutzername "gast" reserviert |
| 1.21.0 | Verbesserung | Vorläufige Einzahlungen nach abgelehnter Einzahlung zurückgehalten |
| 1.20.3 | Verbesserung | Fusszeile in zwei Zeilen, Teile brechen als Ganzes um |
| 1.20.2 | Verbesserung | Standard-Copyright mit Start- und aktuellem Jahr |
| 1.20.1 | Verbesserung | Platzhalter {jahr} und {startjahr} im Footer-Copyright |
| 1.20.0 | Neue Funktion | Neuer Admin-Bereich unter /admin mit neuem Menü und Livewire-Seiten |
| 1.19.0 | Neue Funktion | Alle veröffentlichten Fassungen von Datenschutz und Nutzungsbestimmungen archiviert |
| 1.18.1 | Bugfix | Kleine Korrekturen an den Rechtshinweisen |
| 1.18.0 | Neue Funktion | Hinweise bei Änderungen der Rechtstexte, Ankündigungsmodi |
| 1.17.0 | Neue Funktion | Datenschutz und Nutzungsbestimmungen mit Zustimmung, Konto sperren, Storno |
| 1.16.0 | Neue Funktion | Fusszeile mit Version und Copyright, lesbares Protokoll |
| 1.15.2 | Sicherheit | Review-Korrekturen: Verschenken-Rückgängig abgesichert, QR-Codes nur durch Admins |
| 1.15.1 | Bugfix | Datumsfelder auf iOS, Infoliste auf Telefonen untereinander |
| 1.15.0 | Neue Funktion | Getrennte Favoriten für Runde und Verschenken |
| 1.14.1 | Bugfix | Mobile Kopfzeile nutzt die volle Breite für alle Rollen |
| 1.14.0 | Verbesserung | Kopfzeile passt auf Telefonen in eine Zeile |
| 1.13.0 | Verbesserung | Live-Aktualisierung stoppt nach 30 Minuten Inaktivität |
| 1.12.0 | Neue Funktion | QR-Codes für Kassier und Admin, Protokoll-Reiter, neue Standard-Reihenfolge |
| 1.11.0 | Neue Funktion | Reiter mit Übersicht aller QR-Code-Arten |
| 1.10.0 | Neue Funktion | QR-Codes mit Verlauf, mehrere aktive pro Art |
| 1.9.1 | Bugfix | Kassen-QR-Seite mit Abbrechen-Knopf |
| 1.9.0 | Neue Funktion | Kassen-QR als PNG und druckbare A4-Bögen mit 4 Klebern |
| 1.8.0 | Neue Funktion | Rückgängig-Fenster pro Buchungsart einstellbar |
| 1.7.0 | Neue Funktion | Biere als Guthaben an Kollegen verschenken |
| 1.6.1 | Bugfix | Gäste-Verkäufe werden nie vom Lager blockiert |
| 1.6.0 | Neue Funktion | QR-Kleber für Gäste ohne Konto, Bar und TWINT |
| 1.5.0 | Neue Funktion | Reihenfolge der Reiter einstellbar, pro Person und als Standard |
| 1.4.0 | Neue Funktion | Mehrfach verwendbare Registrierungscodes für QR und Flyer |
| 1.3.0 | Neue Funktion | Kopfleiste Kassier und Admin, Protokoll für Kassiers, E-Mail Pflicht |
| 1.2.3 | Verbesserung | TWINT-Empfänger als Liste mit "oder" vor dem letzten |
| 1.2.2 | Verbesserung | TWINT: Kopier-Knöpfe statt angezeigter Nummern |
| 1.2.1 | Verbesserung | TWINT-Empfänger im Zahlungssatz hervorgehoben |
| 1.2.0 | Neue Funktion | Mehrere TWINT-Empfänger, Namensvorschläge von Kassier und Admin |
| 1.1.2 | Verbesserung | TWINT-Kasten: Betrag als Beispiel markiert |
| 1.1.1 | Verbesserung | TWINT-Kasten ohne Nummer: ein Satz mit Betrag und Name |
| 1.1.0 | Neue Funktion | TWINT ein-/ausschaltbar, Name statt Nummer möglich |
| 1.0.1 | Verbesserung | Keine Mail mehr an Empfänger bei "Runde spendieren" |
| 1.0.0 | Erste Version | Bier abstreichen, Einzahlungen (Bar/TWINT), Rollen, TOTP, SSO, Kasse, Lager, Gäste, Runde, Statistik, Feedback |
