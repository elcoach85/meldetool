# Meldetool

WordPress-Plugin zur Team- und Fahreranmeldung mit Pods.

## Quickstart

1. Plugin und Pods aktivieren.
2. In Pods die Verbindungen und `Sync`-Optionen für `rennklasse`/`team` sowie `kategorie`/`fahrer` prüfen.
3. Seite `/anmeldung` kontrollieren und sicherstellen, dass Team- und Fahrerformular vorhanden sind.
4. Unter `Einstellungen -> Meldetool` vor Meldebeginn insbesondere Teilnehmerlimits und Etappenoptionen prüfen.
5. Eingehende Teams im Admin manuell freigeben, damit sich Fahrer*innen für diese Teams anmelden können.

## Features im Überblick

- Frontend-Anmeldung über die Seite `/anmeldung` für Teams und Fahrer*innen
- Team- und Fahrerverwaltung im WordPress-Admin (eigene Post Types `team` und `fahrer`)
- Team-Freigabe durch den Veranstalter (manuelle Bestätigung)
- Fahrer melden sich selbstständig in bereits angelegte Teams
- E-Mail-Workflow für Team- und Fahrerbestätigungen (inkl. Double-Opt-In für Fahrer)
- Export von Fahrer- und Teamlisten (CSV + druckbare Starterliste)
- Vollbackup (Export/Import als JSON) inkl. Einstellungen

## Installation und Ersteinrichtung

1. Plugin in WordPress installieren und aktivieren.
2. Sicherstellen, dass das Pods-Plugin installiert und aktiv ist.
3. Nach der Aktivierung die Hinweise im Admin beachten und in Pods folgende Punkte prüfen:
   - Pods -> `rennklasse` -> Verbindungen: `team` aktivieren
   - Pods -> `kategorie` -> Verbindungen: `fahrer` aktivieren
   - Pods -> `Team` -> Feld `Rennklasse` -> Relationship-Optionen: `Sync` aktivieren
   - Pods -> `Fahrer` -> Feld `Kategorie` -> Relationship-Optionen: `Sync` aktivieren
   - Danach ggf. Pods-Cache leeren
4. Prüfen, ob die Seite `/anmeldung` vorhanden ist (sollte von  bei der Aktivierung automatisch angelegt, falls nicht vorhanden). Falls diese bereits vorhanden war,  
   - muss der shortcode `[pods-form name="team" fields="teamname,team-rennklasse,teammanager,email_manager,iban,bic,kontoinhaber"]` (oder ein Pods-Formular mit diesen Feldern) für die Teamanmeldung und
   - der shortcode `[pods-form name="fahrer" fields="nachname,vorname,team,fahrer-kategorie,etappen_auswahl,lizenznummer,uci_id,ist_kapitaen,email_rider,nationalitaet,iban,bic,kontoinhaber" encrypted="0" logged_in="false"]` (oder ein Pods-Formular mit diesen Feldern) dort eingefügt werden.
5. Um die Meldeliste anzuzeigen, muss auf einer entsprechenden Seite der Short-Code `[meldetool_starterliste]` eingefügt werden.

## Bedienung im Admin-Menü

### Fahrer- und Teamlisten

- Im WordPress-Admin-Menü stehen die Listen für `Teams` und `Fahrer*innen` zur Verfügung.
- Dort können Datensätze gefiltert, geprüft und bearbeitet werden.
- Neue Teammeldungen sollten vor Freigabe inhaltlich geprüft werden.

### Wichtiger Ablauf: Teams manuell bestätigen

- Gemeldete Teams müssen manuell durch Admins bestätigt/freigegeben werden.
- Praktisch bedeutet das: Team in der Teamliste prüfen und anschließend veröffentlichen/freigeben.
- Erst nach dieser Freigabe gilt das Team als offiziell gemeldet und wird für die Meldung von Fahrern in der Dropdownliste angezeigt.

### Fahrer melden sich selbständig für Teams

- Fahrer*innen nutzen das Fahrer-Formular auf `/anmeldung` und wählen dort ihr Team.
- Das Team muss dafür bereits vorhanden und veröffentlicht sein.
- Nach Absenden erfolgt ein E-Mail-Bestätigungsprozess für Fahrer (Double-Opt-In). Erst nach dessen erfolgreichem Abschluss (oder nach manueller Bestätigung/Veröffentlichung) ist ein Fahrer in der Meldeliste sichtbar.

## Einstellungsseite (`Einstellungen -> Meldetool`)

Auf der Meldetool-Einstellungsseite werden zentrale Optionen verwaltet:

- Mailversand (Aktivierung, Absender, Reply-To, CC)
- Betreff- und Nachrichtenvorlagen für Team- und Fahrermails
- Etappenoptionen für U17 und Hobby (jeweils eine Option pro Zeile)
- Nationalitätscodes für Fahrer
- Teilnehmerlimits pro Rennklasse

### Sehr wichtig vor Meldebeginn

- Vor Öffnung der Anmeldung die erwartete/zulässige Anzahl von Fahrer*innen pro Rennklasse prüfen und einstellen.
- Diese Limits steuern, ob Teams in einer Rennklasse im Fahrerformular noch auswählbar sind.
- Empfehlung: Limits rechtzeitig testen und bei Bedarf während der Meldephase anpassen.

### Etappenoptionen: Kurz erklärt

- `Etappenoptionen U17`: gilt nur für U17-Teams
- `Etappenoptionen Hobby`: gilt nur für Hobby-Teams
- Die gepflegten Werte erscheinen im Fahrerformular als Auswahl.
- Änderungen wirken sich direkt auf zukünftige Fahreranmeldungen aus.

## Export von Fahrer- und Teamlisten

Unter `Werkzeuge -> Team/Fahrer Export` stehen mehrere Exporte bereit:

- Fahrerliste als CSV
- Teamliste als CSV
- Teammanager-E-Mail-Liste als CSV
- Starterliste als druckbare PDF-Ansicht

Zusätzlich sind Parameter wie Trennzeichen und Nummern pro Team konfigurierbar.

## Backup erzeugen und laden

Unter `Werkzeuge -> Meldetool Backup`:

- Backup exportieren: vollständiges JSON-Backup herunterladen
- Backup importieren: JSON-Backup einspielen
- Optional vor Import: bestehende Teams/Fahrer löschen
- Beziehungen reparieren: Taxonomie-/Relationship-Zuordnungen synchronisieren (wird beim Import eines Backups automatisch durchgeführt, damit Pod-IDs zu Fahrern/Teams passen).

Empfehlung: Vor größeren Änderungen oder vor Saisonstart ein Backup exportieren.

## Debugging

1. Als Admin anmelden
2. Folgende URL aufrufen: `wp-admin/edit.php?post_type=fahrer&debug_fahrer=6355`
3. `6355` durch eine echte Fahrer-ID ersetzen
4. Info-Box mit Debugging-Informationen wird oben angezeigt
