// ─────────────────────────────────────────────────────────────────────────────
// MODELOS DE DEPÓSITO — o espelho Dart do JSON que /api/deposito/* devolve.
//
// O JSON da tela tem esta cara (resumido):
// {
//   "eh_admin": true,
//   "pendente_atual": {"txid":"123","valor":"100.00","qr_code":"<png-base64>",
//                      "copia_e_cola":"000201...","payment_url":null,
//                      "expiracao":"2026-10-05T21:30:00Z","gateway":"mercadopago"},
//   "historico": [{"id":9,"valor":"100.00","pago_em":"05/10/2026 14:00",
//                  "btc_price":360000,"status":"pago","registrado":true}],
//   "admin_depositos": [{"id":9,"txid":"123","user_name":"Everton",
//                        "valor":"100.00","liquido":99.0,"metodo":"PIX",
//                        "pago_em":"05/10/2026 14:00","registrado":false,
//                        "estornado":false}],
//   "investidores": [{"id":8,"name":"William","email":"...","cotas":0}],
//   "pausa": {"pausado": true, "segundos": 600000, "motivo": "deposito"}
// }
//
// DepositoPendente também parseia a resposta do POST /api/deposito/criar
// (mesmos campos). Gateway infinitepay: sem qr_code, payment_url preenchido.
// ─────────────────────────────────────────────────────────────────────────────

import 'saque.dart' show StatusPausa;

/// A aba de depósitos inteira: uma resposta = um objeto deste.
class DadosDeposito {
  final bool ehAdmin; // true = usuário id 1 (quem registra os depósitos)
  final DepositoPendente? pendenteAtual; // cobrança em aberto (null = nenhuma)
  final List<DepositoHistorico> historico; // meus depósitos pagos/estornados
  final List<DepositoAdmin> adminDepositos; // de todos (vazia p/ não-admin)
  final List<Investidor> investidores; // seletor do manual + lista de contas
  final String gatewayNome; // quem processa o pagamento (vem do env do servidor)
  final StatusPausa? pausa; // bot pausado p/ depósito manual (null p/ não-admin)

  const DadosDeposito({
    required this.ehAdmin,
    required this.pendenteAtual,
    required this.historico,
    required this.adminDepositos,
    required this.investidores,
    required this.gatewayNome,
    this.pausa,
  });

  factory DadosDeposito.fromJson(Map<String, dynamic> json) {
    return DadosDeposito(
      ehAdmin: json['eh_admin'] as bool? ?? false,
      gatewayNome: json['gateway_nome'] as String? ?? 'PIX',
      pendenteAtual: json['pendente_atual'] == null
          ? null
          : DepositoPendente.fromJson(
              json['pendente_atual'] as Map<String, dynamic>),
      historico: (json['historico'] as List? ?? [])
          .map((d) => DepositoHistorico.fromJson(d as Map<String, dynamic>))
          .toList(),
      adminDepositos: (json['admin_depositos'] as List? ?? [])
          .map((d) => DepositoAdmin.fromJson(d as Map<String, dynamic>))
          .toList(),
      investidores: (json['investidores'] as List? ?? [])
          .map((i) => Investidor.fromJson(i as Map<String, dynamic>))
          .toList(),
      pausa: json['pausa'] == null
          ? null
          : StatusPausa.fromJson(json['pausa'] as Map<String, dynamic>),
    );
  }
}

/// Um investidor da lista do admin (seletor do depósito manual e a seção
/// "Contas"). `cotas` zeradas = conta sem dinheiro, pode ser removida.
class Investidor {
  final int id;
  final String nome;
  final String email;
  final double cotas;

  const Investidor({
    required this.id,
    required this.nome,
    required this.email,
    required this.cotas,
  });

  factory Investidor.fromJson(Map<String, dynamic> json) => Investidor(
        id: json['id'] as int,
        nome: json['name'] as String,
        email: json['email'] as String,
        cotas: _num(json['cotas']),
      );
}

/// Uma cobrança PIX em aberto — nasce do /deposito/criar ou do pendente_atual.
class DepositoPendente {
  final String txid; // id da cobrança no gateway (MP) ou "ip_"+uuid
  final double valor;
  final String? qrCode; // PNG em base64 (null no infinitepay)
  final String? copiaECola; // string PIX copia-e-cola (ou URL no infinitepay)
  final String? paymentUrl; // só infinitepay: link de pagamento
  final DateTime? expiracao; // UTC do servidor → converter p/ local
  final String gateway; // 'mercadopago' | 'infinitepay'

  const DepositoPendente({
    required this.txid,
    required this.valor,
    required this.qrCode,
    required this.copiaECola,
    required this.paymentUrl,
    required this.expiracao,
    required this.gateway,
  });

  factory DepositoPendente.fromJson(Map<String, dynamic> json) {
    return DepositoPendente(
      txid: json['txid'] as String,
      valor: _num(json['valor']),
      qrCode: json['qr_code'] as String?,
      copiaECola: json['copia_e_cola'] as String?,
      paymentUrl: json['payment_url'] as String?,
      // O servidor serializa em ISO UTC — toLocal() acerta o countdown.
      expiracao: DateTime.tryParse(json['expiracao'] as String? ?? '')
          ?.toLocal(),
      gateway: json['gateway'] as String? ?? 'mercadopago',
    );
  }

  /// True quando a cobrança é um link (infinitepay) em vez de QR.
  bool get ehLink => paymentUrl != null && paymentUrl!.isNotEmpty;
}

/// Resposta do GET /api/deposito/status/{txid} (polling de 5s).
class StatusDeposito {
  final String txid;
  final String status; // pendente | pago | expirado | cancelado
  final double valor;

  const StatusDeposito({
    required this.txid,
    required this.status,
    required this.valor,
  });

  factory StatusDeposito.fromJson(Map<String, dynamic> json) => StatusDeposito(
        txid: json['txid'] as String,
        status: json['status'] as String,
        valor: _num(json['valor']),
      );
}

/// Um depósito MEU já pago (ou estornado) — linha do histórico.
class DepositoHistorico {
  final int id;
  final double valor;
  final String pagoEm; // já formatado: "05/10/2026 14:00"
  final double? btcPrice; // preço do BTC na hora do pagamento
  final String status; // 'pago' | 'estornado'
  final bool registrado; // true = admin já creditou as cotas

  const DepositoHistorico({
    required this.id,
    required this.valor,
    required this.pagoEm,
    required this.btcPrice,
    required this.status,
    required this.registrado,
  });

  bool get estornado => status == 'estornado';

  factory DepositoHistorico.fromJson(Map<String, dynamic> json) {
    return DepositoHistorico(
      id: json['id'] as int,
      valor: _num(json['valor']),
      pagoEm: json['pago_em'] as String? ?? '—',
      btcPrice: json['btc_price'] == null ? null : _num(json['btc_price']),
      status: json['status'] as String? ?? 'pago',
      registrado: json['registrado'] as bool? ?? false,
    );
  }
}

/// Depósito confirmado de QUALQUER investidor (só admin vê) — com as ações
/// "Registrar no Bot" (credita cotas) e "Estornar".
class DepositoAdmin {
  final int id;
  final String txid;
  final int userId;
  final String userName;
  final String userEmail;
  final double valor; // bruto pago
  final double liquido; // o que entra nas cotas (taxa já descontada)
  final String metodo; // 'PIX' | 'Cartão Nx'
  final String pagoEm;
  final bool registrado;
  final bool estornado;

  const DepositoAdmin({
    required this.id,
    required this.txid,
    required this.userId,
    required this.userName,
    required this.userEmail,
    required this.valor,
    required this.liquido,
    required this.metodo,
    required this.pagoEm,
    required this.registrado,
    required this.estornado,
  });

  factory DepositoAdmin.fromJson(Map<String, dynamic> json) => DepositoAdmin(
        id: json['id'] as int,
        txid: json['txid'] as String,
        userId: json['user_id'] as int? ?? 0,
        userName: json['user_name'] as String? ?? 'Desconhecido',
        userEmail: json['user_email'] as String? ?? '—',
        valor: _num(json['valor']),
        liquido: _num(json['liquido']),
        metodo: json['metodo'] as String? ?? 'PIX',
        pagoEm: json['pago_em'] as String? ?? '—',
        registrado: json['registrado'] as bool? ?? false,
        estornado: json['estornado'] as bool? ?? false,
      );
}

/// Aceita número OU string-numérica ("100.00") e devolve double
/// (DECIMAL do MySQL chega como string — mesma história do saque.dart).
double _num(dynamic v) {
  if (v is num) return v.toDouble();
  if (v is String) return double.tryParse(v) ?? 0;
  return 0;
}
