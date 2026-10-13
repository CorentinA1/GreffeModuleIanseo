# Greffe — frais d'inscription pour IANSEO

**Version 1.2.1**

Module IANSEO pour gérer les frais d'inscription d'une compétition :
- **Tarifs** : les prix, selon la catégorie de l'archer et son nombre de départs ;
- **Paiements** : le pointage des règlements ;
- **Rapport - Bilan** : l'état des encaissements, avec exports CSV et PDF ;
- **Reçus PDF** : un reçu par archer, un récapitulatif par club.

Le dossier s'appelle `ENGAGEMENT`, mais le module s'affiche sous le nom **Greffe**.

> **Module indépendant, sans garantie ni SAV.** Il n'est pas développé par l'équipe IANSEO (ianseo.net). Il peut
> contenir des bugs, jusqu'à bloquer IANSEO : **sauvegardez vos tarifs et paiements** (ils ne sont pas dans l'export
> de compétition d'IANSEO) et vérifiez les montants. Voir [À lire avant d'utiliser le module](docs/AVERTISSEMENT.md)
> (sauvegardes, licences).

## Installation rapide

1. Télécharger `ENGAGEMENT-vX.Y.Z.zip` dans **Releases**.
2. Le décompresser dans `Modules/Custom/` d'IANSEO : le dossier doit s'appeler `ENGAGEMENT`.
3. Ouvrir une compétition : **Modules → Greffe → Tarifs**.

## Documentation

- [À lire avant d'utiliser le module](docs/AVERTISSEMENT.md) : module indépendant, sauvegardes, licences
- [Installation, mise à jour et désinstallation](docs/INSTALLATION.md)
- [Utilisation](docs/UTILISATION.md) : tarifs, paiements, rapport et bilan, reçus, droits d'accès
- [Données et base de données](docs/DONNEES.md) : ce que le module enregistre
- [Nouveautés de chaque version](docs/NOUVEAUTES.md)

## Licence

Licence non commerciale : voir le fichier [LICENSE](LICENSE). IANSEO a sa propre licence (FSL-1.1-ALv2), que son
utilisation avec ce module doit respecter : voir [Licences](docs/AVERTISSEMENT.md#licences).
