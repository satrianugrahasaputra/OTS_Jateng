package com.ionicframework.otsjateng.model

import com.google.gson.annotations.SerializedName

data class InflasiResponse(
    @SerializedName("status")
    val status: String,
    @SerializedName("data")
    val data: List<InflasiData>
)

data class InflasiData(
    @SerializedName("periode")
    val periode: String,
    @SerializedName("nilai")
    val nilai: Double
)
