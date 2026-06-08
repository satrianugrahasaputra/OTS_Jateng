package com.ionicframework.otsjateng.model;

import java.util.List;

public class modelResponseTahun {

    private final String status;

    private final List<modelTahun> data;

    public modelResponseTahun(String status, List<modelTahun> data) {
        this.status = status;
        this.data = data;
    }

    public String getStatus() {
        return status;
    }

    public List<modelTahun> getData() {
        return data;
    }
}
