<?php

use Illuminate\Support\Facades\Schedule;

// Console commands are classes in app/Console/Commands (auto-discovered).

// Uploaded import files and their raw values are kept 30 days.
Schedule::command('tms:crm:purge-imports')->dailyAt('03:30')->withoutOverlapping()->onOneServer();

// Imports without progress for 15 minutes: resumed once from their cursor, then marked failed.
Schedule::command('tms:crm:import-watchdog')->everyTenMinutes()->withoutOverlapping()->onOneServer();

// Files attached to fiches trashed for more than 12 months are deleted (rows kept).
Schedule::command('tms:crm:purge-attachments')->dailyAt('03:45')->withoutOverlapping()->onOneServer();

// GDPR: list people past the retention period, anonymise those listed 30 days ago.
Schedule::command('tms:crm:gdpr-retention')->dailyAt('03:30')->timezone('Europe/Paris')->withoutOverlapping()->onOneServer();

// Daily summary of the actions to do, for the users who opted in (queued per user).
Schedule::command('tms:crm:daily-digest')->dailyAt('07:30')->timezone('Europe/Paris')->withoutOverlapping()->onOneServer();
