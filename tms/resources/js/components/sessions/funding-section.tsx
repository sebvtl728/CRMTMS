import InputError from '@/components/input-error';
import { textareaClass } from '@/components/sessions/enrolment-list';
import { FichePicker, type Picked } from '@/components/sessions/fiche-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatDay, type Option } from '@/lib/crm';
import { ficheRoute } from '@/lib/dossiers';
import { Link, router, useForm } from '@inertiajs/react';
import { LoaderCircle, Plus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type FundingStatus = 'to_request' | 'requested' | 'agreed' | 'refused';

interface FundingInput {
    funder_organisation_id: string | null;
    funder_person_id: string | null;
    funding_type_id: string;
    dossier_line_id: string | null;
    enrolment_id: string | null;
    target: 'dossier' | 'line' | 'enrolment';
    amount: string;
    status: FundingStatus;
    agreement_number: string | null;
    agreed_on: string | null;
    notes: string | null;
    pays_directly: boolean;
    advance_percent: string | null;
    advance_on: string | null;
}

/** A funding as FundingRows sends it. */
export interface FundingRow {
    id: string;
    funder: { type: 'organisation' | 'person'; id: string; label: string; trashed: boolean; can_open: boolean };
    type: string | null;
    target: { kind: 'line' | 'enrolment'; id: string; label: string } | null;
    /** « 2 100,00 € » (server, exact to the cent). */
    amount: string;
    status: FundingStatus;
    status_label: string;
    agreement_number: string | null;
    agreed_on: string | null;
    notes: string | null;
    /** The funder pays the organisme and receives its own invoice. */
    pays_directly: boolean;
    /** « Acompte 30 % à partir du 01/09/2026 », null without advance. */
    advance: string | null;
    /** The stored values for the form; null without the right to change it. */
    input: FundingInput | null;
}

interface BalancePart {
    kind: 'line' | 'enrolment';
    label: string;
    price: string;
    funded: string;
    remaining_cents: number;
    message: string;
    cancelled: boolean;
}

export interface FundingBalance {
    price: string;
    funded: string;
    /** Of the amount funded: « Accordé », and what still waits for an agreement (À demander, Demandé). */
    agreed: string;
    pending: string;
    pending_cents: number;
    remaining_cents: number;
    /** « Reste à financer : 900,00 € » or « Financements supérieurs au prix : 100,00 € ». */
    message: string;
    balanced: boolean;
    parts: BalancePart[];
}

export interface FundingOptions {
    type: Option[];
    status: Option[];
    line: Option[];
    enrolment: Option[];
    /** OPCO of the contractant's collective agreement, proposed for a new funding. */
    suggested_opco?: { id: string | null; label: string; idcc: string; official: string | null } | null;
}

function statusVariant(status: FundingStatus): 'default' | 'secondary' | 'outline' | 'destructive' {
    switch (status) {
        case 'agreed':
            return 'default';
        case 'requested':
            return 'secondary';
        case 'refused':
            return 'destructive';
        default:
            return 'outline';
    }
}

function Alert({ remaining, message }: { remaining: number; message: string }) {
    const tone =
        remaining === 0
            ? 'border-green-600/40 bg-green-600/5 text-green-700 dark:text-green-400'
            : 'border-amber-500/50 bg-amber-500/10 text-amber-800 dark:text-amber-300';

    return (
        <div role={remaining === 0 ? 'status' : 'alert'} className={`rounded-md border px-3 py-2 text-sm font-medium ${tone}`}>
            {message}
        </div>
    );
}

function FundingDialog({
    dossierId,
    funding,
    options,
    onClose,
}: {
    dossierId: string;
    funding: FundingRow | null;
    options: FundingOptions;
    onClose: () => void;
}) {
    const input = funding?.input;
    const [funderType, setFunderType] = useState<'organisation' | 'person'>(funding?.funder.type ?? 'organisation');
    const suggested = funding ? null : (options.suggested_opco ?? null);
    const [funder, setFunder] = useState<Picked | null>(
        funding ? { id: funding.funder.id, label: funding.funder.label } : suggested?.id ? { id: suggested.id, label: suggested.label } : null,
    );
    const [targetKind, setTargetKind] = useState<'none' | 'line' | 'enrolment'>(
        input?.dossier_line_id ? 'line' : input?.enrolment_id ? 'enrolment' : 'none',
    );
    const { data, setData, setError, clearErrors, transform, post, put, processing, errors } = useForm({
        funder_organisation_id: input?.funder_organisation_id ?? suggested?.id ?? '',
        funder_person_id: input?.funder_person_id ?? '',
        funding_type_id: input?.funding_type_id ?? '',
        dossier_line_id: input?.dossier_line_id ?? '',
        enrolment_id: input?.enrolment_id ?? '',
        amount: input?.amount ?? '',
        status: input?.status ?? 'to_request',
        agreement_number: input?.agreement_number ?? '',
        agreed_on: input?.agreed_on ?? '',
        notes: input?.notes ?? '',
        pays_directly: input?.pays_directly ?? false,
        advance_percent: input?.advance_percent ?? '',
        advance_on: input?.advance_on ?? '',
    });
    const fieldErrors = errors as Record<string, string | undefined>;

    const pick = (type: 'organisation' | 'person', p: Picked | null) => {
        setFunderType(type);
        setFunder(p);
        setData((d) => ({
            ...d,
            funder_organisation_id: type === 'organisation' && p ? p.id : '',
            funder_person_id: type === 'person' && p ? p.id : '',
        }));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        clearErrors('funder_organisation_id', 'dossier_line_id', 'enrolment_id');
        // A name typed in the search is not a choice: nothing is sent without a funder picked.
        if (!data.funder_organisation_id && !data.funder_person_id) {
            setError('funder_organisation_id', 'Choisissez le financeur : cliquez sur un résultat de la recherche.');
            return;
        }
        // A line or a trainee chosen as target must be picked (the server checks it too).
        if (targetKind === 'line' && !data.dossier_line_id) {
            setError('dossier_line_id', 'Choisissez la ligne financée.');
            return;
        }
        if (targetKind === 'enrolment' && !data.enrolment_id) {
            setError('enrolment_id', 'Choisissez le stagiaire financé.');
            return;
        }
        transform((d) => ({
            ...d,
            target: targetKind === 'none' ? 'dossier' : targetKind,
            dossier_line_id: targetKind === 'line' ? d.dossier_line_id : '',
            enrolment_id: targetKind === 'enrolment' ? d.enrolment_id : '',
            // An advance is asked only of a funder paying directly.
            advance_percent: d.pays_directly ? d.advance_percent : '',
            advance_on: d.pays_directly && d.advance_percent ? d.advance_on : '',
        }));
        const done = { preserveScroll: true, onSuccess: onClose };
        if (funding) {
            put(route('dossiers.fundings.update', [dossierId, funding.id]), done);
        } else {
            post(route('dossiers.fundings.store', dossierId), done);
        }
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{funding ? 'Modifier le financement' : 'Ajouter un financement'}</DialogTitle>
                        <DialogDescription>
                            Un financement refusé ne compte plus dans le financé : reportez son montant sur un autre financeur si besoin.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="funder">Financeur *</Label>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                size="sm"
                                variant={funderType === 'organisation' ? 'default' : 'outline'}
                                onClick={() => pick('organisation', null)}
                            >
                                Entreprise
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant={funderType === 'person' ? 'default' : 'outline'}
                                onClick={() => pick('person', null)}
                            >
                                Personne
                            </Button>
                        </div>
                        <FichePicker
                            id="funder"
                            kind={funderType}
                            value={funder}
                            onPick={(p) => pick(funderType, p)}
                            placeholder={funderType === 'organisation' ? 'Rechercher une entreprise (OPCO, client…)' : 'Rechercher une personne'}
                        />
                        {suggested?.id && funder?.id === suggested.id && (
                            <p className="text-muted-foreground text-xs">
                                OPCO proposé d'après la convention collective du contractant (IDCC {suggested.idcc}
                                {suggested.official ? ', table officielle de France compétences' : ''}).
                            </p>
                        )}
                        {suggested && !suggested.id && !funder && (
                            <p className="text-muted-foreground text-xs">
                                Selon la table officielle de France compétences, l'OPCO de la convention IDCC {suggested.idcc} est {suggested.label}.
                                Aucune fiche entreprise à ce nom : créez-la pour la choisir.
                            </p>
                        )}
                        <InputError message={fieldErrors.funder ?? fieldErrors.funder_organisation_id ?? fieldErrors.funder_person_id} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="funding_type_id">Type de financement *</Label>
                            <Select value={data.funding_type_id || undefined} onValueChange={(v) => setData('funding_type_id', v)}>
                                <SelectTrigger id="funding_type_id">
                                    <SelectValue placeholder="Choisissez" />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.type.map((o) => (
                                        <SelectItem key={o.value} value={o.value}>
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.funding_type_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="amount">Montant HT (€) *</Label>
                            <Input
                                id="amount"
                                inputMode="decimal"
                                placeholder="2 100,00"
                                value={data.amount}
                                onChange={(e) => setData('amount', e.target.value)}
                            />
                            <InputError message={errors.amount} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="status">État *</Label>
                            <Select value={data.status} onValueChange={(v) => setData('status', v as FundingStatus)}>
                                <SelectTrigger id="status">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.status.map((o) => (
                                        <SelectItem key={o.value} value={o.value}>
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.status} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="agreed_on">Date d'accord</Label>
                            <Input id="agreed_on" type="date" value={data.agreed_on} onChange={(e) => setData('agreed_on', e.target.value)} />
                            <InputError message={errors.agreed_on} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="agreement_number">N° de prise en charge</Label>
                        <Input
                            id="agreement_number"
                            maxLength={100}
                            value={data.agreement_number}
                            onChange={(e) => setData('agreement_number', e.target.value)}
                        />
                        <InputError message={errors.agreement_number} />
                    </div>

                    <div className="grid gap-1">
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                id="pays_directly"
                                type="checkbox"
                                checked={data.pays_directly}
                                onChange={(e) => setData('pays_directly', e.target.checked)}
                            />
                            Paie directement l'organisme
                        </label>
                        <p className="text-muted-foreground text-xs">
                            Le financeur règle l'organisme et reçoit sa propre facture pour sa part. Pour le contractant, la case est sans effet : il
                            reçoit déjà la facture du reste à charge.
                        </p>
                        <InputError message={fieldErrors.pays_directly} />
                    </div>

                    {data.pays_directly && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="advance_percent">Acompte demandé au financeur (%)</Label>
                                <Input
                                    id="advance_percent"
                                    inputMode="numeric"
                                    placeholder="Aucun"
                                    value={data.advance_percent}
                                    onChange={(e) => setData('advance_percent', e.target.value.replace(/\D/g, '').slice(0, 3))}
                                />
                                <InputError message={fieldErrors.advance_percent} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="advance_on">Facturé à partir du</Label>
                                <Input id="advance_on" type="date" value={data.advance_on} onChange={(e) => setData('advance_on', e.target.value)} />
                                <InputError message={fieldErrors.advance_on} />
                            </div>
                            <p className="text-muted-foreground text-xs sm:col-span-2">
                                Accordé, ce financement reçoit une facture d'acompte (pourcentage de son montant) dès cette date, avant la fin de la
                                formation ; la facture finale, au réalisé, en tient compte.
                            </p>
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="target">Finance</Label>
                        <Select value={targetKind} onValueChange={(v) => setTargetKind(v as typeof targetKind)}>
                            <SelectTrigger id="target">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">Le dossier</SelectItem>
                                <SelectItem value="line">Une ligne du dossier</SelectItem>
                                {options.enrolment.length > 0 && (
                                    <SelectItem value="enrolment">Un stagiaire (ligne au prix par stagiaire)</SelectItem>
                                )}
                            </SelectContent>
                        </Select>
                        {targetKind === 'line' && (
                            <Select value={data.dossier_line_id || undefined} onValueChange={(v) => setData('dossier_line_id', v)}>
                                <SelectTrigger aria-label="Ligne financée">
                                    <SelectValue placeholder="Choisissez la ligne" />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.line.map((o) => (
                                        <SelectItem key={o.value} value={o.value}>
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        {targetKind === 'enrolment' && (
                            <Select value={data.enrolment_id || undefined} onValueChange={(v) => setData('enrolment_id', v)}>
                                <SelectTrigger aria-label="Stagiaire financé">
                                    <SelectValue placeholder="Choisissez le stagiaire" />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.enrolment.map((o) => (
                                        <SelectItem key={o.value} value={o.value}>
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        <InputError message={fieldErrors.target ?? errors.dossier_line_id ?? errors.enrolment_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="funding_notes">Notes</Label>
                        <textarea
                            id="funding_notes"
                            rows={3}
                            maxLength={5000}
                            className={textareaClass}
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                        />
                        <InputError message={errors.notes} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Retour
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Enregistrer
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * « Financements » of the dossier page: the fundings, the balance with its
 * alert (« Reste à financer » / « Financements supérieurs au prix ») for the
 * dossier and each line or trainee targeted, the form with the right.
 */
export function FundingSection({
    dossierId,
    fundings,
    balance,
    options,
}: {
    dossierId: string;
    fundings: FundingRow[];
    balance: FundingBalance;
    /** Null without the right to change the fundings or on a closed dossier. */
    options: FundingOptions | null;
}) {
    const [editing, setEditing] = useState<FundingRow | 'new' | null>(null);

    const destroy = (f: FundingRow) => {
        if (window.confirm(`Supprimer le financement de ${f.funder.label} (${f.amount}) ?`)) {
            router.delete(route('dossiers.fundings.destroy', [dossierId, f.id]), { preserveScroll: true });
        }
    };

    return (
        <section aria-label="Financements" className="grid gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-semibold">Financements</h2>
                {options && (
                    <Button type="button" size="sm" onClick={() => setEditing('new')}>
                        <Plus className="h-4 w-4" />
                        Ajouter un financement
                    </Button>
                )}
            </div>

            <Alert remaining={balance.remaining_cents} message={balance.message} />
            {fundings.length > 0 && (
                <p className={`text-sm ${balance.pending_cents > 0 ? 'text-amber-800 dark:text-amber-300' : 'text-muted-foreground'}`}>
                    Accordé : <strong>{balance.agreed}</strong> · En attente d'accord : <strong>{balance.pending}</strong>
                    {balance.pending_cents > 0 && ' (financements « À demander » ou « Demandé »)'}
                </p>
            )}

            {fundings.length === 0 ? (
                <div className="text-muted-foreground rounded-xl border p-6 text-center text-sm">Aucun financement.</div>
            ) : (
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">Financeur</th>
                                <th className="px-3 py-2 font-medium">Type</th>
                                <th className="px-3 py-2 font-medium">Finance</th>
                                <th className="px-3 py-2 font-medium">État</th>
                                <th className="px-3 py-2 font-medium">Prise en charge</th>
                                <th className="px-3 py-2 text-right font-medium">Montant HT</th>
                                {options && <th className="px-3 py-2" />}
                            </tr>
                        </thead>
                        <tbody>
                            {fundings.map((f) => {
                                const name = `${f.funder.label}${f.funder.trashed ? ' (supprimée)' : ''}`;

                                return (
                                    <tr key={f.id} className="border-t align-top">
                                        <td className="px-3 py-2">
                                            {f.funder.can_open ? (
                                                <Link href={ficheRoute(f.funder.type, f.funder.id)} className="hover:underline">
                                                    {name}
                                                </Link>
                                            ) : (
                                                name
                                            )}
                                            {f.pays_directly && (
                                                <Badge variant="outline" className="ml-2">
                                                    Paie directement
                                                </Badge>
                                            )}
                                            {f.advance && <div className="text-muted-foreground text-xs">{f.advance}</div>}
                                            {f.notes && <div className="text-muted-foreground text-xs whitespace-pre-line">{f.notes}</div>}
                                        </td>
                                        <td className="px-3 py-2">{f.type ?? '-'}</td>
                                        <td className="px-3 py-2">{f.target?.label ?? 'Le dossier'}</td>
                                        <td className="px-3 py-2">
                                            <Badge variant={statusVariant(f.status)}>{f.status_label}</Badge>
                                        </td>
                                        <td className="px-3 py-2">
                                            {f.agreement_number ?? '-'}
                                            {f.agreed_on && <div className="text-muted-foreground text-xs">Accord du {formatDay(f.agreed_on)}</div>}
                                        </td>
                                        <td
                                            className={`px-3 py-2 text-right whitespace-nowrap ${f.status === 'refused' ? 'text-muted-foreground line-through' : ''}`}
                                        >
                                            {f.amount}
                                        </td>
                                        {options && (
                                            <td className="px-3 py-2 text-right whitespace-nowrap">
                                                {f.input && (
                                                    <Button type="button" size="sm" variant="ghost" onClick={() => setEditing(f)}>
                                                        Modifier
                                                    </Button>
                                                )}
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    className="text-destructive"
                                                    onClick={() => destroy(f)}
                                                >
                                                    Supprimer
                                                </Button>
                                            </td>
                                        )}
                                    </tr>
                                );
                            })}
                        </tbody>
                        <tfoot>
                            <tr className="border-t font-semibold">
                                <td className="px-3 py-2" colSpan={5}>
                                    Financé (hors refusés) sur {balance.price} HT
                                </td>
                                <td className="px-3 py-2 text-right whitespace-nowrap">{balance.funded}</td>
                                {options && <td />}
                            </tr>
                        </tfoot>
                    </table>
                </div>
            )}

            {balance.parts.length > 0 && (
                <ul className="grid gap-2" aria-label="Reste à financer par ligne ou par stagiaire">
                    {balance.parts.map((p, i) => (
                        <li
                            key={`${p.kind}-${i}`}
                            className="grid gap-1 rounded-md border px-3 py-2 text-sm sm:flex sm:items-center sm:justify-between"
                        >
                            <span>
                                {p.kind === 'line' ? 'Ligne' : 'Stagiaire'} : {p.label} · prix {p.price}, financé {p.funded}
                                {p.cancelled && (
                                    <Badge variant="destructive" className="ml-2">
                                        Inscription annulée
                                    </Badge>
                                )}
                            </span>
                            <span
                                className={
                                    p.remaining_cents === 0 ? 'text-green-700 dark:text-green-400' : 'font-medium text-amber-800 dark:text-amber-300'
                                }
                            >
                                {p.message}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {editing && options && (
                <FundingDialog
                    dossierId={dossierId}
                    funding={editing === 'new' ? null : editing}
                    options={options}
                    onClose={() => setEditing(null)}
                />
            )}
        </section>
    );
}
