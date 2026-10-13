<?php
/**
 * admin/update.php — Greffe : mise à jour du module depuis GitHub.
 *
 * Réservé à l'administrateur : AclRoot en écriture et, si un module de comptes
 * (AUTH) est actif, la vue « administrateur serveur » (AUTH_ROOT), car AUTH
 * accorde AclRoot à tout organisateur connecté hors compétition.
 * Ne demande pas de compétition ouverte.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once HTDOCS . '/config.php';
require_once dirname(__DIR__) . '/lib/update.php';

checkFullACL(AclRoot, '', AclReadWrite);
if (trim((string) ($_SESSION['AUTH_User'] ?? '')) !== '' && empty($_SESSION['AUTH_ROOT'])) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}

$dir = dirname(__DIR__);
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
if (empty($_SESSION['ENG_UPD_TOKEN'])) $_SESSION['ENG_UPD_TOKEN'] = bin2hex(random_bytes(16));
$token = $_SESSION['ENG_UPD_TOKEN'];

$check = null;
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($token, (string) ($_POST['eng_token'] ?? ''))) {
        http_response_code(403);
        exit('Jeton de sécurité invalide : rechargez la page.');
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'update' || $action === 'force') $result = eng_upd_apply($dir, $action === 'force');
    $check = eng_upd_check($dir);   // après une mise à jour, relit la version installée
}

$cfg = eng_upd_config($dir);
$local = eng_upd_local($dir);
$isDev = is_dir($dir . '/.git');
$field = '<input type="hidden" name="eng_token" value="' . $h($token) . '">';

$PAGE_TITLE = 'Greffe - Mise à jour du module';
include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';
?>
<style>
#eng-upd { max-width:760px; color:#4c4e50; font-size:13px; }
#eng-upd h2 { color:#01367c; font-size:18px; margin:6px 0 14px; }
#eng-upd .card { border:1px solid #d2d4d6; border-radius:6px; background:#fff; padding:14px 16px; margin-bottom:14px; }
#eng-upd .card h3 { margin:0 0 10px; font-size:14px; color:#01367c; }
#eng-upd .msg { border-radius:6px; padding:10px 14px; margin-bottom:12px; }
#eng-upd .ok   { background:#eafaf0; border:1px solid #9bd3ad; color:#1e5e33; }
#eng-upd .err  { background:#fdecea; border:1px solid #e6a19b; color:#b3261e; }
#eng-upd .warn { background:#fff8e1; border:1px solid #e0c46c; color:#8a5a00; }
#eng-upd .badge { display:inline-block; border-radius:10px; padding:1px 9px; font-size:11px; font-weight:600; margin-left:6px; }
#eng-upd .badge.new { background:#fff1d6; color:#8a5a00; }
#eng-upd .badge.same { background:#eafaf0; color:#1e5e33; }
#eng-upd .notes { background:#f7f7f7; border-left:3px solid #0254a8; padding:8px 12px; margin:10px 0 0; white-space:pre-line; }
#eng-upd button { border-radius:6px; cursor:pointer; padding:8px 18px; font-size:13px; font-weight:600; }
#eng-upd button.primary { background:#0254a8; border:1px solid #0254a8; color:#fff; }
#eng-upd button.ghost { background:#fff; border:1px solid #0254a8; color:#0254a8; }
#eng-upd .sub { color:#7d8183; font-size:12px; }
#eng-upd details { margin-top:10px; }
</style>

<div id="eng-upd">
    <h2>Greffe — Mise à jour du module</h2>

    <?php if ($result): ?>
        <div class="msg <?= $result['ok'] ? 'ok' : 'err' ?>"><?= $h($result['message']) ?></div>
    <?php endif; ?>
    <?php if ($isDev): ?>
        <div class="msg warn">Cette installation est une copie de développement (dossier <code>.git</code>) :
            la mise à jour depuis cette page est désactivée. Utilisez Git.</div>
    <?php endif; ?>

    <div class="card">
        <h3>Version installée : <?= $h($local['version'] ?? 'inconnue') ?></h3>
        <div class="sub">Source : <?= $h($cfg['github_url']) ?> (branche <?= $h($cfg['github_branch']) ?>) — fichier <code>module.json</code>.</div>
        <form method="post" style="margin-top:12px">
            <?= $field ?><input type="hidden" name="action" value="check">
            <button type="submit" class="ghost">Vérifier les mises à jour</button>
        </form>

        <?php if ($check): ?>
            <?php if ($check['error']): ?>
                <div class="msg err" style="margin-top:12px"><?= $h($check['error']) ?></div>
            <?php else: ?>
                <p style="margin:14px 0 0">Version disponible : <b><?= $h($check['remote']) ?></b>
                    <?= $check['date'] !== '' ? '<span class="sub">(' . $h($check['date']) . ')</span>' : '' ?>
                    <span class="badge <?= $check['update'] ? 'new' : 'same' ?>"><?= $check['update'] ? 'Mise à jour disponible' : '✓ À jour' ?></span></p>
                <?php if ($check['notes'] !== ''): ?><div class="notes"><?= $h($check['notes']) ?></div><?php endif; ?>
                <?php if ($cfg['github_url'] !== ''): ?><p class="sub" style="margin:6px 0 0"><a href="<?= $h(rtrim($cfg['github_url'], '/') . '/blob/main/docs/NOUVEAUTES.md') ?>" target="_blank" rel="noopener">Toutes les nouveautés</a></p><?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($check && !$check['error'] && !$isDev): ?>
    <div class="card">
        <?php if ($check['update']): ?>
            <h3>Installer la version <?= $h($check['remote']) ?></h3>
            <form method="post" onsubmit="return confirm('Mettre à jour Greffe en version <?= $h($check['remote']) ?> ?');">
                <?= $field ?><input type="hidden" name="action" value="update">
                <button type="submit" class="primary">Mettre à jour</button>
            </form>
        <?php else: ?>
            <details><summary>Forcer la réinstallation de la version <?= $h($check['remote']) ?></summary>
                <form method="post" style="margin-top:10px" onsubmit="return confirm('Réinstaller tous les fichiers du module ?');">
                    <?= $field ?><input type="hidden" name="action" value="force">
                    <button type="submit" class="ghost">Réinstaller</button>
                </form>
            </details>
        <?php endif; ?>
        <p class="sub" style="margin:12px 0 0">Les fichiers du module sont remplacés par ceux du dépôt. Les tarifs et les paiements,
            enregistrés en base, ne sont pas touchés. Le serveur web doit avoir le droit d'écrire dans le dossier du module.</p>
    </div>
    <?php endif; ?>

    <details class="card" style="border-color:#e8b4ae;background:#fdf6f5">
        <summary style="color:#c0392b;font-weight:600;cursor:pointer">Désinstaller le module</summary>
        <p style="margin:10px 0">Supprime le dossier du module. Ses tables (tarifs et paiements) sont conservées,
            sauf si vous demandez leur suppression sur l'écran de confirmation.</p>
        <a href="uninstall.php" style="display:inline-block;background:#c0392b;color:#fff;padding:8px 18px;border-radius:6px;font-weight:600;text-decoration:none">Désinstaller…</a>
    </details>
</div>
<?php include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'; ?>
