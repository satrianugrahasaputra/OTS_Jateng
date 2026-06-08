package com.ionicframework.otsjateng.model;

/**
 * Model class for Statistical Indicator
 */
public class StatIndicator {
    private String id;
    private String name;
    private String value;
    private String unit;
    private int iconResId;
    private int iconBackgroundResId;

    public StatIndicator() {
    }

    public StatIndicator(String id, String name, String value, String unit, int iconResId, int iconBackgroundResId) {
        this.id = id;
        this.name = name;
        this.value = value;
        this.unit = unit;
        this.iconResId = iconResId;
        this.iconBackgroundResId = iconBackgroundResId;
    }

    // Getters
    public String getId() {
        return id;
    }

    public String getName() {
        return name;
    }

    public String getValue() {
        return value;
    }

    public String getUnit() {
        return unit;
    }

    public int getIconResId() {
        return iconResId;
    }

    public int getIconBackgroundResId() {
        return iconBackgroundResId;
    }

    // Setters
    public void setId(String id) {
        this.id = id;
    }

    public void setName(String name) {
        this.name = name;
    }

    public void setValue(String value) {
        this.value = value;
    }

    public void setUnit(String unit) {
        this.unit = unit;
    }

    public void setIconResId(int iconResId) {
        this.iconResId = iconResId;
    }

    public void setIconBackgroundResId(int iconBackgroundResId) {
        this.iconBackgroundResId = iconBackgroundResId;
    }
}
