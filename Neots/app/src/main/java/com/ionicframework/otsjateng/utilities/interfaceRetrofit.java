package com.ionicframework.otsjateng.utilities;

import com.ionicframework.otsjateng.model.modelResponse3;
import com.ionicframework.otsjateng.model.modelResponseDashboard;
import com.ionicframework.otsjateng.model.modelResponseTahun;

import retrofit2.Call;
import retrofit2.http.GET;
import retrofit2.http.Path;

public interface interfaceRetrofit {

    //GET
    @GET("dashboard3/{lang}")
    Call<modelResponseDashboard> getDashboard(@Path("lang") String lang);
    @GET("{jenis}/{lang}")
    Call<modelResponse3> getData3(@Path("jenis") String jenis, @Path("lang") String lang);
    @GET("{jenis}/{tahun}/{lang}")
    Call<modelResponse3> getData3(@Path("jenis") String jenis, @Path("tahun") String tahun, @Path("lang") String lang);
    @GET("tahun/{tabel}")
    Call<modelResponseTahun> getTahun(@Path("tabel") String tabel);

    @GET("{jenis}/{bulan}/{tahun}/{lang}")
    Call<String> getDetail(@Path("jenis") String jenis, @Path("bulan") String bulan,@Path("tahun") String tahun, @Path("lang") String lang);
    @GET("{jenis}/{tahun}/{lang}")
    Call<String> getDetail(@Path("jenis") String jenis, @Path("tahun") String tahun, @Path("lang") String lang);
    @GET("{jenis}/{lang}")
    Call<String> getDetail(@Path("jenis") String jenis, @Path("lang") String lang);


}
