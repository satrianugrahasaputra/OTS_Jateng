package com.ionicframework.otsjateng.utilities

import com.ionicframework.otsjateng.model.InflasiResponse
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory

class InflasiRepository(private val baseUrl: String) {

    private val api: InflasiApiService by lazy {
        Retrofit.Builder()
            .baseUrl(baseUrl) // Use the base URL passed from the Activity/VM logic
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(InflasiApiService::class.java)
    }

    suspend fun getInflasiSeries(): InflasiResponse? {
        return try {
            val response = api.getInflasiSeries()
            if (response.isSuccessful) {
                response.body()
            } else {
                null
            }
        } catch (e: Exception) {
            null
        }
    }
}
