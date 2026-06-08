package com.ionicframework.otsjateng.vm;

import androidx.annotation.NonNull;
import androidx.lifecycle.LiveData;
import androidx.lifecycle.MutableLiveData;
import androidx.lifecycle.ViewModel;

import com.ionicframework.otsjateng.model.modelResponse3;
import com.ionicframework.otsjateng.model.modelResponseDashboard;
import com.ionicframework.otsjateng.model.modelResponseTahun;
import com.ionicframework.otsjateng.utilities.ApiGenerator;
import com.ionicframework.otsjateng.utilities.interfaceRetrofit;

import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;

public class inetViewModel extends ViewModel {

    private MutableLiveData<modelResponseDashboard> dashboardMutableLiveData;
    private MutableLiveData<modelResponse3> modelResponse3MutableLiveData;
    private MutableLiveData<modelResponseTahun> tahunMutableLiveData;
    private MutableLiveData<String> stringMutableLiveData;

    public void setDashboard(String lang, String url) {
        ProsesDashboard(lang, url);
    }

    public LiveData<modelResponseDashboard> getDashboard(){
        if (dashboardMutableLiveData == null) dashboardMutableLiveData = new MutableLiveData<>();
        return dashboardMutableLiveData;
    }

    private void ProsesDashboard(String lang, String url){
        interfaceRetrofit api = ApiGenerator.getInterface(url);
        Call<modelResponseDashboard> call = api.getDashboard(lang);
        call.enqueue(new Callback<modelResponseDashboard>() {
            @Override
            public void onResponse(@NonNull Call<modelResponseDashboard> call, @NonNull Response<modelResponseDashboard> response) {
                if (response.isSuccessful()){
                    dashboardMutableLiveData.setValue(response.body());
                }else {
                    dashboardMutableLiveData.setValue(new modelResponseDashboard("failed", null, null, null));
                }
            }

            @Override
            public void onFailure(@NonNull Call<modelResponseDashboard> call, @NonNull Throwable t) {
                dashboardMutableLiveData.setValue(new modelResponseDashboard("throwable: " + t.getMessage(), null, null, null));
            }
        });
    }

    public void setData3(String jenis, @NonNull String tahun, String lang, String url) {
        ProsesData3(jenis, tahun, lang, url);
    }

    public LiveData<modelResponse3> getData3(){
        if (modelResponse3MutableLiveData == null) modelResponse3MutableLiveData = new MutableLiveData<>();
        return modelResponse3MutableLiveData;
    }

    private void ProsesData3(String jenis, @NonNull String tahun, String lang, String url){
        interfaceRetrofit api = ApiGenerator.getInterface(url);
        Call<modelResponse3> call;
        if (tahun.isEmpty())call = api.getData3(jenis, lang);
        else call = api.getData3(jenis, tahun, lang);
        call.enqueue(new Callback<modelResponse3>() {
            @Override
            public void onResponse(@NonNull Call<modelResponse3> call, @NonNull Response<modelResponse3> response) {
                if (response.isSuccessful()){
                    modelResponse3MutableLiveData.setValue(response.body());
                }else {
                    modelResponse3MutableLiveData.setValue(new modelResponse3("failed", null, null, null, null, null, null));
                }
            }

            @Override
            public void onFailure(@NonNull Call<modelResponse3> call, @NonNull Throwable t) {
                modelResponse3MutableLiveData.setValue(new modelResponse3("throwable: " + t.getMessage(), null, null, null, null, null, null));
            }
        });
    }

    public void setTahun(String tabel, String url) {
        ProsesTahun(tabel, url);
    }

    public LiveData<modelResponseTahun> getTahun(){
        if (tahunMutableLiveData == null) tahunMutableLiveData = new MutableLiveData<>();
        return tahunMutableLiveData;
    }

    private void ProsesTahun(String tabel, String url){
        interfaceRetrofit api = ApiGenerator.getInterface(url);
        Call<modelResponseTahun> call = api.getTahun(tabel);
        call.enqueue(new Callback<modelResponseTahun>() {
            @Override
            public void onResponse(@NonNull Call<modelResponseTahun> call, @NonNull Response<modelResponseTahun> response) {
                if (response.isSuccessful()){
                    tahunMutableLiveData.setValue(response.body());
                }else {
                    tahunMutableLiveData.setValue(new modelResponseTahun("failed", null));
                }
            }

            @Override
            public void onFailure(@NonNull Call<modelResponseTahun> call, @NonNull Throwable t) {
                tahunMutableLiveData.setValue(new modelResponseTahun("throwable: " + t.getMessage(), null));
            }
        });
    }

    public void setDetail(String jenis, @NonNull String tahun, String lang, String url) {
        ProsesDetail(jenis, tahun, lang, url);
    }

    public LiveData<String > getDetail(){
        if (stringMutableLiveData == null) stringMutableLiveData = new MutableLiveData<>();
        return stringMutableLiveData;
    }

    private void ProsesDetail(String jenis, @NonNull String tahun, String lang, String url){
        interfaceRetrofit api = ApiGenerator.getInterface(url);
        Call<String> call;
        if (tahun.isEmpty()){
            call = api.getDetail(jenis, lang);
        }else if (tahun.contains("/")){
            call = api.getDetail(jenis, tahun.split("/")[0], tahun.split("/")[1], lang);
        }else{
            call = api.getDetail(jenis, tahun, lang);
        }
        call.enqueue(new Callback<String>() {
            @Override
            public void onResponse(@NonNull Call<String> call, @NonNull Response<String> response) {
                if (response.isSuccessful()){
                    stringMutableLiveData.setValue(response.body());
                }else {
                    stringMutableLiveData.setValue(response.message());
                }
            }

            @Override
            public void onFailure(@NonNull Call<String> call, @NonNull Throwable t) {
                stringMutableLiveData.setValue(t.getMessage());
            }
        });
    }


}
