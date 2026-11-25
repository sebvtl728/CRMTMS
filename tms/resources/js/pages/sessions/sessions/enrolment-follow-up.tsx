import { TrainingFollowUp, type TrainingFollowUpProps } from '@/components/admissions/training-follow-up';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';

interface EnrolmentFollowUpProps {
    trainee: { id: string; label: string };
    /** The trainees step of the session. */
    back: string;
    follow_up: TrainingFollowUpProps;
}

/** « Suivi » of a trainee opened from the trainees step of a session. */
export default function EnrolmentFollowUp({ trainee, back, follow_up: followUp }: EnrolmentFollowUpProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sessions', href: route('sessions.index') },
        { title: followUp.session.label, href: back },
        { title: `Suivi de ${trainee.label}`, href: '' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Suivi de ${trainee.label}`} />
            <div className="grid gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Heading title={`Suivi de ${trainee.label}`} description={followUp.session.label} />
                    <Button asChild variant="outline" size="sm">
                        <Link href={back}>
                            <ArrowLeft className="h-4 w-4" />
                            Retour aux stagiaires
                        </Link>
                    </Button>
                </div>
                <TrainingFollowUp followUp={followUp} name={trainee.label} />
            </div>
        </AppLayout>
    );
}
