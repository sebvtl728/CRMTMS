<?php

namespace App\Modules\Finance\Invoices;

use App\Modules\Crm\Public\Fiches;
use App\Modules\Finance\Plans\BillingPlans;
use App\Modules\Sessions\Public\Billing;

/**
 * What Due reads for a page of dossiers, loaded once for all of them: the
 * dossiers, their ledgers, plans and labels, and their hours per cut-off
 * month on first use. The number of queries follows the number of distinct
 * months, never the number of dossiers.
 *
 * @phpstan-import-type Plan from BillingPlans
 */
final class DueBatch
{
    /** @var array<string, array<string, mixed>> */
    public readonly array $dossiers;

    /** @var array<string, Ledger> */
    public readonly array $ledgers;

    /** @var array<string, Plan> */
    public readonly array $plans;

    /** @var array<string, array{label: string, trashed: bool, last_name: string, first_name: ?string}> person id => label */
    public readonly array $persons;

    /** @var array<string, array{label: string, trashed: bool}> organisation id => label */
    public readonly array $organisations;

    /** @var array<string, array<string, array{enrolments: list<array<string, mixed>>, sessions: list<array<string, mixed>>}>> month (« » = everything) => dossier => hours */
    private array $hours = [];

    /**
     * @param  list<string>  $ids
     */
    public function __construct(private readonly array $ids, BillingPlans $plans, Fiches $fiches)
    {
        $this->dossiers = Billing::dossiers($ids);
        $this->ledgers = Ledger::ofMany($ids);
        $this->plans = $plans->forMany($ids);

        $people = [];
        $organisations = [];
        foreach ($this->dossiers as $id => $dossier) {
            foreach (Due::labelSubjects($dossier, $this->ledgers[$id]) as $type => $subjects) {
                if ($type === 'person') {
                    array_push($people, ...$subjects);
                } elseif ($type === 'organisation') {
                    array_push($organisations, ...$subjects);
                }
            }
        }
        $this->persons = $fiches->personLabels(array_values(array_unique($people)));
        $this->organisations = $fiches->organisationLabels(array_values(array_unique($organisations)));
    }

    /**
     * @return array{enrolments: list<array<string, mixed>>, sessions: list<array<string, mixed>>}
     */
    public function hours(string $dossierId, ?string $month): array
    {
        $key = $month ?? '';
        $this->hours[$key] ??= Billing::hoursMany($this->ids, $month);

        return $this->hours[$key][strtolower($dossierId)] ?? ['enrolments' => [], 'sessions' => []];
    }
}
