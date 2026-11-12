<?php

namespace App\Modules\Connectors;

use App\Modules\Connectors\Core\ConnectorRegistry;
use App\Modules\Connectors\Smtp\SmtpConnector;
use App\Modules\Connectors\Smtp\SmtpTransportFactory;
use Illuminate\Support\ServiceProvider;

class ConnectorsServiceProvider extends ServiceProvider
{
    /**
     * Connectors shipped with the application. A module adds its own by
     * calling ConnectorRegistry::register() from its service provider.
     *
     * @var list<class-string<Core\Connector>>
     */
    private const CONNECTORS = [
        SmtpConnector::class,
    ];

    public function register(): void
    {
        $this->app->singleton(SmtpTransportFactory::class);
        $this->app->singleton(ConnectorRegistry::class, function ($app) {
            $registry = new ConnectorRegistry($app);
            foreach (self::CONNECTORS as $class) {
                $registry->register($class);
            }

            return $registry;
        });
    }
}
