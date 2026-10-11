<?php

namespace M8zap\Client\Mcp;

use Closure;
use InvalidArgumentException;

/**
 * Definicao fluente de uma ferramenta MCP.
 *
 *   M8zap::ferramenta('consultar_os')
 *       ->titulo('Consultar ordem de servico')
 *       ->descricao('Status e previsao da OS do cliente que esta conversando.')
 *       ->parametro('numero', 'string', 'Numero da OS', obrigatorio: false)
 *       ->somenteLeitura()
 *       ->executar(fn (array $args, ContextoM8zap $ctx) => ...);
 */
class Ferramenta
{
    private ?string $titulo = null;

    private string $descricao = '';

    /** @var array<string,array> */
    private array $parametros = [];

    /** @var list<string> */
    private array $obrigatorios = [];

    private bool $somenteLeitura = false;

    private bool $confirmar = false;

    private ?Closure $executor = null;

    public function __construct(public readonly string $nome)
    {
        if (! preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $nome)) {
            throw new InvalidArgumentException("Nome de ferramenta invalido: {$nome}");
        }
    }

    public function titulo(string $titulo): static
    {
        $this->titulo = $titulo;

        return $this;
    }

    /** Quando a IA deve usar esta ferramenta. E o texto que o modelo le para decidir. */
    public function descricao(string $descricao): static
    {
        $this->descricao = $descricao;

        return $this;
    }

    /**
     * @param  string  $tipo  string|integer|number|boolean|array|object
     * @param  array  $extra  JSON Schema adicional (enum, format, items, minimum...)
     */
    public function parametro(string $nome, string $tipo, string $descricao = '', bool $obrigatorio = true, array $extra = []): static
    {
        $this->parametros[$nome] = array_merge(array_filter([
            'type' => $tipo,
            'description' => $descricao ?: null,
        ]), $extra);

        if ($obrigatorio) {
            $this->obrigatorios[] = $nome;
        }

        return $this;
    }

    /** So consulta dados; nunca pede confirmacao ao cliente. */
    public function somenteLeitura(): static
    {
        $this->somenteLeitura = true;
        $this->confirmar = false;

        return $this;
    }

    /** Altera dados de forma relevante (agendar, cancelar, cobrar): o M8Zap pede o "sim" do cliente antes. */
    public function confirmarAntes(): static
    {
        $this->confirmar = true;
        $this->somenteLeitura = false;

        return $this;
    }

    /** @param  callable(array, ContextoM8zap): mixed  $executor */
    public function executar(callable $executor): static
    {
        $this->executor = Closure::fromCallable($executor);

        return $this;
    }

    /** Definicao no formato do tools/list. */
    public function paraMcp(): array
    {
        return array_filter([
            'name' => $this->nome,
            'title' => $this->titulo,
            'description' => $this->descricao,
            'inputSchema' => array_filter([
                'type' => 'object',
                'properties' => (object) $this->parametros,
                'required' => $this->obrigatorios ?: null,
            ], fn ($v) => $v !== null),
            'annotations' => [
                'readOnlyHint' => $this->somenteLeitura,
                'destructiveHint' => $this->confirmar,
            ],
        ], fn ($v) => $v !== null);
    }

    /**
     * Valida os argumentos e roda a ferramenta.
     *
     * @return array CallToolResult (content, structuredContent?, isError)
     */
    public function rodar(array $argumentos, ContextoM8zap $ctx): array
    {
        if (! $this->executor) {
            return self::erro("A ferramenta {$this->nome} nao tem implementacao.");
        }

        foreach ($this->obrigatorios as $nome) {
            if (! array_key_exists($nome, $argumentos) || $argumentos[$nome] === null || $argumentos[$nome] === '') {
                return self::erro("Parametro obrigatorio ausente: {$nome}.");
            }
        }

        foreach ($argumentos as $nome => $valor) {
            $tipo = $this->parametros[$nome]['type'] ?? null;

            if ($tipo && ! self::tipoConfere($tipo, $valor)) {
                return self::erro("Parametro {$nome} deveria ser do tipo {$tipo}.");
            }
        }

        try {
            $retorno = ($this->executor)($argumentos, $ctx);
        } catch (FerramentaException $e) {
            return self::erro($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return self::erro('Erro interno ao executar '.$this->nome.'. Tente novamente mais tarde.');
        }

        return self::resultado($retorno);
    }

    private static function tipoConfere(string $tipo, mixed $valor): bool
    {
        return match ($tipo) {
            'string' => is_string($valor),
            'integer' => is_int($valor) || (is_string($valor) && ctype_digit(ltrim($valor, '-'))),
            'number' => is_int($valor) || is_float($valor) || is_numeric($valor),
            'boolean' => is_bool($valor),
            'array' => is_array($valor) && array_is_list($valor),
            'object' => is_array($valor) || is_object($valor),
            default => true,
        };
    }

    private static function resultado(mixed $retorno): array
    {
        if ($retorno instanceof \Illuminate\Contracts\Support\Arrayable) {
            $retorno = $retorno->toArray();
        } elseif ($retorno instanceof \JsonSerializable) {
            $retorno = $retorno->jsonSerialize();
        }

        if (is_array($retorno) || is_object($retorno)) {
            return [
                'content' => [['type' => 'text', 'text' => json_encode($retorno, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
                'structuredContent' => $retorno,
                'isError' => false,
            ];
        }

        return [
            'content' => [['type' => 'text', 'text' => $retorno === null ? 'OK' : (string) $retorno]],
            'isError' => false,
        ];
    }

    private static function erro(string $mensagem): array
    {
        return ['content' => [['type' => 'text', 'text' => $mensagem]], 'isError' => true];
    }
}
