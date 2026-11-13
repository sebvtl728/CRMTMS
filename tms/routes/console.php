<?php

use Illuminate\Support\Facades\Schedule;

// Console commands are classes in app/Console/Commands (auto-discovered).

// Uploaded import files and their raw values are kept 30 days.
Schedule::command('tms:crm:purge-imports')->dailyAt('03:30')->withoutOverlapping()->onOneServer();

// GDPR: list people past the retention period, anonymise those listed 30 days ago.
Schedule::command('tms:crm:gdpr-retention')->dailyAt('03:30')->timezone('Europe/Paris')->withoutOverlapping()->onOneServer();
