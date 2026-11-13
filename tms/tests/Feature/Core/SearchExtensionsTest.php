<?php

use Illuminate\Support\Facades\DB;

it('provides unaccent, trigram similarity and an immutable unaccent to the runtime role', function () {
    expect(DB::selectOne('select current_user as u')->u)->toBe('tms_app')
        ->and(DB::selectOne("select immutable_unaccent('Hôtel') as v")->v)->toBe('Hotel')
        ->and(DB::selectOne('select immutable_unaccent(null) as v')->v)->toBeNull()
        ->and((float) DB::selectOne("select similarity('formation', 'formations') as s")->s)->toBeGreaterThan(0.5)
        ->and(DB::selectOne("select provolatile from pg_proc where proname = 'immutable_unaccent'")->provolatile)->toBe('i');
});
