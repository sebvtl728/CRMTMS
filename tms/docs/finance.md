# Guide utilisateur : factures, règlements et relances (0.9, 0.9.1)

Le TMS **émet les factures officielles** de l'organisme, directement depuis le dossier de formation, **au réel des heures faites**. Ce guide s'adresse aux gestionnaires et à la direction. Les réglages (droits, sociétés, numérotation) sont dans `admin.md` (§ 3, § 10, § 18) ; les heures réalisées, dans `sessions.md`.

Règlements, factures soldées et relances : depuis la 0.9.1 (§ 12 à § 14). Pas encore : reçus d'encaissement, remises en banque, rapprochement bancaire, paiement en ligne, export comptable et facture électronique (0.9.2).

## 1. Qui est facturé

Pour chaque dossier, le TMS prépare une facture **par destinataire** :

- **Le contractant du dossier** (l'entreprise, ou le particulier) : il reçoit la facture de tout ce qui n'est pas payé directement par un financeur.
- **Un financeur qui paie directement l'organisme** (OPCO, France Travail, Région…) : il reçoit **sa propre facture pour sa part**. Pour cela, son financement doit être **Accordé**, cocher **« Paie directement l'organisme »** (page du dossier, section Financements) et porter un **numéro de prise en charge** (numéro d'accord). Un financement seulement demandé ne réduit pas la facture du contractant ; s'il est accordé plus tard, la page « À facturer » propose la facture du financeur et l'avoir correspondant au contractant (§ 4).
- **Un financeur facturé hors du TMS** (par exemple le CPF, réglé par la plateforme EDOF) : son type de financement est marqué « Facturé hors du TMS » (Administration › Référentiels › Types de financeur). Sa part est retirée de la facture du contractant, aucune facture n'est émise pour elle dans le TMS, et la facture du contractant porte la mention « Part réglée par <financeur> ». Le type « CPF » est marqué ainsi d'origine (à la mise à jour 0.9, le type nommé exactement « CPF » de chaque organisme l'est aussi) ; décochez la case si votre organisme facture lui-même le CPF.

Le financeur ne peut pas être le contractant lui-même : « Paie directement » est alors refusé. Un financement refusé ne compte jamais.

## 2. Le rythme de facturation

Chaque dossier a son **rythme**, réglé dans le bloc **Facturation** de la page du dossier (droit « Préparer les factures ») :

- **Fin de formation** (par défaut) : une facture quand toutes les sessions du dossier sont terminées et leurs mois de présences clôturés.
- **Mensuel au réalisé** : une facture par mois clôturé. **Seules les heures réalisées d'un mois clôturé sont facturées** : un mois non clôturé n'est jamais facturé, et le mensuel ne se combine pas avec la base « au prévu ».
- **Échéancier** : des échéances (date et pourcentage, 100 % au total, 24 au plus). Les échéances intermédiaires ne portent que sur la **part du contractant** ; **les financeurs ne sont jamais facturés d'avance** : ils sont facturés à la **dernière échéance**, au réalisé, qui régularise tout.

La **base** de calcul :

- **Au réalisé** (par défaut) : on facture les heures réellement faites (présences), jamais plus que ce qui était prévu pour l'inscrit.
- **Au prévu** : pour un forfait, on facture le prix prévu quelles que soient les heures faites.

Les montants calculés partent du **prix des lignes du dossier, remises déduites** (une ligne à 1 000 € remisée de 10 % est facturée sur 900 €).

Les frais (déplacement, repas, forfaits) sont facturés en entier à la première facture (mensuel), à la fin (fin de formation) ou au prorata des pourcentages (échéancier).

**Verrouillage** : dès la **première facture validée** d'un dossier, le rythme, la base, l'échéancier, le réglage « Paie directement », la **société émettrice** et le **contractant** du dossier ne changent plus, ni le **financeur** d'un financement déjà facturé, qui ne se supprime pas non plus (« Ce dossier a des factures validées : faites d'abord un avoir. ») ; il faut d'abord faire un avoir (§ 7). Un dossier qui a des factures validées ne peut pas non plus être mis à la corbeille. La règle des absences non justifiées du dossier est figée elle aussi (§ 3).

## 3. Absences non justifiées et dédit

- **Absences non justifiées** : réglage de l'organisme (Finance › Réglages de facturation, « Non facturées » par défaut), que chaque dossier peut remplacer. Avec « Facturées au contractant », les heures d'un stagiaire **absent** (et la part manquante d'un « partiel ») sur des séances passées sont facturées **au contractant seul**, sur une ligne distincte « Absences non justifiées — non imputables sur les fonds de la formation professionnelle », au tarif horaire de la ligne. Jamais une absence justifiée, une séance annulée, un module en intervalle (pas de présence par séance) ni un stagiaire sorti (règle d'abandon). Jamais imputées à un financeur. **Le réglage de l'organisme ne vaut que pour les dossiers pas encore facturés** : à la première facture validée, le dossier garde la règle alors appliquée, et un changement ultérieur du réglage de l'organisme ne le touche plus (rien de nouveau n'est dû). Le bloc Facturation du dossier propose « Comme l'organisme : … » (le réglage du moment, figé à son tour à la première facture) ou l'autre choix, « Pour ce dossier : … ».
- **Dédit d'abandon** : si la règle d'abandon du dossier prévoit un dédit (`sessions.md` § 4), il est facturé **en entier au contractant** (jamais aux financeurs : il n'est pas imputable sur les fonds de la formation professionnelle), sur une **ligne distincte**, **hors champ de la TVA**. Aucun dédit pour un abandon de force majeure. *Traitement à faire confirmer par l'expert-comptable (§ 11).*

## 4. La page « À facturer » (menu Finance)

Elle liste, par dossier et par destinataire, ce qui est **dû à ce jour** : le dossier, le destinataire, le rythme, la période, le **montant dû HT**, et un détail dépliable pour tout vérifier (heures prévues et réalisées, taux, part de chaque financeur). Filtres : société, DAS, responsable, mois. Un dossier sans rien à facturer n'apparaît pas.

- Le dû = la part cumulée du destinataire moins ce qui lui a **déjà été facturé** (factures validées moins avoirs). Rien n'est oublié, rien n'est facturé deux fois.
- **Cases à cocher + « Préparer les brouillons »** : un brouillon par destinataire.
- **Bientôt à facturer** : les dossiers pas encore prêts, avec la raison en français (une séance pas terminée, un mois à clôturer…). Ils ne sont jamais proposés comme dus.
- **Brouillon en cours** : la ligne a déjà un brouillon (lien direct) ; « Préparer » n'en crée pas un deuxième.
- **À régulariser** : un montant négatif (heures finalement non faites) propose un **avoir** ; quand aucune facture n'est là à corriger, la ligne l'indique (« À régulariser : aucune facture à corriger ») et n'est pas cochable. Quand un avoir ne couvre pas tout, « Reste à régulariser ensuite » affiche ce qui reste, pour un deuxième avoir.
- Quand une ligne calculée a été modifiée à la main sur une facture, l'écart est affiché.

Les modifications après une facture validée (une présence corrigée, un financement accordé tard, un montant changé) se répercutent : le dû est recalculé et la page propose la facture complémentaire ou l'avoir.

## 5. Les brouillons

Un brouillon se crée depuis « À facturer » ou **à la main** depuis le bloc Facturation du dossier. Il contient :

- des **lignes calculées** (une par ligne du dossier, avec la période et les heures réalisées / prévues ; stagiaires nommés sur la facture d'un financeur). On ne les supprime pas : mettez leur montant à 0. Libellé, quantité, unité et prix restent modifiables ;
- des **lignes libres** (frais, repas…), ajoutées et supprimées à volonté. **Elles ne comptent jamais dans le dû calculé** : ajouter un repas ne change pas ce qui reste à facturer.

TVA d'une ligne : 0 pour une société exonérée et pour le dédit, sinon 0 / 5,5 / 10 / 20 %.

**Recalculer** remet le brouillon d'aplomb sur le dû actuel (après une présence ou un financement qui a changé). Il ne change jamais une facture en avoir, ni l'inverse : si le dû a changé de sens (ou s'il n'y a plus d'avoir dû pour un brouillon d'avoir), il refuse (« Le montant dû a changé de sens : supprimez ce brouillon et préparez-en un nouveau. ») sans toucher au brouillon.

**Fiches fusionnées** : un brouillon adressé à une fiche du CRM fusionnée depuis dans une autre (doublon) garde l'ancienne fiche : supprimez-le et préparez-en un nouveau depuis « À facturer ». Un brouillon est modifiable et supprimable ; il y a **un seul brouillon ouvert** par destinataire, dossier et type (facture ou avoir). Les montants sont en centimes entiers, arrondis au demi supérieur.

## 6. La validation

« Valider » (droit « Valider les factures », par défaut administrateur et direction) :

- donne le **numéro définitif** `<préfixe>-<année>-<numéro sur 5 chiffres>` (`F-2026-00001`, avoirs `AV-2026-00001`), **continu, sans trou ni doublon**, par société, type et année (heure de Paris) ; l'échéance = date d'émission + délai de paiement de la société ;
- **fige** le destinataire (nom, adresse, SIRET, e-mail, numéro de dossier, numéro d'accord), les lignes et les totaux, et **archive le PDF** ;
- ajoute la pièce au **journal chaîné** (§ 10) ;
- passe le dossier à l'état « Facturé » quand tout est facturé (et le ramène à « Réalisé » si un avoir fait revenir un dû). L'état est posé **au moment d'une validation** : un dossier dont les sessions se terminent après sa dernière facture reste « Réalisé » jusqu'à la validation suivante.

**Contrôle à la validation** : le dû est recalculé à cet instant ; si le brouillon facture plus que le dû actuel, la validation est refusée (« Le montant dû a changé : recalculez le brouillon avant de le valider. »). Autres refus : total nul, destinataire qui n'est plus facturable, numéro d'accord manquant pour un financeur, société du dossier changée depuis la préparation du brouillon (« La société du dossier a changé : recalculez le brouillon avant de le valider. »). Si le PDF ne peut pas être produit (service indisponible), rien n'est validé et le numéro n'est pas consommé.

Une facture validée est **immuable**, même dans la base de données, et ne se supprime jamais. La corriger = un avoir.

## 7. Les avoirs

Un **avoir corrige toujours une facture** validée du même destinataire. Il est émis par la **même société** et adressé au **même destinataire** que la facture corrigée, même si la société ou le contractant du dossier ont changé depuis (fiches fusionnées) ; un brouillon d'avoir qui diffère est refusé à la validation (« Un avoir doit être émis par la même société et au même destinataire que la facture corrigée. »). « Créer un avoir » depuis la facture (toutes les lignes, ou les lignes choisies) ; un avoir **partiel** s'obtient en réduisant les montants du brouillon. Même circuit : brouillon, validation, numéro `AV`.

- Un avoir **ne dépasse jamais ce qui reste de la facture** corrigée (facture moins ses avoirs déjà validés), ni ce qui a été facturé au destinataire sur le dossier.
- Il reprend les **taux de TVA de la facture corrigée**, non modifiables.
- Les propositions de la page « À facturer » sont **itératives** : un premier avoir couvre ce que la dernière facture permet, puis la page propose le suivant sur la facture précédente jusqu'à tout régulariser.

## 8. Le PDF et ses mentions

Le PDF reprend la charte des devis. Mentions : société émettrice (identité, SIRET, n° de TVA, **n° de déclaration d'activité**, mentions légales, pied de facture), client (nom, adresse, SIREN/TVA si connus), numéro, dates d'émission et d'échéance, période de formation, désignation (dates, heures réalisées / prévues, stagiaires pour un financeur), quantités, prix unitaires HT, TVA par taux, totaux HT et TTC, référence du dossier et du financement (numéro d'accord), conditions de paiement et « pas d'escompte pour paiement anticipé ».

- Société **exonérée** : « Exonération de TVA, article 261-4-4° du CGI » et « Net à payer ».
- Le dédit porte « Indemnité hors champ de la TVA ».
- **Pénalités de retard et indemnité forfaitaire de 40 €** : imprimées seulement quand le destinataire est un **professionnel** (entreprise ou financeur), jamais pour un particulier.
- Un brouillon s'aperçoit avec le filigrane « BROUILLON ». Une facture validée sert **toujours son PDF archivé**, jamais un nouveau rendu ; si l'archive est perdue, le téléchargement répond 410.

## 9. L'envoi par e-mail et le renvoi

Sur la page d'une facture validée (droit « Préparer les factures ») : **Envoyer**. L'adresse est préremplie avec l'e-mail du destinataire figé, modifiable ; l'objet et le message viennent du modèle d'e-mail « Facture » (Administration › Modèles d'e-mails). L'e-mail part en **texte simple** avec le **PDF archivé** en pièce jointe, sur la messagerie de l'organisme. La facture passe à « Envoyée ». En cas d'échec, un bandeau donne la raison ; **Renvoyer** (ou « Envoyer à une autre adresse ») refait un envoi. L'historique de chaque envoi est conservé. Avant l'envoi, le TMS vérifie que le PDF archivé n'a pas été altéré ; sinon rien ne part.

## 10. Liste, export et intégrité

- **Finance › Factures** : liste (numéro, date, destinataire, dossier, HT, TTC, état), filtres, **Exporter (.csv)** (séparateur `;`, dates et montants à la française, mêmes filtres et même périmètre que la liste).
- **Vérifier l'intégrité** (droit « Valider les factures ») : recalcule la chaîne du journal et le contenu de chaque pièce. « Intégrité vérifiée : N pièce(s), chaîne intacte. » ou, en cas de rupture, la première pièce touchée. Même contrôle en ligne de commande : `php artisan tms:finance:verify` (code de sortie 1 en cas de rupture).

## 11. À faire valider avec l'expert-comptable

Avant la première facture réelle :

- le **régime de TVA** de chaque société (exonération de l'article 261-4-4° du CGI, ou assujettie) ;
- le **traitement du dédit** (ligne distincte, hors champ de la TVA, facturée au seul contractant) et des **absences non justifiées** facturées ;
- le **CPF facturé hors du TMS** (réglé par EDOF, coché d'origine sur le type « CPF ») : sa part n'apparaît que comme mention sur la facture du contractant ;
- les mentions de pied de facture (pénalités, indemnité de 40 €, RIB) et l'absence de n° de TVA et d'e-mail de facturation dédié dans les fiches du CRM (le TMS utilise l'e-mail de la fiche) ;
- la **conservation 10 ans** des pièces (le TMS n'efface jamais une facture validée, l'anonymisation RGPD d'une personne non plus ; voir `admin.md` § 11) ;
- les **règles du particulier** (délai de rétractation de 14 jours, acompte de 30 % au plus, § 15) et le texte des **relances** (mention des pénalités et de l'indemnité de 40 € pour les seuls professionnels, § 14).

## 12. Les règlements (0.9.1)

**Finance › Règlements** (droit « Enregistrer les règlements », par défaut administrateur et direction, sur tout l'organisme) liste les sommes reçues : date, payeur, mode, référence, montant et part **non affectée**.

- **Enregistrer un règlement** : depuis la page d'une facture (bouton du bloc **Règlements**, prérempli avec le payeur, la société et le reste dû) ou **Nouveau règlement** (choisissez le payeur, entreprise ou particulier, puis la société). Indiquez la date (jamais dans le futur), le montant, le **mode** (référentiel « Modes de règlement » : virement, chèque, carte bancaire, espèces, prélèvement) et la référence (n° de chèque, libellé du virement).
- **Répartition** : un même règlement peut couvrir **plusieurs factures du même payeur et de la même société** (un OPCO qui vire une somme pour plusieurs dossiers). **Répartir** affecte le montant aux factures les plus anciennes d'abord ; chaque montant reste modifiable. Une affectation ne dépasse jamais le **reste dû** d'une facture, et la somme des affectations ne dépasse jamais le règlement. Le reste est **non affecté** : il s'affecte plus tard en modifiant le règlement (filtre « Avec un reste non affecté »).
- Seule une **facture validée ou envoyée** reçoit un règlement, jamais un avoir ni un brouillon.
- **Modifier** ou **Supprimer** un règlement est journalisé ; la facture, elle, ne change jamais (le règlement vit à côté).

## 13. Factures soldées et en retard

Le **reste dû** d'une facture = TTC − avoirs validés qui la corrigent − règlements affectés. La facture est :

- **Non réglée** (rien de réglé), **Partiellement réglée**, ou **Soldée** (reste dû nul ; un avoir qui l'annule entièrement la solde aussi) ;
- **En retard** quand elle n'est pas soldée et que son échéance est passée (jour de Paris).

Un règlement suivi d'un avoir peut laisser un **trop-perçu** (affiché, jamais remboursé par le TMS : faites le remboursement hors du TMS).

Où le voir : la liste **Finance › Factures** (colonne Reste dû, état de paiement, filtre **Paiement** : « Non soldées », « En retard »), la page de la facture (bloc **Règlements**), le bloc **Facturation** du dossier (réglé, reste à encaisser) et l'**export CSV** (colonnes Réglé, Reste dû, État de paiement).

## 14. Les relances

**Niveaux** : réglés dans **Finance › Réglages de facturation** (droit `settings.manage`) : 1 à 3 délais croissants, en jours après l'échéance (par défaut **7, 30 et 60 jours**). Le niveau suivant ne part qu'une fois le précédent **envoyé** ; une relance qui n'est pas partie (erreur d'envoi) est proposée de nouveau au même niveau. Après le dernier niveau, la facture reste « En retard » sans nouvelle relance.

- **Finance › À relancer** (droit « Préparer les factures », sur son périmètre) : les factures dont un niveau est atteint, avec retard, reste dû, niveau et dernière relance. Cochez puis **Envoyer les relances** : chaque e-mail part avec le modèle de son niveau et le **PDF archivé** joint, à l'adresse du destinataire figée à la validation (une ligne sans adresse n'est pas cochable). Une facture soldée ou suspendue entre-temps est ignorée (le message l'indique).
- **Depuis la page de la facture** : **Relancer (niveau n)** ouvre la fenêtre d'envoi préremplie (adresse, objet et message modifiables).
- **Suspendre les relances** (litige, échéancier accordé), avec un motif ; **Reprendre les relances** les rétablit. Journalisé.
- **Relance automatique** (case dans les réglages, désactivée par défaut) : chaque matin à 8 h 30, le niveau dû de chaque facture part tout seul (historique : « automatique »), jamais deux relances d'une facture le même jour.
- **Modèles** : Administration › Modèles d'e-mails, « Relance de facture — niveau 1, 2, 3 ». Variables : numéro, société, destinataire, montant TTC, reste dû, échéance, jours de retard, niveau, et `{penalites}` : la mention des **pénalités de retard et de l'indemnité forfaitaire de 40 €**, remplie pour un **professionnel** et **toujours vide pour un particulier**.

Les relances apparaissent dans l'historique d'envoi de la facture ; « Renvoyer » refait la dernière relance échouée.

## 15. Le particulier (contractant personne)

*Règles du Code du travail (art. L6353-5 et L6353-6) à faire confirmer par l'expert-comptable.*

- **Délai de rétractation** : quand le dossier porte la **date de signature du contrat** (« Convention signée le »), aucune facture au particulier ne se valide avant **signature + 14 jours** (« Délai de rétractation du particulier : aucune facture avant le … »). Sans date de signature, le brouillon affiche un avertissement mais se valide.
- **Échéancier** : la **première échéance** d'un particulier ne dépasse pas **30 %** du prix et ne tombe pas pendant le délai de rétractation. Contrôlé à l'enregistrement de l'échéancier (un échéancier déjà verrouillé par une facture n'est pas re-contrôlé).
- Les factures d'un **financeur** du dossier ne sont pas concernées.
