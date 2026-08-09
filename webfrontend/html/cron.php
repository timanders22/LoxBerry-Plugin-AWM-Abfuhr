<?php
/**
 * Abfuhrkalender AWM Muenchen - minutlicher Cron-Lauf (via cron/cron.01min)
 *
 * 1. Kalender im konfigurierten Intervall abrufen (Standard: alle 14 Tage).
 * 2. Zustand aktualisieren und bei Aenderung (oder alle 30 min) per MQTT melden.
 * 3. Ansage am Vorabend zur konfigurierten Uhrzeit ausloesen (einmal pro Tag).
 * 4. Jahres-Erneuerung des AWM-Links pruefen (hoechstens einmal taeglich).
 * 5. Alte Tages-Merker aufraeumen.
 */

require_once __DIR__ . '/awm_lib.php';

$sigall = array();
foreach (awm_cals() as $n => $c) {
    awm_fetch(false, $n);
    $st = awm_state(false, $n);
    $sigall[$n] = array($st['morgen'], $st['heute'], $st['tage'], $st['ok'], $st['warnung']);
    // MQTT je Kalender: bei Aenderung sofort, sonst alle 30 Minuten als Lebenszeichen
    //
    // Die Signatur enthaelt nur Zahlen, ist also nie von einem Zeichensatz
    // abhaengig. Trotzdem wird das Ergebnis geprueft: waere es false,
    // stimmte es mit keinem gespeicherten Stand ueberein und das Plugin
    // schickte jede Minute dieselben Werte ans MQTT-Gateway.
    $sig = json_encode($sigall[$n]);
    if ($sig === false) {
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

// Merker-Aufraeumen (aeltere announced_-/renew_-Dateien)
foreach (glob(awm_tmpdir() . '/announced_*') ?: array() as $f) {
    if (basename($f) !== 'announced_' . date('Ymd')) {
        @unlink($f);
    }
}
foreach (glob(awm_tmpdir() . '/renew_*') ?: array() as $f) {
    if (substr(basename($f), -8) !== date('Ymd')) {
        @unlink($f);
    }
}
echo "OK\n";
