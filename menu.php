<?php
// ── Menu ianseo — module GREFFE (dossier ENGAGEMENT) ─────────────────────────
// Inclus sur toutes les pages par Common/Menu.php : aucune requête ici, et
// surtout pas eng_schema() (les tables se créent à l'ouverture d'une page).
// Entrées visibles seulement si une compétition est ouverte et que
// l'utilisateur a au moins la lecture sur les participants.
if (!empty($on) && !empty($_SESSION['TourId'])
    && subFeatureAcl($acl, AclParticipants, 'pEntries') >= AclReadOnly) {

    // Même clé partout ('Greffe') : le premier élément est le titre du sous-menu.
    if (!isset($ret['MODS']['Greffe'])) $ret['MODS']['Greffe'][] = 'Greffe';

    if (subFeatureAcl($acl, AclParticipants, 'pEntries') >= AclReadWrite) {
        $ret['MODS']['Greffe'][] = 'Tarifs|' . $CFG->ROOT_DIR . 'Modules/Custom/ENGAGEMENT/tarifs.php';
        $ret['MODS']['Greffe'][] = 'Paiements|' . $CFG->ROOT_DIR . 'Modules/Custom/ENGAGEMENT/paiements.php';
    }
    $ret['MODS']['Greffe'][] = 'Rapport - Bilan|' . $CFG->ROOT_DIR . 'Modules/Custom/ENGAGEMENT/reporting.php';

    // Administrateur seulement. Avec un module de comptes (AUTH), AclRoot ne
    // suffit pas : il faut aussi la vue administrateur serveur (AUTH_ROOT).
    if (subFeatureAcl($acl, AclRoot, '') >= AclReadWrite
        && (empty($_SESSION['AUTH_User']) || !empty($_SESSION['AUTH_ROOT']))) {
        $ret['MODS']['Greffe'][] = MENU_DIVIDER;
        $ret['MODS']['Greffe'][] = 'Mise à jour du module|' . $CFG->ROOT_DIR . 'Modules/Custom/ENGAGEMENT/admin/update.php';
    }
}
