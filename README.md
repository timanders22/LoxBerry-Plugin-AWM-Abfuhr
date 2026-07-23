# LoxBerry-Plugin: Abfuhrkalender AWM München

Bringt den Abfuhrkalender des AWM München (Abfallwirtschaftsbetrieb) — oder
jeden anderen iCal-/ICS-Abfuhrkalender mit Tonnen-Namen in den Termin-Titeln —
in Loxone: Welche Tonne ist **morgen** fällig, welche **heute**, und in wie
vielen Tagen kommt die nächste Leerung. Dazu optionale **Vorabend-Ansage**
(TTS über Music Server/Audioserver), **MQTT**-Veröffentlichung und **JSON**.

Kompatibel mit LoxBerry 3.x und **LoxBerry 4** (reines PHP, läuft mit PHP 7.4 und 8.x).

## Funktionen

- iCal-/ICS-Import per URL, Abruf-Intervall einstellbar; der Kalender wird
  persistent gespeichert (übersteht Neustarte, Ausfälle des Entsorger-Servers)
- Rollt AWM-**Serientermine** korrekt selbst aus (RRULE FREQ=WEEKLY + INTERVAL
  + UNTIL + EXDATE) inkl. der einzelnen „Achtung“-Verschiebungstermine rund um
  Feiertage — der Verschiebungshinweis wird zusätzlich als Text angezeigt
- Tonnen-Erkennung: Restmüll/Hausmüll, Bio, Papier, Wertstoff/Gelbe Tonne
- **Loxone-Textzeile**, 1:1 kompatibel zum verbreiteten muell.php-Format
  (`MUELL;REST=..;BIO=..;PAPIER=..;DATUM=..`), erweitert um `WERT`, `OK`,
  `WARN`, Heute-Flags (`HREST` …) und Tage-Zähler (`TREST` …)
- **Vorabend-Ansage** (TTS) zur konfigurierten Uhrzeit über Music
  Server/Audioserver (Zonen mit individueller Lautstärke, `Zone~Lautstärke`)
  oder über eine eigene URL-Vorlage — einmal pro Tag, nur wenn morgen etwas
  fällig ist
- **MQTT** über das LoxBerry MQTT Gateway (bei Änderung + alle 30 min):
  `awm/rest_morgen`, `_heute`, `tage_*`, `datum_*`, `ok`, `warnung`, `hinweis`
- **JSON-Endpunkt** für Drittsoftware (`?json=1`)
- **Jahreswechsel-Warnung**: Der AWM-Link enthält das Kalenderjahr; endet der
  Kalender in <30 Tagen, warnt das Plugin in der Oberfläche, per `WARN=1` in
  Loxone und per MQTT
- Reiter: Einstellungen (mit Laien-Anleitung zur AWM-Website und
  „Jetzt abrufen“), Einbindung in Loxone (Schritt-für-Schritt inkl. kompletter
  Baustein-Liste und Praxis-Hinweisen zum Benachrichtigungs-Baustein), Test,
  Protokoll (mit Rotation)
- Konfiguration, Log und Kalender überleben Plugin-Updates und Neuinstallation
  (Sicherungskopie außerhalb des Plugin-Ordners)

## Endpunkte

| Aufruf | Zweck |
|---|---|
| `/plugins/awmabfuhr/awm.php` | Loxone-Zeile `MUELL;REST=..;BIO=..;PAPIER=..;DATUM=..;WERT=..;OK=..;WARN=..;HREST=..;…;TREST=..;…` |
| `/plugins/awmabfuhr/awm.php?debug=1` | zusätzlich alle Termine im Vorschau-Fenster |
| `/plugins/awmabfuhr/awm.php?refresh=1` | Kalender sofort neu abrufen |
| `/plugins/awmabfuhr/awm.php?json=1` | kompletter Zustand als JSON |
| `/plugins/awmabfuhr/awm.php?say=1` | Test: Vorabend-Ansage sofort abspielen |

## Einrichtung (AWM München)

1. [AWM-Abfuhrkalender öffnen](https://www.awm-muenchen.de/abfall-entsorgen/muelltonnen/abfuhrkalender),
   Straße und Hausnummer eingeben, „Weiter“.
2. Auf der Ergebnisseite den iCal-Export-Link („Termine als iCal/ICS“) mit der
   rechten Maustaste kopieren.
3. Link in der Plugin-Oberfläche als „iCal-Kalender-URL“ eintragen, speichern.
4. „Jetzt abrufen“ klicken — fertig; ab dann hält das Plugin die Termine
   automatisch aktuell.

## Datenschutz

Es sind **keine persönlichen Daten** im Plugin enthalten. Adresse bzw.
Kalender stecken ausschließlich in der lokal gespeicherten iCal-URL
(`config/plugins/awmabfuhr/awm.json`). Externe Verbindungen gibt es nur zum
konfigurierten Kalender-Server.

## Lizenz

MIT — siehe [LICENSE](LICENSE).
