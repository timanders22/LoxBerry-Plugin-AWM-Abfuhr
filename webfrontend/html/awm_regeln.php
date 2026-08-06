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
 * Absicht, sondern wird von awm_selbstpruefung_regeln() nachgewiesen.
 */

/* ==================================================================
 * Tonnenarten
 *
 * Die vier alten bleiben in ihrer Reihenfolge und mit ihren Schluesseln -
 * daran haengen Konfiguration, MQTT-Themen und Loxone-Bausteine. Neue
 * kommen nur hinten dazu.
 * ================================================================== */

function awm_tonnenarten()
{
    return array(
        'rest'   => array('text' => 'Restmüll',       'alt' => 1),
        'bio'    => array('text' => 'Biotonne',       'alt' => 1),
        'papier' => array('text' => 'Papier',         'alt' => 1),
        'wert'   => array('text' => 'Wertstoff/Gelb', 'alt' => 1),
        'glas'   => array('text' => 'Glas',           'alt' => 0),
        'sperr'  => array('text' => 'Sperrmüll',      'alt' => 0),
        'gruen'  => array('text' => 'Grünschnitt',    'alt' => 0),
        'schad'  => array('text' => 'Schadstoffe',    'alt' => 0),
    );
}

/** Nur die vier Tonnen, die es schon in 1.0.2 gab. */
function awm_tonnen_alt()
{
    $out = array();
    foreach (awm_tonnenarten() as $k => $v) {
        if (!empty($v['alt'])) { $out[] = $k; }
    }
    return $out;
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
    $l = function_exists('mb_strtolower') ? mb_strtolower($summary, 'UTF-8') : strtolower($summary);
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

function awm_glatt($s)
{
    // Umlaute ZUERST und in BEIDEN Schreibweisen aufloesen.
    //
    // Der Grund ist unangenehm: ohne die Erweiterung mbstring laesst
    // strtolower() ein 'Ü' unveraendert - es arbeitet byteweise und kennt
    // UTF-8 nicht. Wer erst kleinschreibt und dann nur nach 'ü' sucht,
    // findet auf so einem System nichts. LoxBerry bringt mbstring
    // ueblicherweise mit, aber "ueblicherweise" ist keine Zusicherung.
    $l = strtr((string) $s, array(
        'ä' => 'ae', 'Ä' => 'ae', 'ö' => 'oe', 'Ö' => 'oe',
        'ü' => 'ue', 'Ü' => 'ue', 'ß' => 'ss',
    ));
    $l = function_exists('mb_strtolower') ? mb_strtolower($l, 'UTF-8') : strtolower($l);
    $l = preg_replace('/\s+/u', ' ', $l);
    return trim((string) $l);
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
 * zuordnet, ueberschreibt bewusst.
 */
function awm_bins_regeln($summary, $regeln)
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
    if ($getroffen) {
        return $b;
    }
    return awm_bins_eingebaut($summary);
}

/**
 * Welche Titel stehen wirklich in der geholten Datei?
 *
 * Das ist die Antwort auf "welches Wort steht bei mir fuer welche Tonne":
 * die Oberflaeche zeigt die tatsaechlich vorkommenden Bezeichnungen samt
 * Haeufigkeit und daneben eine Auswahlliste. Raten muss niemand.
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
    $out = array();
    foreach ($zaehler as $titel => $n) {
        $b = awm_bins_regeln($titel, $regeln);
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
        $out[] = array('titel' => $titel, 'anzahl' => $n,
                       'tonnen' => $treffer, 'quelle' => $quelle);
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

/** RRULE zerlegen. Rueckgabe: array mit freq, interval, until, count, byday, bymonthday. */
function awm_rrule_lesen($rrule)
{
    $r = array('freq' => null, 'interval' => 1, 'until' => '99991231',
               'count' => 0, 'byday' => array(), 'bymonthday' => array());
    if (!is_string($rrule) || $rrule === '') { return $r; }
    if (preg_match('/FREQ=([A-Z]+)/i', $rrule, $m)) { $r['freq'] = strtoupper($m[1]); }
    if (preg_match('/INTERVAL=(\d+)/i', $rrule, $m)) { $r['interval'] = max(1, (int) $m[1]); }
    if (preg_match('/UNTIL=(\d{8})/i', $rrule, $m)) { $r['until'] = $m[1]; }
    if (preg_match('/COUNT=(\d+)/i', $rrule, $m)) { $r['count'] = max(0, (int) $m[1]); }
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
    return $r;
}

/** Der wievielte Wochentag seiner Art ist dieses Datum im Monat? (1..5) */
function awm_wochentag_position($ymd)
{
    return (int) floor((((int) substr($ymd, 6, 2)) - 1) / 7) + 1;
}

/** Wie viele dieses Wochentags hat der Monat? */
function awm_wochentage_im_monat($ymd)
{
    $jahr = (int) substr($ymd, 0, 4);
    $monat = (int) substr($ymd, 4, 2);
    $tage = (int) date('t', mktime(0, 0, 0, $monat, 1, $jahr));
    $tag = (int) substr($ymd, 6, 2);
    $rest = $tage - $tag;
    return awm_wochentag_position($ymd) + (int) floor($rest / 7);
}

/**
 * Faellt der Termin auf dieses Datum?
 *
 * $s ist eine Serie aus awm_series(): start, freq, interval, until,
 * exdates - ab 1.1.0 zusaetzlich count, byday, bymonthday.
 */
function awm_trifft($s, $ymd)
{
    if (in_array($ymd, (array) (isset($s['exdates']) ? $s['exdates'] : array()), true)) {
        return false;
    }
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
    $bymonthday = isset($s['bymonthday']) && is_array($s['bymonthday']) ? $s['bymonthday'] : array();

    $a = DateTime::createFromFormat('Ymd', $start);
    $b = DateTime::createFromFormat('Ymd', $ymd);
    if (!$a || !$b) { return false; }
    $a->setTime(0, 0, 0);
    $b->setTime(0, 0, 0);
    $tage = (int) $a->diff($b)->format('%a');
    $wtag = (int) $b->format('N');

    if ($freq === 'DAILY') {
        if ($tage % $interval !== 0) { return false; }
        return awm_count_ok($s, (int) ($tage / $interval));
    }

    if ($freq === 'WEEKLY') {
        // Ohne BYDAY: derselbe Wochentag wie der Basistermin - das ist das
        // Verhalten von 1.0.2 und bleibt Wort fuer Wort erhalten.
        if (!$byday) {
            if ($tage % 7 !== 0) { return false; }
            if ((($tage / 7) % $interval) !== 0) { return false; }
            return awm_count_ok($s, (int) ($tage / 7 / $interval));
        }
        $erlaubt = false;
        foreach ($byday as $d) { if ($d['tag'] === $wtag) { $erlaubt = true; break; } }
        if (!$erlaubt) { return false; }
        // Wochennummer seit dem Wochenbeginn des Starttermins
        $wochenanfang = clone $a;
        $wochenanfang->modify('-' . ((int) $a->format('N') - 1) . ' day');
        $wochen = (int) floor(((int) $wochenanfang->diff($b)->format('%a')) / 7);
        if ($wochen % $interval !== 0) { return false; }
        return awm_count_ok($s, (int) ($wochen / $interval));
    }

    if ($freq === 'MONTHLY') {
        $monate = ((int) substr($ymd, 0, 4) - (int) substr($start, 0, 4)) * 12
                + ((int) substr($ymd, 4, 2) - (int) substr($start, 4, 2));
        if ($monate < 0 || $monate % $interval !== 0) { return false; }
        if ($bymonthday) {
            $tag = (int) substr($ymd, 6, 2);
            $letzter = (int) date('t', $b->getTimestamp());
            $ok = false;
            foreach ($bymonthday as $t) {
                if ($t > 0 && $t === $tag) { $ok = true; break; }
                if ($t < 0 && ($letzter + $t + 1) === $tag) { $ok = true; break; }
            }
            if (!$ok) { return false; }
            return awm_count_ok($s, (int) ($monate / $interval));
        }
        if ($byday) {
            $ok = false;
            foreach ($byday as $d) {
                if ($d['tag'] !== $wtag) { continue; }
                if ($d['pos'] === 0) { $ok = true; break; }
                if ($d['pos'] > 0 && awm_wochentag_position($ymd) === $d['pos']) { $ok = true; break; }
                if ($d['pos'] < 0) {
                    $vonhinten = awm_wochentage_im_monat($ymd) - awm_wochentag_position($ymd) + 1;
                    if ($vonhinten === -$d['pos']) { $ok = true; break; }
                }
            }
            if (!$ok) { return false; }
            return awm_count_ok($s, (int) ($monate / $interval));
        }
        // Ohne BYDAY/BYMONTHDAY: derselbe Kalendertag wie der Basistermin.
        if (substr($ymd, 6, 2) !== substr($start, 6, 2)) { return false; }
        return awm_count_ok($s, (int) ($monate / $interval));
    }

    if ($freq === 'YEARLY') {
        $jahre = (int) substr($ymd, 0, 4) - (int) substr($start, 0, 4);
        if ($jahre < 0 || $jahre % $interval !== 0) { return false; }
        if (substr($ymd, 4, 4) !== substr($start, 4, 4)) { return false; }
        return awm_count_ok($s, (int) ($jahre / $interval));
    }

    // Unbekannte Frequenz: NICHT raten. Nur der Basistermin gilt.
    return $start === $ymd;
}

/** COUNT beachten: der wievielte Termin ist das? (0 = der erste) */
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
    );
    foreach ($muenchen as $titel => $soll) {
        $b = awm_bins_regeln($titel, array());          // ohne Zuordnung
        $treffer = array();
        foreach ($b as $k => $v) { if ($v) { $treffer[] = $k; } }
        $p(in_array($soll, $treffer, true),
           sprintf('Muenchen unveraendert: "%s" -> %s', $titel,
                   $treffer ? implode('+', $treffer) : 'nichts'));
    }
    $b = awm_bins_regeln('Weihnachtsbaum', array());
    $p(array_sum($b) === 0, 'Unbekannter Titel ohne Zuordnung trifft nichts');

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

    // Ein Titel, zwei Tonnen
    $b = awm_bins_regeln('Papier und Gelber Sack', array(
        array('muster' => 'papier', 'tonne' => 'papier', 'art' => 'enthaelt'),
        array('muster' => 'gelber sack', 'tonne' => 'wert', 'art' => 'enthaelt')));
    $p(!empty($b['papier']) && !empty($b['wert']), 'Ein Titel kann zwei Tonnen setzen');

    $p(awm_glatt('  GRÜNE   Tonne  ') === 'gruene tonne', 'Glaettung: Umlaut, Grosschreibung, Leerraum');
    $p(awm_glatt('Straße') === 'strasse', 'Glaettung: scharfes s');

    /* --- 3. Wiederholungsregeln --- */
    $w = function ($rrule, $start, $ymd) {
        $r = awm_rrule_lesen($rrule);
        $s = array('start' => $start, 'exdates' => array()) + $r;
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

    // YEARLY
    $p($w('FREQ=YEARLY', '20260320', '20270320'), 'YEARLY: gleicher Tag im Folgejahr');
    $p(!$w('FREQ=YEARLY', '20260320', '20270321'), 'YEARLY: anderer Tag nicht');

    // COUNT
    $s = array('start' => '20260105', 'exdates' => array()) + awm_rrule_lesen('FREQ=WEEKLY;COUNT=3');
    $p(awm_trifft($s, '20260119') && !awm_trifft($s, '20260126'), 'COUNT=3 endet nach dem dritten Termin');

    // EXDATE
    $s = array('start' => '20260105', 'exdates' => array('20260112')) + awm_rrule_lesen('FREQ=WEEKLY');
    $p(!awm_trifft($s, '20260112') && awm_trifft($s, '20260119'), 'EXDATE laesst genau einen Termin aus');

    // Unbekannte Frequenz wird nicht geraten
    $p($w('FREQ=HOURLY', '20260105', '20260105') && !$w('FREQ=HOURLY', '20260105', '20260106'),
       'Unbekannte Frequenz: nur der Basistermin, nichts geraten');

    $p(awm_wochentag_nr('2TH') === 4 && awm_wochentag_nr('SU') === 7, 'Wochentagskuerzel richtig gelesen');
    $r = awm_rrule_lesen('FREQ=MONTHLY;INTERVAL=2;BYDAY=1MO,3WE;UNTIL=20261231;COUNT=5');
    $p($r['freq'] === 'MONTHLY' && $r['interval'] === 2 && count($r['byday']) === 2
       && $r['until'] === '20261231' && $r['count'] === 5, 'RRULE vollstaendig zerlegt');

    return $e;
}
