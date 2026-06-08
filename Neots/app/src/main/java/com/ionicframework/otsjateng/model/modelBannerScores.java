package com.ionicframework.otsjateng.model;

public class modelBannerScores extends modelData {
    private final String ipkpScore;
    private final String ipkpStatus;
    private final String ipakScore;
    private final String ipakStatus;
    private final String period;

    public modelBannerScores(String ipkpScore, String ipkpStatus, String ipakScore, String ipakStatus, String period) {
        this.ipkpScore = ipkpScore;
        this.ipkpStatus = ipkpStatus;
        this.ipakScore = ipakScore;
        this.ipakStatus = ipakStatus;
        this.period = period;
    }

    public String getIpkpScore() {
        return ipkpScore;
    }

    public String getIpkpStatus() {
        return ipkpStatus;
    }

    public String getIpakScore() {
        return ipakScore;
    }

    public String getIpakStatus() {
        return ipakStatus;
    }

    public String getPeriod() {
        return period;
    }
}
