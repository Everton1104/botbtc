import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
    // Lê o google-services.json e injeta as credenciais do Firebase na build.
    id("com.google.gms.google-services")
}

android {
    namespace = "com.botbtc.botbtc_app"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
        // "Desugaring": o flutter_local_notifications usa APIs de data/hora
        // que só existem em Android novo; isto as reescreve pra funcionar
        // também nos antigos. Sem esta linha, a build falha em
        // checkDebugAarMetadata.
        isCoreLibraryDesugaringEnabled = true
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "com.botbtc.botbtc_app"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        // Uses the version code from pubspec.yaml. When using split APKs, 1000 * ABI_VERSION
        // is added automatically by Flutter. (https://developer.android.com/studio/build/configure-apk-splits#configure-APK-versions)
        // You can force using the value of versionCode by specifying the `-P force-version-code-ignoring-abi=true`
        // flag during build.
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    buildTypes {
        release {
            // ASSINATURA DE RELEASE FIXA (key.properties + botbtc-release.jks,
            // ambos fora do git): a keystore é a identidade permanente do app
            // — o Android só aceita atualização por cima de APK com a MESMA
            // assinatura. Antes de 23/11 o release usava a debug key do
            // ambiente (TODO do template), então cada máquina/proot gerava
            // um APK incompatível com o instalado ("App não instalado").
            // Fallback: sem key.properties (build em outra máquina), assina
            // com a debug key como antes — o APK saído daqui NÃO publicar.
            val keystoreProps = Properties()
            val keystoreFile = rootProject.file("key.properties")
            if (keystoreFile.exists()) {
                keystoreProps.load(keystoreFile.inputStream())
                signingConfigs {
                    create("botbtcRelease") {
                        storeFile = file(keystoreProps["storeFile"] as String)
                        storePassword = keystoreProps["storePassword"] as String
                        keyAlias = keystoreProps["keyAlias"] as String
                        keyPassword = keystoreProps["keyPassword"] as String
                    }
                }
                signingConfig = signingConfigs.getByName("botbtcRelease")
            } else {
                signingConfig = signingConfigs.getByName("debug")
            }
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

dependencies {
    // A biblioteca que o desugaring (acima) usa por baixo dos panos.
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
}

flutter {
    source = "../.."
}
