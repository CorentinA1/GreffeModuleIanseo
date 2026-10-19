# À lire avant d'utiliser le module

## Un module indépendant

Greffe est un **module complémentaire indépendant**. Il **n'est pas développé par l'équipe IANSEO**
([ianseo.net](https://www.ianseo.net)), qui ne l'a ni validé ni ne l'assure. Le nom IANSEO sert seulement à dire avec
quel logiciel il fonctionne.

Pour une question sur IANSEO lui-même, adressez-vous à l'équipe IANSEO. Pour une question sur ce module, pas à elle :
elle n'en est pas responsable.

## Pas de garantie, pas de support

Le module est fourni **tel quel, sans garantie** (voir [LICENSE](../LICENSE)) et **sans service après-vente** :
personne n'est tenu de répondre aux questions, de corriger un défaut ou d'assurer un suivi.

Il travaille dans la base de données d'IANSEO. Malgré les essais, il peut contenir des **bugs** : montant faux,
paiement mal enregistré, page qui ne s'affiche plus, voire IANSEO entier qui ne répond plus. Une mise à jour
d'IANSEO peut aussi changer ce sur quoi le module s'appuie. **Vérifiez les montants** avant de remettre un reçu ou
d'arrêter les comptes : le module n'est pas un logiciel de comptabilité, ses reçus ne sont pas des factures.

## Sauvegardez vos données

C'est indispensable : **avant la compétition, pendant (après chaque série de paiements) et avant toute mise à jour**
d'IANSEO ou du module.

1. **Tarifs et paiements** : ils sont rangés dans deux tables propres au module (`EngagementConfig`,
   `EngagementPayments`), qui **ne sont pas dans l'export de compétition d'IANSEO**. Deux façons de les sauvegarder :
   - **par compétition** : **Modules → Greffe → Sauvegarde**, deux fichiers `.json` : les **tarifs** et
     l'**historique des paiements**. On les restaure, dans la même compétition ou sur un autre serveur, depuis la
     même page (voir [Utilisation](UTILISATION.md#5-sauvegarde)) ;
   - **toutes les compétitions** : **Modules → Greffe → Mise à jour du module → Désinstaller le module →
     Télécharger une sauvegarde (.sql)** (administrateur ; le téléchargement ne désinstalle rien). On la restaure en
     l'important dans la base `ianseo` (phpMyAdmin par exemple).
2. **La compétition elle-même** (archers, inscriptions) : **Compétition › Exports › Export de la compétition**,
   rechargée avec **Compétition › Import d'une compétition**.

Une sauvegarde de la **base de données d'IANSEO** entière (phpMyAdmin, ou `mysqldump` de la base `ianseo`) couvre
les deux. Gardez les sauvegardes sur un autre support que l'ordinateur de la compétition.

## Licences

- **IANSEO** est distribué par ses auteurs sous la licence **Functional Source License 1.1, ALv2 Future License**
  (FSL-1.1-ALv2), fournie avec IANSEO (`LICENSE.txt`) et consultable sur
  [spdx.org/licenses/FSL-1.1-ALv2](https://spdx.org/licenses/FSL-1.1-ALv2.html). Elle permet d'utiliser, de
  modifier et de compléter le logiciel pour tout usage autre qu'un « usage concurrent », c'est-à-dire un produit ou
  service **commercial** qui remplacerait IANSEO ou offrirait les mêmes fonctions. Elle ne donne aucun droit sur le
  nom et les marques d'IANSEO, au-delà d'indiquer l'origine du logiciel.
- **Ce module** :
  - ne contient **aucun fichier ni code d'IANSEO** : il appelle les fonctions d'IANSEO déjà installé, dans le
    dossier `Modules/Custom/` prévu pour les modules ;
  - est **gratuit et non commercial**, et ne remplace pas IANSEO : il en a besoin pour fonctionner ;
  - est distribué sous sa propre licence, non commerciale ([LICENSE](../LICENSE)). Son utilisation avec IANSEO doit
    aussi respecter la licence d'IANSEO.

---

[← Retour au README](../README.md)
