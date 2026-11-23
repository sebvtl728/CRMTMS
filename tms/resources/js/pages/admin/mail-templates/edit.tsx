import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Eye, RotateCcw, Send } from 'lucide-react';
import { FormEventHandler, useRef, useState } from 'react';

interface Variable {
    name: string;
    description: string;
    example: string;
}

interface TemplateData {
    key: string;
    label: string;
    description: string;
    editable: boolean;
    customised: boolean;
    subject: string;
    body: string;
    variables: Variable[];
}

const textareaClass =
    'border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 font-mono text-sm focus:ring-2 focus:outline-hidden';

function xsrf(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export default function MailTemplateEdit({ template: t }: { template: TemplateData; status?: string }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: "Modèles d'e-mails", href: '/admin/mail-templates' },
        { title: t.label, href: route('admin.mail-templates.edit', t.key) },
    ];
    const { data, setData, put, processing, errors, clearErrors, setError } = useForm({ subject: t.subject, body: t.body });
    const [focus, setFocus] = useState<'subject' | 'body'>('body');
    const [preview, setPreview] = useState<{ subject: string; body: string } | null>(null);
    const subjectRef = useRef<HTMLInputElement>(null);
    const bodyRef = useRef<HTMLTextAreaElement>(null);

    const insert = (name: string) => {
        const token = `{${name}}`;
        const field = focus;
        const el = field === 'subject' ? subjectRef.current : bodyRef.current;
        const value = data[field];
        const start = el?.selectionStart ?? value.length;
        const end = el?.selectionEnd ?? value.length;
        setData(field, value.slice(0, start) + token + value.slice(end));
        requestAnimationFrame(() => {
            el?.focus();
            el?.setSelectionRange(start + token.length, start + token.length);
        });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('admin.mail-templates.update', t.key), { preserveScroll: true });
    };

    const showPreview = async () => {
        clearErrors();
        const response = await fetch(route('admin.mail-templates.preview', t.key), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify(data),
        });
        const json = await response.json();
        if (response.ok) {
            setPreview(json);
        } else {
            setPreview(null);
            Object.entries((json.errors ?? {}) as Record<string, string[]>).forEach(([k, v]) => setError(k as 'subject' | 'body', v[0]));
        }
    };

    const sendTest = () => router.post(route('admin.mail-templates.test', t.key), data, { preserveScroll: true, preserveState: true });

    const reset = () => {
        if (!window.confirm('Rétablir le modèle par défaut ? Votre texte sera remplacé par celui du TMS.')) return;
        router.delete(route('admin.mail-templates.destroy', t.key), {
            preserveScroll: true,
            // The form shows the default text again (the page props changed, not the form's state).
            onSuccess: (page) => {
                const fresh = (page.props as unknown as { template: TemplateData }).template;
                setData({ subject: fresh.subject, body: fresh.body });
                setPreview(null);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t.label} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Heading title={t.label} description={t.description} />
                    {!t.editable && <Badge variant="outline">Modèle système</Badge>}
                    {t.customised && <Badge variant="secondary">Personnalisé</Badge>}
                </div>

                {!t.editable ? (
                    <div className="max-w-3xl rounded-xl border p-4 text-sm">
                        <p className="font-medium">{t.subject}</p>
                        <p className="text-muted-foreground mt-2 whitespace-pre-line">{t.body}</p>
                    </div>
                ) : (
                    <div className="grid gap-6 lg:grid-cols-[1fr_18rem]">
                        <form onSubmit={submit} className="grid max-w-3xl gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="subject">Objet</Label>
                                <Input
                                    id="subject"
                                    ref={subjectRef}
                                    maxLength={200}
                                    value={data.subject}
                                    onFocus={() => setFocus('subject')}
                                    onChange={(e) => setData('subject', e.target.value)}
                                />
                                <InputError message={errors.subject} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="body">Texte</Label>
                                <textarea
                                    id="body"
                                    ref={bodyRef}
                                    rows={14}
                                    maxLength={10000}
                                    value={data.body}
                                    onFocus={() => setFocus('body')}
                                    onChange={(e) => setData('body', e.target.value)}
                                    className={textareaClass}
                                />
                                <InputError message={errors.body} />
                                <InputError message={(errors as Record<string, string | undefined>).test} />
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button type="submit" disabled={processing}>
                                    Enregistrer
                                </Button>
                                <Button type="button" variant="outline" onClick={showPreview}>
                                    <Eye className="h-4 w-4" />
                                    Aperçu
                                </Button>
                                <Button type="button" variant="outline" onClick={sendTest}>
                                    <Send className="h-4 w-4" />
                                    Envoyer un e-mail de test
                                </Button>
                                {t.customised && (
                                    <Button type="button" variant="outline" onClick={reset}>
                                        <RotateCcw className="h-4 w-4" />
                                        Rétablir le modèle par défaut
                                    </Button>
                                )}
                            </div>
                            {preview && (
                                <section aria-label="Aperçu" className="bg-muted/30 rounded-xl border p-4 text-sm">
                                    <p className="text-muted-foreground text-xs">Aperçu avec des données d'exemple</p>
                                    <p className="mt-1 font-medium">{preview.subject}</p>
                                    <p className="mt-2 whitespace-pre-line">{preview.body}</p>
                                </section>
                            )}
                        </form>
                        <aside className="grid content-start gap-2">
                            <h2 className="text-sm font-semibold">Variables</h2>
                            <p className="text-muted-foreground text-xs">
                                Cliquez pour insérer à l'endroit du curseur. Elles sont remplacées par leur valeur à l'envoi.
                            </p>
                            <ul className="grid gap-1">
                                {t.variables.map((v) => (
                                    <li key={v.name}>
                                        <button
                                            type="button"
                                            onClick={() => insert(v.name)}
                                            className="hover:bg-muted w-full rounded-md border px-2 py-1 text-left text-xs"
                                            title={`Exemple : ${v.example}`}
                                        >
                                            <code>{`{${v.name}}`}</code>
                                            <span className="text-muted-foreground"> - {v.description}</span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </aside>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
