#!/bin/bash
# Abfuhrkalender AWM - preupgrade: Konfiguration + Log sichern
ARGV1=$1
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-awmabfuhr}"
BASE="${ARGV5:-$LBHOMEDIR}"
TMPF="$ARGV1"
mkdir -p "$TMPF" 2>/dev/null
cp -p "$BASE/config/plugins/$PFOLDER/awm.json" "$TMPF/awm.json" 2>/dev/null
cp -p "$BASE/log/plugins/$PFOLDER/awm.log" "$TMPF/awm.log" 2>/dev/null
cp -p "$BASE/data/plugins/$PFOLDER/kalender.ics" "$TMPF/kalender.ics" 2>/dev/null
exit 0
