<?php
/**
 * admin/update-check.php — vérification des mises à jour en arrière-plan, pour la pastille du menu.
 *
 * Appelé par une image invisible que menu.php ajoute au menu de l'administrateur quand la
 * dernière vérification date de plus d'un jour (lib/update.php, eng_upd_notify_*).
 * Ne renvoie rien (204) : le navigateur n'affiche rien et la page n'attend pas.
 * La session est relâchée avant d'interroger GitHub : sinon, sans Internet, les pages
 * suivantes de l'utilisateur attendraient la fin du délai.
 */
require_once dirname(__DIR__, 4) . '/config.php';
require_once dirname(__DIR__) . '/lib/update.php';

$admin = hasFullACL(AclRoot, '', AclReadWrite)
    && (trim((string) ($_SESSION['AUTH_User'] ?? '')) === '' || !empty($_SESSION['AUTH_ROOT']));
session_write_close();
header('Cache-Control: no-store');
http_response_code(204);
if ($admin) {
    ignore_user_abort(true);
    eng_upd_notify_refresh(dirname(__DIR__));
}
