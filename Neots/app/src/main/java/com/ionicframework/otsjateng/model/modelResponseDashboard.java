package com.ionicframework.otsjateng.model;

import java.util.List;

public class modelResponseDashboard {

    private final String status;
    private final modelDataImage image;
    private final modelDataImage maklumat;
    private final List<modelDataDashboard> data;

    public modelResponseDashboard(String status, modelDataImage image, modelDataImage maklumat, List<modelDataDashboard> data) {
        this.status = status;
        this.image = image;
        this.maklumat = maklumat;
        this.data = data;
    }

    public String getStatus() {
        return status;
    }

    public modelDataImage getImage() {
        return image;
    }

    public modelDataImage getMaklumat(){
        return maklumat;
    }

    public List<modelDataDashboard> getData() {
        return data;
    }
}
