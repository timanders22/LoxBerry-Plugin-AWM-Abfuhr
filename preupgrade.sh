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
# Die Wurzel: $5 (vom Installer) oder $LBHOMEDIR, wenn dort config/plugins
# und data/plugins liegen - sonst vom eigenen Ablageort AUFWAERTS SUCHEN, bis
# ein Verzeichnis config/plugins, data/plugins UND config/system/general.json
# traegt. Keine feste Ebenenzahl und kein fest verdrahteter Systempfad danach.
#
# Bis 1.4.12 stand hier nur BASE="${5:-$LBHOMEDIR}", ohne jede Pruefung. Ohne
# beides wurden die Pfade ab der Laufwerkswurzel gebildet (/config/plugins/...,
# /data/plugins/...), und das Skript meldete trotzdem <OK> bzw. <INFO> (in WSL
# gemessen, Pruefung-AWM-Abfuhr-1.4.13, Faelle H3 bis H12 und C11 bis C14).
# general.json ist die Bedingung aus dem Raumklima-Vorfall (Regeln/06): ein
# LoxBerry hat die Datei immer, ein Pruefstandsrest nie. Findet sich nichts,
# wird GEWARNT statt vollzogen. Bauart Spotpreis-Octopus 1.1.12.
awm_wurzel_suchen() {
    awm_v=$(cd "$1" 2>/dev/null && pwd -P) || return 1
    awm_i=0
    while [ -n "$awm_v" ] && [ "$awm_v" != "/" ] && [ "$awm_i" -lt 8 ]; do
        if [ -d "$awm_v/config/plugins" ] && [ -d "$awm_v/data/plugins" ] \
           && [ -f "$awm_v/config/system/general.json" ]; then
            echo "$awm_v"
            return 0
        fi
        awm_v=$(dirname "$awm_v")
        awm_i=$((awm_i + 1))
    done
    return 1
}
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    BASE=$(awm_wurzel_suchen "$(dirname "$(readlink -f "$0")")") || BASE=""
fi
if [ -z "$BASE" ]; then
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden: weder als"
    echo "<WARNING> fuenftes Argument noch in \$LBHOMEDIR, und oberhalb von"
    echo "<WARNING> $(dirname "$(readlink -f "$0")") traegt kein Verzeichnis"
    echo "<WARNING> config/plugins, data/plugins und config/system/general.json."
    echo "<WARNING> Es wurde nichts gesichert."
    exit 1
fi

# ---------- I1: Marke "Aktualisierung laeuft" - als Erstes ----------
# Entscheidung 1 (29.09.2026): postinstall.sh spielt Zweitschrift und
# Kalenderbestand NUR zurueck, wenn diese Marke liegt - ohne Altersvergleich.
# Bis 1.4.14 gab es sie nicht, und eine Neuinstallation holte Aktionstoken und
# Kalenderadresse einer frueheren Installation zurueck (Installer-Pruefer,
# Fall D). cron.php laesst den Takt aus, solange sie liegt (M10); postupgrade.sh
# raeumt sie ab. Sie liegt NEBEN dem Datenordner, weil purge_installation den
# Ordner selbst loescht. Laesst sie sich nicht anlegen, hielte postinstall.sh
# das Update fuer eine Neuinstallation und legte die Einstellungen beiseite -
# deshalb Abbruch mit rc 2, vor purge_installation (Bauform KODI-NG 1.2.12,
# APC-UPS 1.2.14). Vorher festhalten, ob schon eine Marke lag: dann hat ein
# frueherer Versuch DIESES Updates abgebrochen (I3).
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
AWM_MARKE_VORHER=0
[ -f "$MARKE" ] && AWM_MARKE_VORHER=1
mkdir -p "$BASE/data/plugins" 2>/dev/null
# In geschweiften Klammern: sonst schreibt die Schale ihre eigene Meldung
# ("cannot create ...") am 2>/dev/null vorbei ins Protokoll.
{ date +%s > "$MARKE"; } 2>/dev/null
if ! grep -qx '[0-9][0-9]*' "$MARKE" 2>/dev/null; then
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen."
    echo "<FAIL> Ohne sie hielte postinstall.sh dieses Update fuer eine Neuinstallation"
    echo "<FAIL> und legte die Einstellungen beiseite. Die Aktualisierung wird"
    echo "<FAIL> abgebrochen; die bisherige Fassung bleibt unveraendert installiert."
    exit 2
fi

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
# I3 (Entscheidung 1): einen alten Bestand wegraeumen, BEVOR der neue angelegt
# wird. Bis 1.4.14 blieb eine kalender_3.ics aus einem frueheren Vorgang in
# $BKDIR liegen und kam mit dem Update zurueck - awm.php?cal=3 meldete danach
# OK=1 mit einem Termin von 2024 (Installer-Pruefer, Fall E). Ausnahme: die
# Marke lag schon vor diesem Lauf (abgebrochener Versuch dieses Updates) -
# dann ist der Bestand dessen Abschrift und bleibt.
if [ "$AWM_MARKE_VORHER" != "1" ] && [ -d "$BKDIR" ]; then
    for f in "$BKDIR"/kalender_*.ics; do
        { [ -e "$f" ] || [ -L "$f" ]; } || continue
        rm -f "$f" 2>/dev/null
    done
fi
mkdir -p "$BKDIR" 2>/dev/null
for f in "$BASE/data/plugins/$PFOLDER"/kalender_*.ics; do
    [ -f "$f" ] && [ -s "$f" ] || continue
    cp -p "$f" "$BKDIR/$(basename "$f")" 2>/dev/null
done

exit 0
