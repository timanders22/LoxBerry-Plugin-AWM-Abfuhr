<?php
/**
 * Abfuhrkalender AWM Muenchen - Admin-Oberflaeche (1.4.0)
 *
 * Fuenf Reiter nach Hausstandard:
 *   Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * Bis 1.3.8 gab es einen sechsten ("Tonnen"). Die Tonnenzuordnung ist eine
 * Konfiguration und steht deshalb jetzt in den Einstellungen - die
 * Reiterliste bleibt damit die des Hausstandards.
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-GLOBALS (u.a. $cfg aus general.json als
 * stdClass) und wuerde gleichnamige Plugin-Variablen ueberschreiben - daher
 * tragen hier ALLE Variablen ein aw_-Praefix.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
/* Fehler NICHT in die Seite schreiben, sondern ins Protokoll.
 *
 * Bis 1.4.6 stand hier display_errors=1. Auf PHP 8 ist der Zugriff auf einen
 * fehlenden Array-Schluessel eine WARNUNG (bis 7.4 war es ein HINWEIS und
 * damit von der Maske oben gedeckt) - gemessen am 05.09.2026: 7.4.33 stumm,
 * 8.4.24 "Warning: Undefined array key". Eine einzige solche Warnung vor
 * Zeile 197 oder 552 bringt header() zu Fall, und der Knopf "Einstellungen
 * sichern" liefert dann eine Seite mit angehaengtem JSON statt einer Datei -
 * genau der Schaden, den der Baublock weiter unten beschreibt. Ausserdem
 * standen Serverpfade im Browser. */
ini_set('display_errors', '0');
ini_set('log_errors', '1');

/* ---- Bibliothek finden: installiert im html-Zweig, im Archiv daneben ----
 *
 * Installiert liegen html/ und htmlauth/ in GETRENNTEN Baeumen. Welche Lage
 * gilt, entscheidet der eigene Ablageort, nicht die Reihenfolge der
 * Versuche: liegt diese Datei unter <Wurzel>/webfrontend/htmlauth/plugins/
 * <ordner>, ist sie installiert, sonst liegt sie in einem ausgepackten
 * Archiv. Bis 1.4.12 wurden zwei Kandidaten der Reihe nach probiert, der
 * installierte VOR der eigenen Bibliothek - aus einem Archiv unter /plugin
 * war das //html/plugins/htmlauth/awm_lib.php ab der Laufwerkswurzel, und
 * was dort lag, lief als Bibliothek (in WSL gemessen,
 * Pruefung-AWM-Abfuhr-1.4.13, Fall C6; Bauart Spotpreis-Octopus 1.1.12).
 *
 * Wurzel und Ordnername kommen danach aus awm_paths() - EINE Stelle fuer die
 * Wurzelregel (general.json, Archivmodus). Bis 1.4.12 stand hier eine eigene
 * Kopie der Wurzelsuche, die ohne general.json auskam.
 */
$aw_ordner = basename(__DIR__);
if (basename(dirname(__DIR__)) === 'plugins' && basename(dirname(dirname(__DIR__))) === 'htmlauth') {
    $aw_libcand = dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . $aw_ordner . '/awm_lib.php';
} else {
    $aw_libcand = dirname(__DIR__) . '/html/awm_lib.php';
}
if (is_file($aw_libcand)) {
    require_once $aw_libcand;
}
if (!function_exists('awm_config')) {
    echo '<p style="font-family:sans-serif;color:#b00">Die Programmbibliothek '
       . '<code>awm_lib.php</code> wurde nicht gefunden. Das Plugin ist unvollstaendig '
       . 'installiert.</p>';
    exit;
}
$aw_lbhome = awm_paths()['lbhome'];
$aw_plugin = awm_paths()['plugin'];
if ($aw_lbhome !== '') {
    $aw_sdk = $aw_lbhome . '/libs/phplib/loxberry_system.php';
    if (file_exists($aw_sdk)) {
        require_once $aw_sdk;
        require_once $aw_lbhome . '/libs/phplib/loxberry_web.php';
    }
}

$aw_p = awm_paths();
$aw_cfgdir = dirname($aw_p['config']);
$aw_cfgfile = $aw_p['config'];
$aw_bkfile = $aw_p['backup'];
$aw_logfile = $aw_p['log'];
/* Wohin die Fehler gehen, steht erst fest, seit die Pfade bekannt sind.
 * Eine eigene Datei neben dem Plugin-Protokoll: der Betreiber soll den
 * Unterschied sehen zwischen "das Plugin sagt etwas" und "PHP klagt". */
if (is_dir(dirname($aw_logfile))) {
    ini_set('error_log', dirname($aw_logfile) . '/php_fehler.log');
}

function aw_t($k) { return aw_e(awm_t($k)); }
function aw_d($ymd) { return strlen((string) $ymd) === 8 ? substr($ymd, 6, 2) . '.' . substr($ymd, 4, 2) . '.' . substr($ymd, 0, 4) : '-'; }

/* X-2 (Regeln/04): nach einer Beanstandung zeigt der GET einmal die
 * EINGETIPPTEN Werte des beanstandeten Formulars ($aw_ein aus der
 * Einmalmeldung) statt der gespeicherten, das beanstandete Feld markiert.
 * Tonnen und Termine nur fuer den Kalender, fuer den sie getippt wurden. */
function aw_ein_aktiv($form)
{
    $e = isset($GLOBALS['aw_ein']) ? $GLOBALS['aw_ein'] : null;
    if (!is_array($e) || $e['formular'] !== $form) { return false; }
    $cal = array('bins' => 'bins_cal', 'termine' => 'term_cal');
    if (isset($cal[$form])) {
        $w = isset($e['werte'][$cal[$form]]) ? $e['werte'][$cal[$form]] : '';
        return max(1, (int) $w) === (int) (isset($GLOBALS['aw_bcal']) ? $GLOBALS['aw_bcal'] : 1);
    }
    return true;
}
/** Eingetippter Wert, sonst der gespeicherte. */
function aw_ew($form, $name, $gespeichert)
{
    if (aw_ein_aktiv($form) && array_key_exists($name, $GLOBALS['aw_ein']['werte'])) {
        return $GLOBALS['aw_ein']['werte'][$name];
    }
    return $gespeichert;
}
/** Haken: eingetippt, sonst gespeichert. */
function aw_eh($form, $name, $gespeichert)
{
    if (aw_ein_aktiv($form) && array_key_exists($name, $GLOBALS['aw_ein']['werte'])) {
        return $GLOBALS['aw_ein']['werte'][$name] === '1';
    }
    return (bool) $gespeichert;
}
/** Merkmal fuer ein beanstandetes Feld (leer, wenn nicht beanstandet). */
function aw_em($form, $name)
{
    return (aw_ein_aktiv($form) && in_array($name, $GLOBALS['aw_ein']['falsch'], true))
        ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}
/** Ein beanstandetes Zahlenfeld wird als Textfeld gezeigt - ein type=number
 *  zeigt "abc" nicht an, und der Anwender saehe ein leeres Feld. */
function aw_et($form, $name)
{
    return aw_em($form, $name) !== '' ? 'text' : 'number';
}
/** Zahl der eingetippten Zeilen eines Listenfeldes. */
function aw_ein_zeilen($form, $name)
{
    $n = 0;
    if (!aw_ein_aktiv($form)) { return 0; }
    foreach (array_keys($GLOBALS['aw_ein']['werte']) as $k) {
        if (strpos($k, $name . '.') === 0) { $n = max($n, (int) substr($k, strlen($name) + 1) + 1); }
    }
    return $n;
}

/**
 * Formulartoken gegen fremde Absender.
 *
 * Diese Seite liegt zwar hinter der Anmeldung, aber ein Klick auf einer
 * fremden Seite kann im angemeldeten Browser trotzdem ein Formular
 * abschicken - und damit die Konfiguration ueberschreiben oder ein neues
 * Aktionstoken erzeugen, das alle Adressen in Loxone ungueltig macht.
 * Uebernommen aus dem Abfahrts-Assistenten.
 */
function aw_formtoken(array $cfg)
{
    return substr(hash('sha256', 'awm|' . (string) (isset($cfg['aktionstoken']) ? $cfg['aktionstoken'] : '')), 0, 32);
}
function aw_formtoken_ok(array $cfg)
{
    $soll = aw_formtoken($cfg);
    $ist = isset($_POST['formtoken']) ? (string) $_POST['formtoken'] : '';
    return $ist !== '' && hash_equals($soll, $ist);
}

/** Konfiguration schreiben - immer unteilbar, immer mit Sicherung. */
function aw_speichern($daten, &$fehler)
{
    $p = awm_paths();
    if (!is_dir(dirname($p['config']))) { @mkdir(dirname($p['config']), 0775, true); }
    /* C9: ohne Sperre wird nicht geschrieben. Bis 1.4.14 schrieb diese
     * Funktion auch dann, wenn awm_sperre() false lieferte. Die Seite haelt
     * die Sperre in der Regel schon (siehe unten); sie wird hier
     * wiedereintrittsfaehig noch einmal genommen. Bekam die Seite sie nicht,
     * wird nicht ein zweites Mal gewartet. */
    $sperre = awm_sperre('config', empty($GLOBALS['aw_sperre']) ? 0 : 10);
    if ($sperre === false) {
        $fehler[] = awm_t('MELD.GESPERRT');
        return false;
    }
    /* 0600: in der Datei stehen das Aktionstoken und die iCal-Adresse
     * mit Strasse und Hausnummer - das Plugin sagt es in
     * uninstall/uninstall selbst. Hausstandard seit 03.09.2026
     * (Regeln/05); dieselbe Zahl wie bei Robonect und MG iSmart. */
    $ok = awm_json_schreiben($p['config'], $daten, 0600, true);
    if ($ok) {
        @copy($p['config'], $p['backup']);
        @chmod($p['backup'], 0600);
    } else {
        $fehler[] = sprintf(awm_t('MELD.NICHT_GESPEICHERT'), $p['config']);
    }
    awm_sperre_frei($sperre);
    return $ok;
}

/**
 * Nach einem Speichern: die Folgen fuer MQTT ziehen (M2-M4) und die Abo-Datei
 * des Gateways nachfuehren (M8). Ein Praefix, dessen Raeumen der Broker nicht
 * bestaetigt hat, wird in der Konfiguration gemerkt ('_mqtt_frueher', nicht
 * Teil der Sicherung); die Deinstallation leert es noch einmal.
 */
function aw_mqtt_folgen($alt, $neu, &$hinweise, &$fehler)
{
    $f = awm_mqtt_folgen($alt, $neu);
    foreach ($f['meldungen'] as $m) { $hinweise[] = $m; }
    $jetzt = awm_config(true);
    $l = awm_mqtt_frueher_praefixe($jetzt);
    $basis = awm_mqtt_basis($jetzt);
    $l2 = array_values(array_diff(array_unique(array_merge($l, $f['merken'])), array($basis)));
    if ($l2 !== $l) {
        $jetzt['_mqtt_frueher'] = array_slice($l2, -8);
        aw_speichern($jetzt, $fehler);
    }
    awm_abo_datei($basis, true);
}

/** Zu welcher Kalendernummer gehoert der Platz (0-basiert) in der Liste? */
function aw_calnummer($cfg, $slot)
{
    $n = 0;
    foreach (array_values((array) $cfg['cals']) as $i => $c) {
        $c = (array) $c;
        if (trim((string) (isset($c['url']) ? $c['url'] : '')) === '' && empty($c['hochgeladen'])) {
            continue;
        }
        $n++;
        if ($i === (int) $slot) { return $n; }
    }
    return 0;
}

/* ==================================================================
 * Beanstandungen SAMMELN, nicht ueberschreiben.
 *
 * Bis 1.3.8 stand hier je Pruefung ein $aw_err = '...'. Bei zwei
 * fehlerhaften Zeilen sah der Anwender nur die letzte und korrigierte einen
 * Fehler nach dem anderen.
 * ================================================================== */
$aw_fehler = array();
$aw_hinweise = array();
$aw_saved = false;
/* X-2: welche Felder welches Formulars beanstandet wurden ('name' bzw.
 * 'name.N'); '_kalender' heisst "beanstandet, aber an keinem Feld". */
$aw_bean = array();
$aw_bean_form = '';
$aw_ein = null;

/* Die Reiterliste steht genau EINMAL - ausgeschrieben, damit die
 * Hausstandard-Pruefung sie als Literal findet. */
$aw_reiter = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');
$aw_tab = 'tab-settings';
if (isset($_POST['activetab']) && in_array((string) $_POST['activetab'], $aw_reiter, true)) {
    $aw_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && in_array('tab-' . (string) $_GET['form'], $aw_reiter, true)) {
    $aw_tab = 'tab-' . (string) $_GET['form'];
}

$aw_post = ($_SERVER['REQUEST_METHOD'] === 'POST');
/* C9: Lesen und Schreiben der Konfiguration unter EINER Sperre. Bis 1.4.14
 * las die Seite die Konfiguration VOR der Sperre, und die Sperre war nicht
 * blockierend: ein Speichern waehrend der Jahres-Erneuerung konnte den
 * neuen Link mit der alten Adresse aus dem Formular ueberschreiben. Die
 * Sperre wird vor dem ersten Lesen genommen (blockierend, bis 10 s) und nach
 * den Handlern wieder abgegeben; ohne sie schreibt aw_speichern() nicht. */
$aw_sperre = awm_sperre('config', 10);
/* U8: die Lage der Konfiguration VOR dem ersten Lesen merken - awm_config(true)
 * heilt sie sonst, und der Reiter Test saehe nie "aus der Zweitschrift". */
$aw_cfg_lage = awm_config_lage();
$aw_cfg_roh = awm_config(true);      // hier DARF angelegt werden - angemeldeter Bereich

/* Beim ersten Aufruf ein Token erzeugen, damit die Adressen fuer Loxone sofort
 * benutzbar sind (schuetzt die ausloesenden Aufrufe im unangemeldeten awm.php).
 *
 * U3/U2: still nur bei einer Neuinstallation (Konfiguration leer). Fehlt das
 * Token in einer bestehenden Konfiguration oder passt es nicht zum Muster, wird
 * ein neues erzeugt UND gesagt, dass die Adressen in Loxone neu zu uebernehmen
 * sind - bis 1.4.14 entstand es still, und jede eingetragene Adresse wurde
 * stumm ungueltig (Oberflaechen-Pruefer, Befund 3). */
if (!awm_token_gueltig(isset($aw_cfg_roh['aktionstoken']) ? $aw_cfg_roh['aktionstoken'] : null)) {
    $aw_tok_war = isset($aw_cfg_roh['aktionstoken']) ? $aw_cfg_roh['aktionstoken'] : '';
    $aw_cfg_roh['aktionstoken'] = awm_token_erzeugen();
    if (aw_speichern($aw_cfg_roh, $aw_fehler) && $aw_cfg_lage !== 'leer') {
        $aw_hinweise[] = awm_t($aw_tok_war === '' ? 'MELD.TOKEN_ERZEUGT' : 'MELD.TOKEN_UNGUELTIG');
        awm_log('Aktionstoken ' . ($aw_tok_war === '' ? 'fehlte' : 'passte nicht zum Muster')
              . ' - ein neues wurde erzeugt; die Adressen in Loxone muessen neu uebernommen werden.');
    }
}

if ($aw_post && !aw_formtoken_ok($aw_cfg_roh)) {
    $aw_fehler[] = awm_t('MELD.FORMTOKEN');
    $aw_post = false;
}

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon
 * geschrieben - "Cannot modify header information", und der Knopf
 * "Einstellungen sichern" lieferte eine Seite mit angehaengtem JSON
 * statt einer Datei.
 *
 * Am PHP-CLI ist das unsichtbar: header() ist dort wirkungslos und
 * headers_sent() immer falsch. Und wer OHNE gueltiges Formularmerkmal
 * misst, wird vom Wachposten abgewiesen, bevor der Handler anlaeuft.
 * Beides hat den Fehler lange verdeckt.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Wachposten, Reiterwahl,
 * ALLE Handler samt Downloads, dann erst lbheader(), dann HTML.
 * ================================================================== */
/* ---------- Loxone-Vorlagen herunterladen (Hausstandard) ---------- */
if ($aw_post && isset($_POST['vorlage'])) {
    $aw_vcal = max(1, min(9, (int) (isset($_POST['vorlage_cal']) ? $_POST['vorlage_cal'] : 1)));
    list($aw_vname, $aw_vinhalt) = ($_POST['vorlage'] === 'vo')
        ? awm_vorlage_vo($aw_vcal) : awm_vorlage($aw_vcal);
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $aw_vname . '"');
    echo $aw_vinhalt;
    exit;
}

/* ---------- MQTT speichern (eigener Reiter, Hausstandard) ----------
 * NICHT den save-Handler mitbenutzen: der setzt Haken per isset() und wuerde
 * beim Absenden des MQTT-Formulars die Einstellungs-Haken auf 0 stellen. */
if ($aw_post && isset($_POST['mqtt_save'])) {
    $aw_bean_form = 'mqtt';
    $aw_new = awm_config(true);
    $aw_vorher = $aw_new;
    $aw_new['mqtt_enabled'] = isset($_POST['mqtt_enabled']) ? 1 : 0;
    $aw_thema = trim((string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : ''));
    // Was nicht ins Muster passt, wird ABGEWIESEN, nicht zurechtgebogen.
    /* Nr. 19: auch ein LEERES Thema. Bis 1.4.16 wurde es still zu "awm" und
     * gespeichert - wer das Feld geleert hatte, sah danach "awm" und hielt es
     * fuer seinen Wert. */
    if ($aw_thema === '') {
        $aw_fehler[] = awm_t('MELD.THEMA_LEER');
        $aw_bean[] = 'mqtt_topic';
    } elseif (awm_wert_pruefen('mqtt_topic', $aw_thema) !== '') {
        $aw_fehler[] = awm_t('MELD.THEMA_UNGUELTIG');
        $aw_bean[] = 'mqtt_topic';
    } else {
        $aw_new['mqtt_topic'] = $aw_thema;
        if (aw_speichern($aw_new, $aw_fehler)) {
            $aw_saved = true;
            aw_mqtt_folgen($aw_vorher, $aw_new, $aw_hinweise, $aw_fehler);
        }
    }
    $aw_tab = 'tab-mqtt';
}

/* ---------- Neues Aktionstoken erzeugen ---------- */
if ($aw_post && isset($_POST['token_neu'])) {
    $aw_new = awm_config(true);
    $aw_new['aktionstoken'] = awm_token_erzeugen();
    if (aw_speichern($aw_new, $aw_fehler)) {
        $aw_hinweise[] = awm_t('MELD.TOKEN_NEU');
    }
    $aw_tab = 'tab-loxone';
}

/* ---------- Protokoll leeren ---------- */
if ($aw_post && isset($_POST['clearlog'])) {
    if (!is_dir(dirname($aw_logfile))) { @mkdir(dirname($aw_logfile), 0775, true); }
    @file_put_contents($aw_logfile, '[' . date('Y-m-d H:i:s') . '] ' . awm_t('MELD.LOG_GELEERT') . "\n");
    $aw_hinweise[] = awm_t('MELD.LOG_GELEERT');     // U1: die Einmalmeldung sagt es
    $aw_tab = 'tab-log';
}

/* ---------- Kalenderdatei hochladen ----------
 * Nicht jeder Entsorger bietet eine dauerhafte Adresse; manche geben nur
 * einen Download hinter einem Formular. Das Plugin speichert den Kalender
 * ohnehin dauerhaft - es fehlte nur der Weg hinein. */
if ($aw_post && isset($_POST['upload'])) {
    $aw_slot = max(0, min(AWM_MAX_KALENDER - 1, (int) (isset($_POST['up_slot']) ? $_POST['up_slot'] : 0)));
    /* is_uploaded_file() ZUERST - dieselbe Wache wie beim
     * Zurueckspielen einer Sicherung. Ohne sie liesse sich jede Datei
     * des Servers unterschieben. */
    if (!isset($_FILES['icsdatei']) || !isset($_FILES['icsdatei']['tmp_name'])
            || !@is_uploaded_file($_FILES['icsdatei']['tmp_name'])
            || $_FILES['icsdatei']['error'] !== UPLOAD_ERR_OK) {
        $aw_fehler[] = awm_t('MELD.UPLOAD_FEHLT');
    } elseif ($_FILES['icsdatei']['size'] > 4 * 1024 * 1024) {
        $aw_fehler[] = awm_t('MELD.UPLOAD_GROSS');
    } else {
        $aw_inhalt = (string) @file_get_contents($_FILES['icsdatei']['tmp_name']);
        $aw_inhalt = awm_utf8(awm_ics_entfalten($aw_inhalt));   // C1: erst entfalten
        $aw_grund = awm_ics_pruefen($aw_inhalt);
        if ($aw_grund !== '') {
            $aw_fehler[] = $aw_grund;
        } else {
            $aw_new = awm_config(true);
            $aw_liste = array_values((array) $aw_new['cals']);
            while (count($aw_liste) <= $aw_slot) { $aw_liste[] = array('name' => '', 'url' => ''); }
            $aw_liste[$aw_slot]['hochgeladen'] = 1;
            if (trim((string) (isset($aw_liste[$aw_slot]['name']) ? $aw_liste[$aw_slot]['name'] : '')) === '') {
                $aw_liste[$aw_slot]['name'] = sprintf(awm_t_oder('EINST.KALENDER_N', 'Kalender %d'), $aw_slot + 1);   // U10
            }
            $aw_new['cals'] = $aw_liste;
            if (aw_speichern($aw_new, $aw_fehler)) {
                $aw_nr = aw_calnummer($aw_new, $aw_slot);
                if ($aw_nr > 0 && awm_datei_schreiben(awm_icsfile($aw_nr), $aw_inhalt)) {
                    @unlink(awm_tmpdir() . '/state_' . $aw_nr . '.json');
                    awm_state(true, $aw_nr);
                    $aw_hinweise[] = sprintf(awm_t('MELD.UPLOAD_OK'),
                        substr_count($aw_inhalt, 'BEGIN:VEVENT'), $aw_nr);
                    awm_log('Kalender ' . $aw_nr . ' aus hochgeladener Datei uebernommen: '
                          . strlen($aw_inhalt) . ' Bytes');
                } else {
                    $aw_fehler[] = awm_t('MELD.UPLOAD_SCHREIB');
                }
            }
        }
    }
    $aw_tab = 'tab-settings';
}

/* ---------- Tonnenzuordnung speichern ---------- */
if ($aw_post && isset($_POST['save_bins'])) {
    $aw_bean_form = 'bins';
    $aw_new = awm_config(true);
    $aw_liste = array_values((array) $aw_new['cals']);
    $aw_cn = max(1, (int) (isset($_POST['bins_cal']) ? $_POST['bins_cal'] : 1));
    // Von der Kalendernummer zurueck auf den Platz in der Liste - EINE
    // Funktion in der Bibliothek (C2), dieselbe wie beim Lesen der Regeln.
    $aw_slot = awm_cal_platz($aw_liste, $aw_cn);
    $aw_arten = awm_tonnenarten();
    $aw_titel = isset($_POST['bin_titel']) ? (array) $_POST['bin_titel'] : array();
    $aw_tonne = isset($_POST['bin_tonne']) ? (array) $_POST['bin_tonne'] : array();
    $aw_art = isset($_POST['bin_art']) ? (array) $_POST['bin_art'] : array();
    $aw_regeln_neu = array();
    foreach ($aw_titel as $aw_k => $aw_tt) {
        $aw_tt = trim((string) $aw_tt);      // nur Rand-Leerraum faellt still weg (Nr. 19)
        $aw_zz = (string) (isset($aw_tonne[$aw_k]) ? $aw_tonne[$aw_k] : '');
        if ($aw_zz === '') {
            continue;                    // "keine Zuordnung" (Auswahl im Formular)
        }
        /* Nr. 19: eine HALBE Zeile - Tonne gewaehlt, aber kein Titel - wurde
         * bis 1.4.16 still verworfen und "gespeichert" gemeldet. */
        if ($aw_tt === '') {
            $aw_fehler[] = sprintf(awm_t('MELD.ZEILE_OHNE_TITEL'), $aw_k + 1);
            $aw_bean[] = 'bin_tonne.' . $aw_k;
            continue;
        }
        /* AWM-n19b (Nr. 19): Anfuehrungs- und Steuerzeichen im Titel wurden bis
         * 1.4.17 still entfernt - die gespeicherte Regel traf den Titel danach
         * nicht mehr, gemeldet war "gespeichert". Entscheidung 24: das gerade
         * Anfuehrungszeichen bleibt jetzt stehen (die Regel trifft den Titel
         * damit), nur ein Steuerzeichen ist eine Beanstandung - dieselbe
         * Pruefung wie beim Zurueckspielen. Der Titel kommt aus der
         * Kalenderdatei (verstecktes Feld); markiert wird die Auswahl der Zeile. */
        if (!awm_text_zeichen_ok($aw_tt, false, false)) {
            $aw_fehler[] = sprintf(awm_t('MELD.TITEL_ZEICHEN'), $aw_k + 1);
            $aw_bean[] = 'bin_tonne.' . $aw_k;
            continue;
        }
        if (!isset($aw_arten[$aw_zz])) {
            $aw_fehler[] = sprintf(awm_t('MELD.TONNE_UNBEKANNT'), $aw_zz);
            $aw_bean[] = 'bin_tonne.' . $aw_k;
            continue;
        }
        $aw_aa = (string) (isset($aw_art[$aw_k]) ? $aw_art[$aw_k] : 'enthaelt');
        if (!in_array($aw_aa, array('enthaelt', 'beginnt', 'genau'), true)) {
            $aw_fehler[] = sprintf(awm_t('MELD.ART_UNBEKANNT'), $aw_tt);
            $aw_bean[] = 'bin_art.' . $aw_k;
            continue;
        }
        $aw_regeln_neu[] = array('muster' => $aw_tt, 'tonne' => $aw_zz, 'art' => $aw_aa);
    }
    /* Nr. 19: ein unbekannter oder fehlender Modus wurde bis 1.4.16 still zu
     * "ersetzen" - und damit verlor die eingebaute Erkennung Tonnen, ohne dass
     * es jemand gewaehlt hatte. */
    $aw_mod = (isset($_POST['bins_modus']) && is_string($_POST['bins_modus'])) ? $_POST['bins_modus'] : '';
    if (!in_array($aw_mod, array('ersetzen', 'ergaenzen'), true)) {
        $aw_fehler[] = sprintf(awm_t('MELD.REGELMODUS'), $aw_mod);
        $aw_bean[] = 'bins_modus';
    }
    if ($aw_slot < 0) {
        $aw_fehler[] = awm_t('MELD.KALENDER_FEHLT');
        $aw_bean[] = '_kalender';
    } elseif (!$aw_fehler) {
        $aw_liste[$aw_slot]['regeln'] = $aw_regeln_neu;
        $aw_liste[$aw_slot]['regeln_modus'] = $aw_mod;
        $aw_new['cals'] = $aw_liste;
        if (aw_speichern($aw_new, $aw_fehler)) {
            awm_state(true, $aw_cn);
            $aw_hinweise[] = sprintf(awm_t('MELD.ZUORDNUNG_OK'), count($aw_regeln_neu));
        }
    }
    $aw_tab = 'tab-settings';
}

/* ---------- Eigene Termine speichern ---------- */
if ($aw_post && isset($_POST['save_termine'])) {
    $aw_bean_form = 'termine';
    $aw_new = awm_config(true);
    $aw_liste = array_values((array) $aw_new['cals']);
    $aw_cn = max(1, (int) (isset($_POST['term_cal']) ? $_POST['term_cal'] : 1));
    $aw_slot = awm_cal_platz($aw_liste, $aw_cn);         // C2
    $aw_arten = awm_tonnenarten();
    $aw_td = isset($_POST['term_datum']) ? (array) $_POST['term_datum'] : array();
    $aw_tb = isset($_POST['term_tonne']) ? (array) $_POST['term_tonne'] : array();
    $aw_tx = isset($_POST['term_text']) ? (array) $_POST['term_text'] : array();
    $aw_neu = array();
    foreach ($aw_td as $aw_k => $aw_dd) {
        $aw_dd = trim((string) $aw_dd);
        $aw_bb = (string) (isset($aw_tb[$aw_k]) ? $aw_tb[$aw_k] : '');
        if ($aw_dd === '' && $aw_bb === '') {
            /* Nr. 19: eine Zeile mit Text, aber ohne Datum und Tonne wurde bis
             * 1.4.16 still verworfen. Ganz leere Zeilen bleiben leer. */
            if (trim((string) (isset($aw_tx[$aw_k]) ? $aw_tx[$aw_k] : '')) !== '') {
                $aw_fehler[] = sprintf(awm_t('MELD.TERMIN_HALB'), $aw_k + 1);
                $aw_bean[] = 'term_datum.' . $aw_k;
            }
            continue;
        }
        /* AWM-n19b (Nr. 19): Anfuehrungszeichen, Semikolon und Steuerzeichen im
         * Text wurden bis 1.4.17 still entfernt und das Uebrige gespeichert.
         * Jetzt eine Beanstandung (dieselbe Pruefung wie beim Zurueckspielen);
         * nur Leerraum am Rand faellt still weg. */
        $aw_txr = isset($aw_tx[$aw_k]) ? $aw_tx[$aw_k] : '';
        $aw_txt = is_string($aw_txr) ? trim($aw_txr) : '';
        if (!is_string($aw_txr) || !awm_text_zeichen_ok($aw_txt, true)) {
            $aw_fehler[] = sprintf(awm_t('MELD.TERMIN_ZEICHEN'), $aw_k + 1);
            $aw_bean[] = 'term_text.' . $aw_k;
        }
        // Erwartet TT.MM.JJJJ oder JJJJ-MM-TT. Was nicht passt, wird
        // abgewiesen und gemeldet - nicht zurechtgebogen.
        $aw_ymd = '';
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $aw_dd, $m)) { $aw_ymd = $m[3] . $m[2] . $m[1]; }
        elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $aw_dd, $m)) { $aw_ymd = $m[1] . $m[2] . $m[3]; }
        if ($aw_ymd === '' || awm_tagnummer($aw_ymd) < 0) {
            $aw_fehler[] = sprintf(awm_t('MELD.DATUM_UNGUELTIG'), $aw_dd);
            $aw_bean[] = 'term_datum.' . $aw_k;
            continue;
        }
        if (!isset($aw_arten[$aw_bb])) {
            $aw_fehler[] = sprintf(awm_t('MELD.TONNE_FEHLT'), $aw_dd);
            $aw_bean[] = 'term_tonne.' . $aw_k;
            continue;
        }
        $aw_neu[] = array('datum' => $aw_ymd, 'tonne' => $aw_bb, 'text' => $aw_txt);
    }
    if ($aw_slot < 0) {
        $aw_fehler[] = awm_t('MELD.KALENDER_FEHLT');
        $aw_bean[] = '_kalender';
    } elseif (!$aw_fehler) {
        $aw_liste[$aw_slot]['termine'] = $aw_neu;
        $aw_new['cals'] = $aw_liste;
        if (aw_speichern($aw_new, $aw_fehler)) {
            awm_state(true, $aw_cn);
            $aw_hinweise[] = sprintf(awm_t('MELD.TERMINE_OK'), count($aw_neu));
        }
    }
    $aw_tab = 'tab-settings';
}

/* ---------- Jetzt abrufen ---------- */
if ($aw_post && isset($_POST['fetchnow'])) {
    $aw_msgs = array();
    foreach (awm_cals() as $aw_n => $aw_c) {
        list($aw_fok, $aw_fq) = awm_fetch(true, $aw_n);
        awm_state(true, $aw_n);
        if ($aw_fq === 'gebremst') {         // C5: ausdruecklich sagen
            $aw_msgs[] = $aw_c['name'] . ': ' . sprintf(awm_t('MELD.ABRUF_GEBREMST'),
                AWM_SOFORT_ABSTAND, awm_sofortabruf_warte($aw_n));
            continue;
        }
        $aw_msgs[] = $aw_c['name'] . ': ' . ($aw_fok ? $aw_fq : awm_t('MELD.FEHLGESCHLAGEN'));
    }
    if ($aw_msgs) {
        $aw_hinweise[] = awm_t('MELD.ABRUF') . ' ' . implode(' / ', $aw_msgs);
    } else {
        $aw_fehler[] = awm_t('MELD.KEINE_URL');
    }
    $aw_tab = 'tab-settings';
}

/* ---------- Speichern (Haupt-Formular) ----------
 *
 * $aw_new entsteht aus der BESTEHENDEN Konfiguration und wird nur dort
 * ueberschrieben, wo das Formular etwas mitschickt. Bis 1.3.8 wurde es von
 * Grund auf neu gebaut - und musste dann Feld fuer Feld daran erinnert
 * werden, Tonnenzuordnung, MQTT-Werte und Aktionstoken zu retten. Genau so
 * gingen am 13.08.2026 in drei anderen Linien die Token verloren.
 */
if ($aw_post && isset($_POST['save'])) {
    $aw_bean_form = 'save';
    $aw_new = awm_config(true);
    $aw_vorher = $aw_new;
    $aw_alt_cals = array_values((array) $aw_new['cals']);
    $aw_names = isset($_POST['cal_name']) ? (array) $_POST['cal_name'] : array();
    $aw_urls = isset($_POST['cal_url']) ? (array) $_POST['cal_url'] : array();
    $aw_ans = isset($_POST['cal_ansage']) ? (array) $_POST['cal_ansage'] : array();
    $aw_zon = isset($_POST['cal_zonen']) ? (array) $_POST['cal_zonen'] : array();
    $aw_liste = array();
    for ($aw_i = 0; $aw_i < AWM_MAX_KALENDER; $aw_i++) {
        $aw_u = trim((string) (isset($aw_urls[$aw_i]) ? $aw_urls[$aw_i] : ''));
        $aw_alt = isset($aw_alt_cals[$aw_i]) ? (array) $aw_alt_cals[$aw_i] : array();
        $aw_hoch = !empty($aw_alt['hochgeladen']);
        if ($aw_u !== '' && !preg_match('#^https?://#i', $aw_u)) {
            $aw_fehler[] = sprintf(awm_t('MELD.URL_UNGUELTIG'), $aw_i + 1);
            $aw_bean[] = 'cal_url.' . $aw_i;
            $aw_u = isset($aw_alt['url']) ? (string) $aw_alt['url'] : '';   // alte behalten
        }
        if ($aw_u === '' && !$aw_hoch) {
            /* Nur die Adresse leeren - alles andere bleibt.
             * Bis 1.4.6 wurde hier $aw_alt weggeworfen, und mit ihm die
             * Zuordnungsregeln und die eigenen Termine dieses Kalenders.
             * Sie standen danach in keiner Datei mehr. */
            $aw_eintrag = $aw_alt;
            $aw_eintrag['name'] = trim((string) (isset($aw_names[$aw_i]) ? $aw_names[$aw_i] : ''));
            $aw_eintrag['url'] = '';
            $aw_liste[] = $aw_eintrag;
            continue;
        }
        // Alles, was das Formular NICHT mitschickt, wird uebernommen.
        $aw_eintrag = $aw_alt;
        $aw_eintrag['name'] = trim((string) (isset($aw_names[$aw_i]) ? $aw_names[$aw_i] : ''));
        $aw_eintrag['url'] = $aw_u;
        $aw_eintrag['ansage'] = !empty($aw_ans[$aw_i]) ? 1 : 0;
        /* U5: eine unzulaessige Zonenangabe wird beanstandet und der alte Wert
         * behalten - bis 1.4.14 wurde "2,a4" still zu "2,4". Geprueft mit
         * derselben Funktion wie beim Zurueckspielen (U4). */
        $aw_zz = (string) (isset($aw_zon[$aw_i]) ? $aw_zon[$aw_i] : '');
        if (awm_ist_zonen($aw_zz)) {
            $aw_eintrag['zonen'] = trim($aw_zz);
        } else {
            $aw_fehler[] = sprintf(awm_t('MELD.ZONEN'), $aw_zz);
            $aw_bean[] = 'cal_zonen.' . $aw_i;
            $aw_eintrag['zonen'] = isset($aw_alt['zonen']) && is_string($aw_alt['zonen']) ? $aw_alt['zonen'] : '';
        }
        $aw_liste[] = $aw_eintrag;
    }
    $aw_new['cals'] = $aw_liste;
    /* Abweisen und melden, nicht stillschweigend zurechtbiegen: wer 200
     * Tage Vorschau eintraegt, bekam bis 1.4.6 wortlos 90 und hielt sie
     * fuer seinen Wert (Regeln/05). Der bisherige Wert bleibt stehen. */
    $aw_fd = isset($_POST['fetch_days']) ? $_POST['fetch_days'] : 14;
    if (awm_ist_zahl($aw_fd, 1, 60)) {
        $aw_new['fetch_days'] = (int) $aw_fd;
    } else {
        $aw_fehler[] = sprintf(awm_t('MELD.ZAHL_BEREICH'), awm_t('EINST.L_INTERVALL'), 1, 60, $aw_fd);
        $aw_bean[] = 'fetch_days';
    }
    $aw_la = isset($_POST['lookahead']) ? $_POST['lookahead'] : 35;
    if (awm_ist_zahl($aw_la, 7, 90)) {
        $aw_new['lookahead'] = (int) $aw_la;
    } else {
        $aw_fehler[] = sprintf(awm_t('MELD.ZAHL_BEREICH'), awm_t('EINST.L_VORSCHAU'), 7, 90, $aw_la);
        $aw_bean[] = 'lookahead';
    }
    $aw_new['autorenew'] = isset($_POST['autorenew']) ? 1 : 0;
    $aw_new['melden'] = isset($_POST['melden']) ? 1 : 0;
    $aw_hwr = isset($_POST['hinweis_woerter']) ? $_POST['hinweis_woerter'] : '';
    $aw_hw = is_string($aw_hwr) ? trim($aw_hwr) : '';
    /* Nr. 19: eine LEERE Liste wurde bis 1.4.16 still zur Standardliste. Jetzt
     * eine Beanstandung - dieselbe Pruefung wie beim Zurueckspielen.
     * AWM-n19b: Anfuehrungs- und Steuerzeichen wurden bis 1.4.17 still
     * entfernt; jetzt ebenso eine Beanstandung (awm_text_zeichen_ok). */
    if (!is_string($aw_hwr) || !awm_text_zeichen_ok($aw_hw)) {
        $aw_fehler[] = sprintf(awm_t('MELD.TEXT_ZEICHEN'), awm_t('EINST.L_STICHWORTE'));
        $aw_bean[] = 'hinweis_woerter';
    } elseif (awm_wert_pruefen('hinweis_woerter', $aw_hw) !== '') {
        $aw_fehler[] = awm_t('MELD.STICHWORTE_LEER');
        $aw_bean[] = 'hinweis_woerter';
    } else {
        $aw_new['hinweis_woerter'] = $aw_hw;
    }
    $aw_zeit = (string) (isset($_POST['notify_time']) ? $_POST['notify_time'] : '');
    $aw_zeit2 = (string) (isset($_POST['notify_time2']) ? $_POST['notify_time2'] : '');
    /* U4: dieselbe Pruefung wie beim Zurueckspielen (awm_ist_zeit: 00:00 bis
     * 23:59, ohne Rand). Bis 1.4.14 nahm das Formular "25:99" an, und die
     * EIGENE Sicherung liess sich danach nicht zurueckspielen. U5: ein
     * ungueltiger Wert behaelt den alten und wird gemeldet. */
    if (!awm_ist_zeit($aw_zeit)) {
        $aw_fehler[] = sprintf(awm_t('MELD.UHRZEIT'), $aw_zeit);
        $aw_bean[] = 'notify_time';
        $aw_zeit = (string) $aw_new['notify']['time'];
    }
    if (!awm_ist_zeit($aw_zeit2)) {
        $aw_fehler[] = sprintf(awm_t('MELD.UHRZEIT'), $aw_zeit2);
        $aw_bean[] = 'notify_time2';
        $aw_zeit2 = (string) $aw_new['notify']['time2'];
    }
    $aw_new['notify'] = array(
        'audio' => isset($_POST['notify_audio']) ? 1 : 0,
        'push' => isset($_POST['notify_push']) ? 1 : 0,
        'time' => $aw_zeit,
        'audio2' => isset($_POST['notify_audio2']) ? 1 : 0,
        'time2' => $aw_zeit2,
    );
    $aw_bd = trim((string) (isset($_POST['ruhe_bis']) ? $_POST['ruhe_bis'] : ''));
    $aw_bd_ymd = '';
    if ($aw_bd !== '') {
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $aw_bd, $m)) { $aw_bd_ymd = $m[3] . $m[2] . $m[1]; }
        elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $aw_bd, $m)) { $aw_bd_ymd = $m[1] . $m[2] . $m[3]; }
        if ($aw_bd_ymd === '' || awm_tagnummer($aw_bd_ymd) < 0) {
            $aw_fehler[] = sprintf(awm_t('MELD.DATUM_UNGUELTIG'), $aw_bd);
            $aw_bean[] = 'ruhe_bis';
            $aw_bd_ymd = (string) $aw_new['ruhe']['bis_datum'];
        }
    }
    /* U4/U5: die Ruhezeit wie die Uhrzeiten oben. Bis 1.4.14 wurde "abc" still
     * zur Werksvorgabe 22:00 (nicht einmal zum alten Wert), "7:5" still zu
     * 07:00 - ohne Meldung. */
    $aw_rv = (string) (isset($_POST['ruhe_von']) ? $_POST['ruhe_von'] : '');
    $aw_rb = (string) (isset($_POST['ruhe_bis_zeit']) ? $_POST['ruhe_bis_zeit'] : '');
    if (!awm_ist_zeit($aw_rv)) {
        $aw_fehler[] = sprintf(awm_t('MELD.UHRZEIT'), $aw_rv);
        $aw_bean[] = 'ruhe_von';
        $aw_rv = (string) $aw_new['ruhe']['von'];
    }
    if (!awm_ist_zeit($aw_rb)) {
        $aw_fehler[] = sprintf(awm_t('MELD.UHRZEIT'), $aw_rb);
        $aw_bean[] = 'ruhe_bis_zeit';
        $aw_rb = (string) $aw_new['ruhe']['bis'];
    }
    $aw_new['ruhe'] = array(
        'urlaub' => isset($_POST['ruhe_urlaub']) ? 1 : 0,
        'nachts' => isset($_POST['ruhe_nachts']) ? 1 : 0,
        'von' => $aw_rv,
        'bis' => $aw_rb,
        'bis_datum' => $aw_bd_ymd,
    );
    $aw_mode = (string) (isset($_POST['tts_mode']) ? $_POST['tts_mode'] : 'musicserver');
    $aw_port = isset($_POST['tts_port']) ? $_POST['tts_port'] : 7091;
    if (awm_ist_zahl($aw_port, 1, 65535)) { $aw_port = (int) $aw_port; }
    else {
        $aw_fehler[] = sprintf(awm_t('MELD.ZAHL_BEREICH'), awm_t('EINST.L_PORT'), 1, 65535, $aw_port);
        $aw_bean[] = 'tts_port';
        $aw_port = (int) $aw_new['tts']['port'];
    }
    $aw_vol = isset($_POST['tts_volume']) ? $_POST['tts_volume'] : 8;
    if (awm_ist_zahl($aw_vol, 1, 100)) { $aw_vol = (int) $aw_vol; }
    else {
        $aw_fehler[] = sprintf(awm_t('MELD.ZAHL_BEREICH'), awm_t('EINST.L_LAUT'), 1, 100, $aw_vol);
        $aw_bean[] = 'tts_volume';
        $aw_vol = (int) $aw_new['tts']['volume'];
    }
    /* U4/U5: Ansageart, Adresse, Zonen und Sprache mit denselben Pruefern wie
     * beim Zurueckspielen; Unzulaessiges wird gemeldet, der alte Wert bleibt.
     * Bis 1.4.14: "DE1" still zu "de", "192.0.2.4/x?y" ungeprueft als
     * Adresse, "Kueche" als Zone - die eigene Sicherung wurde danach
     * abgewiesen. */
    if (!in_array($aw_mode, array('musicserver', 'ms4h', 'audioserver', 'custom', 'alexang'), true)) {
        $aw_fehler[] = sprintf(awm_t('MELD.MODUS'), $aw_mode);
        $aw_bean[] = 'tts_mode';
        $aw_mode = (string) $aw_new['tts']['mode'];
    }
    $aw_ip = trim((string) (isset($_POST['tts_ip']) ? $_POST['tts_ip'] : ''));
    if (!awm_ist_host($aw_ip)) {
        $aw_fehler[] = sprintf(awm_t('MELD.HOST'), $aw_ip);
        $aw_bean[] = 'tts_ip';
        $aw_ip = (string) $aw_new['tts']['ip'];
    }
    $aw_zo = (string) (isset($_POST['tts_zones']) ? $_POST['tts_zones'] : '');
    if (!awm_ist_zonen($aw_zo)) {
        $aw_fehler[] = sprintf(awm_t('MELD.ZONEN'), $aw_zo);
        $aw_bean[] = 'tts_zones';
        $aw_zo = (string) $aw_new['tts']['zones'];
    }
    $aw_la2 = (string) (isset($_POST['tts_lang']) ? $_POST['tts_lang'] : '');
    if (!awm_ist_sprache($aw_la2)) {
        $aw_fehler[] = sprintf(awm_t('MELD.SPRACHE'), $aw_la2);
        $aw_bean[] = 'tts_lang';
        $aw_la2 = (string) $aw_new['tts']['lang'];
    }
    /* Ansage-2: Alexa-NG. Abgewiesen wird benannt, nicht zurechtgebogen; ein
     * Feld, das keine Zeichenkette ist (name[]), ist eine Beanstandung. Das
     * Sprechtoken reist nie ins Formular zurueck: leer lassen behaelt es, der
     * Haken loescht es. */
    $aw_al_roh = function ($feld) {
        if (!isset($_POST[$feld])) { return ''; }
        return is_string($_POST[$feld]) ? trim($_POST[$feld]) : false;
    };
    $aw_ag = $aw_al_roh('alexa_geraet');
    if ($aw_ag === false || !awm_alexa_geraet_ok($aw_ag)) {
        $aw_fehler[] = awm_t('MELD.ALEXA_GERAET');
        $aw_bean[] = 'alexa_geraet';
        $aw_ag = (string) $aw_new['tts']['alexa_geraet'];
    }
    $aw_al = $aw_al_roh('alexa_laut');
    if ($aw_al === '') {
        $aw_al = -1;
    } elseif ($aw_al !== false && awm_ist_zahl($aw_al, 0, 100)) {
        $aw_al = (int) $aw_al;
    } else {
        $aw_fehler[] = sprintf(awm_t('MELD.ZAHL_BEREICH'), awm_t('EINST.L_ALEXA_LAUT'), 0, 100,
                               $aw_al === false ? '' : $aw_al);
        $aw_bean[] = 'alexa_laut';
        $aw_al = (int) $aw_new['tts']['alexa_laut'];
    }
    $aw_at = (string) $aw_new['tts']['alexa_token'];
    if (isset($_POST['alexa_token_loeschen'])) {
        $aw_at = '';
    } else {
        $aw_atw = $aw_al_roh('alexa_token');
        if ($aw_atw === false || ($aw_atw !== '' && !awm_alexa_token_ok($aw_atw))) {
            $aw_fehler[] = awm_t('MELD.ALEXA_TOKEN');
            $aw_bean[] = 'alexa_token';
        } elseif ($aw_atw !== '') {
            $aw_at = $aw_atw;
        }
    }
    /* AWM-a2n (Nr. 19): bis 1.4.17 nur ein Hinweis, und gespeichert wurde
     * trotzdem - die Ansage entfiel danach still. Jetzt eine Beanstandung wie
     * im Abfahrts-Assistenten 1.6.19 und in Octopus 1.1.18. */
    if ($aw_mode === 'alexang' && !awm_alexa_token_ok($aw_at) && !in_array('alexa_token', $aw_bean, true)) {
        $aw_fehler[] = awm_t('MELD.ALEXA_OHNE_TOKEN');
        $aw_bean[] = 'alexa_token';
    }
    $aw_new['tts'] = array(
        'mode' => $aw_mode,
        'ip' => $aw_ip,
        'port' => $aw_port,
        'zones' => $aw_zo,
        'volume' => $aw_vol,
        'lang' => $aw_la2,
        'template' => trim((string) (isset($_POST['tts_template']) ? $_POST['tts_template'] : '')),
        'alexa_geraet' => $aw_ag,
        'alexa_token' => $aw_at,
        'alexa_laut' => $aw_al,
    );
    /* AWM-n19b (Nr. 19): Anfuehrungs- und Steuerzeichen in den Ansagetexten
     * wurden bis 1.4.17 still entfernt. Jetzt eine Beanstandung (dieselbe
     * Pruefung wie beim Zurueckspielen); der Rand-Leerraum faellt still weg. */
    $aw_ansage_neu = array();
    foreach (array('vorlage' => 'ansage_vorlage', 'vorlage2' => 'ansage_vorlage2') as $aw_ak => $aw_af) {
        $aw_av = $aw_al_roh($aw_af);
        if ($aw_av === false || !awm_text_zeichen_ok($aw_av)) {
            $aw_fehler[] = sprintf(awm_t('MELD.TEXT_ZEICHEN'),
                                   awm_t($aw_ak === 'vorlage' ? 'EINST.L_ANSAGETEXT' : 'EINST.L_ANSAGETEXT2'));
            $aw_bean[] = $aw_af;
            $aw_av = (string) $aw_new['ansage'][$aw_ak];
        }
        $aw_ansage_neu[$aw_ak] = $aw_av;
    }
    $aw_new['ansage'] = $aw_ansage_neu;
    /* Entscheidung 16 (30.09.2026, Regeln/04): bei einer Beanstandung wird
     * NICHTS gespeichert, auch nicht die uebrigen richtigen Felder. Bis 1.4.16
     * wurde "trotzdem gespeichert, damit nicht alles Uebrige verlorengeht" -
     * dieser Grund ist mit X-2 entfallen: alle Eingaben stehen nach der
     * Umleitung wieder im Formular, das beanstandete Feld markiert. */
    if ($aw_bean) {
        // nichts schreiben; die Meldungen stehen oben auf der Seite
    } elseif (aw_speichern($aw_new, $aw_fehler)) {
        $aw_saved = true;
        foreach (array_keys(awm_cals()) as $aw_n) { awm_state(true, $aw_n); }
        aw_mqtt_folgen($aw_vorher, $aw_new, $aw_hinweise, $aw_fehler);    // M4: entfernte Kalender
    }
    $aw_tab = 'tab-settings';
}

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das. */
if ($aw_post && isset($_POST['awm_sichern'])) {
    /* EINE Stelle baut die Datei - samt lesbarem Kopf und ohne
     * Altschluessel, die das Zurueckspielen sonst ablehnt. */
    $awm_js = awm_sicherung_bauen();
    if ($awm_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="awm_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $awm_js;
        exit;
    }
    $aw_fehler[] = awm_t('EINST.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei
 * des Servers unterschieben. Dann die Groessengrenze - eine Sicherung
 * dieses Plugins ist wenige Kilobyte gross; alles darueber wird gar
 * nicht erst gelesen. */
if ($aw_post && isset($_POST['awm_zurueck'])) {
    if (!isset($_FILES['awm_sicherung']) || !is_array($_FILES['awm_sicherung'])
        || !isset($_FILES['awm_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['awm_sicherung']['tmp_name'])) {
        $aw_fehler[] = awm_t('EINST.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['awm_sicherung']['size'] > 262144) {
        $aw_fehler[] = awm_t('EINST.SICH_ZU_GROSS');
    } else {
        list($awm_neu, $awm_mangel, $awm_n) = awm_sicherung_lesen(
            (string) @file_get_contents($_FILES['awm_sicherung']['tmp_name']));
        if ($awm_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert
             * wird nichts. */
            $aw_fehler[] = awm_t('EINST.SICH_ABGELEHNT') . ' ' . implode(' ', $awm_mangel);
        } else {
            /* U3 (Bauform Renault 2.1.13 U4): eine Sicherung ohne Token
             * ("kein Token gesichert") behaelt das geltende Token, und die
             * Seite sagt es. Bis 1.4.14 wurde es gespeichert, und beim
             * naechsten Seitenaufruf entstand STILL ein neues - jede in Loxone
             * eingetragene Adresse war danach ungueltig. */
            $awm_vorher = awm_config(true);
            $awm_tok_behalten = false;
            if ($awm_neu['aktionstoken'] === '' && awm_token_gueltig(isset($awm_vorher['aktionstoken']) ? $awm_vorher['aktionstoken'] : null)) {
                $awm_neu['aktionstoken'] = $awm_vorher['aktionstoken'];
                $awm_tok_behalten = true;
            }
            /* Ansage-2: das Alexa-NG-Sprechtoken steht nicht in der Sicherung und
             * BLEIBT - auch wenn eine fremde Datei eines mitbringt. */
            if (!isset($awm_neu['tts']) || !is_array($awm_neu['tts'])) { $awm_neu['tts'] = array(); }
            $awm_neu['tts']['alexa_token'] = isset($awm_vorher['tts']['alexa_token'])
                ? (string) $awm_vorher['tts']['alexa_token'] : '';
            if (!awm_config_speichern($awm_neu)) {
                $aw_fehler[] = awm_t('EINST.SICH_SCHREIBFEHLER');
            } else {
            $aw_hinweise[] = sprintf(awm_t('EINST.SICH_UEBERNOMMEN'), $awm_n);
            if ($awm_tok_behalten) { $aw_hinweise[] = awm_t('EINST.SICH_TOKEN_BEHALTEN'); }
            aw_mqtt_folgen($awm_vorher, $awm_neu, $aw_hinweise, $aw_fehler);     // M2-M4, M8
            /* Den Dienst nachziehen UND sagen, was mit ihm geschah
             * (Regeln/05). Ohne das stimmt die Seite, aber der Miniserver
             * rechnet bis zum naechsten Minutenlauf mit dem alten Stand. */
            awm_log('Einstellungen aus einer Sicherungsdatei zurueckgespielt ('
                    . (int) $awm_n . ' Werte).');
            $awm_nach = 0;
            foreach (array_keys(awm_cals()) as $awm_kn) {
                awm_state(true, $awm_kn);
                awm_mqtt_publish(null, $awm_kn);
                $awm_nach++;
            }
            $aw_hinweise[] = sprintf(awm_t('EINST.SICH_DIENST'), $awm_nach);
            }
        }
    }
}


if ($aw_sperre) { awm_sperre_frei($aw_sperre); }     // C9: nach den Handlern

/* ---------- U1: POST - Umleitung - GET ----------
 *
 * Regeln/04: "Jeder POST-Handler endet mit einer Umleitung." Bis 1.4.14
 * antworteten alle neun Handler mit HTTP 200; Neuladen leerte das Protokoll
 * ein zweites Mal, und nach "Neues Aktionstoken" meldete die Seite einen
 * fremden Absender (Oberflaechen-Pruefer, Befund 1). Jetzt: Ergebnis als
 * Einmalmeldung ablegen, 303 auf den Reiter, beim GET einmal zeigen. Das
 * gilt auch fuer einen POST ohne gueltiges Formularmerkmal. Die Downloads
 * (Vorlagen, Sicherung) haben oben schon geendet. Laesst sich die
 * Einmalmeldung nicht ablegen, wird wie bisher ohne Umleitung gezeigt. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aw_ziel = 'index.php?form=' . substr($aw_tab, 4);
    $aw_bc = isset($_POST['bins_cal']) ? $_POST['bins_cal'] : (isset($_POST['term_cal']) ? $_POST['term_cal'] : null);
    if ($aw_bc !== null && is_scalar($aw_bc)) { $aw_ziel .= '&bcal=' . max(1, (int) $aw_bc); }
    /* X-2: nach einer Beanstandung reisen die Eingaben DIESES Formulars mit
     * (Positivliste, keine Geheimnisse); ohne Beanstandung nicht - sonst
     * saehe ein still berichtigter Wert wie angenommen aus. */
    $aw_eing = ($aw_bean && $aw_bean_form !== '')
        ? awm_eingaben_sammeln($aw_bean_form, $_POST, $aw_bean) : null;
    if (awm_einmal_schreiben($aw_saved, $aw_hinweise, $aw_fehler, $aw_eing)) {
        header('Location: ' . $aw_ziel, true, 303);
        exit;
    }
    awm_log('Die Einmalmeldung liess sich nicht ablegen - die Seite wird ohne Umleitung gezeigt.');
    $aw_ein = $aw_eing;
} else {
    $aw_einmal = awm_einmal_lesen();
    if ($aw_einmal !== null) {
        if ($aw_einmal['gespeichert']) { $aw_saved = true; }
        $aw_ein = $aw_einmal['eingaben'];                    // X-2
        $aw_hinweise = array_merge($aw_einmal['hinweise'], $aw_hinweise);
        $aw_fehler = array_merge($aw_einmal['fehler'], $aw_fehler);
    }
}

/* ---------- Laden ---------- */
$aw_cfg = awm_config(true);
$aw_notify = $aw_cfg['notify'];
$aw_tts = $aw_cfg['tts'];
$aw_ruhe = $aw_cfg['ruhe'];
$aw_ansage = $aw_cfg['ansage'];
$aw_cals = awm_cals();
$aw_ftok = aw_formtoken($aw_cfg);

$aw_states = array();
foreach ($aw_cals as $aw_n => $aw_c) {
    $aw_states[$aw_n] = awm_state(false, $aw_n);
}
$aw_st1 = $aw_states ? reset($aw_states) : null;

$aw_loglines = array();
if (is_file($aw_logfile)) {
    $aw_loglines = array_slice(array_reverse(file($aw_logfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()), 0, 300);
}

// Welcher Kalender wird in der Zuordnungstabelle gezeigt?
$aw_bcal = max(1, min(max(1, count($aw_cals)), (int) (isset($_GET['bcal']) ? $_GET['bcal'] : 1)));
$aw_titel_liste = awm_titel_der_datei($aw_bcal);
$aw_arten_anz = awm_tonnenarten();
$aw_regeln_anz = awm_regeln($aw_bcal);
$aw_regel_zu = array();
foreach ($aw_regeln_anz as $aw_r) { $aw_regel_zu[$aw_r['muster']] = $aw_r; }

$aw_frame = class_exists('LBWeb', false);
$aw_host = aw_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '<loxberry-ip>');
$aw_basis = '/plugins/' . aw_e($aw_plugin) . '/awm.php';
$aw_tok = aw_e($aw_cfg['aktionstoken']);



if ($aw_frame) {
    LBWeb::lbheader('Abfuhrkalender (AWM &amp; iCal)', 'https://wiki.loxberry.de/', 'help.html');
}

?>
<style>
/* Hausstandard - wortgetreu aus VORLAGE_hausstandard.css.html */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap input[type=file],
.sm-wrap select, .sm-wrap textarea {
  width: 100%; max-width: 520px; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px;
  font-size: 0.95em; box-sizing: border-box; }
.sm-wrap table input[type=text], .sm-wrap table select { max-width: none; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
/* Wortgetreu aus VORLAGE_hausstandard.css.html (B54, 17.09.2026): jede Tabelle
   mit Eingabefeldern kommt in .sm-breit. lb-content schneidet seitlich ab; an
   BatterieBMS waren so zwei Spalten im Browser nicht erreichbar. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-kachel span { font-size: 0.82em; color: #666; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-fehler { border: 1px solid #ef9a9a; background: #ffebee; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1 1 220px; }
.sm-grau { color: #999; font-style: italic; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. Nachgezogen am
   05.09.2026 nach Regeln/04; Wortlaut aus VORLAGE_hausstandard.css.html.

   Am Geraet gemessen (LoxBerry 4.0.0.15, components.css): die Rahmen-CSS
   zeichnet seit der neuen Oberflaeche selbst einen Pfeil - Regel
   ".lb-content select". Darauf kann sich eine Plugin-Oberflaeche nicht
   verlassen: die Regel gibt es erst seit dieser Fassung, und die eigene
   Feldregel loescht sie, sobald sie die Kurzform "background:" benutzt.
   Dann steht ein Auswahlfeld da, das aussieht wie ein Textfeld.

   Die Raute im SVG wird als %23 geschrieben: eine rohe Raute beendet in
   einer CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
/* Ergaenzung, nicht aus der Vorlage (X-2, Regeln/04): ein nach einer
   Beanstandung zurueckgegebenes Feld, das beanstandet wurde. */
.sm-wrap .sm-beanstandet { border: 2px solid #c62828 !important; background: #fff5f5 !important; }

</style>
<div class="sm-wrap">

<?php if ($aw_saved) { ?><div class="sm-hinweis"><b><?= aw_t('MELD.GESPEICHERT') ?></b> <?= aw_t('MELD.GESPEICHERT_ZUSATZ') ?></div><?php } ?>
<?php foreach ($aw_hinweise as $aw_h) { ?><div class="sm-hinweis"><?= aw_e($aw_h) ?></div><?php } ?>
<?php if ($aw_fehler) { ?>
<div class="sm-fehler"><b><?= aw_t('MELD.FEHLER') ?></b>
<ul style="margin:6px 0 0 18px;padding:0;">
<?php foreach ($aw_fehler as $aw_f) { ?><li><?= aw_e($aw_f) ?></li><?php } ?>
</ul></div>
<?php } ?>

<?php if (is_array($aw_ein) && aw_ein_aktiv($aw_ein['formular'])) { /* X-2 */ ?>
<div class="sm-warnung" id="eingaben-zurueck"><?= aw_t('EINST.EINGABEN_ZURUECK') ?></div>
<?php } ?>
<?php if (!$aw_cals) { ?>
<div class="sm-warnung"><b><?= aw_t('MELD.KEIN_KALENDER') ?></b> <?= aw_t('MELD.KEIN_KALENDER_ZUSATZ') ?></div>
<?php } ?>

<?php /* Statuskacheln - die Frage, wegen der man die Seite ueberhaupt oeffnet,
         ohne Lesen beantwortet. Zwanzig Schwesterplugins haben sie; hier
         stand bis 1.3.8 Fliesstext in einem blauen Kasten. */ ?>
<?php foreach ($aw_states as $aw_n => $aw_st) {
    $aw_mn = array();
    foreach ($aw_arten_anz as $aw_k => $aw_v) { if (!empty($aw_st['morgen'][$aw_k])) { $aw_mn[] = $aw_v['text']; } }
    ?>
<h3><?= aw_e($aw_cals[$aw_n]['name']) ?></h3>
<div class="sm-kacheln">
  <div class="sm-kachel"><b><?= $aw_mn ? aw_e(implode(', ', $aw_mn)) : '&ndash;' ?></b><span><?= aw_t('KACHEL.MORGEN') ?></span></div>
  <div class="sm-kachel"><b><?= (int) $aw_st['next']['tage'] >= 0 ? (int) $aw_st['next']['tage'] : '&ndash;' ?></b><span><?= aw_t('KACHEL.TAGE_NEXT') ?></span></div>
  <div class="sm-kachel"><b><?= $aw_st['letzter'] !== '' ? aw_e(aw_d($aw_st['letzter'])) : '&infin;' ?></b><span><?= aw_t('KACHEL.KALENDER_BIS') ?></span></div>
  <div class="sm-kachel"><b><?= (int) $aw_st['alter'] >= 0 ? (int) $aw_st['alter'] . ' h' : '&ndash;' ?></b><span><?= aw_t('KACHEL.ALTER') ?></span></div>
  <div class="sm-kachel"><b class="<?= $aw_st['abruf_ok'] ? 'sm-an' : 'sm-aus' ?>"><?= $aw_st['abruf_ok'] ? aw_t('KACHEL.OK') : aw_t('KACHEL.FEHLER') ?></b><span><?= aw_t('KACHEL.ABRUF') ?></span></div>
</div>
<?php if (!$aw_st['abruf_ok'] && $aw_st['abruf_grund'] !== '') { ?>
<div class="sm-fehler"><b><?= aw_t('MELD.ABRUF_FEHLER') ?></b> <?= aw_e($aw_st['abruf_grund']) ?></div>
<?php } ?>
<?php if (!empty($aw_st['hinweis'])) { ?>
<div class="sm-warnung"><b><?= aw_t('MELD.HINWEIS_ENTSORGER') ?></b> <?= aw_e($aw_st['hinweis']) ?></div>
<?php } ?>
<?php if (!empty($aw_st['warnung'])) { ?>
<div class="sm-warnung"><b><?= aw_t('MELD.ACHTUNG') ?></b> <?= aw_t('MELD.KALENDER_ENDET') ?></div>
<?php } ?>
<?php if (!empty($aw_st['luecken'])) { ?>
<div class="sm-warnung"><b><?= aw_t('MELD.LUECKEN') ?></b> <?= aw_t('MELD.LUECKEN_TEXT') ?>
<ul style="margin:6px 0 0 18px;padding:0;">
<?php foreach (array_slice($aw_st['luecken'], 0, 8) as $aw_l) {
    $aw_ln = array();
    foreach ($aw_l['tonnen'] as $aw_lk) { $aw_ln[] = $aw_arten_anz[$aw_lk]['text']; } ?>
<li><?= aw_e(aw_d($aw_l['datum'])) ?> &ndash; <?= aw_e(implode(' + ', $aw_ln)) ?></li>
<?php } ?>
</ul></div>
<?php } ?>
<?php if (!empty($aw_st['termine'])) { ?>
<table class="sm-tbl" style="max-width:520px;"><tr><th><?= aw_t('TAB.DATUM') ?></th><th><?= aw_t('TAB.ABHOLUNG') ?></th></tr>
<?php foreach (array_slice($aw_st['termine'], 0, 6) as $aw_tt) { ?>
<tr><td><?= aw_e(aw_d($aw_tt[0])) ?></td><td><?= aw_e($aw_tt[1]) ?></td></tr>
<?php } ?></table>
<?php } ?>
<?php } ?>

<div class="sm-tabs">
	<a class="sm-tab<?= $aw_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
	   href="index.php?form=settings"><?= aw_t('REITER.EINSTELLUNGEN') ?></a>
	<a class="sm-tab<?= $aw_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"
	   href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab<?= $aw_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
	   href="index.php?form=loxone"><?= aw_t('REITER.LOXONE') ?></a>
	<a class="sm-tab<?= $aw_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
	   href="index.php?form=test"><?= aw_t('REITER.TEST') ?></a>
	<a class="sm-tab<?= $aw_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
	   href="index.php?form=log"><?= aw_t('REITER.LOG') ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $aw_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="formtoken" value="<?= aw_e($aw_ftok) ?>">

<h2><?= aw_t('EINST.H_QUELLEN') ?></h2>
<div class="sm-hinweis" id="jeder-ical"><?= awm_t('EINST.JEDER_ICAL') ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:34px;"><?= aw_t('EINST.T_NR') ?></th><th style="width:150px;"><?= aw_t('EINST.T_NAME') ?></th>
    <th><?= aw_t('EINST.T_URL') ?></th>
    <th style="width:70px;"><?= aw_t('EINST.T_ANSAGE') ?></th>
    <th style="width:110px;"><?= aw_t('EINST.T_ZONEN') ?></th></tr>
<?php for ($aw_i = 0; $aw_i < AWM_MAX_KALENDER; $aw_i++) {
    $aw_c = isset($aw_cfg['cals'][$aw_i]) ? (array) $aw_cfg['cals'][$aw_i] : array();
    $aw_c += array('name' => '', 'url' => '', 'ansage' => ($aw_i === 0 ? 1 : 0), 'zonen' => '', 'hochgeladen' => 0); ?>
<tr>
<td><?= $aw_i + 1 ?></td>
<td><input data-role="none" type="text" name="cal_name[]" value="<?= aw_e(aw_ew('save', 'cal_name.' . $aw_i, $aw_c['name'])) ?>"<?= aw_em('save', 'cal_name.' . $aw_i) ?> placeholder="<?= $aw_i === 0 ? aw_t('EINST.PH_NAME1') : aw_t('EINST.PH_NAME2') ?>"></td>
<td><input data-role="none" type="text" name="cal_url[]" value="<?= aw_e(aw_ew('save', 'cal_url.' . $aw_i, $aw_c['url'])) ?>"<?= aw_em('save', 'cal_url.' . $aw_i) ?> placeholder="https://www.awm-muenchen.de/...&amp;section=ics&amp;...">
<?php if (!empty($aw_c['hochgeladen'])) { ?><div class="sm-hilfe"><?= aw_t('EINST.IST_HOCHGELADEN') ?></div><?php } ?></td>
<td style="text-align:center;"><input data-role="none" type="checkbox" name="cal_ansage[<?= $aw_i ?>]" value="1" <?= aw_eh('save', 'cal_ansage.' . $aw_i, !empty($aw_c['ansage'])) ? 'checked' : '' ?>></td>
<td><input data-role="none" type="text" name="cal_zonen[<?= $aw_i ?>]" value="<?= aw_e(aw_ew('save', 'cal_zonen.' . $aw_i, $aw_c['zonen'])) ?>"<?= aw_em('save', 'cal_zonen.' . $aw_i) ?> placeholder="<?= aw_t('EINST.PH_ZONEN') ?>"></td>
</tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?= awm_t('EINST.H_QUELLEN_HILFE') ?></div>

<div class="sm-step"><b><?= aw_t('EINST.AWM_TITEL') ?></b><br>
<?= aw_t('EINST.AWM_1') ?> <a href="https://www.awm-muenchen.de/abfall-entsorgen/muelltonnen/abfuhrkalender" target="_blank"><?= aw_t('EINST.AWM_LINK') ?></a><br>
<?= aw_t('EINST.AWM_2') ?><br>
<?= aw_t('EINST.AWM_3') ?><br>
<?= aw_t('EINST.AWM_4') ?><br>
<span class="sm-hilfe"><b><?= aw_t('EINST.HINWEIS') ?></b> <?= awm_t('EINST.AWM_JAHR') ?></span>
</div>

<div class="sm-step"><b><?= aw_t('QUELLEN.H_WEITERE') ?></b><br>
<?= aw_t('QUELLEN.EINLEITUNG') ?>
<div class="sm-hilfe" style="margin-top:8px;"><b>Abfallplus / abfall.io</b><br><?= awm_t('QUELLEN.ABFALLIO') ?></div>
<div class="sm-hilfe" style="margin-top:8px;"><b>Jumomind / MyM&uuml;ll</b><br><?= aw_t('QUELLEN.JUMOMIND') ?></div>
<div class="sm-hilfe" style="margin-top:8px;"><b>ATURIS AbfallKalenderSystem</b><br><?= aw_t('QUELLEN.ATURIS') ?></div>
<div class="sm-hilfe" style="margin-top:8px;"><?= aw_t('QUELLEN.SONST') ?></div>
<div class="sm-hilfe" style="margin-top:8px;"><b><?= aw_t('EINST.HINWEIS') ?></b> <?= aw_t('QUELLEN.HINWEIS_JAHR') ?></div>
</div>

<h2><?= aw_t('EINST.H_ABRUF') ?></h2>
<div class="sm-row">
    <div class="sm-feld">
        <label><?= aw_t('EINST.L_INTERVALL') ?></label>
        <input data-role="none" type="<?= aw_et('save', 'fetch_days') ?>" name="fetch_days" value="<?= aw_e(aw_ew('save', 'fetch_days', (int) $aw_cfg['fetch_days'])) ?>"<?= aw_em('save', 'fetch_days') ?> min="1" max="60">
        <div class="sm-hilfe"><?= aw_t('EINST.H_INTERVALL') ?></div>
    </div>
    <div class="sm-feld">
        <label><?= aw_t('EINST.L_VORSCHAU') ?></label>
        <input data-role="none" type="<?= aw_et('save', 'lookahead') ?>" name="lookahead" value="<?= aw_e(aw_ew('save', 'lookahead', (int) $aw_cfg['lookahead'])) ?>"<?= aw_em('save', 'lookahead') ?> min="7" max="90">
        <div class="sm-hilfe"><?= aw_t('EINST.H_VORSCHAU') ?></div>
    </div>
    <div class="sm-feld">
        <label><?= aw_t('EINST.L_STICHWORTE') ?></label>
        <input data-role="none" type="text" name="hinweis_woerter" value="<?= aw_e(aw_ew('save', 'hinweis_woerter', trim((string) $aw_cfg['hinweis_woerter']) !== '' ? $aw_cfg['hinweis_woerter'] : AWM_HINWEIS_STANDARD)) ?>"<?= aw_em('save', 'hinweis_woerter') ?> placeholder="<?= aw_e(AWM_HINWEIS_STANDARD) ?>">
        <div class="sm-hilfe"><?= aw_t('EINST.H_STICHWORTE') ?></div>
    </div>
</div>
<div class="sm-feld">
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="autorenew" <?= aw_eh('save', 'autorenew', !empty($aw_cfg['autorenew'])) ? 'checked' : '' ?>> <?= aw_t('EINST.L_AUTORENEW') ?>
    </label>
    <div class="sm-hilfe"><?= aw_t('EINST.H_AUTORENEW') ?></div>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-top:8px;">
        <input data-role="none" type="checkbox" name="melden" <?= aw_eh('save', 'melden', !empty($aw_cfg['melden'])) ? 'checked' : '' ?>> <?= aw_t('EINST.L_MELDEN') ?>
    </label>
    <div class="sm-hilfe"><?= aw_t('EINST.H_MELDEN') ?></div>
</div>

<h2><?= aw_t('EINST.H_BENACHRICHTIGUNG') ?></h2>
<div class="sm-feld">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="notify_audio" <?= aw_eh('save', 'notify_audio', !empty($aw_notify['audio'])) ? 'checked' : '' ?>> <?= aw_t('EINST.L_AUDIO') ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_push" <?= aw_eh('save', 'notify_push', !empty($aw_notify['push'])) ? 'checked' : '' ?>> <?= aw_t('EINST.L_PUSH') ?>
    </label>
    <div class="sm-hilfe"><?= awm_t('EINST.H_AUDIOPUSH') ?></div>
</div>
<div class="sm-row">
    <div class="sm-feld">
        <label><?= aw_t('EINST.L_ZEIT') ?></label>
        <input data-role="none" type="text" name="notify_time" value="<?= aw_e(aw_ew('save', 'notify_time', $aw_notify['time'])) ?>"<?= aw_em('save', 'notify_time') ?> placeholder="18:00">
        <div class="sm-hilfe"><?= awm_t('EINST.H_ZEIT') ?></div>
    </div>
    <div class="sm-feld">
        <label style="display:inline-flex;align-items:center;gap:6px;">
            <input data-role="none" type="checkbox" name="notify_audio2" <?= aw_eh('save', 'notify_audio2', !empty($aw_notify['audio2'])) ? 'checked' : '' ?>> <?= aw_t('EINST.L_AUDIO2') ?>
        </label>
        <input data-role="none" type="text" name="notify_time2" value="<?= aw_e(aw_ew('save', 'notify_time2', $aw_notify['time2'])) ?>"<?= aw_em('save', 'notify_time2') ?> placeholder="06:30" style="margin-top:6px;">
        <div class="sm-hilfe"><?= awm_t('EINST.H_AUDIO2') ?></div>
    </div>
</div>

<h2><?= aw_t('EINST.H_RUHE') ?></h2>
<div class="sm-feld">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="ruhe_urlaub" <?= aw_eh('save', 'ruhe_urlaub', !empty($aw_ruhe['urlaub'])) ? 'checked' : '' ?>> <?= aw_t('EINST.L_URLAUB') ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="ruhe_nachts" <?= aw_eh('save', 'ruhe_nachts', !empty($aw_ruhe['nachts'])) ? 'checked' : '' ?>> <?= aw_t('EINST.L_NACHTS') ?>
    </label>
    <div class="sm-hilfe"><?= aw_t('EINST.H_URLAUB') ?>
    <?php $aw_dt = awm_daytype(); ?>
    <?php if ($aw_dt['quelle'] === 'keine') { ?><span class="sm-grau">(<?= aw_t('EINST.FERIEN_FEHLT') ?>)</span><?php } else { ?><span class="sm-an">(<?= aw_e($aw_dt['quelle']) ?>)</span><?php } ?>
    </div>
</div>
<div class="sm-row">
    <div class="sm-feld"><label><?= aw_t('EINST.L_VON') ?></label>
        <input data-role="none" type="text" name="ruhe_von" value="<?= aw_e(aw_ew('save', 'ruhe_von', $aw_ruhe['von'])) ?>"<?= aw_em('save', 'ruhe_von') ?> placeholder="22:00"></div>
    <div class="sm-feld"><label><?= aw_t('EINST.L_BIS') ?></label>
        <input data-role="none" type="text" name="ruhe_bis_zeit" value="<?= aw_e(aw_ew('save', 'ruhe_bis_zeit', $aw_ruhe['bis'])) ?>"<?= aw_em('save', 'ruhe_bis_zeit') ?> placeholder="07:00"></div>
    <div class="sm-feld"><label><?= aw_t('EINST.L_AUSSETZEN') ?></label>
        <input data-role="none" type="text" name="ruhe_bis" value="<?= aw_e(aw_ew('save', 'ruhe_bis', $aw_ruhe['bis_datum'] !== '' ? aw_d($aw_ruhe['bis_datum']) : '')) ?>"<?= aw_em('save', 'ruhe_bis') ?> placeholder="31.12.2026">
        <div class="sm-hilfe"><?= aw_t('EINST.H_AUSSETZEN') ?></div></div>
</div>

<h2><?= aw_t('EINST.H_SPRACHE') ?></h2>
<div class="sm-row">
    <div class="sm-feld">
        <label><?= aw_t('EINST.L_MODUS') ?></label>
        <?php $aw_tm = aw_ew('save', 'tts_mode', $aw_tts['mode']); /* X-2 */ ?>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="awTtsMode()"<?= aw_em('save', 'tts_mode') ?>>
            <option value="musicserver"<?= $aw_tm === 'musicserver' ? ' selected' : '' ?>><?= aw_t('EINST.MODE_MS') ?></option>
            <option value="ms4h"<?= $aw_tm === 'ms4h' ? ' selected' : '' ?>><?= aw_t('EINST.MODE_MS4H') ?></option>
            <option value="audioserver"<?= $aw_tm === 'audioserver' ? ' selected' : '' ?>><?= aw_t('EINST.MODE_AS') ?></option>
            <option value="custom"<?= $aw_tm === 'custom' ? ' selected' : '' ?>><?= aw_t('EINST.MODE_EIGEN') ?></option>
            <option value="alexang"<?= $aw_tm === 'alexang' ? ' selected' : '' ?>><?= aw_t('EINST.MODE_ALEXA') ?></option>
        </select>
    </div>
    <div class="sm-feld"><label><?= aw_t('EINST.L_IP') ?></label>
        <input data-role="none" type="text" name="tts_ip" value="<?= aw_e(aw_ew('save', 'tts_ip', $aw_tts['ip'])) ?>"<?= aw_em('save', 'tts_ip') ?> placeholder="192.168.1.50"></div>
    <div class="sm-feld"><label><?= aw_t('EINST.L_PORT') ?></label>
        <input data-role="none" type="<?= aw_et('save', 'tts_port') ?>" name="tts_port" value="<?= aw_e(aw_ew('save', 'tts_port', (int) $aw_tts['port'])) ?>"<?= aw_em('save', 'tts_port') ?> min="1" max="65535"></div>
</div>
<div class="sm-row">
    <div class="sm-feld">
        <label><?= aw_t('EINST.L_ZONEN') ?></label>
        <input data-role="none" type="text" name="tts_zones" value="<?= aw_e(aw_ew('save', 'tts_zones', $aw_tts['zones'])) ?>"<?= aw_em('save', 'tts_zones') ?> placeholder="2,4,6">
        <div class="sm-hilfe"><?= awm_t('EINST.H_ZONEN') ?></div>
    </div>
    <div class="sm-feld"><label><?= aw_t('EINST.L_LAUT') ?></label>
        <input data-role="none" type="<?= aw_et('save', 'tts_volume') ?>" name="tts_volume" value="<?= aw_e(aw_ew('save', 'tts_volume', (int) $aw_tts['volume'])) ?>"<?= aw_em('save', 'tts_volume') ?> min="1" max="100"></div>
    <div class="sm-feld"><label><?= aw_t('EINST.L_SPRACHE') ?></label>
        <input data-role="none" type="text" name="tts_lang" value="<?= aw_e(aw_ew('save', 'tts_lang', $aw_tts['lang'])) ?>"<?= aw_em('save', 'tts_lang') ?> maxlength="2"></div>
</div>
<div class="sm-feld" id="tts_template_row">
    <label><?= aw_t('EINST.L_VORLAGE') ?></label>
    <textarea data-role="none" name="tts_template" id="tts_template" rows="2" placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?= aw_e(aw_ew('save', 'tts_template', $aw_tts['template'])) ?></textarea>
    <div class="sm-hilfe"><?= awm_t('EINST.H_VORLAGE') ?></div>
</div>
<div id="tts_audioserver_hint" class="sm-warnung" style="display:none;"><?= awm_t('EINST.H_AUDIOSERVER') ?></div>
<div id="tts_alexa_rows">
<div class="sm-hilfe"><?= awm_t('EINST.H_ALEXA') ?></div>
<div class="sm-row">
    <div class="sm-feld">
        <label for="alexa_geraet"><?= aw_t('EINST.L_ALEXA_GERAET') ?></label>
        <input data-role="none" type="text" id="alexa_geraet" name="alexa_geraet" value="<?= aw_e(aw_ew('save', 'alexa_geraet', $aw_tts['alexa_geraet'])) ?>"<?= aw_em('save', 'alexa_geraet') ?> placeholder="kueche">
        <div class="sm-hilfe"><?= awm_t('EINST.H_ALEXA_GERAET') ?></div>
    </div>
    <div class="sm-feld">
        <label for="alexa_laut"><?= aw_t('EINST.L_ALEXA_LAUT') ?></label>
        <input data-role="none" type="<?= aw_et('save', 'alexa_laut') ?>" id="alexa_laut" name="alexa_laut" value="<?= aw_e(aw_ew('save', 'alexa_laut', (int) $aw_tts['alexa_laut'] >= 0 ? (int) $aw_tts['alexa_laut'] : '')) ?>"<?= aw_em('save', 'alexa_laut') ?> min="0" max="100">
        <div class="sm-hilfe"><?= aw_t('EINST.H_ALEXA_LAUT') ?></div>
    </div>
</div>
<div class="sm-feld">
    <label for="alexa_token"><?= aw_t('EINST.L_ALEXA_TOKEN') ?></label>
<?php /* Das Sprechtoken reist NIE ins Formular zurueck (wie ein Kennwort):
   angezeigt wird nur, ob eins gespeichert ist und wie lang es ist. */ ?>
    <input data-role="none" type="password" id="alexa_token" name="alexa_token" value="" autocomplete="new-password" placeholder="<?= (string) $aw_tts['alexa_token'] !== '' ? aw_e(sprintf(awm_t('EINST.P_ALEXA_TOKEN_GESETZT'), strlen((string) $aw_tts['alexa_token']))) : aw_t('EINST.P_ALEXA_TOKEN_LEER') ?>"<?= aw_em('save', 'alexa_token') ?>>
    <div class="sm-hilfe"><?= awm_t('EINST.H_ALEXA_TOKEN') ?></div>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;">
        <input data-role="none" type="checkbox" name="alexa_token_loeschen" value="1"<?= aw_eh('save', 'alexa_token_loeschen', false) ? ' checked' : '' ?>> <?= aw_t('EINST.L_ALEXA_TOKEN_LOESCHEN') ?>
    </label>
</div>
</div>

<div class="sm-row">
    <div class="sm-feld">
        <label><?= aw_t('EINST.L_ANSAGETEXT') ?></label>
        <input data-role="none" type="text" name="ansage_vorlage" value="<?= aw_e(aw_ew('save', 'ansage_vorlage', $aw_ansage['vorlage'])) ?>"<?= aw_em('save', 'ansage_vorlage') ?> placeholder="<?= aw_e(awm_t_oder('TEXT.ANSAGE_MEHRERE', '')) ?>">
        <div class="sm-hilfe"><?= awm_t('EINST.H_ANSAGETEXT') ?></div>
    </div>
    <div class="sm-feld">
        <label><?= aw_t('EINST.L_ANSAGETEXT2') ?></label>
        <input data-role="none" type="text" name="ansage_vorlage2" value="<?= aw_e(aw_ew('save', 'ansage_vorlage2', $aw_ansage['vorlage2'])) ?>"<?= aw_em('save', 'ansage_vorlage2') ?> placeholder="<?= aw_e(awm_t_oder('TEXT.ANSAGE_MORGENS', '')) ?>">
        <div class="sm-hilfe"><?= aw_t('EINST.H_ANSAGETEXT2') ?></div>
    </div>
</div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= aw_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= aw_t('KNOPF.SPEICHERN') ?></button>
</div>
</form>

<h2><?= aw_t('EINST.H_TONNEN') ?></h2>
<div class="sm-hinweis"><?= aw_t('TONNEN.ERKLAERUNG') ?></div>
<?php if (count($aw_cals) > 1) { ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= aw_t('LEGENDE.LESEN') ?></span>
</div>
<div class="sm-knopfreihe">
<?php /* U9: die Kalenderwahl schaltet nur die Ansicht um - grau-gruen wie
         jedes Ansehen, der gewaehlte mit Zeiger. Bis 1.4.14 war er orange
         (sm-b-aktion) und behauptete eine Wirkung, die nicht eintritt. */
foreach ($aw_cals as $aw_n => $aw_c) {
    if ($aw_n === $aw_bcal) { ?>
<a class="sm-btn sm-b-lesen" aria-current="true" href="index.php?form=settings&amp;bcal=<?= (int) $aw_n ?>">&#9656; <?= aw_e($aw_c['name']) ?></a>
<?php } else { ?>
<a class="sm-btn sm-b-lesen" href="index.php?form=settings&amp;bcal=<?= (int) $aw_n ?>"><?= aw_e($aw_c['name']) ?></a>
<?php } } ?>
</div>
<?php } ?>
<?php if (!$aw_titel_liste) { ?>
<div class="sm-warnung"><?= aw_t('TONNEN.KEINE_TITEL') ?></div>
<?php } else { ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="formtoken" value="<?= aw_e($aw_ftok) ?>">
<input data-role="none" type="hidden" name="bins_cal" value="<?= (int) $aw_bcal ?>">
<div class="sm-feld">
  <label><?= aw_t('TONNEN.L_MODUS') ?></label>
  <?php $aw_bm = aw_ew('bins', 'bins_modus', awm_regeln_modus($aw_bcal)); /* X-2 */ ?>
  <select data-role="none" name="bins_modus"<?= aw_em('bins', 'bins_modus') ?>>
    <option value="ersetzen"<?= $aw_bm === 'ersetzen' ? ' selected' : '' ?>><?= aw_t('TONNEN.MODUS_ERSETZEN') ?></option>
    <option value="ergaenzen"<?= $aw_bm === 'ergaenzen' ? ' selected' : '' ?>><?= aw_t('TONNEN.MODUS_ERGAENZEN') ?></option>
  </select>
  <div class="sm-hilfe"><?= aw_t('TONNEN.H_MODUS') ?></div>
</div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= aw_t('TONNEN.T_TITEL') ?></th><th style="width:60px;"><?= aw_t('TONNEN.T_ANZAHL') ?></th>
    <th><?= aw_t('TONNEN.T_JETZT') ?></th><th style="width:170px;"><?= aw_t('TONNEN.T_TONNE') ?></th>
    <th style="width:130px;"><?= aw_t('TONNEN.T_ART') ?></th></tr>
<?php $aw_k = 0; foreach ($aw_titel_liste as $aw_e2) {
    $aw_g = awm_glatt($aw_e2['titel']);
    $aw_hat = isset($aw_regel_zu[$aw_g]) ? $aw_regel_zu[$aw_g] : null;
    /* X-2: die eingetippte Zeile nur, wenn sie zu DIESEM Titel gehoert. */
    $aw_bz = aw_ew('bins', 'bin_titel.' . $aw_k, null) === $aw_e2['titel'];
    $aw_btn = $aw_bz ? aw_ew('bins', 'bin_tonne.' . $aw_k, '') : null;
    $aw_bar = $aw_bz ? aw_ew('bins', 'bin_art.' . $aw_k, '') : null; ?>
<tr>
  <td><span class="sm-mono"><?= aw_e($aw_e2['titel']) ?></span>
      <input data-role="none" type="hidden" name="bin_titel[<?= $aw_k ?>]" value="<?= aw_e($aw_e2['titel']) ?>"></td>
  <td><?= (int) $aw_e2['anzahl'] ?></td>
  <td><?php
      if (!$aw_e2['tonnen']) { echo '<span class="sm-grau">' . aw_t('TONNEN.NICHTS') . '</span>'; }
      else {
          $aw_n2 = array();
          foreach ($aw_e2['tonnen'] as $aw_tk) { $aw_n2[] = aw_e($aw_arten_anz[$aw_tk]['text']); }
          echo implode(' + ', $aw_n2);
          echo ' <span class="sm-hilfe">(' . aw_t($aw_e2['quelle'] === 'regel' ? 'TONNEN.Q_REGEL' : 'TONNEN.Q_EINGEBAUT') . ')</span>';
      }
      if (!empty($aw_e2['verloren'])) {
          $aw_v2 = array();
          foreach ($aw_e2['verloren'] as $aw_vk) { $aw_v2[] = aw_e($aw_arten_anz[$aw_vk]['text']); }
          echo '<div class="sm-aus" style="font-size:0.85em;">' . aw_t('TONNEN.VERLOREN') . ' ' . implode(' + ', $aw_v2) . '</div>';
      } ?></td>
  <td><select data-role="none" name="bin_tonne[<?= $aw_k ?>]"<?= aw_em('bins', 'bin_tonne.' . $aw_k) ?>>
      <option value=""><?= aw_t('TONNEN.KEINE_ZUORDNUNG') ?></option>
      <?php foreach ($aw_arten_anz as $aw_tk => $aw_tv) { ?>
      <option value="<?= aw_e($aw_tk) ?>"<?= ($aw_bz ? $aw_btn === $aw_tk : ($aw_hat && $aw_hat['tonne'] === $aw_tk)) ? ' selected' : '' ?>><?= aw_e($aw_tv['text']) ?></option>
      <?php } ?></select></td>
  <td><select data-role="none" name="bin_art[<?= $aw_k ?>]"<?= aw_em('bins', 'bin_art.' . $aw_k) ?>>
      <?php foreach (array('genau', 'enthaelt', 'beginnt') as $aw_ak) { ?>
      <option value="<?= $aw_ak ?>"<?= ($aw_bz ? $aw_bar === $aw_ak : (($aw_hat && $aw_hat['art'] === $aw_ak) || (!$aw_hat && $aw_ak === 'genau'))) ? ' selected' : '' ?>><?= aw_t('TONNEN.ART_' . strtoupper($aw_ak)) ?></option>
      <?php } ?></select></td>
</tr>
<?php $aw_k++; } ?>
</table>
</div>
<div class="sm-hilfe"><?= aw_t('TONNEN.FUSSNOTE') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= aw_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save_bins" value="1"><?= aw_t('TONNEN.K_SPEICHERN') ?></button>
</div>
</form>
<?php } ?>

<h2><?= aw_t('EIGEN.H_TITEL') ?></h2>
<div class="sm-hinweis"><?= aw_t('EIGEN.ERKLAERUNG') ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="formtoken" value="<?= aw_e($aw_ftok) ?>">
<input data-role="none" type="hidden" name="term_cal" value="<?= (int) $aw_bcal ?>">
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:140px;"><?= aw_t('EIGEN.T_DATUM') ?></th><th style="width:170px;"><?= aw_t('EIGEN.T_TONNE') ?></th><th><?= aw_t('EIGEN.T_TEXT') ?></th></tr>
<?php
$aw_eig = awm_eigene_termine($aw_bcal);
/* X-2: nach einer Beanstandung so viele Zeilen wie eingetippt. */
$aw_tz = max(count($aw_eig) + 3, aw_ein_zeilen('termine', 'term_datum'));
for ($aw_i = 0; $aw_i < $aw_tz; $aw_i++) {
    $aw_e3 = isset($aw_eig[$aw_i]) ? $aw_eig[$aw_i] : null;
    $aw_tt3 = aw_ew('termine', 'term_tonne.' . $aw_i, null); ?>
<tr>
<td><input data-role="none" type="text" name="term_datum[<?= $aw_i ?>]" value="<?= aw_e(aw_ew('termine', 'term_datum.' . $aw_i, $aw_e3 ? aw_d($aw_e3['start']) : '')) ?>"<?= aw_em('termine', 'term_datum.' . $aw_i) ?> placeholder="24.12.2026"></td>
<td><select data-role="none" name="term_tonne[<?= $aw_i ?>]"<?= aw_em('termine', 'term_tonne.' . $aw_i) ?>>
    <option value=""><?= aw_t('EIGEN.KEINE') ?></option>
    <?php foreach ($aw_arten_anz as $aw_tk => $aw_tv) { ?>
    <option value="<?= aw_e($aw_tk) ?>"<?= ($aw_tt3 !== null ? $aw_tt3 === $aw_tk : ($aw_e3 && $aw_e3['eigen'] === $aw_tk)) ? ' selected' : '' ?>><?= aw_e($aw_tv['text']) ?></option>
    <?php } ?></select></td>
<td><input data-role="none" type="text" name="term_text[<?= $aw_i ?>]" value="<?= aw_e(aw_ew('termine', 'term_text.' . $aw_i, $aw_e3 ? $aw_e3['summary'] : '')) ?>"<?= aw_em('termine', 'term_text.' . $aw_i) ?> placeholder="<?= aw_t('EIGEN.PH_TEXT') ?>"></td>
</tr>
<?php } ?>
</table>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= aw_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="save_termine" value="1"><?= aw_t('EIGEN.K_SPEICHERN') ?></button>
</div>
</form>

<h2><?= aw_t('EINST.H_UPLOAD') ?></h2>
<div class="sm-hinweis"><?= aw_t('EINST.H_UPLOAD_TEXT') ?></div>
<form action="index.php" method="post" enctype="multipart/form-data">
<input data-role="none" type="hidden" name="upload" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="formtoken" value="<?= aw_e($aw_ftok) ?>">
<div class="sm-row">
  <div class="sm-feld"><label><?= aw_t('EINST.L_UP_SLOT') ?></label>
    <select data-role="none" name="up_slot">
      <?php for ($aw_i = 0; $aw_i < AWM_MAX_KALENDER; $aw_i++) {
          $aw_nm = isset($aw_cfg['cals'][$aw_i]['name']) ? trim((string) $aw_cfg['cals'][$aw_i]['name']) : ''; ?>
      <option value="<?= $aw_i ?>"><?= ($aw_i + 1) . ($aw_nm !== '' ? ' - ' . aw_e($aw_nm) : '') ?></option>
      <?php } ?>
    </select></div>
  <div class="sm-feld"><label><?= aw_t('EINST.L_UP_DATEI') ?></label>
    <input data-role="none" type="file" name="icsdatei" accept=".ics,text/calendar"></div>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= aw_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
<?php /* U9: Hochladen ueberschreibt Kalenderdatei und Konfiguration - orange. */ ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= aw_t('KNOPF.HOCHLADEN') ?></button>
</div>
</form>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= aw_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
<?php /* U9: "Jetzt abrufen" geht ins Netz und ueberschreibt die Kalenderdatei - orange. */ ?>
<form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fetchnow" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="formtoken" value="<?= aw_e($aw_ftok) ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= aw_t('KNOPF.JETZT_ABRUFEN') ?></button>
</form>
</div>


<h2><?= awm_t('EINST.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= awm_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= awm_t('EINST.SICH_WARNUNG') ?></div>
<?php /* X-3: wuerde das eigene Zurueckspielen die Sicherung abweisen, steht
         es gelb am Knopf - mit dem Namen der Einstellung und dem Grund, nie
         mit dem Wert. DIESELBE Pruefung wie beim Zurueckspielen. Gesichert
         wird trotzdem vollstaendig (die Datei traegt dann "_warnung"). */
$aw_altw = awm_sicherung_altwerte();
if ($aw_altw) {
    $aw_altl = array();
    foreach ($aw_altw as $aw_ak => $aw_ag) { $aw_altl[] = ($aw_ak === '_' ? '' : $aw_ak . ' (') . $aw_ag . ($aw_ak === '_' ? '' : ')'); } ?>
<div class="sm-warnung" id="sicherung-altwerte"><?= aw_e(sprintf(awm_t('EINST.SICH_ALTWERT'), implode('; ', $aw_altl))) ?></div>
<?php } ?>
<?php /* U9: eine Legende ueber der Sicherungsreihe - gruen (sichern) und orange (zurueckspielen). */ ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= aw_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= aw_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="formtoken" value="<?= aw_e($aw_ftok) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="awm_sichern" value="1"><?= awm_t('EINST.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="formtoken" value="<?= aw_e($aw_ftok) ?>">
    <input data-role="none" type="file" name="awm_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="awm_zurueck" value="1"><?= awm_t('EINST.K_ZURUECK') ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $aw_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="mqtt_save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<input data-role="none" type="hidden" name="formtoken" value="<?= aw_e($aw_ftok) ?>">
<h2><?= aw_t('MQTT.H_TITEL') ?></h2>
<?php if (awm_mqtt_gateway_autostart() === false) { ?>
<div class="sm-warnung"><b>MQTT:</b> <?= aw_t('MQTT.W_AUTOSTART') ?></div>
<?php } ?>
<?php if (!function_exists('socket_create')) { ?>
<div class="sm-fehler"><b>MQTT:</b> <?= aw_t('MQTT.W_SOCKETS') ?></div>
<?php } ?>
<div class="sm-feld">
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="mqtt_enabled" <?= aw_eh('mqtt', 'mqtt_enabled', !empty($aw_cfg['mqtt_enabled'])) ? 'checked' : '' ?>> <?= aw_t('MQTT.L_AN') ?>
</label>
</div>
<div class="sm-feld">
    <label><?= aw_t('MQTT.L_PRAEFIX') ?></label>
    <input data-role="none" type="text" name="mqtt_topic" value="<?= aw_e(aw_ew('mqtt', 'mqtt_topic', $aw_cfg['mqtt_topic'])) ?>"<?= aw_em('mqtt', 'mqtt_topic') ?> placeholder="awm">
    <div class="sm-hilfe"><?= aw_t('MQTT.H_PRAEFIX') ?></div>
</div>

<div class="sm-step"><b><?= aw_t('MQTT.H_ABO') ?></b><br>
<?= aw_t('MQTT.T_ABO') ?>
<div class="sm-pre"><?= aw_e($aw_cfg['mqtt_topic']) ?>/#</div>
<?php /* awm_abo_text() statt aw_t(): aw_t() maskiert (aw_e(awm_t(...))),
     und der Hinweis traegt HTML. Durch aw_t() gingen dem Anwender die
     spitzen Klammern als Text auf den Bildschirm. */ ?>
<b><?= awm_abo_text() ?></b>
</div>

<h2><?= aw_t('MQTT.H_THEMEN') ?></h2>
<div class="sm-hilfe"><?= aw_t('MQTT.H_THEMEN_TEXT') ?></div>
<table class="sm-tbl">
<tr><th style="width:230px;"><?= aw_t('MQTT.T_THEMA') ?></th><th style="width:90px;"><?= aw_t('MQTT.T_RETAIN') ?></th><th><?= aw_t('MQTT.T_BEDEUTUNG') ?></th></tr>
<?php /* Die Spalte fragt DIESELBE Funktion wie der Sender (awm_mqtt_retain).
     Regeln/07: "Wer ein Thema anlegt, schreibt in die Namenstabelle des
     Reiters Einbindung in Loxone, ob es retained ist." Eine zweite Liste
     hier waere eine, die beim naechsten neuen Thema auseinanderlaeuft. */ ?>
<?php foreach (awm_mqtt_themen() as $aw_th => $aw_bed) { $aw_r = awm_mqtt_retain($aw_th); ?>
<tr><td><span class="sm-mono"><?= aw_e($aw_cfg['mqtt_topic']) ?>/<?= aw_e($aw_th) ?></span></td>
<td><span class="<?= $aw_r ? 'sm-an' : 'sm-aus' ?>"><?= aw_t($aw_r ? 'MQTT.RETAIN_JA' : 'MQTT.RETAIN_NEIN') ?></span></td>
<td><?= aw_e($aw_bed) ?></td></tr>
<?php } ?>
</table>
<div class="sm-hilfe"><?= aw_t('MQTT.H_RETAIN') ?></div>
<div class="sm-hilfe"><?= sprintf(awm_t('MQTT.H_ZWEITER'), aw_e($aw_cfg['mqtt_topic']), aw_e($aw_cfg['mqtt_topic'])) ?></div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= aw_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= aw_t('KNOPF.SPEICHERN') ?></button>
</div>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $aw_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= aw_t('LOX.H_TITEL') ?></h2>

<div class="sm-step"><b><?= aw_t('LOX.S1') ?></b><br><?= aw_t('LOX.S1_TEXT') ?></div>

<div class="sm-step"><b><?= aw_t('LOX.S2') ?></b><br>
<?= aw_t('LOX.S2_TEXT') ?>
<div class="sm-pre"><?= aw_e($aw_cfg['mqtt_topic']) ?>/#</div>
<?php /* awm_abo_text() statt aw_t(): aw_t() maskiert (aw_e(awm_t(...))),
     und der Hinweis traegt HTML. Durch aw_t() gingen dem Anwender die
     spitzen Klammern als Text auf den Bildschirm. */ ?>
<b><?= awm_abo_text() ?></b>
</div>

<div class="sm-step"><b><?= aw_t('LOX.S3') ?></b><br>
<?= aw_t('LOX.S3_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= aw_t('LOX.T_EIGENSCHAFT') ?></th><th><?= aw_t('LOX.T_WERT') ?></th></tr>
<tr><td>URL</td><td><span class="sm-mono">http://<?= $aw_host ?><?= $aw_basis ?></span></td></tr>
<tr><td><?= aw_t('LOX.T_ZYKLUS') ?></td><td>300 <?= aw_t('LOX.T_SEKUNDEN') ?></td></tr>
</table>
<?= awm_t('LOX.S3_BEFEHLE') ?>
<table class="sm-tbl">
<tr><th style="width:170px;"><?= aw_t('LOX.T_SUCHTEXT') ?></th><th style="width:60px;"><?= aw_t('LOX.T_TYP') ?></th><th><?= aw_t('LOX.T_BEDEUTUNG') ?></th></tr>
<?php foreach (awm_felder($aw_bcal) as $aw_fn => $aw_ff) { ?>
<tr><td><span class="sm-mono"><?= aw_e(awm_check($aw_fn)) ?></span></td>
    <td><?= $aw_ff[0] ? aw_t('LOX.ANALOG') : aw_t('LOX.DIGITAL') ?></td>
    <td><?= aw_e($aw_ff[4]) ?><?= $aw_ff[3] !== '' ? ' [' . aw_e($aw_ff[3]) . ']' : '' ?></td></tr>
<?php } ?>
</table>
<div class="sm-hilfe"><?= awm_t('LOX.S3_SEMIKOLON') ?></div>
<div class="sm-hilfe"><?= awm_t('LOX.S3_KOMPAT') ?></div>
</div>

<div class="sm-step"><b><?= aw_t('LOX.S4') ?></b><br>
<?= aw_t('LOX.S4_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= aw_t('LOX.T_ZWECK') ?></th><th><?= aw_t('LOX.T_ADRESSE') ?></th></tr>
<tr><td><?= aw_t('LOX.A_SAY') ?></td><td><span class="sm-mono"><?= $aw_basis ?>?say=1&amp;token=<?= $aw_tok ?></span></td></tr>
<tr><td><?= aw_t('LOX.A_PTEST') ?></td><td><span class="sm-mono"><?= $aw_basis ?>?ptest=1&amp;token=<?= $aw_tok ?></span></td></tr>
<tr><td><?= aw_t('LOX.A_ACK') ?></td><td><span class="sm-mono"><?= $aw_basis ?>?ack=1&amp;token=<?= $aw_tok ?></span></td></tr>
<tr><td><?= aw_t('LOX.A_RENEW') ?></td><td><span class="sm-mono"><?= $aw_basis ?>?renew=1&amp;token=<?= $aw_tok ?></span></td></tr>
<tr><td><?= aw_t('LOX.A_SELFTEST') ?></td><td><span class="sm-mono"><?= $aw_basis ?>?selftest=1&amp;token=<?= $aw_tok ?></span></td></tr>
</table>
<div class="sm-hilfe"><?= aw_t('LOX.S4_TOKEN') ?></div>
</div>

<div class="sm-step"><b><?= aw_t('LOX.S5') ?></b><br>
<?= awm_t('LOX.S5_TEXT') ?>
</div>

<div class="sm-step"><b><?= aw_t('LOX.S6') ?></b>
<table class="sm-tbl">
<tr><th style="width:34px;">#</th><th><?= aw_t('LOX.T_BAUSTEIN') ?></th><th><?= aw_t('LOX.T_NAME') ?></th><th><?= aw_t('LOX.T_PARAM') ?></th><th><?= aw_t('LOX.T_EINGAENGE') ?></th></tr>
<?php /* U10: Namen und Parameter ueber Sprachschluessel - bis 1.4.14 standen
         sie fest deutsch in der Seite, auch in der englischen. */ ?>
<tr><td>1</td><td><?= aw_t('LOX.B_SCHWELL') ?></td><td><?= aw_t('LOX.N01') ?></td><td><?= aw_t('LOX.P_SCHWELLE') ?></td><td><span class="sm-mono">ANN</span></td></tr>
<tr><td>2</td><td><?= aw_t('LOX.B_SCHWELL') ?></td><td><?= aw_t('LOX.N02') ?></td><td><?= aw_t('LOX.P_SCHWELLE') ?></td><td><span class="sm-mono">PUSH</span></td></tr>
<tr><td>3</td><td><?= aw_t('LOX.B_UND') ?></td><td><?= aw_t('LOX.N03') ?></td><td>&ndash;</td><td>#1, #2</td></tr>
<tr><td>4</td><td><?= aw_t('LOX.B_ODER') ?></td><td><?= aw_t('LOX.N04') ?></td><td><?= aw_t('LOX.P_SAMMLER') ?></td><td>#3</td></tr>
<tr><td>5</td><td><?= aw_t('LOX.B_BENACHR') ?></td><td><?= aw_t('LOX.N05') ?></td><td><?= aw_t('LOX.P_TEXT') ?></td><td>#4</td></tr>
<tr><td>6</td><td><?= aw_t('LOX.B_SCHWELL') ?></td><td><?= aw_t('LOX.N06') ?></td><td><?= aw_t('LOX.P_SCHWELLE') ?></td><td><span class="sm-mono">PTEST</span></td></tr>
<tr><td>7</td><td><?= aw_t('LOX.B_BENACHR') ?></td><td><?= aw_t('LOX.N07') ?></td><td><?= aw_t('LOX.P_EIGEN') ?></td><td>#6</td></tr>
<tr><td>8</td><td><?= aw_t('LOX.B_TASTER') ?></td><td><?= aw_t('LOX.N08') ?></td><td><?= aw_t('LOX.P_VISU') ?></td><td>&ndash;</td></tr>
<tr><td>9</td><td><?= aw_t('LOX.B_VAUS') ?></td><td><?= aw_t('LOX.N09') ?></td><td><span class="sm-mono">?ack=1</span></td><td>#8</td></tr>
<tr><td>10</td><td><?= aw_t('LOX.B_SCHWELL') ?></td><td><?= aw_t('LOX.N10') ?></td><td><?= aw_t('LOX.P_SCHWELLE') ?></td><td><span class="sm-mono">HREST</span> &hellip;</td></tr>
<tr><td>11</td><td><?= aw_t('LOX.B_SCHWELL') ?></td><td><?= aw_t('LOX.N11') ?></td><td><?= aw_t('LOX.P_SCHWELLE') ?></td><td><span class="sm-mono">WARN</span></td></tr>
<tr><td>12</td><td><?= aw_t('LOX.B_SCHWELL') ?></td><td><?= aw_t('LOX.N12') ?></td><td><?= aw_t('LOX.P_SCHWELLE') ?>, <?= aw_t('LOX.P_INVERS') ?></td><td><span class="sm-mono">FETCH</span></td></tr>
<tr><td>13</td><td><?= aw_t('LOX.B_STATUS') ?></td><td><?= aw_t('LOX.N13') ?></td><td><span class="sm-mono">&lt;v.1&gt; <?= aw_t('LOX.P_TAGE') ?></span></td><td><span class="sm-mono">TNEXT</span></td></tr>
<tr><td>14</td><td><?= aw_t('LOX.B_BENACHR') ?></td><td><?= aw_t('LOX.N14') ?></td><td><?= aw_t('LOX.P_EIGEN') ?></td><td>#11, #12 <?= aw_t('LOX.P_UEBER_ODER') ?></td></tr>
</table>
<div class="sm-hilfe"><b><?= aw_t('LOX.ZU4') ?></b> <?= aw_t('LOX.ZU4_TEXT') ?></div>
<div class="sm-hilfe"><b><?= aw_t('LOX.ZU9') ?></b> <?= aw_t('LOX.ZU9_TEXT') ?></div>
<div class="sm-hilfe"><b><?= aw_t('LOX.ZU12') ?></b> <?= awm_t('LOX.ZU12_TEXT') ?></div>
<div class="sm-hilfe"><b><?= aw_t('LOX.ZU13') ?></b> <?= awm_t('LOX.ZU13_TEXT') ?></div>
</div>

<div class="sm-step"><b><?= aw_t('LOX.S7') ?></b><br>
<?= aw_t('LOX.S7_TEXT') ?>
</div>

<h2><?= aw_t('LOX.H_VORLAGE') ?></h2>
<div class="sm-hinweis"><?= aw_t('LOX.H_VORLAGE_TEXT') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= aw_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= aw_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="formtoken" value="<?= aw_e($aw_ftok) ?>">
    <input data-role="none" type="hidden" name="vorlage" value="vi">
    <input data-role="none" type="hidden" name="vorlage_cal" value="<?= (int) $aw_bcal ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= aw_t('KNOPF.VORLAGE_VI') ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="formtoken" value="<?= aw_e($aw_ftok) ?>">
    <input data-role="none" type="hidden" name="vorlage" value="vo">
    <input data-role="none" type="hidden" name="vorlage_cal" value="<?= (int) $aw_bcal ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= aw_t('KNOPF.VORLAGE_VO') ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="formtoken" value="<?= aw_e($aw_ftok) ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= aw_t('KNOPF.TOKEN_NEU') ?></button>
  </form>
</div>
<div class="sm-hilfe"><?= aw_t('LOX.TOKEN_WARN') ?></div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $aw_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= aw_t('REITER.TEST') ?></h2>

<?php
$aw_pruef = awm_selbstpruefung_regeln();
$aw_pruef = array_merge($aw_pruef, awm_selbstpruefung_erneuerung());
$aw_pruef = array_merge($aw_pruef, awm_selbstpruefung_robust());
// Die Reiterliste gegen die Bereiche halten - drei Stellen, die
// auseinanderlaufen koennen (Hausstandard).
/* U8: die Pflichtzeilen nach Regeln/04, aus der EIGENEN Datei gezaehlt bzw.
 * wirklich gemessen. Bis 1.4.14 stand hier "count($aw_reiter) === 5" - die
 * Zahl im Quelltext der Pruefung. Der Aufruf des eigenen Endpunkts laeuft
 * nur, wenn der Reiter Test serverseitig der offene ist. "Nicht feststellbar"
 * zaehlt weder als bestanden noch als gescheitert und steht daneben. */
$aw_quelle = (string) @file_get_contents(__FILE__);
$aw_pflicht = array(awm_pruef_reiter($aw_quelle, $aw_reiter), awm_pruef_formulare($aw_quelle));
$aw_pflicht[] = $aw_tab === 'tab-test' ? awm_pruef_endpunkt($aw_cfg['aktionstoken'])
    : array('hinweis', awm_t('TEST.P_ENDPUNKT_ZU'));
$aw_pflicht[] = awm_pruef_konfiguration($aw_cfg_lage);
$aw_alz = awm_pruef_alexang($aw_cfg, $aw_tab === 'tab-test');     // Ansage-2
if ($aw_alz !== null) { $aw_pflicht[] = $aw_alz; }
$aw_pflicht[] = awm_pruef_vorlagen(count($aw_cals));
$aw_pflicht = array_merge($aw_pflicht, awm_pruef_cron());
$aw_ph = 0;
foreach ($aw_pflicht as $aw_z) {
    if ($aw_z[0] === 'hinweis') { $aw_ph++; continue; }
    $aw_pruef[] = array($aw_z[0] === 'ok' ? 1 : 0, $aw_z[1]);
}
$aw_pf = 0;
foreach ($aw_pruef as $aw_z) { if (!$aw_z[0]) { $aw_pf++; } }
?>
<div class="<?= $aw_pf ? 'sm-fehler' : ($aw_ph ? 'sm-warnung' : 'sm-hinweis') ?>">
<b><?= sprintf(aw_t($aw_pf ? 'TEST.SELBST_FEHL' : 'TEST.SELBST_OK'), count($aw_pruef) - $aw_pf, count($aw_pruef)) ?></b><?php
if ($aw_ph) { echo ' ' . aw_e(sprintf(awm_t('TEST.HINWEISE'), $aw_ph)); } ?>
</div>
<h3><?= aw_t('TEST.H_PFLICHT') ?></h3>
<table class="sm-tbl">
<?php foreach ($aw_pflicht as $aw_z) {
    $aw_zk = $aw_z[0] === 'ok' ? 'sm-an' : ($aw_z[0] === 'fehl' ? 'sm-aus' : 'sm-grau');
    $aw_zs = $aw_z[0] === 'ok' ? '&#10003;' : ($aw_z[0] === 'fehl' ? '&#10007;' : '&#9675;'); ?>
<tr><td style="width:34px;" class="<?= $aw_zk ?>"><?= $aw_zs ?></td><td><?= aw_e($aw_z[1]) ?></td></tr>
<?php } ?>
</table>
<?php if ($aw_pf) { ?>
<ul>
<?php foreach ($aw_pruef as $aw_z) { if (!$aw_z[0]) { ?><li><?= aw_e($aw_z[1]) ?></li><?php } } ?>
</ul>
<?php } ?>

<h3><?= aw_t('TEST.H_DIAGNOSE') ?></h3>
<p class="sm-hilfe"><?= aw_t('TEST.H_DIAGNOSE_TEXT') ?></p>
<?php foreach ($aw_cals as $aw_n => $aw_c) { $aw_dg = awm_kalender_diagnose($aw_n); ?>
<table class="sm-tbl">
<tr><th colspan="2"><?= aw_e($aw_c['name']) ?></th></tr>
<tr><td style="width:230px;"><?= aw_t('TEST.D_DATEI') ?></td><td><?= $aw_dg['da'] ? aw_e($aw_dg['stand']) . ', ' . (int) $aw_dg['bytes'] . ' Byte' : '<span class="sm-aus">' . aw_t('TEST.D_KEINE') . '</span>' ?></td></tr>
<tr><td><?= aw_t('TEST.D_EREIGNISSE') ?></td><td><?= (int) $aw_dg['ereignisse'] ?> (<?= (int) $aw_dg['serien'] ?> <?= aw_t('TEST.D_SERIEN') ?>, <?= (int) $aw_dg['einzel'] ?> <?= aw_t('TEST.D_EINZEL') ?>)</td></tr>
<tr><td><?= aw_t('TEST.D_ZEITRAUM') ?></td><td><?= $aw_dg['erste'] !== '' ? aw_e(aw_d($aw_dg['erste'])) . ' &ndash; ' . aw_e(aw_d($aw_dg['letzte'])) : '&ndash;' ?></td></tr>
<tr><td><?= aw_t('TEST.D_EXDATE') ?></td><td><?= (int) $aw_dg['exdates'] ?> EXDATE, <?= (int) $aw_dg['rdates'] ?> RDATE</td></tr>
<tr><td><?= aw_t('TEST.D_RRULE') ?></td><td>
<?php if (!$aw_dg['rrules']) { echo '<span class="sm-grau">' . aw_t('TEST.D_KEINE_RRULE') . '</span>'; }
      foreach ($aw_dg['rrules'] as $aw_rr => $aw_rn) { ?>
<span class="sm-mono"><?= aw_e($aw_rr) ?></span> &times;<?= (int) $aw_rn ?><br>
<?php } ?></td></tr>
<tr><td><?= aw_t('TEST.D_UNBEKANNT') ?></td><td><?php
if ($aw_dg['unbekannt']) { echo '<span class="sm-aus">' . aw_e(implode(', ', $aw_dg['unbekannt'])) . '</span> ' . aw_t('TEST.D_UNBEKANNT_WARN'); }
else { echo '<span class="sm-an">' . aw_t('TEST.D_ALLES_VERSTANDEN') . '</span>'; } ?></td></tr>
<tr><td><?= aw_t('TEST.D_REGELFEHLER') ?></td><td><?php
if (!$aw_dg['regelfehler']) { echo '<span class="sm-an">' . aw_t('TEST.D_REGELFEHLER_KEINE') . '</span>'; }
else { foreach ($aw_dg['regelfehler'] as $aw_rf) {
    echo '<span class="sm-aus">' . aw_e($aw_rf) . '</span><br>';
} } ?></td></tr>
<tr><td><?= aw_t('TEST.D_LUECKEN') ?></td><td><?php
if (!$aw_dg['luecken']) { echo '<span class="sm-an">' . aw_t('TEST.D_KEINE_LUECKEN') . '</span>'; }
else { foreach ($aw_dg['luecken'] as $aw_l) {
    $aw_ln = array();
    foreach ($aw_l['tonnen'] as $aw_lk) { $aw_ln[] = $aw_arten_anz[$aw_lk]['text']; }
    echo '<span class="sm-aus">' . aw_e(aw_d($aw_l['datum'])) . '</span> ' . aw_e(implode(' + ', $aw_ln)) . '<br>';
} } ?></td></tr>
</table>
<?php } ?>

<h3><?= aw_t('TEST.H_ANSEHEN') ?></h3>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= aw_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= aw_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= aw_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-lesen" href="<?= $aw_basis ?>" target="_blank"><?= aw_t('KNOPF.ZEILE') ?></a>
<a class="sm-btn sm-b-lesen" href="<?= $aw_basis ?>?json=1" target="_blank"><?= aw_t('KNOPF.JSON') ?></a>
<a class="sm-btn sm-b-lesen" href="<?= $aw_basis ?>?text=1" target="_blank"><?= aw_t('KNOPF.TEXT') ?></a>
<a class="sm-btn sm-b-lesen" href="<?= $aw_basis ?>?ics=1" target="_blank"><?= aw_t('KNOPF.ICS') ?></a>
</div>

<h3><?= aw_t('TEST.H_TECHNIK') ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-technik" href="<?= $aw_basis ?>?debug=1" target="_blank"><?= aw_t('KNOPF.DEBUG') ?></a>
<?php if (count($aw_cals) > 1) { /* U12: nur, wenn es Kalender 2 gibt */ ?>
<a class="sm-btn sm-b-technik" href="<?= $aw_basis ?>?debug=1&amp;cal=2" target="_blank"><?= aw_t('KNOPF.DEBUG2') ?></a>
<?php } ?>
<a class="sm-btn sm-b-technik" href="<?= $aw_basis ?>?selftest=1&amp;token=<?= $aw_tok ?>" target="_blank"><?= aw_t('KNOPF.SELFTEST') ?></a>
</div>

<h3><?= aw_t('TEST.H_AKTION') ?></h3>
<p class="sm-hilfe"><?= aw_t('TEST.H_AKTION_TEXT') ?></p>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-aktion" href="<?= $aw_basis ?>?say=1&amp;token=<?= $aw_tok ?>" target="_blank"><?= aw_t('KNOPF.SAY') ?></a>
<a class="sm-btn sm-b-aktion" href="<?= $aw_basis ?>?ptest=1&amp;token=<?= $aw_tok ?>" target="_blank"><?= aw_t('KNOPF.PTEST') ?></a>
<a class="sm-btn sm-b-aktion" href="<?= $aw_basis ?>?ack=1&amp;token=<?= $aw_tok ?>" target="_blank"><?= aw_t('KNOPF.ACK') ?></a>
<a class="sm-btn sm-b-aktion" href="<?= $aw_basis ?>?refresh=1&amp;debug=1&amp;token=<?= $aw_tok ?>" target="_blank"><?= aw_t('KNOPF.REFRESH') ?></a>
<a class="sm-btn sm-b-aktion" href="<?= $aw_basis ?>?renew=1&amp;token=<?= $aw_tok ?>" target="_blank"><?= aw_t('KNOPF.RENEW') ?></a>
</div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $aw_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= aw_t('REITER.LOG') ?></h2>
<div class="sm-hilfe"><?= aw_t('LOG.TEXT') ?><br><?= aw_t('LOG.DATEI') ?> <span class="sm-mono"><?= aw_e($aw_logfile) ?></span></div>
<?php if ($aw_loglines) { ?>
<div class="sm-log"><?= aw_e(implode("\n", $aw_loglines)) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= aw_t('LOG.LEER') ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= aw_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <input data-role="none" type="hidden" name="formtoken" value="<?= aw_e($aw_ftok) ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= aw_t('KNOPF.LOG_LEEREN') ?></button>
</form>
</div>
<?php
// Die Liste der LoxBerry-Logdateien gehoert nach Hausstandard in diesen
// Reiter - zwanzig Schwesterplugins haben sie, hier fehlte sie.
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html();
}
?>
</div>

</div>
<script>
function awTtsMode() {
    var m = document.getElementById('tts_mode');
    if (!m) { return; }
    var v = m.value;
    document.getElementById('tts_audioserver_hint').style.display = (v === 'audioserver') ? 'block' : 'none';
    document.getElementById('tts_template_row').style.display = (v === 'ms4h' || v === 'custom') ? 'block' : 'none';
    var al = document.getElementById('tts_alexa_rows');
    if (al) { al.style.display = (v === 'alexang') ? 'block' : 'none'; }
    var port = document.getElementsByName('tts_port')[0];
    if (v === 'musicserver' && port && (!port.value || port.value === '80')) { port.value = 7091; }
}
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.ziel === id); });
        document.querySelectorAll('.sm-seite').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
    }
    // Die Reiter sind echte Verweise. Solange dieses Skript laeuft, wird der
    // Klick abgefangen und nur umgeschaltet - ohne Skript folgt der Browser
    // dem Verweis und baut die Seite neu auf. Welcher Reiter offen ist,
    // entscheidet ohnehin der Server: sm-active steht schon im HTML.
    tabs.forEach(function (t) {
        t.addEventListener('click', function (ev) {
            ev.preventDefault();
            activate(t.dataset.ziel);
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', t.getAttribute('href'));
            }
        });
    });
    awTtsMode();
})();
</script>
<?php
if ($aw_frame) {
    LBWeb::lbfooter();
}
