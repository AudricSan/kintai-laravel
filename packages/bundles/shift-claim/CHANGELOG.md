# Changelog

## 1.0.0

Reconstruction en package Laravel natif à partir du bundle `shift-claim` 3.0.0 de l'ancien framework : pages Livewire + Flux, droits avec portée magasin et droits par défaut du rôle `manager` (contrat 1.2), `PendingRequestProvider`, notifications (`Notifier`), journal, API `/api/v1/shift-claim` (ancienne URL `/api/v1/shift-claims`). Exige le contrat 1.3 (`ShiftWarnings`).

Écarts :
- le Core n'a plus de drapeau `is_open` : un shift ouvert est un shift sans employé et il est d'office dans la bourse ; « publier » un shift attribué devient une proposition (table `shift_claim_offers`) ; la section « Shift ouvert » du formulaire de shift n'existe plus (le Core crée les shifts ouverts lui-même) ;
- l'attribution passe par le remplacement de shifts du Core (l'ancien shift part à la corbeille, un nouveau shift porte le candidat) avec les garde-fous du planning et leur confirmation ; l'ancien titulaire est prévenu par les notifications de planning du Core (le type `shift_claim_handed_over` disparaît) ;
- une candidature retirée ou rejetée peut être redéposée (elle repasse en attente) ;
- la bourse ne liste que les shifts à venir ; le congé approuvé empêche de candidater ;
- l'annonce d'un shift ouvert (`open_shift_published`) part à la création ou quand un shift devient ouvert, plus par un bouton « publier » ;
- l'API n'expose plus de `PUT status` : les décisions sont des actions (`withdraw`, `approve`, `reject`) et `offer` / `DELETE offer` gèrent les propositions.
