<?php

namespace M8zap\Client\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use M8zap\Client\Events\MensagemAtualizada;

/**
 * Recebe o callback ja validado pelo middleware de assinatura e transforma em
 * evento da aplicacao. Responda sempre 2xx rapido: o M8Zap reenvia em 5xx.
 */
class CallbackController
{
    public function __invoke(Request $request): JsonResponse
    {
        $evento = (string) ($request->input('evento') ?? $request->header('X-M8zap-Evento', 'mensagem.atualizada'));

        MensagemAtualizada::dispatch($evento, (array) $request->input('mensagem', []));

        return response()->json(['ok' => true]);
    }
}
