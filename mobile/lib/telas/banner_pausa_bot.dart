// ─────────────────────────────────────────────────────────────────────────────
// BANNER DO BOT PAUSADO — compartilhado pelas abas Saques e Depósitos.
//
// Três caras, três situações:
//  • saque EM ANDAMENTO → o PIX foi liberado mas ainda não saiu (ex.: limite
//    noturno da Binance). O bot fica SEGURO (pausa renovada a cada ciclo)
//    até o admin concluir ou cancelar — botões próprios pra isso;
//  • 'saque'     → confirmação de saque: retoma sozinho em 3 min (countdown);
//  • 'deposito'  → depósito manual: fica pausado ATÉ o admin transferir o
//                  valor pra Binance e liberar (sem countdown).
// O botão Retomar é o mesmo endpoint (/api/saque/retomar) nos dois últimos.
// ─────────────────────────────────────────────────────────────────────────────

import 'package:flutter/material.dart';

import '../modelos/saque.dart';
import '../tema.dart';
import '../util/formatar.dart';

class BannerPausaBot extends StatelessWidget {
  final StatusPausa pausa;
  final VoidCallback onRetomar; // ritual _acao da aba que hospeda o banner
  final bool ocupado; // ação em curso (desliga os botões)
  // Saque em andamento: concluir (PIX saiu) e cancelar (devolve cotas).
  final VoidCallback? onConcluirSaque;
  final VoidCallback? onCancelarSaque;

  const BannerPausaBot({
    super.key,
    required this.pausa,
    required this.onRetomar,
    this.ocupado = false,
    this.onConcluirSaque,
    this.onCancelarSaque,
  });

  @override
  Widget build(BuildContext context) {
    final saque = pausa.saqueEmAndamento;

    // ── Saque em andamento: o caso que pode durar a madrugada inteira ──
    if (saque != null) {
      return Card(
        margin: const EdgeInsets.only(bottom: 4),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const Icon(Icons.local_shipping_outlined,
                      color: Cores.dourado),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('Saque #${saque.id} em andamento — bot seguro',
                            style: const TextStyle(fontWeight: FontWeight.w700)),
                        Text(
                          'PIX de ${moeda(saque.valorLiquido)} para ${saque.nome}. '
                          'O bot só volta quando você concluir ou cancelar.',
                          style: const TextStyle(
                              fontSize: 11.5, color: Cores.textoSuave),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              Row(
                children: [
                  Expanded(
                    child: FilledButton.icon(
                      onPressed: ocupado || onConcluirSaque == null
                          ? null
                          : onConcluirSaque,
                      icon: const Icon(Icons.task_alt_outlined, size: 18),
                      label: const Text('PIX enviado — Concluir'),
                    ),
                  ),
                  const SizedBox(width: 8),
                  TextButton(
                    onPressed: ocupado || onCancelarSaque == null
                        ? null
                        : onCancelarSaque,
                    style:
                        TextButton.styleFrom(foregroundColor: Cores.vermelho),
                    child: const Text('Cancelar'),
                  ),
                ],
              ),
            ],
          ),
        ),
      );
    }

    // ── Pausa comum (saque aguardando countdown ou depósito manual) ──
    return Card(
      margin: const EdgeInsets.only(bottom: 4),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            const Icon(Icons.pause_circle_outline, color: Cores.dourado),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    pausa.ehDeposito
                        ? 'Bot pausado para o depósito'
                        : 'Bot pausado para a transferência',
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                  Text(
                    pausa.ehDeposito
                        ? 'Libere depois que o valor cair na Binance.'
                        : 'Retoma sozinho em ${pausa.segundos}s — ou libere já se o PIX acabou.',
                    style: const TextStyle(
                        fontSize: 11.5, color: Cores.textoSuave),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            // Botão menor (TextButton) porque é uma ação secundária do banner.
            TextButton(
              onPressed: ocupado ? null : onRetomar,
              child: const Text('Retomar'),
            ),
          ],
        ),
      ),
    );
  }
}
