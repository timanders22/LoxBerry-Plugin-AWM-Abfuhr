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
    echo "<WARNING> Es wurde nichts zurueckgespielt."
    exit 1
fi

if [ -n "$ARGV6" ] && [ -d "$ARGV6" ]; then
    TMPF="$ARGV6"
else
    TMPF="$PWD/$ARGV1"
fi

CFGDIR="$BASE/config/plugins/$PFOLDER"
LOGDIR="$BASE/log/plugins/$PFOLDER"
DATDIR="$BASE/data/plugins/$PFOLDER"
mkdir -p "$CFGDIR" "$LOGDIR" "$DATDIR" 2>/dev/null
# Dieselbe Frage wie am Ende von postinstall.sh: mindestens ein Kalender mit
# Adresse oder hochgeladener Datei?
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
AWM_VORHER=0; awm_eingerichtet "$CFGDIR/awm.json" && AWM_VORHER=1
AWM_GESICHERT=0; awm_eingerichtet "$TMPF/awm.json" && AWM_GESICHERT=1

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
# Traegt die Zweitschrift Inhalt? Lesbares JSON-Objekt UND Aktionstoken oder
# ein eingerichteter Kalender - dieselbe Frage wie awm_zweitschrift_hat_inhalt()
# in webfrontend/html/awm_lib.php. Bis 1.4.12 wurde sie ungeprueft kopiert,
# und postinstall.sh meldete "wiederhergestellt" auch fuer eine kaputte oder
# leere Datei (in WSL gemessen, Pruefung-AWM-Abfuhr-1.4.13, Faelle N1a, N1b,
# N3). Ohne PHP gilt sie als ohne Inhalt; das meldet postinstall.sh ohnehin.
awm_hat_inhalt() {
    [ -s "$1" ] || return 1
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        if (!is_array($d)) { exit(1); }
        if (isset($d["aktionstoken"]) && is_string($d["aktionstoken"]) && trim($d["aktionstoken"]) !== "") { exit(0); }
        if (isset($d["ical_url"]) && is_string($d["ical_url"]) && trim($d["ical_url"]) !== "") { exit(0); }
        foreach ((isset($d["cals"]) && is_array($d["cals"])) ? $d["cals"] : array() as $c) {
            if (!is_array($c)) { continue; }
            if (isset($c["url"]) && is_string($c["url"]) && trim($c["url"]) !== "") { exit(0); }
            if (!empty($c["hochgeladen"])) { exit(0); }
        }
        exit(1);' "$1" 2>/dev/null
}
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$CFGDIR/awm.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || ! grep -q '"' "$CF" 2>/dev/null; then
        if awm_hat_inhalt "$BK"; then
            cp -p "$BK" "$CF"
        else
            echo "<WARNING> Die Sicherung $PFOLDER.backup.json traegt keinen Inhalt - nicht uebernommen."
        fi
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
# mqtt_sig_*.txt gibt es seit 1.4.9 nicht mehr (die Signatur ueber ALLE Werte
# ist durch den Vergleich je Thema ersetzt); mqtt_letzte_*.json ist der neue
# Merker und wird hier ebenfalls weggeraeumt - damit schickt der erste
# Minutenlauf nach dem Update den VOLLEN Satz. Regeln/07: wer auf Retain
# umstellt oder die Themenmenge aendert, braucht danach einen Vollversand,
# sonst steht der halbe Zustand nicht im Broker.
rm -f /tmp/awmabfuhr/state_*.json /tmp/awmabfuhr/mqtt_sig_*.txt \
      /tmp/awmabfuhr/mqtt_letzte_*.json 2>/dev/null

# Die Konfiguration traegt das Aktionstoken und die iCal-Adresse mit Strasse
# und Hausnummer - uninstall/uninstall sagt das selbst. Sie gehoert deshalb
# auf 0600, wie bei Robonect und MG iSmart. Bis 1.4.6 setzte kein einziges
# Skript dieser Linie ein chmod, und die Datei stand auf 0664.
# Das Schlusswort zur Konfiguration steht hier nur, wenn postinstall.sh es
# hierher verwiesen hat: dort war awm.json noch nicht eingerichtet, die
# Ablage von preupgrade.sh aber schon.
if [ $AWM_VORHER = 0 ] && [ $AWM_GESICHERT = 1 ]; then
    if awm_eingerichtet "$CFGDIR/awm.json"; then
        echo "<OK> Aktualisierung abgeschlossen, Einstellungen uebernommen."
    else
        echo "<WARNING> Die Einstellungen liessen sich nicht zurueckholen."
        echo "<WARNING> Bitte Plugin-Oberflaeche oeffnen und iCal-URL eintragen."
    fi
fi

chmod 600 "$CFGDIR/awm.json" 2>/dev/null
[ -f "$BK" ] && chmod 600 "$BK" 2>/dev/null

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
