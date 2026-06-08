package com.ionicframework.otsjateng;

import android.Manifest;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.view.Menu;
import android.view.MenuItem;
import android.view.View;
import android.widget.Button;
import android.widget.TextView;
import android.widget.Toast;
import android.view.ViewGroup;
import android.annotation.SuppressLint;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.app.ActivityCompat;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.lifecycle.ViewModelProvider;

import com.ionicframework.otsjateng.databinding.ActivityDetailKontenBinding;
import com.ionicframework.otsjateng.utilities.classFungsi;
import com.ionicframework.otsjateng.vm.inetViewModel;

import org.apache.poi.hssf.usermodel.HSSFRow;
import org.apache.poi.hssf.usermodel.HSSFSheet;
import org.apache.poi.hssf.usermodel.HSSFWorkbook;
import org.apache.poi.ss.util.CellRangeAddress;
import org.json.JSONArray;
import org.json.JSONObject;

import java.io.File;
import java.io.FileOutputStream;
import java.util.ArrayList;
import java.util.Calendar;
import java.util.List;
import java.util.Objects;
import android.graphics.Color;

import com.github.mikephil.charting.charts.LineChart;
import com.github.mikephil.charting.components.XAxis;
import com.github.mikephil.charting.components.YAxis;
import com.github.mikephil.charting.data.Entry;
import com.github.mikephil.charting.data.LineData;
import com.github.mikephil.charting.data.LineDataSet;
import com.github.mikephil.charting.formatter.IndexAxisValueFormatter;

public class DetailKonten extends AppCompatActivity {

    private ActivityDetailKontenBinding binding;
    private boolean isInitialYearSet = false;
    private inetViewModel viewModel;
    private String strMenu, strTabelDB, strJudul, strLang, strUrl, strTahun;
    private String[][] listIsi;
    private String[] judul1, kolom, kolom1;

    private int x, jmlKolom, rowspan, colspan, intTengah;

    final ActivityResultLauncher<Intent> startTahun = registerForActivityResult(
            new ActivityResultContracts.StartActivityForResult(), result -> {
                if (result.getResultCode() == RESULT_OK) {
                    binding.prgBar.setVisibility(View.VISIBLE);
                    binding.wvKonten.loadUrl("about:blank");
                    String strHasil = Objects.requireNonNull(result.getData()).getStringExtra("tahun");
                    if (strMenu.equals("ekspor_komoditas") || strMenu.equals("impor_komoditas")) {
                        strHasil = result.getData().getStringExtra("bulan") + "/" + strHasil;
                    }
                    ProsesAsync(strHasil);
                }
            });

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        binding = ActivityDetailKontenBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());

        // Force root background to black in Dark Mode
        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.Q) {
            boolean isDarkMode = (getResources().getConfiguration().uiMode
                    & android.content.res.Configuration.UI_MODE_NIGHT_MASK) == android.content.res.Configuration.UI_MODE_NIGHT_YES;
            if (isDarkMode) {
                binding.getRoot().setBackgroundColor(Color.BLACK);
                getWindow().getDecorView().setBackgroundColor(Color.BLACK); // Cover full window
                binding.wvKonten.setBackgroundColor(Color.TRANSPARENT); // Ensure WebView doesn't block
            }
        }

        inisialisasi();
        inisialisasiViewModel();

        // Use current year as starting point for server fallback
        int currentYear = java.util.Calendar.getInstance().get(java.util.Calendar.YEAR);
        strTahun = String.valueOf(currentYear);

        if (strMenu.equals("ekspor_komoditas") || strMenu.equals("impor_komoditas"))
            strTahun = currentYear + "/" + currentYear;

        if (getSupportActionBar() != null) {
            getSupportActionBar().hide();
        }

        ProsesAsync(strTahun);
        ViewCompat.setOnApplyWindowInsetsListener(binding.headerDetail, (v, windowInsets) -> {
            Insets insets = windowInsets.getInsets(WindowInsetsCompat.Type.systemBars());
            v.setPadding(v.getPaddingLeft(), insets.top, v.getPaddingRight(), v.getPaddingBottom());
            return WindowInsetsCompat.CONSUMED;
        });

    }

    private void inisialisasi() {
        binding.prgBar.setVisibility(View.VISIBLE);
        binding.wvKonten.getSettings().setLoadWithOverviewMode(false);
        binding.wvKonten.getSettings().setUseWideViewPort(false);
        binding.wvKonten.getSettings().setBuiltInZoomControls(false);
        binding.wvKonten.getSettings().setDisplayZoomControls(false);
        binding.wvKonten.getSettings().setJavaScriptEnabled(true);

        // Enable dark mode support for WebView
        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.Q) {
            binding.wvKonten.getSettings().setForceDark(android.webkit.WebSettings.FORCE_DARK_AUTO);
        }

        Bundle extra = getIntent().getExtras();
        strMenu = Objects.requireNonNull(extra).getString("menu");
        strLang = extra.getString("lang");

        SharedPreferences preferences = this.getSharedPreferences("link", MODE_PRIVATE);
        strUrl = preferences.getString("link", "");

        // Perbaikan Scroll WebView agar tidak konflik dengan NestedScrollView parent
        binding.wvKonten.setOnTouchListener(new View.OnTouchListener() {
            @SuppressLint("ClickableViewAccessibility")
            @Override
            public boolean onTouch(View v, android.view.MotionEvent event) {
                switch (event.getAction()) {
                    case android.view.MotionEvent.ACTION_DOWN:
                    case android.view.MotionEvent.ACTION_MOVE:
                        v.getParent().requestDisallowInterceptTouchEvent(true);
                        break;
                    case android.view.MotionEvent.ACTION_UP:
                    case android.view.MotionEvent.ACTION_CANCEL:
                        v.getParent().requestDisallowInterceptTouchEvent(false);
                        break;
                }
                return false;
            }
        });

        setupHeaderActions();
    }

    // Variable to store available years
    private List<com.ionicframework.otsjateng.model.modelTahun> listTahun;

    private void inisialisasiViewModel() {
        viewModel = new ViewModelProvider(DetailKonten.this).get(inetViewModel.class);
        viewModel.getDetail().observe(this, this::processFinish);

        // Observe Tahun Data
        viewModel.getTahun().observe(this, modelResponseTahun -> {
            if (modelResponseTahun.getData() != null && !modelResponseTahun.getData().isEmpty()) {
                listTahun = modelResponseTahun.getData();
                if (!isInitialYearSet) {
                    strTahun = listTahun.get(0).getTahun();
                    binding.btnYearSelector.setText(strTahun + " ▼");
                    isInitialYearSet = true;
                }
            }
        });

        // Trigger fetch if needed (reusing logic from tahunActivity)
        if (strTabelDB != null && !strTabelDB.isEmpty()) {
            viewModel.setTahun(strTabelDB, strUrl);
        }
    }

    private void ProsesAsync(String strTahun) {
        if (strMenu.equals("inflasi_prov_series") || strMenu.equals("inflasi_kelompok")
                || strMenu.equals("inflasi_penyumbang")
                || strMenu.equals("inflasi_6_kota") ||
                strMenu.equals("inflasi_ibu_kota") || strMenu.equals("ntp_prov") || strMenu.equals("ntp_penyumbang")
                || strMenu.equals("ntp_prov_jawa") ||
                strMenu.equals("ntup") || strMenu.equals("ntp_series") || strMenu.equals("ekspor_negara")
                || strMenu.equals("ekspor_komoditas") ||
                strMenu.equals("ekspor_pertumbuhan") || strMenu.equals("ekspor_migas") || strMenu.equals("impor_negara")
                || strMenu.equals("impor_komoditas") || strMenu.equals("impor_pertumbuhan") ||
                strMenu.equals("impor_migas") || strMenu.equals("neraca") || strMenu.equals("pdrb_lu_nominal")
                || strMenu.equals("pdrb_lu_pertumbuhan") ||
                strMenu.equals("pdrb_lu_distribusi") || strMenu.equals("pdrb_lu_sumber")
                || strMenu.equals("pdrb_pengeluaran_nominal") ||
                strMenu.equals("pdrb_pengeluaran_pertumbuhan") || strMenu.equals("pdrb_pengeluaran_distribusi")
                || strMenu.equals("pdrb_pengeluaran_sumber") ||
                strMenu.contains("pdrb_kab_") || strMenu.contains("miskin") || strMenu.equals("gini_ratio_prov")
                || strMenu.equals("tpak_prov") ||
                strMenu.contains("naker") || strMenu.equals("tpak_kab") || strMenu.equals("ipm_komponen_kab")
                || strMenu.equals("skd_kab") || strMenu.equals("skd_kab_smt") || strMenu.equals("skd_kab_ann")) {
            viewModel.setDetail(strMenu, strTahun, strLang, strUrl);
        } else {
            viewModel.setDetail(strMenu, "", strLang, strUrl);
        }
    }

    private void setupHeaderActions() {
        // Back Button
        binding.btnBack.setOnClickListener(v -> getOnBackPressedDispatcher().onBackPressed());

        // Default Visibility
        binding.btnExcel.setVisibility(View.GONE);
        binding.btnShare.setVisibility(View.GONE);
        binding.btnYearSelector.setVisibility(View.GONE);

        // Excel
        binding.btnExcel.setVisibility(View.VISIBLE);
        binding.btnExcel.setOnClickListener(v -> {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                createExcel();
            } else if (checkSelfPermission(
                    Manifest.permission.WRITE_EXTERNAL_STORAGE) == PackageManager.PERMISSION_GRANTED) {
                createExcel();
            } else {
                ActivityCompat.requestPermissions(DetailKonten.this,
                        new String[] { Manifest.permission.WRITE_EXTERNAL_STORAGE }, 1);
            }
        });

        // Share
        binding.btnShare.setVisibility(View.VISIBLE);
        binding.btnShare.setOnClickListener(v -> shareData());

        // Year Selector
        if (!strMenu.equals("ipm_status_series") && !strMenu.equals("rpjpn")) {
            binding.btnYearSelector.setVisibility(View.VISIBLE);

            int currentYear = Calendar.getInstance().get(Calendar.YEAR);
            // Try to parse current showing year if possible, else default
            binding.btnYearSelector.setText(currentYear + " ▼");

            binding.btnYearSelector.setOnClickListener(v -> {
                // Special case for Exim (Month + Year) -> Keep using Activity for now or custom
                // dialog
                if (strMenu.equals("ekspor_komoditas") || strMenu.equals("impor_komoditas")) {
                    Intent intent = new Intent(DetailKonten.this, tahunActivity.class);
                    intent.putExtra("tabel", strTabelDB);
                    intent.putExtra("menu", strMenu);
                    intent.putExtra("lang", strLang);
                    startTahun.launch(intent);
                } else {
                    // Modern Bottom Sheet for Year Only
                    showYearPickerBottomSheet();
                }
            });
        }
    }

    private void showYearPickerBottomSheet() {
        if (listTahun == null || listTahun.isEmpty()) {
            // Data not ready, try fetching or show message
            if (strTabelDB != null)
                viewModel.setTahun(strTabelDB, strUrl);
            Toast.makeText(this, "Memuat data tahun...", Toast.LENGTH_SHORT).show();
            return;
        }

        com.google.android.material.bottomsheet.BottomSheetDialog bottomSheetDialog = new com.google.android.material.bottomsheet.BottomSheetDialog(
                this);

        // Simple List Layout
        android.widget.ListView listView = new android.widget.ListView(this);
        // Create String array
        String[] years = new String[listTahun.size()];
        for (int i = 0; i < listTahun.size(); i++) {
            years[i] = listTahun.get(i).getTahun();
        }

        android.widget.ArrayAdapter<String> adapter = new android.widget.ArrayAdapter<>(
                this, android.R.layout.simple_list_item_1, years);
        listView.setAdapter(adapter);

        listView.setOnItemClickListener((parent, view, position, id) -> {
            strTahun = years[position];
            binding.btnYearSelector.setText(strTahun + " ▼");
            isInitialYearSet = true; // Prevent observer from overwriting
            
            // Refresh Data
            binding.prgBar.setVisibility(View.VISIBLE);
            binding.wvKonten.loadUrl("about:blank");
            ProsesAsync(strTahun);

            bottomSheetDialog.dismiss();
        });

        bottomSheetDialog.setContentView(listView);
        bottomSheetDialog.show();
    }

    // Removed showPopupMenu and previous Menu logic completely
    /*
     * @Override
     * public boolean onCreateOptionsMenu(Menu menu) {
     * // ... Removed ...
     * }
     * 
     * @Override
     * public boolean onOptionsItemSelected(@NonNull MenuItem item) {
     * // ... Removed ...
     * }
     */

    /*
     * @Override
     * public boolean onOptionsItemSelected(@NonNull MenuItem item) {
     * // ... Removed ...
     * }
     */    private void shareData() {
        if (listIsi == null) {
            new classFungsi(DetailKonten.this, "Data belum tersedia").TampilkanSnackBar();
        } else {
            try {
                // INJECT DEBUG LOGS
                android.util.Log.e("SHARE_DEBUG", "----- START SHARE DATA FOR: " + strMenu + " -----");
                android.util.Log.e("SHARE_DEBUG", "KOLOM (" + kolom.length + "): " + java.util.Arrays.toString(kolom));
                for(int r = 0; r < listIsi.length; r++) {
                    android.util.Log.e("SHARE_DEBUG", "ROW " + r + " (" + listIsi[r].length + "): " + java.util.Arrays.toString(listIsi[r]));
                }
                
                StringBuilder caption = new StringBuilder();

                // Headline
                caption.append("\uD83D\uDCCA *Update ").append(strJudul).append("!*\n\n");

                // Body
                // Check if Transposed Series Layout (similar to logic in processFinish)
                // 1) Determine logic type
                boolean isNarrative = strMenu.equals("inflasi_prov_series");
                boolean isRowBased = false;

                switch (strMenu) {
                    case "miskin_prov":
                    case "gini_ratio_prov_series":
                    case "tpak_prov":
                    case "naker_lu_jk":
                    case "naker_lu_wilayah":
                    case "naker_formal_prov":
                    case "naker_pendidikan_prov":
                    case "naker_setengah_prov":
                        isRowBased = true;
                        // Assuming loadHtmlData() would be here if this was processFinish
                        // loadHtmlData();
                }

                // This catch block is from the instruction's context, but it's not in the provided shareData method.
                // Since the instruction explicitly states "in the catch block of processFinish",
                // and processFinish is not provided, I cannot place it.
                // The instruction's "Code Edit" snippet seems to be from a different method.
                // I will proceed by assuming the user wants to add this to the *existing* catch block in shareData,
                // as it's the only one present in the provided document.
                // If the user meant a different method, the provided document is incomplete for that.

                // 2) Build Content
                if (isNarrative) {
                    String period = "-";
                    String valMtm = "-", valYoy = "-", valCal = "-";

                    if (listIsi != null && listIsi.length > 0) {
                        int validRow = -1;
                        
                        // Find the latest ROW (month) that actually has MTM data
                        for (int r = listIsi.length - 1; r >= 0; r--) {
                            boolean hasMtmData = false;
                            if (listIsi[r] != null) {
                                for (int c = 0; c < listIsi[r].length; c++) {
                                    if (listIsi[r][c] != null) {
                                        String[] parts = listIsi[r][c].split("mufti");
                                        if (parts.length > 1) {
                                            String label = parts[0].toLowerCase();
                                            String val = parts[1].trim();
                                            if ((label.contains("month") || label.contains("mtm") || label.contains("bulan")) && 
                                                !val.equals("-") && !val.isEmpty() && !val.equalsIgnoreCase("null")) {
                                                hasMtmData = true;
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                            if (hasMtmData) {
                                validRow = r;
                                break;
                            }
                        }

                        if (validRow != -1) {
                            // kolom array starts from 0 (usually "Jenis Indikator"), so row r aligns with kolom[r+1]
                            period = (kolom.length > validRow + 1) ? kolom[validRow + 1] : "-";
                            
                            // Extract values for all columns in the valid row
                            for (int c = 0; c < listIsi[validRow].length; c++) {
                                if (listIsi[validRow][c] != null) {
                                    String[] parts = listIsi[validRow][c].split("mufti");
                                    if (parts.length > 1) {
                                        String label = parts[0].toLowerCase();
                                        if (label.contains("month") || label.contains("mtm") || label.contains("bulan")) {
                                            valMtm = parts[1];
                                        } else if (label.contains("kalender") || label.contains("calendar")) {
                                            valCal = parts[1];
                                        } else if (label.contains("year") || label.contains("yoy") || label.contains("tahun")) {
                                            valYoy = parts[1];
                                        }
                                    }
                                }
                            }
                        }
                    }

                    caption.append("Bulan ").append(period).append(", Jawa Tengah mengalami inflasi sebesar *")
                            .append(valMtm).append("%*.");
                    if (!valYoy.equals("-")) {
                        caption.append(" Secara tahunan, inflasi tercatat *").append(valYoy).append("%*.");
                    }
                    if (!valCal.equals("-")) {
                        caption.append(" Tahun Kalender, inflasi tercatat *").append(valCal).append("%*.");
                    }
                    caption.append("\nTetap pantau harga kebutuhan pokok ya, Sahabat Statistik!");

                } else if (isRowBased) {
                    // ROW-BASED: Data is in listIsi[i][0] -> Label mufti Val1 mufti Val2...
                    // Log shows: KOLOM (13): [Kabupaten/Kota, Januari, Februari, ...]
                    // ROW 0: [Cilacapmufti-0.42mufti2.63mufti, Cilacapmufti0.80mufti4.22mufti, null...]
                    int maxRow = (listIsi != null) ? listIsi.length : 0;
                    int targetC = -1; // The column index in listIsi (which month)
                    
                    // Find the latest column index that has MTM data for at least one row
                    int maxCol = (listIsi != null && maxRow > 0) ? listIsi[0].length : 0;
                    
                    outerLoop:
                    for (int c = maxCol - 1; c >= 0; c--) {
                        for (int i = 0; i < maxRow; i++) {
                            if (listIsi[i] != null && listIsi[i].length > c && listIsi[i][c] != null) {
                                String[] parts = listIsi[i][c].split("mufti");
                                // Using index 1 which represents MTM (parts[1])
                                if (parts.length > 1) {
                                    String v = parts[1].trim();
                                    if (!v.isEmpty() && !v.equals("-") && !v.equalsIgnoreCase("null")) {
                                        targetC = c;
                                        break outerLoop;
                                    }
                                }
                            }
                        }
                    }
                    
                    if (targetC == -1) targetC = 0;

                    // Header alignment: targetC + 1 (since targetC=0 maps to Januari)
                    String period = "-";
                    if (kolom != null && (targetC + 1) < kolom.length) {
                        period = kolom[targetC + 1];
                    }
                    
                    if (strLang.equals("en")) {
                        caption.append(getString(R.string.share_pada_periode_en)).append(" ").append(period).append(", ")
                                .append(getString(R.string.share_tercatat_en)).append("\n");
                    } else {
                        caption.append(getString(R.string.share_pada_periode)).append(" ").append(period).append(", ")
                                .append(getString(R.string.share_tercatat)).append("\n");
                    }
                    
                    int limit = 0;
                    for (int i = 0; i < maxRow && limit < 15; i++) {
                        if (listIsi[i] != null && listIsi[i].length > targetC && listIsi[i][targetC] != null) {
                            String[] parts = listIsi[i][targetC].split("mufti");
                            if (parts.length > 1) { // Extract MTM
                                String label = parts[0];
                                String value = parts[1].trim(); 
                                caption.append("- ").append(label).append(": *").append(value).append("*");
                                caption.append("\n");
                                limit++;
                            }
                        }
                    }
                                 } else {
                    // COLUMN-BASED (Ekspor/Impor) or FLAT-STRUCTURE (SKD/PDRB)
                    // We want to share the LATEST Time Period -> Target Row or Target Part

                    int maxRow = (listIsi != null) ? listIsi.length : 0;
                    int maxCol = (listIsi != null && maxRow > 0) ? listIsi[0].length : 0;
                    
                    int targetRow = -1;
                    int targetPart = 1;
                    int groupSize = (kolom1 != null && kolom1.length > 1) ? kolom1.length : 1;
                    
                    // Iterating backwards to find the last row/column that actually has valid data
                    for (int r = maxRow - 1; r >= 0; r--) {
                        boolean hasData = false;
                        for (int j = 0; j < maxCol; j++) {
                            if (listIsi[r] != null && listIsi[r][j] != null) {
                                String[] parts = listIsi[r][j].split("mufti");
                                for (int p = parts.length - 1; p >= 1; p--) {
                                    String val = parts[p].trim();
                                    if (!val.isEmpty() && !val.equals("-") && !val.equalsIgnoreCase("null")) {
                                        hasData = true;
                                        if (p > targetPart) targetPart = p;
                                    }
                                }
                            }
                        }
                        if (hasData) {
                            targetRow = r;
                            break;
                        }
                    }
                    
                    if (targetRow == -1 && maxRow > 0) targetRow = maxRow - 1;

                    // Calculate period index
                    int headerIdx;
                    if (maxRow == 1) {
                        headerIdx = 1 + (targetPart - 1) / groupSize;
                    } else {
                        headerIdx = targetRow + 1;
                    }

                    String period = "-";
                    if (kolom != null && headerIdx < kolom.length) {
                        period = kolom[headerIdx];
                    } else if (kolom != null && kolom.length > 1) {
                        period = kolom[kolom.length - 1];
                    }

                    if (strLang.equals("en")) {
                        caption.append(getString(R.string.share_pada_periode_en)).append(" ").append(period).append(", ")
                                .append(getString(R.string.share_tercatat_en)).append("\n");
                    } else {
                        caption.append(getString(R.string.share_pada_periode)).append(" ").append(period).append(", ")
                                .append(getString(R.string.share_tercatat)).append("\n");
                    }

                    int limit = 0;
                    if (targetRow >= 0) {
                        // Start index for values in 'parts' array
                        int startP;
                        if (maxRow == 1) {
                            startP = 1 + ((targetPart - 1) / groupSize) * groupSize;
                        } else {
                            startP = 1;
                        }

                        for (int j = 0; j < maxCol && limit < 15; j++) {
                            if (listIsi[targetRow][j] != null) {
                                String[] parts = listIsi[targetRow][j].split("mufti");
                                if (parts.length > startP) {
                                    caption.append("- ").append(parts[0]).append(": *").append(parts[startP]).append("*");
                                    for (int k = 1; k < groupSize; k++) {
                                        if (parts.length > startP + k) {
                                            caption.append(" ").append(parts[startP + k]);
                                        }
                                    }
                                    caption.append("\n");
                                    limit++;
                                }
                            }
                        }
                    }
                }

                String footer = (strLang.equals("en")) ? getString(R.string.share_desc_en)
                        : getString(R.string.share_desc);
                caption.append("\n\n\uD83D\uDCF2 *").append(footer).append("*");

                Intent intent = new Intent(Intent.ACTION_SEND);
                intent.setType("text/plain");
                intent.putExtra(Intent.EXTRA_TEXT, caption.toString());
                startActivity(Intent.createChooser(intent, "Bagikan via"));

            } catch (Exception e) {
                new classFungsi(DetailKonten.this, "Gagal memproses data: " + e.getMessage()).TampilkanSnackBar();
            }
        }
    }

    public void processFinish(String strOutput) {
        JSONObject jsonObject1, isi;
        JSONArray array;

        String strTabel = "", temp;
        String[] isiKolom;
        listIsi = null;
        x = 0;
        jmlKolom = 0;
        StringBuilder strHead = new StringBuilder(), strHeader = new StringBuilder(), strData = new StringBuilder();

        // Detect dark mode
        int currentNightMode = getResources().getConfiguration().uiMode
                & android.content.res.Configuration.UI_MODE_NIGHT_MASK;
        boolean isDarkMode = (currentNightMode == android.content.res.Configuration.UI_MODE_NIGHT_YES);
        String bodyClass = isDarkMode ? " class=\"dark-mode\"" : "";

        strHead = strHead.append("<head>\n\t<link rel=\"stylesheet\" type=\"text/css\" href=\"styles.css\">\n" +
                "<meta name=\"color-scheme\" content=\"light dark\">\n" +
                "</head>\n<body" + bodyClass + ">\n<div class=\"table-container\">\n<table class =\"tabel\">\n");

        try {
            if (strOutput == null || strOutput.isEmpty() || strOutput.startsWith("failed") || strOutput.startsWith("throwable")) {
                new classFungsi(DetailKonten.this, "Gagal memuat data: " + strOutput).TampilkanSnackBar();
                binding.llKonten.setVisibility(View.GONE);
                binding.llTitleContainer.setVisibility(View.GONE);
                binding.prgBar.setVisibility(View.GONE);
                return;
            }
            jsonObject1 = new JSONObject(strOutput);
            JSONArray ArrData = jsonObject1.getJSONArray("data");

            strTabelDB = jsonObject1.optString("tabel");
            strJudul = jsonObject1.getString("judul");
            binding.tvMainTitle.setText(strJudul); // Update Header Title (Now in Body)

            // Extract Year from Title if possible (e.g. "... 2024")
            try {
                java.util.regex.Matcher m = java.util.regex.Pattern.compile("(\\d{4})").matcher(strJudul);
                if (m.find()) {
                    String foundYear = m.group(1);
                    binding.btnYearSelector.setText(foundYear + " ▼");
                }
            } catch (Exception e) {
                e.printStackTrace();
            }

            kolom = jsonObject1.getString("kolom").split(":");
            String rawKolom1 = jsonObject1.optString("kolom1");
            kolom1 = (rawKolom1 != null && !rawKolom1.isEmpty() && !rawKolom1.equals("null")) ? rawKolom1.split(":") : new String[0];
            
            boolean hasSubHeader = kolom1.length > 0;
            int headerRows = hasSubHeader ? 2 : 1;

            // Restore rowspan and colspan logic required by createExcel()
            if (hasSubHeader) {
                rowspan = 3;
                colspan = (kolom.length - 1) + (kolom1.length * kolom.length);
            } else {
                rowspan = 2;
                colspan = kolom.length - 1;
            }

            // Row 1
            strHeader = strHeader.append("\t<tr>\n");
            // First column (Sticky)
            strHeader = strHeader.append("\t\t<td class=\"header-tabel header-sticky\" rowspan=\"").append(headerRows)
                    .append("\">")
                    .append(kolom[0]).append("</td>\n");
            
            // Parent Columns
            for (int i = 1; i < kolom.length; i++) {
                if (hasSubHeader) {
                    strHeader = strHeader.append("\t\t<td class=\"header-tabel\" colspan=\"").append(kolom1.length)
                            .append("\">").append(kolom[i]).append("</td>\n");
                } else {
                    strHeader = strHeader.append("\t\t<td class=\"header-tabel\">").append(kolom[i]).append("</td>\n");
                }
            }
            strHeader = strHeader.append("\t</tr>\n");

            // Row 2 (Sub-headers) if exist
            if (hasSubHeader) {
                strHeader = strHeader.append("\t<tr>\n");
                for (int j = 1; j < kolom.length; j++) {
                    for (String s : kolom1) {
                        strHeader = strHeader.append("\t\t<td class=\"header-tabel\">").append(s).append("</td>\n");
                    }
                }
                strHeader = strHeader.append("\t</tr>\n");
            }

            switch (strMenu) {
                case "inflasi_prov_series":
                case "inflasi_kelompok":
                case "ntp_prov":
                case "ntp_prov_jawa":
                case "ntup":
                case "ntp_series":
                case "ekspor_negara":
                case "ekspor_migas":
                case "impor_migas":
                case "impor_negara":
                case "neraca":
                case "pdrb_lu_distribusi":
                case "pdrb_lu_sumber":
                case "pdrb_pengeluaran_distribusi":
                case "pdrb_pengeluaran_sumber":
                case "gini_ratio_prov":
                case "naker_setengah_prov":
                case "ipm_status_series":
                    judul1 = jsonObject1.optString("judul1").split(":");

                    // First pass: find the maximum number of columns across all periods to set jmlKolom
                    x = ArrData.length();
                    jmlKolom = 0;
                    for (int i = 0; i < x; i++) {
                        JSONArray tempArr = ArrData.getJSONObject(i).getJSONArray("data");
                        if (tempArr.length() > jmlKolom) {
                            jmlKolom = tempArr.length();
                        }
                    }
                    if (jmlKolom == 0) jmlKolom = 1; // Fallback to avoid 0 dimension
                    listIsi = new String[x][jmlKolom];

                    for (int i = 0; i < ArrData.length(); i++) {
                        array = ArrData.getJSONObject(i).getJSONArray("data");
                        for (int j = 0; j < array.length(); j++) {
                            isi = array.getJSONObject(j);
                            // Safety check
                            if (j < jmlKolom) {
                                listIsi[i][j] = isi.optString("isi1") + "mufti"
                                        + isi.optString("isi2");
                            }
                        }
                    }

                    for (int j = 0; j < jmlKolom; j++) {
                        switch (strMenu) {
                            case "ntp_prov":
                                if (j == 0 || j == 3) {
                                    strData = strData.append("\t<tr>\n");
                                    if (j == 0)
                                        strData = strData.append("\t\t<td class=\"vervar1 section-header\">")
                                                .append(judul1[0])
                                                .append("</td>")
                                                .append("\t\t<td class=\"section-header\" colspan=\"")
                                                .append(x)
                                                .append("\"></td>");
                                    if (j == 3)
                                        strData = strData.append("\t\t<td class=\"vervar1 section-header\">")
                                                .append(judul1[1])
                                                .append("</td>")
                                                .append("\t\t<td class=\"section-header\" colspan=\"")
                                                .append(x)
                                                .append("\"></td>");
                                    strData = strData.append("\t</tr>\n");
                                }
                                break;
                            case "ekspor_migas":
                            case "impor_migas":
                                if (j == 0 || j == 2) {
                                    strData = strData.append("\t<tr>\n");
                                    if (j == 0)
                                        strData = strData.append("\t\t<td class=\"vervar1 section-header\">")
                                                .append(judul1[0])
                                                .append("</td>")
                                                .append("\t\t<td class=\"section-header\" colspan=\"")
                                                .append(x)
                                                .append("\"></td>");
                                    if (j == 2)
                                        strData = strData.append("\t\t<td class=\"vervar1 section-header\">")
                                                .append(judul1[1])
                                                .append("</td>")
                                                .append("\t\t<td class=\"section-header\" colspan=\"")
                                                .append(x)
                                                .append("\"></td>");
                                    strData = strData.append("\t</tr>\n");
                                }
                                break;
                            case "naker_setengah_prov":
                                if (j == 0 || j == 1 || j == 3) {
                                    strData = strData.append("\t<tr>\n");
                                    if (j == 0)
                                        strData = strData.append("\t\t<td class=\"vervar1\" colspan=\"")
                                                .append(x + 1)
                                                .append("\">")
                                                .append(judul1[0])
                                                .append("</td>");
                                    if (j == 1)
                                        strData = strData.append("\t\t<td class=\"vervar1\" colspan=\"")
                                                .append(x + 1)
                                                .append("\">")
                                                .append(judul1[1])
                                                .append("</td>");
                                    if (j == 3)
                                        strData = strData.append("\t\t<td class=\"vervar1\" colspan=\"")
                                                .append(x + 1)
                                                .append("\">")
                                                .append(judul1[2])
                                                .append("</td>");
                                    strData = strData.append("\t</tr>\n");
                                }
                                break;
                        }
                        strData = strData.append("\t<tr>\n");
                        for (int i = 0; i < x; i++) {
                            if (listIsi[i][j] != null) {
                                isiKolom = listIsi[i][j].split("mufti");
                                if (i == 0)
                                    strData = strData.append("\t\t<td class=\"vervar col-sticky\">").append(isiKolom[0])
                                            .append("</td>");
                                strData = strData.append("\t\t<td class=\"datacell\">")
                                        .append((isiKolom.length == 1) ? "" : isiKolom[1]).append("</td>");
                            } else {
                                if (i == 0)
                                    strData = strData.append("\t\t<td class=\"vervar col-sticky\">").append("")
                                            .append("</td>");
                                strData = strData.append("\t\t<td class=\"datacell\">").append("").append("</td>");
                            }
                        }
                        strData = strData.append("\t</tr>\n");
                    }
                    break;

                case "inflasi_penyumbang":
                case "ntp_penyumbang":
                case "ekspor_pertumbuhan":
                case "impor_pertumbuhan":
                    intTengah = 0;
                    String strBulan;
                    for (int i = 0; i < ArrData.length(); i++) {
                        strBulan = ArrData.getJSONObject(i).getString("bulan");
                        array = ArrData.getJSONObject(i).getJSONArray("data");
                        for (int j = 0; j < array.length(); j++) {
                            if (i == 0 && j == 0) {
                                x = ArrData.length();
                                jmlKolom = array.length();
                                if (strMenu.equals("inflasi_penyumbang"))
                                    intTengah = jmlKolom / 2;
                                if (strMenu.equals("ntp_penyumbang"))
                                    intTengah = 2;
                                if (strTabelDB.equals("ots_ekspor") || strTabelDB.equals("ots_impor"))
                                    intTengah = jmlKolom / 2;
                                if (strTabelDB.equals("t_ekspor") || strTabelDB.equals("t_impor"))
                                    intTengah = jmlKolom;
                                listIsi = new String[x][intTengah];
                            }
                            isi = array.getJSONObject(j);
                            if (j > intTengah - 1) {
                                temp = Objects.requireNonNull(listIsi)[i][j % intTengah];
                                temp = temp + "mufti" + isi.optString("isi1") + "mufti" + isi.optString("isi2");
                                listIsi[i][j % intTengah] = temp;
                            } else {
                                Objects.requireNonNull(listIsi)[i][j] = strBulan + "mufti" + isi.optString("isi1")
                                        + "mufti" + isi.optString("isi2");
                            }
                        }
                    }

                    for (int i = 0; i < x; i++) {
                        for (int j = 0; j < intTengah; j++) {
                            strData = strData.append("\t<tr>\n");
                            if (listIsi[i][j] != null) {
                                isiKolom = listIsi[i][j].split("mufti");
                                if (j == 0)
                                    strData = strData.append("\t\t<td class=\"vervar col-sticky\" rowspan=\"")
                                            .append(intTengah + 1).append("\">").append(isiKolom[0]).append("</td>");
                                for (int k = 1; k < isiKolom.length; k++) {
                                    strData = strData.append("\t\t<td class=\"datacellHuruf\">").append(isiKolom[k])
                                            .append("</td>");
                                }
                                if (j == intTengah - 1) {
                                    strData = strData.append("\t<tr>\n");
                                    for (int k = 1; k < isiKolom.length; k++) {
                                        strData = strData.append("\t\t<td class=\"vervar\">").append("</td>");
                                    }
                                    strData.append("\t</tr>\n");
                                }
                            }
                            strData = strData.append("\t</tr>\n");
                        }
                    }
                    break;
                case "pdrb_lu_nominal":
                case "pdrb_lu_pertumbuhan":
                case "pdrb_pengeluaran_nominal":
                case "pdrb_pengeluaran_pertumbuhan":
                case "miskin_prov":
                case "gini_ratio_prov_series":
                case "tpak_prov":
                case "naker_lu_jk":
                case "naker_lu_wilayah":
                case "naker_formal_prov":
                case "naker_pendidikan_prov":
                    judul1 = jsonObject1.optString("judul1").split(":");
                    for (int i = 0; i < ArrData.length(); i++) {
                        if (strMenu.equals("pdrb_lu_nominal") || strMenu.equals("pdrb_pengeluaran_nominal")
                                || strMenu.equals("tpak_prov") ||
                                strMenu.contains("naker_lu") || strMenu.equals("naker_formal_prov")
                                || strMenu.equals("naker_setengah_prov"))
                            jmlKolom = 2;
                        if (strMenu.equals("pdrb_lu_pertumbuhan") || strMenu.equals("pdrb_pengeluaran_pertumbuhan")
                                || strMenu.equals("miskin_prov") ||
                                strMenu.equals("gini_ratio_prov_series"))
                            jmlKolom = 3;
                        if (strMenu.equals("naker_pendidikan_prov"))
                            jmlKolom = 6;

                        array = ArrData.getJSONObject(i).getJSONArray("data");
                        if (array.length() != 0) {
                            for (int j = 0; j < array.length(); j++) {
                                if (i == 0 && j == 0) {
                                    x = array.length();
                                    listIsi = new String[x][jmlKolom];
                                }
                                isi = array.getJSONObject(j);
                                if (i == 0) {
                                    listIsi[j][0] = isi.optString("isi1") + "mufti" + isi.optString("isi2");
                                    listIsi[j][1] = isi.optString("isi1") + "mufti" + isi.optString("isi3");
                                    if (strMenu.equals("pdrb_lu_pertumbuhan")
                                            || strMenu.equals("pdrb_pengeluaran_pertumbuhan")
                                            || strMenu.equals("miskin_prov") ||
                                            strMenu.equals("gini_ratio_prov_series")
                                            || strMenu.equals("naker_pendidikan_prov")) {
                                        listIsi[j][2] = isi.optString("isi1") + "mufti" + isi.optString("isi4");
                                        if (strMenu.equals("naker_pendidikan_prov")) {
                                            listIsi[j][3] = isi.optString("isi1") + "mufti" + isi.optString("isi5");
                                            listIsi[j][4] = isi.optString("isi1") + "mufti" + isi.optString("isi6");
                                            listIsi[j][5] = isi.optString("isi1") + "mufti" + isi.optString("isi7");
                                        }
                                    }
                                } else {
                                    temp = Objects.requireNonNull(listIsi)[j][0];
                                    temp = temp + "mufti" + isi.optString("isi2");
                                    listIsi[j][0] = temp;
                                    temp = Objects.requireNonNull(listIsi)[j][1];
                                    temp = temp + "mufti" + isi.optString("isi3");
                                    listIsi[j][1] = temp;
                                    if (strMenu.equals("pdrb_lu_pertumbuhan")
                                            || strMenu.equals("pdrb_pengeluaran_pertumbuhan")
                                            || strMenu.equals("miskin_prov") || strMenu.equals("gini_ratio_prov_series")
                                            || strMenu.equals("naker_pendidikan_prov")) {
                                        temp = Objects.requireNonNull(listIsi)[j][2];
                                        temp = temp + "mufti" + isi.optString("isi4");
                                        listIsi[j][2] = temp;
                                        if (strMenu.equals("naker_pendidikan_prov")) {
                                            temp = Objects.requireNonNull(listIsi)[j][3];
                                            temp = temp + "mufti" + isi.optString("isi5");
                                            listIsi[j][3] = temp;
                                            temp = Objects.requireNonNull(listIsi)[j][4];
                                            temp = temp + "mufti" + isi.optString("isi6");
                                            listIsi[j][4] = temp;
                                            temp = Objects.requireNonNull(listIsi)[j][5];
                                            temp = temp + "mufti" + isi.optString("isi7");
                                            listIsi[j][5] = temp;
                                        }
                                    }
                                }
                            }
                        } else {
                            for (int j = 0; j < x; j++) {
                                temp = Objects.requireNonNull(listIsi)[j][0];
                                temp = temp + "mufti" + " ";
                                listIsi[j][0] = temp;
                                temp = Objects.requireNonNull(listIsi)[j][1];
                                temp = temp + "mufti" + " ";
                                listIsi[j][1] = temp;
                                if (strMenu.equals("pdrb_lu_pertumbuhan")
                                        || strMenu.equals("pdrb_pengeluaran_pertumbuhan")
                                        || strMenu.equals("miskin_prov") || strMenu.equals("gini_ratio_prov_series")
                                        || strMenu.equals("naker_pendidikan_prov")) {
                                    temp = Objects.requireNonNull(listIsi)[j][2];
                                    temp = temp + "mufti" + " ";
                                    listIsi[j][2] = temp;
                                    if (strMenu.equals("naker_pendidikan_prov")) {
                                        temp = Objects.requireNonNull(listIsi)[j][3];
                                        temp = temp + "mufti" + " ";
                                        listIsi[j][3] = temp;
                                        temp = Objects.requireNonNull(listIsi)[j][4];
                                        temp = temp + "mufti" + " ";
                                        listIsi[j][4] = temp;
                                        temp = Objects.requireNonNull(listIsi)[j][5];
                                        temp = temp + "mufti" + " ";
                                        listIsi[j][5] = temp;
                                    }
                                }
                            }
                        }
                    }

                    // Untuk Cek Isian
                    int intIsian = 0;
                    if (strMenu.equals("gini_ratio_prov_series")) {
                        String[] isian = Objects.requireNonNull(listIsi)[0][0].split("mufti");
                        intIsian = isian.length;
                    }

                    for (int i = 0; i < x; i++) {
                        strData = strData.append("\t<tr>\n");
                        if (listIsi[i] != null) {
                            for (int j = 0; j < jmlKolom; j++) {
                                if (listIsi[i][j] == null) {
                                    listIsi[i][j] = "- mufti -";
                                }
                                isiKolom = listIsi[i][j].split("mufti");
                                if (strMenu.equals("gini_ratio_prov_series")) {
                                    if (intIsian != isiKolom.length) {
                                        temp = isiKolom[0] + "mufti" + isiKolom[1] + "mufti ";
                                        listIsi[i][j] = temp;
                                        isiKolom = listIsi[i][j].split("mufti");
                                    }
                                } else if (strMenu.equals("tpak_prov") && j == 0) {
                                    if (i == 0 || i == 1 || i == 3) {
                                        strData = strData.append("\t<tr>\n");
                                        if (i == 0)
                                            strData = strData.append("\t\t<td class=\"vervar1 col-sticky\">")
                                                    .append(judul1[0])
                                                    .append("</td>");
                                        if (i == 1)
                                            strData = strData.append("\t\t<td class=\"vervar1\">").append(judul1[1])
                                                    .append("</td>");
                                        if (i == 3)
                                            strData = strData.append("\t\t<td class=\"vervar1\">").append(judul1[2])
                                                    .append("</td>");
                                        strData = strData.append("\t</tr>\n");
                                    }
                                } else if ((strMenu.equals("naker_formal_prov")
                                        || strMenu.equals("naker_pendidikan_prov")) && j == 0) {
                                    if (i == 0 || i == 2) {
                                        strData = strData.append("\t<tr>\n");
                                        if (i == 0)
                                            strData = strData.append("\t\t<td class=\"vervar1 col-sticky\">")
                                                    .append(judul1[0])
                                                    .append("</td>");
                                        if (i == 2)
                                            strData = strData.append("\t\t<td class=\"vervar1\">").append(judul1[1])
                                                    .append("</td>");
                                        strData = strData.append("\t</tr>\n");
                                    }
                                }
                                if (j == 0)
                                    strData = strData.append("\t\t<td class=\"vervar col-sticky\">").append(isiKolom[0])
                                            .append("</td>");
                                for (int k = 1; k < isiKolom.length; k++) {
                                    strData = strData.append("\t\t<td class=\"datacell\">").append(isiKolom[k])
                                            .append("</td>");
                                }
                            }
                        }
                        strData = strData.append("\t</tr>\n");
                    }
                    break;
                case "inflasi_6_kota":
                case "inflasi_ibu_kota":
                    x = ArrData.length();
                    for (int i = 0; i < x; i++) {
                        array = ArrData.getJSONObject(i).getJSONArray("data");
                        if (array.length() != 0) {
                            for (int j = 0; j < array.length(); j++) {
                                if (i == 0 && j == 0) {
                                    jmlKolom = array.length();
                                    listIsi = new String[array.length()][x];
                                }
                                isi = array.getJSONObject(j);
                                if (strMenu.equals("inflasi_6_kota") || strMenu.equals("inflasi_ibu_kota")) {
                                    Objects.requireNonNull(listIsi)[j][i] = isi.optString("isi1") + "mufti"
                                            + isi.optString("isi2") + "mufti" + isi.optString("isi3") + "mufti"
                                            + isi.optString("isi4");
                                } else {
                                    Objects.requireNonNull(listIsi)[j][i] = isi.optString("isi1") + "mufti"
                                            + isi.optString("isi2") + "mufti" + isi.optString("isi3");
                                }
                            }
                        }
                    }

                    // Untuk Cek Isian
                    for (int i = 0; i < jmlKolom; i++) {
                        strData = strData.append("\t<tr>\n");
                        for (int j = 0; j < ArrData.length(); j++) {
                            if (listIsi[i][j] != null) {
                                isiKolom = listIsi[i][j].split("mufti");
                                if (j == 0)
                                    strData = strData.append("\t\t<td class=\"vervar col-sticky\">").append(isiKolom[0])
                                            .append("</td>");
                                for (int k = 1; k < isiKolom.length; k++) {
                                    strData = strData.append("\t\t<td class=\"datacell\">").append(isiKolom[k])
                                            .append("</td>");
                                }
                            }
                        }
                        strData = strData.append("\t</tr>\n");
                    }
                    break;
                default:
                    JSONObject object;
                    listIsi = new String[1][ArrData.length()];
                    for (int i = 0; i < ArrData.length(); i++) {
                        object = ArrData.getJSONObject(i);
                        listIsi[0][i] = object.optString("isi1") + "mufti" + object.optString("isi2") + "mufti" +
                                object.optString("isi3") + "mufti" + object.optString("isi4") + "mufti"
                                + object.optString("isi5") + "mufti" + object.optString("isi6")
                                + "mufti" + object.optString("isi7") + "mufti" + object.optString("isi8") + "mufti"
                                + object.optString("isi9");
                    }
                    x = ArrData.length();
                    for (int j = 0; j < x; j++) {
                        isiKolom = listIsi[0][j].split("mufti");
                        strData = strData.append("\t<tr>\n");

                        String style = "";
                        if (isiKolom[0].contains("IPTEK, Inovasi") ||
                                isiKolom[0].contains("Integrasi Ekonomi") ||
                                isiKolom[0].contains("Perkotaan dan Perdesaan") ||
                                isiKolom[0].contains("Keluarga Berkualitas")) {
                            style = " style=\"color: #1976D2; font-weight: bold;\"";
                        }

                        strData = strData.append("\t\t<td class=\"vervar col-sticky\"" + style + ">")
                                .append(isiKolom[0])
                                .append("</td>");
                        for (int k = 1; k < isiKolom.length; k++) {
                            strData = strData.append("\t\t<td class=\"datacell\">").append(isiKolom[k]).append("</td>");
                        }
                        strData = strData.append("\t</tr>\n");
                    }
                    break;
            }

            strTabel = strHead.toString() + strHeader + strData
                    + "</table>\n</div>\n<p style=\"font-size: 13px; color: #666; font-style: italic; margin-top: 8px; text-align: left; padding-left: 4px;\">Geser layar untuk melihat data lainnya</p>\n";

            strTabel += "</body>";
            strTabel = strTabel.replace("null", " ");
            binding.wvKonten.loadDataWithBaseURL("file:///android_asset/", strTabel, "text/html", "utf-8", null);

        } catch (Exception e) {
            binding.wvKonten.loadDataWithBaseURL("file:///android_asset/", strOutput, "text/html", "utf-8", null);
        }

        // Setup Line Chart for series data
        if (isSeriesMenu() && listIsi != null && kolom != null) {
            setupLineChart();
        }

        binding.prgBar.setVisibility(View.INVISIBLE);
    }

    /**
     * Check if the current menu is a series-type menu that should display a chart
     */
    private boolean isSeriesMenu() {
        return strMenu.equals("inflasi_prov_series") ||
                strMenu.equals("inflasi_kelompok") ||
                strMenu.equals("inflasi_6_kota") ||
                strMenu.equals("inflasi_ibu_kota") ||
                strMenu.equals("ntp_prov") ||
                strMenu.equals("ntp_prov_jawa") ||
                strMenu.equals("ntup") ||
                strMenu.equals("ntp_series") ||
                strMenu.equals("ekspor_negara") ||
                strMenu.equals("ekspor_migas") ||
                strMenu.equals("impor_migas") ||
                strMenu.equals("impor_negara") ||
                strMenu.equals("neraca") ||
                strMenu.equals("pdrb_lu_distribusi") ||
                strMenu.equals("pdrb_lu_sumber") ||
                strMenu.equals("pdrb_pengeluaran_distribusi") ||
                strMenu.equals("pdrb_pengeluaran_sumber") ||
                strMenu.equals("gini_ratio_prov") ||
                strMenu.equals("gini_ratio_prov_series") ||
                strMenu.equals("naker_setengah_prov") ||
                strMenu.equals("ipm_status_series") ||
                strMenu.equals("pdrb_lu_nominal") ||
                strMenu.equals("pdrb_lu_pertumbuhan") ||
                strMenu.equals("pdrb_pengeluaran_nominal") ||
                strMenu.equals("pdrb_pengeluaran_pertumbuhan") ||
                strMenu.equals("miskin_prov") ||
                strMenu.equals("tpak_prov") ||
                strMenu.contains("naker_lu") ||
                strMenu.equals("naker_formal_prov") ||
                strMenu.equals("naker_pendidikan_prov") ||
                strMenu.equals("naker_setengah_prov");
    }

    /**
     * Setup and display Line Chart with series data - supports multiple lines
     */
    private void setupLineChart() {
        try {
            // Check for special menu types that have different data structure
            boolean isKotaMenu = strMenu.equals("inflasi_6_kota") || strMenu.equals("inflasi_ibu_kota");

            // Get period labels from kolom array (skip first column which is label header)
            List<String> labels = new ArrayList<>();
            for (int i = 1; i < kolom.length && i <= x; i++) {
                labels.add(kolom[i]);
            }

            if (listIsi == null || labels.isEmpty()) {
                binding.chartContainer.setVisibility(View.GONE);
                return;
            }

            // Define colors for each line - extended palette for many rows
            int[] lineColors = {
                    Color.parseColor("#4472C4"), // Blue
                    Color.parseColor("#ED7D31"), // Orange
                    Color.parseColor("#70AD47"), // Green
                    Color.parseColor("#FFC000"), // Yellow
                    Color.parseColor("#5B9BD5"), // Light Blue
                    Color.parseColor("#A5A5A5"), // Gray
                    Color.parseColor("#9E480E"), // Dark Orange
                    Color.parseColor("#636363"), // Dark Gray
                    Color.parseColor("#997300"), // Dark Yellow
                    Color.parseColor("#255E91"), // Dark Blue
                    Color.parseColor("#43682B"), // Dark Green
                    Color.parseColor("#7030A0"), // Purple
                    Color.parseColor("#C00000"), // Red
                    Color.parseColor("#00B050"), // Bright Green
                    Color.parseColor("#0070C0"), // Bright Blue
                    Color.parseColor("#BF8F00") // Gold
            };

            // Create multiple datasets (one for each row of data)
            List<LineDataSet> dataSets = new ArrayList<>();
            int maxRows;

            if (isKotaMenu) {
                // For inflasi_6_kota and inflasi_ibu_kota: data is in listIsi[row][period]
                maxRows = jmlKolom;
                for (int row = 0; row < maxRows; row++) {
                    List<Entry> entries = new ArrayList<>();
                    String rowLabel = "Data " + (row + 1);

                    // Get label and values - data is in listIsi[row][period]
                    for (int period = 0; period < x && period < labels.size(); period++) {
                        if (listIsi[row] != null && listIsi[row][period] != null) {
                            String[] parts = listIsi[row][period].split("mufti");
                            // Get row label from first period
                            if (period == 0 && parts.length > 0 && !parts[0].isEmpty()) {
                                rowLabel = parts[0];
                            }
                            // Value is in position 1 (isi2)
                            if (parts.length > 1) {
                                try {
                                    String valueStr = parts[1].replace(",", ".")
                                            .replace("%", "")
                                            .replace(" ", "")
                                            .trim();
                                    if (!valueStr.isEmpty() && !valueStr.equals("-")) {
                                        float value = Float.parseFloat(valueStr);
                                        entries.add(new Entry(period, value));
                                    }
                                } catch (NumberFormatException e) {
                                    // Skip non-numeric values
                                }
                            }
                        }
                    }

                    if (!entries.isEmpty()) {
                        LineDataSet dataSet = new LineDataSet(entries, rowLabel);
                        styleDataSet(dataSet, lineColors[row % lineColors.length]);
                        dataSets.add(dataSet);
                    }
                }
            } else {
                // Standard series data: data is in listIsi[period][row]
                maxRows = jmlKolom;
                for (int row = 0; row < maxRows; row++) {
                    List<Entry> entries = new ArrayList<>();
                    String rowLabel = "Data " + (row + 1);

                    // Get label from first period's data cell
                    if (listIsi.length > 0 && listIsi[0] != null && listIsi[0].length > row && listIsi[0][row] != null) {
                        String[] parts = listIsi[0][row].split("mufti");
                        if (parts.length > 0 && !parts[0].isEmpty()) {
                            rowLabel = parts[0];
                        }
                    }

                    // Extract values across all periods
                    for (int period = 0; period < x && period < labels.size(); period++) {
                        if (period < listIsi.length && listIsi[period] != null && row < listIsi[period].length && listIsi[period][row] != null) {
                            String[] parts = listIsi[period][row].split("mufti");
                            if (parts.length > 1) {
                                try {
                                    String valueStr = parts[1].replace(",", ".")
                                            .replace("%", "")
                                            .replace(" ", "")
                                            .trim();
                                    if (!valueStr.isEmpty() && !valueStr.equals("-")) {
                                        float value = Float.parseFloat(valueStr);
                                        entries.add(new Entry(period, value));
                                    }
                                } catch (NumberFormatException e) {
                                    // Skip non-numeric values
                                }
                            }
                        }
                    }

                    if (!entries.isEmpty()) {
                        LineDataSet dataSet = new LineDataSet(entries, rowLabel);
                        styleDataSet(dataSet, lineColors[row % lineColors.length]);
                        dataSets.add(dataSet);
                    }
                }
            }

            if (dataSets.isEmpty()) {
                binding.chartContainer.setVisibility(View.GONE);
                return;
            }

            // Create LineData with all datasets
            LineData lineData = new LineData();
            for (LineDataSet ds : dataSets) {
                lineData.addDataSet(ds);
            }
            binding.lineChart.setData(lineData);

            // Configure X-axis
            XAxis xAxis = binding.lineChart.getXAxis();
            xAxis.setPosition(XAxis.XAxisPosition.BOTTOM);
            xAxis.setGranularity(1f);
            xAxis.setLabelRotationAngle(-45f);
            xAxis.setValueFormatter(new IndexAxisValueFormatter(labels));
            xAxis.setLabelCount(labels.size());
            xAxis.setDrawGridLines(true);
            xAxis.setGridColor(Color.LTGRAY);

            // Configure Y-axis
            YAxis leftAxis = binding.lineChart.getAxisLeft();
            leftAxis.setDrawGridLines(true);
            leftAxis.setGridColor(Color.LTGRAY);
            binding.lineChart.getAxisRight().setEnabled(false);

            // Configure chart appearance - ENABLE HORIZONTAL SCROLL
            binding.lineChart.getDescription().setEnabled(false);
            binding.lineChart.setTouchEnabled(true);
            binding.lineChart.setDragEnabled(true);
            binding.lineChart.setDragXEnabled(true);
            binding.lineChart.setDragYEnabled(false);
            binding.lineChart.setScaleEnabled(true);
            binding.lineChart.setScaleXEnabled(true);
            binding.lineChart.setScaleYEnabled(false);
            binding.lineChart.setPinchZoom(false);
            binding.lineChart.setDoubleTapToZoomEnabled(false);
            binding.lineChart.setDrawGridBackground(false);
            binding.lineChart.setBackgroundColor(Color.WHITE);

            // Set visible range to enable scrolling
            if (labels.size() > 6) {
                binding.lineChart.setVisibleXRangeMaximum(6);
                binding.lineChart.moveViewToX(0);
            }

            // Configure legend
            binding.lineChart.getLegend().setEnabled(true);
            binding.lineChart.getLegend().setWordWrapEnabled(true);
            binding.lineChart.getLegend().setHorizontalAlignment(
                    com.github.mikephil.charting.components.Legend.LegendHorizontalAlignment.CENTER);

            // Handle dark mode
            int currentNightMode = getResources().getConfiguration().uiMode
                    & android.content.res.Configuration.UI_MODE_NIGHT_MASK;
            boolean isDarkMode = (currentNightMode == android.content.res.Configuration.UI_MODE_NIGHT_YES);
            if (isDarkMode) {
                binding.lineChart.setBackgroundColor(Color.parseColor("#1E1E1E"));
                xAxis.setTextColor(Color.WHITE);
                xAxis.setGridColor(Color.DKGRAY);
                leftAxis.setTextColor(Color.WHITE);
                leftAxis.setGridColor(Color.DKGRAY);
                binding.lineChart.getLegend().setTextColor(Color.WHITE);
                for (LineDataSet ds : dataSets) {
                    ds.setValueTextColor(Color.WHITE);
                }
            }

            // Animate and show
            binding.lineChart.animateX(800);
            binding.lineChart.invalidate();
            binding.chartContainer.setVisibility(View.VISIBLE);

        } catch (Exception e) {
            e.printStackTrace();
            binding.chartContainer.setVisibility(View.GONE);
        }
    }

    /**
     * Helper method to style a LineDataSet
     */
    private void styleDataSet(LineDataSet dataSet, int lineColor) {
        dataSet.setColor(lineColor);
        dataSet.setCircleColor(lineColor);
        dataSet.setLineWidth(2.5f);
        dataSet.setCircleRadius(4f);
        dataSet.setDrawCircleHole(true);
        dataSet.setCircleHoleRadius(2f);
        dataSet.setValueTextSize(9f);
        dataSet.setDrawFilled(false);
        dataSet.setMode(LineDataSet.Mode.LINEAR);
        dataSet.setDrawValues(false);
    }

    private void createExcel() {
        HSSFRow rowData;
        HSSFWorkbook workbook = new HSSFWorkbook();
        HSSFSheet sheet = workbook.createSheet(strMenu);
        int indexRow = 0;
        String[] isiKolom;

        if (rowspan > 1) {
            sheet.addMergedRegion(new CellRangeAddress(0, rowspan - 1, 0, 0));
        }
        if (colspan > 1) {
            sheet.addMergedRegion(new CellRangeAddress(0, 0, 1, colspan));
        }
        rowData = sheet.createRow(indexRow);
        indexRow++;
        rowData.createCell(0).setCellValue(kolom[0]);
        rowData.createCell(1).setCellValue(strJudul);

        rowData = sheet.createRow(indexRow);
        indexRow++;
        if (kolom1.length > 0) {
            for (int i = 1; i < kolom.length; i++) {
                int firstCol = 1 + (kolom1.length * (i - 1));
                int lastCol = kolom1.length * i;
                if (firstCol < lastCol) {
                    sheet.addMergedRegion(new CellRangeAddress(1, 1, firstCol, lastCol));
                }
                rowData.createCell(firstCol).setCellValue(kolom[i]);
            }
            rowData = sheet.createRow(indexRow);
            indexRow++;
            for (int i = 1; i < kolom.length; i++) {
                for (int j = 0; j < kolom1.length; j++) {
                    rowData.createCell(i + j + ((i - 1) * (kolom1.length - 1))).setCellValue(kolom1[j]);
                }
            }
        } else {
            for (int i = 1; i < kolom.length; i++)
                rowData.createCell(i).setCellValue(kolom[i]);
        }

        switch (strMenu) {
            case "inflasi_prov_series":
            case "inflasi_kelompok":
            case "ntp_prov":
            case "ntp_prov_jawa":
            case "ntup":
            case "ntp_series":
            case "ekspor_negara":
            case "ekspor_migas":
            case "impor_migas":
            case "impor_negara":
            case "neraca":
            case "pdrb_lu_distribusi":
            case "pdrb_lu_sumber":
            case "pdrb_pengeluaran_distribusi":
            case "pdrb_pengeluaran_sumber":
            case "gini_ratio_prov":
            case "naker_setengah_prov":
            case "ipm_status_series":
                for (int j = 0; j < jmlKolom; j++) {
                    switch (strMenu) {
                        case "ntp_prov":
                            if (j == 0 || j == 3) {
                                rowData = sheet.createRow(indexRow);
                                indexRow++;
                                if (j == 0)
                                    rowData.createCell(0).setCellValue(judul1[0]);
                                if (j == 3)
                                    rowData.createCell(0).setCellValue(judul1[1]);
                            }
                            break;
                        case "ekspor_migas":
                        case "impor_migas":
                            if (j == 0 || j == 2) {
                                rowData = sheet.createRow(indexRow);
                                indexRow++;
                                if (j == 0)
                                    rowData.createCell(0).setCellValue(judul1[0]);
                                if (j == 2)
                                    rowData.createCell(0).setCellValue(judul1[1]);
                            }
                            break;
                        case "naker_setengah_prov":
                            if (j == 0 || j == 1 || j == 3) {
                                rowData = sheet.createRow(indexRow);
                                indexRow++;
                                if (j == 0)
                                    rowData.createCell(0).setCellValue(judul1[0]);
                                if (j == 1)
                                    rowData.createCell(0).setCellValue(judul1[1]);
                                if (j == 3)
                                    rowData.createCell(0).setCellValue(judul1[2]);
                            }
                            break;
                    }

                    for (int i = 0; i < x; i++) {
                        if (listIsi[i][j] != null) {
                            isiKolom = listIsi[i][j].split("mufti");
                            if (i == 0) {
                                rowData = sheet.createRow(indexRow);
                                indexRow++;
                                rowData.createCell(0).setCellValue(isiKolom[0]);
                            }
                            rowData.createCell(i + 1).setCellValue(isiKolom[1]);
                        } else {
                            if (i == 0) {
                                rowData = sheet.createRow(indexRow);
                                indexRow++;
                                rowData.createCell(0).setCellValue("");
                            }
                            rowData.createCell(i + 1).setCellValue("");
                        }
                    }
                }
                break;
            case "inflasi_penyumbang":
            case "ntp_penyumbang":
            case "ekspor_pertumbuhan":
            case "impor_pertumbuhan":
                for (int i = 0; i < x; i++) {
                    for (int j = 0; j < intTengah; j++) {
                        rowData = sheet.createRow(indexRow);
                        indexRow++;
                        if (listIsi[i][j] != null) {
                            isiKolom = listIsi[i][j].split("mufti");
                            if (j == 0)
                                rowData.createCell(0).setCellValue(isiKolom[0]);
                            for (int k = 1; k < isiKolom.length; k++)
                                rowData.createCell(k).setCellValue(isiKolom[k]);
                        }
                    }
                }
                break;
            case "pdrb_lu_nominal":
            case "pdrb_lu_pertumbuhan":
            case "pdrb_pengeluaran_nominal":
            case "pdrb_pengeluaran_pertumbuhan":
            case "miskin_prov":
            case "gini_ratio_prov_series":
            case "tpak_prov":
            case "naker_lu_jk":
            case "naker_lu_wilayah":
            case "naker_formal_prov":
            case "naker_pendidikan_prov":
                int intIsian = 0;
                if (strMenu.equals("gini_ratio_prov_series")) {
                    String[] isian = Objects.requireNonNull(listIsi)[0][0].split("mufti");
                    intIsian = isian.length;
                }

                for (int i = 0; i < x; i++) {
                    rowData = sheet.createRow(indexRow);
                    indexRow++;
                    if (listIsi[i] != null) {
                        for (int j = 0; j < jmlKolom; j++) {
                            isiKolom = listIsi[i][j].split("mufti");
                            if (strMenu.equals("gini_ratio_prov_series")) {
                                if (intIsian != isiKolom.length) {
                                    String temp = isiKolom[0] + "mufti" + isiKolom[1] + "mufti ";
                                    listIsi[i][j] = temp;
                                    isiKolom = listIsi[i][j].split("mufti");
                                }
                            } else if (strMenu.equals("tpak_prov") && j == 0) {
                                if (i == 0 || i == 1 || i == 3) {
                                    if (i == 0)
                                        rowData.createCell(0).setCellValue(judul1[0]);
                                    if (i == 1)
                                        rowData.createCell(0).setCellValue(judul1[1]);
                                    if (i == 3)
                                        rowData.createCell(0).setCellValue(judul1[2]);
                                    rowData = sheet.createRow(indexRow);
                                    indexRow++;
                                }
                            } else if ((strMenu.equals("naker_formal_prov") || strMenu.equals("naker_pendidikan_prov"))
                                    && j == 0) {
                                if (i == 0 || i == 2) {
                                    if (i == 0)
                                        rowData.createCell(0).setCellValue(judul1[0]);
                                    if (i == 2)
                                        rowData.createCell(0).setCellValue(judul1[1]);
                                    rowData = sheet.createRow(indexRow);
                                    indexRow++;
                                }
                            }
                            if (j == 0)
                                rowData.createCell(0).setCellValue(isiKolom[0]);
                            for (int k = 1; k < isiKolom.length; k++)
                                rowData.createCell(k + (j * kolom1.length)).setCellValue(isiKolom[k]);
                        }
                    }
                }
                break;
            case "inflasi_6_kota":
            case "inflasi_ibu_kota":
                for (int i = 0; i < jmlKolom; i++) {
                    rowData = sheet.createRow(indexRow);
                    indexRow++;
                    for (int j = 0; j < x; j++) {
                        if (listIsi[i][j] != null) {
                            isiKolom = listIsi[i][j].split("mufti");
                            if (j == 0)
                                rowData.createCell(0).setCellValue(isiKolom[0]);
                            for (int k = 1; k < isiKolom.length; k++)
                                rowData.createCell(k + (j * kolom1.length)).setCellValue(isiKolom[k]);
                        }
                    }
                }
                break;
            default:
                for (int j = 0; j < x; j++) {
                    isiKolom = listIsi[0][j].split("mufti");
                    rowData = sheet.createRow(indexRow);
                    indexRow++;
                    rowData.createCell(0).setCellValue(isiKolom[0]);
                    for (int k = 1; k < isiKolom.length; k++)
                        rowData.createCell(k).setCellValue(isiKolom[k].replace("null", " "));
                }
                break;
        }

        try {
            File dirDownload = new File(
                    Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS) + "/Ots");
            dirDownload.mkdirs();
            String safeJudul = strJudul.replaceAll("[\\\\/:*?\"<>|\\n\\r]", "_");
            String timeStamp = String.valueOf(System.currentTimeMillis());
            String finalJudul = safeJudul + "_" + timeStamp;
            File fileFile = new File(Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS)
                    + "/Ots/" + finalJudul + ".xls");
            FileOutputStream fileOutputStream = new FileOutputStream(fileFile);
            workbook.write(fileOutputStream);
            fileOutputStream.close();
            new classFungsi(DetailKonten.this, getString(R.string.export_selesai),
                    finalJudul, ".xls").TampilkanSnackBarOpenFile();
        } catch (Exception e) {
            android.widget.Toast
                    .makeText(this, "Gagal export Excel: " + e.getMessage(), android.widget.Toast.LENGTH_LONG).show();
            e.printStackTrace();
        }
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, @NonNull String[] permissions,
            @NonNull int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == 1 && grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
            createExcel();
        }
    }

}
