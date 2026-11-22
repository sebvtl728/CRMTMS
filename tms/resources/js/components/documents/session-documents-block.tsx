import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { Clock, Eye, FileText, LoaderCircle, Send } from 'lucide-react';
import { useState } from 'react';

interface Doc {
    id: string;
    number: string;
    status: string;
    status_label: string;
    email_status: 'pending' | 'sent' | 'failed' | null;
}

interface Trainee {
    enrolment_id: string;
    name: string;
    left_on: string | null;
    documents: Record<string, Doc | null>;
}

interface Props {
    session_id: string;
    can_manage: boolean;
    types: { value: string; label: string }[];
    trainees: Trainee[];
    /** When the convocations leave by themselves (Administration › Automatismes), else null. */
    auto_convocation: string | null;
}

/** « Documents » of a session: convocations, attestations and certificates of each trainee, one by one or for all. */
export function SessionDocumentsBlock({ session_id, can_manage, types, trainees, auto_convocation }: Props) {
    const [busy, setBusy] = useState<string | null>(null);
    const [asking, setAsking] = useState(false);
    const post = (url: string, type: string, key: string, then?: () => void) =>
        router.post(url, { type }, { preserveScroll: true, onStart: () => setBusy(key), onFinish: () => setBusy(null), onSuccess: then });
    // A planned automatic sending: « Émettre pour tous » asks first, so the two never collide.
    const planned = auto_convocation !== null && auto_convocation.startsWith('Envoi automatique');
    const issue = (type: string) => {
        if (type === 'convocation' && planned) {
            setAsking(true);
            return;
        }
        post(`/sessions/${session_id}/documents`, type, `issue-${type}`);
    };
    // « Envoyer à tous »: says how many trainees receive it, first.
    const sendAll = (type: string, label: string) => {
        const n = trainees.filter((tr) => {
            const doc = tr.documents[type];
            return doc !== null && doc.status === 'issued' && (doc.email_status === null || doc.email_status === 'failed');
        }).length;
        if (n === 0) {
            window.alert(`Aucun document « ${label} » émis à envoyer : émettez-les d'abord.`);
            return;
        }
        if (window.confirm(`Envoyer « ${label} » par e-mail à ${n} stagiaire${n > 1 ? 's' : ''} ?`)) {
            post(`/sessions/${session_id}/documents/send`, type, `send-${type}`);
        }
    };
    const issueAndSendNow = () => {
        setAsking(false);
        post(`/sessions/${session_id}/documents`, 'convocation', 'issue-convocation', () =>
            post(`/sessions/${session_id}/documents/send`, 'convocation', 'send-convocation'),
        );
    };

    return (
        <div className="grid gap-3">
            {can_manage && (
                <p className="text-muted-foreground text-xs">
                    <strong>Émettre</strong> crée le document avec son numéro définitif (il ne se modifie plus : une erreur se corrige en l'annulant)
                    ; <strong>Envoyer</strong> l'adresse par e-mail à chaque stagiaire.
                </p>
            )}
            {can_manage && (
                <div className="grid gap-2 sm:grid-cols-3">
                    {types.map((t) => (
                        <div key={t.value} className="grid gap-1 rounded-md border p-2">
                            <span className="text-sm font-medium">{t.label}</span>
                            <div className="flex flex-wrap gap-1">
                                <Button size="sm" variant="outline" disabled={busy !== null} onClick={() => issue(t.value)}>
                                    {busy === `issue-${t.value}` ? (
                                        <LoaderCircle className="h-4 w-4 animate-spin" />
                                    ) : (
                                        <FileText className="h-4 w-4" />
                                    )}
                                    Émettre pour tous
                                </Button>
                                <Button size="sm" variant="outline" disabled={busy !== null} onClick={() => sendAll(t.value, t.label)}>
                                    {busy === `send-${t.value}` ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                                    Envoyer à tous
                                </Button>
                            </div>
                            {t.value === 'convocation' && auto_convocation && (
                                <p className="text-muted-foreground flex items-start gap-1 text-xs">
                                    <Clock className="mt-0.5 h-3 w-3 shrink-0" />
                                    {auto_convocation}
                                </p>
                            )}
                        </div>
                    ))}
                </div>
            )}
            <div className="overflow-x-auto rounded-md border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="p-2 font-medium">Stagiaire</th>
                            {types.map((t) => (
                                <th key={t.value} className="p-2 font-medium">
                                    {t.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {trainees.map((tr) => (
                            <tr key={tr.enrolment_id} className="border-t">
                                <td className="p-2">
                                    {tr.name}
                                    {tr.left_on && <span className="text-muted-foreground text-xs"> (sorti)</span>}
                                </td>
                                {types.map((t) => {
                                    const doc = tr.documents[t.value];
                                    return (
                                        <td key={t.value} className="p-2">
                                            {doc ? (
                                                <div className="flex flex-wrap items-center gap-1">
                                                    <a
                                                        href={`/documents/${doc.id}/pdf?apercu=1`}
                                                        target="_blank"
                                                        rel="noopener"
                                                        className="inline-flex items-center gap-1 underline"
                                                    >
                                                        <Eye className="h-3.5 w-3.5" />
                                                        {doc.number}
                                                    </a>
                                                    <Badge variant="secondary">
                                                        {doc.email_status === 'failed' ? 'Échec de l’envoi' : doc.status_label}
                                                    </Badge>
                                                </div>
                                            ) : can_manage ? (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    disabled={busy !== null}
                                                    onClick={() =>
                                                        post(`/enrolments/${tr.enrolment_id}/documents`, t.value, `${tr.enrolment_id}-${t.value}`)
                                                    }
                                                >
                                                    Émettre
                                                </Button>
                                            ) : (
                                                <span className="text-muted-foreground">-</span>
                                            )}
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <p className="text-muted-foreground text-xs">
                L’attestation et le certificat s’émettent à la fin de la formation (ou à la sortie du stagiaire) ; « Envoyer à tous » n’envoie que les
                documents émis et pas encore envoyés, aux stagiaires qui ont une adresse e-mail.
            </p>
            <Dialog open={asking} onOpenChange={setAsking}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Les convocations partent toutes seules</DialogTitle>
                        <DialogDescription>
                            {auto_convocation} Vous n'avez rien à faire : chaque stagiaire la recevra par e-mail. Vous pouvez aussi les envoyer tout
                            de suite.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <Button variant="outline" onClick={() => setAsking(false)}>
                            Laisser l'envoi automatique
                        </Button>
                        <Button onClick={issueAndSendNow}>
                            <Send className="h-4 w-4" />
                            Émettre et envoyer maintenant
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
