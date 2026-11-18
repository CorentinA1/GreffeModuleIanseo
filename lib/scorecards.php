<?php
/**
 * lib/scorecards.php — GREFFE : feuilles de marque complétées, une page par archer avec toutes ses distances.
 *
 * La feuille est celle d'IANSEO (Qualification → Impression des feuilles de marque, « Score complet », « Feuille de
 * marque de l'archer avec toutes les distances sur une seule page », « avec les résultats des flèches ») : dessinée
 * par la classe du cœur ScorePDF, à la place et à la taille que lui donne CreateSessionScorecard()
 * (Common/Lib/ScorecardsLib.php). Le module la sort par club ou par archer, ce que l'impression d'IANSEO ne fait pas.
 *
 * Fonctionnalité inspirée du module « Feuilles de marque par club ou archer » de Steph
 * (https://github.com/Steph-Krs/IanseoModules). Lecture seule : rien n'est écrit en base.
 *
 * Préfixe : eng_sc_.
 */

require_once 'Common/pdf/ScorePDF.inc.php';

/**
 * L'onglet « Feuilles de marque » est-il proposé ? Voir eng_scorecards_tab() (lib/boot.php) : ScoreKeeper NG,
 * NG PRO ou NG Live activé, et lecture sur les qualifications.
 *
 * @return bool
 */
function eng_sc_available()
{
    return eng_scorecards_tab();
}

/**
 * Nombre de distances, et parcours (campagne, nature, 3D), qui ont leurs propres feuilles.
 *
 * @return array [distances, field].
 */
function eng_sc_competition()
{
    $r = safe_fetch(safe_r_sql("SELECT ToNumDist, ToCategory FROM Tournament WHERE ToId = " . (int) $_SESSION['TourId']));
    return [
        'distances' => $r ? max(1, min(8, (int) $r->ToNumDist)) : 1,
        'field'     => $r ? (((int) $r->ToCategory & 12) !== 0) : false,
    ];
}

/**
 * Départs de qualification : ordre => libellé.
 *
 * @return array<int, string>
 */
function eng_sc_sessions()
{
    $out = [];
    $rs = safe_r_sql("SELECT SesOrder, SesName FROM Session WHERE SesTournament = " . (int) $_SESSION['TourId'] . "
        AND SesType = 'Q' ORDER BY SesOrder");
    while ($r = safe_fetch($rs)) {
        $out[(int) $r->SesOrder] = $r->SesOrder . ($r->SesName !== '' ? ' : ' . $r->SesName : '');
    }
    return $out;
}

/**
 * Options de mise en page d'une requête : celles de l'impression d'IANSEO, sous les mêmes noms.
 *
 * @param array $src $_GET.
 * @param array $sessions eng_sc_sessions().
 * @return array [sessions => départs choisis (tous si aucun), page, header, logos, flags].
 */
function eng_sc_options(array $src, array $sessions)
{
    $chosen = [];
    foreach ((array) ($src['Sessions'] ?? []) as $s) {
        if (isset($sessions[(int) $s])) $chosen[(int) $s] = (int) $s;
    }
    ksort($chosen);
    return [
        'sessions' => $chosen ? array_values($chosen) : array_keys($sessions),
        'page'     => !empty($src['ScorePageHeaderFooter']),
        'header'   => !empty($src['ScoreHeader']),
        'logos'    => !empty($src['ScoreLogos']),
        'flags'    => !empty($src['ScoreFlags']),
    ];
}

/**
 * Les feuilles des archers placés, prêtes pour ScorePDF::DrawScoreNew() : colonnes de la requête d'IANSEO
 * (GetScoreBySessionQuery()), flèches et scores compris.
 *
 * @param int[] $orders Départs.
 * @param array $filter ['club' => code] ou ['archer' => EnId] pour ne lire qu'un destinataire.
 * @return array Feuilles, par club, nom de l'archer, départ.
 */
function eng_sc_read_cards(array $orders, array $filter = [])
{
    $orders = array_filter(array_map('intval', $orders));
    if (!$orders) return [];
    $tour = (int) $_SESSION['TourId'];

    $cols  = '';
    $joins = '';
    $tot   = '0';
    for ($i = 1; $i <= 8; $i++) {
        $cols .= ", d$i.DiEnds AS NumEnds$i, d$i.DiArrows AS NumArrows$i"
            . ", QuD{$i}Arrowstring AS Arr$i, QuD{$i}Score AS QuD$i, QuD{$i}Gold AS QuGD$i, QuD{$i}XNine AS QuXD$i, $tot AS Tot$i";
        $tot  .= " + QuD{$i}Score";   // cumul des distances précédentes
        $joins .= " LEFT JOIN DistanceInformation d$i ON d$i.DiTournament = EnTournament AND d$i.DiType = 'Q'"
            . " AND d$i.DiSession = QuSession AND d$i.DiDistance = $i";
    }

    $where = '';
    if (isset($filter['archer'])) {
        $where = ' AND EnId = ' . (int) $filter['archer'];
    } elseif (isset($filter['club'])) {
        $where = $filter['club'] === '' ? " AND IFNULL(CoCode, '') = ''" : ' AND CoCode = ' . StrSafe_DB($filter['club']);
    }

    // Countries en LEFT JOIN : un archer sans club a quand même sa feuille. Blason absent : repli sur les caractères
    // de la compétition dans cette requête (sinon le cœur relit la base à chaque volée).
    $sql = "SELECT EnId, TRIM(EnCode) AS EnCode, IFNULL(CoCode, '') AS CoCode, IFNULL(CoName, '') AS CoName,
            QuSession AS Session, QuTarget AS AtTarget, UPPER(QuLetter) AS Letter,
            CONCAT(QuTarget, UPPER(QuLetter)) AS tNo, '' AS Dist, EnDob AS DoB, EdEmail AS Email,
            CONCAT(EnFirstName, ' ', EnName) AS Ath, IF(CoId IS NULL, '', CONCAT(CoCode, ' - ', CoName)) AS Noc,
            EnDivision AS `Div`, EnClass AS Cls, CONCAT(EnDivision, ' ', EnClass) AS Cat, SesName,
            QuScore AS Score, QuIrmType AS Irm,
            IF(IFNULL(TfGolds, '') = '', ToGolds, TfGolds) AS Golds,
            IF(IFNULL(TfXNine, '') = '', ToXNine, TfXNine) AS XNine,
            IF(IFNULL(TfGoldsChars, '') = '', ToGoldsChars, TfGoldsChars) AS GoldsChars,
            IF(IFNULL(TfXNineChars, '') = '', ToXNineChars, TfXNineChars) AS XNineChars,
            '' AS D0, Td1 AS D1, Td2 AS D2, Td3 AS D3, Td4 AS D4, Td5 AS D5, Td6 AS D6, Td7 AS D7, Td8 AS D8,
            d1.DiEnds AS NumEnds0, d1.DiArrows AS NumArrows0 $cols
        FROM Entries
        INNER JOIN Qualifications ON QuId = EnId
        INNER JOIN Tournament ON ToId = EnTournament
        INNER JOIN Session ON SesTournament = EnTournament AND SesOrder = QuSession AND SesType = 'Q'
        LEFT JOIN Countries ON CoId = EnCountry AND CoTournament = EnTournament
        LEFT JOIN TargetFaces ON TfTournament = EnTournament AND TfId = EnTargetFace
        LEFT JOIN ExtraData ON EdId = EnId AND EdType = 'E'
        LEFT JOIN TournamentDistances ON TdType = ToType AND TdTournament = ToId
            AND CONCAT(TRIM(EnDivision), TRIM(EnClass)) LIKE TdClasses
        $joins
        WHERE EnTournament = $tour AND QuTarget > 0 AND QuSession IN (" . implode(',', $orders) . ")$where
        ORDER BY CoCode, EnFirstName, EnName, EnCode, QuSession";

    $cards = [];
    $seen  = [];
    $rs = safe_r_sql($sql);
    while ($r = safe_fetch($rs)) {
        // Une catégorie qui répond à deux règles de distances ramènerait deux fois le même archer.
        if (isset($seen[$r->EnId])) continue;
        $seen[$r->EnId] = true;
        $cards[] = (array) $r;
    }
    safe_free_result($rs);
    return $cards;
}

/**
 * Distances tirées par l'archer, dans l'ordre : réglées pour son départ (volées) et pas « - » pour sa catégorie.
 *
 * @param array $card
 * @param int $numDist Nombre de distances de la compétition.
 * @return int[]
 */
function eng_sc_card_distances(array $card, $numDist)
{
    $out = [];
    for ($d = 1; $d <= $numDist; $d++) {
        if (trim((string) ($card['D' . $d] ?? '')) === '-') continue;
        $out[] = $d;
    }
    return $out;
}

/**
 * Saisie d'un archer : flèches attendues (volées × flèches de son départ) et saisies, sur ses distances.
 * Distance dont les volées ne sont pas réglées : au moins une flèche attendue.
 *
 * @return array [shot, expected, complete].
 */
function eng_sc_card_progress(array $card, $numDist)
{
    $shot = 0;
    $expected = 0;
    $complete = true;
    foreach (eng_sc_card_distances($card, $numDist) as $d) {
        $n    = strlen(str_replace(' ', '', (string) ($card['Arr' . $d] ?? '')));
        $want = (int) ($card['NumEnds' . $d] ?? 0) * (int) ($card['NumArrows' . $d] ?? 0);
        $shot += $n;
        $expected += $want;
        if ($n < max(1, $want)) $complete = false;
    }
    return ['shot' => $shot, 'expected' => $expected, 'complete' => $complete];
}

/**
 * État de la saisie d'un départ, d'après ses archers attendus (sans IRM). Fonction pure.
 *
 * Un départ peut être tiré sur feuilles de marque papier même quand ScoreKeeper est activé (téléphones en panne…) :
 * seuls les totaux sont alors saisis dans IANSEO, sans aucune flèche. Ce départ n'a pas de feuille à sortir, et il
 * ne doit pas bloquer les départs saisis flèche par flèche.
 *
 * @param int $archers Archers attendus (placés, sans IRM).
 * @param int $complete Archers dont toutes les flèches sont saisies.
 * @param int $withArrows Archers ayant au moins une flèche saisie.
 * @param int $withScore Archers ayant un score (total) saisi.
 * @return string 'complete' (feuilles disponibles), 'paper' (totaux sans flèches : pas de feuille),
 *                'pending' (saisie en cours ou pas commencée), 'empty' (aucun archer attendu).
 */
function eng_sc_session_status($archers, $complete, $withArrows, $withScore)
{
    if ($archers <= 0) return 'empty';
    if ($complete >= $archers) return 'complete';
    if ($withArrows === 0 && $withScore > 0) return 'paper';
    return 'pending';
}

/**
 * Ce que la page affiche : l'état de la saisie de chaque départ, et les clubs et archers des départs dont les
 * feuilles sont disponibles (saisis flèche par flèche). Un archer marqué par un IRM (non partant, abandon,
 * disqualifié…) n'est pas attendu.
 *
 * Deux contrôles : l'onglet n'existe qu'avec ScoreKeeper activé (eng_scorecards_tab()) ; puis, départ par départ,
 * les feuilles ne sont proposées que pour les départs dont toutes les flèches sont remontées. Un départ saisi sur
 * papier (totaux sans flèches) est mis de côté ; un départ en cours fait attendre, comme avant.
 *
 * @param array $sessions eng_sc_sessions().
 * @param int $numDist
 * @return array [clubs => code => [code, name, archers, sessions] (départs complets seulement), archers,
 *               sessions => ordre => [status, archers, complete], scoring => [complete, ready, done, todo, missing]].
 */
function eng_sc_summary(array $sessions, $numDist)
{
    $cards = eng_sc_read_cards(array_keys($sessions));
    $stats = array_fill_keys(array_keys($sessions), ['archers' => 0, 'complete' => 0, 'arrows' => 0, 'score' => 0]);
    $prog  = [];
    foreach ($cards as $i => $c) {
        $prog[$i] = eng_sc_card_progress($c, $numDist);
        if ((int) $c['Irm'] !== 0) continue;
        $s = (int) $c['Session'];
        $stats[$s]['archers']++;
        if ($prog[$i]['complete']) $stats[$s]['complete']++;
        if ($prog[$i]['shot'] > 0) $stats[$s]['arrows']++;
        if ((int) $c['Score'] > 0) $stats[$s]['score']++;
    }
    $out = ['clubs' => [], 'archers' => 0, 'sessions' => [],
            'scoring' => ['complete' => false, 'ready' => [], 'done' => 0, 'todo' => 0, 'missing' => []]];
    foreach ($stats as $s => $st) {
        $out['sessions'][$s] = ['status' => eng_sc_session_status($st['archers'], $st['complete'], $st['arrows'], $st['score']),
            'archers' => $st['archers'], 'complete' => $st['complete']];
        if ($out['sessions'][$s]['status'] === 'complete') $out['scoring']['ready'][] = $s;
    }

    foreach ($cards as $i => $c) {
        $s      = (int) $c['Session'];
        $status = $out['sessions'][$s]['status'];
        $irm    = (int) $c['Irm'] !== 0;
        if ($status === 'pending' && !$irm) {
            if ($prog[$i]['complete']) {
                $out['scoring']['done']++;
            } else {
                $out['scoring']['todo']++;
                $out['scoring']['missing'][] = ['club' => (string) $c['CoCode'], 'name' => $c['Ath'], 'session' => $s,
                    'target' => $c['AtTarget'] . $c['Letter'], 'shot' => $prog[$i]['shot'], 'expected' => $prog[$i]['expected']];
            }
        }
        if ($status !== 'complete') continue;
        $code = (string) $c['CoCode'];
        if (!isset($out['clubs'][$code])) {
            $out['clubs'][$code] = ['code' => $code, 'name' => $c['CoName'], 'archers' => [], 'sessions' => array_fill_keys(array_keys($sessions), 0)];
        }
        $out['clubs'][$code]['archers'][] = ['id' => (int) $c['EnId'], 'name' => (string) $c['Ath'], 'licence' => (string) $c['EnCode'],
            'cat' => trim($c['Cls'] . $c['Div']), 'session' => $s, 'target' => $c['AtTarget'] . $c['Letter'],
            'score' => (int) $c['Score'], 'irm' => $irm];
        $out['clubs'][$code]['sessions'][$s]++;
        $out['archers']++;
    }
    // Feuilles proposées dès qu'un départ est saisi flèche par flèche, même si d'autres sont encore en cours.
    $out['scoring']['complete'] = (bool) $out['scoring']['ready'];
    return $out;
}

/**
 * Départs dont les feuilles peuvent sortir : ceux saisis flèche par flèche, quel que soit l'état des autres. Pour
 * les PDF et l'archive, qui ne font pas confiance aux départs demandés par l'adresse.
 *
 * @param array $sessions eng_sc_sessions().
 * @param int $numDist
 * @return array Ordre => libellé.
 */
function eng_sc_ready_sessions(array $sessions, $numDist)
{
    $sum = eng_sc_summary($sessions, $numDist);
    return array_intersect_key($sessions, array_flip($sum['scoring']['ready']));
}

/**
 * Le document de feuilles de marque d'IANSEO, images intégrées à leur taille d'impression.
 *
 * IANSEO intègre les images de la compétition (en-tête, logos, bandeau du bas) telles qu'elles ont été envoyées :
 * un logo de 1 300 pixels imprimé sur 15 mm pèse plus de 100 Ko. Une archive porte un document par club ou par
 * archer, chacun avec sa copie des images : elles sont réduites à leur taille imprimée, en 300 dpi (la résolution
 * par défaut de TCPDF, sans perte visible à l'impression), JPEG qualité 85.
 */
class EngScorecardPdf extends ScorePDF
{
    public function __construct($Portrait = true)
    {
        parent::__construct($Portrait);
        $this->setJPEGQuality(85);
    }

    public function Image($file, $x = null, $y = null, $w = 0, $h = 0, $type = '', $link = '', $align = '', $resize = false,
                          $dpi = 300, $palign = '', $ismask = false, $imgmask = false, $border = 0, $fitbox = false,
                          $hidden = false, $fitonpage = false, $alt = false, $altimgs = array())
    {
        return parent::Image(eng_sc_print_image($file, $w, $h, $dpi, $fitbox), $x, $y, $w, $h, $type, $link, $align, true,
            $dpi, $palign, $ismask, $imgmask, $border, $fitbox, $hidden, $fitonpage, $alt, $altimgs);
    }
}

/**
 * Copie JPEG d'une image, réduite à sa taille imprimée, faite une fois et réutilisée par tous les documents
 * (dossier images/ des archives, purgé au bout d'un jour sans usage). Image dont la taille imprimée est inconnue,
 * ou déjà assez petite : rendue telle quelle. Les PNG (drapeaux avec transparence…) sont convertis seulement s'ils
 * sont opaques.
 *
 * @param mixed $file Chemin, ou « @ » suivi des données (TCPDF).
 * @param float $w Largeur imprimée (mm), 0 si elle suit la hauteur.
 * @param float $h Hauteur imprimée (mm), 0 si elle suit la largeur.
 * @param int $dpi Résolution voulue.
 * @param mixed $fitbox Image ajustée dans $w × $h, proportions gardées.
 * @return mixed Chemin à intégrer.
 */
function eng_sc_print_image($file, $w, $h, $dpi, $fitbox = false)
{
    static $done = [];
    if (!is_string($file) || $file === '' || $file[0] === '@' || !function_exists('imagecreatetruecolor')) return $file;
    $key = $file . '|' . $w . '|' . $h . '|' . $dpi . '|' . ($fitbox ? 1 : 0);
    if (isset($done[$key])) return $done[$key];
    $done[$key] = $file;

    $info = @getimagesize($file);
    if (!$info || $info[0] < 1 || $info[1] < 1 || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) return $file;
    $sx = $w > 0 ? $w / 25.4 * $dpi / $info[0] : 0;
    $sy = $h > 0 ? $h / 25.4 * $dpi / $info[1] : 0;
    if ($sx > 0 && $sy > 0 && $fitbox) {
        $sx = $sy = min($sx, $sy);
    } elseif ($sx <= 0 || $sy <= 0) {
        $sx = $sy = max($sx, $sy);
    }
    if ($sx <= 0 || max($sx, $sy) > 0.8) return $file;   // taille inconnue, ou trop peu à gagner
    // Jamais plus grande que la taille calculée par TCPDF, sinon il la réduirait encore dans chaque document.
    $pw = max(1, (int) floor($info[0] * $sx));
    $ph = max(1, (int) floor($info[1] * $sy));

    $dir  = eng_sc_jobs_root() . DIRECTORY_SEPARATOR . 'images';
    $copy = $dir . DIRECTORY_SEPARATOR . md5($file . '|' . @filemtime($file) . '|' . @filesize($file) . "|{$pw}x{$ph}") . '.jpg';
    if (is_file($copy)) {
        @touch($copy);
        return $done[$key] = $copy;
    }
    $src = $info[2] === IMAGETYPE_JPEG ? @imagecreatefromjpeg($file) : @imagecreatefrompng($file);
    if (!$src) return $file;
    if ($info[2] === IMAGETYPE_PNG && eng_sc_has_alpha($src)) {
        imagedestroy($src);
        return $file;   // la transparence ne passe pas en JPEG
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        imagedestroy($src);
        return $file;
    }
    $dst = imagecreatetruecolor($pw, $ph);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $pw, $ph, $info[0], $info[1]);
    // Écrite à côté puis renommée : une requête simultanée ne lit jamais une demi-image.
    $tmp = $copy . '.' . getmypid() . '.tmp';
    $ok  = @imagejpeg($dst, $tmp, 85) && @rename($tmp, $copy);
    imagedestroy($src);
    imagedestroy($dst);
    if (!$ok) {
        @unlink($tmp);
        return $file;
    }
    return $done[$key] = $copy;
}

/** Une image PNG a-t-elle des pixels transparents ? (échantillonnage d'une grille de points) */
function eng_sc_has_alpha($img)
{
    $w = imagesx($img);
    $h = imagesy($img);
    $step = max(1, (int) (min($w, $h) / 40));
    for ($y = 0; $y < $h; $y += $step) {
        for ($x = 0; $x < $w; $x += $step) {
            if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 0) return true;
        }
    }
    return false;
}

/**
 * Document réglé comme celui d'IANSEO pour des feuilles complétées.
 *
 * @param array $opts eng_sc_options().
 * @return ScorePDF
 */
function eng_sc_new_pdf(array $opts)
{
    $pdf = new EngScorecardPdf(true);
    $pdf->FillWithArrows = true;
    if (!$opts['header']) $pdf->HideHeader();
    if (!$opts['logos'])  $pdf->HideLogo();
    if (!$opts['flags'])  $pdf->HideFlags();
    $pdf->FullHeaderShow($opts['page']);
    return $pdf;
}

/**
 * Place et taille des feuilles d'une page « toutes les distances sur une seule page », pour $count distances :
 * la géométrie de CreateSessionScorecard() (tir à la cible, sans QR code ni marqueur de lecture automatique).
 *
 * @param ScorePDF $pdf Document en portrait (avant tout changement d'orientation).
 * @param int $count Nombre de distances de la compétition.
 * @return array [w, h, landscape, origins => liste de [x, y], break => indice de la distance qui commence une
 *               nouvelle page (0 : aucune)].
 */
function eng_sc_layout(ScorePDF $pdf, $count)
{
    $m  = $pdf->getSideMargin();
    $pw = $pdf->GetPageWidth();
    $ph = $pdf->GetPageHeight();
    $w  = ($pw - $m * 3) / 2;
    $h  = ($ph - $m * 3 - ($pdf->NoTensOnlyX ? 7 : 0)) / 2;
    $x  = $m;
    $x2 = $x + $m + $w;
    $y  = $m;
    $y2 = $y + $m + $h - ($pdf->PrintFullHeader ? 15 : 0);

    if ($count <= 2) {
        $x = $m * 3;
        $h = $pw - $m * 2 - ($pdf->NoTensOnlyX ? 7 : 0) - ($pdf->PrintFullHeader ? 10 : 0);
        $w = ($ph - $x * 3) / 2;
    } elseif ($count === 3 || $count === 5 || $count === 6) {
        $h = ($pw - $m * 2) - ($pdf->PrintFullHeader ? 10 : 0);
        $w = ($ph - $m * 4) / 3;
    }
    $row  = [[$x, $y], [2 * $x + $w, $y], [3 * $x + 2 * $w, $y]];
    $quad = [[$x, $y], [$x2, $y], [$x, $y2], [$x2, $y2]];
    switch ($count) {
        case 1:  $origins = [$row[0]]; break;
        case 2:  $origins = [$row[0], $row[1]]; break;
        case 3:  $origins = $row; break;
        case 4:  $origins = $quad; break;
        case 5:  $origins = array_merge($row, [$row[0], $row[1]]); break;
        case 6:  $origins = array_merge($row, $row); break;
        default: $origins = array_merge($quad, $quad); break;   // 7 ou 8
    }
    return [
        'w' => $w, 'h' => $h, 'origins' => $origins,
        'landscape' => $count <= 3 || $count === 5 || $count === 6,
        'break' => $count === 5 || $count === 6 ? 3 : ($count > 6 ? 4 : 0),
    ];
}

/**
 * Dessine une page (deux pour plus de 4 ou 6 distances) par archer : chacune de ses distances à la place que lui
 * donne IANSEO ; une distance qu'il ne tire pas laisse sa place vide.
 *
 * @param ScorePDF $pdf eng_sc_new_pdf().
 * @param array $cards eng_sc_read_cards().
 * @param int $numDist Nombre de distances de la compétition.
 * @return int Nombre d'archers dessinés.
 */
function eng_sc_draw(ScorePDF $pdf, array $cards, $numDist)
{
    $g = eng_sc_layout($pdf, $numDist);
    if ($g['landscape']) $pdf->setPageOrientation('L');
    $n = 0;
    foreach ($cards as $card) {
        $pdf->AddPage();
        for ($d = 1; $d <= $numDist; $d++) {
            $k = $d - 1;
            if ($g['break'] && $k === $g['break']) $pdf->AddPage();
            if (empty($card['NumEnds' . $d]) || trim((string) ($card['D' . $d] ?? '')) === '-') continue;
            [$x, $y] = $g['origins'][$k];
            $pdf->DrawScoreNew($x, $y, $g['w'], $g['h'], $d, $card);
        }
        $n++;
    }
    return $n;
}

/**
 * Nom du fichier d'un club (code et nom : « 9000001_Club_Exemple ») ou d'un archer (nom et licence) dans l'archive,
 * sans extension.
 *
 * @param array $card
 * @param string $mode 'club' ou 'archer'.
 * @return string
 */
function eng_sc_file_name(array $card, $mode)
{
    if ($mode === 'archer') {
        $code = eng_sc_ascii($card['EnCode'] ?? '');
        return eng_sc_ascii($card['Ath'] ?? '') . '_' . ($code !== '' ? $code : (int) $card['EnId']);
    }
    $code = (string) ($card['CoCode'] ?? '');
    if ($code === '') return 'sans_club';
    $clean = eng_sc_ascii($code);
    // Un code qu'il a fallu nettoyer porte une somme de contrôle, pour ne pas rejoindre un autre code.
    if ($clean !== $code) $clean .= '_' . sprintf('%08x', crc32($code));
    $name = eng_sc_ascii($card['CoName'] ?? '');
    return $clean . ($name !== '' ? '_' . $name : '');
}

/**
 * Feuilles réparties en fichiers : un par club, ou un par archer.
 *
 * @param array $cards eng_sc_read_cards() (déjà rangées par club puis par archer).
 * @param string $mode 'club' ou 'archer'.
 * @return array nom de fichier => feuilles.
 */
function eng_sc_split(array $cards, $mode)
{
    $files = [];
    foreach ($cards as $card) $files[eng_sc_file_name($card, $mode)][] = $card;
    return $files;
}

// ── Archive ZIP, construite par étapes ──────────────────────────────────────────
//
// Une grande compétition ne doit jamais buter sur une limite de temps du serveur : chaque appel dessine quelques
// secondes de fichiers. Les fichiers vont dans le répertoire temporaire du serveur (jamais sous Modules/Custom/),
// appartiennent à la session qui les a demandés, et sont supprimés au téléchargement ou au bout d'un jour.

/** Durée de vie d'une archive non téléchargée, en secondes. */
const ENG_SC_JOB_TTL = 86400;

/** Dossier des archives en cours. */
function eng_sc_jobs_root()
{
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ianseo-greffe-feuilles';
}

/** Dossier d'une archive. */
function eng_sc_job_dir($job)
{
    return eng_sc_jobs_root() . DIRECTORY_SEPARATOR . $job;
}

/**
 * Nouvelle archive, rattachée à la session et à la compétition ouverte.
 *
 * @param array $data Options, noms des fichiers, nom de l'archive.
 * @param array $files eng_sc_split().
 * @return string Identifiant, '' en cas d'échec.
 */
function eng_sc_job_create(array $data, array $files)
{
    eng_sc_jobs_purge();
    $job = bin2hex(random_bytes(8));
    $dir = eng_sc_job_dir($job);
    if (!@mkdir($dir . DIRECTORY_SEPARATOR . 'files', 0700, true)) return '';
    $data['next'] = 0;
    if (@file_put_contents($dir . DIRECTORY_SEPARATOR . 'job.json', json_encode($data)) === false
        || @file_put_contents($dir . DIRECTORY_SEPARATOR . 'files.ser', serialize($files)) === false) {
        eng_sc_job_remove($job);
        return '';
    }
    $_SESSION['ENG_SC_JOBS'][$job] = (int) $_SESSION['TourId'];
    return $job;
}

/** Archive de cette session et de cette compétition, ou null. */
function eng_sc_job_load($job)
{
    if (!is_string($job) || !preg_match('/^[a-f0-9]{16}$/', $job)) return null;
    if (($_SESSION['ENG_SC_JOBS'][$job] ?? null) !== (int) $_SESSION['TourId']) return null;
    $f = eng_sc_job_dir($job) . DIRECTORY_SEPARATOR . 'job.json';
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($d) ? $d : null;
}

/** Enregistre l'avancement. */
function eng_sc_job_save($job, array $data)
{
    return @file_put_contents(eng_sc_job_dir($job) . DIRECTORY_SEPARATOR . 'job.json', json_encode($data)) !== false;
}

/** Feuilles à dessiner, ou null. */
function eng_sc_job_files($job)
{
    $d = @file_get_contents(eng_sc_job_dir($job) . DIRECTORY_SEPARATOR . 'files.ser');
    $files = $d === false ? null : unserialize($d, ['allowed_classes' => false]);
    return is_array($files) ? $files : null;
}

/** Supprime une archive et ses fichiers. */
function eng_sc_job_remove($job)
{
    if (!preg_match('/^[a-f0-9]{16}$/', (string) $job)) return;
    $dir = eng_sc_job_dir($job);
    foreach ([$dir . DIRECTORY_SEPARATOR . 'files', $dir] as $path) {
        foreach ((array) glob($path . DIRECTORY_SEPARATOR . '*') as $f) {
            if (is_file($f)) @unlink($f);
        }
        if (is_dir($path)) @rmdir($path);
    }
}

/** Supprime les archives de plus d'un jour. */
function eng_sc_jobs_purge()
{
    $limit = time() - ENG_SC_JOB_TTL;
    foreach ((array) glob(eng_sc_jobs_root() . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $dir) {
        if (basename($dir) !== 'images' && @filemtime($dir) < $limit) eng_sc_job_remove(basename($dir));
    }
    // Copies réduites des images (eng_sc_print_image()) inutilisées depuis un jour.
    foreach ((array) glob(eng_sc_jobs_root() . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . '*') as $f) {
        if (is_file($f) && @filemtime($f) < $limit) @unlink($f);
    }
}

/** Range les PDF dans archive.zip, puis les supprime. @return string Chemin, '' en cas d'échec. */
function eng_sc_job_zip($job)
{
    $dir = eng_sc_job_dir($job);
    $zip = new ZipArchive();
    $out = $dir . DIRECTORY_SEPARATOR . 'archive.zip';
    if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return '';
    $files = (array) glob($dir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . '*.pdf');
    foreach ($files as $f) $zip->addFile($f, basename($f));
    if (!$zip->close()) return '';
    foreach ($files as $f) @unlink($f);
    return $out;
}

/**
 * Texte réduit aux caractères sûrs d'un nom de fichier, accents retirés (pas iconv //TRANSLIT : sous Windows il
 * écrit « é » en « 'e »).
 *
 * @param string $s
 * @return string
 */
function eng_sc_ascii($s)
{
    $t = (string) $s;
    if (class_exists('Normalizer')) {
        $t = preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($t, Normalizer::FORM_D));
    } else {
        $t = strtr($t, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ñ' => 'n', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ú' => 'u', 'ÿ' => 'y', 'À' => 'A', 'Â' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Î' => 'I',
            'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'œ' => 'oe', 'Œ' => 'OE', 'æ' => 'ae', 'Æ' => 'AE']);
    }
    return trim((string) preg_replace('/[^A-Za-z0-9_\-]+/', '_', $t), '_');
}
