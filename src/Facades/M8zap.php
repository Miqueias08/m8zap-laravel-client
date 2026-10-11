<?php

namespace M8zap\Client\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array enviarTemplate(string $telefone, string $modelo, array $parametros = [], array $opcoes = [])
 * @method static array enviarTexto(string $telefone, string $texto, array $opcoes = [])
 * @method static array consultar(int|string $mensagemId)
 * @method static array templates(?string $instancia = null, array $filtros = [])
 * @method static array criarTemplate(array $dados)
 * @method static array instancias(bool $somenteConectadas = false)
 * @method static array criarConvite(array $dados = [])
 * @method static array convite(int|string $id)
 * @method static \M8zap\Client\Mcp\Ferramenta ferramenta(string $nome)
 */
class M8zap extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \M8zap\Client\M8zap::class;
    }
}
