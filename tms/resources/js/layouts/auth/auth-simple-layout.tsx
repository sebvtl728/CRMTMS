import AppLogoIcon from '@/components/app-logo-icon';

interface AuthLayoutProps {
    children: React.ReactNode;
    name?: string;
    title?: string;
    description?: string;
    /** Uses the screen width (online tests on a computer) instead of a narrow centred column. */
    wide?: boolean;
}

export default function AuthSimpleLayout({ children, title, description, wide = false }: AuthLayoutProps) {
    return (
        <div className={`bg-background flex min-h-svh flex-col items-center gap-6 p-6 md:p-10 ${wide ? 'justify-start' : 'justify-center'}`}>
            <div className={wide ? 'w-full max-w-6xl' : 'w-full max-w-sm'}>
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <div className="flex flex-col items-center gap-2 font-medium">
                            <div className="mb-1 flex h-9 w-9 items-center justify-center rounded-md">
                                <AppLogoIcon className="size-9 fill-current text-[var(--foreground)] dark:text-white" />
                            </div>
                            <span className="sr-only">{title}</span>
                        </div>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">{title}</h1>
                            <p className="text-muted-foreground text-center text-sm">{description}</p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
