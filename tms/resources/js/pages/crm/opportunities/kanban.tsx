import {
    isFiltered,
    OpportunityExportButton,
    OpportunityFilters,
    opportunityQuery,
    type OpportunityFilterOptions,
    type OpportunityFilterValues,
} from '@/components/crm/opportunity-filters';
import { StageBadge, type OpportunityRow } from '@/components/crm/opportunity-list';
import { StageDialog, type StageOption } from '@/components/crm/stage-dialog';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatAmount, formatDay, type Option } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
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
import { Head, Link, router } from '@inertiajs/react';
import { GripVertical, List } from 'lucide-react';
import { useMemo, useState } from 'react';

interface Column {
    stage: StageOption;
    count: number;
    amount: string;
    weighted_amount: string;
    cards: OpportunityRow[];
}

interface KanbanProps {
    columns: Column[];
    filters: OpportunityFilterValues;
    options: OpportunityFilterOptions;
    stages: StageOption[];
    lossReasons: Option[];
    closedDays: number;
    cardsLimit: number;
    can: { update: boolean; export: boolean };
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Opportunités', href: '/crm/opportunities' },
    { title: 'Pipeline', href: '/crm/opportunities/kanban' },
];

/** What the stage dialog is opened for: a card, and the column it was dropped on (none: chosen in the dialog). */
interface DialogState {
    card: OpportunityRow;
    target?: string;
}

function CardBody({ card }: { card: OpportunityRow }) {
    return (
        <>
            <Link href={route('crm.opportunities.show', card.id)} className="font-medium break-words hover:underline">
                {card.title}
            </Link>
            <p className="text-muted-foreground text-xs">
                {card.account.name ?? '—'} · {card.target === 'individual' ? 'Particulier' : 'Entreprise'}
            </p>
            <p className="mt-1 text-sm">
                {formatAmount(card.amount)} · {card.probability} %
            </p>
            <p className="text-muted-foreground text-xs">
                {[card.das, card.responsible ?? 'Sans responsable'].filter(Boolean).join(' · ')}
                {card.expected_close_on && ` · ${formatDay(card.expected_close_on)}`}
            </p>
        </>
    );
}

function DraggableCard({ card, disabled }: { card: OpportunityRow; disabled: boolean }) {
    const { attributes, listeners, setNodeRef, isDragging } = useDraggable({ id: card.id, data: { card }, disabled });

    return (
        <li ref={setNodeRef} className={`bg-background flex gap-1 rounded-lg border p-2 shadow-xs ${isDragging ? 'opacity-40' : ''}`}>
            {!disabled && (
                <button
                    type="button"
                    {...listeners}
                    {...attributes}
                    aria-label={`Déplacer « ${card.title} »`}
                    className="text-muted-foreground hover:text-foreground -ml-1 cursor-grab touch-none self-start rounded p-0.5 active:cursor-grabbing"
                >
                    <GripVertical className="h-4 w-4" />
                </button>
            )}
            <div className="min-w-0 flex-1">
                <CardBody card={card} />
            </div>
        </li>
    );
}

function ColumnHeader({ column, closedDays }: { column: Column; closedDays: number }) {
    return (
        <header className="border-b p-2">
            <div className="flex items-center justify-between gap-2">
                <StageBadge stage={{ id: column.stage.value, label: column.stage.label, outcome: column.stage.outcome }} />
                <span className="text-muted-foreground text-xs" aria-label={`${column.count} opportunité${column.count > 1 ? 's' : ''}`}>
                    {column.count}
                </span>
            </div>
            <p className="mt-1 text-xs">
                {formatAmount(column.amount)} · pondéré {formatAmount(column.weighted_amount)}
            </p>
            {column.stage.outcome !== null && <p className="text-muted-foreground text-xs">{closedDays} derniers jours</p>}
        </header>
    );
}

function KanbanColumn({
    column,
    cards,
    closedDays,
    cardsLimit,
    canUpdate,
}: {
    column: Column;
    cards: OpportunityRow[];
    closedDays: number;
    cardsLimit: number;
    canUpdate: boolean;
}) {
    const { setNodeRef, isOver } = useDroppable({ id: column.stage.value });

    return (
        <section
            ref={setNodeRef}
            aria-label={column.stage.label}
            className={`bg-muted/40 flex w-64 shrink-0 flex-col rounded-xl border ${isOver ? 'ring-primary ring-2' : ''}`}
        >
            <ColumnHeader column={column} closedDays={closedDays} />
            <ul className="grid min-h-24 content-start gap-2 p-2">
                {cards.map((card) => (
                    <DraggableCard key={card.id} card={card} disabled={!canUpdate} />
                ))}
            </ul>
            {column.count > column.cards.length && (
                <p className="text-muted-foreground px-2 pb-2 text-xs">
                    {cardsLimit} affichées sur {column.count} : affinez les filtres.
                </p>
            )}
        </section>
    );
}

/**
 * « Pipeline »: one column per active stage. Dragging a card to an open stage
 * moves it at once (it goes back if the server refuses); to « Gagné » or
 * « Perdu » the stage dialog asks for the date or the loss reason first.
 * Below 768 px: the opportunities grouped by stage, with « Changer d'étape ».
 */
export default function OpportunitiesKanban({ columns, filters, options, stages, lossReasons, closedDays, cardsLimit, can, status }: KanbanProps) {
    /** Moves sent and not yet answered: card id => target stage (the card is shown there meanwhile). */
    const [pending, setPending] = useState<Record<string, string>>({});
    const [dragged, setDragged] = useState<OpportunityRow | null>(null);
    const [dialog, setDialog] = useState<DialogState | null>(null);
    const [error, setError] = useState<string | null>(null);

    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }), useSensor(KeyboardSensor));

    const cardsByStage = useMemo(() => {
        const all = columns.flatMap((c) => c.cards);
        const byStage: Record<string, OpportunityRow[]> = {};
        columns.forEach((c) => (byStage[c.stage.value] = []));
        all.forEach((card) => byStage[pending[card.id] ?? card.stage.id]?.push(card));

        return byStage;
    }, [columns, pending]);

    const stageLabel = (id: string | number | undefined) => stages.find((s) => s.value === id)?.label ?? '';
    const cardTitle = (id: string | number) => columns.flatMap((c) => c.cards).find((c) => c.id === id)?.title ?? '';
    const announcements: Announcements = {
        onDragStart: ({ active }) => `Opportunité « ${cardTitle(active.id)} » prise.`,
        onDragOver: ({ active, over }) => (over ? `« ${cardTitle(active.id)} » au-dessus de l'étape ${stageLabel(over.id)}.` : undefined),
        onDragEnd: ({ active, over }) =>
            over ? `« ${cardTitle(active.id)} » déposée dans l'étape ${stageLabel(over.id)}.` : `« ${cardTitle(active.id)} » reposée.`,
        onDragCancel: ({ active }) => `Déplacement de « ${cardTitle(active.id)} » annulé.`,
    };

    const move = (card: OpportunityRow, target: string) => {
        setPending((p) => ({ ...p, [card.id]: target }));
        router.post(
            route('crm.opportunities.stage', card.id),
            { stage_id: target },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => setError(Object.values(errors)[0] ?? "Le changement d'étape a été refusé."),
                onFinish: () =>
                    setPending((p) => {
                        const next = { ...p };
                        delete next[card.id];

                        return next;
                    }),
            },
        );
    };

    const onDragStart = ({ active }: DragStartEvent) => {
        setError(null);
        setDragged((active.data.current?.card as OpportunityRow | undefined) ?? null);
    };

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        setDragged(null);
        const card = active.data.current?.card as OpportunityRow | undefined;
        const target = over ? String(over.id) : null;
        if (!card || !target || target === (pending[card.id] ?? card.stage.id)) return;

        const stage = stages.find((s) => s.value === target);
        if (stage?.outcome) {
            // Gagné: date of the win; Perdu: loss reason. Cancelling leaves the card where it was.
            setDialog({ card, target });

            return;
        }
        move(card, target);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pipeline" />

            <div className="flex min-w-0 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Pipeline" description="Opportunités par étape ; faites glisser une carte pour changer son étape" />
                    <div className="flex flex-wrap items-start gap-2">
                        <Button asChild variant="outline">
                            <Link href={route('crm.opportunities.index', opportunityQuery(filters))}>
                                <List className="h-4 w-4" />
                                Vue liste
                            </Link>
                        </Button>
                        {can.export && <OpportunityExportButton filters={filters} />}
                    </div>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}
                {error && (
                    <p role="alert" className="text-destructive text-sm">
                        {error}
                    </p>
                )}

                <OpportunityFilters routeName="crm.opportunities.kanban" filters={filters} options={options} />

                {/* Board: 768 px and above. */}
                <DndContext
                    sensors={sensors}
                    onDragStart={onDragStart}
                    onDragEnd={onDragEnd}
                    onDragCancel={() => setDragged(null)}
                    accessibility={{
                        announcements,
                        screenReaderInstructions: {
                            draggable:
                                "Pour déplacer une opportunité, appuyez sur Espace ou Entrée, choisissez l'étape avec les flèches, puis appuyez de nouveau sur Espace ou Entrée. Échap annule.",
                        },
                    }}
                >
                    <div className="hidden gap-3 overflow-x-auto pb-2 md:flex">
                        {columns.map((column) => (
                            <KanbanColumn
                                key={column.stage.value}
                                column={column}
                                cards={cardsByStage[column.stage.value] ?? []}
                                closedDays={closedDays}
                                cardsLimit={cardsLimit}
                                canUpdate={can.update}
                            />
                        ))}
                    </div>
                    <DragOverlay>
                        {dragged && (
                            <div className="bg-background w-60 rounded-lg border p-2 shadow-lg">
                                <CardBody card={dragged} />
                            </div>
                        )}
                    </DragOverlay>
                </DndContext>

                {/* Below 768 px: grouped list. */}
                <div className="grid gap-4 md:hidden">
                    {columns.map((column) => (
                        <section key={column.stage.value} aria-label={column.stage.label} className="rounded-xl border">
                            <ColumnHeader column={column} closedDays={closedDays} />
                            {column.cards.length === 0 ? (
                                <p className="text-muted-foreground p-3 text-sm">Aucune opportunité.</p>
                            ) : (
                                <ul className="grid gap-2 p-2">
                                    {column.cards.map((card) => (
                                        <li key={card.id} className="rounded-lg border p-2">
                                            <CardBody card={card} />
                                            {can.update && (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    className="mt-2"
                                                    onClick={() => setDialog({ card })}
                                                >
                                                    Changer d'étape
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    ))}
                </div>

                {columns.every((c) => c.count === 0) && (
                    <div className="text-muted-foreground rounded-xl border p-8 text-center text-sm">
                        {isFiltered(filters) ? 'Aucune opportunité ne correspond à ces filtres.' : 'Aucune opportunité pour le moment.'}
                    </div>
                )}
            </div>

            {dialog && (
                <StageDialog
                    open
                    opportunityId={dialog.card.id}
                    currentStageId={dialog.card.stage.id}
                    initialStageId={dialog.target}
                    stages={stages}
                    lossReasons={lossReasons}
                    onClose={() => setDialog(null)}
                />
            )}
        </AppLayout>
    );
}
