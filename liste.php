<?php
/**
 * liste.php — GREFFE : liste papier des archers et de ce qu'ils doivent (PDF).
 *
 *   ?depart=all     une seule liste, tous départs confondus (défaut)
 *   ?depart=each    une liste par départ (une page par départ), puis les non placés
 *   ?depart=<n>     la liste d'un départ
 *   ?only=unpaid    seulement les archers qui ont encore quelque chose à régler (défaut : tous)
 *   ?sort=club      tri par club puis nom (défaut : nom)
 *
 * Par départ, un archer figure au départ où il règle, son premier départ ; ses départs suivants
 * le rappellent dans un encadré « déjà attendu » (lib/report.php, eng_greffe_list). Une colonne
 * par moyen de paiement, vide pour cocher le règlement à la main ; un paiement déjà enregistré y
 * porte sa date, dans la colonne de son moyen.
 */
define('ENG_ACCESS', 'read');
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/accounts.php';
require_once __DIR__ . '/lib/report.php';
require_once __DIR__ . '/lib/pdf.php';

$tour     = eng_tournament();
$methods  = eng_methods();
$accounts = eng_load_accounts($ENG_TOUR, eng_load_config());

$depart = (string) ($_GET['depart'] ?? 'all');
$scope  = $depart === 'each' ? 'each' : (ctype_digit($depart) && (int) $depart >= 1 && (int) $depart <= $tour->Sessions ? (int) $depart : 'all');
$only   = ($_GET['only'] ?? '') === 'unpaid' ? 'unpaid' : 'all';
$sort   = ($_GET['sort'] ?? '') === 'club' ? 'club' : 'name';

// Noms des départs (« Samedi matin »…) quand ils sont saisis dans IANSEO.
$sesNames = [];
$rs = safe_r_sql("SELECT SesOrder, SesName FROM Session WHERE SesTournament = " . StrSafe_DB($ENG_TOUR) . " AND SesType = 'Q'");
while ($r = safe_fetch($rs)) $sesNames[intval($r->SesOrder)] = trim((string) $r->SesName);

/** Titre d'une section : « Tous les départs », « Départ 2 — Samedi après-midi », « Archers non placés ». */
function eng_list_title($session, array $names)
{
    if ($session === null) return 'Tous les départs';
    if ($session === 0) return 'Archers non placés';
    return 'Départ ' . $session . (($names[$session] ?? '') !== '' ? ' — ' . $names[$session] : '');
}

/** Ce qui reste à régler, en clair : montant, « Réglé », « Exonéré », ou « — » (rien de dû, rien payé). */
function eng_list_left(array $a)
{
    if (in_array($a['status'], ['waived', 'exempt'], true)) return 'Exonéré';
    $left = eng_account_amounts($a)['left'];
    if ($left > 0) return eng_pdf_money($left);
    return $a['payment'] ? 'Réglé' : '—';
}

/**
 * Colonnes des moyens de paiement : vides si quelque chose reste dû (à cocher à la main), sinon la
 * date du paiement dans la colonne de son moyen.
 */
function eng_list_paid(array $a, array $methods)
{
    $cells = array_fill_keys(array_keys($methods), '');
    $p = $a['payment'];
    if ($p && eng_account_amounts($a)['left'] <= 0 && isset($cells[$p['EpMethod']])) {
        $cells[$p['EpMethod']] = eng_local_time($p['EpCreated'], 'd/m');
    }
    return array_values($cells);
}

$sections = eng_greffe_list($accounts, $scope, $only, $sort, $tour->Sessions);
$printed = eng_local_time(eng_db_now(), 'd/m/Y à H:i');

$pdf = eng_pdf_new('Greffe - Liste des archers');
foreach ($sections as $k => $sec) {
    if ($k > 0) {
        $pdf->startPageGroup();   // pagination propre à chaque départ
        $pdf->AddPage();
    }
    $pdf->SetFont($pdf->FontStd, 'B', 14);
    $pdf->Cell(eng_pdf_width($pdf), 8, 'Liste du greffe — ' . eng_list_title($sec['session'], $sesNames), 0, 1, 'L');
    $pdf->SetFont($pdf->FontStd, '', 8);
    $n = count($sec['rows']);
    $pdf->MultiCell(eng_pdf_width($pdf), 4, 'Édité le ' . $printed . ' · ' . $n . ' archer' . ($n > 1 ? 's' : '')
        . ($only === 'unpaid' ? ' ayant un reste à régler' : '') . ' · tri par ' . ($sort === 'club' ? 'club' : 'nom')
        . ($sec['session'] ? ' · chaque archer figure à son premier départ, où il règle' : '')
        . "\nÉtat à la date d'édition : les paiements enregistrés ensuite n'y figurent pas.", 0, 'L');
    $pdf->SetY($pdf->GetY() + 2);

    if (!$sec['rows']) {
        $pdf->SetFont($pdf->FontStd, 'I', 9);
        $pdf->Cell(eng_pdf_width($pdf), 8, 'Aucun archer à régler à ce départ.', 0, 1, 'L');
    } else {
        $rows = [];
        foreach ($sec['rows'] as $a) {
            $rows[] = array_merge([
                trim(mb_strtoupper($a['name']) . ' ' . $a['first']),
                $a['code'],
                trim($a['club_name'] . ($a['club_code'] !== '' ? ' (' . $a['club_code'] . ')' : '')),
                eng_sessions_text($a),
                implode(' / ', $a['cats']),
                eng_pdf_money($a['due']),
                eng_list_left($a),
            ], eng_list_paid($a, $methods));
        }
        $cols = [['Archer', 22, 'L'], ['Licence', 10, 'L'], ['Club', 20, 'L'], ['Départ(s)', 8, 'C'], ['Catégorie', 11, 'L'],
                 ['Dû', 9, 'R'], ['Reste', 9, 'R']];
        $short = ['cb' => 'CB'];   // « Carte bancaire » ne tient pas dans une colonne étroite
        foreach ($methods as $key => $label) $cols[] = [$short[$key] ?? $label, 8, 'C'];
        eng_pdf_table($pdf, $cols, $rows,
            array_merge(['Total', '', '', '', '', eng_pdf_money($sec['due']), eng_pdf_money($sec['left'])], array_fill(0, count($methods), '')), 'C');
    }

    if ($sec['also']) {
        eng_pdf_section($pdf, 'Aussi à ce départ, déjà attendus à un départ précédent', 12);
        $pdf->SetFont($pdf->FontStd, 'I', 8);
        $pdf->MultiCell(eng_pdf_width($pdf), 4, 'Ils règlent à leur premier départ : vérifier avant d\'encaisser, pour ne pas faire payer deux fois.', 0, 'L');
        $pdf->SetY($pdf->GetY() + 1);
        $rows = [];
        foreach ($sec['also'] as $x) {
            $a = $x['account'];
            $rows[] = [trim(mb_strtoupper($a['name']) . ' ' . $a['first']), $a['code'],
                       trim($a['club_name'] . ($a['club_code'] !== '' ? ' (' . $a['club_code'] . ')' : '')),
                       'Départ ' . $x['first'], eng_list_left($a)];
        }
        eng_pdf_table($pdf, [['Archer', 25, 'L'], ['Licence', 10, 'L'], ['Club', 24, 'L'], ['Attendu au', 12, 'L'], ['Reste', 12, 'R']], $rows, null, 'C');
    }
}

$stamp = preg_replace('/\D/', '', substr(eng_db_now(), 0, 16));
$what = $scope === 'all' ? 'tous-departs' : ($scope === 'each' ? 'par-depart' : 'depart-' . $scope);
$pdf->Output('greffe_liste_' . $what . '_' . $stamp . '.pdf', 'I');
