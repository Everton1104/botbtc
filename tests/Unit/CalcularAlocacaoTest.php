<?php

namespace Tests\Unit;

use App\Http\Controllers\BinanceController;
use App\Models\BotConfig;
use App\Services\BotExecutor;
use PHPUnit\Framework\TestCase;

/**
 * M1/M2/M3 — zonas de alocação RELATIVAS ao alvo editável.
 *
 * Sem banco: o config é montado em memória (forceFill) e o BinanceController
 * entra como mock — calcularAlocacao não toca a exchange.
 */
class CalcularAlocacaoTest extends TestCase
{
    private function config(array $attrs = []): BotConfig
    {
        return (new BotConfig)->forceFill(array_merge([
            'target_btc_pct' => 50.0,
            'target_brl_pct' => 50.0,
            'delta_alerta_pct' => 20.0,
            'delta_bloqueio_pct' => 30.0,
            'piso_alta_delta_pct' => 10.0,
            'piso_baixa_delta_pct' => 10.0,
            'guard_meta_pct' => 80.0,
        ], $attrs));
    }

    private function executor(): BotExecutor
    {
        return new BotExecutor($this->createMock(BinanceController::class));
    }

    /** Saldos que produzem o pctBTC pedido num patrimônio de R$1000 (preço 100). */
    private function aloc(float $pctBTC, array $attrs = [], ?array $tendencia = null): array
    {
        $t = $tendencia ?? $this->tendenciaNeutra();

        return $this->executor()->calcularAlocacao(
            (100 - $pctBTC) * 10, // BRL não multiplica pelo preço
            $pctBTC / 100 * 10,   // BTC: valor = qty × 100
            100.0,
            $this->config($attrs),
            $t
        );
    }

    private function tendenciaNeutra(): array
    {
        return ['ema9' => 100, 'ma21' => 100, 'rsi_4h' => 50, 'preco' => 100,
            'ma21_4h' => 100, 'macd' => 0, 'macd_signal' => 0, 'trend_4h' => 0];
    }

    private function tendenciaAltaForte(): array
    {
        return ['ema9' => 110, 'ma21' => 100, 'rsi_4h' => 65, 'preco' => 115,
            'ma21_4h' => 105, 'macd' => 10, 'macd_signal' => 5, 'trend_4h' => 1];
    }

    private function tendenciaBaixaForte(): array
    {
        return ['ema9' => 90, 'ma21' => 100, 'rsi_4h' => 35, 'preco' => 85,
            'ma21_4h' => 95, 'macd' => -10, 'macd_signal' => -5, 'trend_4h' => -1];
    }

    // ── Paridade: alvo 50 + deltas 20/30 = limites absolutos antigos (70/80) ──

    public function test_alvo_50_reproduz_zonas_de_70_e_80(): void
    {
        $this->assertSame('equilibrado', $this->aloc(50.0)['zona']);
        $this->assertSame('alerta_btc_alto', $this->aloc(71.0)['zona']);
        $this->assertSame('bloqueio_btc_alto', $this->aloc(81.0)['zona']);
        $this->assertTrue($this->aloc(81.0)['bloquear_compra_normal']);

        $this->assertSame('alerta_brl_alto', $this->aloc(29.0)['zona']);
        $this->assertSame('bloqueio_brl_alto', $this->aloc(19.0)['zona']);
        $this->assertTrue($this->aloc(19.0)['bloquear_venda_normal']);
    }

    // ── Alvo editável: as zonas viajam com o alvo ─────────────────────────────

    public function test_alvo_70_nao_dispara_alerta_no_proprio_alvo(): void
    {
        $cfg = ['target_btc_pct' => 70.0];

        // 75% BTC está DENTRO da zona neutra (50–90) — antes o bot já
        // reduziria compras aqui (alerta absoluto em 70).
        $a = $this->aloc(75.0, $cfg);
        $this->assertSame('equilibrado', $a['zona']);
        $this->assertSame(1.0, $a['fator_compra']);

        // Fora da banda: 91 > 70+20 → alerta; bloqueio ficaria em 100 (clamp,
        // zona desarmada — alvo agressivo tolera BTC alto).
        $this->assertSame('alerta_btc_alto', $this->aloc(91.0, $cfg)['zona']);
        $this->assertSame('alerta_btc_alto', $this->aloc(96.0, $cfg)['zona']);
        $this->assertFalse($this->aloc(96.0, $cfg)['bloquear_compra_normal']);

        // Lado BRL: neutro até 40, alerta até 50, bloqueio abaixo de 40.
        $this->assertSame('alerta_brl_alto', $this->aloc(45.0, $cfg)['zona']);
        $this->assertSame('bloqueio_brl_alto', $this->aloc(39.0, $cfg)['zona']);
    }

    public function test_brl_e_sempre_derivado_do_alvo_btc(): void
    {
        $a = $this->aloc(50.0, ['target_btc_pct' => 70.0]);
        $this->assertSame(70.0, $a['target_btc']);
        $this->assertSame(30.0, $a['target_brl']);
        $this->assertSame([50.0, 90.0], $a['faixa_equilibrio']);
    }

    public function test_alvo_fora_do_range_e_saneado(): void
    {
        // 0/ausente → default 50; fora de [5,95] → clamp nas bordas.
        $this->assertSame(50.0, $this->aloc(50.0, ['target_btc_pct' => 0.0])['target_btc']);
        $this->assertSame(5.0, $this->aloc(50.0, ['target_btc_pct' => 3.0])['target_btc']);
        $this->assertSame(95.0, $this->aloc(50.0, ['target_btc_pct' => 100.0])['target_btc']);
    }

    // ── Pisos de tendência forte escalam com o alvo ───────────────────────────

    public function test_piso_da_alta_forte_escala_com_o_alvo(): void
    {
        $t = $this->tendenciaAltaForte();

        // Alvo 50, piso 40 (comportamento antigo): 55% BTC não é bloqueado.
        $a = $this->aloc(55.0, [], $t);
        $this->assertFalse($a['bloquear_venda_total']);

        // Alvo 70, piso 60 (70−10): os mesmos 55% agora seguram a venda.
        $b = $this->aloc(55.0, ['target_btc_pct' => 70.0], $t);
        $this->assertTrue($b['bloquear_venda_total']);
        $this->assertSame(0.0, $b['fator_venda']);

        // All-in de venda num alvo 95 realiza até ~85, não até 5.
        $c = $this->aloc(90.0, ['target_btc_pct' => 95.0], $t);
        $this->assertFalse($c['bloquear_venda_total']); // 90 > piso 85
        $d = $this->aloc(84.0, ['target_btc_pct' => 95.0], $t);
        $this->assertTrue($d['bloquear_venda_total']);   // 84 ≤ piso 85
    }

    public function test_piso_da_baixa_forte_nao_impede_convergencia_a_95(): void
    {
        $t = $this->tendenciaBaixaForte();

        // Alvo 95/5: piso BRL efetivo = max(5, 5−10) = 5 → com 7% BRL o bot
        // continua comprando (antes, piso absoluto de 40% travaria em 93/7
        // para sempre — o bot nunca chegaria ao alvo agressivo).
        $a = $this->aloc(93.0, ['target_btc_pct' => 95.0], $t);
        $this->assertFalse($a['bloquear_compra_total']);

        // Alvo 50 (piso 40) mantém a proteção antiga de segurar caixa.
        $b = $this->aloc(93.0, [], $t);
        $this->assertTrue($b['bloquear_compra_total']);
        $this->assertSame(0.0, $b['fator_compra']);
    }

    public function test_piso_efetivo_nunca_desce_de_5(): void
    {
        // Delta exagerado de 30 num alvo 5 → piso teórico −25 → fica em 5.
        $t = $this->tendenciaAltaForte();
        $a = $this->aloc(4.0, ['target_btc_pct' => 5.0, 'piso_alta_delta_pct' => 30.0], $t);
        $this->assertTrue($a['bloquear_venda_total']); // 4 ≤ piso 5
    }
}
