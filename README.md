# LoxBerry-Plugin: Abfuhrkalender AWM München

Bringt den Abfuhrkalender des AWM München (Abfallwirtschaftsbetrieb) — oder
jeden anderen iCal-/ICS-Abfuhrkalender mit Tonnen-Namen in den Termin-Titeln —
in Loxone: Welche Tonne ist **morgen** fällig, welche **heute**, und in wie
vielen Tagen kommt die nächste Leerung. Dazu **Vorabend-Ansage**
(TTS über Music Server/Audioserver), **MQTT**-Veröffentlichung und **JSON**.

Kompatibel mit LoxBerry 3.x und **LoxBerry 4** (reines PHP, läuft mit PHP 7.4 und 8.x).

## Funktionen

- iCal-/ICS-Import per Adresse **oder als hochgeladene Datei**, Abruf-Intervall
  einstellbar; der Kalender wird dauerhaft gespeichert und übersteht Neustarts,
  Updates und Ausfälle des Entsorger-Servers
- Rollt **Serientermine** selbst aus: `FREQ=DAILY/WEEKLY/MONTHLY/YEARLY` mit
  `INTERVAL`, `BYDAY`, `BYMONTHDAY`, `BYMONTH`, `BYSETPOS`, `WKST`, `COUNT`,
  `UNTIL`, `EXDATE`, `RDATE` und `RECURRENCE-ID`. Was das Plugin **nicht**
  auswerten kann, sagt es im Reiter *Test* — es rät nicht
- **Acht Tonnenarten**: Restmüll/Hausmüll, Bio, Papier, Wertstoff/Gelbe Tonne,
  Glas, Sperrmüll, Grünschnitt, Schadstoffe. Alle acht kommen über die
  Loxone-Zeile, MQTT, die Vorlage und die Ansage an
- **Zuordnungstabelle je Kalender**: die Oberfläche zeigt, welche Bezeichnungen
  wirklich in der Datei stehen, und was das Plugin daraus macht — samt der
  Angabe, was eine eigene Regel dabei verdrängt
- **Eigene Termine** von Hand (Weihnachtsbaum, Sperrmüll auf Abruf)
- **Loxone-Textzeile**, 1:1 kompatibel zum verbreiteten muell.php-Format
  (`MUELL;REST=..;BIO=..;PAPIER=..;DATUM=..`), erweitert um Heute-Flags,
  Tage-Zähler, Datum je Tonne, **Loxone-Zeit** zum Rechnen, die nächste
  Abholung überhaupt, Ausfallerkennung und die Melde-Merker
- **Vorabend-Ansage** und optional eine **zweite Ansage am Abholmorgen**
  („Steht die Tonne schon draußen?“), Text frei wählbar mit Platzhaltern
- **Ruhezeiten**: nachts nicht sprechen, im Urlaub nicht sprechen (Quelle ist
  das Plugin *Ferien und Feiertage*, falls installiert), oder bis zu einem
  Datum ganz aussetzen
- **Quittierung** aus Loxone zurück ans Plugin (`?ack=1`) — danach entfällt die
  Morgen-Ansage
- **MQTT** über das LoxBerry MQTT Gateway, bei jeder Änderung und alle 30 min
  als Lebenszeichen. **Alle** Werte der Loxone-Zeile, dazu Hinweistext und drei
  fertige Sätze
- **Zwei Loxone-Vorlagen** zum Einlesen: virtuelle Eingänge (nur die Tonnen, die
  im eigenen Kalender vorkommen) und ein virtueller Ausgang für die
  auslösenden Aufrufe samt Token
- **Ausfallerkennung**: Alter der Kalenderdatei, Erfolg des letzten Abrufs,
  letzter Termin im Kalender — als eigene Werte für Loxone
- **Lücken-Erkennung**: streicht der Entsorger einen Termin, ohne einen Ersatz
  zu nennen, wird das angezeigt statt verschwiegen
- **Warnungen im LoxBerry-Meldebereich** bei ausgelaufenem Kalender, dreimal
  gescheitertem Abruf und misslungener Jahres-Erneuerung
- **Jahres-Erneuerung**: Endet der Kalender in weniger als 30 Tagen, versucht
  das Plugin selbst einen frischen Link — mit eigenen Strategien für AWM
  München, Abfallplus/abfall.io, Jumomind/MyMüll und ATURIS
- Konfiguration, Protokoll und Kalender überleben Plugin-Updates und eine
  Neuinstallation

## Neu in 1.4.0

Diese Fassung geht auf eine zeilenweise Durchsicht mit zwei Prüfagenten
zurück. Gemessen wurde gegen den echten Parser des Plugins und gegen einen
echten AWM-Export (73 Abholtage im Jahr 2026); die Münchner Erkennung ist
danach Zeile für Zeile dieselbe geblieben.

### Was still falsch war

- **Die Loxone-Vorlage vertauschte acht Eingänge.** `MUELL_REST` war als analog
  0–365 mit der Einheit „Tage“ deklariert und führt in Wahrheit 0/1;
  `MUELL_TREST` war digital 0–1 und führt den Tage-Zähler. Loxone Config nimmt
  so eine Datei klaglos an — der Fehler fällt erst in der App auf. `WARN` trug
  außerdem den Kommentar eines anderen Feldes.
- **`EXDATE:a,b,c` in einer Zeile** ließ nur die erste Ausnahme wirken. Der
  Münchner Export schreibt ein Datum je Zeile und war davon nicht betroffen;
  wer den Kalender eines anderen Entsorgers eintrug, bekam Abholtermine
  gemeldet, die abgesagt waren.
- **`BYSETPOS`, `BYMONTH` und `FREQ=YEARLY;BYDAY`** wurden nicht ausgewertet.
  „Letzter Werktag des Monats“ traf jeden Werktag — 20 Termine im Februar statt
  einem. Genau diese Formen benutzen Entsorger für Sperrmüll, Grünschnitt und
  das Schadstoffmobil.
- **Ein Kalender mit `COUNT` lief leer, ohne dass es jemand erfuhr.** Die
  Jahreswechsel-Warnung sah nur auf `UNTIL`.
- **`RDATE`, `RECURRENCE-ID` und `STATUS:CANCELLED`** wurden nicht beachtet.
- **Ein `CHARSET=` an einer einzelnen Zeile** rechnete die ganze Datei um — aus
  „Restmülltonne“ wurde „RestmÃ¼lltonne“, und zwar dauerhaft.
- **Die Vorabend-Ansage verlangte Minutengleichheit.** Zog ein Cron-Lauf über
  die Zielminute, entfiel sie still bis zum nächsten Tag.
- **Vier der acht Tonnenarten kamen nirgends an.** Glas, Sperrmüll, Grünschnitt
  und Schadstoffe waren in der Zuordnungstabelle wählbar und blieben folgenlos.
- **HTTP und MQTT lieferten verschieden viel** — 19 gegen 23 Werte. Beide Wege
  rechnen jetzt aus derselben Funktion; sie können nicht mehr auseinanderlaufen.
- **In vier Reitern stand PHP-Quelltext wörtlich in der Seite.**
- **Die englische Sprachdatei übersetzte MQTT-Themennamen** — der
  englischsprachige Anwender abonnierte Themen, die es nicht gibt.

### Was dazugekommen ist

Die Suchtexte der Loxone-Vorlage tragen jetzt das Trennzeichen
(`\i;REST=\i\v`) und entstehen an einer einzigen Stelle. Bestehende Eingänge
ohne Semikolon funktionieren unverändert weiter.

Dazu: bis zu vier Kalender, Datei-Upload, eigene Termine, Ruhezeiten und
Urlaub, zweite Ansage, Quittierung, freie Ansagetexte, Loxone-Zeit,
Ausfallerkennung, Lücken-Erkennung, Kalenderdiagnose im Reiter *Test*,
LoxBerry-Meldungen, virtuelle Ausgangs-Vorlage, Formulartoken, und ein
`?refresh=1`, das nicht mehr ohne Token ins Netz geht.

Die Oberfläche folgt jetzt dem Hausstandard mit fünf Reitern; die
Tonnenzuordnung ist eine Einstellung und steht deshalb bei den Einstellungen.

## Endpunkte

| Aufruf | Zweck |
|---|---|
| `/plugins/awmabfuhr/awm.php` | Loxone-Zeile mit allen Werten |
| `/plugins/awmabfuhr/awm.php?debug=1` | zusätzlich alle Termine, Lücken und Abruffehler |
| `/plugins/awmabfuhr/awm.php?json=1` | kompletter Zustand als JSON |
| `/plugins/awmabfuhr/awm.php?text=1` | drei fertige Sätze und der Hinweistext |
| `/plugins/awmabfuhr/awm.php?ics=1` | der gespeicherte Kalender zum Abonnieren |
| `/plugins/awmabfuhr/awm.php?say=1&token=…` | Ansage sofort abspielen **(Token nötig)** |
| `/plugins/awmabfuhr/awm.php?ptest=1&token=…` | Test-Pushnachricht auslösen **(Token nötig)** |
| `/plugins/awmabfuhr/awm.php?ack=1&token=…` | Quittierung „Tonne steht draußen“ **(Token nötig)** |
| `/plugins/awmabfuhr/awm.php?renew=1&token=…` | Jahres-Erneuerung jetzt versuchen **(Token nötig)** |
| `/plugins/awmabfuhr/awm.php?refresh=1&token=…` | Kalender sofort neu abrufen **(Token nötig)** |
| `/plugins/awmabfuhr/awm.php?selftest=1&token=…` | nur prüfen, ob das Token stimmt — löst nichts aus |

`&cal=2` wählt den zweiten Kalender, `&cal=3` den dritten und so fort.

## Einrichtung (AWM München)

1. [AWM-Abfuhrkalender öffnen](https://www.awm-muenchen.de/abfall-entsorgen/muelltonnen/abfuhrkalender),
   Straße und Hausnummer eingeben, „Weiter“.
2. Auf der Ergebnisseite den iCal-Export-Link („Termine als iCal/ICS“) mit der
   rechten Maustaste kopieren.
3. Link in der Plugin-Oberfläche als „iCal-Adresse“ eintragen, speichern.
4. „Jetzt abrufen“ klicken — fertig; ab dann hält das Plugin die Termine
   automatisch aktuell.

Bietet der eigene Entsorger keine dauerhafte Adresse, sondern nur eine Datei
zum Herunterladen: die Datei unter „Kalenderdatei hochladen“ übergeben.

## Datenschutz

Es sind **keine persönlichen Daten** im Plugin enthalten. Die Adresse steckt
ausschließlich in der lokal gespeicherten iCal-Adresse
(`config/plugins/awmabfuhr/awm.json`); beim AWM enthält sie Straße und
Hausnummer. Externe Verbindungen gibt es nur zum konfigurierten
Kalender-Server. Beim Deinstallieren werden auch die Sicherungskopien neben
dem Plugin-Ordner entfernt — sie enthalten dieselbe Adresse und das
Aktionstoken.

## Lizenz

MIT — siehe [LICENSE](LICENSE).
