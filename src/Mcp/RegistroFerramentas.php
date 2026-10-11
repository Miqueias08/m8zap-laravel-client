<?php

namespace M8zap\Client\Mcp;

/** Ferramentas MCP que este sistema expoe ao M8Zap. */
class RegistroFerramentas
{
    /** @var array<string,Ferramenta> */
    private array $ferramentas = [];

    public function registrar(string $nome): Ferramenta
    {
        return $this->ferramentas[$nome] = new Ferramenta($nome);
    }

    public function buscar(string $nome): ?Ferramenta
    {
        return $this->ferramentas[$nome] ?? null;
    }

    /** @return list<Ferramenta> em ordem deterministica (a spec recomenda) */
    public function todas(): array
    {
        $lista = $this->ferramentas;
        ksort($lista);

        return array_values($lista);
    }
}
