# Nouveautés de chaque version

## 1.3.2

- **Mise à jour du module sous Windows.** La mise à jour depuis IANSEO s'arrêtait sur « Écriture impossible :
  admin/update.php » : Windows refuse de remplacer la page en cours d'affichage. Le module écrit maintenant
  directement dans ce fichier. Pour passer à cette version depuis une version précédente sous Windows, une seule
  fois : décompresser le ZIP de la Release dans `Modules/Custom` en écrasant les fichiers ; les tarifs et paiements, en base, sont gardés.

## 1.3.1

- **Liste papier du greffe** (page Paiements, bouton « Éditer la liste ») : PDF des archers avec le montant dû, le reste
  à régler et une colonne de pointage à remplir à la main. Tous départs confondus, par départ ou pour un seul départ ;
  tous les archers ou seulement ceux qui doivent ; tri par nom ou par club. Par départ, chaque archer figure à son
  premier départ, où il règle, et il est rappelé sur les feuilles de ses départs suivants.

## 1.3.0

- **Tarifs — critère « Clubs »** : une règle peut viser tous les clubs, le **club organisateur** de la compétition,
  les autres clubs, ou des clubs choisis. Exemple : un tarif réduit pour les archers du club organisateur. L'aperçu
  calcule aussi le prix selon le club. Les règles existantes valent « tous les clubs ».
- **Mention en pied de reçu** (page Tarifs, cadre « Reçus ») : texte libre imprimé sur les reçus et récapitulatifs,
  vide par défaut, avec la mention habituelle des associations (article 293 B du CGI) proposée en un clic.
- **Paiements** : tri par colonne (clic sur l'en-tête), filtres par départ et par nombre de départs. Tri et filtres
  restent en place après chaque paiement.
- **Nouvelle page Sauvegarde** : deux fichiers `.json` séparés, les **tarifs** et l'**historique complet des
  paiements**, à garder avec l'export de la compétition ou à importer sur un autre serveur (test vers compétition). Import avec aperçu,
  rattachement par licence, aucun archer déjà pointé n'est modifié, un import rejoué n'ajoute rien.
- **Thème** : les pages suivent la palette et le mode sombre du module THEME d'IANSEO.

## 1.2.2

- **Mise à jour du module** : seules les nouveautés de la version disponible s'affichent, avec un lien
  « Toutes les nouveautés » vers cette page.

## 1.2.1

- **À lire avant d'utiliser le module** ([AVERTISSEMENT.md](AVERTISSEMENT.md)) : le module est indépendant d'IANSEO
  (non développé par l'équipe IANSEO), sans garantie ni SAV ; sauvegarder les tarifs et paiements, qui ne sont pas dans
  l'export de compétition d'IANSEO ; licences d'IANSEO et du module.
- **Page Tarifs** : rappel de cet avertissement (avec le lien de sauvegarde pour l'administrateur) et lien vers le mode
  d'emploi.
- **Documentation** réorganisée : README court, détails dans `docs/` (installation, utilisation, données, nouveautés).
- **Mise à jour du module** : les fichiers sont téléchargés depuis la version publiée. Une mise à jour lancée juste
  après une publication pouvait recevoir des fichiers de la version précédente.

## 1.2.0

- **Désinstallation depuis IANSEO** : suppression des tables au choix, sauvegarde SQL, confirmation par le nom du module.

## 1.1.0

- **Mise à jour du module depuis IANSEO** (Modules → Greffe → Mise à jour du module).

## 1.0.1

- Libellés « Greffe » et « Rapport - Bilan ».

## 1.0.0

Première version : frais d'inscription avec tarifs, paiements, rapport et bilan, reçus PDF.

---

[← Retour au README](../README.md)
