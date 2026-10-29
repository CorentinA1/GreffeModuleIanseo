<?php
/**
 * plan.php — GREFFE : plan de cible avec les blasons, en codes courts (PDF).
 *
 *   ?depart=<n>     le plan d'un départ (défaut : tous les départs)
 *
 * Même état qu'IANSEO (Participants › Impressions › Liste par cible « + Type de cible »,
 * Partecipants/PrnSession.php?tf=1), mais le libellé du blason, illisible dans sa colonne étroite,
 * est remplacé par un code tiré du blason physique : « 60 » pour un blason unique de 60 cm,
 * « T40 (CL) » / « T40 (CO) » pour un trispot classique / poulie. Légende en fin de document.
 */
define('ENG_ACCESS', 'read');
require_once __DIR__ . '/lib/boot.php';
require_once $CFG->DOCUMENT_PATH . 'Common/pdf/ResultPDF.inc.php';
require_once $CFG->DOCUMENT_PATH . 'Common/Fun_FormatText.inc.php';
require_once $CFG->DOCUMENT_PATH . 'Common/OrisFunctions.php';
require_once $CFG->DOCUMENT_PATH . 'Common/pdf/PdfChunkLoader.php';

$tour   = eng_tournament();
$depart = (string) ($_GET['depart'] ?? '');
$scope  = ctype_digit($depart) && (int) $depart >= 1 && (int) $depart <= $tour->Sessions ? (int) $depart : 0;

// Paramètres lus par la requête et l'état IANSEO : un départ ou tous, colonne des blasons.
unset($_REQUEST['Session'], $_REQUEST['Empty'], $_REQUEST['Filled'], $_REQUEST['EnCodes']);
if ($scope) $_REQUEST['Session'] = $scope;
$_REQUEST['tf'] = 1;

/**
 * Code court d'un blason, d'après son type (table Targets) et son diamètre :
 * blason unique → diamètre seul (« 60 ») ; trispot → « T40 (CL) » ou « T40 (CO) » ;
 * blason extérieur poulies → « 80 (CO) ». Autre type ou diamètre inconnu : le libellé.
 */
function eng_face_code($type, $diam, $name)
{
    $type = intval($type);
    $diam = intval($diam);
    if ($diam <= 0) return $name;
    switch ($type) {
        case 1: case 3: case 5: case 7: return (string) $diam;   // TrgIndComplete, TrgCOIndComplete, TrgOutdoor, TrgHMOutComplete
        case 2: return 'T' . $diam . ' (CL)';                    // TrgIndSmall
        case 4: return 'T' . $diam . ' (CO)';                    // TrgCOIndSmall
        case 9: return $diam . ' (CO)';                          // TrgCOOutdoor
    }
    return $name;
}

// Blason de chaque place (départ, cible, lettre) : la requête de l'état ne donne que le libellé.
$faces = [];
$rs = safe_r_sql("SELECT QuSession, QuTarget, QuLetter, TfName, TfT1, TfW1
    FROM Qualifications
    INNER JOIN Entries ON EnId = QuId AND EnTournament = " . StrSafe_DB($ENG_TOUR) . "
    INNER JOIN TargetFaces ON TfTournament = EnTournament AND TfId = EnTargetFace
    WHERE QuSession > 0");
while ($r = safe_fetch($rs)) {
    $faces[$r->QuSession . '|' . intval($r->QuTarget) . '|' . $r->QuLetter] = $r;
}

$PdfData = getStartList();

// Libellés remplacés par les codes ; la légende garde chaque libellé derrière son code.
$legend = [];
foreach ($PdfData->Data['Items'] as $ev => $rows) {
    foreach ($rows as $i => $row) {
        $f = $faces[$row->Session . '|' . intval(substr($row->TargetNo, 0, -1)) . '|' . substr($row->TargetNo, -1)] ?? null;
        if (!$f || !$row->Athlete) continue;
        $name = get_text($f->TfName, 'Tournament', '', true);
        $code = eng_face_code($f->TfT1, $f->TfW1, $name);
        $PdfData->Data['Items'][$ev][$i]->TfName = $code;
        if ($code !== $name) $legend[$code][$name] = true;
    }
}

$pdf = new ResultPDF($PdfData->Description);
require_once(PdfChunkLoader('StartList.inc.php'));

if ($legend) {
    uksort($legend, 'strnatcmp');
    if (!$pdf->SamePage(8 + 4 * count($legend))) $pdf->AddPage();
    $pdf->SetY($pdf->GetY() + 4);
    $pdf->SetFont($pdf->FontStd, 'B', 9);
    $pdf->Cell(190, 5, 'Blasons', 0, 1, 'L');
    foreach ($legend as $code => $names) {
        $pdf->SetFont($pdf->FontStd, 'B', 8);
        $pdf->Cell(18, 4, $code, 0, 0, 'L');
        $pdf->SetFont($pdf->FontStd, '', 8);
        $pdf->Cell(172, 4, implode(' · ', array_keys($names)), 0, 1, 'L');
    }
}

$pdf->Output('plan-cibles-blasons' . ($scope ? '_depart-' . $scope : '') . '.pdf', 'I');
