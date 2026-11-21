import { SessionDocumentsBlock } from '@/components/documents/session-documents-block';
import { SessionSurveysBlock } from '@/components/questionnaires/session-surveys-block';
import { type ComponentType } from 'react';

/** A block another module adds to a session page (Sessions\Public\SessionBlocks). */
export interface SessionBlock {
    key: string;
    title: string;
    component: string;
    props: Record<string, unknown>;
}

// The components a module may name, by key.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const COMPONENTS: Record<string, ComponentType<any>> = {
    'documents.session': SessionDocumentsBlock,
    'questionnaires.session': SessionSurveysBlock,
};

/** The blocks of the session page, each under its title (anchor = last part of its key: #documents). */
export function SessionBlocks({ blocks }: { blocks: SessionBlock[] }) {
    return (
        <>
            {blocks.map((block) => {
                const Component = COMPONENTS[block.component];
                return Component ? (
                    <section key={block.key} id={block.key.split('.')[0]} aria-label={block.title} className="grid scroll-mt-4 gap-3">
                        <h2 className="text-sm font-semibold">{block.title}</h2>
                        <Component {...block.props} />
                    </section>
                ) : null;
            })}
        </>
    );
}
