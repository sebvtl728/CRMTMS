<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\References\ReferenceList;

/**
 * What a file imports. Affiliations: one person and her entreprise on the
 * same row (« interlocuteurs »). Notes: history of exchanges, the subject
 * found by SIRET, e-mail or source identifier. Trainings: the catalogue,
 * written through Catalogue\Trainings (TrainingRows), for the users who
 * manage it.
 */
enum ImportTarget: string
{
    case Organisations = 'organisations';
    case People = 'people';
    case Affiliations = 'affiliations';
    case Notes = 'notes';
    case Trainings = 'trainings';

    private const ORGANISATION = [
        'name' => 'Raison sociale', 'siret' => 'SIRET', 'naf_code' => 'Code NAF', 'legal_form' => 'Forme juridique',
        'sector' => "Secteur d'activité", 'establishment_type' => "Type d'établissement", 'company_size' => 'Taille',
        'origin' => 'Origine', 'status' => 'Statut', 'responsible' => 'Responsable', 'email' => 'E-mail', 'phone' => 'Téléphone',
        'website' => 'Site web', 'address_line1' => 'Adresse', 'address_line2' => "Complément d'adresse", 'postal_code' => 'Code postal',
        'city' => 'Ville', 'country' => 'Pays', 'notes_summary' => 'Notes', 'das' => 'DAS', 'source_id' => 'Identifiant source',
    ];

    private const PERSON = [
        'civility' => 'Civilité', 'last_name' => 'Nom', 'first_name' => 'Prénom', 'email' => 'E-mail', 'email_secondary' => 'E-mail secondaire',
        'phone' => 'Téléphone', 'mobile' => 'Mobile', 'address_line1' => 'Adresse', 'address_line2' => "Complément d'adresse",
        'postal_code' => 'Code postal', 'city' => 'Ville', 'country' => 'Pays', 'birth_date' => 'Date de naissance',
        'is_individual' => 'Particulier', 'project' => 'Projet', 'professional_situation' => 'Situation professionnelle',
        'funding_mode' => 'Mode de financement', 'origin' => 'Origine', 'status' => 'Statut', 'responsible' => 'Responsable',
        'das' => 'DAS', 'source_id' => 'Identifiant source',
    ];

    private const AFFILIATION = [
        'job_title' => 'Fonction', 'decision_role' => 'Rôle dans la décision', 'work_email' => 'E-mail professionnel',
        'work_phone' => 'Téléphone professionnel', 'is_referent' => 'Référent', 'started_on' => 'Début du rattachement',
    ];

    /** Entreprise columns of an « interlocuteurs » file (prefixed organisation.). */
    private const AFFILIATION_ORGANISATION = [
        'name', 'siret', 'email', 'phone', 'address_line1', 'postal_code', 'city', 'sector', 'status', 'source_id',
    ];

    private const NOTE = [
        'subject_siret' => "SIRET de l'entreprise", 'subject_email' => 'E-mail de la fiche',
        'subject_source_id' => 'Identifiant source de la fiche', 'date' => 'Date', 'body' => 'Note',
    ];

    private const TRAINING = [
        'code' => 'Code', 'title' => 'Intitulé', 'das' => 'DAS', 'duration_hours' => 'Durée (heures)', 'duration_days' => 'Durée (jours)',
        'audience' => 'Public', 'prerequisites' => 'Prérequis', 'price_company' => 'Tarif entreprise', 'price_individual' => 'Tarif particulier',
        'funding_modes' => 'Financements', 'is_active' => 'Active', 'source_id' => 'Identifiant source',
    ];

    /**
     * Header synonyms (comparison keys, see Text::key) per field.
     */
    private const SYNONYMS = [
        'name' => ['raison sociale', 'nom entreprise', 'nom de l entreprise', 'entreprise', 'societe', 'denomination', 'organisation', 'structure', 'etablissement', 'client', 'raison social', 'nom societe', 'nom de la societe', 'nom client', 'nom du client', 'enseigne', 'nom commercial', 'denomination sociale'],
        'siret' => ['siret', 'n siret', 'no siret', 'num siret', 'numero siret', 'numero de siret', 'n de siret', 'siret etablissement'],
        'naf_code' => ['naf', 'code naf', 'ape', 'code ape', 'naf ape'],
        'email' => ['mail', 'email', 'e mail', 'courriel', 'adresse mail', 'adresse e mail', 'adresse email', 'mel', 'adresse electronique', 'adresse courriel'],
        'email_secondary' => ['email 2', 'e mail 2', 'mail 2', 'autre e mail', 'autre email'],
        'phone' => ['tel', 'telephone', 'tel fixe', 'telephone fixe', 'fixe', 'standard', 'n tel', 'ligne fixe', 'numero de telephone', 'n telephone', 'tel bureau', 'telephone bureau'],
        'mobile' => ['portable', 'mobile', 'tel portable', 'telephone portable', 'gsm', 'tel mobile', 'telephone mobile', 'port', 'cellulaire', 'tel port', 'numero de portable', 'numero de mobile'],
        'website' => ['site', 'site web', 'site internet', 'url', 'web', 'www', 'adresse web', 'adresse internet'],
        'address_line1' => ['adresse', 'adresse 1', 'adresse ligne 1', 'rue', 'voie', 'adresse postale', 'numero et rue'],
        'address_line2' => ['adresse 2', 'adresse ligne 2', 'complement', 'complement d adresse', 'complement adresse'],
        'postal_code' => ['cp', 'code postal', 'code postale', 'c p'],
        'city' => ['ville', 'commune', 'localite'],
        'country' => ['pays'],
        'status' => ['statut', 'etat'],
        'responsible' => ['responsable', 'commercial', 'suivi par', 'charge de clientele'],
        'origin' => ['origine', 'source', 'provenance', 'origine du contact'],
        'sector' => ['secteur', 'secteur d activite', 'activite'],
        'legal_form' => ['forme juridique', 'statut juridique'],
        'establishment_type' => ['type d etablissement', 'type etablissement'],
        'company_size' => ['taille', 'effectif', 'tranche d effectif'],
        'das' => ['das', 'domaine', 'domaines'],
        'notes_summary' => ['notes', 'remarques', 'observations', 'commentaires'],
        'source_id' => ['id', 'identifiant', 'identifiant source', 'reference', 'ref', 'code client', 'id externe', 'numero client'],
        'civility' => ['civilite', 'titre', 'civ'],
        'last_name' => ['nom', 'nom de famille', 'nom de naissance', 'nom usuel', 'patronyme', 'nom du contact', 'nom contact'],
        'first_name' => ['prenom', 'prenoms', 'prenom du contact', 'prenom contact'],
        'birth_date' => ['date de naissance', 'naissance', 'ne le', 'nee le', 'date naissance', 'ddn'],
        'is_individual' => ['particulier'],
        'project' => ['projet', 'projet de formation'],
        'professional_situation' => ['situation', 'situation professionnelle'],
        'funding_mode' => ['financement', 'mode de financement'],
        'job_title' => ['fonction', 'poste', 'intitule de poste', 'intitule du poste', 'profession', 'metier'],
        'decision_role' => ['role', 'role dans la decision'],
        'work_email' => ['e mail pro', 'email pro', 'mail pro', 'e mail professionnel', 'email professionnel'],
        'work_phone' => ['tel pro', 'telephone pro', 'telephone professionnel', 'ligne directe'],
        'is_referent' => ['referent', 'contact principal'],
        'started_on' => ['depuis', 'date d entree', 'debut', 'arrivee'],
        'organisation.name' => ['entreprise', 'raison sociale', 'societe', 'nom entreprise', 'nom de l entreprise', 'organisation', 'structure', 'etablissement', 'employeur'],
        'organisation.siret' => ['siret', 'n siret', 'no siret', 'numero siret', 'siret entreprise'],
        'organisation.source_id' => ['id entreprise', 'identifiant entreprise', 'code entreprise'],
        'organisation.postal_code' => ['cp entreprise', 'code postal entreprise'],
        'organisation.city' => ['ville entreprise'],
        'subject_siret' => ['siret', 'n siret', 'numero siret'],
        'subject_email' => ['email', 'e mail', 'mail', 'courriel'],
        'subject_source_id' => ['id', 'identifiant', 'reference', 'ref', 'code client', 'id fiche'],
        'date' => ['date', 'date de l echange', 'date echange', 'date de contact', 'le'],
        'code' => ['code', 'code formation', 'reference', 'ref', 'reference formation', 'code produit'],
        'title' => ['intitule', 'libelle', 'titre', 'formation', 'nom de la formation', 'intitule de la formation', 'designation'],
        'duration_hours' => ['duree heures', 'duree en heures', 'nombre d heures', 'nb heures', 'heures', 'duree h', 'volume horaire'],
        'duration_days' => ['duree jours', 'duree en jours', 'nombre de jours', 'nb jours', 'jours', 'duree j'],
        'audience' => ['public', 'public vise', 'public concerne', 'cible'],
        'prerequisites' => ['prerequis', 'pre requis'],
        'price_company' => ['tarif entreprise', 'prix entreprise', 'tarif ht entreprise', 'prix ht entreprise', 'tarif inter', 'tarif'],
        'price_individual' => ['tarif particulier', 'prix particulier', 'tarif individuel', 'prix individuel', 'tarif ht particulier'],
        'funding_modes' => ['financements', 'financement', 'modes de financement', 'mode de financement', 'dispositifs'],
        'is_active' => ['active', 'actif', 'en catalogue', 'disponible'],
        'body' => ['note', 'notes', 'commentaire', 'commentaires', 'texte', 'compte rendu', 'echange', 'contenu', 'description', 'observations'],
    ];

    /**
     * Abbreviations of a header word, written out before a header is compared
     * loosely (see ColumnGuesser): « Tél » = « téléphone », « CP » = « code postal ».
     */
    public const ABBREVIATIONS = [
        'tel' => 'telephone', 'tph' => 'telephone', 'port' => 'portable', 'mob' => 'mobile', 'mail' => 'email', 'courriel' => 'email',
        'mel' => 'email', 'cp' => 'code postal', 'rs' => 'raison sociale', 'ste' => 'societe', 'dpt' => 'departement',
        'dept' => 'departement', 'adr' => 'adresse', 'naiss' => 'naissance', 'ddn' => 'date naissance', 'fct' => 'fonction',
        'resp' => 'responsable', 'civ' => 'civilite', 'nb' => 'nombre', 'ent' => 'entreprise', 'etab' => 'etablissement',
        'tels' => 'telephone', 'telephones' => 'telephone', 'emails' => 'email', 'mails' => 'email',
    ];

    /** Words left out when a header is compared loosely (« Tél portable du contact » = « tel portable »). */
    public const STOP_WORDS = [
        'de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'a', 'au', 'aux', 'en', 'et', 'ou', 'par', 'pour', 'sur',
        'client', 'clients', 'contact', 'contacts', 'n', 'no', 'num', 'numero', 'perso', 'personnel', 'personnelle', 'principal', 'principale',
    ];

    /**
     * Field proposed for a column whose cells have this shape (see
     * ValueShapes), when the target has one without ambiguity.
     */
    public function shapeField(string $shape): ?string
    {
        $fields = match ($this) {
            self::Organisations => [ValueShapes::EMAIL => 'email', ValueShapes::PHONE => 'phone', ValueShapes::POSTCODE => 'postal_code',
                ValueShapes::SIRET => 'siret', ValueShapes::WEBSITE => 'website'],
            self::People => [ValueShapes::EMAIL => 'email', ValueShapes::MOBILE => 'mobile', ValueShapes::PHONE => 'phone',
                ValueShapes::POSTCODE => 'postal_code', ValueShapes::CIVILITY => 'civility'],
            // A column of dates is rarely the birth date (creation, last contact...): never proposed from the content.
            self::Affiliations => [ValueShapes::EMAIL => 'email', ValueShapes::MOBILE => 'mobile', ValueShapes::PHONE => 'phone',
                ValueShapes::POSTCODE => 'postal_code', ValueShapes::CIVILITY => 'civility', ValueShapes::SIRET => 'organisation.siret'],
            self::Notes => [ValueShapes::EMAIL => 'subject_email', ValueShapes::SIRET => 'subject_siret', ValueShapes::DATE => 'date'],
            self::Trainings => [],
        };

        return $fields[$shape] ?? null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Organisations => 'Entreprises',
            self::People => 'Personnes',
            self::Affiliations => 'Interlocuteurs (personne et entreprise)',
            self::Notes => "Historique d'échanges (notes)",
            self::Trainings => 'Catalogue de formations',
        };
    }

    /**
     * Target fields: code => French label.
     *
     * @return array<string, string>
     */
    public function fields(): array
    {
        return match ($this) {
            self::Organisations => self::ORGANISATION,
            self::People => self::PERSON,
            self::Affiliations => array_diff_key(self::PERSON, ['is_individual' => true]) + self::AFFILIATION + $this->organisationFields(),
            self::Notes => self::NOTE,
            self::Trainings => self::TRAINING,
        };
    }

    /**
     * @return array<string, string>
     */
    private function organisationFields(): array
    {
        $fields = [];
        foreach (self::AFFILIATION_ORGANISATION as $field) {
            $fields['organisation.'.$field] = 'Entreprise : '.mb_strtolower(self::ORGANISATION[$field]);
        }
        $fields['organisation.name'] = 'Entreprise : raison sociale';
        $fields['organisation.siret'] = 'Entreprise : SIRET';
        $fields['organisation.email'] = 'Entreprise : e-mail';
        $fields['organisation.source_id'] = 'Entreprise : identifiant source';

        return $fields;
    }

    /**
     * @return list<string> comparison keys a header may use for $field
     */
    public function synonyms(string $field): array
    {
        return [Text::key($this->fields()[$field] ?? $field), ...self::SYNONYMS[$field] ?? []];
    }

    /**
     * Fields whose values are items of a reference list (DAS: several per cell).
     *
     * @return array<string, ReferenceList>
     */
    public function referenceFields(): array
    {
        $lists = match ($this) {
            self::Organisations => ['legal_form' => ReferenceList::LegalFormOrg, 'sector' => ReferenceList::Sector, 'establishment_type' => ReferenceList::EstablishmentType,
                'company_size' => ReferenceList::CompanySize, 'origin' => ReferenceList::ContactOrigin, 'das' => ReferenceList::Das],
            self::People => ['professional_situation' => ReferenceList::ProfessionalSituation, 'funding_mode' => ReferenceList::FundingMode,
                'origin' => ReferenceList::ContactOrigin, 'das' => ReferenceList::Das],
            self::Affiliations => ['professional_situation' => ReferenceList::ProfessionalSituation, 'funding_mode' => ReferenceList::FundingMode,
                'origin' => ReferenceList::ContactOrigin, 'das' => ReferenceList::Das, 'decision_role' => ReferenceList::DecisionRole,
                'organisation.sector' => ReferenceList::Sector],
            self::Notes => [],
            self::Trainings => ['das' => ReferenceList::Das, 'funding_modes' => ReferenceList::FundingMode],
        };

        return $lists;
    }

    /**
     * Fields the file must provide a column for.
     *
     * @return list<string>
     */
    public function requiredFields(): array
    {
        return match ($this) {
            self::Organisations => ['name'],
            self::People, self::Affiliations => ['last_name'],
            self::Notes => ['body'],
            self::Trainings => ['title', 'das'],
        };
    }

    /**
     * Fields holding the identifier of the fiche in the software it comes from.
     *
     * @return list<string>
     */
    public function identifierFields(): array
    {
        return array_values(array_filter(['source_id', 'organisation.source_id', 'subject_source_id'], fn (string $f) => isset($this->fields()[$f])));
    }

    /**
     * What stops an import with this mapping (French messages): a required
     * field without column, notes without a way to find the fiche,
     * identifiers without their source software (only when the file really
     * fills an identifier column: a closure, as the answer needs a pass over the file).
     *
     * @param  (callable(): bool)|null  $identifiersFilled  null: assumed filled
     * @return list<string>
     */
    public function blockingProblems(FieldMap $map, ?string $sourceSystem, ?callable $identifiersFilled = null): array
    {
        $errors = [];
        foreach ($this->requiredFields() as $field) {
            if (! $map->has($field)) {
                $errors[] = 'Associez une colonne au champ obligatoire « '.$this->fields()[$field].' ».';
            }
        }
        if ($this === self::Notes && ! array_filter(['subject_siret', 'subject_email', 'subject_source_id'], fn ($f) => $map->has($f))) {
            $errors[] = 'Associez une colonne permettant de retrouver la fiche : SIRET, e-mail ou identifiant source.';
        }
        if (array_filter($this->identifierFields(), fn ($f) => $map->has($f)) !== [] && $sourceSystem === null
            && ($identifiersFilled === null || $identifiersFilled())) {
            $errors[] = 'Indiquez le logiciel d\'origine des identifiants (ex. : Formevol) pour pouvoir réimporter sans doublon.';
        }

        return $errors;
    }

    /**
     * Fields mapped through ValueMapper: reference lists, plus status and responsible.
     *
     * @return list<string>
     */
    public function mappableFields(): array
    {
        $fields = array_keys($this->referenceFields());
        foreach (['status', 'responsible', 'organisation.status'] as $field) {
            if (isset($this->fields()[$field])) {
                $fields[] = $field;
            }
        }

        return $fields;
    }
}
