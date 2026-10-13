# Installation, mise à jour et désinstallation

Lisez d'abord [À lire avant d'utiliser le module](AVERTISSEMENT.md) : module indépendant d'IANSEO, sans garantie,
sauvegardes.

Il faut une installation IANSEO qui fonctionne (PHP 8, MySQL ou MariaDB).

Le module doit se trouver dans `Modules/Custom/ENGAGEMENT`. Le nom du dossier, `ENGAGEMENT`, compte : les liens du module l'utilisent. `Modules/Custom/` est le seul dossier que les mises à jour d'IANSEO ne réécrivent pas.

## Windows

1. Télécharger `ENGAGEMENT-vX.Y.Z.zip` dans **Releases**. Le dossier y est déjà nommé `ENGAGEMENT`.
2. Le décompresser dans `C:\ianseo\htdocs\Modules\Custom\`, ce qui donne `C:\ianseo\htdocs\Modules\Custom\ENGAGEMENT\menu.php`.

Le bouton **Code → Download ZIP** convient aussi, à condition de renommer en `ENGAGEMENT` le dossier `GreffeModuleIanseo-main` obtenu.

## Linux (ligne de commande)

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

## Premier lancement

Ouvrir une compétition dans IANSEO : le menu **Modules → Greffe** apparaît. Les onglets Tarifs · Paiements · Rapport - Bilan permettent ensuite de passer d'une page à l'autre. Les tables du module se créent toutes seules à l'ouverture de la première page.

## Mise à jour

**Depuis IANSEO** (à partir de la version 1.1.0) : **Modules → Greffe → Mise à jour du module**, réservé à l'administrateur.
1. **Vérifier les mises à jour** compare la version installée à celle de GitHub et affiche les nouveautés.
2. **Mettre à jour** télécharge et remplace les fichiers du module. Tous les fichiers sont récupérés avant d'en remplacer un seul : en cas d'échec, rien n'est modifié.

Le serveur web doit pouvoir écrire dans le dossier `ENGAGEMENT` (sous Linux : `chown www-data`, voir l'installation). La source est indiquée dans `module.json`, que les mises à jour ne remplacent jamais.

**Autres cas :**
- **Installé avec `git clone` :** `cd /var/www/html/ianseo/Modules/Custom/ENGAGEMENT && sudo git pull`. La page d'IANSEO refuse les dossiers gérés par Git, pour ne pas les désynchroniser.
- **Depuis une version 1.0.x,** qui n'a pas encore cette page : une seule fois, remplacer le contenu du dossier `ENGAGEMENT` par celui de la nouvelle version.

Les tarifs et les paiements, enregistrés en base, sont toujours conservés.

## Désinstallation

**Modules → Greffe → Mise à jour du module → Désinstaller le module** (administrateur). L'écran de confirmation :
- indique ce qui sera supprimé : le dossier du module et, **seulement si vous cochez la case**, ses deux tables (tarifs et paiements de toutes les compétitions) ;
- propose de **télécharger une sauvegarde** des tables (.sql) avant de les supprimer ;
- demande de taper le nom du module (`ENGAGEMENT`) pour confirmer.

Les tables d'IANSEO ne sont jamais touchées. Si vous conservez les tables, une réinstallation retrouve les tarifs et les paiements.

---

[← Retour au README](../README.md)
