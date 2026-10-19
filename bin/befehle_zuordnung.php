<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Abfuhrkalender (AWM & iCal).
 *
 * Gruppe 1 / MQTT (1.4.25; Bauvorschrift Nachtrag 19.10.2026; Entwurf Musterprojekt/entwurf_gruppe1_ab.txt
 * Abschnitt 3, E13; Muster vb_g1_bruecke_bau/beispiel/bin/befehle_zuordnung.php). Die Bruecke selbst
 * (bin/befehlsbruecke.php und .sh) ist byte-gleich die Stammfassung Werkzeuge/gemeinsam/ - linieneigen ist nur diese
 * Datei.
 *
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts.
 * 'tun' ruft DIESELBE Funktion wie der HTTP-Endpunkt awm.php - awm_aktion() in awm_lib.php - mit Quelle 'mqtt' und
 * gibt array(bool ok, string grund) zurueck. Die Token-Pruefung des Endpunkts ersetzt der Haken "Befehle ueber MQTT
 * annehmen" (Entwurf gemeinsam E13).
 *
 * Die vier Befehle sind Pulse (nur "1"; Loxone sendet feste 1, Nachtrag 19.10.2026), alle harmlos - kein zweiter
 * Haken (Entwurf ab, Abschnitt 3). Kalender: die Bruecke abonniert nur <praefix>/befehl/# des GRUNDpraefixes
 * (Nachtrag 19.10.2026). testpush und tonne_draussen gelten fuer alle Kalender (ein Merker) und haben deshalb keine
 * Kalendernummer; ansage und erneuern gelten je Kalender: ohne Nummer Kalender 1, fuer Kalender 2 bis 4 mit der
 * Nummer als Ebene im Namen (<praefix>/befehl/2/ansage). Warum im Namen und nicht in der Nutzlast: ein Puls traegt
 * nur "1" (die Bruecke prueft das, und Loxone sendet eine feste 1); eine Nummer in der Nutzlast machte aus dem Puls
 * eine Zahl mit Bereich und aus "derselbe Befehl" zwei Befehle fuer die Sperrminute. Als Ebene bleibt jede
 * Kombination ein eigener Name mit eigener Sperrminute und eigener Quittung (<praefix>/befehl/2/ansage/quittung) -
 * so empfiehlt es auch der Bruecken-Bericht, offene Frage 4.
 */
if (!function_exists('awm_config')) {
    $awm_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $awm_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/awm_lib.php';
    }
    $awm_kand[] = dirname(__DIR__) . '/webfrontend/html/awm_lib.php';
    foreach ($awm_kand as $awm_k) {
        if (is_file($awm_k)) { require_once $awm_k; break; }
    }
}
// awm_aktion_ergebnis() der Linie -> array(ok, grund) der Bruecke
$awm_um = function ($r) {
    return array(!empty($r['ok']), isset($r['grund']) ? (string) $r['grund'] : '');
};
return array(
    'fassung' => 1,
    'linie'   => 'awmabfuhr',
    'plugin'  => 'Abfuhrkalender (AWM & iCal)',
    'geladen' => function_exists('awm_config') && function_exists('awm_aktion') && function_exists('awm_befehle_einstellungen'),
    'einstellungen' => function () {
        return awm_befehle_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        awm_log(($stufe === 'INFO' ? '' : $stufe . ': ') . $text);
    },
    // Kalender 2 bis 4 (AWM_MAX_KALENDER = 4); Kalender 1 hat den Namen ohne Nummer
    'teile' => array('kal' => '[2-4]'),
    'befehle' => array(
        'ansage'         => array('art' => 'puls', 'eingreifend' => false, 'titel' => 'Abfuhrkalender Ansage jetzt (MQTT)',
                                  'kachel' => 'BEFEHL.K_ANSAGE', 'quittung' => 'ausgefuehrt',
                                  'tun' => function ($w, $t) use ($awm_um) { return $awm_um(awm_aktion('say', 1, 'mqtt')); }),
        'testpush'       => array('art' => 'puls', 'eingreifend' => false, 'titel' => 'Abfuhrkalender Test-Pushnachricht (MQTT)',
                                  'kachel' => 'BEFEHL.K_TESTPUSH', 'quittung' => 'ausgefuehrt',
                                  'tun' => function ($w, $t) use ($awm_um) { return $awm_um(awm_aktion('ptest', 1, 'mqtt')); }),
        'tonne_draussen' => array('art' => 'puls', 'eingreifend' => false, 'titel' => 'Abfuhrkalender Tonne steht draußen (MQTT)',
                                  'kachel' => 'BEFEHL.K_TONNE', 'quittung' => 'ausgefuehrt',
                                  'tun' => function ($w, $t) use ($awm_um) { return $awm_um(awm_aktion('ack', 1, 'mqtt')); }),
        'erneuern'       => array('art' => 'puls', 'eingreifend' => false, 'titel' => 'Abfuhrkalender Kalender-Link erneuern (MQTT)',
                                  'kachel' => 'BEFEHL.K_ERNEUERN', 'quittung' => 'ausgefuehrt',
                                  'tun' => function ($w, $t) use ($awm_um) { return $awm_um(awm_aktion('renew', 1, 'mqtt')); }),
        '{kal}/ansage'   => array('art' => 'puls', 'eingreifend' => false, 'titel' => 'Abfuhrkalender Ansage Kalender n (MQTT)',
                                  'kachel' => 'BEFEHL.K_ANSAGE_N', 'quittung' => 'ausgefuehrt',
                                  'tun' => function ($w, $t) use ($awm_um) { return $awm_um(awm_aktion_kalender('say', $t['kal'])); }),
        '{kal}/erneuern' => array('art' => 'puls', 'eingreifend' => false, 'titel' => 'Abfuhrkalender Kalender-Link erneuern Kalender n (MQTT)',
                                  'kachel' => 'BEFEHL.K_ERNEUERN_N', 'quittung' => 'ausgefuehrt',
                                  'tun' => function ($w, $t) use ($awm_um) { return $awm_um(awm_aktion_kalender('renew', $t['kal'])); }),
    ),
);
