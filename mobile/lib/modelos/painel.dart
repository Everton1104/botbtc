// ─────────────────────────────────────────────────────────────────────────────
// MODELOS DO PAINEL — o espelho Dart do JSON que /api/painel devolve.
//
// O JSON tem esta cara (resumido):
// {
//   "tiles": {
//     "binance_ok": true, "preco_btc": 390197,
//     "brl":     {"total": 3709.57, "livre": 3042.21, "bloqueado": 667.36},
//     "btc_brl": {"total": 7830.37, "livre": 7194.35, "bloqueado": 636.02},
//     "bnb_brl": 596.49, "total_geral_brl": 12136.44
//   },
//   "ordens":       [{"lado": "BUY", "preco": 379185, "quantidade": 0.00176, "status": "NEW"}],
//   "investidores": [{"name": "Everton", "investimento_inicial": "5711.75", ...}],
//   "saques":       [{"name": "...", "valor_bruto": "100.00", "criado_em": "16/09/2026 10:00"}]
// }
//
// Detalhe IMPORTANTE: campos de dinheiro vindo do MySQL DECIMAL chegam como
// STRING ("5711.75514016"), enquanto os calculados no controller chegam como
// número. A função `_num` aceita os dois — nunca confie no tipo vindo do JSON.
// ─────────────────────────────────────────────────────────────────────────────

/// Painel completo: uma resposta = um objeto deste.
class Painel {
  final Tiles tiles;
  final List<Ordem> ordens;
  final List<Investidor> investidores;
  final List<SaquePendente> saques;

  const Painel({
    required this.tiles,
    required this.ordens,
    required this.investidores,
    required this.saques,
  });

  factory Painel.fromJson(Map<String, dynamic> json) {
    return Painel(
      tiles: Tiles.fromJson(json['tiles'] as Map<String, dynamic>),
      ordens: (json['ordens'] as List? ?? [])
          .map((o) => Ordem.fromJson(o as Map<String, dynamic>))
          .toList(),
      investidores: (json['investidores'] as List? ?? [])
          .map((i) => Investidor.fromJson(i as Map<String, dynamic>))
          .toList(),
      saques: (json['saques'] as List? ?? [])
          .map((s) => SaquePendente.fromJson(s as Map<String, dynamic>))
          .toList(),
    );
  }
}

/// Os quatro "stat tiles" do topo (preço, saldos, total).
class Tiles {
  final bool binanceOk; // false = a Binance falhou (ex.: IP fora do whitelist)
  final double precoBtc;
  final double brlTotal;
  final double brlLivre;
  final double brlBloqueado;
  final double btcTotalBrl; // BTC convertido pra reais
  final double btcLivreBrl;
  final double btcBloqueadoBrl;
  final double bnbBrl;
  final double totalGeralBrl; // BRL + BTC em R$ + BNB em R$
  final double atr; // volatilidade que dimensiona o grid
  final double saltoAtr; // o "salto" dinâmico que o bot usa (ATR × fator)

  const Tiles({
    required this.binanceOk,
    required this.precoBtc,
    required this.brlTotal,
    required this.brlLivre,
    required this.brlBloqueado,
    required this.btcTotalBrl,
    required this.btcLivreBrl,
    required this.btcBloqueadoBrl,
    required this.bnbBrl,
    required this.totalGeralBrl,
    required this.atr,
    required this.saltoAtr,
  });

  factory Tiles.fromJson(Map<String, dynamic> json) {
    final brl = json['brl'] as Map<String, dynamic>;
    final btc = json['btc_brl'] as Map<String, dynamic>;
    return Tiles(
      binanceOk: json['binance_ok'] as bool? ?? false,
      precoBtc: _num(json['preco_btc']),
      brlTotal: _num(brl['total']),
      brlLivre: _num(brl['livre']),
      brlBloqueado: _num(brl['bloqueado']),
      btcTotalBrl: _num(btc['total']),
      btcLivreBrl: _num(btc['livre']),
      btcBloqueadoBrl: _num(btc['bloqueado']),
      bnbBrl: _num(json['bnb_brl']),
      totalGeralBrl: _num(json['total_geral_brl']),
      atr: _num(json['atr']),
      saltoAtr: _num(json['salto_atr']),
    );
  }
}

/// Uma ordem aberta na Binance (linha da tabela "Ordens Abertas").
class Ordem {
  final String lado; // "BUY" ou "SELL"
  final double preco;
  final double quantidade; // em BTC
  final String status; // ex.: "NEW"

  const Ordem({required this.lado, required this.preco, required this.quantidade, required this.status});

  bool get isCompra => lado == 'BUY';
  double get total => preco * quantidade; // o site calcula preço × quantidade

  factory Ordem.fromJson(Map<String, dynamic> json) => Ordem(
        lado: json['lado'] as String,
        preco: _num(json['preco']),
        quantidade: _num(json['quantidade']),
        status: json['status'] as String,
      );
}

/// Um investidor (linha da tabela "Investidores" — só o admin recebe).
class Investidor {
  final int id;
  final String nome;
  final String email;
  final double investimentoInicial;
  final double cotas;
  final double percentual; // participação no total (0–100)
  final double valorAtual;
  final double lucro;

  const Investidor({
    required this.id,
    required this.nome,
    required this.email,
    required this.investimentoInicial,
    required this.cotas,
    required this.percentual,
    required this.valorAtual,
    required this.lucro,
  });

  factory Investidor.fromJson(Map<String, dynamic> json) => Investidor(
        id: json['id'] as int,
        nome: json['name'] as String,
        email: json['email'] as String,
        investimentoInicial: _num(json['investimento_inicial']),
        cotas: _num(json['cotas']),
        percentual: _num(json['percentual']),
        valorAtual: _num(json['valor_atual']),
        lucro: _num(json['lucro']),
      );
}

/// Um saque aguardando o admin (linha da tabela "Saques Pendentes").
class SaquePendente {
  final int id;
  final String nome;
  final double valorBruto;
  final double valorLiquido; // líquido após taxa PIX
  final double cotas;
  final String criadoEm; // já formatado pelo servidor: "16/09/2026 10:00"

  const SaquePendente({
    required this.id,
    required this.nome,
    required this.valorBruto,
    required this.valorLiquido,
    required this.cotas,
    required this.criadoEm,
  });

  factory SaquePendente.fromJson(Map<String, dynamic> json) => SaquePendente(
        id: json['id'] as int,
        nome: json['name'] as String,
        valorBruto: _num(json['valor_bruto']),
        valorLiquido: _num(json['valor_liquido']),
        cotas: _num(json['cotas']),
        criadoEm: json['criado_em'] as String,
      );
}

/// Aceita número OU string-numérica ("5711.75") e devolve double.
/// É o conforto para campos DECIMAL do MySQL, que o Laravel serializa
/// como string para não perder precisão.
double _num(dynamic v) {
  if (v is num) return v.toDouble();
  if (v is String) return double.tryParse(v) ?? 0;
  return 0;
}
