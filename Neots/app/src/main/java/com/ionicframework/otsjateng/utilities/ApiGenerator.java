package com.ionicframework.otsjateng.utilities;

import androidx.annotation.NonNull;

import java.util.concurrent.TimeUnit;

import okhttp3.OkHttpClient;
import retrofit2.Retrofit;
import retrofit2.converter.gson.GsonConverterFactory;
import retrofit2.converter.scalars.ScalarsConverterFactory;

public class ApiGenerator {

    private static final OkHttpClient ok = new OkHttpClient.Builder()
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)
            .build();

    @NonNull
    public static interfaceRetrofit getInterface(String url){
        Retrofit retrofit = new Retrofit.Builder()
                .baseUrl(url)
                .client(ok)
                .addConverterFactory(ScalarsConverterFactory.create())
                .addConverterFactory(GsonConverterFactory.create())
                .build();
        return retrofit.create(interfaceRetrofit.class);
    }
}
