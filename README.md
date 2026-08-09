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

## Neu in 1.3.0

- **Der Kalender überlebt das Update jetzt wirklich.** `preupgrade.sh` sicherte
  eine Datei namens `kalender.ics`, die es seit 1.1.0 nicht mehr gibt — seit der
  Mehrkalender-Fähigkeit heißen sie `kalender_1.ics` und `kalender_2.ics`. Der
  Befehl lief lautlos ins Leere. Zusätzlich gibt es jetzt eine dauerhafte
  Sicherung außerhalb des Plugin-Ordners, wie sie die Konfiguration längst hat.
- **Wiederholversuch nach einem Ausfall des Entsorger-Servers.** Ein
  fehlgeschlagener Abruf setzte den Zeitstempel der vorhandenen Datei auf jetzt
  und verschob den nächsten Versuch damit um das volle Abruf-Intervall — bei der
  Voreinstellung also um 14 Tage. Jetzt wird nach einer Stunde erneut gefragt.
- **Zeichensätze außer UTF-8.** Kalender in ISO-8859-1 oder Windows-1252 werden
  beim Abruf und beim Einlesen umgewandelt. Vorher scheiterte `json_encode` an
  einem einzelnen Umlaut: der Zwischenspeicher ließ sich nicht schreiben und
  `?json=1` lieferte einen leeren Rumpf mit Status 200.
- **Eigene TTS-Vorlagen ohne IP-Feld** funktionieren. Die Prüfung auf eine
  gesetzte IP galt für alle Ausgabearten und machte Vorlagen wie
  `http://sprich.local/say?text={text}` unbenutzbar.
- **Stichwörter für Feiertagsverschiebungen sind einstellbar.** Fest verdrahtet
  war „Achtung“ — die Formulierung des AWM München. Andere Entsorger schreiben
  „Verschiebung“, „Feiertagsregelung“ oder „Ersatztermin“. „Achtung“ steht
  weiterhin an erster Stelle der Standardliste.
- **Unteilbares Schreiben** (Nebendatei + `rename`) für Konfiguration,
  Kalender und Zwischenspeicher. Der Zwischenspeicher wird jede Minute vom
  Cron-Lauf geschrieben und im 300-s-Takt vom Miniserver gelesen — ein halb
  geschriebenes JSON konnte dort als Nullwert ankommen.
- **Rechnung ohne `DateTime`.** Die Terminprüfung legte je Aufruf zwei
  Datumsobjekte an; bei 35 Vorschautagen und 200 Terminen waren das 14.000
  Objekte für eine Rechnung mit 36 verschiedenen Datumswerten. 677.817
  Vergleiche gegen die alte Fassung, über beide Zeitumstellungen hinweg: kein
  einziger Unterschied.
- **Relative Links** werden beim AWM-Website-Scraping mitgelesen.
- `PRERELEASECFG` war leer — wer den Vorab-Kanal eingestellt hatte, bekam gar
  keine Aktualisierungen mehr. Es gibt jetzt eine `prerelease.cfg`.
- Oberfläche nach Hausstandard: Reiter als echte Verweise (funktionieren auch
  ohne JavaScript), Farblegende in jedem Reiter mit Knöpfen, kein roter Knopf
  mehr, Tonnennamen und Tagesangaben aus den Sprachdateien statt fest auf
  Deutsch.

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
