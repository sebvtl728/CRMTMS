import { SVGAttributes } from 'react';

/** A neutral training pictogram (a mortarboard): the product's own mark, never a framework's. */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M20 6 2 14l18 8 14-6.2V26h3V14.3L20 6Z" />
            <path d="M9 19.6V27c0 3 5 6 11 6s11-3 11-6v-7.4l-11 4.9-11-4.9Z" />
        </svg>
    );
}
