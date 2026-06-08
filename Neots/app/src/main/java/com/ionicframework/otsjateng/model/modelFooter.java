package com.ionicframework.otsjateng.model;

public class modelFooter
        extends modelData {

    private final String string, id;

    public modelFooter(String string, String id){
        this.string = string;
        this.id = id;
    }

    public String getString() {
        return string;
    }

    public String getId() {
        return id;
    }
}
