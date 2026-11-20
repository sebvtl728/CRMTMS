# Documents de formation

Depuis la 0.10, le TMS produit les documents d'un dossier avec ses données : **convention de formation** (contractant
entreprise) et **contrat de formation** (particulier) pour le dossier ; **convocation**, **attestation de fin de
formation** et **certificat de réalisation** pour chaque stagiaire (§ 6). La convention et le contrat se signent en
ligne (§ 7).

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

## 6. Documents des stagiaires (page de la session)

Sur la page de la session, étape **Stagiaires**, bloc **Documents** : un tableau des stagiaires (inscrits, confirmés,
sortis) avec, pour chacun, sa convocation, son attestation et son certificat.

- **Émettre pour tous** émet le document de chaque stagiaire qui ne l'a pas encore (tout ou rien : si un document
  échoue, aucun n'est émis) ; **Émettre** sur une ligne l'émet pour ce stagiaire seulement.
- **Envoyer à tous** envoie les documents émis et pas encore envoyés aux stagiaires qui ont une adresse e-mail ; le
  message indique combien n'en ont pas.
- **Convocation** : formation, dates, durée, lieu et tableau des séances planifiées (date, horaires, lieu), textes
  « Message » et « Informations pratiques » modifiables.
- **Attestation de fin de formation** : stagiaire, entreprise, formation, période (de son entrée à sa sortie), heures
  réalisées (présences et heures saisies) sur les heures prévues ; textes modifiables.
- **Certificat de réalisation** : modèle du ministère du Travail, texte non modifiable, durée = heures réalisées.
- L'attestation et le certificat s'émettent **à la fin de la formation**, ou plus tôt pour un stagiaire **sorti**
  (abandon). Rien n'est émis pour une inscription annulée ou en liste d'attente, ni pour une session annulée.
- Quand chaque stagiaire encore attendu d'un dossier a reçu sa convocation, le dossier passe de **Convention signée** à
  **Convocations envoyées**.
- La synthèse de la session porte deux cartes : **Convocations** (envoyées / stagiaires) et **Attestations** (émises /
  stagiaires, à faire après la fin de la formation).

## 7. Signature en ligne

**Demander la signature** (convention ou contrat émis, droit « Émettre les documents ») envoie au signataire un **lien
personnel**, valable **30 jours** :

1. il ouvre le lien (sans compte), lit le document ;
2. coche « J'ai lu le document et j'accepte de le signer électroniquement » ;
3. demande son **code** (6 chiffres, envoyé à son adresse e-mail, valable **10 minutes**, 5 essais ; un nouveau code
   au plus par minute) ;
4. **dessine sa signature** dans le cadre (doigt, souris ou stylet ; bouton « Effacer ») : obligatoire, « Signer »
   reste grisé tant que le cadre est vide ;
5. saisit le code et **signe**.

Le PDF signé = le document émis avec la **signature dessinée dans l'encadré** du signataire (« Lu et approuvé » ; sous
le dessin : « Signé électroniquement le … par … »), suivi d'une **page de preuve** : signataire, adresse e-mail vérifiée,
date et heure de Paris, adresse IP, navigateur, empreinte SHA-256 du document envoyé, signature dessinée et son
empreinte, référence de la signature. Le texte est exactement celui du document envoyé : à l'émission, le TMS garde la
page qui a produit le PDF et la reproduit à la signature avec le dessin, sans relire les données du dossier ; le
document envoyé reste archivé à l'identique. Un document émis avant la 0.10.1 se signe aussi : le dessin figure alors
sur la page de preuve seulement. C'est une **signature électronique simple**. Le signataire reçoit une copie du PDF signé ; le document passe à **Signé**, le dossier
à **Convention signée** (ce qui fait partir le délai de rétractation de 14 jours d'un particulier). Le bloc du dossier
propose le **PDF signé**.

Un nouvel envoi remplace le lien précédent ; annuler le document rend le lien invalide. Les e-mails (« Demande de
signature en ligne », « Code de signature », « Confirmation de signature ») se modifient dans Administration ›
Modèles d'e-mails ; la demande doit garder `{lien}`, le code `{code}`.

## 5. Données personnelles

Le document émis est conservé (preuve de la formation). L'anonymisation d'une personne efface le destinataire figé
(particulier : nom et adresse ; convention : contact et e-mail) et l'adresse, l'objet et le message des e-mails ; le PDF
archivé est gardé ; ses signatures en ligne perdent nom, adresse e-mail, adresse IP et navigateur. L'export des données
d'une personne liste les documents qui la nomment.
