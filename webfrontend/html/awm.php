<?php
/**
 * Abfuhrkalender AWM Muenchen - Miniserver-Endpunkt
 *
 * Aufrufe (&cal=2 waehlt den zweiten Kalender, z. B. Ferienhaus; Standard 1):
 *
 *   (ohne Parameter) -> MUELL;REST=..;BIO=..;PAPIER=..;DATUM=..;WERT=..;OK=..;WARN=..;
 *                       HREST=..;..;TREST=..;..;ANN=..;AUDIO=..;PUSH=..;PTEST=..;
 *                       GLAS=..;..;DREST=..;..;SREST=..;..;TNEXT=..;DNEXT=..;SNEXT=..;
 *                       AGE=..;FETCH=..;LETZTER=..;HINW=..;LUECKE=..;ACK=..;RUHE=..
 *
 *                       Die ersten vier Felder sind identisch zum klassischen muell.php
 *                       (REST/BIO/PAPIER = morgen faellig, DATUM = morgen als JJJJMMTT).
 *                       H* = heute faellig; T* = Tage bis zur naechsten Leerung (-1 = unbekannt);
 *                       D* = deren Datum als JJJJMMTT; S* = dasselbe als Loxone-Zeit
 *                       (Sekunden seit 01.01.2009 - damit rechnet der Miniserver);
 *                       WARN=1 = Kalender endet in <30 Tagen; ANN=1 = Erinnerungsfenster JETZT;
 *                       AUDIO/PUSH = Freigaben aus der Plugin-Konfiguration;
 *                       PTEST=1 = Test-Pushnachricht angefordert (5 min aktiv);
 *                       AGE = Alter der Kalenderdatei in Stunden, FETCH = letzter Abruf ok,
 *                       LETZTER = letzter Termin im Kalender (Loxone-Zeit, 0 = unbegrenzt),
 *                       HINW=1 = es gibt einen Hinweis (Text ueber ?text=1),
 *                       LUECKE = Tage bis zum naechsten ersatzlos gestrichenen Termin,
 *                       ACK=1 = heute quittiert, RUHE=1 = Ansage ausgesetzt.
 *
 *   ?debug=1         -> zusaetzlich alle Termine im Vorschau-Fenster
 *   ?refresh=1       -> Kalender sofort neu abrufen
 *   ?json=1          -> kompletter Zustand als JSON
 *   ?text=1          -> die fertigen Saetze und der Hinweistext (fuer Textbausteine)
 *   ?ics=1           -> der gespeicherte Kalender zum Abonnieren
 *
 * Die Aufrufe, die etwas AUSLOESEN, verlangen seit 1.3.6 ein Token aus dem
 * Reiter "Einbindung in Loxone". Ohne passendes Token antworten sie mit
 * HTTP 403. Die abfragenden Aufrufe bleiben offen - sie aendern nichts.
 *
 *   ?say=1&token=T     -> Test: Ansage sofort abspielen (bzw. Demo-Text)
 *   ?ptest=1&token=T   -> Test-Pushnachricht ausloesen (setzt PTEST fuer 5 Minuten)
 *   ?ack=1&token=T     -> Quittierung "Tonne steht draussen" (setzt ACK bis Mitternacht)
 *   ?renew=1&token=T   -> Jahres-Erneuerung des Kalender-Links jetzt versuchen
 *   ?selftest=1&token=T -> nur pruefen, ob das Token stimmt; loest nichts aus
 *
 * Zugangsdaten stehen ausschliesslich in der Plugin-Konfiguration - diese URL
 * enthaelt kein Passwort.
 *
 * DIESE DATEI RECHNET NICHTS SELBST. Alle Werte kommen aus awm_werte() -
 * derselben Funktion, aus der auch die MQTT-Meldung entsteht. Bis 1.3.8
 * stand die Rechnung zweimal da, und die beiden Wege lieferten verschieden
 * viel.
 */

require_once __DIR__ . '/awm_lib.php';
$cal = isset($_GET['cal']) ? max(1, min(9, (int) $_GET['cal'])) : 1;

/* ---------- Kalender zum Abonnieren ----------
 * Wer den Kalender aufs Telefon oder in eine andere Hausautomatik legen
 * will, holt ihn ab jetzt vom LoxBerry statt vom Entsorger: der Server des
 * Entsorgers wird weiterhin nur alle 14 Tage gefragt, und die Adresse mit
 * Strasse und Hausnummer bleibt im Haus. */
if (isset($_GET['ics'])) {
    $awm_f = awm_icsfile($cal);
    if (!is_file($awm_f)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Kein Kalender gespeichert.\n";
        exit;
    }
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="abfuhrkalender_' . (int) $cal . '.ics"');
    readfile($awm_f);
    exit;
}

/* ---------- JSON ---------- */
if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    awm_fetch(isset($_GET['refresh']), $cal);
    $st = awm_state(isset($_GET['refresh']), $cal);
    // Die Meldeflags und die fertigen Saetze gehoeren mit hinein - sonst
    // muesste Drittsoftware sie nachbauen.
    $st = array_merge($st, awm_meldeflags($st, $cal));
    $st['werte'] = awm_werte($st, $cal);
    $st['text'] = array(
        'morgen' => awm_text_morgen($st),
        'heute' => awm_text_heute($st),
        'naechste' => awm_text_naechste($st),
    );
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

/* ---------- Fertige Saetze ----------
 * Fuer den Statustext-Baustein in Loxone und fuer die Visualisierung. Bis
 * 1.3.8 musste der Anwender den Satz aus vier Flags selbst bauen. */
if (isset($_GET['text'])) {
    awm_fetch(false, $cal);
    $st = awm_state(false, $cal);
    echo awm_text_morgen($st) . "\n";
    echo awm_text_heute($st) . "\n";
    echo awm_text_naechste($st) . "\n";
    echo ($st['hinweis'] !== '' ? $st['hinweis'] : '-') . "\n";
    exit;
}

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

/** Einheitliche Abweisung - jeder Aktionsendpunkt antwortet gleich. */
function awm_token_abweisen($praefix) {
    $cfg = awm_config();
    $soll = isset($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    http_response_code(403);
    echo $praefix . ';OK=0;ERR=' . ($soll === '' ? 'KEIN_TOKEN_EINGERICHTET' : 'TOKEN') . "\n";
    exit;
}

/* ---------- Selbsttest: Token pruefen, ohne etwas auszuloesen ----------
 * Hausregel: jeder Aktionsendpunkt beantwortet ?selftest=1&token=... , ohne
 * dass etwas passiert. Sonst laesst sich nicht feststellen, ob die Adresse im
 * Miniserver noch stimmt, ohne wirklich etwas auszuloesen.
 */
if (isset($_GET['selftest'])) {
    if (!awm_token_ok()) {
        awm_token_abweisen('SELFTEST');
    }
    echo "SELFTEST;OK=1;TOKEN=OK;CAL=" . $cal . "\n";
    exit;
}

/* ---------- Test-Ansage ---------- */
if (isset($_GET['say'])) {
    /* Seit 1.3.6 tokenpflichtig: der Aufruf laesst das Haus sprechen. Ohne
     * Token konnte jedes Geraet im Heimnetz die Ansage ausloesen. */
    if (!awm_token_ok()) {
        awm_token_abweisen('SAY');
    }
    awm_fetch(false, $cal);
    $awm_c = awm_cal($cal);
    $text = awm_announce_text(null, $cal);
    if ($text === '') {
        $text = awm_t_oder('TEXT.ANSAGE_TEST',
            'Hallo! Dies ist eine Testansage des Abfuhrkalenders. Morgen wird keine Tonne abgeholt.');
    }
    $ok = awm_say($text, $awm_c ? $awm_c['zonen'] : '');
    echo 'SAY;OK=' . ($ok ? 1 : 0) . ";TEXT=$text\n";
    exit;
}

/* ---------- Test-Pushnachricht ---------- */
if (isset($_GET['ptest'])) {
    /* Seit 1.3.6 tokenpflichtig - Hausstandard fuer alle Aktionsendpunkte. */
    if (!awm_token_ok()) {
        awm_token_abweisen('PTEST');
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
    echo "PTEST;OK=1;DAUER=300\nHinweis: Loxone pollt alle 300 s - die Push-Nachricht kommt innerhalb von 5 Minuten,\nsofern der Test-Benachrichtigungsbaustein laut Anleitung (Schritt 6) verdrahtet ist.\n";
    exit;
}

/* ---------- Quittierung "Tonne steht draussen" ----------
 * Bis 1.3.8 lebte die Quittierung ausschliesslich im Miniserver, und der
 * Reiter "Einbindung in Loxone" brauchte dafuer sieben Bausteine. Jetzt
 * weiss auch das Plugin davon und laesst die Morgen-Ansage aus. */
if (isset($_GET['ack'])) {
    if (!awm_token_ok()) {
        awm_token_abweisen('ACK');
    }
    awm_ack_setzen();
    foreach (array_keys(awm_cals()) as $awm_n) {
        awm_mqtt_publish(null, $awm_n);
    }
    echo "ACK;OK=1;BIS=Mitternacht\n";
    exit;
}

/* ---------- Jahres-Erneuerung manuell ---------- */
if (isset($_GET['renew'])) {
    /* Seit 1.3.6 tokenpflichtig: der Aufruf schreibt die Konfiguration um
     * (neuer Kalender-Link) und geht dafuer ins Netz. */
    if (!awm_token_ok()) {
        awm_token_abweisen('RENEW');
    }
    $ok = awm_renew($cal);
    echo 'RENEW;OK=' . ($ok ? 1 : 0) . "\n" . ($ok ? 'Neuer Link gespeichert.' : 'Erneuerung fehlgeschlagen - Details im Protokoll.') . "\n";
    exit;
}

/* ---------- Abruf / Zustand ----------
 *
 * ?refresh wird nur noch mit Token akzeptiert. Ohne Token konnte jedes
 * Geraet im Heimnetz beliebig oft eine ausgehende Anfrage an den Entsorger
 * ausloesen - und weil isset() geprueft wurde, tat das sogar ?refresh=0.
 * Die Oberflaeche und der Reiter Test schicken das Token mit.
 */
$awm_refresh = false;
if (isset($_GET['refresh']) && (string) $_GET['refresh'] !== '0') {
    $awm_refresh = awm_token_ok();
    if (!$awm_refresh) {
        awm_log('Abruf ueber ?refresh=1 ohne gueltiges Token abgelehnt.');
    }
}
list($ok, $quelle) = awm_fetch($awm_refresh, $cal);
$st = awm_state($awm_refresh, $cal);

if (isset($_GET['debug'])) {
    $c = awm_cal($cal);
    $icst = is_file(awm_icsfile($cal)) ? date('Y-m-d H:i', filemtime(awm_icsfile($cal))) . ', ' . filesize(awm_icsfile($cal)) . ' B' : 'keiner';
    echo 'DEBUG  Kalender ' . $cal . ' (' . ($c ? $c['name'] : '-') . ')  Quelle: ' . $quelle . '  Ereignisse: ' . $st['ereignisse'] . '  Datei: ' . $icst . "\n";
    if (isset($_GET['refresh']) && !$awm_refresh) {
        echo "HINWEIS: ?refresh=1 verlangt das Aktionstoken - es wurde der gespeicherte Stand benutzt.\n";
    }
    if (!$st['abruf_ok'] && $st['abruf_grund'] !== '') {
        echo 'ABRUF-FEHLER: ' . $st['abruf_grund'] . "\n";
    }
    if ($st['hinweis'] !== '') {
        echo 'HINWEIS: ' . $st['hinweis'] . "\n";
    }
    if ($st['warnung']) {
        echo "WARNUNG: Kalender liefert in weniger als 30 Tagen keine Termine mehr - Jahres-Erneuerung laeuft automatisch, sonst neuen iCal-Link eintragen!\n";
    }
    if (!empty($st['luecken'])) {
        echo "ERSATZLOS GESTRICHENE TERMINE (der Entsorger nennt keinen Ersatz):\n";
        foreach ($st['luecken'] as $l) {
            echo '  ' . substr($l['datum'], 6, 2) . '.' . substr($l['datum'], 4, 2) . '.' . substr($l['datum'], 0, 4)
               . '  ' . implode('+', $l['tonnen']) . '  (' . $l['titel'] . ")\n";
        }
    }
    echo "Abholtermine im Vorschau-Fenster:\n";
    foreach ($st['termine'] as $t) {
        echo $t[0] . '  ' . $t[1] . "\n";
    }
    echo "\n";
}

/* Die Zeile entsteht aus awm_werte() - derselben Quelle wie die
 * MQTT-Meldung. Zur Reihenfolge der Felder siehe awm_feldliste(): die
 * ersten neunzehn duerfen sich nicht aendern, neue kommen hinten dazu. */
echo awm_zeile(awm_werte($st, $cal)) . "\n";
