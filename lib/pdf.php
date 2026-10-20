<?php
/**
 * lib/pdf.php — mise en page PDF du module (état complet, reçus).
 *
 * S'appuie sur IanseoPdf (Common/pdf/IanseoPdf.php), qui fournit l'en-tête de
 * la compétition (logos, nom, lieu, dates) et le pied de page numéroté.
 */
require_once HTDOCS . '/Common/pdf/IanseoPdf.php';

/**
 * Nouveau document, première page ouverte.
 *
 * @param string $title Titre (pied de page, propriétés du document).
 * @param bool $portrait
 * @return IanseoPdf
 */
function eng_pdf_new($title, $portrait = true)
{
    $pdf = new IanseoPdf($title, $portrait);
    $pdf->startPageGroup();
    $pdf->AddPage();
    return $pdf;
}

/** Largeur utile de la page. */
function eng_pdf_width(IanseoPdf $pdf)
{
    return $pdf->getPageWidth() - 2 * IanseoPdf::sideMargin;
}

/** Montant pour le PDF : « 17,50 € ». */
function eng_pdf_money($v)
{
    return number_format((float) $v, 2, ',', ' ') . ' ' . eng_tournament()->Currency;
}

/**
 * Titre de section ; passe à la page suivante s'il resterait seul en bas.
 *
 * @param float $keep Hauteur à garder sous le titre (en-tête de tableau + une ligne).
 */
function eng_pdf_section(IanseoPdf $pdf, $title, $keep = 20)
{
    if (!$pdf->SamePage(8 + $keep)) $pdf->AddPage();
    $pdf->SetY($pdf->GetY() + 3);
    $pdf->SetFont($pdf->FontStd, 'B', 11);
    $pdf->Cell(eng_pdf_width($pdf), 7, $title, 0, 1, 'L');
}

/**
 * Tableau. L'en-tête est répété en haut de chaque nouvelle page.
 *
 * @param IanseoPdf $pdf
 * @param array $cols Colonnes : [libellé, largeur relative, alignement 'L'|'C'|'R'].
 * @param array $rows Lignes : tableaux de textes, dans l'ordre des colonnes. Un
 *                    texte contenant « \n » passe sur plusieurs lignes (et revient
 *                    à la ligne s'il est trop long) : la ligne entière s'agrandit.
 * @param array|null $total Ligne de total, en gras (facultative).
 * @param string|null $headAlign Alignement des en-têtes ('C' : centrés) ; par défaut, celui de la
 *                               colonne (à droite pour 'R', sinon à gauche).
 */
function eng_pdf_table(IanseoPdf $pdf, array $cols, array $rows, $total = null, $headAlign = null)
{
    $w = eng_pdf_width($pdf);
    $sum = array_sum(array_column($cols, 1));
    $widths = array_map(function ($c) use ($w, $sum) { return $w * $c[1] / $sum; }, $cols);

    $head = function () use ($pdf, $cols, $widths, $headAlign) {
        $pdf->SetFont($pdf->FontStd, 'B', 8);
        $pdf->SetFillColor(2, 84, 168);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($cols as $i => $c) {
            $pdf->Cell($widths[$i], 6, $c[0], 1, 0, $headAlign ?? ($c[2] === 'R' ? 'R' : 'L'), 1);
        }
        $pdf->Ln();
        $pdf->SetTextColor(0, 0, 0);
    };
    $line = function ($cells, $bold, $fill) use ($pdf, $cols, $widths, $head) {
        $pdf->SetFont($pdf->FontStd, $bold ? 'B' : '', 8);
        $texts = [];
        foreach ($cols as $i => $c) $texts[$i] = (string) ($cells[$i] ?? '');

        // Ligne simple : Cell (qui resserre un texte trop long). Ligne à plusieurs
        // lignes de texte : MultiCell, toutes les cellules à la même hauteur.
        $multi = (bool) array_filter($texts, function ($t) { return strpos($t, "\n") !== false; });
        $h = 5;
        if ($multi) {
            $n = 1;
            foreach ($texts as $i => $t) $n = max($n, $pdf->getNumLines($t, $widths[$i]));
            $h = max(5, 3.6 * $n + 1.4);
        }
        if (!$pdf->SamePage($h)) {
            $pdf->AddPage();
            $head();
            $pdf->SetFont($pdf->FontStd, $bold ? 'B' : '', 8);
        }
        $pdf->SetFillColor(240, 244, 255);
        foreach ($cols as $i => $c) {
            if ($multi) {
                $pdf->MultiCell($widths[$i], $h, $texts[$i], 1, $c[2], $fill, 0, '', '', true, 0, false, true, $h, 'M');
            } else {
                $pdf->Cell($widths[$i], $h, $texts[$i], 1, 0, $c[2], $fill);
            }
        }
        $pdf->Ln($h);
    };

    $head();
    foreach (array_values($rows) as $k => $cells) $line($cells, false, $k % 2 === 1);
    if ($total !== null) $line($total, true, false);
}

/**
 * Ligne « libellé : valeur ».
 *
 * @param float $labelW Largeur de la colonne des libellés (mm).
 */
function eng_pdf_kv(IanseoPdf $pdf, $label, $value, $bold = false, $labelW = 45)
{
    $pdf->SetFont($pdf->FontStd, '', 9);
    $pdf->Cell($labelW, 5.5, $label, 0, 0, 'L');
    $pdf->SetFont($pdf->FontStd, $bold ? 'B' : '', 9);
    $pdf->MultiCell(eng_pdf_width($pdf) - $labelW, 5.5, $value, 0, 'L');
}

/**
 * Encadré coloré pleine largeur (montant réglé, reste dû…).
 *
 * @param array $rgb Couleur de fond [r, g, b].
 */
function eng_pdf_box(IanseoPdf $pdf, $text, array $rgb = [240, 244, 255], $size = 11)
{
    $pdf->SetY($pdf->GetY() + 2);
    $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
    $pdf->SetFont($pdf->FontStd, 'B', $size);
    $pdf->MultiCell(eng_pdf_width($pdf), 9, $text, 1, 'C', true);
    $pdf->SetY($pdf->GetY() + 2);
}
