<?php

namespace App\Modules\Finance\Invoices;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Finance\Billing\BillingCalculator;
use App\Modules\Finance\Billing\CutOff;
use App\Modules\Finance\Billing\Shares;
use App\Modules\Finance\Permissions;
use App\Modules\Finance\Plans\BillingPlans;
use App\Modules\Sessions\Public\Billing;
use Carbon\CarbonImmutable;

/**
 * What is due on a dossier, per recipient (spec 0.9 § 4.3 step 4, § 4.5,
 * § 4 bis 2–6): the share of each recipient cumulated up to a cut-off
 * (BillingCalculator) minus what was already invoiced to it (Ledger), per
 * component (training, dédit, absences). Positive: an invoice to prepare;
 * negative: a credit note (corrected_invoice_id: the recipient's latest
 * validated invoice). A share billed outside the TMS never gets a row: a
 * mention « Part réglée par <financeur> » on the contractor's.
 *
 * Cut-offs, oldest first (each recipient gets the first one where
 * something is due, from its latest invoiced cut-off on — a late change of
 * an invoiced period gives a complement or a credit note):
 *  - « end »: today (Paris), ready once the training is over and closed;
 *  - « monthly »: each month of the sessions up to the current one, ready
 *    while closed, one draft per month;
 *  - « schedule »: each instalment due by today, the last one cut today
 *    (the regularisation at the realised hours waits for the end).
 *
 * The first cut-off not ready stops the search; a dossier with nothing due
 * is shown « bientôt à facturer » with its reason, never as due.
 *
 * @phpstan-type Cut array{cut: CutOff, key: string, month: ?string, from: string, to: string, label: string}
 */
final class Due
{
    public const DEDIT_LABEL = 'Dédit — non imputable sur les fonds de la formation professionnelle';

    public const ABSENCES_LABEL = 'Absences non justifiées — non imputables sur les fonds de la formation professionnelle';

    public const RHYTHMS = [
        BillingPlans::END => 'Fin de formation',
        BillingPlans::MONTHLY => 'Mensuel',
        BillingPlans::SCHEDULE => 'Échéancier',
    ];

    private const COMPONENTS = [InvoiceLine::TRAINING => 'training_cents', InvoiceLine::DEDIT => 'dedit_cents', InvoiceLine::ABSENCES => 'absences_cents'];

    public function __construct(
        private readonly BillingPlans $plans,
        private readonly Fiches $fiches,
    ) {}

    public static function today(): string
    {
        return CarbonImmutable::now('Europe/Paris')->toDateString();
    }

    /**
     * The due rows and the dossiers « bientôt à facturer » of the dossiers
     * $user reaches by finance.invoices.view (and its scope), ordered by
     * dossier number. Filters: company_id, das_id, responsible_user_id;
     * month (« 2026-09 »): the rows whose period ends in that month.
     *
     * @param  array{company_id?: ?string, das_id?: ?string, responsible_user_id?: ?string, month?: ?string}  $filters
     * @return array{rows: list<array<string, mixed>>, soon: list<array<string, mixed>>}
     */
    public function forUser(User $user, array $filters = []): array
    {
        $scope = [
            'company_id' => $filters['company_id'] ?? null,
            'das_id' => $filters['das_id'] ?? null,
            'responsible_user_id' => $filters['responsible_user_id'] ?? null,
        ];
        $started = Billing::dossiersFor($user, [...$scope, 'started_by' => self::today()], Permissions::VIEW)
            ->toBase()->pluck('id')->map(fn ($id) => (string) $id)->all();
        // Cancelled or settled dossiers already invoiced: a credit note or a complement may be due.
        $invoiced = Invoice::query()->whereIn('status', InvoiceStatus::values(InvoiceStatus::ISSUED))
            ->whereIn('dossier_id', Billing::dossiersFor($user, $scope, Permissions::VIEW))
            ->distinct()->pluck('dossier_id')->map(fn ($id) => (string) $id)->all();
        $numbers = Billing::numbers(array_values(array_unique([...$started, ...$invoiced])));
        uasort($numbers, fn (array $a, array $b) => $a['number'] <=> $b['number']);
        $ids = array_map('strval', array_keys($numbers));

        $month = $filters['month'] ?? null;
        $rows = [];
        $soon = [];
        foreach ($ids as $id) {
            $due = $this->forDossier($id);
            foreach ($due['rows'] as $row) {
                if ($month === null || substr($row['period']['to'], 0, 7) === $month) {
                    $rows[] = $row;
                }
            }
            array_push($soon, ...$due['soon']);
        }

        return ['rows' => $rows, 'soon' => $soon];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, soon: list<array<string, mixed>>}
     */
    public function forDossier(string $dossierId): array
    {
        $dossier = Billing::dossier($dossierId);
        if ($dossier === null || $dossier['trashed']) {
            return ['rows' => [], 'soon' => []];
        }
        $ledger = Ledger::of($dossierId);
        $cancelled = $dossier['status'] === 'cancelled';
        if ($cancelled && ! $ledger->issued) {
            return ['rows' => [], 'soon' => []];
        }
        $plan = $this->plans->for($dossierId);
        $today = self::today();
        // A dossier cancelled after an invoice: what was done, today (a credit note or a complement).
        if ($cancelled) {
            $plan = [...$plan, 'rhythm' => BillingPlans::END];
        }
        $cuts = $cancelled ? [self::cancelledCut($dossier, $today)] : self::cuts($dossier, $plan, $today);

        $found = [];
        $reason = null;
        if ($cuts === [] && $plan['rhythm'] === BillingPlans::SCHEDULE) {
            $reason = BillingCalculator::NOT_DUE;
        }
        /** @var array<string, array{enrolments: list<array<string, mixed>>, sessions: list<array<string, mixed>>}> $hours */
        $hours = [];
        foreach ($cuts as $cut) {
            $hours[$cut['month'] ?? ''] ??= Billing::hours($dossierId, $cut['month']);
            $shares = BillingCalculator::cumulative($dossier, $hours[$cut['month'] ?? ''], $plan, $plan['absences'], $cut['cut']);
            if (! $shares->ready) {
                $reason = $shares->reason;

                break;
            }
            foreach ($this->recipientsAt($shares, $ledger) as $key => $recipient) {
                if (isset($found[$key]) || ($ledger->lastCut[$key] ?? '') > $cut['key']) {
                    continue;
                }
                $components = [];
                foreach (self::COMPONENTS as $component => $field) {
                    // A share billed outside the TMS (its funding type marked so after an invoice): 0 here, a credit note at most.
                    $share = ($shares->byRecipient[$key]['external'] ?? false) ? 0 : (int) ($shares->byRecipient[$key][$field] ?? 0);
                    $invoiced = $ledger->invoiced($key, $component);
                    $components[$component] = ['share' => $share, 'invoiced' => $invoiced, 'due' => $share - $invoiced];
                }
                if (array_filter(array_column($components, 'due')) !== []) {
                    $found[$key] = [$recipient, $cut, $shares, $components];
                }
            }
        }

        $labels = $this->labels($dossier, $ledger);
        $head = [
            'id' => $dossier['id'],
            'number' => $dossier['number'],
            'url' => '/dossiers/'.$dossier['id'],
            'contractor' => $labels[$dossier['contractor']['type'].':'.$dossier['contractor']['id']] ?? '—',
        ];
        $rows = [];
        foreach ($found as $key => [$recipient, $cut, $shares, $components]) {
            $rows[] = $this->row($dossier, $plan, $head, $key, $recipient, $cut, $shares, $components, $ledger, $labels);
        }
        usort($rows, fn (array $a, array $b) => [$a['recipient']['role'] === 'funder', $a['cut_off']] <=> [$b['recipient']['role'] === 'funder', $b['cut_off']]);

        $soon = $rows === [] && $reason !== null ? [[
            'key' => $dossier['id'],
            'dossier' => $head,
            'rhythm' => $plan['rhythm'],
            'rhythm_label' => self::RHYTHMS[$plan['rhythm']],
            'reason' => $reason,
        ]] : [];

        return ['rows' => $rows, 'soon' => $soon];
    }

    /**
     * The recipients at a cut-off: the shares not billed outside the TMS,
     * then those met on invoices only (a funding no longer agreed or
     * deleted: its share is 0, a credit note is due).
     *
     * @return array<string, array{role: string, type: string, id: string, funding_id: ?string}>
     */
    private function recipientsAt(Shares $shares, Ledger $ledger): array
    {
        $recipients = [];
        foreach ($shares->byRecipient as $key => $share) {
            if (! $share['external']) {
                $r = $share['recipient'];
                $recipients[$key] = ['role' => $r['type'], 'type' => $r['fiche_type'], 'id' => $r['id'], 'funding_id' => $r['funding_id'] ?? null];
            }
        }

        return $recipients + $ledger->recipients;
    }

    /**
     * @param  array<string, mixed>  $dossier
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $head
     * @param  array{role: string, type: string, id: string, funding_id: ?string}  $recipient
     * @param  Cut  $cut
     * @param  array<string, array{share: int, invoiced: int, due: int}>  $components
     * @param  array<string, string>  $labels
     * @return array<string, mixed>
     */
    private function row(array $dossier, array $plan, array $head, string $key, array $recipient, array $cut, Shares $shares, array $components, Ledger $ledger, array $labels): array
    {
        $total = array_sum(array_column($components, 'due'));
        $kind = $total < 0 ? Invoice::CREDIT_NOTE : Invoice::INVOICE;
        $draft = $ledger->drafts[$key][$kind] ?? null;
        $linesById = array_column($dossier['lines'], null, 'id');

        $lines = $this->trainingLines($dossier, $shares, $key, $components[InvoiceLine::TRAINING]['due'], $ledger, $cut, $labels);
        if ($components[InvoiceLine::DEDIT]['due'] !== 0) {
            $lines[] = self::line(InvoiceLine::DEDIT, self::DEDIT_LABEL, $components[InvoiceLine::DEDIT]['due']);
        }
        if ($components[InvoiceLine::ABSENCES]['due'] !== 0) {
            $lines[] = self::line(InvoiceLine::ABSENCES, self::ABSENCES_LABEL, $components[InvoiceLine::ABSENCES]['due']);
        }
        if ($key === Invoice::CONTRACTOR) {
            foreach ($shares->byRecipient as $share) {
                if ($share['external'] && $share['training_cents'] > 0) {
                    $funder = $labels[$share['recipient']['fiche_type'].':'.$share['recipient']['id']] ?? '—';
                    $lines[] = [...self::line(InvoiceLine::MENTION, 'Part réglée par '.$funder, 0), 'funding_id' => $share['recipient']['funding_id'] ?? null];
                }
            }
        }

        return [
            'key' => $dossier['id'].'|'.$key,
            'dossier' => $head,
            'recipient_key' => $key,
            'recipient' => [...$recipient, 'label' => $labels[$recipient['type'].':'.$recipient['id']] ?? '—'],
            'rhythm' => $plan['rhythm'],
            'rhythm_label' => self::RHYTHMS[$plan['rhythm']],
            'basis' => $plan['basis'],
            'cut_off' => $cut['key'],
            'period' => ['from' => $cut['from'], 'to' => $cut['to'], 'label' => $cut['label']],
            'kind' => $kind,
            'total_cents' => $total,
            'components' => $components,
            'lines' => $lines,
            'split' => array_values(array_map(fn (array $s) => [
                'label' => $labels[$s['recipient']['fiche_type'].':'.$s['recipient']['id']] ?? '—',
                'role' => $s['recipient']['type'],
                'training_cents' => $s['training_cents'],
                'external' => $s['external'],
            ], $shares->byRecipient)),
            'detail' => array_map(fn (array $d) => [
                ...$d,
                'line_label' => $linesById[$d['line_id']]['label'] ?? '—',
                'trainee' => $d['enrolment_id'] === null ? null : ($labels['trainee:'.$d['enrolment_id']] ?? null),
            ], $shares->detail),
            'modified_cents' => $ledger->modified[$key] ?? 0,
            'draft' => $draft === null ? null : ['id' => $draft->id, 'url' => '/finance/invoices/'.$draft->id],
            'corrected_invoice_id' => $kind === Invoice::CREDIT_NOTE ? ($ledger->latestInvoice[$key] ?? null)?->id : null,
        ];
    }

    /**
     * The training lines due to the recipient, one per line of the dossier:
     * each recipient's share spread over the dossier's lines (a funding
     * targeted on a line or a trainee: that line; else in proportion of
     * what each line bills; the contractor: what the funders leave of each
     * line), minus what each line already invoiced to it; the sum is the
     * training due exactly.
     *
     * @param  array<string, mixed>  $dossier
     * @param  Cut  $cut
     * @param  array<string, string>  $labels
     * @return list<array<string, mixed>>
     */
    private function trainingLines(array $dossier, Shares $shares, string $key, int $due, Ledger $ledger, array $cut, array $labels): array
    {
        if ($due === 0) {
            return [];
        }
        $lineAmounts = [];
        foreach ($dossier['lines'] as $line) {
            $lineAmounts[$line['id']] = 0;
        }
        foreach ($shares->detail as $d) {
            $lineAmounts[$d['line_id']] = ($lineAmounts[$d['line_id']] ?? 0) + $d['amount_cents'];
        }
        $fundings = array_column($dossier['fundings'], null, 'id');
        $enrolments = array_column($dossier['enrolments'], null, 'id');

        $allocs = [];
        $taken = array_fill_keys(array_keys($lineAmounts), 0);
        foreach ($shares->byRecipient as $k => $share) {
            if ($k === Invoice::CONTRACTOR) {
                continue;
            }
            $funding = $fundings[$share['recipient']['funding_id'] ?? ''] ?? null;
            $target = match (true) {
                $funding !== null && $funding['enrolment_id'] !== null => [($enrolments[$funding['enrolment_id']]['line_id'] ?? '') => 1],
                $funding !== null && $funding['line_id'] !== null => [$funding['line_id'] => 1],
                default => $lineAmounts,
            };
            $allocs[$k] = self::allocate($share['training_cents'], $target);
            foreach ($allocs[$k] as $lineId => $cents) {
                $taken[$lineId] = ($taken[$lineId] ?? 0) + $cents;
            }
        }
        if (isset($shares->byRecipient[Invoice::CONTRACTOR])) {
            $left = [];
            foreach ($lineAmounts as $lineId => $amount) {
                $left[$lineId] = max(0, $amount - ($taken[$lineId] ?? 0));
            }
            $allocs[Invoice::CONTRACTOR] = self::allocate($shares->byRecipient[Invoice::CONTRACTOR]['training_cents'], array_sum($left) > 0 ? $left : $lineAmounts);
        }

        $cumulated = $allocs[$key] ?? [];
        $invoiced = $ledger->perLine[$key] ?? [];
        $perLine = [];
        foreach (array_unique([...array_keys($cumulated), ...array_keys($invoiced)]) as $lineId) {
            $perLine[(string) $lineId] = ($cumulated[$lineId] ?? 0) - ($invoiced[$lineId] ?? 0);
        }
        // Lines invoiced without a reference (never by the drafts) or rounding: the training due exactly.
        $gap = $due - array_sum($perLine);
        if ($gap !== 0) {
            $first = array_key_first(array_filter($perLine)) ?? array_key_first($perLine) ?? (string) array_key_first($lineAmounts);
            $perLine[$first] = ($perLine[$first] ?? 0) + $gap;
        }

        $funding = $fundings[substr($key, strlen('funding:'))] ?? null;
        $lines = [];
        foreach ($dossier['lines'] as $line) {
            $cents = $perLine[$line['id']] ?? 0;
            if ($cents === 0) {
                continue;
            }
            $details = array_values(array_filter($shares->detail, fn (array $d) => $d['line_id'] === $line['id']
                && ($funding === null || $funding['enrolment_id'] === null || $d['enrolment_id'] === $funding['enrolment_id'])));
            $planned = array_filter(array_column($details, 'planned'), fn ($v) => $v !== null);
            $realised = array_filter(array_column($details, 'realised'), fn ($v) => $v !== null);
            $hours = $planned === [] ? null : [array_sum($realised), array_sum($planned)];

            $label = $line['label'].' — '.$cut['label'];
            if ($hours !== null) {
                $label .= ' — '.self::hours($hours[0]).' réalisées / '.self::hours($hours[1]).' prévues';
            }
            $trainees = $key === Invoice::CONTRACTOR ? [] : array_values(array_filter(array_map(
                fn (array $d) => $d['enrolment_id'] === null ? null : ($labels['trainee:'.$d['enrolment_id']] ?? null), $details)));

            $lines[] = [
                ...self::line(InvoiceLine::TRAINING, $label, $cents),
                'description' => $trainees === [] ? null : 'Stagiaires : '.implode(', ', $trainees),
                'planned_minutes' => $hours[1] ?? null,
                'realised_minutes' => $hours[0] ?? null,
                'dossier_line_id' => $line['id'],
                'enrolment_id' => $funding['enrolment_id'] ?? null,
                'funding_id' => $funding['id'] ?? null,
            ];
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(string $component, string $label, int $cents): array
    {
        return [
            'kind' => InvoiceLine::COMPUTED, 'component' => $component, 'label' => $label, 'description' => null,
            'quantity' => '1.00', 'unit' => 'forfait', 'unit_price_cents' => $cents, 'total_ht_cents' => $cents,
            'planned_minutes' => null, 'realised_minutes' => null, 'dossier_line_id' => null, 'enrolment_id' => null, 'funding_id' => null,
        ];
    }

    /**
     * $cents spread in proportion of $weights (largest remainders, exact sum).
     *
     * @param  array<string, int>  $weights
     * @return array<string, int>
     */
    private static function allocate(int $cents, array $weights): array
    {
        $weights = array_map(fn (int $w) => max(0, $w), $weights);
        $total = array_sum($weights);
        if ($weights === []) {
            return [];
        }
        if ($total === 0) {
            return [(string) array_key_first($weights) => $cents];
        }
        $out = [];
        $rest = [];
        foreach ($weights as $id => $weight) {
            $product = $cents * $weight;
            $out[(string) $id] = intdiv($product, $total);
            $rest[(string) $id] = abs($product % $total);
        }
        $left = $cents - array_sum($out);
        arsort($rest);
        foreach (array_keys($rest) as $id) {
            if ($left === 0) {
                break;
            }
            $out[$id] += $left > 0 ? 1 : -1;
            $left += $left > 0 ? -1 : 1;
        }

        return $out;
    }

    /** 540 → « 9 h », 750 → « 12 h 30 ». */
    public static function hours(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $m === 0 ? "{$h} h" : sprintf('%d h %02d', $h, $m);
    }

    /**
     * The cut-offs of the plan up to today, oldest first.
     *
     * @param  array<string, mixed>  $dossier
     * @param  array<string, mixed>  $plan
     * @return list<Cut>
     */
    private static function cuts(array $dossier, array $plan, string $today): array
    {
        $sessions = array_values(array_filter($dossier['sessions'], fn (array $s) => $s['status'] !== 'cancelled'));
        if ($sessions === []) {
            return [];
        }
        $start = min(array_column($sessions, 'starts_on'));
        $end = max(array_column($sessions, 'ends_on'));
        $whole = ['from' => $start, 'to' => $end, 'label' => self::period($start, $end)];

        if ($plan['rhythm'] === BillingPlans::MONTHLY) {
            $cuts = [];
            $last = min(substr($end, 0, 7), substr($today, 0, 7));
            for ($month = substr($start, 0, 7); $month <= $last; $month = CarbonImmutable::parse($month.'-01')->addMonth()->format('Y-m')) {
                $cut = CutOff::month($month);
                $cuts[] = ['cut' => $cut, 'key' => $month, 'month' => $month, 'from' => $month.'-01', 'to' => $cut->date,
                    'label' => CarbonImmutable::parse($month.'-01')->locale('fr')->translatedFormat('F Y')];
            }

            return $cuts;
        }

        if ($plan['rhythm'] === BillingPlans::SCHEDULE) {
            $cuts = [];
            $percent = 0;
            foreach ($plan['schedule'] as $instalment) {
                if ($instalment['due_on'] > $today) {
                    break;
                }
                $percent += (int) $instalment['percent'];
                $cuts[] = $percent < 100
                    ? ['cut' => CutOff::on($instalment['due_on']), 'key' => $instalment['due_on'], 'month' => null,
                        'from' => $start, 'to' => $instalment['due_on'], 'label' => self::period($start, $instalment['due_on'])]
                    : ['cut' => CutOff::on($today), 'key' => $today, 'month' => null, ...$whole];
            }

            return $cuts;
        }

        return [['cut' => CutOff::on($today), 'key' => $today, 'month' => null, ...$whole]];
    }

    /**
     * The cut-off of a dossier cancelled after an invoice: today, over the
     * dates of its sessions (cancelled ones included).
     *
     * @param  array<string, mixed>  $dossier
     * @return Cut
     */
    private static function cancelledCut(array $dossier, string $today): array
    {
        $from = $dossier['sessions'] === [] ? $today : min(array_column($dossier['sessions'], 'starts_on'));
        $to = $dossier['sessions'] === [] ? $today : max(array_column($dossier['sessions'], 'ends_on'));

        return ['cut' => CutOff::on($today), 'key' => $today, 'month' => null, 'from' => $from, 'to' => $to, 'label' => self::period($from, $to)];
    }

    /** « du 01/09/2026 au 30/09/2026 » */
    private static function period(string $from, string $to): string
    {
        $day = fn (string $d) => CarbonImmutable::parse($d)->format('d/m/Y');

        return 'du '.$day($from).' au '.$day($to);
    }

    /**
     * Names keyed « organisation:<id> », « person:<id> » (the recipients,
     * the contractor and the funders) and « trainee:<enrolment id> ».
     *
     * @param  array<string, mixed>  $dossier
     * @return array<string, string>
     */
    private function labels(array $dossier, Ledger $ledger): array
    {
        $fiches = [$dossier['contractor']['type'] => [$dossier['contractor']['id']]];
        foreach ($dossier['fundings'] as $f) {
            $fiches[$f['funder']['type']][] = $f['funder']['id'];
        }
        foreach ($ledger->recipients as $r) {
            $fiches[$r['type']][] = $r['id'];
        }
        $names = $this->fiches->personLabels(array_values(array_unique([...$fiches['person'] ?? [], ...array_column($dossier['enrolments'], 'person_id')])));

        $labels = [];
        foreach ($this->fiches->organisationLabels(array_values(array_unique($fiches['organisation'] ?? []))) as $id => $o) {
            $labels['organisation:'.$id] = $o['label'];
        }
        foreach ($names as $id => $p) {
            $labels['person:'.$id] = $p['label'];
        }
        foreach ($dossier['enrolments'] as $e) {
            if (isset($names[$e['person_id']])) {
                $labels['trainee:'.$e['id']] = $names[$e['person_id']]['label'];
            }
        }

        return $labels;
    }
}
