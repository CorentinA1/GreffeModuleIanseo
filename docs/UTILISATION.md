# Utilisation

> **Sauvegardez les tarifs et paiements** avant et pendant la compétition : ils ne sont pas dans l'export de
> compétition d'IANSEO. Voir [À lire avant d'utiliser le module](AVERTISSEMENT.md).

## 1. Tarifs (Modules → Greffe → Tarifs)

Une **règle** regroupe un libellé (« Jeunes », « Adultes »…), des divisions (armes), des classes (catégories), des clubs et une **grille de prix**.

- **Divisions et classes :** si aucune case n'est cochée, la règle s'applique à toutes.
- **Clubs :** tous les clubs (par défaut), le **club organisateur**, les **autres clubs**, ou des **clubs choisis**
  (cases à cocher). Le club organisateur est celui déclaré dans la compétition (**Compétition › Informations**,
  code du club organisateur) ; s'il n'est pas renseigné, « Club organisateur » ne retient personne. Exemple de tarif
  réduit : une règle « Jeunes — club organisateur » placée **avant** la règle « Jeunes ».
- **Grille :** elle a une case par session de la compétition. Chaque case contient le prix **total** pour ce nombre de départs (exemple : 1 départ = 10 €, 2 départs = 17 €), pas un prix par départ. Les centimes sont acceptés (« 7,50 »).
- **Choix de la règle :** pour chaque archer, **la première règle qui correspond** s'applique. L'ordre compte : placez les règles les plus précises en haut (boutons ▲ ▼).
- **Si aucune règle ne correspond :** la **grille par défaut** s'applique. Si elle est vide, c'est le **tarif de base**, multiplié par le nombre de départs.
- **Aperçu :** le cadre « Aperçu » calcule le prix d'une catégorie, d'un club et d'un nombre de départs sur la saisie en cours, avant même l'enregistrement.
- **Mention en pied de reçu :** texte libre imprimé en bas des reçus et récapitulatifs (cadre « Reçus »). Vide par
  défaut. Le bouton « Insérer la mention habituelle des associations » propose « Association loi 1901 — TVA non
  applicable, article 293 B du Code général des impôts » : ne l'utilisez que si elle s'applique à votre club.

## 2. Paiements (Modules → Greffe → Paiements)

Une ligne par archer, identifié par sa **licence** (un archer sans licence est repéré par son numéro d'inscription IANSEO). Le nombre de départs est le nombre d'inscriptions de cette licence dans la compétition.

- **Payer :** choisir le moyen (Espèces, Chèque, Carte bancaire, Virement, Exonéré), éventuellement une note, puis cliquer. Pour un chèque, la note sert au **numéro de chèque** : elle reste facultative, mais elle alimente l'état des chèques. Le paiement porte toujours sur la totalité du montant dû à cet instant.
- **Annuler :** le compte repasse à « non payé ». Le paiement annulé reste dans l'historique.
- **Payé — écart :** le montant dû a changé après le paiement (départ ajouté ou retiré, tarifs modifiés). « Régulariser » réenregistre le paiement au nouveau montant, avec le même moyen.
- **Règlement d'un club en une fois :** tous les archers non payés du club passent à payé avec le même moyen.
- **Filtres :** club, règle, statut, catégories mixtes, archers sans règle, **départ** (archers inscrits à ce départ,
  ou non placés), **nombre de départs**, plus une recherche par nom, licence ou club.
- **Tri :** un clic sur l'en-tête d'une colonne trie la liste (archer, club, départ, catégorie, règle, dû, statut,
  date du paiement) ; un second clic inverse l'ordre. Le tri et les filtres restent en place après chaque paiement.
- **Reçu / Récap. :** sous le montant de chaque archer, ce lien ouvre son reçu PDF.
- **Liste papier du greffe (PDF)** : cadre « Liste papier du greffe », bouton « Éditer la liste ». Une ligne par archer :
  licence, club, départ(s), catégorie, **montant dû**, **reste à régler** (ou « Réglé », « Exonéré »), et une colonne
  **Pointage** vide pour noter le règlement à la main (elle indique le moyen et la date d'un paiement déjà enregistré).
  Total en bas de chaque liste. Choix :
  - **Tous les départs confondus**, **Par départ** (une page par départ, puis les archers non placés) ou **un seul départ** ;
  - **Tous les archers** ou **seulement ceux qui doivent encore** ;
  - **tri par nom** ou **par club**.

  **Par départ, chaque archer figure au départ où il règle : son premier départ.** Les totaux des départs
  s'additionnent donc sans doublon. Sur la feuille de ses départs suivants, il est rappelé dans un encadré « Aussi à ce
  départ, déjà attendus à un départ précédent », pour ne pas le faire payer deux fois. La liste montre l'état au moment
  de l'édition : les paiements enregistrés ensuite n'y figurent pas.

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

## 3. Rapport - Bilan (Modules → Greffe → Rapport - Bilan)

- **Synthèse :** archers, inscriptions, total dû, encaissé, exonéré, reste à encaisser, et trop-perçu s'il y en a. On a toujours **dû = encaissé + exonéré + reste − trop-perçu**.
- **Ventilations :** par moyen de paiement, par club, par règle, par nombre de départs et par départ (session).
- **Listes de contrôle :** impayés, comptes en écart, archers sans règle, catégories mixtes. Un clic sur une ligne ouvre la page des paiements filtrée.
- **Exports :**
  - **Bilan financier (PDF)** : état d'une page avec le résumé (dû, encaissé, exonéré, reste), les encaissements par moyen de paiement (tous les moyens, même à 0 €) et le nombre d'impayés par départ ;
  - **État complet (PDF)** ;
  - **CSV par archer** et **CSV par club** : séparateur `;` et accents compatibles Excel ;
  - **Récapitulatifs clubs (PDF)** : un par club ;
  - **État des chèques (PDF)** : bordereau de remise en banque, avec une ligne par chèque (numéro, date, club, archers, montant), le total et une zone « remis en banque le ». Un chèque de club réglé en une fois tient sur une seule ligne : regroupement par numéro, ou, sans numéro, par club et heure d'enregistrement.

## 4. Reçus

Ce sont des **reçus, pas des factures** : il n'y a pas de numérotation légale.

- **Archer réglé :** « Reçu d'inscription » avec le moyen, la date et un cadre pour le cachet de l'organisateur.
- **Archer non réglé :** « Récapitulatif d'inscription » avec le reste dû.
- **Club :** liste de ses archers, montants, statuts et bilan.

La mention saisie sur la page Tarifs (cadre « Reçus ») s'imprime en bas de chacun.

## 5. Sauvegarde (Modules → Greffe → Sauvegarde)

Les tarifs et paiements **ne sont pas dans l'export de compétition d'IANSEO** : cette page les met dans **deux
fichiers `.json` séparés**, à garder avec l'export `.ianseo`, ou à importer sur un autre serveur (par exemple d'un
serveur de test vers celui de la compétition).

- **Télécharger les tarifs :** règles, grilles, tarif de base, mention de reçu. Réutilisable d'une compétition à
  l'autre (mêmes tarifs chaque année, par exemple).
- **Télécharger l'historique :** **toutes les lignes** de paiement de la compétition, annulations comprises, avec
  leurs dates et auteurs.
- **Restaurer ou importer :** envoyer un fichier (tarifs ou historique : le module reconnaît lequel) affiche
  d'abord un **aperçu** ; rien n'est écrit avant « Importer ».
  - Les paiements sont rattachés par **licence**. Sans licence : par nom, prénom et club, s'il n'y a qu'une
    inscription qui correspond.
  - Un archer qui a **déjà des paiements** dans la compétition n'est pas modifié ; il est listé pour vérification.
  - Un archer **introuvable** (licence non inscrite ici) ou **ambigu** n'est pas importé ; il est listé.
  - **Rejouer un import n'ajoute rien :** les lignes déjà présentes sont ignorées.
  - Fichier de tarifs : il **remplace** les tarifs de la compétition. Avant, l'aperçu signale ce qui ne correspond
    pas à la compétition : moins de départs, divisions, classes ou clubs absents, club organisateur différent.

## Droits d'accès

- **Tarifs, Paiements et Sauvegarde :** droit IANSEO « Participants » en **écriture**.
- **Rapport - Bilan et reçus :** même droit, en **lecture**.

---

[← Retour au README](../README.md)
