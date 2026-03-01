<?php

declare(strict_types=1);

namespace Dozor\Console;

use Illuminate\Console\Command;
use Dozor\Agent\Server;
use SensitiveParameter;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'dozor:agent', description: 'Run the Dozor local ingest agent.')]
final class AgentCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'dozor:agent
        {--listen-on= : TCP address the local ingest server should listen on}
        {--store-path= : Directory where NDJSON ingest files will be stored}
        {--auth-connection-timeout=0.5}
        {--auth-timeout=0.5}
        {--ingest-connection-timeout=0.5}
        {--ingest-timeout=0.5}
        {--server=}
        {--silent : Do not output startup details}';

    /**
     * @var string
     */
    protected $description = 'Run the Dozor local ingest agent.';

    public function __construct(
        #[SensitiveParameter]
        private readonly ?string $token,
        private readonly ?string $server,
        private readonly ?string $ingestUri,
        private readonly ?string $storePath,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $listenOn = $this->option('listen-on') ?: ($this->ingestUri ?: '127.0.0.1:4815');
        $serverName = $this->option('server') ?: ($this->server ?: gethostname() ?: 'unknown');
        $storePath = $this->option('store-path') ?: ($this->storePath ?: storage_path('app/dozor'));
        $tokenHash = substr(hash('xxh128', (string) $this->token), 0, 7);
        $silent = (bool) $this->option('silent');

        $server = new Server(
            listenOn: $listenOn,
            tokenHash: $tokenHash,
            storePath: $storePath,
            output: $silent ? null : $this->output,
            serverName: $serverName,
        );

        $server->run();

        return self::SUCCESS;
    }
}
