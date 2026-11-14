import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatAmount, todayIso, type Option } from '@/lib/crm';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect } from 'react';

export interface QuotePipeline {
    opportunity_id: string;
    open: boolean;
    won_stage_id: string | null;
    lost_stage_id: string | null;
    loss_reasons: Option[];
}

export interface ProposeWon {
    amount_ht: string;
    amount_display: string;
    won_on: string;
}

const textareaClass =
    'border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden';

/**
 * The customer's answer: « Accepté » (date, today by default, never in the
 * future nor before the quote) or « Refusé » (date and optional reason).
 */
export function QuoteAnswerDialog({
    quoteId,
    number,
    issuedOn,
    answer,
    onClose,
}: {
    quoteId: string;
    number: string;
    issuedOn: string;
    answer: 'accept' | 'refuse' | null;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        accepted_on: todayIso(),
        refused_on: todayIso(),
        refusal_reason: '',
    });

    useEffect(() => {
        if (answer) {
            reset();
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [answer]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route(answer === 'accept' ? 'crm.quotes.accept' : 'crm.quotes.refuse', quoteId), { preserveScroll: true, onSuccess: () => onClose() });
    };

    const field = answer === 'accept' ? 'accepted_on' : 'refused_on';

    return (
        <Dialog open={answer !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>{answer === 'accept' ? `Devis ${number} accepté` : `Devis ${number} refusé`}</DialogTitle>
                        <DialogDescription>La réponse du client est enregistrée dans l'historique du devis.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="quote-answer-on">{answer === 'accept' ? "Date d'acceptation" : 'Date du refus'}</Label>
                        <Input
                            id="quote-answer-on"
                            type="date"
                            min={issuedOn}
                            max={todayIso()}
                            value={data[field]}
                            onChange={(e) => setData(field, e.target.value)}
                        />
                        <InputError message={errors[field]} />
                    </div>
                    {answer === 'refuse' && (
                        <div className="grid gap-2">
                            <Label htmlFor="quote-refusal-reason">Motif (facultatif)</Label>
                            <textarea
                                id="quote-refusal-reason"
                                rows={3}
                                maxLength={2000}
                                value={data.refusal_reason}
                                onChange={(e) => setData('refusal_reason', e.target.value)}
                                className={textareaClass}
                            />
                            <InputError message={errors.refusal_reason} />
                        </div>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Annuler
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
 * After the answer: proposes to move the opportunity (« Gagné » with the
 * date of acceptance and, ticked by default, the quote's total HT as
 * amount; « Perdu » with a required reason), through the stage endpoint.
 * The user may decline.
 */
export function QuoteOutcomeDialog({
    quoteId,
    pipeline,
    proposeWon,
    proposeLost,
    onClose,
}: {
    quoteId: string;
    pipeline: QuotePipeline;
    proposeWon: ProposeWon | null;
    proposeLost: boolean;
    onClose: () => void;
}) {
    const won = proposeWon !== null && pipeline.won_stage_id !== null;
    const lost = !won && proposeLost && pipeline.lost_stage_id !== null;
    const open = pipeline.open && (won || lost);

    const { data, setData, transform, post, processing, errors } = useForm({
        update_amount: true as boolean,
        loss_reason_id: '',
        loss_comment: '',
    });

    transform((d) =>
        won
            ? { stage_id: pipeline.won_stage_id, won_on: proposeWon?.won_on, ...(d.update_amount ? { quote_id: quoteId } : {}) }
            : { stage_id: pipeline.lost_stage_id, loss_reason_id: d.loss_reason_id, loss_comment: d.loss_comment },
    );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('crm.opportunities.stage', pipeline.opportunity_id), { preserveScroll: true, onSuccess: () => onClose() });
    };

    const errs = errors as Record<string, string | undefined>;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>{won ? "Passer l'opportunité en « Gagné » ?" : "Passer l'opportunité en « Perdu » ?"}</DialogTitle>
                        <DialogDescription>
                            {won ? `Gagnée le ${proposeWon?.won_on.split('-').reverse().join('/')}, date d'acceptation du devis.` : 'Le motif de perte est obligatoire.'}
                        </DialogDescription>
                    </DialogHeader>

                    {won && (
                        <div className="flex items-center gap-2">
                            <Checkbox id="update_amount" checked={data.update_amount} onCheckedChange={(c) => setData('update_amount', c === true)} />
                            <Label htmlFor="update_amount">
                                Mettre le montant de l'opportunité à jour ({formatAmount(proposeWon?.amount_ht)} HT)
                            </Label>
                        </div>
                    )}

                    {lost && (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="quote-loss-reason">Motif de perte *</Label>
                                <Select value={data.loss_reason_id} onValueChange={(v) => setData('loss_reason_id', v)}>
                                    <SelectTrigger id="quote-loss-reason" aria-invalid={Boolean(errs.loss_reason_id)}>
                                        <SelectValue placeholder="Choisissez un motif" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {pipeline.loss_reasons.map((r) => (
                                            <SelectItem key={r.value} value={r.value}>
                                                {r.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errs.loss_reason_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="quote-loss-comment">Commentaire</Label>
                                <textarea
                                    id="quote-loss-comment"
                                    rows={3}
                                    maxLength={2000}
                                    value={data.loss_comment}
                                    onChange={(e) => setData('loss_comment', e.target.value)}
                                    className={textareaClass}
                                />
                                <InputError message={errs.loss_comment} />
                            </div>
                        </>
                    )}
                    <InputError message={errs.stage_id ?? errs.quote_id ?? errs.won_on} />

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Ne pas changer
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            {won ? 'Passer en « Gagné »' : 'Passer en « Perdu »'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
