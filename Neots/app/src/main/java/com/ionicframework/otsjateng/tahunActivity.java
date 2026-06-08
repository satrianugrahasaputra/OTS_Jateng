package com.ionicframework.otsjateng;

import android.app.Activity;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Bundle;
import android.view.View;
import android.widget.AdapterView;
import android.widget.ArrayAdapter;

import androidx.activity.EdgeToEdge;
import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.lifecycle.ViewModelProvider;

import com.ionicframework.otsjateng.databinding.ActivityTahun1Binding;
import com.ionicframework.otsjateng.databinding.ActivityTahunBinding;
import com.ionicframework.otsjateng.model.modelTahun;
import com.ionicframework.otsjateng.vm.inetViewModel;

import java.util.List;
import java.util.Objects;

public class tahunActivity
        extends AppCompatActivity
        implements AdapterView.OnItemSelectedListener, View.OnClickListener {

    private ActivityTahunBinding binding;
    private ActivityTahun1Binding binding1;
    private String strBulan;
    private String strTahun;
    private String strMenu;
    private long indexBulan, indexTahun;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        EdgeToEdge.enable(this);
        super.onCreate(savedInstanceState);
        Bundle bundle = getIntent().getExtras();
        SharedPreferences preferences = this.getSharedPreferences("link", MODE_PRIVATE);
        String strUrl = preferences.getString("link", "");
        String strTabel = Objects.requireNonNull(bundle).getString("tabel");
        strMenu = bundle.getString("menu");
        if (Objects.requireNonNull(strMenu).equals("ekspor_komoditas") || strMenu.equals("impor_komoditas")){
            binding1 = ActivityTahun1Binding.inflate(getLayoutInflater());
            setContentView(binding1.getRoot());
            binding1.prgBar.setVisibility(View.VISIBLE);
            binding1.spnTahun.setOnItemSelectedListener(this);
            binding1.spnBulan.setOnItemSelectedListener(this);
            binding1.btnOK.setOnClickListener(this);
        }else {
            binding = ActivityTahunBinding.inflate(getLayoutInflater());
            setContentView(binding.getRoot());
            binding.prgBar.setVisibility(View.VISIBLE);
            binding.spnTahun.setOnItemSelectedListener(this);
            binding.btnOK.setOnClickListener(this);
        }

        inetViewModel viewModel = new ViewModelProvider(tahunActivity.this).get(inetViewModel.class);
        viewModel.getTahun().observe(this, modelResponseTahun -> {
            if (modelResponseTahun.getData() != null){
                processFinish(modelResponseTahun.getData());
            }
        });
        viewModel.setTahun(strTabel, strUrl);
    }

    @Override
    public void onItemSelected(@NonNull AdapterView<?> parent, View view, int position, long id) {
        if (parent.getId() == R.id.spnBulan){
            indexBulan = parent.getItemIdAtPosition(position);
            strBulan = Long.toString(indexBulan);
        }else if (parent.getId() == R.id.spnTahun){
            strTahun = parent.getItemAtPosition(position).toString();
            indexTahun = parent.getItemIdAtPosition(position);
        }
    }

    @Override
    public void onNothingSelected(AdapterView<?> parent) {

    }

    @Override
    public void onClick(@NonNull View v) {
        if (v.getId() == R.id.btnOK){
            if (strMenu.equals("ekspor_komoditas") || strMenu.equals("impor_komoditas")){
                if (indexBulan != 0 && indexTahun != 0){
                    Intent intent = new Intent();
                    intent.putExtra("tahun", strTahun);
                    intent.putExtra("bulan", strBulan);
                    setResult(Activity.RESULT_OK, intent);
                    finish();
                }
            }else {
                if (indexTahun != 0){
                    Intent intent = new Intent();
                    intent.putExtra("tahun", strTahun);
                    setResult(Activity.RESULT_OK, intent);
                    finish();
                }
            }
        }
    }

    public void processFinish(List<modelTahun> tahuns) {
        try {
            String[] strTahun = new String[tahuns.size() + 1];
            String[] strBulan = {getString(R.string.bulan), "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"};
            strTahun[0] = getString(R.string.tahun);
            for (int i = 1; i < tahuns.size() + 1; i++){
                strTahun[i] = tahuns.get(i - 1).getTahun();
            }
            ArrayAdapter<String> arrayAdapter = new ArrayAdapter<>(tahunActivity.this, R.layout.list_layout, strTahun);
            if (strMenu.equals("ekspor_komoditas") || strMenu.equals("impor_komoditas")){
                binding1.spnTahun.setAdapter(arrayAdapter);
                binding1.prgBar.setVisibility(View.INVISIBLE);
                binding1.txtTahun.setVisibility(View.VISIBLE);
                binding1.spnTahun.setVisibility(View.VISIBLE);
                binding1.btnOK.setVisibility(View.VISIBLE);
                arrayAdapter = new ArrayAdapter<>(tahunActivity.this, R.layout.list_layout, strBulan);
                binding1.spnBulan.setAdapter(arrayAdapter);
                binding1.txtBulan.setVisibility(View.VISIBLE);
                binding1.spnBulan.setVisibility(View.VISIBLE);
            }else{
                binding.spnTahun.setAdapter(arrayAdapter);
                binding.prgBar.setVisibility(View.INVISIBLE);
                binding.txtTahun.setVisibility(View.VISIBLE);
                binding.spnTahun.setVisibility(View.VISIBLE);
                binding.btnOK.setVisibility(View.VISIBLE);
            }
        } catch (Exception ignored) {

        }
    }
}
