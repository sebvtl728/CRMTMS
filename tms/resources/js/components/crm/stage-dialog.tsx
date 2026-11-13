import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { todayIso, type Option } from '@/lib/crm';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect } from 'react';

export interface StageOption extends Option {
    outcome: 'won' | 'lost' | null;
    probability: number;
}

/**
 * Stage move of an opportunity (fiche, and the kanban of a later step):
 * « Perdu » asks for the loss reason (required by the server too), « Gagné »
 * for the date of the win (today by default).
 */
export function StageDialog({
    opportunityId,
    currentStageId,
    stages,
    lossReasons,
    open,
    initialStageId,
    onClose,
}: {
    opportunityId: string;
    currentStageId: string;
    stages: StageOption[];
    lossReasons: Option[];
    open: boolean;
    /** Target preselected (kanban drop); the current stage otherwise. */
    initialStageId?: string;
    onClose: (moved: boolean) => void;
}) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        stage_id: initialStageId ?? currentStageId,
        loss_reason_id: '',
        loss_comment: '',
        won_on: todayIso(),
    });

    useEffect(() => {
        if (open) {
            reset();
            clearErrors();
            setData('stage_id', initialStageId ?? currentStageId);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, initialStageId, currentStageId]);

    const target = stages.find((s) => s.value === data.stage_id);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('crm.opportunities.stage', opportunityId), {
            preserveScroll: true,
            onSuccess: () => onClose(true),
        });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose(false)}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>Changer d'étape</DialogTitle>
                        <DialogDescription>
                            La probabilité suit l'étape, sauf si vous l'avez modifiée à la main depuis le dernier passage.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="stage_id">Étape</Label>
                        <Select value={data.stage_id} onValueChange={(v) => setData('stage_id', v)}>
                            <SelectTrigger id="stage_id">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {stages.map((s) => (
                                    <SelectItem key={s.value} value={s.value}>
                                        {s.label} ({s.probability} %)
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.stage_id} />
                    </div>

                    {target?.outcome === 'lost' && (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="loss_reason_id">Motif de perte *</Label>
                                <Select value={data.loss_reason_id} onValueChange={(v) => setData('loss_reason_id', v)}>
                                    <SelectTrigger id="loss_reason_id" aria-invalid={Boolean(errors.loss_reason_id)}>
                                        <SelectValue placeholder="Choisissez un motif" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {lossReasons.map((r) => (
                                            <SelectItem key={r.value} value={r.value}>
                                                {r.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.loss_reason_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="loss_comment">Commentaire</Label>
                                <textarea
                                    id="loss_comment"
                                    rows={3}
                                    maxLength={2000}
                                    value={data.loss_comment}
                                    onChange={(e) => setData('loss_comment', e.target.value)}
                                    className="border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden"
                                />
                                <InputError message={errors.loss_comment} />
                            </div>
                        </>
                    )}

                    {target?.outcome === 'won' && (
                        <div className="grid gap-2">
                            <Label htmlFor="won_on">Date de gain</Label>
                            <Input id="won_on" type="date" max={todayIso()} value={data.won_on} onChange={(e) => setData('won_on', e.target.value)} />
                            <InputError message={errors.won_on} />
                        </div>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onClose(false)}>
                            Annuler
                        </Button>
                        <Button type="submit" disabled={processing || data.stage_id === currentStageId}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Enregistrer
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
