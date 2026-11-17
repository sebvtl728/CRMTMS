import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuBadge, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';

const matches = (url: string, itemUrl: string) => url === itemUrl || url.startsWith(itemUrl + '/') || url.startsWith(itemUrl + '?');

export function NavMain({ items = [], label }: { items: NavItem[]; label: string }) {
    const page = usePage();
    // The most specific entry wins (« Pipeline » /crm/opportunities/kanban over « Opportunités »).
    const active = items
        .filter((item) => matches(page.url, item.url))
        .reduce<NavItem | null>((best, item) => (best === null || item.url.length > best.url.length ? item : best), null);

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton asChild isActive={item === active}>
                            <Link href={item.url} prefetch>
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                        {item.alert && (
                            <SidebarMenuBadge aria-label={item.alert} title={item.alert}>
                                <span className="bg-destructive block h-2.5 w-2.5 rounded-full" aria-hidden />
                            </SidebarMenuBadge>
                        )}
                        {item.badge !== undefined && item.badge > 0 && (
                            <SidebarMenuBadge aria-label={`${item.badge} à faire`}>{item.badge > 99 ? '99+' : item.badge}</SidebarMenuBadge>
                        )}
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
