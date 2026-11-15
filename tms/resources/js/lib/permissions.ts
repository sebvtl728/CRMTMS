/** The four « Consulter » rights of the CRM domains (fiches, opportunités, devis, actions). */
export const CRM_VIEW_PERMISSIONS = ['crm.fiches.view', 'crm.opportunities.view', 'crm.quotes.view', 'crm.activities.view'] as const;

/** At least one « Consulter » right: the CRM is open (search, home shortcuts); the server stays the authority. */
export function canViewCrm(permissions: string[]): boolean {
    return CRM_VIEW_PERMISSIONS.some((permission) => permissions.includes(permission));
}
