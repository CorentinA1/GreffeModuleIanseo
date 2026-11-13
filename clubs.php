<?php
/**
 * clubs.php — GREFFE : liste des participants par clubs, aérée (PDF).
 *
 *   ?depart=<n>    seulement les archers de ce départ (défaut : tous les départs)
 *   ?page=1        une page par club (à remettre à chaque club)
 *
 * Même contenu que l'état d'IANSEO « Liste des participants par pays/clubs » avec le type de cible
 * (Partecipants/PrnCountry.php?tf=1), en plus lisible : bandeau par club avec son logo (table Flags, comme les
 * feuilles de marque) et son nombre d'archers ; une ligne par inscription (un archer sur deux départs : deux
 * lignes) ; catégorie « S1HCL » ; blason en code court (lib/faces.php). En fin de document, le nombre d'archers
 * par départ. Clubs et archers par ordre alphabétique.
 */
define('ENG_ACCESS', 'read');
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/accounts.php';
require_once __DIR__ . '/lib/faces.php';
require_once __DIR__ . '/lib/pdf.php';

$tour    = eng_tournament();
$depart  = (string) ($_GET['depart'] ?? '');
$scope   = ctype_digit($depart) && (int) $depart >= 1 && (int) $depart <= $tour->Sessions ? (int) $depart : 0;
$perPage = !empty($_GET['page']);
$multi   = !$scope && $tour->Sessions > 1;   // colonne « Départ »

// Inscriptions avec leur club et leur blason.
$rs = safe_r_sql("SELECT EnId, EnCode, EnName, EnFirstName, EnDivision, EnClass,
        IFNULL(QuSession, 0) AS QuSession, IF(QuTarget > 0, CONCAT(QuTarget, QuLetter), '') AS Target,
        IFNULL(CoCode, '') AS CoCode, IFNULL(CoName, '') AS CoName, TfName, TfT1, TfW1
    FROM Entries
    LEFT JOIN Qualifications ON QuId = EnId
    LEFT JOIN Countries ON CoId = EnCountry AND CoTournament = EnTournament
    LEFT JOIN TargetFaces ON TfTournament = EnTournament AND TfId = EnTargetFace
    WHERE EnTournament = " . StrSafe_DB($ENG_TOUR) . " AND EnAthlete = 1"
    . ($scope ? " AND QuSession = " . $scope : ''));

// Club => lignes (une par inscription), archers distincts (licence), inscrits par départ.
$clubs = [];
$bySes = [];
while ($r = safe_fetch($rs)) {
    $ck = $r->CoCode . '|' . $r->CoName;
    if (!isset($clubs[$ck])) $clubs[$ck] = ['code' => $r->CoCode, 'name' => $r->CoName, 'rows' => [], 'archers' => []];
    $key = eng_account_key($r->EnCode, $r->EnId);
    $clubs[$ck]['archers'][$key] = true;
    $ses = intval($r->QuSession);
    $bySes[$ses] = ($bySes[$ses] ?? 0) + 1;
    $clubs[$ck]['rows'][] = [
        // Piège ianseo : EnFirstName est le NOM de famille, EnName le prénom.
        'name'   => trim(mb_strtoupper($r->EnFirstName) . ' ' . $r->EnName),
        'code'   => $key[0] === '#' ? '' : $key,
        'cat'    => trim($r->EnClass) . trim($r->EnDivision),   // « S2HCL » : classe puis arme, comme le reste du module
        'ses'    => $ses,
        'target' => (string) $r->Target,
        'face'   => $r->TfName !== null ? eng_face_code($r->TfT1, $r->TfW1, get_text($r->TfName, 'Tournament', '', true)) : '',
    ];
}
uasort($clubs, function ($x, $y) {
    return ($x['name'] === '') <=> ($y['name'] === '') ?: strcasecmp($x['name'], $y['name']) ?: strcmp($x['code'], $y['code']);
});
foreach ($clubs as &$c) {
    // Par nom, puis départ (non placé en dernier).
    usort($c['rows'], function ($x, $y) {
        return strcasecmp($x['name'], $y['name']) ?: strcmp($x['code'], $y['code'])
            ?: ($x['ses'] ?: PHP_INT_MAX) <=> ($y['ses'] ?: PHP_INT_MAX);
    });
}
unset($c);

// Logos des clubs, comme sur les feuilles de marque : table Flags (propres à la compétition, sinon communs).
$logos = [];
if ($clubs) {
    $codes = array_filter(array_unique(array_column($clubs, 'code')), 'strlen');
    if ($codes) {
        $rs = safe_r_sql("SELECT FlCode, FlJPG FROM Flags WHERE FlTournament IN (-1, " . StrSafe_DB($ENG_TOUR) . ")
            AND FlCode IN (" . implode(',', array_map('StrSafe_DB', $codes)) . ") AND FlJPG > '' ORDER BY FlTournament");
        while ($r = safe_fetch($rs)) {
            $jpg = base64_decode($r->FlJPG, true);
            if ($jpg !== false && @getimagesizefromstring($jpg)) $logos[$r->FlCode] = $jpg;   // la compétition l'emporte
        }
    }
}

/** Départ d'une inscription : « D1 », « non placé ». */
function eng_clubs_session($s)
{
    return $s ? 'D' . $s : 'non placé';
}

$pdf = eng_pdf_new('Greffe - Liste des participants par clubs');
$W = eng_pdf_width($pdf);
// Colonnes : libellé, largeur relative, alignement.
$cols = [['Archer', 34, 'L'], ['Licence', 13, 'L'], ['Catégorie', 13, 'L']];
if ($multi) $cols[] = ['Départ', 9, 'L'];
$cols[] = ['Cible', 9, 'L'];
$cols[] = ['Blason', 14, 'L'];
$sum = array_sum(array_column($cols, 1));
foreach ($cols as &$col) $col[1] = $W * $col[1] / $sum;
unset($col);
$rowH = 6.5;
$bandH = 11;

/**
 * Fond d'un bandeau, économe en encre : bleu très pâle et filet bleu à gauche ; texte en bleu foncé ensuite.
 * Un seul rectangle (deux cellules remplies laisseraient un filet blanc entre elles).
 */
$bandBg = function ($x, $y) use ($pdf, $W, $bandH) {
    $pdf->SetFillColor(234, 240, 250);
    $pdf->Rect($x, $y, $W, $bandH, 'F');
    $pdf->SetFillColor(2, 84, 168);
    $pdf->Rect($x, $y, 1.2, $bandH, 'F');
    $pdf->SetTextColor(1, 54, 124);
};

/** Bandeau du club (logo s'il y en a un, nom, code, nombre d'archers) puis en-tête des colonnes. */
$band = function ($c, $suite) use ($pdf, $W, $cols, $logos, $bandH, $bandBg) {
    $n = count($c['archers']);
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    $bandBg($x, $y);
    $logoW = 0;
    if (isset($logos[$c['code']])) {
        // Logo, proportions gardées, au plus 3:2 comme sur les feuilles de marque.
        [$iw, $ih] = getimagesizefromstring($logos[$c['code']]);
        $h = $bandH - 2.4;
        $w = min($h * 1.5, $h * $iw / max(1, $ih));
        $pdf->Image('@' . $logos[$c['code']], $x + 3, $y + 1.2, $w, $h, '', '', '', true, 300, '', false, false, 0, true);
        $logoW = $w + 3;
    }
    $pdf->SetXY($x + $logoW, $y);
    $pdf->SetFont($pdf->FontStd, 'B', 12);
    $title = ($c['name'] !== '' ? $c['name'] : 'Sans club') . ($c['code'] !== '' ? '  (' . $c['code'] . ')' : '') . ($suite ? ' — suite' : '');
    $pdf->Cell($W - 35 - $logoW, $bandH, '  ' . $title, 0, 0, 'L', false, '', 1);
    $pdf->SetFont($pdf->FontStd, 'B', 10);
    $pdf->Cell(35, $bandH, $n . ' archer' . ($n > 1 ? 's' : '') . '  ', 0, 1, 'R');
    $pdf->SetTextColor(70, 70, 70);
    $pdf->SetFont($pdf->FontStd, 'B', 8);
    foreach ($cols as $col) $pdf->Cell($col[1], 6, '  ' . $col[0], 'B', 0, $col[2]);
    $pdf->Ln();
    $pdf->SetTextColor(0, 0, 0);
};

$sub = $scope ? 'Départ ' . $scope : ($tour->Sessions > 1 ? 'Tous les départs' : '');
$title = 'Liste des participants par clubs' . ($sub !== '' ? ' — ' . $sub : '');
$nArch = array_sum(array_map(function ($c) { return count($c['archers']); }, $clubs));
$pdf->SetFont($pdf->FontStd, 'B', 15);
$pdf->Cell($W, 9, $title, 0, 1, 'L');
$pdf->SetFont($pdf->FontStd, '', 9);
$pdf->SetTextColor(90, 90, 90);
$pdf->Cell($W, 5, count($clubs) . ' club' . (count($clubs) > 1 ? 's' : '') . ' · ' . $nArch . ' archer' . ($nArch > 1 ? 's' : '')
    . ' · édité le ' . eng_local_time(eng_db_now(), 'd/m/Y à H:i'), 0, 1, 'L');
$pdf->SetTextColor(0, 0, 0);
$pdf->SetY($pdf->GetY() + 4);

if (!$clubs) {
    $pdf->SetFont($pdf->FontStd, 'I', 10);
    $pdf->Cell($W, 8, 'Aucun archer inscrit' . ($scope ? ' à ce départ.' : '.'), 0, 1, 'L');
}

$first = true;
foreach ($clubs as $c) {
    if (!$first && $perPage) {
        // Page remise au club : le titre de l'édition est rappelé.
        $pdf->AddPage();
        $pdf->SetFont($pdf->FontStd, 'B', 12);
        $pdf->Cell($W, 8, $title, 0, 1, 'L');
        $pdf->SetY($pdf->GetY() + 2);
    } elseif (!$pdf->SamePage($bandH + 6 + 2 * $rowH)) {
        // Le club commence sur une nouvelle page s'il ne tient pas son bandeau et deux lignes.
        $pdf->AddPage();
    }
    $first = false;
    $band($c, false);

    $pdf->SetFillColor(240, 244, 255);
    foreach ($c['rows'] as $k => $row) {
        if (!$pdf->SamePage($rowH)) {
            $pdf->AddPage();
            $band($c, true);
            $pdf->SetFillColor(240, 244, 255);
        }
        $cells = [$row['name'], $row['code'], $row['cat']];
        if ($multi) $cells[] = eng_clubs_session($row['ses']);
        $cells[] = $row['target'] !== '' ? $row['target'] : '—';
        $cells[] = $row['face'];
        foreach ($cols as $i => $col) {
            $pdf->SetFont($pdf->FontStd, $i === 0 ? 'B' : '', $i === 0 ? 10 : 9.5);
            $pdf->Cell($col[1], $rowH, '  ' . $cells[$i], 0, 0, $col[2], $k % 2 === 1, '', 1);
        }
        $pdf->Ln();
    }
    $pdf->SetDrawColor(2, 84, 168);
    $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetX() + $W, $pdf->GetY());
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetY($pdf->GetY() + 8);
}

// Fin du document : archers par départ et total de la compétition.
if ($clubs) {
    ksort($bySes, SORT_NUMERIC);
    if (isset($bySes[0])) $bySes = array_slice($bySes, 1, null, true) + [0 => $bySes[0]];   // non placés en dernier
    // Les pages des clubs restent à remettre seules ; sinon le total tient d'un bloc.
    if ($perPage || !$pdf->SamePage($bandH + (count($bySes) + 1) * $rowH + 2)) $pdf->AddPage();
    $nIns = array_sum($bySes);
    $x = $pdf->GetX();
    $bandBg($x, $pdf->GetY());
    $pdf->SetFont($pdf->FontStd, 'B', 12);
    $pdf->Cell($W / 2, $bandH, '  ' . ($scope ? 'Total du départ' : 'Total de la compétition'), 0, 0, 'L');
    $pdf->SetFont($pdf->FontStd, 'B', 10);
    $pdf->Cell($W / 2, $bandH, count($clubs) . ' club' . (count($clubs) > 1 ? 's' : '') . ' · ' . $nArch . ' archer' . ($nArch > 1 ? 's' : '') . '  ', 0, 1, 'R');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(240, 244, 255);
    $k = 0;
    foreach ($bySes as $s => $n) {
        $pdf->SetFont($pdf->FontStd, 'B', 10);
        $pdf->Cell($W / 2, $rowH, '  ' . ($s ? 'Départ ' . $s : 'Non placés'), 0, 0, 'L', $k % 2 === 1);
        $pdf->SetFont($pdf->FontStd, '', 10);
        $pdf->Cell($W / 2, $rowH, $n . ' archer' . ($n > 1 ? 's' : '') . '  ', 0, 1, 'R', $k++ % 2 === 1);
    }
    // Un archer sur deux départs compte à chacun : le total des départs peut dépasser le nombre d'archers.
    $pdf->SetDrawColor(2, 84, 168);
    $pdf->Line($x, $pdf->GetY(), $x + $W, $pdf->GetY());
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetFont($pdf->FontStd, 'B', 10);
    $pdf->Cell($W / 2, $rowH, '  Total des départs', 0, 0, 'L');
    $pdf->Cell($W / 2, $rowH, $nIns . ' archer' . ($nIns > 1 ? 's' : '') . ($nIns !== $nArch ? ' (' . $nArch . ' différents)' : '') . '  ', 0, 1, 'R');
}

$pdf->Output('participants-par-clubs' . ($scope ? '_depart-' . $scope : '') . '.pdf', 'I');
