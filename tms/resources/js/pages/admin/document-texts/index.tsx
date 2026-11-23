import { OptionSelect, textareaClass } from '@/components/admissions/fields';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Block {
    key: string;
    label: string;
    required: boolean;
    default: string;
    body: string;
    overridden: boolean;
}

interface Props {
    types: { value: string; label: string }[];
    type: string;
    companies: { value: string; label: string }[];
    company_id: string | null;
    fields: string[];
    blocks: Block[];
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Modèles de documents', href: '/admin/document-texts' }];

/** One block: its text for the chosen level (organisme or company), saved on its own. */
function BlockForm({ block, type, companyId }: { block: Block; type: string; companyId: string | null }) {
    const { data, setData, put, processing, errors } = useForm({ body: block.body, company_id: companyId });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/admin/document-texts/${type}/${block.key}`, { preserveScroll: true });
    };
    const reset = () => {
        if (window.confirm(`Revenir au texte d’origine pour « ${block.label} » ?`)) {
            router.delete(`/admin/document-texts/${type}/${block.key}`, { data: { company_id: companyId }, preserveScroll: true });
        }
    };

    return (
        <form onSubmit={submit} className="grid gap-2 rounded-xl border p-4">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="font-semibold">{block.label}</h2>
                {block.required && <Badge variant="outline">Obligatoire</Badge>}
                {block.overridden ? (
                    <Badge>Personnalisé</Badge>
                ) : (
                    <span className="text-muted-foreground text-xs">Texte d’origine ou de l’organisme</span>
                )}
            </div>
            <textarea
                aria-label={block.label}
                className={`${textareaClass} min-h-24`}
                value={data.body}
                onChange={(e) => setData('body', e.target.value)}
            />
            <InputError message={errors.body} />
            <div className="flex flex-wrap gap-2">
                <Button type="submit" size="sm" disabled={processing || data.body === block.body}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Enregistrer
                </Button>
                {block.overridden && (
                    <Button type="button" size="sm" variant="outline" onClick={reset}>
                        Revenir au texte d’origine
                    </Button>
                )}
            </div>
        </form>
    );
}

export default function DocumentTexts({ types, type, companies, company_id, fields, blocks }: Props) {
    const go = (next: { type?: string; company?: string | null }) =>
        router.get(
            '/admin/document-texts',
            { type: next.type ?? type, company: next.company === undefined ? company_id : next.company },
            { preserveScroll: true },
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Modèles de documents" />
            <div className="flex max-w-4xl flex-col gap-4 p-4">
                <Heading
                    title="Modèles de documents"
                    description="La mise en page est fournie par le TMS ; vous adaptez les textes, pour l’organisme ou pour une société. Un texte vide revient au texte d’origine."
                />
                <div className="grid gap-3 sm:grid-cols-2">
                    <OptionSelect id="doc-type" label="Document" value={type} options={types} onChange={(v) => v && go({ type: v })} />
                    <OptionSelect
                        id="doc-company"
                        label="Textes de"
                        value={company_id}
                        options={companies}
                        noneLabel="L’organisme (toutes les sociétés)"
                        onChange={(v) => go({ company: v })}
                    />
                </div>
                <p className="text-muted-foreground text-xs">
                    Champs utilisables : {fields.join(' ')}. Ils sont remplacés par les données du dossier à l’émission. Pas de mise en forme ni de
                    balises.
                </p>
                {blocks.map((b) => (
                    <BlockForm key={`${type}-${company_id ?? 'org'}-${b.key}-${b.body}`} block={b} type={type} companyId={company_id} />
                ))}
            </div>
        </AppLayout>
    );
}
