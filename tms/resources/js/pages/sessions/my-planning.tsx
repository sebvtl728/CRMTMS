import Heading from '@/components/heading';
import { Unavailabilities, type UnavailabilityRow } from '@/components/sessions/unavailabilities';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface Props {
    trainer: { id: string; label: string; status_label: string } | null;
    unavailabilities: UnavailabilityRow[];
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Mon planning', href: '/mon-planning' }];

export default function MyPlanning({ trainer, unavailabilities, status }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Mon planning" />

            <div className="flex flex-col gap-8 p-4">
                <Heading
                    title="Mon planning"
                    description={trainer ? `${trainer.label} · formateur ${trainer.status_label.toLowerCase()}` : 'Vos séances et vos indisponibilités.'}
                />

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                {trainer === null ? (
                    <p className="text-sm">
                        Votre compte n'est lié à aucune fiche formateur. Demandez à la personne qui gère les formateurs de le lier à votre fiche.
                    </p>
                ) : (
                    <>
                        <section className="flex flex-col gap-3" aria-labelledby="seances_title">
                            <h2 id="seances_title" className="text-lg font-semibold">
                                Mes séances
                            </h2>
                            <p className="text-muted-foreground text-sm">Aucune séance planifiée.</p>
                        </section>

                        <section className="flex flex-col gap-3" aria-labelledby="unavailabilities_title">
                            <h2 id="unavailabilities_title" className="text-lg font-semibold">
                                Mes indisponibilités
                            </h2>
                            <p className="text-muted-foreground text-sm">Signalez les périodes où vous ne pouvez pas assurer de séance.</p>
                            <Unavailabilities
                                rows={unavailabilities}
                                storeUrl={route('my-planning.unavailabilities.store')}
                                destroyUrl={(id) => route('my-planning.unavailabilities.destroy', id)}
                            />
                        </section>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
