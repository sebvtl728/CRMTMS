import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
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
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
