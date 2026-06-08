package com.ionicframework.otsjateng.model;

public class modelPublikasi
            extends modelData{

    private final  String pub_id, title, issn, sch_date, rl_date, updt_date, cover, pdf, size;

    public modelPublikasi(String pub_id, String title, String issn, String sch_date, String rl_date, String updt_date, String cover, String pdf, String size){
        this.pub_id = pub_id;
        this.title = title;
        this.issn = issn;
        this.sch_date = sch_date;
        this.rl_date = rl_date;
        this.updt_date = updt_date;
        this.cover = cover;
        this.pdf = pdf;
        this.size = size;
    }

    public String getPub_id() {
        return pub_id;
    }

    public String getTitle() {
        return title;
    }

    public String getIssn() {
        return issn;
    }

    public String getSch_date() {
        return sch_date;
    }

    public String getRl_date() {
        return rl_date;
    }

    public String getUpdt_date() {
        return updt_date;
    }

    public String getCover() {
        return cover;
    }

    public String getPdf() {
        return pdf;
    }

    public String getSize() {
        return size;
    }
}
