#!/bin/bash
# Abfuhrkalender AWM - postupgrade: Konfiguration, Protokoll und Kalender
# zurueckspielen
#
# $1 ist KEIN Pfad (siehe preupgrade.sh) - der Arbeitsordner steht im
# sechsten Argument.
ARGV1=$1
ARGV3=$3
ARGV5=$5
ARGV6=$6
PFOLDER="${ARGV3:-awmabfuhr}"
BASE="${ARGV5:-$LBHOMEDIR}"

if [ -n "$ARGV6" ] && [ -d "$ARGV6" ]; then
    TMPF="$ARGV6"
else
    TMPF="$PWD/$ARGV1"
fi

CFGDIR="$BASE/config/plugins/$PFOLDER"
LOGDIR="$BASE/log/plugins/$PFOLDER"
DATDIR="$BASE/data/plugins/$PFOLDER"
mkdir -p "$CFGDIR" "$LOGDIR" "$DATDIR" 2>/dev/null

[ -f "$TMPF/awm.json" ] && cp -p "$TMPF/awm.json" "$CFGDIR/awm.json"
[ -f "$TMPF/awm.log" ] && cp -p "$TMPF/awm.log" "$LOGDIR/awm.log"

# Kalender zurueckspielen - alle, die gesichert wurden.
for f in "$TMPF"/kalender_*.ics; do
    [ -f "$f" ] || continue
    cp -p "$f" "$DATDIR/$(basename "$f")"
done

# Rueckfallebene Konfiguration: die dauerhafte Sicherung.
#
# Die Pruefung auf einen leeren Rumpf war bis 1.2.0 ein Textvergleich gegen
# genau "{}". Eine Datei mit "{ }", einem Zeilenumbruch davor oder auch nur
# einem Leerzeichen dahinter galt damit als brauchbar. Jetzt entscheidet, ob
# ueberhaupt ein Schluessel darin steht.
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$CFGDIR/awm.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || ! grep -q '"' "$CF" 2>/dev/null; then
        cp -p "$BK" "$CF"
    fi
fi

# Rueckfallebene Kalender: nur, wenn wirklich keiner da ist. Ein vorhandener
# Kalender ist immer aktueller als die Sicherung.
BKDIR="$BASE/config/plugins/$PFOLDER.backup.ics"
if [ -d "$BKDIR" ] && [ -z "$(ls -A "$DATDIR"/kalender_*.ics 2>/dev/null)" ]; then
    for f in "$BKDIR"/kalender_*.ics; do
        [ -f "$f" ] && [ -s "$f" ] || continue
        cp -p "$f" "$DATDIR/$(basename "$f")"
    done
fi

# Zwischenspeicher der alten Fassung wegwerfen.
#
# In 1.4.0 haben state_N.json und die MQTT-Signatur neue Felder. Ein
# stehengebliebener Zwischenspeicher aus 1.3.8 wuerde bis zu zehn Minuten
# lang eine Zeile ohne die neuen Werte liefern - und die Signatur wuerde
# gleich bleiben, sodass ueber MQTT gar nichts nachkaeme.
rm -f /tmp/awmabfuhr/state_*.json /tmp/awmabfuhr/mqtt_sig_*.txt 2>/dev/null

# Eigentuemer richtigstellen.
#
# cp -p uebernimmt Rechte und Zeitstempel, aber der Eigentuemer richtet sich
# danach, wer das Skript ausfuehrt. Laeuft das Update als root - und das tut
# es bei LoxBerry - gehoeren die zurueckgespielten Dateien anschliessend root.
# Der Webserver und der Cron-Lauf arbeiten als loxberry und koennten dann
# weder Konfiguration noch Kalender schreiben: das Plugin liesse sich nicht
# mehr speichern, und die Jahres-Erneuerung schluege stumm fehl.
if id loxberry >/dev/null 2>&1; then
    chown -R loxberry:loxberry "$CFGDIR" "$LOGDIR" "$DATDIR" 2>/dev/null
    [ -f "$BK" ] && chown loxberry:loxberry "$BK" 2>/dev/null
    [ -d "$BKDIR" ] && chown -R loxberry:loxberry "$BKDIR" 2>/dev/null
fi

exit 0
