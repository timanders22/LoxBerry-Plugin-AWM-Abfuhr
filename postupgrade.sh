#!/bin/bash
# Abfuhrkalender AWM - postupgrade: Konfiguration + Log wiederherstellen
ARGV1=$1
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-awmabfuhr}"
BASE="${ARGV5:-$LBHOMEDIR}"
TMPF="$ARGV1"
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" "$BASE/data/plugins/$PFOLDER" 2>/dev/null
[ -f "$TMPF/awm.json" ] && cp -p "$TMPF/awm.json" "$BASE/config/plugins/$PFOLDER/awm.json"
[ -f "$TMPF/awm.log" ] && cp -p "$TMPF/awm.log" "$BASE/log/plugins/$PFOLDER/awm.log"
[ -f "$TMPF/kalender.ics" ] && cp -p "$TMPF/kalender.ics" "$BASE/data/plugins/$PFOLDER/kalender.ics"
# Fallback: externe Sicherung
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$BASE/config/plugins/$PFOLDER/awm.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$BK" "$CF"
    fi
fi
exit 0
