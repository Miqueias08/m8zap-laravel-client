<?php

namespace M8zap\Client;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use M8zap\Client\Http\Controllers\CallbackController;
use M8zap\Client\Http\Controllers\McpController;
use M8zap\Client\Http\Middleware\AutenticarMcp;
use M8zap\Client\Http\Middleware\VerificarAssinaturaM8zap;
use M8zap\Client\Mcp\RegistroFerramentas;

class M8zapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/m8zap.php', 'm8zap');

        $this->app->singleton(M8zap::class, fn ($app) => new M8zap($app['config']->get('m8zap')));

        // Ferramentas que a IA do M8Zap pode usar neste sistema (servidor MCP).
        $this->app->singleton(RegistroFerramentas::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/m8zap.php' => config_path('m8zap.php'),
        ], 'm8zap-config');

        // Servidor MCP: o M8Zap (e outros clientes MCP) chamam as ferramentas daqui.
        // Sem o grupo "web": nada de sessao nem CSRF numa chamada entre servidores.
        if ($rotaMcp = config('m8zap.mcp.rota')) {
            Route::middleware([AutenticarMcp::class])
                ->match(['get', 'post', 'delete'], $rotaMcp, McpController::class)
                ->name('m8zap.mcp');
        }

        $rota = config('m8zap.rota_callback');

        if (! $rota) {
            return;
        }

        // Rota de callback pronta: o sistema so escuta o evento MensagemAtualizada.
        Route::middleware(['api', VerificarAssinaturaM8zap::class])
            ->post($rota, CallbackController::class)
            ->name('m8zap.callback');
    }
}
