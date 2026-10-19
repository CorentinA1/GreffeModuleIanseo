<?php
/**
 * sauvegarde.php — GREFFE : sauvegarde et restauration du greffe d'une compétition.
 *
 *   ?export=tarifs       fichier JSON des tarifs (règles, grilles, tarif de base, mention de reçu)
 *   ?export=paiements    fichier JSON de l'historique complet des paiements
 *   POST action=preview  fichier envoyé (l'un ou l'autre) : contrôle, aperçu de ce qui sera fait (rien n'est écrit)
 *   POST action=apply    import confirmé, recalculé sur la base du moment, en une transaction
 *   POST action=forget   abandon de l'import en attente
 *
 * Les tarifs et paiements ne sont pas dans l'export de compétition d'IANSEO :
 * ce fichier les garde à part, ou les passe d'un serveur à l'autre (dev vers
 * prod). Rattachement des archers et règles d'import : lib/transfer.php.
 */
define('ENG_ACCESS', 'write');
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/accounts.php';
require_once __DIR__ . '/lib/transfer.php';

$tour = eng_tournament();
$self = $ENG_ROOT . 'sauvegarde.php';
$org  = eng_org_code();

/** Version du module, inscrite dans le fichier exporté. */
function eng_module_version()
{
    $v = json_decode((string) @file_get_contents(__DIR__ . '/version.json'), true);
    return (string) ($v['version'] ?? '');
}

// ── Export ───────────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $what = $_GET['export'] === 'paiements' ? 'paiements' : 'tarifs';
    $data = eng_export_build($what, [
        'code'     => (string) ($tour->ToCode ?? ''),
        'name'     => (string) ($tour->ToName ?? ''),
        'from'     => (string) ($tour->ToWhenFrom ?? ''),
        'to'       => (string) ($tour->ToWhenTo ?? ''),
        'sessions' => $tour->Sessions,
        'org'      => $org,
    ], eng_load_config(), eng_fetch_payment_history($ENG_TOUR), eng_fetch_entries($ENG_TOUR), eng_db_now(), eng_module_version());
    $code = trim(preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($tour->ToCode ?? '')), '_') ?: 'competition';
    $stamp = preg_replace('/\D/', '', substr(eng_db_now(), 0, 16));
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="greffe_' . $what . '_' . $code . '_' . $stamp . '.json"');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// ── Actions (POST) ───────────────────────────────────────────────────────────
$action = (string) ($_POST['action'] ?? '');
$pending = $_SESSION['ENG_IMPORT'] ?? null;
if ($pending && ($pending['tour'] ?? 0) !== $ENG_TOUR) $pending = null;   // import préparé pour une autre compétition
$error = '';

if ($action === 'preview') {
    eng_check_token();
    $up = $_FILES['file'] ?? null;
    if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error = 'Choisissez un fichier de sauvegarde (.json).';
    } elseif ($up['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'])) {
        $error = in_array($up['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'Fichier trop volumineux.' : 'Le fichier n\'a pas pu être envoyé ; réessayez.';
    } elseif ($up['size'] > ENG_TRANSFER_MAX) {
        $error = 'Fichier trop volumineux.';
    } else {
        $parsed = eng_import_parse((string) file_get_contents($up['tmp_name']));
        if ($parsed['ok']) {
            $_SESSION['ENG_IMPORT'] = ['tour' => $ENG_TOUR, 'file' => mb_substr(basename((string) $up['name']), 0, 80), 'data' => $parsed['data']];
            header('Location: ' . $self . '#apercu');
            exit;
        }
        $error = $parsed['error'];
    }
} elseif ($action === 'forget') {
    eng_check_token();
    unset($_SESSION['ENG_IMPORT']);
    header('Location: ' . $self);
    exit;
} elseif ($action === 'apply') {
    eng_check_token();
    $msg = ['err', 'Aucun import en attente : envoyez de nouveau le fichier.'];
    if ($pending) {
        $data = $pending['data'];
        // Une case ne vaut que si le fichier porte ce contenu (tarifs, ou historique).
        $doConfig = !empty($_POST['do_config']) && $data['config'] !== null;
        $doPay = !empty($_POST['do_payments']) && $data['content'] !== 'tarifs';
        // Plan recalculé maintenant : la base a pu changer depuis l'aperçu.
        $plan = eng_import_plan($data['payments'], eng_fetch_entries($ENG_TOUR), eng_fetch_payment_history($ENG_TOUR));
        if (!$doConfig && !$doPay) {
            $msg = ['err', 'Rien n\'était coché : rien n\'a été importé.'];
        } else {
            safe_w_BeginTransaction();
            try {
                if ($doConfig) {
                    eng_save_config(eng_normalize_config($data['config'] + ['org' => $org], $tour->Sessions));
                }
                if ($doPay) {
                    foreach ($plan['insert'] as $r) eng_insert_imported_payment($ENG_TOUR, $r);
                }
                safe_w_Commit();
                unset($_SESSION['ENG_IMPORT']);
                $parts = [];
                if ($doConfig) $parts[] = 'tarifs remplacés';
                if ($doPay) {
                    $n = count($plan['insert']);
                    $parts[] = $n . ' ligne' . ($n > 1 ? 's' : '') . ' de paiement importée' . ($n > 1 ? 's' : '')
                        . ' pour ' . $plan['accounts'] . ' archer' . ($plan['accounts'] > 1 ? 's' : '');
                }
                $msg = ['ok', 'Import terminé : ' . implode(', ', $parts) . '.'];
            } catch (Throwable $e) {
                safe_w_Rollback();
                $msg = ['err', 'Import annulé, rien n\'a été écrit : ' . $e->getMessage()];
            }
        }
    }
    $_SESSION['ENG_FLASH_SAVE'] = $msg;
    header('Location: ' . $self);
    exit;
}

$flash = $_SESSION['ENG_FLASH_SAVE'] ?? null;
unset($_SESSION['ENG_FLASH_SAVE']);

// ── Aperçu de l'import en attente ────────────────────────────────────────────
$preview = null;
if ($pending) {
    $data = $pending['data'];
    $withCfg = $data['config'] !== null;
    $withPay = $data['content'] !== 'tarifs';
    $fileCfg = $withCfg ? eng_normalize_config($data['config'], $data['competition']['sessions']) : null;
    $current = eng_load_config();
    $preview = [
        'file'     => $pending['file'],
        'content'  => eng_transfer_contents()[$data['content']],
        'withCfg'  => $withCfg,
        'withPay'  => $withPay,
        'comp'     => $data['competition'],
        'data'     => $data,
        'cfg'      => $fileCfg,
        'cfgEmpty' => !$withCfg || (eng_config_is_empty($fileCfg) && $fileCfg['note'] === ''),
        'hereCfg'  => !eng_config_is_empty($current) || $current['note'] !== '',
        'warn'     => $withCfg ? eng_import_config_warnings($fileCfg, $tour->Sessions, eng_codes('div'), eng_codes('cls'), eng_codes('club'),
                          $data['competition']['org'], $org) : [],
        'plan'     => eng_import_plan($data['payments'], eng_fetch_entries($ENG_TOUR), eng_fetch_payment_history($ENG_TOUR)),
        'sameComp' => $data['competition']['code'] !== '' && $data['competition']['code'] === (string) ($tour->ToCode ?? ''),
    ];
}

$history = eng_fetch_payment_history($ENG_TOUR);
$here = ['payments' => count($history), 'accounts' => count(array_unique(array_column($history, 'EpAccount'))), 'config' => eng_load_config()];

/** Liste d'archers (conflits, absents, ambigus) dans un bloc dépliable. */
function eng_people_list($title, array $list, $why)
{
    if (!$list) return '';
    $h = '<details class="people"><summary>' . eng_h($title) . ' : ' . count($list) . '</summary><p class="sub">' . eng_h($why) . '</p><ul>';
    foreach ($list as [$key, $label, $n]) {
        $h .= '<li>' . eng_h($label) . ' <span class="sub">' . eng_h($key) . ' · ' . $n . ' ligne' . ($n > 1 ? 's' : '') . '</span></li>';
    }
    return $h . '</ul></details>';
}

$PAGE_TITLE = 'Greffe - Sauvegarde';
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
    max-width:980px; color:var(--gris); font-size:13px; }
#eng a { color:var(--lien); }
#eng .card { border:1px solid var(--bord); border-radius:6px; background:var(--carte); box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:16px; }
#eng .card > h3 { margin:0; padding:10px 14px; font-size:14px; color:var(--sur-bleu); background:var(--bleu); border-radius:5px 5px 0 0; }
#eng .card > div { padding:14px; }
#eng .msg { border-radius:6px; padding:10px 14px; margin-bottom:12px; }
#eng .msg ul { margin:4px 0 0 18px; padding:0; }
#eng .msg.ok   { background:var(--ok-fond); border:1px solid var(--ok-bord); color:var(--vert); }
#eng .msg.err  { background:var(--err-fond); border:1px solid var(--err-bord); color:var(--rouge); }
#eng .msg.warn { background:var(--warn-fond); border:1px solid var(--warn-bord); color:var(--orange); }
#eng .msg.info { background:var(--bleu-clair); border:1px solid var(--bord); color:var(--gris); }
#eng .help, #eng .sub { color:var(--sous); font-size:12px; }
#eng .help { margin:0 0 10px; }
#eng .kv { display:grid; grid-template-columns:max-content 1fr; gap:4px 16px; margin:0 0 12px; }
#eng .kv b { color:var(--bleu-fonce); font-weight:600; }
#eng .row { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
#eng input[type=file] { background:var(--champ); color:var(--champ-texte); padding:5px; border:1px solid var(--bord); border-radius:5px; }
#eng label.check { display:flex; gap:8px; align-items:flex-start; margin:10px 0; font-weight:600; }
#eng label.check span { font-weight:normal; color:var(--sous); display:block; }
#eng .btn, #eng button { display:inline-block; border-radius:6px; cursor:pointer; padding:8px 18px; font-size:13px; font-weight:600; text-decoration:none; }
#eng .btn.primary, #eng button.primary { background:var(--bleu); border:1px solid var(--bleu); color:var(--sur-bleu); }
#eng .btn.primary:hover, #eng button.primary:hover { background:var(--bleu-survol); }
#eng button.ghost { background:var(--carte); border:1px solid var(--bord); color:var(--gris); }
#eng .exports { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:12px; }
#eng .export { display:flex; flex-direction:column; gap:6px; align-items:flex-start; border:1px solid var(--bord);
               border-radius:6px; padding:12px 14px; background:var(--fond); }
#eng .export b { color:var(--bleu-fonce); font-size:14px; }
#eng .export .btn { margin-top:auto; }
#eng details.people { margin:8px 0; }
#eng details.people summary { cursor:pointer; font-weight:600; }
#eng details.people ul { margin:6px 0 0 18px; padding:0; }
</style>

<div id="eng">
    <?= eng_nav('sauvegarde') ?>

    <?php if ($flash): ?><div class="msg <?= eng_h($flash[0]) ?>"><?= eng_h($flash[1]) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="msg err"><b>Fichier refusé, rien n'a été importé.</b> <?= eng_h($error) ?></div><?php endif; ?>

    <div class="card">
        <h3>Sauvegarder cette compétition</h3>
        <div>
            <p class="help">Les tarifs et les paiements du Greffe <b>ne sont pas dans l'export de compétition d'IANSEO</b>.
                Deux fichiers séparés : gardez-les avec l'export <code>.ianseo</code>, ou importez-les sur un autre serveur.</p>
            <div class="exports">
                <div class="export">
                    <b>Tarifs</b>
                    <span class="sub"><?= count($here['config']['rules']) ?> règle<?= count($here['config']['rules']) > 1 ? 's' : '' ?>,
                        grille par défaut, tarif de base<?= $here['config']['note'] !== '' ? ', mention de reçu' : '' ?>.
                        Réutilisable d'une compétition à l'autre.</span>
                    <a class="btn primary" href="<?= eng_h($self . '?export=tarifs') ?>">Télécharger les tarifs (.json)</a>
                </div>
                <div class="export">
                    <b>Historique des paiements</b>
                    <span class="sub"><?= $here['payments'] ?> ligne<?= $here['payments'] > 1 ? 's' : '' ?> pour
                        <?= $here['accounts'] ?> archer<?= $here['accounts'] > 1 ? 's' : '' ?>, annulations comprises.
                        Propre à cette compétition.</span>
                    <a class="btn primary" href="<?= eng_h($self . '?export=paiements') ?>">Télécharger l'historique (.json)</a>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <h3>Restaurer ou importer</h3>
        <div>
            <p class="help">Envoyez un fichier de tarifs ou d'historique : un aperçu montre ce qui sera fait, <b>rien n'est
                écrit avant votre confirmation</b>. Les paiements sont rattachés par licence (sans licence : par nom, prénom et club).
                Un archer qui a déjà des paiements ici n'est jamais modifié ; rejouer un import n'ajoute rien.</p>
            <form method="post" action="<?= eng_h($self) ?>" enctype="multipart/form-data" class="row">
                <?= eng_token_field() ?><input type="hidden" name="action" value="preview">
                <input type="file" name="file" accept=".json,application/json" required>
                <button type="submit" class="primary">Voir l'aperçu</button>
            </form>
        </div>
    </div>

    <?php if ($preview): $p = $preview['plan']; $c = $preview['comp']; $nIns = count($p['insert']); ?>
    <div class="card" id="apercu">
        <h3>Aperçu de l'import — <?= eng_h($preview['file']) ?></h3>
        <div>
            <div class="kv">
                <span>Contenu</span><b><?= eng_h($preview['content']) ?></b>
                <span>Compétition d'origine</span><b><?= eng_h(trim($c['name'] . ($c['code'] !== '' ? ' (' . $c['code'] . ')' : '')) ?: '—') ?>
                    <?= $c['from'] !== '' ? '<span class="sub">' . eng_h($c['from']) . ($c['to'] !== '' && $c['to'] !== $c['from'] ? ' → ' . eng_h($c['to']) : '') . '</span>' : '' ?></b>
                <span>Sauvegarde du</span><b><?= eng_h(eng_local_time($preview['data']['exported'], 'd/m/Y à H:i') ?: '—') ?>
                    <?= $preview['data']['module'] !== '' ? '<span class="sub">module ' . eng_h($preview['data']['module']) . '</span>' : '' ?></b>
                <span>Compétition ouverte</span><b><?= eng_h(trim(($tour->ToName ?? '') . (($tour->ToCode ?? '') !== '' ? ' (' . $tour->ToCode . ')' : ''))) ?></b>
            </div>
            <?php if (!$preview['sameComp']): ?>
                <div class="msg info">Le fichier vient d'une autre compétition (ou d'un autre code) : vérifiez que c'est bien celle-ci que vous voulez compléter.</div>
            <?php endif; ?>

            <form method="post" action="<?= eng_h($self) ?>" id="apply-form">
                <?= eng_token_field() ?><input type="hidden" name="action" value="apply">

                <?php if ($preview['withCfg']): ?>
                <label class="check"><input type="checkbox" name="do_config" value="1"<?= !$preview['cfgEmpty'] && (!$preview['hereCfg'] || !$preview['withPay']) ? ' checked' : '' ?><?= $preview['cfgEmpty'] ? ' disabled' : '' ?>>
                    <span class="txt">Remplacer les tarifs de cette compétition par ceux du fichier
                        <span><?= $preview['cfgEmpty'] ? 'Le fichier ne contient aucun tarif.'
                            : count($preview['cfg']['rules']) . ' règle' . (count($preview['cfg']['rules']) > 1 ? 's' : '')
                              . (eng_grid_is_empty($preview['cfg']['default']) ? '' : ', grille par défaut')
                              . ($preview['cfg']['base'] > 0 ? ', tarif de base ' . eng_money($preview['cfg']['base']) : '')
                              . ($preview['cfg']['note'] !== '' ? ', mention de reçu' : '')
                              . ($preview['hereCfg'] ? '. Attention : les tarifs déjà saisis ici seront remplacés.' : '.') ?></span></span></label>
                <?php if ($preview['warn'] && !$preview['cfgEmpty']): ?>
                    <div class="msg warn"><b>Tarifs à vérifier après l'import :</b><ul>
                        <?php foreach ($preview['warn'] as $w): ?><li><?= eng_h($w) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($preview['withPay']): ?>
                <label class="check"><input type="checkbox" name="do_payments" value="1"<?= $nIns ? ' checked' : ' disabled' ?>>
                    <span class="txt">Importer les paiements
                        <span><?= $nIns ? $nIns . ' ligne' . ($nIns > 1 ? 's' : '') . ' d\'historique pour ' . $p['accounts'] . ' archer' . ($p['accounts'] > 1 ? 's' : '') . ' (annulations comprises)'
                                        : 'Aucune ligne à importer.' ?><?= $p['same'] ? ' · ' . $p['same'] . ' déjà présente' . ($p['same'] > 1 ? 's' : '') . ', ignorée' . ($p['same'] > 1 ? 's' : '') : '' ?></span></span></label>
                <?= eng_people_list('Archers déjà pointés ici, non modifiés', $p['conflict'],
                    'Ils ont déjà un historique de paiement dans cette compétition : l\'import ne le touche pas. Vérifiez-les à la main.') ?>
                <?= eng_people_list('Archers introuvables ici, non importés', $p['missing'],
                    'Aucune inscription avec cette licence (ou, sans licence, ce nom, ce prénom et ce club) dans cette compétition.') ?>
                <?= eng_people_list('Archers sans licence ambigus, non importés', $p['ambiguous'],
                    'Plusieurs inscriptions sans licence portent ce nom, ce prénom et ce club : impossible de choisir.') ?>
                <?php endif; ?>

                <div class="row" style="margin-top:14px">
                    <button type="submit" class="primary">Importer</button>
                    <button type="submit" class="ghost" form="forget-form">Abandonner</button>
                </div>
            </form>
            <form method="post" action="<?= eng_h($self) ?>" id="forget-form"><?= eng_token_field() ?><input type="hidden" name="action" value="forget"></form>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var f = document.getElementById('apply-form');
    if (!f) return;
    f.addEventListener('submit', function (e) {
        var cfg = f.elements['do_config'], pay = f.elements['do_payments'];
        var what = [];
        if (cfg && cfg.checked) what.push('remplacer les tarifs');
        if (pay && pay.checked) what.push('importer les paiements');
        if (!what.length) { e.preventDefault(); alert('Cochez ce qu\'il faut importer.'); return; }
        if (!confirm('Confirmer : ' + what.join(' et ') + ' ?')) { e.preventDefault(); return; }
        if (f.dataset.sent) { e.preventDefault(); return; }
        f.dataset.sent = '1';
    });
})();
</script>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
