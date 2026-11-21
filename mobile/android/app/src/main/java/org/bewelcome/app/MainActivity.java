package org.bewelcome.app;

import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.view.animation.DecelerateInterpolator;
import android.view.animation.OvershootInterpolator;
import android.webkit.WebView;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;

import androidx.core.content.ContextCompat;
import androidx.core.splashscreen.SplashScreen;

import com.getcapacitor.BridgeActivity;
import com.getcapacitor.WebViewListener;

/**
 * Launch: the system splash shows the mark on orange, then this overlay takes over at the same place and
 * animates it to the left while the name slides in. The overlay fades out once the first page is loaded.
 */
public class MainActivity extends BridgeActivity {

    /** The mark is 48dp high in the overlay and about 115dp in the system splash icon. */
    private static final float MARK_START_SCALE = 2.4f;
    private static final long MIN_SHOW_MS = 1400;
    private static final long MAX_SHOW_MS = 6000;

    private final Handler handler = new Handler(Looper.getMainLooper());
    private FrameLayout overlay;
    private long shownAt;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        SplashScreen splash = SplashScreen.installSplashScreen(this);
        super.onCreate(savedInstanceState);

        // The overlay is already under the system splash, so it can go at once
        splash.setOnExitAnimationListener(provider -> provider.remove());

        if (savedInstanceState == null) {
            showOverlay();
        }
    }

    private void showOverlay() {
        float dp = getResources().getDisplayMetrics().density;

        overlay = new FrameLayout(this);
        overlay.setBackgroundColor(ContextCompat.getColor(this, R.color.bw_orange));
        overlay.setClickable(true);

        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(Gravity.CENTER_VERTICAL);

        ImageView mark = new ImageView(this);
        mark.setImageResource(R.drawable.splash_mark);
        LinearLayout.LayoutParams markParams = new LinearLayout.LayoutParams((int) (41 * dp), (int) (48 * dp));
        markParams.rightMargin = (int) (10 * dp);
        row.addView(mark, markParams);

        ImageView name = new ImageView(this);
        name.setImageResource(R.drawable.splash_name);
        row.addView(name, new LinearLayout.LayoutParams((int) (198 * dp), (int) (48 * dp)));

        overlay.addView(row, new FrameLayout.LayoutParams(
            ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.CENTER));
        addContentView(overlay, new ViewGroup.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));

        // Start: the mark alone, big and in the middle of the screen, as in the system splash
        float rowWidth = (41 + 10 + 198) * dp;
        float centreShift = rowWidth / 2f - (41 * dp) / 2f;
        mark.setScaleX(MARK_START_SCALE);
        mark.setScaleY(MARK_START_SCALE);
        mark.setTranslationX(centreShift);
        name.setAlpha(0f);
        name.setTranslationX(40 * dp);

        mark.animate()
            .scaleX(1f).scaleY(1f).translationX(0f)
            .setStartDelay(150).setDuration(550)
            .setInterpolator(new OvershootInterpolator(1.2f))
            .start();
        name.animate()
            .alpha(1f).translationX(0f)
            .setStartDelay(380).setDuration(500)
            .setInterpolator(new DecelerateInterpolator())
            .start();

        shownAt = System.currentTimeMillis();
        bridge.addWebViewListener(new WebViewListener() {
            @Override
            public void onPageLoaded(WebView webView) {
                long wait = Math.max(0, MIN_SHOW_MS - (System.currentTimeMillis() - shownAt));
                handler.postDelayed(MainActivity.this::hideOverlay, wait);
            }
        });
        // Never keep the splash if the page takes too long
        handler.postDelayed(this::hideOverlay, MAX_SHOW_MS);
    }

    private void hideOverlay() {
        final View view = overlay;
        if (view == null) {
            return;
        }
        overlay = null;
        view.animate()
            .alpha(0f).setDuration(300)
            .withEndAction(() -> {
                ViewGroup parent = (ViewGroup) view.getParent();
                if (parent != null) {
                    parent.removeView(view);
                }
            })
            .start();
    }

    @Override
    public void onDestroy() {
        handler.removeCallbacksAndMessages(null);
        super.onDestroy();
    }
}
