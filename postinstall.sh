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

# Die Konfiguration traegt das Aktionstoken und die iCal-Adresse mit Strasse
# und Hausnummer - uninstall/uninstall sagt das selbst. Sie gehoert deshalb
# auf 0600, wie bei Robonect und MG iSmart. Bis 1.4.6 setzte kein einziges
# Skript dieser Linie ein chmod, und die Datei stand auf 0664.
chmod 600 "$CFGDIR/awm.json" 2>/dev/null
[ -f "$BK" ] && chmod 600 "$BK" 2>/dev/null

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

# Die Erstanleitung nur, wenn keine eingerichtete Konfiguration vorliegt.
# postinstall.sh laeuft auch bei jedem Upgrade; danach war der Rat falsch und
# legte nahe, die Einstellungen seien verloren. "Eingerichtet" heisst: es gibt
# mindestens einen Kalender mit Adresse oder hochgeladener Datei (dieselbe
# Frage wie awm_cals()), oder die alte Einzeladresse ical_url. Das
# Aktionstoken allein reicht nicht; es entsteht beim ersten Oeffnen der
# Oberflaeche ohne jeden Kalender. PHP ist hier sicher da (Pruefung oben).
# Gleichlautend in postupgrade.sh.
awm_eingerichtet() {
    [ -s "$1" ] || return 1
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        if (!is_array($d)) { exit(1); }
        if (isset($d["ical_url"]) && is_string($d["ical_url"]) && trim($d["ical_url"]) !== "") { exit(0); }
        foreach ((isset($d["cals"]) && is_array($d["cals"])) ? $d["cals"] : array() as $c) {
            if (!is_array($c)) { continue; }
            if (isset($c["url"]) && is_string($c["url"]) && trim($c["url"]) !== "") { exit(0); }
            if (!empty($c["hochgeladen"])) { exit(0); }
        }
        exit(1);' "$1" 2>/dev/null
}
# Die Ablage von preupgrade.sh - dieselbe Wahl wie dort und in postupgrade.sh.
if [ -n "$6" ] && [ -d "$6" ]; then AWM_TMPF="$6"; else AWM_TMPF="$PWD/$1"; fi
if awm_eingerichtet "$CF"; then
    echo "<OK> Installation abgeschlossen, Einstellungen uebernommen."
elif awm_eingerichtet "$AWM_TMPF/awm.json"; then
    # Ohne Zweitschrift holt erst postupgrade.sh die Konfiguration zurueck
    # und meldet dort, ob es gelang.
    echo "<OK> Installation abgeschlossen. Die Einstellungen holt postupgrade.sh gleich zurueck."
else
    echo "<OK> Installation abgeschlossen. Bitte Plugin-Oberflaeche oeffnen und iCal-URL eintragen."
fi
exit 0
