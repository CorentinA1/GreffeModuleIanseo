<?php
/**
 * medailles.php — GREFFE : médailles or, argent, bronze à prévoir pour la remise des prix (PDF).
 *
 *   ?seul=0    pas de médaille pour un archer seul dans sa catégorie (défaut : il reçoit l'or)
 *
 * Nombre d'archers de chaque catégorie de la compétition (classe et arme, comme la liste par catégories), tous
 * départs confondus, placés ou non ; médailles par catégorie : lib/medals.php.
 */
define('ENG_ACCESS', 'read');
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/participants.php';
require_once __DIR__ . '/lib/medals.php';

$tour = eng_tournament();
$solo = ($_GET['seul'] ?? '') !== '0';
$med  = eng_medals_table(eng_part_load($ENG_TOUR, 0), $solo);
[$nArch, $gold, $silver, $bronze] = $med['total'];
$nCat = count($med['cats']);

$pdf = eng_pdf_new('Greffe - Médailles à prévoir');
$W = eng_pdf_width($pdf);
$pdf->SetFont($pdf->FontStd, 'B', 15);
$pdf->Cell($W, 9, 'Médailles à prévoir', 0, 1, 'L');
$pdf->SetFont($pdf->FontStd, '', 9);
$pdf->SetTextColor(90, 90, 90);
$pdf->MultiCell($W, 4.5, $nCat . ' catégorie' . ($nCat > 1 ? 's' : '') . ' · ' . $nArch . ' archer' . ($nArch > 1 ? 's' : '')
    . ' · édité le ' . eng_local_time(eng_db_now(), 'd/m/Y à H:i')
    . "\nUne médaille par place sur le podium, dans la limite des archers de la catégorie : l'or dès un archer, "
    . "l'argent dès deux, le bronze dès trois" . ($solo ? '.' : ' ; pas de médaille pour un archer seul dans sa catégorie.')
    . " Les égalités ne sont pas prévues.", 0, 'L');
$pdf->SetTextColor(0, 0, 0);
$pdf->SetY($pdf->GetY() + 3);

if (!$med['cats']) {
    $pdf->SetFont($pdf->FontStd, 'I', 10);
    $pdf->Cell($W, 8, 'Aucun archer inscrit.', 0, 1, 'L');
} else {
    eng_pdf_box($pdf, 'À commander : ' . $gold . ' or · ' . $silver . ' argent · ' . $bronze . ' bronze · '
        . ($gold + $silver + $bronze) . ' médailles au total', [255, 248, 225], 12);
    $pdf->SetY($pdf->GetY() + 3);
    $dash = function ($v) { return $v ? (string) $v : '—'; };
    $rows = [];
    foreach ($med['cats'] as [$cat, $label, $n, $o, $a, $b]) {
        $rows[] = [$cat, $label, (string) $n, $dash($o), $dash($a), $dash($b)];
    }
    eng_pdf_table($pdf, [['Catégorie', 12, 'L'], ['Libellé', 40, 'L'], ['Archers', 10, 'C'], ['Or', 9, 'C'], ['Argent', 9, 'C'], ['Bronze', 9, 'C']],
        $rows, ['Total', '', (string) $nArch, (string) $gold, (string) $silver, (string) $bronze], 'C');
}

$pdf->Output('medailles-a-prevoir.pdf', 'I');
