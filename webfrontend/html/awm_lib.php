<?php
/**
 * Abfuhrkalender AWM Muenchen - gemeinsame Bibliothek
 *
 * Laedt iCal-/ICS-Exporte von Abfuhrkalendern - AWM Muenchen ebenso wie jeder
 * andere Entsorger in Deutschland - rollt Serientermine selbst aus (ab 1.1.0
 * FREQ=DAILY/WEEKLY/MONTHLY/YEARLY mit INTERVAL, BYDAY, BYMONTHDAY, COUNT,
 * UNTIL und EXDATE, siehe awm_regeln.php) und liefert:
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

/** Wartezeit bis zum naechsten Versuch, wenn der Entsorger nicht antwortet. */
define('AWM_WIEDERHOLUNG', 3600);

/**
 * Stichwoerter, an denen eine Feiertagsverschiebung zu erkennen ist.
 *
 * "achtung" steht zuerst und bleibt: so schreibt es der AWM Muenchen, und
 * daran darf sich nichts aendern. Die uebrigen decken die Formulierungen
 * anderer Entsorger ab. Der Anwender kann die Liste in den Einstellungen
 * ueberschreiben.
 */
define('AWM_HINWEIS_STANDARD', 'achtung, verschiebung, verschoben, feiertag, ersatztermin, ausnahme');

// Tonnenzuordnung und Wiederholungsregeln (ab 1.1.0). Liegt in einer eigenen
// Datei, damit die Aenderung gegenueber 1.0.2 an einer Stelle nachlesbar ist.
require_once __DIR__ . '/awm_regeln.php';


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function awm_paths() {
    $lbhomedir = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
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
        'hinweis_woerter' => AWM_HINWEIS_STANDARD,  // Stichwoerter fuer Feiertagsverschiebungen
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
                         'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '');
    return $cfg;
}

/** Stichwoerter fuer Feiertagsverschiebungen, bereinigt. Nie leer. */
function awm_hinweis_woerter() {
    $cfg = awm_config();
    $roh = trim((string) (isset($cfg['hinweis_woerter']) ? $cfg['hinweis_woerter'] : ''));
    if ($roh === '') {
        $roh = AWM_HINWEIS_STANDARD;
    }
    $out = array();
    foreach (explode(',', $roh) as $w) {
        $w = trim($w);
        if ($w !== '') { $out[] = $w; }
    }
    return $out ? $out : array('achtung');
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

/* ==================================================================
 * Unteilbares Schreiben
 *
 * Zwei Dinge gehen beim geradeaus geschriebenen file_put_contents schief:
 *
 * 1. Wer die Datei liest, waehrend sie geschrieben wird, sieht die Haelfte.
 *    Das betrifft hier den Zwischenspeicher state_N.json: cron.php schreibt
 *    ihn jede Minute, awm.php liest ihn im 300-s-Takt des Miniservers, und
 *    die Oberflaeche liest ihn auch. Ein halb geschriebenes JSON faellt
 *    beim Einlesen durch - der Miniserver bekommt dann eine Zeile aus
 *    frisch gerechneten, aber nicht gespeicherten Werten, oder im
 *    schlimmsten Fall Nullen.
 * 2. Ein Stromausfall mitten im Schreiben hinterlaesst eine Ruine.
 *
 * Nebendatei schreiben und umbenennen loest beides: rename() ist auf
 * demselben Dateisystem unteilbar. Wer liest, sieht entweder den alten
 * oder den neuen Stand, nie etwas dazwischen.
 * ================================================================== */

function awm_datei_schreiben($pfad, $inhalt, $rechte = 0664)
{
    $dir = dirname($pfad);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $neben = $pfad . '.neu' . getmypid();
    $laenge = strlen((string) $inhalt);
    if (@file_put_contents($neben, (string) $inhalt) !== $laenge) {
        @unlink($neben);
        return false;
    }
    @chmod($neben, $rechte);
    if (!@rename($neben, $pfad)) {
        @unlink($neben);
        return false;
    }
    return true;
}

/**
 * JSON unteilbar schreiben.
 *
 * json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
 * macht daraus eine LEERE Datei - und meldet das mit der Rueckgabe 0 auch
 * noch als Erfolg. Deshalb wird das Ergebnis hier geprueft, bevor
 * ueberhaupt etwas geschrieben wird.
 */
function awm_json_schreiben($pfad, $daten, $rechte = 0664, $huebsch = false)
{
    $flaggen = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if ($huebsch) { $flaggen |= JSON_PRETTY_PRINT; }
    $js = json_encode($daten, $flaggen);
    if ($js === false || $js === '' || $js === 'null') {
        awm_log('Schreibfehler ' . basename($pfad) . ': ' . json_last_error_msg()
                . ' - Datei bleibt unveraendert');
        return false;
    }
    return awm_datei_schreiben($pfad, $js, $rechte);
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
        $neu = awm_utf8($neu);
        awm_datei_schreiben($f, $neu);
        @unlink(awm_tmpdir() . '/state_' . (int) $cal . '.json');
        awm_log('Kalender ' . $cal . ' (' . $c['name'] . ') abgerufen: ' . strlen($neu) . ' Bytes, ' . substr_count($neu, 'BEGIN:VEVENT') . ' Ereignisse');
        return array(1, 'frisch');
    }
    if (is_file($f)) {
        // Nicht jede Minute erneut versuchen - aber auch nicht erst in
        // 14 Tagen wieder. Bis 1.2.0 stand hier ein blankes touch(), das
        // die Datei auf JETZT setzte: ein Server, der eine Minute lang
        // nicht erreichbar war, wurde damit fuer das volle Abruf-Intervall
        // nicht mehr gefragt. Beim Jahreswechsel ist das genau der Fall,
        // in dem man den frischen Kalender braucht.
        @touch($f, max(0, time() - $maxage + AWM_WIEDERHOLUNG));
        awm_log('Kalender ' . $cal . ': Abruf fehlgeschlagen - nutze letzten gespeicherten Stand, naechster Versuch in '
                . (int) (AWM_WIEDERHOLUNG / 60) . ' Minuten');
        return array(1, 'cache-fallback');
    }
    awm_log('Kalender ' . $cal . ': Abruf FEHLGESCHLAGEN und kein Stand gespeichert');
    return array(0, 'FEHLGESCHLAGEN');
}

/* ==================================================================
 * Zeichensatz
 *
 * Der Standard schreibt UTF-8 vor, aber nicht jeder Entsorger haelt sich
 * daran - ISO-8859-1 und Windows-1252 kommen vor, teils mit
 * "CHARSET=ISO-8859-1" im Kopf, teils ohne jede Angabe.
 *
 * Das ist kein Schoenheitsfehler. Ein einzelnes Latin-1-Byte in einem
 * Termin-Titel wandert durch awm_series() in den Zustand, und dort scheitert
 * json_encode() daran: der Zwischenspeicher liesse sich nicht mehr
 * schreiben, ?json=1 lieferte einen leeren Rumpf. Deshalb wird schon beim
 * Abruf umgewandelt - und beim Einlesen noch einmal, damit auch die
 * Kalender heil werden, die vor 1.3.0 schon auf der Platte lagen.
 * ================================================================== */

function awm_utf8($text)
{
    $text = (string) $text;
    // Angabe im Kalender selbst hat Vorrang.
    $von = '';
    if (preg_match('/CHARSET=["\']?([A-Za-z0-9_-]+)/i', $text, $m)) {
        $von = strtoupper($m[1]);
    }
    if ($von === 'UTF-8' || $von === 'UTF8') {
        $von = '';
    }
    // Ohne Angabe: nur eingreifen, wenn es wirklich kein UTF-8 ist.
    if ($von === '') {
        if (preg_match('//u', $text)) {
            return $text;              // gueltiges UTF-8, nichts zu tun
        }
        $von = 'WINDOWS-1252';         // deckt ISO-8859-1 mit ab
    }
    $neu = false;
    if (function_exists('iconv')) {
        $neu = @iconv($von, 'UTF-8//TRANSLIT', $text);
    }
    if ($neu === false && function_exists('mb_convert_encoding')) {
        $neu = @mb_convert_encoding($text, 'UTF-8', $von);
    }
    if ($neu === false || $neu === '') {
        // Letzte Rueckfallebene: alles Ungueltige entfernen. Lieber ein
        // fehlendes Zeichen als eine Datei, die kein JSON mehr wird.
        $neu = preg_replace('/[\x80-\xFF]/', '?', $text);
    }
    // Die Angabe im Kopf stimmt jetzt nicht mehr - sonst wandelt der
    // naechste Aufruf ein zweites Mal um.
    return preg_replace('/CHARSET=["\']?[A-Za-z0-9_-]+/i', 'CHARSET=UTF-8', (string) $neu);
}

/* ---------------- ICS auswerten ---------------- */

/** Serien einlesen (inkl. Einzeltermine, z. B. "Achtung: ..."-Verschiebungen). */
function awm_series($cal = 1) {
    $f = awm_icsfile($cal);
    if (!is_file($f)) {
        return array();
    }
    // Zeichensatz auch beim Einlesen pruefen: Kalender, die vor 1.3.0
    // abgerufen wurden, liegen noch so auf der Platte, wie sie kamen.
    $ics = awm_utf8((string) file_get_contents($f));
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
            // Ab 1.1.0 wird die RRULE vollstaendig zerlegt (awm_regeln.php).
            // Bis 1.0.2 wurden BYDAY, BYMONTHDAY und COUNT ignoriert - bei
            // monatlichen Kalendern fiel dadurch fast das ganze Jahr weg.
            $s = array_merge($s, awm_rrule_lesen($mr[1]));
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
    // Ab 1.1.0 macht das awm_trifft() in awm_regeln.php - mit allen
    // Frequenzen. Fuer FREQ=WEEKLY ohne BYDAY ist das Ergebnis Zeile fuer
    // Zeile dasselbe wie in 1.0.2; die Selbstpruefung weist das nach.
    return awm_trifft($s, $ymd);
}

/** Tonnen-Erkennung aus dem Termin-Titel.
 *
 * Ab 1.1.0 gilt zuerst die Zuordnungstabelle des Kalenders; trifft keine
 * Regel, greift unveraendert die eingebaute Erkennung von 1.0.2.
 */
function awm_bins_of($summary, $cal = 1) {
    return awm_bins_regeln($summary, awm_regeln($cal));
}

/** Welche Tonnen werden am Datum (Ymd) abgeholt? */
function awm_bins($serien, $ymd, $cal = 1) {
    // Die vier alten Schluessel bleiben an erster Stelle - daran haengen
    // Konfiguration, MQTT-Themen und Loxone-Bausteine.
    $t = awm_tonnen_leer();
    $t['texte'] = array();
    $regeln = awm_regeln($cal);
    foreach ($serien as $s) {
        if (!awm_pickup($s, $ymd)) {
            continue;
        }
        $b = awm_bins_regeln($s['summary'], $regeln);
        foreach ($b as $k => $v) {
            if ($v) { $t[$k] = 1; }
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
        'heute' => awm_bins($serien, date('Ymd'), $cal),
        'morgen' => awm_bins($serien, date('Ymd', strtotime('+1 day')), $cal),
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
        $x = awm_bins($serien, $ymd, $cal);
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
    // Feiertags-/Verschiebungs-Hinweis der naechsten 14 Tage.
    //
    // Bis 1.2.0 stand hier das Wort "achtung" fest im Code. Das ist die
    // Formulierung des AWM Muenchen; andere Entsorger schreiben
    // "Verschiebung", "Feiertagsregelung" oder "Ersatztermin", und deren
    // Anwender bekamen den Hinweis nie zu sehen. Die Woerter stehen jetzt
    // in der Konfiguration - mit "achtung" an erster Stelle, damit
    // Muenchen sich nicht aendert.
    $woerter = awm_hinweis_woerter();
    $heute_nr = awm_tagnummer(date('Ymd'));
    foreach ($serien as $s) {
        if ($s['freq'] !== null && $s['freq'] !== '') {
            continue;                 // nur Einzeltermine sind Verschiebungen
        }
        $text = $s['summary'] . ' ' . $s['desc'];
        $treffer = false;
        foreach ($woerter as $wort) {
            if (stripos($text, $wort) !== false) { $treffer = true; break; }
        }
        if (!$treffer) {
            continue;
        }
        $diff = awm_tagnummer($s['start']) - $heute_nr;
        if (awm_tagnummer($s['start']) >= 0 && $diff >= 0 && $diff <= 14) {
            $st['hinweis'] = trim($s['summary']) . ($s['desc'] !== '' ? ' - ' . $s['desc'] : '');
            break;
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
    // Unteilbar, damit awm.php nie eine halb geschriebene Datei liest.
    awm_json_schreiben($cache, $st);
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

/**
 * Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE. Ein Zeilenumbruch im Wert - aus einer
 * Fehlermeldung des Betriebssystems, einem Geraetenamen oder der Ausgabe
 * eines Systembefehls - zerlegt die Uebertragung, und aus den Bruchstuecken
 * bildet das Gateway erfundene Themen. Ein Tabulator schadet ebenso, weil
 * Leerzeichen Thema und Wert trennt.
 */
function awm_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

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
        $msg = 'publish ' . $prefix . '/' . $k . ' ' . awm_mqtt_wert_saeubern($v);
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
    if ($mode === 'musicserver' && (string) $tts['ip'] === '') {
        return '';   // ohne IP laesst sich die Music-Server-Adresse nicht bauen
    }
    if ($mode === 'musicserver') {
        // Zonenliste normalisieren: "2,4,6" + Lautstaerke-Feld -> "2~8,4~8,6~8".
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
    // Die IP wird nur verlangt, wenn die Vorlage sie auch verwendet. Bis
    // 1.2.0 stand die Pruefung weiter oben und galt fuer alle Modi: eine
    // eigene Vorlage wie "http://sprich.local/say?text={text}" liess sich
    // damit gar nicht benutzen, obwohl sie keine IP braucht.
    if ((string) $tts['ip'] === '' && strpos($tpl, '{ip}') !== false) {
        return '';
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
        return 'Hallo! Morgen wird ' . $namen[0] . ' abgeholt. Bitte rausstellen.';
    }
    $letzte = array_pop($namen);
    return 'Hallo! Morgen werden ' . implode(', ', $namen) . ' und ' . $letzte . ' abgeholt. Bitte rausstellen.';
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

/* ==================================================================
 * Automatische Link-Erneuerung zum Jahreswechsel
 *
 * Viele Abfuhrkalender-Links tragen das Kalenderjahr in sich. Zum
 * Jahreswechsel liefert der alte Link dann keine Termine mehr - und das
 * faellt erst auf, wenn die Tonne stehen bleibt. awm_state() setzt
 * deshalb 'warnung', und hier wird versucht, den Link selbst zu erneuern.
 *
 * Ab 1.2.0 ist das nach Entsorger aufgeteilt:
 *
 *   awm_renew_strategien()   die Tabelle - HIER kommen neue Provider dazu
 *   awm_renew_kand_*()       rechnen aus einer URL Kandidaten fuers
 *                            Folgejahr aus. Reine Textarbeit, kein Netz,
 *                            deshalb von awm_selbstpruefung_erneuerung()
 *                            nachpruefbar.
 *   awm_renew_scrape_awm()   die Rueckfallebene fuer AWM (Website lesen).
 *   awm_renew()              waehlt die Strategie und schreibt das
 *                            Ergebnis in die Konfiguration.
 *
 * MUENCHEN BLEIBT UNVERAENDERT. Die AWM-Strategie ist Zeile fuer Zeile
 * die aus 1.1.0: erst Jahr im Link tauschen, dann die Ergebnisseite
 * scrapen. Der einzige Unterschied ist, dass auch der gescrapte Link
 * jetzt Termine enthalten MUSS, bevor er uebernommen wird - bis 1.1.0
 * genuegte ein BEGIN:VCALENDAR, ein leerer Kalender konnte also einen
 * funktionierenden Link ueberschreiben.
 * ================================================================== */

/**
 * Welche Strategie gilt fuer welche URL? Erster Treffer gewinnt, deshalb
 * steht die allgemeine Rueckfallebene ('*') zuletzt.
 *
 *   muster    Textstueck in der URL, '*' trifft immer
 *   kand      Funktion(URL) -> Liste von Kandidaten-URLs fuers Folgejahr
 *   fallback  Funktion(URL, cal) -> array(URL, ICS-Text) oder leer;
 *             laeuft nur, wenn kein Kandidat funktioniert hat
 */
function awm_renew_strategien() {
    return array(
        array('muster' => 'awm-muenchen.de',
              'name' => 'AWM Muenchen',
              'kand' => 'awm_renew_kand_awm',
              'fallback' => 'awm_renew_scrape_awm'),
        array('muster' => 'abfall.io',
              'name' => 'Abfallplus / abfall.io',
              'kand' => 'awm_renew_kand_jahresfenster',
              'fallback' => ''),
        array('muster' => '*',
              'name' => 'allgemein (Jahreszahl im Link)',
              'kand' => 'awm_renew_kand_jahreszahl',
              'fallback' => ''),
    );
}

function awm_renew_strategie($url) {
    foreach (awm_renew_strategien() as $s) {
        if ($s['muster'] === '*' || stripos($url, $s['muster']) !== false) {
            return $s;
        }
    }
    return null;
}

/**
 * Kandidat abrufen und pruefen.
 *
 * Uebernommen wird nur ein Kalender MIT Terminen. Damit bleibt ein
 * Fehlgriff folgenlos: der alte Link steht weiter in der Konfiguration.
 * Rueckgabe: ICS-Text oder ''.
 */
function awm_renew_test($url) {
    $ics = awm_http_get($url, 25);
    if ($ics !== false && strpos($ics, 'BEGIN:VCALENDAR') !== false
        && substr_count($ics, 'BEGIN:VEVENT') > 0) {
        return $ics;
    }
    return '';
}

/* ---------------- Kandidaten je Entsorger ---------------- */

/**
 * AWM Muenchen: das Jahr steht als eigener Parameter im Link
 *   ...tx_awmabfuhrkalender_abfuhrkalender[year]=2026...
 * (in der Regel URL-kodiert als %5Byear%5D bzw. year%5D=).
 */
function awm_renew_kand_awm($url) {
    if (!preg_match('/year%5D=(\d{4})|year\]=(\d{4})/i', $url, $my)) {
        return array();
    }
    $year = (int) ($my[1] !== '' ? $my[1] : $my[2]);
    $neu = preg_replace('/(year(?:%5D|\])=)' . $year . '/i', '${1}' . ($year + 1), $url);
    return $neu === $url ? array() : array($neu);
}

/**
 * Abfallplus / abfall.io: das Jahr steckt als Zeitfenster im Link
 *   ...&timeperiod=20260101-20261231&...
 * Geprueft wird dort der 'key', nicht das Fenster - Ersetzen genuegt,
 * ein Abruf der Website ist nicht noetig.
 */
function awm_renew_kand_jahresfenster($url) {
    if (!preg_match('/timeperiod=(\d{4})0101-(\d{4})1231/i', $url, $m)) {
        return array();
    }
    $von = (int) $m[1];
    $neu = str_replace($m[0], 'timeperiod=' . ($von + 1) . '0101-' . ($von + 1) . '1231', $url);
    return $neu === $url ? array() : array($neu);
}

/**
 * Rueckfallebene fuer Entsorger, deren Link-Aufbau (noch) nicht bekannt
 * ist - etwa Jumomind/MyMuell oder ATURIS.
 *
 * Kommt das laufende oder das vergangene Jahr GENAU EINMAL in der URL
 * vor, wird es hochgezaehlt. "Genau einmal" ist Absicht: taucht die Zahl
 * mehrfach auf, ist nicht zu entscheiden, welche das Kalenderjahr ist,
 * und Raten waere schlimmer als Nichtstun. Uebernommen wird ohnehin nur,
 * was awm_renew_test() als Kalender mit Terminen bestaetigt.
 */
function awm_renew_kand_jahreszahl($url) {
    $out = array();
    foreach (array((int) date('Y'), (int) date('Y') - 1) as $year) {
        if (substr_count($url, (string) $year) !== 1) {
            continue;
        }
        $neu = str_replace((string) $year, (string) ($year + 1), $url);
        if ($neu !== $url) {
            $out[] = $neu;
        }
    }
    return $out;
}

/* ---------------- Rueckfallebene AWM: Website lesen ---------------- */

/**
 * Stufe 2 fuer AWM, unveraendert aus 1.1.0: Ergebnisseite mit denselben
 * Parametern (ohne cHash/section) laden und den frischen ICS-Link
 * herauslesen. Rueckgabe: array(URL, ICS-Text) oder array('', '').
 */
function awm_renew_scrape_awm($url, $cal = 1) {
    if (!preg_match('/year%5D=(\d{4})|year\]=(\d{4})/i', $url, $my)) {
        return array('', '');
    }
    $year = (int) ($my[1] !== '' ? $my[1] : $my[2]);
    $newyear = $year + 1;
    $base = preg_replace('/[?&]cHash=[0-9a-f]+/i', '', $url);
    $base = preg_replace('/([?&])tx_awmabfuhrkalender_abfuhrkalender(?:%5B|\[)section(?:%5D|\])=ics&?/i', '$1', $base);
    $base = preg_replace('/(year(?:%5D|\])=)' . $year . '/i', '${1}' . $newyear, $base);
    $html = awm_http_get($base, 25);
    if ($html === false) {
        return array('', '');
    }
    $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
    // Absolute Adressen - der Fall, den 1.1.0 und 1.2.0 abgedeckt haben.
    preg_match_all('#https?://www\.awm-muenchen\.de/[^"\'\s]*section(?:%5D|\])=ics[^"\'\s]*#i', $html, $mm);
    $kandidaten = $mm[0];
    // Und die relativen: TYPO3 schreibt Verweise auf die eigene Seite je
    // nach Einstellung als "/entsorgen/..." statt mit vollem Namen. Bis
    // 1.2.0 fand der Ausdruck davon nichts, und die Rueckfallebene lief
    // ins Leere, obwohl der Link auf der Seite stand.
    if (preg_match_all('#(?:href=["\']|["\'\s])(/[^"\'\s]*section(?:%5D|\])=ics[^"\'\s]*)#i', $html, $mr)) {
        foreach ($mr[1] as $rel) {
            $kandidaten[] = 'https://www.awm-muenchen.de' . $rel;
        }
    }
    if (!$kandidaten) {
        return array('', '');
    }
    $kandidaten = array_values(array_unique($kandidaten));
    foreach ($kandidaten as $cand) {
        if (strpos($cand, (string) $newyear) === false) {
            continue;
        }
        $ics = awm_renew_test($cand);
        if ($ics !== '') {
            awm_log('Jahres-Erneuerung: Link per Website-Scraping gefunden');
            return array($cand, $ics);
        }
    }
    return array('', '');
}

/* ---------------- Ablauf ---------------- */

/**
 * Link eines Kalenders aufs Folgejahr erneuern.
 * Bei Erfolg wird die Konfiguration aktualisiert (inkl. Sicherungskopie)
 * und der frische Kalender gleich abgelegt.
 */
function awm_renew($cal = 1) {
    $p = awm_paths();
    $c = awm_cal($cal);
    if ($c === null) {
        return false;
    }
    $s = awm_renew_strategie($c['url']);
    if ($s === null) {
        awm_log('Jahres-Erneuerung Kalender ' . $cal . ': keine Strategie fuer diesen Link');
        return false;
    }
    awm_log('Jahres-Erneuerung Kalender ' . $cal . ': Strategie "' . $s['name'] . '"');

    $neu = '';
    $ics = '';
    foreach (call_user_func($s['kand'], $c['url']) as $kandidat) {
        $t = awm_renew_test($kandidat);
        if ($t !== '') {
            $neu = $kandidat;
            $ics = $t;
            awm_log('Jahres-Erneuerung: Link-Umschreibung erfolgreich');
            break;
        }
    }
    if ($neu === '' && $s['fallback'] !== '') {
        list($neu, $ics) = call_user_func($s['fallback'], $c['url'], $cal);
    }
    if ($neu === '' || $ics === '') {
        awm_log('Jahres-Erneuerung: FEHLGESCHLAGEN - bitte neuen Kalender-Link beim Entsorger holen');
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
    // Erst die Konfiguration, dann die Sicherung - und nur, wenn das
    // Schreiben wirklich geklappt hat. Andernfalls stuende in der Sicherung
    // gleich mit, was in der Konfiguration schon fehlt.
    if (!awm_json_schreiben($p['config'], $raw, 0664, true)) {
        awm_log('Jahres-Erneuerung: neuer Link gefunden, liess sich aber NICHT speichern - alter Link bleibt gueltig');
        return false;
    }
    @copy($p['config'], $p['backup']);
    awm_datei_schreiben(awm_icsfile($cal), awm_utf8($ics));
    @unlink(awm_tmpdir() . '/state_' . (int) $cal . '.json');
    awm_log('Jahres-Erneuerung: neuer Link fuer Kalender ' . $cal . ' gespeichert');
    return true;
}

/** Nennt der Link ein Jahr, das schon vorbei ist? */
function awm_renew_veraltet($url) {
    if (preg_match('/year(?:%5D|\])=(\d{4})/i', $url, $m)) {
        return (int) $m[1] < (int) date('Y');
    }
    if (preg_match('/timeperiod=(\d{4})0101/i', $url, $m)) {
        return (int) $m[1] < (int) date('Y');
    }
    return false;
}

/** Cron: Erneuerung hoechstens einmal taeglich versuchen, wenn noetig. */
function awm_renew_check() {
    $cfg = awm_config();
    if (empty($cfg['autorenew'])) {
        return;
    }
    foreach (awm_cals() as $n => $c) {
        $st = awm_state(false, $n);
        $need = $st['warnung'] === 1 || awm_renew_veraltet($c['url']);
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

/* ==================================================================
 * Selbstpruefung der Erneuerung
 *
 * Prueft nur die Textarbeit an den Links - ohne Netz, damit sie in der
 * Oberflaeche jederzeit laufen kann. Der erste Block ist der wichtige:
 * er weist nach, dass der AWM-Link genauso umgeschrieben wird wie
 * bisher.
 * ================================================================== */

function awm_selbstpruefung_erneuerung()
{
    $e = array();
    $p = function ($ok, $text) use (&$e) { $e[] = array($ok ? 1 : 0, $text); };

    /* --- 1. AWM bleibt unveraendert --- */
    $awm = 'https://www.awm-muenchen.de/entsorgen/abfuhrkalender'
         . '?tx_awmabfuhrkalender_abfuhrkalender%5Bhausnummer%5D=1'
         . '&tx_awmabfuhrkalender_abfuhrkalender%5Byear%5D=2026'
         . '&tx_awmabfuhrkalender_abfuhrkalender%5Bsection%5D=ics'
         . '&cHash=abc123';
    $k = awm_renew_kand_awm($awm);
    $p(count($k) === 1 && strpos($k[0], 'year%5D=2027') !== false,
       'AWM: Jahr im Link wird hochgezaehlt (2026 -> 2027)');
    $p(count($k) === 1 && strpos($k[0], 'hausnummer%5D=1') !== false
                       && strpos($k[0], 'cHash=abc123') !== false,
       'AWM: alle uebrigen Parameter bleiben unangetastet');
    $p(awm_renew_strategie($awm) !== null
       && awm_renew_strategie($awm)['kand'] === 'awm_renew_kand_awm',
       'AWM-Link waehlt die AWM-Strategie');
    $p(awm_renew_kand_awm('https://www.awm-muenchen.de/ohne-jahr.ics') === array(),
       'AWM ohne Jahresangabe: kein Kandidat, kein Schaden');

    /* --- 2. Abfallplus / abfall.io --- */
    $aio = 'https://api.abfall.io/?key=f35bd08b&mode=export&idhousenumber=2859'
         . '&wastetypes=20,17&timeperiod=20260101-20261231&type=ics';
    $k = awm_renew_kand_jahresfenster($aio);
    $p(count($k) === 1 && strpos($k[0], 'timeperiod=20270101-20271231') !== false,
       'abfall.io: Zeitfenster wird aufs Folgejahr gesetzt');
    $p(count($k) === 1 && strpos($k[0], 'key=f35bd08b') !== false,
       'abfall.io: Schluessel bleibt unangetastet');
    $s = awm_renew_strategie($aio);
    $p($s !== null && $s['kand'] === 'awm_renew_kand_jahresfenster',
       'abfall.io-Link waehlt die abfall.io-Strategie');

    /* --- 3. Allgemeine Rueckfallebene --- */
    $j = (int) date('Y');
    $p(awm_renew_kand_jahreszahl('https://beispiel.de/kalender_' . $j . '.ics')
       === array('https://beispiel.de/kalender_' . ($j + 1) . '.ics'),
       'Allgemein: einzelne Jahreszahl wird hochgezaehlt');
    $p(awm_renew_kand_jahreszahl('https://beispiel.de/' . $j . '/kalender_' . $j . '.ics') === array(),
       'Allgemein: mehrdeutige Jahreszahl wird in Ruhe gelassen');
    $p(awm_renew_kand_jahreszahl('https://beispiel.de/kalender.ics') === array(),
       'Allgemein: Link ohne Jahreszahl liefert keinen Kandidaten');
    $s = awm_renew_strategie('https://mymuell.jumomind.com/webmodul/beispiel/ical');
    $p($s !== null && $s['kand'] === 'awm_renew_kand_jahreszahl',
       'Unbekannter Entsorger landet in der Rueckfallebene');

    /* --- 4. Veraltet-Erkennung --- */
    $p(awm_renew_veraltet(str_replace('2026', (string) ($j - 1), $awm)),
       'Veraltet: AWM-Link aus dem Vorjahr wird erkannt');
    $p(!awm_renew_veraltet(str_replace('2026', (string) $j, $awm)),
       'Veraltet: AWM-Link des laufenden Jahres gilt als aktuell');
    $p(awm_renew_veraltet(str_replace('2026', (string) ($j - 1), $aio)),
       'Veraltet: abfall.io-Fenster aus dem Vorjahr wird erkannt');

    return $e;
}

/* ==================================================================
 * Selbstpruefung der Robustheit (ab 1.3.0)
 *
 * Vier Stellen, an denen das Plugin bis 1.2.0 stillschweigend das
 * Falsche tat. Sie laufen alle ohne Netz und ohne Schreibzugriff auf die
 * Konfiguration.
 * ================================================================== */

function awm_selbstpruefung_robust()
{
    $e = array();
    $p = function ($ok, $text) use (&$e) { $e[] = array($ok ? 1 : 0, $text); };

    /* --- 1. Zeichensatz --- */
    $latin = "BEGIN:VCALENDAR\r\nSUMMARY:Restm\xFClltonne gr\xFCn\r\nEND:VCALENDAR";
    $u = awm_utf8($latin);
    $p(preg_match('//u', $u) === 1, 'Latin-1-Kalender wird zu gueltigem UTF-8');
    $p(json_encode(array('s' => $u)) !== false,
       'Umgewandelter Kalender laesst sich als JSON schreiben');
    $p(strpos($u, "Restm\u{00fc}lltonne") !== false, 'Der Umlaut ueberlebt die Umwandlung');
    $schon = "BEGIN:VCALENDAR\r\nSUMMARY:Restm\u{00fc}lltonne\r\nEND:VCALENDAR";
    $p(awm_utf8($schon) === $schon, 'Gueltiges UTF-8 wird nicht angefasst');
    $mit = "BEGIN:VCALENDAR\r\nX-WR-CALNAME;CHARSET=ISO-8859-1:M\xFCll\r\nEND:VCALENDAR";
    $p(preg_match('//u', awm_utf8($mit)) === 1, 'Angabe CHARSET=ISO-8859-1 im Kopf wird befolgt');
    $p(preg_match('//u', awm_utf8(awm_utf8($mit))) === 1
       && strpos(awm_utf8(awm_utf8($mit)), 'M') !== false,
       'Zweimaliges Umwandeln schadet nicht');

    /* --- 2. Sprachausgabe: eigene Vorlage ohne IP --- */
    // Bis 1.2.0 gab awm_tts_url() bei leerer IP fuer JEDEN Modus ''
    // zurueck. Eine Vorlage wie "http://sprich.local/say?text={text}"
    // braucht aber gar keine IP - sie war damit unbenutzbar.
    $cfg = awm_config();
    $sicher = $cfg['tts'];
    $p(function_exists('awm_tts_url'), 'Sprachausgabe-Funktion vorhanden');
    $p(strpos((string) awm_tts_url_test('custom', '', 'http://sprich.local/say?text={text}', 'Hallo'),
              'sprich.local') !== false,
       'Eigene Vorlage ohne IP wird gebaut, nicht verworfen');
    $p(awm_tts_url_test('musicserver', '', '', 'Hallo') === '',
       'Music Server ohne IP liefert weiterhin nichts');
    $p(strpos((string) awm_tts_url_test('musicserver', '192.168.1.50', '', 'Hallo'),
              '192.168.1.50:') !== false,
       'Music Server mit IP unveraendert');
    $p(awm_tts_url_test('audioserver', '', '', 'Hallo') === null,
       'Original Loxone Audioserver liefert weiterhin null');
    unset($sicher);

    /* --- 3. Stichwoerter fuer Verschiebungen --- */
    $w = awm_hinweis_woerter();
    $p(in_array('achtung', array_map('strtolower', $w), true),
       'Muenchen unveraendert: "achtung" ist immer dabei');
    $p(count($w) > 1, 'Weitere Stichwoerter fuer andere Entsorger sind hinterlegt');

    /* --- 4. Unteilbares Schreiben --- */
    $probe = awm_tmpdir() . '/selbsttest.json';
    $ok = awm_json_schreiben($probe, array('a' => 1, 'b' => "\u{00fc}"));
    $gelesen = $ok ? json_decode((string) @file_get_contents($probe), true) : null;
    $p($ok && is_array($gelesen) && $gelesen['a'] === 1, 'Unteilbares Schreiben und Lesen klappt');
    $p(!awm_json_schreiben($probe, array('a' => "\xFF\xFE ungueltig")),
       'Ungueltiges UTF-8 wird abgelehnt, statt die Datei zu leeren');
    $nachher = json_decode((string) @file_get_contents($probe), true);
    $p(is_array($nachher) && isset($nachher['a']) && $nachher['a'] === 1,
       'Nach dem abgelehnten Schreiben steht der alte Inhalt noch da');
    @unlink($probe);

    return $e;
}

/**
 * Sprachausgabe-Adresse mit vorgegebenen Werten bauen - nur fuer die
 * Selbstpruefung. Die Konfiguration wird dabei NICHT angefasst.
 */
function awm_tts_url_test($mode, $ip, $template, $text)
{
    if ($mode === 'audioserver') {
        return null;
    }
    if ($mode === 'musicserver') {
        return $ip === '' ? '' : 'http://' . $ip . ':7091/audio/grouped/tts/1~8/'
                                 . rawurlencode('de|' . $text);
    }
    $tpl = trim((string) $template);
    if ($tpl === '') {
        $tpl = 'http://{ip}:{port}/tts?text={text}&zone={zones}&vol={vol}';
    }
    if ($ip === '' && strpos($tpl, '{ip}') !== false) {
        return '';
    }
    return str_replace(array('{ip}', '{port}', '{zones}', '{vol}', '{lang}', '{text}'),
                       array($ip, 7091, '1', 8, 'de', rawurlencode($text)), $tpl);
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini
 * immer vollstaendig sein.
 * ================================================================== */

function awm_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/**
 * Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL".
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt
 * beim Durchsehen sofort auf, was noch fehlt, statt dass die Seite leer
 * bleibt.
 */
function awm_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        // Installiert liegen die Dateien unter
        // <home>/templates/plugins/<ordner>/lang/ - der Ordnername ergibt
        // sich aus dem Ablageort dieser Datei.
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) { $home = $k; break; }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            // Nicht installiert (Entwicklung): neben dem Plugin nachsehen.
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . awm_sprache() . '.ini',
                                 true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        // parse_ini_file mit INI_SCANNER_RAW liefert die Werte samt der
        // Anfuehrungszeichen zurueck, in die sie in der Datei stehen muessen.
        // Die gehoeren nicht in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}

/* ---------------- Loxone-Vorlage (Hausstandard "Alles auf einmal anlegen") ---------------- */
/** name => array(analog, min, max, einheit, kommentar). DATUM (Text) ist bewusst nicht dabei. */
function awm_felder() {
    return array(
        'OK'      => array(0, 0, 1,   '',     '1 = Daten gueltig'),
        'REST'    => array(1, 0, 365, 'Tage', 'Restmuell: Tage bis zur Abfuhr (0 = heute)'),
        'BIO'     => array(1, 0, 365, 'Tage', 'Biotonne: Tage bis zur Abfuhr'),
        'PAPIER'  => array(1, 0, 365, 'Tage', 'Papiertonne: Tage bis zur Abfuhr'),
        'WERT'    => array(1, 0, 365, 'Tage', 'Wertstoff: Tage bis zur Abfuhr'),
        'WARN'    => array(0, 0, 1,   '',     '1 = morgen ist Abfuhr (Tonnen rausstellen)'),
        'HREST'   => array(0, 0, 1,   '',     'heute Abfuhr: Restmuell'),
        'HBIO'    => array(0, 0, 1,   '',     'heute Abfuhr: Bio'),
        'HPAPIER' => array(0, 0, 1,   '',     'heute Abfuhr: Papier'),
        'HWERT'   => array(0, 0, 1,   '',     'heute Abfuhr: Wertstoff'),
        'TREST'   => array(0, 0, 1,   '',     'morgen Abfuhr: Restmuell'),
        'TBIO'    => array(0, 0, 1,   '',     'morgen Abfuhr: Bio'),
        'TPAPIER' => array(0, 0, 1,   '',     'morgen Abfuhr: Papier'),
        'TWERT'   => array(0, 0, 1,   '',     'morgen Abfuhr: Wertstoff'),
        'ANN'     => array(0, 0, 1,   '',     'Meldefenster aktiv'),
        'AUDIO'   => array(0, 0, 1,   '',     'Ansage freigegeben'),
        'PUSH'    => array(0, 0, 1,   '',     'Push freigegeben'),
        'PTEST'   => array(0, 0, 1,   '',     'Test-Push ausloesen'),
    );
}
/** Gepruefter PHP-Nachbau des LoxoneTemplateBuilder - Attributreihenfolge,
 *  CRLF und der Tabulator vor den Kindelementen entsprechen dem Original.
 *  Uebernommen aus LoxBerry-Plugin-APC-UPS, nur das Kuerzel getauscht. */
function awm_xml_virtual_in_http($kopf, $cmds) {
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" ';
    $o .= 'Title="' . awm_vx($kopf['title']) . '" ';
    $o .= 'Comment="' . awm_vx(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . awm_vx(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . awm_vx(isset($kopf['polling']) ? $kopf['polling'] : '300') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf; // wie Original-Export aus Loxone Config 17.1
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . awm_vx($c['title']) . '" ';
        $o .= 'Comment="' . awm_vx($c['comment']) . '" ';
        $o .= 'Check="' . awm_vx($c['check']) . '" ';
        $o .= 'Signed="' . ($c['min'] < 0 ? 'true' : 'false') . '" ';
        $o .= 'Analog="' . ($c['analog'] ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" DefVal="0" ';
        $o .= 'MinVal="' . (int) $c['min'] . '" ';
        $o .= 'MaxVal="' . (int) $c['max'] . '" ';
        $o .= 'Unit="' . awm_vx(isset($c['unit']) ? $c['unit'] : '<v>') . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

function awm_vx($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Hausstandard: Gateway-Autostart aus general.json (PLUGIN_HAUSREGELN Abschnitt 3). */
function awm_mqtt_gateway_autostart() {
    $home = getenv('LBHOMEDIR') ?: '/opt/loxberry';
    $gj = $home . '/config/system/general.json';
    if (!is_file($gj)) { return null; }
    $d = json_decode((string) @file_get_contents($gj), true);
    if (!is_array($d) || !isset($d['Mqtt'])) { return null; }
    return !empty($d['Mqtt']['Gatewayautostart']);
}

/** Vorlage fuer den Import in Loxone Config. Rueckgabe: array(name, inhalt) */
function awm_vorlage() {
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    $ordner = getenv('LBPPLUGINDIR') ?: 'awmabfuhr';
    $cmds = array();
    foreach (awm_felder() as $name => $f) {
        list($analog, $min, $max, $einheit, $text) = $f;
        $cmds[] = array(
            'title' => 'MUELL_' . $name,
            'comment' => $text . ($einheit !== '' ? ' [' . $einheit . ']' : ''),
            'check' => '\i' . $name . '=\i\v',
            'unit' => ($einheit !== '' ? '<v.1> ' . $einheit : '<v.1>'),
            'analog' => $analog, 'min' => $min, 'max' => $max,
        );
    }
    return array('VI_awm.xml', awm_xml_virtual_in_http(array(
        'title' => 'Abfuhrkalender AWM',
        'address' => 'http://' . $host . '/plugins/' . $ordner . '/awm.php',
        'polling' => '300',
        'comment' => 'Erzeugt vom LoxBerry-Plugin Abfuhrkalender AWM (' . date('d.m.Y') . '). '
                   . 'Loxone Config legt beim Import neu an und ueberschreibt nichts - '
                   . 'zweimal eingelesen ergibt doppelte Bausteine.',
    ), $cmds));
}
