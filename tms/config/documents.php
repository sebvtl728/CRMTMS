<?php

/*
| Document rendering (quotes, later invoices and conventions): HTML rendered
| by Blade, converted to PDF by the internal Gotenberg service of the stack.
*/
return [

    // Internal Gotenberg service (compose service « gotenberg », never published).
    'gotenberg_url' => env('GOTENBERG_URL') ?: 'http://gotenberg:3000',

    // Seconds to wait for a conversion.
    'timeout' => 30,

];
