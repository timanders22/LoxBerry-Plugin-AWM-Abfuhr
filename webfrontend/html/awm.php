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
 *                       LETZTER = letzter Termin im Kalender (Loxone-Zeit, 0 = unbegrenzt,
 *                       -1 = keine Kalenderdaten - seit 1.4.25),
 *                       HINW=1 = es gibt einen Hinweis (Text ueber ?text=1),
 *                       LUECKE = Tage bis zum naechsten ersatzlos gestrichenen Termin,
 *                       ACK=1 = quittiert (heute; am Vorabend quittiert: bis zum Abholmorgen),
 *                       RUHE=1 = Ansage ausgesetzt.
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
 *   ?ack=1&token=T     -> Quittierung "Tonne steht draussen" (setzt ACK bis Mitternacht; ist morgen
 *                         etwas faellig, bis zum Abholmorgen - seit 1.4.24)
 *   ?renew=1&token=T   -> Jahres-Erneuerung des Kalender-Links jetzt versuchen
 *   ?selftest=1&token=T -> nur pruefen, ob das Token stimmt; loest nichts aus
 *
 * Seit 1.4.25 (Gruppe 1 / MQTT) nimmt dieselben vier Aktionen auch die Befehlsbruecke
 * an (<praefix>/befehl/ansage|testpush|tonne_draussen|erneuern, Haken "Befehle ueber MQTT
 * annehmen"); beide Wege rufen awm_aktion() in awm_lib.php.
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

/* Auf AWM_MAX_KALENDER geklemmt, nicht auf 9. Bis 1.4.6 standen drei
 * verschiedene Obergrenzen in einem Plugin: die Konfiguration kennt vier
 * Kalender, der Endpunkt liess neun zu, und die README sagte "und so fort".
 * Gemessen am 05.09.2026: ?cal=99 legte einen Zustand fuer Kalender 9 an. */
$cal = isset($_GET['cal']) ? max(1, min(AWM_MAX_KALENDER, (int) $_GET['cal'])) : 1;

/* ---------- Erzwungener Abruf: EINE Entscheidung fuer ALLE Zweige ----------
 *
 * ?refresh geht nach draussen - zum Server des Entsorgers - und schreibt die
 * Kalenderdatei. Das ist ein AUSLOESENDER Aufruf und verlangt deshalb das
 * Aktionstoken (Regeln/05).
 *
 * Bis 1.4.6 stand diese Pruefung nur im Klartext-Zweig. Der JSON-Zweig rief
 * awm_fetch(isset($_GET['refresh'])) ohne jede Pruefung - gemessen am
 * 05.09.2026: '?json=1&refresh=1' UND '?json=1&refresh=0' loesten von jedem
 * Geraet im Heimnetz eine Anfrage an den Entsorger aus, ohne Token. Genau
 * das Loch, das der Kommentar weiter unten fuer geschlossen erklaerte, und
 * genau das, was README und Hilfe als behoben versprechen.
 *
 * Jetzt entsteht die Entscheidung EINMAL, oben, und beide Zweige benutzen
 * sie. Der Wert '0' zaehlt nicht als Wunsch - bis 1.3.8 tat er das wegen
 * isset(), und damit rief auch '?refresh=0' beim Entsorger an.
 */
$awm_refresh = false;
if (isset($_GET['refresh']) && (string) $_GET['refresh'] !== '0') {
    $awm_refresh = awm_token_ok();
    if (!$awm_refresh) {
        /* Hoechstens eine Zeile je Stunde: sonst kann ein Fremder mit
         * wiederholten Aufrufen das Protokoll fluten und die aelteren
         * Eintraege aus der Kappung bei 200 Zeilen draengen. Die Stunde
         * steht mit drin, damit ein andauernder Versuch trotzdem sichtbar
         * bleibt - eine Zeile, die nur einmal im Leben kommt, ist keine
         * Meldung. */
        awm_log_if_changed('refresh_abgelehnt',
            'Abruf ueber ?refresh=1 ohne gueltiges Token abgelehnt (' . date('Y-m-d H') . ' Uhr).');
    }
}

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
    // Dieselbe Entscheidung wie im Klartext-Zweig - siehe oben.
    list($awm_fok, $awm_fq) = awm_fetch($awm_refresh, $cal);
    $st = awm_state($awm_refresh, $cal);
    // Die Meldeflags und die fertigen Saetze gehoeren mit hinein - sonst
    // muesste Drittsoftware sie nachbauen.
    $st = array_merge($st, awm_meldeflags($st, $cal));
    /* C5: eine gebremste Anforderung sagt es ausdruecklich (Regeln/03). */
    if ($awm_fq === 'gebremst') {
        $st['refresh'] = 'GEBREMST';
        $st['refresh_warte'] = awm_sofortabruf_warte($cal);
    }
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
    $soll = isset($cfg['aktionstoken']) ? $cfg['aktionstoken'] : '';
    /* U2: fail closed - das GESPEICHERTE Token muss eine Zeichenkette nach
     * dem Muster des Erzeugers sein. Bis 1.4.14 wurde eine Liste zu "Array",
     * und ?selftest=1&token=Array bekam SELFTEST;OK=1. */
    if (!awm_token_gueltig($soll)) { return false; }
    $ist = isset($_GET['token']) ? $_GET['token'] : '';
    return is_string($ist) && hash_equals($soll, $ist);
}

/**
 * Einheitliche Abweisung - jeder Aktionsendpunkt antwortet gleich.
 *
 * Und zwar wirklich gleich: bis 1.4.6 stand im Fehlergrund, OB ueberhaupt
 * ein Token eingerichtet ist ('KEIN_TOKEN_EINGERICHTET' gegen 'TOKEN').
 * Damit erfuhr ein Fremder ohne Anmeldung, dass diese Anlage ungeschuetzt
 * ist - und der Kommentar darueber behauptete das Gegenteil dessen, was der
 * Code tat. Der Unterschied gehoert ins Protokoll, nicht in die Antwort.
 */
function awm_token_abweisen($praefix) {
    $cfg = awm_config();
    $soll = isset($cfg['aktionstoken']) ? $cfg['aktionstoken'] : '';
    if (!awm_token_gueltig($soll)) {
        awm_log_if_changed('token_fehlt',
            'Ein Aktionsaufruf wurde abgewiesen, weil in der Konfiguration kein '
            . 'gueltiges Aktionstoken steht. Die Plugin-Oberflaeche legt beim '
            . 'naechsten Aufruf eines an (' . date('Y-m-d H') . ' Uhr).');
    }
    http_response_code(403);
    echo $praefix . ";OK=0;ERR=TOKEN\n";
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

/* ---------- Ausloesende Aufrufe: Ansage, Test-Push, Quittierung, Jahres-Erneuerung ----------
 *
 * Gruppe 1 / MQTT (1.4.25; Bauvorschrift Nachtrag 19.10.2026, Entwurf ab Abschnitt 3): die vier Zweige stehen jetzt
 * in awm_aktion() (awm_lib.php) - dieselbe Funktion ruft die Befehlsbruecke (bin/befehle_zuordnung.php, Quelle
 * 'mqtt'). Hier bleibt, was nur der HTTP-Weg hat: die Token-Pruefung (seit 1.3.6 tokenpflichtig - ohne Token konnte
 * jedes Geraet im Heimnetz das Haus sprechen lassen, eine Pushnachricht ausloesen oder die Konfiguration umschreiben),
 * Statuscode und Antwortzeilen. Beide sind byte-gleich wie bis 1.4.24 (Wirkungstest Quelle gegen Bau), ebenso die
 * Reihenfolge der Zweige (say vor ptest vor ack vor renew).
 */
foreach (array('say' => 'SAY', 'ptest' => 'PTEST', 'ack' => 'ACK', 'renew' => 'RENEW') as $awm_a => $awm_k) {
    if (!isset($_GET[$awm_a])) {
        continue;
    }
    if (!awm_token_ok()) {
        awm_token_abweisen($awm_k);
    }
    $awm_r = awm_aktion($awm_a, $cal, 'http');
    if ($awm_r['http'] !== 200) {
        http_response_code($awm_r['http']);
    }
    echo $awm_r['antwort'];
    exit;
}

/* ---------- Abruf / Zustand ----------
 *
 * $awm_refresh steht oben - EINE Entscheidung fuer alle Zweige dieser Datei.
 * Die Oberflaeche und der Reiter Test schicken das Token mit.
 */
list($ok, $quelle) = awm_fetch($awm_refresh, $cal);
$st = awm_state($awm_refresh, $cal);
/* C5: ein gebremster Sofortabruf wird ausdruecklich gemeldet - mit dem
 * Debug-Kopf davor, sonst als eigene Zeile HINTER der Zeile fuer Loxone
 * (die Suchtexte treffen so weiter zuerst die Zeile MUELL;...). */
$awm_bremse = ($quelle === 'gebremst')
    ? 'HINWEIS: ?refresh=1 gebremst - hoechstens ein Sofortabruf je ' . AWM_SOFORT_ABSTAND
      . ' s und Kalender, naechster in ' . awm_sofortabruf_warte($cal) . ' s. Es gilt der gespeicherte Stand.'
    : '';

if (isset($_GET['debug'])) {
    $c = awm_cal($cal);
    $icst = is_file(awm_icsfile($cal)) ? date('Y-m-d H:i', filemtime(awm_icsfile($cal))) . ', ' . filesize(awm_icsfile($cal)) . ' B' : 'keiner';
    echo 'DEBUG  Kalender ' . $cal . ' (' . ($c ? $c['name'] : '-') . ')  Quelle: ' . $quelle . '  Ereignisse: ' . $st['ereignisse'] . '  Datei: ' . $icst . "\n";
    if (isset($_GET['refresh']) && !$awm_refresh) {
        echo "HINWEIS: ?refresh=1 verlangt das Aktionstoken - es wurde der gespeicherte Stand benutzt.\n";
    }
    if ($awm_bremse !== '') { echo $awm_bremse . "\n"; $awm_bremse = ''; }
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
if ($awm_bremse !== '') { echo $awm_bremse . "\n"; }
