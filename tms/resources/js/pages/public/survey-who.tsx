import AuthLayout from '@/layouts/auth-layout';
import { Head, router } from '@inertiajs/react';

interface Props {
    organisme: string;
    title: string;
    session: string;
    trainees: { id: string; name: string }[];
}

/**
 * The page of the session QR code: the trainee picks his name among those
 * who have not answered yet, then reaches his own questionnaire.
 */
export default function SurveyWho({ organisme, title, session, people }: Props) {
    return (
        <AuthLayout title={title} description={`Session ${session}${organisme ? ` - ${organisme}` : ''}. Qui êtes-vous ?`}>
            <Head title="Questionnaire" />
            {trainees.length === 0 ? (
                <p className="text-sm">Tous les stagiaires ont répondu. Merci !</p>
            ) : (
                <ul className="grid gap-2">
                    {trainees.map((p) => (
                        <li key={p.id}>
                            <button
                                type="button"
                                className="hover:bg-accent min-h-12 w-full rounded-md border px-4 text-left text-base"
                                onClick={() => router.post(window.location.pathname, { invitation_id: p.id })}
                            >
                                {p.name}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </AuthLayout>
    );
}
