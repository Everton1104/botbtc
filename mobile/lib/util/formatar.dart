// ─────────────────────────────────────────────────────────────────────────────
// FORMATAÇÃO DE NÚMEROS — o mesmo visual do site.
//
// O site formata com Intl.NumberFormat do JavaScript ('pt-BR' para reais,
// 2 casas; 'en-US' para quantidades de BTC). O pacote intl do Dart faz o
// mesmo serviço: 12136.44 → "R$ 12.136,44" na formatação brasileira.
//
// Os objetos NumberFormat são criados UMA vez (final, no topo do arquivo)
// porque construir um formatador é caro — recriar a cada célula da tabela
// deixaria a lista engasgada.
// ─────────────────────────────────────────────────────────────────────────────

import 'package:intl/intl.dart';

final NumberFormat _fmtMoeda =
    NumberFormat.currency(locale: 'pt_BR', symbol: 'R\$ ', decimalDigits: 2);

/// 3709.58 → "R$ 3.709,58" (mesmo fmt() do site).
String moeda(num valor) => _fmtMoeda.format(valor);

/// 0.00176 → "0,00176"? Não! O site mostra BTC no padrão en-US com ponto:
/// qty.toFixed(5) → "0.00176". Mantemos igual para não confundir quem lê
/// os dois lados.
final NumberFormat _fmtBtc =
    NumberFormat.decimalPatternDigits(locale: 'en_US', decimalDigits: 5);

/// Quantidade de BTC com 5 casas, padrão en-US (igual ao site).
String qtdBtc(num valor) => _fmtBtc.format(valor);

/// Seta + cor usadas nos lucros: ▲ verde quando positivo, ▼ vermelho quando
/// negativo (o site faz a mesma coisa com text-green / text-red).
(String seta, bool positivo) setaLucro(num valor) {
  if (valor > 0) return ('▲', true);
  if (valor < 0) return ('▼', false);
  return ('', true);
}
