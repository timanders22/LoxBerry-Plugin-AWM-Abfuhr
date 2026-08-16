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
  `awm/rest_morgen`, `_heute`, `tage_*`, `datum_*`, `ok`, `warnung`, `hinweis`,
  dazu die Melde-Merker `ann`, `audio`, `push`, `ptest`
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

## Neu in 1.3.6

**Der MQTT-Weg liefert jetzt alles, was der HTTP-Weg liefert.** Bisher
veröffentlichte das Plugin über MQTT 19 Werte, über HTTP aber 23: es fehlten
die vier Melde-Merker `ann` (Erinnerungsfenster), `audio` und `push`
(Freigaben aus der Konfiguration) sowie `ptest` (Test-Push). Wer auf MQTT
umstellte, verlor damit genau die Werte, mit denen sich Vorabend-Ansage und
Pushnachricht im Miniserver steuern und **prüfen** lassen — der Test-Push
löste über MQTT schlicht nicht mehr aus.

Drei Änderungen, damit das wirklich wirkt:

- Die vier Merker kommen aus **einer** Funktion (`awm_meldeflags()`), die
  beide Wege benutzen. HTTP und MQTT können nicht mehr auseinanderlaufen.
  `ann` bleibt beim Zweit- und Drittkalender 0 — wie bisher schon in der
  HTTP-Zeile, sonst redete das Haus mehrfach.
- Sie stehen jetzt auch in der **Signatur** des Cron-Laufs. Ohne das wären
  sie zwar in der Nachricht gewesen, die Nachricht aber nicht verschickt
  worden: `ann` und `ptest` ändern sich allein durch Zeitablauf, nicht durch
  einen Zustandswechsel — ein gesetzter `ptest` wäre bis zum halbstündlichen
  Lebenszeichen liegengeblieben, sein Fenster ist aber nur fünf Minuten breit.
- `?ptest=1` veröffentlicht **sofort**, statt bis zu eine Minute auf den
  nächsten Cron-Lauf zu warten. Ein Test, der erst eine Minute später wirkt,
  sieht aus wie ein Test, der nicht wirkt.

**Aktionstoken für die drei auslösenden Aufrufe.** `?say=1` (das Haus spricht),
`?ptest=1` (Pushnachricht aufs Telefon) und `?renew=1` (schreibt die
Konfiguration um) lagen bisher offen im Heimnetz — jedes Gerät konnte sie
auslösen. Sie verlangen jetzt ein Token aus dem Reiter *Einbindung in Loxone*;
ohne passendes Token antworten sie mit HTTP 403. Die abfragenden Aufrufe
bleiben offen, sie ändern nichts.

Dazu neu: **`?selftest=1&token=…`** beantwortet die Tokenfrage, ohne etwas
auszulösen — Hausstandard für alle Aktionsendpunkte. Das Token wird beim
ersten Aufruf der Oberfläche erzeugt und überlebt jedes Speichern; ein Knopf
erzeugt auf Wunsch ein neues.

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
| `/plugins/awmabfuhr/awm.php?say=1&token=…` | Test: Vorabend-Ansage sofort abspielen **(Token nötig)** |
| `/plugins/awmabfuhr/awm.php?ptest=1&token=…` | Test-Pushnachricht auslösen **(Token nötig)** |
| `/plugins/awmabfuhr/awm.php?renew=1&token=…` | Jahres-Erneuerung jetzt versuchen **(Token nötig)** |
| `/plugins/awmabfuhr/awm.php?selftest=1&token=…` | nur prüfen, ob das Token stimmt — löst nichts aus |

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
