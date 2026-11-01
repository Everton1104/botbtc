package com.botbtc.botbtc_app

import android.content.Intent
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.io.File

class MainActivity : FlutterActivity() {
    companion object {
        private const val CANAL = "botbtc/instalador"
    }

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
    }
}
