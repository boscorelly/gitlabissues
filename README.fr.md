# GitLab Issues – plugin GLPI 11

[English: README.md](README.md)

Crée une issue GitLab depuis un ticket GLPI, puis **vérifie toutes les 5 minutes** (action automatique GLPI)
si l'issue a été fermée. À la fermeture, le plugin agit sur le ticket **via les objets GLPI** (suivi, solution) :
historique, règles métier, notifications et autres actions automatiques (clôture automatique des tickets
résolus, enquêtes de satisfaction…) se déclenchent comme pour une action manuelle.

Compatible **GLPI 11.0.x** (testé sur 11.0.11, PHP 8.3, MariaDB 10.11). Licence AGPL-3.0-or-later.

## Installation

1. Copier le dossier `gitlabissues` dans `plugins/` (ou `marketplace/`) de GLPI.
2. *Configuration > Plugins* : installer puis activer. L'installation :
   - crée la table `glpi_plugin_gitlabissues_links` ;
   - enregistre l'action automatique **CheckIssues** (toutes les 5 min) ;
   - donne le droit « GitLab Issues » aux profils qui peuvent déjà modifier les tickets.
3. **Cron système** : l'action automatique est en mode « CLI ». Vérifiez que le cron de GLPI tourne
   (`* * * * * php /chemin/glpi/front/cron.php`). Sans cron système, passez l'action en mode « GLPI »
   dans *Configuration > Actions automatiques > CheckIssues* (moins fiable).
4. *Configuration > Plugins > GitLab Issues* : URL de GitLab, jeton, projet par défaut, comportement
   à la fermeture. Le bouton « Enregistrer et tester la connexion » vérifie l'URL, le jeton et ses périmètres.

### Jeton GitLab
Un jeton de **projet ou de groupe** (ou personnel) avec le périmètre **`api`** et le rôle **Reporter** ou plus.
Il est stocké **chiffré** (clé `glpicrypt.key` de GLPI) et n'est jamais renvoyé au navigateur.
Si vous changez la clé de GLPI, ressaisissez le jeton.

## Utilisation

- Onglet **Issues GitLab** d'un ticket : choisir le projet (recherche), éventuellement des labels
  supplémentaires, **Créer une issue GitLab**.
- L'issue contient : titre (`[GLPI #12] …`, modifiable), lien vers le ticket, tableau d'informations
  (statut, priorité, urgence, impact, catégorie, entité, demandeurs/attributaires), description convertie
  en Markdown, **images en ligne et pièces jointes envoyées dans le projet GitLab**.
- Un suivi « issue créée » est ajouté au ticket. Sa visibilité se choisit **à chaque création** (privé, public ou aucun)
  grâce au sélecteur de l'onglet ; la valeur proposée par défaut se règle dans la configuration (suivi activé, privé ou public).
- Un ticket peut avoir plusieurs issues (projets différents). Un doublon dans le même projet est refusé
  sauf case cochée.

### À la fermeture de l'issue
Configurable : suivi (public ou privé) avec la personne qui a fermé, la date et les merge requests de clôture ;
et/ou **résolution du ticket** (solution avec type et texte personnalisables : `{issue}`, `{issue_url}`,
`{closed_by}`, `{closed_at}`, `{ticket_id}`). L'auteur est un utilisateur choisi, ou celui qui a créé l'issue.

Cas particuliers :
| Situation | Comportement |
|---|---|
| Issue **déplacée** vers un autre projet | Pas de résolution du ticket ; suivi privé d'information ; l'issue n'est plus suivie |
| Issue **introuvable** (supprimée, accès retiré) | Abandon après 3 vérifications consécutives, suivi privé d'information |
| Ticket déjà **clos** / en corbeille | Le lien passe à « fermée », le ticket n'est pas touché |
| GitLab **injoignable**, jeton refusé, limite de requêtes | L'exécution s'arrête proprement, l'erreur est affichée dans l'onglet, nouvelle tentative au passage suivant |
| Échec pendant les actions sur le ticket | **Annulation complète** (transaction) : l'issue reste « ouverte » et tout est rejoué au passage suivant, sans doublon |

## Informer le demandeur (non-techniciens)
Le demandeur ne voit jamais l'onglet « Issues GitLab » : par défaut, il ne sait rien de l'issue. Deux options (*Configuration > Plugins > Issues GitLab* : dans la section « Création d'issue » et dans la section « Quand l'issue est fermée sur GitLab »)
ajoutent des **suivis publics neutres**, sans aucun détail GitLab (ni projet, ni lien, ni développeur, ni merge request) :
- à la création d'une issue (le technicien peut décocher « Informer le demandeur » pour une issue donnée) : par défaut *« Votre demande a été prise en compte par l'équipe technique et transmise aux personnes en charge du développement. Vous serez informé(e) de la suite. »*
- à la fermeture de l'issue, un seul choix : rien, un suivi public, ou **le ticket est résolu** avec ce message comme solution : *« Le traitement technique lié à votre demande est terminé. »*

Les deux textes sont modifiables. Les notifications GLPI s'appliquent aux suivis publics et aux solutions. Les détails techniques (qui a fermé l'issue, quand, merge requests) restent dans les suivis **privés**.

## Langues
Anglais (`en_GB`, `en_US`) et français (`fr_FR`). Le plugin utilise **la langue de chaque utilisateur si une traduction existe, sinon l'anglais**
(GLPI seul se rabattrait sur la langue par défaut de l'instance). Les messages neutres écrits dans le ticket pour le demandeur suivent la même règle (langue de l'interface de la personne qui les déclenche ; langue par défaut de l'instance pour l'action automatique).
Pour ajouter une langue : copier `locales/gitlabissues.pot` en `locales/<xx_XX>.po`, le traduire, lancer `python3 tools/locales.py` depuis le dépôt git (nécessite `msgfmt`), vider le cache de GLPI. Contributions bienvenues.

## Droits
Onglet **Issues GitLab** d'un profil (*Administration > Profils > (profil)*, dernier onglet de la page) ou tableau **Droits par profil** de la page de configuration du plugin : lecture, création d'issue, actualisation, déliaison.
Les droits sont cumulatifs : **la lecture est requise** pour les autres (cocher un droit accorde aussi la lecture), et les profils
de l'interface self-service ne peuvent jamais utiliser le plugin. La page de configuration nécessite le droit GLPI
*Configuration > Mettre à jour*. Les droits sont vérifiés **côté serveur** à chaque action, pas seulement en masquant les boutons ;
les formulaires sont protégés par le CSRF de GLPI.

| Droit | Permet |
|---|---|
| Lecture | Voir l'onglet « Issues GitLab » d'un ticket et le statut des issues liées |
| Créer une issue | Rechercher un projet et créer une issue (nécessite aussi l'accès au ticket) |
| Actualiser le statut | Bouton « Actualiser » |
| Délier | Bouton « Délier » |

Les profils qui peuvent déjà modifier les tickets reçoivent les quatre droits à la première installation ; ajustez-les dans le profil.

## Mise à jour du plugin
Aucun dépôt n'est nécessaire : GLPI compare la version écrite dans `setup.php` à celle qu'il a en base.
1. Remplacez le dossier `plugins/gitlabissues` par la nouvelle version.
2. Videz le cache (`php bin/console cache:clear` ou le contenu de `files/_cache/`) : GLPI garde les templates compilés.
3. *Configuration > Plugins* : le plugin apparaît **désactivé, « à mettre à jour »**. Cliquez sur *Mettre à jour*, puis *Activer*.
   (En ligne de commande : `php bin/console plugin:install gitlabissues --username=<admin>` puis `plugin:activate gitlabissues`.)

La mise à jour rejoue l'installation de façon sûre : elle conserve vos réglages, le jeton, les liens ticket/issue existants,
l'action automatique et les droits que vous avez choisis par profil, et n'ajoute que ce qui manque (nouvelles options, nouvelles colonnes).
Une mise à jour **automatique** depuis GLPI (Marketplace) ne serait possible qu'en publiant le plugin dans le catalogue des plugins GLPI.

## Limites connues
- Seul le sens ticket → issue est créé. Les commentaires ne sont pas synchronisés.
- Une issue **rouverte après sa fermeture** n'est plus suivie (le ticket a déjà été traité).
- Les pièces jointes des suivis du ticket ne sont pas copiées (seulement celles du ticket et les images de sa description).
- La conversion HTML → Markdown couvre ce que produit l'éditeur GLPI (titres, listes, tableaux, liens, images, code, citations).
- Le plugin interroge GitLab (pas de webhook) : le délai de détection est de ~5 minutes.

## Tests (dossier `tests/`)
- `html_to_markdown_test.php` : tests unitaires de la conversion (sans GLPI).
- `mock_gitlab.py` : faux serveur GitLab (port 8099).
- `integration.php` : bout en bout sur un vrai GLPI (CLI) : création d'issue, cron réel (`CronTask::launch`), tous les cas ci-dessus.
- `http_test.py` : application web réelle (connexion, onglet, configuration, création, droits, CSRF).
- `run_all.sh` : enchaîne le tout.

Dernier résultat : 20 tests unitaires, 53 d'intégration, 29 HTTP — tous réussis, plus un scénario de mise à jour (`upgrade_test.sh`).
**Non testé** : un vrai serveur GitLab (seulement le mock de l'API v4), et le JavaScript des listes de recherche
(select2) dans un vrai navigateur.
