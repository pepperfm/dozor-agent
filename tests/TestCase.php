<?php

declare(strict_types=1);

namespace Dozor\Tests;

use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Psr\Log\NullLogger;

use function array_map;
use function explode;
use function fclose;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function stream_socket_server;
use function stream_socket_get_name;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;

abstract class TestCase extends PHPUnitTestCase
{
    /**
     * @var list<string>
     */
    private array $temporaryDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapContainer();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }

        Facade::clearResolvedInstances();

        parent::tearDown();
    }

    protected function makeTemporaryDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . '-' . uniqid('', true);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            self::fail("Unable to create temporary directory [$directory]");
        }

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    protected function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if ($socket === false) {
            self::fail("Unable to allocate free port [$errorCode:$errorMessage]");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if (!is_string($name) || !str_contains($name, ':')) {
            self::fail('Unable to resolve allocated socket port');
        }

        $segments = array_map('trim', explode(':', $name));
        $port = (int) end($segments);

        self::assertGreaterThan(0, $port);

        return $port;
    }

    private function bootstrapContainer(): void
    {
        $container = new Container();
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        $container->instance('http', new Factory());
        $container->instance('log', new NullLogger());
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
