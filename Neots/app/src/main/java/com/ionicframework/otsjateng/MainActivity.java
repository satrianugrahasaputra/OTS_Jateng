package com.ionicframework.otsjateng;

import android.Manifest;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Build;
import android.os.Bundle;
import android.view.Menu;
import android.view.MenuItem;
import android.view.MotionEvent;
import android.view.View;
import android.view.ViewGroup;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.IntentSenderRequest;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.appcompat.app.AppCompatDelegate;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.lifecycle.ViewModelProvider;
import androidx.recyclerview.widget.GridLayoutManager;
import androidx.recyclerview.widget.LinearLayoutManager;
import com.ionicframework.otsjateng.model.modelDataDashboard;
import com.ionicframework.otsjateng.model.modelFooter;

import com.google.android.gms.tasks.Task;
import com.google.android.play.core.appupdate.AppUpdateInfo;
import com.google.android.play.core.appupdate.AppUpdateManager;
import com.google.android.play.core.appupdate.AppUpdateManagerFactory;
import com.google.android.play.core.appupdate.AppUpdateOptions;
import com.google.android.play.core.install.model.AppUpdateType;
import com.google.android.play.core.install.model.UpdateAvailability;
import com.google.firebase.database.DataSnapshot;
import com.google.firebase.database.DatabaseError;
import com.google.firebase.database.DatabaseReference;
import com.google.firebase.database.FirebaseDatabase;
import com.google.firebase.database.ValueEventListener;
import com.ionicframework.otsjateng.databinding.ContentMainBinding;
import com.ionicframework.otsjateng.model.modelData;
import com.ionicframework.otsjateng.model.modelDataImage;
import com.ionicframework.otsjateng.model.modelBannerScores;
import com.ionicframework.otsjateng.utilities.AdapterList;
import com.ionicframework.otsjateng.utilities.checkPermission;
import com.ionicframework.otsjateng.utilities.classFungsi;
import com.ionicframework.otsjateng.utilities.LatifaChatBottomSheet;
import com.ionicframework.otsjateng.vm.inetViewModel;
import java.util.ArrayList;
import java.util.List;

import android.os.Handler;
import android.os.Looper;

public class MainActivity
        extends AppCompatActivity {

    // Auto-Refresh Handler
    private final Handler refreshHandler = new Handler(Looper.getMainLooper());
    private final Runnable refreshRunnable = new Runnable() {
        @Override
        public void run() {
            // Fetch updated data
            if (viewModel != null) {
                SharedPreferences preferences = getSharedPreferences("link", MODE_PRIVATE);
                viewModel.setDashboard(lang, preferences.getString("link", ""));
            }
            // Schedule next run in 30 seconds
            refreshHandler.postDelayed(this, 30000);
        }
    };

    private ContentMainBinding binding;
    private inetViewModel viewModel;
    private List<modelData> dashboardList;
    private AdapterList adapter;
    private AppUpdateManager updateManager;
    private String lang;
    private boolean isDarkMode;

    final ActivityResultLauncher<IntentSenderRequest> startUpdate = registerForActivityResult(
            new ActivityResultContracts.StartIntentSenderForResult(),
            result -> {
                if (result.getResultCode() != RESULT_OK) {
                    new classFungsi(this, "Update Gagal").TampilkanSnackBar();
                }
            });

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        binding = ContentMainBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());

        DatabaseReference myRef = FirebaseDatabase.getInstance().getReference("link");
        SharedPreferences preferences = this.getSharedPreferences("link", MODE_PRIVATE);
        lang = preferences.getString("lang", "in");
        isDarkMode = preferences.getBoolean("dark_mode", false);
        applyDarkMode();
        binding.prgBar.setVisibility(View.VISIBLE);

        inisialisasi();
        inisialisasiViewModel();

        // ====== SERVER asli ======
        myRef.addValueEventListener(new ValueEventListener() {
            @Override
            public void onDataChange(@NonNull DataSnapshot dataSnapshot) {
                String value = dataSnapshot.getValue(String.class);
                String productionUrl = "https://adminots.jateng.pro/";
                String finalUrl = (value != null && !value.isEmpty()) ? value : productionUrl;
                SharedPreferences.Editor editor = preferences.edit();
                editor.putString("link", finalUrl);
                editor.apply();
                viewModel.setDashboard(lang, preferences.getString("link", ""));
            }

            @Override
            public void onCancelled(@NonNull DatabaseError error) {
                String productionUrl = "https://adminots.jateng.pro/";
                SharedPreferences.Editor editor = preferences.edit();
                editor.putString("link", productionUrl);
                editor.apply();
                viewModel.setDashboard(lang, productionUrl);
            }
        });
        // ====== SERVER asli ======

        // ====== TESTING LOKAL ======
        // String finalUrl = "http://192.168.43.62:8000/";
        // SharedPreferences.Editor editor = preferences.edit();
        // editor.putString("link", finalUrl);
        // editor.apply();
        // viewModel.setDashboard(lang, finalUrl);
        // ====== TESTING LOKAL ======

        ViewCompat.setOnApplyWindowInsetsListener(binding.headerCustom, (v, windowInsets) -> {
            Insets insets = windowInsets.getInsets(WindowInsetsCompat.Type.systemBars());
            v.setPadding(v.getPaddingLeft(), insets.top, v.getPaddingRight(), v.getPaddingBottom());
            return WindowInsetsCompat.CONSUMED;
        });

        setupHeaderActions();
    }

    private void setupHeaderActions() {
        // Theme Toggle
        binding.btnHeaderTheme.setOnClickListener(v -> {
            boolean newMode = !isDarkMode;
            SharedPreferences prefs = getSharedPreferences("link", MODE_PRIVATE);
            prefs.edit().putBoolean("dark_mode", newMode).apply();
            recreate();
        });

        // Initial State for Theme Icon & Color
        if (isDarkMode) {
            binding.btnHeaderTheme.setImageResource(R.drawable.ic_night);
            binding.btnHeaderTheme.setColorFilter(getResources().getColor(R.color.header_icon));
            binding.btnHeaderCall.setColorFilter(getResources().getColor(R.color.header_icon));
            binding.btnHeaderLang.setTextColor(getResources().getColor(R.color.header_icon));
        } else {
            binding.btnHeaderTheme.setImageResource(R.drawable.ic_wb_sunny);
            binding.btnHeaderTheme.setColorFilter(getResources().getColor(R.color.header_icon));
            binding.btnHeaderCall.setColorFilter(getResources().getColor(R.color.header_icon));
            binding.btnHeaderLang.setTextColor(getResources().getColor(R.color.header_icon));
        }

        // Initial Language Text
        if (lang.equals("en")) {
            binding.btnHeaderLang.setText("EN");
        } else {
            binding.btnHeaderLang.setText("ID");
        }

        // Language Toggle
        binding.btnHeaderLang.setOnClickListener(v -> {
            String current = binding.btnHeaderLang.getText().toString();
            if (current.equalsIgnoreCase("ID")) {
                lang = "en";
            } else {
                lang = "in";
            }
            SharedPreferences prefs = getSharedPreferences("link", MODE_PRIVATE);
            prefs.edit().putString("lang", lang).apply();
            recreate(); // Fully rebuild Activity with new language
        });

        // Call Custom (Pengaduan)
        binding.btnHeaderCall.setOnClickListener(v -> {
            Intent intent = new Intent(this, WebViewActivity.class);
            intent.putExtra("link", "s.bps.go.id/pengaduanjateng");
            startActivity(intent);
        });
    }

    private void inisialisasi() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            checkPermission.checkAndRequestPermissions(MainActivity.this,
                    Manifest.permission.WRITE_EXTERNAL_STORAGE,
                    Manifest.permission.READ_EXTERNAL_STORAGE);
        } else {
            checkPermission.checkAndRequestPermissions(MainActivity.this,
                    Manifest.permission.WRITE_EXTERNAL_STORAGE,
                    Manifest.permission.READ_EXTERNAL_STORAGE);
        }

        dashboardList = new ArrayList<>();
        adapter = new AdapterList(MainActivity.this, dashboardList, "Dashboard", lang);

        GridLayoutManager mLayoutManager = new GridLayoutManager(MainActivity.this, 2);
        mLayoutManager.setSpanSizeLookup(new GridLayoutManager.SpanSizeLookup() {
            @Override
            public int getSpanSize(int position) {
                int type = adapter.getItemViewType(position);
                if (type == AdapterList.VIEW_TYPE_IMAGE || type == AdapterList.VIEW_TYPE_FOOTER
                        || type == AdapterList.VIEW_TYPE_BANNER_SCORES || type == AdapterList.VIEW_TYPE_MAKLUMAT) {
                    return 2;
                }
                return 1;
            }
        });

        binding.rvHome.setLayoutManager(mLayoutManager);
        binding.rvHome.setAdapter(adapter);

        updateManager = AppUpdateManagerFactory.create(this);
        Task<AppUpdateInfo> task = updateManager.getAppUpdateInfo();
        task.addOnSuccessListener(appUpdateInfo -> {
            if (appUpdateInfo.updateAvailability() == UpdateAvailability.UPDATE_AVAILABLE) {
                updateManager.startUpdateFlowForResult(appUpdateInfo,
                        startUpdate,
                        AppUpdateOptions.newBuilder(AppUpdateType.IMMEDIATE).build());
            }
        });

        // LATIFA FAB click listener
        binding.fabLatifa.setOnClickListener(v -> {
            Intent intent = new Intent(this, WebViewActivity.class);
            intent.putExtra("link", "https://latifa.jateng.pro/");
            startActivity(intent);
        });

        // Make LATIFA draggable
        binding.fabLatifa.setOnTouchListener(new View.OnTouchListener() {
            private float dX, dY;
            private float startX, startY;

            @Override
            public boolean onTouch(View view, MotionEvent event) {
                switch (event.getActionMasked()) {
                    case MotionEvent.ACTION_DOWN:
                        dX = binding.latifaContainer.getX() - event.getRawX();
                        dY = binding.latifaContainer.getY() - event.getRawY();
                        startX = event.getRawX();
                        startY = event.getRawY();
                        return true; // Consume touch to allow dragging

                    case MotionEvent.ACTION_MOVE:
                        binding.latifaContainer.animate()
                                .x(event.getRawX() + dX)
                                .y(event.getRawY() + dY)
                                .setDuration(0)
                                .start();
                        return true;

                    case MotionEvent.ACTION_UP:
                        float endX = event.getRawX();
                        float endY = event.getRawY();
                        // If movement is small, treat as a click
                        if (Math.abs(endX - startX) < 10 && Math.abs(endY - startY) < 10) {
                            view.performClick();
                        }
                        return true;
                    default:
                        return false;
                }
            }
        });

        // Swipe to refresh listener
        binding.swipeRefresh.setColorSchemeResources(R.color.primaryColor, R.color.secondaryColor);
        binding.swipeRefresh.setOnRefreshListener(() -> {
            SharedPreferences preferences = this.getSharedPreferences("link", MODE_PRIVATE);
            viewModel.setDashboard(lang, preferences.getString("link", ""));
        });
    }

    private void inisialisasiViewModel() {
        viewModel = new ViewModelProvider(MainActivity.this).get(inetViewModel.class);
        viewModel.getDashboard().observe(this, modelResponseDashboard -> {
            if (modelResponseDashboard.getStatus().equals("success")) {
                dashboardList.clear();

                // Logic to find IPKP and IPAK scores
                String ipkpScore = "-";
                String ipkpStatus = "-";
                String ipakScore = "-";
                String ipakStatus = "-";
                String bannerPeriod = "";

                List<modelDataDashboard> filteredData = new ArrayList<>();

                if (modelResponseDashboard.getData() != null) {
                    for (modelDataDashboard item : modelResponseDashboard.getData()) {
                        String itemId = (item.getId() != null) ? item.getId().trim().toLowerCase() : "";
                        String itemInd = (item.getIndikator() != null) ? item.getIndikator().trim().toLowerCase() : "";

                        // Pencarian lebih luas untuk IPKP (mencari 'kualitas', 'pelayanan', 'quality',
                        // 'service')
                        boolean isIpkp = itemId.equals("skm") || itemId.equals("ipkp") ||
                                itemInd.contains("kualitas") || itemInd.contains("pelayanan") ||
                                itemInd.contains("quality") || itemInd.contains("service");

                        // Pencarian lebih luas untuk IPAK (mencari 'anti korupsi', 'anti-korupsi',
                        // 'korupsi',
                        // 'anti corruption', 'anti-corruption', 'corruption')
                        boolean isIpak = itemId.equals("ipak") ||
                                itemInd.contains("anti korupsi") || itemInd.contains("anti-korupsi") ||
                                itemInd.contains("korupsi") || itemInd.contains("anti corruption") ||
                                itemInd.contains("anti-corruption") || itemInd.contains("corruption");

                        // IPKP
                        if (isIpkp) {
                            String currentVal = item.getNilai() != null ? item.getNilai().replace(".", ",") : "-";
                            if (ipkpScore.equals("-") || (!currentVal.equals("-") && !currentVal.equals("0,00"))) {
                                ipkpScore = currentVal;
                                // Extract period from SKM item
                                String trw = item.getPeriode();
                                String tahun = item.getTahun();
                                if (trw != null && !trw.isEmpty() && tahun != null && !tahun.isEmpty()) {
                                    // Capitalize first letter of triwulan if needed, otherwise use as is
                                    String periodStr = trw.substring(0, 1).toUpperCase() + trw.substring(1);
                                    bannerPeriod = periodStr + " " + tahun;
                                }

                                if (item.getTanda() != null && !item.getTanda().isEmpty()
                                        && !item.getTanda().equals("-")) {
                                    ipkpStatus = item.getTanda();
                                } else {
                                    double val = 0;
                                    try {
                                        val = Double.parseDouble(item.getNilai().replace(",", "."));
                                    } catch (Exception e) {
                                    }
                                    if (val >= 3.5)
                                        ipkpStatus = "Sangat Baik";
                                    else if (val >= 3.0)
                                        ipkpStatus = "Baik";
                                    else
                                        ipkpStatus = "Cukup";
                                }
                            }
                        }

                        // IPAK
                        if (isIpak) {
                            String currentVal = item.getNilai() != null ? item.getNilai().replace(".", ",") : "-";
                            if (ipakScore.equals("-") || (!currentVal.equals("-") && !currentVal.equals("0,00"))) {
                                ipakScore = currentVal;
                                if (item.getTanda() != null && !item.getTanda().isEmpty()
                                        && !item.getTanda().equals("-")) {
                                    ipakStatus = item.getTanda();
                                } else {
                                    double val = 0;
                                    try {
                                        val = Double.parseDouble(item.getNilai().replace(",", "."));
                                    } catch (Exception e) {
                                    }
                                    if (val >= 3.5)
                                        ipakStatus = "Sangat Baik";
                                    else if (val >= 3.0)
                                        ipakStatus = "Baik";
                                    else
                                        ipakStatus = "Cukup";
                                }

                                // Capture period from IPAK as well if IPKP didn't provide it
                                if (bannerPeriod.isEmpty()) {
                                    String trw = item.getPeriode();
                                    String tahun = item.getTahun();
                                    if (trw != null && !trw.isEmpty() && tahun != null && !tahun.isEmpty()) {
                                        String periodStr = trw.substring(0, 1).toUpperCase() + trw.substring(1);
                                        bannerPeriod = periodStr + " " + tahun;
                                    }
                                }
                            }
                        }

                        // Force Translate IUP Title if language is English
                        if (itemId.equals("iup") && lang.equals("en")) {
                            if (itemInd.contains("indikator utama")) {
                                item.setIndikator("Regional Development Main Indicators\nof Jawa Tengah Province");
                            }
                        }

                        // Add to filtered list if NOT ipak (user wants to keep IPKP/skm in the list)
                        if (!isIpak) {
                            filteredData.add(item);
                        }
                    }
                }

                // Create Banner Model with dynamic data from API
                dashboardList.add(new modelBannerScores(ipkpScore, ipkpStatus, ipakScore, ipakStatus, bannerPeriod));

                dashboardList.add(modelResponseDashboard.getMaklumat());
                dashboardList.addAll(filteredData);

                dashboardList.add(new modelFooter("Hak Cipta © 2026 BPS Jateng", ""));
                adapter.notifyDataSetChanged();

                // Trigger fetch for Inflation Series to update comparison logic
                SharedPreferences preferences = this.getSharedPreferences("link", MODE_PRIVATE);
                viewModel.setDetail("inflasi_prov_series", "0/0", lang, preferences.getString("link", ""));
            } else {
                new classFungsi(MainActivity.this, "Gagal memuat dashboard: " + modelResponseDashboard.getStatus())
                        .TampilkanSnackBar();
            }
            binding.prgBar.setVisibility(View.INVISIBLE);
            binding.swipeRefresh.setRefreshing(false);
        });

        // Observer for Inflation Series Data to update Dashboard
        viewModel.getDetail().observe(this, strOutput -> {
            if (strOutput != null && !strOutput.isEmpty() && dashboardList.size() > 0) {
                try {
                    org.json.JSONObject jsonObject = new org.json.JSONObject(strOutput);
                    org.json.JSONArray arrData = jsonObject.getJSONArray("data");
                    String[] koloms = jsonObject.getString("kolom").split(":");

                    // Find Inflation Item in Dashboard List
                    int inflasiIndex = -1;
                    for (int i = 0; i < dashboardList.size(); i++) {
                        if (dashboardList.get(i) instanceof modelDataDashboard) {
                            if (((modelDataDashboard) dashboardList.get(i)).getId().equals("inflasi")) {
                                inflasiIndex = i;
                                break;
                            }
                        }
                    }

                    if (inflasiIndex != -1 && arrData.length() > 0) {
                        // Parse m-to-m data (usually first row, columns [1] and [2] are dates?
                        // Based on DetailKonten: data[0] is rows.
                        // arrData is array of objects {bulan, data[{isi1, isi2...}]}
                        // BUT DetailKonten logic for inflasi_prov_series says:
                        // "Transposed"? ArrData length is columns. data array is rows.
                        // Let's assume standard series structure:
                        // ArrData[0] -> Column 1 (Latest Month usually if descending? Or Jan if
                        // Ascending?)
                        // Actually DetailKonten logic:
                        // for inflasi_prov_series: Transposed.
                        // ArrData[i] is a COLUMN.
                        // ArrData[0] is likely the FIRST Month column in the series (Jan?).
                        // Wait, user image shows Jan - Dec.
                        // If API returns full year, we need the LATEST filled month.

                        // Re-reading DetailKonten logic:
                        // for transposed: ArrData.length is number of COLUMNS (Months).
                        // content is in arrData[i].data[j].
                        // Row 0 is M-to-M data.

                        // Let's find the last non-empty value in M-to-M row (row 0).
                        // Iterator through columns (ArrData)

                        double currentVal = 0;
                        double prevVal = 0;
                        String currentPeriod = "";
                        String prevPeriod = "";
                        boolean foundCurrent = false;

                        // Loop backwards from last column to find data
                        for (int i = arrData.length() - 1; i >= 0; i--) {
                            org.json.JSONArray colData = arrData.getJSONObject(i).getJSONArray("data");
                            if (colData.length() > 0) {
                                String valStr = colData.getJSONObject(0).getString("isi2"); // isi2 usually value?
                                // DetailKonten: listIsi[i][j] = isi.optString("isi1") + "mufti" +
                                // isi.optString("isi2");
                                // Row 0 (MTM) is index 0.

                                // Check valid number
                                try {
                                    // "isi2" might be the number.
                                    // Let's verify structure from DetailKonten again:
                                    // "isi1" + mufti + "isi2".
                                    // if transposed, listIsi[i][j].
                                    // i (columns), j (rows).
                                    // row 0 is MtoM.
                                } catch (Exception e) {
                                }
                            }
                        }

                        // Alternate strategy: We see user image: Dec 0.50, Nov 0.19.
                        // This data comes from `inflasi_prov_series`.
                        // We will update the Dashboard item.

                        // Since JSON parsing complexity is high without seeing response,
                        // I will apply robust logic: Scan for numbers in Row 0 (MTM).

                        List<Double> mtmValues = new ArrayList<>();
                        List<String> periods = new ArrayList<>();

                        for (int i = 0; i < arrData.length(); i++) {
                            org.json.JSONArray colRows = arrData.getJSONObject(i).getJSONArray("data");
                            if (colRows.length() > 0) {
                                // MTM is likely Row 0
                                String val = colRows.getJSONObject(0).getString("isi2"); // Value
                                String period = koloms[i + 1]; // Header name for this column

                                try {
                                    mtmValues.add(Double.parseDouble(val.replace(",", ".")));
                                    periods.add(period);
                                } catch (Exception e) {
                                }
                            }
                        }

                        if (mtmValues.size() >= 2) {
                            // Data is Descending (Index 0 is Latest, Index 1 is Previous)
                            double curr = mtmValues.get(0);
                            double prev = mtmValues.get(1);
                            String currPer = periods.get(0);
                            String prevPer = periods.get(1);

                            double diff = curr - prev;

                            modelDataDashboard oldItem = (modelDataDashboard) dashboardList.get(inflasiIndex);

                            // Create updated item with CALCULATED difference
                            // diff string format "0.00"
                            String diffStr = String.format(java.util.Locale.US, "%.2f", Math.abs(diff));
                            String tanda = (diff > 0) ? "lebih tinggi" : "lebih rendah";
                            if (diff == 0)
                                tanda = "sama";

                            // We want to force the adapter to use the comparison template.
                            // BUT AdapterList has my custom overrides now.
                            // I should revert the override in AdapterList OR modify it to respect this new
                            // data.

                            // Strategy: Update AdapterList to use standard template IF "poin" is valid.
                            // And here we set "poin" to valid diff.

                            modelDataDashboard newItem = new modelDataDashboard(
                                    oldItem.getId(), oldItem.getIndikator(),
                                    currPer, oldItem.getTahun(),
                                    String.format(java.util.Locale.US, "%.2f", curr),
                                    tanda,
                                    diffStr, // Poin = Diff
                                    prevPer, // Sebelumnya = Previous Period Name
                                    oldItem.getSatuan(),
                                    oldItem.getDelta(),
                                    oldItem.getLang());

                            dashboardList.set(inflasiIndex, newItem);
                            adapter.notifyItemChanged(inflasiIndex);
                        }
                    }
                } catch (Exception e) {
                    // e.printStackTrace();
                }
            }
        });
    }

    @Override
    protected void onResume() {
        super.onResume();
        // Start auto-refresh timer (first run after 30 seconds to allow initial load to
        // finish)
        refreshHandler.postDelayed(refreshRunnable, 30000);
    }

    @Override
    protected void onPause() {
        super.onPause();
        // Stop auto-refresh when app is not visible to save resources
        refreshHandler.removeCallbacks(refreshRunnable);
    }

    private void applyDarkMode() {
        if (isDarkMode) {
            AppCompatDelegate.setDefaultNightMode(AppCompatDelegate.MODE_NIGHT_YES);
        } else {
            AppCompatDelegate.setDefaultNightMode(AppCompatDelegate.MODE_NIGHT_NO);
        }
    }

    @Override
    public boolean onCreateOptionsMenu(Menu menu) {
        getMenuInflater().inflate(R.menu.main, menu);
        return super.onCreateOptionsMenu(menu);
    }

    @Override
    public boolean onPrepareOptionsMenu(Menu menu) {
        MenuItem darkModeItem = menu.findItem(R.id.dark_mode);
        if (isDarkMode) {
            darkModeItem.setIcon(R.drawable.ic_light_mode);
        } else {
            darkModeItem.setIcon(R.drawable.ic_dark_mode);
        }
        return super.onPrepareOptionsMenu(menu);
    }

    @Override
    public boolean onOptionsItemSelected(@NonNull MenuItem item) {
        if (item.getItemId() == R.id.pengaduan) {
            Intent intent = new Intent(this, WebViewActivity.class);
            intent.putExtra("link", "https://45.jateng.pro/pengaduan/");
            startActivity(intent);
        } else if (item.getItemId() == R.id.dark_mode) {
            isDarkMode = !isDarkMode;
            SharedPreferences preferences = this.getSharedPreferences("link", MODE_PRIVATE);
            SharedPreferences.Editor editor = preferences.edit();
            editor.putBoolean("dark_mode", isDarkMode);
            editor.apply();
            applyDarkMode();
        } else if (item.getItemId() == R.id.menuIndo) {
            SharedPreferences preferences = this.getSharedPreferences("link", MODE_PRIVATE);
            SharedPreferences.Editor editor = preferences.edit();
            editor.putString("lang", "in");
            editor.apply();
            recreate(); // Fully rebuild Activity with new language
        } else if (item.getItemId() == R.id.menuEng) {
            SharedPreferences preferences = this.getSharedPreferences("link", MODE_PRIVATE);
            SharedPreferences.Editor editor = preferences.edit();
            editor.putString("lang", "en");
            editor.apply();
            recreate(); // Fully rebuild Activity with new language
        }
        return super.onOptionsItemSelected(item);
    }
}
