<?php

namespace M8zap\Client\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use M8zap\Client\Exceptions\M8zapException;
use M8zap\Client\M8zap;

/**
 * Job que o seu sistema despacha ao concluir um evento de negocio.
 *
 *   EnviarWhatsapp::template($os->cliente->celular, 'os_pronta',
 *       [$os->cliente->nome, $os->numero, $os->totalFormatado()],
 *       ['entidade' => 'ordem_servico', 'id' => $os->id]
 *   );
 *
 * A chave de idempotencia sai de entidade+id: reprocessar a fila nao gera
 * segunda mensagem para o cliente.
 */
class EnviarWhatsapp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(
        public readonly string $telefone,
        public readonly ?string $modelo,
        public readonly array $parametros = [],
        public readonly array $opcoes = [],
        public readonly ?string $texto = null
    ) {
        $this->onQueue(config('m8zap.queue', 'default'));
    }

    public static function template(string $telefone, string $modelo, array $parametros = [], array $opcoes = []): self
    {
        return new self($telefone, $modelo, $parametros, $opcoes);
    }

    public static function texto(string $telefone, string $texto, array $opcoes = []): self
    {
        return new self($telefone, null, [], $opcoes, $texto);
    }

    public function backoff(): array
    {
        return [10, 30, 120, 600, 1800];
    }

    public function handle(M8zap $m8zap): void
    {
        try {
            $this->modelo
                ? $m8zap->enviarTemplate($this->telefone, $this->modelo, $this->parametros, $this->opcoes)
                : $m8zap->enviarTexto($this->telefone, (string) $this->texto, $this->opcoes);
        } catch (M8zapException $e) {
            Log::warning('M8Zap: falha ao enviar', [
                'telefone' => $this->telefone,
                'modelo' => $this->modelo,
                'codigo' => $e->codigo,
                'status' => $e->status,
                'erro' => $e->getMessage(),
            ]);

            // Erro de contrato (modelo errado, telefone invalido) nao melhora
            // com nova tentativa: registra e encerra.
            if (! $e->transitorio()) {
                $this->fail($e);

                return;
            }

            $this->release($this->backoff()[$this->attempts() - 1] ?? 1800);
        }
    }
}
