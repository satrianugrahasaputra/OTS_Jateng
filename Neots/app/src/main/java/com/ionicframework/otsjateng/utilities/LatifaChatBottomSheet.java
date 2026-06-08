package com.ionicframework.otsjateng.utilities;

import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.EditText;
import android.widget.ImageButton;

import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;

import com.google.android.material.bottomsheet.BottomSheetBehavior;
import com.google.android.material.bottomsheet.BottomSheetDialogFragment;
import com.ionicframework.otsjateng.R;

import java.util.ArrayList;
import java.util.List;

public class LatifaChatBottomSheet extends BottomSheetDialogFragment {

    private RecyclerView rvChatMessages;
    private EditText etChatInput;
    private ImageButton btnSendMessage;
    private ImageButton btnCloseChat;
    private ChatAdapter chatAdapter;
    private List<ChatMessage> chatMessages;

    public static LatifaChatBottomSheet newInstance() {
        return new LatifaChatBottomSheet();
    }

    @Nullable
    @Override
    public View onCreateView(@NonNull LayoutInflater inflater, @Nullable ViewGroup container,
            @Nullable Bundle savedInstanceState) {
        return inflater.inflate(R.layout.bottom_sheet_chat, container, false);
    }

    @Override
    public void onViewCreated(@NonNull View view, @Nullable Bundle savedInstanceState) {
        super.onViewCreated(view, savedInstanceState);

        // Initialize views
        rvChatMessages = view.findViewById(R.id.rvChatMessages);
        etChatInput = view.findViewById(R.id.etChatInput);
        btnSendMessage = view.findViewById(R.id.btnSendMessage);
        btnCloseChat = view.findViewById(R.id.btnCloseChat);

        // Initialize chat messages
        chatMessages = new ArrayList<>();

        // Add welcome message
        chatMessages.add(new ChatMessage(
                "Halo! Saya Latifa, asisten virtual cerdas Anda di aplikasi OTS Jateng (One Touch Statistic). " +
                        "Ada data statistik Jawa Tengah yang bisa saya bantu carikan? " +
                        "Anda bisa bertanya tentang inflasi, kemiskinan, IPM, NTP, dan banyak lagi! 😊",
                false // isUser = false means LATIFA message
        ));

        // Setup RecyclerView
        chatAdapter = new ChatAdapter(chatMessages);
        rvChatMessages.setLayoutManager(new LinearLayoutManager(getContext()));
        rvChatMessages.setAdapter(chatAdapter);

        // Close button
        btnCloseChat.setOnClickListener(v -> dismiss());

        // Send button
        btnSendMessage.setOnClickListener(v -> {
            String message = etChatInput.getText().toString().trim();
            if (!message.isEmpty()) {
                sendMessage(message);
            }
        });
    }

    private void sendMessage(String message) {
        // Add user message
        chatMessages.add(new ChatMessage(message, true));
        chatAdapter.notifyItemInserted(chatMessages.size() - 1);

        // Clear input
        etChatInput.setText("");

        // Scroll to bottom
        rvChatMessages.smoothScrollToPosition(chatMessages.size() - 1);

        // Simulate LATIFA response (for now, just echo back)
        rvChatMessages.postDelayed(() -> {
            String response = getLatifaResponse(message);
            chatMessages.add(new ChatMessage(response, false));
            chatAdapter.notifyItemInserted(chatMessages.size() - 1);
            rvChatMessages.smoothScrollToPosition(chatMessages.size() - 1);
        }, 500);
    }

    private String getLatifaResponse(String userMessage) {
        // Latifa - Asisten Virtual OTS Jateng
        // Professional, friendly, informative - like a statistics service officer
        // Definitions sourced from BPS Jateng
        String lowerMessage = userMessage.toLowerCase();

        // === GREETING RESPONSES ===
        if (lowerMessage.contains("halo") || lowerMessage.contains("hai") || lowerMessage.contains("hi")
                || lowerMessage.contains("hello") || lowerMessage.contains("selamat")) {
            return "Halo! Saya Latifa, asisten virtual Anda di aplikasi OTS Jateng. " +
                    "Ada data statistik Jawa Tengah yang bisa saya bantu carikan? " +
                    "Anda bisa bertanya tentang inflasi, kemiskinan, IPM, dan banyak lagi!";
        }

        // === THANK YOU RESPONSES ===
        if (lowerMessage.contains("terima kasih") || lowerMessage.contains("thanks")
                || lowerMessage.contains("makasih")) {
            return "Sama-sama! Senang bisa membantu. Jika ada pertanyaan lain seputar data statistik Jateng, " +
                    "silakan tanyakan saja. Saya Latifa, selalu siap membantu Anda! 😊";
        }

        // === IUP (Indikator Utama Pembangunan) ===
        if (lowerMessage.contains("iup") || lowerMessage.contains("indikator utama")) {
            if (lowerMessage.contains("apa itu") || lowerMessage.contains("definisi")
                    || lowerMessage.contains("pengertian")) {
                return "📖 **Apa itu IUP?**\n\n" +
                        "IUP (Indikator Utama Pembangunan) adalah kumpulan indikator strategis yang digunakan untuk " +
                        "mengukur capaian pembangunan daerah. Indikator ini mencakup berbagai sektor seperti ekonomi, "
                        +
                        "sosial, dan kesejahteraan masyarakat.\n\n" +
                        "Sumber: BPS Provinsi Jawa Tengah\n\n" +
                        "💡 Buka menu **IUP** untuk melihat data lengkap.";
            }
            return "Data **Indikator Utama Pembangunan Daerah (IUP)** tersedia di menu IUP. " +
                    "Menu ini berisi ringkasan data makro pembangunan Jawa Tengah dalam satu tampilan.";
        }

        // === INFLASI (Menu Inflasi) ===
        if (lowerMessage.contains("inflasi") || lowerMessage.contains("harga naik")
                || lowerMessage.contains("kenaikan harga")) {
            if (lowerMessage.contains("apa itu") || lowerMessage.contains("definisi")
                    || lowerMessage.contains("pengertian")) {
                return "📖 **Apa itu Inflasi?**\n\n" +
                        "Inflasi adalah kecenderungan naiknya harga barang dan jasa secara umum dan terus menerus " +
                        "dalam jangka waktu tertentu. Inflasi dihitung berdasarkan perubahan Indeks Harga Konsumen (IHK).\n\n"
                        +
                        "📊 Jenis perhitungan:\n" +
                        "• m-to-m (month to month): perubahan harga bulan ini vs bulan lalu\n" +
                        "• y-on-y (year on year): perubahan harga bulan ini vs bulan yang sama tahun lalu\n" +
                        "• Tahun Kalender: perubahan sejak Januari tahun berjalan\n\n" +
                        "Sumber: BPS Provinsi Jawa Tengah";
            } else if (lowerMessage.contains("kelompok") || lowerMessage.contains("pengeluaran")) {
                return "Untuk melihat inflasi menurut Kelompok Pengeluaran, silakan buka menu **Inflasi** → " +
                        "**Menurut Kelompok Pengeluaran**. Di sana Anda bisa lihat kontribusi masing-masing " +
                        "kelompok terhadap inflasi total.\n\n💡 Tip: Tekan tombol titik tiga di pojok kanan atas " +
                        "untuk melihat data historis (Series) atau mengunduh Excel.";
            } else if (lowerMessage.contains("komoditas") || lowerMessage.contains("penyumbang")) {
                return "Data komoditas penyumbang inflasi tersedia di menu **Inflasi** → **Komoditas Penyumbang**. " +
                        "Di sini Anda bisa melihat komoditas apa saja yang berkontribusi terhadap inflasi.";
            } else if (lowerMessage.contains("kota") || lowerMessage.contains("9 kota")) {
                return "Untuk perbandingan inflasi antar kota, silakan buka menu **Inflasi** → **Inflasi 9 Kota**. " +
                        "Data mencakup kota-kota besar di Jawa Tengah seperti Semarang, Solo, dan lainnya.";
            } else if (lowerMessage.contains("ibu kota")) {
                return "Data Inflasi Ibu Kota Provinsi tersedia di menu **Inflasi** → **Inflasi Ibu Kota Provinsi**. " +
                        "Di sini Anda bisa membandingkan inflasi Semarang dengan ibu kota provinsi lain.";
            } else {
                return "Data **Inflasi** Jawa Tengah tersedia di menu Inflasi pada dashboard.\n\n" +
                        "📊 Sub-menu yang tersedia:\n" +
                        "• Inflasi Jateng (Series)\n" +
                        "• Menurut Kelompok Pengeluaran\n" +
                        "• Komoditas Penyumbang\n" +
                        "• Inflasi 9 Kota\n" +
                        "• Inflasi Ibu Kota Provinsi\n\n" +
                        "📈 Contoh: Inflasi Jateng (Nov 2025) adalah 0.19% (month to month).\n\n" +
                        "💡 Tekan tombol Series (titik tiga) untuk melihat data historis atau unduh Excel.";
            }
        }

        // === NILAI TUKAR PETANI (NTP) ===
        if (lowerMessage.contains("ntp") || lowerMessage.contains("nilai tukar petani")
                || lowerMessage.contains("petani") || lowerMessage.contains("pertanian")) {
            if (lowerMessage.contains("apa itu") || lowerMessage.contains("definisi")
                    || lowerMessage.contains("pengertian")) {
                return "📖 **Apa itu NTP?**\n\n" +
                        "NTP (Nilai Tukar Petani) adalah rasio antara Indeks Harga yang Diterima Petani (It) dengan " +
                        "Indeks Harga yang Dibayar Petani (Ib). NTP merupakan salah satu indikator untuk mengukur " +
                        "tingkat kesejahteraan petani.\n\n" +
                        "✅ NTP > 100 → Petani mengalami surplus (sejahtera)\n" +
                        "❌ NTP < 100 → Petani mengalami defisit\n" +
                        "⚖️ NTP = 100 → Impas\n\n" +
                        "Sumber: BPS Provinsi Jawa Tengah";
            } else {
                return "Data **Nilai Tukar Petani (NTP)** tersedia di menu NTP.\n\n" +
                        "📊 Sub-menu yang tersedia:\n" +
                        "• NTP Provinsi\n" +
                        "• Komoditas Penyumbang NTP\n" +
                        "• NTP Pulau Jawa\n" +
                        "• NTUP (Nilai Tukar Usaha Pertanian)\n" +
                        "• Series Data\n\n" +
                        "💡 Tip: Tekan tombol titik tiga untuk melihat data historis dan unduh Excel.";
            }
        }

        // === EKSPOR ===
        if (lowerMessage.contains("ekspor") && !lowerMessage.contains("impor")) {
            if (lowerMessage.contains("apa itu") || lowerMessage.contains("definisi")
                    || lowerMessage.contains("pengertian")) {
                return "📖 **Apa itu Ekspor?**\n\n" +
                        "Ekspor adalah kegiatan mengeluarkan barang dari daerah pabean Indonesia ke luar negeri. " +
                        "Nilai ekspor dihitung berdasarkan nilai FOB (Free on Board), yaitu nilai barang ditambah " +
                        "biaya angkut dan asuransi sampai ke pelabuhan muat.\n\n" +
                        "Sumber: BPS Provinsi Jawa Tengah";
            }
            return "Data **Ekspor** Jawa Tengah tersedia di menu Ekspor.\n\n" +
                    "📊 Sub-menu yang tersedia:\n" +
                    "• Menurut Komoditas\n" +
                    "• Pertumbuhan Tertinggi\n" +
                    "• Negara Tujuan Utama\n" +
                    "• Migas & Non-Migas\n\n" +
                    "💡 Tekan tombol Series untuk melihat tren data historis.";
        }

        // === IMPOR ===
        if (lowerMessage.contains("impor") && !lowerMessage.contains("ekspor")) {
            if (lowerMessage.contains("apa itu") || lowerMessage.contains("definisi")
                    || lowerMessage.contains("pengertian")) {
                return "📖 **Apa itu Impor?**\n\n" +
                        "Impor adalah kegiatan memasukkan barang ke dalam daerah pabean Indonesia dari luar negeri. " +
                        "Nilai impor dihitung berdasarkan nilai CIF (Cost, Insurance, and Freight), yaitu nilai barang "
                        +
                        "ditambah biaya asuransi dan ongkos angkut.\n\n" +
                        "Sumber: BPS Provinsi Jawa Tengah";
            }
            return "Data **Impor** Jawa Tengah tersedia di menu Impor.\n\n" +
                    "📊 Sub-menu yang tersedia:\n" +
                    "• Menurut Komoditas\n" +
                    "• Negara Asal Utama\n" +
                    "• Migas & Non-Migas\n\n" +
                    "💡 Data dapat diunduh dalam format Excel melalui tombol titik tiga.";
        }

        // === EKSPOR & IMPOR (both mentioned) ===
        if ((lowerMessage.contains("ekspor") && lowerMessage.contains("impor"))
                || lowerMessage.contains("perdagangan")) {
            return "Data perdagangan luar negeri tersedia di menu **Ekspor**, **Impor**, dan **Neraca Perdagangan**. " +
                    "Anda bisa melihat data berdasarkan komoditas, negara tujuan/asal, dan kategori migas/non-migas.";
        }

        // === NERACA PERDAGANGAN ===
        if (lowerMessage.contains("neraca")) {
            if (lowerMessage.contains("apa itu") || lowerMessage.contains("definisi")
                    || lowerMessage.contains("pengertian")) {
                return "📖 **Apa itu Neraca Perdagangan?**\n\n" +
                        "Neraca Perdagangan adalah selisih antara nilai ekspor dan nilai impor suatu wilayah " +
                        "dalam periode tertentu.\n\n" +
                        "✅ Surplus: Jika ekspor > impor\n" +
                        "❌ Defisit: Jika impor > ekspor\n" +
                        "⚖️ Seimbang: Jika ekspor = impor\n\n" +
                        "Sumber: BPS Provinsi Jawa Tengah";
            }
            return "Data **Neraca Perdagangan** (selisih ekspor dan impor) tersedia di menu Neraca Perdagangan. " +
                    "Neraca surplus jika ekspor > impor, dan defisit jika impor > ekspor.";
        }

        // === PDRB / PERTUMBUHAN EKONOMI ===
        if (lowerMessage.contains("pdrb") || lowerMessage.contains("ekonomi")
                || lowerMessage.contains("pertumbuhan")) {
            if (lowerMessage.contains("apa itu") || lowerMessage.contains("definisi")
                    || lowerMessage.contains("pengertian")) {
                return "📖 **Apa itu Pertumbuhan Ekonomi / PDRB?**\n\n" +
                        "PDRB (Produk Domestik Regional Bruto) adalah jumlah nilai tambah bruto yang dihasilkan " +
                        "seluruh unit usaha dalam suatu wilayah, atau jumlah seluruh nilai barang dan jasa akhir " +
                        "yang dihasilkan seluruh unit ekonomi di suatu wilayah.\n\n" +
                        "📊 PDRB dihitung dengan 2 pendekatan:\n" +
                        "• Menurut Lapangan Usaha (17 kategori)\n" +
                        "• Menurut Pengeluaran (konsumsi, investasi, ekspor-impor)\n\n" +
                        "Pertumbuhan ekonomi adalah persentase perubahan PDRB ADHK dari periode sebelumnya.\n\n" +
                        "Sumber: BPS Provinsi Jawa Tengah";
            }
            return "Data **Pertumbuhan Ekonomi** tersedia di menu Pertumbuhan Ekonomi.\n\n" +
                    "📊 Sub-menu yang tersedia:\n" +
                    "• PDRB Lapangan Usaha (Nominal, Pertumbuhan, Distribusi, Sumber)\n" +
                    "• PDRB Pengeluaran\n" +
                    "• PDRB Kabupaten/Kota\n\n" +
                    "📈 Contoh: Pertumbuhan Ekonomi Jateng Triwulan III 2025 adalah 5.37%.\n\n" +
                    "💡 Tekan tombol Series untuk analisis tren historis.";
        }

        // === KEMISKINAN ===
        if (lowerMessage.contains("kemiskinan") || lowerMessage.contains("miskin")
                || lowerMessage.contains("penduduk miskin")) {
            if (lowerMessage.contains("apa itu") || lowerMessage.contains("definisi")
                    || lowerMessage.contains("pengertian")) {
                return "📖 **Apa itu Kemiskinan?**\n\n" +
                        "Kemiskinan dipandang sebagai ketidakmampuan dari sisi ekonomi untuk memenuhi kebutuhan " +
                        "dasar makanan dan bukan makanan yang diukur dari sisi pengeluaran. Penduduk miskin adalah " +
                        "penduduk yang memiliki rata-rata pengeluaran per kapita per bulan di bawah Garis Kemiskinan.\n\n"
                        +
                        "📊 Indikator:\n" +
                        "• Persentase Penduduk Miskin (P0)\n" +
                        "• Indeks Kedalaman Kemiskinan (P1)\n" +
                        "• Indeks Keparahan Kemiskinan (P2)\n\n" +
                        "Sumber: BPS Provinsi Jawa Tengah";
            }
            return "Data **Kemiskinan** tersedia di menu Kemiskinan.\n\n" +
                    "📊 Sub-menu yang tersedia:\n" +
                    "• Kemiskinan Provinsi\n" +
                    "• Kemiskinan Kabupaten/Kota\n\n" +
                    "📈 Contoh: Tingkat kemiskinan Jateng (Maret 2025) adalah 9.48%.\n\n" +
                    "💡 Tekan tombol Series untuk melihat tren penurunan kemiskinan.";
        }

        // === GINI RATIO ===
        if (lowerMessage.contains("gini") || lowerMessage.contains("ketimpangan")
                || lowerMessage.contains("ketidaksetaraan")) {
            if (lowerMessage.contains("apa itu") || lowerMessage.contains("definisi")
                    || lowerMessage.contains("pengertian")) {
                return "📖 **Apa itu Gini Ratio?**\n\n" +
                        "Gini Ratio (Koefisien Gini) adalah ukuran ketimpangan pendapatan atau kekayaan " +
                        "dalam suatu populasi. Nilainya berkisar antara 0 hingga 1.\n\n" +
                        "📊 Interpretasi:\n" +
                        "• 0 = Pemerataan sempurna (semua orang punya pendapatan sama)\n" +
                        "• 1 = Ketimpangan sempurna (satu orang menguasai seluruh pendapatan)\n" +
                        "• < 0.3 = Ketimpangan rendah\n" +
                        "• 0.3-0.4 = Ketimpangan sedang\n" +
                        "• > 0.4 = Ketimpangan tinggi\n\n" +
                        "Sumber: BPS Provinsi Jawa Tengah";
            }
            return "Data **Gini Ratio** (indikator ketimpangan pendapatan) tersedia di menu Gini Ratio.\n\n" +
                    "📖 Penjelasan: Gini Ratio bernilai 0-1. Semakin mendekati 0, distribusi pendapatan semakin merata. "
                    +
                    "Semakin mendekati 1, ketimpangan semakin tinggi.\n\n" +
                    "💡 Tersedia data tingkat provinsi dan kabupaten/kota.";
        }

        // === TPT / PENGANGGURAN / KETENAGAKERJAAN ===
        if (lowerMessage.contains("pengangguran") || lowerMessage.contains("tpt")
                || lowerMessage.contains("naker") || lowerMessage.contains("tenaga kerja")
                || lowerMessage.contains("tpak") || lowerMessage.contains("ketenagakerjaan")) {
            if (lowerMessage.contains("apa itu") || lowerMessage.contains("definisi")
                    || lowerMessage.contains("pengertian")) {
                return "📖 **Apa itu TPT?**\n\n" +
                        "TPT (Tingkat Pengangguran Terbuka) adalah persentase jumlah pengangguran terhadap jumlah " +
                        "angkatan kerja. Pengangguran terbuka adalah penduduk yang sedang mencari pekerjaan, " +
                        "mempersiapkan usaha, tidak mencari karena merasa tidak mungkin mendapat pekerjaan, " +
                        "atau sudah diterima bekerja tapi belum mulai bekerja.\n\n" +
                        "📊 Rumus: TPT = (Pengangguran / Angkatan Kerja) x 100%\n\n" +
                        "TPAK (Tingkat Partisipasi Angkatan Kerja) adalah persentase angkatan kerja " +
                        "terhadap penduduk usia kerja (15+ tahun).\n\n" +
                        "Sumber: BPS Provinsi Jawa Tengah";
            }
            return "Data **Ketenagakerjaan** tersedia di menu TPT.\n\n" +
                    "📊 Sub-menu yang tersedia:\n" +
                    "• TPAK dan TPT\n" +
                    "• Pekerja Menurut Lapangan Usaha\n" +
                    "• Pekerja Formal dan Informal\n" +
                    "• Pekerja Menurut Pendidikan\n" +
                    "• Setengah Penganggur\n\n" +
                    "📈 Contoh: TPT Jateng (Agustus 2025) adalah 4.66%.\n\n" +
                    "💡 Tekan tombol Series untuk analisis tren historis.";
        }

        // === IPM ===
        if (lowerMessage.contains("ipm") || lowerMessage.contains("pembangunan manusia")
                || lowerMessage.contains("indeks pembangunan")) {
            if (lowerMessage.contains("apa itu") || lowerMessage.contains("definisi")
                    || lowerMessage.contains("pengertian")) {
                return "📖 **Apa itu IPM?**\n\n" +
                        "IPM (Indeks Pembangunan Manusia) adalah indikator yang mengukur keberhasilan pembangunan " +
                        "kualitas hidup manusia. IPM dibangun melalui tiga dimensi dasar:\n\n" +
                        "📊 Komponen IPM:\n" +
                        "• Umur Panjang & Hidup Sehat → Umur Harapan Hidup (UHH)\n" +
                        "• Pengetahuan → Harapan Lama Sekolah (HLS) & Rata-rata Lama Sekolah (RLS)\n" +
                        "• Standar Hidup Layak → Pengeluaran per Kapita\n\n" +
                        "📈 Kategori IPM:\n" +
                        "• < 60: Rendah\n" +
                        "• 60-70: Sedang\n" +
                        "• 70-80: Tinggi\n" +
                        "• ≥ 80: Sangat Tinggi\n\n" +
                        "Sumber: BPS Provinsi Jawa Tengah";
            }
            return "Data **Indeks Pembangunan Manusia (IPM)** tersedia di menu IPM.\n\n" +
                    "📊 Sub-menu yang tersedia:\n" +
                    "• Series Nilai IPM\n" +
                    "• Komponen IPM (UHH, HLS, RLS, Pengeluaran)\n" +
                    "• Perbandingan Antar Kabupaten/Kota\n\n" +
                    "📖 IPM mengukur kualitas hidup manusia dari tiga dimensi: kesehatan, pendidikan, dan standar hidup layak.\n\n"
                    +
                    "💡 Tekan tombol Series untuk melihat perkembangan IPM.";
        }

        // === IPKP / IPAK ===
        if (lowerMessage.contains("ipkp") || lowerMessage.contains("ipak")
                || lowerMessage.contains("pelayanan") || lowerMessage.contains("anti korupsi")) {
            if (lowerMessage.contains("apa itu") || lowerMessage.contains("definisi")
                    || lowerMessage.contains("pengertian")) {
                return "📖 **Apa itu IPKP?**\n\n" +
                        "IPKP (Indeks Persepsi Kualitas Pelayanan) adalah ukuran untuk menilai kualitas " +
                        "pelayanan publik berdasarkan persepsi masyarakat pengguna layanan. Survei ini mengukur " +
                        "berbagai aspek pelayanan seperti kemudahan akses, kecepatan, keramahan, dan profesionalitas.\n\n"
                        +
                        "IPAK (Indeks Persepsi Anti Korupsi) mengukur persepsi masyarakat terhadap upaya " +
                        "pencegahan korupsi dalam pelayanan publik.\n\n" +
                        "Sumber: BPS Provinsi Jawa Tengah";
            }
            return "Data **IPKP** (Indeks Persepsi Kualitas Pelayanan) dan **IPAK** (Indeks Persepsi Anti Korupsi) " +
                    "tersedia di menu Pelayanan Publik.\n\n" +
                    "📊 Data ini mencerminkan hasil survei kepuasan masyarakat terhadap pelayanan statistik.";
        }

        // === MAKLUMAT ===
        if (lowerMessage.contains("maklumat") || lowerMessage.contains("standar pelayanan")) {
            return "Menu **Maklumat** berisi informasi tentang **Standar Pelayanan Statistik Terpadu** " +
                    "BPS Provinsi Jawa Tengah. Silakan buka menu Maklumat untuk detailnya.";
        }

        // === MENU / NAVIGASI UMUM ===
        if (lowerMessage.contains("menu") || lowerMessage.contains("fitur")
                || lowerMessage.contains("apa saja")) {
            return "Aplikasi OTS Jateng memiliki **12 Indikator Strategis** di dashboard:\n\n" +
                    "1️⃣ IUP - Indikator Utama Pembangunan\n" +
                    "2️⃣ Inflasi - Kenaikan harga barang/jasa\n" +
                    "3️⃣ NTP - Nilai Tukar Petani\n" +
                    "4️⃣ Ekspor - Perdagangan ke luar negeri\n" +
                    "5️⃣ Impor - Perdagangan dari luar negeri\n" +
                    "6️⃣ Neraca - Selisih ekspor-impor\n" +
                    "7️⃣ Pertumbuhan Ekonomi - PDRB\n" +
                    "8️⃣ Kemiskinan - Penduduk miskin\n" +
                    "9️⃣ Gini Ratio - Ketimpangan\n" +
                    "🔟 TPT - Pengangguran\n" +
                    "1️⃣1️⃣ IPM - Pembangunan Manusia\n" +
                    "1️⃣2️⃣ Pelayanan Publik - IPKP/IPAK\n\n" +
                    "Ketik nama indikator + 'apa itu' untuk penjelasan, misal: 'apa itu inflasi'";
        }

        // === SERIES / DOWNLOAD ===
        if (lowerMessage.contains("series") || lowerMessage.contains("historis")
                || lowerMessage.contains("download") || lowerMessage.contains("unduh")
                || lowerMessage.contains("excel")) {
            return "Untuk melihat data historis atau mengunduh Excel:\n\n" +
                    "📌 Tekan tombol **titik tiga (⋮)** di pojok kanan atas halaman data.\n" +
                    "📌 Pilih **Series** untuk data historis atau **Download Excel** untuk unduhan.\n\n" +
                    "Fitur ini tersedia di hampir semua menu data statistik.";
        }

        // === ABOUT LATIFA / CHATBOT ===
        if (lowerMessage.contains("siapa kamu") || lowerMessage.contains("latifa")
                || lowerMessage.contains("chatbot") || lowerMessage.contains("kamu siapa")) {
            return "Saya **Latifa**, asisten virtual cerdas di aplikasi OTS Jateng (One Touch Statistic). " +
                    "Saya siap membantu Anda menemukan data statistik dengan cepat dan menjelaskan istilah statistik.\n\n"
                    +
                    "🤖 Untuk chatbot yang lebih lengkap dengan AI, silakan kunjungi:\n" +
                    "👉 http://s.bps.go.id/latifa_web";
        }

        // === DEFAULT RESPONSE ===
        return "Terima kasih atas pertanyaannya! Untuk informasi lebih lengkap tentang data statistik " +
                "Jawa Tengah, silakan jelajahi menu di dashboard.\n\n" +
                "🤖 Untuk chatbot Latifa yang lebih canggih dengan AI, kunjungi:\n" +
                "👉 http://s.bps.go.id/latifa_web";
    }

    @Override
    public void onStart() {
        super.onStart();
        // Make bottom sheet expanded
        View view = getView();
        if (view != null) {
            View parent = (View) view.getParent();
            BottomSheetBehavior<View> behavior = BottomSheetBehavior.from(parent);
            behavior.setState(BottomSheetBehavior.STATE_EXPANDED);
            behavior.setSkipCollapsed(true);

            // Set height to 80% of screen
            ViewGroup.LayoutParams layoutParams = parent.getLayoutParams();
            layoutParams.height = (int) (getResources().getDisplayMetrics().heightPixels * 0.85);
            parent.setLayoutParams(layoutParams);
        }
    }

    // Inner class for ChatMessage
    public static class ChatMessage {
        private final String message;
        private final boolean isUser;

        public ChatMessage(String message, boolean isUser) {
            this.message = message;
            this.isUser = isUser;
        }

        public String getMessage() {
            return message;
        }

        public boolean isUser() {
            return isUser;
        }
    }

    // Inner class for ChatAdapter
    public static class ChatAdapter extends RecyclerView.Adapter<RecyclerView.ViewHolder> {
        private static final int VIEW_TYPE_LATIFA = 0;
        private static final int VIEW_TYPE_USER = 1;

        private final List<ChatMessage> messages;

        public ChatAdapter(List<ChatMessage> messages) {
            this.messages = messages;
        }

        @Override
        public int getItemViewType(int position) {
            return messages.get(position).isUser() ? VIEW_TYPE_USER : VIEW_TYPE_LATIFA;
        }

        @NonNull
        @Override
        public RecyclerView.ViewHolder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
            LayoutInflater inflater = LayoutInflater.from(parent.getContext());
            if (viewType == VIEW_TYPE_USER) {
                View view = inflater.inflate(R.layout.item_chat_user, parent, false);
                return new UserMessageHolder(view);
            } else {
                View view = inflater.inflate(R.layout.item_chat_latifa, parent, false);
                return new LatifaMessageHolder(view);
            }
        }

        @Override
        public void onBindViewHolder(@NonNull RecyclerView.ViewHolder holder, int position) {
            ChatMessage message = messages.get(position);
            if (holder instanceof UserMessageHolder) {
                ((UserMessageHolder) holder).txtMessage.setText(message.getMessage());
            } else if (holder instanceof LatifaMessageHolder) {
                ((LatifaMessageHolder) holder).txtMessage.setText(message.getMessage());
                // Enable clickable links
                ((LatifaMessageHolder) holder).txtMessage.setMovementMethod(
                        android.text.method.LinkMovementMethod.getInstance());
            }
        }

        @Override
        public int getItemCount() {
            return messages.size();
        }

        static class UserMessageHolder extends RecyclerView.ViewHolder {
            android.widget.TextView txtMessage;

            UserMessageHolder(@NonNull View itemView) {
                super(itemView);
                txtMessage = itemView.findViewById(R.id.txtMessage);
            }
        }

        static class LatifaMessageHolder extends RecyclerView.ViewHolder {
            android.widget.TextView txtMessage;

            LatifaMessageHolder(@NonNull View itemView) {
                super(itemView);
                txtMessage = itemView.findViewById(R.id.txtMessage);
            }
        }
    }
}
