package com.ionicframework.otsjateng.vm

import androidx.lifecycle.LiveData
import androidx.lifecycle.MutableLiveData
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.ionicframework.otsjateng.model.InflasiData
import com.ionicframework.otsjateng.utilities.InflasiRepository
import kotlinx.coroutines.launch

class InflasiViewModel : ViewModel() {

    private val _inflasiData = MutableLiveData<List<InflasiData>>()
    val inflasiData: LiveData<List<InflasiData>> get() = _inflasiData

    private val _error = MutableLiveData<String>()
    val error: LiveData<String> get() = _error

    fun fetchInflasiSeries(url: String) {
        val repository = InflasiRepository(url)
        viewModelScope.launch {
            val response = repository.getInflasiSeries()
            if (response != null && response.status == "success") {
                _inflasiData.value = response.data
            } else {
                _error.value = "Failed to fetch data"
            }
        }
    }
}
