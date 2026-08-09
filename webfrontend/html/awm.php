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
 *   ?say=1           -> Test: Ansage sofort abspielen (bzw. Demo-Text)
 *   ?ptest=1         -> Test-Pushnachricht ausloesen (setzt PTEST fuer 5 Minuten)
 *   ?renew=1         -> Jahres-Erneuerung des AWM-Links jetzt versuchen
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

/* ---------- Test-Ansage ---------- */
if (isset($_GET['say'])) {
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
    @file_put_contents(awm_tmpdir() . '/ptest', '1');
    awm_log('Test-Pushnachricht angefordert (PTEST=1 fuer 5 Minuten; Loxone fragt im 300-s-Takt ab)');
    echo "PTEST;OK=1;DAUER=300\nHinweis: Loxone pollt alle 300 s - die Push-Nachricht kommt innerhalb von 5 Minuten,\nsofern der Test-Benachrichtigungsbaustein laut Anleitung (Schritt 3) verdrahtet ist.\n";
    exit;
}

/* ---------- Jahres-Erneuerung manuell ---------- */
if (isset($_GET['renew'])) {
    $ok = awm_renew($cal);
    echo 'RENEW;OK=' . ($ok ? 1 : 0) . "\n" . ($ok ? 'Neuer Link gespeichert.' : 'Erneuerung fehlgeschlagen - Details im Protokoll.') . "\n";
    exit;
}

/* ---------- Abruf / Zustand ---------- */
list($ok, $quelle) = awm_fetch(isset($_GET['refresh']), $cal);
$st = awm_state(isset($_GET['refresh']), $cal);
$cfg = awm_config();

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
    $cal === 1 ? awm_ann_active($st) : 0,
    empty($cfg['notify']['audio']) ? 0 : 1,
    empty($cfg['notify']['push']) ? 0 : 1,
    awm_ptest_active());
