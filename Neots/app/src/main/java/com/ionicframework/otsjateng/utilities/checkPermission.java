package com.ionicframework.otsjateng.utilities;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.Context;
import android.content.DialogInterface;
import android.content.pm.PackageManager;

import androidx.annotation.NonNull;
import androidx.core.app.ActivityCompat;

import java.util.ArrayList;
import java.util.List;

public class checkPermission {

    public static final int REQUEST_CODE_PERMISSIONS = 100;


    /**
     * Check if multiple permissions are granted, if not request them.
     *
     * @param activity    calling activity which needs permissions.
     * @param permissions one or more permissions, such as {@link android.Manifest.permission#CAMERA}.
     */
    public static void checkAndRequestPermissions(Activity activity, @NonNull String... permissions) {

        List<String> permissionsList = new ArrayList<>();
        for (String permission : permissions) {
            int permissionState = activity.checkSelfPermission(permission);
            if (permissionState == PackageManager.PERMISSION_DENIED) {
                permissionsList.add(permission);
            }
        }
        if (!permissionsList.isEmpty()) {
            ActivityCompat.requestPermissions(activity,
                    permissionsList.toArray(new String[0]),
                    REQUEST_CODE_PERMISSIONS);
        }

    }


    /**
     * Handle the result of permission request, should be called from the calling {@link Activity}'s
     * {@link ActivityCompat.OnRequestPermissionsResultCallback#onRequestPermissionsResult(int, String[], int[])}
     *
     * @param activity calling activity which needs permissions.
     * @param requestCode code used for requesting permission.
     * @param permissions permissions which were requested.
     * @param grantResults results of request.
     * @param callBack Callback interface to receive the result of permission request.
     */
    public static void onRequestPermissionsResult(final Activity activity, int requestCode, @NonNull String[] permissions, @NonNull int[] grantResults, final PermissionsCallBack callBack) {
        if (requestCode == checkPermission.REQUEST_CODE_PERMISSIONS && grantResults.length > 0) {

            final List<String> permissionsList = new ArrayList<>();
            for (int i = 0; i < permissions.length; i++) {
                if (grantResults[i] == PackageManager.PERMISSION_DENIED) {
                    permissionsList.add(permissions[i]);
                }
            }

            if (permissionsList.isEmpty() && callBack != null) {
                callBack.permissionsGranted();
            } else {
                boolean showRationale = false;
                for (String permission : permissionsList) {
                    if (ActivityCompat.shouldShowRequestPermissionRationale(activity, permission)) {
                        showRationale = true;
                        break;
                    }
                }

                if (showRationale) {
                    showAlertDialog(activity, (dialogInterface, i) -> checkAndRequestPermissions(activity, permissionsList.toArray(new String[0])), (dialogInterface, i) -> {
                        if (callBack != null) {
                            callBack.permissionsDenied();
                        }
                    });
                }
            }
        }
    }

    /**
     * Show alert if any permission is denied and ask again for it.
     */
    private static void showAlertDialog(Context context,
                                        DialogInterface.OnClickListener okListener,
                                        DialogInterface.OnClickListener cancelListener) {

        new AlertDialog.Builder(context)
                .setMessage("Some permissions are not granted. Application may not work as expected. Do you want to grant them?")
                .setPositiveButton("OK", okListener)
                .setNegativeButton("Cancel", cancelListener)
                .create()
                .show();
    }

    public interface PermissionsCallBack {
        void permissionsGranted();
        void permissionsDenied();
    }
}