package com.ionicframework.otsjateng;

import android.annotation.SuppressLint;
import android.app.DownloadManager;
import android.content.Context;
import android.net.Uri;
import android.os.Bundle;
import android.os.Environment;
import android.view.View;
import android.webkit.WebSettings;

import androidx.appcompat.app.AppCompatActivity;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;

import com.ionicframework.otsjateng.databinding.ActivityWebViewBinding;
import com.ionicframework.otsjateng.utilities.classFungsi;

import java.util.Objects;

public class WebViewActivity
        extends AppCompatActivity
        implements SwipeRefreshLayout.OnRefreshListener {

    ActivityWebViewBinding binding;
    String strUrl;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        binding = ActivityWebViewBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());

        strUrl = Objects.requireNonNull(getIntent().getExtras()).getString("link");
        inisialisasi();
        ViewCompat.setOnApplyWindowInsetsListener(binding.getRoot(), (v, windowInsets) -> {
            Insets insets = windowInsets.getInsets(WindowInsetsCompat.Type.systemBars());
            v.setPadding(insets.left, insets.top, insets.right, insets.bottom);
            return WindowInsetsCompat.CONSUMED;
        });

    }

    @SuppressLint("SetJavaScriptEnabled")
    private void inisialisasi() {
        binding.prgBar.setVisibility(View.VISIBLE);
        binding.webView.getSettings().setLoadWithOverviewMode(true);
        binding.webView.getSettings().setBuiltInZoomControls(true);
        binding.webView.getSettings().setJavaScriptEnabled(true);
        binding.webView.getSettings().setDomStorageEnabled(true);
        binding.webView.getSettings().setLoadsImagesAutomatically(true);
        binding.webView.getSettings().setMixedContentMode(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW);
        binding.webView.setWebViewClient(new android.webkit.WebViewClient());

        binding.webView.setDownloadListener((url, userAgent, contentDisposition, mimeType, contentLength) -> {
            DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url));
            String strFile = Uri.parse(url).getLastPathSegment();
            request.setDestinationInExternalPublicDir(Environment.DIRECTORY_PICTURES, strFile);
            request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            DownloadManager downloadManager = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
            downloadManager.enqueue(request);
            new classFungsi(WebViewActivity.this, "Downloading...").TampilkanSnackBar();
        });

        binding.swipe.setOnRefreshListener(this);
        binding.webView.loadUrl(strUrl);
    }

    @Override
    public void onRefresh() {
        binding.webView.reload();
        binding.swipe.setRefreshing(false);
    }
}