<?php
/**
 * admin/uninstall.php — Greffe : désinstallation du module.
 *
 * Réservé à l'administrateur (même règle que admin/update.php). Écran de
 * confirmation : ce qui sera supprimé, case « supprimer aussi les tables »
 * (décochée par défaut), sauvegarde SQL téléchargeable, nom du module à taper.
 * Voir lib/uninstall.php pour la suppression du dossier.
 */
define('HTDOCS', dirname(__DIR__, 4));
require_once HTDOCS . '/config.php';
require_once dirname(__DIR__) . '/lib/uninstall.php';

checkFullACL(AclRoot, '', AclReadWrite);
if (trim((string) ($_SESSION['AUTH_User'] ?? '')) !== '' && empty($_SESSION['AUTH_ROOT'])) {
    CD_redirect($CFG->ROOT_DIR . 'noAccess.php');
    die();
}

$dir    = dirname(__DIR__);
$name   = basename($dir);
$target = eng_uns_target($dir);
$isDev  = is_dir($dir . '/.git');
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
if (empty($_SESSION['ENG_UNS_TOKEN'])) $_SESSION['ENG_UNS_TOKEN'] = bin2hex(random_bytes(16));
$token = $_SESSION['ENG_UNS_TOKEN'];

// ── Sauvegarde SQL des tables ────────────────────────────────────────────────
if (isset($_GET['backup'])) {
    eng_uns_send_backup('greffe_sauvegarde_' . gmdate('Ymd_His') . '.sql');
    exit;
}

// ── Désinstallation ──────────────────────────────────────────────────────────
$errors = [];
$dropped = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'uninstall') {
    if (!hash_equals($token, (string) ($_POST['eng_token'] ?? ''))) {
        $errors[] = 'Jeton de sécurité invalide : rechargez la page.';
    } elseif ($isDev) {
        $errors[] = 'Copie de développement (dossier .git) : désinstallation refusée.';
    } elseif (!$target) {
        $errors[] = 'Dossier du module non reconnu : désinstallation refusée par sécurité.';
    } elseif (trim((string) ($_POST['confirm'] ?? '')) !== $name) {
        $errors[] = 'Tapez exactement « ' . $name . ' » pour confirmer.';
    } else {
        if (!empty($_POST['drop_tables'])) $dropped = eng_uns_drop_tables();
        unset($_SESSION['ENG_UNS_TOKEN']);

        // Windows : suppression confiée à un script jetable hors du module.
        $remover = eng_uns_write_remover($target, $CFG->ROOT_DIR);
        if ($remover) {
            header('Location: ' . $CFG->ROOT_DIR . 'Modules/Custom/' . $remover['name'] . '?k=' . $remover['key']);
            exit;
        }
        // Modules/Custom/ non accessible en écriture : suppression directe (Linux).
        chdir(HTDOCS);
        $gone = eng_uns_rrmdir($target);
        $PAGE_TITLE = 'Greffe - Désinstallation';
        include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';
        echo '<div style="max-width:700px;font-size:13px;margin:10px">';
        echo $gone
            ? '<h2 style="color:#1e5e33">Greffe est désinstallé.</h2><p>Le dossier du module a été supprimé.</p>'
            : '<h2 style="color:#b3261e">Suppression incomplète</h2><p>Supprimez à la main le dossier <code>' . $h($target) . '</code>.</p>';
        echo $dropped ? '<p>Tables supprimées : ' . $h(implode(', ', $dropped)) . '.</p>' : '<p>Les tables du module ont été conservées.</p>';
        echo '<p><a href="' . $h($CFG->ROOT_DIR) . '">Retour à ianseo</a></p></div>';
        include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php';
        exit;
    }
}

$counts = eng_uns_counts();
$hasData = (bool) array_filter($counts);
$PAGE_TITLE = 'Greffe - Désinstallation';
include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';
?>
<style>
#eng-uns { max-width:760px; color:#4c4e50; font-size:13px; }
#eng-uns h2 { color:#b3261e; font-size:18px; margin:6px 0 14px; }
#eng-uns .box { border:1px solid #e8b4ae; background:#fdf6f5; border-radius:8px; padding:16px 20px; margin-bottom:14px; }
#eng-uns ul { margin:6px 0 12px; padding-left:20px; line-height:1.8; }
#eng-uns code { background:#fff; border:1px solid #eadad8; border-radius:3px; padding:1px 5px; }
#eng-uns .msg { border-radius:6px; padding:10px 14px; margin-bottom:12px; background:#fdecea; border:1px solid #e6a19b; color:#b3261e; }
#eng-uns .warn { background:#fff4e5; border-left:3px solid #f5a623; padding:10px 14px; border-radius:0 6px 6px 0; margin:12px 0; }
#eng-uns label.drop { display:flex; gap:8px; align-items:flex-start; margin:12px 0; }
#eng-uns input[type=text] { padding:7px 10px; border:1px solid #c8b4b0; border-radius:5px; font-size:13px; width:220px; }
#eng-uns button, #eng-uns a.btn { border-radius:6px; cursor:pointer; padding:8px 18px; font-size:13px; font-weight:600; text-decoration:none; display:inline-block; }
#eng-uns button.danger { background:#c0392b; border:1px solid #c0392b; color:#fff; }
#eng-uns a.btn { background:#fff; border:1px solid #0254a8; color:#0254a8; }
#eng-uns a.cancel { margin-left:10px; }
</style>

<div id="eng-uns">
    <h2>Greffe — Désinstaller le module</h2>

    <?php foreach ($errors as $e): ?><div class="msg"><?= $h($e) ?></div><?php endforeach; ?>

    <?php if ($isDev): ?>
        <div class="msg">Cette installation est une copie de développement (dossier <code>.git</code>) : la désinstallation est désactivée.</div>
    <?php elseif (!$target): ?>
        <div class="msg">Le dossier du module n'est pas reconnu (<code><?= $h($dir) ?></code>) : désinstallation désactivée par sécurité.</div>
    <?php else: ?>
    <div class="box">
        <b>Seront supprimés :</b>
        <ul>
            <li>le dossier du module <code><?= $h($target) ?></code> (le menu Greffe disparaît) ;</li>
            <li><b>seulement si vous cochez la case ci-dessous</b>, les tables du module :
                <?php foreach ($counts as $t => $n): ?>
                    <code><?= $h($t) ?></code> (<?= $n === null ? 'absente' : $n . ' ligne' . ($n > 1 ? 's' : '') ?>)
                <?php endforeach; ?>.</li>
        </ul>
        Les tables d'IANSEO ne sont jamais touchées. Le module peut être réinstallé plus tard depuis GitHub ;
        si les tables sont conservées, ses tarifs et paiements seront retrouvés.

        <?php if ($hasData): ?>
            <div class="warn">Les tables contiennent les <b>tarifs et l'historique des paiements de toutes les compétitions</b>.
                Avant de les supprimer, gardez une copie :
                <a class="btn" href="?backup=1" style="margin-left:6px">Télécharger une sauvegarde (.sql)</a></div>
        <?php endif; ?>

        <form method="post" id="uns-form">
            <input type="hidden" name="eng_token" value="<?= $h($token) ?>">
            <input type="hidden" name="action" value="uninstall">
            <label class="drop"><input type="checkbox" name="drop_tables" value="1" id="drop">
                <span><b>Supprimer aussi les tables du module</b> (tarifs et paiements de toutes les compétitions) — irréversible.</span></label>
            <p>Pour confirmer, tapez le nom du module : <b><?= $h($name) ?></b><br>
                <input type="text" name="confirm" autocomplete="off" required></p>
            <button type="submit" class="danger">Désinstaller</button>
            <a class="cancel" href="update.php">Annuler</a>
        </form>
    </div>
    <?php endif; ?>
</div>
<script>
(function () {
    var f = document.getElementById('uns-form');
    if (!f) return;
    f.addEventListener('submit', function (e) {
        var drop = document.getElementById('drop').checked;
        var msg = drop ? 'Désinstaller Greffe ET SUPPRIMER ses tables (tarifs et paiements) ? Cette action est irréversible.'
                       : 'Désinstaller Greffe ? Les tables (tarifs et paiements) seront conservées.';
        if (!confirm(msg)) e.preventDefault();
    });
})();
</script>
<?php include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'; ?>
