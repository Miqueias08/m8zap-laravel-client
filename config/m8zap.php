<?php

return [
    // URL base do M8Zap, sem barra final. Ex.: https://app.m8zap.com.br
    'url' => env('M8ZAP_URL', ''),

    // Token gerado no painel do M8Zap em Integracoes (m8z_...).
    'token' => env('M8ZAP_TOKEN', ''),

    // Identificador deste sistema; vai no campo origem.sistema de cada mensagem.
    'sistema' => env('M8ZAP_SISTEMA', 'oficina'),

    // Para onde o M8Zap devolve o status de entrega.
    'callback_url' => env('M8ZAP_CALLBACK_URL'),

    // Segredo exibido junto com o token; valida a assinatura dos callbacks.
    'callback_secret' => env('M8ZAP_CALLBACK_SECRET', ''),

    // Tolerancia do timestamp do callback, em segundos (anti replay).
    'callback_tolerancia' => (int) env('M8ZAP_CALLBACK_TOLERANCIA', 300),

    'timeout' => (int) env('M8ZAP_TIMEOUT', 15),

    // Fila usada pelo job de envio deste lado.
    'queue' => env('M8ZAP_QUEUE', 'default'),

    // Rota de callback registrada automaticamente. null desliga o registro.
    'rota_callback' => env('M8ZAP_ROTA_CALLBACK', 'webhooks/m8zap'),

    /*
    | Servidor MCP: ferramentas que os agentes de IA do M8Zap podem usar neste
    | sistema. Registre com M8zap::ferramenta(...) num service provider.
    */
    'mcp' => [
        // Endereco do servidor MCP (cadastre a URL completa no M8Zap). null desliga.
        'rota' => env('M8ZAP_MCP_ROTA', 'mcp/m8zap'),

        // Segredo exibido no M8Zap ao criar a conexao MCP: valida a assinatura e
        // libera os dados do contato (telefone) para as ferramentas.
        'segredo' => env('M8ZAP_MCP_SECRET', ''),

        // Opcional: token Bearer para outros clientes MCP (ex.: Claude Desktop). Sem contato.
        'token' => env('M8ZAP_MCP_TOKEN', ''),

        // Tolerancia do timestamp da assinatura, em segundos (anti replay).
        'tolerancia' => (int) env('M8ZAP_MCP_TOLERANCIA', 300),

        // Limite de chamadas por minuto, por IP ("maximo,minutos").
        'limite' => env('M8ZAP_MCP_LIMITE', '300,1'),

        // Origins de navegador aceitas (chamadas servidor-a-servidor nao enviam Origin).
        'origens' => array_filter(explode(',', (string) env('M8ZAP_MCP_ORIGENS', ''))),

        // Orientacao geral para a IA sobre este sistema (opcional).
        'instrucoes' => env('M8ZAP_MCP_INSTRUCOES'),
    ],
];
