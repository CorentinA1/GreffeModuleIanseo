<?php
/**
 * plan-illustre.php — GREFFE : plan de cible illustré (PDF, A4 paysage, une page par départ, 18 cibles au plus par page).
 *
 *   ?depart=<n>     le plan d'un départ (défaut : tous les départs ayant au moins un archer placé)
 *
 * Chaque cible du départ est une butte carrée, à l'échelle (130 cm), avec les blasons à y poser, dessinés
 * d'après la table Targets d'IANSEO : un grand blason pour 4 archers (122, 80), un par colonne pour un 60,
 * un par archer pour un 40 ou un trispot. Disposition du plan de cible visuel (Modules/DragDropTarget) :
 * colonne A/C à gauche, B/D à droite. Cible sans archer : la butte seule. Pas de nom d'archer.
 * En-tête et pied de page des PDF d'IANSEO.
 */
define('ENG_ACCESS', 'read');
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/faces.php';
require_once $CFG->DOCUMENT_PATH . 'Common/pdf/ResultPDF.inc.php';
require_once $CFG->DOCUMENT_PATH . 'Common/Fun_FormatText.inc.php';


$tour   = eng_tournament();
$depart = (string) ($_GET['depart'] ?? '');
$scope  = ctype_digit($depart) && (int) $depart >= 1 && (int) $depart <= $tour->Sessions ? (int) $depart : 0;

// Départs : nom, cibles, archers par cible, horaire du premier tir.
$sessions = [];
$rs = safe_r_sql("SELECT SesOrder, SesName, SesTar4Session, SesFirstTarget, SesAth4Target,
        (SELECT MIN(CONCAT(DiDay, ' ', DiStart)) FROM DistanceInformation
            WHERE DiTournament = SesTournament AND DiSession = SesOrder AND DiType = 'Q' AND DiDay > 0) AS Start
    FROM Session WHERE SesTournament = " . StrSafe_DB($ENG_TOUR) . " AND SesType = 'Q'"
    . ($scope ? " AND SesOrder = " . intval($scope) : '') . " ORDER BY SesOrder");
while ($r = safe_fetch($rs)) {
    $first = max(1, intval($r->SesFirstTarget));
    $sessions[intval($r->SesOrder)] = ['name' => (string) $r->SesName, 'first' => $first,
        'last' => $first + max(0, intval($r->SesTar4Session)) - 1, 'ath' => intval($r->SesAth4Target) ?: 4, 'start' => (string) $r->Start, 'targets' => []];
}

// Distances de chaque catégorie (Participants › Distances) : toutes celles du concours (deux pour un TAE
// 2 × 70 m ou un 50 + 30 m), en mètres (TdDist), sinon tirées du libellé (Td « 15m-1 », niveaux FFTA) ; la
// règle de classes la plus précise l'emporte. Disciplines de parcours (campagne, nature, 3D : ToElabTeam 1 ou 2) :
// pas de distance, elle change à chaque cible.
$to = safe_fetch(safe_r_sql("SELECT ToType, ToNumDist, ToElabTeam FROM Tournament WHERE ToId = " . StrSafe_DB($ENG_TOUR)));
$distRules = [];
if ($to && !in_array(intval($to->ToElabTeam), [1, 2], true)) {
    $rs = safe_r_sql("SELECT * FROM TournamentDistances WHERE TdTournament = " . StrSafe_DB($ENG_TOUR)
        . " AND TdType = " . intval($to->ToType) . " ORDER BY LENGTH(TdClasses) DESC");
    while ($r = safe_fetch_assoc($rs)) {
        $d = [];
        for ($i = 1; $i <= max(1, min(8, intval($to->ToNumDist))); $i++) {
            $m = intval($r['TdDist' . $i]) ?: intval($r['Td' . $i]);
            if ($m > 0 && !in_array($m, $d, true)) $d[] = $m;
        }
        $like = '/^' . strtr(preg_quote($r['TdClasses'], '/'), ['%' => '.*', '_' => '.']) . '$/i';
        $distRules[] = [$like, $d];
    }
}

// Archers placés : blason (type, diamètre) et distances de chaque place.
$typeIds = [];
$rs = safe_r_sql("SELECT QuSession, QuTarget, QuLetter, TfT1, TfW1, TfName, CONCAT(TRIM(EnDivision), TRIM(EnClass)) AS Cat
    FROM Entries
    INNER JOIN Qualifications ON QuId = EnId
    LEFT JOIN TargetFaces ON TfTournament = EnTournament AND TfId = EnTargetFace
    WHERE EnTournament = " . StrSafe_DB($ENG_TOUR) . " AND EnAthlete = 1 AND QuSession > 0 AND QuTarget > 0"
    . ($scope ? " AND QuSession = " . intval($scope) : ''));
while ($r = safe_fetch($rs)) {
    $s = intval($r->QuSession);
    if (!isset($sessions[$s])) continue;
    $r->Dist = [];
    foreach ($distRules as [$like, $d]) {
        if (preg_match($like, $r->Cat)) { $r->Dist = $d; break; }
    }
    $sessions[$s]['targets'][intval($r->QuTarget)][$r->QuLetter] = $r;
    if ($r->TfT1) $typeIds[] = intval($r->TfT1);
}
$types = eng_face_types($typeIds);

if (!$scope) $sessions = array_filter($sessions, function ($s) { return $s['targets']; });

$pdf = new ResultPDF('Plan de cible illustré', false);
$breakMargin = $pdf->getBreakMargin();   // pied de page IANSEO : lu avant de couper le saut de page automatique
$pdf->SetAutoPageBreak(false);
$firstPage = true;
foreach ($sessions as $order => $ses) {
    if (!$firstPage) $pdf->AddPage();
    $firstPage = false;
    eng_plan_page($pdf, $order, $ses, $types, $breakMargin);
}

$pdf->Output('plan-cible-illustre' . ($scope ? '_depart-' . $scope : '') . '.pdf', 'I');
