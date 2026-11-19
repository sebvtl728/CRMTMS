import { BlockRows, type FicheBlock } from '@/components/crm/fiche-blocks';
import { DocumentsBlock } from '@/components/documents/documents-block';
import { BillingBlock } from '@/components/finance/billing-block';
import { type ComponentType } from 'react';

/**
 * A block another module adds to a dossier (Sessions\Public\DossierBlocks):
 * the rows of a fiche block, and optionally an interactive component named
 * by the server (« component », with its « props »), shown above the rows.
 */
export interface DossierBlock extends FicheBlock {
    component?: string;
    props?: Record<string, unknown>;
}

// The interactive components a module may name, by key.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const COMPONENTS: Record<string, ComponentType<any>> = {
    'finance.billing': BillingBlock,
    'documents.dossier': DocumentsBlock,
};

/** A block of the dossier page: its component (when known), then its rows. */
export function DossierBlockPanel({ block }: { block: DossierBlock }) {
    const Component = block.component ? COMPONENTS[block.component] : undefined;

    return (
        <div className="grid gap-3">
            {Component && <Component {...block.props} />}
            {/* A component listing its own items sends no rows and an empty « empty » text: nothing more to show. */}
            {(block.rows.length > 0 || block.empty !== '') && <BlockRows block={block} />}
        </div>
    );
}
