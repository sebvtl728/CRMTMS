import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate, formatSize } from '@/lib/crm';
import { router, useForm } from '@inertiajs/react';
import { Check, Copy, Download, Link as LinkIcon, LoaderCircle, Send } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

export interface PositioningTest {
    brochure_id: string;
    version_id: string;
    title: string;
    original_name: string;
    size: number;
}

export interface PositioningSendingRow {
    id: string;
    created_at: string | null;
    email: string | null;
    tests: string[];
    email_status: 'pending' | 'sent' | 'failed' | 'none';
    email_error: string | null;
    expires_at: string;
    submitted_at: string | null;
    link: string;
}

export interface PositioningProps {
    tests: PositioningTest[];
    /** Published online tests of the training or DAS. */
    online_tests: { id: string; title: string; total_points: number; time_limit_minutes: number | null }[];
    defaults: { to: string; subject: string; body: string };
    sendings: PositioningSendingRow[];
    can_send: boolean;
    /** The working link to copy (who may change the candidature only). */
    link: { url: string; expires_on: string } | null;
}

const EMAIL_STATUS: Record<PositioningSendingRow['email_status'], string> = {
    pending: 'E-mail en cours d’envoi',
    sent: 'E-mail envoyé',
    failed: 'Échec de l’e-mail',
    none: 'Lien généré',
};

/**
 * The positioning tests of the library offered for the candidature (its
 * training or DAS), « Envoyer le test » (the PDF attached, a personal upload
 * link in the message) and the sendings with the state of their link.
 */
export function PositioningPanel({ candidatureId, positioning }: { candidatureId: string; positioning: PositioningProps }) {
    const [open, setOpen] = useState(false);
    const online = positioning.online_tests ?? [];
    // What the link leads to: an online test (by id) or the PDF tests to download and send back.
    const [choice, setChoice] = useState<string>(online[0]?.id ?? 'pdf');
    const hasSomething = positioning.tests.length > 0 || online.length > 0;
    const linkData = choice === 'pdf' ? { version_ids: positioning.tests.map((t) => t.version_id) } : { online_test_id: choice };

    return (
        <div className="grid gap-2">
            {online.length > 0 && (
                <ul className="grid gap-1 text-sm">
                    {online.map((t) => (
                        <li key={t.id}>
                            En ligne : <strong>{t.title}</strong>{' '}
                            <span className="text-muted-foreground text-xs">
                                {t.total_points} points · {t.time_limit_minutes ? `${t.time_limit_minutes} min` : 'durée libre'}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            {positioning.tests.length === 0 && online.length === 0 ? (
                <p className="text-muted-foreground text-xs">
                    Aucun test de positionnement pour cette formation ou ce DAS (Formation › Tests en ligne, ou Catalogue › Plaquettes et tests pour
                    un PDF).
                </p>
            ) : (
                <ul className="grid gap-1 text-sm">
                    {positioning.tests.map((t) => (
                        <li key={t.version_id} className="flex flex-wrap items-center gap-2">
                            <a
                                href={`/catalogue/brochures/${t.brochure_id}/versions/${t.version_id}`}
                                className="inline-flex items-center gap-1 hover:underline"
                            >
                                <Download className="h-3.5 w-3.5" />
                                {t.title}
                            </a>
                            <span className="text-muted-foreground text-xs">{formatSize(t.size)}</span>
                        </li>
                    ))}
                </ul>
            )}
            {positioning.link && <LinkBox link={positioning.link} />}
            {positioning.can_send && hasSomething && (
                <div className="flex flex-wrap items-center gap-2">
                    {online.length > 0 && (
                        <select
                            aria-label="Test à faire passer"
                            className="border-input bg-background h-8 rounded-md border px-2 text-sm"
                            value={choice}
                            onChange={(e) => setChoice(e.target.value)}
                        >
                            {online.map((t) => (
                                <option key={t.id} value={t.id}>
                                    En ligne : {t.title}
                                </option>
                            ))}
                            {positioning.tests.length > 0 && <option value="pdf">PDF à télécharger et renvoyer</option>}
                        </select>
                    )}
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => router.post(`/admissions/candidatures/${candidatureId}/positioning/link`, linkData, { preserveScroll: true })}
                    >
                        <LinkIcon className="h-4 w-4" />
                        {positioning.link ? 'Générer un nouveau lien' : 'Générer le lien'}
                    </Button>
                    <Button type="button" size="sm" variant="outline" onClick={() => setOpen(true)}>
                        <Send className="h-4 w-4" />
                        Envoyer par e-mail
                    </Button>
                </div>
            )}
            {positioning.sendings.length > 0 && (
                <ul className="grid gap-1 text-xs">
                    {positioning.sendings.map((s) => (
                        <li key={s.id} className="text-muted-foreground">
                            {formatDate(s.created_at)} · {s.tests.join(', ')} · {s.email ?? '—'} · {EMAIL_STATUS[s.email_status]}
                            {s.email_error && ` (${s.email_error})`} · {s.link}
                            {s.submitted_at && ` le ${formatDate(s.submitted_at)}`}
                        </li>
                    ))}
                </ul>
            )}
            <SendDialog candidatureId={candidatureId} positioning={positioning} choice={choice} open={open} onClose={() => setOpen(false)} />
        </div>
    );
}

/** The candidate's link, to copy and hand over (SMS, e-mail…). */
function LinkBox({ link }: { link: { url: string; expires_on: string } }) {
    const [copied, setCopied] = useState(false);
    const copy = () => {
        void navigator.clipboard?.writeText(link.url).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    };

    return (
        <div className="grid gap-1 rounded-md border p-2">
            <Label htmlFor="positioning-link" className="text-xs">
                Lien du candidat (valable jusqu’au {link.expires_on})
            </Label>
            <div className="flex gap-2">
                <Input id="positioning-link" readOnly value={link.url} onFocus={(e) => e.target.select()} className="font-mono text-xs" />
                <Button type="button" size="sm" onClick={copy}>
                    {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                    {copied ? 'Copié' : 'Copier'}
                </Button>
            </div>
        </div>
    );
}

function SendDialog({
    candidatureId,
    positioning,
    choice,
    open,
    onClose,
}: {
    candidatureId: string;
    positioning: PositioningProps;
    choice: string;
    open: boolean;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        online_test_id: choice === 'pdf' ? '' : choice,
        version_ids: choice === 'pdf' ? positioning.tests.map((t) => t.version_id) : ([] as string[]),
        to: positioning.defaults.to,
        subject: positioning.defaults.subject,
        body: positioning.defaults.body,
    });

    useEffect(() => {
        if (open) {
            reset();
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/admissions/candidatures/${candidatureId}/positioning/send`, { preserveScroll: true, onSuccess: () => onClose() });
    };
    const errs = errors as Record<string, string | undefined>;
    const toggle = (id: string, checked: boolean) =>
        setData('version_ids', checked ? [...data.version_ids, id] : data.version_ids.filter((v) => v !== id));

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-xl">
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>Envoyer le test de positionnement</DialogTitle>
                        <DialogDescription>
                            Les tests cochés sont joints. {'{lien}'} devient le lien personnel où le candidat dépose sa copie (valable 14 jours) ; un
                            nouvel envoi remplace le lien précédent.
                        </DialogDescription>
                    </DialogHeader>
                    {data.online_test_id && (
                        <p className="text-sm">
                            Test en ligne : <strong>{(positioning.online_tests ?? []).find((t) => t.id === data.online_test_id)?.title}</strong> — le
                            lien ouvre le test à l’écran (ordinateur seulement).
                        </p>
                    )}
                    <fieldset className="grid gap-1">
                        <legend className="mb-1 text-sm font-medium">{data.online_test_id ? 'PDF à joindre (facultatif)' : 'Tests *'}</legend>
                        {positioning.tests.map((t) => (
                            <Label key={t.version_id} className="flex items-center gap-2 font-normal">
                                <input
                                    type="checkbox"
                                    checked={data.version_ids.includes(t.version_id)}
                                    onChange={(e) => toggle(t.version_id, e.target.checked)}
                                />
                                {t.title}
                            </Label>
                        ))}
                        <InputError message={errs.version_ids} />
                    </fieldset>
                    <div className="grid gap-2">
                        <Label htmlFor="positioning-to">Destinataire *</Label>
                        <Input id="positioning-to" type="email" value={data.to} onChange={(e) => setData('to', e.target.value)} />
                        <InputError message={errs.to} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="positioning-subject">Objet *</Label>
                        <Input id="positioning-subject" maxLength={200} value={data.subject} onChange={(e) => setData('subject', e.target.value)} />
                        <InputError message={errs.subject} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="positioning-body">Message *</Label>
                        <textarea
                            id="positioning-body"
                            rows={9}
                            maxLength={5000}
                            value={data.body}
                            onChange={(e) => setData('body', e.target.value)}
                            className="border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden"
                        />
                        <InputError message={errs.body} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Annuler
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Envoyer
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
