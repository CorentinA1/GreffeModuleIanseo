# Données et base de données

Le module **ne modifie aucune table d'IANSEO** : il se contente de les lire. Il crée deux tables qui lui sont propres, `EngagementConfig` (les tarifs et la mention de reçu) et `EngagementPayments` (l'historique des paiements).

Depuis la version 1.3.0, `EngagementConfig` a une colonne `EcReceiptNote` (mention en pied de reçu), ajoutée
automatiquement à la première ouverture d'une page du module. Les règles tarifaires (`EcRules`, JSON) portent leur
critère de club (`club` : `all`, `org`, `other` ou `list`, et `clubs` : codes de club) ; une règle enregistrée avant
la 1.3.0 vaut « tous les clubs ».

La page **Sauvegarde** écrit deux fichiers JSON (format `greffe-ianseo`, version 1), chacun avec la compétition
d'origine et un champ `content` :
- `tarifs` : règles, grille par défaut, tarif de base, mention de reçu ;
- `paiements` : chaque ligne d'historique (archer, moyen, montant, note, auteur, dates d'enregistrement et
  d'annulation).

Ces deux tables ne font pas partie de l'export de compétition d'IANSEO : voir les sauvegardes dans
[À lire avant d'utiliser le module](AVERTISSEMENT.md).

---

[← Retour au README](../README.md)
