export type Place = 'company' | 'centre';
export type Signer = 'apprentice' | 'tutor';
export type Weeks = Record<'w1' | 'w2' | 'w3' | 'w45', string | null>;

export const WEEKS: { key: keyof Weeks; label: string }[] = [
    { key: 'w1', label: 'Semaine 1' },
    { key: 'w2', label: 'Semaine 2' },
    { key: 'w3', label: 'Semaine 3' },
    { key: 'w45', label: 'Semaine 4-5' },
];

export const PLACE_LABEL: Record<Place, string> = { company: 'En entreprise', centre: 'En centre de formation' };

export interface SignState {
    status: 'none' | 'sent' | 'signed' | 'paper';
    at: string | null;
    name: string | null;
}

export interface BookletPage {
    entries: Weeks;
    observations: Weeks;
    apprentice: SignState;
    tutor: SignState | null;
    visa: { by: string | null; at: string } | null;
}

export interface LinkState {
    email: string | null;
    sent_at: string | null;
    expires_on: string;
    email_status: string;
    open: boolean;
}

export interface BookletMonthRow {
    month: string;
    label: string;
    places: Place[];
    centre_minutes: number;
    pages: Partial<Record<Place, BookletPage>>;
    links: Record<Signer, LinkState | null>;
}

export interface GridSkill {
    key: string;
    title: string;
    centre: string | null;
    company: string | null;
}

export interface Grid {
    types: { title: string; competences: { title: string; skills: GridSkill[] }[] }[];
    orphans: number;
}

export interface Visit {
    id: string;
    visited_on: string;
    points: string;
    trainer_user_id: string | null;
    trainer: string | null;
}

export const textareaClass =
    'border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden disabled:opacity-60';

export function signLabel(s: SignState): string {
    switch (s.status) {
        case 'signed':
            return `Signé en ligne le ${s.at}`;
        case 'paper':
            return 'Signé sur papier';
        case 'sent':
            return 'Lien envoyé, en attente';
        default:
            return 'À envoyer';
    }
}
