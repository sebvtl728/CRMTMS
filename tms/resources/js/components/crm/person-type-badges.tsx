import { Badge } from '@/components/ui/badge';

/** « Particulier » / « Interlocuteur » badges of a person. */
export function PersonTypeBadges({ individual, contact }: { individual: boolean; contact: boolean }) {
    return (
        <>
            {individual && <Badge variant="secondary">Particulier</Badge>}
            {contact && <Badge variant="outline">Interlocuteur</Badge>}
        </>
    );
}
