// ─────────────────────────────────────────────────────────────────────────────
// MODELOS DE SAQUE — o espelho Dart do JSON que /api/saque/tela devolve.
//
// O JSON tem esta cara (resumido):
// {
//   "eh_admin": true,
//   "disponivel": 5711.75,          // o máximo que dá pra sacar hoje
//   "pendentes":  [{"id": 7, "valor_bruto": "100.00", ...}],
//   "historico":  [{"valor_liquido": "99.00", "confirmado_em": "16/09/2026 10:00"}],
//   "aprovacoes": [{"id": 7, "name": "Everton", "valor_bruto": "100.00", ...}],  // só admin
//   "pausa":      {"pausado": true, "segundos": 143}                              // só admin
// }
//
// Mesma regra do painel: campos DECIMAL do MySQL chegam como STRING
// ("100.00"), os calculados vêm como número — _num aceita os dois.
// ─────────────────────────────────────────────────────────────────────────────

/// A tela de saques inteira: uma resposta = um objeto deste.
class DadosSaque {
  final bool ehAdmin; // true = usuário id 1 (quem aprova os saques)
  final double disponivel; // valor atual do investimento (teto do saque)
  final String? chavePix; // chave de recebimento (exigida no saque)
  final List<SaqueMeu> pendentes; // meus saques aguardando aprovação
  final List<SaqueHistorico> historico; // meus saques já confirmados
  final List<SaqueAprovacao> aprovacoes; // TODOS os pendentes (só admin)
  final StatusPausa? pausa; // bot pausado pós-confirmação (null p/ não-admin)

  const DadosSaque({
    required this.ehAdmin,
    required this.disponivel,
    required this.chavePix,
    required this.pendentes,
    required this.historico,
    required this.aprovacoes,
    required this.pausa,
  });

  factory DadosSaque.fromJson(Map<String, dynamic> json) {
    return DadosSaque(
      ehAdmin: json['eh_admin'] as bool? ?? false,
      disponivel: _num(json['disponivel']),
      chavePix: json['chave_pix'] as String?,
      pendentes: (json['pendentes'] as List? ?? [])
          .map((s) => SaqueMeu.fromJson(s as Map<String, dynamic>))
          .toList(),
      historico: (json['historico'] as List? ?? [])
          .map((s) => SaqueHistorico.fromJson(s as Map<String, dynamic>))
          .toList(),
      // Não-admin não recebe estas chaves do servidor → lista vazia / null.
      aprovacoes: (json['aprovacoes'] as List? ?? [])
          .map((s) => SaqueAprovacao.fromJson(s as Map<String, dynamic>))
          .toList(),
      pausa: json['pausa'] == null
          ? null
          : StatusPausa.fromJson(json['pausa'] as Map<String, dynamic>),
    );
  }
}

/// Um saque MEU aguardando aprovação (dá pra cancelar).
class SaqueMeu {
  final int id;
  final double valorBruto;
  final double valorLiquido; // bruto − 1% de taxa (admin não paga)
  final bool emAndamento; // true = admin já iniciou, PIX a caminho
  final String criadoEm; // já formatado: "16/09/2026 10:00"

  const SaqueMeu({
    required this.id,
    required this.valorBruto,
    required this.valorLiquido,
    this.emAndamento = false,
    required this.criadoEm,
  });

  factory SaqueMeu.fromJson(Map<String, dynamic> json) => SaqueMeu(
        id: json['id'] as int,
        valorBruto: _num(json['valor_bruto']),
        valorLiquido: _num(json['valor_liquido']),
        emAndamento: json['em_andamento'] as bool? ?? false,
        criadoEm: json['criado_em'] as String,
      );
}

/// Um saque meu já confirmado (dinheiro já saiu).
class SaqueHistorico {
  final double valorLiquido;
  final String confirmadoEm; // "16/09/2026 10:00" ou "—" sem data

  const SaqueHistorico({required this.valorLiquido, required this.confirmadoEm});

  factory SaqueHistorico.fromJson(Map<String, dynamic> json) => SaqueHistorico(
        valorLiquido: _num(json['valor_liquido']),
        confirmadoEm: json['confirmado_em'] as String,
      );
}

/// Um saque de QUALQUER investidor aguardando o OK do admin (só admin vê).
class SaqueAprovacao {
  final int id;
  final String nome;
  final String email;
  final String chavePix; // onde o admin paga o PIX ('—' se legado sem snapshot)
  final double valorBruto;
  final double valorLiquido;
  final double cotas;
  final bool emAndamento; // true = PIX iniciado, aguardando concluir/cancelar
  final String criadoEm;

  const SaqueAprovacao({
    required this.id,
    required this.nome,
    required this.email,
    required this.chavePix,
    required this.valorBruto,
    required this.valorLiquido,
    required this.cotas,
    this.emAndamento = false,
    required this.criadoEm,
  });

  factory SaqueAprovacao.fromJson(Map<String, dynamic> json) => SaqueAprovacao(
        id: json['id'] as int,
        nome: json['name'] as String,
        email: json['email'] as String,
        chavePix: json['chave_pix'] as String? ?? '—',
        valorBruto: _num(json['valor_bruto']),
        valorLiquido: _num(json['valor_liquido']),
        cotas: _num(json['cotas']),
        emAndamento: json['em_andamento'] as bool? ?? false,
        criadoEm: json['criado_em'] as String,
      );
}

/// Saque em andamento (status 'confirmado'): o PIX pode demorar — ex. limite
/// noturno da Binance — então o bot fica seguro até o admin concluir/cancelar.
class SaqueEmAndamento {
  final int id;
  final double valorLiquido;
  final String nome;

  const SaqueEmAndamento({
    required this.id,
    required this.valorLiquido,
    required this.nome,
  });

  factory SaqueEmAndamento.fromJson(Map<String, dynamic> json) =>
      SaqueEmAndamento(
        id: json['id'] as int,
        valorLiquido: _num(json['valor_liquido']),
        nome: json['nome'] as String? ?? '—',
      );
}

/// Situação da pausa do bot (só admin). O motivo diz quem pausou:
/// 'saque' = confirmação de saque (retoma sozinho em 3 min) |
/// 'deposito' = depósito manual (fica pausado até o admin liberar).
/// [saqueEmAndamento] não-null = há um PIX aguardando conclusão: a pausa é
/// renovada a cada ciclo do bot até o admin concluir ou cancelar o saque.
class StatusPausa {
  final bool pausado;
  final int segundos; // quanto tempo falta (0 quando não está pausado)
  final String? motivo; // 'saque' | 'deposito' | null
  final SaqueEmAndamento? saqueEmAndamento;

  const StatusPausa({
    required this.pausado,
    required this.segundos,
    this.motivo,
    this.saqueEmAndamento,
  });

  /// Pausa de depósito manual: sem countdown — sai quando o admin libera.
  bool get ehDeposito => motivo == 'deposito';

  factory StatusPausa.fromJson(Map<String, dynamic> json) => StatusPausa(
        pausado: json['pausado'] as bool? ?? false,
        segundos: (json['segundos'] as num?)?.toInt() ?? 0,
        motivo: json['motivo'] as String?,
        saqueEmAndamento: json['saque_em_andamento'] == null
            ? null
            : SaqueEmAndamento.fromJson(
                json['saque_em_andamento'] as Map<String, dynamic>),
      );
}

/// Aceita número OU string-numérica ("100.00") e devolve double
/// (DECIMAL do MySQL chega como string — mesma história do painel.dart).
double _num(dynamic v) {
  if (v is num) return v.toDouble();
  if (v is String) return double.tryParse(v) ?? 0;
  return 0;
}
