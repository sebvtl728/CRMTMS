import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface TemplateRow {
    key: string;
    label: string;
    description: string;
    editable: boolean;
    customised: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: "Modèles d'e-mails", href: '/admin/mail-templates' }];

export default function MailTemplatesIndex({ templates, status }: { templates: TemplateRow[]; status?: string }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Modèles d'e-mails" />

            <div className="flex flex-col gap-4 p-4">
                <Heading title="Modèles d'e-mails" description="Les textes des e-mails envoyés par le TMS au nom de votre organisme" />
                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <ul className="grid gap-3">
                    {templates.map((t) => (
                        <li key={t.key} className="rounded-xl border p-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <Link href={route('admin.mail-templates.edit', t.key)} className="font-medium underline">
                                    {t.label}
                                </Link>
                                {!t.editable && <Badge variant="outline">Modèle système</Badge>}
                                {t.customised && <Badge variant="secondary">Personnalisé</Badge>}
                            </div>
                            <p className="text-muted-foreground mt-1 text-sm">{t.description}</p>
                        </li>
                    ))}
                </ul>
            </div>
        </AppLayout>
    );
}
