<?php

namespace App\Modules\Qualite\Qualiopi;

/**
 * The national quality referential (Qualiopi), version 2026: annex of the
 * décret n° 2026-728 of 1 August 2026 (JO of 4 August 2026, NOR
 * TRSD2619632D), in force from 1 November 2026, no transitional provision.
 * The same for every organisme: kept in the code, versioned (a later
 * version is added beside it; the organisme's work is keyed by version).
 * Transcribed from an information document (October 2026): to check
 * against Légifrance (VERIFY_NOTICE). The thresholds of indicators 19 and
 * 20 await an order (arrêté).
 *
 * Scopes: all (every provider), certifying (actions leading to a
 * professional certification), alternance (training in alternance), cfa
 * (apprenticeship training centres only), subcontracting (subcontracting or
 * portage salarial), work_based (periods of training in a work situation).
 */
final class QualiopiReferential
{
    public const VERSION = '2026';

    public const DECREE = 'Décret n° 2026-728 du 1er août 2026 (JO du 4 août 2026, NOR TRSD2619632D), en vigueur le 1er novembre 2026';

    public const VERIFY_NOTICE = 'Texte transcrit d’un document d’information : à vérifier sur Légifrance (annexe du décret n° 2026-728).';

    public const SCOPES = [
        'all' => 'Tous les prestataires',
        'certifying' => 'Prestations conduisant à une certification professionnelle',
        'alternance' => 'Formations en alternance',
        'cfa' => 'Spécifique CFA',
        'subcontracting' => 'En cas de sous-traitance ou de portage salarial',
        'work_based' => 'Formation en situation de travail',
    ];

    /** @var array<int, string> */
    public const CRITERIA = [
        1 => 'Les conditions d’information du public sur les prestations proposées, les délais pour y accéder et les résultats obtenus',
        2 => 'L’identification précise des objectifs des prestations proposées et l’adaptation de ces prestations aux publics bénéficiaires',
        3 => 'L’adaptation aux publics bénéficiaires des prestations et des modalités d’accueil, d’accompagnement, de suivi et d’évaluation mises en œuvre',
        4 => 'L’adéquation des moyens pédagogiques, techniques et d’encadrement aux prestations mises en œuvre',
        5 => 'La qualification et le développement des connaissances et compétences des personnels chargés de mettre en œuvre les prestations',
        6 => 'L’inscription et l’investissement du prestataire dans son environnement professionnel',
        7 => 'Le recueil et la prise en compte des appréciations et des réclamations formulées par les parties prenantes aux prestations délivrées',
    ];

    /**
     * number => [criterion, title, text, scope, change (null | new | strengthened | clarified)]
     *
     * @var array<int, array{0: int, 1: string, 2: string, 3: string, 4: string|null}>
     */
    private const INDICATORS = [
        1 => [1, 'Information du public', 'Le prestataire diffuse une information accessible au public, détaillée et vérifiable sur les prestations proposées : prérequis, objectifs, type de reconnaissance de la formation délivrée, durée, modalités pédagogiques et de financements, délais d’accès, tarifs, contacts, méthodes mobilisées et modalités d’évaluation, accessibilité aux personnes en situation de handicap. Sa communication ne comporte aucune mention de nature à induire le public en erreur, notamment sur les conditions d’accès, le contenu, les modalités pédagogiques, le financement des formations, les droits ou l’absence de droits de poursuite d’études conférés par la formation préparée.', 'all', 'strengthened'],
        2 => [1, 'Indicateurs de résultats', 'Le prestataire diffuse des indicateurs de résultats adaptés à la nature des prestations mises en œuvre et des publics accueillis en précisant de manière transparente leurs modalités de calcul ou en s’appuyant sur des dispositifs existants.', 'all', 'strengthened'],
        3 => [1, 'Obtention des certifications', 'Lorsque le prestataire met en œuvre des prestations conduisant à une certification professionnelle, il informe sur les taux d’obtention des certifications préparées, les possibilités de valider un/ou des blocs de compétences, ainsi que sur les équivalences, passerelles, suites de parcours, en particulier les poursuites d’études, et les débouchés.', 'certifying', 'clarified'],
        4 => [2, 'Analyse du besoin', 'Le prestataire analyse le besoin du bénéficiaire en lien avec l’entreprise et/ou le financeur concerné(s).', 'all', null],
        5 => [2, 'Objectifs de la prestation', 'Le prestataire définit les objectifs opérationnels et évaluables de la prestation.', 'all', null],
        6 => [2, 'Mise en œuvre de la prestation', 'Le prestataire établit les contenus et les modalités de mise en œuvre de la prestation, adaptés aux objectifs définis et aux publics bénéficiaires.', 'all', null],
        7 => [2, 'Adéquation contenus / exigences', 'Lorsque le prestataire met en œuvre des prestations conduisant à une certification professionnelle, il s’assure de l’adéquation du ou des contenus de la prestation aux exigences de la certification visée et peut prouver sa capacité à assurer cette certification, y compris en qualité d’organisme habilité.', 'certifying', 'strengthened'],
        8 => [2, 'Positionnement à l’entrée', 'Le prestataire détermine les procédures de positionnement et d’évaluation des acquis à l’entrée de la prestation.', 'all', null],
        9 => [3, 'Conditions de déroulement', 'Le prestataire informe les publics bénéficiaires sur les conditions de déroulement de la prestation.', 'all', 'clarified'],
        10 => [3, 'Adaptation de la prestation', 'Le prestataire met en œuvre et adapte la prestation, l’accompagnement et le suivi aux publics bénéficiaires.', 'all', null],
        11 => [3, 'Atteinte des objectifs', 'Le prestataire évalue l’atteinte par les publics bénéficiaires des objectifs de la prestation.', 'all', null],
        12 => [3, 'Engagement des bénéficiaires', 'Le prestataire décrit et met en œuvre les mesures pour favoriser l’engagement des bénéficiaires et prévenir les ruptures de parcours. Il s’assure de la prévention et du traitement de toute situation de violence, dont les violences sexistes et sexuelles, de harcèlement ou de discrimination dans le cadre de leur formation.', 'all', 'strengthened'],
        13 => [3, 'Coordination des apprentis', 'Pour les formations en alternance, le prestataire, en lien avec l’entreprise, anticipe avec l’apprenant les missions confiées, à court, moyen et long terme, et assure la coordination et la progressivité des apprentissages réalisés en centre de formation et en entreprise.', 'alternance', null],
        14 => [3, 'Exercice de la citoyenneté', 'Le prestataire met en œuvre un accompagnement socio-professionnel, éducatif et relatif à l’exercice de la citoyenneté. Il dispose d’une procédure de traitement sans délai des situations de rupture liées à des difficultés, violences ou discriminations subies par l’apprenant en formation ou dans l’entreprise d’accueil.', 'cfa', 'strengthened'],
        15 => [3, 'Droits et devoirs de l’apprenti', 'Le prestataire informe les apprentis de leurs droits et devoirs en tant qu’apprentis et salariés ainsi que des règles applicables en matière de santé et de sécurité en milieu professionnel de manière renforcée lorsqu’ils sont mineurs. Il les informe des dispositifs d’accompagnement, de prévention et de signalement des situations de violences, de harcèlement moral ou sexuel, d’agissements sexistes et de discriminations, ainsi que des interlocuteurs susceptibles de les accompagner. Il communique les coordonnées du médiateur de l’apprentissage et veille à signaler les dysfonctionnements à l’inspection du travail.', 'cfa', 'strengthened'],
        16 => [3, 'Présentation à la certification', 'Lorsque le prestataire met en œuvre des formations conduisant à une certification professionnelle, il s’assure que les conditions de présentation des bénéficiaires à la certification respectent les exigences formelles de l’autorité de certification.', 'certifying', null],
        17 => [4, 'Moyens humains et techniques', 'Le prestataire met à disposition ou s’assure de la mise à disposition des moyens humains et techniques adaptés et d’un environnement approprié (conditions, locaux, équipements, plateaux techniques…).', 'all', null],
        18 => [4, 'Coordination des acteurs', 'Le prestataire mobilise et coordonne les différents intervenants internes et/ou externes (pédagogiques, administratifs, logistiques, commerciaux…).', 'all', null],
        19 => [4, 'Ressources pédagogiques', 'Le prestataire met à disposition du bénéficiaire des ressources pédagogiques et permet à celui-ci de se les approprier. Lorsque des modules pédagogiques sont réalisés à distance, le prestataire vérifie l’effectivité de leur suivi par les apprenants. Au-delà d’un nombre d’intervenants par formation, fixé par arrêté du ministre chargé de la formation professionnelle, le prestataire dispose d’un référent pédagogique par formation chargé d’assurer la coordination pédagogique entre les intervenants.', 'all', 'strengthened'],
        20 => [4, 'Personnels dédiés', 'Le prestataire dispose d’un personnel dédié à l’appui à la mobilité nationale et internationale, d’un référent handicap et d’un conseil de perfectionnement. Il s’assure de la qualité du pilotage de la formation, de la participation des apprentis, formateurs et entreprises à sa gouvernance. Lorsque la proportion d’heures d’enseignement réalisées par des intervenants permanents est inférieure à un seuil fixé par arrêté du ministre chargé de la formation professionnelle, le prestataire s’assure de la mise en œuvre de modalités renforcées de supervision pédagogique et de contrôle de la qualité des interventions.', 'cfa', 'strengthened'],
        21 => [5, 'Compétences des acteurs', 'Le prestataire détermine, mobilise et évalue les compétences des différents intervenants internes et/ou externes, adaptées aux prestations.', 'all', null],
        22 => [5, 'Gestion des compétences', 'Le prestataire entretient et développe les compétences de ses salariés, adaptées aux prestations qu’il délivre.', 'all', null],
        23 => [6, 'Veille légale et réglementaire', 'Le prestataire réalise une veille légale et réglementaire sur le champ de la formation professionnelle et en exploite les enseignements.', 'all', null],
        24 => [6, 'Veille emplois et métiers', 'Le prestataire réalise une veille sur les évolutions des compétences, des métiers et des emplois dans ses secteurs d’intervention, et en exploite les enseignements.', 'all', null],
        25 => [6, 'Veille technologique', 'Le prestataire réalise une veille sur les innovations pédagogiques et technologiques permettant une évolution de ses prestations et en exploite les enseignements.', 'all', null],
        26 => [6, 'Public en situation de handicap', 'Le prestataire mobilise les expertises, outils et réseaux nécessaires pour accueillir, accompagner/former ou orienter les publics en situation de handicap.', 'all', null],
        27 => [6, 'Sous-traitance / portage salarial', 'Lorsque le prestataire fait appel à la sous-traitance ou au portage salarial, il s’assure du respect de la conformité au présent référentiel et en assure la traçabilité dans les contrats de sous-traitance.', 'subcontracting', 'strengthened'],
        28 => [6, 'Formation en situation de travail', 'Lorsque les prestations dispensées au bénéficiaire comprennent des périodes de formation en situation de travail, le prestataire mobilise son réseau de partenaires socio-économiques pour co-construire l’ingénierie de formation et favoriser l’accueil en entreprise.', 'work_based', null],
        29 => [6, 'Insertion professionnelle', 'Le prestataire développe des actions qui concourent à l’insertion professionnelle ou la poursuite d’étude par la voie de l’apprentissage ou par toute autre voie permettant de développer leurs connaissances et leurs compétences.', 'cfa', null],
        30 => [7, 'Recueil des appréciations', 'Le prestataire recueille les appréciations des parties prenantes : bénéficiaires, financeurs (le cas échéant), équipes pédagogiques et entreprises concernées.', 'all', 'clarified'],
        31 => [7, 'Traitement des réclamations', 'Le prestataire met en œuvre des modalités de traitement des difficultés rencontrées par les parties prenantes, des réclamations exprimées par ces dernières ainsi que des aléas survenus en cours de prestation.', 'all', 'clarified'],
        32 => [7, 'Mesures d’amélioration continue', 'Le prestataire met en place une démarche d’amélioration continue à partir de l’analyse des appréciations et des réclamations, ainsi qu’une analyse des risques sur la qualité des formations délivrées.', 'all', 'strengthened'],
        33 => [7, 'Dispositif d’évaluation des contenus et des enseignements par les apprenants', 'Le prestataire met en place un dispositif d’évaluation des contenus et des enseignements par les apprenants, distinct du recueil général de satisfaction, dont les résultats sont partagés avec les équipes pédagogiques et donnent lieu à la formalisation d’une démarche d’amélioration continue, dont il mesure périodiquement l’efficacité.', 'cfa', 'new'],
    ];

    public const CHANGES = ['new' => 'Nouveau', 'strengthened' => 'Renforcé', 'clarified' => 'Précisé'];

    /**
     * Every indicator, by number.
     *
     * @return array<int, array{number: int, criterion: int, title: string, text: string, scope: string, scope_label: string, change: string|null, change_label: string|null}>
     */
    public static function indicators(): array
    {
        $all = [];
        foreach (self::INDICATORS as $number => [$criterion, $title, $text, $scope, $change]) {
            $all[$number] = [
                'number' => $number,
                'criterion' => $criterion,
                'title' => $title,
                'text' => $text,
                'scope' => $scope,
                'scope_label' => self::SCOPES[$scope],
                'change' => $change,
                'change_label' => $change === null ? null : self::CHANGES[$change],
            ];
        }

        return $all;
    }

    /** @return array{number: int, criterion: int, title: string, text: string, scope: string, scope_label: string, change: string|null, change_label: string|null}|null */
    public static function indicator(int $number): ?array
    {
        return self::indicators()[$number] ?? null;
    }
}
