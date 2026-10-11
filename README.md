# Greffe — frais d'inscription pour IANSEO

**Version 1.2.0**

Module IANSEO pour gérer les frais d'inscription d'une compétition :
- **Tarifs** : les prix, selon la catégorie de l'archer et son nombre de départs ;
- **Paiements** : le pointage des règlements ;
- **Rapport - Bilan** : l'état des encaissements, avec exports CSV et PDF ;
- **Reçus PDF** : un reçu par archer, un récapitulatif par club.

Le dossier s'appelle `ENGAGEMENT`, mais le module s'affiche sous le nom **Greffe**.

## Installation

Il faut une installation IANSEO qui fonctionne (PHP 8, MySQL ou MariaDB).

Le module doit se trouver dans `Modules/Custom/ENGAGEMENT`. Le nom du dossier, `ENGAGEMENT`, compte : les liens du module l'utilisent. `Modules/Custom/` est le seul dossier que les mises à jour d'IANSEO ne réécrivent pas.

### Windows

1. Télécharger `ENGAGEMENT-vX.Y.Z.zip` dans **Releases**. Le dossier y est déjà nommé `ENGAGEMENT`.
2. Le décompresser dans `C:\ianseo\htdocs\Modules\Custom\`, ce qui donne `C:\ianseo\htdocs\Modules\Custom\ENGAGEMENT\menu.php`.

Le bouton **Code → Download ZIP** convient aussi, à condition de renommer en `ENGAGEMENT` le dossier `GreffeModuleIanseo-main` obtenu.

### Linux (ligne de commande)

Adapter le chemin à votre installation (par exemple `/var/www/html/ianseo`) :

```bash
cd /var/www/html/ianseo/Modules/Custom
sudo git clone https://github.com/CorentinA1/GreffeModuleIanseo.git ENGAGEMENT
```

Sans Git :

```bash
cd /var/www/html/ianseo/Modules/Custom
wget https://github.com/CorentinA1/GreffeModuleIanseo/archive/refs/heads/main.tar.gz
tar xzf main.tar.gz && mv GreffeModuleIanseo-main ENGAGEMENT && rm main.tar.gz
sudo chown -R www-data:www-data ENGAGEMENT   # pour les mises à jour depuis IANSEO
```

### Premier lancement

Ouvrir une compétition dans IANSEO : le menu **Modules → Greffe** apparaît. Les onglets Tarifs · Paiements · Rapport - Bilan permettent ensuite de passer d'une page à l'autre. Les tables du module se créent toutes seules à l'ouverture de la première page.

### Mise à jour

**Depuis IANSEO** (à partir de la version 1.1.0) : **Modules → Greffe → Mise à jour du module**, réservé à l'administrateur.
1. **Vérifier les mises à jour** compare la version installée à celle de GitHub et affiche les nouveautés.
2. **Mettre à jour** télécharge et remplace les fichiers du module. Tous les fichiers sont récupérés avant d'en remplacer un seul : en cas d'échec, rien n'est modifié.

Le serveur web doit pouvoir écrire dans le dossier `ENGAGEMENT` (sous Linux : `chown www-data`, voir l'installation). La source est indiquée dans `module.json`, que les mises à jour ne remplacent jamais.

**Autres cas :**
- **Installé avec `git clone` :** `cd /var/www/html/ianseo/Modules/Custom/ENGAGEMENT && sudo git pull`. La page d'IANSEO refuse les dossiers gérés par Git, pour ne pas les désynchroniser.
- **Depuis une version 1.0.x,** qui n'a pas encore cette page : une seule fois, remplacer le contenu du dossier `ENGAGEMENT` par celui de la nouvelle version.

Les tarifs et les paiements, enregistrés en base, sont toujours conservés.

### Désinstallation

**Modules → Greffe → Mise à jour du module → Désinstaller le module** (administrateur). L'écran de confirmation :
- indique ce qui sera supprimé : le dossier du module et, **seulement si vous cochez la case**, ses deux tables (tarifs et paiements de toutes les compétitions) ;
- propose de **télécharger une sauvegarde** des tables (.sql) avant de les supprimer ;
- demande de taper le nom du module (`ENGAGEMENT`) pour confirmer.

Les tables d'IANSEO ne sont jamais touchées. Si vous conservez les tables, une réinstallation retrouve les tarifs et les paiements.

## Utilisation

### 1. Tarifs (Modules → Greffe → Tarifs)

Une **règle** regroupe un libellé (« Jeunes », « Adultes »…), des divisions (armes), des classes (catégories) et une **grille de prix**.

- **Divisions et classes :** si aucune case n'est cochée, la règle s'applique à toutes.
- **Grille :** elle a une case par session de la compétition. Chaque case contient le prix **total** pour ce nombre de départs (exemple : 1 départ = 10 €, 2 départs = 17 €), pas un prix par départ. Les centimes sont acceptés (« 7,50 »).
- **Choix de la règle :** pour chaque archer, **la première règle qui correspond** s'applique. L'ordre compte : placez les règles les plus précises en haut (boutons ▲ ▼).
- **Si aucune règle ne correspond :** la **grille par défaut** s'applique. Si elle est vide, c'est le **tarif de base**, multiplié par le nombre de départs.
- **Aperçu :** le cadre « Aperçu » calcule le prix d'une catégorie et d'un nombre de départs sur la saisie en cours, avant même l'enregistrement.

### 2. Paiements (Modules → Greffe → Paiements)

Une ligne par archer, identifié par sa **licence** (un archer sans licence est repéré par son numéro d'inscription IANSEO). Le nombre de départs est le nombre d'inscriptions de cette licence dans la compétition.

- **Payer :** choisir le moyen (Espèces, Chèque, Carte bancaire, Virement, Exonéré), éventuellement une note, puis cliquer. Pour un chèque, la note sert au **numéro de chèque** : elle reste facultative, mais elle alimente l'état des chèques. Le paiement porte toujours sur la totalité du montant dû à cet instant.
- **Annuler :** le compte repasse à « non payé ». Le paiement annulé reste dans l'historique.
- **Payé — écart :** le montant dû a changé après le paiement (départ ajouté ou retiré, tarifs modifiés). « Régulariser » réenregistre le paiement au nouveau montant, avec le même moyen.
- **Règlement d'un club en une fois :** tous les archers non payés du club passent à payé avec le même moyen.
- **Filtres :** club, règle, statut, catégories mixtes, archers sans règle, plus une recherche par nom, licence ou club.
- **Reçu / Récap. :** sous le montant de chaque archer, ce lien ouvre son reçu PDF.

Statuts possibles :

| Statut | Signification |
|---|---|
| Non payé | Rien n'est enregistré. |
| Payé | Payé au montant dû actuel. |
| Payé — écart | Payé, mais le montant dû a changé depuis. |
| Exonéré | Pointé avec le moyen « Exonéré » : soldé sans encaissement. |
| Exonéré (IANSEO) | « Ne paie pas » est coché pour l'archer dans IANSEO (Accréditation). |

Signalements ⚠ :
- **catégories différentes selon les départs :** la règle appliquée est celle du premier départ ;
- **grille plus courte que le nombre de départs :** le dernier prix saisi s'applique ;
- **« ne paie pas » coché sur une partie seulement des inscriptions.**

### 3. Rapport - Bilan (Modules → Greffe → Rapport - Bilan)

- **Synthèse :** archers, inscriptions, total dû, encaissé, exonéré, reste à encaisser, et trop-perçu s'il y en a. On a toujours **dû = encaissé + exonéré + reste − trop-perçu**.
- **Ventilations :** par moyen de paiement, par club, par règle, par nombre de départs et par départ (session).
- **Listes de contrôle :** impayés, comptes en écart, archers sans règle, catégories mixtes. Un clic sur une ligne ouvre la page des paiements filtrée.
- **Exports :**
  - **Bilan financier (PDF)** : état d'une page avec le résumé (dû, encaissé, exonéré, reste), les encaissements par moyen de paiement (tous les moyens, même à 0 €) et le nombre d'impayés par départ ;
  - **État complet (PDF)** ;
  - **CSV par archer** et **CSV par club** : séparateur `;` et accents compatibles Excel ;
  - **Récapitulatifs clubs (PDF)** : un par club ;
  - **État des chèques (PDF)** : bordereau de remise en banque, avec une ligne par chèque (numéro, date, club, archers, montant), le total et une zone « remis en banque le ». Un chèque de club réglé en une fois tient sur une seule ligne : regroupement par numéro, ou, sans numéro, par club et heure d'enregistrement.

### 4. Reçus

Ce sont des **reçus, pas des factures** : il n'y a pas de numérotation légale.

- **Archer réglé :** « Reçu d'inscription » avec le moyen, la date et un cadre pour le cachet de l'organisateur.
- **Archer non réglé :** « Récapitulatif d'inscription » avec le reste dû.
- **Club :** liste de ses archers, montants, statuts et bilan.

## Droits d'accès

- **Tarifs et Paiements :** droit IANSEO « Participants » en **écriture**.
- **Rapport - Bilan et reçus :** même droit, en **lecture**.

## Base de données

Le module **ne modifie aucune table d'IANSEO** : il se contente de les lire. Il crée deux tables qui lui sont propres, `EngagementConfig` (les tarifs) et `EngagementPayments` (l'historique des paiements).

## Licence

MIT : voir le fichier [LICENSE](LICENSE). Libre d'utilisation, de modification et de redistribution, en conservant la mention de copyright.
