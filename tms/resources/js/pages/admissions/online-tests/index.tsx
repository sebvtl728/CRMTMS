import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle, Plus, Sparkles } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Row {
    id: string;
    title: string;
    published_version: number | null;
    import_status: 'importing' | 'failed' | null;
    total_points: number | null;
    time_limit_minutes: number | null;
    updated_at: string | null;
}

/** « Créer depuis un PDF »: the AI drafts the test from a paper one, to review before publishing. */
function FromPdf() {
    const { data, setData, post, processing, errors } = useForm<{ file: File | null }>({ file: null });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/admissions/online-tests/from-pdf', { forceFormData: true });
    };

    return (
        <form onSubmit={submit} className="grid max-w-xl gap-1 rounded-xl border p-3">
            <Label htmlFor="pdf">
                Créer depuis un PDF (l’intelligence artificielle prépare les questions, vous les relisez et ajoutez les bonnes réponses)
            </Label>
            <div className="flex flex-wrap gap-2">
                <Input
                    id="pdf"
                    type="file"
                    accept="application/pdf,.pdf"
                    onChange={(e) => setData('file', e.target.files?.[0] ?? null)}
                    className="flex-1"
                />
                <Button type="submit" variant="outline" disabled={processing || data.file === null}>
                    {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Sparkles className="h-4 w-4" />}
                    Créer depuis le PDF
                </Button>
            </div>
            {errors.file && <p className="text-sm text-red-600">{errors.file}</p>}
            {processing && <p className="text-muted-foreground text-xs">Envoi du PDF…</p>}
        </form>
    );
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tests en ligne', href: '/admissions/online-tests' }];

export default function OnlineTestsIndex({ tests, status }: { tests: Row[]; status?: string }) {
    const { data, setData, post, processing, errors } = useForm({ title: '' });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/admissions/online-tests');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tests en ligne" />
            <div className="flex flex-col gap-4 p-4">
                <Heading
                    title="Tests en ligne"
                    description="Tests de positionnement passés à l’écran par les candidats (ordinateur seulement), corrigés dans le TMS."
                />
                {status && <div className="text-sm font-medium text-green-600">{status}</div>}
                <form onSubmit={submit} className="flex max-w-xl flex-wrap items-start gap-2">
                    <div className="grid flex-1 gap-1">
                        <Input
                            aria-label="Titre du nouveau test"
                            placeholder="Titre du nouveau test"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            maxLength={200}
                        />
                        {errors.title && <p className="text-sm text-red-600">{errors.title}</p>}
                    </div>
                    <Button type="submit" disabled={processing || data.title.trim() === ''}>
                        <Plus className="h-4 w-4" />
                        Nouveau test
                    </Button>
                </form>
                <FromPdf />
                {tests.length === 0 ? (
                    <p className="text-muted-foreground rounded-xl border p-8 text-center text-sm">Aucun test en ligne pour le moment.</p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Titre</th>
                                    <th className="px-3 py-2 font-medium">Version publiée</th>
                                    <th className="px-3 py-2 font-medium">Points</th>
                                    <th className="px-3 py-2 font-medium">Durée</th>
                                    <th className="px-3 py-2 font-medium">Modifié</th>
                                </tr>
                            </thead>
                            <tbody>
                                {tests.map((t) => (
                                    <tr key={t.id} className="hover:bg-muted/30 border-t">
                                        <td className="px-3 py-2 font-medium">
                                            <Link href={`/admissions/online-tests/${t.id}/edit`} className="hover:underline">
                                                {t.title}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2">
                                            {t.import_status === 'importing'
                                                ? 'Import en cours…'
                                                : t.import_status === 'failed'
                                                  ? 'Import impossible'
                                                  : t.published_version === null
                                                    ? 'Brouillon'
                                                    : `Version ${t.published_version}`}
                                        </td>
                                        <td className="px-3 py-2">{t.total_points ?? '-'}</td>
                                        <td className="px-3 py-2">{t.time_limit_minutes ? `${t.time_limit_minutes} min` : 'Libre'}</td>
                                        <td className="px-3 py-2">{formatDate(t.updated_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
