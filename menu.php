<?php
// ── Menu ianseo — module GREFFE (dossier ENGAGEMENT) ─────────────────────────
// Inclus sur toutes les pages par Common/Menu.php : aucune requête ici, et
// surtout pas eng_schema() (les tables se créent à l'ouverture d'une page).
// Entrées visibles seulement si une compétition est ouverte et que
// l'utilisateur a au moins la lecture sur les participants.
if (!empty($on) && !empty($_SESSION['TourId'])
    && subFeatureAcl($acl, AclParticipants, 'pEntries') >= AclReadOnly) {

    if (!isset($ret['MODS']['GREFFE'])) $ret['MODS']['GREFFE'][] = 'GREFFE';

    if (subFeatureAcl($acl, AclParticipants, 'pEntries') >= AclReadWrite) {
        $ret['MODS']['GREFFE'][] = 'Tarifs|' . $CFG->ROOT_DIR . 'Modules/Custom/ENGAGEMENT/tarifs.php';
        $ret['MODS']['GREFFE'][] = 'Paiements|' . $CFG->ROOT_DIR . 'Modules/Custom/ENGAGEMENT/paiements.php';
    }
    $ret['MODS']['GREFFE'][] = 'Reporting|' . $CFG->ROOT_DIR . 'Modules/Custom/ENGAGEMENT/reporting.php';
}
