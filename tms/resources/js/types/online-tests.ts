export type QuestionType = 'short_text' | 'long_text' | 'single_choice' | 'multiple_choice' | 'true_false' | 'number' | 'yes_no';

export interface Choice {
    id: string;
    label: string;
}

export interface Question {
    id: string;
    type: QuestionType;
    label: string;
    support_text?: string | null;
    image?: string | null;
    choices?: Choice[];
    answer?: string | string[] | boolean | null;
    tolerance?: string;
    points: number;
    required: boolean;
}

export interface Section {
    title: string;
    intro?: string | null;
    questions: Question[];
}

export interface TestContent {
    sections: Section[];
}

export const QUESTION_TYPES: { value: QuestionType; label: string }[] = [
    { value: 'short_text', label: 'Réponse courte' },
    { value: 'long_text', label: 'Réponse rédigée' },
    { value: 'single_choice', label: 'Choix unique' },
    { value: 'multiple_choice', label: 'Choix multiple' },
    { value: 'true_false', label: 'Vrai / faux' },
    { value: 'number', label: 'Nombre à compléter' },
    { value: 'yes_no', label: 'Oui / non' },
];

export function newId(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}
