#!/bin/bash
set -euo pipefail
umask 077

POS_PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
POS_ANDROID_DIR="$POS_PROJECT_DIR/outputs/android-webview"
POS_SDK_DIR="${ANDROID_SDK_ROOT:-${ANDROID_HOME:-$HOME/Library/Android/sdk}}"
POS_SIGNING_DIR="${POS_SIGNING_DIR:-$HOME/.android/twinsofte-pos}"
POS_BUILD_TOOLS="$POS_SDK_DIR/build-tools/36.0.0"

mkdir -p "$POS_SIGNING_DIR" "$POS_ANDROID_DIR/app/src/main/assets"
sed 's/export function/function/g; s/export async function/async function/g' "$POS_PROJECT_DIR/resources/js/mobile.js" > "$POS_ANDROID_DIR/app/src/main/assets/mobile.js"
printf 'sdk.dir=%s\n' "$POS_SDK_DIR" > "$POS_ANDROID_DIR/local.properties"

cd "$POS_ANDROID_DIR"
POS_GRADLE_ARGS=(--no-daemon testReleaseUnitTest lintRelease assembleRelease)
if [ -n "${POS_URL:-}" ]; then
    POS_GRADLE_ARGS+=("-PposUrl=$POS_URL")
fi
./gradlew "${POS_GRADLE_ARGS[@]}"

if [ ! -f "$POS_SIGNING_DIR/release.jks" ]; then
    openssl rand -base64 36 > "$POS_SIGNING_DIR/release.pass"
    keytool -genkeypair -keystore "$POS_SIGNING_DIR/release.jks" -storepass:file "$POS_SIGNING_DIR/release.pass" -keypass:file "$POS_SIGNING_DIR/release.pass" -alias twinsofte -keyalg RSA -keysize 3072 -validity 10000 -dname 'CN=Twinsofte POS, OU=Apps, O=Twinsofte, C=LK' -noprompt
fi

"$POS_BUILD_TOOLS/apksigner" sign --ks "$POS_SIGNING_DIR/release.jks" --ks-key-alias twinsofte --ks-pass "file:$POS_SIGNING_DIR/release.pass" --out "$POS_PROJECT_DIR/outputs/Twinsofte-POS.apk" "$POS_ANDROID_DIR/app/build/outputs/apk/release/app-release-unsigned.apk"
"$POS_BUILD_TOOLS/apksigner" verify --verbose "$POS_PROJECT_DIR/outputs/Twinsofte-POS.apk"
"$POS_BUILD_TOOLS/zipalign" -c 4 "$POS_PROJECT_DIR/outputs/Twinsofte-POS.apk"
printf 'APK: %s\nSigning key (keep for future updates): %s\n' "$POS_PROJECT_DIR/outputs/Twinsofte-POS.apk" "$POS_SIGNING_DIR/release.jks"
