// ─────────────────────────────────────────────────────────────────────────────
// AUTO-ATUALIZAÇÃO — download do APK de dentro do app (sem site/navegador).
//
// Quem usa: TelaAtualizacao (app bloqueado por versão mínima). O APK é
// baixado em STREAM com progresso (19 MB não podem congelar a tela sem
// informação) pro cache interno do app — obtido pelo mesmo canal nativo
// (MainActivity) que depois dispara o instalador, então o arquivo nasce
// num root que o FileProvider já enxerga (res/xml/file_paths.xml).
//
// Fora da Play Store o Android nunca instala em silêncio: o usuário sempre
// vê o "Atualizar app?" do sistema. O que este serviço elimina é o resto
// (abrir navegador, logar no site, achar o arquivo baixado).
// ─────────────────────────────────────────────────────────────────────────────

import 'dart:io';

import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;

const _canalInstalador = MethodChannel('botbtc/instalador');

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
/// [progresso] é chamado a cada pedaço recebido com os BYTES recebidos e o
/// total (0 = servidor não informou o tamanho; a UI mostra barra
/// indeterminada). Em falha de rede lança HttpException com mensagem
/// amigável — quem chama decide se oferece o fallback do site.
Future<String> baixarApk(
  String url,
  void Function(int recebidos, int total) progresso,
) async {
  final cliente = http.Client();

  try {
    final resposta = await cliente.send(http.Request('GET', Uri.parse(url)));

    if (resposta.statusCode != 200) {
      throw HttpException(
          'O servidor respondeu ${resposta.statusCode} ao baixar o app.');
    }

    // Cache interno do app (root mapeado no file_paths.xml do FileProvider —
    // sem isso o instalador não leria o arquivo).
    final dir = await diretorioCache();
    final arquivo = File('$dir/app-botbtc-atualizacao.apk');
    final sink = arquivo.openWrite();

    final total = resposta.contentLength ?? 0;
    var recebido = 0;

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
  } finally {
    cliente.close();
  }
}
