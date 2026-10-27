package com.twinsofte.pos;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.app.AlertDialog;
import android.app.ProgressDialog;
import android.content.ClipData;
import android.content.Intent;
import android.graphics.Color;
import android.graphics.Typeface;
import android.net.Uri;
import android.os.Bundle;
import android.print.PrintAttributes;
import android.print.PrintManager;
import android.util.Base64;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.webkit.CookieManager;
import android.webkit.JavascriptInterface;
import android.webkit.RenderProcessGoneDetail;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;
import androidx.core.content.FileProvider;
import org.json.JSONObject;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

public class MainActivity extends Activity {
    private static final int PICK_FILE = 41, SAVE_PDF = 42;
    private static final long MAX_PDF = 20L * 1024 * 1024;
    private final ExecutorService worker = Executors.newSingleThreadExecutor();
    private WebView web;
    private FrameLayout root;
    private LinearLayout overlay;
    private ProgressBar progress;
    private String serverUrl;
    private volatile String currentUrl = "";
    private ValueCallback<Uri[]> upload;
    private File pendingSave;
    private ProgressDialog pdfProgress;
    private long backPressed;
    private boolean failed;
    private volatile boolean pdfBusy;

    @Override public void onCreate(Bundle saved) {
        super.onCreate(saved);
        serverUrl = getPreferences(MODE_PRIVATE).getString("server", BuildConfig.POS_URL);
        if (serverUrl.trim().isEmpty()) showSetup();
        else {
            try { serverUrl = UrlPolicy.normalize(serverUrl); createBrowser(saved); }
            catch (Exception error) { showSetup(); }
        }
        worker.execute(this::cleanOldPdfs);
    }

    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }
    private TextView text(String value, int size, int color) {
        TextView label = new TextView(this); label.setText(value); label.setTextSize(size); label.setTextColor(color); label.setGravity(Gravity.CENTER); return label;
    }
    private LinearLayout brandedPanel(String title, String message) {
        LinearLayout panel = new LinearLayout(this); panel.setOrientation(LinearLayout.VERTICAL); panel.setGravity(Gravity.CENTER); panel.setPadding(dp(28), dp(28), dp(28), dp(28)); panel.setBackgroundColor(Color.rgb(8,35,93));
        ImageView logo = new ImageView(this); logo.setImageResource(R.drawable.app_logo); panel.addView(logo, new LinearLayout.LayoutParams(dp(136), dp(136)));
        TextView heading = text(title, 25, Color.WHITE); heading.setTypeface(null, Typeface.BOLD); heading.setPadding(0,dp(20),0,dp(8)); panel.addView(heading);
        TextView subtitle = text(message, 14, Color.rgb(173,219,247)); subtitle.setPadding(0,0,0,dp(20)); panel.addView(subtitle);
        return panel;
    }
    private void showSetup() {
        LinearLayout panel = brandedPanel("Twinsofte POS", "Connect your store to get started. Enter the live POS website address.");
        EditText address = new EditText(this); address.setSingleLine(true); address.setInputType(android.text.InputType.TYPE_CLASS_TEXT | android.text.InputType.TYPE_TEXT_VARIATION_URI); address.setTextColor(Color.WHITE); address.setHintTextColor(Color.LTGRAY); address.setHint("https://your-pos-website.com");
        if (serverUrl != null) address.setText(serverUrl);
        panel.addView(address, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(56)));
        Button connect = new Button(this); connect.setText("Connect to my store"); panel.addView(connect);
        TextView help = text("Your store data stays on your server. Internet access is required.", 12, Color.rgb(173,219,247)); panel.addView(help);
        connect.setOnClickListener(v -> {
            try {
                String next = UrlPolicy.normalize(address.getText().toString());
                if (web != null) { CookieManager.getInstance().removeAllCookies(null); web.destroy(); web = null; }
                serverUrl = next; getPreferences(MODE_PRIVATE).edit().putString("server", serverUrl).apply(); createBrowser(null);
            } catch (Exception error) { address.setError(error.getMessage()); }
        });
        setContentView(panel);
    }

    @SuppressLint("SetJavaScriptEnabled") private void createBrowser(Bundle saved) {
        root = new FrameLayout(this); root.setBackgroundColor(Color.WHITE);
        web = new WebView(this); web.setBackgroundColor(Color.WHITE);
        WebSettings settings = web.getSettings(); settings.setJavaScriptEnabled(true); settings.setDomStorageEnabled(true);
        settings.setAllowFileAccess(false); settings.setAllowContentAccess(true); settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        settings.setCacheMode(WebSettings.LOAD_DEFAULT); settings.setUseWideViewPort(true); settings.setLoadWithOverviewMode(true); settings.setTextZoom(100);
        settings.setSupportMultipleWindows(false); settings.setMediaPlaybackRequiresUserGesture(true);
        settings.setUserAgentString(settings.getUserAgentString() + " TwinsoftePOS/1.0 AndroidApp");
        CookieManager.getInstance().setAcceptCookie(true); CookieManager.getInstance().setAcceptThirdPartyCookies(web, false);
        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG);
        web.addJavascriptInterface(new Bridge(), "ImranAndroid");
        root.addView(web, new FrameLayout.LayoutParams(-1,-1));
        progress = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal); progress.setMax(100);
        FrameLayout.LayoutParams bar = new FrameLayout.LayoutParams(-1, dp(3), Gravity.TOP); root.addView(progress,bar);
        overlay = brandedPanel("Twinsofte POS", "Opening your store…");
        ProgressBar spinner = new ProgressBar(this); overlay.addView(spinner, new LinearLayout.LayoutParams(dp(32),dp(32)));
        root.addView(overlay, new FrameLayout.LayoutParams(-1,-1));
        // Keep content clear of Android system bars on phones and tablets.
        root.setOnApplyWindowInsetsListener((view,insets) -> {
            view.setPadding(insets.getSystemWindowInsetLeft(),insets.getSystemWindowInsetTop(),insets.getSystemWindowInsetRight(),insets.getSystemWindowInsetBottom()); return insets.consumeSystemWindowInsets();
        });
        web.setWebChromeClient(new WebChromeClient() {
            @Override public void onProgressChanged(WebView view,int value) { progress.setProgress(value); progress.setVisibility(value==100 ? View.GONE : View.VISIBLE); }
            @Override public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback, FileChooserParams params) {
                if (upload != null) upload.onReceiveValue(null);
                upload = callback;
                try { startActivityForResult(params.createIntent(),PICK_FILE); return true; }
                catch (Exception error) { upload = null; return false; }
            }
        });
        web.setWebViewClient(new WebViewClient() {
            @Override public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                String url = request.getUrl().toString();
                if (UrlPolicy.sameOrigin(serverUrl,url)) return false;
                if (request.isForMainFrame()) openExternal(url);
                return true;
            }
            @Override public void onPageStarted(WebView view,String url,android.graphics.Bitmap icon) { currentUrl = url; failed = false; progress.setVisibility(View.VISIBLE); }
            @Override public void onPageCommitVisible(WebView view,String url) { if (!failed) overlay.setVisibility(View.GONE); }
            @Override public void onPageFinished(WebView view,String url) {
                CookieManager.getInstance().flush(); progress.setVisibility(View.GONE);
                if (!failed) overlay.setVisibility(View.GONE);
                installMobileSupport();
            }
            @Override public void onReceivedError(WebView view,WebResourceRequest request,WebResourceError error) { if (request.isForMainFrame()) showConnectionError(); }
            @Override public void onReceivedHttpError(WebView view,WebResourceRequest request,WebResourceResponse response) { if (request.isForMainFrame() && response.getStatusCode()>=500) showConnectionError(); }
            @Override public boolean onRenderProcessGone(WebView view,RenderProcessGoneDetail detail) {
                currentUrl = ""; root.removeView(view); view.destroy(); web = null;
                new AlertDialog.Builder(MainActivity.this).setTitle("Store view interrupted").setMessage("Android stopped the page. A completed sale may already be saved. Reopen and check Sales before entering it again.")
                    .setCancelable(false).setPositiveButton("Reopen store",(dialog,which)->createBrowser(null)).show(); return true;
            }
        });
        web.setDownloadListener((url,agent,disposition,mime,length) -> {
            if (UrlPolicy.sameOrigin(serverUrl,url)) downloadRemote(url,"Document.pdf",null,false);
            else if (!url.startsWith("blob:") && !url.startsWith("data:")) openExternal(url);
            else Toast.makeText(this,"Use the PDF Download button to save this document.",Toast.LENGTH_LONG).show();
        });
        setContentView(root);
        if (saved == null || web.restoreState(saved) == null) web.loadUrl(serverUrl);
        root.postDelayed(() -> { if (overlay.getVisibility()==View.VISIBLE && !failed) showConnectionError(); }, 25000);
    }

    private void installMobileSupport() {
        try (InputStream input = getAssets().open("mobile.js")) {
            String script = readAsset(input);
            web.evaluateJavascript("if (!window.TwinsofteMobile) {" + script + "}", null);
            web.evaluateJavascript("window.print = function() { window.ImranAndroid.printPage(); };", null);
        } catch (Exception ignored) { }
    }
    private String readAsset(InputStream input) throws Exception {
        java.io.ByteArrayOutputStream output = new java.io.ByteArrayOutputStream();
        byte[] buffer = new byte[8192]; int count;
        while ((count = input.read(buffer)) != -1) output.write(buffer, 0, count);
        return output.toString("UTF-8");
    }
    private void showConnectionError() {
        failed = true;
        overlay.removeAllViews();
        LinearLayout message = brandedPanel("Unable to open your store", "Check your internet connection and try again. Your saved sales remain on the server.");
        Button retry = new Button(this); retry.setText("Try again"); message.addView(retry); retry.setOnClickListener(v->{ failed=false; overlay.setVisibility(View.GONE); if(web!=null) web.reload(); });
        Button setup = new Button(this); setup.setText("Store connection settings"); message.addView(setup); setup.setOnClickListener(v->showSetup());
        overlay.addView(message,new LinearLayout.LayoutParams(-1,-1)); overlay.setVisibility(View.VISIBLE);
    }
    private void openExternal(String value) {
        try {
            Uri uri = Uri.parse(value); String scheme = uri.getScheme();
            if ("https".equals(scheme) || "http".equals(scheme) || "tel".equals(scheme) || "mailto".equals(scheme) || "whatsapp".equals(scheme)) startActivity(new Intent(Intent.ACTION_VIEW,uri));
        } catch (Exception error) { Toast.makeText(this,"No app is available to open this link.",Toast.LENGTH_SHORT).show(); }
    }

    private class Bridge {
        @JavascriptInterface public void sharePdfUrl(String url,String filename,String id) { if (trusted()) runOnUiThread(()->downloadRemote(url,filename,id,true)); }
        @JavascriptInterface public void downloadPdfUrl(String url,String filename,String id) { if (trusted()) runOnUiThread(()->downloadRemote(url,filename,id,false)); }
        @JavascriptInterface public void sharePdf(String filename,String base64) { if (trusted()) saveBase64(filename,base64,true); }
        @JavascriptInterface public void downloadPdf(String filename,String base64) { if (trusted()) saveBase64(filename,base64,false); }
        @JavascriptInterface public void printPage() { if (trusted()) runOnUiThread(()-> { if(web!=null) ((PrintManager)getSystemService(PRINT_SERVICE)).print("Twinsofte Invoice",web.createPrintDocumentAdapter("Twinsofte Invoice"),new PrintAttributes.Builder().build()); }); }
        private boolean trusted() { return UrlPolicy.sameOrigin(serverUrl,currentUrl); }
    }

    private void downloadRemote(String url,String filename,String id,boolean share) {
        if (pdfBusy || pendingSave != null) { result(id,false,"Finish the current PDF action first."); return; }
        if (!UrlPolicy.sameOrigin(serverUrl,url)) { result(id,false,"PDF address must belong to your store."); return; }
        pdfBusy = true; showPdfProgress();
        String cookies = CookieManager.getInstance().getCookie(url);
        worker.execute(() -> {
            HttpURLConnection connection = null; File file = null;
            try {
                connection = (HttpURLConnection)new URL(url).openConnection();
                connection.setInstanceFollowRedirects(false); connection.setConnectTimeout(15000); connection.setReadTimeout(45000);
                connection.setRequestProperty("Accept","application/pdf");
                if (cookies != null) connection.setRequestProperty("Cookie",cookies);
                if (connection.getResponseCode()!=200 || connection.getContentType()==null || !connection.getContentType().toLowerCase(java.util.Locale.ROOT).contains("application/pdf")) throw new Exception("Could not retrieve PDF. Check your connection or sign in again.");
                String disposition = connection.getHeaderField("Content-Disposition");
                String serverName = filename;
                if (disposition != null) {
                    java.util.regex.Matcher match = java.util.regex.Pattern.compile("filename=\"?([^\";]+)").matcher(disposition);
                    if (match.find()) serverName = match.group(1);
                }
                file = newPdfFile(serverName);
                try (InputStream in = connection.getInputStream(); OutputStream out = new FileOutputStream(file)) {
                    byte[] buffer = new byte[16384]; long total=0; int count;
                    while ((count=in.read(buffer))!=-1) { total+=count; if(total>MAX_PDF) throw new Exception("PDF is larger than 20 MB. Export a smaller report."); out.write(buffer,0,count); }
                }
                validatePdf(file);
                File ready = file; runOnUiThread(()-> { hidePdfProgress(); pdfBusy=false; presentPdf(ready,share,id); });
            } catch (Exception error) {
                if(file!=null) file.delete();
                runOnUiThread(()-> { hidePdfProgress(); pdfBusy=false; result(id,false,error.getMessage()); Toast.makeText(this,error.getMessage(),Toast.LENGTH_LONG).show(); });
            } finally { if(connection!=null) connection.disconnect(); }
        });
    }
    private void saveBase64(String filename,String encoded,boolean share) {
        if (pdfBusy || pendingSave!=null) return;
        pdfBusy=true;
        runOnUiThread(this::showPdfProgress);
        worker.execute(()-> {
            File file=null;
            try {
                if(encoded.length()>MAX_PDF*4/3+8) throw new Exception("PDF is too large. Please use a smaller report.");
                file=newPdfFile(filename);
                try(OutputStream out=new FileOutputStream(file)) { out.write(Base64.decode(encoded,Base64.DEFAULT)); }
                validatePdf(file); File ready=file;
                runOnUiThread(()-> { hidePdfProgress(); pdfBusy=false; presentPdf(ready,share,null); });
            } catch(Exception error) { if(file!=null) file.delete(); runOnUiThread(()-> { hidePdfProgress(); pdfBusy=false; Toast.makeText(this,"Unable to prepare PDF. Please retry.",Toast.LENGTH_LONG).show(); }); }
        });
    }
    private File newPdfFile(String name) throws Exception {
        File folder = new File(getCacheDir(),"pdf/"+java.util.UUID.randomUUID());
        if(!folder.mkdirs()) throw new Exception("Unable to create PDF storage.");
        return new File(folder,UrlPolicy.pdfName(name));
    }
    private void validatePdf(File file) throws Exception {
        try(InputStream in=new FileInputStream(file)) { byte[] header=new byte[5]; if(in.read(header)!=5 || !new String(header,StandardCharsets.US_ASCII).equals("%PDF-")) throw new Exception("Invalid PDF response. Please sign in and retry."); }
    }
    private void showPdfProgress() {
        if(isFinishing()) return;
        pdfProgress=ProgressDialog.show(this,"Preparing PDF","Your sale is saved. Getting the document…",true,false);
    }
    private void hidePdfProgress() { if(pdfProgress!=null) { pdfProgress.dismiss(); pdfProgress=null; } }
    private void presentPdf(File file,boolean share,String id) {
        if(isFinishing()) return;
        try {
            if(share) {
                Uri uri=FileProvider.getUriForFile(this,BuildConfig.APPLICATION_ID+".files",file);
                Intent intent=new Intent(Intent.ACTION_SEND); intent.setType("application/pdf"); intent.putExtra(Intent.EXTRA_STREAM,uri); intent.putExtra(Intent.EXTRA_TITLE,file.getName()); intent.setClipData(ClipData.newRawUri("PDF",uri)); intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
                startActivity(Intent.createChooser(intent,"Share PDF — choose WhatsApp or another app"));
            } else {
                pendingSave=file;
                Intent intent=new Intent(Intent.ACTION_CREATE_DOCUMENT); intent.setType("application/pdf"); intent.addCategory(Intent.CATEGORY_OPENABLE); intent.putExtra(Intent.EXTRA_TITLE,file.getName()); startActivityForResult(intent,SAVE_PDF);
            }
            result(id,true,null);
        } catch(Exception error) { pendingSave=null; result(id,false,"No app is available for this action."); Toast.makeText(this,"No app is available for this action.",Toast.LENGTH_LONG).show(); }
    }
    private void result(String id,boolean ok,String error) {
        if(id==null || web==null) return;
        try { JSONObject data=new JSONObject(); data.put("id",id); data.put("ok",ok); data.put("error",error); web.evaluateJavascript("window.dispatchEvent(new CustomEvent('twinsofte:pdf-result',{detail:"+data+"}));",null); }
        catch(Exception ignored) { }
    }
    private void cleanOldPdfs() {
        File[] folders=new File(getCacheDir(),"pdf").listFiles(); if(folders==null) return;
        for(File folder:folders) { File[] files=folder.listFiles(); if(files==null) continue; for(File file:files) if(System.currentTimeMillis()-file.lastModified()>86400000L) file.delete(); if(folder.list()!=null && folder.list().length==0) folder.delete(); }
    }
    @Override protected void onActivityResult(int request,int code,Intent data) {
        super.onActivityResult(request,code,data);
        if(request==PICK_FILE && upload!=null) { upload.onReceiveValue(WebChromeClient.FileChooserParams.parseResult(code,data)); upload=null; }
        if(request==SAVE_PDF) {
            File file=pendingSave; pendingSave=null;
            if(code==RESULT_OK && data!=null && data.getData()!=null && file!=null) {
                Uri destination=data.getData(); worker.execute(()-> {
                    try(InputStream in=new FileInputStream(file); OutputStream out=getContentResolver().openOutputStream(destination)) { if(out==null) throw new Exception(); byte[] buffer=new byte[16384]; int count; while((count=in.read(buffer))!=-1) out.write(buffer,0,count); runOnUiThread(()->Toast.makeText(this,"PDF saved",Toast.LENGTH_SHORT).show()); }
                    catch(Exception error) { runOnUiThread(()->Toast.makeText(this,"Could not save PDF. Please retry.",Toast.LENGTH_LONG).show()); }
                });
            }
        }
    }
    @Override public void onBackPressed() {
        if(web!=null && web.canGoBack()) { web.goBack(); return; }
        if(System.currentTimeMillis()-backPressed<2000) super.onBackPressed();
        else { backPressed=System.currentTimeMillis(); Toast.makeText(this,"Press back again to close Twinsofte POS",Toast.LENGTH_SHORT).show(); }
    }
    @Override protected void onSaveInstanceState(Bundle state) { super.onSaveInstanceState(state); if(web!=null) web.saveState(state); }
    @Override protected void onPause() { super.onPause(); CookieManager.getInstance().flush(); }
    @Override protected void onDestroy() { hidePdfProgress(); if(upload!=null) upload.onReceiveValue(null); if(web!=null) { web.removeJavascriptInterface("ImranAndroid"); web.destroy(); web=null; } worker.shutdown(); super.onDestroy(); }
}
