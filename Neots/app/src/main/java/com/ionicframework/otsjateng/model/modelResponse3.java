package com.ionicframework.otsjateng.model;

import java.util.List;

public class modelResponse3 extends modelData{

    private final String status, judul, tahun, bulan, kolom, tabel;
    private final List<modelIsi3> data;

    public modelResponse3(String status, String judul, String tahun, String bulan, String kolom, String tabel, List<modelIsi3> data) {
        this.status = status;
        this.judul = judul;
        this.tahun = tahun;
        this.bulan = bulan;
        this.kolom = kolom;
        this.tabel = tabel;
        this.data = data;
    }

    public String getStatus() {
        return status;
    }

    public String getJudul() {
        return judul;
    }

    public String getTahun() {
        return tahun;
    }

    public String getBulan() {
        return bulan;
    }

    public String getKolom() {
        return kolom;
    }

    public String getTabel() {
        return tabel;
    }

    public List<modelIsi3> getData() {
        return data;
    }
}
