import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type Option } from '@/lib/crm';
import { Calculator, LoaderCircle, Trash2 } from 'lucide-react';
import { useState } from 'react';

export interface TrainingOption extends Option {
    das_id: string;
    /** Price HT per unit for the account (entreprise or particulier), null when missing. */
    price_cents: number | null;
    is_active: boolean;
    /** Funding modes the catalogue offers for this training (empty: no constraint). */
    funding_mode_ids: string[];
}

// A type, not an interface: useForm needs an index-compatible shape.
export type TrainingLineInput = {
    training_id: string;
    quantity: string;
};

export interface TrainingLine {
    id: string;
    training_id: string;
    code: string | null;
    title: string;
    das: string | null;
    quantity: string;
    price_cents: number | null;
    amount_cents: number | null;
    is_active: boolean;
}

export interface TrainingEstimate {
    amount_cents: number;
    amount_display: string;
    missing: string[];
}

const euros = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** Cents as « 1 200,00 € », « - » when missing. */
export function formatCents(cents: number | null | undefined): string {
    return cents === null || cents === undefined ? '-' : euros.format(cents / 100);
}

/** Stored quantity (« 3.00 », « 1.50 ») as typed (« 3 », « 1,5 »). */
export function quantityInput(value: string): string {
    return value.includes('.') ? value.replace(/\.?0+$/, '').replace('.', ',') : value;
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

const ADD = 'add';

/**
 * Trainings of the catalogue on the opportunity form: add, quantity,
 * remove; on an existing opportunity, the amount they propose (computed by
 * the server, never saved) can be carried into the amount.
 */
export function OpportunityTrainingsField({
    lines,
    options,
    errors,
    opportunityId,
    onChange,
    onAdded,
    onApplyAmount,
}: {
    lines: TrainingLineInput[];
    options: TrainingOption[];
    errors: Record<string, string | undefined>;
    opportunityId: string | null;
    onChange: (lines: TrainingLineInput[]) => void;
    /** A training was added (prefills the DAS when none is chosen). */
    onAdded: (option: TrainingOption) => void;
    onApplyAmount: (amount: string) => void;
}) {
    const [estimate, setEstimate] = useState<TrainingEstimate | null>(null);
    const [computing, setComputing] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);

    const byId = new Map(options.map((o) => [o.value, o]));
    const available = options.filter((o) => !lines.some((l) => l.training_id === o.value) && o.is_active);

    const change = (next: TrainingLineInput[]) => {
        setEstimate(null);
        onChange(next);
    };
    const add = (id: string) => {
        const option = byId.get(id);
        if (!option) return;
        change([...lines, { training_id: id, quantity: '1' }]);
        onAdded(option);
    };

    const compute = async () => {
        if (!opportunityId) return;
        setFailure(null);
        setComputing(true);
        try {
            const response = await fetch(route('crm.opportunities.estimate', opportunityId), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify({ training_lines: lines }),
            });
            const json = (await response.json().catch(() => ({}))) as Partial<TrainingEstimate> & { message?: string };
            if (!response.ok) {
                setFailure(
                    response.status === 422
                        ? 'Corrigez les formations avant de calculer le montant.'
                        : (json.message ?? 'Le calcul a échoué, réessayez.'),
                );

                return;
            }
            setEstimate(json as TrainingEstimate);
        } catch {
            setFailure('Le calcul a échoué, réessayez.');
        } finally {
            setComputing(false);
        }
    };

    return (
        <fieldset className="grid gap-3 rounded-xl border p-4">
            <legend className="px-2 text-sm font-semibold">Formations</legend>

            {lines.length === 0 ? (
                <p className="text-muted-foreground text-sm">Aucune formation du catalogue sur cette opportunité.</p>
            ) : (
                <ul className="grid gap-3">
                    {lines.map((line, index) => {
                        const option = byId.get(line.training_id);
                        return (
                            <li key={line.training_id} className="grid gap-2 sm:grid-cols-[1fr_8rem_auto] sm:items-end">
                                <div className="text-sm">
                                    <div className="font-medium">{option?.label ?? 'Formation indisponible'}</div>
                                    <div className="text-muted-foreground text-xs">
                                        {option?.price_cents === null || option === undefined
                                            ? 'Tarif non renseigné pour ce compte'
                                            : `${formatCents(option.price_cents)} HT l'unité`}
                                    </div>
                                    <InputError message={errors[`training_lines.${index}.training_id`]} />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`training_quantity_${index}`} className="text-xs">
                                        Quantité
                                    </Label>
                                    <Input
                                        id={`training_quantity_${index}`}
                                        inputMode="decimal"
                                        value={line.quantity}
                                        onChange={(e) => change(lines.map((l, i) => (i === index ? { ...l, quantity: e.target.value } : l)))}
                                    />
                                    <InputError message={errors[`training_lines.${index}.quantity`]} />
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Retirer ${option?.label ?? 'la formation'}`}
                                    onClick={() => change(lines.filter((_, i) => i !== index))}
                                >
                                    <Trash2 className="h-4 w-4" />
                                </Button>
                            </li>
                        );
                    })}
                </ul>
            )}
            <InputError message={errors.training_lines} />

            {available.length > 0 && (
                <div className="grid gap-1 sm:max-w-md">
                    <Label htmlFor="training_add" className="text-xs">
                        Ajouter une formation
                    </Label>
                    <Select value={ADD} onValueChange={(v) => v !== ADD && add(v)}>
                        <SelectTrigger id="training_add">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ADD}>Choisissez une formation</SelectItem>
                            {available.map((o) => (
                                <SelectItem key={o.value} value={o.value}>
                                    {o.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            )}

            {lines.length > 0 &&
                (opportunityId ? (
                    <div className="grid gap-2 text-sm">
                        <div>
                            <Button type="button" variant="outline" size="sm" onClick={compute} disabled={computing}>
                                {computing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Calculator className="h-4 w-4" />}
                                Calculer le montant proposé
                            </Button>
                        </div>
                        {failure && (
                            <p role="alert" className="text-destructive">
                                {failure}
                            </p>
                        )}
                        {estimate && (
                            <div className="flex flex-wrap items-center gap-2" aria-live="polite">
                                <span>
                                    Montant proposé : <strong>{estimate.amount_display}</strong>
                                </span>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => onApplyAmount(estimate.amount_display.replace(/\s*€$/, ''))}
                                >
                                    Reporter dans le montant estimé
                                </Button>
                            </div>
                        )}
                        {estimate && estimate.missing.length > 0 && <MissingPrices titles={estimate.missing} />}
                    </div>
                ) : (
                    <p className="text-muted-foreground text-sm">
                        Le montant proposé par les formations se calcule une fois l'opportunité enregistrée.
                    </p>
                ))}
        </fieldset>
    );
}

function MissingPrices({ titles }: { titles: string[] }) {
    return (
        <p
            role="status"
            className="rounded-md border border-amber-300 bg-amber-50 p-2 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-50"
        >
            Tarif non renseigné, compté 0 : {titles.join(', ')}.
        </p>
    );
}

/** « Formations » section of the opportunity fiche. */
export function OpportunityTrainingsSummary({ lines, estimate }: { lines: TrainingLine[]; estimate: TrainingEstimate }) {
    if (lines.length === 0) {
        return <p className="text-muted-foreground text-sm">Aucune formation du catalogue sur cette opportunité.</p>;
    }

    return (
        <div className="grid gap-3 text-sm">
            <ul className="grid gap-2">
                {lines.map((l) => (
                    <li key={l.id} className="flex flex-wrap justify-between gap-x-4 gap-y-1 border-b pb-2 last:border-b-0">
                        <span>
                            <span className="font-medium">{l.title}</span>
                            {l.code && <span className="text-muted-foreground"> · {l.code}</span>}
                            {!l.is_active && <span className="text-muted-foreground"> (inactive)</span>}
                        </span>
                        <span className="text-muted-foreground">
                            {quantityInput(l.quantity)} × {formatCents(l.price_cents)} = {formatCents(l.amount_cents)}
                        </span>
                    </li>
                ))}
            </ul>
            <p>
                Montant proposé par les formations : <strong>{estimate.amount_display}</strong>
            </p>
            {estimate.missing.length > 0 && <MissingPrices titles={estimate.missing} />}
        </div>
    );
}
