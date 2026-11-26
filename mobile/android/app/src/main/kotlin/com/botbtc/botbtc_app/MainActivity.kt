package com.botbtc.botbtc_app

import android.content.Intent
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.io.File

class MainActivity : FlutterActivity() {
    companion object {
        private const val CANAL = "botbtc/instalador"
        private const val CANAL_ARQ = "botbtc/arquivos"
        private const val REQ_SALVAR = 4711
    }

    // "Salvar como" (SAF): conteúdo pendente + resultado do canal enquanto o
    // seletor de armazenamento (pendrive/pasta interna) está aberto.
    private var salvarConteudo: String? = null
    private var salvarResult: MethodChannel.Result? = null

    override fun configureFlutterEngine(engine: FlutterEngine) {
        super.configureFlutterEngine(engine)

        MethodChannel(engine.dartExecutor.binaryMessenger, CANAL)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    // Onde salvar o APK baixado: o cache INTERNO do app é um
                    // dos roots mapeados no file_paths.xml do FileProvider —
                    // pré-requisito pro instalador ler o arquivo.
                    "cacheDir" -> result.success(cacheDir.absolutePath)

                    // Dispara o instalador do sistema sobre o APK baixado
                    // (install/update por cima, mesma assinatura). O Android
                    // cuida do resto: permissão "desta fonte" na 1ª vez e o
                    // diálogo "Atualizar app?".
                    "instalarApk" -> {
                        val path = call.argument<String>("path")
                        if (path == null) {
                            result.error("sem_path", "Caminho do APK ausente", null)
                            return@setMethodCallHandler
                        }
                        try {
                            val uri = androidx.core.content.FileProvider.getUriForFile(
                                this, "$packageName.fileprovider", File(path)
                            )
                            val intent = Intent(Intent.ACTION_VIEW).apply {
                                setDataAndType(uri, "application/vnd.android.package-archive")
                                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                            }
                            startActivity(intent)
                            result.success(true)
                        } catch (e: Exception) {
                            result.error("falhou", e.message, null)
                        }
                    }

                    else -> result.notImplemented()
                }
            }

        // Salvar arquivo via SELETOR do sistema (ACTION_CREATE_DOCUMENT): o
        // usuário escolhe pendrive/armazenamento interno e o nome final. Sem
        // permissão de armazenamento — o SAF entrega um URI gravável.
        MethodChannel(engine.dartExecutor.binaryMessenger, CANAL_ARQ)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "salvarComo" -> {
                        val nome = call.argument<String>("nome") ?: "arquivo.txt"
                        val mime = call.argument<String>("mime") ?: "application/octet-stream"
                        val conteudo = call.argument<String>("conteudo")
                        if (conteudo == null) {
                            result.error("sem_conteudo", "Conteúdo ausente", null)
                            return@setMethodCallHandler
                        }
                        salvarConteudo = conteudo
                        salvarResult = result
                        try {
                            val intent = Intent(Intent.ACTION_CREATE_DOCUMENT).apply {
                                addCategory(Intent.CATEGORY_OPENABLE)
                                setType(mime)
                                putExtra(Intent.EXTRA_TITLE, nome)
                            }
                            startActivityForResult(intent, REQ_SALVAR)
                        } catch (e: Exception) {
                            salvarConteudo = null
                            salvarResult = null
                            result.error("falhou", e.message, null)
                        }
                    }
                    else -> result.notImplemented()
                }
            }
    }

    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode != REQ_SALVAR) return
        val result = salvarResult
        val conteudo = salvarConteudo
        salvarResult = null
        salvarConteudo = null
        if (result == null) return
        val uri = data?.data
        if (resultCode != RESULT_OK || uri == null) {
            result.success(false) // usuário cancelou o seletor
            return
        }
        try {
            contentResolver.openOutputStream(uri, "wt")?.use { out ->
                out.write(conteudo?.toByteArray(Charsets.UTF_8) ?: ByteArray(0))
            }
            result.success(true)
        } catch (e: Exception) {
            result.error("falhou", e.message, null)
        }
    }
}
