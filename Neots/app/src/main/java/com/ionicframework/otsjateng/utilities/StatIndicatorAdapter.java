package com.ionicframework.otsjateng.utilities;

import android.content.Context;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.TextView;

import androidx.annotation.NonNull;
import androidx.cardview.widget.CardView;
import androidx.recyclerview.widget.RecyclerView;

import com.ionicframework.otsjateng.R;
import com.ionicframework.otsjateng.model.StatIndicator;

import java.util.List;

/**
 * Adapter for Statistical Indicators RecyclerView
 */
public class StatIndicatorAdapter extends RecyclerView.Adapter<StatIndicatorAdapter.ViewHolder> {

    private final Context context;
    private final List<StatIndicator> indicatorList;
    private OnItemClickListener onItemClickListener;

    public interface OnItemClickListener {
        void onItemClick(StatIndicator indicator, int position);
    }

    public StatIndicatorAdapter(Context context, List<StatIndicator> indicatorList) {
        this.context = context;
        this.indicatorList = indicatorList;
    }

    public void setOnItemClickListener(OnItemClickListener listener) {
        this.onItemClickListener = listener;
    }

    @NonNull
    @Override
    public ViewHolder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
        View view = LayoutInflater.from(context).inflate(R.layout.item_stat_indicator, parent, false);
        return new ViewHolder(view);
    }

    @Override
    public void onBindViewHolder(@NonNull ViewHolder holder, int position) {
        StatIndicator indicator = indicatorList.get(position);

        // Set indicator name
        holder.tvIndicatorName.setText(indicator.getName());

        // Set indicator value
        holder.tvIndicatorValue.setText(indicator.getValue());

        // Set indicator unit
        holder.tvIndicatorUnit.setText(indicator.getUnit());

        // Set icon
        if (indicator.getIconResId() != 0) {
            holder.imgIcon.setImageResource(indicator.getIconResId());
        }

        // Set icon background
        if (indicator.getIconBackgroundResId() != 0) {
            holder.imgIconBg.setBackgroundResource(indicator.getIconBackgroundResId());
        }

        // Set click listener
        holder.cardIndicator.setOnClickListener(v -> {
            if (onItemClickListener != null) {
                onItemClickListener.onItemClick(indicator, position);
            }
        });
    }

    @Override
    public int getItemCount() {
        return indicatorList != null ? indicatorList.size() : 0;
    }

    /**
     * Update data and refresh RecyclerView
     */
    public void updateData(List<StatIndicator> newData) {
        indicatorList.clear();
        indicatorList.addAll(newData);
        notifyDataSetChanged();
    }

    /**
     * Add single item
     */
    public void addItem(StatIndicator indicator) {
        indicatorList.add(indicator);
        notifyItemInserted(indicatorList.size() - 1);
    }

    /**
     * Remove item at position
     */
    public void removeItem(int position) {
        if (position >= 0 && position < indicatorList.size()) {
            indicatorList.remove(position);
            notifyItemRemoved(position);
        }
    }

    /**
     * ViewHolder class
     */
    public static class ViewHolder extends RecyclerView.ViewHolder {
        CardView cardIndicator;
        FrameLayout imgIconBg;
        ImageView imgIcon;
        TextView tvIndicatorName;
        TextView tvIndicatorValue;
        TextView tvIndicatorUnit;

        public ViewHolder(@NonNull View itemView) {
            super(itemView);
            cardIndicator = itemView.findViewById(R.id.card_indicator);
            imgIconBg = itemView.findViewById(R.id.img_icon_bg);
            imgIcon = itemView.findViewById(R.id.img_icon);
            tvIndicatorName = itemView.findViewById(R.id.tv_indicator_name);
            tvIndicatorValue = itemView.findViewById(R.id.tv_indicator_value);
            tvIndicatorUnit = itemView.findViewById(R.id.tv_indicator_unit);
        }
    }
}
