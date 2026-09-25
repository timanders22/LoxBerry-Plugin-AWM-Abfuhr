# LoxBerry-Plugin: Abfuhrkalender AWM München

Bringt den Abfuhrkalender des AWM München (Abfallwirtschaftsbetrieb) — oder
jeden anderen iCal-/ICS-Abfuhrkalender mit Tonnen-Namen in den Termin-Titeln —
in Loxone: Welche Tonne ist **morgen** fällig, welche **heute**, und in wie
vielen Tagen kommt die nächste Leerung. Dazu **Vorabend-Ansage**
(TTS über Music Server/Audioserver), **MQTT**-Veröffentlichung und **JSON**.

Kompatibel mit LoxBerry 3.x und **LoxBerry 4** (reines PHP, läuft mit PHP 7.4 und 8.x).

## Neu in 1.4.13

- **Nur noch drei Themen gehen zurückbehalten (retained) an den Broker:
  `audio`, `push` und `letzter`.** Alles, was allein durch die Uhr falsch
  wird — morgen, heute, „in N Tagen", die nächste Abholung, Hinweis,
  Fenster, fertige Sätze —, und jede Aussage des Plugins über sich selbst
  (`ok` „Kalenderdaten vorhanden", `abruf`) gehen flüchtig hinaus. Bis 1.4.12
  gingen 59 von 64 Themen zurückbehalten hinaus; nach einem Neustart von
  Broker oder Gateway stand dann zum Beispiel „Restmüll morgen" oder `ok=1`
  aus einem Lauf, der Tage zurücklag, als aktueller Wert im Miniserver.
  Entschieden wird jetzt über eine Positivliste — ein neues Thema geht nie
  still zurückbehalten hinaus, ein leerer Wert nie. Preis: nach einem solchen
  Neustart fehlen die flüchtigen Werte, bis der nächste volle Satz kommt
  (spätestens nach 30 Minuten).
- **Die Altwerte räumt das Plugin selbst ab.** Solange der Broker noch einen
  zurückbehaltenen Wert einer Vorfassung hält, geht unmittelbar vor dem
  gültigen Wert eine leere zurückbehaltene Nachricht hinaus. Ob das gewirkt
  hat, fragt das Plugin beim Broker nach (eigenes MQTT-Abonnement mit den
  Zugangsdaten aus der `general.json`); erst wenn er bestätigt, dass nichts
  mehr steht, wird ein Merker gesetzt. Ist der Broker nicht zu fragen, wird
  ohne Merker in jedem Lauf vor dem Wert abgeräumt — der UDP-Eingang des
  Gateways verwirft unter Last Datagramme, und das Senden meldet trotzdem
  Erfolg.
- **Die Deinstallation leert die zurückbehaltenen Themen** unter dem
  eingestellten Präfix (Kalender 1 bis 4) und liest beim Broker nach. Ist er
  nicht zu fragen, leert sie nur die eingerichteten Kalender und sagt, dass
  nicht nachgelesen wurde. Themen unter einem früher eingestellten Präfix
  bleiben stehen.
- **Ein ausgepacktes Archiv wirkt nicht mehr auf die Anlage.** Der
  Minutenlauf (`cron.php`) arbeitet nur noch aus der Installation oder mit
  ausdrücklich gesetztem `LBHOMEDIR` und `LBPPLUGINDIR`, sonst bricht er mit
  einer Meldung ab. Die Wurzelsuche verlangt `config/system/general.json`;
  die Hakenskripte und die Deinstallation warnen ohne brauchbare Wurzel,
  statt Pfade ab `/` zu bilden. Sprachdateien, die Bibliothek der Oberfläche
  und das Ferien-Plugin werden nicht mehr über Pfade ab `/` gesucht.
- **Eine Sicherung ohne Inhalt wird nicht mehr zurückgespielt,** und die
  Installation meldet nicht mehr „wiederhergestellt", wenn die Sicherung
  kaputt oder leer war. Die Jahres-Erneuerung schreibt die Konfiguration mit
  den Rechten 0600 statt 0664.

Alles in WSL nachgestellt (`Pruefung-AWM-Abfuhr-1.4.13/`, 71 Fälle), nicht
am Gerät.

## Neu in 1.4.12

**Nach einem Update fordert die Installation nicht mehr dazu auf, die iCal-URL
einzutragen, wenn die Einstellungen übernommen sind.** Bis 1.4.11 stand dieser
Rat am Ende jeder Installation. Jetzt meldet sie „Einstellungen übernommen",
sobald nach dem Zurückholen mindestens ein Kalender mit Adresse oder
hochgeladener Datei eingetragen ist. Fehlt die Zweitschrift, holt erst
`postupgrade.sh` die Einstellungen zurück und meldet dort, ob es gelang;
scheitert es, erscheint der Rat als Warnung. In WSL nachgestellt
(`Pruefung-AWM-Abfuhr-1.4.12/`), nicht am Gerät.

## Neu in 1.4.10

- **Tabellen mit Eingabefeldern rollen seitlich, statt abgeschnitten zu werden.**
  Die Oberfläche von LoxBerry schneidet breite Inhalte ab, ohne dass die Seite
  seitlich rollt; an BatterieBMS waren so zwei Einstellungen je Speicher nicht
  erreichbar. Diese Tabellen stehen jetzt im Rollbehälter der Hausform.

## Neu in 1.4.9

- **Es gehen nur noch die Themen hinaus, deren Wert sich geändert hat.** Bis
  1.4.8 schickte jede Änderung irgendeines Wertes den **ganzen** Satz — 61
  Datagramme in einem Stoß. Am Gerät gemessen (16.09.2026): der
  Empfangspuffer des MQTT-Gateways auf Port 11884 steht dauerhaft bei rund
  46 kB, und der Kernel hat **1 146 176 von 6 933 061 Datagrammen verworfen —
  16,5 %**. In einer Mitschrift über zwei Minutenläufe kamen 62 der 64 Themen
  an; zwei fehlten. Der Absender merkt davon nichts: `sendto()` meldet auch
  für ein verworfenes Datagramm Erfolg.

  Seit 1.4.8 gehen die Zustände zurückbehalten hinaus — und damit wird aus
  einem verlorenen Datagramm ein Schaden, den man nicht sieht: im Broker
  bleibt der **alte** Wert stehen und sieht aus wie der aktuelle, bis der
  nächste Vollversand kommt.

  Gemessen an einem eigenen UDP-Fänger, beide PHP-Fassungen:

  | Lauf | bis 1.4.8 | ab 1.4.9 |
  |---|---|---|
  | nichts geändert | 3 (nur Lebenszeichen) | 3 |
  | ein Wert geändert | **64** | **4** — Lebenszeichen und das eine Thema |
  | alle 30 Minuten | 64 | 64 |
  | erster Lauf nach dem Update | 64 | 64 |

  Der volle Satz alle 30 Minuten bleibt: ein neu aufgesetzter Broker hat die
  zurückbehaltenen Werte sonst nicht. Der Merker wird nur fortgeschrieben,
  wenn wirklich etwas hinausging — sonst gälte ein Lauf ohne Broker als
  erledigt, und Felder, die tagelang gleich stehen, fehlten dauerhaft.
  Bauart von ACTiKamera 1.9.19.

- **Die drei `.cfg` tragen jetzt LF** statt CRLF. Hausbrauch in Plugin-Ordnern
  seit dem 13.09.2026; das Freigabetor meldete sie. Am Inhalt ändert sich
  nichts.

## Neu in 1.4.8

- **Das Auswahlfeld zeichnet seinen Pfeil selbst.** Bis 1.4.7 kam er von der
  Oberfläche des LoxBerry. Am 05.09.2026 am Gerät gemessen (LoxBerry 4.0.0.15,
  `system/css/components.css`): deren Regel `.lb-content select`
  gibt es erst seit der neuen Oberfläche, und jede eigene Feldregel mit der
  Kurzform `background:` löscht sie wieder. Darauf soll sich eine
  Plugin-Oberfläche nicht verlassen (`Regeln/04`).

- **Zustände gehen jetzt zurückbehalten (retained) an den Broker.** Bis 1.4.7
  schickte das Plugin ausnahmslos `publish`; am 06.09.2026 am Broker der
  Anlage gemessen: **0 zurückbehaltene Themen unter `awm/`**, während der
  Broker insgesamt 2 158 hielt. Nach einem Neustart des Miniservers oder des
  MQTT-Gateways standen die virtuellen Eingänge damit leer, bis der nächste
  Vollversand kam — längstens eine halbe Stunde.

  Dass der UDP-Weg des Gateways das kann, ist am Gerät im Quelltext gemessen
  (`sbin/mqttgateway.pl`: die vier Befehle `publish`, `retain`, `reconnect`,
  `save_relayed_states`). Von 64 Themen gehen **59 zurückbehalten** hinaus und
  **5 nicht**:

  | Thema | warum nicht zurückbehalten |
  |---|---|
  | `alter` | Das Alter *ist* der Zeitbezug — zurückbehalten stünde dort die Stundenzahl von damals, und genau dieses Feld soll den Ausfall anzeigen. |
  | `ptest` | Ein Testmerker mit fünf Minuten Lebensdauer. Zurückbehalten löste er nach jedem Neustart eine Test-Pushnachricht aus, deren Anlass längst vorbei ist. |
  | `status/ok`, `status/ts`, `status/zaehler` | Das Lebenszeichen zeigte zurückbehalten immer „lebt“ und beantwortete die Frage nicht mehr, für die es da ist. |

  Die Tabelle im Reiter *MQTT* nennt je Thema, ob es zurückbehalten wird, und
  fragt dafür dieselbe Funktion wie der Sender. Hausstandard: `Regeln/07`;
  Bauart übernommen von GardenaSmartSystem 1.2.5.

  **Beim Umstieg:** die zurückbehaltenen Werte entstehen mit dem ersten
  Minutenlauf nach dem Einspielen. Wer das Plugin wieder entfernt, lässt sie
  auf dem Broker zurück — sie verschwinden erst, wenn jemand auf dasselbe
  Thema eine leere zurückbehaltene Nachricht schickt.

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

`&cal=2` wählt den zweiten Kalender, `&cal=3` den dritten — bis `&cal=4`.
Mehr Kalender kennt das Plugin nicht; eine höhere Nummer beantwortet der
Endpunkt mit der Zeile des vierten.

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

## Fassung 1.4.7 — die Wiederholungsregeln, und wer etwas auslösen darf

**Sicherheit.** `?json=1&refresh=1` löste bis 1.4.6 einen Abruf beim Entsorger
aus, **ohne Aktionstoken** — und wegen `isset()` tat das sogar
`?json=1&refresh=0`. Die Prüfung stand nur im Klartext-Zweig. Sie entsteht
jetzt einmal, oben in `awm.php`, und beide Zweige benutzen sie. Ebenfalls neu:
`cron.php` liegt zwar weiterhin im unangemeldeten Baum, nimmt aber nur noch
Aufrufe von der Kommandozeile an — über HTTP war es bis 1.4.6 möglich, damit
Abruf, MQTT-Meldung, Ansage und die Jahres-Erneuerung auszulösen.

**Wiederholungsregeln nach RFC 5545.** Der Kandidatensatz entsteht jetzt je
Zeitraum — bei `FREQ=YEARLY` also je **Jahr**, nicht je Monat. Vier Formen
gingen bis 1.4.6 still daneben:

| Regel | bis 1.4.6 | jetzt |
|---|---|---|
| `FREQ=DAILY;BYDAY=MO,WE,FR` | jeden Tag | montags, mittwochs, freitags |
| `FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1` | jeden Werktag | freitags |
| `FREQ=YEARLY;BYMONTH=1,4,7,10;BYDAY=MO;BYSETPOS=1` | vier Termine im Jahr | einer |
| `FREQ=MONTHLY;BYDAY=2TH;BYSETPOS=1` | erster Donnerstag | zweiter Donnerstag |

`COUNT` wird nicht mehr nach elf Jahren abgeschnitten: eine Serie mit
`COUNT=20` und `FREQ=YEARLY` endete bis 1.4.6 beim elften Termin, und alle
späteren wurden gestrichen. Was sich an einer Regel **nicht lesen** lässt
(`BYMONTH=abc`, eine Ordnungszahl bei `FREQ=WEEKLY`), steht jetzt im Reiter
*Test* statt still zu wirken.

**Herzschlag.** Neu am Ende der Loxone-Zeile: `ZAEHLER` läuft 0…999 um und
bleibt stehen, sobald der Minutenlauf steht — auf eine Änderungsüberwachung
verdrahten. Über MQTT kommen dazu `status/ok`, `status/ts` und
`status/zaehler`; sie gehen bei **jedem** Durchgang hinaus, auch wenn sich
sonst nichts geändert hat.

**Einstellungen sichern und zurückspielen.** Die Sicherung enthält nur noch
die bekannten Schlüssel und einen lesbaren Kopf — auf einer aus einer alten
Fassung fortgeschriebenen Anlage lehnte das Zurückspielen die **eigene**
Sicherung bis 1.4.6 vollständig ab (wegen `ical_url`). Beim Zurückspielen wird
jetzt jeder **Wert** geprüft, nicht nur der Schlüsselname; danach wird der
Zustand neu berechnet und neu veröffentlicht, und die Seite sagt es.

**Kleineres.** Die Konfiguration steht auf `0600` (sie trägt Aktionstoken und
Anschrift). Die Ruhezeit vergleicht Uhrzeiten nicht mehr als Zeichenketten —
`9:00` bis `17:00` sperrte bis 1.4.6 von 0:00 bis 16:59. `AGE` zeigt wieder das
echte Alter der Kalenderdatei. Ein leerer Kalender vom Entsorger überschreibt
den guten gespeicherten Stand nicht mehr. Eine geleerte Kalender-Adresse löscht
nicht mehr die Zuordnungsregeln und die eigenen Termine dieses Kalenders. Zahlen
außerhalb ihrer Grenzen werden abgewiesen und gemeldet statt stillschweigend
gekappt. Fehlerausgabe geht ins Protokoll statt in die Seite.

## Fassung 1.4.6 — Wortlaut für das MQTT-Gateway V2

Nur Text: der Reiter *MQTT* und der Reiter *Einbindung in Loxone* nennen jetzt
den Ort im LoxBerry (*System → MQTT Gateway → Subscriptions*) und unterscheiden
Gateway-Fassung 1 von 2 und neuer. Kein PHP berührt.

## Fassung 1.4.5 — der Stat-Zwischenspeicher
Die Protokollkappung (512 000 Byte) stand in
`webfrontend/html/awm_lib.php:372`. PHP merkt sich aber die Antworten von
`stat()`: innerhalb **eines** Prozesses sieht `filesize()` die erste Größe
und danach nie wieder eine neue — `file_put_contents(…, FILE_APPEND)` macht
den Eintrag nicht ungültig. Die Kappung fällt dann still aus.

Gemessen am 29.08.2026, 20 000 Zeilen im selben Prozess:

| | ohne `clearstatcache` | mit |
|---|---|---|
| PHP 7.4.33 | 1 220 000 Byte, **nicht gekappt** | 220 332 Byte, gekappt |
| PHP 8.4.24 | 220 332 Byte, gekappt | 220 332 Byte, gekappt |

Die beiden PHP-Fassungen verhalten sich also verschieden — und LoxBerry 3.x
fährt 7.4. Wer nur unter 8.4 misst, sieht den Fehler nie. Folgen hatte das
hier nicht: die Aufrufer sind kurzlebig, und ein **frischer** Prozess kappt
richtig. Eine Funktion darf aber nicht davon abhängen, wer sie wie oft ruft.

Abhilfe: `clearstatcache(true, …)` **vor** dem Tor; der zweite Parameter
beschränkt das Leeren auf diese eine Datei. Dasselbe Muster tragen Robonect,
Saugroboter, SignalBot, Octopus, Sprachsteuerung und WärmepumpeCloud schon
länger — es ist am 29.08.2026 im ganzen Bestand nachgezogen worden.

## Lizenz

MIT — siehe [LICENSE](LICENSE).
