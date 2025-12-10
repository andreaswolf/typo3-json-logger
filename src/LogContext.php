<?php

declare(strict_types=1);

namespace a9f\TYPO3JsonLogger;

use TYPO3\CMS\Core\SingletonInterface;

final class LogContext implements SingletonInterface
{
    /** @var string[] */
    private $information = [];

    /**
     * Adds information to the context. The given value must be something that can be converted to a string, i.e.
     * objects must implement __toString().
     */
    public function add(string $key, $value): void
    {
        $this->information[$key] = (string)$value;
    }

    public function getAll(): array
    {
        return $this->information;
    }

    public function remove(string $key): void
    {
        unset($this->information[$key]);
    }

    public function reset(): void
    {
        $this->information = [];
    }
}
