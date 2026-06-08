package com.ionicframework.otsjateng.model;

public class modelHeader3
        extends modelData {

    private final String kolom1;
    private final String kolom2;
    private final String kolom3;

    public modelHeader3(String kolom1, String kolom2, String kolom3){
        this.kolom1 = kolom1;
        this.kolom2 = kolom2;
        this.kolom3 = kolom3;
    }

    public String getKolom1() {
        return kolom1;
    }

    public String getKolom2() {
        return kolom2;
    }

    public String getKolom3() {
        return kolom3;
    }
}
