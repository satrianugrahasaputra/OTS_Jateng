package com.ionicframework.otsjateng.utilities;

import static android.view.View.GONE;
import static android.view.View.VISIBLE;

import android.content.Context;
import android.content.Intent;
import android.graphics.Typeface;
import android.view.LayoutInflater;
import android.view.MotionEvent;
import android.view.View;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.RelativeLayout;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.cardview.widget.CardView;
import androidx.constraintlayout.widget.ConstraintLayout;
import androidx.core.content.ContextCompat;
import androidx.recyclerview.widget.RecyclerView;

import android.graphics.Color;
import com.google.android.material.card.MaterialCardView;
import com.ionicframework.otsjateng.DetailKonten;
import com.ionicframework.otsjateng.ListKonten;
import com.ionicframework.otsjateng.R;
import com.ionicframework.otsjateng.WebViewActivity;
import com.ionicframework.otsjateng.model.modelData;
import com.ionicframework.otsjateng.model.modelData3;
import com.ionicframework.otsjateng.model.modelDataDashboard;
import com.ionicframework.otsjateng.model.modelDataImage;
import com.ionicframework.otsjateng.model.modelFooter;
import com.ionicframework.otsjateng.model.modelHeader3;
import com.ionicframework.otsjateng.model.modelMenu;
import com.ionicframework.otsjateng.model.modelBannerScores;

import org.jetbrains.annotations.Nullable;

import java.math.BigDecimal;
import java.math.RoundingMode;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.Objects;

public class AdapterList
        extends RecyclerView.Adapter<RecyclerView.ViewHolder> {

    public static final int VIEW_TYPE_HEADER = 1;
    public static final int VIEW_TYPE_ITEM = 2;
    public static final int VIEW_TYPE_DATA = 3;
    public static final int VIEW_TYPE_FOOTER = 4;
    public static final int VIEW_TYPE_IMAGE = 5;
    public static final int VIEW_TYPE_INFLATION_HEADER = 6;
    public static final int VIEW_TYPE_INFLATION_CARD = 7;
    public static final int VIEW_TYPE_SKIP = 8; // For skipping items
    public static final int VIEW_TYPE_BANNER_SCORES = 9;
    public static final int VIEW_TYPE_MAKLUMAT = 10;

    private final Context mContext;
    private final String mJenis;
    private String mLang;
    private final List<modelData> mList;


    public void updateLang(String lang) {
        android.util.Log.d("TransDebug", "updateLang: " + lang);
        this.mLang = lang;
        notifyDataSetChanged();
    }

    // Icon mapping: old names (without "d_" prefix) -> new drawable names
    private static final Map<String, String> ICON_MAPPING = new HashMap<>();
    static {
        ICON_MAPPING.put("iup", "iup_new");
        ICON_MAPPING.put("inflasi", "inflasi_new");
        ICON_MAPPING.put("ntp", "ntp_new");
        ICON_MAPPING.put("eskpor", "ekspor_new");
        ICON_MAPPING.put("ekspor", "ekspor_new");
        ICON_MAPPING.put("impor", "impor_new");
        ICON_MAPPING.put("neraca", "neraca_perdagangan_new");
        ICON_MAPPING.put("pdrb_prov", "pertumbuhan_ekonomi_new");
        ICON_MAPPING.put("miskin", "kemiskinan_new");
        ICON_MAPPING.put("gini_ratio", "gini_ratio_new");
        ICON_MAPPING.put("naker", "tpt_new");
        ICON_MAPPING.put("ipm", "ipm_new");
        ICON_MAPPING.put("skm", "ipkp_new");
        // Country icon aliases for different naming conventions
        ICON_MAPPING.put("china", "d_tiongkok");
        ICON_MAPPING.put("cina", "d_tiongkok");
        ICON_MAPPING.put("cn", "d_tiongkok");
        ICON_MAPPING.put("tiongkok", "d_tiongkok");
        ICON_MAPPING.put("korea_selatan", "d_korsel");
        ICON_MAPPING.put("uni_eropa", "d_ue");
        ICON_MAPPING.put("amerika_serikat", "d_amerika");
    }

    /**
     * Get the drawable resource name for an icon, using new icon names if available
     * 
     * @param iconId the original icon ID (without "d_" prefix)
     * @return the drawable resource name
     */
    private String getIconResourceName(String iconId) {
        if (ICON_MAPPING.containsKey(iconId)) {
            return ICON_MAPPING.get(iconId);
        }
        // Fallback to old naming convention
        return "d_" + iconId;
    }

    /**
     * Get the border color resource ID for an indicator
     * 
     * @param indicatorId the indicator ID
     * @return the color resource ID
     */
    private int getBorderColorResourceId(String indicatorId) {
        switch (indicatorId) {
            case "inflasi":
                return R.color.border_inflasi;
            case "ipm":
                return R.color.border_ipm;
            case "ekspor":
            case "eskpor":
                return R.color.border_ekspor;
            case "impor":
                return R.color.border_impor;
            case "ntp":
                return R.color.border_ntp;
            case "miskin":
                return R.color.border_kemiskinan;
            case "naker":
                return R.color.border_naker;
            case "gini_ratio":
                return R.color.border_gini_ratio;
            case "neraca":
                return R.color.border_neraca;
            case "pdrb_prov":
                return R.color.border_pdrb_prov;
            case "skm":
                return R.color.border_skm;
            case "iup":
                return R.color.border_iup;
            default:
                return R.color.border_default;
        }
    }

    private String translateDate(String dateStr, String lang) {
        if (dateStr == null || dateStr.trim().isEmpty()) {
            return "";
        }

        // Split into words, translate month name, keep rest
        String[] parts = dateStr.trim().split("\\s+");
        StringBuilder result = new StringBuilder();

        // Indonesian -> English month map
        String[][] idToEn = {
                { "januari", "January" }, { "februari", "February" }, { "maret", "March" },
                { "april", "April" }, { "mei", "May" }, { "juni", "June" },
                { "juli", "July" }, { "agustus", "August" }, { "september", "September" },
                { "oktober", "October" }, { "nopember", "November" }, { "november", "November" },
                { "desember", "December" }
        };

        // English -> Indonesian month map
        String[][] enToId = {
                { "january", "Januari" }, { "february", "Februari" }, { "march", "Maret" },
                { "april", "April" }, { "may", "Mei" }, { "june", "Juni" },
                { "july", "Juli" }, { "august", "Agustus" }, { "september", "September" },
                { "october", "Oktober" }, { "november", "November" }, { "december", "Desember" }
        };

        for (int i = 0; i < parts.length; i++) {
            String word = parts[i];
            String wordLower = word.toLowerCase();
            boolean found = false;

            if (lang.equalsIgnoreCase("en")) {
                for (String[] pair : idToEn) {
                    if (wordLower.equals(pair[0])) {
                        result.append(pair[1]);
                        found = true;
                        break;
                    }
                }
            } else {
                for (String[] pair : enToId) {
                    if (wordLower.equals(pair[0])) {
                        result.append(pair[1]);
                        found = true;
                        break;
                    }
                }
            }

            if (!found) {
                result.append(word);
            }

            if (i < parts.length - 1) {
                result.append(" ");
            }
        }

        return result.toString();
    }

    public AdapterList(Context context, List<modelData> modelDataList, String strJenis, String lang) {
        mContext = context;
        mList = modelDataList;
        mJenis = strJenis;
        mLang = lang;
    }

    public AdapterList(Context context, List<modelData> modelDataList, String strJenis) {
        this(context, modelDataList, strJenis, "in");
    }

    class ImageViewHolder
            extends RecyclerView.ViewHolder
            implements View.OnTouchListener {

        final CustomWebView imageView;

        ImageViewHolder(@NonNull View itemView) {
            super(itemView);
            imageView = itemView.findViewById(R.id.imgView);
            imageView.getSettings().setLoadWithOverviewMode(true);
            imageView.getSettings().setUseWideViewPort(true);
            imageView.setOnTouchListener(this);
        }

        @Override
        public boolean onTouch(View view, @NonNull MotionEvent motionEvent) {
            if (motionEvent.getAction() == MotionEvent.ACTION_DOWN) {
                view.performClick();
                final int position = getBindingAdapterPosition();
                modelDataImage dataImage = (modelDataImage) mList.get(position);
                if (position == 1) {
                    Intent intent = new Intent(mContext, com.ionicframework.otsjateng.MaklumatActivity.class);
                    intent.putExtra("link", dataImage.getLink());
                    mContext.startActivity(intent);
                }
                return true;
            }
            return false;
        }

    }

    class ListDashboardHolder
            extends RecyclerView.ViewHolder
            implements View.OnClickListener {

        final TextView txtTitle, txtValue, txtUnit, txtCatatan, txtPeriod, txtValueSplit, txtUnitSplit;
        final ImageView imgIcon;
        final MaterialCardView cardView;
        final RelativeLayout layoutSplitValue;

        ListDashboardHolder(@NonNull View itemView) {
            super(itemView);

            txtTitle = itemView.findViewById(R.id.txtTitle);
            txtValue = itemView.findViewById(R.id.txtValue);
            txtUnit = itemView.findViewById(R.id.txtUnit);
            txtCatatan = itemView.findViewById(R.id.catatan);
            txtPeriod = itemView.findViewById(R.id.txtPeriod);

            // New Split Views
            layoutSplitValue = itemView.findViewById(R.id.layoutSplitValue);
            txtValueSplit = itemView.findViewById(R.id.txtValueSplit);
            txtUnitSplit = itemView.findViewById(R.id.txtUnitSplit);

            imgIcon = itemView.findViewById(R.id.imgIcon);
            cardView = itemView.findViewById(R.id.cardDashboard);
            itemView.setOnClickListener(this);
        }

        @Override
        public void onClick(View view) {
            if (mJenis.equals("Dashboard")) {
                final int position = getBindingAdapterPosition();
                modelDataDashboard dataDashboard = (modelDataDashboard) mList.get(position);
                Intent intent;
                String template;
                switch (dataDashboard.getId()) {
                    case "neraca":
                        template = String.format(mContext.getString(R.string.templatedashboardneraca),
                                dataDashboard.getIndikator(), dataDashboard.getPeriode(), dataDashboard.getTahun(),
                                dataDashboard.getTanda());
                        break;
                    case "skm":
                    case "naker":
                    case "ipm":
                        template = String.format(mContext.getString(R.string.templatedashboardskm),
                                dataDashboard.getIndikator(), dataDashboard.getPeriode(), dataDashboard.getTahun());
                        break;
                    case "iup":
                        template = "";
                        break;
                    case "inflasi":
                        // Logic handled by MainActivity update.
                        // If Poin is set (from MainActivity calculation), use standard template logic.
                        // If Poin is empty (initial load), show Value only.

                        String valInflasi = dataDashboard.getNilai();
                        try {
                            BigDecimal val = new BigDecimal(valInflasi);
                            valInflasi = val.setScale(2, RoundingMode.HALF_EVEN).toString();
                        } catch (Exception e) {
                        }

                        boolean isZero = false;
                        String checkPoin = dataDashboard.getPoin();
                        if (checkPoin != null) {
                            try {
                                double d = Double.parseDouble(checkPoin.replace(",", "."));
                                if (Math.abs(d) < 0.001)
                                    isZero = true;
                            } catch (Exception e) {
                            }
                        }

                        if (dataDashboard.getPoin() == null || dataDashboard.getPoin().isEmpty() || isZero) {
                            // Fallback if series fetch hasn't completed or failed: Show Value
                            template = String.format("%s %s %s sebesar %s %%",
                                    dataDashboard.getIndikator(), dataDashboard.getPeriode(),
                                    dataDashboard.getTahun(), valInflasi);
                        } else {
                            // Series fetch success: Show Comparison
                            // Template: "%s %s %s %s %s %s dibandingkan %s"
                            // Indikator, Periode, Tahun, Tanda, Poin, Delta, Sebelumnya

                            // Need to ensure formatting
                            String diffStr = dataDashboard.getPoin(); // Already formatted in MainActivity

                            template = String.format(mContext.getString(R.string.templatedashboard),
                                    dataDashboard.getIndikator(), dataDashboard.getPeriode(), dataDashboard.getTahun(),
                                    dataDashboard.getTanda(), diffStr, dataDashboard.getDelta(),
                                    dataDashboard.getSebelumnya());

                            if (dataDashboard.getLang().equals("en"))
                                template = template.replace("dibandingkan", "compared to");
                        }
                        break;
                    default:
                        String strPoin = dataDashboard.getPoin();
                        if (!dataDashboard.getPoin().isEmpty() && !dataDashboard.getPoin().equals("-")) {
                            try {
                                BigDecimal bigDecimal = new BigDecimal(dataDashboard.getPoin());
                                strPoin = bigDecimal.setScale(2, RoundingMode.HALF_EVEN).toString();
                            } catch (NumberFormatException e) {
                                strPoin = dataDashboard.getPoin();
                            }
                        }
                        template = String.format(mContext.getString(R.string.templatedashboard),
                                dataDashboard.getIndikator(), dataDashboard.getPeriode(), dataDashboard.getTahun(),
                                dataDashboard.getTanda(), strPoin, dataDashboard.getDelta(),
                                dataDashboard.getSebelumnya());
                        if (dataDashboard.getLang().equals("en"))
                            template = template.replace("dibandingkan", "compared to");
                        break;
                }
                template = template.replace("  ", " ");

                intent = new Intent(mContext, ListKonten.class);
                intent.putExtra("menu", dataDashboard.getId());
                intent.putExtra("indikator", dataDashboard.getIndikator());
                intent.putExtra("lang", dataDashboard.getLang());
                intent.putExtra("catatan_detail", template);
                mContext.startActivity(intent);
            }
        }
    }

    class ListMenuHolder
            extends RecyclerView.ViewHolder
            implements View.OnClickListener {

        final TextView txtMenu;
        final ImageView imgMenuIcon;
        final ImageView imgArrow;

        ListMenuHolder(@NonNull View itemView) {
            super(itemView);
            txtMenu = itemView.findViewById(R.id.txtMenu);
            imgMenuIcon = itemView.findViewById(R.id.imgMenuIcon);
            imgArrow = itemView.findViewById(R.id.imgArrow);
            itemView.setOnClickListener(this);
        }

        @Override
        public void onClick(View view) {
            Intent intent;
            final int position = getBindingAdapterPosition();
            modelMenu modelMenu = (modelMenu) mList.get(position);
            switch (modelMenu.getIdMenu()) {
                case "inflasi_series":
                case "inflasi_6_kota":
                case "inflasi_ibu_kota":
                case "ntp_series":
                case "ekspor_komoditas":
                case "impor_komoditas":
                case "pdrb_lu_nominal":
                case "pdrb_lu_pertumbuhan":
                case "pdrb_lu_distribusi":
                case "pdrb_lu_sumber":
                case "pdrb_pengeluaran_nominal":
                case "pdrb_pengeluaran_pertumbuhan":
                case "pdrb_pengeluaran_distribusi":
                case "pdrb_pengeluaran_sumber":
                case "miskin_kab":
                case "gini_ratio_prov_series":
                case "tpak_kab":
                case "ipm_komponen_prov_series":
                case "ipm_komponen_kab":
                case "skd_kab":
                case "skd_kab_smt":
                case "skd_kab_ann":
                case "pdrb_kab_nominal":
                case "pdrb_kab_pertumbuhan":
                case "pdrb_kab_distribusi":
                case "pdrb_kab_perkapita":
                    intent = new Intent(mContext, DetailKonten.class);
                    break;
                default:
                    intent = new Intent(mContext, ListKonten.class);
                    break;
            }
            intent.putExtra("menu", modelMenu.getIdMenu());
            intent.putExtra("indikator", modelMenu.getNamaMenu());
            intent.putExtra("lang", modelMenu.getStrlang());
            mContext.startActivity(intent);
        }
    }

    class ListNegaraHolder
            extends RecyclerView.ViewHolder
            implements View.OnClickListener {

        final TextView txtTitle, txtValue, txtCatatan;
        final ImageView imgIcon;
        final MaterialCardView cardView;

        ListNegaraHolder(@NonNull View itemView) {
            super(itemView);

            txtTitle = itemView.findViewById(R.id.txtTitle);
            txtValue = itemView.findViewById(R.id.txtValue);
            txtCatatan = itemView.findViewById(R.id.catatan);
            imgIcon = itemView.findViewById(R.id.imgIcon);
            cardView = itemView.findViewById(R.id.cardNegara);
            itemView.setOnClickListener(this);
        }

        @Override
        public void onClick(View view) {
            // Currently no action on click for country items
        }
    }

    static class Header3Holder
            extends RecyclerView.ViewHolder {

        final TextView txtKolom1, txtKolom2, txtKolom3;
        final LinearLayout linearLayout;
        final CardView cardView;

        Header3Holder(@NonNull View itemView) {
            super(itemView);

            txtKolom1 = itemView.findViewById(R.id.txtKolom1);
            txtKolom2 = itemView.findViewById(R.id.txtKolom2);
            txtKolom3 = itemView.findViewById(R.id.txtKolom3);
            linearLayout = itemView.findViewById(R.id.line1);
            cardView = itemView.findViewById(R.id.cvHeader);
        }
    }

    static class Data3Holder
            extends RecyclerView.ViewHolder {

        final TextView txtKolom1, txtKolom2, txtKolom3;
        final LinearLayout linearLayout;

        Data3Holder(@NonNull View itemView) {
            super(itemView);

            txtKolom1 = itemView.findViewById(R.id.txtKolom1);
            txtKolom2 = itemView.findViewById(R.id.txtKolom2);
            txtKolom3 = itemView.findViewById(R.id.txtKolom3);
            linearLayout = itemView.findViewById(R.id.line1);
        }
    }

    // ViewHolder for inflation header card
    static class InflationHeaderHolder extends RecyclerView.ViewHolder {
        final TextView txtHeaderTitle, txtHeaderPeriod;

        InflationHeaderHolder(@NonNull View itemView) {
            super(itemView);
            txtHeaderTitle = itemView.findViewById(R.id.txtHeaderTitle);
            txtHeaderPeriod = itemView.findViewById(R.id.txtHeaderPeriod);
        }
    }

    // ViewHolder for inflation metric card (Month to Month, Year on Year, etc.)
    static class InflationCardHolder extends RecyclerView.ViewHolder {
        final TextView txtMetricName, txtJatengValue, txtNasionalValue;
        final android.widget.ProgressBar progressJateng, progressNasional;
        final com.google.android.material.card.MaterialCardView cardInflation;

        InflationCardHolder(@NonNull View itemView) {
            super(itemView);
            txtMetricName = itemView.findViewById(R.id.txtMetricName);
            txtJatengValue = itemView.findViewById(R.id.txtJatengValue);
            txtNasionalValue = itemView.findViewById(R.id.txtNasionalValue);
            progressJateng = itemView.findViewById(R.id.progressJateng);
            progressNasional = itemView.findViewById(R.id.progressNasional);
            cardInflation = itemView.findViewById(R.id.cardInflation);
        }
    }

    // Empty ViewHolder for skipping items
    static class SkipHolder extends RecyclerView.ViewHolder {
        SkipHolder(@NonNull View itemView) {
            super(itemView);
        }
    }

    // ... (ImageViewholder) ...

    // ... (ListDashboardHolder) ...

    // ...

    static class BannerScoresHolder extends RecyclerView.ViewHolder {
        final TextView tvIpkpLabel, tvIpkpValue, tvIpkpStatus, tvIpkpSubtitle;
        final TextView tvIpakLabel, tvIpakValue, tvIpakStatus, tvIpakSubtitle;
        final TextView tvMethodNote, tvFooterText, tvPeriodText;
        final CardView cardView;

        BannerScoresHolder(@NonNull View itemView) {
            super(itemView);
            tvIpkpLabel = itemView.findViewById(R.id.tv_ipkp_label);
            tvIpkpValue = itemView.findViewById(R.id.tv_ipkp_value);
            tvIpkpStatus = itemView.findViewById(R.id.tv_ipkp_status);
            tvIpkpSubtitle = itemView.findViewById(R.id.tv_ipkp_subtitle);

            tvIpakLabel = itemView.findViewById(R.id.tv_ipak_label);
            tvIpakValue = itemView.findViewById(R.id.tv_ipak_value);
            tvIpakStatus = itemView.findViewById(R.id.tv_ipak_status);
            tvIpakSubtitle = itemView.findViewById(R.id.tv_ipak_subtitle);

            tvMethodNote = itemView.findViewById(R.id.tv_method_note);
            tvFooterText = itemView.findViewById(R.id.tv_footer_text);
            tvPeriodText = itemView.findViewById(R.id.tv_period_text);

            cardView = itemView.findViewById(R.id.card_header_scores);
        }
    }

    static class MaklumatViewHolder extends RecyclerView.ViewHolder {
        final MaterialCardView cardView;
        final TextView tvMaklumatTitle;

        MaklumatViewHolder(@NonNull View itemView) {
            super(itemView);
            cardView = itemView.findViewById(R.id.card_maklumat);
            tvMaklumatTitle = itemView.findViewById(R.id.tv_maklumat_title);
        }
    }

    static class ListDataIconHolder
            extends RecyclerView.ViewHolder {

        final TextView txtItem, txtValue;
        final ImageView imgIcon;
        final ConstraintLayout relativeLayout;
        final MaterialCardView iconContainer;

        ListDataIconHolder(@NonNull View itemView) {
            super(itemView);
            txtItem = itemView.findViewById(R.id.txtItem);
            txtValue = itemView.findViewById(R.id.txtValue);
            imgIcon = itemView.findViewById(R.id.imgIcon);
            relativeLayout = itemView.findViewById(R.id.relat1);
            iconContainer = itemView.findViewById(R.id.icon_container);
        }
    }

    class FooterHolder
            extends RecyclerView.ViewHolder {

        final TextView txtFooter;

        FooterHolder(View itemView) {
            super(itemView);
            txtFooter = itemView.findViewById(R.id.txtFooter);
        }

    }

    @Override
    public int getItemViewType(int position) {
        // Special handling for inflasi_prov - use new card layout
        if (mJenis.equals("inflasi_prov")) {
            if (mList.get(position) instanceof modelHeader3) {
                modelHeader3 header = (modelHeader3) mList.get(position);
                // First header (title with judul) - show as inflation header
                // Second header (column labels like Inflasi, Jateng, Nasional) - skip it
                if (!header.getKolom2().isEmpty() || !header.getKolom3().isEmpty()) {
                    // This is the column header row, skip it
                    return VIEW_TYPE_SKIP;
                }
                return VIEW_TYPE_INFLATION_HEADER;
            } else if (mList.get(position) instanceof modelData3) {
                return VIEW_TYPE_INFLATION_CARD;
            }
        }

        if (mList.get(position) instanceof modelHeader3) {
            return VIEW_TYPE_HEADER;
        } else if (mList.get(position) instanceof modelMenu) {
            return VIEW_TYPE_ITEM;
        } else if (mList.get(position) instanceof modelData3) {
            return VIEW_TYPE_DATA;
        } else if (mList.get(position) instanceof modelDataDashboard) {
            return VIEW_TYPE_ITEM;
        } else if (mList.get(position) instanceof modelDataImage) {
            return VIEW_TYPE_MAKLUMAT;
        } else if (mList.get(position) instanceof modelBannerScores) {
            return VIEW_TYPE_BANNER_SCORES;
        } else {
            return VIEW_TYPE_FOOTER;
        }
    }

    @NonNull
    @Override
    public RecyclerView.ViewHolder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
        if (viewType == VIEW_TYPE_ITEM) {
            if (mJenis.equals("ekspor_negara") || mJenis.equals("impor_negara")) {
                // Use new horizontal layout for country list
                return new ListNegaraHolder(LayoutInflater.from(parent.getContext())
                        .inflate(R.layout.adapter_list_negara, parent, false));
            } else if (mJenis.equals("Dashboard")) {
                return new ListDashboardHolder(LayoutInflater.from(parent.getContext())
                        .inflate(R.layout.adapter_list_dashboard, parent, false));
            } else {
                return new ListMenuHolder(
                        LayoutInflater.from(parent.getContext()).inflate(R.layout.adapter_list_menu, parent, false));
            }
        } else if (viewType == VIEW_TYPE_DATA) {
            switch (mJenis) {
                case "inflasi_kelompok":
                case "ntup":
                case "iup":
                    return new ListDataIconHolder(LayoutInflater.from(parent.getContext())
                            .inflate(R.layout.adapter_list_data_icon, parent, false));
                default:
                    return new Data3Holder(
                            LayoutInflater.from(parent.getContext()).inflate(R.layout.adapter_body3, parent, false));
            }
        } else if (viewType == VIEW_TYPE_HEADER) {
            return new Header3Holder(
                    LayoutInflater.from(parent.getContext()).inflate(R.layout.adapter_header3, parent, false));
        } else if (viewType == VIEW_TYPE_MAKLUMAT) {
            return new MaklumatViewHolder(
                    LayoutInflater.from(parent.getContext()).inflate(R.layout.adapter_banner_maklumat, parent, false));
        } else if (viewType == VIEW_TYPE_IMAGE) {
            // Deprecated/Unused if modelDataImage is redirected to MAKLUMAT above
            return new ImageViewHolder(
                    LayoutInflater.from(parent.getContext()).inflate(R.layout.adapter_image, parent, false));
        } else if (viewType == VIEW_TYPE_INFLATION_HEADER) {
            return new InflationHeaderHolder(
                    LayoutInflater.from(parent.getContext()).inflate(R.layout.adapter_inflation_header, parent, false));
        } else if (viewType == VIEW_TYPE_INFLATION_CARD) {
            return new InflationCardHolder(
                    LayoutInflater.from(parent.getContext()).inflate(R.layout.adapter_inflation_card, parent, false));
        } else if (viewType == VIEW_TYPE_BANNER_SCORES) {
            return new BannerScoresHolder(
                    LayoutInflater.from(parent.getContext()).inflate(R.layout.adapter_banner_scores, parent, false));
        } else if (viewType == VIEW_TYPE_SKIP) {
            return new SkipHolder(
                    LayoutInflater.from(parent.getContext()).inflate(R.layout.adapter_skip, parent, false));

        } else {
            return new FooterHolder(
                    LayoutInflater.from(parent.getContext()).inflate(R.layout.footer_button, parent, false));
        }
    }

    @Override
    public void onBindViewHolder(@NonNull RecyclerView.ViewHolder holder, int position) {
        if (holder.getItemViewType() == VIEW_TYPE_BANNER_SCORES) {
            BannerScoresHolder bannerHolder = (BannerScoresHolder) holder;
            modelBannerScores data = (modelBannerScores) mList.get(position);

            // Bind IPKP
            bannerHolder.tvIpkpValue.setText(data.getIpkpScore().replace(".", ","));

            String ipkpStatus = data.getIpkpStatus();
            if (ipkpStatus != null && !ipkpStatus.trim().isEmpty() && !ipkpStatus.startsWith("(")
                    && !ipkpStatus.endsWith(")")) {
                ipkpStatus = "(" + ipkpStatus + ")";
            }

            android.util.Log.d("TransDebug", "BannerBind: mLang=" + mLang);

            // Translate Status if English
            if (mLang != null && mLang.equals("en")) {
                if (ipkpStatus != null && ipkpStatus.contains("Sangat Baik"))
                    ipkpStatus = "(Excellent)";
                else if (ipkpStatus != null && ipkpStatus.contains("Baik"))
                    ipkpStatus = "(Good)";
            }

            bannerHolder.tvIpkpStatus.setText(ipkpStatus);
            bannerHolder.tvIpkpValue.setTextColor(Color.WHITE);

            // Bind IPAK
            bannerHolder.tvIpakValue.setText(data.getIpakScore().replace(".", ","));

            String ipakStatus = data.getIpakStatus();
            if (ipakStatus != null && !ipakStatus.trim().isEmpty() && !ipakStatus.startsWith("(")
                    && !ipakStatus.endsWith(")")) {
                ipakStatus = "(" + ipakStatus + ")";
            }

            // Translate Status if English
            if (mLang != null && mLang.equalsIgnoreCase("en")) {
                if (ipakStatus != null && ipakStatus.contains("Sangat Baik"))
                    ipakStatus = "(Excellent)";
                else if (ipakStatus != null && ipakStatus.contains("Baik"))
                    ipakStatus = "(Good)";
            }

            bannerHolder.tvIpakStatus.setText(ipakStatus);
            bannerHolder.tvIpakValue.setTextColor(Color.WHITE);

            // Translate Static Labels
            if (mLang != null && mLang.equalsIgnoreCase("en")) {
                bannerHolder.tvIpkpLabel.setText(mContext.getString(R.string.banner_ipkp_label_en));
                bannerHolder.tvIpakLabel.setText(mContext.getString(R.string.banner_ipak_label_en));
                bannerHolder.tvMethodNote.setText(mContext.getString(R.string.banner_method_note_en));
                bannerHolder.tvFooterText.setText(mContext.getString(R.string.banner_footer_en));
            } else {
                bannerHolder.tvIpkpLabel.setText(mContext.getString(R.string.banner_ipkp_label));
                bannerHolder.tvIpakLabel.setText(mContext.getString(R.string.banner_ipak_label));
                bannerHolder.tvMethodNote.setText(mContext.getString(R.string.banner_method_note));
                bannerHolder.tvFooterText.setText(mContext.getString(R.string.banner_footer));
            }

            // Bind Period Text dynamically
            String period = data.getPeriod();
            if (period != null && !period.trim().isEmpty()) {
                bannerHolder.tvPeriodText.setText(period);
            }

            return; // Done
        } else if (holder.getItemViewType() == VIEW_TYPE_MAKLUMAT) {
            MaklumatViewHolder maklumatHolder = (MaklumatViewHolder) holder;
            modelDataImage data = (modelDataImage) mList.get(position);

            View.OnClickListener clickListener = v -> {
                Intent intent = new Intent(mContext, com.ionicframework.otsjateng.MaklumatActivity.class);
                intent.putExtra("link", data.getLink());
                mContext.startActivity(intent);
            };

            maklumatHolder.cardView.setOnClickListener(clickListener);

            // Translate Maklumat Text
            if (mLang != null && mLang.equalsIgnoreCase("en")) {
                maklumatHolder.tvMaklumatTitle.setText(mContext.getString(R.string.maklumat_text_en));
            } else {
                maklumatHolder.tvMaklumatTitle.setText(mContext.getString(R.string.maklumat_text_id));
            }

            return;
        }

        if (holder.getItemViewType() == VIEW_TYPE_ITEM) {
            BigDecimal bigDecimal;
            String pembulatan, strPoin = "";
            if (mJenis.equals("Dashboard")) {
                ListDashboardHolder holderDashboard = (ListDashboardHolder) holder;
                modelDataDashboard dashboards = (modelDataDashboard) getItem(position);
                assert dashboards != null;

                // Universal Text Cleaning (Applied to ALL items)
                String rawIndicator = dashboards.getIndikator();
                String cleanIndicator = rawIndicator.replace("<br>", " ")
                        .replace("<br/>", " ")
                        .replace("<br />", " ")
                        .replace("&lt;br&gt;", " ")
                        .replace("&lt;br/&gt;", " ");

                if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.N) {
                    cleanIndicator = android.text.Html.fromHtml(cleanIndicator, android.text.Html.FROM_HTML_MODE_LEGACY)
                            .toString();
                } else {
                    cleanIndicator = android.text.Html.fromHtml(cleanIndicator).toString();
                }
                cleanIndicator = cleanIndicator.replace("\n", " ").trim();

                holderDashboard.txtTitle.setText(cleanIndicator);

                // --- NEW LOGIC: Value + Unit and Period ---
                String valStr = "";
                if (dashboards.getId().equals("gini_ratio")) {
                    valStr = dashboards.getNilai();
                    try {
                        // For Gini, sometimes poin is the value if nilio is empty?
                        // Assuming getNilai() is correct.
                        // Also usually Gini is 3 decimals.
                        if (valStr == null || valStr.equals("-"))
                            valStr = "0.000";
                    } catch (Exception e) {
                    }
                } else {
                    try {
                        bigDecimal = new BigDecimal(dashboards.getNilai());
                        valStr = bigDecimal.setScale(2, RoundingMode.HALF_EVEN).toString();

                        // Calculate Poin for template (kept for intent extra)
                        if (!dashboards.getId().equals("neraca") && !dashboards.getId().equals("inflasi")
                                && !dashboards.getId().equals("pdrb_prov")) {
                            bigDecimal = new BigDecimal(dashboards.getPoin());
                            strPoin = bigDecimal.setScale(2, RoundingMode.HALF_EVEN).toString();
                        }
                    } catch (Exception e) {
                        valStr = dashboards.getNilai();
                    }
                }

                // Append Satuan if exists
                String satuan = dashboards.getSatuan();
                if (satuan == null)
                    satuan = "";

                // Logic for Display Mode: Centered (Default for ALL)
                // "Rata Kanan Kiri" removed as per user request to Center

                // CENTERED MODE (Default)
                String fullValue = valStr;
                if (!satuan.isEmpty()) {
                    fullValue = valStr + " " + satuan;
                }
                holderDashboard.txtValue.setText(fullValue);

                holderDashboard.txtValue.setVisibility(VISIBLE);
                holderDashboard.layoutSplitValue.setVisibility(GONE);

                // Set Period (Bulan + Tahun)
                String period = dashboards.getPeriode();
                String tahun = dashboards.getTahun();
                String periodStr = period;
                if (tahun != null && !tahun.isEmpty()) {
                    if (!period.contains(tahun)) {
                        periodStr = period + " " + tahun;
                    }
                }

                // Translate Period Bidirectionally
                if (mLang != null) {
                    periodStr = translateDate(periodStr, mLang);
                }

                holderDashboard.txtPeriod.setText(periodStr);
                holderDashboard.txtPeriod.setVisibility(VISIBLE);

                // Reset Unit visibility (Keep it gone as we merged it)
                holderDashboard.txtUnit.setText(satuan);
                holderDashboard.txtUnit.setVisibility(View.GONE);

                String template;
                holderDashboard.txtTitle.setVisibility(VISIBLE);
                // Template generation for Intent Extra (Click Action)
                switch (dashboards.getId()) {
                    case "neraca":
                        template = String.format(mContext.getString(R.string.templatedashboardneraca),
                                cleanIndicator, dashboards.getPeriode(), dashboards.getTahun(),
                                dashboards.getTanda());
                        break;
                    case "skm":
                    case "naker":
                    case "ipm":
                        template = String.format(mContext.getString(R.string.templatedashboardskm),
                                cleanIndicator, dashboards.getPeriode(), dashboards.getTahun());
                        break;
                    case "iup":
                        template = "";
                        // Logic for IUP specific styling
                        holderDashboard.txtValue.setText(cleanIndicator);
                        holderDashboard.txtValue.setTextSize(15);
                        holderDashboard.txtValue.setTypeface(null, Typeface.BOLD);

                        String indicatorTrimmed = cleanIndicator.trim();
                        int currentNightMode = mContext.getResources().getConfiguration().uiMode
                                & android.content.res.Configuration.UI_MODE_NIGHT_MASK;
                        boolean isDarkMode = currentNightMode == android.content.res.Configuration.UI_MODE_NIGHT_YES;

                        if (indicatorTrimmed.contains("IPTEK, Inovasi") ||
                                indicatorTrimmed.contains("Integrasi Ekonomi") ||
                                indicatorTrimmed.contains("Perkotaan dan Perdesaan") ||
                                indicatorTrimmed.contains("Keluarga Berkualitas")) {
                            holderDashboard.txtValue.setTextColor(Color.parseColor("#1976D2"));
                        } else {
                            if (isDarkMode) {
                                holderDashboard.txtValue.setTextColor(Color.WHITE);
                            } else {
                                holderDashboard.txtValue.setTextColor(Color.BLACK);
                            }
                        }

                        holderDashboard.txtTitle.setVisibility(GONE);
                        holderDashboard.txtPeriod.setVisibility(GONE);
                        break;
                    default:
                        template = String.format(mContext.getString(R.string.templatedashboard),
                                cleanIndicator, dashboards.getPeriode(), dashboards.getTahun(),
                                dashboards.getTanda(), strPoin, dashboards.getDelta(), dashboards.getSebelumnya());
                        if (dashboards.getLang().equals("en"))
                            template = template.replace("dibandingkan", "compared to");
                        break;
                }
                template = template.replace("  ", " ");
                holderDashboard.txtCatatan.setText(template);
                int intIcon = mContext.getResources().getIdentifier(getIconResourceName(dashboards.getId()), "drawable",
                        mContext.getPackageName());
                holderDashboard.imgIcon.setImageResource(intIcon);

                // --- REMOVED HARDCODED OVERRIDES ---
                // Previously specific IDs like inflasi/ekspor had hardcoded .setText() values.
                // These are now removed to rely on dashboards.getNilai() from API.

            } else if (mJenis.equals("ekspor_negara") || mJenis.equals("impor_negara")) {
                ListNegaraHolder negaraHolder = (ListNegaraHolder) holder;
                modelDataDashboard dataDashboard = (modelDataDashboard) getItem(position);

                // Set country name
                negaraHolder.txtTitle.setText(Objects.requireNonNull(dataDashboard).getIndikator());

                // Set value
                bigDecimal = new BigDecimal(dataDashboard.getNilai());
                pembulatan = bigDecimal.setScale(2, RoundingMode.HALF_EVEN).toString();
                negaraHolder.txtValue.setText(pembulatan);

                // Set comparison text
                String string = dataDashboard.getPoin() + "% dibanding bulan sebelumnya";
                negaraHolder.txtCatatan.setText(string);

                // Set flag icon - convert to lowercase and remove spaces
                String iconId = dataDashboard.getId().toLowerCase().replace(" ", "_");
                int intIcon = mContext.getResources().getIdentifier(getIconResourceName(iconId),
                        "drawable", mContext.getPackageName());
                if (intIcon != 0) {
                    negaraHolder.imgIcon.setImageResource(intIcon);
                } else {
                    // Fallback to default flag icon
                    negaraHolder.imgIcon.setImageResource(android.R.drawable.ic_menu_gallery);
                }
            } else {
                ListMenuHolder menuHolder = (ListMenuHolder) holder;
                modelMenu menu = (modelMenu) getItem(position);
                menuHolder.txtMenu.setText(Objects.requireNonNull(menu).getNamaMenu());

                // Set icon based on menu ID
                if (menuHolder.imgMenuIcon != null) {
                    int iconRes;
                    String menuId = menu.getIdMenu();
                    if (menuId.contains("ntp") || menuId.equals("ntup")) {
                        iconRes = mContext.getResources().getIdentifier("ic_ntp", "drawable",
                                mContext.getPackageName());
                    } else if (menuId.contains("penyumbang") || menuId.contains("komoditas")) {
                        iconRes = mContext.getResources().getIdentifier("ic_komoditas", "drawable",
                                mContext.getPackageName());
                    } else if (menuId.contains("inflasi")) {
                        iconRes = mContext.getResources().getIdentifier("ic_inflasi", "drawable",
                                mContext.getPackageName());
                    } else if (menuId.equals("ekspor_pertumbuhan") || menuId.equals("ekspor_negara")
                            || menuId.equals("ekspor_migas") || menuId.equals("impor_pertumbuhan")
                            || menuId.equals("impor_negara") || menuId.equals("impor_migas")) {
                        // Icon for Pertumbuhan Tertinggi, Negara Tujuan Utama, Migas dan Nonmigas
                        iconRes = mContext.getResources().getIdentifier("ic_ekspor_impor", "drawable",
                                mContext.getPackageName());
                    } else if (menuId.contains("pdrb")) {
                        // Icon for PDRB menu items: Nominal, Pertumbuhan, Distribusi, Sumber
                        // Pertumbuhan
                        iconRes = mContext.getResources().getIdentifier("ic_pdrb", "drawable",
                                mContext.getPackageName());
                    } else if (menuId.equals("miskin_prov") || menuId.equals("miskin_kab")) {
                        // Icon for Kemiskinan Provinsi and Kemiskinan Kabupaten/Kota
                        iconRes = mContext.getResources().getIdentifier("ic_kemiskinan", "drawable",
                                mContext.getPackageName());
                    } else if (menuId.contains("gini_ratio")) {
                        // Icon for Gini Ratio and Series menu items
                        iconRes = mContext.getResources().getIdentifier("ic_gini_ratio", "drawable",
                                mContext.getPackageName());
                    } else if (menuId.contains("tpak") || menuId.startsWith("naker_")
                            || menuId.contains("tpt")) {
                        // Icon for TPT menu items: TPAK dan TPT, Pekerja menurut lapangan usaha,
                        // Pekerja formal dan informal, Pekerja menurut pendidikan, Setelah penganggur
                        iconRes = mContext.getResources().getIdentifier("ic_tpt", "drawable",
                                mContext.getPackageName());
                    } else if (menuId.contains("ipm")) {
                        // Icon for IPM menu items: Series Nilai IPM, Komponen IPM, Perbandingan IPM,
                        // Nilai IPM, Status Pembangunan Manusia
                        iconRes = mContext.getResources().getIdentifier("ic_ipm", "drawable",
                                mContext.getPackageName());
                    } else if (menuId.contains("skm") || menuId.contains("skd")) {
                        // Icon for IPKP menu items: Data Provinsi, Data Kabupaten/Kota
                        iconRes = mContext.getResources().getIdentifier("ic_ipkp", "drawable",
                                mContext.getPackageName());
                    } else {
                        iconRes = android.R.drawable.ic_menu_info_details;
                    }
                    if (iconRes != 0) {
                        menuHolder.imgMenuIcon.setImageResource(iconRes);
                    }
                }

                // Set Action Icon (Arrow or Dots)
                String menuId = menu.getIdMenu();
                int actionIconRes;
                switch (menuId) {
                    case "inflasi_series":

                        // Detail Content -> Show Three Dots
                        actionIconRes = R.drawable.ic_more;
                        break;
                    default:
                        // List Content -> Show Arrow
                        actionIconRes = R.drawable.ic_chevron_right_white_24dp;
                        break;
                }
                menuHolder.imgArrow.setImageResource(actionIconRes);
            }
        } else if (holder.getItemViewType() == VIEW_TYPE_HEADER) {
            Header3Holder header3Holder = (Header3Holder) holder;
            modelHeader3 header3 = (modelHeader3) getItem(position);
            String title = Objects.requireNonNull(header3).getKolom1();

            if (title.isEmpty()) {
                header3Holder.linearLayout.removeView(header3Holder.txtKolom1);
            } else {
                header3Holder.txtKolom1.setText(title);

                // Modern Styling: Dynamic Header Colors based on content (Inflasi/Deflasi)
                if (title.toLowerCase().contains("inflasi") || title.toLowerCase().contains("inflation")) {
                    header3Holder.cardView.setCardBackgroundColor(Color.parseColor("#E53935")); // Modern Red
                } else if (title.toLowerCase().contains("deflasi") || title.toLowerCase().contains("deflation")) {
                    header3Holder.cardView.setCardBackgroundColor(Color.parseColor("#43A047")); // Modern Green
                } else {
                    header3Holder.cardView.setCardBackgroundColor(ContextCompat.getColor(mContext, R.color.primaryColor));
                }
            }

            if (header3.getKolom2().isEmpty()) {
                header3Holder.linearLayout.removeView(header3Holder.txtKolom2);
            } else {
                header3Holder.txtKolom2.setText(header3.getKolom2());
            }

            if (header3.getKolom3().isEmpty()) {
                header3Holder.linearLayout.removeView(header3Holder.txtKolom3);
            } else {
                header3Holder.txtKolom3.setText(header3.getKolom3());
            }

            if (header3.getKolom2().isEmpty() && header3.getKolom3().isEmpty()) {
                header3Holder.cardView.setUseCompatPadding(false);
            }
        } else if (holder.getItemViewType() == VIEW_TYPE_IMAGE) {
            ImageViewHolder imageViewHolder = (ImageViewHolder) holder;
            modelDataImage image = (modelDataImage) getItem(position);
            imageViewHolder.imageView.loadUrl(Objects.requireNonNull(image).getImage());
        } else if (holder.getItemViewType() == VIEW_TYPE_DATA) {
            String pembulatan2, pembulatan3;
            BigDecimal bigDecimal2, bigDecimal3;
            switch (mJenis) {
                case "inflasi_kelompok":
                case "ntup":
                case "iup":
                    ListDataIconHolder iconHolder = (ListDataIconHolder) holder;
                    modelData3 dataIcon = (modelData3) getItem(position);
                    iconHolder.txtItem.setText(Objects.requireNonNull(dataIcon).getKolom2());
                    BigDecimal bigDecimal = new BigDecimal(dataIcon.getKolom3());
                    String pembulatan = bigDecimal.setScale(2, RoundingMode.HALF_EVEN).toString();
                    iconHolder.txtValue.setText(pembulatan);

                    // Modern Styling: Dynamic Value Color for Inflation/Deflation
                    if (mJenis.equals("inflasi_kelompok")) {
                        try {
                            double val = Double.parseDouble(pembulatan);
                            if (val > 0) {
                                iconHolder.txtValue.setTextColor(Color.parseColor("#E53935")); // Red for inflation
                            } else if (val < 0) {
                                iconHolder.txtValue.setTextColor(Color.parseColor("#43A047")); // Green for deflation
                            } else {
                                iconHolder.txtValue.setTextColor(ContextCompat.getColor(mContext, R.color.primaryColor));
                            }
                        } catch (Exception e) {
                            iconHolder.txtValue.setTextColor(ContextCompat.getColor(mContext, R.color.primaryColor));
                        }
                    }

                    // Modern Styling: Icon Container Background for Dark Mode
                    int currentNightMode = mContext.getResources().getConfiguration().uiMode
                            & android.content.res.Configuration.UI_MODE_NIGHT_MASK;
                    boolean isDarkMode = currentNightMode == android.content.res.Configuration.UI_MODE_NIGHT_YES;
                    if (iconHolder.iconContainer != null) {
                        if (isDarkMode) {
                            iconHolder.iconContainer.setCardBackgroundColor(Color.parseColor("#2C2C2C")); // Subtle Dark Gray
                        } else {
                            iconHolder.iconContainer.setCardBackgroundColor(Color.parseColor("#F5F5F5")); // Light Gray
                        }
                    }

                    // Custom Icon Logic for IUP
                    int intIcon;
                    String itemText = dataIcon.getKolom2().toLowerCase();
                    if (itemText.contains("proporsi kontribusi pdrb")) {
                        intIcon = mContext.getResources().getIdentifier("proporsi_kontribusi_pdrb", "drawable",
                                mContext.getPackageName());
                    } else if (itemText.contains("rasio pdrb industri pengolahan")) {
                        intIcon = mContext.getResources().getIdentifier("industri_pengolahan", "drawable",
                                mContext.getPackageName());
                    } else if (itemText.contains("indeks ketimpangan gender") || itemText.contains("ikg")) {
                        intIcon = mContext.getResources().getIdentifier("ikg", "drawable", mContext.getPackageName());
                    } else {
                        intIcon = mContext.getResources().getIdentifier(getIconResourceName(dataIcon.getKolom1()),
                                "drawable",
                                mContext.getPackageName());
                    }

                    if (intIcon != 0) {
                        iconHolder.imgIcon.setImageResource(intIcon);
                    } else {
                        // Fallback checking original approach if 0, though getIconResourceName handles
                        // defaults
                        intIcon = mContext.getResources().getIdentifier(getIconResourceName(dataIcon.getKolom1()),
                                "drawable",
                                mContext.getPackageName());
                        iconHolder.imgIcon.setImageResource(intIcon);
                    }
                    if (dataIcon.getKolom1().equals(" ")) {
                        iconHolder.relativeLayout
                                .setBackgroundColor(ContextCompat.getColor(mContext, android.R.color.darker_gray));
                    }
                    break;
                default: {
                    Data3Holder data3Holder = (Data3Holder) holder;
                    modelData3 modelData3 = (modelData3) getItem(position);
                    switch (mJenis) {
                        case "inflasi":
                        case "pdrb_kab_nominal":
                        case "tpak_prov":
                        case "ipm_prov_series":
                        case "ipm_kab":
                        case "skd_prov_ann":
                        case "skd_kab_ann":
                            bigDecimal2 = new BigDecimal(Objects.requireNonNull(modelData3).getKolom2());
                            bigDecimal3 = new BigDecimal(modelData3.getKolom3());

                            pembulatan2 = bigDecimal2.setScale(2, RoundingMode.HALF_EVEN).toString();
                            pembulatan3 = bigDecimal3.setScale(2, RoundingMode.HALF_EVEN).toString();
                            break;

                        case "gini_ratio_prov":
                            bigDecimal2 = new BigDecimal(Objects.requireNonNull(modelData3).getKolom2());

                            pembulatan2 = bigDecimal2.setScale(3, RoundingMode.HALF_EVEN).toString();
                            pembulatan3 = modelData3.getKolom3();
                            break;
                        case "ntp_prov":
                        case "ntp_penyumbang":
                        case "ntp_prov_jawa":
                        case "ekspor_migas":
                        case "impor_migas":
                        case "neraca":
                        case "pdrb_kab_pertumbuhan":
                        case "pdrb_kab_distribusi":
                        case "pdrb_kab_perkapita":
                            pembulatan2 = Objects.requireNonNull(modelData3).getKolom2();
                            pembulatan3 = modelData3.getKolom3();
                            if (!mJenis.equals("ntp_penyumbang") && !mJenis.equals("ekspor_migas")
                                    && !mJenis.equals("impor_migas")) {
                                bigDecimal2 = new BigDecimal(Objects.requireNonNull(modelData3).getKolom2());
                                pembulatan2 = bigDecimal2.setScale(2, RoundingMode.HALF_EVEN).toString();
                            } else {
                                if (!pembulatan2.isEmpty()) {
                                    bigDecimal2 = new BigDecimal(Objects.requireNonNull(modelData3).getKolom2());
                                    pembulatan2 = bigDecimal2.setScale(2, RoundingMode.HALF_EVEN).toString();
                                }
                            }
                            break;
                        default:
                            pembulatan2 = Objects.requireNonNull(modelData3).getKolom2();
                            pembulatan3 = modelData3.getKolom3();
                            break;
                    }
                    if (Objects.requireNonNull(modelData3).getKolom1().isEmpty()) {
                        data3Holder.linearLayout.removeView(data3Holder.txtKolom1);
                    } else {
                        data3Holder.txtKolom1.setText(modelData3.getKolom1());
                    }

                    if (modelData3.getKolom2().isEmpty()) {
                        data3Holder.linearLayout.removeView(data3Holder.txtKolom2);
                    } else {
                        data3Holder.txtKolom2.setText(pembulatan2);
                    }

                    if (modelData3.getKolom3().isEmpty()) {
                        data3Holder.linearLayout.removeView(data3Holder.txtKolom3);
                    } else {
                        data3Holder.txtKolom3.setText(pembulatan3);

                        // Modern Styling: Color coding for Inflation/Deflation in table rows
                        if (mJenis.equals("inflasi_penyumbang")) {
                            try {
                                double val = Double.parseDouble(pembulatan3);
                                if (val > 0) {
                                    data3Holder.txtKolom3.setTextColor(Color.parseColor("#E53935")); // Red
                                } else if (val < 0) {
                                    data3Holder.txtKolom3.setTextColor(Color.parseColor("#43A047")); // Green
                                }
                            } catch (Exception ignored) {}
                        }
                    }

                    if (modelData3.getKolom2().isEmpty() && modelData3.getKolom3().isEmpty()) {
                        data3Holder.txtKolom1.setTypeface(null, Typeface.BOLD);
                        data3Holder.linearLayout
                                .setBackgroundColor(ContextCompat.getColor(mContext, android.R.color.darker_gray));
                    }
                    break;
                }
            }
        } else if (holder.getItemViewType() == VIEW_TYPE_INFLATION_HEADER) {
            // Bind inflation header data
            InflationHeaderHolder inflationHeaderHolder = (InflationHeaderHolder) holder;
            modelHeader3 header = (modelHeader3) getItem(position);
            if (header != null) {
                String title = header.getKolom1();
                // Parse title to extract main title and period
                // Format expected: "Inflasi Provinsi Jawa Tengah dan Nasional:Desember 2025
                // (%)"
                if (title.contains(":")) {
                    String[] parts = title.split(":");
                    inflationHeaderHolder.txtHeaderTitle.setText(parts[0].trim());
                    if (parts.length > 1) {
                        inflationHeaderHolder.txtHeaderPeriod.setText(parts[1].trim());
                    }
                } else {
                    inflationHeaderHolder.txtHeaderTitle.setText(title);
                    inflationHeaderHolder.txtHeaderPeriod.setText("");
                }
            }
        } else if (holder.getItemViewType() == VIEW_TYPE_INFLATION_CARD) {
            // Bind inflation card data
            InflationCardHolder inflationCardHolder = (InflationCardHolder) holder;
            modelData3 data = (modelData3) getItem(position);
            if (data != null) {
                // Set metric name (Month to Month, Year on Year, etc.)
                inflationCardHolder.txtMetricName.setText(data.getKolom1());

                // Set Jateng value
                String jatengValue = data.getKolom2();
                try {
                    BigDecimal bd = new BigDecimal(jatengValue);
                    jatengValue = bd.setScale(2, RoundingMode.HALF_EVEN).toString();
                } catch (NumberFormatException e) {
                    // Keep original value
                }
                inflationCardHolder.txtJatengValue.setText(jatengValue);

                // Set Nasional value
                String nasionalValue = data.getKolom3();
                try {
                    BigDecimal bd = new BigDecimal(nasionalValue);
                    nasionalValue = bd.setScale(2, RoundingMode.HALF_EVEN).toString();
                } catch (NumberFormatException e) {
                    // Keep original value
                }
                inflationCardHolder.txtNasionalValue.setText(nasionalValue);

                // Calculate and set progress bars (relative to max value for comparison)
                try {
                    float jatengFloat = Float.parseFloat(data.getKolom2());
                    float nasionalFloat = Float.parseFloat(data.getKolom3());
                    float maxValue = Math.max(jatengFloat, nasionalFloat);
                    if (maxValue > 0) {
                        int jatengProgress = (int) ((jatengFloat / maxValue) * 100);
                        int nasionalProgress = (int) ((nasionalFloat / maxValue) * 100);
                        inflationCardHolder.progressJateng.setProgress(jatengProgress);
                        inflationCardHolder.progressNasional.setProgress(nasionalProgress);
                    }
                } catch (NumberFormatException e) {
                    inflationCardHolder.progressJateng.setProgress(50);
                    inflationCardHolder.progressNasional.setProgress(50);
                }
            }
        } else if (holder.getItemViewType() == VIEW_TYPE_SKIP) {
            // Skip - do nothing for this view type
            return;

        } else {
            FooterHolder footerHolder = (FooterHolder) holder;
            modelFooter modelFooter = (modelFooter) getItem(position);
            footerHolder.txtFooter.setText(Objects.requireNonNull(modelFooter).getString());
        }
    }

    @Override
    public int getItemCount() {
        return mList.size();
    }

    @Nullable
    private modelData getItem(int position) {
        if (mList.isEmpty()) {
            return null;
        } else {
            if (position == mList.size()) {
                return null;
            } else {
                return mList.get(position);
            }
        }
    }

}