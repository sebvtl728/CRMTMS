# Documents de formation

Depuis la 0.10, le TMS produit les documents d'un dossier avec ses données : **convention de formation** (contractant
entreprise) et **contrat de formation** (particulier). Convocations, attestations, certificat de réalisation et
signature en ligne arrivent dans les livraisons suivantes de la 0.10.

## 1. Droits

| Droit | Permet | Par défaut |
|---|---|---|
| `documents.view` — Consulter les documents | voir et télécharger les documents des dossiers | administrateur, direction, assistant(e), responsable de DAS (ses DAS) ; commercial et chargé(e) de relations (leurs dossiers) |
| `documents.manage` — Émettre les documents | émettre, envoyer, annuler | administrateur, direction, assistant(e) ; responsable de DAS (ses DAS) |

Les deux droits suivent le **périmètre du dossier** (ses fiches, ses DAS, tout l'organisme). Un dossier hors périmètre
n'affiche pas ses documents ; leur lien répond « introuvable ».

## 2. Émettre

Sur la page du dossier, bloc **Documents** :

- **Émettre : convention de formation** pour un contractant entreprise ; **Émettre : contrat de formation** pour un
  particulier (le TMS propose le bon document) ;
- le document reprend les données du dossier au moment de l'émission : sessions (dates, durée, lieu), stagiaires,
  lignes et prix du dossier, contractant (et contact du dossier pour une convention), société émettrice (logo,
  signature et cachet, déclaration d'activité, mentions légales) et les **textes** de l'organisme (§ 4) ;
- il reçoit un numéro définitif `DOC-<année>-<numéro sur 5 chiffres>`, continu, et son PDF est **archivé** : il ne
  change plus jamais, même si le dossier change ensuite ;
- une seule convention (ou un seul contrat) **valide** par dossier : pour corriger, **Annuler** (motif obligatoire) puis
  émettre un nouveau document. Le document annulé reste consultable avec son motif.

Si le moteur PDF est indisponible, l'émission est refusée (« Le PDF n'a pas pu être généré, réessayez dans un instant ») et
aucun numéro n'est consommé.

## 3. Aperçu, téléchargement, envoi

- **Aperçu** ouvre le PDF archivé dans un nouvel onglet ; **Télécharger** l'enregistre.
- **Envoyer** : destinataire (contact du dossier pour une convention, le particulier pour un contrat ; modifiable),
  objet et message du modèle d'e-mail « Envoi d'une convention » / « Envoi d'un contrat » (Administration › Modèles
  d'e-mails). Le PDF archivé est joint. L'envoi se fait en arrière-plan ; un échec affiche « L'e-mail n'est pas parti »
  avec un motif, sans le texte du serveur.
- Une convention ou un contrat envoyé fait passer le dossier à **Convention envoyée** (s'il était à « Créé » ; jamais en
  arrière).

## 4. Modèles de documents

**Administration › Modèles de documents** (droit `settings.manage`) : pour chaque document, les articles (préambule,
objet, durée, prix, dédit, rétractation…) avec leur texte d'origine, conforme aux articles L6353-1 à L6353-7 du Code du
travail.

- Les textes se règlent pour **l'organisme** (toutes les sociétés) ou pour **une société** ; à défaut, le texte de
  l'organisme, puis le texte d'origine.
- Champs utilisables : `{stagiaire}`, `{stagiaires}`, `{formation}`, `{dates}`, `{duree}`, `{lieu}`, `{prix}`,
  `{contractant}`, `{societe}`, `{numero_dossier}` ; un champ inconnu ou une balise est refusé.
- Les articles **obligatoires** (prix, dédit ; pour le contrat : rétractation de 14 jours, modalités de paiement) ne
  peuvent pas être vidés. **Revenir au texte d'origine** efface la personnalisation.
- Un changement de texte ne modifie jamais un document déjà émis.

La **signature et le cachet** de la société se déposent dans Administration › Sociétés (image PNG ou JPEG) : ils
s'impriment sur les documents émis ensuite.

## 5. Données personnelles

Le document émis est conservé (preuve de la formation). L'anonymisation d'une personne efface le destinataire figé
(particulier : nom et adresse ; convention : contact et e-mail) et l'adresse, l'objet et le message des e-mails ; le PDF
archivé est gardé. L'export des données d'une personne liste les documents qui la nomment.
