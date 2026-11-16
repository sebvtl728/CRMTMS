<?php

namespace App\Modules\Connectors;

use App\Modules\Connectors\Ai\Ai;
use App\Modules\Connectors\Ai\AiConnector;
use App\Modules\Connectors\Ai\AnthropicClient;
use App\Modules\Connectors\Ai\OpenAiCompatibleClient;
use App\Modules\Connectors\Calendar\GraphClient;
use App\Modules\Connectors\Calendar\Microsoft365Connector;
use App\Modules\Connectors\Calendar\QueueOutlookPush;
use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorRegistry;
use App\Modules\Connectors\Mail365\GraphMailClient;
use App\Modules\Connectors\Mail365\Microsoft365MailConnector;
use App\Modules\Connectors\Sirene\SireneClient;
use App\Modules\Connectors\Sirene\SireneConnector;
use App\Modules\Connectors\Smtp\SmtpConnector;
use App\Modules\Connectors\Smtp\SmtpTransportFactory;
use App\Modules\Core\Audit\MorphAliases;
use App\Modules\Crm\Activities\Events\ActivityChanged;
use Illuminate\Support\Facades\Event;
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
        Microsoft365Connector::class,
        Microsoft365MailConnector::class,
        AiConnector::class,
    ];

    public function register(): void
    {
        MorphAliases::register(['connector_config' => ConnectorConfig::class]);

        $this->app->singleton(SmtpTransportFactory::class);
        $this->app->singleton(SireneClient::class);
        $this->app->singleton(GraphClient::class);
        $this->app->singleton(GraphMailClient::class);
        $this->app->singleton(AnthropicClient::class);
        $this->app->singleton(OpenAiCompatibleClient::class);
        $this->app->singleton(Ai::class);
        $this->app->singleton(ConnectorRegistry::class, function ($app) {
            $registry = new ConnectorRegistry($app);
            foreach (self::CONNECTORS as $class) {
                $registry->register($class);
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        // The CRM announces its actions; Outlook copies follow (Microsoft 365).
        Event::listen(ActivityChanged::class, QueueOutlookPush::class);
    }
}
