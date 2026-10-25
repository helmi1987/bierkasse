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
- Statistik: Biere pro Monat und Wochentag, Rangliste
- Gäste ohne Konto per QR-Kleber (Bar oder TWINT)
- Bier für einen Gast buchen
- Runde spendieren
- Bier verschenken
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
