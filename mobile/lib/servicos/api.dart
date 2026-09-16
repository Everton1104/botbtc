// ─────────────────────────────────────────────────────────────────────────────
// SERVIÇO DE API — a ponte entre o app e o servidor Laravel.
//
// Aqui vive TUDO que conversa com a rede: login, logout, buscar dados do
// usuário. As telas não fazem HTTP por conta própria; elas chamam os métodos
// estáticos desta classe (ApiService.login(...), ApiService.me(), ...).
//
// Como a autenticação funciona:
//   1. O app manda email + senha para POST /api/login.
//   2. O servidor devolve um "token" (uma string longa e única).
//   3. O app guarda esse token no armazenamento local (shared_preferences)
//      e o envia no cabeçalho "Authorization: Bearer <token>" nas próximas
//      chamadas. É como um crachá: apresentou, entra.
// ─────────────────────────────────────────────────────────────────────────────

import 'dart:convert'; // jsonEncode / jsonDecode: converte objetos Dart ↔ JSON

import 'package:http/http.dart' as http; // pacote para fazer requisições HTTP
import 'package:shared_preferences/shared_preferences.dart'; // "caderninho" que salva dados no aparelho

/// URL base do servidor.
///
/// IMPORTANTE — por que 127.0.0.1 (o próprio aparelho)?
/// Neste projeto o servidor Laravel roda DENTRO DO CELULAR, no Termux/proot
/// (a "dev box" é o próprio aparelho). Um app Android acessando 127.0.0.1
/// chega nos programas do Termux, porque compartilham a mesma rede local
/// do Android. Vantagem: funciona até sem Wi-Fi, pois não depende do IP da rede.
///
/// Quando o app for publicado, trocar por: https://botbtc.com.br
const String kBaseUrl = 'http://127.0.0.1:8000';

/// Chave usada para guardar/ler o token no armazenamento local.
/// Centralizada aqui para não espalhar a string 'token_api' pelo código
/// (se um dia precisar mudar, muda só nesta linha).
const String _chaveToken = 'token_api';

/// Exceção personalizada para erros QUE VÊM DA API (senha errada, token
/// expirado, etc.).
///
/// Por que uma classe própria? Porque assim a tela de login pode capturar
/// `on ApiException` e mostrar a mensagem do servidor, separando isso de
/// outros erros (ex.: servidor fora do ar), que são tratados de forma diferente.
class ApiException implements Exception {
  final String mensagem; // texto pronto para mostrar na tela
  final int? status; // código HTTP (401, 429...), útil para decisões

  ApiException(this.mensagem, [this.status]);

  /// O que aparece quando alguém usa a exceção como String (ex.: '$e').
  @override
  String toString() => mensagem;
}

/// Modelo do usuário — os dados que a API devolve sobre quem logou.
///
/// O JSON do servidor vem assim:
///   {"id":1, "name":"Everton", "email":"...", "whatsapp":"55...",
///    "whatsapp_verificado":true}
class Usuario {
  final int id;
  final String nome;
  final String email;
  final String? whatsapp; // null se ainda não cadastrou o número
  final bool whatsappVerificado; // true se já confirmou o código

  /// Construtor: `required` obriga quem criar um Usuario a informar os campos.
  const Usuario({
    required this.id,
    required this.nome,
    required this.email,
    this.whatsapp,
    required this.whatsappVerificado,
  });

  /// Construtor "de fábrica": cria um Usuario a partir do mapa que resulta
  /// de jsonDecode(...). À direita vão as chaves EXATAS do JSON do Laravel;
  /// à esquerda, os nomes que usaremos no resto do app (em português).
  factory Usuario.fromJson(Map<String, dynamic> json) {
    return Usuario(
      id: json['id'] as int,
      nome: json['name'] as String,
      email: json['email'] as String,
      whatsapp: json['whatsapp'] as String?,
      whatsappVerificado: json['whatsapp_verificado'] as bool,
    );
  }
}

/// O serviço em si. Métodos estáticos = chamamos direto na classe
/// (ApiService.login(...)), sem precisar criar `ApiService()`.
class ApiService {
  /// Cópia do token na memória do app (preenchida no login ou ao abrir).
  /// O header Authorization precisa de uma String imediata, e ler o
  /// shared_preferences é assíncrono — por isso mantemos esta cópia.
  static String? tokenEmMemoria;

  /// Pergunta ao armazenamento local: existe um token salvo?
  ///
  /// Usado logo na abertura do app para decidir a primeira tela:
  /// tem token → vai direto para a Home; não tem → mostra o Login.
  static Future<bool> temTokenSalvo() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_chaveToken) != null;
  }

  /// Lê o token salvo no aparelho e guarda na memória. `??=` significa
  /// "só preenche se ainda estiver null" (não substitui um token novo).
  static Future<String?> carregarTokenSalvo() async {
    tokenEmMemoria ??= (await SharedPreferences.getInstance()).getString(_chaveToken);
    return tokenEmMemoria;
  }

  /// Apaga o token (do caderninho e da memória). Chamado no logout.
  static Future<void> limparToken() async {
    tokenEmMemoria = null;
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_chaveToken);
  }

  /// Faz login: envia email e senha, recebe o token e devolve o Usuario.
  ///
  /// `Future<Usuario>` = "prometo entregar um Usuario depois" (a rede leva
  /// tempo; por isso as telas usam `await`).
  static Future<Usuario> login({
    required String email,
    required String senha,
  }) async {
    // Monta e envia a requisição POST (o `await` pausa SÓ esta função,
    // a interface continua respondendo — é a graça do async).
    final resposta = await http.post(
      Uri.parse('$kBaseUrl/api/login'), // endpoint completo
      headers: {'Accept': applicationJson}, // pedimos resposta em JSON
      body: jsonEncode({
        'email': email,
        'password': senha, // o servidor espera "password", não "senha"
        'device_name': 'android', // nome do token no servidor (aparece no banco)
      }),
    );

    // 200 = sucesso. Qualquer outro código cai no _lancaErro.
    if (resposta.statusCode == 200) {
      final dados = jsonDecode(resposta.body) as Map<String, dynamic>;
      // Guarda o "crachá" em memória e no aparelho (sobrevive ao fechar o app).
      tokenEmMemoria = dados['token'] as String;
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_chaveToken, tokenEmMemoria!);
      return Usuario.fromJson(dados['user'] as Map<String, dynamic>);
    }

    _lancaErro(resposta); // nunca retorna: sempre joga uma ApiException
  }

  /// Busca os dados do dono do token salvo (GET /api/me).
  ///
  /// Serve também para CONFIRMAR que o token ainda é válido — se o servidor
  /// responder 401, o token foi revogado/expirou e o app volta pro login.
  static Future<Usuario> me() async {
    // Se o app abriu direto na Home (sem login nesta sessão), o token ainda
    // está só no disco — precisamos trazê-lo para a memória antes de usar.
    await carregarTokenSalvo();

    final resposta = await http.get(
      Uri.parse('$kBaseUrl/api/me'),
      headers: _headersComToken(),
    );

    if (resposta.statusCode == 200) {
      final dados = jsonDecode(resposta.body) as Map<String, dynamic>;
      return Usuario.fromJson(dados['user'] as Map<String, dynamic>);
    }

    _lancaErro(resposta);
  }

  /// Encerra a sessão: pede ao servidor para revogar o token atual.
  ///
  /// Se a chamada falhar (ex.: sem internet), mesmo assim limpamos o token
  /// local — do ponto de vista do app, sessão encerrada é sessão sem token.
  static Future<void> logout() async {
    try {
      await carregarTokenSalvo(); // garante o header com o token certo
      await http.post(
        Uri.parse('$kBaseUrl/api/logout'),
        headers: _headersComToken(),
      );
    } finally {
      // `finally` roda SEMPRE — com erro ou sem erro. Garante a limpeza.
      await limparToken();
    }
  }

  /// Cabeçalhos comuns a toda chamada autenticada.
  ///
  /// "Bearer" é o esquema que o Laravel Sanctum espera no header
  /// Authorization. Se não houver token em memória, o header nem é enviado
  /// (o `if` dentro do mapa faz essa inclusão condicional).
  static Map<String, String> _headersComToken() {
    return {
      'Accept': applicationJson,
      if (tokenEmMemoria != null) 'Authorization': 'Bearer $tokenEmMemoria',
    };
  }

  /// Traduz uma resposta de erro do servidor em ApiException com mensagem
  /// amigável. O tipo `Never` deixa explícito: esta função SEMPRE joga
  /// exceção, nunca devolve um valor.
  static Never _lancaErro(http.Response resposta) {
    String mensagem = 'Erro inesperado (${resposta.statusCode}).';
    try {
      final corpo = jsonDecode(resposta.body);
      if (corpo is Map && corpo['message'] is String) {
        // O Laravel quase sempre manda {"message": "..."} nos erros.
        mensagem = corpo['message'] as String;
      } else if (corpo is Map && corpo['errors'] is Map && (corpo['errors'] as Map).isNotEmpty) {
        // Erros de validação (422): pega a primeira reclamação do formulário.
        final primeiro = (corpo['errors'] as Map).values.first;
        if (primeiro is List && primeiro.isNotEmpty) {
          mensagem = primeiro.first as String;
        }
      }
    } catch (_) {
      // O corpo não era JSON — mantemos a mensagem genérica acima.
    }

    if (resposta.statusCode == 429) {
      // 429 = "too many requests": o limitador de tentativas do login agiu.
      mensagem = 'Muitas tentativas. Aguarde um minuto e tente de novo.';
    }

    throw ApiException(mensagem, resposta.statusCode);
  }
}

/// Constante evita digitar essa string em vários lugares (e errar sem perceber).
const String applicationJson = 'application/json';
