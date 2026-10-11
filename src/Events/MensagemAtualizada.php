<?php

namespace M8zap\Client\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Disparado a cada callback do M8Zap.
 *
 * $evento: mensagem.enviada | mensagem.entregue | mensagem.lida | mensagem.falhou
 * $mensagem['origem'] traz entidade e id do registro que gerou a notificacao.
 */
class MensagemAtualizada
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $evento,
        public readonly array $mensagem
    ) {
    }

    public function entidade(): ?string
    {
        return $this->mensagem['origem']['entidade'] ?? null;
    }

    public function registroId(): ?string
    {
        return $this->mensagem['origem']['id'] ?? null;
    }

    public function falhou(): bool
    {
        return $this->evento === 'mensagem.falhou';
    }
}
