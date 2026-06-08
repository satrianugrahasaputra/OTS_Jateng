package com.ionicframework.otsjateng.model;

public class modelIsi3 extends modelData{

    private final String isi1, isi2, isi3;
    private final String negara, deskripsi, nilai, poin;

    public modelIsi3(String isi1, String isi2, String isi3, String negara, String deskripsi, String nilai, String poin) {
        this.isi1 = isi1;
        this.isi2 = isi2;
        this.isi3 = isi3;
        this.negara = negara;
        this.deskripsi = deskripsi;
        this.nilai = nilai;
        this.poin = poin;
    }

    public String getIsi1() {
        return isi1;
    }

    public String getIsi2() {
        return isi2;
    }

    public String getIsi3() {
        return isi3;
    }

    public String getNegara() {
        return negara;
    }

    public String getDeskripsi() {
        return deskripsi;
    }

    public String getNilai() {
        return nilai;
    }

    public String getPoin() {
        return poin;
    }
}
