<?php
/**
 * lib/report.php — agrégats du reporting.
 *
 * Fonction pure : comptes en entrée (eng_build_accounts), tableaux en sortie.
 * La page HTML, les CSV et le PDF lisent tous ce même résultat, ce qui garantit
 * qu'ils affichent les mêmes chiffres. Testée par tests/test_report.php.
 */

/** Ligne de montants à zéro. */
function eng_report_zero()
{
    return ['archers' => 0, 'departs' => 0, 'due' => 0.0, 'paid' => 0.0, 'waived' => 0.0, 'left' => 0.0, 'over' => 0.0];
}

/** Ajoute un compte à une ligne de montants. */
function eng_report_add(array &$line, array $account, array $amounts)
{
    if ($account['n'] > 0) $line['archers']++;
    $line['departs'] += $account['n'];
    foreach (['due', 'paid', 'waived', 'left', 'over'] as $k) $line[$k] = round($line[$k] + $amounts[$k], 2);
}

/**
 * Agrégats du reporting.
 *
 * @param array $accounts Comptes (eng_build_accounts).
 * @return array [
 *   'totals'  => ligne de montants + 'unpaid' (nombre d'archers non payés),
 *   'methods' => code => ['n', 'amount'] (paiements actifs, « exonere » compris),
 *   'clubs'   => club_id => ligne + 'code', 'name' ; triés par nom,
 *   'rules'   => clé de règle (eng_rule_key) => ligne + 'label',
 *   'counts'  => nombre de départs => ligne,
 *   'sessions'=> session => ['entries', 'archers', 'unpaid'] (0 = non placé),
 *   'lists'   => unpaid | diff | norule | mixed | short | partial => comptes,
 * ]
 *   « ligne » = archers, departs, due, paid, waived, left, over.
 */
function eng_report(array $accounts)
{
    $r = [
        'totals'   => eng_report_zero() + ['unpaid' => 0],
        'methods'  => [],
        'clubs'    => [],
        'rules'    => [],
        'counts'   => [],
        'sessions' => [],
        'lists'    => ['unpaid' => [], 'diff' => [], 'norule' => [], 'mixed' => [], 'short' => [], 'partial' => []],
    ];

    foreach ($accounts as $a) {
        $m = eng_account_amounts($a);
        eng_report_add($r['totals'], $a, $m);
        if ($a['status'] === 'unpaid') $r['totals']['unpaid']++;

        if ($a['payment']) {
            $code = $a['payment']['EpMethod'];
            if (!isset($r['methods'][$code])) $r['methods'][$code] = ['n' => 0, 'amount' => 0.0];
            $r['methods'][$code]['n']++;
            $r['methods'][$code]['amount'] = round($r['methods'][$code]['amount'] + (float) $a['payment']['EpAmount'], 2);
        }

        $c = $a['club_id'];
        if (!isset($r['clubs'][$c])) $r['clubs'][$c] = eng_report_zero() + ['code' => $a['club_code'], 'name' => $a['club_name']];
        eng_report_add($r['clubs'][$c], $a, $m);

        if ($a['n'] > 0) {
            $k = eng_rule_key($a['price']);
            if (!isset($r['rules'][$k])) $r['rules'][$k] = eng_report_zero() + ['label' => $a['price']['label']];
            eng_report_add($r['rules'][$k], $a, $m);

            if (!isset($r['counts'][$a['n']])) $r['counts'][$a['n']] = eng_report_zero();
            eng_report_add($r['counts'][$a['n']], $a, $m);

            foreach (array_unique($a['sessions']) as $s) {
                if (!isset($r['sessions'][$s])) $r['sessions'][$s] = ['entries' => 0, 'archers' => 0, 'unpaid' => 0];
                $r['sessions'][$s]['archers']++;
                if ($a['status'] === 'unpaid') $r['sessions'][$s]['unpaid']++;
            }
            foreach ($a['sessions'] as $s) $r['sessions'][$s]['entries']++;
        }

        if ($a['status'] === 'unpaid') $r['lists']['unpaid'][] = $a;
        if ($a['status'] === 'diff') $r['lists']['diff'][] = $a;
        if ($a['n'] > 0 && in_array($a['price']['source'], ['default', 'base'], true)) $r['lists']['norule'][] = $a;
        if ($a['mixed']) $r['lists']['mixed'][] = $a;
        if ($a['price']['short']) $r['lists']['short'][] = $a;
        if ($a['pays'] === 'partial') $r['lists']['partial'][] = $a;
    }

    uasort($r['clubs'], function ($x, $y) {
        return strcasecmp($x['name'], $y['name']) ?: strcmp($x['code'], $y['code']);
    });
    // Règles dans l'ordre de la configuration (r0, r1… r10), puis défaut, puis base.
    uksort($r['rules'], function ($x, $y) {
        $rank = function ($k) { return $k[0] === 'r' ? intval(substr($k, 1)) : ($k === 'default' ? 10000 : 10001); };
        return $rank($x) <=> $rank($y);
    });
    ksort($r['counts']);
    // Session 0 (non placé) en dernier.
    uksort($r['sessions'], function ($x, $y) {
        return ($x === 0 ? PHP_INT_MAX : $x) <=> ($y === 0 ? PHP_INT_MAX : $y);
    });
    // Ordre des moyens : celui de eng_methods().
    $order = array_keys(eng_methods());
    uksort($r['methods'], function ($x, $y) use ($order) {
        $ix = array_search($x, $order, true);
        $iy = array_search($y, $order, true);
        return ($ix === false ? 99 : $ix) <=> ($iy === false ? 99 : $iy);
    });

    return $r;
}

/**
 * État des chèques (bordereau de remise en banque).
 *
 * Un chèque = les paiements actifs « cheque » d'un même club portant la même
 * note (numéro de chèque, comparé sans tenir compte de la casse ni des espaces) :
 * c'est le cas d'un club réglé en une fois. Sans note, les paiements d'un même
 * club enregistrés dans la même seconde forment un chèque (« Régler le club »
 * les écrit d'un coup) ; sinon chaque paiement est un chèque à part. Ces
 * chèques sont signalés « sans numéro ».
 *
 * @param array $accounts Comptes (eng_build_accounts).
 * @return array [
 *   'cheques' => liste triée par date puis numéro : ['number' (note telle que
 *                saisie, '' si aucune), 'date' (EpCreated du premier paiement),
 *                'club_code', 'club_name', 'archers' (« NOM Prénom »…), 'amount'],
 *   'count'   => nombre de chèques,
 *   'total'   => montant total,
 *   'missing' => nombre de chèques sans numéro,
 * ]
 */
function eng_cheques(array $accounts)
{
    $groups = [];
    foreach ($accounts as $a) {
        $p = $a['payment'];
        if (!$p || $p['EpMethod'] !== 'cheque') continue;
        $note = trim((string) $p['EpNote']);
        $norm = mb_strtolower(preg_replace('/\s+/', '', $note));
        $key = $a['club_id'] . '|' . ($norm !== '' ? 'n:' . $norm : 't:' . $p['EpCreated']);
        if (!isset($groups[$key])) {
            $groups[$key] = ['number' => $note, 'date' => (string) $p['EpCreated'], 'club_code' => $a['club_code'],
                             'club_name' => $a['club_name'], 'archers' => [], 'amount' => 0.0];
        }
        $g = &$groups[$key];
        $g['archers'][] = $a['n'] === 0 ? '(inscription supprimée)' : trim(mb_strtoupper($a['name']) . ' ' . $a['first']);
        $g['amount'] = round($g['amount'] + (float) $p['EpAmount'], 2);
        if ((string) $p['EpCreated'] < $g['date']) $g['date'] = (string) $p['EpCreated'];
        unset($g);
    }
    $list = array_values($groups);
    usort($list, function ($x, $y) {
        return strcmp($x['date'], $y['date']) ?: strnatcasecmp($x['number'], $y['number']);
    });
    return [
        'cheques' => $list,
        'count'   => count($list),
        'total'   => round(array_sum(array_column($list, 'amount')), 2),
        'missing' => count(array_filter($list, function ($c) { return $c['number'] === ''; })),
    ];
}
