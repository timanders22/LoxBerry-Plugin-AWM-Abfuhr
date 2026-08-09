<?php
/**
 * Abfuhrkalender AWM Muenchen - Admin-Oberflaeche (v1.0.1)
 * Reiter: Einstellungen | Einbindung in Loxone | Test | Protokoll
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-GLOBALS (u.a. $cfg aus general.json als
 * stdClass) und wuerde gleichnamige Plugin-Variablen ueberschreiben - daher
 * tragen hier ALLE Variablen ein aw_-Praefix.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

$aw_lbhome = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
$aw_plugin = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
if ($aw_lbhome && is_dir($aw_lbhome . '/config/plugins/' . $aw_plugin) === false) {
    $aw_plugin = basename(dirname(__DIR__));
    if (is_dir($aw_lbhome . '/config/plugins/' . $aw_plugin) === false) {
        $aw_plugin = 'awmabfuhr';
    }
}
if ($aw_lbhome) {
    $aw_sdk = $aw_lbhome . '/libs/phplib/loxberry_system.php';
    if (file_exists($aw_sdk)) {
        require_once $aw_sdk;
        require_once $aw_lbhome . '/libs/phplib/loxberry_web.php';
    }
    $aw_cfgdir = $aw_lbhome . '/config/plugins/' . $aw_plugin;
    $aw_bkfile = $aw_lbhome . '/config/plugins/' . $aw_plugin . '.backup.json';
    $aw_logfile = $aw_lbhome . '/log/plugins/' . $aw_plugin . '/awm.log';
} else {
    $aw_cfgdir = dirname(dirname(__DIR__)) . '/config';
    $aw_bkfile = $aw_cfgdir . '/awm.backup.json';
    $aw_logfile = sys_get_temp_dir() . '/awmabfuhr/awm.log';
}
$aw_cfgfile = $aw_cfgdir . '/awm.json';

// Bibliothek einbinden (installiert unter .../html/plugins/<plugin>/, im Archiv unter ../html/)
foreach (array(
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . $aw_plugin . '/awm_lib.php',
    dirname(__DIR__) . '/html/awm_lib.php',
) as $aw_libcand) {
    if (is_file($aw_libcand)) {
        require_once $aw_libcand;
        break;
    }
}

// Selbstheilung: fehlende/leere Konfiguration aus Sicherung wiederherstellen
if ((!is_file($aw_cfgfile) || trim((string) @file_get_contents($aw_cfgfile)) === '' || trim((string) @file_get_contents($aw_cfgfile)) === '{}') && is_file($aw_bkfile)) {
    @mkdir($aw_cfgdir, 0775, true);
    @copy($aw_bkfile, $aw_cfgfile);
}

$aw_saved = false;
$aw_err = '';
$aw_note = '';
// Der aktive Reiter kommt aus dem abgeschickten Formular ODER aus der
// Adresse (die Reiter sind echte Verweise). Beides wird gegen dieselbe
// Positivliste geprueft - sie muss Zeichen fuer Zeichen zu den fuenf
// id-Werten der Bereiche weiter unten passen.
$aw_tabliste = array('tab-settings', 'tab-bins', 'tab-loxone', 'tab-test', 'tab-log');
$aw_tab = 'tab-settings';
if (in_array((string) (isset($_GET['tab']) ? $_GET['tab'] : ''), $aw_tabliste, true)) {
    $aw_tab = $_GET['tab'];
}
if (in_array((string) (isset($_POST['activetab']) ? $_POST['activetab'] : ''), $aw_tabliste, true)) {
    $aw_tab = $_POST['activetab'];
}

// ---------- Protokoll leeren ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    @mkdir(dirname($aw_logfile), 0775, true);
    @file_put_contents($aw_logfile, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Admin-Oberflaeche)\n");
    $aw_tab = 'tab-log';
}

// ---------- Tonnenzuordnung speichern (ab 1.1.0) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_bins'])) {
    $aw_cfg2 = awm_config();
    $aw_cl = isset($aw_cfg2['cals']) && is_array($aw_cfg2['cals']) ? array_values($aw_cfg2['cals']) : array();
    $aw_cn = max(1, min(count($aw_cl), (int) (isset($_POST['bins_cal']) ? $_POST['bins_cal'] : 1)));
    $aw_arten = awm_tonnenarten();
    $aw_titel = isset($_POST['bin_titel']) ? (array) $_POST['bin_titel'] : array();
    $aw_tonne = isset($_POST['bin_tonne']) ? (array) $_POST['bin_tonne'] : array();
    $aw_art = isset($_POST['bin_art']) ? (array) $_POST['bin_art'] : array();
    $aw_regeln_neu = array();
    foreach ($aw_titel as $aw_k => $aw_tt) {
        $aw_tt = trim(preg_replace('/[\x00-\x1F\x7F"]/', '', (string) $aw_tt));
        $aw_zz = (string) (isset($aw_tonne[$aw_k]) ? $aw_tonne[$aw_k] : '');
        if ($aw_tt === '' || $aw_zz === '') {
            continue;                    // leere Zeile = keine Zuordnung
        }
        if (!isset($aw_arten[$aw_zz])) {
            $aw_err = 'Unbekannte Tonnenart: ' . htmlspecialchars($aw_zz, ENT_QUOTES, 'UTF-8');
            continue;
        }
        $aw_aa = (string) (isset($aw_art[$aw_k]) ? $aw_art[$aw_k] : 'enthaelt');
        if (!in_array($aw_aa, array('enthaelt', 'beginnt', 'genau'), true)) {
            $aw_err = 'Unbekannte Vergleichsart bei "' . htmlspecialchars($aw_tt, ENT_QUOTES, 'UTF-8') . '".';
            continue;
        }
        $aw_regeln_neu[] = array('muster' => $aw_tt, 'tonne' => $aw_zz, 'art' => $aw_aa);
    }
    if ($aw_err === '') {
        if (!isset($aw_cl[$aw_cn - 1])) {
            $aw_err = 'Diesen Kalender gibt es nicht.';
        } else {
            $aw_cl[$aw_cn - 1]['regeln'] = $aw_regeln_neu;
            $aw_cfg2['cals'] = $aw_cl;
            if (!is_dir($aw_cfgdir)) { @mkdir($aw_cfgdir, 0775, true); }
            // Nebendatei + rename: haelt sowohl ungueltiges UTF-8 als auch
            // einen Stromausfall mitten im Schreiben von der Konfiguration fern.
            if (function_exists('awm_json_schreiben')
                ? awm_json_schreiben($aw_cfgfile, $aw_cfg2, 0664, true)
                : false) {
                @copy($aw_cfgfile, $aw_bkfile);
                // Der Zwischenspeicher haelt 10 Minuten - ohne Auffrischen
                // sieht man die neue Zuordnung erst danach.
                if (function_exists('awm_state')) { awm_state(true, $aw_cn); }
                $aw_note = 'Die Tonnenzuordnung wurde gespeichert (' . count($aw_regeln_neu) . ' Regeln).';
            } else {
                $aw_err = 'Konfiguration konnte nicht gespeichert werden: ' . $aw_cfgfile;
            }
        }
    }
    $aw_tab = 'tab-bins';
}

// ---------- Jetzt abrufen ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetchnow']) && function_exists('awm_fetch')) {
    $aw_msgs = array();
    foreach (awm_cals() as $aw_n => $aw_c) {
        list($aw_fok, $aw_fq) = awm_fetch(true, $aw_n);
        awm_state(true, $aw_n);
        $aw_msgs[] = $aw_c['name'] . ': ' . ($aw_fok ? $aw_fq : 'FEHLGESCHLAGEN');
    }
    $aw_note = $aw_msgs ? ('Abruf: ' . implode(' / ', $aw_msgs)) : 'Bitte zuerst eine iCal-URL speichern.';
}

// ---------- Speichern ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $aw_new = array();
    $aw_new['cals'] = array();
    $aw_names = isset($_POST['cal_name']) ? (array) $_POST['cal_name'] : array();
    $aw_urls = isset($_POST['cal_url']) ? (array) $_POST['cal_url'] : array();
    for ($aw_i = 0; $aw_i < 2; $aw_i++) {
        $aw_u = trim((string) (isset($aw_urls[$aw_i]) ? $aw_urls[$aw_i] : ''));
        if ($aw_u === '') {
            continue;
        }
        if (!preg_match('#^https?://#i', $aw_u)) {
            $aw_err = 'Kalender ' . ($aw_i + 1) . ': Die iCal-URL muss mit http:// oder https:// beginnen.';
            continue;
        }
        // Die Zuordnungstabelle haengt am selben Eintrag und wird hier NICHT
        // mitgeschickt - sie muss deshalb aus der bestehenden Konfiguration
        // uebernommen werden. Ohne das loescht ein Klick auf 'Speichern' in
        // den Einstellungen die gesamte Tonnenzuordnung.
        $aw_alt = awm_config();
        $aw_alte_cals = isset($aw_alt['cals']) && is_array($aw_alt['cals'])
            ? array_values($aw_alt['cals']) : array();
        $aw_regeln_alt = isset($aw_alte_cals[$aw_i]['regeln']) && is_array($aw_alte_cals[$aw_i]['regeln'])
            ? $aw_alte_cals[$aw_i]['regeln'] : array();
        $aw_new['cals'][] = array(
            'name' => trim((string) (isset($aw_names[$aw_i]) ? $aw_names[$aw_i] : '')),
            'url' => $aw_u,
            'regeln' => $aw_regeln_alt,
        );
    }
    $aw_new['fetch_days'] = max(1, min(60, (int) (isset($_POST['fetch_days']) ? $_POST['fetch_days'] : 14)));
    $aw_new['lookahead'] = max(7, min(90, (int) (isset($_POST['lookahead']) ? $_POST['lookahead'] : 35)));
    $aw_new['autorenew'] = isset($_POST['autorenew']) ? 1 : 0;
    // Stichwoerter fuer Feiertagsverschiebungen. Leer = Standardliste,
    // die "achtung" enthaelt - Muenchen bleibt damit unveraendert.
    $aw_hw = trim((string) (isset($_POST['hinweis_woerter']) ? $_POST['hinweis_woerter'] : ''));
    $aw_hw = trim(preg_replace('/[\x00-\x1F\x7F"]/', '', $aw_hw));
    $aw_new['hinweis_woerter'] = $aw_hw !== '' ? $aw_hw : AWM_HINWEIS_STANDARD;
    $aw_new['mqtt_enabled'] = isset($_POST['mqtt_enabled']) ? 1 : 0;
    $aw_new['mqtt_topic'] = preg_replace('#[^\w/\-]#', '', (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : 'awm')) ?: 'awm';
    $aw_new['notify'] = array(
        'audio' => isset($_POST['notify_audio']) ? 1 : 0,
        'push' => isset($_POST['notify_push']) ? 1 : 0,
        'time' => preg_match('/^\d{1,2}:\d{2}$/', (string) (isset($_POST['notify_time']) ? $_POST['notify_time'] : '')) ? $_POST['notify_time'] : '18:00',
    );
    $aw_mode = (string) (isset($_POST['tts_mode']) ? $_POST['tts_mode'] : 'musicserver');
    $aw_new['tts'] = array(
        'mode' => in_array($aw_mode, array('musicserver', 'ms4h', 'audioserver', 'custom'), true) ? $aw_mode : 'musicserver',
        'ip' => trim((string) (isset($_POST['tts_ip']) ? $_POST['tts_ip'] : '')),
        'port' => max(1, min(65535, (int) (isset($_POST['tts_port']) ? $_POST['tts_port'] : 7091))),
        'zones' => trim((string) (isset($_POST['tts_zones']) ? $_POST['tts_zones'] : '1')),
        'volume' => max(1, min(100, (int) (isset($_POST['tts_volume']) ? $_POST['tts_volume'] : 8))),
        'lang' => preg_replace('/[^a-z]/', '', strtolower((string) (isset($_POST['tts_lang']) ? $_POST['tts_lang'] : 'de'))) ?: 'de',
        'template' => trim((string) (isset($_POST['tts_template']) ? $_POST['tts_template'] : '')),
    );
    if ($aw_err === '') {
        if (!is_dir($aw_cfgdir)) {
            @mkdir($aw_cfgdir, 0775, true);
        }
        // Siehe oben: unteilbar schreiben, sonst kann ein Abbruch die
        // Konfiguration halb hinterlassen.
        if (function_exists('awm_json_schreiben')
            ? awm_json_schreiben($aw_cfgfile, $aw_new, 0664, true)
            : false) {
            $aw_saved = true;
            @copy($aw_cfgfile, $aw_bkfile); // Sicherung ausserhalb des Plugin-Ordners
        } else {
            $aw_err = 'Konfiguration konnte nicht gespeichert werden: ' . $aw_cfgfile;
        }
    }
}

// ---------- Laden ----------
$aw_cfg = function_exists('awm_config') ? awm_config() : array();
if (!is_array($aw_cfg)) { $aw_cfg = array(); }
$aw_cfg += array('cals' => array(), 'fetch_days' => 14, 'lookahead' => 35, 'autorenew' => 1,
    'mqtt_enabled' => 0, 'mqtt_topic' => 'awm', 'notify' => array(), 'tts' => array());
$aw_notify = is_array($aw_cfg['notify']) ? $aw_cfg['notify'] : array();
$aw_notify += array('audio' => 1, 'push' => 1, 'time' => '18:00');
$aw_tts = is_array($aw_cfg['tts']) ? $aw_cfg['tts'] : array();
$aw_tts += array('mode' => 'musicserver', 'ip' => '', 'port' => 7091, 'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '');
$aw_cals = function_exists('awm_cals') ? awm_cals() : array();

$aw_states = array();
foreach ($aw_cals as $aw_n => $aw_c) {
    $aw_states[$aw_n] = awm_state(false, $aw_n);
}
$aw_loglines = array();
if (is_file($aw_logfile)) {
    $aw_loglines = array_slice(array_reverse(file($aw_logfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()), 0, 300);
}

function aw_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function aw_d($ymd) { return strlen((string) $ymd) === 8 ? substr($ymd, 6, 2) . '.' . substr($ymd, 4, 2) . '.' . substr($ymd, 0, 4) : '-'; }

/**
 * Farblegende der Knoepfe.
 *
 * Hausregel: je Reiter EINE Legende, und zwar ueber den Knopfreihen. Bis
 * 1.2.0 stand sie nur im Reiter Test - in den uebrigen drei Reitern gab es
 * Knoepfe in denselben Farben, aber nichts, was sie erklaerte.
 */
function aw_legende()
{
    echo '<div class="sm-legende">'
       . '<span><i class="sm-punkt sm-b-lesen"></i> ' . awm_t('LEGENDE.LESEN') . '</span>'
       . '<span><i class="sm-punkt sm-b-technik"></i> ' . awm_t('LEGENDE.TECHNIK') . '</span>'
       . '<span><i class="sm-punkt sm-b-aktion"></i> ' . awm_t('LEGENDE.AKTION') . '</span>'
       . '</div>';
}

$aw_frame = class_exists('LBWeb', false);
if ($aw_frame) {
    LBWeb::lbheader('Abfuhrkalender AWM M&uuml;nchen', 'https://wiki.loxberry.de/', '');
}
$aw_host = aw_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '<loxberry-ip>');
?>
<style>
.sm-wrap { max-width: 940px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap select, .sm-wrap textarea {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.sm-row { display: flex; gap: 12px; }
.sm-row > div { flex: 1; }
.sm-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-warn { background: #fff8e1; border: 1px solid #ffe082; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; text-shadow: none !important; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-log { text-shadow: none !important; background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; }
.sm-tbl th { background: #f0f0f0; }
.sm-wrap .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button { text-shadow: none !important; box-shadow: none !important; }
.sm-wrap a.sm-btn, .sm-wrap a.sm-btn:visited, .sm-wrap a.sm-btn:hover { color: #fff !important; text-decoration: none; }
/* Zeigefinger drauf: nur abdunkeln, Beschriftung bleibt weiss.
   Ohne diese Regel faerbt das jQuery-Mobile-Thema von LoxBerry den
   Hintergrund beim Ueberfahren weiss - zusammen mit color:#fff war die
   Beschriftung dann unlesbar. Getroffen hatte es "Zuordnung speichern"
   im Reiter Tonnen, den einzigen Knopf ohne data-role='none'.
   brightness() statt fester Farben, damit jede Knopfvariante ihre
   eigene behaelt. */
.sm-wrap .sm-btn:hover, .sm-wrap .sm-btn:focus, .sm-wrap .sm-btn:active,
.sm-wrap button.sm-btn:hover, .sm-wrap button.sm-btn:focus, .sm-wrap button.sm-btn:active {
    color: #fff !important; filter: brightness(0.92); background-image: none !important; }

/* --- Einheitliches Kachel-Raster im Reiter <?php echo awm_t('TEXT.TEST'); ?> (Standard aller Plugins) --- */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; text-shadow: none !important; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-btn.sm-b-lesen   { background: #6dac20; }
.sm-btn.sm-b-technik { background: #546e7a; }
.sm-btn.sm-b-aktion  { background: #e0620d; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }

/* Die Zuordnungstabelle im Reiter Tonnen. Sie trug bis 1.2.0 die Klasse
   sm-tab2, zu der es nie eine Regel gab - die Tabelle stand also ohne
   Rahmen und ohne Abstaende da, mitten zwischen gestalteten Elementen.
   Dasselbe galt fuer sm-hint und sm-aus. */
.sm-tbl2 { border-collapse: collapse; margin: 8px 0; width: 100%; }
.sm-tbl2 th, .sm-tbl2 td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; vertical-align: middle; }
.sm-tbl2 th { background: #f0f0f0; }
.sm-tbl2 code { background: #f5f5f5; padding: 2px 6px; border-radius: 4px; font-family: ui-monospace, monospace; }
.sm-tbl2 select { min-width: 150px; }
.sm-hint { font-size: 0.82em; color: #666; }
.sm-aus { color: #999; font-style: italic; }

/* Reiter sind echte Verweise - sie muessen aussehen wie zuvor die
   Bereiche, und der Browser darf sie nicht unterstreichen. */
.sm-wrap a.sm-tab, .sm-wrap a.sm-tab:visited { text-decoration: none; display: inline-block; }
.sm-wrap a.sm-tab.sm-active, .sm-wrap a.sm-tab.sm-active:visited { color: #fff !important; }
</style>
<div class="sm-wrap">

<?php if ($aw_saved) { ?><div class="sm-alert sm-ok"><b><?php echo awm_t('TEXT.KONFIGURATION_GESPEICHERT'); ?></b> <?php echo awm_t('TEXT.INKL_SICHERUNGSKOPIE_FR_UPDATES'); ?></div><?php } ?>
<?php if ($aw_note !== '') { ?><div class="sm-alert sm-ok"><?= aw_e($aw_note) ?></div><?php } ?>
<?php if ($aw_err !== '') { ?><div class="sm-alert sm-err"><b><?php echo awm_t('TEXT.FEHLER'); ?></b> <?= aw_e($aw_err) ?></div><?php } ?>

<?php if (!$aw_cals) { ?>
<div class="sm-alert sm-info"><b><?php echo awm_t('TEXT.NOCH_KEIN_KALENDER_EINGERICHTET'); ?></b> <?php echo awm_t('TEXT.BITTE_UNTEN_DIE_ICAL_URL_EINTRAGEN'); ?></div>
<?php } ?>
<?php foreach ($aw_states as $aw_n => $aw_st) { ?>
<div class="sm-alert sm-info"><b><?= aw_e($aw_cals[$aw_n]['name']) ?></b>:
<?php if ($aw_st['ok']) { ?>
<?php
// Die Tonnennamen und die Tagesangabe standen bis 1.2.0 fest auf Deutsch
// in der Vorlage - in der englischen Oberflaeche stand mitten im Satz
// "Restmuell" und "in 3 T.". Beides kommt jetzt aus den Sprachdateien.
$aw_faellig = array();
if ($aw_st['morgen']['rest'])   { $aw_faellig[] = '<b>' . awm_t('TEXT.RESTMLL') . '</b>'; }
if ($aw_st['morgen']['bio'])    { $aw_faellig[] = '<b>' . awm_t('TEXT.BIO') . '</b>'; }
if ($aw_st['morgen']['papier']) { $aw_faellig[] = '<b>' . awm_t('TEXT.PAPIER') . '</b>'; }
if ($aw_st['morgen']['wert'])   { $aw_faellig[] = '<b>' . awm_t('TEXT.WERTSTOFF') . '</b>'; }
$aw_tagetext = function ($n) {
    return $n >= 0 ? sprintf(awm_t('TEXT.IN_X_TAGEN'), (int) $n) : '-';
};
?>
<?= (int) $aw_st['ereignisse'] ?> <?php echo awm_t('TEXT.SERIEN_TERMINE_MORGEN'); ?> <?= $aw_faellig ? implode(' ', $aw_faellig) : awm_t('TEXT.KEINE_ABHOLUNG') ?> <?php echo awm_t('TEXT.NCHSTE_REST'); ?> <?= $aw_tagetext($aw_st['tage']['rest']) ?>,
<?php echo awm_t('TEXT.BIO'); ?> <?= $aw_tagetext($aw_st['tage']['bio']) ?>,
<?php echo awm_t('TEXT.PAPIER'); ?> <?= $aw_tagetext($aw_st['tage']['papier']) ?>
<?php if (!empty($aw_st['termine'])) { ?>
<table class="sm-tbl"><tr><th><?php echo awm_t('TEXT.DATUM'); ?></th><th><?php echo awm_t('TEXT.ABHOLUNG'); ?></th></tr>
<?php foreach (array_slice($aw_st['termine'], 0, 6) as $aw_t) { ?>
<tr><td><?= aw_e(aw_d($aw_t[0])) ?></td><td><?= aw_e($aw_t[1]) ?></td></tr>
<?php } ?></table>
<?php } ?>
<?php } else { ?>
<b><?php echo awm_t('TEXT.NOCH_KEINE_TERMINE_GELADEN'); ?></b> <?php echo awm_t('TEXT.BITTE_JETZT_ABRUFEN_KLICKEN_REITER'); ?>
<?php } ?>
<?php if (!empty($aw_st['hinweis'])) { ?><br><b><?php echo awm_t('TEXT.HINWEIS_VOM_ENTSORGER'); ?></b> <?= aw_e($aw_st['hinweis']) ?><?php } ?>
<?php if (!empty($aw_st['warnung'])) { ?><br><b style="color:#c62828;"><?php echo awm_t('TEXT.ACHTUNG'); ?></b> <?php echo awm_t('TEXT.KALENDER_ENDET_IN_30_TAGEN_JAHRESW'); ?><?php } ?>
</div>
<?php } ?>

<?php
// Reiter sind ECHTE Verweise, nicht nur anklickbare Bereiche. Ohne
// JavaScript - oder wenn ein Skript weiter oben abbricht - blieb bis
// 1.2.0 jeder Reiter ausser dem ersten unerreichbar, und die Seite sah
// aus, als fehle der halbe Inhalt. Mit href funktioniert sie auch dann,
// nur eben mit einem Seitenaufbau je Klick. Das Skript unten faengt den
// Klick ab, solange es laeuft.
$aw_reiter = array(
    'tab-settings' => awm_t('REITER.EINSTELLUNGEN'),
    'tab-bins'     => awm_t('REITER.TONNEN'),
    'tab-loxone'   => awm_t('REITER.LOXONE'),
    'tab-test'     => awm_t('REITER.TEST'),
    'tab-log'      => awm_t('REITER.LOG'),
);
?>
<div class="sm-tabs">
<?php foreach ($aw_reiter as $aw_id => $aw_titel) { ?>
    <a class="sm-tab<?= $aw_id === $aw_tab ? ' sm-active' : '' ?>" data-pane="<?= $aw_id ?>"
       href="index.php?tab=<?= $aw_id ?><?= $aw_id === 'tab-bins' ? '&amp;bcal=' . (int) (isset($_GET['bcal']) ? $_GET['bcal'] : 1) : '' ?>"><?= $aw_titel ?></a>
<?php } ?>
</div>

<!-- ================= Reiter: <?php echo awm_t('TEXT.EINSTELLUNG'); ?>en ================= -->
<div class="sm-pane<?= $aw_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo awm_t('TEXT.DATENQUELLEN_BIS_ZU_2_KALENDER'); ?></h2>
<table class="sm-tbl" style="width:100%;">
<tr><th style="width:36px;">Nr.</th><th style="width:180px;"><?php echo awm_t('TEXT.NAME_FREI'); ?></th><th><?php echo awm_t('TEXT.ICAL_KALENDER_URL'); ?></th></tr>
<?php for ($aw_i = 0; $aw_i < 2; $aw_i++) {
    $aw_c = isset($aw_cfg['cals'][$aw_i]) ? (array) $aw_cfg['cals'][$aw_i] : array();
    $aw_c += array('name' => '', 'url' => ''); ?>
<tr>
<td><?= $aw_i + 1 ?></td>
<td><input data-role="none" type="text" name="cal_name[]" value="<?= aw_e($aw_c['name']) ?>" placeholder="<?= $aw_i === 0 ? 'z. B. Zuhause' : 'z. B. Ferienhaus (leer = ungenutzt)' ?>"></td>
<td><input data-role="none" type="text" name="cal_url[]" value="<?= aw_e($aw_c['url']) ?>" placeholder="https://www.awm-muenchen.de/...&amp;section=ics&amp;..."></td>
</tr>
<?php } ?>
</table>
<div class="sm-small"><?php echo awm_t('TEXT.KALENDER_1_IST_DER_STANDARD_ALLE_L'); ?> <span class="sm-mono"><?php echo awm_t('TEXT.CAL'); ?></span><?php echo awm_t('TEXT.KALENDER_2_WIRD_MIT'); ?> <span class="sm-mono"><?php echo awm_t('TEXT.CAL_2'); ?></span> <?php echo awm_t('TEXT.ABGEFRAGT_MQTT'); ?> <span class="sm-mono"><?= aw_e($aw_cfg['mqtt_topic']) ?>/2/...</span><?php echo awm_t('TEXT.ANSAGE_PUSH_FENSTER_BEZIEHEN_SICH_'); ?></div>

<div class="sm-step"><b><?php echo awm_t('TEXT.ABFUHRKALENDER_AWM_MNCHEN_EINRICHT'); ?></b><br>
1. <a href="https://www.awm-muenchen.de/abfall-entsorgen/muelltonnen/abfuhrkalender" target="_blank"><?php echo awm_t('TEXT.AWM_ABFUHRKALENDER_FFNEN'); ?></a><?php echo awm_t('TEXT.DORT_STRAE_UND_HAUSNUMMER_EINGEBEN'); ?><br>
<?php echo awm_t('TEXT.2_AUF_DER_ERGEBNISSEITE_DEN_ICAL_E'); ?> <b><?php echo awm_t('TEXT.RECHTEN_MAUSTASTE'); ?></b> <?php echo awm_t('TEXT.ANKLICKEN_UND_LINK_ADRESSE_KOPIERE'); ?><br>
<?php echo awm_t('TEXT.3_DEN_KOPIERTEN_LINK_OBEN_IN_DAS_F'); ?><br>
<?php echo awm_t('TEXT.4_DANACH_EINMAL_JETZT_ABRUFEN_KLIC'); ?><br>
<span class="sm-small"><b><?php echo awm_t('TEXT.HINWEIS'); ?></b> <?php echo awm_t('TEXT.DER_AWM_LINK_ENTHLT_DAS_KALENDERJA'); ?>
<span class="sm-mono"><?php echo awm_t('TEXT.WARN_1'); ?></span> <?php echo awm_t('TEXT.IN_LOXONE_UND_PER_MQTT_DANN_EINFAC'); ?></span>
</div>

<div class="sm-step"><b><?php echo awm_t('QUELLEN.H_WEITERE'); ?></b><br>
<?php echo awm_t('QUELLEN.EINLEITUNG'); ?>
<?php
// Kurzanleitungen je Kalendersystem. Eine neue Quelle kommt an ZWEI Stellen
// dazu: hier und in beiden Sprachdateien ([QUELLEN]) - und falls der Link
// ein Jahr traegt, zusaetzlich in awm_renew_strategien() in awm_lib.php.
//
// BEWUSST OHNE VERWEIS auf die Anbieterseiten (mymuell.de, abfallplus.de,
// abfallkalender.info): das sind Verkaufsseiten fuer Kommunen, dort laesst
// sich keine Adresse eingeben. Einstieg ist immer der eigene Entsorger.
// Die Namen enthalten HTML-Entitaeten und werden deshalb nicht escaped.
$aw_quellen = array(
    array('name' => 'Abfallplus / abfall.io', 'key' => 'QUELLEN.ABFALLIO'),
    array('name' => 'Jumomind / MyM&uuml;ll',  'key' => 'QUELLEN.JUMOMIND'),
    array('name' => 'ATURIS AbfallKalenderSystem', 'key' => 'QUELLEN.ATURIS'),
);
foreach ($aw_quellen as $aw_q) { ?>
<div class="sm-small" style="margin-top:8px;">
<b><?= $aw_q['name'] ?></b><br>
<?php echo awm_t($aw_q['key']); ?>
</div>
<?php } ?>
<div class="sm-small" style="margin-top:8px;"><?php echo awm_t('QUELLEN.SONST'); ?></div>
<div class="sm-small" style="margin-top:8px;"><b><?php echo awm_t('TEXT.HINWEIS'); ?></b> <?php echo awm_t('QUELLEN.HINWEIS_JAHR'); ?></div>
</div>

<div class="sm-row">
    <div>
        <label><?php echo awm_t('TEXT.ABRUF_INTERVALL_TAGE'); ?></label>
        <input data-role="none" type="number" name="fetch_days" value="<?= (int) $aw_cfg['fetch_days'] ?>" min="1" max="60">
        <div class="sm-small"><?php echo awm_t('TEXT.ABFUHRKALENDER_NDERN_SICH_SELTEN_S'); ?> <b><?php echo awm_t('TEXT.14_TAGE'); ?></b> <?php echo awm_t('TEXT.REICHT_VLLIG_UND_SCHONT_DEN_ENTSOR'); ?></div>
    </div>
    <div>
        <label><?php echo awm_t('TEXT.VORSCHAU_FENSTER_TAGE'); ?></label>
        <input data-role="none" type="number" name="lookahead" value="<?= (int) $aw_cfg['lookahead'] ?>" min="7" max="90">
        <div class="sm-small"><?php echo awm_t('TEXT.FR_TERMINLISTE_TAGE_ZHLER_UND_DEBU'); ?></div>
    </div>
    <div>
        <label style="margin-top:10px;">&nbsp;</label>
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
            <input data-role="none" type="checkbox" name="autorenew" <?= !empty($aw_cfg['autorenew']) ? 'checked' : '' ?>> <?php echo awm_t('TEXT.AWM_LINK_ZUM_JAHRESWECHSEL_AUTOMAT'); ?>
        </label>
        <div class="sm-small"><?php echo awm_t('TEXT.VERSUCHT_JAHR_TAUSCH_UND_WEBSITE_S'); ?></div>
    </div>
</div>

<div class="sm-row">
    <div>
        <label><?php echo awm_t('HINWEIS.LABEL'); ?></label>
        <input data-role="none" type="text" name="hinweis_woerter"
               value="<?= aw_e(isset($aw_cfg['hinweis_woerter']) && trim((string) $aw_cfg['hinweis_woerter']) !== '' ? $aw_cfg['hinweis_woerter'] : AWM_HINWEIS_STANDARD) ?>"
               placeholder="<?= aw_e(AWM_HINWEIS_STANDARD) ?>">
        <div class="sm-small"><?php echo awm_t('HINWEIS.ERKLAERUNG'); ?></div>
    </div>
</div>

<h2><?php echo awm_t('TEXT.BENACHRICHTIGUNGEN'); ?></h2>
<div style="margin-bottom:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="notify_audio" <?= !empty($aw_notify['audio']) ? 'checked' : '' ?>> <?php echo awm_t('TEXT.AUDIOAUSGABE_AKTIV'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_push" <?= !empty($aw_notify['push']) ? 'checked' : '' ?>> <?php echo awm_t('TEXT.PUSH_NACHRICHT_AKTIV'); ?>
    </label>
    <div class="sm-small"><?php echo awm_t('TEXT.BEIDES_AN_ANSAGE_PUSH_NUR_EINES_AN'); ?> <span class="sm-mono"><?php echo awm_t('TEXT.AUDIO'); ?></span>/<span class="sm-mono"><?php echo awm_t('TEXT.PUSH'); ?></span>
    <?php echo awm_t('TEXT.AN_LOXONE_BERGEBEN_DEN_PUSH_VERSCH'); ?></div>
</div>
<div class="sm-row">
    <div>
        <label><?php echo awm_t('TEXT.ERINNERUNGS_UHRZEIT_AM_VORABEND'); ?></label>
        <input data-role="none" type="text" name="notify_time" value="<?= aw_e($aw_notify['time']) ?>" placeholder="18:00">
        <div class="sm-small"><?php echo awm_t('TEXT.ZU_DIESER_ZEIT_SPRICHT_DAS_PLUGIN_'); ?><span class="sm-mono"><?php echo awm_t('TEXT.ANN_1'); ?></span> <?php echo awm_t('TEXT.FR_10_MINUTEN'); ?></div>
    </div>
</div>

<h2><?php echo awm_t('TEXT.SPRACHAUSGABE'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo awm_t('TEXT.AUDIO_AUSGABE'); ?></label>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="awTtsMode()">
            <option value="musicserver"<?= $aw_tts['mode'] === 'musicserver' ? ' selected' : '' ?>><?php echo awm_t('TEXT.LOXONE_MUSIC_SERVER_KLASSISCH'); ?></option>
            <option value="ms4h"<?= $aw_tts['mode'] === 'ms4h' ? ' selected' : '' ?>><?php echo awm_t('TEXT.AUDIOSERVER4HOME_MUSICSERVER4HOME'); ?></option>
            <option value="audioserver"<?= $aw_tts['mode'] === 'audioserver' ? ' selected' : '' ?>><?php echo awm_t('TEXT.ORIGINAL_LOXONE_AUDIOSERVER_VIA_LO'); ?></option>
            <option value="custom"<?= $aw_tts['mode'] === 'custom' ? ' selected' : '' ?>><?php echo awm_t('TEXT.EIGENE_URL_VORLAGE'); ?></option>
        </select>
    </div>
    <div>
        <label><?php echo awm_t('TEXT.IP_DES_AUDIO_SERVERS'); ?></label>
        <input data-role="none" type="text" name="tts_ip" value="<?= aw_e($aw_tts['ip']) ?>" placeholder="z. B. 192.168.1.50">
    </div>
    <div>
        <label><?php echo awm_t('TEXT.PORT'); ?></label>
        <input data-role="none" type="number" name="tts_port" value="<?= (int) $aw_tts['port'] ?>" min="1" max="65535">
    </div>
</div>
<div class="sm-row">
    <div>
        <label><?php echo awm_t('TEXT.ZONEN'); ?></label>
        <input data-role="none" type="text" name="tts_zones" value="<?= aw_e($aw_tts['zones']) ?>" placeholder="z. B. 2,4,6">
        <div class="sm-small"><?php echo awm_t('TEXT.ZONENNUMMERN_MIT_KOMMA_Z_B'); ?> <span class="sm-mono">2,4,6</span><?php echo awm_t('TEXT.DIE_LAUTSTRKE_KOMMT_AUS_DEM_FELD_D'); ?> <span class="sm-mono"><?php echo awm_t('TEXT.ZONE_LAUTSTRKE'); ?></span> <?php echo awm_t('TEXT.Z_B'); ?> <span class="sm-mono">2~25,4~40</span><?php echo awm_t('TEXT.LEERZEICHEN_NACH_DEM_KOMMA_SIND_ER'); ?> <span class="sm-mono">2,4,6</span> und <span class="sm-mono">2, 4, 6</span> <?php echo awm_t('TEXT.FUNKTIONIEREN_BEIDE'); ?></div>
    </div>
    <div>
        <label><?php echo awm_t('TEXT.LAUTSTRKE'); ?></label>
        <input data-role="none" type="number" name="tts_volume" value="<?= (int) $aw_tts['volume'] ?>" min="1" max="100">
    </div>
    <div>
        <label><?php echo awm_t('TEXT.SPRACHE'); ?></label>
        <input data-role="none" type="text" name="tts_lang" value="<?= aw_e($aw_tts['lang']) ?>" maxlength="2">
    </div>
</div>
<div id="tts_template_row">
    <label><?php echo awm_t('TEXT.URL_VORLAGE_FR_AUDIOSERVER4HOME_MS'); ?></label>
    <textarea data-role="none" name="tts_template" id="tts_template" rows="2" placeholder="<?php echo awm_t('TEXT.HTTP'); ?>{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?= aw_e($aw_tts['template']) ?></textarea>
    <div class="sm-small"><?php echo awm_t('TEXT.PLATZHALTER'); ?> <span class="sm-mono"><?php echo awm_t('TEXT.IP_PORT_ZONES_VOL_LANG_TEXT'); ?></span><?php echo awm_t('TEXT.LEER_STANDARD_VORLAGE'); ?></div>
</div>
<div id="tts_audioserver_hint" class="sm-alert sm-info" style="display:none;">
    <?php echo awm_t('TEXT.DER_ORIGINALE_LOXONE_AUDIOSERVER_B'); ?> <b><?php echo awm_t('TEXT.KEINE_HTTP_TTS_SCHNITTSTELLE'); ?></b><?php echo awm_t('TEXT.IN_DIESEM_MODUS_SPRICHT_DAS_PLUGIN'); ?>
    <span class="sm-mono">ANN=1</span> <?php echo awm_t('TEXT.ANLEITUNG_SCHRITT_3'); ?>
</div>

<h2><?php echo awm_t('TEXT.MQTT_OPTIONAL'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="mqtt_enabled" <?= !empty($aw_cfg['mqtt_enabled']) ? 'checked' : '' ?>> <?php echo awm_t('TEXT.ZUSTAND_PER_MQTT_VERFFENTLICHEN'); ?>
</label>
<div class="sm-row" style="margin-top:6px;">
    <div>
        <label><?php echo awm_t('TEXT.TOPIC_PRFIX'); ?></label>
        <input data-role="none" type="text" name="mqtt_topic" value="<?= aw_e($aw_cfg['mqtt_topic']) ?>" placeholder="awm">
        <div class="sm-small"><?php echo awm_t('TEXT.NUTZT_DAS'); ?> <b><?php echo awm_t('TEXT.LOXBERRY_MQTT_GATEWAY'); ?></b><?php echo awm_t('TEXT.VERFFENTLICHT_BEI_AUML_NDERUNG_UND'); ?>
        <span class="sm-mono"><?= aw_e($aw_cfg['mqtt_topic']) ?><?php echo awm_t('TEXT.REST_MORGEN'); ?></span>, <span class="sm-mono"><?php echo awm_t('TEXT.BIO_MORGEN'); ?></span>,
        <span class="sm-mono"><?php echo awm_t('TEXT.PAPIER_MORGEN'); ?></span>, <span class="sm-mono"><?php echo awm_t('TEXT.WERT_MORGEN'); ?></span><?php echo awm_t('TEXT.DIESELBEN_MIT'); ?> <span class="sm-mono"><?php echo awm_t('TEXT.HEUTE'); ?></span>,
        <span class="sm-mono"><?php echo awm_t('TEXT.TAGE_REST'); ?></span> <?php echo awm_t('TEXT.USW'); ?> <span class="sm-mono"><?php echo awm_t('TEXT.DATUM_REST'); ?></span> <?php echo awm_t('TEXT.USW_2'); ?>,
        <span class="sm-mono">/ok</span>, <span class="sm-mono"><?php echo awm_t('TEXT.WARNUNG'); ?></span>, <span class="sm-mono"><?php echo awm_t('TEXT.HINWEIS_2'); ?></span>
        <?php echo awm_t('TEXT.KALENDER_2'); ?> <span class="sm-mono"><?= aw_e($aw_cfg['mqtt_topic']) ?>/2/...</span>).</div>
    </div>
</div>

<?php aw_legende(); ?>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" style="margin-top:0;"><?php echo awm_t('TEXT.SPEICHERN'); ?></button>
</div>
</form>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fetchnow" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" style="margin-top:0;"><?php echo awm_t('TEXT.JETZT_ABRUFEN'); ?></button>
</form>
</div>
</div>

<!-- ================= Reiter: Tonnen ================= -->
<div class="sm-pane<?= $aw_tab === 'tab-bins' ? ' sm-active' : '' ?>" id="tab-bins">
<h2><?php echo awm_t('TONNEN.H_TITEL'); ?></h2>
<div class="sm-alert sm-info"><?php echo awm_t('TONNEN.ERKLAERUNG'); ?></div>
<?php
$aw_bcal = max(1, min(max(1, count($aw_cals)), (int) (isset($_GET['bcal']) ? $_GET['bcal'] : 1)));
$aw_titel_liste = function_exists('awm_titel_der_datei') ? awm_titel_der_datei($aw_bcal) : array();
$aw_arten_anz = awm_tonnenarten();
$aw_regeln_anz = awm_regeln($aw_bcal);
// Zu welchem gefundenen Titel gibt es schon eine Regel?
$aw_regel_zu = array();
foreach ($aw_regeln_anz as $aw_r) { $aw_regel_zu[$aw_r['muster']] = $aw_r; }
?>
<?php aw_legende(); ?>
<?php if (count($aw_cals) > 1) { ?>
<div class="sm-knopfreihe">
<?php foreach ($aw_cals as $aw_n => $aw_c) { ?>
<a class="sm-btn <?= $aw_n === $aw_bcal ? 'sm-b-aktion' : 'sm-b-lesen' ?>"
   href="index.php?tab=tab-bins&amp;bcal=<?= (int) $aw_n ?>"><?= aw_e($aw_c['name']) ?></a>
<?php } ?>
</div>
<?php } ?>

<?php if (!$aw_titel_liste) { ?>
<div class="sm-alert sm-warn"><?php echo awm_t('TONNEN.KEINE_TITEL'); ?></div>
<?php // Der Knopf gehoert hierher: wer diese Meldung liest, braucht genau ihn.
      // Bis 1.1.0 verwies der Text auf den Reiter Test - dort gab es ihn nie. ?>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fetchnow" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-bins">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" style="margin-top:0;"><?php echo awm_t('TEXT.JETZT_ABRUFEN'); ?></button>
</form>
</div>
<?php } else { ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-bins">
<input data-role="none" type="hidden" name="bins_cal" value="<?= (int) $aw_bcal ?>">
<table class="sm-tbl2">
<tr><th><?php echo awm_t('TONNEN.T_TITEL'); ?></th>
    <th><?php echo awm_t('TONNEN.T_ANZAHL'); ?></th>
    <th><?php echo awm_t('TONNEN.T_JETZT'); ?></th>
    <th><?php echo awm_t('TONNEN.T_TONNE'); ?></th>
    <th><?php echo awm_t('TONNEN.T_ART'); ?></th></tr>
<?php $aw_k = 0; foreach ($aw_titel_liste as $aw_e2) {
    $aw_g = awm_glatt($aw_e2['titel']);
    $aw_hat = isset($aw_regel_zu[$aw_g]) ? $aw_regel_zu[$aw_g] : null; ?>
<tr>
  <td><code><?= aw_e($aw_e2['titel']) ?></code>
      <input data-role="none" type="hidden" name="bin_titel[<?= $aw_k ?>]" value="<?= aw_e($aw_e2['titel']) ?>"></td>
  <td><?= (int) $aw_e2['anzahl'] ?></td>
  <td><?php
      if (!$aw_e2['tonnen']) { echo '<span class="sm-aus">' . awm_t('TONNEN.NICHTS') . '</span>'; }
      else {
          $aw_n2 = array();
          foreach ($aw_e2['tonnen'] as $aw_tk) { $aw_n2[] = aw_e($aw_arten_anz[$aw_tk]['text']); }
          echo implode(' + ', $aw_n2);
          echo ' <span class="sm-hint">(' . awm_t($aw_e2['quelle'] === 'regel' ? 'TONNEN.Q_REGEL' : 'TONNEN.Q_EINGEBAUT') . ')</span>';
      } ?></td>
  <td><select data-role="none" name="bin_tonne[<?= $aw_k ?>]">
      <option value=""><?php echo awm_t('TONNEN.KEINE_ZUORDNUNG'); ?></option>
      <?php foreach ($aw_arten_anz as $aw_tk => $aw_tv) { ?>
      <option value="<?= aw_e($aw_tk) ?>"<?= ($aw_hat && $aw_hat['tonne'] === $aw_tk) ? ' selected' : '' ?>><?= aw_e($aw_tv['text']) ?></option>
      <?php } ?></select></td>
  <td><select data-role="none" name="bin_art[<?= $aw_k ?>]">
      <?php foreach (array('genau', 'enthaelt', 'beginnt') as $aw_ak) { ?>
      <option value="<?= $aw_ak ?>"<?= ($aw_hat && $aw_hat['art'] === $aw_ak) || (!$aw_hat && $aw_ak === 'genau') ? ' selected' : '' ?>><?php echo awm_t('TONNEN.ART_' . strtoupper($aw_ak)); ?></option>
      <?php } ?></select></td>
</tr>
<?php $aw_k++; } ?>
</table>
<p class="sm-hint"><?php echo awm_t('TONNEN.FUSSNOTE'); ?></p>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save_bins" value="1"><?php echo awm_t('TONNEN.K_SPEICHERN'); ?></button>
</div>
</form>
<?php } ?>
</div>

<div class="sm-pane<?= $aw_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?php echo awm_t('TEXT.EINBINDUNG_IN_LOXONE_SCHRITT_FR_SC'); ?></h2>
<p><?php echo awm_t('TEXT.DER_MINISERVER_FRAGT_DAS_PLUGIN_RE'); ?> <b><?php echo awm_t('TEXT.MORGEN'); ?></b> <?php echo awm_t('TEXT.FLLIG_WELCHE'); ?> <b><?php echo awm_t('TEXT.HEUTE_2'); ?></b><?php echo awm_t('TEXT.IN_WIE_VIELEN_TAGEN_KOMMT_DIE_NCHS'); ?> <span class="sm-mono">ANN</span><?php echo awm_t('TEXT.MIT_DEM_DER_MINISERVER_DEN_PUSH_VE'); ?> <b><?php echo awm_t('TEXT.ANSAGE'); ?></b> <?php echo awm_t('TEXT.SPRICHT_DAS_PLUGIN_SELBST_REITER_E'); ?></p>

<div class="sm-step"><b><?php echo awm_t('TEXT.SCHRITT_1_VIRTUELLER_HTTP_EINGANG_'); ?></b> <?php echo awm_t('TEXT.ABFRAGE_ALLE_300_S'); ?>
<table class="sm-tbl">
<tr><th><?php echo awm_t('TEXT.EIGENSCHAFT'); ?></th><th><?php echo awm_t('TEXT.WERT'); ?></th></tr>
<tr><td>URL</td><td><span class="sm-mono">http://<?= $aw_host ?><?php echo awm_t('TEXT.PLUGINS'); ?><?= aw_e($aw_plugin) ?><?php echo awm_t('TEXT.AWM_PHP'); ?></span> (Kalender 2: <span class="sm-mono"><?php echo awm_t('TEXT.CAL_2_2'); ?></span>)</td></tr>
<tr><td><?php echo awm_t('TEXT.ABFRAGEZYKLUS'); ?></td><td><?php echo awm_t('TEXT.300_SEKUNDEN'); ?></td></tr>
</table>
<?php echo awm_t('TEXT.BEFEHLE_JE_EIN_VIRTUELLER_HTTP_EIN'); ?> <span class="sm-mono">\i...\i</span> <?php echo awm_t('TEXT.SUCHTEXT'); ?> <span class="sm-mono">\v</span> <?php echo awm_t('TEXT.ZAHL_DAHINTER'); ?>
<table class="sm-tbl">
<tr><th><?php echo awm_t('TEXT.BEFEHLSERKENNUNG'); ?></th><th><?php echo awm_t('TEXT.BEDEUTUNG'); ?></th></tr>
<tr><td><span class="sm-mono"><?php echo awm_t('TEXT.IREST_I_V'); ?></span> / <span class="sm-mono"><?php echo awm_t('TEXT.IBIO_I_V'); ?></span> / <span class="sm-mono"><?php echo awm_t('TEXT.IPAPIER_I_V'); ?></span> / <span class="sm-mono"><?php echo awm_t('TEXT.IWERT_I_V'); ?></span></td><td><?php echo awm_t('TEXT.1_MORGEN_WIRD_DIESE_TONNE_ABGEHOLT'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo awm_t('TEXT.IHREST_I_V'); ?></span> usw.</td><td><?php echo awm_t('TEXT.1_HEUTE_FLLIG_Z_B_FR_TONNE_WIEDER_'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo awm_t('TEXT.ITREST_I_V'); ?></span> usw.</td><td><?php echo awm_t('TEXT.TAGE_BIS_ZUR_NCHSTEN_LEERUNG_0_HEU'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo awm_t('TEXT.IANN_I_V'); ?></span></td><td><?php echo awm_t('TEXT.1_ERINNERUNGSFENSTER_JETZT_10_MIN_'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo awm_t('TEXT.IPUSH_I_V'); ?></span> / <span class="sm-mono"><?php echo awm_t('TEXT.IAUDIO_I_V'); ?></span></td><td><?php echo awm_t('TEXT.FREIGABEN_AUS_DER_PLUGIN_KONFIGURA'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo awm_t('TEXT.IPTEST_I_V'); ?></span></td><td><?php echo awm_t('TEXT.1_TEST_PUSHNACHRICHT_ANGEFORDERT_R'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo awm_t('TEXT.IOK_I_V'); ?></span></td><td><?php echo awm_t('TEXT.1_KALENDERDATEN_VORHANDEN'); ?></td></tr>
<tr><td><span class="sm-mono"><?php echo awm_t('TEXT.IWARN_I_V'); ?></span></td><td><?php echo awm_t('TEXT.1_KALENDER_ENDET_IN_30_TAGEN_UND_A'); ?></td></tr>
</table>
<?php echo awm_t('TEXT.DIE_ZEILE_BEGINNT_MIT'); ?> <span class="sm-mono"><?php echo awm_t('TEXT.MUELL_REST_BIO_PAPIER_DATUM'); ?></span> <?php echo awm_t('TEXT.UND_IST_DAMIT'); ?>
<b><?php echo awm_t('TEXT.1_1_KOMPATIBEL'); ?></b> <?php echo awm_t('TEXT.ZU_BESTEHENDEN_KONFIGURATIONEN_DES'); ?>
</div>

<div class="sm-step"><b><?php echo awm_t('TEXT.SCHRITT_2_KACHELN_FR_DIE_APP'); ?></b><br>
<?php echo awm_t('TEXT.REST_BIO_PAPIER_WERT_ALS_DIGITALAN'); ?>
<span class="sm-mono"><?php echo awm_t('TEXT.V_0_TAGE'); ?></span><?php echo awm_t('TEXT.IN_DEN_EIGENSCHAFTEN_VISUALISIERUN'); ?>
</div>

<div class="sm-step"><b><?php echo awm_t('TEXT.SCHRITT_3_PUSH_NACHRICHT_QUITTIER_'); ?></b>
<table class="sm-tbl">
<tr><th><?php echo awm_t('TEXT.BAUSTEIN'); ?></th><th><?php echo awm_t('TEXT.NAME'); ?></th><th>Einstellung</th><th><?php echo awm_t('TEXT.EINGNGE'); ?></th></tr>
<tr><td><?php echo awm_t('TEXT.SCHWELLWERTSCHALTER_S1'); ?></td><td><?php echo awm_t('TEXT.ERINNERUNGSFENSTER_AKTIV'); ?></td><td><?php echo awm_t('TEXT.EIN_0_5_AUS_0_4'); ?></td><td><?php echo awm_t('TEXT.ANN'); ?></td></tr>
<tr><td><?php echo awm_t('TEXT.SCHWELLWERTSCHALTER_S2'); ?></td><td><?php echo awm_t('TEXT.PUSH_FREIGEGEBEN'); ?></td><td>Ein 0,5 / Aus 0,4</td><td><?php echo awm_t('TEXT.PUSH_2'); ?></td></tr>
<tr><td><?php echo awm_t('TEXT.UND_U1'); ?></td><td><?php echo awm_t('TEXT.MLL_PUSH_JETZT'); ?></td><td></td><td><?php echo awm_t('TEXT.S1_S2'); ?></td></tr>
<tr><td><?php echo awm_t('TEXT.ODER_O1'); ?></td><td><?php echo awm_t('TEXT.PUSH_SAMMLER'); ?></td><td><?php echo awm_t('TEXT.EINZIGE_QUELLE_DES_BENACHRICHTIGUN'); ?></td><td>U1</td></tr>
<tr><td><?php echo awm_t('TEXT.BENACHRICHTIGUNGS_BAUSTEIN'); ?></td><td><?php echo awm_t('TEXT.PUSH_TONNE_RAUSSTELLEN'); ?></td><td><?php echo awm_t('TEXT.TEXT_Z_B_MORGEN_IST_MLLABFUHR_TONN'); ?></td><td><?php echo awm_t('TEXT.O1'); ?></td></tr>
<tr><td><?php echo awm_t('TEXT.TASTER_REMANENT_VISU'); ?></td><td><?php echo awm_t('TEXT.TONNE_STEHT_DRAUEN'); ?></td><td><?php echo awm_t('TEXT.QUITTIERUNG_DURCH_DIE_FAMILIE'); ?></td><td></td></tr>
<tr><td><?php echo awm_t('TEXT.IMPULSGEBER_BEI_UHRZEIT'); ?></td><td><?php echo awm_t('TEXT.IMPULS_21_00_NACHFASS'); ?></td><td><?php echo awm_t('TEXT.21_00_UHR'); ?></td><td></td></tr>
<tr><td><?php echo awm_t('TEXT.UND_U2_NICHT_N1'); ?></td><td><?php echo awm_t('TEXT.NACHFASS_NTIG'); ?></td><td><?php echo awm_t('TEXT.ERINNERUNG_KAM_ABER_NICHT_QUITTIER'); ?></td><td><?php echo awm_t('TEXT.U2_IMPULS_21_00_TAGES_MERKER_N1_TA'); ?></td></tr>
<tr><td><?php echo awm_t('TEXT.BENACHRICHTIGUNGS_BAUSTEIN_2'); ?></td><td><?php echo awm_t('TEXT.PUSH_TONNE_IMMER_NOCH_DRIN'); ?></td><td></td><td><?php echo awm_t('TEXT.U2'); ?></td></tr>
<tr><td><?php echo awm_t('TEXT.MERKER_TASTER_REMANENT'); ?></td><td><?php echo awm_t('TEXT.TAGES_MERKER_ERINNERUNG'); ?></td><td><?php echo awm_t('TEXT.EIN_DURCH_U1_RESET_3_00_UHR_SETZT_'); ?></td><td></td></tr>
<tr><td><?php echo awm_t('TEXT.BENACHRICHTIGUNGS_BAUSTEIN_3'); ?></td><td><?php echo awm_t('TEXT.TEST_PUSH'); ?></td><td><?php echo awm_t('TEXT.EIGENER_BAUSTEIN_NUR_FR_DEN_TEST'); ?></td><td><?php echo awm_t('TEXT.SCHWELLWERTSCHALTER_AN_PTEST_EIN_0'); ?></td></tr>
</table>
<b><?php echo awm_t('TEXT.PRAXIS_ERFAHRUNGEN_ZUM_BENACHRICHT'); ?></b> <?php echo awm_t('TEXT.ERSPART_LANGE_FEHLERSUCHE'); ?><br>
<?php echo awm_t('TEXT.ER_SENDET_NUR_BEI_EINER_01_FLANKE_'); ?><br>
<?php echo awm_t('TEXT.FR_DEN_TEST_PTEST_EINEN_EIGENEN_BE'); ?><br>
<?php echo awm_t('TEXT.WARN_1_EIGNET_SICH_FR_EINEN_VIERTE'); ?>
</div>

<div class="sm-step"><b><?php echo awm_t('TEXT.SCHRITT_4_MQTT_ALTERNATIVE_JSON'); ?></b><br>
<?php echo awm_t('TEXT.ALLE_WERTE_GIBT_ES_AUCH_BER_DAS_LO'); ?>
<span class="sm-mono"><?= aw_e($aw_cfg['mqtt_topic']) ?>/...</span> <?php echo awm_t('TEXT.UND_ALS_JSON_FR_DRITTSOFTWARE'); ?>
<span class="sm-mono">http://<?= $aw_host ?>/plugins/<?= aw_e($aw_plugin) ?><?php echo awm_t('TEXT.AWM_PHP_JSON_1'); ?></span>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-pane<?= $aw_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?php echo awm_t('REITER.TEST'); ?></h2>
<?php aw_legende(); ?>

<h3 class="sm-h3"><?php echo awm_t('TEXT.ANSEHEN'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-lesen"  href="/plugins/<?= aw_e($aw_plugin) ?>/awm.php" target="_blank"><?php echo awm_t('TEXT.LOXONE_ZEILE_ABRUFEN'); ?></a>
<a class="sm-btn sm-b-lesen"  href="/plugins/<?= aw_e($aw_plugin) ?>/awm.php?json=1" target="_blank"><?php echo awm_t('TEXT.JSON_ANSICHT'); ?></a>
</div>

<h3 class="sm-h3"><?php echo awm_t('TONNEN.H_SELBSTTEST'); ?></h3>
<p class="sm-hint"><?php echo awm_t('TONNEN.SELBSTTEST_TEXT'); ?></p>
<?php
$aw_pruef = function_exists('awm_selbstpruefung_regeln') ? awm_selbstpruefung_regeln() : array();
// Ab 1.2.0 gehoert die Link-Erneuerung mit in den Selbsttest: sie prueft,
// dass der AWM-Link genauso umgeschrieben wird wie bisher.
if (function_exists('awm_selbstpruefung_erneuerung')) {
    $aw_pruef = array_merge($aw_pruef, awm_selbstpruefung_erneuerung());
}
// Ab 1.3.0 zusaetzlich die vier Stellen, an denen das Plugin bis 1.2.0
// stillschweigend das Falsche tat: Zeichensatz, Sprachausgabe-Vorlage,
// Stichwoerter fuer Verschiebungen, unteilbares Schreiben.
if (function_exists('awm_selbstpruefung_robust')) {
    $aw_pruef = array_merge($aw_pruef, awm_selbstpruefung_robust());
}
$aw_pf = 0;
foreach ($aw_pruef as $aw_z) { if (!$aw_z[0]) { $aw_pf++; } }
?>
<div class="sm-alert <?= $aw_pf ? 'sm-warn' : 'sm-info' ?>">
<?= sprintf(awm_t($aw_pf ? 'TONNEN.SELBSTTEST_FEHL' : 'TONNEN.SELBSTTEST_OK'),
            count($aw_pruef) - $aw_pf, count($aw_pruef)) ?>
</div>
<?php if ($aw_pf) { ?>
<ul>
<?php foreach ($aw_pruef as $aw_z) { if (!$aw_z[0]) { ?><li><?= aw_e($aw_z[1]) ?></li><?php } } ?>
</ul>
<?php } ?>

<h3 class="sm-h3"><?php echo awm_t('TEXT.TECHNISCHE_AUSKUNFT'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-technik"  href="/plugins/<?= aw_e($aw_plugin) ?>/awm.php?debug=1" target="_blank"><?php echo awm_t('TEXT.DEBUG_ALLE_TERMINE'); ?></a>
<a class="sm-btn sm-b-technik"  href="/plugins/<?= aw_e($aw_plugin) ?>/awm.php?refresh=1&amp;debug=1" target="_blank"><?php echo awm_t('TEXT.NEU_ABRUFEN_DEBUG'); ?></a>
<a class="sm-btn sm-b-technik" style="margin-left:8px;" href="/plugins/<?= aw_e($aw_plugin) ?>/awm.php?debug=1&amp;cal=2" target="_blank"><?php echo awm_t('TEXT.DEBUG_KALENDER_2'); ?></a>
</div>

<h3 class="sm-h3"><?php echo awm_t('TEXT.LST_ETWAS_AUS'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= aw_e($aw_plugin) ?>/awm.php?say=1" target="_blank"><?php echo awm_t('TEXT.TEST_ANSAGE_JETZT'); ?></a>
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= aw_e($aw_plugin) ?>/awm.php?ptest=1" target="_blank"><?php echo awm_t('TEXT.TEST_PUSHNACHRICHT_2'); ?></a>
<a class="sm-btn sm-b-aktion"  href="/plugins/<?= aw_e($aw_plugin) ?>/awm.php?renew=1" target="_blank"><?php echo awm_t('TEXT.JAHRES_ERNEUERUNG_TESTEN'); ?></a>
</div>


<div class="sm-small">
<?php echo awm_t('TEXT.TEXT_2'); ?> <b><?php echo awm_t('TEXT.LOXONE_ZEILE'); ?></b> <?php echo awm_t('TEXT.ZEIGT_GENAU_DAS_WAS_DER_MINISERVER'); ?><br>
&bull; <b><?php echo awm_t('TEXT.TEST_ANSAGE'); ?></b> <?php echo awm_t('TEXT.SPRICHT_SOFORT_IN_DEN_KONFIGURIERT'); ?><br>
&bull; <b><?php echo awm_t('TEXT.TEST_PUSHNACHRICHT'); ?></b> <?php echo awm_t('TEXT.SETZT'); ?> <span class="sm-mono"><?php echo awm_t('TEXT.PTEST_1'); ?></span> <?php echo awm_t('TEXT.FR_5_MINUTEN_DER_PUSH_KOMMT_BER_DE'); ?><br>
&bull; <b><?php echo awm_t('TEXT.JAHRES_ERNEUERUNG'); ?></b> <?php echo awm_t('TEXT.VERSUCHT_SOFORT_EINEN_FRISCHEN_AWM'); ?>
</div>
</div>

<!-- ================= Reiter: <?php echo awm_t('TEXT.PROTOKOLL'); ?> ================= -->
<div class="sm-pane<?= $aw_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?php echo awm_t('REITER.LOG'); ?></h2>
<div class="sm-small" style="margin-bottom:8px;"><?php echo awm_t('TEXT.PROTOKOLLIERT_WERDEN_KALENDER_ABRU'); ?><br><?php echo awm_t('TEXT.DATEI'); ?> <span class="sm-mono"><?= aw_e($aw_logfile) ?></span></div>
<?php if ($aw_loglines) { ?>
<div class="sm-log"><?= aw_e(implode("\n", $aw_loglines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo awm_t('TEXT.NOCH_KEINE_PROTOKOLL_EINTRGE_VORHA'); ?></div>
<?php } ?>
<?php aw_legende(); ?>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo awm_t('TEXT.PROTOKOLL_LEEREN'); ?></button>
</form>
</div>
</div>

</div>
<script>
function awTtsMode() {
    var m = document.getElementById('tts_mode').value;
    document.getElementById('tts_audioserver_hint').style.display = (m === 'audioserver') ? 'block' : 'none';
    document.getElementById('tts_template_row').style.display = (m === 'ms4h' || m === 'custom') ? 'block' : 'none';
    var port = document.getElementsByName('tts_port')[0];
    if (m === 'musicserver' && (!port.value || port.value === '80')) { port.value = 7091; }
}
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.pane === id); });
        document.querySelectorAll('.sm-pane').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
    }
    // Die Reiter sind echte Verweise. Solange dieses Skript laeuft, wird
    // der Klick abgefangen und nur umgeschaltet - ohne Skript folgt der
    // Browser dem Verweis und baut die Seite neu auf. Beides fuehrt zum
    // selben Reiter, und die Adresszeile stimmt in beiden Faellen.
    tabs.forEach(function (t) {
        t.addEventListener('click', function (ev) {
            ev.preventDefault();
            activate(t.dataset.pane);
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', t.getAttribute('href'));
            }
        });
    });
    activate(<?= json_encode($aw_tab) ?>);
    awTtsMode();
})();
</script>
<?php
if ($aw_frame) {
    LBWeb::lbfooter();
}
