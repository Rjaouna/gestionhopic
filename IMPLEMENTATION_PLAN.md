# État de réalisation

## Disponible

- Authentification Symfony, deux espaces, contrôle d’accès aux demandes et aux pièces jointes, protection CSRF et limitation des tentatives.
- Tableau de bord RH, indicateurs et graphique issus de SQLite, planning hebdomadaire filtrable, liste de demandes et pagination.
- Espace collaborateur responsive, cartes de soldes, navigation mobile, calendrier, demandes, profil et notifications.
- Création, acceptation, refus et annulation de demande avec motif facultatif. Calcul par demi-journée sur jours travaillés, hors jours fériés, avec contrôle des chevauchements.
- Ajustement des droits avec motif facultatif, prévisualisation, historique paginé et écritures atomiques SQLite.
- Gestion des collaborateurs, services, types d’absence et de compteurs, horaires et jours fériés. Journal d’activité, export CSV et sauvegarde SQLite.
- Fixtures facultatives pour 24 collaborateurs, six services, demandes et mouvements de soldes.

## À compléter pour couvrir le cahier des charges intégral

- Invitation et réinitialisation par e-mail. Réglages d’entreprise, présentation personnalisée et préférences de notification.
- Modification d’une demande existante, demande d’annulation d’un congé accepté et traitement groupé. Calcul horaire et règles avancées des types d’absence.
- Attribution collective, renouvellement et report automatique des compteurs. Import Excel et exports Excel/PDF.
- Analyses détaillées par service et type d’absence, absences simultanées, présentations par mois et par équipe, tableaux de suivi complémentaires.
- Rétention, anonymisation, gestion documentaire avancée et validation d’exploitation avant déploiement.
