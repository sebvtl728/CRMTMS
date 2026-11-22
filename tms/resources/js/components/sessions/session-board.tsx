import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { sendJson, sessionDates, sessionStatusVariant, type SessionRow } from '@/lib/sessions';
import {
    DndContext,
    DragOverlay,
    KeyboardSensor,
    PointerSensor,
    useDraggable,
    useDroppable,
    useSensor,
    useSensors,
    type Announcements,
    type DragEndEvent,
    type DragStartEvent,
} from '@dnd-kit/core';
import { Link, router } from '@inertiajs/react';
import { GripVertical, MoreHorizontal } from 'lucide-react';
import { useMemo, useState } from 'react';

/** A card of the board: a session row with its progress (SessionRows with the kanban's extras). */
export type BoardCard = SessionRow & {
    planned_minutes: number;
    scheduled_minutes: number;
    /** « 3 h 30 / 21 h ». */
    hours_label: string;
    /** Trainees holding a place. */
    enrolled: number;
    /** « Synthèse » cards to do or in alert the viewer sees. */
    pending: number;
    /** Planifiée ↔ Confirmée allowed (shown planned or confirmed, right to update). */
    can_move: boolean;
};

export interface BoardColumn {
    status: SessionRow['status'];
    label: string;
    /** All the matching sessions (the cards stop at 50). */
    total: number;
    cards: BoardCard[];
}

/** The two states a card moves between. */
const MOVABLE = ['planned', 'confirmed'] as const;
type Movable = (typeof MOVABLE)[number];

const isMovable = (status: string): status is Movable => (MOVABLE as readonly string[]).includes(status);

function failure(status: number, message: string | null): string {
    if (status === 403) return 'Vous ne pouvez pas modifier cette session.';
    if (status === 404) return 'Cette session est introuvable.';
    if ((status === 409 || status === 422) && message) return message;

    return "Le changement d'état a échoué, réessayez.";
}

function places(card: BoardCard): string {
    return card.capacity === null ? `${card.enrolled} inscrit${card.enrolled > 1 ? 's' : ''}` : `${card.enrolled} / ${card.capacity} inscrits`;
}

function CardBody({ card }: { card: BoardCard }) {
    const ratio = card.planned_minutes > 0 ? Math.min(100, Math.round((card.scheduled_minutes / card.planned_minutes) * 100)) : 0;
    const over = card.scheduled_minutes > card.planned_minutes;

    return (
        <div className="grid gap-1 text-sm">
            <div className="flex items-start justify-between gap-2">
                <Link href={route('sessions.show', card.id)} className="font-medium break-words hover:underline">
                    {card.label}
                </Link>
                {card.pending > 0 && (
                    <Badge
                        variant="outline"
                        className="shrink-0 border-amber-500 text-amber-700 dark:text-amber-400"
                        title={`${card.pending} point${card.pending > 1 ? 's' : ''} à traiter dans la synthèse`}
                    >
                        {card.pending}
                        <span className="sr-only"> point{card.pending > 1 ? 's' : ''} à traiter</span>
                    </Badge>
                )}
            </div>
            {card.title && card.training && <p className="text-muted-foreground text-xs">{card.training.title}</p>}
            <p className="text-xs">{sessionDates(card)}</p>
            <p className="text-xs">{places(card)}</p>
            <div className="grid gap-0.5">
                <div
                    role="meter"
                    aria-label="Heures planifiées sur les heures prévues"
                    aria-valuemin={0}
                    aria-valuemax={card.planned_minutes}
                    aria-valuenow={card.scheduled_minutes}
                    aria-valuetext={card.hours_label}
                    className="bg-muted h-1.5 overflow-hidden rounded-full"
                >
                    <div className={`h-full ${over ? 'bg-destructive' : 'bg-primary'}`} style={{ width: `${over ? 100 : ratio}%` }} />
                </div>
                <p className="text-muted-foreground text-xs">{card.hours_label} planifiées</p>
            </div>
            <p className="text-muted-foreground text-xs">{card.responsible ?? 'Sans responsable'}</p>
        </div>
    );
}

function DraggableCard({ card, status, onMove }: { card: BoardCard; status: string; onMove: (card: BoardCard, to: Movable) => void }) {
    const { attributes, listeners, setNodeRef, isDragging } = useDraggable({ id: card.id, data: { card }, disabled: !card.can_move });
    const other: Movable | null = status === 'planned' ? 'confirmed' : status === 'confirmed' ? 'planned' : null;

    return (
        <li
            ref={setNodeRef}
            style={card.color ? { borderLeftColor: card.color } : undefined}
            className={`bg-background flex gap-1 rounded-lg border p-2 shadow-xs ${card.color ? 'border-l-4' : ''} ${isDragging ? 'opacity-40' : ''}`}
        >
            {card.can_move && (
                <button
                    type="button"
                    {...listeners}
                    {...attributes}
                    aria-label={`Déplacer « ${card.label} »`}
                    className="text-muted-foreground hover:text-foreground -ml-1 cursor-grab touch-none self-start rounded p-0.5 active:cursor-grabbing"
                >
                    <GripVertical className="h-4 w-4" />
                </button>
            )}
            <div className="min-w-0 flex-1">
                <CardBody card={card} />
            </div>
            {card.can_move && other && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button type="button" variant="ghost" size="icon" className="h-6 w-6 shrink-0" aria-label={`Actions de « ${card.label} »`}>
                            <MoreHorizontal className="h-4 w-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onSelect={() => onMove(card, other)}>
                            {other === 'confirmed' ? 'Passer en confirmée' : 'Repasser en planifiée'}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </li>
    );
}

function Column({
    column,
    cards,
    listHref,
    onMove,
}: {
    column: BoardColumn;
    cards: BoardCard[];
    listHref: (status: string) => string;
    onMove: (card: BoardCard, to: Movable) => void;
}) {
    const { setNodeRef, isOver } = useDroppable({ id: column.status, disabled: !isMovable(column.status) });
    const others = column.total - column.cards.length;

    return (
        <section
            ref={setNodeRef}
            aria-label={column.label}
            className={`bg-muted/40 flex w-72 shrink-0 flex-col rounded-xl border ${isOver ? 'ring-primary ring-2' : ''}`}
        >
            <header className="flex items-center justify-between gap-2 border-b p-2">
                <Badge variant={sessionStatusVariant(column.status)}>{column.label}</Badge>
                <span className="text-muted-foreground text-xs" aria-label={`${column.total} session${column.total > 1 ? 's' : ''}`}>
                    {column.total}
                </span>
            </header>
            {column.status === 'done' && <p className="text-muted-foreground px-2 pt-2 text-xs">Terminées depuis moins de 90 jours</p>}
            <ul className="grid min-h-24 content-start gap-2 p-2">
                {cards.map((card) => (
                    <DraggableCard key={card.id} card={card} status={column.status} onMove={onMove} />
                ))}
                {cards.length === 0 && <li className="text-muted-foreground p-2 text-xs">Aucune session.</li>}
            </ul>
            {(others > 0 || column.status === 'done') && (
                <div className="grid gap-1 px-2 pb-2 text-xs">
                    {others > 0 && (
                        <Link href={listHref(column.status)} className="hover:underline">
                            Voir les {others} autre{others > 1 ? 's' : ''}
                        </Link>
                    )}
                    {column.status === 'done' && (
                        <Link href={listHref('done')} className="hover:underline">
                            Voir toutes les sessions terminées
                        </Link>
                    )}
                </div>
            )}
        </section>
    );
}

/**
 * « Sessions en colonnes »: one column per state shown on the Paris day.
 * A card moves only between « Planifiée » and « Confirmée » (by dragging it,
 * or with its menu from the keyboard); the card shows in its new column
 * at once and goes back with a message if the server refuses. Columns
 * scroll sideways on a phone.
 */
export function SessionBoard({ columns, listHref }: { columns: BoardColumn[]; listHref: (status: string) => string }) {
    /** Moves sent and not yet answered: card id => target state. */
    const [pending, setPending] = useState<Record<string, Movable>>({});
    const [dragged, setDragged] = useState<BoardCard | null>(null);
    const [error, setError] = useState<string | null>(null);

    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }), useSensor(KeyboardSensor));

    const cardsByStatus = useMemo(() => {
        const byStatus: Record<string, BoardCard[]> = {};
        columns.forEach((c) => (byStatus[c.status] = []));
        columns.forEach((c) => c.cards.forEach((card) => byStatus[pending[card.id] ?? c.status]?.push(card)));

        return byStatus;
    }, [columns, pending]);

    const statusOf = (card: BoardCard) => pending[card.id] ?? columns.find((c) => c.cards.some((x) => x.id === card.id))?.status;
    const label = (id: string | number | undefined) => columns.find((c) => c.status === id)?.label ?? '';
    const cardLabel = (id: string | number) => columns.flatMap((c) => c.cards).find((c) => c.id === id)?.label ?? '';
    const announcements: Announcements = {
        onDragStart: ({ active }) => `Session « ${cardLabel(active.id)} » prise.`,
        onDragOver: ({ active, over }) => (over ? `« ${cardLabel(active.id)} » au-dessus de la colonne ${label(over.id)}.` : undefined),
        onDragEnd: ({ active, over }) =>
            over ? `« ${cardLabel(active.id)} » déposée dans la colonne ${label(over.id)}.` : `« ${cardLabel(active.id)} » reposée.`,
        onDragCancel: ({ active }) => `Déplacement de « ${cardLabel(active.id)} » annulé.`,
    };

    const forget = (id: string) =>
        setPending((p) => {
            const next = { ...p };
            delete next[id];

            return next;
        });

    const move = async (card: BoardCard, to: Movable) => {
        setError(null);
        setPending((p) => ({ ...p, [card.id]: to }));
        try {
            const answer = await sendJson('POST', route('sessions.status', card.id), { status: to });
            if (!answer.ok) {
                setError(failure(answer.status, answer.message));
                forget(card.id);

                return;
            }
        } catch {
            setError("Le changement d'état a échoué, réessayez.");
            forget(card.id);

            return;
        }
        router.reload({ only: ['board'], onFinish: () => forget(card.id) });
    };

    const onDragStart = ({ active }: DragStartEvent) => {
        setError(null);
        setDragged((active.data.current?.card as BoardCard | undefined) ?? null);
    };

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        setDragged(null);
        const card = active.data.current?.card as BoardCard | undefined;
        const target = over ? String(over.id) : null;
        if (!card || !target || !isMovable(target) || target === statusOf(card)) return;
        void move(card, target);
    };

    return (
        <div className="grid gap-2">
            {error && (
                <p role="alert" className="text-destructive text-sm">
                    {error}
                </p>
            )}
            <DndContext
                sensors={sensors}
                onDragStart={onDragStart}
                onDragEnd={onDragEnd}
                onDragCancel={() => setDragged(null)}
                accessibility={{
                    announcements,
                    screenReaderInstructions: {
                        draggable:
                            'Pour déplacer une session, appuyez sur Espace ou Entrée, choisissez la colonne avec les flèches, puis appuyez de nouveau sur Espace ou Entrée. Échap annule. Le menu de la carte propose aussi le changement.',
                    },
                }}
            >
                <div className="flex gap-3 overflow-x-auto pb-2">
                    {columns.map((column) => (
                        <Column key={column.status} column={column} cards={cardsByStatus[column.status] ?? []} listHref={listHref} onMove={move} />
                    ))}
                </div>
                <DragOverlay>
                    {dragged && (
                        <div className="bg-background w-64 rounded-lg border p-2 shadow-lg">
                            <CardBody card={dragged} />
                        </div>
                    )}
                </DragOverlay>
            </DndContext>
        </div>
    );
}
