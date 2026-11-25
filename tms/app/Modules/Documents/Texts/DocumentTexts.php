<?php

namespace App\Modules\Documents\Texts;

use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * The texts of the training documents. The layout is in the code (Blade);
 * each document has named blocks whose default text the TMS provides
 * (Code du travail, articles L6353-1 to L6353-7). The organisme may write
 * its own text of a block, for itself (company null) or for one company:
 * company text, else organisme text, else default. A text is plain: no
 * tag, only the fields of FIELDS, replaced literally when the document is
 * rendered (Blade escapes the result). A required block cannot be emptied.
 */
final class DocumentTexts
{
    /** The fields a text may hold. */
    public const FIELDS = ['{stagiaire}', '{stagiaires}', '{formation}', '{dates}', '{duree}', '{lieu}', '{prix}', '{contractant}', '{societe}', '{numero_dossier}', '{heures_realisees}', '{date_entree}', '{date_sortie}', '{apprenti}', '{employeur}', '{tuteur}', '{type_contrat}', '{debut_contrat}', '{fin_contrat}', '{heures_centre}', '{code_rncp}', '{opco}'];

    public const MAX_LENGTH = 5000;

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @return array<string, array{label: string, default: string, required: bool}>
     */
    public static function blocks(DocumentType $type): array
    {
        return match ($type) {
            DocumentType::Convention => [
                'preambule' => self::block('Préambule', 'La présente convention est conclue entre {societe}, organisme de formation, et {contractant}, en application des articles L6353-1 et L6353-2 du Code du travail.'),
                'objet' => self::block('Objet', "{societe} s'engage à organiser l'action de formation « {formation} » au bénéfice de : {stagiaires}. Cette action entre dans la catégorie des actions de formation prévues à l'article L6313-1 du Code du travail."),
                'nature_duree' => self::block('Nature, durée et lieu', "L'action se déroule {dates}, pour une durée de {duree}, à l'adresse suivante : {lieu}. Le programme détaillé (objectifs, contenu, prérequis) est annexé à la présente convention."),
                'effectif' => self::block('Effectif', 'Les stagiaires désignés par {contractant} sont listés ci-dessus.'),
                'moyens' => self::block('Moyens pédagogiques et techniques', "Les moyens pédagogiques, techniques et d'encadrement sont décrits dans le programme annexé. L'assiduité est attestée par des feuilles d'émargement signées par demi-journée."),
                'sanction' => self::block('Suivi et sanction de la formation', "À l'issue de la formation, {societe} remet à chaque stagiaire une attestation de fin de formation et un certificat de réalisation."),
                'prix' => self::block('Dispositions financières', "Le prix de l'action de formation est de {prix}. Il est facturé selon les modalités prévues au dossier {numero_dossier} ; les sommes prises en charge par un financeur lui sont facturées directement lorsqu'il règle l'organisme.", true),
                'dedit' => self::block('Dédit et abandon', "En cas d'abandon d'un stagiaire en cours de formation, seules les heures réalisées sont facturées, au prorata du prix. En cas de force majeure dûment reconnue, aucune somme autre que les heures réalisées n'est due.", true),
                'litiges' => self::block('Litiges', "Si une contestation ou un différend ne peut être réglé à l'amiable, le tribunal compétent est celui du siège de {societe}."),
            ],
            DocumentType::Contract => [
                'objet' => self::block('Objet', "Le présent contrat est conclu entre {societe}, organisme de formation, et {stagiaire}, en application des articles L6353-3 à L6353-7 du Code du travail, pour l'action de formation « {formation} »."),
                'nature_duree' => self::block('Nature, durée et lieu', "L'action se déroule {dates}, pour une durée de {duree}, à l'adresse suivante : {lieu}. Le programme détaillé (objectifs, contenu, modalités) est annexé au présent contrat."),
                'prerequis' => self::block('Niveau de connaissances préalables', 'Les prérequis et le public visé figurent au programme annexé.'),
                'moyens' => self::block('Moyens pédagogiques et formateurs', 'Les moyens pédagogiques et techniques, ainsi que la qualité des formateurs, sont décrits dans le programme annexé.'),
                'sanction' => self::block('Sanction de la formation', "À l'issue de la formation, {societe} remet au stagiaire une attestation de fin de formation et un certificat de réalisation."),
                'prix' => self::block('Prix', 'Le prix de la formation est de {prix}, payable selon les modalités ci-dessous.', true),
                'retractation' => self::block('Délai de rétractation', 'Dans un délai de 14 jours à compter de la signature du présent contrat, le stagiaire peut se rétracter par lettre recommandée avec avis de réception (article L6353-5 du Code du travail).', true),
                'paiement' => self::block('Modalités de paiement', "Aucune somme ne peut être exigée du stagiaire avant l'expiration du délai de rétractation. À l'expiration de ce délai, il ne peut être payé une somme supérieure à 30 % du prix convenu ; le solde est échelonné au fur et à mesure du déroulement de l'action (article L6353-6 du Code du travail).", true),
                'abandon' => self::block('Interruption de la formation', 'Si, par suite de force majeure dûment reconnue, le stagiaire est empêché de suivre la formation, il peut rompre le contrat : seules les prestations effectivement dispensées sont dues, au prorata de leur valeur (article L6353-7 du Code du travail).'),
            ],
            DocumentType::Convocation => [
                'message' => self::block('Message', "Madame, Monsieur,\n\nNous avons le plaisir de vous convier à la formation « {formation} », {dates}, d'une durée de {duree}, à l'adresse suivante : {lieu}. Les horaires figurent ci-dessous.", true),
                'pratique' => self::block('Informations pratiques', "Merci de vous présenter dix minutes avant le début de la formation. Le règlement intérieur vous sera remis le premier jour. Pour toute question, ou si vous avez besoin d'une adaptation liée à une situation de handicap, contactez {societe}."),
            ],
            DocumentType::CompletionCertificate => [
                'attestation' => self::block('Attestation', '{societe} atteste que {stagiaire} a suivi la formation « {formation} », {dates}.', true),
                'assiduite' => self::block('Assiduité', "Présence du {date_entree} au {date_sortie} : {heures_realisees} réalisées sur {duree} prévues. Les objectifs et la nature de l'action figurent au programme de la formation."),
            ],
            DocumentType::RealisationCertificate => [],
            // Default texts of the alternance conventions: to be checked by the organisme (Administration › Modèles de documents).
            DocumentType::ApprenticeshipConvention => self::alternanceBlocks('apprentissage', "le maître d'apprentissage"),
            DocumentType::ProfessionalisationConvention => self::alternanceBlocks('professionnalisation', 'le tuteur'),
        };
    }

    /**
     * The blocks of an alternance convention: the same frame for an
     * apprenticeship and a professionalisation contract.
     *
     * @return array<string, array{label: string, default: string, required: bool}>
     */
    private static function alternanceBlocks(string $kind, string $tutorRole): array
    {
        return [
            'parties' => self::block('Parties', "La présente convention est conclue entre {societe}, organisme de formation (centre de formation), et {employeur}, employeur, pour la formation de {apprenti} dans le cadre de son {type_contrat}.", true),
            'objet' => self::block('Objet', "La convention fixe les conditions dans lesquelles {societe} assure la formation « {formation} » (titre ou diplôme visé, code RNCP {code_rncp}) de {apprenti}, salarié(e) de {employeur} par un contrat de {$kind} conclu du {debut_contrat} au {fin_contrat}.", true),
            'organisation' => self::block('Organisation de la formation en alternance', "La formation alterne des périodes en centre de formation, pour une durée de {heures_centre}, {dates}, à l'adresse suivante : {lieu}, et des périodes en entreprise. Le planning de l'alternance et le programme sont annexés à la présente convention."),
            'centre' => self::block('Engagements du centre de formation', "{societe} s'engage à dispenser les enseignements prévus au programme, à suivre l'assiduité de l'apprenant par des feuilles d'émargement, à informer l'employeur de ses absences et à évaluer sa progression, en lien avec l'entreprise."),
            'employeur' => self::block("Engagements de l'employeur", "{employeur} s'engage à permettre à {apprenti} de suivre l'ensemble de la formation en centre, temps compris dans son temps de travail, et à lui confier des activités en rapport avec la qualification visée, sous la responsabilité de {$tutorRole}, {tuteur}."),
            'suivi' => self::block('Suivi et évaluation', "Le suivi de l'alternance est assuré conjointement par {societe} et {$tutorRole} : livret de suivi, échanges réguliers et visites en entreprise. Les évaluations prévues au référentiel de la certification sont organisées par {societe}."),
            'financement' => self::block('Dispositions financières', "Le coût de la formation est pris en charge par l'opérateur de compétences de l'entreprise ({opco}) selon le niveau de prise en charge qu'il fixe. Le prix de la formation est de {prix} ; tout reste à charge éventuel de l'employeur est facturé selon les modalités prévues au dossier {numero_dossier}.", true),
            'rupture' => self::block('Rupture du contrat', "En cas de rupture du contrat de {$kind}, l'employeur en informe sans délai {societe}. La présente convention prend fin à la date de la rupture ; seules les heures de formation réalisées restent dues."),
            'litiges' => self::block('Litiges', "Si une contestation ou un différend ne peut être réglé à l'amiable, le tribunal compétent est celui du siège de {societe}."),
        ];
    }

    /**
     * The text of each block for a company (null: the organisme's).
     *
     * @return array<string, string>
     */
    public function resolve(DocumentType $type, ?string $companyId): array
    {
        $rows = DocumentText::query()->where('type', $type->value)
            ->where(fn ($q) => $q->whereNull('company_id')->when($companyId !== null, fn ($q) => $q->orWhere('company_id', $companyId)))
            ->get();
        $texts = [];
        foreach (self::blocks($type) as $key => $block) {
            $own = $rows->first(fn (DocumentText $t) => $t->block === $key && $companyId !== null && $t->company_id === $companyId)
                ?? $rows->first(fn (DocumentText $t) => $t->block === $key && $t->company_id === null);
            $texts[$key] = $own->body ?? $block['default'];
        }

        return $texts;
    }

    /**
     * The blocks with their stored text for exactly this level (company or organisme).
     *
     * @return array<string, string>
     */
    public function stored(DocumentType $type, ?string $companyId): array
    {
        return DocumentText::query()->where('type', $type->value)
            ->when($companyId === null, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->where('company_id', $companyId))
            ->pluck('body', 'block')->all();
    }

    /**
     * @throws ValidationException
     */
    public function save(DocumentType $type, ?string $companyId, string $block, string $body, User $by): void
    {
        $definition = self::blocks($type)[$block] ?? throw new \InvalidArgumentException("Unknown block {$block}.");
        $body = trim(str_replace("\r\n", "\n", $body));
        $error = match (true) {
            $body === '' && $definition['required'] => 'Ce texte est obligatoire.',
            $body === '' => 'Saisissez un texte, ou revenez au texte d\'origine.',
            mb_strlen($body) > self::MAX_LENGTH => 'Ce texte est trop long ('.self::MAX_LENGTH.' caractères au plus).',
            preg_match('/<[^>]*>/', $body) === 1 => 'Le texte ne peut pas contenir de balises.',
            default => self::unknownField($body),
        };
        if ($error !== null) {
            throw ValidationException::withMessages(['body' => $error]);
        }

        $row = DocumentText::query()->where('type', $type->value)->where('block', $block)
            ->when($companyId === null, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->where('company_id', $companyId))
            ->first() ?? new DocumentText(['type' => $type->value, 'block' => $block, 'company_id' => $companyId]);
        $row->fill(['body' => $body, 'updated_by' => $by->id])->save();
    }

    public function reset(DocumentType $type, ?string $companyId, string $block, User $by): void
    {
        DocumentText::query()->where('type', $type->value)->where('block', $block)
            ->when($companyId === null, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->where('company_id', $companyId))
            ->get()->each(fn (DocumentText $t) => $t->delete());
        $this->audit->record('document_text.reset', null, [], ['type' => $type->value, 'block' => $block, 'company_id' => $companyId], $by);
    }

    /**
     * The text with each known field replaced by its value, literally (the
     * caller escapes the result); an unknown brace stays as typed.
     *
     * @param  array<string, string>  $values  field => value
     */
    public static function fill(string $text, array $values): string
    {
        return strtr($text, array_intersect_key($values, array_flip(self::FIELDS)));
    }

    private static function unknownField(string $body): ?string
    {
        preg_match_all('/\{[^{}\s]*\}/u', $body, $found);
        foreach ($found[0] as $field) {
            if (! in_array($field, self::FIELDS, true)) {
                return "Champ inconnu : {$field}.";
            }
        }

        return null;
    }

    /** @return array{label: string, default: string, required: bool} */
    private static function block(string $label, string $default, bool $required = false): array
    {
        return ['label' => $label, 'default' => $default, 'required' => $required];
    }
}
