package com.ionicframework.otsjateng.model;

public class modelDataDashboard
        extends modelData {

    private final String id, bulan, tahun, nilai, tanda, poin, sebelumnya, satuan, delta, lang;
    private String indikator;

    public modelDataDashboard(String id, String indikator, String bulan, String tahun, String nilai, String tanda,
            String poin, String sebelumnya, String satuan, String delta, String lang) {
        this.id = id;
        this.indikator = indikator;
        this.bulan = bulan;
        this.tahun = tahun;
        this.nilai = nilai;
        this.tanda = tanda;
        this.poin = poin;
        this.sebelumnya = sebelumnya;
        this.satuan = satuan;
        this.delta = delta;
        this.lang = lang;
    }

    public String getId() {
        return id;
    }

    public String getIndikator() {
        return indikator;
    }

    public String getPeriode() {
        return bulan;
    }

    public String getTahun() {
        return tahun;
    }

    public String getNilai() {
        return nilai;
    }

    public String getTanda() {
        return tanda;
    }

    public String getPoin() {
        return poin;
    }

    public String getSebelumnya() {
        return sebelumnya;
    }

    public String getSatuan() {
        return satuan;
    }

    public String getDelta() {
        return delta;
    }

    public String getLang() {
        return lang;
    }

    public void setIndikator(String indikator) {
        this.indikator = indikator;
    }
}
