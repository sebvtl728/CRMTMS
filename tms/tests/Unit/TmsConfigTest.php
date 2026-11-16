<?php

/**
 * config/tms.php read with the given environment (compose passes unset
 * optional variables as empty strings).
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function tmsConfigWith(array $env): array
{
    $saved = [];
    foreach ($env as $key => $value) {
        $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
        $_SERVER[$key] = $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    try {
        return require config_path('tms.php');
    } finally {
        foreach ($saved as $key => [$server, $envValue, $put]) {
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            if ($envValue === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $envValue;
            }
            putenv($put === false ? $key : "{$key}={$put}");
        }
    }
}

it('treats an empty TMS_ALLOW_PRIVATE_SMTP_HOSTS as unset (mode default)', function () {
    expect(tmsConfigWith(['TMS_MODE' => 'single', 'TMS_ALLOW_PRIVATE_SMTP_HOSTS' => ''])['connectors']['allow_private_hosts'])->toBeTrue()
        ->and(tmsConfigWith(['TMS_MODE' => 'multi', 'TMS_ALLOW_PRIVATE_SMTP_HOSTS' => ''])['connectors']['allow_private_hosts'])->toBeFalse();
});

it('honours an explicit TMS_ALLOW_PRIVATE_SMTP_HOSTS', function () {
    expect(tmsConfigWith(['TMS_MODE' => 'single', 'TMS_ALLOW_PRIVATE_SMTP_HOSTS' => 'false'])['connectors']['allow_private_hosts'])->toBeFalse()
        ->and(tmsConfigWith(['TMS_MODE' => 'multi', 'TMS_ALLOW_PRIVATE_SMTP_HOSTS' => 'true'])['connectors']['allow_private_hosts'])->toBeTrue();
});

it('offers a local AI server on a self-hosted install only, unless TMS_ALLOW_LOCAL_AI says otherwise', function () {
    expect(tmsConfigWith(['TMS_MODE' => 'single', 'TMS_ALLOW_LOCAL_AI' => ''])['connectors']['allow_local_ai'])->toBeTrue()
        ->and(tmsConfigWith(['TMS_MODE' => 'multi', 'TMS_ALLOW_LOCAL_AI' => ''])['connectors']['allow_local_ai'])->toBeFalse()
        ->and(tmsConfigWith(['TMS_MODE' => 'single', 'TMS_ALLOW_LOCAL_AI' => 'false'])['connectors']['allow_local_ai'])->toBeFalse()
        ->and(tmsConfigWith(['TMS_MODE' => 'multi', 'TMS_ALLOW_LOCAL_AI' => 'true'])['connectors']['allow_local_ai'])->toBeTrue();
});

it('treats empty booleans and mode as their defaults', function () {
    $config = tmsConfigWith(['TMS_MODE' => '', 'TMS_VITE_HOT' => '', 'TMS_ALLOW_INTERNAL_SMTP_HOSTS' => '']);

    expect($config['mode'])->toBe('single')
        ->and($config['vite_hot'])->toBeTrue()
        ->and($config['connectors']['allow_internal_hosts'])->toBeFalse();
});
