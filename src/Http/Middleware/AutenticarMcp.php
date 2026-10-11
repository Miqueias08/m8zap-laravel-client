<?php

namespace M8zap\Client\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege o endpoint MCP.
 *
 *  1. Origin presente e fora da lista -> 403 (protecao contra DNS rebinding, exigida pela spec).
 *  2. X-M8zap-Signature valida (HMAC com M8ZAP_MCP_SECRET) -> chamada do M8Zap,
 *     com dados do contato confiaveis.
 *  3. Senao, Authorization: Bearer M8ZAP_MCP_TOKEN -> outros clientes MCP
 *     (Claude Desktop, testes), sem dados de contato.
 */
class AutenticarMcp
{
    public function handle(Request $request, Closure $next): Response
    {
        $origem = $request->headers->get('Origin');
        $permitidas = (array) config('m8zap.mcp.origens', []);

        if ($origem !== null && ! in_array($origem, $permitidas, true)) {
            return response()->json(['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Origin nao permitida.']], 403);
        }

        $segredo = (string) config('m8zap.mcp.segredo');
        $token = (string) config('m8zap.mcp.token');

        if ($segredo === '' && $token === '') {
            return response()->json(['jsonrpc' => '2.0', 'error' => ['code' => -32603, 'message' => 'M8ZAP_MCP_SECRET nao configurado.']], 500);
        }

        if ($segredo !== '' && $this->assinaturaValida($request, $segredo)) {
            $request->attributes->set('m8zap_assinado', true);

            return $next($request);
        }

        if ($token !== '' && hash_equals($token, (string) $request->bearerToken())) {
            $request->attributes->set('m8zap_assinado', false);

            return $next($request);
        }

        return response()->json(['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Nao autorizado.']], 401);
    }

    private function assinaturaValida(Request $request, string $segredo): bool
    {
        parse_str(str_replace(',', '&', (string) $request->header('X-M8zap-Signature')), $partes);

        $t = (int) ($partes['t'] ?? 0);
        $v1 = (string) ($partes['v1'] ?? '');

        if ($t <= 0 || $v1 === '' || abs(time() - $t) > (int) config('m8zap.mcp.tolerancia', 300)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $t.'.'.$request->getContent(), $segredo), $v1);
    }
}
