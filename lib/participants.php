<?php
/**
 * lib/participants.php — listes des participants aérées (PDF) : par clubs (clubs.php), par catégories
 * (categories.php), par ordre alphabétique (alphabetique.php).
 *
 * Même contenu que les états d'IANSEO (Participants › Impressions, avec le type de cible : PrnCountry.php,
 * PrnCategory.php, PrnAlphabetical.php ?tf=1), en plus lisible et économe en encre : un bandeau clair par groupe
 * (avec le logo du club pour la liste par clubs, table Flags comme les feuilles de marque) et son nombre d'archers,
 * une ligne par inscription (un archer sur deux départs : deux lignes), catégorie « S1HCL », blason en code court
 * (lib/faces.php) ; en fin de document, le nombre d'archers par départ et le total.
 */
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/faces.php';
require_once __DIR__ . '/pdf.php';

/**
 * Inscriptions des archers, une ligne par inscription.
 *
 * @param int $scope Départ (0 : tous).
 * @return array Lignes : key (licence ou « #EnId »), name (« NOM Prénom »), code (licence), cat (« S1HCL »),
 *               cat_label (« Arc Classique Senior 1 Hommes »), cat_order (« CLS1H »), club_code, club_name, ses,
 *               target, face.
 */
function eng_part_load($tourId, $scope)
{
    $rs = safe_r_sql("SELECT EnId, EnCode, EnName, EnFirstName, EnDivision, EnClass,
            IFNULL(QuSession, 0) AS QuSession, IF(QuTarget > 0, CONCAT(QuTarget, QuLetter), '') AS Target,
            IFNULL(CoCode, '') AS CoCode, IFNULL(CoName, '') AS CoName, TfName, TfT1, TfW1,
            IFNULL(DivDescription, '') AS DivDescription, IFNULL(ClDescription, '') AS ClDescription
        FROM Entries
        LEFT JOIN Qualifications ON QuId = EnId
        LEFT JOIN Countries ON CoId = EnCountry AND CoTournament = EnTournament
        LEFT JOIN TargetFaces ON TfTournament = EnTournament AND TfId = EnTargetFace
        LEFT JOIN Divisions ON DivId = EnDivision AND DivTournament = EnTournament
        LEFT JOIN Classes ON ClId = EnClass AND ClTournament = EnTournament
        WHERE EnTournament = " . StrSafe_DB($tourId) . " AND EnAthlete = 1"
        . ($scope ? " AND QuSession = " . intval($scope) : ''));
    $rows = [];
    while ($r = safe_fetch($rs)) {
        $key = eng_account_key($r->EnCode, $r->EnId);
        $rows[] = [
            'key'       => $key,
            // Piège ianseo : EnFirstName est le NOM de famille, EnName le prénom.
            'name'      => trim(mb_strtoupper($r->EnFirstName) . ' ' . $r->EnName),
            'code'      => $key[0] === '#' ? '' : $key,
            'cat'       => trim($r->EnClass) . trim($r->EnDivision),   // « S2HCL » : classe puis arme, comme le reste du module
            'cat_label' => trim($r->DivDescription . ' ' . $r->ClDescription),
            'cat_order' => trim($r->EnDivision) . trim($r->EnClass),   // ordre de l'état d'IANSEO : « BBS2H », « CLS1F »…
            'club_code' => (string) $r->CoCode,
            'club_name' => (string) $r->CoName,
            'ses'       => intval($r->QuSession),
            'target'    => (string) $r->Target,
            'face'      => $r->TfName !== null ? eng_face_code($r->TfT1, $r->TfW1, get_text($r->TfName, 'Tournament', '', true)) : '',
        ];
    }
    return $rows;
}

/** Tri des lignes d'un groupe : nom, licence, puis départ (non placé en dernier). */
function eng_part_sort(array &$rows)
{
    usort($rows, function ($x, $y) {
        return strcasecmp($x['name'], $y['name']) ?: strcmp($x['key'], $y['key'])
            ?: ($x['ses'] ?: PHP_INT_MAX) <=> ($y['ses'] ?: PHP_INT_MAX);
    });
}

/**
 * Logos des clubs, comme sur les feuilles de marque : table Flags (propre à la compétition, sinon commun).
 *
 * @return array Code du club => image (JPEG binaire).
 */
function eng_part_logos($tourId, array $codes)
{
    $codes = array_values(array_filter(array_unique($codes), 'strlen'));
    if (!$codes) return [];
    $logos = [];
    $rs = safe_r_sql("SELECT FlCode, FlJPG FROM Flags WHERE FlTournament IN (-1, " . StrSafe_DB($tourId) . ")
        AND FlCode IN (" . implode(',', array_map('StrSafe_DB', $codes)) . ") AND FlJPG > '' ORDER BY FlTournament");
    while ($r = safe_fetch($rs)) {
        $jpg = base64_decode($r->FlJPG, true);
        if ($jpg !== false && @getimagesizefromstring($jpg)) $logos[$r->FlCode] = $jpg;   // la compétition l'emporte
    }
    return $logos;
}

/** Club d'une ligne : « Archers de Démoville », « — » sans club. */
function eng_part_club(array $row)
{
    return $row['club_name'] !== '' ? $row['club_name'] : ($row['club_code'] !== '' ? $row['club_code'] : '—');
}

/**
 * Édite une liste et l'envoie au navigateur.
 *
 * @param string $title   Titre (« Liste des participants par clubs »).
 * @param array  $groups  Groupes dans l'ordre : ['title' => texte du bandeau, 'logo' => image ou null, 'rows' => lignes].
 * @param array  $cols    Colonnes : [clé, libellé, largeur relative, alignement]. Clés : name, code, cat, club, ses,
 *                        target, face. La colonne « ses » n'est gardée que sur plusieurs départs.
 * @param array  $opt     scope (départ, 0 : tous), sessions (nombre de départs), perPage (une page par groupe),
 *                        file (nom du PDF sans extension), count (début du décompte, « 3 clubs · », facultatif).
 */
function eng_part_pdf($title, array $groups, array $cols, array $opt)
{
    $scope = intval($opt['scope']);
    $multi = !$scope && intval($opt['sessions']) > 1;
    if (!$multi) $cols = array_values(array_filter($cols, function ($c) { return $c[0] !== 'ses'; }));

    $pdf = eng_pdf_new('Greffe - ' . $title);
    $W = eng_pdf_width($pdf);
    $sum = array_sum(array_column($cols, 2));
    foreach ($cols as &$col) $col[2] = $W * $col[2] / $sum;
    unset($col);
    $rowH = 6.5;
    $bandH = 11;

    // Fond d'un bandeau, économe en encre : bleu très pâle et filet bleu à gauche ; texte en bleu foncé ensuite.
    // Un seul rectangle (deux cellules remplies laisseraient un filet blanc entre elles).
    $bandBg = function ($x, $y) use ($pdf, $W, $bandH) {
        $pdf->SetFillColor(234, 240, 250);
        $pdf->Rect($x, $y, $W, $bandH, 'F');
        $pdf->SetFillColor(2, 84, 168);
        $pdf->Rect($x, $y, 1.2, $bandH, 'F');
        $pdf->SetTextColor(1, 54, 124);
    };

    // Bandeau d'un groupe (logo s'il y en a un, titre, nombre d'archers) puis en-tête des colonnes.
    $band = function ($g, $suite) use ($pdf, $W, $cols, $bandH, $bandBg) {
        $n = count(array_unique(array_column($g['rows'], 'key')));
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $bandBg($x, $y);
        $logoW = 0;
        if (!empty($g['logo'])) {
            // Logo, proportions gardées, au plus 3:2 comme sur les feuilles de marque.
            [$iw, $ih] = getimagesizefromstring($g['logo']);
            $h = $bandH - 2.4;
            $w = min($h * 1.5, $h * $iw / max(1, $ih));
            $pdf->Image('@' . $g['logo'], $x + 3, $y + 1.2, $w, $h, '', '', '', true, 300, '', false, false, 0, true);
            $logoW = $w + 3;
        }
        $pdf->SetXY($x + $logoW, $y);
        $pdf->SetFont($pdf->FontStd, 'B', 12);
        $pdf->Cell($W - 35 - $logoW, $bandH, '  ' . $g['title'] . ($suite ? ' — suite' : ''), 0, 0, 'L', false, '', 1);
        $pdf->SetFont($pdf->FontStd, 'B', 10);
        $pdf->Cell(35, $bandH, $n . ' archer' . ($n > 1 ? 's' : '') . '  ', 0, 1, 'R');
        $pdf->SetTextColor(70, 70, 70);
        $pdf->SetFont($pdf->FontStd, 'B', 8);
        foreach ($cols as $col) $pdf->Cell($col[2], 6, '  ' . $col[1], 'B', 0, $col[3]);
        $pdf->Ln();
        $pdf->SetTextColor(0, 0, 0);
    };

    $all = array_merge([], ...array_column($groups, 'rows'));
    $nArch = count(array_unique(array_column($all, 'key')));
    $sub = $scope ? 'Départ ' . $scope : (intval($opt['sessions']) > 1 ? 'Tous les départs' : '');
    $full = $title . ($sub !== '' ? ' — ' . $sub : '');
    $pdf->SetFont($pdf->FontStd, 'B', 15);
    $pdf->Cell($W, 9, $full, 0, 1, 'L');
    $pdf->SetFont($pdf->FontStd, '', 9);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell($W, 5, ($opt['count'] ?? '') . $nArch . ' archer' . ($nArch > 1 ? 's' : '') . ' · édité le ' . eng_local_time(eng_db_now(), 'd/m/Y à H:i'), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY($pdf->GetY() + 4);

    if (!$groups) {
        $pdf->SetFont($pdf->FontStd, 'I', 10);
        $pdf->Cell($W, 8, 'Aucun archer inscrit' . ($scope ? ' à ce départ.' : '.'), 0, 1, 'L');
    }

    $first = true;
    foreach ($groups as $g) {
        if (!$first && !empty($opt['perPage'])) {
            // Page remise ou affichée seule : le titre de l'édition est rappelé.
            $pdf->AddPage();
            $pdf->SetFont($pdf->FontStd, 'B', 12);
            $pdf->Cell($W, 8, $full, 0, 1, 'L');
            $pdf->SetY($pdf->GetY() + 2);
        } elseif (!$pdf->SamePage($bandH + 6 + 2 * $rowH)) {
            // Le groupe commence sur une nouvelle page s'il ne tient pas son bandeau et deux lignes.
            $pdf->AddPage();
        }
        $first = false;
        $band($g, false);

        $pdf->SetFillColor(240, 244, 255);
        foreach ($g['rows'] as $k => $row) {
            if (!$pdf->SamePage($rowH)) {
                $pdf->AddPage();
                $band($g, true);
                $pdf->SetFillColor(240, 244, 255);
            }
            foreach ($cols as $col) {
                switch ($col[0]) {
                    case 'club':   $text = eng_part_club($row); break;
                    case 'ses':    $text = $row['ses'] ? 'D' . $row['ses'] : 'non placé'; break;
                    case 'target': $text = $row['target'] !== '' ? $row['target'] : '—'; break;
                    default:       $text = (string) $row[$col[0]];
                }
                $pdf->SetFont($pdf->FontStd, $col[0] === 'name' ? 'B' : '', $col[0] === 'name' ? 10 : 9.5);
                $pdf->Cell($col[2], $rowH, '  ' . $text, 0, 0, $col[3], $k % 2 === 1, '', 1);
            }
            $pdf->Ln();
        }
        $pdf->SetDrawColor(2, 84, 168);
        $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetX() + $W, $pdf->GetY());
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetY($pdf->GetY() + 8);
    }

    // Fin du document : archers par départ et total.
    if ($all) {
        $bySes = [];
        foreach ($all as $row) $bySes[$row['ses']] = ($bySes[$row['ses']] ?? 0) + 1;
        ksort($bySes, SORT_NUMERIC);
        if (isset($bySes[0])) $bySes = array_slice($bySes, 1, null, true) + [0 => $bySes[0]];   // non placés en dernier
        // Les pages des groupes restent seules ; sinon le total tient d'un bloc.
        if (!empty($opt['perPage']) || !$pdf->SamePage($bandH + (count($bySes) + 1) * $rowH + 2)) $pdf->AddPage();
        $nIns = count($all);
        $x = $pdf->GetX();
        $bandBg($x, $pdf->GetY());
        $pdf->SetFont($pdf->FontStd, 'B', 12);
        $pdf->Cell($W / 2, $bandH, '  ' . ($scope ? 'Total du départ' : 'Total de la compétition'), 0, 0, 'L');
        $pdf->SetFont($pdf->FontStd, 'B', 10);
        $pdf->Cell($W / 2, $bandH, ($opt['count'] ?? '') . $nArch . ' archer' . ($nArch > 1 ? 's' : '') . '  ', 0, 1, 'R');
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

    $pdf->Output($opt['file'] . ($scope ? '_depart-' . $scope : '') . '.pdf', 'I');
}

/** Départ demandé (?depart=n), 0 pour tous les départs. */
function eng_part_scope($tour)
{
    $depart = (string) ($_GET['depart'] ?? '');
    return ctype_digit($depart) && (int) $depart >= 1 && (int) $depart <= $tour->Sessions ? (int) $depart : 0;
}
