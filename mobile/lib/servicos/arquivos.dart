// Salvar arquivos pelo SELETOR do Android (SAF — ACTION_CREATE_DOCUMENT):
// abre o "navegador de arquivos" do sistema (pendrive OTG, armazenamento
// interno, Downloads…) e o usuário escolhe onde gravar. Implementação nativa
// no MainActivity.kt (canal "botbtc/arquivos") — sem permissão de
// armazenamento: o SAF devolve um URI já gravável.
import 'package:flutter/services.dart';

class Arquivos {
  static const _canal = MethodChannel('botbtc/arquivos');

  /// Abre o seletor pra salvar [conteudo] como [nome]. Devolve true se
  /// gravou, false se o usuário cancelou o seletor.
  static Future<bool> salvarComo({
    required String nome,
    required String mime,
    required String conteudo,
  }) async {
    try {
      final ok = await _canal.invokeMethod<bool>('salvarComo', {
        'nome': nome,
        'mime': mime,
        'conteudo': conteudo,
      });
      return ok ?? false;
    } on PlatformException {
      return false;
    }
  }
}
