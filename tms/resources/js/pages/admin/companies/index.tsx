import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Building2, Pencil } from 'lucide-react';

interface CompanyRow {
    id: string;
    name: string;
    legal_form: string;
    siret: string | null;
    vat_regime: string;
    is_default: boolean;
    mentions: string;
}

interface CompaniesIndexProps {
    companies: CompanyRow[];
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sociétés', href: '/admin/companies' }];

export default function CompaniesIndex({ companies }: CompaniesIndexProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sociétés" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading title="Sociétés" description="Entités juridiques émettrices de vos devis et factures" />
                    <Button asChild>
                        <Link href={route('admin.companies.create')}>
                            <Building2 className="h-4 w-4" />
                            Ajouter une société
                        </Link>
                    </Button>
                </div>

                <section className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-2 font-medium">Société</th>
                                <th className="px-4 py-2 font-medium">Forme juridique</th>
                                <th className="px-4 py-2 font-medium">SIRET</th>
                                <th className="px-4 py-2 font-medium">TVA</th>
                                <th className="px-4 py-2 font-medium">Mentions légales</th>
                                <th className="px-4 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {companies.map((company) => (
                                <tr key={company.id} className="border-t align-top">
                                    <td className="px-4 py-2 font-medium">
                                        {company.name} {company.is_default && <Badge variant="secondary">Par défaut</Badge>}
                                    </td>
                                    <td className="px-4 py-2">{company.legal_form}</td>
                                    <td className="px-4 py-2">{company.siret ?? <Badge variant="outline">À compléter</Badge>}</td>
                                    <td className="px-4 py-2">{company.vat_regime}</td>
                                    <td className="text-muted-foreground px-4 py-2">{company.mentions}</td>
                                    <td className="px-4 py-2 text-right">
                                        <Button asChild variant="ghost" size="sm">
                                            <Link href={route('admin.companies.edit', company.id)}>
                                                <Pencil className="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                            {companies.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-4 py-6 text-center">
                                        Aucune société.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </section>
            </div>
        </AppLayout>
    );
}
