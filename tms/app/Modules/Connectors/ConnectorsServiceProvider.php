<?php

namespace App\Modules\Connectors;

use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorRegistry;
use App\Modules\Connectors\Sirene\SireneClient;
use App\Modules\Connectors\Sirene\SireneConnector;
use App\Modules\Connectors\Smtp\SmtpConnector;
use App\Modules\Connectors\Smtp\SmtpTransportFactory;
use App\Modules\Core\Audit\MorphAliases;
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
        SireneConnector::class,
    ];

    public function register(): void
    {
        MorphAliases::register(['connector_config' => ConnectorConfig::class]);

        $this->app->singleton(SmtpTransportFactory::class);
        $this->app->singleton(SireneClient::class);
        $this->app->singleton(ConnectorRegistry::class, function ($app) {
            $registry = new ConnectorRegistry($app);
            foreach (self::CONNECTORS as $class) {
                $registry->register($class);
            }

            return $registry;
        });
    }
}
