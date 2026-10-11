<?php
/**
 * lib/accounts.php — comptes des archers et paiements.
 *
 * Un compte = un archer = une licence (TRIM/UPPER de Entries.EnCode), ou
 * « #<EnId> » pour une inscription sans licence. Jamais par nom.
 * Nombre de départs = nombre de lignes Entries (EnAthlete = 1) du compte.
 *
 * Deux couches :
 *   - eng_build_accounts() et ce qu'elle appelle sont PURES (testées par
 *     tests/test_accounts.php) : lignes en entrée, comptes en sortie ;
 *   - les fonctions eng_fetch_* / eng_pay* lisent et écrivent la base.
 *
 * Heures : NOW() côté SQL. ianseo règle chaque connexion MySQL sur le fuseau de
 * la compétition : les dates sont stockées en heure locale de la compétition.
 */

/** Moyens de paiement : code stocké => libellé. */
function eng_methods()
{
    return [
        'especes'  => 'Espèces',
        'cheque'   => 'Chèque',
        'cb'       => 'Carte bancaire',
        'virement' => 'Virement',
        'exonere'  => 'Exonéré',
    ];
}

/** Libellés des statuts d'un compte. */
function eng_statuses()
{
    return [
        'unpaid' => 'Non payé',
        'paid'   => 'Payé',
        'diff'   => 'Payé — écart',
        'waived' => 'Exonéré',
        'exempt' => 'Exonéré (IANSEO)',
    ];
}

/** Clé de compte d'une ligne Entries. */
function eng_account_key($code, $enId)
{
    $c = strtoupper(trim((string) $code));
    return $c !== '' ? $c : '#' . intval($enId);
}

/**
 * Inscription qui fixe la règle tarifaire d'un archer à plusieurs départs.
 *
 * SEUL point de décision pour les catégories mixtes (catégorie différente
 * selon les départs) : l'inscription au plus petit départ. Les entrées
 * arrivent déjà triées par eng_build_accounts (session croissante, session 0
 * = non placée en dernier, puis EnId).
 *
 * @param array $entries Inscriptions triées du compte (au moins une).
 * @return array L'inscription de référence.
 */
function eng_reference_entry(array $entries)
{
    return $entries[0];
}

/**
 * Construit les comptes.
 *
 * @param array $rows Lignes d'inscription (tableaux associatifs) : EnId, EnCode,
 *                    EnName, EnFirstName, EnDivision, EnClass, EnPays,
 *                    QuSession, QuTargetNo, Target (cible « 12A », facultative),
 *                    CoId, CoCode, CoName.
 * @param array $payments Paiement actif par clé de compte (voir
 *                        eng_fetch_active_payments) : EpId, EpMethod, EpAmount,
 *                        EpNote, EpBy, EpCreated.
 * @param array $config Configuration tarifaire normalisée.
 * @return array Comptes indexés par clé (attention : une licence toute en chiffres
 *               devient une clé entière ; utiliser $account['key']), triés par nom
 *               puis prénom. Chaque compte :
 *   key, code, name (nom de famille), first (prénom), club_id, club_code, club_name,
 *   entries (triées), n, sessions[], cats[] (« CL S1H » distincts), mixed,
 *   price (résultat eng_price), pays ('yes' | 'no' | 'partial'),
 *   due (tarif, y compris pour un exonéré), payment (ou null),
 *   status : unpaid | paid | diff | waived (paiement « exonere ») | exempt (EnPays = 0),
 *   diff (dû − montant payé), waived (montant exonéré, 0 sinon).
 *   Ainsi : dû = payé + exonéré + reste à encaisser.
 *   Un paiement sans inscription (archer supprimé depuis) donne un compte à
 *   0 départ, dû 0, statut « diff ».
 */
function eng_build_accounts(array $rows, array $payments, array $config)
{
    $groups = [];
    foreach ($rows as $r) {
        $groups[eng_account_key($r['EnCode'] ?? '', $r['EnId'] ?? 0)][] = $r;
    }
    foreach ($payments as $key => $p) {
        if (!isset($groups[$key])) $groups[$key] = [];
    }

    $accounts = [];
    foreach ($groups as $key => $entries) {
        $key = (string) $key;   // une licence toute en chiffres deviendrait une clé entière
        usort($entries, function ($a, $b) {
            $sa = intval($a['QuSession']) ?: PHP_INT_MAX;
            $sb = intval($b['QuSession']) ?: PHP_INT_MAX;
            return $sa <=> $sb ?: intval($a['EnId']) <=> intval($b['EnId']);
        });
        $n = count($entries);
        $ref = $n ? eng_reference_entry($entries) : [];

        $cats = $sessions = [];
        $payN = 0;
        foreach ($entries as $e) {
            $cat = trim($e['EnDivision']) . ' ' . trim($e['EnClass']);
            if (!in_array($cat, $cats, true)) $cats[] = $cat;
            $sessions[] = intval($e['QuSession']);
            if (intval($e['EnPays']) === 1) $payN++;
        }
        $pays = $payN === $n ? 'yes' : ($payN === 0 ? 'no' : 'partial');

        $price = eng_price($config, $ref['EnDivision'] ?? '', $ref['EnClass'] ?? '', $n);
        $due = $price['total'];

        $payment = $payments[$key] ?? null;
        if ($payment !== null && $payment['EpMethod'] === 'exonere' && $n > 0) {
            // Exonéré : soldé quel que soit le dû, même s'il a changé depuis.
            $diff = 0.0;
            $status = 'waived';
        } elseif ($payment !== null) {
            $diff = round($due - (float) $payment['EpAmount'], 2);
            $status = abs($diff) < 0.005 && $n > 0 ? 'paid' : 'diff';
        } else {
            $diff = 0.0;
            $status = ($pays === 'no' && $n > 0) ? 'exempt' : 'unpaid';
        }

        $accounts[$key] = [
            'key'       => $key,
            'code'      => $key[0] === '#' ? '' : $key,
            // Piège ianseo : EnFirstName est le NOM de famille, EnName le prénom.
            'name'      => (string) ($ref['EnFirstName'] ?? ''),
            'first'     => (string) ($ref['EnName'] ?? ''),
            'club_id'   => intval($ref['CoId'] ?? 0),
            'club_code' => (string) ($ref['CoCode'] ?? ''),
            'club_name' => (string) ($ref['CoName'] ?? ''),
            'entries'   => $entries,
            'n'         => $n,
            'sessions'  => $sessions,
            'cats'      => $cats,
            'mixed'     => count($cats) > 1,
            'price'     => $price,
            'pays'      => $pays,
            'due'       => $due,
            'payment'   => $payment,
            'status'    => $status,
            'diff'      => $diff,
            'waived'    => in_array($status, ['waived', 'exempt'], true) ? $due : 0.0,
        ];
    }

    uasort($accounts, function ($a, $b) {
        // Comptes sans inscription (archer supprimé) en fin de liste.
        return ($a['n'] === 0) <=> ($b['n'] === 0)
            ?: strcasecmp($a['name'], $b['name']) ?: strcasecmp($a['first'], $b['first']) ?: strcmp($a['key'], $b['key']);
    });
    return $accounts;
}

/** Numéros de départ d'un compte : « 2 », « 1, 2 » ; « ? » pour un archer non placé. */
function eng_sessions_text(array $a)
{
    return implode(', ', array_map(function ($s) { return $s ? (string) $s : '?'; }, $a['sessions']));
}

/**
 * Clé de la règle appliquée, pour filtrer : « r0 », « r1 »…, « default », « base ».
 */
function eng_rule_key(array $price)
{
    return $price['source'] === 'rule' ? 'r' . $price['rule'] : $price['source'];
}

/**
 * Ventilation des montants d'un compte, la même partout (paiements, reporting).
 *
 * Pour tout ensemble de comptes : due = paid + waived + left − over.
 *
 * @param array $a Compte (eng_build_accounts).
 * @return array [
 *     'due'    => tarif,
 *     'paid'   => encaissé (paiement actif hors « exonere »),
 *     'waived' => exonéré (paiement « exonere » ou EnPays = 0),
 *     'left'   => reste à encaisser (non payé, ou écart positif),
 *     'over'   => trop-perçu (écart négatif, ex. départ retiré après paiement),
 * ]
 */
function eng_account_amounts(array $a)
{
    $paid = ($a['payment'] && $a['status'] !== 'waived') ? (float) $a['payment']['EpAmount'] : 0.0;
    return [
        'due'    => $a['due'],
        'paid'   => $paid,
        'waived' => $a['waived'],
        'left'   => $a['status'] === 'unpaid' ? $a['due'] : ($a['status'] === 'diff' && $a['diff'] > 0 ? $a['diff'] : 0.0),
        'over'   => $a['status'] === 'diff' && $a['diff'] < 0 ? -$a['diff'] : 0.0,
    ];
}

// ── Accès à la base ──────────────────────────────────────────────────────────

/** Inscriptions d'archers de la compétition, au format attendu par eng_build_accounts. */
function eng_fetch_entries($tourId)
{
    $rs = safe_r_sql("SELECT EnId, EnCode, EnName, EnFirstName, EnDivision, EnClass, EnPays,
            IFNULL(QuSession, 0) AS QuSession, IFNULL(QuTargetNo, '') AS QuTargetNo,
            IF(QuTarget > 0, CONCAT(QuTarget, QuLetter), '') AS Target,
            IFNULL(CoId, 0) AS CoId, IFNULL(CoCode, '') AS CoCode, IFNULL(CoName, '') AS CoName
        FROM Entries
        LEFT JOIN Qualifications ON QuId = EnId
        LEFT JOIN Countries ON CoId = EnCountry AND CoTournament = EnTournament
        WHERE EnTournament = " . StrSafe_DB($tourId) . " AND EnAthlete = 1");
    $rows = [];
    while ($r = safe_fetch_assoc($rs)) $rows[] = $r;
    return $rows;
}

/** Paiement actif (dernière ligne non annulée) de chaque compte. */
function eng_fetch_active_payments($tourId)
{
    $rs = safe_r_sql("SELECT EpId, EpAccount, EpMethod, EpAmount, EpNote, EpBy, EpCreated
        FROM EngagementPayments
        WHERE EpTournament = " . StrSafe_DB($tourId) . " AND EpCancelled IS NULL
        ORDER BY EpId");
    $out = [];
    while ($r = safe_fetch_assoc($rs)) $out[$r['EpAccount']] = $r;   // le dernier l'emporte
    return $out;
}

/** Comptes de la compétition ouverte. */
function eng_load_accounts($tourId, array $config)
{
    return eng_build_accounts(eng_fetch_entries($tourId), eng_fetch_active_payments($tourId), $config);
}

/** Utilisateur courant pour l'historique (module AUTH), ou vide. */
function eng_current_user()
{
    return mb_substr(trim((string) ($_SESSION['AUTH_User'] ?? '')), 0, 64);
}

/**
 * Enregistre le paiement d'un compte non payé, au montant dû actuel.
 *
 * Le montant n'est jamais pris du navigateur : il est recalculé ici.
 *
 * @return bool Faux si le compte est inconnu ou déjà payé.
 */
function eng_pay($tourId, array $account, $method, $note = '')
{
    if (!isset(eng_methods()[$method])) return false;
    if ($account['status'] !== 'unpaid' || $account['n'] === 0) return false;
    safe_w_sql("INSERT INTO EngagementPayments (EpTournament, EpAccount, EpMethod, EpAmount, EpNote, EpBy, EpCreated)
        VALUES (" . StrSafe_DB($tourId) . ", " . StrSafe_DB($account['key']) . ", " . StrSafe_DB($method) . ", "
        . StrSafe_DB(number_format($account['due'], 2, '.', '')) . ", "
        . StrSafe_DB(mb_substr(trim((string) $note), 0, 160)) . ", " . StrSafe_DB(eng_current_user()) . ", NOW())");
    return true;
}

/**
 * Annule le paiement actif d'un compte : la ligne est datée, pas supprimée.
 *
 * @return bool Faux s'il n'y avait pas de paiement actif.
 */
function eng_cancel($tourId, array $account)
{
    if (empty($account['payment'])) return false;
    safe_w_sql("UPDATE EngagementPayments
        SET EpCancelled = NOW(), EpCancelBy = " . StrSafe_DB(eng_current_user()) . "
        WHERE EpId = " . StrSafe_DB(intval($account['payment']['EpId'])) . "
          AND EpTournament = " . StrSafe_DB($tourId) . " AND EpCancelled IS NULL");
    return safe_w_affected_rows() > 0;
}

/**
 * Régularise un compte en écart : annule le paiement actif et en recrée un au
 * nouveau montant dû, avec le même moyen et la même note.
 */
function eng_resync($tourId, array $account)
{
    if ($account['status'] !== 'diff' || $account['n'] === 0) return false;
    $method = $account['payment']['EpMethod'];
    $note = $account['payment']['EpNote'];
    if (!eng_cancel($tourId, $account)) return false;
    $account['status'] = 'unpaid';
    return eng_pay($tourId, $account, $method, $note);
}
