#!/bin/bash
# Abfuhrkalender AWM - preupgrade: Konfiguration, Protokoll und Kalender sichern
#
# Aufgerufen wird (plugininstall.pl):
#   cd "$tempfolder" && "$script" "$tempfile" "$pname" "$pfolder" "$pversion" "$lbhomedir" "$tempfolder"
#
# $1 IST KEIN PFAD. Es ist eine zehnstellige Zufallskennung aus &generate(10).
# Der absolute Arbeitsordner steht im SECHSTEN Argument. Bis 1.3.8 stand hier
# TMPF="$1" - das ging nur gut, weil der Installer vorher in den Arbeitsordner
# wechselt und der relative Name dort zufaellig landet. Wer den Aufruf einmal
# ohne dieses cd nachstellt, sichert ins Nichts, und der cp scheitert lautlos.
#
# ACHTUNG, hier steckte bis 1.2.0 ein zweiter Fehler mit stillen Folgen:
#
#   cp -p "$BASE/data/plugins/$PFOLDER/kalender.ics" ...
#
# Diese Datei gibt es seit 1.1.0 nicht mehr. Seit der Mehrkalender-Faehigkeit
# heissen sie kalender_1.ics bis kalender_4.ics. Der Befehl lief ins Leere -
# mit 2>/dev/null auch noch lautlos - und beim Update verschwanden alle
# Kalender. Normalerweise faellt das nicht auf, weil das Plugin innerhalb einer
# Minute neu abruft. Ausser der Link ist inzwischen abgelaufen, oder der
# Kalender wurde hochgeladen: dann sind die Termine weg und kein Abruf holt
# sie wieder.
ARGV1=$1
ARGV3=$3
ARGV5=$5
ARGV6=$6
PFOLDER="${ARGV3:-awmabfuhr}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Sechstes Argument, wenn es da ist; sonst der alte Weg ueber das
# Arbeitsverzeichnis, in dem der Installer uns startet.
if [ -n "$ARGV6" ] && [ -d "$ARGV6" ]; then
    TMPF="$ARGV6"
else
    TMPF="$PWD/$ARGV1"
fi

mkdir -p "$TMPF" 2>/dev/null

cp -p "$BASE/config/plugins/$PFOLDER/awm.json" "$TMPF/awm.json" 2>/dev/null
cp -p "$BASE/log/plugins/$PFOLDER/awm.log" "$TMPF/awm.log" 2>/dev/null

# Alle Kalender, egal wie viele
for f in "$BASE/data/plugins/$PFOLDER"/kalender_*.ics; do
    [ -f "$f" ] || continue
    cp -p "$f" "$TMPF/$(basename "$f")" 2>/dev/null
done

# Dauerhafte Sicherung ausserhalb des Plugin-Ordners. Sie ueberlebt auch ein
# Update, bei dem das Zwischenverzeichnis verlorengeht. NICHT unter
# data/plugins/<ordner> - genau das loescht der Installer im Schritt
# "Removing old installation", eine Sekunde nachdem preupgrade es hinschreibt.
BKDIR="$BASE/config/plugins/$PFOLDER.backup.ics"
mkdir -p "$BKDIR" 2>/dev/null
for f in "$BASE/data/plugins/$PFOLDER"/kalender_*.ics; do
    [ -f "$f" ] && [ -s "$f" ] || continue
    cp -p "$f" "$BKDIR/$(basename "$f")" 2>/dev/null
done

exit 0
