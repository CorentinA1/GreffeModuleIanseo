<?php
/**
 * recu.php — GREFFE : reçus PDF.
 *
 *   ?account=<clé>   reçu d'un archer (licence, ou #EnId sans licence)
 *   ?club=<CoId>     récapitulatif d'un club : ses archers, montants, statut, moyen
 *   ?club=all        récapitulatifs de tous les clubs, un par page
 *
 * Ce sont des reçus, pas des factures : aucune numérotation légale. Un archer
 * non réglé reçoit un « récapitulatif » qui indique le reste dû.
 */
define('ENG_ACCESS', 'read');
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/accounts.php';
require_once __DIR__ . '/lib/report.php';
require_once __DIR__ . '/lib/pdf.php';

$tour     = eng_tournament();
$methods  = eng_methods();
$statuses = eng_statuses();
$accounts = eng_load_accounts($ENG_TOUR, eng_load_config());
$divs     = eng_codes('div');
$clss     = eng_codes('cls');
$printed  = eng_local_time(eng_db_now(), 'd/m/Y à H:i');

/** Organisateur, pour la mention de bas de reçu. */
function eng_organizer($tour)
{
    $name = trim((string) ($tour->ToComDescr ?? ''));
    $code = trim((string) ($tour->ToCommitee ?? ''));
    return $name !== '' ? $name . ($code !== '' ? ' (' . $code . ')' : '') : $code;
}

/** Titre de document, date d'édition. */
function eng_doc_title(IanseoPdf $pdf, $title, $printed)
{
    $pdf->SetFont($pdf->FontStd, 'B', 15);
    $pdf->Cell(eng_pdf_width($pdf), 9, $title, 0, 1, 'L');
    $pdf->SetFont($pdf->FontStd, '', 8);
    $pdf->Cell(eng_pdf_width($pdf), 4, 'Édité le ' . $printed, 0, 1, 'L');
    $pdf->SetY($pdf->GetY() + 3);
}

/**
 * Mention finale : document non fiscal, cachet de l'organisateur.
 *
 * @param bool $isReceipt Vrai si rien ne reste à régler : cadre pour le cachet.
 * @param bool $waived Exonération : la mention ne parle pas de règlement.
 */
function eng_doc_footer(IanseoPdf $pdf, $tour, $isReceipt, $waived = false)
{
    $pdf->SetY($pdf->GetY() + 4);
    $pdf->SetFont($pdf->FontStd, 'I', 8);
    $org = eng_organizer($tour);
    $txt = ($waived
            ? 'Ce document atteste l\'exonération des droits d\'inscription. Il ne constitue pas une facture.'
            : ($isReceipt
                ? 'Ce reçu atteste le règlement des droits d\'inscription. Il ne constitue pas une facture.'
                : 'Ce document récapitule les droits d\'inscription. Il ne constitue pas une facture.'))
        . ($org !== '' ? "\nOrganisateur : " . $org : '');
    $pdf->MultiCell(eng_pdf_width($pdf), 4, $txt, 0, 'L');
    if ($isReceipt) {
        if (!$pdf->SamePage(30)) $pdf->AddPage();
        $pdf->SetY($pdf->GetY() + 6);
        $x = IanseoPdf::sideMargin + eng_pdf_width($pdf) - 80;
        $pdf->SetX($x);
        $pdf->SetFont($pdf->FontStd, '', 8);
        $pdf->Cell(80, 22, 'Cachet et signature de l\'organisateur', 1, 1, 'C', false, '', 0, false, 'T', 'T');
    }
}

/** Reçu (ou récapitulatif) d'un archer, sur la page courante. */
function eng_receipt(IanseoPdf $pdf, array $a, $tour, array $methods, array $divs, array $clss, $printed)
{
    $p = $a['payment'];
    $settled = in_array($a['status'], ['paid', 'waived', 'diff'], true);
    eng_doc_title($pdf, $settled ? 'Reçu d\'inscription' : 'Récapitulatif d\'inscription', $printed);

    eng_pdf_kv($pdf, 'Archer', trim(mb_strtoupper($a['name']) . ' ' . $a['first']), true);
    eng_pdf_kv($pdf, 'Licence', $a['code'] !== '' ? $a['code'] : 'sans licence');
    eng_pdf_kv($pdf, 'Club', trim($a['club_name'] . ($a['club_code'] !== '' ? ' (' . $a['club_code'] . ')' : '')));

    eng_pdf_section($pdf, 'Inscription' . ($a['n'] > 1 ? 's' : ''), 12);
    $rows = [];
    foreach ($a['entries'] as $e) {
        $d = strtoupper(trim($e['EnDivision']));
        $c = strtoupper(trim($e['EnClass']));
        $rows[] = [
            intval($e['QuSession']) ? 'Départ ' . intval($e['QuSession']) : 'Non placé',
            (string) ($e['Target'] ?? ''),
            $d . (($divs[$d] ?? '') !== '' ? ' — ' . $divs[$d] : ''),
            $c . (($clss[$c] ?? '') !== '' ? ' — ' . $clss[$c] : ''),
        ];
    }
    eng_pdf_table($pdf, [['Départ', 14, 'L'], ['Cible', 8, 'L'], ['Arme', 30, 'L'], ['Catégorie', 30, 'L']], $rows);

    eng_pdf_section($pdf, 'Droits d\'inscription', 12);
    eng_pdf_kv($pdf, 'Tarif appliqué', $a['price']['label'] . ' — ' . $a['n'] . ' départ' . ($a['n'] > 1 ? 's' : ''));
    eng_pdf_kv($pdf, 'Montant', eng_pdf_money($a['due']), true);

    $when = $p ? eng_local_time($p['EpCreated'], 'd/m/Y') : '';
    $how = $p ? ($methods[$p['EpMethod']] ?? $p['EpMethod']) : '';
    switch ($a['status']) {
        case 'paid':
            eng_pdf_box($pdf, 'Réglé : ' . eng_pdf_money($p['EpAmount']) . ' — ' . $how . ' — le ' . $when, [234, 250, 240]);
            break;
        case 'waived':
            eng_pdf_box($pdf, 'Inscription exonérée — aucun montant à régler', [238, 240, 242]);
            break;
        case 'exempt':
            eng_pdf_box($pdf, 'Exonéré (IANSEO) — aucun montant à régler', [238, 240, 242]);
            break;
        case 'diff':
            eng_pdf_box($pdf, 'Réglé : ' . eng_pdf_money($p['EpAmount']) . ' — ' . $how . ' — le ' . $when . "\n"
                . ($a['diff'] > 0 ? 'Reste à régler : ' . eng_pdf_money($a['diff']) : 'Trop-perçu : ' . eng_pdf_money(-$a['diff'])),
                [255, 241, 214]);
            break;
        default:
            eng_pdf_box($pdf, 'Reste à régler : ' . eng_pdf_money($a['due']), [253, 236, 234]);
    }
    if ($p && $p['EpNote'] !== '') eng_pdf_kv($pdf, 'Note', $p['EpNote']);

    // Cachet de l'organisateur seulement si rien ne reste à régler.
    $cleared = in_array($a['status'], ['paid', 'waived', 'exempt'], true) ||($a['status'] === 'diff' && $a['diff'] < 0);
    eng_doc_footer($pdf, $tour, $cleared, in_array($a['status'], ['waived', 'exempt'], true));
}

/** Récapitulatif d'un club, sur la page courante. */
function eng_club_recap(IanseoPdf $pdf, array $club, array $list, $tour, array $methods, array $statuses, $printed)
{
    eng_doc_title($pdf, 'Récapitulatif club', $printed);
    eng_pdf_kv($pdf, 'Club', trim($club['name'] . ($club['code'] !== '' ? ' (' . $club['code'] . ')' : '')) ?: '(sans club)', true);
    eng_pdf_kv($pdf, 'Archers', $club['archers'] . ' — ' . $club['departs'] . ' inscription' . ($club['departs'] > 1 ? 's' : ''));

    eng_pdf_section($pdf, 'Archers', 12);
    $rows = [];
    foreach ($list as $a) {
        $p = $a['payment'];
        $rows[] = [
            $a['n'] === 0 ? '(inscription supprimée)' : trim(mb_strtoupper($a['name']) . ' ' . $a['first']),
            $a['code'],
            eng_sessions_text($a),
            implode(' / ', $a['cats']),
            eng_pdf_money($a['due']),
            $statuses[$a['status']],
            // Moyen et date, puis la note (n° de chèque…) à la ligne. Exonéré : la note seule.
            trim(($p && $a['status'] !== 'waived' ? ($methods[$p['EpMethod']] ?? $p['EpMethod']) . ' ' . eng_local_time($p['EpCreated'], 'd/m/Y') : '')
                . ($p && $p['EpNote'] !== '' ? "\n" . $p['EpNote'] : '')),
        ];
    }
    eng_pdf_table($pdf,
        [['Archer', 27, 'L'], ['Licence', 11, 'L'], ['Départ(s)', 8, 'L'], ['Catégorie', 13, 'L'], ['Dû', 10, 'R'], ['Statut', 13, 'L'], ['Paiement', 25, 'L']],
        $rows, ['Total', '', '', '', eng_pdf_money($club['due']), '', '']);

    eng_pdf_section($pdf, 'Bilan', 25);
    eng_pdf_kv($pdf, 'Total dû', eng_pdf_money($club['due']), true);
    eng_pdf_kv($pdf, 'Encaissé', eng_pdf_money($club['paid']));
    if ($club['waived'] > 0) eng_pdf_kv($pdf, 'Exonéré', eng_pdf_money($club['waived']));
    if ($club['over'] > 0) eng_pdf_kv($pdf, 'Trop-perçu', eng_pdf_money($club['over']));
    eng_pdf_box($pdf, $club['left'] > 0 ? 'Reste à régler : ' . eng_pdf_money($club['left']) : 'Club entièrement réglé',
        $club['left'] > 0 ? [253, 236, 234] : [234, 250, 240]);

    eng_doc_footer($pdf, $tour, $club['left'] <= 0);
}

$safeName = function ($s) { return trim(preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $s), '_'); };

// ── Reçu d'un archer ─────────────────────────────────────────────────────────
if (isset($_GET['account'])) {
    $a = $accounts[(string) $_GET['account']] ?? null;
    if (!$a || $a['n'] === 0) {
        http_response_code(404);
        exit('Archer introuvable dans cette compétition.');
    }
    $pdf = eng_pdf_new('GREFFE - Reçu d\'inscription');
    eng_receipt($pdf, $a, $tour, $methods, $divs, $clss, $printed);
    $pdf->Output('recu_' . $safeName($a['name'] . '_' . $a['first']) . '.pdf', 'I');
    exit;
}

// ── Récapitulatif(s) club ────────────────────────────────────────────────────
if (isset($_GET['club'])) {
    $byClub = [];
    foreach ($accounts as $a) {
        if ($a['n'] > 0) $byClub[$a['club_id']][] = $a;
    }
    $report = eng_report(array_filter($accounts, function ($a) { return $a['n'] > 0; }));
    $all = $_GET['club'] === 'all';
    $ids = $all ? array_keys($report['clubs']) : [intval($_GET['club'])];
    $ids = array_values(array_filter($ids, function ($id) use ($byClub) { return isset($byClub[$id]); }));
    if (!$ids) {
        http_response_code(404);
        exit('Club introuvable ou sans archer dans cette compétition.');
    }

    $pdf = eng_pdf_new('GREFFE - Récapitulatif club');
    foreach ($ids as $k => $id) {
        if ($k > 0) {
            $pdf->startPageGroup();   // pagination propre à chaque club
            $pdf->AddPage();
        }
        eng_club_recap($pdf, $report['clubs'][$id], $byClub[$id], $tour, $methods, $statuses, $printed);
    }
    $club = $report['clubs'][$ids[0]];
    $pdf->Output($all ? 'recapitulatifs_clubs.pdf' : 'recapitulatif_' . $safeName($club['name'] ?: $club['code']) . '.pdf', 'I');
    exit;
}

http_response_code(400);
echo 'Paramètre attendu : account ou club.';
