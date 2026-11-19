import { OnlineTestRunner, type OnlineProps } from '@/components/admissions/online-test-runner';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { formatSize } from '@/lib/crm';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Download, LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Props {
    state: 'open' | 'submitted' | 'invalid';
    mode?: 'online' | 'upload';
    online?: OnlineProps | null;
    organisme?: string;
    first_name?: string;
    training?: string | null;
    deadline?: string;
    submitted_on?: string | null;
    tests?: { title: string; size: number; href: string }[];
    max_files?: number;
    status?: string;
}

/**
 * The candidate's personal page of a positioning test (no account): the
 * tests to download, then the copy to deposit, once.
 */
export default function PositioningTest({
    state,
    mode,
    online,
    organisme,
    first_name,
    training,
    deadline,
    submitted_on,
    tests = [],
    max_files = 5,
    status,
}: Props) {
    const { data, setData, post, processing, errors, progress } = useForm<{ files: File[]; done: boolean }>({ files: [], done: false });
    const errs = usePage().props.errors as Record<string, string | undefined>;
    const fileErrors = Object.entries({ ...errs, ...(errors as Record<string, string | undefined>) })
        .filter(([key]) => key.startsWith('files.'))
        .map(([, message]) => message);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(window.location.pathname, { forceFormData: true, preserveScroll: true });
    };

    if (state === 'invalid') {
        return (
            <AuthLayout
                title="Lien invalide ou expiré"
                description="Ce lien ne fonctionne plus. Demandez un nouvel envoi à l'organisme de formation."
            >
                <Head title="Test de positionnement" />
            </AuthLayout>
        );
    }

    return (
        <AuthLayout
            title={`Test de positionnement${organisme ? ` - ${organisme}` : ''}`}
            description={`Bonjour ${first_name ?? ''}${training ? `, pour la formation « ${training} »` : ''}.`}
        >
            <Head title="Test de positionnement" />
            {mode === 'online' && online ? (
                <OnlineTestRunner token={window.location.pathname.split('/').pop() ?? ''} online={online} submittedOn={submitted_on} />
            ) : (
                <div className="grid gap-6">
                    {status && <p className="text-sm font-medium text-green-600">{status}</p>}

                    <section className="grid gap-2">
                        <h2 className="text-sm font-semibold">1. Téléchargez votre test</h2>
                        <ul className="grid gap-1 text-sm">
                            {tests.map((t) => (
                                <li key={t.href}>
                                    <a href={t.href} className="inline-flex items-center gap-1 underline">
                                        <Download className="h-4 w-4" />
                                        {t.title}
                                    </a>{' '}
                                    <span className="text-muted-foreground text-xs">{formatSize(t.size)}</span>
                                </li>
                            ))}
                        </ul>
                    </section>

                    {state === 'submitted' ? (
                        <p className="rounded-md border p-3 text-sm">
                            Votre copie a été reçue le {submitted_on}. L'organisme reviendra vers vous ; vous n'avez rien d'autre à faire.
                        </p>
                    ) : (
                        <form onSubmit={submit} className="grid gap-4" noValidate>
                            <h2 className="text-sm font-semibold">2. Déposez votre copie avant le {deadline}</h2>
                            <div className="grid gap-2">
                                <Label htmlFor="files">Fichiers (PDF ou photos JPG/PNG, {max_files} au plus, 20 Mo chacun)</Label>
                                <Input
                                    id="files"
                                    type="file"
                                    multiple
                                    accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png"
                                    onChange={(e) => setData('files', Array.from(e.target.files ?? []))}
                                />
                                <InputError message={errs.files ?? (errors as Record<string, string | undefined>).files} />
                                {fileErrors.map((m) => (
                                    <InputError key={m} message={m} />
                                ))}
                            </div>
                            <Label className="flex items-center gap-2 font-normal">
                                <input type="checkbox" checked={data.done} onChange={(e) => setData('done', e.target.checked)} />
                                J'ai terminé mon test et je dépose ma copie (un seul dépôt possible).
                            </Label>
                            <InputError message={errs.done ?? (errors as Record<string, string | undefined>).done} />
                            {progress && <progress value={progress.percentage} max="100" className="w-full" />}
                            <Button type="submit" disabled={processing || data.files.length === 0}>
                                {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                Envoyer ma copie
                            </Button>
                        </form>
                    )}
                </div>
            )}
        </AuthLayout>
    );
}
