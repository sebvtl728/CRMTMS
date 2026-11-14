<?php

use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Search\GlobalSearch;
use Illuminate\Support\Facades\DB;

/*
 * Volume: 50 000 fiches (25 000 entreprises, 25 000 personnes, each one
 * attached to an entreprise) of the same organisme, searched as the runtime role (tms_app, FORCE RLS). Slow group:
 * excluded from the default run (phpunit.xml), run with
 * `php artisan test --group=slow`.
 */

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

it('searches 50 000 fiches in less than a second under rls', function () {
    $user = commercial();
    $this->actingAs($user);
    $tenant = tenant()->id;

    expect(DB::selectOne('select current_user as role')->role)->toBe('tms_app');

    // Set-based inserts (one statement each): the generated search columns are computed by the database.
    DB::insert(<<<'SQL'
        insert into organisations (id, tenant_id, name, city, postal_code, status, created_at, updated_at)
        select gen_random_uuid(), ?, 'Entreprise ' || lpad(i::text, 5, '0') || ' ' || (array['Boulangerie','Garage','Hôtel','Menuiserie','Pharmacie'])[1 + i % 5],
               'Ville ' || (i % 500), lpad((10000 + i)::text, 5, '0'), 'prospect', now(), now()
        from generate_series(1, 25000) as i
        SQL, [$tenant]);
    DB::insert(<<<'SQL'
        insert into people (id, tenant_id, first_name, last_name, email, city, status, legal_basis, created_at, updated_at)
        select gen_random_uuid(), ?, 'Prénom' || i, 'Nom' || lpad(i::text, 5, '0'), 'personne' || i || '@exemple.fr',
               'Ville ' || (i % 500), 'prospect', 'interet_legitime', now(), now()
        from generate_series(1, 25000) as i
        SQL, [$tenant]);
    // Every person is an interlocuteur (the people search also looks into the work e-mail and phone).
    DB::insert(<<<'SQL'
        insert into affiliations (id, tenant_id, person_id, organisation_id, work_email, created_at, updated_at)
        select gen_random_uuid(), ?, p.id, o.id, 'pro' || p.rn || '@exemple.fr', now(), now()
        from (select id, row_number() over (order by last_name) as rn from people) p
        join (select id, row_number() over (order by name) as rn from organisations) o using (rn)
        SQL, [$tenant]);
    DB::statement('select public.crm_refresh_statistics()');

    expect(DB::table('organisations')->count() + DB::table('people')->count())->toBe(50000);

    $search = app(GlobalSearch::class);
    $search->query('garage', $user); // warm-up (plan cache, connection)

    $started = microtime(true);
    $result = $search->query('entreprise 01234', $user);
    $people = $search->query('nom12345', $user);
    $seconds = microtime(true) - $started;
    fwrite(STDERR, sprintf("\nsearch over 50 000 fiches: %.0f ms\n", $seconds * 1000));

    expect(collect($result['organisations'])->pluck('name')->all())->toBe(['Entreprise 01234 Pharmacie'])
        ->and(collect($people['people'])->pluck('name')->all())->toHaveCount(1)
        ->and($seconds)->toBeLessThan(1.0);

    // Plans as the runtime role, RLS applied. Under FORCE RLS, LIKE (textlike) is not leakproof:
    // PostgreSQL never turns it into a condition of the trigram index (search_vector_trgm), it filters
    // the organisme's rows after the policy. Measured above: well under a second at 50 000 fiches.
    // Using the trigram index under RLS would need a leakproof operator, which only a superuser can
    // create (see the task report).
    foreach (['organisations' => Organisation::query()->search('01234'), 'people' => Person::query()->search('nom12345')] as $table => $query) {
        $plan = collect(DB::select('explain analyze '.$query->toRawSql()))->pluck('QUERY PLAN')->implode("\n");
        fwrite(STDERR, "\n{$plan}\n");
        expect($plan)->toContain("current_setting('app.tenant_id'")
            ->toContain('search_vector ~~')
            ->not->toContain("{$table}_search_vector_trgm");
    }
})->group('slow');
