<?php
/**
 * lib/faces.php — blasons : code court, dessin d'après la table Targets d'IANSEO.
 *
 * Utilisé par plan.php (codes courts dans le plan de cible) et plan-illustre.php (dessin des buttes).
 */

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

/**
 * Zones des types de blason demandés, lues dans la table Targets d'IANSEO (taille relative et couleur de
 * chaque zone, colonnes A_size/A_color… et 1_size/1_color…).
 *
 * @param int[] $ids TarId.
 * @return array<int, array> TarId => ['descr' => TarDescr, 'spots' => 3 pour un trispot (« Small »), sinon 1,
 *               'zones' => [[taille relative 0…1, 'RRGGBB'], …] de la plus grande à la plus petite,
 *               'max' => taille relative de la plus grande zone (diamètre dessiné / diamètre du blason)].
 */
function eng_face_types(array $ids)
{
    $ids = array_filter(array_map('intval', $ids));
    if (!$ids) return [];
    $out = [];
    $rs = safe_r_sql("SELECT * FROM Targets WHERE TarId IN (" . implode(',', array_unique($ids)) . ")");
    while ($r = safe_fetch_assoc($rs)) {
        $full = max(1, floatval($r['TarFullSize']));
        $zones = [];
        foreach ($r as $col => $v) {
            if (!preg_match('/^([A-Z1-9])_size$/', $col, $m)) continue;
            $size = floatval($v);
            $color = strtoupper(trim((string) ($r[$m[1] . '_color'] ?? '')));
            if ($size <= 0 || !preg_match('/^[0-9A-F]{6}$/', $color)) continue;
            $zones[(string) $size] = [$size / $full, $color];   // une taille = une zone (l'X a sa propre taille)
        }
        krsort($zones, SORT_NUMERIC);
        $zones = array_values($zones);
        $out[intval($r['TarId'])] = [
            'id'    => intval($r['TarId']),
            'descr' => (string) $r['TarDescr'],
            'spots' => stripos($r['TarDescr'], 'Small') !== false ? 3 : 1,
            'zones' => $zones,
            'max'   => $zones ? $zones[0][0] : 1.0,
        ];
    }
    return $out;
}

/**
 * Nombre d'archers qui tirent sur un même blason posé sur la butte, comme le plan de cible visuel
 * (Modules/DragDropTarget) : 4 pour un grand blason (122, 80 complet, Beursault), 2 pour un 60 ou un
 * trispot de 60, 1 pour un 40, un trispot de 40 ou un 80 poulies (réduit).
 *
 * @param array $type Entrée d'eng_face_types().
 * @param int $diam Diamètre du blason (TfW1), en cm.
 */
function eng_face_share(array $type, $diam)
{
    if ($type['descr'] === 'TrgFrBeursault') return 4;
    $d = $diam * $type['max'];   // diamètre réellement imprimé (d'un spot pour un trispot)
    if ($type['spots'] > 1) return $d >= 25 ? 2 : 1;
    return $d >= 80 ? 4 : ($d >= 55 ? 2 : 1);
}

/**
 * Dessine un blason (ou un trispot vertical) centré en ($cx, $cy).
 *
 * @param TCPDF $pdf
 * @param array $type Entrée d'eng_face_types().
 * @param float $diam Diamètre du blason sur la page, en mm (celui de la zone la plus grande : × $type['max']).
 */
function eng_draw_face($pdf, $cx, $cy, array $type, $diam)
{
    $d = $diam * $type['max'];
    $pitch = $d * 1.08;
    $pdf->SetLineStyle(['width' => 0.05, 'color' => [90, 90, 90]]);
    for ($s = 0; $s < $type['spots']; $s++) {
        $y = $cy + ($s - ($type['spots'] - 1) / 2) * $pitch;
        foreach ($type['zones'] as $z) {
            $c = $z[1];
            $pdf->SetFillColor(hexdec(substr($c, 0, 2)), hexdec(substr($c, 2, 2)), hexdec(substr($c, 4, 2)));
            $pdf->Circle($cx, $y, $z[0] * $diam / 2, 0, 360, 'DF');
        }
    }
}

/** Hauteur d'un blason dessiné (trispot : trois spots), en mm, pour un diamètre $diam sur la page. */
function eng_face_height(array $type, $diam)
{
    $d = $diam * $type['max'];
    return $type['spots'] > 1 ? $d + ($type['spots'] - 1) * $d * 1.08 : $d;
}

const ENG_BUTTE = 130;   // côté d'une butte, en cm
const ENG_PLAN_MAX = 18;  // cibles au plus par page du plan illustré (6 × 3 en paysage), réglage de l'utilisateur

/**
 * Blasons à poser sur une butte : [[type, diamètre cm, x cm, y cm, lettres, pâle], …], coordonnées depuis le
 * coin haut gauche de la butte. Colonne gauche : lettres A, C… ; droite : B, D… (ordre impair / pair).
 * Comme le plan de cible visuel : dans une colonne qui a au moins un archer, une place libre reçoit le blason
 * de cette colonne, « pâle » (à poser quand même) ; une colonne sans archer reste vide.
 *
 * @param int $ath Archers par cible du départ (SesAth4Target) : places à remplir dans chaque colonne.
 */
function eng_butte_faces(array $places, array $types, $ath = 4)
{
    $cols = [[], []];
    foreach ($places as $letter => $p) {
        if (!isset($types[intval($p->TfT1)]) || intval($p->TfW1) <= 0) continue;
        $n = ord(strtoupper($letter)) - 64;
        if ($n < 1) continue;
        $p->share = eng_face_share($types[intval($p->TfT1)], intval($p->TfW1));
        $cols[($n - 1) % 2][$n] = [$letter, $p];
    }
    $out = [];
    // Grand blason (4 archers) : un seul, au centre de la butte.
    foreach ($cols as $col) foreach ($col as $e) {
        if ($e[1]->share === 4) {
            $letters = [];
            foreach ($cols as $c) foreach ($c as $x) $letters[] = $x[0];
            sort($letters);
            return [[$types[intval($e[1]->TfT1)], intval($e[1]->TfW1), ENG_BUTTE / 2, ENG_BUTTE / 2, $letters, false]];
        }
    }
    $half = ENG_BUTTE / 2;
    foreach ($cols as $i => $col) {
        if (!$col) continue;
        ksort($col);
        $x0 = $i * $half;
        $shared = null;
        foreach ($col as $e) if ($e[1]->share === 2) { $shared = $e[1]; break; }
        if ($shared) {   // un blason pour la colonne
            $out[] = [$types[intval($shared->TfT1)], intval($shared->TfW1), $x0 + $half / 2, ENG_BUTTE / 2, array_column($col, 0), false];
            continue;
        }
        // Un blason par place : rang 1 (A, B) puis rang 2 (C, D) ; place libre : blason de la colonne, pâle.
        $ref = reset($col)[1];
        for ($n = $i + 1; $n <= max(intval($ath) ?: 4, max(array_keys($col))); $n += 2) {
            $pale = !isset($col[$n]);
            $e = $pale ? [chr(64 + $n), $ref] : $col[$n];
            $t = $types[intval($e[1]->TfT1)];
            $row = intdiv($n - 1, 2);
            if ($t['spots'] > 1) $out[] = [$t, intval($e[1]->TfW1), $x0 + $half * ($row ? 0.72 : 0.28), ENG_BUTTE / 2, [$e[0]], $pale];
            else $out[] = [$t, intval($e[1]->TfW1), $x0 + $half / 2, ENG_BUTTE * ($row ? 0.75 : 0.25), [$e[0]], $pale];
        }
    }
    return $out;
}

/**
 * Pages d'un départ du plan de cible illustré : titre, puis une butte par cible, en grille ajustée à la page.
 * Au-delà de ENG_PLAN_MAX cibles, le départ continue sur d'autres pages, avec des buttes de même taille.
 *
 * @param TCPDF $pdf Page déjà ouverte, en paysage.
 * @param int $order Numéro du départ.
 * @param array $ses ['name', 'first', 'last' (cibles du départ), 'ath' (archers par cible), 'start' (« AAAA-MM-JJ HH:MM:SS » ou ''),
 *                   'targets' => [cible => [lettre => place (TfT1, TfW1, Dist : distances en m, [] en parcours)]]].
 * @param array $types eng_face_types().
 * @param float $breakMargin Hauteur réservée au pied de page, en mm.
 */
function eng_plan_page($pdf, $order, array $ses, array $types, $breakMargin)
{
    $last = $ses['targets'] ? max($ses['last'], max(array_keys($ses['targets']))) : $ses['last'];
    $nums = $last >= $ses['first'] ? range($ses['first'], $last) : [];
    $nArch = array_sum(array_map('count', $ses['targets']));

    if (!$nums) return;

    // Pages : au plus ENG_PLAN_MAX cibles, réparties également (38 cibles → 10, 10, 9, 9).
    $pages = array_chunk($nums, (int) ceil(count($nums) / ceil(count($nums) / ENG_PLAN_MAX)));
    $left = $pdf->getSideMargin();
    $width = $pdf->getPageWidth() - 2 * $left;
    $gap = 3; $headH = 5.5; $capH = 6;
    $side = $cols = $cellW = $k = $fs = 0;

    foreach ($pages as $p => $page) {
        if ($p > 0) $pdf->AddPage();

        // Titre du départ.
        $pdf->SetTextColor(0);
        $pdf->SetFont($pdf->FontStd, 'B', 13);
        $title = 'Plan de cible — Départ ' . $order . ($ses['name'] !== '' ? ' · ' . $ses['name'] : '');
        if ($ses['start'] !== '' && substr($ses['start'], 0, 4) !== '0000') {
            $title .= ' · ' . date('d/m/Y H:i', strtotime($ses['start']));
        }
        $info = count($nums) . ' cibles · ' . $nArch . ' archer' . ($nArch > 1 ? 's' : '');
        if (count($pages) > 1) $info = 'cibles ' . reset($page) . ' à ' . end($page) . ' (page ' . ($p + 1) . '/' . count($pages) . ') · ' . $info;
        $pdf->Cell($width * 0.6, 7, $title, 0, 0, 'L');
        $pdf->SetFont($pdf->FontStd, '', 9);
        $pdf->Cell($width * 0.4, 7, $info, 0, 1, 'R');
        $top = $pdf->GetY() + 2;

        // Grille, calculée sur la première page (la plus remplie) et gardée pour les suivantes : le nombre de
        // colonnes qui donne les plus grandes buttes.
        if ($p === 0) {
            $bottom = $pdf->getPageHeight() - $breakMargin - 2;
            $best = [1, 0];
            for ($c = 1; $c <= count($page); $c++) {
                $rows = (int) ceil(count($page) / $c);
                $sd = min(($width - ($c - 1) * $gap) / $c, ($bottom - $top - ($rows - 1) * $gap) / $rows - $headH - $capH);
                if ($sd > $best[1]) $best = [$c, $sd];
            }
            [$cols, $side] = $best;
            $cellW = ($width - ($cols - 1) * $gap) / $cols;
            $k = $side / ENG_BUTTE;   // mm par cm
            $fs = max(7, min(10, $side / 5));   // taille du texte, en points
        }

        foreach ($page as $i => $num) {
            $x = $left + ($i % $cols) * ($cellW + $gap) + ($cellW - $side) / 2;
            $y = $top + intdiv($i, $cols) * ($side + $headH + $capH + $gap);
            $places = $ses['targets'][$num] ?? [];

            // En-tête : numéro et distance(s).
            // Distances des archers de la butte (vide en parcours) : « 18 m », « 70 m », « 50 / 30 m ».
            $dists = array_unique(array_merge([], ...array_values(array_map(function ($p) { return (array) $p->Dist; }, $places))));
            rsort($dists);
            $pdf->SetFillColor(230, 230, 230);
            $pdf->SetLineStyle(['width' => 0.2, 'color' => [0, 0, 0]]);
            $pdf->SetXY($x, $y);
            $pdf->SetFont($pdf->FontStd, 'B', $fs);
            $pdf->Cell($side * 0.55, $headH, 'Cible ' . $num, 'LTB', 0, 'L', true);
            $pdf->SetFont($pdf->FontStd, 'B', $fs);
            $pdf->Cell($side * 0.45, $headH, $dists ? implode(' / ', $dists) . ' m' : '', 'RTB', 0, 'R', true);

            // Butte : carré ; blasons s'il y a des archers.
            $by = $y + $headH;
            $faces = eng_butte_faces($places, $types, $ses['ath'] ?? 4);
            if ($faces) $pdf->SetFillColor(250, 246, 232);
            $pdf->Rect($x, $by, $side, $side, $faces ? 'DF' : 'D');
            $captions = [];
            foreach ($faces as $f) {
                [$type, $diam, $fx, $fy, $letters, $pale] = $f;
                if ($pale) $pdf->setAlpha(0.3);   // place libre : blason à poser, effacé
                eng_draw_face($pdf, $x + $fx * $k, $by + $fy * $k, $type, $diam * $k);
                // Blason d'un seul archer : sa lettre au-dessus. Blason partagé : les lettres sont dans la légende.
                if (count($letters) === 1 && eng_face_share($type, $diam) === 1) {
                    $lh = $fs * 0.42;
                    $pdf->SetFont($pdf->FontStd, 'B', $fs * 0.9);
                    $pdf->SetTextColor(0);
                    $pdf->SetXY($x + $fx * $k - $lh, $by + $fy * $k - eng_face_height($type, $diam * $k) / 2 - $lh);
                    $pdf->Cell(2 * $lh, $lh, $letters[0], 0, 0, 'C');
                }
                // Trispot : classique ou poulie en gras sous le blason (spots identiques à l'œil).
                $arme = ['TrgIndSmall' => 'CL', 'TrgCOIndSmall' => 'CO'][$type['descr']] ?? '';
                if ($arme !== '') {
                    $lh = $fs * 0.42;
                    $pdf->SetFont($pdf->FontStd, 'B', $fs * 0.9);
                    $pdf->SetTextColor(0);
                    $pdf->SetXY($x + $fx * $k - 2 * $lh, $by + $fy * $k + eng_face_height($type, $diam * $k) / 2);
                    $pdf->Cell(4 * $lh, $lh, $arme, 0, 0, 'C');
                }
                if ($pale) {
                    $pdf->setAlpha(1);
                    continue;   // pas dans la légende : aucun archer
                }
                $code = eng_face_code($type['id'], $diam, '');
                $captions[$code !== '' ? $code : $diam . ' cm'] = array_merge($captions[$code !== '' ? $code : $diam . ' cm'] ?? [], $letters);
            }
            // Légende : blasons de la butte et leurs archers (« 122 : A B C D », « T40 (CL) : A C »).
            $cap = [];
            foreach ($captions as $code => $letters) { sort($letters); $cap[] = $code . ' : ' . implode(' ', array_unique($letters)); }
            $pdf->SetFont($pdf->FontStd, 'B', $fs * 1.05);
            $pdf->SetXY($x, $by + $side);
            $pdf->Cell($side, $capH, implode(' · ', $cap), 0, 0, 'C', false, '', 1);
        }
    }
}
