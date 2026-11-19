<?php

namespace Tests\Feature;

use App\Http\Controllers\BinanceController;
use App\Models\BotConfig;
use App\Models\BotState;
use App\Services\BotAdminService;
use App\Services\BotExecutor;
use App\Services\SpreadFixoService;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Estratégia "SPREAD FIXO" — grid de valor fixo com gatilho por valorização
 * (feature reversível: bot_config.estrategia_grid = 'atr' | 'fixo').
 *
 * Tabelas criadas à mão em sqlite :memory: (o schema completo do projeto tem
 * migrations MySQL-specific); Binance mockada por teste — nada de rede.
 */
class SpreadFixoTest extends TestCase
{
    private const USER = '1';
    private const PRECO = 340000.0;
    private const GATILHO_ABS = 1020.0; // 0,30% de 340000

    private MockInterface $binance;

    protected function setUp(): void
    {
        parent::setUp();

        // ── Schema mínimo ──────────────────────────────────────────────
        Schema::dropIfExists('bot_config');
        Schema::create('bot_config', function ($t) {
            $t->increments('id');
            $t->float('salto')->default(0);
            foreach (['nivel1','nivel2','nivel3','nivel4','nivel5','nivel6','nivel7'] as $n) $t->float($n)->default(0.5);
            $t->float('nivel_final')->default(0.08);
            $t->integer('allin_threshold')->default(15);
            $t->float('min_notional')->default(50);
            $t->float('piso_ordem_pct')->default(3);
            $t->float('boost_pct')->default(0);
            $t->float('target_btc_pct')->default(50);
            $t->float('target_brl_pct')->default(50);
            $t->float('delta_alerta_pct')->default(20);
            $t->float('delta_bloqueio_pct')->default(30);
            $t->float('piso_alta_delta_pct')->default(10);
            $t->float('piso_baixa_delta_pct')->default(10);
            $t->float('guard_meta_pct')->default(80);
            $t->boolean('modo_subida_auto_habilitado')->default(true);
            $t->float('spread_minimo_pct')->default(0.90);
            $t->float('taxa_total_pct')->default(0.15);
            $t->boolean('camadas_atr_habilitado')->default(true);
            $t->float('rsi_maximo_compra')->default(80);
            $t->float('rsi_minimo_venda')->default(20);
            $t->float('adapt_histerese_pct')->default(30);
            $t->integer('adapt_cooldown_min')->default(240);
            $t->integer('adapt_persistencia_min')->default(30);
            $t->float('patrimonio_inicial')->nullable();
            $t->float('btc_inicial')->nullable();
            $t->float('brl_inicial')->nullable();
            $t->timestamp('base_iniciada_em')->nullable();
            $t->string('estrategia_grid', 10)->default('atr');
            $t->float('fixo_valor_ordem', 12, 2)->default(1000);
            $t->float('fixo_gatilho_pct', 6, 3)->default(0.30);
            $t->integer('fixo_espera_min')->default(30);
            $t->timestamps();
        });

        Schema::dropIfExists('bot_state');
        Schema::create('bot_state', function ($t) {
            $t->increments('id');
            $t->string('id_user');
            $t->float('preco_referencia')->nullable();
            $t->string('direcao_atual')->nullable();
            $t->integer('contador_subidas')->default(0);
            $t->integer('contador_quedas')->default(0);
            $t->integer('contador_anterior')->default(0);
            $t->integer('salto')->default(0);
            $t->unsignedBigInteger('order_id_compra')->nullable();
            $t->unsignedBigInteger('order_id_venda')->nullable();
            $t->boolean('ativo')->default(1);
            $t->timestamp('pausado_ate')->nullable();
            $t->string('pausa_motivo')->nullable();
            $t->boolean('modo_subida')->default(false);
            $t->boolean('pausado_manual')->default(false);
            $t->boolean('modo_subida_auto')->default(false);
            $t->timestamp('par_criado_em')->nullable();
            $t->timestamp('fixo_espera_desde')->nullable();
            $t->timestamps();
        });

        // pix_payments — só o suficiente pro scope de retidos (tabela vazia).
        Schema::dropIfExists('pix_payments');
        Schema::create('pix_payments', function ($t) {
            $t->increments('id');
            $t->unsignedBigInteger('user_id')->default(0);
            $t->string('status')->default('pago');
            $t->boolean('registrado')->default(false);
            $t->timestamp('convertido_em')->nullable();
            $t->float('gatilho_preco')->nullable();
            $t->timestamp('transferido_em')->nullable();
            $t->float('valor')->default(0);
            $t->float('valor_liquido')->nullable();
            $t->timestamps();
        });

        // O executor consulta saques em andamento antes do dispatch —
        // tabela mínima (vazia = sem saque pendente).
        Schema::dropIfExists('bot_withdrawal_requests');
        Schema::create('bot_withdrawal_requests', function ($t) {
            $t->increments('id');
            $t->string('status')->default('pendente');
            $t->timestamps();
        });

        // O cache estático do BotConfig atravessa testes (mesmo processo
        // PHP) e apontaria pra registro de tabela já recriada — zera.
        (new \ReflectionClass(BotConfig::class))->setStaticPropertyValue('cache', null);

        // Linha do tempo das estratégias (eras ATR↔fixo).
        Schema::dropIfExists('bot_estrategia_historico');
        Schema::create('bot_estrategia_historico', function ($t) {
            $t->increments('id');
            $t->string('estrategia', 10);
            $t->float('gatilho_pct', 6, 3)->nullable();
            $t->timestamp('iniciado_em')->nullable();
            $t->timestamp('encerrado_em')->nullable();
            $t->double('preco_btc', 16, 2)->nullable();
            $t->double('patrimonio', 16, 2)->nullable();
            $t->timestamps();
        });

        // ── Binance mockada ────────────────────────────────────────────
        $this->binance = Mockery::mock(BinanceController::class);
        $this->binance->shouldReceive('getPrecoBTC')->andReturn(self::PRECO)->byDefault();
        $this->binance->shouldReceive('getSaldos')->andReturn(['balances' => [
            ['asset' => 'BRL', 'free' => '5000.00', 'locked' => '0'],
            ['asset' => 'BTC', 'free' => '0.01000000', 'locked' => '0'],
        ]])->byDefault();
        $this->binance->shouldReceive('getOpenOrders')->andReturn([])->byDefault();
        $this->binance->shouldReceive('buyLimit')->andReturn(['orderId' => 111])->byDefault();
        $this->binance->shouldReceive('sellLimit')->andReturn(['orderId' => 222])->byDefault();
        $this->binance->shouldReceive('cancelarOrdem')->andReturn([])->byDefault();
        $this->app->instance(BinanceController::class, $this->binance);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function config(string $estrategia = 'fixo'): BotConfig
    {
        $cfg = BotConfig::atual();
        $cfg->estrategia_grid = $estrategia;
        $cfg->save();
        return $cfg->refresh();
    }

    private function state(array $attrs = []): BotState
    {
        return BotState::create(array_merge([
            'id_user' => self::USER,
            'preco_referencia' => self::PRECO,
            'ativo' => 1,
        ], $attrs));
    }

    // ── Dispatch: estrategia=fixo desvia do fluxo ATR ──────────────────

    public function test_dispatch_para_estrategia_fixa_no_executor(): void
    {
        $this->config('fixo');
        // Sem state: o SpreadFixoService inicializa sozinho (não pode cair
        // no inicializarBotSemDivisao do ATR, que buscaria klines).
        $this->binance->shouldReceive('getKlines')->never();

        $res = app(BotExecutor::class)->executar(self::USER);

        $this->assertStringContainsString('Par fixo criado', $res);
        $state = BotState::where('id_user', self::USER)->first();
        $this->assertNotNull($state);
        $this->assertNotNull($state->order_id_compra);
        $this->assertNotNull($state->order_id_venda);
        $this->assertNull($state->fixo_espera_desde);
    }

    public function test_estrategia_atr_mantem_fluxo_original(): void
    {
        $this->config('atr');
        $this->state();

        // Prova da separação: o fluxo ATR analisa klines (o fixo NUNCA
        // chama). Com 0 ordens abertas ele cria par pelo caminho ATR.
        $this->binance->shouldReceive('getKlines')->once()->andReturn([]);

        $res = app(BotExecutor::class)->executar(self::USER);

        $this->assertIsString($res); // rodou o fluxo ATR até o fim
    }

    // ── Criação do par: preços ±gatilho, valor fixo ────────────────────

    public function test_cria_par_com_gatilho_e_valor_fixos(): void
    {
        $this->config('fixo');
        $this->state();

        $compras = [];
        $vendas = [];
        $this->binance->shouldReceive('buyLimit')
            ->andReturnUsing(function (float $preco, float $qty) use (&$compras) {
                $compras[] = [$preco, $qty];
                return ['orderId' => 111];
            });
        $this->binance->shouldReceive('sellLimit')
            ->andReturnUsing(function (float $preco, float $qty) use (&$vendas) {
                $vendas[] = [$preco, $qty];
                return ['orderId' => 222];
            });

        $res = app(SpreadFixoService::class)->executar(self::USER);

        $this->assertStringContainsString('Par fixo criado', $res);
        $this->assertCount(1, $compras);
        $this->assertCount(1, $vendas);

        // Pernas a ±0,30% do centro (340000): 338980 / 341020
        $this->assertEqualsWithDelta(self::PRECO - self::GATILHO_ABS, $compras[0][0], 0.01);
        $this->assertEqualsWithDelta(self::PRECO + self::GATILHO_ABS, $vendas[0][0], 0.01);

        // Valor fixo: qty × preço ≈ R$ 1000 em cada perna
        $this->assertEqualsWithDelta(1000.0, $compras[0][1] * $compras[0][0], 0.01);
        $this->assertEqualsWithDelta(1000.0, $vendas[0][1] * $vendas[0][0], 0.01);
    }

    public function test_par_completo_nao_faz_nada(): void
    {
        $this->config('fixo');
        $this->state();

        $this->binance->shouldReceive('getOpenOrders')->andReturn([
            ['side' => 'BUY', 'price' => (string) (self::PRECO - self::GATILHO_ABS), 'orderId' => 1],
            ['side' => 'SELL', 'price' => (string) (self::PRECO + self::GATILHO_ABS), 'orderId' => 2],
        ]);
        $this->binance->shouldReceive('buyLimit')->never();
        $this->binance->shouldReceive('sellLimit')->never();
        $this->binance->shouldReceive('cancelarOrdem')->never();

        $res = app(SpreadFixoService::class)->executar(self::USER);

        $this->assertSame('Par completo. Nada a fazer.', $res);
        $this->assertNull(BotState::where('id_user', self::USER)->first()->fixo_espera_desde);
    }

    // ── Perna executada: recria centrado no fill ────────────────────────

    public function test_perna_buy_executou_recria_no_fill(): void
    {
        $this->config('fixo');
        $this->state();

        // Par antigo centrado em 340000: BUY 339490 executou, sobrou a SELL
        // 340510 → fill = 340510 − 2×510 = 339490. Par novo centrado no fill.
        $sellPrice = self::PRECO + self::GATILHO_ABS;
        $cancelada = false;
        $this->binance->shouldReceive('cancelarOrdem')
            ->andReturnUsing(function () use (&$cancelada) {
                $cancelada = true;
                return [];
            });
        $this->binance->shouldReceive('getOpenOrders')
            ->andReturnUsing(function () use (&$cancelada, $sellPrice) {
                return $cancelada ? [] : [['side' => 'SELL', 'price' => (string) $sellPrice, 'orderId' => 2]];
            });

        $compras = [];
        $this->binance->shouldReceive('buyLimit')
            ->andReturnUsing(function (float $preco) use (&$compras) {
                $compras[] = $preco;
                return ['orderId' => 111];
            });

        $res = app(SpreadFixoService::class)->executar(self::USER);

        $fill = $sellPrice - 2 * self::GATILHO_ABS; // 339490
        $this->assertStringContainsString('Par fixo criado', $res);
        $this->assertEqualsWithDelta($fill - self::GATILHO_ABS, $compras[0], 0.01);
    }

    // ── Saldo insuficiente: segura o lado e liga a espera ──────────────

    public function test_sem_brl_pra_compra_liga_espera(): void
    {
        $this->config('fixo');
        $this->state();

        // BRL de R$ 30: abaixo do min_notional (50) → BUY não nasce; BTC
        // cobre a SELL de R$ 1000 → par incompleto com espera ligada.
        $this->binance->shouldReceive('getSaldos')->andReturn(['balances' => [
            ['asset' => 'BRL', 'free' => '30.00', 'locked' => '0'],
            ['asset' => 'BTC', 'free' => '0.01000000', 'locked' => '0'],
        ]]);
        $this->binance->shouldReceive('buyLimit')->never();

        $res = app(SpreadFixoService::class)->executar(self::USER);

        $this->assertStringContainsString('sem saldo pra compra', $res);
        $state = BotState::where('id_user', self::USER)->first();
        $this->assertNotNull($state->fixo_espera_desde);
        $this->assertNotNull($state->order_id_venda);
        $this->assertNull($state->order_id_compra);
    }

    public function test_espera_dentro_da_janela_segura_perna_viva(): void
    {
        $this->config('fixo');
        // Espera começou há 10 min (janela de 30): segura sem mexer em nada.
        $this->state(['fixo_espera_desde' => now()->subMinutes(10)]);

        // Perna viva SELL longe do preço (não estabilizou) — mas dentro da
        // janela nada deve ser cancelado nem criado (BTC zero = sem recompor).
        $this->binance->shouldReceive('getOpenOrders')->andReturn([
            ['side' => 'SELL', 'price' => (string) (self::PRECO + 2000), 'orderId' => 2],
        ]);
        $this->binance->shouldReceive('getSaldos')->andReturn(['balances' => [
            ['asset' => 'BRL', 'free' => '30.00', 'locked' => '0'],
            ['asset' => 'BTC', 'free' => '0.00000000', 'locked' => '0'],
        ]]);
        $this->binance->shouldReceive('buyLimit')->never();
        $this->binance->shouldReceive('sellLimit')->never();
        $this->binance->shouldReceive('cancelarOrdem')->never();

        $res = app(SpreadFixoService::class)->executar(self::USER);

        $this->assertStringContainsString('Segurando perna faltante', $res);
    }

    public function test_espera_estourada_faz_rebase_no_preco_atual(): void
    {
        $this->config('fixo');
        // Espera começou há 40 min (janela de 30): rebase.
        $this->state(['fixo_espera_desde' => now()->subMinutes(40)]);

        // Perna viva SELL presa lá em cima (preço caiu e não voltou).
        $cancelada = false;
        $this->binance->shouldReceive('cancelarOrdem')
            ->andReturnUsing(function () use (&$cancelada) {
                $cancelada = true;
                return [];
            });
        $this->binance->shouldReceive('getOpenOrders')
            ->andReturnUsing(function () use (&$cancelada) {
                // Depois do cancelamento, a lista vem vazia (confirmação).
                return $cancelada ? [] : [['side' => 'SELL', 'price' => '343000', 'orderId' => 2]];
            });

        $compras = [];
        $this->binance->shouldReceive('buyLimit')
            ->andReturnUsing(function (float $preco) use (&$compras) {
                $compras[] = $preco;
                return ['orderId' => 111];
            });

        $res = app(SpreadFixoService::class)->executar(self::USER);

        // Nova base = preço ATUAL (340000), não o centro da SELL presa.
        $this->assertStringContainsString('Rebase', $res);
        $this->assertTrue($cancelada);
        $this->assertEqualsWithDelta(self::PRECO - self::GATILHO_ABS, $compras[0], 0.01);
        // Espera renovada (não zerada): par novo completo → desarmada no criarPar.
        $this->assertNull(BotState::where('id_user', self::USER)->first()->fixo_espera_desde);
    }

    public function test_recompoe_perna_faltante_quando_saldo_volta(): void
    {
        $this->config('fixo');
        // BUY viva a −0,15% do centro; BTC voltou (fill parcial etc).
        $this->state(['fixo_espera_desde' => now()->subMinutes(5)]);
        $this->binance->shouldReceive('getOpenOrders')->andReturn([
            ['side' => 'BUY', 'price' => (string) (self::PRECO - self::GATILHO_ABS), 'orderId' => 1],
        ]);

        $vendas = [];
        $this->binance->shouldReceive('sellLimit')
            ->andReturnUsing(function (float $preco) use (&$vendas) {
                $vendas[] = $preco;
                return ['orderId' => 222];
            });

        $res = app(SpreadFixoService::class)->executar(self::USER);

        // SELL nasce ancorada na BUY viva: viva + 2× gatilho (centro + gatilho).
        $this->assertStringContainsString('recomposto', $res);
        $this->assertEqualsWithDelta(self::PRECO + self::GATILHO_ABS, $vendas[0], 0.01);
        $this->assertNull(BotState::where('id_user', self::USER)->first()->fixo_espera_desde);
    }

    // ── Config: clamps do gatilho e troca de estratégia ────────────────

    public function test_gatilho_nunca_desce_da_taxa_total(): void
    {
        $this->config('atr'); // taxa_total_pct = 0.15

        app(BotAdminService::class)->salvarConfig([
            'estrategia_grid' => 'fixo',
            'fixo_gatilho_pct' => 0.05, // abaixo da taxa total → clamp
        ]);

        $cfg = BotConfig::atual();
        $this->assertSame('fixo', $cfg->estrategia_grid);
        // Ciclo captura o gatilho: abaixo da taxa total (0,15%) é prejuízo
        // garantido por ciclo — 0,15% era break-even exato (lucro zero).
        $this->assertEqualsWithDelta(0.15, (float) $cfg->fixo_gatilho_pct, 0.0001);
    }

    public function test_troca_de_estrategia_no_salvar_config_cancela_ordens(): void
    {
        $this->config('atr');
        $this->state();

        $canceladas = 0;
        $this->binance->shouldReceive('getOpenOrders')->andReturnUsing(function () use (&$canceladas) {
            return $canceladas > 0 ? [] : [['side' => 'SELL', 'price' => '341000', 'orderId' => 9]];
        });
        $this->binance->shouldReceive('cancelarOrdem')->andReturnUsing(function () use (&$canceladas) {
            $canceladas++;
            return [];
        });

        $r = app(BotAdminService::class)->salvarConfig(['estrategia_grid' => 'fixo']);

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $canceladas);
        $this->assertSame('fixo', BotConfig::atual()->estrategia_grid);
    }

    // ── Botão do painel: alternarEstrategia ─────────────────────────────

    public function test_botao_ativa_spread_fixo_com_ritual_da_troca(): void
    {
        $this->config('atr');
        $this->state();

        $canceladas = 0;
        $this->binance->shouldReceive('getOpenOrders')->andReturnUsing(function () use (&$canceladas) {
            return $canceladas > 0 ? [] : [['side' => 'SELL', 'price' => '341000', 'orderId' => 9]];
        });
        $this->binance->shouldReceive('cancelarOrdem')->andReturnUsing(function () use (&$canceladas) {
            $canceladas++;
            return [];
        });

        $r = app(BotAdminService::class)->alternarEstrategia(true);

        $this->assertTrue($r['ok']);
        $this->assertSame('fixo', $r['estrategia_grid']);
        $this->assertSame(1, $r['canceladas']);
        $this->assertSame(1, $canceladas);
        $this->assertSame('fixo', BotConfig::atual()->estrategia_grid);
    }

    public function test_botao_reativa_atr_e_zera_espera_do_fixo(): void
    {
        $this->config('fixo');
        $this->state(['fixo_espera_desde' => now()->subMinutes(10)]);

        $r = app(BotAdminService::class)->alternarEstrategia(false);

        $this->assertTrue($r['ok']);
        $this->assertSame('atr', $r['estrategia_grid']);
        $this->assertSame('atr', BotConfig::atual()->estrategia_grid);
        $this->assertNull(BotState::where('id_user', self::USER)->first()->fixo_espera_desde);
    }

    public function test_botao_na_mesma_estrategia_e_noop(): void
    {
        $this->config('fixo');

        // Já está no fixo: nada acontece (nem cancelamento).
        $this->binance->shouldReceive('cancelarOrdem')->never();

        $r = app(BotAdminService::class)->alternarEstrategia(true);

        $this->assertTrue($r['ok']);
        $this->assertSame('fixo', $r['estrategia_grid']);
        $this->assertSame(0, $r['canceladas']);
        $this->assertStringContainsString('nada a fazer', $r['mensagem']);
    }

    public function test_precos_gerados_sao_multiplos_do_tick(): void
    {
        // Gatilho que gera centavos: 0,157% de 340000 = 533.8 → pernas
        // 339466.2 / 340533.8. A Binance rejeita centavos (tick=1 do
        // BTCBRL) — as ordens têm que sair inteiras (fallback offline do
        // helper = tick 1, o valor real do par).
        $this->config('fixo');
        $this->state();
        $cfg = BotConfig::atual();
        $cfg->fixo_gatilho_pct = 0.157;
        $cfg->save();

        $compras = [];
        $vendas = [];
        $this->binance->shouldReceive('buyLimit')
            ->andReturnUsing(function (float $preco) use (&$compras) {
                $compras[] = $preco;
                return ['orderId' => 111];
            });
        $this->binance->shouldReceive('sellLimit')
            ->andReturnUsing(function (float $preco) use (&$vendas) {
                $vendas[] = $preco;
                return ['orderId' => 222];
            });

        $res = app(SpreadFixoService::class)->executar(self::USER);

        $this->assertStringContainsString('Par fixo criado', $res);
        $this->assertSame(339466.0, $compras[0]); // 339466.2 → tick 1
        $this->assertSame(340534.0, $vendas[0]);  // 340533.8 → tick 1
    }

    public function test_mudar_gatilho_com_fixo_ativo_recria_par_na_hora(): void
    {
        $this->config('fixo');
        $this->state();

        $canceladas = 0;
        $this->binance->shouldReceive('getOpenOrders')->andReturnUsing(function () use (&$canceladas) {
            return $canceladas > 0 ? [] : [['side' => 'SELL', 'price' => '341000', 'orderId' => 9]];
        });
        $this->binance->shouldReceive('cancelarOrdem')->andReturnUsing(function () use (&$canceladas) {
            $canceladas++;
            return [];
        });

        $r = app(BotAdminService::class)->salvarConfig(['fixo_gatilho_pct' => 0.5]);

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $canceladas);
        $this->assertEqualsWithDelta(0.5, (float) BotConfig::atual()->fixo_gatilho_pct, 0.0001);
        $this->assertStringContainsString('novo gatilho', $r['mensagem']);
    }

    public function test_troca_registra_era_no_historico(): void
    {
        $this->config('atr');
        $this->state();

        // getPrecos usado pela foto da era (preço + patrimônio na borda).
        $this->binance->shouldReceive('getPrecos')->andReturn(['BTCBRL' => self::PRECO, 'BNBBRL' => 0]);

        app(BotAdminService::class)->alternarEstrategia(true);
        $era1 = \App\Models\BotEstrategiaHistorico::vigente();
        $this->assertNotNull($era1);
        $this->assertSame('fixo', $era1->estrategia);
        $this->assertNull($era1->encerrado_em);
        // Gatilho vigente na abertura (default 0,30 no schema do teste)
        $this->assertEqualsWithDelta(0.30, (float) $era1->gatilho_pct, 0.0001);
        // Foto da borda: 5000 BRL + 0.01 BTC × 340000 = 8400
        $this->assertEqualsWithDelta(8400.0, (float) $era1->patrimonio, 0.01);
        $this->assertEqualsWithDelta(self::PRECO, (float) $era1->preco_btc, 0.01);

        // Segunda troca: era do fixo encerra, era do ATR abre.
        app(BotAdminService::class)->alternarEstrategia(false);
        $era1 = $era1->refresh();
        $era2 = \App\Models\BotEstrategiaHistorico::vigente();
        $this->assertNotNull($era1->encerrado_em);
        $this->assertNotSame($era1->id, $era2->id);
        $this->assertSame('atr', $era2->estrategia);
        $this->assertNull($era2->gatilho_pct); // era ATR não tem gatilho
        $this->assertSame(2, \App\Models\BotEstrategiaHistorico::count());
    }
}
