<?php

namespace App\Modules\Crm\Activities;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;

/**
 * « À faire aujourd'hui »: the overdue and today's actions of a user, each
 * with its title, its fiche and its due time; a link to the « À faire »
 * page. No report, no place.
 */
class DailyDigestMail extends Mailable
{
    /**
     * Private on purpose: public properties of a Mailable are passed to the
     * view and would replace the prepared rows of the same name.
     *
     * @param  Collection<int, Activity>  $overdue
     * @param  Collection<int, Activity>  $today
     */
    public function __construct(private readonly string $name, private readonly Collection $overdue, private readonly Collection $today) {}

    public function build(): self
    {
        $count = $this->overdue->count() + $this->today->count();
        $tz = (string) config('app.timezone');
        $line = fn (Activity $a): array => [
            'subject' => $a->subject,
            'fiche' => $a->ficheName(),
            'date' => $a->due_at?->setTimezone($tz)->format('d/m/Y'),
            'time' => $a->due_at?->setTimezone($tz)->format('H:i'),
        ];

        return $this->subject($count === 1 ? 'À faire aujourd\'hui : 1 action' : "À faire aujourd'hui : {$count} actions")
            ->view('mail.crm-daily-digest', [
                'name' => $this->name,
                'overdue' => $this->overdue->map($line)->all(),
                'today' => $this->today->map($line)->all(),
                'url' => route('crm.todo'),
            ]);
    }
}
