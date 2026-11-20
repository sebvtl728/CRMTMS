import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Link, router } from '@inertiajs/react';
import { LoaderCircle, QrCode, Send } from 'lucide-react';
import { useState } from 'react';

interface QuestionResult {
    id: string;
    type: 'rating' | 'choice' | 'text';
    label: string;
    answers: number;
    average?: number | null;
    average_label?: string;
    distribution?: number[];
    counts?: Record<string, number>;
    texts?: string[];
}

interface Campaign {
    id: string;
    kind: 'hot' | 'cold';
    audience: 'trainee' | 'client';
    label: string;
    opened_on: string;
    closes_on: string;
    open: boolean;
    results: {
        invited: number;
        answered: number;
        response_rate: number | null;
        average_label: string;
        satisfaction_rate: number | null;
        questions: QuestionResult[];
    };
    invitations: { name: string; email_status: 'pending' | 'sent' | 'failed' | 'none'; email_error: string | null; answered: boolean }[];
}

interface Props {
    session_id: string;
    can_launch: boolean;
    ended: boolean;
    qr_url: string | null;
    campaigns: Campaign[];
}

const EMAIL: Record<string, string> = { pending: 'Envoi en cours', sent: 'E-mail envoyé', failed: 'Échec de l’envoi', none: 'Sans adresse (QR code)' };

const percent = (v: number | null) => (v === null ? '-' : `${v} %`);

/** « Questionnaires » of a session: launch the hot / cold questionnaire, show the QR code, read the results. */
export function SessionSurveysBlock({ session_id, can_launch, ended, qr_url, campaigns }: Props) {
    const [busy, setBusy] = useState<string | null>(null);
    const hot = campaigns.find((c) => c.kind === 'hot' && c.audience === 'trainee');
    const cold = campaigns.find((c) => c.kind === 'cold' && c.audience === 'trainee');
    const launch = (kind: 'hot' | 'cold') =>
        router.post(`/sessions/${session_id}/questionnaires/${kind}`, {}, { preserveScroll: true, onStart: () => setBusy(kind), onFinish: () => setBusy(null) });

    return (
        <div className="grid gap-3">
            {(can_launch || qr_url) && (
                <div className="flex flex-wrap gap-2">
                    {can_launch && (
                        <Button size="sm" variant={hot ? 'outline' : 'default'} disabled={busy !== null} onClick={() => launch('hot')}>
                            {busy === 'hot' ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                            {hot ? 'Inviter les nouveaux stagiaires (à chaud)' : 'Lancer le questionnaire à chaud'}
                        </Button>
                    )}
                    {can_launch && ended && (
                        <Button size="sm" variant="outline" disabled={busy !== null} onClick={() => launch('cold')}>
                            {busy === 'cold' ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                            {cold ? 'Inviter les nouveaux stagiaires (à froid)' : 'Envoyer le questionnaire à froid maintenant'}
                        </Button>
                    )}
                    {qr_url && (
                        <Button size="sm" variant="outline" asChild>
                            <Link href={qr_url}>
                                <QrCode className="h-4 w-4" />
                                Afficher le QR code
                            </Link>
                        </Button>
                    )}
                </div>
            )}

            {campaigns.length === 0 && (
                <p className="text-muted-foreground text-sm">
                    Aucun questionnaire envoyé. Le questionnaire à chaud part automatiquement le lendemain du dernier jour s'il n'a pas été lancé.
                </p>
            )}

            {campaigns.map((c) => (
                <div key={c.id} className="grid gap-3 rounded-md border p-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-medium">{c.label}</span>
                        <span className="text-muted-foreground text-xs">
                            envoyé le {c.opened_on}, {c.open ? `ouvert jusqu'au ${c.closes_on}` : `clos le ${c.closes_on}`}
                        </span>
                    </div>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <Stat label="Réponses" value={`${c.results.answered} / ${c.results.invited}`} />
                        <Stat label="Taux de réponse" value={percent(c.results.response_rate)} />
                        <Stat label="Satisfaction moyenne" value={c.results.average_label} />
                        <Stat label="Satisfaits (≥ 4/5)" value={percent(c.results.satisfaction_rate)} />
                    </div>
                    {c.results.answered > 0 && (
                        <ul className="grid gap-2 text-sm">
                            {c.results.questions.map((q) => (
                                <li key={q.id} className="grid gap-1">
                                    <span>{q.label}</span>
                                    {q.type === 'rating' && (
                                        <span className="text-muted-foreground text-xs">
                                            Moyenne {q.average_label} · {q.distribution?.map((n, i) => `${i + 1} : ${n}`).join(' · ')}
                                        </span>
                                    )}
                                    {q.type === 'choice' && (
                                        <span className="text-muted-foreground text-xs">
                                            {Object.entries(q.counts ?? {})
                                                .map(([o, n]) => `${o} : ${n}`)
                                                .join(' · ')}
                                        </span>
                                    )}
                                    {q.type === 'text' &&
                                        (q.texts?.length ? (
                                            <ul className="text-muted-foreground grid gap-1 border-l-2 pl-2 text-xs">
                                                {q.texts.map((t, i) => (
                                                    <li key={i} className="whitespace-pre-line">
                                                        « {t} »
                                                    </li>
                                                ))}
                                            </ul>
                                        ) : (
                                            <span className="text-muted-foreground text-xs">Aucun commentaire.</span>
                                        ))}
                                </li>
                            ))}
                        </ul>
                    )}
                    <details>
                        <summary className="cursor-pointer text-sm">Destinataires</summary>
                        <ul className="mt-2 grid gap-1 text-sm">
                            {c.invitations.map((i, k) => (
                                <li key={k} className="flex flex-wrap items-center gap-2">
                                    <span>{i.name}</span>
                                    <Badge variant={i.email_status === 'failed' ? 'destructive' : 'outline'} title={i.email_error ?? undefined}>
                                        {EMAIL[i.email_status]}
                                    </Badge>
                                    {i.answered ? <Badge>Répondu</Badge> : <Badge variant="secondary">En attente</Badge>}
                                </li>
                            ))}
                        </ul>
                    </details>
                </div>
            ))}
        </div>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-md border p-2">
            <div className="text-muted-foreground text-xs">{label}</div>
            <div className="text-lg font-semibold">{value}</div>
        </div>
    );
}
