# Cartographie projet – TMS sur mesure

Nov 9, 2026 · @Sebastien

Cartographie cible du TMS à développer : tout le périmètre fonctionnel relevé dans Formdev, complété par le référentiel Qualiopi 2026, un vrai CRM, le lien LMS et les intégrations. Toutes les fonctions inactives ou grisées dans la démo Formdev sont ici spécifiées comme actives.

## 1. Vision et principes

Le TMS gère tout le cycle d'un organisme de formation, de la prospection au BPF, avec Qualiopi intégré à chaque écran plutôt qu'ajouté à côté.

**Périmètre** : organismes de formation, formateurs indépendants et CFA ; actions de formation, bilans de compétences, VAE, apprentissage ; présentiel, distanciel, blended et FOAD ; mono ou multi-société.

**Publics** : direction, gestionnaires administratifs, commerciaux, responsable qualité, formateurs internes et externes, apprenants, entreprises clientes, financeurs (OPCO, France Travail, CPF), auditeur Qualiopi.

**Principes de conception**

- **Tout actif** : aucune fonction en mode démo ou grisée ; ce qui était verrouillé dans l'essai Formdev est spécifié complet (section 13).
- **Qualiopi natif** : chaque donnée saisie alimente une preuve ; chaque indicateur a ses mesures automatiques et son dossier de preuves.
- **Référentiels versionnés** : indicateurs Qualiopi, guide de lecture, modèles de documents, programmes et champs de fusion portent une version et une date d'effet.
- **Une seule saisie** : données entreprise récupérées par SIRET, statut Qualiopi des sous-traitants par données ouvertes, codes RNCP par référentiel officiel.
- **Automatisation par évènements** : rappels, mails, SMS, quiz et questionnaires suivent un cycle de vie paramétrable.
- **Auto-hébergeable** : déploiement possible sur ton infrastructure (Proxmox, Nextcloud pour l'archivage, authentik pour l'authentification), IA locale optionnelle.

## 2. Carte des modules

Le TMS compte 16 modules, regroupés en 5 familles. Les modules marqués ★ n'existent pas ou sont inactifs dans Formdev.

| Famille | Module | Écrans principaux |
| --- | --- | --- |
| Accueil | Centre de contrôle | Alertes « À traiter » (23 types), widgets personnalisables, synthèse Qualiopi, agenda du jour |
| Commercial | CRM ★ | Prospects, pipeline d'opportunités, historique des échanges, campagnes |
| Commercial | Devis | Liste, création (lignes programmes / sessions / produits annexes), relances, transformation en dossier |
| Opérationnel | Sessions | Liste, centre de contrôle, fiche (16 sous-écrans : participants, intervenants, agenda, dossiers, signatures, présences, finance, pédagogie, qualité, archives) |
| Opérationnel | Dossiers | Contractant, participants, financements multi-financeurs, produits annexes, signatures, finance, archives |
| Opérationnel | Organisations | Entreprises, financeurs, OPCO ; contacts avec référent ; dossiers liés et financés ; finance ; qualité |
| Opérationnel | Apprenants | État civil, contact, handicap, statut emploi, ID France Travail, parcours, résultats, extranet |
| Opérationnel | Plannings | Général, intervenants, ressources, sessions, indisponibilités ; planification par glisser-déposer ; export |
| Référentiel | Catalogue | Programmes (contenu Qualiopi, versions), modules pédagogiques, produits annexes, familles, certifications RNCP/RS |
| Référentiel | Ressources | Intervenants (qualifications datées, contrats), ressources matérielles (familles, capacité, échéances), indisponibilités |
| Référentiel | Archives (GED) | Documents, mails, SMS générés ; familles de documents ; versionnage |
| Pédagogie | Évaluations ★ | Quiz enrichis, grilles de compétences, positionnement, notes pédagogiques, certificats |
| Pédagogie | Connecteur LMS ★ | Modules distanciels, suivi des temps de connexion et de la progression, remontée des résultats |
| Gestion | Finance | Factures, échéances, règlements, remises en banque, relances, charges, export comptable, paiement en ligne |
| Gestion | Qualité | Référentiel Qualiopi, preuves, enquêtes, objectifs, réclamations ★, veille ★, amélioration continue ★ |
| Gestion | Pilotage | BPF, tableaux de bord (production, direction, commercial, qualité), NF203, RGPD |

Transverses à toutes les fiches : notes et rappels, mails et SMS programmés, signatures électroniques, archives, champs personnalisés, analytique, sectorisation, utilisateur responsable, export Excel, filtres enregistrés.

**Configuration** : sociétés, lieux, finance (banques, plan comptable, analytique), modèles (documents, questionnaires, quiz, mails, SMS), champs de fusion, compétences, rappels planifiés, champs personnalisés, portails, connecteurs, utilisateurs et droits.

## 3. Modèle de données

Le pivot est le **Dossier** : il relie un contractant, des participants inscrits à des sessions, et un ou plusieurs financements qui déterminent la facturation.

| Entité | Champs clés | Relations |
| --- | --- | --- |
| Société | Raison sociale, SIRET, NDA, UAI, n° et date Qualiopi, types de certification, conditions de paiement, numérotation, logos, cachet | 1..n lieux, banques, exercices comptables ; porte tout le reste |
| Programme | Code, intitulé, version, CPF/RNCP/RS, tarif, heures, jours, autonomie, objectifs, public, prérequis, moyens, accessibilité, évaluation, sanctions, NSF, nature | 1..n modules, compétences, modèles (mails, quiz, questionnaires), sessions |
| Module | Intitulé, durée, modalité (présentiel / distanciel / LMS), ressources requises | Appartient à un programme ; planifié en créneaux |
| Session | Référence, type (inter, intra, FOAD), dates, lieux, places, blended, état, responsable | Programme ; participants ; intervenants ; créneaux ; dossiers ; campagnes |
| Créneau (agenda) | Date, heures, module, salle, intervenant | Session ; émargements |
| Organisation | Type (entreprise, OPCO, financeur, sous-traitant), SIRET, NAF, TVA, compte comptable, modèle de facture | Contacts ; dossiers ; factures ; opportunités |
| Contact | Nom, fonction, coordonnées, référent | Organisation ; opportunités ; échanges CRM |
| Apprenant | État civil, contact, handicap, statut emploi, contrat, ID France Travail, représentant légal | Participations ; résultats ; compte portail |
| Participation (inscription) | Session, dates d'entrée et sortie, heures, prix, catégorie BPF, convoqué, financé, validation | Apprenant × session × dossier |
| Dossier | Numéro, contractant, prix formation, prix financé, retourné signé, état, soldé | Participations ; financements ; produits annexes ; factures |
| Financement | Financeur, montant HT, accord, n° de prise en charge, échéancier | Dossier × organisation financeur |
| Intervenant | Type (salarié, vacataire, sous-traitant), NDA, qualifications datées, coût horaire, lien classe virtuelle | Sessions ; créneaux ; charges ; contrats de mission |
| Ressource matérielle | Famille, capacité, coût, échéance | Créneaux ; charges |
| Opportunité / Devis | Étape, montant, probabilité, validité, motif de perte, lignes | Prospect / organisation ; devient dossier |
| Facture / Échéance / Règlement / Remise | Numéros, montants, états, types de règlement | Dossier × financeur ; banque |
| Charge | Fournisseur, montants, type (intervenant, ressource, exceptionnelle) | Session |
| Campagne d'évaluation | Type (quiz, questionnaire), modèle, cycle de vie, taux | Session ; module ; destinataires ; réponses |
| Indicateur Qualiopi | Version du référentiel, n°, critère, texte, applicabilité, statut, responsable | Mesures automatiques ; preuves |
| Preuve | Type, fichier, lien, date, auteur, période | Indicateur ; objet source (session, programme…) |
| Document généré | Modèle, version, objet source, statut de signature | Archives ; signature |

Transverses : Note/Rappel, Message (mail, SMS), Champ personnalisé, Code analytique, Utilisateur, Journal d'audit.

## 4. Flux métier de bout en bout

Un parcours complet traverse 10 étapes ; chaque étape produit des preuves Qualiopi sans saisie supplémentaire.

1. **Offre** – programme créé et versionné au catalogue, publié sur le site (API, WordPress, annonceurs). *Preuves : indicateurs 1, 5, 6.*
2. **Prospection** – prospect → opportunité → échanges tracés → devis avec programme joint, relances automatiques.
3. **Analyse du besoin et positionnement** – questionnaire ou quiz envoyé avant l'entrée, recueil handicap et attentes. *Indicateurs 4, 8, 26.*
4. **Contractualisation** – devis gagné → dossier → financements (OPCO, CPF, France Travail, entreprise) → convention ou contrat → signature électronique.
5. **Inscription** – assistant en 5 étapes (session, apprenant, catégorie BPF, informations, détails), pré-réservation ou liste d'attente si complet.
6. **Préparation** – agenda (module × créneau × intervenant qualifié × salle), contrôle des qualifications et indisponibilités, convocations mail et SMS, accès portail et LMS. *Indicateurs 9, 17 à 22.*
7. **Réalisation** – émargement numérique ou papier par créneau, suivi distanciel via LMS, notes pédagogiques, gestion des absences et abandons. *Indicateurs 10 à 12, 19.*
8. **Évaluation** – quiz, grilles de compétences, validation finale, certificats ; questionnaires à chaud, à froid, financeur, intervenant ; évaluation des enseignements par les apprentis (CFA). *Indicateurs 11, 30, 33.*
9. **Clôture** – attestations, certificat de réalisation, relevé de compétences ; facturation par échéance et par financeur ; règlements, remises, relances ; export comptable.
10. **Amélioration** – réclamations traitées, analyse des résultats, plan d'actions, analyse de risques ; BPF annuel ; tableaux de bord. *Indicateurs 2, 31, 32.*

## 5. Qualité Qualiopi

Le module couvre les 33 indicateurs du référentiel en vigueur depuis le 1er novembre 2026 (décret n° 2026-728 du 1er août 2026), tous actifs, avec une mesure automatique ou un registre dédié pour chacun.

**Socle**

- **Référentiel versionné** : table des indicateurs par version (2019 à 32 indicateurs, 2026 à 33), version du guide de lecture (V9 en vigueur, V10 annoncée), date d'effet ; bascule de version sans perte d'historique.
- **Applicabilité par profil** : action de formation, bilan, VAE, CFA, certifiant, sous-traitance, nouvel entrant ; un indicateur non applicable est déclaré et justifié, jamais simplement masqué.
- **Fiche indicateur** : texte officiel et niveau attendu, mesures calculées, preuves (fichier, lien, objet), statut (à faire, en cours, prêt, à revoir), responsable, date de revue, commentaires.
- **Dossier d'audit** : export par indicateur (PDF + archive), journal des modifications, accès lecture seule pour l'auditeur.

**Indicateurs, mesures automatiques et modules sources** (titres abrégés ; « 2026 » = indicateur réécrit ou créé par le décret)

| N° | Indicateur | Applicable | Mesures automatiques et registres | Module source |
| --- | --- | --- | --- | --- |
| 1 | Information du public (2026 : vérifiable, sans mention trompeuse) | Tous | Canaux de diffusion, complétude des programmes, date de mise à jour, versions publiées | Catalogue, site |
| 2 | Indicateurs de résultats (2026 : méthode de calcul explicitée) | Tous | Taux de satisfaction, réussite, abandon, insertion, avec formule et période publiées | Qualité, Évaluations |
| 3 | Obtention des certifications (2026 : poursuite d'études) | Certifiant | Taux d'obtention par certification, information poursuite d'études | Catalogue, Évaluations |
| 4 | Analyse du besoin | Tous | Questionnaires de besoin envoyés et reçus, apprenants en situation de handicap | Évaluations, Apprenants |
| 5 | Objectifs opérationnels | Tous | Programmes avec objectifs et grille de compétences | Catalogue |
| 6 | Contenus et modalités | Tous | Sessions par mode, documents contractuels générés, parcours individualisés | Sessions, Documents |
| 7 | Adéquation contenus / certification (2026) | Certifiant | Correspondance modules ↔ blocs RNCP, écarts | Catalogue |
| 8 | Positionnement à l'entrée | Tous | Quiz de positionnement par participant, taux de passage | Évaluations |
| 9 | Conditions de déroulement | Tous | Taux de convoqués, rubrique accessibilité, règlement intérieur diffusé | Sessions, Documents |
| 10 | Adaptation de la prestation | Tous | Entrées différées, plannings individuels, adaptations tracées | Sessions |
| 11 | Atteinte des objectifs | Tous | Taux de réussite, quiz, validations de compétences | Évaluations |
| 12 | Engagement et prévention des abandons (2026 : violences, harcèlement, discriminations, VSS) | Tous | Taux d'assiduité, abandons, registre de signalements | Présences, Qualité |
| 13 | Coordination de l'alternance | CFA | Livrets d'apprentissage, rencontres entreprise tracées | Dossiers, CRM |
| 14 | Accompagnement socio-professionnel (2026 : ruptures liées aux violences) | CFA | Suivis individuels, procédure rupture, délais de traitement | Apprenants, Qualité |
| 15 | Droits et devoirs de l'apprenti (2026 : santé et sécurité) | CFA | Documents remis et signés | Documents |
| 16 | Inscription aux épreuves de certification | Certifiant | Inscriptions, dates d'examen, convocations | Évaluations |
| 17 | Moyens humains et techniques | Tous | Intervenants, ressources, capacité des salles | Ressources |
| 18 | Coordination des acteurs | Tous | Ordres de mission, comptes portail formateur actifs | Ressources, Portails |
| 19 | Ressources pédagogiques (2026 : effectivité du distanciel, référent pédagogique) | Tous | Temps et progression LMS, référent désigné, ressources à jour | LMS, Ressources |
| 20 | Référents et gouvernance (2026 : gouvernance CFA, part de permanents) | CFA | Référents handicap et mobilité, conseil de perfectionnement, ratio d'heures permanents | Ressources, Qualité |
| 21 | Compétences des acteurs | Tous | Qualifications à jour par intervenant affecté | Ressources |
| 22 | Développement des compétences | Tous | Qualifications à échéance, formations suivies par l'équipe | Ressources |
| 23 | Veille légale et réglementaire | Tous | Registre de veille (source, date, impact, action) | Qualité |
| 24 | Veille emplois et métiers | Tous | Registre de veille, lien aux programmes impactés | Qualité |
| 25 | Veille pédagogique et technologique | Tous | Registre de veille, innovations intégrées | Qualité |
| 26 | Handicap | Tous | Registre partenaires (Agefiph, Cap emploi, MDPH…), référent, adaptations par apprenant | Qualité, Apprenants |
| 27 | Sous-traitance | Si sous-traitance | Sous-traitants avec statut Qualiopi vérifié par données ouvertes, heures sous-traitées, satisfaction | Ressources, Intégrations |
| 28 | Formation en situation de travail | Alternance | Partenaires et tuteurs mobilisés | CRM, Dossiers |
| 29 | Insertion professionnelle | CFA | Taux d'insertion et de poursuite d'études à 6 mois | Évaluations, Apprenants |
| 30 | Recueil des appréciations | Tous | Taux de satisfaction par public, taux de réponse | Qualité |
| 31 | Traitement des réclamations | Tous | Registre réclamations et incidents, délais, réponses | Qualité, Portails |
| 32 | Amélioration continue (2026 : analyse des risques) | Tous | Plan d'actions relié aux enquêtes et réclamations, cartographie des risques | Qualité |
| 33 | Évaluation des enseignements par les apprentis (nouveau 2026) | CFA | Dispositif d'évaluation distinct du questionnaire de satisfaction, partage aux équipes | Évaluations, Qualité |

**Objectifs qualité** : 16 taux pilotés par période avec cible modifiable (satisfaction globale et par public, réponse, réussite, présence, absence, certification, clients inactifs, devis gagnés, grilles de compétences, complétude des programmes, convocations, accessibilité, sous-traitance, blended).

À vérifier sur le texte du décret avant développement : intitulés exacts 2026 et applicabilité de chaque indicateur ; les titres ci-dessus sont des résumés.

## 6. Commercial et CRM

Le CRM couvre l'amont que Formdev ne gère pas (prospects, pipeline, échanges) et reprend son enchaînement devis → dossier.

| Brique | Fonctions |
| --- | --- |
| Prospects et contacts | Fiche prospect (entreprise par SIRET, contacts, origine, secteur, taille), statut prospect / client / inactif, dédoublonnage, import CSV |
| Opportunités | Pipeline en colonnes par étape, montant, probabilité, montant pondéré, date de clôture prévue, motif de perte, responsable |
| Historique des échanges | Appels, rendez-vous, mails envoyés et reçus, notes, tâches ; fil chronologique par organisation et contact |
| Devis | Destinataire, contact, référence auto, date, validité ; lignes programmes, sessions, produits annexes ; totaux HT/TVA/TTC ; programme joint automatiquement ; modèle devis + convention ; signature électronique |
| Places | Pré-réservation et liste d'attente par session, entrée différée, sortie anticipée, alerte de remplissage |
| Relances | Relance automatique des devis en négociation, rappels par évènement, âge des devis |
| Diffusion de l'offre | Site catalogue, API publique, plugin WordPress, annonceurs (type Form'Annonce), fiches CPF |
| Campagnes | Segments (secteur, OPCO, anciens stagiaires), envois via Mailjet ou équivalent, désinscription RGPD, statistiques |
| Pilotage commercial | Carnet de commande, CA par organisation et session, taux de transformation, panier moyen, prévisions, objectifs par commercial |

Règle : une opportunité gagnée crée le dossier sans ressaisie (contractant, participants, lignes, financements prévus).

## 7. Pédagogie, évaluations et LMS

Le TMS gère les évaluations administratives et qualité ; les parcours distanciels vivent dans un LMS connecté (Learncraft en priorité), dont les données remontent comme preuves et justificatifs de facturation.

**Évaluations (actif complet)**

- Quiz : seuil de réussite, cycle de vie automatisé (ouverture, relances, clôture, diffusion), types de questions choix unique, choix multiple, vrai/faux, réponse courte, échelle, appariement, ordre ; banque de questions par compétence ; génération assistée par IA depuis le contenu des modules (lien ExoGen).
- Positionnement à l'entrée et évaluation finale par participant, comparaison avant / après.
- Grilles de compétences par programme : catégories (blocs RNCP), notation texte ou note, note maximale, coefficient ; relevés et bulletins.
- Notes pédagogiques par apprenant (nature, échéance, statut) ; suivi des abandons.
- Certificats et diplômes : numérotation paramétrable, dates de validité, rappels de recyclage (CACES, habilitations).
- Questionnaires : à chaud et à froid apprenant, donneur d'ordre, intervenant, financeur ; évaluation des enseignements par les apprentis (indicateur 33) ; réponse anonyme, pondération, questions obligatoires, blocs d'information.

**Connecteur LMS (actif, inactif dans la démo Formdev)**

| Sens | Données |
| --- | --- |
| TMS → LMS | Création des comptes apprenants, inscription aux cours par module, dates d'ouverture et de fermeture |
| LMS → TMS | Temps de connexion, progression par module, résultats aux activités, attestations d'assiduité distancielle |
| Usages | Émargement distanciel, facturation au réalisé (financeurs, CPF), preuves indicateurs 11 et 19, alertes de décrochage |

**Classes virtuelles (actif)** : lien par créneau ou par intervenant, présence récupérée quand l'outil le permet.

**Module permis / CACES (optionnel, actif)** : tests CACES, validité des habilitations intervenants, centres d'examen, inspecteurs, numérotation — à garder si ton activité le demande.

## 8. Documents, communications et automatisations

Le moteur de documents et le moteur d'évènements sont les deux pièces les plus coûteuses à construire : à concevoir tôt, car tous les modules s'y branchent.

**Moteur de documents**

- Modèles .docx avec champs de fusion `${...}` (Formdev en compte 1 058 en 30 catégories), boucles (participants, modules, lignes), conditions ; sortie PDF ; versionnage des modèles.
- Dictionnaire des champs consultable et versionné, incluant les champs personnalisés.
- Modèles à fournir : conventions (standard, avenant, intra, tripartite), contrat de formation, devis + convention, programme, plan de formation, convocation et convocation globale, règlement intérieur, feuilles d'émargement, attestations (présence, assiduité, fin de formation, inscription), certificat de réalisation (modèle ministère), relevé de compétences, bulletin, relevé d'absences, chevalet, plan d'accès, ordre et contrat de mission, reçu d'encaissement, relance facture, demandes de prise en charge (AGEFICE, FAFCEA, FAFPM…), fiche identité de l'organisme.
- Génération automatique paramétrable (programme joint aux devis, conventions et convocations), archivage automatique, envoi par mail ou dépôt sur portail.
- Signature électronique : conventions, contrats, protocoles, émargements ; suivi des états et relances.

**Communications**

- Modèles de mails (9 familles : commun, devis, dossier, portail, facture et relance, intervenant, participant, questionnaire…) et de SMS (8 familles, dont convocations) ; expéditeur par société.
- Envoi immédiat ou programmé, historique archivé, détection des numéros invalides, pièces jointes générées.

**Automatisations (moteur d'évènements)**

| Déclencheur | Actions possibles |
| --- | --- |
| Création d'organisation, d'apprenant, de devis | Tâche, mail, rappel à J+n |
| Avant démarrage de dossier ou de session | Convocations, rappels SMS, contrôle des pièces manquantes |
| Début, fin de session, fin de module | Ouverture et clôture de quiz et questionnaires, attestations |
| Dossier non retourné, financement non accordé, convocation non envoyée | Relances et alertes à délai paramétrable |
| Échéance de qualification, de ressource, de validité de formation | Alerte et tâche de renouvellement |
| Facture à échéance non soldée | Relance par niveau |
| Document déposé sur un portail | Notification au destinataire |

Chaque automatisme a : déclencheur, délai, condition, action, destinataires, modèle, journal d'exécution.

## 9. Finance, conformité, BPF et RGPD

La facturation se fait par dossier et par financeur, selon des échéanciers, avec les obligations fiscales et sociales de l'organisme intégrées.

| Fonction | Détail |
| --- | --- |
| Échéanciers | Par dossier × financeur ; types (à l'inscription, mensuel, au réalisé, à la clôture) ; modèles par société |
| Factures | Numérotation continue, avoirs, validation, état des paiements, génération PDF, envoi, facturation au réalisé (présences et LMS) |
| Paiement en ligne | Lien de paiement Stripe sur facture, rapprochement automatique (actif) |
| Règlements | Saisie, affectation multi-factures, types (virement, chèque, CB, OPCO), reçus |
| Remises en banque | Bordereaux par banque et type |
| Relances | Niveaux, délais, modèles, pénalités et frais de recouvrement B2B et B2C |
| Charges | Factures fournisseurs, coûts intervenants et ressources par session, marge par session |
| Comptabilité | Plan comptable (option DREETS), automatisation des comptes, codes analytiques, export journaux ventes et encaissements, documents comptables PDF, exercices |
| NF203 / anti-fraude TVA | Inaltérabilité des factures, chaînage, grand total mensuel et d'exercice, clôtures périodiques, archive fiscale exportable |
| BPF | Génération annuelle par exercice à partir des catégories de stagiaires, financements et heures ; cadres prêts à reporter sur la plateforme de déclaration ; contrôles de cohérence (actif) |
| RGPD | Registre des traitements, durées de conservation par catégorie, anonymisation programmée des apprenants, export et effacement sur demande, consentements SMS et mails, journal d'accès (actif, grisé dans la démo Formdev) |
| Multi-société | Une société par entité juridique : NDA, Qualiopi, banque, numérotation, modèles, marque blanche |

## 10. Pilotage et tableaux de bord

Le pilotage combine un accueil personnalisable par utilisateur et quatre familles de tableaux de bord exportables.

**Centre de contrôle** : 23 alertes « À traiter » (devis en attente, sessions incomplètes, dossiers à financer ou non signés, documents à signer, inscriptions à compléter, apprenants à convoquer, modules à émarger, dossiers et échéances à facturer, factures à valider ou non soldées, questionnaires en attente, mails et SMS programmés, SMS invalides, échéances apprenants, intervenants et ressources, programmes à compléter) ; widget Qualiopi ; bibliothèque de widgets en glisser-déposer.

| Famille | Tableaux et indicateurs |
| --- | --- |
| Chiffre d'affaires | Devis gagnés, contractualisé, facturé, encaissé, réalisé, à facturer ; total, du mois, graphique |
| Production | CA par session, heures réalisées, charge des intervenants, taux de remplissage, marge |
| Direction | Factures, apprenants par session, situations des dossiers, lignes de facture, règlements en attente |
| Commercial | Carnet de commande, pipeline, devis, taux de transformation, panier moyen, CA par organisation, clients inactifs, échanges |
| Qualité | Rapports par programme et par session, questionnaires en attente, objectifs qualité, avancement Qualiopi par indicateur |

Chaque indicateur affiche sa formule et sa période (exigence de l'indicateur 2 réécrit en 2026), et s'exporte en Excel et PDF.

## 11. Portails, rôles et droits

Trois portails externes et sept rôles internes, avec des droits par module et un filtrage des listes par utilisateur si besoin.

| Portail | Ce que l'utilisateur fait |
| --- | --- |
| Apprenant | Convocations, planning individuel, documents, émargement numérique, quiz et questionnaires, accès LMS, attestations, dépôt d'avis ou de réclamation |
| Client (entreprise, financeur) | Devis et conventions à signer, suivi des stagiaires, présences, factures et paiement en ligne, questionnaires donneur d'ordre, réclamations |
| Formateur | Planning, listes de participants, émargement, notes pédagogiques, envoi de quiz, documents à jour, ordres et contrats de mission, déclaration d'indisponibilités, questionnaire intervenant |

| Rôle interne | Périmètre |
| --- | --- |
| Administrateur | Tout, configuration, utilisateurs |
| Direction | Lecture complète, pilotage, validation des factures |
| Gestionnaire administratif | Sessions, dossiers, apprenants, documents, plannings |
| Commercial | CRM, devis, organisations ; sessions en lecture |
| Comptable | Finance, exports, NF203 |
| Responsable qualité | Module Qualité, enquêtes, registres, dossier d'audit |
| Auditeur (temporaire) | Lecture seule sur les preuves et le dossier d'audit, accès daté |

**Sécurité** : authentification unique (SSO via authentik), double facteur, journal d'audit des accès et des changements de droits, sauvegardes, hébergement en France.

## 12. Intégrations et données ouvertes

Aucune API ne fournit le référentiel Qualiopi : il est saisi et versionné dans le TMS. Les données ouvertes servent à vérifier et préremplir.

| Source / outil | Usage dans le TMS | Statut |
| --- | --- | --- |
| [Liste publique des organismes de formation](https://www.data.gouv.fr/fr/datasets/582c8978c751df788ec0bb7e/) (data.gouv.fr, mise à jour quotidienne) | Vérifier le statut Qualiopi et le NDA des sous-traitants (indicateur 27) et de sa propre société | Import planifié du fichier |
| Annuaire des Entreprises / base SIRENE | Préremplir organisations par SIRET (raison sociale, adresse, NAF) | API à confirmer |
| Répertoires RNCP / RS (France Compétences) | Codes et blocs de compétences des programmes certifiants (indicateur 7) | Données ouvertes à confirmer |
| Décret n° 2026-728 et guide de lecture | Source du référentiel versionné (saisie manuelle, 33 indicateurs) | Mise à jour à chaque révision |
| LMS (Learncraft) | Comptes, inscriptions, temps, progression, résultats | Connecteur à développer |
| ExoGen / IA locale (Ollama, llama.cpp) | Génération de quiz, aide à la rédaction des programmes, synthèse des enquêtes | Optionnel |
| Signature électronique | Conventions, contrats, émargements | Prestataire à choisir |
| Mailjet ou équivalent, passerelle SMS | Mails transactionnels, campagnes, SMS | Connecteur |
| Stripe | Paiement en ligne des factures | Connecteur |
| Classes virtuelles | Liens et présences | Connecteur |
| Nextcloud | Archivage des documents et dossiers d'audit | Connecteur (WebDAV) |
| authentik | SSO des utilisateurs internes et formateurs | Connecteur (OIDC) |
| ERPNext | Comptabilité générale si déléguée | Export ou API |
| Site web, WordPress, annonceurs | Diffusion du catalogue | API publique + plugin |

Une API REST documentée expose les objets principaux (catalogue, sessions, inscriptions, dossiers) pour les intégrations futures.

## 13. Fonctions inactives dans la démo Formdev, rendues actives

Tout ce qui était grisé, verrouillé, vide ou redirigé dans l'essai Formdev est spécifié ici comme une fonction complète du TMS.

| Fonction | État dans la démo Formdev | Cible dans le TMS | Section |
| --- | --- | --- | --- |
| Indicateurs Qualiopi 03, 07, 08, 13, 14, 15, 16, 20, 28, 29, 33 | Pastilles grisées, non ouvrables | Actifs, applicabilité par profil, mesures et registres | 5 |
| Indicateurs 23 à 26, 31, 32 | Aucune mesure automatique | Registres de veille, handicap, réclamations, amélioration continue et risques | 5 |
| QualiopiEasy (bêta) | Statut binaire « au vert » | Statuts, responsable, revue, dossier d'audit exportable, accès auditeur | 5 |
| Connecteur LMS, modules LMS, quiz LMS | Écrans redirigés vers l'accueil | Connecteur bidirectionnel Learncraft | 7 |
| Classes virtuelles | Non accessible | Liens par créneau, présences | 7 |
| Module permis / CACES | Non testé | Optionnel, actif si besoin | 7 |
| Quiz | Choix unique ou multiple seulement | 7 types de questions, banque, génération IA | 7 |
| RGPD | Carte grisée | Registre, conservation, anonymisation, droits des personnes | 9 |
| BPF | Non généré | Génération annuelle avec contrôles | 9 |
| Paiement en ligne Stripe | Paramétrage non testé | Actif sur factures | 9 |
| NF203 | Clôtures non testées | Actif complet | 9 |
| Tableaux de bord | Non générés | 4 familles + CA, exportables | 10 |
| Présences par apprenant | Redirigé vers l'accueil | Vue par apprenant, absences justifiées ou non | 4 |
| Modèles SMS | Route absente | Modèles et envois SMS | 8 |
| Site web catalogue, extranets | Option unique visible | Portails apprenant, client, formateur complets + API catalogue | 11, 12 |
| Mailjet, Form'Annonce | Connecteurs non testés | Connecteurs actifs | 12 |
| Notifications, Support, Mon compte | Non ouverts | Centre de notifications, aide intégrée, gestion du compte et des utilisateurs | 11 |
| Gestion des utilisateurs et droits | Non visible | 7 rôles, droits par module, SSO | 11 |

## 14. Feuille de route par lots

Quatre lots, chacun utilisable en production à sa livraison ; l'ordre suit les dépendances (le moteur de documents et le référentiel Qualiopi conditionnent presque tout).

| Lot | Contenu | Dépend de |
| --- | --- | --- |
| 1 – Socle | Sociétés, utilisateurs et rôles, SSO ; catalogue (programmes, modules) ; organisations, contacts, apprenants ; sessions, agenda, plannings ; dossiers, inscriptions, financements ; moteur de documents (conventions, convocations, émargements, attestations) ; émargement ; référentiel Qualiopi versionné avec preuves manuelles | – |
| 2 – Gestion | Devis ; facturation par échéances, règlements, relances, export comptable, NF203 ; BPF ; moteur d'évènements (rappels, mails, SMS) ; signature électronique ; archives Nextcloud | Lot 1 |
| 3 – Qualité et pédagogie | Quiz, questionnaires, grilles de compétences ; mesures automatiques des 33 indicateurs ; registres (veille, handicap, réclamations, risques) ; objectifs qualité ; dossier d'audit ; RGPD | Lots 1-2 |
| 4 – Ouverture | Portails apprenant, client, formateur ; connecteur LMS ; CRM complet et campagnes ; tableaux de bord ; paiement en ligne ; API publique et diffusion catalogue ; données ouvertes ; IA | Lots 1-3 |

Recommandation : démarrer le lot 1 par le modèle de données et le moteur de documents, puis tester avec un vrai dossier OPCO de bout en bout avant d'élargir.

## 15. Décisions à prendre

- [ ] Usage : outil interne (CAP Formation, ton activité) ou produit commercialisé à d'autres organismes ?
- [ ] Multi-société et module CFA / apprentissage nécessaires dès le lot 1 ?
- [ ] Facturation et comptabilité dans le TMS ou déléguées à ERPNext ?
- [ ] Prestataire de signature électronique et passerelle SMS.
- [ ] Stack technique (back-end, base de données, moteur de génération .docx) et hébergement (homelab ou hébergeur certifié).
- [ ] Module permis / CACES conservé ou retiré ?
- [ ] Vérifier sur le texte officiel du décret n° 2026-728 les intitulés et l'applicabilité des 33 indicateurs, et intégrer le guide de lecture V10 dès sa parution.

## Sources

- Relevé fonctionnel de Formdev (app.form-dev.fr, version 5.13.10, compte d'essai), 9 novembre 2026 — détail écran par écran dans le doc « Cartographie Formdev – base du futur TMS ».
- [Décret Qualiopi du 1er août 2026 : le référentiel passe à 33 indicateurs](https://certiforma.fr/decret-qualiopi-2026-728-33-indicateurs/) (Certiforma)
- [Qualiopi : version actualisée du référentiel national qualité](https://www.akto.fr/qualiopi-version-actualisee-referentiel-national-qualite/) (AKTO)
- [Qualiopi : les nouveautés du référentiel au 1er novembre 2026](https://www.cnfce.com/actualites/qualiopi-ce-qui-change-au-1er-novembre-2026) (CNFCE)
- [Liste publique des organismes de formation](https://www.data.gouv.fr/fr/datasets/582c8978c751df788ec0bb7e/) (data.gouv.fr)
- [Learnybox et Formdev](https://learnybox.com/formdev/) (LearnyBox)
