#!/bin/bash
# Abfuhrkalender AWM - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-awmabfuhr}"
BASE="${ARGV5:-$LBHOMEDIR}"

CFGDIR="$BASE/config/plugins/$PFOLDER"
LOGDIR="$BASE/log/plugins/$PFOLDER"
DATDIR="$BASE/data/plugins/$PFOLDER"
mkdir -p "$CFGDIR" "$LOGDIR" "$DATDIR" 2>/dev/null

if [ ! -f "$CFGDIR/awm.json" ]; then
    echo '{}' > "$CFGDIR/awm.json"
fi

# Konfiguration aus Sicherung wiederherstellen (uebersteht Updates UND
# Neuinstallation). Geprueft wird, ob ueberhaupt ein Schluessel in der Datei
# steht - der Textvergleich gegen genau "{}" liess bis 1.2.0 jede Variante
# mit Leerzeichen oder Zeilenumbruch durchgehen.
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$CFGDIR/awm.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || ! grep -q '"' "$CF" 2>/dev/null; then
        cp -p "$BK" "$CF"
        echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi

# Kalender aus der dauerhaften Sicherung zurueckholen, falls keiner da ist.
BKDIR="$BASE/config/plugins/$PFOLDER.backup.ics"
if [ -d "$BKDIR" ] && [ -z "$(ls -A "$DATDIR"/kalender_*.ics 2>/dev/null)" ]; then
    for f in "$BKDIR"/kalender_*.ics; do
        [ -f "$f" ] && [ -s "$f" ] || continue
        cp -p "$f" "$DATDIR/$(basename "$f")"
        echo "<OK> Kalender $(basename "$f") aus Sicherung wiederhergestellt."
    done
fi

# Eigentuemer: die Installation laeuft als root, der Betrieb als loxberry.
if id loxberry >/dev/null 2>&1; then
    chown -R loxberry:loxberry "$CFGDIR" "$LOGDIR" "$DATDIR" 2>/dev/null
    [ -f "$BK" ] && chown loxberry:loxberry "$BK" 2>/dev/null
    [ -d "$BKDIR" ] && chown -R loxberry:loxberry "$BKDIR" 2>/dev/null
fi

if ! command -v php >/dev/null 2>&1; then
    echo "<FAIL> PHP wurde nicht gefunden. Ohne PHP laeuft weder der Cron-Lauf noch der Miniserver-Endpunkt."
    exit 1
fi

echo "<OK> Installation abgeschlossen. Bitte Plugin-Oberflaeche oeffnen und iCal-URL eintragen."
exit 0
