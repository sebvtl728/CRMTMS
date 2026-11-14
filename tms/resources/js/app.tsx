import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { initializeTheme } from './hooks/use-appearance';

declare global {
    const route: typeof routeFn;
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Tab title: the organisme's name (default company) once signed in, else the application's.
let siteName = appName;
const brandName = (props: Record<string, unknown>): string => {
    const brand = props.brand as { name?: string } | null | undefined;

    return brand?.name || appName;
};

createInertiaApp({
    title: (title) => (title ? `${title} - ${siteName}` : siteName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);
        siteName = brandName(props.initialPage.props);
        router.on('navigate', (event) => {
            siteName = brandName(event.detail.page.props);
        });

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
