# GREFFE — frais d'inscription pour IANSEO

**Version 1.0.0**

Module IANSEO pour gérer les frais d'inscription d'une compétition :
- **Tarifs** : les prix, selon la catégorie de l'archer et son nombre de départs ;
- **Paiements** : le pointage des règlements ;
- **Reporting** : l'état des encaissements, avec exports CSV et PDF ;
- **Reçus PDF** : un reçu par archer, un récapitulatif par club.

Le dossier s'appelle `ENGAGEMENT`, mais le module s'affiche sous le nom **GREFFE**.

## Installation

1. Télécharger le module : bouton **Code → Download ZIP** sur GitHub, ou la dernière version dans **Releases**.
2. Décompresser l'archive et **renommer le dossier obtenu en `ENGAGEMENT`**. Le nom compte : les liens du module l'utilisent.
3. Le copier dans `Modules/Custom/` de l'installation IANSEO, ce qui donne `C:\ianseo\htdocs\Modules\Custom\ENGAGEMENT\menu.php`. C'est le seul dossier que les mises à jour d'IANSEO ne réécrivent pas.

   Avec Git : `git clone https://github.com/CorentinA1/GreffeModuleIanseo.git ENGAGEMENT`, lancé depuis `Modules/Custom/`.

4. Ouvrir une compétition. Le menu **Modules → GREFFE** apparaît, et les onglets Tarifs · Paiements · Reporting permettent ensuite de passer d'une page à l'autre.
5. Les tables du module se créent toutes seules à l'ouverture de la première page.

**Mise à jour :** remplacer les fichiers du dossier `ENGAGEMENT` par ceux de la nouvelle version. Les tarifs et les paiements, enregistrés en base, sont conservés.

Prérequis : PHP 8 et MySQL ou MariaDB, ceux d'IANSEO.

## Utilisation

### 1. Tarifs (Modules → GREFFE → Tarifs)

Une **règle** regroupe un libellé (« Jeunes », « Adultes »…), des divisions (armes), des classes (catégories) et une **grille de prix**.

- **Divisions et classes :** si aucune case n'est cochée, la règle s'applique à toutes.
- **Grille :** elle a une case par session de la compétition. Chaque case contient le prix **total** pour ce nombre de départs (exemple : 1 départ = 10 €, 2 départs = 17 €), pas un prix par départ. Les centimes sont acceptés (« 7,50 »).
- **Choix de la règle :** pour chaque archer, **la première règle qui correspond** s'applique. L'ordre compte : placez les règles les plus précises en haut (boutons ▲ ▼).
- **Si aucune règle ne correspond :** la **grille par défaut** s'applique. Si elle est vide, c'est le **tarif de base**, multiplié par le nombre de départs.
- **Aperçu :** le cadre « Aperçu » calcule le prix d'une catégorie et d'un nombre de départs sur la saisie en cours, avant même l'enregistrement.

### 2. Paiements (Modules → GREFFE → Paiements)

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

### 3. Reporting (Modules → GREFFE → Reporting)

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
- **Reporting et reçus :** même droit, en **lecture**.

## Écritures en base

Le module **n'écrit jamais dans les tables d'IANSEO**. Il les lit seulement : `Entries`, `Qualifications`, `Countries`, `Divisions`, `Classes`, `Tournament`.

Il crée et utilise deux tables à lui, déclarées dans `version.json` :

| Table | Contenu | Écrite par |
|---|---|---|
| `EngagementConfig` | Une ligne par compétition : tarif de base, grille par défaut et règles (JSON). | Enregistrement de la page Tarifs |
| `EngagementPayments` | L'historique des paiements, une ligne par paiement. | Payer, Régler un club, Régulariser (insertion) ; Annuler, Régulariser (date d'annulation) |

- **Aucune suppression :** une annulation renseigne `EpCancelled` et `EpCancelBy` au lieu d'effacer la ligne. Le paiement actif d'un archer est sa dernière ligne non annulée.
- **Montant figé :** le montant enregistré (`EpAmount`) est le dû au moment du clic, recalculé par le serveur.
- **Auteur :** `EpBy` contient l'utilisateur connecté si le module d'authentification AUTH est installé, sinon il reste vide.
- **Heures :** les dates sont en heure locale de la compétition, car IANSEO règle la connexion MySQL sur son fuseau.
- **Désinstallation :** supprimer le dossier, puis les deux tables :

```sql
DROP TABLE IF EXISTS EngagementConfig, EngagementPayments;
```

## Pour les développeurs

| Fichier | Rôle |
|---|---|
| `menu.php` | Entrées du menu Modules → GREFFE. |
| `tarifs.php`, `paiements.php`, `reporting.php`, `recu.php` | Les pages. |
| `lib/boot.php` | Amorçage commun : IANSEO, droits, tables, jeton CSRF, mise en forme. |
| `lib/schema.php` | Création des tables. |
| `lib/pricing.php` | Moteur de calcul des prix (fonctions pures). |
| `lib/accounts.php` | Comptes des archers, statuts, paiements. |
| `lib/report.php` | Agrégats du reporting (fonction pure). |
| `lib/pdf.php` | Mise en page PDF, sur la base de la classe `IanseoPdf`. |

Deux pièges d'IANSEO à connaître :
- **Noms :** `Entries.EnFirstName` contient le **nom de famille** et `EnName` le **prénom**.
- **Dates :** PHP est forcé en UTC, mais la connexion MySQL est réglée sur le fuseau de la compétition. Pour dater, utiliser `NOW()` en SQL (ou `eng_db_now()`), jamais `date()`.

## Licence

MIT : voir le fichier [LICENSE](LICENSE). Libre d'utilisation, de modification et de redistribution, en conservant la mention de copyright.
