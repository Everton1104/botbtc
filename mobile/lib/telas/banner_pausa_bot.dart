// ─────────────────────────────────────────────────────────────────────────────
// BANNER DO BOT PAUSADO — compartilhado pelas abas Saques e Depósitos.
//
// Dois motivos de pausa, duas falas:
//  • 'saque'     → confirmação de saque: retoma sozinho em 3 min (countdown);
//  • 'deposito'  → depósito manual: fica pausado ATÉ o admin transferir o
//                  valor pra Binance e liberar (sem countdown).
// O botão Retomar é o mesmo endpoint (/api/saque/retomar) nos dois casos.
// ─────────────────────────────────────────────────────────────────────────────

import 'package:flutter/material.dart';

import '../modelos/saque.dart';
import '../tema.dart';

class BannerPausaBot extends StatelessWidget {
  final StatusPausa pausa;
  final VoidCallback onRetomar; // ritual _acao da aba que hospeda o banner
  final bool ocupado; // ação em curso (desliga o botão Retomar)

  const BannerPausaBot({
    super.key,
    required this.pausa,
    required this.onRetomar,
    this.ocupado = false,
  });

  @override
  Widget build(BuildContext context) {
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
