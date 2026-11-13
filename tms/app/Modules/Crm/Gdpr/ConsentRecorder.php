<?php

namespace App\Modules\Crm\Gdpr;

use App\Modules\Crm\People\Person;
use Illuminate\Support\Facades\DB;

/**
 * Appends a consent; on the prospecting channel it also sets (refusal) or
 * lifts (consent) the opposition to prospecting of the person.
 */
final class ConsentRecorder
{
    public const OPT_OUT_SOURCE = 'Opposition à la prospection';

    public function record(Person $person, ConsentChannel $channel, bool $granted, ?string $source): Consent
    {
        return DB::transaction(function () use ($person, $channel, $granted, $source): Consent {
            $consent = Consent::query()->create([
                'person_id' => $person->id, 'channel' => $channel, 'granted' => $granted,
                'source' => $source === null || trim($source) === '' ? null : trim($source),
            ]);

            if ($channel === ConsentChannel::Prospecting) {
                if (! $granted && $person->prospecting_opt_out_at === null) {
                    $person->prospecting_opt_out_at = now();
                    $person->save();
                } elseif ($granted && $person->prospecting_opt_out_at !== null) {
                    $person->prospecting_opt_out_at = null;
                    $person->save();
                }
            }

            return $consent;
        });
    }
}
