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

// Sent quotes past their validity (Paris date) become « Expiré ».
Schedule::command('tms:crm:expire-quotes')->dailyAt('00:05')->timezone('Europe/Paris')->withoutOverlapping()->onOneServer();

// Admissions: rejected and withdrawn candidatures anonymised two years after their last change.
Schedule::command('tms:admissions:purge')->dailyAt('03:50')->timezone('Europe/Paris')->withoutOverlapping()->onOneServer();

// Finance (0.9.1): automatic reminders of unpaid invoices, for the organismes that turned them on.
Schedule::command('tms:finance:remind')->dailyAt('08:30')->timezone('Europe/Paris')->withoutOverlapping()->onOneServer();

// Lot 1e: the trainer's follow-up calls and the reactivation calls due today.
Schedule::command('tms:sessions:follow-ups')->dailyAt('06:30')->timezone('Europe/Paris')->withoutOverlapping()->onOneServer();

// 0.11: the hot questionnaires nobody launched and the cold ones due today.
Schedule::command('tms:questionnaires:daily')->dailyAt('07:00')->timezone('Europe/Paris')->withoutOverlapping()->onOneServer();
Schedule::command('tms:automation:daily')->dailyAt('07:15')->timezone('Europe/Paris')->withoutOverlapping()->onOneServer();
