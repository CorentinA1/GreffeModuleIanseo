<?php
/**
 * reporting.php — GREFFE : état des inscriptions et des paiements.
 *
 * Synthèse, ventilations (moyen, club, règle, nombre de départs, session),
 * listes de contrôle, exports CSV (?csv=archers | clubs) et PDF (?pdf=1).
 *
 * Les sections sont décrites une seule fois (eng_report_sections) puis rendues
 * en HTML ou en PDF : les deux sorties affichent forcément les mêmes chiffres.
 */
define('ENG_ACCESS', 'read');
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/accounts.php';
require_once __DIR__ . '/lib/report.php';

$tour     = eng_tournament();
$methods  = eng_methods();
$statuses = eng_statuses();
$config   = eng_load_config();
$accounts = eng_load_accounts($ENG_TOUR, $config);
$report   = eng_report($accounts);
$self     = $ENG_ROOT . 'reporting.php';
$payUrl   = $ENG_ROOT . 'paiements.php';

/** « NOM Prénom », ou la mention d'une inscription supprimée. */
function eng_who(array $a)
{
    return $a['n'] === 0 ? '(inscription supprimée)' : trim(mb_strtoupper($a['name']) . ' ' . $a['first']);
}

/**
 * Sections du rapport, indépendantes du format de sortie.
 *
 * Colonne : [libellé, largeur relative, alignement, type 'text' | 'int' | 'money'].
 * Ligne : valeurs brutes dans l'ordre des colonnes ; clé '_href' facultative
 * (lien dans la page HTML).
 */
function eng_report_sections(array $r, array $methods, $payUrl)
{
    $t = $r['totals'];
    $money = function ($label, $w = 14) { return [$label, $w, 'R', 'money']; };
    $int = function ($label, $w = 9) { return [$label, $w, 'R', 'int']; };
    $amountCols = [$int('Archers'), $int('Inscriptions', 11), $money('Dû'), $money('Encaissé'), $money('Exonéré'), $money('Reste')];
    $amountVals = function ($l) { return [$l['archers'], $l['departs'], $l['due'], $l['paid'], $l['waived'], $l['left']]; };
    $s = [];

    $rows = [
        ['Archers', $t['archers'], null],
        ['Inscriptions (départs)', $t['departs'], null],
        ['Total dû', null, $t['due']],
        ['Encaissé', null, $t['paid']],
        ['Exonéré', null, $t['waived']],
        ['Reste à encaisser (' . $t['unpaid'] . ' archer' . ($t['unpaid'] > 1 ? 's' : '') . ' non payé' . ($t['unpaid'] > 1 ? 's' : '') . ')', null, $t['left']],
    ];
    if ($t['over'] > 0) $rows[] = ['Trop-perçu (à rembourser ou régulariser)', null, $t['over']];
    $s[] = ['id' => 'synthese', 'title' => 'Synthèse',
        'cols' => [['Indicateur', 50, 'L', 'text'], $int('Nombre', 12), $money('Montant', 16)], 'rows' => $rows];

    $rows = [];
    foreach ($r['methods'] as $code => $m) {
        $rows[] = [($methods[$code] ?? $code) . ($code === 'exonere' ? ' (non encaissé)' : ''), $m['n'], $m['amount']];
    }
    $cash = array_filter($r['methods'], function ($k) { return $k !== 'exonere'; }, ARRAY_FILTER_USE_KEY);
    $s[] = ['id' => 'moyens', 'title' => 'Par moyen de paiement',
        'cols' => [['Moyen', 50, 'L', 'text'], $int('Paiements', 12), $money('Montant', 16)], 'rows' => $rows,
        'total' => ['Total encaissé', array_sum(array_column($cash, 'n')), round(array_sum(array_column($cash, 'amount')), 2)],
        'empty' => 'Aucun paiement enregistré.'];

    $rows = [];
    foreach ($r['clubs'] as $id => $c) {
        $rows[] = array_merge([$c['name'] !== '' ? $c['name'] : '(sans club)', $c['code']], $amountVals($c))
            + ['_href' => $payUrl . '?club=' . $id];
    }
    $s[] = ['id' => 'clubs', 'title' => 'Par club',
        'cols' => array_merge([['Club', 30, 'L', 'text'], ['Code', 11, 'L', 'text']], $amountCols),
        'rows' => $rows, 'total' => array_merge(['Total', ''], $amountVals($t))];

    $rows = [];
    foreach ($r['rules'] as $k => $l) $rows[] = array_merge([$l['label']], $amountVals($l)) + ['_href' => $payUrl . '?rule=' . $k];
    $s[] = ['id' => 'regles', 'title' => 'Par règle tarifaire',
        'cols' => array_merge([['Règle', 41, 'L', 'text']], $amountCols), 'rows' => $rows];

    $rows = [];
    foreach ($r['counts'] as $n => $l) $rows[] = array_merge([$n . ' départ' . ($n > 1 ? 's' : '')], $amountVals($l));
    $s[] = ['id' => 'departs', 'title' => 'Par nombre de départs',
        'cols' => array_merge([['Nombre de départs', 41, 'L', 'text']], $amountCols), 'rows' => $rows];

    $rows = [];
    foreach ($r['sessions'] as $n => $l) $rows[] = [$n ? 'Départ ' . $n : 'Non placé', $l['entries'], $l['archers'], $l['unpaid']];
    $s[] = ['id' => 'sessions', 'title' => 'Par départ (session)',
        'cols' => [['Départ', 41, 'L', 'text'], $int('Inscriptions', 14), $int('Archers', 14), $int('Non payés', 14)], 'rows' => $rows,
        'note' => 'Un archer à plusieurs départs compte dans chacun de ses départs.'];

    $person = [['Archer', 30, 'L', 'text'], ['Licence', 12, 'L', 'text'], ['Club', 26, 'L', 'text']];
    $base = function ($a) use ($payUrl) {
        return [eng_who($a), $a['code'] !== '' ? $a['code'] : '—', $a['club_name']]
            + ['_href' => $payUrl . '?q=' . rawurlencode($a['key']) . '#a-' . rawurlencode($a['key'])];
    };
    $list = function ($id, $title, $key, array $extraCols, $extra, $note = '') use ($r, $person, $base, &$s) {
        $rows = [];
        foreach ($r['lists'][$key] as $a) {
            $b = $base($a);
            $href = $b['_href'];
            unset($b['_href']);
            $rows[] = array_merge($b, $extra($a)) + ['_href' => $href];
        }
        $s[] = ['id' => $id, 'title' => $title . ' (' . count($rows) . ')', 'cols' => array_merge($person, $extraCols),
            'rows' => $rows, 'empty' => 'Aucun.', 'note' => $note];
    };
    $list('impayes', 'Impayés', 'unpaid', [['Départ(s)', 9, 'L', 'text'], $money('Dû')], function ($a) {
        return [eng_sessions_text($a), $a['due']];
    });
    $list('ecarts', 'Comptes en écart', 'diff', [$money('Payé'), $money('Dû'), $money('Écart')], function ($a) {
        return [(float) $a['payment']['EpAmount'], $a['due'], $a['diff']];
    }, 'Écart positif : reste à encaisser. Écart négatif : trop-perçu.');
    $list('sansregle', 'Archers sans règle', 'norule', [['Catégorie', 16, 'L', 'text'], ['Tarif appliqué', 18, 'L', 'text'], $money('Dû')], function ($a) {
        return [implode(' / ', $a['cats']), $a['price']['label'], $a['due']];
    }, 'Aucune règle ne correspond : grille par défaut ou tarif de base appliqué.');
    $list('mixtes', 'Archers à catégories mixtes', 'mixed', [['Catégories', 22, 'L', 'text'], ['Règle', 16, 'L', 'text']], function ($a) {
        return [implode(' / ', $a['cats']), $a['price']['label']];
    }, 'Catégories différentes selon les départs : la règle appliquée est celle du premier départ.');
    if ($r['lists']['short']) {
        $list('courte', 'Grille trop courte', 'short', [['Départ(s)', 9, 'L', 'text'], ['Règle', 16, 'L', 'text'], $money('Dû')], function ($a) {
            return [eng_sessions_text($a), $a['price']['label'], $a['due']];
        }, 'Plus de départs que de cases saisies : dernier total appliqué. Complétez la grille dans Tarifs.');
    }
    if ($r['lists']['partial']) {
        $list('partiel', '« Ne paie pas » partiel (IANSEO)', 'partial', [['Départ(s)', 9, 'L', 'text'], $money('Dû')], function ($a) {
            return [eng_sessions_text($a), $a['due']];
        }, '« Ne paie pas » coché sur une partie seulement des inscriptions : tarif normal appliqué.');
    }
    return $s;
}

$sections = eng_report_sections($report, $methods, $payUrl);
$stamp = str_replace('-', '', substr(eng_db_now(), 0, 10));   // date de la compétition, pas l'UTC de PHP

// ── Export CSV ───────────────────────────────────────────────────────────────
if (isset($_GET['csv'])) {
    $what = $_GET['csv'] === 'clubs' ? 'clubs' : 'archers';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="greffe_' . $what . '_' . $ENG_TOUR . '_' . $stamp . '.csv"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";   // BOM : Excel lit l'UTF-8 et les accents
    $out = fopen('php://output', 'w');
    $put = function (array $cells) use ($out) {
        fputcsv($out, array_map(function ($v) {
            if (is_float($v)) return number_format($v, 2, ',', '');   // virgule décimale pour Excel français
            return str_replace(["\r", "\n"], ' ', (string) $v);
        }, $cells), ';', '"', '');
    };
    if ($what === 'clubs') {
        $put(['Code', 'Club', 'Archers', 'Inscriptions', 'Dû', 'Encaissé', 'Exonéré', 'Reste', 'Trop-perçu']);
        foreach ($report['clubs'] as $c) {
            $put([$c['code'], $c['name'], $c['archers'], $c['departs'], $c['due'], $c['paid'], $c['waived'], $c['left'], $c['over']]);
        }
    } else {
        $put(['Licence', 'Nom', 'Prénom', 'Code club', 'Club', 'Nb départs', 'Départs', 'Catégories', 'Règle', 'Dû',
              'Statut', 'Moyen', 'Montant payé', 'Date', 'Par', 'Note', 'Écart', 'Signalements']);
        foreach ($accounts as $a) {
            $p = $a['payment'];
            $flags = array_filter([
                $a['mixed'] ? 'catégories mixtes' : '',
                in_array($a['price']['source'], ['default', 'base'], true) && $a['n'] > 0 ? 'sans règle' : '',
                $a['price']['short'] ? 'grille courte' : '',
                $a['pays'] === 'partial' ? 'ne paie pas partiel' : '',
                $a['n'] === 0 ? 'inscription supprimée' : '',
            ]);
            $put([$a['code'], $a['name'], $a['first'], $a['club_code'], $a['club_name'], $a['n'],
                  eng_sessions_text($a), implode(' / ', $a['cats']), $a['price']['label'], (float) $a['due'],
                  $statuses[$a['status']], $p ? ($methods[$p['EpMethod']] ?? $p['EpMethod']) : '',
                  $p ? (float) $p['EpAmount'] : '', $p ? eng_local_time($p['EpCreated'], 'd/m/Y H:i') : '',
                  $p ? $p['EpBy'] : '', $p ? $p['EpNote'] : '', $a['status'] === 'diff' ? (float) $a['diff'] : '',
                  implode(', ', $flags)]);
        }
    }
    fclose($out);
    exit;
}

/** Valeur d'une cellule selon le type de colonne. */
function eng_cell($v, $type, $moneyFn)
{
    if ($v === null || $v === '') return '';
    if ($type === 'money') return $moneyFn($v);
    if ($type === 'int') return (string) intval($v);
    return (string) $v;
}

// ── Bilan financier (PDF) : résumé, moyens de paiement, impayés par départ ──
if (isset($_GET['bilan'])) {
    require_once __DIR__ . '/lib/pdf.php';
    $t = $report['totals'];
    $pdf = eng_pdf_new('Greffe - Bilan financier');
    $pdf->SetFont($pdf->FontStd, 'B', 14);
    $pdf->Cell(eng_pdf_width($pdf), 8, 'Bilan financier', 0, 1, 'L');
    $pdf->SetFont($pdf->FontStd, '', 8);
    $pdf->Cell(eng_pdf_width($pdf), 4, 'Édité le ' . eng_local_time(eng_db_now(), 'd/m/Y à H:i'), 0, 1, 'L');

    eng_pdf_section($pdf, 'Résumé', 30);
    eng_pdf_kv($pdf, 'Archers / inscriptions', $t['archers'] . ' / ' . $t['departs']);
    eng_pdf_kv($pdf, 'Total dû', eng_pdf_money($t['due']), true);
    eng_pdf_kv($pdf, 'Encaissé', eng_pdf_money($t['paid']), true);
    eng_pdf_kv($pdf, 'Exonéré', eng_pdf_money($t['waived']));
    eng_pdf_kv($pdf, 'Reste à encaisser', eng_pdf_money($t['left']) . ' (' . $t['unpaid'] . ' archer' . ($t['unpaid'] > 1 ? 's' : '') . ' non payé' . ($t['unpaid'] > 1 ? 's' : '') . ')', true);
    if ($t['over'] > 0) eng_pdf_kv($pdf, 'Trop-perçu', eng_pdf_money($t['over']));

    // Tous les moyens, même sans paiement, pour un bilan toujours complet.
    eng_pdf_section($pdf, 'Encaissements par moyen de paiement');
    $rows = [];
    $n = 0;
    foreach ($methods as $code => $label) {
        if ($code === 'exonere') continue;
        $m = $report['methods'][$code] ?? ['n' => 0, 'amount' => 0.0];
        $rows[] = [$label, $m['n'], eng_pdf_money($m['amount'])];
        $n += $m['n'];
    }
    $ex = $report['methods']['exonere'] ?? ['n' => 0, 'amount' => 0.0];
    eng_pdf_table($pdf, [['Moyen de paiement', 50, 'L'], ['Paiements', 15, 'R'], ['Montant', 20, 'R']],
        $rows, ['Total encaissé', $n, eng_pdf_money($t['paid'])]);
    $pdf->SetFont($pdf->FontStd, 'I', 8);
    $pdf->Cell(eng_pdf_width($pdf), 6, 'Exonérés (non encaissés) : ' . $ex['n'] . ' paiement' . ($ex['n'] > 1 ? 's' : '') . ' pour '
        . eng_pdf_money($ex['amount']) . ($t['waived'] > $ex['amount'] ? ' ; exonérés IANSEO compris, ' . eng_pdf_money($t['waived']) . ' au total' : '') . '.', 0, 1, 'L');

    eng_pdf_section($pdf, 'Impayés par départ');
    $rows = [];
    foreach ($report['sessions'] as $s => $l) {
        $rows[] = [$s ? 'Départ ' . $s : 'Non placé', $l['archers'], $l['unpaid']];
    }
    eng_pdf_table($pdf, [['Départ', 50, 'L'], ['Archers inscrits', 15, 'R'], ['Impayés', 20, 'R']],
        $rows, ['Total (archers distincts)', $t['archers'], $t['unpaid']]);
    $pdf->SetFont($pdf->FontStd, 'I', 8);
    $pdf->MultiCell(eng_pdf_width($pdf), 4, 'Un archer inscrit sur plusieurs départs compte dans chacun d\'eux ; le total compte chaque archer une seule fois.', 0, 'L');

    $pdf->Output('greffe_bilan_' . $ENG_TOUR . '_' . $stamp . '.pdf', 'I');
    exit;
}

// ── État des chèques (PDF) : bordereau de remise en banque ───────────────────
if (isset($_GET['cheques'])) {
    require_once __DIR__ . '/lib/pdf.php';
    $ch = eng_cheques($accounts);
    $pdf = eng_pdf_new('Greffe - État des chèques');
    $pdf->SetFont($pdf->FontStd, 'B', 14);
    $pdf->Cell(eng_pdf_width($pdf), 8, 'État des chèques', 0, 1, 'L');
    $pdf->SetFont($pdf->FontStd, '', 8);
    $pdf->Cell(eng_pdf_width($pdf), 4, 'Édité le ' . eng_local_time(eng_db_now(), 'd/m/Y à H:i'), 0, 1, 'L');
    $pdf->SetY($pdf->GetY() + 3);

    if (!$ch['cheques']) {
        $pdf->SetFont($pdf->FontStd, '', 9);
        $pdf->Cell(eng_pdf_width($pdf), 6, 'Aucun paiement par chèque enregistré.', 0, 1, 'L');
    } else {
        $rows = [];
        foreach ($ch['cheques'] as $k => $c) {
            $n = count($c['archers']);
            $rows[] = [
                $k + 1,
                $c['number'] !== '' ? $c['number'] : 'sans n°',
                eng_local_time($c['date'], 'd/m/Y'),
                trim($c['club_name'] . ($c['club_code'] !== '' ? ' (' . $c['club_code'] . ')' : '')),
                $n > 1 ? $n . " archers :\n" . implode(', ', $c['archers']) : $c['archers'][0],
                eng_pdf_money($c['amount']),
            ];
        }
        eng_pdf_table($pdf,
            [['#', 4, 'R'], ['N° de chèque', 14, 'L'], ['Date', 10, 'L'], ['Club', 22, 'L'], ['Archer(s)', 38, 'L'], ['Montant', 12, 'R']],
            $rows, ['', '', '', '', 'Total : ' . $ch['count'] . ' chèque' . ($ch['count'] > 1 ? 's' : ''), eng_pdf_money($ch['total'])]);

        $pdf->SetY($pdf->GetY() + 4);
        eng_pdf_kv($pdf, 'Nombre de chèques', (string) $ch['count'], true);
        eng_pdf_kv($pdf, 'Montant total', eng_pdf_money($ch['total']), true);
        if ($ch['missing']) {
            eng_pdf_kv($pdf, 'Sans numéro', $ch['missing'] . ' chèque' . ($ch['missing'] > 1 ? 's' : '')
                . ' : numéro à compléter dans la note du paiement');
        }
        if (!$pdf->SamePage(30)) $pdf->AddPage();
        $pdf->SetY($pdf->GetY() + 8);
        $pdf->SetFont($pdf->FontStd, '', 9);
        $half = eng_pdf_width($pdf) / 2;
        $pdf->Cell($half, 6, 'Remis en banque le : ____ / ____ / ________', 0, 0, 'L');
        $pdf->Cell($half, 6, 'Signature :', 0, 1, 'L');
    }
    $pdf->Output('greffe_cheques_' . $ENG_TOUR . '_' . $stamp . '.pdf', 'I');
    exit;
}

// ── Export PDF ───────────────────────────────────────────────────────────────
if (isset($_GET['pdf'])) {
    require_once __DIR__ . '/lib/pdf.php';
    $pdf = eng_pdf_new('Greffe - État des inscriptions');
    $pdf->SetFont($pdf->FontStd, 'B', 14);
    $pdf->Cell(eng_pdf_width($pdf), 8, 'État des inscriptions et des paiements', 0, 1, 'L');
    $pdf->SetFont($pdf->FontStd, '', 8);
    $pdf->Cell(eng_pdf_width($pdf), 4, 'Édité le ' . eng_local_time(eng_db_now(), 'd/m/Y à H:i'), 0, 1, 'L');
    foreach ($sections as $sec) {
        eng_pdf_section($pdf, $sec['title']);
        if (!empty($sec['note'])) {
            $pdf->SetFont($pdf->FontStd, 'I', 7);
            $pdf->MultiCell(eng_pdf_width($pdf), 4, $sec['note'], 0, 'L');
        }
        if (!$sec['rows']) {
            $pdf->SetFont($pdf->FontStd, '', 8);
            $pdf->Cell(eng_pdf_width($pdf), 5, $sec['empty'] ?? 'Aucune donnée.', 0, 1, 'L');
            continue;
        }
        $fmt = function ($row) use ($sec) {
            unset($row['_href']);
            $out = [];
            foreach ($sec['cols'] as $i => $c) $out[] = eng_cell($row[$i] ?? null, $c[3], 'eng_pdf_money');
            return $out;
        };
        eng_pdf_table($pdf, $sec['cols'], array_map($fmt, $sec['rows']), isset($sec['total']) ? $fmt($sec['total']) : null);
    }
    $pdf->Output('greffe_etat_' . $ENG_TOUR . '_' . $stamp . '.pdf', 'I');
    exit;
}

// ── Page ─────────────────────────────────────────────────────────────────────
$t = $report['totals'];
$PAGE_TITLE = 'Greffe - Rapport - Bilan';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
#eng {
    /* Couleurs : variables d'IANSEO (Common/Styles/colors.css), redéfinies par le module THEME pour chaque palette
       et pour le mode sombre. États : --text-success / --text-danger / --text-warning mêlées à --black (texte) ou à
       --row-background (fonds), lisibles dans les deux modes. */
    --bleu:var(--header-dark); --sur-bleu:var(--row-main-text); --bleu-fonce:var(--main-text); --bleu-clair:var(--table-background);
    --bleu-survol:color-mix(in srgb, var(--header-dark) 82%, var(--black)); --lien:var(--thm-link, var(--header-dark));
    --gris:var(--text-dark); --sous:var(--text-muted); --bord:var(--input-border); --fond:var(--header-light);
    --carte:var(--row-background); --champ:var(--text-white); --champ-texte:var(--black);
    --vert:color-mix(in srgb, var(--text-success) 60%, var(--black)); --ok-fond:color-mix(in srgb, var(--text-success) 14%, var(--row-background)); --ok-bord:color-mix(in srgb, var(--text-success) 45%, var(--row-background));
    --rouge:color-mix(in srgb, var(--text-danger) 70%, var(--black)); --err-fond:color-mix(in srgb, var(--text-danger) 14%, var(--row-background)); --err-bord:color-mix(in srgb, var(--text-danger) 45%, var(--row-background));
    --orange:color-mix(in srgb, var(--text-warning) 50%, var(--black)); --warn-fond:color-mix(in srgb, var(--text-warning) 16%, var(--row-background)); --warn-bord:color-mix(in srgb, var(--text-warning) 55%, var(--row-background));
    color:var(--gris); font-size:13px; max-width:1100px; }
#eng .bar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:14px; }
#eng .bar a { display:inline-block; background:var(--carte); border:1px solid var(--bleu); color:var(--lien); font-weight:600;
              padding:6px 14px; border-radius:6px; text-decoration:none; }
#eng .bar a:hover { background:var(--bleu-clair); }
#eng .bar a.primary { background:var(--bleu); color:var(--sur-bleu); }
#eng .kpis { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
#eng .kpi { background:var(--carte); border:1px solid var(--bord); border-radius:6px; padding:8px 14px; min-width:140px; }
#eng .kpi span { display:block; font-size:11px; color:var(--sous); }
#eng .kpi b { font-size:20px; color:var(--bleu-fonce); }
#eng .kpi.paid b { color:var(--vert); }
#eng .kpi.left b { color:var(--rouge); }
#eng .kpi.waived b, #eng .kpi.over b { color:var(--orange); }
#eng .toc { font-size:12px; margin:0 0 14px; }
#eng .toc a { margin-right:10px; }
#eng .card { border:1px solid var(--bord); border-radius:6px; background:var(--carte); box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:16px; }
#eng .card > h3 { margin:0; padding:9px 14px; font-size:14px; color:var(--sur-bleu); background:var(--bleu); border-radius:5px 5px 0 0; }
#eng .card > div { padding:10px 14px; overflow-x:auto; }
#eng .note { font-size:11px; color:var(--sous); margin:0 0 8px; font-style:italic; }
#eng table.rep { border-collapse:collapse; width:100%; }
#eng table.rep th { text-align:left; border-bottom:2px solid var(--bleu); padding:4px 8px; font-size:12px; color:var(--bleu-fonce); }
#eng table.rep td { border-bottom:1px solid var(--bleu-clair); padding:4px 8px; }
#eng table.rep .R { text-align:right; white-space:nowrap; }
#eng table.rep tr.total td { font-weight:bold; border-top:2px solid var(--bleu); border-bottom:0; }
#eng table.rep tbody tr:hover td { background:var(--bleu-clair); }
#eng .empty { color:var(--sous); }
#eng .msg.warn { background:var(--warn-fond); border:1px solid var(--warn-bord); color:var(--orange); border-radius:6px; padding:10px 14px; margin-bottom:12px; }
@media (min-width: 1000px) { #eng .two { display:grid; grid-template-columns:1fr 1fr; gap:16px; } }
@media print { #eng .bar, #eng .toc { display:none; } }
</style>

<div id="eng">
    <?= eng_nav('reporting') ?>

    <?php if (eng_config_is_empty($config)): ?>
        <div class="msg warn">Aucun tarif n'est configuré : tous les montants sont à 0 €.
            <a href="<?= eng_h($ENG_ROOT . 'tarifs.php') ?>">Configurer les tarifs</a></div>
    <?php endif; ?>

    <div class="bar">
        <a class="primary" href="<?= eng_h($self . '?bilan=1') ?>" target="_blank">Bilan financier (PDF)</a>
        <a href="<?= eng_h($self . '?pdf=1') ?>" target="_blank">État complet (PDF)</a>
        <a href="<?= eng_h($self . '?csv=archers') ?>">CSV par archer</a>
        <a href="<?= eng_h($self . '?csv=clubs') ?>">CSV par club</a>
        <?php if ($t['archers'] > 0): ?><a href="<?= eng_h($ENG_ROOT . 'recu.php?club=all') ?>" target="_blank">Récapitulatifs clubs (PDF)</a><?php endif; ?>
        <?php if (isset($report['methods']['cheque'])): ?><a href="<?= eng_h($self . '?cheques=1') ?>" target="_blank">État des chèques (PDF)</a><?php endif; ?>
    </div>

    <div class="kpis">
        <div class="kpi"><span>Archers · inscriptions</span><b><?= $t['archers'] ?> · <?= $t['departs'] ?></b></div>
        <div class="kpi"><span>Total dû</span><b><?= eng_money($t['due']) ?></b></div>
        <div class="kpi paid"><span>Encaissé</span><b><?= eng_money($t['paid']) ?></b></div>
        <div class="kpi waived"><span>Exonéré</span><b><?= eng_money($t['waived']) ?></b></div>
        <div class="kpi left"><span>Reste à encaisser · <?= $t['unpaid'] ?> non payé<?= $t['unpaid'] > 1 ? 's' : '' ?></span><b><?= eng_money($t['left']) ?></b></div>
        <?php if ($t['over'] > 0): ?><div class="kpi over"><span>Trop-perçu</span><b><?= eng_money($t['over']) ?></b></div><?php endif; ?>
    </div>

    <div class="toc">
        <?php foreach ($sections as $sec): if ($sec['id'] === 'synthese') continue; ?>
            <a href="#s-<?= eng_h($sec['id']) ?>"><?= eng_h($sec['title']) ?></a>
        <?php endforeach; ?>
    </div>

    <?php
    $render = function (array $sec) use ($payUrl) {
        echo '<div class="card" id="s-' . eng_h($sec['id']) . '"><h3>' . eng_h($sec['title']) . '</h3><div>';
        if (!empty($sec['note'])) echo '<p class="note">' . eng_h($sec['note']) . '</p>';
        if (!$sec['rows']) {
            echo '<span class="empty">' . eng_h($sec['empty'] ?? 'Aucune donnée.') . '</span></div></div>';
            return;
        }
        echo '<table class="rep"><thead><tr>';
        foreach ($sec['cols'] as $c) echo '<th class="' . $c[2] . '">' . eng_h($c[0]) . '</th>';
        echo '</tr></thead><tbody>';
        $line = function ($row, $class = '') use ($sec) {
            echo '<tr' . ($class ? ' class="' . $class . '"' : '') . '>';
            foreach ($sec['cols'] as $i => $c) {
                $txt = eng_h(eng_cell($row[$i] ?? null, $c[3], 'eng_money'));
                if ($i === 0 && !empty($row['_href'])) $txt = '<a href="' . eng_h($row['_href']) . '">' . $txt . '</a>';
                echo '<td class="' . $c[2] . '">' . $txt . '</td>';
            }
            echo '</tr>';
        };
        foreach ($sec['rows'] as $row) $line($row);
        echo '</tbody>';
        if (isset($sec['total'])) { echo '<tfoot>'; $line($sec['total'], 'total'); echo '</tfoot>'; }
        echo '</table></div></div>';
    };
    $byId = array_column($sections, null, 'id');
    // Disposition : petites ventilations côte à côte, grands tableaux en pleine largeur.
    echo '<div class="two">';
    $render($byId['moyens']);
    $render($byId['departs']);
    echo '</div>';
    $render($byId['clubs']);
    echo '<div class="two">';
    $render($byId['regles']);
    $render($byId['sessions']);
    echo '</div>';
    foreach ($sections as $sec) {
        if (!in_array($sec['id'], ['synthese', 'moyens', 'departs', 'clubs', 'regles', 'sessions'], true)) $render($sec);
    }
    ?>
</div>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
