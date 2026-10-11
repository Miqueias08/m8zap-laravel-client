<?php

namespace M8zap\Client\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use M8zap\Client\Mcp\ContextoM8zap;
use M8zap\Client\Mcp\RegistroFerramentas;

/**
 * Servidor MCP (Streamable HTTP, sem estado) — "dual-era":
 *  - moderno 2026-07-28: versao/capacidades em params._meta, headers espelhados validados;
 *  - legado 2025-03-26..2025-11-25: initialize sem sessao (servidor stateless).
 *
 * Responde sempre application/json (a spec permite JSON ou SSE).
 */
class McpController
{
    public const VERSAO_MODERNA = '2026-07-28';

    public const VERSOES_LEGADAS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    private const META_VERSAO = 'io.modelcontextprotocol/protocolVersion';

    /** Validade sugerida ao cliente para resultados cacheaveis. */
    private const CACHE_MS = ['server/discover' => 3600000, 'tools/list' => 300000];

    public function __invoke(Request $request, RegistroFerramentas $registro): Response|JsonResponse
    {
        if (! $request->isMethod('post')) {
            return response('', 405, ['Allow' => 'POST']);
        }

        $msg = json_decode($request->getContent(), true);

        if (! is_array($msg)) {
            return $this->erro(null, -32700, 'JSON invalido.', 400);
        }

        if (($msg['jsonrpc'] ?? null) !== '2.0' || ! is_string($msg['method'] ?? null)) {
            return $this->erro($msg['id'] ?? null, -32600, 'Requisicao JSON-RPC invalida.', 400);
        }

        // Notificacao (sem id): nada a responder.
        if (! array_key_exists('id', $msg)) {
            return response('', 202);
        }

        $id = $msg['id'];
        $metodo = $msg['method'];
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];

        if ($metodo === 'initialize') {
            return $this->inicializarLegado($id, $params);
        }

        $moderno = isset($meta[self::META_VERSAO]);

        if ($moderno && ($falha = $this->validarModerno($request, $id, $metodo, $params, $meta))) {
            return $falha;
        }

        $resultado = match ($metodo) {
            'server/discover' => [
                'supportedVersions' => array_merge([self::VERSAO_MODERNA], self::VERSOES_LEGADAS),
                'capabilities' => $this->capacidades(),
                'instructions' => config('m8zap.mcp.instrucoes'),
            ],
            'ping' => [],
            'tools/list' => ['tools' => array_map(fn ($f) => $f->paraMcp(), $registro->todas())],
            'tools/call' => $this->chamar($request, $registro, $params, $meta),
            default => null,
        };

        if ($resultado === null) {
            return $this->erro($id, -32601, "Metodo nao suportado: {$metodo}", $moderno ? 404 : 200);
        }

        if (isset($resultado['__erro'])) {
            // Erro de protocolo dentro de uma requisicao valida: JSON-RPC normal, HTTP 200.
            return $this->erro($id, $resultado['__erro'][0], $resultado['__erro'][1], 200);
        }

        if ($moderno) {
            $resultado = array_merge(['resultType' => 'complete'], $resultado, [
                '_meta' => ['io.modelcontextprotocol/serverInfo' => $this->infoServidor()],
            ]);

            // Dicas de cache obrigatorias para discover e listas (2026-07-28).
            // A lista e a mesma para qualquer chamador, entao "public".
            if (isset(self::CACHE_MS[$metodo])) {
                $resultado['ttlMs'] = self::CACHE_MS[$metodo];
                $resultado['cacheScope'] = 'public';
            }
        }

        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) $resultado], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function chamar(Request $request, RegistroFerramentas $registro, array $params, array $meta): array
    {
        $nome = (string) ($params['name'] ?? '');
        $ferramenta = $registro->buscar($nome);

        if (! $ferramenta) {
            return ['__erro' => [-32602, "Ferramenta desconhecida: {$nome}"]];
        }

        $argumentos = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        return $ferramenta->rodar($argumentos, ContextoM8zap::daRequisicao($request, $meta));
    }

    private function inicializarLegado(mixed $id, array $params): JsonResponse
    {
        $pedida = (string) ($params['protocolVersion'] ?? '');
        $versao = in_array($pedida, self::VERSOES_LEGADAS, true) ? $pedida : self::VERSOES_LEGADAS[0];

        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => array_filter([
            'protocolVersion' => $versao,
            'capabilities' => $this->capacidades(),
            'serverInfo' => $this->infoServidor(),
            'instructions' => config('m8zap.mcp.instrucoes'),
        ])]);
    }

    /** Headers espelhados precisam bater com o corpo (spec: Server Validation). */
    private function validarModerno(Request $request, mixed $id, string $metodo, array $params, array $meta): ?JsonResponse
    {
        $versao = (string) $meta[self::META_VERSAO];

        if ($versao !== self::VERSAO_MODERNA) {
            return $this->erro($id, -32022, 'Unsupported protocol version', 400, [
                'supported' => array_merge([self::VERSAO_MODERNA], self::VERSOES_LEGADAS),
                'requested' => $versao,
            ]);
        }

        if ($request->header('MCP-Protocol-Version') !== $versao) {
            return $this->erro($id, -32020, 'Header mismatch: MCP-Protocol-Version', 400);
        }

        if ($request->header('Mcp-Method') !== $metodo) {
            return $this->erro($id, -32020, 'Header mismatch: Mcp-Method', 400);
        }

        if ($metodo === 'tools/call' && self::decodificar((string) $request->header('Mcp-Name')) !== (string) ($params['name'] ?? '')) {
            return $this->erro($id, -32020, 'Header mismatch: Mcp-Name', 400);
        }

        if (! array_key_exists('io.modelcontextprotocol/clientCapabilities', $meta)) {
            return $this->erro($id, -32602, 'Missing _meta clientCapabilities', 400);
        }

        return null;
    }

    private static function decodificar(string $valor): string
    {
        if (preg_match('/^=\?base64\?(.*)\?=$/', $valor, $m)) {
            return (string) base64_decode($m[1], true);
        }

        return $valor;
    }

    private function capacidades(): array
    {
        return ['tools' => ['listChanged' => false]];
    }

    private function infoServidor(): array
    {
        return ['name' => (string) config('m8zap.sistema', 'saas'), 'version' => '1.0.0'];
    }

    private function erro(mixed $id, int $codigo, string $mensagem, int $status, mixed $dados = null): JsonResponse
    {
        $erro = ['code' => $codigo, 'message' => $mensagem];

        if ($dados !== null) {
            $erro['data'] = $dados;
        }

        return response()->json(array_filter(['jsonrpc' => '2.0', 'id' => $id, 'error' => $erro], fn ($v) => $v !== null), $status);
    }
}
