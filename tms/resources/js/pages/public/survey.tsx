import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Question {
    id: string;
    type: 'rating' | 'choice' | 'text';
    label: string;
    required: boolean;
    options?: string[];
}

interface Props {
    state: 'open' | 'answered' | 'invalid';
    organisme?: string;
    title?: string;
    session?: string;
    first_name?: string;
    deadline?: string;
    questions?: Question[];
}

const MARKS = [1, 2, 3, 4, 5];

/**
 * A satisfaction questionnaire answered without an account (personal link or
 * the session QR code): marks 1 to 5 as big buttons (phones first), choices,
 * free text; one answer only.
 */
export default function Survey({ state, organisme, title, session, first_name, deadline, questions = [] }: Props) {
    const { data, setData, post, processing, errors, wasSuccessful } = useForm<{ answers: Record<string, string | number> }>({ answers: {} });
    const errs = errors as Record<string, string | undefined>;
    const set = (id: string, value: string | number) => setData('answers', { ...data.answers, [id]: value });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(window.location.pathname + window.location.search, { preserveScroll: true });
    };

    if (state === 'invalid') {
        return (
            <AuthLayout title="Lien invalide ou expiré" description="Ce questionnaire n'est plus disponible. Merci pour votre intérêt.">
                <Head title="Questionnaire" />
            </AuthLayout>
        );
    }

    if (state === 'answered' || wasSuccessful) {
        return (
            <AuthLayout title="Merci !" description="Votre réponse a bien été enregistrée. Vous pouvez fermer cette page.">
                <Head title="Questionnaire" />
            </AuthLayout>
        );
    }

    return (
        <AuthLayout
            title={title ?? 'Questionnaire'}
            description={`Bonjour ${first_name ?? ''}${session ? `, session ${session}` : ''}${organisme ? ` - ${organisme}` : ''}. Répondez avant le ${deadline}.`}
        >
            <Head title="Questionnaire" />
            <form onSubmit={submit} className="grid gap-6" noValidate>
                {questions.map((q, i) => (
                    <fieldset key={q.id} className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">
                            {i + 1}. {q.label}
                            {q.required && <span className="text-destructive"> *</span>}
                        </legend>
                        {q.type === 'rating' && (
                            <>
                                <div className="grid grid-cols-5 gap-2">
                                    {MARKS.map((m) => (
                                        <button
                                            key={m}
                                            type="button"
                                            aria-pressed={data.answers[q.id] === m}
                                            aria-label={`${m} sur 5`}
                                            onClick={() => set(q.id, m)}
                                            className={`h-12 rounded-md border text-lg font-semibold ${
                                                data.answers[q.id] === m ? 'bg-primary text-primary-foreground border-primary' : 'bg-background'
                                            }`}
                                        >
                                            {m}
                                        </button>
                                    ))}
                                </div>
                                <div className="text-muted-foreground flex justify-between text-xs">
                                    <span>1 = pas du tout satisfait</span>
                                    <span>5 = très satisfait</span>
                                </div>
                            </>
                        )}
                        {q.type === 'choice' &&
                            (q.options ?? []).map((o) => (
                                <label key={o} className="flex min-h-10 items-center gap-3 rounded-md border px-3 text-sm">
                                    <input type="radio" name={q.id} checked={data.answers[q.id] === o} onChange={() => set(q.id, o)} />
                                    {o}
                                </label>
                            ))}
                        {q.type === 'text' && (
                            <textarea
                                aria-label={q.label}
                                maxLength={2000}
                                className="border-input min-h-24 rounded-md border p-2 text-sm"
                                value={String(data.answers[q.id] ?? '')}
                                onChange={(e) => set(q.id, e.target.value)}
                            />
                        )}
                        <InputError message={errs[`answers.${q.id}`]} />
                    </fieldset>
                ))}
                <Button type="submit" size="lg" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Envoyer mes réponses
                </Button>
            </form>
        </AuthLayout>
    );
}
