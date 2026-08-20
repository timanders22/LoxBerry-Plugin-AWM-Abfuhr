<?php
/**
 * Abfuhrkalender AWM Muenchen - minutlicher Cron-Lauf (via cron/cron.01min)
 *
 * 1. Kalender im konfigurierten Intervall abrufen (Standard: alle 14 Tage).
 * 2. Zustand aktualisieren und bei Aenderung (oder alle 30 min) per MQTT melden.
 * 3. Ansage am Vorabend und - falls eingeschaltet - am Abholmorgen ausloesen.
 * 4. Jahres-Erneuerung des Kalender-Links pruefen (hoechstens einmal taeglich).
 * 5. Alte Tages-Merker aufraeumen.
 */

require_once __DIR__ . '/awm_lib.php';

/* Nur ein Lauf gleichzeitig (Muster FerienFeiertage): der Abruf wartet je
 * Kalender bis zu 20 s - der naechste Minutenlauf soll nicht hineinlaufen. */
$awm_lock = awm_sperre('cron');
if ($awm_lock === false) {
    echo "BUSY\n";
    exit(0);
}

foreach (awm_cals() as $n => $c) {
    awm_fetch(false, $n);
    $st = awm_state(false, $n);
    /* Die Signatur entsteht aus DENSELBEN Werten, die auch verschickt
     * werden. Bis 1.3.8 stand hier eine eigene Zusammenstellung - und was
     * nicht in der Signatur steht, geht nicht raus, auch wenn es sich
     * geaendert hat. Genau daran lag es, dass ein gesetzter PTEST bis zum
     * halbstuendlichen Lebenszeichen liegenblieb, obwohl sein Fenster nur
     * fuenf Minuten breit ist.
     *
     * Mit awm_werte() als Quelle kann das nicht mehr passieren: jeder neue
     * Wert ist automatisch Teil der Signatur. */
    $sig = json_encode(awm_werte($st, $n));
    if ($sig === false) {
        // Waere es false, stimmte es mit keinem gespeicherten Stand ueberein
        // und das Plugin schickte jede Minute dieselben Werte ans Gateway.
        $sig = 'unlesbar';
    }
    $sigf = awm_tmpdir() . '/mqtt_sig_' . $n . '.txt';
    $beat = awm_tmpdir() . '/mqtt_beat_' . $n;
    $old = is_file($sigf) ? (string) file_get_contents($sigf) : '';
    if ($sig !== $old || !is_file($beat) || time() - filemtime($beat) > 1800) {
        awm_mqtt_publish($st, $n);
        awm_datei_schreiben($sigf, $sig);
        @touch($beat);
    }
}

awm_announce_check();
awm_renew_check();

/* Merker-Aufraeumen. Die Liste steht hier, damit sie beim naechsten neuen
 * Merker nicht vergessen wird - eine Datei je Tag summiert sich sonst still
 * auf der Ramdisk. */
foreach (array('announced_', 'announced2_', 'renew_', 'ack_', 'daytype_') as $awm_praefix) {
    foreach (glob(awm_tmpdir() . '/' . $awm_praefix . '*') ?: array() as $f) {
        // Alle diese Namen enden auf JJJJMMTT (daytype_ zusaetzlich auf .json).
        $name = basename($f);
        if (!preg_match('/(\d{8})(\.json)?$/', $name, $m)) { continue; }
        if ($m[1] < date('Ymd')) {
            @unlink($f);
        }
    }
}
/* Meldesperren laufen nach einem Tag ab - die Dateien bleiben sonst liegen. */
foreach (glob(awm_tmpdir() . '/meldung_*') ?: array() as $f) {
    if (time() - filemtime($f) > 172800) { @unlink($f); }
}

echo "OK\n";
