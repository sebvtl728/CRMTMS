import { FicheSearch, NextFollowUp, type TypeOption } from '@/components/crm/quick-action';
import { type StageOption } from '@/components/crm/stage-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type Option } from '@/lib/crm';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { CalendarDays, LoaderCircle, MapPin, X } from 'lucide-react';
import { FormEventHandler, useEffect } from 'react';

interface Fiche {
    id: string;
    name: string;
}

interface OpportunityChoice {
    id: string;
    name: string;
    stage_id: string;
}

interface Meeting {
    id: string;
    subject: string;
    purpose: string | null;
    type: string | null;
    time: string;
    location: string | null;
    organisation: Fiche | null;
    person: Fiche | null;
    opportunity: Fiche | null;
    opportunities: OpportunityChoice[];
}

interface PickedFiche {
    kind: 'organisation' | 'person';
    id: string;
    name: string;
    opportunities: OpportunityChoice[];
}

interface ReportProps {
    meetings: Meeting[];
    selected: string | null;
    fiche: PickedFiche | null;
    types: TypeOption[];
    results: Option[];
    stages: StageOption[];
    loss_reasons: Option[];
    can_create: boolean;
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Compte rendu de RDV', href: '/crm/report' }];

const selectClass = 'border-input bg-background focus:ring-ring h-10 w-full rounded-md border px-3 text-sm focus:ring-2 focus:outline-hidden';
const textareaClass =
    'border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-base focus:ring-2 focus:outline-hidden md:text-sm';

/** The meeting's fiche, as shown on its card. */
function ficheName(m: Meeting): string {
    return m.person?.name ?? m.organisation?.name ?? m.opportunity?.name ?? m.subject;
}

/**
 * « Compte rendu de RDV »: one form for the phone. The only meeting of the
 * day is preselected; result, report, stage and follow-up are one tap each.
 */
export default function Report({ meetings, selected, fiche, types, results, stages, loss_reasons, can_create }: ReportProps) {
    const initialMeeting = selected ?? (meetings.length === 1 ? meetings[0].id : '');
    const defaultType = types.find((t) => t.code === 'rendez-vous-physique')?.value ?? '';

    const { data, setData, post, processing, errors, transform, clearErrors } = useForm({
        activity_id: initialMeeting,
        organisation_id: '',
        person_id: '',
        type_id: defaultType,
        result_id: '',
        purpose: meetings.find((m) => m.id === initialMeeting)?.purpose ?? '',
        report: '',
        next_steps: '',
        opportunity_id: '',
        stage_id: '',
        loss_reason_id: '',
        loss_comment: '',
        next_type_id: '',
        next_due_at: '',
    });

    const meeting = meetings.find((m) => m.id === data.activity_id) ?? null;
    const pickedFiche =
        data.activity_id === '' && fiche && (fiche.kind === 'organisation' ? data.organisation_id : data.person_id) === fiche.id ? fiche : null;
    const opportunities = meeting?.opportunities ?? pickedFiche?.opportunities ?? [];
    const opportunity = opportunities.find((o) => o.id === data.opportunity_id) ?? null;
    const target = stages.find((s) => s.value === data.stage_id);

    // The opportunity follows the meeting or fiche chosen (the first one by default), its stage with it.
    const candidates = opportunities.map((o) => o.id).join(',');
    useEffect(() => {
        const first = opportunities[0];
        setData((d) => ({ ...d, opportunity_id: first?.id ?? '', stage_id: first?.stage_id ?? '', loss_reason_id: '', loss_comment: '' }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [candidates]);

    const chooseMeeting = (id: string) => {
        clearErrors();
        setData((d) => {
            const activityId = d.activity_id === id ? '' : id;

            return {
                ...d,
                activity_id: activityId,
                organisation_id: '',
                person_id: '',
                purpose: meetings.find((m) => m.id === activityId)?.purpose ?? '',
            };
        });
    };

    const pickFiche = (kind: 'organisation' | 'person', picked: Fiche) => {
        clearErrors();
        setData((d) => ({
            ...d,
            activity_id: '',
            organisation_id: kind === 'organisation' ? picked.id : '',
            person_id: kind === 'person' ? picked.id : '',
        }));
        router.reload({ only: ['fiche'], data: { [kind]: picked.id } });
    };

    const clearFiche = () => setData((d) => ({ ...d, organisation_id: '', person_id: '' }));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        // The stage is sent only when it changes (an opportunity already lost stays as it is).
        transform((d) => ({ ...d, stage_id: opportunity && d.stage_id !== opportunity.stage_id ? d.stage_id : '' }));
        post(route('crm.report.store'), {
            preserveScroll: true,
            onSuccess: () => {
                setData((d) => ({
                    ...d,
                    activity_id: '',
                    organisation_id: '',
                    person_id: '',
                    result_id: '',
                    purpose: '',
                    report: '',
                    next_steps: '',
                    next_type_id: '',
                    next_due_at: '',
                }));
            },
        });
    };

    const hasSubject = data.activity_id !== '' || data.organisation_id !== '' || data.person_id !== '';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Compte rendu de RDV" />

            <form onSubmit={submit} noValidate className="mx-auto flex w-full max-w-xl min-w-0 flex-col gap-5 p-4 pb-24 md:pb-4">
                <Heading title="Compte rendu de RDV" description="Résultat, compte rendu, étape et prochaine relance en une seule saisie." />

                <fieldset className="grid gap-2">
                    <legend className="mb-2 text-sm font-semibold">Rendez-vous du jour</legend>
                    {meetings.length === 0 && <p className="text-muted-foreground text-sm">Aucun rendez-vous à compléter aujourd'hui.</p>}
                    <ul className="grid gap-2">
                        {meetings.map((m) => (
                            <li key={m.id}>
                                <button
                                    type="button"
                                    aria-pressed={data.activity_id === m.id}
                                    onClick={() => chooseMeeting(m.id)}
                                    className={cn(
                                        'grid w-full gap-0.5 rounded-lg border p-3 text-left text-sm',
                                        data.activity_id === m.id ? 'border-primary ring-primary bg-primary/5 ring-1' : 'hover:bg-muted',
                                    )}
                                >
                                    <span className="flex items-center gap-2">
                                        <CalendarDays className="text-muted-foreground h-4 w-4" aria-hidden />
                                        <span className="font-semibold tabular-nums">{m.time}</span>
                                        <span className="font-medium break-words">{ficheName(m)}</span>
                                    </span>
                                    <span className="text-muted-foreground text-xs break-words">
                                        {m.type}
                                        {m.opportunity && ` · ${m.opportunity.name}`}
                                    </span>
                                    {m.location && (
                                        <span className="text-muted-foreground flex items-center gap-1 text-xs break-words">
                                            <MapPin className="h-3 w-3" aria-hidden />
                                            {m.location}
                                        </span>
                                    )}
                                </button>
                            </li>
                        ))}
                    </ul>
                    <InputError message={errors.activity_id} />

                    {can_create && data.activity_id === '' && (
                        <div className="grid gap-2 rounded-lg border border-dashed p-3">
                            <span className="text-sm font-medium">Sans rendez-vous prévu : choisir une fiche</span>
                            {pickedFiche ? (
                                <div className="flex items-center gap-2 text-sm">
                                    <span className="bg-muted rounded-full px-3 py-1 font-medium">{pickedFiche.name}</span>
                                    <Button type="button" variant="ghost" size="icon" aria-label="Retirer la fiche" onClick={clearFiche}>
                                        <X className="h-4 w-4" />
                                    </Button>
                                </div>
                            ) : (
                                <FicheSearch onPick={pickFiche} hasFiche={false} />
                            )}
                            <InputError
                                message={(errors as Record<string, string | undefined>).fiche ?? errors.organisation_id ?? errors.person_id}
                            />
                            {pickedFiche && (
                                <div className="grid gap-1">
                                    <Label htmlFor="report-type">Type d'action</Label>
                                    <select
                                        id="report-type"
                                        className={selectClass}
                                        value={data.type_id}
                                        onChange={(e) => setData('type_id', e.target.value)}
                                    >
                                        <option value="">Choisissez</option>
                                        {types.map((t) => (
                                            <option key={t.value} value={t.value}>
                                                {t.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.type_id} />
                                </div>
                            )}
                        </div>
                    )}
                </fieldset>

                <fieldset className="grid gap-2" disabled={!hasSubject}>
                    <legend className="mb-2 text-sm font-semibold">Résultat</legend>
                    <div className="flex flex-wrap gap-2">
                        {results.map((r) => (
                            <Button
                                key={r.value}
                                type="button"
                                size="sm"
                                variant={data.result_id === r.value ? 'default' : 'outline'}
                                aria-pressed={data.result_id === r.value}
                                onClick={() => setData('result_id', data.result_id === r.value ? '' : r.value)}
                            >
                                {r.label}
                            </Button>
                        ))}
                    </div>
                    <InputError message={errors.result_id} />
                </fieldset>

                <div className="grid gap-2">
                    <Label htmlFor="report-purpose">Motif</Label>
                    <textarea
                        id="report-purpose"
                        rows={2}
                        maxLength={2000}
                        disabled={!hasSubject}
                        value={data.purpose}
                        onChange={(e) => setData('purpose', e.target.value)}
                        className={textareaClass}
                    />
                    <InputError message={errors.purpose} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="report-text">Compte rendu</Label>
                    <textarea
                        id="report-text"
                        rows={5}
                        maxLength={10000}
                        disabled={!hasSubject}
                        value={data.report}
                        onChange={(e) => setData('report', e.target.value)}
                        className={textareaClass}
                    />
                    <InputError message={errors.report} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="report-next-steps">Suite à donner</Label>
                    <textarea
                        id="report-next-steps"
                        rows={2}
                        maxLength={2000}
                        disabled={!hasSubject}
                        value={data.next_steps}
                        onChange={(e) => setData('next_steps', e.target.value)}
                        className={textareaClass}
                    />
                    <InputError message={errors.next_steps} />
                </div>

                {opportunities.length > 0 && (
                    <fieldset className="grid gap-3 rounded-xl border p-3">
                        <legend className="px-1 text-sm font-semibold">Opportunité</legend>
                        {opportunities.length > 1 && (
                            <div className="grid gap-1">
                                <Label htmlFor="report-opportunity">Opportunité concernée</Label>
                                <select
                                    id="report-opportunity"
                                    className={selectClass}
                                    value={data.opportunity_id}
                                    onChange={(e) => {
                                        const o = opportunities.find((x) => x.id === e.target.value);
                                        setData((d) => ({ ...d, opportunity_id: e.target.value, stage_id: o?.stage_id ?? '' }));
                                    }}
                                >
                                    {opportunities.map((o) => (
                                        <option key={o.id} value={o.id}>
                                            {o.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                        {opportunities.length === 1 && <p className="text-sm font-medium break-words">{opportunities[0].name}</p>}
                        <InputError message={errors.opportunity_id} />
                        <div className="grid gap-1">
                            <Label htmlFor="report-stage">Étape</Label>
                            <select
                                id="report-stage"
                                className={selectClass}
                                value={data.stage_id}
                                onChange={(e) => setData('stage_id', e.target.value)}
                            >
                                {stages.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label} ({s.probability} %)
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.stage_id} />
                        </div>
                        {target?.outcome === 'lost' && opportunity && data.stage_id !== opportunity.stage_id && (
                            <>
                                <div className="grid gap-1">
                                    <Label htmlFor="report-loss-reason">Motif de perte *</Label>
                                    <select
                                        id="report-loss-reason"
                                        className={selectClass}
                                        aria-invalid={Boolean(errors.loss_reason_id)}
                                        value={data.loss_reason_id}
                                        onChange={(e) => setData('loss_reason_id', e.target.value)}
                                    >
                                        <option value="">Choisissez un motif</option>
                                        {loss_reasons.map((r) => (
                                            <option key={r.value} value={r.value}>
                                                {r.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.loss_reason_id} />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="report-loss-comment">Commentaire</Label>
                                    <textarea
                                        id="report-loss-comment"
                                        rows={2}
                                        maxLength={2000}
                                        value={data.loss_comment}
                                        onChange={(e) => setData('loss_comment', e.target.value)}
                                        className={textareaClass}
                                    />
                                    <InputError message={errors.loss_comment} />
                                </div>
                            </>
                        )}
                    </fieldset>
                )}

                <NextFollowUp
                    types={types}
                    typeId={data.next_type_id}
                    dueAt={data.next_due_at}
                    onChange={(field, value) => setData(field, value)}
                    errors={errors}
                />

                <div className="bg-background/95 fixed inset-x-0 bottom-0 z-10 flex gap-2 border-t p-3 md:static md:border-0 md:bg-transparent md:p-0">
                    <Button asChild type="button" variant="outline" className="md:order-last">
                        <Link href={route('crm.planning')}>Planning</Link>
                    </Button>
                    <Button type="submit" className="flex-1 md:flex-none" disabled={processing || !hasSubject}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Enregistrer le compte rendu
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
