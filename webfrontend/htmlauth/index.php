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
$aw_tab = preg_match('/^tab-(settings|loxone|test|log)$/', (string) (isset($_POST['activetab']) ? $_POST['activetab'] : '')) ? $_POST['activetab'] : 'tab-settings';

// ---------- Protokoll leeren ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    @mkdir(dirname($aw_logfile), 0775, true);
    @file_put_contents($aw_logfile, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Admin-Oberflaeche)\n");
    $aw_tab = 'tab-log';
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
        $aw_new['cals'][] = array(
            'name' => trim((string) (isset($aw_names[$aw_i]) ? $aw_names[$aw_i] : '')),
            'url' => $aw_u,
        );
    }
    $aw_new['fetch_days'] = max(1, min(60, (int) (isset($_POST['fetch_days']) ? $_POST['fetch_days'] : 14)));
    $aw_new['lookahead'] = max(7, min(90, (int) (isset($_POST['lookahead']) ? $_POST['lookahead'] : 35)));
    $aw_new['autorenew'] = isset($_POST['autorenew']) ? 1 : 0;
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
        $aw_json = json_encode($aw_new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($aw_cfgfile, $aw_json) !== false) {
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

$aw_frame = class_exists('LBWeb', false);
if ($aw_frame) {
    LBWeb::lbheader('Abfuhrkalender AWM M&uuml;nchen', 'https://wiki.loxberry.de/', '');
}
$aw_host = aw_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '<loxberry-ip>');
?>
<style>
.aw-wrap { max-width: 940px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.aw-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.aw-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.aw-wrap input[type=text], .aw-wrap input[type=number], .aw-wrap select, .aw-wrap textarea {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.aw-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.aw-row { display: flex; gap: 12px; }
.aw-row > div { flex: 1; }
.aw-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.aw-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.aw-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.aw-err { background: #ffebee; border: 1px solid #ef9a9a; }
.aw-warn { background: #fff8e1; border: 1px solid #ffe082; }
.aw-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.aw-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.aw-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.aw-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.aw-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; text-shadow: none !important; }
.aw-tab.aw-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.aw-pane { display: none; padding-top: 4px; }
.aw-pane.aw-active { display: block; }
.aw-log { text-shadow: none !important; background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.aw-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.aw-tbl { border-collapse: collapse; margin: 8px 0; }
.aw-tbl th, .aw-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; }
.aw-tbl th { background: #f0f0f0; }
.aw-wrap .aw-btn, .aw-wrap a.aw-btn, .aw-wrap button { text-shadow: none !important; box-shadow: none !important; }
.aw-wrap a.aw-btn, .aw-wrap a.aw-btn:visited, .aw-wrap a.aw-btn:hover { color: #fff !important; text-decoration: none; }
</style>
<div class="aw-wrap">

<?php if ($aw_saved) { ?><div class="aw-alert aw-ok"><b>Konfiguration gespeichert</b> (inkl. Sicherungskopie f&uuml;r Updates).</div><?php } ?>
<?php if ($aw_note !== '') { ?><div class="aw-alert aw-ok"><?= aw_e($aw_note) ?></div><?php } ?>
<?php if ($aw_err !== '') { ?><div class="aw-alert aw-err"><b>Fehler:</b> <?= aw_e($aw_err) ?></div><?php } ?>

<?php if (!$aw_cals) { ?>
<div class="aw-alert aw-info"><b>Noch kein Kalender eingerichtet.</b> Bitte unten die iCal-URL eintragen, speichern und &bdquo;Jetzt abrufen&ldquo; klicken.</div>
<?php } ?>
<?php foreach ($aw_states as $aw_n => $aw_st) { ?>
<div class="aw-alert aw-info"><b><?= aw_e($aw_cals[$aw_n]['name']) ?></b>:
<?php if ($aw_st['ok']) { ?>
<?= (int) $aw_st['ereignisse'] ?> Serien/Termine &middot;
Morgen: <?= $aw_st['morgen']['rest'] ? '<b>Restm&uuml;ll</b> ' : '' ?><?= $aw_st['morgen']['bio'] ? '<b>Bio</b> ' : '' ?><?= $aw_st['morgen']['papier'] ? '<b>Papier</b> ' : '' ?><?= $aw_st['morgen']['wert'] ? '<b>Wertstoff</b> ' : '' ?><?= ($aw_st['morgen']['rest'] || $aw_st['morgen']['bio'] || $aw_st['morgen']['papier'] || $aw_st['morgen']['wert']) ? '' : 'keine Abholung' ?> &middot;
N&auml;chste: Rest <?= $aw_st['tage']['rest'] >= 0 ? 'in ' . (int) $aw_st['tage']['rest'] . ' T.' : '-' ?>,
Bio <?= $aw_st['tage']['bio'] >= 0 ? 'in ' . (int) $aw_st['tage']['bio'] . ' T.' : '-' ?>,
Papier <?= $aw_st['tage']['papier'] >= 0 ? 'in ' . (int) $aw_st['tage']['papier'] . ' T.' : '-' ?>
<?php if (!empty($aw_st['termine'])) { ?>
<table class="aw-tbl"><tr><th>Datum</th><th>Abholung</th></tr>
<?php foreach (array_slice($aw_st['termine'], 0, 6) as $aw_t) { ?>
<tr><td><?= aw_e(aw_d($aw_t[0])) ?></td><td><?= aw_e($aw_t[1]) ?></td></tr>
<?php } ?></table>
<?php } ?>
<?php } else { ?>
<b>Noch keine Termine geladen</b> &mdash; bitte &bdquo;Jetzt abrufen&ldquo; klicken (Reiter Einstellungen).
<?php } ?>
<?php if (!empty($aw_st['hinweis'])) { ?><br><b>Hinweis vom Entsorger:</b> <?= aw_e($aw_st['hinweis']) ?><?php } ?>
<?php if (!empty($aw_st['warnung'])) { ?><br><b style="color:#c62828;">Achtung:</b> Kalender endet in &lt;30 Tagen (Jahreswechsel). Die automatische Erneuerung l&auml;uft &mdash; falls sie fehlschl&auml;gt (Protokoll), bitte neuen iCal-Link eintragen.<?php } ?>
</div>
<?php } ?>

<div class="aw-tabs">
    <div class="aw-tab" data-pane="tab-settings">Einstellungen</div>
    <div class="aw-tab" data-pane="tab-loxone">Einbindung in Loxone</div>
    <div class="aw-tab" data-pane="tab-test">Test</div>
    <div class="aw-tab" data-pane="tab-log">Protokoll</div>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="aw-pane" id="tab-settings">
<form method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2>Datenquellen (bis zu 2 Kalender)</h2>
<table class="aw-tbl" style="width:100%;">
<tr><th style="width:36px;">Nr.</th><th style="width:180px;">Name (frei)</th><th>iCal-Kalender-URL</th></tr>
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
<div class="aw-small">Kalender 1 ist der Standard (alle Loxone-Aufrufe ohne <span class="aw-mono">&amp;cal=</span>);
Kalender 2 wird mit <span class="aw-mono">&amp;cal=2</span> abgefragt (MQTT: <span class="aw-mono"><?= aw_e($aw_cfg['mqtt_topic']) ?>/2/...</span>).
Ansage/Push-Fenster beziehen sich auf Kalender 1.</div>

<div class="aw-step"><b>Abfuhrkalender AWM M&uuml;nchen einrichten</b><br>
1. <a href="https://www.awm-muenchen.de/abfall-entsorgen/muelltonnen/abfuhrkalender" target="_blank">AWM-Abfuhrkalender &ouml;ffnen</a>,
dort Stra&szlig;e und Hausnummer eingeben und auf &bdquo;Weiter&ldquo; klicken.<br>
2. Auf der Ergebnisseite den iCal-Export-Link (&bdquo;Termine als iCal/ICS&ldquo;) mit der <b>rechten Maustaste</b> anklicken
und &bdquo;Link-Adresse kopieren&ldquo; w&auml;hlen.<br>
3. Den kopierten Link oben in das Feld &bdquo;iCal-Kalender-URL&ldquo; einf&uuml;gen und unten &bdquo;Speichern&ldquo; klicken.<br>
4. Danach einmal &bdquo;Jetzt abrufen&ldquo; klicken &mdash; der Kalender wird geladen und ab dann automatisch aktuell gehalten.<br>
<span class="aw-small"><b>Hinweis:</b> Der AWM-Link enth&auml;lt das Kalenderjahr. Das Plugin erneuert den Link zum
Jahreswechsel automatisch (siehe unten); falls das fehlschl&auml;gt, warnt es rechtzeitig (oben, per
<span class="aw-mono">WARN=1</span> in Loxone und per MQTT) &mdash; dann einfach einen neuen Link holen und einf&uuml;gen.
Funktioniert auch mit iCal-Exporten anderer Entsorger, solange die Termin-Titel die Tonnen-Namen enthalten.</span>
</div>

<div class="aw-row">
    <div>
        <label>Abruf-Intervall (Tage)</label>
        <input data-role="none" type="number" name="fetch_days" value="<?= (int) $aw_cfg['fetch_days'] ?>" min="1" max="60">
        <div class="aw-small">Abfuhrkalender &auml;ndern sich selten &mdash; Standard <b>14 Tage</b> reicht v&ouml;llig und schont den Entsorger-Server.</div>
    </div>
    <div>
        <label>Vorschau-Fenster (Tage)</label>
        <input data-role="none" type="number" name="lookahead" value="<?= (int) $aw_cfg['lookahead'] ?>" min="7" max="90">
        <div class="aw-small">F&uuml;r Terminliste, Tage-Z&auml;hler und Debug-Ansicht. Empfehlung 35.</div>
    </div>
    <div>
        <label style="margin-top:10px;">&nbsp;</label>
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
            <input data-role="none" type="checkbox" name="autorenew" <?= !empty($aw_cfg['autorenew']) ? 'checked' : '' ?>> AWM-Link zum Jahreswechsel automatisch erneuern
        </label>
        <div class="aw-small">Versucht Jahr-Tausch und Website-Scraping (h&ouml;chstens 1x t&auml;glich, Ergebnis im Protokoll).</div>
    </div>
</div>

<h2>Benachrichtigungen</h2>
<div style="margin-bottom:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="notify_audio" <?= !empty($aw_notify['audio']) ? 'checked' : '' ?>> Audioausgabe aktiv
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_push" <?= !empty($aw_notify['push']) ? 'checked' : '' ?>> Push-Nachricht aktiv
    </label>
    <div class="aw-small">Beides an = Ansage + Push. Nur eines an = nur diese Ausgabe. Beides aus = keine Meldung.
    Die Ansage spricht das Plugin selbst; die Freigaben werden zus&auml;tzlich als <span class="aw-mono">AUDIO=</span>/<span class="aw-mono">PUSH=</span>
    an Loxone &uuml;bergeben (den Push verschickt der Miniserver, Anleitung Schritt 3).</div>
</div>
<div class="aw-row">
    <div>
        <label>Erinnerungs-Uhrzeit (am Vorabend)</label>
        <input data-role="none" type="text" name="notify_time" value="<?= aw_e($aw_notify['time']) ?>" placeholder="18:00">
        <div class="aw-small">Zu dieser Zeit spricht das Plugin die Ansage und &ouml;ffnet das Push-Fenster (<span class="aw-mono">ANN=1</span> f&uuml;r 10 Minuten).</div>
    </div>
</div>

<h2>Sprachausgabe</h2>
<div class="aw-row">
    <div>
        <label>Audio-Ausgabe</label>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="awTtsMode()">
            <option value="musicserver"<?= $aw_tts['mode'] === 'musicserver' ? ' selected' : '' ?>>Loxone Music Server (klassisch)</option>
            <option value="ms4h"<?= $aw_tts['mode'] === 'ms4h' ? ' selected' : '' ?>>Audioserver4Home / MusicServer4Home</option>
            <option value="audioserver"<?= $aw_tts['mode'] === 'audioserver' ? ' selected' : '' ?>>Original Loxone Audioserver (via Loxone Config)</option>
            <option value="custom"<?= $aw_tts['mode'] === 'custom' ? ' selected' : '' ?>>Eigene URL-Vorlage</option>
        </select>
    </div>
    <div>
        <label>IP des Audio-Servers</label>
        <input data-role="none" type="text" name="tts_ip" value="<?= aw_e($aw_tts['ip']) ?>" placeholder="z. B. 192.168.1.50">
    </div>
    <div>
        <label>Port</label>
        <input data-role="none" type="number" name="tts_port" value="<?= (int) $aw_tts['port'] ?>" min="1" max="65535">
    </div>
</div>
<div class="aw-row">
    <div>
        <label>Zonen</label>
        <input data-role="none" type="text" name="tts_zones" value="<?= aw_e($aw_tts['zones']) ?>" placeholder="z. B. 2,4,6">
        <div class="aw-small">Zonennummern mit Komma (z.&nbsp;B. <span class="aw-mono">2,4,6</span>) &mdash; die Lautst&auml;rke kommt aus dem Feld daneben. Optional je Zone eigene Lautst&auml;rke: <span class="aw-mono">Zone~Lautst&auml;rke</span> (z.&nbsp;B. <span class="aw-mono">2~25,4~40</span>). Leerzeichen nach dem Komma sind erlaubt &mdash; <span class="aw-mono">2,4,6</span> und <span class="aw-mono">2, 4, 6</span> funktionieren beide.</div>
    </div>
    <div>
        <label>Lautst&auml;rke (%)</label>
        <input data-role="none" type="number" name="tts_volume" value="<?= (int) $aw_tts['volume'] ?>" min="1" max="100">
    </div>
    <div>
        <label>Sprache</label>
        <input data-role="none" type="text" name="tts_lang" value="<?= aw_e($aw_tts['lang']) ?>" maxlength="2">
    </div>
</div>
<div id="tts_template_row">
    <label>URL-Vorlage (f&uuml;r Audioserver4Home/MS4H bzw. eigene Ausgabe)</label>
    <textarea data-role="none" name="tts_template" id="tts_template" rows="2" placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?= aw_e($aw_tts['template']) ?></textarea>
    <div class="aw-small">Platzhalter: <span class="aw-mono">{ip} {port} {zones} {vol} {lang} {text}</span>. Leer = Standard-Vorlage.</div>
</div>
<div id="tts_audioserver_hint" class="aw-alert aw-info" style="display:none;">
    Der originale Loxone Audioserver bietet <b>keine HTTP-TTS-Schnittstelle</b>. In diesem Modus spricht das Plugin NICHT selbst;
    die Sprachausgabe baut man in Loxone Config: Textgenerator &rarr; TTS-Eingang des Audioplayers, ausgel&ouml;st &uuml;ber
    <span class="aw-mono">ANN=1</span> (Anleitung Schritt 3).
</div>

<h2>MQTT (optional)</h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="mqtt_enabled" <?= !empty($aw_cfg['mqtt_enabled']) ? 'checked' : '' ?>> Zustand per MQTT ver&ouml;ffentlichen
</label>
<div class="aw-row" style="margin-top:6px;">
    <div>
        <label>Topic-Pr&auml;fix</label>
        <input data-role="none" type="text" name="mqtt_topic" value="<?= aw_e($aw_cfg['mqtt_topic']) ?>" placeholder="awm">
        <div class="aw-small">Nutzt das <b>LoxBerry MQTT Gateway</b>. Ver&ouml;ffentlicht bei &Auml;nderung und alle 30 min:
        <span class="aw-mono"><?= aw_e($aw_cfg['mqtt_topic']) ?>/rest_morgen</span>, <span class="aw-mono">/bio_morgen</span>,
        <span class="aw-mono">/papier_morgen</span>, <span class="aw-mono">/wert_morgen</span>, dieselben mit <span class="aw-mono">_heute</span>,
        <span class="aw-mono">/tage_rest</span> usw., <span class="aw-mono">/datum_rest</span> usw.,
        <span class="aw-mono">/ok</span>, <span class="aw-mono">/warnung</span>, <span class="aw-mono">/hinweis</span>
        (Kalender 2: <span class="aw-mono"><?= aw_e($aw_cfg['mqtt_topic']) ?>/2/...</span>).</div>
    </div>
</div>

<button data-role="none" class="aw-btn" type="submit">Speichern</button>
</form>
<form method="post" style="margin-top:8px;">
    <input data-role="none" type="hidden" name="fetchnow" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="aw-btn" type="submit" style="background:#607d8b;margin-top:0;">Jetzt abrufen</button>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="aw-pane" id="tab-loxone">
<h2>Einbindung in Loxone &mdash; Schritt f&uuml;r Schritt</h2>
<p>Der Miniserver fragt das Plugin regelm&auml;&szlig;ig ab und bekommt fertig ausgewertete Werte:
Welche Tonne ist <b>morgen</b> f&auml;llig, welche <b>heute</b>, in wie vielen Tagen kommt die n&auml;chste Leerung &mdash;
plus das Erinnerungsfenster <span class="aw-mono">ANN</span>, mit dem der Miniserver den Push verschickt.
Die <b>Ansage</b> spricht das Plugin selbst (Reiter Einstellungen).</p>

<div class="aw-step"><b>Schritt 1: Virtueller HTTP-Eingang &bdquo;Abfuhrkalender&ldquo;</b> (Abfrage alle 300 s)
<table class="aw-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>URL</td><td><span class="aw-mono">http://<?= $aw_host ?>/plugins/<?= aw_e($aw_plugin) ?>/awm.php</span> (Kalender 2: <span class="aw-mono">?cal=2</span>)</td></tr>
<tr><td>Abfragezyklus</td><td>300 Sekunden</td></tr>
</table>
Befehle (je ein &bdquo;Virtueller HTTP-Eingang Befehl&ldquo;; <span class="aw-mono">\i...\i</span> = Suchtext, <span class="aw-mono">\v</span> = Zahl dahinter):
<table class="aw-tbl">
<tr><th>Befehlserkennung</th><th>Bedeutung</th></tr>
<tr><td><span class="aw-mono">\iREST=\i\v</span> / <span class="aw-mono">\iBIO=\i\v</span> / <span class="aw-mono">\iPAPIER=\i\v</span> / <span class="aw-mono">\iWERT=\i\v</span></td><td>1 = MORGEN wird diese Tonne abgeholt</td></tr>
<tr><td><span class="aw-mono">\iHREST=\i\v</span> usw.</td><td>1 = HEUTE f&auml;llig (z. B. f&uuml;r &bdquo;Tonne wieder reinholen&ldquo;)</td></tr>
<tr><td><span class="aw-mono">\iTREST=\i\v</span> usw.</td><td>Tage bis zur n&auml;chsten Leerung (0 = heute; &minus;1 = unbekannt) &mdash; als Kachel &bdquo;Restm&uuml;ll in X Tagen&ldquo;</td></tr>
<tr><td><span class="aw-mono">\iANN=\i\v</span></td><td>1 = Erinnerungsfenster JETZT (10 min ab eingestellter Uhrzeit, wenn morgen etwas f&auml;llig ist) &mdash; Ausl&ouml;ser f&uuml;r den Push</td></tr>
<tr><td><span class="aw-mono">\iPUSH=\i\v</span> / <span class="aw-mono">\iAUDIO=\i\v</span></td><td>Freigaben aus der Plugin-Konfiguration (Push nur senden, wenn PUSH=1)</td></tr>
<tr><td><span class="aw-mono">\iPTEST=\i\v</span></td><td>1 = Test-Pushnachricht angefordert (Reiter Test; 5 min aktiv)</td></tr>
<tr><td><span class="aw-mono">\iOK=\i\v</span></td><td>1 = Kalenderdaten vorhanden</td></tr>
<tr><td><span class="aw-mono">\iWARN=\i\v</span></td><td>1 = Kalender endet in &lt;30 Tagen und Auto-Erneuerung ist (noch) nicht gelungen &mdash; idealer Ausl&ouml;ser f&uuml;r einen Admin-Push</td></tr>
</table>
Die Zeile beginnt mit <span class="aw-mono">MUELL;REST=..;BIO=..;PAPIER=..;DATUM=..</span> und ist damit
<b>1:1 kompatibel</b> zu bestehenden Konfigurationen des klassischen muell.php-Skripts.
</div>

<div class="aw-step"><b>Schritt 2: Kacheln f&uuml;r die App</b><br>
REST/BIO/PAPIER/WERT als Digitalanzeigen &bdquo;morgen f&auml;llig&ldquo;, die T-Werte als Analoganzeigen mit Einheit
<span class="aw-mono">&lt;v.0&gt; Tage</span>. In den Eigenschaften &bdquo;Visualisierung&ldquo; freigeben und Raum/Kategorie zuordnen.
</div>

<div class="aw-step"><b>Schritt 3: Push-Nachricht + Quittier-Taster &mdash; komplette Baustein-Liste</b>
<table class="aw-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Schwellwertschalter S1</td><td>Erinnerungsfenster aktiv</td><td>Ein 0,5 / Aus 0,4</td><td>&larr; ANN</td></tr>
<tr><td>Schwellwertschalter S2</td><td>Push freigegeben</td><td>Ein 0,5 / Aus 0,4</td><td>&larr; PUSH</td></tr>
<tr><td>UND U1</td><td>M&uuml;ll-Push jetzt</td><td></td><td>S1 &amp; S2</td></tr>
<tr><td>ODER O1</td><td>Push-Sammler</td><td>einzige Quelle des Benachrichtigungs-Bausteins!</td><td>U1</td></tr>
<tr><td>Benachrichtigungs-Baustein</td><td>Push &bdquo;Tonne rausstellen&ldquo;</td><td>Text z. B. &bdquo;Morgen ist M&uuml;llabfuhr &mdash; Tonne rausstellen! (Details: App-Kacheln)&ldquo;</td><td>&larr; O1</td></tr>
<tr><td>Taster (remanent, Visu)</td><td>Tonne steht drau&szlig;en</td><td>Quittierung durch die Familie</td><td></td></tr>
<tr><td>Impulsgeber bei Uhrzeit</td><td>Impuls 21:00 Nachfass</td><td>21:00 Uhr</td><td></td></tr>
<tr><td>UND U2 + NICHT N1</td><td>Nachfass n&ouml;tig</td><td>Erinnerung kam, aber nicht quittiert</td><td>U2: Impuls 21:00 &amp; Tages-Merker &amp; N1(&larr; Taster)</td></tr>
<tr><td>Benachrichtigungs-Baustein 2</td><td>Push &bdquo;Tonne immer noch drin!&ldquo;</td><td></td><td>&larr; U2</td></tr>
<tr><td>Merker (Taster remanent)</td><td>Tages-Merker Erinnerung</td><td>EIN durch U1, Reset 3:00 Uhr (setzt auch den Quittier-Taster zur&uuml;ck)</td><td></td></tr>
<tr><td>Benachrichtigungs-Baustein 3</td><td>Test-Push</td><td>eigener Baustein NUR f&uuml;r den Test</td><td>&larr; Schwellwertschalter an PTEST (Ein 0,5/Aus 0,4)</td></tr>
</table>
<b>Praxis-Erfahrungen zum Benachrichtigungs-Baustein</b> (erspart lange Fehlersuche):<br>
&bull; Er sendet NUR bei einer 0&rarr;1-Flanke. NIEMALS mehrere Quellen direkt an den Eingang legen &mdash;
eine dauerhaft aktive Quelle verschluckt alle weiteren Ausl&ouml;ser. Immer erst im ODER-Baustein sammeln.<br>
&bull; F&uuml;r den Test (PTEST) einen EIGENEN Benachrichtigungs-Baustein verwenden.<br>
&bull; WARN=1 eignet sich f&uuml;r einen vierten Baustein &bdquo;AWM-Link erneuern!&ldquo; an den Admin.
</div>

<div class="aw-step"><b>Schritt 4: MQTT-Alternative + JSON</b><br>
Alle Werte gibt es auch &uuml;ber das LoxBerry MQTT Gateway (Reiter Einstellungen &rarr; MQTT) unter
<span class="aw-mono"><?= aw_e($aw_cfg['mqtt_topic']) ?>/...</span> &mdash; und als JSON f&uuml;r Drittsoftware:
<span class="aw-mono">http://<?= $aw_host ?>/plugins/<?= aw_e($aw_plugin) ?>/awm.php?json=1</span>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="aw-pane" id="tab-test">
<h2>Test</h2>
<p>
<a class="aw-btn" style="display:inline-block;margin-right:8px;" href="/plugins/<?= aw_e($aw_plugin) ?>/awm.php" target="_blank">Loxone-Zeile abrufen</a>
<a class="aw-btn" style="display:inline-block;margin-right:8px;" href="/plugins/<?= aw_e($aw_plugin) ?>/awm.php?debug=1" target="_blank">Debug (alle Termine)</a>
<a class="aw-btn" style="display:inline-block;margin-right:8px;background:#607d8b;" href="/plugins/<?= aw_e($aw_plugin) ?>/awm.php?refresh=1&amp;debug=1" target="_blank">Neu abrufen + Debug</a>
<a class="aw-btn" style="display:inline-block;margin-right:8px;background:#607d8b;" href="/plugins/<?= aw_e($aw_plugin) ?>/awm.php?json=1" target="_blank">JSON-Ansicht</a>
</p>
<p>
<a class="aw-btn" style="display:inline-block;margin-right:8px;background:#e65100;" href="/plugins/<?= aw_e($aw_plugin) ?>/awm.php?say=1" target="_blank">Test-Ansage jetzt</a>
<a class="aw-btn" style="display:inline-block;margin-right:8px;background:#e65100;" href="/plugins/<?= aw_e($aw_plugin) ?>/awm.php?ptest=1" target="_blank">Test-Pushnachricht</a>
<a class="aw-btn" style="display:inline-block;background:#455a64;" href="/plugins/<?= aw_e($aw_plugin) ?>/awm.php?renew=1" target="_blank">Jahres-Erneuerung testen</a>
<?php if (isset($aw_cals[2])) { ?>
<a class="aw-btn" style="display:inline-block;margin-left:8px;" href="/plugins/<?= aw_e($aw_plugin) ?>/awm.php?debug=1&amp;cal=2" target="_blank">Debug Kalender 2</a>
<?php } ?>
</p>
<div class="aw-small">
&bull; <b>Loxone-Zeile</b> zeigt genau das, was der Miniserver bekommt.<br>
&bull; <b>Test-Ansage</b> spricht sofort in den konfigurierten Zonen (Demo-Text, falls morgen nichts f&auml;llig ist).<br>
&bull; <b>Test-Pushnachricht</b> setzt <span class="aw-mono">PTEST=1</span> f&uuml;r 5 Minuten &mdash; der Push kommt &uuml;ber den
Test-Benachrichtigungsbaustein in Loxone (Schritt 3) innerhalb des 300-s-Abfragetakts.<br>
&bull; <b>Jahres-Erneuerung</b> versucht sofort, einen frischen AWM-Link f&uuml;rs Folgejahr zu beschaffen (Ergebnis im Protokoll).
</div>
</div>

<!-- ================= Reiter: Protokoll ================= -->
<div class="aw-pane" id="tab-log">
<h2>Protokoll</h2>
<div class="aw-small" style="margin-bottom:8px;">Protokolliert werden Kalender-Abrufe, Zustands&auml;nderungen, Ansagen, Jahres-Erneuerungen und Fehler. Neueste Eintr&auml;ge oben (max. 300 angezeigt).<br>Datei: <span class="aw-mono"><?= aw_e($aw_logfile) ?></span></div>
<?php if ($aw_loglines) { ?>
<div class="aw-log"><?= aw_e(implode("\n", $aw_loglines)) ?></div>
<?php } else { ?>
<div class="aw-alert aw-info">Noch keine Protokoll-Eintr&auml;ge vorhanden.</div>
<?php } ?>
<form method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="aw-btn" type="submit" style="background:#c62828;">Protokoll leeren</button>
</form>
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
    var tabs = document.querySelectorAll('.aw-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('aw-active', t.dataset.pane === id); });
        document.querySelectorAll('.aw-pane').forEach(function (p) { p.classList.toggle('aw-active', p.id === id); });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { activate(t.dataset.pane); }); });
    activate(<?= json_encode($aw_tab) ?>);
    awTtsMode();
})();
</script>
<?php
if ($aw_frame) {
    LBWeb::lbfooter();
}
