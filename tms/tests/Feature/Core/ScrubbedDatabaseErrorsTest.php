<?php

use App\Modules\Core\Tenancy\ForEachTenant;
use App\Modules\Crm\People\Person;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Everything logged while running $fn, as one JSON string (message + context).
 */
function loggedDuring(callable $fn): string
{
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $context = array_map(fn ($v) => $v instanceof Throwable ? ['class' => $v::class, 'message' => $v->getMessage(), 'trace' => $v->getTraceAsString()] : $v, $e->context);
        $logged[] = ['level' => $e->level, 'message' => $e->message, 'context' => $context];
    });
    $fn();

    return json_encode($logged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
}

function duplicatePersonError(): QueryException
{
    tenant();
    $person = makePerson('Secret', ['email' => 'jeanne.secret@exemple.test']);
    try {
        DB::transaction(fn () => DB::table('people')->insert([
            'id' => $person->id, 'tenant_id' => tenant()->id, 'last_name' => 'Secret', 'email' => 'jeanne.secret@exemple.test',
            'created_at' => now(), 'updated_at' => now(),
        ]));
    } catch (QueryException $e) {
        return $e;
    }

    throw new RuntimeException('expected a unique violation');
}

it('logs a database error without its bound values', function () {
    $e = duplicatePersonError();
    expect($e->getMessage())->toContain('jeanne.secret@exemple.test');

    $logged = loggedDuring(fn () => report($e));

    expect($logged)->not->toContain('jeanne.secret')
        ->and($logged)->not->toContain(Person::query()->value('id'))
        ->and($logged)->toContain('23505')
        ->and($logged)->toContain('insert into \"people\"')
        ->and($logged)->toContain("UniqueConstraintViolationException");
});

it('scrubs a database error wrapped in another exception', function () {
    $e = new RuntimeException('Import impossible', 0, duplicatePersonError());

    $logged = loggedDuring(fn () => report($e));

    expect($logged)->not->toContain('jeanne.secret')->and($logged)->toContain('23505')->and($logged)->toContain(RuntimeException::class);
});

it('scrubs a database error raised for one organisme of a scheduled task', function () {
    $e = duplicatePersonError();

    $logged = loggedDuring(fn () => ForEachTenant::run(fn () => throw $e));

    expect($logged)->not->toContain('jeanne.secret')->and($logged)->toContain('23505');
});

it('still logs other exceptions in full', function () {
    $logged = loggedDuring(fn () => report(new RuntimeException('Panne imprévue')));

    expect($logged)->toContain('Panne imprévue');
});
