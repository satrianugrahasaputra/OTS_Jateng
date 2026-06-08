package com.ionicframework.otsjateng;

import android.annotation.SuppressLint;
import android.app.DownloadManager;
import android.content.Context;
import android.net.Uri;
import android.os.Bundle;
import android.os.Environment;
import android.view.View;
import android.webkit.WebSettings;
import android.webkit.WebView;

import androidx.appcompat.app.AppCompatActivity;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;

import com.google.android.material.appbar.MaterialToolbar;
import com.google.android.material.progressindicator.LinearProgressIndicator;
import com.ionicframework.otsjateng.utilities.classFungsi;

import java.util.Objects;

public class MaklumatActivity extends AppCompatActivity implements SwipeRefreshLayout.OnRefreshListener {

    private WebView webView;
    private SwipeRefreshLayout swipeRefreshLayout;
    private LinearProgressIndicator prgBar;
    private String strUrl;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_maklumat);

        MaterialToolbar toolbar = findViewById(R.id.toolbar);
        setSupportActionBar(toolbar);
        toolbar.setNavigationOnClickListener(v -> getOnBackPressedDispatcher().onBackPressed());

        if (getIntent().getExtras() != null) {
            strUrl = getIntent().getExtras().getString("link");
        }

        webView = findViewById(R.id.webView);
        swipeRefreshLayout = findViewById(R.id.swipe);
        prgBar = findViewById(R.id.prgBar);

        inisialisasi();
    }

    @SuppressLint("SetJavaScriptEnabled")
    private void inisialisasi() {
        prgBar.setVisibility(View.VISIBLE);

        WebSettings settings = webView.getSettings();
        settings.setLoadWithOverviewMode(true);
        settings.setBuiltInZoomControls(true);
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setLoadsImagesAutomatically(true);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW);
        settings.setUseWideViewPort(true);

        webView.setDownloadListener((url, userAgent, contentDisposition, mimeType, contentLength) -> {
            DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url));
            String strFile = Uri.parse(url).getLastPathSegment();
            request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, strFile);
            request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            DownloadManager downloadManager = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
            if (downloadManager != null) {
                downloadManager.enqueue(request);
                new classFungsi(MaklumatActivity.this, "Downloading...").TampilkanSnackBar();
            }
        });

        // Hide progress bar when page finishes loading
        webView.setWebViewClient(new android.webkit.WebViewClient() {
            @Override
            public void onPageFinished(WebView view, String url) {
                super.onPageFinished(view, url);
                prgBar.setVisibility(View.INVISIBLE);
            }
        });

        swipeRefreshLayout.setOnRefreshListener(this);

        if (strUrl != null && !strUrl.isEmpty()) {
            webView.loadUrl(strUrl);
        }
    }

    @Override
    public void onRefresh() {
        webView.reload();
        swipeRefreshLayout.setRefreshing(false);
    }
}
