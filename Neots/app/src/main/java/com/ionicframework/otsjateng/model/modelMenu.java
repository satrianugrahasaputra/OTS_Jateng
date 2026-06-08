package com.ionicframework.otsjateng.model;

public class modelMenu
        extends modelData {

    private final String idMenu, namaMenu, strlang;

    public modelMenu(String idMenu, String namaMenu, String strLang){
        this.idMenu = idMenu;
        this.namaMenu = namaMenu;
        this.strlang = strLang;
    }

    public String getIdMenu() {
        return idMenu;
    }

    public String getNamaMenu() {
        return namaMenu;
    }

    public String getStrlang() {
        return strlang;
    }
}
