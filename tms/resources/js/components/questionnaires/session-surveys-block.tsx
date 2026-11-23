import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Link, router } from '@inertiajs/react';
import { Clock, LoaderCircle, QrCode, Send } from 'lucide-react';
import { useState, type ReactNode } from 'react';

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
    /** The trainees a launch e-mails. */
    trainee_count: number;
    ended: boolean;
    qr_url: string | null;
    /** The last day: the hot questionnaire leaves by itself at 13:30 (« 31/08/2026 »). */
    hot_on: string;
    /** The cold one, null when the organisme sends none (0 month). */
    cold_on: string | null;
    campaigns: Campaign[];
}

const EMAIL: Record<string, string> = {
    pending: 'Envoi en cours',
    sent: 'E-mail envoyé',
    failed: 'Échec de l’envoi',
    none: 'Sans adresse (QR code)',
};

const percent = (v: number | null) => (v === null ? '-' : `${v} %`);

/** « Questionnaires » of a session: launch the hot / cold questionnaire, show the QR code, read the results. */
export function SessionSurveysBlock({ session_id, can_launch, trainee_count, ended, qr_url, hot_on, cold_on, campaigns }: Props) {
    const [busy, setBusy] = useState<string | null>(null);
    const hot = campaigns.find((c) => c.kind === 'hot' && c.audience === 'trainee');
    const cold = campaigns.find((c) => c.kind === 'cold' && c.audience === 'trainee');
    const launch = (kind: 'hot' | 'cold') => {
        const what = kind === 'hot' ? 'à chaud' : 'à froid';
        if (
            !window.confirm(
                `Envoyer le questionnaire ${what} par e-mail à ${trainee_count} stagiaire${trainee_count > 1 ? 's' : ''} ? Ceux qui l'ont déjà reçu ne le reçoivent pas deux fois.`,
            )
        ) {
            return;
        }
        router.post(
            `/sessions/${session_id}/questionnaires/${kind}`,
            {},
            { preserveScroll: true, onStart: () => setBusy(kind), onFinish: () => setBusy(null) },
        );
    };

    return (
        <div className="grid gap-3">
            <div className="grid gap-2 sm:grid-cols-2">
                <SurveyCard
                    title="À chaud (fin de formation)"
                    sent={hot ? `Envoyé le ${hot.opened_on} · ${hot.results.answered}/${hot.results.invited} réponses` : null}
                    auto={`Part tout seul par e-mail le dernier jour, ${hot_on} à 13 h 30, s'il n'a pas été lancé avant. Vous n'avez rien à faire.`}
                    manual={
                        can_launch && (!hot || hot.open) ? (
                            <>
                                <p className="text-muted-foreground">
                                    Facultatif : le faire remplir en salle (plus de réponses) — lancez-le, puis affichez le QR code.
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <Button size="sm" variant="outline" disabled={busy !== null} onClick={() => launch('hot')}>
                                        {busy === 'hot' ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                                        {hot ? 'Inviter les nouveaux stagiaires' : 'Lancer maintenant'}
                                    </Button>
                                    {qr_url && (
                                        <Button size="sm" variant="outline" asChild>
                                            <Link href={qr_url}>
                                                <QrCode className="h-4 w-4" />
                                                Afficher le QR code
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            </>
                        ) : qr_url ? (
                            <Button size="sm" variant="outline" asChild>
                                <Link href={qr_url}>
                                    <QrCode className="h-4 w-4" />
                                    Afficher le QR code
                                </Link>
                            </Button>
                        ) : null
                    }
                />
                <SurveyCard
                    title="À froid (quelques mois après)"
                    sent={cold ? `Envoyé le ${cold.opened_on} · ${cold.results.answered}/${cold.results.invited} réponses` : null}
                    auto={cold_on ? `Part tout seul par e-mail le ${cold_on} à 7 h. Vous n'avez rien à faire.` : null}
                    manual={
                        can_launch && (!cold || cold.open) ? (
                            <>
                                <Button size="sm" variant="outline" disabled={busy !== null || !ended} onClick={() => launch('cold')}>
                                    {busy === 'cold' ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                                    {cold ? 'Inviter les nouveaux stagiaires' : 'Envoyer maintenant'}
                                </Button>
                                <p className="text-muted-foreground">
                                    {ended
                                        ? "En cas de problème d'envoi automatique, ou pour ne pas attendre le délai."
                                        : 'Possible à partir du dernier jour de la session.'}
                                </p>
                            </>
                        ) : null
                    }
                />
            </div>

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

/** One questionnaire: sent (date, answers), or when it leaves by itself; the manual sending folded apart. */
function SurveyCard({ title, sent, auto, manual }: { title: string; sent: string | null; auto: string | null; manual: ReactNode }) {
    return (
        <div className="grid content-start gap-2 rounded-md border p-3 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-1">
                <span className="font-medium">{title}</span>
                {sent ? <Badge>Envoyé</Badge> : auto ? <Badge variant="secondary">Automatique</Badge> : <Badge variant="outline">Désactivé</Badge>}
            </div>
            <p className="text-muted-foreground flex items-start gap-1 text-xs">
                {!sent && auto && <Clock className="mt-0.5 h-3 w-3 shrink-0" />}
                {sent ?? auto ?? 'Pas de questionnaire à froid (délai à 0 mois dans Administration › Questionnaires).'}
            </p>
            {manual && (
                <details className="text-xs">
                    <summary className="cursor-pointer font-medium">{sent ? 'Relancer ou afficher le QR code' : 'Envoyer à la main'}</summary>
                    <div className="mt-2 grid gap-2">{manual}</div>
                </details>
            )}
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
