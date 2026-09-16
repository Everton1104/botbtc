// ─────────────────────────────────────────────────────────────────────────────
// SERVIÇO DE NOTIFICAÇÕES PUSH — a ponte com o Firebase Cloud Messaging (FCM).
//
// Como um push chega no celular:
//
//   [Laravel] ──(chama a API do Firebase)──▶ [servidor do Google]
//        ──(empurra pro aparelho com o token)──▶ [este app] 🔔
//
// O que este arquivo faz, na ordem em que o app liga:
//   1. inicializar(): conecta ao Firebase, cria o "canal" de notificações
//      (grupo onde o Android agrupa os avisos do app), pede permissão ao
//      usuário (Android 13+) e pega o TOKEN — o endereço único deste celular
//      dentro do Firebase.
//   2. Entrega o token ao Laravel (POST /api/dispositivo-token). É assim que
//      o servidor sabe pra quem mandar push quando o bot operar.
//   3. Registra os "ouvintes":
//      • onMessage       → push chegando com o app ABERTO (mostramos na tela);
//      • onBackgroundMessage → push com o app FECHADO (o próprio Android
//        exibe na bandeja; este handler nem roda Dart da interface);
//      • onTokenRefresh  → o Firebase trocou o token (reinstalação etc.);
//        entregamos o novo ao Laravel de novo.
// ─────────────────────────────────────────────────────────────────────────────

import 'dart:ui'; // Color (usada no detalhe dourado da notificação)

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

import 'api.dart';

/// Instância do plugin de notificações locais — "local" = desenhada pelo
/// próprio app (usada pra mostrar o push na tela quando o app está aberto).
final FlutterLocalNotificationsPlugin _notificacoesLocais =
    FlutterLocalNotificationsPlugin();

/// O mesmo canal que o Laravel cita no envio ('botbtc_aviso' no FcmService).
/// Android 8+ exige que toda notificação pertença a um canal — é ele que
/// o usuário vê em Configurações → Notificações → BotBTC.
const AndroidNotificationChannel _canal = AndroidNotificationChannel(
  'botbtc_aviso', // id: tem que bater com o channel_id do FcmService
  'Avisos do bot', // nome visível pro usuário
  description: 'Compras, vendas e saques do BotBTC',
  importance: Importance.high, // alta = aparece como banner no topo
);

/// Handler de push com o app FECHADO/recolhido.
///
/// PRECISA ser uma função global (fora de qualquer classe) e anotada com
/// @pragma('vm:entry-point'): quando o app está morto, o Android sobe uma
/// "isoleta" separada só pra executar este handler — sem essa anotação, o
/// compilador acharia que a função não é usada e a apagaria da build.
@pragma('vm:entry-point')
Future<void> _aoReceberPushEmSegundoPlano(RemoteMessage mensagem) async {
  // Nada a fazer aqui: com o app fechado, o próprio sistema desenha a
  // notificação na bandeja a partir do payload. Este handler existe porque
  // o firebase_messaging o exige — e é útil se um dia quisermos, por
  // exemplo, salvar dados do push mesmo com o app fechado.
}

/// Inicializa tudo: Firebase → canal → permissão → token → ouvintes.
///
/// Chamada uma única vez no main(), antes do runApp. Se qualquer coisa
/// falhar (ex.: Firebase ainda não configurado), o app segue funcionando
/// SEM push — notificação é acessório, nunca pode derrubar o login/painel.
Future<void> inicializarNotificacoes() async {
  try {
    // 1. Conecta ao projeto Firebase (usa o google-services.json da build).
    await Firebase.initializeApp();

    final mensageiro = FirebaseMessaging.instance;

    // 2. Cria o canal no Android (se já existe, o Android só ignora).
    await _prepararNotificacoesLocais();

    // 3. Permissão: no Android 13+ o usuário precisa aprovar. A resposta
    //    (aceitou/negou) não muda nosso fluxo — só com aprovação o push
    //    aparece; negado, o app funciona normal, só sem avisos.
    await mensageiro.requestPermission();

    // 4. Pega o token deste celular e entrega ao Laravel.
    await _registrarToken(mensageiro);

    // 5. Ouvintes de chegada de push e de troca de token.
    FirebaseMessaging.onMessage.listen(_aoReceberPushComAppAberto);
    FirebaseMessaging.onBackgroundMessage(_aoReceberPushEmSegundoPlano);
    mensageiro.onTokenRefresh.listen((_) => _registrarToken(mensageiro));
  } catch (e) {
    // Sem drama: app continua de pé, só sem notificações.
    // ignore: avoid_print
    print('Notificações indisponíveis: $e');
  }
}

/// Prepara o plugin de notificações locais e cria o canal no Android.
Future<void> _prepararNotificacoesLocais() async {
  // initialize() liga o plugin; o ícone escolhido aqui é o default dos
  // avisos que NÓS desenhamos (app aberto).
  await _notificacoesLocais.initialize(
    // Na v22 do pacote os parâmetros viraram NOMEADOS — por isso o
    // `settings:` explícito (nas versões antigas era posicional).
    settings: const InitializationSettings(
      android: AndroidInitializationSettings('@mipmap/ic_launcher'), // ícone: o ₿
    ),
  );

  // O ?? . é "se existir a implementação Android, cria o canal; senão nada".
  // (No desktop/teste não existe — a chamada simplesmente não acontece.)
  await _notificacoesLocais
      .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
      ?.createNotificationChannel(_canal);
}

/// Re-entrega o token FCM ao Laravel. Chamado logo após um LOGIN bem-sucedido:
/// o registro do início (main) acontece antes do login existir, então a
/// primeira sessão precisaria reiniciar o app pra receber push sem isto.
Future<void> sincronizarToken() async {
  try {
    await _registrarToken(FirebaseMessaging.instance);
  } catch (_) {
    // mesma regra: push é acessório, nunca derruba nada
  }
}

/// Pega o token FCM e envia ao Laravel.
Future<void> _registrarToken(FirebaseMessaging mensageiro) async {
  final token = await mensageiro.getToken();
  if (token != null) {
    await ApiService.registrarDispositivo(token);
  }
}

/// Push chegando com o app ABERTO.
///
/// Aqui o Android NÃO desenha nada sozinho (entende que o usuário já está
/// olhando o app) — então nós mesmos mostramos a notificação local, senão o
/// push chegaria silenciosamente e ninguém veria.
Future<void> _aoReceberPushComAppAberto(RemoteMessage mensagem) async {
  final notificacao = mensagem.notification;
  if (notificacao == null) return; // push só de dados — nada a exibir

  await _notificacoesLocais.show(
    // id único por notificação (segundos desde 1970) — repetir um id
    // SUBSTITUI a notificação anterior em vez de empilhar.
    id: DateTime.now().millisecondsSinceEpoch ~/ 1000,
    title: notificacao.title,
    body: notificacao.body,
    notificationDetails: NotificationDetails(
      android: AndroidNotificationDetails(
        _canal.id,
        _canal.name,
        channelDescription: _canal.description,
        icon: '@mipmap/ic_launcher', // o ₿ dourado
        color: const Color(0xFFF0B90B), // detalhes na cor do site
        styleInformation: BigTextStyleInformation(notificacao.body ?? ''),
      ),
    ),
  );
}
