#!/bin/bash
# Abfuhrkalender AWM - preupgrade: Konfiguration, Protokoll und Kalender sichern
#
# ACHTUNG, hier steckte bis 1.2.0 ein Fehler mit stillen Folgen:
#
#   cp -p "$BASE/data/plugins/$PFOLDER/kalender.ics" ...
#
# Diese Datei gibt es seit 1.1.0 nicht mehr. Seit der Mehrkalender-Faehigkeit
# heissen sie kalender_1.ics und kalender_2.ics. Der Befehl lief also ins
# Leere - mit 2>/dev/null auch noch lautlos - und beim Update verschwanden
# beide Kalender. Normalerweise faellt das nicht auf, weil das Plugin
# innerhalb einer Minute neu abruft. Ausser der Link ist inzwischen
# abgelaufen: dann sind die Termine weg und der Abruf holt sie nicht wieder.
#
# Deshalb jetzt: ALLE kalender_*.ics sichern, und zusaetzlich eine dauerhafte
# Kopie ausserhalb des Plugin-Ordners anlegen - so wie es die Konfiguration
# mit $PFOLDER.backup.json seit jeher hat.
ARGV1=$1
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-awmabfuhr}"
BASE="${ARGV5:-$LBHOMEDIR}"
TMPF="$ARGV1"

mkdir -p "$TMPF" 2>/dev/null

cp -p "$BASE/config/plugins/$PFOLDER/awm.json" "$TMPF/awm.json" 2>/dev/null
cp -p "$BASE/log/plugins/$PFOLDER/awm.log" "$TMPF/awm.log" 2>/dev/null

# Alle Kalender, egal wie viele
for f in "$BASE/data/plugins/$PFOLDER"/kalender_*.ics; do
    [ -f "$f" ] || continue
    cp -p "$f" "$TMPF/$(basename "$f")" 2>/dev/null
done

# Dauerhafte Sicherung ausserhalb des Plugin-Ordners. Sie ueberlebt auch
# ein Update, bei dem das Zwischenverzeichnis verloren geht.
BKDIR="$BASE/config/plugins/$PFOLDER.backup.ics"
mkdir -p "$BKDIR" 2>/dev/null
for f in "$BASE/data/plugins/$PFOLDER"/kalender_*.ics; do
    [ -f "$f" ] && [ -s "$f" ] || continue
    cp -p "$f" "$BKDIR/$(basename "$f")" 2>/dev/null
done

exit 0
