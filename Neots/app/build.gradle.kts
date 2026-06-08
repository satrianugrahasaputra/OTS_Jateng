plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.google.gms.google.services)
}

android {
    namespace = "com.ionicframework.otsjateng"
    compileSdk = 36

    defaultConfig {
        applicationId = "com.ionicframework.otsjateng"
        minSdk = 26
        targetSdk = 36
        versionCode = 251238
        versionName = "251238"
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_1_8
        targetCompatibility = JavaVersion.VERSION_1_8
    }

    buildFeatures {
        viewBinding = true
        buildConfig = true
    }
}

dependencies {

    implementation(libs.appcompat)
    implementation(libs.material)
    implementation(libs.activity)
    implementation(libs.constraintlayout)
    implementation(libs.poi) //java 7

    implementation (libs.retrofit)
    implementation (libs.converter.gson)
    implementation (libs.converter.scalars)

    implementation(platform(libs.firebase.bom))
    implementation(libs.firebase.analytics)
    implementation(libs.firebase.database)
    implementation(libs.app.update)
    implementation(libs.swiperefreshlayout)
    implementation(libs.mpandroidchart)



}