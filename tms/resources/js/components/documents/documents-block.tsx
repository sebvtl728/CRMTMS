import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/crm';
import { router, useForm } from '@inertiajs/react';
import { Download, Eye, FileText, LoaderCircle, PenLine, Send, XCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface DocumentRow {
    id: string;
    type: string;
    type_label: string;
    number: string;
    status: string;
    status_label: string;
    issued_at: string;
    recipient: string | null;
    sent_at: string | null;
    email_status: 'pending' | 'sent' | 'failed' | null;
    email_error: string | null;
    cancel_reason: string | null;
    defaults: { to: string; subject: string; body: string } | null;
    signature: { status: 'pending' | 'signed' | 'cancelled'; signed_at: string | null; email: string | null } | null;
    can_sign: boolean;
}

interface Props {
    dossier_id: string;
    can_manage: boolean;
    issuable: { value: string; label: string; per_trainee: boolean }[];
    documents: DocumentRow[];
}

const textareaClass = 'border-input bg-background min-h-32 w-full rounded-md border p-2 text-sm';

function SendDialog({ doc, onClose }: { doc: DocumentRow; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm(doc.defaults ?? { to: '', subject: '', body: '' });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/documents/${doc.id}/send`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-xl">
                <form onSubmit={submit} className="grid gap-3">
                    <DialogHeader>
                        <DialogTitle>
                            Envoyer {doc.type_label.toLowerCase()} {doc.number}
                        </DialogTitle>
                        <DialogDescription>Le PDF archivé est joint automatiquement.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1">
                        <Label htmlFor="doc-to">Destinataire</Label>
                        <Input id="doc-to" type="email" value={data.to} onChange={(e) => setData('to', e.target.value)} />
                        <InputError message={errors.to} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="doc-subject">Objet</Label>
                        <Input id="doc-subject" value={data.subject} onChange={(e) => setData('subject', e.target.value)} />
                        <InputError message={errors.subject} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="doc-body">Message</Label>
                        <textarea id="doc-body" className={textareaClass} value={data.body} onChange={(e) => setData('body', e.target.value)} />
                        <InputError message={errors.body} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Fermer
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                            Envoyer
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function SignDialog({ doc, onClose }: { doc: DocumentRow; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ email: doc.signature?.email ?? doc.defaults?.to ?? '' });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/documents/${doc.id}/signature`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-3">
                    <DialogHeader>
                        <DialogTitle>Faire signer {doc.number} en ligne</DialogTitle>
                        <DialogDescription>
                            Le signataire reçoit un lien personnel (valable 30 jours), lit le document et signe avec un code reçu par e-mail. Une page
                            de preuve est ajoutée au PDF signé. Un nouvel envoi remplace le lien précédent.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1">
                        <Label htmlFor="sign-email">Adresse du signataire</Label>
                        <Input id="sign-email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                        <InputError message={errors.email} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Fermer
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <PenLine className="h-4 w-4" />}
                            Envoyer le lien
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function CancelDialog({ doc, onClose }: { doc: DocumentRow; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ reason: '' });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/documents/${doc.id}/cancel`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-3">
                    <DialogHeader>
                        <DialogTitle>Annuler {doc.number}</DialogTitle>
                        <DialogDescription>
                            Un document émis ne se modifie pas : il reste archivé avec la mention « Annulé », et vous pouvez en émettre un nouveau.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1">
                        <Label htmlFor="doc-reason">Motif de l’annulation</Label>
                        <Input id="doc-reason" value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                        <InputError message={errors.reason} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Fermer
                        </Button>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            Annuler le document
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** The « Documents » block of a dossier: issue the convention or the contract, and each trainee's forms; preview, send, sign, cancel. */
export function DocumentsBlock({ dossier_id, can_manage, issuable, documents }: Props) {
    const [sending, setSending] = useState<DocumentRow | null>(null);
    const [cancelling, setCancelling] = useState<DocumentRow | null>(null);
    const [signing, setSigning] = useState<DocumentRow | null>(null);
    const [issuing, setIssuing] = useState(false);
    const issue = (type: string) =>
        router.post(
            `/dossiers/${dossier_id}/documents`,
            { type },
            { preserveScroll: true, onStart: () => setIssuing(true), onFinish: () => setIssuing(false) },
        );

    return (
        <div className="grid gap-3">
            {can_manage && issuable.length > 0 && (
                <div className="grid gap-2">
                    {[false, true].map((perTrainee) => {
                        const types = issuable.filter((t) => t.per_trainee === perTrainee);
                        return (
                            types.length > 0 && (
                                <div key={String(perTrainee)} className="flex flex-wrap items-center gap-2">
                                    {perTrainee && <span className="text-muted-foreground text-xs">Pour chaque stagiaire :</span>}
                                    {types.map((t) => (
                                        <Button
                                            key={t.value}
                                            size="sm"
                                            variant={perTrainee ? 'outline' : 'default'}
                                            onClick={() => issue(t.value)}
                                            disabled={issuing}
                                        >
                                            {issuing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <FileText className="h-4 w-4" />}
                                            Émettre : {t.label.charAt(0).toLowerCase() + t.label.slice(1)}
                                        </Button>
                                    ))}
                                </div>
                            )
                        );
                    })}
                </div>
            )}
            {documents.length > 0 && (
                <ul className="divide-y rounded-md border">
                    {documents.map((d) => (
                        <li key={d.id} className="grid gap-1 p-3 text-sm">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">
                                    {d.type_label} {d.number}
                                </span>
                                <Badge variant={d.status === 'cancelled' ? 'outline' : 'secondary'}>{d.status_label}</Badge>
                                <span className="text-muted-foreground text-xs">
                                    émis le {formatDate(d.issued_at)}
                                    {d.recipient ? ` · ${d.recipient}` : ''}
                                    {d.sent_at ? ` · envoyé le ${formatDate(d.sent_at)}` : ''}
                                </span>
                            </div>
                            {d.email_status === 'pending' && <p className="text-muted-foreground text-xs">Envoi en cours…</p>}
                            {d.email_status === 'failed' && <p className="text-xs text-red-600">L’e-mail n’est pas parti : {d.email_error}</p>}
                            {d.cancel_reason && <p className="text-muted-foreground text-xs">Motif de l’annulation : {d.cancel_reason}</p>}
                            {d.signature?.status === 'pending' && (
                                <p className="text-muted-foreground text-xs">
                                    Lien de signature envoyé{d.signature.email ? ` à ${d.signature.email}` : ''} : en attente.
                                </p>
                            )}
                            {d.signature?.status === 'signed' && (
                                <p className="text-xs text-green-700 dark:text-green-400">Signé en ligne le {formatDate(d.signature.signed_at)}.</p>
                            )}
                            <div className="flex flex-wrap gap-2">
                                <Button asChild size="sm" variant="outline">
                                    <a href={`/documents/${d.id}/pdf?apercu=1`} target="_blank" rel="noopener">
                                        <Eye className="h-4 w-4" />
                                        Aperçu
                                    </a>
                                </Button>
                                <Button asChild size="sm" variant="outline">
                                    <a href={`/documents/${d.id}/pdf`}>
                                        <Download className="h-4 w-4" />
                                        Télécharger
                                    </a>
                                </Button>
                                {d.signature?.status === 'signed' && (
                                    <Button asChild size="sm" variant="outline">
                                        <a href={`/documents/${d.id}/signed`} target="_blank" rel="noopener">
                                            <PenLine className="h-4 w-4" />
                                            PDF signé
                                        </a>
                                    </Button>
                                )}
                                {d.can_sign && (
                                    <Button size="sm" variant="outline" onClick={() => setSigning(d)}>
                                        <PenLine className="h-4 w-4" />
                                        {d.signature?.status === 'pending' ? 'Renvoyer le lien de signature' : 'Demander la signature'}
                                    </Button>
                                )}
                                {can_manage && d.status !== 'cancelled' && (
                                    <>
                                        <Button size="sm" variant="outline" onClick={() => setSending(d)} disabled={d.email_status === 'pending'}>
                                            <Send className="h-4 w-4" />
                                            Envoyer
                                        </Button>
                                        <Button size="sm" variant="ghost" onClick={() => setCancelling(d)}>
                                            <XCircle className="h-4 w-4" />
                                            Annuler
                                        </Button>
                                    </>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            {sending && <SendDialog doc={sending} onClose={() => setSending(null)} />}
            {cancelling && <CancelDialog doc={cancelling} onClose={() => setCancelling(null)} />}
            {signing && <SignDialog doc={signing} onClose={() => setSigning(null)} />}
        </div>
    );
}
