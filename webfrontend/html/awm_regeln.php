<?php
/**
 * Abfuhrkalender - Tonnenzuordnung und Wiederholungsregeln (ab 1.1.0)
 *
 * Diese Datei macht das Plugin bundesweit brauchbar. Sie loest zwei Probleme,
 * die beim Abfuhrkalender jeder Gemeinde ausserhalb Muenchens auftreten:
 *
 * 1. DAS NAMENSPROBLEM
 *    Es gibt keinen Standard fuer die Termin-Bezeichnung. Derselbe Restmuell
 *    heisst je nach Entsorger "Restmuelltonne 2-woechentlich", "RM 14taeg."
 *    oder "Graue Tonne - Tour B". Bis 1.0.2 waren vier Stichwoerter fest
 *    verdrahtet - alles andere fiel durch.
 *    Ab jetzt: eine Zuordnungstabelle JE KALENDER, die der Anwender pflegt,
 *    und die aus der geholten Datei vorschlaegt, was wirklich darin steht.
 *
 * 2. DIE WIEDERHOLUNGSREGELN
 *    Bis 1.0.2 wurde nur FREQ=WEEKLY ausgewertet. Bei FREQ=MONTHLY oder
 *    YEARLY galt allein der Basistermin - der Rest des Jahres fiel
 *    STILLSCHWEIGEND weg. Fuer Muenchen ohne Folgen (dort ist alles
 *    woechentlich), bundesweit ein ernstes Problem: viele Entsorger legen
 *    Papier und Gelbe Tonne monatlich an ("jeden zweiten Donnerstag").
 *
 * MUENCHEN BLEIBT UNVERAENDERT. Wer keine Zuordnung pflegt, bekommt genau
 * die Erkennung von 1.0.2 - Zeile fuer Zeile dieselbe. Das ist nicht nur
 * Absicht, sondern wird von awm_selbstpruefung_regeln() nachgewiesen - und
 * seit 1.4.0 zusaetzlich an einer ECHTEN AWM-Exportdatei geeicht
 * (73 Abholtage im Jahr 2026, 6 Serien; siehe README).
 *
 * ------------------------------------------------------------------------
 * NEU IN 1.4.0 - und warum
 *
 * Am 20.08.2026 wurden drei Wiederholungsformen gegen den echten Parser
 * gemessen, und alle drei gingen still daneben:
 *
 *   FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1   20 Treffer statt 1
 *   FREQ=MONTHLY;BYMONTH=3,9;BYMONTHDAY=15          12 statt 2 im Jahr
 *   FREQ=YEARLY;BYMONTH=3;BYDAY=2SA                 ab dem 2. Jahr keiner
 *
 * Das sind genau die Formen, in denen Entsorger Sperrmuell, Gruenschnitt
 * und das Schadstoffmobil anlegen. Im Muenchner Kalender kommt keine davon
 * vor - dort ist alles FREQ=WEEKLY;INTERVAL=2;BYDAY=..;WKST=MO. Die
 * Befunde treffen also NICHT Muenchen, sondern jeden anderen Entsorger.
 *
 * BYSETPOS, BYMONTH und WKST werden deshalb jetzt ausgewertet, YEARLY kennt
 * BYDAY und BYMONTHDAY, und RDATE sowie RECURRENCE-ID kommen dazu
 * (letzteres in awm_series()).
 *
 * Der Satz weiter unten - "Alles andere wird NICHT geraten" - galt fuer
 * BYSETPOS und BYMONTH eben nicht: da wurde sehr wohl geraten, und zwar zu
 * viel. Jetzt stimmt er wieder, und was das Plugin NICHT versteht, sagt es
 * im Reiter Test (awm_rrule_unbekannt).
 */

/* ==================================================================
 * Tonnenarten
 *
 * Die vier alten bleiben in ihrer Reihenfolge und mit ihren Schluesseln -
 * daran haengen Konfiguration, MQTT-Themen und Loxone-Bausteine. Neue
 * kommen nur hinten dazu.
 *
 * Ab 1.4.0 werden ALLE acht ausgeliefert: bis dahin waren glas, sperr,
 * gruen und schad zwar in der Zuordnungstabelle waehlbar und wurden in
 * heute/morgen auch gefuellt - aber tage, naechste, die MQTT-Schleife, die
 * Loxone-Zeile, die Vorlage und die Ansage kannten nur die ersten vier.
 * Wer "Altglas" zuordnete, bekam nirgends etwas zu sehen.
 *
 * Die Namen kommen aus der Sprachdatei; der deutsche Text bleibt als
 * Rueckfallebene stehen, damit die Datei auch ohne awm_t() benutzbar ist
 * (Selbstpruefung, Aufruf aus einem Skript).
 * ================================================================== */

function awm_tonnenarten()
{
    $fest = array(
        'rest'   => array('text' => 'Restmüll',       'alt' => 1),
        'bio'    => array('text' => 'Biotonne',       'alt' => 1),
        'papier' => array('text' => 'Papier',         'alt' => 1),
        'wert'   => array('text' => 'Wertstoff/Gelb', 'alt' => 1),
        'glas'   => array('text' => 'Glas',           'alt' => 0),
        'sperr'  => array('text' => 'Sperrmüll',      'alt' => 0),
        'gruen'  => array('text' => 'Grünschnitt',    'alt' => 0),
        'schad'  => array('text' => 'Schadstoffe',    'alt' => 0),
    );
    if (!function_exists('awm_t')) {
        return $fest;
    }
    foreach ($fest as $k => $v) {
        $t = awm_t('TONNE.' . strtoupper($k));
        // awm_t() gibt den Schluessel zurueck, wenn er fehlt - dann bleibt
        // der eingebaute Text stehen, statt "TONNE.REST" anzuzeigen.
        if ($t !== '' && strpos($t, 'TONNE.') !== 0) {
            $fest[$k]['text'] = $t;
        }
    }
    return $fest;
}

/**
 * Die Feldnamen fuer Loxone und MQTT je Tonnenart.
 *
 * Sie stehen HIER und nur hier. Wer eine Tonnenart ergaenzt, ergaenzt beide
 * Tabellen - und bekommt Loxone-Feld, MQTT-Thema, Vorlage und Ansage ohne
 * weiteres Zutun, weil alles darueber laeuft.
 */
function awm_tonnen_kuerzel()
{
    return array(
        'rest' => 'REST', 'bio' => 'BIO', 'papier' => 'PAPIER', 'wert' => 'WERT',
        'glas' => 'GLAS', 'sperr' => 'SPERR', 'gruen' => 'GRUEN', 'schad' => 'SCHAD',
    );
}

function awm_tonnen_leer()
{
    $out = array();
    foreach (awm_tonnenarten() as $k => $v) { $out[$k] = 0; }
    return $out;
}

/* ==================================================================
 * Die eingebaute Erkennung - wortgleich aus 1.0.2
 *
 * Sie bleibt als Rueckfallebene bestehen. Wer nichts zuordnet, bekommt
 * exakt das bisherige Verhalten.
 * ================================================================== */

function awm_bins_eingebaut($summary)
{
    $l = function_exists('mb_strtolower') ? mb_strtolower($summary, 'UTF-8') : awm_klein($summary);
    $b = awm_tonnen_leer();
    if (strpos($l, 'rest') !== false || strpos($l, 'hausm') !== false) { $b['rest'] = 1; }
    if (strpos($l, 'bio') !== false) { $b['bio'] = 1; }
    if (strpos($l, 'papier') !== false) { $b['papier'] = 1; }
    if (strpos($l, 'wertstoff') !== false || strpos($l, 'gelbe') !== false) { $b['wert'] = 1; }
    return $b;
}

/* ==================================================================
 * Die Zuordnungstabelle des Anwenders
 *
 * Eine Regel ist: {"muster": "...", "tonne": "rest", "art": "enthaelt"}
 *
 *   enthaelt  der Text kommt irgendwo im Titel vor (der Regelfall)
 *   beginnt   der Titel faengt damit an
 *   genau     der Titel ist genau dieser Text
 *
 * Absichtlich KEINE regulaeren Ausdruecke: wer sie beherrscht, kann sie
 * hier nicht gebrauchen, und wer sie nicht beherrscht, baut sich damit
 * eine Falle. Drei Arten decken jeden Fall ab, der in einem
 * Abfuhrkalender vorkommt.
 *
 * Verglichen wird auf einer geglaetteten Fassung: Kleinschreibung, Umlaute
 * aufgeloest, Mehrfachleerzeichen zusammengezogen. Damit trifft "Grüne
 * Tonne" auch "GRUENE  TONNE".
 * ================================================================== */

/**
 * Kleinschreibung, die eine UTF-8-Folge nicht zerstoert.
 *
 * strtolower() arbeitet in PHP 7.4 LOCALE-ABHAENGIG und byteweise. Bei
 * gesetztem deutschem Locale macht es aus dem Byte 0xC3 das Byte 0xE3 -
 * die UTF-8-Folge ist damit kaputt, das nachfolgende preg_replace mit dem
 * Schalter /u gibt null zurueck, und awm_glatt() lieferte einen
 * LEERSTRING. Gemessen am 20.08.2026 auf PHP 7.4.33 ohne mbstring:
 * awm_glatt('Café-Tonne') === ''. Wirkung: ein Muster mit so einem Zeichen
 * wurde in awm_regeln() stillschweigend verworfen - die Regel verschwand,
 * ohne dass die Oberflaeche etwas meldete.
 *
 * Deshalb wird hier NUR A-Z kleingeschrieben. Alles ab 0x80 bleibt Byte
 * fuer Byte stehen.
 */
function awm_klein($s)
{
    return preg_replace_callback('/[A-Z]+/', function ($m) {
        return strtolower($m[0]);
    }, (string) $s);
}

function awm_glatt($s)
{
    // Umlaute ZUERST und in BEIDEN Schreibweisen aufloesen.
    $l = strtr((string) $s, array(
        'ä' => 'ae', 'Ä' => 'ae', 'ö' => 'oe', 'Ö' => 'oe',
        'ü' => 'ue', 'Ü' => 'ue', 'ß' => 'ss',
    ));
    $l = function_exists('mb_strtolower') ? mb_strtolower($l, 'UTF-8') : awm_klein($l);
    // Bei ungueltigem UTF-8 gibt preg_replace mit /u null zurueck. Dann
    // ohne /u weitermachen, statt einen Leerstring zu liefern.
    $n = preg_replace('/\s+/u', ' ', $l);
    if ($n === null) {
        $n = preg_replace('/\s+/', ' ', $l);
    }
    return trim((string) $n);
}

/** Regeln eines Kalenders aus der Konfiguration, bereinigt. */
function awm_regeln($cal = 1)
{
    $cfg = awm_config();
    $cals = isset($cfg['cals']) && is_array($cfg['cals']) ? array_values($cfg['cals']) : array();
    $i = max(1, (int) $cal) - 1;
    $r = isset($cals[$i]['regeln']) && is_array($cals[$i]['regeln']) ? $cals[$i]['regeln'] : array();
    $arten = awm_tonnenarten();
    $out = array();
    foreach ($r as $x) {
        if (!is_array($x)) { continue; }
        $m = awm_glatt(isset($x['muster']) ? $x['muster'] : '');
        $t = (string) (isset($x['tonne']) ? $x['tonne'] : '');
        $a = (string) (isset($x['art']) ? $x['art'] : 'enthaelt');
        if ($m === '' || !isset($arten[$t])) { continue; }
        if (!in_array($a, array('enthaelt', 'beginnt', 'genau'), true)) { $a = 'enthaelt'; }
        $out[] = array('muster' => $m, 'tonne' => $t, 'art' => $a);
    }
    return $out;
}

/**
 * Ersetzt eine Regel die eingebaute Erkennung, oder ergaenzt sie sie?
 *
 * Bis 1.3.8 gab es nur "ersetzen", und das war Absicht: wer zuordnet, soll
 * bewusst ueberschreiben koennen ("Biotonne" -> Gruenschnitt). Der Preis
 * war ein stiller Verlust, gemessen am 20.08.2026:
 *
 *   "Papier und Biotonne" ohne Regel        -> papier=1 bio=1
 *   "Papier und Biotonne" mit Papier-Regel  -> papier=1 bio=0
 *
 * Wer EINE Tonne zuordnete, verlor die andere - ohne Meldung. Deshalb ist
 * die Wahl jetzt einstellbar, und die Oberflaeche zeigt im Reiter Tonnen
 * ausdruecklich an, was die eingebaute Erkennung zusaetzlich gefunden
 * haette (Feld 'verloren'). Voreinstellung bleibt "ersetzen" - bestehende
 * Anlagen aendern sich dadurch nicht.
 */
function awm_regeln_modus($cal = 1)
{
    $cfg = awm_config();
    $cals = isset($cfg['cals']) && is_array($cfg['cals']) ? array_values($cfg['cals']) : array();
    $i = max(1, (int) $cal) - 1;
    $m = isset($cals[$i]['regeln_modus']) ? (string) $cals[$i]['regeln_modus'] : 'ersetzen';
    return $m === 'ergaenzen' ? 'ergaenzen' : 'ersetzen';
}

/** Trifft eine Regel auf den Titel zu? */
function awm_regel_trifft($regel, $glatt)
{
    switch ($regel['art']) {
        case 'genau':   return $glatt === $regel['muster'];
        case 'beginnt': return strpos($glatt, $regel['muster']) === 0;
        default:        return strpos($glatt, $regel['muster']) !== false;
    }
}

/**
 * Tonnen aus einem Termin-Titel.
 *
 * Reihenfolge: erst die Regeln des Anwenders. Trifft KEINE, greift die
 * eingebaute Erkennung. So bleibt Muenchen ohne Zutun richtig, und wer
 * zuordnet, ueberschreibt bewusst - oder ergaenzt, siehe awm_regeln_modus().
 */
function awm_bins_regeln($summary, $regeln, $modus = 'ersetzen')
{
    $b = awm_tonnen_leer();
    $glatt = awm_glatt($summary);
    $getroffen = false;
    foreach ((array) $regeln as $r) {
        if (awm_regel_trifft($r, $glatt)) {
            $b[$r['tonne']] = 1;
            $getroffen = true;
        }
    }
    if (!$getroffen) {
        return awm_bins_eingebaut($summary);
    }
    if ($modus === 'ergaenzen') {
        foreach (awm_bins_eingebaut($summary) as $k => $v) {
            if ($v) { $b[$k] = 1; }
        }
    }
    return $b;
}

/**
 * Welche Titel stehen wirklich in der geholten Datei?
 *
 * Das ist die Antwort auf "welches Wort steht bei mir fuer welche Tonne":
 * die Oberflaeche zeigt die tatsaechlich vorkommenden Bezeichnungen samt
 * Haeufigkeit und daneben eine Auswahlliste. Raten muss niemand.
 *
 * Ab 1.4.0 kommt 'verloren' dazu: welche Tonnen haette die eingebaute
 * Erkennung zusaetzlich gefunden, die durch eine Regel wegfallen. Damit ist
 * der stille Verlust sichtbar.
 */
function awm_titel_der_datei($cal = 1)
{
    $serien = function_exists('awm_series') ? awm_series($cal) : array();
    $zaehler = array();
    foreach ($serien as $s) {
        $t = trim((string) (isset($s['summary']) ? $s['summary'] : ''));
        if ($t === '') { continue; }
        if (!isset($zaehler[$t])) { $zaehler[$t] = 0; }
        $zaehler[$t]++;
    }
    arsort($zaehler);
    $regeln = awm_regeln($cal);
    $modus = awm_regeln_modus($cal);
    $out = array();
    foreach ($zaehler as $titel => $n) {
        $b = awm_bins_regeln($titel, $regeln, $modus);
        $treffer = array();
        foreach ($b as $k => $v) { if ($v) { $treffer[] = $k; } }
        // Woher kam die Zuordnung? Das ist der Unterschied zwischen
        // "ich habe das so eingestellt" und "das Plugin hat geraten".
        $quelle = 'keine';
        if ($treffer) {
            $glatt = awm_glatt($titel);
            foreach ($regeln as $r) {
                if (awm_regel_trifft($r, $glatt)) { $quelle = 'regel'; break; }
            }
            if ($quelle === 'keine') { $quelle = 'eingebaut'; }
        }
        // Was faellt durch die Regel weg?
        $verloren = array();
        if ($quelle === 'regel' && $modus === 'ersetzen') {
            foreach (awm_bins_eingebaut($titel) as $k => $v) {
                if ($v && empty($b[$k])) { $verloren[] = $k; }
            }
        }
        $out[] = array('titel' => $titel, 'anzahl' => $n,
                       'tonnen' => $treffer, 'quelle' => $quelle,
                       'verloren' => $verloren);
    }
    return $out;
}

/* ==================================================================
 * Wiederholungsregeln
 *
 * Unterstuetzt werden die Formen, die in Abfuhrkalendern wirklich
 * vorkommen. Alles andere wird NICHT geraten, sondern als "nur
 * Basistermin" behandelt und im Reiter Test benannt - eine erfundene
 * Wiederholung waere schlimmer als eine fehlende.
 *
 * Grundlage: RFC 5545, Abschnitt 3.3.10 (Recurrence Rule).
 * ================================================================== */

/** Wochentagskuerzel nach RFC 5545 -> 1 (Montag) bis 7 (Sonntag). */
function awm_wochentag_nr($kuerzel)
{
    $m = array('MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4,
               'FR' => 5, 'SA' => 6, 'SU' => 7);
    $k = strtoupper(substr((string) $kuerzel, -2));
    return isset($m[$k]) ? $m[$k] : 0;
}

/** Welche RRULE-Bestandteile kann dieses Plugin auswerten? */
function awm_rrule_bekannt()
{
    return array('FREQ', 'INTERVAL', 'UNTIL', 'COUNT', 'BYDAY',
                 'BYMONTHDAY', 'BYMONTH', 'BYSETPOS', 'WKST');
}

/** RRULE zerlegen. */
function awm_rrule_lesen($rrule)
{
    $r = array('freq' => null, 'interval' => 1, 'until' => '99991231',
               'count' => 0, 'byday' => array(), 'bymonthday' => array(),
               'bymonth' => array(), 'bysetpos' => array(), 'wkst' => 1);
    if (!is_string($rrule) || $rrule === '') { return $r; }
    if (preg_match('/FREQ=([A-Z]+)/i', $rrule, $m)) { $r['freq'] = strtoupper($m[1]); }
    if (preg_match('/INTERVAL=(\d+)/i', $rrule, $m)) { $r['interval'] = max(1, (int) $m[1]); }
    if (preg_match('/UNTIL=(\d{8})/i', $rrule, $m)) { $r['until'] = $m[1]; }
    if (preg_match('/COUNT=(\d+)/i', $rrule, $m)) { $r['count'] = max(0, (int) $m[1]); }
    if (preg_match('/WKST=([A-Z]{2})/i', $rrule, $m)) {
        $w = awm_wochentag_nr($m[1]);
        if ($w > 0) { $r['wkst'] = $w; }
    }
    if (preg_match('/BYDAY=([^;\r\n]+)/i', $rrule, $m)) {
        foreach (explode(',', $m[1]) as $t) {
            $t = trim($t);
            if ($t === '') { continue; }
            // Form: [+|-]n TAG, etwa "2TH" (zweiter Donnerstag) oder "-1FR"
            $pos = 0;
            if (preg_match('/^([+-]?\d+)([A-Za-z]{2})$/', $t, $mm)) {
                $pos = (int) $mm[1];
                $tag = awm_wochentag_nr($mm[2]);
            } else {
                $tag = awm_wochentag_nr($t);
            }
            if ($tag > 0) { $r['byday'][] = array('tag' => $tag, 'pos' => $pos); }
        }
    }
    if (preg_match('/BYMONTHDAY=([^;\r\n]+)/i', $rrule, $m)) {
        foreach (explode(',', $m[1]) as $t) {
            $t = (int) trim($t);
            if ($t !== 0) { $r['bymonthday'][] = $t; }
        }
    }
    if (preg_match('/BYMONTH=([^;\r\n]+)/i', $rrule, $m)) {
        foreach (explode(',', $m[1]) as $t) {
            $t = (int) trim($t);
            if ($t >= 1 && $t <= 12) { $r['bymonth'][] = $t; }
        }
    }
    if (preg_match('/BYSETPOS=([^;\r\n]+)/i', $rrule, $m)) {
        foreach (explode(',', $m[1]) as $t) {
            $t = (int) trim($t);
            if ($t !== 0) { $r['bysetpos'][] = $t; }
        }
    }
    return $r;
}

/**
 * Welche Bestandteile einer RRULE versteht das Plugin NICHT?
 *
 * Fuer die Kalenderdiagnose im Reiter Test: eine Regel, die stillschweigend
 * halb ausgewertet wird, ist genau die Sorte Fehler, die diese Datei
 * vermeiden soll. Was hier herauskommt, wird angezeigt - nicht verschwiegen.
 */
function awm_rrule_unbekannt($rrule)
{
    $bekannt = awm_rrule_bekannt();
    $out = array();
    foreach (explode(';', (string) $rrule) as $teil) {
        $teil = trim($teil);
        if ($teil === '') { continue; }
        $gleich = strpos($teil, '=');
        $name = strtoupper(trim($gleich === false ? $teil : substr($teil, 0, $gleich)));
        if ($name === '') { continue; }
        if (!in_array($name, $bekannt, true) && !in_array($name, $out, true)) {
            $out[] = $name;
        }
    }
    return $out;
}

/* ==================================================================
 * Datumsrechnung ohne DateTime
 *
 * awm_state() fragt jede Serie fuer jeden Tag des Vorschau-Fensters ab.
 * Bei 35 Tagen und einem Kalender mit 200 Terminen sind das 7000 Aufrufe
 * von awm_trifft() - und bis 1.2.0 legte JEDER davon zwei DateTime-Objekte
 * an und liess ein DateInterval berechnen. Das sind 14000 Objekte fuer
 * eine Rechnung, deren Ergebnis sich auf 36 verschiedene Datumswerte
 * beschraenkt.
 *
 * awm_tagnummer() gibt die Tage seit dem 01.01.1970 zurueck und merkt sich
 * jedes Datum. Damit bleiben je Durchlauf so viele Umrechnungen uebrig,
 * wie es verschiedene Datumswerte gibt - beim obigen Beispiel 36 statt
 * 14000.
 *
 * gmmktime() statt mktime(): die Sommerzeit-Umstellung darf hier nicht
 * hineinspielen. Am 30.03. wuerde mktime() sonst einen Tag mit 23 Stunden
 * liefern, und die Division durch 86400 landete auf dem Vortag.
 * ================================================================== */

/** Tage seit dem 01.01.1970 fuer ein Datum im Format Ymd. -1 = ungueltig. */
function awm_tagnummer($ymd)
{
    static $merker = array();
    $ymd = (string) $ymd;
    if (isset($merker[$ymd])) {
        return $merker[$ymd];
    }
    if (!preg_match('/^\d{8}$/', $ymd)) {
        return $merker[$ymd] = -1;
    }
    $j = (int) substr($ymd, 0, 4);
    $m = (int) substr($ymd, 4, 2);
    $t = (int) substr($ymd, 6, 2);
    if ($m < 1 || $m > 12 || $t < 1 || $t > 31 || !checkdate($m, $t, $j)) {
        return $merker[$ymd] = -1;
    }
    return $merker[$ymd] = (int) floor(gmmktime(0, 0, 0, $m, $t, $j) / 86400);
}

/** Umkehrung: Datum (Ymd) aus einer Tagnummer. */
function awm_datum_aus_tagnummer($tagnr)
{
    return gmdate('Ymd', (int) $tagnr * 86400);
}

/** Wochentag 1 (Montag) bis 7 (Sonntag) aus einer Tagnummer.
 *  Tag 0 (01.01.1970) war ein Donnerstag - daher die 3. */
function awm_wochentag_aus_tagnummer($tagnr)
{
    return (int) ((($tagnr % 7) + 7 + 3) % 7) + 1;
}

/** Wie viele Tage hat der Monat dieses Datums? */
function awm_tage_im_monat($ymd)
{
    static $merker = array();
    $k = substr((string) $ymd, 0, 6);
    if (isset($merker[$k])) {
        return $merker[$k];
    }
    return $merker[$k] = (int) date('t', mktime(0, 0, 0, (int) substr($ymd, 4, 2), 1, (int) substr($ymd, 0, 4)));
}

/** Der wievielte Wochentag seiner Art ist dieses Datum im Monat? (1..5) */
function awm_wochentag_position($ymd)
{
    return (int) floor((((int) substr($ymd, 6, 2)) - 1) / 7) + 1;
}

/** Wie viele dieses Wochentags hat der Monat? */
function awm_wochentage_im_monat($ymd)
{
    $tage = awm_tage_im_monat($ymd);
    $tag = (int) substr($ymd, 6, 2);
    $rest = $tage - $tag;
    return awm_wochentag_position($ymd) + (int) floor($rest / 7);
}

/**
 * Welche Tage eines Monats trifft die Regel?
 *
 * Das ist die Stelle, an der BYSETPOS ueberhaupt erst moeglich wird: nach
 * RFC 5545 wird zuerst der komplette Kandidatensatz des Zeitraums gebildet
 * und ERST DANN daraus die n-te Stelle gezogen. Wer BYSETPOS Tag fuer Tag
 * auswerten will, kann es nicht - genau deshalb fiel es bis 1.3.8 unter
 * den Tisch, und "letzter Werktag des Monats" traf jeden Werktag.
 *
 * Rueckgabe: aufsteigende Liste von Tagen (1..31).
 */
function awm_monatsliste($s, $jahr, $monat)
{
    $erster = sprintf('%04d%02d01', $jahr, $monat);
    $tage = awm_tage_im_monat($erster);
    $n1 = awm_tagnummer($erster);
    if ($n1 < 0) { return array(); }
    $byday = isset($s['byday']) && is_array($s['byday']) ? $s['byday'] : array();
    $bymd = isset($s['bymonthday']) && is_array($s['bymonthday']) ? $s['bymonthday'] : array();
    $setpos = isset($s['bysetpos']) && is_array($s['bysetpos']) ? $s['bysetpos'] : array();

    $kand = array();
    if ($bymd) {
        foreach ($bymd as $t) {
            $d = $t > 0 ? $t : $tage + $t + 1;
            if ($d >= 1 && $d <= $tage) { $kand[$d] = true; }
        }
        // BYDAY und BYMONTHDAY zusammen: Schnittmenge (RFC 5545).
        if ($byday) {
            foreach (array_keys($kand) as $d) {
                $w = awm_wochentag_aus_tagnummer($n1 + $d - 1);
                $ok = false;
                foreach ($byday as $bd) { if ($bd['tag'] === $w) { $ok = true; break; } }
                if (!$ok) { unset($kand[$d]); }
            }
        }
    } elseif ($byday) {
        for ($d = 1; $d <= $tage; $d++) {
            $w = awm_wochentag_aus_tagnummer($n1 + $d - 1);
            $ymd = sprintf('%04d%02d%02d', $jahr, $monat, $d);
            foreach ($byday as $bd) {
                if ($bd['tag'] !== $w) { continue; }
                // Die Stellenangabe im BYDAY ("2TH") gilt nur, wenn kein
                // BYSETPOS danebensteht - sonst bildet BYSETPOS die Auswahl.
                if ($setpos || $bd['pos'] === 0) { $kand[$d] = true; break; }
                if ($bd['pos'] > 0 && awm_wochentag_position($ymd) === $bd['pos']) { $kand[$d] = true; break; }
                if ($bd['pos'] < 0) {
                    $vonhinten = awm_wochentage_im_monat($ymd) - awm_wochentag_position($ymd) + 1;
                    if ($vonhinten === -$bd['pos']) { $kand[$d] = true; break; }
                }
            }
        }
    } else {
        // Ohne BYDAY/BYMONTHDAY: derselbe Kalendertag wie der Basistermin.
        $d = (int) substr((string) $s['start'], 6, 2);
        if ($d >= 1 && $d <= $tage) { $kand[$d] = true; }
    }

    $liste = array_keys($kand);
    sort($liste);
    if (!$setpos) {
        return $liste;
    }
    $aus = array();
    $anz = count($liste);
    foreach ($setpos as $p) {
        $idx = $p > 0 ? $p - 1 : $anz + $p;
        if ($idx >= 0 && $idx < $anz) { $aus[$liste[$idx]] = true; }
    }
    $aus = array_keys($aus);
    sort($aus);
    return $aus;
}

/**
 * Trifft die reine Wiederholungsregel dieses Datum?
 *
 * OHNE EXDATE, OHNE RDATE, OHNE COUNT. Das ist Absicht: awm_serie_ende()
 * zaehlt damit die Termine der Regel, und nach RFC 5545 zaehlt COUNT die
 * von der Regel erzeugten Termine - ein EXDATE streicht einen davon, es
 * verlaengert die Serie aber nicht.
 */
function awm_regel_trifft_datum($s, $ymd)
{
    $start = (string) $s['start'];
    $freq = isset($s['freq']) ? $s['freq'] : null;
    if ($freq === null || $freq === '') {
        return $start === $ymd;                 // Einzeltermin
    }
    if ($ymd < $start) { return false; }
    $until = (string) (isset($s['until']) ? $s['until'] : '99991231');
    if ($ymd > $until) { return false; }

    $interval = max(1, (int) (isset($s['interval']) ? $s['interval'] : 1));
    $byday = isset($s['byday']) && is_array($s['byday']) ? $s['byday'] : array();
    $bymonth = isset($s['bymonth']) && is_array($s['bymonth']) ? $s['bymonth'] : array();

    $na = awm_tagnummer($start);
    $nb = awm_tagnummer($ymd);
    if ($na < 0 || $nb < 0) { return false; }
    $tage = $nb - $na;
    $wtag = awm_wochentag_aus_tagnummer($nb);
    $jahr = (int) substr($ymd, 0, 4);
    $monat = (int) substr($ymd, 4, 2);
    $tagimmonat = (int) substr($ymd, 6, 2);

    // BYMONTH gilt fuer alle Frequenzen. Bis 1.3.8 wurde es nirgends
    // gelesen: FREQ=MONTHLY;BYMONTH=3,9 traf jeden Monat.
    if ($bymonth && !in_array($monat, $bymonth, true)) {
        return false;
    }

    if ($freq === 'DAILY') {
        return ($tage % $interval) === 0;
    }

    if ($freq === 'WEEKLY') {
        // Ohne BYDAY: derselbe Wochentag wie der Basistermin - das ist das
        // Verhalten von 1.0.2 und bleibt Wort fuer Wort erhalten.
        if (!$byday) {
            if ($tage % 7 !== 0) { return false; }
            return ((int) ($tage / 7) % $interval) === 0;
        }
        $erlaubt = false;
        foreach ($byday as $d) { if ($d['tag'] === $wtag) { $erlaubt = true; break; } }
        if (!$erlaubt) { return false; }
        // Wochennummer seit dem Wochenbeginn des Starttermins. Der
        // Wochenbeginn ist Montag, sofern die Regel nichts anderes sagt
        // (WKST) - bei INTERVAL>=2 verschiebt ein WKST=SU sonst jede
        // zweite Woche. Der Muenchner Kalender schreibt WKST=MO aus; das
        // Ergebnis ist damit dasselbe wie in 1.3.8.
        $wkst = isset($s['wkst']) ? max(1, min(7, (int) $s['wkst'])) : 1;
        $wa = awm_wochentag_aus_tagnummer($na);
        $wochenanfang = $na - (($wa - $wkst + 7) % 7);
        $wochen = (int) floor(($nb - $wochenanfang) / 7);
        return ($wochen % $interval) === 0;
    }

    if ($freq === 'MONTHLY') {
        $monate = ($jahr - (int) substr($start, 0, 4)) * 12
                + ($monat - (int) substr($start, 4, 2));
        if ($monate < 0 || $monate % $interval !== 0) { return false; }
        return in_array($tagimmonat, awm_monatsliste($s, $jahr, $monat), true);
    }

    if ($freq === 'YEARLY') {
        $jahre = $jahr - (int) substr($start, 0, 4);
        if ($jahre < 0 || $jahre % $interval !== 0) { return false; }
        // Ohne BYMONTH gilt der Monat des Basistermins.
        if (!$bymonth && $monat !== (int) substr($start, 4, 2)) { return false; }
        // Mit BYDAY oder BYMONTHDAY entscheidet die Monatsliste - damit
        // kann YEARLY jetzt auch "zweiter Samstag im Maerz" (Schadstoff-
        // mobil). Bis 1.3.8 verglich der Zweig nur MMTT des Basistermins,
        // und der Termin verschwand ab dem zweiten Jahr.
        if ($byday || (isset($s['bymonthday']) && $s['bymonthday'])) {
            return in_array($tagimmonat, awm_monatsliste($s, $jahr, $monat), true);
        }
        return $tagimmonat === (int) substr($start, 6, 2);
    }

    // Unbekannte Frequenz: NICHT raten. Nur der Basistermin gilt.
    return $start === $ymd;
}

/**
 * Der letzte Termin, den die Regel erzeugt (Ymd), oder '99991231'.
 *
 * Gebraucht an zwei Stellen: fuer COUNT (siehe awm_trifft) und fuer die
 * Jahreswechsel-Warnung. Letztere sah bis 1.3.8 nur auf UNTIL:
 *
 *     $end = $s['freq'] === null ? $s['start'] : min($s['until'], '99991231');
 *
 * Eine Serie mit COUNT und ohne UNTIL behielt damit '99991231' und galt als
 * "reicht in die Zukunft" - auch wenn ihr letzter Termin gestern war.
 * Gemessen am 20.08.2026: ok=1, warnung=0, alle Tage-Zaehler -1. Der
 * Kalender lief leer, und weder Anwender noch Auto-Erneuerung erfuhren es.
 *
 * Die Suche laeuft Tag fuer Tag und ist deshalb hart begrenzt (Regel:
 * Schleifen brauchen eine Obergrenze). Sie kostet nur bei Serien mit COUNT
 * etwas, und das Ergebnis wird gemerkt.
 */
function awm_serie_ende($s)
{
    static $merker = array();
    $freq = isset($s['freq']) ? $s['freq'] : null;
    $start = (string) $s['start'];
    if ($freq === null || $freq === '') {
        return $start;
    }
    $count = (int) (isset($s['count']) ? $s['count'] : 0);
    $until = (string) (isset($s['until']) ? $s['until'] : '99991231');
    if ($count <= 0) {
        return $until;
    }
    $key = $start . '|' . $freq . '|' . (int) (isset($s['interval']) ? $s['interval'] : 1)
         . '|' . $until . '|' . $count
         . '|' . json_encode(isset($s['byday']) ? $s['byday'] : array())
         . '|' . json_encode(isset($s['bymonthday']) ? $s['bymonthday'] : array())
         . '|' . json_encode(isset($s['bymonth']) ? $s['bymonth'] : array())
         . '|' . json_encode(isset($s['bysetpos']) ? $s['bysetpos'] : array())
         . '|' . (int) (isset($s['wkst']) ? $s['wkst'] : 1);
    if (isset($merker[$key])) {
        return $merker[$key];
    }
    $n = awm_tagnummer($start);
    if ($n < 0) { return $merker[$key] = $start; }
    $gefunden = $start;
    $treffer = 0;
    for ($i = 0; $i < 4000; $i++) {          // harte Obergrenze, ~11 Jahre
        $ymd = awm_datum_aus_tagnummer($n + $i);
        if ($ymd > $until) { break; }
        if (awm_regel_trifft_datum($s, $ymd)) {
            $treffer++;
            $gefunden = $ymd;
            if ($treffer >= $count) { break; }
        }
    }
    return $merker[$key] = $gefunden;
}

/**
 * Faellt der Termin auf dieses Datum?
 *
 * $s ist eine Serie aus awm_series(): start, freq, interval, until,
 * exdates, rdates - ab 1.1.0 zusaetzlich count, byday, bymonthday, ab
 * 1.4.0 bymonth, bysetpos, wkst.
 */
function awm_trifft($s, $ymd)
{
    if (in_array($ymd, (array) (isset($s['exdates']) ? $s['exdates'] : array()), true)) {
        return false;
    }
    // RDATE: zusaetzliche Einzeltermine derselben Serie. Bis 1.3.8 wurden
    // sie gar nicht gelesen - genau die Form, in der manche Entsorger den
    // Ersatztermin nach einem Feiertag liefern. Die Tonne blieb stehen.
    // (Der Muenchner Kalender benutzt sie nicht; er legt fuer den Ersatz
    // einen eigenen "Achtung"-Einzeltermin an.)
    if (in_array($ymd, (array) (isset($s['rdates']) ? $s['rdates'] : array()), true)) {
        return true;
    }
    if (!awm_regel_trifft_datum($s, $ymd)) {
        return false;
    }
    $count = (int) (isset($s['count']) ? $s['count'] : 0);
    if ($count > 0 && $ymd > awm_serie_ende($s)) {
        return false;
    }
    return true;
}

/** COUNT beachten: der wievielte Termin ist das? (0 = der erste)
 *  Bleibt fuer Aufrufer aus aelteren Fassungen erhalten. */
function awm_count_ok($s, $nummer)
{
    $c = (int) (isset($s['count']) ? $s['count'] : 0);
    if ($c <= 0) { return true; }
    return $nummer < $c;
}

/* ==================================================================
 * Selbstpruefung
 *
 * Der wichtigste Teil ist der erste Block: er weist nach, dass die
 * Muenchner Erkennung sich NICHT geaendert hat.
 * ================================================================== */

function awm_selbstpruefung_regeln()
{
    $e = array();
    $p = function ($ok, $text) use (&$e) { $e[] = array($ok ? 1 : 0, $text); };

    /* --- 1. Muenchen bleibt unveraendert --- */
    $muenchen = array(
        'Restmülltonne'                  => 'rest',
        'Restmüll 14-täglich'            => 'rest',
        'Hausmüll'                       => 'rest',
        'Biotonne'                       => 'bio',
        'Papiertonne'                    => 'papier',
        'Wertstofftonne'                 => 'wert',
        'Gelbe Tonne'                    => 'wert',
        // Wie es im echten AWM-Export wirklich steht (Messung 20.08.2026):
        // mit Strassenname dahinter, beim Ersatztermin mit fuehrendem
        // Leerzeichen und dem Wort "Achtung:" davor.
        'Restmülltonne, Musterstraße 1'   => 'rest',
        ' Achtung: Biotonne, Musterstraße 1' => 'bio',
    );
    foreach ($muenchen as $titel => $soll) {
        $b = awm_bins_regeln($titel, array());          // ohne Zuordnung
        $treffer = array();
        foreach ($b as $k => $v) { if ($v) { $treffer[] = $k; } }
        $p(in_array($soll, $treffer, true),
           sprintf('Muenchen unveraendert: "%s" -> %s', trim($titel),
                   $treffer ? implode('+', $treffer) : 'nichts'));
    }
    $b = awm_bins_regeln('Weihnachtsbaum', array());
    $p(array_sum($b) === 0, 'Unbekannter Titel ohne Zuordnung trifft nichts');

    /* --- 1b. Die Muenchner Serienregel, wortgleich aus dem Export --- */
    $awm = array('start' => '20260112', 'exdates' => array('20261228', '20260406'), 'rdates' => array())
         + awm_rrule_lesen('FREQ=WEEKLY;INTERVAL=2;UNTIL=20261231;BYDAY=MO;WKST=MO');
    $p(awm_trifft($awm, '20260112') && awm_trifft($awm, '20260126') && awm_trifft($awm, '20260209'),
       'AWM-Serie: Restmuell alle zwei Wochen montags');
    $p(!awm_trifft($awm, '20260119'), 'AWM-Serie: die Woche dazwischen nicht');
    $p(!awm_trifft($awm, '20260406') && !awm_trifft($awm, '20261228'),
       'AWM-Serie: beide EXDATE-Termine fallen aus');
    $p(!awm_trifft($awm, '20270111'), 'AWM-Serie: nach UNTIL ist Schluss');

    /* --- 2. Das Namensproblem --- */
    $regeln = array(
        array('muster' => 'rm', 'tonne' => 'rest', 'art' => 'beginnt'),
        array('muster' => 'graue tonne', 'tonne' => 'rest', 'art' => 'enthaelt'),
        array('muster' => 'gruene tonne', 'tonne' => 'bio', 'art' => 'enthaelt'),
        array('muster' => 'altglas', 'tonne' => 'glas', 'art' => 'enthaelt'),
    );
    $faelle = array(
        'RM 14täg.'            => 'rest',
        'Graue Tonne - Tour B' => 'rest',
        'GRÜNE  TONNE'         => 'bio',
        'Altglas-Sammlung'     => 'glas',
    );
    foreach ($faelle as $titel => $soll) {
        $b = awm_bins_regeln($titel, $regeln);
        $p(!empty($b[$soll]), sprintf('Zuordnung greift: "%s" -> %s', $titel, $soll));
    }
    // Diese drei haetten in 1.0.2 nichts getroffen - das ist der Gewinn.
    $p(array_sum(awm_bins_eingebaut('RM 14täg.')) === 0,
       'Gegenprobe: "RM 14taeg." traf in 1.0.2 wirklich nichts');
    $p(array_sum(awm_bins_eingebaut('Graue Tonne - Tour B')) === 0,
       'Gegenprobe: "Graue Tonne - Tour B" traf in 1.0.2 wirklich nichts');

    // Die Regel gewinnt gegen die eingebaute Erkennung.
    $b = awm_bins_regeln('Biotonne', array(array('muster' => 'biotonne', 'tonne' => 'gruen', 'art' => 'genau')));
    $p(!empty($b['gruen']) && empty($b['bio']),
       'Eine Regel ueberschreibt die eingebaute Erkennung');

    // ... und im Modus "ergaenzen" nicht (ab 1.4.0).
    $reg1 = array(array('muster' => 'papier', 'tonne' => 'papier', 'art' => 'enthaelt'));
    $b1 = awm_bins_regeln('Papier und Biotonne', $reg1, 'ersetzen');
    $b2 = awm_bins_regeln('Papier und Biotonne', $reg1, 'ergaenzen');
    $p(!empty($b1['papier']) && empty($b1['bio']),
       'Modus "ersetzen": die eingebaute Bio-Erkennung faellt weg (wie bisher)');
    $p(!empty($b2['papier']) && !empty($b2['bio']),
       'Modus "ergaenzen": Bio bleibt erhalten');

    // Ein Titel, zwei Tonnen
    $b = awm_bins_regeln('Papier und Gelber Sack', array(
        array('muster' => 'papier', 'tonne' => 'papier', 'art' => 'enthaelt'),
        array('muster' => 'gelber sack', 'tonne' => 'wert', 'art' => 'enthaelt')));
    $p(!empty($b['papier']) && !empty($b['wert']), 'Ein Titel kann zwei Tonnen setzen');

    $p(awm_glatt('  GRÜNE   Tonne  ') === 'gruene tonne', 'Glaettung: Umlaut, Grosschreibung, Leerraum');
    $p(awm_glatt('Straße') === 'strasse', 'Glaettung: scharfes s');
    // Der Fall, der ohne mbstring einen Leerstring lieferte (gemessen
    // 20.08.2026 auf PHP 7.4.33): ein Nicht-ASCII-Zeichen ausserhalb der
    // Umlaut-Tabelle.
    $p(awm_glatt('Café-Tonne') !== '', 'Glaettung: fremdes Zeichen zerstoert den Text nicht');
    $p(strpos(awm_glatt('Café-Tonne'), 'tonne') !== false,
       'Glaettung: der Rest des Titels bleibt durchsuchbar');

    /* --- 3. Wiederholungsregeln --- */
    $w = function ($rrule, $start, $ymd) {
        $r = awm_rrule_lesen($rrule);
        $s = array('start' => $start, 'exdates' => array(), 'rdates' => array()) + $r;
        return awm_trifft($s, $ymd);
    };

    // WEEKLY wie bisher (Muenchen)
    $p($w('FREQ=WEEKLY', '20260105', '20260112'), 'WEEKLY: eine Woche spaeter');
    $p(!$w('FREQ=WEEKLY', '20260105', '20260113'), 'WEEKLY: anderer Wochentag nicht');
    $p($w('FREQ=WEEKLY;INTERVAL=2', '20260105', '20260119'), 'WEEKLY INTERVAL=2: zwei Wochen');
    $p(!$w('FREQ=WEEKLY;INTERVAL=2', '20260105', '20260112'), 'WEEKLY INTERVAL=2: eine Woche nicht');
    $p(!$w('FREQ=WEEKLY;UNTIL=20260201', '20260105', '20260209'), 'UNTIL wird beachtet');

    // WEEKLY mit BYDAY - das konnte 1.0.2 nicht
    $p($w('FREQ=WEEKLY;BYDAY=MO,TH', '20260105', '20260108'), 'WEEKLY BYDAY: Montag und Donnerstag');
    $p(!$w('FREQ=WEEKLY;BYDAY=MO,TH', '20260105', '20260107'), 'WEEKLY BYDAY: Mittwoch nicht');

    // MONTHLY - in 1.0.2 fiel hier ALLES ausser dem Basistermin weg
    $p($w('FREQ=MONTHLY;BYDAY=2TH', '20260108', '20260212'),
       'MONTHLY BYDAY=2TH: zweiter Donnerstag im Februar');
    $p(!$w('FREQ=MONTHLY;BYDAY=2TH', '20260108', '20260205'),
       'MONTHLY BYDAY=2TH: erster Donnerstag nicht');
    $p($w('FREQ=MONTHLY;BYDAY=-1FR', '20260130', '20260227'),
       'MONTHLY BYDAY=-1FR: letzter Freitag im Februar');
    $p($w('FREQ=MONTHLY;BYMONTHDAY=15', '20260115', '20260315'), 'MONTHLY BYMONTHDAY=15');
    $p(!$w('FREQ=MONTHLY;BYMONTHDAY=15', '20260115', '20260316'), 'MONTHLY BYMONTHDAY: anderer Tag nicht');
    $p($w('FREQ=MONTHLY;INTERVAL=3;BYMONTHDAY=1', '20260101', '20260401'), 'MONTHLY INTERVAL=3');
    $p(!$w('FREQ=MONTHLY;INTERVAL=3;BYMONTHDAY=1', '20260101', '20260301'), 'MONTHLY INTERVAL=3: dazwischen nicht');
    $p($w('FREQ=MONTHLY;BYMONTHDAY=-1', '20260131', '20260228'),
       'MONTHLY BYMONTHDAY=-1: der letzte Tag des Monats');

    // YEARLY
    $p($w('FREQ=YEARLY', '20260320', '20270320'), 'YEARLY: gleicher Tag im Folgejahr');
    $p(!$w('FREQ=YEARLY', '20260320', '20270321'), 'YEARLY: anderer Tag nicht');

    /* --- 3b. Die drei Formen, die bis 1.3.8 still danebengingen --- */

    // BYSETPOS: "letzter Werktag des Monats". Gemessen am 20.08.2026:
    // 20 Treffer im Februar 2026 statt einem.
    $bs = 'FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1';
    $n = 0;
    for ($d = 1; $d <= 28; $d++) {
        if ($w($bs, '20260130', sprintf('202602%02d', $d))) { $n++; }
    }
    $p($n === 1, sprintf('BYSETPOS=-1: genau ein Termin im Februar 2026 (gezaehlt: %d)', $n));
    $p($w($bs, '20260130', '20260227'), 'BYSETPOS=-1: das ist der 27.02.2026 (Freitag)');
    $p($w('FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=1', '20260101', '20260302'),
       'BYSETPOS=1: erster Werktag im Maerz 2026 ist der 02.03.');

    // BYMONTH: wurde gar nicht gelesen.
    $p($w('FREQ=MONTHLY;BYMONTH=3,9;BYMONTHDAY=15', '20260315', '20260915'),
       'BYMONTH=3,9: der September zaehlt');
    $p(!$w('FREQ=MONTHLY;BYMONTH=3,9;BYMONTHDAY=15', '20260315', '20260415'),
       'BYMONTH=3,9: der April nicht mehr');

    // YEARLY mit BYDAY: verschwand ab dem zweiten Jahr.
    $p($w('FREQ=YEARLY;BYMONTH=3;BYDAY=2SA', '20260314', '20270313'),
       'YEARLY BYDAY=2SA: zweiter Samstag im Maerz 2027');
    $p(!$w('FREQ=YEARLY;BYMONTH=3;BYDAY=2SA', '20260314', '20270306'),
       'YEARLY BYDAY=2SA: der erste Samstag nicht');

    // WKST bei INTERVAL>=2
    $p($w('FREQ=WEEKLY;INTERVAL=2;BYDAY=SU;WKST=SU', '20260104', '20260118'),
       'WKST=SU: jede zweite Woche ab Sonntag');

    // COUNT
    $s = array('start' => '20260105', 'exdates' => array()) + awm_rrule_lesen('FREQ=WEEKLY;COUNT=3');
    $p(awm_trifft($s, '20260119') && !awm_trifft($s, '20260126'), 'COUNT=3 endet nach dem dritten Termin');
    $p(awm_serie_ende($s) === '20260119', 'COUNT=3: der letzte Termin ist der 19.01.2026');
    // Ein EXDATE verlaengert die Serie NICHT (RFC 5545: COUNT zaehlt die
    // von der Regel erzeugten Termine).
    $s2 = array('start' => '20260105', 'exdates' => array('20260112')) + awm_rrule_lesen('FREQ=WEEKLY;COUNT=3');
    $p(awm_serie_ende($s2) === '20260119', 'COUNT: ein EXDATE verlaengert die Serie nicht');
    // Und die Stelle, an der die Jahreswechsel-Warnung bis 1.3.8 blind war
    $p(awm_serie_ende($s) !== '99991231',
       'COUNT ohne UNTIL: das Serienende ist bekannt (Grundlage der Jahreswechsel-Warnung)');

    // EXDATE
    $s = array('start' => '20260105', 'exdates' => array('20260112')) + awm_rrule_lesen('FREQ=WEEKLY');
    $p(!awm_trifft($s, '20260112') && awm_trifft($s, '20260119'), 'EXDATE laesst genau einen Termin aus');

    // RDATE
    $s = array('start' => '20260105', 'exdates' => array(), 'rdates' => array('20260107'))
       + awm_rrule_lesen('FREQ=WEEKLY;COUNT=1');
    $p(awm_trifft($s, '20260107'), 'RDATE fuegt einen Zusatztermin hinzu');
    $s['exdates'] = array('20260107');
    $p(!awm_trifft($s, '20260107'), 'EXDATE schlaegt RDATE');

    // Unbekannte Frequenz wird nicht geraten
    $p($w('FREQ=HOURLY', '20260105', '20260105') && !$w('FREQ=HOURLY', '20260105', '20260106'),
       'Unbekannte Frequenz: nur der Basistermin, nichts geraten');
    $p(awm_rrule_unbekannt('FREQ=MONTHLY;BYSETPOS=-1;BYWEEKNO=3') === array('BYWEEKNO'),
       'Unbekannte RRULE-Bestandteile werden benannt, nicht verschwiegen');
    $p(awm_rrule_unbekannt('FREQ=WEEKLY;INTERVAL=2;UNTIL=20261231;BYDAY=MO;WKST=MO') === array(),
       'Die Muenchner RRULE wird vollstaendig verstanden');

    /* --- 4. Die Datumsrechnung ohne DateTime (ab 1.3.0) --- */
    $abweichung = 0;
    $wtagfehler = 0;
    foreach (array('20260325', '20261024', '20260101', '20260228', '20240229') as $anker) {
        $ts = mktime(12, 0, 0, (int) substr($anker, 4, 2), (int) substr($anker, 6, 2), (int) substr($anker, 0, 4));
        for ($i = 0; $i <= 20; $i++) {
            $tag = date('Ymd', $ts + $i * 86400);
            $soll = (int) round((strtotime(date('Y-m-d', $ts + $i * 86400) . ' 12:00:00')
                               - strtotime(date('Y-m-d', $ts) . ' 12:00:00')) / 86400);
            if (awm_tagnummer($tag) - awm_tagnummer($anker) !== $soll) { $abweichung++; }
            if (awm_wochentag_aus_tagnummer(awm_tagnummer($tag)) !== (int) date('N', $ts + $i * 86400)) { $wtagfehler++; }
        }
    }
    $p($abweichung === 0, 'Tagesabstand ohne DateTime: 105 Vergleiche, auch ueber beide Zeitumstellungen');
    $p($wtagfehler === 0, 'Wochentag ohne DateTime: 105 Vergleiche gegen date(N)');
    $p(awm_tagnummer('20260230') === -1 && awm_tagnummer('nonsens') === -1,
       'Unmoegliches Datum wird als ungueltig gemeldet, nicht geraten');
    $p(awm_tage_im_monat('20240201') === 29 && awm_tage_im_monat('20230201') === 28,
       'Schaltjahr wird erkannt');
    $p(awm_datum_aus_tagnummer(awm_tagnummer('20260821')) === '20260821',
       'Tagnummer und Datum sind umkehrbar');
    $p($w('FREQ=WEEKLY', '20260323', '20260330') && $w('FREQ=WEEKLY', '20260323', '20260406'),
       'WEEKLY ueber die Sommerzeit-Umstellung hinweg');
    $p($w('FREQ=WEEKLY', '20261019', '20261026'), 'WEEKLY ueber die Winterzeit-Umstellung hinweg');
    $p($w('FREQ=WEEKLY;INTERVAL=2', '20260323', '20260406')
       && !$w('FREQ=WEEKLY;INTERVAL=2', '20260323', '20260330'),
       'WEEKLY INTERVAL=2 ueber die Umstellung: nur jede zweite Woche');

    $p(awm_wochentag_nr('2TH') === 4 && awm_wochentag_nr('SU') === 7, 'Wochentagskuerzel richtig gelesen');
    $r = awm_rrule_lesen('FREQ=MONTHLY;INTERVAL=2;BYDAY=1MO,3WE;UNTIL=20261231;COUNT=5;BYSETPOS=-1;BYMONTH=4;WKST=SU');
    $p($r['freq'] === 'MONTHLY' && $r['interval'] === 2 && count($r['byday']) === 2
       && $r['until'] === '20261231' && $r['count'] === 5
       && $r['bysetpos'] === array(-1) && $r['bymonth'] === array(4) && $r['wkst'] === 7,
       'RRULE vollstaendig zerlegt (inkl. BYSETPOS, BYMONTH, WKST)');

    /* --- 5. Alle acht Tonnenarten sind auslieferbar (ab 1.4.0) --- */
    $arten = awm_tonnenarten();
    $kuerzel = awm_tonnen_kuerzel();
    $fehlt = array();
    foreach (array_keys($arten) as $k) {
        if (!isset($kuerzel[$k])) { $fehlt[] = $k; }
    }
    $p(!$fehlt, 'Jede Tonnenart hat ein Feldkuerzel fuer Loxone und MQTT'
                . ($fehlt ? ' - fehlt: ' . implode(', ', $fehlt) : ''));
    $p(count($arten) === 8, 'Acht Tonnenarten vorhanden');

    return $e;
}
