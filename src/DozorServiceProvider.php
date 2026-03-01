<?php

declare(strict_types=1);

namespace Dozor;

use Illuminate\Support\ServiceProvider;
use Dozor\Console\AgentCommand;
use Dozor\Console\StatusCommand;
use Dozor\Contracts\DozorContract as CoreContract;
use Dozor\Contracts\IngestContract as IngestContract;
use Dozor\Http\Middleware\TraceRequest;
use Dozor\Watchers\QueueWatcher;
use Dozor\Watchers\QueryWatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Throwable;

class DozorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/dozor.php', 'dozor');

        $this->app->singleton(IngestContract::class, static function (Application $app): IngestContract {
            /** @var array<string, mixed> $config */
            $config = $app->make(Repository::class)->get('dozor', []);
            $tokenHash = substr(hash('xxh128', (string) Arr::get($config, 'token', '')), 0, 7);

            return new Ingest(
                transmitTo: (string) Arr::get($config, 'ingest.uri', '127.0.0.1:4815'),
                connectionTimeout: (float) Arr::get($config, 'ingest.connection_timeout', 0.5),
                timeout: (float) Arr::get($config, 'ingest.timeout', 0.5),
                streamFactory: new SocketStreamFactory()(...),
                buffer: new RecordsBuffer((int) Arr::get($config, 'ingest.event_buffer', 500)),
                tokenHash: $tokenHash,
            );
        });

        $this->app->singleton(CoreContract::class, function (Application $app): CoreContract {
            try {
                /** @var array<string, mixed> $config */
                $config = $app->make(Repository::class)->get('dozor', []);

                return new Dozor(
                    ingest: $app->make(IngestContract::class),
                    config: $config,
                );
            } catch (Throwable $e) {
                logger()->error('dozor.package.core_boot_failed', [
                    'class' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                throw $e;
            }
        });

        $this->app->alias(CoreContract::class, Dozor::class);
        $this->app->alias(IngestContract::class, Ingest::class);
        $this->app->alias(CoreContract::class, 'dozor');
        $this->app->singleton(
            QueryWatcher::class,
            static fn(Application $app) => new QueryWatcher($app->make(CoreContract::class))
        );
        $this->app->singleton(
            QueueWatcher::class,
            static fn(Application $app) => new QueueWatcher($app->make(CoreContract::class))
        );
        $this->app->singleton(
            TraceRequest::class,
            static fn(Application $app) => new TraceRequest($app->make(CoreContract::class))
        );
        $this->app->singleton(AgentCommand::class, function (Application $app) {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('dozor', []);
            $token = Arr::get($config, 'token');
            $server = Arr::get($config, 'server');
            $ingestUri = Arr::get($config, 'ingest.uri');
            $storePath = Arr::get($config, 'agent.store_path', storage_path('app/dozor'));

            return new AgentCommand(
                token: is_string($token) ? $token : null,
                server: is_string($server) ? $server : null,
                ingestUri: is_string($ingestUri) ? $ingestUri : null,
                storePath: is_string($storePath) ? $storePath : null,
            );
        });
    }

    public function boot(): void
    {
        $sourceConfigPath = __DIR__ . '/../config/dozor.php';
        $targetConfigPath = $this->app->configPath('dozor.php');

        $this->publishes([
            $sourceConfigPath => $targetConfigPath,
        ], ['dozor', 'dozor-config']);

        if ($this->app->runningInConsole()) {
            $this->commands([
                AgentCommand::class,
                StatusCommand::class,
            ]);
        }

        try {
            /** @var CoreContract $core */
            $core = $this->app->make(CoreContract::class);
        } catch (Throwable $e) {
            logger()->error('dozor.package.discovery_failed', [
                'class' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }

        if (!$core->enabled()) {
            return;
        }

        Event::listen(QueryExecuted::class, $this->app->make(QueryWatcher::class));
        Event::listen(JobProcessing::class, [$this->app->make(QueueWatcher::class), 'started']);
        Event::listen(JobProcessed::class, [$this->app->make(QueueWatcher::class), 'finished']);
        Event::listen(JobFailed::class, [$this->app->make(QueueWatcher::class), 'finished']);

        $this->callAfterResolving(Router::class, function (Router $router): void {
            $router->aliasMiddleware('dozor.trace', TraceRequest::class);

            if (!config('dozor.http.attach_middleware', true)) {
                return;
            }

            foreach ((array) config('dozor.http.groups', ['web', 'api']) as $group) {
                try {
                    $router->pushMiddlewareToGroup($group, TraceRequest::class);
                } catch (Throwable $e) {
                    logger()->debug('dozor.package.middleware_attach_skipped', [
                        'group' => $group,
                        'class' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        });
    }
}
