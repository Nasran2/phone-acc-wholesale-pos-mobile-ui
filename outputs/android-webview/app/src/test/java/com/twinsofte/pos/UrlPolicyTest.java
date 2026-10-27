package com.twinsofte.pos;

import org.junit.Test;
import static org.junit.Assert.*;

public class UrlPolicyTest {
    @Test public void packagedStoreAddressIsReadyToOpenWithoutSetup() {
        assertFalse(BuildConfig.POS_URL.isEmpty());
        assertEquals(BuildConfig.POS_URL, UrlPolicy.normalize(BuildConfig.POS_URL));
        assertTrue(UrlPolicy.sameOrigin(BuildConfig.POS_URL, BuildConfig.POS_URL + "/login"));
    }
    @Test public void normalizesWebsiteAndSubdirectory() {
        assertEquals("https://store.example/pos",UrlPolicy.normalize(" store.example/pos/ "));
        assertEquals("http://192.168.1.5:8000",UrlPolicy.normalize("http://192.168.1.5:8000/"));
    }
    @Test public void rejectsUntrustedSchemesAndCredentials() {
        for(String url:new String[]{"javascript:alert(1)","https://user:pass@store.example","http://store.example","https://store.example?token=123","file:///tmp/a"}) {
            try { UrlPolicy.normalize(url); fail("Accepted unsafe URL: "+url); }
            catch(IllegalArgumentException expected) { }
        }
    }
    @Test public void pdfDownloadsCannotLeaveTheStoreOrigin() {
        assertTrue(UrlPolicy.sameOrigin("https://store.example","https://store.example/sales/1/pdf"));
        assertTrue(UrlPolicy.sameOrigin("https://store.example","https://store.example:443/sales/1/pdf"));
        assertFalse(UrlPolicy.sameOrigin("https://store.example","https://evil.example/sales/1/pdf"));
        assertFalse(UrlPolicy.sameOrigin("https://store.example","https://store.example.evil.example/sales/1/pdf"));
        assertFalse(UrlPolicy.sameOrigin("https://store.example","http://store.example/sales/1/pdf"));
        assertFalse(UrlPolicy.sameOrigin("https://store.example","https://store.example:444/sales/1/pdf"));
    }
    @Test public void pdfNamesCannotEscapeCacheDirectory() {
        assertFalse(UrlPolicy.pdfName("../../other/file").contains("/"));
        assertFalse(UrlPolicy.pdfName("../../other/file").startsWith("."));
        assertEquals("Invoice.pdf",UrlPolicy.pdfName(null));
        assertEquals("invoice.PDF",UrlPolicy.pdfName("invoice.PDF"));
    }
}
