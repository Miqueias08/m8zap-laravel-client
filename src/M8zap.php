<?php

namespace M8zap\Client;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use M8zap\Client\Exceptions\M8zapException;

/**
 * Cliente da API v1 do M8Zap.
 *
 * Nao use direto no fluxo HTTP da sua aplicacao: despache o job
 * M8zap\Client\Jobs\EnviarWhatsapp para nao prender o request do usuario.
 */
class M8zap
{
    public function __construct(private readonly array $config)
    {
    }

    /**
     * Registra uma ferramenta que os agentes de IA do M8Zap podem usar (MCP).
     *
     *   M8zap::ferramenta('consultar_os')->descricao('...')->somenteLeitura()
     *       ->executar(fn (array $args, ContextoM8zap $ctx) => ...);
     */
    public function ferramenta(string $nome): Mcp\Ferramenta
    {
        return app(Mcp\RegistroFerramentas::class)->registrar($nome);
    }

    /**
     * Envia um modelo aprovado. E o caminho normal de notificacao, porque a
     * janela de 24h do cliente quase sempre esta fechada.
     *
     * @param  list<string>  $parametros  valores de {{1}}, {{2}}...
     * @param  array{instancia?:string,entidade?:string,id?:string|int,idempotency_key?:string,header?:mixed,botoes?:array,callback_url?:string}  $opcoes
     */
    public function enviarTemplate(string $telefone, string $modelo, array $parametros = [], array $opcoes = []): array
    {
        return $this->post([
            'para' => $telefone,
            'tipo' => 'template',
            'template' => array_filter([
                'nome' => $modelo,
                'idioma' => $opcoes['idioma'] ?? 'pt_BR',
                'parametros' => array_values($parametros),
                'header' => $opcoes['header'] ?? null,
                'botoes' => $opcoes['botoes'] ?? null,
            ], fn ($v) => $v !== null),
        ], $opcoes);
    }

    /**
     * Texto livre. So funciona com a janela de 24h aberta (o cliente falou com
     * voce nas ultimas 24h); fora dela o M8Zap responde codigo janela_fechada.
     */
    public function enviarTexto(string $telefone, string $texto, array $opcoes = []): array
    {
        return $this->post([
            'para' => $telefone,
            'tipo' => 'texto',
            'texto' => $texto,
        ], $opcoes);
    }

    /** Consulta o estado atual de uma mensagem enviada. */
    public function consultar(int|string $mensagemId): array
    {
        $resposta = $this->http()->get($this->url("/api/v1/mensagens/{$mensagemId}"));

        if (! $resposta->successful()) {
            throw new M8zapException(
                $resposta->json('message') ?? 'Falha ao consultar a mensagem.',
                $resposta->json('codigo'),
                $resposta->status(),
                (array) $resposta->json()
            );
        }

        return (array) $resposta->json('data');
    }

    /**
     * Modelos aprovados. Informe a instancia para ver so os daquele numero.
     *
     * @param  array{nome?:string,aprovados?:bool}  $filtros
     */
    public function templates(?string $instancia = null, array $filtros = []): array
    {
        $resposta = $this->http()->get($this->url('/api/v1/templates'), array_filter([
            'instancia' => $instancia,
            'nome' => $filtros['nome'] ?? null,
            'aprovados' => array_key_exists('aprovados', $filtros)
                ? ($filtros['aprovados'] ? 'true' : 'false')
                : null,
        ], fn ($v) => $v !== null));

        return $resposta->successful() ? (array) $resposta->json('data') : [];
    }

    /**
     * Cadastra um modelo na conta da Meta da instancia.
     *
     * Volta com status PENDING: a Meta leva de minutos a dias para aprovar.
     *
     * @param  array{name:string,category:string,body:string,language?:string,header_text?:string,footer_text?:string,buttons?:list<string>,instancia?:string}  $dados
     */
    public function criarTemplate(array $dados): array
    {
        $resposta = $this->http()->post($this->url('/api/v1/templates'), $dados);

        // 409 = ja existe naquela instancia; devolvemos o modelo atual em vez de estourar.
        if ($resposta->status() === 409) {
            return (array) $resposta->json('data', []);
        }

        if (! $resposta->successful()) {
            throw new M8zapException(
                $resposta->json('message') ?? 'Falha ao cadastrar o modelo.',
                $resposta->json('codigo'),
                $resposta->status(),
                (array) $resposta->json()
            );
        }

        return (array) $resposta->json('data');
    }

    /** Instancias (numeros) da conta. */
    public function instancias(bool $somenteConectadas = false): array
    {
        $resposta = $this->http()->get($this->url('/api/v1/instancias'), array_filter([
            'conectadas' => $somenteConectadas ? 'true' : null,
        ]));

        return $resposta->successful() ? (array) $resposta->json('data') : [];
    }

    /**
     * Convite para o cliente conectar o WhatsApp dele (inclusive coexistencia).
     *
     * Devolve o link; leve o usuario ate ele em aba normal — o popup da Meta
     * nao abre dentro de iframe.
     *
     * @param  array{instancia?:string,modo?:string,redirect_url?:string,callback_url?:string,expira_em_minutos?:int,origem?:array}  $dados
     */
    public function criarConvite(array $dados = []): array
    {
        $resposta = $this->http()->post($this->url('/api/v1/onboarding/whatsapp'), $dados);

        if (! $resposta->successful()) {
            throw new M8zapException(
                $resposta->json('message') ?? 'Falha ao gerar o convite.',
                $resposta->json('codigo'),
                $resposta->status(),
                (array) $resposta->json()
            );
        }

        return (array) $resposta->json('data');
    }

    /** Estado de um convite ja gerado. */
    public function convite(int|string $id): array
    {
        $resposta = $this->http()->get($this->url("/api/v1/onboarding/whatsapp/{$id}"));

        return $resposta->successful() ? (array) $resposta->json('data') : [];
    }

    private function post(array $payload, array $opcoes): array
    {
        $entidade = $opcoes['entidade'] ?? null;
        $registroId = $opcoes['id'] ?? null;

        $payload['origem'] = array_filter([
            'sistema' => $this->config['sistema'] ?? null,
            'entidade' => $entidade,
            'id' => $registroId !== null ? (string) $registroId : null,
        ], fn ($v) => $v !== null && $v !== '');

        // Instancia = numero de saida (ex.: gestaooficina_01). Sem ela, o M8Zap
        // usa o numero principal da conta.
        if (! empty($opcoes['instancia'])) {
            $payload['instancia'] = $opcoes['instancia'];
        } elseif (! empty($opcoes['conta_id'])) {
            $payload['conta_id'] = (int) $opcoes['conta_id'];
        }

        $callback = $opcoes['callback_url'] ?? ($this->config['callback_url'] ?? null);

        if ($callback) {
            $payload['callback_url'] = $callback;
        }

        // Sem chave estavel, um retry vira mensagem duplicada no celular do cliente.
        $chave = $opcoes['idempotency_key'] ?? $this->chavePadrao($payload, $entidade, $registroId);

        $resposta = $this->http()
            ->withHeaders(['Idempotency-Key' => $chave])
            ->post($this->url('/api/v1/mensagens'), $payload);

        if (! $resposta->successful()) {
            throw new M8zapException(
                $resposta->json('message') ?? 'Falha ao enviar a mensagem.',
                $resposta->json('codigo'),
                $resposta->status(),
                (array) $resposta->json()
            );
        }

        return (array) $resposta->json('data');
    }

    /** oficina:ordem_servico:1234:os_pronta — determinista e legivel no log. */
    private function chavePadrao(array $payload, ?string $entidade, mixed $registroId): string
    {
        $partes = array_filter([
            $this->config['sistema'] ?? 'sistema',
            // Sem a instancia, dois clientes com o mesmo numero de OS colidiriam.
            $payload['instancia'] ?? null,
            $entidade,
            $registroId !== null ? (string) $registroId : null,
            $payload['template']['nome'] ?? 'texto',
        ]);

        $chave = implode(':', $partes);

        // Sem entidade/id nao ha como ser determinista: cai no hash do conteudo.
        if ($entidade === null || $registroId === null) {
            $chave .= ':'.substr(hash('sha256', json_encode($payload)), 0, 16);
        }

        return substr($chave, 0, 191);
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->config['token'] ?? '')
            ->acceptJson()
            ->timeout((int) ($this->config['timeout'] ?? 15))
            ->connectTimeout(5);
    }

    private function url(string $caminho): string
    {
        return rtrim((string) ($this->config['url'] ?? ''), '/').$caminho;
    }
}
