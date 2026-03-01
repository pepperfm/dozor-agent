<?php

declare(strict_types=1);

namespace Dozor;

use RuntimeException;

final class SocketStreamFactory
{
    /**
     * @return resource
     */
    public function __invoke(string $address, float $timeout)
    {
        $stream = @stream_socket_client($address, $errorCode, $errorMessage, $timeout);
        if ($stream === false) {
            throw new RuntimeException(sprintf('Unable to connect to Dozor agent [%s:%s] at %s', $errorCode, $errorMessage, $address));
        }

        return $stream;
    }
}
