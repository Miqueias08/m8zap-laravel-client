<?php

namespace M8zap\Client\Mcp;

use Illuminate\Http\Request;

/**
 * Quem esta pedindo: o cliente do WhatsApp e a empresa no M8Zap.
 *
 * Os dados do contato so sao preenchidos quando a chamada veio assinada pelo
 * M8Zap (HMAC com M8ZAP_MCP_SECRET). Chamadas autenticadas so por token
 * (ex.: Claude Desktop) nao trazem contato — nunca confie em telefone que nao
 * foi assinado.
 */
final class ContextoM8zap
{
    public function __construct(
        public readonly bool $assinado,
        public readonly ?string $telefone,
        public readonly ?string $nome,
        public readonly ?int $conversaId,
        public readonly ?int $empresaId,
        public readonly ?string $empresaNome,
        public readonly bool $simulacao,
        public readonly bool $confirmadoPeloCliente,
        public readonly Request $request,
    ) {}

    public static function daRequisicao(Request $request, array $meta): self
    {
        $assinado = (bool) $request->attributes->get('m8zap_assinado', false);
        $contato = $assinado ? (array) ($meta['br.com.m8zap/contato'] ?? []) : [];
        $empresa = $assinado ? (array) ($meta['br.com.m8zap/empresa'] ?? []) : [];

        return new self(
            assinado: $assinado,
            telefone: isset($contato['telefone']) ? preg_replace('/\D/', '', (string) $contato['telefone']) : null,
            nome: $contato['nome'] ?? null,
            conversaId: isset($contato['conversa_id']) ? (int) $contato['conversa_id'] : null,
            empresaId: isset($empresa['id']) ? (int) $empresa['id'] : null,
            empresaNome: $empresa['nome'] ?? null,
            simulacao: (bool) ($contato['simulacao'] ?? false),
            confirmadoPeloCliente: (bool) ($contato['confirmado_pelo_cliente'] ?? false),
            request: $request,
        );
    }

    /**
     * Telefone do cliente, ou erro amigavel para a IA quando nao veio.
     * Use em toda ferramenta que mostra dados de um cliente.
     */
    public function telefoneObrigatorio(): string
    {
        if (! $this->telefone) {
            throw new FerramentaException('Nao foi possivel identificar o cliente desta conversa.');
        }

        return $this->telefone;
    }
}
