<?php
/**
 * lib/transfer.php — sauvegarde et restauration du greffe d'une compétition.
 *
 * Les tarifs et paiements vivent dans les tables du module, absentes de l'export
 * de compétition d'IANSEO. Ce fichier les met dans des fichiers JSON et les relit,
 * pour les garder à part ou les passer d'un serveur à l'autre (dev vers prod).
 * Deux fichiers distincts : les tarifs d'un côté, l'historique des paiements de
 * l'autre (champ « content » : 'tarifs' ou 'paiements').
 *
 * Fonctions PURES (testées par tests/test_transfer.php) : ni base, ni session.
 * La page sauvegarde.php lit la base, appelle ces fonctions, puis écrit.
 *
 * Rattachement des paiements dans la compétition d'arrivée :
 *   - par licence (clé de compte) : l'archer doit y être inscrit avec la même licence ;
 *   - sans licence (« #EnId », numéro propre à chaque serveur) : par nom, prénom
 *     et club, seulement s'il n'y a qu'une inscription sans licence qui correspond.
 * Un archer introuvable ou ambigu n'est pas importé : il est listé.
 *
 * Un compte qui a déjà un historique dans la compétition d'arrivée n'est pas
 * touché (l'import n'écrase rien) ; une ligne déjà présente à l'identique est
 * ignorée : rejouer un import n'ajoute rien.
 */

/** Identifiant du format de fichier, et sa version. */
if (!defined('ENG_TRANSFER_FORMAT')) define('ENG_TRANSFER_FORMAT', 'greffe-ianseo');
if (!defined('ENG_TRANSFER_VERSION')) define('ENG_TRANSFER_VERSION', 1);
/** Taille maximale d'un fichier importé (octets). */
if (!defined('ENG_TRANSFER_MAX')) define('ENG_TRANSFER_MAX', 5 * 1024 * 1024);

/** Contenus possibles d'un fichier : code => libellé. « tout » : fichier 1.3.0 qui portait les deux. */
function eng_transfer_contents()
{
    return [
        'tarifs'    => 'Tarifs',
        'paiements' => 'Historique des paiements',
        'tout'      => 'Tarifs et historique des paiements',
    ];
}

/** Identité d'un archer pour le rattachement sans licence : « NOM|PRÉNOM|CLUB ». */
function eng_identity($name, $first, $club)
{
    $n = function ($s) { return mb_strtoupper(preg_replace('/\s+/', ' ', trim((string) $s))); };
    return $n($name) . '|' . $n($first) . '|' . $n($club);
}

/**
 * Contenu d'un fichier de sauvegarde.
 *
 * @param string $what 'tarifs' (configuration seule) ou 'paiements' (historique seul).
 * @param array $tour 'code', 'name', 'from', 'to', 'sessions', 'org' de la compétition.
 * @param array $config Configuration tarifaire normalisée.
 * @param array $payments Toutes les lignes EngagementPayments de la compétition, par EpId
 *                        croissant (EpAccount, EpMethod, EpAmount, EpNote, EpBy, EpCreated,
 *                        EpCancelled, EpCancelBy).
 * @param array $entries Inscriptions (format eng_fetch_entries), pour nommer les comptes.
 * @param string $now Date d'export (heure de la compétition).
 * @param string $module Version du module.
 * @return array Prêt pour json_encode.
 */
function eng_export_build($what, array $tour, array $config, array $payments, array $entries, $now, $module)
{
    $what = $what === 'paiements' ? 'paiements' : 'tarifs';
    $who = [];
    foreach ($entries as $e) {
        $k = eng_account_key($e['EnCode'] ?? '', $e['EnId'] ?? 0);
        // ianseo : EnFirstName = nom de famille, EnName = prénom.
        if (!isset($who[$k])) $who[$k] = [(string) $e['EnFirstName'], (string) $e['EnName'], (string) $e['CoCode']];
    }
    $rows = [];
    foreach ($payments as $p) {
        $k = (string) $p['EpAccount'];
        $w = $who[$k] ?? ['', '', ''];
        $rows[] = [
            'account'   => $k,
            'name'      => $w[0],
            'first'     => $w[1],
            'club'      => $w[2],
            'method'    => (string) $p['EpMethod'],
            'amount'    => number_format((float) $p['EpAmount'], 2, '.', ''),
            'note'      => (string) $p['EpNote'],
            'by'        => (string) $p['EpBy'],
            'created'   => (string) $p['EpCreated'],
            'cancelled' => $p['EpCancelled'] === null ? null : (string) $p['EpCancelled'],
            'cancel_by' => (string) $p['EpCancelBy'],
        ];
    }
    $out = [
        'format'      => ENG_TRANSFER_FORMAT,
        'version'     => ENG_TRANSFER_VERSION,
        'content'     => $what,
        'module'      => (string) $module,
        'exported'    => (string) $now,
        'competition' => [
            'code'     => (string) ($tour['code'] ?? ''),
            'name'     => (string) ($tour['name'] ?? ''),
            'from'     => (string) ($tour['from'] ?? ''),
            'to'       => (string) ($tour['to'] ?? ''),
            'sessions' => (int) ($tour['sessions'] ?? 1),
            'org'      => (string) ($tour['org'] ?? ''),
        ],
    ];
    if ($what === 'tarifs') {
        $out['config'] = [
            'base'    => $config['base'],
            'default' => json_decode(eng_grid_to_json($config['default']), true) ?: new stdClass(),
            'rules'   => json_decode(eng_rules_to_json($config['rules']), true),
            'note'    => $config['note'],
        ];
    } else {
        $out['payments'] = $rows;
    }
    return $out;
}

/** Date SQL « AAAA-MM-JJ HH:MM:SS » valide ? */
function eng_is_sql_datetime($s)
{
    if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return false;
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $s);
    return $d && $d->format('Y-m-d H:i:s') === $s;
}

/**
 * Lit et contrôle un fichier de sauvegarde.
 *
 * Tout ce qui sort d'ici est sûr à écrire : textes tronqués aux tailles des
 * colonnes, moyens connus, montants et dates contrôlés. Une seule ligne
 * illisible rend le fichier entier invalide : mieux vaut refuser que restaurer
 * une partie de l'historique sans le dire.
 *
 * @param string $json Contenu du fichier.
 * @return array ['ok' => bool, 'error' => string, 'data' => [content ('tarifs' | 'paiements' |
 *               'tout'), competition, config (brute, null sans tarifs), payments (lignes propres,
 *               vide sans historique), module, exported]]
 */
function eng_import_parse($json)
{
    $fail = function ($msg) { return ['ok' => false, 'error' => $msg, 'data' => null]; };
    if (strlen((string) $json) > ENG_TRANSFER_MAX) return $fail('Fichier trop volumineux.');
    $d = json_decode((string) $json, true);
    if (!is_array($d)) return $fail('Ce fichier n\'est pas une sauvegarde du Greffe (JSON illisible).');
    if (($d['format'] ?? '') !== ENG_TRANSFER_FORMAT) return $fail('Ce fichier n\'est pas une sauvegarde du Greffe.');
    if (!is_int($d['version'] ?? null) || $d['version'] > ENG_TRANSFER_VERSION) {
        return $fail('Sauvegarde faite par une version plus récente du module : mettez le module à jour avant de l\'importer.');
    }
    // Sans « content » : fichier qui portait tarifs et historique ensemble.
    $content = (string) ($d['content'] ?? 'tout');
    if (!isset(eng_transfer_contents()[$content])) return $fail('Contenu de sauvegarde inconnu.');
    $hasConfig = $content !== 'paiements';
    $hasPayments = $content !== 'tarifs';
    if ($hasConfig && !is_array($d['config'] ?? null)) return $fail('Sauvegarde des tarifs incomplète : aucun tarif dans le fichier.');
    if ($hasPayments && !is_array($d['payments'] ?? null)) return $fail('Sauvegarde de l\'historique incomplète : aucun paiement dans le fichier.');
    $comp = is_array($d['competition'] ?? null) ? $d['competition'] : [];
    $config = $hasConfig ? $d['config'] : [];
    $methods = eng_methods();

    $rows = [];
    foreach (($hasPayments ? $d['payments'] : []) as $i => $p) {
        $line = 'Paiement n° ' . ($i + 1);
        if (!is_array($p)) return $fail($line . ' : ligne illisible.');
        $account = strtoupper(trim((string) ($p['account'] ?? '')));
        if ($account === '' || mb_strlen($account) > 25) return $fail($line . ' : archer manquant.');
        $method = (string) ($p['method'] ?? '');
        if (!isset($methods[$method])) return $fail($line . ' : moyen de paiement inconnu (« ' . mb_substr($method, 0, 20) . ' »).');
        $amount = eng_parse_amount($p['amount'] ?? null);
        if ($amount === null) return $fail($line . ' : montant illisible.');
        $created = $p['created'] ?? '';
        if (!eng_is_sql_datetime($created)) return $fail($line . ' : date illisible.');
        $cancelled = $p['cancelled'] ?? null;
        if ($cancelled !== null && !eng_is_sql_datetime($cancelled)) return $fail($line . ' : date d\'annulation illisible.');
        $rows[] = [
            'account'   => $account,
            'name'      => mb_substr(trim((string) ($p['name'] ?? '')), 0, 64),
            'first'     => mb_substr(trim((string) ($p['first'] ?? '')), 0, 64),
            'club'      => mb_substr(strtoupper(trim((string) ($p['club'] ?? ''))), 0, 16),
            'method'    => $method,
            'amount'    => $amount,
            'note'      => mb_substr(trim((string) ($p['note'] ?? '')), 0, 160),
            'by'        => mb_substr(trim((string) ($p['by'] ?? '')), 0, 64),
            'created'   => $created,
            'cancelled' => $cancelled,
            'cancel_by' => mb_substr(trim((string) ($p['cancel_by'] ?? '')), 0, 64),
        ];
    }

    return ['ok' => true, 'error' => '', 'data' => [
        'content'     => $content,
        'module'      => mb_substr((string) ($d['module'] ?? ''), 0, 20),
        'exported'    => eng_is_sql_datetime($d['exported'] ?? '') ? $d['exported'] : '',
        'competition' => [
            'code'     => mb_substr((string) ($comp['code'] ?? ''), 0, 20),
            'name'     => mb_substr((string) ($comp['name'] ?? ''), 0, 120),
            'from'     => mb_substr((string) ($comp['from'] ?? ''), 0, 10),
            'to'       => mb_substr((string) ($comp['to'] ?? ''), 0, 10),
            'sessions' => max(1, (int) ($comp['sessions'] ?? 1)),
            'org'      => mb_substr(strtoupper(trim((string) ($comp['org'] ?? ''))), 0, 16),
        ],
        'config'      => $hasConfig ? [
            'base'    => $config['base'] ?? 0,
            'default' => is_array($config['default'] ?? null) ? $config['default'] : [],
            'rules'   => is_array($config['rules'] ?? null) ? $config['rules'] : [],
            'note'    => $config['note'] ?? '',
        ] : null,
        'payments'    => $rows,
    ]];
}

/**
 * Plan d'import des paiements dans la compétition d'arrivée.
 *
 * @param array $rows Paiements du fichier (eng_import_parse).
 * @param array $entries Inscriptions de la compétition d'arrivée (format eng_fetch_entries).
 * @param array $existing Lignes EngagementPayments déjà présentes (EpAccount, EpMethod,
 *                        EpAmount, EpCreated).
 * @return array [
 *   'insert'    => lignes à écrire, dans l'ordre du fichier, 'target' = clé de compte d'arrivée,
 *   'accounts'  => nombre de comptes concernés par 'insert',
 *   'same'      => lignes déjà présentes à l'identique (ignorées),
 *   'conflict'  => comptes ignorés car ils ont déjà un autre historique ici : [clé, nom, lignes],
 *   'missing'   => archers introuvables ici : [clé, nom, lignes],
 *   'ambiguous' => archers sans licence qui correspondent à plusieurs inscriptions : [clé, nom, lignes],
 * ]
 */
function eng_import_plan(array $rows, array $entries, array $existing)
{
    $keys = $noLicence = [];
    foreach ($entries as $e) {
        $k = eng_account_key($e['EnCode'] ?? '', $e['EnId'] ?? 0);
        $keys[$k] = true;
        if ($k[0] === '#') $noLicence[eng_identity($e['EnFirstName'], $e['EnName'], $e['CoCode'])][] = $k;
    }
    $sig = function ($account, $method, $amount, $created) {
        return $account . '|' . $method . '|' . number_format((float) $amount, 2, '.', '') . '|' . $created;
    };
    $have = $haveAccount = [];
    foreach ($existing as $x) {
        $have[$sig((string) $x['EpAccount'], $x['EpMethod'], $x['EpAmount'], $x['EpCreated'])] = true;
        $haveAccount[(string) $x['EpAccount']] = true;
    }

    // Compte d'arrivée de chaque compte du fichier.
    $target = $byAccount = [];
    foreach ($rows as $i => $r) {
        $byAccount[$r['account']][] = $r + ['_i' => $i];   // position dans le fichier
    }
    $out = ['insert' => [], 'accounts' => 0, 'same' => 0, 'conflict' => [], 'missing' => [], 'ambiguous' => []];
    $label = function (array $r) {
        $n = trim(mb_strtoupper($r['name']) . ' ' . $r['first']);
        return $n !== '' ? $n . ($r['club'] !== '' ? ' (' . $r['club'] . ')' : '') : $r['account'];
    };
    foreach ($byAccount as $account => $list) {
        $account = (string) $account;
        $info = [$account, $label($list[0]), count($list)];
        if ($account[0] !== '#') {
            if (!isset($keys[$account])) { $out['missing'][] = $info; continue; }
            $t = $account;
        } else {
            $cands = $noLicence[eng_identity($list[0]['name'], $list[0]['first'], $list[0]['club'])] ?? [];
            if (count($cands) > 1) { $out['ambiguous'][] = $info; continue; }
            if (!$cands || $list[0]['name'] === '') { $out['missing'][] = $info; continue; }
            $t = $cands[0];
        }
        $new = $seen = [];
        foreach ($list as $r) {
            $s = $sig($t, $r['method'], $r['amount'], $r['created']);
            // Déjà ici, ou deux fois dans le fichier : une seule ligne.
            if (isset($have[$s]) || isset($seen[$s])) { $out['same']++; continue; }
            $seen[$s] = true;
            $new[] = $r + ['target' => $t];
        }
        if (!$new) continue;
        if (isset($haveAccount[$t]) || in_array($t, $target, true)) { $out['conflict'][] = [$t, $info[1], count($new)]; continue; }
        $target[$account] = $t;
        foreach ($new as $r) $out['insert'][] = $r;
    }
    // Ordre du fichier (= ordre d'origine des EpId) : le dernier paiement actif reste le dernier.
    usort($out['insert'], function ($a, $b) { return $a['_i'] <=> $b['_i']; });
    foreach ($out['insert'] as &$r) unset($r['_i']);
    unset($r);
    $out['accounts'] = count($target);
    return $out;
}

/**
 * Écarts entre les tarifs du fichier et la compétition d'arrivée, à signaler
 * avant de les remplacer.
 *
 * @param array $fileConfig Configuration du fichier, normalisée au nombre de sessions du FICHIER.
 * @param int $sessions Nombre de sessions de la compétition d'arrivée.
 * @param array $divs, $clss, $clubs Codes connus ici (code => libellé).
 * @param string $fileOrg, $org Club organisateur du fichier et d'ici.
 * @return string[] Avertissements.
 */
function eng_import_config_warnings(array $fileConfig, $sessions, array $divs, array $clss, array $clubs, $fileOrg, $org)
{
    $w = [];
    if ($fileConfig['sessions'] > $sessions) {
        $w[] = 'Le fichier prévoit ' . $fileConfig['sessions'] . ' départs, cette compétition ' . $sessions
            . ' : les prix au-delà de ' . $sessions . ' départ' . ($sessions > 1 ? 's' : '') . ' seront perdus.';
    }
    $unknown = ['div' => [], 'cls' => [], 'clubs' => []];
    $useOrg = false;
    foreach ($fileConfig['rules'] as $r) {
        foreach (['div' => $divs, 'cls' => $clss, 'clubs' => $clubs] as $k => $known) {
            foreach ($r[$k] as $c) if (!isset($known[$c])) $unknown[$k][$c] = true;
        }
        if ($r['club'] !== 'all' && $r['club'] !== 'list') $useOrg = true;
    }
    $names = ['div' => 'Divisions absentes', 'cls' => 'Classes absentes', 'clubs' => 'Clubs absents'];
    foreach ($unknown as $k => $codes) {
        if ($codes) $w[] = $names[$k] . ' de cette compétition (règles à revoir) : ' . implode(', ', array_keys($codes)) . '.';
    }
    if ($useOrg && $fileOrg !== $org) {
        $w[] = 'Club organisateur différent (' . ($fileOrg ?: 'aucun') . ' dans le fichier, ' . ($org ?: 'aucun')
            . ' ici) : les règles « club organisateur » et « autres clubs » s\'appliqueront au club d\'ici.';
    }
    return $w;
}
