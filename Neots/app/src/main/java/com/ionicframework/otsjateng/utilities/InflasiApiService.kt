package com.ionicframework.otsjateng.utilities

import com.ionicframework.otsjateng.model.InflasiResponse
import retrofit2.Response
import retrofit2.http.GET

interface InflasiApiService {
    @GET("api/inflasi/series")
    suspend fun getInflasiSeries(): Response<InflasiResponse>
}
