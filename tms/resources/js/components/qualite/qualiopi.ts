/** Shared by the Qualiopi pages. */
export interface IndicatorRow {
    number: number;
    criterion: number;
    title: string;
    text: string;
    scope: string;
    scope_label: string;
    change: string | null;
    change_label: string | null;
    applicable: boolean;
    not_applicable_reason: string | null;
    status: 'todo' | 'in_progress' | 'ready' | 'to_review';
    responsible_user_id: string | null;
    review_on: string | null;
    notes: string | null;
}

export const STATUS_STYLES: Record<string, string> = {
    todo: 'bg-muted text-muted-foreground',
    in_progress: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    ready: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    to_review: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
};
