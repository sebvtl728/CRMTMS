<?php

namespace App\Modules\Connectors\Core;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The connectors known to the application (registered by
 * ConnectorsServiceProvider; later modules register theirs the same way).
 */
class ConnectorRegistry
{
    /** @var array<string, class-string<Connector>> */
    private array $connectors = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<Connector>  $class
     */
    public function register(string $class): void
    {
        $this->connectors[$class::key()] = $class;
    }

    /**
     * @return array<string, Connector> key => connector
     */
    public function all(): array
    {
        return array_map(fn (string $class) => $this->container->make($class), $this->connectors);
    }

    public function has(string $key): bool
    {
        return isset($this->connectors[$key]);
    }

    public function make(string $key): Connector
    {
        return isset($this->connectors[$key])
            ? $this->container->make($this->connectors[$key])
            : throw new InvalidArgumentException("Connecteur inconnu : {$key}.");
    }
}
