package com.ionicframework.otsjateng.utilities;

import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.graphics.Color;
import android.net.Uri;
import android.os.Environment;
import android.view.View;

import androidx.annotation.NonNull;

import com.google.android.material.snackbar.Snackbar;
import com.ionicframework.otsjateng.BuildConfig;
import com.ionicframework.otsjateng.R;

import java.io.File;

public class classFungsi {

    private String strPesan, strUrlFile, strExt;
    private final Context mContext;
    private File newFile;

    public classFungsi (Context context, String strPesan){
        mContext = context;
        this.strPesan = strPesan;
    }

    public classFungsi (Context context, String strUrlFile, String strId, @NonNull String strExt){
        this.mContext = context;
        this.strUrlFile = strUrlFile;
        this.strExt = strExt;
        switch (strExt) {
            case ".png":
                newFile = new File(Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_PICTURES) + "/", strId + strExt);
                break;
            case ".jpg":
                newFile = new File(Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DCIM) + "/", strId + strExt);
                break;
            default:
                newFile = new File(Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS) + "/Ots/", strId + strExt);
                break;
        }
    }

    public void TampilkanSnackBar(){
        View view = ((Activity)mContext).getWindow().getDecorView().findViewById(android.R.id.content);
        Snackbar snackbar = Snackbar.make(view, strPesan, Snackbar.LENGTH_SHORT);
        snackbar.show();
    }

    public void TampilkanSnackBarOpenFile(){
        View view = ((Activity)mContext).getWindow().getDecorView().findViewById(android.R.id.content);
        Snackbar snackbar = Snackbar
                .make(view, strUrlFile, 8000)
                .setActionTextColor(Color.GREEN)
                .setAction(R.string.buka, view1 -> classFungsi.this.BukaFile());
        snackbar.show();
    }

    private void BukaFile(){
        Uri uri;
        Intent intent = new Intent(Intent.ACTION_VIEW);
        if (strExt.equals(".xls")) {
            uri = FileProvider.getUriForFile(mContext, BuildConfig.APPLICATION_ID + ".fileprovider", newFile);
            intent.setDataAndType(uri, "application/vnd.ms-excel");
        }
        intent.setFlags(Intent.FLAG_ACTIVITY_NO_HISTORY);
        intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
        mContext.startActivity(intent);
    }
}
