package com.twinsofte.pos;

import java.net.URI;
import java.util.Locale;

final class UrlPolicy {
    static String normalize(String input) {
        String value = input.trim();
        if (!value.contains("://")) value = "https://" + value;
        URI uri = URI.create(value);
        if (uri.getHost() == null || uri.getUserInfo() != null || uri.getFragment() != null || uri.getQuery() != null) {
            throw new IllegalArgumentException("Enter the website address, without login details or query parameters.");
        }
        String scheme = uri.getScheme().toLowerCase(Locale.ROOT);
        String host = uri.getHost().toLowerCase(Locale.ROOT);
        boolean privateHost = host.equals("localhost") || host.equals("10.0.2.2") || host.startsWith("192.168.") || host.startsWith("10.") || host.matches("172\\.(1[6-9]|2[0-9]|3[01])\\..*") || host.endsWith(".local");
        if (!scheme.equals("https") && !(scheme.equals("http") && privateHost)) {
            throw new IllegalArgumentException("Use an HTTPS website address (HTTP is supported for a local network server).");
        }
        return value.replaceAll("/+$", "");
    }

    static boolean sameOrigin(String base, String target) {
        try {
            URI a = URI.create(base), b = URI.create(target);
            return b.getUserInfo() == null && a.getScheme().equalsIgnoreCase(b.getScheme())
                && a.getHost().equalsIgnoreCase(b.getHost()) && port(a) == port(b);
        } catch (Exception error) { return false; }
    }

    private static int port(URI uri) { return uri.getPort() >= 0 ? uri.getPort() : ("https".equalsIgnoreCase(uri.getScheme()) ? 443 : 80); }

    static String pdfName(String input) {
        String name = input == null ? "Invoice" : input.replaceAll("[^a-zA-Z0-9._ -]", "-");
        name = name.replaceAll("^\\.+", "");
        if (name.trim().isEmpty()) name = "Invoice";
        if (name.length() > 100) name = name.substring(0, 100);
        return name.toLowerCase(Locale.ROOT).endsWith(".pdf") ? name : name + ".pdf";
    }
}
