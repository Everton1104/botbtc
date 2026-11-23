// ─────────────────────────────────────────────────────────────────────────────
// AUTO-ATUALIZAÇÃO — download do APK de dentro do app (sem site/navegador).
//
// Quem usa: TelaAtualizacao (app bloqueado por versão mínima). O APK é
// baixado em STREAM com progresso (19 MB não podem congelar a tela sem
// informação) pro cache interno do app — obtido pelo mesmo canal nativo
// (MainActivity) que depois dispara o instalador, então o arquivo nasce
// num root que o FileProvider já enxerga (res/xml/file_paths.xml).
//
// ROBUSTEZ (o caso real de 23/11: download de 21 MB morrendo no meio por
// glitch de rede e a tela só dizendo "não foi possível baixar"):
//   · até 3 tentativas, RETOMANDO de onde parou com Range (o servidor do
//     APK anuncia accept-ranges: bytes — LiteSpeed/Cloudflare cortam
//     conexão longa com frequência em rede móvel; retomar transforma um
//     glitch em 2 segundos de espera em vez de recomeçar do zero);
//   · se o servidor ignorar o Range (responde 200 completa), o arquivo
//     parcial é descartado e o stream é usado inteiro;
//   · User-Agent próprio (BotBTC/versão) — identificação limpa no
//     servidor/Cloudflare e rastreio de problemas de entrega.
//
// Fora da Play Store o Android nunca instala em silêncio: o usuário sempre
// vê o "Atualizar app?" do sistema. O que este serviço elimina é o resto
// (abrir navegador, logar no site, achar o arquivo baixado).
// ─────────────────────────────────────────────────────────────────────────────

import 'dart:async';
import 'dart:io';

import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;

import 'api.dart' show kAppVersao;

const _canalInstalador = MethodChannel('botbtc/instalador');
const _tentativas = 3;

/// Cache interno do app, informado pelo Android (é o root que o
/// FileProvider tem mapeado — salvar fora dele trava o instalador).
Future<String> diretorioCache() async {
  return _canalInstalador.invokeMethod<String>('cacheDir') as String;
}

/// Dispara o instalador do sistema sobre o APK em [path]. Lança
/// PlatformException se o Android recusar (o Android exibe a permissão
/// "instalar desta fonte" na 1ª vez; quem chama oferece o site como plano B).
Future<void> instalarApk(String path) async {
  await _canalInstalador.invokeMethod('instalarApk', {'path': path});
}

/// Baixa o APK de [url] e devolve o caminho do arquivo salvo.
///
/// [progresso] é chamado a cada pedaço recebido com os BYTES acumulados e o
/// total real (0 = servidor não informou o tamanho; a UI mostra barra
/// indeterminada). Em falha lança HttpException com mensagem amigável —
/// quem chama decide se oferece o fallback do site.
Future<String> baixarApk(
  String url,
  void Function(int recebidos, int total) progresso,
) async {
  final dir = await diretorioCache();
  final arquivo = File('$dir/app-botbtc-atualizacao.apk');
  Object? ultima;

  // 3 tentativas; da 2ª em diante o download RETOMA do tamanho do arquivo
  // parcial (Range). Entre tentativas, espera que cresce (2s, 4s) — glitch
  // de rede curto se resolve sozinho sem recomeçar 21 MB do zero.
  for (var tentativa = 1; tentativa <= _tentativas; tentativa++) {
    try {
      var jaTem = (tentativa > 1 && arquivo.existsSync())
          ? arquivo.lengthSync()
          : 0;

      final requisicao = http.Request('GET', Uri.parse(url))
        ..headers['User-Agent'] = 'BotBTC/$kAppVersao (+https://botbtc.com.br)';
      if (jaTem > 0) {
        requisicao.headers['Range'] = 'bytes=$jaTem-';
      }

      final resposta =
          await http.Client().send(requisicao).timeout(const Duration(seconds: 30));

      // O servidor ignorou o Range e mandou o arquivo COMPLETO (200): o
      // parcial está sujo — recomeça do zero com o stream inteiro.
      if (resposta.statusCode == 200) {
        jaTem = 0;
      } else if (resposta.statusCode != 206) {
        throw HttpException(
            'O servidor respondeu ${resposta.statusCode} ao baixar o app.');
      }

      // Total real: no 206 o content-length é só do RESTO. O tamanho
      // completo vem no header content-range ("bytes X-Y/TOTAL").
      var total = resposta.contentLength ?? 0;
      if (jaTem > 0 && total > 0) total += jaTem;
      final faixa = resposta.headers['content-range'];
      if (faixa != null) {
        final partes = faixa.split('/');
        if (partes.length == 2 && int.tryParse(partes[1]) != null) {
          total = int.parse(partes[1]);
        }
      }

      final sink = arquivo.openWrite(mode: jaTem > 0 ? FileMode.append : FileMode.write);
      var recebido = jaTem;

      try {
        await for (final pedaco in resposta.stream) {
          sink.add(pedaco);
          recebido += pedaco.length;
          progresso(recebido, total);
        }
      } finally {
        await sink.close();
      }

      // Zerado/negativo = download morreu no meio sem erro de stream.
      if (total > 0 && recebido < total) {
        throw const HttpException('O download terminou antes do esperado.');
      }

      return arquivo.path;
    } catch (e) {
      ultima = e;
      if (tentativa < _tentativas) {
        await Future<void>.delayed(Duration(seconds: 2 * tentativa));
      }
    }
  }

  // Última tentativa também falhou: repassa o erro REAL (a tela mostra o
  // texto — diagnóstico na mão de quem testando, sem adivinhação).
  if (ultima is HttpException) throw ultima;
  throw HttpException('Não foi possível baixar o app ($ultima).');
}
