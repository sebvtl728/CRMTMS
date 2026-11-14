import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';
import { Fragment } from 'react';

export function Breadcrumbs({ breadcrumbs }: { breadcrumbs: BreadcrumbItemType[] }) {
    return (
        <>
            {breadcrumbs.length > 0 && (
                // On a phone only the current page shows, cut to the width left by the header buttons.
                <Breadcrumb className="min-w-0">
                    <BreadcrumbList className="flex-nowrap">
                        {breadcrumbs.map((item, index) => {
                            const isLast = index === breadcrumbs.length - 1;
                            return (
                                <Fragment key={index}>
                                    <BreadcrumbItem className={isLast ? 'min-w-0' : 'hidden md:inline-flex'}>
                                        {isLast ? (
                                            <BreadcrumbPage className="block truncate">{item.title}</BreadcrumbPage>
                                        ) : (
                                            <BreadcrumbLink href={item.href}>{item.title}</BreadcrumbLink>
                                        )}
                                    </BreadcrumbItem>
                                    {!isLast && <BreadcrumbSeparator className="hidden md:block" />}
                                </Fragment>
                            );
                        })}
                    </BreadcrumbList>
                </Breadcrumb>
            )}
        </>
    );
}
