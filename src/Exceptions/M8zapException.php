<?php

namespace M8zap\Client\Exceptions;

use RuntimeException;

class M8zapException extends RuntimeException
{
    public function __construct(
        string $mensagem,
        public readonly ?string $codigo = null,
        public readonly int $status = 0,
        public readonly array $corpo = []
    ) {
        parent::__construct($mensagem);
    }

    /** Erro que vale nova tentativa (rede, 429, 5xx). */
    public function transitorio(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }
}
