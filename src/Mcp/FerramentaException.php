<?php

namespace M8zap\Client\Mcp;

use RuntimeException;

/**
 * Lance dentro de uma ferramenta para devolver um erro que a IA deve ler e
 * contornar (ex.: "OS 123 nao encontrada para este cliente"). Vira um
 * resultado com isError=true — nao um erro de protocolo.
 */
class FerramentaException extends RuntimeException
{
}
