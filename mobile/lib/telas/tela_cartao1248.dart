// ─────────────────────────────────────────────────────────────────────────────
// TELA "CARTÃO 1248" — o backup físico da seed da cold wallet, no app.
//
// Espelha a página /1248 do site, mas com o desenho do cartão em PNG
// (assets/card-1248.png, o mesmo arquivo que o usuário mantém na storage):
//   • a imagem do cartão (número na lateral, grade 1·2·4·8 ao lado)
//   • as instruções de uso (mesma redação da página)
//   • botão pra baixar o PDF com os 24 cartões (abre no navegador)
//   • atalho pro simulador interativo, que continua no site
// ─────────────────────────────────────────────────────────────────────────────

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../servicos/api.dart';
import '../tema.dart';

class TelaCartao1248 extends StatelessWidget {
  const TelaCartao1248({super.key});

  Future<void> _abrir(String url) async {
    final uri = Uri.parse(url);
    final abriu = await launchUrl(uri, mode: LaunchMode.externalApplication);
    if (!abriu) {
      throw 'Não foi possível abrir $url';
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Cartão 1248')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // ── O cartão (PNG) ─────────────────────────────────────────────
            Container(
              decoration: BoxDecoration(
                color: Cores.cartao,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: Cores.borda),
              ),
              padding: const EdgeInsets.all(16),
              child: Image.asset(
                'assets/card-1248.png',
                // Cartão retrato: limita a altura pra não dominar a tela.
                height: 380,
                fit: BoxFit.contain,
              ),
            ),
            const SizedBox(height: 16),

            // ── Como funciona ──────────────────────────────────────────────
            _card(
              icone: Icons.info_outline,
              titulo: 'Como funciona',
              filho: const Text(
                'Cada palavra da seed (12 ou 24) tem um número de 1 a 2048 na lista BIP-39:\n\n'
                '• O primeiro espaço é a posição da frase (1-12 ou 1-24).\n'
                '• O primeiro grupo é o do milhar (1 ou 2), o segundo grupo de números '
                '(1, 2, 4 e 8) marca a casa da centena, depois a dezena e o último a '
                'unidade: fure 1 · 2 · 4 · 8 pra registrar o número da palavra.\n'
                '• Dígito 0 = deixa o grupo sem furo.  Ex.: dígito 9 = fura 1 + 8, '
                'dígito 7 = fura 1 + 2 + 4.\n\n'
                'Exemplo: palavra nº 1709 → 1-7-0-9 → fura 1 (milhar), 1+2+4 (centena), '
                'dezena 0 sem furo, 1+8 (unidade).',
                style: TextStyle(fontSize: 13.5, height: 1.55, color: Cores.textoSuave),
              ),
            ),
            const SizedBox(height: 16),

            // ── Aviso de segurança ─────────────────────────────────────────
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: const Color(0xFF8a6d1a).withValues(alpha: 0.15),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: const Color(0xFFb8860b).withValues(alpha: 0.4)),
              ),
              child: const Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(Icons.warning_amber_rounded, color: Cores.dourado, size: 22),
                  SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      'Quem tiver os cartões tem acesso à carteira. Guarde em segredo, '
                      'longe de fotos e telas — furo em papel grosso ou metal não apaga.',
                      style: TextStyle(fontSize: 12.5, height: 1.5, color: Cores.textoSuave),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),

            // ── Ações ──────────────────────────────────────────────────────
            FilledButton.icon(
              style: FilledButton.styleFrom(
                backgroundColor: Cores.dourado,
                foregroundColor: Colors.black,
                padding: const EdgeInsets.symmetric(vertical: 14),
              ),
              onPressed: () => _abrir('$kBaseUrl/card-1248.pdf?v=1'),
              icon: const Icon(Icons.picture_as_pdf),
              label: const Text(
                'Baixar PDF com 24 frases',
                style: TextStyle(fontWeight: FontWeight.w700),
              ),
            ),
            const SizedBox(height: 10),
            OutlinedButton.icon(
              style: OutlinedButton.styleFrom(
                foregroundColor: Cores.dourado,
                side: const BorderSide(color: Cores.borda),
                padding: const EdgeInsets.symmetric(vertical: 12),
              ),
              onPressed: () => _abrir('$kBaseUrl/1248'),
              icon: const Icon(Icons.open_in_new, size: 18),
              label: const Text('Simulador interativo (site)'),
            ),
            const SizedBox(height: 8),
            const Text(
              'Dica: imprima em papel 200g+, cartolina ou cartão — '
              'ou use como guia pra furar uma placa de metal.',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 11.5, height: 1.5, color: Cores.textoSuave),
            ),
          ],
        ),
      ),
    );
  }

  Widget _card({required IconData icone, required String titulo, required Widget filho}) {
    return Container(
      decoration: BoxDecoration(
        color: Cores.cartao,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Cores.borda),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 4),
            child: Row(
              children: [
                Icon(icone, color: Cores.dourado, size: 20),
                const SizedBox(width: 8),
                Text(titulo, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5)),
              ],
            ),
          ),
          Padding(padding: const EdgeInsets.fromLTRB(16, 4, 16, 16), child: filho),
        ],
      ),
    );
  }
}
