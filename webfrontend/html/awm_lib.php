<?php
/**
 * Abfuhrkalender AWM Muenchen - gemeinsame Bibliothek
 *
 * Laedt iCal-/ICS-Exporte von Abfuhrkalendern (AWM Muenchen oder andere
 * Anbieter, solange die Termin-Titel die Tonnen-Namen enthalten), rollt
 * Serientermine selbst aus (RRULE FREQ=WEEKLY + INTERVAL + UNTIL + EXDATE +
 * einzelne "Achtung"-Verschiebungstermine) und liefert:
 *   - Loxone-Textzeile (kompatibel zum klassischen muell.php-Format)
 *   - bis zu 2 Kalender (z. B. Zuhause + Ferienhaus), Auswahl per &cal=2
 *   - JSON-Zustand, MQTT ueber das LoxBerry MQTT Gateway
 *   - Vorabend-Ansage (TTS) und Push-Freigabe wie beim Abfahrtsassistenten
 *   - automatische Jahres-Erneuerung des AWM-Links (Jahr-Tausch + Scraping)
 *
 * Keine persoenlichen Daten im Code - Adressen/Kalender stecken ausschliesslich
 * in den lokal konfigurierten iCal-URLs.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('Europe/Berlin');

function awm_paths() {
    $lbhomedir = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
    $plugindir = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
    if ($lbhomedir && is_dir($lbhomedir . '/config/plugins/' . $plugindir) === false) {
        $plugindir = 'awmabfuhr';
    }
    if ($lbhomedir) {
        return array(
            'config' => $lbhomedir . '/config/plugins/' . $plugindir . '/awm.json',
            'backup' => $lbhomedir . '/config/plugins/' . $plugindir . '.backup.json',
            'log' => $lbhomedir . '/log/plugins/' . $plugindir . '/awm.log',
            'data' => $lbhomedir . '/data/plugins/' . $plugindir,
            'tmp' => '/tmp/awmabfuhr',
            'lbhome' => $lbhomedir,
        );
    }
    return array(
        'config' => dirname(dirname(__DIR__)) . '/config/awm.json',
        'backup' => dirname(dirname(__DIR__)) . '/config/awm.backup.json',
        'log' => sys_get_temp_dir() . '/awmabfuhr/awm.log',
        'data' => sys_get_temp_dir() . '/awmabfuhr/data',
        'tmp' => sys_get_temp_dir() . '/awmabfuhr',
        'lbhome' => '',
    );
}

function awm_config() {
    $p = awm_paths();
    // Selbstheilung: fehlende/leere Konfiguration aus Sicherung wiederherstellen
    if ((!is_file($p['config']) || trim((string) @file_get_contents($p['config'])) === '' || trim((string) @file_get_contents($p['config'])) === '{}') && is_file($p['backup'])) {
        @mkdir(dirname($p['config']), 0775, true);
        @copy($p['backup'], $p['config']);
    }
    $cfg = is_file($p['config']) ? (json_decode((string) file_get_contents($p['config']), true) ?: array()) : array();
    if (!is_array($cfg)) {
        $cfg = array();
    }
    $cfg += array(
        'cals' => array(),
        'fetch_days' => 14,        // Abruf-Intervall in TAGEN (Kalender aendern sich selten)
        'lookahead' => 35,         // Vorschau-Fenster in Tagen
        'autorenew' => 1,          // AWM-Link zum Jahreswechsel automatisch erneuern (Best-Effort)
        'mqtt_enabled' => 0,
        'mqtt_topic' => 'awm',
        'notify' => array(),
        'tts' => array(),
    );
    if (!is_array($cfg['cals'])) { $cfg['cals'] = array(); }
    // Migration alter Konfigurationen (Einzel-URL / tts.enabled / fetch_hours)
    if (empty($cfg['cals']) && !empty($cfg['ical_url'])) {
        $cfg['cals'] = array(array('name' => 'Zuhause', 'url' => (string) $cfg['ical_url']));
    }
    if (!is_array($cfg['notify'])) { $cfg['notify'] = array(); }
    if (!is_array($cfg['tts'])) { $cfg['tts'] = array(); }
    if (isset($cfg['tts']['enabled']) && !isset($cfg['notify']['audio'])) {
        $cfg['notify']['audio'] = (int) $cfg['tts']['enabled'];
    }
    if (isset($cfg['tts']['time']) && !isset($cfg['notify']['time'])) {
        $cfg['notify']['time'] = (string) $cfg['tts']['time'];
    }
    if (isset($cfg['tts']['system']) && !isset($cfg['tts']['mode'])) {
        $cfg['tts']['mode'] = $cfg['tts']['system'] === 'custom' ? 'custom' : 'musicserver';
    }
    $cfg['notify'] += array('audio' => 1, 'push' => 1, 'time' => '18:00');
    $cfg['tts'] += array('mode' => 'musicserver', 'ip' => '', 'port' => 7091,
                         'zones' => '1', 'volume' => 20, 'lang' => 'de', 'template' => '');
    return $cfg;
}

/** Kalenderliste (nur Eintraege mit URL), 1-basiert. */
function awm_cals() {
    $cfg = awm_config();
    $out = array();
    $n = 0;
    foreach ((array) $cfg['cals'] as $c) {
        $c = (array) $c;
        if (trim((string) (isset($c['url']) ? $c['url'] : '')) === '') {
            continue;
        }
        $n++;
        $out[$n] = array(
            'name' => trim((string) (isset($c['name']) ? $c['name'] : '')) !== '' ? trim((string) $c['name']) : ('Kalender ' . $n),
            'url' => trim((string) $c['url']),
        );
    }
    return $out;
}

function awm_cal($n) {
    $cals = awm_cals();
    $n = max(1, (int) $n);
    return isset($cals[$n]) ? $cals[$n] : null;
}

function awm_tmpdir() {
    $p = awm_paths();
    if (!is_dir($p['tmp'])) {
        @mkdir($p['tmp'], 0775, true);
    }
    return $p['tmp'];
}

function awm_datadir() {
    $p = awm_paths();
    if (!is_dir($p['data'])) {
        @mkdir($p['data'], 0775, true);
    }
    return $p['data'];
}

/* ---------------- Protokoll ---------------- */

function awm_log($msg) {
    $p = awm_paths();
    $f = $p['log'];
    $dir = dirname($f);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (is_file($f) && filesize($f) > 512000) { // Rotation: letzte 200 Zeilen behalten
        $tail = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), -200);
        @file_put_contents($f, implode("\n", $tail) . "\n");
    }
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function awm_log_if_changed($key, $line) {
    $f = awm_tmpdir() . '/last_' . $key . '.txt';
    $prev = is_file($f) ? (string) file_get_contents($f) : '';
    if ($line !== $prev) {
        awm_log($key . ': ' . $line);
        @file_put_contents($f, $line);
    }
}

/* ---------------- HTTP-Hilfe ---------------- */

function awm_http_get($url, $tmo = 20) {
    $ctx = stream_context_create(array('http' => array(
        'timeout' => $tmo,
        'user_agent' => 'Mozilla/5.0 (LoxBerry Abfuhrkalender)',
        'follow_location' => 1,
    ), 'ssl' => array('verify_peer' => true)));
    return @file_get_contents($url, false, $ctx);
}

/* ---------------- ICS holen ---------------- */

function awm_icsfile($cal = 1) {
    return awm_datadir() . '/kalender_' . max(1, (int) $cal) . '.ics'; // persistent
}

/** Kalender laden, wenn aelter als Intervall (oder $force). Rueckgabe: [ok, quelle]. */
function awm_fetch($force = false, $cal = 1) {
    $cfg = awm_config();
    $c = awm_cal($cal);
    $f = awm_icsfile($cal);
    if ($c === null) {
        return array(is_file($f) ? 1 : 0, 'keine URL konfiguriert');
    }
    $maxage = max(1, (int) $cfg['fetch_days']) * 86400;
    if (!$force && is_file($f) && time() - filemtime($f) < $maxage) {
        return array(1, 'cache');
    }
    $neu = awm_http_get($c['url']);
    if ($neu !== false && strpos($neu, 'BEGIN:VCALENDAR') !== false) {
        file_put_contents($f, $neu);
        @unlink(awm_tmpdir() . '/state_' . (int) $cal . '.json');
        awm_log('Kalender ' . $cal . ' (' . $c['name'] . ') abgerufen: ' . strlen($neu) . ' Bytes, ' . substr_count($neu, 'BEGIN:VEVENT') . ' Ereignisse');
        return array(1, 'frisch');
    }
    if (is_file($f)) {
        @touch($f); // nicht jede Minute erneut versuchen
        awm_log('Kalender ' . $cal . ': Abruf fehlgeschlagen - nutze letzten gespeicherten Stand');
        return array(1, 'cache-fallback');
    }
    awm_log('Kalender ' . $cal . ': Abruf FEHLGESCHLAGEN und kein Stand gespeichert');
    return array(0, 'FEHLGESCHLAGEN');
}

/* ---------------- ICS auswerten ---------------- */

/** Serien einlesen (inkl. Einzeltermine, z. B. "Achtung: ..."-Verschiebungen). */
function awm_series($cal = 1) {
    $f = awm_icsfile($cal);
    if (!is_file($f)) {
        return array();
    }
    $ics = (string) file_get_contents($f);
    $ics = preg_replace("/\r?\n[ \t]/", '', $ics); // Zeilenfaltung entfalten
    $serien = array();
    $parts = explode('BEGIN:VEVENT', $ics);
    foreach ($parts as $i => $ev) {
        if ($i === 0) {
            continue;
        }
        if (!preg_match('/DTSTART[^:\r\n]*:(\d{8})/', $ev, $md)) {
            continue;
        }
        $s = array('start' => $md[1], 'summary' => '', 'freq' => null, 'interval' => 1,
                   'until' => '99991231', 'exdates' => array(), 'desc' => '');
        if (preg_match('/SUMMARY[^:\r\n]*:([^\r\n]+)/', $ev, $ms)) {
            $s['summary'] = str_replace(array('\\,', '\\;', '\\n'), array(',', ';', ' '), trim($ms[1]));
        }
        if (preg_match('/DESCRIPTION[^:\r\n]*:([^\r\n]+)/', $ev, $mdx)) {
            $s['desc'] = str_replace(array('\\,', '\\;', '\\n'), array(',', ';', ' '), trim($mdx[1]));
        }
        if (preg_match('/RRULE:([^\r\n]+)/', $ev, $mr)) {
            $r = $mr[1];
            if (preg_match('/FREQ=(\w+)/', $r, $m2)) { $s['freq'] = $m2[1]; }
            if (preg_match('/INTERVAL=(\d+)/', $r, $m2)) { $s['interval'] = max(1, (int) $m2[1]); }
            if (preg_match('/UNTIL=(\d{8})/', $r, $m2)) { $s['until'] = $m2[1]; }
        }
        if (preg_match_all('/EXDATE[^:\r\n]*:(\d{8})/', $ev, $mx)) {
            $s['exdates'] = $mx[1];
        }
        $serien[] = $s;
    }
    return $serien;
}

/** Holt eine Serie am Datum (Ymd) ab? */
function awm_pickup($s, $ymd) {
    if (in_array($ymd, $s['exdates'])) {
        return false;
    }
    if ($s['freq'] === null) {
        return $s['start'] === $ymd;    // Einzeltermin (z. B. "Achtung: ...")
    }
    if ($s['freq'] !== 'WEEKLY') {
        return $s['start'] === $ymd;    // andere Frequenzen: nur Basistermin
    }
    if ($ymd < $s['start'] || $ymd > $s['until']) {
        return false;
    }
    $a = DateTime::createFromFormat('Ymd', $s['start']);
    $b = DateTime::createFromFormat('Ymd', $ymd);
    if (!$a || !$b) {
        return false;
    }
    $tage = (int) $a->diff($b)->format('%a');
    if ($tage % 7 !== 0) {
        return false;                    // gleicher Wochentag wie der Basistermin
    }
    return (($tage / 7) % $s['interval']) === 0;
}

/** Tonnen-Erkennung aus dem Termin-Titel. */
function awm_bins_of($summary) {
    $l = function_exists('mb_strtolower') ? mb_strtolower($summary, 'UTF-8') : strtolower($summary);
    $b = array('rest' => 0, 'bio' => 0, 'papier' => 0, 'wert' => 0);
    if (strpos($l, 'rest') !== false || strpos($l, 'hausm') !== false) { $b['rest'] = 1; }
    if (strpos($l, 'bio') !== false) { $b['bio'] = 1; }
    if (strpos($l, 'papier') !== false) { $b['papier'] = 1; }
    if (strpos($l, 'wertstoff') !== false || strpos($l, 'gelbe') !== false) { $b['wert'] = 1; }
    return $b;
}

/** Welche Tonnen werden am Datum (Ymd) abgeholt? */
function awm_bins($serien, $ymd) {
    $t = array('rest' => 0, 'bio' => 0, 'papier' => 0, 'wert' => 0, 'texte' => array());
    foreach ($serien as $s) {
        if (!awm_pickup($s, $ymd)) {
            continue;
        }
        $b = awm_bins_of($s['summary']);
        foreach (array('rest', 'bio', 'papier', 'wert') as $k) {
            if ($b[$k]) { $t[$k] = 1; }
        }
        $t['texte'][] = $s['summary'];
    }
    $t['texte'] = array_values(array_unique($t['texte']));
    return $t;
}

/** Kompletter Zustand eines Kalenders (mit Cache 10 min). */
function awm_state($force = false, $cal = 1) {
    $cfg = awm_config();
    $cal = max(1, (int) $cal);
    $cache = awm_tmpdir() . '/state_' . $cal . '.json';
    $icst = is_file(awm_icsfile($cal)) ? filemtime(awm_icsfile($cal)) : 0;
    if (!$force && is_file($cache) && time() - filemtime($cache) < 600 && filemtime($cache) >= $icst) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (is_array($c) && isset($c['datum']) && $c['datum'] === date('Ymd')) {
            return $c;
        }
    }
    $serien = awm_series($cal);
    $st = array(
        'ok' => $serien ? 1 : 0,
        'cal' => $cal,
        'datum' => date('Ymd'),
        'heute' => awm_bins($serien, date('Ymd')),
        'morgen' => awm_bins($serien, date('Ymd', strtotime('+1 day'))),
        'tage' => array('rest' => -1, 'bio' => -1, 'papier' => -1, 'wert' => -1),
        'naechste' => array('rest' => '', 'bio' => '', 'papier' => '', 'wert' => ''),
        'termine' => array(),
        'hinweis' => '',
        'warnung' => 0,
        'letzter' => '',
        'ereignisse' => count($serien),
        'ts' => time(),
    );
    $look = max(7, min(90, (int) $cfg['lookahead']));
    $lastterm = '';
    for ($d = 0; $d <= $look; $d++) {
        $ymd = date('Ymd', strtotime("+$d day"));
        $x = awm_bins($serien, $ymd);
        foreach (array('rest', 'bio', 'papier', 'wert') as $k) {
            if ($x[$k] && $st['tage'][$k] < 0) {
                $st['tage'][$k] = $d;
                $st['naechste'][$k] = $ymd;
            }
        }
        if ($x['texte']) {
            $st['termine'][] = array($ymd, implode(' | ', $x['texte']));
            $lastterm = $ymd;
        }
    }
    // Feiertags-/Verschiebungs-Hinweis ("Achtung"-Termine der naechsten 14 Tage)
    foreach ($serien as $s) {
        if (stripos($s['summary'], 'achtung') !== false && $s['freq'] === null) {
            $diff = (strtotime(substr($s['start'], 0, 4) . '-' . substr($s['start'], 4, 2) . '-' . substr($s['start'], 6, 2)) - strtotime('today')) / 86400;
            if ($diff >= 0 && $diff <= 14) {
                $st['hinweis'] = trim($s['summary']) . ($s['desc'] !== '' ? ' - ' . $s['desc'] : '');
                break;
            }
        }
    }
    // Jahreswechsel-Warnung: Kalender liefert bald keine Termine mehr
    $st['letzter'] = $lastterm;
    $future = false;
    foreach ($serien as $s) {
        $end = $s['freq'] === null ? $s['start'] : min($s['until'], '99991231');
        if ($end >= date('Ymd', strtotime('+30 days'))) {
            $future = true;
            break;
        }
    }
    if ($st['ok'] && !$future) {
        $st['warnung'] = 1; // in <30 Tagen keine Termine mehr -> Link erneuern (Jahreswechsel)
    }
    file_put_contents($cache, json_encode($st));
    awm_log_if_changed('zustand_' . $cal, 'morgen R=' . $st['morgen']['rest'] . ' B=' . $st['morgen']['bio'] . ' P=' . $st['morgen']['papier'] . ' W=' . $st['morgen']['wert'] . ' | Warnung=' . $st['warnung']);
    return $st;
}

/* ---------------- Ansage-/Push-Fenster (fuer Loxone) ---------------- */

/** 1 innerhalb von 10 Minuten ab der Erinnerungs-Uhrzeit, wenn morgen etwas faellig ist. */
function awm_ann_active($st = null) {
    $cfg = awm_config();
    if ($st === null) {
        $st = awm_state();
    }
    $m = $st['morgen'];
    if (!($m['rest'] || $m['bio'] || $m['papier'] || $m['wert'])) {
        return 0;
    }
    $when = preg_match('/^\d{1,2}:\d{2}$/', (string) $cfg['notify']['time']) ? $cfg['notify']['time'] : '18:00';
    list($hh, $mm) = explode(':', $when);
    $start = mktime((int) $hh, (int) $mm, 0);
    return (time() >= $start && time() < $start + 600) ? 1 : 0;
}

/** Test-Push-Merker (5 Minuten nach Klick auf "Test-Pushnachricht"). */
function awm_ptest_active() {
    $f = awm_tmpdir() . '/ptest';
    return (is_file($f) && time() - filemtime($f) < 300) ? 1 : 0;
}

/* ---------------- MQTT (LoxBerry MQTT Gateway, UDP-Relay) ---------------- */

function awm_mqtt_publish($st = null, $cal = 1) {
    $cfg = awm_config();
    if (empty($cfg['mqtt_enabled'])) {
        return;
    }
    $p = awm_paths();
    if ($p['lbhome'] === '') {
        return;
    }
    if ($st === null) {
        $st = awm_state(false, $cal);
    }
    $gen = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
    $udpport = 0;
    if (isset($gen['Mqtt']['Udpinport'])) { $udpport = (int) $gen['Mqtt']['Udpinport']; }
    if (!$udpport && isset($gen['mqtt']['udpinport'])) { $udpport = (int) $gen['mqtt']['udpinport']; }
    if (!$udpport) {
        return; // MQTT-Gateway nicht eingerichtet
    }
    $prefix = trim((string) $cfg['mqtt_topic']) !== '' ? trim((string) $cfg['mqtt_topic']) : 'awm';
    if ((int) $cal > 1) { // Kalender 1 behaelt die kurzen Topics
        $prefix .= '/' . (int) $cal;
    }
    $msgs = array(
        'ok' => $st['ok'],
        'warnung' => $st['warnung'],
        'hinweis' => $st['hinweis'] !== '' ? $st['hinweis'] : '-',
    );
    foreach (array('rest', 'bio', 'papier', 'wert') as $k) {
        $msgs[$k . '_morgen'] = $st['morgen'][$k];
        $msgs[$k . '_heute'] = $st['heute'][$k];
        $msgs['tage_' . $k] = $st['tage'][$k];
        $msgs['datum_' . $k] = $st['naechste'][$k] !== '' ? $st['naechste'][$k] : '-';
    }
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) {
        return;
    }
    foreach ($msgs as $k => $v) {
        $msg = 'publish ' . $prefix . '/' . $k . ' ' . $v;
        @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $udpport);
    }
    socket_close($s);
}

/* ---------------- Ansage (TTS) - identisch zum Abfahrtsassistenten ---------------- */

/** TTS-URL fuer die konfigurierte Ausgabe bauen. Fuer mode=audioserver: null. */
function awm_tts_url($text) {
    $cfg = awm_config();
    $tts = $cfg['tts'];
    $mode = $tts['mode'];
    if ($mode === 'audioserver') {
        return null; // Original Loxone Audioserver: TTS nur ueber Loxone Config (Textgenerator -> TTS-Eingang)
    }
    if ((string) $tts['ip'] === '') {
        return '';
    }
    if ($mode === 'musicserver') {
        // Zonenliste normalisieren: "2,4,6" + Lautstaerke-Feld -> "2~20,4~20,6~20".
        // Explizite Angaben "Zone~Lautstaerke" haben Vorrang.
        $vol = max(1, min(100, (int) $tts['volume']));
        $zones = array();
        foreach (explode(',', (string) $tts['zones']) as $z) {
            $z = trim($z);
            if ($z === '') {
                continue;
            }
            $zones[] = (strpos($z, '~') === false) ? $z . '~' . $vol : $z;
        }
        $zoneStr = $zones ? implode(',', $zones) : '1~' . $vol;
        return 'http://' . $tts['ip'] . ':' . (int) $tts['port'] . '/audio/grouped/tts/' . $zoneStr . '/' . rawurlencode($tts['lang'] . '|' . $text);
    }
    // ms4h (MusicServer4Home / Audioserver4Home) und custom: Vorlage mit Platzhaltern
    $tpl = trim((string) $tts['template']);
    if ($tpl === '') {
        // Standard-Vorlage MusicServer4Home
        $tpl = 'http://{ip}:{port}/tts?text={text}&zone={zones}&vol={vol}';
    }
    return str_replace(
        array('{ip}', '{port}', '{zones}', '{vol}', '{lang}', '{text}'),
        array($tts['ip'], (int) $tts['port'], $tts['zones'], (int) $tts['volume'], $tts['lang'], rawurlencode($text)),
        $tpl
    );
}

function awm_say($text) {
    $url = awm_tts_url($text);
    if ($url === null) {
        awm_log('Ansage: Modus "Original Loxone Audioserver" - Sprachausgabe erfolgt ueber Loxone Config (Textgenerator)');
        return false;
    }
    if ($url === '') {
        awm_log('Ansage uebersprungen: keine TTS-IP konfiguriert');
        return false;
    }
    $r = awm_http_get($url, 10);
    awm_log('Ansage gesendet: "' . $text . '" -> ' . ($r !== false ? 'OK' : 'FEHLER'));
    return $r !== false;
}

/** Ansagetext fuer die Tonnen von morgen (Kalender 1). */
function awm_announce_text($st = null) {
    if ($st === null) {
        $st = awm_state();
    }
    $namen = array();
    if ($st['morgen']['rest']) { $namen[] = 'die Restmuelltonne'; }
    if ($st['morgen']['bio']) { $namen[] = 'die Biotonne'; }
    if ($st['morgen']['papier']) { $namen[] = 'die Papiertonne'; }
    if ($st['morgen']['wert']) { $namen[] = 'die Wertstofftonne'; }
    $namen = array_map(function ($n) { return str_replace('Restmuelltonne', "Restm\u{00fc}lltonne", $n); }, $namen);
    if (!$namen) {
        return '';
    }
    if (count($namen) === 1) {
        return 'Ding Dong! Morgen wird ' . $namen[0] . ' abgeholt. Bitte rausstellen.';
    }
    $letzte = array_pop($namen);
    return 'Ding Dong! Morgen werden ' . implode(', ', $namen) . ' und ' . $letzte . ' abgeholt. Bitte rausstellen.';
}

/** Cron-Pruefung: Ansage am Vorabend zur konfigurierten Uhrzeit (einmal pro Tag). */
function awm_announce_check() {
    $cfg = awm_config();
    if (empty($cfg['notify']['audio'])) {
        return;
    }
    $when = preg_match('/^\d{1,2}:\d{2}$/', (string) $cfg['notify']['time']) ? $cfg['notify']['time'] : '18:00';
    list($hh, $mm) = explode(':', $when);
    $target = sprintf('%02d:%02d', (int) $hh, (int) $mm);
    if (date('H:i') !== $target) {
        return;
    }
    $flag = awm_tmpdir() . '/announced_' . date('Ymd');
    if (is_file($flag)) {
        return;
    }
    @file_put_contents($flag, '1');
    $text = awm_announce_text();
    if ($text === '') {
        awm_log('Ansage-Zeitpunkt ' . $target . ': morgen keine Abholung - keine Ansage');
        return;
    }
    awm_say($text);
}

/* ---------------- Automatische Jahres-Erneuerung (AWM, Best-Effort) ---------------- */

/**
 * Der AWM-iCal-Link enthaelt das Kalenderjahr (+ cHash). Diese Funktion versucht,
 * einen Link fuers Folgejahr zu beschaffen:
 *   Stufe 1: Jahr im Link tauschen und testen (falls der Server den cHash nicht prueft).
 *   Stufe 2: Ergebnisseite der AWM-Website mit denselben Parametern (ohne cHash)
 *            laden und den frischen ICS-Link herauslesen (Scraping).
 * Bei Erfolg wird die Konfiguration aktualisiert (inkl. Sicherungskopie).
 */
function awm_renew($cal = 1) {
    $p = awm_paths();
    $cfg = awm_config();
    $c = awm_cal($cal);
    if ($c === null || stripos($c['url'], 'awm-muenchen.de') === false) {
        return false; // nur fuer AWM-Links
    }
    if (!preg_match('/year%5D=(\d{4})|year\]=(\d{4})/i', $c['url'], $my)) {
        return false;
    }
    $year = (int) ($my[1] !== '' ? $my[1] : $my[2]);
    $newyear = $year + 1;
    awm_log('Jahres-Erneuerung Kalender ' . $cal . ': versuche ' . $year . ' -> ' . $newyear);
    // Stufe 1: Jahr tauschen
    $try = preg_replace('/(year(?:%5D|\])=)' . $year . '/i', '${1}' . $newyear, $c['url']);
    $ics = awm_http_get($try, 25);
    $neu = '';
    if ($ics !== false && strpos($ics, 'BEGIN:VCALENDAR') !== false && substr_count($ics, 'BEGIN:VEVENT') > 0) {
        $neu = $try;
        awm_log('Jahres-Erneuerung: Jahr-Tausch erfolgreich');
    } else {
        // Stufe 2: Ergebnisseite scrapen (ohne cHash/section)
        $base = preg_replace('/[?&]cHash=[0-9a-f]+/i', '', $c['url']);
        $base = preg_replace('/([?&])tx_awmabfuhrkalender_abfuhrkalender(?:%5B|\[)section(?:%5D|\])=ics&?/i', '$1', $base);
        $base = preg_replace('/(year(?:%5D|\])=)' . $year . '/i', '${1}' . $newyear, $base);
        $html = awm_http_get($base, 25);
        if ($html !== false) {
            $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
            if (preg_match_all('#https?://www\.awm-muenchen\.de/[^"\'\s]*section(?:%5D|\])=ics[^"\'\s]*#i', $html, $mm)) {
                foreach ($mm[0] as $cand) {
                    if (strpos($cand, (string) $newyear) === false) {
                        continue;
                    }
                    $ics = awm_http_get($cand, 25);
                    if ($ics !== false && strpos($ics, 'BEGIN:VCALENDAR') !== false) {
                        $neu = $cand;
                        awm_log('Jahres-Erneuerung: Link per Website-Scraping gefunden');
                        break;
                    }
                }
            }
        }
    }
    if ($neu === '') {
        awm_log('Jahres-Erneuerung: FEHLGESCHLAGEN - bitte neuen iCal-Link von der AWM-Website holen');
        return false;
    }
    // Konfiguration aktualisieren
    $raw = json_decode((string) @file_get_contents($p['config']), true);
    if (!is_array($raw)) {
        return false;
    }
    $i = 0;
    foreach ((array) $raw['cals'] as $idx => $cc) {
        if (trim((string) (isset($cc['url']) ? $cc['url'] : '')) === '') {
            continue;
        }
        $i++;
        if ($i === (int) $cal) {
            $raw['cals'][$idx]['url'] = $neu;
            break;
        }
    }
    @file_put_contents($p['config'], json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @copy($p['config'], $p['backup']);
    file_put_contents(awm_icsfile($cal), $ics);
    @unlink(awm_tmpdir() . '/state_' . (int) $cal . '.json');
    awm_log('Jahres-Erneuerung: neuer Link fuer Kalender ' . $cal . ' gespeichert (' . $newyear . ')');
    return true;
}

/** Cron: Erneuerung hoechstens einmal taeglich versuchen, wenn noetig. */
function awm_renew_check() {
    $cfg = awm_config();
    if (empty($cfg['autorenew'])) {
        return;
    }
    foreach (awm_cals() as $n => $c) {
        $st = awm_state(false, $n);
        $need = $st['warnung'] === 1;
        if (!$need && preg_match('/year(?:%5D|\])=(\d{4})/i', $c['url'], $my)) {
            $need = (int) $my[1] < (int) date('Y');
        }
        if (!$need) {
            continue;
        }
        $flag = awm_tmpdir() . '/renew_' . $n . '_' . date('Ymd');
        if (is_file($flag)) {
            continue;
        }
        @file_put_contents($flag, '1');
        awm_renew($n);
    }
}
