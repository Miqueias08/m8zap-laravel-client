<?php

namespace M8zap\Client\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valida o header X-M8zap-Signature: t=<epoch>,v1=<hmac sha256>.
 *
 * O HMAC e calculado sobre "<t>.<corpo bruto>" com o segredo da empresa.
 * A janela de tolerancia evita replay de um callback capturado.
 */
class VerificarAssinaturaM8zap
{
    public function handle(Request $request, Closure $next): Response
    {
        $segredo = (string) config('m8zap.callback_secret');

        if ($segredo === '') {
            return response()->json(['message' => 'M8ZAP_CALLBACK_SECRET nao configurado.'], 500);
        }

        $header = (string) $request->header('X-M8zap-Signature');

        parse_str(str_replace(',', '&', $header), $partes);

        $timestamp = (int) ($partes['t'] ?? 0);
        $assinatura = (string) ($partes['v1'] ?? '');

        if ($timestamp <= 0 || $assinatura === '') {
            return response()->json(['message' => 'Assinatura ausente.'], 401);
        }

        $tolerancia = (int) config('m8zap.callback_tolerancia', 300);

        if (abs(time() - $timestamp) > $tolerancia) {
            return response()->json(['message' => 'Assinatura expirada.'], 401);
        }

        $esperada = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $segredo);

        if (! hash_equals($esperada, $assinatura)) {
            return response()->json(['message' => 'Assinatura invalida.'], 401);
        }

        return $next($request);
    }
}
