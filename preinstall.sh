#!/bin/bash
# Abfuhrkalender AWM - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# X-1 (Entscheidung 1 vom 29.09.2026, Nachzug 01.10.2026). Der Installer ruft
# dieses Skript bei JEDEM Einbau auf, nach dem Aufraeumen der alten Fassung
# und VOR dem Kopieren von Konfiguration, Cron-Datei und Oberflaeche
# (sbin/plugininstall.pl: preupgrade :846, purge :874, preinstall :877,
# Cron :990, HTML :1066 - Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: Zweitschrift und
# Kalenderbestand werden von postinstall.sh zurueckgespielt.
#
# Ohne Marke ist es eine NEUINSTALLATION. Eine liegengebliebene Zweitschrift
# (config/plugins/<ordner>.backup.json) und ein liegengebliebener
# Kalenderbestand (config/plugins/<ordner>.backup.ics) einer frueheren
# Installation gehen nach <name>.alt, gemeldet mit genau einer <WARNING>.
# Bis 1.4.16 tat das erst postinstall.sh - und die Cron-Datei liegt am Geraet
# vor postinstall.sh: ein Minutentakt dazwischen las die Zweitschrift der
# frueheren Installation (awm_config(), Nur-Lese-Weg) und arbeitete mit ihrem
# Aktionstoken und ihrer Kalenderadresse (in WSL gemessen, B-Nachzug
# 01.10.2026). Die Bibliothek liest .alt nie; die Deinstallation raeumt es ab.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-awmabfuhr}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Die Wurzel wie in preupgrade.sh, postinstall.sh und postupgrade.sh: $5 oder
# $LBHOMEDIR, sonst vom eigenen Ablageort aufwaerts bis zu einem Verzeichnis
# mit config/plugins, data/plugins UND config/system/general.json (Regeln/06,
# Raumklima-Vorfall). Findet sich nichts, wird nichts angefasst.
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
if [ -z "$BASE" ] || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv/rm daneben.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    # Aktualisierung: nichts zu tun, postinstall.sh spielt zurueck.
    exit 0
fi

BK="$BASE/config/plugins/$PFOLDER.backup.json"
BKDIR="$BASE/config/plugins/$PFOLDER.backup.ics"
AWM_BEISEITE=""
AWM_FEST=""
for ZIEL in "$BK" "$BKDIR"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            AWM_BEISEITE="$AWM_BEISEITE $ZIEL.alt"
        else
            AWM_FEST="$AWM_FEST $ZIEL"
        fi
    fi
done
# Die Zweitschrift traegt Aktionstoken und Kalenderadresse - auch beiseite 0600.
[ -f "$BK.alt" ] && [ ! -L "$BK.alt" ] && chmod 600 "$BK.alt" 2>/dev/null

if [ -n "$AWM_BEISEITE" ] || [ -n "$AWM_FEST" ]; then
    AWM_TEXT="<WARNING> Neuinstallation: gesicherte Einstellungen und Kalender einer frueheren Installation werden NICHT eingespielt."
    [ -n "$AWM_BEISEITE" ] && AWM_TEXT="$AWM_TEXT Beiseitegelegt:$AWM_BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$AWM_FEST" ] && AWM_TEXT="$AWM_TEXT Nicht zu verschieben, bitte von Hand entfernen:$AWM_FEST"
    echo "$AWM_TEXT"
fi
exit 0
