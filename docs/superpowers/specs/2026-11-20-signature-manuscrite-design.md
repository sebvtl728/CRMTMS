# Signature manuscrite dans l'encadré (0.10.1) - conception courte

## Intention

Le signataire en ligne (stagiaire particulier pour un contrat, contact de l'entreprise pour une convention, client pour
un devis « Bon pour accord ») **dessine sa signature** à la main ; elle apparaît **dans l'encadré signature du
document**, comme sur un papier signé. Le certificat (page de preuve) reste. Demande de l'utilisateur, 2026-11-20 :
dessin **obligatoire** pour signer.

Non-objectif : changer la valeur juridique (reste une signature électronique simple ; le code e-mail et la page de
preuve font foi). Anti-objectif : un document signé dont le texte diffère de celui que le signataire a lu.

## Approches

1. **Rendre à nouveau la source figée (retenue).** À l'émission (document) ou au gel (devis envoyé), le HTML exact du
   document et de son pied de page est archivé à côté du PDF. À la signature, ce même HTML est rendu par Gotenberg, le
   dessin inséré dans l'emplacement réservé de l'encadré. Texte identique par construction ; aucun module ne recalcule
   de données.
2. Coller l'image sur le PDF existant à la position de l'encadré : position variable selon la longueur du document,
   bibliothèque PDF supplémentaire. Écartée.
3. Rendre à nouveau depuis les données vivantes : le texte pourrait avoir changé (société, textes). Écartée.

## Conception

- **Source figée** : fichier JSON `{html, footer}` à côté du PDF archivé (`<pdf>.source.json`, même zone
  `TenantStorage`), écrit par `DocumentIssuer` et `QuoteFreezer` dans leur transaction (fichier supprimé si elle échoue).
  Les gabarits contiennent dans l'encadré du signataire l'emplacement exact `<div class="signature-slot"></div>`.
- **Demande de signature** (`Core\Signatures`) : `request(..., ?string $sourcePath)` enregistre `source_path` et
  `source_sha256` (nouvelles colonnes de `signature_requests`). Pas de source (document émis avant la 0.10.1) : la
  signature fonctionne, le dessin ne figure que sur la page de preuve.
- **Signer** : `sign($request, $code, $drawing, $ip, $agent)`. Le dessin est une image PNG (data URI) **obligatoire** :
  PNG valide (relu par GD), 200 Ko au plus, 2000 × 1000 px au plus, non vide (au moins quelques pixels opaques),
  sinon erreur de validation `drawing` « Dessinez votre signature. ». Contrôles : original (sha256, comme aujourd'hui)
  et source (sha256 ; altérée = 410). PDF signé = source rendue avec le dessin + page de preuve fusionnée ; la page de
  preuve montre le dessin, l'empreinte du document envoyé, celle du dessin et la mention « Le document signé reprend
  le texte du document envoyé, la signature dessinée insérée dans l'encadré ». Dessin archivé (`drawing_path`,
  `drawing_sha256`) ; l'original n'est jamais modifié.
- **Encadré** : sous le dessin, « Signé électroniquement le JJ/MM/AAAA par <nom> ». Contrat / convention : encadré
  du cocontractant (« Lu et approuvé ») ; devis : encadré « Bon pour accord ».
- **Page publique** (`public/signature.tsx`) : cadre de dessin (canvas, doigt / souris / stylet, `touch-action: none`,
  bouton « Effacer ») avant le code ; « Signer » inactif tant que le cadre est vide. Composant maison, sans dépendance.
- **RGPD** : l'anonymisation qui efface les données du signataire efface aussi le dessin archivé (le PDF signé suit la
  règle actuelle de chaque module : gardé pour les documents, effacé pour les devis).

## Laissé au constructeur

Taille d'affichage du dessin dans l'encadré (tient dans l'encadré existant), épaisseur du trait, nom exact du
composant.
