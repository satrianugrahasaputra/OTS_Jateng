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
import android.view.ViewGroup;
import android.widget.TextView;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.app.ActivityCompat;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.lifecycle.ViewModelProvider;
import androidx.recyclerview.widget.LinearLayoutManager;

import com.ionicframework.otsjateng.databinding.ActivityListMenuBinding;
import com.ionicframework.otsjateng.model.modelData;
import com.ionicframework.otsjateng.model.modelData3;
import com.ionicframework.otsjateng.model.modelDataDashboard;
import com.ionicframework.otsjateng.model.modelFooter;
import com.ionicframework.otsjateng.model.modelHeader3;
import com.ionicframework.otsjateng.model.modelIsi3;
import com.ionicframework.otsjateng.model.modelMenu;
import com.ionicframework.otsjateng.model.modelResponse3;
import com.ionicframework.otsjateng.model.modelTahun;
import com.ionicframework.otsjateng.utilities.AdapterList;
import com.ionicframework.otsjateng.utilities.classFungsi;
import com.ionicframework.otsjateng.vm.inetViewModel;

import org.apache.poi.hssf.usermodel.HSSFRow;
import org.apache.poi.hssf.usermodel.HSSFSheet;
import org.apache.poi.hssf.usermodel.HSSFWorkbook;
import org.apache.poi.ss.util.CellRangeAddress;

import java.io.File;
import java.io.FileOutputStream;
import java.util.ArrayList;
import java.util.Calendar;
import java.util.List;
import java.util.Objects;

public class ListKonten
        extends AppCompatActivity {

    private ActivityListMenuBinding binding;
    private inetViewModel viewModel;
    private AdapterList adapter;
    private List<modelData> modelDataList;
    private String strJudul, strLang, strUrl;
    private final String[] kolom1 = new String[3];
    private String strMenu, strIndikator, strTabelDB;
    private List<modelTahun> listTahun;
    private boolean isInitialYearSet = false;

    final ActivityResultLauncher<Intent> startTahun = registerForActivityResult(
            new ActivityResultContracts.StartActivityForResult(), result -> {
                if (result.getResultCode() == RESULT_OK) {
                    binding.prgBar.setVisibility(View.VISIBLE);
                    modelDataList.clear();
                    setData(Objects.requireNonNull(result.getData()).getStringExtra("tahun"));
                }
            });

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        binding = ActivityListMenuBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());

        if (getIntent().hasExtra("catatan_detail")) {
            String catatan = getIntent().getStringExtra("catatan_detail");
            if (catatan != null && !catatan.isEmpty()) {
                binding.cardInfoDetail.setVisibility(View.VISIBLE);
                binding.tvInfoDetail.setText(catatan);
            }
        }

        inisialisasi();
        switch (Objects.requireNonNull(strMenu)) {
            case "inflasi":
            case "ntp":
            case "ekspor":
            case "impor":
            case "pdrb_prov":
            case "miskin":
            case "gini_ratio":
            case "naker":
            case "ipm":
            case "skm":
            case "iup":
                if (strLang.equals("in"))
                    setMenu();
                else
                    setMenuEn();
                break;
            default:
                setData(Integer.toString(Calendar.getInstance().get(Calendar.YEAR)));
                break;
        }

        if (getSupportActionBar() != null) {
            getSupportActionBar().hide();
        }

        ViewCompat.setOnApplyWindowInsetsListener(binding.getRoot(), (v, windowInsets) -> {
            Insets insets = windowInsets.getInsets(WindowInsetsCompat.Type.systemBars());
            v.setPadding(insets.left, insets.top, insets.right, insets.bottom);
            return WindowInsetsCompat.CONSUMED;
        });

        setupHeaderActions();
    }

    private void inisialisasi() {
        SharedPreferences preferences = this.getSharedPreferences("link", MODE_PRIVATE);
        strUrl = preferences.getString("link", "");
        Bundle extra = getIntent().getExtras();
        strMenu = Objects.requireNonNull(extra).getString("menu");
        strIndikator = extra.getString("indikator");
        strLang = extra.getString("lang");

        viewModel = new ViewModelProvider(this).get(inetViewModel.class);
        modelDataList = new ArrayList<>();
        binding.rvKonten.setLayoutManager(new LinearLayoutManager(ListKonten.this));
        adapter = new AdapterList(this, modelDataList, strMenu);
        binding.rvKonten.setAdapter(adapter);

        viewModel.getData3().observe(this, modelResponse3 -> {
            binding.prgBar.setVisibility(View.INVISIBLE);
            if (modelResponse3.getStatus().equals("success")) {
                processFinish(modelResponse3);
            }
        });

        // Observe Tahun for Year Selector (specifically for ipm_kab)
        viewModel.getTahun().observe(this, modelResponseTahun -> {
            if (modelResponseTahun.getData() != null && !modelResponseTahun.getData().isEmpty()) {
                listTahun = modelResponseTahun.getData();
                // Only set the initial year if it's not already set by user
                if (!isInitialYearSet) {
                    String latestYear = listTahun.get(0).getTahun();
                    binding.btnYearSelector.setText(latestYear + " ▼");
                    isInitialYearSet = true;
                }
            }
        });
    }

    private void setData(String strTahun) {
        if (strMenu.contains("pdrb_kab") || strMenu.equals("ipm_kab") || strMenu.equals("skd_prov")
                || strMenu.equals("skd_kab") || strMenu.equals("skd_prov_smt") || strMenu.equals("skd_kab_smt")
                || strMenu.equals("skd_prov_ann") || strMenu.equals("skd_kab_ann")) {
            viewModel.setData3(strMenu, strTahun, strLang, strUrl);
        } else {
            viewModel.setData3(strMenu, "", strLang, strUrl);
        }
        binding.prgBar.setVisibility(View.VISIBLE);
    }

    private void setupHeaderActions() {
        binding.btnBack.setOnClickListener(v -> getOnBackPressedDispatcher().onBackPressed());

        boolean showMenu = false;
        if (strMenu.equals("inflasi_prov") || strMenu.equals("inflasi_kelompok") || strMenu.equals("inflasi_penyumbang")
                || strMenu.equals("ntp_prov") ||
                strMenu.equals("ntp_penyumbang") || strMenu.equals("ntp_prov_jawa") || strMenu.equals("ntup")
                || strMenu.equals("ekspor_pertumbuhan") ||
                strMenu.equals("ekspor_negara") || strMenu.equals("ekspor_migas") || strMenu.equals("impor_pertumbuhan")
                || strMenu.equals("impor_negara") ||
                strMenu.equals("impor_migas") || strMenu.equals("neraca") || strMenu.equals("miskin_prov")
                || strMenu.equals("gini_ratio_prov") ||
                strMenu.equals("tpak_prov") || strMenu.equals("naker_lu_jk") || strMenu.equals("naker_lu_wilayah")
                || strMenu.equals("naker_formal_prov") ||
                strMenu.equals("naker_pendidikan_prov") || strMenu.equals("naker_setengah_prov")
                || strMenu.equals("ipm_komponen_prov") || strMenu.equals("ipm_status") ||
                strMenu.equals("iup") ||
                strMenu.contains("pdrb_kab_") || strMenu.equals("ipm_kab") ||
                strMenu.equals("ipm_prov_series") || strMenu.equals("ipm_perbandingan_prov") ||
                strMenu.contains("skd")) {
            showMenu = true;
        }

        if (showMenu) {
            boolean isIpmSeries = strMenu.equals("ipm_prov_series") || strMenu.equals("ipm_perbandingan_prov");
            boolean isIpmKab = strMenu.equals("ipm_kab");
            boolean isSkd = strMenu.contains("skd");

            if (isIpmSeries || isIpmKab || isSkd) {
                // IPM Customization: Use dedicated buttons, hide 3-dots
                binding.btnMenu.setVisibility(View.GONE);
                binding.btnSeriesAction.setVisibility(View.GONE);
                binding.btnExcelHeader.setVisibility(View.VISIBLE);
                binding.btnShareHeader.setVisibility(View.VISIBLE);

                binding.btnExcelHeader.setOnClickListener(v -> {
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                        createExcel();
                    } else {
                        if (checkSelfPermission(Manifest.permission.WRITE_EXTERNAL_STORAGE) == PackageManager.PERMISSION_GRANTED) {
                            createExcel();
                        } else {
                            ActivityCompat.requestPermissions(ListKonten.this,
                                    new String[]{Manifest.permission.WRITE_EXTERNAL_STORAGE}, 1);
                        }
                    }
                });

                binding.btnShareHeader.setOnClickListener(v -> shareData());

                if (isIpmKab || (isSkd && !strMenu.equals("skd_prov_ann"))) {
                    binding.llYearContainer.setVisibility(View.VISIBLE);
                    binding.btnYearSelector.setOnClickListener(v -> showYearPickerBottomSheet());

                    // Trigger year fetch to populate selector
                    if (strTabelDB != null && !strTabelDB.isEmpty()) {
                        viewModel.setTahun(strTabelDB, strUrl);
                    }
                } else {
                    binding.llYearContainer.setVisibility(View.GONE);
                }

            } else {
                // Regular logic for other menus
                binding.btnExcelHeader.setVisibility(View.GONE);
                binding.btnShareHeader.setVisibility(View.GONE);
                binding.llYearContainer.setVisibility(View.GONE);

                // 1. Determine Series Availability
                boolean hasSeries = strMenu.equals("inflasi_prov") || strMenu.equals("inflasi_kelompok")
                        || strMenu.equals("inflasi_penyumbang")
                        || strMenu.equals("ntp_prov") ||
                        strMenu.equals("ntp_penyumbang") || strMenu.equals("ntp_prov_jawa") || strMenu.equals("ntup")
                        || strMenu.equals("ekspor_pertumbuhan") ||
                        strMenu.equals("ekspor_negara") || strMenu.equals("ekspor_migas")
                        || strMenu.equals("impor_pertumbuhan")
                        || strMenu.equals("impor_negara") ||
                        strMenu.equals("impor_migas") || strMenu.equals("neraca") || strMenu.equals("miskin_prov")
                        || strMenu.equals("gini_ratio_prov") ||
                        strMenu.equals("tpak_prov") || strMenu.equals("naker_lu_jk") || strMenu.equals("naker_lu_wilayah")
                        || strMenu.equals("naker_formal_prov") ||
                        strMenu.equals("naker_pendidikan_prov") || strMenu.equals("naker_setengah_prov")
                        || strMenu.equals("ipm_komponen_prov") || strMenu.equals("ipm_status") ||
                        strMenu.equals("iup");

                // 2. Determine Menu Availability (Excel, Tahun, etc)
                boolean hasMenu = false;
                // Excel is available for Series items (except specific ones), pdrb_kab, etc.
                if ((hasSeries && !strMenu.equals("ekspor_negara") && !strMenu.equals("impor_negara")
                        && !strMenu.equals("iup")) ||
                        strMenu.contains("pdrb_kab_")) {
                    hasMenu = true;
                }
                // Tahun is available for pdrb_kab
                if (strMenu.contains("pdrb_kab_")) {
                    hasMenu = true;
                }

                // 3. Setup Series Button (Priority)
                if (hasSeries) {
                    binding.btnSeriesAction.setVisibility(View.VISIBLE);
                    binding.btnMenu.setVisibility(View.GONE); // Hide 3-dots as requested

                    binding.btnSeriesAction.setOnClickListener(v -> {
                        String intentMenu = strMenu;
                        if (strMenu.equals("ipm_komponen_prov"))
                            intentMenu = "ipm_komponen_prov_series";
                        if (strMenu.equals("ipm_status"))
                            intentMenu = "ipm_status_series";
                        if (strMenu.equals("inflasi_prov"))
                            intentMenu = "inflasi_prov_series";
                        if (strMenu.equals("iup"))
                            intentMenu = "rpjpn";
                        if (strMenu.equals("gini_ratio_prov"))
                            intentMenu = "gini_ratio_prov_series";

                        Intent intent = new Intent(ListKonten.this, DetailKonten.class);
                        intent.putExtra("menu", intentMenu);
                        intent.putExtra("lang", strLang);
                        startActivity(intent);
                    });
                } else if (hasMenu) {
                    // 4. Setup Menu Button (Fallback if no Series)
                    binding.btnSeriesAction.setVisibility(View.GONE);
                    binding.btnMenu.setVisibility(View.VISIBLE);
                    binding.btnMenu.setImageResource(R.drawable.ic_more);
                    binding.btnMenu.setOnClickListener(this::showPopupMenu);
                } else {
                    binding.btnSeriesAction.setVisibility(View.GONE);
                    binding.btnMenu.setVisibility(View.GONE);
                    binding.btnMenu.setOnClickListener(null);
                }
            }
        } else {
            binding.btnMenu.setVisibility(View.GONE);
            binding.btnSeriesAction.setVisibility(View.GONE);
            binding.btnExcelHeader.setVisibility(View.GONE);
            binding.btnShareHeader.setVisibility(View.GONE);
            binding.llYearContainer.setVisibility(View.GONE);
            binding.btnMenu.setOnClickListener(null);
        }

        // --- MANUAL TRANSLATION FOR STATIC UI ELEMENTS ---
        if (strLang != null && strLang.equals("en")) {
            // Series Button Text (It's inside a LinearLayout, child at index 0 is TextView)
            TextView tvSeries = (TextView) binding.btnSeriesAction.getChildAt(0);
            if (tvSeries != null)
                tvSeries.setText(getString(R.string.series));
            // Note: R.string.series is "Series" (translatable=false), but if we want
            // "SERIES" vs "SERIES" it matches.

            // Analysis Title
            binding.tvAnalysisTitle.setText(getString(R.string.analisis_ringkas_en));

            // IUP Header Title
            binding.tvIupTitle.setText(getString(R.string.iup_title_en));
            binding.tvIupSubtitle.setText(getString(R.string.iup_subtitle_en));
        } else {
            // Default Indonesian
            binding.tvAnalysisTitle.setText(getString(R.string.analisis_ringkas));
            binding.tvIupTitle.setText(getString(R.string.iup_title));
            binding.tvIupSubtitle.setText(getString(R.string.iup_subtitle));
        }
    }

    private void showYearPickerBottomSheet() {
        if (listTahun == null || listTahun.isEmpty()) {
            if (strTabelDB != null && !strTabelDB.isEmpty()) {
                viewModel.setTahun(strTabelDB, strUrl);
            }
            new classFungsi(this, "Memuat data tahun...").TampilkanSnackBar();
            return;
        }

        com.google.android.material.bottomsheet.BottomSheetDialog bottomSheetDialog = new com.google.android.material.bottomsheet.BottomSheetDialog(this);
        android.widget.ListView listView = new android.widget.ListView(this);
        String[] years = new String[listTahun.size()];
        for (int i = 0; i < listTahun.size(); i++) {
            years[i] = listTahun.get(i).getTahun();
        }

        android.widget.ArrayAdapter<String> adapterYears = new android.widget.ArrayAdapter<>(this, android.R.layout.simple_list_item_1, years);
        listView.setAdapter(adapterYears);

        listView.setOnItemClickListener((parent, view, position, id) -> {
            String selectedYear = years[position];
            binding.btnYearSelector.setText(selectedYear + " ▼");
            isInitialYearSet = true; // Prevent observer from overwriting
            binding.prgBar.setVisibility(View.VISIBLE);
            modelDataList.clear();
            setData(selectedYear);
            bottomSheetDialog.dismiss();
        });

        bottomSheetDialog.setContentView(listView);
        bottomSheetDialog.show();
    }

    private void shareData() {
        if (modelDataList == null || modelDataList.isEmpty()) {
            new classFungsi(this, "Data belum tersedia").TampilkanSnackBar();
            return;
        }

        try {
            StringBuilder caption = new StringBuilder();
            caption.append("\uD83D\uDCCA *").append(strJudul).append("*\n\n");

            for (modelData data : modelDataList) {
                if (data instanceof modelHeader3) {
                    modelHeader3 h = (modelHeader3) data;
                    if (!h.getKolom1().isEmpty()) caption.append("*").append(h.getKolom1()).append("*\n");
                    if (!h.getKolom2().isEmpty() || !h.getKolom3().isEmpty()) {
                        caption.append(h.getKolom2()).append(" | ").append(h.getKolom3()).append("\n");
                    }
                } else if (data instanceof modelData3) {
                    modelData3 d = (modelData3) data;
                    caption.append(d.getKolom1()).append(": ").append(d.getKolom2());
                    if (!d.getKolom3().isEmpty()) caption.append(" (").append(d.getKolom3()).append(")");
                    caption.append("\n");
                }
            }

            String footer = (strLang.equals("en")) ? getString(R.string.share_desc_en) : getString(R.string.share_desc);
            caption.append("\n\uD83D\uDCF2 *").append(footer).append("*");

            Intent intent = new Intent(Intent.ACTION_SEND);
            intent.setType("text/plain");
            intent.putExtra(Intent.EXTRA_TEXT, caption.toString());
            startActivity(Intent.createChooser(intent, "Bagikan via"));
        } catch (Exception e) {
            new classFungsi(this, "Gagal memproses data: " + e.getMessage()).TampilkanSnackBar();
        }
    }

    private void showPopupMenu(View view) {
        android.widget.PopupMenu popup = new android.widget.PopupMenu(this, view);

        // Logic from onCreateOptionsMenu
        if (strMenu.equals("inflasi_prov") || strMenu.equals("inflasi_kelompok") || strMenu.equals("inflasi_penyumbang")
                || strMenu.equals("ntp_prov") ||
                strMenu.equals("ntp_penyumbang") || strMenu.equals("ntp_prov_jawa") || strMenu.equals("ntup")
                || strMenu.equals("ekspor_pertumbuhan") ||
                strMenu.equals("ekspor_negara") || strMenu.equals("ekspor_migas") || strMenu.equals("impor_pertumbuhan")
                || strMenu.equals("impor_negara") ||
                strMenu.equals("impor_migas") || strMenu.equals("neraca") || strMenu.equals("miskin_prov")
                || strMenu.equals("gini_ratio_prov") ||
                strMenu.equals("tpak_prov") || strMenu.equals("naker_lu_jk") || strMenu.equals("naker_lu_wilayah")
                || strMenu.equals("naker_formal_prov") ||
                strMenu.equals("naker_pendidikan_prov") || strMenu.equals("naker_setengah_prov")
                || strMenu.equals("ipm_komponen_prov") || strMenu.equals("ipm_status") ||
                strMenu.equals("iup")) {
            popup.getMenuInflater().inflate(R.menu.konten_menu, popup.getMenu());

            if (!strMenu.equals("ekspor_negara") && !strMenu.equals("impor_negara") && !strMenu.equals("iup")) {
                MenuItem menuItem = popup.getMenu().findItem(R.id.menuExcel);
                menuItem.setVisible(true);
            }
        } else if (strMenu.contains("pdrb_kab_") || strMenu.equals("ipm_kab")) {
            popup.getMenuInflater().inflate(R.menu.konten_menu, popup.getMenu());
            MenuItem menuItem = popup.getMenu().findItem(R.id.menuTahun);
            menuItem.setVisible(true);
            menuItem = popup.getMenu().findItem(R.id.menuExcel);
            menuItem.setVisible(true);
        } else if (strMenu.equals("ipm_prov_series") || strMenu.equals("ipm_perbandingan_prov")) {
            popup.getMenuInflater().inflate(R.menu.konten_menu, popup.getMenu());
            MenuItem menuItem = popup.getMenu().findItem(R.id.menuExcel);
            menuItem.setVisible(true);
        } else {
            // Fallback for simple list view if needed, or don't show popup
            return;
        }

        // Logic from onOptionsItemSelected
        popup.setOnMenuItemClickListener(item -> {
            int id = item.getItemId();
            if (id == R.id.menuTahun) {
                Intent intent = new Intent(ListKonten.this, tahunActivity.class);
                intent.putExtra("tabel", strTabelDB);
                intent.putExtra("menu", strMenu);
                intent.putExtra("lang", strLang);
                startTahun.launch(intent);
                return true;
            } else if (id == R.id.menuExcel) {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                    createExcel();
                } else {
                    if (checkSelfPermission(Manifest.permission.WRITE_EXTERNAL_STORAGE) == PackageManager.PERMISSION_GRANTED) {
                        createExcel();
                    } else {
                        ActivityCompat.requestPermissions(ListKonten.this,
                                new String[] { Manifest.permission.WRITE_EXTERNAL_STORAGE }, 1);
                    }
                }
                return true;
            }
            return false;
        });

        popup.show();
    }

    private void setMenu() {
        switch (strMenu) {
            case "inflasi":
                modelDataList.add(new modelMenu("inflasi_prov", getString(R.string.inflasi_prov), strLang));
                modelDataList.add(new modelMenu("inflasi_kelompok", getString(R.string.inflasi_kelompok), strLang));
                modelDataList.add(new modelMenu("inflasi_penyumbang", getString(R.string.inflasi_penyumbang), strLang));
                modelDataList.add(new modelMenu("inflasi_6_kota", getString(R.string.inflasi_6_kota), strLang));
                modelDataList.add(new modelMenu("inflasi_ibu_kota", getString(R.string.inflasi_ibu_kota), strLang));
                break;
            case "ntp":
                modelDataList.add(new modelMenu("ntp_prov", getString(R.string.ntp_prov), strLang));
                modelDataList.add(new modelMenu("ntp_penyumbang", getString(R.string.ntp_penyumbang), strLang));
                modelDataList.add(new modelMenu("ntp_prov_jawa", getString(R.string.ntp_prov_jawa), strLang));
                modelDataList.add(new modelMenu("ntup", getString(R.string.NTUP), strLang));
                modelDataList.add(new modelMenu("ntp_series", getString(R.string.ntp_series), strLang));
                break;
            case "ekspor":
                modelDataList.add(new modelMenu("ekspor_komoditas", getString(R.string.ekspor_komoditas), strLang));
                modelDataList.add(new modelMenu("ekspor_pertumbuhan", getString(R.string.ekspor_pertumbuhan), strLang));
                modelDataList.add(new modelMenu("ekspor_negara", getString(R.string.ekspor_negara), strLang));
                modelDataList.add(new modelMenu("ekspor_migas", getString(R.string.ekspor_migas), strLang));
                break;
            case "impor":
                modelDataList.add(new modelMenu("impor_komoditas", getString(R.string.impor_komoditas), strLang));
                modelDataList.add(new modelMenu("impor_pertumbuhan", getString(R.string.impor_pertumbuhan), strLang));
                modelDataList.add(new modelMenu("impor_negara", getString(R.string.impor_negara), strLang));
                modelDataList.add(new modelMenu("impor_migas", getString(R.string.impor_migas), strLang));
                break;
            case "pdrb_prov":
                modelDataList.add(new modelHeader3(getString(R.string.pdrb), "", ""));
                modelDataList.add(new modelMenu("pdrb_lu_nominal", getString(R.string.pdrb_lu_nominal), strLang));
                modelDataList
                        .add(new modelMenu("pdrb_lu_pertumbuhan", getString(R.string.pdrb_lu_pertumbuhan), strLang));
                modelDataList.add(new modelMenu("pdrb_lu_distribusi", getString(R.string.pdrb_lu_distribusi), strLang));
                modelDataList.add(new modelMenu("pdrb_lu_sumber", getString(R.string.pdrb_lu_sumber), strLang));
                modelDataList.add(new modelHeader3(getString(R.string.pdrb_pengeluaran), "", ""));
                modelDataList.add(new modelMenu("pdrb_pengeluaran_nominal",
                        getString(R.string.pdrb_pengeluaran_nominal), strLang));
                modelDataList.add(new modelMenu("pdrb_pengeluaran_pertumbuhan",
                        getString(R.string.pdrb_pengeluaran_pertumbuhan), strLang));
                modelDataList.add(new modelMenu("pdrb_pengeluaran_distribusi",
                        getString(R.string.pdrb_pengeluaran_distribusi), strLang));
                modelDataList.add(
                        new modelMenu("pdrb_pengeluaran_sumber", getString(R.string.pdrb_pengeluaran_sumber), strLang));
                modelDataList.add(new modelHeader3(getString(R.string.pdrb_kab), "", ""));
                modelDataList.add(new modelMenu("pdrb_kab_nominal", getString(R.string.pdrb_kab_nominal), strLang));
                modelDataList
                        .add(new modelMenu("pdrb_kab_pertumbuhan", getString(R.string.pdrb_kab_pertumbuhan), strLang));
                modelDataList
                        .add(new modelMenu("pdrb_kab_distribusi", getString(R.string.pdrb_kab_distribusi), strLang));
                modelDataList.add(new modelMenu("pdrb_kab_perkapita", getString(R.string.pdrb_kab_perkapita), strLang));
                break;
            case "miskin":
                modelDataList.add(new modelMenu("miskin_prov", getString(R.string.miskin_prov), strLang));
                modelDataList.add(new modelMenu("miskin_kab", getString(R.string.miskin_kab), strLang));
                break;
            case "gini_ratio":
                modelDataList.add(new modelMenu("gini_ratio_prov", getString(R.string.gini_ratio_prov), strLang));
                modelDataList.add(
                        new modelMenu("gini_ratio_prov_series", getString(R.string.gini_ratio_prov_series), strLang));
                break;
            case "naker":
                modelDataList.add(new modelHeader3(getString(R.string.prov), "", ""));
                modelDataList.add(new modelMenu("tpak_prov", getString(R.string.tpak_prov), strLang));
                modelDataList.add(new modelMenu("naker_lu_jk", getString(R.string.naker_lu_jk), strLang));
                modelDataList.add(new modelMenu("naker_lu_wilayah", getString(R.string.naker_lu_wilayah), strLang));
                modelDataList.add(new modelMenu("naker_formal_prov", getString(R.string.naker_formal_prov), strLang));
                modelDataList.add(
                        new modelMenu("naker_pendidikan_prov", getString(R.string.naker_pendidikan_prov), strLang));
                modelDataList
                        .add(new modelMenu("naker_setengah_prov", getString(R.string.naker_setengah_prov), strLang));
                modelDataList.add(new modelHeader3(getString(R.string.kab), "", ""));
                modelDataList.add(new modelMenu("tpak_kab", getString(R.string.tpak_kab), strLang));
                break;
            case "ipm":
                modelDataList.add(new modelHeader3(getString(R.string.prov), "", ""));
                modelDataList.add(new modelMenu("ipm_prov_series", getString(R.string.ipm_prov_series), strLang));
                modelDataList.add(new modelMenu("ipm_komponen_prov", getString(R.string.ipm_komponen_prov), strLang));
                modelDataList.add(
                        new modelMenu("ipm_perbandingan_prov", getString(R.string.ipm_perbandingan_prov), strLang));
                modelDataList.add(new modelHeader3(getString(R.string.kab), "", ""));
                modelDataList.add(new modelMenu("ipm_kab", getString(R.string.ipm_kab), strLang));
                modelDataList.add(new modelMenu("ipm_komponen_kab", getString(R.string.ipm_komponen_kab), strLang));
                modelDataList.add(new modelMenu("ipm_status", getString(R.string.ipm_status), strLang));
                break;
            case "skm":
                modelDataList.add(new modelHeader3(getString(R.string.ikk_kab_ann), "", ""));
                modelDataList.add(new modelMenu("skd_prov_ann", getString(R.string.skd_prov), strLang));
                modelDataList.add(new modelMenu("skd_kab_ann", getString(R.string.skd_kab), strLang));
                modelDataList.add(new modelHeader3(getString(R.string.ikk_kab), "", ""));
                modelDataList.add(new modelMenu("skd_prov", getString(R.string.skd_prov), strLang));
                modelDataList.add(new modelMenu("skd_kab", getString(R.string.skd_kab), strLang));
                modelDataList.add(new modelHeader3(getString(R.string.ikk_kab_smt), "", ""));
                modelDataList.add(new modelMenu("skd_prov_smt", getString(R.string.skd_prov), strLang));
                modelDataList.add(new modelMenu("skd_kab_smt", getString(R.string.skd_kab), strLang));
                break;
            case "iup":
                // IUP uses data from API
                setData(Integer.toString(java.util.Calendar.getInstance().get(java.util.Calendar.YEAR)));
                return; // Don't call notifyDataSetChanged yet, setData will handle it
        }
        setTitle(strIndikator);
        adapter.notifyDataSetChanged();
        binding.prgBar.setVisibility(View.INVISIBLE);
    }

    private void setMenuEn() {
        switch (strMenu) {
            case "inflasi":
                modelDataList.add(new modelMenu("inflasi_prov", getString(R.string.inflasi_prov_en), strLang));
                modelDataList.add(new modelMenu("inflasi_kelompok", getString(R.string.inflasi_kelompok_en), strLang));
                modelDataList
                        .add(new modelMenu("inflasi_penyumbang", getString(R.string.inflasi_penyumbang_en), strLang));
                modelDataList.add(new modelMenu("inflasi_6_kota", getString(R.string.inflasi_6_kota_en), strLang));
                modelDataList.add(new modelMenu("inflasi_ibu_kota", getString(R.string.inflasi_ibu_kota_en), strLang));
                break;
            case "ntp":
                modelDataList.add(new modelMenu("ntp_prov", getString(R.string.ntp_prov_en), strLang));
                modelDataList.add(new modelMenu("ntp_penyumbang", getString(R.string.ntp_penyumbang_en), strLang));
                modelDataList.add(new modelMenu("ntp_prov_jawa", getString(R.string.ntp_prov_jawa_en), strLang));
                modelDataList.add(new modelMenu("ntup", getString(R.string.NTUP), strLang));
                modelDataList.add(new modelMenu("ntp_series", getString(R.string.ntp_series_en), strLang));
                break;
            case "ekspor":
                modelDataList.add(new modelMenu("ekspor_komoditas", getString(R.string.ekspor_komoditas_en), strLang));
                modelDataList
                        .add(new modelMenu("ekspor_pertumbuhan", getString(R.string.ekspor_pertumbuhan_en), strLang));
                modelDataList.add(new modelMenu("ekspor_negara", getString(R.string.ekspor_negara_en), strLang));
                modelDataList.add(new modelMenu("ekspor_migas", getString(R.string.ekspor_migas_en), strLang));
                break;
            case "impor":
                modelDataList.add(new modelMenu("impor_komoditas", getString(R.string.impor_komoditas_en), strLang));
                modelDataList
                        .add(new modelMenu("impor_pertumbuhan", getString(R.string.impor_pertumbuhan_en), strLang));
                modelDataList.add(new modelMenu("impor_negara", getString(R.string.impor_negara_en), strLang));
                modelDataList.add(new modelMenu("impor_migas", getString(R.string.impor_migas_en), strLang));
                break;
            case "pdrb_prov":
                modelDataList.add(new modelHeader3(getString(R.string.pdrb_en), "", ""));
                modelDataList.add(new modelMenu("pdrb_lu_nominal", getString(R.string.pdrb_lu_nominal_en), strLang));
                modelDataList
                        .add(new modelMenu("pdrb_lu_pertumbuhan", getString(R.string.pdrb_lu_pertumbuhan_en), strLang));
                modelDataList
                        .add(new modelMenu("pdrb_lu_distribusi", getString(R.string.pdrb_lu_distribusi_en), strLang));
                modelDataList.add(new modelMenu("pdrb_lu_sumber", getString(R.string.pdrb_lu_sumber_en), strLang));
                modelDataList.add(new modelHeader3(getString(R.string.pdrb_pengeluaran_en), "", ""));
                modelDataList.add(new modelMenu("pdrb_pengeluaran_nominal",
                        getString(R.string.pdrb_pengeluaran_nominal_en), strLang));
                modelDataList.add(new modelMenu("pdrb_pengeluaran_pertumbuhan",
                        getString(R.string.pdrb_pengeluaran_pertumbuhan_en), strLang));
                modelDataList.add(new modelMenu("pdrb_pengeluaran_distribusi",
                        getString(R.string.pdrb_pengeluaran_distribusi_en), strLang));
                modelDataList.add(new modelMenu("pdrb_pengeluaran_sumber",
                        getString(R.string.pdrb_pengeluaran_sumber_en), strLang));
                modelDataList.add(new modelHeader3(getString(R.string.pdrb_kab_en), "", ""));
                modelDataList.add(new modelMenu("pdrb_kab_nominal", getString(R.string.pdrb_kab_nominal_en), strLang));
                modelDataList.add(
                        new modelMenu("pdrb_kab_pertumbuhan", getString(R.string.pdrb_kab_pertumbuhan_en), strLang));
                modelDataList
                        .add(new modelMenu("pdrb_kab_distribusi", getString(R.string.pdrb_kab_distribusi_en), strLang));
                modelDataList
                        .add(new modelMenu("pdrb_kab_perkapita", getString(R.string.pdrb_kab_perkapita_en), strLang));
                break;
            case "miskin":
                modelDataList.add(new modelMenu("miskin_prov", getString(R.string.miskin_prov_en), strLang));
                modelDataList.add(new modelMenu("miskin_kab", getString(R.string.miskin_kab_en), strLang));
                break;
            case "gini_ratio":
                modelDataList.add(new modelMenu("gini_ratio_prov", getString(R.string.gini_ratio_prov), strLang));
                modelDataList.add(
                        new modelMenu("gini_ratio_prov_series", getString(R.string.gini_ratio_prov_series), strLang));
                break;
            case "naker":
                modelDataList.add(new modelHeader3(getString(R.string.prov_en), "", ""));
                modelDataList.add(new modelMenu("tpak_prov", getString(R.string.tpak_prov_en), strLang));
                modelDataList.add(new modelMenu("naker_lu_jk", getString(R.string.naker_lu_jk_en), strLang));
                modelDataList.add(new modelMenu("naker_lu_wilayah", getString(R.string.naker_lu_wilayah_en), strLang));
                modelDataList
                        .add(new modelMenu("naker_formal_prov", getString(R.string.naker_formal_prov_en), strLang));
                modelDataList.add(
                        new modelMenu("naker_pendidikan_prov", getString(R.string.naker_pendidikan_prov_en), strLang));
                modelDataList
                        .add(new modelMenu("naker_setengah_prov", getString(R.string.naker_setengah_prov_en), strLang));
                modelDataList.add(new modelHeader3(getString(R.string.kab_en), "", ""));
                modelDataList.add(new modelMenu("tpak_kab", getString(R.string.tpak_kab_en), strLang));
                break;
            case "ipm":
                modelDataList.add(new modelHeader3(getString(R.string.prov_en), "", ""));
                modelDataList.add(new modelMenu("ipm_prov_series", getString(R.string.ipm_prov_series_en), strLang));
                modelDataList
                        .add(new modelMenu("ipm_komponen_prov", getString(R.string.ipm_komponen_prov_en), strLang));
                modelDataList.add(
                        new modelMenu("ipm_perbandingan_prov", getString(R.string.ipm_perbandingan_prov_en), strLang));
                modelDataList.add(new modelHeader3(getString(R.string.kab_en), "", ""));
                modelDataList.add(new modelMenu("ipm_kab", getString(R.string.ipm_kab_en), strLang));
                modelDataList.add(new modelMenu("ipm_komponen_kab", getString(R.string.ipm_komponen_kab_en), strLang));
                modelDataList.add(new modelMenu("ipm_status", getString(R.string.ipm_status_en), strLang));
                break;
            case "skm":
                modelDataList.add(new modelHeader3(getString(R.string.ikk_kab_ann_en), "", ""));
                modelDataList.add(new modelMenu("skd_prov_ann", getString(R.string.skd_prov_en), strLang));
                modelDataList.add(new modelMenu("skd_kab_ann", getString(R.string.skd_kab_en), strLang));
                modelDataList.add(new modelHeader3(getString(R.string.ikk_kab_en), "", ""));
                modelDataList.add(new modelMenu("skd_prov", getString(R.string.skd_prov_en), strLang));
                modelDataList.add(new modelMenu("skd_kab", getString(R.string.skd_kab_en), strLang));
                modelDataList.add(new modelHeader3(getString(R.string.ikk_kab_smt_en), "", ""));
                modelDataList.add(new modelMenu("skd_prov_smt", getString(R.string.skd_prov_en), strLang));
                modelDataList.add(new modelMenu("skd_kab_smt", getString(R.string.skd_kab_en), strLang));
                break;
            case "iup":
                // IUP uses data from API
                setData(Integer.toString(java.util.Calendar.getInstance().get(java.util.Calendar.YEAR)));
                return; // Don't call notifyDataSetChanged yet, setData will handle it
        }
        setTitle(strIndikator);
        adapter.notifyDataSetChanged();
        binding.prgBar.setVisibility(View.INVISIBLE);
    }

    private void createExcel() {
        HSSFRow rowData;
        HSSFWorkbook workbook = new HSSFWorkbook();
        HSSFSheet sheet = workbook.createSheet(strMenu);
        int indexRow = 0;
        for (int i = 0; i < modelDataList.size(); i++) {
            if (modelDataList.get(i) instanceof modelHeader3) {
                modelHeader3 modelHeader3 = (modelHeader3) modelDataList.get(i);
                if (modelHeader3.getKolom2().isEmpty() && modelHeader3.getKolom3().isEmpty()) {
                    sheet.addMergedRegion(new CellRangeAddress(indexRow, indexRow, 0, 2));
                    rowData = sheet.createRow(indexRow);
                    rowData.createCell(0).setCellValue(modelHeader3.getKolom1());
                } else {
                    rowData = sheet.createRow(indexRow);
                    rowData.createCell(0).setCellValue(modelHeader3.getKolom1());
                    rowData.createCell(1).setCellValue(modelHeader3.getKolom2());
                    rowData.createCell(2).setCellValue(modelHeader3.getKolom3());
                }
                indexRow++;
            } else if (modelDataList.get(i) instanceof modelData3) {
                modelData3 modelData3 = (modelData3) modelDataList.get(i);
                rowData = sheet.createRow(indexRow);
                rowData.createCell(0).setCellValue(modelData3.getKolom1());
                rowData.createCell(1).setCellValue(modelData3.getKolom2());
                rowData.createCell(2).setCellValue(modelData3.getKolom3());
                indexRow++;
            }
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
            new classFungsi(ListKonten.this, getString(R.string.export_selesai),
                    finalJudul, ".xls").TampilkanSnackBarOpenFile();
        } catch (Exception ignored) {

        }
    }

    public void processFinish(modelResponse3 modelResponse3) {
        List<modelIsi3> isis;
        try {
            strTabelDB = modelResponse3.getTabel();
            if (strTabelDB != null && !strTabelDB.isEmpty() && (strMenu.equals("ipm_kab") || strMenu.contains("skd"))) {
                viewModel.setTahun(strTabelDB, strUrl);
            }
            isis = modelResponse3.getData();
            String kolom = modelResponse3.getKolom();
            kolom1[0] = kolom.substring(0, kolom.indexOf(":"));
            kolom1[1] = kolom.substring(kolom.indexOf(":") + 1, kolom.lastIndexOf(":"));
            kolom1[2] = kolom.substring(kolom.lastIndexOf(":") + 1);
            strJudul = modelResponse3.getJudul();
            if (strMenu.equals("inflasi_penyumbang") || strMenu.equals("ntp_prov")
                    || strMenu.equals("ekspor_pertumbuhan")
                    || strMenu.equals("impor_pertumbuhan") || strMenu.equals("miskin_prov")
                    || strMenu.equals("tpak_prov")
                    || strMenu.equals("naker_formal_prov") || strMenu.equals("naker_pendidikan_prov")
                    || strMenu.equals("naker_setengah_prov")) {
                String[] arrJudul = new String[0];
                int indexArr = 0;
                int intMenu = 0;
                switch (strMenu) {
                    case "inflasi_penyumbang":
                    case "ekspor_pertumbuhan":
                    case "impor_pertumbuhan":
                        intMenu = 4;
                        break;
                    case "ntp_prov":
                        intMenu = 2;
                        break;
                    case "miskin_prov":
                        intMenu = 3;
                        arrJudul = strJudul.split(":");
                        break;
                    case "tpak_prov":
                    case "naker_formal_prov":
                    case "naker_setengah_prov":
                        intMenu = 1;
                        arrJudul = strJudul.split(":");
                        break;
                    case "naker_pendidikan_prov":
                        intMenu = 5;
                        break;
                    default:
                        break;
                }
                modelDataList.add(new modelHeader3(strJudul.substring(0, strJudul.indexOf(":")), "", ""));
                if (!strMenu.equals("miskin_prov")) {
                    if (!strMenu.equals("naker_pendidikan_prov")) {
                        modelDataList.add(new modelHeader3(kolom1[0], kolom1[1], kolom1[2]));
                    } else {
                        modelDataList.add(new modelHeader3(kolom1[0], kolom1[1].substring(0, kolom1[1].indexOf("x")),
                                kolom1[2].substring(0, kolom1[2].indexOf("x"))));
                    }
                }
                for (int i = 0; i < modelResponse3.getData().size(); i++) {
                    modelDataList
                            .add(new modelData3(isis.get(i).getIsi1(), isis.get(i).getIsi2(), isis.get(i).getIsi3()));
                    if (strMenu.equals("miskin_prov")) {
                        if (i >= intMenu - 1 && i != isis.size() - 1) {
                            if (i % intMenu == 2) {
                                indexArr = indexArr + 1;
                                modelDataList.add(new modelHeader3(arrJudul[indexArr], "", ""));
                            }
                        }
                    } else if (strMenu.equals("tpak_prov") || strMenu.equals("naker_setengah_prov")
                            || strMenu.equals("trans_sosial")) {
                        if (i == intMenu - 1) {
                            modelDataList.add(new modelHeader3(arrJudul[1], "", ""));
                            modelDataList.add(new modelHeader3(kolom1[0], kolom1[1], kolom1[2]));
                        } else if (i == 2) {
                            modelDataList.add(new modelHeader3(arrJudul[2], "", ""));
                            modelDataList.add(new modelHeader3(kolom1[0], kolom1[1], kolom1[2]));
                        }
                    } else {
                        if (i == intMenu) {
                            modelDataList
                                    .add(new modelHeader3(strJudul.substring(strJudul.lastIndexOf(":") + 1), "", ""));
                            if (!strMenu.equals("naker_pendidikan_prov")) {
                                modelDataList.add(new modelHeader3(kolom1[0], kolom1[1], kolom1[2]));
                            } else {
                                modelDataList.add(
                                        new modelHeader3(kolom1[0], kolom1[1].substring(kolom1[1].lastIndexOf("x") + 1),
                                                kolom1[2].substring(kolom1[2].lastIndexOf("x") + 1)));
                            }
                        }
                    }
                }
            } else if (strMenu.equals("ekspor_negara") || strMenu.equals("impor_negara")) {
                modelDataList.add(new modelHeader3(modelResponse3.getJudul(), "", ""));
                for (int i = 0; i < isis.size(); i++) {
                    modelDataList.add(new modelDataDashboard(isis.get(i).getNegara(), isis.get(i).getDeskripsi(), "",
                            "", isis.get(i).getNilai(), "", isis.get(i).getPoin(), "", "", "", strLang));
                }
            } else {
                modelDataList.add(new modelHeader3(strJudul, "", ""));
                modelDataList.add(new modelHeader3(kolom1[0], kolom1[1], kolom1[2]));
                for (int i = 0; i < isis.size(); i++) {
                    String label = isis.get(i).getIsi1();
                    String isi2 = isis.get(i).getIsi2();
                    // Manual Translation for IUP if API fails to translate
                    if (strMenu.equals("iup") && !strLang.equals("in")) {
                        isi2 = translateIup(isi2);
                    }
                    modelDataList
                            .add(new modelData3(label, isi2, isis.get(i).getIsi3()));
                }
            }
            adapter.notifyDataSetChanged();
            binding.prgBar.setVisibility(View.INVISIBLE);
        } catch (Exception e) {
            new classFungsi(ListKonten.this, e.toString()).TampilkanSnackBar();
        }

    }

    private String translateIup(String indo) {
        if (indo == null)
            return "";
        String lower = indo.toLowerCase();
        // Specific matches first (more specific patterns before general ones)
        if (lower.contains("rasio pdrb industri pengolahan"))
            return "Manufacturing GRDP Ratio (%)";
        if (lower.contains("rasio pdrb penyediaan akomodasi"))
            return "Accommodation, Food and\nBeverage GRDP Ratio (%)";
        if (lower.contains("pembentukan modal tetap"))
            return "Gross Fixed Capital\nFormation (% GRDP)";
        if (lower.contains("ekspor barang dan jasa"))
            return "Export of Goods and\nServices (% GRDP)";
        if (lower.contains("proporsi kontribusi pdrb"))
            return "Metropolitan Area GRDP\nContribution to National (%)";
        if (lower.contains("ketimpangan gender") || lower.contains("ikg"))
            return "Gender Inequality\nIndex (GII)";
        if (lower.contains("inflasi"))
            return "Inflation (y-on-y)";
        if (lower.contains("pertumbuhan ekonomi"))
            return "Economic Growth (c-to-c)";
        if (lower.contains("kemiskinan"))
            return "Poverty Percentage";
        if (lower.contains("gini"))
            return "Gini Ratio";
        if (lower.contains("ipm") || lower.contains("pembangunan manusia"))
            return "Human Development Index (HDI)";
        if (lower.contains("pengangguran"))
            return "Open Unemployment Rate (TPT)";
        if (lower.contains("ntp"))
            return "Farmer Terms of Trade (NTP)";
        if (lower.contains("ekspor"))
            return "Export Value";
        if (lower.contains("impor"))
            return "Import Value";
        if (lower.contains("luas panen"))
            return "Harvested Area of Rice";
        if (lower.contains("produksi padi"))
            return "Rice Production";
        if (lower.contains("wisatawan") || lower.contains("tingkat penghunian"))
            return "Room Occupancy Rate";
        if (lower.contains("penduduk"))
            return "Total Population";
        if (lower.contains("pdrb per kapita"))
            return "GRDP per Capita";
        if (lower.contains("angka harapan hidup"))
            return "Life Expectancy";
        if (lower.contains("rata-rata lama sekolah"))
            return "Mean Years of Schooling";
        if (lower.contains("harapan lama sekolah"))
            return "Expected Years of Schooling";
        if (lower.contains("pengeluaran per kapita"))
            return "Expenditure per Capita";
        return indo;
    }
}
