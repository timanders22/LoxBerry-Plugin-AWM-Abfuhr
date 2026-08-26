<?php
/**
 * Abfuhrkalender AWM Muenchen - gemeinsame Bibliothek
 *
 * Laedt iCal-/ICS-Exporte von Abfuhrkalendern - AWM Muenchen ebenso wie jeder
 * andere Entsorger in Deutschland - rollt Serientermine selbst aus (ab 1.1.0
 * FREQ=DAILY/WEEKLY/MONTHLY/YEARLY mit INTERVAL, BYDAY, BYMONTHDAY, COUNT,
 * UNTIL und EXDATE, ab 1.4.0 zusaetzlich BYMONTH, BYSETPOS, WKST, RDATE und
 * RECURRENCE-ID, siehe awm_regeln.php) und liefert:
 *   - Loxone-Textzeile (kompatibel zum klassischen muell.php-Format)
 *   - bis zu 4 Kalender (z. B. Zuhause + Ferienhaus), Auswahl per &cal=2
 *   - JSON-Zustand, MQTT ueber das LoxBerry MQTT Gateway
 *   - Vorabend-Ansage (TTS) und Push-Freigabe wie beim Abfahrtsassistenten
 *   - automatische Jahres-Erneuerung des AWM-Links (Jahr-Tausch + Scraping)
 *
 * Keine persoenlichen Daten im Code - Adressen/Kalender stecken ausschliesslich
 * in den lokal konfigurierten iCal-URLs.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * ------------------------------------------------------------------------
 * DIE WICHTIGSTE REGEL DIESER DATEI (ab 1.4.0)
 *
 * Alle ausgelieferten Werte entstehen an EINER Stelle: awm_feldliste() sagt,
 * welche Felder es gibt, awm_werte() rechnet sie aus. HTTP-Zeile, MQTT-
 * Themen und die Loxone-Vorlage lesen daraus - sie rechnen nichts selbst.
 *
 * Warum: bis 1.3.5 fehlten dem MQTT-Weg vier Werte, die es ueber HTTP gab.
 * 1.3.6 hat das fuer diese vier behoben, indem beide Wege awm_meldeflags()
 * benutzen. Gemessen am 20.08.2026 lief es danach in die andere Richtung
 * auseinander: MQTT lieferte 23 Werte, HTTP nur 19 - es fehlten hinweis und
 * die vier datum_*. Zwei Rechnungen fuer dieselbe Sache laufen immer
 * auseinander, es ist nur eine Frage der Richtung. Jetzt gibt es eine.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('Europe/Berlin');

/** Wartezeit bis zum naechsten Versuch, wenn der Entsorger nicht antwortet. */
define('AWM_WIEDERHOLUNG', 3600);

/** Loxone rechnet in Sekunden seit dem 01.01.2009. */
define('AWM_LOXEPOCHE', 1230768000);

/** So viele Kalender bietet die Oberflaeche an (awm.php nimmt 1..9). */
define('AWM_MAX_KALENDER', 4);

/** Wie weit die Vorausschau fuer "letzter Termin" hoechstens laeuft (Tage). */
define('AWM_HORIZONT', 400);

/** So viele gestrichene Termine sieht sich die Luecken-Suche hoechstens an. */
define('AWM_LUECKEN_MAX', 200);

/**
 * Stichwoerter, an denen eine Feiertagsverschiebung zu erkennen ist.
 *
 * "achtung" steht zuerst und bleibt: so schreibt es der AWM Muenchen, und
 * daran darf sich nichts aendern. Gemessen am echten Export vom 20.08.2026:
 * "SUMMARY: Achtung: Restmuelltonne, Musterstraße 1". Die uebrigen decken die
 * Formulierungen anderer Entsorger ab. Der Anwender kann die Liste in den
 * Einstellungen ueberschreiben.
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
            'icsbackup' => $lbhomedir . '/config/plugins/' . $plugindir . '.backup.ics',
            'log' => $lbhomedir . '/log/plugins/' . $plugindir . '/awm.log',
            'data' => $lbhomedir . '/data/plugins/' . $plugindir,
            'tmp' => '/tmp/awmabfuhr',
            'lbhome' => $lbhomedir,
            'plugin' => $plugindir,
        );
    }
    return array(
        'config' => dirname(dirname(__DIR__)) . '/config/awm.json',
        'backup' => dirname(dirname(__DIR__)) . '/config/awm.backup.json',
        'icsbackup' => dirname(dirname(__DIR__)) . '/config/awm.backup.ics',
        'log' => sys_get_temp_dir() . '/awmabfuhr/awm.log',
        'data' => sys_get_temp_dir() . '/awmabfuhr/data',
        'tmp' => sys_get_temp_dir() . '/awmabfuhr',
        'lbhome' => '',
        'plugin' => 'awmabfuhr',
    );
}

/**
 * Die Vorgabewerte - an EINER Stelle.
 *
 * Bis 1.3.8 standen sie zweimal: in awm_config() und noch einmal in
 * index.php. Zwei Listen von Vorgaben sind zwei Gelegenheiten, eine neue
 * Einstellung an einer Stelle zu vergessen - und genau dann fehlt sie im
 * Aktualisierungsfall, also auf jeder bestehenden Anlage.
 */
function awm_config_vorgaben()
{
    return array(
        'cals' => array(),
        'fetch_days' => 14,        // Abruf-Intervall in TAGEN (Kalender aendern sich selten)
        'lookahead' => 35,         // Vorschau-Fenster in Tagen
        'autorenew' => 1,          // AWM-Link zum Jahreswechsel automatisch erneuern (Best-Effort)
        'hinweis_woerter' => AWM_HINWEIS_STANDARD,
        'mqtt_enabled' => 0,
        'mqtt_topic' => 'awm',
        'notify' => array(),
        'tts' => array(),
        'ruhe' => array(),
        'ansage' => array(),
        'melden' => 1,             // Warnungen in den LoxBerry-Meldebereich legen
        'aktionstoken' => '',      // schuetzt ?ptest= ?say= ?renew= ?ack= (unangemeldeter Endpunkt)
    );
}

function awm_config_teilvorgaben()
{
    return array(
        'notify' => array('audio' => 1, 'push' => 1, 'time' => '18:00',
                          'audio2' => 0, 'time2' => '06:30'),
        'tts' => array('mode' => 'musicserver', 'ip' => '', 'port' => 7091,
                       'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => ''),
        'ruhe' => array('urlaub' => 0, 'nachts' => 0, 'von' => '22:00', 'bis' => '07:00',
                        'bis_datum' => ''),
        'ansage' => array('vorlage' => '', 'vorlage2' => ''),
    );
}

/**
 * Konfiguration lesen.
 *
 * $anlegen darf NUR aus dem angemeldeten Bereich true sein. Bis 1.3.8 legte
 * diese Funktion bei fehlender Konfiguration das Verzeichnis an und kopierte
 * die Sicherung zurueck - und sie wird aus awm.php aufgerufen, also aus dem
 * unangemeldeten Teil. Die Hausregel verlangt zwei Zugriffswege: einen, der
 * nur liest, und einen, der bei Bedarf anlegt.
 */
function awm_config($anlegen = false) {
    $p = awm_paths();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    $leer = ($roh === '' || $roh === '{}' || strpos($roh, '"') === false);
    if ($leer && is_file($p['backup'])) {
        if ($anlegen) {
            @mkdir(dirname($p['config']), 0775, true);
            @copy($p['backup'], $p['config']);
            $roh = trim((string) @file_get_contents($p['config']));
        } else {
            // Nur lesen: die Sicherung unmittelbar verwenden, ohne zu schreiben.
            $roh = trim((string) @file_get_contents($p['backup']));
        }
    }
    $cfg = $roh !== '' ? (json_decode($roh, true) ?: array()) : array();
    if (!is_array($cfg)) {
        $cfg = array();
    }
    $cfg += awm_config_vorgaben();
    if (!is_array($cfg['cals'])) { $cfg['cals'] = array(); }
    // Migration alter Konfigurationen (Einzel-URL / tts.enabled / fetch_hours)
    if (empty($cfg['cals']) && !empty($cfg['ical_url'])) {
        $cfg['cals'] = array(array('name' => 'Zuhause', 'url' => (string) $cfg['ical_url']));
    }
    foreach (awm_config_teilvorgaben() as $ab => $werte) {
        if (!is_array($cfg[$ab])) { $cfg[$ab] = array(); }
    }
    if (isset($cfg['tts']['enabled']) && !isset($cfg['notify']['audio'])) {
        $cfg['notify']['audio'] = (int) $cfg['tts']['enabled'];
    }
    if (isset($cfg['tts']['time']) && !isset($cfg['notify']['time'])) {
        $cfg['notify']['time'] = (string) $cfg['tts']['time'];
    }
    if (isset($cfg['tts']['system']) && !isset($cfg['tts']['mode'])) {
        $cfg['tts']['mode'] = $cfg['tts']['system'] === 'custom' ? 'custom' : 'musicserver';
    }
    foreach (awm_config_teilvorgaben() as $ab => $werte) {
        $cfg[$ab] += $werte;
    }
    return $cfg;
}

/** Sperre gegen parallele Laeufe - 1:1 nach fer_sperre (FerienFeiertage):
 *  flock auf eine Sperrdatei, nicht blockierend; false = ein Lauf laeuft schon. */
function awm_sperre($name = 'cron') {
    $f = awm_tmpdir() . '/' . preg_replace('/[^a-z0-9_]/', '', $name) . '.lock';
    $fh = @fopen($f, 'c');
    if ($fh === false) {
        // Nicht stillschweigend weiterlaufen: ohne Sperre ist der Schaden
        // groesser als ohne Lauf, und ohne Meldung sucht niemand danach.
        awm_log('WARNUNG: Sperrdatei ' . $f . ' laesst sich nicht oeffnen - '
              . 'Platz im Verzeichnis und Eigentuemer pruefen.');
        return false;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return false;
    }
    return $fh;
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

/** Kalenderliste (nur Eintraege mit URL oder hochgeladener Datei), 1-basiert. */
function awm_cals() {
    $cfg = awm_config();
    $out = array();
    $n = 0;
    foreach ((array) $cfg['cals'] as $idx => $c) {
        $c = (array) $c;
        $url = trim((string) (isset($c['url']) ? $c['url'] : ''));
        $hoch = !empty($c['hochgeladen']);
        if ($url === '' && !$hoch) {
            continue;
        }
        $n++;
        $out[$n] = array(
            'name' => trim((string) (isset($c['name']) ? $c['name'] : '')) !== '' ? trim((string) $c['name']) : ('Kalender ' . $n),
            'url' => $url,
            'hochgeladen' => $hoch ? 1 : 0,
            'ansage' => !empty($c['ansage']) || $n === 1 ? 1 : 0,
            'zonen' => trim((string) (isset($c['zonen']) ? $c['zonen'] : '')),
        );
        // Kalender 1 behaelt die Ansage, sofern nichts anderes eingestellt ist.
        if ($n === 1 && isset($c['ansage'])) {
            $out[$n]['ansage'] = !empty($c['ansage']) ? 1 : 0;
        }
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

/* ==================================================================
 * Meldungen in den LoxBerry-Benachrichtigungsbereich
 *
 * Bis 1.3.8 wusste nur das Protokoll, wenn der Abruf scheiterte oder der
 * Kalender auslief - und in das Protokoll sieht niemand, solange die Tonnen
 * geleert werden. Fuenfzehn Schwesterplugins legen ihre Warnungen ueber
 * notify_ext() ab; hier fehlte es.
 *
 * Anders als bei den Python-Plugins braucht es kein Zwischenstueck: dieses
 * Plugin ist selbst PHP. Die Bibliothek wird nur bei Bedarf eingebunden.
 *
 * Dieselbe Meldung wird hoechstens einmal am Tag abgelegt - sonst stuenden
 * bei einem laengeren Ausfall 1440 gleiche Eintraege im Meldebereich.
 * ================================================================== */

function awm_notify($schwere, $text, $schluessel = '')
{
    $cfg = awm_config();
    if (empty($cfg['melden'])) {
        return false;
    }
    $schluessel = $schluessel !== '' ? $schluessel : substr(md5($text), 0, 12);
    $flag = awm_tmpdir() . '/meldung_' . preg_replace('/[^a-z0-9_]/i', '', $schluessel);
    if (is_file($flag) && time() - filemtime($flag) < 86400) {
        return false;
    }
    $p = awm_paths();
    if ($p['lbhome'] === '') {
        return false;
    }
    $sdk = $p['lbhome'] . '/libs/phplib/loxberry_log.php';
    if (!is_file($sdk)) {
        return false;
    }
    require_once $p['lbhome'] . '/libs/phplib/loxberry_system.php';
    require_once $sdk;
    if (!function_exists('notify_ext')) {
        awm_log('Meldung nicht moeglich: notify_ext() gibt es in dieser LoxBerry-Fassung nicht');
        return false;
    }
    notify_ext(array(
        'PACKAGE'  => $p['plugin'],
        'NAME'     => 'Abfuhrkalender',
        'MESSAGE'  => $text,
        'SEVERITY' => (int) $schwere,
    ));
    @file_put_contents($flag, '1');
    awm_log('Meldung abgelegt (Schwere ' . (int) $schwere . '): ' . $text);
    return true;
}

/* ==================================================================
 * HTTP
 *
 * Vor mancher Schnittstelle sitzt ein Waechter, der eine Anfrage ohne
 * Accept-Kopfzeilen abweist. Und der nackte Fehlertext hilft niemandem:
 * "erreichbar, aber der Dienst laeuft nicht" fuehrt zu einer voellig
 * anderen Suche als "es antwortet nichts". Beides ist Hausregel, beides
 * fehlte hier bis 1.3.8 - das Protokoll sagte nur "Abruf fehlgeschlagen".
 *
 * Uebernommen aus dem Abfahrts-Assistenten, wo es geprueft laeuft.
 * ================================================================== */

function awm_http_kopf() {
    return array(
        'User-Agent: LoxBerry Abfuhrkalender',
        'Accept: text/calendar, text/html, */*',
        'Accept-Language: de,en;q=0.8',
        'Accept-Encoding: identity',
    );
}

function awm_http_grund($errno, $fehler, $status) {
    if ($errno === 7)  { return 'Verbindung abgewiesen - die Gegenstelle ist erreichbar, der Dienst laeuft aber nicht'; }
    if ($errno === 6)  { return 'Name laesst sich nicht aufloesen - stimmt die Adresse?'; }
    if ($errno === 28) { return 'Zeitueberschreitung - es antwortet nichts'; }
    if ($errno === 35 || $errno === 60) { return 'Verschluesselung scheiterte (Zertifikat oder Protokoll)'; }
    if ($errno !== 0)  { return 'Netzfehler ' . (int) $errno . ': ' . (string) $fehler; }
    if ($status === 401 || $status === 403) { return 'HTTP ' . $status . ' - der Zugang wurde abgewiesen'; }
    if ($status === 404) { return 'HTTP 404 - die Adresse gibt es nicht (Link veraltet?)'; }
    if ($status === 429) { return 'HTTP 429 - der Entsorger drosselt die Abfragen'; }
    if ($status >= 500)  { return 'HTTP ' . $status . ' - Fehler beim Entsorger'; }
    if ($status >= 400)  { return 'HTTP ' . $status; }
    return '';
}

/**
 * Eine Adresse abrufen. Rueckgabe: Rumpf oder false.
 *
 * Beide Abrufwege verhalten sich gleich: file_get_contents folgt von sich
 * aus bis zu zwanzig Weiterleitungen, curl ohne Zutun keiner. Damit koennte
 * eine Adresse - in der bei manchen Entsorgern ein Schluessel steht - je
 * nach vorhandenem php-curl unterschiedlich weit wandern. Beide folgen
 * jetzt hoechstens einer.
 */
function awm_http_get($url, $tmo = 20, &$grund = '', &$status = 0) {
    $grund = '';
    $status = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 1,
            CURLOPT_TIMEOUT => $tmo,
            CURLOPT_CONNECTTIMEOUT => min(8, $tmo),
            CURLOPT_HTTPHEADER => awm_http_kopf(),
        ));
        $rumpf = curl_exec($ch);
        $errno = curl_errno($ch);
        $fehler = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $grund = awm_http_grund($errno, $fehler, $status);
        if ($rumpf === false || $errno !== 0 || $status >= 400) {
            return false;
        }
        return $rumpf;
    }
    $ctx = stream_context_create(array('http' => array(
        'timeout' => $tmo,
        'header' => implode("\r\n", awm_http_kopf()),
        'follow_location' => 1,
        'max_redirects' => 2,          // 1 Weiterleitung, wie oben
        'ignore_errors' => true,
    ), 'ssl' => array('verify_peer' => true)));
    $rumpf = @file_get_contents($url, false, $ctx);
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $z) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $z, $m)) { $status = (int) $m[1]; }
        }
    }
    if ($rumpf === false) {
        $grund = 'Abruf nicht moeglich - es antwortet nichts (Zeitueberschreitung oder kein Weg dorthin)';
        return false;
    }
    if ($status >= 400) {
        $grund = awm_http_grund(0, '', $status);
        return false;
    }
    return $rumpf;
}

/* ---------------- ICS holen ---------------- */

function awm_icsfile($cal = 1) {
    return awm_datadir() . '/kalender_' . max(1, (int) $cal) . '.ics'; // persistent
}

/** Merkdatei fuer den Zustand des letzten Abrufs (fuer AGE/FETCH). */
function awm_fetchstand_datei($cal = 1) {
    return awm_tmpdir() . '/fetch_' . max(1, (int) $cal) . '.json';
}

function awm_fetchstand($cal = 1) {
    $f = awm_fetchstand_datei($cal);
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (!is_array($d)) { $d = array(); }
    return $d + array('ok' => 1, 'zeit' => 0, 'grund' => '', 'fehler' => 0);
}

/**
 * Kalender laden, wenn aelter als Intervall (oder $force).
 * Rueckgabe: [ok, quelle].
 */
function awm_fetch($force = false, $cal = 1) {
    $cfg = awm_config();
    $c = awm_cal($cal);
    $f = awm_icsfile($cal);
    if ($c === null) {
        return array(is_file($f) ? 1 : 0, 'keine URL konfiguriert');
    }
    // Hochgeladene Kalender werden nicht abgerufen - es gibt keine Adresse.
    if ($c['url'] === '' && !empty($c['hochgeladen'])) {
        return array(is_file($f) ? 1 : 0, 'hochgeladen');
    }
    $maxage = max(1, (int) $cfg['fetch_days']) * 86400;
    if (!$force && is_file($f) && time() - filemtime($f) < $maxage) {
        return array(1, 'cache');
    }
    $grund = '';
    $status = 0;
    $neu = awm_http_get($c['url'], 20, $grund, $status);
    if ($neu !== false && strpos($neu, 'BEGIN:VCALENDAR') !== false) {
        $neu = awm_utf8($neu);
        awm_datei_schreiben($f, $neu);
        @unlink(awm_tmpdir() . '/state_' . (int) $cal . '.json');
        awm_json_schreiben(awm_fetchstand_datei($cal),
            array('ok' => 1, 'zeit' => time(), 'grund' => '', 'fehler' => 0));
        awm_log('Kalender ' . $cal . ' (' . $c['name'] . ') abgerufen: ' . strlen($neu) . ' Bytes, ' . substr_count($neu, 'BEGIN:VEVENT') . ' Ereignisse');
        return array(1, 'frisch');
    }
    if ($neu !== false && $grund === '') {
        $grund = 'Die Antwort ist kein Kalender (kein BEGIN:VCALENDAR) - '
               . 'hat eine Anmeldeseite geantwortet statt der Schnittstelle?';
    }
    $stand = awm_fetchstand($cal);
    $fehlerzahl = (int) $stand['fehler'] + 1;
    awm_json_schreiben(awm_fetchstand_datei($cal),
        array('ok' => 0, 'zeit' => (int) $stand['zeit'], 'grund' => $grund, 'fehler' => $fehlerzahl));
    if (is_file($f)) {
        // Nicht jede Minute erneut versuchen - aber auch nicht erst in
        // 14 Tagen wieder. Bis 1.2.0 stand hier ein blankes touch(), das
        // die Datei auf JETZT setzte: ein Server, der eine Minute lang
        // nicht erreichbar war, wurde damit fuer das volle Abruf-Intervall
        // nicht mehr gefragt. Beim Jahreswechsel ist das genau der Fall,
        // in dem man den frischen Kalender braucht.
        @touch($f, max(0, time() - $maxage + AWM_WIEDERHOLUNG));
        awm_log('Kalender ' . $cal . ': Abruf fehlgeschlagen (' . $grund . ') - nutze letzten gespeicherten Stand, naechster Versuch in '
                . (int) (AWM_WIEDERHOLUNG / 60) . ' Minuten');
        if ($fehlerzahl >= 3) {
            awm_notify(3, sprintf(awm_t_oder('MELDUNG.ABRUF_WIEDERHOLT',
                        'Der Abfuhrkalender "%s" laesst sich seit %d Versuchen nicht '
                        . 'abrufen: %s Die Termine stammen aus dem gespeicherten Stand.'),
                        $c['name'], $fehlerzahl, $grund), 'fetch' . $cal);
        }
        return array(1, 'cache-fallback');
    }
    awm_log('Kalender ' . $cal . ': Abruf FEHLGESCHLAGEN und kein Stand gespeichert - ' . $grund);
    awm_notify(3, sprintf(awm_t_oder('MELDUNG.ABRUF_NIE',
                'Der Abfuhrkalender "%s" konnte noch nie abgerufen werden: %s'),
                $c['name'], $grund), 'fetch' . $cal);
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
 * schreiben, ?json=1 lieferte einen leeren Rumpf.
 *
 * AB 1.4.0 WIRD ZUERST GEPRUEFT, OB ES UEBERHAUPT NOETIG IST.
 *
 * Bis 1.3.8 stand die Suche nach "CHARSET=" vor der Pruefung, und sie
 * durchsuchte die GANZE Datei. CHARSET ist aber ein Parameter EINER
 * Property. Gemessen am 20.08.2026: eine gueltige UTF-8-Datei mit der Zeile
 *
 *     X-WR-CALNAME;CHARSET=ISO-8859-1:Abfuhr
 *
 * wurde vollstaendig von Latin-1 nach UTF-8 gerechnet - aus
 * "Restmuelltonne" wurde "RestmÃ¼lltonne". Und weil awm_fetch() das
 * Ergebnis speichert und der Kopf auf CHARSET=UTF-8 umgeschrieben wird, war
 * der Schaden dauerhaft.
 * ================================================================== */

function awm_utf8($text)
{
    $text = (string) $text;
    // Gueltiges UTF-8 wird NICHT angefasst - egal, was im Kopf steht.
    if (preg_match('//u', $text)) {
        return $text;
    }
    $von = '';
    if (preg_match('/CHARSET=["\']?([A-Za-z0-9_-]+)/i', $text, $m)) {
        $von = strtoupper($m[1]);
    }
    if ($von === '' || $von === 'UTF-8' || $von === 'UTF8') {
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

/**
 * Sieht das nach einem brauchbaren Kalender aus?
 *
 * Fuer den Datei-Upload: was nicht ins Muster passt, wird ABGEWIESEN und
 * gemeldet, nicht zurechtgebogen. Rueckgabe: '' = in Ordnung, sonst der
 * Grund.
 */
function awm_ics_pruefen($text)
{
    $text = (string) $text;
    if (trim($text) === '') {
        return 'Die Datei ist leer.';
    }
    if (strpos($text, 'BEGIN:VCALENDAR') === false) {
        return 'Das ist keine iCal-Datei - BEGIN:VCALENDAR fehlt.';
    }
    if (substr_count($text, 'BEGIN:VEVENT') < 1) {
        return 'Die Datei enthaelt keinen einzigen Termin (BEGIN:VEVENT).';
    }
    return '';
}

/* ---------------- ICS auswerten ---------------- */

/**
 * Ein Datum aus einer iCal-Eigenschaft.
 *
 * Bis 1.3.8 wurden stur die ersten acht Ziffern genommen. Fuer
 * "DTSTART;TZID=Europe/Berlin;VALUE=DATE:20260112" - so schreibt es der AWM -
 * ist das richtig. Fuer die UTC-Form nicht: "20260104T230000Z" ist in
 * Europe/Berlin bereits der 05.01., und die Ansage kam einen Tag zu frueh.
 * Gemessen am 20.08.2026.
 */
function awm_ics_datum($wert)
{
    $wert = trim((string) $wert);
    if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/', $wert, $m)) {
        $ts = gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
        return date('Ymd', $ts);       // date() rechnet in Europe/Berlin
    }
    if (preg_match('/^(\d{8})/', $wert, $m)) {
        return $m[1];
    }
    return '';
}

/** Alle Datumsangaben einer Eigenschaft (EXDATE/RDATE koennen Listen sein). */
function awm_ics_datumsliste($ev, $name)
{
    $out = array();
    // Die Kommaliste ist der Fall, den 1.3.8 verlor: der Ausdruck verlangte
    // das Praefix "EXDATE...:" vor JEDEM Treffer, also blieb nach dem ersten
    // Datum der Rest der Zeile liegen. Gemessen am 20.08.2026:
    // "EXDATE;VALUE=DATE:20260112,20260119,20260126" ergab nur den 12.01.,
    // und an den beiden anderen Tagen meldete das Plugin eine Abholung, die
    // der Entsorger abgesagt hatte. (Der Muenchner Export schreibt je Zeile
    // ein Datum - der Fehler traf also andere Entsorger.)
    if (!preg_match_all('/^' . $name . '[^:\r\n]*:([^\r\n]+)/mi', $ev, $mm)) {
        return $out;
    }
    foreach ($mm[1] as $zeile) {
        foreach (explode(',', $zeile) as $stueck) {
            $d = awm_ics_datum($stueck);
            if ($d !== '') { $out[] = $d; }
        }
    }
    return array_values(array_unique($out));
}

/** Text einer Eigenschaft, mit aufgeloesten Maskierungen. */
function awm_ics_text($ev, $name)
{
    if (!preg_match('/^' . $name . '[^:\r\n]*:([^\r\n]*)/mi', $ev, $m)) {
        return '';
    }
    // \N ist nach RFC 5545 gleichwertig zu \n - bis 1.3.8 blieb es woertlich
    // stehen und wanderte so in den Hinweis, ins MQTT-Thema und in die
    // Oberflaeche.
    return trim(str_replace(
        array('\\,', '\\;', '\\n', '\\N', '\\\\'),
        array(',', ';', ' ', ' ', '\\'),
        $m[1]));
}

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

    // METHOD:CANCEL sagt: diese Nachricht sagt Termine AB. Sie als
    // Terminliste zu lesen waere die Umkehrung ihrer Bedeutung.
    if (preg_match('/^METHOD:CANCEL/mi', $ics)) {
        awm_log('Kalender ' . $cal . ': METHOD:CANCEL - die Datei sagt Termine ab, sie enthaelt keine.');
        return array();
    }

    $serien = array();
    $ausnahmen = array();          // UID => Liste der ersetzten Datumsangaben
    $parts = explode('BEGIN:VEVENT', $ics);
    foreach ($parts as $i => $ev) {
        if ($i === 0) {
            continue;
        }
        // Grenze des Termins beachten. Ohne das reichte der letzte Abschnitt
        // bis zum Dateiende - stand dort ein VTIMEZONE (nach RFC zulaessig),
        // uebernahm der Einzeltermin dessen RRULE. Gemessen am 20.08.2026:
        // aus einem Einzeltermin wurde freq='YEARLY'.
        $ende = strpos($ev, 'END:VEVENT');
        if ($ende !== false) {
            $ev = substr($ev, 0, $ende);
        }
        // Der Wecker gehoert nicht zum Termin - er bringt eine eigene
        // DESCRIPTION mit. Der AWM-Export legt in jeden Termin einen.
        $ev = preg_replace('/BEGIN:VALARM.*?END:VALARM/s', '', $ev);

        // Abgesagte Termine sind keine Termine.
        if (preg_match('/^STATUS:CANCELLED/mi', $ev)) {
            continue;
        }
        if (!preg_match('/^DTSTART[^:\r\n]*:([^\r\n]+)/mi', $ev, $md)) {
            continue;
        }
        $start = awm_ics_datum($md[1]);
        if ($start === '') {
            continue;
        }
        $s = array('start' => $start, 'summary' => '', 'freq' => null, 'interval' => 1,
                   'until' => '99991231', 'count' => 0, 'byday' => array(),
                   'bymonthday' => array(), 'bymonth' => array(), 'bysetpos' => array(),
                   'wkst' => 1, 'exdates' => array(), 'rdates' => array(),
                   'desc' => '', 'uid' => '', 'rrule' => '');
        $s['summary'] = awm_ics_text($ev, 'SUMMARY');
        $s['desc'] = awm_ics_text($ev, 'DESCRIPTION');
        $s['uid'] = awm_ics_text($ev, 'UID');
        if (preg_match('/^RRULE:([^\r\n]+)/mi', $ev, $mr)) {
            // Ab 1.1.0 wird die RRULE vollstaendig zerlegt (awm_regeln.php).
            // Bis 1.0.2 wurden BYDAY, BYMONTHDAY und COUNT ignoriert - bei
            // monatlichen Kalendern fiel dadurch fast das ganze Jahr weg.
            $s['rrule'] = trim($mr[1]);
            $s = array_merge($s, awm_rrule_lesen($mr[1]));
        }
        $s['exdates'] = awm_ics_datumsliste($ev, 'EXDATE');
        $s['rdates'] = awm_ics_datumsliste($ev, 'RDATE');

        // RECURRENCE-ID: dieser Termin ERSETZT eine Instanz der Serie mit
        // derselben UID. Bis 1.3.8 kam er als zusaetzlicher Einzeltermin
        // dazu, waehrend der Originaltermin stehenblieb - zwei Ansagen, eine
        // davon falsch.
        if (preg_match('/^RECURRENCE-ID[^:\r\n]*:([^\r\n]+)/mi', $ev, $mrec)) {
            $ersetzt = awm_ics_datum($mrec[1]);
            if ($ersetzt !== '' && $s['uid'] !== '') {
                if (!isset($ausnahmen[$s['uid']])) { $ausnahmen[$s['uid']] = array(); }
                $ausnahmen[$s['uid']][] = $ersetzt;
            }
            $s['freq'] = null;          // die Ausnahme selbst ist ein Einzeltermin
        }
        $serien[] = $s;
    }
    // Die ersetzten Termine aus der jeweiligen Serie herausnehmen.
    if ($ausnahmen) {
        foreach ($serien as $k => $s) {
            if ($s['freq'] === null || $s['uid'] === '' || !isset($ausnahmen[$s['uid']])) {
                continue;
            }
            $serien[$k]['exdates'] = array_values(array_unique(
                array_merge($s['exdates'], $ausnahmen[$s['uid']])));
        }
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

/**
 * Eigene Sondertermine des Anwenders.
 *
 * Weihnachtsbaum, Sperrmuell auf Abruf, der Gruenschnitt-Container am
 * Ortsrand - das steht in keinem ICS und muss trotzdem erinnert werden. Sie
 * werden wie Einzeltermine aus der Datei behandelt und laufen damit durch
 * dieselbe Rechnung: Zaehler, MQTT, Ansage, Loxone-Zeile.
 */
function awm_eigene_termine($cal = 1) {
    $cfg = awm_config();
    $cals = isset($cfg['cals']) && is_array($cfg['cals']) ? array_values($cfg['cals']) : array();
    $i = max(1, (int) $cal) - 1;
    $liste = isset($cals[$i]['termine']) && is_array($cals[$i]['termine']) ? $cals[$i]['termine'] : array();
    $arten = awm_tonnenarten();
    $out = array();
    foreach ($liste as $t) {
        $t = (array) $t;
        $d = preg_replace('/[^0-9]/', '', (string) (isset($t['datum']) ? $t['datum'] : ''));
        $tonne = (string) (isset($t['tonne']) ? $t['tonne'] : '');
        if (strlen($d) !== 8 || awm_tagnummer($d) < 0 || !isset($arten[$tonne])) {
            continue;
        }
        $out[] = array(
            'start' => $d, 'freq' => null, 'interval' => 1, 'until' => '99991231',
            'count' => 0, 'byday' => array(), 'bymonthday' => array(), 'bymonth' => array(),
            'bysetpos' => array(), 'wkst' => 1, 'exdates' => array(), 'rdates' => array(),
            'summary' => trim((string) (isset($t['text']) ? $t['text'] : '')) !== ''
                         ? trim((string) $t['text']) : $arten[$tonne]['text'],
            'desc' => '', 'uid' => 'eigen', 'rrule' => '', 'eigen' => $tonne,
        );
    }
    return $out;
}

/** Welche Tonnen werden am Datum (Ymd) abgeholt? */
function awm_bins($serien, $ymd, $cal = 1) {
    // Die vier alten Schluessel bleiben an erster Stelle - daran haengen
    // Konfiguration, MQTT-Themen und Loxone-Bausteine.
    $t = awm_tonnen_leer();
    $t['texte'] = array();
    $regeln = awm_regeln($cal);
    $modus = awm_regeln_modus($cal);
    foreach ($serien as $s) {
        if (!awm_pickup($s, $ymd)) {
            continue;
        }
        // Eigene Termine tragen ihre Tonne mit sich - da wird nicht geraten.
        if (!empty($s['eigen'])) {
            $t[$s['eigen']] = 1;
        } else {
            $b = awm_bins_regeln($s['summary'], $regeln, $modus);
            foreach ($b as $k => $v) {
                if ($v) { $t[$k] = 1; }
            }
        }
        $t['texte'][] = $s['summary'];
    }
    $t['texte'] = array_values(array_unique($t['texte']));
    return $t;
}

/**
 * Ausgefallene Termine ohne Ersatz.
 *
 * Gemessen am echten AWM-Kalender (20.08.2026): die Papiertonne hat drei
 * EXDATE-Termine - 02.04., 30.04. und 14.05.2026 - und KEINEN Ersatztermin
 * dazu. Aus dem 14-Tage-Takt werden dadurch einmal 28 und einmal 42 Tage
 * Pause. Das Plugin sagte dazu bisher nichts; man merkt es, wenn die Tonne
 * voll ist.
 *
 * Gemeldet wird ein EXDATE nur dann, wenn im Umkreis von sieben Tagen
 * keine Abholung derselben Tonne stattfindet - sonst waere jeder normale
 * Feiertagsersatz eine Meldung.
 */
function awm_luecken($serien, $cal = 1, $von = null, $bis = null)
{
    $von = $von === null ? date('Ymd') : $von;
    $bis = $bis === null ? date('Ymd', strtotime('+180 days')) : $bis;
    $regeln = awm_regeln($cal);
    $modus = awm_regeln_modus($cal);
    $out = array();
    $geprueft = 0;
    $gekappt = 0;
    foreach ($serien as $s) {
        if (empty($s['exdates']) || $s['freq'] === null) {
            continue;
        }
        $bins = awm_bins_regeln($s['summary'], $regeln, $modus);
        $tonnen = array();
        foreach ($bins as $k => $v) { if ($v) { $tonnen[] = $k; } }
        if (!$tonnen) { continue; }
        foreach ($s['exdates'] as $ex) {
            if ($ex < $von || $ex > $bis) { continue; }
            $n = awm_tagnummer($ex);
            if ($n < 0) { continue; }
            // Harte Obergrenze. Die Suche ist verschachtelt; was darueber
            // hinausgeht, wird NICHT stillschweigend weggelassen, sondern
            // gezaehlt und gemeldet.
            if ($geprueft >= AWM_LUECKEN_MAX) { $gekappt++; continue; }
            $geprueft++;
            $ersatz = '';
            // Der Tag selbst gehoert MIT geprueft: der AWM legt fuer den
            // Ersatz einen eigenen "Achtung"-Einzeltermin an, und der liegt
            // auf genau dem Datum, das die Serie ausschliesst. Ohne d=0
            // meldete die Luecken-Erkennung die drei Weihnachtstermine als
            // Ausfall, obwohl abgeholt wird (gemessen 20.08.2026).
            for ($d = 0; $d <= 7; $d++) {
                foreach (($d === 0 ? array(0) : array(-$d, $d)) as $vz) {
                $tag = awm_datum_aus_tagnummer($n + $vz);
                $b = awm_bins($serien, $tag, $cal);
                foreach ($tonnen as $tk) {
                    if (!empty($b[$tk])) { $ersatz = $tag; break 3; }
                }
                }
            }
            if ($ersatz === '') {
                $out[] = array('datum' => $ex, 'tonnen' => $tonnen, 'titel' => $s['summary']);
            }
        }
    }
    usort($out, function ($a, $b) { return strcmp($a['datum'], $b['datum']); });
    if ($gekappt > 0) {
        awm_log('Luecken-Suche: ' . AWM_LUECKEN_MAX . ' gestrichene Termine geprueft, '
              . $gekappt . ' weitere NICHT - die Liste ist unvollstaendig.');
    }
    return $out;
}

/** Loxone rechnet in Sekunden seit dem 01.01.2009. 0 = kein Datum. */
function awm_loxzeit($ymd)
{
    $n = awm_tagnummer((string) $ymd);
    if ($n < 0) { return 0; }
    // Ortszeit 00:00 des Tages, damit Loxone denselben Tag anzeigt.
    $ts = mktime(0, 0, 0, (int) substr($ymd, 4, 2), (int) substr($ymd, 6, 2), (int) substr($ymd, 0, 4));
    return max(0, $ts - AWM_LOXEPOCHE);
}

/** Kompletter Zustand eines Kalenders (mit Cache 10 min). */
function awm_state($force = false, $cal = 1) {
    $cfg = awm_config();
    $p = awm_paths();
    $cal = max(1, (int) $cal);
    $cache = awm_tmpdir() . '/state_' . $cal . '.json';
    $icst = is_file(awm_icsfile($cal)) ? filemtime(awm_icsfile($cal)) : 0;
    // Die Konfiguration gehoert in die Gueltigkeit des Zwischenspeichers.
    // Bis 1.3.8 blieb nach einer Aenderung von lookahead, den Stichwoertern
    // oder der Kalender-URL bis zu 600 Sekunden der alte Stand stehen - in
    // der Oberflaeche, in awm.php und ueber MQTT.
    $cfgt = is_file($p['config']) ? filemtime($p['config']) : 0;
    if (!$force && is_file($cache) && time() - filemtime($cache) < 600
        && filemtime($cache) >= $icst && filemtime($cache) >= $cfgt) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (is_array($c) && isset($c['datum']) && $c['datum'] === date('Ymd')) {
            return $c;
        }
    }
    $serien = array_merge(awm_series($cal), awm_eigene_termine($cal));
    $arten = array_keys(awm_tonnenarten());
    $leer_i = array();
    $leer_s = array();
    foreach ($arten as $k) { $leer_i[$k] = -1; $leer_s[$k] = ''; }

    $fstand = awm_fetchstand($cal);
    $st = array(
        'ok' => $serien ? 1 : 0,
        'cal' => $cal,
        'datum' => date('Ymd'),
        'heute' => awm_bins($serien, date('Ymd'), $cal),
        'morgen' => awm_bins($serien, date('Ymd', strtotime('+1 day')), $cal),
        'tage' => $leer_i,
        'naechste' => $leer_s,
        'termine' => array(),
        'hinweis' => '',
        'warnung' => 0,
        'letzter' => '',
        'ereignisse' => count($serien),
        'next' => array('tage' => -1, 'datum' => '', 'tonnen' => array()),
        'alter' => -1,
        'abruf_ok' => (int) $fstand['ok'],
        'abruf_grund' => (string) $fstand['grund'],
        'ts' => time(),
    );
    $icsdatei = awm_icsfile($cal);
    if (is_file($icsdatei)) {
        $st['alter'] = (int) floor((time() - filemtime($icsdatei)) / 3600);
    }
    $look = max(7, min(90, (int) $cfg['lookahead']));
    for ($d = 0; $d <= $look; $d++) {
        $ymd = date('Ymd', strtotime("+$d day"));
        $x = awm_bins($serien, $ymd, $cal);
        foreach ($arten as $k) {
            if ($x[$k] && $st['tage'][$k] < 0) {
                $st['tage'][$k] = $d;
                $st['naechste'][$k] = $ymd;
            }
        }
        if ($x['texte']) {
            $st['termine'][] = array($ymd, implode(' | ', $x['texte']));
            // Die naechste Abholung ueberhaupt - damit der Miniserver nicht
            // das Minimum ueber acht Zaehler selbst bilden muss (und dabei
            // die -1 ausschliessen).
            if ($st['next']['tage'] < 0) {
                $treffer = array();
                foreach ($arten as $k) { if ($x[$k]) { $treffer[] = $k; } }
                if ($treffer) {
                    $st['next'] = array('tage' => $d, 'datum' => $ymd, 'tonnen' => $treffer);
                }
            }
        }
    }
    // Feiertags-/Verschiebungs-Hinweis der naechsten 14 Tage.
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
    /* Jahreswechsel-Warnung: Kalender liefert bald keine Termine mehr.
     *
     * Der letzte Termin wird jetzt aus awm_serie_ende() genommen, nicht mehr
     * aus UNTIL. Eine Serie mit COUNT und ohne UNTIL galt damit als
     * unendlich, auch wenn ihr letzter Termin gestern war - siehe die
     * Erklaerung bei awm_serie_ende(). */
    $ende = '';
    foreach ($serien as $s) {
        $e = awm_serie_ende($s);
        if ($e === '99991231') { $ende = '99991231'; break; }
        if ($e > $ende) { $ende = $e; }
    }
    $st['letzter'] = $ende === '99991231' ? '' : $ende;
    $grenze = date('Ymd', strtotime('+30 days'));
    if ($st['ok'] && $ende !== '99991231' && $ende < $grenze) {
        $st['warnung'] = 1; // in <30 Tagen keine Termine mehr -> Link erneuern
    }
    /* Ersatzlos gestrichene Termine.
     *
     * Gemessen am echten AWM-Kalender: die Papiertonne hat drei EXDATE-
     * Termine ohne Ersatz, aus dem 14-Tage-Takt werden einmal 28 und einmal
     * 42 Tage. Bis 1.3.8 sagte das Plugin dazu nichts. */
    $st['luecken'] = awm_luecken($serien, $cal);
    $st['luecke_tage'] = 0;
    if ($st['luecken']) {
        $tn = awm_tagnummer($st['luecken'][0]['datum']) - $heute_nr;
        $st['luecke_tage'] = max(0, $tn);
    }
    // Unteilbar, damit awm.php nie eine halb geschriebene Datei liest.
    awm_json_schreiben($cache, $st);
    $kurz = array();
    foreach ($arten as $k) { $kurz[] = strtoupper(substr($k, 0, 1)) . '=' . $st['morgen'][$k]; }
    awm_log_if_changed('zustand_' . $cal, 'morgen ' . implode(' ', $kurz)
        . ' | Warnung=' . $st['warnung'] . ' | Abruf=' . $st['abruf_ok']);
    if ($st['warnung']) {
        awm_notify(3, awm_t_oder('MELDUNG.KALENDER_ENDET',
                    'Der Abfuhrkalender liefert in weniger als 30 Tagen keine Termine mehr. '
                    . 'Die Jahres-Erneuerung laeuft automatisch; klappt sie nicht, muss ein '
                    . 'frischer iCal-Link beim Entsorger geholt werden.'), 'jahr' . $cal);
    }
    return $st;
}

/* ==================================================================
 * Sondertage, Ruhezeiten und Urlaub
 *
 * Bis 1.3.8 sprach das Haus auch dann, wenn niemand da war. Die Quelle der
 * Sondertage ist das (optionale) LoxBerry-Plugin "Ferien und Feiertage" -
 * ist es nicht installiert, sind alle Werte 0 und es bleibt bei der
 * Uhrzeitregel. Uebernommen aus dem Abfahrts-Assistenten, wo es geprueft
 * laeuft; nur das Kuerzel ist getauscht.
 * ================================================================== */

function awm_webport() {
    $p = awm_paths();
    $gj = $p['lbhome'] . '/config/system/general.json';
    if ($p['lbhome'] !== '' && is_file($gj)) {
        $d = json_decode((string) @file_get_contents($gj), true);
        if (isset($d['Webserver']['Port']) && (int) $d['Webserver']['Port'] > 0) {
            return (int) $d['Webserver']['Port'];
        }
    }
    return 80;
}

/** Sondertage von heute bzw. eines beliebigen Tages (Ymd). */
function awm_daytype($tag = null)
{
    static $mem = array();
    $tag = $tag === null ? date('Ymd') : (string) $tag;
    if (isset($mem[$tag])) {
        return $mem[$tag];
    }
    $leer = array('feiertag' => 0, 'ferien' => 0, 'urlaub' => 0, 'quelle' => 'keine', 'name' => '');
    $cachef = awm_tmpdir() . '/daytype_' . $tag . '.json';
    if (is_file($cachef) && time() - filemtime($cachef) < 900) {
        $c = json_decode((string) @file_get_contents($cachef), true);
        if (is_array($c)) { return $mem[$tag] = ($c + $leer); }
    }
    $res = $leer;
    // 1) Die Bibliothek des Ferien-Plugins unmittelbar einbinden.
    //    WICHTIG: LBPPLUGINDIR zeigt hier auf DIESES Plugin - ohne
    //    Umschalten suchte das Ferien-Plugin im falschen Konfigurations-
    //    und Datenverzeichnis.
    $p = awm_paths();
    $kandidaten = array();
    if ($p['lbhome'] !== '') {
        $kandidaten[] = $p['lbhome'] . '/webfrontend/html/plugins/ferien/ferien_lib.php';
    }
    $kandidaten[] = dirname(dirname(__DIR__)) . '/html/plugins/ferien/ferien_lib.php';
    foreach ($kandidaten as $cand) {
        if (!is_file($cand)) { continue; }
        $merker = getenv('LBPPLUGINDIR');
        putenv('LBPPLUGINDIR=ferien');
        include_once $cand;
        if (function_exists('fer_data') && function_exists('fer_day')) {
            $d = fer_data();
            $info = @fer_day($d, $tag);
            if (is_array($info)) {
                $res['feiertag'] = !empty($info['feiertag']) ? 1 : 0;
                $res['ferien'] = !empty($info['ferien']) ? 1 : 0;
                $res['urlaub'] = !empty($info['urlaub']) ? 1 : 0;
                $res['name'] = (string) (isset($info['feiertag_name']) && $info['feiertag_name'] !== ''
                                ? $info['feiertag_name']
                                : (isset($info['ferien_name']) ? $info['ferien_name'] : ''));
                $res['quelle'] = 'Ferien-Plugin';
            }
        }
        putenv($merker === false ? 'LBPPLUGINDIR' : 'LBPPLUGINDIR=' . $merker);
        break;
    }
    // 2) Ersatzweise die JSON-Schnittstelle - aber nur fuer HEUTE, mehr
    //    bietet sie nicht.
    if ($res['quelle'] === 'keine' && $tag === date('Ymd') && $p['lbhome'] !== '') {
        $js = awm_http_get('http://127.0.0.1:' . awm_webport() . '/plugins/ferien/ferien.php?json=1', 4);
        $d = @json_decode((string) $js, true);
        if (is_array($d) && isset($d['heute'])) {
            $res['feiertag'] = !empty($d['heute']['feiertag']) ? 1 : 0;
            $res['ferien'] = !empty($d['heute']['ferien']) ? 1 : 0;
            $res['urlaub'] = !empty($d['heute']['urlaub']) ? 1 : 0;
            $res['quelle'] = 'Ferien-Plugin (JSON)';
        }
    }
    awm_json_schreiben($cachef, $res);
    return $mem[$tag] = $res;
}

/**
 * Darf jetzt gesprochen werden? Rueckgabe: true/false, Grund ueber &$grund.
 */
function awm_ruhe_aktiv(&$grund = '')
{
    $cfg = awm_config();
    $r = $cfg['ruhe'];
    $grund = '';
    $bis = preg_replace('/[^0-9]/', '', (string) $r['bis_datum']);
    if (strlen($bis) === 8 && date('Ymd') <= $bis) {
        $grund = 'Ansage ist bis zum ' . substr($bis, 6, 2) . '.' . substr($bis, 4, 2) . '.' . substr($bis, 0, 4) . ' ausgesetzt';
        return true;
    }
    if (!empty($r['urlaub'])) {
        $t = awm_daytype();
        if (!empty($t['urlaub'])) {
            $grund = 'Urlaub (Quelle: ' . $t['quelle'] . ')';
            return true;
        }
    }
    if (!empty($r['nachts'])) {
        $von = preg_match('/^\d{1,2}:\d{2}$/', (string) $r['von']) ? $r['von'] : '22:00';
        $bis2 = preg_match('/^\d{1,2}:\d{2}$/', (string) $r['bis']) ? $r['bis'] : '07:00';
        $jetzt = date('H:i');
        $drin = ($von <= $bis2) ? ($jetzt >= $von && $jetzt < $bis2)
                                : ($jetzt >= $von || $jetzt < $bis2);
        if ($drin) {
            $grund = 'Sperrzeit ' . $von . ' bis ' . $bis2;
            return true;
        }
    }
    return false;
}

/* ---------------- Ansage-/Push-Fenster (fuer Loxone) ---------------- */

/** 1 innerhalb von 10 Minuten ab der Erinnerungs-Uhrzeit, wenn morgen etwas faellig ist. */
function awm_ann_active($st = null) {
    $cfg = awm_config();
    if ($st === null) {
        $st = awm_state();
    }
    $m = $st['morgen'];
    $etwas = false;
    foreach (array_keys(awm_tonnenarten()) as $k) {
        if (!empty($m[$k])) { $etwas = true; break; }
    }
    if (!$etwas) {
        return 0;
    }
    $when = preg_match('/^\d{1,2}:\d{2}$/', (string) $cfg['notify']['time']) ? $cfg['notify']['time'] : '18:00';
    list($hh, $mm) = explode(':', $when);
    $start = mktime((int) $hh, (int) $mm, 0);
    return (time() >= $start && time() < $start + 600) ? 1 : 0;
}

/** Ein Aktionstoken erzeugen.
 *
 * Der Zeichenvorrat laesst i, l, o und 0/1 weg: das Token steht in der
 * Oberflaeche zum Abschreiben, und diese Zeichen verwechselt man dabei.
 * Uebernommen aus dem Saugroboter-Plugin, damit alle Linien dasselbe tun.
 */
function awm_token_erzeugen($laenge = 24) {
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}

/** Test-Push-Merker (5 Minuten nach Klick auf "Test-Pushnachricht"). */
function awm_ptest_active() {
    $f = awm_tmpdir() . '/ptest';
    return (is_file($f) && time() - filemtime($f) < 300) ? 1 : 0;
}

/** Quittierung "Tonne steht draussen" - gilt bis Mitternacht. */
function awm_ack_datei() {
    return awm_tmpdir() . '/ack_' . date('Ymd');
}

function awm_ack_active() {
    return is_file(awm_ack_datei()) ? 1 : 0;
}

function awm_ack_setzen() {
    @file_put_contents(awm_ack_datei(), '1');
    awm_log('Quittierung erhalten - die zweite Ansage entfaellt heute.');
    return 1;
}

/**
 * Die Meldeflags an EINER Stelle: ann, audio, push, ptest, ack, ruhe.
 *
 * Sie standen bis 1.3.5 nur in der HTTP-Antwort. Wer auf MQTT umstellte,
 * verlor sie ersatzlos: kein Meldefenster, keine Freigaben und vor allem
 * kein PTEST, also keine Moeglichkeit mehr, den Push-Weg zu pruefen, ohne
 * auf einen echten Abholtermin zu warten.
 *
 * Seit 1.3.6 liefert diese Funktion die Werte fuer beide Wege. Sie koennen
 * damit nicht mehr auseinanderlaufen.
 *
 * ann gilt fuer die Kalender, die eine Ansage haben - bis 1.3.8 war das
 * fest der erste. Fuer ein Ferienhaus mit eigenem Music Server war das die
 * falsche Antwort; jetzt entscheidet die Einstellung des Kalenders.
 */
function awm_meldeflags($st = null, $cal = 1)
{
    $cfg = awm_config();
    $c = awm_cal($cal);
    $ansage = $c === null ? ((int) $cal === 1) : !empty($c['ansage']);
    $g = '';
    return array(
        'ann'   => $ansage ? awm_ann_active($st) : 0,
        'audio' => empty($cfg['notify']['audio']) ? 0 : 1,
        'push'  => empty($cfg['notify']['push']) ? 0 : 1,
        'ptest' => awm_ptest_active(),
        'ack'   => awm_ack_active(),
        'ruhe'  => awm_ruhe_aktiv($g) ? 1 : 0,
    );
}

/* ==================================================================
 * Die Felder - EINE Quelle fuer Loxone-Zeile, MQTT und Vorlage
 *
 * DIE REIHENFOLGE DER ERSTEN NEUNZEHN FELDER DARF SICH NICHT AENDERN.
 *
 * Nicht nur wegen der 1:1-Kompatibilitaet zum alten muell.php, sondern aus
 * einem zweiten, weniger offensichtlichen Grund: Loxone sucht bei einem
 * "Virtuellen HTTP-Eingang Befehl" den Suchtext WOERTLICH und nimmt den
 * ERSTEN Treffer in der Zeile. Ein Suchtext, der nur den Feldnamen REST
 * mit Gleichheitszeichen nennt, steckt damit auch in HREST= und TREST=.
 *
 * Neue Felder deshalb immer HINTEN anhaengen. Und seit 1.4.0 traegt jeder
 * erzeugte Suchtext das Semikolon mit (awm_check) - damit ist die Falle
 * fuer neue Vorlagen zu. Alte Vorlagen ohne Semikolon funktionieren
 * weiter, weil REST, BIO, PAPIER und WERT vorn stehen.
 * ================================================================== */

/**
 * Der Suchtext fuer einen Virtuellen HTTP-Eingang - an EINER Stelle.
 *
 * REGELN_3 A11: das Trennzeichen gehoert in den Suchtext. Am 20.08.2026 in
 * drei Plugins gemessen: der Suchtext fuer das Feld KM traf zuerst
 * INSPKM= und las damit die
 * Inspektionsvorgabe statt des Kilometerstands - beide Zahlen sahen aus wie
 * ein Kilometerstand, es fiel monatelang nicht auf.
 *
 * Diese Funktion ist die einzige Stelle, an der das Muster entsteht. In den
 * betroffenen Plugins stand es fuenfmal woertlich im Quelltext - einmal in
 * der Vorlage und viermal in der Oberflaeche -, und genau diese Verdopplung
 * liess die Regel auseinanderlaufen.
 */
function awm_check($feld)
{
    return '\i;' . $feld . '=\i\v';
}

/**
 * Alle Felder in ihrer verbindlichen Reihenfolge.
 *
 * Je Feld: analog, min, max, einheit, text, lox (in die Vorlage?),
 * mqtt (Themenname; '' = nicht ueber MQTT), gruppe (fuer die Vorlage).
 */
function awm_feldliste()
{
    $k = awm_tonnen_kuerzel();
    $arten = awm_tonnenarten();
    $f = array();
    $f['REST'] = null; // Platzhalter, wird unten gefuellt - Reihenfolge!

    $f = array();
    // --- 1. Der historische Kopf, Feld fuer Feld wie in 1.0.2 ---
    $f['REST']   = array(0, 0, 1, '', 'Restmuell: morgen faellig', 1, 'rest_morgen', 'rest');
    $f['BIO']    = array(0, 0, 1, '', 'Biotonne: morgen faellig', 1, 'bio_morgen', 'bio');
    $f['PAPIER'] = array(0, 0, 1, '', 'Papiertonne: morgen faellig', 1, 'papier_morgen', 'papier');
    $f['DATUM']  = array(0, 0, 99991231, '', 'Datum von morgen (JJJJMMTT)', 0, 'datum_morgen', '');
    $f['WERT']   = array(0, 0, 1, '', 'Wertstoff/Gelb: morgen faellig', 1, 'wert_morgen', 'wert');
    $f['OK']     = array(0, 0, 1, '', '1 = Kalenderdaten vorhanden', 1, 'ok', '');
    $f['WARN']   = array(0, 0, 1, '', '1 = Kalender endet in weniger als 30 Tagen (Link erneuern)', 1, 'warnung', '');
    foreach (array('rest', 'bio', 'papier', 'wert') as $a) {
        $f['H' . $k[$a]] = array(0, 0, 1, '', $arten[$a]['text'] . ': heute faellig', 1, $a . '_heute', $a);
    }
    foreach (array('rest', 'bio', 'papier', 'wert') as $a) {
        $f['T' . $k[$a]] = array(1, -1, 400, 'Tage', $arten[$a]['text'] . ': Tage bis zur naechsten Leerung (-1 = unbekannt)', 1, 'tage_' . $a, $a);
    }
    $f['ANN']   = array(0, 0, 1, '', '1 = Erinnerungsfenster jetzt (10 min ab der eingestellten Uhrzeit)', 1, 'ann', '');
    $f['AUDIO'] = array(0, 0, 1, '', 'Ansage in der Plugin-Konfiguration freigegeben', 1, 'audio', '');
    $f['PUSH']  = array(0, 0, 1, '', 'Pushnachricht in der Plugin-Konfiguration freigegeben', 1, 'push', '');
    $f['PTEST'] = array(0, 0, 1, '', '1 = Test-Pushnachricht angefordert (5 Minuten)', 1, 'ptest', '');

    // --- 2. Ab hier NUR anhaengen (1.4.0) ---
    // Die vier weiteren Tonnenarten. Bis 1.3.8 waren sie in der
    // Zuordnungstabelle waehlbar, kamen aber nirgends an.
    foreach (array('glas', 'sperr', 'gruen', 'schad') as $a) {
        $f[$k[$a]] = array(0, 0, 1, '', $arten[$a]['text'] . ': morgen faellig', 1, $a . '_morgen', $a);
    }
    foreach (array('glas', 'sperr', 'gruen', 'schad') as $a) {
        $f['H' . $k[$a]] = array(0, 0, 1, '', $arten[$a]['text'] . ': heute faellig', 1, $a . '_heute', $a);
    }
    foreach (array('glas', 'sperr', 'gruen', 'schad') as $a) {
        $f['T' . $k[$a]] = array(1, -1, 400, 'Tage', $arten[$a]['text'] . ': Tage bis zur naechsten Leerung (-1 = unbekannt)', 1, 'tage_' . $a, $a);
    }
    // Datum der naechsten Leerung, einmal als JJJJMMTT (wie das MQTT-Thema
    // es seit jeher liefert) und einmal als Loxone-Zeit zum Rechnen.
    foreach (array_keys($arten) as $a) {
        $f['D' . $k[$a]] = array(1, 0, 99991231, '', $arten[$a]['text'] . ': Datum der naechsten Leerung (JJJJMMTT, 0 = keins)', 0, 'datum_' . $a, $a);
    }
    foreach (array_keys($arten) as $a) {
        $f['S' . $k[$a]] = array(1, 0, 2147483647, 's', $arten[$a]['text'] . ': naechste Leerung als Loxone-Zeit (Sekunden seit 01.01.2009)', 1, 'zeit_' . $a, $a);
    }
    // Die naechste Abholung ueberhaupt
    $f['TNEXT'] = array(1, -1, 400, 'Tage', 'Tage bis zur naechsten Abholung (irgendeine Tonne)', 1, 'tage_next', '');
    $f['DNEXT'] = array(1, 0, 99991231, '', 'Datum der naechsten Abholung (JJJJMMTT)', 0, 'datum_next', '');
    $f['SNEXT'] = array(1, 0, 2147483647, 's', 'Naechste Abholung als Loxone-Zeit', 1, 'zeit_next', '');
    // Ausfallerkennung
    $f['AGE']     = array(1, -1, 100000, 'h', 'Alter der Kalenderdatei in Stunden (-1 = keine Datei)', 1, 'alter', '');
    $f['FETCH']   = array(0, 0, 1, '', '1 = der letzte Abrufversuch war erfolgreich', 1, 'abruf', '');
    $f['LETZTER'] = array(1, 0, 2147483647, 's', 'Letzter Termin im Kalender als Loxone-Zeit (0 = unbegrenzt)', 1, 'letzter', '');
    $f['HINW']    = array(0, 0, 1, '', '1 = der Entsorger meldet einen Hinweis (Text ueber ?text=1)', 1, 'hinweis_da', '');
    $f['LUECKE']  = array(1, 0, 400, 'Tage', 'Tage bis zum naechsten ersatzlos gestrichenen Termin (0 = keiner)', 1, 'luecke', '');
    // Quittierung und Ruhe
    $f['ACK']  = array(0, 0, 1, '', '1 = heute quittiert ("Tonne steht draussen")', 1, 'ack', '');
    $f['RUHE'] = array(0, 0, 1, '', '1 = Ansage ausgesetzt (Urlaub, Sperrzeit)', 1, 'ruhe', '');
    return $f;
}

/**
 * Alle Werte eines Kalenders - die EINZIGE Rechnung.
 *
 * Rueckgabe: Feldname => Wert, in der Reihenfolge von awm_feldliste().
 */
function awm_werte($st = null, $cal = 1)
{
    if ($st === null) {
        $st = awm_state(false, $cal);
    }
    $k = awm_tonnen_kuerzel();
    $flags = awm_meldeflags($st, $cal);
    $w = array();
    foreach (array('rest', 'bio', 'papier') as $a) { $w[$k[$a]] = (int) $st['morgen'][$a]; }
    $w['DATUM'] = date('Ymd', strtotime('+1 day'));
    $w['WERT'] = (int) $st['morgen']['wert'];
    $w['OK'] = (int) $st['ok'];
    $w['WARN'] = (int) $st['warnung'];
    foreach (array('rest', 'bio', 'papier', 'wert') as $a) { $w['H' . $k[$a]] = (int) $st['heute'][$a]; }
    foreach (array('rest', 'bio', 'papier', 'wert') as $a) { $w['T' . $k[$a]] = (int) $st['tage'][$a]; }
    $w['ANN'] = (int) $flags['ann'];
    $w['AUDIO'] = (int) $flags['audio'];
    $w['PUSH'] = (int) $flags['push'];
    $w['PTEST'] = (int) $flags['ptest'];
    foreach (array('glas', 'sperr', 'gruen', 'schad') as $a) { $w[$k[$a]] = (int) $st['morgen'][$a]; }
    foreach (array('glas', 'sperr', 'gruen', 'schad') as $a) { $w['H' . $k[$a]] = (int) $st['heute'][$a]; }
    foreach (array('glas', 'sperr', 'gruen', 'schad') as $a) { $w['T' . $k[$a]] = (int) $st['tage'][$a]; }
    foreach (array_keys(awm_tonnenarten()) as $a) {
        $w['D' . $k[$a]] = $st['naechste'][$a] !== '' ? (int) $st['naechste'][$a] : 0;
    }
    foreach (array_keys(awm_tonnenarten()) as $a) {
        $w['S' . $k[$a]] = awm_loxzeit($st['naechste'][$a]);
    }
    $w['TNEXT'] = (int) $st['next']['tage'];
    $w['DNEXT'] = $st['next']['datum'] !== '' ? (int) $st['next']['datum'] : 0;
    $w['SNEXT'] = awm_loxzeit($st['next']['datum']);
    $w['AGE'] = (int) $st['alter'];
    $w['FETCH'] = (int) $st['abruf_ok'];
    $w['LETZTER'] = awm_loxzeit($st['letzter']);
    $w['HINW'] = $st['hinweis'] !== '' ? 1 : 0;
    // Die naechste ersatzlose Luecke - berechnet aus dem Zustand, damit die
    // teure Suche nicht in jede Abfrage faellt.
    $w['LUECKE'] = 0;
    if (!empty($st['luecke_tage'])) { $w['LUECKE'] = (int) $st['luecke_tage']; }
    $w['ACK'] = (int) $flags['ack'];
    $w['RUHE'] = (int) $flags['ruhe'];
    return $w;
}

/** Die Loxone-Textzeile aus den Werten. */
function awm_zeile($werte)
{
    $teile = array();
    foreach ($werte as $name => $wert) {
        $teile[] = $name . '=' . awm_mqtt_wert_saeubern($wert);
    }
    return 'MUELL;' . implode(';', $teile);
}

/* ---------------- MQTT (LoxBerry MQTT Gateway, UDP-Relay) ---------------- */

/**
 * Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE. Ein Zeilenumbruch im Wert - aus einer
 * Fehlermeldung des Betriebssystems, einem Geraetenamen oder der Ausgabe
 * eines Systembefehls - zerlegt die Uebertragung, und aus den Bruchstuecken
 * bildet das Gateway erfundene Themen. Ein Tabulator schadet ebenso, weil
 * Leerzeichen Thema und Wert trennt. Ein Semikolon zerlegt die Loxone-Zeile.
 */
function awm_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t", ';'), array(' ', ' ', ' ', ' ', ','), (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

/** Alle MQTT-Themen mit Bedeutung - fuer die Tabelle im Reiter MQTT. */
function awm_mqtt_themen()
{
    $out = array();
    foreach (awm_feldliste() as $name => $f) {
        if ($f[6] === '') { continue; }
        $out[$f[6]] = $f[4];
    }
    $out['hinweis'] = 'Hinweistext des Entsorgers ("-" = keiner)';
    $out['text_morgen'] = 'Fertiger Satz: was morgen faellig ist';
    $out['text_heute'] = 'Fertiger Satz: was heute faellig ist';
    $out['text_naechste'] = 'Fertiger Satz: die naechste Abholung';
    return $out;
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
    // socket_create gibt es nur mit der Erweiterung ext-sockets. Das @ davor
    // unterdrueckt KEIN "Call to undefined function" - der Cron-Lauf waere
    // mit einem Fatal abgebrochen, und zwar jede Minute.
    if (!function_exists('socket_create')) {
        awm_log('MQTT: die PHP-Erweiterung "sockets" fehlt - ohne sie laesst sich das '
              . 'Gateway nicht ueber UDP erreichen. MQTT bleibt aus.');
        awm_notify(3, awm_t_oder('MELDUNG.SOCKETS',
              'MQTT ist eingeschaltet, aber die PHP-Erweiterung „sockets“ fehlt. '
              . 'Ohne sie erreicht das Plugin das MQTT-Gateway nicht.'), 'sockets');
        return;
    }
    $prefix = trim((string) $cfg['mqtt_topic']) !== '' ? trim((string) $cfg['mqtt_topic']) : 'awm';
    if ((int) $cal > 1) { // Kalender 1 behaelt die kurzen Topics
        $prefix .= '/' . (int) $cal;
    }
    /* Dieselbe Quelle wie die HTTP-Zeile. Bis 1.3.8 rechnete jeder Weg
     * selbst, und sie liefen auseinander: MQTT hatte 23 Werte, HTTP 19. */
    $msgs = array();
    $werte = awm_werte($st, $cal);
    foreach (awm_feldliste() as $name => $f) {
        if ($f[6] === '' || !isset($werte[$name])) { continue; }
        $msgs[$f[6]] = $werte[$name];
    }
    $msgs['hinweis'] = $st['hinweis'] !== '' ? $st['hinweis'] : '-';
    $msgs['text_morgen'] = awm_text_morgen($st);
    $msgs['text_heute'] = awm_text_heute($st);
    $msgs['text_naechste'] = awm_text_naechste($st);
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) {
        awm_log('MQTT: UDP-Buchse liess sich nicht oeffnen - keine Veroeffentlichung.');
        return;
    }
    foreach ($msgs as $k => $v) {
        $msg = 'publish ' . $prefix . '/' . $k . ' ' . awm_mqtt_wert_saeubern($v);
        @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $udpport);
    }
    socket_close($s);
}

/* ==================================================================
 * Fertige Saetze
 *
 * Bis 1.3.8 musste der Anwender aus vier Flags den Satz "Morgen: Restmuell
 * und Biotonne" im Miniserver selbst zusammensetzen - mit Textbausteinen,
 * und in jeder Anlage neu. Diese drei Funktionen liefern ihn fertig, fuer
 * die Ansage, fuer den Textbaustein und fuer ?text=1.
 * ================================================================== */

/** Namen der Tonnen einer Bin-Liste, in der Reihenfolge der Tonnenarten. */
function awm_tonnennamen($bins)
{
    $out = array();
    foreach (awm_tonnenarten() as $k => $v) {
        if (!empty($bins[$k])) { $out[] = $v['text']; }
    }
    return $out;
}

/** "A, B und C" - mit dem Bindewort aus der Sprachdatei. */
function awm_aufzaehlung($namen)
{
    if (!$namen) { return ''; }
    if (count($namen) === 1) { return $namen[0]; }
    $letzte = array_pop($namen);
    $und = awm_t('TEXT.UND_WORT');
    if ($und === '' || strpos($und, 'TEXT.') === 0) { $und = 'und'; }
    return implode(', ', $namen) . ' ' . $und . ' ' . $letzte;
}

function awm_text_morgen($st = null)
{
    if ($st === null) { $st = awm_state(); }
    $n = awm_tonnennamen($st['morgen']);
    if (!$n) { return awm_t_oder('TEXT.S_MORGEN_NICHTS', 'Morgen wird nichts abgeholt.'); }
    return sprintf(awm_t_oder('TEXT.S_MORGEN', 'Morgen: %s.'), awm_aufzaehlung($n));
}

function awm_text_heute($st = null)
{
    if ($st === null) { $st = awm_state(); }
    $n = awm_tonnennamen($st['heute']);
    if (!$n) { return awm_t_oder('TEXT.S_HEUTE_NICHTS', 'Heute wird nichts abgeholt.'); }
    return sprintf(awm_t_oder('TEXT.S_HEUTE', 'Heute: %s.'), awm_aufzaehlung($n));
}

function awm_text_naechste($st = null)
{
    if ($st === null) { $st = awm_state(); }
    if ((int) $st['next']['tage'] < 0) {
        return awm_t_oder('TEXT.S_NAECHSTE_KEINE', 'Kein Termin bekannt.');
    }
    $bins = array();
    foreach ((array) $st['next']['tonnen'] as $k) { $bins[$k] = 1; }
    $d = $st['next']['datum'];
    $datum = substr($d, 6, 2) . '.' . substr($d, 4, 2) . '.' . substr($d, 0, 4);
    return sprintf(awm_t_oder('TEXT.S_NAECHSTE', '%s am %s (in %d Tagen).'),
                   awm_aufzaehlung(awm_tonnennamen($bins)), $datum, (int) $st['next']['tage']);
}

/** Sprachtext mit Rueckfall auf einen festen Text. */
function awm_t_oder($schluessel, $ersatz)
{
    $t = awm_t($schluessel);
    return ($t === '' || $t === $schluessel) ? $ersatz : $t;
}

/* ---------------- Ansage (TTS) - identisch zum Abfahrtsassistenten ---------------- */

/** TTS-URL fuer die konfigurierte Ausgabe bauen. Fuer mode=audioserver: null. */
function awm_tts_url($text, $zonen = '') {
    $cfg = awm_config();
    $tts = $cfg['tts'];
    $mode = $tts['mode'];
    if ($mode === 'audioserver') {
        return null; // Original Loxone Audioserver: TTS nur ueber Loxone Config (Textgenerator -> TTS-Eingang)
    }
    if (trim((string) $zonen) !== '') {
        $tts['zones'] = trim((string) $zonen);   // Zonen des Kalenders haben Vorrang
    }

    /* Zonenliste EINMAL fuer alle Modi normalisieren. */
    $zl = array();
    foreach (explode(',', (string) $tts['zones']) as $z) {
        $z = trim($z);
        if ($z !== '') { $zl[] = $z; }
    }
    $tts['zones'] = implode(',', $zl);
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
    // Die IP wird nur verlangt, wenn die Vorlage sie auch verwendet.
    if ((string) $tts['ip'] === '' && strpos($tpl, '{ip}') !== false) {
        return '';
    }
    return str_replace(
        array('{ip}', '{port}', '{zones}', '{vol}', '{lang}', '{text}'),
        array($tts['ip'], (int) $tts['port'], $tts['zones'], (int) $tts['volume'], $tts['lang'], rawurlencode($text)),
        $tpl
    );
}

function awm_say($text, $zonen = '') {
    $url = awm_tts_url($text, $zonen);
    if ($url === null) {
        awm_log('Ansage: Modus "Original Loxone Audioserver" - Sprachausgabe erfolgt ueber Loxone Config (Textgenerator)');
        return false;
    }
    if ($url === '') {
        awm_log('Ansage uebersprungen: keine TTS-IP konfiguriert');
        return false;
    }
    $grund = '';
    $r = awm_http_get($url, 10, $grund);
    awm_log('Ansage gesendet: "' . $text . '" -> ' . ($r !== false ? 'OK' : 'FEHLER: ' . $grund));
    return $r !== false;
}

/**
 * Ansagetext fuer die Tonnen von morgen.
 *
 * Bis 1.3.8 stand er fest im Code, auf Deutsch, mit "die Restmuelltonne",
 * "die Biotonne" - in der englischen Oberflaeche sprach das Haus trotzdem
 * deutsch. Jetzt kommt die Vorlage aus der Konfiguration, ersatzweise aus
 * der Sprachdatei. Platzhalter: {tonnen}, {anzahl}, {datum}, {wochentag}.
 */
function awm_ansage_bauen($vorlage, $bins, $ymd)
{
    $namen = awm_tonnennamen($bins);
    if (!$namen) {
        return '';
    }
    $vorlage = trim((string) $vorlage);
    if ($vorlage === '') {
        $vorlage = count($namen) === 1
            ? awm_t_oder('TEXT.ANSAGE_EINE', 'Hallo! Morgen wird {tonnen} abgeholt. Bitte rausstellen.')
            : awm_t_oder('TEXT.ANSAGE_MEHRERE', 'Hallo! Morgen werden {tonnen} abgeholt. Bitte rausstellen.');
    }
    $wochentage = explode(',', awm_t_oder('TEXT.WOCHENTAGE',
        'Montag,Dienstag,Mittwoch,Donnerstag,Freitag,Samstag,Sonntag'));
    $wnr = awm_wochentag_aus_tagnummer(awm_tagnummer($ymd));
    return str_replace(
        array('{tonnen}', '{anzahl}', '{datum}', '{wochentag}'),
        array(awm_aufzaehlung($namen), count($namen),
              substr($ymd, 6, 2) . '.' . substr($ymd, 4, 2) . '.',
              isset($wochentage[$wnr - 1]) ? trim($wochentage[$wnr - 1]) : ''),
        $vorlage);
}

/** Ansagetext fuer die Tonnen von morgen (Vorabend). */
function awm_announce_text($st = null, $cal = 1) {
    if ($st === null) {
        $st = awm_state(false, $cal);
    }
    $cfg = awm_config();
    return awm_ansage_bauen($cfg['ansage']['vorlage'], $st['morgen'],
                            date('Ymd', strtotime('+1 day')));
}

/** Ansagetext am Abholmorgen ("die Tonne steht noch drin"). */
function awm_announce_text2($st = null, $cal = 1) {
    if ($st === null) {
        $st = awm_state(false, $cal);
    }
    $cfg = awm_config();
    $v = trim((string) $cfg['ansage']['vorlage2']);
    if ($v === '') {
        $v = awm_t_oder('TEXT.ANSAGE_MORGENS',
                        'Guten Morgen! Heute wird {tonnen} abgeholt. Steht die Tonne schon draussen?');
    }
    return awm_ansage_bauen($v, $st['heute'], date('Ymd'));
}

/**
 * Cron-Pruefung: Ansage am Vorabend und am Abholmorgen.
 *
 * ZEITFENSTER STATT MINUTENGLEICHHEIT. Bis 1.3.8 stand hier
 *
 *     if (date('H:i') !== $target) { return; }
 *
 * waehrend der Merker ANN ein Zehn-Minuten-Fenster benutzt. Zieht ein
 * Cron-Lauf ueber die Zielminute - der Abruf wartet je Kalender bis 20 s,
 * die Jahres-Erneuerung je Kandidat bis 25 s -, entfiel die Ansage still
 * bis zum naechsten Tag. Der Tagesmerker verhindert Doppelansagen ohnehin,
 * ein Fenster ist also gefahrlos.
 */
function awm_announce_check() {
    $cfg = awm_config();
    if (empty($cfg['notify']['audio'])) {
        return;
    }
    $grund = '';
    if (awm_ruhe_aktiv($grund)) {
        awm_log_if_changed('ruhe', 'Ansage ausgesetzt: ' . $grund);
        return;
    }
    $imFenster = function ($zeit) {
        if (!preg_match('/^\d{1,2}:\d{2}$/', (string) $zeit)) { return false; }
        list($hh, $mm) = explode(':', $zeit);
        $start = mktime((int) $hh, (int) $mm, 0);
        return time() >= $start && time() < $start + 600;
    };

    foreach (awm_cals() as $n => $c) {
        if (empty($c['ansage'])) {
            continue;
        }
        $st = awm_state(false, $n);

        // 1. Vorabend
        $when = preg_match('/^\d{1,2}:\d{2}$/', (string) $cfg['notify']['time']) ? $cfg['notify']['time'] : '18:00';
        $flag = awm_tmpdir() . '/announced_' . $n . '_' . date('Ymd');
        if ($imFenster($when) && !is_file($flag)) {
            @file_put_contents($flag, '1');
            $text = awm_announce_text($st, $n);
            if ($text === '') {
                awm_log('Ansage-Zeitpunkt ' . $when . ' (' . $c['name'] . '): morgen keine Abholung - keine Ansage');
            } else {
                awm_say($text, $c['zonen']);
            }
        }

        // 2. Abholmorgen - nur wenn nicht quittiert
        if (empty($cfg['notify']['audio2'])) {
            continue;
        }
        $when2 = preg_match('/^\d{1,2}:\d{2}$/', (string) $cfg['notify']['time2']) ? $cfg['notify']['time2'] : '06:30';
        $flag2 = awm_tmpdir() . '/announced2_' . $n . '_' . date('Ymd');
        if ($imFenster($when2) && !is_file($flag2)) {
            @file_put_contents($flag2, '1');
            if (awm_ack_active()) {
                awm_log('Morgen-Ansage entfaellt: heute wurde bereits quittiert.');
                continue;
            }
            $text = awm_announce_text2($st, $n);
            if ($text !== '') {
                awm_say($text, $c['zonen']);
            }
        }
    }
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
 * MUENCHEN BLEIBT UNVERAENDERT.
 * ================================================================== */

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
        // Die beiden folgenden nennt die Oberflaeche seit 1.1.0 als Quelle -
        // eine Strategie hatten sie bis 1.3.8 nicht und fielen in die
        // allgemeine Rueckfallebene, die nur bei genau einer Jahreszahl in
        // der Adresse ueberhaupt etwas tut.
        array('muster' => 'jumomind',
              'name' => 'Jumomind / MyMuell',
              'kand' => 'awm_renew_kand_jumomind',
              'fallback' => ''),
        array('muster' => 'mymuell',
              'name' => 'Jumomind / MyMuell',
              'kand' => 'awm_renew_kand_jumomind',
              'fallback' => ''),
        array('muster' => 'abfallkalender',
              'name' => 'ATURIS AbfallKalenderSystem',
              'kand' => 'awm_renew_kand_aturis',
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
 * Uebernommen wird nur ein Kalender MIT Terminen - und ab 1.4.0 nur einer
 * mit Terminen IN DER ZUKUNFT. Ohne die zweite Bedingung genuegte ein
 * beliebiger nicht-leerer Kalender: die allgemeine Rueckfallebene zaehlt
 * auch eine Kundennummer hoch, wenn sie zufaellig wie eine Jahreszahl
 * aussieht, und der fremde Kalender haette den eigenen Link ueberschrieben.
 * Rueckgabe: ICS-Text oder ''.
 */
function awm_renew_test($url) {
    $grund = '';
    $ics = awm_http_get($url, 25, $grund);
    if ($ics === false || strpos($ics, 'BEGIN:VCALENDAR') === false
        || substr_count($ics, 'BEGIN:VEVENT') < 1) {
        return '';
    }
    // Mindestens ein Termin ab heute - sonst ist es ein alter Kalender.
    $heute = date('Ymd');
    if (preg_match_all('/^DTSTART[^:\r\n]*:([^\r\n]+)/mi', $ics, $mm)) {
        foreach ($mm[1] as $roh) {
            $d = awm_ics_datum($roh);
            if ($d !== '' && $d >= $heute) {
                return $ics;
            }
        }
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
 * Jumomind / MyMuell: das Jahr steht als eigener Parameter
 *   ...&year=2026...  oder  .../ical/2026
 * Beide Formen kommen vor; die Kennungen (city_id, area_id) bleiben.
 */
function awm_renew_kand_jumomind($url) {
    $out = array();
    if (preg_match('/([?&]year=)(\d{4})/i', $url, $m)) {
        $neu = str_replace($m[0], $m[1] . ((int) $m[2] + 1), $url);
        if ($neu !== $url) { $out[] = $neu; }
    }
    if (preg_match('#(/)(\d{4})(/?)$#', $url, $m)) {
        $neu = substr($url, 0, -strlen($m[0])) . '/' . ((int) $m[2] + 1) . $m[3];
        if ($neu !== $url) { $out[] = $neu; }
    }
    return array_values(array_unique($out));
}

/**
 * ATURIS AbfallKalenderSystem: das Jahr steht als Parameter "jahr" oder
 * "year", je nach Gemeinde.
 */
function awm_renew_kand_aturis($url) {
    $out = array();
    if (preg_match('/([?&](?:jahr|year|kalenderjahr)=)(\d{4})/i', $url, $m)) {
        $neu = str_replace($m[0], $m[1] . ((int) $m[2] + 1), $url);
        if ($neu !== $url) { $out[] = $neu; }
    }
    if (!$out) {
        // Kein benannter Parameter: auf die allgemeine Regel zurueckfallen,
        // die nur bei genau einer Jahreszahl etwas tut.
        return awm_renew_kand_jahreszahl($url);
    }
    return $out;
}

/**
 * Rueckfallebene fuer Entsorger, deren Link-Aufbau (noch) nicht bekannt ist.
 *
 * Kommt das laufende oder das vergangene Jahr GENAU EINMAL in der URL vor,
 * wird es hochgezaehlt. "Genau einmal" ist Absicht: taucht die Zahl
 * mehrfach auf, ist nicht zu entscheiden, welche das Kalenderjahr ist, und
 * Raten waere schlimmer als Nichtstun. Uebernommen wird ohnehin nur, was
 * awm_renew_test() als Kalender mit KUENFTIGEN Terminen bestaetigt.
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

function awm_renew_scrape_awm($url, $cal = 1) {
    if (!preg_match('/year%5D=(\d{4})|year\]=(\d{4})/i', $url, $my)) {
        return array('', '');
    }
    $year = (int) ($my[1] !== '' ? $my[1] : $my[2]);
    $newyear = $year + 1;
    $base = preg_replace('/[?&]cHash=[0-9a-f]+/i', '', $url);
    $base = preg_replace('/([?&])tx_awmabfuhrkalender_abfuhrkalender(?:%5B|\[)section(?:%5D|\])=ics&?/i', '$1', $base);
    $base = preg_replace('/(year(?:%5D|\])=)' . $year . '/i', '${1}' . $newyear, $base);
    $grund = '';
    $html = awm_http_get($base, 25, $grund);
    if ($html === false) {
        awm_log('Jahres-Erneuerung: die AWM-Ergebnisseite antwortet nicht - ' . $grund);
        return array('', '');
    }
    $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
    // Absolute Adressen
    preg_match_all('#https?://www\.awm-muenchen\.de/[^"\'\s]*section(?:%5D|\])=ics[^"\'\s]*#i', $html, $mm);
    $kandidaten = $mm[0];
    // Und die relativen: TYPO3 schreibt Verweise auf die eigene Seite je
    // nach Einstellung als "/entsorgen/..." statt mit vollem Namen.
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
 *
 * Die Konfiguration wird unter derselben Sperre geschrieben, unter der auch
 * die Oberflaeche speichert. Ohne sie konnte ein Klick auf "Speichern" den
 * frisch erneuerten Link zurueckschreiben - mit der alten Adresse aus dem
 * Formular, und der Tagesmerker verhinderte dann bis zum naechsten Tag
 * einen zweiten Versuch.
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
        awm_notify(3, sprintf(awm_t_oder('MELDUNG.RENEW_FEHL',
                    'Die automatische Jahres-Erneuerung des Abfuhrkalenders "%s" ist '
                    . 'fehlgeschlagen. Bitte einen frischen iCal-Link beim Entsorger holen '
                    . 'und in der Plugin-Oberflaeche eintragen.'),
                    $c['name']), 'renew' . $cal);
        return false;
    }

    $sperre = awm_sperre('config');
    // Konfiguration aktualisieren
    $raw = json_decode((string) @file_get_contents($p['config']), true);
    if (!is_array($raw)) {
        if ($sperre) { flock($sperre, LOCK_UN); fclose($sperre); }
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
    // Schreiben wirklich geklappt hat.
    if (!awm_json_schreiben($p['config'], $raw, 0664, true)) {
        awm_log('Jahres-Erneuerung: neuer Link gefunden, liess sich aber NICHT speichern - alter Link bleibt gueltig');
        if ($sperre) { flock($sperre, LOCK_UN); fclose($sperre); }
        return false;
    }
    @copy($p['config'], $p['backup']);
    awm_datei_schreiben(awm_icsfile($cal), awm_utf8($ics));
    @unlink(awm_tmpdir() . '/state_' . (int) $cal . '.json');
    if ($sperre) { flock($sperre, LOCK_UN); fclose($sperre); }
    awm_log('Jahres-Erneuerung: neuer Link fuer Kalender ' . $cal . ' gespeichert');
    awm_notify(6, sprintf(awm_t_oder('MELDUNG.RENEW_OK',
                'Der Abfuhrkalender "%s" wurde automatisch auf das Folgejahr umgestellt. '
                . 'Die Termine sind wieder aktuell.'), $c['name']), 'renewok' . $cal);
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
    if (preg_match('/[?&](?:jahr|year|kalenderjahr)=(\d{4})/i', $url, $m)) {
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
        if ($c['url'] === '') { continue; }        // hochgeladen: nichts zu erneuern
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
 * Kalenderdiagnose
 *
 * Die Selbstpruefungen pruefen Rechenwege, nie die eigene Datei. Genau
 * daran waeren die drei Befunde vom 20.08.2026 (BYSETPOS, BYMONTH, YEARLY)
 * an der betroffenen Anlage aufgefallen - eine Regel, die das Plugin nur
 * halb versteht, sieht man nur, wenn man in die Datei sieht.
 * ================================================================== */

function awm_kalender_diagnose($cal = 1)
{
    $f = awm_icsfile($cal);
    $d = array('datei' => $f, 'da' => is_file($f) ? 1 : 0, 'bytes' => 0, 'stand' => '',
               'ereignisse' => 0, 'serien' => 0, 'einzel' => 0, 'rrules' => array(),
               'unbekannt' => array(), 'exdates' => 0, 'rdates' => 0,
               'luecken' => array(), 'erste' => '', 'letzte' => '');
    if (!is_file($f)) {
        return $d;
    }
    $d['bytes'] = filesize($f);
    $d['stand'] = date('d.m.Y H:i', filemtime($f));
    $serien = awm_series($cal);
    $d['ereignisse'] = count($serien);
    foreach ($serien as $s) {
        if ($s['freq'] === null || $s['freq'] === '') { $d['einzel']++; } else { $d['serien']++; }
        if ($s['rrule'] !== '') {
            if (!isset($d['rrules'][$s['rrule']])) { $d['rrules'][$s['rrule']] = 0; }
            $d['rrules'][$s['rrule']]++;
            foreach (awm_rrule_unbekannt($s['rrule']) as $u) {
                if (!in_array($u, $d['unbekannt'], true)) { $d['unbekannt'][] = $u; }
            }
        }
        $d['exdates'] += count($s['exdates']);
        $d['rdates'] += count($s['rdates']);
    }
    arsort($d['rrules']);
    $d['luecken'] = awm_luecken($serien, $cal);
    // Erster und letzter Termin im Vorausblick
    $n = awm_tagnummer(date('Ymd'));
    for ($i = 0; $i <= AWM_HORIZONT; $i++) {
        $ymd = awm_datum_aus_tagnummer($n + $i);
        $b = awm_bins($serien, $ymd, $cal);
        if ($b['texte']) {
            if ($d['erste'] === '') { $d['erste'] = $ymd; }
            $d['letzte'] = $ymd;
        }
    }
    return $d;
}

/* ==================================================================
 * Selbstpruefung der Erneuerung
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

    /* --- 2b. Jumomind und ATURIS (ab 1.4.0) --- */
    $jm = 'https://mymuell.jumomind.com/mmapp/api/ical?city_id=42&area_id=7&year=2026';
    $k = awm_renew_kand_jumomind($jm);
    $p(in_array('https://mymuell.jumomind.com/mmapp/api/ical?city_id=42&area_id=7&year=2027', $k, true),
       'Jumomind: der Jahresparameter wird hochgezaehlt');
    $p(count($k) === 1 && strpos($k[0], 'city_id=42') !== false && strpos($k[0], 'area_id=7') !== false,
       'Jumomind: die Kennungen bleiben unangetastet');
    $k2 = awm_renew_kand_jumomind('https://mymuell.jumomind.com/webmodul/beispiel/ical/2026');
    $p($k2 === array('https://mymuell.jumomind.com/webmodul/beispiel/ical/2027'),
       'Jumomind: auch die Form mit dem Jahr am Ende des Pfades');
    $s = awm_renew_strategie($jm);
    $p($s !== null && $s['kand'] === 'awm_renew_kand_jumomind',
       'Jumomind-Link waehlt jetzt eine eigene Strategie (bis 1.3.8: allgemeine Rueckfallebene)');

    $at = 'https://www.abfallkalender-beispiel.de/export.php?ort=12&strasse=345&jahr=2026';
    $k = awm_renew_kand_aturis($at);
    $p(count($k) === 1 && strpos($k[0], 'jahr=2027') !== false
       && strpos($k[0], 'strasse=345') !== false,
       'ATURIS: der Jahresparameter wird hochgezaehlt, die Adresse bleibt');
    $s = awm_renew_strategie($at);
    $p($s !== null && $s['kand'] === 'awm_renew_kand_aturis',
       'ATURIS-Link waehlt jetzt eine eigene Strategie');

    /* --- 3. Allgemeine Rueckfallebene --- */
    $j = (int) date('Y');
    $p(awm_renew_kand_jahreszahl('https://beispiel.de/kalender_' . $j . '.ics')
       === array('https://beispiel.de/kalender_' . ($j + 1) . '.ics'),
       'Allgemein: einzelne Jahreszahl wird hochgezaehlt');
    $p(awm_renew_kand_jahreszahl('https://beispiel.de/' . $j . '/kalender_' . $j . '.ics') === array(),
       'Allgemein: mehrdeutige Jahreszahl wird in Ruhe gelassen');
    $p(awm_renew_kand_jahreszahl('https://beispiel.de/kalender.ics') === array(),
       'Allgemein: Link ohne Jahreszahl liefert keinen Kandidaten');
    $s = awm_renew_strategie('https://entsorger-xy.de/ical.php?id=abc');
    $p($s !== null && $s['kand'] === 'awm_renew_kand_jahreszahl',
       'Unbekannter Entsorger landet in der Rueckfallebene');

    /* --- 4. Veraltet-Erkennung --- */
    $p(awm_renew_veraltet(str_replace('2026', (string) ($j - 1), $awm)),
       'Veraltet: AWM-Link aus dem Vorjahr wird erkannt');
    $p(!awm_renew_veraltet(str_replace('2026', (string) $j, $awm)),
       'Veraltet: AWM-Link des laufenden Jahres gilt als aktuell');
    $p(awm_renew_veraltet(str_replace('2026', (string) ($j - 1), $aio)),
       'Veraltet: abfall.io-Fenster aus dem Vorjahr wird erkannt');
    $p(awm_renew_veraltet('https://x.de/ical?jahr=' . ($j - 1)),
       'Veraltet: Jahresparameter aus dem Vorjahr wird erkannt');

    return $e;
}

/* ==================================================================
 * Selbstpruefung der Robustheit (ab 1.3.0)
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
    // Der Befund vom 20.08.2026: ein CHARSET an EINER Property darf nicht
    // die ganze Datei umrechnen.
    $gemischt = "BEGIN:VCALENDAR\r\nX-WR-CALNAME;CHARSET=ISO-8859-1:Abfuhr\r\n"
              . "SUMMARY:Restm\u{00fc}lltonne\r\nEND:VCALENDAR";
    $p(strpos(awm_utf8($gemischt), "Restm\u{00fc}lltonne") !== false,
       'Ein CHARSET an einer einzelnen Zeile rechnet NICHT die ganze Datei um');

    /* --- 2. Sprachausgabe: eigene Vorlage ohne IP --- */
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

    /* --- 5. Die Suchtexte treffen eindeutig (ab 1.4.0, REGELN_3 A11) --- */
    // Nicht "fehlt das Semikolon" wird geprueft, sondern die WIRKUNG: trifft
    // der Suchtext eines Feldes in der echten Antwortzeile zuerst ein
    // anderes Feld? Genau das ist am 20.08.2026 in drei Plugins passiert.
    $felder = awm_feldliste();
    $probe_werte = array();
    $i = 0;
    foreach ($felder as $name => $f) { $probe_werte[$name] = $i++; }
    $zeile = awm_zeile($probe_werte);
    $falsch = array();
    foreach (array_keys($felder) as $name) {
        // So sucht Loxone: der Text zwischen \i und \i, woertlich, erster Treffer.
        $such = ';' . $name . '=';
        $pos = strpos($zeile, $such);
        $soll = strpos($zeile, ';' . $name . '=' . $probe_werte[$name]);
        if ($pos === false || $pos !== $soll) { $falsch[] = $name; }
    }
    $p(!$falsch, 'Jeder Suchtext trifft sein eigenes Feld zuerst'
                 . ($falsch ? ' - falsch: ' . implode(', ', $falsch) : ''));
    /* Und die Gegenprobe - richtig gestellt.
     *
     * Der erste Anlauf pruefte, ob HEUTE ein Suchtext ohne Trennzeichen
     * danebentrifft. Er meldete null Faelle, und das stimmt auch: REST
     * steht vor HREST, TREST, DREST und SREST, also findet Loxone zuerst
     * das richtige Feld. Genau das beschreibt awm.php seit jeher - und
     * genau das ist die Zeitbombe, denn es haengt allein an der
     * Reihenfolge.
     *
     * Gemessen wird deshalb das RISIKO: wie viele Namenspaare gibt es, bei
     * denen der eine Name das Endstueck des anderen ist? Jedes davon
     * traefe falsch, sobald jemand die Reihenfolge aendert oder ein Feld
     * dazwischenschiebt. Mit dem Trennzeichen ist keines mehr mehrdeutig. */
    $namen = array_keys($felder);
    $paare = 0;
    foreach ($namen as $a) {
        foreach ($namen as $b) {
            if ($a !== $b && substr($b, -strlen($a)) === $a) { $paare++; }
        }
    }
    $p($paare > 0,
       'Gegenprobe: ' . $paare . ' Namenspaare, bei denen ein Feldname das Endstueck '
       . 'eines anderen ist - ohne Trennzeichen haengt die Zuordnung allein an der Reihenfolge');
    $mehrdeutig = 0;
    foreach ($namen as $a) {
        foreach ($namen as $b) {
            if ($a !== $b && strpos(';' . $b . '=', ';' . $a . '=') !== false) { $mehrdeutig++; }
        }
    }
    $p($mehrdeutig === 0,
       'Mit Trennzeichen ist keines dieser Paare mehr mehrdeutig');
    $p(strpos(awm_check('REST'), ';REST=') !== false,
       'awm_check() erzeugt den Suchtext mit Trennzeichen');

    /* --- 6. Loxone-Vorlage und Ausgabe beschreiben dasselbe (ab 1.4.0) --- */
    // Der schwerste Befund vom 20.08.2026: die Vorlage nannte REST analog
    // 0-365 "Tage" (es kommt 0/1) und TREST digital 0-1 (es kommt der
    // Tageszaehler). Hier wird gemessen, dass jeder Wert in seinen
    // deklarierten Bereich passt.
    $st_probe = array(
        'ok' => 1, 'warnung' => 0, 'hinweis' => '', 'letzter' => '20261231',
        'alter' => 5, 'abruf_ok' => 1, 'abruf_grund' => '',
        'heute' => awm_tonnen_leer(), 'morgen' => awm_tonnen_leer(),
        'tage' => array(), 'naechste' => array(),
        'next' => array('tage' => 3, 'datum' => '20260824', 'tonnen' => array('rest')),
        'termine' => array(), 'cal' => 1, 'datum' => date('Ymd'), 'ereignisse' => 3,
    );
    foreach (array_keys(awm_tonnenarten()) as $kk) {
        $st_probe['tage'][$kk] = -1;
        $st_probe['naechste'][$kk] = '';
    }
    $st_probe['morgen']['rest'] = 1;
    $st_probe['tage']['rest'] = 3;
    $st_probe['naechste']['rest'] = '20260824';
    $werte = awm_werte($st_probe, 1);
    $ausserhalb = array();
    foreach ($felder as $name => $f) {
        if (!isset($werte[$name])) { $ausserhalb[] = $name . ' (fehlt)'; continue; }
        $v = $werte[$name];
        if (!is_numeric($v)) { continue; }
        if ($v < $f[1] || $v > $f[2]) { $ausserhalb[] = $name . '=' . $v; }
    }
    $p(!$ausserhalb, 'Jeder Wert passt in den Bereich, den die Vorlage fuer ihn nennt'
                     . ($ausserhalb ? ' - ausserhalb: ' . implode(', ', $ausserhalb) : ''));
    $p($felder['REST'][0] === 0 && $felder['TREST'][0] === 1,
       'REST ist digital (morgen faellig), TREST analog (Tage) - bis 1.3.8 vertauscht');
    $p($felder['TREST'][1] === -1,
       'Der Tageszaehler darf -1 werden ("noch kein Termin bekannt")');
    $p(strpos($felder['WARN'][4], '30') !== false,
       'Der Kommentar zu WARN beschreibt die Jahreswechsel-Warnung, nicht die Abfuhr');

    /* --- 7. Beide Wege liefern dasselbe (ab 1.4.0) --- */
    $mqtt = array();
    foreach ($felder as $name => $f) { if ($f[6] !== '') { $mqtt[] = $name; } }
    $nurhttp = array();
    foreach (array_keys($felder) as $name) {
        if (!in_array($name, $mqtt, true)) { $nurhttp[] = $name; }
    }
    $p(!$nurhttp, 'Jeder Wert der Loxone-Zeile hat ein MQTT-Thema'
                  . ($nurhttp ? ' - ohne: ' . implode(', ', $nurhttp) : ''));
    $p(count($werte) === count($felder),
       'awm_werte() liefert genau die Felder aus awm_feldliste()');

    /* --- 8. Loxone-Zeit --- */
    $p(awm_loxzeit('20090101') === 0, 'Loxone-Zeit: der 01.01.2009 ist die Null');
    $p(awm_loxzeit('') === 0 && awm_loxzeit('nonsens') === 0,
       'Loxone-Zeit: ohne Datum kommt 0, nicht ein erfundener Wert');
    $p(awm_loxzeit('20260821') > awm_loxzeit('20260820'),
       'Loxone-Zeit: spaeter ist groesser');

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

/**
 * Welche Tonnen kommen im Kalender dieses Anwenders wirklich vor?
 *
 * Die Vorlage soll die Bausteine des ANWENDERS anlegen, nicht acht
 * Tonnenarten, von denen er drei hat (Hausregel: "Die Titel je erkanntem
 * Geraet ausgeben, nicht als Platzhalter"). Ohne Kalender bleibt es bei den
 * vier klassischen.
 */
function awm_aktive_tonnen($cal = 1)
{
    $serien = array_merge(awm_series($cal), awm_eigene_termine($cal));
    if (!$serien) {
        return array('rest', 'bio', 'papier', 'wert');
    }
    $regeln = awm_regeln($cal);
    $modus = awm_regeln_modus($cal);
    $gefunden = array();
    foreach ($serien as $s) {
        if (!empty($s['eigen'])) { $gefunden[$s['eigen']] = true; continue; }
        foreach (awm_bins_regeln($s['summary'], $regeln, $modus) as $k => $v) {
            if ($v) { $gefunden[$k] = true; }
        }
    }
    // Die vier klassischen bleiben IMMER dabei: an ihnen haengt die
    // 1:1-Kompatibilitaet zum alten muell.php, und wer sie in einer
    // bestehenden Anlage verdrahtet hat, soll sie beim Neuimport
    // wiederfinden. Die vier neuen kommen nur dazu, wenn sie im Kalender
    // wirklich vorkommen.
    foreach (array('rest', 'bio', 'papier', 'wert') as $k) { $gefunden[$k] = true; }
    $out = array();
    foreach (array_keys(awm_tonnenarten()) as $k) {
        if (isset($gefunden[$k])) { $out[] = $k; }
    }
    return $out;
}

/** name => array(analog, min, max, einheit, kommentar) - fuer die Vorlage. */
function awm_felder($cal = 1) {
    $aktiv = awm_aktive_tonnen($cal);
    $out = array();
    foreach (awm_feldliste() as $name => $f) {
        if (!$f[5]) { continue; }                      // nicht fuer Loxone
        if ($f[7] !== '' && !in_array($f[7], $aktiv, true)) { continue; }
        $out[$name] = array($f[0], $f[1], $f[2], $f[3], $f[4]);
    }
    return $out;
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

/** Virtueller Ausgang - fuer die drei Aufrufe, die etwas ausloesen. */
function awm_xml_virtual_out($kopf, $cmds) {
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut Title="' . awm_vx($kopf['title']) . '" ';
    $o .= 'Comment="' . awm_vx(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . awm_vx($kopf['address']) . '" ';
    $o .= 'CmdInit="" CloseAfterSend="true" CmdSeparator="">' . $crlf;
    $o .= "\t" . '<Info templateType="1" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . awm_vx($c['title']) . '" ';
        $o .= 'Comment="' . awm_vx($c['comment']) . '" ';
        $o .= 'CmdOnMethod="' . awm_vx(isset($c['method']) ? $c['method'] : 'GET') . '" ';
        $o .= 'CmdOn="' . awm_vx($c['on']) . '" ';
        $o .= 'CmdOnHTTP="" CmdOffMethod="GET" CmdOff="" CmdOffHTTP="" ';
        $o .= 'Analog="false" Repeat="0" RepeatRate="0"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
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

function awm_host() {
    return isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
}

/** Vorlage fuer den Import in Loxone Config. Rueckgabe: array(name, inhalt) */
function awm_vorlage($cal = 1) {
    $host = awm_host();
    $ordner = getenv('LBPPLUGINDIR') ?: 'awmabfuhr';
    $cal = max(1, (int) $cal);
    $cmds = array();
    foreach (awm_felder($cal) as $name => $f) {
        list($analog, $min, $max, $einheit, $text) = $f;
        $cmds[] = array(
            'title' => 'MUELL_' . $name . ($cal > 1 ? '_' . $cal : ''),
            'comment' => $text . ($einheit !== '' ? ' [' . $einheit . ']' : ''),
            // EINE Stelle fuer das Suchmuster - siehe awm_check().
            'check' => awm_check($name),
            'unit' => ($einheit !== '' ? '<v.1> ' . $einheit : '<v.1>'),
            'analog' => $analog, 'min' => $min, 'max' => $max,
        );
    }
    $c = awm_cal($cal);
    return array('VI_awm' . ($cal > 1 ? '_' . $cal : '') . '.xml', awm_xml_virtual_in_http(array(
        'title' => 'Abfuhrkalender AWM' . ($c ? ' ' . $c['name'] : ''),
        'address' => 'http://' . $host . '/plugins/' . $ordner . '/awm.php' . ($cal > 1 ? '?cal=' . $cal : ''),
        'polling' => '300',
        'comment' => 'Erzeugt vom LoxBerry-Plugin Abfuhrkalender AWM (' . date('d.m.Y') . '). '
                   . 'Loxone Config legt beim Import neu an und ueberschreibt nichts - '
                   . 'zweimal eingelesen ergibt doppelte Bausteine.',
    ), $cmds));
}

/**
 * Vorlage fuer den virtuellen AUSGANG.
 *
 * Bis 1.3.8 gab es nur die Eingangs-Vorlage. Die drei ausloesenden Adressen
 * - Ansage, Test-Push, Jahres-Erneuerung - musste man samt Token von Hand
 * abtippen, und nach jedem neuen Token noch einmal.
 */
function awm_vorlage_vo($cal = 1) {
    $host = awm_host();
    $ordner = getenv('LBPPLUGINDIR') ?: 'awmabfuhr';
    $cfg = awm_config();
    $tok = (string) $cfg['aktionstoken'];
    $basis = '/plugins/' . $ordner . '/awm.php';
    $cal = max(1, (int) $cal);
    $suffix = '&token=' . $tok . ($cal > 1 ? '&cal=' . $cal : '');
    $cmds = array(
        array('title' => 'Ansage jetzt', 'comment' => 'Spricht sofort in den konfigurierten Zonen',
              'on' => $basis . '?say=1' . $suffix),
        array('title' => 'Test-Pushnachricht', 'comment' => 'Setzt PTEST fuer fuenf Minuten',
              'on' => $basis . '?ptest=1' . $suffix),
        array('title' => 'Quittierung Tonne draussen', 'comment' => 'Setzt ACK bis Mitternacht, die Morgen-Ansage entfaellt',
              'on' => $basis . '?ack=1' . $suffix),
        array('title' => 'Jahres-Erneuerung', 'comment' => 'Versucht sofort einen frischen Kalender-Link',
              'on' => $basis . '?renew=1' . $suffix),
    );
    return array('VO_awm' . ($cal > 1 ? '_' . $cal : '') . '.xml', awm_xml_virtual_out(array(
        'title' => 'Abfuhrkalender AWM steuern',
        'address' => 'http://' . $host,
        'comment' => 'Erzeugt vom LoxBerry-Plugin Abfuhrkalender AWM (' . date('d.m.Y') . '). '
                   . 'ACHTUNG: die Adressen enthalten das Aktionstoken. Wird in der '
                   . 'Plugin-Oberflaeche ein neues Token erzeugt, muss diese Vorlage neu '
                   . 'eingelesen oder das Token in den Befehlen ausgetauscht werden.',
    ), $cmds));
}


/**
 * Die Fassung des LoxBerry-MQTT-Gateways - 0 heisst "nicht feststellbar".
 *
 * Sie steht als Mqtt.Gatewayversion in config/system/general.json (ab Werk
 * 1) und entscheidet, was der Anwender eintragen muss: unter V1 jedes Thema
 * von Hand auf der Abo-Seite, ab V2 erscheint die Themengruppe von selbst in
 * den Subscriptions.
 *
 * Die Datei wird hier eigens gelesen, obwohl andere Stellen sie auch lesen.
 * Das ist Absicht: dieser Baustein passt damit in jedes Plugin, unabhaengig
 * davon, wie es seinen MQTT-Zustand ermittelt - und er geht nicht kaputt,
 * wenn jemand jene Funktion umbaut.
 */
function awm_gateway_fassung()
{
    $home = getenv('LBHOMEDIR');
    if (!$home && defined('LBHOMEDIR')) {
        $home = LBHOMEDIR;
    }
    if (!$home || !is_dir($home)) {
        return 0;
    }
    $d = @json_decode((string) @file_get_contents(
        $home . '/config/system/general.json'), true);
    if (!is_array($d)) {
        return 0;
    }
    foreach (array('Mqtt', 'mqtt') as $ab) {
        if (!isset($d[$ab]) || !is_array($d[$ab])) {
            continue;
        }
        foreach (array('Gatewayversion', 'gatewayversion') as $sl) {
            if (isset($d[$ab][$sl]) && (string) $d[$ab][$sl] !== '') {
                return (int) $d[$ab][$sl];
            }
        }
    }
    return 0;
}

/**
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Bis hierher stand an der Ausgabestelle unbedingt "Ohne diesen Eintrag
 * kommt am Miniserver nichts an". Das gilt fuer Gateway V1; ab V2 schickte
 * der Satz jeden Anwender zu einem Eingabeplatz, den es nicht mehr gibt.
 *
 * Drei Ausgaenge: ist die Fassung nicht feststellbar, werden BEIDE Faelle
 * genannt statt einer behauptet.
 */
function awm_abo_text()
{
    $f = awm_gateway_fassung();
    if ($f <= 0) {
        return awm_t('MQTT.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(awm_t('MQTT.ABO_GEMESSEN'), $f) . '</span>';
    return awm_t($f >= 2 ? 'MQTT.ABO_V2' : 'MQTT.T_ABO_WARN') . $gemessen;
}


/**
 * Den ganzen Konfigurationsstand ablegen - und sagen, ob es geklappt hat.
 *
 * Bisher schrieb diese Linie mitten in index.php. Das Zurueckspielen einer
 * Sicherung braucht aber EINE Stelle, sonst steht die Pruefung "hat es
 * geklappt?" an vier Orten verschieden da.
 *
 * Der Schreibweg ist der, den die Linie ohnehin benutzt - hier wird kein
 * Verhalten geaendert, nur ein vorhandenes zusammengefasst.
 */
function awm_config_speichern($cfg)
{
    $p = awm_paths();
    $js = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        return false;   /* ungueltiges UTF-8 - lieber gar nicht schreiben
                           als eine halbe Datei hinterlassen */
    }
    @mkdir(dirname($p['config']), 0775, true);
    return (bool) (@file_put_contents($p['config'], $js) !== false);
}


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Der wichtigste Punkt: eine halb gueltige Datei ueberschreibt GAR NICHTS.
 * Wer eine Sicherung zurueckspielt, will entweder den ganzen Stand oder
 * gar keinen - eine zur Haelfte uebernommene Konfiguration ist schlimmer
 * als die alte, und man sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function awm_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(awm_t('EINST.SICH_KEIN_JSON')), 0);
    }
    $neu = awm_config_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(awm_t('EINST.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = awm_t('EINST.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}
