<?php
/**
 * lib/schema.php — création des tables du module GREFFE.
 *
 * Le module n'écrit jamais dans les tables du cœur : tout ce qu'il retient vit
 * dans les deux tables ci-dessous, créées à la première ouverture d'une page.
 *
 * Pas de COLLATE imposé : les tables prennent la collation par défaut de la
 * base, celle des tables ianseo créées à l'installation. De toute façon, le
 * module ne fait aucune jointure SQL sur une colonne texte entre ses tables et
 * celles du cœur : comptes et paiements sont rapprochés en PHP.
 *
 * Ne dépend pas de Modules/Custom/_shared/ : ce dossier vient d'un autre dépôt
 * et peut manquer sur une installation qui n'a que GREFFE.
 */

if (!defined('ENG_SCHEMA_VERSION')) define('ENG_SCHEMA_VERSION', 1);

/**
 * Crée les tables si besoin.
 *
 * Idempotent. Protégé par un drapeau de session qui porte la version du
 * schéma : le corps rejoue à chaque nouvelle session et après une mise à jour
 * du module. Une future migration de données devra donc être conditionnée à
 * « la colonne vient d'être ajoutée », jamais à l'état apparent des données.
 *
 * À appeler depuis les pages du module uniquement, jamais depuis menu.php
 * (inclus sur toutes les pages d'ianseo).
 */
function eng_schema()
{
    $flag = '_eng_schema_v' . ENG_SCHEMA_VERSION;
    if (!empty($_SESSION[$flag])) return;

    // Tarifs d'une compétition. EcRules et EcDefault sont du JSON, au format
    // décrit dans lib/pricing.php (eng_config_from_row).
    safe_w_sql("CREATE TABLE IF NOT EXISTS EngagementConfig (
        EcTournament INT UNSIGNED  NOT NULL PRIMARY KEY,
        EcBase       DECIMAL(8,2)  NOT NULL DEFAULT 0,
        EcDefault    TEXT          NULL,
        EcRules      TEXT          NULL,
        EcUpdated    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Historique des paiements. Le paiement actif d'un compte est sa dernière
    // ligne non annulée ; une annulation date la ligne, elle ne la supprime pas.
    safe_w_sql("CREATE TABLE IF NOT EXISTS EngagementPayments (
        EpId         INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
        EpTournament INT UNSIGNED  NOT NULL,
        EpAccount    VARCHAR(25)   NOT NULL,
        EpMethod     VARCHAR(16)   NOT NULL,
        EpAmount     DECIMAL(9,2)  NOT NULL,
        EpNote       VARCHAR(160)  NOT NULL DEFAULT '',
        EpBy         VARCHAR(64)   NOT NULL DEFAULT '',
        EpCreated    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        EpCancelled  DATETIME      NULL,
        EpCancelBy   VARCHAR(64)   NOT NULL DEFAULT '',
        KEY k_account (EpTournament, EpAccount)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $_SESSION[$flag] = true;
}
