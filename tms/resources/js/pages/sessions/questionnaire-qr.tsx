import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';
import { Printer } from 'lucide-react';

interface Props {
    session: string;
    session_id: string;
    title: string;
    url: string;
    qr: string;
    closes_on: string;
}

/**
 * The QR code of the hot questionnaire, full page, to project or print in
 * the room on the last day: each trainee scans it, picks his name and answers.
 */
export default function QuestionnaireQr({ session, session_id, title, url, qr, closes_on }: Props) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-white p-6 text-center text-black">
            <Head title="QR code du questionnaire" />
            <h1 className="text-3xl font-semibold">{title}</h1>
            <p className="text-xl">Session {session}</p>
            <img src={qr} alt="QR code du questionnaire" className="h-auto w-[min(80vw,480px)]" />
            <p className="text-lg">Scannez ce code avec votre téléphone, choisissez votre nom et répondez (2 minutes).</p>
            <p className="font-mono text-sm break-all">{url}</p>
            <p className="text-sm">Ouvert jusqu'au {closes_on}.</p>
            <div className="flex gap-2 print:hidden">
                <Button onClick={() => window.print()}>
                    <Printer className="h-4 w-4" />
                    Imprimer
                </Button>
                <Button variant="outline" asChild>
                    <Link href={`/sessions/${session_id}?etape=stagiaires#questionnaires`}>Retour à la session</Link>
                </Button>
            </div>
        </div>
    );
}
