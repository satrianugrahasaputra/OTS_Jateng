package com.ionicframework.otsjateng;

import android.os.Bundle;
import android.text.TextUtils;
import android.view.MenuItem;
import android.widget.ArrayAdapter;
import android.widget.AutoCompleteTextView;
import android.widget.Toast;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.appcompat.widget.Toolbar;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.textfield.TextInputEditText;
import com.ionicframework.otsjateng.utilities.classFungsi;

public class PengaduanActivity extends AppCompatActivity {

    private TextInputEditText etNama, etEmail, etHp, etIsi;
    private AutoCompleteTextView etJenis;
    private MaterialButton btnKirim;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_pengaduan);

        Toolbar toolbar = findViewById(R.id.toolbar);
        setSupportActionBar(toolbar);
        if (getSupportActionBar() != null) {
            getSupportActionBar().setDisplayHomeAsUpEnabled(true);
            getSupportActionBar().setDisplayShowHomeEnabled(true);
        }

        inisialisasiView();
        setupSpinner();
        setupListener();
    }

    private void inisialisasiView() {
        etNama = findViewById(R.id.etNama);
        etEmail = findViewById(R.id.etEmail);
        etHp = findViewById(R.id.etHp);
        etIsi = findViewById(R.id.etIsi);
        etJenis = findViewById(R.id.etJenis);
        btnKirim = findViewById(R.id.btnKirim);
    }

    private void setupSpinner() {
        String[] jenisPengaduan = new String[] {
                "Layanan PST",
                "Permintaan Data",
                "Konsultasi Statistik",
                "Pengaduan Website/Aplikasi",
                "Lainnya"
        };

        ArrayAdapter<String> adapter = new ArrayAdapter<>(
                this,
                android.R.layout.simple_dropdown_item_1line,
                jenisPengaduan);
        etJenis.setAdapter(adapter);
    }

    private void setupListener() {
        btnKirim.setOnClickListener(view -> {
            if (validasiInput()) {
                kirimPengaduan();
            }
        });
    }

    private boolean validasiInput() {
        if (TextUtils.isEmpty(etNama.getText())) {
            etNama.setError("Nama wajib diisi");
            return false;
        }
        if (TextUtils.isEmpty(etEmail.getText())) {
            etEmail.setError("Email wajib diisi");
            return false;
        }
        if (TextUtils.isEmpty(etHp.getText())) {
            etHp.setError("Nomor HP wajib diisi");
            return false;
        }
        if (TextUtils.isEmpty(etIsi.getText())) {
            etIsi.setError("Isi pengaduan wajib diisi");
            return false;
        }
        return true;
    }

    private void kirimPengaduan() {
        // Simulasi pengiriman data
        // Di sini nantinya bisa ditambahkan logic POST ke API jika endpoint sudah
        // diketahui

        new classFungsi(this, "Pengaduan berhasil dikirim! (Mode UI)").TampilkanSnackBar();

        // Reset form
        etNama.setText("");
        etEmail.setText("");
        etHp.setText("");
        etIsi.setText("");
        etJenis.setText("Layanan PST");
        etNama.requestFocus();
    }

    @Override
    public boolean onOptionsItemSelected(@NonNull MenuItem item) {
        if (item.getItemId() == android.R.id.home) {
            getOnBackPressedDispatcher().onBackPressed();
            return true;
        }
        return super.onOptionsItemSelected(item);
    }
}
