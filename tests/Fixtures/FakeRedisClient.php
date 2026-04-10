<?php

declare(strict_types=1);

namespace MichalKanak\MessageFlowVisualizerBundle\Tests\Fixtures;

use LogicException;
use Predis\ClientInterface;
use Predis\Command\CommandInterface;

/**
 * Minimal in-memory Predis client for testing.
 *
 * Predis\ClientInterface methods are dispatched via __call(),
 * so they cannot be mocked with PHPUnit's createMock().
 */
class FakeRedisClient implements ClientInterface
{
    /** @var array<string, string> */
    private array $data = [];

    public function get(string $key): ?string
    {
        return $this->data[$key] ?? null;
    }

    public function setex(string $key, int $ttl, string $value): void
    {
        $this->data[$key] = $value;
    }

    public function zadd(string $key, array $membersAndScores): int
    {
        return 1;
    }

    public function hset(string $key, string $field, string $value): int
    {
        return 1;
    }

    public function getProfile()
    {
        throw new LogicException('Not implemented');
    }

    public function getOptions()
    {
        throw new LogicException('Not implemented');
    }

    public function connect()
    {
    }

    public function disconnect()
    {
    }

    public function getConnection()
    {
        throw new LogicException('Not implemented');
    }

    public function createCommand($method, $arguments = [])
    {
        throw new LogicException('Not implemented');
    }

    public function executeCommand(CommandInterface $command)
    {
        throw new LogicException('Not implemented');
    }

    public function __call($commandID, $arguments)
    {
        throw new LogicException("Unexpected call: $commandID");
    }

    public function getCommandFactory()
    {
        throw new LogicException('Not implemented');
    }
}
