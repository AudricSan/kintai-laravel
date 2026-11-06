# Changelog

## 1.0.0

Reconstruction en package Laravel natif à partir du bundle `planning-check` de l'ancien framework : pages Livewire + Flux, droits avec portée magasin et droits par défaut du rôle `manager` (contrat 1.2), fonctionnalité de magasin `planning_check`, notifications (`Notifier`), journal (`ActivityRecorder`), tâche planifiée `kintai:planning-check-escalate`, API `/api/v1/planning-check`.

Écarts :
- La fenêtre modale de l'ancien framework (« Les shifts de demain sont-ils corrects ? », mise en retrait de 45 minutes quand on modifie le planning, « plus tard ») est remplacée par une page `/planning-check` avec l'état de demain, l'aperçu des shifts et les actions ; le lien « Non, modifier le planning » mène à la timeline des shifts.
- Le fuseau est celui de l'instance (`kintai.timezone`) et non plus celui du magasin.
- Les réglages quittent le stockage de l'ancien framework pour la table `planning_check_settings` du bundle ; l'interrupteur d'activation s'ajoute à la fonctionnalité de magasin du Core.
- Les adresses e-mail saisies à la main disparaissent : les destinataires du PDF sont des employés actifs du magasin.
- Le PDF est servi directement par une route (généré à la demande, rien n'est stocké).
- Nouveaux : pastille du menu, entrée dans la page « Demandes », widget « Planning de demain », API mobile, journal des envois (`planning_check_sends`).
