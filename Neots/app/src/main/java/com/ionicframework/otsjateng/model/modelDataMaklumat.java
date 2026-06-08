package com.ionicframework.otsjateng.model;

public class modelDataMaklumat extends modelData {

    private final String image, link;

    public modelDataMaklumat(String image, String link) {
        this.image = image;
        this.link = link;
    }

    public String getImage() {
        return image;
    }

    public String getLink() {
        return link;
    }
}
