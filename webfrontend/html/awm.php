<?php
/**
 * Abfuhrkalender AWM Muenchen - Miniserver-Endpunkt
 *
 * Aufrufe (&cal=2 waehlt den zweiten Kalender, z. B. Ferienhaus; Standard 1):
 *   (ohne Parameter) -> MUELL;REST=..;BIO=..;PAPIER=..;DATUM=..;WERT=..;OK=..;WARN=..;
 *                       HREST=..;HBIO=..;HPAPIER=..;HWERT=..;TREST=..;TBIO=..;TPAPIER=..;TWERT=..;
 *                       ANN=..;AUDIO=..;PUSH=..;PTEST=..
 *                       Die ersten vier Felder sind identisch zum klassischen muell.php
 *                       (REST/BIO/PAPIER = morgen faellig, DATUM = morgen als JJJJMMTT).
 *                       H* = heute faellig; T* = Tage bis zur naechsten Leerung (-1 = unbekannt);
 *                       WARN=1 = Kalender endet in <30 Tagen; ANN=1 = Erinnerungsfenster JETZT
 *                       (10 min ab konfigurierter Uhrzeit, wenn morgen etwas faellig ist);
 *                       AUDIO/PUSH = Freigaben aus der Plugin-Konfiguration;
 *                       PTEST=1 = Test-Pushnachricht angefordert (5 min aktiv).
 *   ?debug=1         -> zusaetzlich alle Termine im Vorschau-Fenster
 *   ?refresh=1       -> Kalender sofort neu abrufen
 *   ?json=1          -> kompletter Zustand als JSON
 *
 * Die drei Aufrufe, die etwas AUSLOESEN, verlangen seit 1.3.6 ein Token aus
 * dem Reiter "Einbindung in Loxone". Ohne passendes Token antworten sie mit
 * HTTP 403. Die abfragenden Aufrufe bleiben offen - sie aendern nichts.
 *
 *   ?say=1&token=T     -> Test: Ansage sofort abspielen (bzw. Demo-Text)
 *   ?ptest=1&token=T   -> Test-Pushnachricht ausloesen (setzt PTEST fuer 5 Minuten)
 *   ?renew=1&token=T   -> Jahres-Erneuerung des AWM-Links jetzt versuchen
 *   ?selftest=1&token=T -> nur pruefen, ob das Token stimmt; loest nichts aus
 *
 * Zugangsdaten stehen ausschliesslich in der Plugin-Konfiguration - diese URL
 * enthaelt kein Passwort.
 */

require_once __DIR__ . '/awm_lib.php';
$cal = isset($_GET['cal']) ? max(1, min(9, (int) $_GET['cal'])) : 1;

/* ---------- JSON ---------- */
if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    awm_fetch(isset($_GET['refresh']), $cal);
    $st = awm_state(isset($_GET['refresh']), $cal);
    $st['ann'] = $cal === 1 ? awm_ann_active($st) : 0;
    $js = json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($js === false) {
        // Passiert nur bei ungueltigem UTF-8 aus dem Kalender. Ein leerer
        // Rumpf mit Status 200 waere das Schlimmste: die Gegenstelle haelt
        // ihn fuer eine gueltige Antwort.
        http_response_code(500);
        echo json_encode(array('ok' => 0, 'fehler' => json_last_error_msg()));
        awm_log('JSON-Ausgabe fehlgeschlagen: ' . json_last_error_msg());
        exit;
    }
    echo $js;
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

/** Ist ein gueltiges Aktionstoken mitgeschickt worden?
 *
 * Ohne eingerichtetes Token ist die Antwort NEIN - ein leeres Soll darf
 * nicht auf ein leeres Ist passen, sonst schuetzt die Pruefung genau die
 * Anlage nicht, bei der noch nie jemand ein Token gesetzt hat. Die
 * Oberflaeche legt beim ersten Aufruf eines an.
 */
function awm_token_ok() {
    $cfg = awm_config();
    $soll = isset($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    if ($soll === '') { return false; }
    return hash_equals($soll, isset($_GET['token']) ? (string) $_GET['token'] : '');
}

/* ---------- Selbsttest: Token pruefen, ohne etwas auszuloesen ----------
 * Hausregel: jeder Aktionsendpunkt beantwortet ?selftest=1&token=... , ohne
 * dass etwas passiert. Sonst laesst sich nicht feststellen, ob die Adresse im
 * Miniserver noch stimmt, ohne wirklich etwas auszuloesen.
 */
if (isset($_GET['selftest'])) {
    $aw_cfg_st = awm_config();
    $aw_soll_st = isset($aw_cfg_st['aktionstoken']) ? (string) $aw_cfg_st['aktionstoken'] : '';
    if ($aw_soll_st === '') {
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
        exit;
    }
    if (!hash_equals($aw_soll_st, isset($_GET['token']) ? (string) $_GET['token'] : '')) {
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=TOKEN\n";
        exit;
    }
    echo "SELFTEST;OK=1;TOKEN=OK;CAL=" . $cal . "\n";
    exit;
}

/* ---------- Test-Ansage ---------- */
if (isset($_GET['say'])) {
    /* Seit 1.3.6 tokenpflichtig: der Aufruf laesst das Haus sprechen. Ohne
     * Token konnte jedes Geraet im Heimnetz die Ansage ausloesen. */
    if (!awm_token_ok()) {
        http_response_code(403);
        echo "SAY;OK=0;ERR=TOKEN\n";
        exit;
    }
    awm_fetch(false, 1);
    $text = awm_announce_text();
    if ($text === '') {
        $text = 'Hallo! Dies ist eine Testansage des Abfuhrkalenders. Morgen wird keine Tonne abgeholt.';
    }
    $ok = awm_say($text);
    echo 'SAY;OK=' . ($ok ? 1 : 0) . ";TEXT=$text\n";
    exit;
}

/* ---------- Test-Pushnachricht ---------- */
if (isset($_GET['ptest'])) {
    /* Seit 1.3.6 tokenpflichtig - Hausstandard fuer alle Aktionsendpunkte.
     * Der Aufruf setzt PTEST=1 fuer fuenf Minuten; das Loxone-Programm
     * schickt daraufhin eine echte Pushnachricht, und seit dieser Fassung
     * geht zusaetzlich sofort eine MQTT-Meldung heraus. Ohne Token konnte
     * jedes Geraet im Netz dem Anwender Meldungen aufs Telefon schicken. */
    if (!awm_token_ok()) {
        http_response_code(403);
        echo "PTEST;OK=0;ERR=TOKEN\n";
        exit;
    }
    @file_put_contents(awm_tmpdir() . '/ptest', '1');
    awm_log('Test-Pushnachricht angefordert (PTEST=1 fuer 5 Minuten; Loxone fragt im 300-s-Takt ab)');
    /* Sofort melden, statt bis zu einer Minute auf den Cron zu warten.
     * Ueber HTTP holt sich der Miniserver den Merker beim naechsten Abruf;
     * ueber MQTT muss ihn das Plugin schicken - und ein Test, der erst eine
     * Minute spaeter wirkt, sieht aus wie ein Test, der nicht wirkt.
     * Ueber alle Kalender, weil der Merker fuer alle gilt. */
    foreach (array_keys(awm_cals()) as $awm_n) {
        awm_mqtt_publish(null, $awm_n);
    }
    echo "PTEST;OK=1;DAUER=300\nHinweis: Loxone pollt alle 300 s - die Push-Nachricht kommt innerhalb von 5 Minuten,\nsofern der Test-Benachrichtigungsbaustein laut Anleitung (Schritt 3) verdrahtet ist.\n";
    exit;
}

/* ---------- Jahres-Erneuerung manuell ---------- */
if (isset($_GET['renew'])) {
    /* Seit 1.3.6 tokenpflichtig: der Aufruf schreibt die Konfiguration um
     * (neuer Kalender-Link) und geht dafuer ins Netz. */
    if (!awm_token_ok()) {
        http_response_code(403);
        echo "RENEW;OK=0;ERR=TOKEN\n";
        exit;
    }
    $ok = awm_renew($cal);
    echo 'RENEW;OK=' . ($ok ? 1 : 0) . "\n" . ($ok ? 'Neuer Link gespeichert.' : 'Erneuerung fehlgeschlagen - Details im Protokoll.') . "\n";
    exit;
}

/* ---------- Abruf / Zustand ---------- */
list($ok, $quelle) = awm_fetch(isset($_GET['refresh']), $cal);
$st = awm_state(isset($_GET['refresh']), $cal);
$cfg = awm_config();
/* Dieselbe Quelle wie die MQTT-Meldung - siehe awm_meldeflags(). */
$flags = awm_meldeflags($st, $cal);

if (isset($_GET['debug'])) {
    $c = awm_cal($cal);
    $icst = is_file(awm_icsfile($cal)) ? date('Y-m-d H:i', filemtime(awm_icsfile($cal))) . ', ' . filesize(awm_icsfile($cal)) . ' B' : 'keiner';
    echo 'DEBUG  Kalender ' . $cal . ' (' . ($c ? $c['name'] : '-') . ')  Quelle: ' . $quelle . '  Ereignisse: ' . $st['ereignisse'] . '  Datei: ' . $icst . "\n";
    if ($st['hinweis'] !== '') {
        echo 'HINWEIS: ' . $st['hinweis'] . "\n";
    }
    if ($st['warnung']) {
        echo "WARNUNG: Kalender liefert in weniger als 30 Tagen keine Termine mehr - Jahres-Erneuerung laeuft automatisch, sonst neuen iCal-Link eintragen!\n";
    }
    echo "Abholtermine im Vorschau-Fenster:\n";
    foreach ($st['termine'] as $t) {
        echo $t[0] . '  ' . $t[1] . "\n";
    }
    echo "\n";
}

/* DIE REIHENFOLGE DIESER FELDER DARF SICH NICHT AENDERN.
 *
 * Nicht nur wegen der 1:1-Kompatibilitaet zum alten muell.php, sondern aus
 * einem zweiten, weniger offensichtlichen Grund: Loxone sucht bei einem
 * "Virtuellen HTTP-Eingang Befehl" den Suchtext WOERTLICH und nimmt den
 * ERSTEN Treffer in der Zeile. Der Suchtext \iREST=\i\v steckt aber auch
 * in HREST= und TREST=, \iBIO=\i\v in HBIO= und TBIO=, und so weiter.
 *
 * Dass trotzdem jeder Baustein den richtigen Wert bekommt, liegt einzig
 * daran, dass REST, BIO, PAPIER und WERT VOR ihren H- und T-Varianten
 * stehen. Wer die Reihenfolge umstellt - etwa um die Zeile "aufzuraeumen"
 * - liefert stillschweigend falsche Zahlen an bestehende Loxone-Projekte,
 * ohne dass irgendwo ein Fehler auftaucht.
 *
 * Neue Felder deshalb immer HINTEN anhaengen. Wer einen Namen braucht, der
 * ein bestehendes Feld als Anfangsstueck enthaelt, muss ihn vor die
 * kuerzere Variante setzen - oder den Baustein mit fuehrendem Semikolon
 * suchen lassen (\i;FELD=\i\v).
 */
printf("MUELL;REST=%d;BIO=%d;PAPIER=%d;DATUM=%s;WERT=%d;OK=%d;WARN=%d;HREST=%d;HBIO=%d;HPAPIER=%d;HWERT=%d;TREST=%d;TBIO=%d;TPAPIER=%d;TWERT=%d;ANN=%d;AUDIO=%d;PUSH=%d;PTEST=%d\n",
    $st['morgen']['rest'], $st['morgen']['bio'], $st['morgen']['papier'],
    date('Ymd', strtotime('+1 day')),
    $st['morgen']['wert'], $st['ok'], $st['warnung'],
    $st['heute']['rest'], $st['heute']['bio'], $st['heute']['papier'], $st['heute']['wert'],
    $st['tage']['rest'], $st['tage']['bio'], $st['tage']['papier'], $st['tage']['wert'],
    $flags['ann'], $flags['audio'], $flags['push'], $flags['ptest']);
