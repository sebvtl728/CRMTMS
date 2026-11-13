# Guide utilisateur : fiches, recherche, import et RGPD

Ce guide s'adresse aux personnes qui utilisent le TMS au quotidien (commerciaux, chargés de relations, assistants, direction, responsables de DAS). Les écrans sont désignés par leur libellé à l'écran. Ce que vous voyez dépend de votre rôle : les boutons auxquels vous n'avez pas droit n'apparaissent pas (voir `admin.md` pour la liste des droits par rôle).

Le menu de gauche, section **Commercial**, donne accès à **Entreprises**, **Personnes**, **Imports** et **RGPD**. Le mot « entreprise » désigne ici toute organisation cliente ou prospect : entreprise, association, collectivité.

## 1. L'écran d'accueil

**Accueil** rassemble vos raccourcis :

- **Nouvelle entreprise** et **Nouveau particulier** ouvrent directement le formulaire de saisie ;
- **Rechercher** (ou les touches **Ctrl K**) ouvre la recherche (voir plus bas) ;
- le nombre d'entreprises et de personnes que vous pouvez voir ;
- **Dernières fiches consultées** : les 5 dernières fiches que vous avez ouvertes, les plus récentes en premier. Cette liste est personnelle ; elle ne garde que le fait que vous avez ouvert la fiche, pas son contenu. Une fiche supprimée, fusionnée ou hors de votre périmètre n'y figure plus.

## 2. Les fiches

### Entreprises

Écran **Entreprises**. Une fiche entreprise porte : raison sociale, SIRET, code NAF, forme juridique, secteur, type d'établissement, taille, origine, statut (**Prospect**, **Client** ou **Inactif**), responsable, coordonnées, DAS concernés et un résumé libre.

- **Saisie rapide** : seule la **raison sociale** est obligatoire. Complétez le reste quand vous l'avez.
- **Compléter depuis le SIRET** : saisissez les 14 chiffres, puis ce bouton va chercher l'annuaire officiel des entreprises et remplit **uniquement les champs encore vides**. Rien n'est enregistré tant que vous n'avez pas cliqué sur **Enregistrer**. Si l'annuaire ne répond pas, saisissez les informations à la main.
- Un même SIRET ne peut pas servir à deux fiches : le formulaire vous propose d'ouvrir la fiche existante.
- Sur une fiche, les onglets sont **Informations**, **Interlocuteurs** et **Historique**.

### Personnes

Écran **Personnes**. Une personne peut être :

- un **particulier** (stagiaire à titre individuel, financement personnel…) : case « Particulier » cochée ;
- un **interlocuteur** : personne rattachée à une ou plusieurs entreprises, avec sa fonction et son **rôle dans la décision** ;
- les deux à la fois.

Seul le **nom** est obligatoire. Pour rattacher quelqu'un à une entreprise, ouvrez la fiche de l'entreprise, onglet **Interlocuteurs**, puis **Ajouter un interlocuteur** : **Nouvelle personne** ou **Personne existante** (recherche par nom, e-mail ou téléphone). Vous pouvez désigner un **référent**. **Mettre fin au rattachement** (avec une date) conserve la trace dans **Anciens rattachements** ; **Supprimer le rattachement** l'efface.

### Historique, notes et pièces jointes

L'onglet **Historique** d'une fiche liste, du plus récent au plus ancien, les **notes**, les **pièces jointes** et les **modifications** de la fiche (qui a changé quoi et quand ; les données personnelles modifiées sont signalées sans afficher leur valeur).

- **Ajouter une note** : écrivez dans la zone « Ajouter une note » (appel, rendez-vous, information utile), puis **Ajouter la note**. Une note met à jour la date de **dernier contact** de la fiche.
- **Joindre un fichier** : PDF, images, Word, Excel, OpenDocument, texte, CSV, 20 Mo maximum.
- Vous pouvez supprimer une note ou retirer un fichier ; la suppression est tracée dans l'historique.

### Suppression et restauration

**Supprimer** une fiche la place dans la corbeille : elle disparaît des listes mais peut être restaurée (case **Corbeille** de la liste, puis **Restaurer** sur la fiche). Les rattachements, notes et pièces jointes suivent la fiche.

### Mobile

Les écrans fonctionnent sur téléphone (listes en cartes, fiche en une colonne, saisie d'une note sur place). L'export et l'import sont réservés à l'ordinateur.

## 3. Rechercher

- **Recherche globale** : **Ctrl K** (ou **Cmd K** sur Mac, ou la touche **/** hors d'un champ) ouvre une fenêtre de recherche. Tapez au moins 2 caractères : nom, SIRET, e-mail ou téléphone, sans souci d'accents ni de majuscules ni d'espaces dans le SIRET ou le téléphone. Les résultats sont groupés **Entreprises** et **Personnes** ; **flèches** pour choisir, **Entrée** pour ouvrir.
- **Recherche dans une liste** : le champ en haut de **Entreprises** ou **Personnes** filtre la liste en tapant. Les filtres (statut, DAS, secteur, responsable, origine…) se combinent.
- **Mes fiches** : n'affiche que les fiches dont vous êtes le responsable.
- **Filtres enregistrés** : **Enregistrer ce filtre** mémorise vos critères et les colonnes choisies sous un nom. Un filtre peut rester **privé** ou être **partagé** avec tout l'organisme (seul son auteur peut le modifier).
- **Colonnes** : choisissez les colonnes de la liste et de l'export.

> Les **Responsables de DAS** ne voient que les fiches rattachées aux DAS qui leur sont attribués (par l'administrateur). Les autres rôles voient toutes les fiches de l'organisme.

## 4. Doublons et fusion

### Avertissement à la saisie

Quand vous créez ou modifiez une fiche qui ressemble à une autre (même SIRET, même e-mail, même téléphone, nom très proche), le formulaire affiche **Fiches similaires** avec la raison de la ressemblance. Pour chaque fiche, vous pouvez :

- **Ouvrir** la fiche existante dans un nouvel onglet ;
- **Créer puis fusionner** (ou **Fusionner** sur une fiche déjà enregistrée) ;
- **Créer quand même** si ce n'est pas un doublon.

Les doublons ne sont jamais cherchés dans les données d'un autre organisme.

### Fusionner deux fiches

L'écran **Fusionner deux fiches** montre la **fiche conservée** et la **fiche absorbée**. Pour chaque champ qui diffère, choisissez la valeur à garder. Les champs identiques sont regroupés. Au clic sur **Fusionner**, une confirmation rappelle que **la fusion est définitive** :

- l'historique, les notes, les pièces jointes et les rattachements de la fiche absorbée passent sur la fiche conservée ; si les deux fiches avaient le même rattachement, il n'en reste qu'un ;
- les DAS sont réunis ;
- l'adresse de la fiche absorbée renvoie vers la fiche conservée ;
- la fiche absorbée ne peut pas être restaurée et ses données personnelles sont effacées.

## 5. Importer un fichier

L'import (menu **Imports**) charge des fiches depuis un fichier **Excel (.xlsx)** ou **CSV**. Il se fait depuis un ordinateur. Vous pouvez aussi y accéder par le bouton **Importer** de la liste **Entreprises**.

### Étape 1 : Déposer le fichier

Choisissez ce que contient votre fichier :

- **Entreprises** : une ligne par entreprise ;
- **Personnes** : une ligne par personne (particuliers ou contacts sans entreprise) ;
- **Interlocuteurs (personne et entreprise)** : une ligne par interlocuteur, la personne et son entreprise sont créées ensemble et reliées ;
- **Historique d'échanges (notes)** : échanges passés (appels, rendez-vous…) rattachés à une fiche déjà présente, retrouvée par SIRET, e-mail ou identifiant source.

Déposez le fichier (CSV avec séparateur `;`, `,` ou tabulation, en UTF-8 ou en Windows-1252 comme l'export Excel français : l'encodage et le séparateur sont reconnus tout seuls ; si le fichier est mal lu, ouvrez « Le fichier est mal lu ? Options avancées »). Si vous avez déjà enregistré un **profil** pour ce type de fichier, choisissez-le : les colonnes seront associées d'avance. Puis **Continuer : associer les colonnes**.

### Étape 2 : Associer les colonnes

Pour chaque colonne du fichier, indiquez le champ du TMS correspondant, ou **Ignorer**. L'écran devine les associations d'après les titres de colonnes ; il indique les **champs obligatoires** manquants et prévient si une colonne est vide dans les 5 premières lignes. Vous pouvez enregistrer l'association sous un nom de **profil** : au prochain fichier du même format, les colonnes seront déjà associées. Puis **Continuer : analyser le fichier**.

### Étape 3 : Associer les valeurs (si besoin)

Quand le fichier contient des valeurs inconnues (un secteur, un DAS, un statut, un responsable qui n'existent pas encore), l'écran **Associer les valeurs** vous demande pour chacune de :

- **l'associer à une valeur existante** de la liste, ou à un statut, ou à un utilisateur ;
- **la créer** comme nouvelle valeur de la liste (**Créer la valeur**, ou **Tout créer**) ;
- **laisser vide**.

### Étape 4 : Vérifier la simulation

Rien n'est encore écrit. La **simulation** annonce, pour votre fichier : les fiches qui seraient **créées**, **mises à jour**, **ignorées**, les lignes **en erreur** et les lignes **à vérifier**, avec le détail ligne par ligne. Lignes vides en fin de fichier : ignorées. SIRET abîmé par Excel (notation scientifique du type `7,32829E+13`) : la ligne est refusée avec un message clair, jamais devinée.

Choisissez **Que faire quand la fiche existe déjà ?** :

- **Ignorer la ligne** : la fiche existante n'est pas touchée ;
- **Compléter la fiche existante** : si la ligne correspond **à coup sûr** à une fiche (même SIRET, même e-mail ou téléphone, ou même nom dans la même ville), seuls ses champs **vides** sont remplis avec les valeurs du fichier ; rien de ce qui est déjà saisi n'est écrasé. Une ressemblance seulement possible est ignorée ;
- **Créer quand même** : une nouvelle fiche est créée (impossible si le SIRET existe déjà).

Quand une ligne porte un **identifiant source** déjà importé, c'est cette fiche qui est mise à jour, quelle que soit la stratégie.

### Étape 5 : Lancer l'import

**Lancer l'import**, puis confirmez. Une barre de progression suit l'avancement ; vous pouvez fermer la page et revenir plus tard dans **Imports**.

### Étape 6 : Rapport

Le **Rapport d'import** donne les totaux (fiches créées, mises à jour, ignorées, lignes en erreur) et un fichier **Télécharger le rapport (Excel)** avec le sort de chaque ligne et la raison des erreurs. Corrigez les lignes en erreur dans votre fichier et réimportez-le : les fiches déjà créées ne sont pas dupliquées.

### Annuler un import

Dans **Imports**, **Annuler l'import** ouvre une confirmation qui annonce combien de fiches seront supprimées. L'annulation :

- **supprime** (met à la corbeille) les fiches **créées** par cet import ;
- **conserve** une fiche créée par l'import si quelqu'un l'a **modifiée depuis**, si une note, un rattachement ou une pièce jointe y a été ajouté par ailleurs, ou si elle a été fusionnée. Ces fiches sont signalées dans le rapport ;
- **ne défait pas** les mises à jour : les fiches existantes que l'import avait complétées restent telles quelles.

Réimporter plus tard le même fichier peut restaurer les fiches mises à la corbeille par l'annulation, mais jamais celles que quelqu'un a supprimées volontairement.

L'historique des imports est conservé ; le fichier d'origine (et les valeurs brutes de ses lignes) est supprimé automatiquement au bout de 30 jours.

## 6. Exporter

Dans les listes **Entreprises** et **Personnes** (sur ordinateur), **Exporter (.xlsx)** télécharge un fichier Excel de **la liste telle qu'elle est filtrée**, avec les colonnes choisies dans **Colonnes**. Un contrôle préalable refuse l'export, avec un message, s'il dépasse la limite de lignes (50 000 par défaut) ou si vous exportez trop souvent. Chaque export est tracé dans le journal d'audit. Les valeurs commençant par `=`, `+`, `-` ou `@` sont neutralisées pour ne pas être exécutées par Excel. Les dates et montants sont au format français.

## 7. RGPD

Les données de personnes sont des données personnelles. Le TMS aide à respecter la durée de conservation, le droit d'opposition et le droit d'accès.

### Sur la fiche d'une personne

La section **RGPD** de la fiche affiche :

- la **Base légale** du traitement (intérêt légitime par défaut ; consentement, contrat ou obligation légale) ;
- les **Consentements** par canal (e-mail, SMS, téléphone, prospection) : **Enregistrer un consentement** ajoute un choix daté (**Accordé** ou **Refusé ou retiré**) avec sa source. L'historique est conservé : un nouveau choix s'ajoute sans effacer les précédents ;
- **Opposition à la prospection** : enregistre que la personne ne veut plus être sollicitée ; une mention **Opposé à la prospection** apparaît sur la fiche, dans les listes et la recherche ;
- **Exporter ses données** : fichier contenant toutes les données que le TMS détient sur la personne (droit d'accès) ;
- **Anonymiser maintenant** : efface définitivement les données personnelles de la fiche (nom, coordonnées, notes, pièces jointes…). Pour confirmer, tapez le nom de la personne. Seules des données statistiques (statut, DAS, rattachements) restent. **Cette opération est irréversible.**

### Durée de conservation

Une personne **sans relation contractuelle** (ni client, ni rattachée à une entreprise cliente) dont le **dernier contact** date de plus de **3 ans** (réglable de 1 à 10 ans, voir ci-dessous) est inscrite chaque nuit sur la liste **À anonymiser**. Elle y reste **30 jours**, puis est anonymisée automatiquement. Un nouveau contact (une note, par exemple) la retire de la liste.

### L'écran RGPD

L'écran **RGPD** (menu Commercial, réservé aux personnes ayant ce droit) présente :

- **Anonymisation prévue** : les personnes listées, leur dernier contact, la date d'inscription et la date d'anonymisation prévue. **Conserver** suspend l'anonymisation jusqu'à une date, avec un **motif** (formation en cours, litige…) ;
- **Durée de conservation en cours** : les personnes que vous avez choisi de conserver ;
- **Années après le dernier contact** : la durée de conservation de votre organisme, **Enregistrer** pour la modifier.

Chaque nuit où des personnes sont nouvellement listées, les responsables RGPD reçoivent un e-mail avec le nombre de personnes concernées (sans leurs noms).

## 8. Utilisateurs et droits (administrateur)

Le menu **Administration › Utilisateurs** permet à l'administrateur de l'organisme d'**inviter** des collègues, de **changer leur rôle**, de **désactiver** / **réactiver** un compte, de **renvoyer** ou **révoquer** une invitation et d'attribuer les **DAS suivis** à un Responsable de DAS. Un organisme garde toujours au moins un administrateur actif. Les droits CRM par rôle sont détaillés dans `admin.md`.
