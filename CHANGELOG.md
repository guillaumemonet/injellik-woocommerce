# Changelog

## [1.0.0] - 2026-11-06

Version initiale, portée du [module PrestaShop](https://github.com/guillaumemonet/injellik-prestashop) 2.1.0.

### Ajouté
- Connexion par clé de licence (`ijk_lic_…`, en-tête `X-Api-Key`), ou par identifiant et mot de passe pour les comptes créés avant les abonnements (JWT en cache).
- Assistant d'installation : connexion, catalogue, historique de commandes, blocs.
- Page de suivi : état de l'API, activité sur 30 jours, affichages, clics et taux de clic par bloc, file d'événements, classements, derniers événements.
- Page de réglages : connexion, blocs, données, intégration, réglages avancés.
- Emplacements : fiche produit, panier (classique et bloc Panier), page de remerciement, boutique et catégories, page d'accueil ; sept stratégies avec stratégie de repli.
- Bloc de l'éditeur « Recommandations Injellik », shortcode `[injellik_recommendations]`, fonction `injellik_recommendations()` et action `injellik_recommendations`.
- Chargement asynchrone des blocs, compatible avec les caches de page, et événements JS `injellik:loaded` / `injellik_loaded`.
- Suivi `VIEW` et `CLICK` par le navigateur : les pages restent sans cookie, donc cachables.
- Suivi `ADDCART`, `BUY` (une fois par commande, rattaché à la visite du paiement même après une notification de paiement) et `RATED` (avis produit avec note).
- File d'événements locale, envoi après la réponse par lots (`POST /api/actions/batch`, repli sur l'envoi unitaire), réessais.
- Abonnement suspendu ou clé invalide : blocs masqués, événements conservés sans consommer leurs essais, nouvel essai toutes les 15 minutes ; alerte sur les pages de l'extension et rappel sur les autres pages de l'administration.
- Synchronisation automatique des produits (profil : catégories, étiquettes, marque, prix) et synchronisation complète chaque nuit.
- Import de l'historique des commandes payées avec leur date réelle (`occurred_at`) et `source: import`, non facturé ; les commandes déjà suivies en direct ne sont pas renvoyées.
- WP-Cron (envoi toutes les 10 minutes, catalogue chaque nuit) et URL de cron REST à jeton.
- Commandes WP-CLI : `status`, `flush`, `sync-catalog`, `import-orders`.
- Compatibilité HPOS, blocs Panier / Commande, thèmes blocs, multisite.
- Texte pour la politique de confidentialité et effacement des données personnelles (`DELETE /api/users/{uuid}`).
- Traduction française.
